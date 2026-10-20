<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Menus and the block header: Kadence navigation posts built from items, and set-header (a header pattern published as
 * the Kadence block header with the menu inside).
 *
 * Live menus: a parent link (kadence/navigation-link) carries marker classes (see markers()) saying what its children
 * are; Nav writes those children as kadence/navigation-link blocks of kind 'post-type' (type + id, so Kadence prints the
 * entry's current permalink) with a deterministic uniqueID and the class lb-auto-child. Only lb-auto-child children are
 * ever rewritten (other children stay where they are) and links with isMegaMenu are left alone (a mega menu panel is
 * the administrator's design). Entries marks Nav dirty when something a menu shows changes (title, slug, status, order,
 * parent, or where a "View all" child points); flush() then rebuilds every marked menu
 * and writes only those whose children differ. LB tools that change entries call flush() before they answer (result
 * menus_updated); otherwise it runs after the REST callback, before the response or redirect of the request, with
 * shutdown as the last fallback.
 */
final class Nav {

	/** @var bool something menus show changed in this request. */
	private static $dirty = false;

	/** @var bool the shutdown fallback is registered. */
	private static $hooked = false;

	/** @var array<string,array<string,string>> marker renames waiting for flush(): kind (parent) => old path => new path. */
	private static $renames = [];

	public static function boot(): void {
		// After every REST callback (also internal requests such as the rest-request tool's, which never reach
		// rest_post_dispatch), and before Cache's own flush (999) so the purge a menu write asks for goes out with the response.
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'flush_through' ], 990 );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'flush_through' ], 990 );
		add_filter( 'wp_redirect', [ __CLASS__, 'flush_through' ], 990 );
	}

	public static function define_tools(): void {
		Abilities::add( 'set-navigation', 'Create or update a navigation menu', 'Kadence navigation menu from items [{label, url, children:[...]}]. key identifies it so re-running updates it (main-menu is the one the header shows). Live items: an item with auto "services" or "areas" gets its children from the entries and keeps them up to date when services or areas are added, renamed, reordered or removed: the top-level ones, or with parent (a service or area slug or path) that one\'s children. depth 2 adds each child\'s own children as a second level (sub-services under their service). limit = how many (default 12, 0 = all; a "View all" link to the hub or the parent follows when there are more). Children you list yourself stay. Returns the navigation post id.',
			[ 'properties' => [ 'key' => [ 'type' => 'string' ], 'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ], 'required' => [ 'items' ] ], [ __CLASS__, 'set_navigation' ], 'edit_theme_options' );

		Abilities::add( 'set-header', 'Set the site header from a header pattern', 'Applies a header pattern (list-patterns piece header; the Design hub does this when you choose a header): fills slots, builds the menu from nav items (or keeps the main menu), publishes it as the Kadence block header and switches the site to it.',
			[ 'properties' => [
				'pattern' => [ 'type' => 'string' ],
				'nav'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Menu items [{label,url,children}]' ],
				'slots'   => [ 'type' => 'object' ],
				'replace' => [ 'type' => 'object' ],
			], 'required' => [ 'pattern' ] ], [ __CLASS__, 'set_header' ], 'edit_theme_options' );
	}

	/* ------------------------------------------------------------------ live menus */

	/**
	 * Marker classes (className on a kadence/navigation-link) and what they mean. {slug}, {path} and {n} are
	 * placeholders; a path's "/" is written "--" (slugs never contain a double dash).
	 *
	 * @return array<string,string> class => meaning
	 */
	public static function markers(): array {
		return [
			'lb-auto-services'          => 'Children are the top-level services (menu_order, then title).',
			'lb-auto-areas'             => 'Children are the top-level service areas.',
			'lb-auto-parent-{path}'     => 'Children are the children of that service or area instead.',
			'lb-auto-depth-2'           => 'Each child also lists its own children (a second dropdown level).',
			'lb-auto-limit-{n}'         => 'At most n children (plus a "View all" link to the hub).',
			'lb-auto-child'             => 'A generated child item; only these are replaced on rebuild.',
			'lb-auto-viewall'           => 'The generated "View all" child (with lb-auto-child).',
		];
	}

	/** Something menus show changed: flush before this request ends. */
	public static function mark_dirty(): void {
		self::$dirty = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ __CLASS__, 'flush_quietly' ], -10 ); // Before Cache's shutdown flush (0).
		}
	}

	/** A service or area path changed: markers naming the old one follow it (kind parent). */
	public static function rename( string $kind, string $old, string $new ): void {
		if ( '' !== $old && $old !== $new && 'parent' === $kind ) {
			self::$renames[ $kind ][ $old ] = $new;
			self::mark_dirty();
		}
	}

	/** Filter callback (REST responses, redirects): flush, then pass the value on unchanged. */
	public static function flush_through( $value ) {
		self::flush_quietly();
		return $value;
	}

	/** flush() that never breaks the request it runs in. */
	public static function flush_quietly(): void {
		try {
			self::flush();
		} catch ( \Throwable $e ) {
			self::$dirty = false;
		}
	}

	/**
	 * Rebuild the generated children of every marked menu item now (when something changed, or always with $force) and
	 * write the menus whose children differ.
	 *
	 * @return int[] ids of the kadence_navigation posts that changed
	 */
	public static function flush( bool $force = false ): array {
		if ( ! $force && ! self::$dirty ) {
			return [];
		}
		self::$dirty = false;
		Entries::forget();
		$changed = [];
		foreach ( self::nav_ids() as $id ) {
			if ( self::rebuild( $id ) ) {
				$changed[] = $id;
			}
		}
		self::$renames = [];
		if ( $changed ) {
			Cache::purge_later();
		}
		return $changed;
	}

	/** kadence_navigation posts holding marker classes. */
	private static function nav_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY ID", 'kadence_navigation', '%' . $wpdb->esc_like( 'lb-auto-' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	/** Rebuild one navigation post's auto children; writes it only when they changed. @return bool written */
	public static function rebuild( int $nav_id ): bool {
		$post = get_post( $nav_id );
		if ( ! $post || 'kadence_navigation' !== $post->post_type || false === strpos( $post->post_content, 'lb-auto-' ) ) {
			return false;
		}
		$blocks = parse_blocks( $post->post_content );
		$seed = (string) get_post_meta( $nav_id, '_lb_key', true );
		if ( ! self::walk( $blocks, '' !== $seed ? $seed : (string) $nav_id ) ) {
			return false;
		}
		// A system write: only the generated children changed (escaped labels, permalinks), so the rest of the menu (a mega
		// menu panel an administrator wrote) must not go through kses because an editor's save triggered the rebuild.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			$r = wp_update_post( [ 'ID' => $nav_id, 'post_content' => wp_slash( serialize_blocks( $blocks ) ) ], true );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		return ! is_wp_error( $r ) && $r;
	}

	/**
	 * Walk a block list, filling marked links. $ancestors = post ids the enclosing links point at (Kadence refuses to
	 * render a link whose id is already being rendered above it).
	 *
	 * @return bool something changed
	 */
	private static function walk( array &$blocks, string $seed, array $ancestors = [] ): bool {
		$changed = false;
		foreach ( $blocks as &$b ) {
			if ( ! empty( $b['attrs']['isMegaMenu'] ) || self::is_auto_child( $b ) ) {
				continue; // A mega menu panel is the admin's design; generated children have no children of their own.
			}
			$is_link = 'kadence/navigation-link' === ( $b['blockName'] ?? '' );
			$here = $is_link && ! empty( $b['attrs']['id'] ) ? array_merge( $ancestors, [ (int) $b['attrs']['id'] ] ) : $ancestors;
			if ( $is_link && false !== strpos( (string) ( $b['attrs']['className'] ?? '' ), 'lb-auto-' ) ) {
				if ( self::apply_renames( $b ) ) {
					$changed = true;
				}
				$spec = self::spec( (string) ( $b['attrs']['className'] ?? '' ) );
				if ( $spec && self::fill( $b, $spec, $seed, $here ) ) {
					$changed = true;
				}
			}
			if ( ! empty( $b['innerBlocks'] ) && self::walk( $b['innerBlocks'], $seed, $here ) ) {
				$changed = true;
			}
		}
		return $changed;
	}

	private static function classes( string $class_name ): array {
		return array_values( array_filter( preg_split( '/\s+/', trim( $class_name ) ) ?: [] ) );
	}

	private static function is_auto_child( array $b ): bool {
		return 'kadence/navigation-link' === ( $b['blockName'] ?? '' ) && in_array( 'lb-auto-child', self::classes( (string) ( $b['attrs']['className'] ?? '' ) ), true );
	}

	/**
	 * What a parent's markers ask for: {type service|area, parent (path), depth 1|2, limit} or null. A menu from before v2
	 * may say lb-auto-category-{slug}: its category became a parent service with that slug.
	 */
	public static function spec( string $class_name ): ?array {
		$s = [ 'type' => '', 'parent' => '', 'depth' => 1, 'limit' => 0 ];
		foreach ( self::classes( $class_name ) as $c ) {
			if ( 'lb-auto-services' === $c && '' === $s['type'] ) {
				$s['type'] = 'service';
			} elseif ( 'lb-auto-areas' === $c && '' === $s['type'] ) {
				$s['type'] = 'area';
			} elseif ( preg_match( '/^lb-auto-category-(.+)$/', $c, $m ) ) {
				$s['parent'] = $m[1];
				$s['type'] = 'service';
			} elseif ( preg_match( '/^lb-auto-parent-(.+)$/', $c, $m ) ) {
				$s['parent'] = str_replace( '--', '/', $m[1] );
			} elseif ( 'lb-auto-depth-2' === $c ) {
				$s['depth'] = 2;
			} elseif ( preg_match( '/^lb-auto-limit-(\d+)$/', $c, $m ) ) {
				$s['limit'] = (int) $m[1];
			}
		}
		if ( '' === $s['type'] && '' !== $s['parent'] ) {
			$s['type'] = 'area'; // Menus from before v2: a parent marker alone meant an area.
		}
		return '' === $s['type'] ? null : $s;
	}

	/** The marker classes for an auto item ($limit 0 = all). */
	public static function marker_class( string $auto, string $parent_path = '', int $limit = 0, int $depth = 1 ): string {
		$c = 'areas' === $auto || 'area' === $auto ? [ 'lb-auto-areas' ] : [ 'lb-auto-services' ];
		if ( '' !== trim( $parent_path, '/' ) ) {
			$c[] = 'lb-auto-parent-' . implode( '--', array_map( 'sanitize_title', explode( '/', trim( $parent_path, '/' ) ) ) );
		}
		if ( $depth > 1 ) {
			$c[] = 'lb-auto-depth-2';
		}
		if ( $limit > 0 ) {
			$c[] = 'lb-auto-limit-' . $limit;
		}
		return implode( ' ', $c );
	}

	/** Rewrite parent markers that name a moved service or area path. */
	private static function apply_renames( array &$b ): bool {
		if ( ! self::$renames ) {
			return false;
		}
		$classes = self::classes( (string) ( $b['attrs']['className'] ?? '' ) );
		$out = [];
		foreach ( $classes as $c ) {
			if ( preg_match( '/^lb-auto-parent-(.+)$/', $c, $m ) ) {
				$path = str_replace( '--', '/', $m[1] );
				foreach ( (array) ( self::$renames['parent'] ?? [] ) as $old => $new ) {
					if ( $path === $old || 0 === strpos( $path, $old . '/' ) ) {
						$c = 'lb-auto-parent-' . str_replace( '/', '--', $new . substr( $path, strlen( $old ) ) );
						break;
					}
				}
			}
			$out[] = $c;
		}
		if ( $out === $classes ) {
			return false;
		}
		$b['attrs']['className'] = implode( ' ', $out );
		return true;
	}

	/** Plain label text as Kadence stores it (entities kept escaped, so kses never changes it and nothing churns). */
	private static function label( string $title ): string {
		return esc_html( wp_strip_all_tags( $title ) );
	}

	/**
	 * A generated child: kind post-type + type + id (Kadence prints the current permalink); an existing child for the same
	 * entry keeps its uniqueID and styling. A child pointing where an enclosing link points is written as a custom link
	 * (url only): Kadence would refuse to render a link whose id is already being rendered above it. $kids: its own
	 * generated children (depth 2).
	 */
	private static function child( \WP_Post $p, string $key, ?array $old, array $ancestors = [], array $kids = [] ): array {
		$attrs = $old ? (array) ( $old['attrs'] ?? [] ) : [ 'uniqueID' => 'lb' . substr( md5( $key . '|' . $p->ID ), 0, 8 ) ];
		$classes = array_diff( self::classes( (string) ( $attrs['className'] ?? '' ) ), [ 'lb-auto-child', 'lb-auto-viewall' ] );
		$attrs = array_merge( $attrs, [ 'label' => self::label( $p->post_title ), 'url' => (string) get_permalink( $p ), 'kind' => 'post-type', 'type' => $p->post_type, 'id' => (int) $p->ID, 'className' => trim( 'lb-auto-child ' . implode( ' ', $classes ) ) ] );
		if ( in_array( (int) $p->ID, $ancestors, true ) ) {
			$attrs['kind'] = 'custom';
			unset( $attrs['type'], $attrs['id'] );
		}
		$inner = self::inner_content( [], count( $kids ) );
		return [ 'blockName' => 'kadence/navigation-link', 'attrs' => $attrs, 'innerBlocks' => $kids, 'innerHTML' => implode( '', array_filter( $inner, 'is_string' ) ), 'innerContent' => $inner ];
	}

	/** The post a spec's parent path names (a service or an area), 0 when none. */
	private static function parent_id( array $spec ): int {
		if ( '' === $spec['parent'] ) {
			return 0;
		}
		return 'service' === $spec['type'] ? Entries::service_id( $spec['parent'] ) : Entries::area_id( $spec['parent'] );
	}

	/** Where "View all" goes for a spec: the parent service or area, else the hub. ?\WP_Post */
	private static function view_all_target( array $spec ): ?\WP_Post {
		$id = self::parent_id( $spec );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			$id = Roles::hubs()[ 'service' === $spec['type'] ? 'services' : 'areas' ];
		}
		$p = $id ? get_post( $id ) : null;
		return $p && 'publish' === $p->post_status ? $p : null;
	}

	/** The comparable shape of a generated child (what it shows and where it points, with its own children). */
	private static function sig( array $b ): string {
		$a = (array) ( $b['attrs'] ?? [] );
		return wp_json_encode( [ (int) ( $a['id'] ?? 0 ), (string) ( $a['label'] ?? '' ), (string) ( $a['url'] ?? '' ), (string) ( $a['kind'] ?? '' ), (string) ( $a['type'] ?? '' ), in_array( 'lb-auto-viewall', self::classes( (string) ( $a['className'] ?? '' ) ), true ), array_map( [ __CLASS__, 'sig' ], (array) ( $b['innerBlocks'] ?? [] ) ) ] );
	}

	/** Replace a marked link's generated children. @return bool changed */
	private static function fill( array &$b, array $spec, string $seed, array $ancestors = [] ): bool {
		$limit = (int) $spec['limit'];
		$q = [ 'limit' => $limit > 0 ? $limit + 1 : 0 ];
		if ( '' !== $spec['parent'] ) {
			$pid = self::parent_id( $spec );
			$posts = $pid ? Entries::query( $spec['type'], $q + [ 'parent' => $pid ] ) : [];
		} else {
			$posts = Entries::query( $spec['type'], $q + [ 'parent' => 'top' ] );
		}
		$more = $limit > 0 && count( $posts ) > $limit;
		if ( $more ) {
			$posts = array_slice( $posts, 0, $limit );
		}
		// uniqueIDs of new children: the menu, the parent link (its uniqueID, so two auto links never share child IDs), the post.
		$puid = (string) ( $b['attrs']['uniqueID'] ?? '' );
		$key = $seed . '|' . ( '' !== $puid ? $puid : $spec['type'] . '|' . $spec['parent'] );
		$kids = (array) ( $b['innerBlocks'] ?? [] );
		$old = [];
		$manual = [];
		$pos = null;
		foreach ( $kids as $k ) {
			if ( self::is_auto_child( $k ) ) {
				$old[] = $k;
				$pos = null === $pos ? count( $manual ) : $pos;
			} else {
				$manual[] = $k;
			}
		}
		$by_id = [];
		foreach ( $old as $k ) {
			$id = (int) ( $k['attrs']['id'] ?? 0 );
			if ( $id && ! in_array( 'lb-auto-viewall', self::classes( (string) ( $k['attrs']['className'] ?? '' ) ), true ) ) {
				$by_id[ $id ] = $k;
			}
		}
		$want = [];
		foreach ( $posts as $p ) {
			$kids = [];
			if ( $spec['depth'] > 1 ) { // Second level: the entry's own children, in full.
				$old_kids = [];
				foreach ( (array) ( ( $by_id[ (int) $p->ID ] ?? [] )['innerBlocks'] ?? [] ) as $k ) {
					$old_kids[ (int) ( $k['attrs']['id'] ?? 0 ) ] = $k;
				}
				foreach ( Entries::query( $spec['type'], [ 'parent' => (int) $p->ID ] ) as $c ) {
					$kids[] = self::child( $c, $key . '|' . $p->ID, $old_kids[ (int) $c->ID ] ?? null, array_merge( $ancestors, [ (int) $p->ID ] ) );
				}
			}
			$want[] = self::child( $p, $key, $by_id[ (int) $p->ID ] ?? null, $ancestors, $kids );
		}
		$target = $more ? self::view_all_target( $spec ) : null;
		if ( $target ) {
			$va = self::child( $target, $key . '|all', null, $ancestors );
			$va['attrs']['label'] = self::label( 'service' === $spec['type'] ? __( 'View all services', 'launch-builder' ) : __( 'View all areas', 'launch-builder' ) );
			$va['attrs']['className'] = 'lb-auto-child lb-auto-viewall';
			foreach ( $old as $k ) { // Keep the old "View all" item's uniqueID and styling.
				if ( in_array( 'lb-auto-viewall', self::classes( (string) ( $k['attrs']['className'] ?? '' ) ), true ) ) {
					$keep = array_diff_key( (array) $k['attrs'], array_flip( [ 'label', 'url', 'kind', 'type', 'id', 'className' ] ) );
					$va['attrs'] = array_merge( $keep, $va['attrs'], isset( $keep['uniqueID'] ) ? [ 'uniqueID' => $keep['uniqueID'] ] : [] );
				}
			}
			$want[] = $va;
		}
		if ( array_map( [ __CLASS__, 'sig' ], $old ) === array_map( [ __CLASS__, 'sig' ], $want ) ) {
			return false;
		}
		$pos = null === $pos ? count( $manual ) : $pos;
		$new = array_merge( array_slice( $manual, 0, $pos ), $want, array_slice( $manual, $pos ) );
		$b['innerBlocks'] = $new;
		$b['innerContent'] = self::inner_content( (array) ( $b['innerContent'] ?? [] ), count( $new ) );
		$b['innerHTML'] = implode( '', array_filter( $b['innerContent'], 'is_string' ) );
		return true;
	}

	/** innerContent for $n inner blocks, keeping the block's own leading and trailing markup. */
	private static function inner_content( array $old, int $n ): array {
		if ( 0 === $n ) {
			return [];
		}
		$first = isset( $old[0] ) && is_string( $old[0] ) ? $old[0] : "\n";
		$last = $old && is_string( end( $old ) ) && count( $old ) > 1 ? (string) end( $old ) : "\n";
		$out = [ $first ];
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = null;
			$out[] = $i < $n - 1 ? "\n" : $last;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ navigation + header */

	/**
	 * Navigation-link blocks for items [{label, url, children, auto, parent, depth, limit, post_id}]. uniqueIDs are
	 * derived from the menu key and the item's place, so re-running set-navigation does not churn Kadence's CSS.
	 */
	private static function nav_blocks( array $items, string $key = '', string $path = '', array &$warnings = [] ): array {
		$out = [];
		foreach ( array_values( $items ) as $i => $it ) {
			$it = (array) $it;
			$label = sanitize_text_field( is_scalar( $it['label'] ?? '' ) ? (string) ( $it['label'] ?? '' ) : '' );
			if ( '' === $label ) {
				continue;
			}
			$here = $path . '/' . $i;
			$kids = ! empty( $it['children'] ) && is_array( $it['children'] ) ? self::nav_blocks( $it['children'], $key, $here, $warnings ) : [];
			$attrs = [ 'label' => $label, 'url' => esc_url_raw( is_scalar( $it['url'] ?? '' ) ? (string) ( $it['url'] ?? '#' ) : '#' ) ?: '#', 'kind' => 'custom', 'uniqueID' => 'lb' . substr( md5( $key . '|' . $here . '|' . $label ), 0, 8 ) ];
			$target = ! empty( $it['post_id'] ) ? get_post( (int) $it['post_id'] ) : null;
			$auto = in_array( $it['auto'] ?? '', [ 'services', 'areas' ], true ) ? (string) $it['auto'] : '';
			if ( $auto ) {
				$parent_path = '';
				$parent = $it['parent'] ?? ( 'services' === $auto ? ( $it['category'] ?? '' ) : '' ); // category: briefs from before v2.
				if ( '' !== $parent && is_scalar( $parent ) ) {
					$pid = 'services' === $auto ? Entries::service_id( $parent ) : Entries::area_id( $parent );
					$parent_path = $pid ? (string) get_page_uri( $pid ) : trim( (string) $parent, '/' );
					if ( ! $pid ) {
						$warnings[] = $label . ': no ' . ( 'services' === $auto ? 'service' : 'service area' ) . ' "' . sanitize_text_field( (string) $parent ) . '" yet (the item stays empty until it exists).';
					} elseif ( ! $target && empty( $it['url'] ) ) {
						$target = get_post( $pid );
					}
				}
				if ( ! $target && empty( $it['url'] ) ) { // No link given: the hub.
					$hub = Roles::hubs()[ 'services' === $auto ? 'services' : 'areas' ];
					$target = $hub ? get_post( $hub ) : null;
					if ( ! $target ) {
						$warnings[] = $label . ': no url and no ' . ( 'services' === $auto ? 'services' : 'areas' ) . ' hub page (set-page-role), so the item itself links to "#".';
					}
				}
				$limit = isset( $it['limit'] ) && is_numeric( $it['limit'] ) ? max( 0, (int) $it['limit'] ) : 12;
				$depth = isset( $it['depth'] ) && 2 === (int) $it['depth'] ? 2 : 1;
				$attrs['className'] = self::marker_class( $auto, $parent_path, $limit, $depth );
			}
			if ( $target instanceof \WP_Post && 'publish' === $target->post_status ) {
				$attrs = array_merge( $attrs, [ 'url' => (string) get_permalink( $target ), 'kind' => 'post-type', 'type' => $target->post_type, 'id' => (int) $target->ID ] );
			}
			$out[] = [
				'blockName'    => 'kadence/navigation-link',
				'attrs'        => $attrs,
				'innerBlocks'  => $kids,
				'innerHTML'    => $kids ? str_repeat( "\n", count( $kids ) + 1 ) : '',
				'innerContent' => self::inner_content( [], count( $kids ) ),
			];
		}
		return $out;
	}

	public static function set_navigation( $input ) {
		$input = (array) $input;
		$items = (array) ( $input['items'] ?? [] );
		$key = sanitize_key( $input['key'] ?? 'main-menu' );
		$key = '' !== $key ? $key : 'main-menu';
		$warnings = [];
		$kids = self::nav_blocks( $items, 'nav-' . $key, '', $warnings );
		if ( ! $kids ) {
			return new \WP_Error( 'lb_empty', 'No valid items (each needs a label).', [ 'status' => 400 ] );
		}
		$root = [ [ 'blockName' => 'kadence/navigation', 'attrs' => [], 'innerBlocks' => $kids, 'innerHTML' => '', 'innerContent' => array_merge( [ "\n" ], array_fill( 0, count( $kids ), null ), [ "\n" ] ) ] ];
		self::walk( $root, 'nav-' . $key ); // Auto children are written with the menu (one write).
		$auto = substr_count( serialize_blocks( $root ), 'lb-auto-child' );
		$id = Abilities::upsert_post( 'kadence_navigation', 'nav-' . $key, 'Launch Builder: ' . $key, serialize_blocks( $root ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// Navigation styles live on the menu post, shared by desktop and the mobile slide-out.
		// Set responsive defaults only on first creation; keep subsequent editor changes.
		add_post_meta( $id, '_kad_navigation_orientationTablet', 'vertical', true );
		add_post_meta( $id, '_kad_navigation_orientationMobile', 'vertical', true );
		Cache::purge_later();
		$out = [ 'navigation_id' => $id, 'items' => count( $kids ), 'auto_items' => $auto ];
		if ( $warnings ) {
			$out['warnings'] = $warnings;
		}
		return $out;
	}

	private static function set_nav_id( array &$blocks, int $nav_id ): void {
		foreach ( $blocks as &$b ) {
			if ( 'kadence/navigation' === ( $b['blockName'] ?? '' ) ) {
				$b['attrs']['id'] = $nav_id;
				unset( $b['attrs']['templateKey'] );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::set_nav_id( $b['innerBlocks'], $nav_id );
			}
		}
	}

	/** The main menu (set-navigation key main-menu), 0 when there is none yet. */
	public static function main_menu_id(): int {
		$f = post_type_exists( 'kadence_navigation' ) ? get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'publish', 'meta_key' => '_lb_key', 'meta_value' => 'nav-main-menu', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ) : [];
		return $f ? (int) $f[0] : 0;
	}

	public static function set_header( $input ) {
		$index = Patterns::index();
		$slug = sanitize_key( $input['pattern'] ?? '' );
		if ( ! isset( $index[ $slug ] ) || 'header' !== ( $index[ $slug ]['piece'] ?? '' ) ) {
			return new \WP_Error( 'lb_no_header', 'Unknown header pattern. Use list-patterns with piece header.', [ 'status' => 404 ] );
		}
		if ( ! Plugin::kadence_theme_active() || ! post_type_exists( 'kadence_header' ) ) {
			return new \WP_Error( 'lb_no_kadence', 'The block header needs the Kadence theme and Kadence Blocks (Header Adv) to be active.', [ 'status' => 412 ] );
		}
		$snap = ! empty( $input['no_snapshot'] ) ? null : Abilities::snapshot( 'set-header', [ 'blocks_header', 'blocks_header_id' ] );
		$blocks = parse_blocks( (string) Patterns::content( $slug ) );
		[ $applied, $warnings ] = Patterns::fill( $blocks, (array) ( $input['slots'] ?? [] ), (array) ( $input['replace'] ?? [] ), (array) ( $input['vars'] ?? [] ) );
		$nav_id = 0;
		if ( ! empty( $input['nav'] ) ) {
			$nav = self::set_navigation( [ 'key' => 'main-menu', 'items' => (array) $input['nav'] ] );
			if ( is_wp_error( $nav ) ) {
				return $nav;
			}
			$nav_id = (int) $nav['navigation_id'];
			foreach ( (array) ( $nav['warnings'] ?? [] ) as $w ) {
				$warnings[] = 'nav: ' . $w;
			}
		}
		$nav_id = $nav_id ?: self::main_menu_id(); // No items given: the header shows the main menu.
		if ( $nav_id ) {
			self::set_nav_id( $blocks, $nav_id );
		}
		$id = Abilities::upsert_post( 'kadence_header', 'site-header', 'Launch Builder: site header', serialize_blocks( $blocks ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( (array) ( Plugin::pattern_extra( $slug )['header_meta'] ?? [] ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		update_post_meta( $id, '_lb_pattern', $slug );
		set_theme_mod( 'blocks_header', true );
		set_theme_mod( 'blocks_header_id', $id );
		Cache::purge_later();
		return [ 'header_id' => $id, 'navigation_id' => $nav_id, 'applied' => $applied, 'warnings' => $warnings, 'snapshot' => $snap, 'edit_url' => get_edit_post_link( $id, 'raw' ) ];
	}

	/* ------------------------------------------------------------------ playbook + audit */

	/** Module hook: how menus stay live. */
	public static function playbook( array &$pb ): void {
		$pb['live_menus'] = 'Menus never list services or areas by hand: give a set-navigation item auto "services" or auto "areas" (parent: a slug or path for that entry\'s children; depth 2 for a second level of sub-services) and limit (default 12, 0 = all). Its children follow the entries (added, renamed, reordered, removed) and link by post id; children you add yourself stay.';
	}

	/** Module hook: audit checks this module owns. */
	public static function audit_checks(): array {
		return [ 'menu_static_entry_url' => [ 'menus', [ __CLASS__, 'audit_static_links' ] ] ];
	}

	/** Menu links typed as URLs that point at a service or area (they break on renames; use auto items). */
	public static function audit_static_links( $args = [] ): array {
		global $wpdb;
		$out = [];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID LIMIT 200", 'kadence_navigation' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_map( 'intval', (array) $ids ) as $nid ) {
			$found = [];
			$scan = static function ( array $blocks ) use ( &$scan, &$found ) {
				foreach ( $blocks as $b ) {
					$a = (array) ( $b['attrs'] ?? [] );
					if ( 'kadence/navigation-link' === ( $b['blockName'] ?? '' ) && 'post-type' !== ( $a['kind'] ?? '' ) && ! empty( $a['url'] ) && '#' !== $a['url'] ) {
						$pid = url_to_postid( (string) $a['url'] );
						$pt = $pid ? get_post_type( $pid ) : '';
						if ( in_array( $pt, [ Entries::SERVICE, Entries::AREA ], true ) ) {
							$found[] = (string) ( $a['label'] ?? $a['url'] );
						}
					}
					if ( ! empty( $b['innerBlocks'] ) ) {
						$scan( $b['innerBlocks'] );
					}
				}
			};
			$scan( parse_blocks( (string) get_post_field( 'post_content', $nid ) ) );
			if ( $found ) {
				$out[] = [ 'severity' => 'warning', 'code' => 'menu_static_entry_url', 'object_type' => 'kadence_navigation', 'id' => $nid, 'url' => '', 'message' => sprintf( 'Menu "%s" links services or areas by typed URL (%s); they break when an entry is renamed and new entries never appear.', get_the_title( $nid ), implode( ', ', array_slice( $found, 0, 8 ) ) . ( count( $found ) > 8 ? '...' : '' ) ), 'fix' => 'set-navigation with auto items (auto services / areas).' ];
			}
		}
		// Classic menus (Appearance > Menus) are not kept in step: flag the ones listing services or areas.
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, t.meta_value AS type, o.meta_value AS object, u.meta_value AS url FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_menu_item_type' LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_menu_item_object' LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_menu_item_url' WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY p.ID LIMIT 500", 'nav_menu_item' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$menus = [];
		foreach ( (array) $items as $it ) {
			$entry = 'post_type' === $it->type && in_array( $it->object, [ Entries::SERVICE, Entries::AREA ], true );
			if ( ! $entry && 'custom' === $it->type && '' !== (string) $it->url && '#' !== $it->url ) {
				$pid = url_to_postid( (string) $it->url );
				$entry = $pid && in_array( get_post_type( $pid ), [ Entries::SERVICE, Entries::AREA ], true );
			}
			$in = $entry ? wp_get_object_terms( (int) $it->ID, 'nav_menu', [ 'fields' => 'ids' ] ) : [];
			if ( $in && ! is_wp_error( $in ) ) {
				foreach ( $in as $mid ) {
					$menus[ (int) $mid ] = ( $menus[ (int) $mid ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $menus as $mid => $n ) {
			$term = get_term( $mid, 'nav_menu' );
			$out[] = [ 'severity' => 'warning', 'code' => 'menu_static_entry_url', 'object_type' => 'nav_menu', 'id' => $mid, 'url' => '', 'message' => sprintf( 'Classic menu "%s" lists %d service/area links by hand; new, renamed or removed entries are not kept in step.', $term instanceof \WP_Term ? $term->name : (string) $mid, $n ), 'fix' => 'Use the Kadence block header with set-navigation auto items.' ];
		}
		return $out;
	}
}
