<?php
/**
 * Exportación de pedidos a CSV (para análisis en Excel).
 *
 * Descarga vía GET al front: ?keytill_export=orders&<filtros>&_wpnonce=…
 * Requiere la capability del POS + un nonce. Genera UNA FILA POR PRODUCTO
 * VENDIDO (línea de pedido), con vendedor, cajero, medio de pago, etc., para
 * poder pivotear (qué vendedor vendió más, qué producto se vendió más, …).
 *
 * Formato pensado para Excel en español: separador «;», decimales con coma y
 * BOM UTF-8 (para que respete los acentos).
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Export {

	/** Query var que dispara la descarga. */
	const QV = 'keytill_export';

	/** Acción del nonce. */
	const NONCE = 'keytill_export_orders';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/** URL base de descarga (con nonce). El front le agrega los filtros. */
	public static function url() {
		return add_query_arg(
			array(
				self::QV   => 'orders',
				'_wpnonce' => wp_create_nonce( self::NONCE ),
			),
			home_url( '/' )
		);
	}

	/** ¿Puede exportar? Misma barrera que operar el POS o gestionar pedidos. */
	private static function can_export() {
		return current_user_can( KEYTILL_CAP ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Si la request pide exportar, valida y streamea el CSV; corta.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET[ self::QV ] ) || 'orders' !== $_GET[ self::QV ] ) {
			return;
		}
		if ( ! self::can_export() ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'keytill' ), 403 );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'The export link expired. Generate it again from the POS.', 'keytill' ), 403 );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_die( esc_html__( 'WooCommerce is unavailable.', 'keytill' ), 500 );
		}

		self::stream_csv();
		exit;
	}

	/**
	 * Arma la consulta de pedidos con los mismos filtros del listado y streamea
	 * el CSV (una fila por línea de pedido).
	 */
	private static function stream_csv() {
		$g = function ( $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- maybe_render() ya verifico el nonce y la capability antes de llegar aca.
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		};

		// Estado.
		$status       = sanitize_key( $g( 'status' ) );
		$all_statuses = array_map(
			function ( $s ) {
				return 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s;
			},
			array_keys( wc_get_order_statuses() )
		);
		$args = array(
			'type'    => 'shop_order',
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => ( $status && 'any' !== $status && in_array( $status, $all_statuses, true ) ) ? array( $status ) : $all_statuses,
			// `limit`/`offset` se setean por lote más abajo (no se cargan todos juntos).
		);

		$pm = sanitize_key( $g( 'payment_method' ) );
		if ( $pm ) {
			$args['payment_method'] = $pm;
		}

		$meta_query = array();
		$origin     = sanitize_key( $g( 'origin' ) );
		if ( 'pos' === $origin ) {
			$meta_query[] = array( 'key' => '_keytill', 'compare' => 'EXISTS' );
		} elseif ( 'web' === $origin ) {
			$meta_query[] = array( 'key' => '_keytill', 'compare' => 'NOT EXISTS' );
		}
		$cajero = (int) $g( 'cajero_id' );
		if ( $cajero ) {
			$meta_query[] = array( 'key' => '_keytill_cajero_id', 'value' => $cajero );
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Se exige un rango de fechas (Desde y Hasta): no se exporta todo el histórico.
		$from = $g( 'date_from' );
		$to   = $g( 'date_to' );
		if ( '' === $from || '' === $to ) {
			wp_die( esc_html__( 'Choose a date range (From and To) to export.', 'keytill' ), 400 );
		}
		$args['date_created'] = strtotime( $from . ' 00:00:00' ) . '...' . strtotime( $to . ' 23:59:59' );
		$search = trim( $g( 'search' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// Salvaguardas para exportaciones extensas: sin tope de tiempo, sin acumular
		// objetos en el object cache (se procesa de a lotes) y sin buffers que
		// retengan todo en memoria. Evita agotar la memoria de PHP o cortar por
		// timeout cuando el rango abarca miles de pedidos.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Cabeceras HTTP de descarga.
		$fname = 'pedidos-' . ( $from ? $from : 'inicio' ) . '_' . ( $to ? $to : gmdate( 'Y-m-d' ) ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		header( 'X-Accel-Buffering: no' ); // no bufferear en proxies/Nginx

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output es el stream de la respuesta HTTP, no el filesystem: WP_Filesystem no aplica.
		$out = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- idem: stream de salida.
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 para Excel.

		$cols = array(
			__( 'Order', 'keytill' ),
			__( 'Date', 'keytill' ),
			__( 'Status', 'keytill' ),
			__( 'Source', 'keytill' ),
			__( 'Customer', 'keytill' ),
			__( 'Tax ID', 'keytill' ),
			__( 'Salesperson', 'keytill' ),
			__( 'Cashier', 'keytill' ),
			__( 'Payment method', 'keytill' ),
			__( 'Product', 'keytill' ),
			__( 'Variation', 'keytill' ),
			__( 'Quantity', 'keytill' ),
			__( 'Unit price', 'keytill' ),
			__( 'Amount', 'keytill' ),
			__( 'Order total', 'keytill' ),
		);
		fputcsv( $out, $cols, self::delimiter() );

		$statuses = wc_get_order_statuses();

		// Recorre los pedidos DE A LOTES (no todos de una) para mantener la memoria
		// acotada sin importar cuántos pedidos haya en el rango.
		$batch  = 500;
		$offset = 0;
		do {
			$args['limit']  = $batch;
			$args['offset'] = $offset;
			$orders = wc_get_orders( $args );
			$count  = is_array( $orders ) ? count( $orders ) : 0;

			foreach ( (array) $orders as $o ) {
				if ( ! $o instanceof WC_Order ) {
					continue;
				}
				$number    = $o->get_order_number();
				$date      = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '';
				$st_key    = 'wc-' . $o->get_status();
				$st        = isset( $statuses[ $st_key ] ) ? $statuses[ $st_key ] : $o->get_status();
				$is_pos    = 'keytill' === $o->get_created_via();
				$origin_lb = $is_pos ? 'POS' : __( 'Web', 'keytill' );

				$cust = (string) $o->get_meta( '_keytill_customer_name' );
				if ( '' === $cust ) {
					$cust = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
				}
				$doc = (string) $o->get_meta( '_keytill_customer_doc' );

				$seller = (string) $o->get_meta( '_keytill_seller' );
				if ( '' === $seller ) {
					$sid    = (string) $o->get_meta( '_keytill_seller_id' );
					$seller = ( '' !== $sid && class_exists( 'KEYTILL_Sellers' ) ) ? KEYTILL_Sellers::name_of( $sid ) : '';
				}

				$cashier = self::cashier_name( (int) $o->get_meta( '_keytill_cajero_id' ) );
				$pay     = $o->get_payment_method_title();
				$o_total = self::num( (float) $o->get_total() );

				$items = $o->get_items( 'line_item' );
				if ( empty( $items ) ) {
					continue;
				}
				foreach ( $items as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$qty   = (int) $item->get_quantity();
					$total = (float) $item->get_total();
					$unit  = $qty ? $total / $qty : $total;

					// Variación: atributos de la línea, en una sola celda.
					$attrs = wc_display_item_meta(
						$item,
						array( 'before' => '', 'after' => '', 'separator' => ' · ', 'echo' => false, 'autop' => false )
					);

					fputcsv(
						$out,
						array(
							$number,
							$date,
							$st,
							$origin_lb,
							$cust,
							$doc,
							$seller,
							$cashier,
							$pay,
							$item->get_name(),
							wp_strip_all_tags( (string) $attrs ),
							$qty,
							self::num( $unit ),
							self::num( $total ),
							$o_total,
						),
						self::delimiter()
					);
				}
			}
			$offset += $batch;
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		} while ( $count === $batch );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- idem: stream de salida.
		fclose( $out );
	}

	/**
	 * Número para el CSV: toma los decimales y el separador de WooCommerce, así
	 * los importes del export coinciden con los que muestra la tienda en
	 * cualquier país. Sin separador de miles, que confunde a las planillas.
	 */
	private static function num( $n ) {
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$sep = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		return number_format( (float) $n, $dec, $sep, '' );
	}

	/**
	 * Delimitador del CSV. Si los decimales usan coma hay que separar con punto
	 * y coma, o la planilla parte los importes en dos columnas.
	 */
	private static function delimiter() {
		$sep = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		return ( ',' === $sep ) ? ';' : ',';
	}

	/** Nombre del cajero (usuario WP) sin exponer el email/login. */
	private static function cashier_name( $uid ) {
		if ( ! $uid ) {
			return '';
		}
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
