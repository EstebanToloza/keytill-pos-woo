<?php
/**
 * Generador de etiquetas imprimibles con código de barras (Code 128).
 *
 * Mismo enfoque que el comprobante: HTML autocontenido + window.print() del
 * navegador (sin PDF ni dependencias). Genera una hoja A4 con N etiquetas de
 * 3,5×3,5 cm de un producto/variación, con su código de barras Code 128, el
 * nombre, los atributos (color/talle) y el número debajo.
 *
 * Ruta: GET al front con ?keytill_labels=<product_id>&qty=<n>&_wpnonce=<nonce>.
 * Requiere capability del POS o poder editar órdenes, y un nonce por-producto.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

class KEYTILL_Labels {

	/** Query var que dispara la impresión de etiquetas. */
	const QV = 'keytill_labels';

	/** Tope de etiquetas por producto (evita hojas gigantes por error). */
	const MAX_QTY = 1000;

	/** Tope de productos distintos en un lote. */
	const MAX_ITEMS = 2000;

	/** Tope global de etiquetas en un lote (suma de todas las cantidades). */
	const MAX_TOTAL = 20000;

	/** Acción de nonce del lote (impresión de varios productos a la vez). */
	const BATCH_NONCE = 'keytill_labels_batch';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Acción de nonce por-producto.
	 *
	 * @param int $id ID del producto/variación.
	 * @return string
	 */
	private static function nonce_action( $id ) {
		return 'keytill_labels_' . (int) $id;
	}

	/** URL destino del formulario de lote (POST). El nonce va en el form. */
	public static function batch_url() {
		return add_query_arg( self::QV, 'batch', home_url( '/' ) );
	}

	/** Nonce para el formulario de lote. */
	public static function batch_nonce() {
		return wp_create_nonce( self::BATCH_NONCE );
	}

	/**
	 * URL base de impresión de etiquetas de un producto (con nonce). El front le
	 * agrega &qty=N. El nonce protege el id; la cantidad no es sensible.
	 *
	 * @param int $id ID del producto/variación.
	 * @return string
	 */
	public static function url( $id ) {
		$id = (int) $id;
		return add_query_arg(
			array(
				self::QV   => $id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $id ) ),
			),
			home_url( '/' )
		);
	}

	/** ¿Puede imprimir? (operar el POS o editar productos). */
	private static function can_print() {
		return current_user_can( KEYTILL_CAP ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Si la request pide etiquetas, valida y renderiza; corta. Dos modos:
	 *  - individual: ?keytill_labels=<id>&qty=<n>&_wpnonce (GET, nonce por-producto).
	 *  - lote:       ?keytill_labels=batch + POST items[<id>]=<qty> + _wpnonce de lote.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET[ self::QV ] ) ) {
			return;
		}
		// El permiso (capability) se chequea SIEMPRE antes del nonce.
		if ( ! self::can_print() ) {
			wp_die( esc_html__( 'You do not have permission to print labels.', 'keytill' ), 403 );
		}

		if ( 'batch' === $_GET[ self::QV ] ) {
			self::render_batch();
			exit;
		}

		$id = absint( $_GET[ self::QV ] );
		if ( ! $id ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $id ) ) ) {
			wp_die( esc_html__( 'The label link expired. Reopen it from the product.', 'keytill' ), 403 );
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			wp_die( esc_html__( 'Product not found.', 'keytill' ), 404 );
		}
		$code = (string) $product->get_meta( '_keytill_barcode' );
		if ( '' === $code ) {
			wp_die( esc_html__( 'This product has no barcode set.', 'keytill' ), 400 );
		}
		$qty = isset( $_GET['qty'] ) ? absint( $_GET['qty'] ) : 1;
		$qty = max( 1, min( self::MAX_QTY, $qty ) );

		$units = array( self::make_unit( $product, $code, $qty ) );
		self::render_sheet( $units, array( 'qty_control' => true, 'qty' => $qty, 'code' => $code ) );
		exit;
	}

	/**
	 * Modo lote: valida el nonce del formulario y arma las unidades a imprimir a
	 * partir de items[<id>] = <qty> (POST). Descarta ids sin código de barras.
	 */
	private static function render_batch() {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::BATCH_NONCE ) ) {
			wp_die( esc_html__( 'The label session expired. Generate the labels again from the POS.', 'keytill' ), 403 );
		}
		$items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		if ( empty( $items ) ) {
			wp_die( esc_html__( 'You did not select any product to print.', 'keytill' ), 400 );
		}

		$units       = array();
		$total       = 0;
		$dropped_cap = 0; // ítems no impresos por topes (cantidad de ítems).
		$no_barcode  = 0; // ítems descartados por no tener código de barras.
		foreach ( $items as $id => $qty ) {
			// Topes de seguridad: en vez de descartar en silencio, contamos lo que
			// queda afuera para avisarlo en la hoja impresa.
			if ( count( $units ) >= self::MAX_ITEMS || $total >= self::MAX_TOTAL ) {
				$dropped_cap++;
				continue;
			}
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$code = (string) $product->get_meta( '_keytill_barcode' );
			if ( '' === $code ) {
				$no_barcode++;
				continue; // sin código de barras: no se puede imprimir.
			}
			$qty = max( 1, min( self::MAX_QTY, absint( $qty ) ) );
			if ( $total + $qty > self::MAX_TOTAL ) {
				$qty = self::MAX_TOTAL - $total; // recorta al tope global.
			}
			if ( $qty < 1 ) {
				$dropped_cap++;
				continue;
			}
			$units[] = self::make_unit( $product, $code, $qty );
			$total  += $qty;
		}

		if ( empty( $units ) ) {
			wp_die( esc_html__( 'None of the selected products has a barcode set.', 'keytill' ), 400 );
		}

		// Aviso visible (no se imprime) si algo quedó afuera por topes o sin código.
		$notices = array();
		if ( $dropped_cap > 0 ) {
			/* translators: %d: cantidad de productos que no entraron por el tope. */
			$notices[] = sprintf( _n( '%d producto no entró por el tope del lote.', '%d productos no entraron por el tope del lote.', $dropped_cap, 'keytill' ), $dropped_cap );
		}
		if ( $no_barcode > 0 ) {
			/* translators: %d: cantidad de productos sin código de barras. */
			$notices[] = sprintf( _n( '%d producto se salteó por no tener código de barras.', '%d productos se saltearon por no tener código de barras.', $no_barcode, 'keytill' ), $no_barcode );
		}

		self::render_sheet( $units, array( 'qty_control' => false, 'notice' => implode( ' ', $notices ) ) );
		exit;
	}

	/**
	 * Arma una «unidad» de impresión (producto + código + cantidad + textos + SVG).
	 *
	 * @param WC_Product $product Producto/variación.
	 * @param string     $code    Código de barras.
	 * @param int        $qty     Cantidad de etiquetas.
	 * @return array
	 */
	private static function make_unit( $product, $code, $qty ) {
		return array(
			'texts' => self::label_texts( $product ),
			'code'  => $code,
			'qty'   => (int) $qty,
			'svg'   => self::code128_svg( $code ),
		);
	}

	/**
	 * Título y líneas de atributos de la etiqueta a partir del producto/variación.
	 * Cada línea de atributo va en formato «Propiedad: Valor». Qué atributos se
	 * muestran se controla en Ajustes (si no hay ninguno configurado, se muestran
	 * todos los atributos de la variación).
	 *
	 * @param WC_Product $product Producto.
	 * @return array{title:string,subs:string[]}
	 */
	private static function label_texts( $product ) {
		if ( ! $product instanceof WC_Product_Variation ) {
			return array( 'title' => $product->get_name(), 'subs' => array() );
		}

		$parent = wc_get_product( $product->get_parent_id() );
		$title  = $parent ? $parent->get_name() : $product->get_name();

		// Atributos a mostrar (nombres de taxonomía) según Ajustes; null = todos.
		$only = class_exists( 'KEYTILL_Settings' ) ? KEYTILL_Settings::label_attributes() : null;

		$subs = array();
		foreach ( $product->get_variation_attributes() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			// 'attribute_pa_color' => 'pa_color'; 'attribute_talle' => 'talle'.
			$taxonomy = str_replace( 'attribute_', '', $key );
			if ( is_array( $only ) && ! in_array( $taxonomy, $only, true ) ) {
				continue;
			}
			$label = wc_attribute_label( $taxonomy, $parent instanceof WC_Product ? $parent : null );
			// Valor legible: para taxonomías, el nombre del término; si no, el valor tal cual.
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $value, $taxonomy );
				$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
			}
			$subs[] = $label . ': ' . $value;
		}

		return array( 'title' => $title, 'subs' => $subs );
	}

	/**
	 * Renderiza la hoja A4 con todas las etiquetas de las unidades y lanza la
	 * impresión. Cada unidad aporta `qty` etiquetas iguales; se imprimen en el
	 * orden recibido, todas en la misma hoja continua.
	 *
	 * @param array[] $units Unidades ({texts, code, qty, svg}).
	 * @param array   $opts  Opciones: qty_control(bool), qty(int), code(string).
	 */
	private static function render_sheet( $units, $opts = array() ) {
		$qty_control = ! empty( $opts['qty_control'] );
		$total       = 0;
		foreach ( $units as $u ) {
			$total += (int) $u['qty'];
		}
		$title = $qty_control && isset( $opts['code'] )
			? sprintf( __( 'Labels %1$s (%2$d)', 'keytill' ), $opts['code'], $total )
			: sprintf( _n( 'Etiquetas (%d)', 'Etiquetas (%d)', $total, 'keytill' ), $total );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<style>
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; background:#e9edf2; font-family:'Helvetica Neue',Arial,system-ui,sans-serif; color:#0A1A33;
		-webkit-print-color-adjust:exact; print-color-adjust:exact; }
	/* Cada hoja es una grilla FIJA: misma cantidad y misma posición de etiquetas
	   en todas las hojas (apilables para cortar juntas en guillotina). */
	.page{ width:210mm; margin:0 auto 6mm; background:#fff; padding:8mm; box-shadow:0 1px 6px rgba(10,26,51,.15);
		break-after:page; page-break-after:always; }
	.page:last-child{ break-after:auto; page-break-after:auto; }
	.page.is-a3{ width:297mm; }
	.page .grid{ display:grid; grid-template-columns:repeat(var(--cols,5), 35mm); grid-auto-rows:35mm;
		gap:2mm; justify-content:start; align-content:start; }
	.label{ width:35mm; height:35mm; border:0.2mm solid #d8dee6; border-radius:1mm; padding:1.5mm;
		display:flex; flex-direction:column; align-items:center; justify-content:space-between;
		overflow:hidden; break-inside:avoid; page-break-inside:avoid; }
	.label .name{ font-weight:800; font-size:8pt; line-height:1.1; text-align:center; width:100%;
		overflow:hidden; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
	.label .sub{ font-size:6.5pt; color:#3a4453; text-align:center; width:100%; line-height:1.1;
		overflow:hidden; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
	.label .bc{ width:100%; height:12mm; display:flex; align-items:center; justify-content:center; }
	.label .bc svg{ width:100%; height:12mm; display:block; }
	.label .code{ font-family:'Courier New',monospace; font-size:8pt; letter-spacing:1px; text-align:center; width:100%; }
	.toolbar{ position:fixed; top:0; left:0; right:0; background:#0A1A33; color:#fff; padding:8px 14px;
		display:flex; gap:14px; align-items:center; font-size:13px; }
	.toolbar button{ font:inherit; background:#fff; color:#0A1A33; border:0; border-radius:6px; padding:6px 14px; cursor:pointer; font-weight:700; }
	.toolbar .muted{ opacity:.8; }
	.toolbar .qtybox{ display:flex; align-items:center; gap:6px; }
	.toolbar .qtybox label{ opacity:.8; }
	.toolbar .qtybox input{ width:64px; font:inherit; padding:5px 6px; border:0; border-radius:6px; }
	.toolbar .qtybox button{ padding:6px 10px; }
	.toolbar .paperbox{ display:flex; align-items:center; gap:6px; }
	.toolbar .paperbox label{ opacity:.8; }
	.toolbar .paperbox select{ font:inherit; padding:5px 8px; border:0; border-radius:6px; cursor:pointer; }
	.notice-bar{ position:fixed; top:44px; left:0; right:0; background:#fff3cd; color:#7a5b00;
		border-bottom:1px solid #e6d28a; padding:8px 14px; font-size:13px; font-weight:600; z-index:5; }
	.wrap{ padding-top:44px; }
	body.has-notice .wrap{ padding-top:80px; }
	@media print{
		html,body{ background:#fff; }
		.toolbar, .notice-bar{ display:none; }
		.wrap{ padding-top:0; }
		.page{ margin:0; box-shadow:none; }
	}
</style>
<!-- Tamaño de página conmutable (A4/A3) por JS. -->
<style id="keytill-pagesize">@page{ size:A4; margin:0; }</style>
</head>
<body class="<?php echo empty( $opts['notice'] ) ? '' : 'has-notice'; ?>">
	<div class="toolbar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print / Save PDF', 'keytill' ); ?></button>
		<span class="paperbox">
			<label for="keytill-paper"><?php esc_html_e( 'Sheet', 'keytill' ); ?></label>
			<select id="keytill-paper" onchange="keytillSetPaper(this.value)">
				<option value="A4">A4</option>
				<option value="A3">A3</option>
			</select>
		</span>
		<?php if ( $qty_control ) : ?>
			<span class="qtybox">
				<label for="keytill-qty"><?php esc_html_e( 'Quantity', 'keytill' ); ?></label>
				<input id="keytill-qty" type="number" min="1" max="<?php echo (int) self::MAX_QTY; ?>" value="<?php echo (int) $total; ?>" />
				<button type="button" onclick="keytillSetQty(document.getElementById('keytill-qty').value)"><?php esc_html_e( 'Refresh', 'keytill' ); ?></button>
			</span>
			<span class="muted"><?php echo esc_html( sprintf( __( '%1$d labels · %2$s · 3.5 × 3.5 cm', 'keytill' ), $total, isset( $opts['code'] ) ? $opts['code'] : '' ) ); ?></span>
		<?php else : ?>
			<span class="muted"><?php echo esc_html( sprintf( _n( '%1$d etiqueta · %2$d producto · 3,5 × 3,5 cm', '%1$d etiquetas · %2$d productos · 3,5 × 3,5 cm', $total, 'keytill' ), $total, count( $units ) ) ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $opts['notice'] ) ) : ?>
		<div class="notice-bar">⚠ <?php echo esc_html( $opts['notice'] ); ?></div>
	<?php endif; ?>
	<script>
		// Conmuta el tamaño de hoja (A4/A3) sin recargar: cambia la regla @page, el
		// ancho de la hoja y re-pagina la grilla. Recuerda la elección.
		function keytillSetPaper( v ){
			v = ( v === 'A3' ) ? 'A3' : 'A4';
			var st = document.getElementById( 'keytill-pagesize' );
			if ( st ) { st.textContent = '@page{ size:' + v + '; margin:0; }'; }
			document.body.classList.toggle( 'is-a3', v === 'A3' );
			try { localStorage.setItem( 'keytill_label_paper', v ); } catch ( e ) {}
			if ( window.keytillPaginate ) { window.keytillPaginate( v ); }
		}
		<?php if ( $qty_control ) : ?>
		function keytillSetQty( n ){
			var q = Math.max( 1, Math.min( <?php echo (int) self::MAX_QTY; ?>, parseInt( n, 10 ) || 1 ) );
			var u = new URL( window.location.href );
			u.searchParams.set( 'qty', q );
			window.location.href = u.toString();
		}
		<?php endif; ?>
	</script>
	<div class="wrap">
		<!-- Depósito plano de etiquetas: JS las reparte en hojas de grilla fija. -->
		<div id="keytill-pool" hidden>
			<?php
			foreach ( $units as $u ) :
				for ( $i = 0; $i < (int) $u['qty']; $i++ ) :
					?>
					<div class="label">
						<span class="name"><?php echo esc_html( $u['texts']['title'] ); ?></span>
						<?php if ( ! empty( $u['texts']['subs'] ) ) : ?>
							<span class="sub"><?php echo esc_html( implode( ' · ', $u['texts']['subs'] ) ); ?></span>
						<?php endif; ?>
						<span class="bc"><?php echo $u['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG generado internamente ?></span>
						<span class="code"><?php echo esc_html( $u['code'] ); ?></span>
					</div>
					<?php
				endfor;
			endforeach;
			?>
		</div>
		<div id="keytill-pages"></div>
	</div>
	<script>
		// Grilla fija por hoja: MISMA cantidad y MISMA posición en todas las hojas,
		// para poder apilarlas y cortarlas juntas en la guillotina. Cada etiqueta
		// mide 35 mm; los cupos por hoja se calculan con margen de 8 mm.
		( function(){
			var pool = document.getElementById( 'keytill-pool' );
			var LABELS = Array.prototype.map.call( pool.children, function ( el ) { return el.outerHTML; } );
			if ( pool.parentNode ) { pool.parentNode.removeChild( pool ); }
			var GRID = { A4: { cols: 5, rows: 7 }, A3: { cols: 7, rows: 10 } };
			window.keytillPaginate = function ( paper ){
				var g = GRID[ paper ] || GRID.A4;
				var per = g.cols * g.rows;
				var pages = document.getElementById( 'keytill-pages' );
				if ( ! pages ) { return; }
				var html = '';
				for ( var i = 0; i < LABELS.length; i += per ) {
					html += '<div class="page' + ( paper === 'A3' ? ' is-a3' : '' ) + '" style="--cols:' + g.cols + '">' +
						'<div class="grid">' + LABELS.slice( i, i + per ).join( '' ) + '</div></div>';
				}
				pages.innerHTML = html;
			};
			// Aplica la hoja recordada (dispara la paginación inicial).
			var v = 'A4';
			try { v = localStorage.getItem( 'keytill_label_paper' ) || 'A4'; } catch ( e ) {}
			var sel = document.getElementById( 'keytill-paper' );
			if ( sel ) { sel.value = v; }
			keytillSetPaper( v );
		} )();
		window.addEventListener( 'load', function(){ setTimeout( function(){ window.print(); }, 250 ); } );
	</script>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Code 128 (símbolo)
	 * ------------------------------------------------------------------ */

	/**
	 * Codifica los datos en valores Code 128. Usa Code C (doble densidad) para
	 * cadenas numéricas de largo par (caso del código de 12 dígitos); si no, B.
	 *
	 * @param string $data Datos.
	 * @return int[] Valores (incluye start, checksum y stop).
	 */
	private static function code128_encode( $data ) {
		$codes = array();
		if ( ctype_digit( $data ) && 0 === strlen( $data ) % 2 ) {
			$codes[] = 105; // Start C
			for ( $i = 0; $i < strlen( $data ); $i += 2 ) {
				$codes[] = (int) substr( $data, $i, 2 );
			}
		} else {
			$codes[] = 104; // Start B
			for ( $i = 0; $i < strlen( $data ); $i++ ) {
				$codes[] = ord( $data[ $i ] ) - 32;
			}
		}
		$sum = $codes[0];
		$n   = count( $codes );
		for ( $i = 1; $i < $n; $i++ ) {
			$sum += $codes[ $i ] * $i;
		}
		$codes[] = $sum % 103; // checksum
		$codes[] = 106;        // Stop
		return $codes;
	}

	/**
	 * Tabla de patrones Code 128 (índices 0–106). Cada patrón es la secuencia de
	 * anchos de módulos (barra, espacio, barra, …). El 106 (stop) tiene 7 módulos.
	 *
	 * @return string[]
	 */
	private static function code128_patterns() {
		$p =
			'212222222122222221121223121322131222122213122312132212221213' .
			'221312231212112232122132122231113222123122123221223211221132' .
			'221231213212223112312131311222321122321221312212322112322211' .
			'212123212321232121111323131123131321112313132113132311211313' .
			'231113231311112133112331132131113123113321133121313121211331' .
			'231131213113213311213131311123311321331121312113312311332111' .
			'314111221411431111111224111422121124121421141122141221112214' .
			'112412122114122411142112142211241211221114413111241112134111' .
			'111242121142121241114212124112124211411212421112421211212141' .
			'214121412121111143111341131141114113114311411113411311113141' .
			'114131311141411131211412211214211232';
		$out = str_split( $p, 6 );  // índices 0–105
		$out[] = '2331112';          // 106: stop
		return $out;
	}

	/**
	 * Genera el SVG del código de barras Code 128. El SVG se estira al ancho del
	 * contenedor (escala uniforme en X: preserva la proporción de las barras).
	 *
	 * @param string $data   Datos (12 dígitos).
	 * @param int    $height Alto en unidades del viewBox.
	 * @return string SVG.
	 */
	public static function code128_svg( $data, $height = 60 ) {
		$T     = self::code128_patterns();
		$codes = self::code128_encode( $data );
		$quiet = 10; // zona muda (módulos) a cada lado
		$x     = $quiet;
		$rects = '';
		foreach ( $codes as $val ) {
			if ( ! isset( $T[ $val ] ) ) {
				continue;
			}
			$pat = $T[ $val ];
			$bar = true;
			for ( $k = 0; $k < strlen( $pat ); $k++ ) {
				$w = (int) $pat[ $k ];
				if ( $bar && $w > 0 ) {
					$rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . (int) $height . '"/>';
				}
				$x  += $w;
				$bar = ! $bar;
			}
		}
		$total = $x + $quiet;
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . (int) $height . '" '
			. 'preserveAspectRatio="none" shape-rendering="crispEdges">'
			. '<rect x="0" y="0" width="' . $total . '" height="' . (int) $height . '" fill="#fff"/>'
			. '<g fill="#000">' . $rects . '</g></svg>';
	}
}
