<?php
/**
 * Arqueo de caja: sesiones de apertura/cierre, retiros y desglose por medio.
 *
 * Una sesión de caja es un CPT `hmpos_cash_session`. Su post ID es el
 * `register_session_id` que se guarda en cada orden del POS (`_hmpos_session`).
 * El efectivo esperado y el desglose por medio se calculan sumando las órdenes
 * ligadas a la sesión.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lógica de caja.
 */
class HMPOS_Cash {

	const CPT = 'hmpos_cash_session';

	/**
	 * Engancha el registro del CPT.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );

		// Lista de admin como historial de arqueos (solo lectura): columnas útiles,
		// sin "Añadir nueva" ni edición (las sesiones las genera el POS).
		if ( is_admin() ) {
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column' ), 10, 2 );
			add_filter( 'bulk_actions-edit-' . self::CPT, '__return_empty_array' );
			add_filter( 'post_row_actions', array( __CLASS__, 'admin_row_actions' ), 10, 2 );
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_detail_metabox' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Historial de arqueos en el admin (solo lectura)
	 * ------------------------------------------------------------------ */

	/** Columnas de la lista de sesiones de caja. */
	public static function admin_columns( $cols ) {
		return array(
			'apertura'   => __( 'Apertura', 'hm-pos' ),
			'cierre'     => __( 'Cierre', 'hm-pos' ),
			'cajero'     => __( 'Cajero', 'hm-pos' ),
			'estado'     => __( 'Estado', 'hm-pos' ),
			'fondo'      => __( 'Fondo', 'hm-pos' ),
			'retiros'    => __( 'Retiros', 'hm-pos' ),
			'esperado'   => __( 'Esperado', 'hm-pos' ),
			'contado'    => __( 'Contado', 'hm-pos' ),
			'diferencia' => __( 'Diferencia', 'hm-pos' ),
		);
	}

	/**
	 * Etiqueta legible de un cajero: nombre visible → usuario → email → '—'.
	 * (Algunos usuarios —p. ej. un admin— pueden no tener display_name.)
	 *
	 * @param int $uid User ID.
	 * @return string
	 */
	public static function cashier_label( $uid ) {
		$u = $uid ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			return '—';
		}
		return $u->display_name ? $u->display_name : ( $u->user_login ? $u->user_login : $u->user_email );
	}

	/** Formatea un monto con el símbolo de la tienda. */
	private static function fmt( $n ) {
		return function_exists( 'wc_price' ) ? wc_price( (float) $n ) : number_format_i18n( (float) $n, 2 );
	}

	/** Formatea una fecha mysql a d/m/Y H:i (o '—'). */
	private static function fmt_date( $mysql ) {
		return $mysql ? esc_html( mysql2date( 'd/m/Y H:i', $mysql ) ) : '—';
	}

	/** Render de cada columna. */
	public static function admin_column( $col, $post_id ) {
		switch ( $col ) {
			case 'apertura':
				$link = get_edit_post_link( $post_id );
				$txt  = self::fmt_date( get_post_meta( $post_id, '_hmpos_opened_at', true ) );
				echo $link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					? '<a href="' . esc_url( $link ) . '" title="' . esc_attr__( 'Ver detalle', 'hm-pos' ) . '"><strong>' . esc_html( $txt ) . '</strong></a>'
					: esc_html( $txt );
				break;
			case 'cierre':
				echo self::fmt_date( get_post_meta( $post_id, '_hmpos_closed_at', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'cajero':
				echo esc_html( self::cashier_label( (int) get_post_meta( $post_id, '_hmpos_cajero_id', true ) ) );
				break;
			case 'estado':
				$open = 'open' === get_post_meta( $post_id, '_hmpos_status', true );
				printf(
					'<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;%s">%s</span>',
					$open ? 'background:#e1f7ef;color:#15805f' : 'background:#eef1f6;color:#50607a', // phpcs:ignore
					esc_html( $open ? __( 'Abierta', 'hm-pos' ) : __( 'Cerrada', 'hm-pos' ) )
				);
				break;
			case 'fondo':
				echo wp_kses_post( self::fmt( get_post_meta( $post_id, '_hmpos_opening_float', true ) ) );
				break;
			case 'retiros':
				$t = (float) get_post_meta( $post_id, '_hmpos_withdrawals_total', true );
				echo $t > 0 ? wp_kses_post( self::fmt( $t ) ) : '—';
				break;
			case 'esperado':
				$v = get_post_meta( $post_id, '_hmpos_expected_cash', true );
				echo '' !== $v ? wp_kses_post( self::fmt( $v ) ) : '—';
				break;
			case 'contado':
				$v = get_post_meta( $post_id, '_hmpos_counted_cash', true );
				echo '' !== $v ? wp_kses_post( self::fmt( $v ) ) : '—';
				break;
			case 'diferencia':
				$v = get_post_meta( $post_id, '_hmpos_difference', true );
				if ( '' === $v ) {
					echo '—';
					break;
				}
				$d     = (float) $v;
				$color = $d < 0 ? '#c0392b' : ( $d > 0 ? '#b7791f' : '#15805f' );
				$lbl   = $d < 0 ? __( 'FALTA', 'hm-pos' ) : ( $d > 0 ? __( 'SOBRA', 'hm-pos' ) : __( 'OK', 'hm-pos' ) );
				printf(
					'<strong style="color:%s">%s</strong> <span style="color:%s;font-size:11px">%s</span>',
					esc_attr( $color ),
					wp_kses_post( self::fmt( abs( $d ) ) ),
					esc_attr( $color ),
					esc_html( $lbl )
				);
				break;
		}
	}

	/** Sin acciones de fila (es un registro; no se edita/borra a mano). */
	public static function admin_row_actions( $actions, $post ) {
		if ( isset( $post->post_type ) && self::CPT === $post->post_type ) {
			return array();
		}
		return $actions;
	}

	/** Caja de detalle de la sesión (retiros/ingresos con motivo + desglose). */
	public static function add_detail_metabox() {
		add_meta_box( 'hmpos_cash_detail', __( 'Detalle de la caja', 'hm-pos' ), array( __CLASS__, 'render_detail' ), self::CPT, 'normal', 'high' );
	}

	public static function render_detail( $post ) {
		$id = (int) $post->ID;
		$p  = self::session_payload( $id );

		echo '<table class="widefat" style="max-width:420px;margin-bottom:16px"><tbody>';
		$rows = array(
			array( __( 'Fondo inicial', 'hm-pos' ), self::fmt( $p['opening_float'] ) ),
			array( __( 'Ventas efectivo', 'hm-pos' ), self::fmt( $p['cash_sales'] ) ),
			array( __( 'Ingresos', 'hm-pos' ), self::fmt( $p['deposits_total'] ) ),
			array( __( 'Retiros', 'hm-pos' ), '− ' . self::fmt( $p['withdrawals_total'] ) ),
			array( __( 'Devoluciones efectivo', 'hm-pos' ), '− ' . self::fmt( $p['cash_refunds'] ) ),
			array( __( 'Esperado en cajón', 'hm-pos' ), self::fmt( $p['expected_cash'] ) ),
		);
		if ( 'closed' === $p['status'] ) {
			$rows[] = array( __( 'Contado', 'hm-pos' ), self::fmt( isset( $p['counted_cash'] ) ? $p['counted_cash'] : 0 ) );
			$rows[] = array( __( 'Diferencia', 'hm-pos' ), self::fmt( isset( $p['difference'] ) ? $p['difference'] : 0 ) );
		}
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td style="text-align:right">%s</td></tr>', esc_html( $r[0] ), wp_kses_post( $r[1] ) );
		}
		echo '</tbody></table>';

		// Desglose por medio de pago.
		if ( ! empty( $p['breakdown'] ) ) {
			echo '<h3>' . esc_html__( 'Por medio de pago', 'hm-pos' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:420px"><tbody>';
			foreach ( $p['breakdown'] as $b ) {
				if ( (float) $b['total'] <= 0 && (int) $b['count'] <= 0 ) {
					continue;
				}
				printf(
					'<tr><td>%s <span style="color:#888">(%d)</span></td><td style="text-align:right">%s</td></tr>',
					esc_html( $b['label'] ),
					(int) $b['count'],
					wp_kses_post( self::fmt( $b['total'] ) )
				);
			}
			echo '</tbody></table>';
		}

		self::render_move_table( __( 'Retiros', 'hm-pos' ), $p['withdrawals'], 'reason' );
		self::render_move_table( __( 'Ingresos', 'hm-pos' ), $p['deposits'], 'concept' );
	}

	/** Tabla de movimientos (retiros/ingresos) con su motivo. */
	private static function render_move_table( $title, $list, $concept_key ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( empty( $list ) || ! is_array( $list ) ) {
			echo '<p>' . esc_html__( 'Sin movimientos.', 'hm-pos' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Fecha', 'hm-pos' ), __( 'Concepto', 'hm-pos' ), __( 'Monto', 'hm-pos' ), __( 'Cajero', 'hm-pos' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $list as $m ) {
			$concept = isset( $m[ $concept_key ] ) ? $m[ $concept_key ] : '';
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td>%s</td></tr>',
				esc_html( self::fmt_date( isset( $m['at'] ) ? $m['at'] : '' ) ),
				esc_html( '' !== $concept ? $concept : '—' ),
				wp_kses_post( self::fmt( isset( $m['amount'] ) ? $m['amount'] : 0 ) ),
				esc_html( isset( $m['cajero'] ) ? $m['cajero'] : '' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Registra el CPT de sesiones de caja (visible solo en admin).
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Sesiones de caja', 'hm-pos' ),
					'singular_name' => __( 'Sesión de caja', 'hm-pos' ),
					'menu_name'     => __( 'Cajas (POS)', 'hm-pos' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'hmpos', // submenú de "HM POS"
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-money-alt',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				// Solo lectura desde el admin: las sesiones las crea el POS
				// (wp_insert_post programático, no pasa por esta cap). Oculta "Añadir nueva".
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'supports'        => array( 'title' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Consulta
	 * ------------------------------------------------------------------ */

	/**
	 * ID de la sesión de caja abierta DE UN CAJERO, o 0 si no tiene ninguna.
	 *
	 * Multi-caja: cada usuario logueado tiene su propia caja (se opera con un
	 * usuario distinto por PC). Sin `$user_id` usa el usuario actual.
	 *
	 * @param int $user_id Cajero (0 = usuario actual).
	 * @return int
	 */
	public static function get_open_session_id( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array( 'key' => '_hmpos_status', 'value' => 'open' ),
					array( 'key' => '_hmpos_cajero_id', 'value' => $user_id ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * ¿El post es una sesión de caja abierta?
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function is_open( $id ) {
		return $id && get_post_type( $id ) === self::CPT && 'open' === get_post_meta( $id, '_hmpos_status', true );
	}

	/**
	 * Sucursal de una sesión de caja ('' si no tiene / no aplica).
	 *
	 * @param int $id Session ID.
	 * @return string branch_id
	 */
	public static function session_branch( $id ) {
		return $id ? (string) get_post_meta( $id, '_hmpos_branch', true ) : '';
	}

	/**
	 * Sucursal de la caja ABIERTA de un cajero (la que opera). '' si no hay caja
	 * o no tiene sucursal. Es de dónde se descuenta/reingresa el stock por sucursal.
	 *
	 * @param int $user_id Cajero (0 = usuario actual).
	 * @return string branch_id
	 */
	public static function open_session_branch( $user_id = 0 ) {
		return self::session_branch( self::get_open_session_id( $user_id ) );
	}

	/**
	 * Órdenes del POS ligadas a una sesión (pagadas).
	 *
	 * @param int $id Session ID.
	 * @return WC_Order[]
	 */
	private static function session_orders( $id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order', // excluye refunds (también llevan _hmpos_session)
				'limit'      => -1,
				// Incluye 'refunded' para que una venta devuelta TOTALMENTE en la misma
				// sesión no desaparezca del arqueo: su total bruto queda en las ventas y
				// el reintegro (cash_refunds) lo neutraliza. Sin esto, la venta se caía
				// del cálculo pero el reintegro igual se restaba → sobrante fantasma.
				'status'     => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_hmpos_session',
						'value'   => (string) $id,
						'compare' => '=',
					),
				),
			)
		);

		// Blindaje cross-storage: wc_get_orders con meta_query se respeta con HPOS,
		// pero el store legacy (HPOS apagado) puede ignorarlo y devolver TODAS las
		// órdenes. Filtramos en PHP para quedarnos SOLO con ventas del POS de ESTA
		// sesión — así las órdenes de la WEB (Mercado Pago, transferencia del gateway,
		// etc.) nunca contaminan el arqueo ni el desglose por medio.
		$out = array();
		foreach ( (array) $orders as $o ) {
			if ( $o instanceof WC_Order
				&& 'hmpos' === $o->get_created_via()
				&& (string) $id === (string) $o->get_meta( '_hmpos_session' ) ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Operaciones
	 * ------------------------------------------------------------------ */

	/**
	 * Abre una sesión de caja con un fondo inicial.
	 *
	 * @param float $opening_float Fondo inicial.
	 * @param int   $user_id       Cajero.
	 * @return array|WP_Error Payload de la sesión, o error si ya hay una abierta.
	 */
	public static function open_session( $opening_float, $user_id, $branch_id = '' ) {
		// Multi-caja: solo se rechaza si ESTE cajero ya tiene una caja abierta.
		if ( self::get_open_session_id( $user_id ) ) {
			return new WP_Error( 'hmpos_cash_open', __( 'Ya tenés una caja abierta.', 'hm-pos' ), array( 'status' => 409 ) );
		}

		// Sucursal de la caja: si hay sucursales activas, es obligatoria (define de
		// dónde se descuenta/reingresa el stock por sucursal). Sin sucursales, se ignora.
		$branch = '';
		if ( class_exists( 'HMPOS_Branches' ) && HMPOS_Branches::active() ) {
			$branch = HMPOS_Branches::valid_id( $branch_id );
			if ( '' === $branch ) {
				return new WP_Error( 'hmpos_cash_branch', __( 'Elegí la sucursal de la caja.', 'hm-pos' ), array( 'status' => 400 ) );
			}
		}

		$user = get_userdata( $user_id );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: fecha/hora, 2: cajero. */
					__( 'Caja %1$s · %2$s', 'hm-pos' ),
					wp_date( 'd/m/Y H:i' ),
					$user ? $user->display_name : ''
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_hmpos_status', 'open' );
		update_post_meta( $id, '_hmpos_cajero_id', (int) $user_id );
		if ( '' !== $branch ) {
			update_post_meta( $id, '_hmpos_branch', $branch );
		}
		update_post_meta( $id, '_hmpos_opening_float', round( (float) $opening_float, 2 ) );
		update_post_meta( $id, '_hmpos_opened_at', current_time( 'mysql' ) );
		update_post_meta( $id, '_hmpos_withdrawals', array() );
		update_post_meta( $id, '_hmpos_withdrawals_total', 0 );
		update_post_meta( $id, '_hmpos_deposits', array() );
		update_post_meta( $id, '_hmpos_deposits_total', 0 );

		return self::session_payload( $id );
	}

	/**
	 * Registra un retiro de efectivo de la sesión abierta.
	 *
	 * @param int    $id     Session ID.
	 * @param float  $amount Monto.
	 * @param string $reason Motivo.
	 * @return array|WP_Error
	 */
	public static function add_withdrawal( $id, $amount, $reason ) {
		if ( ! self::is_open( $id ) ) {
			return new WP_Error( 'hmpos_cash_closed', __( 'No hay una caja abierta.', 'hm-pos' ), array( 'status' => 409 ) );
		}
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'hmpos_bad_amount', __( 'Monto de retiro inválido.', 'hm-pos' ), array( 'status' => 400 ) );
		}

		$uid    = get_current_user_id();
		$list   = get_post_meta( $id, '_hmpos_withdrawals', true );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'amount'    => $amount,
			'reason'    => $reason,
			'at'        => current_time( 'mysql' ),
			'cajero_id' => (int) $uid, // atribución en caja compartida (un cajón, varios cajeros)
			'cajero'    => $uid ? self::cashier_label( $uid ) : '',
		);
		update_post_meta( $id, '_hmpos_withdrawals', $list );
		update_post_meta( $id, '_hmpos_withdrawals_total', round( array_sum( wp_list_pluck( $list, 'amount' ) ), 2 ) );

		return self::session_payload( $id );
	}

	/**
	 * Registra un ingreso de efectivo al cajón (aporte, cambio, ajuste), con
	 * concepto. Suma al efectivo esperado.
	 *
	 * @param int    $id      Session ID.
	 * @param float  $amount  Monto.
	 * @param string $concept Concepto.
	 * @return array|WP_Error
	 */
	public static function add_deposit( $id, $amount, $concept ) {
		if ( ! self::is_open( $id ) ) {
			return new WP_Error( 'hmpos_cash_closed', __( 'No hay una caja abierta.', 'hm-pos' ), array( 'status' => 409 ) );
		}
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'hmpos_bad_amount', __( 'Monto de ingreso inválido.', 'hm-pos' ), array( 'status' => 400 ) );
		}

		$uid    = get_current_user_id();
		$list   = get_post_meta( $id, '_hmpos_deposits', true );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'amount'    => $amount,
			'concept'   => sanitize_text_field( (string) $concept ),
			'at'        => current_time( 'mysql' ),
			'cajero_id' => (int) $uid,
			'cajero'    => $uid ? self::cashier_label( $uid ) : '',
		);
		update_post_meta( $id, '_hmpos_deposits', $list );
		update_post_meta( $id, '_hmpos_deposits_total', round( array_sum( wp_list_pluck( $list, 'amount' ) ), 2 ) );

		return self::session_payload( $id );
	}

	/**
	 * Cierra la sesión: calcula esperado vs. contado + desglose y lo guarda.
	 *
	 * @param int   $id           Session ID.
	 * @param float $counted_cash Efectivo contado.
	 * @return array|WP_Error
	 */
	public static function close_session( $id, $counted_cash ) {
		if ( ! self::is_open( $id ) ) {
			return new WP_Error( 'hmpos_cash_closed', __( 'No hay una caja abierta para cerrar.', 'hm-pos' ), array( 'status' => 409 ) );
		}

		$summary  = self::compute_summary( $id );
		$counted  = round( (float) $counted_cash, 2 );
		$expected = $summary['expected_cash'];
		$diff     = round( $counted - $expected, 2 );

		update_post_meta( $id, '_hmpos_status', 'closed' );
		update_post_meta( $id, '_hmpos_closed_at', current_time( 'mysql' ) );
		update_post_meta( $id, '_hmpos_counted_cash', $counted );
		update_post_meta( $id, '_hmpos_expected_cash', $expected );
		update_post_meta( $id, '_hmpos_difference', $diff );
		update_post_meta( $id, '_hmpos_breakdown', wp_json_encode( $summary['breakdown'] ) );

		return self::session_payload( $id );
	}

	/* ------------------------------------------------------------------ *
	 * Cálculo y forma de datos
	 * ------------------------------------------------------------------ */

	/**
	 * Suma ventas de la sesión: total por medio de pago + efectivo esperado.
	 *
	 * @param int $id Session ID.
	 * @return array{breakdown:array,sales_total:float,cash_sales:float,expected_cash:float}
	 */
	public static function compute_summary( $id ) {
		$methods   = HMPOS_REST::payment_methods();
		$breakdown = array();
		foreach ( $methods as $mid => $mlabel ) {
			$breakdown[ $mid ] = array(
				'id'    => $mid,
				'label' => $mlabel,
				'total' => 0.0,
				'count' => 0,
			);
		}
		// Bucket informativo del crédito en tienda (no es efectivo del cajón).
		$breakdown['credito'] = array(
			'id'    => 'credito',
			'label' => __( 'Crédito en tienda', 'hm-pos' ),
			'total' => 0.0,
			'count' => 0,
		);

		$sales_total = 0.0;
		$cash_sales  = 0.0;
		$by_cashier  = array(); // desglose de ventas por cajero (caja compartida: un cajón, varios cajeros)
		foreach ( self::session_orders( $id ) as $order ) {
			if ( ! ( $order instanceof WC_Order ) ) {
				continue; // defensivo: nunca un refund
			}
			$pm          = $order->get_payment_method();
			$total       = (float) $order->get_total();
			$credit_used = (float) $order->get_meta( '_hmpos_credit_used' );
			$charged     = round( $total - $credit_used, 2 ); // lo cobrado por el medio real
			$sales_total += $total;

			// Atribución por cajero (quién hizo la venta).
			$cid = (int) $order->get_meta( '_hmpos_cajero_id' );
			if ( ! isset( $by_cashier[ $cid ] ) ) {
				$by_cashier[ $cid ] = array(
					'id'    => $cid,
					'name'  => self::cashier_label( $cid ),
					'total' => 0.0,
					'count' => 0,
				);
			}
			$by_cashier[ $cid ]['total'] += $total;
			++$by_cashier[ $cid ]['count'];

			if ( ! isset( $breakdown[ $pm ] ) ) {
				$breakdown[ $pm ] = array(
					'id'    => $pm,
					'label' => $order->get_payment_method_title() ? $order->get_payment_method_title() : $pm,
					'total' => 0.0,
					'count' => 0,
				);
			}
			$breakdown[ $pm ]['total'] += $charged;
			++$breakdown[ $pm ]['count'];

			// Porción pagada con crédito (informativa). Para ventas 100% crédito el
			// medio ya es 'credito' y $charged es 0, así que no se duplica.
			if ( $credit_used > 0 ) {
				$breakdown['credito']['total'] += $credit_used;
				if ( 'credito' !== $pm ) {
					++$breakdown['credito']['count'];
				}
			}

			if ( 'efectivo' === $pm ) {
				$cash_sales += $charged;
			}
		}

		$float    = (float) get_post_meta( $id, '_hmpos_opening_float', true );
		$wd       = (float) get_post_meta( $id, '_hmpos_withdrawals_total', true );
		$deposits = (float) get_post_meta( $id, '_hmpos_deposits_total', true );
		$refunds  = (float) get_post_meta( $id, '_hmpos_cash_refunds_total', true );

		// Ordena el desglose por cajero de mayor a menor venta.
		$by_cashier = array_values( $by_cashier );
		usort(
			$by_cashier,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return array(
			'breakdown'     => array_values( $breakdown ),
			'by_cashier'    => $by_cashier,
			'sales_total'   => round( $sales_total, 2 ),
			'cash_sales'    => round( $cash_sales, 2 ),
			'cash_refunds'  => round( $refunds, 2 ),
			'deposits'      => round( $deposits, 2 ),
			'expected_cash' => round( $float + $cash_sales + $deposits - $wd - $refunds, 2 ),
		);
	}

	/**
	 * Registra una devolución en efectivo contra la caja abierta (sale del cajón).
	 * Si no hay caja abierta, no hace nada (el refund igual queda en la orden).
	 *
	 * @param float $amount Monto devuelto en efectivo.
	 * @return int Session ID afectado, o 0.
	 */
	public static function record_cash_refund( $amount ) {
		$id = self::get_open_session_id();
		if ( ! $id ) {
			return 0;
		}
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return $id;
		}
		$prev = (float) get_post_meta( $id, '_hmpos_cash_refunds_total', true );
		update_post_meta( $id, '_hmpos_cash_refunds_total', round( $prev + $amount, 2 ) );
		return $id;
	}

	/**
	 * Forma de una sesión para el front.
	 *
	 * @param int $id Session ID.
	 * @return array
	 */
	public static function session_payload( $id ) {
		$status  = get_post_meta( $id, '_hmpos_status', true );
		$summary = self::compute_summary( $id );
		$list    = get_post_meta( $id, '_hmpos_withdrawals', true );

		$cajero_id   = (int) get_post_meta( $id, '_hmpos_cajero_id', true );
		$cajero_user = $cajero_id ? get_userdata( $cajero_id ) : null;

		$branch_id = self::session_branch( $id );

		$payload = array(
			'register_session_id' => (int) $id,
			'status'              => $status,
			'cajero_id'           => $cajero_id,
			'cajero_name'         => $cajero_user ? $cajero_user->display_name : '',
			'branch_id'           => $branch_id,
			'branch_name'         => ( '' !== $branch_id && class_exists( 'HMPOS_Branches' ) ) ? HMPOS_Branches::name_of( $branch_id ) : '',
			'opening_float'       => (float) get_post_meta( $id, '_hmpos_opening_float', true ),
			'opened_at'           => get_post_meta( $id, '_hmpos_opened_at', true ),
			'withdrawals'         => is_array( $list ) ? $list : array(),
			'withdrawals_total'   => (float) get_post_meta( $id, '_hmpos_withdrawals_total', true ),
			'deposits'            => is_array( get_post_meta( $id, '_hmpos_deposits', true ) ) ? get_post_meta( $id, '_hmpos_deposits', true ) : array(),
			'deposits_total'      => (float) get_post_meta( $id, '_hmpos_deposits_total', true ),
			'sales_total'         => $summary['sales_total'],
			'cash_sales'          => $summary['cash_sales'],
			'cash_refunds'        => $summary['cash_refunds'],
			'expected_cash'       => $summary['expected_cash'],
			'breakdown'           => $summary['breakdown'],
			'by_cashier'          => $summary['by_cashier'],
		);

		if ( 'closed' === $status ) {
			$payload['closed_at']    = get_post_meta( $id, '_hmpos_closed_at', true );
			$payload['counted_cash'] = (float) get_post_meta( $id, '_hmpos_counted_cash', true );
			$payload['difference']   = (float) get_post_meta( $id, '_hmpos_difference', true );
		}

		return $payload;
	}
}
