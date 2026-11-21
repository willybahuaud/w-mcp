<?php
/**
 * Résultat d'une décision d'accès.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Decision
 *
 * Objet-valeur : autorisé ou non, avec le motif. Le motif part dans le journal
 * et sert à formuler le message renvoyé à l'IA.
 */
class WMCP_Decision {

	const REASON_ALLOWED        = 'allowed';
	const REASON_ALWAYS_ALLOWED = 'always_allowed';
	const REASON_USER_ALLOW     = 'user_allow';
	const REASON_NO_POLICY      = 'no_policy';
	const REASON_PAUSED         = 'paused';
	const REASON_UNKNOWN_USER   = 'unknown_user';
	const REASON_USER_DENY      = 'user_deny';
	const REASON_PROFILE_DENY   = 'profile_deny';
	const REASON_NOT_LISTED     = 'not_listed';
	const REASON_LEVEL          = 'level_not_allowed';
	const REASON_WORDPRESS      = 'wordpress_permission';

	/**
	 * Accès accordé ou non.
	 *
	 * @var bool
	 */
	private $allowed;

	/**
	 * Motif de la décision.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructeur.
	 *
	 * @param bool   $allowed Accès accordé ou non.
	 * @param string $reason  L'une des constantes REASON_*.
	 */
	private function __construct( $allowed, $reason ) {
		$this->allowed = $allowed;
		$this->reason  = $reason;
	}

	/**
	 * Crée une décision positive.
	 *
	 * @param string $reason L'une des constantes REASON_*.
	 * @return self
	 */
	public static function allow( $reason = self::REASON_ALLOWED ) {
		return new self( true, $reason );
	}

	/**
	 * Crée une décision négative.
	 *
	 * @param string $reason L'une des constantes REASON_*.
	 * @return self
	 */
	public static function deny( $reason ) {
		return new self( false, $reason );
	}

	/**
	 * Indique si l'accès est accordé.
	 *
	 * @return bool
	 */
	public function is_allowed() {
		return $this->allowed;
	}

	/**
	 * Retourne le motif de la décision.
	 *
	 * @return string
	 */
	public function get_reason() {
		return $this->reason;
	}
}
