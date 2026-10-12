<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Login links: expiring one-click sign-in links for client accounts (a secure replacement for older plain-text login-link
 * plugins). Off until an administrator turns it on.
 *
 * Link:    <wp_login_url>?action=lb-link&k=lbl1.<selector>.<secret>  (selector 12 and secret 43 base64url characters)
 * Storage: one protected usermeta row per link, `_lb_login_link_{blog}_{selector}`, holding an HMAC of the secret keyed
 *          with a key derived from the site's auth salt (bound to the blog and the account), never the secret itself.
 *          The plaintext link exists only in the response that created it (or the email it was sent in).
 * Flow:    everything runs inside wp-login.php (action lb-link), so normal page views pay nothing.
 *          A. GET with k: the token is checked (nothing is consumed), moved into a signed 10-minute cookie bound to the
 *             link, and the browser is sent (303) to the clean URL, so the token leaves the address bar at once.
 *          B. GET without k: a page names the account and asks for one click. Link scanners and prefetchers stop here.
 *             This page alone uses Referrer-Policy same-origin (its URL holds no token), so browsers send its real
 *             Origin with the click; under no-referrer they would send "Origin: null".
 *          C. POST from that page (same-origin, pending cookie, form token): every check runs again, one use is consumed
 *             with a compare-and-swap, the standard WordPress session cookie is set, wp_login fires, and the browser is
 *             redirected (303) to a same-site target.
 * Policy (at creation and again at every use): the creator still has `lb_manage_login_links` (edit_users) and edit_user
 * on the account; the account is not the creator, not a network super admin, a member of this site, has no capability
 * the creator lacks, is admin-level only when the site setting allows it, and has no two-factor login unless a filter
 * allows it. Rate limits: per IP (10 failures / 15 min, then 429 for invalid attempts; a valid link still passes) and
 * per link (5 wrong secrets lock it for 15 min), in expiring transients (never autoloaded). Every creation, use,
 * revocation, extension and link lock goes to the Launch Builder activity log; failures and IP throttles caused by
 * signed-out visitors only within a small site-wide budget (log_budget()), so nobody can push the other rows out of
 * that shared 200-row log by failing on purpose.
 *
 * Off: boot() registers nothing (with the Modules registry the module is not even booted). Login_Links_Guard (below,
 * always on and tiny) keeps what must work while it is off: the LB_LOGIN_LINKS constant, the old-plugin notice and
 * audit check, site-state, the WP-CLI command and the screen that says how to turn the module on.
 *
 * Not on MCP, on purpose: the MCP token already acts as an administrator, so a create tool would let a leaked token or a
 * prompt-injected agent mint browser sessions as any user (and skip two-factor), and the plaintext link would pass
 * through the AI transcript and provider logs. Links are made by a person in wp-admin or with WP-CLI.
 */
final class Login_Links {

	/** Modules contract: toggleable, off until an administrator turns it on. */
	const MODULE = [
		'slug'        => 'login-links',
		'label'       => 'Login links',
		'description' => 'Expiring, revocable one-click sign-in links for client accounts (Users > Login links). Hashed, rate limited, logged, never on MCP.',
		'toggle'      => true,
		'default'     => false,
		'note'        => 'Turning it off stops every link at once; links still valid work again when it is turned back on. LB_LOGIN_LINKS in wp-config.php overrides this switch (true = on, false = off).',
	];

	const OPT_ENABLED = 'launch_builder_login_links_enabled'; // '0' | '1' (autoloaded): the switch until the Modules registry owns it.
	const OPT         = 'launch_builder_login_links';         // settings (not autoloaded)
	const META_PREFIX = '_lb_login_link_';                    // + {blog}_{selector}
	const ACTION      = 'lb-link';
	const COOKIE      = 'lb_link_pending';
	const PREFIX      = 'lbl1';
	const TOKEN_RE    = '/^lbl1\.([A-Za-z0-9_-]{12})\.([A-Za-z0-9_-]{43})$/';
	const SEL_RE      = '/^[A-Za-z0-9_-]{12}$/';
	const HARD_MAX    = 30;       // days, never raised by a filter
	const PENDING_TTL = 600;      // seconds between opening the link and clicking Continue
	const WINDOW      = 900;      // throttle window, seconds
	const IP_LIMIT    = 10;       // failures per IP per window before invalid attempts get 429
	const LINK_LIMIT  = 5;        // wrong secrets per link per window before the link locks
	const USER_CAP    = 5;        // active links per account
	const SITE_CAP    = 200;      // active links per site
	const LOG_CAP     = 5;        // activity log rows signed-out visitors can cause (failures, IP throttles) per window, site-wide
	const LOG_DAY     = 20;       // ... and per UTC day; past either budget one row a day says the rest are only counted
	const KEEP_DAYS   = 30;       // expired / revoked / used-up rows are deleted this long after
	const GC_HOOK     = 'launch_builder_login_links_gc';
	const CAP         = 'lb_manage_login_links';
	const PAGE        = 'lb-login-links';

	/** Capabilities that make an account admin-level (links to it need the allow_admin_targets setting). */
	const ADMIN_CAPS = [ 'manage_options', 'edit_users', 'create_users', 'promote_users', 'list_users', 'delete_users', 'remove_users', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'install_themes', 'update_themes', 'delete_themes', 'switch_themes', 'edit_themes', 'edit_files', 'update_core' ];

	const DEFAULTS = [
		'default_days'              => 7,
		'allow_admin_targets'       => false,
		'revoke_on_password_change' => true,
		'revoke_on_role_change'     => true,
	];

	/** @var array|null result of a create form post, shown once by page() */
	private static $created = null;

	/** @var string selector tagged onto the session being created */
	private static $session_tag = '';

	/** @var string Referrer-Policy of the lb-link page being rendered (meta_referrer() prints it) */
	private static $referrer = 'no-referrer';

	/** @var bool boot() ran with the module on */
	private static $booted = false;

	/* ------------------------------------------------------------------ module */

	/**
	 * On or off. The constant LB_LOGIN_LINKS wins (false = hard kill switch); then the Modules registry when it exists
	 * (Modules::enabled( class ), which also keeps a disabled module from booting at all); otherwise this module's own
	 * option, off by default.
	 */
	public static function enabled(): bool {
		if ( defined( 'LB_LOGIN_LINKS' ) ) {
			return (bool) LB_LOGIN_LINKS;
		}
		if ( self::registry() ) {
			try {
				return (bool) call_user_func( [ __NAMESPACE__ . '\\Modules', 'enabled' ], self::class );
			} catch ( \Throwable $e ) {
				return false; // Registry present but unreadable: stay off.
			}
		}
		return '1' === (string) get_option( self::OPT_ENABLED, '0' );
	}

	/** Does the shared Modules registry own the on/off switch? */
	private static function registry(): bool {
		return class_exists( __NAMESPACE__ . '\\Modules' ) && is_callable( [ __NAMESPACE__ . '\\Modules', 'enabled' ] );
	}

	/**
	 * Filter launch_builder_module_enabled (added by Login_Links_Guard): LB_LOGIN_LINKS in wp-config.php decides for this
	 * module in the Modules registry too (screen, site-state, which module hooks run).
	 */
	public static function module_switch( $on, $slug = '', $class = '' ) {
		return ( self::MODULE['slug'] === $slug || ltrim( (string) $class, '\\' ) === self::class ) && defined( 'LB_LOGIN_LINKS' ) ? (bool) LB_LOGIN_LINKS : $on;
	}

	/** Where an administrator turns the module on (shown while it is off). */
	public static function how_to_enable(): string {
		if ( defined( 'LB_LOGIN_LINKS' ) ) {
			return __( 'The LB_LOGIN_LINKS constant in wp-config.php controls this module (true = on, false = off).', 'launch-builder' );
		}
		if ( self::registry() ) {
			return __( 'Turn it on under Launch Builder > Modules (WP-CLI: wp launch-builder modules enable login-links --user=<admin>).', 'launch-builder' );
		}
		return __( 'Turn it on under Users > Login links (WP-CLI: wp option update launch_builder_login_links_enabled 1).', 'launch-builder' );
	}

	/**
	 * Module hook. Registers the sign-in action and everything that serves it, only while the module is on (once per
	 * request: Login_Links_Guard calls it too, for LB_LOGIN_LINKS = true when the registry decided before its filter).
	 */
	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return; // Off: no wp-login action, so every link stops working (and starts again if the module is turned back on).
		}
		self::$booted = true;
		add_action( 'login_form_' . self::ACTION, [ __CLASS__, 'landing' ] );
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_cap' ], 10, 2 );
		add_action( self::GC_HOOK, [ __CLASS__, 'gc' ] );
		add_action( 'admin_init', [ __CLASS__, 'schedule' ] );
		// Automatic revocation.
		add_action( 'after_password_reset', [ __CLASS__, 'on_password_reset' ], 10, 1 );
		add_action( 'wp_set_password', [ __CLASS__, 'on_set_password' ], 10, 2 );
		add_action( 'profile_update', [ __CLASS__, 'on_profile_update' ], 10, 3 );
		add_action( 'set_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
		add_action( 'add_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
		add_action( 'remove_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
		add_action( 'remove_user_from_blog', [ __CLASS__, 'on_remove_from_blog' ], 10, 2 );
		// wp-admin.
		add_filter( 'user_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_action( 'show_user_profile', [ __CLASS__, 'profile_section' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_section' ] );
		add_action( 'admin_post_lb_ll_revoke', [ __CLASS__, 'handle_revoke' ] );
		add_action( 'admin_post_lb_ll_extend', [ __CLASS__, 'handle_extend' ] );
		add_action( 'admin_post_lb_ll_settings', [ __CLASS__, 'handle_settings' ] );
	}

	/** Module hook: plugin deactivated, or the module switched off in the Modules registry. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::GC_HOOK );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::GC_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( wp_next_scheduled( self::GC_HOOK ) ) {
			wp_clear_scheduled_hook( self::GC_HOOK );
		}
	}

	/** `lb_manage_login_links` = edit_users (filter launch_builder_login_links_cap). On multisite that means super admins. */
	public static function map_cap( $caps, $cap ) {
		if ( self::CAP !== $cap ) {
			return $caps;
		}
		return [ (string) apply_filters( 'launch_builder_login_links_cap', 'edit_users' ) ];
	}

	/** @return array{default_days:int,allow_admin_targets:bool,revoke_on_password_change:bool,revoke_on_role_change:bool} */
	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		$s = ( is_array( $s ) ? $s : [] ) + self::DEFAULTS;
		return [
			'default_days'              => max( 1, min( self::max_days(), (int) $s['default_days'] ) ),
			'allow_admin_targets'       => (bool) $s['allow_admin_targets'],
			'revoke_on_password_change' => (bool) $s['revoke_on_password_change'],
			'revoke_on_role_change'     => (bool) $s['revoke_on_role_change'],
		];
	}

	/** Longest lifetime in days: 30, or less through the filter launch_builder_login_links_max_ttl (never more). */
	public static function max_days(): int {
		return max( 1, min( self::HARD_MAX, (int) apply_filters( 'launch_builder_login_links_max_ttl', self::HARD_MAX ) ) );
	}

	/* ------------------------------------------------------------------ tokens and storage */

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- token encoding.
	}

	/** HMAC key: derived from the auth salt, so rotating the salts in wp-config.php ends every link. */
	private static function key(): string {
		return hash_hmac( 'sha256', 'launch-builder|login-links|v1', wp_salt( 'auth' ) );
	}

	/** What is stored instead of the secret. Bound to the site and the account, so a row moved to another user is dead. */
	private static function verifier( int $blog, int $user_id, string $selector, string $secret ): string {
		return hash_hmac( 'sha256', self::PREFIX . '|' . $blog . '|' . $user_id . '|' . $selector . '|' . $secret, self::key() );
	}

	private static function meta_key( string $selector, int $blog = 0 ): string {
		return self::META_PREFIX . ( $blog ? $blog : get_current_blog_id() ) . '_' . $selector;
	}

	/** A stored record, or null when the value is not ours. Never instantiates objects. */
	private static function decode( $raw ): ?array {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return null;
		}
		$v = @unserialize( trim( $raw ), [ 'allowed_classes' => false ] ); // phpcs:ignore -- our own row, objects refused.
		return is_array( $v ) && 1 === (int) ( $v['v'] ?? 0 ) && isset( $v['verifier'], $v['expires_at'] ) ? $v : null;
	}

	/** @return array|null {umeta_id, user_id, raw, rec, selector} */
	private static function row_from_db( array $r, int $blog ): ?array {
		$prefix = self::META_PREFIX . $blog . '_';
		if ( 0 !== strpos( (string) $r['meta_key'], $prefix ) ) {
			return null;
		}
		$selector = substr( (string) $r['meta_key'], strlen( $prefix ) );
		$rec = self::decode( $r['meta_value'] );
		if ( ! $rec || ! preg_match( self::SEL_RE, $selector ) ) {
			return null;
		}
		return [ 'umeta_id' => (int) $r['umeta_id'], 'user_id' => (int) $r['user_id'], 'raw' => (string) $r['meta_value'], 'rec' => $rec, 'selector' => $selector ];
	}

	/** One link of this site by selector (direct query: meta_key index, no stale object cache, exact-case match). */
	private static function find( string $selector ): ?array {
		global $wpdb;
		if ( ! preg_match( self::SEL_RE, $selector ) ) {
			return null;
		}
		$blog = get_current_blog_id();
		$key = self::meta_key( $selector, $blog );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 2", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			if ( $r['meta_key'] === $key ) {
				return self::row_from_db( $r, $blog );
			}
		}
		return null;
	}

	/** Links of this site (newest first), optionally of one account. */
	private static function rows( int $user_id = 0 ): array {
		global $wpdb;
		$blog = get_current_blog_id();
		$like = $wpdb->esc_like( self::META_PREFIX . $blog . '_' ) . '%';
		$sql = $user_id
			? $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY umeta_id DESC", $user_id, $like )
			: $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY umeta_id DESC LIMIT 2000", $like );
		$out = [];
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = self::row_from_db( $r, $blog );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Change a record atomically: compare-and-swap on the stored value, retried from a fresh read when another request
	 * changed it first. $fn( $row ) returns the new record or a WP_Error. @return array|\WP_Error the updated row
	 */
	private static function mutate( string $selector, callable $fn ) {
		global $wpdb;
		for ( $try = 0; $try < 3; $try++ ) {
			$row = self::find( $selector );
			if ( ! $row ) {
				return new \WP_Error( 'lb_ll_not_found', __( 'No such login link.', 'launch-builder' ) );
			}
			$rec = $fn( $row );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
			$rec['rev'] = (int) ( $row['rec']['rev'] ?? 0 ) + 1;
			$raw = serialize( $rec ); // phpcs:ignore -- same format add_user_meta stores.
			$n = $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $raw ], [ 'umeta_id' => $row['umeta_id'], 'meta_value' => $row['raw'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $row['user_id'], 'user_meta' );
			if ( 1 === (int) $n ) {
				$row['rec'] = $rec;
				$row['raw'] = $raw;
				return $row;
			}
		}
		return new \WP_Error( 'lb_ll_busy', __( 'The link changed while it was being used. Try again.', 'launch-builder' ) );
	}

	/** active | revoked | used | expired */
	public static function status( array $rec, int $now = 0 ): string {
		$now = $now ? $now : time();
		if ( ! empty( $rec['revoked_at'] ) ) {
			return 'revoked';
		}
		if ( (int) $rec['max_uses'] > 0 && (int) $rec['uses'] >= (int) $rec['max_uses'] ) {
			return 'used';
		}
		return (int) $rec['expires_at'] <= $now ? 'expired' : 'active';
	}

	/** What may be shown or returned about a link: never the verifier. */
	public static function public_record( array $row ): array {
		$r = $row['rec'];
		$u = get_userdata( $row['user_id'] );
		$c = get_userdata( (int) $r['created_by'] );
		return [
			'id'               => $row['selector'],
			'user_id'          => $row['user_id'],
			'user_login'       => $u ? $u->user_login : '',
			'created_by'       => (int) $r['created_by'],
			'created_by_login' => $c ? $c->user_login : '',
			'created_at'       => (int) $r['created_at'],
			'expires_at'       => (int) $r['expires_at'],
			'max_uses'         => (int) $r['max_uses'],
			'uses'             => (int) $r['uses'],
			'last_used_at'     => (int) ( $r['last_used_at'] ?? 0 ),
			'last_ip'          => (string) ( $r['last_ip'] ?? '' ),
			'redirect'         => (string) ( $r['redirect'] ?? '' ),
			'note'             => (string) ( $r['note'] ?? '' ),
			'status'           => self::status( $r ),
			'revoked_at'       => (int) ( $r['revoked_at'] ?? 0 ),
			'revoked_by'       => (int) ( $r['revoked_by'] ?? 0 ),
			'revoked_reason'   => (string) ( $r['revoked_reason'] ?? '' ),
		];
	}

	/* ------------------------------------------------------------------ policy */

	/** Admin-level account: holds any of ADMIN_CAPS or a manage_network* capability (primitive caps, not mapped). */
	public static function is_admin_level( \WP_User $user ): bool {
		foreach ( array_keys( array_filter( (array) $user->allcaps ) ) as $cap ) {
			if ( in_array( $cap, self::ADMIN_CAPS, true ) || 0 === strpos( (string) $cap, 'manage_network' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the account use two-factor login? Built-in detectors for Two Factor (verified against its source), Wordfence
	 * Login Security, WP 2FA, Solid Security and SiteGround Security; a detector whose plugin is present but whose API
	 * cannot be called counts as "yes" (fail closed). Filter launch_builder_login_links_user_has_2fa( $has, $user ).
	 */
	public static function has_2fa( \WP_User $user ): bool {
		$has = false;
		try {
			if ( class_exists( '\Two_Factor_Core' ) ) {
				$has = ! is_callable( [ '\Two_Factor_Core', 'is_user_using_two_factor' ] ) || \Two_Factor_Core::is_user_using_two_factor( $user->ID );
			}
			if ( ! $has && class_exists( '\WordfenceLS\Controller_Users' ) ) {
				$c = is_callable( [ '\WordfenceLS\Controller_Users', 'shared' ] ) ? \WordfenceLS\Controller_Users::shared() : null;
				$has = ! ( $c && is_callable( [ $c, 'has_2fa_active' ] ) ) || $c->has_2fa_active( $user );
			}
			if ( ! $has && class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
				$has = ! is_callable( [ '\WP2FA\Admin\Helpers\User_Helper', 'is_user_using_two_factor' ] ) || \WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user->ID );
			}
			if ( ! $has && class_exists( '\ITSEC_Two_Factor' ) ) {
				$c = is_callable( [ '\ITSEC_Two_Factor', 'get_instance' ] ) ? \ITSEC_Two_Factor::get_instance() : null;
				$has = ! ( $c && is_callable( [ $c, 'is_user_using_two_factor' ] ) ) || $c->is_user_using_two_factor( $user->ID );
			}
			if ( ! $has ) {
				$has = (bool) get_user_meta( $user->ID, 'sg_security_2fa_configured', true );
			}
		} catch ( \Throwable $e ) {
			$has = true;
		}
		return (bool) apply_filters( 'launch_builder_login_links_user_has_2fa', (bool) $has, $user );
	}

	/**
	 * May $creator hold a sign-in link to $target? Run at creation ('create') and at every use ('redeem').
	 *
	 * @return true|\WP_Error (code says why; messages are for administrators, never shown to the link's visitor)
	 */
	public static function check_policy( $creator, $target, string $phase = 'create' ) {
		$no = static function ( string $code, string $msg ) {
			return new \WP_Error( 'lb_ll_' . $code, $msg, [ 'status' => 403 ] );
		};
		if ( ! $creator instanceof \WP_User || ! $creator->exists() ) {
			return $no( 'creator_missing', __( 'The person who made this link no longer has an account.', 'launch-builder' ) );
		}
		if ( ! $target instanceof \WP_User || ! $target->exists() ) {
			return $no( 'target_missing', __( 'That account does not exist.', 'launch-builder' ) );
		}
		if ( ! user_can( $creator, self::CAP ) ) {
			return $no( 'creator_cap', __( 'Only users who can edit other users can make login links.', 'launch-builder' ) );
		}
		if ( ! user_can( $creator, 'edit_user', $target->ID ) ) {
			return $no( 'creator_cannot_edit', __( 'You cannot edit this account, so you cannot make a login link for it.', 'launch-builder' ) );
		}
		if ( $creator->ID === $target->ID && ! apply_filters( 'launch_builder_login_links_allow_self', false, $target ) ) {
			return $no( 'self', __( 'You cannot make a login link for your own account.', 'launch-builder' ) );
		}
		if ( is_multisite() && is_super_admin( $target->ID ) ) {
			return $no( 'super_admin', __( 'Network administrators never get login links.', 'launch-builder' ) );
		}
		if ( ! is_user_member_of_blog( $target->ID, get_current_blog_id() ) ) {
			return $no( 'not_member', __( 'That account is not a member of this site.', 'launch-builder' ) );
		}
		if ( function_exists( 'is_user_spammy' ) && is_user_spammy( $target ) ) {
			return $no( 'spam', __( 'That account is marked as spam.', 'launch-builder' ) );
		}
		$network_admin = is_multisite() && is_super_admin( $creator->ID );
		foreach ( array_keys( array_filter( (array) $target->allcaps ) ) as $cap ) {
			$cap = (string) $cap;
			if ( wp_roles()->is_role( $cap ) || $network_admin ) {
				continue; // allcaps also lists role names; a network admin holds every capability.
			}
			// Primitive against primitive: user_can() would map some names (manage_links, edit_css…) to do_not_allow
			// even for administrators. user_has_cap is applied as WP_User::has_cap() does, for dynamically granted caps.
			$mine = (array) apply_filters( 'user_has_cap', $creator->allcaps, [ $cap ], [ $cap, $creator->ID ], $creator );
			if ( empty( $mine[ $cap ] ) ) {
				/* translators: %s: capability name */
				return $no( 'target_more_powerful', sprintf( __( 'That account can do something you cannot (%s), so you cannot make a login link for it.', 'launch-builder' ), $cap ) );
			}
		}
		if ( self::is_admin_level( $target ) && ! self::settings()['allow_admin_targets'] ) {
			return $no( 'admin_target', __( 'That account has administrator-level rights. Links to such accounts are off (Users > Login links > Settings).', 'launch-builder' ) );
		}
		if ( self::has_2fa( $target ) && ! apply_filters( 'launch_builder_login_links_allow_2fa_users', false, $target ) ) {
			return $no( 'two_factor', __( 'That account uses two-factor login. A login link would skip it, so none can be made.', 'launch-builder' ) );
		}
		$ok = apply_filters( 'redeem' === $phase ? 'launch_builder_login_link_can_redeem' : 'launch_builder_login_link_can_create', true, $target, $creator );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return false === $ok ? $no( 'vetoed', __( 'Another plugin refused this login link.', 'launch-builder' ) ) : true;
	}

	/** Can the record be used now? (state + policy) @return true|\WP_Error */
	private static function redeemable( array $row ) {
		$r = $row['rec'];
		$status = self::status( $r );
		if ( (int) $r['blog'] !== get_current_blog_id() ) {
			return new \WP_Error( 'lb_ll_wrong_site', 'link belongs to another site' );
		}
		if ( 'active' !== $status ) {
			return new \WP_Error( 'lb_ll_' . $status, 'link ' . $status );
		}
		return self::check_policy( get_userdata( (int) $r['created_by'] ), get_userdata( $row['user_id'] ), 'redeem' );
	}

	/** May $actor revoke or see this link? Its own account always may; otherwise the manage capability plus edit_user. */
	private static function can_manage_row( int $actor, array $row ): bool {
		return $actor > 0 && ( $actor === $row['user_id'] || ( user_can( $actor, self::CAP ) && user_can( $actor, 'edit_user', $row['user_id'] ) ) );
	}

	/**
	 * Where a link may send the browser after sign-in, as a root-relative path ('' = the default for the account).
	 * Accepts a path or a URL on this site; refuses other hosts, scheme-relative and backslash tricks, control
	 * characters, encoded variants of those, and the login page. @return string|\WP_Error
	 */
	public static function normalize_redirect( string $raw ) {
		$bad = new \WP_Error( 'lb_ll_bad_redirect', __( 'The page to open must be a page of this site, for example /my-account/.', 'launch-builder' ), [ 'status' => 400 ] );
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( strlen( $raw ) > 2048 ) {
			return $bad;
		}
		$home = wp_parse_url( home_url() );
		if ( preg_match( '#^https?://#i', $raw ) ) { // A full URL of this site: keep its path and query.
			$p = wp_parse_url( $raw );
			if ( ! $p || empty( $p['host'] ) || strtolower( $p['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( $p['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
				return $bad;
			}
			$raw = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
		$checks = [ $raw, rawurldecode( $raw ), rawurldecode( rawurldecode( $raw ) ) ];
		foreach ( $checks as $s ) {
			if ( '/' !== substr( $s, 0, 1 ) || '//' === substr( $s, 0, 2 ) || false !== strpos( $s, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $s ) || preg_match( '#^/*[a-z][a-z0-9+.-]*:#i', $s ) ) {
				return $bad;
			}
			$path = strtolower( (string) wp_parse_url( $s, PHP_URL_PATH ) );
			$login = strtolower( (string) wp_parse_url( wp_login_url(), PHP_URL_PATH ) );
			if ( false !== strpos( $path, 'wp-login.php' ) || ( '' !== $login && '/' !== $login && 0 === strpos( $path, rtrim( $login, '/' ) ) ) ) {
				return $bad;
			}
		}
		$p = wp_parse_url( $raw );
		if ( false === $p || isset( $p['scheme'] ) || isset( $p['host'] ) ) {
			return $bad;
		}
		$path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		// WordPress's own check; its sanitised result (non-ASCII percent-encoded) must still be on this origin.
		$safe = wp_validate_redirect( self::origin() . $path, '' );
		if ( '' === $safe || 0 !== strpos( $safe, self::origin() . '/' ) ) {
			return $bad;
		}
		return substr( $safe, strlen( self::origin() ) );
	}

	/** scheme://host[:port] of the site. */
	private static function origin(): string {
		$h = wp_parse_url( home_url() );
		return ( $h['scheme'] ?? 'http' ) . '://' . ( $h['host'] ?? '' ) . ( isset( $h['port'] ) ? ':' . $h['port'] : '' );
	}

	/* ------------------------------------------------------------------ public API (wp-admin and WP-CLI call these) */

	/**
	 * Create a link. $args: days (1..max), max_uses (0 = until it expires, 1 = single use), redirect (path), note
	 * (<= 120 chars), email (bool: send it to the account's own address instead of returning it).
	 *
	 * @return array|\WP_Error { id, url (only when not emailed), emailed, record }
	 */
	public static function create( int $target_id, array $args = [], int $creator_id = 0 ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'lb_ll_off', __( 'Login links are turned off.', 'launch-builder' ), [ 'status' => 403 ] );
		}
		$creator = get_userdata( $creator_id ? $creator_id : get_current_user_id() );
		$target = get_userdata( $target_id );
		$ok = self::check_policy( $creator, $target, 'create' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$max = self::max_days();
		$days = isset( $args['days'] ) && '' !== $args['days'] ? (int) $args['days'] : self::settings()['default_days'];
		if ( $days < 1 || $days > $max ) {
			/* translators: %d: maximum number of days */
			return new \WP_Error( 'lb_ll_bad_days', sprintf( __( 'A link lasts 1 to %d days.', 'launch-builder' ), $max ), [ 'status' => 400 ] );
		}
		$uses = (int) ( $args['max_uses'] ?? 0 );
		if ( $uses < 0 || $uses > 100 ) {
			return new \WP_Error( 'lb_ll_bad_uses', __( 'Uses must be 0 (until it expires) to 100.', 'launch-builder' ), [ 'status' => 400 ] );
		}
		$redirect = self::normalize_redirect( (string) ( $args['redirect'] ?? '' ) );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}
		$note = sanitize_text_field( (string) ( $args['note'] ?? '' ) );
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 120 ) : substr( $note, 0, 120 );
		$active = static function ( array $rows ): int {
			return count( array_filter( $rows, static function ( $r ) { return 'active' === self::status( $r['rec'] ); } ) );
		};
		$user_cap = max( 1, (int) apply_filters( 'launch_builder_login_links_per_user', self::USER_CAP ) );
		if ( $active( self::rows( $target->ID ) ) >= $user_cap ) {
			/* translators: %d: number of links */
			return new \WP_Error( 'lb_ll_user_cap', sprintf( __( 'This account already has %d active links. Revoke one first.', 'launch-builder' ), $user_cap ), [ 'status' => 409 ] );
		}
		if ( $active( self::rows() ) >= self::SITE_CAP ) {
			return new \WP_Error( 'lb_ll_site_cap', __( 'This site has too many active login links. Revoke some first.', 'launch-builder' ), [ 'status' => 409 ] );
		}

		global $wpdb;
		$blog = get_current_blog_id();
		$selector = '';
		for ( $i = 0; $i < 5 && '' === $selector; $i++ ) {
			$s = self::b64url( random_bytes( 9 ) );
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", self::meta_key( $s, $blog ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$selector = $taken ? '' : $s;
		}
		if ( '' === $selector ) {
			return new \WP_Error( 'lb_ll_busy', __( 'Could not make a link. Try again.', 'launch-builder' ) );
		}
		$secret = self::b64url( random_bytes( 32 ) );
		$now = time();
		$rec = [
			'v'              => 1,
			'blog'           => $blog,
			'verifier'       => self::verifier( $blog, $target->ID, $selector, $secret ),
			'created_by'     => $creator->ID,
			'created_at'     => $now,
			'expires_at'     => $now + $days * DAY_IN_SECONDS,
			'max_uses'       => $uses,
			'uses'           => 0,
			'last_used_at'   => 0,
			'last_ip'        => '',
			'redirect'       => $redirect,
			'note'           => $note,
			'revoked_at'     => 0,
			'revoked_by'     => 0,
			'revoked_reason' => '',
			'rev'            => 0,
		];
		if ( ! add_user_meta( $target->ID, self::meta_key( $selector, $blog ), wp_slash( $rec ), true ) ) {
			return new \WP_Error( 'lb_ll_store', __( 'Could not save the link.', 'launch-builder' ) );
		}
		$url = add_query_arg( [ 'action' => self::ACTION, 'k' => self::PREFIX . '.' . $selector . '.' . $secret ], wp_login_url() );
		$row = [ 'umeta_id' => 0, 'user_id' => $target->ID, 'raw' => '', 'rec' => $rec, 'selector' => $selector ];
		$public = self::public_record( $row );
		self::event( 'login_link.created', true, $row, $creator->ID, ( $uses ? $uses . ' use(s), ' : '' ) . $days . ' day(s)' );
		$out = [ 'id' => $selector, 'url' => $url, 'emailed' => false, 'record' => $public ];
		if ( ! empty( $args['email'] ) ) {
			$sent = self::send_email( $target, $url, $public );
			self::event( 'login_link.emailed', $sent, $row, $creator->ID, $sent ? 'sent to the account address' : 'wp_mail failed' );
			if ( $sent ) {
				$out['emailed'] = true;
				$out['url'] = ''; // Sent: not returned anywhere else.
			} else {
				$out['email_failed'] = true; // The caller shows the link once instead.
			}
		}
		unset( $secret );
		return $out;
	}

	/**
	 * List links (metadata only). $args: user_id, status 'active'|'all', offset, limit (<= 500).
	 *
	 * @return array{total:int,links:array}
	 */
	public static function list_links( array $args = [] ): array {
		$all = array_map( [ __CLASS__, 'public_record' ], self::rows( (int) ( $args['user_id'] ?? 0 ) ) );
		if ( 'all' !== ( $args['status'] ?? 'active' ) ) {
			$all = array_values( array_filter( $all, static function ( $r ) { return 'active' === $r['status']; } ) );
		}
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		return [ 'total' => count( $all ), 'links' => array_slice( $all, max( 0, (int) ( $args['offset'] ?? 0 ) ), $limit ) ];
	}

	/**
	 * Revoke one link (by id). $by = acting user (0 = the system). Optionally ends the sessions it started.
	 *
	 * @return array|\WP_Error the public record
	 */
	public static function revoke( string $id, int $by, string $reason = 'manual', bool $sign_out = false ) {
		$row = self::mutate(
			$id,
			static function ( $row ) use ( $by, $reason ) {
				if ( $by && ! self::can_manage_row( $by, $row ) ) {
					return new \WP_Error( 'lb_ll_forbidden', __( 'You cannot revoke this link.', 'launch-builder' ), [ 'status' => 403 ] );
				}
				if ( ! empty( $row['rec']['revoked_at'] ) ) {
					return new \WP_Error( 'lb_ll_already', __( 'That link was already revoked.', 'launch-builder' ) );
				}
				$rec = $row['rec'];
				$rec['revoked_at'] = time();
				$rec['revoked_by'] = $by;
				$rec['revoked_reason'] = substr( sanitize_key( $reason ), 0, 40 );
				return $rec;
			}
		);
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $sign_out ) {
			self::end_sessions( $row['user_id'], $row['selector'] );
		}
		self::event( 'login_link.revoked', true, $row, $by, $reason . ( $sign_out ? ', sessions ended' : '' ) );
		return self::public_record( $row );
	}

	/** Revoke every active link of an account ($user_id) or of the whole site (0). @return int how many */
	public static function revoke_many( int $user_id, int $by, string $reason = 'manual', bool $sign_out = false ): int {
		$n = 0;
		foreach ( self::rows( $user_id ) as $row ) {
			if ( 'active' === self::status( $row['rec'] ) && ! is_wp_error( self::revoke( $row['selector'], $by, $reason, false ) ) ) {
				$n++;
			}
		}
		if ( $sign_out && $user_id ) {
			self::end_sessions( $user_id, '' );
		}
		return $n;
	}

	/**
	 * Extend a link to $days from now (at most the maximum lifetime). The acting user must pass the creation policy for
	 * the account. Same URL, so a bookmark keeps working. @return array|\WP_Error the public record
	 */
	public static function extend( string $id, int $days, int $by ) {
		$max = self::max_days();
		if ( $days < 1 || $days > $max ) {
			/* translators: %d: maximum number of days */
			return new \WP_Error( 'lb_ll_bad_days', sprintf( __( 'A link lasts 1 to %d days.', 'launch-builder' ), $max ), [ 'status' => 400 ] );
		}
		$row = self::mutate(
			$id,
			static function ( $row ) use ( $days, $by ) {
				$ok = self::check_policy( get_userdata( $by ), get_userdata( $row['user_id'] ), 'create' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$st = self::status( $row['rec'] );
				if ( 'revoked' === $st || 'used' === $st ) {
					return new \WP_Error( 'lb_ll_' . $st, __( 'A revoked or used-up link cannot be extended. Make a new one.', 'launch-builder' ), [ 'status' => 409 ] );
				}
				$rec = $row['rec'];
				$rec['expires_at'] = time() + $days * DAY_IN_SECONDS;
				return $rec;
			}
		);
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::event( 'login_link.extended', true, $row, $by, 'to ' . gmdate( 'Y-m-d H:i', (int) $row['rec']['expires_at'] ) . ' UTC' );
		return self::public_record( $row );
	}

	/** Delete rows that expired, were revoked or used up more than KEEP_DAYS ago. @return int deleted */
	public static function gc(): int {
		$cut = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$n = 0;
		foreach ( self::rows() as $row ) {
			$r = $row['rec'];
			$st = self::status( $r );
			$at = 'revoked' === $st ? (int) $r['revoked_at'] : ( 'used' === $st ? max( (int) $r['last_used_at'], (int) $r['created_at'] ) : (int) $r['expires_at'] );
			if ( 'active' !== $st && $at < $cut && delete_metadata_by_mid( 'user', $row['umeta_id'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * End the sessions a link started (all link sessions of the account when $selector is ''). With a session manager
	 * other than core's usermeta one, every session of the account is ended.
	 */
	private static function end_sessions( int $user_id, string $selector ): void {
		$manager = (string) apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' );
		if ( 'WP_User_Meta_Session_Tokens' !== $manager ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			return;
		}
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) ) {
			return;
		}
		$keep = array_filter(
			$sessions,
			static function ( $s ) use ( $selector ) {
				$tag = is_array( $s ) ? (string) ( $s['lb_login_link'] ?? '' ) : '';
				return '' === $tag || ( '' !== $selector && $tag !== $selector );
			}
		);
		if ( count( $keep ) !== count( $sessions ) ) {
			$keep ? update_user_meta( $user_id, 'session_tokens', $keep ) : delete_user_meta( $user_id, 'session_tokens' );
		}
	}

	/* ------------------------------------------------------------------ automatic revocation */

	public static function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User && self::settings()['revoke_on_password_change'] ) {
			self::revoke_many( $user->ID, 0, 'password_changed' );
		}
	}

	public static function on_set_password( $password, $user_id = 0 ): void {
		if ( $user_id && self::settings()['revoke_on_password_change'] ) {
			self::revoke_many( (int) $user_id, 0, 'password_changed' );
		}
	}

	public static function on_profile_update( $user_id, $old = null, $data = [] ): void {
		if ( $old instanceof \WP_User && is_array( $data ) && isset( $data['user_pass'] ) && $data['user_pass'] !== $old->user_pass && self::settings()['revoke_on_password_change'] ) {
			self::revoke_many( (int) $user_id, 0, 'password_changed' );
		}
	}

	public static function on_role_change( $user_id ): void {
		if ( self::settings()['revoke_on_role_change'] ) {
			self::revoke_many( (int) $user_id, 0, 'role_changed' );
		}
	}

	/** Multisite: usermeta survives leaving a site, the links must not. */
	public static function on_remove_from_blog( $user_id, $blog_id ): void {
		$switch = (int) $blog_id && (int) $blog_id !== get_current_blog_id() && function_exists( 'switch_to_blog' );
		if ( $switch ) {
			switch_to_blog( (int) $blog_id );
		}
		self::revoke_many( (int) $user_id, 0, 'removed_from_site' );
		if ( $switch ) {
			restore_current_blog();
		}
	}

	/* ------------------------------------------------------------------ throttling and logging */

	private static function client_ip(): string {
		$ip = (string) apply_filters( 'launch_builder_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' ); // phpcs:ignore
		return substr( (string) preg_replace( '/[^0-9a-f:.]/i', '', $ip ), 0, 45 );
	}

	private static function ip_key(): string {
		return 'lb_ll_ip_' . substr( hash_hmac( 'sha256', self::client_ip(), wp_salt( 'nonce' ) ), 0, 32 );
	}

	private static function sel_key( string $selector ): string {
		return 'lb_ll_sel_' . md5( $selector );
	}

	/** Count a failure for this IP; log it (bounded). @return int failures of this IP in the window, this one included */
	private static function failure( string $reason, ?array $row ): int {
		$key = self::ip_key();
		$n = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, self::WINDOW );
		if ( $n <= self::IP_LIMIT ) {
			self::event( 'login_link.failed', false, $row, 0, $reason, true );
		} elseif ( self::IP_LIMIT + 1 === $n ) {
			self::event( 'login_link.locked', false, $row, 0, 'ip throttled after ' . self::IP_LIMIT . ' failures', true );
		}
		return $n;
	}

	/**
	 * Activity log row + action launch_builder_login_link_event( $event, $user_id, $public_record ) (always fired). The
	 * note names the link by its first 6 characters, never the secret. $bounded: rows signed-out visitors can cause at
	 * will (failures, IP throttles), written only within log_budget().
	 */
	private static function event( string $event, bool $ok, ?array $row, int $actor, string $why, bool $bounded = false ): void {
		$public = $row ? self::public_record( $row ) : [];
		do_action( 'launch_builder_login_link_event', $event, $row ? $row['user_id'] : 0, $public );
		if ( $bounded && ! self::log_budget() ) {
			return;
		}
		$actor_user = $actor ? get_userdata( $actor ) : null;
		$note = ( $row ? 'link ' . substr( $row['selector'], 0, 6 ) . ' for ' . $public['user_login'] . ', ' : '' )
			. ( $actor_user ? 'by ' . $actor_user->user_login . ', ' : '' )
			. 'ip ' . ( self::client_ip() ? self::client_ip() : '-' ) . ': ' . $why;
		$uid = $actor ? $actor : ( 'login_link.used' === $event && $row ? $row['user_id'] : get_current_user_id() );
		self::log( $event, $ok, $note, $uid );
	}

	/**
	 * May one more visitor-caused row go to the shared activity log? At most LOG_CAP per 15-minute window and LOG_DAY per
	 * UTC day, site-wide (fixed buckets in expiring transients). The first row refused on a day is replaced by one row
	 * saying that further failures are only counted (site-state login_links.unlogged_failures).
	 */
	private static function log_budget(): bool {
		$day = gmdate( 'Ymd' );
		$wkey = 'lb_ll_logw_' . (int) floor( time() / self::WINDOW );
		$dkey = 'lb_ll_logd_' . $day;
		$w = (int) get_transient( $wkey );
		$d = (int) get_transient( $dkey );
		if ( $w < self::LOG_CAP && $d < self::LOG_DAY ) {
			set_transient( $wkey, $w + 1, self::WINDOW );
			set_transient( $dkey, $d + 1, DAY_IN_SECONDS );
			return true;
		}
		$x = self::unlogged() + 1;
		set_transient( 'lb_ll_logx_' . $day, $x, DAY_IN_SECONDS );
		if ( 1 === $x ) {
			self::log( 'login_link.failed', false, 'more failed sign-in links are only counted from now on, not logged one by one (at most ' . self::LOG_CAP . ' rows per 15 minutes and ' . self::LOG_DAY . ' per day; count in site-state login_links.unlogged_failures)', 0 );
		}
		return false;
	}

	/** Visitor-caused rows not written today (log_budget()). */
	public static function unlogged(): int {
		return (int) get_transient( 'lb_ll_logx_' . gmdate( 'Ymd' ) );
	}

	/** Writes the shared activity log (Activity::log when it exists, else the launch_builder_log rows directly). */
	private static function log( string $event, bool $ok, string $note, int $user_id ): void {
		$activity = __NAMESPACE__ . '\\Activity';
		if ( class_exists( $activity ) && is_callable( [ $activity, 'log' ] ) ) {
			try {
				call_user_func( [ $activity, 'log' ], $event, $ok, $note, [ 'u' => $user_id, 'ip' => self::client_ip() ] );
				return;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- fall back to the direct write.
			}
		}
		$log = (array) get_option( 'launch_builder_log', [] );
		array_unshift( $log, [ 't' => time(), 'u' => $user_id, 'tool' => $event, 'ok' => $ok, 'note' => $note, 'ip' => self::client_ip() ] );
		update_option( 'launch_builder_log', array_slice( $log, 0, 200 ), false );
	}

	/* ------------------------------------------------------------------ the landing (wp-login.php?action=lb-link) */

	/** login_form_lb-link: runs only on wp-login.php, only while the module is on. */
	public static function landing(): void {
		self::headers();
		$k = isset( $_GET['k'] ) ? wp_unslash( $_GET['k'] ) : null; // phpcs:ignore WordPress.Security -- validated below; never echoed.
		unset( $_GET['k'], $_REQUEST['k'] );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		if ( 'POST' === $method ) {
			self::confirm();
		} elseif ( null !== $k ) {
			self::arrive( $k );
		} else {
			self::interstitial();
		}
		exit;
	}

	private static function headers(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		add_action( 'login_head', [ __CLASS__, 'meta_referrer' ], 1 );
	}

	/** login_header() adds core's strict-origin referrer meta just before login_head runs: replace it with ours. */
	public static function meta_referrer(): void {
		remove_action( 'login_head', 'wp_strict_cross_origin_referrer' );
		echo '<meta name="referrer" content="' . esc_attr( self::$referrer ) . '" />' . "\n";
	}

	public static function landing_url(): string {
		return add_query_arg( 'action', self::ACTION, wp_login_url() );
	}

	/** Step A: a GET carrying the token. Nothing is consumed and nobody is signed in. */
	private static function arrive( $k ): void {
		if ( ! is_string( $k ) || 61 !== strlen( $k ) || ! preg_match( self::TOKEN_RE, $k, $m ) ) {
			self::fail( 'malformed token', null );
		}
		[ , $selector, $secret ] = $m;
		$row = self::find( $selector );
		if ( ! $row ) {
			self::fail( 'unknown link', null );
		}
		$skey = self::sel_key( $selector );
		$wrong = (int) get_transient( $skey );
		if ( $wrong >= self::LINK_LIMIT ) {
			self::fail( 'link locked', $row );
		}
		if ( ! hash_equals( (string) $row['rec']['verifier'], self::verifier( (int) $row['rec']['blog'], $row['user_id'], $selector, $secret ) ) ) {
			set_transient( $skey, $wrong + 1, self::WINDOW );
			if ( self::LINK_LIMIT === $wrong + 1 ) {
				self::event( 'login_link.locked', false, $row, 0, 'link locked after ' . self::LINK_LIMIT . ' wrong secrets' );
			}
			self::fail( 'wrong secret', $row );
		}
		$ok = self::redeemable( $row );
		if ( is_wp_error( $ok ) ) {
			self::fail( $ok->get_error_code(), $row );
		}
		$exp = time() + self::PENDING_TTL;
		$value = $selector . '.' . $exp . '.' . self::pending_mac( $selector, $exp, (string) $row['rec']['verifier'] );
		self::set_pending( $value, $exp );
		wp_safe_redirect( self::landing_url(), 303 );
		exit;
	}

	private static function pending_mac( string $selector, int $exp, string $verifier ): string {
		return hash_hmac( 'sha256', 'pending|' . $selector . '|' . $exp . '|' . $verifier, self::key() );
	}

	private static function set_pending( string $value, int $exp ): void {
		$path = (string) wp_parse_url( wp_login_url(), PHP_URL_PATH );
		setcookie(
			self::COOKIE,
			$value,
			[
				'expires'  => $exp,
				'path'     => '' !== $path ? $path : '/',
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/** @return array|null [row, cookie value] of a valid pending cookie */
	private static function read_pending(): ?array {
		$v = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security -- verified below.
		if ( ! preg_match( '/^([A-Za-z0-9_-]{12})\.(\d{9,11})\.([a-f0-9]{64})$/', $v, $m ) ) {
			return null;
		}
		$exp = (int) $m[2];
		if ( $exp < time() || $exp > time() + self::PENDING_TTL + 60 ) {
			return null;
		}
		$row = self::find( $m[1] );
		if ( ! $row || ! hash_equals( self::pending_mac( $m[1], $exp, (string) $row['rec']['verifier'] ), $m[3] ) ) {
			return null;
		}
		return [ $row, $v ];
	}

	private static function form_token( string $cookie ): string {
		return hash_hmac( 'sha256', 'confirm|' . $cookie, self::key() );
	}

	/** Step B: GET without the token: name the account, ask for one click. */
	private static function interstitial(): void {
		$p = self::read_pending();
		if ( ! $p ) {
			self::fail( 'no or expired confirmation cookie', null );
		}
		[ $row, $cookie ] = $p;
		$ok = self::redeemable( $row );
		if ( is_wp_error( $ok ) ) {
			self::fail( $ok->get_error_code(), $row );
		}
		$user = get_userdata( $row['user_id'] );
		if ( is_user_logged_in() && get_current_user_id() === $user->ID ) {
			self::set_pending( '', 1 ); // Already signed in as this account: no use is consumed.
			wp_safe_redirect( self::target( $row, $user ), 303 );
			exit;
		}
		$r = $row['rec'];
		// This URL holds no token, and under no-referrer browsers would send "Origin: null" with the click (confirm()
		// checks Origin): same-origin sends this page's origin to this site only, nothing to other sites.
		self::$referrer = 'same-origin';
		header( 'Referrer-Policy: same-origin' );
		$message = '';
		if ( is_user_logged_in() ) {
			/* translators: %s: name of the account currently signed in */
			$message = '<p class="message">' . esc_html( sprintf( __( 'You are signed in as %s. Continuing signs that account out.', 'launch-builder' ), wp_get_current_user()->display_name ) ) . '</p>';
		}
		login_header( __( 'Sign in', 'launch-builder' ), $message );
		echo '<form name="lbllform" id="loginform" method="post" action="' . esc_url( self::landing_url() ) . '">';
		/* translators: 1: site name, 2: account name */
		echo '<p>' . sprintf( esc_html__( 'Sign in to %1$s as %2$s.', 'launch-builder' ), '<strong>' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</strong>', '<strong>' . esc_html( $user->display_name ) . '</strong>' ) . '</p>';
		echo '<p style="color:#50575e">' . esc_html( self::mask_email( $user->user_email ) ) . '</p>';
		$left = (int) $r['max_uses'] > 0 ? (int) $r['max_uses'] - (int) $r['uses'] : 0;
		/* translators: %s: date and time */
		$line = sprintf( __( 'This link works until %s.', 'launch-builder' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r['expires_at'] ) );
		if ( $left ) {
			/* translators: %d: number of sign-ins left */
			$line .= ' ' . sprintf( _n( '%d sign-in left.', '%d sign-ins left.', $left, 'launch-builder' ), $left );
		}
		echo '<p style="color:#50575e;margin-top:8px">' . esc_html( $line ) . '</p>';
		echo '<input type="hidden" name="lbt" value="' . esc_attr( self::form_token( $cookie ) ) . '" />';
		/* translators: %s: account name */
		echo '<p class="submit" style="margin-top:16px"><button type="submit" class="button button-primary button-large" style="float:none;width:100%">' . esc_html( sprintf( __( 'Continue as %s', 'launch-builder' ), $user->display_name ) ) . '</button></p>';
		echo '</form>';
		echo '<p id="nav">' . esc_html__( 'Not you? Close this page. Nothing has changed.', 'launch-builder' ) . '</p>';
		login_footer();
	}

	/** Step C: the click. Same-origin POST with the pending cookie and the form token; consumes one use; signs in. */
	private static function confirm(): void {
		$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null; // phpcs:ignore WordPress.Security -- compared only.
		if ( null !== $site && 'same-origin' !== strtolower( (string) $site ) ) {
			self::fail( 'cross-site post', null );
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : null; // phpcs:ignore WordPress.Security -- compared only.
		if ( 'null' === $origin && 'same-origin' === strtolower( (string) $site ) ) {
			$origin = null; // A privacy setting hid the origin; the browser itself (Sec-Fetch-Site, not settable by pages) says same-origin.
		}
		if ( null !== $origin && ! self::same_origin( $origin ) ) {
			self::fail( 'foreign origin', null );
		}
		$p = self::read_pending();
		if ( ! $p ) {
			self::fail( 'no or expired confirmation cookie', null );
		}
		[ $row, $cookie ] = $p;
		$t = isset( $_POST['lbt'] ) && is_string( $_POST['lbt'] ) ? (string) wp_unslash( $_POST['lbt'] ) : ''; // phpcs:ignore WordPress.Security -- the HMAC form token is the nonce.
		if ( ! hash_equals( self::form_token( $cookie ), $t ) ) {
			self::fail( 'bad form token', $row );
		}
		$ip = self::client_ip();
		$row = self::mutate(
			$row['selector'],
			static function ( $fresh ) use ( $ip ) {
				$ok = self::redeemable( $fresh );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$rec = $fresh['rec'];
				$rec['uses'] = (int) $rec['uses'] + 1;
				$rec['last_used_at'] = time();
				$rec['last_ip'] = $ip;
				return $rec;
			}
		);
		if ( is_wp_error( $row ) ) {
			self::fail( $row->get_error_code(), $p[0] );
		}
		$user = get_userdata( $row['user_id'] );
		if ( is_user_logged_in() && get_current_user_id() !== $user->ID ) {
			wp_destroy_current_session();
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );
		}
		self::$session_tag = $row['selector'];
		add_filter( 'attach_session_information', [ __CLASS__, 'tag_session' ], 10, 1 );
		wp_set_auth_cookie( $user->ID, false, is_ssl() ); // A standard browser-session login (no "remember me").
		remove_filter( 'attach_session_information', [ __CLASS__, 'tag_session' ], 10 );
		self::$session_tag = '';
		wp_set_current_user( $user->ID );
		self::set_pending( '', 1 );
		self::event( 'login_link.used', true, $row, 0, 'signed in (' . (int) $row['rec']['uses'] . ( (int) $row['rec']['max_uses'] ? '/' . (int) $row['rec']['max_uses'] : '' ) . ')' );
		if ( self::is_admin_level( $user ) ) {
			self::notify_admin( $user, $row );
		}
		do_action( 'wp_login', $user->user_login, $user ); // Two-factor plugins that hook wp_login step in here.
		wp_safe_redirect( self::target( $row, $user ), 303 );
		exit;
	}

	public static function tag_session( $info ) {
		$info = is_array( $info ) ? $info : [];
		if ( '' !== self::$session_tag ) {
			$info['lb_login_link'] = self::$session_tag;
		}
		return $info;
	}

	private static function same_origin( string $origin ): bool {
		$o = wp_parse_url( $origin );
		$l = wp_parse_url( wp_login_url() );
		if ( ! $o || empty( $o['host'] ) || empty( $l['host'] ) ) {
			return false;
		}
		$port = static function ( $p ) {
			return (int) ( $p['port'] ?? ( 'https' === strtolower( (string) ( $p['scheme'] ?? '' ) ) ? 443 : 80 ) );
		};
		return strtolower( $o['host'] ) === strtolower( $l['host'] ) && $port( $o ) === $port( $l );
	}

	/** After sign-in: the link's page, else the account's default (dashboard, profile or home), through login_redirect. */
	private static function target( array $row, \WP_User $user ): string {
		$path = (string) ( $row['rec']['redirect'] ?? '' );
		$requested = '' !== $path ? self::origin() . $path : '';
		$url = '' !== $requested ? $requested : ( user_can( $user, 'edit_posts' ) ? admin_url() : ( user_can( $user, 'read' ) ? admin_url( 'profile.php' ) : home_url( '/' ) ) );
		$url = (string) apply_filters( 'login_redirect', $url, $requested, $user );
		return wp_validate_redirect( $url, admin_url() );
	}

	/** The one page every failure gets (403, or 429 while the IP is throttled). The reason goes to the log only. */
	private static function fail( string $reason, ?array $row ): void {
		$n = self::failure( $reason, $row );
		$throttled = $n > self::IP_LIMIT;
		status_header( $throttled ? 429 : 403 );
		if ( $throttled ) {
			header( 'Retry-After: ' . self::WINDOW );
		}
		if ( $row ) {
			self::set_pending( '', 1 ); // A failed link ends its pending confirmation (a forged cross-site post does not).
		}
		$msg = $throttled
			? __( 'Too many attempts. Wait 15 minutes, then try again.', 'launch-builder' )
			: __( 'This sign-in link is invalid, expired or already used. Ask the site owner for a new one.', 'launch-builder' );
		login_header( __( 'Sign-in link', 'launch-builder' ), '', new \WP_Error( 'lb_ll', esc_html( $msg ) ) );
		echo '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Log in with a password', 'launch-builder' ) . '</a></p>';
		login_footer();
		exit;
	}

	private static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '';
		}
		return substr( $email, 0, 1 ) . str_repeat( '*', min( 6, max( 3, $at - 1 ) ) ) . substr( $email, $at );
	}

	/* ------------------------------------------------------------------ email */

	/** Sends the link to the account's own address only (plain text, neutral wording). Filter launch_builder_login_link_email. */
	private static function send_email( \WP_User $user, string $url, array $public ): bool {
		if ( ! is_email( $user->user_email ) ) {
			return false;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$until = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $public['expires_at'] );
		$lines = [
			/* translators: %s: account display name */
			sprintf( __( 'Hello %s,', 'launch-builder' ), $user->display_name ),
			'',
			/* translators: %s: site name */
			sprintf( __( 'Use this link to sign in to %s:', 'launch-builder' ), $site ),
			'',
			$url,
			'',
			/* translators: %s: date and time */
			sprintf( __( 'It works until %s.', 'launch-builder' ), $until ) . ( 1 === (int) $public['max_uses'] ? ' ' . __( 'It works once.', 'launch-builder' ) : '' ),
			__( 'Do not forward this email: anyone with the link can sign in as you until it expires.', 'launch-builder' ),
			__( 'If you did not expect it, ignore it or tell the site owner.', 'launch-builder' ),
		];
		$mail = apply_filters(
			'launch_builder_login_link_email',
			[
				/* translators: %s: site name */
				'subject' => sprintf( __( 'Your sign-in link for %s', 'launch-builder' ), $site ),
				'message' => implode( "\n", $lines ),
				'headers' => [],
			],
			$user,
			$public
		);
		$mail = is_array( $mail ) ? $mail : [];
		return (bool) wp_mail( $user->user_email, (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ), (array) ( $mail['headers'] ?? [] ) ); // Always the account's own address.
	}

	/** Tell the site admin email when a link signs into an admin-level account. */
	private static function notify_admin( \WP_User $user, array $row ): void {
		$to = (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) || strtolower( $to ) === strtolower( $user->user_email ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: account login */
		$subject = sprintf( __( '[%1$s] Login link used for %2$s', 'launch-builder' ), $site, $user->user_login );
		/* translators: 1: account login, 2: link id, 3: IP address */
		$body = sprintf( __( 'A login link signed in to the administrator-level account %1$s (link %2$s, from %3$s). Users > Login links lists and revokes links.', 'launch-builder' ), $user->user_login, substr( $row['selector'], 0, 6 ), self::client_ip() );
		wp_mail( $to, $subject, $body );
	}

	/* ------------------------------------------------------------------ wp-admin */

	public static function page_url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'users.php?page=' . self::PAGE ) );
	}

	public static function menu(): void {
		$hook = add_users_page( __( 'Login links', 'launch-builder' ), __( 'Login links', 'launch-builder' ), self::enabled() ? self::CAP : 'manage_options', self::PAGE, [ __CLASS__, 'page' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ __CLASS__, 'page_load' ] );
		}
	}

	/** Handles the create form (POSTed to the page itself, so the new link is shown once in this response only). */
	public static function page_load(): void {
		if ( ! self::enabled() ) {
			return;
		}
		self::gc();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'create' !== ( $_POST['lb_ll_do'] ?? '' ) ) { // phpcs:ignore WordPress.Security
			return;
		}
		check_admin_referer( 'lb_ll_create' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to make login links.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Referrer-Policy: no-referrer' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is validated in create().
		$target = self::resolve_user( (string) ( $in['user'] ?? '' ) );
		$uses = (string) ( $in['uses'] ?? 'unlimited' );
		$redirect_choice = (string) ( $in['redirect_choice'] ?? '' );
		$redirect = 'dashboard' === $redirect_choice ? (string) wp_parse_url( admin_url(), PHP_URL_PATH ) : ( 'home' === $redirect_choice ? (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : ( 'path' === $redirect_choice ? (string) ( $in['redirect'] ?? '' ) : '' ) );
		$r = $target ? self::create(
			$target->ID,
			[
				'days'     => (int) ( $in['days'] ?? 0 ),
				'max_uses' => 'single' === $uses ? 1 : ( 'multi' === $uses ? max( 1, (int) ( $in['max_uses'] ?? 1 ) ) : 0 ),
				'redirect' => $redirect,
				'note'     => (string) ( $in['note'] ?? '' ),
				'email'    => 'email' === ( $in['delivery'] ?? '' ),
			],
			get_current_user_id()
		) : new \WP_Error( 'lb_ll_no_user', __( 'Choose an account.', 'launch-builder' ) );
		self::$created = [ 'result' => $r, 'user' => $target ];
	}

	private static function resolve_user( string $who ): ?\WP_User {
		$who = trim( $who );
		if ( '' === $who ) {
			return null;
		}
		$u = ctype_digit( $who ) ? get_userdata( (int) $who ) : ( is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who ) );
		return $u instanceof \WP_User ? $u : null;
	}

	private static function when( int $t ): string {
		return $t ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t ) : '-';
	}

	private static function flash( string $type, string $msg ): void {
		set_transient( 'lb_ll_flash_' . get_current_user_id(), [ $type, $msg ], 120 );
	}

	private static function back( string $fallback = '' ): void {
		$to = wp_get_referer();
		wp_safe_redirect( $to ? $to : ( $fallback ? $fallback : self::page_url() ) );
		exit;
	}

	/** Flash messages, the old-plugin notice. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id = $screen ? (string) $screen->id : '';
		if ( in_array( $id, [ 'users_page_' . self::PAGE, 'users', 'profile', 'user-edit' ], true ) ) {
			$f = get_transient( 'lb_ll_flash_' . get_current_user_id() );
			if ( is_array( $f ) && 2 === count( $f ) ) {
				delete_transient( 'lb_ll_flash_' . get_current_user_id() );
				echo '<div class="notice notice-' . ( 'error' === $f[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $f[1] ) . '</p></div>';
			}
		}
		if ( defined( 'PKG_AUTOLOGIN_VERSION' ) && current_user_can( 'activate_plugins' ) && in_array( $id, [ 'dashboard', 'plugins', 'users', 'profile', 'user-edit', 'users_page_' . self::PAGE ], true ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'The old login-link plugin is still active.', 'launch-builder' ) . '</strong> '
				. esc_html__( 'Its links work only while that plugin is active: as soon as you deactivate it, every old autologin link stops working. Launch Builder does not import them, because that plugin stores them in plain text and they never expire.', 'launch-builder' ) . '</p><p>'
				. esc_html__( 'Make new login links for the people who need them, send them, then deactivate and delete that plugin.', 'launch-builder' )
				. ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Users > Login links', 'launch-builder' ) . '</a></p></div>';
		}
	}

	/** Users list: "Create login link" on accounts the current user may make one for. */
	public static function row_action( $actions, $user ) {
		if ( $user instanceof \WP_User && current_user_can( self::CAP ) && true === self::check_policy( wp_get_current_user(), $user, 'create' ) ) {
			$actions['lb_login_link'] = '<a href="' . esc_url( self::page_url( [ 'user_id' => $user->ID ] ) ) . '">' . esc_html__( 'Create login link', 'launch-builder' ) . '</a>';
		}
		return $actions;
	}

	/** Profile screens: the account's links. Managers see Create / Revoke; the account itself sees and can revoke its own. */
	public static function profile_section( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$me = get_current_user_id();
		$manager = current_user_can( self::CAP ) && current_user_can( 'edit_user', $user->ID ) && $me !== $user->ID;
		$rows = array_values( array_filter( self::rows( $user->ID ), static function ( $r ) { return 'active' === self::status( $r['rec'] ); } ) );
		if ( ! $manager && ! $rows ) {
			return;
		}
		echo '<h2 id="lb-login-links">' . esc_html__( 'Login links', 'launch-builder' ) . '</h2>';
		echo '<p class="description">' . ( $me === $user->ID
			? esc_html__( 'Links that sign in to your account without a password. Revoke any you did not ask for.', 'launch-builder' )
			: esc_html__( 'Expiring links that sign in to this account without a password.', 'launch-builder' ) ) . '</p>';
		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:900px;margin:8px 0"><thead><tr><th>' . esc_html__( 'Note', 'launch-builder' ) . '</th><th>' . esc_html__( 'Made by', 'launch-builder' ) . '</th><th>' . esc_html__( 'Expires', 'launch-builder' ) . '</th><th>' . esc_html__( 'Uses', 'launch-builder' ) . '</th><th>' . esc_html__( 'Last used', 'launch-builder' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$p = self::public_record( $row );
				$revoke = wp_nonce_url( admin_url( 'admin-post.php?action=lb_ll_revoke&id=' . rawurlencode( $p['id'] ) ), 'lb_ll_revoke_' . $p['id'] );
				echo '<tr><td>' . esc_html( '' !== $p['note'] ? $p['note'] : '-' ) . '</td><td>' . esc_html( $p['created_by_login'] ) . '<br><small>' . esc_html( self::when( $p['created_at'] ) ) . '</small></td><td>' . esc_html( self::when( $p['expires_at'] ) ) . '</td><td>' . esc_html( $p['uses'] . ( $p['max_uses'] ? ' / ' . $p['max_uses'] : '' ) ) . '</td><td>' . esc_html( self::when( $p['last_used_at'] ) ) . '</td><td><a class="button button-small" href="' . esc_url( $revoke ) . '">' . esc_html__( 'Revoke', 'launch-builder' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No active links.', 'launch-builder' ) . '</p>';
		}
		if ( $manager ) {
			$ok = self::check_policy( wp_get_current_user(), $user, 'create' );
			if ( true === $ok ) {
				echo '<p><a class="button" href="' . esc_url( self::page_url( [ 'user_id' => $user->ID ] ) ) . '">' . esc_html__( 'Create login link', 'launch-builder' ) . '</a></p>';
			} else {
				echo '<p class="description">' . esc_html( $ok->get_error_message() ) . '</p>';
			}
		}
	}

	/** Users > Login links. */
	public static function page(): void {
		$on = self::enabled();
		echo '<div class="wrap"><h1>' . esc_html__( 'Login links', 'launch-builder' ) . '</h1>';
		echo '<p style="max-width:760px;color:#50575e">' . esc_html__( 'Send a client an expiring link that signs in to their account with one click. Only a hash of each link is stored, links open a confirmation page (so email scanners never sign anyone in), and every use is logged under Launch Builder > Activity and safety.', 'launch-builder' ) . '</p>';
		if ( ! $on ) {
			self::page_off();
			echo '</div>';
			return;
		}
		$legacy = self::legacy_count();
		if ( $legacy && ! defined( 'PKG_AUTOLOGIN_VERSION' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: %d: number of accounts */
			echo esc_html( sprintf( _n( '%d account still has an old login-link code stored in plain text.', '%d accounts still have old login-link codes stored in plain text.', $legacy, 'launch-builder' ), $legacy ) ) . ' ' . esc_html__( 'They no longer work, but anyone who reads a database backup can see them.', 'launch-builder' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_ll_legacy_delete">';
			wp_nonce_field( 'lb_ll_legacy_delete' );
			echo '<p><button class="button">' . esc_html__( 'Delete the old codes', 'launch-builder' ) . '</button></p></form></div>';
		}
		self::page_created();
		self::page_create_form();
		self::page_list();
		if ( current_user_can( 'manage_options' ) ) {
			self::page_settings();
		}
		echo '</div>';
	}

	private static function page_off(): void {
		echo '<div class="card" style="max-width:760px"><h2>' . esc_html__( 'Login links are off', 'launch-builder' ) . '</h2>';
		echo '<p>' . esc_html__( 'While off, no link works and nothing is added to the login page. Links made before stay stored and work again (until they expire) if you turn the module back on.', 'launch-builder' ) . '</p>';
		if ( defined( 'LB_LOGIN_LINKS' ) ) {
			echo '<p><em>' . esc_html( self::how_to_enable() ) . '</em></p>';
		} elseif ( self::registry() ) {
			echo '<p>' . esc_html( self::how_to_enable() ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=launch-builder-modules' ) ) . '">' . esc_html__( 'Open Modules', 'launch-builder' ) . '</a></p>';
		} elseif ( current_user_can( 'manage_options' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_ll_enable"><input type="hidden" name="on" value="1">';
			wp_nonce_field( 'lb_ll_enable' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Turn on login links', 'launch-builder' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	private static function page_created(): void {
		if ( ! self::$created ) {
			return;
		}
		$r = self::$created['result'];
		$user = self::$created['user'];
		if ( is_wp_error( $r ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<div class="card" style="max-width:760px;border-left:4px solid #00a32a">';
		if ( $r['emailed'] ) {
			/* translators: %s: masked email address */
			echo '<h2>' . esc_html__( 'Link sent', 'launch-builder' ) . '</h2><p>' . esc_html( sprintf( __( 'Emailed to %s. It is not shown here and cannot be shown again.', 'launch-builder' ), self::mask_email( $user->user_email ) ) ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html__( 'Copy the link now', 'launch-builder' ) . '</h2>';
		if ( ! empty( $r['email_failed'] ) ) {
			echo '<p>' . esc_html__( 'The email could not be sent, so here is the link instead.', 'launch-builder' ) . '</p>';
		}
		/* translators: 1: account name, 2: date and time */
		echo '<p><strong>' . esc_html( sprintf( __( 'Anyone with this link can sign in as %1$s until %2$s.', 'launch-builder' ), $user->display_name, self::when( (int) $r['record']['expires_at'] ) ) ) . '</strong> ' . esc_html__( 'It is shown once: only a hash is stored, so it cannot be shown again.', 'launch-builder' ) . '</p>';
		echo '<p><input type="text" readonly id="lbll-url" class="large-text code" value="' . esc_attr( $r['url'] ) . '" onfocus="this.select()"></p>';
		echo '<p><button type="button" class="button button-primary" id="lbll-copy">' . esc_html__( 'Copy link', 'launch-builder' ) . '</button></p></div>';
		echo '<script>document.getElementById("lbll-copy").addEventListener("click",function(){var i=document.getElementById("lbll-url"),b=this;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand("copy")}).then(function(){b.textContent=' . wp_json_encode( __( 'Copied', 'launch-builder' ) ) . '})});</script>';
	}

	private static function page_create_form(): void {
		$s = self::settings();
		$max = self::max_days();
		$me = wp_get_current_user();
		$pre = isset( $_GET['user_id'] ) ? get_userdata( absint( $_GET['user_id'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		echo '<div class="card" style="max-width:760px"><h2>' . esc_html__( 'Make a link', 'launch-builder' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( self::page_url() ) . '" id="lbll-create"><input type="hidden" name="lb_ll_do" value="create">';
		wp_nonce_field( 'lb_ll_create' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="lbll-user">' . esc_html__( 'Account', 'launch-builder' ) . '</label></th><td>';
		if ( $pre ) {
			$ok = self::check_policy( $me, $pre, 'create' );
			echo '<strong>' . esc_html( $pre->display_name . ' (' . $pre->user_login . ')' ) . '</strong>';
			if ( true === $ok ) {
				echo '<input type="hidden" name="user" value="' . esc_attr( (string) $pre->ID ) . '">';
			} else {
				echo '<p class="description" style="color:#b32d2e">' . esc_html( $ok->get_error_message() ) . '</p></td></tr></tbody></table></form></div>';
				return;
			}
		} else {
			$users = get_users( [ 'number' => 201, 'orderby' => 'login', 'blog_id' => get_current_blog_id() ] );
			if ( count( $users ) > 200 ) {
				echo '<input type="text" id="lbll-user" name="user" class="regular-text" placeholder="' . esc_attr__( 'Username or email', 'launch-builder' ) . '" required>';
			} else {
				echo '<select id="lbll-user" name="user" required><option value="">' . esc_html__( 'Choose…', 'launch-builder' ) . '</option>';
				foreach ( $users as $u ) {
					$ok = self::check_policy( $me, $u, 'create' );
					echo '<option value="' . esc_attr( (string) $u->ID ) . '"' . ( true === $ok ? '' : ' disabled' ) . '>' . esc_html( $u->display_name . ' (' . $u->user_login . ')' . ( true === $ok ? '' : ': ' . self::short_reason( $ok ) ) ) . '</option>';
				}
				echo '</select>';
			}
			echo '<p class="description">' . esc_html__( 'Accounts you cannot make a link for are greyed out with the reason.', 'launch-builder' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="lbll-days">' . esc_html__( 'Works for', 'launch-builder' ) . '</label></th><td><input type="number" id="lbll-days" name="days" min="1" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $s['default_days'] ) . '" class="small-text"> ' . esc_html__( 'days', 'launch-builder' );
		/* translators: %d: maximum number of days */
		echo '<p class="description">' . esc_html( sprintf( __( 'At most %d days. Shorter is safer.', 'launch-builder' ), $max ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Uses', 'launch-builder' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="uses" value="unlimited" checked> ' . esc_html__( 'Any number of times until it expires', 'launch-builder' ) . '</label><br>';
		echo '<label><input type="radio" name="uses" value="single"> ' . esc_html__( 'Once', 'launch-builder' ) . '</label><br>';
		echo '<label><input type="radio" name="uses" value="multi"> ' . esc_html__( 'Up to', 'launch-builder' ) . ' <input type="number" name="max_uses" min="1" max="100" value="3" class="small-text"> ' . esc_html__( 'times', 'launch-builder' ) . '</label></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="lbll-redirect-choice">' . esc_html__( 'Opens', 'launch-builder' ) . '</label></th><td><select id="lbll-redirect-choice" name="redirect_choice"><option value="">' . esc_html__( 'Their usual start page', 'launch-builder' ) . '</option><option value="dashboard">' . esc_html__( 'Dashboard', 'launch-builder' ) . '</option><option value="home">' . esc_html__( 'Home page', 'launch-builder' ) . '</option><option value="path">' . esc_html__( 'A page of this site…', 'launch-builder' ) . '</option></select> <input type="text" name="redirect" class="regular-text" placeholder="/my-account/"><p class="description">' . esc_html__( 'Only pages of this site.', 'launch-builder' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="lbll-note">' . esc_html__( 'Note', 'launch-builder' ) . '</label></th><td><input type="text" id="lbll-note" name="note" maxlength="120" class="regular-text" placeholder="' . esc_attr__( 'For example: website review, March', 'launch-builder' ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Delivery', 'launch-builder' ) . '</th><td><fieldset><label><input type="radio" name="delivery" value="copy" checked> ' . esc_html__( 'Show it here once so I can copy it', 'launch-builder' ) . '</label><br><label><input type="radio" name="delivery" value="email"> ' . esc_html__( 'Email it to the account\'s own address', 'launch-builder' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Email logging plugins (WP Mail SMTP, FluentSMTP) keep a copy of every email, so emailed links default to 3 days and one use.', 'launch-builder' ) . '</p></fieldset></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Make link', 'launch-builder' ) );
		echo '</form></div>';
		echo '<script>(function(){var f=document.getElementById("lbll-create");if(!f)return;f.addEventListener("change",function(e){if(e.target.name==="delivery"&&e.target.value==="email"){var d=f.querySelector("[name=days]");if(+d.value>3)d.value=3;f.querySelector("[name=uses][value=single]").checked=true}if(e.target.name==="redirect"){f.querySelector("[name=redirect_choice]").value="path"}})})();</script>';
	}

	private static function short_reason( \WP_Error $e ): string {
		$map = [
			'lb_ll_self'                 => __( 'your own account', 'launch-builder' ),
			'lb_ll_admin_target'         => __( 'administrator-level', 'launch-builder' ),
			'lb_ll_target_more_powerful' => __( 'has rights you lack', 'launch-builder' ),
			'lb_ll_two_factor'           => __( 'uses two-factor login', 'launch-builder' ),
			'lb_ll_super_admin'          => __( 'network administrator', 'launch-builder' ),
			'lb_ll_creator_cannot_edit'  => __( 'you cannot edit it', 'launch-builder' ),
		];
		return $map[ $e->get_error_code() ] ?? __( 'not allowed', 'launch-builder' );
	}

	private static function page_list(): void {
		$all = isset( $_GET['status'] ) && 'all' === $_GET['status']; // phpcs:ignore WordPress.Security.NonceVerification -- display filter.
		$list = self::list_links( [ 'status' => $all ? 'all' : 'active', 'limit' => 500 ] );
		$me = get_current_user_id();
		echo '<h2>' . esc_html__( 'Links', 'launch-builder' ) . '</h2>';
		echo '<ul class="subsubsub"><li><a href="' . esc_url( self::page_url() ) . '"' . ( $all ? '' : ' class="current"' ) . '>' . esc_html__( 'Active', 'launch-builder' ) . '</a> | </li><li><a href="' . esc_url( self::page_url( [ 'status' => 'all' ] ) ) . '"' . ( $all ? ' class="current"' : '' ) . '>' . esc_html__( 'All (last 30 days)', 'launch-builder' ) . '</a></li></ul>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Account', 'launch-builder' ), __( 'Note', 'launch-builder' ), __( 'Made', 'launch-builder' ), __( 'Expires', 'launch-builder' ), __( 'Uses', 'launch-builder' ), __( 'Last used', 'launch-builder' ), __( 'Opens', 'launch-builder' ), __( 'Status', 'launch-builder' ), '' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $list['links'] ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No links yet.', 'launch-builder' ) . '</td></tr>';
		}
		$labels = [ 'active' => __( 'Active', 'launch-builder' ), 'expired' => __( 'Expired', 'launch-builder' ), 'revoked' => __( 'Revoked', 'launch-builder' ), 'used' => __( 'Used up', 'launch-builder' ) ];
		foreach ( $list['links'] as $p ) {
			$row = [ 'user_id' => $p['user_id'], 'selector' => $p['id'] ];
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( $p['user_id'] ) ) . '">' . esc_html( $p['user_login'] ) . '</a><br><code style="font-size:11px">' . esc_html( substr( $p['id'], 0, 6 ) ) . '</code></td>';
			echo '<td>' . esc_html( '' !== $p['note'] ? $p['note'] : '-' ) . '</td>';
			echo '<td>' . esc_html( self::when( $p['created_at'] ) ) . '<br><small>' . esc_html( $p['created_by_login'] ) . '</small></td>';
			echo '<td>' . esc_html( self::when( $p['expires_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $p['uses'] . ( $p['max_uses'] ? ' / ' . $p['max_uses'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( self::when( $p['last_used_at'] ) ) . ( '' !== $p['last_ip'] ? '<br><small>' . esc_html( $p['last_ip'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( '' !== $p['redirect'] ? $p['redirect'] : __( 'default', 'launch-builder' ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $p['status'] ] ?? $p['status'] ) . ( $p['revoked_reason'] ? '<br><small>' . esc_html( str_replace( '_', ' ', $p['revoked_reason'] ) ) . '</small>' : '' ) . '</td><td>';
			if ( 'active' === $p['status'] && self::can_manage_row( $me, $row + [ 'rec' => [] ] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="lb_ll_revoke"><input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '">';
				wp_nonce_field( 'lb_ll_revoke_' . $p['id'], '_wpnonce', false );
				echo '<button class="button button-small">' . esc_html__( 'Revoke', 'launch-builder' ) . '</button> <label style="font-size:12px"><input type="checkbox" name="sign_out" value="1"> ' . esc_html__( 'and end its sessions', 'launch-builder' ) . '</label></form> ';
			}
			if ( in_array( $p['status'], [ 'active', 'expired' ], true ) && true === self::check_policy( wp_get_current_user(), get_userdata( $p['user_id'] ), 'create' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="lb_ll_extend"><input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '">';
				wp_nonce_field( 'lb_ll_extend_' . $p['id'], '_wpnonce', false );
				/* translators: %d: number of days */
				echo '<input type="hidden" name="days" value="' . esc_attr( (string) self::settings()['default_days'] ) . '"><button class="button button-small">' . esc_html( sprintf( __( 'Extend to %d days from now', 'launch-builder' ), self::settings()['default_days'] ) ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function page_settings(): void {
		$s = self::settings();
		echo '<div class="card" style="max-width:760px;margin-top:24px"><h2>' . esc_html__( 'Settings', 'launch-builder' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_ll_settings">';
		wp_nonce_field( 'lb_ll_settings' );
		echo '<p><label>' . esc_html__( 'New links work for', 'launch-builder' ) . ' <input type="number" name="default_days" min="1" max="' . esc_attr( (string) self::max_days() ) . '" value="' . esc_attr( (string) $s['default_days'] ) . '" class="small-text"> ' . esc_html__( 'days by default', 'launch-builder' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="revoke_on_password_change" value="1"' . checked( $s['revoke_on_password_change'], true, false ) . '> ' . esc_html__( 'Revoke an account\'s links when its password changes', 'launch-builder' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="revoke_on_role_change" value="1"' . checked( $s['revoke_on_role_change'], true, false ) . '> ' . esc_html__( 'Revoke an account\'s links when its role changes', 'launch-builder' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="allow_admin_targets" value="1"' . checked( $s['allow_admin_targets'], true, false ) . '> ' . esc_html__( 'Allow links to administrator-level accounts', 'launch-builder' ) . '</label><br><span class="description">' . esc_html__( 'Off by default. When on, each use is emailed to the site admin address. Network administrators never get links.', 'launch-builder' ) . '</span></p>';
		submit_button( __( 'Save settings', 'launch-builder' ), 'secondary', 'submit', false );
		echo '</form><hr>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Revoke every login link on this site?', 'launch-builder' ) ) ) . ')"><input type="hidden" name="action" value="lb_ll_revoke"><input type="hidden" name="all" value="1">';
		wp_nonce_field( 'lb_ll_revoke_all' );
		echo '<p><button class="button">' . esc_html__( 'Revoke every link', 'launch-builder' ) . '</button> ';
		if ( ! defined( 'LB_LOGIN_LINKS' ) && ! self::registry() ) {
			echo '</p></form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_ll_enable"><input type="hidden" name="on" value="0">';
			wp_nonce_field( 'lb_ll_enable' );
			echo '<p><button class="button">' . esc_html__( 'Turn login links off', 'launch-builder' ) . '</button>';
		}
		echo '</p></form>';
		echo '<p class="description">' . esc_html__( 'Changing the security keys in wp-config.php also ends every link. Old links are deleted 30 days after they expire or are revoked.', 'launch-builder' ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ admin-post handlers */

	public static function handle_enable(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_ll_enable' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		if ( defined( 'LB_LOGIN_LINKS' ) || self::registry() ) { // The constant or the Modules screen owns the switch.
			self::flash( 'error', self::how_to_enable() );
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$on = ! empty( $_POST['on'] );
		update_option( self::OPT_ENABLED, $on ? '1' : '0', true );
		if ( ! $on ) {
			wp_clear_scheduled_hook( self::GC_HOOK );
		}
		self::log( 'login_link.module', true, $on ? 'turned on' : 'turned off', get_current_user_id() );
		self::flash( 'success', $on ? __( 'Login links are on.', 'launch-builder' ) : __( 'Login links are off. No link works until they are turned back on.', 'launch-builder' ) );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_revoke(): void {
		$me = get_current_user_id();
		$in = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security -- nonce checked per branch below.
		$sign_out = ! empty( $in['sign_out'] );
		if ( ! empty( $in['all'] ) ) {
			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_ll_revoke_all' ) ) {
				wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
			}
			$n = self::revoke_many( 0, $me, 'revoke_all' );
			/* translators: %d: number of links */
			self::flash( 'success', sprintf( _n( '%d link revoked.', '%d links revoked.', $n, 'launch-builder' ), $n ) );
			self::back();
		}
		$id = (string) ( $in['id'] ?? '' );
		if ( ! preg_match( self::SEL_RE, $id ) || ! check_admin_referer( 'lb_ll_revoke_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$r = self::revoke( $id, $me, 'manual', $sign_out );
		self::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Link revoked.', 'launch-builder' ) );
		self::back();
	}

	public static function handle_extend(): void {
		$id = isset( $_POST['id'] ) ? (string) wp_unslash( $_POST['id'] ) : ''; // phpcs:ignore WordPress.Security -- nonce checked next.
		if ( ! preg_match( self::SEL_RE, $id ) || ! check_admin_referer( 'lb_ll_extend_' . $id ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$r = self::extend( $id, absint( $_POST['days'] ?? 0 ), get_current_user_id() );
		self::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Link extended.', 'launch-builder' ) );
		self::back();
	}

	public static function handle_settings(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_ll_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$old = self::settings();
		$new = [
			'default_days'              => max( 1, min( self::max_days(), absint( $_POST['default_days'] ?? 7 ) ) ),
			'allow_admin_targets'       => ! empty( $_POST['allow_admin_targets'] ),
			'revoke_on_password_change' => ! empty( $_POST['revoke_on_password_change'] ),
			'revoke_on_role_change'     => ! empty( $_POST['revoke_on_role_change'] ),
		];
		update_option( self::OPT, $new, false );
		$changed = [];
		foreach ( $new as $k => $v ) {
			if ( $old[ $k ] !== $v ) {
				$changed[] = $k . '=' . ( is_bool( $v ) ? ( $v ? 'on' : 'off' ) : $v );
			}
		}
		if ( $changed ) {
			self::log( 'login_link.settings', true, implode( ', ', $changed ), get_current_user_id() );
		}
		self::flash( 'success', __( 'Settings saved.', 'launch-builder' ) );
		self::back();
	}

	/* ------------------------------------------------------------------ the old login-link plugin */

	/** Accounts with an old plaintext pkg_autologin_code. */
	public static function legacy_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", 'pkg_autologin_code' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Deletes the old plugin's plaintext codes (refused while it is active: that would break its links without warning). */
	public static function handle_legacy_delete(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_ll_legacy_delete' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		if ( defined( 'PKG_AUTOLOGIN_VERSION' ) ) {
			self::flash( 'error', __( 'Deactivate the old login-link plugin first.', 'launch-builder' ) );
			self::back();
		}
		$n = self::legacy_count();
		foreach ( [ 'pkg_autologin_code', 'pkg_autologin_staged_code', 'pkg_autologin_staged_code_nonce' ] as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		self::log( 'login_link.legacy_deleted', true, $n . ' old autologin code(s) deleted', get_current_user_id() );
		/* translators: %d: number of accounts */
		self::flash( 'success', sprintf( _n( 'Deleted the old code of %d account.', 'Deleted the old codes of %d accounts.', $n, 'launch-builder' ), $n ) );
		self::back();
	}

	/* ------------------------------------------------------------------ site-state, playbook, audit */

	/** site-state login_links (Login_Links_Guard adds it, so it is there while the module is off too). Counts only. */
	public static function state(): array {
		$on = self::enabled();
		return [
			'enabled'                 => $on,
			'active'                  => $on ? self::list_links( [ 'status' => 'active', 'limit' => 1 ] )['total'] : 0,
			'admin_targets_allowed'   => $on && self::settings()['allow_admin_targets'],
			'unlogged_failures'       => self::unlogged(),
			'reference_plugin_active' => defined( 'PKG_AUTOLOGIN_VERSION' ),
			'legacy_codes_found'      => self::legacy_count(),
		];
	}

	/** get-playbook login_links (added by Login_Links_Guard). */
	public static function playbook_text(): string {
		return 'Login links (Users > Login links, a module that is off until an administrator turns it on) are expiring one-click sign-in links for client accounts. A person makes them in wp-admin or with WP-CLI (wp launch-builder login-link); there is no MCP tool for them on purpose (a connected assistant must never be able to mint sign-in sessions). site-state.login_links shows whether the module is on and how many links are active; audit-site reports risky settings.';
	}

	/** Module hook (only while the module is on; the old-plugin check is Login_Links_Guard's). @return array code => [scope, callable] */
	public static function audit_checks(): array {
		return [
			'login_links_admin_targets_on' => [ 'security', [ __CLASS__, 'audit_admin_targets' ] ],
			'login_links_long_lived'       => [ 'security', [ __CLASS__, 'audit_long_lived' ] ],
			'login_links_many_active'      => [ 'security', [ __CLASS__, 'audit_many' ] ],
		];
	}

	private static function finding( string $code, string $severity, string $message, string $fix ): array {
		return [ 'severity' => $severity, 'code' => $code, 'object_type' => 'site', 'id' => 0, 'url' => self::page_url(), 'message' => $message, 'fix' => $fix ];
	}

	public static function audit_legacy(): array {
		$out = [];
		if ( defined( 'PKG_AUTOLOGIN_VERSION' ) ) {
			$out[] = self::finding( 'login_links_reference_plugin_active', 'warning', 'The old login-link plugin is active: it stores permanent sign-in codes in plain text, works for administrators and runs on any GET request.', 'Make Launch Builder login links for the people who need them, then deactivate and delete that plugin (its links stop working at once).' );
		}
		$n = self::legacy_count();
		if ( $n ) {
			$out[] = self::finding( 'login_links_legacy_plaintext', defined( 'PKG_AUTOLOGIN_VERSION' ) ? 'warning' : 'info', $n . ' account(s) have an old login-link code stored in plain text (usermeta pkg_autologin_code).', 'After deactivating that plugin, delete the old codes under Users > Login links.' );
		}
		return $out;
	}

	public static function audit_admin_targets(): array {
		return self::enabled() && self::settings()['allow_admin_targets'] ? [ self::finding( 'login_links_admin_targets_on', 'warning', 'Login links to administrator-level accounts are allowed.', 'Turn the setting off unless you need it right now (Users > Login links > Settings).' ) ] : [];
	}

	public static function audit_long_lived(): array {
		if ( ! self::enabled() ) {
			return [];
		}
		$n = 0;
		foreach ( self::list_links( [ 'limit' => 500 ] )['links'] as $p ) {
			$n += $p['expires_at'] > time() + 14 * DAY_IN_SECONDS ? 1 : 0;
		}
		return $n ? [ self::finding( 'login_links_long_lived', 'info', $n . ' active login link(s) have more than 14 days left.', 'Prefer short links; revoke the ones no longer needed.' ) ] : [];
	}

	public static function audit_many(): array {
		if ( ! self::enabled() ) {
			return [];
		}
		$n = self::list_links( [ 'limit' => 1 ] )['total'];
		return $n > 20 ? [ self::finding( 'login_links_many_active', 'warning', $n . ' login links are active.', 'Revoke the ones no longer needed (Users > Login links).' ) ] : [];
	}
}

/**
 * The always-on part of Login links: listed in Plugin::MODULES right after Login_Links (this file is loaded by then),
 * not switchable, and it adds nothing to the login page or the front end. The Modules registry does not boot a module
 * that is off, but these must keep working while Login links is off:
 *  - LB_LOGIN_LINKS in wp-config.php (filter launch_builder_module_enabled; and Login_Links::boot() here, for "true"
 *    while the registry has the module off, since the registry decides before this filter exists);
 *  - the notice while the old login-link plugin is active (its links stop the moment it is deactivated) and the
 *    audit check for its plain-text codes;
 *  - Users > Login links for administrators (it says how to turn the module on), site-state, playbook, WP-CLI;
 *  - clearing the GC cron when the plugin is deactivated.
 */
final class Login_Links_Guard {

	const MODULE = [
		'slug'        => 'login-links-guard',
		'label'       => 'Login links: safety checks',
		'description' => 'Always on and light: warns while the old login-link plugin is active, reports its plain-text codes in the audit, and keeps the Login links switch reachable. Adds nothing to the login page.',
		'toggle'      => false,
	];

	public static function boot(): void {
		add_filter( 'launch_builder_module_enabled', [ Login_Links::class, 'module_switch' ], 10, 3 );
		Login_Links::boot(); // Runs once: a no-op when the registry booted it already or when the module is off.
		add_action( 'admin_notices', [ Login_Links::class, 'notices' ] );
		add_action( 'admin_menu', [ Login_Links::class, 'menu' ] );
		add_action( 'admin_post_lb_ll_enable', [ Login_Links::class, 'handle_enable' ] );
		add_action( 'admin_post_lb_ll_legacy_delete', [ Login_Links::class, 'handle_legacy_delete' ] );
		if ( ! Login_Links::enabled() ) {
			add_action( 'admin_init', [ Login_Links::class, 'unschedule' ] );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'launch-builder login-link', Login_Links_CLI::class );
		}
	}

	/** Module hook: plugin deactivated (Login_Links' own deactivate() is skipped while the module is off). */
	public static function deactivate(): void {
		Login_Links::deactivate();
	}

	public static function site_state( array &$state ): void {
		$state['login_links'] = Login_Links::state();
	}

	public static function playbook( array &$pb ): void {
		$pb['login_links'] = Login_Links::playbook_text();
	}

	/** The old plugin's plain-text codes are a risk whether or not Login links is on. */
	public static function audit_checks(): array {
		return [ 'login_links_legacy_plaintext' => [ 'security', [ Login_Links::class, 'audit_legacy' ] ] ];
	}
}

/**
 * wp launch-builder login-link <create|list|revoke|extend|gc>. Run as a user with --user=<login>; the same rules apply
 * as in wp-admin (no CLI bypass). Lives in this file because only Login_Links_Guard registers it.
 */
final class Login_Links_CLI {

	private function me(): \WP_User {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->exists() ) {
			\WP_CLI::error( 'Run as a user with the global flag, e.g. --user=admin.' );
		}
		if ( ! Login_Links::enabled() ) {
			\WP_CLI::error( 'Login links are off. ' . Login_Links::how_to_enable() );
		}
		return $u;
	}

	private function user( string $who ): \WP_User {
		$u = ctype_digit( $who ) ? get_userdata( (int) $who ) : ( is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who ) );
		if ( ! $u instanceof \WP_User ) {
			\WP_CLI::error( 'No such user: ' . $who );
		}
		return $u;
	}

	/**
	 * Make a sign-in link for a user. The link is printed once (only a hash is stored).
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * [--days=<days>]
	 * : Lifetime in days (1-30). Default: the site setting (7).
	 *
	 * [--single-use]
	 * : The link works once.
	 *
	 * [--max-uses=<n>]
	 * : The link works up to n times (0 = until it expires).
	 *
	 * [--redirect=<path>]
	 * : Page of this site to open after sign-in, e.g. /my-account/.
	 *
	 * [--note=<text>]
	 * : Note shown in the list (120 characters).
	 *
	 * [--email]
	 * : Email the link to the account's own address instead of printing it.
	 *
	 * [--porcelain]
	 * : Print only the URL.
	 */
	public function create( $args, $assoc ) {
		$me = $this->me();
		$target = $this->user( (string) ( $args[0] ?? '' ) );
		$r = Login_Links::create(
			$target->ID,
			[
				'days'     => $assoc['days'] ?? '',
				'max_uses' => isset( $assoc['single-use'] ) ? 1 : (int) ( $assoc['max-uses'] ?? 0 ),
				'redirect' => (string) ( $assoc['redirect'] ?? '' ),
				'note'     => (string) ( $assoc['note'] ?? '' ),
				'email'    => isset( $assoc['email'] ),
			],
			$me->ID
		);
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		if ( $r['emailed'] ) {
			\WP_CLI::success( 'Link ' . $r['id'] . ' emailed to ' . $target->user_login . "'s own address." );
			return;
		}
		if ( isset( $assoc['porcelain'] ) ) {
			\WP_CLI::line( $r['url'] );
			return;
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' for ' . $target->user_login . ', valid until ' . gmdate( 'Y-m-d H:i', (int) $r['record']['expires_at'] ) . ' UTC. Anyone with it can sign in as ' . $target->user_login . '. It is shown once:' );
		\WP_CLI::line( $r['url'] );
	}

	/**
	 * List links (metadata only, never secrets).
	 *
	 * [--account=<user>]
	 * : Only this account (ID, login or email). WP-CLI's global --user selects who runs the command.
	 *
	 * [--status=<status>]
	 * : active (default) or all.
	 *
	 * [--format=<format>]
	 * : table (default), json or csv.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$me = $this->me();
		$uid = 0;
		if ( ! empty( $assoc['account'] ) ) {
			$uid = $this->user( (string) $assoc['account'] )->ID;
		}
		if ( ! user_can( $me, Login_Links::CAP ) && $uid !== $me->ID ) {
			$uid = $me->ID; // Without the manage capability you see only links to your own account.
		}
		$list = Login_Links::list_links( [ 'user_id' => $uid, 'status' => 'all' === ( $assoc['status'] ?? '' ) ? 'all' : 'active', 'limit' => 500 ] );
		$items = array_map(
			static function ( $p ) {
				foreach ( [ 'created_at', 'expires_at', 'last_used_at', 'revoked_at' ] as $k ) {
					$p[ $k ] = $p[ $k ] ? gmdate( 'Y-m-d H:i', $p[ $k ] ) : '';
				}
				return $p;
			},
			$list['links']
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $items, [ 'id', 'user_login', 'status', 'expires_at', 'uses', 'max_uses', 'last_used_at', 'created_by_login', 'redirect', 'note' ] );
	}

	/**
	 * Revoke one link, every link of an account, or every link on the site.
	 *
	 * [<id>]
	 * : Link id (from list).
	 *
	 * [--account=<user>]
	 * : Revoke every link of this account.
	 *
	 * [--all]
	 * : Revoke every link on the site (needs manage_options).
	 *
	 * [--sign-out]
	 * : Also end the sessions the links started.
	 */
	public function revoke( $args, $assoc ) {
		$me = $this->me();
		$sign_out = isset( $assoc['sign-out'] );
		if ( isset( $assoc['all'] ) ) {
			if ( ! user_can( $me, 'manage_options' ) ) {
				\WP_CLI::error( 'Revoking every link needs manage_options.' );
			}
			\WP_CLI::success( Login_Links::revoke_many( 0, $me->ID, 'revoke_all' ) . ' link(s) revoked.' );
			return;
		}
		if ( ! empty( $assoc['account'] ) ) {
			$u = $this->user( (string) $assoc['account'] );
			if ( $u->ID !== $me->ID && ! ( user_can( $me, Login_Links::CAP ) && user_can( $me, 'edit_user', $u->ID ) ) ) {
				\WP_CLI::error( 'You cannot revoke links of ' . $u->user_login . '.' );
			}
			\WP_CLI::success( Login_Links::revoke_many( $u->ID, $me->ID, 'manual', $sign_out ) . ' link(s) revoked.' );
			return;
		}
		$r = Login_Links::revoke( (string) ( $args[0] ?? '' ), $me->ID, 'manual', $sign_out );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' revoked.' );
	}

	/**
	 * Move a link's expiry to <days> from now (same URL).
	 *
	 * <id>
	 * : Link id.
	 *
	 * --days=<days>
	 * : New lifetime from now (1-30).
	 */
	public function extend( $args, $assoc ) {
		$me = $this->me();
		$r = Login_Links::extend( (string) ( $args[0] ?? '' ), (int) ( $assoc['days'] ?? 0 ), $me->ID );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' now expires ' . gmdate( 'Y-m-d H:i', (int) $r['expires_at'] ) . ' UTC.' );
	}

	/** Delete links that expired, were revoked or used up more than 30 days ago. */
	public function gc( $args, $assoc ) {
		$me = $this->me();
		if ( ! user_can( $me, 'manage_options' ) ) {
			\WP_CLI::error( 'gc needs manage_options.' );
		}
		\WP_CLI::success( Login_Links::gc() . ' old link(s) deleted.' );
	}
}
