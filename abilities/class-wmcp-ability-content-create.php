<?php
/**
 * Ability : création d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Create
 *
 * Provisoire : disparaît dès que `core/content-create` existe sur le site.
 */
class WMCP_Ability_Content_Create extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-create';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_superseding_ability() {
		return 'core/content-create';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Create content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Creates a post, page or custom post type entry. Content must be WordPress block markup using blocks registered on this site. '
			. 'It is saved as a draft unless another status is given. If the user cannot publish, a publish request is saved as "pending" for review.';
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
			'post_type' => array(
				'type'        => 'string',
				'description' => 'Content type to create.',
				'enum'        => WMCP_Writable_Types::get_post_types(),
				'default'     => 'post',
			),
			'title'     => array(
				'type'        => 'string',
				'description' => 'Title.',
				'minLength'   => 1,
			),
			'content'   => array(
				'type'        => 'string',
				'description' => 'Body, as block markup.',
			),
			'excerpt'   => array(
				'type'        => 'string',
				'description' => 'Short summary.',
			),
			'slug'      => array(
				'type'        => 'string',
				'description' => 'URL slug. Generated from the title when omitted.',
			),
			'status'    => array(
				'type'        => 'string',
				'description' => 'Requested status.',
				'enum'        => WMCP_Content_Writer::ALLOWED_STATUSES,
				'default'     => 'draft',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'title' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		$post_type        = $input['post_type'] ?? 'post';
		$post_type_object = get_post_type_object( $post_type );

		// La liste blanche est revérifiée ici : le type par défaut n'est pas passé par le schéma.
		return $post_type_object && WMCP_Writable_Types::has_post_type( $post_type ) && current_user_can( $post_type_object->cap->create_posts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$writer = $this->services->get_content_writer();
		$post   = $writer->create( $input['post_type'] ?? 'post', $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'item' => WMCP_Presenter::post( $post ),
			'note' => $writer->describe_status( $input['status'] ?? 'draft', $post ),
		);
	}
}
