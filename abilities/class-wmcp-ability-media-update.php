<?php
/**
 * Ability : modification des informations d'un média.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Media_Update
 *
 * Texte seulement. L'envoi de fichiers est hors périmètre en v1 : télécharger
 * une URL fournie par un modèle ouvre trop de risques pour un premier jet.
 */
class WMCP_Ability_Media_Update extends WMCP_Ability {

	/**
	 * Correspondance entre les champs de l'ability et ceux du média.
	 *
	 * @var array<string,string>
	 */
	const FIELD_MAP = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/media-update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Update media details', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Updates the title, caption, description or alternative text of a file in the media library. Only the fields you pass are changed. It does not upload or replace the file itself.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_level() {
		return WMCP_Ability_Level::WRITE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_properties() {
		return array(
			'id'          => array(
				'type'        => 'integer',
				'description' => 'ID of the media.',
			),
			'title'       => array(
				'type'        => 'string',
				'description' => 'New title.',
			),
			'caption'     => array(
				'type'        => 'string',
				'description' => 'New caption.',
			),
			'description' => array(
				'type'        => 'string',
				'description' => 'New description.',
			),
			'alt_text'    => array(
				'type'        => 'string',
				'description' => 'New alternative text, read by screen readers.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'id' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return $this->can_on_post( 'edit_post', $input['id'] ?? 0, array( 'attachment' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		// Un média est en ligne dès qu'il existe : le modifier change tout de suite
		// ce que voient les visiteurs. Même règle que pour un contenu publié.
		if ( ! $this->services->can_publish() ) {
			return new WP_Error( 'wmcp_live_content', WMCP_Messages::action_denied( 'This media is live and this user is not allowed to publish, so it cannot be changed directly.' ) );
		}

		$attachment = $this->find_post( $input['id'], array( 'attachment' ) );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$post_data = array();
		foreach ( self::FIELD_MAP as $field => $post_field ) {
			if ( isset( $input[ $field ] ) ) {
				$post_data[ $post_field ] = sanitize_textarea_field( $input[ $field ] );
			}
		}

		if ( $post_data ) {
			$post_data['ID'] = $attachment->ID;
			$updated         = wp_update_post( wp_slash( $post_data ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $attachment->ID, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt_text'] ) ) );
		}

		return array( 'item' => WMCP_Presenter::media( get_post( $attachment->ID ) ) );
	}
}
