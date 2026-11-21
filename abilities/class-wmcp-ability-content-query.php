<?php
/**
 * Ability : recherche et lecture de contenus.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Query
 *
 * Sans lecture, l'IA ne trouve pas l'identifiant à modifier. Le plugin AI
 * officiel fournit `core/content-query` : quand elle est présente, celle-ci
 * ne s'enregistre pas.
 */
class WMCP_Ability_Content_Query extends WMCP_Ability {

	/**
	 * Statuts lisibles par cette ability.
	 *
	 * @var string[]
	 */
	const READABLE_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-query';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_superseding_ability() {
		return 'core/content-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Find and read content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists posts, pages or custom post type entries, or reads one by ID. Use it to find the ID of the content to change. '
			. 'Pass "id" with "include_content" to read the full block markup of one entry. Only entries the user can edit are returned, drafts included.';
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
				'post_type'       => array(
					'type'        => 'string',
					'description' => 'Content type to search.',
					'enum'        => WMCP_Writable_Types::get_post_types(),
					'default'     => 'post',
				),
				'id'              => array(
					'type'        => 'integer',
					'description' => 'Read this single entry instead of searching.',
				),
				'search'          => array(
					'type'        => 'string',
					'description' => 'Keywords to look for in titles and content.',
				),
				'status'          => array(
					'type'        => 'string',
					'description' => 'Only return entries with this status. All statuses by default.',
					'enum'        => self::READABLE_STATUSES,
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => 'Include the raw block markup of each entry. Keep it off when listing.',
					'default'     => false,
				),
			),
			$this->get_pagination_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		$post_type        = $input['post_type'] ?? 'post';
		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object && WMCP_Writable_Types::has_post_type( $post_type ) && current_user_can( $post_type_object->cap->edit_posts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$with_content = ! empty( $input['include_content'] );

		if ( ! empty( $input['id'] ) ) {
			$post = $this->find_post( $input['id'] );
			if ( is_wp_error( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'wmcp_not_found', 'No content with this ID is available to this user.' );
			}

			return array( 'items' => array( WMCP_Presenter::post( $post, $with_content ) ) );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $input['post_type'] ?? 'post',
				'post_status'    => $input['status'] ?? self::READABLE_STATUSES,
				's'              => $input['search'] ?? '',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = WMCP_Presenter::post( $post, $with_content );
			}
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}
}
