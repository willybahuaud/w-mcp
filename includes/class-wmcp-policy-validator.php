<?php
/**
 * Validation de la matrice de droits.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Policy_Validator
 *
 * Contrôle la structure d'une matrice reçue de WBoard. Une matrice invalide est
 * rejetée en bloc : mieux vaut garder l'ancienne qu'appliquer des droits à moitié lus.
 */
class WMCP_Policy_Validator {

	/**
	 * Erreurs relevées pendant la validation.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Valide une matrice.
	 *
	 * @param mixed $policy Matrice décodée depuis le JSON.
	 * @return string[] Liste des erreurs, vide si la matrice est valide.
	 */
	public function validate( $policy ) {
		$this->errors = array();

		if ( ! is_array( $policy ) ) {
			return array( 'policy: must be an object.' );
		}

		$this->validate_header( $policy );

		$profiles = $policy['profiles'] ?? null;
		$users    = $policy['users'] ?? null;

		if ( ! is_array( $profiles ) ) {
			$this->errors[] = 'profiles: must be an object.';
			$profiles       = array();
		}
		if ( ! is_array( $users ) ) {
			$this->errors[] = 'users: must be an object.';
			$users          = array();
		}

		foreach ( $profiles as $profile_name => $profile ) {
			$this->validate_profile( (string) $profile_name, $profile );
		}
		foreach ( $users as $user_id => $user ) {
			$this->validate_user( (string) $user_id, $user, array_keys( $profiles ) );
		}

		return $this->errors;
	}

	/**
	 * Valide les champs de premier niveau.
	 *
	 * @param array<string,mixed> $policy Matrice.
	 * @return void
	 */
	private function validate_header( array $policy ) {
		if ( ! isset( $policy['version'] ) || ! is_int( $policy['version'] ) || $policy['version'] < 1 ) {
			$this->errors[] = 'version: must be a positive integer.';
		}
		if ( isset( $policy['paused'] ) && ! is_bool( $policy['paused'] ) ) {
			$this->errors[] = 'paused: must be a boolean.';
		}
	}

	/**
	 * Valide un profil.
	 *
	 * @param string $profile_name Nom du profil.
	 * @param mixed  $profile      Définition du profil.
	 * @return void
	 */
	private function validate_profile( $profile_name, $profile ) {
		$path = 'profiles.' . $profile_name;

		if ( ! is_array( $profile ) ) {
			$this->errors[] = $path . ': must be an object.';
			return;
		}

		$levels = $profile['levels'] ?? null;
		if ( ! is_array( $levels ) || array_diff( $levels, WMCP_Ability_Level::all() ) ) {
			$this->errors[] = $path . '.levels: must be a list among ' . implode( ', ', WMCP_Ability_Level::all() ) . '.';
		}
		if ( isset( $profile['can_publish'] ) && ! is_bool( $profile['can_publish'] ) ) {
			$this->errors[] = $path . '.can_publish: must be a boolean.';
		}

		$this->validate_patterns( $path . '.allow', $profile['allow'] ?? array() );
		$this->validate_patterns( $path . '.deny', $profile['deny'] ?? array() );
	}

	/**
	 * Valide l'entrée d'un utilisateur.
	 *
	 * @param string   $user_id       Identifiant de l'utilisateur WordPress.
	 * @param mixed    $user          Définition des droits de l'utilisateur.
	 * @param string[] $profile_names Noms des profils déclarés.
	 * @return void
	 */
	private function validate_user( $user_id, $user, array $profile_names ) {
		$path = 'users.' . $user_id;

		if ( ! ctype_digit( $user_id ) || 0 === (int) $user_id ) {
			$this->errors[] = $path . ': key must be a WordPress user ID.';
		}
		if ( ! is_array( $user ) ) {
			$this->errors[] = $path . ': must be an object.';
			return;
		}

		$profile_name = $user['profile'] ?? null;
		if ( ! is_string( $profile_name ) || ! in_array( $profile_name, $profile_names, true ) ) {
			$this->errors[] = $path . '.profile: must reference a declared profile.';
		}

		$this->validate_patterns( $path . '.allow', $user['allow'] ?? array() );
		$this->validate_patterns( $path . '.deny', $user['deny'] ?? array() );
	}

	/**
	 * Valide une liste de motifs.
	 *
	 * @param string $path     Chemin du champ, pour le message d'erreur.
	 * @param mixed  $patterns Liste de motifs.
	 * @return void
	 */
	private function validate_patterns( $path, $patterns ) {
		if ( ! is_array( $patterns ) ) {
			$this->errors[] = $path . ': must be a list.';
			return;
		}

		foreach ( $patterns as $pattern ) {
			if ( ! WMCP_Name_Matcher::is_valid_pattern( $pattern ) ) {
				$this->errors[] = $path . ': invalid pattern "' . ( is_scalar( $pattern ) ? $pattern : gettype( $pattern ) ) . '".';
			}
		}
	}
}
