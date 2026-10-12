<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Launch Builder home: a dashboard (the launch runway, the optional modules as switches, connection, health,
 * activity and design summaries), the dedicated Modules page, a compact widget for the WordPress Dashboard, and the
 * shared admin stylesheet and script every Launch Builder screen uses (assets/admin/). Modules are saved as you click.
 */
final class Dashboard {

	const NONCE = 'lb_dash';

	/** Module slug => [ category, icon, settings page slug (a screen of this plugin) ]. Other modules get the defaults. */
	const META = [
		'conversions' => [ 'marketing', 'bolt', '' ],
		'permalinks'  => [ 'seo', 'link', 'launch-builder-urls' ],
		'duplicate'   => [ 'content', 'copy', 'launch-builder-duplicate' ],
		'media'       => [ 'media', 'image', '' ],
		'avatars'     => [ 'access', 'user', '' ],
		'login-links' => [ 'access', 'key', '' ],
		'branding'    => [ 'admin', 'badge', 'launch-builder-branding' ],
	];

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_lb_module_toggle', [ __CLASS__, 'ajax_toggle' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'widget_setup' ] );
	}

	/* ------------------------------------------------------------------ assets */

	/** Is this one of our screens? (The page slugs all start with launch-builder or lb-.) */
	public static function is_screen( string $hook = '' ): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return 0 === strpos( $page, 'launch-builder' ) || 0 === strpos( $page, 'lb-' ) || false !== strpos( $hook, 'launch-builder' );
	}

	public static function assets( $hook = '' ): void {
		$hook = (string) $hook;
		if ( ! self::is_screen( $hook ) && ! ( 'index.php' === $hook && current_user_can( 'manage_options' ) ) ) {
			return;
		}
		wp_enqueue_style( 'lb-admin', LB_URL . 'assets/admin/lb-admin.css', [], LB_VERSION );
		wp_enqueue_script( 'lb-admin', LB_URL . 'assets/admin/lb-admin.js', [], LB_VERSION, true );
		wp_add_inline_script(
			'lb-admin',
			'window.lbAdmin=' . wp_json_encode(
				[
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( self::NONCE ),
					'i18n'  => [
						'on'     => __( 'On', 'launch-builder' ),
						'off'    => __( 'Off', 'launch-builder' ),
						'reload' => __( 'Reload', 'launch-builder' ),
						'copied' => __( 'Copied', 'launch-builder' ),
						'failed' => __( 'Could not save that change. Try again.', 'launch-builder' ),
					],
				]
			) . ';',
			'before'
		);
	}

	/* ------------------------------------------------------------------ small pieces */

	/** Inline icons (24 grid, drawn as strokes in currentColor). */
	public static function icon( string $name, string $class = '' ): string {
		static $paths = null;
		if ( null === $paths ) {
			$paths = [
				'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
				'bolt'     => '<path d="M13 3L5 13.5h6L10 21l8-10.5h-6z"/>',
				'link'     => '<path d="M9.5 14.5l5-5M8 12l-2 2a3.5 3.5 0 0 0 5 5l2-2M16 12l2-2a3.5 3.5 0 0 0-5-5l-2 2"/>',
				'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2.5"/><path d="M16 8V6.5A2.5 2.5 0 0 0 13.5 4h-7A2.5 2.5 0 0 0 4 6.5v7A2.5 2.5 0 0 0 6.5 16H8"/>',
				'image'    => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="M4 17l5-4.5 3.5 3L16 12l4.5 4.5"/>',
				'user'     => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.4 3.6-5.2 7-5.2s6.2 1.8 7 5.2"/>',
				'key'      => '<circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4m-3 3l2.5 2.5M13.5 9.5L16 12"/>',
				'badge'    => '<path d="M12 3.5l7 2.8v5.4c0 4.3-3 7.4-7 8.8-4-1.4-7-4.5-7-8.8V6.3z"/>',
				'box'      => '<path d="M12 3.5l7.5 4v9l-7.5 4-7.5-4v-9z"/><path d="M4.5 7.5L12 11.5l7.5-4M12 11.5v9"/>',
				'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
				'search'   => '<circle cx="10.5" cy="10.5" r="6"/><path d="M15 15l5 5"/>',
				'business' => '<rect x="5" y="3.5" width="9" height="17" rx="1.5"/><path d="M14 9.5h4.5a1.5 1.5 0 0 1 1.5 1.5v9.5H14M8.5 8h2M8.5 12h2M8.5 16h2"/>',
				'connect'  => '<path d="M9 4v4M15 4v4M7 8h10v3a5 5 0 0 1-10 0zM12 16v4.5"/>',
				'layout'   => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M9.5 9.5v10"/>',
				'content'  => '<path d="M7 3.5h7l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5z"/><path d="M14 3.5v4h4M8.5 12h7M8.5 15.5h7"/>',
				'shield'   => '<path d="M12 3.5l7 2.8v5.4c0 4.3-3 7.4-7 8.8-4-1.4-7-4.5-7-8.8V6.3z"/><path d="M9 12l2.2 2.2L15.2 10"/>',
				'globe'    => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.4 3.6 5.2 3.6 8.5s-1.1 6.1-3.6 8.5c-2.5-2.4-3.6-5.2-3.6-8.5S9.5 5.9 12 3.5z"/>',
				'activity' => '<path d="M3 12h4l2.5-6 5 12L17 12h4"/>',
				'sliders'  => '<path d="M5 6h8M17 6h2M5 12h2M11 12h8M5 18h10M19 18h0"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
				'out'      => '<path d="M8 16l8-8M9 8h7v7"/>',
			];
		}
		$p = $paths[ $name ] ?? $paths['box'];
		return '<svg class="lb-i' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $p . '</svg>'; // phpcs:ignore -- static markup.
	}

	/** The menu icon: a map pin with an arrow, as a data URI (WordPress paints it in the menu colours). */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" fill-rule="evenodd" d="M10 1.5a6 6 0 0 0-6 6c0 4.2 6 11 6 11s6-6.8 6-11a6 6 0 0 0-6-6zm0 3.2l3.2 3.7h-2.1v3.1H8.9V8.4H6.8z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function brand_mark(): string {
		return '<span class="lb-mark" aria-hidden="true"><svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1.5a6 6 0 0 0-6 6c0 4.2 6 11 6 11s6-6.8 6-11a6 6 0 0 0-6-6zm0 3.2l3.2 3.7h-2.1v3.1H8.9V8.4H6.8z"/></svg></span>';
	}

	private static function categories(): array {
		return [
			'marketing' => __( 'Marketing', 'launch-builder' ),
			'seo'       => __( 'URLs and SEO', 'launch-builder' ),
			'content'   => __( 'Content', 'launch-builder' ),
			'media'     => __( 'Media', 'launch-builder' ),
			'access'    => __( 'People and access', 'launch-builder' ),
			'admin'     => __( 'Admin', 'launch-builder' ),
			'tools'     => __( 'Tools', 'launch-builder' ),
		];
	}

	/** @return array{cat:string,icon:string,url:string} */
	private static function meta( string $slug, bool $enabled ): array {
		$m = self::META[ $slug ] ?? [ 'tools', 'box', '' ];
		$url = '';
		if ( 'login-links' === $slug && class_exists( __NAMESPACE__ . '\\Login_Links' ) ) {
			$url = $enabled ? admin_url( 'users.php?page=' . Login_Links::PAGE ) : '';
		} elseif ( '' !== $m[2] && $enabled ) {
			$url = Admin::url( $m[2] );
		}
		$out = [ 'cat' => $m[0], 'icon' => $m[1], 'url' => $url ];
		return (array) apply_filters( 'launch_builder_dashboard_module_meta', $out, $slug, $enabled );
	}

	/** The optional modules, ready to show. @return array<int,array> */
	private static function optional(): array {
		$rows = array_values( array_filter( Modules::all(), static function ( $m ) { return $m['toggle']; } ) );
		usort( $rows, static function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );
		return $rows;
	}

	private static function header( string $title, string $sub, string $actions = '' ): string {
		return '<header class="lb-top"><div class="lb-brand">' . self::brand_mark() . '<div><h1>' . esc_html( $title ) . '</h1><p class="lb-tag">' . $sub . '</p></div></div>' . ( '' !== $actions ? '<div class="lb-actions">' . $actions . '</div>' : '' ) . '</header><hr class="wp-header-end">'; // phpcs:ignore -- $sub and $actions are built from escaped parts.
	}

	private static function button( string $label, string $url, string $kind = '', string $icon = '' ): string {
		return '<a class="lb-btn' . ( '' !== $kind ? ' lb-btn--' . esc_attr( $kind ) : '' ) . '" href="' . esc_url( $url ) . '">' . ( '' !== $icon ? self::icon( $icon ) : '' ) . esc_html( $label ) . '</a>';
	}

	/* ------------------------------------------------------------------ the launch runway */

	/**
	 * The six stations from "business facts" to "live".
	 *
	 * @return array<int,array{id:string,icon:string,name:string,detail:string,state:string,url:string,cta:string}>
	 */
	public static function stations(): array {
		$res = Company::resolve();
		$essentials = [ 'name', 'phone', 'email', 'address', 'hours', 'cta_label' ];
		$set = count( array_filter( $essentials, static function ( $k ) use ( $res ) { return isset( $res[ $k ]['from'] ) && 'default' !== $res[ $k ]['from']; } ) );
		$tok = get_option( 'launch_builder_mcp_token' );
		$designed = Chrome::applied( 'hero' ) || Chrome::applied( 'footer' ) || (bool) get_theme_mod( 'blocks_header' );
		$pages = (int) ( wp_count_posts( 'page' )->publish ?? 0 );
		$services = (int) ( wp_count_posts( Entries::SERVICE )->publish ?? 0 );
		$areas = (int) ( wp_count_posts( Entries::AREA )->publish ?? 0 );
		$built = ( $services + $areas ) > 0 && $pages >= 3;
		$audit = Audit::results();
		$errors = (int) ( $audit['totals']['error'] ?? 0 );
		$warnings = (int) ( $audit['totals']['warning'] ?? 0 );
		$public = '1' === (string) get_option( 'blog_public', '1' );

		if ( ! $audit ) {
			$check = [ 'todo', __( 'Not run yet', 'launch-builder' ) ];
		} elseif ( $errors ) {
			$check = [ 'warn', sprintf( /* translators: %d: number of errors */ _n( '%d error to fix', '%d errors to fix', $errors, 'launch-builder' ), $errors ) ];
		} elseif ( $warnings ) {
			$check = [ 'warn', sprintf( /* translators: %d: number of warnings */ _n( '%d warning', '%d warnings', $warnings, 'launch-builder' ), $warnings ) ];
		} else {
			$check = [ 'ok', __( 'All clear', 'launch-builder' ) ];
		}

		return [
			[ 'id' => 'business', 'icon' => 'business', 'name' => __( 'Business info', 'launch-builder' ), 'detail' => sprintf( /* translators: %d: how many of the six essentials are set */ __( '%d of 6 essentials set', 'launch-builder' ), $set ), 'state' => 6 === $set ? 'ok' : 'todo', 'url' => Admin::url( 'launch-builder-business' ), 'cta' => __( 'Add business details', 'launch-builder' ) ],
			[ 'id' => 'connect', 'icon' => 'connect', 'name' => __( 'AI assistant', 'launch-builder' ), 'detail' => is_array( $tok ) ? sprintf( /* translators: %s: last four characters of the token */ __( 'Token ending %s', 'launch-builder' ), (string) ( $tok['last4'] ?? '' ) ) : __( 'Not connected yet', 'launch-builder' ), 'state' => is_array( $tok ) ? 'ok' : 'todo', 'url' => Admin::url( 'launch-builder-connect' ), 'cta' => __( 'Connect an assistant', 'launch-builder' ) ],
			[ 'id' => 'design', 'icon' => 'layout', 'name' => __( 'Design', 'launch-builder' ), 'detail' => $designed ? __( 'Shared designs in place', 'launch-builder' ) : __( 'Nothing applied yet', 'launch-builder' ), 'state' => $designed ? 'ok' : 'todo', 'url' => Admin::url( 'launch-builder-patterns' ), 'cta' => __( 'Preview the designs', 'launch-builder' ) ],
			[ 'id' => 'content', 'icon' => 'content', 'name' => __( 'Content', 'launch-builder' ), 'detail' => sprintf( /* translators: 1: pages, 2: services, 3: areas */ __( '%1$d pages, %2$d services, %3$d areas', 'launch-builder' ), $pages, $services, $areas ), 'state' => $built ? 'ok' : 'todo', 'url' => admin_url( 'edit.php?post_type=page' ), 'cta' => __( 'Open the pages', 'launch-builder' ) ],
			[ 'id' => 'check', 'icon' => 'shield', 'name' => __( 'Site check', 'launch-builder' ), 'detail' => $check[1], 'state' => $check[0], 'url' => Admin::url( 'launch-builder-health' ), 'cta' => $audit ? __( 'Review the check', 'launch-builder' ) : __( 'Run the check', 'launch-builder' ) ],
			[ 'id' => 'live', 'icon' => 'globe', 'name' => __( 'Live', 'launch-builder' ), 'detail' => $public ? __( 'Visible to search engines', 'launch-builder' ) : __( 'Hidden from search engines', 'launch-builder' ), 'state' => $public ? 'ok' : 'todo', 'url' => admin_url( 'options-reading.php' ), 'cta' => __( 'Open reading settings', 'launch-builder' ) ],
		];
	}

	private static function runway( bool $compact = false ): string {
		$st = self::stations();
		$next = null;
		foreach ( $st as $s ) {
			if ( 'ok' !== $s['state'] ) {
				$next = $s;
				break;
			}
		}
		$out = '';
		if ( ! $compact ) {
			$out .= '<section class="lb-panel lb-runway" aria-labelledby="lb-runway-h"><div class="lb-panel__head"><h2 id="lb-runway-h">' . esc_html__( 'Launch runway', 'launch-builder' ) . '</h2><span class="lb-note" style="margin:0">' . esc_html__( 'Each station lights up when it is done.', 'launch-builder' ) . '</span></div><ol class="lb-runway__stations">';
			foreach ( $st as $s ) {
				$cls = 'ok' === $s['state'] ? 'ok' : ( 'warn' === $s['state'] ? 'warn' : ( $next && $next['id'] === $s['id'] ? 'next' : 'todo' ) );
				$node = 'ok' === $s['state'] ? self::icon( 'check' ) : self::icon( $s['icon'] );
				$out .= '<li class="lb-st lb-st--' . esc_attr( $cls ) . '"><span class="lb-st__node">' . $node . '</span><span class="lb-st__name">' . esc_html( $s['name'] ) . '</span><span class="lb-st__detail">' . esc_html( $s['detail'] ) . '</span><a class="lb-st__hit" href="' . esc_url( $s['url'] ) . '"><span class="screen-reader-text">' . esc_html( $s['cta'] ) . '</span></a></li>';
			}
			$out .= '</ol>';
		}
		if ( $next ) {
			$out .= '<div class="lb-next"><div><b>' . esc_html( sprintf( /* translators: %s: station name */ __( 'Next: %s', 'launch-builder' ), $next['name'] ) ) . '</b><span>' . esc_html( $next['detail'] ) . '</span></div>' . self::button( $next['cta'], $next['url'], 'primary', 'arrow' ) . '</div>';
		} else {
			$out .= '<div class="lb-next lb-next--done"><div><b>' . esc_html__( 'Everything is in place', 'launch-builder' ) . '</b><span>' . esc_html__( 'The business facts, the assistant, the designs, the content and the check are all done, and the site is visible.', 'launch-builder' ) . '</span></div></div>';
		}
		return $compact ? $out : $out . '</section>';
	}

	/* ------------------------------------------------------------------ modules */

	private static function tile( array $m, bool $full = false ): string {
		$meta = self::meta( $m['slug'], (bool) $m['enabled'] );
		$on = (bool) $m['enabled'];
		$desc = trim( $m['description'] );
		$out = '<li class="lb-mod' . ( $full ? ' lb-mod--full' : '' ) . '" data-slug="' . esc_attr( $m['slug'] ) . '" data-label="' . esc_attr( $m['label'] ) . '" data-cat="' . esc_attr( $meta['cat'] ) . '" data-on="' . ( $on ? '1' : '0' ) . '" data-text="' . esc_attr( $desc . ' ' . $m['note'] ) . '">';
		$out .= '<div class="lb-mod__head"><span class="lb-mod__icon">' . self::icon( $meta['icon'] ) . '</span><h3 class="lb-mod__title" id="lb-mod-' . esc_attr( $m['slug'] ) . '">' . esc_html( $m['label'] ) . '</h3>';
		/* translators: %s: module name */
		$out .= '<button type="button" class="lb-switch" role="switch" aria-checked="' . ( $on ? 'true' : 'false' ) . '" aria-labelledby="lb-mod-' . esc_attr( $m['slug'] ) . '"><span class="screen-reader-text">' . esc_html( sprintf( __( 'Turn %s on or off', 'launch-builder' ), $m['label'] ) ) . '</span></button></div>';
		$out .= '<p class="lb-mod__desc">' . esc_html( $desc ) . '</p>';
		$out .= '<div class="lb-mod__foot"><span class="lb-chip lb-chip--' . ( $on ? 'on' : 'plain' ) . '" data-lb-state>' . ( $on ? esc_html__( 'On', 'launch-builder' ) : esc_html__( 'Off', 'launch-builder' ) ) . '</span>';
		if ( ! $m['default'] ) {
			$out .= '<span class="lb-mod__meta">' . esc_html__( 'Off by default', 'launch-builder' ) . '</span>';
		}
		if ( '' !== $meta['url'] ) {
			$out .= '<a class="lb-link" href="' . esc_url( $meta['url'] ) . '">' . esc_html__( 'Open settings', 'launch-builder' ) . self::icon( 'arrow' ) . '</a>';
		}
		$out .= '</div>';
		if ( $full && '' !== trim( $m['note'] ) ) {
			$out .= '<p class="lb-note" style="margin:0">' . esc_html( $m['note'] ) . '</p>';
		}
		return $out . '</li>';
	}

	private static function board( bool $full ): string {
		$mods = self::optional();
		$cats = self::categories();
		$used = [];
		foreach ( $mods as $m ) {
			$used[ self::meta( $m['slug'], (bool) $m['enabled'] )['cat'] ] = true;
		}
		$out = '<div data-lb-board>';
		if ( $full ) {
			$out .= '<div class="lb-tools"><label class="lb-search"><span class="screen-reader-text">' . esc_html__( 'Search modules', 'launch-builder' ) . '</span>' . self::icon( 'search' ) . '<input type="search" data-lb-search placeholder="' . esc_attr__( 'Search modules', 'launch-builder' ) . '"></label>';
			$out .= '<div class="lb-filters" role="group" aria-label="' . esc_attr__( 'Filter by category', 'launch-builder' ) . '"><button type="button" data-cat="all" aria-pressed="true">' . esc_html__( 'All', 'launch-builder' ) . '</button><button type="button" data-cat="on" aria-pressed="false">' . esc_html__( 'On', 'launch-builder' ) . '</button>';
			foreach ( $cats as $slug => $label ) {
				if ( isset( $used[ $slug ] ) ) {
					$out .= '<button type="button" data-cat="' . esc_attr( $slug ) . '" aria-pressed="false">' . esc_html( $label ) . '</button>';
				}
			}
			$out .= '</div><span class="lb-count"><span data-lb-shown>' . (int) count( $mods ) . '</span> ' . esc_html__( 'shown', 'launch-builder' ) . '</span></div>';
		}
		if ( ! $mods ) {
			return $out . '<p class="lb-empty"><b>' . esc_html__( 'No optional modules yet', 'launch-builder' ) . '</b>' . esc_html__( 'When this version has optional modules, they appear here with a switch.', 'launch-builder' ) . '</p></div>';
		}
		$out .= '<ul class="lb-mods">';
		foreach ( $mods as $m ) {
			$out .= self::tile( $m, $full );
		}
		$out .= '</ul><p class="lb-empty" data-lb-none hidden><b>' . esc_html__( 'Nothing matches', 'launch-builder' ) . '</b>' . esc_html__( 'Try another word or choose All.', 'launch-builder' ) . '</p></div>';
		return $out;
	}

	public static function ajax_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to change modules.', 'launch-builder' ) ], 403 );
		}
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$on = isset( $_POST['on'] ) && '1' === (string) wp_unslash( $_POST['on'] );
		$r = $on ? Modules::set( [ $slug ], [] ) : Modules::set( [], [ $slug ] );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( [ 'message' => $r->get_error_message() ], 400 );
		}
		$label = $slug;
		$enabled = $on;
		foreach ( Modules::all() as $m ) {
			if ( $m['slug'] === $slug ) {
				$label = $m['label'];
				$enabled = (bool) $m['enabled'];
			}
		}
		wp_send_json_success(
			[
				'enabled' => $enabled,
				'reload'  => true,
				'message' => $enabled
					? sprintf( /* translators: %s: module name */ __( '%s is on. Reload to see its screens.', 'launch-builder' ), $label )
					: sprintf( /* translators: %s: module name */ __( '%s is off. Reload to update the menu.', 'launch-builder' ), $label ),
			]
		);
	}

	/* ------------------------------------------------------------------ side cards */

	private static function connect_card(): string {
		$tok = get_option( 'launch_builder_mcp_token' );
		$log = (array) get_option( 'launch_builder_log', [] );
		$last = $log ? (int) ( $log[0]['t'] ?? 0 ) : 0;
		$out = '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'AI assistant', 'launch-builder' ) . '</h2>';
		$out .= is_array( $tok ) ? '<span class="lb-chip lb-chip--ok">' . esc_html__( 'Connected', 'launch-builder' ) . '</span>' : '<span class="lb-chip lb-chip--warn">' . esc_html__( 'Not connected', 'launch-builder' ) . '</span>';
		$out .= '</div>';
		if ( is_array( $tok ) ) {
			$exp = ! empty( $tok['expires'] ) ? sprintf( /* translators: %s: date */ __( 'expires %s', 'launch-builder' ), wp_date( get_option( 'date_format' ), (int) $tok['expires'] ) ) : __( 'does not expire', 'launch-builder' );
			$out .= '<div class="lb-kv">' . self::icon( 'connect' ) . '<div><b>' . esc_html( sprintf( /* translators: %s: last four characters of the token */ __( 'Token ending %s', 'launch-builder' ), (string) ( $tok['last4'] ?? '' ) ) ) . '</b><span>' . esc_html( $exp ) . ( $last ? ' · ' . esc_html( sprintf( /* translators: %s: how long ago */ __( 'last change %s ago', 'launch-builder' ), human_time_diff( $last ) ) ) : '' ) . '</span></div></div>';
			$out .= self::button( __( 'Manage the connection', 'launch-builder' ), Admin::url( 'launch-builder-connect' ) );
		} else {
			$out .= '<p class="lb-note" style="margin:0 0 12px">' . esc_html__( 'Connect an AI assistant in three steps. It can then build and update the site from the premade designs.', 'launch-builder' ) . '</p>' . self::button( __( 'Connect an assistant', 'launch-builder' ), Admin::url( 'launch-builder-connect' ), 'primary', 'arrow' );
		}
		return $out . '</section>';
	}

	private static function health_card(): string {
		$a = Audit::results();
		$out = '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Site check', 'launch-builder' ) . '</h2></div>';
		if ( ! $a ) {
			$out .= '<p class="lb-note" style="margin:0 0 12px">' . esc_html__( 'The check reads every page and setting the way a visitor and a search engine would, and lists what to fix.', 'launch-builder' ) . '</p>' . self::button( __( 'Run the check', 'launch-builder' ), Admin::url( 'launch-builder-health' ), 'primary', 'shield' );
			return $out . '</section>';
		}
		$e = (int) ( $a['totals']['error'] ?? 0 );
		$w = (int) ( $a['totals']['warning'] ?? 0 );
		$i = (int) ( $a['totals']['info'] ?? 0 );
		$out .= '<div class="lb-health"><div class="' . ( $e ? 'bad' : '' ) . '"><b>' . $e . '</b><span>' . esc_html__( 'Errors', 'launch-builder' ) . '</span></div><div class="' . ( $w ? 'warn' : '' ) . '"><b>' . $w . '</b><span>' . esc_html__( 'Warnings', 'launch-builder' ) . '</span></div><div><b>' . $i . '</b><span>' . esc_html__( 'Notes', 'launch-builder' ) . '</span></div></div>';
		$when = ! empty( $a['started'] ) ? strtotime( (string) $a['started'] ) : 0;
		$out .= '<p class="lb-note" style="margin:0 0 12px">' . ( $when ? esc_html( sprintf( /* translators: %s: how long ago */ __( 'Last run %s ago.', 'launch-builder' ), human_time_diff( $when ) ) ) : '' ) . ( empty( $a['complete'] ) ? ' ' . esc_html__( 'It did not finish.', 'launch-builder' ) : '' ) . '</p>';
		return $out . self::button( __( 'Open the check', 'launch-builder' ), Admin::url( 'launch-builder-health' ) ) . '</section>';
	}

	private static function links_card(): string {
		$rows = [
			[ 'business', __( 'Business info', 'launch-builder' ), Admin::url( 'launch-builder-business' ) ],
			[ 'layout', __( 'Design hub', 'launch-builder' ), Admin::url( 'launch-builder-patterns' ) ],
			[ 'sliders', __( 'All modules', 'launch-builder' ), Admin::url( 'launch-builder-modules' ) ],
			[ 'activity', __( 'Activity and safety', 'launch-builder' ), Admin::url( 'launch-builder-activity' ) ],
		];
		$out = '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Jump to', 'launch-builder' ) . '</h2></div><ul class="lb-links">';
		foreach ( $rows as [ $icon, $label, $url ] ) {
			$out .= '<li><a href="' . esc_url( $url ) . '">' . self::icon( $icon ) . esc_html( $label ) . self::icon( 'arrow', 'arrow' ) . '</a></li>';
		}
		return $out . '</ul></section>';
	}

	private static function activity_card(): string {
		$log = array_slice( (array) get_option( 'launch_builder_log', [] ), 0, 6 );
		$out = '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Recent activity', 'launch-builder' ) . '</h2><a class="lb-link" href="' . esc_url( Admin::url( 'launch-builder-activity' ) ) . '">' . esc_html__( 'See all', 'launch-builder' ) . '</a></div>';
		if ( ! $log ) {
			return $out . '<p class="lb-empty" style="padding:14px 0;text-align:left"><b>' . esc_html__( 'Nothing yet', 'launch-builder' ) . '</b>' . esc_html__( 'Changes your assistant makes show up here.', 'launch-builder' ) . '</p></section>';
		}
		$out .= '<ul class="lb-feed">';
		foreach ( $log as $e ) {
			$u = get_userdata( (int) ( $e['u'] ?? 0 ) );
			$out .= '<li><div><b>' . esc_html( ucfirst( str_replace( [ '-', '_' ], ' ', (string) ( $e['tool'] ?? '' ) ) ) ) . '</b><span>' . esc_html( sprintf( /* translators: 1: how long ago, 2: user name */ __( '%1$s ago by %2$s', 'launch-builder' ), human_time_diff( (int) ( $e['t'] ?? 0 ) ), $u ? $u->user_login : '-' ) ) . '</span></div>' . ( ! empty( $e['ok'] ) ? '' : '<span class="lb-chip lb-chip--bad">' . esc_html__( 'Failed', 'launch-builder' ) . '</span>' ) . '</li>';
		}
		return $out . '</ul></section>';
	}

	private static function design_card(): string {
		$mode = Chrome::mode();
		$preset = Style::get()['preset'];
		$slots = 0;
		foreach ( [ 'header', 'hero', 'crumbs', 'template', 'before_footer', 'footer', 'sticky_bar' ] as $s ) {
			if ( Chrome::applied( $s ) ) {
				$slots++;
			}
		}
		$out = '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Design', 'launch-builder' ) . '</h2><a class="lb-link" href="' . esc_url( Admin::url( 'launch-builder-patterns' ) ) . '">' . esc_html__( 'Preview', 'launch-builder' ) . '</a></div><div class="lb-health" style="grid-template-columns:repeat(2,1fr)">';
		$out .= '<div><b>' . (int) $slots . '</b><span>' . esc_html__( 'Shared designs placed', 'launch-builder' ) . '</span></div>';
		$out .= '<div><b style="font-size:16px;line-height:1.7">' . esc_html( '' !== $preset ? ucfirst( $preset ) : __( 'Default', 'launch-builder' ) ) . '</b><span>' . esc_html__( 'Style preset', 'launch-builder' ) . '</span></div></div>';
		$out .= '<p class="lb-note" style="margin:0">' . esc_html( 'elements' === $mode ? __( 'Placed with Kadence Elements, so one edit reaches every page.', 'launch-builder' ) : __( 'Placed by Launch Builder, so one edit reaches every page.', 'launch-builder' ) ) . '</p></section>';
		return $out;
	}

	/* ------------------------------------------------------------------ pages */

	public static function page_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mods = self::optional();
		$on = count( array_filter( $mods, static function ( $m ) { return $m['enabled']; } ) );
		echo '<div class="wrap lb-wrap">'; // phpcs:ignore
		echo self::header( 'Launch Builder', esc_html( sprintf( /* translators: 1: site name, 2: plugin version */ __( '%1$s · version %2$s', 'launch-builder' ), get_bloginfo( 'name' ), LB_VERSION ) ), self::button( __( 'Business info', 'launch-builder' ), Admin::url( 'launch-builder-business' ), '', 'business' ) . self::button( __( 'Preview designs', 'launch-builder' ), Admin::url( 'launch-builder-patterns' ), 'primary', 'layout' ) ); // phpcs:ignore
		echo self::runway(); // phpcs:ignore
		echo '<div class="lb-grid"><div><section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Modules', 'launch-builder' ) . '</h2><span class="lb-note" style="margin:0"><span data-lb-on-count>' . (int) $on . '</span> ' . esc_html__( 'of', 'launch-builder' ) . ' <span data-lb-total-count>' . (int) count( $mods ) . '</span> ' . esc_html__( 'on', 'launch-builder' ) . ' · <a href="' . esc_url( Admin::url( 'launch-builder-modules' ) ) . '">' . esc_html__( 'Manage all', 'launch-builder' ) . '</a></span></div>'; // phpcs:ignore
		echo self::board( false ) . '</section>'; // phpcs:ignore
		echo '<div class="lb-grid lb-grid--even">' . self::activity_card() . self::design_card() . '</div></div>'; // phpcs:ignore
		echo '<div class="lb-stack">' . self::connect_card() . self::health_card() . self::links_card() . '</div></div></div>'; // phpcs:ignore
	}

	public static function page_modules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mods = self::optional();
		$fixed = array_values( array_filter( Modules::all(), static function ( $m ) { return ! $m['toggle']; } ) );
		$on = count( array_filter( $mods, static function ( $m ) { return $m['enabled']; } ) );
		echo '<div class="wrap lb-wrap">'; // phpcs:ignore
		echo self::header( __( 'Modules', 'launch-builder' ), esc_html__( 'Switch the optional parts of Launch Builder on or off. A module that is off loads nothing: no tools, no screens, no front-end code.', 'launch-builder' ), '<span class="lb-chip lb-chip--plain"><span data-lb-on-count>' . (int) $on . '</span>&nbsp;' . esc_html__( 'of', 'launch-builder' ) . '&nbsp;<span data-lb-total-count>' . (int) count( $mods ) . '</span>&nbsp;' . esc_html__( 'on', 'launch-builder' ) . '</span>' ); // phpcs:ignore
		echo '<section class="lb-panel">' . self::board( true ) . '</section>'; // phpcs:ignore
		echo '<section class="lb-panel"><details class="lb-core"><summary>' . esc_html__( 'Always on', 'launch-builder' ) . ' (' . (int) count( $fixed ) . ')</summary><table class="lb-table"><tbody>';
		foreach ( $fixed as $m ) {
			echo '<tr><td><b>' . esc_html( $m['label'] ) . '</b></td><td>' . esc_html( $m['description'] ) . '</td><td><span class="lb-badge core">' . esc_html( $m['core'] ? __( 'Core', 'launch-builder' ) : __( 'Always on', 'launch-builder' ) ) . '</span></td></tr>';
		}
		echo '</tbody></table></details><p class="lb-note">' . esc_html__( 'Your AI assistant can read and change these switches with get-modules and set-modules.', 'launch-builder' ) . '</p></section></div>';
	}

	/* ------------------------------------------------------------------ WordPress Dashboard widget */

	public static function widget_setup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'lb_dashboard_widget', __( 'Launch Builder', 'launch-builder' ), [ __CLASS__, 'widget' ] );
	}

	public static function widget(): void {
		$mods = self::optional();
		$on = count( array_filter( $mods, static function ( $m ) { return $m['enabled']; } ) );
		$a = Audit::results();
		echo '<div class="lb-wrap lb-widget" style="max-width:none;margin:0">'; // phpcs:ignore
		echo self::runway( true ); // phpcs:ignore
		echo '<div class="lb-health"><div><b>' . (int) $on . '</b><span>' . esc_html__( 'Modules on', 'launch-builder' ) . '</span></div><div class="' . ( ! empty( $a['totals']['error'] ) ? 'bad' : '' ) . '"><b>' . (int) ( $a['totals']['error'] ?? 0 ) . '</b><span>' . esc_html__( 'Errors', 'launch-builder' ) . '</span></div><div class="' . ( ! empty( $a['totals']['warning'] ) ? 'warn' : '' ) . '"><b>' . (int) ( $a['totals']['warning'] ?? 0 ) . '</b><span>' . esc_html__( 'Warnings', 'launch-builder' ) . '</span></div></div>'; // phpcs:ignore
		echo '<p style="margin:0"><a class="lb-link" href="' . esc_url( Admin::url() ) . '">' . esc_html__( 'Open Launch Builder', 'launch-builder' ) . self::icon( 'arrow' ) . '</a> &nbsp; <a class="lb-link" href="' . esc_url( Admin::url( 'launch-builder-modules' ) ) . '">' . esc_html__( 'Modules', 'launch-builder' ) . '</a></p></div>'; // phpcs:ignore
	}
}
