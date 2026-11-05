<?php
/**
 * Stock por sucursal.
 *
 * El stock REAL vive por (producto/variación × sucursal) en una tabla propia.
 * El stock nativo de WooCommerce (`_stock`) se mantiene como la SUMA de todas
 * las sucursales (espejo), recalculado en cada cambio, para que la tienda web,
 * los reportes y cualquier otro plugin sigan viendo el total correcto y no haya
 * sobreventa web.
 *
 * Los productos y variaciones son posts (incluso con HPOS, que solo mueve las
 * órdenes), así que `product_id` referencia tanto a un producto simple como a
 * una variación.
 *
 * Esta clase es la BASE del stock por sucursal: guarda, ajusta y mueve. El
 * descuento al vender (POS y web) se conecta aparte, una vez definida la
 * política con el cliente.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Branch_Stock {

	/** Versión del esquema (subir si cambia la tabla). */
	const DB_VERSION = '1';

	/** Opción con la versión instalada del esquema. */
	const DB_VERSION_OPTION = 'keytill_branch_stock_db_version';

	/** Opción-bandera: la migración inicial del stock ya corrió. */
	const MIGRATED_OPTION = 'keytill_branch_stock_migrated';

	/** Nombre de la tabla. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'keytill_branch_stock';
	}

	public static function init() {
		// Crea/actualiza la tabla en instalaciones ya activas (la activación solo
		// corre al (re)activar el plugin).
		//
		// NO se migra el stock automáticamente: como el stock ya está repartido
		// físicamente entre locales y Woo solo guarda el total, no hay forma de
		// adivinar el reparto. Cada producto entra al stock por sucursal cuando el
		// encargado carga el reparto real en el editor (gradual/opt-in). Hasta
		// entonces sigue funcionando con su stock único de siempre.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );

		// Ventas WEB (no-POS): descuentan/reingresan por sucursal según la prioridad
		// configurada. Estos hooks NO disparan para órdenes del POS (esas están
		// filtradas y el POS reduce a mano). Los distribuidos ajustan sus sucursales
		// y el nativo se re-sincroniza a la suma; los legacy los maneja Woo.
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'on_web_order_reduce' ), 20 );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'on_web_order_restore' ), 20 );
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'on_web_restock_refunded' ), 20, 5 );
	}

	/* ------------------------------------------------------------------ *
	 * Ventas web (prioridad de sucursales)
	 * ------------------------------------------------------------------ */

	/** Sucursales activas ordenadas por prioridad web (menor primero, luego nombre). */
	public static function web_order_branches() {
		$rows = KEYTILL_Branches::active();
		usort(
			$rows,
			function ( $a, $b ) {
				$pa = isset( $a['web_priority'] ) ? (int) $a['web_priority'] : 0;
				$pb = isset( $b['web_priority'] ) ? (int) $b['web_priority'] : 0;
				if ( $pa === $pb ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
				return $pa <=> $pb;
			}
		);
		return array_map(
			function ( $r ) {
				return (string) $r['id'];
			},
			$rows
		);
	}

	/**
	 * Descuenta $qty de un producto distribuido siguiendo la prioridad web: toma
	 * de la primera sucursal con stock, y si se agota pasa a la siguiente. Si no
	 * alcanza en ninguna, el resto queda negativo en la de mayor prioridad.
	 */
	public static function web_reduce( $product_id, $qty, $priority ) {
		$remaining = (int) $qty;
		foreach ( $priority as $bid ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$have = self::get( $product_id, $bid );
			if ( $have <= 0 ) {
				continue;
			}
			$take = min( $have, $remaining );
			self::adjust( $product_id, $bid, -$take );
			$remaining -= $take;
		}
		if ( $remaining > 0 && ! empty( $priority ) ) {
			self::adjust( $product_id, $priority[0], -$remaining ); // faltante en la 1ª prioridad
		}
	}

	/** Descuento de stock de una venta WEB (por sucursal, según prioridad). */
	public static function on_web_order_reduce( $order ) {
		if ( ! $order instanceof WC_Order || 'keytill' === $order->get_created_via() ) {
			return; // el POS lo maneja aparte
		}
		$priority = self::web_order_branches();
		if ( ! $priority ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$prod = $item->get_product();
			if ( ! $prod || ! self::is_managed( $prod->get_id() ) ) {
				continue; // legacy: lo maneja Woo (nativo)
			}
			self::web_reduce( $prod->get_id(), (int) $item->get_quantity(), $priority );
		}
	}

	/** Reingreso de stock al cancelar una orden WEB: a la sucursal de 1ª prioridad. */
	public static function on_web_order_restore( $order ) {
		if ( ! $order instanceof WC_Order || 'keytill' === $order->get_created_via() ) {
			return;
		}
		$priority = self::web_order_branches();
		if ( ! $priority ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$prod = $item->get_product();
			if ( ! $prod || ! self::is_managed( $prod->get_id() ) ) {
				continue;
			}
			self::adjust( $prod->get_id(), $priority[0], (int) $item->get_quantity() );
		}
	}

	/** Reingreso por devolución de una orden WEB: a la sucursal de 1ª prioridad. */
	public static function on_web_restock_refunded( $product_id, $old_stock, $new_stock, $order, $product = null ) {
		if ( $order instanceof WC_Order && 'keytill' === $order->get_created_via() ) {
			return; // el POS reintegra a la sucursal de la caja aparte
		}
		if ( ! self::is_managed( $product_id ) ) {
			return;
		}
		$qty = (int) $new_stock - (int) $old_stock;
		if ( $qty <= 0 ) {
			return;
		}
		$priority = self::web_order_branches();
		if ( $priority ) {
			self::adjust( $product_id, $priority[0], $qty );
		}
	}

	/**
	 * ¿El producto/variación YA está en el sistema por sucursal? (tiene al menos
	 * una fila). Si no, es "legacy": usa su stock único de Woo como hasta ahora.
	 */
	public static function is_managed( $product_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE product_id = %d', (int) $product_id )
		) > 0;
	}

	/* ------------------------------------------------------------------ *
	 * Esquema
	 * ------------------------------------------------------------------ */

	public static function maybe_upgrade() {
		if ( (string) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql     = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			branch_id VARCHAR(32) NOT NULL,
			qty INT NOT NULL DEFAULT 0,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_branch (product_id, branch_id),
			KEY product_id (product_id),
			KEY branch_id (branch_id)
		) $charset;";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/* ------------------------------------------------------------------ *
	 * Lectura
	 * ------------------------------------------------------------------ */

	/** Cantidad de una (producto/variación, sucursal). 0 si no hay fila. */
	public static function get( $product_id, $branch_id ) {
		global $wpdb;
		$v = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT qty FROM ' . self::table() . ' WHERE product_id = %d AND branch_id = %s',
				(int) $product_id,
				(string) $branch_id
			)
		);
		return null === $v ? 0 : (int) $v;
	}

	/** Mapa [branch_id => qty] de un producto/variación (solo filas existentes). */
	public static function by_product( $product_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT branch_id, qty FROM ' . self::table() . ' WHERE product_id = %d', (int) $product_id ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['branch_id'] ] = (int) $r['qty'];
		}
		return $out;
	}

	/** Stock por sucursal de un producto, alineado a las sucursales ACTIVAS
	 * (incluye 0 para las que no tienen fila). Para pintar el editor/POS. */
	public static function map_for_active( $product_id ) {
		$have = self::by_product( $product_id );
		$out  = array();
		foreach ( KEYTILL_Branches::active() as $b ) {
			$bid         = (string) $b['id'];
			$out[ $bid ] = isset( $have[ $bid ] ) ? (int) $have[ $bid ] : 0;
		}
		return $out;
	}

	/** Suma total de un producto/variación (todas las sucursales, activas o no). */
	public static function total( $product_id ) {
		global $wpdb;
		$v = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(qty),0) FROM ' . self::table() . ' WHERE product_id = %d', (int) $product_id )
		);
		return (int) $v;
	}

	/* ------------------------------------------------------------------ *
	 * Escritura
	 * ------------------------------------------------------------------ */

	/** Upsert crudo de una fila (no re-sincroniza el total). */
	private static function put( $product_id, $branch_id, $qty ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (product_id, branch_id, qty, updated_at) VALUES (%d, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = VALUES(updated_at)',
				(int) $product_id,
				(string) $branch_id,
				max( 0, (int) $qty ),
				current_time( 'mysql' )
			)
		);
	}

	/** Fija la cantidad absoluta de una sucursal y recalcula el total nativo. */
	public static function set( $product_id, $branch_id, $qty ) {
		$branch_id = KEYTILL_Branches::valid_id( $branch_id );
		if ( '' === $branch_id ) {
			return;
		}
		self::put( $product_id, $branch_id, (int) $qty );
		self::sync_native( $product_id );
	}

	/** Fija varias sucursales de una y recalcula el total nativo una sola vez. */
	public static function set_bulk( $product_id, array $by_branch ) {
		foreach ( $by_branch as $bid => $qty ) {
			$bid = KEYTILL_Branches::valid_id( $bid );
			if ( '' === $bid ) {
				continue;
			}
			self::put( $product_id, $bid, (int) $qty );
		}
		self::sync_native( $product_id );
	}

	/**
	 * Ajuste atómico (+/-) de una sucursal. Recalcula el total nativo.
	 *
	 * Con $guard=true (para el descuento de una venta) NO deja negativo: si no
	 * alcanza el stock, no toca nada y devuelve false. Sin guard, aplica el delta
	 * tal cual y PUEDE quedar negativo: eso es información válida (un faltante a
	 * reconciliar, p. ej. "vender igual" desde una sucursal sin stock, o un
	 * reintegro sobre una sucursal ya en negativo).
	 *
	 * @return bool true si aplicó; false si con guard no había suficiente.
	 */
	public static function adjust( $product_id, $branch_id, $delta, $guard = false ) {
		global $wpdb;
		$branch_id = KEYTILL_Branches::valid_id( $branch_id );
		if ( '' === $branch_id ) {
			return false;
		}
		$delta = (int) $delta;
		$now   = current_time( 'mysql' );
		$table = self::table();

		// Asegura la fila (0 si no existía).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (product_id, branch_id, qty, updated_at) VALUES (%d, %s, 0, %s)",
				(int) $product_id,
				$branch_id,
				$now
			)
		);

		if ( $guard && $delta < 0 ) {
			// Resta atómica: solo si hay suficiente (evita sobreventa con dos cajas).
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET qty = qty + %d, updated_at = %s WHERE product_id = %d AND branch_id = %s AND qty >= %d",
					$delta,
					$now,
					(int) $product_id,
					$branch_id,
					-$delta
				)
			);
			if ( ! $affected ) {
				return false;
			}
		} else {
			// Sin guard: aplica el delta tal cual (puede quedar negativo = faltante).
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET qty = qty + %d, updated_at = %s WHERE product_id = %d AND branch_id = %s",
					$delta,
					$now,
					(int) $product_id,
					$branch_id
				)
			);
		}

		self::sync_native( $product_id );
		return true;
	}

	/**
	 * Mueve stock de una sucursal a otra (transferencia atómica). El total no
	 * cambia. Devuelve true o WP_Error.
	 */
	public static function move( $product_id, $from, $to, $qty ) {
		$from = KEYTILL_Branches::valid_id( $from );
		$to   = KEYTILL_Branches::valid_id( $to );
		$qty  = (int) $qty;
		if ( '' === $from || '' === $to ) {
			return new WP_Error( 'keytill_move_branch', __( 'Sucursal inválida.', 'keytill' ), array( 'status' => 400 ) );
		}
		if ( $from === $to ) {
			return new WP_Error( 'keytill_move_same', __( 'Elegí sucursales distintas.', 'keytill' ), array( 'status' => 400 ) );
		}
		if ( $qty <= 0 ) {
			return new WP_Error( 'keytill_move_qty', __( 'Cantidad inválida.', 'keytill' ), array( 'status' => 400 ) );
		}
		// Resta atómica en origen (con guard); si no alcanza, aborta sin tocar destino.
		if ( ! self::adjust( $product_id, $from, -$qty, true ) ) {
			return new WP_Error( 'keytill_move_stock', __( 'No hay suficiente stock en la sucursal de origen.', 'keytill' ), array( 'status' => 409 ) );
		}
		self::adjust( $product_id, $to, $qty );
		return true;
	}

	/**
	 * Refleja el total (suma de sucursales) en el stock nativo de WooCommerce,
	 * dejando manage_stock activo. Es lo que mantiene coherente la web/reportes.
	 */
	public static function sync_native( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}
		$total = self::total( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $total );
		if ( ! $product->backorders_allowed() ) {
			$product->set_stock_status( $total > 0 ? 'instock' : 'outofstock' );
		}
		$product->save();
	}

	/* ------------------------------------------------------------------ *
	 * Migración inicial
	 * ------------------------------------------------------------------ */

	/**
	 * Siembra el stock actual de cada producto/variación (que gestiona stock) en
	 * su sucursal asignada (`_keytill_branch_id`) o en la sucursal por defecto si no
	 * tiene. Corre UNA sola vez, y solo cuando ya hay sucursales cargadas.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		$branches = KEYTILL_Branches::all();
		if ( ! $branches ) {
			return; // sin sucursales todavía: se sembrará cuando existan
		}
		self::migrate( (string) $branches[0]['id'] );
	}

	/**
	 * @param string $default_branch Sucursal para los ítems sin `_keytill_branch_id`.
	 */
	public static function migrate( $default_branch ) {
		global $wpdb;
		$default = KEYTILL_Branches::valid_id( $default_branch );
		if ( '' === $default ) {
			$all = KEYTILL_Branches::all();
			if ( ! $all ) {
				return;
			}
			$default = (string) $all[0]['id'];
		}

		// Candidatos: productos simples + variaciones que gestionan stock, con un
		// _stock numérico. (Los productos son posts aun con HPOS.)
		$rows = $wpdb->get_results(
			"SELECT s.post_id AS pid, s.meta_value AS stock, b.meta_value AS branch
			 FROM {$wpdb->postmeta} s
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = s.post_id AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'
			 LEFT JOIN {$wpdb->postmeta} b ON b.post_id = s.post_id AND b.meta_key = '" . esc_sql( KEYTILL_Branches::META ) . "'
			 WHERE s.meta_key = '_stock' AND s.meta_value REGEXP '^-?[0-9]+$'",
			ARRAY_A
		);

		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['pid'];
			$qty = max( 0, (int) $r['stock'] );
			$bid = KEYTILL_Branches::valid_id( isset( $r['branch'] ) ? (string) $r['branch'] : '' );
			if ( '' === $bid ) {
				$bid = $default;
			}
			// No pisar si ya hay una fila (idempotencia fina). get_var devuelve string.
			if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE product_id = %d', $pid ) ) ) {
				self::put( $pid, $bid, $qty );
			}
		}

		update_option( self::MIGRATED_OPTION, 1 );
	}
}
