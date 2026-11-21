<?php
/**
 * Tables du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Schema
 *
 * Crée et met à jour les trois tables : journal, compteurs de lecture, demandes.
 */
class WMCP_Schema {

	/**
	 * Version du schéma. À incrémenter à chaque changement de structure.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	const OPTION_DB_VERSION = 'wmcp_db_version';

	const TABLE_LOG         = 'wmcp_log';
	const TABLE_READ_COUNTS = 'wmcp_read_counts';
	const TABLE_REQUESTS    = 'wmcp_requests';

	/**
	 * Retourne le nom complet d'une table.
	 *
	 * @param string $table L'une des constantes TABLE_*.
	 * @return string
	 */
	public static function table_name( $table ) {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Met le schéma à niveau si la version enregistrée est en retard.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::install();
		}
	}

	/**
	 * Crée ou met à jour les tables.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::get_table_definitions() as $definition ) {
			dbDelta( $definition );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Retourne les requêtes de création, au format attendu par dbDelta.
	 *
	 * @return string[]
	 */
	private static function get_table_definitions() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$log             = self::table_name( self::TABLE_LOG );
		$read_counts     = self::table_name( self::TABLE_READ_COUNTS );
		$requests        = self::table_name( self::TABLE_REQUESTS );

		return array(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				client varchar(190) NOT NULL DEFAULT '',
				session_id varchar(64) NOT NULL DEFAULT '',
				ability varchar(190) NOT NULL DEFAULT '',
				level varchar(20) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				reason varchar(190) NOT NULL DEFAULT '',
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				input longtext NOT NULL,
				input_hash char(64) NOT NULL DEFAULT '',
				object_type varchar(20) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				before_ref bigint(20) unsigned NOT NULL DEFAULT 0,
				after_ref bigint(20) unsigned NOT NULL DEFAULT 0,
				before_snapshot longtext NOT NULL,
				policy_version int(10) unsigned NOT NULL DEFAULT 0,
				collected_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY session_status (session_id, status)
			) {$charset_collate};",
			"CREATE TABLE {$read_counts} (
				day date NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ability varchar(190) NOT NULL DEFAULT '',
				calls int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day, user_id, ability)
			) {$charset_collate};",
			"CREATE TABLE {$requests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				session_id varchar(64) NOT NULL DEFAULT '',
				intent text NOT NULL,
				blocked_on text NOT NULL,
				related_ability varchar(190) NOT NULL DEFAULT '',
				example text NOT NULL,
				frequency varchar(20) NOT NULL DEFAULT '',
				denied_log_ids text NOT NULL,
				collected_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY user_created (user_id, created_at)
			) {$charset_collate};",
		);
	}
}
