<?php
/**
 * Ability : modification d'un bloc d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Content_Update_Block
 *
 * Réécrire toute une page pour changer un paragraphe est le défaut relevé sur
 * les abilities d'écriture officielles en préparation : le modèle régénère le
 * reste et l'abîme. Ici, seul le bloc visé change.
 */
class WMCP_Ability_Content_Update_Block extends WMCP_Ability {

	/**
	 * Opérations acceptées.
	 *
	 * @var string[]
	 */
	const OPERATIONS = array(
		WMCP_Block_Tree::OPERATION_REPLACE,
		WMCP_Block_Tree::OPERATION_INSERT_BEFORE,
		WMCP_Block_Tree::OPERATION_INSERT_AFTER,
		WMCP_Block_Tree::OPERATION_REMOVE,
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/content-update-block';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update one block of content', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Replaces, inserts or removes blocks at a given path, leaving the rest of the content untouched. Get the paths from "wmcp/content-get-blocks" first: they change after every edit. '
			. '"content" is block markup for one or more blocks, required unless the operation is "remove". A revision is kept.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_level() {
		return WMCP_Ability_Level::WRITE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_properties() {
		return array(
			'id'        => array(
				'type'        => 'integer',
				'description' => 'ID of the content.',
			),
			'path'      => array(
				'type'        => 'string',
				'description' => 'Path of the target block.',
				'pattern'     => '^\d+(\.\d+)*$',
			),
			'operation' => array(
				'type'        => 'string',
				'description' => 'What to do at this path.',
				'enum'        => self::OPERATIONS,
				'default'     => WMCP_Block_Tree::OPERATION_REPLACE,
			),
			'content'   => array(
				'type'        => 'string',
				'description' => 'Block markup of the new block(s).',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'id', 'path' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return $this->can_on_post( 'edit_post', $input['id'] ?? 0, WMCP_Ability_Content_Get_Blocks::get_block_post_types() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$post = $this->find_post( $input['id'], WMCP_Ability_Content_Get_Blocks::get_block_post_types() );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$operation  = $input['operation'] ?? WMCP_Block_Tree::OPERATION_REPLACE;
		$new_blocks = $this->get_new_blocks( $operation, $input['content'] ?? '' );
		if ( is_wp_error( $new_blocks ) ) {
			return $new_blocks;
		}

		$blocks = WMCP_Block_Tree::apply( WMCP_Block_Document::parse( $post->post_content ), $input['path'], $operation, $new_blocks );
		if ( null === $blocks ) {
			return new WP_Error( 'wmcp_unknown_path', 'No block at this path. Call "wmcp/content-get-blocks" to get the current outline.' );
		}

		// Seul le fragment ajouté a été validé : le reste du contenu était déjà en place.
		$updated = $this->services->get_content_writer()->update( $post, array( 'content' => WMCP_Block_Document::serialize( $blocks ) ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'item'   => WMCP_Presenter::post( $updated ),
			'blocks' => WMCP_Block_Tree::outline( WMCP_Block_Document::parse( $updated->post_content ) ),
		);
	}

	/**
	 * Valide et analyse les blocs à placer.
	 *
	 * @param string $operation Opération demandée.
	 * @param string $content   Balisage reçu.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function get_new_blocks( $operation, $content ) {
		if ( WMCP_Block_Tree::OPERATION_REMOVE === $operation ) {
			return array();
		}
		if ( '' === trim( (string) $content ) ) {
			return new WP_Error( 'wmcp_missing_content', 'The "content" field is required for this operation.' );
		}

		$validity = WMCP_Block_Validator::validate( $content );
		if ( is_wp_error( $validity ) ) {
			return $validity;
		}

		return WMCP_Block_Document::parse( $content );
	}
}
