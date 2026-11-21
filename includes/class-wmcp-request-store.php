<?php
/**
 * Accès à la table des demandes de fonctionnalité.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Request_Store
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Table propre au plugin, nom issu d'une constante.
 */
class WMCP_Request_Store extends WMCP_Table_Store {

	/**
	 * {@inheritDoc}
	 */
	protected function get_table() {
		return WMCP_Schema::TABLE_REQUESTS;
	}

	/**
	 * Compte les demandes d'un utilisateur sur les dernières 24 heures.
	 *
	 * @param int $user_id Identifiant de l'utilisateur.
	 * @return int
	 */
	public function count_recent_for_user( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE user_id = %d AND created_at >= %s",
				(int) $user_id,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}
}
