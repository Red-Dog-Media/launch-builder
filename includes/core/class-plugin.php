<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap and module registry. Patterns are convention-based: patterns/<name>.php is registered automatically
 * (theme-style file header).
 *
 * Pattern headers: Title, Slug, Description, Categories, Keywords, plus
 *  Inserter: no                     hide from the editor inserter (headers and design pieces are placed by the Design hub)
 *  Header-Meta: {json}              post meta applied to the kadence_header post
 *  Requires: kadence-blocks-pro     only available when that plugin is active (Plugin::REQUIREMENTS)
 *  Piece: hero-service              the design piece it is (Design_Hub::PIECES)
 *  Look: auto-shop                  the look it belongs to (Design_Hub::LOOKS)
 * The parsed headers are cached in a transient keyed by LB_VERSION, the newest file time, the file names and which
 * required plugins are active; patterns are registered with filePath, so a file is only read when its content is used.
 *
 * Modules: Plugin::MODULES lists the classes that make up the plugin, in order. A module may implement any of these static
 * methods and the registry calls it at the right time (nothing else has to be edited to add one):
 *  boot()                       register hooks (called once, at plugin load, in MODULES order)
 *  define_tools()               register tools with Abilities::add() (Abilities::define)
 *  site_state( array &$state )  add keys to the site-state tool result (Abilities::site_state)
 *  playbook( array &$pb )       add guidance to get-playbook (Content::playbook)
 *  audit_checks()               return [code => [scope, callable, batched?]] checks for Audit::run
 *  activate()                   plugin activated (Plugin::activate, via register_activation_hook), e.g. Migrate::rerun()
 *  deactivate()                 plugin deactivated (Plugin::deactivate), e.g. park what must not outlive the plugin
 *
 * A listed class whose file does not exist is skipped.
 */
final class Plugin {

	/**
	 * Ordered module list. The order is the boot order (it matters only for hooks on the same priority: Abilities, Mcp,
	 * Company, Entries and Admin keep the order they always booted in) and the order tools are listed in.
	 */
	const MODULES = [
		Abilities::class,   // tool registry + options/snapshots/REST proxy/media tools
		Mcp::class,         // /mcp endpoint and REST wrappers
		Patterns::class,    // pattern registry, slots, build pipeline, pattern tools
		Nav::class,         // menus + block header
		Company::class,     // Business Info + tokens on render
		Design::class,      // brand, buttons, menus, page style, sidebar, block defaults
		Design_Hub::class,  // design pieces in three looks, written as native Kadence objects
		Query_Lists::class, // "children of this page" and "top level" for Kadence Query Loops
		Content::class,     // markdown pages, layouts, playbook
		Entries::class,     // services / areas post types and their fields
		Duplicate::class,   // safe copies of posts, pages, entries (toggleable)
		Media::class,       // media hygiene: GPS/XMP stripping, sideload normalisation, media audit (off by default)
		Avatars::class,     // local avatars (toggleable module): profile photos from the Media Library, no Gravatar
		Admin::class,       // wp-admin screens + pattern preview
		Design_Hub_Admin::class, // the Design hub screen
		Dashboard::class,   // the dashboard, the Modules page, the admin stylesheet and the WordPress Dashboard widget
		Login_Links::class, // expiring sign-in links (module, off by default)
		Login_Links_Guard::class, // its always-on part (old-plugin notice, switch, CLI); boots after Login_Links
		Branding::class,    // white-label branding: sign-in screen, Site Owner role, admin look (module, off by default)
		Roles::class,       // page roles
		Tokens::class,      // dynamic text engine
		Cache::class,       // coalesced page-cache purge (flushed before REST responses and redirects)
		Migrate::class,     // versioned data migrations
		Audit::class,       // site audit, sync-site, preview-urls
		Compat::class,      // third-party compatibility (stub)
		Meta_Box_Sync::class, // services and areas as Meta Box post types, their field groups and the Business Info settings page (toggleable)
		Builder::class,     // build-site: a six-section brief in, a site out
		Updater::class,     // updates from GitHub releases
		Inspect::class,     // inspect-page (loopback GET + parse), role body class
		Modules::class,     // module switches (get-modules / set-modules)
		Permalinks::class,  // custom URIs, permastructures, URL lookup table, URL tools
		Redirects::class,   // 301s: Rank Math Redirections or our own table
		Launch::class,      // go-live, detokenize, convert-page-to-entry, prepare to remove
	];

	/** Companion plugins a pattern can require (its Requires: header) => plugin file. */
	const REQUIREMENTS = [
		'kadence-blocks-pro' => 'kadence-blocks-pro/kadence-blocks-pro.php',
		'kadence-pro'        => 'kadence-pro/kadence-pro.php',
	];

	/** Pattern file headers: row key => header name (read with get_file_data). */
	const PATTERN_HEADERS = [
		'title'       => 'Title',
		'slug'        => 'Slug',
		'description' => 'Description',
		'categories'  => 'Categories',
		'keywords'    => 'Keywords',
		'inserter'    => 'Inserter',
		'header_meta' => 'Header-Meta',
		'requires'    => 'Requires',
		'piece'       => 'Piece',
		'look'        => 'Look',
	];

	/** Transient with the parsed pattern headers ({key, rows}). */
	const HEADER_CACHE = 'lb_pattern_headers';

	/** @var array<string,array> pattern slug => extra file-header data. */
	private static $extra = [];

	/** @var array<int,array> patterns skipped because a required plugin is missing. */
	private static $unavailable = [];

	private static $registered = false;

	/** @var array<string,array>|null per-request pattern_index(). */
	private static $index = null;

	public static function pattern_extra( string $slug ): array {
		return self::$extra[ 'launch-builder/' . $slug ] ?? [];
	}

	/**
	 * Pattern categories: slug (registered as launch-builder-<slug>) => label. 'launch-builder' (All) is registered too.
	 * tools/gen reads the keys of this array (def `cat` must be one of them).
	 */
	public static function categories(): array {
		return [
			'headers'  => __( 'Headers', 'launch-builder' ),
			'heroes'   => __( 'Heroes', 'launch-builder' ),
			'lists'    => __( 'Service and area lists', 'launch-builder' ),
			'cta'      => __( 'Calls to action', 'launch-builder' ),
			'footers'  => __( 'Footers', 'launch-builder' ),
			'sidebars' => __( 'Sidebars', 'launch-builder' ),
			'sections' => __( 'Page sections', 'launch-builder' ),
		];
	}

	/** One pattern's parsed file headers (see pattern_index()), or []. */
	public static function pattern_meta( string $slug ): array {
		return self::pattern_index()[ $slug ] ?? [];
	}

	/**
	 * Every pattern file's headers, parsed: slug => {slug, name, file, title, description, categories[], keywords[],
	 * inserter, header_meta, requires, piece, look, available}. Cached in a
	 * transient keyed by LB_VERSION, the newest file time, the file names and the active required plugins, so a normal
	 * request reads no pattern file at all.
	 */
	public static function pattern_index(): array {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$files = glob( LB_DIR . 'patterns/*.php' ) ?: [];
		sort( $files );
		$stamp = 0;
		$names = [];
		foreach ( $files as $file ) {
			$stamp = max( $stamp, file_exists( $file ) ? (int) filemtime( $file ) : 0 );
			$names[] = basename( $file );
		}
		$reqs = [];
		foreach ( array_keys( self::REQUIREMENTS ) as $req ) {
			$reqs[] = $req . '=' . ( self::requirement_met( $req ) ? 1 : 0 );
		}
		$key = md5( LB_VERSION . '|' . $stamp . '|' . implode( ',', $names ) . '|' . implode( ',', $reqs ) );
		$cached = get_transient( self::HEADER_CACHE );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_array( $cached['rows'] ?? null ) ) {
			$rows = $cached['rows'];
		} else {
			$rows = [];
			foreach ( $files as $file ) {
				$row = self::read_pattern_header( $file );
				if ( $row ) {
					$rows[ $row['slug'] ] = $row;
				}
			}
			set_transient( self::HEADER_CACHE, [ 'key' => $key, 'rows' => $rows ], WEEK_IN_SECONDS );
		}
		foreach ( $rows as $slug => $row ) {
			$rows[ $slug ]['available'] = self::requirement_met( (string) $row['requires'] );
		}
		self::$index = $rows;
		return $rows;
	}

	/** Parse one pattern file's header block; null when it is not a launch-builder/* pattern. */
	private static function read_pattern_header( string $file ): ?array {
		$m = get_file_data( $file, self::PATTERN_HEADERS );
		if ( '' === $m['title'] || 0 !== strpos( (string) $m['slug'], 'launch-builder/' ) ) {
			return null;
		}
		$list = static function ( $v ): array {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ), 'strlen' ) );
		};
		$slug = sanitize_key( substr( $m['slug'], 15 ) );
		if ( '' === $slug ) {
			return null;
		}
		return [
			'slug'        => $slug,
			'name'        => 'launch-builder/' . $slug,
			'file'        => basename( $file ),
			'title'       => (string) $m['title'],
			'description' => (string) $m['description'],
			'categories'  => $list( '' !== $m['categories'] ? $m['categories'] : 'launch-builder' ),
			'keywords'    => $list( $m['keywords'] ),
			'inserter'    => 'no' !== strtolower( trim( (string) $m['inserter'] ) ),
			'header_meta' => json_decode( (string) $m['header_meta'], true ) ?: [],
			'requires'    => strtolower( trim( (string) $m['requires'] ) ),
			'piece'       => sanitize_key( (string) $m['piece'] ),
			'look'        => sanitize_key( (string) $m['look'] ),
		];
	}

	public static function unavailable(): array {
		self::register_patterns();
		return self::$unavailable;
	}

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'maybe_register_patterns' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_patterns' ], 5 ); // The block editor reads patterns over REST (any rest_url_prefix).
		foreach ( self::modules( 'boot' ) as $module ) {
			$module::boot();
		}
	}

	/** Activation hook (launch-builder.php): every module's activate(). */
	public static function activate(): void {
		if ( ! Modules::first_activation() ) {
			Avatars::keep_gravatar_step(); // A site that already had Local avatars on keeps its Gravatar behaviour (see Avatars::DEFAULTS).
		}
		foreach ( self::modules( 'activate' ) as $module ) {
			$module::activate();
		}
	}

	/** Deactivation hook (launch-builder.php): every module's deactivate(). */
	public static function deactivate(): void {
		foreach ( self::modules( 'deactivate' ) as $module ) {
			$module::deactivate();
		}
	}

	/**
	 * Modules that implement a method, in MODULES order (all modules when $method is empty). Callers that are modules
	 * themselves skip their own class when they iterate (for example Content::playbook builds the base playbook).
	 * A module switched off on the Modules screen (Modules::enabled) is never returned, so it boots and registers nothing.
	 *
	 * @return string[] fully qualified class names
	 */
	public static function modules( string $method = '' ): array {
		$out = [];
		foreach ( (array) apply_filters( 'launch_builder_modules', self::MODULES ) as $class ) {
			// Disabled modules are skipped everywhere (see Modules).
			if ( is_string( $class ) && class_exists( $class ) && ( '' === $method || method_exists( $class, $method ) ) && Modules::enabled( $class ) ) {
				$out[] = $class;
			}
		}
		return $out;
	}

	public static function kadence_blocks_active(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( 'kadence/rowlayout' );
	}

	public static function kadence_theme_active(): bool {
		return 'kadence' === get_template();
	}

	/** Is a required companion plugin active (on this site, or network-wide)? */
	public static function requirement_met( string $req ): bool {
		$req = strtolower( trim( $req ) );
		if ( '' === $req ) {
			return true;
		}
		if ( ! isset( self::REQUIREMENTS[ $req ] ) ) {
			return false;
		}
		$file = self::REQUIREMENTS[ $req ];
		if ( in_array( $file, (array) get_option( 'active_plugins', [] ), true ) ) {
			return true;
		}
		return is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )[ $file ] );
	}

	/**
	 * Patterns are only needed over REST (the editor), in WP-CLI and on our /mcp endpoint, so public page views and
	 * admin-ajax/heartbeat skip reading the files. Anything that needs them later calls register_patterns() (idempotent).
	 */
	public static function maybe_register_patterns(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || preg_match( '#/mcp/?(\?|$)#', (string) wp_parse_url( $uri, PHP_URL_PATH ) . '?' ) ) {
			self::register_patterns();
		}
	}

	/**
	 * Register the categories and every available pattern (idempotent). Patterns are registered with filePath: WordPress
	 * reads a file only when that pattern's content is requested (the editor's pattern list reads them all).
	 */
	public static function register_patterns(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		register_block_pattern_category( 'launch-builder', [ 'label' => __( 'Launch Builder: All', 'launch-builder' ) ] );
		foreach ( self::categories() as $slug => $label ) {
			/* translators: %s: pattern category name, e.g. Heroes */
			register_block_pattern_category( 'launch-builder-' . $slug, [ 'label' => sprintf( __( 'Launch: %s', 'launch-builder' ), $label ) ] );
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		foreach ( self::pattern_index() as $row ) {
			if ( ! $row['available'] ) {
				self::$unavailable[] = [ 'slug' => $row['slug'], 'title' => $row['title'], 'requires' => $row['requires'] ];
				continue;
			}
			self::$extra[ $row['name'] ] = [ 'header_meta' => $row['header_meta'], 'requires' => $row['requires'] ];
			if ( $registry->is_registered( $row['name'] ) ) {
				continue;
			}
			register_block_pattern(
				$row['name'],
				[
					'title'       => $row['title'],
					'description' => $row['description'],
					'categories'  => $row['categories'],
					'keywords'    => $row['keywords'],
					'inserter'    => $row['inserter'],
					'filePath'    => LB_DIR . 'patterns/' . $row['file'],
				]
			);
		}
	}
}
