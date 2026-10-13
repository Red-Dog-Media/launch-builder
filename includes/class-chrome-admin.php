<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Chrome admin: makes the Kadence Elements and synced patterns Launch Builder manages self-explanatory in wp-admin.
 *
 *  - Lists (Kadence Elements, synced patterns): a "Launch Builder (N)" view that shows only what Launch Builder made or
 *    manages, and a "Used for" column that names the page types a design covers in plain words.
 *  - Edit screens (element, synced pattern, block header): a note at the top that says what the design is, which page
 *    types it covers, that changing it changes all of them, and a button into the Design hub to change the design or pick
 *    another one. In the block editor the same note is an editor notice (the editor covers page-level notices).
 *
 * Nothing here writes data. The names come from Chrome (Chrome::title(), Chrome::page_types()); what a post is for comes
 * from Chrome::managed(). The look is the shared admin stylesheet (assets/admin/lb-admin.css), which this class loads on
 * these screens.
 */
final class Chrome_Admin {

	/** Modules contract (see the Modules screen): always on. */
	const MODULE = [
		'slug'        => 'chrome-admin',
		'label'       => 'Elements screens',
		'description' => 'A Launch Builder view and a Used for column on the Kadence Elements and synced patterns lists, and a note on each shared design that says which page types it covers and where to change it.',
		'toggle'      => false,
	];

	/** List screens that get the view and the column. */
	const LIST_TYPES = [ 'kadence_element', 'wp_block' ];

	/** Edit screens that get the note. */
	const EDIT_TYPES = [ 'kadence_element', 'wp_block', 'kadence_header' ];

	/** Query arg of the Launch Builder view. */
	const VIEW = 'lb_managed';

	public static function boot(): void {
		foreach ( self::LIST_TYPES as $type ) {
			add_filter( 'views_edit-' . $type, [ __CLASS__, 'views' ], 20 );
			add_filter( 'manage_' . $type . '_posts_columns', [ __CLASS__, 'columns' ], 20 );
			add_action( 'manage_' . $type . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		}
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_list' ], 20 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'keep_view' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_notice' ] );
	}

	/* ------------------------------------------------------------------ helpers */

	/** The shared stylesheet, on the list and edit screens of the posts above. */
	public static function assets( $hook = '' ): void {
		unset( $hook );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$list = 'edit' === $screen->base && in_array( $screen->post_type, self::LIST_TYPES, true );
		$edit = 'post' === $screen->base && in_array( $screen->post_type, self::EDIT_TYPES, true );
		if ( $list || ( $edit && ! self::is_block_editor() ) ) { // The block editor shows the note as an editor notice and needs no stylesheet.
			wp_enqueue_style( 'lb-admin', LB_URL . 'assets/admin/lb-admin.css', [], LB_VERSION );
		}
	}

	/** IDs of the posts of a type that Launch Builder made or manages (it has a Launch Builder key or a slot; trash not counted). @return int[] */
	private static function managed_ids( string $type ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a small read of the posts Launch Builder owns, run only on the two list screens.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND p.ID IN ( SELECT m.post_id FROM {$wpdb->postmeta} m WHERE m.meta_key IN ('_lb_key','_lb_slot') )", $type ) );
		return array_map( 'intval', (array) $ids );
	}

	private static function view_on(): bool {
		return ! empty( $_GET[ self::VIEW ] ); // phpcs:ignore WordPress.Security.NonceVerification -- a read-only list filter.
	}

	/** How many posts of a type Launch Builder made or manages (trash not counted). */
	private static function count( string $type ): int {
		return count( self::managed_ids( $type ) );
	}

	/* ------------------------------------------------------------------ lists */

	/** Add the Launch Builder view next to All / Published / Draft. */
	public static function views( $views ) {
		$views = (array) $views;
		$screen = get_current_screen();
		$type = $screen ? (string) $screen->post_type : '';
		if ( ! in_array( $type, self::LIST_TYPES, true ) ) {
			return $views;
		}
		$n = self::count( $type );
		if ( ! $n ) {
			return $views;
		}
		$on = self::view_on();
		if ( $on ) { // Only one view is "current" at a time.
			foreach ( $views as $k => $v ) {
				$views[ $k ] = str_replace( [ ' class="current"', ' aria-current="page"' ], '', (string) $v );
			}
		}
		$url = add_query_arg( [ 'post_type' => $type, self::VIEW => 1 ], admin_url( 'edit.php' ) );
		$views['lb_managed'] = '<a href="' . esc_url( $url ) . '"' . ( $on ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Launch Builder', 'launch-builder' ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		return $views;
	}

	/** restrict_manage_posts: the list form carries the view along, so a search or a bulk action stays inside it. */
	public static function keep_view( $post_type = '' ): void {
		if ( self::view_on() && in_array( $post_type, self::LIST_TYPES, true ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::VIEW ) . '" value="1">';
		}
	}

	/** The Launch Builder view: only posts Launch Builder made or manages (added to Kadence's own type filter, never replacing it). */
	public static function filter_list( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! self::view_on() ) {
			return;
		}
		if ( ! in_array( $query->get( 'post_type' ), self::LIST_TYPES, true ) ) {
			return;
		}
		$ids = self::managed_ids( (string) $query->get( 'post_type' ) );
		$query->set( 'post__in', $ids ? $ids : [ 0 ] );
		if ( empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a read-only list order.
			$query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ); // The order of the page (header, hero, crumbs ... footer), so the list reads as an outline.
		}
	}

	/** "Used for" straight after the title. */
	public static function columns( $cols ): array {
		$out = [];
		$done = false;
		foreach ( (array) $cols as $k => $label ) {
			$out[ $k ] = $label;
			if ( 'title' === $k ) {
				$out['lb_used_for'] = esc_html__( 'Used for', 'launch-builder' );
				$done = true;
			}
		}
		if ( ! $done ) {
			$out['lb_used_for'] = esc_html__( 'Used for', 'launch-builder' );
		}
		return $out;
	}

	/** The cell: the page types in plain words, and below them which part of the page it is. */
	public static function column( $column, $post_id ): void {
		if ( 'lb_used_for' !== $column ) {
			return;
		}
		$m = Chrome::managed( (int) $post_id );
		if ( ! $m ) {
			if ( '' !== (string) get_post_meta( (int) $post_id, '_lb_key', true ) ) { // Made by Launch Builder, but not one of the shared designs.
				echo '<div class="lb-wrap lb-cell"><span>' . esc_html__( 'Made by Launch Builder', 'launch-builder' ) . '</span></div>';
				return;
			}
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not managed by Launch Builder', 'launch-builder' ) . '</span>';
			return;
		}
		$types = $m['roles'] ? Chrome::page_types( $m['slot'], $m['roles'] ) : __( 'No page types right now', 'launch-builder' );
		$by = [ 'design' => '', 'adopted' => __( 'your own element', 'launch-builder' ), 'legacy' => __( 'older Launch Builder element', 'launch-builder' ) ][ $m['kind'] ] ?? '';
		echo '<div class="lb-wrap lb-cell"><strong>' . esc_html( $types ) . '</strong><span>' . esc_html( Chrome::slot_name( $m['slot'] ) . ( '' !== $by ? ' · ' . $by : '' ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- both parts are escaped.
	}

	/* ------------------------------------------------------------------ edit screens */

	/** The id of the post being edited when this screen should carry the note (right type, the user may edit it), else 0. */
	private static function current_id(): int {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, self::EDIT_TYPES, true ) ) {
			return 0;
		}
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- reading which post is open.
		return $id && current_user_can( 'edit_post', $id ) ? $id : 0;
	}

	/** A slot as part of a sentence ("the hero"). */
	private static function slot_phrase( string $slot ): string {
		$p = [
			'header'        => __( 'the header', 'launch-builder' ),
			'notice'        => __( 'the notice bar', 'launch-builder' ),
			'hero'          => __( 'the hero', 'launch-builder' ),
			'crumbs'        => __( 'the breadcrumbs', 'launch-builder' ),
			'template'      => __( 'the page template', 'launch-builder' ),
			'notfound_body' => __( 'the 404 content', 'launch-builder' ),
			'before_footer' => __( 'the section before the footer', 'launch-builder' ),
			'footer'        => __( 'the footer', 'launch-builder' ),
			'sticky_bar'    => __( 'the sticky call bar', 'launch-builder' ),
		];
		return $p[ $slot ] ?? strtolower( Chrome::slot_name( $slot ) );
	}

	/** The words of the note: [ heading, what it is and covers, what to do ]. @return array{0:string,1:string,2:string} */
	private static function words( array $m ): array {
		$part = self::slot_phrase( $m['slot'] );
		$types = $m['roles'] ? Chrome::page_types( $m['slot'], $m['roles'] ) : '';
		$has = '' !== $types;
		if ( 'adopted' === $m['kind'] ) {
			return [
				__( 'Launch Builder places this element for you', 'launch-builder' ),
				/* translators: 1: part of the page, such as "the hero"; 2: page types, such as "Service pages" */
				$has ? sprintf( __( 'It is your own element, used as %1$s on: %2$s. Launch Builder never changes what is inside it.', 'launch-builder' ), $part, $types )
					/* translators: %s: part of the page, such as "the hero" */
					: sprintf( __( 'It is your own element, used as %s, but no page type uses it right now. Launch Builder never changes what is inside it.', 'launch-builder' ), $part ),
				__( 'Edit it here as you like. To swap it for a ready-made design, use the Design hub.', 'launch-builder' ),
			];
		}
		if ( 'legacy' === $m['kind'] ) {
			return [
				__( 'An older Launch Builder element', 'launch-builder' ),
				/* translators: 1: part of the page, such as "the hero"; 2: page types, such as "Service pages" */
				$has ? sprintf( __( 'It prints %1$s on: %2$s, using the display rules set here in Kadence.', 'launch-builder' ), $part, $types )
					/* translators: %s: part of the page, such as "the hero" */
					: sprintf( __( 'It prints %s, but its display rules reach no page type right now.', 'launch-builder' ), $part ),
				__( 'The Design hub can replace it with a shared design that covers the same page types in one place.', 'launch-builder' ),
			];
		}
		if ( 'kadence_element' === $m['type'] ) {
			return [
				__( 'Launch Builder looks after this element', 'launch-builder' ),
				/* translators: 1: part of the page, such as "the hero"; 2: page types, such as "Service pages" */
				$has ? sprintf( __( 'It places %1$s on: %2$s. The design sits in a synced pattern inside it, so editing the design changes all of those pages at once.', 'launch-builder' ), $part, $types )
					/* translators: %s: part of the page, such as "the hero" */
					: sprintf( __( 'It places %s, but no page type uses it right now. The design sits in a synced pattern inside it.', 'launch-builder' ), $part ),
				__( 'To use a different design instead, pick another in the Design hub.', 'launch-builder' ),
			];
		}
		return [
			__( 'This design is looked after by Launch Builder', 'launch-builder' ),
			/* translators: 1: part of the page, such as "the hero"; 2: page types, such as "Service pages" */
			$has ? sprintf( __( 'It is the design behind %1$s on: %2$s. Editing it here changes all of them.', 'launch-builder' ), $part, $types )
				/* translators: %s: part of the page, such as "the hero" */
				: sprintf( __( 'It is the design behind %s, but no page type uses it right now.', 'launch-builder' ), $part ),
			__( 'To use a different design instead, pick another in the Design hub.', 'launch-builder' ),
		];
	}

	/** The page types as pills (one per type, or one summary when there are many). @return string[] */
	private static function pills( array $m ): array {
		if ( ! $m['roles'] ) {
			return [];
		}
		$known = array_values( array_intersect( array_keys( Chrome::ROLE_NAMES ), $m['roles'] ) );
		if ( count( $known ) > 6 ) {
			return [ Chrome::page_types( $m['slot'], $m['roles'] ) ];
		}
		return array_map( static function ( $r ) { return Chrome::ROLE_NAMES[ $r ][0]; }, $known );
	}

	/** Where "Change design" goes: the Design hub on the first page type of the design, at the slot's card. */
	private static function hub_url( array $m ): string {
		$first = array_values( array_intersect( array_keys( Chrome::ROLE_NAMES ), $m['roles'] ) );
		$args = [ 'tab' => 'placed' ];
		if ( $first ) {
			$args['role'] = $first[0];
		}
		return Design_Hub::url( $args ) . '#lb-slot-' . $m['slot'];
	}

	/** Edit link of another post, '' when it is missing or not editable by this user. */
	private static function edit_url( int $id ): string {
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) || 'trash' === get_post_status( $id ) ) {
			return '';
		}
		return (string) get_edit_post_link( $id, 'raw' );
	}

	/**
	 * Everything the note says, as plain data (the block editor shows it as an editor notice, the classic screens as HTML).
	 *
	 * @return array{heading:string,text:string,hint:string,pills:string[],extra:string[],hub:string,other:array{label:string,url:string}|null}|null
	 */
	public static function note( int $id ): ?array {
		$m = Chrome::managed( $id );
		if ( ! $m ) {
			return null;
		}
		[ $heading, $text, $hint ] = self::words( $m );
		$extra = [];
		if ( 'publish' !== get_post_status( $id ) ) {
			$extra[] = __( 'It is not published, so it shows on no page.', 'launch-builder' );
		}
		if ( Chrome::edited( $id ) ) {
			$extra[] = __( 'You have edited it, so Launch Builder keeps your version when it applies the shared designs again.', 'launch-builder' );
		}
		$other = null;
		if ( 'design' === $m['kind'] ) {
			if ( 'kadence_element' === $m['type'] && $m['store'] && self::edit_url( $m['store'] ) ) {
				$other = [ 'label' => __( 'Edit the design', 'launch-builder' ), 'url' => self::edit_url( $m['store'] ) ];
			} elseif ( 'kadence_element' !== $m['type'] && $m['element'] && self::edit_url( $m['element'] ) ) {
				$other = [ 'label' => __( 'Open the element', 'launch-builder' ), 'url' => self::edit_url( $m['element'] ) ];
			}
		}
		return [
			'heading' => $heading,
			'text'    => $text,
			'hint'    => $hint,
			'pills'   => self::pills( $m ),
			'extra'   => $extra,
			'hub'     => current_user_can( 'manage_options' ) ? self::hub_url( $m ) : '',
			'other'   => $other,
		];
	}

	/** The note at the top of the edit screen. */
	public static function notice(): void {
		$id = self::current_id();
		if ( ! $id || self::is_block_editor() ) {
			return; // The block editor gets an editor notice (editor_notice()).
		}
		$n = self::note( $id );
		if ( ! $n ) {
			return;
		}
		echo self::note_html( $n ); // phpcs:ignore WordPress.Security.EscapeOutput -- note_html() escapes every part.
	}

	private static function is_block_editor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	/** @param array $n note() */
	private static function note_html( array $n ): string {
		$out = '<div class="lb-wrap lb-elnote" role="region" aria-label="' . esc_attr__( 'About this design', 'launch-builder' ) . '">';
		$out .= '<span class="lb-slot__tile" aria-hidden="true">' . Dashboard::icon( 'layout' ) . '</span>';
		$out .= '<div class="lb-elnote__body"><h2>' . esc_html( $n['heading'] ) . '</h2><p>' . esc_html( $n['text'] ) . ' ' . esc_html( $n['hint'] ) . '</p>';
		if ( $n['pills'] ) {
			$out .= '<span class="lb-pills">';
			foreach ( $n['pills'] as $p ) {
				$out .= '<span class="lb-pill">' . esc_html( $p ) . '</span>';
			}
			$out .= '</span>';
		}
		foreach ( $n['extra'] as $line ) {
			$out .= '<p class="lb-note">' . esc_html( $line ) . '</p>';
		}
		$out .= '</div><div class="lb-elnote__actions">';
		if ( '' !== $n['hub'] ) {
			$out .= '<a class="lb-btn lb-btn--primary" href="' . esc_url( $n['hub'] ) . '">' . Dashboard::icon( 'sliders' ) . esc_html__( 'Change design in the Design hub', 'launch-builder' ) . '</a>';
		}
		if ( $n['other'] ) {
			$out .= '<a class="lb-btn" href="' . esc_url( $n['other']['url'] ) . '">' . esc_html( $n['other']['label'] ) . '</a>';
		}
		return $out . '</div></div>';
	}

	/** In the block editor the note is an editor notice with the same words and buttons. */
	public static function editor_notice(): void {
		$id = self::current_id();
		$n = $id ? self::note( $id ) : null;
		if ( ! $n ) {
			return;
		}
		$actions = [];
		if ( '' !== $n['hub'] ) {
			$actions[] = [ 'label' => __( 'Change design in the Design hub', 'launch-builder' ), 'url' => $n['hub'] ];
		}
		if ( $n['other'] ) {
			$actions[] = $n['other'];
		}
		$msg = $n['heading'] . '. ' . $n['text'] . ' ' . $n['hint'] . ( $n['extra'] ? ' ' . implode( ' ', $n['extra'] ) : '' );
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; // Safe inside a script element whatever the words hold.
		wp_register_script( 'lb-chrome-note', false, [ 'wp-data', 'wp-dom-ready', 'wp-notices' ], LB_VERSION, true );
		wp_enqueue_script( 'lb-chrome-note' );
		wp_add_inline_script( 'lb-chrome-note', 'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("info",' . wp_json_encode( $msg, $flags ) . ',{id:"lb-chrome-note",isDismissible:true,actions:' . wp_json_encode( $actions, $flags ) . '.map(function(a,i){return{label:a.label,url:a.url,variant:0===i?"primary":"secondary"};})});});' );
	}
}
