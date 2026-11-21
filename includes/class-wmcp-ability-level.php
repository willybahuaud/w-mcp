<?php
/**
 * Niveau de risque d'une ability.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Level
 *
 * Déduit le niveau d'une ability de ses annotations (`meta.annotations`).
 * Dans le doute, on classe au plus risqué : une ability tierce sans annotation
 * est refusée tant que la matrice ne l'autorise pas explicitement.
 */
class WMCP_Ability_Level {

	const READ        = 'read';
	const WRITE       = 'write';
	const DESTRUCTIVE = 'destructive';

	/**
	 * Tous les niveaux, du moins au plus risqué.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::READ, self::WRITE, self::DESTRUCTIVE );
	}

	/**
	 * Calcule le niveau à partir des annotations d'une ability.
	 *
	 * @param array<string,mixed> $annotations Annotations (`readonly`, `destructive`, `idempotent`).
	 * @return string L'une des constantes de niveau.
	 */
	public static function from_annotations( array $annotations ) {
		if ( true === ( $annotations['readonly'] ?? null ) ) {
			return self::READ;
		}

		// Seule une déclaration explicite « ni lecture seule, ni destructif » vaut écriture simple.
		$declared_writable = false === ( $annotations['readonly'] ?? null );
		$declared_safe     = false === ( $annotations['destructive'] ?? null );

		if ( $declared_writable && $declared_safe ) {
			return self::WRITE;
		}

		return self::DESTRUCTIVE;
	}

	/**
	 * Retourne les annotations à déclarer pour un niveau donné.
	 *
	 * @param string $level L'une des constantes de niveau.
	 * @return array<string,bool>
	 */
	public static function to_annotations( $level ) {
		return array(
			'readonly'    => self::READ === $level,
			'destructive' => self::DESTRUCTIVE === $level,
			'idempotent'  => self::READ === $level,
		);
	}
}
