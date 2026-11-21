<?php
/**
 * Types de contenu et taxonomies ouverts aux abilities.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Writable_Types
 *
 * Liste blanche : seuls les types publics sont proposés à l'IA. Les types
 * techniques (révisions, menus, réglages d'extensions stockés en CPT) restent
 * hors de portée tant qu'un site ne les ouvre pas explicitement.
 */
class WMCP_Writable_Types {

	/**
	 * Retourne les types de contenu gérables par les abilities.
	 *
	 * @return string[]
	 */
	public static function get_post_types() {
		$post_types = get_post_types( array( 'public' => true ) );
		// Les médias ont leurs propres abilities.
		unset( $post_types['attachment'] );

		/**
		 * Filtre les types de contenu gérables par les abilities de W-MCP.
		 *
		 * @param string[] $post_types Noms des types de contenu.
		 */
		return array_values( (array) apply_filters( 'wmcp_writable_post_types', array_values( $post_types ) ) );
	}

	/**
	 * Retourne les taxonomies gérables par les abilities.
	 *
	 * @return string[]
	 */
	public static function get_taxonomies() {
		/**
		 * Filtre les taxonomies gérables par les abilities de W-MCP.
		 *
		 * @param string[] $taxonomies Noms des taxonomies.
		 */
		return array_values( (array) apply_filters( 'wmcp_writable_taxonomies', array_values( get_taxonomies( array( 'public' => true ) ) ) ) );
	}

	/**
	 * Indique si un type de contenu est gérable.
	 *
	 * @param string $post_type Nom du type de contenu.
	 * @return bool
	 */
	public static function has_post_type( $post_type ) {
		return in_array( $post_type, self::get_post_types(), true );
	}

	/**
	 * Indique si une taxonomie est gérable.
	 *
	 * @param string $taxonomy Nom de la taxonomie.
	 * @return bool
	 */
	public static function has_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, self::get_taxonomies(), true );
	}
}
