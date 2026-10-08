<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap and module registry. Everything is convention-based so adding to the toolkit is just adding a folder:
 *  - blocks/<name>/block.json   -> registered automatically
 *  - patterns/<name>.php        -> registered automatically (theme-style file header)
 *
 * Pattern file headers: Title, Slug, Description, Categories, Keywords, plus
 *  Inserter: no                     hide from the editor inserter (headers are applied by set-header)
 *  Header-Meta: {json}              post meta applied to the kadence_header post
 *  Requires: kadence-blocks-pro     only available when that plugin is active (also: kadence-pro)
 *
 * Modules: Plugin::MODULES lists the classes that make up the plugin, in order. A module may implement any of these static
 * methods and the registry calls it at the right time (nothing else has to be edited to add one):
 *  boot()                       register hooks (called once, at plugin load, in MODULES order)
 *  define_tools()               register tools with Abilities::add() (Abilities::define)
 *  site_state( array &$state )  add keys to the site-state tool result (Abilities::site_state)
 *  playbook( array &$pb )       add guidance to get-playbook (Content::playbook)
 *  audit_checks()               return [code => [scope, callable, batched?]] checks for Audit::run
 *  blueprint_steps()            return build-site steps (see Blueprint::run_steps for the contract)
 *  brief_schema( array &$schema ) describe the brief data the module consumes (Brief::schema)
 *  activate()                   plugin activated (Plugin::activate, via register_activation_hook), e.g. Migrate::rerun()
 *  deactivate()                 plugin deactivated (Plugin::deactivate), e.g. park what must not outlive the plugin
 *
 * A listed class whose file does not exist is skipped.
 */
final class Plugin {

	/**
	 * Ordered module list. The order is the boot order (it matters only for hooks on the same priority: Abilities, Mcp,
	 * Globals, Company, Entries and Admin keep the order they always booted in) and the order tools are listed in.
	 */
	const MODULES = [
		Abilities::class,   // tool registry + options/snapshots/REST proxy/media tools
		Mcp::class,         // /mcp endpoint and REST wrappers
		Patterns::class,    // pattern registry, slots, build pipeline, pattern tools
		Nav::class,         // menus + block header
		Globals::class,     // legacy global areas (patterns-mode placement)
		Blueprint::class,   // build-site, synced patterns, elements
		Company::class,     // Business Info + tokens on render
		Rating::class,      // rating widget block (render)
		Design::class,      // brand, buttons, menus, page style, sidebar, block defaults
		Content::class,     // markdown pages, layouts, recipes, playbook
		Brief::class,       // brief -> blueprint
		Entries::class,     // services / areas post types and live lists
		Admin::class,       // wp-admin screens + pattern preview
		Roles::class,       // page roles
		Breadcrumbs::class, // breadcrumb trail
		Chrome::class,      // per-role chrome map (stub)
		Tokens::class,      // dynamic text engine (stub)
		Style::class,       // style DNA (stub)
		Cache::class,       // coalesced page-cache purge (flushed before REST responses and redirects)
		Migrate::class,     // versioned data migrations
		Audit::class,       // site audit (stub)
		Compat::class,      // third-party compatibility (stub)
		Updater::class,     // updates from GitHub releases
	];

	/** @var array<string,array> pattern slug => extra file-header data. */
	private static $extra = [];

	/** @var array<int,array> patterns skipped because a required plugin is missing. */
	private static $unavailable = [];

	private static $registered = false;

	public static function pattern_extra( string $slug ): array {
		return self::$extra[ 'launch-builder/' . $slug ] ?? [];
	}

	public static function unavailable(): array {
		self::register_patterns();
		return self::$unavailable;
	}

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
		add_action( 'init', [ __CLASS__, 'maybe_register_patterns' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_patterns' ], 5 ); // The block editor reads patterns over REST (any rest_url_prefix).
		add_filter( 'block_categories_all', [ __CLASS__, 'block_category' ] );
		foreach ( self::modules( 'boot' ) as $module ) {
			$module::boot();
		}
	}

	/** Activation hook (launch-builder.php): every module's activate(). */
	public static function activate(): void {
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
	 *
	 * @return string[] fully qualified class names
	 */
	public static function modules( string $method = '' ): array {
		$out = [];
		foreach ( (array) apply_filters( 'launch_builder_modules', self::MODULES ) as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && ( '' === $method || method_exists( $class, $method ) ) ) {
				$out[] = $class;
			}
		}
		return $out;
	}

	public static function block_category( array $categories ): array {
		array_unshift(
			$categories,
			[
				'slug'  => 'launch-builder',
				'title' => __( 'Launch Builder', 'launch-builder' ),
				'icon'  => 'star-filled',
			]
		);
		return $categories;
	}

	public static function register_blocks(): void {
		foreach ( glob( LB_DIR . 'blocks/*/block.json' ) ?: [] as $file ) {
			register_block_type( dirname( $file ) );
		}
	}

	public static function kadence_blocks_active(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( 'kadence/rowlayout' );
	}

	public static function kadence_theme_active(): bool {
		return 'kadence' === get_template();
	}

	/** Is a required companion plugin active? */
	public static function requirement_met( string $req ): bool {
		$map = [
			'kadence-blocks-pro' => 'kadence-blocks-pro/kadence-blocks-pro.php',
			'kadence-pro'        => 'kadence-pro/kadence-pro.php',
		];
		$req = strtolower( trim( $req ) );
		if ( '' === $req ) {
			return true;
		}
		return isset( $map[ $req ] ) && in_array( $map[ $req ], (array) get_option( 'active_plugins', [] ), true );
	}

	/**
	 * Patterns are only needed over REST (the editor), in WP-CLI and on our /mcp endpoint, so public page views and
	 * admin-ajax/heartbeat skip reading ~40 files. Anything that needs them later calls register_patterns() (idempotent).
	 */
	public static function maybe_register_patterns(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || preg_match( '#/mcp/?(\?|$)#', (string) wp_parse_url( $uri, PHP_URL_PATH ) . '?' ) ) {
			self::register_patterns();
		}
	}

	public static function register_patterns(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$categories = [
			'launch-builder'          => __( 'Launch Builder: All', 'launch-builder' ),
			'launch-builder-headers'  => __( 'Launch: Headers', 'launch-builder' ),
			'launch-builder-heroes'   => __( 'Launch: Heroes', 'launch-builder' ),
			'launch-builder-services' => __( 'Launch: Services', 'launch-builder' ),
			'launch-builder-areas'    => __( 'Launch: Service areas', 'launch-builder' ),
			'launch-builder-maps'     => __( 'Launch: Maps & contact', 'launch-builder' ),
			'launch-builder-cta'      => __( 'Launch: Calls to action', 'launch-builder' ),
			'launch-builder-footers'  => __( 'Launch: Footers', 'launch-builder' ),
			'launch-builder-proof'    => __( 'Launch: Reviews & proof', 'launch-builder' ),
			'launch-builder-content'  => __( 'Launch: Text + media', 'launch-builder' ),
			'launch-builder-text'     => __( 'Launch: Bare text pages', 'launch-builder' ),
			'launch-builder-ratings'  => __( 'Launch: Rating widgets', 'launch-builder' ),
		];
		foreach ( $categories as $slug => $label ) {
			register_block_pattern_category( $slug, [ 'label' => $label ] );
		}

		foreach ( glob( LB_DIR . 'patterns/*.php' ) ?: [] as $file ) {
			$meta = get_file_data(
				$file,
				[
					'title'       => 'Title',
					'slug'        => 'Slug',
					'description' => 'Description',
					'categories'  => 'Categories',
					'keywords'    => 'Keywords',
					'inserter'    => 'Inserter',
					'header_meta' => 'Header-Meta',
					'header_nav'  => 'Header-Nav',
					'requires'    => 'Requires',
				]
			);
			if ( ! $meta['title'] || ! $meta['slug'] ) {
				continue;
			}
			if ( ! self::requirement_met( (string) $meta['requires'] ) ) {
				self::$unavailable[] = [ 'slug' => preg_replace( '#^launch-builder/#', '', $meta['slug'] ), 'title' => $meta['title'], 'requires' => $meta['requires'] ];
				continue;
			}
			ob_start();
			include $file;
			$content = ob_get_clean();

			self::$extra[ $meta['slug'] ] = [ 'header_meta' => json_decode( (string) $meta['header_meta'], true ) ?: [], 'header_nav' => json_decode( (string) $meta['header_nav'], true ) ?: [], 'requires' => $meta['requires'] ];

			register_block_pattern(
				$meta['slug'],
				[
					'title'       => $meta['title'],
					'description' => $meta['description'],
					'categories'  => array_filter( array_map( 'trim', explode( ',', $meta['categories'] ?: 'launch-builder' ) ) ),
					'keywords'    => array_filter( array_map( 'trim', explode( ',', $meta['keywords'] ) ) ),
					'content'     => $content,
					'inserter'    => 'no' !== strtolower( trim( (string) $meta['inserter'] ) ),
				]
			);
		}
	}
}
