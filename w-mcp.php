<?php
/**
 * Plugin Name:       W-MCP
 * Plugin URI:        https://github.com/willybahuaud/w-mcp
 * Description:       Encadre l'accès MCP du site : droits par utilisateur pilotés depuis WBoard, journal des actions, remontée des besoins non couverts.
 * Version:           0.3.0
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Author:            Willy Bahuaud
 * Author URI:        https://wabeo.fr
 * License:           GPL-2.0-or-later
 * Text Domain:       w-mcp
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'WMCP_VERSION', '0.3.0' );
define( 'WMCP_FILE', __FILE__ );
define( 'WMCP_PATH', plugin_dir_path( __FILE__ ) );

require_once WMCP_PATH . 'includes/class-wmcp-autoloader.php';

WMCP_Autoloader::register( WMCP_PATH );

/**
 * Charge le serveur OAuth vendoré (module de WordPress/mcp-adapter#437).
 *
 * Chargé ici, et non dans un hook, car le module s'accroche lui-même à
 * `plugins_loaded` (priorité 10) : il doit être présent avant que ce hook ne
 * parte. Il s'auto-neutralise si l'adapter publié fournit déjà OAuth, et peut
 * être coupé en définissant `WMCP_OAUTH` à false avant le chargement du plugin.
 */
if ( ! defined( 'WMCP_OAUTH' ) || WMCP_OAUTH ) {
	require_once WMCP_PATH . 'lib/wp-oauth/load.php';
}

/**
 * Démarre le plugin une fois toutes les extensions chargées.
 *
 * On attend `plugins_loaded` pour pouvoir constater la présence du MCP Adapter
 * et de wboard-connector, quel que soit l'ordre de chargement.
 *
 * @return void
 */
function wmcp_bootstrap() {
	WMCP_Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'wmcp_bootstrap', 20 );

register_activation_hook( __FILE__, array( 'WMCP_Schema', 'install' ) );
register_deactivation_hook( __FILE__, array( 'WMCP_Retention', 'unschedule' ) );
