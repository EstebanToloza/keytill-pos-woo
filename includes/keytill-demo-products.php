<?php
/**
 * Catálogo demo para probar el POS en un sitio de desarrollo. Lo usa
 * `wp keytill seed-demo`; no se carga nunca en el front.
 *
 * Es a propósito un surtido genérico de retail (no de un rubro en
 * particular) y en inglés, para que sirva de demo en cualquier tienda.
 * El SKU hace de código de barras interno.
 *
 * Los stocks están elegidos para que se vean todos los estados de la
 * interfaz: varios con stock normal, un par en stock bajo (badge ámbar)
 * y uno en cero (badge rojo / «sin stock»).
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve el catálogo demo.
 *
 * @return array<int,array{code:string,name:string,cat:string,price:string,stock:int}>
 */
function keytill_demo_products() {
	return array(
		array(
			'code'  => 'DEMO-1001',
			'name'  => 'Classic Cotton T-Shirt',
			'cat'   => 'Apparel',
			'price' => '24.00',
			'stock' => 24,
		),
		array(
			'code'  => 'DEMO-1002',
			'name'  => 'Hooded Sweatshirt',
			'cat'   => 'Apparel',
			'price' => '59.00',
			'stock' => 15,
		),
		array(
			'code'  => 'DEMO-1003',
			'name'  => 'Lightweight Rain Jacket',
			'cat'   => 'Apparel',
			'price' => '118.00',
			'stock' => 8,
		),
		array(
			'code'  => 'DEMO-1004',
			'name'  => 'Wool Socks (3-pack)',
			'cat'   => 'Apparel',
			'price' => '12.90',
			'stock' => 0, // sin stock
		),
		array(
			'code'  => 'DEMO-2001',
			'name'  => 'Running Shoes',
			'cat'   => 'Footwear',
			'price' => '89.00',
			'stock' => 12,
		),
		array(
			'code'  => 'DEMO-2002',
			'name'  => 'Canvas Sneakers',
			'cat'   => 'Footwear',
			'price' => '54.00',
			'stock' => 9,
		),
		array(
			'code'  => 'DEMO-2003',
			'name'  => 'Leather Sandals',
			'cat'   => 'Footwear',
			'price' => '39.00',
			'stock' => 2, // stock bajo
		),
		array(
			'code'  => 'DEMO-3001',
			'name'  => 'Insulated Water Bottle 750ml',
			'cat'   => 'Accessories',
			'price' => '28.50',
			'stock' => 50,
		),
		array(
			'code'  => 'DEMO-3002',
			'name'  => 'Canvas Tote Bag',
			'cat'   => 'Accessories',
			'price' => '19.00',
			'stock' => 30,
		),
		array(
			'code'  => 'DEMO-3003',
			'name'  => 'Travel Duffel 40L',
			'cat'   => 'Accessories',
			'price' => '71.00',
			'stock' => 11,
		),
		array(
			'code'  => 'DEMO-3004',
			'name'  => 'Knitted Beanie',
			'cat'   => 'Accessories',
			'price' => '16.50',
			'stock' => 1, // stock bajo
		),
		array(
			'code'  => 'DEMO-4001',
			'name'  => 'Ceramic Mug',
			'cat'   => 'Home',
			'price' => '14.00',
			'stock' => 40,
		),
		array(
			'code'  => 'DEMO-4002',
			'name'  => 'Scented Candle',
			'cat'   => 'Home',
			'price' => '21.00',
			'stock' => 21,
		),
		array(
			'code'  => 'DEMO-4003',
			'name'  => 'Cotton Bath Towel',
			'cat'   => 'Home',
			'price' => '34.00',
			'stock' => 16,
		),
		array(
			'code'  => 'DEMO-4004',
			'name'  => 'Stainless Steel Lunch Box',
			'cat'   => 'Home',
			'price' => '43.00',
			'stock' => 6,
		),
	);
}
