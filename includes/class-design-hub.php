<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Design hub (Launch Builder > Design hub, page slug launch-builder-patterns).
 *
 * Tab "Placed designs": a page type switcher (named in plain words, with the real pages of each type), the real
 * front-end page of a sample object of that type in a frame at desktop, tablet and mobile widths, and beside it every
 * chrome slot with the design that type gets: the design, the page types it covers, whether it is applied, where it lives
 * (Kadence Element, synced pattern or Launch Builder itself) and Change design, Edit design and See in library. Change
 * design opens a dialog with live previews of every design for the slot, a larger preview and the scope of the change
 * ("This will change the hero on: ..."); without JavaScript the same choice is a plain form. Breadcrumbs also say which page
 * types show them and let you change that. Changes go through Chrome::set_tool() (a snapshot is saved first) behind a
 * nonce and a capability check, and the last change can be undone. Kadence Conversions items are listed on the side.
 *
 * Tab "Pattern library": every pattern with search, groups and widths, rendered by Admin::preview_route().
 *
 * The page frame loads the real page with ?lb_hub=1&_lbh=<nonce>: for a verified administrator that only hides the admin
 * bar and keeps the response out of search engines and caches.
 */
final class Design_Hub {

	const PAGE  = 'launch-builder-patterns';
	const FRAME = 'lb_hub_frame';
	const APPLY = 'lb_hub_apply';
	const UNDO  = 'lb_hub_undo';

	/** Slots from the top of a page to the bottom. */
	const ORDER = [ 'notice', 'header', 'hero', 'crumbs', 'template', 'notfound_body', 'before_footer', 'footer', 'sticky_bar' ];

	/** Roles in the order the switcher shows them. */
	const ROLE_ORDER = [ 'home', 'page', 'service', 'area', 'category', 'services_hub', 'areas_hub', 'conversion', 'thanks', 'legal', 'post', 'blog', 'search', 'notfound' ];

	/** Page types the site check expects to have no breadcrumbs (Inspect: "crumbs_on_excluded_role"); the checklist marks them. */
	const NO_CRUMBS = [ 'home', 'conversion', 'thanks' ];

	/** Widths of the page frame (the page is rendered at this width and scaled to fit the pane). */
	const WIDTHS = [ 'desktop' => 1280, 'tablet' => 820, 'mobile' => 390 ];

	/** Most pages read when counting how many pages each page type has (a bigger site is counted up to this many and shows "200+"). */
	const CENSUS_CAP = 200;

	/** @var array<string,array> What the "Change design" dialog needs for each slot of the page type shown, collected while the cards are built. */
	private static $pick = [];

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_post_lb_hub_apply', [ __CLASS__, 'handle_apply' ] );
		add_action( 'admin_post_lb_hub_undo', [ __CLASS__, 'handle_undo' ] );
		add_action( 'init', [ __CLASS__, 'frame_mode' ], 1 );
	}

	public static function url( array $args = [] ): string {
		$u = Admin::url( self::PAGE );
		return $args ? add_query_arg( $args, $u ) : $u;
	}

	private static function is_hub(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return self::PAGE === $page;
	}

	public static function assets( $hook = '' ): void {
		if ( ! self::is_hub() ) {
			return;
		}
		wp_enqueue_script( 'lb-hub', LB_URL . 'assets/admin/lb-hub.js', [ 'lb-admin' ], LB_VERSION, true );
	}

	/** A hub frame request (?lb_hub=1&_lbh=nonce from an administrator): no admin bar, not indexed, never cached. */
	public static function frame_mode(): void {
		if ( empty( $_GET['lb_hub'] ) || empty( $_GET['_lbh'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_lbh'] ) ), self::FRAME ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		add_filter( 'show_admin_bar', '__return_false', 99 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'X-LB-Hub-Frame: 1' );
	}

	/* ------------------------------------------------------------------ names */

	/** @return array{0:string,1:string,2:string} name, what it is in plain words, the name as it reads in the middle of a sentence. */
	private static function slot_info( string $slot ): array {
		switch ( $slot ) {
			case 'notice':
				return [ __( 'Notice bar', 'launch-builder' ), __( 'A slim bar above the header, for news or an offer', 'launch-builder' ), __( 'the notice bar', 'launch-builder' ) ];
			case 'header':
				return [ __( 'Header', 'launch-builder' ), __( 'Logo, menu and call button at the very top', 'launch-builder' ), __( 'the header', 'launch-builder' ) ];
			case 'hero':
				return [ __( 'Hero', 'launch-builder' ), __( 'The big banner with the page title', 'launch-builder' ), __( 'the hero', 'launch-builder' ) ];
			case 'crumbs':
				return [ __( 'Breadcrumbs', 'launch-builder' ), __( 'The trail under the banner: Home > Services > this page', 'launch-builder' ), __( 'the breadcrumbs', 'launch-builder' ) ];
			case 'template':
				return [ __( 'Page template', 'launch-builder' ), __( 'The layout every service and area page shares', 'launch-builder' ), __( 'the page template', 'launch-builder' ) ];
			case 'notfound_body':
				return [ __( '404 content', 'launch-builder' ), __( 'What visitors see when a page is missing', 'launch-builder' ), __( 'the 404 content', 'launch-builder' ) ];
			case 'before_footer':
				return [ __( 'Before the footer', 'launch-builder' ), __( 'Sections stacked above the footer', 'launch-builder' ), __( 'the sections above the footer', 'launch-builder' ) ];
			case 'footer':
				return [ __( 'Footer', 'launch-builder' ), __( 'Contact details, links and licence at the bottom', 'launch-builder' ), __( 'the footer', 'launch-builder' ) ];
			case 'sticky_bar':
				return [ __( 'Sticky call bar', 'launch-builder' ), __( 'Call and quote buttons that stay on screen on phones', 'launch-builder' ), __( 'the sticky call bar', 'launch-builder' ) ];
		}
		return [ $slot, '', $slot ];
	}

	/**
	 * A page type in plain words: [ as a title, as it reads in the middle of a sentence ]. Roles::label() keeps the
	 * technical names the editor and the site tools use; owners think in "standard pages" and "service pages".
	 *
	 * @return array{0:string,1:string}
	 */
	private static function role_names( string $role ): array {
		switch ( $role ) {
			case 'home':
				return [ __( 'Home page', 'launch-builder' ), __( 'home page', 'launch-builder' ) ];
			case 'page':
				return [ __( 'Standard pages', 'launch-builder' ), __( 'standard pages', 'launch-builder' ) ];
			case 'legal':
				return [ __( 'Legal pages', 'launch-builder' ), __( 'legal pages', 'launch-builder' ) ];
			case 'services_hub':
				return [ __( 'Services overview page', 'launch-builder' ), __( 'services overview page', 'launch-builder' ) ];
			case 'areas_hub':
				return [ __( 'Areas overview page', 'launch-builder' ), __( 'areas overview page', 'launch-builder' ) ];
			case 'category':
				return [ __( 'Service category pages', 'launch-builder' ), __( 'service category pages', 'launch-builder' ) ];
			case 'service':
				return [ __( 'Service pages', 'launch-builder' ), __( 'service pages', 'launch-builder' ) ];
			case 'area':
				return [ __( 'Service area pages', 'launch-builder' ), __( 'service area pages', 'launch-builder' ) ];
			case 'conversion':
				return [ __( 'Contact and quote pages', 'launch-builder' ), __( 'contact and quote pages', 'launch-builder' ) ];
			case 'thanks':
				return [ __( 'Thank-you pages', 'launch-builder' ), __( 'thank-you pages', 'launch-builder' ) ];
			case 'post':
				return [ __( 'Blog posts', 'launch-builder' ), __( 'blog posts', 'launch-builder' ) ];
			case 'blog':
				return [ __( 'Blog and archive pages', 'launch-builder' ), __( 'blog and archive pages', 'launch-builder' ) ];
			case 'search':
				return [ __( 'Search results page', 'launch-builder' ), __( 'search results page', 'launch-builder' ) ];
			case 'notfound':
				return [ __( 'Page not found (404)', 'launch-builder' ), __( 'page not found (404)', 'launch-builder' ) ];
		}
		$l = Roles::label( $role );
		return [ $l, $l ];
	}

	private static function role_label( string $role ): string {
		return self::role_names( $role )[0];
	}

	private static function role_noun( string $role ): string {
		return self::role_names( $role )[1];
	}


	/* ------------------------------------------------------------------ which pages a change reaches */

	/**
	 * How many pages each page type has, the first few titles, and which pages have a hero of their own or none (a shared
	 * hero or breadcrumb change does not reach those). Only ids are read (plus the cached meta of the pages), so a big site
	 * stays cheap: pages are counted up to CENSUS_CAP and then say "200+", posts, services and areas are counted exactly.
	 * Counted once per request.
	 *
	 * Per page type: n (pages), more (n is a lower bound), items (first three: [id, title]), own / own_items (pages whose
	 * _lb_hero is "content": a hero of their own), nohero / nohero_items (_lb_hero is "none").
	 *
	 * @return array<string,array{n:int,more:bool,items:array,own:int,own_items:array,nohero:int,nohero_items:array}>
	 */
	private static function census(): array {
		static $c = null;
		if ( null !== $c ) {
			return $c;
		}
		$c = [];
		foreach ( self::roles() as $r ) {
			$c[ $r ] = [ 'n' => 0, 'more' => false, 'items' => [], 'own' => 0, 'own_items' => [], 'nohero' => 0, 'nohero_items' => [] ];
		}
		$q = new \WP_Query( [ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => self::CENSUS_CAP, 'fields' => 'ids', 'orderby' => 'menu_order title', 'order' => 'ASC', 'ignore_sticky_posts' => true, 'update_post_term_cache' => false ] );
		$ids = array_map( 'intval', (array) $q->posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids ); // One query for the page role and hero choice of every page counted.
			_prime_post_caches( $ids, false, false ); // And one for the pages themselves (the page type reads the post).
		}
		$more = (int) $q->found_posts > count( $ids );
		$hooked = (bool) has_filter( 'launch_builder_role' ); // The filter needs the whole post; without one the id is enough.
		foreach ( $c as $role => $unused ) {
			$c[ $role ]['more'] = $more && ! in_array( $role, [ 'service', 'area', 'post' ], true );
		}
		foreach ( $ids as $id ) {
			$role = $hooked ? Roles::of( $id ) : Roles::explain_page( $id )[0];
			if ( isset( $c[ $role ] ) ) {
				self::census_add( $c, $role, $id, (string) get_post_meta( $id, Chrome::HERO_META, true ) );
			}
		}
		// Entries and posts: the page type is the post type, so the count is exact and only a few rows are read.
		foreach ( [ 'service' => [ Entries::SERVICE, 'menu_order title', 'ASC' ], 'area' => [ Entries::AREA, 'menu_order title', 'ASC' ], 'post' => [ 'post', 'date', 'DESC' ] ] as $role => $t ) {
			if ( ! post_type_exists( $t[0] ) ) {
				continue;
			}
			$base = [ 'post_type' => $t[0], 'post_status' => 'publish', 'posts_per_page' => 3, 'fields' => 'ids', 'orderby' => $t[1], 'order' => $t[2], 'ignore_sticky_posts' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ];
			$c[ $role ]['n'] = (int) ( wp_count_posts( $t[0] )->publish ?? 0 );
			$c[ $role ]['items'] = array_map( 'intval', (array) ( new \WP_Query( $base + [ 'no_found_rows' => true ] ) )->posts );
			foreach ( [ 'content' => 'own', 'none' => 'nohero' ] as $val => $key ) {
				$h = new \WP_Query( $base + [ 'meta_key' => Chrome::HERO_META, 'meta_value' => $val ] );
				$c[ $role ][ $key ] = (int) $h->found_posts;
				$c[ $role ][ $key . '_items' ] = array_map( 'intval', (array) $h->posts );
			}
		}
		// Titles, for the few pages that are named on screen.
		$need = [];
		foreach ( $c as $one ) {
			foreach ( [ 'items', 'own_items', 'nohero_items' ] as $k ) {
				foreach ( $one[ $k ] as $id ) {
					$need[ (int) $id ] = true;
				}
			}
		}
		if ( $need ) {
			_prime_post_caches( array_keys( $need ), false, false );
		}
		foreach ( $c as $role => $one ) {
			foreach ( [ 'items', 'own_items', 'nohero_items' ] as $k ) {
				$c[ $role ][ $k ] = array_map( static function ( $id ) {
					$t = trim( html_entity_decode( wp_strip_all_tags( get_the_title( (int) $id ) ), ENT_QUOTES, 'UTF-8' ) );
					return [ (int) $id, '' !== $t ? $t : __( '(no title)', 'launch-builder' ) ];
				}, $one[ $k ] );
			}
		}
		return $c;
	}

	/** One page into the census of its page type (ids only: titles are looked up at the end for the few that are shown). */
	private static function census_add( array &$c, string $role, int $id, string $hero ): void {
		$c[ $role ]['n']++;
		if ( count( $c[ $role ]['items'] ) < 3 ) {
			$c[ $role ]['items'][] = $id;
		}
		$k = 'content' === $hero ? 'own' : ( 'none' === $hero ? 'nohero' : '' );
		if ( '' !== $k ) {
			$c[ $role ][ $k ]++;
			if ( count( $c[ $role ][ $k . '_items' ] ) < 3 ) {
				$c[ $role ][ $k . '_items' ][] = $id;
			}
		}
	}

	/** How many pages a page type has, as shown: "4", or "200+" when the site has more pages than were counted. */
	private static function num( string $role ): string {
		$c = self::census()[ $role ] ?? null;
		return $c ? (string) $c['n'] . ( $c['more'] ? '+' : '' ) : '0';
	}

	/** "4 pages", "12 posts", "200+ pages". */
	private static function count_text( string $role ): string {
		$c = self::census()[ $role ] ?? null;
		$n = $c ? (int) $c['n'] : 0;
		if ( $c && $c['more'] ) {
			return sprintf( /* translators: %s: number of pages with a plus sign, such as 200+ */ __( '%s pages', 'launch-builder' ), self::num( $role ) );
		}
		if ( 'post' === $role ) {
			return sprintf( /* translators: %d: number of blog posts */ _n( '%d post', '%d posts', $n, 'launch-builder' ), $n );
		}
		return sprintf( /* translators: %d: number of pages */ _n( '%d page', '%d pages', $n, 'launch-builder' ), $n );
	}

	/** "+2 more" for the pages a short list leaves out, "and more" when the site has more pages than were counted. */
	private static function more_text( string $role, int $shown ): string {
		$c = self::census()[ $role ] ?? null;
		if ( ! $c ) {
			return '';
		}
		if ( $c['more'] ) {
			return __( 'and more', 'launch-builder' );
		}
		return $c['n'] > $shown ? sprintf( /* translators: %d: how many more pages there are */ __( '+%d more', 'launch-builder' ), $c['n'] - $shown ) : '';
	}

	/** The real titles of a page type: "About, Our Team, FAQ +1 more" (plain text, '' when it has none). */
	private static function examples( string $role ): string {
		$c = self::census()[ $role ] ?? null;
		if ( ! $c || ! $c['items'] ) {
			return '';
		}
		$t = implode( ', ', array_map( static function ( $i ) { return $i[1]; }, $c['items'] ) );
		$more = self::more_text( $role, count( $c['items'] ) );
		return '' !== $more ? $t . ' ' . $more : $t;
	}

	/**
	 * One page type with its real pages: "Standard pages: About, Our Team, FAQ +1 more (4 pages)". Plain text: escape it
	 * where it is printed.
	 */
	private static function scope_one( string $role, bool $examples ): string {
		$name = self::role_label( $role );
		$n = (int) ( self::census()[ $role ]['n'] ?? 0 );
		if ( 0 === $n ) {
			// The home page, the blog, search, 404 and category archives exist without a page of their own. On a site with more
			// pages than were counted, none found does not mean none.
			return in_array( $role, [ 'home', 'blog', 'search', 'notfound', 'category' ], true ) || ! empty( self::census()[ $role ]['more'] ) ? $name : sprintf( /* translators: %s: page type such as Legal pages */ __( '%s (none yet)', 'launch-builder' ), $name );
		}
		$ex = $examples ? self::examples( $role ) : '';
		return $name . ( '' !== $ex ? ': ' . $ex : '' ) . ' (' . self::count_text( $role ) . ')';
	}

	/**
	 * Page types and their pages as one line. One page type gets its real titles; several get a short list (the first
	 * four, then "and 3 more page types (12 pages)"). Plain text.
	 *
	 * @param string[] $roles
	 */
	private static function roles_text( array $roles, bool $examples ): string {
		$roles = array_values( $roles );
		if ( ! $roles ) {
			return '';
		}
		if ( 1 === count( $roles ) ) {
			return self::scope_one( $roles[0], $examples );
		}
		$parts = [];
		$more = 0;
		$more_pages = 0;
		$lower = false;
		foreach ( $roles as $i => $r ) {
			if ( $i < 4 ) {
				$parts[] = self::scope_one( $r, false );
			} else {
				$more++;
				$more_pages += (int) ( self::census()[ $r ]['n'] ?? 0 );
				$lower = $lower || ! empty( self::census()[ $r ]['more'] );
			}
		}
		$text = implode( ', ', $parts );
		if ( $more ) {
			$pages = $lower ? sprintf( /* translators: %d: number of pages (at least) */ __( '%d+ pages', 'launch-builder' ), $more_pages ) : sprintf( /* translators: %d: number of pages */ _n( '%d page', '%d pages', $more_pages, 'launch-builder' ), $more_pages );
			$text .= ' ' . sprintf( /* translators: %d: number of other page types */ _n( 'and %d more page type', 'and %d more page types', $more, 'launch-builder' ), $more ) . ( $more_pages ? ' (' . $pages . ')' : '' );
		}
		return $text;
	}

	/**
	 * The pages of $roles whose _lb_hero is $key ('own' or 'nohero'): how many (a lower bound when $more), the first three
	 * titles, and whether the count is a lower bound.
	 *
	 * @param string[] $roles
	 * @return array{0:int,1:string[],2:bool}
	 */
	private static function hero_pages( string $key, array $roles ): array {
		$n = 0;
		$names = [];
		$more = false;
		foreach ( $roles as $r ) {
			$c = self::census()[ $r ] ?? null;
			if ( $c && $c[ $key ] ) {
				$n += $c[ $key ];
				$more = $more || $c['more'];
				foreach ( $c[ $key . '_items' ] as $i ) {
					if ( count( $names ) < 3 ) {
						$names[] = $i[1];
					}
				}
			}
		}
		return [ $n, $names, $more ];
	}

	/** Titles for a sentence: "About, Our Team, FAQ +2 more". @param string[] $names */
	private static function who( int $n, array $names, bool $more ): string {
		$rest = $n - count( $names );
		return implode( ', ', $names ) . ( $more ? ' ' . __( 'and more', 'launch-builder' ) : ( $rest > 0 ? ' ' . sprintf( /* translators: %d: how many more pages there are */ __( '+%d more', 'launch-builder' ), $rest ) : '' ) );
	}

	/**
	 * Pages that are set apart from the shared hero ('' when there are none, or for a slot this does not touch): the ones
	 * with a hero of their own, where the shared hero and the shared breadcrumbs step aside, and the ones set to show no
	 * hero (and so no breadcrumbs). @param string[] $roles
	 */
	private static function own_text( string $slot, array $roles ): string {
		if ( ! in_array( $slot, [ 'hero', 'crumbs' ], true ) ) {
			return '';
		}
		$out = [];
		[ $n, $names, $more ] = self::hero_pages( 'own', $roles );
		if ( $n ) {
			$num = $n . ( $more ? '+' : '' );
			$who = self::who( $n, $names, $more );
			$out[] = 'crumbs' === $slot
				? sprintf( /* translators: 1: number of pages, 2: their titles */ _n( '%1$s page has a hero of its own (%2$s), so it gets no shared breadcrumbs.', '%1$s pages have a hero of their own (%2$s), so they get no shared breadcrumbs.', $n, 'launch-builder' ), $num, $who )
				: sprintf( /* translators: 1: number of pages, 2: their titles */ _n( '%1$s page has a hero of its own (%2$s), so the shared one steps aside.', '%1$s pages have a hero of their own (%2$s), so the shared one steps aside.', $n, 'launch-builder' ), $num, $who );
		}
		[ $n, $names, $more ] = self::hero_pages( 'nohero', $roles );
		if ( $n ) {
			$num = $n . ( $more ? '+' : '' );
			$who = self::who( $n, $names, $more );
			$out[] = 'crumbs' === $slot
				? sprintf( /* translators: 1: number of pages, 2: their titles */ _n( '%1$s page shows no hero and no breadcrumbs (%2$s).', '%1$s pages show no hero and no breadcrumbs (%2$s).', $n, 'launch-builder' ), $num, $who )
				: sprintf( /* translators: 1: number of pages, 2: their titles */ _n( '%1$s page shows no hero (%2$s).', '%1$s pages show no hero (%2$s).', $n, 'launch-builder' ), $num, $who );
		}
		return implode( ' ', $out );
	}

	/** "This will change the hero on: Standard pages: About, Our Team, FAQ +1 more (4 pages)." Plain text. @param string[] $roles */
	private static function sentence( string $slot, array $roles ): string {
		$noun = self::slot_info( $slot )[2];
		if ( 'notice' === $slot ) {
			return sprintf( /* translators: %s: part of the page such as "the notice bar" */ __( 'This will change %s on every page of your site.', 'launch-builder' ), $noun );
		}
		return sprintf( /* translators: 1: part of the page such as "the hero", 2: page types and their pages */ __( 'This will change %1$s on: %2$s.', 'launch-builder' ), $noun, self::roles_text( $roles, true ) );
	}

	/**
	 * What a change of one slot for a page type can reach, narrowest first. A page type with its own design for the slot
	 * has one choice; one that follows the default also has "every page type that follows the default". Slots that are
	 * one design for a fixed set of page types (breadcrumbs, 404) or the whole site (notice bar) have one choice too.
	 *
	 * @return array<int,array{v:string,label:string,detail:string,sentence:string,own:string,roles:string[]}>
	 */
	private static function scope_options( string $slot, string $role ): array {
		$mine = self::affected( $slot, $role, 'role' );
		$out = [];
		$add = static function ( string $v, string $label, array $roles, bool $examples ) use ( $slot, &$out ) {
			$out[] = [ 'v' => $v, 'label' => $label, 'detail' => self::roles_text( $roles, $examples ), 'sentence' => self::sentence( $slot, $roles ), 'own' => self::own_text( $slot, $roles ), 'roles' => $roles ];
		};
		if ( ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$add( 'role', '', $mine, 'crumbs' !== $slot );
			return $out;
		}
		$add( 'role', __( 'Only this page type', 'launch-builder' ), $mine, true );
		if ( self::follows_default( $slot, $role ) ) {
			$all = self::affected( $slot, $role, 'default' );
			if ( count( $all ) > count( $mine ) ) {
				$add( 'default', __( 'Every page type that follows the default design', 'launch-builder' ), $all, false );
			}
		}
		return $out;
	}

	/** Icons for the slots (24 grid strokes, like Dashboard::icon()). */
	private static function icon( string $slot ): string {
		$p = [
			'notice'        => '<path d="M4 9.5v5h3l5.5 4v-13L7 9.5z"/><path d="M16.5 9a4 4 0 0 1 0 6"/>',
			'header'        => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M7 7h3"/>',
			'hero'          => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M7.5 11h7M7.5 14.5h4.5"/>',
			'crumbs'        => '<path d="M4 12h3M17 12h3"/><path d="M10 8.5l3 3.5-3 3.5"/><circle cx="9" cy="12" r="0.1"/>',
			'template'      => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M9.5 9.5v10"/>',
			'notfound_body' => '<circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/>',
			'before_footer' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 12.5h17M3.5 16h17"/>',
			'footer'        => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 15h17M7 17.5h4"/>',
			'sticky_bar'    => '<rect x="7" y="3.5" width="10" height="17" rx="2.5"/><path d="M10 17.5h4"/>',
			'edit'          => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 7.5l3 3"/>',
			'library'       => '<rect x="3.5" y="4.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="4.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
			'swap'          => '<path d="M5 8h13M14 4l4 4-4 4M19 16H6M10 12l-4 4 4 4"/>',
			'reload'        => '<path d="M19 12a7 7 0 1 1-2.2-5.1M19 4.5V8h-3.5"/>',
			'open'          => '<path d="M14 4h6v6M20 4l-8 8M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
			'popup'         => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M15.5 8.5l2 2M17.5 8.5l-2 2"/><path d="M7 14h6"/>',
			'chevron'       => '<path d="M7 10l5 5 5-5"/>',
			'pages'         => '<rect x="6.5" y="3.5" width="12" height="14" rx="2"/><path d="M4 7.5V18a2.5 2.5 0 0 0 2.5 2.5H15M10 8.5h5M10 12h5"/>',
			'close'         => '<path d="M6 6l12 12M18 6L6 18"/>',
			'check'         => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'back'          => '<path d="M15 5l-7 7 7 7"/>',
			'help'          => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.6a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1.1.9-1.1 1.8M12 16.6v.01"/>',
		];
		return '<svg class="lb-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $p[ $slot ] ?? $p['hero'] ) . '</svg>'; // phpcs:ignore -- static markup.
	}

	private static function brand_mark(): string {
		return '<span class="lb-mark" aria-hidden="true"><svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1.5a6 6 0 0 0-6 6c0 4.2 6 11 6 11s6-6.8 6-11a6 6 0 0 0-6-6zm0 3.2l3.2 3.7h-2.1v3.1H8.9V8.4H6.8z"/></svg></span>';
	}

	private static function button( string $label, string $url, string $kind = '', string $icon = '' ): string {
		return '<a class="lb-btn' . ( '' !== $kind ? ' lb-btn--' . esc_attr( $kind ) : '' ) . '" href="' . esc_url( $url ) . '">' . ( '' !== $icon ? Dashboard::icon( $icon ) : '' ) . esc_html( $label ) . '</a>';
	}

	/* ------------------------------------------------------------------ request */

	private static function tab(): string {
		$t = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'library' === $t || 'placed' === $t ) {
			return $t;
		}
		return isset( $_GET['p'] ) ? 'library' : 'placed'; // phpcs:ignore WordPress.Security.NonceVerification -- links made before the hub existed (&p=<pattern>) open the library.
	}

	/** @return string[] */
	private static function roles(): array {
		return array_values( array_intersect( self::ROLE_ORDER, Chrome::roles() ) );
	}

	private static function role(): string {
		$r = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $r, self::roles(), true ) ? $r : 'home';
	}

	/* ------------------------------------------------------------------ the page */

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = self::tab();
		$role = self::role();
		self::$pick = [];
		$out = '<div class="wrap lb-wrap lb-hub">';
		$out .= '<header class="lb-top"><div class="lb-brand">' . self::brand_mark() . '<div><h1>' . esc_html__( 'Design hub', 'launch-builder' ) . '</h1><p class="lb-tag">' . esc_html__( 'See the shared designs on your real pages, preview another one, and switch with a click.', 'launch-builder' ) . '</p></div></div><div class="lb-actions">' . self::button( __( 'Site health', 'launch-builder' ), Admin::url( 'launch-builder-health' ), '', 'shield' ) . self::button( __( 'Business info', 'launch-builder' ), Admin::url( 'launch-builder-business' ), '', 'business' ) . '</div></header><hr class="wp-header-end">';
		$out .= self::tabs( $tab, $role );
		$out .= self::flash();
		$out .= '<div class="lb-hub-panel" id="lb-hub-placed" role="tabpanel" aria-labelledby="lb-tab-placed"' . ( 'placed' === $tab ? '' : ' hidden' ) . '>' . self::panel_placed( $role, 'placed' === $tab ) . '</div>';
		$out .= '<div class="lb-hub-panel" id="lb-hub-library" role="tabpanel" aria-labelledby="lb-tab-library"' . ( 'library' === $tab ? '' : ' hidden' ) . '>' . self::panel_library() . '</div>';
		$out .= self::modal( $role );
		$out .= '</div>';
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- every dynamic part is escaped where it is built.
		self::config( $tab, $role );
	}

	/**
	 * The script's settings. The preview nonce is written as n="..." (tools that drive the preview route read it there).
	 * The designs the Change design dialog lists are printed as JSON in their own script tag.
	 */
	private static function config( string $tab, string $role ): void {
		$t = [
			'rendering'  => __( 'Rendering this group (the first view takes a few seconds, later views are instant)…', 'launch-builder' ),
			'loading'    => __( 'Loading the page…', 'launch-builder' ),
			'use_with'   => __( 'Use it with: apply-pattern slug', 'launch-builder' ),
			'all_hint'   => __( 'Every pattern in this group, one after another. Click a pattern name on the left to jump to it.', 'launch-builder' ),
			'all_suffix' => __( '(all)', 'launch-builder' ),
			'busy'       => __( 'Working…', 'launch-builder' ),
			'in_use'     => __( 'In use:', 'launch-builder' ),
			'see_live'   => __( 'See it on a page', 'launch-builder' ),
			'pk_in_use'  => __( 'In use', 'launch-builder' ),
			'pk_same'    => __( 'This design is already in use here. Pick another one.', 'launch-builder' ),
			'pk_redo'    => __( 'This design is chosen already, but your site is not showing it yet. Apply places it.', 'launch-builder' ),
			'pk_apply'   => __( 'Apply', 'launch-builder' ),
			'pk_nopv_big' => __( 'This preview could not be shown here. You can still apply the design and look at your live page: you can undo the change with one click.', 'launch-builder' ),
			'pk_first'   => __( 'Pick a design first.', 'launch-builder' ),
			'pk_empty'   => __( 'There are no other designs for this part yet.', 'launch-builder' ),
			'pk_nopv'    => __( 'Preview not available', 'launch-builder' ),
		];
		echo '<script>window.lbHub=(function(){var base=' . wp_json_encode( home_url( '/' ) ) . ',n=' . wp_json_encode( wp_create_nonce( 'lb_preview' ) ) . ';return {base:base,n:n,tab:' . wp_json_encode( $tab ) . ',role:' . wp_json_encode( $role ) . ',url:' . wp_json_encode( self::url() ) . ',t:' . wp_json_encode( $t ) . '};})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$pick = [ 'role' => $role, 'roleName' => self::role_label( $role ), 'check' => self::icon( 'check' ), 'slots' => self::$pick ];
		echo '<script type="application/json" id="lb-hub-pick">' . wp_json_encode( $pick, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with the tag characters escaped.
	}

	/**
	 * The Change design dialog: one shell for every slot, filled by lb-hub.js from the JSON in #lb-hub-pick. A modal
	 * dialog element (role dialog, aria-modal, labelled and described), so focus stays inside it and Escape closes it; the
	 * script moves focus in and back. It is a form that posts to the same admin-post handler, with the same nonce, as the
	 * plain form on each card.
	 */
	private static function modal( string $role ): string {
		$out = '<dialog id="lb-modal" class="lb-modal" role="dialog" aria-modal="true" aria-labelledby="lb-modal-title" aria-describedby="lb-modal-sub" data-view="list">';
		$out .= '<form class="lb-modal__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'change', '', $role ) . '<input type="hidden" name="design" value="">';
		$out .= '<header class="lb-modal__head"><div class="lb-modal__titles"><h2 id="lb-modal-title" tabindex="-1" data-lb-pk-title>' . esc_html__( 'Change design', 'launch-builder' ) . '</h2><p id="lb-modal-sub" data-lb-pk-sub></p></div>';
		$out .= '<button type="button" class="lb-iconbtn lb-iconbtn--lg" data-lb-pk-close><span class="screen-reader-text">' . esc_html__( 'Close', 'launch-builder' ) . '</span>' . self::icon( 'close' ) . '</button></header>';
		$out .= '<div class="lb-modal__body"><div class="lb-modal__list" data-lb-pk-list role="region" aria-label="' . esc_attr__( 'Designs', 'launch-builder' ) . '"></div>';
		$out .= '<div class="lb-modal__detail" data-lb-pk-detail>';
		$out .= '<button type="button" class="lb-link lb-modal__back" data-lb-pk-back>' . self::icon( 'back' ) . esc_html__( 'All designs', 'launch-builder' ) . '</button>';
		$out .= '<p class="lb-modal__hint" data-lb-pk-hint>' . esc_html__( 'Pick a design to see it larger.', 'launch-builder' ) . '</p>';
		$out .= '<div class="lb-modal__picked" data-lb-pk-picked hidden>';
		$out .= self::stage( [
			'id'      => 'lb-stage-modal',
			'title'   => __( 'Design preview', 'launch-builder' ),
			'open'    => '#',
			'default' => self::WIDTHS['desktop'],
			'widths'  => [ __( 'Desktop', 'launch-builder' ) => self::WIDTHS['desktop'], __( 'Tablet', 'launch-builder' ) => self::WIDTHS['tablet'], __( 'Phone', 'launch-builder' ) => self::WIDTHS['mobile'] ],
			'loading' => __( 'Loading the preview…', 'launch-builder' ),
		] );
		$out .= '<div class="lb-modal__nopv" data-lb-pk-nopv hidden></div>';
		$out .= '<div class="lb-modal__info"><h3 data-lb-pk-name></h3><p data-lb-pk-desc></p><p class="lb-note" data-lb-pk-for></p></div>';
		$out .= '<fieldset class="lb-radios lb-modal__scope" data-lb-pk-scope hidden><legend>' . esc_html__( 'How far should this change go?', 'launch-builder' ) . '</legend><div data-lb-pk-scope-list></div></fieldset>';
		$out .= '<p class="lb-note lb-modal__extra" data-lb-pk-own></p><p class="lb-note lb-modal__extra" data-lb-pk-replaces></p>';
		$out .= '</div></div></div>';
		$out .= '<footer class="lb-modal__foot"><p class="lb-willchange lb-modal__will" data-lb-pk-sentence role="status" aria-live="polite"></p><p class="lb-note">' . esc_html__( 'A snapshot is saved first, and you can undo the change right afterwards.', 'launch-builder' ) . '</p><div class="lb-modal__acts"><button type="button" class="lb-btn" data-lb-pk-close>' . esc_html__( 'Cancel', 'launch-builder' ) . '</button><button type="submit" class="lb-btn lb-btn--primary" data-lb-pk-apply data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '" disabled>' . esc_html__( 'Apply this design', 'launch-builder' ) . '</button></div></footer>';
		return $out . '</form></dialog>';
	}

	private static function tabs( string $tab, string $role ): string {
		$out = '<div class="lb-hub-tabs" role="tablist" aria-label="' . esc_attr__( 'Design hub sections', 'launch-builder' ) . '">';
		foreach ( [ 'placed' => __( 'Placed designs', 'launch-builder' ), 'library' => __( 'Pattern library', 'launch-builder' ) ] as $k => $label ) {
			$args = [ 'tab' => $k ];
			if ( 'placed' === $k ) {
				$args['role'] = $role;
			}
			$out .= '<a href="' . esc_url( self::url( $args ) ) . '" role="tab" id="lb-tab-' . esc_attr( $k ) . '" aria-controls="lb-hub-' . esc_attr( $k ) . '" aria-selected="' . ( $k === $tab ? 'true' : 'false' ) . '" data-lb-hub-tab="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</a>';
		}
		return $out . '</div>';
	}

	/* ------------------------------------------------------------------ messages (one per redirect) */

	private static function flash_key(): string {
		return 'lb_hub_flash_' . get_current_user_id();
	}

	private static function undo_key(): string {
		return 'lb_hub_undo_' . get_current_user_id();
	}

	private static function set_flash( string $kind, string $message, bool $undo = false ): void {
		set_transient( self::flash_key(), [ 'kind' => $kind, 'message' => $message, 'undo' => $undo ], 10 * MINUTE_IN_SECONDS );
	}

	private static function flash(): string {
		$f = get_transient( self::flash_key() );
		if ( ! is_array( $f ) || empty( $f['message'] ) ) {
			return '';
		}
		delete_transient( self::flash_key() );
		$kind = in_array( $f['kind'] ?? '', [ 'ok', 'warn', 'bad' ], true ) ? $f['kind'] : 'ok';
		$undo = ! empty( $f['undo'] ) ? get_transient( self::undo_key() ) : false;
		$out = '<div class="lb-flash lb-flash--' . esc_attr( $kind ) . '" role="status"><p>' . esc_html( (string) $f['message'] ) . '</p>';
		if ( 'bad' !== $kind && is_array( $undo ) ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_hub_undo"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::UNDO ) ) . '"><input type="hidden" name="role" value="' . esc_attr( (string) ( $undo['role'] ?? '' ) ) . '"><button type="submit" class="lb-btn lb-btn--sm" data-busy="' . esc_attr__( 'Undoing…', 'launch-builder' ) . '">' . esc_html__( 'Undo this change', 'launch-builder' ) . '</button></form>';
		}
		return $out . '</div>';
	}

	/* ------------------------------------------------------------------ tab 1: placed designs */

	private static function panel_placed( string $role, bool $active ): string {
		$sample = self::sample( $role );
		$gc = Chrome::get_tool( [ 'roles' => false ] );
		$out = self::role_switcher( $role );
		$out .= self::scope_callout( $role );
		$out .= '<div class="lb-hub-cols"><div class="lb-hub-main">' . self::stage_panel( $role, $sample, $active ) . '</div>';
		$out .= '<div class="lb-hub-side">' . self::slots_panel( $role, $sample, $gc ) . self::conversions_panel( $role ) . '</div></div>';
		$out .= self::mode_bar();
		return $out;
	}

	private static function mode_bar(): string {
		$mode = Chrome::mode();
		if ( 'elements' === $mode ) {
			$chip = '<span class="lb-chip lb-chip--ok">' . esc_html__( 'Kadence Elements', 'launch-builder' ) . '</span>';
			$text = __( 'Designs are placed as Kadence Elements, each holding a synced pattern, so one edit reaches every page that uses it.', 'launch-builder' );
		} else {
			if ( ! post_type_exists( 'kadence_element' ) ) {
				$why = __( 'Kadence Pro Elements are not active, so Launch Builder places the designs itself. Edit a design through its synced pattern; nothing is lost when Elements are switched on later.', 'launch-builder' );
			} elseif ( 'patterns' === get_option( Chrome::OPT_MODE, 'auto' ) ) {
				$why = __( 'Placement is set to patterns, so Launch Builder places the designs itself even though Kadence Elements are available.', 'launch-builder' );
			} else {
				$why = __( 'The Elements module of Kadence Pro is off, so Launch Builder places the designs itself. Edit a design through its synced pattern.', 'launch-builder' );
			}
			$chip = '<span class="lb-chip lb-chip--plain">' . esc_html__( 'Patterns mode', 'launch-builder' ) . '</span>';
			$text = $why;
		}
		return '<div class="lb-modebar">' . $chip . '<p>' . esc_html( $text ) . '</p></div>';
	}

	private static function role_switcher( string $current ): string {
		$out = '<div class="lb-rolebar"><p class="lb-eyebrow" id="lb-roles-h">' . esc_html__( 'Choose a page type', 'launch-builder' ) . '</p><nav class="lb-roles" aria-labelledby="lb-roles-h">';
		foreach ( self::roles() as $r ) {
			$n = (int) ( self::census()[ $r ]['n'] ?? 0 );
			$out .= '<a class="lb-role" href="' . esc_url( self::url( [ 'tab' => 'placed', 'role' => $r ] ) ) . '"' . ( $r === $current ? ' aria-current="page"' : '' ) . ' data-role="' . esc_attr( $r ) . '" title="' . esc_attr( self::scope_one( $r, true ) ) . '">' . esc_html( self::role_label( $r ) ) . ( $n > 1 ? '<span class="lb-role__n" aria-hidden="true">' . esc_html( self::num( $r ) ) . '</span>' : '' ) . '</a>';
		}
		return $out . '</nav></div>';
	}

	/**
	 * Says which pages the designs on screen belong to, with their real titles, and how to make one page different. A design
	 * belongs to a page type, so a change reaches every page of it: that has to be impossible to miss.
	 */
	private static function scope_callout( string $role ): string {
		$c = self::census()[ $role ] ?? [ 'n' => 0, 'more' => false, 'items' => [] ];
		$n = (int) $c['n'];
		$single = in_array( $role, [ 'home', 'search', 'notfound' ], true );
		$out = '<section class="lb-scope" aria-labelledby="lb-scope-h"><span class="lb-scope__icon">' . self::icon( 'pages' ) . '</span><div class="lb-scope__body">';
		$out .= '<p class="lb-eyebrow">' . esc_html__( 'You are looking at', 'launch-builder' ) . '</p><h2 id="lb-scope-h">' . esc_html( self::role_label( $role ) ) . '</h2>';
		if ( $c['items'] ) {
			$out .= '<p class="lb-scope__pages">';
			foreach ( $c['items'] as $i ) {
				$edit = self::edit_link( (int) $i[0] );
				$out .= '' !== $edit ? '<a class="lb-pill" href="' . esc_url( $edit ) . '" title="' . esc_attr__( 'Open this page in the editor', 'launch-builder' ) . '">' . esc_html( $i[1] ) . '</a>' : '<span class="lb-pill">' . esc_html( $i[1] ) . '</span>';
			}
			$rest = self::more_text( $role, count( $c['items'] ) );
			if ( '' !== $rest ) {
				$out .= '<span class="lb-pill lb-pill--more">' . esc_html( $rest ) . '</span>';
			}
			$out .= '<span class="lb-scope__n">' . esc_html( self::count_text( $role ) ) . '</span></p>';
		}
		if ( $single ) {
			$rule = __( 'This is a single page, so a change here changes just that page.', 'launch-builder' );
		} elseif ( 0 === $n && empty( $c['more'] ) ) {
			$rule = __( 'There are no pages of this type yet. The designs below are ready for the first one you add.', 'launch-builder' );
		} elseif ( 1 === $n && empty( $c['more'] ) ) {
			$rule = __( 'Only one page has this type right now. Pages of this type that you add later will use the same designs.', 'launch-builder' );
		} elseif ( ! empty( $c['more'] ) ) {
			$rule = __( 'Every page of this type shares the same designs. Change one here and it changes on all of them.', 'launch-builder' );
		} else {
			$rule = sprintf( /* translators: %d: number of pages */ __( 'Every page of this type shares the same designs. Change one here and it changes on all %d of them.', 'launch-builder' ), $n );
		}
		$out .= '<p class="lb-scope__rule">' . esc_html( $rule ) . '</p>';
		$help = self::one_page_help( $role );
		if ( '' !== $help ) {
			$out .= '<details class="lb-scope__help"><summary>' . self::icon( 'help' ) . esc_html__( 'How do I change just one page?', 'launch-builder' ) . '</summary>' . $help . '</details>';
		}
		return $out . '</div></section>';
	}

	/**
	 * How to make a single page different, in the words of the editor sidebar (see Roles::editor_panel()). HTML, escaped.
	 * '' when there is no page of the type to change (search, 404, a type with no page, a blog without a posts page).
	 */
	private static function one_page_help( string $role ): string {
		$c = self::census()[ $role ] ?? null;
		if ( in_array( $role, [ 'search', 'notfound', 'blog' ], true ) || ! $c || // The posts page and its archives share one design; an own hero is not read there.
			 ( ! $c['n'] && ! $c['more'] ) ) {
			return '';
		}
		$entry = in_array( $role, [ 'service', 'area', 'post' ], true ); // Edited in the block editor, whose panel is "Shared hero"; there is no Page role choice.
		$fixed = in_array( $role, [ 'home', 'blog' ], true ); // The front page and the posts page: their role is locked in the editor.
		$out = '<div class="lb-scope__steps"><p>' . esc_html( $fixed ? __( 'This page type is one page, so a change here changes just that page. To give the page a hero of its own instead:', 'launch-builder' ) : __( 'A design belongs to a page type, not to one page. That is why changing it for a type changes every page of that type.', 'launch-builder' ) ) . '</p><ol>';
		$open = $entry
			? sprintf( /* translators: 1: name of the sidebar panel in the block editor, 2: the choice to make */ __( 'Open it in the block editor. In the sidebar, find the %1$s panel and set “Hero on this page” to “%2$s”.', 'launch-builder' ), __( 'Shared hero', 'launch-builder' ), __( 'Its own hero', 'launch-builder' ) )
			: sprintf( /* translators: 1: name of the sidebar box in the editor, 2: the choice to make */ __( 'Open it in the editor. In the sidebar, find the %1$s box and set “Hero on this page” to “%2$s”.', 'launch-builder' ), __( 'Page role', 'launch-builder' ), __( 'Its own hero', 'launch-builder' ) );
		$out .= '<li><b>' . esc_html__( 'Give that page its own hero.', 'launch-builder' ) . '</b> ' . esc_html( $open . ' ' . __( 'The shared hero and breadcrumbs step aside on this page. Add any hero from Patterns > Launch Builder, or choose “No hero”.', 'launch-builder' ) ) . '</li>';
		if ( ! $entry && ! $fixed ) {
			// The names the hub uses; the editor's own list says "Conversion page" for the contact and quote pages.
			$norm = static function ( $x ) {
				return rtrim( strtolower( $x ), 's' );
			};
			$names = [];
			foreach ( Roles::PAGE_ROLES as $pr ) {
				$hub = self::role_label( $pr );
				$ed = Roles::label( $pr );
				$names[] = $norm( $hub ) === $norm( $ed ) ? $hub : sprintf( /* translators: 1: page type as named in the hub, 2: its name in the editor */ __( '%1$s (“%2$s” in the editor)', 'launch-builder' ), $hub, $ed );
			}
			$out .= '<li><b>' . esc_html__( 'Or give it another page type.', 'launch-builder' ) . '</b> ' . esc_html( sprintf( /* translators: 1: name of the sidebar box, 2: the page types the editor offers */ __( 'In the same %1$s box, choose another role for the page: %2$s. It then follows the designs you pick for that type here.', 'launch-builder' ), __( 'Page role', 'launch-builder' ), wp_sprintf( '%l', $names ) ) ) . '</li>';
		}
		return $out . '</ol></div>';
	}

	/* ---- the page of a role ---- */

	/**
	 * What to show for a role: the real page of a sample object. url '' when there is none (hint says how to make one).
	 *
	 * @return array{url:string,label:string,post:?\WP_Post,hint:string,hint_url:string,hint_cta:string}
	 */
	private static function sample( string $role ): array {
		$s = [ 'url' => '', 'label' => '', 'post' => null, 'hint' => '', 'hint_url' => '', 'hint_cta' => '' ];
		$post = Roles::sample( $role );
		switch ( $role ) {
			case 'home':
				$front = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
				$s['url'] = home_url( '/' );
				$s['post'] = $front instanceof \WP_Post ? $front : null;
				$s['label'] = __( 'Your home page', 'launch-builder' );
				return $s;
			case 'search':
				$s['url'] = add_query_arg( 's', _x( 'service', 'sample search word', 'launch-builder' ), home_url( '/' ) );
				$s['label'] = __( 'A search on your site', 'launch-builder' );
				return $s;
			case 'notfound':
				$s['url'] = home_url( '/lb-sample-not-found/' );
				$s['label'] = __( 'An address that does not exist', 'launch-builder' );
				return $s;
			case 'blog':
				if ( ! $post ) {
					$cat = (int) get_option( 'default_category' );
					$link = $cat ? get_category_link( $cat ) : '';
					if ( $link && ! is_wp_error( $link ) ) {
						$s['url'] = (string) $link;
						$s['label'] = __( 'A blog category archive', 'launch-builder' );
						return $s;
					}
				}
				break;
			case 'category':
				if ( ! $post && taxonomy_exists( Entries::CAT ) ) {
					$terms = get_terms( [ 'taxonomy' => Entries::CAT, 'hide_empty' => false, 'number' => 1 ] );
					$link = is_array( $terms ) && $terms ? get_term_link( $terms[0] ) : '';
					if ( $link && ! is_wp_error( $link ) ) {
						$s['url'] = (string) $link;
						$s['label'] = $terms[0]->name;
						return $s;
					}
				}
				break;
		}
		if ( $post ) {
			$link = get_permalink( $post );
			if ( $link ) {
				$s['url'] = (string) $link;
				$s['post'] = $post;
				$s['label'] = get_the_title( $post );
				return $s;
			}
		}
		$h = self::missing_sample( $role );
		return array_merge( $s, $h );
	}

	/** How to create something to preview a role with. @return array{hint:string,hint_url:string,hint_cta:string} */
	private static function missing_sample( string $role ): array {
		switch ( $role ) {
			case 'service':
				return [ 'hint' => __( 'There is no service to show yet. Add one and its page appears here with every shared design around it.', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=' . Entries::SERVICE ), 'hint_cta' => __( 'Add a service', 'launch-builder' ) ];
			case 'area':
				return [ 'hint' => __( 'There is no service area to show yet. Add one and its page appears here with every shared design around it.', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=' . Entries::AREA ), 'hint_cta' => __( 'Add a service area', 'launch-builder' ) ];
			case 'post':
				return [ 'hint' => __( 'There is no published blog post yet. Publish one to see this page type.', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php' ), 'hint_cta' => __( 'Write a post', 'launch-builder' ) ];
			case 'services_hub':
			case 'areas_hub':
				return [ 'hint' => __( 'No page is set as this hub yet. Create the page, then choose its role in the Page role box of the page editor (or ask your AI assistant to run set-page-role).', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=page' ), 'hint_cta' => __( 'Create a page', 'launch-builder' ) ];
			case 'category':
				return [ 'hint' => __( 'No page is linked to a service category yet. Create the page and link it to a category (your AI assistant can do it with set-page-role).', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=page' ), 'hint_cta' => __( 'Create a page', 'launch-builder' ) ];
			case 'blog':
				return [ 'hint' => __( 'There is no blog page or archive to show yet. Publish a post, or set a posts page under Settings > Reading.', 'launch-builder' ), 'hint_url' => admin_url( 'options-reading.php' ), 'hint_cta' => __( 'Reading settings', 'launch-builder' ) ];
			case 'conversion':
			case 'thanks':
			case 'legal':
				return [ 'hint' => __( 'No page has this role yet. Create a page and choose the role in the Page role box of the page editor.', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=page' ), 'hint_cta' => __( 'Create a page', 'launch-builder' ) ];
		}
		return [ 'hint' => __( 'There is no published page of this kind yet. Publish one to see it here.', 'launch-builder' ), 'hint_url' => admin_url( 'post-new.php?post_type=page' ), 'hint_cta' => __( 'Create a page', 'launch-builder' ) ];
	}

	private static function frame_url( string $url ): string {
		return add_query_arg( [ 'lb_hub' => '1', '_lbh' => wp_create_nonce( self::FRAME ) ], $url );
	}

	/* ---- the stage (a frame scaled to the pane) ---- */

	/**
	 * @param array{id?:string,src?:string,active?:bool,title:string,open?:string,default:int,widths:array<string,int>,loading:string} $o
	 */
	private static function stage( array $o ): string {
		$out = '<div class="lb-stage" data-lb-stage' . ( ! empty( $o['id'] ) ? ' id="' . esc_attr( $o['id'] ) . '"' : '' ) . '>';
		$out .= '<div class="lb-stage__bar"><div class="lb-seg" role="group" aria-label="' . esc_attr__( 'Preview width', 'launch-builder' ) . '">';
		foreach ( $o['widths'] as $label => $px ) {
			$out .= '<button type="button" data-w="' . (int) $px . '" aria-pressed="' . ( (int) $px === (int) $o['default'] ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		$out .= '</div><span class="lb-stage__size" data-lb-size></span><span class="lb-stage__tools"><button type="button" class="lb-iconbtn" data-lb-reload title="' . esc_attr__( 'Reload the preview', 'launch-builder' ) . '"><span class="screen-reader-text">' . esc_html__( 'Reload the preview', 'launch-builder' ) . '</span>' . self::icon( 'reload' ) . '</button>';
		$out .= '<a class="lb-iconbtn" data-lb-open target="_blank" rel="noopener" href="' . esc_url( (string) ( $o['open'] ?? '#' ) ) . '" title="' . esc_attr__( 'Open in a new tab', 'launch-builder' ) . '"><span class="screen-reader-text">' . esc_html__( 'Open in a new tab', 'launch-builder' ) . '</span>' . self::icon( 'open' ) . '</a></span></div>';
		$src = (string) ( $o['src'] ?? '' );
		$out .= '<div class="lb-stage__view"><div class="lb-stage__load" data-lb-loading role="status" hidden>' . esc_html( $o['loading'] ) . '</div><div class="lb-stage__frame" data-lb-frame><iframe title="' . esc_attr( $o['title'] ) . '"' . ( '' === $src ? '' : ( ! empty( $o['active'] ) ? ' src="' . esc_url( $src ) . '"' : ' data-src="' . esc_url( $src ) . '"' ) ) . '></iframe></div></div></div>';
		return $out;
	}

	private static function stage_panel( string $role, array $s, bool $active ): string {
		$label = self::role_label( $role );
		$out = '<section class="lb-panel lb-stagepanel" aria-labelledby="lb-stage-h"><div class="lb-panel__head"><h2 id="lb-stage-h">' . esc_html__( 'Live preview', 'launch-builder' ) . '</h2>';
		if ( $s['url'] ) {
			$edit = $s['post'] && current_user_can( 'edit_post', $s['post']->ID ) ? get_edit_post_link( $s['post']->ID, 'raw' ) : '';
			$n = (int) ( self::census()[ $role ]['n'] ?? 0 );
			$out .= '<span class="lb-note" style="margin:0">' . esc_html__( 'Showing:', 'launch-builder' ) . ' ' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $s['label'] ) . '</a>' : esc_html( $s['label'] ) ) . ( $n > 1 ? ' ' . esc_html( sprintf( /* translators: 1: number of pages, such as 4 or 200+, 2: page type such as "standard pages" */ __( '(one of %1$s %2$s)', 'launch-builder' ), self::num( $role ), self::role_noun( $role ) ) ) : '' ) . '</span>';
		}
		$out .= '</div>';
		if ( ! $s['url'] ) {
			$out .= '<div class="lb-empty lb-empty--stage"><b>' . esc_html( sprintf( /* translators: %s: page type such as "thank-you pages" */ __( 'No %s to show yet', 'launch-builder' ), self::role_noun( $role ) ) ) . '</b>' . esc_html( $s['hint'] ) . '<br><a class="lb-btn lb-btn--primary" style="margin-top:14px" href="' . esc_url( $s['hint_url'] ) . '">' . esc_html( $s['hint_cta'] ) . '</a></div></section>';
			return $out;
		}
		$out .= self::stage( [
			'id'      => 'lb-stage-live',
			'src'     => self::frame_url( $s['url'] ),
			'active'  => $active,
			'title'   => sprintf( /* translators: %s: page type such as "standard pages" */ __( 'Live preview: %s', 'launch-builder' ), $label ),
			'open'    => $s['url'],
			'default' => self::WIDTHS['desktop'],
			'widths'  => [ __( 'Desktop', 'launch-builder' ) => self::WIDTHS['desktop'], __( 'Tablet', 'launch-builder' ) => self::WIDTHS['tablet'], __( 'Mobile', 'launch-builder' ) => self::WIDTHS['mobile'] ],
			'loading' => __( 'Loading the page…', 'launch-builder' ),
		] );
		$out .= '<p class="lb-note">' . esc_html__( 'This is the live page, as a visitor sees it. Parts you have not applied yet show nothing. If the frame stays blank, open the page in a new tab.', 'launch-builder' ) . '</p></section>';
		return $out;
	}

	/* ---- what is placed ---- */

	private static function slots_panel( string $role, array $sample, array $gc ): string {
		$rows = [];
		$live = 0;
		$used = 0;
		foreach ( self::ORDER as $slot ) {
			$r = self::slot_row( $slot, $role, $gc, $sample );
			$rows[] = $r;
			if ( 'na' !== $r['status'] ) {
				$used++;
			}
			if ( 'live' === $r['status'] ) {
				$live++;
			}
		}
		$out = '<section class="lb-panel lb-placed" aria-labelledby="lb-placed-h"><div class="lb-panel__head"><h2 id="lb-placed-h">' . esc_html( sprintf( /* translators: %s: page type such as "standard pages" */ __( 'Designs on %s', 'launch-builder' ), self::role_noun( $role ) ) ) . '</h2><span class="lb-chip lb-chip--' . ( $used && $live === $used ? 'ok' : 'plain' ) . '">' . esc_html( sprintf( /* translators: 1: slots live, 2: slots that apply */ __( '%1$d of %2$d live', 'launch-builder' ), $live, $used ) ) . '</span></div>';
		$out .= '<p class="lb-note" style="margin:-6px 0 14px">' . esc_html__( 'From the top of the page to the bottom. Each card is one part of the page: change it for all of these pages, edit it, or see it in the library.', 'launch-builder' ) . '</p>';
		$active = '';
		$idle = '';
		$idle_n = 0;
		foreach ( $rows as $r ) {
			if ( 'na' === $r['status'] && 'crumbs' !== $r['slot'] ) { // Breadcrumbs stay in view: they can be switched on for this page type.
				$idle .= self::slot_card( $r, $role );
				$idle_n++;
			} else {
				$active .= self::slot_card( $r, $role );
			}
		}
		$out .= '<ul class="lb-slots">' . $active . '</ul>';
		if ( '' !== $idle ) {
			$out .= '<details class="lb-idle"><summary>' . esc_html( sprintf( /* translators: %d: number of parts that do not apply */ _n( '%d part is not used on this page type', '%d parts are not used on this page type', $idle_n, 'launch-builder' ), $idle_n ) ) . '</summary><ul class="lb-slots">' . $idle . '</ul></details>';
		}
		return $out . '</section>';
	}

	/** What a DESIGN is, in words and slugs. @param mixed $d @return array{kind:string,slugs:string[],label:string,custom:bool,id:int,key:string} */
	private static function describe( $d ): array {
		$r = [ 'kind' => 'none', 'slugs' => [], 'label' => '', 'custom' => false, 'id' => 0, 'key' => '' ];
		if ( false === $d || null === $d || '' === $d ) {
			return $r;
		}
		if ( 'theme' === $d ) {
			return [ 'kind' => 'theme' ] + $r;
		}
		if ( is_string( $d ) ) {
			return [ 'kind' => 'pattern', 'slugs' => [ $d ], 'label' => self::title( $d ) ] + $r;
		}
		if ( ! is_array( $d ) ) {
			return $r;
		}
		if ( isset( $d['adopt'] ) ) {
			$id = (int) $d['adopt'];
			return [ 'kind' => 'adopt', 'id' => $id, 'label' => $id ? get_the_title( $id ) : '' ] + $r;
		}
		if ( isset( $d['synced'] ) ) {
			$k = $d['synced'];
			$id = is_int( $k ) || ctype_digit( (string) $k ) ? (int) $k : Blueprint::find_synced( (string) $k );
			return [ 'kind' => 'synced', 'id' => $id, 'key' => (string) $k, 'label' => $id ? get_the_title( $id ) : (string) $k ] + $r;
		}
		if ( isset( $d['pattern'] ) ) {
			$slug = (string) $d['pattern'];
			return [ 'kind' => 'pattern', 'slugs' => [ $slug ], 'label' => self::title( $slug ), 'custom' => ! empty( $d['slots'] ) || ! empty( $d['replace'] ) || ! empty( $d['markdown'] ) ] + $r;
		}
		if ( isset( $d['sections'] ) && is_array( $d['sections'] ) ) {
			$slugs = [];
			$n = 0;
			foreach ( $d['sections'] as $s ) {
				$n++;
				$slug = is_string( $s ) ? $s : ( is_array( $s ) && isset( $s['pattern'] ) ? (string) $s['pattern'] : '' );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
			return [ 'kind' => 'sections', 'slugs' => $slugs, 'label' => sprintf( /* translators: %d: number of sections */ _n( '%d section', '%d sections', $n, 'launch-builder' ), $n ) ] + $r;
		}
		return $r;
	}

	private static function title( string $slug ): string {
		$p = Patterns::index()[ $slug ] ?? null;
		return $p ? (string) $p['title'] : $slug;
	}

	/** The state the map implies for a design: none | theme | failed | the group key chrome would compile it to. @param mixed $design */
	private static function expected_state( string $slot, $design ): string {
		$n = Chrome::normalize( $design, $slot );
		if ( is_wp_error( $n ) ) {
			return 'failed';
		}
		if ( false === $n[0] ) {
			return 'none';
		}
		if ( 'theme' === $n[0] ) {
			return 'theme';
		}
		return Chrome::group_key( $slot, $n[0] );
	}

	/** Why a slot does not matter for a role ('' = it does). */
	private static function not_used( string $slot, string $role ): string {
		if ( 'template' === $slot && ! in_array( $role, [ 'service', 'area' ], true ) ) {
			return __( 'Only service and area pages have a page template.', 'launch-builder' );
		}
		if ( 'notfound_body' === $slot && 'notfound' !== $role ) {
			return __( 'Only the 404 page uses this.', 'launch-builder' );
		}
		if ( 'crumbs' === $slot && ! Chrome::crumbs_on( $role ) ) {
			return __( 'Breadcrumbs are off for this page type.', 'launch-builder' );
		}
		return '';
	}

	private static function edit_link( int $id ): string {
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return '';
		}
		$u = get_edit_post_link( $id, 'raw' );
		return $u ? (string) $u : admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	private static function library_link( string $slug ): string {
		return self::url( [ 'tab' => 'library', 'p' => $slug ] );
	}

	/**
	 * Everything the card of one slot needs.
	 *
	 * @param mixed[] $gc     Chrome::get_tool() (compiled groups with ledger status, foreign elements).
	 * @param array   $sample sample() of the role.
	 */
	private static function slot_row( string $slot, string $role, array $gc, array $sample ): array {
		[ $name, $hint ] = self::slot_info( $slot );
		$design = Chrome::design( $slot, $role );
		$state = Chrome::state( $slot, $role );
		$applied = Chrome::applied( $slot );
		$expect = self::expected_state( $slot, $design );
		$d = self::describe( $design );
		$na = self::not_used( $slot, $role );
		$printer = Chrome::printer( $slot, $role );
		$g = Chrome::group( $slot, $role );

		if ( '' !== $na ) {
			$status = 'na';
		} elseif ( 'failed' === $expect ) {
			$status = 'failed';
		} elseif ( ! $applied ) {
			$status = 'none' === $expect || 'theme' === $expect ? $expect : 'unapplied';
		} elseif ( 'failed' === $state ) {
			$status = 'failed';
		} elseif ( $state !== $expect ) {
			$status = 'stale';
		} elseif ( 'none' === $state || 'theme' === $state ) {
			$status = $state;
		} else {
			$status = '' !== $printer ? 'live' : 'off';
		}

		$row = [
			'slot' => $slot, 'name' => $name, 'hint' => $hint, 'status' => $status, 'na' => $na, 'design' => $d, 'raw' => $design, 'printer' => $printer,
			'places' => [], 'edited' => false, 'covers' => 'na' === $status ? [] : self::covers( $slot, $role ), 'foreign' => [], 'missing' => [], 'note' => '', 'expires' => $g ? (string) ( $g['expires'] ?? '' ) : '',
		];

		// Where it lives: the Kadence Element, the synced pattern / header it holds, or Launch Builder itself.
		$grow = null;
		foreach ( (array) ( $gc['slots'][ $slot ]['groups'] ?? [] ) as $gr ) {
			if ( $g && (string) ( $gr['key'] ?? '' ) === $g['key'] ) {
				$grow = $gr;
			}
		}
		if ( $g && $grow ) {
			$kind = (string) ( $grow['kind'] ?? '' );
			$store = (array) ( $grow['store'] ?? [] );
			$el = (array) ( $grow['element'] ?? [] );
			if ( ! empty( $el['id'] ) && 'elements' === Chrome::mode() ) {
				$row['places'][] = [ 'kind' => 'element', 'id' => (int) $el['id'], 'edit' => self::edit_link( (int) $el['id'] ) ];
			}
			if ( ! empty( $store['id'] ) && 'adopt' !== $kind ) {
				$row['places'][] = [ 'kind' => 'header' === $kind ? 'header' : 'synced', 'id' => (int) $store['id'], 'edit' => self::edit_link( (int) $store['id'] ) ];
			}
			$row['edited'] = 'kept_edits' === ( $store['ledger'] ?? '' ) || 'kept_edits' === ( $el['ledger'] ?? '' );
		}
		foreach ( (array) ( $gc['foreign_elements'] ?? [] ) as $fe ) {
			if ( ( $fe['slot'] ?? '' ) === $slot && in_array( $role, (array) ( $fe['roles'] ?? [] ), true ) ) {
				$row['foreign'][] = [ 'title' => (string) ( $fe['title'] ?? '' ), 'edit' => self::edit_link( (int) ( $fe['id'] ?? 0 ) ) ];
			}
		}
		foreach ( $d['slugs'] as $slug ) {
			if ( ! isset( Patterns::index()[ $slug ] ) ) {
				$row['missing'][] = $slug;
			}
		}
		if ( in_array( $slot, [ 'hero', 'crumbs' ], true ) && $sample['post'] ) {
			$h = (string) get_post_meta( $sample['post']->ID, Chrome::HERO_META, true );
			if ( 'content' === $h || 'none' === $h ) {
				$row['note'] = 'content' === $h ? __( 'This sample page has its own hero, so the shared one steps aside on it.', 'launch-builder' ) : __( 'This sample page is set to show no hero, so the shared one stays out of it.', 'launch-builder' );
			}
		}
		if ( in_array( $slot, [ 'sticky_bar', 'notice' ], true ) && class_exists( __NAMESPACE__ . '\\Conversions' ) && Conversions::active() && Conversions::slot_live( $slot, $role ) ) {
			$row['note'] = __( 'A Kadence Conversions banner covers this slot on this page type, so the plugin\'s own copy stays hidden.', 'launch-builder' );
		}
		return $row;
	}

	/** The title of the first group of designs: made for this page type, or just "All designs" for a part that is one design for the whole site. */
	private static function fit_label( string $slot, string $role ): string {
		if ( ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			return __( 'All designs', 'launch-builder' );
		}
		return sprintf( /* translators: %s: page type such as "standard pages" */ __( 'Made for %s', 'launch-builder' ), self::role_noun( $role ) );
	}

	/** Alternatives for a slot and role: [ made for this role, other designs of the slot ]. Parts that are one design for every page type have one list. @return array{0:array,1:array} */
	private static function alternatives( string $slot, string $role ): array {
		$r = Patterns::list_patterns( [ 'slot' => $slot, 'limit' => 200 ] );
		$fit = [];
		$other = [];
		if ( is_wp_error( $r ) ) {
			return [ $fit, $other ];
		}
		foreach ( (array) $r['patterns'] as $p ) {
			$roles = (array) ( $p['roles'] ?? [] );
			if ( ! $roles || in_array( $role, $roles, true ) ) {
				$fit[] = $p;
			} else {
				$other[] = $p;
			}
		}
		if ( ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			return [ array_merge( $fit, $other ), [] ];
		}
		return [ $fit, $other ];
	}

	/** Extra choices besides patterns: value => label. @return array<string,string> */
	private static function extra_choices( string $slot ): array {
		switch ( $slot ) {
			case 'header':
				return [ 'theme' => __( 'The theme\'s own header', 'launch-builder' ) ];
			case 'hero':
				return [ 'theme' => __( 'Kadence title banner (the theme\'s own)', 'launch-builder' ), '__none' => __( 'No hero', 'launch-builder' ) ];
			case 'footer':
				return [ 'theme' => __( 'The theme\'s own footer', 'launch-builder' ) ];
			case 'notfound_body':
				return [ 'theme' => __( 'The theme\'s own 404 content', 'launch-builder' ) ];
			case 'notice':
				return [ '__none' => __( 'No notice bar', 'launch-builder' ) ];
			case 'template':
				return [ '__none' => __( 'No template (plain content)', 'launch-builder' ) ];
			case 'before_footer':
				return [ '__none' => __( 'Nothing above the footer', 'launch-builder' ) ];
			case 'sticky_bar':
				return [ '__none' => __( 'No sticky bar', 'launch-builder' ) ];
		}
		return [];
	}

	/** Does this role follow the slot's "default" (so a change can apply to every role that does)? */
	private static function follows_default( string $slot, string $role ): bool {
		if ( ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			return false;
		}
		$v = Chrome::get()[ $slot ] ?? null;
		return is_array( $v ) && ! self::is_design_object( $v ) && array_key_exists( 'default', $v ) && ! array_key_exists( $role, $v );
	}

	private static function is_design_object( $v ): bool {
		return is_array( $v ) && (bool) array_intersect_key( $v, [ 'pattern' => 1, 'sections' => 1, 'synced' => 1, 'adopt' => 1 ] );
	}

	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'live':
				return __( 'Live', 'launch-builder' );
			case 'unapplied':
				return __( 'Not applied yet', 'launch-builder' );
			case 'stale':
				return __( 'Needs applying', 'launch-builder' );
			case 'failed':
				return __( 'Could not be placed', 'launch-builder' );
			case 'off':
				return __( 'Switched off', 'launch-builder' );
			case 'theme':
				return __( 'Theme default', 'launch-builder' );
			case 'none':
				return __( 'None', 'launch-builder' );
		}
		return __( 'Not used here', 'launch-builder' );
	}

	private static function chip_class( string $status ): string {
		if ( 'live' === $status ) {
			return 'lb-chip--ok';
		}
		if ( 'unapplied' === $status || 'stale' === $status || 'off' === $status ) {
			return 'lb-chip--warn';
		}
		if ( 'failed' === $status ) {
			return 'lb-chip--bad';
		}
		return 'lb-chip--plain';
	}

	/** Where to edit a card's design: the synced pattern or header that holds it, else the Kadence Element. */
	private static function edit_target( array $r ): string {
		$first = '';
		foreach ( $r['places'] as $pl ) {
			if ( '' === $pl['edit'] ) {
				continue;
			}
			if ( 'element' !== $pl['kind'] ) {
				return $pl['edit'];
			}
			$first = '' === $first ? $pl['edit'] : $first;
		}
		return $first;
	}

	private static function slot_card( array $r, string $role ): string {
		$slot = $r['slot'];
		$st = $r['status'];
		$d = $r['design'];
		$theme_is_none = in_array( $slot, [ 'header', 'footer', 'notfound_body' ], true ); // For these a design of "none" means the theme's own is used.
		$label = 'none' === $st && $theme_is_none ? __( 'Theme default', 'launch-builder' ) : self::status_label( $st );
		$out = '<li class="lb-slot" id="lb-slot-' . esc_attr( $slot ) . '" data-slot="' . esc_attr( $slot ) . '" data-state="' . esc_attr( $st ) . '">';
		$out .= '<div class="lb-slot__head"><span class="lb-slot__tile">' . self::icon( $slot ) . '</span><div class="lb-slot__name"><b>' . esc_html( $r['name'] ) . '</b><span>' . esc_html( $r['hint'] ) . '</span></div><span class="lb-chip ' . esc_attr( self::chip_class( $st ) ) . '" data-lb-slot-state>' . esc_html( $label ) . '</span></div>';

		if ( 'na' === $st ) {
			$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( $r['na'] ) . '</p>';
			if ( 'crumbs' === $slot ) {
				$out .= self::crumbs_form( $role );
			}
			return $out . '</li>';
		}

		// The design.
		$out .= '<div class="lb-slot__design">';
		if ( 'none' === $d['kind'] ) {
			$out .= '<span class="lb-slot__none">' . esc_html( $theme_is_none ? __( 'This page type keeps the theme\'s own.', 'launch-builder' ) : __( 'Nothing is placed here on purpose.', 'launch-builder' ) ) . '</span>';
		} elseif ( 'theme' === $d['kind'] ) {
			$out .= '<span class="lb-slot__none">' . esc_html__( 'This page type keeps the theme\'s own.', 'launch-builder' ) . '</span>';
		} elseif ( 'sections' === $d['kind'] ) {
			$out .= '<strong>' . esc_html( $d['label'] ) . '</strong><span class="lb-pills">';
			foreach ( $d['slugs'] as $slug ) {
				$out .= isset( Patterns::index()[ $slug ] ) ? '<a class="lb-pill" href="' . esc_url( self::library_link( $slug ) ) . '" title="' . esc_attr__( 'See it in the library', 'launch-builder' ) . '">' . esc_html( self::title( $slug ) ) . '</a>' : '<span class="lb-pill lb-pill--missing">' . esc_html( $slug ) . '</span>';
			}
			$out .= '</span>';
		} elseif ( 'pattern' === $d['kind'] ) {
			$out .= '<strong>' . esc_html( $d['label'] ) . '</strong>' . ( $d['custom'] ? ' <span class="lb-chip lb-chip--plain">' . esc_html__( 'customised', 'launch-builder' ) . '</span>' : '' );
		} else {
			$out .= '<strong>' . esc_html( '' !== $d['label'] ? $d['label'] : ( 'adopt' === $d['kind'] ? __( 'A Kadence Element', 'launch-builder' ) : __( 'A synced pattern', 'launch-builder' ) ) ) . '</strong> <span class="lb-chip lb-chip--plain">' . esc_html( 'adopt' === $d['kind'] ? __( 'adopted element', 'launch-builder' ) : __( 'synced pattern', 'launch-builder' ) ) . '</span>';
		}
		$out .= '</div>';

		// Which page types get this design.
		if ( in_array( $d['kind'], [ 'pattern', 'sections', 'synced', 'adopt' ], true ) && $r['covers'] ) {
			$out .= '<p class="lb-slot__covers"><span class="lb-slot__label">' . esc_html__( 'Used on', 'launch-builder' ) . '</span><span>' . esc_html( 'notice' === $slot ? __( 'Every page of your site', 'launch-builder' ) : self::roles_text( $r['covers'], false ) ) . '</span></p>';
			if ( 'crumbs' === $slot ) {
				$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html__( 'They all share one design. Use Change design to pick another, or the button below to choose where they show.', 'launch-builder' ) . '</p>';
			}
			$own = self::own_text( $slot, $r['covers'] );
			if ( '' !== $own ) {
				$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( $own ) . '</p>';
			}
		}

		// What the state means.
		if ( 'unapplied' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'Your site does not show this yet. Applying places it for every page type that uses it.', 'launch-builder' ) . '</p>';
		} elseif ( 'stale' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'The design settings changed after the last apply. Apply to bring the site in line.', 'launch-builder' ) . '</p>';
		} elseif ( 'failed' === $st ) {
			$out .= '<p class="lb-slot__line">' . ( $r['missing'] ? esc_html( sprintf( /* translators: %s: pattern slug(s) */ __( 'The pattern %s is not in this version yet, so nothing is placed. Choose another design below.', 'launch-builder' ), implode( ', ', $r['missing'] ) ) ) : esc_html__( 'The last apply could not place this design. Apply again, or choose another design below.', 'launch-builder' ) ) . '</p>';
		} elseif ( 'off' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'Its element or synced pattern was set to draft or moved to the trash, so nothing prints. Publish it again to bring it back.', 'launch-builder' ) . '</p>';
		} elseif ( 'live' === $st && $r['missing'] ) {
			$out .= '<p class="lb-slot__line">' . esc_html( sprintf( /* translators: %s: pattern slug(s) */ __( 'The pattern %s is not available in this version; what is placed is a saved copy.', 'launch-builder' ), implode( ', ', $r['missing'] ) ) ) . '</p>';
		}
		if ( 'notice' === $slot && '' !== $r['expires'] && 'live' === $st ) {
			$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( sprintf( /* translators: %s: date the notice bar stops showing */ __( 'Shows until %s.', 'launch-builder' ), mysql2date( get_option( 'date_format' ), str_replace( 'T', ' ', $r['expires'] ) ) ) ) . '</p>';
		}
		if ( '' !== $r['note'] ) {
			$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( $r['note'] ) . '</p>';
		}
		foreach ( $r['foreign'] as $f ) {
			$out .= '<p class="lb-slot__line lb-slot__line--warn">' . esc_html( sprintf( /* translators: %s: element title */ __( 'Another Kadence Element, "%s", also prints here.', 'launch-builder' ), $f['title'] ) ) . ( $f['edit'] ? ' <a href="' . esc_url( $f['edit'] ) . '">' . esc_html__( 'Edit it', 'launch-builder' ) . '</a>' : '' ) . '</p>';
		}

		// The actions that matter: change it, edit it, see it in the library.
		$edit = self::edit_target( $r );
		$needs = in_array( $st, [ 'unapplied', 'stale', 'failed' ], true ) && ! ( 'failed' === $st && $r['missing'] );
		$foot = '';
		if ( $needs ) {
			$foot .= self::apply_form( $slot, $role, 'unapplied' === $st ? __( 'Apply', 'launch-builder' ) : __( 'Apply again', 'launch-builder' ) );
		}
		$foot .= self::change_form( $r, $role, ! $needs );
		if ( '' !== $edit ) {
			$foot .= '<a class="lb-btn lb-btn--sm" href="' . esc_url( $edit ) . '">' . self::icon( 'edit' ) . esc_html__( 'Edit design', 'launch-builder' ) . '</a>';
		}
		if ( 'pattern' === $d['kind'] && isset( Patterns::index()[ $d['slugs'][0] ] ) ) {
			$foot .= '<a class="lb-link" href="' . esc_url( self::library_link( $d['slugs'][0] ) ) . '">' . self::icon( 'library' ) . esc_html__( 'See in library', 'launch-builder' ) . '</a>';
		}
		if ( '' !== $foot ) {
			$out .= '<div class="lb-slot__foot">' . $foot . '</div>';
		}
		if ( '' !== $edit && count( $r['covers'] ) > 1 ) { // Edit design opens the one design that all of them share.
			$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( 'notice' === $slot ? __( 'Edits change this on every page of your site.', 'launch-builder' ) : __( 'Edits change every page type listed under Used on.', 'launch-builder' ) ) . '</p>';
		}
		if ( 'crumbs' === $slot ) {
			$out .= self::crumbs_form( $role );
		}

		// The technical details, out of the way.
		$more = '';
		if ( in_array( $st, [ 'live', 'off', 'stale' ], true ) ) {
			$where = '';
			if ( 'element' === $r['printer'] ) {
				$where = __( 'Placed by a Kadence Element', 'launch-builder' );
			} elseif ( 'lb' === $r['printer'] ) {
				$where = __( 'Placed by Launch Builder', 'launch-builder' );
			}
			$links = [];
			foreach ( $r['places'] as $pl ) {
				if ( 'element' === $pl['kind'] && '' !== $pl['edit'] && $pl['edit'] !== $edit ) {
					$links[] = '<a class="lb-edit" href="' . esc_url( $pl['edit'] ) . '">' . self::icon( 'edit' ) . esc_html__( 'Placement settings', 'launch-builder' ) . '</a>';
				}
			}
			if ( '' !== $where || $links ) {
				$more .= '<p class="lb-slot__where">' . ( '' !== $where ? '<span>' . esc_html( $where ) . '</span>' : '' ) . implode( '', $links ) . '</p>';
			}
			if ( $r['edited'] ) {
				$more .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html__( 'You edited it in the editor, so Launch Builder keeps your version when it re-applies.', 'launch-builder' ) . '</p>';
			}
		}
		if ( 'pattern' === $d['kind'] ) {
			$more .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html__( 'Pattern name:', 'launch-builder' ) . ' <code>' . esc_html( $d['slugs'][0] ) . '</code></p>';
		}
		if ( '' !== $more ) {
			$out .= '<details class="lb-slot__more"><summary>' . esc_html__( 'Details', 'launch-builder' ) . '</summary>' . $more . '</details>';
		}
		return $out . '</li>';
	}

	private static function post_fields( string $op, string $slot, string $role ): string {
		return '<input type="hidden" name="action" value="lb_hub_apply"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::APPLY ) ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="slot" value="' . esc_attr( $slot ) . '"><input type="hidden" name="role" value="' . esc_attr( $role ) . '">';
	}

	private static function apply_form( string $slot, string $role, string $label ): string {
		return '<form class="lb-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'apply', $slot, $role ) . '<button type="submit" class="lb-btn lb-btn--primary lb-btn--sm" data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** A page type's real pages without its name: "About, Our Team, FAQ +1 more (4 pages)" ('' when it has none). */
	private static function scope_detail( string $role ): string {
		$n = (int) ( self::census()[ $role ]['n'] ?? 0 );
		return $n > 0 ? self::examples( $role ) . ' (' . self::count_text( $role ) . ')' : '';
	}

	/** Breadcrumbs: the page types that show them, as a checklist (the design is picked with Change design). */
	private static function crumbs_form( string $role ): string {
		$on = self::affected( 'crumbs', $role, 'role' );
		$out = '<details class="lb-where"><summary class="lb-btn lb-btn--sm">' . self::icon( 'pages' ) . esc_html__( 'Choose where breadcrumbs show', 'launch-builder' ) . '</summary>';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'crumbs', 'crumbs', $role );
		$out .= '<fieldset class="lb-checks"><legend>' . esc_html__( 'Show breadcrumbs on these page types', 'launch-builder' ) . '</legend><div class="lb-checks__list">';
		foreach ( self::roles() as $r ) {
			$id = 'lb-crumb-' . $r;
			$detail = self::scope_detail( $r );
			$flag = in_array( $r, self::NO_CRUMBS, true ) ? '<small class="lb-check__flag">' . esc_html__( 'Not usually wanted here', 'launch-builder' ) . '</small>' : '';
			$out .= '<label class="lb-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="crumb_roles[]" value="' . esc_attr( $r ) . '"' . ( in_array( $r, $on, true ) ? ' checked' : '' ) . '><span><b>' . esc_html( self::role_label( $r ) ) . '</b>' . $flag . ( '' !== $detail ? '<small>' . esc_html( $detail ) . '</small>' : '' ) . '</span></label>';
		}
		$out .= '</div></fieldset><p class="lb-note">' . esc_html__( 'They all share one design. A page with a hero of its own shows no breadcrumbs.', 'launch-builder' ) . '</p>';
		$out .= '<div class="lb-change__actions"><button type="submit" class="lb-btn lb-btn--primary lb-btn--sm" data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '">' . esc_html__( 'Save and apply', 'launch-builder' ) . '</button></div><p class="lb-note" style="margin:8px 0 0">' . esc_html__( 'A snapshot is saved first, and you can undo the change right afterwards.', 'launch-builder' ) . '</p></form></details>';
		return $out;
	}

	/**
	 * Change design. With JavaScript the button opens the dialog (previews of every design, then Apply); without it the
	 * details element below holds the same choice as a plain form that posts to the same handler.
	 */
	private static function change_form( array $r, string $role, bool $primary = true ): string {
		$slot = $r['slot'];
		[ $fit, $other ] = self::alternatives( $slot, $role );
		$extra = self::extra_choices( $slot );
		if ( ! $fit && ! $other && ! $extra ) {
			return '<span class="lb-note lb-slot__nochoice">' . esc_html__( 'No other design for this slot yet.', 'launch-builder' ) . '</span>';
		}
		$d = $r['design'];
		if ( 'pattern' === $d['kind'] && ! $d['custom'] ) {
			$cur = $d['slugs'][0];
		} elseif ( 'theme' === $d['kind'] || ( 'none' === $d['kind'] && isset( $extra['theme'] ) && ! isset( $extra['__none'] ) ) ) {
			$cur = 'theme';
		} else {
			$cur = 'none' === $d['kind'] && isset( $extra['__none'] ) ? '__none' : '';
		}
		$scopes = self::scope_options( $slot, $role );
		self::$pick[ $slot ] = self::pick_slot( $r, $role, $fit, $other, $extra, $cur, $scopes );
		$id = 'lb-pick-' . $slot;
		$cls = 'lb-btn lb-btn--sm' . ( $primary ? ' lb-btn--primary' : '' );

		$out = '<button type="button" class="' . esc_attr( $cls ) . '" data-lb-pick="' . esc_attr( $slot ) . '" aria-haspopup="dialog" hidden>' . self::icon( 'swap' ) . esc_html__( 'Change design', 'launch-builder' ) . '</button>';
		$out .= '<details class="lb-change"><summary class="' . esc_attr( $cls ) . '">' . self::icon( 'swap' ) . esc_html__( 'Change design', 'launch-builder' ) . '</summary>';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'change', $slot, $role );
		$out .= '<label class="lb-field" for="' . esc_attr( $id ) . '">' . esc_html__( 'Design', 'launch-builder' ) . '</label><select name="design" id="' . esc_attr( $id ) . '" data-lb-pick-select required>';
		if ( '' === $cur ) {
			$out .= '<option value="" selected disabled>' . esc_html__( 'Choose a design…', 'launch-builder' ) . '</option>';
		}
		$opt = static function ( array $p ) use ( $cur ) {
			return '<option value="' . esc_attr( $p['slug'] ) . '" data-slug="' . esc_attr( $p['slug'] ) . '" data-title="' . esc_attr( $p['title'] ) . '"' . ( $p['slug'] === $cur ? ' selected' : '' ) . '>' . esc_html( $p['title'] ) . ' (' . esc_html( $p['slug'] ) . ')</option>';
		};
		if ( $fit ) {
			$out .= '<optgroup label="' . esc_attr( self::fit_label( $slot, $role ) ) . '">';
			foreach ( $fit as $p ) {
				$out .= $opt( $p );
			}
			$out .= '</optgroup>';
		}
		if ( $other ) {
			$out .= '<optgroup label="' . esc_attr__( 'Made for other page types', 'launch-builder' ) . '">';
			foreach ( $other as $p ) {
				$out .= $opt( $p );
			}
			$out .= '</optgroup>';
		}
		if ( $extra ) {
			$out .= '<optgroup label="' . esc_attr__( 'Other choices', 'launch-builder' ) . '">';
			foreach ( $extra as $v => $label ) {
				$out .= '<option value="' . esc_attr( $v ) . '"' . ( $v === $cur ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
			$out .= '</optgroup>';
		}
		$out .= '</select>';
		if ( count( $scopes ) > 1 ) {
			$out .= '<fieldset class="lb-radios"><legend>' . esc_html__( 'How far should this change go?', 'launch-builder' ) . '</legend>';
			foreach ( $scopes as $i => $o ) {
				$out .= '<label class="lb-check" for="' . esc_attr( $id . '-' . $o['v'] ) . '"><input type="radio" name="scope" id="' . esc_attr( $id . '-' . $o['v'] ) . '" value="' . esc_attr( $o['v'] ) . '"' . ( 0 === $i ? ' checked' : '' ) . '><span><b>' . esc_html( $o['label'] ) . '</b><small>' . esc_html( $o['detail'] ) . '</small></span></label>';
			}
			$out .= '</fieldset>';
		} else {
			$out .= '<p class="lb-willchange">' . esc_html( $scopes[0]['sentence'] ) . '</p>';
			if ( '' !== $scopes[0]['own'] ) {
				$out .= '<p class="lb-note" style="margin:6px 0 0">' . esc_html( $scopes[0]['own'] ) . '</p>';
			}
		}
		$out .= '<div class="lb-change__actions"><button type="submit" class="lb-btn lb-btn--primary lb-btn--sm" data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '">' . esc_html__( 'Apply this design', 'launch-builder' ) . '</button></div>';
		$out .= '<p class="lb-note" style="margin:8px 0 0">' . esc_html__( 'A snapshot is saved first, and you can undo the change right afterwards.', 'launch-builder' ) . '</p></form></details>';
		return $out;
	}

	/** Pixel sizes the designs of a slot are previewed at: [ page width, visible height, widest page width of the large preview ]. */
	private static function thumb( string $slot ): array {
		switch ( $slot ) {
			case 'notice':
				return [ 1200, 70, 1280 ];
			case 'crumbs':
				return [ 1200, 90, 1280 ];
			case 'hero':
				return [ 1200, 560, 1280 ];
			case 'template':
				return [ 1200, 900, 1280 ];
			case 'notfound_body':
				return [ 1200, 720, 1280 ];
			case 'before_footer':
				return [ 1200, 780, 1280 ];
			case 'footer':
				return [ 1200, 640, 1280 ];
			case 'sticky_bar':
				return [ 420, 300, 390 ]; // A bar for phones: shown at phone width.
		}
		return [ 1200, 700, 1280 ];
	}

	/** The first sentence of a pattern's description: the part written for owners (what follows can be about how it is placed). */
	private static function first_sentence( string $d ): string {
		$d = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $d ) ) );
		$parts = preg_split( '/(?<=[.!?])\s/', $d, 2 );
		return rtrim( is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : $d, ' :;,' );
	}

	/** The first sentence of a pattern's description, short enough for a card. */
	private static function brief( string $d ): string {
		$s = self::first_sentence( $d );
		return mb_strlen( $s ) > 120 ? rtrim( mb_substr( $s, 0, 117 ), ' ,;:.' ) . '…' : $s;
	}

	/** What a non-pattern choice (the theme's own, nothing) means for a slot. */
	private static function extra_info( string $slot, string $v ): string {
		if ( 'theme' === $v ) {
			switch ( $slot ) {
				case 'header':
					return __( 'The header your theme draws, as set in the Customizer.', 'launch-builder' );
				case 'hero':
					return __( 'The standard title area from your theme, with the page title.', 'launch-builder' );
				case 'footer':
					return __( 'The footer your theme draws, as set in the Customizer.', 'launch-builder' );
				case 'notfound_body':
					return __( 'The standard "page not found" text from your theme.', 'launch-builder' );
			}
		}
		if ( '__none' === $v ) {
			switch ( $slot ) {
				case 'hero':
					return __( 'No banner. The page starts with its own content.', 'launch-builder' );
				case 'notice':
					return __( 'No bar above the header.', 'launch-builder' );
				case 'template':
					return __( 'No shared layout. Each page shows only its own content.', 'launch-builder' );
				case 'before_footer':
					return __( 'Nothing between the content and the footer.', 'launch-builder' );
				case 'sticky_bar':
					return __( 'No call bar on phones.', 'launch-builder' );
			}
		}
		return '';
	}

	/**
	 * What the dialog shows for one slot (plain text: the script sets it with textContent). Designs made for this page type
	 * come first, then the rest, then the non-pattern choices; the design in use leads its group.
	 *
	 * @param array[]  $fit    designs made for this page type (list-patterns rows).
	 * @param array[]  $other  designs made for other page types.
	 * @param string[] $extra  value => label of the choices that are not patterns.
	 * @param array[]  $scopes scope_options().
	 */
	private static function pick_slot( array $r, string $role, array $fit, array $other, array $extra, string $cur, array $scopes ): array {
		$slot = $r['slot'];
		$d = $r['design'];
		$can_see = 'header' !== $slot; // Header patterns are applied, not rendered on their own.
		$item = static function ( array $p ) use ( $cur, $can_see ) {
			$names = array_map( [ __CLASS__, 'role_label' ], array_values( (array) ( $p['roles'] ?? [] ) ) );
			$desc = (string) ( $p['description'] ?? '' );
			return [
				'v'    => (string) $p['slug'],
				't'    => (string) $p['title'],
				'd'    => self::brief( $desc ),
				'long' => self::first_sentence( $desc ),
				'for'  => $names ? sprintf( /* translators: %s: page types */ __( 'Made for: %s', 'launch-builder' ), implode( ', ', $names ) ) : __( 'Works on any page type.', 'launch-builder' ),
				'cap'  => $can_see ? '' : __( 'Seen on your page once applied', 'launch-builder' ),
				'use'  => (string) $p['slug'] === $cur,
				'o'    => false,
			];
		};
		$lead = static function ( array $items ) { // The design in use first, the rest in their order.
			$a = [];
			$b = [];
			foreach ( $items as $it ) {
				if ( $it['use'] ) {
					$a[] = $it;
				} else {
					$b[] = $it;
				}
			}
			return array_merge( $a, $b );
		};
		$groups = [];
		if ( $fit ) {
			$groups[] = [ 'label' => self::fit_label( $slot, $role ), 'items' => $lead( array_map( $item, $fit ) ) ];
		}
		if ( $other ) {
			$groups[] = [ 'label' => __( 'Made for other page types', 'launch-builder' ), 'items' => $lead( array_map( $item, $other ) ) ];
		}
		if ( $extra ) {
			$items = [];
			foreach ( $extra as $v => $label ) {
				$info = self::extra_info( $slot, (string) $v );
				$items[] = [ 'v' => (string) $v, 't' => $label, 'd' => $info, 'long' => $info, 'for' => '', 'cap' => '__none' === (string) $v ? __( 'Nothing is placed', 'launch-builder' ) : __( 'Your theme\'s own design', 'launch-builder' ), 'use' => (string) $v === $cur, 'o' => true ];
			}
			$groups[] = [ 'label' => __( 'Other choices', 'launch-builder' ), 'items' => $items ];
		}
		$replaces = '';
		if ( in_array( $d['kind'], [ 'sections', 'adopt', 'synced' ], true ) || ( 'pattern' === $d['kind'] && $d['custom'] ) ) {
			$replaces = sprintf( /* translators: %s: what is placed now, such as "3 sections" */ __( 'This replaces your current design: %s.', 'launch-builder' ), '' !== $d['label'] ? $d['label'] : __( 'a saved design', 'launch-builder' ) );
		}
		[ $pw, $ph, $sw ] = self::thumb( $slot );
		return [
			'name'     => $r['name'],
			'title'    => sprintf( /* translators: %s: part of the page such as "the hero" */ __( 'Change %s', 'launch-builder' ), self::slot_info( $slot )[2] ),
			'sub'      => in_array( $slot, Chrome::ROLE_SLOTS, true )
				? sprintf( /* translators: %s: page type such as "standard pages" */ __( 'For %s. Pick a design to see it larger. Nothing changes until you press Apply.', 'launch-builder' ), self::role_noun( $role ) )
				: __( 'Pick a design to see it larger. Nothing changes until you press Apply.', 'launch-builder' ),
			'current'  => $cur,
			'status'   => $r['status'], // "Already in use" is nothing to do only while the slot is settled (live, none or theme); otherwise Apply places it.
			'groups'   => $groups,
			'icon'     => self::icon( $slot ),
			'wide'     => in_array( $slot, [ 'notice', 'crumbs' ], true ), // Slim bars: one preview per row, so they can be read.
			'preview'  => $can_see,
			'note'     => $can_see ? '' : __( 'A header cannot be shown on its own. Apply it and look at your live page: you can undo the change with one click.', 'launch-builder' ),
			'pw'       => $pw,
			'ph'       => $ph,
			'sw'       => $sw,
			'scopes'   => array_map( static function ( $o ) { return [ 'v' => $o['v'], 'label' => $o['label'], 'detail' => $o['detail'], 'sentence' => $o['sentence'], 'own' => $o['own'] ]; }, $scopes ),
			'replaces' => $replaces,
		];
	}

	/* ---- popups and banners (Kadence Conversions) ---- */

	private static function conversions_panel( string $role ): string {
		$head = '<section class="lb-panel lb-conv" aria-labelledby="lb-conv-h"><div class="lb-panel__head"><h2 id="lb-conv-h">' . esc_html__( 'Popups and banners', 'launch-builder' ) . '</h2>';
		if ( ! class_exists( __NAMESPACE__ . '\\Conversions' ) || ! Conversions::active() ) {
			return $head . '</div><p class="lb-note" style="margin:0">' . esc_html__( 'Kadence Conversions is not active. When it is, its popups, slide-ins and banners are listed here with where they show.', 'launch-builder' ) . '</p></section>';
		}
		$list = Conversions::get_tool( [ 'limit' => 30 ] );
		$items = [];
		foreach ( is_array( $list ) ? (array) ( $list['items'] ?? [] ) : [] as $it ) {
			if ( 'trash' !== ( $it['status'] ?? '' ) ) {
				$items[] = $it;
			}
		}
		$head .= '<a class="lb-link" href="' . esc_url( admin_url( 'edit.php?post_type=' . Conversions::POST_TYPE ) ) . '">' . esc_html__( 'Open Conversions', 'launch-builder' ) . '</a></div>';
		if ( ! $items ) {
			return $head . '<p class="lb-note" style="margin:0">' . esc_html__( 'No popups or banners yet. Your AI assistant adds them with set-conversion, or build one in Kadence Conversions.', 'launch-builder' ) . '</p></section>';
		}
		$types = [ 'popup' => __( 'Popup', 'launch-builder' ), 'banner' => __( 'Banner', 'launch-builder' ), 'slide_in' => __( 'Slide-in', 'launch-builder' ), 'slide-in' => __( 'Slide-in', 'launch-builder' ) ];
		$trig = [ 'exit_intent' => __( 'Exit intent', 'launch-builder' ), 'time' => __( 'After a delay', 'launch-builder' ), 'scroll' => __( 'On scroll', 'launch-builder' ), 'link' => __( 'On click', 'launch-builder' ), 'load' => __( 'On page load', 'launch-builder' ), 'content_end' => __( 'End of content', 'launch-builder' ) ];
		$out = $head . '<ul class="lb-convlist">';
		foreach ( $items as $it ) {
			$status = (string) ( $it['status'] ?? '' );
			if ( ! empty( $it['expired'] ) ) {
				$chip = [ 'warn', __( 'Expired', 'launch-builder' ) ];
			} elseif ( 'publish' === $status ) {
				$chip = [ 'ok', __( 'Live', 'launch-builder' ) ];
			} elseif ( 'future' === $status ) {
				$chip = [ 'warn', __( 'Scheduled', 'launch-builder' ) ];
			} else {
				$chip = [ 'plain', __( 'Draft', 'launch-builder' ) ];
			}
			$type = (string) ( $it['type'] ?? '' );
			$here = '';
			if ( 'launch-builder' === ( $it['owner'] ?? '' ) && ! empty( $it['roles_enforced'] ) ) {
				$show = (array) ( $it['show_roles'] ?? [] );
				$hide = (array) ( $it['hide_roles'] ?? [] );
				$yes = ( ! $show || in_array( $role, $show, true ) ) && ! in_array( $role, $hide, true );
				$here = '<span class="lb-chip ' . ( $yes ? 'lb-chip--ok' : 'lb-chip--plain' ) . '">' . esc_html( $yes ? sprintf( /* translators: %s: page type such as "service pages" */ __( 'Shows on %s', 'launch-builder' ), self::role_noun( $role ) ) : sprintf( /* translators: %s: page type such as "service pages" */ __( 'Hidden on %s', 'launch-builder' ), self::role_noun( $role ) ) ) . '</span>';
			} else {
				$here = '<span class="lb-chip lb-chip--plain">' . esc_html__( 'Rules set in Kadence', 'launch-builder' ) . '</span>';
			}
			$edit = ! empty( $it['id'] ) ? self::edit_link( (int) $it['id'] ) : '';
			$out .= '<li><span class="lb-slot__tile lb-slot__tile--sm">' . self::icon( 'popup' ) . '</span><div class="lb-convlist__body"><b>' . esc_html( (string) ( $it['title'] ?? '' ) ) . '</b><span>' . esc_html( ( $types[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) ) . ( isset( $trig[ $it['trigger'] ?? '' ] ) ? ' · ' . $trig[ $it['trigger'] ] : '' ) ) . '</span><span class="lb-convlist__chips"><span class="lb-chip lb-chip--' . esc_attr( $chip[0] ) . '">' . esc_html( $chip[1] ) . '</span>' . $here . '</span></div>' . ( $edit ? '<a class="lb-link" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'launch-builder' ) . '</a>' : '' ) . '</li>';
		}
		return $out . '</ul><p class="lb-note" style="margin:12px 0 0">' . esc_html__( 'Popups open on a trigger, so they are not part of the page preview.', 'launch-builder' ) . '</p></section>';
	}

	/* ------------------------------------------------------------------ writes */

	/** Capability and nonce for a hub action; refuses with 403. */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the shared designs.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private static function back( string $role, string $slot = '' ): void {
		wp_safe_redirect( self::url( [ 'tab' => 'placed', 'role' => $role ] ) . ( '' !== $slot ? '#lb-slot-' . $slot : '' ) );
		exit;
	}

	/**
	 * The map that sets one slot (for a role) to a value: false, 'theme' or a pattern slug.
	 *
	 * @param mixed $value
	 */
	private static function map_for( string $slot, string $role, $value, string $scope ): array {
		if ( 'crumbs' === $slot ) {
			return [ 'crumbs' => [ 'design' => $value ] ];
		}
		if ( in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$key = 'default' === $scope ? 'default' : $role;
			$out = [ $key => $value ];
			if ( 'default' !== $key && self::one_for_all( $slot ) ) {
				// The slot is one design for every page type (a bare design, not a map). Written as a map it would lose that:
				// the page types the standard map names would fall back to their standard designs. So write the old design
				// out for every other page type, and as the default.
				$was = Chrome::get()[ $slot ] ?? false;
				foreach ( Chrome::roles() as $r ) {
					if ( $r !== $key ) {
						$out[ $r ] = $was;
					}
				}
				$out['default'] = $was;
			}
			return [ $slot => $out ];
		}
		return [ $slot => $value ];
	}

	/** Is a page-type slot one design for every page type (a bare design or false) rather than a map of page types? */
	private static function one_for_all( string $slot ): bool {
		$v = Chrome::get()[ $slot ] ?? null;
		return ! is_array( $v ) || self::is_design_object( $v );
	}

	/** The map that puts a slot back as it was before map_for(). */
	private static function inverse_map( string $slot, string $role, string $scope, array $stored ): array {
		if ( 'crumbs' === $slot ) {
			$was = $stored['crumbs'] ?? null;
			if ( null === $was ) {
				return [ 'crumbs' => null ]; // Nothing was stored: the standard breadcrumbs again.
			}
			return [ 'crumbs' => [ 'design' => is_array( $was ) && array_key_exists( 'design', $was ) ? $was['design'] : ( Chrome::get()['crumbs']['design'] ?? false ) ] ];
		}
		if ( in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			if ( 'default' !== $scope && self::one_for_all( $slot ) ) {
				return [ $slot => array_key_exists( $slot, $stored ) ? $stored[ $slot ] : null ]; // Back to the whole slot as it was (one design for every page type, or nothing stored).
			}
			$key = 'default' === $scope ? 'default' : $role;
			$cur = $stored[ $slot ] ?? null;
			return [ $slot => [ $key => is_array( $cur ) && array_key_exists( $key, $cur ) ? $cur[ $key ] : null ] ];
		}
		return [ $slot => array_key_exists( $slot, $stored ) ? $stored[ $slot ] : null ];
	}

	/** The slots to compile for a change of $slot (the hero decides whether a template drops its own banner). @return string[] */
	private static function slots_for( string $slot ): array {
		return 'hero' === $slot ? [ 'hero', 'template' ] : [ $slot ];
	}

	/**
	 * Words for the result of an apply. Only what concerns the page types in $roles counts as a failure: other page types
	 * may still use designs this version does not have yet, which their own cards say.
	 *
	 * @param string[] $roles page types the change was for.
	 * @return array{0:string,1:string[]} kind (ok|warn), notes
	 */
	private static function report( array $res, array $roles ): array {
		$ap = (array) ( $res['applied'] ?? $res );
		$notes = [];
		$failed = false;
		foreach ( (array) ( $ap['compile_failed'] ?? [] ) as $f ) {
			if ( array_intersect( $roles, (array) ( $f['roles'] ?? [] ) ) ) {
				$failed = true;
				$notes[] = sprintf( /* translators: 1: slot name, 2: reason */ __( '%1$s: %2$s', 'launch-builder' ), self::slot_info( (string) ( $f['slot'] ?? '' ) )[0], (string) ( $f['error'] ?? '' ) );
			}
		}
		foreach ( (array) ( $ap['kept_edits'] ?? [] ) as $k ) {
			$notes[] = sprintf( /* translators: %s: design key */ __( 'Kept your edited version of %s.', 'launch-builder' ), (string) ( $k['key'] ?? '' ) );
		}
		foreach ( (array) ( $ap['skipped'] ?? [] ) as $k ) {
			$notes[] = (string) ( $k['why'] ?? '' );
		}
		return [ $failed ? 'warn' : 'ok', array_values( array_filter( $notes ) ) ];
	}

	/**
	 * The page types a change reaches: this one, or every one that follows the slot's default. Breadcrumbs reach every
	 * page type that shows them, the 404 content the not-found page and the notice bar every page type.
	 *
	 * @return string[]
	 */
	private static function affected( string $slot, string $role, string $scope ): array {
		if ( 'crumbs' === $slot ) {
			return array_values( array_intersect( self::roles(), (array) ( Chrome::get()['crumbs']['roles'] ?? [] ) ) );
		}
		if ( 'notfound_body' === $slot ) {
			return [ 'notfound' ];
		}
		if ( 'default' !== $scope && in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			return [ $role ];
		}
		$out = [];
		foreach ( self::roles() as $r ) {
			if ( 'default' === $scope ? self::follows_default( $slot, $r ) : true ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/** The page types that get exactly the design this page type gets in a slot, this one included. @return string[] */
	private static function covers( string $slot, string $role ): array {
		if ( in_array( $slot, [ 'notice', 'crumbs', 'notfound_body' ], true ) ) {
			return 'notice' === $slot ? self::roles() : self::affected( $slot, $role, 'role' );
		}
		$mine = wp_json_encode( Chrome::design( $slot, $role ) );
		$out = [];
		foreach ( self::roles() as $r ) {
			if ( '' === self::not_used( $slot, $r ) && wp_json_encode( Chrome::design( $slot, $r ) ) === $mine ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/** admin-post op "crumbs": choose the page types that show breadcrumbs (the design is changed with op "change"). */
	private static function apply_crumbs( string $role ): void {
		$posted = isset( $_POST['crumb_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['crumb_roles'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification -- guard() checked the nonce.
		$new = array_values( array_intersect( self::roles(), $posted ) ); // Only known page types, in the hub's order.
		$old = self::affected( 'crumbs', $role, 'role' );
		[ $name ] = self::slot_info( 'crumbs' );
		if ( $new === $old ) {
			self::set_flash( 'ok', sprintf( /* translators: %s: part of the page, "Breadcrumbs" */ __( '%s already show on exactly those page types.', 'launch-builder' ), $name ) );
			self::back( $role, 'crumbs' );
		}
		$stored = get_option( Chrome::OPT_MAP, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$was = $stored['crumbs'] ?? null;
		if ( null === $was ) {
			$inverse = [ 'crumbs' => null ];
		} else {
			$inverse = [ 'crumbs' => [ 'roles' => is_array( $was ) && array_key_exists( 'roles', $was ) ? $was['roles'] : $old ] ];
		}
		$res = Chrome::set_tool( [ 'map' => [ 'crumbs' => [ 'roles' => $new ] ], 'slots' => [ 'crumbs' ], 'replace_legacy' => true ] );
		if ( is_wp_error( $res ) ) {
			self::set_flash( 'bad', $res->get_error_message() );
			self::back( $role, 'crumbs' );
		}
		[ $kind, $notes ] = self::report( (array) $res, array_values( array_unique( array_merge( $new, $old ) ) ) );
		set_transient( self::undo_key(), [ 'slot' => 'crumbs', 'role' => $role, 'map' => $inverse, 'slots' => [ 'crumbs' ], 'snapshot' => (string) ( $res['snapshot'] ?? '' ) ], HOUR_IN_SECONDS );
		$msg = $new
			? sprintf( /* translators: %s: page types and their pages */ __( 'Breadcrumbs now show on: %s.', 'launch-builder' ), self::roles_text( $new, false ) )
			: __( 'Breadcrumbs are now off on every page type.', 'launch-builder' );
		if ( ! empty( $res['snapshot'] ) ) {
			$msg .= ' ' . sprintf( /* translators: %s: snapshot id */ __( 'A snapshot (%s) was saved first and is listed under Activity and safety.', 'launch-builder' ), (string) $res['snapshot'] );
		}
		self::set_flash( $kind, $msg . ( $notes ? ' ' . implode( ' ', $notes ) : '' ), true );
		self::back( $role, 'crumbs' );
	}

	/** admin-post: change a slot's design, or apply a slot. */
	public static function handle_apply(): void {
		self::guard( self::APPLY );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'change';
		$slot = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		if ( ! isset( Chrome::SLOTS[ $slot ] ) || ! in_array( $role, self::roles(), true ) ) {
			self::set_flash( 'bad', __( 'That slot or page type is not known.', 'launch-builder' ) );
			self::back( in_array( $role, self::roles(), true ) ? $role : 'home' );
		}
		[ $name ] = self::slot_info( $slot );
		$slots = self::slots_for( $slot );

		if ( 'crumbs' === $op && 'crumbs' === $slot ) {
			self::apply_crumbs( $role );
		}

		if ( 'apply' === $op ) {
			$res = Chrome::set_tool( [ 'map' => [], 'slots' => $slots, 'replace_legacy' => true ] );
			if ( is_wp_error( $res ) ) {
				self::set_flash( 'bad', $res->get_error_message() );
				self::back( $role, $slot );
			}
			[ $kind, $notes ] = self::report( (array) $res, [ $role ] );
			delete_transient( self::undo_key() );
			self::set_flash( $kind, sprintf( /* translators: %s: slot name */ __( '%s applied.', 'launch-builder' ), $name ) . ( $notes ? ' ' . implode( ' ', $notes ) : '' ) . ( ! empty( $res['snapshot'] ) ? ' ' . sprintf( /* translators: %s: snapshot id */ __( 'A snapshot (%s) was saved first and is listed under Activity and safety.', 'launch-builder' ), (string) $res['snapshot'] ) : '' ) );
			self::back( $role, $slot );
		}

		$raw = isset( $_POST['design'] ) ? sanitize_text_field( wp_unslash( $_POST['design'] ) ) : '';
		$scope = isset( $_POST['scope'] ) && 'default' === sanitize_key( wp_unslash( $_POST['scope'] ) ) && self::follows_default( $slot, $role ) ? 'default' : 'role';
		$allowed = array_keys( self::extra_choices( $slot ) );
		$index = Patterns::index();
		if ( '__none' === $raw && in_array( '__none', $allowed, true ) ) {
			$value = false;
		} elseif ( 'theme' === $raw && in_array( 'theme', $allowed, true ) ) {
			$value = 'theme';
		} elseif ( '' !== $raw && isset( $index[ $raw ] ) && $index[ $raw ]['slot'] === $slot ) {
			$value = $raw;
		} else {
			self::set_flash( 'bad', __( 'Choose one of the designs in the list.', 'launch-builder' ) );
			self::back( $role, $slot );
		}
		$status = self::slot_row( $slot, $role, [], [ 'post' => null ] )['status'];
		if ( Chrome::design( $slot, $role ) === $value && 'default' !== $scope && in_array( $status, [ 'live', 'none', 'theme' ], true ) ) {
			self::set_flash( 'ok', sprintf( /* translators: %s: slot name */ __( '%s already uses that design.', 'launch-builder' ), $name ) );
			self::back( $role, $slot );
		}

		$stored = get_option( Chrome::OPT_MAP, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$map = self::map_for( $slot, $role, $value, $scope );
		$inverse = self::inverse_map( $slot, $role, $scope, $stored );
		$reach = self::affected( $slot, $role, $scope );
		$res = Chrome::set_tool( [ 'map' => $map, 'slots' => $slots, 'replace_legacy' => true ] );
		if ( is_wp_error( $res ) ) {
			self::set_flash( 'bad', $res->get_error_message() );
			self::back( $role, $slot );
		}
		[ $kind, $notes ] = self::report( (array) $res, $reach );
		$what = false === $value ? __( 'off', 'launch-builder' ) : ( 'theme' === $value ? __( 'the theme\'s own', 'launch-builder' ) : self::title( (string) $value ) );
		if ( 'warn' === $kind ) {
			// The design did not compile: put the map back so the site keeps what it had.
			Chrome::set_tool( [ 'map' => $inverse, 'slots' => $slots ] );
			self::set_flash( 'bad', sprintf( /* translators: 1: slot name, 2: design name */ __( '%1$s was not changed: %2$s could not be placed.', 'launch-builder' ), $name, $what ) . ' ' . implode( ' ', $notes ) );
			self::back( $role, $slot );
		}
		set_transient( self::undo_key(), [ 'slot' => $slot, 'role' => $role, 'map' => $inverse, 'slots' => $slots, 'snapshot' => (string) ( $res['snapshot'] ?? '' ) ], HOUR_IN_SECONDS );
		// Say again what the change reached: the page types and their real pages.
		$msg = sprintf( /* translators: 1: slot name such as "Hero", 2: design name or "none", 3: page types and their pages */ __( '%1$s is now %2$s on: %3$s.', 'launch-builder' ), $name, false === $value || 'theme' === $value ? $what : '“' . $what . '”', 'notice' === $slot ? __( 'every page of your site', 'launch-builder' ) : self::roles_text( $reach, true ) );
		if ( in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$msg .= ' ' . ( 'default' === $scope ? __( 'Page types with a design of their own keep it.', 'launch-builder' ) : __( 'Other page types are not changed.', 'launch-builder' ) );
		}
		$own = self::own_text( $slot, $reach );
		if ( '' !== $own ) {
			$msg .= ' ' . $own;
		}
		if ( ! empty( $res['snapshot'] ) ) {
			$msg .= ' ' . sprintf( /* translators: %s: snapshot id */ __( 'A snapshot (%s) was saved first and is listed under Activity and safety.', 'launch-builder' ), (string) $res['snapshot'] );
		}
		self::set_flash( 'ok', $msg . ( $notes ? ' ' . implode( ' ', $notes ) : '' ), true );
		self::back( $role, $slot );
	}

	/** admin-post: undo the last change made from the hub (puts the previous design back and applies it). */
	public static function handle_undo(): void {
		self::guard( self::UNDO );
		$undo = get_transient( self::undo_key() );
		$role = is_array( $undo ) && in_array( $undo['role'] ?? '', self::roles(), true ) ? $undo['role'] : 'home';
		if ( ! is_array( $undo ) || empty( $undo['map'] ) || empty( $undo['slots'] ) ) {
			self::set_flash( 'warn', __( 'There is nothing to undo any more. Earlier snapshots are listed under Activity and safety.', 'launch-builder' ) );
			self::back( $role );
		}
		delete_transient( self::undo_key() );
		$res = Chrome::set_tool( [ 'map' => (array) $undo['map'], 'slots' => array_values( array_intersect( (array) $undo['slots'], array_keys( Chrome::SLOTS ) ) ) ] );
		if ( is_wp_error( $res ) ) {
			self::set_flash( 'bad', $res->get_error_message() );
			self::back( $role, (string) $undo['slot'] );
		}
		[ $kind, $notes ] = self::report( (array) $res, [ $role ] );
		self::set_flash( $kind, sprintf( /* translators: %s: slot name */ __( 'Undone: %s is back to what it was.', 'launch-builder' ), self::slot_info( (string) $undo['slot'] )[0] ) . ( $notes ? ' ' . implode( ' ', $notes ) : '' ) );
		self::back( $role, (string) $undo['slot'] );
	}

	/* ------------------------------------------------------------------ tab 2: pattern library */

	/** Pattern groups for the list. @return array<string,array{label:string,items:array}> */
	private static function groups(): array {
		Plugin::register_patterns();
		$cats = array_diff_key( Plugin::categories(), [ 'headers' => 1 ] ); // The registry's category list (faq, process, offers, templates...).
		$out = [];
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			if ( 0 !== strpos( $p['name'], 'launch-builder/' ) ) {
				continue;
			}
			foreach ( (array) ( $p['categories'] ?? [] ) as $c ) {
				$k = preg_replace( '/^launch-builder-/', '', $c );
				if ( isset( $cats[ $k ] ) ) {
					$out[ $k ]['label'] = $cats[ $k ];
					$out[ $k ]['items'][] = [ 'slug' => substr( $p['name'], 15 ), 'title' => $p['title'], 'desc' => $p['description'] ?? '' ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Where each pattern is used now: slug => [ { slot, roles[] } ] from the map (every slot and page type).
	 *
	 * @return array<string,array<string,string[]>> slug => slot => role slugs
	 */
	private static function usage(): array {
		$out = [];
		foreach ( self::ORDER as $slot ) {
			foreach ( self::roles() as $role ) {
				if ( '' !== self::not_used( $slot, $role ) ) {
					continue;
				}
				foreach ( self::describe( Chrome::design( $slot, $role ) )['slugs'] as $slug ) {
					$out[ $slug ][ $slot ][] = $role;
				}
			}
		}
		return $out;
	}

	private static function panel_library(): string {
		$groups = self::groups();
		$index = Patterns::index();
		$usage = self::usage();
		$cur = isset( $_GET['p'] ) ? sanitize_key( wp_unslash( $_GET['p'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$out = '<div class="lb-lib"><aside class="lb-panel lb-lib__side" aria-label="' . esc_attr__( 'Patterns', 'launch-builder' ) . '"><label class="lb-search">' . '<span class="screen-reader-text">' . esc_html__( 'Search patterns', 'launch-builder' ) . '</span>' . Dashboard::icon( 'search' ) . '<input type="search" id="lb-q" placeholder="' . esc_attr__( 'Search patterns', 'launch-builder' ) . '"></label><div class="lb-lib__list">';
		foreach ( $groups as $ck => $g ) {
			$out .= '<div class="lb-g"><h4><span>' . esc_html( $g['label'] ) . '</span><a href="#" class="lb-all" data-cat="' . esc_attr( $ck ) . '" data-title="' . esc_attr( $g['label'] ) . '">' . esc_html__( 'view all', 'launch-builder' ) . '</a></h4>';
			foreach ( $g['items'] as $it ) {
				$use = '';
				$first = '';
				foreach ( $usage[ $it['slug'] ] ?? [] as $slot => $roles ) {
					$use .= ( '' === $use ? '' : '; ' ) . self::slot_info( $slot )[0] . ' (' . implode( ', ', array_map( [ __CLASS__, 'role_label' ], $roles ) ) . ')';
					$first = '' === $first ? $roles[0] : $first;
				}
				$meta = $index[ $it['slug'] ] ?? [];
				$out .= '<a href="' . esc_url( self::url( [ 'tab' => 'library', 'p' => $it['slug'] ] ) ) . '" class="lb-it' . ( $it['slug'] === $cur ? ' on' : '' ) . '" data-cat="' . esc_attr( $ck ) . '" data-slug="' . esc_attr( $it['slug'] ) . '" data-title="' . esc_attr( $it['title'] ) . '" data-desc="' . esc_attr( $it['desc'] ) . '" data-slot="' . esc_attr( (string) ( $meta['slot'] ?? '' ) ) . '" data-roles="' . esc_attr( implode( ',', (array) ( $meta['roles'] ?? [] ) ) ) . '" data-used="' . esc_attr( $use ) . '" data-used-url="' . esc_attr( '' !== $first ? self::url( [ 'tab' => 'placed', 'role' => $first ] ) : '' ) . '">' . esc_html( $it['title'] ) . '</a>';
			}
			$out .= '</div>';
		}
		if ( ! $groups ) {
			$out .= '<p class="lb-empty">' . esc_html__( 'No patterns are registered. Kadence Blocks has to be active for the patterns to load.', 'launch-builder' ) . '</p>';
		}
		$out .= '</div></aside><section class="lb-panel lb-lib__main" aria-labelledby="lb-t"><div class="lb-panel__head"><h2 id="lb-t">' . esc_html__( 'Pattern library', 'launch-builder' ) . '</h2></div><p class="lb-lib__desc" id="lb-d">' . esc_html__( 'Every pattern, drawn with your colours and Business Info. Click a group to load it, then click a pattern to jump to it.', 'launch-builder' ) . '</p><p class="lb-lib__used" id="lb-used" hidden></p>';
		$out .= self::stage( [
			'id'      => 'lb-stage-lib',
			'title'   => __( 'Pattern preview', 'launch-builder' ),
			'open'    => '#',
			'default' => 1440,
			'widths'  => [ __( 'Desktop', 'launch-builder' ) => 1440, __( 'Tablet', 'launch-builder' ) => self::WIDTHS['tablet'], __( 'Mobile', 'launch-builder' ) => self::WIDTHS['mobile'] ],
			'loading' => __( 'Rendering this group (the first view takes a few seconds, later views are instant)…', 'launch-builder' ),
		] );
		return $out . '</section></div>';
	}
}
