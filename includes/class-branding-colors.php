<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard colours: the sidebar, the top bar, links and buttons of wp-admin, in the colours an owner picks on the
 * Branding screen. Part of the Branding module (off with it, inert until configured).
 *
 * How it works: a colour scheme ("Dashboard colours") is registered with WordPress, and one small stylesheet is printed in
 * wp-admin's head for the people it applies to (admin_head only, so nothing loads on the public site). The stylesheet is
 * compiled when the settings are saved and kept in an option keyed by plugin version and a hash of the colours; a page
 * view only reads it (a stale key is compiled in memory, never written back).
 *
 * Who sees it (admin_colors.scope): "clients" (default) = Site Owner accounts, "everyone", or "nobody" (the settings
 * screen preview only). Whatever the scope, anyone can pick "Dashboard colours" on their own profile page. A person who
 * chose another colour scheme for themselves keeps it unless the scope is "everyone". Core's own scheme picker keeps
 * showing what the person really stored. Administrators are never locked out: text colours are always corrected to a
 * readable contrast, and the constant LB_ADMIN_COLORS_OFF (or LB_BRANDING_SAFE_MODE) in wp-config.php switches it off.
 */
final class Branding_Colors {

	/** The five colours an owner picks. */
	const KEYS = [ 'sidebar', 'sidebar_text', 'highlight', 'topbar', 'accent' ];

	/** The ready-made schemes a call can name ('' = none, brand = the Kadence palette). */
	const SCHEME_IDS = [ '', 'brand', 'midnight', 'forest', 'daylight', 'ember', 'plum', 'slate' ];

	/** The key of the colour scheme registered with WordPress. */
	const SCHEME_KEY = 'lb-site-colors';

	/** The constant that switches only the dashboard colours off. */
	const OFF_CONSTANT = 'LB_ADMIN_COLORS_OFF';

	/** The compiled stylesheet: { k: version and hash, css }. Not autoloaded. */
	const OPT_CSS = 'launch_builder_branding_colors_css';

	/** What WordPress 7 and later use (its default colour scheme, "modern"), for a colour that is left empty. */
	const WP_DEFAULT = [ 'sidebar' => '#1E1E1E', 'sidebar_text' => '#FFFFFF', 'highlight' => '#3858E9', 'topbar' => '#1E1E1E', 'accent' => '#3858E9' ];

	/** The same for WordPress before 7, where the default colour scheme is "fresh". */
	const WP_DEFAULT_LEGACY = [ 'sidebar' => '#1D2327', 'sidebar_text' => '#FFFFFF', 'highlight' => '#2271B1', 'topbar' => '#1D2327', 'accent' => '#2271B1' ];

	/** The colour of the page behind the content (links and buttons must read on it). */
	const PAGE_BG = '#F0F0F1';

	const MIN_TEXT = 4.5;
	const MIN_UI   = 3.0;

	/** Ready-made schemes. Every text pairing in them passes WCAG AA (checked by the unit tests). */
	const SCHEMES = [
		'midnight' => [ 'sidebar' => '#0F172A', 'sidebar_text' => '#E2E8F0', 'highlight' => '#38BDF8', 'topbar' => '#0B1220', 'accent' => '#1D4ED8' ],
		'forest'   => [ 'sidebar' => '#14281D', 'sidebar_text' => '#E6F0E9', 'highlight' => '#4CAF7A', 'topbar' => '#0E1D15', 'accent' => '#1F7A4D' ],
		'daylight' => [ 'sidebar' => '#E4E9F0', 'sidebar_text' => '#1F2933', 'highlight' => '#1D4ED8', 'topbar' => '#FFFFFF', 'accent' => '#1D4ED8' ],
		'ember'    => [ 'sidebar' => '#2B1B14', 'sidebar_text' => '#F7E8E0', 'highlight' => '#F97316', 'topbar' => '#1F120D', 'accent' => '#C2410C' ],
		'plum'     => [ 'sidebar' => '#2E1A47', 'sidebar_text' => '#F3E8FF', 'highlight' => '#C084FC', 'topbar' => '#220F38', 'accent' => '#7E22CE' ],
		'slate'    => [ 'sidebar' => '#1F2937', 'sidebar_text' => '#F3F4F6', 'highlight' => '#2DD4BF', 'topbar' => '#111827', 'accent' => '#0F766E' ],
	];

	/** @var bool true while core's scheme picker draws (it must see what the person stored, not what we apply) */
	private static $picker = false;

	/* ------------------------------------------------------------------ switches and settings */

	/** Is it switched off by a constant, or by the Branding safe mode? */
	public static function off(): bool {
		return Branding::safe() || ( defined( self::OFF_CONSTANT ) && constant( self::OFF_CONSTANT ) );
	}

	/** Is this WordPress 7 or later? There the default colour scheme is "modern" and "fresh" (now called Classic) is a deliberate pick. */
	public static function modern_default(): bool {
		$v = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '7.0';
		return ! preg_match( '/^(\d+)\./', $v, $m ) || (int) $m[1] >= 7;
	}

	/** The colour scheme a person has when they never chose one. */
	public static function default_scheme(): string {
		return self::modern_default() ? 'modern' : 'fresh';
	}

	/** The stored scheme values that mean "never chose one" (only these are switched under the default audience). @return string[] */
	public static function unchosen(): array {
		return [ '', self::default_scheme() ];
	}

	/** The colours WordPress uses when nothing is set, for this WordPress version. @return array<string,string> */
	public static function defaults(): array {
		return self::modern_default() ? self::WP_DEFAULT : self::WP_DEFAULT_LEGACY;
	}

	/** The five stored colours (#RRGGBB or ''), from the Branding settings unless a settings array is given. @return array<string,string> */
	public static function colors( ?array $settings = null ): array {
		$s = null === $settings ? (array) Branding::section( 'admin_colors' ) : $settings;
		$out = [];
		foreach ( self::KEYS as $k ) {
			$h = isset( $s[ $k ] ) && is_string( $s[ $k ] ) ? Style::hex( $s[ $k ] ) : null;
			$out[ $k ] = $h ? $h : '';
		}
		return $out;
	}

	/** Has the owner set at least one colour? */
	public static function customised( ?array $settings = null ): bool {
		return '' !== implode( '', self::colors( $settings ) );
	}

	/** A ready-made scheme's five colours (null for none or unknown). 'brand' is read from the Kadence palette now. */
	public static function scheme( string $id ): ?array {
		if ( 'brand' === $id ) {
			return self::brand();
		}
		if ( ! isset( self::SCHEMES[ $id ] ) ) {
			return null;
		}
		return array_intersect_key( self::SCHEMES[ $id ], array_flip( self::KEYS ) );
	}

	/** The id of the ready-made scheme whose five colours are exactly these ('' when none is). @param array<string,string> $colors */
	public static function matching_scheme( array $colors ): string {
		foreach ( self::SCHEME_IDS as $id ) {
			$s = '' === $id ? null : self::scheme( $id );
			if ( $s && $s == array_intersect_key( $colors, $s ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- the key order does not matter.
				return $id;
			}
		}
		return '';
	}

	/** The brand colours: the sidebar and top bar in the strongest text colour, the active item and accent in the first accent. */
	public static function brand(): array {
		$pal = Style::palette();
		$h = static function ( string $slug, string $fallback ) use ( $pal ): string {
			return (string) ( Style::hex( $pal[ $slug ] ?? $fallback ) ?: $fallback );
		};
		$base = $h( 'palette3', '#1A202C' );
		$hl = $h( 'palette1', '#2B6CB0' );
		$sb = $base;
		for ( $step = 1; $step <= 6 && self::ratio( $hl, $sb ) < self::MIN_UI; $step++ ) {
			$sb = self::darken( $base, $step / 10 ); // The active item has to stand out from the sidebar.
		}
		return [
			'sidebar'      => $sb,
			'sidebar_text' => $h( 'palette9', '#FFFFFF' ),
			'highlight'    => $hl,
			'topbar'       => $sb,
			'accent'       => $hl,
		];
	}

	/** The name of a ready-made scheme as shown on the Branding screen. */
	public static function label( string $id ): string {
		$names = [
			'brand'    => __( 'My brand colours', 'launch-builder' ),
			'midnight' => __( 'Midnight', 'launch-builder' ),
			'forest'   => __( 'Forest', 'launch-builder' ),
			'daylight' => __( 'Daylight', 'launch-builder' ),
			'ember'    => __( 'Ember', 'launch-builder' ),
			'plum'     => __( 'Plum', 'launch-builder' ),
			'slate'    => __( 'Slate', 'launch-builder' ),
		];
		return $names[ $id ] ?? $id;
	}

	/** Everything the Branding screen's script needs to run the same maths and offer the schemes. */
	public static function client_config(): array {
		$schemes = [];
		foreach ( array_merge( [ 'brand' ], array_keys( self::SCHEMES ) ) as $id ) {
			$schemes[ $id ] = [ 'label' => self::label( $id ) ] + (array) self::scheme( $id );
		}
		return [ 'schemes' => $schemes, 'defaults' => self::defaults(), 'page' => self::PAGE_BG ];
	}

	/* ------------------------------------------------------------------ colour maths (mirrored in assets/js/branding-colors.js) */

	/** WCAG contrast ratio, not rounded (a ratio is never rounded up to pass). */
	public static function ratio( string $a, string $b ): float {
		$la = Style::luminance( $a );
		$lb = Style::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** A ratio as shown to people: cut (not rounded) to two decimals. */
	public static function shown( float $r ): string {
		return number_format( floor( $r * 100 + 1e-9 ) / 100, 2, '.', '' );
	}

	/** $fg, moved toward white or black (whichever reads better on $bg) in small steps until it reaches $min; unchanged when it already does. */
	public static function readable( string $fg, string $bg, float $min = self::MIN_TEXT ): string {
		if ( self::ratio( $fg, $bg ) >= $min ) {
			return $fg;
		}
		$target = self::ratio( '#FFFFFF', $bg ) >= self::ratio( '#000000', $bg ) ? '#FFFFFF' : '#000000';
		for ( $i = 1; $i <= 20; $i++ ) {
			$c = Style::blend( $target, $fg, $i / 20 );
			if ( self::ratio( $c, $bg ) >= $min ) {
				return $c;
			}
		}
		return $target;
	}

	/** The text colour for a background: white, else near-black, else pure black; whichever reaches $min first, else the best of white and black. */
	public static function on( string $bg, float $min = self::MIN_TEXT ): string {
		foreach ( [ '#FFFFFF', '#1D2327', '#000000' ] as $c ) {
			if ( self::ratio( $c, $bg ) >= $min ) {
				return $c;
			}
		}
		return self::ratio( '#FFFFFF', $bg ) >= self::ratio( '#000000', $bg ) ? '#FFFFFF' : '#000000';
	}

	/** Mixed with black: a hover or pressed state. */
	public static function darken( string $hex, float $amount ): string {
		return Style::blend( '#000000', $hex, $amount );
	}

	/** A background a step away from $hex: darker, and half as much when it is already light. */
	public static function deepen( string $hex, float $amount ): string {
		return self::darken( $hex, Style::luminance( $hex ) < 0.35 ? $amount : $amount / 2 );
	}

	private static function rgb( string $hex ): string {
		$h = ltrim( $hex, '#' );
		return hexdec( substr( $h, 0, 2 ) ) . ',' . hexdec( substr( $h, 2, 2 ) ) . ',' . hexdec( substr( $h, 4, 2 ) );
	}

	/**
	 * The final colours for the five chosen ones, with every text colour checked: sidebar text on the sidebar, text on
	 * the active item and on the top bar, button text and links on the page. Text colours are corrected, never the
	 * backgrounds. A colour left empty is the WordPress default.
	 *
	 * @param array $in Saved or posted colours (keys of self::KEYS).
	 * @return array{colors:array<string,string>,v:array<string,string>,ratios:array<string,float>,notes:string[],adjusted:string[]}
	 */
	public static function resolve( array $in ): array {
		$set = self::colors( $in );
		$def = self::defaults();
		$c = [];
		foreach ( self::KEYS as $k ) {
			$c[ $k ] = '' !== $set[ $k ] ? $set[ $k ] : $def[ $k ];
		}
		$notes = [];
		$adj = [];
		$ratios = [];

		$sb = $c['sidebar'];
		if ( '' === $set['sidebar_text'] ) {
			$c['sidebar_text'] = self::on( $sb ); // Not chosen: readable on whatever the sidebar is.
		}
		$st = self::readable( $c['sidebar_text'], $sb );
		$ratios['sidebar_text'] = self::ratio( $st, $sb );
		if ( $st !== $c['sidebar_text'] ) {
			$adj[] = 'sidebar_text';
			/* translators: 1: the colour chosen, 2: its contrast ratio, 3: the colour used instead */
			$notes[] = sprintf( __( 'Sidebar text %1$s has only %2$s:1 contrast on the sidebar (4.5:1 needed), so %3$s is used.', 'launch-builder' ), $c['sidebar_text'], self::shown( self::ratio( $c['sidebar_text'], $sb ) ), $st );
		}
		$sub = self::deepen( $sb, 0.22 );
		$sub_text = self::readable( Style::blend( $st, $sub, 0.78 ), $sub );
		$sub_hover = self::readable( $st, $sub );

		$hl = $c['highlight'];
		$hl_text = self::on( $hl );
		$ratios['active_text'] = self::ratio( $hl_text, $hl );
		$ratios['active_on_sidebar'] = self::ratio( $hl, $sb );
		if ( $ratios['active_on_sidebar'] < self::MIN_UI && ( '' !== $set['highlight'] || '' !== $set['sidebar'] ) ) { // WordPress's own pair is not the owner's to fix.
			/* translators: %s: contrast ratio */
			$notes[] = sprintf( __( 'The active menu item colour is close to the sidebar colour (%s:1). Pick one that stands out more (3:1 or more).', 'launch-builder' ), self::shown( $ratios['active_on_sidebar'] ) );
		}
		$bubble_on = self::on( $sub );

		$tb = $c['topbar'];
		$tb_text = self::on( $tb );
		$ratios['bar_text'] = self::ratio( $tb_text, $tb );
		$tb_hover = self::deepen( $tb, 0.25 );
		$tb_hover_text = self::on( $tb_hover );
		$tb_sub_text = self::readable( Style::blend( $tb_hover_text, $tb_hover, 0.78 ), $tb_hover );

		$acc = $c['accent'];
		$acc_text = self::on( $acc );
		$ratios['button_text'] = self::ratio( $acc_text, $acc );
		$acc_hover = self::darken( $acc, 0.12 );
		$acc_active = self::darken( $acc, 0.24 );
		$link = self::readable( $acc, self::PAGE_BG );
		$ratios['link'] = self::ratio( $link, self::PAGE_BG );
		if ( $link !== $acc ) {
			$adj[] = 'accent';
			/* translators: 1: its contrast ratio, 2: the colour links use instead */
			$notes[] = sprintf( __( 'The accent colour has only %1$s:1 contrast on the page (4.5:1 needed for links), so links use %2$s. Buttons keep the accent colour.', 'launch-builder' ), self::shown( self::ratio( $acc, self::PAGE_BG ) ), $link );
		}
		$ratios['button_on_page'] = self::ratio( $acc, self::PAGE_BG );
		if ( $ratios['button_on_page'] < self::MIN_UI ) {
			/* translators: %s: contrast ratio */
			$notes[] = sprintf( __( 'The accent colour is very light on the page (%s:1), so buttons are hard to see (3:1 needed). Pick a stronger colour.', 'launch-builder' ), self::shown( $ratios['button_on_page'] ) );
		}
		$link_hover = self::darken( $link, 0.2 );

		$v = [
			'sidebar' => $sb, 'sidebar_text' => $st, 'sub' => $sub, 'sub_text' => $sub_text, 'sub_hover' => $sub_hover,
			'highlight' => $hl, 'highlight_text' => $hl_text, 'bubble_on' => $bubble_on,
			'topbar' => $tb, 'bar_text' => $tb_text, 'bar_hover' => $tb_hover, 'bar_hover_text' => $tb_hover_text, 'bar_sub_text' => $tb_sub_text,
			'accent' => $acc, 'accent_text' => $acc_text, 'accent_hover' => $acc_hover, 'accent_hover_text' => self::on( $acc_hover ), 'accent_active' => $acc_active, 'accent_active_text' => self::on( $acc_active ),
			'link' => $link, 'link_hover' => $link_hover, 'link_d10' => self::darken( $link, 0.1 ),
		];
		return [ 'colors' => $c, 'v' => $v, 'ratios' => $ratios, 'notes' => $notes, 'adjusted' => $adj ];
	}

	/* ------------------------------------------------------------------ the stylesheet */

	/** The compiled stylesheet for a resolved set of colours: rules for the sidebar, the top bar, links, buttons and the theme colour variables. */
	public static function css( array $r ): string {
		$v = $r['v'];
		$S = 'body.admin-color-' . self::SCHEME_KEY;
		$rules = [];
		$add = static function ( array $selectors, string $decl ) use ( &$rules, $S ): void {
			$rules[] = implode( ',', array_map( static function ( $sel ) use ( $S ) { return $S . ' ' . $sel; }, $selectors ) ) . '{' . $decl . '}';
		};
		$v10 = $v['link_d10'];
		$vars = '--wp-admin-theme-color:' . $v['link'] . ';--wp-admin-theme-color--rgb:' . self::rgb( $v['link'] ) . ';--wp-admin-theme-color-darker-10:' . $v10 . ';--wp-admin-theme-color-darker-10--rgb:' . self::rgb( $v10 ) . ';--wp-admin-theme-color-darker-20:' . $v['link_hover'] . ';--wp-admin-theme-color-darker-20--rgb:' . self::rgb( $v['link_hover'] );
		$rules[] = $S . '{' . $vars . '}';
		// Links keep core's specificity (:where), so every component that sets its own colour still wins.
		$rules[] = ':where(' . $S . ') a{color:' . $v['link'] . '}';
		$rules[] = ':where(' . $S . ') a:hover,:where(' . $S . ') a:active,:where(' . $S . ') a:focus{color:' . $v['link_hover'] . '}';

		// Buttons.
		$add( [ '.button-primary' ], 'background:' . $v['accent'] . ';border-color:' . $v['accent'] . ';color:' . $v['accent_text'] );
		$add( [ '.button-primary:hover', '.button-primary.hover' ], 'background:' . $v['accent_hover'] . ';border-color:' . $v['accent_hover'] . ';color:' . $v['accent_hover_text'] );
		$add( [ '.button-primary:focus' ], 'background:' . $v['accent'] . ';border-color:' . $v['accent'] . ';color:' . $v['accent_text'] . ';box-shadow:0 0 0 1px #fff,0 0 0 3px var(--wp-admin-theme-color)' );
		$add( [ '.button-primary:active', '.button-primary.active' ], 'background:' . $v['accent_active'] . ';border-color:' . $v['accent_active'] . ';color:' . $v['accent_active_text'] );

		// Sidebar.
		$add( [ '#adminmenuback', '#adminmenuwrap', '#adminmenu' ], 'background:' . $v['sidebar'] );
		$add( [ '#adminmenu a', '#adminmenu div.wp-menu-image:before', '#collapse-button', 'div#wp-responsive-toggle a:before' ], 'color:' . $v['sidebar_text'] );
		$add( [ '#adminmenu a:hover', '#adminmenu li.menu-top:hover', '#adminmenu li.opensub>a.menu-top', '#adminmenu li>a.menu-top:focus', '#collapse-button:hover', '#collapse-button:focus' ], 'color:' . $v['highlight_text'] . ';background-color:' . $v['highlight'] );
		$add( [ '#adminmenu li.menu-top:hover div.wp-menu-image:before', '#adminmenu li.opensub>a.menu-top div.wp-menu-image:before', '#adminmenu li:hover div.wp-menu-image:before', '#adminmenu li a:focus div.wp-menu-image:before', '#adminmenu li.opensub div.wp-menu-image:before' ], 'color:' . $v['highlight_text'] );
		$add( [ '#adminmenu li.current a.menu-top', '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu', '#adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head', '.folded #adminmenu li.current.menu-top' ], 'color:' . $v['highlight_text'] . ';background:' . $v['highlight'] );
		$add( [ '#adminmenu li.wp-has-current-submenu div.wp-menu-image:before', '#adminmenu a.current:hover div.wp-menu-image:before', '#adminmenu li.current div.wp-menu-image:before', '#adminmenu li.wp-has-current-submenu a:focus div.wp-menu-image:before', '#adminmenu li.wp-has-current-submenu.opensub div.wp-menu-image:before' ], 'color:' . $v['highlight_text'] );
		$add( [ '#adminmenu .wp-submenu', '#adminmenu .wp-has-current-submenu .wp-submenu', '#adminmenu .wp-has-current-submenu.opensub .wp-submenu', '#adminmenu a.wp-has-current-submenu:focus+.wp-submenu' ], 'background:' . $v['sub'] );
		$add( [ '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after', '#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after' ], 'border-right-color:' . $v['sub'] );
		$add( [ '#adminmenu .wp-submenu .wp-submenu-head', '#adminmenu .wp-submenu a', '#adminmenu .wp-has-current-submenu .wp-submenu a', '#adminmenu a.wp-has-current-submenu:focus+.wp-submenu a', '#adminmenu .wp-has-current-submenu.opensub .wp-submenu a' ], 'color:' . $v['sub_text'] );
		$add( [ '#adminmenu .wp-submenu a:focus', '#adminmenu .wp-submenu a:hover', '#adminmenu .wp-has-current-submenu .wp-submenu a:focus', '#adminmenu .wp-has-current-submenu .wp-submenu a:hover', '#adminmenu .wp-submenu li.current a', '#adminmenu .wp-has-current-submenu .wp-submenu li.current a', '#adminmenu .wp-has-current-submenu.opensub .wp-submenu a:focus', '#adminmenu .wp-has-current-submenu.opensub .wp-submenu a:hover', '#adminmenu .wp-has-current-submenu.opensub .wp-submenu li.current a' ], 'color:' . $v['sub_hover'] );
		$add( [ '#adminmenu .menu-counter', '#adminmenu .awaiting-mod', '#adminmenu .update-plugins' ], 'color:' . $v['highlight_text'] . ';background:' . $v['highlight'] );
		$add( [ '#adminmenu li.current a .awaiting-mod', '#adminmenu li a.wp-has-current-submenu .update-plugins', '#adminmenu li:hover a .awaiting-mod', '#adminmenu li.menu-top:hover>a .update-plugins' ], 'color:' . $v['bubble_on'] . ';background:' . $v['sub'] );

		// Top bar.
		$add( [ '#wpadminbar' ], 'color:' . $v['bar_text'] . ';background:' . $v['topbar'] );
		$add( [ '#wpadminbar .ab-item', '#wpadminbar a.ab-item', '#wpadminbar>#wp-toolbar span.ab-label', '#wpadminbar>#wp-toolbar span.noticon', '#wpadminbar .ab-icon', '#wpadminbar .ab-icon:before', '#wpadminbar .ab-item:before', '#wpadminbar .ab-item:after', '#wpadminbar #adminbarsearch:before' ], 'color:' . $v['bar_text'] );
		$add( [ '#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item', '#wpadminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus', '#wpadminbar .ab-top-menu>li.menupop.hover>.ab-item' ], 'color:' . $v['bar_hover_text'] . ';background:' . $v['bar_hover'] );
		$add( [ '#wpadminbar:not(.mobile)>#wp-toolbar li:hover span.ab-label', '#wpadminbar:not(.mobile)>#wp-toolbar li.hover span.ab-label', '#wpadminbar:not(.mobile)>#wp-toolbar a:focus span.ab-label', '#wpadminbar:not(.mobile) li:hover .ab-icon:before', '#wpadminbar:not(.mobile) li:hover .ab-item:before', '#wpadminbar:not(.mobile) li:hover .ab-item:after', '#wpadminbar:not(.mobile) li:hover #adminbarsearch:before' ], 'color:' . $v['bar_hover_text'] );
		$add( [ '#wpadminbar .menupop .ab-sub-wrapper', '#wpadminbar .quicklinks .menupop ul.ab-sub-secondary', '#wpadminbar .quicklinks .menupop ul.ab-sub-secondary .ab-submenu', '.wp-responsive-open #wpadminbar #wp-admin-bar-menu-toggle a' ], 'background:' . $v['bar_hover'] );
		// The phone menu button: core paints its open icon a fixed light blue, which is hard to see on a light bar.
		$add( [ '.wp-responsive-open #wpadminbar #wp-admin-bar-menu-toggle .ab-icon:before' ], 'color:' . $v['bar_hover_text'] );
		$add( [ '#wpadminbar .ab-submenu .ab-item', '#wpadminbar .quicklinks .menupop ul li a', '#wpadminbar .quicklinks .menupop.hover ul li a', '#wpadminbar #wp-admin-bar-user-info .username', '#wpadminbar .quicklinks li .blavatar', '#wpadminbar .menupop .menupop>.ab-item:before' ], 'color:' . $v['bar_sub_text'] );
		$add( [ '#wpadminbar .quicklinks .menupop ul li a:hover', '#wpadminbar .quicklinks .menupop ul li a:focus', '#wpadminbar .quicklinks .menupop.hover ul li a:hover', '#wpadminbar .quicklinks .menupop.hover ul li a:focus', '#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover>a', '#wpadminbar #wp-admin-bar-user-info .display-name', '#wpadminbar #wp-admin-bar-user-info a:hover .display-name', '#wpadminbar li:hover .ab-icon:before', '#wpadminbar li:hover .ab-item:before', '#wpadminbar li a:focus .ab-icon:before', '#wpadminbar li .ab-item:focus:before', '#wpadminbar li.hover .ab-icon:before', '#wpadminbar li.hover .ab-item:before' ], 'color:' . $v['bar_hover_text'] );
		$add( [ '#wpadminbar #wp-admin-bar-recovery-mode', '#wpadminbar #wp-admin-bar-recovery-mode .ab-item', '#wpadminbar #wp-admin-bar-recovery-mode a.ab-item' ], 'color:' . $v['accent_text'] . ';background-color:' . $v['accent'] );

		return implode( '', $rules );
	}

	/* ------------------------------------------------------------------ cache */

	/** The cache key of a set of colours: the plugin version plus a hash of the five colours. */
	public static function cache_key( array $colors ): string {
		return LB_VERSION . ':' . substr( md5( implode( '|', self::colors( $colors ) ) ), 0, 12 );
	}

	/**
	 * The stylesheet for the saved colours: the stored copy when its key matches and it looks like our own output, else
	 * compiled here without writing anything (a page view never writes; the next save, or the update migration,
	 * refreshes the stored copy).
	 */
	public static function compiled(): string {
		$colors = self::colors();
		$cached = get_option( self::OPT_CSS, [] );
		if ( is_array( $cached ) && ( $cached['k'] ?? '' ) === self::cache_key( $colors ) && is_string( $cached['css'] ?? null ) && self::safe_css( $cached['css'] ) ) {
			return $cached['css'];
		}
		return self::css( self::resolve( $colors ) );
	}

	/**
	 * Can a stored stylesheet be printed as it is? The key is easy to work out, and the row is plain data, so the text
	 * itself is checked: what css() compiles never holds a tag, a quote, a backslash, an import or a url().
	 */
	public static function safe_css( string $css ): bool {
		return '' !== $css && ! preg_match( '/[<"\'\\\\]|@import|url\s*\(/i', $css );
	}

	/** Compile the saved colours into the cache option, or remove it when nothing is customised. Called when the settings change. */
	public static function refresh_cache(): void {
		$colors = self::colors();
		if ( '' === implode( '', $colors ) ) {
			delete_option( self::OPT_CSS );
			return;
		}
		update_option( self::OPT_CSS, [ 'k' => self::cache_key( $colors ), 'css' => self::css( self::resolve( $colors ) ) ], false );
	}

	/** Migration step (every plugin version): the stored copy is rebuilt for the new version outside page views. */
	public static function migrate_css( $cursor = null, int $batch = 50 ) {
		self::refresh_cache();
		Ledger::forget( 'branding:kwl' ); // A bookkeeping row an earlier version left behind; nothing reads it any more.
		return [ 'done' => true, 'report' => [ 'customised' => self::customised() ] ];
	}

	/* ------------------------------------------------------------------ in wp-admin */

	/** Register the hooks (wp-admin requests only; each callback decides who it applies to). */
	public static function boot(): void {
		if ( self::off() ) {
			add_filter( 'get_user_option_admin_color', [ __CLASS__, 'fallback_scheme' ] ); // Someone chose our scheme once: WordPress's default shows.
			return;
		}
		add_filter( 'get_user_option_admin_color', [ __CLASS__, 'user_scheme' ], 10, 3 );
		add_action( 'admin_init', [ __CLASS__, 'register_scheme' ], 20 );
		add_action( 'admin_head', [ __CLASS__, 'print_css' ], 5 );
		add_action( 'admin_color_scheme_picker', [ __CLASS__, 'picker_start' ], 1 );
		add_action( 'admin_color_scheme_picker', [ __CLASS__, 'picker_end' ], 99 );
	}

	/** The scheme is ours but not available (switched off, or colours reset): WordPress's own default. */
	public static function fallback_scheme( $value ) {
		return self::SCHEME_KEY === $value ? self::default_scheme() : $value;
	}

	/** Register "Dashboard colours" in the profile page's scheme picker once colours are saved. */
	public static function register_scheme(): void {
		if ( ! self::customised() || ! Branding::active() ) {
			return;
		}
		$v = self::resolve( self::colors() )['v'];
		wp_admin_css_color(
			self::SCHEME_KEY,
			__( 'Dashboard colours', 'launch-builder' ),
			false, // No stylesheet of its own: the compiled rules are printed in the head.
			[ $v['sidebar'], $v['sub'], $v['highlight'], $v['accent'] ],
			[ 'base' => $v['sidebar_text'], 'focus' => $v['highlight_text'], 'current' => $v['highlight_text'] ]
		);
	}

	public static function picker_start(): void {
		self::$picker = true;
	}

	public static function picker_end(): void {
		self::$picker = false;
	}

	/**
	 * get_user_option_admin_color: the scheme this request's own user gets. Their stored choice stays unless the scope says
	 * "everyone"; for "clients" only Site Owner accounts that never chose a scheme (unset, or the one WordPress gives by
	 * default) are switched. On WordPress 7 and later "fresh" (Classic) is a deliberate pick, so it is kept; before 7 it
	 * is the default and "modern" is the deliberate pick.
	 */
	public static function user_scheme( $value, $option = '', $user = null ) {
		if ( ! $user instanceof \WP_User || get_current_user_id() !== (int) $user->ID ) {
			return $value;
		}
		if ( ! self::customised() || ! Branding::active() ) {
			return self::SCHEME_KEY === $value ? self::default_scheme() : $value;
		}
		if ( self::SCHEME_KEY === $value || self::$picker ) {
			return $value;
		}
		$scope = (string) Branding::section( 'admin_colors' )['scope'];
		if ( 'everyone' === $scope ) {
			return self::SCHEME_KEY;
		}
		if ( 'clients' === $scope && Branding_Client::is_client( $user ) && in_array( (string) $value, self::unchosen(), true ) ) {
			return self::SCHEME_KEY;
		}
		return $value;
	}

	/** Is this the profile page (or another account's profile)? The scheme picker lives there. */
	private static function on_profile(): bool {
		global $pagenow;
		return isset( $pagenow ) && in_array( $pagenow, [ 'profile.php', 'user-edit.php' ], true );
	}

	/**
	 * admin_head: the compiled stylesheet, for a request whose user has the scheme. On the profile page it is printed
	 * whenever the scheme is offered: core's picker script swaps the page's colour class on a click, and without the
	 * rules already on the page the choice would look broken until the page is loaded again (every rule is scoped to
	 * the scheme's own body class, so the others are not touched).
	 */
	public static function print_css(): void {
		$mine = self::SCHEME_KEY === (string) get_user_option( 'admin_color' );
		if ( ! $mine && ! ( self::on_profile() && self::customised() && Branding::active() ) ) {
			return;
		}
		echo '<style id="lb-admin-colors">' . self::compiled() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- compiled() returns our own output or a stored copy that passed safe_css().
	}

	/* ------------------------------------------------------------------ state */

	/** What get-branding reports about the dashboard colours. @return array<string,mixed> */
	public static function state(): array {
		$s = (array) Branding::section( 'admin_colors' );
		$r = self::resolve( $s );
		$scope = (string) $s['scope'];
		$who = [ 'clients' => __( 'Site Owner accounts', 'launch-builder' ), 'everyone' => __( 'Everyone', 'launch-builder' ), 'nobody' => __( 'Nobody (preview only)', 'launch-builder' ) ][ $scope ] ?? '';
		$schemes = [ 'brand' => self::brand() ];
		foreach ( array_keys( self::SCHEMES ) as $id ) {
			$schemes[ $id ] = self::scheme( $id );
		}
		return [
			'scope'       => $scope,
			'who_sees'    => $who,
			'scheme'      => (string) $s['scheme'],
			'customised'  => self::customised( $s ),
			'in_use'      => Branding::active() && ! self::off() && 'nobody' !== $scope && self::customised( $s ),
			'switched_off' => self::off(),
			'colors'      => self::colors( $s ),
			'final'       => array_intersect_key( $r['v'], array_flip( [ 'sidebar', 'sidebar_text', 'highlight', 'highlight_text', 'topbar', 'bar_text', 'accent', 'accent_text', 'link' ] ) ),
			'contrast'    => array_map( 'floatval', array_map( [ __CLASS__, 'shown' ], $r['ratios'] ) ),
			'notes'       => $r['notes'],
			'schemes'     => $schemes,
		];
	}
}
