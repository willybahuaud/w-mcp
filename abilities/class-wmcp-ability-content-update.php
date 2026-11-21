<?php
/**
 * Ability : modification d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Update
 *
 * Provisoire : disparaît dès que `core/content-update` existe sur le site.
 */
class WMCP_Ability_Content_Update extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_superseding_ability() {
		return 'core/content-update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Updates fields of an existing post, page or custom post type entry. Only the fields you pass are changed, and a revision is kept. '
			. '"content" replaces the whole body: to change one block of a long page, prefer "wmcp/content-update-block". '
			. 'Live content cannot be changed by a user who is not allowed to publish.';
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
				'description' => 'ID of the content to update.',
			),
			'title'   => array(
				'type'        => 'string',
				'description' => 'New title.',
			),
			'content' => array(
				'type'        => 'string',
				'description' => 'New body, as block markup. Replaces the whole body.',
			),
			'excerpt' => array(
				'type'        => 'string',
				'description' => 'New short summary.',
			),
			'slug'    => array(
				'type'        => 'string',
				'description' => 'New URL slug. Changing it changes the public URL.',
			),
			'status'  => array(
				'type'        => 'string',
				'description' => 'New status.',
				'enum'        => WMCP_Content_Writer::ALLOWED_STATUSES,
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

		$writer  = $this->services->get_content_writer();
		$updated = $writer->update( $post, $input );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'item' => WMCP_Presenter::post( $updated ),
			'note' => $writer->describe_status( $input['status'] ?? null, $updated ),
		);
	}
}
