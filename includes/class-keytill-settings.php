<?php
/**
 * Ajustes del POS + menú unificado "Keytill".
 *
 * Centraliza la configuración del plugin (datos del negocio para el comprobante,
 * vigencia del crédito, medios de pago, etiqueta de la caja) y arma un único menú
 * de admin "Keytill" con submenús (Inicio · Ajustes · Cajas · Créditos · Manual),
 * reemplazando los menús top-level sueltos.
 *
 * Fuente única de configuración: opciones prefijadas `keytill_`. Todo tiene defaults
 * sensatos → el plugin funciona sin tocar nada.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Settings {

	/** Opción con los datos generales (comprobante + etiqueta de caja). */
	const OPTION = 'keytill_settings';

	/** Opción con los medios de pago no-efectivo (lista de etiquetas). */
	const METHODS_OPTION = 'keytill_payment_methods';

	/** Opción con los atributos (taxonomías) a mostrar en las etiquetas. */
	const LABEL_ATTRS_OPTION = 'keytill_label_attributes';

	/** Slug del menú padre. */
	const MENU = 'keytill';

	/**
	 * Defaults de los datos generales. `business_name` vacío = usa el nombre del sitio.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'business_name'    => '',
			'business_tax_id'  => '',
			'business_address' => '',
			'business_phone'   => '',
			'business_logo'    => 0,
			'receipt_subtitle' => __( 'Purchase summary', 'keytill' ),
			'receipt_footer'   => __( 'Not valid as an invoice. Thank you for your purchase!', 'keytill' ),
			'register_label'   => __( 'REGISTER 01', 'keytill' ),
			'exchange_days'    => 30, // validez del ticket de cambio (0 = sin plazo)
			'tax_status_field' => 0,  // mostrar el campo "condicion fiscal" del cliente
			'delete_data'      => 0,  // borrar datos al DESINSTALAR (ver uninstall.php)
		);
	}

	/**
	 * Medios de pago no-efectivo por defecto (además de Efectivo, que es fijo).
	 *
	 * Traducibles: es lo que ve quien instala y no configura nada, así que en un
	 * sitio en inglés tienen que salir en inglés. Una vez que el comercio los
	 * edita quedan como datos suyos y ya no se traducen, que es lo correcto.
	 */
	public static function default_methods() {
		return array( __( 'Card', 'keytill' ), __( 'Bank transfer', 'keytill' ), __( 'QR', 'keytill' ) );
	}

	/**
	 * Lee un dato general con su default.
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	public static function get( $key ) {
		$opt = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $opt[ $key ] ) ? $opt[ $key ] : '';
	}

	/**
	 * Datos del negocio para el comprobante (con el nombre resuelto al del sitio si está vacío).
	 *
	 * @return array
	 */
	public static function business() {
		$name = self::get( 'business_name' );
		return array(
			'name'     => '' !== $name ? $name : get_bloginfo( 'name' ),
			'tax_id'   => self::get( 'business_tax_id' ),
			'address'  => self::get( 'business_address' ),
			'phone'    => self::get( 'business_phone' ),
			'subtitle' => self::get( 'receipt_subtitle' ),
			'footer'   => self::get( 'receipt_footer' ),
			'logo'     => (int) self::get( 'business_logo' ),
		);
	}

	/** Etiqueta de la caja (ej. "REGISTER 01"). */
	public static function register_label() {
		$v = self::get( 'register_label' );
		return '' !== $v ? $v : __( 'REGISTER 01', 'keytill' );
	}

	/** Tope del monto de una gift card de monto libre en el POS. 0 = sin tope. */
	public static function giftcard_max() {
		return max( 0, (int) get_option( 'keytill_giftcard_max', 0 ) );
	}

	/**
	 * Atributos (nombres de taxonomía, ej. `pa_talle`, `pa_color`) a mostrar en las
	 * etiquetas de producto. La opción no guardada (null) significa «mostrar todos
	 * los atributos de la variación»: no se pierde información hasta que se configure.
	 * Un array vacío guardado también se interpreta como «todos».
	 *
	 * @return string[]|null Lista de taxonomías, o null para «todos».
	 */
	public static function label_attributes() {
		$v = get_option( self::LABEL_ATTRS_OPTION, null );
		if ( ! is_array( $v ) || empty( $v ) ) {
			return null;
		}
		return array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
	}

	/**
	 * Medios de pago como mapa id => etiqueta. Efectivo siempre primero (es el
	 * medio especial: cajón, vuelto y efectivo esperado del arqueo). El resto son
	 * etiquetas de registro (el cobro real se hace afuera: posnet, transferencia,
	 * app de MP). El id se deriva de la etiqueta (sanitize_title), así los defaults
	 * mantienen los ids históricos (tarjeta/transferencia/qr).
	 *
	 * @return array<string,string>
	 */
	public static function payment_methods() {
		$labels = get_option( self::METHODS_OPTION, null );
		if ( ! is_array( $labels ) ) {
			$labels = self::default_methods();
		}
		$methods = array( 'efectivo' => __( 'Cash', 'keytill' ) );
		foreach ( $labels as $label ) {
			$label = trim( (string) $label );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_title( $label );
			if ( '' === $id || 'efectivo' === $id || 'credito' === $id || isset( $methods[ $id ] ) ) {
				continue; // ids reservados / duplicados
			}
			$methods[ $id ] = $label;
		}
		return $methods;
	}

	/**
	 * Medios de pago para el front: [{key,id,name}] con teclas 1..N (Efectivo = 1).
	 * Acotado a 9 para que la tecla numérica funcione.
	 *
	 * @return array
	 */
	public static function payment_methods_front() {
		$out = array();
		$key = 1;
		foreach ( self::payment_methods() as $id => $label ) {
			if ( $key > 9 ) {
				break;
			}
			$out[] = array(
				'key'  => (string) $key,
				'id'   => $id,
				'name' => $label,
			);
			++$key;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Ciclo de vida
	 * ------------------------------------------------------------------ */

	public static function init() {
		// Menú padre temprano (prioridad 9) para que los submenús y el CPT de cajas
		// se puedan colgar de él.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/**
	 * Assets de la pantalla de Ajustes: selector de medios (logo) del comprobante.
	 *
	 * @param string $hook Hook suffix de la pantalla admin actual.
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'keytill-settings' ) ) {
			return;
		}
		wp_enqueue_media();
		// Handle vacío con deps: el inline JS se imprime después de jQuery + media.
		wp_register_script( 'keytill-settings', '', array( 'jquery', 'media-editor' ), KEYTILL_VERSION, true );
		wp_enqueue_script( 'keytill-settings' );
		$js = '(function($){var frame;' .
			"$('#keytill_logo_pick').on('click',function(e){e.preventDefault();" .
			'if(frame){frame.open();return;}' .
			'frame=wp.media({title:' . wp_json_encode( __( 'Choose logo', 'keytill' ) ) . ",library:{type:'image'},multiple:false,button:{text:" . wp_json_encode( __( 'Use as logo', 'keytill' ) ) . '}});' .
			"frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();" .
			'var url=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;' .
			"$('#keytill_business_logo').val(a.id);" .
			"$('#keytill_logo_preview').show().find('img').attr('src',url);" .
			"$('#keytill_logo_clear').show();});frame.open();});" .
			"$('#keytill_logo_clear').on('click',function(e){e.preventDefault();" .
			"$('#keytill_business_logo').val('');$('#keytill_logo_preview').hide();$(this).hide();});" .
			'})(jQuery);';
		wp_add_inline_script( 'keytill-settings', $js );
	}

	/** Registra el menú padre "Keytill" + submenús propios (Inicio, Ajustes). */
	public static function register_menu() {
		add_menu_page(
			__( 'Keytill', 'keytill' ),
			__( 'Keytill', 'keytill' ),
			KEYTILL_CAP, // operate: cajero/encargado/gerente/admin ven el menú (Inicio)
			self::MENU,
			array( __CLASS__, 'render_home' ),
			'dashicons-store',
			'56.7'
		);
		// El primer submenú lo genera WP automáticamente (etiqueta "Keytill" → Inicio).
		add_submenu_page(
			self::MENU,
			__( 'POS settings', 'keytill' ),
			__( 'Settings', 'keytill' ),
			'manage_woocommerce',
			'keytill-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/** Registra las opciones + secciones/campos (Settings API). */
	public static function register_settings() {
		register_setting( 'keytill_settings_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
		register_setting(
			'keytill_settings_group',
			'keytill_credit_expiry_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					return max( 0, (int) $v );
				},
			)
		);
		register_setting(
			'keytill_settings_group',
			'keytill_giftcard_max',
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					return max( 0, (int) $v );
				},
			)
		);
		register_setting( 'keytill_settings_group', self::METHODS_OPTION, array( __CLASS__, 'sanitize_methods' ) );
		register_setting( 'keytill_settings_group', self::LABEL_ATTRS_OPTION, array( __CLASS__, 'sanitize_label_attributes' ) );
	}

	/** Sanitiza la lista de atributos de etiqueta → array de nombres de taxonomía. */
	public static function sanitize_label_attributes( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $tax ) {
			$tax = sanitize_key( (string) $tax );
			if ( '' !== $tax && taxonomy_exists( $tax ) && ! in_array( $tax, $out, true ) ) {
				$out[] = $tax;
			}
		}
		return $out;
	}

	/** Sanitiza los datos generales. */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = array();
		$out['business_name']    = sanitize_text_field( $input['business_name'] ?? '' );
		$out['business_tax_id']  = sanitize_text_field( $input['business_tax_id'] ?? '' );
		$out['business_address'] = sanitize_text_field( $input['business_address'] ?? '' );
		$out['business_phone']   = sanitize_text_field( $input['business_phone'] ?? '' );
		$out['business_logo']    = absint( $input['business_logo'] ?? 0 );
		$out['receipt_subtitle'] = sanitize_text_field( $input['receipt_subtitle'] ?? $d['receipt_subtitle'] );
		$out['receipt_footer']   = sanitize_text_field( $input['receipt_footer'] ?? $d['receipt_footer'] );
		$out['register_label']   = sanitize_text_field( $input['register_label'] ?? $d['register_label'] );
		$out['exchange_days']    = max( 0, (int) ( $input['exchange_days'] ?? $d['exchange_days'] ) );
		$out['tax_status_field'] = empty( $input['tax_status_field'] ) ? 0 : 1;
		$out['delete_data']      = empty( $input['delete_data'] ) ? 0 : 1;
		return $out;
	}

	/** Sanitiza los medios de pago (textarea, uno por línea) → array de etiquetas. */
	public static function sanitize_methods( $input ) {
		if ( is_array( $input ) ) {
			$lines = $input;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $input );
		}
		$out  = array();
		$seen = array();
		foreach ( $lines as $line ) {
			$label = sanitize_text_field( trim( (string) $line ) );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_title( $label );
			// Efectivo es fijo; ids reservados o duplicados se descartan.
			if ( '' === $id || 'efectivo' === $id || 'credito' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $label;
			if ( count( $out ) >= 8 ) {
				break; // tope: efectivo + 8 = 9 teclas
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Pantallas
	 * ------------------------------------------------------------------ */

	/** Inicio: atajo a la pantalla del POS + estado. Visible para operadores. */
	public static function render_home() {
		$pos_url = self::pos_page_url();
		$open    = class_exists( 'KEYTILL_Cash' ) ? KEYTILL_Cash::get_open_session_id() : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Keytill', 'keytill' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Counter point of sale for WooCommerce.', 'keytill' ); ?></p>

			<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px">
				<div class="card" style="padding:16px 20px;max-width:420px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Open the POS', 'keytill' ); ?></h2>
					<?php if ( $pos_url ) : ?>
						<p><a class="button button-primary button-hero" href="<?php echo esc_url( $pos_url ); ?>"><?php esc_html_e( 'Open sales screen', 'keytill' ); ?></a></p>
						<p class="description"><code><?php echo esc_html( $pos_url ); ?></code></p>
					<?php else : ?>
						<p><?php esc_html_e( 'There is no page with the [keytill] shortcode yet. Create a page, paste the [keytill] shortcode and assign the «Keytill — Full screen» template.', 'keytill' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card" style="padding:16px 20px;max-width:420px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Status', 'keytill' ); ?></h2>
					<p>
						<?php esc_html_e( 'Register:', 'keytill' ); ?>
						<strong><?php /* translators: %d: id of the open register session. */ echo $open ? esc_html( sprintf( __( 'open (#%d)', 'keytill' ), $open ) ) : esc_html__( 'closed', 'keytill' ); ?></strong>
					</p>
					<p><?php esc_html_e( 'Version:', 'keytill' ); ?> <code><?php echo esc_html( KEYTILL_VERSION ); ?></code></p>
					<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=keytill-settings' ) ); ?>"><?php esc_html_e( 'POS settings', 'keytill' ); ?> →</a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/** Ajustes (gerencia). */
	public static function render_settings() {
		$methods = get_option( self::METHODS_OPTION, null );
		if ( ! is_array( $methods ) ) {
			$methods = self::default_methods();
		}
		$methods_text = implode( "\n", $methods );
		$b            = self::defaults();
		$opt          = wp_parse_args( (array) get_option( self::OPTION, array() ), $b );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'POS settings', 'keytill' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'keytill_settings_group' ); ?>

				<h2><?php esc_html_e( 'Business details (receipt)', 'keytill' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Header and footer of the internal receipt. If the name is empty, the site name is used.', 'keytill' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="keytill_business_name"><?php esc_html_e( 'Business name', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_name]" id="keytill_business_name" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_business_tax_id"><?php esc_html_e( 'Business tax ID', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_tax_id]" id="keytill_business_tax_id" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_tax_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_business_address"><?php esc_html_e( 'Address', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_address]" id="keytill_business_address" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_address'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_business_phone"><?php esc_html_e( 'Phone', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_phone]" id="keytill_business_phone" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_phone'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'keytill' ); ?></th>
						<td>
							<?php
							$logo_id  = (int) $opt['business_logo'];
							$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[business_logo]" id="keytill_business_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
							<div id="keytill_logo_preview" style="margin-bottom:8px;<?php echo $logo_src ? '' : 'display:none'; ?>">
								<img src="<?php echo esc_url( $logo_src ); ?>" alt="" style="max-width:180px;max-height:90px;height:auto;border:1px solid #ccd0d4;padding:4px;background:#fff" />
							</div>
							<button type="button" class="button" id="keytill_logo_pick"><?php esc_html_e( 'Choose logo', 'keytill' ); ?></button>
							<button type="button" class="button" id="keytill_logo_clear" style="<?php echo $logo_id ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'keytill' ); ?></button>
							<p class="description"><?php esc_html_e( 'Shown in the receipt header. Recommended: horizontal PNG/JPG.', 'keytill' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_receipt_subtitle"><?php esc_html_e( 'Receipt subtitle', 'keytill' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[receipt_subtitle]" id="keytill_receipt_subtitle" type="text" class="regular-text" value="<?php echo esc_attr( $opt['receipt_subtitle'] ); ?>" placeholder="<?php esc_attr_e( 'Purchase summary', 'keytill' ); ?>" />
							<p class="description"><?php esc_html_e( 'Below the business name, in the header.', 'keytill' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_receipt_footer"><?php esc_html_e( 'Footer text', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[receipt_footer]" id="keytill_receipt_footer" type="text" class="large-text" value="<?php echo esc_attr( $opt['receipt_footer'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_exchange_days">Validez del ticket de cambio (dias)</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[exchange_days]" id="keytill_exchange_days" type="number" min="0" class="small-text" value="<?php echo esc_attr( $opt['exchange_days'] ); ?>" />
							<p class="description">Dias para cambiar presentando el ticket de cambio. 0 = sin plazo.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer tax status', 'keytill' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[tax_status_field]" value="1" <?php checked( ! empty( $opt['tax_status_field'] ) ); ?> />
								<?php esc_html_e( 'Show the «Tax status» field on the customer record', 'keytill' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Disabled by default. Enable it if your country requires it (e.g. VAT status). It is a free-text field; an extension can provide a list with the keytill_fiscal_options filter.', 'keytill' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'keytill' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delete_data]" value="1" <?php checked( ! empty( $opt['delete_data'] ) ); ?> />
								<?php esc_html_e( 'Delete all Keytill data when the plugin is uninstalled', 'keytill' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default: uninstalling keeps your register history and the balance of any gift card still in circulation, so reinstalling loses nothing. Turn this on only if you want a clean removal — it permanently deletes settings, locations, salespeople, register sessions and every store credit. Deactivating the plugin never deletes anything.', 'keytill' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payment methods', 'keytill' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'One per line (in addition to Cash, which is fixed). The POS only records the chosen method for the register count — the actual payment happens elsewhere (card terminal, bank transfer, app). You can rename or add (e.g. «PayPal», «Debit card»). Max. 8.', 'keytill' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="keytill_methods"><?php esc_html_e( 'Methods (non-cash)', 'keytill' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( self::METHODS_OPTION ); ?>" id="keytill_methods" rows="6" class="regular-text" placeholder="Tarjeta&#10;Transferencia&#10;Mercado Pago"><?php echo esc_textarea( $methods_text ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Product labels', 'keytill' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Attributes printed on variation labels, as «Property: Value» (e.g. «Size: XL»). If you select none, all variation attributes are shown.', 'keytill' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Attributes to show', 'keytill' ); ?></th>
						<td>
							<?php
							$attrs    = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
							$selected = (array) get_option( self::LABEL_ATTRS_OPTION, array() );
							if ( empty( $attrs ) ) :
								?>
								<p class="description"><?php esc_html_e( 'No global product attributes are defined.', 'keytill' ); ?></p>
							<?php else : ?>
								<fieldset>
									<?php foreach ( $attrs as $attr ) : ?>
										<?php $tax = wc_attribute_taxonomy_name( $attr->attribute_name ); ?>
										<label style="display:inline-block;min-width:200px;margin:0 0 6px">
											<input type="checkbox" name="<?php echo esc_attr( self::LABEL_ATTRS_OPTION ); ?>[]" value="<?php echo esc_attr( $tax ); ?>" <?php checked( in_array( $tax, $selected, true ) ); ?> />
											<?php echo esc_html( $attr->attribute_label ); ?>
											<code style="opacity:.6"><?php echo esc_html( $tax ); ?></code>
										</label><br />
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Store credit and gift cards', 'keytill' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="keytill_credit_expiry_days"><?php esc_html_e( 'Default validity (days)', 'keytill' ); ?></label></th>
						<td>
							<input name="keytill_credit_expiry_days" id="keytill_credit_expiry_days" type="number" min="0" step="1" value="<?php echo esc_attr( (int) get_option( 'keytill_credit_expiry_days', 30 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 = never expires. Applies to new refund credits and gift cards.', 'keytill' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="keytill_giftcard_max"><?php esc_html_e( 'Gift card limit (open amount)', 'keytill' ); ?></label></th>
						<td>
							<input name="keytill_giftcard_max" id="keytill_giftcard_max" type="number" min="0" step="1" value="<?php echo esc_attr( self::giftcard_max() ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 = no limit. Maximum amount a cashier can load on an open-amount gift card at the POS.', 'keytill' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'General', 'keytill' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="keytill_register_label"><?php esc_html_e( 'Register label', 'keytill' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[register_label]" id="keytill_register_label" type="text" class="regular-text" value="<?php echo esc_attr( $opt['register_label'] ); ?>" placeholder="<?php echo esc_attr__( 'REGISTER 01', 'keytill' ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** URL de la primera página que tenga el shortcode [keytill], o '' si no hay. */
	public static function pos_page_url() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[keytill]',
				'fields'         => 'ids',
			)
		);
		return $pages ? get_permalink( $pages[0] ) : '';
	}
}
