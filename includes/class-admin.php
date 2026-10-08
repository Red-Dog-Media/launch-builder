<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Launch Builder admin area: a top-level menu with a guided setup (checklist, connect in three steps, connection
 * check), Business Info, a live pattern preview, and activity/safety. Written as plain PHP with a little inline CSS/JS
 * (no build step, nothing loaded on other admin screens).
 */
final class Admin {

	const SLUG = 'launch-builder';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_lb_check', [ __CLASS__, 'run_check' ] );
		add_action( 'admin_post_lb_remove_all_data', [ __CLASS__, 'save_remove_all_data' ] );
		add_action( 'template_redirect', [ __CLASS__, 'preview_route' ], 1 );
	}

	public static function url( string $page = '' ): string {
		return admin_url( 'admin.php?page=' . ( '' === $page ? self::SLUG : $page ) );
	}

	public static function menu(): void {
		add_menu_page( 'Launch Builder', 'Launch Builder', 'manage_options', self::SLUG, [ __CLASS__, 'page_start' ], 'dashicons-layout', 58 );
		add_submenu_page( self::SLUG, 'Get started', 'Get started', 'manage_options', self::SLUG, [ __CLASS__, 'page_start' ] );
		add_submenu_page( self::SLUG, 'Business Info', 'Business Info', 'manage_options', 'launch-builder-business', [ Company::class, 'page' ] );
		add_submenu_page( self::SLUG, 'Pattern preview', 'Pattern preview', 'manage_options', 'launch-builder-patterns', [ __CLASS__, 'page_preview' ] );
		add_submenu_page( self::SLUG, 'Activity and safety', 'Activity and safety', 'manage_options', 'launch-builder-activity', [ __CLASS__, 'page_activity' ] );
	}

	/* ------------------------------------------------------------------ shared bits */

	private static function css(): string {
		return '<style>
.lb-wrap{max-width:980px}.lb-wrap h1{margin-bottom:4px}.lb-sub{color:#50575e;margin:0 0 18px;font-size:14px}
.lb-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 22px;margin:0 0 18px}
.lb-card h2{margin:0 0 12px;font-size:16px}.lb-card p{margin:.4em 0}
.lb-list{margin:0;padding:0;list-style:none}.lb-list li{display:flex;gap:12px;align-items:flex-start;padding:9px 0;border-top:1px solid #f0f0f1}.lb-list li:first-child{border-top:0}
.lb-dot{flex:0 0 22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;margin-top:1px}
.lb-ok{background:#00a32a}.lb-warn{background:#dba617}.lb-bad{background:#d63638}.lb-opt{background:#8c8f94}
.lb-list b{display:block}.lb-list span.d{color:#50575e;font-size:13px}
.lb-steps{counter-reset:s;margin:0;padding:0;list-style:none}.lb-steps>li{position:relative;padding:4px 0 18px 46px}
.lb-steps>li:before{counter-increment:s;content:counter(s);position:absolute;left:0;top:2px;width:30px;height:30px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center}
.lb-steps h3{margin:0 0 4px;font-size:15px}.lb-steps p{margin:.2em 0 .6em;color:#3c434a}
.lb-code{position:relative;background:#1d2327;color:#e2e8f0;border-radius:8px;padding:12px 78px 12px 14px;font:12.5px/1.55 Consolas,Menlo,monospace;white-space:pre-wrap;word-break:break-all;margin:6px 0}
.lb-copy{position:absolute;right:8px;top:8px}
.lb-tabs{display:flex;gap:6px;margin:8px 0 0}.lb-tabs button{border:1px solid #c3c4c7;background:#f6f7f7;border-radius:6px 6px 0 0;padding:6px 12px;cursor:pointer}.lb-tabs button.on{background:#1d2327;color:#fff;border-color:#1d2327}
.lb-pane{display:none}.lb-pane.on{display:block}
.lb-table{border-collapse:collapse;width:100%}.lb-table td{padding:7px 10px;border-top:1px solid #f0f0f1;vertical-align:top}
</style>';
	}

	private static function js(): string {
		return '<script>
document.addEventListener("click",function(e){var b=e.target.closest("[data-lb-copy]");if(b){var t=document.getElementById(b.getAttribute("data-lb-copy"));if(t){navigator.clipboard.writeText(t.innerText.trim()).then(function(){var o=b.textContent;b.textContent="Copied";setTimeout(function(){b.textContent=o},1400)})}}
var tab=e.target.closest("[data-lb-tab]");if(tab){var g=tab.closest(".lb-tabgroup");g.querySelectorAll(".lb-tabs button").forEach(function(x){x.classList.remove("on")});tab.classList.add("on");g.querySelectorAll(".lb-pane").forEach(function(p){p.classList.toggle("on",p.id===tab.getAttribute("data-lb-tab"))})}});
</script>';
	}

	private static function dot( string $state ): string {
		$map = [ 'ok' => [ 'lb-ok', '&#10003;' ], 'warn' => [ 'lb-warn', '!' ], 'bad' => [ 'lb-bad', '&#10005;' ], 'opt' => [ 'lb-opt', '&middot;' ] ];
		return '<span class="lb-dot ' . $map[ $state ][0] . '">' . $map[ $state ][1] . '</span>';
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
		$rows[] = [ $ssl ? 'ok' : 'warn', 'Secure connection', $ssl ? ( is_ssl() ? 'HTTPS: the token travels encrypted.' : 'Local site: fine for testing.' ) : 'This site is on http. Use HTTPS before connecting an agent, or the token can be read on the way.', '' ];
		$res = Company::resolve();
		$essentials = [ 'name', 'phone', 'email', 'address', 'hours', 'cta_label' ];
		$set = count( array_filter( $essentials, static function ( $k ) use ( $res ) { return 'default' !== $res[ $k ]['from']; } ) );
		$rows[] = [ 6 === $set ? 'ok' : 'warn', 'Business Info', $set . ' of ' . count( $essentials ) . ' essentials set (name, phone, email, address, hours, button text). Until set, patterns show demo values.', '<a href="' . esc_url( self::url( 'launch-builder-business' ) ) . '">Open Business Info</a>' ];
		$tok = get_option( 'launch_builder_mcp_token' );
		$rows[] = [ is_array( $tok ) ? 'ok' : 'warn', 'Connection token', is_array( $tok ) ? 'Active, ending ' . esc_html( (string) $tok['last4'] ) . ', expires ' . esc_html( ! empty( $tok['expires'] ) ? wp_date( 'Y-m-d', (int) $tok['expires'] ) : 'never' ) . '.' : 'None yet. Step 1 below creates one.', '' ];
		$bh = (bool) get_theme_mod( 'blocks_header' );
		$rows[] = [ $bh ? 'ok' : 'opt', 'Block header', $bh ? 'A Kadence block header is active (set-header).' : 'Not set yet. Your AI assistant sets it with set-header.', '' ];
		$bp = Plugin::requirement_met( 'kadence-blocks-pro' );
		$tp = Plugin::requirement_met( 'kadence-pro' );
		$rows[] = [ $bp && $tp ? 'ok' : 'opt', 'Kadence Pro (optional)', ( $bp ? 'Blocks Pro active' : 'Blocks Pro not active' ) . ', ' . ( $tp ? 'Theme Kit Pro active' : 'Theme Kit Pro not active' ) . '. Pro patterns (sliders, modal, split content) and per-page Elements need them.', '' ];
		return $rows;
	}

	public static function page_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = home_url( '/mcp' );
		$check = get_transient( 'lb_check_' . get_current_user_id() );
		echo self::css() . '<div class="wrap lb-wrap"><h1>Launch Builder</h1><p class="lb-sub">Build a client site with your AI assistant from premade Kadence patterns. Three steps to connect, then ask for what you want.</p>';

		echo '<div class="lb-card"><h2>Setup checklist</h2><ul class="lb-list">';
		foreach ( self::checklist() as [ $state, $t, $d, $fix ] ) {
			echo '<li>' . self::dot( $state ) . '<div><b>' . esc_html( $t ) . '</b><span class="d">' . $d . ( $fix ? ' ' . $fix : '' ) . '</span></div></li>'; // phpcs:ignore -- $d/$fix are built from escaped parts above.
		}
		echo '</ul></div>';

		$cc = wp_json_encode( [ 'mcpServers' => [ 'launch-builder' => [ 'type' => 'http', 'url' => $url, 'headers' => [ 'Authorization' => 'Bearer YOUR_TOKEN' ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$cd = wp_json_encode( [ 'mcpServers' => [ 'launch-builder' => [ 'command' => 'npx', 'args' => [ '-y', 'mcp-remote', $url, '--header', 'Authorization: Bearer YOUR_TOKEN' ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		echo '<div class="lb-card"><h2>Connect your AI assistant in 3 steps</h2><ol class="lb-steps">';
		echo '<li><h3>Create a token</h3><p>The token lets your AI assistant act as <strong>you</strong> on this site (it expires in 180 days). For a client site, make a dedicated administrator account first and create the token while signed in as that account. It is shown once, so copy it straight away.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_mcp_token">';
		wp_nonce_field( 'lb_mcp_token' );
		$tok = get_option( 'launch_builder_mcp_token' );
		submit_button( is_array( $tok ) ? 'Replace token (the old one stops working)' : 'Generate token', 'primary', 'submit', false );
		echo '</form></li>';

		echo '<li><h3>Paste one command</h3><p>The next screen shows these with your real token filled in. Pick the kind of MCP client you use:</p><div class="lb-tabgroup"><div class="lb-tabs"><button type="button" class="on" data-lb-tab="lb-p1">Remote server</button><button type="button" data-lb-tab="lb-p2">Local bridge</button><button type="button" data-lb-tab="lb-p3">Other</button></div>';
		echo '<div class="lb-pane on" id="lb-p1"><p>Most MCP clients accept a remote HTTP server in their configuration:</p><div class="lb-code" id="lb-c1">' . esc_html( $cc ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-c1">Copy</button></div></div>';
		echo '<div class="lb-pane" id="lb-p2"><p>Clients that only run local servers can use the mcp-remote bridge (needs Node.js):</p><div class="lb-code" id="lb-c2">' . esc_html( (string) $cd ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-c2">Copy</button></div></div>';
		echo '<div class="lb-pane" id="lb-p3"><p>Any MCP client that supports remote servers:</p><div class="lb-code" id="lb-c3">URL: ' . esc_html( $url ) . "\nHeader: Authorization: Bearer YOUR_TOKEN" . '<button type="button" class="button lb-copy" data-lb-copy="lb-c3">Copy</button></div><p>Using a dashboard instead? It can call the same tools over REST with a WordPress application password. See below.</p></div></div></li>';

		echo '<li><h3>Check the connection</h3><p>Tests that this site answers on <code>/mcp</code> and that the tools are ready. It does not need your token.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_check">';
		wp_nonce_field( 'lb_check' );
		submit_button( 'Run connection check', 'secondary', 'submit', false );
		echo '</form>';
		if ( is_array( $check ) ) {
			echo '<table class="lb-table" style="margin-top:10px">';
			foreach ( $check as [ $state, $t, $d ] ) {
				echo '<tr><td style="width:30px">' . self::dot( $state ) . '</td><td><b>' . esc_html( $t ) . '</b><br><span class="description">' . esc_html( $d ) . '</span></td></tr>';
			}
			echo '</table>';
		}
		echo '</li></ol></div>';

		$prompt = "Use the launch-builder MCP. Call get-playbook first, then build a website for [business name], a [type of business] in [city]. Phone [number], email [email]. Brand colours [hex codes]. Ask me for anything you need.";
		echo '<div class="lb-card"><h2>Then ask for what you want</h2><p>Paste something like this into your AI assistant and fill in the brackets:</p><div class="lb-code" id="lb-prompt">' . esc_html( $prompt ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-prompt">Copy</button></div>';
		echo '<p>Preview what your assistant can use in <a href="' . esc_url( self::url( 'launch-builder-patterns' ) ) . '">Pattern preview</a>. Business facts live in <a href="' . esc_url( self::url( 'launch-builder-business' ) ) . '">Business Info</a>: change them there and every page, header and footer follows.</p></div>';

		echo '<div class="lb-card"><h2>Using a dashboard? Application password</h2><p>A dashboard can call the same tools over REST with a WordPress application password (create one under Users &rarr; Profile &rarr; Application Passwords). No token or command-line needed.</p><div class="lb-code" id="lb-rest">GET  ' . esc_html( rest_url( 'launch-builder/v1/tools' ) ) . "\nPOST " . esc_html( rest_url( 'launch-builder/v1/tools/get-playbook' ) ) . '<button type="button" class="button lb-copy" data-lb-copy="lb-rest">Copy</button></div>';
		echo '<p class="description">' . ( wp_is_application_passwords_available() ? 'Application passwords are available on this site.' : 'Application passwords are disabled on this site (they need HTTPS or a local environment, and a security plugin may block them).' ) . '</p></div>';
		echo '</div>' . self::js(); // phpcs:ignore
	}

	/** Loopback tests that do not need the token. */
	public static function run_check(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_check' ) ) {
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
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
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
		}
		empty( $_POST['remove_all'] ) ? delete_option( 'launch_builder_remove_all_data' ) : update_option( 'launch_builder_remove_all_data', 1, false );
		wp_safe_redirect( self::url( 'launch-builder-activity' ) );
		exit;
	}

	/* ------------------------------------------------------------------ pattern preview */

	/** Pattern groups for the preview list. @return array<string,array{label:string,items:array}> */
	private static function groups(): array {
		Plugin::register_patterns();
		$cats = [ 'heroes' => 'Heroes', 'services' => 'Services', 'content' => 'Text + media', 'text' => 'Bare text pages', 'areas' => 'Service areas', 'maps' => 'Maps and contact', 'cta' => 'Calls to action', 'footers' => 'Footers', 'proof' => 'Reviews and proof', 'ratings' => 'Rating widgets' ];
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
	 * Preview route. ?lb_preview=<slug> renders one pattern; ?lb_preview=@<category> renders every pattern of a category in a
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
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );

		$files = $is_cat ? ( glob( LB_DIR . 'patterns/*.php' ) ?: [] ) : [ LB_DIR . 'patterns/' . $slug . '.php' ];
		$stamp = 0;
		foreach ( $files as $fl ) {
			$stamp = max( $stamp, is_file( $fl ) ? (int) filemtime( $fl ) : 0 );
		}
		$key = 'lb_pv_' . md5( implode( '|', [ $raw, 'pv3', LB_VERSION, $stamp, count( $files ), wp_json_encode( get_option( Company::OPT ) ), wp_json_encode( get_option( Company::OPT_SRC ) ), (string) get_option( 'kadence_global_palette' ), md5( wp_json_encode( get_theme_mods() ) ), md5( wp_json_encode( get_option( 'active_plugins' ) ) ), (string) get_option( 'kadence_blocks_config_blocks' ) ] ) );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit && empty( $_GET['lb_fresh'] ) ) { // phpcs:ignore
			header( 'X-LB-Preview: cached' );
			echo $hit; // phpcs:ignore -- admin-only, built below from trusted pattern files.
			exit;
		}

		ob_start();
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
		foreach ( $items as $p ) {
			if ( in_array( 'launch-builder-headers', (array) $p['categories'], true ) ) {
				echo '<p style="padding:24px;font-family:sans-serif">Header patterns are applied with <code>set-header</code>; they show on your site once set.</p>';
				continue;
			}
			$sl = substr( $p['name'], 15 );
			echo '<section class="lb-pv-item" id="p-' . esc_attr( $sl ) . '">';
			if ( $is_cat ) {
				echo '<div class="lb-pv-label">' . esc_html( $p['title'] ) . ' <code>' . esc_html( $sl ) . '</code></div>';
			}
			echo '<div class="entry-content single-content">' . do_blocks( $p['content'] ) . '</div></section>'; // phpcs:ignore -- admin-only preview of trusted pattern files.
		}
		wp_footer();
		echo '</body></html>';
		$out = (string) ob_get_clean();
		if ( $items ) {
			set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
		}
		echo $out; // phpcs:ignore
		exit;
	}

	public static function page_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$groups = self::groups();
		$nonce = wp_create_nonce( 'lb_preview' );
		$cur = isset( $_GET['p'] ) ? sanitize_key( wp_unslash( $_GET['p'] ) ) : ''; // phpcs:ignore
		echo self::css() . '<style>
.lb-pv{display:grid;grid-template-columns:270px 1fr;gap:18px;align-items:start}.lb-side{background:#fff;border:1px solid #dcdcde;border-radius:10px;max-height:calc(100vh - 190px);overflow:auto}
.lb-side input{width:calc(100% - 20px);margin:10px}.lb-side h4{margin:10px 12px 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#646970;display:flex;justify-content:space-between;align-items:center}.lb-side h4 a{padding:0;border:0;font-size:11px;text-transform:none;letter-spacing:0;color:#2271b1;display:inline}
.lb-side a{display:block;padding:6px 12px;text-decoration:none;color:#1d2327;border-left:3px solid transparent;font-size:13px}.lb-side a:hover{background:#f6f7f7}.lb-side a.on{background:#f0f6fc;border-left-color:#2271b1;font-weight:600}
.lb-main{background:#fff;border:1px solid #dcdcde;border-radius:10px;overflow:hidden}.lb-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid #f0f0f1}
.lb-bar .t{font-weight:600;margin-right:auto}.lb-frame{background:#f0f0f1;padding:14px;position:relative}#lb-ld{display:none;position:absolute;left:0;right:0;top:0;z-index:2;text-align:center;padding:10px;background:#2271b1;color:#fff;font-size:13px}#lb-ld.on{display:block}
#lb-fw{margin:0 auto;overflow:hidden;border-radius:6px;box-shadow:0 1px 8px rgba(0,0,0,.12);background:#fff}#lb-fw iframe{border:0;background:#fff;display:block}.lb-bar .button.on{background:#2271b1;color:#fff;border-color:#2271b1}
@media(max-width:900px){.lb-pv{grid-template-columns:1fr}}
</style><div class="wrap lb-wrap" style="max-width:1400px"><h1>Pattern preview</h1><p class="lb-sub">Every pattern, rendered with your theme, colours and Business Info. Click a group name to render that whole group in one go, then click patterns to jump between them. Your AI assistant picks from these; a person can insert any of them in the editor (Patterns &rarr; Launch Builder).</p><div class="lb-pv"><div class="lb-side"><input type="search" id="lb-q" placeholder="Search patterns">';
		foreach ( $groups as $ck => $g ) {
			echo '<div class="lb-g"><h4><span>' . esc_html( $g['label'] ) . '</span><a href="#" class="lb-all" data-cat="' . esc_attr( $ck ) . '" data-title="' . esc_attr( $g['label'] ) . '">view all</a></h4>';
			foreach ( $g['items'] as $it ) {
				echo '<a href="#" class="lb-it' . ( $it['slug'] === $cur ? ' on' : '' ) . '" data-cat="' . esc_attr( $ck ) . '" data-slug="' . esc_attr( $it['slug'] ) . '" data-title="' . esc_attr( $it['title'] ) . '" data-desc="' . esc_attr( $it['desc'] ) . '">' . esc_html( $it['title'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div><div class="lb-main"><div class="lb-bar"><span class="t" id="lb-t"></span><button type="button" class="button on" data-w="1440">Desktop</button><button type="button" class="button" data-w="820">Tablet</button><button type="button" class="button" data-w="390">Mobile</button><a class="button" id="lb-open" target="_blank" rel="noopener">Open</a></div><div style="padding:8px 14px;color:#50575e;font-size:13px" id="lb-d"></div><div class="lb-frame"><div id="lb-ld">Rendering this group (the first view takes a few seconds, later views are instant)&hellip;</div><div id="lb-fw"><iframe id="lb-f" title="Pattern preview"></iframe></div></div></div></div></div>';
		echo '<script>(function(){var base=' . wp_json_encode( home_url( '/' ) ) . ',n=' . wp_json_encode( $nonce ) . ',f=document.getElementById("lb-f"),ld=document.getElementById("lb-ld"),loaded="",
all=[].slice.call(document.querySelectorAll(".lb-it")),heads=[].slice.call(document.querySelectorAll(".lb-all"));
f.addEventListener("load",function(){ld.classList.remove("on")});
function mark(a){all.forEach(function(x){x.classList.toggle("on",x===a)})}
function url(cat,slug){return base+"?lb_preview=@"+cat+"&_lbn="+n+(slug?"#p-"+slug:"")}
function load(cat,slug){if(loaded===cat){ld.classList.remove("on");try{var d=f.contentWindow.document,el=slug?d.getElementById("p-"+slug):null;f.contentWindow.scrollTo(0,el?el.offsetTop:0);return}catch(e){}}
loaded=cat;ld.classList.add("on");f.src=url(cat,slug);document.getElementById("lb-open").href=url(cat,slug)}
function showItem(a){mark(a);document.getElementById("lb-t").textContent=a.dataset.title+"  ("+a.dataset.slug+")";document.getElementById("lb-d").textContent=a.dataset.desc+"  Use it with: apply-pattern slug \"" + a.dataset.slug + "\"";load(a.dataset.cat,a.dataset.slug);history.replaceState(null,"","admin.php?page=launch-builder-patterns&p="+a.dataset.slug)}
all.forEach(function(a){a.addEventListener("click",function(e){e.preventDefault();showItem(a)})});
heads.forEach(function(h){h.addEventListener("click",function(e){e.preventDefault();mark(null);document.getElementById("lb-t").textContent=h.dataset.title+" (all)";document.getElementById("lb-d").textContent="Every pattern in this group, one after another. Click a pattern name on the left to jump to it.";load(h.dataset.cat,"")})});
var fw=document.getElementById("lb-fw"),wrap=document.querySelector(".lb-frame"),cur=1440,btns=[].slice.call(document.querySelectorAll("[data-w]"));
function fit(){var cw=wrap.clientWidth-28,sc=Math.min(1,cw/cur),h=Math.max(420,window.innerHeight-310);fw.style.width=Math.round(cur*sc)+"px";fw.style.height=h+"px";f.style.width=cur+"px";f.style.height=Math.round(h/sc)+"px";f.style.transform="scale("+sc+")";f.style.transformOrigin="0 0"}
btns.forEach(function(b){b.addEventListener("click",function(){cur=+b.dataset.w;btns.forEach(function(x){x.classList.toggle("on",x===b)});fit()})});window.addEventListener("resize",fit);fit();
document.getElementById("lb-q").addEventListener("input",function(e){var q=e.target.value.toLowerCase();all.forEach(function(a){a.style.display=(a.dataset.title+a.dataset.slug+a.dataset.desc).toLowerCase().indexOf(q)>-1?"":"none"});document.querySelectorAll(".lb-g").forEach(function(g){g.style.display=[].some.call(g.querySelectorAll(".lb-it"),function(a){return a.style.display!=="none"})?"":"none"})});
var start=all.filter(function(a){return a.classList.contains("on")})[0]||all[0];if(start){showItem(start)}})();</script>';
	}

	/* ------------------------------------------------------------------ activity + safety */

	public static function page_activity(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo self::css() . '<div class="wrap lb-wrap"><h1>Activity and safety</h1><p class="lb-sub">What your AI assistant has changed, and the switches that keep a connected agent in bounds.</p>';
		echo '<div class="lb-card"><h2>Power tools</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_mcp_power">';
		wp_nonce_field( 'lb_mcp_power' );
		echo '<p><label><input type="checkbox" name="power" value="1" ' . checked( Abilities::power(), true, false ) . '> Allow the REST proxy to create users and change plugins, themes and core settings.</label></p><p class="description">Off by default. Turn it on for a fresh build that needs it (for example installing Rank Math), then turn it off.</p>';
		submit_button( 'Save', 'secondary', 'submit', false );
		echo '</form></div>';
		echo '<div class="lb-card"><h2>When the plugin is deleted</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_remove_all_data">';
		wp_nonce_field( 'lb_remove_all_data' );
		echo '<p><label><input type="checkbox" name="remove_all" value="1" ' . checked( (bool) get_option( 'launch_builder_remove_all_data' ), true, false ) . '> Also delete Business Info when Launch Builder is deleted.</label></p><p class="description">Off by default: pages print Business Info through tokens, so it is kept unless you tick this. Settings, logs and tokens are always removed; your pages, patterns and media stay.</p>';
		submit_button( 'Save', 'secondary', 'submit', false );
		echo '</form></div>';
		echo '<div class="lb-card"><h2>Undo</h2><p>Design and settings changes are snapshotted before they are written. Ask your AI assistant to run <code>restore-snapshot</code> with no id to list them, or with an id to undo one.</p></div>';
		$log = array_slice( (array) get_option( 'launch_builder_log', [] ), 0, 40 );
		echo '<div class="lb-card"><h2>Recent activity</h2>';
		if ( ! $log ) {
			echo '<p>No write calls yet.</p>';
		} else {
			echo '<table class="lb-table"><thead><tr><td><b>When</b></td><td><b>User</b></td><td><b>Tool</b></td><td><b>Result</b></td></tr></thead><tbody>';
			foreach ( $log as $e ) {
				$u = get_userdata( (int) $e['u'] );
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $e['t'] ) ) . '</td><td>' . esc_html( $u ? $u->user_login : '-' ) . '</td><td><code>' . esc_html( $e['tool'] ) . '</code></td><td>' . ( $e['ok'] ? 'ok' : 'failed ' . esc_html( (string) $e['note'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}
}
