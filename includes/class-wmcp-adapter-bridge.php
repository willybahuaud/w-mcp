<?php
/**
 * Pont avec le MCP Adapter.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Adapter_Bridge
 *
 * Seul point de contact avec les classes et les hooks de l'adapter. Le jour où
 * il entre dans le core et change de forme, c'est ce fichier qu'on met à jour.
 *
 * Deux rôles : repérer qu'une requête est une requête MCP, et fermer la porte
 * aux utilisateurs absents de la matrice.
 */
class WMCP_Adapter_Bridge {

	/**
	 * Interface implémentée par les transports REST de l'adapter.
	 *
	 * @var string
	 */
	const REST_TRANSPORT_INTERFACE = '\WP\MCP\Transport\Contracts\McpRestTransportInterface';

	/**
	 * En-tête HTTP portant l'identifiant de session MCP.
	 *
	 * @var string
	 */
	const SESSION_HEADER = 'mcp_session_id';

	/**
	 * Contexte de la requête.
	 *
	 * @var WMCP_Context
	 */
	private $context;

	/**
	 * Stockage de la matrice.
	 *
	 * @var WMCP_Policy_Store
	 */
	private $policy_store;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Context      $context      Contexte de la requête.
	 * @param WMCP_Policy_Store $policy_store Stockage de la matrice.
	 */
	public function __construct( WMCP_Context $context, WMCP_Policy_Store $policy_store ) {
		$this->context      = $context;
		$this->policy_store = $policy_store;
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'on_rest_request' ), 10, 3 );
		add_filter( 'mcp_adapter_pre_tool_call', array( $this, 'on_pre_tool_call' ), 10, 3 );
		add_filter( 'mcp_adapter_pre_resource_read', array( $this, 'on_pre_read' ), 10, 1 );
		add_filter( 'mcp_adapter_pre_prompt_get', array( $this, 'on_pre_read' ), 10, 1 );
	}

	/**
	 * Active le contexte MCP à la lecture d'une ressource ou d'un prompt.
	 *
	 * Sans cela, par le transport stdio, une ressource adossée à une ability
	 * s'exécuterait hors contexte, donc hors matrice et hors journal.
	 *
	 * @param mixed $payload Paramètres de la lecture.
	 * @return mixed Les paramètres, inchangés.
	 */
	public function on_pre_read( $payload ) {
		$this->context->activate();

		return $payload;
	}

	/**
	 * Repère une requête REST adressée à un serveur MCP et contrôle l'accès.
	 *
	 * Ce filtre passe avant le callback de permission de la route : le contexte
	 * est donc actif pendant tout le traitement, contrôle de permission compris.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Résultat en cours.
	 * @param array<string,mixed>                              $handler  Gestionnaire de la route.
	 * @param WP_REST_Request                                  $request  Requête.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public function on_rest_request( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $this->is_mcp_handler( $handler ) ) {
			return $response;
		}

		$this->context->activate( (string) $request->get_header( self::SESSION_HEADER ) );

		// Sans utilisateur, on laisse l'adapter répondre : les couches OAuth
		// s'appuient sur son 401 pour lancer la découverte.
		if ( ! is_user_logged_in() ) {
			return $response;
		}

		$decision = $this->policy_store->get_resolver()->decide_access( get_current_user_id() );
		if ( $decision->is_allowed() ) {
			return $response;
		}

		return new WP_Error( 'wmcp_access_denied', WMCP_Messages::access_denied( $decision ), array( 'status' => 403 ) );
	}

	/**
	 * Refuse les outils MCP qui ne reposent pas sur une ability.
	 *
	 * La matrice et le journal travaillent au niveau de l'Abilities API : un
	 * outil déclaré directement auprès de l'adapter leur échapperait.
	 *
	 * @param array<string,mixed>|WP_Error $arguments Arguments de l'appel.
	 * @param string                       $tool_name Nom de l'outil MCP.
	 * @param object                       $mcp_tool  Outil MCP (`McpTool` de l'adapter).
	 * @return array<string,mixed>|WP_Error
	 */
	public function on_pre_tool_call( $arguments, $tool_name, $mcp_tool ) {
		// Le transport stdio (WP-CLI) ne passe pas par REST : on active le contexte ici.
		$this->context->activate();

		if ( is_wp_error( $arguments ) ) {
			return $arguments;
		}

		$is_ability_backed = is_object( $mcp_tool ) && method_exists( $mcp_tool, 'is_ability_backed' ) && $mcp_tool->is_ability_backed();
		if ( $is_ability_backed ) {
			return $this->is_describing_denied_ability( $tool_name, $arguments )
				? new WP_Error( 'wmcp_denied', WMCP_Messages::action_denied( 'This ability is not available to this user.' ) )
				: $arguments;
		}

		return new WP_Error(
			'wmcp_tool_not_governed',
			WMCP_Messages::action_denied( sprintf( 'The tool "%s" is not backed by a WordPress ability, so it cannot be governed by the site access policy and is disabled.', $tool_name ) )
		);
	}

	/**
	 * Indique si l'appel demande la description d'une ability que la matrice refuse.
	 *
	 * L'outil `get-ability-info` de l'adapter lit le registre sans passer par le
	 * filtre de découverte : il donnerait le schéma d'abilities masquées.
	 *
	 * @param string                    $tool_name Nom de l'outil MCP.
	 * @param array<string,mixed>|mixed $arguments Arguments de l'appel.
	 * @return bool
	 */
	private function is_describing_denied_ability( $tool_name, $arguments ) {
		if ( false === strpos( (string) $tool_name, 'get-ability-info' ) || ! is_array( $arguments ) ) {
			return false;
		}

		$ability_name = (string) ( $arguments['ability_name'] ?? '' );
		$ability      = '' !== $ability_name && wp_has_ability( $ability_name ) ? wp_get_ability( $ability_name ) : null;
		if ( ! $ability instanceof WP_Ability ) {
			return false;
		}

		return ! $this->policy_store->get_resolver()->decide( get_current_user_id(), $ability_name, WMCP_Guard::get_level( $ability ) )->is_allowed();
	}

	/**
	 * Indique si une route REST est servie par un transport de l'adapter.
	 *
	 * @param array<string,mixed>|mixed $handler Gestionnaire de la route.
	 * @return bool
	 */
	private function is_mcp_handler( $handler ) {
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;

		return is_array( $callback )
			&& isset( $callback[0] )
			&& is_object( $callback[0] )
			&& interface_exists( self::REST_TRANSPORT_INTERFACE )
			&& is_a( $callback[0], self::REST_TRANSPORT_INTERFACE );
	}
}
