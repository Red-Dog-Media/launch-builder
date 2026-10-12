<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * export-brief: the site as a brief. Two exports of an unchanged site are identical (no ids, times or other noise), and a
 * dry run of build-from-brief on the export reports no changes. Page and entry text is not exported as pages hold
 * pattern markup: they carry content "keep" (the builder then leaves the stored text alone); include_content adds the text
 * of services and areas as Markdown (converted from the blocks, so it can differ in small ways from what was written).
 */
final class Brief_Export {

	/** @var array<string,int>|null permalink path => page id (menus). */
	private static $url_map = null;

	/** export-brief. */
	public static function tool( $input = [] ) {
		$input = (array) $input;
		$all = [ 'business', 'brand', 'seo', 'sitemap', 'entries', 'media', 'menus', 'chrome', 'urls', 'launch' ];
		$want = $all;
		if ( isset( $input['sections'] ) ) {
			if ( ! is_array( $input['sections'] ) || array_filter( $input['sections'], static function ( $v ) { return ! is_string( $v ); } ) ) {
				return new \WP_Error( 'lb_invalid_args', 'sections must be a list of section names.', [ 'status' => 400 ] );
			}
			$want = array_values( array_intersect( $all, $input['sections'] ) );
			if ( ! $want ) {
				return new \WP_Error( 'lb_sections', 'sections: ' . implode( ', ', $all ) . '.', [ 'status' => 400 ] );
			}
		}
		$content = ! empty( $input['include_content'] );
		$limit = max( 1, min( 300, (int) ( $input['limit'] ?? 150 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$out = [ 'version' => 1 ];
		$more = [];
		$totals = [];
		if ( in_array( 'business', $want, true ) ) {
			$out['business'] = self::business();
		}
		if ( in_array( 'brand', $want, true ) ) {
			$b = self::brand();
			if ( $b ) {
				$out['brand'] = $b;
			}
		}
		if ( in_array( 'seo', $want, true ) ) {
			$t = Compat::templates();
			ksort( $t );
			if ( $t ) {
				$out['seo'] = [ 'templates' => $t ];
			}
		}
		if ( in_array( 'sitemap', $want, true ) ) {
			$pages = self::pages();
			$totals['pages'] = count( $pages );
			$out['sitemap'] = [ 'pages' => array_slice( $pages, $offset, $limit ) ];
			if ( $offset + $limit < count( $pages ) ) {
				$more['pages'] = $offset + $limit;
			}
		}
		if ( in_array( 'entries', $want, true ) ) {
			$cats = self::categories();
			$svc = self::services( $content );
			$areas = self::areas( $content );
			$totals['services'] = count( $svc );
			$totals['areas'] = count( $areas );
			$out['entries'] = [ 'categories' => $cats, 'services' => array_slice( $svc, $offset, $limit ), 'areas' => array_slice( $areas, $offset, $limit ) ];
			if ( $offset + $limit < max( count( $svc ), count( $areas ) ) ) {
				$more['entries'] = $offset + $limit;
			}
		}
		if ( in_array( 'media', $want, true ) ) {
			$m = self::media();
			if ( $m ) {
				$out['media'] = $m;
			}
		}
		if ( in_array( 'menus', $want, true ) ) {
			$m = self::menus();
			if ( $m ) {
				$out['menus'] = $m;
			}
		}
		if ( in_array( 'chrome', $want, true ) ) {
			$c = self::chrome();
			if ( $c ) {
				$out['chrome'] = $c;
			}
		}
		if ( in_array( 'urls', $want, true ) && Modules::enabled( Permalinks::class ) ) {
			$out['urls'] = self::urls();
		}
		if ( in_array( 'launch', $want, true ) ) {
			$out['launch'] = [ 'indexing' => '0' !== (string) get_option( 'blog_public', '1' ) ];
		}
		$res = [ 'brief' => $out, 'totals' => $totals ];
		if ( $more ) {
			$res['next_offset'] = min( $more );
			$res['note'] = 'Some lists were cut at limit ' . $limit . '; call again with offset ' . min( $more ) . ' (and sections) for the rest.';
		}
		return $res;
	}

	/* ------------------------------------------------------------------ sections */

	private static function business(): array {
		$saved = (array) get_option( Company::OPT, [] );
		$fields = Company::fields();
		$out = [];
		foreach ( $saved as $k => $v ) {
			if ( ! isset( $fields[ $k ] ) || '' === $v || [] === $v || null === $v ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		$name = (string) ( $out['name'] ?? '' );
		$title = (string) get_option( 'blogname' );
		if ( '' !== $title && $title !== $name ) {
			$out['site_title'] = $title;
		}
		$tag = (string) get_option( 'blogdescription' );
		if ( '' !== $tag && $tag !== (string) ( $out['tagline'] ?? '' ) ) {
			$out['site_tagline'] = $tag;
		}
		ksort( $out );
		return $out;
	}

	private static function brand(): array {
		$out = [];
		$pal = [];
		foreach ( Brief_Build::palette_now() as $slug => $hex ) {
			if ( preg_match( '/^palette[1-9]$/', (string) $slug ) ) {
				$pal[ $slug ] = $hex;
			}
		}
		if ( $pal ) {
			ksort( $pal );
			$out['palette'] = $pal;
		}
		foreach ( [ 'headings' => 'heading_font', 'body' => 'base_font' ] as $role => $mod ) {
			$f = get_theme_mod( $mod, [] );
			if ( is_array( $f ) && ! empty( $f['family'] ) && 'inherit' !== strtolower( (string) $f['family'] ) && false === strpos( (string) $f['family'], 'apple-system' ) ) {
				$spec = [ 'family' => (string) $f['family'] ];
				if ( ! empty( $f['weight'] ) ) {
					$spec['weight'] = (string) $f['weight'];
				}
				$out['fonts'][ $role ] = $spec;
			}
		}
		$st = Style::get();
		if ( '' !== $st['preset'] || $st['tokens'] ) {
			$out['style'] = [ 'preset' => $st['preset'], 'tokens' => $st['tokens'] ];
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		$key = $logo ? (string) get_post_meta( $logo, '_lb_media_key', true ) : '';
		if ( '' !== $key ) {
			$out['logo'] = $key;
		}
		$saved = (array) get_option( Company::OPT, [] );
		$inv = (int) ( $saved['logo_inverse'] ?? 0 );
		$ikey = $inv ? (string) get_post_meta( $inv, '_lb_media_key', true ) : '';
		if ( '' !== $ikey ) {
			$out['logo_inverse'] = $ikey;
		}
		$icon = (int) get_option( 'site_icon' );
		$skey = $icon ? (string) get_post_meta( $icon, '_lb_media_key', true ) : '';
		if ( '' !== $skey ) {
			$out['site_icon'] = $skey;
		}
		ksort( $out );
		return $out;
	}

	/** Every page as a flat list, parents first, siblings by order then title. */
	private static function pages(): array {
		$posts = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => 1000, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
		$by_parent = [];
		foreach ( $posts as $p ) {
			$by_parent[ (int) $p->post_parent ][] = $p;
		}
		foreach ( $by_parent as $pid => $list ) {
			usort( $list, static function ( $a, $b ) {
				return [ (int) $a->menu_order, strtolower( $a->post_title ), (int) $a->ID ] <=> [ (int) $b->menu_order, strtolower( $b->post_title ), (int) $b->ID ];
			} );
			$by_parent[ $pid ] = $list;
		}
		$known = array_map( static function ( $p ) { return (int) $p->ID; }, $posts );
		$out = [];
		$walk = static function ( int $parent ) use ( &$walk, &$out, $by_parent, $known ) {
			foreach ( $by_parent[ $parent ] ?? [] as $p ) {
				$out[] = self::page( $p );
				$walk( (int) $p->ID );
			}
		};
		$walk( 0 );
		foreach ( $by_parent as $pid => $list ) { // Children whose parent is gone (trashed): at the top.
			if ( 0 !== $pid && ! in_array( $pid, $known, true ) ) {
				foreach ( $list as $p ) {
					$out[] = self::page( $p );
					$walk( (int) $p->ID );
				}
			}
		}
		return $out;
	}

	/** The key a page has in a brief. */
	public static function page_key( int $id ): string {
		$k = (string) get_post_meta( $id, '_lb_key', true );
		$p = get_post( $id );
		return '' !== $k ? $k : ( $p ? self::default_key( $p ) : 'page:' );
	}

	/** The key a brief gives a page, service or area that has no _lb_key: its type, then its path or slug. */
	public static function default_key( \WP_Post $p ): string {
		$uri = (string) get_page_uri( $p );
		$uri = '' !== $uri ? $uri : sanitize_title( $p->post_title );
		if ( Entries::AREA === $p->post_type ) {
			return 'area:' . $uri;
		}
		if ( Entries::SERVICE === $p->post_type ) {
			return 'svc:' . ( '' !== (string) $p->post_name ? $p->post_name : sanitize_title( $p->post_title ) );
		}
		return 'page:' . $uri;
	}

	private static function page( \WP_Post $p ): array {
		$id = (int) $p->ID;
		$status = in_array( $p->post_status, [ 'publish', 'private' ], true ) ? $p->post_status : 'draft';
		$row = [ 'key' => self::page_key( $id ), 'title' => $p->post_title, 'slug' => '' !== (string) $p->post_name ? $p->post_name : sanitize_title( $p->post_title ), 'status' => $status, 'order' => (int) $p->menu_order ];
		if ( $p->post_parent ) {
			$row['parent'] = self::page_key( (int) $p->post_parent );
		}
		$role = Brief_Build::role_of( $id );
		$row['role'] = $role;
		if ( 'category' === $role ) {
			$term = (int) ( Roles::catmap()[ $id ] ?? 0 );
			$t = $term ? get_term( $term, Entries::CAT ) : null;
			if ( $t instanceof \WP_Term ) {
				$row['category'] = $t->slug;
			}
		}
		$seo = [];
		foreach ( [ 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'keyword' => 'rank_math_focus_keyword' ] as $f => $mk ) {
			$v = (string) get_post_meta( $id, $mk, true );
			if ( '' !== $v ) {
				$seo[ $f ] = $v;
			}
		}
		$rob = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rob ) && $rob ) {
			$seo['robots'] = Compat::robots_list( $rob );
		}
		if ( $seo ) {
			$row['seo'] = $seo;
		}
		$row['content'] = 'keep';
		return $row;
	}

	private static function categories(): array {
		$terms = get_terms( [ 'taxonomy' => Entries::CAT, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
		$out = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			$row = [ 'name' => $t->name, 'slug' => $t->slug ];
			$page = (int) get_term_meta( $t->term_id, '_lb_page', true );
			if ( $page && get_post( $page ) ) {
				$row['page'] = self::page_key( $page );
			}
			$out[] = $row;
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );
		return $out;
	}

	private static function entry_posts( string $pt ): array {
		$posts = get_posts( [ 'post_type' => $pt, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => 1000, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
		return $posts;
	}

	/** Fields services and areas share. */
	private static function entry_common( \WP_Post $p, bool $content ): array {
		$id = (int) $p->ID;
		$row = [ 'title' => $p->post_title, 'slug' => '' !== (string) $p->post_name ? $p->post_name : sanitize_title( $p->post_title ), 'status' => 'publish' === $p->post_status ? 'publish' : 'draft', 'order' => (int) $p->menu_order ];
		if ( '' !== trim( (string) $p->post_excerpt ) ) {
			$row['summary'] = (string) $p->post_excerpt;
		}
		$icon = (string) get_post_meta( $id, '_lb_icon', true );
		if ( '' !== $icon ) {
			$row['icon'] = $icon;
		}
		$seo = [];
		foreach ( [ 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'keyword' => 'rank_math_focus_keyword' ] as $f => $mk ) {
			$v = (string) get_post_meta( $id, $mk, true );
			if ( '' !== $v ) {
				$seo[ $f ] = $v;
			}
		}
		if ( $seo ) {
			$row['seo'] = $seo;
		}
		$faqs = Brief_Build::faqs_raw( $id );
		if ( $faqs ) {
			$row['faqs'] = $faqs;
		}
		$thumb = (int) get_post_thumbnail_id( $id );
		$mkey = $thumb ? (string) get_post_meta( $thumb, '_lb_media_key', true ) : '';
		if ( '' !== $mkey ) {
			$row['image'] = $mkey;
		}
		if ( $content && '' !== trim( (string) $p->post_content ) ) {
			$row['markdown'] = self::markdown( (string) $p->post_content );
			$row['content'] = 'build';
		} else {
			$row['content'] = 'keep';
		}
		return $row;
	}

	private static function entry_key( \WP_Post $p ): string {
		$k = (string) get_post_meta( $p->ID, '_lb_key', true );
		return '' !== $k ? $k : self::default_key( $p );
	}

	private static function services( bool $content ): array {
		$out = [];
		foreach ( self::entry_posts( Entries::SERVICE ) as $p ) {
			$terms = wp_get_object_terms( $p->ID, Entries::CAT );
			$cats = is_wp_error( $terms ) ? [] : array_values( wp_list_pluck( $terms, 'slug' ) );
			sort( $cats );
			$row = [ 'key' => self::entry_key( $p ) ] + self::entry_common( $p, $content );
			if ( $cats ) {
				$row['categories'] = $cats;
			}
			$out[] = $row;
		}
		usort( $out, static function ( $a, $b ) { return [ $a['order'], strtolower( $a['title'] ), $a['key'] ] <=> [ $b['order'], strtolower( $b['title'] ), $b['key'] ]; } );
		return $out;
	}

	private static function areas( bool $content ): array {
		$posts = self::entry_posts( Entries::AREA );
		$by_parent = [];
		foreach ( $posts as $p ) {
			$by_parent[ (int) $p->post_parent ][] = $p;
		}
		foreach ( $by_parent as $pid => $list ) {
			usort( $list, static function ( $a, $b ) {
				return [ (int) $a->menu_order, strtolower( $a->post_title ), (int) $a->ID ] <=> [ (int) $b->menu_order, strtolower( $b->post_title ), (int) $b->ID ];
			} );
			$by_parent[ $pid ] = $list;
		}
		$known = array_map( static function ( $p ) { return (int) $p->ID; }, $posts );
		$out = [];
		$one = static function ( \WP_Post $p ) use ( $content ): array {
			$row = [ 'key' => self::entry_key( $p ) ] + self::entry_common( $p, $content );
			if ( $p->post_parent ) {
				$par = get_post( (int) $p->post_parent );
				$row['parent'] = $par ? self::entry_key( $par ) : '';
				if ( '' === $row['parent'] ) {
					unset( $row['parent'] );
				}
			}
			$svc = [];
			foreach ( array_map( 'intval', (array) get_post_meta( $p->ID, '_lb_services', false ) ) as $sid ) {
				$sp = $sid ? get_post( $sid ) : null;
				if ( $sp && Entries::SERVICE === $sp->post_type && 'trash' !== $sp->post_status ) {
					$svc[] = self::entry_key( $sp );
				}
			}
			sort( $svc );
			if ( $svc ) {
				$row['services'] = $svc;
			}
			return $row;
		};
		$walk = static function ( int $parent ) use ( &$walk, &$out, $by_parent, $one ) {
			foreach ( $by_parent[ $parent ] ?? [] as $p ) {
				$out[] = $one( $p );
				$walk( (int) $p->ID );
			}
		};
		$walk( 0 );
		foreach ( $by_parent as $pid => $list ) {
			if ( 0 !== $pid && ! in_array( $pid, $known, true ) ) {
				foreach ( $list as $p ) {
					$out[] = $one( $p );
					$walk( (int) $p->ID );
				}
			}
		}
		return $out;
	}

	private static function media(): array {
		$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lb_media_key', 'numberposts' => 300, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out = [];
		$logo = (int) get_theme_mod( 'custom_logo' );
		$icon = (int) get_option( 'site_icon' );
		$saved = (array) get_option( Company::OPT, [] );
		$inv = (int) ( $saved['logo_inverse'] ?? 0 );
		foreach ( $ids as $id ) {
			$src = (string) get_post_meta( (int) $id, '_lb_src', true );
			if ( '' === $src ) {
				continue;
			}
			$row = [ 'key' => (string) get_post_meta( (int) $id, '_lb_media_key', true ), 'url' => $src, 'alt' => (string) get_post_meta( (int) $id, '_wp_attachment_image_alt', true ), 'use' => '' ];
			$row['use'] = (int) $id === $logo ? 'logo' : ( (int) $id === $inv ? 'logo_inverse' : ( (int) $id === $icon ? 'site_icon' : '' ) );
			if ( '' === $row['use'] ) {
				unset( $row['use'] );
			}
			$out[] = $row;
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } );
		return $out;
	}

	private static function chrome(): array {
		$map = get_option( Chrome::OPT_MAP, [] );
		$mode = get_option( Chrome::OPT_MODE, null );
		$out = [];
		if ( is_array( $map ) && $map ) {
			$out['map'] = $map;
		}
		if ( in_array( $mode, [ 'auto', 'elements', 'patterns' ], true ) ) {
			$out['mode'] = $mode;
		}
		return $out;
	}

	private static function urls(): array {
		$s = Permalinks::settings();
		$ps = [];
		foreach ( [ 'post_types', 'taxonomies' ] as $g ) {
			foreach ( (array) ( $s['permastructures'][ $g ] ?? [] ) as $t => $st ) {
				if ( '' !== (string) $st ) {
					$ps[ $t ] = (string) $st;
				}
			}
		}
		ksort( $ps );
		unset( $s['permastructures'] );
		ksort( $s );
		$out = [];
		if ( $ps ) {
			$out['permastructures'] = $ps;
		}
		$out['settings'] = $s;
		return $out;
	}

	/* ------------------------------------------------------------------ menus */

	private static function menus(): array {
		$out = [];
		foreach ( [ 'main' => 'main-menu', 'footer' => 'footer', 'legal' => 'legal' ] as $name => $key ) {
			$ids = get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => 'nav-' . $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $ids ) {
				$items = self::menu_items( (int) $ids[0] );
				if ( $items ) {
					$out[ $name ] = $items;
				}
			}
		}
		return $out;
	}

	/** permalink path => page id. */
	private static function url_map(): array {
		if ( null === self::$url_map ) {
			self::$url_map = [];
			foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => 1000, 'fields' => 'ids', 'suppress_filters' => true ] ) as $id ) {
				self::$url_map[ self::norm_url( (string) get_permalink( (int) $id ) ) ] = (int) $id;
			}
		}
		return self::$url_map;
	}

	public static function reset(): void {
		self::$url_map = null;
	}

	/** A URL comparable across relative and absolute forms: the path of this site's URLs, the whole URL otherwise. */
	public static function norm_url( string $u ): string {
		$u = trim( $u );
		if ( '' === $u || '#' === $u ) {
			return $u;
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$p = wp_parse_url( $u );
		if ( ! is_array( $p ) ) {
			return $u;
		}
		if ( empty( $p['host'] ) || strtolower( (string) $p['host'] ) === strtolower( $home ) ) {
			$path = (string) ( $p['path'] ?? '/' );
			$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
			if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
			return '/' . trim( $path, '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
		return rtrim( $u, '/' );
	}

	/** The menu items of a navigation post in brief form, without the generated children. */
	public static function menu_items( int $nid, bool $stale = false ): array {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $nid, 'raw' ) );
		foreach ( $blocks as $b ) {
			if ( 'kadence/navigation' === ( $b['blockName'] ?? '' ) ) {
				return self::links( (array) $b['innerBlocks'], $stale );
			}
		}
		return [];
	}

	private static function links( array $blocks, bool $stale ): array {
		$out = [];
		foreach ( $blocks as $b ) {
			if ( 'kadence/navigation-link' !== ( $b['blockName'] ?? '' ) ) {
				continue;
			}
			$a = (array) ( $b['attrs'] ?? [] );
			$classes = preg_split( '/\s+/', trim( (string) ( $a['className'] ?? '' ) ) ) ?: [];
			if ( in_array( 'lb-auto-child', $classes, true ) ) {
				continue;
			}
			$row = [ 'label' => wp_strip_all_tags( html_entity_decode( (string) ( $a['label'] ?? '' ) ) ) ];
			$spec = Nav::spec( (string) ( $a['className'] ?? '' ) );
			$pid = 0;
			if ( 'post-type' === ( $a['kind'] ?? '' ) && 'page' === ( $a['type'] ?? '' ) && ! empty( $a['id'] ) ) {
				$pid = (int) $a['id'];
			} elseif ( ! empty( $a['url'] ) && '#' !== $a['url'] ) {
				$pid = (int) ( self::url_map()[ self::norm_url( (string) $a['url'] ) ] ?? 0 );
			}
			if ( $pid && get_post( $pid ) ) {
				$row['page'] = self::page_key( $pid );
				if ( $stale && 'publish' === get_post_status( $pid ) && 'post-type' !== ( $a['kind'] ?? '' ) ) {
					$row['_stale'] = true; // The page is published now but the menu still has its draft link: the menu needs a rebuild.
				}
			} elseif ( ! empty( $a['url'] ) && '#' !== $a['url'] && ! ( $spec && 'post-type' === ( $a['kind'] ?? '' ) ) ) {
				$row['url'] = self::norm_url( (string) $a['url'] );
			}
			if ( $spec ) {
				$row['auto'] = 'area' === $spec['type'] ? 'areas' : 'services';
				if ( '' !== $spec['category'] ) {
					$row['category'] = $spec['category'];
				}
				if ( '' !== $spec['parent'] ) {
					$row['parent'] = $spec['parent'];
				}
				$row['limit'] = (int) $spec['limit'];
			}
			$kids = self::links( (array) ( $b['innerBlocks'] ?? [] ), $stale );
			if ( $kids ) {
				$row['children'] = $kids;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Does a navigation post hold these items? $items are nav items as build-from-brief writes them (label, post_id | url,
	 * auto, category, parent, limit, children); generated children are ignored.
	 */
	public static function menu_equals( int $nid, array $items ): bool {
		self::reset();
		return self::same_items( self::menu_items( $nid, true ), $items );
	}

	private static function same_items( array $stored, array $want ): bool {
		if ( count( $stored ) !== count( $want ) ) {
			return false;
		}
		foreach ( array_values( $want ) as $i => $w ) {
			$s = $stored[ $i ];
			if ( ! empty( $s['_stale'] ) || (string) $s['label'] !== (string) $w['label'] ) {
				return false;
			}
			$auto = (string) ( $w['auto'] ?? '' );
			if ( $auto !== (string) ( $s['auto'] ?? '' ) ) {
				return false;
			}
			if ( '' !== $auto ) {
				if ( sanitize_title( (string) ( $w['category'] ?? '' ) ) !== (string) ( $s['category'] ?? '' ) || trim( (string) ( $w['parent'] ?? '' ), '/' ) !== (string) ( $s['parent'] ?? '' ) || (int) ( $w['limit'] ?? 12 ) !== (int) ( $s['limit'] ?? 0 ) ) {
					return false;
				}
			}
			$wpid = (int) ( $w['post_id'] ?? 0 );
			if ( $wpid ) {
				if ( ( $s['page'] ?? '' ) !== self::page_key( $wpid ) ) {
					return false;
				}
			} elseif ( isset( $w['url'] ) ) {
				if ( self::norm_url( (string) $w['url'] ) !== (string) ( $s['url'] ?? '' ) && ! isset( $s['page'] ) ) {
					return false;
				}
			} elseif ( isset( $s['url'] ) || isset( $s['page'] ) ) {
				if ( '' === $auto ) {
					return false;
				}
			}
			if ( ! self::same_items( (array) ( $s['children'] ?? [] ), (array) ( $w['children'] ?? [] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ blocks to Markdown (best effort) */

	/** Inline HTML to Markdown. */
	private static function inline( string $html ): string {
		$s = (string) preg_replace( '#<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is', '[$2]($1)', $html );
		$s = (string) preg_replace( '#<(strong|b)>(.*?)</\1>#is', '**$2**', $s );
		$s = (string) preg_replace( '#<(em|i)>(.*?)</\1>#is', '*$2*', $s );
		$s = (string) preg_replace( '#<code>(.*?)</code>#is', '`$1`', $s );
		$s = (string) preg_replace( '#<br\s*/?>#i', ' ', $s );
		return trim( html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES ) );
	}

	private static function items_of( array $b ): array {
		$out = [];
		foreach ( (array) ( $b['innerBlocks'] ?? [] ) as $in ) {
			$name = (string) ( $in['blockName'] ?? '' );
			if ( in_array( $name, [ 'core/list-item', 'kadence/listitem' ], true ) ) {
				$out[] = self::inline( 'kadence/listitem' === $name ? self::kadence_text( $in ) : (string) ( $in['innerHTML'] ?? '' ) );
			}
		}
		return $out;
	}

	private static function kadence_text( array $b ): string {
		return implode( '', array_filter( (array) ( $b['innerContent'] ?? [] ), 'is_string' ) );
	}

	/** Markdown of block content: headings, paragraphs, lists, quotes, separators and images. */
	public static function markdown( string $content ): string {
		$out = [];
		$walk = static function ( array $blocks ) use ( &$walk, &$out ) {
			foreach ( $blocks as $b ) {
				$name = (string) ( $b['blockName'] ?? '' );
				$html = (string) ( $b['innerHTML'] ?? '' );
				if ( 'core/heading' === $name ) {
					$lvl = max( 2, min( 4, (int) ( $b['attrs']['level'] ?? 2 ) ) );
					$out[] = str_repeat( '#', $lvl ) . ' ' . self::inline( $html );
				} elseif ( 'core/paragraph' === $name ) {
					$t = self::inline( $html );
					'' !== $t ? $out[] = $t : null;
				} elseif ( 'kadence/iconlist' === $name ) {
					$out[] = implode( "\n", array_map( static function ( $i ) { return '- ' . $i; }, self::items_of( $b ) ) );
				} elseif ( 'core/list' === $name ) {
					$ordered = ! empty( $b['attrs']['ordered'] );
					$lines = [];
					foreach ( self::items_of( $b ) as $n => $i ) {
						$lines[] = ( $ordered ? ( $n + 1 ) . '. ' : '* ' ) . $i;
					}
					$out[] = implode( "\n", $lines );
				} elseif ( 'core/quote' === $name ) {
					$t = self::inline( implode( ' ', array_map( static function ( $q ) { return (string) ( $q['innerHTML'] ?? '' ); }, (array) ( $b['innerBlocks'] ?? [] ) ) ) );
					'' !== $t ? $out[] = '> ' . $t : null;
				} elseif ( 'core/separator' === $name ) {
					$out[] = '---';
				} elseif ( 'core/image' === $name && preg_match( '/<img[^>]*src="([^"]+)"[^>]*alt="([^"]*)"/i', $html, $m ) ) {
					$out[] = '![' . $m[2] . '](' . $m[1] . ')';
				} elseif ( ! empty( $b['innerBlocks'] ) && ! in_array( $name, [ 'core/list', 'core/quote' ], true ) ) {
					$walk( (array) $b['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return implode( "\n\n", array_filter( $out, 'strlen' ) );
	}
}
