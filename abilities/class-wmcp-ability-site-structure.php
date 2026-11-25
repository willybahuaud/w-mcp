<?php
/**
 * Ability : structure du site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Site_Structure
 *
 * Complète `wmcp/site-context` : le cadrage dit quoi faire et ne pas faire,
 * la structure dit comment le site est construit. Sans elle, l'IA devine les
 * types de contenu et les champs, ou les découvre en plusieurs appels.
 */
class WMCP_Ability_Site_Structure extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/site-structure';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Get the site structure', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Returns how this site is built, read live from WordPress: content types with their supports, taxonomies and custom field groups, menus, the active theme (colors, font sizes, style variations) and the custom block namespaces. '
			. 'Call it without argument once at the start of a session, after wmcp/site-context. '
			. 'Call it with post_type to get the full custom field definitions of one content type (names, types, choices, sub-fields) before reading or planning changes on such content.';
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
			'post_type' => array(
				'type'        => 'string',
				'description' => 'Detail this content type, with its full custom field definitions. Use a name from content_types.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$structure = new WMCP_Site_Structure( new WMCP_Field_Catalog( new WMCP_Field_Presenter() ) );
		$post_type = (string) ( $input['post_type'] ?? '' );

		if ( '' === $post_type ) {
			return $structure->get_overview();
		}

		if ( ! WMCP_Writable_Types::has_post_type( $post_type ) ) {
			return new WP_Error( 'wmcp_unknown_content_type', 'This content type is not available. Use a name from content_types.' );
		}

		return array(
			'content_type' => $structure->get_content_type_detail( $post_type ),
			'note'         => 'Custom fields are described for understanding only: no W-MCP ability writes them yet. If the user needs a field changed, pass the request on with wmcp/request-capability.',
		);
	}
}
