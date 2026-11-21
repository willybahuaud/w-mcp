<?php
/**
 * Validation du balisage de blocs écrit par l'IA.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Block_Validator
 *
 * Un modèle produit volontiers du HTML libre ou des blocs qui n'existent pas
 * sur le site. Dans l'éditeur, ça donne un bloc « Classique » ou un bloc
 * invalide que le client ne sait pas réparer. On refuse donc à l'écriture,
 * en disant précisément quoi corriger.
 */
class WMCP_Block_Validator {

	/**
	 * Blocs refusés même s'ils sont enregistrés sur le site.
	 *
	 * Ils portent du HTML ou du code arbitraire : les accepter reviendrait à
	 * autoriser le HTML libre que ce validateur est là pour refuser. Pour un
	 * compte qui a `unfiltered_html`, ce serait aussi une porte au script stocké.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_BLOCKS = array( 'core/html', 'core/freeform', 'core/missing', 'core/shortcode' );

	/**
	 * Retourne les blocs refusés à l'écriture.
	 *
	 * @return string[]
	 */
	public static function get_forbidden_blocks() {
		/**
		 * Filtre les blocs que les abilities de W-MCP refusent d'écrire.
		 *
		 * @param string[] $forbidden_blocks Noms de blocs.
		 */
		return (array) apply_filters( 'wmcp_forbidden_blocks', self::FORBIDDEN_BLOCKS );
	}

	/**
	 * Valide un contenu.
	 *
	 * @param string $content Balisage de blocs.
	 * @return true|WP_Error
	 */
	public static function validate( $content ) {
		$problems = self::find_problems( parse_blocks( (string) $content ) );
		if ( ! $problems ) {
			return true;
		}

		return new WP_Error(
			'wmcp_invalid_blocks',
			'The content is not valid block markup for this site: ' . implode( ' ', array_unique( $problems ) )
				. ' Use only registered blocks (see "wmcp/block-types-query") written as block markup (<!-- wp:namespace/name --> ... <!-- /wp:namespace/name -->).'
		);
	}

	/**
	 * Cherche les blocs inconnus et le HTML hors bloc, à tous les niveaux.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocs analysés par `parse_blocks()`.
	 * @return string[] Descriptions des problèmes.
	 */
	private static function find_problems( array $blocks ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$problems = array();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			if ( null === $block_name ) {
				// parse_blocks() rend les sauts de ligne entre deux blocs comme un bloc sans nom : c'est normal.
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$problems[] = 'Free HTML outside of any block is not allowed.';
				}
				continue;
			}

			if ( ! $registry->is_registered( $block_name ) ) {
				$problems[] = 'The block "' . $block_name . '" is not registered on this site.';
			} elseif ( in_array( $block_name, self::get_forbidden_blocks(), true ) ) {
				$problems[] = 'The block "' . $block_name . '" is not allowed through MCP because it holds raw HTML or code.';
			}

			$problems = array_merge( $problems, self::find_problems( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}

		return $problems;
	}
}
