<?php
/**
 * Ability : création d'un terme.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Term_Create
 */
class WMCP_Ability_Term_Create extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/term-create';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Create a term', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Creates a term in a taxonomy (a category, a tag, a custom taxonomy term). Check with "wmcp/terms-query" that it does not already exist.';
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
			'taxonomy'    => array(
				'type'        => 'string',
				'description' => 'Taxonomy of the new term.',
				'enum'        => WMCP_Writable_Types::get_taxonomies(),
			),
			'name'        => array(
				'type'        => 'string',
				'description' => 'Name of the term.',
				'minLength'   => 1,
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => 'URL slug. Generated from the name when omitted.',
			),
			'description' => array(
				'type'        => 'string',
				'description' => 'Description of the term.',
			),
			'parent'      => array(
				'type'        => 'integer',
				'description' => 'ID of the parent term, for hierarchical taxonomies.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'taxonomy', 'name' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		$taxonomy = get_taxonomy( $input['taxonomy'] ?? '' );

		return $taxonomy && WMCP_Writable_Types::has_taxonomy( $taxonomy->name ) && current_user_can( $taxonomy->cap->manage_terms );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$created = wp_insert_term(
			$input['name'],
			$input['taxonomy'],
			array(
				'slug'        => $input['slug'] ?? '',
				'description' => $input['description'] ?? '',
				'parent'      => (int) ( $input['parent'] ?? 0 ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array( 'item' => WMCP_Presenter::term( get_term( $created['term_id'], $input['taxonomy'] ) ) );
	}
}
