<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Design hub (Launch Builder > Design hub, page slug launch-builder-patterns).
 *
 * Tab "Placed designs": a role switcher, the real front-end page of a sample object of that role in a frame at desktop,
 * tablet and mobile widths, and beside it every chrome slot with the design that role gets: the pattern, whether it is
 * applied, where it lives (Kadence Element, synced pattern or Launch Builder itself) with Edit links, a link to the
 * pattern in the library and a Change design control. Changes go through Chrome::set_tool() (a snapshot is saved
 * first) behind a nonce and a capability check, and the last change can be undone. Kadence Conversions items are listed
 * on the side.
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

	/** Widths of the page frame (the page is rendered at this width and scaled to fit the pane). */
	const WIDTHS = [ 'desktop' => 1280, 'tablet' => 820, 'mobile' => 390 ];

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

	/** @return array{0:string,1:string} name, what it is. */
	private static function slot_info( string $slot ): array {
		switch ( $slot ) {
			case 'notice':
				return [ __( 'Notice bar', 'launch-builder' ), __( 'A dated bar above the header', 'launch-builder' ) ];
			case 'header':
				return [ __( 'Header', 'launch-builder' ), __( 'Logo, menu and call to action', 'launch-builder' ) ];
			case 'hero':
				return [ __( 'Hero', 'launch-builder' ), __( 'The title banner at the top of the page', 'launch-builder' ) ];
			case 'crumbs':
				return [ __( 'Breadcrumbs', 'launch-builder' ), __( 'The trail under the hero', 'launch-builder' ) ];
			case 'template':
				return [ __( 'Page template', 'launch-builder' ), __( 'The body every service and area page shares', 'launch-builder' ) ];
			case 'notfound_body':
				return [ __( '404 content', 'launch-builder' ), __( 'What a missing page says', 'launch-builder' ) ];
			case 'before_footer':
				return [ __( 'Before the footer', 'launch-builder' ), __( 'Sections stacked above the footer', 'launch-builder' ) ];
			case 'footer':
				return [ __( 'Footer', 'launch-builder' ), __( 'Contact details, links and licence', 'launch-builder' ) ];
			case 'sticky_bar':
				return [ __( 'Sticky call bar', 'launch-builder' ), __( 'Call and quote buttons on phones', 'launch-builder' ) ];
		}
		return [ $slot, '' ];
	}

	private static function role_label( string $role ): string {
		switch ( $role ) {
			case 'home':
				return __( 'Home', 'launch-builder' );
			case 'page':
				return __( 'Page', 'launch-builder' );
			case 'legal':
				return __( 'Legal', 'launch-builder' );
			case 'services_hub':
				return __( 'Services hub', 'launch-builder' );
			case 'areas_hub':
				return __( 'Areas hub', 'launch-builder' );
			case 'category':
				return __( 'Category', 'launch-builder' );
			case 'service':
				return __( 'Service', 'launch-builder' );
			case 'area':
				return __( 'Area', 'launch-builder' );
			case 'conversion':
				return __( 'Conversion', 'launch-builder' );
			case 'thanks':
				return __( 'Thank you', 'launch-builder' );
			case 'post':
				return __( 'Blog post', 'launch-builder' );
			case 'blog':
				return __( 'Blog', 'launch-builder' );
			case 'search':
				return __( 'Search', 'launch-builder' );
			case 'notfound':
				return __( '404', 'launch-builder' );
		}
		return Roles::label( $role );
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
		$out = '<div class="wrap lb-wrap lb-hub">';
		$out .= '<header class="lb-top"><div class="lb-brand">' . self::brand_mark() . '<div><h1>' . esc_html__( 'Design hub', 'launch-builder' ) . '</h1><p class="lb-tag">' . esc_html__( 'Every shared design on a real page, where it lives, and one click to switch it for another.', 'launch-builder' ) . '</p></div></div><div class="lb-actions">' . self::button( __( 'Site health', 'launch-builder' ), Admin::url( 'launch-builder-health' ), '', 'shield' ) . self::button( __( 'Business info', 'launch-builder' ), Admin::url( 'launch-builder-business' ), '', 'business' ) . '</div></header><hr class="wp-header-end">';
		$out .= self::tabs( $tab, $role );
		$out .= self::flash();
		$out .= '<div class="lb-hub-panel" id="lb-hub-placed" role="tabpanel" aria-labelledby="lb-tab-placed"' . ( 'placed' === $tab ? '' : ' hidden' ) . '>' . self::panel_placed( $role, 'placed' === $tab ) . '</div>';
		$out .= '<div class="lb-hub-panel" id="lb-hub-library" role="tabpanel" aria-labelledby="lb-tab-library"' . ( 'library' === $tab ? '' : ' hidden' ) . '>' . self::panel_library() . '</div>';
		$out .= '</div>';
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- every dynamic part is escaped where it is built.
		self::config( $tab, $role );
	}

	/** The script's settings. The preview nonce is written as n="..." (tools that drive the preview route read it there). */
	private static function config( string $tab, string $role ): void {
		$t = [
			'rendering'  => __( 'Rendering this group (the first view takes a few seconds, later views are instant)…', 'launch-builder' ),
			'loading'    => __( 'Loading the page…', 'launch-builder' ),
			'use_with'   => __( 'Use it with: apply-pattern slug', 'launch-builder' ),
			'all_hint'   => __( 'Every pattern in this group, one after another. Click a pattern name on the left to jump to it.', 'launch-builder' ),
			'all_suffix' => __( '(all)', 'launch-builder' ),
			'busy'       => __( 'Working…', 'launch-builder' ),
			/* translators: 1: pattern name, 2: page type */
			'previewing' => __( 'Previewing %1$s on its own, as a %2$s page.', 'launch-builder' ),
			'back'       => __( 'Back to the live page', 'launch-builder' ),
			'in_use'     => __( 'In use:', 'launch-builder' ),
			'see_live'   => __( 'See it on a page', 'launch-builder' ),
		];
		echo '<script>window.lbHub=(function(){var base=' . wp_json_encode( home_url( '/' ) ) . ',n=' . wp_json_encode( wp_create_nonce( 'lb_preview' ) ) . ';return {base:base,n:n,tab:' . wp_json_encode( $tab ) . ',role:' . wp_json_encode( $role ) . ',url:' . wp_json_encode( self::url() ) . ',t:' . wp_json_encode( $t ) . '};})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
		$out = self::mode_bar();
		$out .= self::role_switcher( $role );
		$out .= '<div class="lb-hub-cols"><div class="lb-hub-main">' . self::stage_panel( $role, $sample, $active ) . '</div>';
		$out .= '<div class="lb-hub-side">' . self::slots_panel( $role, $sample, $gc ) . self::conversions_panel( $role ) . '</div></div>';
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
		$out = '<nav class="lb-roles" aria-label="' . esc_attr__( 'Page type', 'launch-builder' ) . '">';
		foreach ( self::roles() as $r ) {
			$out .= '<a class="lb-role" href="' . esc_url( self::url( [ 'tab' => 'placed', 'role' => $r ] ) ) . '"' . ( $r === $current ? ' aria-current="page"' : '' ) . ' data-role="' . esc_attr( $r ) . '" title="' . esc_attr( Roles::label( $r ) ) . '">' . esc_html( self::role_label( $r ) ) . '</a>';
		}
		return $out . '</nav>';
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
		$out .= '<div class="lb-stage__banner" data-lb-banner hidden></div>';
		$src = (string) ( $o['src'] ?? '' );
		$out .= '<div class="lb-stage__view"><div class="lb-stage__load" data-lb-loading role="status" hidden>' . esc_html( $o['loading'] ) . '</div><div class="lb-stage__frame" data-lb-frame><iframe title="' . esc_attr( $o['title'] ) . '"' . ( '' === $src ? '' : ( ! empty( $o['active'] ) ? ' src="' . esc_url( $src ) . '"' : ' data-src="' . esc_url( $src ) . '"' ) ) . '></iframe></div></div></div>';
		return $out;
	}

	private static function stage_panel( string $role, array $s, bool $active ): string {
		$label = self::role_label( $role );
		$out = '<section class="lb-panel lb-stagepanel" aria-labelledby="lb-stage-h"><div class="lb-panel__head"><h2 id="lb-stage-h">' . esc_html( sprintf( /* translators: %s: page type such as Service */ __( 'Live page: %s', 'launch-builder' ), $label ) ) . '</h2>';
		if ( $s['url'] ) {
			$edit = $s['post'] && current_user_can( 'edit_post', $s['post']->ID ) ? get_edit_post_link( $s['post']->ID, 'raw' ) : '';
			$out .= '<span class="lb-note" style="margin:0">' . ( $edit ? esc_html__( 'Sample:', 'launch-builder' ) . ' <a href="' . esc_url( $edit ) . '">' . esc_html( $s['label'] ) . '</a>' : esc_html( $s['label'] ) ) . '</span>';
		}
		$out .= '</div>';
		if ( ! $s['url'] ) {
			$out .= '<div class="lb-empty lb-empty--stage"><b>' . esc_html( sprintf( /* translators: %s: page type */ __( 'No %s page to show yet', 'launch-builder' ), strtolower( $label ) ) ) . '</b>' . esc_html( $s['hint'] ) . '<br><a class="lb-btn lb-btn--primary" style="margin-top:14px" href="' . esc_url( $s['hint_url'] ) . '">' . esc_html( $s['hint_cta'] ) . '</a></div></section>';
			return $out;
		}
		$out .= self::stage( [
			'id'      => 'lb-stage-live',
			'src'     => self::frame_url( $s['url'] ),
			'active'  => $active,
			'title'   => sprintf( /* translators: %s: page type */ __( 'Live preview of a %s page', 'launch-builder' ), $label ),
			'open'    => $s['url'],
			'default' => self::WIDTHS['desktop'],
			'widths'  => [ __( 'Desktop', 'launch-builder' ) => self::WIDTHS['desktop'], __( 'Tablet', 'launch-builder' ) => self::WIDTHS['tablet'], __( 'Mobile', 'launch-builder' ) => self::WIDTHS['mobile'] ],
			'loading' => __( 'Loading the page…', 'launch-builder' ),
		] );
		$out .= '<p class="lb-note">' . esc_html__( 'This is the real page, as a visitor gets it. A slot that is not applied yet shows nothing here. If the frame stays blank, the site or its host does not allow being framed: use the open-in-new-tab button.', 'launch-builder' ) . '</p></section>';
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
		$out = '<section class="lb-panel lb-placed" aria-labelledby="lb-placed-h"><div class="lb-panel__head"><h2 id="lb-placed-h">' . esc_html__( 'What is placed', 'launch-builder' ) . '</h2><span class="lb-chip lb-chip--' . ( $used && $live === $used ? 'ok' : 'plain' ) . '">' . esc_html( sprintf( /* translators: 1: slots live, 2: slots that apply */ __( '%1$d of %2$d live', 'launch-builder' ), $live, $used ) ) . '</span></div>';
		$out .= '<p class="lb-note" style="margin:-6px 0 14px">' . esc_html( sprintf( /* translators: %s: page type such as Service */ __( 'The design each slot gives a %s page. Edit it where it lives, see it in the library, or switch it for another.', 'launch-builder' ), self::role_label( $role ) ) ) . '</p>';
		$out .= '<ul class="lb-slots">';
		foreach ( $rows as $r ) {
			$out .= self::slot_card( $r, $role );
		}
		return $out . '</ul></section>';
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
			'places' => [], 'edited' => false, 'shared' => [], 'foreign' => [], 'missing' => [], 'note' => '', 'expires' => $g ? (string) ( $g['expires'] ?? '' ) : '',
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
				$row['places'][] = [ 'label' => __( 'Kadence Element', 'launch-builder' ), 'edit_label' => __( 'Edit the element', 'launch-builder' ), 'id' => (int) $el['id'], 'edit' => self::edit_link( (int) $el['id'] ) ];
			}
			if ( ! empty( $store['id'] ) && 'adopt' !== $kind ) {
				$row['places'][] = 'header' === $kind
					? [ 'label' => __( 'Block header', 'launch-builder' ), 'edit_label' => __( 'Edit the header', 'launch-builder' ), 'id' => (int) $store['id'], 'edit' => self::edit_link( (int) $store['id'] ) ]
					: [ 'label' => __( 'Synced pattern', 'launch-builder' ), 'edit_label' => __( 'Edit the synced pattern', 'launch-builder' ), 'id' => (int) $store['id'], 'edit' => self::edit_link( (int) $store['id'] ) ];
			}
			$row['edited'] = 'kept_edits' === ( $store['ledger'] ?? '' ) || 'kept_edits' === ( $el['ledger'] ?? '' );
			foreach ( (array) ( $g['roles'] ?? [] ) as $r2 ) {
				if ( $r2 !== $role ) {
					$row['shared'][] = self::role_label( (string) $r2 );
				}
			}
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

	/** Alternatives for a slot and role: [ made for this role, other designs of the slot ]. @return array{0:array,1:array} */
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

	private static function slot_card( array $r, string $role ): string {
		$slot = $r['slot'];
		$st = $r['status'];
		$d = $r['design'];
		$out = '<li class="lb-slot" id="lb-slot-' . esc_attr( $slot ) . '" data-slot="' . esc_attr( $slot ) . '" data-state="' . esc_attr( $st ) . '">';
		$out .= '<div class="lb-slot__head"><span class="lb-slot__tile">' . self::icon( $slot ) . '</span><div class="lb-slot__name"><b>' . esc_html( $r['name'] ) . '</b><span>' . esc_html( $r['hint'] ) . '</span></div><span class="lb-chip ' . esc_attr( self::chip_class( $st ) ) . '" data-lb-slot-state>' . esc_html( self::status_label( $st ) ) . '</span></div>';

		if ( 'na' === $st ) {
			return $out . '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( $r['na'] ) . '</p></li>';
		}

		// The design.
		$out .= '<div class="lb-slot__design">';
		if ( 'none' === $d['kind'] ) {
			$out .= '<span class="lb-slot__none">' . esc_html__( 'Nothing is placed here on purpose.', 'launch-builder' ) . '</span>';
		} elseif ( 'theme' === $d['kind'] ) {
			$out .= '<span class="lb-slot__none">' . esc_html__( 'This page type keeps the theme\'s own.', 'launch-builder' ) . '</span>';
		} elseif ( 'sections' === $d['kind'] ) {
			$out .= '<strong>' . esc_html( $d['label'] ) . '</strong><span class="lb-pills">';
			foreach ( $d['slugs'] as $slug ) {
				$out .= isset( Patterns::index()[ $slug ] ) ? '<a class="lb-pill" href="' . esc_url( self::library_link( $slug ) ) . '" title="' . esc_attr__( 'See it in the library', 'launch-builder' ) . '">' . esc_html( self::title( $slug ) ) . '</a>' : '<span class="lb-pill lb-pill--missing">' . esc_html( $slug ) . '</span>';
			}
			$out .= '</span>';
		} elseif ( 'pattern' === $d['kind'] ) {
			$out .= '<strong>' . esc_html( $d['label'] ) . '</strong> <code>' . esc_html( $d['slugs'][0] ) . '</code>' . ( $d['custom'] ? ' <span class="lb-chip lb-chip--plain">' . esc_html__( 'customised', 'launch-builder' ) . '</span>' : '' );
		} else {
			$out .= '<strong>' . esc_html( '' !== $d['label'] ? $d['label'] : ( 'adopt' === $d['kind'] ? __( 'A Kadence Element', 'launch-builder' ) : __( 'A synced pattern', 'launch-builder' ) ) ) . '</strong> <span class="lb-chip lb-chip--plain">' . esc_html( 'adopt' === $d['kind'] ? __( 'adopted element', 'launch-builder' ) : __( 'synced pattern', 'launch-builder' ) ) . '</span>';
		}
		$out .= '</div>';

		// What the state means.
		if ( 'unapplied' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'Your site does not show this yet. Applying places it for every page type that uses it.', 'launch-builder' ) . '</p>';
		} elseif ( 'stale' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'The design settings changed after the last apply. Apply to bring the site in line.', 'launch-builder' ) . '</p>';
		} elseif ( 'failed' === $st ) {
			$out .= '<p class="lb-slot__line">' . ( $r['missing'] ? esc_html( sprintf( /* translators: %s: pattern slug(s) */ __( 'The pattern %s is not in this version yet, so nothing is placed. Choose another design below.', 'launch-builder' ), implode( ', ', $r['missing'] ) ) ) : esc_html__( 'The last apply could not place this design. Apply again, or choose another design below.', 'launch-builder' ) ) . '</p>';
		} elseif ( 'off' === $st ) {
			$out .= '<p class="lb-slot__line">' . esc_html__( 'Its element or synced pattern was set to draft or moved to the trash, so nothing prints. Publish it again to bring it back.', 'launch-builder' ) . '</p>';
		} elseif ( in_array( $st, [ 'live' ], true ) && $r['missing'] ) {
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

		// Where it lives.
		if ( in_array( $st, [ 'live', 'off', 'stale' ], true ) ) {
			$where = '';
			if ( 'element' === $r['printer'] ) {
				$where = __( 'Placed by a Kadence Element', 'launch-builder' );
			} elseif ( 'lb' === $r['printer'] ) {
				$where = __( 'Placed by Launch Builder', 'launch-builder' );
			}
			$links = [];
			foreach ( $r['places'] as $pl ) {
				$links[] = $pl['edit'] ? '<a class="lb-edit" href="' . esc_url( $pl['edit'] ) . '">' . self::icon( 'edit' ) . esc_html( $pl['edit_label'] ) . '</a>' : '<span>' . esc_html( $pl['label'] ) . '</span>';
			}
			if ( '' !== $where || $links ) {
				$out .= '<p class="lb-slot__where">' . ( '' !== $where ? '<span>' . esc_html( $where ) . '</span>' : '' ) . implode( '', $links ) . '</p>';
			}
			if ( $r['edited'] ) {
				$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html__( 'You edited it in the editor, so Launch Builder keeps your version when it re-applies.', 'launch-builder' ) . '</p>';
			}
			if ( $r['shared'] ) {
				$out .= '<p class="lb-slot__line lb-slot__line--quiet">' . esc_html( sprintf( /* translators: %s: page types */ __( 'Also used on: %s.', 'launch-builder' ), implode( ', ', $r['shared'] ) ) ) . '</p>';
			}
		}

		// Actions.
		$foot = '';
		if ( in_array( $d['kind'], [ 'pattern' ], true ) && isset( Patterns::index()[ $d['slugs'][0] ] ) ) {
			$foot .= '<a class="lb-link" href="' . esc_url( self::library_link( $d['slugs'][0] ) ) . '">' . self::icon( 'library' ) . esc_html__( 'See in library', 'launch-builder' ) . '</a>';
		}
		if ( in_array( $st, [ 'unapplied', 'stale', 'failed' ], true ) && ! ( 'failed' === $st && $r['missing'] ) ) {
			$foot .= self::apply_form( $slot, $role, 'unapplied' === $st ? __( 'Apply', 'launch-builder' ) : __( 'Apply again', 'launch-builder' ) );
		}
		$foot .= self::change_form( $r, $role );
		if ( '' !== $foot ) {
			$out .= '<div class="lb-slot__foot">' . $foot . '</div>';
		}
		return $out . '</li>';
	}

	private static function post_fields( string $op, string $slot, string $role ): string {
		return '<input type="hidden" name="action" value="lb_hub_apply"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::APPLY ) ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="slot" value="' . esc_attr( $slot ) . '"><input type="hidden" name="role" value="' . esc_attr( $role ) . '">';
	}

	private static function apply_form( string $slot, string $role, string $label ): string {
		return '<form class="lb-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'apply', $slot, $role ) . '<button type="submit" class="lb-btn lb-btn--primary lb-btn--sm" data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function change_form( array $r, string $role ): string {
		$slot = $r['slot'];
		[ $fit, $other ] = self::alternatives( $slot, $role );
		$extra = self::extra_choices( $slot );
		if ( ! $fit && ! $other && ! $extra ) {
			return '<span class="lb-note lb-slot__nochoice">' . esc_html__( 'No other design for this slot yet.', 'launch-builder' ) . '</span>';
		}
		$cur = 'pattern' === $r['design']['kind'] && ! $r['design']['custom'] ? $r['design']['slugs'][0] : ( 'theme' === $r['design']['kind'] ? 'theme' : ( 'none' === $r['design']['kind'] ? '__none' : '' ) );
		$id = 'lb-pick-' . $slot;
		$out = '<details class="lb-change"><summary class="lb-btn lb-btn--sm">' . self::icon( 'swap' ) . esc_html__( 'Change design', 'launch-builder' ) . '</summary>';
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-lb-busy>' . self::post_fields( 'change', $slot, $role );
		$out .= '<label class="lb-field" for="' . esc_attr( $id ) . '">' . esc_html__( 'Design', 'launch-builder' ) . '</label><select name="design" id="' . esc_attr( $id ) . '" data-lb-pick required>';
		if ( '' === $cur ) {
			$out .= '<option value="" selected disabled>' . esc_html__( 'Choose a design…', 'launch-builder' ) . '</option>';
		}
		$opt = static function ( array $p ) use ( $cur ) {
			return '<option value="' . esc_attr( $p['slug'] ) . '" data-slug="' . esc_attr( $p['slug'] ) . '" data-title="' . esc_attr( $p['title'] ) . '"' . ( $p['slug'] === $cur ? ' selected' : '' ) . '>' . esc_html( $p['title'] ) . ' (' . esc_html( $p['slug'] ) . ')</option>';
		};
		if ( $fit ) {
			$out .= '<optgroup label="' . esc_attr( sprintf( /* translators: %s: page type */ __( 'Made for %s pages', 'launch-builder' ), self::role_label( $role ) ) ) . '">';
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
			$out .= '<optgroup label="' . esc_attr__( 'Other', 'launch-builder' ) . '">';
			foreach ( $extra as $v => $label ) {
				$out .= '<option value="' . esc_attr( $v ) . '"' . ( $v === $cur ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
			$out .= '</optgroup>';
		}
		$out .= '</select>';
		if ( self::follows_default( $slot, $role ) ) {
			$out .= '<label class="lb-field" for="' . esc_attr( $id ) . '-scope">' . esc_html__( 'Use it for', 'launch-builder' ) . '</label><select name="scope" id="' . esc_attr( $id ) . '-scope"><option value="role">' . esc_html( sprintf( /* translators: %s: page type */ __( 'Only %s pages', 'launch-builder' ), self::role_label( $role ) ) ) . '</option><option value="default">' . esc_html__( 'Every page type that follows the default', 'launch-builder' ) . '</option></select>';
		} elseif ( ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$out .= '<p class="lb-note" style="margin:6px 0 0">' . esc_html( 'crumbs' === $slot ? __( 'Applies to every page type that shows breadcrumbs.', 'launch-builder' ) : __( 'Applies to the whole site.', 'launch-builder' ) ) . '</p>';
		}
		$out .= '<div class="lb-change__actions"><button type="submit" class="lb-btn lb-btn--primary lb-btn--sm" data-busy="' . esc_attr__( 'Applying…', 'launch-builder' ) . '">' . esc_html__( 'Apply this design', 'launch-builder' ) . '</button>';
		if ( 'header' !== $slot && ( $fit || $other ) ) {
			$out .= '<button type="button" class="lb-btn lb-btn--sm" data-lb-preview="' . esc_attr( $id ) . '" data-role="' . esc_attr( $role ) . '" data-role-label="' . esc_attr( self::role_label( $role ) ) . '">' . esc_html__( 'Preview it', 'launch-builder' ) . '</button>';
		}
		$out .= '</div><p class="lb-note" style="margin:8px 0 0">' . esc_html__( 'A snapshot is saved first, and you can undo the change right afterwards.', 'launch-builder' ) . '</p></form></details>';
		return $out;
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
				$here = '<span class="lb-chip ' . ( $yes ? 'lb-chip--ok' : 'lb-chip--plain' ) . '">' . esc_html( $yes ? sprintf( /* translators: %s: page type */ __( 'Shows on %s pages', 'launch-builder' ), self::role_label( $role ) ) : sprintf( /* translators: %s: page type */ __( 'Hidden on %s pages', 'launch-builder' ), self::role_label( $role ) ) ) . '</span>';
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
	private static function map_for( string $slot, string $role, $value, string $scope, array $stored ): array {
		if ( 'crumbs' === $slot ) {
			return [ 'crumbs' => [ 'design' => $value ] ];
		}
		if ( in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$key = 'default' === $scope ? 'default' : $role;
			$out = [ $key => $value ];
			if ( 'default' !== $key && array_key_exists( $slot, $stored ) && ( ! is_array( $stored[ $slot ] ) || self::is_design_object( $stored[ $slot ] ) ) ) {
				$out['default'] = $stored[ $slot ]; // The slot was one design for every role: keep it as the default.
			}
			return [ $slot => $out ];
		}
		return [ $slot => $value ];
	}

	/** The map that puts a slot back as it was before map_for(). */
	private static function inverse_map( string $slot, string $role, string $scope, array $stored ): array {
		if ( 'crumbs' === $slot ) {
			return [ 'crumbs' => [ 'design' => Chrome::get()['crumbs']['design'] ?? false ] ];
		}
		if ( in_array( $slot, Chrome::ROLE_SLOTS, true ) ) {
			$key = 'default' === $scope ? 'default' : $role;
			$cur = $stored[ $slot ] ?? null;
			if ( array_key_exists( $slot, $stored ) && ( ! is_array( $cur ) || self::is_design_object( $cur ) ) ) {
				return [ $slot => $cur ]; // Back to one design for every role.
			}
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

	/** The page types a change reaches: this one, or every one that follows the slot's default. @return string[] */
	private static function affected( string $slot, string $role, string $scope ): array {
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
		$map = self::map_for( $slot, $role, $value, $scope, $stored );
		$inverse = self::inverse_map( $slot, $role, $scope, $stored );
		$reach = self::affected( $slot, $role, $scope );
		$res = Chrome::set_tool( [ 'map' => $map, 'slots' => $slots, 'replace_legacy' => true ] );
		if ( is_wp_error( $res ) ) {
			self::set_flash( 'bad', $res->get_error_message() );
			self::back( $role, $slot );
		}
		[ $kind, $notes ] = self::report( (array) $res, $reach );
		$what = false === $value ? __( 'none', 'launch-builder' ) : ( 'theme' === $value ? __( 'the theme\'s own', 'launch-builder' ) : self::title( (string) $value ) );
		if ( 'warn' === $kind ) {
			// The design did not compile: put the map back so the site keeps what it had.
			Chrome::set_tool( [ 'map' => $inverse, 'slots' => $slots ] );
			self::set_flash( 'bad', sprintf( /* translators: 1: slot name, 2: design name */ __( '%1$s was not changed: %2$s could not be placed.', 'launch-builder' ), $name, $what ) . ' ' . implode( ' ', $notes ) );
			self::back( $role, $slot );
		}
		set_transient( self::undo_key(), [ 'slot' => $slot, 'role' => $role, 'map' => $inverse, 'slots' => $slots, 'snapshot' => (string) ( $res['snapshot'] ?? '' ) ], HOUR_IN_SECONDS );
		$msg = sprintf( /* translators: 1: slot name, 2: design name, 3: page type or "every page type" */ __( '%1$s is now %2$s for %3$s.', 'launch-builder' ), $name, $what, 'default' === $scope || ! in_array( $slot, Chrome::ROLE_SLOTS, true ) ? __( 'every page type that uses it', 'launch-builder' ) : sprintf( /* translators: %s: page type */ __( '%s pages', 'launch-builder' ), self::role_label( $role ) ) );
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
		$out .= '</div></aside><section class="lb-panel lb-lib__main" aria-labelledby="lb-t"><div class="lb-panel__head"><h2 id="lb-t">' . esc_html__( 'Pattern library', 'launch-builder' ) . '</h2></div><p class="lb-lib__desc" id="lb-d">' . esc_html__( 'Every pattern, rendered with your theme, colours and Business Info. Click a group name to render that whole group in one go, then click patterns to jump between them. Your AI assistant picks from these; a person can insert any of them in the editor (Patterns > Launch Builder).', 'launch-builder' ) . '</p><p class="lb-lib__used" id="lb-used" hidden></p>';
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
