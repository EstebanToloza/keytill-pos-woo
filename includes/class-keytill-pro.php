<?php
/**
 * Licencia: qué features están disponibles.
 *
 * TODO el gating free/PRO pasa por acá. La idea es que el resto del plugin
 * nunca pregunte por la licencia directamente: pregunta por una CAPACIDAD
 * (`KEYTILL_Pro::has( KEYTILL_Pro::MULTI_LOCATION )`) y esta clase decide. Así
 * el día que cambie el proveedor de licencias —o el corte— se toca un archivo
 * y no 3.700 líneas de REST.
 *
 * El corte es por **tipo de cosa**, no por volumen: no hay topes artificiales
 * ("hasta 50 productos"). WordPress.org los mira mal y al usuario lo enojan.
 *
 * Dos reglas que no se negocian:
 *
 * 1. FREE es un POS de mostrador completo, no una demo. Vende, cobra, devuelve
 *    en efectivo, imprime y arquea. Si eso no funciona, no hay instalaciones,
 *    no hay reseñas y no hay a quién venderle PRO.
 *
 * 2. **Canjear** crédito funciona SIEMPRE, aunque emitirlo sea PRO. Si alguien
 *    deja de pagar y sus clientes tienen gift cards en la calle, el sistema no
 *    puede rechazarles el código: eso es un problema con los clientes DEL
 *    comercio, y una reseña de una estrella bien merecida.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disponibilidad de features según la licencia.
 */
class KEYTILL_Pro {

	/* ------------------------------------------------------------------ *
	 * Features con compuerta. Una constante por compuerta, siete en total.
	 * ------------------------------------------------------------------ */

	/** Varias cajas a la vez: un cajón por cajero. */
	const MULTI_REGISTER = 'multi_register';

	/** Varias sucursales: stock por local y movimientos entre locales. */
	const MULTI_LOCATION = 'multi_location';

	/** Caja digital (cuenta bancaria), conciliación y libro de movimientos. */
	const DIGITAL_REGISTER = 'digital_register';

	/** EMITIR crédito en tienda y gift cards. Canjearlos es siempre libre. */
	const ISSUE_CREDIT = 'issue_credit';

	/** Planchas de etiquetas con código de barras. */
	const LABELS = 'labels';

	/** Reportes: ventas por vendedor en un rango de fechas. */
	const REPORTS = 'reports';

	/**
	 * Editor completo de productos: crear variables, generar variaciones,
	 * imágenes/galería y valores de atributo.
	 *
	 * NO incluye la edición inline de precio, stock y código de barras —ni la de
	 * las variaciones—, que es trabajo diario de mostrador y queda en FREE. Lo
	 * que se paga es armar el catálogo SIN salir del POS; el editor de
	 * WooCommerce sigue estando gratis para hacerlo desde el panel.
	 */
	const PRODUCT_EDITOR = 'product_editor';

	/**
	 * Todas las features con compuerta.
	 *
	 * @return string[]
	 */
	public static function features() {
		return array(
			self::MULTI_REGISTER,
			self::MULTI_LOCATION,
			self::DIGITAL_REGISTER,
			self::ISSUE_CREDIT,
			self::LABELS,
			self::REPORTS,
			self::PRODUCT_EDITOR,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Consulta
	 * ------------------------------------------------------------------ */

	/**
	 * ¿Está disponible esta feature?
	 *
	 * Orden de resolución:
	 *   1. Filtro `keytill_pro_features` — para tests y para que una extensión
	 *      propia pueda desbloquear sin pasar por el proveedor de licencias.
	 *   2. El proveedor de licencias, cuando esté integrado.
	 *   3. El default (ver {@see self::default_state()}).
	 *
	 * @param string $feature Una de las constantes de esta clase.
	 * @return bool
	 */
	public static function has( $feature ) {
		$feature = (string) $feature;

		// Una feature sin compuerta está disponible por definición: así, si
		// alguien pregunta por una clave que no existe, no se bloquea de más.
		if ( ! in_array( $feature, self::features(), true ) ) {
			return true;
		}

		$state = self::state();
		return ! empty( $state[ $feature ] );
	}

	/** @var array<string,bool>|null Cache del estado por request. */
	private static $cache = null;

	/**
	 * Estado de todas las features. Se cachea por request.
	 *
	 * @return array<string,bool>
	 */
	public static function state() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$state = array();
		foreach ( self::features() as $f ) {
			$state[ $f ] = self::default_state();
		}

		/**
		 * Permite forzar el estado de las features.
		 *
		 * @param array<string,bool> $state Feature => disponible.
		 */
		$state = (array) apply_filters( 'keytill_pro_features', $state );

		self::$cache = array();
		foreach ( self::features() as $f ) {
			self::$cache[ $f ] = ! empty( $state[ $f ] );
		}
		return self::$cache;
	}

	/**
	 * Estado por defecto de una feature.
	 *
	 * Orden:
	 *
	 *   1. Si el proveedor de licencias está configurado y disponible, manda él:
	 *      licencia paga o prueba vigente = PRO.
	 *   2. Si no lo está, devuelve {@see self::UNLICENSED_DEFAULT}.
	 *
	 * El paso 2 es lo que permite desarrollar y correr las suites sin tener que
	 * montar el proveedor. Ver la constante para por qué hoy está en `true`.
	 *
	 * @return bool
	 */
	private static function default_state() {
		if ( class_exists( 'KEYTILL_License' ) && KEYTILL_License::active() ) {
			return KEYTILL_License::is_paying() || KEYTILL_License::is_trial();
		}
		return self::UNLICENSED_DEFAULT;
	}

	/**
	 * Qué pasa cuando NO hay proveedor de licencias configurado.
	 *
	 * Hoy `true` a propósito: el plugin no se publicó, el proveedor todavía no
	 * está dado de alta, y un default restrictivo rompería el desarrollo y las
	 * suites sin probar nada a cambio.
	 *
	 * **Antes de publicar esto tiene que pasar a `false`**, para que un sitio sin
	 * licencia quede en FREE. Las compuertas ya están aplicadas y la suite
	 * `tools/tests/e2e-free.php` ya prueba ese modo forzándolo por filtro, así
	 * que el cambio es de una línea y está cubierto.
	 */
	const UNLICENSED_DEFAULT = true;

	/**
	 * Limpia el cache del estado.
	 *
	 * Hace falta en los tests, que cambian el filtro dentro de un mismo request
	 * y necesitan que la próxima consulta lo relea.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/* ------------------------------------------------------------------ *
	 * Ayudas para el gating
	 * ------------------------------------------------------------------ */

	/**
	 * Error REST estándar de feature no disponible.
	 *
	 * Un 402 Payment Required sería lo semánticamente exacto, pero varios
	 * proxies y plugins de caché lo tratan raro; 403 es el que el front ya sabe
	 * manejar. El código del error (`keytill_pro_required`) es lo que permite
	 * distinguirlo de un problema de permisos.
	 *
	 * @param string $message Mensaje para el usuario.
	 * @return WP_Error
	 */
	public static function denied( $message = '' ) {
		if ( '' === $message ) {
			$message = __( 'This feature is part of Keytill PRO.', 'keytill' );
		}
		return new WP_Error( 'keytill_pro_required', $message, array( 'status' => 403 ) );
	}

	/**
	 * Estado para pasarle al front (y que esconda lo que no corresponde).
	 *
	 * @return array<string,bool>
	 */
	public static function front() {
		return self::state();
	}
}
