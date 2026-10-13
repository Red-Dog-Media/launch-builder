<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The client experience of the Branding module: the "Site Owner" role, and what that role (and any other role added to
 * the client list) sees in wp-admin.
 *
 * The role is real: it simply lacks the dangerous capabilities (settings, plugins, themes, users, import and export,
 * the Customizer), so the menus of those screens vanish because the capability is missing, not because they are hidden.
 * Hiding is an extra layer on top: an allow-list of top-level menus, admin bar nodes and dashboard widgets per role,
 * so anything a new plugin adds later stays hidden from clients too.
 *
 * Administrators are never touched. Who counts as a client: a user whose role has a profile here AND who has neither
 * manage_options nor super admin rights. That makes a lock-out impossible by construction; the filter
 * launch_builder_branding_applies( bool, WP_User ) can only narrow it further.
 */
final class Branding_Client {

	/** Tags the welcome message may use. */
	const MESSAGE_TAGS = [
		'p'      => [],
		'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
		'strong' => [],
		'em'     => [],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
		'h3'     => [],
		'h4'     => [],
		'br'     => [],
	];

	/** What a Site Owner can do: write and publish posts, pages and entries, upload media, manage categories. */
	const BASE_CAPS = [
		'read', 'upload_files', 'manage_categories',
		'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts',
		'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'edit_private_pages', 'read_private_pages',
	];

	/** Capabilities the role can never hold: stripped when another plugin adds them, and never granted by a flag. */
	const DENY_CAPS = [
		'manage_options', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
		'install_themes', 'switch_themes', 'edit_themes', 'update_themes', 'delete_themes', 'update_core',
		'edit_users', 'create_users', 'delete_users', 'promote_users', 'remove_users', 'list_users',
		'unfiltered_upload', 'import', 'export', 'edit_files', 'edit_theme_options', 'manage_privacy_options', 'setup_network',
		'rank_math_role_manager', 'rank_math_general', 'rank_math_titles', 'rank_math_sitemap', 'rank_math_redirections',
	];

	/** Rank Math capabilities of the "basic" SEO box (the box in the editor, nothing from its settings screens). */
	const SEO_CAPS = [ 'rank_math_onpage_general', 'rank_math_onpage_analysis' ];

	const MENU_DEFAULT = [ 'index.php', 'edit.php?post_type=page', 'edit.php', 'edit.php?post_type=lb_service', 'edit.php?post_type=lb_area', 'upload.php', 'profile.php' ];

	const BAR_DEFAULT = [ 'menu-toggle', 'lb-brand', 'site-name', 'view-site', 'dashboard', 'view', 'preview', 'edit', 'new-content', 'new-post', 'new-page', 'new-media', 'new-lb_service', 'new-lb_area', 'my-account', 'user-actions', 'user-info', 'edit-profile', 'logout', 'top-secondary', 'search' ];

	/** Children of these admin bar nodes stay with their parent. */
	const BAR_CONTAINERS = [ 'new-content', 'my-account', 'user-actions', 'site-name' ];

	/** @var array<int,array|null> per-request memo: user id => profile or null */
	private static $memo = [];

	/** @var array|null per-request profiles */
	private static $profiles = null;

	public static function forget(): void {
		self::$memo = [];
		self::$profiles = null;
	}

	/* ------------------------------------------------------------------ who is a client */

	/**
	 * Role => { menus[], admin_bar[], widgets[] } for every role that is simplified: the Site Owner role (when the role
	 * is enabled) and the roles listed under client.roles.
	 *
	 * @return array<string,array{menus:string[],admin_bar:string[],widgets:string[]}>
	 */
	public static function profiles(): array {
		if ( null !== self::$profiles ) {
			return self::$profiles;
		}
		$c = Branding::section( 'client' );
		$d = Branding::section( 'dashboard' );
		$list = [];
		if ( ! empty( $c['role']['enabled'] ) ) {
			$list[ Branding::ROLE ] = [];
		}
		foreach ( (array) $c['roles'] as $role => $p ) {
			if ( null !== $p && 'administrator' !== $role ) {
				$list[ (string) $role ] = (array) $p;
			}
		}
		$comments = ! empty( $c['role']['flags']['comments'] );
		$out = [];
		foreach ( $list as $role => $p ) {
			$menus = isset( $p['menus'] ) ? (array) $p['menus'] : self::MENU_DEFAULT;
			$bar = isset( $p['admin_bar'] ) ? (array) $p['admin_bar'] : self::BAR_DEFAULT;
			if ( $comments && ! isset( $p['menus'] ) ) {
				$menus[] = 'edit-comments.php';
			}
			if ( $comments && ! isset( $p['admin_bar'] ) ) {
				$bar[] = 'comments';
			}
			$widgets = array_values( array_unique( array_merge( [ 'lb_welcome' ], (array) $d['client_widgets'], isset( $p['widgets'] ) ? (array) $p['widgets'] : [] ) ) );
			$out[ $role ] = [ 'menus' => array_values( array_unique( $menus ) ), 'admin_bar' => array_values( array_unique( $bar ) ), 'widgets' => $widgets ];
		}
		return self::$profiles = $out;
	}

	/**
	 * The simplification profile that applies to a user, or null (administrators, super admins, roles without a profile).
	 */
	public static function profile_for( $user = null ): ?array {
		$user = $user instanceof \WP_User ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! Branding::active() ) {
			return null;
		}
		if ( array_key_exists( $user->ID, self::$memo ) ) {
			return self::$memo[ $user->ID ];
		}
		$profile = null;
		if ( ! user_can( $user, 'manage_options' ) && ! ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			$profiles = self::profiles();
			foreach ( (array) $user->roles as $role ) {
				if ( isset( $profiles[ $role ] ) ) {
					$profile = $profiles[ $role ];
					break;
				}
			}
		}
		if ( null !== $profile && ! apply_filters( 'launch_builder_branding_applies', true, $user ) ) {
			$profile = null;
		}
		return self::$memo[ $user->ID ] = $profile;
	}

	public static function is_client( $user = null ): bool {
		return null !== self::profile_for( $user );
	}

	/* ------------------------------------------------------------------ the Site Owner role */

	/** The capabilities this module manages on the role, as cap => wanted. */
	public static function desired_caps( array $settings ): array {
		$flags = (array) ( $settings['client']['role']['flags'] ?? [] );
		$caps = array_fill_keys( self::BASE_CAPS, true );
		$caps['moderate_comments'] = ! empty( $flags['comments'] );
		$caps['unfiltered_html'] = ! empty( $flags['unfiltered_html'] );
		return $caps;
	}

	/**
	 * Create or update the Site Owner role from the settings. Only the managed capabilities change; capabilities another
	 * plugin added stay, except the ones on the deny list, which are stripped. Rank Math's capabilities are written when
	 * the role is created or when the SEO flag changes, so later edits in Rank Math's own role manager are respected.
	 *
	 * @return array{role:string,created:bool,label:string,added:string[],removed:string[],stripped:string[]}
	 */
	public static function sync_role( array $old, array $new, bool $force = false ): array {
		$cfg = (array) ( $new['client']['role'] ?? [] );
		$label = '' !== (string) ( $cfg['label'] ?? '' ) ? (string) $cfg['label'] : 'Site Owner';
		$want = self::desired_caps( $new );
		$report = [ 'role' => Branding::ROLE, 'created' => false, 'label' => $label, 'added' => [], 'removed' => [], 'stripped' => [] ];
		$role = get_role( Branding::ROLE );
		$seo_new = (string) ( $cfg['flags']['seo_box'] ?? 'none' );
		$seo_old = (string) ( $old['client']['role']['flags']['seo_box'] ?? 'none' );
		if ( ! $role ) {
			$initial = array_keys( array_filter( $want ) );
			if ( 'basic' === $seo_new ) {
				$initial = array_merge( $initial, self::SEO_CAPS );
			}
			add_role( Branding::ROLE, $label, array_fill_keys( $initial, true ) );
			$report['created'] = true;
			$report['added'] = $initial;
			return $report;
		}
		foreach ( $want as $cap => $on ) {
			$has = ! empty( $role->capabilities[ $cap ] );
			if ( $on && ! $has ) {
				$role->add_cap( $cap );
				$report['added'][] = $cap;
			} elseif ( ! $on && $has ) {
				$role->remove_cap( $cap );
				$report['removed'][] = $cap;
			}
		}
		if ( $force || $seo_new !== $seo_old ) {
			foreach ( self::SEO_CAPS as $cap ) {
				$has = ! empty( $role->capabilities[ $cap ] );
				if ( 'basic' === $seo_new && ! $has ) {
					$role->add_cap( $cap );
					$report['added'][] = $cap;
				} elseif ( 'basic' !== $seo_new && $has && $seo_new !== $seo_old ) {
					$role->remove_cap( $cap );
					$report['removed'][] = $cap;
				}
			}
		}
		foreach ( array_keys( array_filter( (array) $role->capabilities ) ) as $cap ) {
			if ( in_array( $cap, self::DENY_CAPS, true ) || 0 === strpos( (string) $cap, 'manage_network' ) ) {
				$role->remove_cap( $cap );
				$report['stripped'][] = $cap;
			}
		}
		$roles = wp_roles();
		if ( ( $roles->roles[ Branding::ROLE ]['name'] ?? '' ) !== $label ) {
			$roles->roles[ Branding::ROLE ]['name'] = $label;
			$roles->role_names[ Branding::ROLE ] = $label;
			update_option( $roles->role_key, $roles->roles );
		}
		return $report;
	}

	/**
	 * Migration step (every plugin version): keep the role in line with the settings. It answers in the shape Migrate
	 * documents (done, report); any other shape is "not done", and the runner would call the step again and again.
	 */
	public static function migrate_role( $cursor = null, int $batch = 50 ) {
		if ( Branding::active() && ! empty( Branding::section( 'client' )['role']['enabled'] ) ) {
			$report = self::sync_role( Branding::settings(), Branding::settings() );
			return [ 'done' => true, 'report' => $report ];
		}
		return [ 'done' => true, 'report' => [ 'skipped' => 'role not enabled' ] ];
	}

	/** The role as it is now. @return array{exists:bool,label:string,user_count:int,caps_extra:string[],flags:array} */
	public static function role_state(): array {
		$role = get_role( Branding::ROLE );
		$flags = (array) ( Branding::section( 'client' )['role']['flags'] ?? [] );
		if ( ! $role ) {
			return [ 'exists' => false, 'label' => (string) Branding::section( 'client' )['role']['label'], 'user_count' => 0, 'caps_extra' => [], 'flags' => $flags ];
		}
		$q = new \WP_User_Query( [ 'role' => Branding::ROLE, 'fields' => 'ID', 'number' => 1, 'count_total' => true ] );
		$managed = array_merge( self::BASE_CAPS, [ 'moderate_comments', 'unfiltered_html' ], self::SEO_CAPS );
		$extra = array_values( array_diff( array_keys( array_filter( (array) $role->capabilities ) ), $managed ) );
		return [
			'exists'     => true,
			'label'      => (string) ( wp_roles()->roles[ Branding::ROLE ]['name'] ?? Branding::ROLE ),
			'user_count' => (int) $q->get_total(),
			'caps_extra' => $extra,
			'flags'      => $flags,
			'caps_count' => count( array_filter( (array) $role->capabilities ) ),
		];
	}

	/* ------------------------------------------------------------------ wp-admin */

	/** Register the client rules (wp-admin requests only). Each callback checks who the current user is. */
	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menus' ], PHP_INT_MAX - 1 );
		add_action( 'current_screen', [ __CLASS__, 'screen' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_widgets' ], 1 );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'dashboard_setup' ], PHP_INT_MAX );
		add_action( 'in_admin_header', [ __CLASS__, 'nags' ], 0 );
		add_action( 'in_admin_header', [ __CLASS__, 'help' ], 1 );
		add_filter( 'screen_options_show_screen', [ __CLASS__, 'screen_options' ] );
		add_action( 'admin_notices', [ __CLASS__, 'hidden_notice' ] );
		add_action( 'admin_head-index.php', [ __CLASS__, 'dashboard_css' ] );
	}

	/** Remove the top-level menus a client's profile does not list, and tidy the separators that are left. */
	public static function menus(): void {
		global $menu, $submenu;
		$p = self::profile_for();
		if ( ! $p || ! is_array( $menu ) ) {
			return;
		}
		$allow = array_flip( $p['menus'] );
		foreach ( $menu as $k => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false !== strpos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' ) ) {
				continue;
			}
			if ( ! isset( $allow[ $slug ] ) ) {
				unset( $menu[ $k ] );
			}
		}
		if ( ! empty( Branding::section( 'client' )['blocks_home_off'] ) ) {
			remove_submenu_page( 'kadence-blocks', 'kadence-blocks-home' );
		}
		$last_sep = true; // No separator first, never two in a row, none last.
		foreach ( $menu as $k => $item ) {
			$sep = false !== strpos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' );
			if ( $sep && $last_sep ) {
				unset( $menu[ $k ] );
				continue;
			}
			$last_sep = $sep;
		}
		foreach ( array_reverse( array_keys( $menu ), true ) as $k ) {
			if ( false !== strpos( (string) ( $menu[ $k ][4] ?? '' ), 'wp-menu-separator' ) ) {
				unset( $menu[ $k ] );
			} else {
				break;
			}
		}
	}

	/** current_screen: help tabs, and the guard that sends a client away from a screen outside the allow-list. */
	public static function screen( $screen ): void {
		$p = self::profile_for();
		if ( ! $p || ! $screen instanceof \WP_Screen ) {
			return;
		}
		$c = Branding::section( 'client' );
		if ( empty( $c['block_hidden_screens'] ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( self::screen_allowed( $screen, $p['menus'] ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'lb_hidden', '1', admin_url( 'index.php' ) ) );
		exit;
	}

	/**
	 * Is a screen part of a client's menu? A plugin page (?page=) must be one of the allowed menu slugs or a submenu of
	 * one. Screens without ?page= are the core ones: the dashboard, the profile, media, comments (when allowed), and the
	 * list and editor screens of the allowed content types and their taxonomies. Everything else (users, plugins,
	 * themes, settings, tools, menus, widgets...) is outside.
	 *
	 * @param string[] $menus the allowed top-level menu slugs
	 */
	public static function screen_allowed( \WP_Screen $screen, array $menus ): bool {
		global $submenu;
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only decides where to send the user.
		if ( '' !== $page ) {
			if ( in_array( $page, $menus, true ) || in_array( 'admin.php?page=' . $page, $menus, true ) ) {
				return true;
			}
			foreach ( $menus as $parent ) {
				foreach ( (array) ( $submenu[ $parent ] ?? [] ) as $item ) {
					if ( isset( $item[2] ) && $page === $item[2] ) {
						return true;
					}
				}
			}
			return false;
		}
		$type_ok = static function ( string $type ) use ( $menus ): bool {
			if ( 'post' === $type ) {
				return in_array( 'edit.php', $menus, true );
			}
			if ( 'attachment' === $type ) {
				return in_array( 'upload.php', $menus, true );
			}
			return in_array( 'edit.php?post_type=' . $type, $menus, true );
		};
		switch ( $screen->base ) {
			case 'dashboard':
			case 'profile':
			case 'user-edit':
			case 'async-upload':
				return true;
			case 'upload':
			case 'media':
				return in_array( 'upload.php', $menus, true );
			case 'edit':
			case 'post':
				return $type_ok( (string) ( $screen->post_type ? $screen->post_type : 'post' ) );
			case 'edit-tags':
			case 'term':
				$tax = $screen->taxonomy ? get_taxonomy( $screen->taxonomy ) : null;
				foreach ( $tax ? (array) $tax->object_type : [ 'post' ] as $type ) {
					if ( $type_ok( (string) $type ) ) {
						return true;
					}
				}
				return false;
			case 'edit-comments':
			case 'comment':
				return in_array( 'edit-comments.php', $menus, true );
		}
		return false;
	}

	public static function hidden_notice(): void {
		if ( isset( $_GET['lb_hidden'] ) && self::is_client() ) { // phpcs:ignore WordPress.Security.NonceVerification -- only shows a message.
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'That screen is not part of your account. If you need it, ask the team that manages your website.', 'launch-builder' ) . '</p></div>';
		}
	}

	public static function screen_options( $show ) {
		return self::is_client() && ! empty( Branding::section( 'client' )['hide_screen_options'] ) ? false : $show;
	}

	/** The Help tab goes for clients (core pages add their help tabs after current_screen, so this runs in the page header). */
	public static function help(): void {
		$screen = get_current_screen();
		if ( $screen && self::is_client() && ! empty( Branding::section( 'client' )['hide_help'] ) ) {
			$screen->remove_help_tabs();
			$screen->set_help_sidebar( '' ); // the sidebar carries the WordPress version and support links
		}
	}

	/** Notices for clients: everything is dropped except the ones about their own account and this plugin's own. */
	public static function nags(): void {
		if ( ! self::is_client() || empty( Branding::section( 'client' )['hide_nags'] ) ) {
			return;
		}
		global $wp_filter;
		foreach ( [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ] as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( ! self::keep_notice( $cb['function'] ) ) {
						$wp_filter[ $hook ]->remove_filter( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	/** Notices that stay: the user's own account (default password, pending email change) and this plugin's. */
	private static function keep_notice( $fn ): bool {
		if ( is_string( $fn ) ) {
			return in_array( $fn, [ 'default_password_nag', 'new_user_email_admin_notice' ], true );
		}
		if ( is_array( $fn ) && isset( $fn[0] ) ) {
			$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
			return 0 === strpos( ltrim( $class, '\\' ), __NAMESPACE__ . '\\' );
		}
		return false;
	}

	/** Kadence Blocks' AI features off for clients (the filter is not defined per user as a constant would be). */
	public static function kadence_ai_off( $disabled ) {
		return self::is_client() && ! empty( Branding::section( 'client' )['ai_off'] ) ? true : $disabled;
	}

	/* ------------------------------------------------------------------ admin bar */

	/** Called from the admin bar init: clients get the allow-list. */
	public static function bar_init(): void {
		if ( self::is_client() ) {
			add_action( 'wp_before_admin_bar_render', [ __CLASS__, 'bar_filter' ] );
		}
	}

	/** Remove every admin bar node a client's profile does not list (children of a listed container stay). */
	public static function bar_filter(): void {
		global $wp_admin_bar;
		$p = self::profile_for();
		if ( ! $p || ! $wp_admin_bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$allow = array_flip( $p['admin_bar'] );
		foreach ( $wp_admin_bar->get_nodes() as $id => $node ) {
			if ( isset( $allow[ $id ] ) ) {
				continue;
			}
			if ( $node->parent && isset( $allow[ $node->parent ] ) && in_array( $node->parent, self::BAR_CONTAINERS, true ) ) {
				continue;
			}
			$wp_admin_bar->remove_node( $id );
		}
	}

	/* ------------------------------------------------------------------ dashboard */

	public static function register_widgets(): void {
		if ( ! Branding::active() ) {
			return;
		}
		$d = Branding::section( 'dashboard' );
		if ( ! empty( $d['welcome']['enabled'] ) && self::welcome_visible() ) {
			$title = '' !== $d['welcome']['title'] ? $d['welcome']['title'] : __( 'Welcome', 'launch-builder' );
			wp_add_dashboard_widget( 'lb_welcome', esc_html( $title ), [ __CLASS__, 'render_welcome' ], null, null, 'normal', 'high' );
		}
		if ( ! empty( $d['lb_widget'] ) && current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'lb_overview', esc_html__( 'Launch Builder', 'launch-builder' ), [ __CLASS__, 'render_overview' ] );
		}
	}

	/** Does the welcome panel show for the current user? (The roles listed, or the client roles when none are listed.) */
	private static function welcome_visible(): bool {
		$roles = (array) Branding::section( 'dashboard' )['welcome']['roles'];
		if ( ! $roles ) {
			return self::is_client();
		}
		$user = wp_get_current_user();
		return $user->exists() && (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * wp_dashboard_setup (last): a client keeps only the widgets in their profile, all in one column; and the widgets
	 * named under dashboard.admin_remove go for everyone who is not a client (Site Health and the PHP notice never).
	 */
	public static function dashboard_setup(): void {
		global $wp_meta_boxes;
		if ( ! Branding::active() || empty( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}
		$p = self::profile_for();
		$remove = $p ? [] : array_diff( (array) Branding::section( 'dashboard' )['admin_remove'], Branding::KEEP_WIDGETS );
		if ( ! $p && ! $remove ) {
			return;
		}
		$allow = $p ? array_flip( $p['widgets'] ) : [];
		$kept = [];
		foreach ( (array) $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( $p ? ! isset( $allow[ $id ] ) : in_array( $id, $remove, true ) ) {
						unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $id ] );
					} elseif ( $p && is_array( $box ) ) {
						$kept[ $priority ][ $id ] = $box;
						unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $id ] );
					}
				}
			}
		}
		if ( $p ) {
			foreach ( [ 'high', 'core', 'default', 'low' ] as $prio ) { // One column: everything that stays goes to the normal column.
				foreach ( $kept[ $prio ] ?? [] as $id => $box ) {
					$wp_meta_boxes['dashboard']['normal'][ $prio ][ $id ] = $box;
				}
			}
		}
	}

	/** Clients see one full-width column on the dashboard. */
	public static function dashboard_css(): void {
		if ( self::is_client() ) {
			echo '<style>#wpbody-content #dashboard-widgets .postbox-container{width:100%;float:none}#wpbody-content #dashboard-widgets #postbox-container-2,#wpbody-content #dashboard-widgets #postbox-container-3,#wpbody-content #dashboard-widgets #postbox-container-4{display:none}</style>';
		}
	}

	/** The tiles of the welcome panel, as label, URL pairs the current user may use. @return array<string,array{0:string,1:string}> */
	private static function tiles(): array {
		$all = [];
		if ( current_user_can( 'edit_pages' ) ) {
			$all['pages'] = [ __( 'Edit pages', 'launch-builder' ), admin_url( 'edit.php?post_type=page' ) ];
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$all['posts'] = [ __( 'Write a post', 'launch-builder' ), admin_url( 'post-new.php' ) ];
		}
		foreach ( [ 'services' => [ Entries::SERVICE, __( 'Services', 'launch-builder' ) ], 'areas' => [ Entries::AREA, __( 'Service areas', 'launch-builder' ) ] ] as $key => [ $type, $label ] ) {
			if ( post_type_exists( $type ) && current_user_can( 'edit_posts' ) ) {
				$all[ $key ] = [ $label, admin_url( 'edit.php?post_type=' . $type ) ];
			}
		}
		if ( current_user_can( 'upload_files' ) ) {
			$all['media'] = [ __( 'Photos and files', 'launch-builder' ), admin_url( 'upload.php' ) ];
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			$all['comments'] = [ __( 'Comments', 'launch-builder' ), admin_url( 'edit-comments.php' ) ];
		}
		$all['site'] = [ __( 'View your website', 'launch-builder' ), home_url( '/' ) ];
		$all['profile'] = [ __( 'Your profile', 'launch-builder' ), admin_url( 'profile.php' ) ];
		return $all;
	}

	/** The client welcome panel: greeting, quick actions, the agency's support card and an optional message. */
	public static function render_welcome(): void {
		$d = Branding::section( 'dashboard' )['welcome'];
		$user = wp_get_current_user();
		$first = (string) get_user_meta( $user->ID, 'first_name', true );
		$first = '' !== $first ? $first : $user->display_name;
		$business = Branding::business_name()[0];
		$vars = Branding_Chrome::login_vars( Branding::section( 'login' ) )['colors'];
		$accent = $vars['link'];
		echo '<style>#lb_welcome .lb-welcome{--lbw:' . esc_attr( $accent ) . '}#lb_welcome .lb-welcome h3{margin:0 0 4px;font-size:18px}#lb_welcome .lb-welcome-biz{margin:0 0 12px;color:#50575e}#lb_welcome .lb-welcome h4{margin:16px 0 8px;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#50575e}#lb_welcome .lb-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:0;padding:0;list-style:none}#lb_welcome .lb-tiles a{display:block;padding:12px 14px;border:1px solid #dcdcde;border-left:4px solid var(--lbw);border-radius:6px;background:#fff;color:#1d2327;font-weight:600;text-decoration:none}#lb_welcome .lb-tiles a:hover,#lb_welcome .lb-tiles a:focus-visible{border-color:var(--lbw);background:#f6f7f7}#lb_welcome .lb-support{margin-top:16px;padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7}#lb_welcome .lb-support p{margin:2px 0}</style>';
		echo '<div class="lb-welcome"><h3>' . sprintf( /* translators: %s: first name */ esc_html__( 'Welcome, %s', 'launch-builder' ), esc_html( $first ) ) . '</h3>';
		echo '<p class="lb-welcome-biz">' . esc_html( $business ) . '</p>';
		if ( '' !== $d['message'] ) {
			echo wp_kses( $d['message'], self::MESSAGE_TAGS, [ 'http', 'https', 'mailto', 'tel' ] );
		}
		$tiles = self::tiles();
		$shown = array_filter( (array) $d['tiles'], static function ( $k ) use ( $tiles ) { return isset( $tiles[ $k ] ); } );
		if ( $shown ) {
			echo '<h4>' . esc_html__( 'Quick actions', 'launch-builder' ) . '</h4><ul class="lb-tiles">';
			foreach ( $shown as $k ) {
				echo '<li><a href="' . esc_url( $tiles[ $k ][1] ) . '">' . esc_html( $tiles[ $k ][0] ) . '</a></li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $d['support_card'] ) ) {
			$a = Branding::agency();
			$tel = preg_replace( '/[^0-9+]/', '', $a['support_phone'] );
			echo '<div class="lb-support"><strong>' . sprintf( /* translators: %s: agency name */ esc_html__( 'Need a hand? %s is here to help.', 'launch-builder' ), esc_html( $a['name'] ) ) . '</strong>';
			if ( '' !== $tel ) {
				echo '<p><a href="' . esc_url( 'tel:' . $tel ) . '">' . esc_html( $a['support_phone'] ) . '</a></p>';
			}
			if ( '' !== $a['support_email'] ) {
				echo '<p><a href="' . esc_url( 'mailto:' . $a['support_email'] ) . '">' . esc_html( $a['support_email'] ) . '</a></p>';
			}
			if ( '' !== $a['support_url'] ) {
				echo '<p><a href="' . esc_url( $a['support_url'] ) . '" rel="noopener">' . esc_html__( 'Contact support', 'launch-builder' ) . '</a></p>';
			}
			if ( '' !== $a['booking_url'] ) {
				echo '<p><a href="' . esc_url( $a['booking_url'] ) . '" rel="noopener">' . esc_html__( 'Book a call', 'launch-builder' ) . '</a></p>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/** A small overview for administrators: which modules are on, and the shortcuts. */
	public static function render_overview(): void {
		$on = 0;
		$total = 0;
		foreach ( Modules::all() as $m ) {
			if ( $m['toggle'] ) {
				$total++;
				$on += $m['enabled'] ? 1 : 0;
			}
		}
		echo '<p>' . sprintf( /* translators: 1: number on, 2: number of optional modules */ esc_html__( '%1$d of %2$d optional modules are on.', 'launch-builder' ), (int) $on, (int) $total ) . '</p><ul>';
		foreach ( [ 'launch-builder-business' => __( 'Business Info', 'launch-builder' ), Branding::PAGE => __( 'Branding', 'launch-builder' ), 'launch-builder-modules' => __( 'Modules', 'launch-builder' ), 'launch-builder-health' => __( 'Site health', 'launch-builder' ) ] as $page => $label ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
	}
}
