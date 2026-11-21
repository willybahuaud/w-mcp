<?php
/**
 * Masquage et troncature des données journalisées.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Redactor
 *
 * Le journal part chez WBoard : on n'y laisse ni secret, ni contenu volumineux.
 * Logique pure, testée unitairement.
 */
class WMCP_Redactor {

	/**
	 * Valeur qui remplace une donnée masquée.
	 *
	 * @var string
	 */
	const MASK = '[masked]';

	/**
	 * Suffixe ajouté à une chaîne tronquée.
	 *
	 * @var string
	 */
	const TRUNCATION_MARK = '…[truncated]';

	/**
	 * Fragments de clés considérés comme sensibles par défaut.
	 *
	 * @var string[]
	 */
	const DEFAULT_SENSITIVE_KEYS = array( 'password', 'pass', 'pwd', 'token', 'secret', 'key', 'authorization', 'cookie' );

	/**
	 * Longueur maximale d'une chaîne conservée telle quelle, en octets.
	 *
	 * Au-delà, c'est du contenu (un article, un modèle) : la révision WordPress fait foi.
	 *
	 * @var int
	 */
	const MAX_STRING_BYTES = 500;

	/**
	 * Fragments de clés sensibles, en minuscules.
	 *
	 * @var string[]
	 */
	private $sensitive_keys;

	/**
	 * Constructeur.
	 *
	 * @param string[] $sensitive_keys Fragments de clés sensibles.
	 */
	public function __construct( array $sensitive_keys = self::DEFAULT_SENSITIVE_KEYS ) {
		$this->sensitive_keys = array_map( 'strtolower', $sensitive_keys );
	}

	/**
	 * Masque les secrets et raccourcit les longues chaînes d'une structure.
	 *
	 * @param mixed $value Donnée à nettoyer (scalaire, tableau ou objet).
	 * @return mixed Donnée nettoyée, les objets étant convertis en tableaux.
	 */
	public function redact( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_string( $value ) ) {
			return $this->shorten( $value, self::MAX_STRING_BYTES );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$clean = array();
		foreach ( $value as $key => $item ) {
			$clean[ $key ] = $this->is_sensitive_key( $key ) ? self::MASK : $this->redact( $item );
		}

		return $clean;
	}

	/**
	 * Indique si une clé désigne une donnée sensible.
	 *
	 * @param int|string $key Clé du tableau.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$key = strtolower( $key );
		foreach ( $this->sensitive_keys as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Raccourcit une chaîne à une taille maximale.
	 *
	 * @param string $text      Chaîne d'origine.
	 * @param int    $max_bytes Taille maximale en octets.
	 * @return string
	 */
	public function shorten( $text, $max_bytes ) {
		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		// mb_strcut coupe sur une frontière de caractère : le JSON reste valide en UTF-8.
		return mb_strcut( $text, 0, $max_bytes, 'UTF-8' ) . self::TRUNCATION_MARK;
	}
}
