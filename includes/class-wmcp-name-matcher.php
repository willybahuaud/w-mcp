<?php
/**
 * Correspondance entre un nom d'ability et un motif de la matrice.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Name_Matcher
 *
 * Un motif est soit un nom exact (`wmcp/content-create`), soit un préfixe
 * terminé par un joker (`wmcp/content-*`, `woocommerce/*`). Le joker n'est
 * accepté qu'en fin de motif : c'est suffisant et ça reste lisible dans WBoard.
 */
class WMCP_Name_Matcher {

	/**
	 * Caractère joker.
	 *
	 * @var string
	 */
	const WILDCARD = '*';

	/**
	 * Format accepté pour un motif : espace de noms, slash, nom, joker final optionnel.
	 *
	 * @var string
	 */
	const PATTERN_FORMAT = '#^[a-z0-9][a-z0-9-]*/[a-z0-9-]*\*?\z#';

	/**
	 * Indique si un motif est syntaxiquement valide.
	 *
	 * @param mixed $pattern Motif à contrôler.
	 * @return bool
	 */
	public static function is_valid_pattern( $pattern ) {
		if ( ! is_string( $pattern ) || 1 !== preg_match( self::PATTERN_FORMAT, $pattern ) ) {
			return false;
		}

		// `ns/` seul ne désigne rien : il faut un nom ou un joker après le slash.
		return '/' !== substr( $pattern, -1 );
	}

	/**
	 * Indique si un nom d'ability correspond à un motif.
	 *
	 * @param string $ability_name Nom complet de l'ability.
	 * @param string $pattern      Motif de la matrice.
	 * @return bool
	 */
	public static function matches( $ability_name, $pattern ) {
		if ( self::WILDCARD !== substr( $pattern, -1 ) ) {
			return $ability_name === $pattern;
		}

		$prefix = substr( $pattern, 0, -1 );

		return 0 === strpos( $ability_name, $prefix );
	}

	/**
	 * Indique si un nom d'ability correspond à au moins un motif d'une liste.
	 *
	 * @param string   $ability_name Nom complet de l'ability.
	 * @param string[] $patterns     Motifs de la matrice.
	 * @return bool
	 */
	public static function matches_any( $ability_name, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $ability_name, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
