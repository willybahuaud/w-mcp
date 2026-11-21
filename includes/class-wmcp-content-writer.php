<?php
/**
 * Écriture des contenus.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Content_Writer
 *
 * Règles communes à toutes les écritures de contenu (articles, pages, types
 * custom, compositions) :
 *  - le balisage de blocs est validé avant enregistrement ;
 *  - sans droit de publier, une demande de publication devient « en attente » ;
 *  - sans droit de publier, un contenu déjà en ligne n'est pas modifiable :
 *    le repasser en attente le retirerait du site ;
 *  - un contenu en cours d'édition par quelqu'un d'autre n'est pas écrasé.
 */
class WMCP_Content_Writer {

	/**
	 * Statuts qui mettent un contenu en ligne, tout de suite ou à terme.
	 *
	 * @var string[]
	 */
	const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Statuts qu'une ability peut demander.
	 *
	 * @var string[]
	 */
	const ALLOWED_STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

	/**
	 * Correspondance entre les champs des abilities et ceux de WordPress.
	 *
	 * @var array<string,string>
	 */
	const FIELD_MAP = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'slug'    => 'post_name',
	);

	/**
	 * Services partagés.
	 *
	 * @var WMCP_Services
	 */
	private $services;

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Services $services Services partagés.
	 */
	public function __construct( WMCP_Services $services ) {
		$this->services = $services;
	}

	/**
	 * Crée un contenu.
	 *
	 * @param string              $post_type Type de contenu.
	 * @param array<string,mixed> $fields    Champs de l'ability (`title`, `content`, `excerpt`, `slug`, `status`).
	 * @return WP_Post|WP_Error
	 */
	public function create( $post_type, array $fields ) {
		$post_data = $this->build_post_data( $fields );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}

		$post_data['post_type']   = $post_type;
		$post_data['post_status'] = $this->resolve_status( $fields['status'] ?? 'draft', $post_type );

		return $this->save( $post_data );
	}

	/**
	 * Modifie un contenu existant.
	 *
	 * @param WP_Post             $post               Contenu à modifier.
	 * @param array<string,mixed> $fields             Champs de l'ability à changer.
	 * @param bool                $is_content_checked Vrai si l'appelant a déjà validé ce qu'il a changé
	 *                                                dans le contenu. Sert aux modifications par bloc : on ne
	 *                                                bloque pas l'édition d'un paragraphe parce qu'un autre
	 *                                                bloc, déjà là, n'est plus reconnu.
	 * @return WP_Post|WP_Error
	 */
	public function update( WP_Post $post, array $fields, $is_content_checked = false ) {
		$blocked = $this->get_update_blocker( $post );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$post_data = $this->build_post_data( $fields, $is_content_checked );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}

		$post_data['ID'] = $post->ID;
		if ( isset( $fields['status'] ) ) {
			$post_data['post_status'] = $this->resolve_status( $fields['status'], $post->post_type );
		}

		return $this->save( $post_data );
	}

	/**
	 * Explique un écart entre le statut demandé et le statut obtenu.
	 *
	 * @param string|null $requested_status Statut demandé, ou null.
	 * @param WP_Post     $post             Contenu enregistré.
	 * @return string Chaîne vide s'il n'y a pas d'écart.
	 */
	public function describe_status( $requested_status, WP_Post $post ) {
		if ( null === $requested_status || $requested_status === $post->post_status ) {
			return '';
		}

		return 'The content was saved as "' . $post->post_status . '" instead of "' . $requested_status . '": this user cannot publish directly. Tell the user it is waiting for review.';
	}

	/**
	 * Indique ce qui empêche de modifier un contenu, s'il y a lieu.
	 *
	 * @param WP_Post $post Contenu à modifier.
	 * @return true|WP_Error
	 */
	public function get_update_blocker( WP_Post $post ) {
		if ( in_array( $post->post_status, self::LIVE_STATUSES, true ) && ! $this->can_publish( $post->post_type ) ) {
			return new WP_Error( 'wmcp_live_content', WMCP_Messages::action_denied( 'This content is live and this user is not allowed to publish, so it cannot be changed directly.' ) );
		}

		$locking_user_id = $this->get_locking_user_id( $post->ID );
		if ( $locking_user_id ) {
			$locking_user = get_userdata( $locking_user_id );

			return new WP_Error(
				'wmcp_content_locked',
				sprintf( 'This content is being edited right now by %s. Tell the user and try again later.', $locking_user ? $locking_user->display_name : 'another user' )
			);
		}

		return true;
	}

	/**
	 * Indique si l'utilisateur courant peut mettre en ligne un contenu de ce type.
	 *
	 * Il faut les deux : le profil de la matrice et la capability WordPress.
	 *
	 * @param string $post_type Type de contenu.
	 * @return bool
	 */
	public function can_publish( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object
			&& $this->services->can_publish()
			&& current_user_can( $post_type_object->cap->publish_posts );
	}

	/**
	 * Détermine le statut réellement appliqué.
	 *
	 * @param string $requested_status Statut demandé.
	 * @param string $post_type        Type de contenu.
	 * @return string
	 */
	private function resolve_status( $requested_status, $post_type ) {
		if ( in_array( $requested_status, self::LIVE_STATUSES, true ) && ! $this->can_publish( $post_type ) ) {
			return 'pending';
		}

		return $requested_status;
	}

	/**
	 * Traduit les champs d'une ability en données pour WordPress.
	 *
	 * @param array<string,mixed> $fields             Champs de l'ability.
	 * @param bool                $is_content_checked Vrai pour ne pas revalider le contenu.
	 * @return array<string,mixed>|WP_Error
	 */
	private function build_post_data( array $fields, $is_content_checked = false ) {
		if ( isset( $fields['content'] ) && ! $is_content_checked ) {
			$validity = WMCP_Block_Validator::validate( $fields['content'] );
			if ( is_wp_error( $validity ) ) {
				return $validity;
			}
		}

		$post_data = array();
		foreach ( self::FIELD_MAP as $field => $post_field ) {
			if ( isset( $fields[ $field ] ) ) {
				$post_data[ $post_field ] = $fields[ $field ];
			}
		}

		// WordPress ne filtre pas le HTML des comptes qui ont `unfiltered_html`
		// (éditeurs, administrateurs). Par le MCP, c'est une IA qui écrit, et elle
		// peut avoir lu un contenu piégé : on filtre pour tout le monde.
		if ( $this->services->context->is_mcp() ) {
			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $html_field ) {
				if ( isset( $post_data[ $html_field ] ) ) {
					$post_data[ $html_field ] = wp_kses_post( $post_data[ $html_field ] );
				}
			}
		}

		return $post_data;
	}

	/**
	 * Enregistre un contenu et le relit.
	 *
	 * @param array<string,mixed> $post_data Données au format de `wp_insert_post()`.
	 * @return WP_Post|WP_Error
	 */
	private function save( array $post_data ) {
		// wp_insert_post() attend des données échappées, comme celles d'un formulaire.
		$post_id = isset( $post_data['ID'] )
			? wp_update_post( wp_slash( $post_data ), true )
			: wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return get_post( $post_id );
	}

	/**
	 * Retourne l'utilisateur qui édite actuellement un contenu.
	 *
	 * @param int $post_id Identifiant du contenu.
	 * @return int 0 si personne d'autre ne l'édite.
	 */
	private function get_locking_user_id( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		return (int) wp_check_post_lock( $post_id );
	}
}
