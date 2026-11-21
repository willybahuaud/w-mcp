<?php
/**
 * Contrôle des prérequis du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Dependencies
 *
 * W-MCP ne fait rien seul : il lui faut l'Abilities API de WordPress 7.1,
 * le MCP Adapter et wboard-connector. S'il en manque un, on ne charge rien.
 */
class WMCP_Dependencies {

	/**
	 * Version minimale du MCP Adapter.
	 *
	 * @var string
	 */
	const MIN_ADAPTER_VERSION = '0.7.2';

	/**
	 * Retourne la liste des prérequis manquants.
	 *
	 * @return string[] Messages lisibles, vide si tout est en place.
	 */
	public function get_missing() {
		$missing = array();

		// `wp_ability_invoked` et le filtre de permission n'existent qu'à partir de 7.1.
		if ( ! function_exists( 'wp_register_ability' ) || version_compare( get_bloginfo( 'version' ), '7.1', '<' ) ) {
			$missing[] = __( 'WordPress 7.1 or later (Abilities API).', 'w-mcp' );
		}
		if ( ! $this->has_adapter() ) {
			/* translators: %s: minimum MCP Adapter version. */
			$missing[] = sprintf( __( 'The MCP Adapter plugin, version %s or later.', 'w-mcp' ), self::MIN_ADAPTER_VERSION );
		}
		if ( ! class_exists( 'WBoard_Connector_Security' ) ) {
			$missing[] = __( 'The WBoard Connector plugin (signs the management routes).', 'w-mcp' );
		}

		return $missing;
	}

	/**
	 * Retourne la version du MCP Adapter, si elle est connue.
	 *
	 * @return string Chaîne vide si l'adapter est absent.
	 */
	public function get_adapter_version() {
		if ( defined( 'WP_MCP_VERSION' ) ) {
			return (string) WP_MCP_VERSION;
		}
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) && defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ) {
			return (string) \WP\MCP\Core\McpAdapter::VERSION;
		}

		return '';
	}

	/**
	 * Indique si le MCP Adapter est présent dans une version suffisante.
	 *
	 * @return bool
	 */
	private function has_adapter() {
		$version = $this->get_adapter_version();

		return '' !== $version && version_compare( $version, self::MIN_ADAPTER_VERSION, '>=' );
	}

	/**
	 * Affiche la notice d'administration listant les prérequis manquants.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>W-MCP</strong> : ';
		esc_html_e( 'the plugin is inactive because requirements are missing.', 'w-mcp' );
		echo '</p><ul style="list-style:disc;margin-left:2em">';
		foreach ( $this->get_missing() as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}
}
