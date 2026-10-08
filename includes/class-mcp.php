<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal MCP server (Streamable HTTP, JSON responses) served at:
 *   https://site.com/mcp                              (pretty alias; bearer token only)
 *   https://site.com/wp-json/launch-builder/v1/mcp    (REST route; also accepts WP Application Passwords)
 *
 * Auth: "Authorization: Bearer <token>" (or X-Launch-Token), a Launch Builder token created under
 * Settings > Launch Builder. Only a SHA-256 hash is stored; the plaintext is shown once, in the response
 * to the button click, and never written anywhere. Tokens expire after 180 days and act as the user who
 * created them. Tools are the registry in class-abilities.php; each tool re-checks the user's capability.
 */
final class Mcp {

	private const OPT_TOKEN = 'launch_builder_mcp_token';
	private const OPT_LOG = 'launch_builder_log';
	private const PROTOCOL = '2025-06-18';
	private const MAX_BODY = 1048576; // 1 MB.
	private const MAX_BATCH = 20;
	private const TOKEN_TTL = 15552000; // 180 days.

	public static function boot(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'parse_request', [ __CLASS__, 'pretty' ] );
		add_action( 'admin_post_lb_mcp_token', [ __CLASS__, 'make_token' ] );
		add_action( 'admin_post_lb_mcp_power', [ __CLASS__, 'set_power' ] );
	}

	/* ------------------------------------------------------------------ transport */

	public static function routes(): void {
		register_rest_route( 'launch-builder/v1', '/mcp', [
			'methods'             => [ 'POST', 'GET', 'DELETE' ],
			'callback'            => [ __CLASS__, 'rest' ],
			'permission_callback' => '__return_true', // Auth is enforced in handle().
		] );

		// Plain REST wrappers for dashboards (WordPress Application Password / any WP auth). No JSON-RPC envelope.
		register_rest_route( 'launch-builder/v1', '/tools', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_tools' ],
			'permission_callback' => static function () { return current_user_can( 'edit_pages' ); },
		] );
		register_rest_route( 'launch-builder/v1', '/tools/(?P<name>[a-z0-9-]+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_tool' ],
			'permission_callback' => static function () { return current_user_can( 'edit_pages' ); },
		] );
	}

	/** GET /launch-builder/v1/tools: what the connected user may run. */
	public static function rest_tools() {
		$out = [];
		foreach ( self::tool_list() as $t ) {
			$out[] = [ 'name' => $t['name'], 'title' => $t['title'], 'description' => $t['description'], 'readonly' => $t['annotations']['readOnlyHint'], 'input_schema' => $t['inputSchema'] ];
		}
		return rest_ensure_response( [ 'tools' => $out ] );
	}

	/** POST /launch-builder/v1/tools/<name> with the tool arguments as the JSON body. */
	public static function rest_tool( \WP_REST_Request $req ) {
		$defs = Abilities::defs();
		$name = (string) ( $req->get_url_params()['name'] ?? '' ); // URL only: a tool argument called 'name' in the body must not override it
		if ( ! isset( $defs[ $name ] ) ) {
			return new \WP_Error( 'lb_unknown_tool', 'Unknown tool: ' . $name, [ 'status' => 404 ] );
		}
		$d = $defs[ $name ];
		if ( ! current_user_can( $d['cap'] ) ) {
			self::log( $name, false, 'forbidden' );
			return new \WP_Error( 'lb_forbidden', 'Your user is not permitted to run ' . $name . '.', [ 'status' => 403 ] );
		}
		$args = $req->get_json_params();
		$args = is_array( $args ) ? $args : [];
		$bad = self::validate_args( $d['input'], $args );
		if ( $bad ) {
			return new \WP_Error( 'lb_invalid_args', $bad, [ 'status' => 400 ] );
		}
		try {
			$r = call_user_func( $d['callback'], $args );
		} catch ( \Throwable $e ) {
			self::log( $name, false, 'exception' );
			return new \WP_Error( 'lb_exception', $e->getMessage(), [ 'status' => 500 ] );
		}
		if ( empty( $d['annotations']['readonly'] ) || is_wp_error( $r ) ) {
			self::log( $name, ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() : '' );
		}
		Cache::flush(); // Purges the tool asked for, once, before the response is sent.
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function rest( \WP_REST_Request $req ) {
		[ $status, $body ] = self::handle( $req->get_method(), (string) $req->get_body(), static function ( $h ) use ( $req ) { return $req->get_header( $h ); }, true );
		Cache::flush(); // Purges asked for by the tools in this request, once, before the response is sent.
		$res = new \WP_REST_Response( null === $body ? null : $body, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** Pretty alias: /mcp (works without flushing rewrite rules). */
	public static function pretty( $wp ): void {
		$path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( trim( $home . '/mcp', '/' ) !== $path ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET'; // phpcs:ignore
		wp_set_current_user( 0 ); // Ignore any login cookie: this entry point accepts the bearer token only.
		$raw = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		[ $status, $body ] = self::handle( $method, $raw, static function ( $h ) {
			$name = strtoupper( str_replace( '-', '_', $h ) );
			$key = in_array( $name, [ 'CONTENT_TYPE', 'CONTENT_LENGTH' ], true ) ? $name : 'HTTP_' . $name; // PHP exposes these two without the HTTP_ prefix.
			return $_SERVER[ $key ] ?? ( 'authorization' === strtolower( $h ) ? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null ) : null ); // phpcs:ignore
		}, false );
		Cache::flush(); // Purges asked for by the tools in this request, once, before the response is sent.
		while ( ob_get_level() ) {
			ob_end_clean(); // A stray notice or BOM from another plugin must not corrupt the JSON-RPC body.
		}
		status_header( $status );
		nocache_headers();
		if ( null !== $body ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore
		}
		exit;
	}

	private static function rpc_error( int $http, int $code, string $msg ): array {
		return [ $http, [ 'jsonrpc' => '2.0', 'id' => null, 'error' => [ 'code' => $code, 'message' => $msg ] ] ];
	}

	/** @return array{0:int,1:mixed} HTTP status and JSON-serialisable body (null = empty). */
	private static function handle( string $method, string $raw, callable $header, bool $trust_wp_auth ): array {
		if ( 'GET' === $method || 'DELETE' === $method ) {
			return [ 405, [ 'error' => 'This endpoint accepts POST only (no SSE stream, no sessions).' ] ];
		}
		if ( false === stripos( (string) $header( 'content-type' ), 'application/json' ) ) {
			return self::rpc_error( 415, -32000, 'Content-Type must be application/json.' );
		}
		$auth = self::authenticate( $header, $trust_wp_auth );
		if ( is_wp_error( $auth ) ) {
			return self::rpc_error( (int) ( $auth->get_error_data()['status'] ?? 401 ), -32001, $auth->get_error_message() );
		}
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return self::rpc_error( 413, -32000, 'Request body is over 1 MB.' );
		}
		$msg = json_decode( $raw, true );
		if ( ! is_array( $msg ) ) {
			return self::rpc_error( 400, -32700, 'Parse error' );
		}
		$is_batch = $msg && array_keys( $msg ) === range( 0, count( $msg ) - 1 );
		if ( $is_batch && count( $msg ) > self::MAX_BATCH ) {
			return self::rpc_error( 400, -32600, 'Batch is limited to ' . self::MAX_BATCH . ' messages.' );
		}
		$out = [];
		foreach ( $is_batch ? $msg : [ $msg ] as $m ) {
			$r = self::dispatch( is_array( $m ) ? $m : [] );
			if ( null !== $r ) {
				$out[] = $r;
			}
		}
		if ( ! $out ) {
			return [ 202, null ]; // Only notifications / responses.
		}
		return [ 200, $is_batch ? $out : $out[0] ];
	}

	/* ------------------------------------------------------------------ auth */

	private static function client_ip(): string {
		$ip = (string) apply_filters( 'launch_builder_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' ); // phpcs:ignore
		return preg_replace( '/[^0-9a-f:.]/i', '', $ip );
	}

	private static function authenticate( callable $header, bool $trust_wp_auth ) {
		// 1. Already authenticated by WordPress (Application Password over the REST route, or a logged-in session with nonce).
		if ( $trust_wp_auth && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}
		$tok = '';
		$h = (string) $header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $h, $m ) ) {
			$tok = trim( $m[1] );
		} elseif ( $header( 'x-launch-token' ) ) {
			$tok = trim( (string) $header( 'x-launch-token' ) );
		}
		if ( '' === $tok ) {
			return new \WP_Error( 'lb_auth', 'Unauthorized. Send "Authorization: Bearer <token>" (create one in Settings > Launch Builder).', [ 'status' => 401 ] );
		}
		// 2. A valid token always passes, even while someone else is being throttled.
		$stored = get_option( self::OPT_TOKEN );
		if ( is_array( $stored ) && ! empty( $stored['hash'] ) && hash_equals( $stored['hash'], hash( 'sha256', $tok ) ) ) {
			if ( ! empty( $stored['expires'] ) && time() > (int) $stored['expires'] ) {
				return new \WP_Error( 'lb_expired', 'This token has expired. Create a new one in Settings > Launch Builder.', [ 'status' => 401 ] );
			}
			if ( get_userdata( (int) $stored['user'] ) ) {
				wp_set_current_user( (int) $stored['user'] );
				return true;
			}
		}
		// 3. Only wrong tokens count against the (per-IP) guessing limit.
		$key = 'lb_mcp_fail_' . md5( self::client_ip() );
		$fails = (int) get_transient( $key );
		set_transient( $key, $fails + 1, 10 * MINUTE_IN_SECONDS );
		if ( $fails >= 20 ) {
			return new \WP_Error( 'lb_rate', 'Too many failed attempts. Try again in 10 minutes.', [ 'status' => 429 ] );
		}
		return new \WP_Error( 'lb_auth', 'Unauthorized.', [ 'status' => 401 ] );
	}

	/* ------------------------------------------------------------------ JSON-RPC */

	private static function dispatch( array $m ): ?array {
		$id = $m['id'] ?? null;
		$method = (string) ( $m['method'] ?? '' );
		$params = is_array( $m['params'] ?? null ) ? $m['params'] : [];
		$has_id = array_key_exists( 'id', $m );
		$ok = static function ( $result ) use ( $id ) { return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ]; };
		$err = static function ( int $code, string $msg ) use ( $id ) { return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $msg ] ]; };

		if ( ! $has_id ) {
			return null; // Notification (e.g. notifications/initialized): no response.
		}
		switch ( $method ) {
			case 'initialize':
				$want = (string) ( $params['protocolVersion'] ?? '' );
				return $ok( [
					'protocolVersion' => in_array( $want, [ '2024-11-05', '2025-03-26', '2025-06-18' ], true ) ? $want : self::PROTOCOL,
					'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
					'serverInfo'      => [ 'name' => 'launch-builder', 'title' => 'Launch Builder', 'version' => LB_VERSION ],
					'instructions'    => 'Launch Builder builds Kadence sites from premade patterns. Call get-playbook FIRST: it explains the order of work, page types (landing, text, wide), bare Markdown text pages, and what is global (get-company/set-company business info, set-buttons, set-nav-style, set-page-style). Then site-state. To build a whole starter site in one call use build-site (blueprint: business facts, brand, header + menu, synced patterns, global areas, pages made of patterns, SEO). For finer control: list-patterns / get-pattern (editable slots) / apply-pattern; set-brand (palette, fonts, logo); set-header (header patterns + menu); set-synced-pattern + set-global-area (edit once, shows everywhere, e.g. footer CTA). Kadence palette is an option (use set-brand), fonts and header are theme mods. save-pattern stores a custom per-site pattern. rest-request reaches other WordPress REST routes (users, plugins, themes and core settings are off unless an admin enables power tools). Settings writes are snapshotted: restore-snapshot undoes them.',
				] );
			case 'ping':
				return $ok( new \stdClass() );
			case 'tools/list':
				return $ok( [ 'tools' => self::tool_list() ] );
			case 'tools/call':
				return $ok( self::call( (string) ( $params['name'] ?? '' ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [] ) );
			case 'resources/list':
				return $ok( [ 'resources' => [] ] );
			case 'prompts/list':
				return $ok( [ 'prompts' => [] ] );
		}
		return $err( -32601, 'Method not found: ' . $method );
	}

	/** Make empty arrays serialize as JSON objects where a schema object is required. */
	private static function schema( $s ) {
		if ( ! is_array( $s ) ) {
			return $s;
		}
		if ( ! $s ) {
			return new \stdClass();
		}
		foreach ( $s as $k => $v ) {
			if ( 'properties' === $k && is_array( $v ) ) {
				$s[ $k ] = $v ? array_map( [ __CLASS__, 'schema' ], $v ) : new \stdClass();
			} elseif ( 'items' === $k || 'additionalProperties' === $k ) {
				$s[ $k ] = self::schema( $v );
			}
		}
		return $s;
	}

	private static function tool_list(): array {
		$tools = [];
		foreach ( Abilities::defs() as $d ) {
			if ( ! current_user_can( $d['cap'] ) ) {
				continue;
			}
			$in = $d['input'];
			if ( empty( $in['properties'] ) ) {
				$in['properties'] = new \stdClass();
			}
			$in['properties'] = is_array( $in['properties'] ) ? array_map( [ __CLASS__, 'schema' ], $in['properties'] ) : $in['properties'];
			$tools[] = [
				'name'        => $d['name'],
				'title'       => $d['label'],
				'description' => $d['description'],
				'inputSchema' => $in,
				'annotations' => [ 'readOnlyHint' => ! empty( $d['annotations']['readonly'] ) ],
			];
		}
		return $tools;
	}

	/** Enforce what the schema advertises: required keys, basic types and enums. @return string|null error message */
	private static function validate_args( array $schema, array $args ): ?string {
		$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		foreach ( (array) ( $schema['required'] ?? [] ) as $req ) {
			if ( ! array_key_exists( $req, $args ) ) {
				return 'Missing required argument: ' . $req;
			}
		}
		foreach ( $args as $k => $v ) {
			if ( ! isset( $props[ $k ] ) || ! is_array( $props[ $k ] ) ) {
				continue;
			}
			$p = $props[ $k ];
			switch ( $p['type'] ?? '' ) {
				case 'string':
					$ok = is_string( $v );
					break;
				case 'integer':
					$ok = is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) || ( is_float( $v ) && floor( $v ) === $v );
					break;
				case 'number':
					$ok = is_numeric( $v );
					break;
				case 'boolean':
					$ok = is_bool( $v );
					break;
				case 'array':
				case 'object':
					$ok = is_array( $v );
					break;
				default:
					$ok = true;
			}
			if ( ! $ok ) {
				return 'Argument "' . $k . '" must be of type ' . $p['type'] . '.';
			}
			if ( isset( $p['enum'] ) && ! in_array( $v, $p['enum'], true ) ) {
				return 'Argument "' . $k . '" must be one of: ' . implode( ', ', $p['enum'] ) . '.';
			}
		}
		return null;
	}

	private static function call( string $name, array $args ): array {
		$defs = Abilities::defs();
		if ( ! isset( $defs[ $name ] ) ) {
			return self::text( 'Unknown tool: ' . $name, true );
		}
		$d = $defs[ $name ];
		if ( ! current_user_can( $d['cap'] ) ) {
			self::log( $name, false, 'forbidden' );
			return self::text( 'Your user is not permitted to run ' . $name . '.', true );
		}
		$bad = self::validate_args( $d['input'], $args );
		if ( $bad ) {
			return self::text( 'Invalid arguments for ' . $name . ': ' . $bad, true );
		}
		try {
			$r = call_user_func( $d['callback'], $args );
		} catch ( \Throwable $e ) {
			self::log( $name, false, 'exception' );
			return self::text( 'Error: ' . $e->getMessage(), true );
		}
		if ( empty( $d['annotations']['readonly'] ) || is_wp_error( $r ) ) {
			self::log( $name, ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() : '' );
		}
		if ( is_wp_error( $r ) ) {
			return self::text( $r->get_error_code() . ': ' . $r->get_error_message(), true );
		}
		$json = wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( (string) $json ) > 200000 ) {
			$json = substr( (string) $json, 0, 200000 ) . "\n…(truncated at 200 KB; narrow the request)";
		}
		return self::text( (string) $json, false );
	}

	private static function text( string $t, bool $error ): array {
		return [ 'content' => [ [ 'type' => 'text', 'text' => $t ] ], 'isError' => $error ];
	}

	/** Audit log of write calls and failures (last 200). */
	private static function log( string $tool, bool $ok, string $note = '' ): void {
		$log = (array) get_option( self::OPT_LOG, [] );
		array_unshift( $log, [ 't' => time(), 'u' => get_current_user_id(), 'tool' => $tool, 'ok' => $ok, 'note' => $note ] );
		update_option( self::OPT_LOG, array_slice( $log, 0, 200 ), false );
	}

	/* ------------------------------------------------------------------ settings page */

	/** Create (replace) the MCP token for a user. Returns the plaintext token once; only its hash is stored. */
	public static function create_token( int $user_id ): string {
		$plain = 'lbm_' . bin2hex( random_bytes( 24 ) );
		update_option( self::OPT_TOKEN, [ 'hash' => hash( 'sha256', $plain ), 'user' => $user_id, 'created' => time(), 'expires' => time() + self::TOKEN_TTL, 'last4' => substr( $plain, -4 ) ], false );
		return $plain;
	}

	/** Shows the new token in the response itself (never stored), with copy-paste connection commands. */
	public static function make_token(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_mcp_token' ) ) {
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
		}
		$plain = self::create_token( get_current_user_id() );
		$url = home_url( '/mcp' );
		$remote = wp_json_encode( [ 'mcpServers' => [ 'launch-builder' => [ 'type' => 'http', 'url' => $url, 'headers' => [ 'Authorization' => 'Bearer ' . $plain ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$desktop = wp_json_encode( [ 'mcpServers' => [ 'launch-builder' => [ 'command' => 'npx', 'args' => [ '-y', 'mcp-remote', $url, '--header', 'Authorization: Bearer ' . $plain ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		$pre = 'style="background:#f6f7f7;padding:12px;overflow:auto;white-space:pre-wrap"';
		wp_die(
			'<h1>Your Launch Builder token</h1><p><strong>Copy it now. It is shown once and cannot be recovered.</strong> (Valid 180 days.)</p>'
			. '<p><code style="font-size:15px;user-select:all">' . esc_html( $plain ) . '</code></p>'
			. '<p>Remote server (most MCP clients):</p><pre ' . $pre . '>' . esc_html( (string) $remote ) . '</pre>'
			. '<p>Local bridge (mcp-remote, for clients that only run local servers):</p><pre ' . $pre . '>' . esc_html( (string) $desktop ) . '</pre>'
			. '<p><a href="' . esc_url( Admin::url() ) . '">Back to Launch Builder settings</a></p>',
			'Launch Builder token',
			[ 'response' => 200 ]
		);
	}

	public static function set_power(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_mcp_power' ) ) {
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
		}
		update_option( 'launch_builder_power', ! empty( $_POST['power'] ) ? 1 : 0, false );
		wp_safe_redirect( Admin::url( 'launch-builder-activity' ) );
		exit;
	}
}
