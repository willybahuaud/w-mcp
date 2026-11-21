<?php
/**
 * Ability : droits de l'utilisateur courant.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_My_Permissions
 *
 * Dit à l'IA ce que l'utilisateur peut faire, pour qu'elle ne tâtonne pas à
 * coups d'appels refusés.
 */
class WMCP_Ability_My_Permissions extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/my-permissions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Get my permissions', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Returns what the current user is allowed to do on this site through MCP: their profile, whether they can publish directly, and the abilities they can use with their risk level. '
			. 'Call it before planning changes. Anything not listed is not allowed: use "' . WMCP_Messages::REQUEST_ABILITY . '" to ask for it.';
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
		$resolver = $this->services->policy_store->get_resolver();
		$user_id  = get_current_user_id();

		return array(
			'profile'     => (string) $resolver->get_profile_name( $user_id ),
			'can_publish' => $resolver->can_publish( $user_id ),
			'abilities'   => $this->get_allowed_abilities( $resolver, $user_id ),
			'note'        => 'WordPress capabilities still apply on top of this list: an allowed ability can be refused on a specific item the user cannot edit.',
		);
	}

	/**
	 * Liste les abilities que la matrice autorise à un utilisateur.
	 *
	 * @param WMCP_Policy_Resolver $resolver Résolveur de la matrice en place.
	 * @param int                  $user_id  Identifiant de l'utilisateur.
	 * @return array<int,array<string,string>>
	 */
	private function get_allowed_abilities( WMCP_Policy_Resolver $resolver, $user_id ) {
		$allowed = array();

		foreach ( wp_get_abilities() as $ability ) {
			$name  = $ability->get_name();
			$level = WMCP_Guard::get_level( $ability );

			// Les outils de l'adapter sont de la plomberie, pas des droits à présenter.
			if ( 0 === strpos( $name, WMCP_Logger::RELAY_PREFIX ) || ! $resolver->decide( $user_id, $name, $level )->is_allowed() ) {
				continue;
			}

			$allowed[] = array(
				'name'  => $name,
				'level' => $level,
				'label' => $ability->get_label(),
			);
		}

		return $allowed;
	}
}
