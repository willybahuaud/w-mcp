<?php
/**
 * Ability : compositions disponibles.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Patterns_Query
 *
 * Deux origines : les compositions déclarées par le thème et les extensions
 * (dans leurs fichiers, en lecture seule), et les compositions créées dans
 * l'éditeur (type de contenu `wp_block`, modifiables).
 */
class WMCP_Ability_Patterns_Query extends WMCP_Ability {

	const SOURCE_REGISTERED = 'registered';
	const SOURCE_SAVED      = 'saved';

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/patterns-query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'List block patterns', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Lists block patterns: ready-made arrangements of blocks that keep the site consistent. Prefer reusing a pattern over composing a layout from scratch. '
			. '"registered" patterns come from the theme and plugins and are read-only. "saved" patterns were created in the editor and can be updated with "wmcp/pattern-update". '
			. 'Pass "include_content" to get the block markup.';
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
			'source'          => array(
				'type'        => 'string',
				'description' => 'Only list patterns of this origin. Both by default.',
				'enum'        => array( self::SOURCE_REGISTERED, self::SOURCE_SAVED ),
			),
			'search'          => array(
				'type'        => 'string',
				'description' => 'Keywords to look for in pattern titles and names.',
			),
			'include_content' => array(
				'type'        => 'boolean',
				'description' => 'Include the block markup of each pattern. Use it with "search" to keep the answer short.',
				'default'     => false,
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
		$source       = $input['source'] ?? '';
		$search       = (string) ( $input['search'] ?? '' );
		$with_content = ! empty( $input['include_content'] );
		$items        = array();

		if ( self::SOURCE_SAVED !== $source ) {
			$items = $this->get_registered_patterns( $search, $with_content );
		}
		if ( self::SOURCE_REGISTERED !== $source ) {
			$items = array_merge( $items, $this->get_saved_patterns( $search, $with_content ) );
		}

		return array( 'items' => $items );
	}

	/**
	 * Retourne les compositions déclarées par le thème et les extensions.
	 *
	 * @param string $search       Mots-clés.
	 * @param bool   $with_content Vrai pour inclure le balisage.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_registered_patterns( $search, $with_content ) {
		$items = array();

		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$title = (string) ( $pattern['title'] ?? '' );
			if ( '' !== $search && false === stripos( $pattern['name'] . ' ' . $title, $search ) ) {
				continue;
			}

			$item = array(
				'source'      => self::SOURCE_REGISTERED,
				'name'        => $pattern['name'],
				'title'       => $title,
				'description' => (string) ( $pattern['description'] ?? '' ),
				'categories'  => (array) ( $pattern['categories'] ?? array() ),
			);
			if ( $with_content ) {
				$item['content'] = (string) ( $pattern['content'] ?? '' );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Retourne les compositions créées dans l'éditeur.
	 *
	 * @param string $search       Mots-clés.
	 * @param bool   $with_content Vrai pour inclure le balisage.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_saved_patterns( $search, $with_content ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				's'              => $search,
				'posts_per_page' => 50,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$item = array(
				'source' => self::SOURCE_SAVED,
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				// Synchronisée : modifier la composition modifie tous les contenus qui l'utilisent.
				'synced' => 'unsynced' !== get_post_meta( $post->ID, 'wp_pattern_sync_status', true ),
			);
			if ( $with_content ) {
				$item['content'] = $post->post_content;
			}

			$items[] = $item;
		}

		return $items;
	}
}
