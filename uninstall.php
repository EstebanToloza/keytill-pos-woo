<?php
/**
 * Desinstalación de Keytill POS.
 *
 * Criterio: **no borra nada por defecto**. Un POS guarda el historial de caja
 * y el saldo de las gift cards que están en la calle; si desinstalar borrara
 * eso, un reinstall perdería plata de clientes y la trazabilidad de los
 * arqueos. Así que el borrado es opt-in (Ajustes → «Al desinstalar»).
 *
 * Lo único que corre siempre es limpiar el cron, que sin el plugin apunta a un
 * hook inexistente y no tiene por qué quedar agendado.
 *
 * El meta `_keytill_*` de pedidos y productos se conserva incluso en el borrado
 * total: describe pedidos reales (quién cobró, con qué medio, qué crédito se
 * aplicó) y borrarlo seria reescribir el historial de la tienda. Es inerte sin
 * el plugin.
 *
 * @package Keytill
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Opciones que crea el plugin. */
const KEYTILL_UNINSTALL_OPTIONS = array(
	'keytill_settings',
	'keytill_payment_methods',
	'keytill_label_attributes',
	'keytill_branches',
	'keytill_sellers',
	'keytill_giftcard_max',
	'keytill_credit_db_version',
	'keytill_credit_expiry_days',
	'keytill_branch_stock_db_version',
	'keytill_branch_stock_migrated',
	'keytill_digital_last_balance',
	'keytill_digital_seeded',
	'keytill_roles_version',
);

/** Tablas propias (sin el prefijo). */
const KEYTILL_UNINSTALL_TABLES = array(
	'keytill_credits',
	'keytill_credit_ledger',
	'keytill_branch_stock',
);

/** Custom post types propios (sesiones de caja física y períodos de la digital). */
const KEYTILL_UNINSTALL_CPTS = array(
	'keytill_cash_session',
	'keytill_digital_sess',
);

/** Roles propios. */
const KEYTILL_UNINSTALL_ROLES = array( 'cajero', 'encargado' );

/** Capabilities propias (a quitar del administrador). */
const KEYTILL_UNINSTALL_CAPS = array( 'keytill_operate', 'keytill_manage' );

/**
 * Limpia un sitio. Idempotente.
 */
function keytill_uninstall_site() {
	// Siempre: el cron agendado no sirve sin el plugin.
	$ts = wp_next_scheduled( 'keytill_credit_expire_event' );
	while ( $ts ) {
		wp_unschedule_event( $ts, 'keytill_credit_expire_event' );
		$ts = wp_next_scheduled( 'keytill_credit_expire_event' );
	}
	wp_clear_scheduled_hook( 'keytill_credit_expire_event' );

	// A partir de acá, solo si el comercio lo pidió explícitamente.
	$settings = get_option( 'keytill_settings' );
	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	global $wpdb;

	// Posts de los CPT propios, con su meta (wp_delete_post se encarga).
	foreach ( KEYTILL_UNINSTALL_CPTS as $cpt ) {
		$ids = get_posts(
			array(
				'post_type'        => $cpt,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	// Tablas propias.
	foreach ( KEYTILL_UNINSTALL_TABLES as $table ) {
		$name = $wpdb->prefix . $table;
		// El nombre de tabla es interno (prefijo + constante), no entra por request;
		// no admite placeholders de prepare() por ser un identificador.
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// Opciones.
	foreach ( KEYTILL_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	// Capabilities del administrador y roles propios. Se hace al final: si algo
	// de arriba falla, el admin conserva el acceso para reintentar.
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( KEYTILL_UNINSTALL_CAPS as $cap ) {
			$admin->remove_cap( $cap );
		}
	}
	foreach ( KEYTILL_UNINSTALL_ROLES as $role ) {
		if ( get_role( $role ) ) {
			remove_role( $role );
		}
	}
}

if ( is_multisite() ) {
	// El plugin puede haber estado activo en varios sitios de la red.
	$keytill_sites = get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'public'   => null,
			'archived' => null,
			'deleted'  => null,
		)
	);
	foreach ( $keytill_sites as $keytill_site_id ) {
		switch_to_blog( (int) $keytill_site_id );
		keytill_uninstall_site();
		restore_current_blog();
	}
} else {
	keytill_uninstall_site();
}
