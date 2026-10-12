<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The opt-in bridge to the Kadence White Label plugin (and the Kadence names this plugin can swap itself).
 *
 * Off by default: while kadence.kwl_mode is "off" this class never reads, filters or writes that plugin's settings.
 * An administrator can switch it to:
 *  - manage:  Kadence White Label is configured virtually. A pre_option filter hands that plugin a JSON string built
 *             from the Branding settings, so its database row is never written and everything reverts the moment the
 *             mode is switched off or the module is. Its own settings screen is hidden (this plugin is then the one
 *             place to edit it), and writes to its option are guarded (below).
 *  - persist: the compiled values are written into its option (snapshotted, read back, recorded in the ownership
 *             ledger) so they stay when Launch Builder is removed. "Restore the previous settings" puts the original
 *             value back.
 * Without that plugin, manage mode falls back to the Kadence surfaces a client sees: the editor's block category and
 * sidebar name, the Blocks menu icon and logo.
 *
 * Rules that keep that plugin safe:
 *  - its option is always a JSON string (an array there is a fatal error on PHP 8, so arrays are re-encoded);
 *  - its on/off switches are written as JSON true (1, "1" and "true" silently turn it off) or the key is left out;
 *  - empty strings are never written (they blank a name, description or picture), the key is left out;
 *  - names carry no quotes or apostrophes (that plugin puts them inside a JavaScript string on the Updates screen);
 *  - image values are media library URLs only.
 * Never touched: its license, KADENCE_WHITE_LABEL_CONTROLS_HIDDEN, the licensing constants.
 */
final class Branding_Kadence {

	const OPTION = 'kadence_white_label';
	const TESTED = '1.0.1';

	/** Every key of that plugin's option that this bridge sets. */
	const MANAGED = [
		'enable_white_label', 'hide_theme_page', 'hide_white_label', 'agency_name', 'agency_url', 'agency_image',
		'theme_url', 'theme_name', 'theme_name_short', 'theme_desc', 'theme_image',
		'blocks_name', 'blocks_desc', 'blocks_image', 'blocks_menu_name', 'blocks_icon', 'blocks_icon_svg',
		'blocks_pro_name', 'blocks_pro_desc', 'blocks_pro_image', 'theme_pro_name', 'theme_pro_desc', 'theme_pro_image',
		'plugin_name', 'plugin_desc', 'plugin_image', 'disable_ai',
	];

	/** Its switches: written as JSON true or left out. */
	const SWITCHES = [ 'enable_white_label', 'hide_theme_page', 'hide_white_label', 'disable_ai' ];

	/** @var bool true while this class reads the raw option (the virtual filter steps aside) */
	private static $bypass = false;

	/** @var string|null per-request memo of the virtual value */
	private static $virtual = null;

	/** Forget the per-request memo (the settings changed in this request). */
	public static function forget(): void {
		self::$virtual = null;
	}

	/* ------------------------------------------------------------------ switches and detection */

	public static function mode(): string {
		return (string) Branding::section( 'kadence' )['kwl_mode'];
	}

	/** Is the Kadence White Label plugin loaded? (Detection by constant and class; settings are not read.) */
	public static function detected(): bool {
		return defined( 'KADENCE_WHITE_VERSION' ) || class_exists( 'KadenceWP\KadenceWhiteLabel\Plugin', false );
	}

	public static function version(): string {
		return defined( 'KADENCE_WHITE_VERSION' ) ? (string) KADENCE_WHITE_VERSION : '';
	}

	/** Can this request show what that plugin does? (Anonymous front-end visitors cannot, so they cost nothing.) */
	private static function sees_effects(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security
		if ( false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' ) || isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		return defined( 'LOGGED_IN_COOKIE' ) ? isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) : true;
	}

	/**
	 * Module boot. Registers the write guard always, and everything else only for requests that can show the effect and
	 * only when an administrator switched the bridge on.
	 */
	public static function boot(): void {
		add_filter( 'pre_update_option_' . self::OPTION, [ __CLASS__, 'guard_write' ], 10, 2 );
		if ( ! self::sees_effects() ) {
			return;
		}
		$mode = self::mode();
		if ( 'manage' === $mode ) {
			add_filter( 'pre_option_' . self::OPTION, [ __CLASS__, 'virtual' ], 10, 1 );
			add_filter( 'kadence_white_label_menu_type', [ __CLASS__, 'menu_hidden' ], 20 );
		}
		if ( 'off' !== $mode && ! self::detected() && Branding::active() ) {
			self::boot_fallback();
		}
		self::boot_gaps(); // Has its own switches; the bridge mode does not matter.
	}

	/* ------------------------------------------------------------------ the option */

	/** The option as the database holds it, whatever the virtual filter says. */
	public static function raw() {
		self::$bypass = true;
		$raw = get_option( self::OPTION, null );
		self::$bypass = false;
		return $raw;
	}

	private static function decode( $raw ): array {
		if ( is_string( $raw ) ) {
			$d = json_decode( $raw, true );
			return is_array( $d ) ? $d : [];
		}
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * The values this bridge sets, from the Branding settings. Names and descriptions come from the product settings
	 * (never the client's business name); images are media library URLs; switches are JSON true; empty values are left out.
	 *
	 * @return array<string,mixed>
	 */
	public static function compile(): array {
		$p = Branding::section( 'product' );
		$k = Branding::section( 'kadence' );
		$agency = Branding::agency();
		$out = [ 'enable_white_label' => true, 'hide_theme_page' => true ];
		$name = static function ( $s, int $max ): string {
			$s = trim( str_replace( [ '"', "'", '`', '\\', '<', '>', '&' ], '', wp_strip_all_tags( (string) $s ) ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
		};
		$text = static function ( $s ): string {
			return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		};
		$set = static function ( string $key, $value ) use ( &$out ): void {
			if ( is_string( $value ) ? '' !== $value : ( null !== $value && false !== $value ) ) {
				$out[ $key ] = $value;
			}
		};
		$set( 'agency_name', $name( $agency['name'], 80 ) );
		$set( 'agency_url', esc_url_raw( (string) $agency['url'] ) );
		$set( 'theme_url', esc_url_raw( (string) $agency['url'] ) );
		$sq = (int) $agency['logo_square_id'] ? (int) $agency['logo_square_id'] : (int) $agency['logo_id'];
		$img300 = $sq ? Branding::css_url( Branding::attachment_url( $sq, 'medium' ) ) : '';
		$img256 = $sq ? Branding::css_url( Branding::attachment_url( $sq, 'thumbnail' ) ) : '';
		$set( 'agency_image', $img300 );
		$product = $name( $p['name'], 40 );
		if ( '' !== $product ) {
			$set( 'theme_name', $product );
			$set( 'theme_name_short', $name( '' !== $p['short'] ? $p['short'] : $product, 18 ) );
			$set( 'blocks_name', $name( $product . ' Blocks', 60 ) );
			$set( 'blocks_pro_name', $name( $product . ' Blocks Pro', 60 ) );
			$set( 'theme_pro_name', $name( $product . ' Theme Pro', 60 ) );
			$set( 'plugin_name', $name( $product . ' White Label', 60 ) );
			$set( 'blocks_menu_name', $name( '' !== $p['blocks_menu'] ? $p['blocks_menu'] : 'Blocks', 12 ) );
		}
		$set( 'theme_desc', $text( $p['theme_desc'] ) );
		$set( 'blocks_desc', $text( $p['blocks_desc'] ) );
		$set( 'blocks_pro_desc', $text( $p['blocks_desc'] ) );
		$set( 'theme_pro_desc', $text( $p['theme_desc'] ) );
		$set( 'plugin_desc', $text( $p['blocks_desc'] ) );
		if ( (int) $p['theme_image'] ) {
			$set( 'theme_image', Branding::css_url( Branding::attachment_url( (int) $p['theme_image'], 'full' ) ) );
		}
		foreach ( [ 'blocks_image', 'blocks_pro_image', 'theme_pro_image', 'plugin_image' ] as $key ) {
			$set( $key, $img256 );
		}
		$icon = (string) $k['icon'];
		if ( 'agency' === $icon ) {
			if ( '' !== $agency['icon_svg'] ) {
				$out['blocks_icon'] = 'custom';
				$out['blocks_icon_svg'] = $agency['icon_svg'];
			}
		} elseif ( '' !== $icon ) {
			$out['blocks_icon'] = $icon;
		}
		if ( ! empty( $k['disable_ai_all'] ) ) {
			$out['disable_ai'] = true;
		}
		foreach ( (array) $k['overrides'] as $key => $val ) {
			if ( in_array( $key, Branding::KWL_TEXT_KEYS, true ) ) {
				$clean = '_desc' === substr( $key, -5 ) ? $text( $val ) : $name( $val, 60 );
				if ( '' !== $clean ) {
					$out[ $key ] = $clean;
				}
			}
		}
		return $out;
	}

	/** The compiled values merged over a decoded option: managed keys are replaced, everything else is kept. */
	private static function merged( array $cur, bool $hide_ui ): array {
		foreach ( self::MANAGED as $key ) {
			unset( $cur[ $key ] );
		}
		$compiled = self::compile();
		if ( $hide_ui ) {
			$compiled['hide_white_label'] = true;
		}
		return array_merge( $cur, $compiled );
	}

	/** pre_option filter (manage mode): a JSON string, never an array, never written to the database. */
	public static function virtual( $pre ) {
		if ( self::$bypass || ! Branding::active() || 'manage' !== self::mode() ) {
			return $pre;
		}
		if ( null === self::$virtual ) {
			self::$virtual = (string) wp_json_encode( self::merged( self::decode( self::raw() ), true ) );
		}
		return self::$virtual;
	}

	public static function menu_hidden( $type ) {
		return Branding::active() && 'manage' === self::mode() ? 'hidden' : $type;
	}

	/**
	 * pre_update_option: whatever writes that plugin's option, it stays a JSON string. In manage mode the managed keys
	 * are put back to what the database held, so a save from that plugin's own screen never keeps our values.
	 */
	public static function guard_write( $value, $old = null ) {
		if ( 'off' === self::mode() ) {
			return $value;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = (string) wp_json_encode( $value );
		}
		if ( 'manage' === self::mode() && is_string( $value ) ) {
			$new = self::decode( $value );
			$raw = self::decode( self::raw() );
			foreach ( self::MANAGED as $key ) {
				if ( array_key_exists( $key, $raw ) ) {
					$new[ $key ] = $raw[ $key ];
				} else {
					unset( $new[ $key ] );
				}
			}
			$value = (string) wp_json_encode( $new );
		}
		return $value;
	}

	/* ------------------------------------------------------------------ persist mode */

	/**
	 * Write the compiled values into that plugin's option (merged over what is there), verify, and record it.
	 *
	 * @return string[]|\WP_Error the keys written
	 */
	public static function persist() {
		if ( ! self::detected() ) {
			return new \WP_Error( 'lb_kwl_missing', __( 'Kadence White Label is not installed, so nothing was written to its settings. The values are kept and are written when it is.', 'launch-builder' ) );
		}
		if ( ! Lock::acquire( 'branding', 30 ) ) {
			return new \WP_Error( 'lb_busy', __( 'Another branding change is running. Try again in a moment.', 'launch-builder' ), [ 'status' => 409 ] );
		}
		self::$bypass = true; // This request may have started in manage mode: read and write the real row, not the virtual value.
		try {
			$raw = get_option( self::OPTION, null );
			$rec = get_option( Branding::OPT_KWL, [] );
			if ( ! is_array( $rec ) || ! array_key_exists( 'original', $rec ) ) {
				$rec = [ 'original' => is_string( $raw ) ? $raw : ( null === $raw ? null : (string) wp_json_encode( $raw ) ), 'at' => gmdate( 'c' ), 'keys' => [] ];
			}
			$compiled = self::compile();
			$json = (string) wp_json_encode( self::merged( self::decode( $raw ), false ) );
			update_option( self::OPTION, $json );
			$back = get_option( self::OPTION );
			$arr = is_string( $back ) ? json_decode( $back, true ) : null;
			$ok = is_array( $arr ) && true === ( $arr['enable_white_label'] ?? null );
			foreach ( self::SWITCHES as $sw ) {
				if ( isset( $arr[ $sw ] ) && true !== $arr[ $sw ] ) {
					$ok = false;
				}
			}
			if ( ! $ok ) {
				return new \WP_Error( 'lb_kwl_verify', __( 'The Kadence White Label settings did not read back as expected, so the change was not kept as written. Use Restore to put the previous settings back.', 'launch-builder' ) );
			}
			Ledger::record( 'branding:kwl', 'option', self::OPTION, Ledger::hash_value( get_option( self::OPTION, null ) ), 'set' );
			$rec['keys'] = array_keys( $compiled );
			$rec['written_at'] = gmdate( 'c' );
			update_option( Branding::OPT_KWL, $rec, false );
			return array_keys( $compiled );
		} finally {
			self::$bypass = false;
			Lock::release( 'branding' );
		}
	}

	/** Put that plugin's option back to what it was before the first persist. @return true|\WP_Error */
	public static function restore() {
		$rec = get_option( Branding::OPT_KWL, [] );
		if ( ! is_array( $rec ) || ! array_key_exists( 'original', $rec ) ) {
			return new \WP_Error( 'lb_kwl_nothing', __( 'There are no previous Kadence White Label settings to restore.', 'launch-builder' ), [ 'status' => 404 ] );
		}
		self::$bypass = true;
		try {
			Abilities::snapshot( 'branding-kwl-restore', [], [ self::OPTION ] );
			if ( null === $rec['original'] ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, (string) $rec['original'] );
			}
		} finally {
			self::$bypass = false;
		}
		delete_option( Branding::OPT_KWL );
		Ledger::forget( 'branding:kwl' );
		return true;
	}

	/** Has the persisted option been changed by someone else since it was written? (The hash is of the database row.) */
	public static function drift(): bool {
		return 'persist' === self::mode() && Ledger::is_edited( 'branding:kwl', Ledger::hash_value( self::raw() ) );
	}

	/* ------------------------------------------------------------------ state and checks */

	/** @return array<string,mixed> for get-branding and site-state (never reads that plugin's settings while the bridge is off) */
	public static function state(): array {
		$mode = self::mode();
		$out = [
			'detected'     => self::detected(),
			'version'      => self::version(),
			'tested'       => self::TESTED,
			'mode'         => $mode,
			'managed_keys' => 'off' === $mode ? [] : array_keys( self::compile() ),
		];
		if ( 'off' !== $mode ) {
			$raw = self::raw();
			$out['raw_is_string'] = null === $raw ? null : is_string( $raw );
			$out['drift'] = self::drift();
			$out['previous_settings_saved'] = is_array( get_option( Branding::OPT_KWL, [] ) ) && array_key_exists( 'original', (array) get_option( Branding::OPT_KWL, [] ) );
		} else {
			$out['drift'] = false;
		}
		return $out;
	}

	/** @return string[] */
	public static function warnings(): array {
		$w = [];
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return $w;
		}
		if ( ! self::detected() ) {
			$w[] = __( 'Kadence White Label is not installed. The Kadence editor names and icons use the built-in fallback; nothing is written to that plugin.', 'launch-builder' );
		} elseif ( self::TESTED !== self::version() ) {
			/* translators: 1: installed version, 2: tested version */
			$w[] = sprintf( __( 'Kadence White Label %1$s is installed; this bridge was tested with %2$s.', 'launch-builder' ), self::version(), self::TESTED );
		}
		$raw = self::raw();
		if ( null !== $raw && ! is_string( $raw ) ) {
			$w[] = __( 'The Kadence White Label option holds an array instead of a JSON string, which stops that plugin on PHP 8. Saving the Branding screen re-encodes it.', 'launch-builder' );
		}
		if ( self::drift() ) {
			$w[] = __( 'The Kadence White Label settings were changed outside Launch Builder since they were written. Saving the Branding screen writes them again.', 'launch-builder' );
		}
		return $w;
	}

	/** Audit: that plugin's option must be a JSON string (only while the bridge is on). */
	public static function audit_option(): array {
		if ( 'off' === self::mode() || ! self::detected() ) {
			return [];
		}
		$raw = self::raw();
		if ( null !== $raw && ! is_string( $raw ) ) {
			return [ [ 'severity' => 'error', 'code' => 'branding_kwl_option', 'object_type' => 'site', 'id' => 0, 'url' => admin_url( 'admin.php?page=' . Branding::PAGE ), 'message' => 'The Kadence White Label option holds an array instead of a JSON string, which is a fatal error on PHP 8.', 'fix' => 'Save the Branding screen (it re-encodes the value) or set the option back to a JSON string.' ] ];
		}
		return [];
	}

	public static function audit_drift(): array {
		if ( self::drift() ) {
			return [ [ 'severity' => 'info', 'code' => 'branding_kwl_drift', 'object_type' => 'site', 'id' => 0, 'url' => admin_url( 'admin.php?page=' . Branding::PAGE ), 'message' => 'The persisted Kadence White Label settings were changed outside Launch Builder.', 'fix' => 'Save the Branding screen to write them again, or restore the previous settings.' ] ];
		}
		return [];
	}

	/* ------------------------------------------------------------------ fallback (that plugin is not installed) */

	private static function boot_fallback(): void {
		add_filter( 'kadence_blocks_brand_name', [ __CLASS__, 'brand_name' ], 90 );
		add_filter( 'kadence_blocks_admin_svg_icon', [ __CLASS__, 'menu_icon' ] );
		add_filter( 'kadence_theme_dashboard_logo', [ __CLASS__, 'dash_logo' ] );
		add_filter( 'kadence_blocks_dash_logo', [ __CLASS__, 'dash_logo' ] );
		add_filter( 'block_categories_all', [ __CLASS__, 'block_category' ], 99 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_script' ], 1 );
	}

	private static function product_blocks_name(): string {
		$c = self::compile();
		return (string) ( $c['blocks_name'] ?? '' );
	}

	public static function brand_name( $name ) {
		$n = Branding::active() ? self::product_blocks_name() : '';
		return '' !== $n ? $n : $name;
	}

	public static function menu_icon( $svg ) {
		$a = Branding::agency();
		return Branding::active() && 'agency' === Branding::section( 'kadence' )['icon'] && '' !== $a['icon_svg'] ? $a['icon_svg'] : $svg;
	}

	public static function dash_logo( $url ) {
		$a = Branding::agency();
		$img = Branding::active() && $a['logo_id'] ? Branding::attachment_url( (int) $a['logo_id'], 'medium' ) : '';
		return '' !== $img ? $img : $url;
	}

	public static function block_category( $categories ) {
		$n = Branding::active() ? self::product_blocks_name() : '';
		if ( '' === $n || ! is_array( $categories ) ) {
			return $categories;
		}
		foreach ( $categories as $i => $cat ) {
			if ( is_array( $cat ) && 'kadence-blocks' === ( $cat['slug'] ?? '' ) ) {
				$categories[ $i ]['title'] = $n;
			}
		}
		return $categories;
	}

	/** The name in the editor's Kadence sidebar control (a JavaScript filter that plugin applies). */
	public static function editor_script(): void {
		$n = Branding::active() ? self::product_blocks_name() : '';
		if ( '' === $n ) {
			return;
		}
		wp_add_inline_script( 'wp-hooks', 'wp.hooks.addFilter("kadence.block_sidebar_control_name","launch-builder/branding",function(){return ' . wp_json_encode( $n ) . ';});', 'after' );
	}

	/* ------------------------------------------------------------------ Conversions and other Kadence names (their own switches) */

	/** Surfaces that do not depend on that plugin: the Conversions names and icon, and Kadence AI for the whole site. */
	private static function boot_gaps(): void {
		if ( ! Branding::active() ) {
			return;
		}
		$k = Branding::section( 'kadence' );
		if ( ! empty( $k['conversions_rename'] ) && '' !== (string) Branding::section( 'product' )['name'] ) {
			add_filter( 'gettext_kadence-conversions', [ __CLASS__, 'conversions_text' ], 10, 2 );
			add_action( 'admin_menu', [ __CLASS__, 'conversions_menu' ], 999 );
			add_filter( 'all_plugins', [ __CLASS__, 'conversions_row' ] );
		}
	}

	private static function conversions_name(): string {
		$p = trim( str_replace( [ '"', "'", '`', '\\', '<', '>', '&' ], '', wp_strip_all_tags( (string) Branding::section( 'product' )['name'] ) ) );
		return '' !== $p ? $p . ' Conversions' : '';
	}

	public static function conversions_text( $translated, $text ) {
		$n = self::conversions_name();
		if ( '' !== $n && in_array( $text, [ 'Kadence Conversions - Popups, Slid-ins, Embed', 'Kadence Conversions' ], true ) ) {
			return $n;
		}
		return $translated;
	}

	/** The Conversions menu icon becomes the agency icon. */
	public static function conversions_menu(): void {
		global $menu;
		$a = Branding::agency();
		if ( '' === $a['icon_svg'] ) {
			return;
		}
		foreach ( (array) $menu as $k => $item ) {
			if ( isset( $item[2] ) && 'kadence-conversions' === $item[2] ) {
				$menu[ $k ][6] = 'data:image/svg+xml;base64,' . base64_encode( $a['icon_svg'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- WordPress expects a base64 data URI for SVG menu icons.
			}
		}
	}

	/** The Conversions plugin row, matched by its text domain (not its folder name). */
	public static function conversions_row( $plugins ) {
		$n = self::conversions_name();
		if ( '' === $n || ! is_array( $plugins ) ) {
			return $plugins;
		}
		$a = Branding::agency();
		foreach ( $plugins as $file => $data ) {
			if ( is_array( $data ) && 'kadence-conversions' === ( $data['TextDomain'] ?? '' ) ) {
				$plugins[ $file ]['Name'] = $n;
				$plugins[ $file ]['Title'] = $n;
				$plugins[ $file ]['Author'] = $a['name'];
				$plugins[ $file ]['AuthorName'] = $a['name'];
				$plugins[ $file ]['AuthorURI'] = $a['url'];
			}
		}
		return $plugins;
	}
}
