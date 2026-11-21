<?php
/**
 * Messages renvoyés à l'IA du client.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Messages
 *
 * Ces textes sont lus par un modèle, pas par un humain : ils disent quoi faire
 * ensuite. Un refus n'est pas une impasse, c'est une demande à faire remonter.
 * Ils restent en anglais, compris par tous les modèles quelle que soit la langue du client.
 */
class WMCP_Messages {

	/**
	 * Nom de l'ability de remontée de besoin, cité dans les messages.
	 *
	 * @var string
	 */
	const REQUEST_ABILITY = 'wmcp/request-capability';

	/**
	 * Consigne commune à tous les refus.
	 *
	 * @return string
	 */
	public static function next_step() {
		return 'Do not try to work around this limit. Explain it to the user, then offer to forward their need to the site maintainer with the "' . self::REQUEST_ABILITY . '" ability (describe what they want to achieve and what was blocked). Only send it once they agree.';
	}

	/**
	 * Message de refus d'une ability.
	 *
	 * @param string        $ability_name Nom de l'ability refusée.
	 * @param WMCP_Decision $decision     Décision négative.
	 * @return string
	 */
	public static function ability_denied( $ability_name, WMCP_Decision $decision ) {
		return sprintf(
			'The ability "%1$s" is not allowed for this user by the site access policy (%2$s). %3$s',
			$ability_name,
			$decision->get_reason(),
			self::next_step()
		);
	}

	/**
	 * Message de refus d'accès au serveur MCP.
	 *
	 * @param WMCP_Decision $decision Décision négative.
	 * @return string
	 */
	public static function access_denied( WMCP_Decision $decision ) {
		if ( WMCP_Decision::REASON_PAUSED === $decision->get_reason() ) {
			return 'MCP access to this site is paused by its maintainer. Tell the user to contact them.';
		}

		return 'This user account has no MCP access to this site (' . $decision->get_reason() . '). Tell the user to ask the site maintainer for access.';
	}

	/**
	 * Message de refus d'une action précise, à l'intérieur d'une ability autorisée.
	 *
	 * @param string $explanation Ce qui est refusé, et pourquoi.
	 * @return string
	 */
	public static function action_denied( $explanation ) {
		return $explanation . ' ' . self::next_step();
	}
}
