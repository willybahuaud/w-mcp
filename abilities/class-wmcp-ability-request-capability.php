<?php
/**
 * Ability : remontée d'un besoin non couvert.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Ability_Request_Capability
 *
 * Le cœur du projet. Quand l'IA du client bute sur une limite, elle ne
 * contourne pas : elle transmet au prestataire ce que le client cherche à
 * faire. On comprend ainsi les attentes au lieu d'être contourné.
 */
class WMCP_Ability_Request_Capability extends WMCP_Ability {

	/**
	 * Nombre maximal de demandes par utilisateur sur 24 heures.
	 *
	 * @var int
	 */
	const DAILY_LIMIT = 10;

	/**
	 * Longueur maximale d'un champ de texte, en caractères.
	 *
	 * @var int
	 */
	const MAX_FIELD_LENGTH = 2000;

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wmcp/request-capability';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label() {
		return __( 'Request a missing capability', 'w-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description() {
		return 'Forward a need to the site maintainer when something the user wants cannot be done: an ability is missing, was denied, or does not do what they need. '
			. 'Never work around a limit (raw HTML, another tool, a partial hack): explain the limit to the user, offer to send this request, and only send it once they agree. '
			. 'Describe the goal in the user\'s own words, not the technical step that failed. The maintainer reviews every request and replies to the user.';
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
			'intent'          => array(
				'type'        => 'string',
				'description' => 'What the user wants to achieve, in their own words (the goal, not the tool).',
				'minLength'   => 10,
				'maxLength'   => self::MAX_FIELD_LENGTH,
			),
			'blocked_on'      => array(
				'type'        => 'string',
				'description' => 'What is missing or was denied, and what you tried.',
				'minLength'   => 5,
				'maxLength'   => self::MAX_FIELD_LENGTH,
			),
			'related_ability' => array(
				'type'        => 'string',
				'description' => 'Name of the denied ability, or of the closest existing one, if any.',
				'maxLength'   => 190,
			),
			'example'         => array(
				'type'        => 'string',
				'description' => 'A concrete case: the page, product or content involved.',
				'maxLength'   => self::MAX_FIELD_LENGTH,
			),
			'frequency'       => array(
				'type'        => 'string',
				'description' => 'Whether the user needs this once or regularly.',
				'enum'        => array( 'one_off', 'recurring' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_required_properties() {
		return array( 'intent', 'blocked_on' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_permission( $input ) {
		return is_user_logged_in();
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( $input ) {
		$user          = wp_get_current_user();
		$request_store = $this->services->request_store;

		if ( $request_store->count_recent_for_user( $user->ID ) >= self::DAILY_LIMIT ) {
			return new WP_Error( 'wmcp_request_limit', 'The daily limit of requests is reached for this user. Tell the user to contact the site maintainer directly.' );
		}

		$session_id = $this->services->context->get_session_id();
		$denied_ids = $this->services->log_store->get_recent_denied_ids( $session_id, $user->ID );

		$request_id = $request_store->insert(
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'user_id'         => (int) $user->ID,
				'user_login'      => (string) $user->user_login,
				'session_id'      => $session_id,
				'intent'          => sanitize_textarea_field( $input['intent'] ),
				'blocked_on'      => sanitize_textarea_field( $input['blocked_on'] ),
				'related_ability' => sanitize_text_field( $input['related_ability'] ?? '' ),
				'example'         => sanitize_textarea_field( $input['example'] ?? '' ),
				'frequency'       => sanitize_key( $input['frequency'] ?? '' ),
				// Ce que l'IA a tenté juste avant : le prestataire voit le chemin, pas seulement la demande.
				'denied_log_ids'  => implode( ',', $denied_ids ),
			)
		);

		if ( 0 === $request_id ) {
			return new WP_Error( 'wmcp_request_not_saved', 'The request could not be saved. Tell the user to contact the site maintainer directly.' );
		}

		return array(
			'request_id' => $request_id,
			'message'    => 'The request is recorded and will be forwarded to the site maintainer, who will reply to the user. Tell the user it has been sent, with this reference number.',
		);
	}
}
