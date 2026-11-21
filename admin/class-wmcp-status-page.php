<?php
/**
 * Page d'état, en lecture seule.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Status_Page
 *
 * Aucun réglage ici : les droits s'éditent dans WBoard. La page sert à
 * constater ce qui est en place sur le site.
 */
class WMCP_Status_Page {

	const PAGE_SLUG = 'wmcp-status';

	/**
	 * État du site.
	 *
	 * @var WMCP_Site_Status
	 */
	private $site_status;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Services     $services     Services partagés.
	 * @param WMCP_Dependencies $dependencies Contrôle des prérequis.
	 */
	public function __construct( WMCP_Services $services, WMCP_Dependencies $dependencies ) {
		$this->site_status = new WMCP_Site_Status( $services, $dependencies );
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Ajoute la page au menu Outils.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page( 'W-MCP', 'W-MCP', 'manage_options', self::PAGE_SLUG, array( $this, 'render' ) );
	}

	/**
	 * Affiche la page.
	 *
	 * @return void
	 */
	public function render() {
		$status = $this->site_status->get_status();

		echo '<div class="wrap"><h1>W-MCP</h1>';
		echo '<p>' . esc_html__( 'Read-only status. Permissions are managed from WBoard.', 'w-mcp' ) . '</p>';

		$this->render_summary( $status );
		$this->render_users( $status['users'] );
		$this->render_abilities( $status['abilities'] );

		echo '</div>';
	}

	/**
	 * Affiche le résumé : matrice, collecte, versions.
	 *
	 * @param array<string,mixed> $status État du site.
	 * @return void
	 */
	private function render_summary( array $status ) {
		$rows = array(
			__( 'Policy version', 'w-mcp' )      => $status['policy_version'] > 0 ? (string) $status['policy_version'] : __( 'None: MCP access is closed.', 'w-mcp' ),
			__( 'MCP access', 'w-mcp' )          => $status['paused'] ? __( 'Paused', 'w-mcp' ) : __( 'Open to users of the policy', 'w-mcp' ),
			__( 'Site guidelines', 'w-mcp' )     => $status['has_site_context'] ? __( 'In place', 'w-mcp' ) : __( 'Not written yet', 'w-mcp' ),
			__( 'Last log collection', 'w-mcp' ) => '' !== $status['log']['last_collected_at'] ? $status['log']['last_collected_at'] . ' UTC' : __( 'Never', 'w-mcp' ),
			__( 'Log entries', 'w-mcp' )         => (string) $status['log']['last_id'],
			__( 'Capability requests', 'w-mcp' ) => (string) $status['requests']['last_id'],
			__( 'MCP Adapter', 'w-mcp' )         => $status['versions']['mcp_adapter'],
			__( 'W-MCP', 'w-mcp' )               => $status['versions']['wmcp'],
		);

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:40%">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $status['pre_execute_filters'] ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Callbacks are hooked on wp_pre_execute_ability and can bypass permission checks:', 'w-mcp' );
			echo ' <code>' . esc_html( implode( ', ', $status['pre_execute_filters'] ) ) . '</code></p></div>';
		}
	}

	/**
	 * Affiche les utilisateurs et leur profil.
	 *
	 * @param array<int,array<string,mixed>> $users Utilisateurs candidats.
	 * @return void
	 */
	private function render_users( array $users ) {
		echo '<h2>' . esc_html__( 'Users', 'w-mcp' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'User', 'w-mcp' ) . '</th><th>' . esc_html__( 'Role', 'w-mcp' ) . '</th><th>' . esc_html__( 'MCP profile', 'w-mcp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $users as $user ) {
			echo '<tr><td>' . esc_html( $user['display_name'] . ' (' . $user['login'] . ')' ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $user['roles'] ) ) . '</td>';
			echo '<td>' . esc_html( $user['profile'] ?? __( 'No access', 'w-mcp' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Affiche le catalogue des abilities.
	 *
	 * @param array<int,array<string,mixed>> $abilities Catalogue.
	 * @return void
	 */
	private function render_abilities( array $abilities ) {
		echo '<h2>' . esc_html__( 'Abilities on this site', 'w-mcp' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'Ability', 'w-mcp' ) . '</th><th>' . esc_html__( 'Level', 'w-mcp' ) . '</th><th>' . esc_html__( 'Exposed to MCP', 'w-mcp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $abilities as $ability ) {
			echo '<tr><td><code>' . esc_html( $ability['name'] ) . '</code></td>';
			echo '<td>' . esc_html( $ability['level'] ) . '</td>';
			echo '<td>' . esc_html( $ability['mcp_public'] ? __( 'Yes', 'w-mcp' ) : __( 'No', 'w-mcp' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
