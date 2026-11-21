<?php
/**
 * Ability : recherche de médias.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Media_Query
 */
class WMCP_Ability_Media_Query extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/media-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Find media', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists files of the media library (images, documents), with their ID, URL and alternative text. Use it to pick an existing image for a block, or to find media whose alternative text is missing.';
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
				'search'    => array(
					'type'        => 'string',
					'description' => 'Keywords to look for in titles and file names.',
				),
				'mime_type' => array(
					'type'        => 'string',
					'description' => 'Type filter, for example "image" or "application/pdf".',
				),
			),
			$this->get_pagination_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return current_user_can( 'upload_files' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $input['mime_type'] ?? '',
				's'              => $input['search'] ?? '',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
			)
		);

		return array(
			'items'       => array_map( array( 'WMCP_Presenter', 'media' ), $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}
}
