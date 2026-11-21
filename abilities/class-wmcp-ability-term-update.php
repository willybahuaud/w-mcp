<?php
/**
 * Ability : modification d'un terme.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Term_Update
 */
class WMCP_Ability_Term_Update extends WMCP_Ability {

	/**
	 * Champs modifiables d'un terme.
	 *
	 * @var string[]
	 */
	const EDITABLE_FIELDS = array( 'name', 'slug', 'description', 'parent' );

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/term-update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update a term', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Updates the name, slug, description or parent of an existing term. Only the fields you pass are changed. Changing the slug changes the public URL of the term archive.';
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
				'description' => 'Taxonomy of the term.',
				'enum'        => WMCP_Writable_Types::get_taxonomies(),
			),
			'id'          => array(
				'type'        => 'integer',
				'description' => 'ID of the term to update.',
			),
			'name'        => array(
				'type'        => 'string',
				'description' => 'New name.',
				'minLength'   => 1,
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => 'New URL slug.',
			),
			'description' => array(
				'type'        => 'string',
				'description' => 'New description.',
			),
			'parent'      => array(
				'type'        => 'integer',
				'description' => 'ID of the new parent term, 0 for none.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'taxonomy', 'id' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return WMCP_Writable_Types::has_taxonomy( $input['taxonomy'] ?? '' ) && current_user_can( 'edit_term', absint( $input['id'] ?? 0 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		// Un terme est en ligne dès qu'il existe : le modifier change tout de suite
		// ce que voient les visiteurs. Même règle que pour un contenu publié.
		if ( ! $this->services->can_publish() ) {
			return new WP_Error( 'wmcp_live_content', WMCP_Messages::action_denied( 'This term is live and this user is not allowed to publish, so it cannot be changed directly.' ) );
		}

		$changes = array_intersect_key( $input, array_flip( self::EDITABLE_FIELDS ) );
		if ( ! $changes ) {
			return new WP_Error( 'wmcp_nothing_to_update', 'Pass at least one field to change: ' . implode( ', ', self::EDITABLE_FIELDS ) . '.' );
		}

		$updated = wp_update_term( absint( $input['id'] ), $input['taxonomy'], $changes );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array( 'item' => WMCP_Presenter::term( get_term( $updated['term_id'], $input['taxonomy'] ) ) );
	}
}
