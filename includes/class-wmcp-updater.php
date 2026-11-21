<?php
/**
 * Mises à jour du plugin depuis les GitHub Releases du dépôt public.
 *
 * Même mécanique que wboard-connector : on branche le système natif de mise à
 * jour de WordPress sur l'API GitHub Releases, sans token (le dépôt public suffit).
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Updater
 *
 * Propose la dernière release GitHub comme mise à jour du plugin, dans l'écran
 * natif de WordPress. Le code source vit dans un dépôt privé ; seul le dépôt
 * public reçoit le code distribuable et les releases que cette classe consulte.
 */
class WMCP_Updater {

	/**
	 * Propriétaire du dépôt GitHub public.
	 *
	 * @var string
	 */
	const GITHUB_OWNER = 'willybahuaud';

	/**
	 * Nom du dépôt GitHub public.
	 *
	 * @var string
	 */
	const GITHUB_REPO = 'w-mcp';

	/**
	 * URL de base de l'API GitHub.
	 *
	 * @var string
	 */
	const GITHUB_API_URL = 'https://api.github.com';

	/**
	 * Durée du cache de la release, en secondes (6 heures).
	 *
	 * L'API GitHub non authentifiée est limitée à 60 requêtes par heure et par
	 * IP : sur un hébergement mutualisé, beaucoup de sites partagent ce budget.
	 * Ajustable par le filtre `wmcp_update_cache_duration`.
	 *
	 * @var int
	 */
	const CACHE_DURATION = 21600;

	/**
	 * Clé du transient de cache de la release.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'wmcp_github_release';

	/**
	 * Slug du plugin (dossier et nom court).
	 *
	 * @var string
	 */
	private $plugin_slug = 'w-mcp';

	/**
	 * Basename du plugin (dossier/fichier.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Release déjà lue pendant cette exécution.
	 *
	 * @var object|false|null
	 */
	private $release = null;

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->plugin_basename = plugin_basename( WMCP_FILE );
	}

	/**
	 * Branche les filtres de mise à jour.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
	}

	/**
	 * Déclare une mise à jour disponible si la release dépasse la version installée.
	 *
	 * @param mixed $transient Transient des mises à jour de plugins.
	 * @return mixed Transient, éventuellement complété.
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( false === $release ) {
			return $transient;
		}

		$latest       = $this->parse_version( $release->tag_name );
		$download_url = $this->get_download_url( $release );

		if ( '' === $download_url || version_compare( $latest, WMCP_VERSION, '>' ) !== true ) {
			return $transient;
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'slug'        => $this->plugin_slug,
			'plugin'      => $this->plugin_basename,
			'new_version' => $latest,
			'url'         => $release->html_url,
			'package'     => $download_url,
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires'    => '7.1',
		);

		return $transient;
	}

	/**
	 * Alimente la fiche « Voir les détails » du plugin.
	 *
	 * @param false|object|array $result Résultat par défaut.
	 * @param string             $action Action demandée.
	 * @param object             $args   Arguments de la requête.
	 * @return false|object Fiche du plugin, ou le résultat inchangé.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( false === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'W-MCP',
			'slug'          => $this->plugin_slug,
			'version'       => $this->parse_version( $release->tag_name ),
			'author'        => '<a href="https://wabeo.fr">Willy Bahuaud</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'requires'      => '7.1',
			'tested'        => '',
			'last_updated'  => $release->published_at ?? '',
			'sections'      => array(
				'changelog' => $this->format_changelog( $release->body ?? '' ),
			),
			'download_link' => $this->get_download_url( $release ),
		);
	}

	/**
	 * Corrige le dossier extrait du ZIP GitHub, nommé « repo-tag » au lieu du slug.
	 *
	 * @param string      $source        Dossier extrait.
	 * @param string      $remote_source Dossier distant.
	 * @param WP_Upgrader $upgrader      Instance de l'upgrader.
	 * @param array       $hook_extra    Données de l'upgrade.
	 * @return string|WP_Error Dossier renommé, ou erreur.
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		if ( basename( untrailingslashit( $source ) ) === $this->plugin_slug ) {
			return $source;
		}

		global $wp_filesystem;
		$target = trailingslashit( trailingslashit( $remote_source ) . $this->plugin_slug );

		if ( $wp_filesystem->move( $source, $target ) ) {
			return $target;
		}

		return new WP_Error( 'wmcp_rename_failed', __( 'Could not rename the plugin folder after download.', 'w-mcp' ) );
	}

	/**
	 * Lit la dernière release du dépôt public, en cache.
	 *
	 * @return object|false La release, ou false si indisponible.
	 */
	private function get_release() {
		if ( null !== $this->release ) {
			return $this->release;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			$this->release = $cached;
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( '%s/repos/%s/%s/releases/latest', self::GITHUB_API_URL, self::GITHUB_OWNER, self::GITHUB_REPO ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'W-MCP/' . WMCP_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// On ne garde pas l'échec en cache longtemps : juste de quoi éviter une rafale.
			$this->release = false;
			set_transient( self::CACHE_KEY, false, MINUTE_IN_SECONDS * 15 );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $data ) || ! isset( $data->tag_name ) ) {
			$this->release = false;
			return false;
		}

		/**
		 * Filtre la durée de cache de la release (secondes).
		 *
		 * Permet d'accélérer la prise en compte d'une nouvelle release, ou de la
		 * ralentir pour ménager la limite de l'API GitHub sur une IP mutualisée.
		 *
		 * @param int $duration Durée par défaut (6 heures).
		 */
		$duration = (int) apply_filters( 'wmcp_update_cache_duration', self::CACHE_DURATION );

		set_transient( self::CACHE_KEY, $data, max( MINUTE_IN_SECONDS, $duration ) );
		$this->release = $data;

		return $data;
	}

	/**
	 * Numéro de version depuis un tag, sans le préfixe « v ».
	 *
	 * @param string $tag_name Tag de la release.
	 * @return string Version.
	 */
	private function parse_version( $tag_name ) {
		return ltrim( (string) $tag_name, 'vV' );
	}

	/**
	 * URL du ZIP à installer : un asset .zip de la release de préférence, sinon
	 * l'archive générée par GitHub.
	 *
	 * @param object $release Release GitHub.
	 * @return string URL, ou chaîne vide.
	 */
	private function get_download_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && '.zip' === substr( (string) $asset->name, -4 ) ) {
					return (string) $asset->browser_download_url;
				}
			}
		}

		return isset( $release->zipball_url ) ? (string) $release->zipball_url : '';
	}

	/**
	 * Met en forme le changelog depuis le corps Markdown de la release.
	 *
	 * @param string $body Corps de la release.
	 * @return string HTML échappé.
	 */
	private function format_changelog( $body ) {
		if ( '' === trim( (string) $body ) ) {
			return '<p>' . esc_html__( 'No release notes available.', 'w-mcp' ) . '</p>';
		}

		$html = nl2br( esc_html( (string) $body ) );
		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );

		return (string) preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );
	}
}
