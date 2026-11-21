<?php
/**
 * Accès aux tables du journal.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Log_Store
 *
 * Lignes détaillées (écritures et refus) et compteurs de lecture.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Tables propres au plugin, nom issu d'une constante.
 */
class WMCP_Log_Store extends WMCP_Table_Store {

	/**
	 * {@inheritDoc}
	 */
	protected function get_table() {
		return WMCP_Schema::TABLE_LOG;
	}

	/**
	 * Incrémente le compteur de lecture du jour.
	 *
	 * @param int    $user_id      Identifiant de l'utilisateur.
	 * @param string $ability_name Nom de l'ability.
	 * @return void
	 */
	public function increment_read_count( $user_id, $ability_name ) {
		global $wpdb;

		$table = WMCP_Schema::table_name( WMCP_Schema::TABLE_READ_COUNTS );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, user_id, ability, calls) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE calls = calls + 1",
				gmdate( 'Y-m-d' ),
				(int) $user_id,
				$ability_name
			)
		);
	}

	/**
	 * Retourne les compteurs de lecture depuis une date.
	 *
	 * @param string $since_day Date au format Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_read_counts( $since_day ) {
		global $wpdb;

		$table = WMCP_Schema::table_name( WMCP_Schema::TABLE_READ_COUNTS );

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT day, user_id, ability, calls FROM {$table} WHERE day >= %s ORDER BY day ASC", $since_day ),
			ARRAY_A
		);
	}

	/**
	 * Supprime les compteurs de lecture anciens.
	 *
	 * @param int $days Âge maximal, en jours.
	 * @return void
	 */
	public function purge_read_counts( $days ) {
		global $wpdb;

		$table = WMCP_Schema::table_name( WMCP_Schema::TABLE_READ_COUNTS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Compte les lignes écrites pour un utilisateur sur une période récente.
	 *
	 * @param int $user_id Identifiant de l'utilisateur.
	 * @param int $seconds Durée de la période, en secondes.
	 * @return int
	 */
	public function count_recent_for_user( $user_id, $seconds ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE user_id = %d AND created_at >= %s",
				(int) $user_id,
				gmdate( 'Y-m-d H:i:s', time() - (int) $seconds )
			)
		);
	}

	/**
	 * Fenêtre pendant laquelle un refus est considéré comme lié à une demande, en secondes.
	 *
	 * @var int
	 */
	const RECENT_DENIAL_WINDOW = 1800;

	/**
	 * Retourne les identifiants des refus qui ont précédé une demande.
	 *
	 * Sert à rattacher à une demande ce que l'IA a tenté juste avant. On prend les
	 * refus de la même session MCP, et ceux du même utilisateur dans la dernière
	 * demi-heure : certains clients n'envoient pas d'identifiant de session, ou en
	 * changent à chaque appel.
	 *
	 * @param string $session_id Identifiant de session MCP.
	 * @param int    $user_id    Identifiant de l'utilisateur.
	 * @param int    $limit      Nombre maximal d'identifiants.
	 * @return int[]
	 */
	public function get_recent_denied_ids( $session_id, $user_id, $limit = 20 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table_name()} WHERE user_id = %d AND status = %s AND ( session_id = %s OR created_at >= %s ) ORDER BY id DESC LIMIT %d",
				(int) $user_id,
				WMCP_Logger::STATUS_DENIED,
				$session_id,
				gmdate( 'Y-m-d H:i:s', time() - self::RECENT_DENIAL_WINDOW ),
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
