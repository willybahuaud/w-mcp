<?php
/**
 * Enregistrement des abilities du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Abilities
 */
class WMCP_Abilities {

	/**
	 * Classes des abilities fournies.
	 *
	 * @var string[]
	 */
	const ABILITY_CLASSES = array(
		// Socle, toujours autorisé.
		'WMCP_Ability_Request_Capability',
		'WMCP_Ability_Site_Context',
		'WMCP_Ability_My_Permissions',
		// Contenus.
		'WMCP_Ability_Content_Query',
		'WMCP_Ability_Content_Create',
		'WMCP_Ability_Content_Update',
		'WMCP_Ability_Content_Trash',
		// Termes.
		'WMCP_Ability_Terms_Query',
		'WMCP_Ability_Term_Create',
		'WMCP_Ability_Term_Update',
		'WMCP_Ability_Content_Set_Terms',
		// Médias.
		'WMCP_Ability_Media_Query',
		'WMCP_Ability_Media_Update',
		// Éditeur de blocs.
		'WMCP_Ability_Block_Types_Query',
		'WMCP_Ability_Patterns_Query',
		'WMCP_Ability_Content_Get_Blocks',
		'WMCP_Ability_Content_Update_Block',
		'WMCP_Ability_Pattern_Create',
		'WMCP_Ability_Pattern_Update',
		'WMCP_Ability_Templates_Query',
		'WMCP_Ability_Template_Update',
		'WMCP_Ability_Template_Reset',
	);

	/**
	 * Services partagés.
	 *
	 * @var WMCP_Services
	 */
	private $services;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Services $services Services partagés.
	 */
	public function __construct( WMCP_Services $services ) {
		$this->services = $services;
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		// Priorité tardive : on doit voir les abilities officielles déjà enregistrées
		// pour ne pas doubler celles qui remplacent les nôtres.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ), 100 );
	}

	/**
	 * Enregistre la catégorie du plugin.
	 *
	 * @return void
	 */
	public function register_category() {
		wp_register_ability_category(
			WMCP_Ability::CATEGORY,
			array(
				'label'       => __( 'Governed site management', 'w-mcp' ),
				'description' => __( 'Abilities provided by W-MCP: governed content management and requests to the site maintainer.', 'w-mcp' ),
			)
		);
	}

	/**
	 * Enregistre les abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		/**
		 * Filtre les classes d'abilities enregistrées par W-MCP.
		 *
		 * Permet à un site de retirer une ability, ou d'ajouter les siennes
		 * (classes héritant de WMCP_Ability).
		 *
		 * @param string[] $ability_classes Noms de classes.
		 */
		$ability_classes = (array) apply_filters( 'wmcp_ability_classes', self::ABILITY_CLASSES );

		foreach ( $ability_classes as $ability_class ) {
			if ( ! is_string( $ability_class ) || ! is_subclass_of( $ability_class, 'WMCP_Ability' ) ) {
				continue;
			}

			$ability     = new $ability_class( $this->services );
			$superseding = $ability->get_superseding_ability();

			if ( '' === $superseding || ! wp_has_ability( $superseding ) ) {
				$ability->register();
			}
		}
	}
}
