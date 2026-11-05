<?php
/**
 * Módulo de Crédito en tienda ("HM Crédito").
 *
 * Instrumento de saldo al portador: se emite al devolver una venta (o a mano)
 * y se canjea en el POS (Fase 1) o en el checkout web (Fase 2, pendiente). Un
 * crédito al portador con saldo + vencimiento es, además, una gift card: la
 * misma tabla soporta emitirlas por regalo/venta (Fase 3, pendiente).
 *
 * Frontera limpia: este módulo NO sabe del POS. Expone emitir/canjear/consultar
 * y el resto del plugin lo usa; si mañana se separa a su propio plugin, la
 * costura ya está cortada.
 *
 * Integridad del saldo: el débito es atómico (UPDATE condicional con WHERE
 * balance >= monto), así el canje web y el del mostrador nunca lo sobregiran.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lógica del crédito en tienda.
 */
class HMPOS_Credit {

	/** Versión del esquema de tablas (sube si cambia la estructura). */
	const DB_VERSION = '2';

	/** Opción con la versión de esquema instalada. */
	const DB_VERSION_OPTION = 'hmpos_credit_db_version';

	/** Opción con los días de vigencia por defecto. */
	const EXPIRY_OPTION = 'hmpos_credit_expiry_days';

	/** Días de vigencia por defecto si no se configuró otro valor. */
	const DEFAULT_EXPIRY_DAYS = 30;

	/** Hook del cron diario que marca créditos vencidos. */
	const CRON_HOOK = 'hmpos_credit_expire_event';

	/* ------------------------------------------------------------------ *
	 * Nombres de tabla
	 * ------------------------------------------------------------------ */

	/**
	 * Tabla de créditos.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hmpos_credits';
	}

	/**
	 * Tabla de movimientos (ledger) del crédito.
	 *
	 * @return string
	 */
	public static function ledger_table() {
		global $wpdb;
		return $wpdb->prefix . 'hmpos_credit_ledger';
	}

	/* ------------------------------------------------------------------ *
	 * Ciclo de vida
	 * ------------------------------------------------------------------ */

	/**
	 * Engancha admin, cron y auto-migración de esquema.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_post_hmpos_credit_issue', array( __CLASS__, 'handle_issue' ) );
		add_action( 'admin_post_hmpos_credit_void', array( __CLASS__, 'handle_void' ) );
		add_action( 'admin_post_hmpos_credit_extend', array( __CLASS__, 'handle_extend' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'expire_due' ) );

		// Auto-migración: crea/actualiza las tablas si el plugin ya estaba activo
		// cuando se agregó el módulo (el hook de activación no vuelve a correr).
		add_action(
			'plugins_loaded',
			function () {
				if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
					self::create_tables();
				}
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
				}
			}
		);
	}

	/**
	 * En activación del plugin: crea tablas y programa el cron.
	 */
	public static function activate() {
		self::create_tables();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * En desactivación: limpia el cron (las tablas se conservan).
	 */
	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Crea/actualiza las tablas con dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$credits = self::table();
		$ledger  = self::ledger_table();

		$sql_credits = "CREATE TABLE $credits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			initial_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			balance DECIMAL(12,2) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			kind VARCHAR(20) NOT NULL DEFAULT 'refund',
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			source_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_refund_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cajero_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			issued_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY kind (kind),
			KEY customer_id (customer_id)
		) $charset;";

		$sql_ledger = "CREATE TABLE $ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			credit_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel VARCHAR(10) NOT NULL DEFAULT '',
			cajero_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY credit_id (credit_id)
		) $charset;";

		dbDelta( $sql_credits );
		dbDelta( $sql_ledger );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/* ------------------------------------------------------------------ *
	 * Ajustes
	 * ------------------------------------------------------------------ */

	/**
	 * Días de vigencia configurados (0 = sin vencimiento).
	 *
	 * @return int
	 */
	public static function get_expiry_days() {
		$v = get_option( self::EXPIRY_OPTION, self::DEFAULT_EXPIRY_DAYS );
		return max( 0, (int) $v );
	}

	/* ------------------------------------------------------------------ *
	 * Emisión
	 * ------------------------------------------------------------------ */

	/**
	 * Genera un código único al portador tipo HM-XXXX-XXXX (sin caracteres
	 * ambiguos).
	 *
	 * @return string
	 */
	public static function generate_code() {
		global $wpdb;
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin I,O,0,1
		$table    = self::table();

		do {
			$chunk = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$chunk .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$code   = 'HM-' . substr( $chunk, 0, 4 ) . '-' . substr( $chunk, 4, 4 );
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE code = %s", $code ) ); // phpcs:ignore
		} while ( $exists );

		return $code;
	}

	/**
	 * Emite un crédito nuevo por un monto.
	 *
	 * @param float $amount Monto (> 0).
	 * @param array $args   { customer_id, customer_email, source_order_id,
	 *                        source_refund_id, cajero_id, session_id, note,
	 *                        expiry_days (opcional; por defecto el configurado) }.
	 * @return array|WP_Error Payload del crédito o error.
	 */
	public static function issue( $amount, $args = array() ) {
		global $wpdb;
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'hmpos_credit_amount', __( 'Monto de crédito inválido.', 'hm-pos' ), array( 'status' => 400 ) );
		}

		$now  = current_time( 'mysql' );
		$days = isset( $args['expiry_days'] ) ? max( 0, (int) $args['expiry_days'] ) : self::get_expiry_days();
		$exp  = $days > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . $days . ' days' ) ) : null;
		$code = self::generate_code();

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'code'             => $code,
				'initial_amount'   => self::dec( $amount ),
				'balance'          => self::dec( $amount ),
				'status'           => 'active',
				'kind'             => isset( $args['kind'] ) ? sanitize_key( $args['kind'] ) : 'refund',
				'customer_id'      => isset( $args['customer_id'] ) ? (int) $args['customer_id'] : 0,
				'customer_email'   => isset( $args['customer_email'] ) ? sanitize_email( $args['customer_email'] ) : '',
				'source_order_id'  => isset( $args['source_order_id'] ) ? (int) $args['source_order_id'] : 0,
				'source_refund_id' => isset( $args['source_refund_id'] ) ? (int) $args['source_refund_id'] : 0,
				'cajero_id'        => isset( $args['cajero_id'] ) ? (int) $args['cajero_id'] : 0,
				'session_id'       => isset( $args['session_id'] ) ? (int) $args['session_id'] : 0,
				'note'             => isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '',
				'issued_at'        => $now,
				'expires_at'       => $exp,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'hmpos_credit_insert', __( 'No se pudo emitir el crédito.', 'hm-pos' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::log(
			$id,
			'issue',
			$amount,
			$amount,
			array(
				'order_id'  => isset( $args['source_order_id'] ) ? (int) $args['source_order_id'] : 0,
				'channel'   => isset( $args['channel'] ) ? sanitize_key( $args['channel'] ) : 'pos',
				'cajero_id' => isset( $args['cajero_id'] ) ? (int) $args['cajero_id'] : 0,
				'note'      => isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '',
			)
		);

		return self::payload( self::get( $id ) );
	}

	/* ------------------------------------------------------------------ *
	 * Consulta
	 * ------------------------------------------------------------------ */

	/**
	 * Trae un crédito por ID.
	 *
	 * @param int $id ID.
	 * @return object|null Fila o null.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Trae un crédito por código.
	 *
	 * @param string $code Código.
	 * @return object|null Fila o null.
	 */
	public static function get_by_code( $code ) {
		global $wpdb;
		$table = self::table();
		$code  = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE code = %s", $code ) ); // phpcs:ignore
	}

	/**
	 * ¿Es canjeable? Activo, con saldo y no vencido (chequeo en vivo por si el
	 * cron aún no corrió).
	 *
	 * @param object|null $row Fila.
	 * @return bool
	 */
	public static function is_redeemable( $row ) {
		if ( ! $row ) {
			return false;
		}
		if ( 'active' !== $row->status ) {
			return false;
		}
		if ( (float) $row->balance <= 0 ) {
			return false;
		}
		if ( $row->expires_at && strtotime( $row->expires_at ) <= current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return false;
		}
		return true;
	}

	/**
	 * Motivo por el que un crédito no es canjeable (para mensajes de UI).
	 *
	 * @param object|null $row Fila.
	 * @return string
	 */
	public static function reason_not_redeemable( $row ) {
		if ( ! $row ) {
			return __( 'Código de crédito inexistente.', 'hm-pos' );
		}
		if ( 'void' === $row->status ) {
			return __( 'El crédito fue anulado.', 'hm-pos' );
		}
		if ( 'expired' === $row->status || ( $row->expires_at && strtotime( $row->expires_at ) <= current_time( 'timestamp' ) ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return __( 'El crédito está vencido.', 'hm-pos' );
		}
		if ( (float) $row->balance <= 0 || 'used' === $row->status ) {
			return __( 'El crédito no tiene saldo.', 'hm-pos' );
		}
		return __( 'El crédito no es válido.', 'hm-pos' );
	}

	/* ------------------------------------------------------------------ *
	 * Canje / débito (atómico)
	 * ------------------------------------------------------------------ */

	/**
	 * Debita saldo de forma atómica. UPDATE condicional: solo baja si sigue
	 * activo, no vencido y con saldo suficiente. Evita el sobregiro entre
	 * canjes concurrentes (web ↔ POS).
	 *
	 * NO registra en el ledger (se hace aparte con el order_id ya conocido).
	 *
	 * @param int   $id     ID del crédito.
	 * @param float $amount Monto a debitar (> 0).
	 * @return float|false Saldo resultante, o false si no se pudo debitar.
	 */
	public static function debit( $id, $amount ) {
		global $wpdb;
		$id     = (int) $id;
		$amount = round( (float) $amount, 2 );
		if ( $id <= 0 || $amount <= 0 ) {
			return false;
		}
		$table = self::table();
		$now   = current_time( 'mysql' );
		$amt   = self::dec( $amount );

		// El saldo baja y, si llega a 0, el estado pasa a 'used' en el mismo UPDATE.
		// IMPORTANTE: `status` va ANTES que `balance` en el SET. MySQL evalúa las
		// asignaciones en orden usando el valor ya actualizado de las columnas
		// previas; poniendo status primero, su CASE lee el balance ORIGINAL (si no,
		// restaría el monto dos veces y marcaría 'used' de más).
		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"UPDATE $table
				 SET status = CASE WHEN balance - %s <= 0 THEN 'used' ELSE status END,
				     balance = balance - %s,
				     updated_at = %s
				 WHERE id = %d
				   AND status = 'active'
				   AND balance >= %s
				   AND (expires_at IS NULL OR expires_at > %s)",
				$amt,
				$amt,
				$now,
				$id,
				$amt,
				$now
			)
		);

		if ( 1 !== (int) $affected ) {
			return false;
		}

		$row = self::get( $id );
		return $row ? (float) $row->balance : false;
	}

	/**
	 * Reintegra saldo (reversa de un débito que no llegó a concretar la venta).
	 * Reactiva el crédito si estaba 'used' por haber quedado en 0.
	 *
	 * @param int   $id     ID.
	 * @param float $amount Monto a reintegrar.
	 * @return float|false Saldo resultante.
	 */
	public static function credit_back( $id, $amount ) {
		global $wpdb;
		$id     = (int) $id;
		$amount = round( (float) $amount, 2 );
		if ( $id <= 0 || $amount <= 0 ) {
			return false;
		}
		$table = self::table();
		$now   = current_time( 'mysql' );
		$amt   = self::dec( $amount );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"UPDATE $table
				 SET balance = balance + %s,
				     status = CASE WHEN status = 'used' THEN 'active' ELSE status END,
				     updated_at = %s
				 WHERE id = %d",
				$amt,
				$now,
				$id
			)
		);

		if ( ! $affected ) {
			return false;
		}
		$row = self::get( $id );
		return $row ? (float) $row->balance : false;
	}

	/* ------------------------------------------------------------------ *
	 * Ledger
	 * ------------------------------------------------------------------ */

	/**
	 * Registra un movimiento en el ledger.
	 *
	 * @param int    $credit_id     Crédito.
	 * @param string $type          issue|redeem|reissue|void|adjust.
	 * @param float  $amount        Con signo (+ suma, − resta).
	 * @param float  $balance_after Saldo tras el movimiento.
	 * @param array  $args          { order_id, channel, cajero_id, note }.
	 * @return void
	 */
	public static function log( $credit_id, $type, $amount, $balance_after, $args = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::ledger_table(),
			array(
				'credit_id'     => (int) $credit_id,
				'type'          => sanitize_key( $type ),
				'amount'        => self::dec( $amount ),
				'balance_after' => self::dec( $balance_after ),
				'order_id'      => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
				'channel'       => isset( $args['channel'] ) ? sanitize_key( $args['channel'] ) : '',
				'cajero_id'     => isset( $args['cajero_id'] ) ? (int) $args['cajero_id'] : 0,
				'note'          => isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Vencimiento (cron)
	 * ------------------------------------------------------------------ */

	/**
	 * Marca como vencidos los créditos activos cuya fecha ya pasó.
	 *
	 * @return int Cantidad de créditos vencidos.
	 */
	public static function expire_due() {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"UPDATE $table SET status = 'expired', updated_at = %s
				 WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s",
				$now,
				$now
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Forma de datos / helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Da forma a un crédito para el front / REST.
	 *
	 * @param object|null $row Fila.
	 * @return array|null
	 */
	public static function payload( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'             => (int) $row->id,
			'code'           => $row->code,
			'initial_amount' => (float) $row->initial_amount,
			'balance'        => (float) $row->balance,
			'status'         => $row->status,
			'kind'           => isset( $row->kind ) ? $row->kind : 'refund',
			'redeemable'     => self::is_redeemable( $row ),
			'customer_id'    => (int) $row->customer_id,
			'customer_email' => $row->customer_email,
			'expires_at'     => $row->expires_at,
			'issued_at'      => $row->issued_at,
		);
	}

	/**
	 * Normaliza un código (mayúsculas, sin espacios).
	 *
	 * @param string $code Código.
	 * @return string
	 */
	public static function normalize_code( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$code = preg_replace( '/\s+/', '', $code );
		return $code;
	}

	/**
	 * Formatea un monto como decimal con punto (independiente de locale) para
	 * bindear en columnas DECIMAL.
	 *
	 * @param float $n Monto.
	 * @return string
	 */
	private static function dec( $n ) {
		return number_format( (float) $n, 2, '.', '' );
	}

	/* ------------------------------------------------------------------ *
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Registra el menú de admin "Créditos (POS)".
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'hmpos',
			__( 'Créditos en tienda', 'hm-pos' ),
			__( 'Créditos', 'hm-pos' ),
			'manage_woocommerce',
			'hmpos-credits',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Renderiza la pantalla de admin: buscador + listado de créditos.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'hm-pos' ) );
		}
		global $wpdb;
		$table = self::table();

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 30;
		$offset = ( $paged - 1 ) * $per;

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( self::normalize_code( $search ) ) . '%';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE code LIKE %s OR customer_email LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, '%' . $wpdb->esc_like( $search ) . '%', $per, $offset ) ); // phpcs:ignore
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE code LIKE %s OR customer_email LIKE %s", $like, '%' . $wpdb->esc_like( $search ) . '%' ) ); // phpcs:ignore
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per, $offset ) ); // phpcs:ignore
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore
		}

		$sum_active = (float) $wpdb->get_var( "SELECT COALESCE(SUM(balance),0) FROM $table WHERE status = 'active'" ); // phpcs:ignore
		$labels     = array(
			'active'  => __( 'Activo', 'hm-pos' ),
			'used'    => __( 'Usado', 'hm-pos' ),
			'expired' => __( 'Vencido', 'hm-pos' ),
			'void'    => __( 'Anulado', 'hm-pos' ),
		);

		// Código recién emitido (para mostrarlo destacado tras el redirect).
		$issued = isset( $_GET['issued'] ) ? self::normalize_code( wp_unslash( $_GET['issued'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$issued = ( '' !== $issued && self::get_by_code( $issued ) ) ? $issued : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Créditos en tienda', 'hm-pos' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: saldo total activo. */
					esc_html__( 'Saldo activo en circulación: %s', 'hm-pos' ),
					'<strong>' . wp_kses_post( wc_price( $sum_active ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
				· <?php printf( esc_html__( 'Vigencia por defecto: %d días', 'hm-pos' ), (int) self::get_expiry_days() ); ?>
			</p>

			<?php if ( '' !== $issued ) : ?>
				<div class="notice notice-success">
					<p style="font-size:14px">
						<?php esc_html_e( 'Gift card emitida. Código:', 'hm-pos' ); ?>
						<code style="font-size:18px;padding:4px 8px"><?php echo esc_html( $issued ); ?></code>
						<?php esc_html_e( 'Anotalo/entregáselo al cliente.', 'hm-pos' ); ?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['issue_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No se pudo emitir la gift card (revisá el monto).', 'hm-pos' ); ?></p></div>
			<?php endif; ?>

			<h2 style="margin-top:1em"><?php esc_html_e( 'Emitir gift card', 'hm-pos' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;max-width:760px">
				<input type="hidden" name="action" value="hmpos_credit_issue" />
				<?php wp_nonce_field( 'hmpos_credit_issue' ); ?>
				<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
					<label><?php esc_html_e( 'Monto', 'hm-pos' ); ?><br />
						<input type="number" name="amount" min="1" step="0.01" required style="width:140px" placeholder="0,00" />
					</label>
					<label><?php esc_html_e( 'Vigencia (días)', 'hm-pos' ); ?><br />
						<input type="number" name="expiry_days" min="0" step="1" value="<?php echo (int) self::get_expiry_days(); ?>" style="width:100px" />
					</label>
					<label><?php esc_html_e( 'Email (opcional)', 'hm-pos' ); ?><br />
						<input type="email" name="customer_email" style="width:220px" placeholder="cliente@ejemplo.com" />
					</label>
					<label style="flex:1;min-width:180px"><?php esc_html_e( 'Nota (opcional)', 'hm-pos' ); ?><br />
						<input type="text" name="note" style="width:100%" placeholder="<?php esc_attr_e( 'Ej: regalo cumpleaños', 'hm-pos' ); ?>" />
					</label>
					<button class="button button-primary"><?php esc_html_e( 'Emitir', 'hm-pos' ); ?></button>
				</div>
				<p class="description" style="margin:8px 0 0"><?php esc_html_e( 'Genera un crédito al portador (0 días = sin vencimiento). El código se muestra al confirmar.', 'hm-pos' ); ?></p>
			</form>

			<form method="get">
				<input type="hidden" name="page" value="hmpos-credits" />
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Código o email…', 'hm-pos' ); ?>" />
					<button class="button"><?php esc_html_e( 'Buscar', 'hm-pos' ); ?></button>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Código', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Saldo', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Emitido', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Vence', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Origen', 'hm-pos' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'hm-pos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'Sin créditos todavía.', 'hm-pos' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><code><?php echo esc_html( $r->code ); ?></code></td>
								<td><strong><?php echo wp_kses_post( wc_price( (float) $r->balance ) ); ?></strong>
									<br /><small class="description"><?php echo wp_kses_post( wc_price( (float) $r->initial_amount ) ); ?> <?php esc_html_e( 'inicial', 'hm-pos' ); ?></small></td>
								<td><?php echo esc_html( $r->issued_at ? mysql2date( 'd/m/Y', $r->issued_at ) : '—' ); ?></td>
								<td><?php echo esc_html( isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status ); ?></td>
								<td><?php echo esc_html( $r->customer_email ? $r->customer_email : '—' ); ?></td>
								<td><?php echo esc_html( $r->expires_at ? mysql2date( 'd/m/Y', $r->expires_at ) : __( 'sin venc.', 'hm-pos' ) ); ?></td>
								<td>
									<?php
									$is_gift = isset( $r->kind ) && 'giftcard' === $r->kind;
									if ( $is_gift ) {
										echo '<span class="dashicons dashicons-tickets-alt" style="color:#996b00"></span> ';
										esc_html_e( 'Gift card', 'hm-pos' );
										if ( $r->source_order_id ) {
											echo ' · <a href="' . esc_url( admin_url( 'post.php?action=edit&post=' . (int) $r->source_order_id ) ) . '">' . esc_html( sprintf( __( 'venta #%d', 'hm-pos' ), (int) $r->source_order_id ) ) . '</a>';
										} else {
											echo ' <span class="description">(' . esc_html__( 'admin', 'hm-pos' ) . ')</span>';
										}
									} elseif ( $r->source_order_id ) {
										echo '<a href="' . esc_url( admin_url( 'post.php?action=edit&post=' . (int) $r->source_order_id ) ) . '">' . esc_html( sprintf( __( 'devolución #%d', 'hm-pos' ), (int) $r->source_order_id ) ) . '</a>';
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php if ( 'active' === $r->status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
											<input type="hidden" name="action" value="hmpos_credit_extend" />
											<input type="hidden" name="credit_id" value="<?php echo (int) $r->id; ?>" />
											<?php wp_nonce_field( 'hmpos_credit_extend_' . (int) $r->id ); ?>
											<button class="button button-small"><?php esc_html_e( '+30 días', 'hm-pos' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( '¿Anular este crédito?', 'hm-pos' ) ); ?>');">
											<input type="hidden" name="action" value="hmpos_credit_void" />
											<input type="hidden" name="credit_id" value="<?php echo (int) $r->id; ?>" />
											<?php wp_nonce_field( 'hmpos_credit_void_' . (int) $r->id ); ?>
											<button class="button button-small button-link-delete"><?php esc_html_e( 'Anular', 'hm-pos' ); ?></button>
										</form>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handler admin-post: emite una gift card a mano.
	 */
	public static function handle_issue() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'hm-pos' ) );
		}
		check_admin_referer( 'hmpos_credit_issue' );

		$amount = isset( $_POST['amount'] ) ? round( (float) wp_unslash( $_POST['amount'] ), 2 ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$args   = array(
			'kind'           => 'giftcard',
			'channel'        => 'admin',
			'customer_email' => isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '',
			'note'           => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
			'cajero_id'      => get_current_user_id(),
		);
		if ( isset( $_POST['expiry_days'] ) && '' !== $_POST['expiry_days'] ) {
			$args['expiry_days'] = max( 0, (int) $_POST['expiry_days'] );
		}

		$redirect = admin_url( 'admin.php?page=hmpos-credits' );
		$result   = self::issue( $amount, $args );
		if ( ! is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'issued', rawurlencode( $result['code'] ), $redirect );
		} else {
			$redirect = add_query_arg( 'issue_error', '1', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handler admin-post: anula un crédito.
	 */
	public static function handle_void() {
		$id = isset( $_POST['credit_id'] ) ? absint( $_POST['credit_id'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $id ) {
			wp_die( esc_html__( 'Sin permiso.', 'hm-pos' ) );
		}
		check_admin_referer( 'hmpos_credit_void_' . $id );

		global $wpdb;
		$row = self::get( $id );
		if ( $row && 'active' === $row->status ) {
			$wpdb->update( self::table(), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log( $id, 'void', -1 * (float) $row->balance, 0, array( 'channel' => 'admin', 'cajero_id' => get_current_user_id() ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=hmpos-credits' ) );
		exit;
	}

	/**
	 * Handler admin-post: extiende el vencimiento +30 días.
	 */
	public static function handle_extend() {
		$id = isset( $_POST['credit_id'] ) ? absint( $_POST['credit_id'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $id ) {
			wp_die( esc_html__( 'Sin permiso.', 'hm-pos' ) );
		}
		check_admin_referer( 'hmpos_credit_extend_' . $id );

		global $wpdb;
		$row = self::get( $id );
		if ( $row && 'active' === $row->status ) {
			$base = $row->expires_at ? $row->expires_at : current_time( 'mysql' );
			$new  = gmdate( 'Y-m-d H:i:s', strtotime( $base . ' +30 days' ) );
			$wpdb->update( self::table(), array( 'expires_at' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log( $id, 'adjust', 0, (float) $row->balance, array( 'channel' => 'admin', 'cajero_id' => get_current_user_id(), 'note' => 'Vencimiento +30 días' ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=hmpos-credits' ) );
		exit;
	}
}
