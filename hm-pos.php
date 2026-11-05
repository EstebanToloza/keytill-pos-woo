<?php
/**
 * Plugin Name:       HM Sport POS
 * Description:       Punto de venta de mostrador para HM Sport sobre el mismo WooCommerce de la tienda. Shortcode [hm_pos], pantalla completa, operado por teclado, para el rol cajero.
 * Version:           0.35.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            HM Sport
 * Text Domain:       hm-pos
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Constantes
 * ---------------------------------------------------------------------- */
define( 'HMPOS_VERSION', '0.35.2' );
define( 'HMPOS_FILE', __FILE__ );
define( 'HMPOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'HMPOS_URL', plugin_dir_url( __FILE__ ) );

/** Capability que habilita operar el POS (vender, devoluciones, caja, clientes). */
define( 'HMPOS_CAP', 'hmpos_operate' );

/** Capability de gestión (editar productos/stock/precios, acciones administrativas). */
define( 'HMPOS_CAP_MANAGE', 'hmpos_manage' );

/** Slug del template de página en blanco a pantalla completa. */
define( 'HMPOS_TEMPLATE', 'hmpos-fullscreen' );

/* -------------------------------------------------------------------------
 * Activación / desactivación
 * ---------------------------------------------------------------------- */

/**
 * En activación: crea el rol cajero con la capability del POS, se la da también
 * al administrador, y refresca los rewrites.
 */
function hmpos_activate() {
	hmpos_register_roles();
	require_once HMPOS_DIR . 'includes/credito/class-hmpos-credit.php';
	HMPOS_Credit::activate(); // crea tablas del crédito + programa el cron de vencimiento
	require_once HMPOS_DIR . 'includes/class-hmpos-branches.php';
	require_once HMPOS_DIR . 'includes/class-hmpos-branch-stock.php';
	HMPOS_Branch_Stock::create_table(); // tabla de stock por sucursal
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'hmpos_activate' );

/**
 * En desactivación: refresca rewrites y limpia el cron del crédito. El rol, la
 * capability, las opciones y las tablas del crédito se conservan a propósito
 * (no hay desinstalación destructiva): así reactivar el plugin no rompe páginas
 * ya configuradas ni pierde saldos/gift cards emitidos. Si algún día se quisiera
 * un borrado total, iría en un uninstall.php dedicado.
 */
function hmpos_deactivate() {
	require_once HMPOS_DIR . 'includes/credito/class-hmpos-credit.php';
	HMPOS_Credit::deactivate();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'hmpos_deactivate' );

/**
 * Registra el rol `cajero` + capability `hmpos_operate`.
 * También se la asigna al rol administrator. Idempotente.
 */
function hmpos_register_roles() {
	// Rol cajero: vender/atender (operate), sin gestión.
	// `read_private_products` deja ver/vender en el POS los productos de test
	// (privados/ocultos), sin exponerlos en la web.
	add_role(
		'cajero',
		__( 'Cajero', 'hm-pos' ),
		array(
			'read'                  => true,
			HMPOS_CAP               => true,
			'read_private_products' => true,
		)
	);

	// Rol encargado: opera + gestiona (productos/stock/precios, admin del POS).
	// `upload_files` habilita subir la imagen del producto desde el editor del POS.
	add_role(
		'encargado',
		__( 'Encargado', 'hm-pos' ),
		array(
			'read'                  => true,
			HMPOS_CAP               => true,
			HMPOS_CAP_MANAGE        => true,
			'upload_files'          => true,
			'read_private_products' => true,
		)
	);

	// Asegura las capabilities (por si los roles ya existían):
	// operate → cajero, encargado, administrator; manage → encargado, administrator.
	foreach ( array( 'cajero', 'encargado', 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && ! $role->has_cap( HMPOS_CAP ) ) {
			$role->add_cap( HMPOS_CAP );
		}
	}
	// manage + upload_files → encargado, administrator (el admin ya suele tenerlas).
	foreach ( array( 'encargado', 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		if ( ! $role->has_cap( HMPOS_CAP_MANAGE ) ) {
			$role->add_cap( HMPOS_CAP_MANAGE );
		}
		if ( ! $role->has_cap( 'upload_files' ) ) {
			$role->add_cap( 'upload_files' );
		}
	}
	// read_private_products → cajero, encargado, administrator (ver productos de
	// test privados/ocultos en el POS).
	foreach ( array( 'cajero', 'encargado', 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && ! $role->has_cap( 'read_private_products' ) ) {
			$role->add_cap( 'read_private_products' );
		}
	}
}

/**
 * Migración idempotente de roles/capabilities para instalaciones ya activas
 * (la activación solo corre al (re)activar el plugin). Corre una vez por versión.
 */
function hmpos_maybe_upgrade_roles() {
	if ( '4' === get_option( 'hmpos_roles_version' ) ) {
		return;
	}
	hmpos_register_roles();
	update_option( 'hmpos_roles_version', '4' );
}
add_action( 'init', 'hmpos_maybe_upgrade_roles' );

/* -------------------------------------------------------------------------
 * Compatibilidad con WooCommerce HPOS (custom order tables)
 * ---------------------------------------------------------------------- */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				HMPOS_FILE,
				true
			);
		}
	}
);

/* -------------------------------------------------------------------------
 * Dependencia de WooCommerce
 * ---------------------------------------------------------------------- */
add_action(
	'admin_init',
	function () {
		if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'HM Sport POS necesita WooCommerce activo para funcionar.', 'hm-pos' );
					echo '</p></div>';
				}
			);
		}
	}
);

/* -------------------------------------------------------------------------
 * API REST (namespace hmpos/v1)
 * ---------------------------------------------------------------------- */
require_once HMPOS_DIR . 'includes/credito/class-hmpos-credit.php';
HMPOS_Credit::init();

// Canje del crédito en el checkout web (Fase 2): cupón virtual + reserva atómica.
require_once HMPOS_DIR . 'includes/credito/class-hmpos-credit-web.php';
HMPOS_Credit_Web::init();

// Gift cards vendidas como producto (Fase 3): emisión al pagarse la orden.
require_once HMPOS_DIR . 'includes/credito/class-hmpos-credit-product.php';
HMPOS_Credit_Product::init();

require_once HMPOS_DIR . 'includes/class-hmpos-cash.php';
HMPOS_Cash::init();

// Caja digital (cuenta bancaria única): registro global con saldo corriente.
require_once HMPOS_DIR . 'includes/class-hmpos-cash-digital.php';
HMPOS_Cash_Digital::init();

require_once HMPOS_DIR . 'includes/class-hmpos-customers.php';
HMPOS_Customers::init();

require_once HMPOS_DIR . 'includes/class-hmpos-print.php';
HMPOS_Print::init();

require_once HMPOS_DIR . 'includes/class-hmpos-labels.php';
HMPOS_Labels::init();

require_once HMPOS_DIR . 'includes/class-hmpos-product-admin.php';
HMPOS_Product_Admin::init();

require_once HMPOS_DIR . 'includes/class-hmpos-manual.php';
HMPOS_Manual::init();

require_once HMPOS_DIR . 'includes/class-hmpos-rest.php';
HMPOS_REST::init();

require_once HMPOS_DIR . 'includes/class-hmpos-settings.php';
HMPOS_Settings::init();

require_once HMPOS_DIR . 'includes/class-hmpos-sellers.php';
HMPOS_Sellers::init();

require_once HMPOS_DIR . 'includes/class-hmpos-branches.php';
HMPOS_Branches::init();

require_once HMPOS_DIR . 'includes/class-hmpos-branch-stock.php';
HMPOS_Branch_Stock::init();

// Decodificador del código de barras (utilitario, sin hooks).
require_once HMPOS_DIR . 'includes/class-hmpos-barcode.php';

require_once HMPOS_DIR . 'includes/class-hmpos-export.php';
HMPOS_Export::init();

/* -------------------------------------------------------------------------
 * WP-CLI: comandos `wp hmpos ...`
 * ---------------------------------------------------------------------- */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HMPOS_DIR . 'includes/class-hmpos-cli.php';
	WP_CLI::add_command( 'hmpos seed-demo', array( 'HMPOS_CLI', 'seed_demo' ) );
}

/* -------------------------------------------------------------------------
 * Shortcode [hm_pos] — contenedor del POS con gate por rol
 * ---------------------------------------------------------------------- */
add_shortcode( 'hm_pos', 'hmpos_render_shortcode' );

/**
 * Renderiza el contenedor del POS. Gate: solo usuarios con la capability
 * `hmpos_operate` ven el POS; el resto ve un aviso.
 *
 * @return string HTML.
 */
function hmpos_render_shortcode() {
	if ( ! is_user_logged_in() || ! current_user_can( HMPOS_CAP ) ) {
		return hmpos_render_gate_notice();
	}

	ob_start();
	include HMPOS_DIR . 'templates/pos-app.php';
	return ob_get_clean();
}

/**
 * Aviso para quien no tiene permiso de operar el POS.
 *
 * @return string HTML.
 */
function hmpos_render_gate_notice() {
	$login_url = wp_login_url( get_permalink() );

	ob_start();
	?>
	<div class="hmpos-gate" role="alert">
		<div class="hmpos-gate__card">
			<span class="hmpos-gate__mark">HM<small>SPORT</small></span>
			<h1 class="hmpos-gate__title"><?php esc_html_e( 'Acceso restringido', 'hm-pos' ); ?></h1>
			<p class="hmpos-gate__msg">
				<?php esc_html_e( 'Este punto de venta es solo para cajeros. Iniciá sesión con un usuario autorizado.', 'hm-pos' ); ?>
			</p>
			<?php if ( ! is_user_logged_in() ) : ?>
				<a class="hmpos-gate__btn" href="<?php echo esc_url( $login_url ); ?>">
					<?php esc_html_e( 'Iniciar sesión', 'hm-pos' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Template de página en blanco a pantalla completa
 * ---------------------------------------------------------------------- */

/**
 * Agrega el template a la lista de templates de página seleccionables.
 *
 * @param array $templates Templates existentes (slug => nombre).
 * @return array
 */
function hmpos_register_page_template( $templates ) {
	$templates[ HMPOS_TEMPLATE ] = __( 'HM POS — Pantalla completa', 'hm-pos' );
	return $templates;
}
add_filter( 'theme_page_templates', 'hmpos_register_page_template' );

/**
 * ¿Estamos en la pantalla del POS? True si la página usa nuestro template
 * o si el contenido tiene el shortcode [hm_pos].
 *
 * @return bool
 */
function hmpos_is_pos_screen() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	if ( HMPOS_TEMPLATE === get_page_template_slug( $post ) ) {
		return true;
	}

	return has_shortcode( $post->post_content, 'hm_pos' );
}

/**
 * Fuerza nuestro template en blanco cuando corresponde.
 *
 * @param string $template Ruta del template resuelto.
 * @return string
 */
function hmpos_template_include( $template ) {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && HMPOS_TEMPLATE === get_page_template_slug( $post ) ) {
			return HMPOS_DIR . 'templates/blank-fullscreen.php';
		}
	}
	return $template;
}
add_filter( 'template_include', 'hmpos_template_include' );

/**
 * Oculta la barra de administración de WordPress en la pantalla del POS.
 * (Belt-and-suspenders: el template en blanco ya llama show_admin_bar(false).)
 */
add_filter(
	'show_admin_bar',
	function ( $show ) {
		return hmpos_is_pos_screen() ? false : $show;
	}
);

/* -------------------------------------------------------------------------
 * Privacidad de la pantalla del POS: ni indexable ni pública
 * ---------------------------------------------------------------------- */

/**
 * IDs de las páginas del POS (las que usan el template a pantalla completa).
 *
 * @return int[]
 */
function hmpos_pos_page_ids() {
	$ids = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => HMPOS_TEMPLATE,       // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => true,
		)
	);
	return array_map( 'intval', (array) $ids );
}

/**
 * No indexar la pantalla del POS (noindex/nofollow). Lo respetan WP core,
 * Yoast y RankMath (todos escuchan el filtro wp_robots).
 */
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( hmpos_is_pos_screen() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
		}
		return $robots;
	}
);

/** Excluir las páginas del POS del sitemap de WordPress. */
add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}
		$ids = hmpos_pos_page_ids();
		if ( $ids ) {
			$prev                 = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_merge( $prev, $ids );
		}
		return $args;
	},
	10,
	2
);

/**
 * La pantalla del POS no es pública: un visitante sin permiso de operar
 * (`hmpos_operate`) y sin sesión iniciada se redirige al login (y vuelve al
 * POS tras loguearse). Al staff autorizado no lo toca; a un usuario logueado
 * sin permiso le sigue apareciendo el aviso de acceso del shortcode.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! hmpos_is_pos_screen() || current_user_can( HMPOS_CAP ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			$dest = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
			wp_safe_redirect( wp_login_url( $dest ) );
			exit;
		}
	},
	5
);

/**
 * La pantalla del POS NUNCA debe cachearse. Es una página dinámica por-usuario:
 * su HTML incluye el nonce REST (`wpApiSettings.nonce`), atado a la sesión y al
 * usuario. Si un caché de página (LiteSpeed, WP Rocket, W3TC, etc.) sirve una
 * copia guardada, entrega un nonce viejo/ajeno y toda escritura falla al instante
 * con «Ha fallado la comprobación de la cookie» (rest_cookie_invalid_nonce), sin
 * esperar a que caduque nada. Acá marcamos la página como no-cacheable de forma
 * agnóstica (constante DONOTCACHEPAGE, honrada por casi todos) + la señal propia
 * de LiteSpeed. Complementa el refresco de nonce del front (doble red).
 */
add_action(
	'template_redirect',
	function () {
		if ( ! hmpos_is_pos_screen() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// LiteSpeed Cache: pedir explícitamente no cachear esta respuesta.
		do_action( 'litespeed_control_set_nocache', 'HM POS: pantalla dinámica por-usuario' );
		nocache_headers();
	},
	1
);

/* -------------------------------------------------------------------------
 * Encolado de assets — SOLO en la pantalla del POS
 * ---------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', 'hmpos_enqueue_assets' );

/**
 * Versión de un asset propio para el cache-busting: usa la fecha de modificación
 * del archivo (filemtime) para que cualquier cambio invalide la copia del
 * navegador al instante, sin depender de subir HMPOS_VERSION en cada edición.
 * Cae a HMPOS_VERSION si no se puede leer el archivo.
 *
 * @param string $rel Ruta relativa dentro del plugin (ej. 'assets/js/pos.js').
 * @return string|int
 */
function hmpos_asset_ver( $rel ) {
	$path = HMPOS_DIR . ltrim( $rel, '/' );
	$mt   = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	// Combina versión del plugin + filemtime: así invalida la caché tanto al subir
	// una versión nueva como al editar un archivo suelto.
	return $mt ? HMPOS_VERSION . '.' . $mt : HMPOS_VERSION;
}

/**
 * Encola tokens.css, pos.css, Alpine (vendorizado local) y pos.js únicamente
 * en la pantalla del POS. Localiza el nonce REST para pos.js.
 */
function hmpos_enqueue_assets() {
	if ( ! hmpos_is_pos_screen() ) {
		return;
	}

	wp_enqueue_style(
		'hmpos-tokens',
		HMPOS_URL . 'assets/css/tokens.css',
		array(),
		hmpos_asset_ver( 'assets/css/tokens.css' )
	);

	wp_enqueue_style(
		'hmpos-pos',
		HMPOS_URL . 'assets/css/pos.css',
		array( 'hmpos-tokens' ),
		hmpos_asset_ver( 'assets/css/pos.css' )
	);

	// Alpine vendorizado local (NO CDN). defer para que corra tras parsear el DOM.
	wp_enqueue_script(
		'hmpos-alpine',
		HMPOS_URL . 'assets/js/alpine.min.js',
		array(),
		'3.14.1',
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_enqueue_script(
		'hmpos-app',
		HMPOS_URL . 'assets/js/pos.js',
		array(),
		hmpos_asset_ver( 'assets/js/pos.js' ),
		array( 'in_footer' => true )
	);

	// Pestaña Pedidos de la consola (componente Alpine independiente).
	wp_enqueue_script(
		'hmpos-orders',
		HMPOS_URL . 'assets/js/pos-orders.js',
		array( 'hmpos-app' ),
		hmpos_asset_ver( 'assets/js/pos-orders.js' ),
		array( 'in_footer' => true )
	);

	// Pestaña Productos de la consola (componente Alpine independiente).
	wp_enqueue_script(
		'hmpos-products',
		HMPOS_URL . 'assets/js/pos-products.js',
		array( 'hmpos-app' ),
		hmpos_asset_ver( 'assets/js/pos-products.js' ),
		array( 'in_footer' => true )
	);

	// Pestaña Clientes de la consola (componente Alpine independiente).
	wp_enqueue_script(
		'hmpos-customers',
		HMPOS_URL . 'assets/js/pos-customers.js',
		array( 'hmpos-app' ),
		hmpos_asset_ver( 'assets/js/pos-customers.js' ),
		array( 'in_footer' => true )
	);

	// Pestaña Caja/Banco (componente Alpine independiente).
	wp_enqueue_script(
		'hmpos-cashbank',
		HMPOS_URL . 'assets/js/pos-cashbank.js',
		array( 'hmpos-app' ),
		hmpos_asset_ver( 'assets/js/pos-cashbank.js' ),
		array( 'in_footer' => true )
	);

	// Nonce + raíz REST para las llamadas de Alpine (mismo origen).
	wp_localize_script(
		'hmpos-app',
		'wpApiSettings',
		array(
			'root'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Config del shell: permisos de gestión para habilitar acciones en las pestañas.
	wp_localize_script(
		'hmpos-app',
		'hmposConfig',
		array(
			'canManage'    => current_user_can( HMPOS_CAP_MANAGE ),
			'methods'      => HMPOS_Settings::payment_methods_front(),
			'giftcardMax'  => HMPOS_Settings::giftcard_max(),
			'labelsBatch'  => HMPOS_Labels::batch_url(),
			'labelsNonce'  => HMPOS_Labels::batch_nonce(),
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'zxingUrl'     => add_query_arg( 'ver', hmpos_asset_ver( 'assets/js/vendor/zxing.min.js' ), HMPOS_URL . 'assets/js/vendor/zxing.min.js' ),
			'sellers'      => HMPOS_Sellers::front(),
			'branches'     => HMPOS_Branches::front(),
			'ordersExport' => HMPOS_Export::url(),
		)
	);
}

/**
 * Blindaje de estilos: la pantalla del POS es un canvas dedicado, pero al vivir
 * en una página del sitio el tema y los page builders (Elementor, etc.) encolan
 * igual su CSS global vía wp_head y se filtra sobre inputs/botones/selects/tablas
 * del POS. Acá quitamos ese CSS externo SOLO en la pantalla del POS, conservando
 * los estilos del plugin (handles con prefijo «hmpos», que además traen las
 * fuentes por @import). No toca scripts. Prioridad tardía para correr después de
 * que todos hayan encolado.
 */
function hmpos_shield_styles() {
	if ( ! hmpos_is_pos_screen() ) {
		return;
	}
	if ( empty( $GLOBALS['wp_styles'] ) || ! ( $GLOBALS['wp_styles'] instanceof WP_Styles ) ) {
		return;
	}
	foreach ( (array) $GLOBALS['wp_styles']->queue as $handle ) {
		if ( 0 === strpos( (string) $handle, 'hmpos' ) ) {
			continue; // nuestros estilos (tokens + pos, con las fuentes)
		}
		wp_dequeue_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'hmpos_shield_styles', 100 );

/**
 * Red de seguridad al imprimir: suprime la etiqueta <link> de cualquier estilo
 * externo que se haya encolado DESPUÉS del desencolado (p. ej. WooCommerce Blocks,
 * o assets que algún plugin agrega tarde). Solo en la pantalla del POS y solo para
 * handles que no sean del plugin.
 *
 * @param string $tag    HTML de la etiqueta <link>.
 * @param string $handle Handle del estilo.
 * @return string Tag original, o '' para suprimirlo.
 */
function hmpos_shield_style_tag( $tag, $handle ) {
	if ( ! hmpos_is_pos_screen() ) {
		return $tag;
	}
	if ( 0 === strpos( (string) $handle, 'hmpos' ) ) {
		return $tag;
	}
	return '';
}
add_filter( 'style_loader_tag', 'hmpos_shield_style_tag', 10, 2 );
