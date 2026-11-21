<?php
/**
 * Chargement automatique des classes du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Autoloader
 *
 * Convertit `WMCP_Foo_Bar` en `class-wmcp-foo-bar.php` et cherche le fichier
 * dans les répertoires du plugin, selon la convention de nommage WordPress.
 */
class WMCP_Autoloader {

	/**
	 * Préfixe commun à toutes les classes du plugin.
	 *
	 * @var string
	 */
	const CLASS_PREFIX = 'WMCP_';

	/**
	 * Répertoires parcourus, relatifs à la racine du plugin.
	 *
	 * @var string[]
	 */
	const DIRECTORIES = array( 'includes', 'abilities', 'admin' );

	/**
	 * Racine du plugin, avec slash final.
	 *
	 * @var string
	 */
	private static $base_path = '';

	/**
	 * Enregistre l'autoloader.
	 *
	 * @param string $base_path Racine du plugin, avec slash final.
	 * @return void
	 */
	public static function register( $base_path ) {
		self::$base_path = $base_path;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Charge le fichier d'une classe du plugin.
	 *
	 * @param string $class_name Nom de la classe demandée.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::CLASS_PREFIX ) ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		foreach ( self::DIRECTORIES as $directory ) {
			$file_path = self::$base_path . $directory . '/' . $file_name;
			if ( is_readable( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}
}
