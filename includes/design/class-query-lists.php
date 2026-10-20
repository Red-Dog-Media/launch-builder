<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Lists of services and areas are Kadence Query Loops (Adv): a kadence_query post (the query) and a kadence_query_card
 * post (what one item looks like), placed with <!-- wp:kadence/query {"id":…} /-->. Kadence's query settings cover
 * "top-level entries" and "these parents" but not "children of the page being viewed", so this class adds that.
 *
 * A pattern describes a list with a placeholder: a kadence/query block whose lbList attribute says what to list
 * ({mode, postType, perPage, columns, columnGap, rowGap}) and whose inner blocks are the card template. materialize()
 * turns it into the two Kadence posts (keyed, so placing the same design again updates them) and the reference block.
 * The query post carries _lb_query = the mode:
 *  top            top-level entries of the post type (post_parent 0; areas without their service pages).
 *  children       children of the page being viewed; on a page without children, its siblings (a sub-service lists
 *                 the other sub-services of its service).
 *  area-services  the area service pages of the area being viewed; on an area service page, the other pages of its area.
 * Runtime: the filter kadence_blocks_pro_query_loop_query_vars applies the mode (on the page and in Kadence's REST
 * pagination, which passes the page id as {id}_query_exclude_post_id), and kadence_element_display hides an Element whose
 * list would be empty (its _lb_list meta names the query). snippet() is the same filter as a stand-alone plugin for a
 * site that removes Launch Builder.
 */
final class Query_Lists {

	/** Modes a list can have (see the class comment). */
	const MODES = [ 'top', 'children', 'area-services' ];

	/** Query post meta: the mode. Element meta: the query posts its lists use (an Element with only empty lists is hidden). */
	const META      = '_lb_query';
	const LIST_META = '_lb_list';

	/** @var array<string,int> memo of list sizes for this request: "ql:post" => count. */
	private static $sizes = [];

	public static function boot(): void {
		add_filter( 'kadence_blocks_pro_query_loop_query_vars', [ __CLASS__, 'query_vars' ], 10, 3 );
		add_filter( 'kadence_element_display', [ __CLASS__, 'element_display' ], 20, 2 );
		add_filter( 'render_block_kadence/query', [ __CLASS__, 'render_placeholder' ], 10, 2 );
	}

	/**
	 * A list placeholder that was never turned into a Query Loop (the Design hub's previews render patterns as they are)
	 * prints its card template for the entries it would list, in a plain grid, so a preview shows real entries.
	 */
	public static function render_placeholder( $html, $block ) {
		$spec = $block['attrs']['lbList'] ?? null;
		if ( ! is_array( $spec ) || ! empty( $block['attrs']['id'] ) ) {
			return $html;
		}
		$mode = in_array( $spec['mode'] ?? '', self::MODES, true ) ? (string) $spec['mode'] : 'top';
		$pt = in_array( $spec['postType'] ?? '', [ Entries::SERVICE, Entries::AREA ], true ) ? (string) $spec['postType'] : Entries::SERVICE;
		$viewed = is_singular() ? (int) get_queried_object_id() : (int) get_the_ID();
		$args = self::args_for( $mode, $pt, $viewed ) + [ 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 24, (int) ( $spec['perPage'] ?? 12 ) ) ), 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'post__not_in' => $viewed && 'top' !== $mode ? [ $viewed ] : [] ];
		$posts = get_posts( $args );
		$tpl = serialize_blocks( (array) ( $block['innerBlocks'] ?? [] ) );
		$cols = max( 1, (int) ( ( (array) ( $spec['columns'] ?? [ 3 ] ) )[0] ?? 3 ) );
		$gap = (int) ( ( (array) ( $spec['columnGap'] ?? [ 20 ] ) )[0] ?? 20 );
		$items = '';
		foreach ( $posts as $p ) {
			$ctx = static function ( $context ) use ( $p ) {
				$context['postId'] = $p->ID;
				$context['postType'] = $p->post_type;
				return $context;
			};
			add_filter( 'render_block_context', $ctx );
			$items .= '<li class="kb-query-item">' . do_blocks( $tpl ) . '</li>';
			remove_filter( 'render_block_context', $ctx );
		}
		return '<div class="wp-block-kadence-query lb-list-preview"><ul style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(' . $cols . ',minmax(0,1fr));gap:' . $gap . 'px">' . $items . '</ul></div>';
	}

	/* ------------------------------------------------------------------ runtime */

	/** The page a list is shown on: Kadence's REST parameter for pagination, else the singular page being viewed. */
	private static function viewed( int $ql_id ): int {
		$key = $ql_id . '_query_exclude_post_id';
		if ( isset( $_GET[ $key ] ) && is_numeric( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only, the same parameter Kadence reads.
			return absint( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * WP_Query arguments for a mode on a page: post_parent (and the meta query for area service pages), or post__in [0]
	 * when the mode needs a page and there is none.
	 */
	public static function args_for( string $mode, string $post_type, int $viewed ): array {
		$out = [];
		if ( 'top' === $mode ) {
			$out['post_parent'] = 0;
			if ( Entries::AREA === $post_type ) {
				$out['meta_query'] = [ [ 'key' => '_lb_service', 'compare' => 'NOT EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			return $out;
		}
		$p = $viewed ? get_post( $viewed ) : null;
		if ( ! $p || $p->post_type !== $post_type ) {
			return [ 'post__in' => [ 0 ] ];
		}
		$parent = (int) $p->ID;
		if ( 'area-services' === $mode ) {
			if ( Entries::is_area_service( $p ) ) {
				$parent = (int) $p->post_parent; // An area service page lists the other pages of its area.
			}
			$out['meta_query'] = [ [ 'key' => '_lb_service', 'compare' => 'EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( $p->post_parent && ! get_children( [ 'post_parent' => $p->ID, 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] ) ) {
			$parent = (int) $p->post_parent; // No children: its siblings.
		}
		$out['post_parent'] = $parent;
		return $out;
	}

	/** kadence_blocks_pro_query_loop_query_vars: apply the mode of a marked list. */
	public static function query_vars( $query, $meta = [], $ql_id = 0 ) {
		$mode = $ql_id ? (string) get_post_meta( (int) $ql_id, self::META, true ) : '';
		if ( ! is_array( $query ) || ! in_array( $mode, self::MODES, true ) ) {
			return $query;
		}
		$pt = is_array( $query['post_type'] ?? null ) ? (string) reset( $query['post_type'] ) : (string) ( $query['post_type'] ?? '' );
		unset( $query['post_parent__in'] );
		$args = self::args_for( $mode, $pt, self::viewed( (int) $ql_id ) );
		if ( 'top' === $mode ) {
			// Remove Kadence's automatic exclusion, while keeping an editor's explicit choice.
			$viewed = self::viewed( (int) $ql_id );
			if ( ! in_array( $viewed, array_map( 'intval', (array) ( $meta['exclude'] ?? [] ) ), true ) ) {
				$query['post__not_in'] = array_values( array_diff( (array) ( $query['post__not_in'] ?? [] ), [ $viewed ] ) );
			}
		}
		if ( isset( $args['meta_query'] ) ) {
			$args['meta_query'] = array_merge( (array) ( $query['meta_query'] ?? [] ), $args['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array_merge( $query, $args );
	}

	/** How many entries a list shows on a page (memoised per request). */
	public static function size( int $ql_id, int $viewed ): int {
		$k = $ql_id . ':' . $viewed;
		if ( isset( self::$sizes[ $k ] ) ) {
			return self::$sizes[ $k ];
		}
		$mode = (string) get_post_meta( $ql_id, self::META, true );
		$q = get_post_meta( $ql_id, '_kad_query_query', true );
		$pt = is_array( $q ) && ! empty( $q['postType'] ) ? (string) ( (array) $q['postType'] )[0] : Entries::SERVICE;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return self::$sizes[ $k ] = 1; // Not ours: assume it has something to show.
		}
		$args = self::args_for( $mode, $pt, $viewed ) + [ 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ];
		if ( $viewed && 'top' !== $mode ) {
			$args['post__not_in'] = [ $viewed ];
		}
		return self::$sizes[ $k ] = count( get_posts( $args ) );
	}

	/** kadence_element_display: an Element made of lists that are all empty on this page does not print. */
	public static function element_display( $show, $post = null ) {
		if ( ! $show || ! $post instanceof \WP_Post ) {
			return $show;
		}
		$lists = array_filter( array_map( 'intval', (array) get_post_meta( $post->ID, self::LIST_META, false ) ) );
		if ( ! $lists ) {
			return $show;
		}
		$viewed = is_singular() ? (int) get_queried_object_id() : 0;
		foreach ( $lists as $ql ) {
			if ( self::size( $ql, $viewed ) > 0 ) {
				return $show;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ building lists */

	/** Native query/card state owned by a design, also used when snapshotting an explicit replacement. */
	public static function dependencies( string $design_key ): array {
		$posts = get_posts( [ 'post_type' => [ 'kadence_query', 'kadence_query_card' ], 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'numberposts' => -1, 'meta_key' => '_lb_key', 'meta_value' => $design_key . '-list-', 'meta_compare' => 'LIKE', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out = [];
		foreach ( $posts as $post ) {
			$key = (string) get_post_meta( $post->ID, '_lb_key', true );
			if ( 0 !== strpos( $key, $design_key . '-list-' ) ) {
				continue;
			}
			$out[] = [ 'id' => (int) $post->ID, 'meta' => self::owned_meta( (int) $post->ID ), 'ledger' => 'query-list:' . $key ];
		}
		return $out;
	}

	/** Kadence's editable list settings, excluding generated CSS caches and unrelated plugin metadata. */
	private static function owned_meta( int $id ): array {
		$keys = array_filter( array_keys( get_post_meta( $id ) ), static function ( $key ) {
			return 0 === strpos( $key, '_kad_query_' ) || self::META === $key;
		} );
		sort( $keys );
		return array_values( $keys );
	}

	/** Keep edited native dependencies, including legacy lists for which no ownership baseline was recorded. */
	public static function edited( string $design_key ): bool {
		foreach ( self::dependencies( $design_key ) as $spec ) {
			$row = Ledger::get( $spec['ledger'] );
			$id = $spec['id'];
			if ( ! $row || (int) $row['id'] !== $id || $row['status'] !== get_post_status( $id ) || (string) ( $row['title'] ?? '' ) !== (string) get_post_field( 'post_title', $id, 'raw' ) || Ledger::is_edited( $spec['ledger'], Ledger::hash_post( $id, $spec['meta'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function record( int $id, string $key ): void {
		Ledger::record( 'query-list:' . $key, (string) get_post_type( $id ), $id, Ledger::hash_post( $id, self::owned_meta( $id ) ), (string) get_post_status( $id ), (string) get_post_field( 'post_title', $id, 'raw' ) );
	}

	/** Can this site hold Query Loops (Kadence Blocks Pro)? */
	public static function available(): bool {
		return post_type_exists( 'kadence_query' ) && post_type_exists( 'kadence_query_card' ) && \WP_Block_Type_Registry::get_instance()->is_registered( 'kadence/query' );
	}

	/**
	 * Turn every lbList placeholder in $blocks into a Query Loop: the card post and the query post (keyed "$key-list-N",
	 * updated in place when they exist) and a reference block. Returns the query post ids; WP_Error when the site has no
	 * Query Loop (Kadence Blocks Pro) or a post cannot be written.
	 *
	 * @param array  $blocks parsed blocks (changed in place).
	 * @param string $key    stable key of the design the lists belong to (e.g. design-subservices).
	 * @return int[]|\WP_Error
	 */
	public static function materialize( array &$blocks, string $key ) {
		$n = 0;
		$ids = [];
		$err = null;
		$walk = static function ( array &$list ) use ( &$walk, &$n, &$ids, &$err, $key ) {
			foreach ( $list as &$b ) {
				if ( $err ) {
					return;
				}
				if ( 'kadence/query' === ( $b['blockName'] ?? '' ) && is_array( $b['attrs']['lbList'] ?? null ) ) {
					$r = self::build( $b, $key . '-list-' . $n );
					$n++;
					if ( is_wp_error( $r ) ) {
						$err = $r;
						return;
					}
					$ids[] = $r;
					continue;
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'] );
				}
			}
			unset( $b );
		};
		$walk( $blocks );
		if ( $err ) {
			return $err;
		}
		if ( $n && ! self::available() ) {
			return new \WP_Error( 'lb_no_query_loop', 'Lists need Kadence Blocks Pro (Query Loop Adv).' );
		}
		return $ids;
	}

	/** One placeholder => card post + query post + reference block (in place). @return int|\WP_Error query post id */
	private static function build( array &$b, string $key ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'lb_no_query_loop', 'Lists need Kadence Blocks Pro (Query Loop Adv).' );
		}
		$spec = (array) $b['attrs']['lbList'];
		$mode = in_array( $spec['mode'] ?? '', self::MODES, true ) ? (string) $spec['mode'] : 'top';
		$pt = in_array( $spec['postType'] ?? '', [ Entries::SERVICE, Entries::AREA ], true ) ? (string) $spec['postType'] : Entries::SERVICE;
		$per = max( 1, min( 100, (int) ( $spec['perPage'] ?? 24 ) ) );
		$triple = static function ( $v, array $def ): array {
			$v = is_array( $v ) ? array_values( $v ) : $def;
			return array_map( 'strval', array_slice( array_pad( $v, 3, '' ), 0, 3 ) );
		};
		$label = ucfirst( str_replace( '-', ' ', $key ) );

		// The card: the template blocks inside a kadence/query-card block (Kadence strips that wrapper when it renders).
		$template = serialize_blocks( (array) ( $b['innerBlocks'] ?? [] ) );
		$card_id = Abilities::upsert_post( 'kadence_query_card', $key . '-card', 'Launch Builder: ' . $label . ' card', '' );
		if ( is_wp_error( $card_id ) ) {
			return $card_id;
		}
		$card_uid = $card_id . '_' . substr( md5( $key . '|card' ), 0, 6 );
		$rekeyed = Unique_Ids::rekey( $template, (int) $card_id, $key );
		$template = is_wp_error( $rekeyed ) ? $template : $rekeyed;
		// The root block carries no id of its own: Kadence Blocks Pro follows a block's id to its post, and a root block that names
		// its own post sends it round in a circle (every page showing the list then times out).
		$card_markup = '<!-- wp:kadence/query-card {"uniqueID":"' . $card_uid . '"} -->' . "\n" . $template . "\n" . '<!-- /wp:kadence/query-card -->';
		wp_update_post( [ 'ID' => $card_id, 'post_content' => wp_slash( $card_markup ) ] );
		$card_meta = [
			'_kad_query_card_columns'            => $triple( $spec['columns'] ?? null, [ '3', '2', '1' ] ),
			'_kad_query_card_columnGap'          => $triple( $spec['columnGap'] ?? null, [ '20', '', '' ] ),
			'_kad_query_card_rowGap'             => $triple( $spec['rowGap'] ?? null, [ '20', '', '' ] ),
			'_kad_query_card_postType'           => $pt,
			'_kad_query_card_preview_post_type'  => $pt,
		];
		foreach ( $card_meta as $k => $v ) {
			update_post_meta( $card_id, $k, $v );
		}

		// The query: Kadence's own settings, the card inside it, and the mode.
		$ql_id = Abilities::upsert_post( 'kadence_query', $key, 'Launch Builder: ' . $label, '' );
		if ( is_wp_error( $ql_id ) ) {
			return $ql_id;
		}
		$ql_uid = $ql_id . '_' . substr( md5( $key . '|query' ), 0, 6 );
		$markup = '<!-- wp:kadence/query {"uniqueID":"' . $ql_uid . '"} -->' . "\n"
			. '<!-- wp:kadence/query-card {"uniqueID":"' . $card_uid . '","id":' . (int) $card_id . '} /-->' . "\n"
			. '<!-- /wp:kadence/query -->';
		wp_update_post( [ 'ID' => $ql_id, 'post_content' => wp_slash( $markup ) ] );
		update_post_meta( $ql_id, '_kad_query_query', [
			'perPage'          => (string) $per,
			'pages'            => 0,
			'offset'           => 0,
			'postType'         => [ $pt ],
			'taxonomy'         => [],
			'order'            => 'asc',
			'orderBy'          => 'menu_order',
			'orderMetaKey'     => '',
			'orderMetaKeyType' => '',
			'author'           => '',
			'search'           => '',
			'exclude'          => [],
			'sticky'           => '',
			'inherit'          => false,
			'taxQuery'         => '',
			'parents'          => [],
			'limit'            => 0,
			'comparisonLogic'  => 'AND',
			'infiniteScroll'   => false,
		] );
		update_post_meta( $ql_id, self::META, $mode );
		self::record( (int) $card_id, $key . '-card' );
		self::record( (int) $ql_id, $key );

		$b = [ 'blockName' => 'kadence/query', 'attrs' => [ 'uniqueID' => $ql_uid, 'id' => (int) $ql_id ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ];
		return (int) $ql_id;
	}

	/** The query and card posts of every list Launch Builder made (for a removal or a reset). @return int[] */
	public static function made(): array {
		return array_map( 'intval', get_posts( [ 'post_type' => [ 'kadence_query', 'kadence_query_card' ], 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => '_lb_key', 'suppress_filters' => true ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/* ------------------------------------------------------------------ hand-off */

	/**
	 * The same list modes as a stand-alone plugin, for a site that removes Launch Builder: drop it into wp-content/mu-plugins
	 * and every list keeps showing the right entries. Elements whose lists are empty then print their heading over an
	 * empty list until it has entries.
	 */
	public static function snippet(): string {
		return <<<'PHP'
<?php
/**
 * Plugin Name: Service lists (from Launch Builder)
 * Description: "Children of this page" and "top level" for the Kadence Query Loops Launch Builder set up (query meta _lb_query).
 */
add_filter( 'kadence_blocks_pro_query_loop_query_vars', function ( $query, $meta, $ql_id ) {
	$mode = $ql_id ? (string) get_post_meta( (int) $ql_id, '_lb_query', true ) : '';
	if ( ! is_array( $query ) || ! in_array( $mode, [ 'top', 'children', 'area-services' ], true ) ) {
		return $query;
	}
	$pt = is_array( $query['post_type'] ?? null ) ? (string) reset( $query['post_type'] ) : (string) ( $query['post_type'] ?? '' );
	unset( $query['post_parent__in'] );
	if ( 'top' === $mode ) {
		$key = $ql_id . '_query_exclude_post_id';
		$id = isset( $_GET[ $key ] ) && is_numeric( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : ( is_singular() ? get_queried_object_id() : 0 );
		if ( ! in_array( $id, array_map( 'intval', (array) ( $meta['exclude'] ?? [] ) ), true ) ) {
			$query['post__not_in'] = array_values( array_diff( (array) ( $query['post__not_in'] ?? [] ), [ $id ] ) );
		}
		$query['post_parent'] = 0;
		if ( 'lb_area' === $pt ) {
			$query['meta_query'][] = [ 'key' => '_lb_service', 'compare' => 'NOT EXISTS' ];
		}
		return $query;
	}
	$key = $ql_id . '_query_exclude_post_id';
	$id = isset( $_GET[ $key ] ) && is_numeric( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : ( is_singular() ? get_queried_object_id() : 0 );
	$p = $id ? get_post( $id ) : null;
	if ( ! $p || $p->post_type !== $pt ) {
		$query['post__in'] = [ 0 ];
		return $query;
	}
	$parent = $p->ID;
	if ( 'area-services' === $mode ) {
		if ( $p->post_parent && get_post_meta( $p->ID, '_lb_service', true ) ) {
			$parent = $p->post_parent;
		}
		$query['meta_query'][] = [ 'key' => '_lb_service', 'compare' => 'EXISTS' ];
	} elseif ( $p->post_parent && ! get_children( [ 'post_parent' => $p->ID, 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] ) ) {
		$parent = $p->post_parent;
	}
	$query['post_parent'] = $parent;
	return $query;
}, 10, 3 );
PHP;
	}
}
