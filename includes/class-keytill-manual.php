<?php
/**
 * Manual de operatoria de mostrador. Vive dentro del plugin (no depende de
 * ningún servicio externo) y se sirve de dos maneras:
 *
 * 1) Página autocontenida en el front (como el comprobante): ruta
 *    `?keytill_manual=1`, resuelta en `template_redirect` (render + exit, sin
 *    el theme). Gate: capability del cajero (o quien administre WooCommerce).
 * 2) Ítem de menú en el admin ("Manual (POS)") que la muestra embebida, para
 *    que sea fácil de encontrar. Además, la barra del POS enlaza a la página.
 *
 * El contenido está en templates/manual.php.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manual de mostrador.
 */
class KEYTILL_Manual {

	/** Query var que dispara el render de la página. */
	const QV = 'keytill_manual';

	/**
	 * Engancha el render en el front y el menú en el admin.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
	}

	/**
	 * ¿Quién puede ver el manual? El cajero (opera el POS) o quien administre
	 * WooCommerce (encargado/admin).
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( KEYTILL_CAP ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * URL de la página del manual.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( self::QV, '1', home_url( '/' ) );
	}

	/**
	 * Render de la página autocontenida cuando corresponde la query var.
	 * Gate por capability; si no, corta con 403.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET[ self::QV ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! self::can_view() ) {
			wp_die(
				esc_html__( 'No tenés permiso para ver el manual.', 'keytill' ),
				esc_html__( 'Sin permiso', 'keytill' ),
				array( 'response' => 403 )
			);
		}

		nocache_headers();
		$template = KEYTILL_DIR . 'templates/manual.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
		exit;
	}

	/**
	 * Registra el menú de admin "Manual (POS)" (visible para el cajero).
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'keytill',
			__( 'Manual de mostrador', 'keytill' ),
			__( 'Manual', 'keytill' ),
			KEYTILL_CAP,
			'keytill-manual',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Pantalla de admin: muestra el manual embebido en un iframe a pantalla
	 * (mantiene el diseño autocontenido intacto) + un enlace para abrirlo aparte.
	 */
	public static function render_admin_page() {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'Sin permiso.', 'keytill' ) );
		}
		$url = self::url();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
				<?php esc_html_e( 'Manual de mostrador', 'keytill' ); ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="button button-secondary">
					<?php esc_html_e( 'Abrir en pestaña nueva', 'keytill' ); ?> ↗
				</a>
			</h1>
			<p class="description"><?php esc_html_e( 'Guía de operatoria del POS. Se puede imprimir (Ctrl/Cmd + P) para dejar una copia en la caja.', 'keytill' ); ?></p>
			<iframe
				src="<?php echo esc_url( $url ); ?>"
				title="<?php esc_attr_e( 'Manual de mostrador', 'keytill' ); ?>"
				style="width:100%;height:calc(100vh - 180px);min-height:520px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;margin-top:8px"
			></iframe>
		</div>
		<?php
	}
}
