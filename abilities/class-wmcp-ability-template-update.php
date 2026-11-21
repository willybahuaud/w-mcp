<?php
/**
 * Ability : modification d'un modèle.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Template_Update
 *
 * Classée destructive : une erreur dans un modèle casse toutes les pages qui
 * en dépendent. Sur un site dont le thème vit dans un dépôt, la matrice doit
 * la refuser : la copie en base masquerait le fichier déployé.
 */
class WMCP_Ability_Template_Update extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/template-update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update a site template', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Replaces the block markup of a template or template part. This changes every page that uses it, immediately: read the current markup first, change only what is needed, and confirm with the user before saving. '
			. 'The customised version is stored on the site and overrides the theme file. It can be undone with "wmcp/template-reset".';
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
			'type'    => array(
				'type'        => 'string',
				'description' => 'Template or template part.',
				'enum'        => WMCP_Template_Service::get_types(),
				'default'     => WMCP_Template_Service::TYPE_TEMPLATE,
			),
			'id'      => array(
				'type'        => 'string',
				'description' => 'ID of the template ("theme//slug").',
			),
			'content' => array(
				'type'        => 'string',
				'description' => 'New block markup. Replaces the whole template.',
				'minLength'   => 1,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'id', 'content' );
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

		$validity = WMCP_Block_Validator::validate( $input['content'] );
		if ( is_wp_error( $validity ) ) {
			return $validity;
		}

		$updated = WMCP_Template_Service::update_content( $template, $input['content'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array( 'item' => WMCP_Template_Service::present( $updated ) );
	}
}
