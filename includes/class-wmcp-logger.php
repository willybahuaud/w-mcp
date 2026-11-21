<?php
/**
 * Journal des appels MCP.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Logger
 *
 * Suit chaque exécution d'ability venue du MCP et écrit le journal.
 *
 * Aucun hook du core ne couvre tous les cas à lui seul :
 *  - `wp_ability_invoked` ouvre l'appel ;
 *  - `wp_ability_execute_result` le clôt, en succès ou en erreur ;
 *  - `wp_ability_validate_input` signale une entrée invalide : l'adapter valide
 *    avant d'exécuter, donc l'appel n'est alors jamais ouvert ;
 *  - un appel resté ouvert à la fin de la requête (arrêt inattendu) se clôt au `shutdown` ;
 *  - un refus de la matrice est signalé directement par le garde.
 *
 * Les lectures réussies ne laissent qu'un compteur : le détail n'a pas de valeur
 * et ferait grossir la base du client.
 */
class WMCP_Logger {

	const STATUS_OK      = 'ok';
	const STATUS_ERROR   = 'error';
	const STATUS_DENIED  = 'denied';
	const STATUS_INVALID = 'invalid';

	/**
	 * Taille maximale des paramètres conservés, en octets.
	 *
	 * @var int
	 */
	const MAX_INPUT_BYTES = 8192;

	/**
	 * Nombre maximal de lignes détaillées par utilisateur et par heure.
	 *
	 * Une boucle d'appels refusés ne doit pas faire grossir la base du client.
	 * Au-delà, les lignes sont comptées mais plus écrites.
	 *
	 * @var int
	 */
	const MAX_ROWS_PER_HOUR = 600;

	/**
	 * Option qui garde la trace des lignes non écrites.
	 *
	 * @var string
	 */
	const OPTION_HEALTH = 'wmcp_log_health';

	/**
	 * Préfixe des abilities de l'adapter, qui ne font que relayer vers une autre.
	 *
	 * @var string
	 */
	const RELAY_PREFIX = 'mcp-adapter/';

	/**
	 * Contexte de la requête.
	 *
	 * @var WMCP_Context
	 */
	private $context;

	/**
	 * Stockage de la matrice.
	 *
	 * @var WMCP_Policy_Store
	 */
	private $policy_store;

	/**
	 * Accès aux tables du journal.
	 *
	 * @var WMCP_Log_Store
	 */
	private $log_store;

	/**
	 * Relevé de l'objet modifié.
	 *
	 * @var WMCP_Snapshot
	 */
	private $snapshot;

	/**
	 * Appels ouverts, le plus récent en dernier.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $open_calls = array();

	/**
	 * Refus et entrées invalides déjà journalisés dans la requête, indexés par
	 * statut, ability et empreinte.
	 *
	 * L'adapter contrôle une première fois avant d'exécuter, puis `execute()`
	 * recommence : on ne garde qu'une ligne par appel.
	 *
	 * @var array<string,bool>
	 */
	private $recorded_once = array();

	/**
	 * Constructeur.
	 *
	 * @param WMCP_Context      $context      Contexte de la requête.
	 * @param WMCP_Policy_Store $policy_store Stockage de la matrice.
	 * @param WMCP_Log_Store    $log_store    Accès aux tables du journal.
	 * @param WMCP_Snapshot     $snapshot     Relevé de l'objet modifié.
	 */
	public function __construct( WMCP_Context $context, WMCP_Policy_Store $policy_store, WMCP_Log_Store $log_store, WMCP_Snapshot $snapshot ) {
		$this->context      = $context;
		$this->policy_store = $policy_store;
		$this->log_store    = $log_store;
		$this->snapshot     = $snapshot;
	}

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ability_invoked', array( $this, 'open_call' ), 10, 3 );
		add_filter( 'wp_ability_validate_input', array( $this, 'record_invalid_input' ), 999, 3 );
		// Priorité tardive : on enregistre le résultat tel que le client le recevra.
		add_filter( 'wp_ability_execute_result', array( $this, 'close_call' ), 999, 4 );
		add_action( 'shutdown', array( $this, 'close_unfinished_calls' ) );
	}

	/**
	 * Ouvre le suivi d'un appel.
	 *
	 * @param string     $ability_name Nom de l'ability.
	 * @param mixed      $input        Données d'entrée brutes.
	 * @param WP_Ability $ability      Ability.
	 * @return void
	 */
	public function open_call( $ability_name, $input, $ability ) {
		if ( ! $this->is_tracked( $ability_name ) ) {
			return;
		}

		$this->open_calls[] = array(
			'ability'    => $ability_name,
			'level'      => WMCP_Guard::get_level( $ability ),
			'input'      => $input,
			'started_at' => microtime( true ),
		);
		$this->snapshot->start();
	}

	/**
	 * Clôt le suivi d'un appel et l'enregistre.
	 *
	 * @param mixed  $result       Résultat de l'ability, éventuellement une WP_Error.
	 * @param string $ability_name Nom de l'ability.
	 * @return mixed Le résultat, inchangé.
	 */
	public function close_call( $result, $ability_name ) {
		$call = end( $this->open_calls );
		if ( false === $call || $call['ability'] !== $ability_name ) {
			return $result;
		}
		array_pop( $this->open_calls );

		$is_error       = is_wp_error( $result );
		$object_columns = $this->snapshot->finish();

		if ( ! $is_error && WMCP_Ability_Level::READ === $call['level'] ) {
			$this->log_store->increment_read_count( get_current_user_id(), $ability_name );
			return $result;
		}

		$this->write_row(
			$call,
			$is_error ? self::STATUS_ERROR : self::STATUS_OK,
			$is_error ? (string) $result->get_error_code() : '',
			$object_columns
		);

		return $result;
	}

	/**
	 * Enregistre les appels ouverts qui n'ont jamais abouti (arrêt inattendu).
	 *
	 * @return void
	 */
	public function close_unfinished_calls() {
		foreach ( $this->open_calls as $call ) {
			$this->write_row( $call, self::STATUS_INVALID, 'stopped_before_execution' );
		}
		$this->open_calls = array();
	}

	/**
	 * Enregistre un refus de la matrice.
	 *
	 * @param WP_Ability $ability Ability refusée.
	 * @param mixed      $input   Données d'entrée.
	 * @param string     $reason  Motif du refus (constante de WMCP_Decision).
	 * @return void
	 */
	public function record_denied( WP_Ability $ability, $input, $reason ) {
		// Un outil relais de l'adapter hérite du refus de l'ability visée : une seule ligne suffit.
		if ( $this->is_tracked( $ability->get_name() ) ) {
			$this->write_once( $ability, $input, self::STATUS_DENIED, $reason );
		}
	}

	/**
	 * Enregistre un appel dont les paramètres ne respectent pas le schéma.
	 *
	 * @param true|WP_Error $is_valid     Résultat de la validation.
	 * @param mixed         $input        Données d'entrée.
	 * @param string        $ability_name Nom de l'ability.
	 * @return true|WP_Error Le résultat, inchangé.
	 */
	public function record_invalid_input( $is_valid, $input, $ability_name ) {
		$ability = is_wp_error( $is_valid ) && $this->is_tracked( $ability_name ) ? wp_get_ability( $ability_name ) : null;

		if ( $ability instanceof WP_Ability ) {
			$this->write_once( $ability, $input, self::STATUS_INVALID, (string) $is_valid->get_error_code() );
		}

		return $is_valid;
	}

	/**
	 * Écrit une ligne pour un appel qui n'a pas été exécuté, une seule fois par requête.
	 *
	 * @param WP_Ability $ability Ability visée.
	 * @param mixed      $input   Données d'entrée.
	 * @param string     $status  STATUS_DENIED ou STATUS_INVALID.
	 * @param string     $reason  Motif.
	 * @return void
	 */
	private function write_once( WP_Ability $ability, $input, $status, $reason ) {
		$once_key = $status . '|' . $ability->get_name() . '|' . $this->hash_input( $input );
		if ( isset( $this->recorded_once[ $once_key ] ) ) {
			return;
		}
		$this->recorded_once[ $once_key ] = true;

		$this->write_row(
			array(
				'ability'    => $ability->get_name(),
				'level'      => WMCP_Guard::get_level( $ability ),
				'input'      => $input,
				'started_at' => microtime( true ),
			),
			$status,
			$reason
		);
	}

	/**
	 * Indique si un appel doit être suivi.
	 *
	 * @param string $ability_name Nom de l'ability.
	 * @return bool
	 */
	private function is_tracked( $ability_name ) {
		// Les outils de l'adapter relaient vers l'ability visée : c'est elle qu'on journalise.
		return $this->context->is_mcp() && 0 !== strpos( $ability_name, self::RELAY_PREFIX );
	}

	/**
	 * Écrit une ligne du journal.
	 *
	 * @param array<string,mixed> $call   Appel suivi (`ability`, `level`, `input`, `started_at`).
	 * @param string              $status L'une des constantes STATUS_*.
	 * @param string              $reason Code d'erreur ou motif du refus.
	 * @param array<string,mixed> $object_columns Colonnes relevées par WMCP_Snapshot.
	 * @return void
	 */
	private function write_row( array $call, $status, $reason, array $object_columns = array() ) {
		$user = wp_get_current_user();

		if ( $this->log_store->count_recent_for_user( (int) $user->ID, HOUR_IN_SECONDS ) >= self::MAX_ROWS_PER_HOUR ) {
			$this->note_unwritten_row( 'dropped' );
			return;
		}

		$inserted_id = $this->log_store->insert(
			array_merge(
				array(
					'created_at'      => gmdate( 'Y-m-d H:i:s' ),
					'user_id'         => (int) $user->ID,
					'user_login'      => mb_scrub( (string) $user->user_login, 'UTF-8' ),
					'client'          => $this->context->get_client_label(),
					'session_id'      => $this->context->get_session_id(),
					'ability'         => $call['ability'],
					'level'           => $call['level'],
					'status'          => $status,
					'reason'          => mb_strcut( mb_scrub( (string) $reason, 'UTF-8' ), 0, 190, 'UTF-8' ),
					'duration_ms'     => (int) round( ( microtime( true ) - $call['started_at'] ) * 1000 ),
					'input'           => mb_scrub( $this->encode_input( $call['input'] ), 'UTF-8' ),
					'input_hash'      => $this->hash_input( $call['input'] ),
					'before_snapshot' => '',
					'policy_version'  => $this->policy_store->get_resolver()->get_version(),
				),
				$object_columns
			)
		);

		// Une ligne refusée par la base ne doit pas passer inaperçue : sans cela,
		// un utilisateur pourrait rendre son journal muet sans que personne le voie.
		if ( 0 === $inserted_id ) {
			$this->note_unwritten_row( 'failed' );
		}
	}

	/**
	 * Compte une ligne qui n'a pas pu être écrite. WBoard lit ce compteur dans `/status`.
	 *
	 * @param string $kind `failed` (refusée par la base) ou `dropped` (plafond horaire atteint).
	 * @return void
	 */
	private function note_unwritten_row( $kind ) {
		$health          = self::get_health();
		$health[ $kind ] = $health[ $kind ] + 1;
		$health['last']  = gmdate( 'Y-m-d H:i:s' );

		update_option( self::OPTION_HEALTH, $health, false );
	}

	/**
	 * Retourne le compte des lignes non écrites.
	 *
	 * @return array{failed:int,dropped:int,last:string}
	 */
	public static function get_health() {
		$health = get_option( self::OPTION_HEALTH, array() );

		return array(
			'failed'  => (int) ( $health['failed'] ?? 0 ),
			'dropped' => (int) ( $health['dropped'] ?? 0 ),
			'last'    => (string) ( $health['last'] ?? '' ),
		);
	}

	/**
	 * Prépare les paramètres pour le journal : masqués, puis bornés en taille.
	 *
	 * @param mixed $input Données d'entrée.
	 * @return string JSON.
	 */
	private function encode_input( $input ) {
		/**
		 * Filtre les fragments de clés dont la valeur est masquée dans le journal.
		 *
		 * @param string[] $sensitive_keys Fragments de clés, insensibles à la casse.
		 */
		$sensitive_keys = (array) apply_filters( 'wmcp_log_redacted_keys', WMCP_Redactor::DEFAULT_SENSITIVE_KEYS );
		$redactor       = new WMCP_Redactor( array_filter( $sensitive_keys, 'is_string' ) );

		return $redactor->shorten( (string) wp_json_encode( $redactor->redact( $input ) ), self::MAX_INPUT_BYTES );
	}

	/**
	 * Calcule l'empreinte des paramètres complets.
	 *
	 * Elle permet de prouver que deux appels sont identiques sans conserver leur contenu.
	 *
	 * @param mixed $input Données d'entrée.
	 * @return string SHA-256.
	 */
	private function hash_input( $input ) {
		return hash( 'sha256', (string) wp_json_encode( $input ) );
	}
}
