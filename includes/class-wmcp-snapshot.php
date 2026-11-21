<?php
/**
 * État avant/après d'un objet modifié par une ability.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Snapshot
 *
 * Pendant l'exécution d'une ability, écoute les hooks du core pour savoir quel
 * objet est touché. L'approche est générique : elle vaut pour nos abilities
 * comme pour celles d'une extension tierce, sans rien leur demander.
 *
 * Pour un contenu, on ne copie rien : on note l'identifiant de révision avant
 * et après, la révision WordPress fait foi. Pour un terme, qui n'a pas de
 * révision, on garde un instantané.
 */
class WMCP_Snapshot {

	const OBJECT_POST = 'post';
	const OBJECT_TERM = 'term';

	/**
	 * Vrai pendant l'exécution d'une ability suivie.
	 *
	 * @var bool
	 */
	private $is_capturing = false;

	/**
	 * Données relevées pour l'appel en cours.
	 *
	 * @var array<string,mixed>
	 */
	private $capture = array();

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'pre_post_update', array( $this, 'on_before_post_update' ), 10, 1 );
		add_action( 'wp_insert_post', array( $this, 'on_post_saved' ), 10, 2 );
		add_action( 'edit_terms', array( $this, 'on_before_term_update' ), 10, 2 );
		add_action( 'created_term', array( $this, 'on_term_saved' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_saved' ), 10, 3 );
	}

	/**
	 * Démarre le relevé pour un appel.
	 *
	 * @return void
	 */
	public function start() {
		$this->is_capturing = true;
		$this->capture      = array();
	}

	/**
	 * Termine le relevé et retourne les colonnes du journal correspondantes.
	 *
	 * @return array<string,mixed> Colonnes `object_type`, `object_id`, `before_ref`, `after_ref`, `before_snapshot`.
	 */
	public function finish() {
		$capture            = $this->capture;
		$this->is_capturing = false;
		$this->capture      = array();

		$columns = array(
			'object_type'     => (string) ( $capture['object_type'] ?? '' ),
			'object_id'       => (int) ( $capture['object_id'] ?? 0 ),
			'before_ref'      => (int) ( $capture['before_ref'] ?? 0 ),
			'after_ref'       => 0,
			'before_snapshot' => (string) ( $capture['before_snapshot'] ?? '' ),
		);

		if ( self::OBJECT_POST === $columns['object_type'] ) {
			$columns['after_ref'] = $this->get_latest_revision_id( $columns['object_id'] );
		}

		return $columns;
	}

	/**
	 * Note la révision en place avant la modification d'un contenu.
	 *
	 * @param int $post_id Identifiant du contenu.
	 * @return void
	 */
	public function on_before_post_update( $post_id ) {
		if ( ! $this->can_track_post( $post_id ) ) {
			return;
		}

		$this->set_object( self::OBJECT_POST, $post_id );
		$this->capture['before_ref'] = $this->get_latest_revision_id( $post_id );
	}

	/**
	 * Note le contenu créé ou modifié.
	 *
	 * @param int     $post_id Identifiant du contenu.
	 * @param WP_Post $post    Contenu enregistré.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post ) {
		if ( 'revision' === $post->post_type || ! $this->can_track_post( $post_id ) ) {
			return;
		}

		$this->set_object( self::OBJECT_POST, $post_id );
	}

	/**
	 * Garde un instantané d'un terme avant sa modification.
	 *
	 * @param int    $term_id  Identifiant du terme.
	 * @param string $taxonomy Taxonomie.
	 * @return void
	 */
	public function on_before_term_update( $term_id, $taxonomy ) {
		if ( ! $this->is_capturing || isset( $this->capture['object_type'] ) ) {
			return;
		}
		// Au-delà de ce point, le terme est le premier objet touché par l'appel.

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$this->set_object( self::OBJECT_TERM, $term_id );
		$this->capture['before_snapshot'] = (string) wp_json_encode(
			array(
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
			)
		);
	}

	/**
	 * Note le terme créé ou modifié.
	 *
	 * @param int $term_id Identifiant du terme.
	 * @return void
	 */
	public function on_term_saved( $term_id ) {
		if ( $this->is_capturing ) {
			$this->set_object( self::OBJECT_TERM, $term_id );
		}
	}

	/**
	 * Enregistre l'objet principal de l'appel.
	 *
	 * Le premier objet touché l'emporte, sauf qu'un contenu prend le pas sur un
	 * terme : enregistrer un contenu crée souvent un terme au passage (le thème
	 * d'un modèle, une étiquette), et c'est le contenu qui intéresse le journal.
	 *
	 * @param string $object_type L'une des constantes OBJECT_*.
	 * @param int    $object_id   Identifiant de l'objet.
	 * @return void
	 */
	private function set_object( $object_type, $object_id ) {
		$tracked_type = $this->capture['object_type'] ?? '';

		if ( '' !== $tracked_type && ! ( self::OBJECT_TERM === $tracked_type && self::OBJECT_POST === $object_type ) ) {
			return;
		}

		$this->capture['object_type'] = $object_type;
		$this->capture['object_id']   = (int) $object_id;
		// L'instantané d'un terme n'a plus de sens si l'objet principal devient un contenu.
		unset( $this->capture['before_snapshot'] );
	}

	/**
	 * Indique si un contenu peut être suivi pour l'appel en cours.
	 *
	 * @param int $post_id Identifiant du contenu.
	 * @return bool
	 */
	private function can_track_post( $post_id ) {
		if ( ! $this->is_capturing || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		// Un autre contenu déjà relevé reste l'objet principal de l'appel.
		$is_post_tracked = self::OBJECT_POST === ( $this->capture['object_type'] ?? '' );

		return ! $is_post_tracked || (int) $post_id === (int) $this->capture['object_id'];
	}

	/**
	 * Retourne l'identifiant de la dernière révision d'un contenu.
	 *
	 * @param int $post_id Identifiant du contenu.
	 * @return int 0 si le contenu n'a pas de révision.
	 */
	private function get_latest_revision_id( $post_id ) {
		$revisions = wp_get_latest_revision_id_and_total_count( $post_id );

		return is_wp_error( $revisions ) ? 0 : (int) $revisions['latest_id'];
	}
}
