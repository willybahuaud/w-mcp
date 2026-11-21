<?php
/**
 * Ability : création d'une composition.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Pattern_Create
 */
class WMCP_Ability_Pattern_Create extends WMCP_Ability {

	const POST_TYPE = 'wp_block';

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/pattern-create';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Create a block pattern', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Saves an arrangement of blocks as a reusable pattern, available in the editor. A synced pattern stays identical everywhere it is used; an unsynced one is a starting point copied into the content. '
			. 'If the user cannot publish, the pattern is saved as "pending" and is not usable until reviewed.';
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
			'title'   => array(
				'type'        => 'string',
				'description' => 'Name of the pattern, as shown in the editor.',
				'minLength'   => 1,
			),
			'content' => array(
				'type'        => 'string',
				'description' => 'Block markup of the pattern.',
				'minLength'   => 1,
			),
			'synced'  => array(
				'type'        => 'boolean',
				'description' => 'Whether the pattern stays in sync everywhere it is used.',
				'default'     => true,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'title', 'content' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return current_user_can( get_post_type_object( self::POST_TYPE )->cap->create_posts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$writer  = $this->services->get_content_writer();
		$pattern = $writer->create(
			self::POST_TYPE,
			array(
				'title'   => $input['title'],
				'content' => $input['content'],
				'status'  => 'publish',
			)
		);

		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}
		if ( false === ( $input['synced'] ?? true ) ) {
			update_post_meta( $pattern->ID, 'wp_pattern_sync_status', 'unsynced' );
		}

		return array(
			'item' => WMCP_Presenter::post( $pattern ),
			'note' => $writer->describe_status( 'publish', $pattern ),
		);
	}
}
