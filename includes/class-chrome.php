<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Chrome: the shared designs every page of a role gets (header, notice bar, hero, crumbs, entry template, 404 body,
 * before-footer stack, footer, mobile sticky bar) and where each one is placed.
 *
 * Map (option launch_builder_chrome_map) = DEFAULT_MAP, then the style preset's defaults (Style::chrome_defaults()),
 * then the stored map, merged per slot and, for role-keyed slots and crumbs, per key. Role-keyed slots take
 * { default: DESIGN, <role>: DESIGN, ... }; crumbs is { design, roles[] }; notice and notfound_body are one DESIGN.
 * DESIGN = "pattern-slug" | {pattern, slots, replace, markdown} | {sections:[slug | {pattern...} | {synced}]} |
 *          {synced: key} | {adopt: element_id} | "theme" | false        (a notice DESIGN object may carry expires)
 *   "theme" = keep Kadence's own title banner/header/footer/404 for that role; false = nothing (hero: title hidden; for
 *   the header, footer and 404 body false also means "keep the theme's", there is no "no footer").
 *
 * Mode: option launch_builder_chrome = 'auto' (default) | 'elements' | 'patterns' is the only stored preference;
 * mode() says what is actually used (elements needs Theme Kit Pro with its Elements module on).
 *
 * Compile (apply(), the set-chrome tool, migrations): roles sharing one DESIGN form a group, key
 * chrome-{slot}-{md5(canonical DESIGN)[0..8]}. Each group's design lives in ONE store: an LB-owned synced pattern
 * (wp_block with _lb_key = the group key; a kadence_header post for block-header designs), or the synced pattern /
 * element it references ({synced}, {adopt}). In elements mode a kadence_element with the same key holds only
 * <!-- wp:block {"ref":store} /--> plus placement meta (hook/type/priority per SLOTS, native show/hide rules for
 * legibility, device rules for the sticky bar, expiry for the notice, a distinct menu_order, _lb_key/_lb_slot/_lb_roles).
 * Without Pro the same stores are printed by this class on the slot's hook ("patterns mode"), so toggling Pro never
 * loses chrome. What was compiled is the index option launch_builder_chrome_compiled: per slot, role => group key (or
 * none|theme|failed) and the groups. Nothing changes on the front end for a slot until it has been applied once.
 *
 * Ownership (Ledger): an object LB wrote and someone edited since is kept (kept_edits) unless its key is
 * in force; objects an admin drafted or trashed are never brought back; only unedited LB chrome is pruned (drafted,
 * marked pruned_by_lb); a slot with a design that failed to compile is never pruned and its failing roles keep what they
 * had (compile_failed is reported, e.g. pattern slugs that do not exist yet).
 *
 * Names: the Element, the synced pattern and the block header of a group share one title, "Launch Builder · {slot} · {page
 * types}" (title()), so the Elements list reads as an outline. The ledger row remembers the title LB wrote: a later apply
 * renames the posts while the title is still that one (or, for a row from before titles were tracked, the old machine
 * label) and never a title a person changed; force takes LB's title back. Keys and slugs never change. Chrome_Admin shows
 * the same names and page types on the Elements screens.
 *
 * Runtime: kadence_element_display (role check, _lb_hero, single-winner guard per slot, LB elements off in patterns
 * mode), the patterns-mode renderer (remove_action of the theme's own callbacks, never CSS hiding), the layout policy on
 * kadence_post_layout, the entry template filter and the theme's title-bar crumbs guard (class Breadcrumbs).
 */
final class Chrome {

	const OPT_MODE    = 'launch_builder_chrome';
	const OPT_MAP     = 'launch_builder_chrome_map';
	const OPT_INDEX   = 'launch_builder_chrome_compiled';
	const OPT_PARKED  = 'launch_builder_parked_elements';
	const OPT_UPGRADE = 'launch_builder_chrome_upgrade';   // { replace_legacy: bool, strip_content_heroes: bool } read by the migrations.
	const OPT_REAPPLY = 'launch_builder_chrome_reapply';   // Last re-apply dry run (migration step chrome-reapply).
	const OPT_AUTO    = 'launch_builder_auto_update_chrome';

	/** Page meta: '' (follow the role) | 'content' (the page has its own hero) | 'none' (no hero, no crumbs). */
	const HERO_META = '_lb_hero';

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

	/** Kadence element meta LB writes (and hashes for kept_edits). */
	const KAD_META = [ '_kad_element_type', '_kad_element_hook', '_kad_element_hook_custom', '_kad_element_hook_priority', '_kad_element_show_conditionals', '_kad_element_hide_conditionals', '_kad_element_device_conditionals', '_kad_element_enable_expires', '_kad_element_expires' ];

	/** LB meta that Kadence's "Duplicate" must not copy (a copy becomes a plain admin element). */
	const LB_META = [ '_lb_key', '_lb_slot', '_lb_roles', '_lb_adopted' ];

	/** Kadence Pro hook => chrome slot, for elements LB did not compile (legacy element-* ones, foreign ones). */
	const HOOK_SLOTS = [ 'replace_header' => 'header', 'replace_hero_header' => 'hero', 'replace_single_content' => 'template', 'replace_archive_content' => 'template', 'replace_404' => 'notfound_body', 'replace_footer' => 'footer', 'kadence_before_footer' => 'before_footer', 'fixed_on_footer' => 'sticky_bar' ];

	/** Plain slot names for post titles and the Elements screens (the Design hub words them the same way). */
	const SLOT_NAMES = [
		'header'        => 'Header',
		'notice'        => 'Notice bar',
		'hero'          => 'Hero',
		'crumbs'        => 'Breadcrumbs',
		'template'      => 'Page template',
		'notfound_body' => '404 content',
		'before_footer' => 'Before the footer',
		'footer'        => 'Footer',
		'sticky_bar'    => 'Sticky call bar',
	];

	/**
	 * Page types in plain words, in the order the Design hub lists them: role => [ name, short name ]. The short name reads
	 * in "all pages except ..." (never a raw role key such as services_hub).
	 */
	const ROLE_NAMES = [
		'home'         => [ 'Home page', 'home' ],
		'page'         => [ 'Standard pages', 'standard' ],
		'service'      => [ 'Service pages', 'service' ],
		'area'         => [ 'Service area pages', 'service area' ],
		'category'     => [ 'Service category pages', 'category' ],
		'services_hub' => [ 'Services hub page', 'services hub' ],
		'areas_hub'    => [ 'Service areas hub page', 'areas hub' ],
		'conversion'   => [ 'Conversion pages', 'conversion' ],
		'thanks'       => [ 'Thank-you pages', 'thank-you' ],
		'legal'        => [ 'Legal pages', 'legal' ],
		'post'         => [ 'Blog posts', 'blog post' ],
		'blog'         => [ 'Blog and archive pages', 'blog' ],
		'search'       => [ 'Search results', 'search' ],
		'notfound'     => [ '404 page', '404' ],
	];

	/** Every Launch Builder title starts like this, so the Elements list reads as one outline. */
	const TITLE_PREFIX = 'Launch Builder · ';

	/** Native rule(s) that select each role (a superset; the display filter decides exactly). */
	const ROLE_RULES = [
		'home'         => [ [ 'rule' => 'general|front_page' ] ],
		'page'         => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'legal'        => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'services_hub' => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'areas_hub'    => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'conversion'   => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'thanks'       => [ [ 'rule' => 'singular|page', 'select' => 'all' ] ],
		'category'     => [ [ 'rule' => 'singular|page', 'select' => 'all' ], [ 'rule' => 'tax_archive|lb_service_cat', 'select' => 'all' ] ],
		'service'      => [ [ 'rule' => 'singular|lb_service', 'select' => 'all' ] ],
		'area'         => [ [ 'rule' => 'singular|lb_area', 'select' => 'all' ] ],
		'post'         => [ [ 'rule' => 'singular|post', 'select' => 'all' ] ],
		'blog'         => [ [ 'rule' => 'general|archive' ] ],
		'search'       => [ [ 'rule' => 'general|search' ] ],
		'notfound'     => [ [ 'rule' => 'general|404' ] ],
	];

	/** @var array|null per-request copy of the index option. */
	private static $idx = null;

	/** @var array<string,int|string> slot => winning element id ('lb' when this class prints the slot), per request. */
	private static $winner = [];

	/** @var array<string,string>|null slot => 'element'|'lb'|'' for the current request (decide()). */
	private static $front = null;

	/** @var array<string,bool> post id + block => contains-block cache. */
	private static $contains = [];

	/** @var array build-site chrome preference handling (launch_builder_blueprint filter + step). */
	private static $bp = [ 'had' => false, 'pref' => null, 'passed' => null ];

	/* ================================================================== boot */

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_layout_filter' ] );
		add_filter( 'kadence_element_display', [ __CLASS__, 'element_display' ], 20, 3 );
		add_filter( 'kadence_elements_duplicate_post_exclude_list', [ __CLASS__, 'duplicate_exclude' ] );
		add_action( 'wp', [ __CLASS__, 'setup_front' ], 98 );
		add_filter( 'launch_builder_entry_template', [ __CLASS__, 'entry_template' ], 10, 2 );
		add_filter( 'launch_builder_entry_layout', [ __CLASS__, 'entry_layout' ], 10, 3 );
		add_filter( 'launch_builder_layout_meta', [ __CLASS__, 'layout_meta' ], 20, 4 );
		add_filter( 'launch_builder_blueprint', [ __CLASS__, 'blueprint_in' ], 5, 2 );
		Migrate::register_step( 'chrome-adopt-legacy', [ __CLASS__, 'migrate_adopt' ] );
		Migrate::register_step( 'chrome-page-heroes', [ __CLASS__, 'migrate_page_heroes' ], [ 'after' => [ 'chrome-adopt-legacy' ] ] );
		Migrate::register_step( 'chrome-unpark', [ __CLASS__, 'migrate_unpark' ] );
		Migrate::register_step( 'chrome-reapply', [ __CLASS__, 'migrate_reapply' ], [ 'every_version' => true, 'after' => [ 'chrome-adopt-legacy', 'chrome-page-heroes', 'chrome-unpark' ] ] );
	}

	/** The layout policy is registered at init: Kadence memoises its layout on first use (Pro triggers it at wp:99). */
	public static function register_layout_filter(): void {
		add_filter( 'kadence_post_layout', [ __CLASS__, 'layout' ], 20 );
	}

	/** _lb_hero on pages, posts and entries (editable over REST so the block editor can save it). */
	public static function register_meta(): void {
		foreach ( [ 'page', 'post', Entries::SERVICE, Entries::AREA ] as $pt ) {
			register_post_meta(
				$pt,
				self::HERO_META,
				[
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => [ 'schema' => [ 'type' => 'string', 'enum' => [ '', 'content', 'none' ] ] ],
					'sanitize_callback' => static function ( $v ) { return in_array( $v, [ 'content', 'none' ], true ) ? $v : ''; },
					'auth_callback'     => static function ( $allowed, $key, $post_id ) { return current_user_can( 'edit_post', (int) $post_id ); },
				]
			);
		}
	}

	/* ================================================================== map, mode, designs */

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
			$map = self::merge( $map, (array) $layer );
		}
		return $map;
	}

	/** Lay $layer over $map (see get()); a null value removes the key (back to what is below). */
	private static function merge( array $map, array $layer, array $base = self::DEFAULT_MAP ): array {
		foreach ( array_intersect_key( $layer, self::SLOTS ) as $slot => $v ) {
			if ( null === $v ) {
				$map[ $slot ] = $base[ $slot ];
				continue;
			}
			$keyed = in_array( $slot, self::ROLE_SLOTS, true ) || 'crumbs' === $slot;
			if ( $keyed && is_array( $v ) && is_array( $map[ $slot ] ?? null ) && ! self::is_design_object( $v ) && ! self::is_design_object( $map[ $slot ] ) ) {
				foreach ( $v as $k => $x ) {
					if ( null === $x ) {
						unset( $map[ $slot ][ $k ] );
					} else {
						$map[ $slot ][ $k ] = $x;
					}
				}
			} else {
				$map[ $slot ] = $v;
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
		return self::design_in( self::get(), $slot, $role );
	}

	/** design() for a given map. @return string|array|false */
	private static function design_in( array $map, string $slot, string $role ) {
		if ( ! isset( self::SLOTS[ $slot ], $map[ $slot ] ) ) {
			return false;
		}
		$v = $map[ $slot ];
		if ( 'crumbs' === $slot ) {
			return is_array( $v ) && in_array( $role, (array) ( $v['roles'] ?? [] ), true ) ? ( $v['design'] ?? false ) : false;
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

	/** Roles chrome serves (every role but 'other'). @return string[] */
	public static function roles(): array {
		return array_values( array_diff( array_keys( Roles::ROLES ), [ 'other' ] ) );
	}

	/* ================================================================== names (post titles, Elements screens) */

	/** The plain name of a slot ("Hero", "Breadcrumbs"). */
	public static function slot_name( string $slot ): string {
		return self::SLOT_NAMES[ $slot ] ?? ucfirst( str_replace( '_', ' ', $slot ) );
	}

	/** Page types a slot can serve at all (a template is for services and areas, the 404 body for the 404 page). @return string[] */
	private static function slot_roles( string $slot ): array {
		if ( 'template' === $slot ) {
			return [ 'service', 'area' ];
		}
		return 'notfound_body' === $slot ? [ 'notfound' ] : self::roles();
	}

	/**
	 * Names in a sentence: "A", "A and b", "A, b and c". When every name ends in "pages" only the last keeps the word
	 * ("Conversion and thank-you pages").
	 *
	 * @param string[] $names
	 */
	private static function join_names( array $names ): string {
		$names = array_values( $names );
		$n = count( $names );
		if ( $n > 1 && ! array_filter( $names, static function ( $x ) { return ' pages' !== substr( $x, -6 ); } ) ) {
			for ( $i = 0; $i < $n - 1; $i++ ) {
				$names[ $i ] = substr( $names[ $i ], 0, -6 );
			}
		}
		foreach ( $names as $i => $x ) {
			$names[ $i ] = 0 === $i ? $x : lcfirst( $x );
		}
		return 1 === $n ? $names[0] : implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . $names[ $n - 1 ];
	}

	/**
	 * The page types a design covers, in plain words: "Service pages", "Conversion and thank-you pages", "All pages except
	 * conversion". brief true is the shorter form for post titles: the breadcrumb bar and the notice bar are one design
	 * for the site, so they read "All pages" whenever nearly every page type uses them (the Used for column keeps the
	 * exact list).
	 *
	 * @param string[] $roles
	 */
	public static function page_types( string $slot, array $roles, bool $brief = false ): string {
		$all = self::slot_roles( $slot );
		$have = array_values( array_intersect( array_keys( self::ROLE_NAMES ), array_map( 'strval', $roles ) ) );
		$n = count( $have );
		if ( ! $n ) {
			return 'No page types';
		}
		$missing = array_values( array_diff( array_intersect( array_keys( self::ROLE_NAMES ), $all ), $have ) );
		if ( ! $missing && count( $all ) === count( self::roles() ) ) {
			return 'All pages';
		}
		if ( $brief && $n >= 10 && in_array( $slot, [ 'crumbs', 'notice' ], true ) ) {
			return 'All pages';
		}
		if ( $n >= 8 && $missing ) {
			return 'All pages except ' . self::join_names( array_map( static function ( $r ) { return self::ROLE_NAMES[ $r ][1]; }, $missing ) );
		}
		return self::join_names( array_map( static function ( $r ) { return self::ROLE_NAMES[ $r ][0]; }, $have ) );
	}

	/**
	 * The title Launch Builder gives a design's posts (the Kadence Element, the synced pattern, the block header): slot
	 * first, then the page types, so the Elements list reads like an outline. English on purpose: it is stored data, and
	 * the same title keeps the same post recognisable whatever language the admin uses.
	 *
	 * @param string[] $roles
	 */
	public static function title( string $slot, array $roles ): string {
		return self::TITLE_PREFIX . self::slot_name( $slot ) . ' · ' . self::page_types( $slot, $roles, true );
	}

	/** Has a person edited this Launch Builder design post since LB wrote it? False for anything LB does not own. */
	public static function edited( int $post_id ): bool {
		$m = self::managed( $post_id );
		if ( ! $m || 'design' !== $m['kind'] ) {
			return false;
		}
		$lkey = self::ledger_key( $m['type'], $m['key'] );
		return null !== Ledger::get( $lkey ) && Ledger::is_edited( $lkey, self::hash_of( $post_id, 'kadence_element' === $m['type'] ? self::KAD_META : [] ) );
	}

	/**
	 * What a Kadence Element, synced pattern or block header is for, when Launch Builder manages it; null for any other post.
	 * kind: design (Launch Builder made it for a group of page types), adopted (an element you already had, placed as it
	 * was) or legacy (an element from an older Launch Builder that is not placed through the map).
	 *
	 * @return array{kind:string,type:string,key:string,slot:string,roles:string[],element:int,store:int}|null
	 */
	public static function managed( int $post_id ): ?array {
		$type = (string) get_post_type( $post_id );
		if ( ! in_array( $type, [ 'kadence_element', 'wp_block', 'kadence_header' ], true ) ) {
			return null;
		}
		$key = (string) get_post_meta( $post_id, '_lb_key', true );
		$slot = (string) get_post_meta( $post_id, '_lb_slot', true );
		$roles = get_post_meta( $post_id, '_lb_roles', true );
		$kind = '';
		if ( 'kadence_element' === $type && '' !== $slot && metadata_exists( 'post', $post_id, '_lb_adopted' ) ) {
			$kind = 'adopted';
		} elseif ( 0 === strpos( $key, 'chrome-' ) && '' !== $slot ) {
			$kind = 'design';
		} elseif ( 'kadence_element' === $type && 0 === strpos( $key, 'element-' ) ) {
			$kind = 'legacy';
			$slot = self::HOOK_SLOTS[ (string) get_post_meta( $post_id, '_kad_element_hook', true ) ] ?? '';
			$roles = '' === $slot ? [] : self::rules_roles( (array) json_decode( (string) get_post_meta( $post_id, '_kad_element_show_conditionals', true ), true ), (array) json_decode( (string) get_post_meta( $post_id, '_kad_element_hide_conditionals', true ), true ) );
		}
		if ( '' === $kind || ! isset( self::SLOTS[ $slot ] ) ) {
			return null;
		}
		$g = (array) ( self::index()['slots'][ $slot ]['groups'][ $key ] ?? [] );
		return [ 'kind' => $kind, 'type' => $type, 'key' => $key, 'slot' => $slot, 'roles' => array_values( array_map( 'strval', (array) $roles ) ), 'element' => (int) ( $g['element'] ?? ( 'kadence_element' === $type ? $post_id : 0 ) ), 'store' => (int) ( $g['store'] ?? ( 'kadence_element' === $type ? 0 : $post_id ) ) ];
	}

	/**
	 * Validate and normalise one DESIGN. Returns false, 'theme', a slug string or a normalised object, plus the extras
	 * that are not part of the design's identity (a notice's expiry).
	 *
	 * @param mixed $d
	 * @return array{0:mixed,1:array}|\WP_Error
	 */
	public static function normalize( $d, string $slot ) {
		if ( false === $d || null === $d || '' === $d ) {
			return [ false, [] ];
		}
		if ( is_string( $d ) ) {
			if ( 'theme' === $d ) {
				return [ 'theme', [] ];
			}
			return preg_match( '/^[a-z0-9][a-z0-9-]{0,80}$/', $d ) ? [ $d, [] ] : new \WP_Error( 'lb_design', 'Not a pattern slug: ' . $d );
		}
		if ( ! is_array( $d ) ) {
			return new \WP_Error( 'lb_design', 'A design is a pattern slug, "theme", false or an object.' );
		}
		$extra = [];
		if ( isset( $d['expires'] ) && '' !== $d['expires'] && null !== $d['expires'] ) {
			if ( 'notice' !== $slot ) {
				return new \WP_Error( 'lb_design', 'expires is for the notice slot only.' );
			}
			$ts = strtotime( (string) $d['expires'] );
			if ( ! $ts ) {
				return new \WP_Error( 'lb_design', 'expires is not a date: ' . (string) $d['expires'] );
			}
			$extra['expires'] = gmdate( 'Y-m-d\TH:i:s', $ts ); // Site-local wall time, which is how Kadence Pro compares it.
		}
		unset( $d['expires'] );
		$kinds = array_intersect( array_keys( $d ), [ 'pattern', 'sections', 'synced', 'adopt' ] );
		if ( 1 !== count( $kinds ) ) {
			return new \WP_Error( 'lb_design', 'A design object has exactly one of pattern, sections, synced, adopt.' );
		}
		$kind = reset( $kinds );
		if ( 'adopt' === $kind ) {
			$id = (int) $d['adopt'];
			return $id > 0 && 1 === count( $d ) ? [ [ 'adopt' => $id ], $extra ] : new \WP_Error( 'lb_design', 'adopt takes an element id and nothing else.' );
		}
		if ( 'synced' === $kind ) {
			$s = $d['synced'];
			$s = is_int( $s ) || ctype_digit( (string) $s ) ? (int) $s : sanitize_key( (string) $s );
			return $s && 1 === count( $d ) ? [ [ 'synced' => $s ], $extra ] : new \WP_Error( 'lb_design', 'synced takes a synced pattern key (or id) and nothing else.' );
		}
		if ( 'pattern' === $kind ) {
			$one = self::norm_section( $d );
			return is_wp_error( $one ) ? $one : [ $one, $extra ];
		}
		$secs = [];
		if ( ! is_array( $d['sections'] ) || ! $d['sections'] || count( $d ) > 1 ) {
			return new \WP_Error( 'lb_design', 'sections is a non-empty list (and nothing else beside it).' );
		}
		foreach ( array_slice( array_values( $d['sections'] ), 0, 12 ) as $s ) {
			if ( is_string( $s ) ) {
				$s = [ 'pattern' => $s ];
			}
			if ( is_array( $s ) && isset( $s['synced'] ) && 1 === count( $s ) ) {
				$k = $s['synced'];
				$secs[] = [ 'synced' => is_int( $k ) || ctype_digit( (string) $k ) ? (int) $k : sanitize_key( (string) $k ) ];
				continue;
			}
			$one = is_array( $s ) ? self::norm_section( $s ) : new \WP_Error( 'lb_design', 'A section is a pattern slug, {pattern, slots, replace, markdown} or {synced}.' );
			if ( is_wp_error( $one ) ) {
				return $one;
			}
			$secs[] = $one;
		}
		return [ [ 'sections' => $secs ], $extra ];
	}

	/** {pattern, slots, replace, markdown} with only those keys (empty ones dropped). @return array|\WP_Error */
	private static function norm_section( array $s ) {
		$slug = (string) ( $s['pattern'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,80}$/', $slug ) ) {
			return new \WP_Error( 'lb_design', 'Not a pattern slug: ' . $slug );
		}
		if ( array_diff( array_keys( $s ), [ 'pattern', 'slots', 'replace', 'markdown' ] ) ) {
			return new \WP_Error( 'lb_design', 'A pattern design takes pattern, slots, replace and markdown only.' );
		}
		$out = [ 'pattern' => $slug ];
		foreach ( [ 'slots', 'replace' ] as $k ) {
			if ( isset( $s[ $k ] ) && ! is_array( $s[ $k ] ) ) {
				return new \WP_Error( 'lb_design', $k . ' must be an object.' );
			}
			if ( ! empty( $s[ $k ] ) ) {
				$out[ $k ] = $s[ $k ];
			}
		}
		if ( isset( $s['markdown'] ) && '' !== trim( (string) $s['markdown'] ) ) {
			$out['markdown'] = (string) $s['markdown'];
		}
		return 1 === count( $out ) ? $slug : $out; // {pattern: x} alone is the slug itself (one identity, one group).
	}

	/** Group key of a normalised design in a slot. */
	public static function group_key( string $slot, $design ): string {
		return 'chrome-' . $slot . '-' . substr( Ledger::hash_value( $design ), 0, 8 );
	}

	/**
	 * Check a partial map (set-chrome input) and normalise it for storage.
	 *
	 * @return array{0:array,1:string[]} [ clean partial map, errors ]
	 */
	public static function validate_map( array $in ): array {
		$out = [];
		$err = [];
		$role_keys = array_merge( [ 'default' ], self::roles() );
		foreach ( $in as $slot => $v ) {
			$slot = (string) $slot;
			if ( ! isset( self::SLOTS[ $slot ] ) ) {
				$err[] = "unknown slot: $slot (slots: " . implode( ', ', array_keys( self::SLOTS ) ) . ')';
				continue;
			}
			if ( null === $v ) {
				$out[ $slot ] = null;
				continue;
			}
			if ( 'crumbs' === $slot ) {
				if ( ! is_array( $v ) || self::is_design_object( $v ) || array_diff( array_keys( $v ), [ 'design', 'roles' ] ) ) {
					$err[] = 'crumbs is {design, roles}';
					continue;
				}
				$c = [];
				if ( array_key_exists( 'design', $v ) ) {
					$n = self::normalize( $v['design'], 'crumbs' );
					is_wp_error( $n ) ? $err[] = 'crumbs.design: ' . $n->get_error_message() : $c['design'] = self::stored_design( $v['design'] );
				}
				if ( array_key_exists( 'roles', $v ) ) {
					$roles = array_values( array_unique( array_map( 'strval', (array) $v['roles'] ) ) );
					$bad = array_diff( $roles, self::roles() );
					$bad ? $err[] = 'crumbs.roles: unknown role(s) ' . implode( ', ', $bad ) : $c['roles'] = $roles;
				}
				$out['crumbs'] = $c;
				continue;
			}
			$keyed = in_array( $slot, self::ROLE_SLOTS, true ) && is_array( $v ) && ! self::is_design_object( $v );
			if ( $keyed ) {
				foreach ( $v as $role => $d ) {
					if ( ! in_array( (string) $role, $role_keys, true ) ) {
						$err[] = "$slot: unknown role key $role (default or a role)";
						continue;
					}
					if ( null === $d ) {
						$out[ $slot ][ $role ] = null;
						continue;
					}
					$n = self::normalize( $d, $slot );
					is_wp_error( $n ) ? $err[] = "$slot.$role: " . $n->get_error_message() : $out[ $slot ][ $role ] = self::stored_design( $d );
				}
				continue;
			}
			$n = self::normalize( $v, $slot );
			is_wp_error( $n ) ? $err[] = "$slot: " . $n->get_error_message() : $out[ $slot ] = self::stored_design( $v );
		}
		return [ $out, $err ];
	}

	/** How a design is kept in the stored map (as given, minus empty strings). @return mixed */
	private static function stored_design( $d ) {
		return '' === $d || null === $d ? false : $d;
	}

	/* ================================================================== the compiled index (runtime) */

	/** The compiled index (per request). */
	public static function index(): array {
		if ( null === self::$idx ) {
			$i = get_option( self::OPT_INDEX, [] );
			self::$idx = is_array( $i ) ? $i : [];
		}
		return self::$idx;
	}

	/** Has this slot been applied at least once? (Until then chrome changes nothing on the front end for it.) */
	public static function applied( string $slot ): bool {
		return isset( self::index()['slots'][ $slot ] );
	}

	/** What the index says for a slot and role: a group key, 'none', 'theme', 'failed', or '' (slot not applied). */
	public static function state( string $slot, string $role ): string {
		return (string) ( self::index()['slots'][ $slot ]['roles'][ $role ] ?? '' );
	}

	/** The compiled group a role uses in a slot (null for none/theme/failed/not applied). */
	public static function group( string $slot, string $role ): ?array {
		$k = self::state( $slot, $role );
		$g = 0 === strpos( $k, 'chrome-' ) ? ( self::index()['slots'][ $slot ]['groups'][ $k ] ?? null ) : null;
		return is_array( $g ) ? $g + [ 'key' => $k ] : null;
	}

	/**
	 * Who prints a role's design in a slot: 'element' (Kadence Pro, elements mode), 'lb' (this class, patterns mode or no
	 * element) or '' (nothing: not applied, false/theme/failed, the store is not published, or an admin drafted the element).
	 */
	public static function printer( string $slot, string $role ): string {
		$g = self::group( $slot, $role );
		if ( ! $g ) {
			return '';
		}
		$el = (int) ( $g['element'] ?? 0 );
		$st = $el ? get_post_status( $el ) : false;
		if ( $el && false !== $st && 'publish' !== $st ) {
			return ''; // Switched off by an admin (drafted or trashed): neither Pro nor LB prints it.
		}
		if ( 'publish' !== get_post_status( (int) ( $g['store'] ?? 0 ) ) ) {
			return ''; // The design itself was drafted or trashed.
		}
		return $el && 'publish' === $st && 'elements' === self::mode() ? 'element' : 'lb';
	}

	/** The _lb_hero of the page being shown ('' when not singular). */
	public static function page_hero(): string {
		static $memo = null;
		if ( null !== $memo && did_action( 'wp' ) ) {
			return $memo;
		}
		$v = is_singular() ? (string) get_post_meta( get_queried_object_id(), self::HERO_META, true ) : '';
		$v = in_array( $v, [ 'content', 'none' ], true ) ? $v : '';
		if ( did_action( 'wp' ) ) {
			$memo = $v;
		}
		return $v;
	}

	/**
	 * Decide, once per front-end request, who prints each slot for the current role (patterns-mode renderer, layout
	 * policy and the guard all read this).
	 *
	 * @return array<string,string> slot => 'element'|'lb'|''
	 */
	private static function decide(): array {
		if ( null !== self::$front ) {
			return self::$front;
		}
		$out = [];
		$role = Roles::current();
		if ( '' === $role || 'other' === $role || ! self::index() ) {
			return self::$front = array_fill_keys( array_keys( self::SLOTS ), '' );
		}
		$ids = [];
		foreach ( array_keys( self::SLOTS ) as $slot ) {
			$g = self::group( $slot, $role );
			$g ? array_push( $ids, (int) $g['store'], (int) $g['element'] ) : null;
		}
		$ids = array_filter( array_unique( $ids ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false ); // One query for every store and element this page may print.
		}
		foreach ( array_keys( self::SLOTS ) as $slot ) {
			$p = self::printer( $slot, $role );
			if ( in_array( $slot, [ 'hero', 'crumbs' ], true ) && '' !== $p && '' !== self::page_hero() ) {
				$p = ''; // The page brings its own hero (content) or wants none.
			}
			if ( 'notfound_body' === $slot && ! is_404() ) {
				$p = '';
			}
			if ( 'notice' === $slot && '' !== $p && self::expired( (string) ( self::group( $slot, $role )['expires'] ?? '' ) ) ) {
				$p = '';
			}
			$out[ $slot ] = $p;
		}
		return self::$front = $out;
	}

	/** Who prints each slot on the current front-end request (slot => 'element'|'lb'|''), page _lb_hero and 404 included. */
	public static function decisions(): array {
		return self::decide();
	}

	private static function expired( string $expires ): bool {
		if ( '' === $expires ) {
			return false;
		}
		$ts = strtotime( $expires );
		return $ts && $ts < (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- the expiry is site-local wall time (as Kadence Pro compares it).
	}

	/* ================================================================== Kadence Pro elements: display filter */

	/**
	 * kadence_element_display (priority 20). LB elements (they carry _lb_slot) show only for their roles, never on a page
	 * whose _lb_hero is content/none (hero, crumbs), never in patterns mode (LB prints the design itself), crumbs never
	 * under a hero that already has crumbs. Single-winner guard for header/hero/template/404/footer: the first element
	 * that passes wins the slot, later ones only pass if they are that same element (idempotent for the [kadence_element]
	 * shortcode); legacy LB elements (_lb_key element-*) take part; a slot this class prints itself is won by 'lb'.
	 * AJAX and calls before 'wp' (mini-cart, early shortcodes) are passed through untouched.
	 *
	 * @param mixed    $show
	 * @param \WP_Post $post
	 * @param array    $meta
	 * @return mixed
	 */
	public static function element_display( $show, $post = null, $meta = [] ) {
		if ( wp_doing_ajax() || ! did_action( 'wp' ) || ! $post instanceof \WP_Post ) {
			return $show;
		}
		$id = (int) $post->ID;
		$slot = (string) get_post_meta( $id, '_lb_slot', true );
		if ( '' === $slot ) {
			if ( 0 !== strpos( (string) get_post_meta( $id, '_lb_key', true ), 'element-' ) ) {
				return $show; // Foreign element: Kadence decides (get-chrome reports it when it shadows chrome).
			}
			$slot = self::HOOK_SLOTS[ (string) ( is_array( $meta ) ? ( $meta['hook'] ?? '' ) : '' ) ] ?? '';
			if ( '' === $slot ) {
				return $show;
			}
		} else {
			if ( 'patterns' === self::mode() ) {
				return false;
			}
			if ( $show && metadata_exists( 'post', $id, '_lb_roles' ) ) {
				$show = in_array( Roles::current(), Roles::template_roles( $id ), true );
			}
			if ( $show && in_array( $slot, [ 'hero', 'crumbs' ], true ) && '' !== self::page_hero() ) {
				$show = false;
			}
			if ( $show && 'crumbs' === $slot && self::hero_has_crumbs() ) {
				$show = false;
			}
		}
		if ( $show && in_array( $slot, self::SINGLE_WINNER, true ) ) {
			if ( ! isset( self::$winner[ $slot ] ) ) {
				self::$winner[ $slot ] = $id;
			} elseif ( self::$winner[ $slot ] !== $id ) {
				$show = false;
			}
		}
		return $show;
	}

	/** Kadence's Duplicate copies every meta key: leave LB's out, so a copy is a plain admin element. */
	public static function duplicate_exclude( $list ): array {
		return array_values( array_unique( array_merge( (array) $list, self::LB_META ) ) );
	}

	/** Does the hero that prints on this request already carry breadcrumbs (or does the entry template)? */
	public static function hero_has_crumbs(): bool {
		$role = Roles::current();
		$d = self::decide();
		$w = self::$winner['hero'] ?? null;
		if ( is_int( $w ) ) {
			$g = self::group( 'hero', $role );
			if ( $g && (int) ( $g['element'] ?? 0 ) === $w ) {
				if ( ! empty( $g['flags']['crumbs'] ) ) {
					return true;
				}
			} elseif ( self::contains( $w, 'launch/breadcrumbs' ) ) {
				return true; // A legacy or foreign hero element.
			}
		} elseif ( '' !== $d['hero'] && ! empty( self::group( 'hero', $role )['flags']['crumbs'] ) ) {
			return true;
		}
		return in_array( $role, [ 'service', 'area' ], true ) && '' !== $d['template'] && ! empty( self::group( 'template', $role )['flags']['crumbs'] );
	}

	/** Does a post's content (one level of synced-pattern references included) contain a block? */
	public static function contains( int $post_id, string $block ): bool {
		$k = $post_id . '|' . $block;
		if ( isset( self::$contains[ $k ] ) ) {
			return self::$contains[ $k ];
		}
		$c = (string) get_post_field( 'post_content', $post_id, 'raw' );
		return self::$contains[ $k ] = self::markup_contains( $c, $block, true );
	}

	/** @param bool $follow follow <!-- wp:block {"ref":N} /--> once. */
	private static function markup_contains( string $c, string $block, bool $follow ): bool {
		$short = 0 === strpos( $block, 'core/' ) ? substr( $block, 5 ) : $block;
		if ( false !== strpos( $c, '<!-- wp:' . $short . ' ' ) || false !== strpos( $c, '<!-- wp:' . $short . ' -->' ) || false !== strpos( $c, '<!-- wp:' . $block . ' ' ) ) {
			return true;
		}
		if ( $follow && preg_match_all( '/<!-- wp:block \{"ref":(\d+)/', $c, $m ) ) {
			foreach ( array_slice( $m[1], 0, 10 ) as $ref ) {
				if ( self::markup_contains( (string) get_post_field( 'post_content', (int) $ref, 'raw' ), $block, false ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ================================================================== patterns-mode renderer */

	/**
	 * wp (priority 98, before Kadence Pro builds its element hooks at 99): for every slot this class prints on the current
	 * request, take the theme's own callback off its hook and print the stored design instead.
	 */
	public static function setup_front(): void {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( function_exists( 'is_robots' ) && is_robots() ) ) {
			return;
		}
		$d = self::decide();
		$role = Roles::current();
		foreach ( $d as $slot => $who ) {
			if ( 'lb' !== $who ) {
				continue;
			}
			$g = self::group( $slot, $role );
			if ( in_array( $slot, self::SINGLE_WINNER, true ) ) {
				self::$winner[ $slot ] = 'lb'; // Legacy LB elements on the same hook step aside.
			}
			if ( 'header' !== $slot ) {
				self::build_css( (int) $g['store'] );
			}
			switch ( $slot ) {
				case 'header':
					if ( 'header' === ( $g['kind'] ?? '' ) ) {
						$hid = (int) $g['store'];
						add_filter( 'theme_mod_blocks_header', '__return_true' );
						add_filter( 'theme_mod_blocks_header_id', static function () use ( $hid ) { return $hid; } );
					} else {
						self::build_css( (int) $g['store'] ); // A row pattern in the header slot (not a block header).
						remove_action( 'kadence_header', 'Kadence\header_markup' );
						add_action( 'kadence_header', static function () use ( $g ) { self::print_store( 'header', $g ); } );
					}
					break;
				case 'notice':
					add_action( 'kadence_before_header', static function () use ( $g ) { self::print_store( 'notice', $g ); }, 10 );
					break;
				case 'hero':
					remove_action( 'kadence_hero_header', 'Kadence\hero_title' );
					add_action( 'kadence_hero_header', static function () use ( $g ) { self::print_store( 'hero', $g ); }, 10 );
					break;
				case 'crumbs':
					add_action(
						'kadence_hero_header',
						static function () use ( $g ) {
							if ( ! self::hero_has_crumbs() ) {
								self::print_store( 'crumbs', $g );
							}
						},
						20
					);
					break;
				case 'notfound_body':
					remove_action( 'kadence_404_content', 'Kadence\get_404_content' );
					add_action( 'kadence_404_content', static function () use ( $g ) { self::print_store( 'notfound_body', $g ); }, 10 );
					break;
				case 'before_footer':
					add_action( 'kadence_before_footer', static function () use ( $g ) { self::print_store( 'before_footer', $g ); }, 10 );
					break;
				case 'footer':
					remove_action( 'kadence_footer', 'Kadence\footer_markup' );
					add_action( 'kadence_footer', static function () use ( $g ) { self::print_store( 'footer', $g ); }, 10 );
					break;
				case 'sticky_bar':
					add_filter( 'body_class', static function ( $c ) { $c[] = 'lb-has-sticky-bar'; return $c; } );
					add_action( 'wp_footer', static function () use ( $g ) { self::print_sticky( $g ); }, 5 );
					break;
			}
		}
	}

	/** Put a store's Kadence block CSS in the head (what Kadence Pro does for its elements). */
	private static function build_css( int $id ): void {
		$p = $id ? get_post( $id ) : null;
		if ( ! $p ) {
			return;
		}
		foreach ( [ 'Kadence_Blocks_Frontend', 'Kadence_Blocks_Pro_Frontend' ] as $cls ) {
			if ( class_exists( $cls ) && method_exists( $cls, 'get_instance' ) ) {
				$inst = $cls::get_instance();
				if ( method_exists( $inst, 'frontend_build_css' ) ) {
					$inst->frontend_build_css( $p );
				}
			}
		}
	}

	/** The rendered blocks of a store. */
	private static function render_store( int $id ): string {
		$p = $id ? get_post( $id ) : null;
		if ( ! $p || 'publish' !== $p->post_status ) {
			return '';
		}
		return do_shortcode( do_blocks( (string) $p->post_content ) );
	}

	private static function print_store( string $slot, array $g ): void {
		$html = self::render_store( (int) $g['store'] );
		if ( '' === trim( $html ) ) {
			return;
		}
		$cls = 'lb-chrome lb-chrome--' . $slot;
		if ( 'footer' === $slot && false === stripos( $html, '<footer' ) ) {
			echo '<footer class="' . esc_attr( $cls ) . '" role="contentinfo">' . $html . '</footer>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks.
			return;
		}
		echo '<div class="' . esc_attr( $cls ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks.
	}

	/** The mobile sticky bar without Pro: fixed to the bottom on phones, the page padded by its height. */
	private static function print_sticky( array $g ): void {
		$html = self::render_store( (int) $g['store'] );
		if ( '' === trim( $html ) ) {
			return;
		}
		echo '<div class="lb-chrome lb-chrome--sticky_bar lb-sticky-bar" data-lb-sticky-bar>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks.
		echo '<style id="lb-sticky-bar-css">.lb-sticky-bar{position:fixed;left:0;right:0;bottom:0;z-index:1000}@media (min-width:768px){.lb-sticky-bar{display:none}}@media (max-width:767px){body.lb-has-sticky-bar{padding-bottom:var(--lb-sticky-bar-h,72px)}}</style>';
		echo '<script>(function(){var b=document.querySelector("[data-lb-sticky-bar]");if(!b)return;var s=function(){document.body.style.setProperty("--lb-sticky-bar-h",b.offsetHeight+"px")};s();if(window.ResizeObserver){new ResizeObserver(s).observe(b)}})();</script>';
	}

	/**
	 * launch_builder_entry_template (Entries::wrap, patterns mode): the template slot's store for the entry's role. 0 when
	 * Kadence Pro prints the template element (no double wrap) or the role has none; the legacy synced template while
	 * the slot has not been applied.
	 */
	public static function entry_template( $id, $post = null ) {
		$role = $post instanceof \WP_Post ? ( Entries::SERVICE === $post->post_type ? 'service' : ( Entries::AREA === $post->post_type ? 'area' : '' ) ) : '';
		$st = '' === $role ? '' : self::state( 'template', $role );
		if ( '' === $st || ( 'failed' === $st && ! self::group( 'template', $role ) ) ) {
			return $id;
		}
		$who = self::printer( 'template', $role );
		if ( 'lb' === $who ) {
			self::$winner['template'] = 'lb';
			return (int) self::group( 'template', $role )['store'];
		}
		return 0;
	}

	/* ================================================================== layout policy */

	/**
	 * kadence_post_layout (priority 20, after per-post meta and per-type mods). Only for front-end views of a chrome role;
	 * template post types are skipped; every key is checked with isset (wp-admin passes a different shape and is skipped).
	 *   title         _lb_hero content|none -> hide; live hero design -> above; entry template with its own title -> hide;
	 *                 hero false -> hide; "theme" -> above; otherwise untouched (not applied, failed, switched off).
	 *   sidebar       conversion/thanks -> disable (layout left/right -> normal); otherwise per-post / Customizer.
	 *   layout/boxed/vpadding  per-post when set; service/area with a live template: fullwidth/unboxed/hide; a 404 body
	 *                 printed by LB: unboxed/hide.
	 *   transparent   per-post when set; else the live hero design's Transparent: header (default disable).
	 *   header/footer 'disable' when LB prints that slot in place of the theme's.
	 */
	public static function layout( $layout ) {
		if ( ! is_array( $layout ) || is_admin() || wp_doing_ajax() || ! did_action( 'wp' ) || is_singular( Roles::TEMPLATE_TYPES ) ) {
			return $layout;
		}
		$role = Roles::current();
		if ( '' === $role || 'other' === $role ) {
			return $layout;
		}
		$pid = is_singular() ? (int) get_queried_object_id() : 0;
		$d = self::decide();
		if ( isset( $layout['title'] ) ) {
			$t = self::title_for( $role );
			if ( null !== $t ) {
				$layout['title'] = $t;
			}
		}
		if ( in_array( $role, [ 'conversion', 'thanks' ], true ) && isset( $layout['sidebar'] ) ) {
			$layout['sidebar'] = 'disable';
			if ( isset( $layout['layout'] ) && in_array( $layout['layout'], [ 'left', 'right' ], true ) ) {
				$layout['layout'] = 'normal';
			}
		}
		if ( in_array( $role, [ 'service', 'area' ], true ) && '' !== $d['template'] ) {
			$own = static function ( string $key ) use ( $pid ): bool {
				$v = $pid ? (string) get_post_meta( $pid, $key, true ) : '';
				return '' !== $v && 'default' !== $v;
			};
			if ( isset( $layout['layout'] ) && ! $own( '_kad_post_layout' ) ) {
				$layout['layout'] = 'fullwidth';
				if ( isset( $layout['sidebar'] ) ) {
					$layout['sidebar'] = 'disable';
				}
			}
			if ( isset( $layout['boxed'] ) && ! $own( '_kad_post_content_style' ) ) {
				$layout['boxed'] = 'unboxed';
			}
			if ( isset( $layout['vpadding'] ) && ! $own( '_kad_post_vertical_padding' ) ) {
				$layout['vpadding'] = 'hide';
			}
		}
		if ( 'lb' === $d['notfound_body'] ) {
			isset( $layout['boxed'] ) ? $layout['boxed'] = 'unboxed' : null;
			isset( $layout['vpadding'] ) ? $layout['vpadding'] = 'hide' : null;
		}
		if ( isset( $layout['transparent'] ) && '' !== $d['hero'] ) {
			$pt = $pid ? (string) get_post_meta( $pid, '_kad_post_transparent', true ) : '';
			if ( in_array( $pt, [ 'enable', 'disable' ], true ) ) {
				$layout['transparent'] = $pt;
			} else {
				$layout['transparent'] = ! empty( self::group( 'hero', $role )['flags']['transparent'] ) ? 'enable' : 'disable';
			}
		}
		if ( isset( $layout['footer'] ) && 'lb' === $d['footer'] ) {
			$layout['footer'] = 'disable';
		}
		if ( isset( $layout['header'] ) && 'lb' === $d['header'] && 'header' !== ( self::group( 'header', $role )['kind'] ?? '' ) ) {
			$layout['header'] = 'disable';
		}
		return $layout;
	}

	/** The title layout the policy wants for the current page (null = leave Kadence's). */
	private static function title_for( string $role ): ?string {
		if ( '' !== self::page_hero() ) {
			return 'hide';
		}
		$d = self::decide();
		if ( '' !== $d['hero'] ) {
			return 'above';
		}
		if ( in_array( $role, [ 'service', 'area' ], true ) && '' !== $d['template'] && ! empty( self::group( 'template', $role )['flags']['title'] ) ) {
			return 'hide'; // The entry template carries the title (legacy synced templates).
		}
		$st = self::state( 'hero', $role );
		if ( 'none' === $st ) {
			return 'hide';
		}
		return 'theme' === $st ? 'above' : null;
	}

	/* ================================================================== layout writes (_kad_post_title -> _lb_hero) */

	/**
	 * launch_builder_layout_meta (Content::set_layout): LB stops writing _kad_post_title once chrome is active. A title
	 * passed explicitly (set-page-layout, apply-pattern) becomes _lb_hero: show -> '' (follow the role), hide -> 'content'
	 * when the page opens with its own hero, else 'none'. A title that only comes from a page type is dropped; a 'hide'
	 * page whose content opens with a hero gets _lb_hero 'content'. Before the chrome migrations have run on a site the
	 * old writes stay (migration chrome-page-heroes converts them).
	 *
	 * @param array      $meta    meta key => value about to be written.
	 * @param int        $post_id
	 * @param array      $l       resolved layout (page type + overrides).
	 * @param array|null $in      what the caller passed (tells an explicit title from a page type's).
	 */
	public static function layout_meta( $meta, $post_id = 0, $l = [], $in = null ) {
		$meta = (array) $meta;
		if ( ! array_key_exists( '_kad_post_title', $meta ) ) {
			return $meta;
		}
		$post_id = (int) $post_id;
		$title = (string) $meta['_kad_post_title'];
		if ( is_array( $in ) && isset( $in['title'] ) ) {
			unset( $meta['_kad_post_title'] );
			delete_post_meta( $post_id, '_kad_post_title' );
			$meta[ self::HERO_META ] = 'show' === $title ? '' : ( self::content_hero_of( $post_id ) ? 'content' : 'none' );
			return $meta;
		}
		if ( ! self::active() ) {
			return $meta;
		}
		unset( $meta['_kad_post_title'] );
		if ( 'hide' === $title && '' === (string) get_post_meta( $post_id, self::HERO_META, true ) && self::content_hero_of( $post_id ) ) {
			$meta[ self::HERO_META ] = 'content';
		}
		return $meta;
	}

	/** launch_builder_entry_layout: entries no longer pass a title once chrome is active (the role decides). */
	public static function entry_layout( $layout, $id = 0, $created = false ) {
		$layout = (array) $layout;
		if ( self::active() ) {
			unset( $layout['title'] );
		}
		return $layout;
	}

	/** Chrome has taken over page titles: applied once, or the legacy adoption migration has run. */
	public static function active(): bool {
		return (bool) self::index() || in_array( 'chrome-adopt-legacy', Migrate::state()['done'], true );
	}

	/**
	 * Does a post's content open with its own hero? The first top-level block contains core/post-title,
	 * launch/breadcrumbs or an H1.
	 */
	public static function content_hero_of( int $post_id ): bool {
		$c = (string) get_post_field( 'post_content', $post_id, 'raw' );
		if ( '' === trim( $c ) ) {
			return false;
		}
		foreach ( parse_blocks( $c ) as $b ) {
			if ( empty( $b['blockName'] ) ) {
				if ( '' === trim( (string) ( $b['innerHTML'] ?? '' ) ) ) {
					continue;
				}
				return false !== stripos( (string) $b['innerHTML'], '<h1' );
			}
			return self::block_is_hero( $b );
		}
		return false;
	}

	private static function block_is_hero( array $b ): bool {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( in_array( $name, [ 'core/post-title', 'launch/breadcrumbs' ], true ) ) {
			return true;
		}
		if ( in_array( $name, [ 'core/heading', 'kadence/advancedheading' ], true ) && 1 === (int) ( $b['attrs']['level'] ?? 2 ) ) {
			return true;
		}
		if ( false !== stripos( (string) ( $b['innerHTML'] ?? '' ), '<h1' ) ) {
			return true;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? [] ) as $in ) {
			if ( self::block_is_hero( $in ) ) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== build-site: the preference only when passed */

	/** launch_builder_blueprint (early): remember the stored preference and whether the blueprint passes one. */
	public static function blueprint_in( $bp, $input = [] ) {
		$bp = (array) $bp;
		$raw = get_option( self::OPT_MODE, null );
		self::$bp = [ 'had' => null !== $raw, 'pref' => $raw, 'passed' => isset( $bp['chrome'] ) && in_array( $bp['chrome'], [ 'auto', 'elements', 'patterns' ], true ) ? $bp['chrome'] : null ];
		return $bp;
	}

	/** Module hook: build-site steps. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'chrome-pref', 'at' => 'design', 'run' => [ __CLASS__, 'blueprint_pref' ] ] ];
	}

	/**
	 * Step after build-site's design phase (which writes the preference from blueprint.chrome, 'patterns' when absent):
	 * the preference changes only when the blueprint passes it ('auto' included); otherwise it is put back.
	 */
	public static function blueprint_pref( array $bp, array $ctx ): array {
		unset( $bp, $ctx );
		if ( null !== self::$bp['passed'] ) {
			update_option( self::OPT_MODE, self::$bp['passed'] );
		} elseif ( self::$bp['had'] ) {
			update_option( self::OPT_MODE, self::$bp['pref'] );
		} else {
			delete_option( self::OPT_MODE );
		}
		return [ 'preference' => (string) get_option( self::OPT_MODE, 'auto' ), 'mode' => self::mode() ];
	}

	/* ================================================================== compile (apply) */

	/**
	 * Compile the map into stores, elements (elements mode) and the index.
	 *
	 * @param array $o { dry_run, force: string[] (group keys, or ['all'] with confirm_all), confirm_all, slots: string[]
	 *                 (only these; no orphan sweep), prune (default true), replace_legacy, map (instead of the stored one) }
	 * @return array|\WP_Error report
	 */
	public static function apply( array $o = [] ) {
		$dry = ! empty( $o['dry_run'] );
		$force = array_values( array_filter( array_map( 'strval', (array) ( $o['force'] ?? [] ) ) ) );
		if ( in_array( 'all', $force, true ) && empty( $o['confirm_all'] ) ) {
			return new \WP_Error( 'lb_confirm_all', 'force ["all"] overwrites every edited chrome object: pass confirm_all true as well.', [ 'status' => 400 ] );
		}
		$only = array_values( array_intersect( array_map( 'strval', (array) ( $o['slots'] ?? [] ) ), array_keys( self::SLOTS ) ) );
		$prune = ! array_key_exists( 'prune', $o ) || ! empty( $o['prune'] );
		$map = isset( $o['map'] ) && is_array( $o['map'] ) ? $o['map'] : self::get();
		$mode = self::mode();
		$prev = self::index();
		$index = [ 'slots' => (array) ( $prev['slots'] ?? [] ) ];
		$ctx = [ 'dry' => $dry, 'force' => $force, 'mode' => $mode, 'conv_ids' => null ];
		$r = [ 'mode' => $mode, 'dry_run' => $dry, 'groups' => [], 'compile_failed' => [], 'kept_edits' => [], 'skipped' => [], 'pruned' => [], 'released' => [], 'warnings' => [] ];
		if ( ! $dry && ! Lock::acquire( 'chrome', 180 ) ) {
			return new \WP_Error( 'lb_busy', 'Another chrome apply is running; try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			$hero_live = [];
			foreach ( self::roles() as $role ) { // What the hero slot gives now (replaced below when this run compiles it).
				$hg = self::group( 'hero', $role );
				$hero_live[ $role ] = $hg && '' !== self::liveness( $hg );
			}
			$slot_no = 0;
			foreach ( self::SLOTS as $slot => $def ) {
				$slot_no++;
				if ( $only && ! in_array( $slot, $only, true ) ) {
					continue;
				}
				$states = [];
				$groups = [];
				foreach ( self::roles() as $role ) {
					$n = self::normalize( self::design_in( $map, $slot, $role ), $slot );
					if ( is_wp_error( $n ) ) {
						$states[ $role ] = 'failed';
						$r['compile_failed'][] = [ 'slot' => $slot, 'roles' => [ $role ], 'error' => $n->get_error_message() ];
						continue;
					}
					[ $design, $extra ] = $n;
					if ( false === $design || 'theme' === $design ) {
						$states[ $role ] = false === $design ? 'none' : 'theme';
						continue;
					}
					$key = self::group_key( $slot, $design );
					$groups[ $key ]['design'] = $design;
					$groups[ $key ]['extra'] = $extra;
					$groups[ $key ]['roles'][] = $role;
					$states[ $role ] = $key;
				}
				// Compile each group (hero before template: an entry template drops its own banner under a live hero).
				$compiled = [];
				$failed_slot = false;
				foreach ( $groups as $key => $g ) {
					$strip = 'template' === $slot && $g['roles'] && ! array_diff( $g['roles'], array_keys( array_filter( $hero_live ) ) );
					$c = self::compile( $slot, $key, $g['design'], $strip );
					if ( is_wp_error( $c ) ) {
						$failed_slot = true;
						foreach ( $g['roles'] as $role ) {
							$states[ $role ] = 'failed';
						}
						$r['compile_failed'][] = [ 'slot' => $slot, 'key' => $key, 'roles' => $g['roles'], 'design' => $g['design'], 'error' => $c->get_error_message() ];
						continue;
					}
					foreach ( (array) ( $c['warnings'] ?? [] ) as $w ) {
						$r['warnings'][] = "$key: $w";
					}
					$compiled[ $key ] = $c + [ 'design' => $g['design'], 'extra' => $g['extra'] ];
				}
				// A failing role keeps the group it had (never prune a slot whose design failed to compile).
				$prev_slot = (array) ( $prev['slots'][ $slot ] ?? [] );
				foreach ( $states as $role => $st ) {
					$old = (string) ( $prev_slot['roles'][ $role ] ?? '' );
					if ( 'failed' === $st && 0 === strpos( $old, 'chrome-' ) && isset( $prev_slot['groups'][ $old ] ) && ! isset( $compiled[ $old ] ) ) {
						$compiled[ $old ] = [ 'kind' => 'kept', 'prev' => $prev_slot['groups'][ $old ] ];
					}
					if ( 'failed' === $st && 0 === strpos( $old, 'chrome-' ) && isset( $compiled[ $old ] ) ) {
						$states[ $role ] = $old;
					}
				}
				// Final roles per group, then write.
				$roles_of = [];
				foreach ( $states as $role => $st ) {
					if ( isset( $compiled[ $st ] ) ) {
						$roles_of[ $st ][] = $role;
					}
				}
				$new_groups = [];
				$gi = 0;
				foreach ( $compiled as $key => $c ) {
					if ( empty( $roles_of[ $key ] ) ) {
						continue;
					}
					$order = 100 + $slot_no * 20 + min( 19, $gi++ );
					$entry = self::write_group( $slot, $key, $c, $roles_of[ $key ], $order, $ctx, $r );
					if ( is_wp_error( $entry ) ) {
						$failed_slot = true;
						foreach ( $roles_of[ $key ] as $role ) {
							$states[ $role ] = 'failed';
						}
						$r['compile_failed'][] = [ 'slot' => $slot, 'key' => $key, 'roles' => $roles_of[ $key ], 'error' => $entry->get_error_message() ];
						continue;
					}
					$new_groups[ $key ] = $entry;
				}
				if ( 'hero' === $slot ) {
					foreach ( $states as $role => $st ) {
						$hero_live[ $role ] = isset( $new_groups[ $st ] ) && ! empty( $new_groups[ $st ]['live'] );
					}
				}
				foreach ( (array) ( $prev_slot['groups'] ?? [] ) as $k => $pg ) {
					if ( isset( $new_groups[ $k ] ) || ! $prune ) {
						continue;
					}
					if ( ! $failed_slot ) {
						self::release( $slot, (string) $k, (array) $pg, $ctx, $r, $new_groups );
						continue;
					}
					// A slot with a failing design is never pruned; a group no role uses any more only leaves every role.
					$el = (int) ( $pg['element'] ?? 0 );
					if ( $el && ! $ctx['dry'] ) {
						update_post_meta( $el, '_lb_roles', [] );
					}
					$r['released'][] = [ 'key' => (string) $k, 'slot' => $slot, 'element' => $el, 'what' => 'unused, shown on no role (not pruned: this slot has a design that failed to compile)' ];
				}
				$index['slots'][ $slot ] = [ 'roles' => $states, 'groups' => $new_groups ];
			}
			if ( $prune && ! $only ) {
				self::sweep_orphans( $index, $ctx, $r );
			}
			if ( ! empty( $o['replace_legacy'] ) ) {
				self::replace_legacy( $index, $ctx, $r );
			}
			if ( isset( $index['slots']['crumbs'] ) && ( ! $only || in_array( 'crumbs', $only, true ) ) ) {
				$r['theme_crumbs'] = Breadcrumbs::theme_crumbs_off( $dry );
			}
			$index['time'] = time();
			$index['mode'] = $mode;
			$r['index'] = self::summary( $index );
			if ( ! $dry ) {
				update_option( self::OPT_INDEX, $index, true );
				self::$idx = null;
				self::$front = null;
				Cache::purge_later();
			}
			return $r;
		} finally {
			if ( ! $dry ) {
				Lock::release( 'chrome' );
			}
		}
	}

	/** Compact view of an index: slot => role => key|state. */
	private static function summary( array $index ): array {
		$out = [];
		foreach ( (array) ( $index['slots'] ?? [] ) as $slot => $s ) {
			$out[ $slot ] = (array) ( $s['roles'] ?? [] );
		}
		return $out;
	}

	/**
	 * Turn one design into what gets stored.
	 *
	 * @return array|\WP_Error { kind: pattern|header|synced|adopt, content?, ref?, meta?, flags{crumbs,title,transparent}, warnings[] }
	 */
	private static function compile( string $slot, string $key, $design, bool $strip_hero ) {
		if ( is_array( $design ) && isset( $design['adopt'] ) ) {
			$p = get_post( (int) $design['adopt'] );
			if ( ! $p || 'kadence_element' !== $p->post_type || 'trash' === $p->post_status ) {
				return new \WP_Error( 'lb_adopt', 'adopt: element ' . (int) $design['adopt'] . ' does not exist (or is in the trash).' );
			}
			return [ 'kind' => 'adopt', 'ref' => (int) $p->ID, 'flags' => self::flags( (string) $p->post_content, '' ) ];
		}
		if ( is_array( $design ) && isset( $design['synced'] ) ) {
			$id = self::synced_id( $design['synced'] );
			if ( ! $id ) {
				return new \WP_Error( 'lb_synced', 'synced: no synced pattern ' . $design['synced'] . ' (set-synced-pattern makes one).' );
			}
			return [ 'kind' => 'synced', 'ref' => $id, 'flags' => self::flags( (string) get_post_field( 'post_content', $id, 'raw' ), '' ) ];
		}
		$sections = is_array( $design ) && isset( $design['sections'] ) ? $design['sections'] : [ $design ];
		$all = Patterns::all();
		$parts = [];
		$warnings = [];
		$first = '';
		foreach ( $sections as $i => $s ) {
			if ( is_array( $s ) && isset( $s['synced'] ) ) {
				$id = self::synced_id( $s['synced'] );
				if ( ! $id ) {
					return new \WP_Error( 'lb_synced', 'sections[' . $i . ']: no synced pattern ' . $s['synced'] );
				}
				$parts[] = '<!-- wp:block {"ref":' . $id . '} /-->';
				continue;
			}
			$s = is_string( $s ) ? [ 'pattern' => $s ] : $s;
			$slug = (string) $s['pattern'];
			if ( ! isset( $all[ $slug ] ) ) {
				return new \WP_Error( 'lb_no_pattern', 'pattern ' . $slug . ' does not exist yet.' );
			}
			$first = '' === $first ? $slug : $first;
			if ( in_array( 'launch-builder-headers', (array) ( $all[ $slug ]['categories'] ?? [] ), true ) ) {
				if ( 'header' !== $slot || 1 !== count( $sections ) ) {
					return new \WP_Error( 'lb_is_header', $slug . ' is a header pattern: it fits the header slot only, alone.' );
				}
				return self::compile_header( $slug, $s, $all[ $slug ] );
			}
			$slots = (array) ( $s['slots'] ?? [] );
			if ( $strip_hero ) {
				$slots['@no_hero'] = true;
			}
			$b = Patterns::build_pattern( $slug, $slots, (array) ( $s['replace'] ?? [] ), [], (string) ( $s['markdown'] ?? '' ) );
			if ( is_wp_error( $b ) ) {
				return $b;
			}
			$parts[] = $b['content'];
			foreach ( $b['warnings'] as $w ) {
				$warnings[] = $slug . ': ' . $w;
			}
		}
		$content = implode( "\n\n", $parts );
		return [ 'kind' => 'pattern', 'content' => $content, 'flags' => self::flags( $content, $first ), 'warnings' => $warnings ];
	}

	/** A header-category pattern becomes a kadence_header post (Kadence's own block header store). */
	private static function compile_header( string $slug, array $s, array $p ) {
		if ( ! post_type_exists( 'kadence_header' ) ) {
			return new \WP_Error( 'lb_no_header', 'The block header needs Kadence Blocks (Header Adv).' );
		}
		$blocks = parse_blocks( (string) $p['content'] );
		[ , $warnings ] = Patterns::fill( $blocks, (array) ( $s['slots'] ?? [] ), (array) ( $s['replace'] ?? [] ), [] );
		$nav = get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'publish', 'meta_key' => '_lb_key', 'meta_value' => 'nav-main-menu', 'numberposts' => 1, 'fields' => 'ids' ] );
		if ( $nav ) {
			self::set_nav( $blocks, (int) $nav[0] );
		}
		$meta = (array) ( Plugin::pattern_extra( $slug )['header_meta'] ?? [] );
		return [ 'kind' => 'header', 'content' => serialize_blocks( $blocks ), 'meta' => $meta, 'flags' => [ 'crumbs' => false, 'title' => false, 'transparent' => false ], 'warnings' => (array) $warnings ];
	}

	private static function set_nav( array &$blocks, int $nav_id ): void {
		foreach ( $blocks as &$b ) {
			if ( 'kadence/navigation' === ( $b['blockName'] ?? '' ) ) {
				$b['attrs']['id'] = $nav_id;
				unset( $b['attrs']['templateKey'] );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::set_nav( $b['innerBlocks'], $nav_id );
			}
		}
		unset( $b );
	}

	/** What a design carries: crumbs, a page title, a transparent-header flag (pattern file header Transparent:). */
	private static function flags( string $content, string $first_slug ): array {
		$t = false;
		if ( '' !== $first_slug && is_readable( LB_DIR . 'patterns/' . $first_slug . '.php' ) ) {
			$h = get_file_data( LB_DIR . 'patterns/' . $first_slug . '.php', [ 't' => 'Transparent' ] );
			$t = in_array( strtolower( trim( (string) $h['t'] ) ), [ 'yes', 'true', '1', 'on' ], true );
		}
		return [ 'crumbs' => self::markup_contains( $content, 'launch/breadcrumbs', true ), 'title' => self::markup_contains( $content, 'core/post-title', true ), 'transparent' => $t ];
	}

	/** A synced pattern by LB key (synced-{key}) or id; 0 when missing or trashed. */
	private static function synced_id( $k ): int {
		if ( is_int( $k ) ) {
			$p = get_post( $k );
			return $p && 'wp_block' === $p->post_type && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		$id = Blueprint::find_synced( (string) $k );
		return $id && 'trash' !== get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Write one group: its store (LB synced pattern or block header; nothing for synced/adopt), its element in elements
	 * mode (or LB meta on an adopted element), and return its index entry.
	 *
	 * @return array|\WP_Error
	 */
	private static function write_group( string $slot, string $key, array $c, array $roles, int $order, array &$ctx, array &$r ) {
		$label = self::title( $slot, $roles );
		$entry = [ 'kind' => $c['kind'], 'roles' => $roles, 'store' => 0, 'element' => 0, 'flags' => (array) ( $c['flags'] ?? [] ), 'design' => $c['design'] ?? null ];
		if ( ! empty( $c['extra']['expires'] ) ) {
			$entry['expires'] = $c['extra']['expires'];
		}
		$row = [ 'key' => $key, 'slot' => $slot, 'roles' => $roles, 'kind' => $c['kind'], 'title' => $label ];
		if ( 'kept' === $c['kind'] ) { // A failing design: keep the previous group as it was, only its roles follow the map.
			$entry = array_merge( (array) $c['prev'], [ 'roles' => $roles ] );
			$row['kind'] = $entry['kind'] ?? 'kept';
			$row['store'] = [ 'id' => (int) ( $entry['store'] ?? 0 ), 'action' => 'kept (design failed to compile)' ];
			if ( ! empty( $entry['element'] ) && ! $ctx['dry'] ) {
				update_post_meta( (int) $entry['element'], '_lb_roles', $roles );
			}
			$entry['live'] = '' !== self::liveness( $entry );
			$r['groups'][] = $row;
			return $entry;
		}
		if ( 'pattern' === $c['kind'] || 'header' === $c['kind'] ) {
			$type = 'header' === $c['kind'] ? 'kadence_header' : 'wp_block';
			$w = self::write_post( $type, $key, $label, (string) $c['content'], (array) ( $c['meta'] ?? [] ), [], true, 0, $ctx, $r );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			$entry['store'] = $w['id'];
			$row['store'] = $w;
			if ( $w['id'] && ! $ctx['dry'] && 'trashed' !== $w['action'] ) { // The design knows its roles too: editor previews render it as a page of its first role.
				update_post_meta( $w['id'], '_lb_slot', $slot );
				update_post_meta( $w['id'], '_lb_roles', $roles );
			}
			if ( in_array( $w['action'], [ 'trashed', 'admin_drafted' ], true ) ) { // The design was switched off: its element shows nowhere.
				$el = self::find_post( 'kadence_element', $key );
				if ( $el ) {
					$entry['element'] = (int) $el->ID;
					$ctx['dry'] ? null : update_post_meta( $el->ID, '_lb_roles', [] );
					$row['element'] = [ 'id' => (int) $el->ID, 'action' => 'shown nowhere (its design is ' . $w['action'] . ')' ];
				}
				$entry['live'] = false;
				$r['groups'][] = $row;
				return $entry;
			}
		} else {
			$entry['store'] = (int) $c['ref'];
			$row['store'] = [ 'id' => (int) $c['ref'], 'action' => 'reference' ];
		}
		if ( 'adopt' === $c['kind'] ) {
			$entry['element'] = (int) $c['ref'];
			if ( ! $ctx['dry'] ) {
				update_post_meta( $entry['element'], '_lb_slot', $slot );
				update_post_meta( $entry['element'], '_lb_roles', $roles );
				update_post_meta( $entry['element'], '_lb_adopted', 1 );
				if ( 'draft' === get_post_status( $entry['element'] ) ) { // Changed away and changed back (undo): the adopted element prints again.
					wp_update_post( [ 'ID' => $entry['element'], 'post_status' => 'publish' ] );
				}
			}
			$row['element'] = [ 'id' => $entry['element'], 'action' => 'adopted' ];
		} elseif ( 'elements' === $ctx['mode'] && post_type_exists( 'kadence_element' ) ) {
			if ( ! $entry['store'] && $ctx['dry'] ) {
				$row['element'] = [ 'id' => 0, 'action' => 'create' ];
			} elseif ( $entry['store'] ) {
				$content = 'header' === $c['kind'] ? '<!-- wp:kadence/header {"uniqueID":"header-replace","id":' . (int) $entry['store'] . '} /-->' : '<!-- wp:block {"ref":' . (int) $entry['store'] . '} /-->';
				$meta = self::element_meta( $slot, $roles, (string) ( $entry['expires'] ?? '' ), $ctx );
				$w = self::write_post( 'kadence_element', $key, $label, $content, $meta, self::KAD_META, false, $order, $ctx, $r );
				if ( is_wp_error( $w ) ) {
					return $w;
				}
				$entry['element'] = $w['id'];
				$row['element'] = $w;
				if ( $w['id'] && ! $ctx['dry'] ) {
					update_post_meta( $w['id'], '_lb_slot', $slot );
					update_post_meta( $w['id'], '_lb_roles', $roles ); // Roles update in place, even on an edited element.
				}
			}
		} else {
			$el = self::find_post( 'kadence_element', $key ); // Patterns mode: an element from an earlier elements-mode apply is kept (Pro may come back).
			if ( $el ) {
				$entry['element'] = (int) $el->ID;
				$row['element'] = [ 'id' => (int) $el->ID, 'action' => 'kept for elements mode (not shown in patterns mode)' ];
				if ( ! $ctx['dry'] ) {
					update_post_meta( $el->ID, '_lb_roles', $roles );
				}
			}
		}
		$entry['live'] = '' !== self::liveness( $entry );
		$r['groups'][] = $row;
		return $entry;
	}

	/** Would an index entry print (ignoring the request)? 'element'|'lb'|'' */
	private static function liveness( array $g ): string {
		$el = (int) ( $g['element'] ?? 0 );
		$st = $el ? get_post_status( $el ) : false;
		if ( $el && false !== $st && 'publish' !== $st ) {
			return '';
		}
		if ( ! empty( $g['store'] ) && 'publish' !== get_post_status( (int) $g['store'] ) ) {
			return '';
		}
		return $el && 'publish' === $st && 'elements' === self::mode() ? 'element' : 'lb'; // A store not created yet (dry run) counts as live.
	}

	/** Kadence Pro placement meta for a slot (values as Pro stores them; null = delete). */
	private static function element_meta( string $slot, array $roles, string $expires, array &$ctx ): array {
		$def = self::SLOTS[ $slot ];
		[ $show, $hide ] = self::native_rules( $roles, $ctx );
		$dev = [];
		foreach ( (array) ( $def['devices'] ?? [] ) as $d ) {
			$dev[] = [ 'value' => $d, 'label' => ucfirst( $d ) ];
		}
		return [
			'_kad_element_type'                => $def['type'],
			'_kad_element_hook'                => $def['hook'],
			'_kad_element_hook_custom'         => $def['custom_hook'] ?? null,
			'_kad_element_hook_priority'       => (int) $def['priority'],
			'_kad_element_show_conditionals'   => wp_json_encode( $show ),
			'_kad_element_hide_conditionals'   => $hide ? wp_json_encode( $hide ) : null,
			'_kad_element_device_conditionals' => $dev ? wp_json_encode( $dev ) : null,
			'_kad_element_enable_expires'      => '' !== $expires ? '1' : null,
			'_kad_element_expires'             => '' !== $expires ? $expires : null,
		];
	}

	/**
	 * Native show/hide rules for a role set: a superset of the roles (legibility in the Kadence UI; the display filter
	 * decides exactly), with cheap mutual exclusion: the front page and the conversion/thanks pages are hidden from groups
	 * without those roles.
	 *
	 * @return array{0:array,1:array}
	 */
	private static function native_rules( array $roles, array &$ctx ): array {
		$all = self::roles();
		if ( count( array_intersect( $roles, $all ) ) >= count( $all ) - 2 ) {
			$show = [ [ 'rule' => 'general|site' ] ];
		} else {
			$show = [];
			foreach ( $roles as $role ) {
				foreach ( self::ROLE_RULES[ $role ] ?? [] as $rule ) {
					if ( ! in_array( $rule, $show, true ) ) {
						$show[] = $rule;
					}
				}
			}
		}
		$hide = [];
		$pages = (bool) array_filter( $show, static function ( $x ) { return in_array( $x['rule'], [ 'general|site', 'singular|page' ], true ); } );
		if ( $pages && ! in_array( 'home', $roles, true ) ) {
			$hide[] = [ 'rule' => 'general|front_page' ];
		}
		if ( $pages && ! array_intersect( [ 'conversion', 'thanks' ], $roles ) ) {
			if ( null === $ctx['conv_ids'] ) {
				$ctx['conv_ids'] = array_map( 'intval', get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids', 'meta_query' => [ [ 'key' => Roles::META, 'value' => [ 'conversion', 'thanks' ], 'compare' => 'IN' ] ] ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			if ( $ctx['conv_ids'] ) {
				$hide[] = [ 'rule' => 'singular|page', 'select' => 'ids', 'ids' => $ctx['conv_ids'] ];
			}
		}
		return [ $show, $hide ];
	}

	/** An LB-owned post by type and _lb_key: the oldest one not in the trash, else the trashed one. */
	private static function find_post( string $type, string $key ): ?\WP_Post {
		foreach ( [ [ 'publish', 'draft', 'pending', 'private', 'future' ], [ 'trash' ] ] as $statuses ) {
			$f = get_posts( [ 'post_type' => $type, 'post_status' => $statuses, 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
			if ( $f ) {
				return $f[0];
			}
		}
		return null;
	}

	/** Ledger key of an LB chrome object. */
	private static function ledger_key( string $type, string $key ): string {
		return 'wp_block' === $type ? $key : $key . ':' . $type;
	}

	/**
	 * Hash of a chrome post's stored state for the Ledger: its content plus the given meta keys, where a missing, empty
	 * or false value all count as "not set" and numbers as strings (the block editor saves every registered element meta
	 * key, often as '' or a default, when an admin only opens and updates an element: that is not an edit).
	 */
	private static function hash_of( int $id, array $keys = [] ): string {
		$meta = [];
		foreach ( $keys as $k ) {
			$v = get_post_meta( $id, (string) $k, true );
			$meta[ (string) $k ] = ( '' === $v || null === $v || false === $v || [] === $v ) ? null : ( is_scalar( $v ) ? (string) $v : $v );
		}
		return Ledger::hash_value( [ 'content' => (string) get_post_field( 'post_content', $id, 'raw' ), 'meta' => $meta ] );
	}

	/**
	 * Create or update one LB-owned chrome post under the ownership rules.
	 *
	 * @param array $meta  meta key => value (null deletes) written with the post (Kadence placement / header meta).
	 * @param bool  $rekey re-key Kadence uniqueIDs for this post (stores).
	 * @return array{id:int,action:string}|\WP_Error action: create|update|keep|republish|kept_edits|admin_drafted|trashed
	 */
	private static function write_post( string $type, string $key, string $title, string $content, array $meta, array $hash_keys, bool $rekey, int $order, array &$ctx, array &$r ) {
		$lkey = self::ledger_key( $type, $key );
		$forced = in_array( 'all', $ctx['force'], true ) || in_array( $key, $ctx['force'], true );
		$p = self::find_post( $type, $key );
		$republish = false;
		if ( $p && 'trash' === $p->post_status ) {
			if ( ! $forced ) {
				$r['skipped'][] = [ 'key' => $key, 'type' => $type, 'id' => (int) $p->ID, 'why' => 'trashed: restore it from the trash, or pass force [' . $key . '] for a new one' ];
				return [ 'id' => (int) $p->ID, 'action' => 'trashed' ];
			}
			$p = null;
		}
		if ( $p && 'publish' !== $p->post_status ) {
			$row = Ledger::get( $lkey );
			$lb_draft = $row && ( ! empty( $row['pruned_by_lb'] ) || 'draft' === ( $row['status'] ?? '' ) );
			if ( ! $lb_draft && ! $forced ) {
				$r['skipped'][] = [ 'key' => $key, 'type' => $type, 'id' => (int) $p->ID, 'why' => 'an admin set it to ' . $p->post_status . ': publish it again yourself, or pass force [' . $key . ']' ];
				return [ 'id' => (int) $p->ID, 'action' => 'admin_drafted' ];
			}
			$republish = true;
		}
		if ( $p ) {
			$id = (int) $p->ID;
			// The title follows the page types the design covers, unless a person changed it (force takes LB's back).
			$ours = $forced || self::title_is_ours( $lkey, (string) $p->post_title );
			$edited = Ledger::is_edited( $lkey, self::hash_of( $id, $hash_keys ) );
			if ( $edited && ! $forced ) {
				$r['kept_edits'][] = [ 'key' => $key, 'type' => $type, 'id' => $id ];
				if ( $republish && ! $ctx['dry'] ) {
					wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
					Ledger::record( $lkey, $type, $id, (string) Ledger::get( $lkey )['hash'], 'publish' );
				}
				$renamed = $ours && (string) $p->post_title !== $title;
				if ( $renamed && ! $ctx['dry'] ) { // Only the name moves; the edited design and its hash stay as they are.
					global $wpdb;
					$wpdb->update( $wpdb->posts, [ 'post_title' => $title ], [ 'ID' => $id ] ); // The title only: wp_update_post() would run the content filters over the person's edited markup.
					clean_post_cache( $id );
					Ledger::record( $lkey, $type, $id, (string) Ledger::get( $lkey )['hash'], 'publish', $title );
				}
				return [ 'id' => $id, 'action' => 'kept_edits' ] + ( $renamed ? [ 'renamed' => true ] : [] );
			}
			$new = $rekey ? Unique_Ids::rekey( $content, $id, $key ) : $content;
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$same = (string) $p->post_content === $new && self::meta_same( $id, $meta ) && ( ! $ours || (string) $p->post_title === $title ) && ( $order <= 0 || (int) $p->menu_order === $order );
			$action = $republish ? 'republish' : ( $same ? 'keep' : 'update' );
			if ( $ctx['dry'] ) {
				return [ 'id' => $id, 'action' => $action ];
			}
			if ( ! $same || $republish ) {
				$args = [ 'ID' => $id, 'post_content' => wp_slash( $new ), 'post_status' => 'publish' ];
				if ( $ours ) {
					$args['post_title'] = wp_slash( $title );
				}
				if ( $order > 0 ) {
					$args['menu_order'] = $order;
				}
				$u = wp_update_post( $args, true );
				if ( is_wp_error( $u ) ) {
					return $u;
				}
				self::write_meta( $id, $meta );
			}
			update_post_meta( $id, '_lb_key', $key );
			Ledger::record( $lkey, $type, $id, self::hash_of( $id, $hash_keys ), 'publish', $ours ? $title : null );
			return [ 'id' => $id, 'action' => $action ];
		}
		if ( $ctx['dry'] ) {
			return [ 'id' => 0, 'action' => 'create' ];
		}
		// The address comes from the key, not the title (a title holds spaces and a middle dot, which make an ugly slug).
		$args = [ 'post_type' => $type, 'post_title' => $title, 'post_name' => sanitize_title( 'launch-builder-' . preg_replace( '/^chrome-/', '', $key ) ), 'post_status' => $rekey ? 'draft' : 'publish', 'post_content' => $rekey ? '' : wp_slash( $content ) ];
		if ( $order > 0 ) {
			$args['menu_order'] = $order;
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		update_post_meta( $id, '_lb_key', $key );
		if ( $rekey ) {
			$new = Unique_Ids::rekey( $content, $id, $key );
			if ( is_wp_error( $new ) ) {
				wp_delete_post( $id, true );
				return $new;
			}
			$u = wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( $new ), 'post_status' => 'publish' ], true );
			if ( is_wp_error( $u ) ) {
				return $u;
			}
		}
		self::write_meta( $id, $meta );
		Ledger::record( $lkey, $type, $id, self::hash_of( $id, $hash_keys ), 'publish', $title );
		return [ 'id' => $id, 'action' => 'create' ];
	}

	/**
	 * May Launch Builder rename this post? Only while its title is still the one LB wrote (the ledger remembers it); a row
	 * from before titles were tracked counts only when the title is exactly the old machine label.
	 */
	private static function title_is_ours( string $lkey, string $current ): bool {
		$last = Ledger::title( $lkey );
		if ( null !== $last ) {
			return $current === $last;
		}
		return 1 === preg_match( '/^Launch Builder chrome: [a-z_]+ \([a-z_, ]*\)$/', $current );
	}

	private static function write_meta( int $id, array $meta ): void {
		foreach ( $meta as $k => $v ) {
			if ( null === $v ) {
				delete_post_meta( $id, (string) $k );
			} else {
				update_post_meta( $id, (string) $k, is_string( $v ) ? wp_slash( $v ) : $v );
			}
		}
	}

	private static function meta_same( int $id, array $meta ): bool {
		foreach ( $meta as $k => $v ) {
			$k = (string) $k;
			if ( null === $v ) {
				if ( metadata_exists( 'post', $id, $k ) ) {
					return false;
				}
				continue;
			}
			$cur = get_post_meta( $id, $k, true );
			if ( is_array( $v ) ? $cur != $v : (string) $cur !== (string) $v ) { // phpcs:ignore Universal.Operators.StrictComparisons -- stored arrays come back with string scalars.
				return false;
			}
		}
		return true;
	}

	/**
	 * A group the map no longer uses: unedited LB chrome is drafted (pruned_by_lb); edited chrome stays but leaves every
	 * role (its _lb_roles are emptied, so it shows nowhere); adopted legacy elements (element-*) are drafted; other adopted
	 * elements get their own Kadence conditions back; referenced synced patterns are never touched.
	 */
	private static function release( string $slot, string $key, array $g, array &$ctx, array &$r, array $still = [] ): void {
		$kind = (string) ( $g['kind'] ?? '' );
		$el = (int) ( $g['element'] ?? 0 );
		if ( 'adopt' === $kind && $el ) {
			$legacy = 0 === strpos( (string) get_post_meta( $el, '_lb_key', true ), 'element-' );
			foreach ( $still as $sg ) {
				if ( (int) ( $sg['element'] ?? 0 ) === $el ) {
					return; // Adopted again under another key.
				}
			}
			$r['released'][] = [ 'key' => $key, 'slot' => $slot, 'element' => $el, 'what' => $legacy ? 'legacy element drafted' : 'adopted element given back its own Kadence conditions' ];
			if ( $ctx['dry'] ) {
				return;
			}
			if ( $legacy && 'publish' === get_post_status( $el ) ) {
				wp_update_post( [ 'ID' => $el, 'post_status' => 'draft' ] );
				Ledger::record( self::ledger_key( 'kadence_element', $key ), 'kadence_element', $el, self::hash_of( $el, self::KAD_META ), 'draft' );
				Ledger::mark_pruned( self::ledger_key( 'kadence_element', $key ) );
			}
			foreach ( [ '_lb_slot', '_lb_roles', '_lb_adopted' ] as $m ) {
				delete_post_meta( $el, $m );
			}
			return;
		}
		$keep_store = false;
		if ( $el && 'kadence_element' === get_post_type( $el ) && 0 === strpos( (string) get_post_meta( $el, '_lb_key', true ), 'chrome-' ) ) {
			$keep_store = ! self::prune_post( $el, 'kadence_element', $key, self::KAD_META, $ctx, $r );
		}
		if ( in_array( $kind, [ 'pattern', 'header' ], true ) && ! empty( $g['store'] ) && ! $keep_store ) {
			$type = 'header' === $kind ? 'kadence_header' : 'wp_block';
			self::prune_post( (int) $g['store'], $type, $key, [], $ctx, $r );
		}
	}

	/**
	 * Draft one LB chrome post if unedited. @return bool true when pruned (or already not published), false when kept
	 * because it was edited (an edited element is taken off every role instead).
	 */
	private static function prune_post( int $id, string $type, string $key, array $hash_keys, array &$ctx, array &$r ): bool {
		if ( 'publish' !== get_post_status( $id ) ) {
			return true;
		}
		$lkey = self::ledger_key( $type, $key );
		if ( Ledger::is_edited( $lkey, self::hash_of( $id, $hash_keys ) ) ) {
			$r['kept_edits'][] = [ 'key' => $key, 'type' => $type, 'id' => $id, 'note' => 'no longer in the map; kept because it was edited' . ( 'kadence_element' === $type ? ' (shown on no role)' : '' ) ];
			if ( 'kadence_element' === $type && ! $ctx['dry'] ) {
				update_post_meta( $id, '_lb_roles', [] );
			}
			return false;
		}
		$r['pruned'][] = [ 'key' => $key, 'type' => $type, 'id' => $id ];
		if ( ! $ctx['dry'] ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
			if ( ! Ledger::mark_pruned( $lkey ) ) {
				Ledger::record( $lkey, $type, $id, self::hash_of( $id, $hash_keys ), 'draft' );
				Ledger::mark_pruned( $lkey );
			}
		}
		return true;
	}

	/** Published LB chrome posts no index group points at (a lost index, a renamed slot): prune them like released groups. */
	private static function sweep_orphans( array $index, array &$ctx, array &$r ): void {
		$used = [];
		foreach ( (array) $index['slots'] as $s ) {
			foreach ( (array) ( $s['groups'] ?? [] ) as $g ) {
				$used[] = (int) ( $g['store'] ?? 0 );
				$used[] = (int) ( $g['element'] ?? 0 );
			}
		}
		$posts = get_posts( [ 'post_type' => [ 'wp_block', 'kadence_element', 'kadence_header' ], 'post_status' => 'publish', 'numberposts' => 200, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => 'chrome-', 'compare' => 'LIKE' ] ], 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $posts as $p ) {
			$key = (string) get_post_meta( $p->ID, '_lb_key', true );
			if ( 0 !== strpos( $key, 'chrome-' ) || in_array( (int) $p->ID, $used, true ) ) {
				continue;
			}
			self::prune_post( (int) $p->ID, $p->post_type, $key, 'kadence_element' === $p->post_type ? self::KAD_META : [], $ctx, $r );
		}
	}

	/**
	 * replace_legacy: legacy LB elements (element-*) on a chrome hook whose slot is applied are drafted (unless adopted),
	 * and legacy global areas of applied slots are cleared (snapshot kept).
	 */
	private static function replace_legacy( array $index, array &$ctx, array &$r ): void {
		$els = get_posts( [ 'post_type' => 'kadence_element', 'post_status' => 'publish', 'numberposts' => 100, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => 'element-', 'compare' => 'LIKE' ] ], 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$adopted = [];
		$walk = static function ( $v ) use ( &$walk, &$adopted ) { // An element the map adopts is in use: never draft it.
			if ( is_array( $v ) ) {
				if ( isset( $v['adopt'] ) ) {
					$adopted[ (int) $v['adopt'] ] = true;
				}
				foreach ( $v as $x ) {
					$walk( $x );
				}
			}
		};
		$walk( self::get() );
		foreach ( $els as $p ) {
			$slot = self::HOOK_SLOTS[ (string) get_post_meta( $p->ID, '_kad_element_hook', true ) ] ?? '';
			if ( '' === $slot || ! isset( $index['slots'][ $slot ] ) || '' !== (string) get_post_meta( $p->ID, '_lb_slot', true ) || isset( $adopted[ (int) $p->ID ] ) ) {
				continue;
			}
			$r['released'][] = [ 'key' => (string) get_post_meta( $p->ID, '_lb_key', true ), 'slot' => $slot, 'element' => (int) $p->ID, 'what' => 'legacy element drafted (replace_legacy)' ];
			if ( ! $ctx['dry'] ) {
				wp_update_post( [ 'ID' => $p->ID, 'post_status' => 'draft' ] );
			}
		}
		$areas = Globals::map();
		$owned = array_filter( Globals::AREA_SLOTS, static function ( $slot ) use ( $index ) { return isset( $index['slots'][ $slot ] ); } );
		$drop = array_intersect_key( $areas, $owned );
		if ( $drop ) {
			$r['released'][] = [ 'globals' => array_keys( $drop ), 'what' => 'legacy global areas cleared (replace_legacy)' ];
			if ( ! $ctx['dry'] ) {
				Abilities::snapshot( 'chrome replace_legacy', [], [ 'launch_builder_globals' ] );
				foreach ( array_keys( $drop ) as $a ) {
					Globals::set( (string) $a, 0 );
				}
			}
		}
	}

	/* ================================================================== tools */

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		Abilities::add( 'get-chrome', 'Read the chrome (shared designs per page role)', 'The chrome map (header, notice, hero, crumbs, template, notfound_body, before_footer, footer, sticky_bar per page role), the mode (elements = Kadence Pro Elements, patterns = printed by Launch Builder), what was compiled (stores, elements, ledger status: kept_edits, pruned, admin-drafted), the design each role gets, and non-Launch-Builder elements that shadow chrome. roles: true adds the per-role table.',
			[ 'properties' => [ 'roles' => [ 'type' => 'boolean', 'description' => 'Include the per-role design table (default true).' ] ] ], [ __CLASS__, 'get_tool' ], 'edit_theme_options', $ro );

		Abilities::add( 'set-chrome', 'Set the chrome map and apply it', 'Change the shared designs per page role and compile them. map is merged into the stored map (a role-keyed slot merges per role; null resets a slot or role to the default). DESIGN = "pattern-slug" | {pattern, slots, replace, markdown} | {sections:[...]} | {synced: key} | {adopt: element_id} | "theme" (Kadence\'s own) | false (nothing). notice may carry expires. mode: auto (default: Elements when Kadence Pro Elements is on) | elements | patterns. apply (default true) compiles; dry_run returns the plan only (create/update/keep/kept_edits/prune per object, compile_failed). Edited objects are kept unless their key is in force (["all"] needs confirm_all). replace_legacy drafts the old element-* elements and global areas of applied slots. Snapshots the map, mode and index first.',
			[ 'properties' => [
				'map'            => [ 'type' => 'object', 'description' => 'Partial chrome map, e.g. {"hero":{"service":"hero-service","conversion":false},"crumbs":{"roles":["page","service"]}}.' ],
				'mode'           => [ 'type' => 'string', 'enum' => [ 'auto', 'elements', 'patterns' ] ],
				'apply'          => [ 'type' => 'boolean' ],
				'dry_run'        => [ 'type' => 'boolean' ],
				'force'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Group keys (chrome-hero-1a2b3c4d...) to overwrite even if edited; ["all"] with confirm_all.' ],
				'confirm_all'    => [ 'type' => 'boolean' ],
				'slots'          => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( self::SLOTS ) ], 'description' => 'Apply only these slots (no orphan sweep).' ],
				'replace_legacy' => [ 'type' => 'boolean' ],
			] ], [ __CLASS__, 'set_tool' ], 'edit_theme_options' );
	}

	/** get-chrome */
	public static function get_tool( $input = [] ): array {
		$index = self::index();
		$out = [
			'mode'    => [ 'preference' => (string) get_option( self::OPT_MODE, 'auto' ), 'effective' => self::mode(), 'pro_elements' => post_type_exists( 'kadence_element' ), 'applied_in' => (string) ( $index['mode'] ?? '' ) ],
			'applied' => isset( $index['time'] ) ? gmdate( 'c', (int) $index['time'] ) : null,
			'map'     => self::get(),
			'stored'  => get_option( self::OPT_MAP, [] ),
			'slots'   => [],
		];
		if ( $out['applied'] && $out['mode']['applied_in'] !== $out['mode']['effective'] ) {
			$out['warnings'][] = 'The mode changed since the last apply (' . $out['mode']['applied_in'] . ' -> ' . $out['mode']['effective'] . '): run set-chrome apply.';
		}
		foreach ( (array) ( $index['slots'] ?? [] ) as $slot => $s ) {
			$rows = [];
			foreach ( (array) ( $s['groups'] ?? [] ) as $key => $g ) {
				$kind = (string) ( $g['kind'] ?? '' );
				$store_lkey = 'pattern' === $kind ? $key : ( 'header' === $kind ? self::ledger_key( 'kadence_header', $key ) : '' ); // synced/adopt stores are referenced, not owned.
				$rows[] = [
					'key'      => $key,
					'kind'     => $kind,
					'roles'    => $g['roles'] ?? [],
					'used_for' => self::page_types( (string) $slot, (array) ( $g['roles'] ?? [] ) ),
					'design'   => $g['design'] ?? null,
					'store'    => self::obj_status( (int) ( $g['store'] ?? 0 ), $store_lkey ),
					'element'  => empty( $g['element'] ) ? null : self::obj_status( (int) $g['element'], 'adopt' === $kind ? '' : self::ledger_key( 'kadence_element', $key ), self::KAD_META ),
					'flags'    => $g['flags'] ?? [],
					'expires'  => $g['expires'] ?? null,
				];
			}
			$failed = array_keys( array_filter( (array) ( $s['roles'] ?? [] ), static function ( $v ) { return 'failed' === $v; } ) );
			$out['slots'][ $slot ] = [ 'groups' => $rows, 'failed_roles' => $failed ];
		}
		if ( ! isset( $input['roles'] ) || ! empty( $input['roles'] ) ) {
			foreach ( self::roles() as $role ) {
				foreach ( array_keys( self::SLOTS ) as $slot ) {
					$st = self::state( $slot, $role );
					$g = self::group( $slot, $role );
					$out['roles'][ $role ][ $slot ] = [
						'design'     => self::design( $slot, $role ),
						'state'      => '' === $st ? 'not applied' : ( $g ? 'design' : $st ),
						'key'        => $g ? $g['key'] : null,
						'printed_by' => $g ? ( self::liveness( $g ) ?: 'nothing (switched off or missing)' ) : null,
					];
				}
			}
		}
		$out['foreign_elements'] = self::foreign_elements();
		$out['legacy_elements'] = self::legacy_elements();
		$last = get_option( self::OPT_REAPPLY, null );
		if ( $last ) {
			$out['last_reapply_check'] = $last;
		}
		return $out;
	}

	/** Status of one chrome object: id, post status, ledger status (kept_edits when edited since LB wrote it). */
	private static function obj_status( int $id, string $lkey, array $hash_keys = [] ): array {
		if ( ! $id ) {
			return [ 'id' => 0 ];
		}
		$p = get_post( $id );
		$o = [ 'id' => $id, 'type' => $p ? $p->post_type : '', 'status' => $p ? $p->post_status : 'missing', 'title' => $p ? $p->post_title : '' ];
		if ( '' !== $lkey && $p ) {
			$row = Ledger::get( $lkey );
			$o['ledger'] = $row ? ( Ledger::is_edited( $lkey, self::hash_of( $id, $hash_keys ) ) ? 'kept_edits' : ( ! empty( $row['pruned_by_lb'] ) ? 'pruned_by_lb' : 'owned' ) ) : 'no ledger row';
			if ( $row && 'publish' === ( $row['status'] ?? '' ) && 'publish' !== $p->post_status ) {
				$o['ledger'] = 'admin set it to ' . $p->post_status;
			}
		} else {
			$o['ledger'] = 'not owned (referenced)';
		}
		if ( $p && 'kadence_element' === $p->post_type ) {
			$o['menu_order'] = (int) $p->menu_order;
		}
		return $o;
	}

	/**
	 * Published elements Launch Builder does not own that print on a replace_* hook of a slot the map gives a design to,
	 * for roles their own conditions reach (they print beside or instead of the chrome).
	 */
	public static function foreign_elements(): array {
		if ( ! post_type_exists( 'kadence_element' ) ) {
			return [];
		}
		$out = [];
		$hooks = [ 'replace_header', 'replace_hero_header', 'replace_single_content', 'replace_archive_content', 'replace_404', 'replace_footer' ];
		foreach ( get_posts( [ 'post_type' => 'kadence_element', 'post_status' => 'publish', 'numberposts' => 200, 'meta_query' => [ [ 'key' => '_kad_element_hook', 'value' => $hooks, 'compare' => 'IN' ] ], 'suppress_filters' => true ] ) as $p ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$key = (string) get_post_meta( $p->ID, '_lb_key', true );
			if ( '' !== (string) get_post_meta( $p->ID, '_lb_slot', true ) || 0 === strpos( $key, 'chrome-' ) || 0 === strpos( $key, 'element-' ) ) {
				continue;
			}
			$hook = (string) get_post_meta( $p->ID, '_kad_element_hook', true );
			$slot = self::HOOK_SLOTS[ $hook ] ?? '';
			$reach = self::rules_roles( (array) json_decode( (string) get_post_meta( $p->ID, '_kad_element_show_conditionals', true ), true ), (array) json_decode( (string) get_post_meta( $p->ID, '_kad_element_hide_conditionals', true ), true ) );
			$hit = array_values( array_filter( $reach, static function ( $role ) use ( $slot ) { return false !== self::design( $slot, $role ); } ) );
			if ( $hit ) {
				$out[] = [ 'id' => (int) $p->ID, 'title' => $p->post_title, 'hook' => $hook, 'slot' => $slot, 'roles' => $hit, 'menu_order' => (int) $p->menu_order, 'note' => 'shadows/duplicates the chrome ' . $slot . ' on these roles' ];
			}
		}
		return $out;
	}

	/** Legacy LB elements (element-*) and whether they are adopted. */
	private static function legacy_elements(): array {
		if ( ! post_type_exists( 'kadence_element' ) ) {
			return [];
		}
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'kadence_element', 'post_status' => [ 'publish', 'draft' ], 'numberposts' => 100, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => 'element-', 'compare' => 'LIKE' ] ], 'suppress_filters' => true ] ) as $p ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$out[] = [ 'id' => (int) $p->ID, 'key' => (string) get_post_meta( $p->ID, '_lb_key', true ), 'status' => $p->post_status, 'hook' => (string) get_post_meta( $p->ID, '_kad_element_hook', true ), 'adopted' => '' !== (string) get_post_meta( $p->ID, '_lb_slot', true ) ];
		}
		return $out;
	}

	/** set-chrome */
	public static function set_tool( $input ) {
		$input = (array) $input;
		$map_in = $input['map'] ?? [];
		if ( ! is_array( $map_in ) ) {
			return new \WP_Error( 'lb_map', 'map must be an object.', [ 'status' => 400 ] );
		}
		[ $clean, $errors ] = self::validate_map( $map_in );
		if ( $errors ) {
			return new \WP_Error( 'lb_map', 'The map has errors: ' . implode( '; ', $errors ), [ 'status' => 400, 'errors' => $errors ] );
		}
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : null;
		if ( null !== $mode && ! in_array( $mode, [ 'auto', 'elements', 'patterns' ], true ) ) {
			return new \WP_Error( 'lb_mode', 'mode is auto, elements or patterns.', [ 'status' => 400 ] );
		}
		$stored = get_option( self::OPT_MAP, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$new_stored = self::merge_stored( $stored, $clean );
		$dry = ! empty( $input['dry_run'] );
		$apply = ! array_key_exists( 'apply', $input ) || ! empty( $input['apply'] );
		$out = [ 'dry_run' => $dry ];
		if ( $dry ) {
			$eff = self::DEFAULT_MAP;
			foreach ( [ Style::chrome_defaults(), $new_stored ] as $layer ) {
				$eff = self::merge( $eff, (array) $layer );
			}
			$out['map'] = $eff;
			$plan = self::apply( [ 'dry_run' => true, 'map' => $eff, 'force' => $input['force'] ?? [], 'confirm_all' => ! empty( $input['confirm_all'] ), 'slots' => $input['slots'] ?? [], 'replace_legacy' => ! empty( $input['replace_legacy'] ) ] );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$out['plan'] = $plan;
			if ( null !== $mode ) {
				$out['note'] = 'The plan uses the current mode (' . self::mode() . '); the mode you passed applies once you run without dry_run.';
			}
			return $out;
		}
		$out['snapshot'] = Abilities::snapshot( 'set-chrome', Breadcrumbs::THEME_MODS, [ self::OPT_MAP, self::OPT_MODE, self::OPT_INDEX ] );
		if ( $clean ) {
			update_option( self::OPT_MAP, $new_stored, true );
		}
		if ( null !== $mode ) {
			update_option( self::OPT_MODE, $mode, true );
		}
		$out['map'] = self::get();
		$out['mode'] = [ 'preference' => (string) get_option( self::OPT_MODE, 'auto' ), 'effective' => self::mode() ];
		if ( $apply ) {
			$r = self::apply( [ 'force' => $input['force'] ?? [], 'confirm_all' => ! empty( $input['confirm_all'] ), 'slots' => $input['slots'] ?? [], 'replace_legacy' => ! empty( $input['replace_legacy'] ) ] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$out['applied'] = $r;
		} else {
			Cache::purge_later();
		}
		return $out;
	}

	/** Merge a validated partial map into the stored map (per role for role-keyed slots; null removes). */
	private static function merge_stored( array $stored, array $clean ): array {
		foreach ( $clean as $slot => $v ) {
			if ( null === $v ) {
				unset( $stored[ $slot ] );
				continue;
			}
			$keyed = ( in_array( $slot, self::ROLE_SLOTS, true ) || 'crumbs' === $slot ) && is_array( $v ) && ! self::is_design_object( $v );
			if ( $keyed && isset( $stored[ $slot ] ) && is_array( $stored[ $slot ] ) && ! self::is_design_object( $stored[ $slot ] ) ) {
				foreach ( $v as $k => $x ) {
					if ( null === $x ) {
						unset( $stored[ $slot ][ $k ] );
					} else {
						$stored[ $slot ][ $k ] = $x;
					}
				}
				if ( ! $stored[ $slot ] ) {
					unset( $stored[ $slot ] );
				}
			} else {
				$stored[ $slot ] = $keyed ? array_filter( $v, static function ( $x ) { return null !== $x; } ) : $v;
			}
		}
		return $stored;
	}

	/* ================================================================== module hooks */

	public static function site_state( array &$state ): void {
		$index = self::index();
		$groups = 0;
		$failed = 0;
		foreach ( (array) ( $index['slots'] ?? [] ) as $s ) {
			$groups += count( (array) ( $s['groups'] ?? [] ) );
			$failed += count( array_filter( (array) ( $s['roles'] ?? [] ), static function ( $v ) { return 'failed' === $v; } ) );
		}
		$state['chrome'] = [ 'preference' => (string) get_option( self::OPT_MODE, 'auto' ), 'mode' => self::mode(), 'applied' => isset( $index['time'] ) ? gmdate( 'c', (int) $index['time'] ) : null, 'applied_slots' => array_keys( (array) ( $index['slots'] ?? [] ) ), 'groups' => $groups, 'failed_roles' => $failed ];
	}

	public static function playbook( array &$pb ): void {
		$pb['chrome'] = 'CHROME = the shared designs every page of a role gets: header, notice bar, hero, breadcrumbs, entry template (services/areas), 404 body, before-footer stack, footer, mobile sticky bar. One map says which design each role uses (get-chrome shows it; set-chrome changes and applies it). A design is a pattern slug, a stack of patterns, a synced pattern, "theme" (Kadence\'s own) or false. With Kadence Pro the designs are placed as Elements (one per design, holding a synced pattern), without Pro Launch Builder prints the same synced patterns. Page types that use the same design share one Element. Each Element and synced pattern is named "Launch Builder · part of the page · page types" (for example "Launch Builder · Hero · Service pages"), a name you change yourself is kept, and the Elements list has a Launch Builder view and a Used for column. Pages never carry their own hero or breadcrumbs: a page whose content opens with its own hero has _lb_hero "content" (set-page-layout title "hide" does that), so the chrome hero and crumbs step aside.';
		$pb['tools_by_job']['Chrome'] = 'get-chrome, set-chrome';
	}

	public static function audit_checks(): array {
		return [
			'chrome_missing'         => [ 'chrome', [ __CLASS__, 'audit_missing' ] ],
			'chrome_kept_edits'      => [ 'chrome', [ __CLASS__, 'audit_kept_edits' ] ],
			'foreign_element_shadow' => [ 'chrome', [ __CLASS__, 'audit_foreign' ] ],
			'elements_module_off'    => [ 'chrome', [ __CLASS__, 'audit_mode' ] ],
		];
	}

	public static function audit_missing( array $args = [] ): array {
		$out = [];
		foreach ( (array) ( self::index()['slots'] ?? [] ) as $slot => $s ) {
			foreach ( (array) ( $s['roles'] ?? [] ) as $role => $st ) {
				if ( 'failed' === $st || ( 0 === strpos( (string) $st, 'chrome-' ) && ! self::liveness( (array) ( $s['groups'][ $st ] ?? [] ) ) ) ) {
					$out[] = [ 'severity' => 'warning', 'object_type' => 'chrome', 'id' => 0, 'url' => '', 'message' => "No live $slot design for role $role (" . ( 'failed' === $st ? 'its design did not compile' : 'switched off or missing' ) . ').', 'fix' => 'get-chrome, then set-chrome' ];
				}
			}
		}
		return $out;
	}

	public static function audit_kept_edits( array $args = [] ): array {
		$out = [];
		foreach ( (array) ( self::index()['slots'] ?? [] ) as $slot => $s ) {
			foreach ( (array) ( $s['groups'] ?? [] ) as $key => $g ) {
				foreach ( [ 'store', 'element' ] as $w ) {
					$id = (int) ( $g[ $w ] ?? 0 );
					if ( ! $id || 'adopt' === ( $g['kind'] ?? '' ) || ( 'store' === $w && 'synced' === ( $g['kind'] ?? '' ) ) ) {
						continue;
					}
					$type = (string) get_post_type( $id );
					if ( $type && Ledger::is_edited( self::ledger_key( $type, $key ), self::hash_of( $id, 'kadence_element' === $type ? self::KAD_META : [] ) ) ) {
						$out[] = [ 'severity' => 'info', 'object_type' => $type, 'id' => $id, 'url' => (string) get_edit_post_link( $id, 'raw' ), 'message' => "Chrome $slot ($key) was edited after Launch Builder wrote it; re-applying keeps the edits.", 'fix' => 'set-chrome force ["' . $key . '"] to take Launch Builder\'s version' ];
					}
				}
			}
		}
		return $out;
	}

	public static function audit_foreign( array $args = [] ): array {
		return array_map( static function ( $f ) { return [ 'severity' => 'warning', 'object_type' => 'kadence_element', 'id' => $f['id'], 'url' => (string) get_edit_post_link( $f['id'], 'raw' ), 'message' => 'Element "' . $f['title'] . '" (' . $f['hook'] . ') prints beside or instead of the chrome ' . $f['slot'] . ' on: ' . implode( ', ', $f['roles'] ), 'fix' => 'Draft it, or adopt it: set-chrome map {"' . $f['slot'] . '": {"<role>": {"adopt": ' . $f['id'] . '}}}' ]; }, self::foreign_elements() );
	}

	public static function audit_mode( array $args = [] ): array {
		if ( 'elements' === get_option( self::OPT_MODE, 'auto' ) && 'patterns' === self::mode() ) {
			return [ [ 'severity' => 'warning', 'object_type' => 'option', 'id' => 0, 'url' => '', 'message' => 'Chrome is set to Kadence Pro Elements but the Elements module is off (or Theme Kit Pro is inactive): Launch Builder prints the chrome itself.', 'fix' => 'set-pro-modules {"elements": true}, or set-chrome mode "auto"' ] ];
		}
		return [];
	}

	/* ================================================================== lifecycle: park on deactivation, unpark on activation */

	/** Plugin deactivation: draft every published LB chrome element (without LB they would show on every page). */
	public static function deactivate(): void {
		$els = get_posts( [ 'post_type' => 'kadence_element', 'post_status' => 'publish', 'numberposts' => 200, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => 'chrome-', 'compare' => 'LIKE' ] ], 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$parked = (array) get_option( self::OPT_PARKED, [] );
		foreach ( $els as $p ) {
			$key = (string) get_post_meta( $p->ID, '_lb_key', true );
			if ( 0 !== strpos( $key, 'chrome-' ) ) {
				continue;
			}
			$lkey = self::ledger_key( 'kadence_element', $key );
			$row = Ledger::get( $lkey );
			wp_update_post( [ 'ID' => $p->ID, 'post_status' => 'draft' ] );
			Ledger::record( $lkey, 'kadence_element', $p->ID, $row ? (string) $row['hash'] : self::hash_of( $p->ID, self::KAD_META ), 'draft' );
			$parked[] = (int) $p->ID;
		}
		update_option( self::OPT_PARKED, array_values( array_unique( array_map( 'intval', $parked ) ) ), false );
	}

	/** Plugin activation: the next migration run republishes what deactivation parked. */
	public static function activate(): void {
		Migrate::rerun( 'chrome-unpark' );
	}

	/** Migration step chrome-unpark: republish parked chrome elements that are still drafts (an admin's later choice wins). */
	public static function migrate_unpark( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$ids = array_map( 'intval', (array) get_option( self::OPT_PARKED, [] ) );
		$done = [];
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			$key = $p ? (string) get_post_meta( $id, '_lb_key', true ) : '';
			if ( ! $p || 'draft' !== $p->post_status || 0 !== strpos( $key, 'chrome-' ) ) {
				continue;
			}
			$lkey = self::ledger_key( 'kadence_element', $key );
			$row = Ledger::get( $lkey );
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
			Ledger::record( $lkey, 'kadence_element', $id, $row ? (string) $row['hash'] : self::hash_of( $id, self::KAD_META ), 'publish' );
			$done[] = $id;
		}
		delete_option( self::OPT_PARKED );
		if ( $done ) {
			Cache::purge_later();
		}
		return [ 'done' => true, 'report' => $done ? 'republished parked chrome elements: ' . implode( ', ', $done ) : ( $ids ? 'nothing to republish (parked elements were changed meanwhile)' : 'nothing parked' ) ];
	}

	/* ================================================================== migrations */

	/**
	 * Which roles an element's native rules reach (singular|page with ids: the roles of those pages; hide rules for whole
	 * types and the front page are taken off; id-based hide rules stay in force natively).
	 *
	 * @return string[]
	 */
	public static function rules_roles( array $show, array $hide ): array {
		$all = self::roles();
		$page_roles = [ 'page', 'legal', 'services_hub', 'areas_hub', 'category', 'conversion', 'thanks' ];
		$static_front = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' );
		$roles = [];
		foreach ( $show as $rule ) {
			$rule = (array) $rule;
			$r = (string) ( $rule['rule'] ?? '' );
			$sel = (string) ( $rule['select'] ?? 'all' );
			if ( 'ids' === $sel && 0 === strpos( $r, 'singular|' ) ) {
				foreach ( (array) ( $rule['ids'] ?? [] ) as $id ) {
					$roles[] = Roles::of( (int) $id );
				}
				continue;
			}
			switch ( $r ) {
				case 'general|site':
					$roles = array_merge( $roles, $all );
					break;
				case 'general|front_page':
					$roles[] = 'home';
					break;
				case 'general|singular':
					$roles = array_merge( $roles, $page_roles, [ 'service', 'area', 'post' ], $static_front ? [ 'home' ] : [] );
					break;
				case 'singular|page':
					$roles = array_merge( $roles, $page_roles, $static_front ? [ 'home' ] : [] );
					break;
				case 'singular|' . Entries::SERVICE:
					$roles[] = 'service';
					break;
				case 'singular|' . Entries::AREA:
					$roles[] = 'area';
					break;
				case 'singular|post':
					$roles[] = 'post';
					break;
				case 'general|archive':
				case 'general|home':
				case 'tax_archive|category':
				case 'tax_archive|post_tag':
				case 'general|date':
				case 'general|author':
					$roles[] = 'blog';
					break;
				case 'tax_archive|' . Entries::CAT:
					$roles[] = 'category';
					break;
				case 'general|search':
					$roles[] = 'search';
					break;
				case 'general|404':
					$roles[] = 'notfound';
					break;
			}
		}
		foreach ( $hide as $rule ) {
			$rule = (array) $rule;
			$r = (string) ( $rule['rule'] ?? '' );
			if ( 'ids' === ( $rule['select'] ?? 'all' ) ) {
				continue;
			}
			$off = [ 'general|front_page' => [ 'home' ], 'singular|page' => $page_roles, 'singular|' . Entries::SERVICE => [ 'service' ], 'singular|' . Entries::AREA => [ 'area' ], 'singular|post' => [ 'post' ], 'general|search' => [ 'search' ], 'general|404' => [ 'notfound' ], 'general|archive' => [ 'blog' ] ][ $r ] ?? [];
			$roles = array_diff( $roles, $off );
		}
		return array_values( array_intersect( $all, array_unique( $roles ) ) );
	}

	/**
	 * Migration step chrome-adopt-legacy (step 1): adopt what a site built before chrome uses, by
	 * reference, so it renders the same: legacy elements (element-*) on chrome hooks -> {adopt: id} for the roles their
	 * own rules reach; global areas above_footer/footer/above_header -> before_footer/footer/notice {synced}; the synced
	 * service_template/area_template (patterns mode) -> template {synced}. Then those slots are applied (nothing new is
	 * built, nothing pruned) and the adopted global areas leave launch_builder_globals (snapshot kept). With the option
	 * launch_builder_chrome_upgrade.replace_legacy nothing is adopted (set-chrome replace_legacy replaces it later).
	 */
	public static function migrate_adopt( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$up = (array) get_option( self::OPT_UPGRADE, [] );
		if ( ! empty( $up['replace_legacy'] ) ) {
			return [ 'done' => true, 'report' => 'replace_legacy is set: legacy chrome left for set-chrome replace_legacy' ];
		}
		if ( self::index() ) {
			return [ 'done' => true, 'report' => 'chrome already applied: nothing to adopt' ];
		}
		$add = [];
		$notes = [];
		$all = self::roles();
		if ( 'elements' === self::mode() ) { // Legacy elements only printed while Pro's Elements ran; without it they stay as they are.
			$els = get_posts( [ 'post_type' => 'kadence_element', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order', 'order' => 'ASC', 'meta_query' => [ [ 'key' => '_lb_key', 'value' => 'element-', 'compare' => 'LIKE' ] ], 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $els as $p ) {
				if ( '' !== (string) get_post_meta( $p->ID, '_lb_slot', true ) ) {
					continue;
				}
				$slot = self::HOOK_SLOTS[ (string) get_post_meta( $p->ID, '_kad_element_hook', true ) ] ?? '';
				if ( '' === $slot || 'replace_archive_content' === get_post_meta( $p->ID, '_kad_element_hook', true ) ) {
					continue;
				}
				$roles = self::rules_roles( (array) json_decode( (string) get_post_meta( $p->ID, '_kad_element_show_conditionals', true ), true ), (array) json_decode( (string) get_post_meta( $p->ID, '_kad_element_hide_conditionals', true ), true ) );
				if ( 'template' === $slot ) {
					$roles = array_values( array_intersect( $roles, [ 'service', 'area' ] ) );
				}
				if ( 'notfound_body' === $slot ) {
					$roles = array_values( array_intersect( $roles, [ 'notfound' ] ) );
				}
				$roles = array_values( array_filter( $roles, static function ( $role ) use ( $add, $slot ) { return ! isset( $add[ $slot ][ $role ] ); } ) ); // First element (menu_order) wins a role.
				if ( ! $roles ) {
					continue;
				}
				foreach ( $roles as $role ) {
					$add[ $slot ][ $role ] = [ 'adopt' => (int) $p->ID ];
				}
				$notes[] = $slot . ' <- element ' . $p->ID . ' (' . get_post_meta( $p->ID, '_lb_key', true ) . ') for ' . implode( ', ', $roles );
			}
		}
		$areas = Globals::map();
		$drop = [];
		foreach ( Globals::AREA_SLOTS as $area => $slot ) {
			$id = (int) ( $areas[ $area ] ?? 0 );
			if ( ! $id || 'publish' !== get_post_status( $id ) || isset( $add[ $slot ] ) ) {
				continue;
			}
			$k = (string) get_post_meta( $id, '_lb_key', true );
			$d = [ 'synced' => 0 === strpos( $k, 'synced-' ) ? substr( $k, 7 ) : $id ];
			foreach ( 'notice' === $slot ? [ '*' ] : $all as $role ) {
				$add[ $slot ][ $role ] = $d;
			}
			$drop[] = $area;
			$notes[] = $slot . ' <- global area ' . $area . ' (synced pattern ' . $id . ')';
		}
		foreach ( [ 'service' => 'service_template', 'area' => 'area_template' ] as $role => $sk ) {
			$id = Blueprint::find_synced( $sk );
			if ( $id && 'publish' === get_post_status( $id ) && ! isset( $add['template'][ $role ] ) && 'patterns' === self::mode() ) {
				$add['template'][ $role ] = [ 'synced' => $sk ];
				$notes[] = 'template <- synced ' . $sk . ' for ' . $role;
			}
		}
		if ( ! $add ) {
			return [ 'done' => true, 'report' => 'no legacy chrome to adopt' ];
		}
		Abilities::snapshot( 'migrate chrome-adopt-legacy', [], [ self::OPT_MAP, 'launch_builder_globals' ] );
		$stored = get_option( self::OPT_MAP, [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $add as $slot => $by_role ) {
			if ( isset( $by_role['*'] ) || ! in_array( $slot, self::ROLE_SLOTS, true ) ) {
				$stored[ $slot ] = reset( $by_role );
				continue;
			}
			$cur = isset( $stored[ $slot ] ) && is_array( $stored[ $slot ] ) && ! self::is_design_object( $stored[ $slot ] ) ? $stored[ $slot ] : [];
			$stored[ $slot ] = array_merge( $cur, $by_role );
		}
		update_option( self::OPT_MAP, $stored, true );
		$r = self::apply( [ 'slots' => array_keys( $add ), 'prune' => false ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( $drop as $area ) {
			Globals::set( $area, 0 );
		}
		if ( ! Globals::map() ) {
			delete_option( 'launch_builder_globals' );
		}
		return [ 'done' => true, 'report' => [ 'adopted' => $notes, 'globals_cleared' => $drop, 'compile_failed' => $r['compile_failed'] ] ];
	}

	/**
	 * Migration step chrome-page-heroes (step 2), batched by post id over pages and entries: a page whose
	 * content opens with its own hero (post title, breadcrumbs or an H1 in the first block) gets _lb_hero 'content' (or,
	 * with launch_builder_chrome_upgrade.strip_content_heroes, loses those leading blocks when its role has a chrome
	 * hero); _kad_post_title values LB wrote are removed where the policy gives the same result ('show' always unless the
	 * type's title is off in the Customizer; 'hide' when _lb_hero is content/none). Other 'hide' values stay.
	 */
	public static function migrate_page_heroes( $cursor, int $batch ): array {
		$after = (int) $cursor;
		$up = (array) get_option( self::OPT_UPGRADE, [] );
		$strip = ! empty( $up['strip_content_heroes'] );
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('page', %s, %s) AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d", $after, Entries::SERVICE, Entries::AREA, $batch ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = [ 'content' => 0, 'stripped' => 0, 'title_removed' => 0, 'title_kept' => 0 ];
		foreach ( $ids as $id ) {
			$hero = (string) get_post_meta( $id, self::HERO_META, true );
			$type = (string) get_post_type( $id );
			if ( '' === $hero && self::content_hero_of( $id ) ) {
				$role = Roles::of( $id );
				if ( $strip && 'page' === $type && ! in_array( $role, [ 'home', 'blog' ], true ) && ! in_array( self::design( 'hero', $role ), [ false, 'theme' ], true ) ) {
					$blocks = parse_blocks( (string) get_post_field( 'post_content', $id, 'raw' ) );
					$keep = [];
					$leading = true;
					foreach ( $blocks as $b ) {
						if ( $leading && ( empty( $b['blockName'] ) ? '' === trim( (string) $b['innerHTML'] ) : self::block_is_hero( $b ) ) ) {
							continue;
						}
						$leading = false;
						$keep[] = $b;
					}
					wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( serialize_blocks( $keep ) ) ] );
					$n['stripped']++;
				} else {
					update_post_meta( $id, self::HERO_META, 'content' );
					$hero = 'content';
					$n['content']++;
				}
			}
			$t = (string) get_post_meta( $id, '_kad_post_title', true );
			if ( 'show' === $t && false !== get_theme_mod( $type . '_title', true ) ) {
				delete_post_meta( $id, '_kad_post_title' );
				$n['title_removed']++;
			} elseif ( 'hide' === $t && in_array( $hero, [ 'content', 'none' ], true ) ) {
				delete_post_meta( $id, '_kad_post_title' );
				$n['title_removed']++;
			} elseif ( '' !== $t ) {
				$n['title_kept']++;
			}
		}
		$done = count( $ids ) < $batch;
		if ( $done ) {
			Cache::purge_later();
		}
		return [ 'done' => $done, 'cursor' => $ids ? max( $ids ) : $after, 'report' => array_filter( $n ) ? $n : '' ];
	}

	/**
	 * Migration step chrome-reapply (every version): a dry-run apply; the plan is kept for get-chrome ("what an update
	 * would change"). With the option launch_builder_auto_update_chrome on, unedited chrome is applied for real (edited
	 * objects are kept as always).
	 */
	public static function migrate_reapply( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		if ( ! self::index() ) {
			return [ 'done' => true, 'report' => 'chrome not applied yet' ];
		}
		$plan = self::apply( [ 'dry_run' => true ] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$changes = [];
		foreach ( $plan['groups'] as $g ) {
			foreach ( [ 'store', 'element' ] as $w ) {
				$a = (string) ( $g[ $w ]['action'] ?? '' );
				if ( in_array( $a, [ 'create', 'update', 'republish' ], true ) ) {
					$changes[] = $g['key'] . ' ' . $w . ': ' . $a;
				}
			}
		}
		$applied = false;
		if ( $changes && get_option( self::OPT_AUTO ) ) {
			$r = self::apply();
			$applied = ! is_wp_error( $r );
		}
		update_option( self::OPT_REAPPLY, [ 'time' => gmdate( 'c' ), 'version' => LB_VERSION, 'changes' => $changes, 'kept_edits' => $plan['kept_edits'], 'compile_failed' => count( $plan['compile_failed'] ), 'auto_applied' => $applied ], false );
		return [ 'done' => true, 'report' => $changes ? ( $applied ? 'applied: ' : 'would change (auto_update_chrome off): ' ) . implode( '; ', $changes ) : 'chrome up to date' ];
	}
}
