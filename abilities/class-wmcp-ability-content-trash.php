<?php
/**
 * Ability : mise à la corbeille d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Trash
 *
 * Corbeille uniquement : rien n'est supprimé définitivement par le MCP.
 */
class WMCP_Ability_Content_Trash extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-trash';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_superseding_ability() {
		return 'core/content-delete';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Move content to the trash', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Moves a post, page or custom post type entry to the trash. It disappears from the site but can be restored from the WordPress admin. '
			. 'Nothing is deleted permanently. Confirm with the user before trashing live content.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_level() {
		return WMCP_Ability_Level::DESTRUCTIVE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_properties() {
		return array(
			'id' => array(
				'type'        => 'integer',
				'description' => 'ID of the content to trash.',
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
		return $this->can_on_post( 'delete_post', $input['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$post = $this->find_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Même règle que pour une modification : retirer un contenu en ligne, c'est publier.
		$blocked = $this->services->get_content_writer()->get_update_blocker( $post );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$trashed = wp_trash_post( $post->ID );
		if ( ! $trashed instanceof WP_Post ) {
			return new WP_Error( 'wmcp_trash_failed', 'The content could not be moved to the trash.' );
		}

		return array(
			'item' => WMCP_Presenter::post( get_post( $post->ID ) ),
			'note' => 'Moved to the trash. It can be restored from the WordPress admin.',
		);
	}
}
