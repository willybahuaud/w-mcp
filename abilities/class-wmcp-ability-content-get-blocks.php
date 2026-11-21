<?php
/**
 * Ability : plan des blocs d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Get_Blocks
 */
class WMCP_Ability_Content_Get_Blocks extends WMCP_Ability {

	/**
	 * Types de contenu dont on peut lire les blocs : les types gérables et les compositions.
	 *
	 * @return string[]
	 */
	public static function get_block_post_types() {
		return array_merge( WMCP_Writable_Types::get_post_types(), array( 'wp_block' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-get-blocks';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Get the block outline of content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Returns the outline of a content: one line per block with its path, name, attributes and a text preview. A path is a list of positions separated by dots ("2" is the third block, "2.0" its first inner block). '
			. 'Pass "path" to get the full markup of one block. Use these paths with "wmcp/content-update-block" to change one block without rewriting the page.';
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
		return array(
			'id'   => array(
				'type'        => 'integer',
				'description' => 'ID of the content (or of a saved pattern).',
			),
			'path' => array(
				'type'        => 'string',
				'description' => 'Path of one block, to get its full markup.',
				'pattern'     => '^\d+(\.\d+)*$',
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
		return $this->can_on_post( 'edit_post', $input['id'] ?? 0, self::get_block_post_types() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$post = $this->find_post( $input['id'], self::get_block_post_types() );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocks = WMCP_Block_Document::parse( $post->post_content );

		if ( isset( $input['path'] ) ) {
			$block = WMCP_Block_Tree::get( $blocks, $input['path'] );
			if ( null === $block ) {
				return new WP_Error( 'wmcp_unknown_path', 'No block at this path. Call this ability without "path" to get the current outline.' );
			}

			return array(
				'path'   => $input['path'],
				'markup' => serialize_block( $block ),
			);
		}

		return array(
			'item'   => WMCP_Presenter::post( $post ),
			'blocks' => WMCP_Block_Tree::outline( $blocks ),
		);
	}
}
