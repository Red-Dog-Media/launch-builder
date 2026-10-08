<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Chrome: the shared designs every page of a role gets (header, notice bar, hero, crumbs, entry template, 404 body,
 * before-footer stack, footer, mobile sticky bar) and where each one is placed.
 *
 * The map, slot table and mode are readable; nothing is placed or compiled yet and nothing reads these on the
 * front end.
 *
 * Map (option launch_builder_chrome_map) = DEFAULT_MAP, then the style preset's defaults (Style::chrome_defaults()),
 * then the stored map, merged per slot and, for role-keyed slots and crumbs, per key. Role-keyed slots take
 * { default: DESIGN, <role>: DESIGN, ... }; crumbs is { design, roles[] }; notice and notfound_body are one DESIGN.
 * DESIGN = "pattern-slug" | {pattern, slots} | {sections:[...]} | {synced: key} | {adopt: element_id} | "theme" | false
 *   "theme" = keep Kadence's own title banner/header/footer for that role; false = nothing (for hero: title hidden).
 *
 * Mode: option launch_builder_chrome = 'auto' (default) | 'elements' | 'patterns' is the only stored preference;
 * mode() says what is actually used (elements needs Theme Kit Pro with its Elements module on).
 */
final class Chrome {

	const OPT_MODE = 'launch_builder_chrome';
	const OPT_MAP  = 'launch_builder_chrome_map';

	/**
	 * Slot => placement. hook/type/priority are the Kadence Pro element settings in elements mode ('custom' hooks also
	 * write _kad_element_hook_custom); patterns_mode says how the same design is printed without Pro.
	 */
	const SLOTS = [
		'header'        => [ 'hook' => 'replace_header', 'type' => 'template', 'priority' => 10, 'patterns_mode' => 'filter blocks_header_id per role / remove the theme header' ],
		'notice'        => [ 'hook' => 'kadence_before_header', 'type' => 'default', 'priority' => 10, 'expires' => true, 'patterns_mode' => 'action kadence_before_header' ],
		'hero'          => [ 'hook' => 'replace_hero_header', 'type' => 'template', 'priority' => 10, 'patterns_mode' => 'remove Kadence\hero_title, print on kadence_hero_header' ],
		'crumbs'        => [ 'hook' => 'custom', 'custom_hook' => 'kadence_hero_header', 'type' => 'default', 'priority' => 20, 'patterns_mode' => 'action kadence_hero_header, priority 20' ],
		'template'      => [ 'hook' => 'replace_single_content', 'type' => 'template', 'priority' => 10, 'patterns_mode' => 'Entries::wrap (the_content)' ],
		'notfound_body' => [ 'hook' => 'replace_404', 'type' => 'template', 'priority' => 10, 'patterns_mode' => 'remove Kadence\get_404_content, action kadence_404_content' ],
		'before_footer' => [ 'hook' => 'kadence_before_footer', 'type' => 'default', 'priority' => 10, 'patterns_mode' => 'action kadence_before_footer' ],
		'footer'        => [ 'hook' => 'replace_footer', 'type' => 'template', 'priority' => 10, 'patterns_mode' => 'remove_action of the theme footer markup (not CSS hiding)' ],
		'sticky_bar'    => [ 'hook' => 'fixed_on_footer', 'type' => 'default', 'priority' => 10, 'devices' => [ 'mobile' ], 'patterns_mode' => 'wp_footer fixed bar + body padding' ],
	];

	/** Slots where only one element may print per page (the display filter's single-winner guard). */
	const SINGLE_WINNER = [ 'header', 'hero', 'template', 'notfound_body', 'footer' ];

	/** Slots whose map value is keyed by role (with a 'default'). */
	const ROLE_SLOTS = [ 'header', 'hero', 'template', 'before_footer', 'footer', 'sticky_bar' ];

	/**
	 * The standard map. Slugs that do not exist yet: the eleven hero-* designs,
	 * header-conversion, notfound-body and sticky-call-bar. Until a
	 * slug exists its design does not compile and is reported, never placed. header-conversion is
	 * the header without a menu for conversion and thanks pages; it is NOT header-minimal, an existing full-menu header
	 * (the clean style's main header), which keeps its slug and design.
	 */
	const DEFAULT_MAP = [
		'header'        => [ 'default' => false, 'conversion' => 'header-conversion', 'thanks' => 'header-conversion' ],
		'notice'        => false,
		'hero'          => [
			'default'      => 'hero-page',
			'home'         => false,
			'service'      => 'hero-service',
			'area'         => 'hero-area',
			'category'     => 'hero-category',
			'services_hub' => 'hero-services-hub',
			'areas_hub'    => 'hero-areas-hub',
			'conversion'   => 'hero-conversion',
			'thanks'       => 'hero-thanks',
			'legal'        => 'hero-page',
			'post'         => 'hero-post',
			'blog'         => 'hero-blog',
			'search'       => 'hero-search',
			'notfound'     => false,
		],
		'crumbs'        => [ 'design' => 'breadcrumb-bar', 'roles' => [ 'page', 'legal', 'services_hub', 'areas_hub', 'category', 'service', 'area', 'post', 'blog', 'search', 'notfound' ] ],
		'template'      => [ 'service' => 'template-service', 'area' => 'template-area' ],
		'notfound_body' => 'notfound-body',
		'before_footer' => [ 'default' => [ 'sections' => [ [ 'pattern' => 'testimonials-carousel' ], [ 'pattern' => 'offers-cards' ], [ 'pattern' => 'areas-dynamic-map-list' ] ] ], 'conversion' => false, 'thanks' => false ],
		'footer'        => [ 'default' => 'footer-licensing', 'conversion' => 'footer-minimal' ],
		'sticky_bar'    => [ 'default' => 'sticky-call-bar', 'thanks' => false ],
	];

	/**
	 * The effective map: DEFAULT_MAP, then Style::chrome_defaults() (the preset's designs), then the stored map. Role-keyed
	 * slots and crumbs merge one level deep when both sides are role maps (a stored { hero: { service: X } } keeps
	 * hero.default and the other roles); a slug, "theme", false or a DESIGN object replaces the slot whole (one design
	 * for every role, see design()).
	 */
	public static function get(): array {
		$map = self::DEFAULT_MAP;
		$stored = get_option( self::OPT_MAP, [] );
		foreach ( [ Style::chrome_defaults(), is_array( $stored ) ? $stored : [] ] as $layer ) {
			foreach ( array_intersect_key( (array) $layer, self::SLOTS ) as $slot => $v ) {
				$keyed = in_array( $slot, self::ROLE_SLOTS, true ) || 'crumbs' === $slot;
				$map[ $slot ] = $keyed && is_array( $v ) && is_array( $map[ $slot ] ) && ! self::is_design_object( $v ) && ! self::is_design_object( $map[ $slot ] ) ? array_replace( $map[ $slot ], $v ) : $v;
			}
		}
		return $map;
	}

	/** A DESIGN given as an object ({pattern, slots} | {sections} | {synced} | {adopt}) rather than a per-role map. */
	private static function is_design_object( array $v ): bool {
		return (bool) array_intersect_key( $v, [ 'pattern' => 1, 'sections' => 1, 'synced' => 1, 'adopt' => 1 ] );
	}

	/**
	 * The DESIGN a slot uses for a role (false = nothing). Role-keyed slots fall back to 'default' (a role-keyed slot set
	 * to a single design uses it for every role); crumbs follow crumbs_on(); notfound_body applies to the notfound role only.
	 *
	 * @return string|array|false
	 */
	public static function design( string $slot, string $role ) {
		$map = self::get();
		if ( ! isset( self::SLOTS[ $slot ], $map[ $slot ] ) ) {
			return false;
		}
		$v = $map[ $slot ];
		if ( 'crumbs' === $slot ) {
			return self::crumbs_on( $role ) ? ( $v['design'] ?? false ) : false;
		}
		if ( 'notfound_body' === $slot ) {
			return 'notfound' === $role ? $v : false;
		}
		if ( in_array( $slot, self::ROLE_SLOTS, true ) ) {
			if ( ! is_array( $v ) || self::is_design_object( $v ) ) {
				return $v; // One design for every role.
			}
			return array_key_exists( $role, $v ) ? $v[ $role ] : ( $v['default'] ?? false );
		}
		return $v;
	}

	/** 'elements' when the preference allows it and Kadence Pro Elements are available; otherwise 'patterns'. */
	public static function mode(): string {
		if ( 'patterns' === get_option( self::OPT_MODE, 'auto' ) || ! post_type_exists( 'kadence_element' ) ) {
			return 'patterns';
		}
		$cfg = get_option( 'kadence_pro_theme_config', '' );
		$cfg = is_string( $cfg ) ? json_decode( $cfg, true ) : $cfg;
		return is_array( $cfg ) && true === ( $cfg['elements'] ?? null ) ? 'elements' : 'patterns'; // Exactly Pro's own test (class-kadence-theme-pro.php).
	}

	/** Does the map put breadcrumbs on pages of this role? */
	public static function crumbs_on( string $role ): bool {
		return in_array( $role, (array) ( self::get()['crumbs']['roles'] ?? [] ), true );
	}
}
