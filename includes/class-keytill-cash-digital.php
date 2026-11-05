<?php
/**
 * Caja digital (cuenta bancaria única).
 *
 * Espejo de la caja física, pero para la ÚNICA cuenta a la que entran todos los
 * cobros no-efectivo (transferencia, QR, tarjeta, etc.). Es un registro GLOBAL
 * (una sola, para todos los cajeros) con saldo corriente:
 *
 *   saldo = apertura + cobros no-efectivo − devoluciones + ingresos − retiros
 *
 * A diferencia del efectivo, el saldo NO se cuenta: se arrastra. La apertura de
 * un período = el saldo del último cierre. La primera vez se carga a mano.
 *
 * Períodos CONTIGUOS: el período nuevo arranca en el instante del cierre anterior
 * (no en el de la apertura), para que las ventas por transferencia que ocurran
 * con la caja digital cerrada no se pierdan — se capturan en el período siguiente.
 *
 * Los cobros/devoluciones se DERIVAN de las órdenes del POS por fecha + medio (no
 * se postean a mano), así el saldo siempre es coherente con las ventas reales.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Cash_Digital {

	/** CPT de cada período de la caja digital. */
	const CPT = 'keytill_digital_sess';

	/** Opción: saldo del último cierre (apertura del próximo período). */
	const LAST_BALANCE = 'keytill_digital_last_balance';

	/** Opción: fecha/hora del último cierre (inicio del próximo período). */
	const LAST_CLOSE = 'keytill_digital_last_close';

	/** Opción-bandera: ya se cargó alguna vez el saldo inicial. */
	const SEEDED = 'keytill_digital_seeded';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );

		// Historial de períodos en el admin (solo lectura) + detalle por período.
		if ( is_admin() ) {
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column' ), 10, 2 );
			add_filter( 'bulk_actions-edit-' . self::CPT, '__return_empty_array' );
			add_filter( 'post_row_actions', array( __CLASS__, 'admin_row_actions' ), 10, 2 );
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_detail_metabox' ) );
		}
	}

	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Caja digital', 'keytill' ),
					'singular_name' => __( 'Período de caja digital', 'keytill' ),
					'menu_name'     => __( 'Caja digital', 'keytill' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'keytill', // submenú de "HM POS"
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-bank',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				// Solo lectura: los períodos los crea el POS (no se agregan a mano).
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'supports'            => array( 'title' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Historial en el admin (solo lectura)
	 * ------------------------------------------------------------------ */

	public static function admin_columns( $cols ) {
		return array(
			'apertura' => __( 'Apertura', 'keytill' ),
			'cierre'   => __( 'Cierre', 'keytill' ),
			'abrio'    => __( 'Abrió', 'keytill' ),
			'estado'   => __( 'Estado', 'keytill' ),
			'inicial'  => __( 'Saldo inicial', 'keytill' ),
			'cobros'   => __( 'Cobros', 'keytill' ),
			'ingresos' => __( 'Ingresos', 'keytill' ),
			'retiros'  => __( 'Retiros', 'keytill' ),
			'saldo'    => __( 'Saldo', 'keytill' ),
		);
	}

	public static function admin_column( $col, $post_id ) {
		$open = 'open' === get_post_meta( $post_id, '_keytill_status', true );
		// Para períodos cerrados usamos el snapshot guardado al cerrar (rápido);
		// para el abierto, se calcula en vivo.
		$get = function ( $meta_key, $summary_key ) use ( $post_id, $open ) {
			if ( $open ) {
				$s = self::compute_summary( $post_id );
				return $s[ $summary_key ];
			}
			return (float) get_post_meta( $post_id, $meta_key, true );
		};
		switch ( $col ) {
			case 'apertura':
				$link = get_edit_post_link( $post_id );
				$txt  = self::fmt_date( get_post_meta( $post_id, '_keytill_opened_at', true ) );
				echo $link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					? '<a href="' . esc_url( $link ) . '" title="' . esc_attr__( 'Ver detalle', 'keytill' ) . '"><strong>' . esc_html( $txt ) . '</strong></a>'
					: esc_html( $txt );
				break;
			case 'cierre':
				echo esc_html( self::fmt_date( get_post_meta( $post_id, '_keytill_closed_at', true ) ) );
				break;
			case 'abrio':
				$by = (int) get_post_meta( $post_id, '_keytill_opened_by', true );
				echo esc_html( $by ? self::user_label( $by ) : '—' );
				break;
			case 'estado':
				printf(
					'<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;%s">%s</span>',
					$open ? 'background:#e1f7ef;color:#15805f' : 'background:#eef1f6;color:#50607a', // phpcs:ignore
					esc_html( $open ? __( 'Abierta', 'keytill' ) : __( 'Cerrada', 'keytill' ) )
				);
				break;
			case 'inicial':
				echo wp_kses_post( self::fmt( get_post_meta( $post_id, '_keytill_opening_balance', true ) ) );
				break;
			case 'cobros':
				echo wp_kses_post( self::fmt( $get( '_keytill_sum_sales', 'sales' ) ) );
				break;
			case 'ingresos':
				echo wp_kses_post( self::fmt( $get( '_keytill_sum_ingresos', 'ingresos' ) ) );
				break;
			case 'retiros':
				echo wp_kses_post( self::fmt( $get( '_keytill_sum_retiros', 'retiros' ) ) );
				break;
			case 'saldo':
				$v = $open ? self::compute_summary( $post_id )['balance'] : (float) get_post_meta( $post_id, '_keytill_closing_balance', true );
				echo '<strong>' . wp_kses_post( self::fmt( $v ) ) . '</strong>';
				break;
		}
	}

	public static function admin_row_actions( $actions, $post ) {
		if ( isset( $post->post_type ) && self::CPT === $post->post_type ) {
			return array();
		}
		return $actions;
	}

	private static function fmt( $n ) {
		return function_exists( 'wc_price' ) ? wc_price( (float) $n ) : number_format_i18n( (float) $n, 2 );
	}

	private static function fmt_date( $mysql ) {
		return $mysql ? mysql2date( 'd/m/Y H:i', $mysql ) : '—';
	}

	/** Caja de detalle del período (movimientos con motivo + transferencias). */
	public static function add_detail_metabox() {
		add_meta_box( 'keytill_digital_detail', __( 'Detalle del período', 'keytill' ), array( __CLASS__, 'render_detail' ), self::CPT, 'normal', 'high' );
	}

	public static function render_detail( $post ) {
		$id = (int) $post->ID;
		$s  = self::compute_summary( $id );

		echo '<table class="widefat" style="max-width:420px;margin-bottom:16px"><tbody>';
		$rows = array(
			array( __( 'Saldo inicial', 'keytill' ), self::fmt( $s['opening'] ) ),
			array( __( 'Cobros (no-efectivo)', 'keytill' ), self::fmt( $s['sales'] ) ),
			array( __( 'Devoluciones', 'keytill' ), '− ' . self::fmt( $s['refunds'] ) ),
			array( __( 'Ingresos', 'keytill' ), self::fmt( $s['ingresos'] ) ),
			array( __( 'Retiros', 'keytill' ), '− ' . self::fmt( $s['retiros'] ) ),
		);
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td style="text-align:right">%s</td></tr>', esc_html( $r[0] ), wp_kses_post( $r[1] ) );
		}
		printf( '<tr><td><strong>%s</strong></td><td style="text-align:right"><strong>%s</strong></td></tr>', esc_html__( 'Saldo', 'keytill' ), wp_kses_post( self::fmt( $s['balance'] ) ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Movimientos manuales', 'keytill' ) . '</h3>';
		if ( empty( $s['moves'] ) ) {
			echo '<p>' . esc_html__( 'Sin movimientos manuales en el período.', 'keytill' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Fecha', 'keytill' ), __( 'Tipo', 'keytill' ), __( 'Concepto', 'keytill' ), __( 'Monto', 'keytill' ), __( 'Usuario', 'keytill' ) ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $s['moves'] as $m ) {
				$ingreso = 'ingreso' === $m['kind'];
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right">%s%s</td><td>%s</td></tr>',
					esc_html( self::fmt_date( $m['at'] ) ),
					esc_html( $ingreso ? __( 'Ingreso', 'keytill' ) : __( 'Retiro', 'keytill' ) ),
					esc_html( '' !== $m['concept'] ? $m['concept'] : '—' ),
					$ingreso ? '' : '− ',
					wp_kses_post( self::fmt( $m['amount'] ) ),
					esc_html( isset( $m['user'] ) ? $m['user'] : '' )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Transferencias del período', 'keytill' ) . '</h3>';
		if ( empty( $s['transfers'] ) ) {
			echo '<p>' . esc_html__( 'Sin transferencias en el período.', 'keytill' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Fecha', 'keytill' ), __( 'Venta', 'keytill' ), __( 'Cliente', 'keytill' ), __( 'Monto', 'keytill' ), __( 'Verificación', 'keytill' ) ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $s['transfers'] as $tx ) {
				$vl = 'verificada' === $tx['verification'] ? __( 'Verificada', 'keytill' ) : ( 'no_llego' === $tx['verification'] ? __( 'No llegó', 'keytill' ) : __( 'Pendiente', 'keytill' ) );
				printf(
					'<tr><td>%s</td><td>#%s</td><td>%s</td><td style="text-align:right">%s</td><td>%s</td></tr>',
					esc_html( $tx['date'] ),
					esc_html( $tx['number'] ),
					esc_html( '' !== $tx['customer'] ? $tx['customer'] : '—' ),
					wp_kses_post( self::fmt( $tx['amount'] ) ),
					esc_html( $vl )
				);
			}
			echo '</tbody></table>';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Estado
	 * ------------------------------------------------------------------ */

	/** Id del período abierto (único y global), o 0. */
	public static function get_open_session_id() {
		$q = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_keytill_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => 'open',          // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	public static function is_open( $id ) {
		return 'open' === get_post_meta( (int) $id, '_keytill_status', true );
	}

	/** ¿Ya se inicializó la caja digital alguna vez? */
	public static function is_seeded() {
		return (bool) get_option( self::SEEDED, false );
	}

	/* ------------------------------------------------------------------ *
	 * Operaciones
	 * ------------------------------------------------------------------ */

	/**
	 * Abre un período de la caja digital.
	 *
	 * La apertura = saldo del último cierre. La PRIMERA vez (nunca inicializada)
	 * requiere el saldo inicial a mano ($initial).
	 *
	 * @param int        $user_id Usuario que abre (admin/encargado).
	 * @param float|null $initial Saldo inicial (solo la primera vez).
	 * @return array|WP_Error
	 */
	public static function open_session( $user_id, $initial = null ) {
		if ( self::get_open_session_id() ) {
			return new WP_Error( 'keytill_digital_open', __( 'La caja digital ya está abierta.', 'keytill' ), array( 'status' => 409 ) );
		}

		if ( self::is_seeded() ) {
			$opening = (float) get_option( self::LAST_BALANCE, 0 );
			// El período nuevo arranca 1 seg. DESPUÉS del último cierre (en GMT): así
			// las ventas del borde quedan en el período que cerró y no se duplican, y
			// las que ocurrieron con la caja cerrada se capturan acá.
			$last        = (string) get_option( self::LAST_CLOSE, '' );
			$period_from = $last ? gmdate( 'Y-m-d H:i:s', strtotime( $last . ' UTC' ) + 1 ) : current_time( 'mysql', true );
		} else {
			if ( null === $initial ) {
				return new WP_Error( 'keytill_digital_seed', __( 'Cargá el saldo inicial de la caja digital (solo la primera vez).', 'keytill' ), array( 'status' => 400 ) );
			}
			$opening     = round( (float) $initial, 2 );
			$period_from = current_time( 'mysql', true ); // GMT
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => sprintf( __( 'Caja digital %s', 'keytill' ), wp_date( 'd/m/Y H:i' ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_keytill_status', 'open' );
		update_post_meta( $id, '_keytill_opened_by', (int) $user_id );
		update_post_meta( $id, '_keytill_opened_at', current_time( 'mysql' ) );
		update_post_meta( $id, '_keytill_period_from', $period_from ); // desde acá cuentan las ventas
		update_post_meta( $id, '_keytill_opening_balance', $opening );
		update_post_meta( $id, '_keytill_moves', array() );
		update_option( self::SEEDED, 1 );

		return self::payload( $id );
	}

	/**
	 * Registra un movimiento manual (retiro o ingreso) en la caja digital abierta.
	 *
	 * @param int    $id      Período.
	 * @param string $kind    'retiro' | 'ingreso'.
	 * @param float  $amount  Monto (> 0).
	 * @param string $concept Concepto.
	 * @return array|WP_Error
	 */
	public static function add_move( $id, $kind, $amount, $concept ) {
		if ( ! self::is_open( $id ) ) {
			return new WP_Error( 'keytill_digital_closed', __( 'La caja digital no está abierta.', 'keytill' ), array( 'status' => 409 ) );
		}
		$kind   = ( 'ingreso' === $kind ) ? 'ingreso' : 'retiro';
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'keytill_bad_amount', __( 'Monto inválido.', 'keytill' ), array( 'status' => 400 ) );
		}
		$uid    = get_current_user_id();
		$list   = get_post_meta( $id, '_keytill_moves', true );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'kind'    => $kind,
			'amount'  => $amount,
			'concept' => sanitize_text_field( (string) $concept ),
			'at'      => current_time( 'mysql' ),
			'user_id' => (int) $uid,
			'user'    => $uid ? self::user_label( $uid ) : '',
		);
		update_post_meta( $id, '_keytill_moves', $list );
		return self::payload( $id );
	}

	/**
	 * Cierra el período: calcula el saldo final y lo deja como apertura del próximo.
	 *
	 * @param int $id Período.
	 * @return array|WP_Error
	 */
	public static function close_session( $id ) {
		if ( ! self::is_open( $id ) ) {
			return new WP_Error( 'keytill_digital_closed', __( 'La caja digital no está abierta.', 'keytill' ), array( 'status' => 409 ) );
		}
		$gmt = current_time( 'mysql', true );                               // borde del período (GMT)
		update_post_meta( $id, '_keytill_period_to', $gmt );
		update_post_meta( $id, '_keytill_closed_at', current_time( 'mysql' ) ); // para mostrar (local)

		$summary = self::compute_summary( $id );
		$closing = $summary['balance'];

		update_post_meta( $id, '_keytill_status', 'closed' );
		update_post_meta( $id, '_keytill_closing_balance', $closing );
		// Snapshot para el historial del admin (evita recalcular al listar).
		update_post_meta( $id, '_keytill_sum_sales', $summary['sales'] );
		update_post_meta( $id, '_keytill_sum_refunds', $summary['refunds'] );
		update_post_meta( $id, '_keytill_sum_ingresos', $summary['ingresos'] );
		update_post_meta( $id, '_keytill_sum_retiros', $summary['retiros'] );

		update_option( self::LAST_BALANCE, $closing );
		update_option( self::LAST_CLOSE, $gmt );

		return self::payload( $id );
	}

	/* ------------------------------------------------------------------ *
	 * Cálculo
	 * ------------------------------------------------------------------ */

	/**
	 * Resumen del período: apertura, cobros no-efectivo, devoluciones, ingresos,
	 * retiros y saldo. Incluye la lista de transferencias (para la conciliación).
	 *
	 * @param int $id Período.
	 * @return array
	 */
	public static function compute_summary( $id ) {
		$opening = (float) get_post_meta( $id, '_keytill_opening_balance', true );
		$from    = (string) get_post_meta( $id, '_keytill_period_from', true ); // GMT
		$end     = (string) get_post_meta( $id, '_keytill_period_to', true );   // GMT (si cerró)
		if ( '' === $end ) {
			$end = current_time( 'mysql', true ); // GMT ahora
		}

		$sales   = 0.0;
		$refunds = 0.0;
		$count   = 0;
		$txfers  = array(); // ventas no-efectivo (para la conciliación / tilde)

		foreach ( self::period_orders( $from, $end ) as $o ) {
			$pm = $o->get_payment_method();
			if ( 'efectivo' === $pm || 'credito' === $pm ) {
				continue; // no es dinero de la cuenta digital
			}
			$charged = round( (float) $o->get_total() - (float) $o->get_meta( '_keytill_credit_used' ), 2 );
			if ( $charged <= 0 ) {
				continue;
			}
			$sales += $charged;
			++$count;

			// Devoluciones de esta venta reintegradas por transferencia (restan).
			$ref = self::order_digital_refund( $o );
			$refunds += $ref;

			$txfers[] = array(
				'order_id'     => $o->get_id(),
				'number'       => $o->get_order_number(),
				'date'         => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
				'method'       => $o->get_payment_method_title() ? $o->get_payment_method_title() : $pm,
				'amount'       => $charged,
				'refunded'     => $ref,
				'customer'     => self::order_customer( $o ),
				'verification' => self::verification_of( $o->get_id() ),
			);
		}

		$moves    = get_post_meta( $id, '_keytill_moves', true );
		$moves    = is_array( $moves ) ? $moves : array();
		$ingresos = 0.0;
		$retiros  = 0.0;
		foreach ( $moves as $m ) {
			if ( 'ingreso' === $m['kind'] ) {
				$ingresos += (float) $m['amount'];
			} else {
				$retiros += (float) $m['amount'];
			}
		}

		$balance = round( $opening + $sales - $refunds + $ingresos - $retiros, 2 );

		return array(
			'opening'     => round( $opening, 2 ),
			'sales'       => round( $sales, 2 ),
			'sales_count' => $count,
			'refunds'     => round( $refunds, 2 ),
			'ingresos'    => round( $ingresos, 2 ),
			'retiros'     => round( $retiros, 2 ),
			'balance'     => $balance,
			'transfers'   => $txfers,
			'moves'       => array_values( $moves ),
		);
	}

	/** Órdenes del POS en [from, end]. */
	private static function period_orders( $from, $end ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
				'limit'        => -1,
				'date_created' => strtotime( $from . ' UTC' ) . '...' . strtotime( $end . ' UTC' ),
			)
		);
		$out = array();
		foreach ( (array) $orders as $o ) {
			if ( $o instanceof WC_Order && 'keytill' === $o->get_created_via() ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/**
	 * Monto devuelto de una orden por vía digital (transferencia). Los reintegros
	 * por 'efectivo' o 'credito' NO afectan la caja digital.
	 */
	private static function order_digital_refund( $order ) {
		$sum = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			if ( 'transferencia' === $refund->get_meta( '_keytill_refund_method' ) ) {
				$stored = (float) $refund->get_meta( '_keytill_digital_refund' );
				$sum   += $stored > 0 ? $stored : abs( (float) $refund->get_total() );
			}
		}
		return round( $sum, 2 );
	}

	/* ------------------------------------------------------------------ *
	 * Conciliación (tilde de verificación de transferencias)
	 * ------------------------------------------------------------------ */

	/** Estado de verificación de una venta ('pendiente' | 'verificada' | 'no_llego'). */
	public static function verification_of( $order_id ) {
		$v = get_post_meta( (int) $order_id, '_keytill_transfer_check', true );
		return in_array( $v, array( 'verificada', 'no_llego' ), true ) ? $v : 'pendiente';
	}

	/** Marca el estado de verificación de una transferencia. */
	public static function set_verification( $order_id, $status ) {
		$status = in_array( $status, array( 'verificada', 'no_llego', 'pendiente' ), true ) ? $status : 'pendiente';
		if ( 'pendiente' === $status ) {
			delete_post_meta( (int) $order_id, '_keytill_transfer_check' );
		} else {
			update_post_meta( (int) $order_id, '_keytill_transfer_check', $status );
		}
		return $status;
	}

	/* ------------------------------------------------------------------ *
	 * Forma de datos
	 * ------------------------------------------------------------------ */

	public static function payload( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return array( 'open' => false, 'seeded' => self::is_seeded(), 'last_balance' => (float) get_option( self::LAST_BALANCE, 0 ) );
		}
		$status  = get_post_meta( $id, '_keytill_status', true );
		$summary = self::compute_summary( $id );
		$by      = (int) get_post_meta( $id, '_keytill_opened_by', true );

		$payload = array(
			'open'        => 'open' === $status,
			'seeded'      => true,
			'session_id'  => $id,
			'status'      => $status,
			'opened_at'   => get_post_meta( $id, '_keytill_opened_at', true ),
			'opened_by'   => $by ? self::user_label( $by ) : '',
			'opening'     => $summary['opening'],
			'sales'       => $summary['sales'],
			'sales_count' => $summary['sales_count'],
			'refunds'     => $summary['refunds'],
			'ingresos'    => $summary['ingresos'],
			'retiros'     => $summary['retiros'],
			'balance'     => $summary['balance'],
			'transfers'   => $summary['transfers'],
			'moves'       => $summary['moves'],
		);
		if ( 'closed' === $status ) {
			$payload['closed_at']       = get_post_meta( $id, '_keytill_closed_at', true );
			$payload['closing_balance'] = (float) get_post_meta( $id, '_keytill_closing_balance', true );
		}
		return $payload;
	}

	/** Estado actual para el front (abierta o no). */
	public static function current_payload() {
		$id = self::get_open_session_id();
		return self::payload( $id );
	}

	private static function order_customer( $order ) {
		$name = $order->get_meta( '_keytill_customer_name' );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return $name;
	}

	private static function user_label( $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return '';
		}
		$full = trim( $u->first_name . ' ' . $u->last_name );
		if ( '' !== $full ) {
			return $full;
		}
		return ( $u->display_name && false === strpos( $u->display_name, '@' ) ) ? $u->display_name : '';
	}
}
