<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page roles: what kind of page is being shown (home, a service, a conversion page...). Chrome (heroes, crumbs, headers,
 * footers), tokens and the audit all key off the role, so it is resolved in one place.
 *
 * Where a role comes from:
 *  - front page = home, posts page = blog (never overridable); services, areas and posts by post type;
 *  - other pages: the _lb_role page meta (page|legal|conversion|thanks), else the hubs option (services_hub/areas_hub),
 *    else category when a service category's _lb_page term meta points at the page, else page;
 *  - WooCommerce pages, other post types and other archives: other (no chrome). Opt a post type in with the filter
 *    launch_builder_role_post_types ( [ post_type => role ] ).
 *
 * Roles::current() resolution order (memoised only once the main query exists, i.e. after the 'wp' action):
 *  1. Context::simulate() value.
 *  2. After 'wp' on the front end: the main query (front page, 404, search, category archive, blog views, singulars).
 *  3. No main query (REST, admin, AJAX, CLI, cron): the request post_id (REST/admin/AJAX only, readable posts only), else
 *     the global $post (the block renderer and core/post-template set it, so block context lands here too).
 *  4. Template posts (kadence_element, wp_block, kadence_header, kadence_navigation, kadence_form): the first role in
 *     their _lb_roles meta; sample() gives a matching object to render them with.
 *  5. Otherwise ''. The result passes through the filter launch_builder_role ( $role, ?WP_Post ).
 */
final class Roles {

	/** Every role, slug => label. */
	const ROLES = [
		'home'         => 'Home page',
		'page'         => 'Standard page',
		'legal'        => 'Legal page',
		'services_hub' => 'Services hub',
		'areas_hub'    => 'Service areas hub',
		'category'     => 'Service category page',
		'service'      => 'Service',
		'area'         => 'Service area',
		'conversion'   => 'Conversion page',
		'thanks'       => 'Thank-you page',
		'post'         => 'Blog post',
		'blog'         => 'Blog and archives',
		'search'       => 'Search results',
		'notfound'     => 'Not found (404)',
		'other'        => 'Other (no Launch Builder chrome)',
	];

	/** Roles a page can be given with the _lb_role meta. Hubs live in the hubs option, categories in term meta. */
	const PAGE_ROLES = [ 'page', 'legal', 'conversion', 'thanks' ];

	/** Roles set-page-role accepts ('auto' = forget the explicit role and the hub entry). */
	const ASSIGNABLE = [ 'auto', 'page', 'legal', 'conversion', 'thanks', 'services_hub', 'areas_hub', 'category' ];

	/** Post types that hold designs rather than content (their role comes from _lb_roles). */
	const TEMPLATE_TYPES = [ 'kadence_element', 'wp_block', 'kadence_header', 'kadence_navigation', 'kadence_form' ];

	const META     = '_lb_role';
	const OPT_HUBS = 'launch_builder_hubs';

	/**
	 * Page recipe (build-site pages[].recipe) => the role a page built from it has. home is the front page
	 * and page the default, so neither is written; category comes from the service category's _lb_page (entries.categories).
	 */
	const RECIPE_ROLES = [
		'home'             => 'home',
		'about'            => 'page',
		'faq'              => 'page',
		'reviews'          => 'page',
		'service-category' => 'category',
		'service-area'     => 'areas_hub',
		'contact'          => 'conversion',
		'quote'            => 'conversion',
		'booking'          => 'conversion',
		'landing'          => 'conversion',
		'thank-you'        => 'thanks',
		'privacy'          => 'legal',
		'terms'            => 'legal',
		'accessibility'    => 'legal',
	];

	/** Hub slot => the page path today's breadcrumb trail uses for it (derive_hubs() fills empty slots from these). */
	const HUB_PATHS = [ 'services' => 'services', 'areas' => 'service-areas' ];

	/** Why a page has its role, for people (the meta box and the editor panel). */
	const WHY = [ 'front_page' => 'it is the front page', 'posts_page' => 'it is the posts page', 'meta' => 'set here', 'woo' => 'it is a WooCommerce page', 'hub' => 'set as a hub (set-page-role)', 'category' => 'a service category points at it', 'default' => 'automatic' ];

	/** @var string|null role of the main query, once known. */
	private static $memo = null;

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'add_meta_boxes_page', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_page', [ __CLASS__, 'save_box' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'meta_changed' ], 10, 3 );
		}
		add_filter( 'manage_page_posts_columns', [ __CLASS__, 'column' ] );
		add_action( 'manage_page_posts_custom_column', [ __CLASS__, 'column_value' ], 10, 2 );
		Migrate::register_step( 'roles-derive', [ __CLASS__, 'migrate_derive' ] );
	}

	/* ------------------------------------------------------------------ resolution */

	/** The role of the current request ('' when there is nothing to resolve). */
	public static function current(): string {
		$sim = Context::current();
		if ( $sim ) {
			return $sim['role'];
		}
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$main = did_action( 'wp' ) && ! is_admin();
		if ( $main ) {
			[ $role, $post ] = self::from_query();
		} else {
			$post = self::request_post();
			$role = $post ? self::resolve( $post ) : '';
		}
		$role = (string) apply_filters( 'launch_builder_role', $role, $post );
		if ( $main ) {
			self::$memo = $role;
		}
		return $role;
	}

	/** The role of a post by id (same rules, no request context). '' for unknown posts. */
	public static function of( $id ): string {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return '';
		}
		return (string) apply_filters( 'launch_builder_role', self::resolve( $post ), $post );
	}

	/** Human label for a role. */
	public static function label( string $role ): string {
		return self::ROLES[ $role ] ?? ( '' === $role ? 'None' : $role );
	}

	/**
	 * Post type => role for singular content. Filter launch_builder_role_post_types adds more (unknown types are 'other').
	 *
	 * @return array<string,string>
	 */
	public static function post_types(): array {
		$map = (array) apply_filters( 'launch_builder_role_post_types', [ 'page' => 'page', 'post' => 'post', Entries::SERVICE => 'service', Entries::AREA => 'area' ] );
		return array_filter( $map, static function ( $r ) { return is_string( $r ) && isset( self::ROLES[ $r ] ); } );
	}

	/** @return array{0:string,1:?\WP_Post} role (unfiltered) and the object it came from, for the main query. */
	private static function from_query(): array {
		$obj = get_queried_object();
		$post = $obj instanceof \WP_Post ? $obj : null;
		if ( is_front_page() ) {
			return [ 'home', $post ];
		}
		if ( is_404() ) {
			return [ 'notfound', null ];
		}
		if ( is_search() ) {
			return [ 'search', null ];
		}
		if ( is_tax( Entries::CAT ) ) {
			return [ 'category', null ];
		}
		if ( is_home() || is_category() || is_tag() || is_date() || is_author() ) {
			return [ 'blog', $post ];
		}
		if ( is_singular() && $post ) {
			return [ self::resolve( $post ), $post ];
		}
		return [ 'other', $post ]; // Other archives (other post types, other taxonomies, WooCommerce shop...).
	}

	/** The post to resolve when there is no main query: request post_id (REST/admin/AJAX), else the global $post. */
	private static function request_post(): ?\WP_Post {
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( isset( $_REQUEST['post_id'] ) && ( $rest || is_admin() || wp_doing_ajax() ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only lookup.
			$id = absint( wp_unslash( $_REQUEST['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$p = $id ? get_post( $id ) : null;
			if ( $p && current_user_can( 'read_post', $id ) ) {
				return $p;
			}
		}
		$p = get_post();
		return $p instanceof \WP_Post ? $p : null;
	}

	/** Role of a post, unfiltered (the rules in the class comment). */
	private static function resolve( \WP_Post $post ): string {
		if ( in_array( $post->post_type, self::TEMPLATE_TYPES, true ) ) {
			return self::template_roles( $post->ID )[0] ?? '';
		}
		if ( 'page' === $post->post_type ) {
			return self::explain_page( $post->ID )[0];
		}
		return self::post_types()[ $post->post_type ] ?? 'other';
	}

	/**
	 * A page's role and where it came from: front_page | posts_page | meta | woo | hub | category | default.
	 * WordPress honours the front and posts pages only when "Your homepage displays" is a static page (show_on_front).
	 *
	 * @param int  $id       page id.
	 * @param bool $use_meta false = the role the page would have without its _lb_role (what "Automatic" means for it).
	 * @return array{0:string,1:string}
	 */
	public static function explain_page( int $id, bool $use_meta = true ): array {
		$static = 'page' === get_option( 'show_on_front' );
		if ( $static && $id === (int) get_option( 'page_on_front' ) ) {
			return [ 'home', 'front_page' ];
		}
		if ( $static && $id && $id === (int) get_option( 'page_for_posts' ) ) {
			return [ 'blog', 'posts_page' ];
		}
		$meta = $use_meta ? (string) get_post_meta( $id, self::META, true ) : '';
		if ( in_array( $meta, self::PAGE_ROLES, true ) ) {
			return [ $meta, 'meta' ];
		}
		if ( self::is_woo_page( $id ) ) {
			return [ 'other', 'woo' ];
		}
		$hubs = self::hubs();
		if ( $hubs['services'] && $id === $hubs['services'] ) {
			return [ 'services_hub', 'hub' ];
		}
		if ( $hubs['areas'] && $id === $hubs['areas'] ) {
			return [ 'areas_hub', 'hub' ];
		}
		if ( isset( self::catmap()[ $id ] ) ) {
			return [ 'category', 'category' ];
		}
		return [ 'page', 'default' ];
	}

	/** Roles a template post (element, synced pattern...) is designed for, from its _lb_roles meta (array, JSON or CSV). */
	public static function template_roles( int $id ): array {
		$raw = get_post_meta( $id, '_lb_roles', true );
		if ( is_string( $raw ) ) {
			$dec = json_decode( $raw, true );
			$raw = is_array( $dec ) ? $dec : explode( ',', $raw );
		}
		$out = [];
		foreach ( (array) $raw as $r ) {
			$r = sanitize_key( (string) $r );
			if ( isset( self::ROLES[ $r ] ) ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function is_woo_page( int $id ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $wp ) {
			if ( $id === (int) wc_get_page_id( $wp ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A published object to render a role's designs with (template posts, previews): the first entry of that type, the hub
	 * page, a page with that role... null when the role has no object (search, notfound, other) or none exists yet.
	 */
	public static function sample( string $role ): ?\WP_Post {
		$first = static function ( array $args ): ?\WP_Post {
			$p = get_posts( $args + [ 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] );
			return $p ? $p[0] : null;
		};
		$page = static function ( int $id ): ?\WP_Post {
			$p = $id ? get_post( $id ) : null;
			return $p && 'publish' === $p->post_status ? $p : null;
		};
		switch ( $role ) {
			case 'service':
				return $first( [ 'post_type' => Entries::SERVICE ] );
			case 'area':
				return $first( [ 'post_type' => Entries::AREA ] );
			case 'post':
				return $first( [ 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC' ] );
			case 'home':
				return 'page' === get_option( 'show_on_front' ) ? $page( (int) get_option( 'page_on_front' ) ) : null;
			case 'blog':
				return 'page' === get_option( 'show_on_front' ) ? $page( (int) get_option( 'page_for_posts' ) ) : null;
			case 'services_hub':
				return $page( self::hubs()['services'] );
			case 'areas_hub':
				return $page( self::hubs()['areas'] );
			case 'category':
				foreach ( array_keys( self::catmap() ) as $pid ) {
					if ( $p = $page( (int) $pid ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
						return $p;
					}
				}
				return null;
			case 'legal':
			case 'conversion':
			case 'thanks':
				return $first( [ 'post_type' => 'page', 'meta_key' => self::META, 'meta_value' => $role ] );
			case 'page':
				foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 30, 'orderby' => 'menu_order title', 'order' => 'ASC' ] ) as $p ) {
					if ( 'page' === self::explain_page( $p->ID )[0] ) {
						return $p;
					}
				}
				return null;
		}
		return null;
	}

	/* ------------------------------------------------------------------ hubs + category map */

	/** @return array{services:int,areas:int} hub page ids (0 = none). */
	public static function hubs(): array {
		$h = get_option( self::OPT_HUBS, [] );
		$h = is_array( $h ) ? $h : [];
		return [ 'services' => (int) ( $h['services'] ?? 0 ), 'areas' => (int) ( $h['areas'] ?? 0 ) ];
	}

	/**
	 * Category pages: page id => service category term id, from the terms' _lb_page meta. One get_terms per change of
	 * the terms cache (core bumps it on every term and term-meta write, so there is nothing to invalidate by hand).
	 * When several terms point at one page the deepest term wins (catmap_conflicts() lists them for the audit).
	 *
	 * @return array<int,int>
	 */
	public static function catmap(): array {
		return self::catmap_data()['map'];
	}

	/** @return array<int,int[]> page id => every term id pointing at it, for pages claimed by more than one term. */
	public static function catmap_conflicts(): array {
		return self::catmap_data()['conflicts'];
	}

	private static function catmap_data(): array {
		if ( ! taxonomy_exists( Entries::CAT ) ) {
			return [ 'map' => [], 'conflicts' => [] ]; // Before init: nothing to read yet (and nothing cached).
		}
		$key = 'catmap:' . wp_cache_get_last_changed( 'terms' );
		$hit = wp_cache_get( $key, 'launch_builder' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$terms = get_terms( [ 'taxonomy' => Entries::CAT, 'hide_empty' => false, 'meta_key' => '_lb_page', 'orderby' => 'term_id' ] );
		$map = [];
		$depth = [];
		$all = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			$pid = (int) get_term_meta( $t->term_id, '_lb_page', true );
			if ( $pid <= 0 ) {
				continue;
			}
			$d = count( get_ancestors( $t->term_id, Entries::CAT, 'taxonomy' ) );
			$all[ $pid ][] = (int) $t->term_id;
			if ( ! isset( $map[ $pid ] ) || $d > $depth[ $pid ] ) {
				$map[ $pid ] = (int) $t->term_id;
				$depth[ $pid ] = $d;
			}
		}
		$data = [ 'map' => $map, 'conflicts' => array_filter( $all, static function ( $ids ) { return count( $ids ) > 1; } ) ];
		wp_cache_set( $key, $data, 'launch_builder' );
		return $data;
	}

	/* ------------------------------------------------------------------ derived roles (migration, build-site) */

	/** A page whose role is only the default (no _lb_role, not a hub, category, front, posts or WooCommerce page). */
	private static function plain_page( int $id ): bool {
		return 'default' === self::explain_page( $id )[1];
	}

	/**
	 * Fill EMPTY hub slots from the published pages today's breadcrumb trail uses (/services/, /service-areas/), so a
	 * site keeps its hub crumbs when the trail moves to the hubs option. Never replaces a hub, never takes a page that
	 * has a role of its own. Snapshots the hubs option before writing.
	 *
	 * @return string[] what changed.
	 */
	public static function derive_hubs( string $label ): array {
		$hubs = self::hubs();
		$new = $hubs;
		$out = [];
		foreach ( self::HUB_PATHS as $slot => $path ) {
			$p = $new[ $slot ] ? null : get_page_by_path( $path, OBJECT, 'page' );
			if ( $p && 'publish' === $p->post_status && ! in_array( (int) $p->ID, $new, true ) && self::plain_page( (int) $p->ID ) ) {
				$new[ $slot ] = (int) $p->ID;
				$out[] = $slot . ' hub = page ' . $p->ID . ' (/' . $path . '/)';
			}
		}
		if ( $new !== $hubs ) {
			Abilities::snapshot( $label, [], [ self::OPT_HUBS ] );
			update_option( self::OPT_HUBS, $new, true );
		}
		return $out;
	}

	/**
	 * Migration step roles-derive (runs once per site): empty hub slots from /services/ and /service-areas/, and the
	 * WordPress privacy policy page becomes legal when it has no role of its own.
	 */
	public static function migrate_derive( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$out = self::derive_hubs( 'migrate roles-derive' );
		$priv = (int) get_option( 'wp_page_for_privacy_policy' );
		$pp = $priv ? get_post( $priv ) : null;
		if ( $pp && 'page' === $pp->post_type && 'trash' !== $pp->post_status && self::plain_page( $priv ) ) {
			update_post_meta( $priv, self::META, 'legal' );
			$out[] = 'page ' . $priv . ' (privacy policy) = legal';
		}
		return [ 'done' => true, 'report' => $out ? implode( '; ', $out ) : 'nothing to derive' ];
	}

	/** Module hook: build-site gives pages the role of their recipe (RECIPE_ROLES) once pages and categories exist. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'roles', 'at' => 'categories', 'run' => [ __CLASS__, 'blueprint_roles' ] ] ];
	}

	/**
	 * Recipe roles for the blueprint's pages: conversion/thanks/legal as _lb_role, service-area as the areas hub. Only
	 * pages without a role of their own and only empty hub slots are written, so a re-run never undoes set-page-role.
	 * Then the derive_hubs() rule (empty slots from /services/ and /service-areas/).
	 */
	public static function blueprint_roles( array $bp, array $ctx ): array {
		$ids = (array) ( $ctx['ids'] ?? [] );
		$set = [];
		$warnings = [];
		$hubs = self::hubs();
		$new = $hubs;
		foreach ( (array) ( $bp['pages'] ?? [] ) as $pg ) {
			$pg = (array) $pg;
			$role = self::RECIPE_ROLES[ sanitize_key( (string) ( $pg['recipe'] ?? '' ) ) ] ?? '';
			$slug = sanitize_title( (string) ( $pg['slug'] ?? ( $pg['title'] ?? '' ) ) );
			$id = (int) ( $ids[ $slug ] ?? 0 );
			if ( '' === $role || ! $id ) {
				continue;
			}
			if ( in_array( $role, [ 'legal', 'conversion', 'thanks' ], true ) ) {
				if ( self::plain_page( $id ) ) {
					update_post_meta( $id, self::META, $role );
					$set[ $slug ] = $role;
				}
			} elseif ( 'areas_hub' === $role ) {
				if ( ! $new['areas'] && ! in_array( $id, $new, true ) && self::plain_page( $id ) ) {
					$new['areas'] = $id;
					$set[ $slug ] = $role;
				}
			} elseif ( 'category' === $role && ! isset( self::catmap()[ $id ] ) ) {
				$warnings[] = 'page ' . $slug . ' uses the service-category recipe but no service category points at it (entries.categories[].page), so it is a standard page.';
			}
		}
		if ( $new !== $hubs ) {
			if ( current_user_can( 'edit_theme_options' ) ) {
				Abilities::snapshot( 'build-site roles', [], [ self::OPT_HUBS ] );
				update_option( self::OPT_HUBS, $new, true );
			} else {
				$warnings[] = 'hub pages not set: that needs the edit_theme_options capability.';
				$set = array_filter( $set, static function ( $r ) { return 'areas_hub' !== $r; } );
			}
		}
		$derived = current_user_can( 'edit_theme_options' ) ? self::derive_hubs( 'build-site roles' ) : [];
		return [ 'set' => $set, 'derived' => $derived, 'warnings' => $warnings ];
	}

	/* ------------------------------------------------------------------ page meta, meta box, list column */

	public static function register_meta(): void {
		register_post_meta(
			'page',
			self::META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				// '' = automatic. The editor sends the whole meta object back on save, so '' must validate.
				'show_in_rest'      => [ 'schema' => [ 'type' => 'string', 'enum' => array_merge( [ '' ], self::PAGE_ROLES ) ] ],
				'sanitize_callback' => static function ( $v ) { return in_array( $v, self::PAGE_ROLES, true ) ? $v : ''; },
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) { return current_user_can( 'edit_post', (int) $post_id ); },
			]
		);
	}

	/**
	 * The classic-editor "Page role" box. It is a back-compat box: the block editor never renders it (it shows the
	 * editor_panel() instead), so page edits there stay out of the legacy meta-box save (an extra POST on every save).
	 */
	public static function meta_box(): void {
		add_meta_box( 'lb_page_role', 'Page role', [ __CLASS__, 'box' ], 'page', 'side', 'default', [ '__back_compat_meta_box' => true ] );
	}

	public static function box( \WP_Post $post ): void {
		wp_nonce_field( 'lb_page_role', 'lb_page_role_nonce' );
		[ $role, $source ] = self::explain_page( $post->ID );
		$meta = (string) get_post_meta( $post->ID, self::META, true );
		$locked = in_array( $source, [ 'front_page', 'posts_page' ], true );
		echo '<p><label for="lb-role"><strong>Role</strong></label><br><select id="lb-role" name="lb_role" style="width:100%"' . ( $locked ? ' disabled' : '' ) . '>';
		echo '<option value="">Automatic</option>';
		foreach ( self::PAGE_ROLES as $r ) {
			echo '<option value="' . esc_attr( $r ) . '"' . selected( $meta, $r, false ) . '>' . esc_html( self::ROLES[ $r ] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">Currently: <strong>' . esc_html( self::label( $role ) ) . '</strong> (' . esc_html( self::WHY[ $source ] ?? $source ) . '). The role decides the page hero, breadcrumbs and header/footer variant. Conversion pages (contact, quote, booking) get no breadcrumbs.</p>';
		$hero = (string) get_post_meta( $post->ID, Chrome::HERO_META, true );
		echo '<p><label for="lb-hero"><strong>Hero on this page</strong></label><br><select id="lb-hero" name="lb_hero" style="width:100%">';
		foreach ( [ '' => 'The shared hero', 'content' => 'Its own hero', 'none' => 'No hero' ] as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $hero, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p class="description">The shared hero is set once for every page of this role in Launch Builder &gt; Design hub. &quot;Its own hero&quot; lets this page carry a different hero in its content: the shared hero and the breadcrumbs step aside here.</p>';
	}

	/** Classic-editor save. Writes only when the role changed (the purge comes from meta_changed()). */
	public static function save_box( int $post_id, \WP_Post $post ): void {
		if ( empty( $_POST['lb_page_role_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lb_page_role_nonce'] ) ), 'lb_page_role' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( isset( $_POST['lb_hero'] ) ) {
			$hero = sanitize_key( wp_unslash( $_POST['lb_hero'] ) );
			$hero = in_array( $hero, [ 'content', 'none' ], true ) ? $hero : '';
			if ( (string) get_post_meta( $post_id, Chrome::HERO_META, true ) !== $hero ) {
				'' === $hero ? delete_post_meta( $post_id, Chrome::HERO_META ) : update_post_meta( $post_id, Chrome::HERO_META, $hero );
			}
		}
		if ( ! isset( $_POST['lb_role'] ) ) {
			return; // Disabled select (front or posts page): nothing posted, nothing to change.
		}
		$role = sanitize_key( wp_unslash( $_POST['lb_role'] ) );
		$new = in_array( $role, self::PAGE_ROLES, true ) ? $role : '';
		if ( (string) get_post_meta( $post_id, self::META, true ) === $new ) {
			return;
		}
		'' === $new ? delete_post_meta( $post_id, self::META ) : update_post_meta( $post_id, self::META, $new );
	}

	/**
	 * Block editor: a "Page role" panel in the document sidebar, bound to the registered _lb_role meta (saved with the
	 * post over REST, so no legacy meta box). The page's current and automatic roles are passed in for the help text.
	 * Pages, posts and entries also get the hero choice (_lb_hero: the shared hero, its own hero, none): the way one page
	 * differs from the page type it belongs to (a page with its own hero makes the shared hero and breadcrumbs step aside).
	 */
	public static function editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post = get_post();
		if ( ! $screen || ! $post || $screen->post_type !== $post->post_type || ! in_array( $post->post_type, [ 'page', 'post', Entries::SERVICE, Entries::AREA ], true ) ) {
			return;
		}
		// Every type gets the hero choice (the shared hero, its own, none): it is how one page differs from its page type.
		$data = [
			'type' => $post->post_type,
			'hero' => [
				'meta'    => Chrome::HERO_META,
				'label'   => __( 'Hero on this page', 'launch-builder' ),
				'options' => [
					[ 'label' => __( 'The shared hero', 'launch-builder' ), 'value' => '' ],
					[ 'label' => __( 'Its own hero', 'launch-builder' ), 'value' => 'content' ],
					[ 'label' => __( 'No hero', 'launch-builder' ), 'value' => 'none' ],
				],
				'help'    => __( 'The shared hero is set once for every page of this type in Launch Builder > Design hub. Choose "Its own hero" to put a different hero design in this page\'s content: the shared hero and the breadcrumbs then step aside here.', 'launch-builder' ),
			],
		];
		if ( 'page' === $post->post_type ) {
			[ $role, $source ] = self::explain_page( $post->ID );
			[ $auto, $auto_source ] = self::explain_page( $post->ID, false );
			$labels = [];
			foreach ( self::PAGE_ROLES as $r ) {
				$labels[ $r ] = self::ROLES[ $r ];
			}
			$data += [
				'meta'    => self::META,
				'locked'  => in_array( $source, [ 'front_page', 'posts_page' ], true ),
				'current' => [ 'label' => self::label( $role ), 'why' => self::WHY[ $source ] ?? $source ],
				'auto'    => [ 'label' => self::label( $auto ), 'why' => self::WHY[ $auto_source ] ?? $auto_source ],
				'roles'   => $labels,
			];
		}
		wp_enqueue_script( 'lb-page-role', LB_URL . 'assets/js/page-role.js', [ 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor', 'wp-edit-post' ], (string) @filemtime( LB_DIR . 'assets/js/page-role.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-page-role', 'window.lbPageRole = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/** Any write that changes a page's role (meta box, editor panel over REST, tools) purges the page caches once. */
	public static function meta_changed( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids, $object_id );
		if ( self::META === $meta_key ) {
			Cache::purge_later();
		}
	}

	public static function column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				$out['lb_role'] = 'Role';
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out['lb_role'] ) ) {
			$out['lb_role'] = 'Role';
		}
		return $out;
	}

	public static function column_value( string $col, int $post_id ): void {
		if ( 'lb_role' === $col ) {
			echo esc_html( self::label( self::of( $post_id ) ) );
		}
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'set-page-role', 'Set a page\'s role', 'Say what kind of page a page is, so it gets the right hero, breadcrumbs and header/footer variant. role: page (standard), legal (privacy, terms), conversion (contact, quote, booking, landing: no breadcrumbs), thanks (thank-you pages), services_hub / areas_hub (the page listing all services / all areas; stored in the hubs setting), category (the landing page of a service category: give term = the category slug, name or id; stored on the category), auto (forget an explicit role or hub, back to automatic). The front page is always home and the posts page always blog. Give post_id or path.',
			[ 'properties' => [
				'post_id' => [ 'type' => 'integer', 'description' => 'The page id (or give path).' ],
				'path'    => [ 'type' => 'string', 'description' => 'The page path instead of post_id, e.g. "contact" or "services/drains".' ],
				'role'    => [ 'type' => 'string', 'enum' => self::ASSIGNABLE ],
				'term'    => [ 'type' => [ 'string', 'integer' ], 'description' => 'For role category: the service category (slug, name or term id).' ],
			], 'required' => [ 'role' ] ], [ __CLASS__, 'set_role_tool' ], 'edit_pages' );

		Abilities::add( 'get-roles', 'List page roles', 'The role of every page (paginated), with where it comes from (front_page, posts_page, meta, woo, hub, category, default), the hub pages and the category pages map.',
			[ 'properties' => [
				'offset' => [ 'type' => 'integer', 'description' => 'Skip this many pages (default 0).' ],
				'limit'  => [ 'type' => 'integer', 'description' => 'Pages per call, 1-200 (default 100).' ],
			] ], [ __CLASS__, 'get_roles_tool' ], 'edit_pages', [ 'readonly' => true ] );
	}

	public static function set_role_tool( $input ) {
		$input = (array) $input;
		$role = (string) ( $input['role'] ?? '' );
		if ( ! in_array( $role, self::ASSIGNABLE, true ) ) {
			return new \WP_Error( 'lb_role', 'role must be one of: ' . implode( ', ', self::ASSIGNABLE ) . '.', [ 'status' => 400 ] );
		}
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( ! $id && isset( $input['path'] ) && is_string( $input['path'] ) && '' !== trim( $input['path'], '/ ' ) ) {
			$path = implode( '/', array_map( 'sanitize_title', explode( '/', trim( $input['path'], '/ ' ) ) ) );
			$p = get_page_by_path( $path, OBJECT, 'page' );
			$id = $p ? (int) $p->ID : 0;
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'lb_no_page', 'No such page. Give post_id or path of a page (services and areas have fixed roles).', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that page.', [ 'status' => 403 ] );
		}
		$fixed = self::explain_page( $id )[1];
		if ( in_array( $fixed, [ 'front_page', 'posts_page' ], true ) ) {
			return new \WP_Error( 'lb_role_fixed', 'The front page is always home and the posts page always blog.', [ 'status' => 409 ] );
		}
		$hubs = self::hubs();
		$previous = [ 'role' => self::of( $id ), 'meta' => (string) get_post_meta( $id, self::META, true ), 'hubs' => $hubs ];
		$warnings = [];
		$snap = null;
		$changed = false; // Only a real change purges the page caches.
		$write_hubs = static function ( array $new ) use ( $hubs, &$snap, &$changed ) {
			if ( $new === $hubs ) {
				return true;
			}
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'lb_forbidden', 'Changing the hub pages needs the edit_theme_options capability.', [ 'status' => 403 ] );
			}
			$snap = Abilities::snapshot( 'set-page-role', [], [ self::OPT_HUBS ] );
			update_option( self::OPT_HUBS, $new, true );
			$changed = true;
			return true;
		};
		$unhub = $hubs;
		foreach ( $unhub as $k => $hid ) {
			if ( $hid === $id ) {
				$unhub[ $k ] = 0; // A page holds one role: leaving a hub when it becomes something else.
			}
		}
		if ( in_array( $role, [ 'services_hub', 'areas_hub' ], true ) ) {
			$unhub[ 'services_hub' === $role ? 'services' : 'areas' ] = $id;
		}
		$term_id = 0;
		if ( 'category' === $role ) {
			$t = $input['term'] ?? '';
			$term = is_numeric( $t ) ? get_term( (int) $t, Entries::CAT ) : null;
			if ( ! $term || is_wp_error( $term ) ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $t ), Entries::CAT ) ?: get_term_by( 'name', sanitize_text_field( (string) $t ), Entries::CAT );
			}
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'lb_no_term', 'role category needs term: an existing service category (slug, name or id).', [ 'status' => 400 ] );
			}
			if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
				return new \WP_Error( 'lb_forbidden', 'You cannot edit that service category.', [ 'status' => 403 ] );
			}
			$term_id = (int) $term->term_id;
			$previous['term_page'] = (int) get_term_meta( $term_id, '_lb_page', true );
			// Other categories that claim this page lose the claim (one page = one category); recorded so it can be undone.
			$claims = get_terms( [ 'taxonomy' => Entries::CAT, 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => '_lb_page', 'meta_value' => $id ] );
			$claims = array_values( array_diff( array_map( 'intval', is_wp_error( $claims ) ? [] : (array) $claims ), [ $term_id ] ) );
			foreach ( $claims as $other ) {
				if ( ! current_user_can( 'edit_term', $other ) ) {
					return new \WP_Error( 'lb_forbidden', 'Another service category (term ' . $other . ') points at this page and you cannot edit it.', [ 'status' => 403 ] );
				}
			}
			$previous['term_claims'] = $claims;
		}
		$ok = $write_hubs( $unhub );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$meta_now = (string) get_post_meta( $id, self::META, true );
		if ( in_array( $role, self::PAGE_ROLES, true ) ) {
			if ( $meta_now !== $role ) {
				update_post_meta( $id, self::META, $role );
				$changed = true;
			}
			if ( isset( self::catmap()[ $id ] ) ) {
				$warnings[] = 'A service category points at this page (its _lb_page); the explicit role wins here, but the category still links to this page.';
			}
		} elseif ( '' !== $meta_now ) {
			delete_post_meta( $id, self::META ); // Hub, category and auto follow the derived rules, which an explicit role would override.
			$changed = true;
		}
		if ( $term_id ) {
			if ( (int) get_term_meta( $term_id, '_lb_page', true ) !== $id ) {
				update_term_meta( $term_id, '_lb_page', $id );
				$changed = true;
			}
			foreach ( $previous['term_claims'] as $other ) {
				delete_term_meta( $other, '_lb_page', $id );
				$changed = true;
			}
			if ( $previous['term_claims'] ) {
				$warnings[] = 'Removed this page from service categor' . ( 1 === count( $previous['term_claims'] ) ? 'y' : 'ies' ) . ' ' . implode( ', ', $previous['term_claims'] ) . ' (they pointed at it too); previous.term_claims lists them.';
			}
		}
		if ( $changed ) {
			Cache::purge_later();
		}
		$resolved = self::of( $id );
		if ( 'auto' !== $role && $resolved !== $role ) {
			$warnings[] = 'The page now resolves to ' . $resolved . ' (a filter or another rule decides it).';
		}
		return [ 'post_id' => $id, 'title' => get_the_title( $id ), 'role' => $role, 'resolved' => $resolved, 'term_id' => (int) ( self::catmap()[ $id ] ?? 0 ), 'changed' => $changed, 'previous' => $previous, 'snapshot' => $snap, 'warnings' => $warnings ];
	}

	public static function get_roles_tool( $input = [] ): array {
		$input = (array) $input;
		$limit = max( 1, min( 200, (int) ( $input['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$q = new \WP_Query( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'ignore_sticky_posts' => true ] );
		$pages = [];
		foreach ( $q->posts as $p ) {
			[ $role, $source ] = self::explain_page( $p->ID );
			$filtered = self::of( $p->ID );
			$pages[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'path' => get_page_uri( $p ), 'status' => $p->post_status, 'role' => $filtered, 'source' => $filtered === $role ? $source : 'filter' ];
		}
		$hub = static function ( int $id ) { return $id ? [ 'id' => $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ) ] : null; };
		$h = self::hubs();
		$conf = self::catmap_conflicts();
		$cats = [];
		foreach ( self::catmap() as $pid => $tid ) {
			$t = get_term( $tid, Entries::CAT );
			$cats[] = [ 'page_id' => $pid, 'page' => get_the_title( $pid ), 'term_id' => $tid, 'term' => $t && ! is_wp_error( $t ) ? $t->name : '', 'slug' => $t && ! is_wp_error( $t ) ? $t->slug : '', 'also_claimed_by' => array_values( array_diff( $conf[ $pid ] ?? [], [ $tid ] ) ) ];
		}
		$total = (int) $q->found_posts;
		return [
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
			'pages'       => $pages,
			'hubs'        => [ 'services' => $hub( $h['services'] ), 'areas' => $hub( $h['areas'] ) ],
			'categories'  => $cats,
			'roles'       => self::ROLES,
		];
	}

	/* ------------------------------------------------------------------ module hooks */

	/** Module hook: role summary in site-state. */
	public static function site_state( array &$state ): void {
		$counts = [];
		foreach ( (array) ( $state['pages'] ?? [] ) as $p ) {
			$r = self::of( (int) ( $p['id'] ?? 0 ) );
			$counts[ $r ] = ( $counts[ $r ] ?? 0 ) + 1;
		}
		ksort( $counts );
		$state['roles'] = [ 'hubs' => self::hubs(), 'category_pages' => count( self::catmap() ), 'pages_by_role' => $counts ];
	}

	/** Module hook: how roles work, in get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['page_roles'] = 'Every page has a ROLE that decides its hero, breadcrumbs and header/footer variant: home (front page), page, legal, services_hub, areas_hub, category (a service category landing page), service and area (entries), conversion (contact, quote, booking, landing: no breadcrumbs), thanks, post, blog, search, notfound, other. Most are automatic; set the rest with set-page-role (conversion and thanks pages, the two hubs, category pages) and check them with get-roles.';
		$pb['tools_by_job']['Page roles'] = 'get-roles, set-page-role';
	}
}
