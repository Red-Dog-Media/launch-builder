<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub releases through the normal WordPress update screens (update notices, the details modal,
 * "Update now", bulk updates and automatic updates). Self-contained: no third-party library, nothing is downloaded
 * except the release zip WordPress installs.
 *
 * Source: GET https://api.github.com/repos/{repo}/releases/latest (repo filterable: launch_builder_update_repo; the
 * plugin header's Update URI points at the same repository, so WordPress.org never offers an update for this slug).
 * The release tag is a v-prefixed SemVer version (v1.2.3); it is compared with the installed version (SemVer 2.0
 * precedence) and the release asset named launch-builder.zip is the package. The release body (the CHANGELOG section,
 * written by .github/workflows/release.yml) is the changelog in the details modal, rendered from a small Markdown
 * subset with everything escaped; an HTML comment in it, <!-- launch-builder: {"requires":"6.5","requires_php":"7.4",
 * "tested":"7.1"} -->, carries the new version's requirements. The description comes from the readme.txt bundled here.
 *
 * Hooks: pre_set_site_transient_update_plugins (adds this plugin to response or no_update, so the auto-updates column
 * works either way), plugins_api (details modal), upgrader_pre_download (private repositories), upgrader_source_selection
 * (the extracted folder must become launch-builder/, or whatever folder the plugin is installed in), auto_update_plugin,
 * a "Check for updates" action link on the Plugins screen (nonce, capability update_plugins) and its notice.
 *
 * Cache: one site transient (launch_builder_update) holds the last answer, its ETag (conditional requests do not count
 * against GitHub's rate limit) and the time of the next check: 6 hours after a success, 15 minutes after a failure and
 * doubling up to 6 hours, or when GitHub's rate limit resets. The stored answer is kept for 7 days, so an error never
 * hides an update that was already found.
 *
 * Private repositories: define( 'LB_GITHUB_TOKEN', '...' ) in wp-config.php (a fine-grained token with read access to
 * the repository's contents). It is read from the constant on every request and never stored in the database, in the
 * cache or in a package URL: the zip is then fetched through the release-asset API with the token, and the signed
 * download URL GitHub redirects to is fetched without it.
 *
 * A git checkout (a .git file or folder next to launch-builder.php) is never replaced: updates are still reported, but
 * without a package and without automatic updates (filter launch_builder_update_allow_vcs to allow it).
 */
final class Updater {

	const REPO      = 'Red-Dog-Media/launch-builder';
	const SLUG      = 'launch-builder';
	const ASSET     = 'launch-builder.zip';
	const CACHE     = 'launch_builder_update';
	const TTL       = 21600;  // 6 hours between checks.
	const KEEP      = 604800; // The cached answer (and its ETag) lives 7 days.
	const MIN_RETRY = 900;    // First retry 15 minutes after an error, then doubling up to TTL.
	const NONCE     = 'lb_check_updates';
	const ACTION    = 'launch_builder_check_updates';
	const CRON      = 'launch_builder_update_refresh';
	const API       = 'https://api.github.com';

	/** @var array|null this request's copy of the cache. */
	private static $state = null;

	/** @var array|null parsed readme.txt. */
	private static $readme = null;

	/** Module hook. */
	public static function boot(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'info' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ __CLASS__, 'pre_download' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'source_selection' ], 10, 4 );
		add_filter( 'auto_update_plugin', [ __CLASS__, 'auto_update' ], 20, 2 );
		add_filter( 'plugin_action_links_' . self::basename(), [ __CLASS__, 'action_links' ] );
		add_filter( 'network_admin_plugin_action_links_' . self::basename(), [ __CLASS__, 'action_links' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'check_now' ] );
		add_action( self::CRON, [ __CLASS__, 'refresh' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
	}

	/** Module hook (plugin deactivation, so also before deletion): forget the cached answer. */
	public static function deactivate(): void {
		delete_site_transient( self::CACHE );
		wp_clear_scheduled_hook( self::CRON );
		self::$state = null;
	}

	/** Module hook: what the updater knows (read from the cache only, never a network call). */
	public static function site_state( array &$state ): void {
		$s                = self::cached();
		$state['updates'] = [
			'installed'    => self::installed_version(),
			'latest'       => $s['release']['version'] ?? null,
			'status'       => $s ? self::status( $s ) : 'unchecked',
			'checked'      => empty( $s['checked'] ) ? null : gmdate( 'c', (int) $s['checked'] ),
			'next_check'   => empty( $s['next'] ) ? null : gmdate( 'c', (int) $s['next'] ),
			'error'        => (string) ( $s['error'] ?? '' ),
			'repo'         => self::repo(),
			'token'        => '' !== self::token(), // Whether LB_GITHUB_TOKEN is defined; never its value.
			'git_checkout' => self::vcs_locked(),
		];
	}

	/* ------------------------------------------------------------------ identity */

	/** 'owner/repo' (filter launch_builder_update_repo; an invalid value falls back to the default). */
	public static function repo(): string {
		$repo = apply_filters( 'launch_builder_update_repo', self::REPO );
		return is_string( $repo ) && preg_match( '#^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $repo ) ? $repo : self::REPO;
	}

	/** The plugin file WordPress knows this plugin by (launch-builder/launch-builder.php). */
	public static function basename(): string {
		return plugin_basename( LB_DIR . 'launch-builder.php' );
	}

	/**
	 * The installed version, read from the plugin header on disk: right after an update, in the same request, the new
	 * files are on disk while LB_VERSION still holds the old version.
	 */
	public static function installed_version(): string {
		$data = get_file_data( LB_DIR . 'launch-builder.php', [ 'Version' => 'Version' ] );
		$v    = self::normalize( (string) ( $data['Version'] ?? '' ) );
		return $v ?? (string) LB_VERSION;
	}

	/** Optional token for a private repository: the LB_GITHUB_TOKEN constant (never stored anywhere). */
	private static function token(): string {
		$token = defined( 'LB_GITHUB_TOKEN' ) ? constant( 'LB_GITHUB_TOKEN' ) : '';
		return is_string( $token ) ? trim( $token ) : '';
	}

	/** True when this copy is a git checkout that updates must not overwrite. */
	public static function vcs_locked(): bool {
		return file_exists( LB_DIR . '.git' ) && ! apply_filters( 'launch_builder_update_allow_vcs', false );
	}

	private static function user_agent(): string {
		return 'LaunchBuilder/' . LB_VERSION . ' WordPress/' . get_bloginfo( 'version' );
	}

	/* ------------------------------------------------------------------ versions */

	/**
	 * The SemVer 2.0 version in a release tag: 'v1.2.3' -> '1.2.3', 'v2.0.0-rc.1+build.5' -> '2.0.0-rc.1' (build
	 * metadata dropped, it has no precedence). Null when the tag is not a version.
	 */
	public static function normalize( string $tag ): ?string {
		$id = '(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)';
		if ( ! preg_match( '/^[vV]?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(' . $id . '(?:\.' . $id . ')*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/', trim( $tag ), $m ) ) {
			return null;
		}
		return $m[1] . '.' . $m[2] . '.' . $m[3] . ( isset( $m[4] ) && '' !== $m[4] ? '-' . $m[4] : '' );
	}

	/**
	 * SemVer 2.0 precedence of two versions or tags.
	 *
	 * @return int|null -1, 0 or 1; null when either is not a version.
	 */
	public static function compare( string $a, string $b ): ?int {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( null === $a || null === $b ) {
			return null;
		}
		list( $a_core, $a_pre ) = array_pad( explode( '-', $a, 2 ), 2, '' );
		list( $b_core, $b_pre ) = array_pad( explode( '-', $b, 2 ), 2, '' );
		$x = array_map( 'intval', explode( '.', $a_core ) );
		$y = array_map( 'intval', explode( '.', $b_core ) );
		for ( $i = 0; $i < 3; $i++ ) {
			if ( $x[ $i ] !== $y[ $i ] ) {
				return $x[ $i ] < $y[ $i ] ? -1 : 1;
			}
		}
		if ( $a_pre === $b_pre ) {
			return 0;
		}
		if ( '' === $a_pre || '' === $b_pre ) {
			return '' === $a_pre ? 1 : -1; // A release outranks its pre-releases.
		}
		$p = explode( '.', $a_pre );
		$q = explode( '.', $b_pre );
		for ( $i = 0, $n = max( count( $p ), count( $q ) ); $i < $n; $i++ ) {
			if ( ! isset( $p[ $i ] ) || ! isset( $q[ $i ] ) ) {
				return isset( $p[ $i ] ) ? 1 : -1; // More identifiers rank higher when the rest are equal.
			}
			$pn = ctype_digit( $p[ $i ] );
			$qn = ctype_digit( $q[ $i ] );
			if ( $pn && $qn ) {
				if ( (int) $p[ $i ] !== (int) $q[ $i ] ) {
					return (int) $p[ $i ] < (int) $q[ $i ] ? -1 : 1;
				}
				continue;
			}
			if ( $pn !== $qn ) {
				return $pn ? -1 : 1; // Numeric identifiers rank lower than alphanumeric ones.
			}
			$c = strcmp( $p[ $i ], $q[ $i ] );
			if ( 0 !== $c ) {
				return $c < 0 ? -1 : 1;
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------ GitHub */

	/**
	 * Turn a /releases/latest answer into what the updater keeps, or an error. Only a launch-builder.zip asset of this
	 * repository's release is accepted as the package.
	 *
	 * @param array|string $data decoded JSON or the raw body.
	 * @param string       $repo 'owner/repo' (default: repo()).
	 * @return array|\WP_Error { version, tag, name, body, published, url, package, asset_api, requires, requires_php, tested, prerelease }
	 */
	public static function parse_release( $data, string $repo = '' ) {
		$repo = '' === $repo ? self::repo() : $repo;
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) || ! isset( $data['tag_name'] ) || ! is_string( $data['tag_name'] ) ) {
			return new \WP_Error( 'lb_update_bad_release', __( 'GitHub sent a release without a tag.', 'launch-builder' ) );
		}
		if ( ! empty( $data['draft'] ) ) {
			return new \WP_Error( 'lb_update_bad_release', __( 'The latest release is still a draft.', 'launch-builder' ) );
		}
		$version = self::normalize( $data['tag_name'] );
		if ( null === $version ) {
			/* translators: %s: release tag */
			return new \WP_Error( 'lb_update_bad_tag', sprintf( __( 'The latest release tag "%s" is not a version number like v1.2.3.', 'launch-builder' ), sanitize_text_field( substr( $data['tag_name'], 0, 40 ) ) ) );
		}
		$download = 'https://github.com/' . $repo . '/releases/download/';
		$api      = self::API . '/repos/' . $repo . '/releases/assets/';
		$asset    = null;
		foreach ( ( is_array( $data['assets'] ?? null ) ? $data['assets'] : [] ) as $a ) {
			if ( ! is_array( $a ) || self::ASSET !== ( $a['name'] ?? '' ) || ( isset( $a['state'] ) && 'uploaded' !== $a['state'] ) ) {
				continue;
			}
			$url     = is_string( $a['browser_download_url'] ?? null ) ? $a['browser_download_url'] : '';
			$api_url = is_string( $a['url'] ?? null ) ? $a['url'] : '';
			if ( 0 === stripos( $url, $download ) && '/' . self::ASSET === substr( $url, -strlen( '/' . self::ASSET ) ) && preg_match( '#^' . preg_quote( $api, '#' ) . '\d+$#i', $api_url ) && ! preg_match( '/[\s"\'<>]/', $url ) ) {
				$asset = [ $url, $api_url ];
				break;
			}
		}
		if ( ! $asset ) {
			/* translators: 1: release tag, 2: file name */
			return new \WP_Error( 'lb_update_no_asset', sprintf( __( 'Release %1$s has no %2$s attached yet.', 'launch-builder' ), $data['tag_name'], self::ASSET ) );
		}
		$body = is_string( $data['body'] ?? null ) ? substr( str_replace( [ "\r\n", "\r" ], "\n", $data['body'] ), 0, 20000 ) : '';
		$meta = [];
		if ( preg_match( '/<!--\s*launch-builder:\s*(\{.*?\})\s*-->/s', $body, $m ) ) {
			$meta = json_decode( $m[1], true );
			$meta = is_array( $meta ) ? $meta : [];
		}
		$headers = self::readme()['headers'];
		$req     = static function ( string $key, string $fallback ) use ( $meta ): string {
			$v = isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ? trim( (string) $meta[ $key ] ) : '';
			return preg_match( '/^\d+(\.\d+){0,2}$/', $v ) ? $v : $fallback;
		};
		$html_url  = is_string( $data['html_url'] ?? null ) ? $data['html_url'] : '';
		$published = is_string( $data['published_at'] ?? null ) ? $data['published_at'] : '';
		return [
			'version'      => $version,
			'tag'          => $data['tag_name'],
			'name'         => is_string( $data['name'] ?? null ) ? sanitize_text_field( $data['name'] ) : '',
			'body'         => trim( (string) preg_replace( '/<!--.*?-->/s', '', $body ) ),
			'published'    => preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $published ) ? $published : '',
			'url'          => 0 === stripos( $html_url, 'https://github.com/' . $repo . '/releases/' ) && ! preg_match( '/[\s"\'<>]/', $html_url ) ? $html_url : 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode( $data['tag_name'] ),
			'package'      => $asset[0],
			'asset_api'    => $asset[1],
			'requires'     => $req( 'requires', (string) ( $headers['requires at least'] ?? '' ) ),
			'requires_php' => $req( 'requires_php', (string) ( $headers['requires php'] ?? '' ) ),
			'tested'       => $req( 'tested', (string) ( $headers['tested up to'] ?? '' ) ),
			'prerelease'   => ! empty( $data['prerelease'] ),
		];
	}

	/** The cached state ([] when nothing is cached for the current repository). */
	public static function cached(): array {
		if ( null === self::$state ) {
			$s           = get_site_transient( self::CACHE );
			self::$state = is_array( $s ) && ( $s['repo'] ?? '' ) === self::repo() ? $s : [];
		}
		return self::$state;
	}

	/** Forget this request's copy of the cache (tests, or after the transient was changed elsewhere). */
	public static function reset(): void {
		self::$state  = null;
		self::$readme = null;
	}

	/**
	 * The cached state, refreshed from GitHub when the next check is due (or now with $force).
	 *
	 * @return array { repo, release: array|null, etag, checked, next, error, code: ''|none|network|http|rate_limited|bad_release, fails }
	 */
	public static function fetch( bool $force = false ): array {
		$state = self::cached();
		$now   = time();
		if ( ! $force && $state && $now < (int) ( $state['next'] ?? 0 ) ) {
			return $state;
		}
		$repo    = self::repo();
		$token   = self::token();
		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ( ! empty( $state['etag'] ) && ! empty( $state['release'] ) ) {
			$headers['If-None-Match'] = $state['etag'];
		}
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_safe_remote_get(
			self::API . '/repos/' . $repo . '/releases/latest',
			[
				'timeout'    => wp_doing_cron() ? 15 : 8,
				'headers'    => $headers,
				'user-agent' => self::user_agent(),
			]
		);
		$next = [
			'repo'    => $repo,
			'release' => $state['release'] ?? null,
			'etag'    => (string) ( $state['etag'] ?? '' ),
			'checked' => $now,
			'next'    => $now + self::TTL,
			'error'   => '',
			'code'    => '',
			'fails'   => 0,
		];
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			$next = self::failed( $next, $state, 'network', sprintf( __( 'GitHub could not be reached (%s).', 'launch-builder' ), $response->get_error_message() ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 304 === $code && $next['release'] ) {
				$next['etag'] = (string) $state['etag']; // Unchanged; the conditional request did not count against the rate limit.
			} elseif ( 200 === $code ) {
				$release = self::parse_release( wp_remote_retrieve_body( $response ), $repo );
				if ( is_wp_error( $release ) ) {
					$next = self::failed( $next, $state, 'bad_release', $release->get_error_message() );
				} else {
					$next['release'] = $release;
					$next['etag']    = substr( (string) wp_remote_retrieve_header( $response, 'etag' ), 0, 200 );
				}
			} elseif ( 404 === $code ) { // No published release yet (or a private repository without a usable token).
				$next['release'] = null;
				$next['etag']    = '';
				$next['code']    = 'none';
				$next['error']   = '' === $token ? __( 'No published release was found on GitHub.', 'launch-builder' ) : __( 'No published release was found on GitHub (or LB_GITHUB_TOKEN cannot read the repository).', 'launch-builder' );
			} elseif ( in_array( $code, [ 403, 429 ], true ) && ( '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) || '' !== (string) wp_remote_retrieve_header( $response, 'retry-after' ) ) ) {
				$retry        = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$reset        = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
				$wait         = $retry > 0 ? $retry : ( $reset > $now ? $reset - $now : self::MIN_RETRY );
				$next         = self::failed( $next, $state, 'rate_limited', __( 'GitHub rate limit reached.', 'launch-builder' ) );
				$next['next'] = $now + min( self::TTL, max( self::MIN_RETRY, $wait ) );
			} elseif ( 401 === $code ) {
				$next = self::failed( $next, $state, 'http', __( 'GitHub refused the token in LB_GITHUB_TOKEN (HTTP 401).', 'launch-builder' ) );
			} else {
				/* translators: %d: HTTP status code */
				$next = self::failed( $next, $state, 'http', sprintf( __( 'GitHub answered HTTP %d.', 'launch-builder' ), $code ) );
			}
		}
		self::$state = $next;
		set_site_transient( self::CACHE, $next, self::KEEP );
		return $next;
	}

	/** A failed check: keep what was known, back off (15 min, doubling up to TTL). */
	private static function failed( array $next, array $state, string $code, string $message ): array {
		$fails         = (int) ( $state['fails'] ?? 0 ) + 1;
		$next['fails'] = $fails;
		$next['code']  = $code;
		$next['error'] = $message;
		$next['next']  = $next['checked'] + (int) min( self::TTL, self::MIN_RETRY * ( 2 ** min( 10, $fails - 1 ) ) );
		return $next;
	}

	/**
	 * What a state means for this site.
	 *
	 * @return string available|current|none|error
	 */
	public static function status( array $state ): string {
		if ( '' !== (string) ( $state['error'] ?? '' ) && 'none' !== ( $state['code'] ?? '' ) ) {
			return 'error';
		}
		$release = $state['release'] ?? null;
		if ( ! is_array( $release ) ) {
			return 'none';
		}
		return 1 === self::compare( $release['version'], self::installed_version() ) ? 'available' : 'current';
	}

	/** The plugin icon shown on the Plugins and Updates screens (the LaunchLocal mark). */
	private static function icons(): array {
		$svg = LB_URL . 'assets/logo/launchlocal-icon.svg';
		return [ 'svg' => $svg, 'default' => $svg ];
	}

	/** The zip WordPress installs for a release ('' for a git checkout). */
	public static function package( array $release ): string {
		if ( self::vcs_locked() ) {
			return '';
		}
		return '' !== self::token() ? (string) $release['asset_api'] : (string) $release['package'];
	}

	/** An update_plugins entry: the release when given, else "no update" for the installed version. */
	public static function item( ?array $release ): \stdClass {
		$headers = self::readme()['headers'];
		return (object) [
			'id'           => 'https://github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $release ? $release['version'] : self::installed_version(),
			'url'          => 'https://github.com/' . self::repo(),
			'package'      => $release ? self::package( $release ) : '',
			'icons'        => self::icons(),
			'banners'      => [],
			'banners_rtl'  => [],
			'requires'     => $release ? $release['requires'] : (string) ( $headers['requires at least'] ?? '' ),
			'tested'       => $release ? $release['tested'] : (string) ( $headers['tested up to'] ?? '' ),
			'requires_php' => $release ? $release['requires_php'] : (string) ( $headers['requires php'] ?? '' ),
		];
	}

	/* ------------------------------------------------------------------ WordPress hooks */

	/**
	 * Filter pre_set_site_transient_update_plugins: put this plugin in response (newer release) or no_update.
	 *
	 * @param mixed $transient the update_plugins value being saved.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$basename = self::basename();
		if ( self::may_wait() ) {
			$release = self::fetch()['release'] ?? null;
		} else {
			$state   = self::cached();
			$release = $state['release'] ?? null; // A page view is never held up by GitHub: use the last answer and refresh it from WP-Cron.
			if ( ! $state || time() >= (int) ( $state['next'] ?? 0 ) ) {
				self::queue_refresh();
			}
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}
		unset( $transient->response[ $basename ], $transient->no_update[ $basename ] );
		if ( is_array( $release ) && 1 === self::compare( $release['version'], self::installed_version() ) ) {
			$transient->response[ $basename ] = self::item( $release );
		} else {
			$transient->no_update[ $basename ] = self::item( null );
		}
		return $transient;
	}

	/**
	 * May this request wait for GitHub? Yes for WP-Cron, WP-CLI, front-end and AJAX requests, the Updates screens (the
	 * person asked for a check there, including "Check again") and sites without WP-Cron. An ordinary wp-admin page
	 * view says no: it would sit through the request (up to 8 seconds) before the screen appears.
	 */
	private static function may_wait(): bool {
		if ( ! is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return true;
		}
		$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		return in_array( $page, [ 'update-core.php', 'update.php' ], true ) || ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to wait.
	}

	/** Ask WP-Cron to refresh the cached answer as soon as it can (once; nothing happens when one is already waiting). */
	private static function queue_refresh(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time(), self::CRON );
		}
	}

	/** WP-Cron event: ask GitHub when the next check is due, then let WordPress rebuild its list of plugin updates. */
	public static function refresh(): void {
		self::fetch();
		$updates = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) ) {
			set_site_transient( 'update_plugins', $updates ); // Runs inject() with the new answer.
		}
	}

	/**
	 * Filter plugins_api: the details modal ("View details", "View version x details").
	 *
	 * @param mixed  $result default false.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   request object (slug).
	 * @return mixed
	 */
	public static function info( $result, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release   = self::fetch()['release'] ?? null;
		$installed = self::installed_version();
		$newer     = is_array( $release ) && 1 === self::compare( $release['version'], $installed );
		$readme    = self::readme();
		$h         = $readme['headers'];
		$sections  = [];
		foreach ( [ 'description', 'installation', 'faq', 'other_notes' ] as $key ) {
			if ( '' !== ( $readme['sections'][ $key ] ?? '' ) ) {
				$sections[ $key ] = self::render( $readme['sections'][ $key ] );
			}
		}
		if ( ! isset( $sections['description'] ) && '' !== $readme['short'] ) {
			$sections['description'] = '<p>' . esc_html( $readme['short'] ) . '</p>';
		}
		$notes = $newer && '' !== $release['body']
			? '<h4>' . esc_html( $release['version'] ) . '</h4>' . self::render( $release['body'] )
			: self::render( $readme['sections']['changelog'] ?? '' );
		$sections['changelog'] = $notes . '<p><a href="' . esc_url( 'https://github.com/' . self::repo() . '/releases' ) . '">' . esc_html__( 'All releases on GitHub', 'launch-builder' ) . '</a></p>';

		$plugin = get_file_data( LB_DIR . 'launch-builder.php', [ 'Name' => 'Plugin Name', 'Author' => 'Author', 'AuthorURI' => 'Author URI' ] );
		$author = (string) ( $plugin['Author'] ?? '' );
		$uri    = (string) ( $plugin['AuthorURI'] ?? '' );
		return (object) [
			'name'           => esc_html( '' !== (string) ( $plugin['Name'] ?? '' ) ? $plugin['Name'] : 'Launch Builder' ),
			'slug'           => self::SLUG,
			'version'        => $newer ? $release['version'] : $installed,
			'author'         => '' !== $uri ? '<a href="' . esc_url( $uri ) . '">' . esc_html( $author ) . '</a>' : esc_html( $author ),
			'author_profile' => esc_url_raw( $uri ),
			'homepage'       => 'https://github.com/' . self::repo(),
			'requires'       => $newer ? $release['requires'] : (string) ( $h['requires at least'] ?? '' ),
			'tested'         => $newer ? $release['tested'] : (string) ( $h['tested up to'] ?? '' ),
			'requires_php'   => $newer ? $release['requires_php'] : (string) ( $h['requires php'] ?? '' ),
			'last_updated'   => is_array( $release ) && '' !== $release['published'] ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $release['published'] ) ) . ' GMT' : '',
			'download_link'  => $newer ? self::package( $release ) : '',
			'sections'       => $sections,
			'banners'        => [],
			'icons'          => self::icons(),
			'external'       => true, // No "WordPress.org Plugin Page" link.
		];
	}

	/**
	 * Filter upgrader_pre_download: with LB_GITHUB_TOKEN the package is the release-asset API URL, which needs the token;
	 * GitHub answers with a redirect to a signed URL that must be fetched WITHOUT it.
	 *
	 * @param mixed  $reply      false, or what another filter returned.
	 * @param string $package    package URL.
	 * @param mixed  $upgrader   WP_Upgrader.
	 * @param array  $hook_extra unused.
	 * @return mixed path of the downloaded zip, WP_Error, or $reply untouched.
	 */
	public static function pre_download( $reply, $package = '', $upgrader = null, $hook_extra = [] ) {
		$release = self::cached()['release'] ?? null;
		if ( false !== $reply || '' === self::token() || ! is_array( $release ) || ! is_string( $package ) || $package !== $release['asset_api'] ) {
			return $reply;
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( 'downloading_package', esc_html( $package ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$r = wp_safe_remote_get(
			$package,
			[
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => [
					'Accept'               => 'application/octet-stream',
					'Authorization'        => 'Bearer ' . self::token(),
					'X-GitHub-Api-Version' => '2022-11-28',
				],
				'user-agent'  => self::user_agent(),
			]
		);
		if ( is_wp_error( $r ) ) {
			return new \WP_Error( 'download_failed', __( 'Download failed.', 'launch-builder' ), $r->get_error_message() );
		}
		$code     = (int) wp_remote_retrieve_response_code( $r );
		$location = (string) wp_remote_retrieve_header( $r, 'location' );
		if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) && 0 === stripos( $location, 'https://' ) ) {
			$file = download_url( $location, 300 );
			return is_wp_error( $file ) ? new \WP_Error( 'download_failed', __( 'Download failed.', 'launch-builder' ), $file->get_error_message() ) : $file;
		}
		if ( 200 === $code && '' !== wp_remote_retrieve_body( $r ) ) {
			$file = wp_tempnam( self::ASSET );
			if ( $file && false !== file_put_contents( $file, wp_remote_retrieve_body( $r ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return $file;
			}
		}
		/* translators: %d: HTTP status code */
		return new \WP_Error( 'download_failed', __( 'Download failed.', 'launch-builder' ), sprintf( __( 'GitHub answered HTTP %d.', 'launch-builder' ), $code ) );
	}

	/**
	 * Filter upgrader_source_selection: when this plugin is updated, the extracted folder must be the folder the plugin
	 * is installed in (launch-builder/), or WordPress would install it under another name and deactivate it. A fresh
	 * install of this plugin from a zip with another folder name (GitHub's "Source code" zip) is renamed to launch-builder/.
	 *
	 * @param mixed  $source        extracted folder (or WP_Error).
	 * @param string $remote_source the folder the package was extracted into.
	 * @param mixed  $upgrader      WP_Upgrader.
	 * @param array  $hook_extra    { plugin?, type?, action? }
	 * @return mixed folder to install, or WP_Error.
	 */
	public static function source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = [] ) {
		global $wp_filesystem;
		if ( ! is_string( $source ) || ! is_object( $wp_filesystem ) ) {
			return $source;
		}
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : [];
		$basename   = self::basename();
		$ours       = isset( $hook_extra['plugin'] ) && $basename === $hook_extra['plugin'];
		$source     = trailingslashit( $source );
		$remote     = trailingslashit( (string) $remote_source );
		$main       = $source . 'launch-builder.php';
		if ( ! $ours ) {
			$install = 'install' === ( $hook_extra['action'] ?? '' ) && 'plugin' === ( $hook_extra['type'] ?? '' );
			if ( ! $install || $source === $remote || ! $wp_filesystem->exists( $main ) || ! self::is_ours( (string) $wp_filesystem->get_contents( $main ) ) ) {
				return $source;
			}
		}
		$folder = $ours ? dirname( $basename ) : self::SLUG;
		if ( '.' === $folder || '' === $folder ) {
			return $source;
		}
		if ( $source === $remote ) {
			/* translators: %s: folder name */
			return new \WP_Error( 'lb_package_layout', sprintf( __( 'The update package must contain one %s folder.', 'launch-builder' ), self::SLUG . '/' ) );
		}
		if ( ! $wp_filesystem->exists( $main ) ) {
			return new \WP_Error( 'lb_package_invalid', __( 'The update package does not contain launch-builder.php.', 'launch-builder' ) );
		}
		if ( basename( untrailingslashit( $source ) ) === $folder ) {
			return $source;
		}
		$target = $remote . $folder . '/';
		if ( $wp_filesystem->exists( $target ) || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
			/* translators: %s: folder name */
			return new \WP_Error( 'lb_package_rename', sprintf( __( 'Could not rename the package folder to %s.', 'launch-builder' ), $folder . '/' ) );
		}
		return $target;
	}

	/** Is this main plugin file this plugin (its Update URI is this repository)? */
	private static function is_ours( string $php ): bool {
		return (bool) preg_match( '#^[\s/*\#@]*Update URI:\s*https://github\.com/' . preg_quote( self::repo(), '#' ) . '/?\s*$#mi', $php );
	}

	/**
	 * Filter auto_update_plugin: never auto-update a git checkout.
	 *
	 * @param mixed $update whether to update.
	 * @param mixed $item   update_plugins entry.
	 * @return mixed
	 */
	public static function auto_update( $update, $item = null ) {
		if ( is_object( $item ) && isset( $item->plugin ) && self::basename() === $item->plugin && self::vcs_locked() ) {
			return false;
		}
		return $update;
	}

	/**
	 * "Check for updates" link on the Plugins screen.
	 *
	 * @param mixed $links action links.
	 * @return array
	 */
	public static function action_links( $links ): array {
		$links = (array) $links;
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url = wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'network' => is_network_admin() ? 1 : false ], admin_url( 'admin-post.php' ) ), self::NONCE );
		$links['lb_check_updates'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'launch-builder' ) . '</a>';
		return $links;
	}

	/** admin-post handler of the link: ask GitHub now, then back to the Plugins screen with a notice. */
	public static function check_now(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update plugins for this site.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );
		$status = self::check();
		$back   = empty( $_GET['network'] ) ? admin_url( 'plugins.php' ) : network_admin_url( 'plugins.php' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		wp_safe_redirect( add_query_arg( 'lb_update_check', $status, $back ) );
		exit;
	}

	/**
	 * Ask GitHub now (ignoring the cache and any backoff) and refresh WordPress's list of plugin updates with the answer.
	 *
	 * @return string available|current|none|error
	 */
	public static function check(): string {
		$state   = self::fetch( true );
		$updates = get_site_transient( 'update_plugins' );
		set_site_transient( 'update_plugins', is_object( $updates ) ? $updates : new \stdClass() ); // Runs inject() with the new answer.
		return self::status( $state );
	}

	/** The result of "Check for updates", on the Plugins screen only. */
	public static function notice(): void {
		if ( empty( $_GET['lb_update_check'] ) || ! current_user_can( 'update_plugins' ) || ! function_exists( 'get_current_screen' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) ) {
			return;
		}
		$state = self::cached(); // The message comes from the stored answer, never from the URL.
		$type  = 'info';
		switch ( self::status( $state ) ) {
			case 'available':
				/* translators: 1: new version, 2: installed version */
				$msg = sprintf( __( 'Launch Builder %1$s is available (this site has %2$s).', 'launch-builder' ), $state['release']['version'], self::installed_version() );
				break;
			case 'current':
				$type = 'success';
				/* translators: %s: installed version */
				$msg = sprintf( __( 'Launch Builder is up to date (version %s).', 'launch-builder' ), self::installed_version() );
				break;
			case 'none':
				$msg = '' !== (string) ( $state['error'] ?? '' ) ? (string) $state['error'] : __( 'No published release was found on GitHub.', 'launch-builder' );
				break;
			default:
				$type = 'error';
				/* translators: %s: what went wrong */
				$msg = sprintf( __( 'Launch Builder could not check for updates: %s', 'launch-builder' ), (string) ( $state['error'] ?? '' ) );
				if ( ! empty( $state['next'] ) ) {
					/* translators: %s: time */
					$msg .= ' ' . sprintf( __( 'WordPress will try again after %s.', 'launch-builder' ), wp_date( (string) get_option( 'time_format' ), (int) $state['next'] ) );
				}
		}
		if ( self::vcs_locked() ) {
			$msg .= ' ' . __( 'This copy is a git checkout, so WordPress will not install updates over it.', 'launch-builder' );
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	/* ------------------------------------------------------------------ readme.txt and Markdown */

	/** The bundled readme.txt, parsed (see parse_readme). */
	public static function readme(): array {
		if ( null === self::$readme ) {
			$file         = LB_DIR . 'readme.txt';
			self::$readme = self::parse_readme( is_readable( $file ) ? (string) file_get_contents( $file ) : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return self::$readme;
	}

	/**
	 * Parse a WordPress readme.txt.
	 *
	 * @return array { name, headers: lower-cased name => value, short, sections: key => raw text }
	 */
	public static function parse_readme( string $raw ): array {
		$lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $raw ) );
		$out   = [
			'name'     => '',
			'headers'  => [],
			'short'    => '',
			'sections' => [],
		];
		$i     = 0;
		$n     = count( $lines );
		if ( preg_match( '/^===\s*(.+?)\s*===$/', trim( $lines[0] ) ) ) {
			$out['name'] = trim( trim( $lines[0] ), '= ' );
			$i           = 1;
		}
		for ( ; $i < $n; $i++ ) { // Header lines up to the first blank line after them.
			$line = trim( $lines[ $i ] );
			if ( '' === $line ) {
				if ( $out['headers'] ) {
					break;
				}
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z][A-Za-z ]*):\s*(.*)$/', $line, $m ) ) {
				break;
			}
			$out['headers'][ strtolower( trim( $m[1] ) ) ] = trim( $m[2] );
		}
		$short   = [];
		$section = null;
		$buf     = [];
		for ( ; $i < $n; $i++ ) {
			if ( preg_match( '/^==\s*([^=].*?)\s*==$/', trim( $lines[ $i ] ), $m ) ) {
				if ( null !== $section ) {
					$out['sections'][ $section ] = trim( implode( "\n", $buf ) );
				}
				$section = self::section_key( $m[1] );
				$buf     = [];
			} elseif ( null === $section ) {
				$short[] = trim( $lines[ $i ] );
			} else {
				$buf[] = $lines[ $i ];
			}
		}
		if ( null !== $section ) {
			$out['sections'][ $section ] = trim( implode( "\n", $buf ) );
		}
		$out['short'] = trim( implode( ' ', array_filter( $short, 'strlen' ) ) );
		return $out;
	}

	private static function section_key( string $title ): string {
		$key = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $title ) ), '_' );
		return 'frequently_asked_questions' === $key ? 'faq' : $key;
	}

	/**
	 * A small, safe Markdown subset to HTML for the details modal: headings (#, ##, ###, readme "= Title ="),
	 * paragraphs, bulleted and numbered lists, **bold**, *italic*, `code` and [links](https://...). Every character of
	 * the input is escaped first; only http(s) and mailto links survive; HTML comments are dropped. WordPress runs the
	 * result through wp_kses again before printing it.
	 */
	public static function render( string $text ): string {
		$text  = (string) preg_replace( '/<!--.*?-->/s', '', str_replace( [ "\r\n", "\r" ], "\n", $text ) );
		$html  = '';
		$para  = [];
		$list  = '';
		$items = [];
		$flush = static function () use ( &$html, &$para, &$list, &$items ) {
			if ( $para ) {
				$html .= '<p>' . self::inline( implode( ' ', $para ) ) . "</p>\n";
				$para  = [];
			}
			if ( $list ) {
				$html .= '<' . $list . '><li>' . implode( "</li>\n<li>", array_map( [ __CLASS__, 'inline' ], $items ) ) . '</li></' . $list . ">\n";
				$list  = '';
				$items = [];
			}
		};
		foreach ( explode( "\n", $text ) as $line ) {
			$t = trim( $line );
			if ( '' === $t || preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', $t ) ) {
				$flush();
			} elseif ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*$/', $t, $m ) || preg_match( '/^(=)\s*(.+?)\s*=$/', $t, $m ) ) {
				$flush();
				$level = '=' === $m[1] || strlen( $m[1] ) >= 3 ? 4 : 3;
				$html .= '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . ">\n";
			} elseif ( preg_match( '/^([-*+]|\d+[.)])\s+(.+)$/', $t, $m ) ) {
				$type = ctype_digit( substr( $m[1], 0, 1 ) ) ? 'ol' : 'ul';
				if ( $para || $type !== $list ) {
					$flush();
				}
				$list    = $type;
				$items[] = $m[2];
			} elseif ( $list && preg_match( '/^\s{2,}\S/', $line ) ) {
				$items[ count( $items ) - 1 ] .= ' ' . $t; // Continuation of a list item.
			} else {
				if ( $list ) {
					$flush();
				}
				$para[] = '>' === $t[0] ? ltrim( substr( $t, 1 ) ) : $t;
			}
		}
		$flush();
		return $html;
	}

	/** Inline Markdown on one escaped line (see render()). */
	public static function inline( string $s ): string {
		$s     = esc_html( $s );
		$codes = [];
		$s     = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$codes[] = '<code>' . $m[1] . '</code>';
				return "\x01" . ( count( $codes ) - 1 ) . "\x01";
			},
			$s
		);
		$s     = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(((?:[^()\s]|\([^()\s]*\))+)\)/', // One level of parentheses inside the URL.
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES ), [ 'http', 'https', 'mailto' ] );
				return '' === $url ? $m[1] : '<a href="' . $url . '">' . $m[1] . '</a>';
			},
			$s
		);
		$s     = (string) preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $s );
		$s     = (string) preg_replace( '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $s );
		$s     = (string) preg_replace( '/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/', '<em>$1</em>', $s );
		return (string) preg_replace_callback(
			"/\x01(\d+)\x01/",
			static function ( $m ) use ( $codes ) {
				return $codes[ (int) $m[1] ] ?? '';
			},
			$s
		);
	}
}
