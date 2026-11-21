<?php
/**
 * Ability : document de cadrage du site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Site_Context
 *
 * Sert à l'IA le cadrage rédigé par le prestataire : périmètre du site,
 * consignes, zones à ne pas toucher. Le servir par le MCP évite de dépendre
 * d'un fichier que le client oublierait de charger.
 */
class WMCP_Ability_Site_Context extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/site-context';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Get the site guidelines', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Returns the guidelines written by the site maintainer: what this site is, how its content is organised, editorial rules, and what must not be changed. '
			. 'Read it once at the start of a session, before any change, and follow it.';
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
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return is_user_logged_in();
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$guidelines = $this->services->policy_store->get_site_context();

		return array(
			'site_name'  => get_bloginfo( 'name' ),
			'site_url'   => home_url( '/' ),
			'guidelines' => '' !== $guidelines ? $guidelines : 'No guidelines have been written for this site yet. Stay cautious and ask the user before any significant change.',
		);
	}
}
