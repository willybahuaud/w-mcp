<?php
/**
 * Manipulation d'un arbre de blocs par chemin.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Block_Tree
 *
 * Permet de modifier un bloc sans réécrire le reste du contenu. Un chemin est
 * une suite d'index séparés par des points : `2` désigne le troisième bloc,
 * `2.0` son premier bloc interne.
 *
 * Logique pure sur les tableaux produits par `parse_blocks()`, testée unitairement.
 */
class WMCP_Block_Tree {

	const OPERATION_REPLACE       = 'replace';
	const OPERATION_INSERT_BEFORE = 'insert_before';
	const OPERATION_INSERT_AFTER  = 'insert_after';
	const OPERATION_REMOVE        = 'remove';

	/**
	 * Longueur maximale du texte d'aperçu d'un bloc, en caractères.
	 *
	 * @var int
	 */
	const PREVIEW_LENGTH = 120;

	/**
	 * Retire les blocs sans nom faits uniquement d'espaces.
	 *
	 * `parse_blocks()` en produit un entre deux blocs de premier niveau : ils
	 * décaleraient les chemins sans rien représenter.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocs de premier niveau.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize( array $blocks ) {
		$kept = array();

		foreach ( $blocks as $block ) {
			if ( null !== ( $block['blockName'] ?? null ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$kept[] = $block;
			}
		}

		return $kept;
	}

	/**
	 * Dresse le plan d'un arbre : une ligne par bloc, avec son chemin.
	 *
	 * @param array<int,array<string,mixed>> $blocks      Blocs normalisés.
	 * @param string                         $parent_path Chemin du bloc parent, vide au premier niveau.
	 * @return array<int,array<string,mixed>>
	 */
	public static function outline( array $blocks, $parent_path = '' ) {
		$lines = array();

		foreach ( array_values( $blocks ) as $index => $block ) {
			$path         = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
			$inner_blocks = (array) ( $block['innerBlocks'] ?? array() );

			$lines[] = array(
				'path'        => $path,
				'name'        => (string) ( $block['blockName'] ?? 'core/freeform' ),
				'attributes'  => (array) ( $block['attrs'] ?? array() ),
				'preview'     => self::preview( (string) ( $block['innerHTML'] ?? '' ) ),
				'inner_count' => count( $inner_blocks ),
			);
			$lines   = array_merge( $lines, self::outline( $inner_blocks, $path ) );
		}

		return $lines;
	}

	/**
	 * Retourne le bloc situé à un chemin.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocs normalisés.
	 * @param string                         $path   Chemin du bloc.
	 * @return array<string,mixed>|null Null si le chemin ne désigne aucun bloc.
	 */
	public static function get( array $blocks, $path ) {
		$indexes = self::parse_path( $path );
		if ( null === $indexes ) {
			return null;
		}

		$block = null;
		foreach ( $indexes as $index ) {
			if ( ! isset( $blocks[ $index ] ) ) {
				return null;
			}
			$block  = $blocks[ $index ];
			$blocks = array_values( (array) ( $block['innerBlocks'] ?? array() ) );
		}

		return $block;
	}

	/**
	 * Applique une opération à un chemin et retourne le nouvel arbre.
	 *
	 * @param array<int,array<string,mixed>> $blocks     Blocs normalisés.
	 * @param string                         $path       Chemin du bloc visé.
	 * @param string                         $operation  L'une des constantes OPERATION_*.
	 * @param array<int,array<string,mixed>> $new_blocks Blocs à placer (ignorés pour une suppression).
	 * @return array<int,array<string,mixed>>|null Null si le chemin ne désigne aucun bloc.
	 */
	public static function apply( array $blocks, $path, $operation, array $new_blocks = array() ) {
		$indexes = self::parse_path( $path );
		if ( null === $indexes || null === self::get( $blocks, $path ) ) {
			return null;
		}

		return self::apply_at( array_values( $blocks ), $indexes, $operation, array_values( $new_blocks ) );
	}

	/**
	 * Descend jusqu'au parent du bloc visé, puis y applique l'opération.
	 *
	 * @param array<int,array<string,mixed>> $blocks     Blocs du niveau courant.
	 * @param int[]                          $indexes    Index restant à parcourir.
	 * @param string                         $operation  L'une des constantes OPERATION_*.
	 * @param array<int,array<string,mixed>> $new_blocks Blocs à placer.
	 * @return array<int,array<string,mixed>>
	 */
	private static function apply_at( array $blocks, array $indexes, $operation, array $new_blocks ) {
		$index = array_shift( $indexes );

		if ( ! $indexes ) {
			list( $offset, $removed_count ) = self::get_splice( $index, $operation );
			array_splice( $blocks, $offset, $removed_count, self::OPERATION_REMOVE === $operation ? array() : $new_blocks );

			return $blocks;
		}

		$parent       = $blocks[ $index ];
		$inner_before = array_values( (array) ( $parent['innerBlocks'] ?? array() ) );
		$inner_after  = self::apply_at( $inner_before, $indexes, $operation, $new_blocks );

		// On ne recale les emplacements que du parent direct : plus haut, le nombre d'enfants ne change pas.
		if ( 1 === count( $indexes ) ) {
			list( $offset, $removed_count ) = self::get_splice( $indexes[0], $operation );
			$inserted_count                 = count( $inner_after ) - count( $inner_before ) + $removed_count;
			$parent['innerContent']         = self::splice_placeholders( (array) ( $parent['innerContent'] ?? array() ), $offset, $removed_count, $inserted_count );
		}

		$parent['innerBlocks'] = $inner_after;
		$blocks[ $index ]      = $parent;

		return $blocks;
	}

	/**
	 * Traduit une opération en paramètres de découpe.
	 *
	 * @param int    $index     Index du bloc visé.
	 * @param string $operation L'une des constantes OPERATION_*.
	 * @return array{int,int} Position de la découpe et nombre de blocs retirés.
	 */
	private static function get_splice( $index, $operation ) {
		switch ( $operation ) {
			case self::OPERATION_INSERT_BEFORE:
				return array( $index, 0 );
			case self::OPERATION_INSERT_AFTER:
				return array( $index + 1, 0 );
			default:
				return array( $index, 1 );
		}
	}

	/**
	 * Recale les emplacements de blocs internes dans le `innerContent` d'un parent.
	 *
	 * `innerContent` alterne des fragments HTML et des `null`, un par bloc interne.
	 * Ajouter ou retirer un bloc interne sans toucher à ces `null` produirait un
	 * balisage incohérent à la sérialisation.
	 *
	 * @param array<int,string|null> $inner_content  Contenu interne du parent.
	 * @param int                    $offset         Rang du premier emplacement concerné.
	 * @param int                    $removed_count  Nombre d'emplacements à retirer.
	 * @param int                    $inserted_count Nombre d'emplacements à ajouter.
	 * @return array<int,string|null>
	 */
	private static function splice_placeholders( array $inner_content, $offset, $removed_count, $inserted_count ) {
		$placeholder_positions = array_keys( $inner_content, null, true );
		$new_placeholders      = array_fill( 0, max( 0, $inserted_count ), null );

		if ( isset( $placeholder_positions[ $offset ] ) ) {
			array_splice( $inner_content, $placeholder_positions[ $offset ], $removed_count, $new_placeholders );
			return $inner_content;
		}

		// Insertion après le dernier bloc interne : juste après son emplacement,
		// ou avant la balise fermante si le parent n'en avait aucun.
		$last_position = end( $placeholder_positions );
		$insert_at     = false === $last_position ? max( 0, count( $inner_content ) - 1 ) : $last_position + 1;
		array_splice( $inner_content, $insert_at, 0, $new_placeholders );

		return $inner_content;
	}

	/**
	 * Convertit un chemin en liste d'index.
	 *
	 * @param mixed $path Chemin, par exemple `2.0.1`.
	 * @return int[]|null Null si le chemin est mal formé.
	 */
	private static function parse_path( $path ) {
		if ( ! is_string( $path ) || 1 !== preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			return null;
		}

		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Extrait un aperçu textuel du HTML d'un bloc.
	 *
	 * @param string $html HTML du bloc.
	 * @return string
	 */
	private static function preview( $html ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Classe pure, testée sans WordPress.

		return mb_strlen( $text ) > self::PREVIEW_LENGTH ? mb_substr( $text, 0, self::PREVIEW_LENGTH ) . '…' : $text;
	}
}
