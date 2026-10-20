<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Launch Builder admin area: a top-level menu with a guided setup (checklist, connect in three steps, connection
 * check), Business Info, the pattern preview route (the Design hub's library tab shows it), Site health (the audit, run
 * in AJAX batches) and activity/safety. Written as plain PHP with a little inline CSS/JS (no build step, nothing
 * loaded on other admin screens).
 */
final class Admin {

	const SLUG = 'launch-builder';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_lb_check', [ __CLASS__, 'run_check' ] );
		add_action( 'admin_post_lb_remove_all_data', [ __CLASS__, 'save_remove_all_data' ] );
		add_action( 'wp_ajax_lb_audit', [ __CLASS__, 'ajax_audit' ] );
		add_action( 'template_redirect', [ __CLASS__, 'preview_route' ], 1 );
	}

	public static function url( string $page = '' ): string {
		return admin_url( 'admin.php?page=' . ( '' === $page ? self::SLUG : $page ) );
	}

	public static function menu(): void {
		add_menu_page( 'Launch Builder', 'Launch Builder', 'manage_options', self::SLUG, [ Dashboard::class, 'page_dashboard' ], Dashboard::menu_icon(), 58 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'launch-builder' ), __( 'Dashboard', 'launch-builder' ), 'manage_options', self::SLUG, [ Dashboard::class, 'page_dashboard' ] );
		add_submenu_page( self::SLUG, __( 'Modules', 'launch-builder' ), __( 'Modules', 'launch-builder' ), 'manage_options', 'launch-builder-modules', [ Dashboard::class, 'page_modules' ] );
		add_submenu_page( self::SLUG, __( 'Business Info', 'launch-builder' ), __( 'Business Info', 'launch-builder' ), 'manage_options', 'launch-builder-business', [ Company::class, 'page' ] );
		add_submenu_page( self::SLUG, __( 'Design hub', 'launch-builder' ), __( 'Design hub', 'launch-builder' ), 'manage_options', Design_Hub::PAGE, [ Design_Hub::class, 'page' ] );
		add_submenu_page( self::SLUG, __( 'Site health', 'launch-builder' ), __( 'Site health', 'launch-builder' ), 'manage_options', 'launch-builder-health', [ __CLASS__, 'page_health' ] );
		add_submenu_page( self::SLUG, __( 'Connect', 'launch-builder' ), __( 'Connect', 'launch-builder' ), 'manage_options', 'launch-builder-connect', [ __CLASS__, 'page_start' ] );
		add_submenu_page( self::SLUG, __( 'Activity and safety', 'launch-builder' ), __( 'Activity and safety', 'launch-builder' ), 'manage_options', 'launch-builder-activity', [ __CLASS__, 'page_activity' ] );
	}

	/* ------------------------------------------------------------------ shared bits */

	/** The admin look lives in assets/admin/lb-admin.css (enqueued by Dashboard on every Launch Builder screen). */
	private static function css(): string {
		return '';
	}

	/** Copy buttons and tabs live in assets/admin/lb-admin.js. */
	private static function js(): string {
		return '';
	}

	private static function dot( string $state ): string {
		$map = [ 'ok' => [ 'lb-ok', '&#10003;' ], 'warn' => [ 'lb-warn', '!' ], 'bad' => [ 'lb-bad', '&#10005;' ], 'opt' => [ 'lb-opt', '&middot;' ] ];
		return '<span class="lb-dot ' . $map[ $state ][0] . '" aria-hidden="true">' . $map[ $state ][1] . '</span>';
	}

	private static function local_site(): bool {
		$h = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return in_array( $h, [ 'localhost', '127.0.0.1', '::1' ], true ) || (bool) preg_match( '/\.(local|test|localhost)$/', $h );
	}

	/* ------------------------------------------------------------------ get started */

	/** @return array<int,array{0:string,1:string,2:string,3:string}> state, title, detail, fix link html */
	private static function checklist(): array {
		$rows = [];
		$kb = Plugin::kadence_blocks_active();
		$rows[] = [ $kb ? 'ok' : 'bad', 'Kadence Blocks is active', $kb ? 'The patterns are built from Kadence blocks.' : 'The patterns need it.', $kb ? '' : '<a href="' . esc_url( admin_url( 'plugin-install.php?s=kadence+blocks&tab=search&type=term' ) ) . '">Install Kadence Blocks</a>' ];
		$th = Plugin::kadence_theme_active();
		$rows[] = [ $th ? 'ok' : 'warn', 'Kadence theme is active', $th ? 'Header, global areas, buttons and page layout controls work.' : 'Without it the block header, global areas and the design tools have no effect.', $th ? '' : '<a href="' . esc_url( admin_url( 'themes.php' ) ) . '">Themes</a>' ];
		$pretty = '' !== (string) get_option( 'permalink_structure' );
		$rows[] = [ $pretty ? 'ok' : 'warn', 'Pretty permalinks', $pretty ? 'The short address ' . esc_html( home_url( '/mcp' ) ) . ' works.' : 'Turn on a non-plain permalink structure so /mcp works (the REST address works either way).', $pretty ? '' : '<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">Permalinks</a>' ];
		$ssl = is_ssl() || self::local_site();
		$rows[] = [ $ssl ? 'ok' : 'warn', 'Secure connection', $ssl ? ( is_ssl() ? 'HTTPS: the token travels encrypted.' : 'Local site: fine for testing.' ) : 'This site is on http. Use HTTPS before connecting an AI assistant, or the token can be read on the way.', '' ];
		$res = Company::resolve();
		$essentials = Company::essentials();
		$set = count( array_filter( $essentials, static function ( $k ) use ( $res ) { return 'default' !== $res[ $k ]['from']; } ) );
		$rows[] = [ count( $essentials ) === $set ? 'ok' : 'warn', 'Business Info', $set . ' of ' . count( $essentials ) . ' essentials set (business name, phone, email, address, hours, button text). Until set, patterns show demo values.', '<a href="' . esc_url( self::url( 'launch-builder-business' ) ) . '">Open Business Info</a>' ];
		$tok = get_option( 'launch_builder_mcp_token' );
		$rows[] = [ is_array( $tok ) ? 'ok' : 'warn', 'Connection token', is_array( $tok ) ? 'Active, ending ' . esc_html( (string) $tok['last4'] ) . ', expires ' . esc_html( ! empty( $tok['expires'] ) ? wp_date( 'Y-m-d', (int) $tok['expires'] ) : 'never' ) . '.' : 'None yet. Step 1 below creates one.', '' ];
		$bh = (bool) get_theme_mod( 'blocks_header' );
		$rows[] = [ $bh ? 'ok' : 'opt', 'Block header', $bh ? 'A Kadence block header is active (set-header).' : 'Not set yet. Your AI assistant sets it with set-header.', '' ];
		$bp = Plugin::requirement_met( 'kadence-blocks-pro' );
		$tp = Plugin::requirement_met( 'kadence-pro' );
		$rows[] = [ $bp && $tp ? 'ok' : 'opt', 'Kadence Pro (optional)', ( $bp ? 'Blocks Pro active' : 'Blocks Pro not active' ) . ', ' . ( $tp ? 'Theme Kit Pro active' : 'Theme Kit Pro not active' ) . '. Pro patterns (sliders, modal, split content) and per-page Elements need them.', '' ];
		$audit = Audit::results();
		if ( $audit ) {
			$bad = (int) ( $audit['totals']['error'] ?? 0 );
			$warn = (int) ( $audit['totals']['warning'] ?? 0 );
			$rows[] = [ $bad ? 'bad' : ( $warn ? 'warn' : 'ok' ), 'Site health', sprintf( '%d errors, %d warnings in the last check%s.', $bad, $warn, empty( $audit['complete'] ) ? ' (it did not finish)' : '' ), '<a href="' . esc_url( self::url( 'launch-builder-health' ) ) . '">Open Site health</a>' ];
		}
		return $rows;
	}

	public static function page_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = home_url( '/mcp' );
		$check = get_transient( 'lb_check_' . get_current_user_id() );
		echo self::css() . '<div class="wrap lb-wrap"><h1>' . esc_html__( 'Connect an AI assistant', 'launch-builder' ) . '</h1><p class="lb-sub">' . esc_html__( 'Connect your assistant in three steps, then ask it to build your site.', 'launch-builder' ) . '</p>'; // phpcs:ignore

		echo '<div class="lb-card"><h2>' . esc_html__( 'Setup checklist', 'launch-builder' ) . '</h2><ul class="lb-list">';
		foreach ( self::checklist() as [ $state, $t, $d, $fix ] ) {
			echo '<li>' . self::dot( $state ) . '<div><b>' . esc_html( $t ) . '</b><span class="d">' . $d . ( $fix ? ' ' . $fix : '' ) . '</span></div></li>'; // phpcs:ignore -- $d/$fix are built from escaped parts above.
		}
		echo '</ul></div>';

		echo '<div class="lb-card"><h2>' . esc_html__( 'Connect your AI assistant in 3 steps', 'launch-builder' ) . '</h2><ol class="lb-steps">';
		echo '<li><h3>' . esc_html__( 'Create a token', 'launch-builder' ) . '</h3><p>' . wp_kses( __( 'The token lets your assistant act as <strong>you</strong> on this site. It lasts 180 days and shows once, so copy it now. For a client site, create it from a separate admin account.', 'launch-builder' ), [ 'strong' => [] ] ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_mcp_token">';
		wp_nonce_field( 'lb_mcp_token' );
		$tok = get_option( 'launch_builder_mcp_token' );
		submit_button( is_array( $tok ) ? __( 'Replace token (the old one stops working)', 'launch-builder' ) : __( 'Generate token', 'launch-builder' ), 'primary', 'submit', false );
		echo '</form></li>';

		echo '<li><h3>' . esc_html__( 'Add the server to your MCP client', 'launch-builder' ) . '</h3><p>' . esc_html__( 'The next screen shows these with your real token filled in. Pick the kind of MCP client you use:', 'launch-builder' ) . '</p><div class="lb-tabgroup"><div class="lb-tabs" role="tablist">';
		$configs = Mcp::client_configs( $url, 'YOUR_TOKEN' );
		foreach ( $configs as $i => $c ) {
			echo '<button type="button" role="tab" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '" class="' . ( 0 === $i ? 'on' : '' ) . '" data-lb-tab="lb-p' . (int) $i . '">' . esc_html( $c['label'] ) . '</button>';
		}
		echo '</div>';
		foreach ( $configs as $i => $c ) {
			echo '<div class="lb-pane' . ( 0 === $i ? ' on' : '' ) . '" id="lb-p' . (int) $i . '" role="tabpanel"><p>' . esc_html( $c['help'] ) . '</p><div class="lb-code" id="lb-c' . (int) $i . '">' . esc_html( $c['code'] ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-c' . (int) $i . '">' . esc_html__( 'Copy', 'launch-builder' ) . '</button></div></div>';
		}
		echo '<p class="description">' . esc_html__( 'Using a dashboard instead? It can call the same tools over REST with a WordPress application password. See below.', 'launch-builder' ) . '</p></div></li>';

		echo '<li><h3>' . esc_html__( 'Check the connection', 'launch-builder' ) . '</h3><p>' . wp_kses( __( 'Tests that this site answers on <code>/mcp</code> and that the tools are ready. It does not need your token.', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_check">';
		wp_nonce_field( 'lb_check' );
		submit_button( __( 'Run connection check', 'launch-builder' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( is_array( $check ) ) {
			echo '<table class="lb-table" id="lb-check" style="margin-top:10px">';
			foreach ( $check as [ $state, $t, $d ] ) {
				echo '<tr><td style="width:30px">' . self::dot( $state ) . '</td><td><b>' . esc_html( $t ) . '</b><br><span class="description">' . esc_html( $d ) . '</span></td></tr>'; // phpcs:ignore
			}
			echo '</table>';
		}
		echo '</li></ol></div>';

		$prompt = 'Use the launch-builder MCP server. Call get-playbook first, then build a website for [business name], a [type of business] in [city]. Phone [number], email [email]. Brand colours [hex codes]. Ask me for anything you need.';
		echo '<div class="lb-card"><h2>' . esc_html__( 'Then ask for what you want', 'launch-builder' ) . '</h2><p>' . esc_html__( 'Paste something like this into your AI assistant and fill in the brackets:', 'launch-builder' ) . '</p><div class="lb-code" id="lb-prompt">' . esc_html( $prompt ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-prompt">' . esc_html__( 'Copy', 'launch-builder' ) . '</button></div>';
		echo '<p>' . sprintf( /* translators: 1: Design hub link, 2: Business Info link */ esc_html__( 'Preview what your AI assistant can use in the %1$s. Business facts live in %2$s: change them there and every page, header and footer follows.', 'launch-builder' ), '<a href="' . esc_url( self::url( 'launch-builder-patterns' ) ) . '">' . esc_html__( 'Design hub', 'launch-builder' ) . '</a>', '<a href="' . esc_url( self::url( 'launch-builder-business' ) ) . '">' . esc_html__( 'Business Info', 'launch-builder' ) . '</a>' ) . '</p></div>'; // phpcs:ignore

		echo '<div class="lb-card"><h2>' . esc_html__( 'Using a dashboard? Application password', 'launch-builder' ) . '</h2><p>' . esc_html__( 'A dashboard can use the same tools with a WordPress application password (Users, Profile). No token needed.', 'launch-builder' ) . '</p><div class="lb-code" id="lb-rest">GET  ' . esc_html( rest_url( 'launch-builder/v1/tools' ) ) . "\nPOST " . esc_html( rest_url( 'launch-builder/v1/tools/get-playbook' ) ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-rest">' . esc_html__( 'Copy', 'launch-builder' ) . '</button></div>';
		echo '<p class="description">' . ( wp_is_application_passwords_available() ? esc_html__( 'Application passwords are available on this site.', 'launch-builder' ) : esc_html__( 'Application passwords are disabled on this site (they need HTTPS or a local environment, and a security plugin may block them).', 'launch-builder' ) ) . '</p></div>';
		echo '</div>' . self::js(); // phpcs:ignore
	}

	/** Loopback tests that do not need the token. */
	public static function run_check(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_check' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$out = [];
		$body = wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ] );
		foreach ( [ 'Short address' => home_url( '/mcp' ), 'REST address' => rest_url( 'launch-builder/v1/mcp' ) ] as $label => $u ) {
			$r = wp_remote_post( $u, [ 'timeout' => 12, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => $body, 'sslverify' => ! self::local_site() ] );
			if ( is_wp_error( $r ) ) {
				$out[] = [ 'warn', $label . ' could not be tested', 'This server could not call itself (' . $r->get_error_message() . '). The address may still work from outside; try connecting from your AI assistant.' ];
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$j = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( 401 === $code && is_array( $j ) ) {
				$out[] = [ 'ok', $label . ' reachable', 'It answered and asked for a token, as it should.' ];
			} elseif ( 404 === $code ) {
				$out[] = [ 'bad', $label . ' not found (404)', 'Re-save Settings > Permalinks, or check that a security plugin or the host is not blocking this address.' ];
			} elseif ( in_array( $code, [ 403, 406, 429 ], true ) ) {
				$out[] = [ 'bad', $label . ' blocked (' . $code . ')', 'A firewall or security plugin is rejecting the request. Allow this address, or use the other one.' ];
			} else {
				$out[] = [ 'warn', $label . ' answered ' . $code, 'Unexpected response. Check the Activity page and your host logs.' ];
			}
		}
		$tok = get_option( 'launch_builder_mcp_token' );
		if ( is_array( $tok ) ) {
			$left = (int) ( $tok['expires'] ?? 0 ) - time();
			$out[] = [ $left > 0 ? 'ok' : 'bad', $left > 0 ? 'Token valid' : 'Token expired', $left > 0 ? 'Acts as ' . ( ( $u = get_userdata( (int) $tok['user'] ) ) ? $u->user_login : '(missing user)' ) . ', ' . (int) ceil( $left / DAY_IN_SECONDS ) . ' days left.' : 'Create a new token in step 1.' ];
		} else {
			$out[] = [ 'warn', 'No token yet', 'Do step 1 first.' ];
		}
		$out[] = [ Plugin::kadence_blocks_active() ? 'ok' : 'bad', 'Kadence Blocks', Plugin::kadence_blocks_active() ? 'Active.' : 'Not active: patterns cannot be inserted.' ];
		$out[] = [ 'ok', 'Tools ready', count( Abilities::defs() ) . ' tools, ' . count( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() ) . ' block patterns registered (' . count( Plugin::unavailable() ) . ' hidden because Kadence Pro is not active).' ];
		set_transient( 'lb_check_' . get_current_user_id(), $out, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() . '#lb-check' );
		exit;
	}

	/** The "remove all data" switch: only from this screen (manage_options + nonce), never through set-option. */
	public static function save_remove_all_data(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_remove_all_data' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		empty( $_POST['remove_all'] ) ? delete_option( 'launch_builder_remove_all_data' ) : update_option( 'launch_builder_remove_all_data', 1, false );
		wp_safe_redirect( self::url( 'launch-builder-activity' ) );
		exit;
	}

	/* ------------------------------------------------------------------ pattern preview route */

	/**
	 * Preview route (the Pattern library tab of the Design hub, class Design_Hub, loads it in its frame). ?lb_preview=<slug> renders one pattern; ?lb_preview=@<category> renders every pattern of a category in a
	 * single page (one slow request, then browsing is just scrolling). Output is cached until a pattern, Business Info, the
	 * palette, theme settings or the active plugins change.
	 */
	public static function preview_route(): void {
		if ( empty( $_GET['lb_preview'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( isset( $_GET['_lbn'] ) ? sanitize_text_field( wp_unslash( $_GET['_lbn'] ) ) : '', 'lb_preview' ) ) { // phpcs:ignore
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
		}
		$raw = (string) wp_unslash( $_GET['lb_preview'] ); // phpcs:ignore
		$is_cat = '@' === ( $raw[0] ?? '' );
		$slug = sanitize_key( ltrim( $raw, '@' ) );
		// Render one pattern as a page of its role (Roles: header, or ?lb_role=) so
		// role-dependent output (crumbs, heroes, tokens) matches a real page; the pattern screenshot tool relies on it.
		$role = $is_cat ? '' : Patterns::preview_role( $slug, isset( $_GET['lb_role'] ) ? sanitize_key( wp_unslash( $_GET['lb_role'] ) ) : '' ); // phpcs:ignore
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'X-LB-Preview-Role: ' . $role );

		$files = $is_cat ? ( glob( LB_DIR . 'patterns/*.php' ) ?: [] ) : [ LB_DIR . 'patterns/' . $slug . '.php' ];
		$stamp = 0;
		foreach ( $files as $fl ) {
			$stamp = max( $stamp, is_file( $fl ) ? (int) filemtime( $fl ) : 0 );
		}
		$key = 'lb_pv_' . md5( implode( '|', [ $raw, 'pv4', $role, LB_VERSION, $stamp, count( $files ), wp_json_encode( get_option( Company::OPT ) ), wp_json_encode( get_option( Company::OPT_SRC ) ), (string) get_option( 'kadence_global_palette' ), md5( wp_json_encode( get_theme_mods() ) ), md5( wp_json_encode( get_option( 'active_plugins' ) ) ), (string) get_option( 'kadence_blocks_config_blocks' ) ] ) );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit && empty( $_GET['lb_fresh'] ) ) { // phpcs:ignore
			header( 'X-LB-Preview: cached' );
			echo $hit; // phpcs:ignore -- admin-only, built below from trusted pattern files.
			exit;
		}

		ob_start();
		$simulated = '' !== $role && Context::simulate( $role, Roles::sample( $role ) );
		add_filter( 'the_title', static function ( $t, $id = 0 ) { return (int) $id === (int) get_queried_object_id() ? 'Water Heater Repair in Austin' : $t; }, 99, 2 ); // Patterns that print the page title (hero-inner) show a sample.
		Plugin::register_patterns();
		$reg = \WP_Block_Patterns_Registry::get_instance();
		$items = [];
		if ( $is_cat ) {
			foreach ( $reg->get_all_registered() as $p ) {
				if ( 0 === strpos( $p['name'], 'launch-builder/' ) && in_array( 'launch-builder-' . $slug, (array) ( $p['categories'] ?? [] ), true ) && ! in_array( 'launch-builder-headers', (array) $p['categories'], true ) ) {
					$items[] = $p;
				}
			}
		} elseif ( $reg->is_registered( 'launch-builder/' . $slug ) ) {
			$items[] = $reg->get_registered( 'launch-builder/' . $slug );
		}
		show_admin_bar( false );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
		wp_head();
		echo '<style>html{margin-top:0!important}body{margin:0;background:#fff}.lb-pv-label{display:flex;gap:10px;align-items:baseline;padding:8px 16px;background:#1d2327;color:#fff;font:600 13px/1.4 system-ui,sans-serif}.lb-pv-label code{background:#2c3338;color:#c3c4c7;padding:1px 6px;border-radius:3px;font-weight:400}.lb-pv-item{scroll-margin-top:0}</style></head><body class="' . esc_attr( implode( ' ', get_body_class( 'lb-preview' ) ) ) . '">';
		if ( ! $items ) {
			echo '<p style="padding:24px;font-family:sans-serif">Nothing to preview here. Header patterns are applied with <code>set-header</code> and show on your site once set.</p>';
		}
		$drawn = 0;
		foreach ( $items as $p ) {
			if ( in_array( 'launch-builder-headers', (array) $p['categories'], true ) ) {
				echo '<p style="padding:24px;font-family:sans-serif">Header patterns are applied with <code>set-header</code>; they show on your site once set.</p>';
				continue;
			}
			$sl = substr( $p['name'], 15 );
			$drawn++;
			echo '<section class="lb-pv-item" id="p-' . esc_attr( $sl ) . '">';
			if ( $is_cat ) {
				echo '<div class="lb-pv-label">' . esc_html( $p['title'] ) . ' <code>' . esc_html( $sl ) . '</code></div>';
			}
			echo '<div class="entry-content single-content">' . do_blocks( $p['content'] ) . '</div></section>'; // phpcs:ignore -- admin-only preview of trusted pattern files.
		}
		if ( $drawn && ! $is_cat ) {
			// Tells the Design hub that a design was drawn. A frame on another host cannot be looked into, so this message is
			// how the hub knows the page is not an error page; it carries only the pattern name and the hub checks who sent it.
			echo '<script>try{parent.postMessage({lbPv:1,p:' . wp_json_encode( $slug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '},"*")}catch(e){}</script>';
		}
		wp_footer();
		echo '</body></html>';
		if ( $simulated ) {
			Context::clear();
		}
		$out = (string) ob_get_clean();
		if ( $items ) {
			set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
		}
		echo $out; // phpcs:ignore
		exit;
	}

	/** The old Pattern preview screen is now the Pattern library tab of the Design hub (the same page slug). */
	public static function page_preview(): void {
		Design_Hub::page();
	}

	/* ------------------------------------------------------------------ site health */

	/** Findings made safe and useful for the screen: plain strings, an edit link for posts. */
	private static function decorate( array $findings ): array {
		// Load the posts the findings point at in one go: each edit check below would otherwise fetch its own post.
		$ids = [];
		foreach ( $findings as $f ) {
			$f = (array) $f;
			if ( isset( $f['object_type'], $f['id'] ) && 'post' === $f['object_type'] && is_scalar( $f['id'] ) && ctype_digit( (string) $f['id'] ) ) {
				$ids[ (int) $f['id'] ] = (int) $f['id'];
			}
		}
		if ( $ids ) {
			_prime_post_caches( array_values( $ids ), false, false );
		}
		$out = [];
		foreach ( $findings as $f ) {
			$f = (array) $f;
			$row = [];
			foreach ( [ 'severity', 'code', 'object_type', 'post_type', 'title', 'url', 'message', 'fix' ] as $k ) {
				$row[ $k ] = isset( $f[ $k ] ) && is_scalar( $f[ $k ] ) ? (string) $f[ $k ] : '';
			}
			$row['id'] = isset( $f['id'] ) && is_scalar( $f['id'] ) ? (string) $f['id'] : '';
			$row['edit'] = '';
			if ( 'post' === $row['object_type'] && ctype_digit( $row['id'] ) && current_user_can( 'edit_post', (int) $row['id'] ) ) {
				$row['edit'] = (string) get_edit_post_link( (int) $row['id'], 'raw' );
			} elseif ( 'navigation' === $row['object_type'] && ctype_digit( $row['id'] ) ) {
				$row['edit'] = $row['url'];
				$row['url'] = '';
			}
			if ( '' !== $row['url'] && ! preg_match( '#^https?://#i', $row['url'] ) ) {
				$row['url'] = '';
			}
			$out[] = $row;
		}
		return $out;
	}

	/** One batch of the audit for the Site health screen (POST after_id, scope[]). */
	public static function ajax_audit(): void {
		check_ajax_referer( 'lb_audit' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'launch-builder' ) ], 403 );
		}
		$after = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;
		$scope = isset( $_POST['scope'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['scope'] ) ) ) ) : [];
		$r = Audit::run_cached( [ 'after_id' => $after, 'limit' => 25, 'max_findings' => 200, 'scope' => $scope ] );
		wp_send_json_success( [ 'next_after_id' => $r['next_after_id'], 'totals' => $r['totals'], 'findings' => self::decorate( $r['findings'] ), 'posts' => (int) $r['window']['posts'], 'dropped' => (int) $r['dropped'], 'cached' => ! empty( $r['cached'] ) ] );
	}

	public static function page_health(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = Audit::results();
		$last = $res ? [
			'totals'   => $res['totals'] ?? [],
			'findings' => self::decorate( (array) ( $res['findings'] ?? [] ) ),
			'complete' => ! empty( $res['complete'] ),
			'updated'  => ! empty( $res['updated'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $res['updated'] ) ) : '',
			'not_kept' => (int) ( $res['not_kept'] ?? 0 ),
			'scope'    => implode( ',', (array) ( $res['scope'] ?? [] ) ),
		] : null;
		$data = [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'lb_audit' ),
			'total' => Audit::count_posts(),
			'last'  => $last,
			't'     => [
				'error' => __( 'Error', 'launch-builder' ), 'warning' => __( 'Warning', 'launch-builder' ), 'info' => __( 'Info', 'launch-builder' ),
				'running' => __( 'Checking… %1$d of about %2$d items', 'launch-builder' ), 'done' => __( 'Finished. %d findings.', 'launch-builder' ), 'failed' => __( 'The check stopped: %s. Run it again and it picks up where it left off.', 'launch-builder' ),
				'none' => __( 'No problems found in this run.', 'launch-builder' ), 'never' => __( 'No check has run yet. Run one to see what needs attention before launch.', 'launch-builder' ),
				'filtered' => __( 'Nothing matches these filters.', 'launch-builder' ), 'edit' => __( 'Edit', 'launch-builder' ), 'view' => __( 'View', 'launch-builder' ),
				'last' => __( 'Last check: %s', 'launch-builder' ), 'partial' => __( 'Last check: %s (did not finish)', 'launch-builder' ), 'all_codes' => __( 'All checks', 'launch-builder' ),
				'not_kept' => __( '%d more findings were counted but not listed. Choose a smaller check to see them.', 'launch-builder' ),
			],
		];
		$scopes = [
			''                          => __( 'Everything', 'launch-builder' ),
			'content'                   => __( 'Page content', 'launch-builder' ),
			'company,menus,chrome,seo'  => __( 'Settings (Business Info, menus, shared designs, SEO)', 'launch-builder' ),
			'render'                    => __( 'Rendered pages (one sample per role, slower)', 'launch-builder' ),
		];
		echo self::css(); // phpcs:ignore
		echo '<style>
.lb-hc-bar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center}.lb-hc-bar label{font-weight:600}
.lb-prog{height:8px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin:14px 0 6px}.lb-prog span{display:block;height:100%;width:0;background:#2271b1;transition:width .3s}
.lb-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 18px}.lb-tile{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}.lb-tile b{display:block;font-size:26px;line-height:1.1}.lb-tile span{color:#50575e}
.lb-tile.error b{color:#b32d2e}.lb-tile.warning b{color:#996800}.lb-tile.info b{color:#2271b1}
.lb-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px}.lb-filters .button[aria-pressed=true]{background:#2271b1;border-color:#2271b1;color:#fff}
.lb-find td{font-size:13px}.lb-find .m{font-weight:600;color:#1d2327}.lb-find .fx{color:#50575e;margin-top:3px}.lb-find .w a{font-weight:600}.lb-find .w small{display:block;color:#646970}
.lb-empty{padding:26px;text-align:center;color:#50575e}
@media(max-width:782px){.lb-tiles{grid-template-columns:1fr 1fr 1fr}.lb-tile b{font-size:20px}.lb-find thead{display:none}.lb-find tr{display:block;border-top:1px solid #dcdcde;padding:8px 0}.lb-find td{display:block;border:0;padding:3px 0}}
</style>';
		echo '<div class="wrap lb-wrap"><h1>' . esc_html__( 'Site health', 'launch-builder' ) . '</h1><p class="lb-sub">' . esc_html__( 'Finds what would embarrass a launch: sample text left behind, raw tokens, dead links and missing settings. Big sites are checked in small batches.', 'launch-builder' ) . '</p>';
		echo '<div class="lb-card"><div class="lb-hc-bar"><label for="lb-scope">' . esc_html__( 'What to check', 'launch-builder' ) . '</label><select id="lb-scope">';
		foreach ( $scopes as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select><button type="button" class="button button-primary" id="lb-run">' . esc_html__( 'Run check', 'launch-builder' ) . '</button><span id="lb-status" role="status" aria-live="polite"></span></div><div class="lb-prog" hidden id="lb-prog"><span></span></div></div>';
		echo '<div class="lb-tiles"><div class="lb-tile error"><b id="lb-n-error">0</b><span>' . esc_html__( 'Errors', 'launch-builder' ) . '</span></div><div class="lb-tile warning"><b id="lb-n-warning">0</b><span>' . esc_html__( 'Warnings', 'launch-builder' ) . '</span></div><div class="lb-tile info"><b id="lb-n-info">0</b><span>' . esc_html__( 'Info', 'launch-builder' ) . '</span></div></div>';
		echo '<div class="lb-card"><h2>' . esc_html__( 'Findings', 'launch-builder' ) . '</h2><div class="lb-filters" role="group" aria-label="' . esc_attr__( 'Filter by severity', 'launch-builder' ) . '">';
		foreach ( [ '' => __( 'All', 'launch-builder' ), 'error' => __( 'Errors', 'launch-builder' ), 'warning' => __( 'Warnings', 'launch-builder' ), 'info' => __( 'Info', 'launch-builder' ) ] as $v => $l ) {
			echo '<button type="button" class="button" data-sev="' . esc_attr( $v ) . '" aria-pressed="' . ( '' === $v ? 'true' : 'false' ) . '">' . esc_html( $l ) . '</button>';
		}
		echo '<select id="lb-code" aria-label="' . esc_attr__( 'Filter by check', 'launch-builder' ) . '"></select></div>';
		echo '<p id="lb-last" class="description"></p><table class="lb-table lb-find"><thead><tr><th style="width:90px">' . esc_html__( 'Severity', 'launch-builder' ) . '</th><th>' . esc_html__( 'Problem and fix', 'launch-builder' ) . '</th><th style="width:30%">' . esc_html__( 'Where', 'launch-builder' ) . '</th></tr></thead><tbody id="lb-rows"></tbody></table><div class="lb-empty" id="lb-empty" hidden></div></div></div>';
		echo '<script>window.lbHealth=' . wp_json_encode( $data ) . ';</script>'; // phpcs:ignore
		echo '<script>(function(){var D=window.lbHealth,T=D.t,$=function(i){return document.getElementById(i)},S={rows:[],totals:{error:0,warning:0,info:0},sev:"",code:"",busy:false},order={error:0,warning:1,info:2};
function fmt(s){var a=[].slice.call(arguments,1),i=0;return s.replace(/%(\d\$)?[sd]/g,function(m,n){return String(n?a[parseInt(n,10)-1]:a[i++])})}
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e}
function link(href,text){var a=el("a",null,text);a.href=href;return a}
function counts(){["error","warning","info"].forEach(function(k){$("lb-n-"+k).textContent=S.totals[k]||0})}
function codes(){var sel=$("lb-code"),cur=S.code,seen={};sel.textContent="";var o=el("option",null,T.all_codes);o.value="";sel.appendChild(o);S.rows.forEach(function(r){seen[r.code]=(seen[r.code]||0)+1});Object.keys(seen).sort().forEach(function(c){var o=el("option",null,c+" ("+seen[c]+")");o.value=c;if(c===cur)o.selected=true;sel.appendChild(o)})}
function draw(){var tb=$("lb-rows"),empty=$("lb-empty");tb.textContent="";var rows=S.rows.filter(function(r){return(!S.sev||r.severity===S.sev)&&(!S.code||r.code===S.code)}).sort(function(a,b){return(order[a.severity]||0)-(order[b.severity]||0)});
rows.slice(0,500).forEach(function(r){var tr=el("tr");var td=el("td");td.appendChild(el("span","lb-badge "+r.severity,T[r.severity]||r.severity));tr.appendChild(td);td=el("td");td.appendChild(el("div","m",r.message));if(r.fix)td.appendChild(el("div","fx",r.fix));tr.appendChild(td);td=el("td","w");
if(r.title){td.appendChild(r.edit?link(r.edit,r.title):el("span",null,r.title))}if(r.url){td.appendChild(document.createTextNode(" "));td.appendChild(link(r.url,T.view))}td.appendChild(el("small",null,r.code+(r.post_type?" · "+r.post_type:"")+(r.id?" #"+r.id:"")));tr.appendChild(td);tb.appendChild(tr)});
empty.hidden=!!rows.length;if(!rows.length){empty.textContent=S.rows.length?T.filtered:(S.ran?T.none:T.never)}}
function show(last){S.rows=last.findings||[];S.totals=last.totals||{};S.ran=true;counts();codes();draw();$("lb-last").textContent=fmt(last.complete?T.last:T.partial,last.updated)+(last.not_kept?" "+fmt(T.not_kept,last.not_kept):"")}
document.querySelectorAll("[data-sev]").forEach(function(b){b.addEventListener("click",function(){S.sev=b.getAttribute("data-sev");document.querySelectorAll("[data-sev]").forEach(function(x){x.setAttribute("aria-pressed",x===b?"true":"false")});draw()})});
$("lb-code").addEventListener("change",function(e){S.code=e.target.value;draw()});
$("lb-run").addEventListener("click",function(){if(S.busy)return;S.busy=true;S.rows=[];S.totals={error:0,warning:0,info:0};S.ran=false;S.code="";var btn=this,done=0,scope=$("lb-scope").value,prog=$("lb-prog"),bar=prog.querySelector("span");btn.disabled=true;prog.hidden=false;bar.style.width="2%";counts();codes();draw();$("lb-last").textContent="";
function step(after){var fd=new FormData();fd.append("action","lb_audit");fd.append("_ajax_nonce",D.nonce);fd.append("after_id",after);scope.split(",").filter(Boolean).forEach(function(s){fd.append("scope[]",s)});
fetch(D.ajax,{method:"POST",credentials:"same-origin",body:fd}).then(function(r){if(!r.ok)throw new Error("HTTP "+r.status);return r.json()}).then(function(j){if(!j||!j.success)throw new Error(j&&j.data&&j.data.message?j.data.message:"error");var d=j.data;done+=d.posts;["error","warning","info"].forEach(function(k){S.totals[k]=(S.totals[k]||0)+(d.totals[k]||0)});S.rows=S.rows.concat(d.findings);counts();codes();draw();
bar.style.width=Math.min(98,Math.max(4,Math.round(done/Math.max(1,D.total)*100)))+"%";$("lb-status").textContent=fmt(T.running,done,D.total);if(d.next_after_id!==null&&d.next_after_id!==undefined){step(d.next_after_id)}else{bar.style.width="100%";S.ran=true;S.busy=false;btn.disabled=false;$("lb-status").textContent=fmt(T.done,S.rows.length);draw();setTimeout(function(){prog.hidden=true},800)}}).catch(function(e){S.busy=false;btn.disabled=false;prog.hidden=true;$("lb-status").textContent=fmt(T.failed,e.message)})}
step(0)});
if(D.last){show(D.last)}else{draw()}})();</script>';
	}

	/* ------------------------------------------------------------------ modules */



	/* ------------------------------------------------------------------ activity + safety */

	public static function page_activity(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo self::css() . '<div class="wrap lb-wrap"><h1>' . esc_html__( 'Activity and safety', 'launch-builder' ) . '</h1><p class="lb-sub">' . esc_html__( 'What your AI assistant has changed, and the switches that keep a connected agent in bounds.', 'launch-builder' ) . '</p>'; // phpcs:ignore
		echo '<div class="lb-card"><h2>' . esc_html__( 'Power tools', 'launch-builder' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_mcp_power">';
		wp_nonce_field( 'lb_mcp_power' );
		echo '<p><label><input type="checkbox" name="power" value="1" ' . checked( Abilities::power(), true, false ) . '> ' . esc_html__( 'Let the REST proxy change more than content: users, plugins, themes, settings and permanent deletes.', 'launch-builder' ) . '</label></p><p class="description">' . esc_html__( 'Off by default, so the proxy only writes content, menus, templates and Rank Math page SEO. Turn it on for a build that needs more, like installing Rank Math, then turn it off again.', 'launch-builder' ) . '</p>';
		submit_button( __( 'Save', 'launch-builder' ), 'secondary', 'submit', false );
		echo '</form></div>';
		echo '<div class="lb-card"><h2>' . esc_html__( 'When the plugin is deleted', 'launch-builder' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_remove_all_data">';
		wp_nonce_field( 'lb_remove_all_data' );
		echo '<p><label><input type="checkbox" name="remove_all" value="1" ' . checked( (bool) get_option( 'launch_builder_remove_all_data' ), true, false ) . '> ' . esc_html__( 'Also delete Business Info when Launch Builder is deleted.', 'launch-builder' ) . '</label></p><p class="description">' . esc_html__( 'Off by default. Pages print Business Info through tokens, so it is kept unless you tick this. Settings, logs and tokens are always removed. Your pages, patterns and media stay.', 'launch-builder' ) . '</p>';
		submit_button( __( 'Save', 'launch-builder' ), 'secondary', 'submit', false );
		echo '</form></div>';
		$snaps = array_slice( Abilities::snapshots(), 0, 15 );
		echo '<div class="lb-card"><h2>' . esc_html__( 'Undo', 'launch-builder' ) . '</h2><p>' . wp_kses( __( 'Every settings or design change is saved first (the last 30 are kept). To undo one, ask your assistant to run <code>restore-snapshot</code> with its id. A restore can be undone too.', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';
		if ( $snaps ) {
			echo '<table class="lb-table"><thead><tr><th>' . esc_html__( 'When', 'launch-builder' ) . '</th><th>' . esc_html__( 'What', 'launch-builder' ) . '</th><th>' . esc_html__( 'Snapshot id', 'launch-builder' ) . '</th></tr></thead><tbody>';
			foreach ( $snaps as $s ) {
				$parts = array_filter( [ $s['mods'] ? count( $s['mods'] ) . ' theme mods' : '', $s['options'] ? implode( ', ', array_slice( $s['options'], 0, 3 ) ) . ( count( $s['options'] ) > 3 ? '…' : '' ) : '', $s['posts'] ? count( $s['posts'] ) . ' posts' : '' ] );
				echo '<tr><td>' . esc_html( $s['time'] ? wp_date( 'Y-m-d H:i', (int) strtotime( $s['time'] ) ) : '' ) . '</td><td><b>' . esc_html( $s['label'] ) . '</b><br><span class="description">' . esc_html( implode( ' · ', $parts ) ) . '</span></td><td><code>' . esc_html( $s['id'] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		$log = array_slice( (array) get_option( 'launch_builder_log', [] ), 0, 40 );
		echo '<div class="lb-card"><h2>' . esc_html__( 'Recent activity', 'launch-builder' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No write calls yet.', 'launch-builder' ) . '</p>';
		} else {
			echo '<table class="lb-table"><thead><tr><th>' . esc_html__( 'When', 'launch-builder' ) . '</th><th>' . esc_html__( 'User', 'launch-builder' ) . '</th><th>' . esc_html__( 'Tool', 'launch-builder' ) . '</th><th>' . esc_html__( 'Result', 'launch-builder' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $e ) {
				$u = get_userdata( (int) $e['u'] );
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $e['t'] ) ) . '</td><td>' . esc_html( $u ? $u->user_login : '-' ) . '</td><td><code>' . esc_html( $e['tool'] ) . '</code></td><td>' . ( $e['ok'] ? esc_html__( 'ok', 'launch-builder' ) : esc_html__( 'failed', 'launch-builder' ) . ' ' . esc_html( (string) $e['note'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}
}
