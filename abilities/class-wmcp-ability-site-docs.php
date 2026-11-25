<?php
/**
 * Ability : documents du site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Site_Docs
 *
 * Sert les documents rédigés par le prestataire, un par un. Les lister
 * d'abord évite de charger à chaque session des pages qui ne servent qu'à
 * une tâche précise.
 */
class WMCP_Ability_Site_Docs extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/site-docs';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Read the site documents', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Returns the reference documents written by the site maintainer: procedures, editorial rules, conventions of a template or a content type. '
			. 'Without argument, lists the documents (slug, title, summary). With slug, returns the full text of one document. '
			. 'Read the document that matches the task before starting it, and follow it.';
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
		return array(
			'slug' => array(
				'type'        => 'string',
				'description' => 'Read this document. Use a slug from the list.',
			),
		);
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
		$documents = new WMCP_Document_Store();
		$slug      = (string) ( $input['slug'] ?? '' );

		if ( '' === $slug ) {
			return array( 'documents' => $documents->get_index() );
		}

		$document = $documents->get( $slug );
		if ( null === $document ) {
			return new WP_Error( 'wmcp_unknown_document', 'No document with this slug. Call this ability without argument to list them.' );
		}

		return array( 'document' => $document );
	}
}
