<?php
/**
 * Gift cards como PRODUCTO (Fase 3).
 *
 * Un producto marcado como "gift card" que, cuando la orden que lo contiene se
 * paga (en el mostrador o en la web), emite un crédito al portador por su monto
 * y entrega el código (por email en la web; el POS lo muestra en pantalla).
 *
 * Mismo instrumento que el crédito de devolución: reusa {@see KEYTILL_Credit},
 * solo cambia la VÍA de emisión (kind = 'giftcard').
 *
 * Montos: fijos (el monto = el precio del producto) o a elección (el precio se
 * fija al comprar; ver monto libre en web/POS). Un código por unidad.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emisión de gift cards vendidas como producto.
 */
class KEYTILL_Credit_Product {

	/** Meta de producto: es una gift card. */
	const META_FLAG = '_keytill_giftcard';

	/** Meta de producto: permite monto a elección en la web. */
	const META_CUSTOM = '_keytill_giftcard_custom';

	/** Meta de producto: denominaciones fijas (coma-separadas). */
	const META_DENOMS = '_keytill_giftcard_denoms';

	/** Meta del item de la orden: códigos emitidos [{code, amount, expires}]. */
	const ITEM_META_CODES = '_keytill_giftcard_codes';

	/**
	 * Engancha admin de producto y emisión al pagarse la orden.
	 */
	public static function init() {
		// Campos en la ficha de producto (pestaña General).
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );

		// Emisión cuando la orden pasa a pagada. (No en on-hold/pending: BACS y
		// cheque siguen impagos hasta confirmarse.)
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'issue_for_order_id' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'issue_for_order_id' ), 10, 1 );

		// Monto a elección en la WEB: input en la ficha + precio del carrito.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'web_amount_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'web_validate_amount' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'web_add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'web_apply_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'web_display_amount' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'web_price_html' ), 10, 2 );
	}

	/**
	 * Precio "desde $X" en el catálogo/ficha (el monto real se elige al comprar).
	 *
	 * @param string     $html    HTML del precio.
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	public static function web_price_html( $html, $product ) {
		if ( ! self::is_giftcard( $product ) ) {
			return $html;
		}
		$denoms = self::denominations( $product );
		$min    = $denoms ? min( $denoms ) : (float) $product->get_price();
		if ( $min > 0 ) {
			/* translators: %s: monto mínimo. */
			return sprintf( esc_html__( 'desde %s', 'keytill' ), wc_price( $min ) );
		}
		return $html;
	}

	/* ------------------------------------------------------------------ *
	 * Reconocimiento de producto gift card
	 * ------------------------------------------------------------------ */

	/**
	 * ¿El producto es una gift card?
	 *
	 * @param WC_Product|false|null $product Producto.
	 * @return bool
	 */
	public static function is_giftcard( $product ) {
		return $product instanceof WC_Product && 'yes' === $product->get_meta( self::META_FLAG );
	}

	/**
	 * ¿La gift card permite monto a elección en la web?
	 * (En el POS el cajero siempre puede tipear un monto.)
	 *
	 * @param WC_Product|false|null $product Producto.
	 * @return bool
	 */
	public static function is_custom( $product ) {
		return self::is_giftcard( $product ) && 'yes' === $product->get_meta( self::META_CUSTOM );
	}

	/**
	 * Denominaciones fijas configuradas (montos > 0, únicas y ordenadas).
	 *
	 * @param WC_Product|false|null $product Producto.
	 * @return float[]
	 */
	public static function denominations( $product ) {
		if ( ! self::is_giftcard( $product ) ) {
			return array();
		}
		$raw   = (string) $product->get_meta( self::META_DENOMS );
		$parts = preg_split( '/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$v = round( (float) $p, 2 ); // se cargan como números planos: "5000, 10000, 20000".
			if ( $v > 0 ) {
				$out[] = $v;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Admin de producto
	 * ------------------------------------------------------------------ */

	/**
	 * Dibuja los checkboxes en la ficha de producto.
	 */
	public static function product_fields() {
		echo '<div class="options_group keytill-giftcard-fields">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_FLAG,
				'label'       => __( 'Gift card (HM Crédito)', 'keytill' ),
				'description' => __( 'Al pagarse la orden, emite un crédito al portador por el monto elegido y entrega el código.', 'keytill' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_DENOMS,
				'label'       => __( 'Denominaciones fijas', 'keytill' ),
				'placeholder' => '5000, 10000, 20000',
				'description' => __( 'Montos sugeridos separados por coma. Se ofrecen como opciones rápidas en el POS y en la web.', 'keytill' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_CUSTOM,
				'label'       => __( 'Permitir monto a elección (web)', 'keytill' ),
				'description' => __( 'El cliente puede escribir un importe libre en la web además de las denominaciones. En el POS el cajero siempre puede tipear un monto.', 'keytill' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Guarda los checkboxes del producto.
	 *
	 * @param int $post_id ID del producto.
	 * @return void
	 */
	public static function save_product_fields( $post_id ) {
		// El nonce lo verifica WooCommerce en el guardado del producto.
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$product->update_meta_data( self::META_FLAG, isset( $_POST[ self::META_FLAG ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( self::META_CUSTOM, isset( $_POST[ self::META_CUSTOM ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// Denominaciones: normaliza a "5000, 10000, 20000".
		$denoms_raw = isset( $_POST[ self::META_DENOMS ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_DENOMS ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nums       = array();
		foreach ( preg_split( '/[,\s]+/', $denoms_raw, -1, PREG_SPLIT_NO_EMPTY ) as $n ) {
			$v = round( (float) $n, 2 );
			if ( $v > 0 ) {
				$nums[] = ( $v == (int) $v ) ? (string) (int) $v : (string) $v;
			}
		}
		$product->update_meta_data( self::META_DENOMS, implode( ', ', array_unique( $nums ) ) );
		$product->save();
	}

	/* ------------------------------------------------------------------ *
	 * Monto a elección en la web (ficha de producto → carrito)
	 * ------------------------------------------------------------------ */

	/**
	 * Selector de monto en la ficha del producto: denominaciones + (si se
	 * permite) monto libre.
	 *
	 * @return void
	 */
	public static function web_amount_field() {
		global $product;
		if ( ! self::is_giftcard( $product ) ) {
			return;
		}
		$denoms  = self::denominations( $product );
		$custom  = self::is_custom( $product );
		$default = $denoms ? $denoms[0] : ( (float) $product->get_price() );

		echo '<div class="keytill-giftcard-amount" style="margin:0 0 14px">';
		echo '<label style="display:block;font-weight:600;margin-bottom:6px">' . esc_html__( 'Elegí el monto de la gift card', 'keytill' ) . '</label>';

		if ( $denoms ) {
			echo '<div class="keytill-gc-denoms" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">';
			foreach ( $denoms as $idx => $d ) {
				printf(
					'<button type="button" class="button keytill-gc-denom%s" data-amount="%s" onclick="keytillGcPick(this)">%s</button>',
					0 === $idx ? ' is-active' : '',
					esc_attr( $d ),
					esc_html( wp_strip_all_tags( wc_price( $d ) ) )
				);
			}
			echo '</div>';
		}

		printf(
			'<input type="number" min="1" step="0.01" name="keytill_giftcard_amount" id="keytill_giftcard_amount" value="%s" %s required style="max-width:200px" />',
			esc_attr( $default > 0 ? $default : '' ),
			$custom ? '' : 'readonly'
		);
		if ( $custom ) {
			echo '<p class="description" style="margin:4px 0 0">' . esc_html__( 'O escribí otro monto.', 'keytill' ) . '</p>';
		}
		echo '</div>';
		?>
		<style>.keytill-gc-denom.is-active{outline:2px solid currentColor;outline-offset:1px;font-weight:700}</style>
		<script>
		function keytillGcPick( btn ) {
			var inp = document.getElementById( 'keytill_giftcard_amount' );
			if ( inp ) { inp.value = btn.getAttribute( 'data-amount' ); }
			var all = document.querySelectorAll( '.keytill-gc-denom' );
			for ( var i = 0; i < all.length; i++ ) { all[ i ].classList.remove( 'is-active' ); }
			btn.classList.add( 'is-active' );
		}
		</script>
		<?php
	}

	/**
	 * Valida el monto al agregar al carrito.
	 *
	 * @param bool $passed     Validación previa.
	 * @param int  $product_id Producto.
	 * @return bool
	 */
	public static function web_validate_amount( $passed, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! self::is_giftcard( $product ) ) {
			return $passed;
		}
		$amt = isset( $_POST['keytill_giftcard_amount'] ) ? round( (float) wp_unslash( $_POST['keytill_giftcard_amount'] ), 2 ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $amt <= 0 ) {
			wc_add_notice( __( 'Elegí un monto para la gift card.', 'keytill' ), 'error' );
			return false;
		}
		// Sin monto libre: el importe debe ser una de las denominaciones.
		if ( ! self::is_custom( $product ) ) {
			$denoms = self::denominations( $product );
			if ( $denoms && ! in_array( $amt, $denoms, false ) ) {
				wc_add_notice( __( 'Elegí una de las denominaciones disponibles.', 'keytill' ), 'error' );
				return false;
			}
		}
		return $passed;
	}

	/**
	 * Guarda el monto elegido en los datos del ítem del carrito.
	 *
	 * @param array $data       Datos del ítem.
	 * @param int   $product_id Producto.
	 * @return array
	 */
	public static function web_add_cart_item_data( $data, $product_id ) {
		if ( self::is_giftcard( wc_get_product( $product_id ) ) && isset( $_POST['keytill_giftcard_amount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$amt = round( (float) wp_unslash( $_POST['keytill_giftcard_amount'] ), 2 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $amt > 0 ) {
				$data['keytill_giftcard_amount'] = $amt;
				// Clave única: dos montos distintos no se fusionan en una línea.
				$data['keytill_unique'] = md5( $amt . microtime() . wp_rand() );
			}
		}
		return $data;
	}

	/**
	 * Fija el precio de la línea del carrito al monto elegido.
	 *
	 * @param WC_Cart $cart Carrito.
	 * @return void
	 */
	public static function web_apply_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['keytill_giftcard_amount'] ) && isset( $item['data'] ) ) {
				$item['data']->set_price( (float) $item['keytill_giftcard_amount'] );
			}
		}
	}

	/**
	 * Muestra el monto elegido en el detalle del ítem (carrito/checkout).
	 *
	 * @param array $item_data Datos visibles.
	 * @param array $cart_item Ítem del carrito.
	 * @return array
	 */
	public static function web_display_amount( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['keytill_giftcard_amount'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Monto', 'keytill' ),
				'value' => wp_strip_all_tags( wc_price( $cart_item['keytill_giftcard_amount'] ) ),
			);
		}
		return $item_data;
	}

	/* ------------------------------------------------------------------ *
	 * Emisión al pagarse la orden
	 * ------------------------------------------------------------------ */

	/**
	 * Wrapper por ID (para los hooks de transición de estado).
	 *
	 * @param int $order_id ID de la orden.
	 * @return void
	 */
	public static function issue_for_order_id( $order_id ) {
		self::issue_for_order( wc_get_order( $order_id ) );
	}

	/**
	 * Emite las gift cards de una orden pagada. Idempotente por item (una vez
	 * emitidos los códigos, no se re-emiten). Un código por unidad.
	 *
	 * @param WC_Order|false|null $order Orden.
	 * @return array Lista de códigos emitidos en esta pasada [{code, amount, expires}].
	 */
	public static function issue_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}
		$is_pos  = (bool) $order->get_meta( '_keytill' );
		$channel = $is_pos ? 'pos' : 'web';
		$cajero  = (int) $order->get_meta( '_keytill_cajero_id' );
		$session = (int) $order->get_meta( '_keytill_session' );
		$email   = $order->get_billing_email();

		$new_codes = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! self::is_giftcard( $product ) ) {
				continue;
			}
			if ( $item->get_meta( self::ITEM_META_CODES ) ) {
				continue; // Ya emitido (idempotencia).
			}

			$qty  = max( 1, (int) $item->get_quantity() );
			$unit = round( (float) $item->get_total() / $qty, 2 ); // Monto por unidad (ya refleja el precio elegido).
			if ( $unit <= 0 ) {
				continue;
			}

			$codes = array();
			for ( $i = 0; $i < $qty; $i++ ) {
				$res = KEYTILL_Credit::issue(
					$unit,
					array(
						'kind'            => 'giftcard',
						'channel'         => $channel,
						'source_order_id' => $order->get_id(),
						'customer_id'     => $order->get_customer_id(),
						'customer_email'  => $email,
						'cajero_id'       => $cajero,
						'session_id'      => $session,
						'note'            => sprintf( 'Gift card vendida (%s)', $channel ),
					)
				);
				if ( ! is_wp_error( $res ) ) {
					$codes[] = array(
						'code'    => $res['code'],
						'amount'  => $unit,
						'expires' => $res['expires_at'],
					);
				}
			}

			if ( $codes ) {
				$item->update_meta_data( self::ITEM_META_CODES, $codes );
				$item->save();
				$new_codes = array_merge( $new_codes, $codes );
			}
		}

		if ( $new_codes ) {
			self::note_and_notify( $order, $new_codes, $is_pos );
		}

		return $new_codes;
	}

	/**
	 * Junta todos los códigos de gift card ya emitidos en una orden (para que el
	 * POS los muestre tras el pago).
	 *
	 * @param WC_Order|false|null $order Orden.
	 * @return array [{code, amount, expires}]
	 */
	public static function codes_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$codes = $item->get_meta( self::ITEM_META_CODES );
			if ( is_array( $codes ) ) {
				$out = array_merge( $out, $codes );
			}
		}
		return $out;
	}

	/**
	 * Deja nota en la orden y, si es web, manda el email con los códigos.
	 *
	 * @param WC_Order $order     Orden.
	 * @param array    $codes     Códigos [{code, amount, expires}].
	 * @param bool     $is_pos    Si la venta fue en el POS.
	 * @return void
	 */
	private static function note_and_notify( $order, $codes, $is_pos ) {
		$lines = array();
		foreach ( $codes as $c ) {
			$lines[] = $c['code'] . ' — ' . wp_strip_all_tags( wc_price( $c['amount'] ) );
		}
		$order->add_order_note(
			__( 'Gift card(s) emitida(s):', 'keytill' ) . ' ' . implode( ' · ', $lines )
		);

		if ( $is_pos ) {
			return; // El POS muestra los códigos en pantalla; no se manda email.
		}

		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$store   = get_bloginfo( 'name' );
		$subject = sprintf( __( 'Tu gift card de %s', 'keytill' ), $store );
		$body    = sprintf( __( 'Hola %s,', 'keytill' ), $order->get_billing_first_name() ) . "\n\n";
		$body   .= __( '¡Gracias por tu compra! Estos son los códigos de tu(s) gift card(s):', 'keytill' ) . "\n\n";
		foreach ( $codes as $c ) {
			$exp   = $c['expires'] ? sprintf( __( ' (vence %s)', 'keytill' ), mysql2date( 'd/m/Y', $c['expires'] ) ) : '';
			$body .= '  • ' . $c['code'] . ' — ' . wp_strip_all_tags( wc_price( $c['amount'] ) ) . $exp . "\n";
		}
		$body .= "\n" . __( 'Canjealo en la tienda o en el checkout web ingresando el código.', 'keytill' ) . "\n";
		$body .= "\n" . $store . "\n";

		wp_mail( $email, $subject, $body );
	}
}
