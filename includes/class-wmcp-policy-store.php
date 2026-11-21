<?php
/**
 * Stockage de la matrice de droits et du document de cadrage.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Policy_Store
 *
 * La matrice arrive de WBoard par une route signée. Elle est validée avant
 * d'être enregistrée, et gardée dans une option non autoloadée : elle ne sert
 * qu'aux requêtes MCP.
 */
class WMCP_Policy_Store {

	const OPTION_POLICY       = 'wmcp_policy';
	const OPTION_SITE_CONTEXT = 'wmcp_site_context';

	/**
	 * Taille maximale du document de cadrage, en octets.
	 *
	 * @var int
	 */
	const MAX_CONTEXT_BYTES = 65536;

	/**
	 * Validateur de matrice.
	 *
	 * @var WMCP_Policy_Validator
	 */
	private $validator;

	/**
	 * Résolveur construit sur la matrice en place, mis en cache pour la requête.
	 *
	 * @var WMCP_Policy_Resolver|null
	 */
	private $resolver = null;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Policy_Validator $validator Validateur de matrice.
	 */
	public function __construct( WMCP_Policy_Validator $validator ) {
		$this->validator = $validator;
	}

	/**
	 * Retourne la matrice en place.
	 *
	 * @return array<string,mixed>|null Null si aucune matrice valide n'est enregistrée.
	 */
	public function get_policy() {
		$policy = get_option( self::OPTION_POLICY, null );

		// On revalide à la lecture : une option altérée ne doit pas ouvrir de droits.
		if ( ! is_array( $policy ) || $this->validator->validate( $policy ) ) {
			return null;
		}

		return $policy;
	}

	/**
	 * Retourne le résolveur de la matrice en place.
	 *
	 * @return WMCP_Policy_Resolver
	 */
	public function get_resolver() {
		if ( null === $this->resolver ) {
			$this->resolver = new WMCP_Policy_Resolver( $this->get_policy() );
		}

		return $this->resolver;
	}

	/**
	 * Remplace la matrice.
	 *
	 * @param mixed $policy Matrice décodée depuis le JSON.
	 * @return true|WP_Error
	 */
	public function save_policy( $policy ) {
		$errors = $this->validator->validate( $policy );
		if ( $errors ) {
			return new WP_Error(
				'wmcp_invalid_policy',
				__( 'The policy is invalid and was not applied.', 'w-mcp' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		// Un envoi en retard ne doit pas écraser une matrice plus récente, et une
		// requête rejouée ne doit pas réappliquer la version déjà en place.
		$current_version = $this->get_resolver()->get_version();
		if ( $policy['version'] <= $current_version ) {
			return new WP_Error(
				'wmcp_stale_policy',
				__( 'This policy version, or a more recent one, is already in place.', 'w-mcp' ),
				array(
					'status'          => 409,
					'current_version' => $current_version,
				)
			);
		}

		update_option( self::OPTION_POLICY, $policy, false );
		$this->resolver = null;

		return true;
	}

	/**
	 * Coupe ou rétablit l'accès MCP du site, sans toucher au reste de la matrice.
	 *
	 * @param bool $paused Vrai pour couper l'accès.
	 * @return true|WP_Error Erreur si aucune matrice n'est en place.
	 */
	public function set_paused( $paused ) {
		$policy = $this->get_policy();
		if ( null === $policy ) {
			return new WP_Error( 'wmcp_no_policy', __( 'No policy is in place: MCP access is already closed.', 'w-mcp' ), array( 'status' => 409 ) );
		}

		$policy['paused'] = (bool) $paused;
		update_option( self::OPTION_POLICY, $policy, false );
		$this->resolver = null;

		return true;
	}

	/**
	 * Retourne le document de cadrage du site.
	 *
	 * @return string
	 */
	public function get_site_context() {
		return (string) get_option( self::OPTION_SITE_CONTEXT, '' );
	}

	/**
	 * Enregistre le document de cadrage du site.
	 *
	 * @param string $site_context Texte du document (Markdown).
	 * @return void
	 */
	public function save_site_context( $site_context ) {
		$site_context = sanitize_textarea_field( (string) $site_context );
		$site_context = mb_strcut( $site_context, 0, self::MAX_CONTEXT_BYTES, 'UTF-8' );

		update_option( self::OPTION_SITE_CONTEXT, $site_context, false );
	}
}
