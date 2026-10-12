<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Tool registry for AI agents. Served by our own /mcp endpoint (class-mcp.php) and, when the WordPress
 * Abilities API exists (WP 6.9+), also registered there so other MCP servers can use the same tools.
 *
 * Design: a few curated, typed abilities for the Launch Builder workflow (patterns, brand, snapshots)
 * plus broad "power" abilities (REST proxy, theme mods, options) for greenfield site builds. Every write
 * to settings snapshots first so it can be undone. Permission is always checked against the connected
 * WordPress user, so run the MCP connection as a dedicated user.
 *
 * This class holds the registry and the core tools (site state, theme mods, options, snapshots, REST proxy, media).
 * Every other tool is registered by the module that owns it (module hook define_tools(), see Plugin::MODULES).
 *
 * Snapshots (v2): one non-autoloaded option per snapshot (launch_builder_snapshot_<id>) plus an index
 * (launch_builder_snapshots_index), capped at 30 snapshots and 2 MB. A snapshot holds theme mods, options and,
 * optionally, posts: [{id, type, status, revision_id, ledger_hash, meta, ledger_key/ledger_row}] (the content itself
 * lives in a revision; types without revisions keep it inline). restore-snapshot puts mods, options, post contents,
 * statuses and listed meta back, then lets Chrome re-apply itself (Chrome::after_restore( $snapshot ) when it exists,
 * then the action launch_builder_snapshot_restored). Snapshots written before v2 (all in launch_builder_snapshots) are
 * moved by the migration step snapshots-v2 and stay restorable before it runs.
 */
final class Abilities {

	private const CAT = 'launch-builder';
	private const SNAP_OPTION = 'launch_builder_snapshots'; // v1 storage (every snapshot in one array): read for back-compat.
	private const SNAP_INDEX  = 'launch_builder_snapshots_index';
	private const SNAP_PREFIX = 'launch_builder_snapshot_';
	private const SNAP_MAX    = 30;
	private const SNAP_BYTES  = 2097152; // 2 MB for all snapshots together.

	/** @var array<string,array> name => definition */
	private static $defs = [];

	/** @var string[] snapshot ids pruning must keep (the one being restored). */
	private static $snap_keep = [];

	public static function boot(): void {
		if ( function_exists( 'wp_register_ability' ) ) { // WP 6.9+.
			add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'categories' ] );
			add_action( 'wp_abilities_api_init', [ __CLASS__, 'register' ] );
		}
		Migrate::register_step( 'snapshots-v2', [ __CLASS__, 'migrate_snapshots' ] );
	}

	public static function categories(): void {
		wp_register_ability_category( self::CAT, [
			'label'       => __( 'Launch Builder', 'launch-builder' ),
			'description' => __( 'Build and configure client sites: patterns, brand, settings.', 'launch-builder' ),
		] );
	}

	/* ------------------------------------------------------------------ registration */

	public static function add( string $name, string $label, string $desc, array $input, callable $cb, string $cap = 'manage_options', array $annotations = [] ): void {
		self::$defs[ $name ] = [
			'name'        => $name,
			'label'       => $label,
			'description' => $desc,
			'input'       => $input + [ 'type' => 'object' ],
			'callback'    => $cb,
			'cap'         => $cap,
			'annotations' => $annotations,
		];
	}

	/** All tool definitions (built once). */
	public static function defs(): array {
		if ( ! self::$defs ) {
			self::define();
		}
		return self::$defs;
	}

	/** Register every tool with the WordPress Abilities API (if present). */
	public static function register(): void {
		foreach ( self::defs() as $d ) {
			$in = $d['input'];
			if ( isset( $in['properties'] ) && $in['properties'] instanceof \stdClass ) {
				$in['properties'] = [];
			}
			wp_register_ability( self::CAT . '/' . $d['name'], [
				'label'               => $d['label'],
				'description'         => $d['description'],
				'category'            => self::CAT,
				'input_schema'        => $in,
				'execute_callback'    => $d['callback'],
				'permission_callback' => static function () use ( $d ) {
					return current_user_can( $d['cap'] );
				},
				// Exposure to third-party MCP adapters is opt-in: add_filter( 'launch_builder_abilities_mcp_public', '__return_true' ).
				'meta'                => [ 'show_in_rest' => true, 'mcp' => [ 'public' => (bool) apply_filters( 'launch_builder_abilities_mcp_public', false, $d['name'] ) ], 'annotations' => $d['annotations'] ],
			] );
		}
	}

	private static function define(): void {
		$ro = [ 'readonly' => true ];

		self::add( 'site-state', 'Site state', 'Snapshot of the site: theme, active plugins, pages, menus, Kadence palette, pattern count. Call this first.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'site_state' ], 'edit_pages', $ro );

		self::add( 'get-theme-mods', 'Read theme mods', 'Read Customizer theme mods (Kadence stores palette, fonts, header/footer here). Optional key prefix filter.',
			[ 'properties' => [ 'prefix' => [ 'type' => 'string' ], 'keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ], [ __CLASS__, 'get_theme_mods' ], 'manage_options', $ro );

		self::add( 'set-theme-mods', 'Write theme mods', 'Set Customizer theme mods (merged by key). Snapshots the previous values first. Returns the snapshot id.',
			[ 'properties' => [ 'mods' => [ 'type' => 'object' ], 'label' => [ 'type' => 'string' ] ], 'required' => [ 'mods' ] ], [ __CLASS__, 'set_theme_mods' ] );

		self::add( 'get-option', 'Read an option', 'Read a WordPress option. Secrets (keys, tokens, passwords) are refused.',
			[ 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ] ], [ __CLASS__, 'get_option' ], 'manage_options', $ro );

		self::add( 'find-options', 'Find option names', 'List option names matching a substring (names and sizes only). Use it to discover where a plugin keeps its settings, then get-option.',
			[ 'properties' => [ 'contains' => [ 'type' => 'string' ] ], 'required' => [ 'contains' ] ], [ __CLASS__, 'find_options' ], 'manage_options', $ro );

		self::add( 'set-option', 'Write an option', 'Set a WordPress option. Snapshots the previous value. Site URL, admin email, roles, secrets and Launch Builder internals are refused, and so is any value containing "[redacted]" (a redacted read written back). merge: true sets only the top-level keys given (the option must be an array or a JSON object string); other keys keep their values.',
			[ 'properties' => [ 'name' => [ 'type' => 'string' ], 'value' => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ] ], 'merge' => [ 'type' => 'boolean', 'description' => 'Merge top-level keys into the stored array instead of replacing it.' ], 'label' => [ 'type' => 'string' ] ], 'required' => [ 'name', 'value' ] ], [ __CLASS__, 'set_option' ] );

		self::add( 'restore-snapshot', 'Restore a snapshot', 'Undo a change by snapshot id: theme mods, options and, for snapshots that list posts, their contents, statuses and meta (shared designs are then re-applied). Restoring first takes an undo snapshot of the current values. Call with no id to list snapshots (newest first, 30 kept).',
			[ 'properties' => [ 'id' => [ 'type' => 'string' ] ] ], [ __CLASS__, 'restore_snapshot' ] );

		self::add( 'rest-request', 'Call a WordPress REST route', 'Run an internal REST request as the connected user. Reading works on any route. Writing is limited to content routes (posts, pages, services, areas, media, terms, menus, navigation, templates, global styles, widgets, comments) and Rank Math post SEO meta; users, plugins, themes, core settings and other plugins\' routes need power tools (an administrator switch). Batch requests are refused. Core permission checks apply.',
			[ 'properties' => [
				'method' => [ 'type' => 'string', 'enum' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ] ],
				'route'  => [ 'type' => 'string', 'description' => 'e.g. /wp/v2/pages' ],
				'params' => [ 'type' => 'object', 'description' => 'Query args for GET, JSON body otherwise.' ],
			], 'required' => [ 'method', 'route' ] ], [ __CLASS__, 'rest_request' ] );

		foreach ( Plugin::modules( 'define_tools' ) as $module ) { // Each module registers its own tools (Plugin::MODULES order).
			$module::define_tools();
		}

		self::add( 'sideload-media', 'Import an image from a URL', 'Download an image into the media library. Returns attachment id and URL.',
			[ 'properties' => [ 'url' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'alt' => [ 'type' => 'string' ], 'post_id' => [ 'type' => 'integer' ],
				// Media hygiene normalisation; defaults come from get-media-settings when the module is on.
				'max_px' => [ 'type' => 'integer', 'description' => 'Longest edge in px: 0 = keep, else 640-4096. Default: media hygiene setting when it is on, else unchanged. The result then adds bytes_in, bytes_out, mime_in, mime_out, w, h.' ],
				'format' => [ 'type' => 'string', 'enum' => Media::FORMATS, 'description' => 'auto: an opaque truecolour PNG photo becomes JPEG; keep; jpeg (unless transparent); webp. Default: media hygiene setting when it is on, else keep.' ] ], 'required' => [ 'url' ] ], [ __CLASS__, 'sideload_media' ], 'upload_files' );
	}

	/* ------------------------------------------------------------------ site state */

	public static function site_state(): array {
		$pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ] );
		$plugins = array_map( static function ( $f ) { return dirname( $f ); }, (array) get_option( 'active_plugins', [] ) );
		$menus = [];
		foreach ( wp_get_nav_menus() as $m ) {
			$menus[] = [ 'id' => $m->term_id, 'name' => $m->name, 'items' => (int) $m->count ];
		}
		$state = [
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'theme'       => get_stylesheet(),
			'plugins'     => array_values( $plugins ),
			'front_page'  => [ 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => (int) get_option( 'page_on_front' ) ],
			'pages'       => array_map( static function ( $p ) { return [ 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status ]; }, $pages ),
			'menus'       => $menus,
			'menu_locations' => get_nav_menu_locations(),
			'kadence_palette' => get_option( 'kadence_global_palette' ),
			'block_header'    => [ 'enabled' => (bool) get_theme_mod( 'blocks_header' ), 'id' => (int) get_theme_mod( 'blocks_header_id' ) ],
			'kadence_pro'    => [ 'blocks_pro' => Plugin::requirement_met( 'kadence-blocks-pro' ), 'theme_pro' => Plugin::requirement_met( 'kadence-pro' ) ],
			'launch_builder' => [ 'version' => LB_VERSION, 'patterns' => count( Patterns::all() ), 'kadence_blocks' => Plugin::kadence_blocks_active(), 'kadence_theme' => Plugin::kadence_theme_active() ],
		];
		foreach ( Plugin::modules( 'site_state' ) as $module ) { // Modules add their own keys (global_areas, company, roles...).
			if ( __CLASS__ !== $module ) {
				$module::site_state( $state );
			}
		}
		return $state;
	}

	/* ------------------------------------------------------------------ patterns: thin delegates (moved to class-patterns.php) */

	/** @deprecated Use Patterns::list_patterns(). */
	public static function list_patterns( $input = [] ): array {
		return Patterns::list_patterns( $input );
	}

	/** @deprecated Use Patterns::get_pattern(). */
	public static function get_pattern( $input ) {
		return Patterns::get_pattern( $input );
	}

	/** @deprecated Use Patterns::slots(). */
	public static function slots( array $blocks, string $prefix = '' ): array {
		return Patterns::slots( $blocks, $prefix );
	}

	/** @deprecated Use Patterns::pattern_content(). */
	public static function pattern_content( string $slug ): ?string {
		return Patterns::pattern_content( $slug );
	}

	/** @deprecated Use Patterns::set_text(). */
	public static function set_text( array &$b, string $text ): bool {
		return Patterns::set_text( $b, $text );
	}

	/** @deprecated Use Patterns::fill(). */
	public static function fill( array &$blocks, array $by_id, array $by_value, array $vars = [] ): array {
		return Patterns::fill( $blocks, $by_id, $by_value, $vars );
	}

	/** @deprecated Use Patterns::build_pattern(). */
	public static function build_pattern( string $slug, array $slots = [], array $replace = [], array $vars = [], string $markdown = '' ) {
		return Patterns::build_pattern( $slug, $slots, $replace, $vars, $markdown );
	}

	/** @deprecated Use Patterns::apply_pattern(). */
	public static function apply_pattern( $input ) {
		return Patterns::apply_pattern( $input );
	}

	/* ------------------------------------------------------------------ theme mods / options + snapshots */

	/** Option / mod names are compared trimmed and lower-cased (WordPress trims names; MySQL matches case-insensitively). */
	private static function norm( $name ): string {
		return strtolower( trim( (string) $name ) );
	}

	/** LB internals no tool may write directly (a faked ledger row would let LB overwrite an admin's edits, a planted lock blocks jobs, remove_all_data deletes Business Info on uninstall, module switches have set-modules). */
	private static function internal_option( string $n ): bool {
		return in_array( $n, [ self::SNAP_OPTION, self::SNAP_INDEX, 'launch_builder_log', 'launch_builder_power', 'launch_builder_mcp_token', 'launch_builder_ledger', 'launch_builder_migrate_state', 'launch_builder_db_version', 'launch_builder_parked_elements', 'launch_builder_remove_all_data', 'launch_builder_disabled_modules', 'launch_builder_audit_results' ], true )
			|| (bool) preg_match( '/^launch_builder_(lock|ledger|snapshot)_/', $n );
	}

	/** May this option be read as data (for example as a Business Info source)? Same rules as get-option, minus site-critical names. */
	public static function option_readable( $name ): bool {
		$n = self::norm( $name );
		return '' !== $n && ! self::secret_key( $n )
			&& ! in_array( $n, [ 'siteurl', 'home', 'admin_email', 'new_admin_email', 'active_plugins', 'active_sitewide_plugins' ], true )
			&& ! self::internal_option( $n )
			&& ! preg_match( '/user_roles$|^widget_|^cron$|^recovery_/', $n ) && 0 !== strpos( $n, 'wp_' );
	}

	/** Does a name look like it holds a secret? "password_protected" (Rank Math's noindex switch) is a setting, not a secret. */
	private static function secret_key( $name ): bool {
		$n = (string) preg_replace( '/password[_-]?protected/i', '', (string) $name );
		return (bool) preg_match( '/(pass|secret|token|salt|auth_|_key|api_?key|private|stripe|paypal|smtp|mailgun|sendgrid|recaptcha|licen[sc]e|credential|jetpack_options)/i', $n );
	}

	/** Replace values under secret-looking keys, at any depth. */
	private static function redact( $v ) {
		if ( is_object( $v ) ) {
			$v = (array) $v;
		}
		if ( ! is_array( $v ) ) {
			return $v;
		}
		foreach ( $v as $k => $item ) {
			$v[ $k ] = ( is_string( $k ) && self::secret_key( $k ) ) ? '[redacted]' : self::redact( $item );
		}
		return $v;
	}

	/** Does a value contain the redaction marker anywhere (a redacted read being written back)? */
	private static function has_redacted( $v ): bool {
		if ( is_string( $v ) ) {
			return false !== strpos( $v, '[redacted]' );
		}
		if ( is_object( $v ) ) {
			$v = (array) $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				if ( ( is_string( $k ) && false !== strpos( $k, '[redacted]' ) ) || self::has_redacted( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ snapshots (v2) */

	/**
	 * Save the current values of theme mods, options and posts before a write; returns the snapshot id.
	 *
	 * @param string $label   what is about to change (shown in the list).
	 * @param array  $mods    theme mod names.
	 * @param array  $options option names.
	 * @param array  $posts   post ids, or [ 'id' => int, 'meta' => string[] (single-value meta keys to keep), 'ledger' => ledger key ].
	 *                        The content is kept as a revision (types without revisions keep it inline).
	 */
	public static function snapshot( string $label, array $mods = [], array $options = [], array $posts = [] ): string {
		$id = gmdate( 'ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$snap = [ 'v' => 2, 'id' => $id, 'time' => gmdate( 'c' ), 'label' => sanitize_text_field( $label ), 'user' => get_current_user_id(), 'mods' => [], 'options' => [], 'posts' => [] ];
		foreach ( $mods as $k ) {
			$snap['mods'][ $k ] = [ 'exists' => null !== get_theme_mod( $k, null ), 'value' => get_theme_mod( $k, null ) ];
		}
		foreach ( $options as $k ) {
			$sentinel = '__lb_absent__';
			$v = get_option( $k, $sentinel );
			$snap['options'][ $k ] = [ 'exists' => $v !== $sentinel, 'value' => $v === $sentinel ? null : $v ];
		}
		foreach ( $posts as $spec ) {
			$rec = self::snap_post( $spec );
			if ( $rec ) {
				$snap['posts'][] = $rec;
			}
		}
		$bytes = strlen( maybe_serialize( $snap ) );
		if ( $bytes > self::SNAP_BYTES ) { // Too big even alone: inline post contents go (their revisions, if any, stay).
			foreach ( $snap['posts'] as &$p ) {
				unset( $p['content'], $p['title'], $p['excerpt'] );
			}
			unset( $p );
			$snap['partial'] = true;
			$bytes = strlen( maybe_serialize( $snap ) );
		}
		add_option( self::SNAP_PREFIX . $id, $snap, '', 'no' );
		$index = self::snap_index();
		array_unshift( $index, self::snap_row( $snap, $bytes ) );
		update_option( self::SNAP_INDEX, $index, false );
		self::snap_prune();
		return $id;
	}

	/** One post's restorable state. @param int|array $spec */
	private static function snap_post( $spec ): ?array {
		$spec = is_array( $spec ) ? $spec : [ 'id' => $spec ];
		$id = (int) ( $spec['id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return null;
		}
		$meta_keys = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $spec['meta'] ?? [] ) ) ) ) );
		$rec = [ 'id' => $id, 'type' => $post->post_type, 'status' => $post->post_status, 'revision_id' => 0, 'ledger_hash' => Ledger::hash_post( $id, $meta_keys ), 'meta' => [] ];
		if ( wp_revisions_enabled( $post ) ) {
			$rid = wp_save_post_revision( $id ); // null when nothing changed since the latest revision.
			if ( ! $rid ) {
				$last = wp_get_post_revisions( $id, [ 'numberposts' => 1 ] );
				$r = $last ? reset( $last ) : null;
				if ( $r && $r->post_content === $post->post_content && $r->post_title === $post->post_title && $r->post_excerpt === $post->post_excerpt ) {
					$rid = $r->ID;
				}
			}
			$rec['revision_id'] = (int) $rid;
		}
		if ( ! $rec['revision_id'] ) {
			$rec['content'] = $post->post_content;
			$rec['title'] = $post->post_title;
			$rec['excerpt'] = $post->post_excerpt;
		}
		foreach ( $meta_keys as $k ) {
			$rec['meta'][ $k ] = [ 'exists' => metadata_exists( 'post', $id, $k ), 'value' => get_post_meta( $id, $k, true ) ];
		}
		if ( ! empty( $spec['ledger'] ) ) {
			$rec['ledger_key'] = (string) $spec['ledger'];
			$rec['ledger_row'] = Ledger::get( (string) $spec['ledger'] );
		}
		return $rec;
	}

	/** Index row of a snapshot. */
	private static function snap_row( array $s, int $bytes ): array {
		return [
			'id'      => (string) $s['id'],
			'time'    => (string) ( $s['time'] ?? '' ),
			'label'   => (string) ( $s['label'] ?? '' ),
			'bytes'   => $bytes,
			'mods'    => array_keys( (array) ( $s['mods'] ?? [] ) ),
			'options' => array_keys( (array) ( $s['options'] ?? [] ) ),
			'posts'   => array_map( static function ( $p ) { return (int) ( $p['id'] ?? 0 ); }, (array) ( $s['posts'] ?? [] ) ),
		];
	}

	private static function snap_index(): array {
		$idx = get_option( self::SNAP_INDEX, [] );
		return is_array( $idx ) ? array_values( array_filter( $idx, static function ( $r ) { return is_array( $r ) && ! empty( $r['id'] ); } ) ) : [];
	}

	/** Snapshot ids stored as options (the truth the index is checked against) => bytes, newest first. */
	private static function snap_stored(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::SNAP_PREFIX ) . '%' ), ARRAY_A ); // phpcs:ignore
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ substr( (string) $r['option_name'], strlen( self::SNAP_PREFIX ) ) ] = (int) $r['bytes'];
		}
		krsort( $out, SORT_STRING ); // Ids start with ymd-His, so this is newest first.
		return $out;
	}

	/** Keep the newest 30 snapshots within 2 MB (the newest always stays), and make the index match what is stored. */
	private static function snap_prune(): void {
		$stored = self::snap_stored();
		$keep = [];
		$total = 0;
		$full = false; // Once one does not fit, every older one goes too (oldest first, never a hole in the history).
		foreach ( $stored as $id => $bytes ) {
			$id = (string) $id;
			$protected = in_array( $id, self::$snap_keep, true );
			if ( ! $full && $keep && ( count( $keep ) >= self::SNAP_MAX || $total + $bytes > self::SNAP_BYTES ) ) {
				$full = true;
			}
			if ( ! $keep || $protected || ! $full ) {
				$keep[ $id ] = $bytes;
				$total += $bytes;
			} else {
				delete_option( self::SNAP_PREFIX . $id );
			}
		}
		$index = [];
		$seen = [];
		foreach ( self::snap_index() as $row ) {
			if ( isset( $keep[ (string) $row['id'] ] ) && ! isset( $seen[ $row['id'] ] ) ) {
				$index[] = $row;
				$seen[ $row['id'] ] = true;
			}
		}
		foreach ( $keep as $id => $bytes ) { // Stored but not indexed (two writers raced on the index): add it back.
			if ( ! isset( $seen[ $id ] ) ) {
				$s = get_option( self::SNAP_PREFIX . $id );
				if ( is_array( $s ) ) {
					$index[] = self::snap_row( $s, $bytes );
				}
			}
		}
		usort( $index, static function ( $a, $b ) { return strcmp( (string) $b['id'], (string) $a['id'] ); } );
		update_option( self::SNAP_INDEX, $index, false );
	}

	/** Every snapshot, newest first: [{id, time, label, bytes, mods[], options[], posts[]}] (legacy ones flagged). */
	public static function snapshots(): array {
		$index = self::snap_index();
		if ( count( $index ) !== count( self::snap_stored() ) ) {
			self::snap_prune();
			$index = self::snap_index();
		}
		$legacy = get_option( self::SNAP_OPTION, [] );
		foreach ( is_array( $legacy ) ? $legacy : [] as $s ) { // Not moved yet (migration step snapshots-v2).
			if ( is_array( $s ) && ! empty( $s['id'] ) ) {
				$index[] = self::snap_row( $s, 0 ) + [ 'legacy' => true ];
			}
		}
		return $index;
	}

	/** A snapshot by id (v2 storage first, then the legacy array). */
	public static function get_snapshot( string $id ): ?array {
		$id = trim( $id );
		if ( '' === $id || ! preg_match( '/^[A-Za-z0-9-]+$/', $id ) ) {
			return null;
		}
		$s = get_option( self::SNAP_PREFIX . strtolower( $id ) );
		if ( is_array( $s ) ) {
			return $s;
		}
		$legacy = get_option( self::SNAP_OPTION, [] );
		foreach ( is_array( $legacy ) ? $legacy : [] as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) && (string) $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	/** Migration step snapshots-v2: move the v1 array (launch_builder_snapshots) to one option per snapshot. */
	public static function migrate_snapshots( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$legacy = get_option( self::SNAP_OPTION, null );
		$moved = 0;
		foreach ( array_reverse( is_array( $legacy ) ? $legacy : [] ) as $s ) { // Oldest first.
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			$id = strtolower( (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) $s['id'] ) );
			if ( '' === $id || false !== get_option( self::SNAP_PREFIX . $id, false ) ) {
				continue;
			}
			$s['id'] = $id;
			$s['v'] = 2;
			$s['posts'] = [];
			$s['migrated'] = true;
			add_option( self::SNAP_PREFIX . $id, $s, '', 'no' );
			$moved++;
		}
		delete_option( self::SNAP_OPTION );
		self::snap_prune();
		return [ 'done' => true, 'report' => $moved ? $moved . ' snapshots moved to v2 storage' : 'no legacy snapshots' ];
	}

	public static function get_theme_mods( $input = [] ): array {
		$all = array_filter( (array) get_theme_mods(), static function ( $k ) { return ! self::secret_key( $k ); }, ARRAY_FILTER_USE_KEY );
		if ( ! empty( $input['keys'] ) ) {
			$all = array_intersect_key( $all, array_flip( (array) $input['keys'] ) );
		} elseif ( ! empty( $input['prefix'] ) ) {
			$p = (string) $input['prefix'];
			$all = array_filter( $all, static function ( $k ) use ( $p ) { return 0 === strpos( (string) $k, $p ); }, ARRAY_FILTER_USE_KEY );
		}
		return [ 'theme' => get_stylesheet(), 'count' => count( $all ), 'mods' => self::redact( $all ) ];
	}

	public static function set_theme_mods( $input ) {
		$mods = (array) ( $input['mods'] ?? [] );
		if ( ! $mods ) {
			return new \WP_Error( 'lb_empty', 'No mods given.', [ 'status' => 400 ] );
		}
		if ( self::has_redacted( $mods ) ) {
			return new \WP_Error( 'lb_redacted', 'A value contains "[redacted]": that is a redacted read, not a real value. Send only the keys you change.', [ 'status' => 400 ] );
		}
		$clean = [];
		foreach ( $mods as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' !== $key ) {
				$clean[ $key ] = $v;
			}
		}
		if ( ! $clean ) {
			return new \WP_Error( 'lb_empty', 'No valid mod keys.', [ 'status' => 400 ] );
		}
		$snap = self::snapshot( $input['label'] ?? 'set-theme-mods', array_keys( $clean ) );
		foreach ( $clean as $key => $v ) {
			set_theme_mod( $key, $v );
		}
		return [ 'updated' => array_keys( $clean ), 'snapshot' => $snap ];
	}

	public static function find_options( $input ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( (string) ( $input['contains'] ?? '' ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 1000", $like ), ARRAY_A ); // phpcs:ignore
		$rows = array_values( array_filter( (array) $rows, static function ( $r ) { return ! self::secret_key( $r['name'] ); } ) );
		return [ 'count' => min( 200, count( $rows ) ), 'options' => array_slice( $rows, 0, 200 ) ];
	}

	public static function get_option( $input ) {
		$n = self::norm( $input['name'] ?? '' );
		if ( '' === $n || self::secret_key( $n ) ) {
			return new \WP_Error( 'lb_refused', 'Refused.', [ 'status' => 403 ] );
		}
		return [ 'name' => $n, 'value' => self::redact( get_option( $n ) ) ];
	}

	public static function set_option( $input ) {
		$input = (array) $input;
		$n = self::norm( $input['name'] ?? '' );
		if ( ! array_key_exists( 'value', $input ) ) {
			return new \WP_Error( 'lb_value', 'value is required.', [ 'status' => 400 ] );
		}
		$deny = [ 'siteurl', 'home', 'admin_email', 'new_admin_email', 'default_role', 'users_can_register', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'permalink_structure' ];
		$blocked = in_array( $n, $deny, true ) || self::internal_option( $n ) || (bool) preg_match( '/user_roles$|^widget_|^cron$|^recovery_/', $n ) || ( 0 === strpos( $n, 'wp_' ) && 'wp_page_for_privacy_policy' !== $n );
		if ( '' === $n || self::secret_key( $n ) || $blocked ) {
			return new \WP_Error( 'lb_refused', 'That option can\'t be changed through this ability.', [ 'status' => 403 ] );
		}
		$value = $input['value'];
		if ( self::has_redacted( $value ) ) {
			return new \WP_Error( 'lb_redacted', 'The value contains "[redacted]": get-option hides secrets, so writing a read back would destroy them. Send only the keys you change, with merge: true.', [ 'status' => 400 ] );
		}
		$merged = [];
		if ( ! empty( $input['merge'] ) ) {
			if ( ! is_array( $value ) || ( $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
				return new \WP_Error( 'lb_merge', 'merge needs value to be an object of keys to set.', [ 'status' => 400 ] );
			}
			$cur = get_option( $n, null );
			$json = is_string( $cur ) && '' !== $cur && is_array( json_decode( $cur, true ) );
			$base = $json ? json_decode( $cur, true ) : $cur;
			if ( null !== $base && ! is_array( $base ) && '' !== $base ) {
				return new \WP_Error( 'lb_merge', 'merge only works on an option that holds an array or a JSON object.', [ 'status' => 400 ] );
			}
			$merged = array_keys( $value );
			$value = array_replace( is_array( $base ) ? $base : [], $value );
			if ( $json ) {
				$value = wp_json_encode( $value );
			}
		}
		$snap = self::snapshot( $input['label'] ?? 'set-option', [], [ $n ] );
		update_option( $n, $value );
		$out = [ 'updated' => $n, 'snapshot' => $snap ];
		if ( $merged ) {
			$out['merged_keys'] = $merged;
		}
		return $out;
	}

	/**
	 * restore-snapshot: list (no id) or restore one. Restoring takes an undo snapshot of what it is about to overwrite,
	 * then restores mods, options and posts, then lets Chrome re-apply (Chrome::after_restore, if implemented).
	 */
	public static function restore_snapshot( $input = [] ) {
		$id = trim( (string) ( ( (array) $input )['id'] ?? '' ) );
		if ( '' === $id ) {
			return [ 'snapshots' => self::snapshots() ];
		}
		$s = self::get_snapshot( $id );
		if ( ! $s ) {
			return new \WP_Error( 'lb_no_snapshot', 'Unknown snapshot id. Call restore-snapshot without an id to list them.', [ 'status' => 404 ] );
		}
		$mods = is_array( $s['mods'] ?? null ) ? $s['mods'] : [];
		$options = is_array( $s['options'] ?? null ) ? $s['options'] : [];
		$posts = is_array( $s['posts'] ?? null ) ? $s['posts'] : [];
		$specs = array_map( static function ( $p ) { return [ 'id' => (int) ( $p['id'] ?? 0 ), 'meta' => array_keys( (array) ( $p['meta'] ?? [] ) ), 'ledger' => (string) ( $p['ledger_key'] ?? '' ) ]; }, $posts );
		self::$snap_keep = [ strtolower( (string) $s['id'] ) ];
		$undo = self::snapshot( 'before restore ' . $s['id'], array_keys( $mods ), array_keys( $options ), $specs );
		self::$snap_keep = [];
		foreach ( $mods as $k => $m ) {
			! empty( $m['exists'] ) ? set_theme_mod( $k, $m['value'] ) : remove_theme_mod( $k );
		}
		$modules_before = array_key_exists( Modules::OPT, $options ) ? Modules::states() : null;
		foreach ( $options as $k => $o ) {
			if ( self::internal_option( self::norm( $k ) ) && ! in_array( self::norm( $k ), [ 'launch_builder_disabled_modules' ], true ) ) {
				continue; // Never restore LB bookkeeping (an old snapshot must not resurrect a lock or a ledger row).
			}
			! empty( $o['exists'] ) ? update_option( $k, $o['value'] ) : delete_option( $k );
		}
		$post_report = [];
		foreach ( $posts as $p ) {
			$post_report[] = self::restore_post( (array) $p );
		}
		$out = [ 'restored' => (string) $s['id'], 'undo' => $undo, 'mods' => count( $mods ), 'options' => count( $options ) ];
		if ( $post_report ) {
			$out['posts'] = $post_report;
		}
		if ( null !== $modules_before ) { // Module switches came back: run their activate()/deactivate() as set-modules does.
			$out['modules'] = Modules::after_change( $modules_before );
		}
		if ( method_exists( Chrome::class, 'after_restore' ) ) {
			$chrome = call_user_func( [ Chrome::class, 'after_restore' ], $s );
			$out['chrome'] = is_wp_error( $chrome ) ? [ 'error' => $chrome->get_error_message() ] : $chrome;
		}
		do_action( 'launch_builder_snapshot_restored', $s, $out );
		Cache::purge_later();
		return $out;
	}

	/** Put one post back as the snapshot saw it: content (revision or inline), status, listed meta, ledger row. */
	private static function restore_post( array $rec ): array {
		$id = (int) ( $rec['id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return [ 'id' => $id, 'result' => 'missing' ];
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return [ 'id' => $id, 'result' => 'forbidden' ];
		}
		$did = [];
		$rid = (int) ( $rec['revision_id'] ?? 0 );
		$rev = $rid ? wp_get_post_revision( $rid ) : null;
		if ( $rev && (int) $rev->post_parent === $id ) {
			if ( $rev->post_content !== $post->post_content || $rev->post_title !== $post->post_title || $rev->post_excerpt !== $post->post_excerpt ) {
				wp_restore_post_revision( $rid );
				$did[] = 'content';
			}
		} elseif ( isset( $rec['content'] ) ) {
			if ( (string) $rec['content'] !== $post->post_content || (string) ( $rec['title'] ?? $post->post_title ) !== $post->post_title ) {
				wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => (string) $rec['content'], 'post_title' => (string) ( $rec['title'] ?? $post->post_title ), 'post_excerpt' => (string) ( $rec['excerpt'] ?? $post->post_excerpt ) ] ) );
				$did[] = 'content';
			}
		} elseif ( $rid ) {
			$did[] = 'revision_missing';
		}
		$want = (string) ( $rec['status'] ?? '' );
		$now = (string) get_post_status( $id );
		if ( $want && $want !== $now && in_array( $want, [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], true ) ) {
			if ( 'trash' === $want ) {
				wp_trash_post( $id );
			} else {
				if ( 'trash' === $now ) {
					wp_untrash_post( $id );
				}
				wp_update_post( [ 'ID' => $id, 'post_status' => $want ] );
			}
			$did[] = 'status';
		}
		foreach ( (array) ( $rec['meta'] ?? [] ) as $k => $m ) {
			$k = (string) $k;
			if ( ! empty( $m['exists'] ) ) {
				update_post_meta( $id, $k, wp_slash( $m['value'] ) );
			} else {
				delete_post_meta( $id, $k );
			}
		}
		if ( $rec['meta'] ?? [] ) {
			$did[] = 'meta';
		}
		if ( ! empty( $rec['ledger_key'] ) ) {
			$row = $rec['ledger_row'] ?? null;
			if ( is_array( $row ) && isset( $row['type'], $row['hash'], $row['status'] ) ) {
				Ledger::record( (string) $rec['ledger_key'], (string) $row['type'], $row['id'] ?? $id, (string) $row['hash'], (string) $row['status'] );
				if ( ! empty( $row['pruned_by_lb'] ) ) {
					Ledger::mark_pruned( (string) $rec['ledger_key'] );
				}
			} else {
				Ledger::forget( (string) $rec['ledger_key'] );
			}
			$did[] = 'ledger';
		}
		return [ 'id' => $id, 'result' => $did ? 'restored' : 'unchanged', 'changed' => $did ];
	}

	/* ------------------------------------------------------------------ custom patterns: thin delegates (moved to class-patterns.php) */

	/** @deprecated Use Patterns::save_pattern(). */
	public static function save_pattern( $input ) {
		return Patterns::save_pattern( $input );
	}

	/** @deprecated Use Patterns::delete_pattern(). */
	public static function delete_pattern( $input ) {
		return Patterns::delete_pattern( $input );
	}

	/* ------------------------------------------------------------------ navigation + header */

	public static function upsert_post( string $type, string $key, string $title, string $content, string $status = 'publish' ) {
		$found = get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids' ] );
		$args = [ 'post_type' => $type, 'post_title' => $title, 'post_content' => wp_slash( $content ), 'post_status' => $status ];
		if ( $found ) {
			$args['ID'] = (int) $found[0];
			$id = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_lb_key', $key );
		}
		return $id;
	}

	/** @deprecated Moved to Nav::set_navigation(). */
	public static function set_navigation( $input ) {
		return Nav::set_navigation( $input );
	}

	/** @deprecated Moved to Nav::set_header(). */
	public static function set_header( $input ) {
		return Nav::set_header( $input );
	}

	/* ------------------------------------------------------------------ brand: thin delegate (moved to Design) */

	/** @deprecated Moved to Design::set_brand(). */
	public static function set_brand( $input ) {
		return Design::set_brand( $input );
	}

	/* ------------------------------------------------------------------ REST proxy + media */

	/** Are the high-risk REST routes (users, plugins, themes, settings) enabled? Off by default. */
	public static function power(): bool {
		return (bool) get_option( 'launch_builder_power', false ) || ( defined( 'LB_POWER_TOOLS' ) && LB_POWER_TOOLS );
	}

	/**
	 * Route prefixes rest-request may write to without power tools: the REST bases of every post type and taxonomy shown
	 * in REST (posts, pages, services, areas, elements, synced patterns...), the content routes below and Rank Math's
	 * per-post SEO routes. Filter launch_builder_rest_write_allow adds prefixes (lower case, no trailing slash).
	 *
	 * @return string[]
	 */
	public static function rest_write_allow(): array {
		$allow = [ '/wp/v2/media', '/wp/v2/menus', '/wp/v2/menu-items', '/wp/v2/menu-locations', '/wp/v2/navigation', '/wp/v2/templates', '/wp/v2/template-parts', '/wp/v2/global-styles', '/wp/v2/sidebars', '/wp/v2/widgets', '/wp/v2/comments', '/wp/v2/blocks', '/wp/v2/block-renderer', '/rankmath/v1/updatemeta', '/rankmath/v1/updatemetabulk', '/rankmath/v1/updateschemas', '/rankmath/v1/updateredirection', '/rankmath/v1/updateseoscore' ];
		foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $pt ) {
			$allow[] = '/' . trim( (string) ( $pt->rest_namespace ?: 'wp/v2' ), '/' ) . '/' . ( $pt->rest_base ?: $pt->name );
		}
		foreach ( get_taxonomies( [ 'show_in_rest' => true ], 'objects' ) as $tx ) {
			$allow[] = '/' . trim( (string) ( $tx->rest_namespace ?: 'wp/v2' ), '/' ) . '/' . ( $tx->rest_base ?: $tx->name );
		}
		$allow = (array) apply_filters( 'launch_builder_rest_write_allow', $allow );
		$out = [];
		foreach ( $allow as $a ) {
			$a = '/' . trim( strtolower( (string) $a ), '/' );
			if ( '/' !== $a && ! preg_match( '#^/wp/v2/(users|plugins|themes|settings|block-directory)(/|$)#', $a ) ) {
				$out[] = $a; // Account, code and site-setting routes are never allow-listed, whatever a filter says.
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function rest_request( $input ) {
		$method = strtoupper( (string) ( $input['method'] ?? 'GET' ) );
		$route = '/' . ltrim( (string) ( $input['route'] ?? '' ), '/' );
		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) || '/' === $route || preg_match( '#[?\#%\\\\\s]|\.\.|//#', $route ) ) {
			return new \WP_Error( 'lb_bad_request', 'Bad method or route (no query strings, encoded or blank characters: use params).', [ 'status' => 400 ] );
		}
		$low = strtolower( $route );
		// Never: our own routes (the tools are called directly), the abilities runner, and batch requests (they would
		// carry any sub-request past the gate below).
		if ( preg_match( '#^/(launch-builder|wp-abilities|batch)(/|$)#', $low ) ) {
			return new \WP_Error( 'lb_refused', 'That route cannot be called through rest-request.', [ 'status' => 403 ] );
		}
		$params = (array) ( $input['params'] ?? [] );
		// Reading is open (core permission checks apply). Writing is an allow-list of content routes; everything else
		// (users, plugins, themes, core settings, other plugins' settings) and permanent deletes need power tools.
		if ( 'GET' !== $method && ! self::power() ) {
			$allowed = false;
			foreach ( self::rest_write_allow() as $prefix ) {
				if ( $low === $prefix || 0 === strpos( $low, $prefix . '/' ) ) {
					$allowed = true;
					break;
				}
			}
			$force = 'DELETE' === $method && ! empty( $params['force'] ) && 'false' !== $params['force'];
			if ( ! $allowed || $force ) {
				return new \WP_Error( 'lb_power_off', $force ? 'Permanent deletes (force) through rest-request are turned off; delete without force to move it to the trash, or an administrator can enable power tools under Launch Builder > Activity and safety.' : 'Writing to that route through rest-request is turned off: only content routes (posts, pages, entries, media, terms, menus, templates, Rank Math post meta) are open. Users, plugins, themes, core settings and other plugins\' settings need power tools, which an administrator can enable under Launch Builder > Activity and safety.', [ 'status' => 403 ] );
			}
		}
		$req = new \WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
			$req->set_body_params( $params );
		}
		$res = rest_do_request( $req );
		return [ 'status' => $res->get_status(), 'data' => rest_get_server()->response_to_data( $res, false ) ];
	}

	/** Reject hosts that resolve to private, loopback, link-local (cloud metadata) or carrier-grade NAT addresses. */
	private static function host_is_public( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : ( gethostbynamel( $host ) ?: [] );
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
			$long = ip2long( $ip );
			if ( false !== $long && $long >= ip2long( '100.64.0.0' ) && $long <= ip2long( '100.127.255.255' ) ) {
				return false;
			}
		}
		return true;
	}

	public static function sideload_media( $input ) {
		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) || ! self::host_is_public( $url ) ) {
			return new \WP_Error( 'lb_bad_url', 'That URL is not allowed (it must be a public http(s) image URL).', [ 'status' => 400 ] );
		}
		$pid = (int) ( $input['post_id'] ?? 0 );
		if ( $pid && ! current_user_can( 'edit_post', $pid ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot attach media to that post.', [ 'status' => 403 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$info = [];
		$id = Media::sideload( $url, $pid, sanitize_text_field( $input['title'] ?? '' ), (array) $input, $info ); // media_sideload_image() as before unless media hygiene applies.
		if ( is_wp_error( $id ) ) {
			if ( 0 === strpos( (string) $id->get_error_code(), 'lb_' ) ) { // Launch Builder's own refusals: size, megapixels, arguments.
				return $id;
			}
			return new \WP_Error( 'lb_import_failed', 'Could not import that image. Use a direct JPG, PNG, WebP or GIF URL.', [ 'status' => 400 ] ); // Generic on purpose: no upstream detail.
		}
		update_post_meta( $id, '_lb_src', $url );
		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}
		return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ] + $info; // The normalisation report, empty when it did not apply.
	}
}
