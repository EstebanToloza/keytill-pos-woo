<?php
/**
 * Decodificador del código de barras de 12 dígitos de HM Sport.
 *
 * El cliente identifica cada prenda con un código de 12 dígitos compuesto por 5
 * segmentos (derivado de `Codigos_de_barras.xlsx`, hoja LISTAS). Ejemplo:
 * `201081283022` = Bermuda 8128 · Gris · M.
 *
 * Estructura (concatenación con zero-padding):
 *   AA    (2) Identificación: `HM 1` → 20
 *   B     (1) Tipo de prenda: Inferior→1, Superior→2, Otro→3
 *   CCCCC (5) Código de proveedor: 8128 → 08128
 *   DD    (2) Color
 *   EE    (2) Talle: 1..16 → 01..16; XS→20, S→21, M→22, L→23, XL→24, XXL→25, XXXL→26
 *
 * Esta clase SOLO decodifica (lógica pura, sin estado): convierte el código en
 * sus partes legibles. El mapeo de color/talle a los atributos de WooCommerce y
 * el precargado del producto se resuelven aparte (necesitan la configuración de
 * qué atributo global es "color" y cuál "talle").
 *
 * @package HM_POS
 */

defined( 'ABSPATH' ) || exit;

class HMPOS_Barcode {

	/** Identificación (AA). */
	const IDENT = array(
		'20' => 'HM',
	);

	/** Tipo de prenda (B). */
	const TIPO = array(
		'1' => 'Inferior',
		'2' => 'Superior',
		'3' => 'Otro',
	);

	/** Color (DD). */
	const COLOR = array(
		'01' => 'Azul',
		'10' => 'Beige',
		'11' => 'Blanco',
		'20' => 'Celeste',
		'21' => 'Crema',
		'30' => 'Gris',
		'31' => 'Gris Melange',
		'32' => 'Gris topo',
		'40' => 'Lila',
		'50' => 'Marino',
		'60' => 'Natural',
		'61' => 'Negro',
		'62' => 'Negro-Celeste',
		'63' => 'Negro-Lila',
		'64' => 'Negro-Naranja',
		'65' => 'Negro-Natural',
		'66' => 'Negro-Negro',
		'67' => 'Negro-Verde',
		'70' => 'Tostado',
		'80' => 'Verde',
	);

	/** Talle (EE) no numérico (los numéricos 01..16 se resuelven aparte). */
	const TALLE_LETRA = array(
		'20' => 'XS',
		'21' => 'S',
		'22' => 'M',
		'23' => 'L',
		'24' => 'XL',
		'25' => 'XXL',
		'26' => 'XXXL',
	);

	/**
	 * ¿Es un código válido de 12 dígitos?
	 *
	 * @param string $code Código.
	 * @return bool
	 */
	public static function is_valid( $code ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		return 12 === strlen( $code );
	}

	/**
	 * Decodifica un código de 12 dígitos en sus partes legibles.
	 *
	 * Devuelve false si no tiene 12 dígitos. Si algún segmento no está en las
	 * tablas (color/talle/tipo desconocido), igual devuelve la estructura con
	 * `name` en null para ese segmento (así el front puede precargar lo que sí
	 * reconoció y dejar el resto para completar a mano).
	 *
	 * @param string $code Código escaneado.
	 * @return array|false
	 */
	public static function decode( $code ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 12 !== strlen( $code ) ) {
			return false;
		}

		$aa = substr( $code, 0, 2 );   // identificación
		$b  = substr( $code, 2, 1 );   // tipo
		$cc = substr( $code, 3, 5 );   // proveedor
		$dd = substr( $code, 8, 2 );   // color
		$ee = substr( $code, 10, 2 );  // talle

		return array(
			'raw'            => $code,
			'identificacion' => array(
				'code' => $aa,
				'name' => isset( self::IDENT[ $aa ] ) ? self::IDENT[ $aa ] : null,
			),
			'tipo'           => array(
				'code' => $b,
				'name' => isset( self::TIPO[ $b ] ) ? self::TIPO[ $b ] : null,
			),
			'proveedor'      => array(
				'code' => $cc,
				'num'  => (int) $cc,
			),
			'color'          => array(
				'code' => $dd,
				'name' => isset( self::COLOR[ $dd ] ) ? self::COLOR[ $dd ] : null,
			),
			'talle'          => array(
				'code' => $ee,
				'name' => self::talle_name( $ee ),
			),
		);
	}

	/**
	 * Nombre del talle a partir de su código de 2 dígitos.
	 * 01..16 → "1".."16"; 20..26 → XS..XXXL. Null si no se reconoce.
	 *
	 * @param string $ee Código de talle (2 dígitos).
	 * @return string|null
	 */
	private static function talle_name( $ee ) {
		if ( isset( self::TALLE_LETRA[ $ee ] ) ) {
			return self::TALLE_LETRA[ $ee ];
		}
		$n = (int) $ee;
		if ( $n >= 1 && $n <= 16 ) {
			return (string) $n;
		}
		return null;
	}
}
