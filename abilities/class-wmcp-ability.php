<?php
/**
 * Base des abilities fournies par W-MCP.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability
 *
 * Une classe par ability. Chacune décrit son contrat (nom, schéma, niveau) et
 * porte son contrôle de permission WordPress et son exécution. La matrice,
 * elle, est appliquée par WMCP_Guard, pas ici.
 */
abstract class WMCP_Ability {

	/**
	 * Catégorie commune aux abilities du plugin.
	 *
	 * @var string
	 */
	const CATEGORY = 'wmcp';

	/**
	 * Services partagés.
	 *
	 * @var WMCP_Services
	 */
	protected $services;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Services $services Services partagés.
	 */
	public function __construct( WMCP_Services $services ) {
		$this->services = $services;
	}

	/**
	 * Retourne le nom complet de l'ability.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Retourne le libellé court.
	 *
	 * @return string
	 */
	abstract protected function get_label();

	/**
	 * Retourne la description lue par l'IA : quand et comment utiliser l'ability.
	 *
	 * @return string
	 */
	abstract protected function get_description();

	/**
	 * Retourne le niveau de risque.
	 *
	 * @return string Constante de WMCP_Ability_Level.
	 */
	abstract protected function get_level();

	/**
	 * Retourne les propriétés du schéma d'entrée.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	abstract protected function get_input_properties();

	/**
	 * Contrôle la permission WordPress de l'utilisateur courant.
	 *
	 * @param array<string,mixed> $input Données d'entrée validées.
	 * @return bool|WP_Error
	 */
	abstract public function check_permission( $input );

	/**
	 * Exécute l'ability.
	 *
	 * @param array<string,mixed> $input Données d'entrée validées.
	 * @return array<string,mixed>|WP_Error
	 */
	abstract public function execute( $input );

	/**
	 * Retourne les propriétés obligatoires du schéma d'entrée.
	 *
	 * @return string[]
	 */
	protected function get_required_properties() {
		return array();
	}

	/**
	 * Retourne le nom d'une ability officielle qui rend celle-ci inutile.
	 *
	 * Sert à la bascule : dès que le core ou le plugin AI fournit l'équivalent,
	 * la nôtre ne s'enregistre plus.
	 *
	 * @return string Chaîne vide si aucune.
	 */
	public function get_superseding_ability() {
		return '';
	}

	/**
	 * Retrouve un contenu, ou explique pourquoi il n'est pas accessible.
	 *
	 * @param mixed         $post_id       Identifiant reçu.
	 * @param string[]|null $allowed_types Types de contenu acceptés, ou null pour les types gérables.
	 * @return WP_Post|WP_Error
	 */
	protected function find_post( $post_id, $allowed_types = null ) {
		$post          = get_post( absint( $post_id ) );
		$allowed_types = null === $allowed_types ? WMCP_Writable_Types::get_post_types() : $allowed_types;

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $allowed_types, true ) ) {
			return new WP_Error( 'wmcp_not_found', 'No content with this ID is available through this ability.' );
		}

		return $post;
	}

	/**
	 * Contrôle une capability WordPress sur un contenu précis.
	 *
	 * @param string        $capability    Capability méta (`edit_post`, `delete_post`).
	 * @param mixed         $post_id       Identifiant reçu.
	 * @param string[]|null $allowed_types Types de contenu acceptés, ou null pour les types gérables.
	 * @return bool|WP_Error
	 */
	protected function can_on_post( $capability, $post_id, $allowed_types = null ) {
		$post = $this->find_post( $post_id, $allowed_types );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return current_user_can( $capability, $post->ID );
	}

	/**
	 * Retourne le schéma des paramètres de pagination communs aux abilities de lecture.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_pagination_properties() {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'description' => 'Number of results per page.',
				'minimum'     => 1,
				'maximum'     => 50,
				'default'     => 20,
			),
			'page'     => array(
				'type'        => 'integer',
				'description' => 'Page number, starting at 1.',
				'minimum'     => 1,
				'default'     => 1,
			),
		);
	}

	/**
	 * Enregistre l'ability auprès de WordPress.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_ability(
			$this->get_name(),
			array(
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'permission_callback' => array( $this, 'check_permission' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'annotations'  => WMCP_Ability_Level::to_annotations( $this->get_level() ),
					'show_in_rest' => false,
					// Exposée au MCP uniquement : la matrice ne s'applique pas à l'API REST des abilities.
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Construit le schéma d'entrée complet.
	 *
	 * @return array<string,mixed>
	 */
	private function get_input_schema() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $this->get_input_properties(),
			'additionalProperties' => false,
			'default'              => array(),
		);

		if ( $this->get_required_properties() ) {
			$schema['required'] = $this->get_required_properties();
		}

		return $schema;
	}
}
