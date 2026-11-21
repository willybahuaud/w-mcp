<?php
/**
 * Décision d'accès pour un utilisateur et une ability.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Policy_Resolver
 *
 * Logique pure, sans appel à WordPress : on lui donne une matrice validée,
 * elle répond. C'est le cœur testé du plugin.
 *
 * Ordre de résolution :
 *  1. pas de matrice, site en pause ou utilisateur absent : refus ;
 *  2. abilities toujours autorisées (outils de l'adapter, remontée de besoin) ;
 *  3. exceptions de l'utilisateur (`deny` puis `allow`) ;
 *  4. profil : `deny`, puis `allow` et niveau.
 */
class WMCP_Policy_Resolver {

	/**
	 * Abilities qu'aucun profil ne peut retirer à un utilisateur de la matrice.
	 *
	 * Les trois outils de l'adapter ne font que relayer : le contrôle réel porte
	 * sur l'ability visée. Ils sont nommés un par un : un joker autoriserait
	 * d'office tout outil que l'adapter ajouterait plus tard. Les trois `wmcp/` sont le socle du concept (comprendre
	 * ses droits, lire le cadrage, remonter un besoin).
	 *
	 * @var string[]
	 */
	const ALWAYS_ALLOWED = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
		'wmcp/request-capability',
		'wmcp/my-permissions',
		'wmcp/site-context',
	);

	/**
	 * Matrice validée, ou null si aucune n'est en place.
	 *
	 * @var array<string,mixed>|null
	 */
	private $policy;

	/**
	 * Constructeur.
	 *
	 * @param array<string,mixed>|null $policy Matrice validée, ou null.
	 */
	public function __construct( $policy ) {
		$this->policy = is_array( $policy ) ? $policy : null;
	}

	/**
	 * Indique si une ability fait partie du socle toujours autorisé.
	 *
	 * @param string $ability_name Nom complet de l'ability.
	 * @return bool
	 */
	public static function is_always_allowed( $ability_name ) {
		return WMCP_Name_Matcher::matches_any( $ability_name, self::ALWAYS_ALLOWED );
	}

	/**
	 * Retourne la version de la matrice en place.
	 *
	 * @return int 0 si aucune matrice.
	 */
	public function get_version() {
		return (int) ( $this->policy['version'] ?? 0 );
	}

	/**
	 * Indique si l'accès MCP du site est coupé.
	 *
	 * @return bool
	 */
	public function is_paused() {
		return true === ( $this->policy['paused'] ?? false );
	}

	/**
	 * Décide si un utilisateur peut ouvrir une session MCP.
	 *
	 * @param int $user_id Identifiant de l'utilisateur WordPress.
	 * @return WMCP_Decision
	 */
	public function decide_access( $user_id ) {
		if ( null === $this->policy ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_NO_POLICY );
		}
		if ( $this->is_paused() ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_PAUSED );
		}
		if ( null === $this->get_user_entry( $user_id ) ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_UNKNOWN_USER );
		}

		return WMCP_Decision::allow();
	}

	/**
	 * Décide si un utilisateur peut exécuter une ability.
	 *
	 * @param int    $user_id      Identifiant de l'utilisateur WordPress.
	 * @param string $ability_name Nom complet de l'ability.
	 * @param string $level        Niveau de l'ability (constante de WMCP_Ability_Level).
	 * @return WMCP_Decision
	 */
	public function decide( $user_id, $ability_name, $level ) {
		$access = $this->decide_access( $user_id );
		if ( ! $access->is_allowed() ) {
			return $access;
		}

		if ( self::is_always_allowed( $ability_name ) ) {
			return WMCP_Decision::allow( WMCP_Decision::REASON_ALWAYS_ALLOWED );
		}

		$user = $this->get_user_entry( $user_id );
		if ( WMCP_Name_Matcher::matches_any( $ability_name, $user['deny'] ?? array() ) ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_USER_DENY );
		}
		// Une exception accordée nommément ignore le niveau : c'est son rôle.
		if ( WMCP_Name_Matcher::matches_any( $ability_name, $user['allow'] ?? array() ) ) {
			return WMCP_Decision::allow( WMCP_Decision::REASON_USER_ALLOW );
		}

		return $this->decide_from_profile( $this->get_profile( $user_id ), $ability_name, $level );
	}

	/**
	 * Applique les règles d'un profil.
	 *
	 * @param array<string,mixed> $profile      Définition du profil.
	 * @param string              $ability_name Nom complet de l'ability.
	 * @param string              $level        Niveau de l'ability.
	 * @return WMCP_Decision
	 */
	private function decide_from_profile( array $profile, $ability_name, $level ) {
		if ( WMCP_Name_Matcher::matches_any( $ability_name, $profile['deny'] ?? array() ) ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_PROFILE_DENY );
		}
		if ( ! WMCP_Name_Matcher::matches_any( $ability_name, $profile['allow'] ?? array() ) ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_NOT_LISTED );
		}
		if ( ! in_array( $level, $profile['levels'] ?? array(), true ) ) {
			return WMCP_Decision::deny( WMCP_Decision::REASON_LEVEL );
		}

		return WMCP_Decision::allow();
	}

	/**
	 * Indique si l'utilisateur peut publier directement.
	 *
	 * @param int $user_id Identifiant de l'utilisateur WordPress.
	 * @return bool
	 */
	public function can_publish( $user_id ) {
		return true === ( $this->get_profile( $user_id )['can_publish'] ?? false );
	}

	/**
	 * Retourne le nom du profil d'un utilisateur.
	 *
	 * @param int $user_id Identifiant de l'utilisateur WordPress.
	 * @return string|null Null si l'utilisateur est absent de la matrice.
	 */
	public function get_profile_name( $user_id ) {
		$user = $this->get_user_entry( $user_id );

		return null === $user ? null : (string) $user['profile'];
	}

	/**
	 * Retourne la définition du profil d'un utilisateur.
	 *
	 * @param int $user_id Identifiant de l'utilisateur WordPress.
	 * @return array<string,mixed> Tableau vide si l'utilisateur est absent.
	 */
	private function get_profile( $user_id ) {
		$profile_name = $this->get_profile_name( $user_id );
		if ( null === $profile_name ) {
			return array();
		}

		return (array) ( $this->policy['profiles'][ $profile_name ] ?? array() );
	}

	/**
	 * Retourne l'entrée d'un utilisateur dans la matrice.
	 *
	 * @param int $user_id Identifiant de l'utilisateur WordPress.
	 * @return array<string,mixed>|null
	 */
	private function get_user_entry( $user_id ) {
		$user = $this->policy['users'][ (string) $user_id ] ?? null;

		return is_array( $user ) ? $user : null;
	}
}
