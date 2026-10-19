<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Kadence Conversions (popups, slide-ins, banners) as Launch Builder items, written and kept up to date by key.
 *
 * What Kadence Conversions 1.1.4 does (read from its source):
 *  - Items are posts of the type kadence_conversions (capability edit_kadence_conversions, granted to every user who has
 *    edit_theme_options). The design is the post content: ONE "Conversion Item" block (kadence-conversions/conversion)
 *    whose attributes hold the launch trigger, conversion type, analytics, goal, repeat control, position and size, and
 *    whose inner blocks are the design. The block is rendered on the server (render_callback returns the saved markup
 *    and hands its settings to the front-end script), so the saved markup must be exactly what the block's save() makes.
 *  - Where an item shows is post meta (JSON strings, the same rule vocabulary as Kadence Pro Elements): _kad_conversion_show
 *    / _hide ([{rule: 'general|site' | 'singular|page' | 'tax_archive|<tax>' ..., select: all|ids, ids: [int]}]),
 *    _kad_conversion_user ([{role: public|logged_in|logged_out|<wp role>}]), _kad_conversion_device ([{value: desktop|
 *    tablet|mobile, label}], checked by the script), _kad_conversion_enable_expires + _kad_conversion_expires (site-local
 *    'Y-m-d\TH:i:s'), _kad_conversion_type and _kad_conversion_trigger (list-table copies of the block attributes).
 *    The double-underscore keys (__kad_conversion_expires / __kad_conversion_language) are read only by the admin list
 *    table's meta reader, which never displays them; the front end reads the single-underscore keys, so that is what we
 *    write (expiry verified on a live instance, see the test).
 *  - Display: at 'wp' (priority 99) every published item passes its rules, then the filter
 *    kadence_conversion_display( $show, $post, $meta ). Shown items print in wp_footer; the script decides when (trigger,
 *    cookies, device, schedule).
 *
 * Launch Builder items: post meta _lb_key = 'conversion-<key>' (also the Ledger key), block uniqueID and campaign id
 * (the repeat-control cookie) = 'lbc-<key>'. Page roles are kept two ways:
 *  - native rules, written for legibility in the Conversions UI and so that the item still behaves when Launch Builder is
 *    off: show rules may be BROADER than the roles, hide rules are NEVER broader (a native hide cannot be undone);
 *  - the display filter (kadence_conversion_display, priority 20), which is exact: show_roles (empty = every role) and
 *    hide_roles against Roles::current().
 * The filter applies only while the item's native rules are the ones Launch Builder wrote (_lb_rules = their signature):
 * once an admin changes the display conditions in the Conversions UI, those decide alone (roles_enforced false).
 * Native page-id lists follow role changes (page role meta, hubs, category pages): resync() rewrites them on unedited
 * items. Every write goes through the Ledger: an item an admin edited is reported (kept_edits) and left alone unless
 * forced; an item an admin drafted or trashed is never republished unless forced; delete-conversion drafts (prunes).
 *
 * Chrome contract: while Kadence Conversions is active it OWNS the sticky_bar and notice slots
 * (owns_slot()): Chrome hands the slot's map value to apply_slot(), which writes one banner per distinct design (keys
 * chrome-<slot>-<md5(design)[0..8]>) and drafts the slot's banners that left the map. Chrome still places its own
 * fallback for the slot and hides it at render time only while slot_live( $slot, Roles::current() ) is true, i.e. while
 * a published banner of the slot actually covers the page; so (de)activating Conversions never leaves a site without
 * its sticky call bar or notice bar, and never shows two.
 */
final class Conversions {

	const MODULE = [
		'slug'        => 'conversions',
		'label'       => 'Conversions',
		'description' => 'Exit-intent popups, slide-ins and banners as Kadence Conversions items, kept up to date by key and shown by page role. Does nothing while Kadence Conversions is not active.',
		'toggle'      => true,
		'default'     => true,
	];

	const POST_TYPE  = 'kadence_conversions';
	const BLOCK      = 'kadence-conversions/conversion';
	const KEY_PREFIX = 'conversion-'; // _lb_key and Ledger key.
	const ID_PREFIX  = 'lbc-';        // Block uniqueID (link trigger href "#lbc-<key>") and campaign id (cookie name).

	const TYPES        = [ 'popup', 'banner', 'slide_in' ];
	const TYPE_ALIASES = [ 'bar' => 'banner', 'slide-in' => 'slide_in', 'slidein' => 'slide_in' ];

	/** Our trigger names => the block's conversionTrigger values. */
	const TRIGGERS = [ 'exit_intent' => 'exit_intent', 'delay' => 'time', 'scroll' => 'scroll', 'click' => 'link', 'load' => 'load', 'content_end' => 'content_end' ];

	const DEVICES   = [ 'desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile' ];
	const AUDIENCES = [ 'all', 'logged_out', 'logged_in' ];
	const GOALS     = [ 'button', 'form', 'none' ];

	/** Roles an item is hidden on unless hide_roles says otherwise (visitors there are already converting, or reading legal text). */
	const DEFAULT_HIDE = [ 'conversion', 'thanks', 'legal' ];

	/** The class on the button that counts as the goal (the owner's Conversions setup: Click Button + this class). */
	const GOAL_CLASS = 'conversion-button';

	/** Designs used when an item names none. The pattern program creates them (Slot: conversion); until then they report compile_failed. */
	const DEFAULT_DESIGNS = [ 'popup' => 'popup-exit-intent', 'slide_in' => 'slide-in-quote', 'banner' => 'bar-notice' ];

	/** The exit-intent popup every brief ships with (opt out with conversions.exit_intent = false). */
	const DEFAULT_EXIT_INTENT = [ 'key' => 'exit-intent', 'type' => 'popup', 'trigger' => 'exit_intent', 'design' => 'popup-exit-intent' ];

	/** Chrome slots Conversions takes over while it is active: how their banners are set up. */
	const SLOTS = [
		'sticky_bar' => [ 'position' => 'bottom', 'devices' => [ 'mobile' ], 'close' => false, 'repeat' => 0, 'title' => 'Sticky call bar' ],
		'notice'     => [ 'position' => 'top', 'devices' => [], 'close' => true, 'repeat' => 1, 'title' => 'Notice bar' ],
	];

	/** Stored state of an item besides its content (the Ledger hash covers content + these). */
	const META_KEYS = [
		'_kad_conversion_type', '_kad_conversion_trigger', '_kad_conversion_show', '_kad_conversion_hide', '_kad_conversion_all_show',
		'_kad_conversion_user', '_kad_conversion_user_hidden', '_kad_conversion_device', '_kad_conversion_enable_expires',
		'_kad_conversion_expires', '_kad_conversion_starts', '_kad_conversion_enable_recurring', '_kad_conversion_language',
		'_lb_key', '_lb_show_roles', '_lb_hide_roles', '_lb_rules', '_lb_input', '_lb_src',
	];

	/** Launch Builder's own meta: left out when Conversions duplicates an item (the copy is a plain item). */
	const LB_META = [ '_lb_key', '_lb_show_roles', '_lb_hide_roles', '_lb_rules', '_lb_input', '_lb_src' ];

	/** Input fields that must be scalars (strings, numbers, booleans) when present. */
	const SCALAR_FIELDS = [ 'key', 'type', 'trigger', 'delay', 'scroll', 'audience', 'expires', 'starts', 'repeat', 'repeat_after_goal', 'goal', 'goal_class', 'close_on_goal', 'analytics', 'position', 'offset', 'close', 'width', 'title', 'status', 'force', 'dry_run' ];

	/** The plugin file of Kadence Conversions (its folder may be renamed). */
	const PLUGIN_FILE = 'kadence-conversions.php';

	/** The block's own defaults for the attributes its save() reads (build/conversions.js). */
	const BLOCK_DEFAULTS = [
		'horizontalAlign' => 'center',
		'verticalAlign'   => 'center',
		'height'          => 'auto',
		'innerVAlign'     => 'center',
		'animateIn'       => 'fadeup',
		'animateOut'      => 'fadeout',
		'displayClose'    => true,
		'closePosition'   => 'outside',
		'closeAlign'      => 'right',
		'closeVAlign'     => 'top',
		'closeContent'    => 'icon',
		'closeIcon'       => 'close2',
	];

	/** The block's close2 icon, exactly as its save() serializes it. */
	const CLOSE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path d="M13.957 3.457l-1.414-1.414L8 6.586 3.457 2.043 2.043 3.457 6.586 8l-4.543 4.543 1.414 1.414L8 9.414l4.543 4.543 1.414-1.414L9.414 8z"></path></svg>';

	/** @var bool a resync is running (its own meta writes must not start another). */
	private static $resyncing = false;

	/** @var array|null per request: slot => [ { show: roles|null, hide: roles } ] of the published chrome banners (null = not read yet). */
	private static $live = null;

	public static function boot(): void {
		add_action( 'activated_plugin', [ __CLASS__, 'plugin_toggled' ] );
		add_action( 'deactivated_plugin', [ __CLASS__, 'plugin_toggled' ] );
		add_filter( 'kadence_conversion_display', [ __CLASS__, 'display' ], 20, 3 );
		add_filter( 'kadence_conversions_duplicate_post_exclude_list', [ __CLASS__, 'duplicate_exclude' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'post_meta_changed' ], 10, 3 );
		}
		foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'term_meta_changed' ], 10, 3 );
		}
		foreach ( [ 'add_option_' . Roles::OPT_HUBS, 'update_option_' . Roles::OPT_HUBS, 'delete_option_' . Roles::OPT_HUBS ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'roles_changed' ] );
		}
		add_action( 'save_post_page', [ __CLASS__, 'page_saved' ], 10, 3 );
	}

	/* ------------------------------------------------------------------ detection + slot ownership */

	/** Is Kadence Conversions active (its post type exists, so after init)? */
	public static function active(): bool {
		return defined( 'KADENCE_CONVERSIONS_VERSION' ) && post_type_exists( self::POST_TYPE );
	}

	/**
	 * Does Conversions own this chrome slot? True for sticky_bar and notice while Kadence Conversions is active (filter
	 * launch_builder_conversions_owns_slot( $owns, $slot ) can say no). Chrome then calls apply_slot() for the slot. It
	 * says nothing about what is on the page: Chrome keeps its own fallback and hides it only while slot_live() is true.
	 */
	public static function owns_slot( string $slot ): bool {
		return isset( self::SLOTS[ $slot ] ) && (bool) apply_filters( 'launch_builder_conversions_owns_slot', self::active(), $slot );
	}

	/**
	 * Is a Conversions banner carrying this chrome slot right now? owns_slot( $slot ) and a published, unexpired Launch
	 * Builder banner of the slot (Ledger key conversion-chrome-<slot>-...) exists; with $role, one that shows on that page
	 * role (its show/hide roles; a banner whose display conditions an admin edited counts for every role). Chrome's
	 * render-time guard hides its own sticky_bar / notice output exactly while this is true (pass Roles::current()).
	 * One query per request (reset by every write of this class).
	 */
	public static function slot_live( string $slot, ?string $role = null ): bool {
		if ( ! self::owns_slot( $slot ) ) {
			return false;
		}
		if ( null === self::$live ) {
			self::$live = [];
			$now = self::now();
			$posts = get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => self::KEY_PREFIX . 'chrome-', 'compare' => 'LIKE' ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $posts as $p ) {
				$s = self::chrome_slot_of( (string) get_post_meta( $p->ID, '_lb_key', true ) );
				$rr = '' === $s ? null : self::role_rules( (int) $p->ID );
				if ( null === $rr ) {
					continue;
				}
				$exp = get_post_meta( $p->ID, '_kad_conversion_enable_expires', true ) ? (string) get_post_meta( $p->ID, '_kad_conversion_expires', true ) : '';
				if ( '' !== $exp && $exp < $now ) {
					continue;
				}
				self::$live[ $s ][] = self::roles_enforced( (int) $p->ID ) ? $rr : [ 'show' => null, 'hide' => [] ];
			}
		}
		foreach ( self::$live[ $slot ] ?? [] as $rr ) {
			if ( null === $role || null === $rr['show'] || ( ( ! $rr['show'] || in_array( $role, $rr['show'], true ) ) && ! in_array( $role, $rr['hide'], true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** The chrome slot of a Ledger key conversion-chrome-<slot>-<8 hex> ('' for other keys). */
	private static function chrome_slot_of( string $lkey ): string {
		return preg_match( '/^' . preg_quote( self::KEY_PREFIX, '/' ) . 'chrome-([a-z_]+)-[0-9a-f]{8}$/', $lkey, $m ) ? $m[1] : '';
	}

	/**
	 * Kadence Conversions was activated or deactivated: fires launch_builder_conversions_ownership_changed( bool $active )
	 * so Chrome can re-apply the sticky_bar and notice slots. In this request the change is not complete yet (its post
	 * type registers at init; a deactivated plugin's code is still loaded), so a listener schedules the re-apply for the
	 * next request (e.g. Migrate::rerun of its chrome step) instead of applying now.
	 */
	public static function plugin_toggled( $plugin ): void {
		if ( self::PLUGIN_FILE !== basename( (string) $plugin ) ) {
			return;
		}
		self::$live = null;
		do_action( 'launch_builder_conversions_ownership_changed', 'activated_plugin' === current_action() );
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$roles = array_keys( Roles::ROLES );
		Abilities::add( 'set-conversion', 'Create or update a popup, slide-in or banner (Kadence Conversions)',
			'Needs Kadence Conversions. Writes one Conversion Item by key (re-running updates it; an item edited or drafted in wp-admin is reported and left alone unless force). type: popup | banner (bar) | slide_in. design: a pattern slug, {pattern, slots, replace, markdown}, {sections:[...]}, {synced: key} or {markup: block markup} (default per type: popup-exit-intent, slide-in-quote, bar-notice; a design that does not exist yet reports compile_failed and writes nothing). trigger: exit_intent (default for popups), delay (seconds in delay), scroll (pixels in scroll), click (any link to "#lbc-<key>" opens it), load (default for banners), content_end. Where: show_roles (page roles; empty = every role) and hide_roles (default conversion, thanks, legal), devices (desktop/tablet/mobile; empty = all), audience, expires/starts (site time, YYYY-MM-DD or YYYY-MM-DDTHH:MM). Goal: a click on a button with goal_class (default conversion-button; "" = any link) is tracked as the conversion in the Conversions analytics; repeat = days the item stays away after a visitor closes it (0 = every page view), repeat_after_goal likewise after the goal. copy {eyebrow, headline, accent, body, cta...} fills [[key]] placeholders in the design. dry_run reports what would change.',
			[ 'properties' => [
				'key'               => [ 'type' => 'string', 'description' => 'Stable id, e.g. "exit-intent" (a-z, 0-9, - and _).' ],
				'type'              => [ 'type' => 'string', 'enum' => [ 'popup', 'banner', 'bar', 'slide_in' ] ],
				'design'            => [ 'type' => [ 'string', 'object' ], 'description' => 'Pattern slug, {pattern, slots, replace, markdown}, {sections:[...]}, {synced: key} or {markup: "<!-- wp:... -->"}.' ],
				'trigger'           => [ 'type' => 'string', 'enum' => array_keys( self::TRIGGERS ) ],
				'delay'             => [ 'type' => 'number', 'description' => 'trigger delay: seconds (default 5).' ],
				'scroll'            => [ 'type' => 'integer', 'description' => 'trigger scroll: pixels scrolled (default 300).' ],
				'show_roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => $roles ], 'description' => 'Only on pages of these roles (empty = every role).' ],
				'hide_roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => $roles ], 'description' => 'Never on these roles (default conversion, thanks, legal).' ],
				'devices'           => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( self::DEVICES ) ] ],
				'audience'          => [ 'type' => 'string', 'enum' => self::AUDIENCES, 'description' => 'all (default), logged_out or logged_in visitors.' ],
				'expires'           => [ 'type' => 'string', 'description' => 'Stop showing after this site-time date (YYYY-MM-DD = end of that day) or date-time; "" = never.' ],
				'starts'            => [ 'type' => 'string', 'description' => 'Start showing at this site-time date or date-time (only with expires).' ],
				'repeat'            => [ 'type' => 'integer', 'description' => 'Days the item stays away after a visitor closes it (cookie); 0 = show on every page view. Default 30 (banners 0).' ],
				'repeat_after_goal' => [ 'type' => 'integer', 'description' => 'Days it stays away after the goal (default 90).' ],
				'goal'              => [ 'type' => 'string', 'enum' => self::GOALS, 'description' => 'button (default): a click on goal_class; form: a form submission; none.' ],
				'goal_class'        => [ 'type' => 'string', 'description' => 'Class of the goal button (default conversion-button; "" = any link).' ],
				'close_on_goal'     => [ 'type' => 'boolean' ],
				'analytics'         => [ 'type' => 'boolean', 'description' => 'Track views and conversions in the Conversions analytics (default true).' ],
				'position'          => [ 'type' => 'string', 'enum' => [ 'top', 'bottom' ], 'description' => 'Banners: top or bottom of the screen (default bottom).' ],
				'offset'            => [ 'type' => 'boolean', 'description' => 'Banners: push the page so the banner covers nothing (default true).' ],
				'close'             => [ 'type' => 'boolean', 'description' => 'Show a close button (default true).' ],
				'width'             => [ 'type' => 'integer', 'description' => 'Max width in px (popup default 700, slide-in 400).' ],
				'copy'              => [ 'type' => 'object', 'description' => 'Copy for the design: key => text, filling [[key]] placeholders.' ],
				'title'             => [ 'type' => 'string', 'description' => 'Admin title (default from type and key).' ],
				'status'            => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
				'force'             => [ 'type' => 'boolean', 'description' => 'Overwrite an item edited, drafted or trashed in wp-admin.' ],
				'dry_run'           => [ 'type' => 'boolean' ],
			], 'required' => [ 'key' ] ], [ __CLASS__, 'set_tool' ], 'edit_theme_options' );

		Abilities::add( 'get-conversions', 'List popups, slide-ins and banners (Kadence Conversions)',
			'Every Conversion Item (Launch Builder\'s and others) with type, trigger, goal, analytics, repeat cookie, devices, schedule, where it shows (page roles, whether they are still enforced, and the native rules), design and ownership state (owned, kept_edits, admin_drafted, pruned, trashed). Also whether Kadence Conversions is active, site analytics, an exit-intent item exists, which chrome slots Conversions owns and which of them a published banner carries now (live_slots). Paginated.',
			[ 'properties' => [
				'key'    => [ 'type' => 'string', 'description' => 'Only this Launch Builder item.' ],
				'offset' => [ 'type' => 'integer' ],
				'limit'  => [ 'type' => 'integer', 'description' => '1-100 (default 50).' ],
			] ], [ __CLASS__, 'get_tool' ], 'edit_theme_options', [ 'readonly' => true ] );

		Abilities::add( 'delete-conversion', 'Switch off a Launch Builder popup or banner',
			'Moves a Launch Builder Conversion Item (by key) to draft. Nothing is deleted; set-conversion with the same key publishes it again. An item edited in wp-admin needs force.',
			[ 'properties' => [
				'key'   => [ 'type' => 'string' ],
				'force' => [ 'type' => 'boolean' ],
			], 'required' => [ 'key' ] ], [ __CLASS__, 'delete_tool' ], 'edit_theme_options' );
	}

	/** set-conversion. @return array|\WP_Error */
	public static function set_tool( $input ) {
		if ( ! self::active() ) {
			return self::inactive();
		}
		$item = self::normalize( (array) $input );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		return self::apply( $item );
	}

	/** get-conversions. @return array|\WP_Error */
	public static function get_tool( $input = [] ) {
		$input = (array) $input;
		$out = [
			'active'            => self::active(),
			'version'           => defined( 'KADENCE_CONVERSIONS_VERSION' ) ? (string) KADENCE_CONVERSIONS_VERSION : null,
			'analytics_enabled' => self::site_analytics(),
			'owns_slots'        => array_values( array_filter( array_keys( self::SLOTS ), [ __CLASS__, 'owns_slot' ] ) ),
			'live_slots'        => array_values( array_filter( array_keys( self::SLOTS ), [ __CLASS__, 'slot_live' ] ) ),
		];
		if ( ! $out['active'] ) {
			return $out + [ 'note' => 'Kadence Conversions is not active: there are no conversion items to list.' ];
		}
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$args = [ 'post_type' => self::POST_TYPE, 'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ], 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ], 'ignore_sticky_posts' => true ];
		if ( isset( $input['key'] ) && '' !== (string) $input['key'] ) {
			$args['meta_key'] = '_lb_key'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = self::KEY_PREFIX . sanitize_key( (string) $input['key'] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$q = new \WP_Query( $args );
		$items = [];
		foreach ( $q->posts as $p ) {
			$items[] = self::describe( $p );
		}
		$total = (int) $q->found_posts;
		return $out + [
			'exit_intent' => self::has_exit_intent(),
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
			'items'       => $items,
		];
	}

	/** delete-conversion: draft the item (a prune). @return array|\WP_Error */
	public static function delete_tool( $input ) {
		if ( ! self::active() ) {
			return self::inactive();
		}
		$input = (array) $input;
		$key = sanitize_key( (string) ( $input['key'] ?? '' ) );
		$post = '' === $key ? null : self::find( self::KEY_PREFIX . $key );
		if ( ! $post ) {
			return new \WP_Error( 'lb_no_conversion', 'No Launch Builder conversion item with key ' . $key . '. Use get-conversions.', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that conversion item.', [ 'status' => 403 ] );
		}
		return self::prune( $post, self::KEY_PREFIX . $key, ! empty( $input['force'] ) );
	}

	private static function inactive(): \WP_Error {
		return new \WP_Error( 'lb_no_conversions', 'Kadence Conversions is not active. Install and activate it, then try again.', [ 'status' => 412 ] );
	}

	/* ------------------------------------------------------------------ input */

	/**
	 * Validate and complete an item (tool input, brief item or chrome slot banner).
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize( array $in ) {
		$err = static function ( string $msg ) {
			return new \WP_Error( 'lb_conversion_args', $msg, [ 'status' => 400 ] );
		};
		// Blueprints and briefs reach this without the MCP schema check: refuse wrong shapes instead of casting them.
		foreach ( self::SCALAR_FIELDS as $f ) {
			if ( isset( $in[ $f ] ) && ! is_scalar( $in[ $f ] ) ) {
				return $err( $f . ' must be a string, number or boolean, not ' . gettype( $in[ $f ] ) . '.' );
			}
		}
		foreach ( [ 'show_roles', 'hide_roles', 'devices', 'copy' ] as $f ) {
			if ( isset( $in[ $f ] ) && ! is_array( $in[ $f ] ) ) {
				return $err( $f . ' must be ' . ( 'copy' === $f ? 'an object' : 'a list' ) . ', not ' . gettype( $in[ $f ] ) . '.' );
			}
		}
		if ( isset( $in['design'] ) ) {
			$bad = self::design_shape_error( $in['design'] );
			if ( '' !== $bad ) {
				return $err( $bad );
			}
		}
		$key = sanitize_key( (string) ( $in['key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 60 ) {
			return $err( 'key is required (a-z, 0-9, - and _; at most 60 characters).' );
		}
		$type = strtolower( (string) ( $in['type'] ?? 'popup' ) );
		$type = self::TYPE_ALIASES[ $type ] ?? $type;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $err( 'type must be popup, banner or slide_in.' );
		}
		$trigger = (string) ( $in['trigger'] ?? ( 'banner' === $type ? 'load' : 'exit_intent' ) );
		if ( ! isset( self::TRIGGERS[ $trigger ] ) ) {
			return $err( 'trigger must be one of: ' . implode( ', ', array_keys( self::TRIGGERS ) ) . '.' );
		}
		$roles = static function ( $list, string $name ) use ( $err ) {
			if ( ! is_array( $list ) ) {
				return $err( $name . ' must be a list of page roles.' );
			}
			$out = [];
			foreach ( $list as $r ) {
				if ( ! is_string( $r ) || ! isset( Roles::ROLES[ $r ] ) ) {
					return $err( $name . ': unknown page role ' . ( is_scalar( $r ) ? (string) $r : gettype( $r ) ) . ' (roles: ' . implode( ', ', array_keys( Roles::ROLES ) ) . ').' );
				}
				$out[] = $r;
			}
			$out = array_values( array_unique( $out ) );
			sort( $out );
			return $out;
		};
		$show = $roles( $in['show_roles'] ?? [], 'show_roles' );
		if ( is_wp_error( $show ) ) {
			return $show;
		}
		$hide = array_key_exists( 'hide_roles', $in ) ? $roles( $in['hide_roles'] ?? [], 'hide_roles' ) : array_values( array_diff( self::DEFAULT_HIDE, $show ) );
		if ( is_wp_error( $hide ) ) {
			return $hide;
		}
		sort( $hide );
		$devices = [];
		foreach ( $in['devices'] ?? [] as $d ) {
			if ( ! is_string( $d ) || ! isset( self::DEVICES[ $d ] ) ) {
				return $err( 'devices: use desktop, tablet and/or mobile.' );
			}
			$devices[ $d ] = true;
		}
		$devices = 3 === count( $devices ) ? [] : array_values( array_intersect( array_keys( self::DEVICES ), array_keys( $devices ) ) );
		$audience = (string) ( $in['audience'] ?? 'all' );
		if ( ! in_array( $audience, self::AUDIENCES, true ) ) {
			return $err( 'audience must be all, logged_out or logged_in.' );
		}
		$expires = self::when( $in['expires'] ?? '', true );
		$starts = self::when( $in['starts'] ?? '', false );
		if ( null === $expires || null === $starts ) {
			return $err( 'expires/starts must be YYYY-MM-DD or YYYY-MM-DDTHH:MM (site time).' );
		}
		$goal = (string) ( $in['goal'] ?? 'button' );
		if ( ! in_array( $goal, self::GOALS, true ) ) {
			return $err( 'goal must be button, form or none.' );
		}
		$goal_class = array_key_exists( 'goal_class', $in ) ? (string) $in['goal_class'] : self::GOAL_CLASS;
		if ( '' !== $goal_class && ! preg_match( '/^-?[A-Za-z_][A-Za-z0-9_-]{0,80}$/', $goal_class ) ) {
			return $err( 'goal_class must be one CSS class name (letters, digits, - and _).' );
		}
		$position = (string) ( $in['position'] ?? 'bottom' );
		if ( ! in_array( $position, [ 'top', 'bottom' ], true ) ) {
			return $err( 'position must be top or bottom.' );
		}
		$int = static function ( $v, int $min, int $max, int $default ) {
			return is_numeric( $v ) ? max( $min, min( $max, (int) round( (float) $v ) ) ) : $default;
		};
		$copy = [];
		foreach ( $in['copy'] ?? [] as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' !== $k && is_scalar( $v ) ) {
				$copy[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		ksort( $copy );
		$design = $in['design'] ?? self::DEFAULT_DESIGNS[ $type ];
		$status = (string) ( $in['status'] ?? 'publish' );
		if ( ! in_array( $status, [ 'publish', 'draft' ], true ) ) {
			return $err( 'status must be publish or draft.' );
		}
		$defaults_width = [ 'popup' => 700, 'slide_in' => 400, 'banner' => 0 ];
		return [
			'key'               => $key,
			'type'              => $type,
			'trigger'           => $trigger,
			'delay'             => 'delay' === $trigger ? $int( $in['delay'] ?? 5, 0, 200, 5 ) : 0,
			'scroll'            => 'scroll' === $trigger ? $int( $in['scroll'] ?? 300, 0, 100000, 300 ) : 0,
			'design'            => $design,
			'copy'              => $copy,
			'show_roles'        => $show,
			'hide_roles'        => $hide,
			'devices'           => $devices,
			'audience'          => $audience,
			'expires'           => $expires,
			'starts'            => '' === $expires ? '' : $starts,
			'repeat'            => $int( $in['repeat'] ?? null, 0, 1000, 'banner' === $type ? 0 : 30 ),
			'repeat_after_goal' => $int( $in['repeat_after_goal'] ?? null, 1, 1000, 90 ),
			'goal'              => $goal,
			'goal_class'        => $goal_class,
			'close_on_goal'     => ! empty( $in['close_on_goal'] ),
			'analytics'         => ! array_key_exists( 'analytics', $in ) || ! empty( $in['analytics'] ),
			'position'          => $position,
			'offset'            => ! array_key_exists( 'offset', $in ) || ! empty( $in['offset'] ),
			'close'             => ! array_key_exists( 'close', $in ) || ! empty( $in['close'] ),
			'width'             => $int( $in['width'] ?? null, 0, 2000, $defaults_width[ $type ] ),
			'title'             => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'status'            => $status,
			'force'             => ! empty( $in['force'] ),
			'dry_run'           => ! empty( $in['dry_run'] ),
			'warnings'          => '' === $expires && '' !== self::when( $in['starts'] ?? '', false ) ? [ 'starts is only used together with expires; ignored.' ] : [],
		];
	}

	/**
	 * Shape check of a design (before anything is cast): a slug, or an object with markup (string), synced (string),
	 * ref (integer), sections (a list of slugs or {pattern...}) or pattern (string) with slots / replace (objects) and
	 * markdown (string). @return string the problem ('' = fine).
	 */
	private static function design_shape_error( $design, bool $in_sections = false ): string {
		if ( is_string( $design ) ) {
			return '';
		}
		if ( ! is_array( $design ) ) {
			return 'design must be a pattern slug or an object, not ' . gettype( $design ) . '.';
		}
		foreach ( [ 'markup', 'synced', 'pattern', 'markdown' ] as $f ) {
			if ( isset( $design[ $f ] ) && ! is_string( $design[ $f ] ) ) {
				return 'design.' . $f . ' must be a string.';
			}
		}
		if ( isset( $design['ref'] ) && ! ( is_int( $design['ref'] ) || ( is_string( $design['ref'] ) && ctype_digit( $design['ref'] ) ) ) ) {
			return 'design.ref must be a block id (integer).';
		}
		foreach ( [ 'slots', 'replace' ] as $f ) {
			if ( isset( $design[ $f ] ) && ! is_array( $design[ $f ] ) ) {
				return 'design.' . $f . ' must be an object.';
			}
		}
		if ( isset( $design['sections'] ) ) {
			if ( $in_sections || ! is_array( $design['sections'] ) ) {
				return 'design.sections must be a list of pattern slugs or {pattern, slots, replace, markdown} objects.';
			}
			foreach ( $design['sections'] as $sec ) {
				$bad = self::design_shape_error( $sec, true );
				if ( '' !== $bad ) {
					return $bad;
				}
			}
		}
		return '';
	}

	/** A site-time date or date-time as 'Y-m-d\TH:i:s' ('' = none, null = invalid). A bare date expires at the end of that day. */
	private static function when( $v, bool $end_of_day ): ?string {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		foreach ( [ 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ] as $f ) {
			$d = \DateTime::createFromFormat( '!' . $f, $v, wp_timezone() );
			if ( $d && $d->format( $f ) === $v ) {
				if ( 'Y-m-d' === $f && $end_of_day ) {
					$d->setTime( 23, 59, 59 );
				}
				return $d->format( 'Y-m-d\TH:i:s' );
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ write */

	/**
	 * Write one normalized item through the Ledger. Statuses: created, updated, unchanged, would_create, would_update
	 * (dry run), compile_failed (design does not build; nothing written), kept_edits, kept_draft, trashed (left alone).
	 *
	 * @return array|\WP_Error
	 */
	public static function apply( array $item ) {
		if ( ! self::active() ) {
			return self::inactive();
		}
		$lkey = self::KEY_PREFIX . $item['key'];
		$warnings = (array) ( $item['warnings'] ?? [] );
		$base = [ 'key' => $item['key'], 'type' => $item['type'], 'trigger' => $item['trigger'], 'design' => self::design_ref( $item['design'] ) ];
		$built = self::compile_design( $item['design'], $item['copy'] );
		if ( is_wp_error( $built ) ) {
			return $base + [ 'status' => 'compile_failed', 'error' => $built->get_error_message(), 'warnings' => $warnings ];
		}
		$warnings = array_merge( $warnings, $built['warnings'] );
		$post = self::find( $lkey );
		$state = $post ? self::ownership( $post, $lkey ) : 'new';
		$skip = [ 'kept_edits' => 'kept_edits', 'admin_drafted' => 'kept_draft', 'trashed' => 'trashed' ];
		if ( isset( $skip[ $state ] ) && ! $item['force'] ) {
			$why = [ 'kept_edits' => 'It was edited in wp-admin since Launch Builder wrote it', 'admin_drafted' => 'It was switched off (drafted) in wp-admin', 'trashed' => 'It is in the trash' ];
			return $base + [ 'status' => $skip[ $state ], 'id' => $post->ID, 'note' => $why[ $state ] . '; pass force to overwrite.', 'edit_url' => get_edit_post_link( $post->ID, 'raw' ), 'warnings' => $warnings ];
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) { // Conversions grants its item capabilities to exactly these users.
			return new \WP_Error( 'lb_forbidden', 'Conversion items need the edit_theme_options capability.', [ 'status' => 403 ] );
		}
		if ( $post && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that conversion item.', [ 'status' => 403 ] );
		}
		if ( ! self::site_analytics() && $item['analytics'] ) {
			$warnings[] = 'Analytics are switched off in Conversions > Settings, so views and conversions of this item are not recorded.';
		}
		if ( '' !== $item['expires'] && $item['expires'] < self::now() ) {
			$warnings[] = 'expires is in the past: the item will not show.';
		}
		if ( $item['dry_run'] ) {
			if ( $post && 'owned' === $state ) { // The same test the write makes, so an unchanged item plans no change.
				$same = self::item_meta( $item, $lkey, self::item_content( $item, (int) $post->ID, $built['blocks'] ) );
				if ( $item['status'] === $post->post_status && self::meta_matches( (int) $post->ID, $same ) ) {
					return $base + [ 'status' => 'unchanged', 'id' => (int) $post->ID, 'state' => $state, 'post_status' => $post->post_status, 'warnings' => $warnings ];
				}
			}
			return $base + [ 'status' => $post ? 'would_update' : 'would_create', 'id' => $post ? $post->ID : 0, 'state' => $state, 'warnings' => $warnings ];
		}
		$creating = ! $post;
		if ( $creating ) {
			$id = wp_insert_post( [ 'post_type' => self::POST_TYPE, 'post_title' => self::title( $item ), 'post_status' => 'draft', 'post_content' => '' ], true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_post_meta( $id, '_lb_key', $lkey );
		} else {
			$id = (int) $post->ID;
		}
		$content = self::item_content( $item, $id, $built['blocks'] );
		$meta = self::item_meta( $item, $lkey, $content );
		$link = 'click' === $item['trigger'] ? '#' . self::ID_PREFIX . $item['key'] : null;
		$base += [ 'id' => $id, 'link' => $link, 'edit_url' => get_edit_post_link( $id, 'raw' ) ];
		if ( ! $creating && 'owned' === $state && $item['status'] === $post->post_status && self::meta_matches( $id, $meta ) ) {
			if ( ! Ledger::get( $lkey ) ) { // Ours but its row was lost: record it again.
				Ledger::record( $lkey, self::POST_TYPE, $id, Ledger::hash_post( $id, self::META_KEYS ), $item['status'] );
			}
			return $base + [ 'status' => 'unchanged', 'post_status' => $post->post_status, 'warnings' => $warnings ];
		}
		if ( 'trashed' === $state ) {
			wp_untrash_post( $id );
		}
		$args = [ 'ID' => $id, 'post_content' => wp_slash( $content ), 'post_status' => $item['status'] ];
		if ( '' !== $item['title'] ) {
			$args['post_title'] = $item['title'];
		}
		$r = wp_update_post( $args, true );
		if ( is_wp_error( $r ) ) {
			if ( $creating ) {
				wp_delete_post( $id, true ); // Our own empty draft from a moment ago.
			}
			return $r;
		}
		self::write_meta( $id, $meta );
		Ledger::record( $lkey, self::POST_TYPE, $id, Ledger::hash_post( $id, self::META_KEYS ), $item['status'] );
		self::$live = null;
		Cache::purge_later();
		return $base + [ 'status' => $creating ? 'created' : 'updated', 'post_status' => $item['status'], 'warnings' => $warnings ];
	}

	/** Several items (brief, blueprint): normalize + apply each; problems become warnings. */
	public static function apply_items( array $items ): array {
		$out = [];
		$warnings = [];
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				$warnings[] = 'conversion ?: an item must be an object, not ' . gettype( $raw ) . '.';
				continue;
			}
			$item = self::normalize( $raw );
			if ( is_wp_error( $item ) ) {
				$warnings[] = 'conversion ' . ( isset( $raw['key'] ) && is_scalar( $raw['key'] ) ? (string) $raw['key'] : '?' ) . ': ' . $item->get_error_message();
				continue;
			}
			$r = self::apply( $item );
			if ( is_wp_error( $r ) ) {
				$warnings[] = 'conversion ' . $item['key'] . ': ' . $r->get_error_message();
				continue;
			}
			if ( 'compile_failed' === $r['status'] ) {
				$warnings[] = 'conversion ' . $item['key'] . ': compile_failed (' . $r['error'] . ')';
			}
			foreach ( (array) ( $r['warnings'] ?? [] ) as $w ) {
				$warnings[] = 'conversion ' . $item['key'] . ': ' . $w;
			}
			unset( $r['warnings'] );
			$out[] = $r;
		}
		return [ 'items' => $out, 'warnings' => $warnings ];
	}

	/**
	 * Draft an LB item (delete-conversion, chrome prune). Edited items only with $force.
	 */
	private static function prune( \WP_Post $post, string $lkey, bool $force = false ): array {
		$key = substr( $lkey, strlen( self::KEY_PREFIX ) );
		$state = self::ownership( $post, $lkey );
		$base = [ 'key' => $key, 'id' => $post->ID ];
		if ( 'trashed' === $state ) {
			return $base + [ 'status' => 'trashed' ];
		}
		if ( 'kept_edits' === $state && ! $force ) {
			return $base + [ 'status' => 'kept_edits', 'note' => 'It was edited in wp-admin since Launch Builder wrote it; pass force to switch it off anyway.' ];
		}
		if ( 'publish' !== $post->post_status && 'future' !== $post->post_status ) {
			return $base + [ 'status' => 'unchanged', 'post_status' => $post->post_status ];
		}
		$r = wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ], true );
		if ( is_wp_error( $r ) ) {
			return $base + [ 'status' => 'error', 'error' => $r->get_error_message() ];
		}
		Ledger::record( $lkey, self::POST_TYPE, $post->ID, Ledger::hash_post( $post->ID, self::META_KEYS ), 'draft' );
		Ledger::mark_pruned( $lkey );
		self::$live = null;
		Cache::purge_later();
		return $base + [ 'status' => 'pruned', 'post_status' => 'draft' ];
	}

	/** The LB item with this Ledger key (any status, trash included). */
	private static function find( string $lkey ): ?\WP_Post {
		$p = get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ], 'meta_key' => '_lb_key', 'meta_value' => $lkey, 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $p ? $p[0] : null;
	}

	/** @return \WP_Post[] every LB item (trash excluded). */
	private static function lb_posts(): array {
		return get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private' ], 'numberposts' => 200, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'meta_query' => [ [ 'key' => '_lb_key', 'value' => self::KEY_PREFIX, 'compare' => 'LIKE' ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Who decides about an LB item now: owned (LB may write), kept_edits (edited since LB wrote it), pruned (LB drafted
	 * it), admin_drafted (LB published it, someone switched it off), trashed.
	 */
	private static function ownership( \WP_Post $post, string $lkey ): string {
		if ( 'trash' === $post->post_status ) {
			return 'trashed';
		}
		$row = Ledger::get( $lkey );
		if ( ! $row ) {
			return 'owned'; // It carries our key but the row is gone (e.g. restored database): ours, and re-recorded on write.
		}
		if ( Ledger::is_edited( $lkey, Ledger::hash_post( $post->ID, self::META_KEYS ) ) ) {
			return 'kept_edits';
		}
		if ( ! empty( $row['pruned_by_lb'] ) ) {
			return 'publish' === $post->post_status ? 'owned' : 'pruned';
		}
		if ( 'publish' === ( $row['status'] ?? '' ) && 'publish' !== $post->post_status ) {
			return 'admin_drafted';
		}
		return 'owned';
	}

	private static function title( array $item ): string {
		if ( '' !== $item['title'] ) {
			return $item['title'];
		}
		$labels = [ 'popup' => __( 'Popup', 'launch-builder' ), 'banner' => __( 'Banner', 'launch-builder' ), 'slide_in' => __( 'Slide-in', 'launch-builder' ) ];
		return $labels[ $item['type'] ] . ': ' . ucfirst( str_replace( [ '-', '_' ], ' ', $item['key'] ) );
	}

	/* ------------------------------------------------------------------ design -> block markup */

	/** What the design was, for storage and reports (raw markup is kept as a fingerprint, not copied). */
	private static function design_ref( $design ) {
		if ( is_array( $design ) && isset( $design['markup'] ) ) {
			$m = (string) $design['markup'];
			return [ 'markup' => 'md5:' . md5( $m ), 'bytes' => strlen( $m ) ];
		}
		return $design;
	}

	/**
	 * Build the design into parsed blocks (inner blocks of the Conversion Item). Pattern designs go through
	 * Patterns::build_pattern (slots, replace, markdown, forms, live lists); copy fills [[key]] placeholders.
	 *
	 * @return array|\WP_Error { blocks, warnings }
	 */
	private static function compile_design( $design, array $copy ) {
		$vars = [];
		foreach ( $copy as $k => $v ) {
			$vars[ '[[' . $k . ']]' ] = $v;
		}
		if ( is_string( $design ) ) {
			$design = [ 'pattern' => $design ];
		}
		$design = (array) $design;
		$warnings = [];
		if ( isset( $design['markup'] ) ) {
			$markup = (string) $design['markup'];
			$ok = self::safe_markup( $markup );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$content = $vars ? strtr( $markup, array_map( 'esc_html', $vars ) ) : $markup;
		} elseif ( isset( $design['synced'] ) || isset( $design['ref'] ) ) {
			$ref = isset( $design['ref'] ) ? (int) $design['ref'] : Blueprint::find_synced( sanitize_key( (string) $design['synced'] ) );
			$p = $ref ? get_post( $ref ) : null;
			if ( ! $p || 'wp_block' !== $p->post_type || 'trash' === $p->post_status || ! current_user_can( 'read_post', $p->ID ) ) {
				return new \WP_Error( 'lb_no_synced', 'Unknown synced pattern: ' . ( $design['synced'] ?? $design['ref'] ) . '.', [ 'status' => 404 ] );
			}
			$content = '<!-- wp:block {"ref":' . (int) $p->ID . '} /-->';
		} else {
			$sections = isset( $design['sections'] ) ? (array) $design['sections'] : [ $design ];
			if ( ! $sections ) {
				return new \WP_Error( 'lb_no_design', 'The design has no sections.', [ 'status' => 400 ] );
			}
			$parts = [];
			foreach ( $sections as $sec ) {
				$sec = is_string( $sec ) ? [ 'pattern' => $sec ] : (array) $sec;
				$slug = sanitize_key( (string) ( $sec['pattern'] ?? '' ) );
				if ( '' === $slug ) {
					return new \WP_Error( 'lb_no_design', 'design needs a pattern slug, sections, synced or markup.', [ 'status' => 400 ] );
				}
				$one = Patterns::build_pattern( $slug, (array) ( $sec['slots'] ?? [] ), (array) ( $sec['replace'] ?? [] ), $vars, (string) ( $sec['markdown'] ?? '' ), [ 'no_hero' => false ] );
				if ( is_wp_error( $one ) ) {
					return $one;
				}
				$parts[] = $one['content'];
				foreach ( (array) $one['warnings'] as $w ) {
					$warnings[] = $slug . ': ' . $w;
				}
			}
			$content = implode( "\n\n", $parts );
		}
		$blocks = [];
		foreach ( parse_blocks( $content ) as $b ) {
			if ( empty( $b['blockName'] ) ) {
				if ( '' !== trim( (string) $b['innerHTML'] ) ) {
					return new \WP_Error( 'lb_freeform', 'The design has HTML outside blocks; wrap it in blocks.', [ 'status' => 400 ] );
				}
				continue;
			}
			$blocks[] = $b;
		}
		if ( ! $blocks ) {
			return new \WP_Error( 'lb_no_design', 'The design is empty.', [ 'status' => 400 ] );
		}
		if ( false !== strpos( $content, 'wp:' . self::BLOCK ) ) {
			return new \WP_Error( 'lb_nested', 'A design cannot contain another Conversion Item.', [ 'status' => 400 ] );
		}
		return [ 'blocks' => $blocks, 'warnings' => $warnings ];
	}

	/**
	 * Raw block markup: the same rules as save-pattern (size, no scripts/iframes/forms/event handlers, registered blocks
	 * only). WordPress's own kses still applies on save for users without unfiltered_html.
	 *
	 * @return true|\WP_Error
	 */
	private static function safe_markup( string $content ) {
		if ( strlen( $content ) > 300000 ) {
			return new \WP_Error( 'lb_too_big', 'Markup is over 300 KB.', [ 'status' => 413 ] );
		}
		$probe = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/<\s*(script|iframe|object|embed|style|link|meta|base|form)\b|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|<[^>]*[\s\/"\']on[a-z]{3,}\s*=/i', $probe ) ) {
			return new \WP_Error( 'lb_unsafe', 'Markup contains script, iframe/embed, form tags or inline event handlers.', [ 'status' => 400 ] );
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		$bad = [];
		$walk = static function ( array $blocks ) use ( &$walk, &$bad, $registry ) {
			foreach ( $blocks as $b ) {
				if ( ! empty( $b['blockName'] ) && ! $registry->is_registered( $b['blockName'] ) ) {
					$bad[ $b['blockName'] ] = true;
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return $bad ? new \WP_Error( 'lb_unknown_blocks', 'Unregistered blocks: ' . implode( ', ', array_keys( $bad ) ) . '.', [ 'status' => 400 ] ) : true;
	}

	/**
	 * Kadence uniqueIDs of the design re-keyed to {post_id}_{h6(seed|path)}: stable across re-runs (so an
	 * unchanged item stays unchanged), unique per item (two items never share per-block CSS), and kept by the editor
	 * (its prefix is the post being edited). Per block: the attribute and the block's own markup.
	 */
	private static function rekey( array $blocks, int $post_id, string $seed, string $path = '' ): array {
		foreach ( $blocks as $i => $b ) {
			$p = '' === $path ? (string) $i : $path . '.' . $i;
			$old = $b['attrs']['uniqueID'] ?? null;
			if ( 0 === strpos( (string) ( $b['blockName'] ?? '' ), 'kadence/' ) && is_string( $old ) && '' !== $old ) {
				$new = $post_id . '_' . substr( md5( $seed . '|' . $p ), 0, 6 );
				$b['attrs']['uniqueID'] = $new;
				foreach ( (array) $b['innerContent'] as $k => $chunk ) {
					if ( is_string( $chunk ) ) {
						$b['innerContent'][ $k ] = str_replace( $old, $new, $chunk );
					}
				}
				$b['innerHTML'] = str_replace( $old, $new, (string) ( $b['innerHTML'] ?? '' ) );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$b['innerBlocks'] = self::rekey( $b['innerBlocks'], $post_id, $seed, $p );
			}
			$blocks[ $i ] = $b;
		}
		return $blocks;
	}

	/** Block attributes of the Conversion Item. Only what differs from the block's defaults, plus what PHP reads with other defaults. */
	private static function block_attrs( array $item, int $post_id ): array {
		$a = [
			'uniqueID'           => self::ID_PREFIX . $item['key'],
			'campaignID'         => self::ID_PREFIX . $item['key'],
			'conversionType'     => $item['type'],
			'templateSelected'   => 'none', // Without it the editor opens on its template picker instead of the item.
			'conversionTrigger'  => self::TRIGGERS[ $item['trigger'] ],
			'conversionGoal'     => $item['goal'],
			'conversionTracking' => (bool) $item['analytics'],
			'postID'             => $post_id,
		];
		if ( 'button' === $item['goal'] && '' !== $item['goal_class'] ) {
			$a['goalClass'] = $item['goal_class'];
		}
		if ( $item['close_on_goal'] ) {
			$a['goalClose'] = true;
		}
		if ( 'delay' === $item['trigger'] ) {
			$a['delay'] = (int) $item['delay'] * 1000;
		} elseif ( 'scroll' === $item['trigger'] ) {
			$a['scroll'] = (int) $item['scroll'];
		}
		if ( $item['repeat'] > 0 ) {
			if ( 30 !== $item['repeat'] ) {
				$a['closeRepeat'] = (int) $item['repeat'];
			}
			if ( 90 !== $item['repeat_after_goal'] ) {
				$a['convertRepeat'] = (int) $item['repeat_after_goal'];
			}
		} else {
			$a['repeatControl'] = false;
		}
		if ( 'slide_in' === $item['type'] ) {
			$a['horizontalAlign'] = 'right';
			$a['verticalAlign'] = 'bottom';
			$a['shadow'] = [ [ 'enable' => true, 'color' => 'rgba(0,0,0,0.2)', 'spread' => 0, 'blur' => 12, 'hOffset' => -2, 'vOffset' => -6, 'inset' => false ] ];
		} elseif ( 'banner' === $item['type'] ) {
			$a['verticalAlign'] = $item['position']; // PHP reads a missing one as 'top', the editor as 'center': always written.
			$a['shadow'] = [ [ 'enable' => false, 'color' => 'rgba(0,0,0,0.2)', 'spread' => 0, 'blur' => 14, 'hOffset' => 0, 'vOffset' => 0, 'inset' => false ] ];
			if ( ! $item['offset'] ) {
				$a['offset'] = false;
			}
		}
		if ( $item['width'] > 0 ) {
			$a['maxWidth'] = [ (int) $item['width'], '', '' ];
		}
		$a['padding'] = [ 0, 0, 0, 0 ]; // The design brings its own spacing.
		if ( ! $item['close'] ) {
			$a['displayClose'] = false;
		} elseif ( ! current_user_can( 'unfiltered_html' ) ) {
			$a['closeContent'] = 'text'; // kses would strip the SVG icon for this user and break the block.
		}
		$a['closeLabel'] = __( 'Close', 'launch-builder' );
		return $a;
	}

	/** The whole post content: the Conversion Item block (exactly what its save() makes) around the re-keyed design. */
	private static function item_content( array $item, int $post_id, array $blocks ): string {
		$a = self::block_attrs( $item, $post_id );
		$inner = self::rekey( $blocks, $post_id, self::KEY_PREFIX . $item['key'] );
		$v = static function ( string $k ) use ( $a ) {
			return array_key_exists( $k, $a ) ? $a[ $k ] : ( self::BLOCK_DEFAULTS[ $k ] ?? null );
		};
		$type = $a['conversionType'];
		$cls = [ 'wp-block-kadence-conversions-conversion', 'kadence-conversion-wrap', 'kadence-conversion-' . $post_id, 'kadence-conversion-' . $type, 'kc-align-' . $v( 'horizontalAlign' ), 'kc-valign-' . $v( 'verticalAlign' ), 'kc-' . $a['uniqueID'], 'kc-height-' . $v( 'height' ) ];
		if ( 'banner' !== $type ) {
			$cls[] = 'kc-animate-in-' . $v( 'animateIn' );
			$cls[] = 'kc-animate-out-' . $v( 'animateOut' );
		}
		$cls[] = 'kc-close-align-' . $v( 'closeAlign' );
		$cls[] = 'kc-close-v-align-' . $v( 'closeVAlign' );
		$cls[] = 'kc-close-position-' . $v( 'closePosition' );
		if ( 'auto' !== $v( 'height' ) ) {
			$cls[] = 'kc-inner-v-align-' . $v( 'innerVAlign' );
		}
		$button = '';
		if ( $v( 'displayClose' ) ) {
			$icon = 'icon' === $v( 'closeContent' );
			$button = '<button class="kadence-conversions-close"' . ( $icon ? ' aria-label="' . esc_attr( (string) $v( 'closeLabel' ) ) . '"' : '' ) . '>' . ( $icon ? self::CLOSE_ICON : esc_html( (string) $v( 'closeLabel' ) ) ) . '</button>';
		}
		$screen = 'screen' === $v( 'closePosition' );
		$open = '<div id="kadence-conversion-' . $post_id . '" class="' . esc_attr( implode( ' ', $cls ) ) . '">' . ( 'popup' === $type ? '<div class="kadence-conversion-overlay"></div>' : '' ) . '<div class="kadence-conversion"><div class="kadence-conversion-inner">';
		$close = '</div>' . ( $screen ? '' : $button ) . '</div>' . ( $screen ? $button : '' ) . '</div>';
		$chunks = [ $open ];
		foreach ( array_keys( $inner ) as $i ) {
			if ( $i > 0 ) {
				$chunks[] = "\n\n";
			}
			$chunks[] = null;
		}
		$chunks[] = $close;
		return serialize_block( [ 'blockName' => self::BLOCK, 'attrs' => $a, 'innerBlocks' => array_values( $inner ), 'innerHTML' => $open . $close, 'innerContent' => $chunks ] );
	}

	/** Every meta value the item should have (null = absent). */
	private static function item_meta( array $item, string $lkey, string $content ): array {
		$json = static function ( $v ): string {
			return (string) wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		};
		$input = $item;
		unset( $input['force'], $input['dry_run'], $input['warnings'], $input['status'], $input['title'] );
		$input['design'] = self::design_ref( $input['design'] );
		$devices = [];
		foreach ( $item['devices'] as $d ) {
			$devices[] = [ 'value' => $d, 'label' => self::DEVICES[ $d ] ];
		}
		return self::rules_meta( $item['show_roles'], $item['hide_roles'] ) + [
			'_kad_conversion_type'             => $item['type'],
			'_kad_conversion_trigger'          => self::TRIGGERS[ $item['trigger'] ],
			'_kad_conversion_all_show'         => null,
			'_kad_conversion_user'             => 'all' === $item['audience'] ? null : $json( [ [ 'role' => $item['audience'] ] ] ),
			'_kad_conversion_user_hidden'      => null,
			'_kad_conversion_device'           => $devices ? $json( $devices ) : null,
			'_kad_conversion_enable_expires'   => '' !== $item['expires'] ? '1' : null,
			'_kad_conversion_expires'          => '' !== $item['expires'] ? $item['expires'] : null,
			'_kad_conversion_starts'           => '' !== $item['starts'] ? $item['starts'] : null,
			'_kad_conversion_enable_recurring' => null,
			'_kad_conversion_language'         => null,
			'_lb_key'                          => $lkey,
			'_lb_show_roles'                   => $json( $item['show_roles'] ),
			'_lb_hide_roles'                   => $json( $item['hide_roles'] ),
			'_lb_input'                        => $json( $input ),
			'_lb_src'                          => md5( $json( $input ) . "\n" . $content ),
		];
	}

	/** The native show/hide meta for page roles, plus _lb_rules: the signature of exactly those native rules. */
	private static function rules_meta( array $show_roles, array $hide_roles ): array {
		$show = $show_roles ? self::native_rules( $show_roles, 'show' ) : [ self::rule( 'general|site' ) ];
		$hide = self::native_rules( $hide_roles, 'hide' );
		$meta = [
			'_kad_conversion_show' => (string) wp_json_encode( $show, JSON_UNESCAPED_SLASHES ),
			'_kad_conversion_hide' => $hide ? (string) wp_json_encode( $hide, JSON_UNESCAPED_SLASHES ) : null,
		];
		$meta['_lb_rules'] = self::rules_sig( $meta['_kad_conversion_show'], $meta['_kad_conversion_hide'] );
		return $meta;
	}

	/** Signature of native show/hide meta values (decoded, key order and JSON spelling do not matter; absent = []). */
	private static function rules_sig( $show, $hide ): string {
		$dec = static function ( $v ): array {
			$d = json_decode( (string) $v, true );
			return is_array( $d ) ? $d : [];
		};
		return Ledger::hash_value( [ 'show' => $dec( $show ), 'hide' => $dec( $hide ) ] );
	}

	/**
	 * Do Launch Builder's page roles still decide where this item shows? True while its native show/hide rules are the
	 * ones Launch Builder wrote (_lb_rules); false once an admin changed them in the Conversions UI (then those decide).
	 * Reads from the primed meta cache.
	 */
	public static function roles_enforced( int $id ): bool {
		$sig = (string) get_post_meta( $id, '_lb_rules', true );
		return '' !== $sig && hash_equals( $sig, self::rules_sig( get_post_meta( $id, '_kad_conversion_show', true ), get_post_meta( $id, '_kad_conversion_hide', true ) ) );
	}

	private static function write_meta( int $id, array $meta ): void {
		foreach ( $meta as $k => $v ) {
			if ( null === $v ) {
				delete_post_meta( $id, $k );
			} else {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
		}
	}

	private static function meta_matches( int $id, array $meta ): bool {
		foreach ( $meta as $k => $v ) {
			$cur = get_post_meta( $id, $k, false );
			if ( null === $v ? [] !== $cur : [ (string) $v ] !== array_map( 'strval', $cur ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ page roles -> native rules */

	/** One native rule in the shape the Conversions editor writes. */
	private static function rule( string $rule, string $select = 'all', array $ids = [] ): array {
		return [ 'rule' => $rule, 'select' => $select, 'ids' => array_values( array_map( 'intval', $ids ) ), 'subRule' => '', 'subSelection' => [], 'mustMatch' => '' ];
	}

	/**
	 * Native Conversions rules for page roles. 'show' rules may be broader than the roles (the display filter narrows them);
	 * 'hide' rules are never broader. A show list that would be empty matches nothing (an empty list means the whole site).
	 */
	private static function native_rules( array $roles, string $side ): array {
		$rules = [];
		$ids = [];
		$static_front = 'page' === get_option( 'show_on_front' );
		foreach ( $roles as $role ) {
			switch ( $role ) {
				case 'home':
					$rules[] = self::rule( 'general|front_page' );
					break;
				case 'service':
					$rules[] = self::rule( 'singular|' . Entries::SERVICE );
					break;
				case 'area':
					$rules[] = self::rule( 'singular|' . Entries::AREA );
					break;
				case 'post':
					$rules[] = self::rule( 'singular|post' );
					break;
				case 'search':
					$rules[] = self::rule( 'general|search' );
					break;
				case 'notfound':
					$rules[] = self::rule( 'general|404' );
					break;
				case 'blog':
					if ( $static_front ) {
						$rules[] = self::rule( 'general|home' ); // Without a static front page the posts index IS the front page (role home).
					}
					foreach ( [ 'tax_archive|category', 'tax_archive|post_tag', 'general|date', 'general|author' ] as $r ) {
						$rules[] = self::rule( $r );
					}
					break;
				case 'category':
					$rules[] = self::rule( 'tax_archive|' . Entries::CAT );
					$ids = array_merge( $ids, self::role_page_ids( 'category' ) );
					break;
				case 'page':
					if ( 'show' === $side ) {
						$rules[] = self::rule( 'singular|page' );
					} else {
						$ids = array_merge( $ids, self::role_page_ids( 'page' ) );
					}
					break;
				case 'other':
					if ( 'show' === $side ) {
						$rules[] = self::rule( 'general|site' );
					}
					break;
				default: // legal, conversion, thanks, services_hub, areas_hub: pages by id.
					$ids = array_merge( $ids, self::role_page_ids( $role ) );
			}
		}
		if ( $ids || ( 'show' === $side && $roles && ! $rules ) ) {
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			sort( $ids );
			$rules[] = self::rule( 'singular|page', 'ids', $ids );
		}
		$out = [];
		foreach ( $rules as $r ) {
			$out[ $r['rule'] . '|' . $r['select'] ] = $r;
		}
		return array_values( $out );
	}

	/** Pages that have a role now (ids, ascending). */
	private static function role_page_ids( string $role ): array {
		switch ( $role ) {
			case 'services_hub':
				$cand = [ Roles::hubs()['services'] ];
				break;
			case 'areas_hub':
				$cand = [ Roles::hubs()['areas'] ];
				break;
			case 'category':
				$cand = array_keys( Roles::catmap() );
				break;
			case 'legal':
			case 'conversion':
			case 'thanks':
				$cand = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids', 'meta_key' => Roles::META, 'meta_value' => $role, 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'page':
				$cand = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1000, 'fields' => 'ids', 'suppress_filters' => true ] );
				break;
			default:
				return [];
		}
		$out = [];
		foreach ( array_filter( array_map( 'intval', (array) $cand ) ) as $id ) {
			if ( Roles::of( $id ) === $role ) {
				$out[] = $id;
			}
		}
		sort( $out );
		return $out;
	}

	/** _lb_show_roles / _lb_hide_roles of an item (null when it is not a Launch Builder item). */
	private static function role_rules( int $id ): ?array {
		if ( 0 !== strpos( (string) get_post_meta( $id, '_lb_key', true ), self::KEY_PREFIX ) ) {
			return null;
		}
		$get = static function ( string $k ) use ( $id ): array {
			$v = json_decode( (string) get_post_meta( $id, $k, true ), true );
			return is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : [];
		};
		return [ 'show' => $get( '_lb_show_roles' ), 'hide' => $get( '_lb_hide_roles' ) ];
	}

	/* ------------------------------------------------------------------ front end */

	/**
	 * kadence_conversion_display (priority 20): Launch Builder items show only on their page roles. Exact (the native
	 * rules are the legible approximation); never turns a native "no" into "yes". Only while the native rules are still
	 * the ones Launch Builder wrote (roles_enforced()): after an admin edits them in the Conversions UI, they decide alone.
	 *
	 * @param bool          $show native result.
	 * @param \WP_Post|null $post the item.
	 * @param mixed         $meta Conversions' meta array (unused).
	 */
	public static function display( $show, $post = null, $meta = null ) {
		unset( $meta );
		if ( ! $show || ! $post instanceof \WP_Post || wp_doing_ajax() || ! did_action( 'wp' ) ) {
			return $show;
		}
		$rules = self::role_rules( (int) $post->ID );
		if ( null === $rules ) {
			return $show;
		}
		$slot = self::chrome_slot_of( (string) get_post_meta( $post->ID, '_lb_key', true ) );
		if ( '' !== $slot && ! self::owns_slot( $slot ) ) {
			return false; // A chrome banner whose slot Conversions no longer owns: Chrome's own fallback shows instead.
		}
		if ( ! self::roles_enforced( (int) $post->ID ) ) {
			return $show; // An admin changed its display conditions: those decide.
		}
		$role = Roles::current();
		if ( $rules['show'] && ! in_array( $role, $rules['show'], true ) ) {
			return false;
		}
		return ! in_array( $role, $rules['hide'], true );
	}

	/** Conversions' Duplicate copies all meta: not ours (the copy is a plain item; its native rules still work). */
	public static function duplicate_exclude( $list ): array {
		return array_values( array_unique( array_merge( (array) $list, self::LB_META ) ) );
	}

	/* ------------------------------------------------------------------ keeping native rules current */

	public static function post_meta_changed( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids, $object_id );
		if ( Roles::META === $meta_key ) {
			self::resync();
		}
	}

	public static function term_meta_changed( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids, $object_id );
		if ( '_lb_page' === $meta_key ) {
			self::resync();
		}
	}

	public static function roles_changed(): void {
		self::resync();
	}

	/** A new page can only matter to items that list the 'page' role (its ids are spelled out in hide rules). */
	public static function page_saved( $post_id, $post, $update ): void {
		unset( $post_id, $post );
		if ( ! $update ) {
			self::resync( 'page' );
		}
	}

	/**
	 * Rewrite the native show/hide rules of unedited Launch Builder items from the current page roles (their page-id
	 * lists go stale when a page gets or loses a role). $only_role: only items that use that role.
	 *
	 * @return int[] items rewritten.
	 */
	public static function resync( string $only_role = '' ): array {
		if ( self::$resyncing || ! self::active() ) {
			return [];
		}
		self::$resyncing = true;
		$changed = [];
		try {
			foreach ( self::lb_posts() as $p ) {
				$lkey = (string) get_post_meta( $p->ID, '_lb_key', true );
				$rr = self::role_rules( (int) $p->ID );
				if ( null === $rr || ( '' !== $only_role && ! in_array( $only_role, array_merge( $rr['show'], $rr['hide'] ), true ) ) ) {
					continue;
				}
				$row = Ledger::get( $lkey );
				if ( 'kept_edits' === self::ownership( $p, $lkey ) ) {
					continue;
				}
				$meta = self::rules_meta( $rr['show'], $rr['hide'] );
				if ( self::meta_matches( (int) $p->ID, $meta ) ) {
					continue;
				}
				self::write_meta( (int) $p->ID, $meta );
				Ledger::record( $lkey, self::POST_TYPE, $p->ID, Ledger::hash_post( (int) $p->ID, self::META_KEYS ), (string) ( $row['status'] ?? $p->post_status ) );
				if ( $row && ! empty( $row['pruned_by_lb'] ) ) {
					Ledger::mark_pruned( $lkey );
				}
				$changed[] = (int) $p->ID;
			}
		} finally {
			self::$resyncing = false;
		}
		if ( $changed ) {
			self::$live = null;
			Cache::purge_later();
		}
		return $changed;
	}

	/* ------------------------------------------------------------------ chrome slots (contract with Chrome) */

	/**
	 * Place a chrome slot that Conversions owns (owns_slot()) as banners. $value is the slot's map value: a role map
	 * { default: DESIGN, <role>: DESIGN|false } (sticky_bar) or one DESIGN|false (notice). DESIGN = pattern slug,
	 * {pattern, slots}, {sections}, {synced: key}, {ref: wp_block id} or {markup}; false/"theme" = nothing.
	 * One banner per distinct design, key chrome-<slot>-<md5(design)[0..8]>: the default design shows on every role
	 * except the roles mapped to something else; another design shows on its roles only. Banners of this slot that left
	 * the map are drafted (unless edited), except when a design failed to compile (nothing is pruned then).
	 * {adopt: element_id} is Chrome's (an adopted Element): no banner for those roles; when the map has no design besides
	 * adopt/false/theme, the result says owned false (the adopted element carries the slot) after the prune.
	 * Whatever happens here, Chrome's guard asks slot_live() per page, so a role without a live banner keeps Chrome's own.
	 *
	 * @param array $args { expires: site-time date (notice), force: string[] keys to overwrite despite edits }
	 * @return array { slot, owned, items: [ apply()/prune() results ] }
	 */
	public static function apply_slot( string $slot, $value, array $args = [] ): array {
		if ( ! self::owns_slot( $slot ) ) {
			return [ 'slot' => $slot, 'owned' => false, 'items' => [] ];
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return [ 'slot' => $slot, 'owned' => true, 'items' => [], 'error' => 'Conversion items need the edit_theme_options capability.' ];
		}
		$cfg = self::SLOTS[ $slot ];
		$items = [];
		$keep = [];
		$failed = false;
		$groups = self::slot_groups( $value );
		foreach ( $groups as $g ) {
			$key = 'chrome-' . $slot . '-' . substr( Ledger::hash_value( $g['design'] ), 0, 8 );
			$keep[] = self::KEY_PREFIX . $key;
			$item = self::normalize( [
				'key'        => $key,
				'type'       => 'banner',
				'trigger'    => 'load',
				'design'     => $g['design'],
				'position'   => $cfg['position'],
				'devices'    => $cfg['devices'],
				'close'      => $cfg['close'],
				'repeat'     => $cfg['repeat'],
				'show_roles' => $g['show'],
				'hide_roles' => $g['hide'],
				'expires'    => 'notice' === $slot && isset( $args['expires'] ) && is_scalar( $args['expires'] ) ? (string) $args['expires'] : '',
				'title'      => $cfg['title'],
				'force'      => in_array( $key, (array) ( $args['force'] ?? [] ), true ),
			] );
			$r = is_wp_error( $item ) ? [ 'key' => $key, 'status' => 'error', 'error' => $item->get_error_message() ] : self::apply( $item );
			if ( is_wp_error( $r ) ) {
				$r = [ 'key' => $key, 'status' => 'error', 'error' => $r->get_error_message() ];
			}
			$failed = $failed || in_array( $r['status'], [ 'compile_failed', 'error' ], true );
			$items[] = $r;
		}
		if ( ! $failed ) {
			foreach ( self::lb_posts() as $p ) {
				$lkey = (string) get_post_meta( $p->ID, '_lb_key', true );
				if ( in_array( $p->post_status, [ 'publish', 'future' ], true ) && 0 === strpos( $lkey, self::KEY_PREFIX . 'chrome-' . $slot . '-' ) && ! in_array( $lkey, $keep, true ) ) {
					$items[] = self::prune( $p, $lkey );
				}
			}
		}
		if ( ! $groups && self::slot_adopts( $value ) ) {
			return [ 'slot' => $slot, 'owned' => false, 'items' => $items, 'note' => 'The slot adopts an existing element: Chrome places it; no Conversions banner.' ];
		}
		return [ 'slot' => $slot, 'owned' => true, 'items' => $items ];
	}

	/** Does a slot value use {adopt: element_id} (as the design, or for any role)? */
	private static function slot_adopts( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( isset( $value['adopt'] ) ) {
			return true;
		}
		foreach ( $value as $d ) {
			if ( is_array( $d ) && isset( $d['adopt'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Designs of a slot value grouped by design: [ { design, show: roles, hide: roles } ]. */
	private static function slot_groups( $value ): array {
		$off = static function ( $d ): bool {
			return false === $d || null === $d || 'theme' === $d || '' === $d || ( is_array( $d ) && isset( $d['adopt'] ) );
		};
		$is_design = is_string( $value ) || ( is_array( $value ) && array_intersect_key( $value, [ 'pattern' => 1, 'sections' => 1, 'synced' => 1, 'ref' => 1, 'markup' => 1, 'adopt' => 1 ] ) );
		$map = $is_design ? [ 'default' => $value ] : ( is_array( $value ) ? $value : [ 'default' => false ] );
		$default = $map['default'] ?? false;
		$explicit = [];
		foreach ( $map as $role => $d ) {
			if ( 'default' !== $role && isset( Roles::ROLES[ $role ] ) ) {
				$explicit[ $role ] = $d;
			}
		}
		$groups = [];
		$canon = static function ( $d ): string {
			return Ledger::hash_value( $d );
		};
		if ( ! $off( $default ) ) {
			$hide = [];
			foreach ( $explicit as $role => $d ) {
				if ( $off( $d ) || $canon( $d ) !== $canon( $default ) ) {
					$hide[] = $role;
				}
			}
			$groups[ $canon( $default ) ] = [ 'design' => $default, 'show' => [], 'hide' => $hide ];
		}
		foreach ( $explicit as $role => $d ) {
			$c = $off( $d ) ? '' : $canon( $d );
			if ( '' === $c || ( ! $off( $default ) && $c === $canon( $default ) ) ) {
				continue; // Nothing for this role, or the default group already covers it.
			}
			if ( ! isset( $groups[ $c ] ) ) {
				$groups[ $c ] = [ 'design' => $d, 'show' => [], 'hide' => [] ];
			}
			$groups[ $c ]['show'][] = $role;
		}
		return array_values( $groups );
	}

	/* ------------------------------------------------------------------ brief + blueprint */

	/** Module hook: the brief's conversions section. */
	public static function brief_schema( array &$schema ): void {
		$item = [
			'type'       => 'object',
			'properties' => [
				'key'        => [ 'type' => 'string' ],
				'type'       => [ 'type' => 'string', 'enum' => [ 'popup', 'banner', 'slide_in' ] ],
				'trigger'    => [ 'type' => 'string', 'enum' => array_keys( self::TRIGGERS ) ],
				'design'     => [ 'type' => [ 'string', 'object' ], 'description' => 'Pattern slug or {pattern, slots, replace} / {sections}.' ],
				'copy'       => [ 'type' => 'object', 'description' => 'eyebrow, headline, accent (the italic word), body, cta, cta2: fill [[key]] in the design.' ],
				'delay'      => [ 'type' => 'number' ],
				'scroll'     => [ 'type' => 'integer' ],
				'show_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'hide_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'devices'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'expires'    => [ 'type' => 'string' ],
				'repeat'     => [ 'type' => 'integer' ],
				'goal_class' => [ 'type' => 'string' ],
			],
			'required'   => [ 'key' ],
		];
		$schema['properties']['conversions'] = [
			'type'        => [ 'array', 'object' ],
			'description' => 'Kadence Conversions popups, slide-ins and banners (set-conversion items). Every brief ships the default exit-intent popup (hidden on conversion, thank-you and legal pages; goal = a click on .conversion-button, analytics on); give a list of items, or { exit_intent: false | {overrides}, items: [...] }. Needs Kadence Conversions (skipped with a warning without it).',
			'items'       => $item,
			'properties'  => [
				'exit_intent' => [ 'type' => [ 'boolean', 'object' ], 'description' => 'false = no default exit-intent popup; an object overrides its settings.' ],
				'items'       => [ 'type' => 'array', 'items' => $item ],
			],
			'default'     => [ self::DEFAULT_EXIT_INTENT ],
		];
	}

	/**
	 * The items a brief's conversions value asks for: null (absent) = the default exit-intent popup; a list = those items
	 * plus the default unless one of them is an exit-intent item; { exit_intent: false|true|{overrides}, items } likewise.
	 *
	 * @param mixed $value brief.conversions.
	 * @return array[] raw items for apply_items().
	 */
	public static function brief_items( $value ): array {
		if ( false === $value ) {
			return [];
		}
		$items = [];
		$exit = true;
		if ( is_array( $value ) && ( array_key_exists( 'exit_intent', $value ) || array_key_exists( 'items', $value ) ) ) {
			$exit = $value['exit_intent'] ?? true;
			$exit = is_array( $exit ) ? $exit : wp_validate_boolean( $exit );
			$items = is_array( $value['items'] ?? null ) ? $value['items'] : [];
		} elseif ( is_array( $value ) ) {
			$items = $value;
		}
		// Nothing here is validated yet (normalize() does that per item): read only strings, never use a value as an array key.
		$items = array_values( array_filter( $items, 'is_array' ) );
		foreach ( $items as $it ) {
			if ( ( isset( $it['type'] ) && ! is_string( $it['type'] ) ) || ( isset( $it['trigger'] ) && ! is_string( $it['trigger'] ) ) ) {
				continue; // normalize() refuses it, so it is not the brief's exit-intent item.
			}
			$type = is_string( $it['type'] ?? null ) ? strtolower( $it['type'] ) : 'popup';
			$type = self::TYPE_ALIASES[ $type ] ?? $type;
			$trigger = is_string( $it['trigger'] ?? null ) ? $it['trigger'] : ( 'banner' === $type ? 'load' : 'exit_intent' ); // normalize()'s default.
			if ( 'exit_intent' === $trigger || self::DEFAULT_EXIT_INTENT['key'] === ( $it['key'] ?? '' ) ) {
				$exit = false; // The brief has its own exit-intent item.
			}
		}
		if ( false !== $exit ) {
			$item = array_merge( self::DEFAULT_EXIT_INTENT, is_array( $exit ) ? $exit : [] );
			if ( is_array( $exit ) && isset( $exit['copy'] ) && is_array( $exit['copy'] ) && self::DEFAULT_EXIT_INTENT['design'] === $item['design'] ) {
				$item = self::popup_copy( $item ); // copy { eyebrow, headline, body, button } writes the default popup's own texts.
			}
			array_unshift( $items, $item );
		}
		return $items;
	}

	/**
	 * The default popup with its texts from copy { eyebrow, headline, body, button }: they go into the design's first four text
	 * slots, in the order the design reads (small line, headline, sentence, button). Other copy keys stay for [[key]] fills.
	 *
	 * @param array $item The exit-intent item of the brief (design is the default popup's slug).
	 */
	private static function popup_copy( array $item ): array {
		$names = [ 'eyebrow', 'headline', 'body', 'button' ];
		$texts = [];
		foreach ( $names as $n ) {
			if ( isset( $item['copy'][ $n ] ) && is_scalar( $item['copy'][ $n ] ) && '' !== trim( (string) $item['copy'][ $n ] ) ) {
				$texts[ $n ] = trim( (string) $item['copy'][ $n ] );
			}
		}
		$content = Patterns::content( (string) $item['design'] );
		if ( ! $texts || null === $content ) {
			return $item;
		}
		$ids = [];
		foreach ( Patterns::slots( parse_blocks( $content ) ) as $s ) {
			if ( 'text' === $s['field'] ) {
				$ids[] = $s['id'];
			}
		}
		$slots = [];
		foreach ( $names as $i => $n ) {
			if ( isset( $texts[ $n ], $ids[ $i ] ) ) {
				$slots[ $ids[ $i ] ] = esc_html( $texts[ $n ] );
			}
		}
		$item['copy'] = array_diff_key( $item['copy'], array_flip( $names ) );
		if ( $slots ) {
			$item['design'] = [ 'pattern' => (string) $item['design'], 'slots' => $slots ];
		}
		return $item;
	}

	/** Module hook: build-site writes blueprint.conversions (same forms as the brief) once everything else exists. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'conversions', 'at' => 'done', 'run' => [ __CLASS__, 'blueprint_conversions' ] ] ];
	}

	public static function blueprint_conversions( array $bp, array $ctx ): array {
		unset( $ctx );
		if ( ! array_key_exists( 'conversions', $bp ) ) {
			return [ 'skipped' => 'no conversions in the blueprint' ];
		}
		if ( ! self::active() ) {
			return [ 'skipped' => 'inactive', 'warnings' => [ 'Kadence Conversions is not active: conversions were not created.' ] ];
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return [ 'skipped' => 'capability', 'warnings' => [ 'conversions need the edit_theme_options capability.' ] ];
		}
		try { // The last step of build-site: whatever goes wrong here must not cost the caller the report of everything before.
			return self::apply_items( self::brief_items( $bp['conversions'] ) );
		} catch ( \Throwable $e ) {
			return [ 'error' => get_class( $e ), 'warnings' => [ 'conversions were not (all) created: ' . $e->getMessage() ] ];
		}
	}

	/* ------------------------------------------------------------------ reading items */

	/** The Conversion Item block's attributes of a post ([] if there is none). */
	private static function block_of( \WP_Post $p ): array {
		foreach ( parse_blocks( (string) $p->post_content ) as $b ) {
			if ( self::BLOCK === ( $b['blockName'] ?? '' ) ) {
				return (array) $b['attrs'];
			}
		}
		return [];
	}

	/** The design of an item rendered (its inner blocks only, so Conversions' own block bookkeeping is not touched). */
	private static function design_html( \WP_Post $p ): string {
		foreach ( parse_blocks( (string) $p->post_content ) as $b ) {
			if ( self::BLOCK === ( $b['blockName'] ?? '' ) ) {
				$html = '';
				foreach ( (array) $b['innerBlocks'] as $inner ) {
					$html .= render_block( $inner );
				}
				return $html;
			}
		}
		return '';
	}

	private static function json_meta( int $id, string $k ): array {
		$v = json_decode( (string) get_post_meta( $id, $k, true ), true );
		return is_array( $v ) ? $v : [];
	}

	/** One item for get-conversions. */
	private static function describe( \WP_Post $p ): array {
		$a = self::block_of( $p );
		$lkey = (string) get_post_meta( $p->ID, '_lb_key', true );
		$ours = 0 === strpos( $lkey, self::KEY_PREFIX );
		$trigger = (string) ( $a['conversionTrigger'] ?? get_post_meta( $p->ID, '_kad_conversion_trigger', true ) );
		$repeat = $a['repeatControl'] ?? true;
		$expires = get_post_meta( $p->ID, '_kad_conversion_enable_expires', true ) ? (string) get_post_meta( $p->ID, '_kad_conversion_expires', true ) : '';
		$devices = array_values( array_filter( array_map( static function ( $d ) { return is_array( $d ) ? (string) ( $d['value'] ?? '' ) : ''; }, self::json_meta( $p->ID, '_kad_conversion_device' ) ) ) );
		$user = array_values( array_filter( array_map( static function ( $u ) { return is_array( $u ) ? (string) ( $u['role'] ?? '' ) : ''; }, self::json_meta( $p->ID, '_kad_conversion_user' ) ) ) );
		$out = [
			'id'        => $p->ID,
			'title'     => $p->post_title,
			'status'    => $p->post_status,
			'owner'     => $ours ? 'launch-builder' : 'other',
			'key'       => $ours ? substr( $lkey, strlen( self::KEY_PREFIX ) ) : null,
			'type'      => (string) ( $a['conversionType'] ?? get_post_meta( $p->ID, '_kad_conversion_type', true ) ),
			'trigger'   => $trigger,
			'delay'     => 'time' === $trigger ? ( (int) ( $a['delay'] ?? 5000 ) ) / 1000 : null,
			'scroll'    => 'scroll' === $trigger ? (int) ( $a['scroll'] ?? 300 ) : null,
			'link'      => 'link' === $trigger && ! empty( $a['uniqueID'] ) ? '#' . $a['uniqueID'] : null,
			'goal'      => (string) ( ( $a['conversionGoal'] ?? '' ) ?: 'form' ),
			'goal_class' => (string) ( $a['goalClass'] ?? '' ),
			'close_on_goal' => ! empty( $a['goalClose'] ),
			'analytics' => (bool) ( $a['conversionTracking'] ?? true ),
			'repeat'    => [ 'enabled' => (bool) $repeat, 'close_days' => (int) ( $a['closeRepeat'] ?? 30 ), 'goal_days' => (int) ( $a['convertRepeat'] ?? 90 ), 'cookie' => (string) ( ( $a['campaignID'] ?? '' ) ?: ( $a['uniqueID'] ?? '' ) ) ],
			'devices'   => $devices,
			'audience'  => $user ?: [ 'public' ],
			'expires'   => $expires,
			'expired'   => '' !== $expires && $expires < self::now(),
			'show'      => self::json_meta( $p->ID, '_kad_conversion_show' ) ?: [ self::rule( 'general|site' ) ],
			'hide'      => self::json_meta( $p->ID, '_kad_conversion_hide' ),
			'edit_url'  => get_edit_post_link( $p->ID, 'raw' ),
		];
		if ( $ours ) {
			$rr = self::role_rules( $p->ID );
			$input = self::json_meta( $p->ID, '_lb_input' );
			$out['show_roles'] = $rr['show'];
			$out['hide_roles'] = $rr['hide'];
			$out['roles_enforced'] = self::roles_enforced( (int) $p->ID ); // false: an admin changed the display conditions; those decide.
			$out['design'] = $input['design'] ?? null;
			$out['state'] = self::ownership( $p, $lkey );
		}
		return $out;
	}

	private static function now(): string {
		return ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d\TH:i:s' );
	}

	/** Conversions > Settings "Enable analytics" (on unless switched off). */
	private static function site_analytics(): bool {
		$s = json_decode( (string) get_option( 'kadence_conversions', '' ), true );
		return ! ( is_array( $s ) && isset( $s['enable_analytics'] ) && ! $s['enable_analytics'] );
	}

	/** Is there a published item with the exit-intent trigger? */
	private static function has_exit_intent(): bool {
		foreach ( get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'suppress_filters' => true ] ) as $p ) {
			if ( 'exit_intent' === ( self::block_of( $p )['conversionTrigger'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ audit */

	/** Module hook: audit checks (scope conversions; site-wide, each runs once per audit). */
	public static function audit_checks(): array {
		return [
			'conversion_missing_exit_intent'     => [ 'conversions', [ __CLASS__, 'check_exit_intent' ] ],
			'conversion_shown_on_conversion_page' => [ 'conversions', [ __CLASS__, 'check_conversion_pages' ] ],
			'conversion_no_goal'                 => [ 'conversions', [ __CLASS__, 'check_goals' ] ],
		];
	}

	public static function check_exit_intent( array $args = [] ): array {
		unset( $args );
		if ( ! self::active() || self::has_exit_intent() ) {
			return [];
		}
		return [ [ 'severity' => 'warning', 'code' => 'conversion_missing_exit_intent', 'object_type' => 'site', 'id' => 0, 'url' => admin_url( 'edit.php?post_type=' . self::POST_TYPE ), 'message' => 'No published exit-intent popup: every site ships one.', 'fix' => 'set-conversion { key: "exit-intent" } (or publish the drafted one).' ] ];
	}

	/** Published items that would show on conversion or thank-you pages (role filter for LB items, native rules for all). */
	public static function check_conversion_pages( array $args = [] ): array {
		unset( $args );
		if ( ! self::active() ) {
			return [];
		}
		$pages = [];
		foreach ( [ 'conversion', 'thanks' ] as $role ) {
			foreach ( self::role_page_ids( $role ) as $id ) {
				if ( 'publish' === get_post_status( $id ) ) {
					$pages[ $id ] = $role;
				}
			}
		}
		$out = [];
		foreach ( get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'suppress_filters' => true ] ) as $p ) {
			$rr = self::roles_enforced( (int) $p->ID ) ? self::role_rules( (int) $p->ID ) : null; // Not enforced: the native rules decide.
			foreach ( $pages as $pid => $role ) {
				$roles_ok = null === $rr || ( ( ! $rr['show'] || in_array( $role, $rr['show'], true ) ) && ! in_array( $role, $rr['hide'], true ) );
				if ( $roles_ok && self::native_shows_on_page( $p, (int) $pid ) ) {
					$out[] = [ 'severity' => 'warning', 'code' => 'conversion_shown_on_conversion_page', 'object_type' => self::POST_TYPE, 'id' => $p->ID, 'url' => get_permalink( $pid ), 'message' => sprintf( '"%s" shows on %s (a %s page): visitors there are already converting.', $p->post_title, get_the_title( $pid ), Roles::label( $role ) ), 'fix' => null === $rr ? 'Add a hide rule for that page in the item\'s display settings.' : 'set-conversion with hide_roles including ' . $role . '.' ];
				}
			}
		}
		return $out;
	}

	/** Would Conversions' own rules show this item on a published page (as a visitor, now)? Mirrors check_conversion_conditionals for singular pages. */
	private static function native_shows_on_page( \WP_Post $item, int $page_id ): bool {
		$cond = [ 'general|site', 'general|singular', 'singular|page' ];
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $page_id ) {
			$cond[] = 'general|front_page';
		}
		$match = static function ( array $rule ) use ( $cond, $page_id ): bool {
			$r = (string) ( $rule['rule'] ?? '' );
			if ( ! in_array( $r, $cond, true ) ) {
				return false;
			}
			if ( ! in_array( explode( '|', $r )[0], [ 'singular', 'tax_archive' ], true ) ) {
				return true;
			}
			$sel = $rule['select'] ?? 'all';
			if ( 'all' === $sel ) {
				return true;
			}
			return 'ids' === $sel && in_array( $page_id, (array) ( $rule['ids'] ?? [] ), true );
		};
		$show = self::json_meta( $item->ID, '_kad_conversion_show' ) ?: [ self::rule( 'general|site' ) ];
		if ( ! array_filter( $show, $match ) ) {
			return false;
		}
		if ( array_filter( self::json_meta( $item->ID, '_kad_conversion_hide' ), $match ) ) {
			return false;
		}
		$user = array_map( static function ( $u ) { return is_array( $u ) ? (string) ( $u['role'] ?? '' ) : ''; }, self::json_meta( $item->ID, '_kad_conversion_user' ) );
		if ( array_filter( $user ) && ! array_intersect( [ 'public', 'logged_out' ], $user ) ) {
			return false; // Shown to logged-in users only: not to visitors.
		}
		$expires = get_post_meta( $item->ID, '_kad_conversion_enable_expires', true ) ? (string) get_post_meta( $item->ID, '_kad_conversion_expires', true ) : '';
		return '' === $expires || $expires >= self::now();
	}

	/** Published items without a working goal (nothing to click or submit) or with analytics off. */
	public static function check_goals( array $args = [] ): array {
		unset( $args );
		if ( ! self::active() ) {
			return [];
		}
		$out = [];
		foreach ( get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'suppress_filters' => true ] ) as $p ) {
			$a = self::block_of( $p );
			$goal = (string) ( ( $a['conversionGoal'] ?? '' ) ?: 'form' );
			$problem = '';
			if ( ! $a ) {
				$problem = 'it has no Conversion Item block';
			} elseif ( false === ( $a['conversionTracking'] ?? true ) ) {
				$problem = 'analytics tracking is off';
			} elseif ( 'none' === $goal ) {
				$problem = 'it has no conversion goal';
			} else {
				$html = self::design_html( $p ); // Rendered: Kadence buttons and forms are dynamic blocks.
				if ( 'button' === $goal && ! empty( $a['goalClass'] ) && ! preg_match( '/\sclass="[^"]*(?<![\w-])' . preg_quote( (string) $a['goalClass'], '/' ) . '(?![\w-])/', $html ) ) {
					$problem = 'its goal is a click on .' . $a['goalClass'] . ' but no element in the design has that class';
				} elseif ( 'button' === $goal && empty( $a['goalClass'] ) && ! preg_match( '/<a\s[^>]*href=/i', $html ) ) {
					$problem = 'its goal is a link click but the design has no link';
				} elseif ( 'form' === $goal && false === stripos( $html, '<form' ) ) {
					$problem = 'its goal is a form submission but the design has no form';
				}
			}
			if ( '' !== $problem ) {
				$out[] = [ 'severity' => 'warning', 'code' => 'conversion_no_goal', 'object_type' => self::POST_TYPE, 'id' => $p->ID, 'url' => get_edit_post_link( $p->ID, 'raw' ), 'message' => sprintf( '"%s": %s, so it records no conversions.', $p->post_title, $problem ), 'fix' => 'Give the primary button the goal class (default conversion-button), or set goal_class / goal with set-conversion.' ];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ site state + playbook */

	public static function site_state( array &$state ): void {
		if ( ! self::active() ) {
			$state['conversions'] = [ 'active' => false ];
			return;
		}
		$lb = 0;
		foreach ( self::lb_posts() as $p ) {
			$lb += 'publish' === $p->post_status ? 1 : 0;
		}
		$state['conversions'] = [
			'active'     => true,
			'published'  => (int) ( wp_count_posts( self::POST_TYPE )->publish ?? 0 ),
			'lb_items'   => $lb,
			'exit_intent' => self::has_exit_intent(),
			'owns_slots' => array_values( array_filter( array_keys( self::SLOTS ), [ __CLASS__, 'owns_slot' ] ) ),
			'live_slots' => array_values( array_filter( array_keys( self::SLOTS ), [ __CLASS__, 'slot_live' ] ) ),
		];
	}

	public static function playbook( array &$pb ): void {
		$pb['conversions'] = 'Popups, slide-ins and banners come from Kadence Conversions when it is active: set-conversion writes one by key (design = a pattern; trigger exit_intent / delay / scroll / click / load; shown by page role, hidden on conversion, thank-you and legal pages by default; goal = a click on .conversion-button with analytics on; repeat = days a closed item stays away). Every site gets an exit-intent popup. While Conversions is active it also carries the mobile sticky call bar and the notice bar. get-conversions lists them; delete-conversion switches one off.';
		$pb['tools_by_job']['Popups and bars (Kadence Conversions)'] = 'get-conversions, set-conversion, delete-conversion';
	}
}
