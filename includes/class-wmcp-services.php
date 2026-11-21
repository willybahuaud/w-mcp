<?php
/**
 * Services partagés du plugin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Services
 *
 * Regroupe les objets construits une fois au démarrage, pour les passer aux
 * abilities et aux routes sans variables globales ni singletons éparpillés.
 */
class WMCP_Services {

	/**
	 * Service d'écriture des contenus, construit à la première demande.
	 *
	 * @var WMCP_Content_Writer|null
	 */
	private $content_writer = null;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Context       $context       Contexte de la requête.
	 * @param WMCP_Policy_Store  $policy_store  Stockage de la matrice.
	 * @param WMCP_Log_Store     $log_store     Accès au journal.
	 * @param WMCP_Request_Store $request_store Accès aux demandes.
	 */
	public function __construct(
		public readonly WMCP_Context $context,
		public readonly WMCP_Policy_Store $policy_store,
		public readonly WMCP_Log_Store $log_store,
		public readonly WMCP_Request_Store $request_store
	) {
	}

	/**
	 * Indique si l'utilisateur courant peut publier directement.
	 *
	 * Hors MCP, la matrice ne s'applique pas : seules les capabilities WordPress comptent.
	 *
	 * @return bool
	 */
	public function can_publish() {
		if ( ! $this->context->is_mcp() ) {
			return true;
		}

		return $this->policy_store->get_resolver()->can_publish( get_current_user_id() );
	}

	/**
	 * Retourne le service d'écriture des contenus.
	 *
	 * @return WMCP_Content_Writer
	 */
	public function get_content_writer() {
		if ( null === $this->content_writer ) {
			$this->content_writer = new WMCP_Content_Writer( $this );
		}

		return $this->content_writer;
	}
}
