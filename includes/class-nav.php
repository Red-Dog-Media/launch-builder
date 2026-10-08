<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Menus and the block header: Kadence navigation posts built from items, and set-header (a header pattern published as
 * the Kadence block header with the menu inside).
 *
 * Contract for the live menus: auto items are kadence/navigation-link blocks of kind 'post-type'
 * that point at an entry by id; a parent link carries a marker class (see markers()) saying what its children are, and
 * only children marked lb-auto-child are ever rewritten. Nav::flush() rebuilds those children when entries change.
 */
final class Nav {

	public static function define_tools(): void {
		Abilities::add( 'set-navigation', 'Create or update a navigation menu', 'Kadence navigation menu from items [{label,url,children:[...]}]. key identifies it so re-running updates it. Returns the navigation post id (use in set-header, or leave to set-header).',
			[ 'properties' => [ 'key' => [ 'type' => 'string' ], 'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ], 'required' => [ 'items' ] ], [ __CLASS__, 'set_navigation' ], 'edit_theme_options' );

		Abilities::add( 'set-header', 'Set the site header from a header pattern', 'Applies a header pattern (see list-patterns category headers): fills slots, builds the menu from nav items, publishes it as the Kadence block header and switches the site to it.',
			[ 'properties' => [
				'pattern' => [ 'type' => 'string' ],
				'nav'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Menu items [{label,url,children}]' ],
				'slots'   => [ 'type' => 'object' ],
				'replace' => [ 'type' => 'object' ],
			], 'required' => [ 'pattern' ] ], [ __CLASS__, 'set_header' ], 'edit_theme_options' );
	}

	/* ------------------------------------------------------------------ live menus (contract) */

	/**
	 * Marker classes (className on a kadence/navigation-link) and what they mean. {slug} and {n} are placeholders.
	 *
	 * @return array<string,string> class => meaning
	 */
	public static function markers(): array {
		return [
			'lb-auto-services'          => 'Children are every published service (menu_order, then title).',
			'lb-auto-category-{slug}'   => 'Children are the services in that service category.',
			'lb-auto-areas'             => 'Children are the top-level service areas.',
			'lb-auto-parent-{slug}'     => 'Children are the child areas of that area.',
			'lb-auto-limit-{n}'         => 'At most n children (plus a "View all" link to the hub).',
			'lb-auto-child'             => 'A generated child item; only these are replaced on rebuild.',
		];
	}

	/**
	 * Rebuild the generated children of every marked menu item now. LB tools that change entries call it at the end and
	 * report the result as menus_updated; a shutdown fallback covers admin edits. Not implemented yet: changes nothing.
	 *
	 * @return int[] ids of the kadence_navigation posts that changed
	 */
	public static function flush(): array {
		return [];
	}

	/* ------------------------------------------------------------------ navigation + header */

	private static function nav_blocks( array $items ): array {
		$out = [];
		foreach ( $items as $it ) {
			$it = (array) $it;
			$label = sanitize_text_field( $it['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$kids = ! empty( $it['children'] ) ? self::nav_blocks( (array) $it['children'] ) : [];
			$out[] = [
				'blockName'    => 'kadence/navigation-link',
				'attrs'        => [ 'label' => $label, 'url' => esc_url_raw( (string) ( $it['url'] ?? '#' ) ) ?: '#', 'kind' => 'custom', 'uniqueID' => 'lb' . substr( md5( $label . wp_rand() ), 0, 8 ) ],
				'innerBlocks'  => $kids,
				'innerHTML'    => '',
				'innerContent' => $kids ? array_merge( [ "\n" ], array_fill( 0, count( $kids ), null ), [ "\n" ] ) : [],
			];
		}
		return $out;
	}

	public static function set_navigation( $input ) {
		$items = (array) ( $input['items'] ?? [] );
		$kids = self::nav_blocks( $items );
		if ( ! $kids ) {
			return new \WP_Error( 'lb_empty', 'No valid items (each needs a label).', [ 'status' => 400 ] );
		}
		$root = [ 'blockName' => 'kadence/navigation', 'attrs' => [], 'innerBlocks' => $kids, 'innerHTML' => '', 'innerContent' => array_merge( [ "\n" ], array_fill( 0, count( $kids ), null ), [ "\n" ] ) ];
		$key = sanitize_key( $input['key'] ?? 'main-menu' );
		$id = Abilities::upsert_post( 'kadence_navigation', 'nav-' . $key, 'Launch Builder: ' . $key, serialize_blocks( [ $root ] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return [ 'navigation_id' => $id, 'items' => count( $kids ) ];
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

	public static function set_header( $input ) {
		$all = Patterns::all();
		$slug = sanitize_key( $input['pattern'] ?? '' );
		if ( ! isset( $all[ $slug ] ) || ! in_array( 'launch-builder-headers', (array) ( $all[ $slug ]['categories'] ?? [] ), true ) ) {
			return new \WP_Error( 'lb_no_header', 'Unknown header pattern. Use list-patterns with category headers.', [ 'status' => 404 ] );
		}
		if ( ! Plugin::kadence_theme_active() || ! post_type_exists( 'kadence_header' ) ) {
			return new \WP_Error( 'lb_no_kadence', 'The block header needs the Kadence theme and Kadence Blocks (Header Adv) to be active.', [ 'status' => 412 ] );
		}
		$snap = Abilities::snapshot( 'set-header', [ 'blocks_header', 'blocks_header_id' ] );
		$blocks = parse_blocks( $all[ $slug ]['content'] );
		[ $applied, $warnings ] = Patterns::fill( $blocks, (array) ( $input['slots'] ?? [] ), (array) ( $input['replace'] ?? [] ), (array) ( $input['vars'] ?? [] ) );
		$nav_id = 0;
		if ( ! empty( $input['nav'] ) ) {
			$nav = self::set_navigation( [ 'key' => 'main-menu', 'items' => (array) $input['nav'] ] );
			if ( is_wp_error( $nav ) ) {
				return $nav;
			}
			$nav_id = (int) $nav['navigation_id'];
		}
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
		set_theme_mod( 'blocks_header', true );
		set_theme_mod( 'blocks_header_id', $id );
		$hn = (array) ( Plugin::pattern_extra( $slug )['header_nav'] ?? [] ); // A header may need particular menu colours (a dark bar needs light links).
		if ( $hn && $nav_id ) {
			Design::set_nav_style( [ 'key' => 'main-menu' ] + $hn );
		} elseif ( $nav_id ) { // No special menu colours: clear any a previous header (such as the dark one) left on the shared menu.
			foreach ( [ '_kad_navigation_linkColor', '_kad_navigation_linkColorHover', '_kad_navigation_linkColorActive' ] as $mk ) {
				delete_post_meta( $nav_id, $mk );
			}
		}
		return [ 'header_id' => $id, 'navigation_id' => $nav_id, 'applied' => $applied, 'warnings' => $warnings, 'snapshot' => $snap, 'edit_url' => get_edit_post_link( $id, 'raw' ) ];
	}
}
