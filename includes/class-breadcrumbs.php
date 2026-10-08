<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumbs: the trail (data) and its one renderer (the launch/breadcrumbs block and anything else that prints crumbs).
 *
 * Trails: Home > services page > first category > service, Home > service-areas page > parent areas > area,
 * Home > ancestors > page; nothing on the front page or on archives. Entries::breadcrumbs() delegates here.
 */
final class Breadcrumbs {

	/**
	 * The trail for the current request: [{title, url}], home first, the current object last (its url is the canonical
	 * URL; renderers do not link it). Empty when there is nothing to show (front page, archives, no post).
	 *
	 * @return array<int,array{title:string,url:string}>
	 */
	public static function trail(): array {
		if ( is_front_page() || ! is_singular() ) {
			return [];
		}
		$post = get_post();
		if ( ! $post ) {
			return [];
		}
		$trail = [ [ 'title' => 'Home', 'url' => home_url( '/' ) ] ];
		$page_by = static function ( string $slug ) { $pg = get_page_by_path( $slug ); return $pg && 'publish' === $pg->post_status ? [ 'title' => get_the_title( $pg ), 'url' => get_permalink( $pg ) ] : null; };
		if ( Entries::SERVICE === $post->post_type ) {
			$root = $page_by( 'services' );
			$root ? $trail[] = $root : null;
			$terms = wp_get_object_terms( $post->ID, Entries::CAT );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$pg = (int) get_term_meta( $terms[0]->term_id, '_lb_page', true );
				$trail[] = [ 'title' => $terms[0]->name, 'url' => $pg && 'publish' === get_post_status( $pg ) ? get_permalink( $pg ) : get_term_link( $terms[0] ) ];
			}
		} elseif ( Entries::AREA === $post->post_type ) {
			$root = $page_by( 'service-areas' );
			$root ? $trail[] = $root : null;
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $aid ) {
				$trail[] = [ 'title' => get_the_title( $aid ), 'url' => get_permalink( $aid ) ];
			}
		} else {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $aid ) {
				$trail[] = [ 'title' => get_the_title( $aid ), 'url' => get_permalink( $aid ) ];
			}
		}
		$trail[] = [ 'title' => get_the_title( $post ), 'url' => (string) get_permalink( $post ) ];
		return $trail;
	}

	/**
	 * Should crumbs show in the current context? Final contract: the current role is in the chrome map's crumbs roles and
	 * the page's _lb_hero is not 'none'; always true in simulate/editor contexts. Currently always true (crumbs show
	 * wherever a breadcrumb block is placed).
	 */
	public static function visible(): bool {
		return true;
	}

	/** The breadcrumb nav for the current request, or '' (markup identical to the pre-Phase-0 Entries::breadcrumbs). */
	public static function render(): string {
		if ( ! self::visible() ) {
			return '';
		}
		$trail = self::trail();
		if ( ! $trail ) {
			return '';
		}
		$current = array_pop( $trail );
		$home = '<a class="lb-crumb-home" href="' . esc_url( home_url( '/' ) ) . '" aria-label="Home"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg></a>';
		$h = '<nav class="lb-crumbs" aria-label="Breadcrumb"><ol>';
		foreach ( $trail as $i => $t ) {
			$h .= '<li>' . ( 0 === $i ? $home : '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['title'] ) . '</a>' ) . '</li>';
		}
		$h .= '<li aria-current="page">' . esc_html( $current['title'] ) . '</li></ol></nav>';
		return $h;
	}
}
