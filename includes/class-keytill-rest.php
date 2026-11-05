<?php
/**
 * API REST del POS — namespace keytill/v1. Mismo origen, con nonce.
 *
 * Seguridad: permission_callback exige la capability del cajero; el nonce
 * (X-WP-Nonce) lo verifica la capa REST de WordPress; entrada saneada y
 * salida tipada/escapada.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rutas REST del POS.
 */
class KEYTILL_REST {

	const NS = 'keytill/v1';

	/**
	 * Medios de pago admitidos (id => etiqueta). Espeja METHODS del prototipo.
	 *
	 * @return array<string,string>
	 */
	public static function payment_methods() {
		// Fuente única: los medios configurados en Ajustes (Efectivo fijo + los
		// no-efectivo del local). Fallback a los 4 clásicos si la clase no está.
		if ( class_exists( 'KEYTILL_Settings' ) ) {
			return KEYTILL_Settings::payment_methods();
		}
		return array(
			'efectivo'      => 'Efectivo',
			'tarjeta'       => 'Tarjeta',
			'transferencia' => 'Transferencia',
			'qr'            => 'QR',
		);
	}

	/**
	 * Engancha el registro de rutas.
	 */
	/** True mientras se atiende una request del POS (staff autorizado). */
	private static $pos_context = false;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Los GET del POS no deben cachearse (caja/stock/pedidos en tiempo real):
		// algunos hostings (LiteSpeed, proxies) cachean /wp-json y servían estados
		// viejos (p. ej. "caja digital cerrada" cuando ya estaba abierta).
		add_filter(
			'rest_post_dispatch',
			function ( $result, $server, $request ) {
				if ( $result instanceof WP_REST_Response
					&& 'GET' === $request->get_method()
					&& 0 === strpos( ltrim( $request->get_route(), '/' ), self::NS ) ) {
					$result->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
					$result->header( 'Pragma', 'no-cache' );
				}
				return $result;
			},
			10,
			3
		);

		// En el POS (staff), un producto PRIVADO (test) con precio es vendible,
		// aunque no lo sea en la web. Solo aplica dentro de una request del POS.
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'allow_private_in_pos' ), 20, 2 );

		// Invalida el cache de la precarga de catálogo (?all=1) cuando cambia algo
		// que afecta a la lista: alta/edición/borrado de productos y variaciones, y
		// movimientos de stock (ventas web/POS, refunds). Barato: solo borra un transient.
		$bust = array( __CLASS__, 'bust_catalog_cache' );
		add_action( 'woocommerce_update_product', $bust );
		add_action( 'woocommerce_new_product', $bust );
		add_action( 'save_post_product', $bust );
		add_action( 'woocommerce_product_set_stock', $bust );
		add_action( 'woocommerce_variation_set_stock', $bust );
		add_action( 'woocommerce_reduce_order_stock', $bust );
		add_action( 'woocommerce_restore_order_stock', $bust );
		add_action( 'woocommerce_trash_product', $bust );
		add_action( 'woocommerce_delete_product', $bust );

		// El POS maneja el stock a mano (por sucursal para los distribuidos, nativo
		// para los legacy), así que Woo NO debe reducir ni restaurar el stock de las
		// órdenes/refunds del POS: si no, duplicaría el descuento del total nativo.
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'skip_woo_stock_for_pos' ), 10, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', array( __CLASS__, 'skip_woo_stock_for_pos' ), 10, 2 );
	}

	/**
	 * ¿Woo debe (des)reducir el stock de esta orden? No para las del POS: el POS
	 * lo hace a mano por sucursal. Evita la doble reducción del `_stock` nativo.
	 *
	 * @param bool     $can   Valor propuesto por Woo.
	 * @param WC_Order $order Orden.
	 * @return bool
	 */
	public static function skip_woo_stock_for_pos( $can, $order ) {
		if ( $order instanceof WC_Order && 'keytill' === $order->get_created_via() ) {
			return false;
		}
		return $can;
	}

	/**
	 * Reintegra el stock ya descontado (rollback ante una carrera de sobreventa).
	 *
	 * @param array $reduced Ítems ya reducidos: [{managed, pid, src, qty, product}].
	 */
	private static function rollback_stock( $reduced ) {
		foreach ( (array) $reduced as $pp ) {
			if ( ! empty( $pp['managed'] ) ) {
				KEYTILL_Branch_Stock::adjust( $pp['pid'], $pp['src'], (int) $pp['qty'] ); // sin guard: reintegra
			} elseif ( $pp['product'] instanceof WC_Product && $pp['product']->managing_stock() ) {
				wc_update_product_stock( $pp['product'], (int) $pp['qty'], 'increase' );
			}
		}
	}

	/** Clave del transient de la precarga de catálogo (versionada por si cambia el shape). */
	const CATALOG_CACHE_KEY = 'keytill_catalog_all_v4';

	/**
	 * Permite vender productos privados (de test) DENTRO del POS. No afecta la web.
	 *
	 * @param bool       $purchasable ¿Comprable según Woo?
	 * @param WC_Product $product     Producto/variación.
	 * @return bool
	 */
	public static function allow_private_in_pos( $purchasable, $product ) {
		if ( $purchasable || ! self::$pos_context || ! $product instanceof WC_Product ) {
			return $purchasable;
		}
		if ( '' === (string) $product->get_price() ) {
			return $purchasable;
		}
		if ( 'private' === $product->get_status() ) {
			return true;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent && 'private' === $parent->get_status() ) {
				return true;
			}
		}
		return $purchasable;
	}

	/** Borra el cache de la precarga de catálogo. */
	public static function bust_catalog_cache() {
		delete_transient( self::CATALOG_CACHE_KEY );
	}

	/**
	 * Registra las rutas.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_products' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'q'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'all' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_order' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);

		// --- Devoluciones ---
		register_rest_route(
			self::NS,
			'/orders/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'order_lookup' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'number' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/refund',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_refund' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);

		// --- Consola · Pedidos (listado, filtros, detalle) ---
		register_rest_route(
			self::NS,
			'/orders/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders_list' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/filters',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders_filters' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'order_detail' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// --- Consola · Productos (listado, detalle, edición rápida, alta) ---
		register_rest_route(
			self::NS,
			'/admin/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'admin_products_list' ),
					'permission_callback' => array( __CLASS__, 'can_operate' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'admin_product_create' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/products/meta',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'admin_products_meta' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'admin_product_detail' ),
					'permission_callback' => array( __CLASS__, 'can_operate' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'admin_product_update' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'admin_product_delete' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
		// Asignación de sucursal en lote (por ids tildados o por filtro completo).
		register_rest_route(
			self::NS,
			'/admin/products/bulk-branch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_products_bulk_branch' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		// Mover stock de una sucursal a otra para un producto/variación.
		register_rest_route(
			self::NS,
			'/admin/products/(?P<id>\d+)/move-stock',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_product_move_stock' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		// Genera la grilla de variaciones de un producto variable (fija atributos + combinaciones).
		register_rest_route(
			self::NS,
			'/admin/products/(?P<id>\d+)/variations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_variations_generate' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		// Atributos globales (pa_*) reutilizables: lista + alta de atributo/término.
		register_rest_route(
			self::NS,
			'/admin/attributes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'admin_attributes_list' ),
					'permission_callback' => array( __CLASS__, 'can_operate' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'admin_attribute_create' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/attributes/(?P<id>\d+)/terms',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_attribute_term_create' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
		// Subida de imagen al media library (para el editor de productos).
		register_rest_route(
			self::NS,
			'/admin/media',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'admin_media_upload' ),
				'permission_callback' => array( __CLASS__, 'can_upload' ),
			)
		);

		// --- Crédito en tienda ---
		register_rest_route(
			self::NS,
			'/credit/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'credit_lookup' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// --- Arqueo de caja ---
		register_rest_route(
			self::NS,
			'/cash/current',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cash_current' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cash/open',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cash_open' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cash/withdraw',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cash_withdraw' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cash/close',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cash_close' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		// Movimiento manual (retiro/ingreso) con origen efectivo o digital.
		register_rest_route(
			self::NS,
			'/cash/move',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cash_move' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		// --- Caja digital (cuenta bancaria única) + Caja/Banco ---
		register_rest_route(
			self::NS,
			'/digital/current',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'digital_current' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/digital/open',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'digital_open' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/digital/close',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'digital_close' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/digital/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'digital_verify' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cashbank/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cashbank_summary' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		// Libro de movimientos (histórico filtrable de ingresos/egresos).
		register_rest_route(
			self::NS,
			'/cashbank/ledger',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'cashbank_ledger' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Devoluciones
	 * ------------------------------------------------------------------ */

	/**
	 * Busca una venta por número (= ID por defecto) y devuelve sus líneas
	 * con la cantidad devolvible.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function order_lookup( WP_REST_Request $request ) {
		$order = self::resolve_pos_order( absint( $request->get_param( 'number' ) ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$qty          = (int) $item->get_quantity();
			$refunded_qty = (int) $order->get_qty_refunded_for_item( $item_id ); // negativo
			$refundable   = max( 0, $qty + $refunded_qty );
			// Precio unitario IVA incluido = lo que pagó el cliente por unidad.
			$paid_line    = (float) $item->get_total() + (float) $item->get_total_tax();
			$unit         = $qty ? ( $paid_line / $qty ) : 0.0;
			$product      = $item->get_product();

			$items[] = array(
				'item_id'        => $item_id,
				'product_id'     => (int) $item->get_product_id(),
				'name'           => $item->get_name(),
				'sku'            => $product ? $product->get_sku() : '',
				'qty'            => $qty,
				'qty_refundable' => $refundable,
				'unit_price'     => round( $unit, 2 ),
				'line_total'     => round( $paid_line, 2 ),
			);
		}

		return new WP_REST_Response(
			array(
				'order_id'       => (int) $order->get_id(),
				'number'         => $order->get_order_number(),
				'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
				'total'          => (float) $order->get_total(),
				'total_refunded' => (float) $order->get_total_refunded(),
				'payment_method' => $order->get_payment_method(),
				'payment_title'  => $order->get_payment_method_title(),
				'is_pos'         => (bool) $order->get_meta( '_keytill' ),
				'items'          => $items,
			),
			200
		);
	}

	/**
	 * Resuelve una orden devolvible por número de venta.
	 *
	 * Acepta ventas del POS **y de la web** (el mostrador recibe devoluciones de
	 * compras online → se emite crédito en tienda). Solo exige que esté **pagada**
	 * (no se devuelve algo que no se cobró). El medio de reintegro lo elige el
	 * cajero al procesar (efectivo del cajón o crédito en tienda).
	 *
	 * @param int $number Número de venta (= post ID por defecto).
	 * @return WC_Order|WP_Error
	 */
	private static function resolve_pos_order( $number ) {
		$order = $number ? wc_get_order( $number ) : false;

		// Fallback por si un plugin de numeración usa números propios.
		if ( ! $order && $number ) {
			$ids = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'limit'      => 1,
					'return'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_order_number',
							'value' => (string) $number,
						),
					),
				)
			);
			if ( $ids ) {
				$order = wc_get_order( $ids[0] );
			}
		}

		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			return new WP_Error( 'keytill_not_found', __( 'Sale not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		// Debe estar pagada. Se aceptan tanto ventas del POS como de la web.
		$blocked = array( 'pending', 'on-hold', 'cancelled', 'failed', 'checkout-draft', 'auto-draft', 'trash' );
		if ( in_array( $order->get_status(), $blocked, true ) ) {
			return new WP_Error( 'keytill_not_paid', __( 'The sale is not paid; it cannot be refunded.', 'keytill' ), array( 'status' => 400 ) );
		}
		return $order;
	}

	/**
	 * Procesa una devolución (refund de Woo) con reingreso de stock.
	 *
	 * Body: { order_id, lines:[{item_id, qty}], reason }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function order_refund( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return new WP_Error( 'keytill_no_woo', __( 'WooCommerce is not available.', 'keytill' ), array( 'status' => 500 ) );
		}
		$params   = self::body( $request );
		$order_id = isset( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order    = self::resolve_pos_order( $order_id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Caja abierta obligatoria: una devolución mueve plata (reintegro en
		// efectivo del cajón) o emite un crédito en tienda; en ambos casos debe
		// quedar ligada a la sesión de caja del cajero para el arqueo. Se valida en
		// el servidor (no se confía en el front).
		if ( ! KEYTILL_Cash::get_open_session_id() ) {
			return new WP_Error( 'keytill_cash_closed', __( 'There is no open register. Open the register to process a refund.', 'keytill' ), array( 'status' => 409 ) );
		}

		$raw_lines = isset( $params['lines'] ) && is_array( $params['lines'] ) ? $params['lines'] : array();
		$reason    = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : '';

		// Medio de reintegro: 'credito' (crédito en tienda), 'efectivo' (cajón) o
		// 'transferencia' (se devuelve por transferencia → resta de la caja digital).
		// Por defecto: si la venta fue en efectivo del POS, efectivo; si no, crédito.
		$refund_method = isset( $params['refund_method'] ) ? sanitize_key( $params['refund_method'] ) : '';
		if ( ! in_array( $refund_method, array( 'credito', 'efectivo', 'transferencia' ), true ) ) {
			$refund_method = ( 'efectivo' === $order->get_payment_method() ) ? 'efectivo' : 'credito';
		}

		$line_items = array();
		$amount     = 0.0;
		foreach ( $raw_lines as $ln ) {
			$item_id = isset( $ln['item_id'] ) ? absint( $ln['item_id'] ) : 0;
			$qty     = isset( $ln['qty'] ) ? absint( $ln['qty'] ) : 0;
			if ( ! $item_id || $qty < 1 ) {
				continue;
			}
			$item = $order->get_item( $item_id );
			if ( ! $item ) {
				continue;
			}
			$max = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id );
			if ( $qty > $max ) {
				return new WP_Error(
					'keytill_refund_qty',
					/* translators: %s: nombre del ítem. */
					sprintf( __( 'Quantity to refund exceeds the available amount: %s.', 'keytill' ), $item->get_name() ),
					array( 'status' => 400 )
				);
			}

			$unit         = $item->get_quantity() ? ( (float) $item->get_total() / $item->get_quantity() ) : 0.0;
			$refund_total = round( $unit * $qty, 2 );

			// Impuestos proporcionales por tasa (si los hubiera).
			$refund_tax = array();
			$taxes      = $item->get_taxes();
			if ( ! empty( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $rate_id => $tax_total ) {
					$tax_unit                = $item->get_quantity() ? ( (float) $tax_total / $item->get_quantity() ) : 0.0;
					$refund_tax[ $rate_id ]  = round( $tax_unit * $qty, 2 );
					$amount                 += $refund_tax[ $rate_id ];
				}
			}

			$amount += $refund_total;

			$line_items[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => $refund_total,
				'refund_tax'   => $refund_tax,
			);
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'keytill_refund_empty', __( 'There are no items to refund.', 'keytill' ), array( 'status' => 400 ) );
		}

		$amount = round( $amount, 2 );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order_id,
				'amount'         => $amount,
				'reason'         => $reason,
				'line_items'     => $line_items,
				// El POS reingresa el stock a mano, a la sucursal de la caja (ver abajo);
				// por eso NO se deja que Woo reingrese al total nativo.
				'restock_items'  => false,
				'refund_payment' => false,
			)
		);

		if ( is_wp_error( $refund ) ) {
			return new WP_Error( 'keytill_refund_fail', $refund->get_error_message(), array( 'status' => 500 ) );
		}

		// Marca la devolución como del POS + cajero + sesión de caja.
		$user       = wp_get_current_user();
		$session_id = KEYTILL_Cash::get_open_session_id();

		// Reintegro de stock: SIEMPRE a la sucursal de la caja que recibe la
		// devolución (ahí queda físicamente la prenda), NO a la de la venta original.
		// Distribuido → suma a esa sucursal; legacy → stock único nativo.
		$refund_branch = $session_id ? KEYTILL_Cash::session_branch( $session_id ) : '';
		foreach ( $line_items as $item_id => $li ) {
			$rqty = (int) $li['qty'];
			if ( $rqty < 1 ) {
				continue;
			}
			$oi = $order->get_item( $item_id );
			if ( ! $oi instanceof WC_Order_Item_Product ) {
				continue;
			}
			$prod = $oi->get_product();
			if ( ! $prod ) {
				continue;
			}
			$spid = $prod->get_id();
			if ( '' !== $refund_branch && KEYTILL_Branch_Stock::is_managed( $spid ) ) {
				KEYTILL_Branch_Stock::adjust( $spid, $refund_branch, $rqty );
			} elseif ( $prod->managing_stock() ) {
				wc_update_product_stock( $prod, $rqty, 'increase' );
			}
		}

		$refund->add_meta_data( '_keytill_refund', 1, true );
		$refund->add_meta_data( '_keytill_cajero_id', (int) $user->ID, true );
		$refund->add_meta_data( '_keytill_cajero_login', $user->user_login, true );
		if ( $session_id ) {
			$refund->add_meta_data( '_keytill_session', $session_id, true );
		}
		$refund->add_meta_data( '_keytill_refund_method', $refund_method, true );

		// Re-acreditación: si la venta se pagó (en parte) con crédito en tienda,
		// esa porción del reintegro vuelve SIEMPRE como crédito nuevo (no en
		// efectivo). El resto va por el medio elegido por el cajero.
		$order_total  = (float) $order->get_total();
		$credit_used  = (float) $order->get_meta( '_keytill_credit_used' );
		$credit_frac  = ( $order_total > 0 && $credit_used > 0 ) ? min( 1.0, $credit_used / $order_total ) : 0.0;
		$reissue_base = round( $amount * $credit_frac, 2 );

		// Órdenes WEB pagadas con crédito: si esta devolución dejó la orden
		// TOTALMENTE reembolsada, el hook woocommerce_order_status_refunded ya
		// restituyó el crédito ORIGINAL (KEYTILL_Credit_Web::ensure_released, que corre
		// sincrónicamente dentro de wc_create_refund). En ese caso NO reemitimos esa
		// porción o el reintegro se duplicaría. La porción no-crédito (el resto) se
		// devuelve igual por el medio elegido.
		$fresh        = wc_get_order( $order_id );
		$web_released = $fresh
			&& 'web' === $fresh->get_meta( '_keytill_credit_channel' )
			&& 'released' === $fresh->get_meta( '_keytill_credit_state' );

		$reissue   = $web_released ? 0.0 : $reissue_base;
		$remainder = round( $amount - $reissue_base, 2 );

		// Monto total a emitir como crédito = re-acreditación + (resto si el medio
		// elegido es crédito). Lo que va en efectivo sale del cajón.
		$credit_amount = ( 'credito' === $refund_method ) ? round( $reissue + $remainder, 2 ) : $reissue;
		$cash_back     = ( 'efectivo' === $refund_method ) ? $remainder : 0.0;
		// Reintegro por transferencia: el resto (lo no re-acreditado como crédito)
		// sale de la cuenta bancaria → la caja digital lo resta por esta meta.
		$digital_back  = ( 'transferencia' === $refund_method ) ? $remainder : 0.0;
		if ( $digital_back > 0 ) {
			$refund->add_meta_data( '_keytill_digital_refund', $digital_back, true );
		}

		$refund->save();

		// Emite el crédito en tienda por el monto que corresponde.
		$credit = null;
		if ( $credit_amount > 0 ) {
			$issued = KEYTILL_Credit::issue(
				$credit_amount,
				array(
					'customer_id'      => (int) $order->get_customer_id(),
					'customer_email'   => $order->get_billing_email(),
					'source_order_id'  => (int) $order->get_id(),
					'source_refund_id' => (int) $refund->get_id(),
					'cajero_id'        => (int) $user->ID,
					'session_id'       => (int) $session_id,
					'channel'          => 'pos',
					'note'             => sprintf(
						/* translators: %s: número de venta. */
						__( 'Refund for sale #%s', 'keytill' ),
						$order->get_order_number()
					),
				)
			);
			if ( ! is_wp_error( $issued ) ) {
				$credit = $issued;
				$refund->add_meta_data( '_keytill_credit_issued_id', $issued['id'], true );
				$refund->add_meta_data( '_keytill_credit_issued_code', $issued['code'], true );
				$refund->save();
			}
		}

		// Reintegro en efectivo: sale del cajón de la caja abierta.
		if ( $cash_back > 0 ) {
			KEYTILL_Cash::record_cash_refund( $cash_back );
		}

		return new WP_REST_Response(
			array(
				'refund_id'      => (int) $refund->get_id(),
				'order_number'   => $order->get_order_number(),
				'amount'         => $amount,
				'refund_method'  => $refund_method,
				'payment_method' => $order->get_payment_method(),
				'payment_title'  => $order->get_payment_method_title(),
				'cash_back'      => $cash_back,
				'digital_back'   => $digital_back,
				'credit_amount'  => $credit ? (float) $credit['balance'] : 0.0,
				'credit_code'    => $credit ? $credit['code'] : '',
				'credit_expires' => $credit && $credit['expires_at'] ? mysql2date( 'd/m/Y', $credit['expires_at'] ) : '',
				'voucher_url'    => $credit ? KEYTILL_Print::voucher_url( $credit['code'] ) : '',
				'restocked'      => true,
			),
			201
		);
	}

	/* ------------------------------------------------------------------ *
	 * Consola · Pedidos (listado / filtros / detalle)
	 * ------------------------------------------------------------------ */

	/**
	 * Lista paginada de pedidos con filtros. Permiso: operate (ver/buscar).
	 *
	 * Query: page, per_page, search (número o cliente), status, payment_method,
	 * origin (pos|web), cajero_id, date_from, date_to (Y-m-d).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function orders_list( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 25;
		$search   = trim( (string) $request->get_param( 'search' ) );

		// Búsqueda por número: atajo directo a esa orden. Si el término es un id
		// de pedido, se devuelve solo ese; si no lo es, NO se corta: cae a la
		// búsqueda de texto (un número también puede ser parte de un nombre/DNI).
		if ( '' !== $search && ctype_digit( $search ) ) {
			$one = wc_get_order( (int) $search );
			if ( $one instanceof WC_Order && 'shop_order' === $one->get_type() ) {
				return new WP_REST_Response(
					array( 'items' => array( self::order_row( $one ) ), 'total' => 1, 'pages' => 1, 'page' => 1, 'per_page' => $per_page ),
					200
				);
			}
		}

		$args = array(
			'type'     => 'shop_order',
			'limit'    => $per_page,
			'paged'    => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		// Estado.
		$status       = sanitize_key( (string) $request->get_param( 'status' ) );
		$all_statuses = array_map(
			function ( $s ) { return 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s; },
			array_keys( wc_get_order_statuses() )
		);
		if ( $status && 'any' !== $status && in_array( $status, $all_statuses, true ) ) {
			$args['status'] = array( $status );
		} else {
			$args['status'] = $all_statuses; // todos menos trash/draft (no listados)
		}

		// Medio de pago.
		$pm = sanitize_key( (string) $request->get_param( 'payment_method' ) );
		if ( $pm ) {
			$args['payment_method'] = $pm;
		}

		// Origen + cajero (meta).
		$meta_query = array();
		$origin     = sanitize_key( (string) $request->get_param( 'origin' ) );
		if ( 'pos' === $origin ) {
			$meta_query[] = array( 'key' => '_keytill', 'compare' => 'EXISTS' );
		} elseif ( 'web' === $origin ) {
			$meta_query[] = array( 'key' => '_keytill', 'compare' => 'NOT EXISTS' );
		}
		$cajero = (int) $request->get_param( 'cajero_id' );
		if ( $cajero ) {
			$meta_query[] = array( 'key' => '_keytill_cajero_id', 'value' => $cajero );
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Rango de fechas.
		$from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		if ( $from && $to ) {
			$args['date_created'] = strtotime( $from . ' 00:00:00' ) . '...' . strtotime( $to . ' 23:59:59' );
		} elseif ( $from ) {
			$args['date_created'] = '>=' . strtotime( $from . ' 00:00:00' );
		} elseif ( $to ) {
			$args['date_created'] = '<=' . strtotime( $to . ' 23:59:59' );
		}

		// Búsqueda de texto: usa el buscador nativo de WooCommerce (`s`), que mira
		// los datos de la orden — nombre/apellido de facturación (donde el POS
		// guarda el nombre del cliente, tanto en venta anónima como con cliente
		// adjunto), email, etc. Antes se resolvía a ids de usuario y, si no había
		// match, caía a `customer=[0]` devolviendo TODOS los pedidos de invitado
		// (bug: mostraba pedidos sin relación con el nombre buscado).
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$result = wc_get_orders( $args );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : (array) $result;
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		$pages  = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		$items = array();
		foreach ( $orders as $o ) {
			if ( $o instanceof WC_Order ) {
				$items[] = self::order_row( $o );
			}
		}

		return new WP_REST_Response(
			array( 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per_page ),
			200
		);
	}

	/**
	 * Fila resumida de un pedido para el listado.
	 *
	 * @param WC_Order $o Orden.
	 * @return array
	 */
	private static function order_row( $o ) {
		$cust = $o->get_meta( '_keytill_customer_name' );
		if ( '' === $cust ) {
			$cust = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		}
		return array(
			'id'            => (int) $o->get_id(),
			'number'        => $o->get_order_number(),
			'date'          => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
			'ts'            => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0,
			'cashier'       => $o->get_meta( '_keytill_cajero_login' ),
			'customer'      => $cust,
			'total'         => (float) $o->get_total(),
			'total_f'       => html_entity_decode( wp_strip_all_tags( wc_price( $o->get_total(), array( 'currency' => $o->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			'payment_title' => $o->get_payment_method_title(),
			'status'        => $o->get_status(),
			'status_label'  => wc_get_order_status_name( $o->get_status() ),
			'origin'        => $o->get_meta( '_keytill' ) ? 'pos' : 'web',
			'items_count'   => (int) $o->get_item_count(),
			'refunded'      => (float) $o->get_total_refunded(),
		);
	}

	/**
	 * Datos para poblar los filtros del listado (cajeros, medios, estados).
	 *
	 * @return WP_REST_Response
	 */
	public static function orders_filters() {
		$cashiers = array();
		foreach ( get_users( array( 'role__in' => array( 'cajero', 'encargado', 'administrator' ), 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $u ) {
			$cashiers[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name ? $u->display_name : $u->user_login );
		}

		$methods = array();
		foreach ( self::payment_methods() as $id => $label ) {
			$methods[] = array( 'id' => $id, 'label' => $label );
		}
		$methods[] = array( 'id' => 'credito', 'label' => __( 'Store credit', 'keytill' ) );

		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$statuses[] = array( 'id' => 'wc-' === substr( $key, 0, 3 ) ? substr( $key, 3 ) : $key, 'label' => $label );
		}

		return new WP_REST_Response(
			array( 'cashiers' => $cashiers, 'payment_methods' => $methods, 'statuses' => $statuses ),
			200
		);
	}

	/**
	 * Detalle completo de un pedido para el panel de la consola.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function order_detail( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'keytill_not_found', __( 'The order was not found (order).', 'keytill' ), array( 'status' => 404 ) );
		}

		$currency = $order->get_currency();
		$fmt      = function ( $n ) use ( $currency ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $n, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		};

		$subtotal   = 0.0;
		$sum_totals = 0.0;
		$lines      = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$subtotal   += (float) $item->get_subtotal();
			$sum_totals += (float) $item->get_total();
			$attrs       = wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ', 'autop' => false ) );
			$lines[]     = array(
				'name'    => $item->get_name(),
				'attrs'   => wp_strip_all_tags( $attrs ),
				'qty'     => (int) $item->get_quantity(),
				'total_f' => $fmt( $item->get_total() ),
			);
		}
		$total    = (float) $order->get_total();
		$discount = round( $subtotal - $sum_totals, 2 );

		$cust_name = $order->get_meta( '_keytill_customer_name' );
		if ( '' === $cust_name ) {
			$cust_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		return new WP_REST_Response(
			array(
				'id'            => (int) $order->get_id(),
				'number'        => $order->get_order_number(),
				'date'          => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
				'status'        => $order->get_status(),
				'status_label'  => wc_get_order_status_name( $order->get_status() ),
				'origin'        => $order->get_meta( '_keytill' ) ? 'pos' : 'web',
				'cashier'       => $order->get_meta( '_keytill_cajero_login' ),
				'seller'        => $order->get_meta( '_keytill_seller' ),
				'customer'      => array(
					'id'    => (int) $order->get_customer_id(),
					'name'  => $cust_name,
					'doc'   => $order->get_meta( '_keytill_customer_doc' ),
					'iva'   => $order->get_meta( '_keytill_customer_iva' ),
					'email' => $order->get_billing_email(),
				),
				'lines'         => $lines,
				'subtotal_f'    => $fmt( $subtotal ),
				'discount'      => $discount,
				'discount_f'    => $fmt( $discount ),
				'total_f'       => $fmt( $total ),
				'refunded'      => (float) $order->get_total_refunded(),
				'refunded_f'    => $fmt( $order->get_total_refunded() ),
				'payment_title' => $order->get_payment_method_title(),
				'credit_used'   => (float) $order->get_meta( '_keytill_credit_used' ),
				'credit_used_f' => $fmt( (float) $order->get_meta( '_keytill_credit_used' ) ),
				'credit_code'   => $order->get_meta( '_keytill_credit_code' ),
				'tendered_f'    => '' !== (string) $order->get_meta( '_keytill_tendered' ) ? $fmt( (float) $order->get_meta( '_keytill_tendered' ) ) : '',
				'change_f'      => '' !== (string) $order->get_meta( '_keytill_change' ) ? $fmt( (float) $order->get_meta( '_keytill_change' ) ) : '',
				'giftcards'     => KEYTILL_Credit_Product::codes_for_order( $order ),
				'print_url'     => KEYTILL_Print::print_url( $order->get_id() ),
				'gift_url'      => KEYTILL_Print::gift_url( $order->get_id() ),
				'refundable'    => ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'cancelled', 'failed', 'checkout-draft', 'auto-draft', 'trash' ), true ),
			),
			200
		);
	}

	/* ------------------------------------------------------------------ *
	 * Consola · Productos (listado / detalle / edición rápida / alta)
	 * ------------------------------------------------------------------ */

	/**
	 * Lista paginada de productos (padres) con filtros. Permiso: operate (ver).
	 *
	 * Query: page, per_page, search, status (active|inactive|any),
	 * stock (instock|outofstock|any), category (slug).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_products_list( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;

		// Estados permitidos según el filtro "Estado".
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( 'active' === $status ) {
			$allowed = array( 'publish' );
		} elseif ( 'inactive' === $status ) {
			$allowed = array( 'draft', 'private', 'pending' );
		} else {
			$allowed = array( 'publish', 'draft', 'private', 'pending' );
		}
		$stock = sanitize_key( (string) $request->get_param( 'stock' ) );
		$stock = in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock : '';

		$category = sanitize_title( (string) $request->get_param( 'category' ) );
		$search   = trim( (string) $request->get_param( 'search' ) );

		// Búsqueda: resolvemos ids (nombre + SKU, incl. SKU de variación → padre) y
		// cargamos DIRECTO por id, filtrando/paginando en PHP. NO usamos el `include`
		// de wc_get_products: en sitios con plugins de búsqueda/filtros de productos
		// (FiboSearch, Woo Product Filter, etc.) esos plugins hookean la query
		// (pre_get_posts / post__in) y devuelven vacío aunque el id exista.
		if ( '' !== $search ) {
			$matched = array();
			foreach ( self::search_product_ids( $search ) as $mid ) {
				$p = wc_get_product( $mid );
				if ( ! $p instanceof WC_Product || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
					continue;
				}
				if ( ! in_array( $p->get_status(), $allowed, true ) ) {
					continue;
				}
				if ( '' !== $stock && $p->get_stock_status() !== $stock ) {
					continue;
				}
				if ( '' !== $category && ! has_term( $category, 'product_cat', $p->get_id() ) ) {
					continue;
				}
				$matched[ $p->get_id() ] = $p;
			}
			$matched = array_values( $matched );
			usort(
				$matched,
				function ( $a, $b ) {
					return strcasecmp( $a->get_name(), $b->get_name() );
				}
			);
			$total = count( $matched );
			$pages = max( 1, (int) ceil( $total / $per_page ) );
			$slice = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );
			$items = array();
			foreach ( $slice as $p ) {
				$items[] = self::admin_product_row( $p );
			}
			return new WP_REST_Response(
				array( 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per_page ),
				200
			);
		}

		// Sin búsqueda: listado paginado normal de Woo.
		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'title',
			'order'    => 'ASC',
			'type'     => array( 'simple', 'variable' ),
			'status'   => $allowed,
		);
		if ( '' !== $stock ) {
			$args['stock_status'] = $stock;
		}
		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}

		$result   = wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );
		$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		$items = array();
		foreach ( $products as $p ) {
			if ( $p instanceof WC_Product ) {
				$items[] = self::admin_product_row( $p );
			}
		}

		return new WP_REST_Response(
			array( 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per_page ),
			200
		);
	}

	/**
	 * Asigna (o quita) la sucursal a varios productos de una. Permiso: manage.
	 *
	 * Origen de los productos:
	 *  - `ids`: array de ids de producto (los tildados en la lista), o
	 *  - `all` = true: TODOS los que matcheen el filtro actual (search + category
	 *    + status + stock), sin paginar.
	 *
	 * `branch_id` vacío = quitar la sucursal. La sucursal se guarda en el padre;
	 * las variaciones no se tocan (heredan la del padre).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Mueve stock de una sucursal a otra para un producto/variación. Transferencia
	 * atómica: el total no cambia. Devuelve la fila actualizada (con branch_stock).
	 */
	public static function admin_product_move_stock( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'keytill_not_found', __( 'Product not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$params = self::body( $request );
		$from   = isset( $params['from'] ) ? (string) $params['from'] : '';
		$to     = isset( $params['to'] ) ? (string) $params['to'] : '';
		$qty    = isset( $params['qty'] ) ? (int) $params['qty'] : 0;

		$res = KEYTILL_Branch_Stock::move( $id, $from, $to, $qty );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$product = wc_get_product( $id );
		$row     = ( $product instanceof WC_Product_Variation ) ? self::admin_variation_row( $product ) : self::admin_product_row( $product );
		return new WP_REST_Response( $row, 200 );
	}

	public static function admin_products_bulk_branch( WP_REST_Request $request ) {
		$params     = self::body( $request );
		$branch_raw = isset( $params['branch_id'] ) ? (string) $params['branch_id'] : '';
		$clear      = ( '' === trim( $branch_raw ) );
		$branch     = $clear ? '' : KEYTILL_Branches::valid_id( $branch_raw );
		if ( ! $clear && '' === $branch ) {
			return new WP_Error( 'keytill_bad_branch', __( 'Invalid location.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Ids: explícitos (tildados) o resueltos por el filtro actual.
		$ids = array();
		if ( ! empty( $params['all'] ) ) {
			$ids = self::filtered_parent_ids( $params );
		} elseif ( isset( $params['ids'] ) && is_array( $params['ids'] ) ) {
			foreach ( $params['ids'] as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$ids[ $id ] = $id;
				}
			}
			$ids = array_values( $ids );
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'keytill_no_ids', __( 'You did not select any product.', 'keytill' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 5000 ) {
			$ids = array_slice( $ids, 0, 5000 ); // tope de seguridad.
		}

		// La sucursal vive en cada ítem (producto simple o variación). Un producto
		// variable no tiene sucursal propia: se aplica a TODAS sus variaciones (así
		// "todos del filtro" abarca cada variación de cada variable).
		$targets = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p instanceof WC_Product ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $vid ) {
					$targets[ (int) $vid ] = (int) $vid;
				}
			} else {
				$targets[ (int) $id ] = (int) $id; // simple o variación.
			}
		}

		$updated = 0;
		foreach ( $targets as $tid ) {
			$t = wc_get_product( $tid );
			if ( ! $t instanceof WC_Product ) {
				continue;
			}
			if ( $clear ) {
				$t->delete_meta_data( KEYTILL_Branches::META );
			} else {
				$t->update_meta_data( KEYTILL_Branches::META, $branch );
			}
			$t->save(); // dispara woocommerce_update_product → invalida el caché del catálogo.
			$updated++;
		}

		return new WP_REST_Response(
			array(
				'updated' => $updated,
				'branch'  => $clear ? '' : KEYTILL_Branches::name_of( $branch ),
			),
			200
		);
	}

	/**
	 * Resuelve TODOS los ids de producto padre (simple/variable) que matchean un
	 * filtro (search + category + status + stock), sin paginar. Reusa la misma
	 * lógica que el listado de la consola.
	 *
	 * @param array $params Filtro (search, category, status, stock).
	 * @return int[]
	 */
	private static function filtered_parent_ids( $params ) {
		$status = sanitize_key( isset( $params['status'] ) ? (string) $params['status'] : '' );
		if ( 'active' === $status ) {
			$allowed = array( 'publish' );
		} elseif ( 'inactive' === $status ) {
			$allowed = array( 'draft', 'private', 'pending' );
		} else {
			$allowed = array( 'publish', 'draft', 'private', 'pending' );
		}
		$stock = sanitize_key( isset( $params['stock'] ) ? (string) $params['stock'] : '' );
		$stock = in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock : '';
		$category = sanitize_title( isset( $params['category'] ) ? (string) $params['category'] : '' );
		$search   = trim( isset( $params['search'] ) ? (string) $params['search'] : '' );

		if ( '' !== $search ) {
			$ids = array();
			foreach ( self::search_product_ids( $search ) as $mid ) {
				$p = wc_get_product( $mid );
				if ( ! $p instanceof WC_Product || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
					continue;
				}
				if ( ! in_array( $p->get_status(), $allowed, true ) ) {
					continue;
				}
				if ( '' !== $stock && $p->get_stock_status() !== $stock ) {
					continue;
				}
				if ( '' !== $category && ! has_term( $category, 'product_cat', $p->get_id() ) ) {
					continue;
				}
				$ids[ $p->get_id() ] = (int) $p->get_id();
			}
			return array_values( $ids );
		}

		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'type'   => array( 'simple', 'variable' ),
			'status' => $allowed,
		);
		if ( '' !== $stock ) {
			$args['stock_status'] = $stock;
		}
		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}
		$res = wc_get_products( $args );
		return is_array( $res ) ? array_map( 'absint', $res ) : array();
	}

	/**
	 * Datos para poblar los filtros/alta de productos (categorías).
	 *
	 * @return WP_REST_Response
	 */
	public static function admin_products_meta() {
		$cats = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$cats[] = array( 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count );
			}
		}
		return new WP_REST_Response(
			array( 'categories' => $cats, 'can_manage' => current_user_can( KEYTILL_CAP_MANAGE ) ),
			200
		);
	}

	/**
	 * Detalle de un producto: sus datos + variaciones (si es variable).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_product_detail( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request->get_param( 'id' ) ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'keytill_not_found', __( 'Product not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$row               = self::admin_product_row( $product );
		$row['variations'] = self::admin_variations( $product );

		// Atributos usados en variación (taxonomías globales) + sus términos
		// elegidos, para prellenar el editor de un producto variable.
		$row['variation_attributes'] = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_attributes() as $key => $attr ) {
				$taxonomy = (string) $key;
				if ( ! $attr->get_variation() || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$term_ids = array();
				foreach ( (array) $attr->get_options() as $tid ) {
					$term = get_term( (int) $tid, $taxonomy );
					if ( $term instanceof WP_Term ) {
						$term_ids[] = (int) $term->term_id;
					}
				}
				$tax_obj                       = self::get_attribute_taxonomy( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$row['variation_attributes'][] = array(
					'taxonomy' => $taxonomy,
					'id'       => wc_attribute_taxonomy_id_by_name( $taxonomy ),
					'name'     => $tax_obj ? ( $tax_obj->attribute_label ? $tax_obj->attribute_label : $tax_obj->attribute_name ) : $taxonomy,
					'term_ids' => $term_ids,
				);
			}
		}

		// Campos completos para el editor (no van en la fila del listado por peso).
		$cat_ids            = $product->get_category_ids();
		$img_id             = (int) $product->get_image_id();
		$row['category_id'] = ! empty( $cat_ids ) ? (int) $cat_ids[0] : 0;
		$row['description']       = $product->get_description();
		$row['short_description'] = $product->get_short_description();
		$row['image_id']          = $img_id;
		$row['image_full']        = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';

		// Galería del producto (attachments extra, además de la imagen principal).
		$row['gallery'] = array();
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$gid = (int) $gid;
			$row['gallery'][] = array(
				'id'    => $gid,
				'thumb' => wp_get_attachment_image_url( $gid, 'thumbnail' ),
				'url'   => wp_get_attachment_image_url( $gid, 'medium' ),
			);
		}

		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * Resuelve un término de búsqueda a ids de producto **padre**, matcheando por
	 * nombre y por SKU (exacto y parcial, tanto de productos simples como de
	 * variaciones — la variación se mapea a su padre para el listado).
	 *
	 * @param string $search Término.
	 * @return int[] Ids de producto padre, únicos.
	 */
	private static function search_product_ids( $search ) {
		$ids = array();

		// 1) SKU exacto (producto o variación → padre).
		$exact = wc_get_product_id_by_sku( $search );
		if ( $exact ) {
			$ids[] = self::product_parent_id( $exact );
		}

		// 2) SKU parcial de productos simples/variables (LIKE sobre _sku).
		$by_sku = wc_get_products(
			array(
				'sku'    => $search,
				'limit'  => 50,
				'return' => 'ids',
				'status' => array( 'publish', 'draft', 'private', 'pending' ),
				'type'   => array( 'simple', 'variable' ),
			)
		);
		foreach ( (array) $by_sku as $pid ) {
			$ids[] = (int) $pid;
		}

		// 3) SKU parcial de variaciones → producto padre.
		$var_q = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_sku', 'value' => $search, 'compare' => 'LIKE' ),
				),
			)
		);
		foreach ( (array) $var_q->posts as $vid ) {
			$ids[] = self::product_parent_id( (int) $vid );
		}

		// 4) Nombre (título). Se consulta con un LIKE preparado directo en vez de la
		// búsqueda 's' de WP_Query: en sitios con plugins de búsqueda/filtros de
		// productos (FiboSearch, Woo Product Filter, etc.) esos plugins interceptan
		// el 's' y rompen el resultado (la búsqueda del listado devolvía vacío).
		// El LIKE es determinista e inmune a esos filtros.
		global $wpdb;
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$by_title = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				   AND post_status IN ( 'publish', 'draft', 'private', 'pending' )
				   AND post_title LIKE %s
				 ORDER BY post_title ASC
				 LIMIT 50",
				$like
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE preparado, a propósito inmune a plugins de búsqueda.
		foreach ( (array) $by_title as $pid ) {
			$ids[] = (int) $pid;
		}

		// 5) Código de barras (_keytill_barcode) en productos y variaciones → padre.
		// Se resuelve con un LIKE preparado directo sobre postmeta, por el mismo
		// motivo que el nombre: inmune a plugins que hookean la query de productos.
		$by_barcode = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				 WHERE p.post_type IN ( 'product', 'product_variation' )
				   AND m.meta_key = '_keytill_barcode'
				   AND m.meta_value LIKE %s
				 LIMIT 50",
				$like
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE preparado, a propósito inmune a plugins de búsqueda.
		foreach ( (array) $by_barcode as $pid ) {
			$ids[] = self::product_parent_id( (int) $pid );
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Busca el id de una orden por su clave de idempotencia acotada a un cajero.
	 * Storage-agnóstico: consulta DIRECTO la tabla de meta que corresponda según
	 * esté HPOS activo (wc_orders_meta) o no (postmeta legacy). Se hace así porque
	 * wc_get_orders + meta_query no es fiable con el store legacy (lo ignora).
	 *
	 * @param string $idem      Clave de idempotencia.
	 * @param int    $cajero_id Id del cajero (usuario actual).
	 * @return int Id de la orden o 0 si no hay coincidencia.
	 */
	private static function find_order_id_by_idem( $idem, $cajero_id ) {
		global $wpdb;

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$meta = $wpdb->prefix . 'wc_orders_meta';
			$id   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT k.order_id FROM {$meta} k
					 INNER JOIN {$meta} c ON c.order_id = k.order_id
					 WHERE k.meta_key = '_keytill_idempotency_key' AND k.meta_value = %s
					   AND c.meta_key = '_keytill_cajero_id' AND c.meta_value = %s
					 ORDER BY k.order_id DESC LIMIT 1",
					$idem,
					(string) $cajero_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lookup por clave única, storage-agnóstico a propósito.
		} else {
			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT k.post_id FROM {$wpdb->postmeta} k
					 INNER JOIN {$wpdb->postmeta} c ON c.post_id = k.post_id
					 WHERE k.meta_key = '_keytill_idempotency_key' AND k.meta_value = %s
					   AND c.meta_key = '_keytill_cajero_id' AND c.meta_value = %s
					 ORDER BY k.post_id DESC LIMIT 1",
					$idem,
					(string) $cajero_id
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lookup por clave única, storage-agnóstico a propósito.
		}

		return (int) $id;
	}

	/**
	 * Id del producto padre: si el id es una variación devuelve su padre; si no,
	 * el mismo id. 0 si no existe.
	 *
	 * @param int $id Id de producto o variación.
	 * @return int
	 */
	private static function product_parent_id( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}
		return $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	}

	/**
	 * Variaciones editables de un producto variable (vacío si no lo es).
	 *
	 * @param WC_Product $product Producto padre.
	 * @return array[]
	 */
	private static function admin_variations( $product ) {
		$out = array();
		if ( ! $product->is_type( 'variable' ) ) {
			return $out;
		}
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v instanceof WC_Product_Variation ) {
				$out[] = self::admin_variation_row( $v );
			}
		}
		return $out;
	}

	/**
	 * Fila de un producto (padre) para el listado/edición.
	 *
	 * @param WC_Product $p Producto.
	 * @return array
	 */
	private static function admin_product_row( $p ) {
		$cat_ids = $p->get_category_ids();
		$cat     = '';
		if ( ! empty( $cat_ids ) ) {
			$term = get_term( $cat_ids[0], 'product_cat' );
			$cat  = ( $term instanceof WP_Term ) ? $term->name : '';
		}
		$type       = $p->is_type( 'variable' ) ? 'variable' : 'simple';
		$var_count  = $p->is_type( 'variable' ) ? count( $p->get_children() ) : 0;
		$price_disp = html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' );
		$img_id     = (int) $p->get_image_id();

		return array(
			'id'            => (int) $p->get_id(),
			'name'          => $p->get_name(),
			'sku'           => $p->get_sku(),
			'type'          => $type,
			'category'      => $cat,
			'active'        => ( 'publish' === $p->get_status() ),
			'status'        => $p->get_status(),
			'giftcard'      => KEYTILL_Credit_Product::is_giftcard( $p ),
			// Edición rápida (solo simples; los variables se editan por variación).
			'editable'      => ( 'simple' === $type ),
			'regular_price' => 'simple' === $type ? (string) $p->get_regular_price() : '',
			'sale_price'    => 'simple' === $type ? (string) $p->get_sale_price() : '',
			'barcode'       => 'simple' === $type ? (string) $p->get_meta( '_keytill_barcode' ) : '',
			'label_url'     => KEYTILL_Labels::url( $p->get_id() ),
			'price_html'    => $price_disp,
			'stock_qty'     => ( 'simple' === $type && null !== $p->get_stock_quantity() ) ? (int) $p->get_stock_quantity() : null,
			'manage_stock'  => 'simple' === $type ? (bool) $p->get_manage_stock() : false,
			'in_stock'      => $p->is_in_stock(),
			'variations'    => $var_count,
			'image'         => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
			'branch_id'     => (string) $p->get_meta( KEYTILL_Branches::META ),
			'branch'        => KEYTILL_Branches::name_of( $p->get_meta( KEYTILL_Branches::META ) ),
			// Stock por sucursal (solo simples; los variables lo llevan por variación).
			// branch_managed=false → "legacy": todavía usa el stock único de Woo.
			'branch_stock'   => ( 'simple' === $type ) ? KEYTILL_Branch_Stock::map_for_active( $p->get_id() ) : array(),
			'stock_total'    => ( 'simple' === $type ) ? KEYTILL_Branch_Stock::total( $p->get_id() ) : null,
			'branch_managed' => ( 'simple' === $type ) ? KEYTILL_Branch_Stock::is_managed( $p->get_id() ) : false,
		);
	}

	/**
	 * Fila de una variación para edición rápida.
	 *
	 * @param WC_Product_Variation $v Variación.
	 * @return array
	 */
	private static function admin_variation_row( $v ) {
		return array(
			'id'            => (int) $v->get_id(),
			'label'         => self::variation_label( $v ),
			'sku'           => $v->get_sku( 'edit' ), // SKU propio (vacío si hereda el del padre).
			'barcode'       => (string) $v->get_meta( '_keytill_barcode' ),
			'label_url'     => KEYTILL_Labels::url( $v->get_id() ),
			'regular_price' => (string) $v->get_regular_price(),
			'stock_qty'     => null !== $v->get_stock_quantity() ? (int) $v->get_stock_quantity() : null,
			'manage_stock'  => (bool) $v->get_manage_stock(),
			'in_stock'      => $v->is_in_stock(),
			'is_variation'  => true,
			'branch_id'     => (string) $v->get_meta( KEYTILL_Branches::META ),
			'branch'        => KEYTILL_Branches::name_of( $v->get_meta( KEYTILL_Branches::META ) ),
			'branch_stock'   => KEYTILL_Branch_Stock::map_for_active( $v->get_id() ),
			'stock_total'    => KEYTILL_Branch_Stock::total( $v->get_id() ),
			'branch_managed' => KEYTILL_Branch_Stock::is_managed( $v->get_id() ),
		);
	}

	/**
	 * Lista los atributos globales (pa_*) del sitio con sus términos, para el
	 * editor de variables. **Solo lectura**: levanta lo que ya existe en el
	 * sitio (no crea ni modifica nada). Permiso: operate.
	 *
	 * @return WP_REST_Response
	 */
	public static function admin_attributes_list() {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name ); // 'pa_talle'
			$terms    = array();
			if ( taxonomy_exists( $taxonomy ) ) {
				$found = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
				if ( ! is_wp_error( $found ) ) {
					foreach ( $found as $t ) {
						$terms[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
					}
				}
			}
			$out[] = array(
				'id'       => (int) $tax->attribute_id,
				'name'     => $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name,
				'slug'     => $tax->attribute_name,
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
			);
		}
		return new WP_REST_Response( array( 'attributes' => $out ), 200 );
	}

	/**
	 * Crea un atributo global (pa_*) reutilizable. Aditivo: si ya existe uno con
	 * el mismo slug, lo devuelve (no duplica). Permiso: manage.
	 *
	 * Body: { name }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_attribute_create( WP_REST_Request $request ) {
		$params = self::body( $request );
		$name   = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'keytill_bad_attr', __( 'The attribute name is required.', 'keytill' ), array( 'status' => 400 ) );
		}

		$slug = wc_sanitize_taxonomy_name( $name ); // sin prefijo pa_, minúsculas.
		if ( '' === $slug ) {
			return new WP_Error( 'keytill_bad_attr', __( 'Invalid attribute name.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Dedup: si ya existe un atributo global con ese slug, se reutiliza.
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			if ( $tax->attribute_name === $slug ) {
				return self::attribute_payload( (int) $tax->attribute_id );
			}
		}

		$attr_id = wc_create_attribute(
			array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		if ( is_wp_error( $attr_id ) ) {
			return new WP_Error( 'keytill_attr_fail', $attr_id->get_error_message(), array( 'status' => 400 ) );
		}

		// Registra la taxonomía en esta misma request para poder usarla ya (WC la
		// registra en `init`, que en una request REST ya pasó).
		self::register_attribute_taxonomy( $slug );

		return self::attribute_payload( (int) $attr_id, 201 );
	}

	/**
	 * Crea un término en un atributo global. Aditivo + dedup por slug. Permiso: manage.
	 *
	 * Body: { name }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_attribute_term_create( WP_REST_Request $request ) {
		$attr_id = absint( $request->get_param( 'id' ) );
		$tax_obj = self::get_attribute_taxonomy( $attr_id );
		if ( ! $tax_obj ) {
			return new WP_Error( 'keytill_not_found', __( 'Attribute not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$taxonomy = wc_attribute_taxonomy_name( $tax_obj->attribute_name );
		self::register_attribute_taxonomy( $tax_obj->attribute_name );

		$params = self::body( $request );
		$name   = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'keytill_bad_term', __( 'The value name is required.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Dedup: por nombre o por slug ya existente.
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( ! $existing ) {
			$existing = get_term_by( 'slug', wc_sanitize_taxonomy_name( $name ), $taxonomy );
		}
		if ( $existing instanceof WP_Term ) {
			return new WP_REST_Response(
				array( 'id' => (int) $existing->term_id, 'name' => $existing->name, 'slug' => $existing->slug ),
				200
			);
		}

		$res = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'keytill_term_fail', $res->get_error_message(), array( 'status' => 400 ) );
		}
		$term = get_term( (int) $res['term_id'], $taxonomy );
		return new WP_REST_Response(
			array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ),
			201
		);
	}

	/**
	 * Payload de un atributo global por id (con sus términos).
	 *
	 * @param int $attr_id Id del atributo global.
	 * @param int $status  Código HTTP.
	 * @return WP_REST_Response
	 */
	private static function attribute_payload( $attr_id, $status = 200 ) {
		$tax_obj  = self::get_attribute_taxonomy( $attr_id );
		$taxonomy = $tax_obj ? wc_attribute_taxonomy_name( $tax_obj->attribute_name ) : '';
		$terms    = array();
		if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
			$found = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
			if ( ! is_wp_error( $found ) ) {
				foreach ( $found as $t ) {
					$terms[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
				}
			}
		}
		return new WP_REST_Response(
			array(
				'id'       => (int) $attr_id,
				'name'     => $tax_obj ? ( $tax_obj->attribute_label ? $tax_obj->attribute_label : $tax_obj->attribute_name ) : '',
				'slug'     => $tax_obj ? $tax_obj->attribute_name : '',
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
			),
			$status
		);
	}

	/**
	 * Objeto de taxonomía de atributo global por id (o null).
	 *
	 * @param int $attr_id Id.
	 * @return object|null
	 */
	private static function get_attribute_taxonomy( $attr_id ) {
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			if ( (int) $tax->attribute_id === (int) $attr_id ) {
				return $tax;
			}
		}
		return null;
	}

	/**
	 * Registra la taxonomía de un atributo global en la request actual, para
	 * poder insertar términos/asignarlo sin esperar al próximo `init`.
	 *
	 * @param string $slug Slug del atributo (sin prefijo pa_).
	 */
	private static function register_attribute_taxonomy( $slug ) {
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
				apply_filters(
					'woocommerce_taxonomy_args_' . $taxonomy,
					array( 'hierarchical' => true, 'show_ui' => false, 'query_var' => true, 'rewrite' => false )
				)
			);
		}
	}


	/**
	 * Edición rápida de un producto o variación: precio / stock / activo.
	 * Permiso: manage. status ('publish'|'draft') solo aplica a productos padre.
	 *
	 * Body: { regular_price?, stock_quantity?, status? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_product_update( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request->get_param( 'id' ) ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'keytill_not_found', __( 'Product not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$params      = self::body( $request );
		$is_variation = $product instanceof WC_Product_Variation;

		// Precio de lista.
		if ( array_key_exists( 'regular_price', $params ) ) {
			$raw = $params['regular_price'];
			if ( '' === $raw || null === $raw ) {
				$product->set_regular_price( '' );
			} else {
				$price = wc_format_decimal( $raw );
				if ( '' === $price || (float) $price < 0 ) {
					return new WP_Error( 'keytill_bad_price', __( 'Invalid price.', 'keytill' ), array( 'status' => 400 ) );
				}
				$product->set_regular_price( $price );
				// Sin oferta activa, el precio efectivo sigue al de lista.
				if ( '' === (string) $product->get_sale_price() ) {
					$product->set_price( $price );
				}
			}
		}

		// Stock. '' o null => deja de gestionar stock; número => gestiona y fija.
		if ( array_key_exists( 'stock_quantity', $params ) ) {
			$raw = $params['stock_quantity'];
			if ( '' === $raw || null === $raw ) {
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
			} else {
				$qty = (int) $raw;
				if ( $qty < 0 ) {
					return new WP_Error( 'keytill_bad_stock', __( 'Invalid stock.', 'keytill' ), array( 'status' => 400 ) );
				}
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $qty );
				if ( ! $product->backorders_allowed() ) {
					$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
				}
			}
		}

		// SKU de una variación (el del padre se maneja en el bloque de abajo).
		if ( array_key_exists( 'sku', $params ) && $is_variation ) {
			$sku = wc_clean( $params['sku'] );
			if ( '' !== $sku ) {
				$existing = wc_get_product_id_by_sku( $sku );
				if ( $existing && (int) $existing !== (int) $product->get_id() ) {
					return new WP_Error( 'keytill_dup_sku', __( 'Another product already uses that SKU.', 'keytill' ), array( 'status' => 409 ) );
				}
			}
			$product->set_sku( $sku );
		}

		// Precio de oferta (solo productos padre). '' => sin oferta.
		if ( array_key_exists( 'sale_price', $params ) && ! $is_variation ) {
			$raw = $params['sale_price'];
			if ( '' === $raw || null === $raw ) {
				$product->set_sale_price( '' );
				$reg = (string) $product->get_regular_price();
				if ( '' !== $reg ) {
					$product->set_price( $reg );
				}
			} else {
				$sale = wc_format_decimal( $raw );
				if ( '' === $sale || (float) $sale < 0 ) {
					return new WP_Error( 'keytill_bad_price', __( 'Invalid sale price.', 'keytill' ), array( 'status' => 400 ) );
				}
				$product->set_sale_price( $sale );
				$product->set_price( $sale );
			}
		}

		// Activo (solo productos padre): publish vs draft.
		if ( array_key_exists( 'status', $params ) && ! $is_variation ) {
			$new = sanitize_key( (string) $params['status'] );
			if ( in_array( $new, array( 'publish', 'draft' ), true ) ) {
				$product->set_status( $new );
			}
		}

		// Campos del editor completo (solo productos padre).
		if ( ! $is_variation ) {
			if ( array_key_exists( 'name', $params ) ) {
				$name = sanitize_text_field( $params['name'] );
				if ( '' === $name ) {
					return new WP_Error( 'keytill_bad_name', __( 'The name is required.', 'keytill' ), array( 'status' => 400 ) );
				}
				$product->set_name( $name );
			}
			if ( array_key_exists( 'sku', $params ) ) {
				$sku = wc_clean( $params['sku'] );
				if ( '' !== $sku ) {
					$existing = wc_get_product_id_by_sku( $sku );
					if ( $existing && (int) $existing !== (int) $product->get_id() ) {
						return new WP_Error( 'keytill_dup_sku', __( 'Another product already uses that SKU.', 'keytill' ), array( 'status' => 409 ) );
					}
				}
				$product->set_sku( $sku );
			}
			if ( array_key_exists( 'category_id', $params ) ) {
				$cat = absint( $params['category_id'] );
				$product->set_category_ids( ( $cat && term_exists( $cat, 'product_cat' ) ) ? array( $cat ) : array() );
			}
			if ( array_key_exists( 'description', $params ) ) {
				$product->set_description( wp_kses_post( (string) $params['description'] ) );
			}
			if ( array_key_exists( 'short_description', $params ) ) {
				$product->set_short_description( wp_kses_post( (string) $params['short_description'] ) );
			}
			if ( array_key_exists( 'image_id', $params ) ) {
				$iid = absint( $params['image_id'] );
				$product->set_image_id( $iid ? $iid : '' );
			}
			if ( array_key_exists( 'gallery_ids', $params ) ) {
				$product->set_gallery_image_ids( self::sanitize_gallery_ids( $params['gallery_ids'] ) );
			}
		}

		// Sucursal (informativa): aplica a cualquier ítem (simple o variación).
		// '' la limpia; si no, debe existir en la lista.
		if ( array_key_exists( 'branch_id', $params ) ) {
			$branch = KEYTILL_Branches::valid_id( $params['branch_id'] );
			if ( '' === $branch ) {
				$product->delete_meta_data( KEYTILL_Branches::META );
			} else {
				$product->update_meta_data( KEYTILL_Branches::META, $branch );
			}
		}

		// Código de barras (simples y variaciones). Vacío lo limpia; si no, 12
		// dígitos y único en todo el catálogo (para que el escaneo sea inequívoco).
		if ( array_key_exists( 'barcode', $params ) ) {
			$code = preg_replace( '/\D/', '', (string) $params['barcode'] );
			if ( '' === $code ) {
				$product->delete_meta_data( '_keytill_barcode' );
			} elseif ( 12 !== strlen( $code ) ) {
				return new WP_Error( 'keytill_bad_barcode', __( 'The barcode must be 12 digits.', 'keytill' ), array( 'status' => 400 ) );
			} elseif ( self::barcode_in_use( $code, (int) $product->get_id() ) ) {
				return new WP_Error( 'keytill_dup_barcode', __( 'Another product already uses that barcode.', 'keytill' ), array( 'status' => 409 ) );
			} else {
				$product->update_meta_data( '_keytill_barcode', $code );
			}
		}

		try {
			$product->save();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( 'keytill_save_fail', $e->getMessage(), array( 'status' => 400 ) );
		}

		// Stock por sucursal: si vienen cantidades por sucursal, son la fuente de
		// verdad y el `_stock` nativo pasa a ser la suma (set_bulk lo sincroniza).
		// Va DESPUÉS del save del producto para que este no pise el total nativo.
		if ( isset( $params['branch_stock'] ) && is_array( $params['branch_stock'] ) ) {
			$bs = array();
			foreach ( $params['branch_stock'] as $bid => $qty ) {
				$bs[ (string) $bid ] = max( 0, (int) $qty );
			}
			KEYTILL_Branch_Stock::set_bulk( $product->get_id(), $bs );
		}

		// Una variación puede cambiar el rango de precio/stock del padre.
		$product = wc_get_product( $product->get_id() );
		$row     = $is_variation ? self::admin_variation_row( $product ) : self::admin_product_row( $product );
		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * ¿El código de barras ya está en uso por otro producto/variación?
	 * (Los productos son posts incluso con HPOS, así que la meta_query es fiable.)
	 *
	 * @param string $code       Código (12 dígitos).
	 * @param int    $exclude_id ID a excluir (el que se está editando).
	 * @return bool
	 */
	public static function barcode_in_use( $code, $exclude_id = 0 ) {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => $exclude_id ? array( (int) $exclude_id ) : array(),
				'meta_key'       => '_keytill_barcode',   // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $code,     // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'  => true,
			)
		);
		return ! empty( $ids );
	}

	/**
	 * Alta simple de producto (publicado). Permiso: manage.
	 * Imágenes, variaciones y categorías nuevas se gestionan en el backend.
	 *
	 * Body: { name, sku?, regular_price, stock_quantity?, category_id? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_product_create( WP_REST_Request $request ) {
		$params = self::body( $request );

		$name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'keytill_bad_name', __( 'The name is required.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Tipo: simple (default) o variable. Para variable el precio/stock van por
		// variación (no se piden acá); la grilla se genera luego con su endpoint.
		$type = ( isset( $params['type'] ) && 'variable' === $params['type'] ) ? 'variable' : 'simple';

		$sku = isset( $params['sku'] ) ? wc_clean( $params['sku'] ) : '';
		if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
			return new WP_Error( 'keytill_dup_sku', __( 'A product with that SKU already exists.', 'keytill' ), array( 'status' => 409 ) );
		}

		if ( 'variable' === $type ) {
			$product = new WC_Product_Variable();
		} else {
			$price = isset( $params['regular_price'] ) ? wc_format_decimal( $params['regular_price'] ) : '';
			if ( '' === $price || (float) $price < 0 ) {
				return new WP_Error( 'keytill_bad_price', __( 'The price is required.', 'keytill' ), array( 'status' => 400 ) );
			}
			$product = new WC_Product_Simple();
			$product->set_regular_price( $price );
			$product->set_price( $price );

			// Oferta (opcional): si es válida, el precio efectivo pasa a ser la oferta.
			if ( isset( $params['sale_price'] ) && '' !== $params['sale_price'] && null !== $params['sale_price'] ) {
				$sale = wc_format_decimal( $params['sale_price'] );
				if ( '' !== $sale && (float) $sale >= 0 ) {
					$product->set_sale_price( $sale );
					$product->set_price( $sale );
				}
			}

			if ( isset( $params['stock_quantity'] ) && '' !== $params['stock_quantity'] && null !== $params['stock_quantity'] ) {
				$qty = max( 0, (int) $params['stock_quantity'] );
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $qty );
				$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			}
		}

		$product->set_name( $name );
		$product->set_status( 'publish' );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}

		$cat_id = isset( $params['category_id'] ) ? absint( $params['category_id'] ) : 0;
		if ( $cat_id && term_exists( $cat_id, 'product_cat' ) ) {
			$product->set_category_ids( array( $cat_id ) );
		}

		if ( isset( $params['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $params['description'] ) );
		}
		if ( isset( $params['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $params['short_description'] ) );
		}
		$img_id = isset( $params['image_id'] ) ? absint( $params['image_id'] ) : 0;
		if ( $img_id ) {
			$product->set_image_id( $img_id );
		}
		if ( isset( $params['gallery_ids'] ) ) {
			$product->set_gallery_image_ids( self::sanitize_gallery_ids( $params['gallery_ids'] ) );
		}
		if ( isset( $params['branch_id'] ) ) {
			$branch = KEYTILL_Branches::valid_id( $params['branch_id'] );
			if ( '' !== $branch ) {
				$product->update_meta_data( KEYTILL_Branches::META, $branch );
			}
		}

		try {
			$id = $product->save();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( 'keytill_create_fail', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( ! $id ) {
			return new WP_Error( 'keytill_create_fail', __( 'The product could not be created.', 'keytill' ), array( 'status' => 500 ) );
		}

		// Stock por sucursal al crear un simple (fuente de verdad; el nativo = suma).
		if ( 'variable' !== $type && isset( $params['branch_stock'] ) && is_array( $params['branch_stock'] ) ) {
			$bs = array();
			foreach ( $params['branch_stock'] as $bid => $qty ) {
				$bs[ (string) $bid ] = max( 0, (int) $qty );
			}
			KEYTILL_Branch_Stock::set_bulk( $id, $bs );
		}

		return new WP_REST_Response( self::admin_product_row( wc_get_product( $id ) ), 201 );
	}

	/**
	 * Fija los atributos de variación de un producto variable y **genera** las
	 * variaciones faltantes (producto cartesiano de los términos elegidos).
	 * Permiso: manage.
	 *
	 * Es **aditivo y no destructivo**: agrega términos a los atributos (unión con
	 * los existentes) y crea solo las combinaciones que aún no existen; no borra
	 * variaciones ni toca los atributos globales compartidos.
	 *
	 * Body: { attributes: [ { taxonomy: 'pa_talle', term_ids: [..] }, ... ] }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_variations_generate( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request->get_param( 'id' ) ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'keytill_not_found', __( 'Product not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'keytill_not_variable', __( 'The product is not a variable product.', 'keytill' ), array( 'status' => 400 ) );
		}

		$params = self::body( $request );
		$raw    = isset( $params['attributes'] ) && is_array( $params['attributes'] ) ? $params['attributes'] : array();
		$prune  = ! empty( $params['prune'] ); // borrar variaciones huérfanas (confirmado en el front).

		// Taxonomías que llegan en el request: para éstas los valores del request
		// son autoritativos (permite QUITAR valores). Las taxonomías de variación
		// que ya tenía el padre y NO vienen en el request se conservan tal cual.
		$req_taxes = array();
		foreach ( $raw as $a ) {
			$t = isset( $a['taxonomy'] ) ? sanitize_key( $a['taxonomy'] ) : '';
			if ( '' !== $t ) {
				$req_taxes[ $t ] = true;
			}
		}

		// Por taxonomía juntamos term_ids (para las opciones del padre) y slugs
		// (para las combinaciones de las variaciones).
		$term_ids_by_tax = array(); // taxonomy => [ term_id => term_id ]
		$slugs_by_tax    = array(); // taxonomy => [ slug => slug ]
		$keep_attrs      = array(); // atributos no-variación (o no elegidos) que se conservan
		$order           = array();

		$existing_attrs = $product->get_attributes();
		foreach ( $existing_attrs as $key => $ex ) {
			$taxonomy = (string) $key;
			if ( $ex->get_variation() && taxonomy_exists( $taxonomy ) ) {
				if ( isset( $req_taxes[ $taxonomy ] ) ) {
					continue; // el request manda para esta taxonomía (puede quitar valores).
				}
				// Semilla con los términos previos de variación (taxonomía global):
				// las opciones son term_ids; resolvemos su slug.
				foreach ( (array) $ex->get_options() as $tid ) {
					$term = get_term( (int) $tid, $taxonomy );
					if ( $term instanceof WP_Term ) {
						$term_ids_by_tax[ $taxonomy ][ (int) $term->term_id ] = (int) $term->term_id;
						$slugs_by_tax[ $taxonomy ][ $term->slug ]            = $term->slug;
						$order[ $taxonomy ]                                  = true;
					}
				}
			} elseif ( ! $ex->get_variation() ) {
				$keep_attrs[ $key ] = $ex; // atributos informativos: se conservan tal cual.
			}
		}

		foreach ( $raw as $a ) {
			$taxonomy = isset( $a['taxonomy'] ) ? sanitize_key( $a['taxonomy'] ) : '';
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$term_ids = isset( $a['term_ids'] ) && is_array( $a['term_ids'] ) ? array_map( 'absint', $a['term_ids'] ) : array();
			foreach ( $term_ids as $tid ) {
				$term = get_term( $tid, $taxonomy );
				if ( $term instanceof WP_Term ) {
					$term_ids_by_tax[ $taxonomy ][ (int) $term->term_id ] = (int) $term->term_id;
					$slugs_by_tax[ $taxonomy ][ $term->slug ]            = $term->slug;
					$order[ $taxonomy ]                                  = true;
				}
			}
		}

		if ( empty( $slugs_by_tax ) ) {
			return new WP_Error( 'keytill_no_attrs', __( 'Choose at least one attribute with values.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Combinaciones deseadas (por slug, que es lo que usan las variaciones).
		$chosen = array();
		foreach ( $slugs_by_tax as $taxonomy => $slugs ) {
			$chosen[ $taxonomy ] = $slugs;
		}

		// Huérfanas: variaciones existentes con algún valor que ya no está elegido
		// (p. ej. se quitó un talle) o de una taxonomía de variación que se sacó.
		// Se detectan ANTES de tocar el padre, para poder pedir confirmación.
		$orphans     = array();
		$keep_sigs   = array(); // firmas de las variaciones que se conservan.
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v instanceof WC_Product_Variation ) {
				continue;
			}
			$vattrs    = $v->get_variation_attributes();
			$is_orphan = false;
			foreach ( $vattrs as $akey => $aval ) {
				if ( '' === $aval ) {
					continue; // "cualquiera": no restringe.
				}
				$tax = str_replace( 'attribute_', '', $akey );
				if ( isset( $chosen[ $tax ] ) ) {
					if ( ! isset( $chosen[ $tax ][ $aval ] ) ) {
						$is_orphan = true;
						break;
					}
				} else {
					$is_orphan = true; // taxonomía ya no varía pero la variación fija un valor.
					break;
				}
			}
			if ( $is_orphan ) {
				$label = wc_get_formatted_variation( $v, true, true );
				$orphans[] = array( 'id' => (int) $vid, 'label' => $label ? wp_strip_all_tags( $label ) : ( '#' . $vid ) );
			} else {
				$keep_sigs[ self::variation_signature( $vattrs, array_keys( $chosen ) ) ] = true;
			}
		}

		// Si hay huérfanas y no se confirmó la poda: no tocamos nada y pedimos
		// confirmación al front (le pasamos la lista para mostrarla).
		if ( ! empty( $orphans ) && ! $prune ) {
			return new WP_REST_Response(
				array(
					'needs_prune' => true,
					'orphans'     => $orphans,
					'created'     => 0,
					'variations'  => self::admin_variations( $product ),
				),
				200
			);
		}

		// Poda confirmada: borra las huérfanas.
		$pruned = 0;
		if ( $prune && ! empty( $orphans ) ) {
			foreach ( $orphans as $o ) {
				$ov = wc_get_product( $o['id'] );
				if ( $ov instanceof WC_Product_Variation ) {
					$ov->delete( true );
					$pruned++;
				}
			}
		}

		// Construye los atributos del padre (opciones = term_ids). Asegura la
		// relación de términos en la taxonomía para que Woo los reconozca.
		$attr_objs = $keep_attrs;
		$position  = count( $keep_attrs );
		foreach ( array_keys( $order ) as $taxonomy ) {
			$ids = array_values( $term_ids_by_tax[ $taxonomy ] );
			// Reemplazo (no append): así, si se quitó un valor, se desasocia del padre.
			wp_set_object_terms( $product->get_id(), $ids, $taxonomy, false );

			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attr->set_name( $taxonomy );
			$attr->set_options( $ids );
			$attr->set_position( $position++ );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$attr_objs[ $taxonomy ] = $attr;
		}

		$product->set_attributes( array_values( $attr_objs ) );
		$product->save();

		// Firmas de las variaciones que quedan (para no duplicar).
		$existing_sigs = $keep_sigs;

		// Producto cartesiano de los términos elegidos.
		$combos = self::cartesian( $chosen );

		// Tope de seguridad: no explotar el catálogo.
		$max_total = 200;
		if ( count( $existing_sigs ) + count( array_filter( $combos, function ( $c ) use ( $existing_sigs, $chosen ) {
			return empty( $existing_sigs[ self::combo_signature( $c, array_keys( $chosen ) ) ] );
		} ) ) > $max_total ) {
			return new WP_Error(
				'keytill_too_many',
				/* translators: %d: tope de variaciones. */
				sprintf( __( 'Too many variations (max %d). Reduce values or create them in WooCommerce.', 'keytill' ), $max_total ),
				array( 'status' => 400 )
			);
		}

		$created = 0;
		foreach ( $combos as $combo ) {
			$sig = self::combo_signature( $combo, array_keys( $chosen ) );
			if ( ! empty( $existing_sigs[ $sig ] ) ) {
				continue;
			}
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( $combo ); // [ 'pa_talle' => 'm', ... ]
			$variation->set_status( 'publish' );
			$variation->save();
			$existing_sigs[ $sig ] = true;
			$created++;
		}

		// Recarga y sincroniza el padre (rango de precio/stock).
		$product = wc_get_product( $product->get_id() );
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $product->get_id() );
		}

		return new WP_REST_Response(
			array(
				'created'    => $created,
				'pruned'     => $pruned,
				'variations' => self::admin_variations( $product ),
			),
			201
		);
	}

	/**
	 * Firma de una variación a partir de sus atributos (solo las taxonomías
	 * relevantes), para detectar duplicados.
	 *
	 * @param array    $attrs Atributos ('attribute_pa_talle' => 'm').
	 * @param string[] $keys  Taxonomías relevantes.
	 * @return string
	 */
	private static function variation_signature( $attrs, $keys ) {
		$norm = array();
		foreach ( $attrs as $k => $v ) {
			$norm[ str_replace( 'attribute_', '', $k ) ] = $v;
		}
		return self::combo_signature( $norm, $keys );
	}

	/**
	 * Firma canónica de una combinación (taxonomy => slug).
	 *
	 * @param array    $combo Combinación (taxonomy => slug).
	 * @param string[] $keys  Taxonomías a considerar, en orden.
	 * @return string
	 */
	private static function combo_signature( $combo, $keys ) {
		$parts = array();
		$sorted = $keys;
		sort( $sorted );
		foreach ( $sorted as $k ) {
			$parts[] = $k . '=' . ( isset( $combo[ $k ] ) ? $combo[ $k ] : '' );
		}
		return implode( '|', $parts );
	}

	/**
	 * Producto cartesiano de un mapa taxonomy => [slugs].
	 *
	 * @param array $groups taxonomy => [ slug => slug ].
	 * @return array[] Lista de combinaciones (taxonomy => slug).
	 */
	private static function cartesian( $groups ) {
		$result = array( array() );
		foreach ( $groups as $taxonomy => $slugs ) {
			$next = array();
			foreach ( $result as $partial ) {
				foreach ( $slugs as $slug ) {
					$row              = $partial;
					$row[ $taxonomy ] = $slug;
					$next[]           = $row;
				}
			}
			$result = $next;
		}
		return $result;
	}

	/**
	 * Borra un producto o una variación. Permiso: manage.
	 *  - Variación: borrado definitivo (no tiene sentido mandarla a la papelera).
	 *  - Producto completo (simple/variable/…): va a la **papelera** (recuperable
	 *    desde WordPress si fue un error).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_product_delete( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'keytill_not_found', __( 'Product not found.', 'keytill' ), array( 'status' => 404 ) );
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			$product->delete( true ); // definitivo.
			if ( $parent_id && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $parent_id );
			}
			return new WP_REST_Response( array( 'deleted' => true, 'trashed' => false, 'id' => $id ), 200 );
		}

		// Producto completo → papelera (force = false). Los hooks de Woo se encargan
		// de invalidar el caché del catálogo.
		$product->delete( false );
		return new WP_REST_Response( array( 'deleted' => true, 'trashed' => true, 'id' => $id ), 200 );
	}

	/**
	 * Sube una imagen al media library y devuelve su id + urls. Permiso:
	 * manage + upload_files. Espera un archivo en el campo `file` (multipart).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_media_upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['name'] ) ) {
			return new WP_Error( 'keytill_no_file', __( 'No image was received.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Solo imágenes.
		$check = wp_check_filetype( $files['file']['name'] );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			return new WP_Error( 'keytill_bad_file', __( 'The file must be an image.', 'keytill' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$_FILES['file'] = $files['file'];
		$attach_id      = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attach_id ) ) {
			return new WP_Error( 'keytill_upload_fail', $attach_id->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'id'   => (int) $attach_id,
				'url'  => wp_get_attachment_image_url( $attach_id, 'medium' ),
				'thumb' => wp_get_attachment_image_url( $attach_id, 'thumbnail' ),
			),
			201
		);
	}

	/**
	 * Sanea una lista de ids para la galería: enteros positivos, únicos, que
	 * sean attachments de imagen existentes, preservando el orden recibido.
	 *
	 * @param mixed $raw Lista de ids (array).
	 * @return int[]
	 */
	private static function sanitize_gallery_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
				$out[]        = $id;
				$seen[ $id ]  = true;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Crédito en tienda
	 * ------------------------------------------------------------------ */

	/**
	 * Consulta un crédito por código: saldo, estado y si es canjeable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function credit_lookup( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );
		$row  = KEYTILL_Credit::get_by_code( $code );
		if ( ! $row ) {
			return new WP_Error( 'keytill_credit_404', __( 'Credit code does not exist.', 'keytill' ), array( 'status' => 404 ) );
		}
		$payload               = KEYTILL_Credit::payload( $row );
		$payload['reason']     = KEYTILL_Credit::is_redeemable( $row ) ? '' : KEYTILL_Credit::reason_not_redeemable( $row );
		$payload['expires_at'] = $row->expires_at ? mysql2date( 'd/m/Y', $row->expires_at ) : '';
		return new WP_REST_Response( $payload, 200 );
	}

	/* ------------------------------------------------------------------ *
	 * Arqueo de caja
	 * ------------------------------------------------------------------ */

	/**
	 * Sesión de caja abierta actual (o null).
	 *
	 * @return WP_REST_Response
	 */
	public static function cash_current() {
		$id = KEYTILL_Cash::get_open_session_id();
		return new WP_REST_Response(
			array( 'session' => $id ? KEYTILL_Cash::session_payload( $id ) : null ),
			200
		);
	}

	/**
	 * Abre la caja con un fondo inicial.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cash_open( WP_REST_Request $request ) {
		$params = self::body( $request );
		$float  = isset( $params['opening_float'] ) ? (float) $params['opening_float'] : 0.0;
		if ( $float < 0 ) {
			return new WP_Error( 'keytill_bad_amount', __( 'Invalid opening float.', 'keytill' ), array( 'status' => 400 ) );
		}
		$branch = isset( $params['branch_id'] ) ? (string) $params['branch_id'] : '';
		$res    = KEYTILL_Cash::open_session( $float, get_current_user_id(), $branch );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 201 );
	}

	/**
	 * Registra un retiro de efectivo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cash_withdraw( WP_REST_Request $request ) {
		$params = self::body( $request );
		$id     = KEYTILL_Cash::get_open_session_id();
		if ( ! $id ) {
			return new WP_Error( 'keytill_cash_closed', __( 'There is no open register.', 'keytill' ), array( 'status' => 409 ) );
		}
		$amount = isset( $params['amount'] ) ? (float) $params['amount'] : 0.0;
		$reason = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : '';
		$res    = KEYTILL_Cash::add_withdrawal( $id, $amount, $reason );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 201 );
	}

	/**
	 * Cierra la caja: esperado vs. contado + desglose por medio.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cash_close( WP_REST_Request $request ) {
		// Cerrar/arquear la caja es una función de control: solo encargado/admin
		// (no un cajero, para que no cierre su propio cajón y tape un faltante).
		if ( ! current_user_can( KEYTILL_CAP_MANAGE ) ) {
			return new WP_Error( 'keytill_forbidden', __( 'Only a manager or administrator can close the register.', 'keytill' ), array( 'status' => 403 ) );
		}
		$params  = self::body( $request );
		$id      = KEYTILL_Cash::get_open_session_id();
		if ( ! $id ) {
			return new WP_Error( 'keytill_cash_closed', __( 'There is no open register to close.', 'keytill' ), array( 'status' => 409 ) );
		}
		$counted = isset( $params['counted_cash'] ) ? (float) $params['counted_cash'] : 0.0;
		$res     = KEYTILL_Cash::close_session( $id, $counted );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * Movimiento manual (retiro o ingreso) con origen efectivo (caja física) o
	 * digital (cuenta bancaria). Los de la caja digital son de gestión.
	 *
	 * Body: { account: 'efectivo'|'digital', kind: 'retiro'|'ingreso', amount, concept }
	 */
	public static function cash_move( WP_REST_Request $request ) {
		$params  = self::body( $request );
		$account = ( isset( $params['account'] ) && 'digital' === $params['account'] ) ? 'digital' : 'efectivo';
		$kind    = ( isset( $params['kind'] ) && 'ingreso' === $params['kind'] ) ? 'ingreso' : 'retiro';
		$amount  = isset( $params['amount'] ) ? (float) $params['amount'] : 0.0;
		$concept = isset( $params['concept'] ) ? sanitize_text_field( $params['concept'] ) : '';

		if ( 'digital' === $account ) {
			// La caja digital es la cuenta bancaria: moverla es de gestión.
			if ( ! current_user_can( KEYTILL_CAP_MANAGE ) ) {
				return new WP_Error( 'keytill_forbidden', __( 'Only a manager or administrator can move the digital register.', 'keytill' ), array( 'status' => 403 ) );
			}
			$id = KEYTILL_Cash_Digital::get_open_session_id();
			if ( ! $id ) {
				return new WP_Error( 'keytill_digital_closed', __( 'The digital register is not open.', 'keytill' ), array( 'status' => 409 ) );
			}
			$res = KEYTILL_Cash_Digital::add_move( $id, $kind, $amount, $concept );
			return is_wp_error( $res ) ? $res : new WP_REST_Response( array( 'account' => 'digital', 'digital' => $res ), 201 );
		}

		// Efectivo → caja física del cajero.
		$id = KEYTILL_Cash::get_open_session_id();
		if ( ! $id ) {
			return new WP_Error( 'keytill_cash_closed', __( 'There is no open register.', 'keytill' ), array( 'status' => 409 ) );
		}
		$res = ( 'ingreso' === $kind )
			? KEYTILL_Cash::add_deposit( $id, $amount, $concept )
			: KEYTILL_Cash::add_withdrawal( $id, $amount, $concept );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( array( 'account' => 'efectivo', 'session' => $res ), 201 );
	}

	/* ------------------------------------------------------------------ *
	 * Caja digital (cuenta bancaria única) + Caja/Banco
	 * ------------------------------------------------------------------ */

	public static function digital_current() {
		return new WP_REST_Response( array( 'digital' => KEYTILL_Cash_Digital::current_payload() ), 200 );
	}

	public static function digital_open( WP_REST_Request $request ) {
		$params  = self::body( $request );
		$initial = array_key_exists( 'initial', $params ) ? (float) $params['initial'] : null;
		$res     = KEYTILL_Cash_Digital::open_session( get_current_user_id(), $initial );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 201 );
	}

	public static function digital_close() {
		$id = KEYTILL_Cash_Digital::get_open_session_id();
		if ( ! $id ) {
			return new WP_Error( 'keytill_digital_closed', __( 'The digital register is not open.', 'keytill' ), array( 'status' => 409 ) );
		}
		$res = KEYTILL_Cash_Digital::close_session( $id );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	public static function digital_verify( WP_REST_Request $request ) {
		$params = self::body( $request );
		$oid    = isset( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';
		if ( ! $oid || ! wc_get_order( $oid ) ) {
			return new WP_Error( 'keytill_not_found', __( 'Order not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$new = KEYTILL_Cash_Digital::set_verification( $oid, $status );
		return new WP_REST_Response( array( 'order_id' => $oid, 'verification' => $new ), 200 );
	}

	/** Resumen combinado para la pestaña Caja/Banco (ambas cajas). */
	public static function cashbank_summary() {
		$cash_id = KEYTILL_Cash::get_open_session_id();
		return new WP_REST_Response(
			array(
				'cash'    => $cash_id ? KEYTILL_Cash::session_payload( $cash_id ) : null,
				'digital' => KEYTILL_Cash_Digital::current_payload(),
			),
			200
		);
	}

	/**
	 * Libro de movimientos de Caja/Banco: histórico filtrable de ingresos y egresos
	 * (ventas, ingresos, retiros, devoluciones) de ambas cuentas (efectivo/digital)
	 * y ambos orígenes (local/web). Para ver el estado general más allá del día.
	 *
	 * Filtros (query): from, to (Y-m-d), origen, movement, type, account.
	 */
	public static function cashbank_ledger( WP_REST_Request $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = wp_date( 'Y-m-01' ); // por defecto: desde el 1º del mes
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = wp_date( 'Y-m-d' );    // hasta hoy
		}
		$f_origen  = sanitize_key( (string) $request->get_param( 'origen' ) );
		$f_move    = sanitize_key( (string) $request->get_param( 'movement' ) );
		$f_type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$f_account = sanitize_key( (string) $request->get_param( 'account' ) );

		$rows = array();

		// --- Ventas + devoluciones (órdenes) ---
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
				'limit'        => -1,
				'date_created' => $from . '...' . $to,
			)
		) : array();

		foreach ( (array) $orders as $o ) {
			if ( ! $o instanceof WC_Order ) {
				continue;
			}
			$local  = ( 'keytill' === $o->get_created_via() );
			$origen = $local ? 'local' : 'web';
			$pm     = $o->get_payment_method();

			if ( $local ) {
				$credit_used = (float) $o->get_meta( '_keytill_credit_used' );
				$charged     = round( (float) $o->get_total() - $credit_used, 2 );
				$account     = ( 'credito' === $pm ) ? '' : ( ( 'efectivo' === $pm ) ? 'efectivo' : 'digital' );
			} else {
				$charged = round( (float) $o->get_total(), 2 );
				$account = 'digital'; // la web acredita en la cuenta bancaria / pasarela
			}

			if ( '' !== $account && $charged > 0 ) {
				$ts        = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
				$is_tx     = ( $local && 'digital' === $account );
				$rows[]    = array(
					'ts'           => $ts,
					'date'         => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
					'origen'       => $origen,
					'movement'     => 'ingreso',
					'type'         => 'venta',
					'account'      => $account,
					'amount'       => $charged,
					'detail'       => '#' . $o->get_order_number() . self::ledger_cust( $o ) . ( $o->get_payment_method_title() ? ' · ' . $o->get_payment_method_title() : '' ),
					'order_id'     => $is_tx ? (int) $o->get_id() : 0,
					'verification' => $is_tx ? KEYTILL_Cash_Digital::verification_of( $o->get_id() ) : '',
				);
			}

			// Devoluciones de la orden (egresos).
			foreach ( $o->get_refunds() as $refund ) {
				$method = $refund->get_meta( '_keytill_refund_method' );
				if ( 'efectivo' === $method ) {
					$acc = 'efectivo';
					$amt = abs( (float) $refund->get_total() );
				} elseif ( 'transferencia' === $method ) {
					$acc = 'digital';
					$st  = (float) $refund->get_meta( '_keytill_digital_refund' );
					$amt = $st > 0 ? $st : abs( (float) $refund->get_total() );
				} else {
					continue; // crédito en tienda u otros: no mueven efectivo/banco.
				}
				if ( $amt <= 0 ) {
					continue;
				}
				$rows[] = array(
					'ts'           => $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : 0,
					'date'         => $refund->get_date_created() ? $refund->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
					'origen'       => $origen,
					'movement'     => 'egreso',
					'type'         => 'devolucion',
					'account'      => $acc,
					'amount'       => round( $amt, 2 ),
					'detail'       => sprintf( __( 'Refund #%s', 'keytill' ), $o->get_order_number() ),
					'order_id'     => 0,
					'verification' => '',
				);
			}
		}

		// --- Caja física: ingresos (depósitos) + retiros ---
		$fsess = get_posts(
			array( 'post_type' => KEYTILL_Cash::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' )
		);
		foreach ( $fsess as $sid ) {
			foreach ( (array) get_post_meta( $sid, '_keytill_deposits', true ) as $d ) {
				self::ledger_push( $rows, $d, 'ingreso', 'ingreso', 'efectivo', isset( $d['concept'] ) ? $d['concept'] : '', $from, $to );
			}
			foreach ( (array) get_post_meta( $sid, '_keytill_withdrawals', true ) as $w ) {
				self::ledger_push( $rows, $w, 'egreso', 'retiro', 'efectivo', isset( $w['reason'] ) ? $w['reason'] : '', $from, $to );
			}
		}

		// --- Caja digital: ingresos/retiros manuales ---
		$dsess = get_posts(
			array( 'post_type' => KEYTILL_Cash_Digital::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' )
		);
		foreach ( $dsess as $sid ) {
			foreach ( (array) get_post_meta( $sid, '_keytill_moves', true ) as $m ) {
				$mv = ( 'ingreso' === $m['kind'] ) ? 'ingreso' : 'egreso';
				$ty = ( 'ingreso' === $m['kind'] ) ? 'ingreso' : 'retiro';
				self::ledger_push( $rows, $m, $mv, $ty, 'digital', isset( $m['concept'] ) ? $m['concept'] : '', $from, $to );
			}
		}

		// Filtros.
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $f_origen, $f_move, $f_type, $f_account ) {
					if ( '' !== $f_origen && $r['origen'] !== $f_origen ) {
						return false;
					}
					if ( '' !== $f_move && $r['movement'] !== $f_move ) {
						return false;
					}
					if ( '' !== $f_type && $r['type'] !== $f_type ) {
						return false;
					}
					if ( '' !== $f_account && $r['account'] !== $f_account ) {
						return false;
					}
					return true;
				}
			)
		);

		// Orden por fecha, más reciente primero.
		usort( $rows, function ( $a, $b ) { return $b['ts'] <=> $a['ts']; } );

		// Totales por cuenta.
		$totals = array(
			'efectivo' => array( 'in' => 0.0, 'out' => 0.0 ),
			'digital'  => array( 'in' => 0.0, 'out' => 0.0 ),
		);
		foreach ( $rows as $r ) {
			if ( ! isset( $totals[ $r['account'] ] ) ) {
				continue;
			}
			$k = 'ingreso' === $r['movement'] ? 'in' : 'out';
			$totals[ $r['account'] ][ $k ] += (float) $r['amount'];
		}
		foreach ( $totals as $acc => $v ) {
			$totals[ $acc ]['in']  = round( $v['in'], 2 );
			$totals[ $acc ]['out'] = round( $v['out'], 2 );
			$totals[ $acc ]['net'] = round( $v['in'] - $v['out'], 2 );
		}

		return new WP_REST_Response(
			array( 'from' => $from, 'to' => $to, 'count' => count( $rows ), 'rows' => $rows, 'totals' => $totals ),
			200
		);
	}

	/** Nombre del cliente para el detalle del libro ('' si no hay). */
	private static function ledger_cust( $order ) {
		$name = $order->get_meta( '_keytill_customer_name' );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return '' !== $name ? ' · ' . $name : '';
	}

	/** Agrega al libro un movimiento de sesión (retiro/ingreso) si está en el rango. */
	private static function ledger_push( &$rows, $m, $movement, $type, $account, $detail, $from, $to ) {
		$at  = isset( $m['at'] ) ? (string) $m['at'] : '';
		$d10 = substr( $at, 0, 10 );
		if ( '' === $at || $d10 < $from || $d10 > $to ) {
			return;
		}
		$rows[] = array(
			'ts'           => strtotime( $at ),
			'date'         => mysql2date( 'd/m/Y H:i', $at ),
			'origen'       => 'local',
			'movement'     => $movement,
			'type'         => $type,
			'account'      => $account,
			'amount'       => round( (float) ( isset( $m['amount'] ) ? $m['amount'] : 0 ), 2 ),
			'detail'       => '' !== $detail ? $detail : ( 'ingreso' === $type ? 'Ingreso' : 'Retiro' ),
			'order_id'     => 0,
			'verification' => '',
		);
	}

	/**
	 * Lee el body JSON (o params) de una request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function body( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : $request->get_params();
	}

	/**
	 * Gate de permiso: solo el cajero (o quien tenga la capability).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_operate() {
		if ( current_user_can( KEYTILL_CAP ) ) {
			self::$pos_context = true;
			return true;
		}
		return new WP_Error(
			'keytill_forbidden',
			__( 'You do not have permission to operate the POS.', 'keytill' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Gate de permiso: gestión (editar/crear productos). Encargado o admin.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_manage() {
		if ( current_user_can( KEYTILL_CAP_MANAGE ) ) {
			self::$pos_context = true;
			return true;
		}
		return new WP_Error(
			'keytill_forbidden',
			__( 'You do not have permission to manage products.', 'keytill' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Gate de permiso: gestión + subir archivos (imagen del producto).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_upload() {
		if ( current_user_can( KEYTILL_CAP_MANAGE ) && current_user_can( 'upload_files' ) ) {
			return true;
		}
		return new WP_Error(
			'keytill_forbidden',
			__( 'You do not have permission to upload images.', 'keytill' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/* ------------------------------------------------------------------ *
	 * GET /products?q=
	 * ------------------------------------------------------------------ */

	/**
	 * Busca productos: SKU/código exacto primero, después por nombre.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_products( WP_REST_Request $request ) {
		$q   = trim( (string) $request->get_param( 'q' ) );
		$all = (bool) $request->get_param( 'all' );

		// Precarga del catálogo (híbrido): escaneo por código instantáneo en el
		// front. El stock acá es una pista; la verdad se descuenta al cobrar.
		// Cacheado en transient: la precarga completa (limit=-1 + hidratar cada
		// producto) es cara en catálogos grandes y el front la pide al abrir la
		// caja y al recuperar foco. El cache se invalida al cambiar productos/stock
		// (ver init()); el TTL de 12 h es solo un backstop.
		if ( $all ) {
			$cached = get_transient( self::CATALOG_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return new WP_REST_Response( $cached, 200 );
			}
			$found = wc_get_products(
				array(
					// Incluye privados: en el POS (staff) los productos de test
					// (privados/ocultos) también se ven y se venden. No en la web.
					'status'  => array( 'publish', 'private' ),
					'limit'   => -1,
					'orderby' => 'title',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			$out = array();
			foreach ( $found as $id ) {
				$out = array_merge( $out, self::expand_product( wc_get_product( $id ) ) );
			}
			set_transient( self::CATALOG_CACHE_KEY, $out, 12 * HOUR_IN_SECONDS );
			return new WP_REST_Response( $out, 200 );
		}

		if ( '' === $q ) {
			return new WP_REST_Response( array(), 200 );
		}

		$ids   = array();
		$seen  = array();

		// 1) SKU / código exacto primero.
		$exact_id = wc_get_product_id_by_sku( $q );
		if ( $exact_id ) {
			$ids[]           = $exact_id;
			$seen[ $exact_id ] = true;
		}

		// 2) Búsqueda por nombre (y SKU parcial via 's').
		$found = wc_get_products(
			array(
				'status'  => array( 'publish', 'private' ),
				'limit'   => 12,
				's'       => $q,
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		foreach ( $found as $fid ) {
			if ( empty( $seen[ $fid ] ) ) {
				$ids[]        = $fid;
				$seen[ $fid ] = true;
			}
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out = array_merge( $out, self::expand_product( wc_get_product( $id ) ) );
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Expande un producto en las entradas de catálogo que se pueden vender: los
	 * simples devuelven una entrada; los **variables** devuelven una por cada
	 * variación comprable (cada talle/color es un ítem escaneable). Los grouped
	 * y external no se venden en el mostrador.
	 *
	 * @param WC_Product|false|null $product Producto.
	 * @return array[]
	 */
	private static function expand_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		if ( $product->is_type( 'variable' ) ) {
			$out = array();
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && $v->exists() && 'publish' === $v->get_status() && $v->is_purchasable() ) {
					$out[] = self::product_payload( $v );
				}
			}
			return $out;
		}
		if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return array();
		}
		return array( self::product_payload( $product ) );
	}

	/**
	 * Etiqueta clave-valor de los atributos de una variación
	 * ("Color: Azul · Talle: M"), para que se entienda qué es cada valor.
	 *
	 * @param WC_Product_Variation $variation Variación.
	 * @return string
	 */
	private static function variation_label( $variation ) {
		$parent = wc_get_product( $variation->get_parent_id() );
		$pairs  = array();
		foreach ( $variation->get_variation_attributes() as $attr => $val ) {
			if ( '' === $val ) {
				continue; // "cualquiera".
			}
			$taxonomy = str_replace( 'attribute_', '', $attr );
			$label    = wc_attribute_label( $taxonomy, $parent ? $parent : $variation );
			$value    = $val;
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $val, $taxonomy );
				if ( $term ) {
					$value = $term->name;
				}
			}
			$pairs[] = $label . ': ' . $value;
		}
		return implode( ' · ', $pairs );
	}

	/**
	 * Da forma a un producto (o variación) para el front.
	 *
	 * @param WC_Product $product Producto o variación.
	 * @return array
	 */
	private static function product_payload( WC_Product $product ) {
		$is_var = $product->is_type( 'variation' );
		$parent = $is_var ? wc_get_product( $product->get_parent_id() ) : null;

		// Categoría: de la variación toma la del padre.
		$cat_src = ( $is_var && $parent ) ? $parent : $product;
		$cat_ids = $cat_src->get_category_ids();
		$cat     = '';
		if ( ! empty( $cat_ids ) ) {
			$term = get_term( $cat_ids[0], 'product_cat' );
			$cat  = ( $term instanceof WP_Term ) ? $term->name : '';
		}

		// Nombre = del padre; los atributos van aparte en `variation`.
		$name      = ( $is_var && $parent ) ? $parent->get_name() : $product->get_name();
		$var_label = $is_var ? self::variation_label( $product ) : '';

		// SKU propio de la variación (si no tiene, queda vacío: no se puede
		// escanear individualmente, pero sí buscar por atributos). No se cae al
		// del padre para no volverlo ambiguo entre variaciones.
		$sku = $product->get_sku();

		// Miniatura (para la confirmación del escaneo): la de la variación o, si no
		// tiene, la del padre.
		$img_id = (int) $product->get_image_id();
		if ( ! $img_id && $is_var && $parent ) {
			$img_id = (int) $parent->get_image_id();
		}

		return array(
			'id'                 => (int) $product->get_id(),
			'name'               => $name,
			'variation'          => $var_label,
			'sku'                => $sku,
			'category'           => $cat,
			'image'              => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
			'price'              => (float) $product->get_price(),
			'barcode'            => (string) $product->get_meta( '_keytill_barcode' ),
			'stock_qty'          => null !== $product->get_stock_quantity() ? (int) $product->get_stock_quantity() : null,
			'manage_stock'       => (bool) $product->get_manage_stock(),
			'backorders_allowed' => (bool) $product->backorders_allowed(),
			'in_stock'           => $product->is_in_stock(),
			'giftcard'           => KEYTILL_Credit_Product::is_giftcard( $product ),
			'giftcard_custom'    => KEYTILL_Credit_Product::is_custom( $product ),
			'giftcard_denoms'    => KEYTILL_Credit_Product::denominations( $product ),
			// Sucursal (informativa): cada ítem (simple o variación) tiene la suya.
			'branch'             => KEYTILL_Branches::name_of( $product->get_meta( KEYTILL_Branches::META ) ),
			// Stock por sucursal (si el ítem está distribuido): el front muestra el
			// de la sucursal de la caja y lo usa para el flujo de faltante.
			'branch_stock'       => KEYTILL_Branch_Stock::map_for_active( $product->get_id() ),
			'branch_managed'     => KEYTILL_Branch_Stock::is_managed( $product->get_id() ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * POST /orders
	 * ------------------------------------------------------------------ */

	/**
	 * Crea una orden de Woo pagada a partir del carrito del POS.
	 *
	 * Body: {
	 *   items:[{product_id, qty}],
	 *   customer:{name, doc, iva}|null,
	 *   payment_method,
	 *   discount:{type:'percent'|'fixed', value}|null,
	 *   amount_tendered   // opcional, efectivo: para calcular el vuelto
	 * }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_order( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'keytill_no_woo', __( 'WooCommerce is unavailable.', 'keytill' ), array( 'status' => 500 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// --- items ---
		$raw_items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$items     = array();
		foreach ( $raw_items as $it ) {
			$pid = isset( $it['product_id'] ) ? absint( $it['product_id'] ) : 0;
			$qty = isset( $it['qty'] ) ? absint( $it['qty'] ) : 0;
			// Monto elegido (solo aplica a gift cards de monto libre).
			$amt = isset( $it['amount'] ) ? max( 0, round( (float) $it['amount'], 2 ) ) : null;
			// Descuento por línea (opcional): {type:'percent'|'fixed', value>0}.
			$ldisc = null;
			if ( isset( $it['discount'] ) && is_array( $it['discount'] ) ) {
				$lt = isset( $it['discount']['type'] ) ? sanitize_key( $it['discount']['type'] ) : 'percent';
				$lv = isset( $it['discount']['value'] ) ? (float) $it['discount']['value'] : 0.0;
				if ( $lv > 0 && in_array( $lt, array( 'percent', 'fixed' ), true ) ) {
					if ( 'percent' === $lt ) {
						$lv = min( 100.0, max( 0.0, $lv ) );
					}
					$ldisc = array( 'type' => $lt, 'value' => $lv );
				}
			}
			// Origen del stock (opcional): sucursal de la que se descuenta y si se
			// permite dejar negativo. Solo aplica a productos distribuidos; por
			// defecto se usa la sucursal de la caja con corte de sobreventa.
			$sbranch = isset( $it['stock_branch'] ) ? (string) $it['stock_branch'] : '';
			$sforce  = ! empty( $it['stock_force'] );
			if ( $pid && $qty > 0 ) {
				$items[] = array( 'product_id' => $pid, 'qty' => $qty, 'amount' => $amt, 'discount' => $ldisc, 'stock_branch' => $sbranch, 'stock_force' => $sforce );
			}
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'keytill_empty', __( 'The cart is empty.', 'keytill' ), array( 'status' => 400 ) );
		}

		// --- idempotencia ---
		// El front manda una clave única por intento de venta (se reusa en los
		// reintentos). Si ya existe una orden con esa clave, se devuelve ESA en
		// lugar de crear otra: evita la venta duplicada cuando la respuesta se
		// perdió pero la orden sí se creó. (No es un lock distribuido: cubre el
		// reintento secuencial tras timeout, que es el caso real de una caja.)
		$idem = isset( $params['idempotency_key'] ) ? sanitize_text_field( (string) $params['idempotency_key'] ) : '';
		if ( '' !== $idem ) {
			// Búsqueda del duplicado acotada al cajero actual (la idempotencia es
			// por-cajero: cubre su propio reintento y una clave no recupera la orden
			// de otro). Se consulta DIRECTO contra la tabla de meta correcta según el
			// modo de almacenamiento: con HPOS apagado (store legacy) wc_get_orders
			// IGNORA el meta_query y devolvería la última orden cualquiera → antes eso
			// hacía que toda venta cayera como "replay" y no se creara la orden nueva.
			$dupe_id = self::find_order_id_by_idem( $idem, (int) get_current_user_id() );
			if ( $dupe_id ) {
				$dorder = wc_get_order( $dupe_id );
				if ( $dorder ) {
					$dtotal = (float) $dorder->get_total();
					$dused  = (float) $dorder->get_meta( '_keytill_credit_used' );
					$dchg   = $dorder->get_meta( '_keytill_change' );
					return new WP_REST_Response(
						array(
							'order_id'          => (int) $dorder->get_id(),
							'number'            => $dorder->get_order_number(),
							'total'             => $dtotal,
							'credit_used'       => $dused,
							'amount_due'        => round( $dtotal - $dused, 2 ),
							'change'            => ( '' !== $dchg ) ? (float) $dchg : null,
							'giftcards'         => KEYTILL_Credit_Product::codes_for_order( $dorder ),
							'print_url'         => KEYTILL_Print::print_url( $dorder->get_id() ),
							'idempotent_replay' => true,
						),
						200
					);
				}
			}
		}

		// --- caja abierta (obligatoria para cobrar) ---
		// Toda venta del POS queda ligada a una sesión de caja del cajero: es lo que
		// permite el arqueo al cierre. Se deriva del servidor (no se confía en el
		// register_session_id que manda el front) y, si no hay caja abierta, no se
		// registra la venta. Va DESPUÉS de la idempotencia: un reintento de una
		// orden ya creada se resuelve arriba aunque la caja se haya cerrado luego.
		$open_session_id = KEYTILL_Cash::get_open_session_id();
		if ( ! $open_session_id ) {
			return new WP_Error( 'keytill_cash_closed', __( 'There is no open register. Open the register to take payment.', 'keytill' ), array( 'status' => 409 ) );
		}

		// --- crédito en tienda (canje, opcional) ---
		$credit_code = isset( $params['credit_code'] ) ? KEYTILL_Credit::normalize_code( $params['credit_code'] ) : '';
		$credit_row  = null;
		if ( '' !== $credit_code ) {
			$credit_row = KEYTILL_Credit::get_by_code( $credit_code );
			if ( ! KEYTILL_Credit::is_redeemable( $credit_row ) ) {
				return new WP_Error( 'keytill_credit_invalid', KEYTILL_Credit::reason_not_redeemable( $credit_row ), array( 'status' => 409 ) );
			}
		}

		// --- medio de pago ---
		// Con crédito, el medio es para el RESTO; si el crédito cubre todo, es
		// opcional (la venta queda como "Crédito en tienda").
		$methods = self::payment_methods();
		$pm       = isset( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : '';
		if ( '' !== $pm && ! isset( $methods[ $pm ] ) ) {
			return new WP_Error( 'keytill_bad_method', __( 'Invalid payment method.', 'keytill' ), array( 'status' => 400 ) );
		}
		// No se exige medio acá: si tras el descuento/crédito queda saldo a cobrar y
		// no hay medio válido, se rechaza más abajo (cuando ya se conoce el total).
		// Así una venta con bonificación del 100% (total $0, sin crédito ni medio)
		// se puede concretar.

		// --- descuento ---
		$discount = null;
		if ( isset( $params['discount'] ) && is_array( $params['discount'] ) ) {
			$dtype  = isset( $params['discount']['type'] ) ? sanitize_key( $params['discount']['type'] ) : 'percent';
			$dvalue = isset( $params['discount']['value'] ) ? (float) $params['discount']['value'] : 0.0;
			if ( $dvalue > 0 && in_array( $dtype, array( 'percent', 'fixed' ), true ) ) {
				if ( 'percent' === $dtype ) {
					$dvalue = min( 100.0, max( 0.0, $dvalue ) );
				}
				$discount = array( 'type' => $dtype, 'value' => $dvalue );
			}
		}

		// --- cliente ---
		// Puede venir como cliente guardado (customer_id) o como datos sueltos
		// (venta anónima con datos solo en la orden). El id tiene prioridad.
		$customer_id = isset( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;
		$customer    = null;
		if ( ! $customer_id && isset( $params['customer'] ) && is_array( $params['customer'] ) ) {
			$customer = array(
				'name' => isset( $params['customer']['name'] ) ? sanitize_text_field( $params['customer']['name'] ) : '',
				'doc'  => isset( $params['customer']['doc'] ) ? sanitize_text_field( $params['customer']['doc'] ) : '',
				'iva'  => isset( $params['customer']['iva'] ) ? sanitize_text_field( $params['customer']['iva'] ) : '',
			);
		}

		// --- construcción de la orden (CRUD de Woo) ---
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'keytill_order_fail', __( 'The order could not be created.', 'keytill' ), array( 'status' => 500 ) );
		}

		// Agrupa cantidades por producto (por si viniera repetido) y valida
		// ANTES de crear la orden: producto comprable + stock suficiente.
		// Las gift cards (con el monto elegido) se llevan aparte: cada una es su
		// propia línea con su precio, no se agrupan por producto.
		$qty_by_id    = array();
		$disc_by_id   = array(); // product_id => {type, value} (descuento por línea)
		$src_by_id    = array(); // product_id => sucursal de origen elegida (stock por sucursal)
		$force_by_id  = array(); // product_id => permitir negativo (vender igual)
		$custom_lines = array();
		$gc_max       = class_exists( 'KEYTILL_Settings' ) ? KEYTILL_Settings::giftcard_max() : 0;
		foreach ( $items as $it ) {
			$prod_it = wc_get_product( $it['product_id'] );
			if ( $prod_it && KEYTILL_Credit_Product::is_giftcard( $prod_it ) && null !== $it['amount'] && $it['amount'] > 0 ) {
				// Tope al monto de gift card de monto libre (0 = sin tope).
				if ( $gc_max > 0 && $it['amount'] > $gc_max ) {
					$order->delete( true );
					return new WP_Error(
						'keytill_giftcard_max',
						/* translators: %s: monto máximo. */
						sprintf( __( 'The gift card amount exceeds the allowed limit (%s).', 'keytill' ), wp_strip_all_tags( wc_price( $gc_max ) ) ),
						array( 'status' => 400 )
					);
				}
				$custom_lines[] = array( 'product' => $prod_it, 'qty' => $it['qty'], 'amount' => $it['amount'] );
			} else {
				$qty_by_id[ $it['product_id'] ] = ( $qty_by_id[ $it['product_id'] ] ?? 0 ) + $it['qty'];
				if ( ! empty( $it['discount'] ) ) {
					$disc_by_id[ $it['product_id'] ] = $it['discount'];
				}
				if ( ! empty( $it['stock_branch'] ) ) {
					$src_by_id[ $it['product_id'] ] = $it['stock_branch'];
				}
				if ( ! empty( $it['stock_force'] ) ) {
					$force_by_id[ $it['product_id'] ] = true;
				}
			}
		}

		// Sucursal de la caja: origen por defecto del stock por sucursal.
		$caja_branch = KEYTILL_Cash::session_branch( $open_session_id );

		$prepared   = array();
		$errors     = array();
		$shortages  = array(); // faltantes de productos distribuidos (front puede elegir origen)
		foreach ( $qty_by_id as $pid => $qty ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				/* translators: %d: product id. */
				$errors[] = sprintf( __( 'Product not found (id %d).', 'keytill' ), $pid );
				continue;
			}
			if ( ! $product->is_purchasable() ) {
				// Motivo más frecuente: producto sin precio cargado. Se nombra el
				// producto (no un id) para que el cajero sepa cuál corregir.
				$reason   = ( '' === (string) $product->get_price() )
					? __( 'no price set', 'keytill' )
					: __( 'not available for sale', 'keytill' );
				/* translators: 1: product name, 2: reason. */
				$errors[] = sprintf( __( '%1$s: %2$s.', 'keytill' ), $product->get_name(), $reason );
				continue;
			}

			if ( KEYTILL_Branch_Stock::is_managed( $pid ) ) {
				// Producto DISTRIBUIDO: descuenta de una sucursal (la elegida como
				// origen, o la de la caja). Corte de sobreventa POR SUCURSAL, salvo
				// que el cajero haya confirmado vender igual (force → puede negativo).
				$src = ! empty( $src_by_id[ $pid ] ) ? KEYTILL_Branches::valid_id( $src_by_id[ $pid ] ) : $caja_branch;
				if ( '' === $src ) {
					$errors[] = sprintf( __( '%s: the source location is missing.', 'keytill' ), $product->get_name() );
					continue;
				}
				$force = ! empty( $force_by_id[ $pid ] );
				$have  = KEYTILL_Branch_Stock::get( $pid, $src );
				if ( ! $force && $have < $qty ) {
					// Faltante: se informa dónde SÍ hay, para que el cajero elija.
					$shortages[] = array(
						'product_id'  => $pid,
						'name'        => $product->get_name(),
						'need'        => $qty,
						'branch'      => $src,
						'branch_name' => KEYTILL_Branches::name_of( $src ),
						'have'        => $have,
						'available'   => KEYTILL_Branch_Stock::map_for_active( $pid ),
					);
					continue;
				}
				$prepared[] = array( 'product' => $product, 'qty' => $qty, 'managed' => true, 'pid' => $pid, 'src' => $src, 'force' => $force );
			} else {
				// Producto LEGACY (aún no distribuido): stock único de Woo, como hoy.
				if ( ! $product->has_enough_stock( $qty ) ) {
					$avail    = $product->get_stock_quantity();
					$errors[] = sprintf(
						/* translators: 1: product name, 2: available stock (o "sin stock"), 3: requested qty. */
						__( 'Not enough stock: %1$s (available %2$s, requested %3$d).', 'keytill' ),
						$product->get_name(),
						null === $avail ? __( 'out of stock', 'keytill' ) : (string) $avail,
						$qty
					);
					continue;
				}
				$prepared[] = array( 'product' => $product, 'qty' => $qty, 'managed' => false, 'pid' => $pid );
			}
		}

		if ( ! empty( $errors ) ) {
			$order->delete( true );
			return new WP_Error( 'keytill_stock', implode( ' · ', $errors ), array( 'status' => 409 ) );
		}
		if ( ! empty( $shortages ) ) {
			// Faltante en la sucursal de la caja: NO se bloquea de plano. Se devuelve
			// la info para que el front avise y el cajero elija de dónde descontar
			// (su sucursal en negativo, o la que sí tiene) y reintente con stock_branch/force.
			$order->delete( true );
			$parts = array();
			foreach ( $shortages as $s ) {
				$parts[] = sprintf(
					/* translators: 1: producto, 2: sucursal, 3: disponible, 4: pedido. */
					__( '%1$s: out of stock at %2$s (available %3$d, requested %4$d).', 'keytill' ),
					$s['name'],
					$s['branch_name'],
					$s['have'],
					$s['need']
				);
			}
			return new WP_Error(
				'keytill_branch_stock',
				implode( ' · ', $parts ),
				array( 'status' => 409, 'shortages' => $shortages )
			);
		}

		// $subtotal: total de mercadería. $giftcard_subtotal: porción de gift cards
		// (valor almacenado), que NO se descuenta. El descuento se calcula/aplica
		// solo sobre la diferencia (subtotal descontable).
		$subtotal          = 0.0;
		$giftcard_subtotal = 0.0;
		foreach ( $prepared as $p ) {
			$order->add_product( $p['product'], $p['qty'] );
			$line      = (float) $p['product']->get_price() * $p['qty'];
			$subtotal += $line;
			if ( KEYTILL_Credit_Product::is_giftcard( $p['product'] ) ) {
				$giftcard_subtotal += $line;
			}
		}

		// Gift cards de monto libre: una línea por cada una, con el precio elegido.
		foreach ( $custom_lines as $cl ) {
			$line = round( (float) $cl['amount'] * (int) $cl['qty'], 2 );
			$gi   = new WC_Order_Item_Product();
			$gi->set_product( $cl['product'] );
			$gi->set_quantity( (int) $cl['qty'] );
			$gi->set_subtotal( $line );
			$gi->set_total( $line );
			$order->add_item( $gi );
			$subtotal          += $line;
			$giftcard_subtotal += $line;
		}

		if ( empty( $prepared ) && empty( $custom_lines ) ) {
			$order->delete( true );
			return new WP_Error( 'keytill_empty', __( 'The cart is empty.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Descuento aplicado sobre los LINE ITEMS (subtotal original, total con
		// descuento). Así la orden muestra el descuento y, sobre todo, las
		// devoluciones se calculan sobre lo realmente pagado por línea.
		// Factor GLOBAL (descuento a toda la venta) sobre el subtotal descontable.
		$global_factor = 1.0;
		if ( $discount ) {
			$discountable = $subtotal - $giftcard_subtotal;
			if ( 'percent' === $discount['type'] ) {
				$global_factor = max( 0.0, 1 - ( $discount['value'] / 100 ) );
			} else {
				$global_factor = $discountable > 0 ? max( 0.0, ( $discountable - $discount['value'] ) / $discountable ) : 1.0;
			}
			$order->add_meta_data( '_keytill_discount_type', $discount['type'], true );
			$order->add_meta_data( '_keytill_discount_value', $discount['value'], true );
		}

		// Descuento por LÍNEA (por prenda) y/o global. En el front son excluyentes;
		// acá se combinan multiplicando factores (si solo hay uno, el otro es 1) para
		// ser robustos. Las gift cards nunca se descuentan (valor almacenado). El
		// total con descuento por línea permite que las devoluciones se calculen sobre
		// lo realmente pagado por cada línea.
		foreach ( $order->get_items() as $item ) {
			$prod = $item->get_product();
			if ( $prod && KEYTILL_Credit_Product::is_giftcard( $prod ) ) {
				continue;
			}
			$base        = (float) $item->get_subtotal();
			$line_factor = 1.0;
			$pid_item    = $prod ? $prod->get_id() : 0;
			if ( $pid_item && isset( $disc_by_id[ $pid_item ] ) ) {
				$ld = $disc_by_id[ $pid_item ];
				if ( 'percent' === $ld['type'] ) {
					$line_factor = max( 0.0, 1 - ( $ld['value'] / 100 ) );
				} else {
					$line_factor = $base > 0 ? max( 0.0, ( $base - $ld['value'] ) / $base ) : 1.0;
				}
			}
			if ( $global_factor < 1.0 || $line_factor < 1.0 ) {
				$item->set_total( round( $base * $line_factor * $global_factor, 2 ) );
			}
		}

		// Cliente guardado: liga el usuario de Woo y copia sus datos a la orden.
		if ( $customer_id && get_userdata( $customer_id ) ) {
			KEYTILL_Customers::attach_to_order( $order, $customer_id );
		} elseif ( $customer && ( '' !== $customer['name'] || '' !== $customer['doc'] ) ) {
			// Venta anónima con datos sueltos: nombre en billing + campos fiscales
			// como meta (aunque no se facture ni se guarde un cliente reutilizable).
			if ( '' !== $customer['name'] ) {
				$order->set_billing_first_name( $customer['name'] );
			}
			$order->add_meta_data( '_keytill_customer_name', $customer['name'], true );
			$order->add_meta_data( '_keytill_customer_doc', $customer['doc'], true );
			$order->add_meta_data( '_keytill_customer_iva', $customer['iva'], true );
		}

		// (El medio de pago se fija más abajo, cuando ya se conoce el total y el
		// posible canje de crédito.)

		// Marca POS: distingue estas órdenes de las de la web.
		$user = wp_get_current_user();
		$order->set_created_via( 'keytill' );
		$order->add_meta_data( '_keytill', 1, true );
		$order->add_meta_data( '_keytill_cajero_id', (int) $user->ID, true );
		$order->add_meta_data( '_keytill_cajero_login', $user->user_login, true );
		$order->add_meta_data( '_keytill_ts', current_time( 'mysql' ), true );
		if ( '' !== $idem ) {
			$order->add_meta_data( '_keytill_idempotency_key', $idem, true );
		}

		// Vendedor (opcional): etiqueta de quién atendió, para el reporte por
		// vendedor. Se guarda el id + el nombre "congelado" (preserva el histórico
		// si luego se renombra/quita el vendedor).
		$seller_id = isset( $params['seller_id'] ) ? sanitize_key( (string) $params['seller_id'] ) : '';
		if ( '' !== $seller_id && class_exists( 'KEYTILL_Sellers' ) ) {
			$srow = KEYTILL_Sellers::get( $seller_id );
			if ( $srow ) {
				$order->add_meta_data( '_keytill_seller_id', $seller_id, true );
				$order->add_meta_data( '_keytill_seller', $srow['name'], true );
			}
		}

		// Liga la venta a la sesión de caja abierta del cajero (para el arqueo).
		// El id se derivó del servidor más arriba, no del payload.
		$order->add_meta_data( '_keytill_session', $open_session_id, true );

		$order->calculate_totals();
		$total = (float) $order->get_total();

		// --- split de pago: crédito en tienda + resto por el medio elegido ---
		$credit_used   = 0.0;
		$credit_after  = null;
		if ( $credit_row ) {
			$credit_used = round( min( (float) $credit_row->balance, $total ), 2 );
		}
		$remainder = round( $total - $credit_used, 2 );

		// Si el crédito no cubre todo, hace falta un medio válido para el resto.
		if ( $remainder > 0 && ! isset( $methods[ $pm ] ) ) {
			$order->delete( true );
			return new WP_Error( 'keytill_bad_method', __( 'Choose a payment method for the remainder.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Medio de pago a registrar: el del resto; si no hay resto a cobrar, la venta
		// queda como "Crédito en tienda" (si usó crédito) o "Bonificado" (total $0 por
		// una bonificación del 100%, sin crédito ni cobro).
		if ( $remainder <= 0 ) {
			if ( $credit_used > 0 ) {
				$order->set_payment_method( 'credito' );
				$order->set_payment_method_title( __( 'Store credit', 'keytill' ) );
			} else {
				$order->set_payment_method( 'bonificado' );
				$order->set_payment_method_title( __( 'Comped (100%)', 'keytill' ) );
			}
		} else {
			$order->set_payment_method( $pm );
			$order->set_payment_method_title( $methods[ $pm ] );
		}

		// --- descuento de stock (lo hace el POS, no Woo) ---
		// Distribuido → descuenta la sucursal de origen (por sucursal); legacy →
		// stock único nativo. Si un distribuido se agotó por una carrera (otra caja
		// vendió lo último), se revierte lo ya descontado y se aborta.
		$stock_reduced = array();
		foreach ( $prepared as $pp ) {
			if ( ! empty( $pp['managed'] ) ) {
				$ok = KEYTILL_Branch_Stock::adjust( $pp['pid'], $pp['src'], -1 * (int) $pp['qty'], ! $pp['force'] );
				if ( ! $ok ) {
					self::rollback_stock( $stock_reduced );
					$order->delete( true );
					return new WP_Error(
						'keytill_branch_stock',
						/* translators: %s: producto. */
						sprintf( __( 'It just ran out of stock at this location: %s (another register sold it).', 'keytill' ), $pp['product']->get_name() ),
						array( 'status' => 409 )
					);
				}
			} elseif ( $pp['product'] instanceof WC_Product && $pp['product']->managing_stock() ) {
				wc_update_product_stock( $pp['product'], (int) $pp['qty'], 'decrease' );
			}
			$stock_reduced[] = $pp;
		}

		// Débito atómico del crédito ANTES de completar la venta. Si no se puede
		// (carrera: otro canje lo dejó sin saldo), se aborta la venta.
		if ( $credit_used > 0 ) {
			$credit_after = KEYTILL_Credit::debit( (int) $credit_row->id, $credit_used );
			if ( false === $credit_after ) {
				self::rollback_stock( $stock_reduced ); // reintegra el stock ya descontado
				$order->delete( true );
				return new WP_Error( 'keytill_credit_race', __( 'The credit no longer has enough balance.', 'keytill' ), array( 'status' => 409 ) );
			}
			$order->add_meta_data( '_keytill_credit_used', $credit_used, true );
			$order->add_meta_data( '_keytill_credit_code', $credit_row->code, true );
			$order->add_meta_data( '_keytill_credit_id', (int) $credit_row->id, true );
		}

		// Cobro: descuenta stock y marca pagada. Para venta de mostrador, completada.
		try {
			$order->payment_complete();
			if ( ! $order->has_status( 'completed' ) ) {
				$order->update_status( 'completed', __( 'Counter sale (POS).', 'keytill' ) );
			}
		} catch ( \Exception $e ) {
			// Reversa del crédito si la venta no llegó a concretarse.
			if ( $credit_used > 0 ) {
				KEYTILL_Credit::credit_back( (int) $credit_row->id, $credit_used );
			}
			self::rollback_stock( $stock_reduced );
			$order->delete( true );
			return new WP_Error( 'keytill_order_fail', __( 'The sale could not be completed.', 'keytill' ), array( 'status' => 500 ) );
		}

		// Registra el canje en el ledger del crédito (ya con el order_id).
		if ( $credit_used > 0 ) {
			KEYTILL_Credit::log(
				(int) $credit_row->id,
				'redeem',
				-1 * $credit_used,
				(float) $credit_after,
				array( 'order_id' => (int) $order->get_id(), 'channel' => 'pos', 'cajero_id' => (int) $user->ID )
			);
		}

		// Vuelto (solo efectivo, sobre el RESTO a cobrar; si se envió lo recibido).
		$change = null;
		if ( $remainder > 0 && 'efectivo' === $pm && isset( $params['amount_tendered'] ) ) {
			$tendered = (float) $params['amount_tendered'];
			if ( $tendered >= $remainder ) {
				$change = round( $tendered - $remainder, 2 );
				$order->add_meta_data( '_keytill_tendered', $tendered, true );
				$order->add_meta_data( '_keytill_change', $change, true );
				$order->save();
			}
		}

		// Gift cards vendidas: el hook de "orden pagada" ya emitió los códigos al
		// completar la venta. Se recargan de la orden para mostrarlos en el POS.
		$giftcards = KEYTILL_Credit_Product::codes_for_order( wc_get_order( $order->get_id() ) );

		self::bust_catalog_cache(); // stock fresco en la próxima precarga del POS

		return new WP_REST_Response(
			array(
				'order_id'     => (int) $order->get_id(),
				'number'       => $order->get_order_number(),
				'total'        => $total,
				'credit_used'  => $credit_used,
				'amount_due'   => $remainder,
				'change'       => $change,
				'giftcards'    => $giftcards,
				'print_url'    => KEYTILL_Print::print_url( $order->get_id() ),
				'gift_url'     => KEYTILL_Print::gift_url( $order->get_id() ),
			),
			201
		);
	}
}
