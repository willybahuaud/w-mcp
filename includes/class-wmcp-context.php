<?php
/**
 * Contexte de la requête MCP en cours.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Context
 *
 * Dit si l'exécution courante vient du MCP Adapter. La matrice et le journal ne
 * s'appliquent que dans ce cas : une ability appelée par un plugin, WP-CLI ou
 * l'éditeur garde son comportement normal.
 */
class WMCP_Context {

	/**
	 * Vrai dès qu'une requête MCP est identifiée.
	 *
	 * @var bool
	 */
	private $is_mcp = false;

	/**
	 * Identifiant de session MCP, ou identifiant de requête à défaut.
	 *
	 * @var string
	 */
	private $session_id = '';

	/**
	 * Marque l'exécution courante comme venant du MCP.
	 *
	 * @param string $session_id Identifiant de session MCP, s'il est connu.
	 * @return void
	 */
	public function activate( $session_id = '' ) {
		$this->is_mcp = true;

		if ( '' !== $session_id ) {
			$this->session_id = mb_strcut( sanitize_text_field( $session_id ), 0, 64, 'UTF-8' );
		} elseif ( '' === $this->session_id ) {
			// Sans session (transport stdio), on regroupe au moins les appels de la requête.
			$this->session_id = wp_generate_uuid4();
		}
	}

	/**
	 * Indique si l'exécution courante vient du MCP.
	 *
	 * @return bool
	 */
	public function is_mcp() {
		return $this->is_mcp;
	}

	/**
	 * Retourne l'identifiant de session, pour regrouper les lignes du journal.
	 *
	 * @return string
	 */
	public function get_session_id() {
		return $this->session_id;
	}

	/**
	 * Retourne un libellé du client connecté.
	 *
	 * Avec un mot de passe d'application, c'est son nom (« Claude Code », par exemple).
	 * Les couches OAuth peuvent fournir le leur via le filtre.
	 *
	 * @return string Chaîne vide si rien n'est identifiable.
	 */
	public function get_client_label() {
		$label = '';
		$uuid  = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;

		if ( $uuid ) {
			$app_password = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
			$label        = (string) ( $app_password['name'] ?? '' );
		}

		/**
		 * Filtre le libellé du client MCP enregistré dans le journal.
		 *
		 * @param string $label Libellé déduit du mot de passe d'application, ou chaîne vide.
		 */
		$label = (string) apply_filters( 'wmcp_client_label', $label );

		// mb_strcut : couper au milieu d'un caractère donnerait de l'UTF-8 invalide,
		// que la base refuse. Le nom d'un mot de passe d'application est libre.
		return mb_strcut( sanitize_text_field( $label ), 0, 190, 'UTF-8' );
	}
}
