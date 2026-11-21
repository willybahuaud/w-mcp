<?php
/**
 * Ability : retour d'un modèle à la version du thème.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Template_Reset
 */
class WMCP_Ability_Template_Reset extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/template-reset';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Reset a site template', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Discards the customisation of a template or template part and goes back to the version shipped with the theme. The customised version is moved to the trash. '
			. 'Only works on a customised template that has a theme file. Confirm with the user first.';
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
			'type' => array(
				'type'        => 'string',
				'description' => 'Template or template part.',
				'enum'        => WMCP_Template_Service::get_types(),
				'default'     => WMCP_Template_Service::TYPE_TEMPLATE,
			),
			'id'   => array(
				'type'        => 'string',
				'description' => 'ID of the template ("theme//slug").',
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
		return WMCP_Template_Service::current_user_can_manage();
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		if ( ! $this->services->can_publish() ) {
			return new WP_Error( 'wmcp_live_content', WMCP_Messages::action_denied( 'A template is live on every page that uses it, and this user is not allowed to publish.' ) );
		}

		$template = WMCP_Template_Service::find( $input['type'] ?? WMCP_Template_Service::TYPE_TEMPLATE, $input['id'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		// Sans fichier dans le thème, il n'y a pas de version d'origine : on supprimerait le modèle.
		if ( 'custom' !== $template->source || ! $template->has_theme_file ) {
			return new WP_Error( 'wmcp_nothing_to_reset', 'This template is not a customised version of a theme file, so there is nothing to reset.' );
		}

		$reset = WMCP_Template_Service::reset( $template );
		if ( is_wp_error( $reset ) ) {
			return $reset;
		}

		return array(
			'item' => WMCP_Template_Service::present( $reset ),
			'note' => 'The template is back to the version shipped with the theme.',
		);
	}
}
