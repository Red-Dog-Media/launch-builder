<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Local avatars: profile photos come from the Media Library, never from Gravatar when a user has one, and (optionally)
 * never from Gravatar at all.
 *
 * - Storage: user meta `_lb_avatar` = attachment id (`_lb_avatar_<blog_id>` on multisite blogs other than 1; 0 = removed
 *   on purpose, so an old photo from the other avatars plugin does not come back). The attachment carries `_lb_avatar` = 1, which
 *   asks for square subsizes (lb-avatar-192, lb-avatar-640, filter launch_builder_avatar_sizes) made through the core
 *   subsize API when the photo is assigned: they are listed in the attachment metadata, deleted with it, seen by image
 *   optimisers, and nothing is ever resized or written while a page renders.
 * - Rendering: one pre_get_avatar_data filter (get_avatar, get_avatar_url, the core/avatar block, REST avatar_urls, admin
 *   lists, Rank Math's author schema). A user with a local photo gets its smallest square size >= the requested size and
 *   no Gravatar URL is built. With Gravatar off (setting `gravatar` false, which also sets core avatar_default to
 *   'launch_builder'), everyone else gets the local default: initials, the site icon or logo, a silhouette, blank or a
 *   chosen image. The silhouette is a static file (assets/avatar-default.svg). Initials are a tiny SVG per pair of
 *   letters on their own path (<rest prefix>/launch-builder/v1/avatar/AB), answered at plugin load, before the other
 *   plugins, the theme and init, and cacheable for a year (never on the home URL).
 * - Kadence Theme Kit Pro "Local Gravatars" downloads every avatar image it sees into wp-content/gravatars by file name.
 *   When it is on, its filter is wrapped so it only ever sees Gravatar images (local photos and defaults pass untouched).
 * - The other avatars plugin: while it is active it renders and we stay out of rendering; set-user-avatar and the
 *   profile writes go through its own methods, and changes made in its own screens are mirrored into our meta (its
 *   _updated / _deleted actions). Core's avatar_default is never changed while it renders (it reads
 *   that option itself); the Gravatar switch reaches core when it is deactivated. Its user meta is read as a fallback
 *   when it is inactive and imported by the Migrate step avatars-import-sla (never modified, so reactivating it is the
 *   rollback).
 * - Who may set whose photo: upload_files always; edit_user on someone else; only administrators change administrators;
 *   the image must be one the caller can edit (or the caller's own upload for their own photo), readable, and a JPEG,
 *   PNG, WebP, AVIF or GIF. Same rules in the profile screen and the tools. No AJAX endpoints, no subscriber uploads.
 *
 * Toggleable module (MODULE, default on), per the Modules contract: the Modules registry (Modules::enabled(), screen and
 * set-modules tool) decides, and calls activate() / deactivate() when the switch changes (core's avatar_default is taken
 * again / handed back). Without the registry, the same option is read here (launch_builder_disabled_modules: slug => 0|1,
 * or a plain list of slugs) and changes to it run activate() / deactivate() through its option hooks.
 */
final class Avatars {

	/** Modules contract: identity, label and default state of this toggleable module. */
	const MODULE = [
		'slug'        => 'avatars',
		'label'       => 'Local avatars',
		'description' => 'Profile photos from the Media Library instead of Gravatar, with an optional no-Gravatar mode, a profile photo field and the set-user-avatar tool.',
		'toggle'      => true,
		'default'     => false,
		'note'        => 'Turning it off shows Gravatar images again and hands WordPress\' default avatar back; photo links and settings are kept for when it is turned back on.',
	];

	const OPT          = 'launch_builder_avatars';
	const OPT_IMPORT   = 'launch_builder_avatars_import';
	const OPT_DISABLED = 'launch_builder_disabled_modules';
	const META         = '_lb_avatar';
	const META_ANCHOR  = '_lb_avatar_anchor';
	const SLA_META     = 'simple' . '_local_avatar'; // User meta and avatar_default value of the other avatars plugin (assembled: not spelled out in source).
	const SLA_RATING   = 'simple' . '_local_avatar_rating';
	const SLA_OPT      = 'simple' . '_local_avatars'; // Its settings option and global object.
	const SLA_DEFAULT_OPT = 'simple' . '_local_avatar_default';
	const SLA_CLASS    = 'Simple' . '_Local_Avatars';
	const SLA_SLUG     = 'simple-local' . '-avatars';
	const CORE_DEFAULT = 'launch_builder'; // Value of core's avatar_default that means "the local default".
	const ROUTE        = 'launch-builder/v1/avatar'; // Initials images: rest_url( ROUTE . '/AB' ), answered in boot().
	const SLA_FILE     = 'simple-local' . '-avatars.php'; // Main file of the other avatars plugin (any folder name).
	const MIMES        = [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ];
	const SIZES        = [ 192, 640 ];
	const CHOICES      = [ 'initials', 'logo', 'silhouette', 'blank' ];
	const PRESET_PREFIX = 'preset:'; // A default (or a choice) that names one of the ready-made pictures: preset:<slug>.
	const META_PRESET  = '_lb_avatar_preset'; // A user's own pick among the ready-made pictures (a photo of their own wins over it).
	const DEFAULTS     = [ 'gravatar' => true, 'default' => 'preset:launchlocal', 'anchor' => 'top', 'self_service' => true ];
	const COLORS       = [ '#1d4ed8', '#047857', '#b91c1c', '#7e22ce', '#c2410c', '#0f766e', '#4338ca', '#be185d' ]; // White text >= 4.5:1 on each.

	/** @var array|null settings, per request. */
	private static $settings = null;

	/** @var callable|null Kadence Pro's local Gravatars filter, when wrapped. */
	private static $kadence = null;

	/** @var string|null error from the profile save, reported through user_profile_update_errors. */
	private static $profile_error = null;

	/** @var array<string,string> resolved URLs this request ("uid:px" => url). */
	private static $memo = [];

	/** @var bool true while we call the other avatars plugin ourselves (its actions then report our own change). */
	private static $delegating = false;

	/** @var bool the other avatars plugin was deactivated in this request (still loaded, but it no longer renders). */
	private static $sla_gone = false;

	/* ------------------------------------------------------------------ module state */

	/** Is the module on? The Modules registry decides when it is installed; otherwise one autoloaded option read. */
	public static function enabled(): bool {
		if ( self::registry() ) {
			try {
				return (bool) call_user_func( [ __NAMESPACE__ . '\\Modules', 'enabled' ], __CLASS__ );
			} catch ( \Throwable $e ) {
				unset( $e ); // Registry present but failing: the option below decides.
			}
		}
		$on = ! self::off_in( get_option( self::OPT_DISABLED, [] ) );
		return (bool) apply_filters( 'launch_builder_module_enabled', $on, self::MODULE['slug'], __CLASS__ );
	}

	/** Does the shared Modules registry own the switch (and call activate() / deactivate() itself)? */
	private static function registry(): bool {
		static $has = null;
		if ( null === $has ) {
			$has = class_exists( __NAMESPACE__ . '\\Modules' ) && is_callable( [ __NAMESPACE__ . '\\Modules', 'enabled' ] );
		}
		return $has;
	}

	/** Is this module switched off in a launch_builder_disabled_modules value (slug => 0|1, or a list of slugs)? */
	private static function off_in( $value ): bool {
		$slug = self::MODULE['slug'];
		return is_array( $value ) && ( in_array( $slug, $value, true ) || ( array_key_exists( $slug, $value ) && ! $value[ $slug ] ) );
	}

	/** @return array{gravatar:bool,default:string|int,anchor:string,self_service:bool} */
	/**
	 * The ready-made pictures that ship with the plugin, slug => [ label, url ]. Updates add more files under assets/avatars
	 * and more entries here; other code can add its own with the launch_builder_avatar_presets filter (slug, label and a
	 * file name under assets/avatars, or a full https URL).
	 *
	 * @return array<string,array{label:string,url:string}>
	 */
	public static function presets(): array {
		$list = [
			'launchlocal' => [ 'label' => __( 'LaunchLocal', 'launch-builder' ), 'file' => 'launchlocal.svg' ],
		];
		$list = (array) apply_filters( 'launch_builder_avatar_presets', $list );
		$out = [];
		foreach ( $list as $slug => $p ) {
			$slug = is_string( $slug ) ? $slug : '';
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $slug ) || ! is_array( $p ) ) {
				continue;
			}
			$url = '';
			if ( ! empty( $p['url'] ) && 0 === strpos( (string) $p['url'], 'https://' ) ) {
				$url = esc_url_raw( (string) $p['url'], [ 'https' ] );
			} elseif ( ! empty( $p['file'] ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', (string) $p['file'] ) ) {
				$url = LB_URL . 'assets/avatars/' . $p['file'];
			}
			if ( '' !== $url ) {
				$out[ $slug ] = [ 'label' => sanitize_text_field( (string) ( $p['label'] ?? $slug ) ), 'url' => $url ];
			}
		}
		return $out;
	}

	/** The slug of a ready-made picture from "preset:<slug>" or a bare slug; '' when there is no such picture. */
	public static function preset_slug( string $value ): string {
		$slug = 0 === strpos( $value, self::PRESET_PREFIX ) ? substr( $value, strlen( self::PRESET_PREFIX ) ) : $value;
		return ( '' !== $slug && isset( self::presets()[ $slug ] ) ) ? $slug : '';
	}

	public static function preset_url( string $slug ): string {
		$p = self::presets();
		return isset( $p[ $slug ] ) ? $p[ $slug ]['url'] : '';
	}

	/** The ready-made picture this user chose ('' = none). */
	public static function user_preset( int $user_id ): string {
		return $user_id > 0 ? self::preset_slug( (string) get_user_meta( $user_id, self::META_PRESET, true ) ) : '';
	}

	public static function settings(): array {
		if ( null === self::$settings ) {
			$s = get_option( self::OPT, [] );
			self::$settings = self::normalise( is_array( $s ) ? $s : [] );
		}
		return self::$settings;
	}

	private static function normalise( array $in ): array {
		$out = self::DEFAULTS;
		if ( array_key_exists( 'gravatar', $in ) ) {
			$out['gravatar'] = (bool) $in['gravatar'];
		}
		$d = $in['default'] ?? self::DEFAULTS['default'];
		if ( is_int( $d ) || ( is_string( $d ) && ctype_digit( $d ) ) ) {
			$out['default'] = (int) $d > 0 ? (int) $d : 'initials';
		} elseif ( in_array( $d, self::CHOICES, true ) ) {
			$out['default'] = $d;
		} elseif ( is_string( $d ) && '' !== self::preset_slug( $d ) ) {
			$out['default'] = self::PRESET_PREFIX . self::preset_slug( $d );
		} elseif ( is_string( $d ) && 0 === strpos( $d, self::PRESET_PREFIX ) ) {
			$out['default'] = 'initials'; // A ready-made picture a later version no longer ships.
		}
		if ( isset( $in['anchor'] ) && in_array( $in['anchor'], [ 'top', 'center' ], true ) ) {
			$out['anchor'] = $in['anchor'];
		}
		if ( array_key_exists( 'self_service', $in ) ) {
			$out['self_service'] = (bool) $in['self_service'];
		}
		return $out;
	}

	/** @param bool $explicit the caller asked for Gravatar on just now (see sync_core_default()). */
	private static function save_settings( array $s, bool $explicit = false ): void {
		update_option( self::OPT, self::normalise( $s ), true );
		self::$settings = null;
		self::$memo = [];
		if ( self::enabled() ) { // While the module is off, core keeps its own default (activate() / the next save sync it).
			self::sync_core_default( null, $explicit );
		}
		Cache::purge_later();
	}

	/**
	 * Keep core's avatar_default in line with the Gravatar switch (the Discussion screen and other code read it): Gravatar
	 * off = 'launch_builder'; Gravatar on = never 'launch_builder'. Never while the other avatars plugin renders: it reads
	 * avatar_default itself and turns any value it does not know into a Gravatar URL, so the switch reaches core when it
	 * is deactivated (sla_plugin_changed(), else the next wp-admin load: admin_init()). its own avatar_default value
	 * (which we read as "the local default") is replaced only when the caller turned Gravatar on explicitly.
	 */
	private static function sync_core_default( ?bool $gravatar = null, bool $explicit = false ): void {
		if ( self::sla_active() ) {
			return;
		}
		$gravatar = null === $gravatar ? self::settings()['gravatar'] : $gravatar;
		$cur = (string) get_option( 'avatar_default', 'mystery' );
		if ( ! $gravatar && self::CORE_DEFAULT !== $cur ) {
			update_option( 'avatar_default', self::CORE_DEFAULT );
		} elseif ( $gravatar && ( self::CORE_DEFAULT === $cur || ( $explicit && self::SLA_META === $cur ) ) ) {
			update_option( 'avatar_default', 'mystery' );
		}
	}

	/** Hand core's avatar_default back ('launch_builder' means nothing to core or to the other avatars plugin). */
	private static function release_core_default(): void {
		if ( self::CORE_DEFAULT === get_option( 'avatar_default' ) ) {
			update_option( 'avatar_default', 'mystery' );
		}
	}

	/** User meta key for this site (attachment ids are per site). */
	public static function meta_key(): string {
		$blog = get_current_blog_id();
		return ( is_multisite() && 1 !== $blog ) ? self::META . '_' . $blog : self::META;
	}

	/**
	 * Is the other avatars plugin loaded (it renders and keeps its own meta)? Checked late: it loads after this plugin. False
	 * for the rest of the request in which it was deactivated.
	 */
	public static function sla_active(): bool {
		return ! self::$sla_gone && isset( $GLOBALS[ self::SLA_OPT ] ) && is_object( $GLOBALS[ self::SLA_OPT ] ) && class_exists( self::SLA_CLASS, false );
	}

	/** Kadence Theme Kit Pro's "Local Gravatars" module switched on? */
	public static function kadence_localgravatars(): bool {
		if ( ! Plugin::requirement_met( 'kadence-pro' ) ) {
			return false;
		}
		$cfg = json_decode( (string) get_option( 'kadence_pro_theme_config', '' ), true );
		return is_array( $cfg ) && true === ( $cfg['localgravatars'] ?? null );
	}

	/** Do users without a local photo get the local default (no Gravatar)? */
	private static function local_default( array $args ): bool {
		return ! self::settings()['gravatar'] || in_array( (string) ( $args['default'] ?? '' ), [ self::CORE_DEFAULT, self::SLA_META ], true );
	}

	/* ------------------------------------------------------------------ boot */

	public static function boot(): void {
		if ( ! self::registry() ) {
			// No Modules registry: watch the switch ourselves (also while off; these fire only when the option changes).
			foreach ( [ 'update_option_', 'add_option_', 'delete_option_' ] as $prefix ) {
				add_action( $prefix . self::OPT_DISABLED, [ __CLASS__, 'modules_changed' ], 10, 3 );
			}
		}
		if ( ! self::enabled() ) {
			return; // A disabled site pays one autoloaded option read.
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- only compared.
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$rr = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? $_GET['rest_route'] : '';
		// phpcs:enable
		if ( false !== strpos( $uri, self::ROUTE ) || false !== strpos( $rr, self::ROUTE ) ) {
			self::maybe_serve_initials(); // Exits when the request is for an initials image.
		}
		// The other avatars plugin changing a photo in its own screens while it renders: our copy follows.
		add_action( self::SLA_META . '_updated', [ __CLASS__, 'sla_updated' ] );
		add_action( self::SLA_META . '_deleted', [ __CLASS__, 'sla_deleted' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'late_boot' ], 20 );
		add_action( 'init', [ __CLASS__, 'wrap_kadence' ], 99 );
		add_filter( 'avatar_defaults', [ __CLASS__, 'avatar_defaults' ] );
		add_action( 'activated_plugin', [ __CLASS__, 'sla_plugin_changed' ] );
		add_action( 'deactivated_plugin', [ __CLASS__, 'sla_plugin_changed' ] );

		// Square subsizes for flagged attachments only (new uploads, regeneration, "Edit image").
		add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'add_sizes' ], 10, 3 );
		add_filter( 'wp_get_missing_image_subsizes', [ __CLASS__, 'add_missing_sizes' ], 10, 3 );
		add_action( 'delete_attachment', [ __CLASS__, 'delete_attachment' ] );

		// Profile screens.
		add_filter( 'user_profile_picture_description', [ __CLASS__, 'profile_description' ], 10, 2 );
		add_action( 'show_user_profile', [ __CLASS__, 'profile_section' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_section' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_profile' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile' ] );
		add_action( 'user_profile_update_errors', [ __CLASS__, 'profile_errors' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_init', [ __CLASS__, 'admin_init' ] );

		// Privacy tools.
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );

		Migrate::register_step( 'avatars-import-sla', [ __CLASS__, 'import_step' ] );
		Migrate::register_step( 'avatars-subsizes', [ __CLASS__, 'subsizes_step' ], [ 'after' => [ 'avatars-import-sla' ] ] );
	}

	/** After every plugin loaded: render unless the other avatars plugin does. */
	public static function late_boot(): void {
		if ( ! self::sla_active() ) {
			add_filter( 'pre_get_avatar_data', [ __CLASS__, 'avatar_data' ], 10, 2 );
		}
	}

	public static function admin_init(): void {
		self::register_settings();
		self::editor_sizes();
		// Core's avatar_default out of line with the Gravatar switch (The other avatars plugin went away without its
		// deactivation hooks, or someone set the option directly): two autoloaded reads, a write only on a mismatch.
		if ( ! self::sla_active() && ( ! self::settings()['gravatar'] ) !== ( self::CORE_DEFAULT === get_option( 'avatar_default' ) ) ) {
			self::sync_core_default();
		}
	}

	/**
	 * Module hook: plugin activated, or the module turned on (modules_changed()). Gravatar off again means core's
	 * avatar_default points at the local default; the other avatars plugin photos added meanwhile are imported (both steps
	 * are idempotent and only fill what is missing).
	 */
	public static function activate(): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( ! self::settings()['gravatar'] ) {
			self::sync_core_default( false );
		}
		Migrate::rerun( 'avatars-import-sla' );
		Migrate::rerun( 'avatars-subsizes' );
	}

	/**
	 * Module hook: plugin deactivated, or the module turned off (modules_changed()). Hand avatar_default back to core so
	 * nothing asks Gravatar for 'launch_builder'.
	 */
	public static function deactivate(): void {
		self::release_core_default();
	}

	/**
	 * update_option_ / add_option_ / delete_option_ launch_builder_disabled_modules, registered only when there is no
	 * Modules registry (which runs the module hooks itself): when this module changes state, run activate() or
	 * deactivate() and purge page caches (avatars are in the HTML).
	 */
	public static function modules_changed( $a = null, $b = null, $c = null ): void {
		$hook = (string) current_filter();
		if ( 'update_option_' . self::OPT_DISABLED === $hook ) {
			$was = ! self::off_in( $a ); // ( $old_value, $value, $option )
		} elseif ( 'add_option_' . self::OPT_DISABLED === $hook ) {
			$was = true; // The option did not exist: every module was on.
		} else {
			$was = false; // Deleted: the old value is gone; activate() is safe to repeat.
		}
		self::$settings = null;
		$now = self::enabled();
		if ( $was === $now ) {
			return;
		}
		if ( $now ) {
			self::activate();
		} else {
			self::deactivate();
		}
		self::$memo = [];
		Cache::purge_later();
	}

	/**
	 * activated_plugin / deactivated_plugin for the other avatars plugin. On: it renders from now on and reads core's
	 * avatar_default itself, so 'launch_builder' is handed back. Off: from the next request we render, so the Gravatar
	 * switch reaches core now, and the import runs again for photos it got that are not mirrored yet.
	 */
	public static function sla_plugin_changed( $plugin ): void {
		if ( self::SLA_FILE !== wp_basename( (string) $plugin ) ) {
			return;
		}
		if ( 'activated_plugin' === current_filter() ) {
			self::release_core_default();
		} else {
			self::$sla_gone = true; // Still loaded in this request.
			self::sync_core_default();
			Migrate::rerun( 'avatars-import-sla' );
			Migrate::rerun( 'avatars-subsizes' );
		}
		self::$memo = [];
		Cache::purge_later();
	}

	/* ------------------------------------------------------------------ rendering */

	/**
	 * pre_get_avatar_data: a local photo for users who have one; the local default instead of Gravatar when it is off.
	 * Reads only (user meta, the attachment post and its metadata): no writes, no image work.
	 *
	 * @param array $args        get_avatar_data() arguments.
	 * @param mixed $id_or_email user id, email, WP_User, WP_Post, WP_Comment or a Gravatar hash.
	 * @return array
	 */
	public static function avatar_data( $args, $id_or_email ) {
		if ( ! is_array( $args ) || isset( $args['url'] ) ) {
			return $args;
		}
		if ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) && ! $id_or_email instanceof \WP_Comment ) {
			$id_or_email = get_comment( $id_or_email );
		}
		if ( $id_or_email instanceof \WP_Comment && ! is_avatar_comment_type( get_comment_type( $id_or_email ) ) ) {
			return $args; // Pingbacks and the like: core answers "no avatar".
		}
		$px = max( 1, (int) ( $args['size'] ?? 96 ), (int) ( $args['width'] ?? 0 ), (int) ( $args['height'] ?? 0 ) );
		list( $user_id, $name ) = self::who( $id_or_email );
		if ( $user_id && empty( $args['force_default'] ) ) {
			$key = $user_id . ':' . $px;
			if ( ! isset( self::$memo[ $key ] ) ) {
				$src = self::source( $user_id );
				self::$memo[ $key ] = $src ? self::source_url( $src, $px ) : '';
			}
			if ( '' !== self::$memo[ $key ] ) {
				$args['url'] = self::$memo[ $key ];
				$args['found_avatar'] = true;
				if ( '' === (string) ( $args['alt'] ?? '' ) ) {
					$src = self::source( $user_id );
					$alt = ! empty( $src['id'] ) ? (string) get_post_meta( (int) $src['id'], '_wp_attachment_image_alt', true ) : '';
					$args['alt'] = (string) apply_filters( 'launch_builder_avatar_alt', $alt, $user_id, (int) ( $src['id'] ?? 0 ) );
				}
				return $args;
			}
		}
		if ( $user_id && empty( $args['force_default'] ) ) {
			$preset = self::user_preset( $user_id ); // Their own pick among the ready-made pictures (a photo of their own was handled above).
			if ( '' !== $preset ) {
				$args['url'] = self::preset_url( $preset );
				$args['found_avatar'] = true;
				return $args;
			}
		}
		if ( self::local_default( $args ) ) {
			$args['url'] = self::default_url( $name, $px, $args );
			$args['found_avatar'] = false;
		} else {
			$fallback = self::gravatar_fallback( $args );
			if ( '' !== $fallback ) {
				$args['default'] = $fallback; // Gravatar shows this picture for anyone it does not know.
			}
		}
		return $args;
	}

	/**
	 * With Gravatar on, anyone Gravatar does not know gets the site's default picture too, when that is a ready-made picture
	 * (a public file Gravatar can fetch). A default another person chose under Settings > Discussion is left alone.
	 */
	private static function gravatar_fallback( array $args ): string {
		$d = self::settings()['default'];
		if ( ! is_string( $d ) || 0 !== strpos( $d, self::PRESET_PREFIX ) ) {
			return '';
		}
		$core = (string) ( $args['default'] ?? '' );
		if ( ! in_array( $core, [ '', 'mm', 'mystery', 'mysteryman', 'gravatar_default', self::CORE_DEFAULT ], true ) ) {
			return '';
		}
		$u = self::preset_url( substr( $d, strlen( self::PRESET_PREFIX ) ) );
		return 0 === strpos( $u, 'https://' ) ? $u : ''; // Gravatar only fetches https pictures it can reach.
	}

	/**
	 * Who an avatar request is for. Guest comments are never mapped to an account (their email could be anyone's); a bare
	 * email string is, as core does for Gravatar. Hashes resolve to nobody.
	 *
	 * @return array{0:int,1:string} user id (0 = none) and a display name for initials
	 */
	private static function who( $id_or_email ): array {
		$user = null;
		$name = '';
		if ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( absint( $id_or_email ) );
		} elseif ( $id_or_email instanceof \WP_User ) {
			$user = $id_or_email;
		} elseif ( $id_or_email instanceof \WP_Post ) {
			$user = get_userdata( (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof \WP_Comment ) {
			if ( (int) $id_or_email->user_id > 0 ) {
				$user = get_userdata( (int) $id_or_email->user_id );
			}
			$name = (string) $id_or_email->comment_author;
		} elseif ( is_string( $id_or_email ) && false === strpos( $id_or_email, '.gravatar.com' ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			$name = str_replace( [ '.', '_', '-' ], ' ', substr( $id_or_email, 0, (int) strpos( $id_or_email, '@' ) ) );
		}
		if ( $user && $user->exists() ) {
			$name = '' !== $user->display_name ? $user->display_name : trim( $user->first_name . ' ' . $user->last_name );
			return [ (int) $user->ID, $name ]; // No name: the silhouette (never initials of the login).
		}
		return [ 0, $name ];
	}

	/**
	 * The local photo that renders for a user: ['id' => attachment] (ours, or the other avatars plugin's media while it is
	 * not active), ['url' => ..., 'sizes' => [px => url]] for its old URL-only uploads, or null.
	 */
	public static function source( int $user_id ): ?array {
		$vals = get_user_meta( $user_id, self::meta_key(), false );
		if ( $vals ) {
			$id = (int) $vals[0];
			return ( $id > 0 && self::valid_image( $id ) ) ? [ 'id' => $id, 'from' => 'lb' ] : null;
		}
		$sla = self::sla_meta( $user_id );
		if ( ! $sla ) {
			return null;
		}
		if ( ! empty( $sla['media_id'] ) && self::valid_image( (int) $sla['media_id'] ) ) {
			return [ 'id' => (int) $sla['media_id'], 'from' => 'sla' ];
		}
		if ( ! empty( $sla['full'] ) && self::is_upload_url( (string) $sla['full'] ) ) {
			$sizes = [];
			foreach ( $sla as $k => $u ) {
				if ( is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) ) ) {
					if ( is_string( $u ) && self::is_upload_url( $u ) ) {
						$sizes[ (int) $k ] = $u;
					}
				}
			}
			ksort( $sizes );
			return [ 'url' => (string) $sla['full'], 'sizes' => $sizes, 'from' => 'sla' ];
		}
		return null;
	}

	/** the other avatars plugin's meta for this site (per-blog key first on multisite), or null. */
	private static function sla_meta( int $user_id ): ?array {
		foreach ( self::sla_keys() as $key ) {
			$v = get_user_meta( $user_id, $key, true );
			if ( is_array( $v ) && ( ! empty( $v['media_id'] ) || ! empty( $v['full'] ) ) ) {
				if ( self::SLA_META === $key && is_multisite() && ! empty( $v['blog_id'] ) && (int) $v['blog_id'] !== get_current_blog_id() ) {
					continue; // Shared mode across a network: an attachment id from another site.
				}
				return $v;
			}
		}
		return null;
	}

	/** @return string[] SLA meta keys to read for this site. */
	private static function sla_keys(): array {
		return is_multisite() ? [ self::SLA_META . '_' . get_current_blog_id(), self::SLA_META ] : [ self::SLA_META ];
	}

	private static function is_upload_url( string $url ): bool {
		$base = preg_replace( '#^https?:#i', '', (string) wp_get_upload_dir()['baseurl'] );
		return '' !== $base && 0 === strpos( preg_replace( '#^https?:#i', '', $url ), $base );
	}

	/** An attachment that may render as an avatar: an image of an allowed type, not in the trash. */
	public static function valid_image( int $id ): bool {
		$p = $id > 0 ? get_post( $id ) : null;
		return $p && 'attachment' === $p->post_type && 'trash' !== $p->post_status && in_array( $p->post_mime_type, self::mimes(), true );
	}

	/** @return string[] allowed avatar mime types (filter launch_builder_avatar_mimes; SVG is never allowed). */
	private static function mimes(): array {
		return array_values( array_diff( (array) apply_filters( 'launch_builder_avatar_mimes', self::MIMES ), [ 'image/svg+xml' ] ) );
	}

	private static function source_url( array $src, int $px ): string {
		if ( ! empty( $src['id'] ) ) {
			return self::url_for( (int) $src['id'], $px );
		}
		foreach ( (array) ( $src['sizes'] ?? [] ) as $w => $u ) {
			if ( $w >= $px ) {
				return (string) $u;
			}
		}
		return (string) ( $src['url'] ?? '' );
	}

	/** The smallest square avatar size >= $px (else the largest square there is), from the attachment metadata. */
	public static function url_for( int $att, int $px ): string {
		$meta = wp_get_attachment_metadata( $att );
		$meta = is_array( $meta ) ? $meta : [];
		$best = '';
		$best_w = 0;
		$max = '';
		$max_w = 0;
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $s ) {
			$w = (int) ( $s['width'] ?? 0 );
			if ( 0 !== strpos( (string) $name, 'lb-avatar-' ) || $w <= 0 || (int) ( $s['height'] ?? 0 ) !== $w ) {
				continue;
			}
			if ( $w >= $px && ( '' === $best || $w < $best_w ) ) {
				$best = (string) $name;
				$best_w = $w;
			}
			if ( $w > $max_w ) {
				$max = (string) $name;
				$max_w = $w;
			}
		}
		$size = '' !== $best ? $best : $max;
		$fw = (int) ( $meta['width'] ?? 0 );
		$full_square = $fw > 0 && (int) ( $meta['height'] ?? 0 ) === $fw;
		if ( '' === $best && $full_square && $fw > $max_w ) {
			$size = 'full'; // A square original is better than an upscaled square size.
		} elseif ( '' === $size ) {
			$t = $meta['sizes']['thumbnail'] ?? null; // No square sizes yet (e.g. imported, not processed): a square thumbnail, else the original.
			$size = ( $t && (int) ( $t['width'] ?? 0 ) === (int) ( $t['height'] ?? -1 ) && ( $px <= (int) $t['width'] || ! $full_square ) ) ? 'thumbnail' : 'full';
		}
		$img = wp_get_attachment_image_src( $att, $size );
		return $img ? (string) $img[0] : '';
	}

	/** The local default image for someone without a photo (never a Gravatar URL). */
	private static function default_url( string $name, int $px, array &$args ): string {
		$d = self::settings()['default'];
		if ( is_int( $d ) ) {
			$u = self::valid_image( $d ) ? self::url_for( $d, $px ) : '';
			if ( '' !== $u ) {
				return $u;
			}
			$d = 'initials'; // The chosen image is gone.
		}
		if ( is_string( $d ) && 0 === strpos( $d, self::PRESET_PREFIX ) ) {
			$u = self::preset_url( substr( $d, strlen( self::PRESET_PREFIX ) ) );
			if ( '' !== $u ) {
				return $u;
			}
			$d = 'initials'; // The picture is gone.
		}
		if ( 'logo' === $d ) {
			list( $u, $square ) = self::logo( $px );
			if ( '' !== $u ) {
				if ( ! $square && false === strpos( (string) ( $args['extra_attr'] ?? '' ), 'style=' ) ) {
					$args['extra_attr'] = trim( ( $args['extra_attr'] ?? '' ) . " style='object-fit:contain'" );
				}
				return $u;
			}
			$d = 'initials';
		}
		if ( 'blank' === $d ) {
			return includes_url( 'images/blank.gif' );
		}
		$initials = 'initials' === $d ? self::initials( $name ) : '';
		return '' !== $initials ? self::initials_url( $initials ) : self::silhouette_url();
	}

	/** The neutral silhouette: a static file, no PHP. */
	private static function silhouette_url(): string {
		return LB_URL . 'assets/avatar-default.svg';
	}

	/**
	 * The initials image: one URL per pair of letters (the colour comes from the letters, so two "JS" share one cached
	 * image), on its own path under the REST prefix. That prefix is the one path every WordPress server already hands to
	 * index.php (and rest_url() falls back to ?rest_route= without pretty permalinks); it is not a REST route, boot()
	 * answers it at plugin load. Never the home URL, so no page cache can confuse the image with the home page.
	 */
	private static function initials_url( string $text ): string {
		static $urls = [];
		if ( ! isset( $urls[ $text ] ) ) {
			$route = self::ROUTE . '/' . rawurlencode( $text );
			// rest_url() needs $wp_rewrite (set up after plugins_loaded); an avatar asked for earlier gets the query form.
			$urls[ $text ] = isset( $GLOBALS['wp_rewrite'] ) ? rest_url( $route ) : add_query_arg( 'rest_route', '/' . $route, home_url( '/index.php' ) );
		}
		return $urls[ $text ];
	}

	/**
	 * The site's square icon, else the logo: Business Info site_icon, core site_icon, a Business Info logo (when that
	 * field exists), then the brand logo (custom_logo theme mod, written by set-brand). Reads only (no HTTP, no DNS).
	 *
	 * @return array{0:string,1:bool} URL ('' = none) and whether it is square
	 */
	private static function logo( int $px ): array {
		static $cache = [];
		if ( isset( $cache[ $px ] ) ) {
			return $cache[ $px ];
		}
		$bi = Company::values();
		$cands = [ (string) ( $bi['site_icon'] ?? '' ), (string) get_option( 'site_icon', '' ), (string) ( $bi['logo'] ?? '' ), (string) get_theme_mod( 'custom_logo', '' ) ];
		$out = [ '', false ];
		foreach ( $cands as $c ) {
			$c = trim( $c );
			if ( ctype_digit( $c ) && (int) $c > 0 && self::valid_image( (int) $c ) ) {
				$img = wp_get_attachment_image_src( (int) $c, [ $px, $px ] );
				if ( $img ) {
					$out = [ (string) $img[0], (int) $img[1] === (int) $img[2] ];
					break;
				}
			} elseif ( 1 === preg_match( '#^https?://[^/\s]+#i', $c ) && '' !== esc_url_raw( $c, [ 'http', 'https' ] ) ) {
				$out = [ esc_url_raw( $c, [ 'http', 'https' ] ), false ];
				break;
			}
		}
		$cache[ $px ] = $out;
		return $out;
	}

	/** One or two initials, like core's Gravatar "initials" default. */
	public static function initials( string $name ): string {
		$name = trim( (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $name ) );
		if ( '' === $name ) {
			return '';
		}
		if ( false === strpos( $name, ' ' ) || preg_match( '/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $name ) ) {
			$out = mb_substr( str_replace( ' ', '', $name ), 0, 2, 'UTF-8' );
		} else {
			$out = mb_substr( $name, 0, 1, 'UTF-8' ) . mb_substr( $name, (int) strrpos( $name, ' ' ) + 1, 1, 'UTF-8' );
		}
		return self::upper( $out );
	}

	/** Upper case (WordPress polyfills mb_substr, not mb_strtoupper). */
	private static function upper( string $s ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
	}

	/**
	 * Plugin load (boot): a request for <rest prefix>/launch-builder/v1/avatar/<letters> (or ?rest_route=/launch-builder/
	 * v1/avatar/<letters>) gets the initials SVG and stops here, before the remaining plugins, the theme and init load.
	 * Letters are reduced to \p{L}\p{N} (max 2) and XML-escaped; anything else gets the silhouette. The path form is
	 * unique per image, so it is public and immutable for a year; the ?rest_route= form (sites without pretty permalinks)
	 * lives on /index.php, so it is cacheable by the browser only.
	 */
	private static function maybe_serve_initials(): void {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- a public image, no state change; only matched below.
		$by_query = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] );
		$route = $by_query ? (string) wp_unslash( $_GET['rest_route'] ) : rawurldecode( (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		// phpcs:enable
		if ( 1 !== preg_match( '#/' . preg_quote( self::ROUTE, '#' ) . '/([^/]{1,64})/?$#u', $route, $m ) ) {
			return;
		}
		$text = self::upper( mb_substr( (string) preg_replace( '/[^\p{L}\p{N}]/u', '', $m[1] ), 0, 2, 'UTF-8' ) );
		status_header( 200 );
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( $by_query ? 'Cache-Control: private, max-age=31536000' : 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'" );
		echo self::initials_svg( $text ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed template, XML-escaped letters.
		exit;
	}

	/** The initials SVG (the silhouette when there are no letters). Colour: one of COLORS, chosen by the letters. */
	public static function initials_svg( string $text ): string {
		if ( '' === $text ) {
			$body = '<rect width="64" height="64" fill="#e2e8f0"/><circle cx="32" cy="25" r="11" fill="#94a3b8"/><path d="M12 58c0-11 9-19 20-19s20 8 20 19v6H12z" fill="#94a3b8"/>';
		} else {
			$color = self::COLORS[ hexdec( substr( md5( $text ), 0, 4 ) ) % count( self::COLORS ) ];
			$body = '<rect width="64" height="64" fill="' . $color . '"/><text x="32" y="32" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif" font-size="26" font-weight="600" fill="#fff">' . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</text>';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">' . $body . '</svg>';
	}

	/**
	 * avatar_defaults (Discussion screen): with Gravatar off the only choice is the local default. Not while Simple Local
	 * Avatars renders: it reads the saved choice itself, so its own list stays.
	 */
	public static function avatar_defaults( $defaults ) {
		if ( self::settings()['gravatar'] || self::sla_active() ) {
			return $defaults;
		}
		return [ self::CORE_DEFAULT => __( 'Local default (see Profile photos below)', 'launch-builder' ) ];
	}

	/**
	 * init: Kadence Pro's "Local Gravatars" filter copies whatever image is in an avatar into wp-content/gravatars (a
	 * loopback download per local photo, files named by basename so two photos can swap). Wrap it so only Gravatar
	 * images reach it.
	 */
	public static function wrap_kadence(): void {
		global $wp_filter;
		if ( empty( $wp_filter['get_avatar'] ) || ! class_exists( 'Kadence_Pro\Local_Gravatars', false ) ) {
			return;
		}
		foreach ( $wp_filter['get_avatar']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( ! $fn instanceof \Closure ) {
					continue;
				}
				try {
					$file = wp_normalize_path( (string) ( new \ReflectionFunction( $fn ) )->getFileName() );
				} catch ( \ReflectionException $e ) {
					continue;
				}
				if ( '/local-gravatars.php' !== substr( $file, -20 ) ) {
					continue;
				}
				remove_filter( 'get_avatar', $fn, $priority );
				self::$kadence = $fn;
				add_filter( 'get_avatar', [ __CLASS__, 'kadence_get_avatar' ], $priority );
				return;
			}
		}
	}

	/** Is Kadence Pro's local Gravatars filter wrapped in this request? */
	public static function kadence_wrapped(): bool {
		return null !== self::$kadence;
	}

	/** get_avatar, in place of Kadence's filter: pass it Gravatar images only. */
	public static function kadence_get_avatar( $avatar ) {
		if ( self::$kadence && is_string( $avatar ) && preg_match( '#//([a-z0-9-]+\.)*gravatar\.com/#i', $avatar ) ) {
			return call_user_func( self::$kadence, $avatar );
		}
		return $avatar;
	}

	/* ------------------------------------------------------------------ square sizes */

	private static function flagged( int $att ): bool {
		return $att > 0 && (bool) get_post_meta( $att, self::META, true );
	}

	/** @return int[] square sizes in px, ascending (filter launch_builder_avatar_sizes). */
	private static function size_list(): array {
		$list = array_filter( array_map( 'absint', (array) apply_filters( 'launch_builder_avatar_sizes', self::SIZES ) ) );
		$list = array_values( array_unique( $list ) );
		sort( $list );
		return $list ? $list : self::SIZES;
	}

	private static function anchor_of( int $att ): string {
		$a = (string) get_post_meta( $att, self::META_ANCHOR, true );
		return in_array( $a, [ 'top', 'center' ], true ) ? $a : self::settings()['anchor'];
	}

	/**
	 * The square sizes a flagged attachment gets: every listed size the image can fill without upscaling, plus one square
	 * as large as the short side when that is smaller than the largest listed size (so small photos stay square).
	 *
	 * @return array<string,array{width:int,height:int,crop:bool|array}>
	 */
	public static function sizes_for( int $att, $meta ): array {
		if ( ! self::flagged( $att ) || ! is_array( $meta ) ) {
			return [];
		}
		$w = (int) ( $meta['width'] ?? 0 );
		$h = (int) ( $meta['height'] ?? 0 );
		$min = min( $w, $h );
		if ( $min <= 0 ) {
			return [];
		}
		$crop = 'center' === self::anchor_of( $att ) ? true : [ 'center', 'top' ];
		$list = self::size_list();
		$out = [];
		foreach ( $list as $px ) {
			if ( $px <= $min ) {
				$out[ 'lb-avatar-' . $px ] = [ 'width' => $px, 'height' => $px, 'crop' => $crop ];
			}
		}
		if ( $min < max( $list ) && $w !== $h && ! in_array( $min, $list, true ) ) {
			$out['lb-avatar-sq'] = [ 'width' => $min, 'height' => $min, 'crop' => $crop ];
		}
		return $out;
	}

	/** intermediate_image_sizes_advanced: add the square sizes for flagged attachments (upload, regenerate). */
	public static function add_sizes( $sizes, $meta = [], $att = 0 ) {
		return is_array( $sizes ) ? $sizes + self::sizes_for( (int) $att, $meta ) : $sizes;
	}

	/** wp_get_missing_image_subsizes: the square sizes a flagged attachment does not have yet (wp_update_image_subsizes). */
	public static function add_missing_sizes( $missing, $meta = [], $att = 0 ) {
		if ( ! is_array( $missing ) ) {
			return $missing;
		}
		foreach ( self::sizes_for( (int) $att, $meta ) as $name => $s ) {
			if ( empty( $meta['sizes'][ $name ] ) ) {
				$missing[ $name ] = $s;
			}
		}
		return $missing;
	}

	/** admin_init: the media library's "Edit image" saves registered sizes only, so register ours for that request. */
	private static function editor_sizes(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- core checks the image-editor nonce; this only adds sizes.
		if ( ! wp_doing_ajax() || 'image-editor' !== ( $_POST['action'] ?? '' ) || ! in_array( $_POST['do'] ?? '', [ 'save', 'scale' ], true ) ) {
			return;
		}
		$att = absint( $_POST['postid'] ?? 0 );
		// phpcs:enable
		foreach ( self::sizes_for( $att, wp_get_attachment_metadata( $att ) ) as $name => $s ) {
			add_image_size( $name, $s['width'], $s['height'], $s['crop'] );
		}
	}

	/**
	 * Does a flagged attachment lack one of its square sizes, or the file of one? (Write and audit time only: never at
	 * render.)
	 */
	private static function needs_sizes( int $att ): bool {
		$meta = wp_get_attachment_metadata( $att );
		foreach ( array_keys( self::sizes_for( $att, $meta ) ) as $name ) {
			if ( empty( $meta['sizes'][ $name ] ) ) {
				return true;
			}
		}
		return is_array( $meta ) && (bool) self::lost_files( $att, $meta );
	}

	/**
	 * Our sizes whose file is gone although the original is here. The other avatars plugin caches its own resized copies as
	 * {name}-{N}x{N}.{ext} next to the original, the same names core gives subsizes, and deletes them when a photo
	 * changes: lb-avatar-192 can be among them. (Offloaded media without a local original is left alone.)
	 *
	 * @return string[] size names
	 */
	private static function lost_files( int $att, array $meta ): array {
		$file = get_attached_file( $att );
		if ( ! $file || ! file_exists( $file ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $s ) {
			if ( 0 === strpos( (string) $name, 'lb-avatar-' ) && ! file_exists( dirname( $file ) . '/' . wp_basename( (string) ( $s['file'] ?? '' ) ) ) ) {
				$out[] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * Make the square sizes now (bounded to this one image; fires wp_generate_attachment_metadata 'update' so image
	 * optimisers see the new files). $reset first removes ours (new anchor).
	 *
	 * @return string[] warnings
	 */
	public static function ensure_sizes( int $att, bool $reset = false ): array {
		$warnings = [];
		if ( $reset ) {
			self::drop_sizes( $att );
		} else {
			$meta = wp_get_attachment_metadata( $att );
			$lost = is_array( $meta ) ? self::lost_files( $att, $meta ) : [];
			if ( $lost ) { // Listed but deleted (see lost_files()): forget them so they are made again below.
				foreach ( $lost as $name ) {
					unset( $meta['sizes'][ $name ] );
				}
				wp_update_attachment_metadata( $att, $meta );
			}
		}
		if ( self::needs_sizes( $att ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$r = wp_update_image_subsizes( $att );
			if ( is_wp_error( $r ) ) {
				$warnings[] = 'Square sizes could not be made: ' . $r->get_error_message();
			}
		}
		$meta = wp_get_attachment_metadata( $att );
		$have = array_filter( array_keys( (array) ( $meta['sizes'] ?? [] ) ), static function ( $n ) { return 0 === strpos( (string) $n, 'lb-avatar-' ); } );
		$min = min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );
		if ( ! $have && self::sizes_for( $att, $meta ) ) {
			$warnings[] = 'No square sizes were made (no image editor on this server?): the photo shows as its thumbnail or original.';
		}
		if ( $min > 0 && $min < max( self::size_list() ) ) {
			/* translators: 1: short side in px, 2: largest avatar size in px */
			$warnings[] = sprintf( 'The image is only %1$d px on its short side; avatars larger than that use it without upscaling (best is %2$d px or more).', $min, max( self::size_list() ) );
		}
		self::$memo = [];
		return $warnings;
	}

	/** Remove our square sizes from the metadata and delete their files (only files no other size or the original uses). */
	private static function drop_sizes( int $att ): void {
		$meta = wp_get_attachment_metadata( $att );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			return;
		}
		$file = get_attached_file( $att );
		$dir = $file ? dirname( $file ) : '';
		$base = realpath( (string) wp_get_upload_dir()['basedir'] );
		$keep = [ wp_basename( (string) $file ) ];
		foreach ( $meta['sizes'] as $name => $s ) {
			if ( 0 !== strpos( (string) $name, 'lb-avatar-' ) ) {
				$keep[] = (string) ( $s['file'] ?? '' );
			}
		}
		foreach ( $meta['sizes'] as $name => $s ) {
			if ( 0 !== strpos( (string) $name, 'lb-avatar-' ) ) {
				continue;
			}
			unset( $meta['sizes'][ $name ] );
			$f = wp_basename( (string) ( $s['file'] ?? '' ) );
			if ( '' === $f || '' === $dir || ! $base || in_array( $f, $keep, true ) ) {
				continue;
			}
			$path = realpath( $dir . '/' . $f );
			if ( $path && 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
				wp_delete_file( $path );
			}
		}
		wp_update_attachment_metadata( $att, $meta );
	}

	/** delete_attachment: users who used it lose the link (core deletes the files, ours included). */
	public static function delete_attachment( $att ): void {
		$att = (int) $att;
		if ( ! self::flagged( $att ) ) {
			return;
		}
		$ids = get_users( [ 'meta_key' => self::meta_key(), 'meta_value' => (string) $att, 'fields' => 'ID', 'number' => 1000, 'blog_id' => 0, 'count_total' => false ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$rows = [];
		foreach ( $ids as $uid ) {
			self::unlink_meta( (int) $uid );
			$rows[ 'user_avatar:' . (int) $uid ] = [ 'user_meta', (int) $uid, Ledger::hash_value( [ 'attachment' => 0 ] ), 'removed' ];
		}
		if ( $rows ) {
			Ledger::record_many( $rows );
			Cache::purge_later();
		}
	}

	/* ------------------------------------------------------------------ permissions + writes */

	/** Our attachment id for a user (0 = none or removed). */
	public static function assigned( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::meta_key(), true ) );
	}

	/** The attachment that renders today (ours, else the other avatars plugin's), 0 for none: the undo value. */
	private static function current_attachment( int $user_id ): int {
		$vals = get_user_meta( $user_id, self::meta_key(), false );
		if ( $vals ) {
			return max( 0, (int) $vals[0] );
		}
		$sla = self::sla_meta( $user_id );
		return $sla ? (int) ( $sla['media_id'] ?? 0 ) : 0;
	}

	/**
	 * May the current user change this user's photo? Unknown users and users the caller may not edit get the SAME error
	 * (no user enumeration). Administrators can only be changed by administrators.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_set( int $target_id ) {
		$me = get_current_user_id();
		$target = $target_id > 0 ? get_userdata( $target_id ) : false;
		$unavailable = new \WP_Error( 'lb_user_unavailable', 'That user does not exist or you may not change their photo.', [ 'status' => 404 ] );
		if ( ! $me || ! $target ) {
			return $unavailable;
		}
		if ( $target->ID !== $me ) {
			if ( ! current_user_can( 'edit_user', $target->ID ) || ( user_can( $target, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) ) {
				return $unavailable;
			}
		} elseif ( ! self::settings()['self_service'] && ! current_user_can( 'edit_users' ) ) {
			return new \WP_Error( 'lb_forbidden', 'On this site only administrators set profile photos.', [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * May the current user use this attachment as $target_id's photo? It must exist and not be trashed, be editable by
	 * the caller (or be the caller's own upload for their own photo), its parent post must be readable, and it must be a
	 * real JPEG/PNG/WebP/AVIF/GIF whose file exists. Not found and not allowed share one error.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	public static function check_attachment( int $att, int $target_id ) {
		$p = $att > 0 ? get_post( $att ) : null;
		$unavailable = new \WP_Error( 'lb_attachment_unavailable', 'That image does not exist or you may not use it.', [ 'status' => 404 ] );
		if ( ! $p || 'attachment' !== $p->post_type || 'trash' === $p->post_status ) {
			return $unavailable;
		}
		$me = get_current_user_id();
		if ( ! current_user_can( 'edit_post', $p->ID ) && ! ( $me && $me === $target_id && (int) $p->post_author === $me ) ) {
			return $unavailable;
		}
		$parent = $p->post_parent ? get_post( $p->post_parent ) : null;
		if ( $p->post_parent && ( ! $parent || ! current_user_can( 'read_post', $parent->ID ) ) ) {
			return $unavailable;
		}
		if ( ! wp_attachment_is_image( $p ) || ! in_array( $p->post_mime_type, self::mimes(), true ) ) {
			return new \WP_Error( 'lb_not_an_image', 'Use a JPEG, PNG, WebP, AVIF or GIF image (SVG and other files are refused).', [ 'status' => 400 ] );
		}
		$file = get_attached_file( $p->ID );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'lb_missing_file', 'The image file is missing from the uploads folder.', [ 'status' => 400 ] );
		}
		return $p;
	}

	/**
	 * Give a user a photo (permissions already checked): flag the attachment, make its square sizes, link it, record it
	 * in the Ledger, purge page caches; the other avatars plugin, while active, gets the same change through its own method.
	 *
	 * @return array{previous:int,warnings:string[]}
	 */
	public static function assign( int $user_id, int $att, string $anchor = '' ): array {
		$prev = self::current_attachment( $user_id );
		update_post_meta( $att, self::META, 1 );
		$reset = false;
		if ( in_array( $anchor, [ 'top', 'center' ], true ) && get_post_meta( $att, self::META_ANCHOR, true ) !== $anchor ) {
			$reset = self::anchor_of( $att ) !== $anchor; // A new crop: replace the square files.
			update_post_meta( $att, self::META_ANCHOR, $anchor );
		}
		$warnings = self::ensure_sizes( $att, $reset );
		if ( self::assigned( $user_id ) !== $att ) {
			update_user_meta( $user_id, self::meta_key(), $att );
		}
		Ledger::record( 'user_avatar:' . $user_id, 'user_meta', $user_id, Ledger::hash_value( [ 'attachment' => $att ] ), 'set' );
		if ( self::sla_active() && is_callable( [ $GLOBALS[ self::SLA_OPT ], 'assign_new_user_avatar' ] ) ) {
			$sla_prev = (int) ( self::sla_meta( $user_id )['media_id'] ?? 0 );
			self::$delegating = true; // It deletes, then sets, firing its actions for our own change.
			try {
				$GLOBALS[ self::SLA_OPT ]->assign_new_user_avatar( $att, $user_id );
			} finally {
				self::$delegating = false;
			}
			self::repair( [ $att, $prev, $sla_prev ] ); // Its delete step can take files our sizes share names with.
		}
		self::$memo = [];
		Cache::purge_later();
		do_action( 'launch_builder_avatar_updated', $user_id, $att, $prev );
		return [ 'previous' => $prev, 'warnings' => $warnings ];
	}

	/**
	 * Make again any of our square files the other avatars plugin just deleted (see lost_files(): its cached copies share
	 * names with core subsizes). Flagged images only; nothing to do costs a metadata read and a few file checks.
	 */
	private static function repair( array $atts ): void {
		foreach ( array_unique( array_filter( array_map( 'intval', $atts ) ) ) as $a ) {
			if ( self::flagged( $a ) && self::valid_image( $a ) && self::in_use( $a ) ) {
				self::ensure_sizes( $a );
			}
		}
	}

	/** Is an image someone's photo on this site, or the default image? (One indexed query.) */
	private static function in_use( int $att ): bool {
		if ( self::settings()['default'] === $att ) {
			return true;
		}
		return (bool) get_users( [ 'meta_key' => self::meta_key(), 'meta_value' => (string) $att, 'fields' => 'ID', 'number' => 1, 'count_total' => false, 'blog_id' => 0 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Take a user's photo away (the image stays in the Media Library). @return int the previous attachment id */
	public static function unassign( int $user_id ): int {
		$prev = self::current_attachment( $user_id );
		if ( self::sla_active() && self::sla_meta( $user_id ) && is_callable( [ $GLOBALS[ self::SLA_OPT ], 'avatar_delete' ] ) ) {
			$sla_prev = (int) ( self::sla_meta( $user_id )['media_id'] ?? 0 );
			self::$delegating = true;
			try {
				$GLOBALS[ self::SLA_OPT ]->avatar_delete( $user_id );
			} finally {
				self::$delegating = false;
			}
			self::repair( [ $prev, $sla_prev ] ); // Someone else may use the same image.
		}
		self::unlink_meta( $user_id );
		Ledger::record( 'user_avatar:' . $user_id, 'user_meta', $user_id, Ledger::hash_value( [ 'attachment' => 0 ] ), 'removed' );
		self::$memo = [];
		Cache::purge_later();
		do_action( 'launch_builder_avatar_updated', $user_id, 0, $prev );
		return $prev;
	}

	/** Unlink: 0 while an old photo from the other avatars plugin exists (so it does not come back), else no meta at all. */
	private static function unlink_meta( int $user_id ): void {
		if ( self::sla_meta( $user_id ) ) {
			update_user_meta( $user_id, self::meta_key(), 0 );
		} else {
			delete_user_meta( $user_id, self::meta_key() );
		}
	}

	/* ------------------------------------------------------------------ the other avatars plugin mirror */

	/**
	 * Its _updated action (its profile screen, its REST field, its AJAX, its migration): while it renders, its
	 * screens are the only place users change photos, so our copy follows (otherwise switching over later would show the
	 * old photo). Registered only while the module is on.
	 */
	public static function sla_updated( $user_id ): void {
		$uid = (int) $user_id;
		if ( self::$delegating || $uid <= 0 ) {
			return;
		}
		$have = metadata_exists( 'user', $uid, self::meta_key() );
		$att = self::sla_attachment( $uid );
		if ( $att ) {
			update_post_meta( $att, self::META, 1 );
			if ( ! $have || self::assigned( $uid ) !== $att ) {
				update_user_meta( $uid, self::meta_key(), $att );
			}
			self::ensure_sizes( $att ); // One image, in its save request (never at render); also re-makes files it deleted.
			Ledger::record( 'user_avatar:' . $uid, 'user_meta', $uid, Ledger::hash_value( [ 'attachment' => $att ] ), 'set' );
		} elseif ( $have ) {
			delete_user_meta( $uid, self::meta_key() ); // No usable attachment (an old URL-only photo): the read fallback shows it.
			Ledger::record( 'user_avatar:' . $uid, 'user_meta', $uid, Ledger::hash_value( [ 'attachment' => 0 ] ), 'removed' );
		}
		self::$memo = [];
		Cache::purge_later();
	}

	/**
	 * Its _deleted action (also the first half of a photo change there): the user removed their photo, so ours goes
	 * too (a removal must not come back). It has just deleted its cached copies, which can include our square files.
	 */
	public static function sla_deleted( $user_id ): void {
		$uid = (int) $user_id;
		if ( self::$delegating || $uid <= 0 || ! metadata_exists( 'user', $uid, self::meta_key() ) ) {
			return;
		}
		$old = self::assigned( $uid );
		delete_user_meta( $uid, self::meta_key() ); // Its meta is gone too, so nothing old can come back.
		Ledger::record( 'user_avatar:' . $uid, 'user_meta', $uid, Ledger::hash_value( [ 'attachment' => 0 ] ), 'removed' );
		self::repair( [ $old ] ); // Only if someone still uses that image.
		self::$memo = [];
		Cache::purge_later();
	}

	/** The attachment behind a user's the other avatars plugin photo (its media id, else its old URL resolved), 0 if none. */
	private static function sla_attachment( int $user_id ): int {
		$sla = self::sla_meta( $user_id );
		if ( ! $sla ) {
			return 0;
		}
		if ( ! empty( $sla['media_id'] ) && self::valid_image( (int) $sla['media_id'] ) ) {
			return (int) $sla['media_id'];
		}
		if ( ! empty( $sla['full'] ) ) {
			$found = attachment_url_to_postid( (string) $sla['full'] );
			return ( $found && self::valid_image( $found ) ) ? $found : 0;
		}
		return 0;
	}

	/* ------------------------------------------------------------------ profile screen */

	/** Can the current user change this user's photo from the profile screen? */
	private static function ui_allowed( int $user_id ): bool {
		return current_user_can( 'upload_files' ) && true === self::can_set( $user_id );
	}

	/** user_profile_picture_description: our controls under the core "Profile Picture" preview. */
	public static function profile_description( $description, $user = null ) {
		if ( ! $user instanceof \WP_User || self::sla_active() ) {
			return $description;
		}
		if ( ! self::ui_allowed( $user->ID ) && null === self::source( $user->ID ) && ! self::local_default( [ 'default' => get_option( 'avatar_default' ) ] ) ) {
			return $description; // Gravatar is what shows for this user, and they cannot set a local photo: core's text is right.
		}
		return self::controls( $user );
	}

	/** show_user_profile / edit_user_profile: when avatars are hidden site-wide, core prints no picture row: add ours. */
	public static function profile_section( $user ): void {
		if ( ! $user instanceof \WP_User || self::sla_active() || get_option( 'show_avatars' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Profile photo', 'launch-builder' ) . '</h2><table class="form-table" role="presentation"><tr class="user-profile-picture"><th>' . esc_html__( 'Photo', 'launch-builder' ) . '</th><td>';
		echo get_avatar( $user->ID, 96, '', '', [ 'force_display' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
		echo '<p class="description">' . self::controls( $user ) . '</p></td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in controls().
	}

	private static function controls( \WP_User $user ): string {
		$self = get_current_user_id() === $user->ID;
		if ( ! self::ui_allowed( $user->ID ) ) {
			return esc_html( $self ? __( 'Your profile photo is managed by the site administrators.', 'launch-builder' ) : __( 'You cannot change this user\'s photo.', 'launch-builder' ) ) . self::preset_picker( $user );
		}
		$has = self::current_attachment( $user->ID ) > 0;
		$out = '<span class="lb-avatar-field" data-title="' . esc_attr__( 'Choose a profile photo', 'launch-builder' ) . '" data-button="' . esc_attr__( 'Use this photo', 'launch-builder' ) . '" data-chosen="' . esc_attr__( 'Photo chosen. Save the profile to apply it.', 'launch-builder' ) . '" data-removed="' . esc_attr__( 'Photo removed. Save the profile to apply it.', 'launch-builder' ) . '">';
		$out .= '<button type="button" class="button lb-avatar-choose">' . esc_html( $has ? __( 'Change photo', 'launch-builder' ) : __( 'Choose photo', 'launch-builder' ) ) . '</button> ';
		$out .= '<button type="button" class="button-link button-link-delete lb-avatar-remove"' . ( $has ? '' : ' hidden' ) . '>' . esc_html__( 'Remove photo', 'launch-builder' ) . '</button>';
		$out .= '<input type="hidden" name="lb_avatar" value="">' . wp_nonce_field( 'lb_avatar_' . $user->ID, '_lb_avatar_nonce', false, false );
		$out .= ' <span class="lb-avatar-status" aria-live="polite"></span><br>';
		$out .= esc_html__( 'Pick a photo from the Media Library. It is cropped to a square and used wherever your avatar shows; it is never loaded from Gravatar.', 'launch-builder' );
		return $out . '</span>' . self::preset_picker( $user );
	}

	/** Radio buttons for the ready-made pictures (only when there is more than one to choose from). */
	private static function preset_picker( \WP_User $user ): string {
		$presets = self::presets();
		if ( count( $presets ) < 2 || true !== self::can_set( $user->ID ) ) {
			return '';
		}
		$cur = self::user_preset( $user->ID );
		$out = '<br><span class="lb-avatar-presets" role="radiogroup" aria-label="' . esc_attr__( 'Ready-made pictures', 'launch-builder' ) . '">';
		$out .= esc_html__( 'Or pick a ready-made picture (shown when you have no photo of your own):', 'launch-builder' ) . '<br>';
		$out .= '<input type="hidden" name="lb_avatar_preset_sent" value="1">' . wp_nonce_field( 'lb_avatar_preset_' . $user->ID, '_lb_avatar_preset_nonce', false, false );
		$out .= '<label style="display:inline-block;margin:6px 14px 0 0"><input type="radio" name="lb_avatar_preset" value=""' . checked( '' === $cur, true, false ) . '> ' . esc_html__( 'The site default', 'launch-builder' ) . '</label>';
		foreach ( $presets as $slug => $p ) {
			$out .= '<label style="display:inline-block;margin:6px 14px 0 0"><input type="radio" name="lb_avatar_preset" value="' . esc_attr( $slug ) . '"' . checked( $slug === $cur, true, false ) . '> <img src="' . esc_url( $p['url'] ) . '" alt="" width="32" height="32" style="border-radius:50%;vertical-align:middle"> ' . esc_html( $p['label'] ) . '</label>';
		}
		return $out . '</span>';
	}

	/** The ready-made picture a person chose on their profile. Same rules as the photo: the nonce is not authorization. */
	private static function save_preset( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- verified below.
		if ( empty( $_POST['lb_avatar_preset_sent'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['_lb_avatar_preset_nonce'] ?? '' ) );
		$raw = isset( $_POST['lb_avatar_preset'] ) ? sanitize_key( wp_unslash( $_POST['lb_avatar_preset'] ) ) : '';
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, 'lb_avatar_preset_' . $user_id ) || true !== self::can_set( $user_id ) ) {
			return;
		}
		if ( '' === $raw ) {
			delete_user_meta( $user_id, self::META_PRESET );
		} elseif ( '' !== self::preset_slug( $raw ) ) {
			update_user_meta( $user_id, self::META_PRESET, self::preset_slug( $raw ) );
		} else {
			return;
		}
		self::$memo = [];
		Cache::purge_later();
	}

	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) || self::sla_active() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_register_script( 'lb-avatars', false, [ 'jquery', 'media-views' ], LB_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters -- inline only.
		wp_enqueue_script( 'lb-avatars' );
		wp_add_inline_script( 'lb-avatars', self::js() );
	}

	private static function js(): string {
		return <<<'JS'
(function ($) {
	$(function () {
		var $f = $('.lb-avatar-field');
		if (!$f.length || !window.wp || !wp.media) { return; }
		var frame, $in = $f.find('input[name="lb_avatar"]'), $st = $f.find('.lb-avatar-status'), $rm = $f.find('.lb-avatar-remove');
		var $img = $f.closest('td').find('img.avatar').first();
		$f.on('click', '.lb-avatar-choose', function (e) {
			e.preventDefault();
			if (!frame) {
				frame = wp.media({ title: $f.data('title'), button: { text: $f.data('button') }, library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON(), s = (a.sizes && (a.sizes.thumbnail || a.sizes.medium)) || a;
					$in.val(String(a.id));
					$img.attr('src', s.url).removeAttr('srcset').css('opacity', 1);
					$rm.prop('hidden', false);
					$st.text($f.data('chosen'));
				});
			}
			frame.open();
		});
		$rm.on('click', function (e) {
			e.preventDefault();
			$in.val('0');
			$img.css('opacity', 0.35);
			$rm.prop('hidden', true);
			$st.text($f.data('removed'));
		});
	});
})(jQuery);
JS;
	}

	/** personal_options_update / edit_user_profile_update. Re-checks everything the tool checks; the nonce is not authorization. */
	public static function save_profile( $user_id ): void {
		if ( ! self::sla_active() ) {
			self::save_preset( (int) $user_id );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified below.
		if ( ! isset( $_POST['lb_avatar'] ) || self::sla_active() ) {
			return;
		}
		$user_id = (int) $user_id;
		$raw = trim( sanitize_text_field( wp_unslash( $_POST['lb_avatar'] ) ) );
		if ( '' === $raw ) {
			return; // Untouched.
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['_lb_avatar_nonce'] ?? '' ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, 'lb_avatar_' . $user_id ) || ! ctype_digit( $raw ) ) {
			self::$profile_error = __( 'The profile photo was not saved: the form expired. Reload the page and try again.', 'launch-builder' );
			return;
		}
		if ( ! current_user_can( 'upload_files' ) || true !== self::can_set( $user_id ) ) {
			self::$profile_error = __( 'You may not change this profile photo.', 'launch-builder' );
			return;
		}
		$att = (int) $raw;
		if ( 0 === $att ) {
			if ( self::current_attachment( $user_id ) ) {
				self::unassign( $user_id );
			}
			return;
		}
		if ( self::assigned( $user_id ) === $att ) {
			return;
		}
		$p = self::check_attachment( $att, $user_id );
		if ( is_wp_error( $p ) ) {
			self::$profile_error = $p->get_error_message();
			return;
		}
		self::assign( $user_id, $att );
	}

	/** user_profile_update_errors: report a refused photo on the profile screen. */
	public static function profile_errors( $errors, $update = true, $user = null ): void {
		if ( self::$profile_error && $errors instanceof \WP_Error ) {
			$errors->add( 'lb_avatar', esc_html( self::$profile_error ) );
			self::$profile_error = null;
		}
	}

	/* ------------------------------------------------------------------ Discussion settings */

	private static function register_settings(): void {
		register_setting( 'discussion', self::OPT, [ 'type' => 'object', 'sanitize_callback' => [ __CLASS__, 'sanitize_form' ], 'show_in_rest' => false ] );
		add_settings_section( 'lb_avatars', __( 'Profile photos', 'launch-builder' ), [ __CLASS__, 'settings_intro' ], 'discussion' );
		add_settings_field( 'lb_avatars_fields', __( 'Local avatars', 'launch-builder' ), [ __CLASS__, 'settings_fields' ], 'discussion', 'lb_avatars' );
	}

	/**
	 * Sanitize the option: the Discussion form posts { _form, never_gravatar, default, anchor, self_service }; any other
	 * update_option() call passes the stored shape through normalise(). The form also sets core's avatar_default (it is
	 * saved before this option in the same request, so this wins).
	 */
	public static function sanitize_form( $in ) {
		if ( ! is_array( $in ) ) {
			return self::settings();
		}
		if ( empty( $in['_form'] ) ) {
			return self::normalise( $in );
		}
		$out = self::settings();
		$out['gravatar'] = empty( $in['never_gravatar'] );
		$d = (string) ( $in['default'] ?? '' );
		if ( in_array( $d, self::CHOICES, true ) ) {
			$out['default'] = $d;
		} elseif ( 0 === strpos( $d, self::PRESET_PREFIX ) && '' !== self::preset_slug( $d ) ) {
			$out['default'] = self::PRESET_PREFIX . self::preset_slug( $d );
		} elseif ( ! ( ctype_digit( $d ) && (int) $d === $out['default'] ) ) {
			$out['default'] = 'initials';
		}
		$out['anchor'] = in_array( $in['anchor'] ?? '', [ 'top', 'center' ], true ) ? $in['anchor'] : 'top';
		$out['self_service'] = ! empty( $in['self_service'] );
		self::sync_core_default( $out['gravatar'], $out['gravatar'] ); // Not while the other avatars plugin renders.
		self::$settings = null;
		self::$memo = [];
		Cache::purge_later();
		return $out;
	}

	public static function settings_intro(): void {
		echo '<p>' . esc_html__( 'Launch Builder shows profile photos from the Media Library. Set them on each user\'s profile (Users > Profile) or with the set-user-avatar tool.', 'launch-builder' ) . '</p>';
		if ( self::sla_active() ) {
			echo '<p><strong>' . esc_html__( 'The other avatars plugin is active, so it shows the photos for now. Launch Builder has imported its photos and keeps them in step with changes made in the other avatars plugin; deactivate the other avatars plugin to switch over (reactivating it is the way back).', 'launch-builder' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Until then the settings below are saved but not applied: "Never load profile pictures from Gravatar" and the default image take effect once the other avatars plugin is deactivated, and the Default Avatar choice above stays the one the other avatars plugin uses.', 'launch-builder' ) . '</p>';
		}
		if ( self::kadence_localgravatars() ) {
			echo '<p>' . esc_html__( 'Kadence Pro\'s "Local Gravatars" is on: it now only receives Gravatar images, never local photos. With Gravatar turned off below it has nothing left to do and can be switched off.', 'launch-builder' ) . '</p>';
		}
	}

	public static function settings_fields(): void {
		$s = self::settings();
		$n = self::OPT;
		$labels = [
			'initials'   => __( 'Initials on a coloured background', 'launch-builder' ),
			'logo'       => __( 'Site icon or logo', 'launch-builder' ),
			'silhouette' => __( 'Neutral silhouette', 'launch-builder' ),
			'blank'      => __( 'Blank', 'launch-builder' ),
		];
		echo '<fieldset><input type="hidden" name="' . esc_attr( $n ) . '[_form]" value="1">';
		echo '<label><input type="checkbox" name="' . esc_attr( $n ) . '[never_gravatar]" value="1"' . checked( ! $s['gravatar'], true, false ) . '> ' . esc_html__( 'Never load profile pictures from Gravatar', 'launch-builder' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Neither visitors\' browsers nor this server contact Gravatar. People without a photo get the default image below.', 'launch-builder' ) . '</p><br>';
		echo '<label>' . esc_html__( 'Default image', 'launch-builder' ) . ' <select name="' . esc_attr( $n ) . '[default]">';
		if ( is_int( $s['default'] ) ) {
			/* translators: %d: attachment id */
			echo '<option value="' . esc_attr( (string) $s['default'] ) . '" selected>' . esc_html( sprintf( __( 'Chosen image (#%d)', 'launch-builder' ), $s['default'] ) ) . '</option>';
		}
		if ( self::presets() ) {
			echo '<optgroup label="' . esc_attr__( 'Ready-made pictures', 'launch-builder' ) . '">';
			foreach ( self::presets() as $slug => $p ) {
				$v = self::PRESET_PREFIX . $slug;
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['default'], $v, false ) . '>' . esc_html( $p['label'] ) . '</option>';
			}
			echo '</optgroup><optgroup label="' . esc_attr__( 'Drawn for each person', 'launch-builder' ) . '">';
		} else {
			echo '<optgroup label="' . esc_attr__( 'Other', 'launch-builder' ) . '">';
		}
		foreach ( $labels as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['default'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</optgroup></select></label>';
		echo '<p class="description">' . esc_html__( 'Ready-made pictures, the silhouette, blank image and site icon are plain files; with Gravatar on, anyone Gravatar does not know also gets a ready-made default (on https sites). Initials are drawn by this site: the first time a visitor\'s browser meets a pair of letters it asks the site once for that small image (one per pair, cached for a year).', 'launch-builder' ) . '</p><br>';
		echo '<label>' . esc_html__( 'Square crop keeps', 'launch-builder' ) . ' <select name="' . esc_attr( $n ) . '[anchor]"><option value="top"' . selected( $s['anchor'], 'top', false ) . '>' . esc_html__( 'the top (faces)', 'launch-builder' ) . '</option><option value="center"' . selected( $s['anchor'], 'center', false ) . '>' . esc_html__( 'the centre', 'launch-builder' ) . '</option></select></label><br><br>';
		echo '<label><input type="checkbox" name="' . esc_attr( $n ) . '[self_service]" value="1"' . checked( $s['self_service'], true, false ) . '> ' . esc_html__( 'Users who can upload files may set their own photo', 'launch-builder' ) . '</label></fieldset>';
	}

	/* ------------------------------------------------------------------ privacy */

	public static function register_exporter( $exporters ) {
		$exporters['launch-builder-avatar'] = [ 'exporter_friendly_name' => __( 'Profile photo', 'launch-builder' ), 'callback' => [ __CLASS__, 'export' ] ];
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['launch-builder-avatar'] = [ 'eraser_friendly_name' => __( 'Profile photo', 'launch-builder' ), 'callback' => [ __CLASS__, 'erase' ] ];
		return $erasers;
	}

	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = [];
		if ( $user ) {
			$src = self::source( (int) $user->ID );
			if ( $src ) {
				$data[] = [
					'group_id'    => 'launch-builder-avatar',
					'group_label' => __( 'Profile photo', 'launch-builder' ),
					'item_id'     => 'launch-builder-avatar-' . $user->ID,
					'data'        => [ [ 'name' => __( 'Photo URL', 'launch-builder' ), 'value' => ! empty( $src['id'] ) ? (string) wp_get_attachment_url( (int) $src['id'] ) : (string) $src['url'] ] ],
				];
			}
		}
		if ( $user && '' !== self::user_preset( (int) $user->ID ) ) {
			$data[] = [
				'group_id'    => 'launch-builder-avatar',
				'group_label' => __( 'Profile photo', 'launch-builder' ),
				'item_id'     => 'launch-builder-avatar-preset-' . $user->ID,
				'data'        => [ [ 'name' => __( 'Ready-made picture', 'launch-builder' ), 'value' => self::user_preset( (int) $user->ID ) ] ],
			];
		}
		return [ 'data' => $data, 'done' => true ];
	}

	/** Unlinks the photo; the image itself is Media Library content (erase it there if it must go). */
	public static function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( $user && '' !== self::user_preset( (int) $user->ID ) ) {
			delete_user_meta( (int) $user->ID, self::META_PRESET );
			self::$memo = [];
			$removed = true;
		}
		if ( $user && self::current_attachment( (int) $user->ID ) ) {
			self::unassign( (int) $user->ID );
			$removed = true;
		}
		return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => $removed ? [ __( 'The profile photo was unlinked from the account; the image stays in the Media Library.', 'launch-builder' ) ] : [], 'done' => true ];
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		Abilities::add( 'get-user-avatars', 'List profile photos', 'Users with their profile photo: source lb (set with Launch Builder) | sla (The other avatars plugin, not imported yet) | gravatar | default, the attachment id, square URLs (96/192/640; none for gravatar), alt text and whether you may change it. Also the avatar settings, the provider that renders photos and counts. Never returns emails, logins or Gravatar hashes. Paginated.',
			[ 'properties' => [
				'offset'    => [ 'type' => 'integer', 'description' => 'Skip this many users (default 0).' ],
				'limit'     => [ 'type' => 'integer', 'description' => 'Users per call, 1-100 (default 50).' ],
				'user_id'   => [ 'type' => 'integer', 'description' => 'Only this user.' ],
				'has_local' => [ 'type' => 'boolean', 'description' => 'Only users with a Launch Builder photo.' ],
			] ], [ __CLASS__, 'get_user_avatars_tool' ], 'list_users', $ro );

		Abilities::add( 'set-user-avatar', 'Set a user\'s profile photo', 'Give a user a profile photo from the Media Library (attachment_id) or from a public image URL (url: imported into the Media Library first), or take it away (remove: true; the image stays in the library). Give exactly one of them. The photo shows everywhere WordPress shows avatars (author boxes, comments, the core Avatar block, Rank Math author schema) and is never loaded from Gravatar; square sizes are made once, now. Rules: you need upload_files; changing someone else needs permission to edit that user, and administrators can only be changed by administrators; the image must be one you can edit (or your own upload, for your own photo). anchor: top (default, keeps faces) or center. Undo: call again with previous_attachment_id (0 = remove). For technicians and staff who are not site users, use image blocks instead of creating accounts.',
			[ 'properties' => [
				'user_id'       => [ 'type' => 'integer', 'description' => 'The user (see get-user-avatars).' ],
				'attachment_id' => [ 'type' => 'integer', 'description' => 'A Media Library image (JPEG, PNG, WebP, AVIF or GIF).' ],
				'url'           => [ 'type' => 'string', 'description' => 'A public http(s) image URL to import instead.' ],
				'alt'           => [ 'type' => 'string', 'description' => 'Alt text written to the image (only if you may edit it).' ],
				'anchor'        => [ 'type' => 'string', 'enum' => [ 'top', 'center' ] ],
				'remove'        => [ 'type' => 'boolean', 'description' => 'Take the photo away.' ],
				'preset'        => [ 'type' => 'string', 'description' => 'A ready-made picture (a slug from the presets in get-user-avatars) shown when the user has no photo of their own; none clears it.' ],
			], 'required' => [ 'user_id' ] ], [ __CLASS__, 'set_user_avatar_tool' ], 'upload_files' );

		Abilities::add( 'set-avatar-settings', 'Profile photo settings', 'gravatar: false = never load anything from Gravatar (users without a photo get the default image; also sets WordPress\' default avatar). default: initials | logo (site icon, else logo) | silhouette | blank | preset:<slug> (a ready-made picture; preset:launchlocal is the LaunchLocal mark and the result lists every preset) | attachment (with default_attachment_id). anchor: top | center (square crop of new photos). self_service: users who can upload may set their own photo. While the other avatars plugin is active it keeps rendering: the settings are saved and apply once it is deactivated (warnings say so). Snapshotted (restore-snapshot undoes it).',
			[ 'properties' => [
				'gravatar'              => [ 'type' => 'boolean' ],
				'default'               => [ 'type' => 'string', 'description' => 'initials | logo | silhouette | blank | attachment | preset:<slug> (see presets in the result).' ],
				'default_attachment_id' => [ 'type' => 'integer' ],
				'anchor'                => [ 'type' => 'string', 'enum' => [ 'top', 'center' ] ],
				'self_service'          => [ 'type' => 'boolean' ],
			] ], [ __CLASS__, 'set_avatar_settings_tool' ], 'manage_options' );

		Abilities::add( 'import-avatars', 'Import photos from the other avatars plugin', 'Copy photos from the other avatars plugin into Launch Builder (users who already have a Launch Builder photo, or removed theirs, are skipped; the other avatars plugin\' own data is never changed). The same import runs once by itself after an update; use this to repeat it. dry_run (default true) only reports. Paginated by user id (after_id, limit <= 200).',
			[ 'properties' => [
				'dry_run'  => [ 'type' => 'boolean' ],
				'after_id' => [ 'type' => 'integer' ],
				'limit'    => [ 'type' => 'integer' ],
			] ], [ __CLASS__, 'import_avatars_tool' ], 'manage_options' );
	}

	private static function module_off(): \WP_Error {
		return new \WP_Error( 'lb_module_off', 'The Local avatars module is turned off on this site.', [ 'status' => 409 ] );
	}

	/** Provider that renders photos now. */
	public static function provider(): string {
		if ( self::sla_active() ) {
			return self::SLA_SLUG;
		}
		return self::enabled() ? 'launch-builder' : 'none';
	}

	/** Square URLs for a user, without building Gravatar URLs (they carry an email hash). */
	private static function urls( int $user_id, ?array $src = null ): array {
		$src = $src ?? self::source( $user_id );
		$out = [];
		foreach ( [ 96, 192, 640 ] as $px ) {
			if ( $src ) {
				$out[ $px ] = self::source_url( $src, $px );
			} elseif ( '' !== self::user_preset( $user_id ) ) {
				$out[ $px ] = self::preset_url( self::user_preset( $user_id ) );
			} elseif ( self::local_default( [ 'default' => get_option( 'avatar_default' ) ] ) ) {
				$u = get_userdata( $user_id );
				$name = $u ? (string) $u->display_name : '';
				$args = [];
				$out[ $px ] = self::default_url( $name, $px, $args );
			}
		}
		return $out;
	}

	private static function settings_out(): array {
		$s = self::settings();
		$presets = [];
		foreach ( self::presets() as $slug => $p ) {
			$presets[] = [ 'slug' => $slug, 'value' => self::PRESET_PREFIX . $slug, 'label' => $p['label'], 'url' => $p['url'] ];
		}
		return $s + [ 'core_avatar_default' => (string) get_option( 'avatar_default', 'mystery' ), 'show_avatars' => (bool) get_option( 'show_avatars' ), 'presets' => $presets ];
	}

	public static function get_user_avatars_tool( $input = [] ): array {
		$input = (array) $input;
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) );
		$q = [ 'number' => $limit, 'offset' => max( 0, (int) ( $input['offset'] ?? 0 ) ), 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true ];
		if ( ! empty( $input['user_id'] ) ) {
			$q['include'] = [ (int) $input['user_id'] ];
		}
		if ( ! empty( $input['has_local'] ) ) {
			$q['meta_query'] = [ [ 'key' => self::meta_key(), 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$uq = new \WP_User_Query( $q );
		$sla_on = self::sla_active();
		$rows = [];
		foreach ( (array) $uq->get_results() as $u ) {
			$uid = (int) $u->ID;
			$src = self::source( $uid );
			if ( $sla_on ) { // The other avatars plugin renders: report what it shows.
				$sla = self::sla_meta( $uid );
				$source = $sla ? 'sla' : ( self::settings()['gravatar'] ? 'gravatar' : 'default' );
				$att = $sla ? (int) ( $sla['media_id'] ?? 0 ) : 0;
			} else {
				$source = $src ? (string) $src['from'] : ( self::local_default( [ 'default' => get_option( 'avatar_default' ) ] ) ? 'default' : 'gravatar' );
				$att = $src ? (int) ( $src['id'] ?? 0 ) : 0;
			}
			$rows[] = [
				'id'            => $uid,
				'display_name'  => $u->display_name,
				'roles'         => array_values( (array) $u->roles ),
				'source'        => $source,
				'attachment_id' => $att,
				'preset'        => self::user_preset( $uid ),
				'urls'          => 'gravatar' === $source ? new \stdClass() : self::urls( $uid, $src ),
				'alt'           => $att ? (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) : '',
				'can_edit'      => current_user_can( 'upload_files' ) && true === self::can_set( $uid ),
			];
		}
		return [
			'enabled'  => self::enabled(),
			'provider' => self::provider(),
			'settings' => self::settings_out(),
			'users'    => $rows,
			'total'    => (int) $uq->get_total(),
			'counts'   => self::counts(),
			'next_offset' => ( (int) ( $input['offset'] ?? 0 ) + $limit ) < (int) $uq->get_total() ? (int) ( $input['offset'] ?? 0 ) + $limit : null,
		];
	}

	/** @return array{with_local:int,sla_unmigrated:int} */
	private static function counts(): array {
		$count = static function ( array $mq ): int {
			$q = new \WP_User_Query( [ 'number' => 1, 'fields' => 'ID', 'count_total' => true, 'meta_query' => $mq ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			return (int) $q->get_total();
		};
		$sla = [ 'relation' => 'OR' ];
		foreach ( self::sla_keys() as $k ) {
			$sla[] = [ 'key' => $k, 'compare' => 'EXISTS' ];
		}
		return [
			'with_local'     => $count( [ [ 'key' => self::meta_key(), 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ] ] ),
			'sla_unmigrated' => $count( [ 'relation' => 'AND', $sla, [ 'key' => self::meta_key(), 'compare' => 'NOT EXISTS' ] ] ),
		];
	}

	public static function set_user_avatar_tool( $input ) {
		$input = (array) $input;
		if ( ! self::enabled() ) {
			return self::module_off();
		}
		$uid = (int) ( $input['user_id'] ?? 0 );
		$ok = self::can_set( $uid );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$att = (int) ( $input['attachment_id'] ?? 0 );
		$url = trim( (string) ( $input['url'] ?? '' ) );
		$remove = ! empty( $input['remove'] );
		$preset = sanitize_key( (string) ( $input['preset'] ?? '' ) );
		if ( 1 !== count( array_filter( [ $att > 0, '' !== $url, $remove, '' !== $preset ] ) ) ) {
			return new \WP_Error( 'lb_bad_args', 'Give exactly one of attachment_id, url, preset or remove.', [ 'status' => 400 ] );
		}
		if ( '' !== $preset ) {
			if ( 'none' !== $preset && '' === self::preset_slug( $preset ) ) {
				return new \WP_Error( 'lb_bad_preset', 'That ready-made picture does not exist (see the presets in get-user-avatars).', [ 'status' => 400 ] );
			}
			$prev = self::user_preset( $uid );
			if ( 'none' === $preset ) {
				delete_user_meta( $uid, self::META_PRESET );
			} else {
				update_user_meta( $uid, self::META_PRESET, self::preset_slug( $preset ) );
			}
			self::$memo = [];
			Cache::purge_later();
			return [ 'user_id' => $uid, 'preset' => self::user_preset( $uid ), 'previous_preset' => $prev, 'urls' => self::urls( $uid ), 'warnings' => [], 'provider' => self::provider() ];
		}
		if ( $remove ) {
			$prev = self::unassign( $uid );
			return [ 'user_id' => $uid, 'previous_attachment_id' => $prev, 'attachment_id' => 0, 'urls' => self::urls( $uid ), 'warnings' => [], 'provider' => self::provider() ];
		}
		$alt = trim( sanitize_text_field( (string) ( $input['alt'] ?? '' ) ) );
		if ( '' !== $url ) {
			$r = Abilities::sideload_media( [ 'url' => $url, 'alt' => $alt ] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$att = (int) $r['id'];
		}
		$p = self::check_attachment( $att, $uid );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( '' !== $alt && '' === $url && current_user_can( 'edit_post', $att ) ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $alt );
		}
		$r = self::assign( $uid, $att, (string) ( $input['anchor'] ?? '' ) );
		return [ 'user_id' => $uid, 'previous_attachment_id' => $r['previous'], 'attachment_id' => $att, 'urls' => self::urls( $uid ), 'warnings' => $r['warnings'], 'provider' => self::provider() ];
	}

	public static function set_avatar_settings_tool( $input ) {
		$input = (array) $input;
		$s = self::settings();
		$changed = [];
		if ( array_key_exists( 'gravatar', $input ) ) {
			$s['gravatar'] = (bool) $input['gravatar'];
			$changed[] = 'gravatar';
		}
		$d = (string) ( $input['default'] ?? '' );
		$did = (int) ( $input['default_attachment_id'] ?? 0 );
		if ( 'attachment' === $d || ( '' === $d && $did > 0 ) ) {
			$p = self::check_attachment( $did, 0 );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$s['default'] = $did;
			$changed[] = 'default';
			update_post_meta( $did, self::META, 1 );
			self::ensure_sizes( $did );
		} elseif ( in_array( $d, self::CHOICES, true ) ) {
			$s['default'] = $d;
			$changed[] = 'default';
		} elseif ( '' !== $d && '' !== self::preset_slug( $d ) ) {
			$s['default'] = self::PRESET_PREFIX . self::preset_slug( $d );
			$changed[] = 'default';
		} elseif ( '' !== $d && 'attachment' !== $d ) {
			return new \WP_Error( 'lb_bad_default', 'default must be initials, logo, silhouette, blank, attachment or preset:<slug> (see presets in get-user-avatars).', [ 'status' => 400 ] );
		}
		foreach ( [ 'anchor', 'self_service' ] as $k ) {
			if ( array_key_exists( $k, $input ) ) {
				$s[ $k ] = $input[ $k ];
				$changed[] = $k;
			}
		}
		if ( ! $changed ) {
			return new \WP_Error( 'lb_empty', 'Nothing to change: give gravatar, default, anchor or self_service.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-avatar-settings', [], [ self::OPT, 'avatar_default' ] );
		self::save_settings( $s, ! empty( $input['gravatar'] ) );
		$warnings = [];
		if ( self::sla_active() ) {
			$warnings[] = 'The other avatars plugin is active and renders profile photos with its own settings: these settings are saved but take effect once it is deactivated (core avatar_default is left as it is until then).';
		}
		return [ 'settings' => self::settings_out(), 'changed' => $changed, 'snapshot' => $snap, 'enabled' => self::enabled(), 'warnings' => $warnings ];
	}

	public static function import_avatars_tool( $input ) {
		$input = (array) $input;
		$dry = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$after = max( 0, (int) ( $input['after_id'] ?? 0 ) );
		if ( ! $dry && ! self::enabled() ) {
			return self::module_off();
		}
		if ( ! current_user_can( 'edit_users' ) ) {
			return new \WP_Error( 'lb_forbidden', 'Importing photos needs permission to edit users.', [ 'status' => 403 ] );
		}
		if ( ! Lock::acquire( 'migrate', 120 ) ) {
			return new \WP_Error( 'lb_busy', 'A data migration is running; try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			$r = self::import_batch( $after, max( 1, min( 200, (int) ( $input['limit'] ?? 100 ) ) ), $dry );
			if ( 0 === $after ) {
				$r['settings'] = self::import_settings( $dry );
			}
		} finally {
			Lock::release( 'migrate' );
		}
		$r['dry_run'] = $dry;
		return $r;
	}

	/* ------------------------------------------------------------------ migration from the other avatars plugin */

	/**
	 * Import one batch of users that have the other avatars plugin meta (ID order, after $after). Never overwrites a
	 * Launch Builder photo (or a removal) and never touches SLA's meta or files.
	 *
	 * @return array{imported:int,imported_ids:int[],skipped_existing:int,unresolved:int[],ratings_ignored:int,next_after_id:?int}
	 */
	public static function import_batch( int $after, int $limit, bool $dry ): array {
		global $wpdb;
		$keys = self::sla_keys();
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ($in) AND user_id > %d ORDER BY user_id ASC LIMIT %d", array_merge( $keys, [ $after, $limit ] ) ) ) ); // phpcs:ignore WordPress.DB
		$out = [ 'imported' => 0, 'imported_ids' => [], 'skipped_existing' => 0, 'unresolved' => [], 'ratings_ignored' => 0, 'next_after_id' => count( $ids ) < $limit ? null : (int) end( $ids ) ];
		$rows = [];
		foreach ( $ids as $uid ) {
			if ( metadata_exists( 'user', $uid, self::meta_key() ) ) {
				$out['skipped_existing']++;
				continue;
			}
			$att = self::sla_attachment( $uid );
			foreach ( self::sla_keys() as $k ) {
				if ( in_array( strtoupper( (string) get_user_meta( $uid, str_replace( self::SLA_META, self::SLA_RATING, $k ), true ) ), [ 'R', 'X' ], true ) ) {
					$out['ratings_ignored']++;
					break;
				}
			}
			if ( ! $att ) {
				$out['unresolved'][] = $uid;
				continue;
			}
			$out['imported']++;
			$out['imported_ids'][] = $uid;
			if ( ! $dry ) {
				update_post_meta( $att, self::META, 1 );
				update_user_meta( $uid, self::meta_key(), $att );
				$rows[ 'user_avatar:' . $uid ] = [ 'user_meta', $uid, Ledger::hash_value( [ 'attachment' => $att ] ), 'set' ];
			}
		}
		if ( $rows ) {
			Ledger::record_many( $rows );
			self::$memo = [];
			Cache::purge_later();
		}
		return $out;
	}

	/**
	 * Carry the other avatars plugin's settings over, once, while ours were never saved: its default image, "Local Avatars
	 * Only" (becomes Gravatar off, which is stricter: SLA still loaded its default images from Gravatar) and a core
	 * its own avatar_default value (also Gravatar off). Only our option is written: core's avatar_default
	 * follows when SLA is no longer active (sync_core_default() never touches it while SLA renders). Snapshotted.
	 *
	 * @return string[] what changed (or would change)
	 */
	public static function import_settings( bool $dry ): array {
		if ( false !== get_option( self::OPT, false ) ) {
			return [];
		}
		$new = self::DEFAULTS;
		$changes = [];
		$sla_default = (int) get_option( self::SLA_DEFAULT_OPT, 0 );
		if ( $sla_default && self::valid_image( $sla_default ) ) {
			$new['default'] = $sla_default;
			$changes[] = 'default image: attachment ' . $sla_default . ' (default of the other avatars plugin)';
		}
		$opts = get_option( self::SLA_OPT );
		if ( is_array( $opts ) && ! empty( $opts['only'] ) ) {
			$new['gravatar'] = false;
			$changes[] = 'Local Avatars Only -> Gravatar off (stricter: The other avatars plugin still loaded its default images from Gravatar)';
		}
		if ( self::SLA_META === get_option( 'avatar_default' ) ) {
			$new['gravatar'] = false;
			$changes[] = 'avatar_default of the other plugin -> Gravatar off (local default)';
		}
		if ( $changes && self::sla_active() ) {
			$changes[] = 'The other avatars plugin is active: it keeps rendering with its own settings; core avatar_default switches to launch_builder when it is deactivated';
		}
		if ( $changes && ! $dry ) {
			Abilities::snapshot( 'avatars-import-sla settings', [], [ self::OPT, 'avatar_default' ] );
			self::save_settings( $new );
		}
		return $changes;
	}

	/** Migrate step avatars-import-sla: batches of 25 users; the summary is kept in launch_builder_avatars_import. */
	public static function import_step( $cursor, int $batch ): array {
		$c = is_array( $cursor ) ? $cursor : [ 'after' => 0, 'imported' => 0, 'skipped_existing' => 0, 'unresolved' => [], 'ratings_ignored' => 0, 'settings' => null ];
		if ( null === $c['settings'] ) {
			$c['settings'] = self::import_settings( false );
		}
		$r = self::import_batch( (int) $c['after'], max( 1, min( 25, $batch ) ), false );
		$c['imported'] += $r['imported'];
		$c['skipped_existing'] += $r['skipped_existing'];
		$c['ratings_ignored'] += $r['ratings_ignored'];
		$c['unresolved'] = array_slice( array_merge( $c['unresolved'], $r['unresolved'] ), 0, 100 );
		if ( null !== $r['next_after_id'] ) {
			$c['after'] = $r['next_after_id'];
			return [ 'done' => false, 'cursor' => $c, 'report' => '' ];
		}
		$summary = [ 'time' => gmdate( 'c' ), 'imported' => $c['imported'], 'skipped_existing' => $c['skipped_existing'], 'unresolved' => $c['unresolved'], 'ratings_ignored' => $c['ratings_ignored'], 'settings' => (array) $c['settings'] ];
		update_option( self::OPT_IMPORT, $summary, false );
		$report = sprintf( 'Other avatars plugin: %d imported, %d skipped (already set), %d unresolved', $c['imported'], $c['skipped_existing'], count( $c['unresolved'] ) );
		return [ 'done' => true, 'report' => $c['imported'] || $c['skipped_existing'] || $c['unresolved'] || $c['settings'] ? $report : '' ];
	}

	/** Migrate step avatars-subsizes: square sizes for flagged images that lack them, 5 images per batch. */
	public static function subsizes_step( $cursor, int $batch ): array {
		global $wpdb;
		$limit = max( 1, min( 5, $batch ) );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' AND post_id > %d ORDER BY post_id ASC LIMIT %d", self::META, (int) $cursor, $limit ) ) ); // phpcs:ignore WordPress.DB
		$made = 0;
		foreach ( $ids as $id ) {
			if ( self::valid_image( $id ) && self::needs_sizes( $id ) ) {
				self::ensure_sizes( $id );
				$made++;
			}
		}
		$report = $made ? 'square avatar sizes made for ' . $made . ' images' : '';
		return count( $ids ) < $limit ? [ 'done' => true, 'report' => $report ] : [ 'done' => false, 'cursor' => (int) end( $ids ), 'report' => $report ];
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function site_state( array &$state ): void {
		$state['avatars'] = [
			'enabled'                 => self::enabled(),
			'provider'                => self::provider(),
			'gravatar'                => self::settings()['gravatar'],
			'default'                 => self::settings()['default'],
			'core_avatar_default'     => (string) get_option( 'avatar_default', 'mystery' ),
			'sla_active'              => self::sla_active(),
			'kadence_localgravatars'  => self::kadence_localgravatars(),
			'kadence_wrapped'         => self::kadence_wrapped(),
			'import'                  => get_option( self::OPT_IMPORT, null ),
		] + ( self::enabled() ? self::counts() : [] );
	}

	public static function playbook( array &$pb ): void {
		$pb['avatars'] = 'Profile photos (author boxes, team members who are site users, comments, Rank Math author schema) come from the Media Library: set-user-avatar { user_id, attachment_id | url } (get-user-avatars lists users and who may be changed). For author boxes and team grids use core/avatar (userId, or post-author context in a Query Loop) with core/post-author-name and core/post-author-biography: they pick the photo up automatically. Never create WordPress accounts just to show a technician or staff photo: use image blocks for those. set-avatar-settings { gravatar: false } stops every Gravatar request (privacy); the default image is then initials, the site icon/logo, a silhouette or a chosen image.';
		$pb['tools_by_job']['Profile photos'] = 'get-user-avatars, set-user-avatar, set-avatar-settings, import-avatars';
	}

	/** Module hook: audit checks (site-wide, bounded). */
	public static function audit_checks(): array {
		if ( ! self::enabled() ) {
			return [];
		}
		return [
			'avatars_duplicate_provider'     => [ 'avatars', [ __CLASS__, 'check_provider' ] ],
			'avatars_kadence_localgravatars' => [ 'avatars', [ __CLASS__, 'check_kadence' ] ],
			'avatars_broken'                 => [ 'avatars', [ __CLASS__, 'check_broken' ] ],
			'avatars_gravatar_leak'          => [ 'avatars', [ __CLASS__, 'check_leak' ] ],
		];
	}

	public static function check_provider( $args = [] ): array {
		if ( ! self::sla_active() ) {
			return [];
		}
		$c = self::counts();
		return [ [ 'severity' => 'warning', 'object_type' => 'plugin', 'id' => self::SLA_SLUG, 'url' => admin_url( 'plugins.php' ), 'message' => 'The other avatars plugin is active next to Launch Builder\'s local avatars (it renders; ' . $c['sla_unmigrated'] . ' of its photos are not imported yet).', 'fix' => 'Run import-avatars { dry_run: false }, then deactivate the other avatars plugin (reactivating it is the rollback).' ] ];
	}

	public static function check_kadence( $args = [] ): array {
		if ( ! self::kadence_localgravatars() ) {
			return [];
		}
		$off = ! self::settings()['gravatar'];
		return [ [ 'severity' => $off ? 'warning' : 'info', 'object_type' => 'option', 'id' => 'kadence_pro_theme_config', 'url' => '', 'message' => $off ? 'Kadence Pro "Local Gravatars" is on but Gravatar is off: it has nothing to do.' : 'Kadence Pro "Local Gravatars" is on: Launch Builder passes it Gravatar images only (local photos are never copied into wp-content/gravatars).', 'fix' => $off ? 'set-pro-modules { localgravatars: false }' : '' ] ];
	}

	public static function check_broken( $args = [] ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value > 0 ORDER BY user_id ASC LIMIT 500", self::meta_key() ) ); // phpcs:ignore WordPress.DB
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! self::valid_image( (int) $r->meta_value ) ) {
				$out[] = [ 'severity' => 'warning', 'object_type' => 'user', 'id' => (int) $r->user_id, 'url' => get_edit_user_link( (int) $r->user_id ), 'message' => 'The profile photo points to attachment ' . (int) $r->meta_value . ', which is missing, trashed or not an allowed image.', 'fix' => 'set-user-avatar with a new attachment_id, or remove: true.' ];
			} elseif ( self::needs_sizes( (int) $r->meta_value ) ) {
				$out[] = [ 'severity' => 'info', 'object_type' => 'attachment', 'id' => (int) $r->meta_value, 'url' => '', 'message' => 'The profile photo has no square sizes yet, or one of their files is missing (it shows as its thumbnail, or as a broken image).', 'fix' => 'set-user-avatar again with the same attachment_id (it makes them again).' ];
			}
		}
		return $out;
	}

	public static function check_leak( $args = [] ): array {
		if ( ! self::settings()['gravatar'] || self::CORE_DEFAULT === get_option( 'avatar_default' ) ) {
			return [];
		}
		$authors = get_users( [ 'has_published_posts' => true, 'fields' => 'ID', 'number' => 100 ] );
		$missing = array_values( array_filter( array_map( 'intval', $authors ), static function ( $id ) { return null === self::source( $id ); } ) );
		if ( ! $missing ) {
			return [];
		}
		return [ [ 'severity' => 'info', 'object_type' => 'user', 'id' => $missing[0], 'url' => '', 'message' => count( $missing ) . ' post author(s) have no local photo, so visitors\' browsers load their picture from Gravatar (user ids: ' . implode( ', ', array_slice( $missing, 0, 20 ) ) . ').', 'fix' => 'set-user-avatar for each author, or set-avatar-settings { gravatar: false }.' ] ];
	}
}
