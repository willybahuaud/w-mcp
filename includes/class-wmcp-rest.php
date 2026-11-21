<?php
/**
 * Routes de pilotage, appelées par WBoard.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Rest
 *
 * Le board tire, le site ne pousse pas : même sens d'échange que le connector.
 * Toutes les routes sont protégées par sa signature HMAC. Aucune capability
 * WordPress n'y donne accès, pas même celle d'un administrateur.
 */
class WMCP_Rest {

	const API_NAMESPACE = 'wmcp/v1';

	/**
	 * Taille de page par défaut pour la collecte.
	 *
	 * @var int
	 */
	const DEFAULT_PAGE_SIZE = 200;

	/**
	 * Routes de pilotage et méthode qui les sert.
	 *
	 * @var array<string,string>
	 */
	const ROUTES = array(
		'/status'       => 'get_status',
		'/policy'       => 'put_policy',
		'/pause'        => 'post_pause',
		'/log'          => 'get_log',
		'/read-counts'  => 'get_read_counts',
		'/requests'     => 'get_requests',
		'/oauth-client' => 'post_oauth_client',
	);

	/**
	 * URI de redirection du connecteur claude.ai (OAuth).
	 *
	 * @var string
	 */
	const CLAUDE_REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

	const REPLAY_PREFIX = 'wmcp_req_';

	/**
	 * Durée de mémoire d'un identifiant de requête, en secondes.
	 *
	 * @var int
	 */
	const REPLAY_TTL = 900;

	/**
	 * Services partagés.
	 *
	 * @var WMCP_Services
	 */
	private $services;

	/**
	 * Identifiant de la requête en cours, une fois réservé.
	 *
	 * @var string
	 */
	private $claimed_request_id = '';

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
		$this->services    = $services;
		$this->site_status = new WMCP_Site_Status( $services, $dependencies );
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes.
	 *
	 * Toutes en POST : la signature du connector ne couvre que le corps de la
	 * requête. Avec un GET, rien de ce qui distingue une route d'une autre ne
	 * serait signé.
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( self::ROUTES as $route => $callback ) {
			register_rest_route(
				self::API_NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_signature' ),
				)
			);
		}
	}

	/**
	 * Vérifie que la requête vient du board, pour cette route, et pour la première fois.
	 *
	 * Le connector garantit que le corps a été signé par le board dans les cinq
	 * dernières minutes. Il ne lie la signature ni à la route ni à un usage
	 * unique : une requête observée pourrait être rejouée, ou renvoyée vers une
	 * autre route. Le corps signé porte donc la route visée (`_route`) et un
	 * identifiant à usage unique (`_id`), contrôlés ici.
	 *
	 * Sans limite de débit : la collecte enchaîne les pages, et un visiteur ne
	 * doit pas pouvoir bloquer la coupure d'accès en saturant un compteur.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool|WP_Error
	 */
	public function check_signature( WP_REST_Request $request ) {
		$verified = ( new WBoard_Connector_Security() )->verify_request_no_ratelimit( $request );
		if ( true !== $verified ) {
			return $verified;
		}

		$body = (array) $request->get_json_params();
		if ( '/' . self::API_NAMESPACE . (string) ( $body['_route'] ?? '' ) !== $request->get_route() ) {
			return new WP_Error( 'wmcp_route_mismatch', __( 'This request was signed for another route.', 'w-mcp' ), array( 'status' => 401 ) );
		}

		return $this->claim_request_id( (string) ( $body['_id'] ?? '' ) );
	}

	/**
	 * Réserve l'identifiant d'une requête : il ne sert qu'une fois.
	 *
	 * @param string $request_id Identifiant fourni par le board, 32 caractères hexadécimaux.
	 * @return true|WP_Error
	 */
	private function claim_request_id( $request_id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $request_id ) ) {
			return new WP_Error( 'wmcp_missing_request_id', __( 'A unique request identifier is required.', 'w-mcp' ), array( 'status' => 401 ) );
		}

		// WordPress appelle ce contrôle une seconde fois en fin de requête, pour
		// composer l'en-tête Allow : la même requête doit alors repasser.
		if ( $request_id === $this->claimed_request_id ) {
			return true;
		}

		$transient = self::REPLAY_PREFIX . $request_id;
		if ( false !== get_transient( $transient ) ) {
			return new WP_Error( 'wmcp_replayed_request', __( 'This request was already received.', 'w-mcp' ), array( 'status' => 401 ) );
		}

		// Gardé plus longtemps que la fenêtre d'horodatage du connector (5 minutes).
		set_transient( $transient, 1, self::REPLAY_TTL );
		$this->claimed_request_id = $request_id;

		return true;
	}

	/**
	 * POST /status : état du site et catalogue des abilities.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response( $this->site_status->get_status() );
	}

	/**
	 * POST /policy : remplace la matrice et, s'il est fourni, le document de cadrage.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ policy, site_context? }`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_policy( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$result = $this->services->policy_store->save_policy( $body['policy'] ?? null );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $body['site_context'] ) && is_string( $body['site_context'] ) ) {
			$this->services->policy_store->save_site_context( $body['site_context'] );
		}

		return rest_ensure_response(
			array(
				'applied'        => true,
				'policy_version' => $this->services->policy_store->get_resolver()->get_version(),
			)
		);
	}

	/**
	 * POST /pause : coupe ou rétablit l'accès MCP du site.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ paused: bool }`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_pause( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		if ( ! isset( $body['paused'] ) || ! is_bool( $body['paused'] ) ) {
			return new WP_Error( 'wmcp_invalid_pause', __( 'The "paused" field must be a boolean.', 'w-mcp' ), array( 'status' => 400 ) );
		}

		$result = $this->services->policy_store->set_paused( $body['paused'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'paused' => $body['paused'] ) );
	}

	/**
	 * POST /log : lignes du journal après un curseur.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ after_id, limit }`.
	 * @return WP_REST_Response
	 */
	public function get_log( WP_REST_Request $request ) {
		return rest_ensure_response( $this->collect( $this->services->log_store, $request ) );
	}

	/**
	 * POST /requests : demandes de fonctionnalité après un curseur.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ after_id, limit }`.
	 * @return WP_REST_Response
	 */
	public function get_requests( WP_REST_Request $request ) {
		return rest_ensure_response( $this->collect( $this->services->request_store, $request ) );
	}

	/**
	 * POST /read-counts : compteurs de lecture depuis une date.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ since }` (Y-m-d).
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_read_counts( WP_REST_Request $request ) {
		$since = (string) ( ( (array) $request->get_json_params() )['since'] ?? '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			return new WP_Error( 'wmcp_invalid_since', __( 'The "since" parameter must be a date (YYYY-MM-DD).', 'w-mcp' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'rows' => $this->services->log_store->get_read_counts( $since ) ) );
	}

	/**
	 * POST /oauth-client : crée (ou retrouve) un client OAuth public pour claude.ai.
	 *
	 * Appareillage piloté depuis le board : plutôt que de poser une clé
	 * d'application à la main, le board nomme la connexion et récupère le
	 * client_id à coller dans claude.ai. Le label devient la colonne `client`
	 * du journal : c'est l'attribution de l'outil.
	 *
	 * Idempotent : un client du même nom et de même redirection est renvoyé tel
	 * quel, pour qu'un renvoi de la requête ne crée pas de doublon.
	 *
	 * @param WP_REST_Request $request Requête, corps JSON `{ label }`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_oauth_client( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP\\OAuth\\Clients\\ClientRepository' ) ) {
			return new WP_Error( 'wmcp_oauth_unavailable', __( 'The OAuth module is not active on this site.', 'w-mcp' ), array( 'status' => 501 ) );
		}

		$body  = (array) $request->get_json_params();
		$label = trim( (string) ( $body['label'] ?? '' ) );
		if ( '' === $label ) {
			return new WP_Error( 'wmcp_oauth_missing_label', __( 'A label is required to name the connection.', 'w-mcp' ), array( 'status' => 400 ) );
		}

		$repo     = new \WP\OAuth\Clients\ClientRepository();
		$existing = $this->find_client_by_label( $repo, $label );
		$client   = $existing;

		if ( null === $client ) {
			$result = $repo->create( $label, array( self::CLAUDE_REDIRECT_URI ), false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$client = $result[0];
		}

		return rest_ensure_response(
			array(
				'client_id'    => $client->get_id(),
				'label'        => $client->get_name(),
				'redirect_uri' => self::CLAUDE_REDIRECT_URI,
				'mcp_url'      => $this->mcp_server_url(),
				'reused'       => null !== $existing,
			)
		);
	}

	/**
	 * Retrouve un client public déjà enregistré pour ce label et cette redirection.
	 *
	 * @param \WP\OAuth\Clients\ClientRepository $repo  Dépôt des clients.
	 * @param string                             $label Nom exact.
	 * @return \WP\OAuth\Clients\Client|null
	 */
	private function find_client_by_label( $repo, $label ) {
		foreach ( $repo->all() as $client ) {
			if ( $client->get_name() === $label && in_array( self::CLAUDE_REDIRECT_URI, $client->get_redirect_uris(), true ) ) {
				return $client;
			}
		}

		return null;
	}

	/**
	 * URL REST du premier serveur MCP du site, à coller dans claude.ai.
	 *
	 * @return string Chaîne vide si l'adapter n'expose aucun serveur.
	 */
	private function mcp_server_url() {
		if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			return '';
		}

		foreach ( \WP\MCP\Core\McpAdapter::instance()->get_servers() as $server ) {
			return rest_url( $server->get_server_route_namespace() . '/' . $server->get_server_route() );
		}

		return '';
	}

	/**
	 * Lit une page d'une table collectée.
	 *
	 * Le curseur envoyé par le board vaut accusé de réception : tout ce qui le
	 * précède est marqué comme collecté, puis la page suivante est renvoyée.
	 *
	 * @param WMCP_Table_Store $store   Table à lire.
	 * @param WP_REST_Request  $request Requête.
	 * @return array<string,mixed>
	 */
	private function collect( WMCP_Table_Store $store, WP_REST_Request $request ) {
		// Lus dans le corps signé, jamais dans l'URL : le curseur vaut accusé de réception.
		$body     = (array) $request->get_json_params();
		$after_id = absint( $body['after_id'] ?? 0 );
		$limit    = absint( $body['limit'] ?? 0 );

		$store->mark_collected_up_to( $after_id );

		$rows = $store->get_after( $after_id, $limit > 0 ? $limit : self::DEFAULT_PAGE_SIZE );
		$last = end( $rows );

		return array(
			'rows'     => $rows,
			'next_id'  => false === $last ? $after_id : (int) $last['id'],
			'has_more' => false !== $last && (int) $last['id'] < $store->get_last_id(),
		);
	}
}
