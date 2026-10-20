<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Branding: white-label settings for a client hand-off. Off until an administrator turns it on under Launch Builder >
 * Modules, and even then inert until the Branding screen (or set-branding) has saved settings once.
 *
 * What it can brand (each part has its own switch):
 *  - the sign-in screen (logo, colours, message, a "Website by" credit; no wordpress.org links);
 *  - a real "Site Owner" role that simply lacks the dangerous capabilities, with a simplified admin menu, admin bar,
 *    dashboard and notices for it (administrators are never simplified, redirected or locked out);
 *  - a welcome panel on the client dashboard, admin footer text, admin bar logo, page titles, the editor accent colour;
 *  - the sender name/address of WordPress emails;
 *  - the dashboard colours (sidebar, top bar, links and buttons) for the people you choose (Branding_Colors).
 *
 * Values come from what the site already knows: the business name from Business Info, the logo and site icon from the
 * theme, colours from the brand palette. Only overrides are stored (option launch_builder_branding, not autoloaded);
 * change Business Info or the palette and every branded surface follows on the next request.
 *
 * Switches that are never skipped: the constant LB_BRANDING_SAFE_MODE in wp-config.php (true) turns every surface off;
 * LB_ADMIN_COLORS_OFF turns off only the dashboard colours. Nothing is registered on the public front end except a
 * handful of hooks that read nothing until they fire.
 *
 * Files: this class (settings, validation, tools), Branding_Chrome (login screen, admin bar, footer, emails),
 * Branding_Client (role, menus, notices, dashboard), Branding_Colors (dashboard colours) and Branding_Admin (the
 * settings screen).
 */
final class Branding {

	const MODULE = [
		'slug'        => 'branding',
		'label'       => 'Branding',
		'description' => 'White-label a client hand-off: branded sign-in screen, a simplified Site Owner role and dashboard, dashboard colours, admin footer and email sender, fed from Business Info and the brand palette.',
		'toggle'      => true,
		'default'     => false,
		'note'        => 'Does nothing until you save the Branding screen once. Administrators are never simplified or locked out. LB_BRANDING_SAFE_MODE in wp-config.php turns every branded surface off.',
	];

	const OPT     = 'launch_builder_branding';
	const ROLE    = 'lb_client';
	const PAGE    = 'launch-builder-branding';
	const VERSION = 1;

	/** Agency identity used until the agency settings say otherwise (filter launch_builder_agency, constant LB_AGENCY as JSON). */
	const AGENCY = [
		'name'  => 'LaunchLocal Digital Marketing',
		'short' => 'LaunchLocal',
		'url'   => 'https://golaunchlocal.com',
	];

	/** Dashboard tiles the welcome panel can show. */
	const TILES = [ 'pages', 'posts', 'services', 'areas', 'media', 'comments', 'site', 'profile' ];

	/** Dashboard widgets that can never be removed for administrators (Site Health, the PHP update notice). */
	const KEEP_WIDGETS = [ 'dashboard_site_health', 'dashboard_php_nag' ];

	/**
	 * What every setting accepts. A definition is [ type, ...args ]; a nested array of definitions is a group.
	 * Types: bool, text(max), name(max; quotes and angle brackets are removed), url, email, att (image attachment id),
	 * color (#hex or paletteN), hex (#RGB or #RRGGBB only), int(min,max), enum(list), kses(max), greeting, list(kind), roles.
	 */
	const SCHEMA = [
		'agency'    => [
			'name'          => [ 'text', 80 ],
			'url'           => [ 'url' ],
			'logo'          => [ 'att' ],
			'logo_square'   => [ 'att' ],
			'support_email' => [ 'email' ],
			'support_phone' => [ 'text', 40 ],
			'support_url'   => [ 'url' ],
			'booking_url'   => [ 'url' ],
		],
		'login'     => [
			'enabled'         => [ 'bool' ],
			'logo_source'     => [ 'enum', [ 'client', 'agency', 'custom' ] ],
			'logo'            => [ 'att' ],
			'logo_width'      => [ 'int', 80, 320 ],
			'bg'              => [ 'color' ],
			'bg_image'        => [ 'att' ],
			'bg_overlay'      => [ 'int', 0, 80 ],
			'form_bg'         => [ 'color' ],
			'text'            => [ 'color' ],
			'button_bg'       => [ 'color' ],
			'button_text'     => [ 'color' ],
			'button_hover_bg' => [ 'color' ],
			'link'            => [ 'color' ],
			'message'         => [ 'text', 200 ],
			'hide_back_link'  => [ 'bool' ],
			'credit'          => [ 'bool' ],
		],
		'admin'     => [
			'replace_wp_logo'          => [ 'bool' ],
			'bar_logo_source'          => [ 'enum', [ 'agency', 'client' ] ],
			'bar_link'                 => [ 'enum', [ 'dashboard', 'site', 'agency' ] ],
			'greeting'                 => [ 'greeting' ],
			'menu_logo'                => [ 'bool' ],
			'menu_logo_source'         => [ 'enum', [ 'agency', 'client' ] ],
			'menu_logo_image'          => [ 'att' ],
			'footer_text'              => [ 'text', 200 ],
			'footer_link'              => [ 'url' ],
			'title_suffix'             => [ 'text', 40 ],
			'editor_accent'            => [ 'bool' ],
			'hide_version_for_clients' => [ 'bool' ],
		],
		'dashboard' => [
			'welcome'        => [
				'enabled'      => [ 'bool' ],
				'roles'        => [ 'list', 'role' ],
				'title'        => [ 'text', 80 ],
				'message'      => [ 'kses', 4096 ],
				'tiles'        => [ 'list', 'tile' ],
				'support_card' => [ 'bool' ],
			],
			'client_widgets' => [ 'list', 'widget' ],
			'admin_remove'   => [ 'list', 'widget_admin' ],
			'lb_widget'      => [ 'bool' ],
		],
		'client'    => [
			'role'                 => [
				'enabled' => [ 'bool' ],
				'label'   => [ 'text', 40 ],
				'flags'   => [
					'comments'        => [ 'bool' ],
					'seo_box'         => [ 'enum', [ 'none', 'basic' ] ],
					'unfiltered_html' => [ 'bool' ],
				],
			],
			'roles'                => [ 'roles' ],
			'hide_nags'            => [ 'bool' ],
			'hide_help'            => [ 'bool' ],
			'hide_screen_options'  => [ 'bool' ],
			'block_hidden_screens' => [ 'bool' ],
			'ai_off'               => [ 'bool' ],
			'blocks_home_off'      => [ 'bool' ],
		],
		'email'     => [
			'from_name_source' => [ 'enum', [ 'off', 'business', 'agency', 'custom' ] ],
			'from_name'        => [ 'text', 80 ],
			'from_email'       => [ 'email' ],
		],
		'admin_colors' => [
			'scope'        => [ 'enum', [ 'clients', 'everyone', 'nobody' ] ],
			'scheme'       => [ 'enum', Branding_Colors::SCHEME_IDS ],
			'sidebar'      => [ 'hex' ],
			'sidebar_text' => [ 'hex' ],
			'highlight'    => [ 'hex' ],
			'topbar'       => [ 'hex' ],
			'accent'       => [ 'hex' ],
		],
	];

	/** Stored values equal to these are never stored (only overrides are). */
	const DEFAULTS = [
		'agency'    => [ 'name' => '', 'url' => '', 'logo' => 0, 'logo_square' => 0, 'support_email' => '', 'support_phone' => '', 'support_url' => '', 'booking_url' => '' ],
		'login'     => [ 'enabled' => false, 'logo_source' => 'client', 'logo' => 0, 'logo_width' => 200, 'bg' => '', 'bg_image' => 0, 'bg_overlay' => 40, 'form_bg' => '', 'text' => '', 'button_bg' => '', 'button_text' => '', 'button_hover_bg' => '', 'link' => '', 'message' => '', 'hide_back_link' => false, 'credit' => true ],
		'admin'     => [ 'replace_wp_logo' => false, 'bar_logo_source' => 'client', 'bar_link' => 'dashboard', 'greeting' => '', 'menu_logo' => false, 'menu_logo_source' => 'client', 'menu_logo_image' => 0, 'footer_text' => '', 'footer_link' => '', 'title_suffix' => '', 'editor_accent' => false, 'hide_version_for_clients' => false ],
		'dashboard' => [
			'welcome'        => [ 'enabled' => false, 'roles' => [], 'title' => '', 'message' => '', 'tiles' => [ 'pages', 'posts', 'services', 'areas', 'media', 'site' ], 'support_card' => true ],
			'client_widgets' => [],
			'admin_remove'   => [],
			'lb_widget'      => false,
		],
		'client'    => [
			'role'                 => [ 'enabled' => false, 'label' => 'Site Owner', 'flags' => [ 'comments' => false, 'seo_box' => 'none', 'unfiltered_html' => false ] ],
			'roles'                => [],
			'hide_nags'            => false,
			'hide_help'            => false,
			'hide_screen_options'  => false,
			'block_hidden_screens' => true,
			'ai_off'               => false,
			'blocks_home_off'      => false,
		],
		'email'     => [ 'from_name_source' => 'off', 'from_name' => '', 'from_email' => '' ],
		'admin_colors' => [ 'scope' => 'clients', 'scheme' => '', 'sidebar' => '', 'sidebar_text' => '', 'highlight' => '', 'topbar' => '', 'accent' => '' ],
	];

	/** The sensible first setup: what the Branding screen pre-ticks before the first save and apply_recommended sets. */
	const RECOMMENDED = [
		'login'     => [ 'enabled' => true, 'credit' => true ],
		'admin'     => [ 'replace_wp_logo' => true, 'hide_version_for_clients' => true ],
		'dashboard' => [ 'welcome' => [ 'enabled' => true ] ],
		'client'    => [ 'role' => [ 'enabled' => true ], 'hide_nags' => true, 'hide_help' => true, 'block_hidden_screens' => true ],
		'email'     => [ 'from_name_source' => 'business' ],
	];

	/** @var array|null per-request memos */
	private static $stored = null;
	private static $settings = null;
	private static $resolved = null;

	/* ------------------------------------------------------------------ module hooks */

	/** Is the safe-mode constant set in wp-config.php? Every branded surface is then skipped. */
	public static function safe(): bool {
		return defined( 'LB_BRANDING_SAFE_MODE' ) && LB_BRANDING_SAFE_MODE;
	}

	/** Saved at least once (the Branding screen, set-branding or the site builder step). */
	public static function configured(): bool {
		return ! empty( self::stored()['configured_at'] );
	}

	/** Do branded surfaces run? (Module on, not in safe mode, configured.) */
	public static function active(): bool {
		return ! self::safe() && self::configured();
	}

	/**
	 * Module hook. Registers hooks only; nothing reads the settings here. Anonymous front-end requests register a few
	 * hooks that stay silent until they fire.
	 */
	public static function boot(): void {
		if ( self::safe() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', [ __CLASS__, 'safe_notice' ] );
			}
			return;
		}
		add_action( 'login_init', [ Branding_Chrome::class, 'login' ] );
		add_filter( 'wp_mail_from_name', [ Branding_Chrome::class, 'mail_from_name' ], 20 );
		add_filter( 'wp_mail_from', [ Branding_Chrome::class, 'mail_from' ], 20, 1 );
		add_action( 'admin_bar_init', [ Branding_Chrome::class, 'admin_bar_init' ] );
		add_filter( 'pre_update_option_' . self::OPT, [ __CLASS__, 'guard_write' ] );
		add_filter( 'kadence_blocks_ai_disabled', [ Branding_Client::class, 'kadence_ai_off' ] ); // Read only when Kadence asks.
		Migrate::register_step( 'branding-role', [ Branding_Client::class, 'migrate_role' ], [ 'every_version' => true ] );
		Migrate::register_step( 'branding-colors-css', [ Branding_Colors::class, 'migrate_css' ], [ 'every_version' => true ] );
		if ( is_admin() ) {
			Branding_Admin::boot();
			Branding_Client::boot();
			Branding_Chrome::admin();
			Branding_Colors::boot();
		}
	}

	public static function safe_notice(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Branding is switched off by LB_BRANDING_SAFE_MODE in wp-config.php. Remove the constant to bring the branded screens back.', 'launch-builder' ) . '</p></div>';
		}
	}

	/** Whatever writes the option (the screen, set-branding, set-option, a restore), what is stored is always valid. */
	public static function guard_write( $value ) {
		if ( is_string( $value ) ) {
			$d = json_decode( $value, true );
			$value = is_array( $d ) ? $d : [];
		}
		return is_array( $value ) ? self::sanitize_stored( $value ) : [];
	}

	/** Forget the per-request memos (after the settings, the palette or Business Info changed in this request). */
	public static function reset_cache(): void {
		self::$stored = null;
		self::$settings = null;
		self::$resolved = null;
		Branding_Client::forget();
	}

	/* ------------------------------------------------------------------ settings */

	/** The stored overrides, validated (anything invalid is dropped, so a hand-edited option can never break a surface). */
	public static function stored(): array {
		if ( null === self::$stored ) {
			$raw = get_option( self::OPT, [] );
			if ( is_string( $raw ) ) {
				$d = json_decode( $raw, true );
				$raw = is_array( $d ) ? $d : [];
			}
			self::$stored = is_array( $raw ) ? self::sanitize_stored( $raw ) : [];
		}
		return self::$stored;
	}

	/** Effective settings: the defaults with the stored overrides on top. */
	public static function settings(): array {
		if ( null === self::$settings ) {
			self::$settings = self::overlay( self::DEFAULTS, self::stored() );
		}
		return self::$settings;
	}

	/** One section of settings() (login, admin...). */
	public static function section( string $name ): array {
		return (array) ( self::settings()[ $name ] ?? [] );
	}

	private static function overlay( array $defaults, array $stored ): array {
		$out = [];
		foreach ( $defaults as $k => $d ) {
			if ( array_key_exists( $k, $stored ) ) {
				$out[ $k ] = is_array( $d ) && $d && self::is_assoc( $d ) && is_array( $stored[ $k ] ) ? self::overlay( $d, $stored[ $k ] ) : $stored[ $k ];
			} else {
				$out[ $k ] = $d;
			}
		}
		return $out;
	}

	private static function is_assoc( array $a ): bool {
		return $a && array_keys( $a ) !== range( 0, count( $a ) - 1 );
	}

	/** Validate a raw stored value leniently: invalid parts are dropped, the version and configured stamp are kept. */
	public static function sanitize_stored( array $raw ): array {
		$errors = [];
		$warnings = [];
		$out = self::clean_tree( self::SCHEMA, $raw, '', $errors, $warnings, false );
		$out = self::prune( $out, self::DEFAULTS );
		if ( ! empty( $raw['configured_at'] ) && is_string( $raw['configured_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}T[\d:+Z.-]{6,32}$/', $raw['configured_at'] ) ) {
			$out['configured_at'] = $raw['configured_at'];
		}
		$out['v'] = self::VERSION;
		return $out;
	}

	/** Drop values that equal their default, and groups left empty. */
	private static function prune( array $v, array $defaults ): array {
		foreach ( $v as $k => $x ) {
			if ( ! array_key_exists( $k, $defaults ) ) {
				continue;
			}
			$d = $defaults[ $k ];
			if ( is_array( $x ) && is_array( $d ) && $d && self::is_assoc( $d ) ) {
				$v[ $k ] = self::prune( $x, $d );
				if ( ! $v[ $k ] ) {
					unset( $v[ $k ] );
				}
			} elseif ( $x === $d ) {
				unset( $v[ $k ] );
			}
		}
		return $v;
	}

	/* ------------------------------------------------------------------ validation */

	private static function is_leaf( $def ): bool {
		return is_array( $def ) && isset( $def[0] ) && is_string( $def[0] );
	}

	/**
	 * Walk $input against a schema group. Strict: unknown keys and invalid values are errors. Lenient: they are skipped.
	 * Returns only what was given (partial input stays partial).
	 */
	private static function clean_tree( array $schema, $input, string $path, array &$errors, array &$warnings, bool $strict ): array {
		$out = [];
		if ( ! is_array( $input ) ) {
			if ( $strict ) {
				$errors[] = ( '' === $path ? 'The input' : $path ) . ' must be an object.';
			}
			return $out;
		}
		foreach ( $input as $k => $v ) {
			$p = '' === $path ? (string) $k : $path . '.' . $k;
			if ( ! is_string( $k ) || ! isset( $schema[ $k ] ) ) {
				if ( $strict && ! ( '' === $path && in_array( $k, [ 'v', 'configured_at' ], true ) ) ) {
					$errors[] = 'Unknown setting ' . $p . '.';
				}
				continue;
			}
			$def = $schema[ $k ];
			if ( self::is_leaf( $def ) ) {
				$before = count( $errors );
				$r = self::clean_leaf( $def, $v, $p, $errors, $warnings, $strict );
				if ( $r[0] ) {
					$out[ $k ] = $r[1];
				} elseif ( ! $strict && count( $errors ) > $before ) {
					array_splice( $errors, $before ); // Lenient: the message is not wanted.
				}
			} else {
				$sub = self::clean_tree( $def, $v, $p, $errors, $warnings, $strict );
				if ( $sub ) {
					$out[ $k ] = $sub;
				}
			}
		}
		return $out;
	}

	/** @return array{0:bool,1:mixed} ok, cleaned value */
	private static function clean_leaf( array $def, $v, string $p, array &$errors, array &$warnings, bool $strict ): array {
		$bad = static function ( string $why ) use ( &$errors, $p ): array {
			$errors[] = $p . ': ' . $why;
			return [ false, null ];
		};
		$type = $def[0];
		if ( in_array( $type, [ 'text', 'name', 'url', 'email', 'color', 'hex', 'greeting', 'kses', 'enum' ], true ) && ! is_string( $v ) ) {
			return $bad( 'must be a string.' );
		}
		switch ( $type ) {
			case 'bool':
				if ( is_bool( $v ) ) {
					return [ true, $v ];
				}
				if ( in_array( $v, [ 1, '1', 'true', 'on' ], true ) ) {
					return [ true, true ];
				}
				if ( in_array( $v, [ 0, '0', 'false', 'off', '' ], true ) ) {
					return [ true, false ];
				}
				return $bad( 'must be true or false.' );

			case 'text':
			case 'name':
				$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
				if ( 'name' === $type ) {
					$s = trim( str_replace( [ '"', "'", '`', '\\', '<', '>', '&', "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}" ], '', $s ) );
				}
				if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) > (int) $def[1] : strlen( $s ) > (int) $def[1] ) {
					return $bad( 'is longer than ' . (int) $def[1] . ' characters.' );
				}
				return [ true, $s ];

			case 'url':
				$s = trim( (string) $v );
				if ( '' === $s ) {
					return [ true, '' ];
				}
				$u = esc_url_raw( $s, [ 'http', 'https' ] );
				if ( '' === $u || strlen( $u ) > 2048 ) {
					return $bad( 'must be an http or https address.' );
				}
				return [ true, $u ];

			case 'email':
				$s = trim( (string) $v );
				if ( '' === $s ) {
					return [ true, '' ];
				}
				$e = sanitize_email( $s );
				if ( '' === $e || ! is_email( $e ) || preg_match( '/[\r\n]/', $s ) ) {
					return $bad( 'must be an email address.' );
				}
				return [ true, $e ];

			case 'att':
				if ( '' === $v || null === $v || 0 === $v || '0' === $v ) {
					return [ true, 0 ];
				}
				if ( ! is_numeric( $v ) || (int) $v < 1 ) {
					return $bad( 'must be the id of an image in the media library (or 0 to clear).' );
				}
				$id = (int) $v;
				if ( ! $strict ) {
					return [ true, $id ]; // Stored values are not looked up (this runs at plugin load); use sites check them when they are used.
				}
				if ( ! wp_attachment_is( 'image', $id ) ) {
					return $bad( 'attachment ' . $id . ' is not an image in the media library.' );
				}
				return [ true, $id ];

			case 'color':
				$s = trim( (string) $v );
				if ( '' === $s ) {
					return [ true, '' ];
				}
				if ( preg_match( '/^palette(10|[1-9])$/i', $s ) ) {
					return [ true, strtolower( $s ) ];
				}
				$hex = Color::hex( $s );
				if ( ! $hex || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $s ) ) {
					return $bad( 'must be a #hex colour or a palette slug such as palette1.' );
				}
				return [ true, $hex ];

			case 'hex':
				$s = trim( (string) $v );
				if ( '' === $s ) {
					return [ true, '' ];
				}
				if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $s ) ) {
					return $bad( 'must be a #RRGGBB colour (for example #1E1E1E), or empty for the WordPress default.' );
				}
				return [ true, (string) Color::hex( $s ) ];

			case 'int':
				if ( is_float( $v ) && floor( $v ) === $v ) {
					$v = (int) $v;
				}
				if ( is_string( $v ) && preg_match( '/^-?\d{1,9}$/', trim( $v ) ) ) {
					$v = (int) trim( $v );
				}
				if ( ! is_int( $v ) ) {
					return $bad( 'must be a whole number.' );
				}
				$n = $v;
				if ( $n < (int) $def[1] || $n > (int) $def[2] ) {
					return $bad( 'must be between ' . (int) $def[1] . ' and ' . (int) $def[2] . '.' );
				}
				return [ true, $n ];

			case 'enum':
				if ( ! in_array( $v, $def[1], true ) ) {
					return $bad( 'must be one of: ' . implode( ', ', $def[1] ) . '.' );
				}
				return [ true, $v ];

			case 'greeting':
				$s = trim( wp_strip_all_tags( (string) $v ) );
				if ( '' === $s ) {
					return [ true, '' ];
				}
				if ( strlen( $s ) > 40 || 1 !== substr_count( $s, '%s' ) || 1 !== substr_count( $s, '%' ) ) {
					return $bad( 'must be at most 40 characters and contain %s once (for the name), for example "Hi, %s".' );
				}
				return [ true, $s ];

			case 'kses':
				$s = trim( (string) $v );
				if ( strlen( $s ) > (int) $def[1] ) {
					return $bad( 'is longer than ' . (int) $def[1] . ' bytes.' );
				}
				$clean = wp_kses( $s, Branding_Client::MESSAGE_TAGS, [ 'http', 'https', 'mailto', 'tel' ] );
				if ( trim( $clean ) !== $s ) {
					$warnings[] = $p . ': markup that is not allowed was removed.';
				}
				return [ true, trim( $clean ) ];

			case 'list':
				return self::clean_list( (string) $def[1], $v, $p, $errors, $strict );

			case 'roles':
				return self::clean_roles( $v, $p, $errors, $strict );
		}
		return $bad( 'has an unknown type.' );
	}

	/** @return array{0:bool,1:mixed} */
	private static function clean_list( string $kind, $v, string $p, array &$errors, bool $strict = true ): array {
		if ( ! is_array( $v ) ) {
			$errors[] = $p . ': must be a list.';
			return [ false, null ];
		}
		$out = [];
		foreach ( $v as $item ) {
			if ( ! is_string( $item ) ) {
				$errors[] = $p . ': every entry must be a string.';
				return [ false, null ];
			}
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			switch ( $kind ) {
				case 'tile':
					$ok = in_array( $item, self::TILES, true );
					break;
				case 'role':
					$ok = (bool) preg_match( '/^[a-z0-9_-]{1,60}$/', $item ) && ( ! $strict || get_role( $item ) );
					break;
				case 'widget_admin':
					$ok = (bool) preg_match( '/^[a-z0-9_-]{1,60}$/i', $item ) && ! in_array( $item, self::KEEP_WIDGETS, true );
					break;
				case 'widget':
					$ok = (bool) preg_match( '/^[a-z0-9_-]{1,60}$/i', $item );
					break;
				case 'menu':
					$ok = (bool) preg_match( '/^[A-Za-z0-9_.?=&\/%-]{1,120}$/', $item );
					break;
				case 'node':
					$ok = (bool) preg_match( '/^[A-Za-z0-9_-]{1,60}$/', $item );
					break;
				default:
					$ok = false;
			}
			if ( ! $ok ) {
				$errors[] = $p . ': "' . $item . '" is not allowed here' . ( 'widget_admin' === $kind && in_array( $item, self::KEEP_WIDGETS, true ) ? ' (Site Health and the PHP notice are never removed)' : '' ) . '.';
				return [ false, null ];
			}
			$out[] = $item;
		}
		return [ true, array_values( array_unique( $out ) ) ];
	}

	/** client.roles: role => { menus[], admin_bar[], widgets[] } (null removes a role's profile). At most 5; never administrator. */
	private static function clean_roles( $v, string $p, array &$errors, bool $strict = true ): array {
		if ( ! is_array( $v ) || ( $v && ! self::is_assoc( $v ) ) ) {
			$errors[] = $p . ': must be an object keyed by role.';
			return [ false, null ];
		}
		$out = [];
		foreach ( $v as $role => $profile ) {
			$role = (string) $role;
			if ( 'administrator' === $role ) {
				$errors[] = $p . ': administrator can never be simplified.';
				return [ false, null ];
			}
			if ( ! preg_match( '/^[a-z0-9_-]{1,60}$/', $role ) || ( $strict && ! get_role( $role ) && self::ROLE !== $role ) ) {
				$errors[] = $p . '.' . $role . ': no such role.';
				return [ false, null ];
			}
			if ( null === $profile ) {
				$out[ $role ] = null;
				continue;
			}
			if ( ! is_array( $profile ) ) {
				$errors[] = $p . '.' . $role . ': must be an object with menus, admin_bar and widgets.';
				return [ false, null ];
			}
			$prof = [];
			foreach ( $profile as $k => $list ) {
				$kinds = [ 'menus' => 'menu', 'admin_bar' => 'node', 'widgets' => 'widget' ];
				if ( ! isset( $kinds[ $k ] ) ) {
					$errors[] = $p . '.' . $role . ': unknown key ' . $k . '.';
					return [ false, null ];
				}
				$r = self::clean_list( $kinds[ $k ], $list, $p . '.' . $role . '.' . $k, $errors, $strict );
				if ( ! $r[0] ) {
					return [ false, null ];
				}
				$prof[ $k ] = $r[1];
			}
			$out[ $role ] = $prof;
		}
		if ( count( array_filter( $out, static function ( $x ) { return null !== $x; } ) ) > 5 ) {
			$errors[] = $p . ': at most 5 roles.';
			return [ false, null ];
		}
		return [ true, $out ];
	}

	/* ------------------------------------------------------------------ writing */

	/** Dot paths => value of a nested array (lists count as one value). */
	private static function flatten( array $a, string $prefix = '' ): array {
		$out = [];
		foreach ( $a as $k => $v ) {
			$p = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) && $v && self::is_assoc( $v ) ) {
				$out += self::flatten( $v, $p );
			} else {
				$out[ $p ] = $v;
			}
		}
		return $out;
	}

	private static function merge( array $base, array $in ): array {
		foreach ( $in as $k => $v ) {
			if ( null === $v ) {
				unset( $base[ $k ] );
			} elseif ( is_array( $v ) && $v && self::is_assoc( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/** The agency preset: the agency name and website the plugin ships with (nothing is invented). */
	public static function agency_preset(): array {
		$a = self::agency_base();
		return [
			'agency' => [ 'name' => $a['name'], 'url' => $a['url'] ],
		];
	}

	/**
	 * Validate and store settings. $in is a (partial) nested settings object; $opts: dry_run, reset (section names),
	 * apply_agency_preset, apply_recommended, import (the settings came from a file: a scheme it names never refills colours), source (tool|screen). Snapshots first. Returns the report or a WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public static function apply( array $in, array $opts = [] ) {
		$errors = [];
		$warnings = [];
		$cur = self::stored();
		$new = $cur;
		$reset = array_values( array_filter( array_map( 'strval', (array) ( $opts['reset'] ?? [] ) ) ) );
		foreach ( $reset as $sec ) {
			if ( ! isset( self::SCHEMA[ $sec ] ) ) {
				$errors[] = 'reset: unknown section ' . $sec . ' (use ' . implode( ', ', array_keys( self::SCHEMA ) ) . ').';
			}
		}
		if ( $errors ) {
			return new \WP_Error( 'lb_invalid_args', implode( ' ', $errors ), [ 'status' => 400 ] );
		}
		foreach ( $reset as $sec ) {
			unset( $new[ $sec ] );
		}
		$explicit = self::clean_tree( self::SCHEMA, $in, '', $errors, $warnings, true );
		if ( $errors ) {
			return new \WP_Error( 'lb_invalid_args', implode( ' ', $errors ), [ 'status' => 400, 'errors' => $errors ] );
		}
		$clean = [];
		if ( ! empty( $opts['apply_agency_preset'] ) ) {
			$clean = self::merge( $clean, self::agency_preset() );
		}
		if ( ! empty( $opts['apply_recommended'] ) ) {
			$clean = self::merge( $clean, self::RECOMMENDED );
		}
		$clean = self::merge( $clean, $explicit );
		// A ready-made scheme fills the colours that this call did not set itself (not for an import: it carries every colour it has, and an empty one stays empty).
		$pick = (string) ( $explicit['admin_colors']['scheme'] ?? '' );
		if ( '' !== $pick && null !== Branding_Colors::scheme( $pick ) && empty( $opts['import'] ) ) {
			foreach ( Branding_Colors::scheme( $pick ) as $k => $hex ) {
				if ( ! isset( $explicit['admin_colors'][ $k ] ) ) {
					$clean['admin_colors'][ $k ] = $hex;
				}
			}
		}
		$new = self::merge( $new, $clean );
		// Colours set without a scheme name: the stored name is kept only while the colours still match that scheme exactly, so a stale name can never refill a colour that was emptied.
		$set_colors = array_intersect_key( (array) ( $explicit['admin_colors'] ?? [] ), array_flip( Branding_Colors::KEYS ) );
		if ( $set_colors && ! isset( $explicit['admin_colors']['scheme'] ) ) {
			$new['admin_colors']['scheme'] = Branding_Colors::matching_scheme( Branding_Colors::colors( (array) ( $new['admin_colors'] ?? [] ) ) );
		}
		$new = self::prune( $new, self::DEFAULTS );
		$new['v'] = self::VERSION;
		$was_configured = self::configured();
		$new['configured_at'] = $cur['configured_at'] ?? gmdate( 'c' );

		$old_eff = self::overlay( self::DEFAULTS, $cur );
		$new_eff = self::overlay( self::DEFAULTS, $new );
		$changed = [];
		$a = self::flatten( $old_eff );
		$b = self::flatten( $new_eff );
		foreach ( $b + $a as $path => $_ ) {
			if ( ( $a[ $path ] ?? null ) !== ( $b[ $path ] ?? null ) ) {
				$changed[] = $path;
			}
		}
		$warnings = array_merge( $warnings, self::setting_warnings( $new_eff ) );
		$role_will_sync = ! empty( $new_eff['client']['role']['enabled'] ) && ( ! $was_configured || array_filter( $changed, static function ( $p ) { return 0 === strpos( $p, 'client.role.' ); } ) );

		if ( ! empty( $opts['dry_run'] ) ) {
			return [ 'dry_run' => true, 'changed' => $changed, 'warnings' => $warnings, 'would_mark_configured' => ! $was_configured, 'would_sync_role' => (bool) $role_will_sync ];
		}

		$snap_opts = [ self::OPT ];
		if ( $role_will_sync ) {
			$snap_opts[] = wp_roles()->role_key;
		}
		$snapshot = Abilities::snapshot( 'set-branding', [], $snap_opts );
		update_option( self::OPT, $new, false );
		self::reset_cache();

		$out = [ 'changed' => $changed, 'snapshot' => $snapshot, 'configured' => true, 'warnings' => $warnings ];
		if ( $role_will_sync ) {
			$out['role_changes'] = Branding_Client::sync_role( $old_eff, $new_eff, ! $was_configured );
		}
		if ( array_filter( $changed, static function ( $p ) { return 0 === strpos( $p, 'admin_colors.' ); } ) ) {
			Branding_Colors::refresh_cache(); // Compiled here, so page views never write.
		}
		if ( array_filter( $changed, static function ( $p ) { return 0 === strpos( $p, 'login.' ) || 0 === strpos( $p, 'admin.' ); } ) ) {
			Cache::purge_later();
		}
		$out['preview'] = self::preview_values();
		return $out;
	}

	/** Non-fatal observations about a settings set (contrast, email domain...). @return string[] */
	public static function setting_warnings( array $eff ): array {
		$w = [];
		foreach ( Branding_Chrome::login_vars( $eff['login'] )['warnings'] as $msg ) {
			$w[] = $msg;
		}
		$from = (string) $eff['email']['from_email'];
		if ( '' !== $from && ! Branding_Chrome::same_domain( $from ) ) {
			$w[] = sprintf( /* translators: 1: sender address, 2: site domain */ __( 'The sender address %1$s is not on this site\'s domain (%2$s), so it is not used (mail from another domain often fails SPF and DMARC checks).', 'launch-builder' ), $from, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		if ( 'client' === $eff['login']['logo_source'] && $eff['login']['enabled'] && ! self::client_logo_id() ) {
			$w[] = __( 'The sign-in screen uses the client logo, but the site has no logo yet (set one with set-brand logo_attachment_id or in the Customizer). The business name is shown instead.', 'launch-builder' );
		}
		if ( Branding_Colors::customised( (array) $eff['admin_colors'] ) ) {
			foreach ( Branding_Colors::resolve( (array) $eff['admin_colors'] )['notes'] as $msg ) {
				$w[] = $msg;
			}
		}
		return $w;
	}

	/* ------------------------------------------------------------------ resolved values */

	private static function agency_base(): array {
		$base = self::AGENCY;
		if ( defined( 'LB_AGENCY' ) && is_string( LB_AGENCY ) ) {
			$d = json_decode( LB_AGENCY, true );
			if ( is_array( $d ) ) {
				foreach ( [ 'name', 'short', 'url' ] as $k ) {
					if ( isset( $d[ $k ] ) && is_string( $d[ $k ] ) && '' !== trim( $d[ $k ] ) ) {
						$base[ $k ] = trim( wp_strip_all_tags( $d[ $k ] ) );
					}
				}
			}
		}
		$base = (array) apply_filters( 'launch_builder_agency', $base );
		$base['name'] = (string) ( $base['name'] ?? self::AGENCY['name'] );
		$base['short'] = (string) ( $base['short'] ?? $base['name'] );
		$base['url'] = (string) ( $base['url'] ?? self::AGENCY['url'] );
		return $base;
	}

	/**
	 * The agency: name, url, logo ids and URLs, support contacts. Each saved value overrides the shipped default.
	 *
	 * @return array<string,mixed>
	 */
	public static function agency(): array {
		$base = self::agency_base();
		$s = self::section( 'agency' );
		$name = '' !== $s['name'] ? $s['name'] : $base['name'];
		$short = '' !== $s['name'] ? $s['name'] : $base['short'];
		return [
			'name'          => $name,
			'short'         => $short,
			'url'           => '' !== $s['url'] ? $s['url'] : $base['url'],
			'logo_id'       => self::usable_attachment( (int) $s['logo'] ),
			'logo_square_id' => self::usable_attachment( (int) $s['logo_square'] ),
			'support_email' => (string) $s['support_email'],
			'support_phone' => (string) $s['support_phone'],
			'support_url'   => (string) $s['support_url'],
			'booking_url'   => (string) $s['booking_url'],
			'source'        => [ 'name' => '' !== $s['name'] ? 'override' : 'agency_default', 'url' => '' !== $s['url'] ? 'override' : 'agency_default' ],
		];
	}

	/** The attachment id when it still exists as an image, else 0. */
	public static function usable_attachment( int $id ): int {
		return $id > 0 && wp_attachment_is( 'image', $id ) ? $id : 0;
	}

	/** The attachment's URL for a size ('' when missing). Quotes and parentheses are encoded so it is safe inside CSS url(). */
	public static function attachment_url( int $id, string $size = 'full' ): string {
		if ( $id < 1 ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $id, $size );
		$url = $src ? (string) $src[0] : (string) wp_get_attachment_url( $id );
		return '' === $url ? '' : esc_url_raw( $url );
	}

	/** URL made safe for url("...") in CSS and for single-quoted JavaScript strings. */
	public static function css_url( string $url ): string {
		return str_replace( [ '"', "'", '(', ')', '\\', "\n", "\r", '<', '>' ], [ '%22', '%27', '%28', '%29', '%5C', '', '', '%3C', '%3E' ], $url );
	}

	/** The client's business name and where it comes from (business_info: typed in Business Info; site_title). @return array{0:string,1:string} */
	public static function business_name(): array {
		$res = Company::resolve();
		if ( isset( $res['name'] ) && '' !== $res['name']['value'] && 'saved' === $res['name']['from'] ) {
			return [ $res['name']['value'], 'business_info' ];
		}
		return [ wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), 'site_title' ];
	}

	/**
	 * Save the image for the logo above the admin menu without switching anything on. Works whether or not this module is
	 * on.
	 */
	public static function store_menu_logo_image( int $id ): bool {
		$id = self::usable_attachment( $id );
		if ( $id < 1 ) {
			return false;
		}
		$raw = get_option( self::OPT, [] );
		if ( is_string( $raw ) ) {
			$d = json_decode( $raw, true );
			$raw = is_array( $d ) ? $d : [];
		}
		$raw = is_array( $raw ) ? $raw : [];
		$raw['admin'] = is_array( $raw['admin'] ?? null ) ? $raw['admin'] : [];
		$raw['admin']['menu_logo_image'] = $id;
		update_option( self::OPT, self::sanitize_stored( $raw ), false );
		self::reset_cache();
		return true;
	}

	/** The client logo: the theme's custom logo (written by set-brand). */
	public static function client_logo_id(): int {
		return self::usable_attachment( (int) get_theme_mod( 'custom_logo' ) );
	}

	/** The core site icon (Business Info reads it from here too). */
	public static function site_icon_id(): int {
		return self::usable_attachment( (int) get_option( 'site_icon' ) );
	}

	/**
	 * Every branded value with where it came from: { key: { value, source } } (source: business_info | site_title |
	 * palette | theme_mod | agency_default | override).
	 */
	public static function resolve(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$agency = self::agency();
		[ $bn, $bn_src ] = self::business_name();
		$logo_id = self::client_logo_id();
		$vars = Branding_Chrome::login_vars( self::section( 'login' ) );
		$out = [
			'business_name'  => [ 'value' => $bn, 'source' => $bn_src ],
			'client_logo'    => [ 'value' => [ 'id' => $logo_id, 'url' => self::attachment_url( $logo_id, 'medium' ) ], 'source' => $logo_id ? 'theme_mod' : 'none' ],
			'site_icon'      => [ 'value' => [ 'id' => self::site_icon_id() ], 'source' => self::site_icon_id() ? 'theme_mod' : 'none' ],
			'agency_name'    => [ 'value' => $agency['name'], 'source' => $agency['source']['name'] ],
			'agency_url'     => [ 'value' => $agency['url'], 'source' => $agency['source']['url'] ],
			'agency_logo'    => [ 'value' => [ 'id' => $agency['logo_id'], 'url' => self::attachment_url( $agency['logo_id'], 'medium' ) ], 'source' => $agency['logo_id'] ? 'override' : 'none' ],
		];
		foreach ( $vars['sources'] as $k => $src ) {
			$out[ 'login_' . $k ] = [ 'value' => $vars['colors'][ $k ], 'source' => $src ];
		}
		return self::$resolved = $out;
	}

	/** A short resolved summary for tool results. */
	public static function preview_values(): array {
		$r = self::resolve();
		$out = [];
		foreach ( [ 'business_name', 'agency_name', 'login_bg', 'login_form_bg', 'login_text', 'login_button_bg', 'login_button_text', 'login_button_hover_bg', 'login_link' ] as $k ) {
			if ( isset( $r[ $k ] ) ) {
				$out[ $k ] = $r[ $k ]['value'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ tools */

	/** JSON Schema (every property typed) for a SCHEMA group. */
	private static function json_group( array $group ): array {
		$props = [];
		foreach ( $group as $k => $def ) {
			if ( self::is_leaf( $def ) ) {
				$props[ $k ] = self::json_leaf( $def, (string) $k );
			} else {
				$props[ $k ] = [ 'type' => 'object', 'properties' => self::json_group( $def ) ];
			}
		}
		return $props;
	}

	private static function json_leaf( array $def, string $key ): array {
		switch ( $def[0] ) {
			case 'bool':
				return [ 'type' => 'boolean' ];
			case 'text':
			case 'name':
			case 'kses':
				return [ 'type' => 'string', 'maxLength' => (int) $def[1] ];
			case 'url':
				return [ 'type' => 'string', 'maxLength' => 2048, 'description' => 'http or https address ("" clears).' ];
			case 'email':
				return [ 'type' => 'string', 'maxLength' => 254, 'description' => 'Email address ("" clears).' ];
			case 'att':
				return [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Media library image id (0 clears). Import an image first with sideload-media.' ];
			case 'color':
				return [ 'type' => 'string', 'maxLength' => 9, 'description' => '#hex colour or palette slug (palette1..palette10). "" = follow the brand palette.' ];
			case 'hex':
				return [ 'type' => 'string', 'maxLength' => 7, 'description' => '#RRGGBB colour ("" = the WordPress default). Text colours are corrected when their contrast is too low.' ];
			case 'int':
				return [ 'type' => 'integer', 'minimum' => (int) $def[1], 'maximum' => (int) $def[2] ];
			case 'enum':
				return [ 'type' => 'string', 'enum' => $def[1] ];
			case 'greeting':
				return [ 'type' => 'string', 'maxLength' => 40, 'description' => 'Contains %s once for the name, e.g. "Hi, %s". "" = the name alone.' ];
			case 'list':
				return [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 120 ], 'maxItems' => 60 ];
			case 'roles':
				return [ 'type' => 'object', 'description' => 'role => { menus: [top-level menu slugs], admin_bar: [node ids], widgets: [dashboard widget ids] }; null removes a role\'s profile. At most 5 roles; administrator is refused.', 'additionalProperties' => [ 'type' => [ 'object', 'null' ], 'properties' => [ 'menus' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 120 ] ], 'admin_bar' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 60 ] ], 'widgets' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 60 ] ] ] ] ];
		}
		return [ 'type' => 'string' ];
	}

	public static function define_tools(): void {
		Abilities::add(
			'get-branding',
			'Read branding settings',
			'The Branding module: stored settings (overrides), the effective settings with defaults, every resolved value with its source (business_info, site_title, palette, theme_mod, agency_default, override), the dashboard colours (who sees them, the final colours, contrast ratios, ready-made schemes), the Site Owner role (exists, user count), contrast ratios and warnings. section narrows the settings. export:true adds a portable export (no secrets, no user data).',
			[ 'properties' => [
				'section' => [ 'type' => 'string', 'enum' => array_merge( [ 'all' ], array_keys( self::SCHEMA ) ) ],
				'export'  => [ 'type' => 'boolean' ],
			] ],
			[ __CLASS__, 'tool_get' ],
			'manage_options',
			[ 'readonly' => true ]
		);
		$props = self::json_group( self::SCHEMA );
		$props += [
			'apply_agency_preset' => [ 'type' => 'boolean', 'description' => 'Fill the agency name and website from the shipped agency identity (explicit values in this call win).' ],
			'apply_recommended'   => [ 'type' => 'boolean', 'description' => 'Switch on the recommended first setup: branded sign-in screen, admin bar logo, Site Owner role, welcome panel, hidden nags for clients, business name as email sender.' ],
			'reset'               => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( self::SCHEMA ) ], 'description' => 'Sections to return to their defaults first.' ],
			'dry_run'             => [ 'type' => 'boolean', 'description' => 'Validate and report what would change without writing anything.' ],
			'import'              => [ 'type' => 'object', 'description' => 'An export object from get-branding (format launch-builder-branding): its settings are validated like any other input; images are kept only when the attachment ids exist on this site.' ],
		];
		Abilities::add(
			'set-branding',
			'Set branding',
			'Change branding settings (partial input: only what you send changes). Validated, snapshotted (restore-snapshot undoes it) and capability-checked. The first call marks the module configured, which is what makes branded surfaces run. Images are media library ids (use sideload-media first); colours are #hex or palette slugs, empty = follow the brand palette. admin_colors sets the Dashboard colours (the sidebar, top bar, links and buttons of wp-admin): scheme picks a ready-made set (brand = the Kadence palette), scope says who sees it (clients = Site Owner accounts, everyone, nobody; an administrator not in the audience can still see them under Users, Profile, Dashboard colours), reset: ["admin_colors"] returns to the WordPress default; text colours with too little contrast are corrected and reported. Never accepts roles for users or custom code. Returns changed paths, warnings, role_changes and the new snapshot id.',
			[ 'properties' => $props ],
			[ __CLASS__, 'tool_set' ],
			'manage_options'
		);
	}

	public static function tool_get( $input = [] ) {
		$input = (array) $input;
		$section = isset( $input['section'] ) ? (string) $input['section'] : 'all';
		if ( 'all' !== $section && ! isset( self::SCHEMA[ $section ] ) ) {
			return new \WP_Error( 'lb_invalid_args', 'section must be all or one of ' . implode( ', ', array_keys( self::SCHEMA ) ) . '.', [ 'status' => 400 ] );
		}
		$stored = self::stored();
		$eff = self::settings();
		if ( 'all' !== $section ) {
			$stored = isset( $stored[ $section ] ) ? [ $section => $stored[ $section ] ] : [];
			$eff = [ $section => $eff[ $section ] ];
		}
		unset( $stored['v'] ); // the storage version is not an override
		$out = [
			'configured'  => self::configured(),
			'configured_at' => (string) ( self::stored()['configured_at'] ?? '' ),
			'safe_mode'   => self::safe(),
			'overrides'   => $stored ? $stored : new \stdClass(),
			'settings'    => $eff,
			'resolved'    => self::resolve(),
			'admin_colors' => Branding_Colors::state(),
			'client_role' => Branding_Client::role_state(),
			'contrast'    => Branding_Chrome::login_vars( self::section( 'login' ) )['contrast'],
			'warnings'    => self::all_warnings(),
		];
		if ( ! empty( $input['export'] ) ) {
			$out['export'] = self::export();
		}
		return $out;
	}

	public static function tool_set( $input = [] ) {
		$input = (array) $input;
		foreach ( [ 'apply_agency_preset', 'apply_recommended', 'dry_run' ] as $k ) {
			if ( isset( $input[ $k ] ) && ! is_bool( $input[ $k ] ) ) {
				return new \WP_Error( 'lb_invalid_args', $k . ' must be true or false.', [ 'status' => 400 ] );
			}
		}
		if ( isset( $input['reset'] ) && ! is_array( $input['reset'] ) ) {
			return new \WP_Error( 'lb_invalid_args', 'reset must be a list of section names.', [ 'status' => 400 ] );
		}
		$opts = [ 'dry_run' => ! empty( $input['dry_run'] ), 'reset' => (array) ( $input['reset'] ?? [] ), 'apply_agency_preset' => ! empty( $input['apply_agency_preset'] ), 'apply_recommended' => ! empty( $input['apply_recommended'] ) ];
		$settings = array_diff_key( $input, array_flip( [ 'apply_agency_preset', 'apply_recommended', 'reset', 'dry_run', 'import' ] ) );
		if ( isset( $input['import'] ) ) {
			$imp = self::import_settings( $input['import'] );
			if ( is_wp_error( $imp ) ) {
				return $imp;
			}
			$settings = self::merge( $imp['settings'], $settings );
			$opts['import'] = ! ( is_array( $input['admin_colors'] ?? null ) && array_key_exists( 'scheme', $input['admin_colors'] ) ); // A scheme named in the file never refills colours.
		}
		if ( ! $settings && ! $opts['reset'] && ! $opts['apply_agency_preset'] && ! $opts['apply_recommended'] ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Send settings (login, admin, client...), apply_agency_preset, apply_recommended, reset or import.', [ 'status' => 400 ] );
		}
		$r = self::apply( $settings, $opts );
		if ( ! is_wp_error( $r ) && isset( $imp ) && $imp['dropped'] ) {
			$r['import_dropped'] = $imp['dropped'];
		}
		return $r;
	}

	/** Every warning the settings, the palette and the site produce. @return string[] */
	public static function all_warnings(): array {
		return array_values( array_unique( self::setting_warnings( self::settings() ) ) );
	}

	/* ------------------------------------------------------------------ export / import */

	/** Portable settings: the stored overrides only, plus the images as {url, filename} so a person can re-upload them. */
	public static function export(): array {
		$stored = self::stored();
		unset( $stored['v'], $stored['configured_at'] );
		$images = [];
		foreach ( self::flatten( $stored ) as $path => $val ) {
			$def = self::def_at( $path );
			if ( $def && 'att' === $def[0] && (int) $val > 0 ) {
				$url = (string) wp_get_attachment_url( (int) $val );
				$images[ $path ] = [ 'url' => $url, 'filename' => '' !== $url ? wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) : '' ];
			}
		}
		return [
			'format'      => 'launch-builder-branding',
			'v'           => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'site_host'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'settings'    => $stored ? $stored : new \stdClass(),
			'images'      => $images ? $images : new \stdClass(),
		];
	}

	/** The schema definition at a dot path ('' when none). */
	private static function def_at( string $path ): ?array {
		$node = self::SCHEMA;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! isset( $node[ $part ] ) ) {
				return null;
			}
			$node = $node[ $part ];
			if ( self::is_leaf( $node ) ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Settings from an export object, with every value dropped that this site cannot take (unknown keys, attachment ids
	 * that are not images here). Valid values are re-validated by apply().
	 *
	 * @return array{settings:array,dropped:string[]}|\WP_Error
	 */
	private static function import_settings( $obj ) {
		if ( ! is_array( $obj ) || 'launch-builder-branding' !== ( $obj['format'] ?? '' ) || ! is_array( $obj['settings'] ?? null ) ) {
			return new \WP_Error( 'lb_invalid_args', 'import must be an export object from get-branding (format launch-builder-branding).', [ 'status' => 400 ] );
		}
		if ( strlen( (string) wp_json_encode( $obj ) ) > 262144 ) {
			return new \WP_Error( 'lb_invalid_args', 'import is larger than 256 KB.', [ 'status' => 400 ] );
		}
		$errors = [];
		$warn = [];
		$dropped = [];
		$settings = [];
		foreach ( self::flatten( (array) $obj['settings'] ) as $path => $val ) {
			$def = self::def_at( $path );
			$part = [];
			$cursor = &$part;
			foreach ( explode( '.', $path ) as $seg ) {
				$cursor[ $seg ] = [];
				$cursor = &$cursor[ $seg ];
			}
			$cursor = $val;
			unset( $cursor );
			$e = [];
			$w = [];
			self::clean_tree( self::SCHEMA, $part, '', $e, $w, true );
			if ( $e ) {
				$dropped[] = $path . ': ' . implode( ' ', $e );
				continue;
			}
			$settings = self::merge( $settings, $part );
		}
		return [ 'settings' => $settings, 'dropped' => $dropped ];
	}

	/* ------------------------------------------------------------------ module hooks: state, playbook, audit, blueprint, brief */

	public static function site_state( array &$state ): void {
		$s = self::settings();
		$state['branding'] = [
			'configured'  => self::configured(),
			'safe_mode'   => self::safe(),
			'surfaces'    => [
				'login'       => self::active() && $s['login']['enabled'],
				'admin_bar'   => self::active() && $s['admin']['replace_wp_logo'],
				'footer'      => self::active() && '' !== $s['admin']['footer_text'],
				'welcome'     => self::active() && $s['dashboard']['welcome']['enabled'],
				'emails'      => self::active() && 'off' !== $s['email']['from_name_source'],
				'admin_colors' => self::active() && ! Branding_Colors::off() && 'nobody' !== $s['admin_colors']['scope'] && Branding_Colors::customised( $s['admin_colors'] ),
				'client_role' => self::active() && $s['client']['role']['enabled'],
			],
			'client_role' => Branding_Client::role_state(),
			'smtp_plugin' => Branding_Chrome::smtp_plugin(),
			'warnings'    => self::all_warnings(),
		];
	}

	public static function playbook( array &$pb ): void {
		$pb['tools_by_job']['Branding'] = 'get-branding, set-branding';
		$pb['branding'] = 'Branding (a module, off until switched on under Launch Builder > Modules) white-labels a client hand-off. It runs nothing until set-branding or the Branding screen has saved once. Business Info (name) and the brand palette and logo (set-brand) feed every branded surface, so set those first; branding stores only overrides. Typical order: set-branding { apply_recommended: true } (branded sign-in screen, admin bar logo, Site Owner role, welcome panel, hidden nags for clients, business name as email sender), then agency support contacts (agency.support_email, support_phone, support_url) and a welcome message. Give clients the Site Owner role (a person assigns it; there is no tool for that), never administrator. Dashboard colours: set-branding { admin_colors: { scheme: "brand" } } starts from the Kadence palette (or midnight, forest, daylight, ember, plum, slate), scope clients (default) shows it to Site Owner accounts only (an administrator can preview it under Users, Profile, Dashboard colours), and reset: ["admin_colors"] puts the WordPress default back. Read get-branding warnings for contrast and email-domain notes. LB_BRANDING_SAFE_MODE in wp-config.php turns every branded surface off; LB_ADMIN_COLORS_OFF turns off only the dashboard colours.';
	}

	/** @return array code => [scope, callable] */
	public static function audit_checks(): array {
		return [
			'branding_login_contrast'  => [ 'branding', [ __CLASS__, 'audit_contrast' ] ],
			'branding_email_domain'    => [ 'branding', [ __CLASS__, 'audit_email' ] ],
			'branding_no_logo'         => [ 'branding', [ __CLASS__, 'audit_logo' ] ],
			'branding_admin_colors'    => [ 'branding', [ __CLASS__, 'audit_admin_colors' ] ],
			'branding_safe_mode'       => [ 'branding', [ __CLASS__, 'audit_safe' ] ],
		];
	}

	private static function finding( string $code, string $severity, string $message, string $fix ): array {
		return [ 'severity' => $severity, 'code' => $code, 'object_type' => 'site', 'id' => 0, 'url' => admin_url( 'admin.php?page=' . self::PAGE ), 'message' => $message, 'fix' => $fix ];
	}

	public static function audit_contrast(): array {
		$out = [];
		if ( self::active() && self::section( 'login' )['enabled'] ) {
			foreach ( Branding_Chrome::login_vars( self::section( 'login' ) )['warnings'] as $msg ) {
				$out[] = self::finding( 'branding_login_contrast', 'warning', $msg, 'Pick colours with more contrast on the Branding screen (Login screen), or leave them empty to follow the brand palette.' );
			}
		}
		return $out;
	}

	public static function audit_email(): array {
		$s = self::section( 'email' );
		if ( self::active() && '' !== $s['from_email'] && ! Branding_Chrome::same_domain( (string) $s['from_email'] ) ) {
			return [ self::finding( 'branding_email_domain', 'warning', 'The sender address ' . $s['from_email'] . ' is not on this site\'s domain, so it is not used.', 'Use an address on the site\'s own domain, or clear it.' ) ];
		}
		return [];
	}

	public static function audit_logo(): array {
		$l = self::section( 'login' );
		if ( self::active() && $l['enabled'] && 'client' === $l['logo_source'] && ! self::client_logo_id() ) {
			return [ self::finding( 'branding_no_logo', 'info', 'The branded sign-in screen has no logo: the site has no custom logo.', 'Set the site logo (set-brand logo_attachment_id) or choose another logo source.' ) ];
		}
		return [];
	}

	/** The dashboard colours: anything the contrast guard had to correct or warn about, while the colours are in use. */
	public static function audit_admin_colors(): array {
		$c = (array) self::section( 'admin_colors' );
		$out = [];
		if ( self::active() && ! Branding_Colors::off() && 'nobody' !== $c['scope'] && Branding_Colors::customised( $c ) ) {
			foreach ( Branding_Colors::resolve( $c )['notes'] as $msg ) {
				$out[] = self::finding( 'branding_admin_colors', 'warning', $msg, 'Pick other colours in the Dashboard colours card on the Branding screen, or reset them to the WordPress default.' );
			}
		}
		return $out;
	}

	public static function audit_safe(): array {
		return self::safe() ? [ self::finding( 'branding_safe_mode', 'info', 'LB_BRANDING_SAFE_MODE is set: every branded surface is switched off.', 'Remove the constant from wp-config.php to bring branding back.' ) ] : [];
	}

	/** Build-site step: applies the preset and the recommended setup when the blueprint carries a branding section. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'branding', 'at' => 'done', 'run' => [ __CLASS__, 'blueprint_step' ] ] ];
	}

	public static function blueprint_step( array $bp, array $ctx = [] ) {
		$b = isset( $bp['branding'] ) && is_array( $bp['branding'] ) ? $bp['branding'] : null;
		if ( null === $b ) {
			return [ 'skipped' => 'no branding section in the blueprint' ];
		}
		$in = [];
		$support = (array) ( $b['support'] ?? [] );
		$map = [ 'email' => 'support_email', 'phone' => 'support_phone', 'url' => 'support_url', 'booking_url' => 'booking_url' ];
		foreach ( $map as $from => $to ) {
			if ( isset( $support[ $from ] ) && is_string( $support[ $from ] ) ) {
				$in['agency'][ $to ] = $support[ $from ];
			}
		}
		if ( isset( $b['welcome_message'] ) && is_string( $b['welcome_message'] ) ) {
			$in['dashboard']['welcome']['message'] = $b['welcome_message'];
		}
		if ( isset( $b['email_from_name'] ) && in_array( $b['email_from_name'], [ 'business', 'agency' ], true ) ) {
			$in['email']['from_name_source'] = $b['email_from_name'];
		}
		if ( array_key_exists( 'client_role', $b ) ) {
			$in['client']['role']['enabled'] = (bool) $b['client_role'];
		}
		if ( isset( $b['admin_colors'] ) && is_array( $b['admin_colors'] ) ) {
			$in['admin_colors'] = $b['admin_colors'];
		}
		// The agency preset fills a site that has not been configured; a configured site keeps its own names unless the brief says so.
		$preset = array_key_exists( 'agency_preset', $b ) ? (bool) $b['agency_preset'] : ! self::configured();
		$r = self::apply( $in, [ 'apply_agency_preset' => $preset, 'apply_recommended' => ! self::configured() ] );
		return is_wp_error( $r ) ? $r : [ 'changed' => $r['changed'], 'snapshot' => $r['snapshot'], 'warnings' => $r['warnings'] ];
	}

	public static function brief_schema( array &$schema ): void {
		$schema['properties']['branding'] = [
			'type'        => 'object',
			'description' => 'Branding of the site, applied by build-from-brief: enabled (true switches the Branding module on, false off), dashboard_colors { scheme, scope } (the colours of the WordPress dashboard), agency_credit (the "Website by" line on the sign-in screen), agency { name, url } and site_owner_role (true, false or { label }: a simpler role for the site owner). Business Info and the palette feed the rest. A blueprint for build-site has its own branding step with other keys: see build-site.',
			'properties'  => [
				'enabled'          => [ 'type' => 'boolean', 'description' => 'Switch the Branding module on or off.' ],
				'dashboard_colors' => [
					'type'        => 'object',
					'description' => 'The colours of the WordPress dashboard.',
					'properties'  => [
						'scheme' => [ 'type' => 'string', 'enum' => Branding_Colors::SCHEME_IDS, 'description' => 'A ready-made scheme: brand (the colours of the brand), midnight, forest, daylight, ember, plum or slate. "" = no ready-made scheme (the WordPress default colours).' ],
						'scope'  => [ 'type' => 'string', 'enum' => [ 'clients', 'everyone', 'nobody' ], 'description' => 'Who sees the colours.' ],
					],
				],
				'agency_credit'    => [ 'type' => 'boolean', 'description' => 'Show the "Website by" line on the sign-in screen.' ],
				'agency'           => [
					'type'        => 'object',
					'description' => 'Who built the site.',
					'properties'  => [
						'name' => [ 'type' => 'string' ],
						'url'  => [ 'type' => 'string' ],
					],
				],
				'site_owner_role'  => [ 'type' => [ 'boolean', 'object' ], 'description' => 'true or false, or { label } to name the role.' ],
			],
		];
	}
}
