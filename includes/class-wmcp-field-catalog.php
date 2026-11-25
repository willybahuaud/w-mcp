<?php
/**
 * Champs personnalisés déclarés sur le site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Field_Catalog
 *
 * Retrouve les champs d'un type de contenu : groupes ACF quand l'extension
 * est active, et métadonnées déclarées au core avec `register_post_meta()`.
 */
class WMCP_Field_Catalog {

	/**
	 * Mise en forme des champs.
	 *
	 * @var WMCP_Field_Presenter
	 */
	private $presenter;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Field_Presenter $presenter Mise en forme des champs.
	 */
	public function __construct( WMCP_Field_Presenter $presenter ) {
		$this->presenter = $presenter;
	}

	/**
	 * Résume les groupes de champs d'un type de contenu, sans détailler les champs.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array<int,array<string,mixed>>
	 */
	public function summarize_groups( $post_type ) {
		$groups = array();

		foreach ( $this->get_acf_groups( $post_type ) as $group ) {
			$groups[] = array(
				'title'        => (string) ( $group['title'] ?? '' ),
				'fields_count' => $this->presenter->count_fields( acf_get_fields( $group ) ),
			);
		}

		return $groups;
	}

	/**
	 * Détaille les groupes de champs d'un type de contenu.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array<int,array<string,mixed>>
	 */
	public function describe_groups( $post_type ) {
		$groups = array();

		foreach ( $this->get_acf_groups( $post_type ) as $group ) {
			$groups[] = array(
				'title'       => (string) ( $group['title'] ?? '' ),
				'description' => (string) ( $group['description'] ?? '' ),
				'fields'      => $this->presenter->present_fields( acf_get_fields( $group ) ),
			);
		}

		return $groups;
	}

	/**
	 * Liste les métadonnées déclarées au core pour un type de contenu.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_registered_meta( $post_type ) {
		$meta = array();

		foreach ( get_registered_meta_keys( 'post', $post_type ) as $meta_key => $args ) {
			// Une clé préfixée par « _ » est un détail interne d'extension, pas une donnée du contenu.
			if ( 0 === strpos( $meta_key, '_' ) ) {
				continue;
			}

			$meta[] = array(
				'name'        => $meta_key,
				'type'        => (string) ( $args['type'] ?? 'string' ),
				'single'      => ! empty( $args['single'] ),
				'description' => (string) ( $args['description'] ?? '' ),
			);
		}

		return $meta;
	}

	/**
	 * Retrouve les groupes ACF actifs rattachés à un type de contenu.
	 *
	 * ACF évalue lui-même ses règles d'emplacement : on ne les réinterprète pas.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_acf_groups( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = array();
		foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			if ( is_array( $group ) && ( ! isset( $group['active'] ) || $group['active'] ) ) {
				$groups[] = $group;
			}
		}

		return $groups;
	}
}
