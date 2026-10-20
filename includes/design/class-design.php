<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Brand controls, all stored as Kadence's own settings: the palette, fonts and logo, the theme button styles, menu +
 * dropdown styling, the page title and layout standard, and Kadence's "block defaults". Patterns stay free of one-off
 * styling, so a client's look is changed here once and every page follows. Everything is snapshotted so a change can be
 * undone with restore-snapshot.
 */
final class Design {

	/** The theme mods set-buttons writes (snapshotted before a change). */
	const BUTTON_MODS = [
		'buttons_color', 'buttons_background', 'buttons_border', 'buttons_border_colors', 'buttons_border_radius', 'buttons_padding', 'buttons_typography', 'buttons_shadow', 'buttons_shadow_hover',
		'buttons_secondary_color', 'buttons_secondary_background', 'buttons_secondary_border', 'buttons_secondary_border_colors', 'buttons_secondary_border_radius', 'buttons_secondary_padding', 'buttons_secondary_typography', 'buttons_secondary_shadow', 'buttons_secondary_shadow_hover',
		'buttons_outline_color', 'buttons_outline_background', 'buttons_outline_border', 'buttons_outline_border_colors', 'buttons_outline_border_radius', 'buttons_outline_padding', 'buttons_outline_typography', 'buttons_outline_shadow', 'buttons_outline_shadow_hover',
	];

	public static function define_tools(): void {
		Abilities::add( 'set-brand', 'Set brand: palette, fonts, logo', 'One call to restyle the whole Kadence site. palette: palette1 (primary accent), palette2 (darker accent), palette3 (headings, darkest), palette4 (body text), palette5-6 (muted text), palette7-8 (light backgrounds), palette9 (white/base). fonts: body/headings/h1..h6. Snapshots first.',
			[ 'properties' => [
				'palette'            => [ 'type' => 'object', 'description' => 'palette1..palette9 => hex colour' ],
				'fonts'              => [ 'type' => 'object', 'description' => '{ body:{family,size,weight,lineHeight}, headings:{family,weight}, h1..h6:{size,lineHeight,weight} }. Google font names are auto-flagged.' ],
				'logo_attachment_id' => [ 'type' => 'integer' ],
			] ], [ __CLASS__, 'set_brand' ] );

		Abilities::add( 'set-buttons', 'Design the site-wide buttons', 'Buttons in patterns use the Kadence "Theme Base" style, so this one call restyles every button on the site. primary = the standard button, secondary = Theme Secondary, outline = Outline. Each takes any of: background, color, hover_background, hover_color (palette1-9, #hex, rgb(a) or transparent; outline takes a background too), border_color, hover_border_color, border_width (px), radius (px, or "pill"), padding ("sm"|"md"|"lg"|"xl" or [vertical, horizontal] px), weight (400-900), size (px), uppercase (bool), shadow ("none"|"soft" = same shadow on hover|"lift" = grows on hover|"hard" = offset block shadow). The result has a contrast report: the text on each button, normal and hover.',
			[ 'properties' => [ 'primary' => [ 'type' => 'object' ], 'secondary' => [ 'type' => 'object' ], 'outline' => [ 'type' => 'object' ] ] ], [ __CLASS__, 'set_buttons' ], 'edit_theme_options' );

		Abilities::add( 'set-nav-style', 'Style the menu and its dropdowns', 'Design the main menu (block header) and the sub-menu dropdown. links: {color, hover, active, style ("standard"|"underline"|"underline-fullheight"|"bold"), weight, size, uppercase}. dropdown: {background, hover_background, color, hover_color, active_color, width (px), radius (px), shadow ("none"|"soft"|"strong"), reveal ("none"|"fade"|"fade-up"|"fade-down"), divider (bool or colour), padding ([vertical, horizontal] px per item), spacing (gap under the parent, px), size (px), align ("left"|"center"|"right")}. Applies to the menus this plugin made (or one key), and with theme:true also to the Customizer dropdown used by non-block headers.',
			[ 'properties' => [ 'key' => [ 'type' => 'string' ], 'links' => [ 'type' => 'object' ], 'dropdown' => [ 'type' => 'object' ], 'theme' => [ 'type' => 'boolean' ] ] ], [ __CLASS__, 'set_nav_style' ], 'edit_theme_options' );

		Abilities::add( 'set-page-style', 'Standardise inner pages: title banner and layout', 'Sets the Kadence Customizer defaults every ordinary page inherits, so inner pages need no hero pattern and can be plain text. title: {show, layout ("above" = banner above the content, "normal"), align ("left"|"center"|"right"), height (px), background (palette/hex), color (title colour), overlay, breadcrumbs (bool: Kadence\'s own title-bar breadcrumbs; leave off when the Design hub places a breadcrumb bar)}. content: {width ("normal"|"narrow"|"fullwidth"|"right"|"left" = with sidebar), style ("boxed"|"unboxed"), padding ("show"|"hide")}. site: {content_width (px, the max width rows inherit), edge_spacing (rem)}. preset: "banner" gives a tinted title banner with breadcrumbs and unboxed content (recommended for text pages).',
			[ 'properties' => [ 'preset' => [ 'type' => 'string', 'enum' => [ 'banner', 'plain' ] ], 'title' => [ 'type' => 'object' ], 'content' => [ 'type' => 'object' ], 'site' => [ 'type' => 'object' ] ] ], [ __CLASS__, 'set_page_style' ], 'edit_theme_options' );

		Abilities::add( 'set-block-defaults', 'Set Kadence block defaults', 'What a newly inserted Kadence block starts with. preset "standard" makes buttons use Theme Base, rows inherit the theme max width, and icon lists start as a stylised check list in the brand colour. defaults: {"kadence/iconlist": {...attributes}} merges extra block defaults. reset:true clears them all.',
			[ 'properties' => [ 'preset' => [ 'type' => 'string', 'enum' => [ 'standard' ] ], 'defaults' => [ 'type' => 'object' ], 'reset' => [ 'type' => 'boolean' ] ] ], [ __CLASS__, 'set_block_defaults' ], 'edit_theme_options' );
	}

	/* ------------------------------------------------------------------ helpers */

	/** A Kadence colour: palette slug, hex or rgb(a). Null when it is none of those. */
	public static function color( $v ): ?string {
		$v = trim( (string) $v );
		if ( preg_match( '/^palette(10|[1-9])$/', $v ) || preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) || preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $v ) ) {
			return $v;
		}
		return 'transparent' === strtolower( $v ) ? 'transparent' : null;
	}

	/** Current value of a Kadence theme setting as an array. */
	private static function mod( string $key, array $default = [] ): array {
		$cur = get_theme_mod( $key );
		$arr = is_string( $cur ) ? json_decode( $cur, true ) : ( is_array( $cur ) ? $cur : null );
		return is_array( $arr ) ? $arr : $default;
	}

	private static function px( $v, int $min = 0, int $max = 999 ): int {
		return max( $min, min( $max, (int) $v ) );
	}

	private static function shadow( string $kind ): array {
		$none = [ 'color' => 'rgba(0,0,0,0)', 'hOffset' => 0, 'vOffset' => 0, 'blur' => 0, 'spread' => -7, 'inset' => false, 'disabled' => true ];
		if ( 'soft' === $kind ) {
			return [ 'color' => 'rgba(0,0,0,0.16)', 'hOffset' => 0, 'vOffset' => 6, 'blur' => 16, 'spread' => -6, 'inset' => false, 'disabled' => false ];
		}
		if ( 'lift' === $kind ) {
			return [ 'color' => 'rgba(0,0,0,0.22)', 'hOffset' => 0, 'vOffset' => 14, 'blur' => 26, 'spread' => -8, 'inset' => false, 'disabled' => false ];
		}
		if ( 'hard' === $kind ) {
			return [ 'color' => 'rgba(0,0,0,0.85)', 'hOffset' => 3, 'vOffset' => 3, 'blur' => 0, 'spread' => 0, 'inset' => false, 'disabled' => false ];
		}
		return $none;
	}

	/* ------------------------------------------------------------------ brand */

	/** Palette (the active Kadence palette option), fonts (theme mods) and logo. Snapshots first. */
	public static function set_brand( $input ) {
		$pal_in = (array) ( $input['palette'] ?? [] );
		$fonts_in = (array) ( $input['fonts'] ?? [] );
		$logo = (int) ( $input['logo_attachment_id'] ?? 0 );
		$keys = [];
		$opt_keys = $pal_in ? [ 'kadence_global_palette' ] : []; // Kadence stores the palette as an option, not a theme mod.
		foreach ( $fonts_in as $k => $_ ) {
			$keys[] = [ 'body' => 'base_font', 'headings' => 'heading_font' ][ $k ] ?? ( preg_match( '/^h[1-6]$/', (string) $k ) ? $k . '_font' : null );
		}
		if ( $logo ) {
			$keys[] = 'custom_logo';
		}
		$keys = array_values( array_unique( array_filter( $keys ) ) );
		if ( ! $keys && ! $opt_keys ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Provide palette, fonts or logo_attachment_id.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-brand', $keys, $opt_keys );
		$changed = [];
		$palette_used = null;

		if ( $pal_in ) {
			$names = [ 'palette1' => '#2B6CB0', 'palette2' => '#215387', 'palette3' => '#1A202C', 'palette4' => '#2D3748', 'palette5' => '#4A5568', 'palette6' => '#718096', 'palette7' => '#EDF2F7', 'palette8' => '#F7FAFC', 'palette9' => '#ffffff', 'palette10' => '#FfFfFf' ];
			$default = [ 'palette' => [], 'second-palette' => [], 'third-palette' => [], 'active' => 'palette' ];
			foreach ( $names as $slug => $color ) {
				$default['palette'][] = [ 'color' => $color, 'slug' => $slug, 'name' => 'palette10' === $slug ? 'Palette Color Complement' : 'Palette Color ' . substr( $slug, 7 ) ];
			}
			$cur = get_option( 'kadence_global_palette' );
			$pal = is_string( $cur ) && is_array( json_decode( $cur, true ) ) ? json_decode( $cur, true ) : $default;
			// Edit the palette the site actually uses (Kadence can switch to its second or third palette).
			$active = in_array( $pal['active'] ?? '', [ 'palette', 'second-palette', 'third-palette' ], true ) ? $pal['active'] : 'palette';
			if ( empty( $pal[ $active ] ) || ! is_array( $pal[ $active ] ) ) {
				$pal[ $active ] = ! empty( $pal['palette'] ) && is_array( $pal['palette'] ) ? $pal['palette'] : $default['palette'];
			}
			$seen = [];
			foreach ( $pal[ $active ] as &$entry ) {
				$seen[] = $entry['slug'] ?? '';
				if ( isset( $entry['slug'], $pal_in[ $entry['slug'] ] ) ) {
					$hex = sanitize_hex_color( $pal_in[ $entry['slug'] ] );
					if ( $hex ) {
						$entry['color'] = $hex;
					}
				}
			}
			unset( $entry );
			foreach ( array_diff( array_keys( $names ), $seen ) as $slug ) { // A palette saved without some slots gains them.
				if ( isset( $pal_in[ $slug ] ) && ( $hex = sanitize_hex_color( $pal_in[ $slug ] ) ) ) {
					$pal[ $active ][] = [ 'color' => $hex, 'slug' => $slug, 'name' => 'Palette Color ' . substr( $slug, 7 ) ];
				}
			}
			update_option( 'kadence_global_palette', wp_json_encode( $pal ) );
			Color::forget(); // Contrast checks read the palette.
			$changed[] = 'palette';
			$palette_used = $active;
		}

		$sys = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"';
		$defaults = [
			'base_font'    => [ 'size' => [ 'desktop' => 17 ], 'lineHeight' => [ 'desktop' => 1.6 ], 'family' => $sys, 'google' => false, 'weight' => '400', 'variant' => 'regular', 'color' => 'palette4' ],
			'heading_font' => [ 'family' => 'inherit', 'google' => false, 'weight' => '700', 'variant' => '700', 'color' => 'palette3' ],
		];
		foreach ( $fonts_in as $k => $spec ) {
			$mod = [ 'body' => 'base_font', 'headings' => 'heading_font' ][ $k ] ?? ( preg_match( '/^h[1-6]$/', (string) $k ) ? $k . '_font' : '' );
			if ( '' === $mod || ! is_array( $spec ) ) {
				continue;
			}
			$font = self::mod( $mod, $defaults[ $mod ] ?? [ 'family' => 'inherit', 'google' => false ] );
			if ( ! empty( $spec['family'] ) ) {
				$fam = sanitize_text_field( $spec['family'] );
				$is_sys = in_array( strtolower( $fam ), [ 'inherit', 'system', 'system-ui', 'sans-serif', 'serif' ], true );
				$font['family'] = $is_sys ? ( 'system' === strtolower( $fam ) ? $sys : $fam ) : $fam;
				$font['google'] = ! $is_sys;
				$font['loadGoogle'] = true;
			}
			if ( isset( $spec['weight'] ) ) {
				$font['weight'] = (string) $spec['weight'];
				$font['variant'] = 'regular' === $font['weight'] ? 'regular' : (string) $spec['weight'];
			}
			if ( isset( $spec['size'] ) ) {
				$font['size'] = array_merge( (array) ( $font['size'] ?? [] ), [ 'desktop' => 0 + $spec['size'] ] );
			}
			if ( isset( $spec['lineHeight'] ) ) {
				$font['lineHeight'] = array_merge( (array) ( $font['lineHeight'] ?? [] ), [ 'desktop' => 0 + $spec['lineHeight'] ] );
			}
			set_theme_mod( $mod, $font ); // Kadence typography settings are stored as arrays (the palette alone is a JSON string).
			$changed[] = $mod;
		}
		if ( $logo && wp_attachment_is_image( $logo ) ) {
			set_theme_mod( 'custom_logo', $logo );
			$changed[] = 'custom_logo';
		}
		$out = [ 'changed' => $changed, 'snapshot' => $snap ];
		if ( $palette_used ) {
			$out['palette_active'] = $palette_used;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ buttons */

	/**
	 * The theme mods a set-buttons input describes (pure: reads the current mods to merge into, writes nothing).
	 * primary/secondary/outline map to the Kadence mods buttons_*, buttons_secondary_*, buttons_outline_*.
	 *
	 * @param array $input set-buttons input.
	 * @return array<string,mixed> mod => value
	 */
	public static function button_mods( array $input ): array {
		$prefix = [ 'primary' => 'buttons', 'secondary' => 'buttons_secondary', 'outline' => 'buttons_outline' ];
		$plan = [];
		foreach ( $prefix as $kind => $p ) {
			$s = (array) ( $input[ $kind ] ?? [] );
			if ( ! $s ) {
				continue;
			}
			// Colours: text, hover text, background, hover background (outline too: Kadence stores an outline background).
			$col = self::mod( $p . '_color', [ 'color' => '', 'hover' => '' ] );
			foreach ( [ 'color' => 'color', 'hover_color' => 'hover' ] as $in => $k ) {
				if ( isset( $s[ $in ] ) && null !== ( $c = self::color( $s[ $in ] ) ) ) {
					$col[ $k ] = $c;
					$plan[ $p . '_color' ] = $col;
				}
			}
			// A text colour without a hover colour: hover keeps it (an empty hover leaves the theme's hover text undefined).
			if ( isset( $plan[ $p . '_color' ] ) && ! isset( $s['hover_color'] ) && '' === (string) ( $col['hover'] ?? '' ) ) {
				$plan[ $p . '_color' ]['hover'] = $col['color'];
			}
			$bg = self::mod( $p . '_background', [ 'color' => '', 'hover' => '' ] );
			foreach ( [ 'background' => 'color', 'hover_background' => 'hover' ] as $in => $k ) {
				if ( isset( $s[ $in ] ) && null !== ( $c = self::color( $s[ $in ] ) ) ) {
					$bg[ $k ] = $c;
					$plan[ $p . '_background' ] = $bg;
				}
			}
			// Border: Kadence reads a responsive border ({desktop: {width, unit, style, color}}); a flat array is ignored.
			if ( isset( $s['border_width'] ) || isset( $s['border_color'] ) ) {
				$cur = self::mod( $p . '_border', [] );
				$d = is_array( $cur['desktop'] ?? null ) ? $cur['desktop'] : [];
				$w = isset( $s['border_width'] ) ? self::px( $s['border_width'], 0, 12 ) : (int) ( $d['width'] ?? 2 );
				$c = isset( $s['border_color'] ) ? self::color( $s['border_color'] ) : null;
				$cur['desktop'] = [ 'width' => $w, 'unit' => 'px', 'style' => $w ? 'solid' : 'none', 'color' => $c ?? (string) ( $d['color'] ?? '' ) ];
				$plan[ $p . '_border' ] = $cur;
				if ( null !== $c ) {
					$hc = isset( $s['hover_border_color'] ) ? self::color( $s['hover_border_color'] ) : null;
					$plan[ $p . '_border_colors' ] = [ 'color' => $c, 'hover' => $hc ?? $c ];
				}
			}
			// Corners.
			if ( isset( $s['radius'] ) ) {
				$r = 'pill' === $s['radius'] ? 999 : self::px( $s['radius'], 0, 999 );
				$plan[ $p . '_border_radius' ] = [ 'size' => [ 'mobile' => '', 'tablet' => '', 'desktop' => $r ], 'unit' => [ 'mobile' => 'px', 'tablet' => 'px', 'desktop' => 'px' ] ];
			}
			// Padding.
			if ( isset( $s['padding'] ) ) {
				$presets = [ 'sm' => [ 8, 18 ], 'md' => [ 12, 26 ], 'lg' => [ 16, 34 ], 'xl' => [ 20, 42 ] ];
				$vh = is_array( $s['padding'] ) ? [ self::px( $s['padding'][0] ?? 12, 0, 80 ), self::px( $s['padding'][1] ?? 26, 0, 120 ) ] : ( $presets[ (string) $s['padding'] ] ?? null );
				if ( $vh ) {
					$plan[ $p . '_padding' ] = [ 'size' => [ 'desktop' => [ $vh[0], $vh[1], $vh[0], $vh[1] ] ], 'unit' => [ 'desktop' => 'px' ], 'locked' => [ 'desktop' => false ] ];
				}
			}
			// Type.
			if ( isset( $s['weight'] ) || isset( $s['size'] ) || isset( $s['uppercase'] ) ) {
				$t = self::mod( $p . '_typography', [ 'size' => [ 'desktop' => '' ], 'lineHeight' => [ 'desktop' => '' ], 'family' => 'inherit', 'google' => false, 'weight' => '', 'variant' => '' ] );
				if ( isset( $s['weight'] ) ) {
					$w = (string) self::px( $s['weight'], 100, 900 );
					$t['weight'] = $w;
					$t['variant'] = $w;
				}
				if ( isset( $s['size'] ) ) {
					$t['size'] = array_merge( (array) ( $t['size'] ?? [] ), [ 'desktop' => self::px( $s['size'], 10, 40 ) ] );
				}
				if ( isset( $s['uppercase'] ) ) {
					$t['transform'] = $s['uppercase'] ? 'uppercase' : 'none';
				}
				$plan[ $p . '_typography' ] = $t;
			}
			// Shadow: soft keeps the same soft shadow on hover, lift grows it on hover, hard is an offset block shadow.
			if ( isset( $s['shadow'] ) && in_array( $s['shadow'], [ 'none', 'soft', 'lift', 'hard' ], true ) ) {
				$base = 'lift' === $s['shadow'] ? 'soft' : $s['shadow'];
				$plan[ $p . '_shadow' ] = self::shadow( $base );
				$plan[ $p . '_shadow_hover' ] = self::shadow( 'lift' === $s['shadow'] ? 'lift' : $base );
			}
		}
		return $plan;
	}

	public static function set_buttons( $input ) {
		$input = (array) $input;
		$plan = self::button_mods( $input );
		if ( ! $plan ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Give primary, secondary and/or outline settings.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-buttons', self::BUTTON_MODS );
		foreach ( $plan as $k => $v ) {
			set_theme_mod( $k, $v );
		}
		Cache::purge_later();
		$issues = self::button_contrast();
		return [
			'changed'  => array_keys( $plan ),
			'snapshot' => $snap,
			'note'     => 'Buttons set to "Theme Base" in patterns follow the primary style; "Theme Secondary" follows secondary.',
			'contrast' => [ 'ok' => ! $issues, 'issues' => $issues ],
		];
	}

	/** Text on the primary and secondary theme buttons, normal and hover, below WCAG AA (4.5:1). @return string[] */
	public static function button_contrast(): array {
		$out = [];
		foreach ( [ 'primary' => 'buttons', 'secondary' => 'buttons_secondary' ] as $kind => $p ) {
			$col = self::mod( $p . '_color' );
			$bg = self::mod( $p . '_background' );
			foreach ( [ 'color' => 'normal', 'hover' => 'hover' ] as $k => $state ) {
				$text = (string) ( $col[ $k ] ?? '' );
				$back = (string) ( $bg[ $k ] ?? '' );
				if ( '' === $text || '' === $back || ! Color::hex( $text ) || ! Color::hex( $back ) ) {
					continue; // Theme defaults or transparent: nothing to measure.
				}
				$ratio = Color::contrast( $text, $back );
				if ( $ratio < Color::MIN_TEXT ) {
					$out[] = sprintf( '%s button, %s: text %s on %s is %.2f:1 (needs %.1f:1).', $kind, $state, $text, $back, $ratio, Color::MIN_TEXT );
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ menu + dropdown */

	private static function nav_shadow( string $kind ): array {
		if ( 'none' === $kind ) {
			return [ [ 'enable' => false, 'color' => '#000000', 'opacity' => 0.1, 'spread' => 0, 'blur' => 13, 'hOffset' => 0, 'vOffset' => 2, 'inset' => false ] ];
		}
		$strong = 'strong' === $kind;
		return [ [ 'enable' => true, 'color' => '#000000', 'opacity' => $strong ? 0.22 : 0.12, 'spread' => 0, 'blur' => $strong ? 34 : 24, 'hOffset' => 0, 'vOffset' => $strong ? 14 : 10, 'inset' => false ] ];
	}

	private static function nav_typo( array $cur, array $s ): array {
		$t = $cur ? $cur : [ 'size' => [ '', '', '' ], 'sizeType' => 'px', 'lineHeight' => [ '', '', '' ], 'lineType' => '', 'letterSpacing' => [ '', '', '' ], 'letterType' => 'px', 'textTransform' => '', 'family' => '', 'google' => false, 'style' => '', 'weight' => '', 'variant' => '', 'subset' => '', 'loadGoogle' => true ];
		if ( isset( $s['size'] ) ) {
			$t['size'] = [ self::px( $s['size'], 10, 40 ), '', '' ];
		}
		if ( isset( $s['weight'] ) ) {
			$t['weight'] = (string) self::px( $s['weight'], 100, 900 );
		}
		if ( isset( $s['uppercase'] ) ) {
			$t['textTransform'] = $s['uppercase'] ? 'uppercase' : 'none';
		}
		return $t;
	}

	public static function set_nav_style( $input ) {
		$links = (array) ( $input['links'] ?? [] );
		$dd = (array) ( $input['dropdown'] ?? [] );
		$theme = ! empty( $input['theme'] );
		if ( ! $links && ! $dd ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Give links and/or dropdown settings.', [ 'status' => 400 ] );
		}
		$meta = [];
		$mods = [];

		foreach ( [ 'color' => 'linkColor', 'hover' => 'linkColorHover', 'active' => 'linkColorActive' ] as $in => $m ) {
			if ( isset( $links[ $in ] ) && null !== ( $c = self::color( $links[ $in ] ) ) ) {
				$meta[ '_kad_navigation_' . $m ] = $c;
			}
		}
		if ( isset( $links['style'] ) && in_array( $links['style'], [ 'standard', 'underline', 'underline-fullheight', 'bold' ], true ) ) {
			$meta['_kad_navigation_style'] = $links['style'];
		}
		foreach ( [ 'background' => 'dropdownBg', 'hover_background' => 'dropdownBgHover', 'active_background' => 'dropdownBgActive', 'color' => 'dropdownColor', 'hover_color' => 'dropdownColorHover', 'active_color' => 'dropdownColorActive' ] as $in => $alias ) {
			if ( isset( $dd[ $in ] ) && null !== ( $c = self::color( $dd[ $in ] ) ) ) {
				$map = [ 'dropdownBg' => '_kad_navigation_backgroundDropdown', 'dropdownBgHover' => '_kad_navigation_backgroundDropdownHover', 'dropdownBgActive' => '_kad_navigation_backgroundDropdownActive', 'dropdownColor' => '_kad_navigation_linkColorDropdown', 'dropdownColorHover' => '_kad_navigation_linkColorDropdownHover', 'dropdownColorActive' => '_kad_navigation_linkColorDropdownActive' ];
				$meta[ $map[ $alias ] ] = $c;
			}
		}
		if ( isset( $dd['width'] ) ) {
			$meta['_kad_navigation_dropdownWidth'] = (string) self::px( $dd['width'], 120, 600 );
			$meta['_kad_navigation_dropdownWidthUnit'] = 'px';
		}
		if ( isset( $dd['radius'] ) ) {
			$r = self::px( $dd['radius'], 0, 60 );
			$meta['_kad_navigation_dropdownBorderRadius'] = [ $r, $r, $r, $r ];
			$meta['_kad_navigation_dropdownBorderRadiusUnit'] = 'px';
		}
		if ( isset( $dd['shadow'] ) && in_array( $dd['shadow'], [ 'none', 'soft', 'strong' ], true ) ) {
			$meta['_kad_navigation_dropdownShadow'] = self::nav_shadow( $dd['shadow'] );
		}
		if ( isset( $dd['reveal'] ) && in_array( $dd['reveal'], [ 'none', 'fade', 'fade-up', 'fade-down' ], true ) ) {
			$meta['_kad_navigation_dropdownReveal'] = $dd['reveal'];
		}
		if ( isset( $dd['divider'] ) ) {
			$dc = is_string( $dd['divider'] ) ? self::color( $dd['divider'] ) : ( $dd['divider'] ? 'rgba(0,0,0,0.08)' : null );
			$none = [ '', '', '' ];
			$meta['_kad_navigation_dropdownDivider'] = [ [ 'top' => $none, 'right' => $none, 'bottom' => $dc ? [ $dc, 'solid', 1 ] : $none, 'left' => $none, 'unit' => 'px' ] ];
		}
		if ( isset( $dd['padding'] ) && is_array( $dd['padding'] ) ) {
			$v = self::px( $dd['padding'][0] ?? 10, 0, 60 );
			$h = self::px( $dd['padding'][1] ?? 18, 0, 80 );
			$meta['_kad_navigation_paddingDropdownLink'] = [ (string) $v, (string) $h, (string) $v, (string) $h ];
			$meta['_kad_navigation_paddingDropdownLinkUnit'] = 'px';
		}
		if ( isset( $dd['spacing'] ) ) {
			$meta['_kad_navigation_dropdownVerticalSpacing'] = (string) self::px( $dd['spacing'], 0, 60 );
		}
		if ( isset( $dd['align'] ) && in_array( $dd['align'], [ 'left', 'center', 'right' ], true ) ) {
			$meta['_kad_navigation_dropdownLinkHorizontalAlignment'] = $dd['align'];
		}

		// Menus to style: one key, or every menu this plugin made.
		$q = [ 'post_type' => 'kadence_navigation', 'post_status' => 'any', 'numberposts' => 20, 'fields' => 'ids', 'meta_key' => '_lb_key' ];
		if ( ! empty( $input['key'] ) ) {
			$q['meta_value'] = 'nav-' . sanitize_key( $input['key'] );
		}
		$ids = post_type_exists( 'kadence_navigation' ) ? get_posts( $q ) : [];
		if ( empty( $input['key'] ) ) {
			$ids = array_values( array_filter( $ids, static function ( $id ) { return 0 === strpos( (string) get_post_meta( $id, '_lb_key', true ), 'nav-' ); } ) );
		}
		foreach ( $ids as $id ) { // Typography is merged into what the menu already has.
			if ( isset( $links['size'] ) || isset( $links['weight'] ) || isset( $links['uppercase'] ) ) {
				$cur = get_post_meta( $id, '_kad_navigation_typography', true );
				$meta_t = self::nav_typo( is_array( $cur ) && isset( $cur[0] ) ? $cur[0] : [], $links );
				update_post_meta( $id, '_kad_navigation_typography', [ $meta_t ] );
			}
			if ( isset( $dd['size'] ) ) {
				$cur = get_post_meta( $id, '_kad_navigation_dropdownTypography', true );
				update_post_meta( $id, '_kad_navigation_dropdownTypography', [ self::nav_typo( is_array( $cur ) && isset( $cur[0] ) ? $cur[0] : [], [ 'size' => $dd['size'] ] ) ] );
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( $id, $k, $v );
			}
		}

		// The Customizer dropdown (used by non-block headers). Optional.
		if ( $theme ) {
			$cur_c = self::mod( 'dropdown_navigation_color', [ 'color' => 'palette8', 'hover' => 'palette9', 'active' => 'palette9' ] );
			$cur_b = self::mod( 'dropdown_navigation_background', [ 'color' => 'palette3', 'hover' => 'palette4', 'active' => 'palette4' ] );
			foreach ( [ 'color' => 'color', 'hover_color' => 'hover', 'active_color' => 'active' ] as $in => $k ) {
				if ( isset( $dd[ $in ] ) && null !== ( $c = self::color( $dd[ $in ] ) ) ) {
					$cur_c[ $k ] = $c;
					$mods['dropdown_navigation_color'] = $cur_c;
				}
			}
			foreach ( [ 'background' => 'color', 'hover_background' => 'hover', 'active_background' => 'active' ] as $in => $k ) {
				if ( isset( $dd[ $in ] ) && null !== ( $c = self::color( $dd[ $in ] ) ) ) {
					$cur_b[ $k ] = $c;
					$mods['dropdown_navigation_background'] = $cur_b;
				}
			}
			if ( isset( $dd['width'] ) ) {
				$mods['dropdown_navigation_width'] = [ 'size' => self::px( $dd['width'], 120, 600 ), 'unit' => 'px' ];
			}
			if ( isset( $dd['radius'] ) ) {
				$r = self::px( $dd['radius'], 0, 60 );
				$mods['dropdown_navigation_border_radius'] = [ 'size' => [ $r, $r, $r, $r ], 'unit' => 'px', 'locked' => true ];
			}
			if ( isset( $dd['reveal'] ) && in_array( $dd['reveal'], [ 'none', 'fade', 'fade-up', 'fade-down' ], true ) ) {
				$mods['dropdown_navigation_reveal'] = $dd['reveal'];
			}
			if ( isset( $dd['shadow'] ) && in_array( $dd['shadow'], [ 'none', 'soft', 'strong' ], true ) ) {
				$mods['dropdown_navigation_shadow'] = 'none' === $dd['shadow'] ? [ 'color' => 'rgba(0,0,0,0)', 'hOffset' => 0, 'vOffset' => 0, 'blur' => 0, 'spread' => 0, 'inset' => false, 'disabled' => true ]
					: [ 'color' => 'rgba(0,0,0,' . ( 'strong' === $dd['shadow'] ? '0.22' : '0.12' ) . ')', 'hOffset' => 0, 'vOffset' => 'strong' === $dd['shadow'] ? 14 : 10, 'blur' => 'strong' === $dd['shadow'] ? 34 : 24, 'spread' => 0, 'inset' => false, 'disabled' => false ];
			}
			if ( isset( $dd['divider'] ) ) {
				$dc = is_string( $dd['divider'] ) ? self::color( $dd['divider'] ) : ( $dd['divider'] ? 'rgba(0,0,0,0.08)' : null );
				$mods['dropdown_navigation_divider'] = [ 'width' => $dc ? 1 : 0, 'unit' => 'px', 'style' => 'solid', 'color' => $dc ?: 'rgba(0,0,0,0)' ];
			}
			if ( isset( $dd['size'] ) ) {
				$t = self::mod( 'dropdown_navigation_typography', [ 'size' => [ 'desktop' => '' ], 'family' => 'inherit', 'google' => false ] );
				$t['size'] = array_merge( (array) ( $t['size'] ?? [] ), [ 'desktop' => self::px( $dd['size'], 10, 40 ) ] );
				$mods['dropdown_navigation_typography'] = $t;
			}
		}
		if ( ! $ids && ! $mods ) {
			return new \WP_Error( 'lb_no_nav', 'No menu from this plugin to style yet. Run set-navigation or set-header with nav items first (or pass theme:true for the Customizer dropdown).', [ 'status' => 404 ] );
		}
		$snap = $mods ? Abilities::snapshot( 'set-nav-style', array_keys( $mods ) ) : null;
		foreach ( $mods as $k => $v ) {
			set_theme_mod( $k, $v );
		}
		$out = [ 'menus' => $ids, 'menu_settings' => array_keys( $meta ), 'theme_settings' => array_keys( $mods ), 'snapshot' => $snap ];
		if ( isset( $dd['radius'] ) ) {
			// The style DNA CSS gives the first and last dropdown links the panel's corners (block navigation) and the
			// Customizer dropdown panel the item radius, so hovering an end item never shows square corners.
			$out['dropdown_corners'] = 'rounded: first/last items follow the panel radius';
			Cache::purge_later();
		}
		return $out;
	}

	/* ------------------------------------------------------------------ page title + layout */

	public static function set_page_style( $input ) {
		$preset = (string) ( $input['preset'] ?? '' );
		$title = (array) ( $input['title'] ?? [] );
		$content = (array) ( $input['content'] ?? [] );
		$site = (array) ( $input['site'] ?? [] );
		if ( 'banner' === $preset ) {
			$title += [ 'show' => true, 'layout' => 'above', 'align' => 'left', 'height' => 200, 'background' => 'palette7', 'color' => 'palette3', 'breadcrumbs' => true ];
			$content += [ 'style' => 'unboxed', 'width' => 'normal', 'padding' => 'show' ];
		} elseif ( 'plain' === $preset ) {
			$title += [ 'show' => true, 'layout' => 'normal', 'align' => 'left', 'breadcrumbs' => false ];
			$content += [ 'style' => 'unboxed', 'width' => 'normal', 'padding' => 'show' ];
		}
		$plan = [];

		if ( isset( $title['show'] ) ) {
			$plan['page_title'] = (bool) $title['show'];
		}
		if ( isset( $title['layout'] ) && in_array( $title['layout'], [ 'above', 'normal' ], true ) ) {
			$plan['page_title_layout'] = $title['layout'];
		}
		if ( isset( $title['align'] ) && in_array( $title['align'], [ 'left', 'center', 'right' ], true ) ) {
			$plan['page_title_align'] = [ 'mobile' => '', 'tablet' => '', 'desktop' => $title['align'] ];
		}
		if ( isset( $title['height'] ) ) {
			$plan['page_title_height'] = [ 'size' => [ 'mobile' => '', 'tablet' => '', 'desktop' => self::px( $title['height'], 60, 700 ) ], 'unit' => [ 'mobile' => 'px', 'tablet' => 'px', 'desktop' => 'px' ] ];
		}
		if ( isset( $title['background'] ) && null !== ( $c = self::color( $title['background'] ) ) ) {
			$plan['page_title_background'] = [ 'desktop' => [ 'color' => $c ] ];
		}
		if ( isset( $title['overlay'] ) && null !== ( $c = self::color( $title['overlay'] ) ) ) {
			$plan['page_title_overlay_color'] = [ 'color' => $c ]; // Kadence reads sub_option( 'page_title_overlay_color', 'color' ).
		}
		if ( isset( $title['color'] ) && null !== ( $c = self::color( $title['color'] ) ) ) {
			$font = self::mod( 'page_title_font', [ 'size' => [ 'desktop' => '' ], 'lineHeight' => [ 'desktop' => '' ], 'family' => 'inherit', 'google' => false, 'weight' => '', 'variant' => '', 'color' => '' ] );
			$font['color'] = $c;
			$plan['page_title_font'] = $font;
		}
		$notes = [];
		if ( isset( $title['breadcrumbs'] ) ) {
			$bc = self::mod( 'page_title_element_breadcrumb', [ 'enabled' => false, 'show_title' => true ] );
			$want = (bool) $title['breadcrumbs'];
			if ( $want && Design_Hub::placed( 'breadcrumbs' ) ) {
				// One breadcrumb trail per page: the Design hub's breadcrumb bar is placed, so Kadence's stay off.
				$want = false;
				$notes[] = 'Kadence title-bar breadcrumbs left off: the Design hub\'s breadcrumb bar is placed.';
			}
			$bc['enabled'] = $want;
			$plan['page_title_element_breadcrumb'] = $bc;
		}
		if ( isset( $content['width'] ) && in_array( $content['width'], [ 'normal', 'narrow', 'fullwidth', 'left', 'right' ], true ) ) {
			$plan['page_layout'] = $content['width'];
		}
		if ( isset( $content['style'] ) && in_array( $content['style'], [ 'boxed', 'unboxed' ], true ) ) {
			$plan['page_content_style'] = $content['style'];
		}
		if ( isset( $content['padding'] ) && in_array( $content['padding'], [ 'show', 'hide' ], true ) ) {
			$plan['page_vertical_padding'] = $content['padding'];
		}
		if ( isset( $site['content_width'] ) ) {
			$plan['content_width'] = [ 'size' => self::px( $site['content_width'], 600, 2000 ), 'unit' => 'px' ];
		}
		if ( isset( $site['edge_spacing'] ) ) {
			$plan['content_edge_spacing'] = [ 'size' => [ 'mobile' => '', 'tablet' => '', 'desktop' => max( 0, min( 6, (float) $site['edge_spacing'] ) ) ], 'unit' => [ 'mobile' => 'rem', 'tablet' => 'rem', 'desktop' => 'rem' ] ];
		}
		if ( ! $plan ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Give a preset, title, content or site settings.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-page-style', array_keys( $plan ) );
		foreach ( $plan as $k => $v ) {
			set_theme_mod( $k, $v );
		}
		$out = [ 'changed' => array_keys( $plan ), 'snapshot' => $snap ];
		if ( $notes ) {
			$out['notes'] = $notes;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ block defaults */

	/** The recommended defaults: theme-base buttons, theme-width rows, stylised icon lists. */
	public static function standard_defaults(): array {
		return [
			'kadence/singlebtn' => [ 'inheritStyles' => 'inherit' ],
			'kadence/rowlayout' => [ 'inheritMaxWidth' => true ],
			'kadence/iconlist'  => [ 'icon' => 'fe_checkCircle', 'listGap' => 12 ],
			'kadence/listitem'  => [ 'icon' => 'fe_checkCircle', 'color' => 'palette1', 'showIcon' => true ],
		];
	}

	public static function set_block_defaults( $input ) {
		$cur = json_decode( (string) get_option( 'kadence_blocks_config_blocks', '' ), true );
		$cur = is_array( $cur ) ? $cur : [];
		if ( ! empty( $input['reset'] ) ) {
			$cur = [];
		}
		$add = [];
		if ( 'standard' === ( $input['preset'] ?? '' ) ) {
			$add = self::standard_defaults();
		}
		foreach ( (array) ( $input['defaults'] ?? [] ) as $block => $attrs ) {
			if ( is_string( $block ) && preg_match( '#^kadence/[a-z0-9-]+$#', $block ) && is_array( $attrs ) ) {
				$add[ $block ] = array_merge( (array) ( $add[ $block ] ?? [] ), $attrs );
			}
		}
		if ( ! $add && empty( $input['reset'] ) ) {
			return new \WP_Error( 'lb_empty', 'Give preset "standard", defaults, or reset:true.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-block-defaults', [], [ 'kadence_blocks_config_blocks' ] ); // Only once the input is valid.
		foreach ( $add as $block => $attrs ) {
			$cur[ $block ] = array_merge( (array) ( $cur[ $block ] ?? [] ), $attrs );
		}
		// Unescaped slashes, as Kadence's own settings screen saves it ("kadence/iconlist", not "kadence\/iconlist").
		update_option( 'kadence_blocks_config_blocks', $cur ? (string) wp_json_encode( $cur, JSON_UNESCAPED_SLASHES ) : '' );
		return [ 'blocks' => array_keys( $cur ), 'snapshot' => $snap, 'note' => 'New Kadence blocks inserted in the editor start with these. Existing blocks and patterns are unchanged.' ];
	}
}
