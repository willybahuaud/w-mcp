<?php
/**
 * Mise en forme des objets WordPress renvoyés à l'IA.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Presenter
 *
 * Une seule représentation par type d'objet, partagée par toutes les abilities :
 * l'IA retrouve les mêmes champs en lecture et après une écriture.
 */
class WMCP_Presenter {

	/**
	 * Présente un contenu.
	 *
	 * @param WP_Post $post         Contenu.
	 * @param bool    $with_content Vrai pour inclure le contenu brut (balisage de blocs).
	 * @return array<string,mixed>
	 */
	public static function post( WP_Post $post, $with_content = false ) {
		$presented = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'excerpt'  => $post->post_excerpt,
			'link'     => (string) get_permalink( $post ),
			'modified' => self::modified_date( $post ),
			'author'   => (int) $post->post_author,
		);

		if ( $with_content ) {
			$presented['content'] = $post->post_content;
		}

		return $presented;
	}

	/**
	 * Retourne la date de dernière modification d'un contenu, en UTC.
	 *
	 * WordPress laisse la date GMT à zéro tant qu'un brouillon n'a jamais été
	 * enregistré une seconde fois : on se rabat alors sur la date locale.
	 *
	 * @param WP_Post $post Contenu.
	 * @return string
	 */
	private static function modified_date( WP_Post $post ) {
		if ( '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			return $post->post_modified_gmt . ' UTC';
		}

		return get_gmt_from_date( $post->post_modified ) . ' UTC';
	}

	/**
	 * Présente un terme.
	 *
	 * @param WP_Term $term Terme.
	 * @return array<string,mixed>
	 */
	public static function term( WP_Term $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	/**
	 * Présente un média.
	 *
	 * @param WP_Post $attachment Média.
	 * @return array<string,mixed>
	 */
	public static function media( WP_Post $attachment ) {
		return array(
			'id'          => (int) $attachment->ID,
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt_text'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'mime_type'   => $attachment->post_mime_type,
			'url'         => (string) wp_get_attachment_url( $attachment->ID ),
		);
	}
}
