<?php
/**
 * Démarrage du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Plugin
 *
 * Racine de composition : construit les objets et branche leurs hooks. Aucune
 * logique métier ici.
 */
class WMCP_Plugin {

	/**
	 * Instance unique.
	 *
	 * @var WMCP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services partagés, disponibles une fois le plugin démarré.
	 *
	 * @var WMCP_Services|null
	 */
	private $services = null;

	/**
	 * Retourne l'instance unique.
	 *
	 * @return WMCP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Retourne les services partagés.
	 *
	 * @return WMCP_Services|null Null si les prérequis manquent.
	 */
	public function get_services() {
		return $this->services;
	}

	/**
	 * Démarre le plugin si ses prérequis sont réunis.
	 *
	 * @return void
	 */
	public function boot() {
		$dependencies = new WMCP_Dependencies();
		if ( $dependencies->get_missing() ) {
			add_action( 'admin_notices', array( $dependencies, 'render_notice' ) );
			return;
		}

		WMCP_Schema::maybe_upgrade();

		$this->services = new WMCP_Services(
			new WMCP_Context(),
			new WMCP_Policy_Store( new WMCP_Policy_Validator() ),
			new WMCP_Log_Store(),
			new WMCP_Request_Store()
		);

		$snapshot = new WMCP_Snapshot();
		$logger   = new WMCP_Logger( $this->services->context, $this->services->policy_store, $this->services->log_store, $snapshot );

		$snapshot->register_hooks();
		$logger->register_hooks();
		( new WMCP_Adapter_Bridge( $this->services->context, $this->services->policy_store ) )->register_hooks();
		( new WMCP_OAuth( $this->services->policy_store ) )->register_hooks();
		( new WMCP_Guard( $this->services->context, $this->services->policy_store, $logger ) )->register_hooks();
		( new WMCP_Abilities( $this->services ) )->register_hooks();
		( new WMCP_Rest( $this->services, $dependencies ) )->register_hooks();
		( new WMCP_Retention( $this->services->log_store, $this->services->request_store ) )->register_hooks();
		( new WMCP_Updater() )->register_hooks();

		if ( is_admin() ) {
			( new WMCP_Status_Page( $this->services, $dependencies ) )->register_hooks();
		}
	}
}
