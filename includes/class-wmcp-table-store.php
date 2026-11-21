<?php
/**
 * Base des tables collectées par WBoard.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Table_Store
 *
 * Le journal et les demandes se collectent de la même façon : le board lit ce
 * qui suit un curseur, et son curseur suivant vaut accusé de réception. Rien
 * n'est marqué « collecté » tant que le board n'a pas confirmé l'avoir reçu.
 *
 * Tables propres au plugin : pas d'équivalent WP_Query, donc requêtes préparées
 * directes, sans cache d'objet (données lues une seule fois par collecte).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * -- Le nom de table vient d'une constante du plugin, jamais d'une entrée utilisateur.
 */
abstract class WMCP_Table_Store {

	/**
	 * Nombre maximal de lignes par page de collecte.
	 *
	 * @var int
	 */
	const MAX_PAGE_SIZE = 500;

	/**
	 * Retourne le nom de la table, sans préfixe.
	 *
	 * @return string L'une des constantes WMCP_Schema::TABLE_*.
	 */
	abstract protected function get_table();

	/**
	 * Retourne le nom complet de la table.
	 *
	 * @return string
	 */
	protected function table_name() {
		return WMCP_Schema::table_name( $this->get_table() );
	}

	/**
	 * Insère une ligne.
	 *
	 * @param array<string,mixed> $row Colonnes et valeurs.
	 * @return int Identifiant de la ligne, 0 en cas d'échec.
	 */
	public function insert( array $row ) {
		global $wpdb;

		$inserted = $wpdb->insert( $this->table_name(), $row );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Retourne les lignes qui suivent un curseur.
	 *
	 * @param int $after_id Dernier identifiant déjà reçu par le board.
	 * @param int $limit    Nombre maximal de lignes.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_after( $after_id, $limit ) {
		global $wpdb;

		$limit = max( 1, min( self::MAX_PAGE_SIZE, (int) $limit ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id > %d ORDER BY id ASC LIMIT %d",
				(int) $after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Marque comme collectées les lignes jusqu'à un curseur.
	 *
	 * @param int $up_to_id Dernier identifiant dont le board accuse réception.
	 * @return void
	 */
	public function mark_collected_up_to( $up_to_id ) {
		global $wpdb;

		if ( $up_to_id < 1 ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name()} SET collected_at = %s WHERE id <= %d AND collected_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				(int) $up_to_id
			)
		);
	}

	/**
	 * Retourne le dernier identifiant de la table.
	 *
	 * @return int
	 */
	public function get_last_id() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$this->table_name()}" );
	}

	/**
	 * Retourne la date de la dernière collecte.
	 *
	 * @return string Date UTC, chaîne vide si rien n'a été collecté.
	 */
	public function get_last_collected_at() {
		global $wpdb;

		return (string) $wpdb->get_var( "SELECT MAX(collected_at) FROM {$this->table_name()}" );
	}

	/**
	 * Supprime les lignes anciennes.
	 *
	 * Une ligne collectée part après le premier délai. Une ligne jamais collectée
	 * part après le second, plus long : on protège la base du client si le board
	 * ne passe plus.
	 *
	 * @param int $collected_days   Âge maximal d'une ligne collectée, en jours.
	 * @param int $uncollected_days Âge maximal d'une ligne non collectée, en jours.
	 * @return int Nombre de lignes supprimées.
	 */
	public function purge( $collected_days, $uncollected_days ) {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE ( collected_at IS NOT NULL AND created_at < %s ) OR created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - $collected_days * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - $uncollected_days * DAY_IN_SECONDS )
			)
		);
	}
}
