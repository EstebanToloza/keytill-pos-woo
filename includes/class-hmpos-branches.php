<?php
/**
 * Sucursales del negocio (informativo).
 *
 * El cliente tiene varios locales. Cada producto se asigna a UNA sucursal para
 * que el vendedor vea rápido dónde está disponible al buscarlo o al agregarlo al
 * carrito. Es solo informativo: NO maneja stock por sucursal (el stock sigue
 * siendo el de WooCommerce).
 *
 * Las sucursales NO son entidades de WooCommerce: se guardan en una opción
 * (`hmpos_branches`) como lista de {id, name, active}. En cada producto se
 * registra la sucursal elegida en el meta `_hmpos_branch_id`.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

class HMPOS_Branches {

	/** Opción con la lista de sucursales. */
	const OPTION = 'hmpos_branches';

	/** Meta del producto con la sucursal asignada. */
	const META = '_hmpos_branch_id';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 10 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Datos
	 * ------------------------------------------------------------------ */

	/** Todas las sucursales (activas e inactivas). */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/** Solo las activas (las que se ofrecen para elegir). */
	public static function active() {
		return array_values(
			array_filter(
				self::all(),
				function ( $r ) {
					return ! empty( $r['active'] );
				}
			)
		);
	}

	/** Una sucursal por id, o null. */
	public static function get( $id ) {
		foreach ( self::all() as $r ) {
			if ( (string) $r['id'] === (string) $id ) {
				return $r;
			}
		}
		return null;
	}

	/** Nombre actual de una sucursal por id ('' si no existe). */
	public static function name_of( $id ) {
		$r = self::get( $id );
		return $r ? (string) $r['name'] : '';
	}

	/** Devuelve el id si existe en la lista, o '' (para validar al guardar). */
	public static function valid_id( $id ) {
		$id = sanitize_key( (string) $id );
		return ( '' !== $id && self::get( $id ) ) ? $id : '';
	}

	/** Para el front del POS y el editor: activas como [{id, name}]. */
	public static function front() {
		return array_map(
			function ( $r ) {
				return array(
					'id'   => (string) $r['id'],
					'name' => (string) $r['name'],
				);
			},
			self::active()
		);
	}

	/* ------------------------------------------------------------------ *
	 * Ajustes (Settings API)
	 * ------------------------------------------------------------------ */

	public static function register_settings() {
		register_setting( 'hmpos_branches_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * Sanitiza la lista de sucursales. Descarta filas con nombre vacío (así se
	 * "quita" una sucursal vaciándole el nombre). A las filas nuevas (sin id) les
	 * asigna un id estable.
	 *
	 * @param mixed $input Filas [{id,name,active}].
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out  = array();
		$seen = array();
		if ( is_array( $input ) ) {
			foreach ( $input as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' );
				if ( '' === trim( $name ) ) {
					continue;
				}
				$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
				if ( '' === $id || isset( $seen[ $id ] ) ) {
					$id = 's' . substr( md5( uniqid( '', true ) ), 0, 8 );
				}
				$seen[ $id ] = true;
				$out[]       = array(
					'id'           => $id,
					'name'         => $name,
					'active'       => ! empty( $row['active'] ),
					// Prioridad para las ventas web (menor = primero en descontar).
					'web_priority' => isset( $row['web_priority'] ) ? max( 0, (int) $row['web_priority'] ) : 0,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Pantalla de admin
	 * ------------------------------------------------------------------ */

	public static function register_menus() {
		add_submenu_page(
			HMPOS_Settings::MENU,
			__( 'Sucursales', 'hm-pos' ),
			__( 'Sucursales', 'hm-pos' ),
			'manage_woocommerce',
			'hmpos-branches',
			array( __CLASS__, 'render_manage' )
		);
	}

	/** Alta/edición de sucursales. */
	public static function render_manage() {
		$rows = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sucursales', 'hm-pos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Locales del negocio. El stock se lleva por sucursal (se carga en el editor de productos). "Prioridad web": orden en que las ventas de la tienda online descuentan stock (menor número = primero; si se agota, pasa a la siguiente). Para quitar una sucursal, vaciá su nombre y guardá.', 'hm-pos' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'hmpos_branches_group' ); ?>
				<table class="widefat striped" style="max-width:640px;margin-top:12px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'hm-pos' ); ?></th>
							<th style="width:120px;text-align:center"><?php esc_html_e( 'Prioridad web', 'hm-pos' ); ?></th>
							<th style="width:90px;text-align:center"><?php esc_html_e( 'Activa', 'hm-pos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$idx = 0;
						foreach ( $rows as $r ) :
							?>
							<tr>
								<td>
									<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr( $r['id'] ); ?>" />
									<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>" />
								</td>
								<td style="text-align:center">
									<input type="number" min="0" style="width:70px" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo (int) $idx; ?>][web_priority]" value="<?php echo (int) ( isset( $r['web_priority'] ) ? $r['web_priority'] : 0 ); ?>" />
								</td>
								<td style="text-align:center">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo (int) $idx; ?>][active]" value="1" <?php checked( ! empty( $r['active'] ) ); ?> />
								</td>
							</tr>
							<?php
							++$idx;
						endforeach;
						// Tres filas en blanco para agregar nuevas.
						for ( $k = 1; $k <= 3; $k++ ) :
							?>
							<tr>
								<td>
									<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[new<?php echo (int) $k; ?>][name]" value="" placeholder="<?php esc_attr_e( 'Nueva sucursal…', 'hm-pos' ); ?>" />
								</td>
								<td style="text-align:center">
									<input type="number" min="0" style="width:70px" name="<?php echo esc_attr( self::OPTION ); ?>[new<?php echo (int) $k; ?>][web_priority]" value="0" />
								</td>
								<td style="text-align:center">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[new<?php echo (int) $k; ?>][active]" value="1" checked />
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
