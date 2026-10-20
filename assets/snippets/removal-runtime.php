<?php
// Exported inside the handoff plugin's plugins_loaded callback, after the standalone list filter.
defined( 'ABSPATH' ) || exit;

$hubs = __LB_HANDOFF_HUBS__;

// These contextual tokens cannot be baked into shared designs for a single page.
add_filter( 'render_block', static function ( $html ) {
	if ( false === strpos( $html, '{{' ) ) {
		return $html;
	}
	$page = get_queried_object();
	if ( ! $page instanceof WP_Post ) {
		return $html;
	}
	$area = 'lb_area' === $page->post_type ? $page : null;
	if ( $area && $area->post_parent && get_post_meta( $area->ID, '_lb_service', true ) ) {
		$area = get_post( $area->post_parent );
	}
	$values = [ 'page.title' => get_the_title( $page ), 'area.name' => $area ? get_the_title( $area ) : '' ];
	return (string) preg_replace_callback( '/\{\{\s*(page\.title|area\.name)\s*(?:\|([^{}]*))?\}\}/', static function ( $match ) use ( $values ) {
		$value = (string) $values[ $match[1] ];
		return esc_html( '' !== $value ? $value : ( $match[2] ?? '' ) );
	}, $html );
}, 20 );

add_action( 'init', static function () {
	if ( WP_Block_Type_Registry::get_instance()->is_registered( 'rank-math/breadcrumb' ) ) {
		return;
	}
	register_block_type( 'rank-math/breadcrumb', [ 'render_callback' => static function () {
		if ( function_exists( 'rank_math_get_breadcrumbs' ) ) {
			return (string) rank_math_get_breadcrumbs();
		}
		return function_exists( 'Kadence\\kadence' ) ? (string) \Kadence\kadence()->get_breadcrumb( [ 'show_title' => true ] ) : '';
	} ] );
}, 100 );

add_filter( 'kadence_breadcrumb_post_types', static function ( $types ) use ( $hubs ) {
	$types = is_array( $types ) ? $types : [];
	foreach ( $hubs as $pt => $id ) {
		if ( $id && ! isset( $types[ $pt ] ) ) {
			$types[ $pt ] = [ 'post_type' => $pt, 'taxonomy' => '', 'archive_page' => $id, 'archive_label' => '' ];
		}
	}
	return $types;
} );

add_filter( 'rank_math/frontend/breadcrumb/items', static function ( $crumbs ) use ( $hubs ) {
	if ( ! is_array( $crumbs ) || ! is_singular( array_keys( $hubs ) ) || ! $crumbs ) {
		return $crumbs;
	}
	$id = (int) ( $hubs[ get_post_type( get_queried_object_id() ) ] ?? 0 );
	if ( ! $id || 'publish' !== get_post_status( $id ) ) {
		return $crumbs;
	}
	$url = (string) get_permalink( $id );
	foreach ( $crumbs as $crumb ) {
		if ( ( $crumb[1] ?? '' ) === $url ) {
			return $crumbs;
		}
	}
	array_splice( $crumbs, 1, 0, [ [ get_the_title( $id ), $url, 'hide_in_schema' => false ] ] );
	return $crumbs;
}, 10 );

// Preserve the Element visibility rule, rather than leaving an empty list heading on leaf pages.
add_filter( 'kadence_element_display', static function ( $show, $post = null ) {
	if ( ! $show || ! $post instanceof WP_Post ) {
		return $show;
	}
	$lists = array_filter( array_map( 'intval', (array) get_post_meta( $post->ID, '_lb_list', false ) ) );
	if ( ! $lists ) {
		return $show;
	}
	foreach ( $lists as $id ) {
		$meta = get_post_meta( $id, '_kad_query_query', true );
		$args = [ 'post_type' => (array) ( $meta['postType'] ?? [ 'lb_service' ] ), 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'post__not_in' => is_singular() ? [ get_queried_object_id() ] : [] ];
		$args = apply_filters( 'kadence_blocks_pro_query_loop_query_vars', $args, $meta, $id );
		if ( get_posts( $args ) ) {
			return $show;
		}
	}
	return false;
}, 20, 2 );
