<?php
/**
 * Ability : modèles de l'éditeur de site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Templates_Query
 */
class WMCP_Ability_Templates_Query extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/templates-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'List site templates', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists the templates ("wp_template": page layouts such as single post or archive) and template parts ("wp_template_part": header, footer) of the block theme. '
			. '"source" tells whether a template still comes from the theme files ("theme") or was customised on the site ("custom"). Pass "id" with "include_content" to read one.';
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
			'type'            => array(
				'type'        => 'string',
				'description' => 'Templates or template parts.',
				'enum'        => WMCP_Template_Service::get_types(),
				'default'     => WMCP_Template_Service::TYPE_TEMPLATE,
			),
			'id'              => array(
				'type'        => 'string',
				'description' => 'Read this single template, by its ID ("theme//slug").',
			),
			'include_content' => array(
				'type'        => 'boolean',
				'description' => 'Include the block markup. Keep it off when listing.',
				'default'     => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return WMCP_Template_Service::current_user_can_manage();
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$template_type = $input['type'] ?? WMCP_Template_Service::TYPE_TEMPLATE;
		$with_content  = ! empty( $input['include_content'] );

		if ( ! empty( $input['id'] ) ) {
			$template = WMCP_Template_Service::find( $template_type, $input['id'] );
			if ( is_wp_error( $template ) ) {
				return $template;
			}

			return array( 'items' => array( WMCP_Template_Service::present( $template, $with_content ) ) );
		}

		$items = array();
		foreach ( get_block_templates( array(), $template_type ) as $template ) {
			$items[] = WMCP_Template_Service::present( $template, $with_content );
		}

		return array( 'items' => $items );
	}
}
