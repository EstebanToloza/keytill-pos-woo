<?php
/**
 * Comprobante interno imprimible (no fiscal) de una venta del POS.
 *
 * Enfoque: HTML autocontenido + window.print() del navegador (sin PDF ni
 * dependencias). La MISMA plantilla sirve al POS (botón "Imprimir" en el modal
 * de confirmación) y al admin del pedido (botón "Imprimir comprobante POS").
 *
 * Ruta: GET al front con ?keytill_print=<order_id>&_wpnonce=<nonce>. Requiere la
 * capability del POS o poder editar órdenes de Woo, y un nonce por-orden.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Print {

	/** Query var que dispara la impresión del comprobante. */
	const QV = 'keytill_print';

	/** Query var que dispara la impresión del voucher de crédito (devolución). */
	const QV_VOUCHER = 'keytill_voucher';

	/** Query var que dispara la impresión del ticket de cambio (sin precios). */
	const QV_GIFT = 'keytill_gift';

	/**
	 * Engancha el handler del front y el botón en el admin del pedido.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		// Botón en la pantalla de edición del pedido (HPOS y legacy usan este hook).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_button' ) );
	}

	/**
	 * Nonce action por-orden (así un nonce no sirve para imprimir otra).
	 *
	 * @param int $order_id ID de la orden.
	 * @return string
	 */
	private static function nonce_action( $order_id ) {
		return 'keytill_print_' . (int) $order_id;
	}

	/**
	 * URL de impresión de una orden (con nonce fresco).
	 *
	 * @param int $order_id ID de la orden.
	 * @return string
	 */
	public static function print_url( $order_id ) {
		$order_id = (int) $order_id;
		return add_query_arg(
			array(
				self::QV  => $order_id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $order_id ) ),
			),
			home_url( '/' )
		);
	}

	/** Acción del nonce del voucher (por código). */
	private static function voucher_nonce_action( $code ) {
		return 'keytill_voucher_' . (string) $code;
	}

	/**
	 * URL de impresión del voucher de crédito (con nonce fresco).
	 *
	 * @param string $code Código del crédito.
	 * @return string
	 */
	public static function voucher_url( $code ) {
		$code = (string) $code;
		return add_query_arg(
			array(
				self::QV_VOUCHER => rawurlencode( $code ),
				'_wpnonce'       => wp_create_nonce( self::voucher_nonce_action( $code ) ),
			),
			home_url( '/' )
		);
	}

	/** Acción del nonce del ticket de cambio (por orden). */
	private static function gift_nonce_action( $order_id ) {
		return 'keytill_gift_' . (int) $order_id;
	}

	/**
	 * URL de impresión del ticket de cambio de una orden (sin precios, escaneable).
	 *
	 * @param int $order_id ID de la orden.
	 * @return string
	 */
	public static function gift_url( $order_id ) {
		$order_id = (int) $order_id;
		return add_query_arg(
			array(
				self::QV_GIFT => $order_id,
				'_wpnonce'    => wp_create_nonce( self::gift_nonce_action( $order_id ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * ¿El usuario actual puede imprimir comprobantes?
	 *
	 * @return bool
	 */
	private static function can_print() {
		return current_user_can( KEYTILL_CAP ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Si la request pide imprimir, valida y renderiza el comprobante; corta.
	 */
	public static function maybe_render() {
		// Voucher de crédito (devolución): ?keytill_voucher=<code>&_wpnonce=…
		if ( isset( $_GET[ self::QV_VOUCHER ] ) ) {
			$code = KEYTILL_Credit::normalize_code( sanitize_text_field( wp_unslash( $_GET[ self::QV_VOUCHER ] ) ) );
			if ( '' === $code ) {
				return;
			}
			if ( ! self::can_print() ) {
				wp_die( esc_html__( 'No tenés permiso para imprimir este voucher.', 'keytill' ), 403 );
			}
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::voucher_nonce_action( $code ) ) ) {
				wp_die( esc_html__( 'El enlace del voucher venció. Volvé a generarlo desde el POS.', 'keytill' ), 403 );
			}
			$credit = KEYTILL_Credit::get_by_code( $code );
			if ( ! $credit ) {
				wp_die( esc_html__( 'No se encontró el crédito.', 'keytill' ), 404 );
			}
			self::render_voucher( $credit );
			exit;
		}

		// Ticket de cambio (sin precios): ?keytill_gift=<id>&_wpnonce=…
		if ( isset( $_GET[ self::QV_GIFT ] ) ) {
			$order_id = absint( $_GET[ self::QV_GIFT ] );
			if ( ! $order_id ) {
				return;
			}
			if ( ! self::can_print() ) {
				wp_die( esc_html__( 'No tenés permiso para imprimir este ticket.', 'keytill' ), 403 );
			}
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::gift_nonce_action( $order_id ) ) ) {
				wp_die( esc_html__( 'El enlace del ticket de cambio venció. Reabrilo desde el POS.', 'keytill' ), 403 );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_die( esc_html__( 'No se encontró el pedido.', 'keytill' ), 404 );
			}
			self::render_gift( $order );
			exit;
		}

		if ( ! isset( $_GET[ self::QV ] ) ) {
			return;
		}
		$order_id = absint( $_GET[ self::QV ] );
		if ( ! $order_id ) {
			return;
		}

		if ( ! self::can_print() ) {
			wp_die( esc_html__( 'No tenés permiso para imprimir este comprobante.', 'keytill' ), 403 );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $order_id ) ) ) {
			wp_die( esc_html__( 'El enlace de impresión venció. Reabrí el comprobante desde el pedido.', 'keytill' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'No se encontró el pedido.', 'keytill' ), 404 );
		}

		self::render( $order );
		exit;
	}

	/**
	 * Botón "Imprimir comprobante POS" en la ficha del pedido (admin).
	 * Solo para órdenes creadas por el POS.
	 *
	 * @param WC_Order $order Orden.
	 */
	public static function admin_button( $order ) {
		if ( ! $order instanceof WC_Order || 'keytill' !== $order->get_created_via() ) {
			return;
		}
		$url = self::print_url( $order->get_id() );
		echo '<p class="form-field form-field-wide"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" class="button">'
			. esc_html__( 'Imprimir comprobante POS', 'keytill' ) . '</a></p>';
	}

	/**
	 * Renderiza el HTML autocontenido del comprobante (A4 media hoja) y lanza
	 * el diálogo de impresión del navegador al cargar.
	 *
	 * @param WC_Order $order Orden.
	 */
	private static function render( $order ) {
		$biz      = class_exists( 'KEYTILL_Settings' ) ? KEYTILL_Settings::business() : array(
			'name'     => get_bloginfo( 'name' ),
			'tax_id'   => '',
			'address'  => '',
			'phone'    => '',
			'subtitle' => __( 'Resumen de compra', 'keytill' ),
			'footer'   => __( 'Documento no válido como factura. ¡Gracias por su compra!', 'keytill' ),
			'logo'     => 0,
		);
		$store    = $biz['name'];
		$subtitle = isset( $biz['subtitle'] ) ? $biz['subtitle'] : '';
		$logo_id  = isset( $biz['logo'] ) ? (int) $biz['logo'] : 0;
		$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$number   = $order->get_order_number();
		$date     = $order->get_date_created();
		$date_str = $date ? $date->date_i18n( 'd/m/Y H:i' ) : '';

		// Cajero: se muestra el NOMBRE (nunca el email/login). Si no hay nombre
		// cargado, no se muestra nada.
		$cashier    = '';
		$cashier_id = (int) $order->get_meta( '_keytill_cajero_id' );
		if ( $cashier_id ) {
			$cu = get_userdata( $cashier_id );
			if ( $cu ) {
				$full = trim( $cu->first_name . ' ' . $cu->last_name );
				if ( '' !== $full ) {
					$cashier = $full;
				} elseif ( $cu->display_name && false === strpos( $cu->display_name, '@' ) ) {
					$cashier = $cu->display_name;
				}
			}
		}

		// Cliente (fiscal, si se cargó).
		$cust_name = $order->get_meta( '_keytill_customer_name' );
		if ( '' === $cust_name ) {
			$cust_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$cust_doc = $order->get_meta( '_keytill_customer_doc' );
		$cust_iva = $order->get_meta( '_keytill_customer_iva' );

		// Líneas: subtotal para detectar descuento.
		$subtotal = 0.0;
		$rows     = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$subtotal += (float) $item->get_subtotal();
			$attrs     = wc_display_item_meta(
				$item,
				array(
					'before'    => '',
					'after'     => '',
					'separator' => ' · ',
					'echo'      => false,
					'autop'     => false,
				)
			);
			$rows[] = array(
				'name'  => $item->get_name(),
				'attrs' => wp_strip_all_tags( $attrs ),
				'qty'   => (int) $item->get_quantity(),
				'total' => (float) $item->get_total(),
			);
		}

		$total    = (float) $order->get_total();
		$discount = round( $subtotal - array_sum( wp_list_pluck( $rows, 'total' ) ), 2 );

		// Pago.
		$pay_title   = $order->get_payment_method_title();
		$credit_used = (float) $order->get_meta( '_keytill_credit_used' );
		$credit_code = $order->get_meta( '_keytill_credit_code' );
		$tendered    = $order->get_meta( '_keytill_tendered' );
		$change      = $order->get_meta( '_keytill_change' );

		// Gift cards emitidas en esta venta.
		$giftcards = KEYTILL_Credit_Product::codes_for_order( $order );

		// A partir de acá, HTML completo (sin theme). Todo escapado.
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( __( 'Comprobante venta #%s', 'keytill' ), $number ) ); ?></title>
<style>
	/* Ticket térmico de 58 mm (área imprimible ~48 mm). Fuente monoespaciada para
	   que los importes queden alineados; todo en negro sobre blanco. */
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; background:#e9edf2; color:#000;
		font-family:'Courier New',ui-monospace,monospace; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.t{ width:48mm; margin:10px auto; background:#fff; padding:3mm 2mm; box-shadow:0 2px 14px rgba(0,0,0,.12);
		font-size:11px; line-height:1.35; }
	.c{ text-align:center; }
	.b{ font-weight:700; }
	.muted{ color:#333; }
	.logo{ display:block; max-width:40mm; max-height:22mm; height:auto; margin:0 auto 4px; }
	.store{ font-size:14px; font-weight:700; text-align:center; letter-spacing:.3px; }
	.sub{ text-align:center; font-size:10px; margin-top:1px; }
	.biz{ text-align:center; font-size:9px; margin-top:2px; }
	.who{ text-align:center; font-size:10px; }
	.hr{ border:0; border-top:1px dashed #000; margin:6px 0; }
	.doc{ text-align:center; }
	.doc b{ font-size:12px; }
	.row{ display:flex; justify-content:space-between; gap:6px; }
	.row .l{ flex:1 1 auto; word-break:break-word; }
	.row .v{ flex:0 0 auto; white-space:nowrap; font-variant-numeric:tabular-nums; text-align:right; }
	.it{ margin:3px 0; }
	.it .at{ font-size:9px; color:#333; }
	.grand{ font-size:13px; font-weight:700; }
	.gc{ border:1px dashed #000; padding:4px 6px; margin-top:6px; }
	.gc .h{ font-size:9px; text-transform:uppercase; letter-spacing:.5px; text-align:center; margin-bottom:2px; }
	.gc .code{ display:flex; justify-content:space-between; gap:6px; }
	.foot{ text-align:center; font-size:9px; margin-top:8px; }
	@media print{
		html,body{ background:#fff; }
		.t{ box-shadow:none; margin:0; width:48mm; }
		@page{ size:58mm auto; margin:0; }
	}
</style>
</head>
<body>
	<div class="t">
		<?php if ( $logo_src ) : ?><img class="logo" src="<?php echo esc_url( $logo_src ); ?>" alt=""><?php endif; ?>
		<div class="store"><?php echo esc_html( $store ); ?></div>
		<?php if ( '' !== $subtitle ) : ?><div class="sub"><?php echo esc_html( $subtitle ); ?></div><?php endif; ?>
		<?php if ( $biz['tax_id'] || $biz['address'] || $biz['phone'] ) : ?>
			<div class="biz"><?php echo esc_html( implode( ' · ', array_filter( array( $biz['tax_id'], $biz['address'], $biz['phone'] ) ) ) ); ?></div>
		<?php endif; ?>

		<hr class="hr">
		<div class="doc"><span class="b"><?php echo esc_html( sprintf( __( 'Venta #%s', 'keytill' ), $number ) ); ?></span><br><?php echo esc_html( $date_str ); ?></div>
		<?php if ( $cashier ) : ?><div class="who muted"><?php esc_html_e( 'Cajero', 'keytill' ); ?>: <?php echo esc_html( $cashier ); ?></div><?php endif; ?>
		<?php if ( '' !== $cust_name || '' !== $cust_doc ) : ?>
			<div class="who muted">
				<?php esc_html_e( 'Cliente', 'keytill' ); ?>: <?php echo esc_html( $cust_name ? $cust_name : __( 'Consumidor final', 'keytill' ) ); ?>
				<?php if ( '' !== $cust_doc ) : ?><br><?php echo esc_html( $cust_doc ); ?><?php endif; ?>
				<?php if ( '' !== $cust_iva ) : ?> · <?php echo esc_html( $cust_iva ); ?><?php endif; ?>
			</div>
		<?php endif; ?>

		<hr class="hr">
		<?php
		foreach ( $rows as $r ) :
			$unit = $r['qty'] > 0 ? $r['total'] / $r['qty'] : $r['total'];
			?>
			<div class="it">
				<div class="nm"><?php echo esc_html( $r['name'] ); ?></div>
				<?php if ( '' !== $r['attrs'] ) : ?><div class="at"><?php echo esc_html( $r['attrs'] ); ?></div><?php endif; ?>
				<div class="row">
					<span class="l"><?php echo esc_html( $r['qty'] ); ?> × <?php echo wp_kses_post( wc_price( $unit, array( 'currency' => $order->get_currency() ) ) ); ?></span>
					<span class="v"><?php echo wp_kses_post( wc_price( $r['total'], array( 'currency' => $order->get_currency() ) ) ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>

		<hr class="hr">
		<?php if ( $discount > 0 ) : ?>
			<div class="row"><span class="l"><?php esc_html_e( 'Subtotal', 'keytill' ); ?></span><span class="v"><?php echo wp_kses_post( wc_price( $subtotal, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
			<div class="row"><span class="l"><?php esc_html_e( 'Descuento', 'keytill' ); ?></span><span class="v">−<?php echo wp_kses_post( wc_price( $discount, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
		<?php endif; ?>
		<div class="row grand"><span class="l"><?php esc_html_e( 'TOTAL', 'keytill' ); ?></span><span class="v"><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>

		<hr class="hr">
		<div class="row"><span class="l"><?php esc_html_e( 'Medio de pago', 'keytill' ); ?></span><span class="v"><?php echo esc_html( $pay_title ); ?></span></div>
		<?php if ( $credit_used > 0 ) : ?>
			<div class="row"><span class="l"><?php esc_html_e( 'Crédito en tienda', 'keytill' ); ?><?php echo $credit_code ? ' (' . esc_html( $credit_code ) . ')' : ''; ?></span><span class="v"><?php echo wp_kses_post( wc_price( $credit_used, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
		<?php endif; ?>
		<?php if ( '' !== (string) $tendered ) : ?>
			<div class="row"><span class="l"><?php esc_html_e( 'Recibido', 'keytill' ); ?></span><span class="v"><?php echo wp_kses_post( wc_price( (float) $tendered, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
		<?php endif; ?>
		<?php if ( '' !== (string) $change ) : ?>
			<div class="row"><span class="l"><?php esc_html_e( 'Vuelto', 'keytill' ); ?></span><span class="v"><?php echo wp_kses_post( wc_price( (float) $change, array( 'currency' => $order->get_currency() ) ) ); ?></span></div>
		<?php endif; ?>

		<?php if ( ! empty( $giftcards ) ) : ?>
			<div class="gc">
				<div class="h"><?php esc_html_e( 'Gift cards emitidas', 'keytill' ); ?></div>
				<?php foreach ( $giftcards as $g ) : ?>
					<div class="code">
						<span><?php echo esc_html( $g['code'] ); ?></span>
						<span><?php echo wp_kses_post( wc_price( (float) $g['amount'], array( 'currency' => $order->get_currency() ) ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<hr class="hr">
		<div class="foot"><?php echo esc_html( $biz['footer'] ); ?></div>
	</div>
	<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
		<?php
	}

	/**
	 * Voucher térmico de 58 mm del crédito emitido en una devolución: monto,
	 * vencimiento, código y código de barras (Code 128) para escanear al canjear.
	 *
	 * @param object $credit Fila de KEYTILL_Credit::get_by_code() (->code, ->balance, ->expires_at).
	 */
	private static function render_voucher( $credit ) {
		$biz = apply_filters(
			'keytill_receipt_business',
			array( 'name' => get_bloginfo( 'name' ), 'logo' => 0 )
		);
		$store   = isset( $biz['name'] ) ? $biz['name'] : get_bloginfo( 'name' );
		$code    = (string) $credit->code;
		$amount  = (float) $credit->balance;
		$expires = ! empty( $credit->expires_at ) ? mysql2date( 'd/m/Y', $credit->expires_at ) : '';
		$svg     = class_exists( 'KEYTILL_Labels' ) ? KEYTILL_Labels::code128_svg( $code ) : '';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( __( 'Voucher %s', 'keytill' ), $code ) ); ?></title>
<style>
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; background:#e9edf2; color:#000;
		font-family:'Courier New',ui-monospace,monospace; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.t{ width:48mm; margin:10px auto; background:#fff; padding:3mm 2mm; box-shadow:0 2px 14px rgba(0,0,0,.12);
		font-size:11px; line-height:1.35; text-align:center; }
	.store{ font-size:13px; font-weight:700; letter-spacing:.3px; }
	.kind{ font-size:11px; font-weight:700; letter-spacing:1px; margin-top:2px; }
	.hr{ border:0; border-top:1px dashed #000; margin:6px 0; }
	.amt{ font-size:20px; font-weight:800; margin:2px 0; }
	.lbl{ font-size:9px; letter-spacing:.5px; }
	.code{ font-size:15px; font-weight:800; letter-spacing:1px; margin-top:2px; }
	.bc{ width:100%; height:14mm; margin:4px 0 0; }
	.bc svg{ width:100%; height:14mm; display:block; }
	.exp{ font-size:10px; margin-top:4px; }
	.foot{ font-size:9px; margin-top:8px; }
	@media print{
		html,body{ background:#fff; }
		.t{ box-shadow:none; margin:0; width:48mm; }
		@page{ size:58mm auto; margin:0; }
	}
</style>
<style id="keytill-pagesize">@page{ size:58mm auto; margin:0; }</style>
</head>
<body>
	<div class="t">
		<div class="store"><?php echo esc_html( $store ); ?></div>
		<div class="kind"><?php esc_html_e( 'CRÉDITO EN TIENDA', 'keytill' ); ?></div>
		<div class="lbl"><?php esc_html_e( 'Voucher de devolución', 'keytill' ); ?></div>
		<hr class="hr">
		<div class="lbl"><?php esc_html_e( 'MONTO', 'keytill' ); ?></div>
		<div class="amt"><?php echo wp_kses_post( wc_price( $amount ) ); ?></div>
		<hr class="hr">
		<div class="lbl"><?php esc_html_e( 'CÓDIGO', 'keytill' ); ?></div>
		<div class="code"><?php echo esc_html( $code ); ?></div>
		<?php if ( $svg ) : ?>
			<span class="bc"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG generado internamente ?></span>
		<?php endif; ?>
		<?php if ( '' !== $expires ) : ?>
			<div class="exp"><?php esc_html_e( 'Válido hasta', 'keytill' ); ?> <?php echo esc_html( $expires ); ?></div>
		<?php endif; ?>
		<hr class="hr">
		<div class="foot"><?php esc_html_e( 'Presentá este voucher para usar tu crédito. No es dinero en efectivo.', 'keytill' ); ?></div>
	</div>
	<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
		<?php
	}

	/**
	 * Ticket de cambio (regalo) térmico de 58 mm: mismas prendas que la venta
	 * pero SIN precios ni totales, con código de barras del número de orden para
	 * escanearlo al hacer la devolución/cambio. Muestra la validez configurada.
	 *
	 * @param WC_Order $order Orden.
	 */
	private static function render_gift( $order ) {
		$biz = class_exists( 'KEYTILL_Settings' ) ? KEYTILL_Settings::business() : array(
			'name' => get_bloginfo( 'name' ),
			'logo' => 0,
		);
		$store    = isset( $biz['name'] ) ? $biz['name'] : get_bloginfo( 'name' );
		$logo_id  = isset( $biz['logo'] ) ? (int) $biz['logo'] : 0;
		$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$number   = (string) $order->get_order_number();
		$date     = $order->get_date_created();
		$date_str = $date ? $date->date_i18n( 'd/m/Y H:i' ) : '';

		// Validez: fecha de la venta + días configurados (0 = sin plazo).
		$days      = class_exists( 'KEYTILL_Settings' ) ? (int) KEYTILL_Settings::get( 'exchange_days' ) : 30;
		$valid_str = '';
		if ( $days > 0 ) {
			$ts        = $date ? $date->getTimestamp() : current_time( 'timestamp' );
			$valid_str = wp_date( 'd/m/Y', $ts + $days * DAY_IN_SECONDS );
		}

		// Prendas: descripción + atributos + cantidad (SIN precios).
		$rows = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$attrs  = wc_display_item_meta(
				$item,
				array(
					'before'    => '',
					'after'     => '',
					'separator' => ' · ',
					'echo'      => false,
					'autop'     => false,
				)
			);
			$rows[] = array(
				'name'  => $item->get_name(),
				'attrs' => wp_strip_all_tags( $attrs ),
				'qty'   => (int) $item->get_quantity(),
			);
		}

		$svg = class_exists( 'KEYTILL_Labels' ) ? KEYTILL_Labels::code128_svg( $number ) : '';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( __( 'Ticket de cambio #%s', 'keytill' ), $number ) ); ?></title>
<style>
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; background:#e9edf2; color:#000;
		font-family:'Courier New',ui-monospace,monospace; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.t{ width:48mm; margin:10px auto; background:#fff; padding:3mm 2mm; box-shadow:0 2px 14px rgba(0,0,0,.12);
		font-size:11px; line-height:1.35; }
	.logo{ display:block; max-width:40mm; max-height:22mm; height:auto; margin:0 auto 4px; }
	.store{ font-size:14px; font-weight:700; text-align:center; letter-spacing:.3px; }
	.title{ text-align:center; font-size:12px; font-weight:700; letter-spacing:1px; margin-top:2px; }
	.hr{ border:0; border-top:1px dashed #000; margin:6px 0; }
	.doc{ text-align:center; }
	.it{ margin:3px 0; }
	.it .nm{ font-weight:700; }
	.it .at{ font-size:9px; color:#333; }
	.it .qty{ font-size:10px; }
	.bc{ text-align:center; margin:8px 0 2px; }
	.bc svg{ max-width:100%; height:auto; }
	.bc .num{ font-size:10px; letter-spacing:1px; }
	.note{ text-align:center; font-size:10px; margin-top:6px; font-weight:700; }
	.foot{ text-align:center; font-size:9px; margin-top:6px; }
	@media print{
		html,body{ background:#fff; }
		.t{ box-shadow:none; margin:0; width:48mm; }
		@page{ size:58mm auto; margin:0; }
	}
</style>
</head>
<body>
	<div class="t">
		<?php if ( $logo_src ) : ?><img class="logo" src="<?php echo esc_url( $logo_src ); ?>" alt=""><?php endif; ?>
		<div class="store"><?php echo esc_html( $store ); ?></div>
		<div class="title"><?php esc_html_e( 'TICKET DE CAMBIO', 'keytill' ); ?></div>

		<hr class="hr">
		<div class="doc"><span style="font-weight:700"><?php echo esc_html( sprintf( __( 'Venta #%s', 'keytill' ), $number ) ); ?></span><br><?php echo esc_html( $date_str ); ?></div>

		<hr class="hr">
		<?php foreach ( $rows as $r ) : ?>
			<div class="it">
				<div class="nm"><?php echo esc_html( $r['name'] ); ?></div>
				<?php if ( '' !== $r['attrs'] ) : ?><div class="at"><?php echo esc_html( $r['attrs'] ); ?></div><?php endif; ?>
				<div class="qty"><?php echo esc_html( sprintf( __( 'Cantidad: %d', 'keytill' ), $r['qty'] ) ); ?></div>
			</div>
		<?php endforeach; ?>

		<?php if ( '' !== $svg ) : ?>
			<div class="bc">
				<?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG propio. ?>
				<div class="num mono"><?php echo esc_html( $number ); ?></div>
			</div>
		<?php endif; ?>

		<hr class="hr">
		<div class="note">
			<?php
			if ( '' !== $valid_str ) {
				echo esc_html( sprintf( __( 'Válido para cambios hasta el %1$s (%2$d días).', 'keytill' ), $valid_str, $days ) );
			} else {
				esc_html_e( 'Presentá este ticket para realizar cambios.', 'keytill' );
			}
			?>
		</div>
		<div class="foot"><?php esc_html_e( 'No es comprobante de pago.', 'keytill' ); ?></div>
	</div>
	<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
		<?php
	}
}
