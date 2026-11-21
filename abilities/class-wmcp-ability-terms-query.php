<?php
/**
 * Ability : recherche de termes.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Terms_Query
 */
class WMCP_Ability_Terms_Query extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/terms-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Find terms', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists the terms of a taxonomy (categories, tags, custom taxonomies), with their IDs. Use it before assigning terms to content or creating a new term, to avoid duplicates.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_level() {
		return WMCP_Ability_Level::READ;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_properties() {
		return array_merge(
			array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy to list.',
					'enum'        => WMCP_Writable_Types::get_taxonomies(),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Keywords to look for in term names.',
				),
			),
			$this->get_pagination_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'taxonomy' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return WMCP_Writable_Types::has_taxonomy( $input['taxonomy'] ?? '' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$per_page = (int) ( $input['per_page'] ?? 20 );

		$terms = get_terms(
			array(
				'taxonomy'   => $input['taxonomy'],
				'search'     => $input['search'] ?? '',
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array( 'items' => array_map( array( 'WMCP_Presenter', 'term' ), $terms ) );
	}
}
