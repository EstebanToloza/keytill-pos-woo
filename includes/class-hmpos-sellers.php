<?php
/**
 * Vendedores del local + reporte de ventas por vendedor.
 *
 * En el mostrador hay un cajero (usuario logueado que opera el POS) pero varios
 * vendedores que atienden. Este módulo permite cargar vendedores con datos
 * mínimos (nombre), elegir uno al confirmar la venta y ver cuánto vendió cada
 * uno en un rango de fechas.
 *
 * Los vendedores NO son usuarios de WordPress: son solo etiquetas de la venta.
 * Se guardan en una opción (`hmpos_sellers`) como lista de {id, name, active}.
 * En cada orden se registra `_hmpos_seller_id` + `_hmpos_seller` (nombre
 * "congelado" al momento de la venta, para preservar el histórico si luego se
 * renombra o se quita el vendedor).
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

class HMPOS_Sellers {

	/** Opción con la lista de vendedores. */
	const OPTION = 'hmpos_sellers';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 10 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Datos
	 * ------------------------------------------------------------------ */

	/** Todos los vendedores (activos e inactivos). */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/** Solo los activos (los que se ofrecen en el POS). */
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

	/** Un vendedor por id, o null. */
	public static function get( $id ) {
		foreach ( self::all() as $r ) {
			if ( (string) $r['id'] === (string) $id ) {
				return $r;
			}
		}
		return null;
	}

	/** Nombre actual de un vendedor por id ('' si no existe). */
	public static function name_of( $id ) {
		$r = self::get( $id );
		return $r ? (string) $r['name'] : '';
	}

	/** Para el front del POS: activos como [{id, name}]. */
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
		register_setting( 'hmpos_sellers_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * Sanitiza la lista de vendedores. Descarta filas con nombre vacío (así se
	 * "quita" un vendedor vaciándole el nombre). A las filas nuevas (sin id) les
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
					$id = 'v' . substr( md5( uniqid( '', true ) ), 0, 8 );
				}
				$seen[ $id ] = true;
				$out[]       = array(
					'id'     => $id,
					'name'   => $name,
					'active' => ! empty( $row['active'] ),
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Pantallas de admin
	 * ------------------------------------------------------------------ */

	public static function register_menus() {
		add_submenu_page(
			HMPOS_Settings::MENU,
			__( 'Vendedores', 'hm-pos' ),
			__( 'Vendedores', 'hm-pos' ),
			'manage_woocommerce',
			'hmpos-sellers',
			array( __CLASS__, 'render_manage' )
		);
		add_submenu_page(
			HMPOS_Settings::MENU,
			__( 'Ventas por vendedor', 'hm-pos' ),
			__( 'Ventas x vendedor', 'hm-pos' ),
			'manage_woocommerce',
			'hmpos-seller-report',
			array( __CLASS__, 'render_report' )
		);
	}

	/** Alta/edición de vendedores. */
	public static function render_manage() {
		$rows = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vendedores', 'hm-pos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Vendedores que atienden en el mostrador. Se eligen al confirmar la venta para saber cuánto vendió cada uno. No son usuarios del sistema. Para quitar uno, vaciá su nombre y guardá (las ventas ya registradas conservan el nombre).', 'hm-pos' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'hmpos_sellers_group' ); ?>
				<table class="widefat striped" style="max-width:640px;margin-top:12px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'hm-pos' ); ?></th>
							<th style="width:90px;text-align:center"><?php esc_html_e( 'Activo', 'hm-pos' ); ?></th>
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
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo (int) $idx; ?>][active]" value="1" <?php checked( ! empty( $r['active'] ) ); ?> />
								</td>
							</tr>
							<?php
							++$idx;
						endforeach;
						// Tres filas en blanco para agregar nuevos.
						for ( $k = 1; $k <= 3; $k++ ) :
							?>
							<tr>
								<td>
									<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[new<?php echo (int) $k; ?>][name]" value="" placeholder="<?php esc_attr_e( 'Nuevo vendedor…', 'hm-pos' ); ?>" />
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

	/** Reporte de ventas por vendedor, filtrable por rango de fechas. */
	public static function render_report() {
		// Rango: default mes actual (desde el 1° hasta hoy).
		$today   = current_time( 'Y-m-d' );
		$from_in = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to_in   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$from    = self::valid_date( $from_in ) ? $from_in : gmdate( 'Y-m-01', strtotime( $today ) );
		$to      = self::valid_date( $to_in ) ? $to_in : $today;

		$data  = self::report( $from, $to );
		$total = 0.0;
		$count = 0;
		foreach ( $data as $d ) {
			$total += $d['total'];
			$count += $d['count'];
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ventas por vendedor', 'hm-pos' ); ?></h1>
			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="hmpos-seller-report" />
				<label><?php esc_html_e( 'Desde', 'hm-pos' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
				</label>
				<label style="margin-left:8px"><?php esc_html_e( 'Hasta', 'hm-pos' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
				</label>
				<?php submit_button( __( 'Filtrar', 'hm-pos' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped" style="max-width:640px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vendedor', 'hm-pos' ); ?></th>
						<th style="width:120px;text-align:right"><?php esc_html_e( 'N° ventas', 'hm-pos' ); ?></th>
						<th style="width:160px;text-align:right"><?php esc_html_e( 'Total neto', 'hm-pos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $data ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'Sin ventas en el rango elegido.', 'hm-pos' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $data as $d ) : ?>
							<tr>
								<td><?php echo esc_html( $d['name'] ); ?></td>
								<td style="text-align:right"><?php echo (int) $d['count']; ?></td>
								<td style="text-align:right"><?php echo wp_kses_post( wc_price( $d['total'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<?php if ( ! empty( $data ) ) : ?>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Total', 'hm-pos' ); ?></th>
							<th style="text-align:right"><?php echo (int) $count; ?></th>
							<th style="text-align:right"><?php echo wp_kses_post( wc_price( $total ) ); ?></th>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
			<p class="description" style="margin-top:10px">
				<?php esc_html_e( 'Total neto = ventas del POS (completadas) menos devoluciones, en el rango. Las ventas sin vendedor asignado se agrupan como «(sin vendedor)».', 'hm-pos' ); ?>
			</p>
		</div>
		<?php
	}

	/** ¿Cadena Y-m-d válida? */
	private static function valid_date( $s ) {
		return is_string( $s ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) && strtotime( $s );
	}

	/**
	 * Totales por vendedor en un rango (fechas Y-m-d, inclusive). Devuelve filas
	 * ordenadas por total desc: [ ['name','count','total'], ... ].
	 *
	 * Storage-agnóstico: filtra por fecha (soportado por ambos stores) y agrupa en
	 * PHP leyendo el meta del vendedor de cada orden (no usa meta_query, que el
	 * store legacy ignora).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function report( $from, $to ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$from_ts = strtotime( $from . ' 00:00:00' );
		$to_ts   = strtotime( $to . ' 23:59:59' );
		if ( ! $from_ts || ! $to_ts || $from_ts > $to_ts ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => -1,
				'status'       => array( 'wc-completed', 'wc-processing' ),
				'date_created' => $from_ts . '...' . $to_ts,
				'return'       => 'objects',
			)
		);

		$rows = array();
		foreach ( (array) $orders as $o ) {
			if ( ! $o instanceof WC_Order || 'hmpos' !== $o->get_created_via() ) {
				continue;
			}
			$sid   = (string) $o->get_meta( '_hmpos_seller_id' );
			$snap  = (string) $o->get_meta( '_hmpos_seller' );
			$key   = '' !== $sid ? $sid : ( '' !== $snap ? 'name:' . $snap : '__none__' );
			$name  = '' !== $sid ? self::name_of( $sid ) : '';
			$label = '' !== $name ? $name : ( '' !== $snap ? $snap : __( '(sin vendedor)', 'hm-pos' ) );

			$net = (float) $o->get_total() - (float) $o->get_total_refunded();

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'name'  => $label,
					'count' => 0,
					'total' => 0.0,
				);
			}
			$rows[ $key ]['count']++;
			$rows[ $key ]['total'] += $net;
		}

		uasort(
			$rows,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		return array_values( $rows );
	}
}
