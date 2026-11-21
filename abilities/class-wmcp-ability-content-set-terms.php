<?php
/**
 * Ability : assignation de termes à un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Set_Terms
 */
class WMCP_Ability_Content_Set_Terms extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-set-terms';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Assign terms to content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Sets the terms of one taxonomy on a post, page or custom post type entry (its categories, its tags). By default the given terms replace the current ones: pass "append" to add them instead. Use existing term IDs from "wmcp/terms-query".';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_level() {
		return WMCP_Ability_Level::WRITE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_properties() {
		return array(
			'id'       => array(
				'type'        => 'integer',
				'description' => 'ID of the content.',
			),
			'taxonomy' => array(
				'type'        => 'string',
				'description' => 'Taxonomy of the terms.',
				'enum'        => WMCP_Writable_Types::get_taxonomies(),
			),
			'term_ids' => array(
				'type'        => 'array',
				'description' => 'IDs of the terms to set. An empty list removes all terms of this taxonomy.',
				'items'       => array( 'type' => 'integer' ),
			),
			'append'   => array(
				'type'        => 'boolean',
				'description' => 'Add to the current terms instead of replacing them.',
				'default'     => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'id', 'taxonomy', 'term_ids' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		$taxonomy = get_taxonomy( $input['taxonomy'] ?? '' );
		if ( ! $taxonomy || ! WMCP_Writable_Types::has_taxonomy( $taxonomy->name ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return false;
		}

		return $this->can_on_post( 'edit_post', $input['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$post = $this->find_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $input['taxonomy'] ) ) {
			return new WP_Error( 'wmcp_taxonomy_mismatch', 'This taxonomy does not apply to this content type.' );
		}

		// Changer les catégories d'un contenu en ligne modifie ce que voient les visiteurs.
		$blocked = $this->services->get_content_writer()->get_update_blocker( $post );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$term_ids = array_map( 'absint', (array) $input['term_ids'] );
		foreach ( $term_ids as $term_id ) {
			if ( ! term_exists( $term_id, $input['taxonomy'] ) ) {
				return new WP_Error( 'wmcp_unknown_term', sprintf( 'Term %d does not exist in this taxonomy. Use "wmcp/terms-query" to find valid IDs.', $term_id ) );
			}
		}

		$assigned = wp_set_object_terms( $post->ID, $term_ids, $input['taxonomy'], ! empty( $input['append'] ) );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		return array(
			'item'  => WMCP_Presenter::post( $post ),
			'terms' => array_map( array( 'WMCP_Presenter', 'term' ), wp_get_object_terms( $post->ID, $input['taxonomy'] ) ),
		);
	}
}
