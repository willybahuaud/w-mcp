<?php
/**
 * État du site vu par W-MCP.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Site_Status
 *
 * Rassemble ce que WBoard a besoin de savoir pour piloter le site : versions,
 * matrice en place, abilities réellement présentes. Sert aussi la page d'état.
 */
class WMCP_Site_Status {

	/**
	 * Services partagés.
	 *
	 * @var WMCP_Services
	 */
	private $services;

	/**
	 * Contrôle des prérequis.
	 *
	 * @var WMCP_Dependencies
	 */
	private $dependencies;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Services     $services     Services partagés.
	 * @param WMCP_Dependencies $dependencies Contrôle des prérequis.
	 */
	public function __construct( WMCP_Services $services, WMCP_Dependencies $dependencies ) {
		$this->services     = $services;
		$this->dependencies = $dependencies;
	}

	/**
	 * Retourne l'état complet du site.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status() {
		$resolver = $this->services->policy_store->get_resolver();

		return array(
			'versions'            => array(
				'wmcp'        => WMCP_VERSION,
				'wordpress'   => get_bloginfo( 'version' ),
				'php'         => PHP_VERSION,
				'mcp_adapter' => $this->dependencies->get_adapter_version(),
			),
			'policy_version'      => $resolver->get_version(),
			'paused'              => $resolver->is_paused(),
			'has_site_context'    => '' !== $this->services->policy_store->get_site_context(),
			'documents_count'     => ( new WMCP_Document_Store() )->count(),
			'log'                 => array(
				// Lignes non écrites : refusées par la base, ou au-delà du plafond horaire.
				'unwritten'         => WMCP_Logger::get_health(),
				'last_id'           => $this->services->log_store->get_last_id(),
				'last_collected_at' => $this->services->log_store->get_last_collected_at(),
			),
			'requests'            => array(
				'last_id'           => $this->services->request_store->get_last_id(),
				'last_collected_at' => $this->services->request_store->get_last_collected_at(),
			),
			'abilities'           => $this->get_abilities_catalog(),
			'users'               => $this->get_candidate_users(),
			'pre_execute_filters' => $this->get_pre_execute_filters(),
		);
	}

	/**
	 * Retourne le catalogue des abilities enregistrées sur le site.
	 *
	 * WBoard s'en sert pour proposer dans la matrice ce qui existe vraiment.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_abilities_catalog() {
		$catalog = array();

		foreach ( wp_get_abilities() as $ability ) {
			$meta      = $ability->get_meta();
			$catalog[] = array(
				'name'           => $ability->get_name(),
				'label'          => $ability->get_label(),
				'category'       => $ability->get_category(),
				'level'          => WMCP_Guard::get_level( $ability ),
				'mcp_public'     => $this->is_exposed_to_mcp( $meta ),
				'always_allowed' => WMCP_Policy_Resolver::is_always_allowed( $ability->get_name() ),
			);
		}

		return $catalog;
	}

	/**
	 * Indique si une ability est exposée au MCP, selon la règle de l'adapter.
	 *
	 * @param array<string,mixed> $meta Métadonnées de l'ability.
	 * @return bool
	 */
	private function is_exposed_to_mcp( array $meta ) {
		if ( isset( $meta['mcp']['public'] ) ) {
			return (bool) $meta['mcp']['public'];
		}

		return true === ( $meta['public'] ?? false );
	}

	/**
	 * Retourne les utilisateurs susceptibles de figurer dans la matrice.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_candidate_users() {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'user_login', 'display_name' ),
				'number'     => 200,
			)
		);

		$candidates = array();
		foreach ( $users as $user ) {
			$candidates[] = array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => array_values( (array) get_userdata( $user->ID )->roles ),
				'profile'      => $this->services->policy_store->get_resolver()->get_profile_name( (int) $user->ID ),
			);
		}

		return $candidates;
	}

	/**
	 * Liste les callbacks accrochés à `wp_pre_execute_ability`.
	 *
	 * Ce filtre du core permet de court-circuiter une ability avant tout contrôle
	 * de permission, donc de passer sous la matrice. On ne le bloque pas, on le signale.
	 *
	 * @return string[]
	 */
	private function get_pre_execute_filters() {
		global $wp_filter;

		$descriptions = array();
		$hook         = $wp_filter['wp_pre_execute_ability'] ?? null;

		if ( ! $hook instanceof WP_Hook ) {
			return $descriptions;
		}

		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$descriptions[] = $this->describe_callback( $callback['function'] );
			}
		}

		return $descriptions;
	}

	/**
	 * Décrit un callback de façon lisible.
	 *
	 * @param callable|mixed $callback Callback.
	 * @return string
	 */
	private function describe_callback( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return $owner . '::' . $callback[1];
		}

		return 'closure';
	}
}
