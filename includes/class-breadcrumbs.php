<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumbs: the trail (data), where crumbs show (visible()), the one renderer (the
 * launch/breadcrumbs block and anything else that prints crumbs) and exactly one BreadcrumbList.
 *
 * Trail by role (home first, the current object last with its canonical URL; renderers do not link it):
 *   page, legal, conversion, thanks, hubs  Home > ancestors > page
 *   service    Home > services hub > primary category (Rank Math / Yoast primary, else first by name) and its parents > service
 *   area       Home > areas hub > parent areas > area
 *   category   Home > services hub > parent categories > the category page (or the category archive)
 *   post       Home > blog page > primary category (and parents) > post
 *   blog       Home > blog page > archive;  search: Home > Search results;  notfound: Home > Page not found
 *   home, other: no trail.
 * Titles honour rank_math_breadcrumb_title (posts and terms) and _yoast_wpseo_bctitle. Category links go through
 * term_link, which points lb_service_cat terms at their category page (the archive 301s there). Filter
 * launch_builder_breadcrumb_trail ( $trail, $role, $object ).
 *
 * visible(): the role is in the chrome map's crumbs roles and the page's _lb_hero is not 'none'; always true in
 * simulate (Context) and editor contexts. Every render checks it.
 *
 * Schema: schema_owner() = 'rankmath' (Rank Math's schema module on: theme support rank-math-breadcrumbs, the items
 * filter gets this trail, breadcrumbs_enabled = visible() && indexable role), 'yoast' (wpseo_breadcrumb_links +
 * wpseo_schema_needs_breadcrumb), else 'lb' (LB prints its own BreadcrumbList on indexable roles). All of it once the
 * chrome crumbs slot has been applied (until then nothing changes for SEO plugins). The Kadence title-bar crumbs are
 * switched off for the roles that show LB crumbs (at runtime) and in the Customizer when the crumbs slot is applied.
 */
final class Breadcrumbs {

	/** separator attribute => character. */
	const SEPARATORS = [ 'raquo' => '»', 'chevron' => '›', 'slash' => '/', 'arrow' => '→', 'dot' => '·', 'pipe' => '|' ];

	/** variant attribute values (contained is the default: container width, accent home icon, thin divider, soft edge). */
	const VARIANTS = [ 'contained', 'plain', 'bar', 'pill', 'underline' ];

	/** Roles whose pages are indexed (a BreadcrumbList is emitted there when crumbs show). */
	const INDEXABLE = [ 'page', 'legal', 'services_hub', 'areas_hub', 'category', 'service', 'area', 'post', 'blog', 'conversion' ];

	/** Kadence title-bar breadcrumb theme mods (pages, posts, entries, post/search/category archives). */
	const THEME_MODS = [ 'page_title_element_breadcrumb', 'post_title_element_breadcrumb', 'lb_service_title_element_breadcrumb', 'lb_area_title_element_breadcrumb', 'post_archive_title_element_breadcrumb', 'search_archive_title_element_breadcrumb', 'lb_service_archive_title_element_breadcrumb' ];

	const HOME_ICON = '<svg class="lb-crumb-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg>';

	public static function boot(): void {
		add_filter( 'term_link', [ __CLASS__, 'term_link' ], 10, 3 );
		add_action( 'template_redirect', [ __CLASS__, 'redirect_archive' ], 5 );
		add_action( 'init', [ __CLASS__, 'seo_support' ], 20 );
		add_filter( 'rank_math/frontend/breadcrumb/items', [ __CLASS__, 'rank_math_items' ], 20, 2 );
		add_filter( 'rank_math/json_ld/breadcrumbs_enabled', [ __CLASS__, 'rank_math_enabled' ], 20 );
		add_filter( 'wpseo_breadcrumb_links', [ __CLASS__, 'yoast_links' ], 20 );
		add_filter( 'wpseo_schema_needs_breadcrumb', [ __CLASS__, 'yoast_needs' ], 20 );
		add_action( 'wp_head', [ __CLASS__, 'json_ld' ], 20 );
		add_action( 'wp', [ __CLASS__, 'theme_guard' ], 98 );
	}

	/* ------------------------------------------------------------------ the trail */

	/**
	 * The trail for the current request: [{title, url}], home first, the current object last (its url is the canonical
	 * URL; renderers do not link it). Empty when there is nothing to show (front page, other).
	 *
	 * @return array<int,array{title:string,url:string}>
	 */
	public static function trail(): array {
		static $memo = [];
		[ $role, $obj ] = self::subject();
		$k = $role . '|' . ( $obj instanceof \WP_Post ? 'p' . $obj->ID : ( $obj instanceof \WP_Term ? 't' . $obj->term_id : '' ) ) . '|' . ( did_action( 'wp' ) ? get_search_query( false ) : '' );
		if ( ! isset( $memo[ $k ] ) || Context::current() ) { // The block, the JSON-LD and the SEO plugin filters ask for the same trail.
			$memo[ $k ] = (array) apply_filters( 'launch_builder_breadcrumb_trail', self::build( $role, $obj ), $role, $obj );
		}
		return $memo[ $k ];
	}

	/**
	 * What the trail is about: the role and the object. Simulation first (with its post or the role's sample), then the
	 * main query on the front end, else (editor, REST block renderer, admin) the request post_id or global post; a
	 * template post (element, synced pattern...) stands for its first _lb_roles role, shown with that role's sample.
	 *
	 * @return array{0:string,1:\WP_Post|\WP_Term|null}
	 */
	public static function subject(): array {
		$sim = Context::current();
		if ( $sim ) {
			return [ $sim['role'], $sim['post'] ? $sim['post'] : Roles::sample( $sim['role'] ) ];
		}
		if ( did_action( 'wp' ) && ! self::editor_context() ) {
			$q = get_queried_object();
			return [ Roles::current(), $q instanceof \WP_Post || $q instanceof \WP_Term ? $q : null ];
		}
		$p = self::request_post();
		if ( ! $p ) {
			return [ '', null ];
		}
		if ( in_array( $p->post_type, Roles::TEMPLATE_TYPES, true ) ) {
			$role = Roles::template_roles( (int) $p->ID )[0] ?? '';
			return [ $role, '' === $role ? null : Roles::sample( $role ) ];
		}
		return [ Roles::of( (int) $p->ID ), $p ];
	}

	/** Editor-ish requests: wp-admin and REST (the block editor's ServerSideRender). */
	public static function editor_context(): bool {
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private static function request_post(): ?\WP_Post {
		if ( isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only lookup, capability checked.
			$id = absint( wp_unslash( $_GET['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$p = $id ? get_post( $id ) : null;
			if ( $p && current_user_can( 'read_post', $id ) ) {
				return $p;
			}
		}
		$p = get_post();
		return $p instanceof \WP_Post ? $p : null;
	}

	/** @param \WP_Post|\WP_Term|null $obj */
	private static function build( string $role, $obj ): array {
		if ( '' === $role || 'home' === $role || 'other' === $role ) {
			return [];
		}
		$t = [ [ 'title' => __( 'Home', 'launch-builder' ), 'url' => home_url( '/' ) ] ];
		$post = $obj instanceof \WP_Post ? $obj : null;
		$term = $obj instanceof \WP_Term ? $obj : null;
		switch ( $role ) {
			case 'search':
				$q = did_action( 'wp' ) ? get_search_query( false ) : '';
				/* translators: %s: the search terms */
				$t[] = [ 'title' => '' !== $q ? sprintf( __( 'Search results for “%s”', 'launch-builder' ), $q ) : __( 'Search results', 'launch-builder' ), 'url' => '' !== $q ? (string) get_search_link( $q ) : '' ];
				return $t;
			case 'notfound':
				$t[] = [ 'title' => __( 'Page not found', 'launch-builder' ), 'url' => '' ];
				return $t;
			case 'blog':
				$bp = self::blog_page();
				if ( $post && $bp && (int) $post->ID === $bp->ID ) {
					$t[] = self::post_crumb( $post, true );
					return $t;
				}
				if ( $bp ) {
					$t[] = self::post_crumb( $bp );
				}
				if ( $term ) {
					$t = array_merge( $t, self::term_crumbs( $term ) );
				} elseif ( did_action( 'wp' ) && is_archive() ) {
					$t[] = [ 'title' => wp_strip_all_tags( (string) get_the_archive_title() ), 'url' => '' ];
				}
				return $t;
			case 'category':
				$hub = self::hub( 'services', $post ? (int) $post->ID : 0 );
				$hub ? $t[] = $hub : null;
				$cat = $term;
				if ( ! $cat && $post ) {
					$tid = Roles::catmap()[ (int) $post->ID ] ?? 0;
					$cat = $tid ? get_term( (int) $tid, Entries::CAT ) : null;
					$cat = $cat instanceof \WP_Term ? $cat : null;
				}
				if ( $cat ) {
					$crumbs = self::term_crumbs( $cat );
					if ( $post ) {
						array_pop( $crumbs ); // The page itself stands for its category.
					}
					$t = array_merge( $t, $crumbs );
				}
				if ( $post ) {
					$t[] = self::post_crumb( $post, true );
				}
				return $t;
			case 'service':
				$hub = self::hub( 'services' );
				$hub ? $t[] = $hub : null;
				if ( $post ) {
					$cat = self::primary_term( (int) $post->ID, Entries::CAT );
					$cat ? $t = array_merge( $t, self::term_crumbs( $cat ) ) : null;
					$t[] = self::post_crumb( $post, true );
				}
				return $t;
			case 'area':
				$hub = self::hub( 'areas' );
				$hub ? $t[] = $hub : null;
				if ( $post ) {
					$t = array_merge( $t, self::ancestor_crumbs( $post ) );
					$t[] = self::post_crumb( $post, true );
				}
				return $t;
			case 'post':
				$bp = self::blog_page();
				$bp ? $t[] = self::post_crumb( $bp ) : null;
				if ( $post ) {
					$cat = self::primary_term( (int) $post->ID, 'category' );
					$cat ? $t = array_merge( $t, self::term_crumbs( $cat ) ) : null;
					$t[] = self::post_crumb( $post, true );
				}
				return $t;
		}
		if ( $post ) { // page, legal, conversion, thanks, services_hub, areas_hub
			$t = array_merge( $t, self::ancestor_crumbs( $post ) );
			$t[] = self::post_crumb( $post, true );
		}
		return $t;
	}

	/** A post crumb; the current one gets the canonical URL. */
	private static function post_crumb( \WP_Post $p, bool $current = false ): array {
		$url = $current ? (string) ( wp_get_canonical_url( $p ) ?: get_permalink( $p ) ) : (string) get_permalink( $p );
		return [ 'title' => self::post_title( $p ), 'url' => $url ];
	}

	private static function post_title( \WP_Post $p ): string {
		foreach ( [ 'rank_math_breadcrumb_title', '_yoast_wpseo_bctitle' ] as $k ) {
			$v = trim( (string) get_post_meta( $p->ID, $k, true ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return wp_strip_all_tags( (string) get_the_title( $p ) );
	}

	/** @return array crumbs for the published ancestors of a post, top first. */
	private static function ancestor_crumbs( \WP_Post $p ): array {
		$out = [];
		foreach ( array_reverse( get_post_ancestors( $p ) ) as $aid ) {
			$a = get_post( (int) $aid );
			if ( $a && 'publish' === $a->post_status ) {
				$out[] = self::post_crumb( $a );
			}
		}
		return $out;
	}

	/** @return array crumbs for a term and its parents, top first (links go through term_link). */
	private static function term_crumbs( \WP_Term $term ): array {
		$out = [];
		$ids = array_reverse( get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' ) );
		$ids[] = (int) $term->term_id;
		foreach ( $ids as $tid ) {
			$t = get_term( (int) $tid, $term->taxonomy );
			if ( ! $t instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $t );
			$out[] = [ 'title' => self::term_title( $t ), 'url' => is_wp_error( $link ) ? '' : (string) $link ];
		}
		return $out;
	}

	private static function term_title( \WP_Term $t ): string {
		$v = trim( (string) get_term_meta( $t->term_id, 'rank_math_breadcrumb_title', true ) );
		if ( '' === $v && class_exists( '\WPSEO_Taxonomy_Meta' ) && method_exists( '\WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) {
			$v = trim( (string) \WPSEO_Taxonomy_Meta::get_term_meta( $t, $t->taxonomy, 'bctitle' ) );
		}
		return '' !== $v ? $v : wp_strip_all_tags( $t->name );
	}

	/** The primary term (Rank Math, then Yoast, if the post has it), else the post's first term by name. */
	private static function primary_term( int $post_id, string $tax ): ?\WP_Term {
		foreach ( [ 'rank_math_primary_' . $tax, '_yoast_wpseo_primary_' . $tax ] as $k ) {
			$tid = (int) get_post_meta( $post_id, $k, true );
			if ( $tid && has_term( $tid, $tax, $post_id ) ) {
				$t = get_term( $tid, $tax );
				if ( $t instanceof \WP_Term ) {
					return $t;
				}
			}
		}
		$terms = wp_get_object_terms( $post_id, $tax, [ 'orderby' => 'name', 'order' => 'ASC' ] );
		return ! is_wp_error( $terms ) && $terms && $terms[0] instanceof \WP_Term ? $terms[0] : null;
	}

	/** The services/areas hub crumb (hubs option, else the legacy /services/ or /service-areas/ page); not the page itself. */
	private static function hub( string $slot, int $self = 0 ): ?array {
		$id = (int) ( Roles::hubs()[ $slot ] ?? 0 );
		$p = $id ? get_post( $id ) : get_page_by_path( Roles::HUB_PATHS[ $slot ], OBJECT, 'page' );
		if ( ! $p instanceof \WP_Post || 'publish' !== $p->post_status || (int) $p->ID === $self ) {
			return null;
		}
		return self::post_crumb( $p );
	}

	private static function blog_page(): ?\WP_Post {
		$id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
		$p = $id ? get_post( $id ) : null;
		return $p && 'publish' === $p->post_status ? $p : null;
	}

	/* ------------------------------------------------------------------ where crumbs show */

	/**
	 * Should crumbs show in the current context? The current role is in the chrome map's crumbs roles and the page's
	 * _lb_hero is not 'none'; always true in simulate/editor contexts.
	 */
	public static function visible(): bool {
		if ( Context::current() || self::editor_context() ) {
			return true;
		}
		if ( ! Chrome::crumbs_on( Roles::current() ) ) {
			return false;
		}
		return 'none' !== Chrome::page_hero();
	}

	public static function indexable( string $role ): bool {
		return in_array( $role, self::INDEXABLE, true );
	}

	/* ------------------------------------------------------------------ the renderer */

	/**
	 * The breadcrumb nav for the current request, or ''.
	 *
	 * @param array $a block attributes: variant (contained|plain|bar|pill|underline), separator (raquo|chevron|slash|arrow|
	 *                 dot|pipe), homeLabel (text of the home crumb; '' = the home icon is the home crumb), showCurrent.
	 */
	public static function render( array $a = [] ): string {
		if ( ! self::visible() ) {
			return '';
		}
		$a = self::args( $a );
		$trail = self::trail();
		if ( ! $trail && ( self::editor_context() || Context::current() ) ) { // Something to see in the editor.
			$trail = [ [ 'title' => __( 'Home', 'launch-builder' ), 'url' => home_url( '/' ) ], [ 'title' => __( 'Parent page', 'launch-builder' ), 'url' => '#' ], [ 'title' => __( 'Current page', 'launch-builder' ), 'url' => '' ] ];
		}
		if ( ! $trail ) {
			return '';
		}
		$home = array_shift( $trail );
		$current = $a['showCurrent'] && $trail ? array_pop( $trail ) : null;
		$lead = 'contained' === $a['variant'];
		$sep = '<span class="lb-crumb-sep" aria-hidden="true">' . esc_html( self::SEPARATORS[ $a['separator'] ] ) . '</span>';
		$items = [];
		$first = true; // The first crumb after the lead (or the very first) has no separator.
		if ( '' === $a['homeLabel'] ) {
			$items[] = '<li class="lb-crumb-home-item' . ( $lead ? ' lb-crumb-lead' : '' ) . '"><a class="lb-crumb-home" href="' . esc_url( $home['url'] ) . '" aria-label="' . esc_attr__( 'Home', 'launch-builder' ) . '">' . self::HOME_ICON . '</a></li>';
			$first = $lead;
		} else {
			if ( $lead ) {
				$items[] = '<li class="lb-crumb-lead" aria-hidden="true"><span class="lb-crumb-home">' . self::HOME_ICON . '</span></li>';
			}
			$items[] = '<li><a href="' . esc_url( $home['url'] ) . '">' . esc_html( $a['homeLabel'] ) . '</a></li>';
			$first = false;
		}
		foreach ( $trail as $c ) {
			$label = esc_html( $c['title'] );
			$items[] = '<li>' . ( $first ? '' : $sep ) . ( '' !== $c['url'] ? '<a href="' . esc_url( $c['url'] ) . '">' . $label . '</a>' : '<span>' . $label . '</span>' ) . '</li>';
			$first = false;
		}
		if ( $current ) {
			$items[] = '<li aria-current="page">' . ( $first ? '' : $sep ) . '<span>' . esc_html( $current['title'] ) . '</span></li>';
		}
		return '<nav class="lb-crumbs lb-crumbs--' . esc_attr( $a['variant'] ) . ' lb-crumbs--sep-' . esc_attr( $a['separator'] ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'launch-builder' ) . '"><ol>' . implode( '', $items ) . '</ol></nav>';
	}

	/** Block attributes with defaults (block.json says the same). */
	public static function args( array $a ): array {
		$label = array_key_exists( 'homeLabel', $a ) ? trim( (string) $a['homeLabel'] ) : 'Home';
		return [
			'variant'     => in_array( $a['variant'] ?? '', self::VARIANTS, true ) ? $a['variant'] : 'contained',
			'separator'   => isset( $a['separator'], self::SEPARATORS[ $a['separator'] ] ) ? $a['separator'] : 'raquo',
			'homeLabel'   => 'Home' === $label ? __( 'Home', 'launch-builder' ) : $label,
			'showCurrent' => ! isset( $a['showCurrent'] ) || (bool) $a['showCurrent'],
		];
	}

	/* ------------------------------------------------------------------ one BreadcrumbList */

	/** Who prints the BreadcrumbList: 'rankmath' (its schema module is on), 'yoast', or 'lb'. */
	public static function schema_owner(): string {
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) && \RankMath\Helper::is_module_active( 'rich-snippet' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		return 'lb';
	}

	/** init: with Rank Math owning schema and LB crumbs applied, turn Rank Math's breadcrumb schema on (no settings change). */
	public static function seo_support(): void {
		if ( 'rankmath' === self::schema_owner() && Chrome::applied( 'crumbs' ) ) {
			add_theme_support( 'rank-math-breadcrumbs' );
		}
	}

	/** Rank Math crumbs = this trail ([name, url, hide_in_schema]); none where LB crumbs do not show. */
	public static function rank_math_items( $crumbs, $obj = null ) {
		if ( ! Chrome::applied( 'crumbs' ) ) {
			return $crumbs;
		}
		if ( ! self::visible() ) {
			return [];
		}
		return array_map( static function ( $c ) { return [ html_entity_decode( $c['title'], ENT_QUOTES, 'UTF-8' ), $c['url'], 'hide_in_schema' => false ]; }, self::trail() );
	}

	public static function rank_math_enabled( $on ) {
		return Chrome::applied( 'crumbs' ) ? self::visible() && self::indexable( Roles::current() ) : $on;
	}

	public static function yoast_links( $links ) {
		if ( ! Chrome::applied( 'crumbs' ) ) {
			return $links;
		}
		if ( ! self::visible() ) {
			return [];
		}
		return array_map( static function ( $c ) { return [ 'text' => $c['title'], 'url' => $c['url'], 'allow_html' => false ]; }, self::trail() );
	}

	public static function yoast_needs( $needs ) {
		return Chrome::applied( 'crumbs' ) ? self::visible() && self::indexable( Roles::current() ) : $needs;
	}

	/** wp_head: LB's own BreadcrumbList when no SEO plugin owns schema, crumbs show and the role is indexed. */
	public static function json_ld(): void {
		if ( 'lb' !== self::schema_owner() || ! Chrome::applied( 'crumbs' ) || is_front_page() || ! self::indexable( Roles::current() ) || ! self::visible() ) {
			return;
		}
		$trail = self::trail();
		if ( count( $trail ) < 2 ) {
			return;
		}
		$items = [];
		$pos = 1;
		foreach ( $trail as $c ) {
			if ( '' === $c['url'] ) {
				continue;
			}
			$items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => html_entity_decode( $c['title'], ENT_QUOTES, 'UTF-8' ), 'item' => $c['url'] ];
		}
		if ( count( $items ) < 2 ) {
			return;
		}
		$last = end( $trail );
		$data = [ '@context' => 'https://schema.org', '@type' => 'BreadcrumbList' ];
		if ( '' !== $last['url'] ) {
			$data['@id'] = $last['url'] . '#breadcrumb'; // An archive crumb without a URL (date, author) gets no @id rather than a bare fragment.
		}
		$data['itemListElement'] = $items;
		echo '<script type="application/ld+json" class="lb-breadcrumb-schema">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with < > & escaped.
	}

	/* ------------------------------------------------------------------ Kadence title-bar crumbs */

	/** wp (98): where LB crumbs show, Kadence's own title-bar crumbs are off whatever the Customizer says. */
	public static function theme_guard(): void {
		if ( is_admin() || ! Chrome::applied( 'crumbs' ) || ! self::visible() ) {
			return;
		}
		foreach ( self::THEME_MODS as $k ) {
			add_filter( 'theme_mod_' . $k, [ __CLASS__, 'mod_off' ] );
		}
	}

	public static function mod_off( $v ) {
		$v = is_array( $v ) ? $v : [ 'show_title' => true ];
		$v['enabled'] = false;
		return $v;
	}

	/**
	 * Applying the crumbs slot (LB crumbs on) switches Kadence's title-bar crumbs off in the Customizer (set-chrome
	 * snapshots these mods first; the Ledger row chrome-theme-crumbs records what LB set).
	 *
	 * @return array{switched_off:string[]}
	 */
	public static function theme_crumbs_off( bool $dry ): array {
		$c = Chrome::get()['crumbs'] ?? [];
		if ( empty( $c['roles'] ) ) {
			return [ 'switched_off' => [] ];
		}
		$off = [];
		foreach ( self::THEME_MODS as $k ) {
			$v = get_theme_mod( $k, null );
			if ( is_array( $v ) && ! empty( $v['enabled'] ) ) {
				$off[] = $k;
				if ( ! $dry ) {
					$v['enabled'] = false;
					set_theme_mod( $k, $v );
				}
			}
		}
		if ( $off && ! $dry ) {
			Ledger::record( 'chrome-theme-crumbs', 'theme_mod', implode( ',', self::THEME_MODS ), Ledger::hash_mods( self::THEME_MODS ), 'set' );
		}
		return [ 'switched_off' => $off ];
	}

	/* ------------------------------------------------------------------ service categories link to their page */

	/** term_link: a service category with a published category page links to that page. */
	public static function term_link( $url, $term = null, $taxonomy = '' ) {
		if ( Entries::CAT !== $taxonomy || ! $term instanceof \WP_Term ) {
			return $url;
		}
		$page = (int) get_term_meta( $term->term_id, '_lb_page', true );
		return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : $url;
	}

	/** template_redirect: the category archive 301s to the category page (one URL per category). */
	public static function redirect_archive(): void {
		if ( ! is_tax( Entries::CAT ) || is_feed() || is_preview() || is_customize_preview() ) {
			return;
		}
		$t = get_queried_object();
		$page = $t instanceof \WP_Term ? (int) get_term_meta( $t->term_id, '_lb_page', true ) : 0;
		if ( $page && 'publish' === get_post_status( $page ) ) {
			wp_safe_redirect( (string) get_permalink( $page ), 301 );
			exit;
		}
	}
}
