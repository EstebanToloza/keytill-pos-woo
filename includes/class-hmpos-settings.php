<?php
/**
 * Ajustes del POS + menú unificado "HM POS".
 *
 * Centraliza la configuración del plugin (datos del negocio para el comprobante,
 * vigencia del crédito, medios de pago, etiqueta de la caja) y arma un único menú
 * de admin "HM POS" con submenús (Inicio · Ajustes · Cajas · Créditos · Manual),
 * reemplazando los menús top-level sueltos.
 *
 * Fuente única de configuración: opciones prefijadas `hmpos_`. Todo tiene defaults
 * sensatos → el plugin funciona sin tocar nada.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

class HMPOS_Settings {

	/** Opción con los datos generales (comprobante + etiqueta de caja). */
	const OPTION = 'hmpos_settings';

	/** Opción con los medios de pago no-efectivo (lista de etiquetas). */
	const METHODS_OPTION = 'hmpos_payment_methods';

	/** Opción con los atributos (taxonomías) a mostrar en las etiquetas. */
	const LABEL_ATTRS_OPTION = 'hmpos_label_attributes';

	/** Slug del menú padre. */
	const MENU = 'hmpos';

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
			'receipt_subtitle' => __( 'Resumen de compra', 'hm-pos' ),
			'receipt_footer'   => __( 'Documento no válido como factura. ¡Gracias por su compra!', 'hm-pos' ),
			'register_label'   => __( 'CAJA 01', 'hm-pos' ),
			'exchange_days'    => 30, // validez del ticket de cambio (0 = sin plazo)
		);
	}

	/** Medios de pago no-efectivo por defecto (además de Efectivo, que es fijo). */
	public static function default_methods() {
		return array( 'Tarjeta', 'Transferencia', 'QR' );
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

	/** Etiqueta de la caja (ej. "CAJA 01"). */
	public static function register_label() {
		$v = self::get( 'register_label' );
		return '' !== $v ? $v : __( 'CAJA 01', 'hm-pos' );
	}

	/** Tope del monto de una gift card de monto libre en el POS. 0 = sin tope. */
	public static function giftcard_max() {
		return max( 0, (int) get_option( 'hmpos_giftcard_max', 0 ) );
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
		$methods = array( 'efectivo' => __( 'Efectivo', 'hm-pos' ) );
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
			$key++;
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
		if ( false === strpos( (string) $hook, 'hmpos-settings' ) ) {
			return;
		}
		wp_enqueue_media();
		// Handle vacío con deps: el inline JS se imprime después de jQuery + media.
		wp_register_script( 'hmpos-settings', '', array( 'jquery', 'media-editor' ), HMPOS_VERSION, true );
		wp_enqueue_script( 'hmpos-settings' );
		$js = "(function($){var frame;" .
			"$('#hmpos_logo_pick').on('click',function(e){e.preventDefault();" .
			"if(frame){frame.open();return;}" .
			"frame=wp.media({title:" . wp_json_encode( __( 'Elegir logo', 'hm-pos' ) ) . ",library:{type:'image'},multiple:false,button:{text:" . wp_json_encode( __( 'Usar como logo', 'hm-pos' ) ) . "}});" .
			"frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();" .
			"var url=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;" .
			"$('#hmpos_business_logo').val(a.id);" .
			"$('#hmpos_logo_preview').show().find('img').attr('src',url);" .
			"$('#hmpos_logo_clear').show();});frame.open();});" .
			"$('#hmpos_logo_clear').on('click',function(e){e.preventDefault();" .
			"$('#hmpos_business_logo').val('');$('#hmpos_logo_preview').hide();$(this).hide();});" .
			"})(jQuery);";
		wp_add_inline_script( 'hmpos-settings', $js );
	}

	/** Registra el menú padre "HM POS" + submenús propios (Inicio, Ajustes). */
	public static function register_menu() {
		add_menu_page(
			__( 'HM POS', 'hm-pos' ),
			__( 'HM POS', 'hm-pos' ),
			HMPOS_CAP, // operate: cajero/encargado/gerente/admin ven el menú (Inicio)
			self::MENU,
			array( __CLASS__, 'render_home' ),
			'dashicons-store',
			'56.7'
		);
		// El primer submenú lo genera WP automáticamente (etiqueta "HM POS" → Inicio).
		add_submenu_page(
			self::MENU,
			__( 'Ajustes del POS', 'hm-pos' ),
			__( 'Ajustes', 'hm-pos' ),
			'manage_woocommerce',
			'hmpos-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/** Registra las opciones + secciones/campos (Settings API). */
	public static function register_settings() {
		register_setting( 'hmpos_settings_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
		register_setting(
			'hmpos_settings_group',
			'hmpos_credit_expiry_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					return max( 0, (int) $v );
				},
			)
		);
		register_setting(
			'hmpos_settings_group',
			'hmpos_giftcard_max',
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					return max( 0, (int) $v );
				},
			)
		);
		register_setting( 'hmpos_settings_group', self::METHODS_OPTION, array( __CLASS__, 'sanitize_methods' ) );
		register_setting( 'hmpos_settings_group', self::LABEL_ATTRS_OPTION, array( __CLASS__, 'sanitize_label_attributes' ) );
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
		$open    = class_exists( 'HMPOS_Cash' ) ? HMPOS_Cash::get_open_session_id() : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HM POS', 'hm-pos' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Punto de venta de mostrador sobre WooCommerce.', 'hm-pos' ); ?></p>

			<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px">
				<div class="card" style="padding:16px 20px;max-width:420px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Abrir el POS', 'hm-pos' ); ?></h2>
					<?php if ( $pos_url ) : ?>
						<p><a class="button button-primary button-hero" href="<?php echo esc_url( $pos_url ); ?>"><?php esc_html_e( 'Abrir pantalla de venta', 'hm-pos' ); ?></a></p>
						<p class="description"><code><?php echo esc_html( $pos_url ); ?></code></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Todavía no hay una página con el shortcode [hm_pos]. Creá una página, pegá el shortcode [hm_pos] y asignale el template «HM POS — Pantalla completa».', 'hm-pos' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card" style="padding:16px 20px;max-width:420px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Estado', 'hm-pos' ); ?></h2>
					<p>
						<?php esc_html_e( 'Caja:', 'hm-pos' ); ?>
						<strong><?php echo $open ? esc_html( sprintf( __( 'abierta (#%d)', 'hm-pos' ), $open ) ) : esc_html__( 'cerrada', 'hm-pos' ); ?></strong>
					</p>
					<p><?php esc_html_e( 'Versión:', 'hm-pos' ); ?> <code><?php echo esc_html( HMPOS_VERSION ); ?></code></p>
					<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=hmpos-settings' ) ); ?>"><?php esc_html_e( 'Ajustes del POS', 'hm-pos' ); ?> →</a></p>
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
			<h1><?php esc_html_e( 'Ajustes del POS', 'hm-pos' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'hmpos_settings_group' ); ?>

				<h2><?php esc_html_e( 'Datos del negocio (comprobante)', 'hm-pos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Encabezado y pie del comprobante interno. Si el nombre queda vacío se usa el nombre del sitio.', 'hm-pos' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmpos_business_name"><?php esc_html_e( 'Nombre del negocio', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_name]" id="hmpos_business_name" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_business_tax_id"><?php esc_html_e( 'CUIT / ID fiscal', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_tax_id]" id="hmpos_business_tax_id" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_tax_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_business_address"><?php esc_html_e( 'Dirección', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_address]" id="hmpos_business_address" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_address'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_business_phone"><?php esc_html_e( 'Teléfono', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[business_phone]" id="hmpos_business_phone" type="text" class="regular-text" value="<?php echo esc_attr( $opt['business_phone'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'hm-pos' ); ?></th>
						<td>
							<?php
							$logo_id  = (int) $opt['business_logo'];
							$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[business_logo]" id="hmpos_business_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
							<div id="hmpos_logo_preview" style="margin-bottom:8px;<?php echo $logo_src ? '' : 'display:none'; ?>">
								<img src="<?php echo esc_url( $logo_src ); ?>" alt="" style="max-width:180px;max-height:90px;height:auto;border:1px solid #ccd0d4;padding:4px;background:#fff" />
							</div>
							<button type="button" class="button" id="hmpos_logo_pick"><?php esc_html_e( 'Elegir logo', 'hm-pos' ); ?></button>
							<button type="button" class="button" id="hmpos_logo_clear" style="<?php echo $logo_id ? '' : 'display:none'; ?>"><?php esc_html_e( 'Quitar', 'hm-pos' ); ?></button>
							<p class="description"><?php esc_html_e( 'Aparece en el encabezado del comprobante. Recomendado: PNG/JPG horizontal.', 'hm-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_receipt_subtitle"><?php esc_html_e( 'Subtítulo del comprobante', 'hm-pos' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[receipt_subtitle]" id="hmpos_receipt_subtitle" type="text" class="regular-text" value="<?php echo esc_attr( $opt['receipt_subtitle'] ); ?>" placeholder="<?php esc_attr_e( 'Resumen de compra', 'hm-pos' ); ?>" />
							<p class="description"><?php esc_html_e( 'Bajo el nombre del negocio, en el encabezado.', 'hm-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_receipt_footer"><?php esc_html_e( 'Leyenda al pie', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[receipt_footer]" id="hmpos_receipt_footer" type="text" class="large-text" value="<?php echo esc_attr( $opt['receipt_footer'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_exchange_days">Validez del ticket de cambio (dias)</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[exchange_days]" id="hmpos_exchange_days" type="number" min="0" class="small-text" value="<?php echo esc_attr( $opt['exchange_days'] ); ?>" />
							<p class="description">Dias para cambiar presentando el ticket de cambio. 0 = sin plazo.</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Medios de pago', 'hm-pos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Uno por línea (además de Efectivo, que es fijo). El POS solo registra el medio elegido para el arqueo — el cobro real se hace afuera (posnet, transferencia, app). Podés renombrar o agregar (ej. «Mercado Pago», «Tarjeta débito»). Máx. 8.', 'hm-pos' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmpos_methods"><?php esc_html_e( 'Medios (no-efectivo)', 'hm-pos' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( self::METHODS_OPTION ); ?>" id="hmpos_methods" rows="6" class="regular-text" placeholder="Tarjeta&#10;Transferencia&#10;Mercado Pago"><?php echo esc_textarea( $methods_text ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Etiquetas de producto', 'hm-pos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Atributos que se imprimen en las etiquetas de las variaciones, en formato «Propiedad: Valor» (ej. «Talle: XL»). Si no marcás ninguno se muestran todos los atributos de la variación.', 'hm-pos' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Atributos a mostrar', 'hm-pos' ); ?></th>
						<td>
							<?php
							$attrs    = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
							$selected = (array) get_option( self::LABEL_ATTRS_OPTION, array() );
							if ( empty( $attrs ) ) :
								?>
								<p class="description"><?php esc_html_e( 'No hay atributos globales de producto definidos.', 'hm-pos' ); ?></p>
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

				<h2><?php esc_html_e( 'Crédito en tienda y gift cards', 'hm-pos' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmpos_credit_expiry_days"><?php esc_html_e( 'Vigencia por defecto (días)', 'hm-pos' ); ?></label></th>
						<td>
							<input name="hmpos_credit_expiry_days" id="hmpos_credit_expiry_days" type="number" min="0" step="1" value="<?php echo esc_attr( (int) get_option( 'hmpos_credit_expiry_days', 30 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 = sin vencimiento. Se aplica a los créditos de devolución y gift cards nuevos.', 'hm-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hmpos_giftcard_max"><?php esc_html_e( 'Tope de gift card (monto libre)', 'hm-pos' ); ?></label></th>
						<td>
							<input name="hmpos_giftcard_max" id="hmpos_giftcard_max" type="number" min="0" step="1" value="<?php echo esc_attr( self::giftcard_max() ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 = sin tope. Monto máximo que un cajero puede cargar en una gift card de monto libre en el POS.', 'hm-pos' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'General', 'hm-pos' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmpos_register_label"><?php esc_html_e( 'Etiqueta de la caja', 'hm-pos' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[register_label]" id="hmpos_register_label" type="text" class="regular-text" value="<?php echo esc_attr( $opt['register_label'] ); ?>" placeholder="CAJA 01" /></td>
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

	/** URL de la primera página que tenga el shortcode [hm_pos], o '' si no hay. */
	public static function pos_page_url() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[hm_pos]',
				'fields'         => 'ids',
			)
		);
		return $pages ? get_permalink( $pages[0] ) : '';
	}
}
