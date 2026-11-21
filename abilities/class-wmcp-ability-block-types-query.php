<?php
/**
 * Ability : blocs disponibles sur le site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Block_Types_Query
 */
class WMCP_Ability_Block_Types_Query extends WMCP_Ability {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/block-types-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'List available blocks', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists the blocks registered on this site, with their style variations. Content written through MCP may only use these blocks. '
			. 'Pass "name" to get the attributes of one block before writing its markup.';
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
			'name'      => array(
				'type'        => 'string',
				'description' => 'Full name of one block (for example "core/cover"), to get its attributes.',
			),
			'namespace' => array(
				'type'        => 'string',
				'description' => 'Only list blocks of this namespace (for example "core").',
			),
			'search'    => array(
				'type'        => 'string',
				'description' => 'Keywords to look for in block names and titles.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( ! empty( $input['name'] ) ) {
			$block_type = $registry->get_registered( $input['name'] );
			if ( ! $block_type ) {
				return new WP_Error( 'wmcp_unknown_block', 'This block is not registered on this site.' );
			}

			return array( 'items' => array( $this->present( $block_type, true ) ) );
		}

		$items = array();
		foreach ( $registry->get_all_registered() as $block_type ) {
			if ( $this->matches( $block_type, $input ) ) {
				$items[] = $this->present( $block_type, false );
			}
		}

		return array( 'items' => $items );
	}

	/**
	 * Indique si un bloc correspond aux filtres demandés.
	 *
	 * @param WP_Block_Type       $block_type Type de bloc.
	 * @param array<string,mixed> $input      Données d'entrée.
	 * @return bool
	 */
	private function matches( WP_Block_Type $block_type, array $input ) {
		$block_namespace = (string) ( $input['namespace'] ?? '' );
		if ( '' !== $block_namespace && 0 !== strpos( $block_type->name, $block_namespace . '/' ) ) {
			return false;
		}

		$search = (string) ( $input['search'] ?? '' );

		return '' === $search || false !== stripos( $block_type->name . ' ' . $block_type->title, $search );
	}

	/**
	 * Présente un type de bloc.
	 *
	 * @param WP_Block_Type $block_type      Type de bloc.
	 * @param bool          $with_attributes Vrai pour inclure le schéma des attributs.
	 * @return array<string,mixed>
	 */
	private function present( WP_Block_Type $block_type, $with_attributes ) {
		$presented = array(
			'name'             => $block_type->name,
			'title'            => (string) $block_type->title,
			'category'         => (string) $block_type->category,
			'description'      => (string) $block_type->description,
			'style_variations' => array_column( (array) $block_type->styles, 'name' ),
			// Blocs parents imposés : un bloc « colonne » n'a de sens que dans « colonnes ».
			'allowed_parents'  => (array) $block_type->parent,
		);

		if ( $with_attributes ) {
			$presented['attributes'] = (array) $block_type->attributes;
			$presented['supports']   = (array) $block_type->supports;
		}

		return $presented;
	}
}
