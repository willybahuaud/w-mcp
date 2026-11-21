<?php
/**
 * Purge planifiée du journal.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Retention
 *
 * Le journal complet vit chez WBoard. Sur le site, on ne garde que le temps
 * nécessaire à la collecte, pour ne pas faire grossir la base du client.
 */
class WMCP_Retention {

	const CRON_HOOK = 'wmcp_purge';

	/**
	 * Durée de conservation d'une ligne déjà collectée, en jours.
	 *
	 * @var int
	 */
	const COLLECTED_DAYS = 90;

	/**
	 * Durée de conservation d'une ligne jamais collectée, en jours.
	 *
	 * @var int
	 */
	const UNCOLLECTED_DAYS = 180;

	/**
	 * Tables à purger.
	 *
	 * @var WMCP_Table_Store[]
	 */
	private $stores;

	/**
	 * Accès aux compteurs de lecture.
	 *
	 * @var WMCP_Log_Store
	 */
	private $log_store;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Log_Store     $log_store     Accès au journal.
	 * @param WMCP_Request_Store $request_store Accès aux demandes.
	 */
	public function __construct( WMCP_Log_Store $log_store, WMCP_Request_Store $request_store ) {
		$this->log_store = $log_store;
		$this->stores    = array( $log_store, $request_store );
	}

	/**
	 * Branche les hooks et planifie la purge quotidienne.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'purge' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Supprime les données anciennes.
	 *
	 * @return void
	 */
	public function purge() {
		foreach ( $this->stores as $store ) {
			$store->purge( self::COLLECTED_DAYS, self::UNCOLLECTED_DAYS );
		}

		$this->log_store->purge_read_counts( self::UNCOLLECTED_DAYS );
	}

	/**
	 * Retire la tâche planifiée, à la désactivation du plugin.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
