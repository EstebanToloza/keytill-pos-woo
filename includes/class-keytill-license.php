<?php
/**
 * Puente con el proveedor de licencias (Freemius).
 *
 * Esta clase es la ÚNICA que sabe que el proveedor existe. {@see KEYTILL_Pro}
 * le pregunta si hay licencia y nada más; el resto del plugin no la conoce.
 * Así, cambiar de proveedor —o sacarlo— no toca las compuertas.
 *
 * Todo degrada con gracia: si el SDK no está, o las credenciales no se
 * configuraron, el plugin funciona igual. Eso no es defensivo por las dudas,
 * es un requisito: el zip que se publica en WordPress.org tiene que poder
 * correr sin el SDK, y el desarrollo también.
 *
 * Producto dado de alta en Freemius: id 45553, slug `keytill`. Las credenciales
 * públicas viven en {@see self::credentials()}; la *secret key* NO está acá ni
 * en ningún lado del plugin, a propósito (ver ese método).
 *
 * Si el SDK no está vendorizado, {@see self::active()} devuelve false y
 * KEYTILL_Pro cae a su default, sin romper nada.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integración con el proveedor de licencias.
 */
class KEYTILL_License {

	/**
	 * Rutas posibles del SDK, relativas al plugin. Se prueban en orden.
	 * `vendor/freemius` es lo que recomienda la guía de integración; la segunda
	 * queda por si alguien lo vendoriza en la raíz.
	 */
	const SDK_PATHS = array( 'vendor/freemius/start.php', 'freemius/start.php' );

	/** @var object|null Instancia del SDK. */
	private static $sdk = null;

	/** @var bool ¿Ya intentamos arrancar? */
	private static $booted = false;

	/**
	 * Credenciales del producto.
	 *
	 * El `id` y el `public_key` son PÚBLICOS por diseño: viajan dentro de cada
	 * copia distribuida del plugin, porque sin ellos ninguna instalación podría
	 * validar su licencia. Por eso van acá y no en wp-config: pedirle a cada
	 * usuario que edite su configuración sería absurdo.
	 *
	 * La que NUNCA entra al plugin es la *secret key*: es para comunicación
	 * servidor-a-servidor desde el backend propio. Quien tenga el zip puede leer
	 * cualquier cosa que esté adentro, así que ahí no va ningún secreto.
	 *
	 * Las constantes siguen soportadas como override (útil para apuntar a otro
	 * producto en pruebas, o para un fork).
	 *
	 * @return array{id:string,slug:string,public_key:string}
	 */
	public static function credentials() {
		$cfg = array(
			'id'         => defined( 'KEYTILL_FS_ID' ) ? (string) KEYTILL_FS_ID : '45553',
			'slug'       => 'keytill',
			'public_key' => defined( 'KEYTILL_FS_PUBLIC_KEY' ) ? (string) KEYTILL_FS_PUBLIC_KEY : 'pk_a1cc773f6d05b2b9adae0363b7711',
		);

		/**
		 * Permite configurar el proveedor de licencias sin constantes.
		 *
		 * @param array $cfg id, slug y public_key.
		 */
		return (array) apply_filters( 'keytill_license_config', $cfg );
	}

	/**
	 * ¿Está el proveedor configurado Y disponible?
	 *
	 * @return bool
	 */
	public static function active() {
		return null !== self::sdk();
	}

	/**
	 * Instancia del SDK, o null si no se puede usar.
	 *
	 * @return object|null
	 */
	public static function sdk() {
		if ( self::$booted ) {
			return self::$sdk;
		}
		self::$booted = true;

		$cfg = self::credentials();
		if ( empty( $cfg['id'] ) || empty( $cfg['public_key'] ) ) {
			return null; // sin credenciales no se arranca nada
		}

		$sdk_path = '';
		foreach ( self::SDK_PATHS as $rel ) {
			if ( file_exists( KEYTILL_DIR . $rel ) ) {
				$sdk_path = KEYTILL_DIR . $rel;
				break;
			}
		}
		if ( '' === $sdk_path ) {
			return null; // el SDK no está vendorizado
		}

		require_once $sdk_path;
		if ( ! function_exists( 'fs_dynamic_init' ) ) {
			return null;
		}

		self::$sdk = fs_dynamic_init(
			array(
				'id'             => $cfg['id'],
				'slug'           => $cfg['slug'],
				'type'           => 'plugin',
				'public_key'     => $cfg['public_key'],
				'is_premium'     => false,
				// El plugin tiene que servir sin licencia: es el requisito de
				// WordPress.org y, además, la versión gratuita es el embudo.
				'has_premium_version' => true,
				'has_addons'     => false,
				// Pasa a true cuando el plan PRO exista y esté publicado en el
				// panel de Freemius. Con false, el SDK no muestra la oferta.
				'has_paid_plans' => true,
				// El plugin se distribuye por WordPress.org: el SDK ajusta su
				// comportamiento (opt-in no forzado, sin updater para la free).
				'is_org_compliant' => true,
				'menu'           => array(
					'slug'    => KEYTILL_Settings::MENU,
					'support' => false,
				),
			)
		);

		return self::$sdk;
	}

	/**
	 * ¿Hay una licencia paga vigente?
	 *
	 * @return bool
	 */
	public static function is_paying() {
		$sdk = self::sdk();
		if ( ! $sdk ) {
			return false;
		}
		// can_use_premium_code__premium_only() no existe en el build gratuito;
		// is_paying() sí, y es lo que corresponde para un plugin con un solo
		// codebase para las dos versiones.
		return method_exists( $sdk, 'is_paying' ) ? (bool) $sdk->is_paying() : false;
	}

	/**
	 * ¿Está en período de prueba?
	 *
	 * @return bool
	 */
	public static function is_trial() {
		$sdk = self::sdk();
		if ( ! $sdk ) {
			return false;
		}
		return method_exists( $sdk, 'is_trial' ) ? (bool) $sdk->is_trial() : false;
	}

	/**
	 * URL para contratar o gestionar la licencia, o '' si no aplica.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		$sdk = self::sdk();
		if ( ! $sdk || ! method_exists( $sdk, 'get_upgrade_url' ) ) {
			return '';
		}
		return (string) $sdk->get_upgrade_url();
	}
}
