<?php
/**
 * Lecture et écriture du balisage de blocs d'un contenu.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Block_Document
 *
 * Fait le lien entre le balisage stocké par WordPress et l'arbre manipulé par
 * WMCP_Block_Tree.
 */
class WMCP_Block_Document {

	/**
	 * Analyse un balisage en arbre de blocs normalisé.
	 *
	 * @param string $content Balisage de blocs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function parse( $content ) {
		return WMCP_Block_Tree::normalize( parse_blocks( (string) $content ) );
	}

	/**
	 * Sérialise un arbre de blocs, un saut de ligne entre deux blocs de premier niveau.
	 *
	 * @param array<int,array<string,mixed>> $blocks Arbre de blocs.
	 * @return string
	 */
	public static function serialize( array $blocks ) {
		return implode( "\n\n", array_map( 'serialize_block', $blocks ) );
	}
}
