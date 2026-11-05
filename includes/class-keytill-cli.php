<?php
/**
 * Comandos WP-CLI del POS.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comandos `wp keytill ...`.
 */
class KEYTILL_CLI {

	/**
	 * Carga los productos demo del prototipo. Idempotente: si el SKU ya existe,
	 * actualiza el producto en vez de duplicarlo.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Reescribe nombre/precio/stock/categoría de los productos ya existentes.
	 *   Sin este flag, los existentes se dejan intactos (solo informa).
	 *
	 * ## EXAMPLES
	 *
	 *     wp keytill seed-demo
	 *     wp keytill seed-demo --force
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Argumentos posicionales (no usados).
	 * @param array $assoc_args Flags.
	 */
	public function seed_demo( $args, $assoc_args ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			WP_CLI::error( 'WooCommerce no está activo.' );
		}

		require_once KEYTILL_DIR . 'includes/keytill-demo-products.php';
		$products = keytill_demo_products();
		$force    = isset( $assoc_args['force'] );

		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $products as $data ) {
			$sku         = $data['code'];
			$existing_id = wc_get_product_id_by_sku( $sku );

			if ( $existing_id && ! $force ) {
				++$skipped;
				WP_CLI::log( "· ya existe (SKU {$sku}): {$data['name']}" );
				continue;
			}

			$product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();

			$product->set_name( $data['name'] );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sku( $sku );
			$product->set_regular_price( (string) $data['price'] );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $data['stock'] );
			$product->set_stock_status( $data['stock'] > 0 ? 'instock' : 'outofstock' );
			$product->set_category_ids( array( $this->ensure_category( $data['cat'] ) ) );

			$product->save();

			if ( $existing_id ) {
				++$updated;
				WP_CLI::log( "↻ actualizado (SKU {$sku}): {$data['name']}" );
			} else {
				++$created;
				WP_CLI::log( "＋ creado (SKU {$sku}): {$data['name']}  ·  stock {$data['stock']}" );
			}
		}

		WP_CLI::success(
			sprintf(
				'Seed demo listo — %d creados, %d actualizados, %d sin tocar (total catálogo: %d).',
				$created,
				$updated,
				$skipped,
				count( $products )
			)
		);
	}

	/**
	 * Devuelve el term_id de la categoría de producto, creándola si hace falta.
	 *
	 * @param string $name Nombre de la categoría.
	 * @return int term_id.
	 */
	private function ensure_category( $name ) {
		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$result = wp_insert_term( $name, 'product_cat' );
		if ( is_wp_error( $result ) ) {
			// Carrera o ya existe: reintenta la búsqueda.
			$term = get_term_by( 'name', $name, 'product_cat' );
			return $term instanceof WP_Term ? (int) $term->term_id : 0;
		}

		return (int) $result['term_id'];
	}
}
