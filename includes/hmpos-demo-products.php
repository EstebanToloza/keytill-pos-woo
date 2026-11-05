<?php
/**
 * Catálogo demo del POS — portado del array PRODUCTS del prototipo
 * (reference/POS Venta.dc.html). SKU = código de barras interno.
 *
 * `stock` se agrega acá para tener estados representativos:
 * un par en stock bajo y uno en cero para probar esos badges.
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve el catálogo demo.
 *
 * @return array<int,array{code:string,name:string,cat:string,price:int,stock:int}>
 */
function hmpos_demo_products() {
	return array(
		array( 'code' => '7791001', 'name' => 'Camiseta Titular Réplica 25/26', 'cat' => 'Indumentaria', 'price' => 89900, 'stock' => 24 ),
		array( 'code' => '7791002', 'name' => 'Short Entrenamiento Dry', 'cat' => 'Indumentaria', 'price' => 34500, 'stock' => 40 ),
		array( 'code' => '7791003', 'name' => 'Buzo Canguro Algodón', 'cat' => 'Indumentaria', 'price' => 62900, 'stock' => 15 ),
		array( 'code' => '7791004', 'name' => 'Campera Rompeviento Impermeable', 'cat' => 'Indumentaria', 'price' => 118000, 'stock' => 8 ),
		array( 'code' => '7791005', 'name' => 'Calza Térmica Larga', 'cat' => 'Indumentaria', 'price' => 41800, 'stock' => 18 ),
		array( 'code' => '7791006', 'name' => 'Medias Deportivas Pack x3', 'cat' => 'Indumentaria', 'price' => 12900, 'stock' => 0 ),  // sin stock
		array( 'code' => '7792001', 'name' => 'Zapatilla Running Neutra 42', 'cat' => 'Calzado', 'price' => 174900, 'stock' => 12 ),
		array( 'code' => '7792002', 'name' => 'Zapatilla Training Cross 40', 'cat' => 'Calzado', 'price' => 149500, 'stock' => 9 ),
		array( 'code' => '7792003', 'name' => 'Botín Papi Suela Lisa 41', 'cat' => 'Calzado', 'price' => 132000, 'stock' => 14 ),
		array( 'code' => '7792004', 'name' => 'Botín Césped Tapones 43', 'cat' => 'Calzado', 'price' => 158900, 'stock' => 7 ),
		array( 'code' => '7792005', 'name' => 'Ojota Pileta 39', 'cat' => 'Calzado', 'price' => 23400, 'stock' => 2 ),  // stock bajo
		array( 'code' => '7793001', 'name' => 'Pelota Fútbol Nº5 Cosida', 'cat' => 'Pelotas', 'price' => 54900, 'stock' => 30 ),
		array( 'code' => '7793002', 'name' => 'Pelota Futsal Nº4 Baja Pique', 'cat' => 'Pelotas', 'price' => 47500, 'stock' => 21 ),
		array( 'code' => '7793003', 'name' => 'Pelota Básquet Nº7 Outdoor', 'cat' => 'Pelotas', 'price' => 61200, 'stock' => 16 ),
		array( 'code' => '7793004', 'name' => 'Pelota Vóley Soft Touch', 'cat' => 'Pelotas', 'price' => 43900, 'stock' => 1 ),  // stock bajo
		array( 'code' => '7794001', 'name' => 'Botella Térmica 750ml', 'cat' => 'Accesorios', 'price' => 28700, 'stock' => 50 ),
		array( 'code' => '7794002', 'name' => 'Bolso Deportivo 40L', 'cat' => 'Accesorios', 'price' => 71400, 'stock' => 11 ),
		array( 'code' => '7794003', 'name' => 'Guantes Arquero Latex', 'cat' => 'Accesorios', 'price' => 56300, 'stock' => 6 ),
	);
}
