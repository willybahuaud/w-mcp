<?php
/**
 * Structure du site, telle que WordPress la connaît.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Site_Structure
 *
 * Décrit comment le site est construit : types de contenu, taxonomies, champs,
 * menus, thème. Tout est lu dans WordPress à la demande : contrairement à un
 * document rédigé, cette description ne peut pas être en retard sur le site.
 */
class WMCP_Site_Structure {

	/**
	 * Champs personnalisés du site.
	 *
	 * @var WMCP_Field_Catalog
	 */
	private $fields;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Field_Catalog $fields Champs personnalisés du site.
	 */
	public function __construct( WMCP_Field_Catalog $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Vue d'ensemble du site.
	 *
	 * @return array<string,mixed>
	 */
	public function get_overview() {
		return array(
			'site'          => $this->get_site(),
			'content_types' => $this->get_content_types(),
			'taxonomies'    => $this->get_taxonomies(),
			'menus'         => $this->get_menus(),
			'theme'         => $this->get_theme(),
			'custom_blocks' => $this->get_custom_block_namespaces(),
		);
	}

	/**
	 * Détail d'un type de contenu, champs compris.
	 *
	 * @param string $post_type Type de contenu gérable.
	 * @return array<string,mixed>
	 */
	public function get_content_type_detail( $post_type ) {
		$detail = $this->describe_content_type( get_post_type_object( $post_type ) );
		unset( $detail['field_groups'] );

		$detail['field_groups']    = $this->fields->describe_groups( $post_type );
		$detail['registered_meta'] = $this->fields->get_registered_meta( $post_type );

		return $detail;
	}

	/**
	 * Réglages généraux qui orientent le travail sur les contenus.
	 *
	 * @return array<string,mixed>
	 */
	private function get_site() {
		$has_static_front = 'page' === get_option( 'show_on_front' );

		return array(
			'name'          => get_bloginfo( 'name' ),
			'url'           => home_url( '/' ),
			'locale'        => get_locale(),
			'front_page_id' => $has_static_front ? (int) get_option( 'page_on_front' ) : null,
			'posts_page_id' => $has_static_front ? (int) get_option( 'page_for_posts' ) : null,
		);
	}

	/**
	 * Types de contenu gérables par le MCP.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_content_types() {
		$content_types = array();

		foreach ( WMCP_Writable_Types::get_post_types() as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( $post_type_object ) {
				$content_types[] = $this->describe_content_type( $post_type_object );
			}
		}

		return $content_types;
	}

	/**
	 * Décrit un type de contenu.
	 *
	 * @param WP_Post_Type $post_type_object Type de contenu.
	 * @return array<string,mixed>
	 */
	private function describe_content_type( WP_Post_Type $post_type_object ) {
		$counts = wp_count_posts( $post_type_object->name );

		return array(
			'name'            => $post_type_object->name,
			'label'           => $post_type_object->label,
			'description'     => (string) $post_type_object->description,
			'hierarchical'    => (bool) $post_type_object->hierarchical,
			'has_archive'     => (bool) $post_type_object->has_archive,
			'supports'        => array_keys( get_all_post_type_supports( $post_type_object->name ) ),
			'taxonomies'      => array_values( array_intersect( get_object_taxonomies( $post_type_object->name ), WMCP_Writable_Types::get_taxonomies() ) ),
			'published_count' => (int) ( $counts->publish ?? 0 ),
			'can_edit'        => current_user_can( $post_type_object->cap->edit_posts ),
			'field_groups'    => $this->fields->summarize_groups( $post_type_object->name ),
		);
	}

	/**
	 * Taxonomies gérables par le MCP.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_taxonomies() {
		$taxonomies = array();

		foreach ( WMCP_Writable_Types::get_taxonomies() as $taxonomy_name ) {
			$taxonomy = get_taxonomy( $taxonomy_name );
			if ( ! $taxonomy ) {
				continue;
			}

			$taxonomies[] = array(
				'name'          => $taxonomy->name,
				'label'         => $taxonomy->label,
				'hierarchical'  => (bool) $taxonomy->hierarchical,
				'content_types' => array_values( (array) $taxonomy->object_type ),
				'terms_count'   => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name ) ),
			);
		}

		return $taxonomies;
	}

	/**
	 * Menus du site : menus classiques avec leurs emplacements, et blocs de navigation.
	 *
	 * @return array<string,mixed>
	 */
	private function get_menus() {
		$locations_by_menu = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			$locations_by_menu[ (int) $menu_id ][] = $location;
		}

		$classic = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$classic[] = array(
				'id'          => (int) $menu->term_id,
				'name'        => $menu->name,
				'items_count' => (int) $menu->count,
				'locations'   => $locations_by_menu[ (int) $menu->term_id ] ?? array(),
			);
		}

		$navigation_blocks = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'numberposts' => 20,
			)
		) as $navigation ) {
			$navigation_blocks[] = array(
				'id'    => (int) $navigation->ID,
				'title' => $navigation->post_title,
			);
		}

		return array(
			'classic'           => $classic,
			'navigation_blocks' => $navigation_blocks,
		);
	}

	/**
	 * Thème actif et ce qu'il impose aux contenus : couleurs, tailles, variations.
	 *
	 * @return array<string,mixed>
	 */
	private function get_theme() {
		$theme    = wp_get_theme();
		$settings = wp_get_global_settings();

		return array(
			'name'             => $theme->get( 'Name' ),
			'version'          => $theme->get( 'Version' ),
			'parent'           => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'is_block_theme'   => wp_is_block_theme(),
			'colors'           => $this->pluck_presets( $settings['color']['palette']['theme'] ?? array(), 'color' ),
			'font_sizes'       => $this->pluck_presets( $settings['typography']['fontSizes']['theme'] ?? array(), 'size' ),
			'style_variations' => array_values( array_filter( array_column( WP_Theme_JSON_Resolver::get_style_variations(), 'title' ) ) ),
		);
	}

	/**
	 * Réduit des préréglages de theme.json à leur identifiant, leur nom et leur valeur.
	 *
	 * @param mixed  $presets   Préréglages du thème.
	 * @param string $value_key Clé qui porte la valeur (`color`, `size`).
	 * @return array<int,array<string,string>>
	 */
	private function pluck_presets( $presets, $value_key ) {
		$plucked = array();

		foreach ( (array) $presets as $preset ) {
			if ( ! is_array( $preset ) || empty( $preset['slug'] ) ) {
				continue;
			}

			$plucked[] = array(
				'slug'  => (string) $preset['slug'],
				'name'  => (string) ( $preset['name'] ?? '' ),
				'value' => (string) ( $preset[ $value_key ] ?? '' ),
			);
		}

		return $plucked;
	}

	/**
	 * Compte les blocs propres au site, par espace de noms (hors blocs du core).
	 *
	 * @return array<string,int>
	 */
	private function get_custom_block_namespaces() {
		$namespaces = array();

		foreach ( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $block_name ) {
			$block_namespace = strstr( $block_name, '/', true );
			if ( 'core' === $block_namespace || false === $block_namespace ) {
				continue;
			}

			$namespaces[ $block_namespace ] = ( $namespaces[ $block_namespace ] ?? 0 ) + 1;
		}

		ksort( $namespaces );

		return $namespaces;
	}
}
