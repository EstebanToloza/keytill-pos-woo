<?php
/**
 * Suite end-to-end del POS contra un WordPress real.
 *
 * Ejercita los endpoints REST con un usuario y permisos reales, que es la unica
 * forma de agarrar cosas que ningun lint ve (plurales de gettext, deteccion del
 * almacenamiento de ordenes, descuento de stock, idempotencia).
 *
 * Uso:
 *   php -d mysqli.default_socket=<socket> ~/wp-cli.phar --path=<sitio> \
 *       eval-file tools/tests/e2e-pos.php
 *
 * Requisitos del sitio: WooCommerce activo, Keytill activo, productos demo
 * cargados (`wp keytill seed-demo`) y los usuarios cajero1 / encargado1.
 *
 * DESTRUCTIVO: borra todas las ordenes y sesiones de caja del sitio antes de
 * arrancar. Correr SOLO en un sitio de pruebas dedicado.
 *
 * @package Keytill
 */

$GLOBALS['kt_pass'] = 0;
$GLOBALS['kt_fail'] = 0;
$GLOBALS['kt_msgs'] = array();

/**
 * Registra una aserción.
 *
 * @param bool   $cond  Condición a verificar.
 * @param string $label Qué se esperaba.
 * @param string $extra Dato observado.
 */
function kt_ok( $cond, $label, $extra = '' ) {
	if ( $cond ) {
		++$GLOBALS['kt_pass'];
	} else {
		++$GLOBALS['kt_fail'];
		$GLOBALS['kt_msgs'][] = $label . ( $extra ? " ($extra)" : '' );
	}
	printf( "  %s  %s%s\n", $cond ? 'OK   ' : 'FALLA', $label, $extra ? " — $extra" : '' );
}

/**
 * Llama a un endpoint REST en proceso (sin HTTP ni nonce).
 *
 * @param string     $method Verbo.
 * @param string     $route  Ruta.
 * @param array|null $body   Cuerpo JSON.
 * @return array [status, data]
 */
function kt_req( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

/** Deja el sitio en cero: ordenes (en cualquier store) y sesiones de caja. */
function kt_reset() {
	foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any' ) ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true ); // la API borra en el store que corresponda
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
	delete_option( 'keytill_digital_last_balance' );
	delete_option( 'keytill_digital_seeded' );
	foreach ( array( 'DEMO-1001' => 24, 'DEMO-4001' => 40, 'DEMO-1004' => 0 ) as $sku => $qty ) {
		$p = wc_get_product( wc_get_product_id_by_sku( $sku ) );
		if ( $p ) {
			$p->set_stock_quantity( $qty );
			$p->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			$p->save();
		}
	}
}

/** Cuenta ordenes reales, sin importar el store. */
function kt_orders() {
	return count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any' ) ) );
}

// ---------------------------------------------------------------------------

$cajero    = get_user_by( 'login', 'cajero1' );
$encargado = get_user_by( 'login', 'encargado1' );
if ( ! $cajero || ! $encargado ) {
	echo "FALTAN los usuarios cajero1 / encargado1\n";
	return;
}

kt_reset();
do_action( 'rest_api_init' );

$tshirt = wc_get_product( wc_get_product_id_by_sku( 'DEMO-1001' ) ); // 24.00, stock 24
$mug    = wc_get_product( wc_get_product_id_by_sku( 'DEMO-4001' ) ); // 14.00, stock 40
$socks  = wc_get_product( wc_get_product_id_by_sku( 'DEMO-1004' ) ); // stock 0

echo "\n=== 1. PERMISOS ===\n";
wp_set_current_user( 0 );
list( $s ) = kt_req( 'GET', '/keytill/v1/products' );
kt_ok( 401 === $s || 403 === $s, 'anonimo no puede leer productos', "status $s" );
list( $s ) = kt_req( 'GET', '/keytill/v1/cashbank/summary' );
kt_ok( 401 === $s || 403 === $s, 'anonimo no puede ver caja/banco', "status $s" );

wp_set_current_user( $cajero->ID );
list( $s ) = kt_req( 'GET', '/keytill/v1/cashbank/summary' );
kt_ok( 403 === $s, 'el cajero NO puede ver caja/banco (es de gestion)', "status $s" );
list( $s ) = kt_req( 'POST', '/keytill/v1/digital/open', array( 'initial' => 500 ) );
kt_ok( 403 === $s, 'el cajero NO puede abrir la caja digital', "status $s" );

echo "\n=== 2. ABRIR CAJA ===\n";
list( $s, $d ) = kt_req( 'POST', '/keytill/v1/cash/open', array( 'opening_float' => 100 ) );
kt_ok( 201 === $s || 200 === $s, 'POST cash/open', "status $s" );
$sid = (int) ( $d['register_session_id'] ?? 0 );
kt_ok( $sid > 0, 'sesion creada', "id $sid" );
list( $s ) = kt_req( 'POST', '/keytill/v1/cash/open', array( 'opening_float' => 50 ) );
kt_ok( 409 === $s, 'no deja abrir una segunda caja', "status $s" );

echo "\n=== 3. VENTA EN EFECTIVO ===\n";
$venta = array(
	'items'           => array(
		array( 'product_id' => $tshirt->get_id(), 'qty' => 2 ),
		array( 'product_id' => $mug->get_id(), 'qty' => 1 ),
	),
	'payment_method'  => 'efectivo',
	'amount_tendered' => 100,
	'idempotency_key' => 'e2e-venta-1',
);
list( $s, $d ) = kt_req( 'POST', '/keytill/v1/orders', $venta );
kt_ok( 201 === $s || 200 === $s, 'POST orders', "status $s" );
$oid = (int) ( $d['order_id'] ?? 0 );
kt_ok( $oid > 0, 'orden creada', "id $oid" );
kt_ok( abs( (float) ( $d['total'] ?? 0 ) - 62.0 ) < 0.01, 'total = 62.00 (2x24 + 1x14)', 'es ' . ( $d['total'] ?? '?' ) );
kt_ok( abs( (float) ( $d['change'] ?? -1 ) - 38.0 ) < 0.01, 'vuelto = 38.00', 'es ' . var_export( $d['change'] ?? null, true ) );

$order = wc_get_order( $oid );
kt_ok( $order && $order->is_paid(), 'orden pagada', $order ? $order->get_status() : '-' );
kt_ok( (int) $order->get_meta( '_keytill_session' ) === $sid, 'ligada a la sesion de caja' );
kt_ok( (int) $order->get_meta( '_keytill_cajero_id' ) === (int) $cajero->ID, 'registra el cajero' );
$tshirt = wc_get_product( $tshirt->get_id() );
kt_ok( 22 === (int) $tshirt->get_stock_quantity(), 'stock: 24 -> 22', 'es ' . $tshirt->get_stock_quantity() );

echo "\n=== 4. IDEMPOTENCIA (doble submit) ===\n";
$antes = kt_orders();
list( $s, $d2 ) = kt_req( 'POST', '/keytill/v1/orders', $venta );
kt_ok( (int) ( $d2['order_id'] ?? 0 ) === $oid, 'misma clave -> devuelve la MISMA orden', 'devolvio ' . ( $d2['order_id'] ?? '?' ) );
kt_ok( ! empty( $d2['idempotent_replay'] ), 'marcada como replay' );
kt_ok( kt_orders() === $antes, 'no creo una orden nueva', kt_orders() . " vs $antes" );
$tshirt = wc_get_product( $tshirt->get_id() );
kt_ok( 22 === (int) $tshirt->get_stock_quantity(), 'NO volvio a descontar stock', 'es ' . $tshirt->get_stock_quantity() );

echo "\n=== 5. STOCK ===\n";
list( $s ) = kt_req( 'POST', '/keytill/v1/orders', array(
	'items'           => array( array( 'product_id' => $socks->get_id(), 'qty' => 1 ) ),
	'payment_method'  => 'efectivo',
	'amount_tendered' => 50,
	'idempotency_key' => 'e2e-sinstock',
) );
kt_ok( 200 !== $s && 201 !== $s, 'rechaza vender sin stock', "status $s" );
list( $s ) = kt_req( 'POST', '/keytill/v1/orders', array(
	'items'           => array( array( 'product_id' => $tshirt->get_id(), 'qty' => 999 ) ),
	'payment_method'  => 'efectivo',
	'amount_tendered' => 99999,
	'idempotency_key' => 'e2e-excede',
) );
kt_ok( 200 !== $s && 201 !== $s, 'rechaza pedir mas que el stock', "status $s" );

echo "\n=== 6. DEVOLUCION A CREDITO ===\n";
list( $s, $d ) = kt_req( 'GET', '/keytill/v1/orders/lookup', array() );
$req = new WP_REST_Request( 'GET', '/keytill/v1/orders/lookup' );
$req->set_param( 'number', (string) $order->get_order_number() );
$res = rest_do_request( $req );
kt_ok( 200 === $res->get_status(), 'GET orders/lookup encuentra la venta', 'status ' . $res->get_status() );
$look  = $res->get_data();
$lines = $look['items'] ?? array();
kt_ok( count( $lines ) >= 1, 'devuelve las lineas', count( $lines ) . ' lineas' );

$first = $lines[0] ?? null;
if ( $first ) {
	list( $s, $d ) = kt_req( 'POST', '/keytill/v1/orders/refund', array(
		'order_id' => $oid,
		'lines'    => array( array( 'item_id' => $first['item_id'], 'qty' => 1 ) ),
		'refund_method' => 'credito',
	) );
	kt_ok( 200 === $s || 201 === $s, 'POST orders/refund a credito', "status $s" );
	$code = $d['credit_code'] ?? '';
	kt_ok( '' !== $code, 'emitio un codigo de credito', $code );
	kt_ok( (bool) preg_match( '/^KT-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code ), 'el codigo tiene el formato KT-XXXX-XXXX', $code );
	$tshirt = wc_get_product( $tshirt->get_id() );
	kt_ok( 23 === (int) $tshirt->get_stock_quantity(), 'stock reingresado: 22 -> 23', 'es ' . $tshirt->get_stock_quantity() );

	$req = new WP_REST_Request( 'GET', '/keytill/v1/credit/lookup' );
	$req->set_param( 'code', $code );
	$res = rest_do_request( $req );
	$cr  = $res->get_data();
	kt_ok( 200 === $res->get_status(), 'GET credit/lookup valida el codigo', 'status ' . $res->get_status() );
	kt_ok( abs( (float) ( $cr['balance'] ?? 0 ) - 24.0 ) < 0.01, 'saldo del credito = 24.00', 'es ' . ( $cr['balance'] ?? '?' ) );
}

echo "\n=== 7. CERRAR CAJA ===\n";
list( $s ) = kt_req( 'POST', '/keytill/v1/cash/close', array( 'counted_cash' => 100 ) );
kt_ok( 403 === $s, 'el cajero NO puede cerrar la caja', "status $s" );

wp_set_current_user( $encargado->ID );
list( $s, $d ) = kt_req( 'GET', '/keytill/v1/cash/current' );
$sum = $d['session'] ?? $d;
kt_ok( 200 === $s, 'el encargado ve la caja', "status $s" );
// esperado = 100 fondo + 62 ventas en efectivo - 24 devuelto a credito (NO es efectivo) = 162
kt_ok( (int) ( $sum['register_session_id'] ?? 0 ) === $sid, 'el encargado ve la sesion DEL CAJERO', 'sesion ' . ( $sum['register_session_id'] ?? '?' ) );
kt_ok( abs( (float) ( $sum['expected_cash'] ?? 0 ) - 162.0 ) < 0.01, 'efectivo esperado = 162.00', 'es ' . ( $sum['expected_cash'] ?? '?' ) );

list( $s, $d ) = kt_req( 'POST', '/keytill/v1/cash/close', array( 'counted_cash' => 160 ) );
kt_ok( 200 === $s || 201 === $s, 'el encargado cierra la caja', "status $s" );
kt_ok( abs( (float) ( $d['difference'] ?? 0 ) + 2.0 ) < 0.01, 'diferencia = -2.00 (faltan 2)', 'es ' . ( $d['difference'] ?? '?' ) );

list( $s, $d ) = kt_req( 'GET', '/keytill/v1/cash/current' );
$cur = $d['session'] ?? $d;
kt_ok( empty( $cur['register_session_id'] ) || 'closed' === ( $cur['status'] ?? '' ), 'ya no hay caja abierta' );

echo "\n=== 8. CAJA DIGITAL ===\n";
list( $s, $d ) = kt_req( 'POST', '/keytill/v1/digital/open', array( 'initial' => 1000 ) );
kt_ok( 200 === $s || 201 === $s, 'el encargado abre la caja digital', "status $s" );
list( $s, $d ) = kt_req( 'GET', '/keytill/v1/digital/current' );
$dig = $d['digital'] ?? ( $d['period'] ?? $d );
kt_ok( abs( (float) ( $dig['opening'] ?? 0 ) - 1000.0 ) < 0.01, 'saldo inicial = 1000.00', 'es ' . ( $dig['opening'] ?? '?' ) );

list( $s, $d ) = kt_req( 'POST', '/keytill/v1/cash/move', array( 'account' => 'digital', 'kind' => 'retiro', 'amount' => 250, 'concept' => 'pago proveedor' ) );
kt_ok( 200 === $s || 201 === $s, 'retiro de 250 de la digital', "status $s" );
list( $s, $d ) = kt_req( 'GET', '/keytill/v1/digital/current' );
$dig = $d['digital'] ?? ( $d['period'] ?? $d );
kt_ok( abs( (float) ( $dig['balance'] ?? 0 ) - 750.0 ) < 0.01, 'saldo = 750.00 tras el retiro', 'es ' . ( $dig['balance'] ?? '?' ) );

list( $s, $d ) = kt_req( 'GET', '/keytill/v1/cashbank/ledger' );
kt_ok( 200 === $s, 'GET cashbank/ledger', "status $s" );

// ---------------------------------------------------------------------------
printf( "\n================  %d OK  ·  %d FALLA  ================\n", $GLOBALS['kt_pass'], $GLOBALS['kt_fail'] );
foreach ( $GLOBALS['kt_msgs'] as $m ) {
	echo "  pendiente: $m\n";
}
