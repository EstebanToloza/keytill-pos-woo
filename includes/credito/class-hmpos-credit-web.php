<?php
/**
 * Canje del crédito en tienda en el CHECKOUT WEB (Fase 2).
 *
 * La tienda usa el checkout/carrito de **bloques** (Store API) y los impuestos
 * están desactivados. La vía más robusta y sin JS propio es representar el
 * crédito como un **cupón virtual**: así reutilizamos el campo nativo "Agregar
 * cupón" del carrito y el checkout, y el descuento aparece en los totales,
 * emails y detalle de la orden sin tocar nada del front.
 *
 * - Cupón `fixed_cart` por `min(saldo, total)` (impuestos off ⇒ el total baja
 *   exactamente ese monto). El monto se recalcula en cada request, así que si el
 *   saldo bajó por un canje en el POS, el descuento se achica solo.
 * - La **reserva** (débito atómico) ocurre al procesar la orden, ANTES del pago.
 *   Si el saldo se agotó en paralelo, se aborta el checkout (mismo criterio que
 *   el POS: debitar antes de cobrar, revertir si el pago falla).
 * - Si la orden pasa a `failed`/`cancelled`, se **re-acredita** el saldo.
 *
 * Frontera limpia: este módulo solo usa la API pública de {@see HMPOS_Credit}.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integración del crédito con el checkout web de WooCommerce.
 */
class HMPOS_Credit_Web {

	/**
	 * Base para el id del cupón virtual (evita colisión con posts reales).
	 * El id resultante = BASE + credit_id, siempre inexistente como post.
	 */
	const COUPON_ID_BASE = 1000000000;

	/** Meta en la orden: id del crédito canjeado. */
	const META_ID = '_hmpos_credit_id';

	/** Meta en la orden: código del crédito canjeado. */
	const META_CODE = '_hmpos_credit_code';

	/** Meta en la orden: monto canjeado. */
	const META_USED = '_hmpos_credit_used';

	/** Meta en la orden: canal del canje. */
	const META_CHANNEL = '_hmpos_credit_channel';

	/** Meta en la orden: estado del canje ('reserved' | 'released'). */
	const META_STATE = '_hmpos_credit_state';

	/** Meta en la orden: marca de faltante (no se pudo re-debitar al pagar). */
	const META_SHORTFALL = '_hmpos_credit_shortfall';

	/**
	 * Engancha los filtros/acciones de Woo.
	 */
	public static function init() {
		// Sintetiza el cupón virtual a partir del código de crédito.
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'coupon_data' ), 10, 2 );

		// Rechaza el crédito no canjeable (vencido / anulado / sin saldo) con un
		// mensaje claro, tanto al aplicarlo como en cada recálculo.
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate' ), 10, 3 );

		// Etiqueta amable en los totales ("Crédito en tienda").
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );

		// Reserva (débito atómico) al procesar la orden, antes del pago.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reserve_classic' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_blocks' ), 20, 1 );

		// Máquina de estados idempotente sobre las transiciones de la orden:
		//   pagada (processing/completed)  → debe quedar 'reserved' (debitado).
		//   no prospera (failed/cancelled) → debe quedar 'released' (reintegrado).
		//   reembolso total (refunded)     → 'released' (se restituye el crédito).
		// Liberar en 'failed' es necesario porque el checkout de bloques crea una
		// orden NUEVA por cada reintento; si no liberáramos, el saldo quedaría
		// trabado por la orden fallida. El re-débito en 'processing' cubre el caso
		// clásico de "pagar la misma orden" tras un fallo.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'ensure_reserved' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'ensure_reserved' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'ensure_released' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'ensure_released' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'ensure_released' ), 10, 1 );
	}

	/* ------------------------------------------------------------------ *
	 * Reconocimiento de códigos de crédito
	 * ------------------------------------------------------------------ */

	/**
	 * ¿El código tiene forma de crédito HM-XXXX-XXXX?
	 *
	 * @param string $code Código (ya normalizado o no).
	 * @return bool
	 */
	public static function is_credit_code( $code ) {
		$code = HMPOS_Credit::normalize_code( $code );
		return (bool) preg_match( '/^HM-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code );
	}

	/* ------------------------------------------------------------------ *
	 * Cupón virtual
	 * ------------------------------------------------------------------ */

	/**
	 * Devuelve los datos del cupón virtual para un código de crédito.
	 * Si el código no es un crédito conocido, deja pasar el valor original
	 * (para no interferir con cupones reales).
	 *
	 * @param mixed  $data Datos actuales (false si no hay cupón).
	 * @param string $code Código ingresado.
	 * @return mixed Array de datos del cupón o el valor original.
	 */
	public static function coupon_data( $data, $code ) {
		if ( ! self::is_credit_code( $code ) ) {
			return $data;
		}
		$credit = HMPOS_Credit::get_by_code( $code );
		if ( ! $credit ) {
			return $data; // No existe: que Woo diga "el cupón no existe".
		}

		// El monto disponible es el saldo; Woo lo topa al total del carrito.
		$amount = max( 0, (float) $credit->balance );

		return array(
			'id'                         => self::COUPON_ID_BASE + (int) $credit->id,
			'code'                       => HMPOS_Credit::normalize_code( $code ),
			'amount'                     => $amount,
			'discount_type'              => 'fixed_cart',
			'individual_use'             => false,
			'usage_limit'                => 0,
			'usage_limit_per_user'       => 0,
			'limit_usage_to_x_items'     => 0,
			'free_shipping'              => false,
			'exclude_sale_items'         => false,
			'minimum_amount'             => '',
			'maximum_amount'             => '',
			'product_ids'                => array(),
			'excluded_product_ids'       => array(),
			'product_categories'         => array(),
			'excluded_product_categories' => array(),
			'date_expires'               => null, // El vencimiento lo validamos nosotros.
			'meta_data'                  => array(
				'_hmpos_credit'    => 1,
				'_hmpos_credit_id' => (int) $credit->id,
			),
		);
	}

	/**
	 * Valida que el crédito sea canjeable; si no, lanza una excepción con el
	 * motivo (Woo la captura y la muestra al cliente).
	 *
	 * @param bool        $valid     Validez calculada por Woo.
	 * @param WC_Coupon   $coupon    Cupón.
	 * @param WC_Discounts $discounts Contexto de descuentos.
	 * @return bool
	 * @throws Exception Si el crédito no es canjeable.
	 */
	public static function validate( $valid, $coupon, $discounts ) {
		if ( ! self::is_our_coupon( $coupon ) ) {
			return $valid;
		}
		$credit = HMPOS_Credit::get_by_code( $coupon->get_code() );
		if ( ! HMPOS_Credit::is_redeemable( $credit ) ) {
			throw new Exception( esc_html( HMPOS_Credit::reason_not_redeemable( $credit ) ) );
		}
		return $valid;
	}

	/**
	 * Etiqueta "Crédito en tienda" en la fila de descuento de los totales.
	 *
	 * @param string    $label  Etiqueta por defecto.
	 * @param WC_Coupon $coupon Cupón.
	 * @return string
	 */
	public static function coupon_label( $label, $coupon ) {
		if ( self::is_our_coupon( $coupon ) ) {
			/* translators: %s: código del crédito. */
			return sprintf( __( 'Crédito en tienda (%s)', 'hm-pos' ), $coupon->get_code() );
		}
		return $label;
	}

	/**
	 * ¿Este cupón es un crédito nuestro?
	 *
	 * @param WC_Coupon $coupon Cupón.
	 * @return bool
	 */
	private static function is_our_coupon( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return false;
		}
		if ( 1 === (int) $coupon->get_meta( '_hmpos_credit' ) ) {
			return true;
		}
		return self::is_credit_code( $coupon->get_code() );
	}

	/* ------------------------------------------------------------------ *
	 * Reserva (débito atómico) al procesar la orden
	 * ------------------------------------------------------------------ */

	/**
	 * Reserva en el flujo clásico de checkout.
	 *
	 * @param int      $order_id ID de la orden.
	 * @param array    $data     Datos posteados.
	 * @param WC_Order $order    Orden.
	 * @return void
	 * @throws Exception Si el saldo se agotó (aborta el checkout clásico).
	 */
	public static function reserve_classic( $order_id, $data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		$error = self::reserve( $order );
		if ( $error ) {
			throw new Exception( esc_html( $error ) );
		}
	}

	/**
	 * Reserva en el flujo de bloques (Store API).
	 *
	 * @param WC_Order $order Orden.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Si el saldo se agotó.
	 */
	public static function reserve_blocks( $order ) {
		$error = self::reserve( $order );
		if ( $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'hmpos_credit_unavailable',
				esc_html( $error ),
				409
			);
		}
	}

	/**
	 * Debita atómicamente el crédito por el monto realmente aplicado como
	 * descuento en la orden. Registra meta + ledger. Idempotente por orden.
	 *
	 * @param WC_Order|null $order Orden.
	 * @return string Mensaje de error si no se pudo reservar, o '' si OK.
	 */
	private static function reserve( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		// Ya reservado (reintento del mismo checkout): no volver a debitar.
		if ( $order->get_meta( self::META_ID ) ) {
			return '';
		}

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$code = $coupon_item->get_code();
			if ( ! self::is_credit_code( $code ) ) {
				continue;
			}
			$credit = HMPOS_Credit::get_by_code( $code );
			if ( ! $credit ) {
				continue;
			}

			// Monto que este cupón descontó en la orden (impuestos off ⇒ es el
			// total canjeado). El monto ya viene topado al saldo por el recálculo
			// del carrito; acá se debita EXACTAMENTE ese descuento. Si el débito
			// atómico falla (el saldo se agotó/venció en paralelo, p. ej. un canje
			// en el POS), se aborta: no se debe conceder el descuento sin debitar.
			$used = round( (float) $coupon_item->get_discount(), 2 );
			if ( $used <= 0 ) {
				continue;
			}

			$balance = HMPOS_Credit::debit( (int) $credit->id, $used );
			if ( false === $balance ) {
				// Se agotó/venció entre la aplicación y el pago: abortar.
				return HMPOS_Credit::reason_not_redeemable( HMPOS_Credit::get( (int) $credit->id ) );
			}

			$order->update_meta_data( self::META_ID, (int) $credit->id );
			$order->update_meta_data( self::META_CODE, $code );
			$order->update_meta_data( self::META_USED, $used );
			$order->update_meta_data( self::META_CHANNEL, 'web' );
			$order->update_meta_data( self::META_STATE, 'reserved' );
			$order->save();

			HMPOS_Credit::log(
				(int) $credit->id,
				'redeem',
				-1 * $used,
				$balance,
				array(
					'order_id'  => $order->get_id(),
					'channel'   => 'web',
					'cajero_id' => 0,
					'note'      => 'Canje en checkout web',
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: 1: monto, 2: código de crédito. */
					__( 'Crédito en tienda aplicado: %1$s (código %2$s).', 'hm-pos' ),
					wp_strip_all_tags( wc_price( $used ) ),
					$code
				)
			);

			// Un solo crédito por orden (el checkout no permite más de un cupón
			// del mismo código; si hubiera otro crédito, se reserva en la próxima
			// pasada — pero el caso normal es uno).
			break;
		}

		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Máquina de estados: reservado ↔ liberado
	 * ------------------------------------------------------------------ */

	/**
	 * Datos del canje en la orden, o null si la orden no tiene crédito aplicado.
	 *
	 * @param int $order_id ID de la orden.
	 * @return array{order:WC_Order,credit_id:int,used:float,state:string}|null
	 */
	private static function redemption( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$credit_id = (int) $order->get_meta( self::META_ID );
		$used      = round( (float) $order->get_meta( self::META_USED ), 2 );
		if ( ! $credit_id || $used <= 0 ) {
			return null;
		}
		return array(
			'order'     => $order,
			'credit_id' => $credit_id,
			'used'      => $used,
			'state'     => (string) $order->get_meta( self::META_STATE ),
		);
	}

	/**
	 * Asegura que el crédito esté DEBITADO (orden pagada). Si había sido
	 * liberado por un fallo previo y ahora la misma orden se paga, lo vuelve a
	 * debitar. Idempotente.
	 *
	 * @param int $order_id ID de la orden.
	 * @return void
	 */
	public static function ensure_reserved( $order_id ) {
		$r = self::redemption( $order_id );
		if ( ! $r || 'released' !== $r['state'] ) {
			return; // Sin crédito o ya reservado.
		}
		$order = $r['order'];

		$balance = HMPOS_Credit::debit( $r['credit_id'], $r['used'] );
		if ( false === $balance ) {
			// La orden quedó pagada pero el saldo ya no alcanza (se gastó mientras
			// estaba liberada). No se puede corregir en silencio: se marca para que
			// el comercio lo revise.
			$order->update_meta_data( self::META_SHORTFALL, $r['used'] );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: monto, 2: código. */
					__( 'ATENCIÓN: no se pudo re-debitar el crédito %2$s por %1$s al pagarse la orden (saldo insuficiente). Revisar manualmente.', 'hm-pos' ),
					wp_strip_all_tags( wc_price( $r['used'] ) ),
					(string) $order->get_meta( self::META_CODE )
				)
			);
			return;
		}

		$order->update_meta_data( self::META_STATE, 'reserved' );
		$order->delete_meta_data( self::META_SHORTFALL );
		$order->save();

		HMPOS_Credit::log(
			$r['credit_id'],
			'redeem',
			-1 * $r['used'],
			$balance,
			array(
				'order_id'  => $order->get_id(),
				'channel'   => 'web',
				'cajero_id' => 0,
				'note'      => 'Re-débito al pagarse la orden tras un fallo',
			)
		);
	}

	/**
	 * Asegura que el crédito esté REINTEGRADO (orden fallida/cancelada/reembolsada).
	 * Idempotente.
	 *
	 * @param int $order_id ID de la orden.
	 * @return void
	 */
	public static function ensure_released( $order_id ) {
		$r = self::redemption( $order_id );
		if ( ! $r || 'reserved' !== $r['state'] ) {
			return; // Sin crédito o ya liberado.
		}
		$order = $r['order'];

		$balance = HMPOS_Credit::credit_back( $r['credit_id'], $r['used'] );
		if ( false === $balance ) {
			return;
		}

		$order->update_meta_data( self::META_STATE, 'released' );
		$order->save();

		HMPOS_Credit::log(
			$r['credit_id'],
			'reissue',
			$r['used'],
			$balance,
			array(
				'order_id'  => $order->get_id(),
				'channel'   => 'web',
				'cajero_id' => 0,
				'note'      => 'Reintegro por orden ' . $order->get_status(),
			)
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: monto, 2: código de crédito. */
				__( 'Crédito en tienda reintegrado: %1$s (código %2$s) por orden no completada.', 'hm-pos' ),
				wp_strip_all_tags( wc_price( $r['used'] ) ),
				(string) $order->get_meta( self::META_CODE )
			)
		);
	}
}
