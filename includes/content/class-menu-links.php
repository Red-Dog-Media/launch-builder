<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The launch/menu-links block: the links of one of the site's menus as a plain list, for footers.
 *
 * The footer menu and the legal menu a brief (or set-navigation) writes are navigation posts that nothing prints on their own;
 * this block prints their top-level links, so a footer built from a pattern follows the menu instead of a typed list. A menu
 * that does not exist falls back to something live (the main menu's top level for the footer, the published legal pages for the
 * legal links); a link that would go nowhere ('#', a page that is not published) is left out, and a list with no links prints
 * nothing at all.
 */
final class Menu_Links {

	/** Menus the block can print: key => navigation key (the _lb_key of the kadence_navigation post, without 'nav-'). */
	const MENUS = [
		'footer' => 'footer',
		'legal'  => 'legal',
		'main'   => 'main-menu',
	];

	/** @var array<string,array> per-request memo. */
	private static $memo = [];

	/** Forget the memo (a menu or a page changed in this request). */
	public static function forget(): void {
		self::$memo = [];
	}

	/**
	 * The links of a menu: [ [ label, url ], ... ] (top level only, dead links left out, fallbacks applied).
	 *
	 * @param string $menu  footer | legal | main.
	 * @param int    $limit most links (0 = all).
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function links( string $menu, int $limit = 8 ): array {
		$menu = isset( self::MENUS[ $menu ] ) ? $menu : 'footer';
		$k = $menu . '|' . $limit;
		if ( isset( self::$memo[ $k ] ) ) {
			return self::$memo[ $k ];
		}
		$links = self::from_navigation( self::MENUS[ $menu ] );
		if ( ! $links && 'footer' === $menu ) {
			$links = self::from_navigation( self::MENUS['main'] );
		}
		if ( ! $links && 'legal' === $menu ) {
			$links = self::legal_pages();
		}
		if ( $limit > 0 ) {
			$links = array_slice( $links, 0, $limit );
		}
		return self::$memo[ $k ] = $links;
	}

	/** The top-level links of the kadence_navigation post with this key. */
	private static function from_navigation( string $key ): array {
		if ( ! post_type_exists( 'kadence_navigation' ) ) {
			return [];
		}
		$ids = get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'publish', 'meta_key' => '_lb_key', 'meta_value' => 'nav-' . $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $ids ) {
			return [];
		}
		$out = [];
		foreach ( parse_blocks( (string) get_post_field( 'post_content', (int) $ids[0] ) ) as $root ) {
			foreach ( (array) ( $root['innerBlocks'] ?? [] ) as $b ) {
				if ( 'kadence/navigation-link' !== ( $b['blockName'] ?? '' ) ) {
					continue;
				}
				$a = (array) ( $b['attrs'] ?? [] );
				$label = trim( wp_strip_all_tags( (string) ( $a['label'] ?? '' ) ) );
				$url = trim( (string) ( $a['url'] ?? '' ) );
				if ( '' === $label || '' === $url || '#' === $url ) {
					continue;
				}
				if ( ! empty( $a['id'] ) && 'post-type' === ( $a['kind'] ?? '' ) && 'publish' !== get_post_status( (int) $a['id'] ) ) {
					continue; // A page that is still a draft leads nowhere.
				}
				$out[] = [ $label, $url ];
			}
		}
		return $out;
	}

	/** Published legal pages (role legal, and the page WordPress calls the privacy policy), as links. */
	private static function legal_pages(): array {
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'meta_key' => Roles::META, 'meta_value' => 'legal', 'numberposts' => 12, 'orderby' => 'menu_order title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$priv = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $priv && 'publish' === get_post_status( $priv ) ) {
			$ids[] = $priv;
		}
		$out = [];
		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
			$out[] = [ get_the_title( $id ), (string) get_permalink( $id ) ];
		}
		return $out;
	}

	/**
	 * HTML for the block.
	 *
	 * @param array $a block attributes: menu, layout (list | inline), limit.
	 */
	public static function render( array $a ): string {
		$menu = isset( self::MENUS[ $a['menu'] ?? '' ] ) ? (string) $a['menu'] : 'footer';
		$limit = isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? max( 0, min( 24, (int) $a['limit'] ) ) : 8;
		$links = self::links( $menu, $limit );
		if ( ! $links ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) { // The editor's preview only.
				return '<p class="lb-entries-empty">' . esc_html__( 'This menu has no links yet. Add them under Menus (the footer and legal menus), and this list fills itself.', 'launch-builder' ) . '</p>';
			}
			return '';
		}
		$h = '<ul class="lb-entries lb-footer-links lb-menu-links' . ( 'inline' === ( $a['layout'] ?? '' ) ? ' lb-inline' : '' ) . '">';
		foreach ( $links as $l ) {
			$h .= '<li><a href="' . esc_url( $l[1] ) . '">' . esc_html( $l[0] ) . '</a></li>';
		}
		return $h . '</ul>';
	}
}
