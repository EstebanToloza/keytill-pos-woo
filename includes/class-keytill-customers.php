<?php
/**
 * Clientes del POS = clientes de WooCommerce (usuarios WP con rol `customer`),
 * unificados con la tienda web. Alta y selección desde el mostrador.
 *
 * Reglas:
 * - Opcional: una venta puede ser anónima (sin cliente).
 * - Para GUARDAR un cliente reutilizable hace falta al menos EMAIL o DOCUMENTO.
 * - Deduplicación por email Y por DNI: si alguno ya existe, no se crea uno
 *   nuevo; se devuelve el existente para seleccionarlo.
 * - Condición fiscal = lista fija (default "Consumidor Final").
 *
 * IMPORTANTE (requisito del negocio): las altas de cliente y las órdenes del
 * POS NO mandan email. Los emails de WooCommerce son solo para la tienda web.
 * - Alta: se usa wp_insert_user() directo (no wc_create_new_customer()), que
 *   no dispara el email de "cuenta nueva".
 * - Órdenes: se filtran los emails de WooCommerce para órdenes created_via=keytill.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Customers {

	const NS = 'keytill/v1';

	/** Meta de usuario: documento tal como se tipeó (display) y normalizado (dedup). */
	const META_DOC      = 'keytill_doc';
	const META_DOC_NORM = 'keytill_doc_norm';
	/** Meta de usuario: condición fiscal (una etiqueta de la lista fija). */
	const META_IVA = 'keytill_iva';
	/** Meta de usuario: fecha de nacimiento (Y-m-d). El teléfono va en billing_phone. */
	const META_BIRTH = 'keytill_birthdate';

	/**
	 * Condiciones fiscales admitidas.
	 *
	 * Vacío por defecto: el campo es de TEXTO LIBRE, así sirve en cualquier país.
	 * Un comercio o una extensión regional puede definir su propia lista con el
	 * filtro `keytill_fiscal_options` (la primera opción pasa a ser el default).
	 *
	 * @return string[]
	 */
	public static function fiscal_options() {
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'keytill_fiscal_options', array() ) ) ) );
	}

	/**
	 * Engancha rutas REST y la supresión de emails del POS.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		self::register_email_suppression();
	}

	/* ================================================================== *
	 * Supresión de emails para el POS (órdenes created_via=keytill)
	 * ================================================================== */

	/**
	 * Desactiva todos los emails de WooCommerce basados en la orden cuando la
	 * orden fue creada por el POS. Las órdenes web conservan sus emails.
	 */
	public static function register_email_suppression() {
		$order_emails = array(
			'new_order',                 // aviso al admin
			'cancelled_order',
			'failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
		);
		foreach ( $order_emails as $id ) {
			add_filter( "woocommerce_email_enabled_{$id}", array( __CLASS__, 'suppress_pos_order_email' ), 10, 2 );
		}
	}

	/**
	 * Devuelve false (email apagado) si el objeto es una orden del POS.
	 *
	 * @param bool  $enabled Estado actual.
	 * @param mixed $object  Orden/refund/cliente según el email.
	 * @return bool
	 */
	public static function suppress_pos_order_email( $enabled, $object ) {
		if ( $object instanceof WC_Abstract_Order && 'keytill' === $object->get_created_via() ) {
			return false;
		}
		// Refund: su created_via puede venir vacío; mirar la orden padre.
		if ( $object instanceof WC_Order_Refund ) {
			$parent = wc_get_order( $object->get_parent_id() );
			if ( $parent && 'keytill' === $parent->get_created_via() ) {
				return false;
			}
		}
		return $enabled;
	}

	/* ================================================================== *
	 * Rutas REST
	 * ================================================================== */

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/customers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_search' ),
					'permission_callback' => array( 'KEYTILL_REST', 'can_operate' ),
					'args'                => array(
						'q' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_create' ),
					'permission_callback' => array( 'KEYTILL_REST', 'can_operate' ),
				),
			)
		);

		// Consola · Clientes: listado paginado.
		register_rest_route(
			self::NS,
			'/customers/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( 'KEYTILL_REST', 'can_operate' ),
			)
		);

		// Consola · Clientes: detalle (con historial) + edición.
		register_rest_route(
			self::NS,
			'/customers/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_detail' ),
					'permission_callback' => array( 'KEYTILL_REST', 'can_operate' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'rest_update' ),
					'permission_callback' => array( 'KEYTILL_REST', 'can_operate' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
	}

	/**
	 * GET /customers/list — listado paginado con búsqueda. Query: page, per_page,
	 * search (texto o DNI). → { items, total, pages, page, per_page }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_list( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;
		$search   = trim( (string) $request->get_param( 'search' ) );

		$args = array(
			'role__in'    => array( 'customer' ),
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
			'fields'      => 'ID',
		);

		if ( '' !== $search ) {
			$only_digits = preg_replace( '/\D+/', '', $search );
			$is_doc      = ( '' !== $only_digits && ! preg_match( '/[a-zA-Z@]/', $search ) );
			if ( $is_doc ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => self::META_DOC_NORM, 'value' => $only_digits, 'compare' => 'LIKE' ),
				);
			} else {
				$args['search']         = '*' . $search . '*';
				$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
			}
		}

		$query = new WP_User_Query( $args );
		$ids   = (array) $query->get_results();
		$total = (int) $query->get_total();
		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		$items = array();
		foreach ( $ids as $id ) {
			$items[] = self::list_row( (int) $id );
		}

		return new WP_REST_Response(
			array( 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per_page ),
			200
		);
	}

	/**
	 * GET /customers/{id} — detalle con estadísticas e historial de compras.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_detail( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$user = get_userdata( $id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return new WP_Error( 'keytill_not_found', __( 'Customer not found.', 'keytill' ), array( 'status' => 404 ) );
		}
		$payload             = self::payload( $id );
		$payload['stats']    = self::stats( $id );
		$payload['orders']   = self::customer_orders( $id );
		$payload['fiscal']   = self::fiscal_options();
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * PATCH /customers/{id} — edita nombre/email/DNI/condición fiscal. Deduplica
	 * contra otros clientes (email y DNI). No dispara emails.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_update( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$data = array(
			'name'  => isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '',
			'email' => isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'doc'   => isset( $params['doc'] ) ? sanitize_text_field( $params['doc'] ) : '',
			'iva'   => isset( $params['iva'] ) ? sanitize_text_field( $params['iva'] ) : '',
		);
		// Solo se tocan si el request los trae (edición parcial).
		if ( isset( $params['phone'] ) ) {
			$data['phone'] = sanitize_text_field( $params['phone'] );
		}
		if ( isset( $params['birthdate'] ) ) {
			$data['birthdate'] = sanitize_text_field( $params['birthdate'] );
		}
		return self::update( absint( $request->get_param( 'id' ) ), $data );
	}

	/**
	 * Actualiza un cliente existente con deduplicación (excluye a sí mismo).
	 *
	 * @param int   $id   Cliente.
	 * @param array $data { name, email, doc, iva }
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $id, array $data ) {
		$id   = (int) $id;
		$user = get_userdata( $id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return new WP_Error( 'keytill_not_found', __( 'Customer not found.', 'keytill' ), array( 'status' => 404 ) );
		}

		$name  = trim( (string) ( $data['name'] ?? '' ) );
		$email = trim( (string) ( $data['email'] ?? '' ) );
		$doc   = trim( (string) ( $data['doc'] ?? '' ) );
		$iva   = self::sanitize_iva( $data['iva'] ?? '' );
		$norm  = self::normalize_doc( $doc );

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'keytill_bad_email', __( 'The email is not valid.', 'keytill' ), array( 'status' => 400 ) );
		}
		if ( '' === $email && '' === $norm ) {
			return new WP_Error( 'keytill_need_id', __( 'The customer needs at least an email or a tax ID.', 'keytill' ), array( 'status' => 400 ) );
		}

		// Deduplicación contra OTROS clientes.
		if ( '' !== $email ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing && (int) $existing->ID !== $id ) {
				return self::conflict( $existing->ID, 'email', $norm );
			}
		}
		if ( '' !== $norm ) {
			$found = get_users(
				array(
					'meta_key'   => self::META_DOC_NORM,
					'meta_value' => $norm,
					'exclude'    => array( $id ),
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			if ( ! empty( $found ) ) {
				return self::conflict( (int) $found[0], 'doc', '', $email );
			}
		}

		// Actualiza el usuario SIN mandar emails (el POS nunca notifica).
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'send_password_change_email', '__return_false' );
		$res = wp_update_user(
			array(
				'ID'           => $id,
				'user_email'   => $email,
				'first_name'   => $name,
				'display_name' => '' !== $name ? $name : $user->user_login,
			)
		);
		remove_filter( 'send_email_change_email', '__return_false' );
		remove_filter( 'send_password_change_email', '__return_false' );

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'keytill_update_fail', $res->get_error_message(), array( 'status' => 500 ) );
		}

		update_user_meta( $id, self::META_DOC, $doc );
		update_user_meta( $id, self::META_DOC_NORM, $norm );
		update_user_meta( $id, self::META_IVA, $iva );
		update_user_meta( $id, 'billing_first_name', $name );
		update_user_meta( $id, 'billing_email', $email );
		// Teléfono y fecha de nacimiento: solo si vienen en el request (edición
		// parcial). '' explícito los limpia.
		if ( array_key_exists( 'phone', $data ) ) {
			update_user_meta( $id, 'billing_phone', sanitize_text_field( $data['phone'] ) );
		}
		if ( array_key_exists( 'birthdate', $data ) ) {
			update_user_meta( $id, self::META_BIRTH, self::sanitize_birthdate( $data['birthdate'] ) );
		}

		$payload           = self::payload( $id );
		$payload['stats']  = self::stats( $id );
		$payload['orders'] = self::customer_orders( $id );
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Fila resumida de un cliente para el listado (payload + estadísticas).
	 *
	 * @param int $uid Cliente.
	 * @return array
	 */
	private static function list_row( $uid ) {
		$row          = self::payload( $uid );
		$row['stats'] = self::stats( $uid );
		return $row;
	}

	/**
	 * Estadísticas de compra de un cliente.
	 *
	 * @param int $uid Cliente.
	 * @return array { orders_count, total_spent, total_spent_f }
	 */
	private static function stats( $uid ) {
		$uid   = (int) $uid;
		$count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $uid ) : 0;
		$spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $uid ) : 0.0;
		return array(
			'orders_count' => $count,
			'total_spent'  => $spent,
			'total_spent_f' => html_entity_decode( wp_strip_all_tags( wc_price( $spent ) ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * Historial de compras (hasta 20, más recientes) de un cliente.
	 *
	 * @param int $uid Cliente.
	 * @return array[]
	 */
	private static function customer_orders( $uid ) {
		$orders = wc_get_orders(
			array(
				'customer' => (int) $uid,
				'limit'    => 20,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'type'     => 'shop_order',
			)
		);
		$out = array();
		foreach ( $orders as $o ) {
			if ( ! $o instanceof WC_Order ) {
				continue;
			}
			$out[] = array(
				'id'           => (int) $o->get_id(),
				'number'       => $o->get_order_number(),
				'date'         => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd/m/Y' ) : '',
				'total_f'      => html_entity_decode( wp_strip_all_tags( wc_price( $o->get_total(), array( 'currency' => $o->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
				'status'       => $o->get_status(),
				'status_label' => wc_get_order_status_name( $o->get_status() ),
				'origin'       => $o->get_meta( '_keytill' ) ? 'pos' : 'web',
				'print_url'    => 'pos' === ( $o->get_meta( '_keytill' ) ? 'pos' : 'web' ) ? KEYTILL_Print::print_url( $o->get_id() ) : '',
			);
		}
		return $out;
	}

	/**
	 * GET /customers?q= — busca por nombre, email o DNI. Máx 10.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_search( WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		return new WP_REST_Response( self::search( $q ), 200 );
	}

	/**
	 * POST /customers — crea un cliente con deduplicación.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$data = array(
			'name'      => isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '',
			'email'     => isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'doc'       => isset( $params['doc'] ) ? sanitize_text_field( $params['doc'] ) : '',
			'iva'       => isset( $params['iva'] ) ? sanitize_text_field( $params['iva'] ) : '',
			'phone'     => isset( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '',
			'birthdate' => isset( $params['birthdate'] ) ? sanitize_text_field( $params['birthdate'] ) : '',
		);
		return self::create( $data );
	}

	/* ================================================================== *
	 * Búsqueda
	 * ================================================================== */

	/**
	 * Busca clientes por nombre/email (texto) y por DNI (normalizado), unificando.
	 *
	 * @param string $q Término.
	 * @return array Lista de payloads.
	 */
	public static function search( $q ) {
		if ( '' === $q ) {
			return array();
		}
		$ids = array();

		// 1) Texto: login, email, display name, nicename.
		$by_text = new WP_User_Query(
			array(
				'search'         => '*' . esc_attr( $q ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'role__in'       => array( 'customer' ),
				'number'         => 10,
				'fields'         => 'ID',
			)
		);
		$ids = array_merge( $ids, (array) $by_text->get_results() );

		// 2) Documento normalizado (alfanumígitos): match por prefijo.
		$norm = self::normalize_doc( $q );
		if ( '' !== $norm ) {
			$by_doc = new WP_User_Query(
				array(
					'meta_key'     => self::META_DOC_NORM,
					'meta_value'   => $norm,
					'meta_compare' => 'LIKE',
					'role__in'     => array( 'customer' ),
					'number'       => 10,
					'fields'       => 'ID',
				)
			);
			$ids = array_merge( $ids, (array) $by_doc->get_results() );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$ids = array_slice( $ids, 0, 10 );

		$out = array();
		foreach ( $ids as $id ) {
			$out[] = self::payload( $id );
		}
		return $out;
	}

	/* ================================================================== *
	 * Alta con deduplicación
	 * ================================================================== */

	/**
	 * Crea un cliente. Requiere al menos email o documento. Deduplica por ambos.
	 *
	 * @param array $data { name, email, doc, iva }
	 * @return WP_REST_Response|WP_Error 201 con payload, o 409 con el existente.
	 */
	public static function create( array $data ) {
		$name  = trim( (string) ( $data['name'] ?? '' ) );
		$email = trim( (string) ( $data['email'] ?? '' ) );
		$doc   = trim( (string) ( $data['doc'] ?? '' ) );
		$iva   = self::sanitize_iva( $data['iva'] ?? '' );
		$norm  = self::normalize_doc( $doc );

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'keytill_bad_email', __( 'The email is not valid.', 'keytill' ), array( 'status' => 400 ) );
		}
		if ( '' === $email && '' === $norm ) {
			return new WP_Error( 'keytill_need_id', __( 'To save a customer, enter at least an email or a tax ID.', 'keytill' ), array( 'status' => 400 ) );
		}

		// --- Deduplicación ---
		if ( '' !== $email ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				return self::conflict( $existing->ID, 'email', $norm );
			}
		}
		if ( '' !== $norm ) {
			$found = get_users(
				array(
					'meta_key'   => self::META_DOC_NORM,
					'meta_value' => $norm,
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			if ( ! empty( $found ) ) {
				return self::conflict( (int) $found[0], 'doc', '', $email );
			}
		}

		// --- Alta (wp_insert_user directo: NO manda email de cuenta nueva) ---
		$login = self::unique_login( $email, $norm, $name );
		$uid   = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'user_email'   => $email, // puede ir vacío (cliente solo-DNI)
				'first_name'   => $name,
				'display_name' => '' !== $name ? $name : $login,
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'keytill_create_fail', $uid->get_error_message(), array( 'status' => 500 ) );
		}

		// Meta propia + billing (para que se vea completo en Woo → Clientes).
		update_user_meta( $uid, self::META_DOC, $doc );
		update_user_meta( $uid, self::META_DOC_NORM, $norm );
		update_user_meta( $uid, self::META_IVA, $iva );
		if ( '' !== $name ) {
			update_user_meta( $uid, 'billing_first_name', $name );
		}
		if ( '' !== $email ) {
			update_user_meta( $uid, 'billing_email', $email );
		}
		$phone = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';
		if ( '' !== $phone ) {
			update_user_meta( $uid, 'billing_phone', $phone );
		}
		$birth = self::sanitize_birthdate( $data['birthdate'] ?? '' );
		if ( '' !== $birth ) {
			update_user_meta( $uid, self::META_BIRTH, $birth );
		}

		return new WP_REST_Response( self::payload( $uid ), 201 );
	}

	/**
	 * Respuesta 409 de conflicto: devuelve el cliente existente para seleccionar,
	 * indicando por qué campo matcheó y si el otro campo difiere.
	 *
	 * @param int    $uid          Cliente existente.
	 * @param string $matched      'email' | 'doc'.
	 * @param string $other_norm   DNI normalizado tipeado (para avisar si difiere).
	 * @param string $other_email  Email tipeado (para avisar si difiere).
	 * @return WP_Error
	 */
	private static function conflict( $uid, $matched, $other_norm = '', $other_email = '' ) {
		$payload = self::payload( $uid );
		$mismatch = '';
		if ( 'email' === $matched && '' !== $other_norm && $payload['doc_norm'] !== $other_norm ) {
			$mismatch = __( 'The tax ID does not match the one on file for that customer.', 'keytill' );
		}
		if ( 'doc' === $matched && '' !== $other_email && strtolower( $payload['email'] ) !== strtolower( $other_email ) ) {
			$mismatch = __( 'The email does not match the one on file for that customer.', 'keytill' );
		}
		$msg = ( 'email' === $matched )
			? __( 'A customer with that email already exists.', 'keytill' )
			: __( 'A customer with that tax ID already exists.', 'keytill' );
		return new WP_Error(
			'keytill_customer_exists',
			trim( $msg . ' ' . $mismatch ),
			array(
				'status'   => 409,
				'matched'  => $matched,
				'mismatch' => $mismatch,
				'customer' => $payload,
			)
		);
	}

	/* ================================================================== *
	 * Adjuntar a una orden
	 * ================================================================== */

	/**
	 * Adjunta un cliente a la orden: liga el usuario y copia nombre/email/doc/iva
	 * al billing y a la meta que lee el comprobante.
	 *
	 * @param WC_Order $order       Orden.
	 * @param int      $customer_id Cliente.
	 * @return bool true si se adjuntó.
	 */
	public static function attach_to_order( $order, $customer_id ) {
		$customer_id = (int) $customer_id;
		$user        = get_userdata( $customer_id );
		if ( ! $user || ! $order instanceof WC_Order ) {
			return false;
		}
		$p = self::payload( $customer_id );

		$order->set_customer_id( $customer_id );
		if ( '' !== $p['name'] ) {
			$order->set_billing_first_name( $p['name'] );
		}
		if ( '' !== $p['email'] ) {
			$order->set_billing_email( $p['email'] );
		}
		// Meta que ya usa el comprobante y el flujo fiscal.
		$order->update_meta_data( '_keytill_customer_id', $customer_id );
		$order->update_meta_data( '_keytill_customer_name', $p['name'] );
		$order->update_meta_data( '_keytill_customer_doc', $p['doc'] );
		$order->update_meta_data( '_keytill_customer_iva', $p['iva'] );
		return true;
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	/**
	 * Payload público de un cliente.
	 *
	 * @param int $uid Usuario.
	 * @return array
	 */
	public static function payload( $uid ) {
		$uid  = (int) $uid;
		$user = get_userdata( $uid );
		$name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
		if ( '' === $name && $user ) {
			$name = $user->display_name;
		}
		return array(
			'id'       => $uid,
			'name'     => $name,
			'email'    => $user ? $user->user_email : '',
			'doc'      => (string) get_user_meta( $uid, self::META_DOC, true ),
			'doc_norm' => (string) get_user_meta( $uid, self::META_DOC_NORM, true ),
			'iva'      => self::sanitize_iva( get_user_meta( $uid, self::META_IVA, true ) ),
			'phone'    => (string) get_user_meta( $uid, 'billing_phone', true ),
			'birthdate' => (string) get_user_meta( $uid, self::META_BIRTH, true ),
		);
	}

	/** Valida/normaliza una fecha de nacimiento: devuelve 'Y-m-d' válido o ''. */
	public static function sanitize_birthdate( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
			return '';
		}
		$t = strtotime( $s );
		return $t ? gmdate( 'Y-m-d', $t ) : '';
	}

	/**
	 * Normaliza un documento a solo dígitos (para dedup y búsqueda).
	 *
	 * @param string $doc Documento.
	 * @return string
	 */
	public static function normalize_doc( $doc ) {
		// Identificador fiscal global (DNI, CUIT, RFC, NIF, VAT…): se normaliza a
		// alfanumérico en MAYÚSCULAS para comparar sin espacios, puntos ni guiones.
		// (No se puede filtrar solo dígitos: fuera de AR suelen llevar letras.)
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $doc ) );
	}

	/**
	 * Devuelve una condición fiscal válida (o el default si no está en la lista).
	 *
	 * @param string $iva Valor.
	 * @return string
	 */
	public static function sanitize_iva( $iva ) {
		$iva     = sanitize_text_field( trim( (string) $iva ) );
		$options = self::fiscal_options();
		if ( empty( $options ) ) {
			return $iva; // sin lista definida: texto libre (default global).
		}
		return in_array( $iva, $options, true ) ? $iva : $options[0];
	}

	/**
	 * Genera un user_login único a partir del email, el DNI o el nombre.
	 *
	 * @param string $email Email.
	 * @param string $norm  DNI normalizado.
	 * @param string $name  Nombre.
	 * @return string
	 */
	private static function unique_login( $email, $norm, $name ) {
		if ( '' !== $email ) {
			$base = sanitize_user( current( explode( '@', $email ) ), true );
		} elseif ( '' !== $norm ) {
			$base = 'cli' . $norm;
		} else {
			$base = sanitize_user( $name, true );
		}
		$base = $base ? $base : 'cliente';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '-' . $i;
			$i++;
		}
		return $login;
	}
}
