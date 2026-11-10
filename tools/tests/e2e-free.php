<?php
/**
 * Suite del modo FREE: que las compuertas cierren lo que deben y —sobre todo—
 * que NO cierren nada de lo que un POS necesita para vender.
 *
 * Se corre forzando el filtro `keytill_pro_features` a todo en false, porque el
 * default de KEYTILL_Pro hoy es PRO (el plugin no se publicó todavía). Sin esta
 * suite el gating no se ejercitaría nunca hasta el día del lanzamiento.
 *
 * La mitad de abajo es la más importante: comprobar que FREE sigue siendo un
 * POS completo. Un corte que rompe la venta no es un corte, es un bug.
 *
 * Uso:
 *   php -d mysqli.default_socket=<socket> ~/wp-cli.phar --path=<sitio> \
 *       eval-file tools/tests/e2e-free.php
 *
 * DESTRUCTIVO: borra ordenes y sesiones. Solo en un sitio de pruebas dedicado.
 *
 * @package Keytill
 */

$GLOBALS['kf_pass'] = 0;
$GLOBALS['kf_fail'] = 0;
$GLOBALS['kf_msgs'] = array();

/**
 * Registra una aserción.
 *
 * @param bool   $cond  Condición.
 * @param string $label Qué se esperaba.
 * @param string $extra Dato observado.
 */
function kf_ok( $cond, $label, $extra = '' ) {
	if ( $cond ) {
		++$GLOBALS['kf_pass'];
	} else {
		++$GLOBALS['kf_fail'];
		$GLOBALS['kf_msgs'][] = $label . ( $extra ? " ($extra)" : '' );
	}
	printf( "  %s  %s%s\n", $cond ? 'OK   ' : 'FALLA', $label, $extra ? " — $extra" : '' );
}

/**
 * Llama a un endpoint REST en proceso.
 *
 * @param string     $method Verbo.
 * @param string     $route  Ruta.
 * @param array|null $body   Cuerpo.
 * @return array [status, data]
 */
function kf_req( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

/** ¿El error es de licencia y no de permisos? Es la distinción que importa. */
function kf_is_pro_error( $data ) {
	return is_array( $data ) && isset( $data['code'] ) && 'keytill_pro_required' === $data['code'];
}

/** Deja el sitio en cero. */
function kf_reset() {
	foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any' ) ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( array( 'keytill_cash_session', 'keytill_digital_sess' ) as $cpt ) {
		foreach ( get_posts( array( 'post_type' => $cpt, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'keytill_credits' );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'keytill_credit_ledger' );
	foreach ( array( 'DEMO-1001' => array( 24, '24.00' ), 'DEMO-4001' => array( 40, '14.00' ), 'DEMO-1004' => array( 0, '12.90' ) ) as $sku => $base ) {
		$p = wc_get_product( wc_get_product_id_by_sku( $sku ) );
		if ( $p ) {
			$p->set_stock_quantity( $base[0] );
			$p->set_stock_status( $base[0] > 0 ? 'instock' : 'outofstock' );
			$p->set_regular_price( $base[1] );
			$p->set_price( $base[1] );
			$p->save();
		}
	}
}

// ---------------------------------------------------------------------------

$cajero    = get_user_by( 'login', 'cajero1' );
$encargado = get_user_by( 'login', 'encargado1' );
if ( ! $cajero || ! $encargado ) {
	echo "FALTAN los usuarios cajero1 / encargado1\n";
	return;
}

// Modo FREE: todo cerrado.
add_filter(
	'keytill_pro_features',
	function () {
		return array();
	},
	99
);
KEYTILL_Pro::flush();

echo "=== 0. EL MODO ESTA FORZADO A FREE ===\n";
foreach ( KEYTILL_Pro::features() as $f ) {
	kf_ok( ! KEYTILL_Pro::has( $f ), "cerrada: $f" );
}

kf_reset();
do_action( 'rest_api_init' );

$tshirt = wc_get_product( wc_get_product_id_by_sku( 'DEMO-1001' ) );
$mug    = wc_get_product( wc_get_product_id_by_sku( 'DEMO-4001' ) );

echo "\n=== 1. LO QUE FREE **SI** TIENE QUE PODER (un POS completo) ===\n";
wp_set_current_user( $cajero->ID );

list( $s, $d ) = kf_req( 'POST', '/keytill/v1/cash/open', array( 'opening_float' => 100 ) );
$sid = (int) ( $d['register_session_id'] ?? 0 );
kf_ok( $sid > 0, 'abrir la caja', "status $s" );

list( $s, $d ) = kf_req( 'GET', '/keytill/v1/products' );
kf_ok( 200 === $s, 'leer el catalogo', "status $s" );

// venta con vuelto
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/orders', array(
	'items'           => array(
		array( 'product_id' => $tshirt->get_id(), 'qty' => 2 ),
		array( 'product_id' => $mug->get_id(), 'qty' => 1 ),
	),
	'payment_method'  => 'efectivo',
	'amount_tendered' => 100,
	'idempotency_key' => 'free-venta-1',
) );
$oid       = (int) ( $d['order_id'] ?? 0 );
$venta_res = (array) $d;
kf_ok( $oid > 0, 'VENDER y cobrar en efectivo', "status $s, orden $oid" );
kf_ok( abs( (float) ( $d['change'] ?? -1 ) - 38.0 ) < 0.01, 'calcular el vuelto', 'es ' . ( $d['change'] ?? '?' ) );
$tshirt = wc_get_product( $tshirt->get_id() );
kf_ok( 22 === (int) $tshirt->get_stock_quantity(), 'descontar stock', 'es ' . $tshirt->get_stock_quantity() );

// venta con descuento por linea y por venta
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/orders', array(
	'items'           => array( array( 'product_id' => $mug->get_id(), 'qty' => 1, 'disc_type' => 'percent', 'disc_value' => 10 ) ),
	'payment_method'  => 'efectivo',
	'amount_tendered' => 50,
	'idempotency_key' => 'free-venta-desc',
) );
kf_ok( 200 === $s || 201 === $s, 'vender con descuento', "status $s" );

// comprobantes: la venta ya devuelve las dos URLs firmadas
$order = wc_get_order( $oid );
kf_ok( ! empty( $venta_res['print_url'] ), 'comprobante interno disponible', $venta_res['print_url'] ?? '-' );
kf_ok( ! empty( $venta_res['gift_url'] ), 'TICKET DE CAMBIO disponible', $venta_res['gift_url'] ?? '-' );

// clientes
list( $s, $d ) = kf_req( 'GET', '/keytill/v1/orders/list' );
kf_ok( 200 === $s, 'listado de pedidos', "status $s" );
list( $s, $d ) = kf_req( 'GET', '/keytill/v1/admin/products' );
kf_ok( 200 === $s, 'listado de productos', "status $s" );

// devolucion en efectivo (lo que FREE sabe hacer)
$req = new WP_REST_Request( 'GET', '/keytill/v1/orders/lookup' );
$req->set_param( 'number', (string) $order->get_order_number() );
$look  = rest_do_request( $req )->get_data();
$item  = $look['items'][0] ?? null;
if ( $item ) {
	list( $s, $d ) = kf_req( 'POST', '/keytill/v1/orders/refund', array(
		'order_id'      => $oid,
		'lines'         => array( array( 'item_id' => $item['item_id'], 'qty' => 1 ) ),
		'refund_method' => 'efectivo',
	) );
	kf_ok( 200 === $s || 201 === $s, 'DEVOLVER en efectivo', "status $s" );
	kf_ok( abs( (float) ( $d['cash_back'] ?? 0 ) - 24.0 ) < 0.01, 'el reintegro sale del cajon', 'es ' . ( $d['cash_back'] ?? '?' ) );
}

// edicion inline de precio y stock: trabajo diario, queda en FREE.
// Va como encargado: editar productos exige manage, el cajero ve en solo lectura.
wp_set_current_user( $encargado->ID );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/products/' . $tshirt->get_id(), array( 'regular_price' => '25.00' ) );
kf_ok( 200 === $s || 201 === $s, 'editar precio inline', "status $s" );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/products/' . $tshirt->get_id(), array( 'stock_quantity' => 30 ) );
kf_ok( 200 === $s || 201 === $s, 'editar stock inline', "status $s" );

// arqueo con desglose por medio: explicitamente en FREE
wp_set_current_user( $encargado->ID );
list( $s, $d ) = kf_req( 'GET', '/keytill/v1/cash/current' );
$ses = $d['session'] ?? $d['closable'];
kf_ok( ! empty( $ses['breakdown'] ), 'el arqueo trae DESGLOSE POR MEDIO', is_array( $ses['breakdown'] ?? null ) ? count( $ses['breakdown'] ) . ' medios' : '-' );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/cash/close', array( 'session_id' => $sid, 'counted_cash' => 100 ) );
kf_ok( 200 === $s, 'CERRAR la caja con conteo', "status $s" );
kf_ok( array_key_exists( 'difference', (array) $d ), 'y devuelve la diferencia', 'es ' . ( $d['difference'] ?? '?' ) );

echo "\n=== 2. LO QUE FREE **NO** TIENE QUE PODER ===\n";

// caja digital / conciliacion / libro
foreach ( array(
	array( 'GET', '/keytill/v1/digital/current', null, 'caja digital (consulta)' ),
	array( 'POST', '/keytill/v1/digital/open', array( 'initial' => 100 ), 'abrir la caja digital' ),
	array( 'POST', '/keytill/v1/digital/close', array(), 'cerrar la caja digital' ),
	array( 'POST', '/keytill/v1/digital/verify', array( 'order_id' => 1, 'status' => 'verificada' ), 'conciliacion' ),
	array( 'GET', '/keytill/v1/cashbank/summary', null, 'pestaña Caja/Banco' ),
	array( 'GET', '/keytill/v1/cashbank/ledger', null, 'libro de movimientos' ),
) as $c ) {
	list( $s, $d ) = kf_req( $c[0], $c[1], $c[2] );
	kf_ok( 403 === $s && kf_is_pro_error( $d ), $c[3] . ': cerrada', "status $s, code " . ( $d['code'] ?? '-' ) );
}

// stock por sucursal
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/products/bulk-branch', array( 'ids' => array( $tshirt->get_id() ), 'branch_id' => 'x' ) );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'asignar sucursal en lote: cerrada', "status $s" );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/products/' . $tshirt->get_id() . '/move-stock', array( 'from' => 'a', 'to' => 'b', 'qty' => 1 ) );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'mover stock entre sucursales: cerrada', "status $s" );

// editor completo
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/products/' . $tshirt->get_id() . '/variations', array( 'attributes' => array() ) );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'generar variaciones: cerrada', "status $s" );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/attributes', array( 'name' => 'Talle' ) );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'crear atributo: cerrada', "status $s" );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/admin/media', array() );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'subir imagen: cerrada', "status $s" );

// emitir credito
$order2 = wc_get_order( $oid );
$req    = new WP_REST_Request( 'GET', '/keytill/v1/orders/lookup' );
$req->set_param( 'number', (string) $order2->get_order_number() );
$look2 = rest_do_request( $req )->get_data();
$it2   = $look2['items'][0] ?? null;
if ( $it2 && (int) ( $it2['qty_refundable'] ?? 0 ) > 0 ) {
	wp_set_current_user( $cajero->ID );
	kf_req( 'POST', '/keytill/v1/cash/open', array( 'opening_float' => 10 ) );
	list( $s, $d ) = kf_req( 'POST', '/keytill/v1/orders/refund', array(
		'order_id'      => $oid,
		'lines'         => array( array( 'item_id' => $it2['item_id'], 'qty' => 1 ) ),
		'refund_method' => 'credito',
	) );
	kf_ok( 403 === $s && kf_is_pro_error( $d ), 'devolver A CREDITO: cerrada', "status $s" );
}

// multi-caja: ya hay una abierta (la del cajero), el encargado no puede abrir otra
wp_set_current_user( $encargado->ID );
list( $s, $d ) = kf_req( 'POST', '/keytill/v1/cash/open', array( 'opening_float' => 50 ) );
kf_ok( 403 === $s && kf_is_pro_error( $d ), 'una SEGUNDA caja en paralelo: cerrada', "status $s, code " . ( $d['code'] ?? '-' ) );

echo "\n=== 3. CANJEAR CREDITO FUNCIONA IGUAL (la regla que no se negocia) ===\n";
// se emite a mano, saltando la compuerta (simula un codigo emitido cuando tenia PRO)
$emitido = KEYTILL_Credit::issue( 50.0, array( 'kind' => 'giftcard', 'channel' => 'admin' ) );
$codigo  = is_array( $emitido ) ? ( $emitido['code'] ?? '' ) : '';
kf_ok( '' !== $codigo, 'hay un credito emitido de antes', is_wp_error( $emitido ) ? $emitido->get_error_message() : $codigo );
if ( '' !== $codigo ) {
	$row = KEYTILL_Credit::get_by_code( $codigo );
	wp_set_current_user( $cajero->ID );
	$req = new WP_REST_Request( 'GET', '/keytill/v1/credit/lookup' );
	$req->set_param( 'code', $codigo );
	$res = rest_do_request( $req );
	kf_ok( 200 === $res->get_status(), 'CONSULTAR el codigo funciona en FREE', 'status ' . $res->get_status() );
	kf_ok( abs( (float) ( $res->get_data()['balance'] ?? 0 ) - 50.0 ) < 0.01, 'y devuelve el saldo', 'es ' . ( $res->get_data()['balance'] ?? '?' ) );
	kf_ok( KEYTILL_Credit::is_redeemable( $row ), 'y sigue siendo canjeable' );
}

printf( "\n================  %d OK  ·  %d FALLA  ================\n", $GLOBALS['kf_pass'], $GLOBALS['kf_fail'] );
foreach ( $GLOBALS['kf_msgs'] as $m ) {
	echo "  pendiente: $m\n";
}
