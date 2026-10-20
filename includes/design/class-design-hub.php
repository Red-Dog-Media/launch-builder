<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Design hub: every design PIECE of a site (header, the four heroes, the breadcrumb bar, the three lists, the
 * before-footer call to action, the footer, the sidebar) in three LOOKS, written as native Kadence objects so the site
 * keeps them without Launch Builder.
 *
 *  piece          native object                                         shown on
 *  header         Kadence block header (kadence_header + theme mods)     every page
 *  hero-*         Kadence Element, hook replace_hero_header             front page / services / areas / other pages
 *  breadcrumbs    Kadence Element, kadence_hero_header priority 20      every page but the front page
 *  lists          Kadence Element with a Query Loop (Adv), in the       services (sub-services), the areas hub page,
 *                 content (top, middle or bottom)                       areas (area service pages)
 *  cta, footer    Kadence Element before the footer / replacing it      every page
 *  sidebar        block widgets in the theme sidebar                    pages, services and areas with a sidebar layout
 *
 * A piece comes from the pattern whose headers say Piece: {piece} and Look: {look} (patterns/{piece}-{look}.php).
 * Placing a piece writes ONE object per piece (keyed design-{piece}): choosing another look rewrites it. Each write is
 * recorded in the Ledger; an object changed in Kadence since is kept unless the call says force. Choosing a look also
 * sets the look's colours and buttons (Kadence palette and button settings, and a Style Library set when Kadence Blocks
 * has one) and the standard layouts (Customizer: the hero replaces the title area; pages, services and areas with the
 * sidebar; the front page and contact pages full width).
 *
 * State: option launch_builder_design_hub { pieces: { piece: { look, pattern, id, placement, time } }, sidebar: [widget
 * numbers] }. Tools: get-designs, set-design. Screen: Launch Builder > Design hub (Design_Hub_Admin).
 */
final class Design_Hub {

	const OPT = 'launch_builder_design_hub';

	/** The looks: slug => label. */
	const LOOKS = [
		'auto-shop'     => 'Auto shop',
		'home-services' => 'Home services',
		'landscaping'   => 'Landscaping',
	];

	/**
	 * Each look's colours (palette1-9) and buttons (set-buttons primary): what choosing a look sets site-wide.
	 * palette1 action, palette2 accent, palette3 darkest, palette4 body text, palette5-6 muted, palette7-8 tints, palette9 white.
	 */
	const LOOK_STYLE = [
		'auto-shop'     => [
			'palette' => [ 'palette1' => '#D62828', 'palette2' => '#F77F00', 'palette3' => '#111418', 'palette4' => '#2B2F36', 'palette5' => '#4A5059', 'palette6' => '#6B7280', 'palette7' => '#E9ECEF', 'palette8' => '#F5F6F7', 'palette9' => '#FFFFFF' ],
			'buttons' => [ 'background' => 'palette1', 'color' => 'palette9', 'hover_background' => 'palette2', 'hover_color' => 'palette9', 'radius' => 2, 'weight' => 800, 'uppercase' => true, 'padding' => 'lg' ],
		],
		'home-services' => [
			'palette' => [ 'palette1' => '#1E5BD8', 'palette2' => '#0E7C8F', 'palette3' => '#0F1C3F', 'palette4' => '#2D3748', 'palette5' => '#4A5568', 'palette6' => '#718096', 'palette7' => '#E8F0FD', 'palette8' => '#F5F8FE', 'palette9' => '#FFFFFF' ],
			'buttons' => [ 'background' => 'palette1', 'color' => 'palette9', 'hover_background' => 'palette3', 'hover_color' => 'palette9', 'radius' => 8, 'weight' => 700, 'uppercase' => false, 'padding' => 'md' ],
		],
		'landscaping'   => [
			'palette' => [ 'palette1' => '#2F7D32', 'palette2' => '#B07D2B', 'palette3' => '#1E2D1F', 'palette4' => '#33413A', 'palette5' => '#55635A', 'palette6' => '#6E7A72', 'palette7' => '#EAF2E3', 'palette8' => '#F6F9F2', 'palette9' => '#FFFFFF' ],
			'buttons' => [ 'background' => 'palette1', 'color' => 'palette9', 'hover_background' => 'palette3', 'hover_color' => 'palette9', 'radius' => 'pill', 'weight' => 600, 'uppercase' => false, 'padding' => 'md' ],
		],
	];

	/**
	 * The pieces: label, kind (header | element | sidebar), slot (what Inspect and the site check call it), the page
	 * roles it shows on ('*' = every role), and for elements the Kadence hook, priority and element type.
	 */
	const PIECES = [
		'header'        => [ 'label' => 'Header', 'kind' => 'header', 'slot' => 'header', 'roles' => [ '*' ] ],
		'hero-home'     => [ 'label' => 'Home page hero', 'kind' => 'element', 'slot' => 'hero', 'roles' => [ 'home' ], 'hook' => 'replace_hero_header', 'priority' => 10, 'type' => 'template' ],
		'hero-service'  => [ 'label' => 'Service hero', 'kind' => 'element', 'slot' => 'hero', 'roles' => [ 'service' ], 'hook' => 'replace_hero_header', 'priority' => 10, 'type' => 'template' ],
		'hero-area'     => [ 'label' => 'Service area hero', 'kind' => 'element', 'slot' => 'hero', 'roles' => [ 'area' ], 'hook' => 'replace_hero_header', 'priority' => 10, 'type' => 'template' ],
		'hero-page'     => [ 'label' => 'Standard page hero', 'kind' => 'element', 'slot' => 'hero', 'roles' => [ 'page', 'legal', 'conversion', 'thanks', 'services_hub', 'areas_hub' ], 'hook' => 'replace_hero_header', 'priority' => 10, 'type' => 'template' ],
		'breadcrumbs'   => [ 'label' => 'Breadcrumb bar', 'kind' => 'element', 'slot' => 'crumbs', 'roles' => [ '*', '-home' ], 'hook' => 'custom', 'custom' => 'kadence_hero_header', 'priority' => 20, 'type' => 'default' ],
		'subservices'   => [ 'label' => 'Sub-service list', 'kind' => 'element', 'slot' => 'list', 'roles' => [ 'service' ], 'placement' => 'bottom', 'priority' => 10, 'type' => 'default' ],
		'areas'         => [ 'label' => 'Service area list', 'kind' => 'element', 'slot' => 'list', 'roles' => [ 'areas_hub' ], 'placement' => 'bottom', 'priority' => 10, 'type' => 'default' ],
		'area-services' => [ 'label' => 'Area services list', 'kind' => 'element', 'slot' => 'list', 'roles' => [ 'area' ], 'placement' => 'bottom', 'priority' => 10, 'type' => 'default' ],
		'cta'           => [ 'label' => 'Before-footer call to action', 'kind' => 'element', 'slot' => 'before_footer', 'roles' => [ '*' ], 'hook' => 'kadence_before_footer', 'priority' => 10, 'type' => 'default' ],
		'footer'        => [ 'label' => 'Footer', 'kind' => 'element', 'slot' => 'footer', 'roles' => [ '*' ], 'hook' => 'replace_footer', 'priority' => 10, 'type' => 'template' ],
		'sidebar'       => [ 'label' => 'Sidebar', 'kind' => 'sidebar', 'slot' => 'sidebar', 'roles' => [ 'page', 'service', 'area', 'legal', 'services_hub', 'areas_hub' ] ],
	];

	/** Where a list sits in the content: placement => Kadence Element hook. */
	const PLACEMENTS = [
		'top'    => 'kadence_single_before_entry_content',
		'middle' => 'kadence_inside_the_content_after_p2',
		'bottom' => 'kadence_single_after_entry_content',
	];

	/** Page role => the Kadence Elements rule that reaches it. */
	const ROLE_RULES = [
		'home'    => [ 'rule' => 'general|front_page' ],
		'service' => [ 'rule' => 'singular|lb_service', 'select' => 'all' ],
		'area'    => [ 'rule' => 'singular|lb_area', 'select' => 'all' ],
		'page'    => [ 'rule' => 'singular|page', 'select' => 'all' ],
	];

	/** The theme sidebar the sidebar piece fills. */
	const SIDEBAR = 'sidebar-primary';

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'crumbs_block' ], 99 );
		add_filter( 'kadence_breadcrumb_post_types', [ __CLASS__, 'kadence_crumb_types' ] );
		add_filter( 'rank_math/frontend/breadcrumb/items', [ __CLASS__, 'rank_math_crumbs' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ catalogue */

	/** The pattern slug of a piece in a look, '' when there is none. */
	public static function pattern( string $piece, string $look ): string {
		foreach ( Patterns::index() as $slug => $row ) {
			if ( $piece === ( $row['piece'] ?? '' ) && $look === ( $row['look'] ?? '' ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/** The role a piece's preview renders as (its first concrete role). */
	public static function piece_role( string $piece ): string {
		foreach ( (array) ( self::PIECES[ $piece ]['roles'] ?? [] ) as $r ) {
			if ( '*' !== $r && '-' !== $r[0] ) {
				return $r;
			}
		}
		return isset( self::PIECES[ $piece ] ) ? 'page' : '';
	}

	/** Every role a piece shows on (the '*' and '-role' notation expanded). */
	public static function roles_of( string $piece ): array {
		$def = (array) ( self::PIECES[ $piece ]['roles'] ?? [] );
		$all = array_diff( array_keys( Roles::ROLES ), [ 'other' ] );
		$out = in_array( '*', $def, true ) ? $all : array_intersect( $def, $all );
		foreach ( $def as $r ) {
			if ( '-' === $r[0] ) {
				$out = array_diff( $out, [ substr( $r, 1 ) ] );
			}
		}
		return array_values( $out );
	}

	/* ------------------------------------------------------------------ state */

	public static function state(): array {
		$s = get_option( self::OPT, [] );
		$s = is_array( $s ) ? $s : [];
		return [ 'pieces' => (array) ( $s['pieces'] ?? [] ), 'sidebar' => array_map( 'intval', (array) ( $s['sidebar'] ?? [] ) ) ];
	}

	private static function save_state( array $s ): void {
		update_option( self::OPT, $s, false );
	}

	/** Is a piece placed (its object exists and is published)? */
	public static function placed( string $piece ): bool {
		return isset( self::placements()[ $piece ] );
	}

	/**
	 * The pieces placed now: piece => { look, pattern, id, roles, kind, placement }. A piece whose object was deleted or
	 * unpublished is not placed (the header: while Kadence uses it as the block header).
	 */
	public static function placements(): array {
		$out = [];
		foreach ( self::state()['pieces'] as $piece => $p ) {
			$def = self::PIECES[ $piece ] ?? null;
			if ( ! $def ) {
				continue;
			}
			$id = (int) ( $p['id'] ?? 0 );
			if ( 'sidebar' === $def['kind'] ) {
				if ( ! self::state()['sidebar'] ) {
					continue;
				}
			} elseif ( ! $id || 'publish' !== get_post_status( $id ) ) {
				continue;
			} elseif ( 'header' === $def['kind'] && ( ! get_theme_mod( 'blocks_header' ) || (int) get_theme_mod( 'blocks_header_id' ) !== $id ) ) {
				continue;
			}
			$out[ $piece ] = [ 'look' => (string) ( $p['look'] ?? '' ), 'pattern' => (string) ( $p['pattern'] ?? '' ), 'id' => $id, 'roles' => self::roles_of( $piece ), 'kind' => $def['kind'], 'placement' => (string) ( $p['placement'] ?? '' ) ];
		}
		return $out;
	}

	/** The look every placed piece shares, '' when none is placed or they differ. */
	public static function current_look(): string {
		$looks = array_unique( array_column( self::placements(), 'look' ) );
		return 1 === count( $looks ) && isset( self::LOOKS[ (string) reset( $looks ) ] ) ? (string) reset( $looks ) : '';
	}

	/** Ids of the Kadence Elements the Design hub placed (published or not). */
	public static function element_ids(): array {
		$ids = [];
		foreach ( self::state()['pieces'] as $piece => $p ) {
			if ( 'element' === ( self::PIECES[ $piece ]['kind'] ?? '' ) && ! empty( $p['id'] ) && get_post( (int) $p['id'] ) ) {
				$ids[] = (int) $p['id'];
			}
		}
		return $ids;
	}

	/** Is Theme Kit Pro's Elements module on (Elements print)? */
	public static function elements_on(): bool {
		if ( ! post_type_exists( 'kadence_element' ) ) {
			return false;
		}
		$cfg = get_option( 'kadence_pro_theme_config', '' );
		$cfg = is_string( $cfg ) ? json_decode( $cfg, true ) : $cfg;
		return ! is_array( $cfg ) || ! array_key_exists( 'elements', $cfg ) || ! empty( $cfg['elements'] );
	}

	/** Does a role get the breadcrumb bar? */
	public static function crumbs_on( string $role ): bool {
		return self::placed( 'breadcrumbs' ) && in_array( $role, self::roles_of( 'breadcrumbs' ), true );
	}

	/**
	 * The block markup of the placed pieces that show on a role, joined (for the SEO analysis and the site check).
	 * $slots narrows it: hero, crumbs, list, before_footer, footer, header, sidebar.
	 */
	public static function role_markup( string $role, array $slots = [] ): string {
		$out = [];
		foreach ( self::placements() as $piece => $p ) {
			$slot = self::PIECES[ $piece ]['slot'];
			if ( ( $slots && ! in_array( $slot, $slots, true ) ) || ! in_array( $role, $p['roles'], true ) || 'sidebar' === $p['kind'] ) {
				continue;
			}
			$out[] = (string) get_post_field( 'post_content', $p['id'], 'raw' );
		}
		return implode( "\n", $out );
	}

	/* ------------------------------------------------------------------ placing pieces */

	/**
	 * Place pieces. $choices: piece => look (or { look, placement }), or a look for every piece ($input look). Options:
	 * force (replace objects changed in Kadence), dry_run, style (the look's colours and buttons; default true when a
	 * whole look is chosen), layouts (default true).
	 *
	 * @return array|\WP_Error { dry_run, pieces: piece => { look, pattern, action, id, note }, style, layouts, snapshot, warnings }
	 */
	public static function set( array $input ) {
		$dry = ! empty( $input['dry_run'] );
		$force = ! empty( $input['force'] );
		$choices = [];
		$look = isset( $input['look'] ) ? sanitize_key( (string) $input['look'] ) : '';
		if ( '' !== $look ) {
			if ( ! isset( self::LOOKS[ $look ] ) ) {
				return new \WP_Error( 'lb_look', 'Unknown look. Looks: ' . implode( ', ', array_keys( self::LOOKS ) ) . '.', [ 'status' => 400 ] );
			}
			foreach ( array_keys( self::PIECES ) as $piece ) {
				$choices[ $piece ] = [ 'look' => $look ];
			}
		}
		foreach ( (array) ( $input['pieces'] ?? [] ) as $piece => $c ) {
			$piece = sanitize_key( (string) $piece );
			if ( ! isset( self::PIECES[ $piece ] ) ) {
				return new \WP_Error( 'lb_piece', 'Unknown piece "' . $piece . '". Pieces: ' . implode( ', ', array_keys( self::PIECES ) ) . '.', [ 'status' => 400 ] );
			}
			$c = is_array( $c ) ? $c : [ 'look' => (string) $c ];
			$c['look'] = sanitize_key( (string) ( $c['look'] ?? ( $choices[ $piece ]['look'] ?? ( self::state()['pieces'][ $piece ]['look'] ?? '' ) ) ) );
			if ( 'none' !== $c['look'] && ! isset( self::LOOKS[ $c['look'] ] ) ) {
				return new \WP_Error( 'lb_look', $piece . ': unknown look "' . $c['look'] . '". Looks: ' . implode( ', ', array_keys( self::LOOKS ) ) . ', none.', [ 'status' => 400 ] );
			}
			if ( isset( $c['placement'] ) && ! isset( self::PLACEMENTS[ (string) $c['placement'] ] ) ) {
				return new \WP_Error( 'lb_placement', $piece . ': placement must be top, middle or bottom.', [ 'status' => 400 ] );
			}
			$choices[ $piece ] = $c;
		}
		if ( ! $choices ) {
			return new \WP_Error( 'lb_empty', 'Give look (every piece in one look) and/or pieces { piece: look }.', [ 'status' => 400 ] );
		}
		if ( ! Plugin::kadence_theme_active() ) {
			return new \WP_Error( 'lb_no_kadence', 'The Design hub places Kadence objects: the Kadence theme must be active.', [ 'status' => 412 ] );
		}

		$state = self::state();
		$out = [ 'dry_run' => $dry, 'pieces' => [], 'warnings' => [] ];
		$snap_posts = [];
		$plan = [];
		foreach ( $choices as $piece => $c ) {
			$def = self::PIECES[ $piece ];
			$row = [ 'look' => $c['look'], 'pattern' => '', 'action' => '', 'id' => (int) ( $state['pieces'][ $piece ]['id'] ?? 0 ), 'note' => '' ];
			if ( 'none' === $c['look'] ) {
				$row['action'] = $row['id'] || ( 'sidebar' === $def['kind'] && $state['sidebar'] ) ? 'remove' : 'none';
				$plan[ $piece ] = $row + [ 'c' => $c ];
				continue;
			}
			$row['pattern'] = self::pattern( $piece, $c['look'] );
			if ( '' === $row['pattern'] ) {
				$row['action'] = 'skip';
				$row['note'] = 'No pattern for this piece in that look.';
			} elseif ( 'element' === $def['kind'] && ! self::elements_on() ) {
				$row['action'] = 'skip';
				$row['note'] = 'Needs the Kadence Pro (Theme Kit Pro) Elements module.';
			} elseif ( 'element' === $def['kind'] && 'areas' === $piece && ! Roles::hubs()['areas'] ) {
				$row['action'] = 'skip';
				$row['note'] = 'No service areas hub page yet: give the /service-areas/ page the role areas_hub (set-page-role), then place it.';
			} elseif ( $row['id'] && self::edited( $piece, $row['id'] ) && ! $force ) {
				$row['action'] = 'keep';
				$row['note'] = 'Changed in Kadence since Launch Builder placed it: kept (force replaces it).';
			} else {
				$row['action'] = $row['id'] && get_post( $row['id'] ) ? 'update' : 'create';
				if ( $row['id'] && 'element' === $def['kind'] ) {
					$snap_posts[] = [ 'id' => $row['id'], 'meta' => self::element_meta_keys(), 'ledger' => 'design:' . $piece ];
				}
			}
			$plan[ $piece ] = $row + [ 'c' => $c ];
		}
		$style_look = ( $input['style'] ?? ( '' !== $look ) ) ? ( '' !== $look ? $look : '' ) : '';
		if ( ! empty( $input['style'] ) && '' === $style_look ) {
			$looks = array_unique( array_filter( array_column( $choices, 'look' ), static function ( $l ) { return 'none' !== $l; } ) );
			$style_look = 1 === count( $looks ) ? (string) reset( $looks ) : '';
		}
		$do_layouts = ! array_key_exists( 'layouts', $input ) || ! empty( $input['layouts'] );
		if ( $dry ) {
			foreach ( $plan as $piece => $row ) {
				unset( $row['c'] );
				$out['pieces'][ $piece ] = $row;
			}
			$out['style'] = '' !== $style_look ? $style_look : null;
			$out['layouts'] = $do_layouts ? self::layout_plan() : null;
			return $out;
		}

		$mods = array_merge( [ 'blocks_header', 'blocks_header_id' ], $do_layouts ? array_keys( self::layout_plan()['mods'] ) : [], '' !== $style_look ? Design::BUTTON_MODS : [] );
		$opts = array_merge( [ self::OPT, 'sidebars_widgets', 'widget_block' ], '' !== $style_look ? [ 'kadence_global_palette' ] : [] );
		$out['snapshot'] = Abilities::snapshot( 'set-design', $mods, $opts, $snap_posts );
		Compat::suppress_indexing( true );
		foreach ( $plan as $piece => $row ) {
			$c = $row['c'];
			unset( $row['c'] );
			if ( in_array( $row['action'], [ 'create', 'update' ], true ) ) {
				$r = self::place( $piece, $row['pattern'], $c, $state );
				if ( is_wp_error( $r ) ) {
					$row['action'] = 'failed';
					$row['note'] = $r->get_error_message();
				} else {
					$row['id'] = (int) $r;
					$state['pieces'][ $piece ] = [ 'look' => $c['look'], 'pattern' => $row['pattern'], 'id' => (int) $r, 'placement' => (string) ( $c['placement'] ?? ( $state['pieces'][ $piece ]['placement'] ?? ( self::PIECES[ $piece ]['placement'] ?? '' ) ) ), 'time' => time() ];
				}
			} elseif ( 'remove' === $row['action'] ) {
				self::remove( $piece, $state );
				unset( $state['pieces'][ $piece ] );
			}
			$out['pieces'][ $piece ] = $row;
			self::save_state( $state ); // After each piece, so a failure later keeps what was placed.
		}
		if ( '' !== $style_look ) {
			$out['style'] = self::apply_style( $style_look );
		}
		if ( $do_layouts ) {
			$out['layouts'] = self::apply_layouts();
		}
		Cache::purge_later();
		return $out;
	}

	/** Element meta keys Launch Builder writes (snapshotted before an update). */
	private static function element_meta_keys(): array {
		return [ '_kad_element_type', '_kad_element_hook', '_kad_element_hook_custom', '_kad_element_hook_priority', '_kad_element_show_conditionals', '_kad_element_hide_conditionals', Query_Lists::LIST_META, '_lb_piece', '_lb_look', '_lb_slot' ];
	}

	/** Was a piece's object changed outside Launch Builder since it was placed? */
	private static function edited( string $piece, int $id ): bool {
		if ( 'sidebar' === self::PIECES[ $piece ]['kind'] || ! get_post( $id ) ) {
			return false;
		}
		$meta = 'element' === self::PIECES[ $piece ]['kind'] ? [ '_kad_element_hook', '_kad_element_show_conditionals', '_kad_element_hide_conditionals' ] : [];
		return Ledger::is_edited( 'design:' . $piece, Ledger::hash_post( $id, $meta ) );
	}

	/** Write one piece. @return int|\WP_Error the object's post id (0 for the sidebar) */
	private static function place( string $piece, string $slug, array $c, array &$state ) {
		$def = self::PIECES[ $piece ];
		if ( 'header' === $def['kind'] ) {
			$r = Nav::set_header( [ 'pattern' => $slug, 'no_snapshot' => true ] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$id = (int) $r['header_id'];
			update_post_meta( $id, '_lb_piece', $piece );
			update_post_meta( $id, '_lb_look', $c['look'] );
			Ledger::record( 'design:' . $piece, 'kadence_header', $id, Ledger::hash_post( $id ), 'publish', get_the_title( $id ) );
			return $id;
		}
		$built = Patterns::build_pattern( $slug );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$blocks = parse_blocks( (string) $built['content'] );
		$lists = Query_Lists::materialize( $blocks, 'design-' . $piece );
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		$content = serialize_blocks( $blocks );
		if ( 'sidebar' === $def['kind'] ) {
			return self::place_sidebar( $content, $state );
		}
		$title = 'Launch Builder: ' . $def['label'];
		$id = Abilities::upsert_post( 'kadence_element', 'design-' . $piece, $title, '' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$rekeyed = Unique_Ids::rekey( $content, (int) $id, $piece );
		$content = is_wp_error( $rekeyed ) ? $content : $rekeyed;
		$u = wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( $content ), 'post_status' => 'publish' ], true );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		$placement = (string) ( $c['placement'] ?? ( $state['pieces'][ $piece ]['placement'] ?? ( $def['placement'] ?? '' ) ) );
		[ $show, $hide ] = self::rules( $piece );
		$hook = isset( $def['placement'] ) ? self::PLACEMENTS[ isset( self::PLACEMENTS[ $placement ] ) ? $placement : $def['placement'] ] : $def['hook'];
		$meta = [
			'_kad_element_type'              => $def['type'],
			'_kad_element_hook'              => $hook,
			'_kad_element_hook_custom'       => $def['custom'] ?? '',
			'_kad_element_hook_priority'     => (int) $def['priority'],
			'_kad_element_show_conditionals' => wp_json_encode( $show ),
			'_kad_element_hide_conditionals' => wp_json_encode( $hide ),
			'_lb_piece'                      => $piece,
			'_lb_look'                       => $c['look'],
			'_lb_slot'                       => $def['slot'],
			'_lb_pattern'                    => $slug,
		];
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		delete_post_meta( $id, Query_Lists::LIST_META );
		foreach ( $lists as $ql ) {
			add_post_meta( $id, Query_Lists::LIST_META, (int) $ql );
		}
		Ledger::record( 'design:' . $piece, 'kadence_element', $id, Ledger::hash_post( $id, [ '_kad_element_hook', '_kad_element_show_conditionals', '_kad_element_hide_conditionals' ] ), 'publish', $title );
		return (int) $id;
	}

	/** Kadence Elements show/hide rules for a piece (from its roles). @return array{0:array,1:array} */
	private static function rules( string $piece ): array {
		$roles = self::roles_of( $piece );
		$all = array_diff( array_keys( Roles::ROLES ), [ 'other' ] );
		$hide = [];
		if ( count( array_intersect( $roles, $all ) ) >= count( $all ) - 1 ) { // Every role (maybe but one).
			$show = [ [ 'rule' => 'general|site' ] ];
			if ( ! in_array( 'home', $roles, true ) ) {
				$hide[] = self::ROLE_RULES['home'];
			}
			return [ $show, $hide ];
		}
		$show = [];
		if ( 'areas' === $piece ) {
			return [ [ [ 'rule' => 'singular|page', 'select' => 'ids', 'ids' => [ (int) Roles::hubs()['areas'] ] ] ], [] ];
		}
		$pages = false;
		foreach ( $roles as $role ) {
			$rule = self::ROLE_RULES[ $role ] ?? self::ROLE_RULES['page']; // legal, conversion, thanks, hubs: pages.
			$pages = $pages || 'singular|page' === $rule['rule'];
			if ( ! in_array( $rule, $show, true ) ) {
				$show[] = $rule;
			}
		}
		if ( $pages && ! in_array( 'home', $roles, true ) ) {
			$hide[] = self::ROLE_RULES['home']; // The front page is a page too.
		}
		return [ $show, $hide ];
	}

	/** The sidebar piece: one block widget at the top of the theme sidebar (its own widgets stay below it). @return int 0 */
	private static function place_sidebar( string $content, array &$state ) {
		$instances = (array) get_option( 'widget_block', [] );
		$sw = wp_get_sidebars_widgets();
		$num = (int) ( $state['sidebar'][0] ?? 0 );
		if ( ! $num || ! isset( $instances[ $num ] ) ) {
			$nums = array_filter( array_keys( $instances ), 'is_int' );
			$num = $nums ? max( $nums ) + 1 : 2;
		}
		$instances[ $num ] = [ 'content' => $content ];
		$instances['_multiwidget'] = 1;
		update_option( 'widget_block', $instances );
		$list = array_values( array_diff( (array) ( $sw[ self::SIDEBAR ] ?? [] ), [ 'block-' . $num ] ) );
		$sw[ self::SIDEBAR ] = array_merge( [ 'block-' . $num ], $list );
		foreach ( $sw as $k => $l ) { // Out of the inactive widgets, wherever it was.
			if ( self::SIDEBAR !== $k && is_array( $l ) ) {
				$sw[ $k ] = array_values( array_diff( $l, [ 'block-' . $num ] ) );
			}
		}
		wp_set_sidebars_widgets( $sw );
		$state['sidebar'] = [ $num ];
		return 0;
	}

	/** Take a piece off the site: an element goes to the Trash, the header is switched off, the sidebar widget removed. */
	private static function remove( string $piece, array &$state ): void {
		$def = self::PIECES[ $piece ];
		$id = (int) ( $state['pieces'][ $piece ]['id'] ?? 0 );
		if ( 'sidebar' === $def['kind'] ) {
			$sw = wp_get_sidebars_widgets();
			foreach ( $state['sidebar'] as $num ) {
				foreach ( $sw as $k => $l ) {
					if ( is_array( $l ) ) {
						$sw[ $k ] = array_values( array_diff( $l, [ 'block-' . $num ] ) );
					}
				}
			}
			wp_set_sidebars_widgets( $sw );
			$state['sidebar'] = [];
			return;
		}
		if ( 'header' === $def['kind'] ) {
			if ( (int) get_theme_mod( 'blocks_header_id' ) === $id ) {
				set_theme_mod( 'blocks_header', false );
			}
			return;
		}
		if ( $id && get_post( $id ) ) {
			wp_trash_post( $id );
		}
		Ledger::forget( 'design:' . $piece );
	}

	/* ------------------------------------------------------------------ look: colours, buttons, Style Library */

	/** The look's palette and buttons as Kadence settings, plus a Style Library set when Kadence Blocks has the library. */
	public static function apply_style( string $look ): array {
		$st = self::LOOK_STYLE[ $look ] ?? null;
		if ( ! $st ) {
			return [];
		}
		$out = [];
		$brand = Design::set_brand( [ 'palette' => $st['palette'] ] );
		$out['palette'] = is_wp_error( $brand ) ? $brand->get_error_message() : 'set';
		$plan = Design::button_mods( [ 'primary' => $st['buttons'], 'secondary' => [ 'background' => 'palette3', 'color' => 'palette9', 'hover_background' => 'palette1', 'hover_color' => 'palette9', 'radius' => $st['buttons']['radius'] ] ] );
		foreach ( $plan as $k => $v ) {
			set_theme_mod( $k, $v );
		}
		$out['buttons'] = array_keys( $plan );
		$out['style_library'] = self::style_library( $look );
		Color::forget();
		return $out;
	}

	/**
	 * Save the look as a Kadence Style Library set ("Launch Builder: <look>") and make it the active one, so Kadence's own
	 * Style Library screen shows and edits it. Colours go to the brand and neutral primitives (Kadence maps them onto the
	 * theme palette); the button radius to the control radius. Returns the slug, or a note when this Kadence Blocks has no
	 * Style Library (before 3.8) or refused the set.
	 */
	public static function style_library( string $look ) {
		$st = self::LOOK_STYLE[ $look ] ?? null;
		$c = function_exists( 'kadence_blocks' ) ? kadence_blocks() : null;
		$store = '\\KadenceWP\\KadenceBlocks\\Design_Tokens\\Database\\Token_Store';
		$active = '\\KadenceWP\\KadenceBlocks\\Design_Tokens\\Database\\Active_Token_Library_Store';
		if ( ! $st || ! is_object( $c ) || ! method_exists( $c, 'get' ) || ! class_exists( $store ) || ! class_exists( $active ) ) {
			return 'not available (Kadence Blocks 3.8 or later has the Style Library)';
		}
		$p = $st['palette'];
		$color = static function ( string $hex ): array { return [ '$type' => 'color', '$value' => $hex ]; };
		$radius = 'pill' === $st['buttons']['radius'] ? '{primitive.dimension.radius.full}' : (int) $st['buttons']['radius'] . 'px';
		$doc = [
			'primitive' => [ 'color' => [
				'brand'   => [ 'primary' => $color( $p['palette1'] ), 'secondary' => $color( $p['palette2'] ), 'button' => $color( $p['palette1'] ), 'button-hover' => $color( $p['palette3'] ) ],
				'neutral' => [ '900' => $color( $p['palette3'] ), '700' => $color( $p['palette4'] ), '600' => $color( $p['palette5'] ), '500' => $color( $p['palette6'] ), '100' => $color( $p['palette7'] ), '50' => $color( $p['palette8'] ), '0' => $color( $p['palette9'] ) ],
			] ],
			'semantic'  => [
				'color'  => [ 'button-bg' => [ '$type' => 'color', '$value' => '{primitive.color.brand.button}' ], 'button-bg-hover' => [ '$type' => 'color', '$value' => '{primitive.color.brand.button-hover}' ], 'button-text' => $color( $p['palette9'] ), 'button-text-hover' => $color( $p['palette9'] ) ],
				'radius' => [ 'control' => [ '$type' => 'dimension', '$value' => $radius ] ],
			],
		];
		$slug = 'lb-' . $look;
		try {
			$validator = '\\KadenceWP\\KadenceBlocks\\Design_Tokens\\Schema\\Validation\\Dtcg_Validator';
			if ( class_exists( $validator ) && method_exists( $validator, 'get_context_overrides' ) ) {
				$res = $c->get( $validator )->validate( $doc, $validator::get_context_overrides() );
				if ( is_object( $res ) && method_exists( $res, 'is_valid' ) && ! $res->is_valid() ) {
					return 'refused by Kadence (the set did not validate)';
				}
			}
			$c->get( $store )->save_document( (string) wp_json_encode( $doc ), $slug, 'Launch Builder: ' . self::LOOKS[ $look ] );
			$c->get( $active )->set( $slug );
		} catch ( \Throwable $e ) {
			return 'not saved: ' . $e->getMessage();
		}
		return $slug;
	}

	/* ------------------------------------------------------------------ layouts */

	/**
	 * The standard layouts as Customizer settings and page meta: the title area is the hero ("above") for pages, services
	 * and areas; those three get the right sidebar; the front page and contact pages are full width without a sidebar.
	 *
	 * @return array{mods:array<string,mixed>,pages:array<int,array>}
	 */
	public static function layout_plan(): array {
		$mods = [];
		foreach ( [ 'page', Entries::SERVICE, Entries::AREA ] as $pt ) {
			$mods[ $pt . '_title' ] = true;
			$mods[ $pt . '_title_layout' ] = 'above';
			$mods[ $pt . '_layout' ] = 'right';
			$mods[ $pt . '_content_style' ] = 'unboxed';
			$mods[ $pt . '_sidebar_id' ] = self::SIDEBAR;
		}
		$mods['page_title_element_breadcrumb'] = [ 'enabled' => false, 'show_title' => true ]; // The breadcrumb bar is the trail.
		$pages = [];
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( $front ) {
			$pages[ $front ] = [ 'width' => 'fullwidth', 'style' => 'unboxed', 'padding' => 'hide' ];
		}
		foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => Roles::META, 'meta_value' => 'conversion' ] ) as $pid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$pages[ (int) $pid ] = [ 'width' => 'fullwidth', 'style' => 'unboxed' ];
		}
		return [ 'mods' => $mods, 'pages' => $pages ];
	}

	public static function apply_layouts(): array {
		$plan = self::layout_plan();
		foreach ( $plan['mods'] as $k => $v ) {
			set_theme_mod( $k, $v );
		}
		foreach ( $plan['pages'] as $pid => $l ) {
			Content::set_layout( (int) $pid, $l );
		}
		return [ 'theme' => array_keys( $plan['mods'] ), 'pages' => array_keys( $plan['pages'] ) ];
	}

	/* ------------------------------------------------------------------ breadcrumbs */

	/**
	 * The breadcrumb bar prints Rank Math's breadcrumb block. Without Rank Math that block does not exist, so it is
	 * registered here and prints Kadence's own trail (the theme's breadcrumbs): the bar works either way.
	 */
	public static function crumbs_block(): void {
		$reg = \WP_Block_Type_Registry::get_instance();
		if ( $reg->is_registered( 'rank-math/breadcrumb' ) ) {
			return;
		}
		register_block_type( 'rank-math/breadcrumb', [ 'render_callback' => [ __CLASS__, 'render_crumbs' ] ] );
	}

	public static function render_crumbs(): string {
		if ( function_exists( 'rank_math_get_breadcrumbs' ) ) {
			return (string) rank_math_get_breadcrumbs();
		}
		if ( function_exists( 'kadence' ) && is_object( kadence() ) && method_exists( kadence(), 'get_breadcrumb' ) ) {
			return (string) kadence()->get_breadcrumb( [ 'show_title' => true ] );
		}
		return '';
	}

	/** Kadence's trail: services and areas sit under their hub page (the hubs are pages, not archives). */
	public static function kadence_crumb_types( $types ) {
		$types = is_array( $types ) ? $types : [];
		$hubs = Roles::hubs();
		foreach ( [ Entries::SERVICE => 'services', Entries::AREA => 'areas' ] as $pt => $slot ) {
			if ( $hubs[ $slot ] && ! isset( $types[ $pt ] ) ) {
				$types[ $pt ] = [ 'post_type' => $pt, 'taxonomy' => '', 'archive_page' => $hubs[ $slot ], 'archive_label' => '' ];
			}
		}
		return $types;
	}

	/** Rank Math's trail: the same hub page after Home on services and areas. */
	public static function rank_math_crumbs( $crumbs, $class = null ) {
		unset( $class );
		if ( ! is_array( $crumbs ) || ! is_singular( [ Entries::SERVICE, Entries::AREA ] ) || count( $crumbs ) < 1 ) {
			return $crumbs;
		}
		$hub = Roles::hubs()[ is_singular( Entries::AREA ) ? 'areas' : 'services' ];
		if ( ! $hub || 'publish' !== get_post_status( $hub ) ) {
			return $crumbs;
		}
		$url = (string) get_permalink( $hub );
		foreach ( $crumbs as $c ) {
			if ( isset( $c[1] ) && $c[1] === $url ) {
				return $crumbs;
			}
		}
		array_splice( $crumbs, 1, 0, [ [ get_the_title( $hub ), $url, 'hide_in_schema' => false ] ] );
		return $crumbs;
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'get-designs', 'List the design pieces and looks', 'The Design hub: every design piece (header, hero-home, hero-service, hero-area, hero-page, breadcrumbs, subservices, areas, area-services, cta, footer, sidebar), what it is placed as, the look placed now (or none), whether it was changed in Kadence since, and the pattern of each look (auto-shop, home-services, landscaping) with a preview URL.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_tool' ], 'edit_theme_options', [ 'readonly' => true ] );

		Abilities::add( 'set-design', 'Choose the designs', 'Place design pieces as native Kadence objects (block header, Elements with display rules, a Query Loop for each list, sidebar widgets). look: one look for every piece (auto-shop, home-services, landscaping), which also sets that look\'s colours and buttons (Kadence palette, button settings and a Kadence Style Library set). pieces: { piece: look | "none" | { look, placement: top|middle|bottom } } to change single pieces (placement: where a list sits in the content). A piece changed in Kadence since it was placed is kept unless force. style: false keeps the current colours. layouts (default true): the hero replaces the title area, pages/services/areas get the right sidebar, the front page and contact pages are full width. dry_run returns the plan. Snapshot first.',
			[ 'properties' => [
				'look'    => [ 'type' => 'string', 'enum' => array_keys( self::LOOKS ) ],
				'pieces'  => [ 'type' => 'object', 'description' => 'piece => look, "none", or { look, placement }.' ],
				'force'   => [ 'type' => 'boolean' ],
				'style'   => [ 'type' => 'boolean' ],
				'layouts' => [ 'type' => 'boolean' ],
				'dry_run' => [ 'type' => 'boolean' ],
			] ], [ __CLASS__, 'set_tool' ], 'edit_theme_options' );
	}

	public static function get_tool(): array {
		$placed = self::placements();
		$state = self::state();
		$pieces = [];
		foreach ( self::PIECES as $piece => $def ) {
			$looks = [];
			foreach ( array_keys( self::LOOKS ) as $look ) {
				$slug = self::pattern( $piece, $look );
				$looks[ $look ] = '' === $slug ? null : [ 'pattern' => $slug, 'preview' => add_query_arg( [ 'lb_preview' => $slug ], home_url( '/' ) ) ];
			}
			$id = (int) ( $state['pieces'][ $piece ]['id'] ?? 0 );
			$pieces[ $piece ] = [
				'label'     => $def['label'],
				'placed_as' => [ 'header' => 'Kadence block header', 'element' => 'Kadence Element', 'sidebar' => 'Sidebar widget' ][ $def['kind'] ],
				'look'      => $placed[ $piece ]['look'] ?? null,
				'id'        => $id ?: null,
				'edited'    => $id ? self::edited( $piece, $id ) : false,
				'placement' => isset( $def['placement'] ) ? ( ( $placed[ $piece ]['placement'] ?? '' ) ?: $def['placement'] ) : null,
				'shows_on'  => self::roles_of( $piece ),
				'looks'     => $looks,
			];
		}
		return [ 'look' => self::current_look() ?: null, 'looks' => self::LOOKS, 'elements_module' => self::elements_on(), 'query_loop' => Query_Lists::available(), 'pieces' => $pieces ];
	}

	public static function set_tool( $input ) {
		return self::set( (array) $input );
	}

	/** Module hook: the placed designs in site-state. */
	public static function site_state( array &$state ): void {
		$state['designs'] = [ 'look' => self::current_look() ?: null, 'placed' => array_map( static function ( $p ) { return $p['look']; }, self::placements() ) ];
	}

	/** Module hook: how designs work, in get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['designs'] = 'Design hub: twelve pieces (header, hero-home, hero-service, hero-area, hero-page, breadcrumbs, subservices, areas, area-services, cta, footer, sidebar), each in three looks (auto-shop, home-services, landscaping). set-design look places every piece in one look with its colours; set-design pieces changes one piece. They are native Kadence objects (block header, Elements, Query Loops, sidebar widgets): edit them in Kadence, and Launch Builder keeps your edits. Lists of sub-services and area services follow the page they show on.';
	}

	/** Module hook: audit checks (a placed piece whose object is gone). */
	public static function audit_checks(): array {
		return [ 'design_piece_missing' => [ 'designs', [ __CLASS__, 'check_missing' ] ] ];
	}

	public static function check_missing(): array {
		$out = [];
		$placed = self::placements();
		foreach ( self::state()['pieces'] as $piece => $p ) {
			if ( isset( self::PIECES[ $piece ] ) && ! isset( $placed[ $piece ] ) ) {
				$out[] = [ 'severity' => 'warning', 'code' => 'design_piece_missing', 'object_type' => 'design', 'id' => $piece, 'url' => admin_url( 'admin.php?page=' . Design_Hub_Admin::PAGE ), 'message' => self::PIECES[ $piece ]['label'] . ' was placed but its Kadence object is gone, unpublished or no longer used, so it does not show.', 'fix' => 'Choose it again in the Design hub (set-design pieces { ' . $piece . ': "' . ( $p['look'] ?? 'home-services' ) . '" }).' ];
			}
		}
		return $out;
	}
}
