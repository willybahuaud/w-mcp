<?php
/**
 * Ability : modification d'une composition.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Pattern_Update
 *
 * Une composition synchronisée est partagée : la modifier change d'un coup
 * tous les contenus qui l'utilisent. Elle suit donc la règle des contenus en
 * ligne et demande le droit de publier.
 */
class WMCP_Ability_Pattern_Update extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/pattern-update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update a block pattern', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Updates the title or the block markup of a saved pattern (source "saved" in "wmcp/patterns-query"). Updating a synced pattern changes every content that uses it at once: tell the user before doing it. '
			. 'Patterns that come from the theme or a plugin cannot be changed here.';
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
			'id'      => array(
				'type'        => 'integer',
				'description' => 'ID of the saved pattern.',
			),
			'title'   => array(
				'type'        => 'string',
				'description' => 'New name.',
				'minLength'   => 1,
			),
			'content' => array(
				'type'        => 'string',
				'description' => 'New block markup. Replaces the whole pattern.',
				'minLength'   => 1,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'id' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return $this->can_on_post( 'edit_post', $input['id'] ?? 0, array( WMCP_Ability_Pattern_Create::POST_TYPE ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$pattern = $this->find_post( $input['id'], array( WMCP_Ability_Pattern_Create::POST_TYPE ) );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		$updated = $this->services->get_content_writer()->update( $pattern, array_intersect_key( $input, array_flip( array( 'title', 'content' ) ) ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array( 'item' => WMCP_Presenter::post( $updated ) );
	}
}
