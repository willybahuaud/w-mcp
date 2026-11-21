<?php
/**
 * Lecture et écriture des modèles de l'éditeur de site.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Template_Service
 *
 * Un modèle vient d'un fichier du thème tant qu'il n'est pas personnalisé ;
 * le modifier crée une copie en base qui masque le fichier. On délègue cette
 * mécanique aux contrôleurs REST du core plutôt que de la réécrire.
 */
class WMCP_Template_Service {

	const TYPE_TEMPLATE = 'wp_template';
	const TYPE_PART     = 'wp_template_part';

	/**
	 * Routes REST du core, par type de modèle.
	 *
	 * @var array<string,string>
	 */
	const REST_ROUTES = array(
		self::TYPE_TEMPLATE => '/wp/v2/templates/',
		self::TYPE_PART     => '/wp/v2/template-parts/',
	);

	/**
	 * Retourne les types de modèles gérés.
	 *
	 * @return string[]
	 */
	public static function get_types() {
		return array_keys( self::REST_ROUTES );
	}

	/**
	 * Indique si l'utilisateur courant peut gérer les modèles.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Retrouve un modèle.
	 *
	 * @param string $template_type L'une des constantes TYPE_*.
	 * @param string $template_id   Identifiant du modèle, de la forme `theme//slug`.
	 * @return WP_Block_Template|WP_Error
	 */
	public static function find( $template_type, $template_id ) {
		$template = get_block_template( (string) $template_id, $template_type );
		if ( ! $template instanceof WP_Block_Template ) {
			return new WP_Error( 'wmcp_template_not_found', 'No template with this ID. Use "wmcp/templates-query" to list them.' );
		}

		return $template;
	}

	/**
	 * Présente un modèle.
	 *
	 * @param WP_Block_Template $template     Modèle.
	 * @param bool              $with_content Vrai pour inclure le balisage de blocs.
	 * @return array<string,mixed>
	 */
	public static function present( WP_Block_Template $template, $with_content = false ) {
		$presented = array(
			'id'             => $template->id,
			'type'           => $template->type,
			'slug'           => $template->slug,
			'title'          => (string) $template->title,
			'description'    => (string) $template->description,
			// `theme` : fichier du thème intact. `custom` : version modifiée en base.
			'source'         => $template->source,
			'has_theme_file' => (bool) $template->has_theme_file,
			'area'           => (string) ( $template->area ?? '' ),
		);

		if ( $with_content ) {
			$presented['content'] = (string) $template->content;
		}

		return $presented;
	}

	/**
	 * Remplace le contenu d'un modèle.
	 *
	 * @param WP_Block_Template $template Modèle à modifier.
	 * @param string            $content  Nouveau balisage de blocs.
	 * @return WP_Block_Template|WP_Error
	 */
	public static function update_content( WP_Block_Template $template, $content ) {
		$request = new WP_REST_Request( 'POST', self::REST_ROUTES[ $template->type ] . $template->id );
		$request->set_param( 'content', $content );

		return self::dispatch( $request, $template );
	}

	/**
	 * Revient à la version du thème en mettant la personnalisation à la corbeille.
	 *
	 * Pas de suppression forcée : rien n'est supprimé définitivement par le MCP,
	 * et la personnalisation reste restaurable depuis l'administration.
	 *
	 * @param WP_Block_Template $template Modèle personnalisé.
	 * @return WP_Block_Template|WP_Error
	 */
	public static function reset( WP_Block_Template $template ) {
		$request = new WP_REST_Request( 'DELETE', self::REST_ROUTES[ $template->type ] . $template->id );
		$request->set_param( 'force', false );

		return self::dispatch( $request, $template );
	}

	/**
	 * Exécute une requête REST interne et relit le modèle.
	 *
	 * @param WP_REST_Request   $request  Requête interne.
	 * @param WP_Block_Template $template Modèle concerné.
	 * @return WP_Block_Template|WP_Error
	 */
	private static function dispatch( WP_REST_Request $request, WP_Block_Template $template ) {
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return self::find( $template->type, $template->id );
	}
}
