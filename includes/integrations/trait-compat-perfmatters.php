<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: Perfmatters: the exclusions Launch Builder adds through its filters and the risk report.
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Perfmatters {

	/* ------------------------------------------------------------------ Perfmatters */

	/** What Launch Builder adds to Perfmatters through its filters ([] for a list turns it off). */
	public static function pm_lists(): array {
		$lists = [ 'rucss' => self::PM_RUCSS_SELECTORS, 'delay' => self::PM_DELAY_EXCLUSIONS, 'lazy' => self::PM_LAZY_EXCLUSIONS, 'rest' => [ '/launch-builder/v1' ] ];
		$lists = apply_filters( 'launch_builder_perfmatters_exclusions', $lists );
		return is_array( $lists ) ? $lists : [];
	}

	private static function pm_merge( $list, string $which ): array {
		$add = (array) ( self::pm_lists()[ $which ] ?? [] );
		return array_values( array_unique( array_merge( is_array( $list ) ? $list : [], array_map( 'strval', $add ) ) ) );
	}

	public static function pm_rucss( $selectors ) {
		return self::pm_merge( $selectors, 'rucss' );
	}

	public static function pm_delay( $exclusions ) {
		return self::pm_merge( $exclusions, 'delay' );
	}

	public static function pm_lazy( $attributes ) {
		return self::pm_merge( $attributes, 'lazy' );
	}

	/** Perfmatters "Disable REST API" never blocks Launch Builder's own routes (each checks its own permissions). */
	public static function pm_rest( $exceptions ) {
		return self::pm_merge( $exceptions, 'rest' );
	}

	/**
	 * Read perfmatters_options and report every setting that can break a Launch Builder site, with what Launch Builder
	 * already handles through Perfmatters' filters and what the site owner must change.
	 *
	 * @return array{active:bool,version:string,auto:array,risks:array,settings:array}
	 */
	public static function perfmatters_report(): array {
		$o = get_option( 'perfmatters_options', [] );
		$o = is_array( $o ) ? $o : [];
		$a = (array) ( $o['assets'] ?? [] );
		$l = (array) ( $o['lazyload'] ?? [] );
		$f = (array) ( $o['fonts'] ?? [] );
		$lists = self::pm_lists();
		$on = static function ( $v ): bool { return ! empty( $v ) && '0' !== (string) $v; };
		$lines = static function ( $v ): array { return array_values( array_filter( array_map( 'trim', is_array( $v ) ? $v : preg_split( '/\r?\n/', (string) $v ) ) ) ); };
		$risks = [];
		$risk = static function ( string $code, string $severity, string $setting, string $message, string $fix ) use ( &$risks ) {
			$risks[] = compact( 'code', 'severity', 'setting', 'message', 'fix' );
		};

		if ( $on( $a['remove_unused_css'] ?? '' ) ) {
			$missing = array_values( array_diff( self::PM_RUCSS_SELECTORS, array_merge( (array) ( $lists['rucss'] ?? [] ), $lines( $a['rucss_excluded_selectors'] ?? '' ) ) ) );
			$missing
				? $risk( 'rucss_selectors', 'warning', 'assets.remove_unused_css', 'Remove Unused CSS strips states that Kadence adds with JavaScript (mobile drawer, dropdowns, sticky header, accordion, tabs, sliders, modals, Conversions popups).', 'Add to Excluded Selectors, one per line: ' . implode( ' ', $missing ) )
				: $risk( 'rucss_selectors', 'info', 'assets.remove_unused_css', 'Remove Unused CSS is on; Launch Builder adds the Kadence and Kadence Conversions state selectors through perfmatters_rucss_excluded_selectors.', 'None. After changing styles or chrome, Launch Builder clears the used CSS.' );
		}
		if ( $on( $a['delay_js'] ?? '' ) ) {
			if ( 'all' === ( $a['delay_js_behavior'] ?? '' ) ) {
				$missing = array_values( array_diff( self::PM_DELAY_EXCLUSIONS, array_merge( (array) ( $lists['delay'] ?? [] ), $lines( $a['delay_js_exclusions'] ?? '' ) ) ) );
				$missing
					? $risk( 'delay_js', 'warning', 'assets.delay_js', 'Delay All Scripts holds back the form embed, Kadence menus/sliders/modals and Kadence Conversions until the first interaction.', 'Add to Excluded from Delay: ' . implode( ' ', $missing ) )
					: $risk( 'delay_js', 'info', 'assets.delay_js', 'Delay All Scripts is on; Launch Builder excludes the form embed, Kadence menus/sliders/modals and Kadence Conversions through perfmatters_delay_js_exclusions.', 'None.' );
			} else {
				$bad = array_values( array_filter( $lines( $a['delay_js_inclusions'] ?? [] ), static function ( $s ) { return (bool) preg_match( '/form_embed|leadconnector|msgsndr|kadence|kt-|kb-|splide|conversions/i', $s ); } ) );
				if ( $bad ) {
					$risk( 'delay_js_inclusions', 'warning', 'assets.delay_js_inclusions', 'Delayed Scripts includes scripts that forms, menus or popups need on load: ' . implode( ', ', $bad ) . '.', 'Remove those lines from Delayed Scripts.' );
				}
			}
		}
		if ( $on( $l['lazy_loading_iframes'] ?? '' ) ) {
			$risk( 'lazy_iframes', ( $lists['lazy'] ?? [] ) ? 'info' : 'warning', 'lazyload.lazy_loading_iframes', 'Lazy loading iframes delays the form embed iframe.', ( $lists['lazy'] ?? [] ) ? 'None: Launch Builder excludes leadconnectorhq/msgsndr/form_embed iframes.' : 'Add leadconnectorhq and msgsndr to Exclude from Lazy Loading.' );
		}
		if ( $on( $l['lazy_loading'] ?? '' ) && (int) ( $l['exclude_leading_images'] ?? 0 ) < 2 ) {
			$risk( 'leading_images', 'warning', 'lazyload.exclude_leading_images', 'Images are lazy-loaded from the first one, including the logo and the hero image (Largest Contentful Paint).', 'Set Exclude Leading Images to 2 or more. (The first hero image already gets fetchpriority=high, which Perfmatters never lazy-loads.)' );
		}
		if ( $on( $l['css_background_images'] ?? '' ) && (int) ( $l['css_background_exclude_leading'] ?? 0 ) < 1 ) {
			$sel = implode( ' ', $lines( $l['css_background_selectors'] ?? [] ) );
			if ( preg_match( '/kt-row-has-bg|kb-row-layout|rowlayout|hero|kt-inside-inner-col/i', $sel ) ) {
				$risk( 'css_background', 'warning', 'lazyload.css_background_images', 'Lazy CSS background images (' . $sel . ') delays hero row backgrounds.', 'Set Exclude Leading Elements (CSS background images) to 1 or more, or remove the row selector.' );
			}
		}
		if ( $on( $o['disable_google_maps'] ?? '' ) ) {
			$risk( 'google_maps', 'info', 'disable_google_maps', 'Disable Google Maps removes the Maps JavaScript API. Launch Builder map patterns are iframe embeds and keep working; a Kadence Google Maps block in JavaScript mode stops.', 'Keep map sections as iframe embeds.' );
		}
		if ( $on( $f['local_google_fonts'] ?? '' ) ) {
			$risk( 'local_fonts', 'warning', 'fonts.local_google_fonts', 'Perfmatters Local Fonts processes the Google Fonts that Kadence already loads locally (Kadence "Load Google Fonts Locally" owns fonts on Launch Builder sites).', 'Turn Perfmatters Local Fonts off.' );
		}
		if ( $on( $f['disable_google_fonts'] ?? '' ) && ! get_theme_mod( 'load_fonts_local', false ) ) {
			$risk( 'google_fonts_off', 'error', 'fonts.disable_google_fonts', 'Google Fonts are disabled and Kadence is not loading them locally: the brand fonts fall back to system fonts.', 'Turn on Kadence "Load Google Fonts Locally" (sync-seo does) or turn Disable Google Fonts off.' );
		}
		if ( in_array( (string) ( $o['disable_rest_api'] ?? '' ), [ 'disable_non_admins', 'disable_logged_out' ], true ) ) {
			$risk( 'rest_api', 'disable_non_admins' === $o['disable_rest_api'] ? 'warning' : 'info', 'disable_rest_api', 'Disable REST API blocks /wp-json for ' . ( 'disable_non_admins' === $o['disable_rest_api'] ? 'everyone but administrators (editors lose the block editor)' : 'logged-out visitors' ) . '. Launch Builder\'s own routes are added to Perfmatters\' exceptions.', 'Connect MCP clients to /mcp (not a /wp-json URL). Prefer "Disable when logged out".' );
		}
		if ( $on( $o['remove_global_styles'] ?? '' ) ) {
			$risk( 'global_styles', 'warning', 'remove_global_styles', 'Remove Global Styles drops WordPress\'s layout and preset CSS (core buttons, columns, group gaps and has-*-color classes used by core blocks).', 'Check pages built from core blocks after turning it on, or keep it off.' );
		}
		if ( $on( $o['disable_embeds'] ?? '' ) ) {
			$risk( 'embeds', 'info', 'disable_embeds', 'Disable Embeds turns off oEmbed: core/embed blocks (YouTube, Vimeo) render as plain links.', 'Use video blocks with an iframe or keep embeds on where videos are used.' );
		}
		$settings = [
			'remove_unused_css' => $on( $a['remove_unused_css'] ?? '' ), 'delay_js' => $on( $a['delay_js'] ?? '' ), 'delay_js_behavior' => (string) ( $a['delay_js_behavior'] ?? '' ),
			'lazy_loading' => $on( $l['lazy_loading'] ?? '' ), 'lazy_loading_iframes' => $on( $l['lazy_loading_iframes'] ?? '' ), 'exclude_leading_images' => (int) ( $l['exclude_leading_images'] ?? 0 ),
			'css_background_images' => $on( $l['css_background_images'] ?? '' ), 'local_google_fonts' => $on( $f['local_google_fonts'] ?? '' ), 'disable_google_maps' => $on( $o['disable_google_maps'] ?? '' ),
			'disable_rest_api' => (string) ( $o['disable_rest_api'] ?? '' ), 'remove_global_styles' => $on( $o['remove_global_styles'] ?? '' ),
		];
		return [
			'active'       => self::pm_active(),
			'version'      => defined( 'PERFMATTERS_VERSION' ) ? (string) PERFMATTERS_VERSION : '',
			'option_found' => (bool) $o,
			'auto'         => [ 'rucss_selectors' => count( (array) ( $lists['rucss'] ?? [] ) ), 'delay_exclusions' => (array) ( $lists['delay'] ?? [] ), 'lazy_exclusions' => (array) ( $lists['lazy'] ?? [] ), 'rest_exceptions' => (array) ( $lists['rest'] ?? [] ) ],
			'risks'        => $risks,
			'settings'     => $settings,
		];
	}
}
