<?php
/**
 * Integración del código de barras del POS con el admin CLÁSICO de WooCommerce:
 * campo en la ficha del producto (simples y variaciones), columna en el listado
 * de productos y acceso a la impresión de etiquetas. Comparte la meta
 * `_keytill_barcode` con el editor del POS (interoperan).
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Product_Admin {

	const META   = '_keytill_barcode';
	const NOTICE = 'keytill_barcode_notice_';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		// Ficha del producto: campo en la pestaña Inventario (solo simples).
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'simple_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );

		// Variaciones: campo por variación.
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );

		// Listado clásico de productos: columna + acción de etiquetas.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'list_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'list_column_value' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );

		add_action( 'admin_notices', array( __CLASS__, 'print_notice' ) );
		add_action( 'admin_head', array( __CLASS__, 'list_column_style' ) );
	}

	/** Ancho de la columna del listado (si no, el encabezado se apila vertical). */
	public static function list_column_style() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		echo '<style>.column-keytill_barcode{width:9em}.column-keytill_barcode code{font-size:12px;white-space:nowrap}</style>';
	}

	/* ------------------------------------------------------------------ *
	 * Ficha del producto (simple)
	 * ------------------------------------------------------------------ */

	/** Campo en la pestaña Inventario (junto al SKU). Solo productos simples. */
	public static function simple_field() {
		woocommerce_wp_text_input(
			array(
				'id'                => self::META,
				'label'             => __( 'Barcode (POS)', 'keytill' ),
				'desc_tip'          => true,
				'description'       => __( '12 digits. Used to scan at the POS and print labels.', 'keytill' ),
				'wrapper_class'     => 'show_if_simple',
				'custom_attributes' => array(
					'inputmode' => 'numeric',
					'maxlength' => '12',
					'pattern'   => '\d{12}',
				),
			)
		);

		global $post;
		$pid  = $post ? (int) $post->ID : 0;
		$code = $pid ? (string) get_post_meta( $pid, self::META, true ) : '';
		if ( '' !== $code ) {
			echo '<p class="form-field show_if_simple"><a href="' . esc_url( self::labels_url( $pid ) ) . '" target="_blank" rel="noopener" class="button">'
				. esc_html__( 'Print labels', 'keytill' ) . '</a></p>';
		}
	}

	/** Guarda el código del producto simple (validado y único). */
	public static function save_simple( $product ) {
		if ( ! isset( $_POST[ self::META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC valida el nonce de la ficha.
			return;
		}
		self::apply_barcode( $product, wp_unslash( $_POST[ self::META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanitiza en apply_barcode.
	}

	/* ------------------------------------------------------------------ *
	 * Variaciones
	 * ------------------------------------------------------------------ */

	/**
	 * Campo por variación.
	 *
	 * @param int     $loop           Índice de la variación en el formulario.
	 * @param array   $variation_data Datos.
	 * @param WP_Post $variation      Post de la variación.
	 */
	public static function variation_field( $loop, $variation_data, $variation ) {
		$vid  = (int) $variation->ID;
		$code = (string) get_post_meta( $vid, self::META, true );
		woocommerce_wp_text_input(
			array(
				'id'                => self::META . '_' . $loop,
				'name'              => self::META . '[' . $loop . ']',
				'value'             => $code,
				'label'             => __( 'Barcode (POS)', 'keytill' ),
				'desc_tip'          => true,
				'description'       => __( '12 digits.', 'keytill' ),
				'wrapper_class'     => 'form-row form-row-full',
				'custom_attributes' => array(
					'inputmode' => 'numeric',
					'maxlength' => '12',
					'pattern'   => '\d{12}',
				),
			)
		);
		if ( '' !== $code ) {
			echo '<p class="form-row form-row-full"><a href="' . esc_url( self::labels_url( $vid ) ) . '" target="_blank" rel="noopener" class="button button-small">'
				. esc_html__( 'Print labels', 'keytill' ) . '</a></p>';
		}
	}

	/** Guarda el código de una variación. */
	public static function save_variation( $variation_id, $i ) {
		if ( ! isset( $_POST[ self::META ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC valida el nonce.
			return;
		}
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		self::apply_barcode( $variation, wp_unslash( $_POST[ self::META ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanitiza en apply_barcode.
		$variation->save();
	}

	/* ------------------------------------------------------------------ *
	 * Validación compartida
	 * ------------------------------------------------------------------ */

	/**
	 * Sanea y aplica el código al producto/variación. Vacío lo limpia; si no,
	 * 12 dígitos y único en el catálogo (si no, no lo guarda y deja un aviso).
	 *
	 * @param WC_Product $product Producto/variación.
	 * @param string     $raw     Valor crudo del formulario.
	 */
	private static function apply_barcode( $product, $raw ) {
		$code = preg_replace( '/\D/', '', (string) $raw );
		if ( '' === $code ) {
			$product->delete_meta_data( self::META );
			return;
		}
		if ( 12 !== strlen( $code ) ) {
			self::notice( __( 'The barcode must be 12 digits: not saved.', 'keytill' ) );
			return;
		}
		if ( class_exists( 'KEYTILL_REST' ) && KEYTILL_REST::barcode_in_use( $code, (int) $product->get_id() ) ) {
			/* translators: %s: código de barras. */
			self::notice( sprintf( __( 'Barcode %s is already used by another product: not saved.', 'keytill' ), $code ) );
			return;
		}
		$product->update_meta_data( self::META, $code );
	}

	/* ------------------------------------------------------------------ *
	 * Listado de productos
	 * ------------------------------------------------------------------ */

	/** Agrega la columna «Cód. barras» después del SKU (o del nombre). */
	public static function list_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'sku' === $key ) {
				$out['keytill_barcode'] = __( 'Barcode', 'keytill' );
			}
		}
		if ( ! isset( $out['keytill_barcode'] ) ) {
			$out['keytill_barcode'] = __( 'Barcode', 'keytill' );
		}
		return $out;
	}

	/** Valor de la columna. */
	public static function list_column_value( $column, $post_id ) {
		if ( 'keytill_barcode' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '—';
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			echo '<span style="color:#888">' . esc_html__( 'per variation', 'keytill' ) . '</span>';
			return;
		}
		$code = (string) $product->get_meta( self::META );
		echo '' !== $code ? '<code>' . esc_html( $code ) . '</code>' : '—';
	}

	/** Acción «Etiquetas» en la fila del producto (simples con código). */
	public static function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return $actions;
		}
		$product = wc_get_product( $post->ID );
		if ( $product && ! $product->is_type( 'variable' ) && '' !== (string) $product->get_meta( self::META ) ) {
			$actions['keytill_labels'] = '<a href="' . esc_url( self::labels_url( $post->ID ) ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Labels', 'keytill' ) . '</a>';
		}
		return $actions;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** URL de etiquetas con una cantidad por defecto (ajustable en la propia hoja). */
	private static function labels_url( $id ) {
		return class_exists( 'KEYTILL_Labels' ) ? add_query_arg( 'qty', 30, KEYTILL_Labels::url( $id ) ) : '#';
	}

	/** Deja un aviso para el usuario actual (se muestra una vez). */
	private static function notice( $msg ) {
		set_transient( self::NOTICE . get_current_user_id(), $msg, 60 );
	}

	/** Muestra y limpia el aviso pendiente. */
	public static function print_notice() {
		$key = self::NOTICE . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}
