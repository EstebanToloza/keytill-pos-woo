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
	 * Loads the demo products. Idempotent: if the SKU already exists the product
	 * is updated instead of duplicated.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite name/price/stock/category of products that already exist.
	 *   Without this flag existing products are left untouched (just reported).
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
			WP_CLI::error( 'WooCommerce is not active.' );
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
				WP_CLI::log( "· already exists (SKU {$sku}): {$data['name']}" );
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
				WP_CLI::log( "↻ updated (SKU {$sku}): {$data['name']}" );
			} else {
				++$created;
				WP_CLI::log( "＋ created (SKU {$sku}): {$data['name']}  ·  stock {$data['stock']}" );
			}
		}

		WP_CLI::success(
			sprintf(
				'Demo seed done — %d created, %d updated, %d left untouched (catalog total: %d).',
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
