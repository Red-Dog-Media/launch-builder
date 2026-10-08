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
 */
final class Abilities {

	private const CAT = 'launch-builder';
	private const SNAP_OPTION = 'launch_builder_snapshots';

	/** @var array<string,array> name => definition */
	private static $defs = [];

	public static function boot(): void {
		if ( function_exists( 'wp_register_ability' ) ) { // WP 6.9+.
			add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'categories' ] );
			add_action( 'wp_abilities_api_init', [ __CLASS__, 'register' ] );
		}
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

		self::add( 'set-option', 'Write an option', 'Set a WordPress option. Snapshots the previous value. Site URL, admin email, roles and secrets are refused.',
			[ 'properties' => [ 'name' => [ 'type' => 'string' ], 'value' => [], 'label' => [ 'type' => 'string' ] ], 'required' => [ 'name', 'value' ] ], [ __CLASS__, 'set_option' ] );

		self::add( 'restore-snapshot', 'Restore a snapshot', 'Undo a previous set-theme-mods / set-option by snapshot id. Call with no id to list snapshots.',
			[ 'properties' => [ 'id' => [ 'type' => 'string' ] ] ], [ __CLASS__, 'restore_snapshot' ] );

		self::add( 'rest-request', 'Call any WordPress REST route', 'Run an internal REST request as the connected user: posts, pages, media, menus, settings, plugins, themes, templates, global styles, Rank Math, etc. Core permission checks apply.',
			[ 'properties' => [
				'method' => [ 'type' => 'string', 'enum' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ] ],
				'route'  => [ 'type' => 'string', 'description' => 'e.g. /wp/v2/pages' ],
				'params' => [ 'type' => 'object', 'description' => 'Query args for GET, JSON body otherwise.' ],
			], 'required' => [ 'method', 'route' ] ], [ __CLASS__, 'rest_request' ] );

		foreach ( Plugin::modules( 'define_tools' ) as $module ) { // Each module registers its own tools (Plugin::MODULES order).
			$module::define_tools();
		}

		self::add( 'sideload-media', 'Import an image from a URL', 'Download an image into the media library. Returns attachment id and URL.',
			[ 'properties' => [ 'url' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'alt' => [ 'type' => 'string' ], 'post_id' => [ 'type' => 'integer' ] ], 'required' => [ 'url' ] ], [ __CLASS__, 'sideload_media' ], 'upload_files' );
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

	/** May this option be read as data (for example as a Business Info source)? Same rules as get-option, minus site-critical names. */
	public static function option_readable( $name ): bool {
		$n = self::norm( $name );
		return '' !== $n && ! self::secret_key( $n )
			&& ! in_array( $n, [ 'siteurl', 'home', 'admin_email', 'new_admin_email', 'active_plugins', 'active_sitewide_plugins', self::SNAP_OPTION, 'launch_builder_log', 'launch_builder_power', 'launch_builder_mcp_token' ], true )
			&& ! preg_match( '/user_roles$|^widget_|^cron$|^recovery_/', $n ) && 0 !== strpos( $n, 'wp_' );
	}

	private static function secret_key( $name ): bool {
		return (bool) preg_match( '/(pass|secret|token|salt|auth_|_key|api_?key|private|stripe|paypal|smtp|mailgun|sendgrid|recaptcha|licen[sc]e|credential|jetpack_options)/i', (string) $name );
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

	public static function snapshot( string $label, array $mods = [], array $options = [] ): string {
		$snaps = (array) get_option( self::SNAP_OPTION, [] );
		$id = gmdate( 'ymd-His' ) . '-' . wp_generate_password( 4, false );
		$snap = [ 'id' => $id, 'time' => gmdate( 'c' ), 'label' => $label, 'mods' => [], 'options' => [] ];
		foreach ( $mods as $k ) {
			$snap['mods'][ $k ] = [ 'exists' => null !== get_theme_mod( $k, null ), 'value' => get_theme_mod( $k, null ) ];
		}
		foreach ( $options as $k ) {
			$sentinel = '__lb_absent__';
			$v = get_option( $k, $sentinel );
			$snap['options'][ $k ] = [ 'exists' => $v !== $sentinel, 'value' => $v === $sentinel ? null : $v ];
		}
		array_unshift( $snaps, $snap );
		update_option( self::SNAP_OPTION, array_slice( $snaps, 0, 30 ), false );
		return $id;
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
		$n = self::norm( $input['name'] ?? '' );
		if ( ! array_key_exists( 'value', (array) $input ) ) {
			return new \WP_Error( 'lb_value', 'value is required.', [ 'status' => 400 ] );
		}
		// LB's own bookkeeping is never written from outside: a faked ledger row would let LB overwrite an admin's edits, a
		// planted lock blocks jobs, and remove_all_data deletes Business Info on uninstall (it has its own admin switch).
		$deny = [ 'siteurl', 'home', 'admin_email', 'new_admin_email', 'default_role', 'users_can_register', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'permalink_structure', self::SNAP_OPTION, 'launch_builder_log', 'launch_builder_power', 'launch_builder_mcp_token', 'launch_builder_ledger', 'launch_builder_migrate_state', 'launch_builder_db_version', 'launch_builder_parked_elements', 'launch_builder_remove_all_data' ];
		$blocked = in_array( $n, $deny, true ) || (bool) preg_match( '/user_roles$|^widget_|^cron$|^recovery_|^launch_builder_(lock|ledger)_/', $n ) || ( 0 === strpos( $n, 'wp_' ) && 'wp_page_for_privacy_policy' !== $n );
		if ( '' === $n || self::secret_key( $n ) || $blocked ) {
			return new \WP_Error( 'lb_refused', 'That option can\'t be changed through this ability.', [ 'status' => 403 ] );
		}
		$snap = self::snapshot( $input['label'] ?? 'set-option', [], [ $n ] );
		update_option( $n, $input['value'] );
		return [ 'updated' => $n, 'snapshot' => $snap ];
	}

	public static function restore_snapshot( $input = [] ) {
		$snaps = (array) get_option( self::SNAP_OPTION, [] );
		$id = (string) ( $input['id'] ?? '' );
		if ( '' === $id ) {
			return [ 'snapshots' => array_map( static function ( $s ) { return [ 'id' => $s['id'], 'time' => $s['time'], 'label' => $s['label'], 'mods' => array_keys( $s['mods'] ), 'options' => array_keys( $s['options'] ) ]; }, $snaps ) ];
		}
		foreach ( $snaps as $s ) {
			if ( $s['id'] !== $id ) {
				continue;
			}
			foreach ( $s['mods'] as $k => $m ) {
				$m['exists'] ? set_theme_mod( $k, $m['value'] ) : remove_theme_mod( $k );
			}
			foreach ( $s['options'] as $k => $o ) {
				$o['exists'] ? update_option( $k, $o['value'] ) : delete_option( $k );
			}
			return [ 'restored' => $id ];
		}
		return new \WP_Error( 'lb_no_snapshot', 'Unknown snapshot id.', [ 'status' => 404 ] );
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

	public static function rest_request( $input ) {
		$method = strtoupper( (string) ( $input['method'] ?? 'GET' ) );
		$route = '/' . ltrim( (string) ( $input['route'] ?? '' ), '/' );
		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) || '/' === $route || preg_match( '#[?\#]|\.\.|//#', $route ) ) {
			return new \WP_Error( 'lb_bad_request', 'Bad method or route (no query strings: use params).', [ 'status' => 400 ] );
		}
		$low = strtolower( $route );
		if ( 0 === strpos( $low, '/launch-builder/' ) || 0 === strpos( $low, '/wp-abilities/' ) ) {
			return new \WP_Error( 'lb_refused', 'That route cannot be called through rest-request.', [ 'status' => 403 ] );
		}
		// Account, code and site-wide-setting routes are off unless an administrator turns on "power tools"
		// (Settings > Launch Builder). Reading them stays possible; use set-option / set-brand for supported changes.
		if ( ! self::power() && 'GET' !== $method && preg_match( '#^/wp/v2/(users|plugins|themes|settings)(/|$)#', $low ) ) {
			return new \WP_Error( 'lb_power_off', 'Changing users, plugins, themes or core settings through rest-request is turned off. An administrator can enable power tools under Settings > Launch Builder.', [ 'status' => 403 ] );
		}
		$req = new \WP_REST_Request( $method, $route );
		$params = (array) ( $input['params'] ?? [] );
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
		$id = media_sideload_image( $url, $pid, sanitize_text_field( $input['title'] ?? '' ), 'id' );
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'lb_import_failed', 'Could not import that image. Use a direct JPG, PNG, WebP or GIF URL.', [ 'status' => 400 ] ); // Generic on purpose: no upstream detail.
		}
		update_post_meta( $id, '_lb_src', $url );
		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}
		return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ];
	}
}
