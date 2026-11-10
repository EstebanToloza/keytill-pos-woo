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
 * Para dejarlo operativo hacen falta tres cosas, en este orden:
 *
 *   1. Dar de alta el producto en el panel de Freemius y anotar `id`,
 *      `public_key` y el slug.
 *   2. Definir las credenciales (ver {@see self::credentials()}): o por
 *      constantes en wp-config.php, o por el filtro `keytill_license_config`.
 *   3. Vendorizar el SDK en `freemius/` (su `start.php` es el punto de entrada).
 *
 * Mientras falte cualquiera de las tres, {@see self::active()} devuelve false y
 * KEYTILL_Pro cae a su default.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integración con el proveedor de licencias.
 */
class KEYTILL_License {

	/** Ruta del SDK, relativa al plugin. */
	const SDK = 'freemius/start.php';

	/** @var object|null Instancia del SDK. */
	private static $sdk = null;

	/** @var bool ¿Ya intentamos arrancar? */
	private static $booted = false;

	/**
	 * Credenciales del producto.
	 *
	 * Se leen de constantes para que no vivan en el repositorio (la secret key
	 * NO va en un plugin distribuido; el `public_key` sí puede). El filtro es
	 * para los tests y para quien prefiera configurarlo por código.
	 *
	 * @return array{id:string,slug:string,public_key:string}
	 */
	public static function credentials() {
		$cfg = array(
			'id'         => defined( 'KEYTILL_FS_ID' ) ? (string) KEYTILL_FS_ID : '',
			'slug'       => 'keytill',
			'public_key' => defined( 'KEYTILL_FS_PUBLIC_KEY' ) ? (string) KEYTILL_FS_PUBLIC_KEY : '',
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

		$sdk_path = KEYTILL_DIR . self::SDK;
		if ( ! file_exists( $sdk_path ) ) {
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
				'has_paid_plans' => true,
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
