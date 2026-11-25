<?php
/**
 * Documents du site mis à disposition de l'IA.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Document_Store
 *
 * Le cadrage (`wmcp/site-context`) est lu à chaque session : il doit rester
 * court. Tout ce qui est long et ne sert que par moments (procédure, charte
 * éditoriale, convention d'un gabarit) va dans des documents nommés, que l'IA
 * liste puis ouvre à la demande. Ils sont rédigés dans WBoard et arrivent avec
 * la matrice.
 */
class WMCP_Document_Store {

	const OPTION_DOCUMENTS = 'wmcp_documents';

	/**
	 * Nombre maximal de documents par site.
	 *
	 * @var int
	 */
	const MAX_DOCUMENTS = 20;

	/**
	 * Taille maximale d'un document, en octets.
	 *
	 * @var int
	 */
	const MAX_CONTENT_BYTES = 65536;

	const MAX_TITLE_BYTES   = 150;
	const MAX_SUMMARY_BYTES = 400;

	/**
	 * Remplace tous les documents du site par ceux reçus.
	 *
	 * Un envoi porte toujours la liste complète : c'est ce qui permet de
	 * retirer un document sans route dédiée.
	 *
	 * @param mixed $documents Liste de `{ slug, title, summary, content }`.
	 * @return int Nombre de documents retenus.
	 */
	public function replace_all( $documents ) {
		$stored = array();

		foreach ( (array) $documents as $document ) {
			if ( count( $stored ) >= self::MAX_DOCUMENTS ) {
				break;
			}

			$clean = is_array( $document ) ? $this->sanitize( $document ) : null;
			if ( null !== $clean ) {
				$stored[ $clean['slug'] ] = $clean;
			}
		}

		update_option( self::OPTION_DOCUMENTS, array_values( $stored ), false );

		return count( $stored );
	}

	/**
	 * Liste les documents, sans leur contenu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_index() {
		$index = array();

		foreach ( $this->get_all() as $document ) {
			$index[] = array(
				'slug'    => $document['slug'],
				'title'   => $document['title'],
				'summary' => $document['summary'],
				'length'  => strlen( $document['content'] ),
			);
		}

		return $index;
	}

	/**
	 * Retrouve un document par son identifiant.
	 *
	 * @param string $slug Identifiant du document.
	 * @return array<string,string>|null
	 */
	public function get( $slug ) {
		foreach ( $this->get_all() as $document ) {
			if ( $document['slug'] === $slug ) {
				return $document;
			}
		}

		return null;
	}

	/**
	 * Compte les documents en place.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->get_all() );
	}

	/**
	 * Lit les documents enregistrés, en écartant ce qui n'en a pas la forme.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_all() {
		$documents = array();

		foreach ( (array) get_option( self::OPTION_DOCUMENTS, array() ) as $document ) {
			if ( is_array( $document ) && isset( $document['slug'], $document['title'], $document['summary'], $document['content'] ) ) {
				$documents[] = $document;
			}
		}

		return $documents;
	}

	/**
	 * Nettoie un document reçu.
	 *
	 * @param array<string,mixed> $document Document reçu.
	 * @return array<string,string>|null Null s'il n'a ni identifiant ni contenu.
	 */
	private function sanitize( array $document ) {
		$slug    = sanitize_title( (string) ( $document['slug'] ?? '' ) );
		$content = sanitize_textarea_field( (string) ( $document['content'] ?? '' ) );

		if ( '' === $slug || '' === $content ) {
			return null;
		}

		$title = sanitize_text_field( (string) ( $document['title'] ?? '' ) );

		return array(
			'slug'    => $slug,
			'title'   => mb_strcut( '' !== $title ? $title : $slug, 0, self::MAX_TITLE_BYTES, 'UTF-8' ),
			'summary' => mb_strcut( sanitize_text_field( (string) ( $document['summary'] ?? '' ) ), 0, self::MAX_SUMMARY_BYTES, 'UTF-8' ),
			'content' => mb_strcut( $content, 0, self::MAX_CONTENT_BYTES, 'UTF-8' ),
		);
	}
}
