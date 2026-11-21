<?php
/**
 * Pont vers le serveur OAuth vendoré, et garde-fous d'accès.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_OAuth
 *
 * Le serveur OAuth lui-même vit dans `lib/wp-oauth/` : c'est le module de la
 * PR WordPress/mcp-adapter#437, vendoré tel quel (voir lib/wp-oauth/VENDORED.md).
 * Cette classe fait seulement le lien avec lui :
 *
 *  1. elle lui déclare les routes MCP à protéger (le module ignore tout du MCP) ;
 *  2. elle impose trois garde-fous repérés à l'audit, par ses hooks d'extension,
 *     sans toucher à son code.
 *
 * Les garde-fous sont volontairement fail-closed : en cas de doute, on refuse.
 */
class WMCP_OAuth {

	/**
	 * Option listant les URL de documents client CIMD autorisées.
	 *
	 * Tableau d'URL exactes. Vide : on s'en remet à l'approbation admin que le
	 * module impose déjà pour tout document inconnu. Non vide : seules ces URL
	 * passent. À verrouiller sur l'URL de Claude après la première connexion.
	 *
	 * @var string
	 */
	const CIMD_ALLOWLIST_OPTION = 'wmcp_oauth_cimd_allowlist';

	/**
	 * Stockage de la matrice, pour savoir qui a le droit d'autoriser une app.
	 *
	 * @var WMCP_Policy_Store
	 */
	private $policy_store;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Policy_Store $policy_store Stockage de la matrice.
	 */
	public function __construct( WMCP_Policy_Store $policy_store ) {
		$this->policy_store = $policy_store;
	}

	/**
	 * Indique si le serveur OAuth vendoré est chargé.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP\\OAuth\\Server' );
	}

	/**
	 * Enregistre les hooks du pont.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'oauth_server_protected_resources', array( $this, 'add_mcp_routes' ) );
		add_filter( 'oauth_server_cimd_admit_url', array( $this, 'admit_cimd_url' ), 10, 2 );
		// Priorité 1 : avant le gestionnaire du module (priorité 10).
		add_action( 'login_form_oauth_authorize', array( $this, 'guard_authorize' ), 1 );
	}

	/**
	 * Déclare les routes des serveurs MCP comme ressources protégées.
	 *
	 * Reprend la logique de la PR (includes/Plugin.php) : l'adapter ne crée ses
	 * serveurs qu'au `rest_api_init`, donc on l'amorce si on est appelé en
	 * dehors d'une requête REST (découverte, écran de consentement).
	 *
	 * @param mixed $routes Routes déjà déclarées.
	 * @return mixed
	 */
	public function add_mcp_routes( $routes ) {
		if ( ! is_array( $routes ) || ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			return $routes;
		}

		if ( ! did_action( 'rest_api_init' ) ) {
			rest_get_server();
		}

		foreach ( WP\MCP\Core\McpAdapter::instance()->get_servers() as $server ) {
			$routes[] = '/' . $server->get_server_route_namespace() . '/' . $server->get_server_route();
		}

		return $routes;
	}

	/**
	 * Garde-fous sur la demande d'autorisation.
	 *
	 * Deux refus, avant que le module ne traite la demande :
	 *  - une demande d'autorisation sans `resource` donnerait un jeton ouvert à
	 *    toute l'API REST (constat haute gravité) : on l'interdit ;
	 *  - seul un utilisateur présent dans la matrice peut autoriser une app,
	 *    pour que ce soit toi qui décides qui connecte une IA au site.
	 *
	 * La soumission du consentement (via `request_id`) et l'approbation d'un
	 * document CIMD ne portent pas de `resource` : on ne contrôle celui-ci que
	 * sur la demande initiale, reconnaissable à son `response_type`.
	 *
	 * @return void
	 */
	public function guard_authorize() {
		$is_initial_request = '' !== $this->request_value( 'response_type' );
		if ( $is_initial_request && '' === $this->request_value( 'resource' ) ) {
			wp_die(
				esc_html__( 'Cette connexion doit préciser la ressource visée (le serveur MCP du site). Demande refusée.', 'w-mcp' ),
				esc_html__( 'Connexion refusée', 'w-mcp' ),
				array( 'response' => 400 )
			);
		}

		if ( is_user_logged_in() && ! $this->user_may_authorize( get_current_user_id() ) ) {
			wp_die(
				esc_html__( 'Ton compte n\'est pas autorisé à connecter une application à ce site. Contacte la personne qui gère la maintenance.', 'w-mcp' ),
				esc_html__( 'Connexion refusée', 'w-mcp' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Applique l'allowlist des documents CIMD, si elle est renseignée.
	 *
	 * @param bool   $admit Décision du module (true par défaut).
	 * @param string $id    URL du document de métadonnées client.
	 * @return bool
	 */
	public function admit_cimd_url( $admit, $id ) {
		$allowlist = get_option( self::CIMD_ALLOWLIST_OPTION, array() );
		if ( ! is_array( $allowlist ) || array() === $allowlist ) {
			return (bool) $admit;
		}

		return in_array( (string) $id, $allowlist, true );
	}

	/**
	 * Indique si un utilisateur peut autoriser une application.
	 *
	 * @param int $user_id Identifiant WordPress.
	 * @return bool
	 */
	private function user_may_authorize( $user_id ) {
		return $this->policy_store->get_resolver()->decide_access( $user_id )->is_allowed();
	}

	/**
	 * Lit une valeur de la requête d'autorisation, nettoyée.
	 *
	 * @param string $key Nom du paramètre.
	 * @return string Chaîne vide si absent.
	 */
	private function request_value( $key ) {
		// Lecture seule pour un contrôle d'accès ; le module vérifie ensuite le nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_string( $_REQUEST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return trim( sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) );
	}
}
