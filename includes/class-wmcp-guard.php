<?php
/**
 * Application de la matrice aux abilities.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Guard
 *
 * Branche le résolveur sur l'Abilities API. C'est ici que la matrice fait
 * autorité : le serveur par défaut de l'adapter passe par un outil générique
 * `execute-ability`, donc seul un contrôle à ce niveau voit toutes les exécutions.
 *
 * Règle absolue : le garde ne fait que restreindre. Il ne transforme jamais un
 * refus de WordPress en accord.
 */
class WMCP_Guard {

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
	 * Journal.
	 *
	 * @var WMCP_Logger
	 */
	private $logger;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Context      $context      Contexte de la requête.
	 * @param WMCP_Policy_Store $policy_store Stockage de la matrice.
	 * @param WMCP_Logger       $logger       Journal.
	 */
	public function __construct( WMCP_Context $context, WMCP_Policy_Store $policy_store, WMCP_Logger $logger ) {
		$this->context      = $context;
		$this->policy_store = $policy_store;
		$this->logger       = $logger;
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'wp_get_abilities_item_include', array( $this, 'filter_listing' ), 999, 2 );
		// Priorité tardive : on statue après tous les autres filtres de permission.
		add_filter( 'wp_ability_permission_result', array( $this, 'filter_permission' ), 999, 4 );
	}

	/**
	 * Décide pour l'utilisateur courant et une ability.
	 *
	 * @param WP_Ability $ability Ability visée.
	 * @return WMCP_Decision
	 */
	public function decide( WP_Ability $ability ) {
		return $this->policy_store->get_resolver()->decide(
			get_current_user_id(),
			$ability->get_name(),
			self::get_level( $ability )
		);
	}

	/**
	 * Retourne le niveau d'une ability.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return string Constante de WMCP_Ability_Level.
	 */
	public static function get_level( WP_Ability $ability ) {
		$annotations = $ability->get_meta()['annotations'] ?? array();

		return WMCP_Ability_Level::from_annotations( is_array( $annotations ) ? $annotations : array() );
	}

	/**
	 * Masque à la découverte les abilities que l'utilisateur ne peut pas exécuter.
	 *
	 * @param bool       $is_included Résultat des filtres précédents.
	 * @param WP_Ability $ability     Ability évaluée.
	 * @return bool
	 */
	public function filter_listing( $is_included, $ability ) {
		if ( ! $is_included || ! $this->context->is_mcp() ) {
			return $is_included;
		}

		return $this->decide( $ability )->is_allowed();
	}

	/**
	 * Refuse l'exécution d'une ability que la matrice n'autorise pas.
	 *
	 * @param bool|WP_Error $permission   Résultat du callback de permission de l'ability.
	 * @param string        $ability_name Nom de l'ability.
	 * @param mixed         $input        Données d'entrée.
	 * @param WP_Ability    $ability      Ability.
	 * @return bool|WP_Error
	 */
	public function filter_permission( $permission, $ability_name, $input, $ability ) {
		// Hors MCP, la matrice ne s'applique pas.
		if ( ! $this->context->is_mcp() ) {
			return $permission;
		}

		// Un refus de WordPress reste un refus. On le journalise quand même : ce
		// que le client a tenté sans en avoir le droit est aussi un signal.
		if ( true !== $permission ) {
			$this->logger->record_denied( $ability, $input, WMCP_Decision::REASON_WORDPRESS );
			return $permission;
		}

		$decision = $this->decide( $ability );
		if ( $decision->is_allowed() ) {
			return true;
		}

		$this->logger->record_denied( $ability, $input, $decision->get_reason() );

		return new WP_Error( 'wmcp_denied', WMCP_Messages::ability_denied( $ability_name, $decision ) );
	}
}
