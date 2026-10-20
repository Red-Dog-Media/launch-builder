<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects: where an old URL goes. Written automatically when a URL changes (Permalinks calls uri_changed()) and by
 * hand (set-redirect, import-url-map, the URLs screen).
 *
 * Two stores:
 *  - Rank Math Redirections, when its "redirections" module is active (and the setting redirect_store is 'auto' or
 *    'rankmath'): rows are written through Rank Math's own API (RankMath\Redirections\Redirection::save(), exact sources,
 *    case-insensitive), so they show and count hits in Rank Math's screen. Verified against Rank Math 1.0.280
 *    (includes/modules/redirections: class-redirection.php, class-db.php).
 *  - Our own table {prefix}lb_redirects otherwise: one row per source path (unique hash), with hit counts. A row targets
 *    either an object (post/term: it always goes to that object's CURRENT URL, so chains cannot form when the object
 *    moves again) or a URL. Consulted only on 404s (template_redirect, priority 2), with one indexed query.
 *
 * No chains, no loops: a new redirect A -> B whose B is itself redirected follows B to its final target; redirects that
 * pointed at A are re-pointed at the final target; a target that leads back to its source is refused. When an object
 * takes over a URL, redirects FROM that URL that led to the same object are removed (Rank Math checks every request,
 * so a stale one would hide the live page); others are reported (audit check redirect_shadowed).
 */
final class Redirects {

	/** Modules screen (Modules contract): always on (URL changes write here; one query, on 404s only). */
	const MODULE = [
		'slug'        => 'redirects',
		'label'       => 'Redirects',
		'description' => 'Redirects from old URLs: written into Rank Math Redirections when its module is on, else kept in our own table with hit counts. Chains are collapsed and loops refused.',
		'toggle'      => false,
	];

	const TABLE    = 'lb_redirects';
	const CODES    = [ 301, 302, 307, 308, 410 ];
	const MAX_HOPS = 10;

	/** @var array<string,bool> memo: does Rank Math's table exist (per blog). */
	private static $rm_table = [];

	public static function boot(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect' ], 2 ); // Before redirect_canonical's 404 guessing (10).
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** dbDelta schema (Permalinks::install() runs it together with the URI table). */
	public static function schema( string $charset ): string {
		$t = self::table();
		return "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_hash char(32) NOT NULL DEFAULT '',
  source varchar(1000) NOT NULL DEFAULT '',
  target varchar(2048) NOT NULL DEFAULT '',
  target_hash char(32) NOT NULL DEFAULT '',
  object_type varchar(10) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  code smallint(5) unsigned NOT NULL DEFAULT 301,
  origin varchar(20) NOT NULL DEFAULT 'manual',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime DEFAULT NULL,
  created datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash),
  KEY target_hash (target_hash),
  KEY object (object_type,object_id)
) {$charset};";
	}

	/* ------------------------------------------------------------------ store */

	/** Is Rank Math's redirections module running (and its table there)? */
	public static function rankmath_active(): bool {
		if ( ! class_exists( '\RankMath\Helper' ) || ! class_exists( '\RankMath\Redirections\Redirection' ) || ! class_exists( '\RankMath\Redirections\DB' ) ) {
			return false;
		}
		try {
			if ( ! \RankMath\Helper::is_module_active( 'redirections' ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return self::rm_table_exists();
	}

	private static function rm_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rank_math_redirections';
	}

	private static function rm_table_exists(): bool {
		global $wpdb;
		$key = (string) get_current_blog_id();
		if ( ! isset( self::$rm_table[ $key ] ) ) {
			self::$rm_table[ $key ] = self::rm_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::rm_table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$rm_table[ $key ];
	}

	/** Where new redirects go: 'rankmath' or 'own' (setting redirect_store: auto | own | rankmath). */
	public static function store(): string {
		$pref = (string) ( Permalinks::settings()['redirect_store'] ?? 'auto' );
		if ( 'own' === $pref ) {
			return 'own';
		}
		return self::rankmath_active() ? 'rankmath' : 'own';
	}

	/* ------------------------------------------------------------------ targets */

	/**
	 * Turn a target given by a caller into { type: object|url|none, object_type, object_id, url, path }.
	 * Accepts post:ID / term:ID, a path or URL on this site (an object living there becomes an object target), or an
	 * external http(s) URL.
	 */
	public static function parse_target( $to ): array {
		if ( is_array( $to ) && isset( $to['object_type'], $to['object_id'] ) ) {
			return [ 'type' => 'object', 'object_type' => 'term' === $to['object_type'] ? 'term' : 'post', 'object_id' => (int) $to['object_id'], 'url' => '', 'path' => '' ];
		}
		$to = trim( (string) $to );
		if ( preg_match( '/^(post|term):(\d+)$/', $to, $m ) ) {
			return [ 'type' => 'object', 'object_type' => $m[1], 'object_id' => (int) $m[2], 'url' => '', 'path' => '' ];
		}
		if ( '' === $to ) {
			return [ 'type' => 'none', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => '' ];
		}
		$path = Permalinks::local_path( $to );
		if ( null !== $path ) { // On this site.
			$hit = '' !== $path ? Permalinks::lookup_uri( $path ) : null;
			if ( $hit ) {
				return [ 'type' => 'object', 'object_type' => $hit['object_type'], 'object_id' => (int) $hit['object_id'], 'url' => '', 'path' => $path ];
			}
			return [ 'type' => 'url', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => $path ];
		}
		$url = esc_url_raw( $to, [ 'http', 'https' ] );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return [ 'type' => 'invalid', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => '' ];
		}
		return [ 'type' => 'url', 'object_type' => '', 'object_id' => 0, 'url' => $url, 'path' => null ];
	}

	/** The URL a parsed target sends visitors to now ('' when its object is gone or not public). */
	public static function target_url( array $t ): string {
		if ( 'object' === $t['type'] ) {
			return Permalinks::live_url( $t['object_type'], (int) $t['object_id'] );
		}
		if ( 'url' === $t['type'] ) {
			return null === $t['path'] ? (string) $t['url'] : Permalinks::build_url( (string) $t['path'] );
		}
		return '';
	}

	/** The site path a parsed target ends at (null for external URLs, '' for the home page). */
	private static function target_path( array $t ): ?string {
		if ( 'object' === $t['type'] ) {
			$url = Permalinks::live_url( $t['object_type'], (int) $t['object_id'] );
			return '' === $url ? null : Permalinks::local_path( $url );
		}
		return 'url' === $t['type'] ? $t['path'] : null;
	}

	/* ------------------------------------------------------------------ adding */

	/**
	 * Add (or replace) the redirect from $from to $to in the active store (or $store), collapsing chains and refusing
	 * loops.
	 *
	 * @param string       $from    path or URL on this site.
	 * @param string|array $to      see parse_target().
	 * @return array|\WP_Error { store, id, from, to, code, collapsed: int, created: bool, dry_run }
	 */
	public static function add( string $from, $to, int $code = 301, string $origin = 'manual', bool $dry_run = false, string $store = '', bool $force = false ) {
		$src = Permalinks::local_path( $from );
		if ( null === $src || '' === $src ) {
			return new \WP_Error( 'lb_redirect_from', 'from must be a path or URL on this site (not the home page).', [ 'status' => 400 ] );
		}
		$first = strtolower( (string) strtok( $src, '/' ) );
		if ( in_array( $first, [ 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login.php', 'xmlrpc.php', 'mcp', strtolower( trim( rest_get_url_prefix(), '/' ) ) ], true ) ) {
			return new \WP_Error( 'lb_redirect_reserved', 'WordPress, REST API and /mcp paths cannot be redirected.', [ 'status' => 400 ] );
		}
		$live = Permalinks::lookup_uri( $src );
		if ( $live && ! $force ) {
			return new \WP_Error( 'lb_redirect_live', 'A page lives at /' . $src . ' (' . $live['object_type'] . ':' . (int) $live['object_id'] . '): a redirect would hide it (Rank Math) or never fire. Give that page another URL first, or pass force: true.', [ 'status' => 409 ] );
		}
		if ( ! in_array( $code, self::CODES, true ) ) {
			return new \WP_Error( 'lb_redirect_code', 'code must be one of ' . implode( ', ', self::CODES ) . '.', [ 'status' => 400 ] );
		}
		$t = 410 === $code ? [ 'type' => 'none', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => null ] : self::parse_target( $to );
		if ( 'invalid' === $t['type'] || ( 410 !== $code && 'none' === $t['type'] ) ) {
			return new \WP_Error( 'lb_redirect_to', 'to must be post:ID, term:ID, a path on this site or an http(s) URL.', [ 'status' => 400 ] );
		}
		$store = in_array( $store, [ 'own', 'rankmath' ], true ) ? $store : self::store();
		if ( 'rankmath' === $store && ! self::rankmath_active() ) {
			return new \WP_Error( 'lb_redirect_store', 'Rank Math Redirections is not active.', [ 'status' => 400 ] );
		}
		// Follow the target through existing redirects (collapse) and refuse loops.
		$t = self::follow( $t, $store );
		$end = self::target_path( $t );
		if ( 410 !== $code && null !== $end && Permalinks::key( $end ) === Permalinks::key( $src ) ) {
			return new \WP_Error( 'lb_redirect_loop', 'That redirect would point at itself (directly or through other redirects).', [ 'status' => 409 ] );
		}
		if ( 410 !== $code && 'object' === $t['type'] && '' === self::target_url( $t ) ) {
			return new \WP_Error( 'lb_redirect_target', 'The target object does not exist or is not published.', [ 'status' => 404 ] );
		}
		$out = [ 'store' => $store, 'from' => '/' . $src, 'to' => 410 === $code ? '' : self::describe( $t ), 'url' => 410 === $code ? '' : self::target_url( $t ), 'code' => $code, 'dry_run' => $dry_run ];
		if ( $dry_run ) {
			return $out;
		}
		return 'rankmath' === $store ? self::rm_add( $src, $t, $code, $out ) : self::own_add( $src, $t, $code, $origin, $out );
	}

	/** Human/tool form of a parsed target: post:ID, term:ID, /path or the URL. */
	public static function describe( array $t ): string {
		if ( 'object' === $t['type'] ) {
			return $t['object_type'] . ':' . (int) $t['object_id'];
		}
		if ( 'url' === $t['type'] ) {
			return null === $t['path'] ? (string) $t['url'] : '/' . $t['path'];
		}
		return '';
	}

	/** Follow a url target through redirects of the store until it stops (max MAX_HOPS). */
	private static function follow( array $t, string $store ): array {
		for ( $i = 0; $i < self::MAX_HOPS && 'url' === $t['type'] && null !== $t['path'] && '' !== $t['path']; $i++ ) {
			if ( 'rankmath' === $store ) {
				$rows = self::rm_rows_by_source( $t['path'] );
				if ( ! $rows || '' === (string) $rows[0]['url_to'] ) {
					break;
				}
				$next = self::parse_target( (string) $rows[0]['url_to'] );
			} else {
				$row = self::own_by_source( $t['path'] );
				if ( ! $row || 410 === (int) $row['code'] ) {
					break;
				}
				$next = self::row_target( $row );
			}
			if ( 'object' === $next['type'] || 'none' === $next['type'] ) {
				return 'none' === $next['type'] ? $t : $next;
			}
			if ( null !== $next['path'] && Permalinks::key( (string) $next['path'] ) === Permalinks::key( (string) $t['path'] ) ) {
				break;
			}
			$t = $next;
		}
		return $t;
	}

	/* ------------------------------------------------------------------ own table */

	private static function ready(): bool {
		return Permalinks::installed();
	}

	public static function row_target( array $row ): array {
		if ( '' !== (string) $row['object_type'] && (int) $row['object_id'] ) {
			return [ 'type' => 'object', 'object_type' => (string) $row['object_type'], 'object_id' => (int) $row['object_id'], 'url' => '', 'path' => '' ];
		}
		$target = (string) $row['target'];
		if ( '' === $target ) {
			return [ 'type' => 'none', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => null ];
		}
		if ( preg_match( '#^https?://#i', $target ) ) {
			return [ 'type' => 'url', 'object_type' => '', 'object_id' => 0, 'url' => $target, 'path' => null ];
		}
		return [ 'type' => 'url', 'object_type' => '', 'object_id' => 0, 'url' => '', 'path' => Permalinks::clean_path( $target ) ];
	}

	public static function own_by_source( string $path ): ?array {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE source_hash = %s LIMIT 1", Permalinks::hash( $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	private static function own_add( string $src, array $t, int $code, string $origin, array $out ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return new \WP_Error( 'lb_redirect_table', 'The redirects table is not installed yet (open wp-admin once or run the migrations).', [ 'status' => 503 ] );
		}
		$tab = self::table();
		$data = [
			'source_hash' => Permalinks::hash( $src ),
			'source'      => $src,
			'target'      => 'url' === $t['type'] ? ( null === $t['path'] ? (string) $t['url'] : (string) $t['path'] ) : '',
			'target_hash' => 'url' === $t['type'] && null !== $t['path'] ? Permalinks::hash( (string) $t['path'] ) : '',
			'object_type' => 'object' === $t['type'] ? (string) $t['object_type'] : '',
			'object_id'   => 'object' === $t['type'] ? (int) $t['object_id'] : 0,
			'code'        => $code,
			'origin'      => sanitize_key( $origin ) ?: 'manual',
		];
		$existing = self::own_by_source( $src );
		if ( $existing ) {
			$wpdb->update( $tab, $data, [ 'id' => (int) $existing['id'] ] ); // phpcs:ignore WordPress.DB
			$id = (int) $existing['id'];
		} else {
			$data['created'] = current_time( 'mysql', true );
			$wpdb->insert( $tab, $data ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { // Lost a race on the unique source: update the winner.
				$existing = self::own_by_source( $src );
				if ( ! $existing ) {
					return new \WP_Error( 'lb_redirect_db', 'Could not save the redirect.', [ 'status' => 500 ] );
				}
				$id = (int) $existing['id'];
				$wpdb->update( $tab, $data, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB
			}
		}
		// Collapse: redirects that pointed at the source now point where it goes.
		$collapsed = 0;
		if ( 410 !== $code ) {
			$set = 'object' === $t['type'] ? [ 'target' => '', 'target_hash' => '', 'object_type' => $data['object_type'], 'object_id' => $data['object_id'] ] : [ 'target' => $data['target'], 'target_hash' => $data['target_hash'], 'object_type' => '', 'object_id' => 0 ];
			$collapsed = (int) $wpdb->update( $tab, $set, [ 'target_hash' => $data['source_hash'] ] ); // phpcs:ignore WordPress.DB
		}
		return $out + [ 'id' => $id, 'created' => ! $existing, 'collapsed' => $collapsed ];
	}

	/** A redirect row of our table in tool form. */
	private static function own_row( array $r ): array {
		$t = self::row_target( $r );
		return [
			'id'       => (int) $r['id'],
			'store'    => 'own',
			'from'     => '/' . $r['source'],
			'to'       => self::describe( $t ),
			'url'      => 410 === (int) $r['code'] ? '' : self::target_url( $t ),
			'code'     => (int) $r['code'],
			'hits'     => (int) $r['hits'],
			'last_hit' => (string) $r['last_hit'],
			'origin'   => (string) $r['origin'],
			'created'  => (string) $r['created'],
		];
	}

	/* ------------------------------------------------------------------ Rank Math store */

	/** Active Rank Math rows with an exact source equal to $path (case-insensitive, slashes ignored). */
	private static function rm_rows_by_source( string $path ): array {
		global $wpdb;
		if ( ! self::rankmath_active() ) {
			return [];
		}
		$t = self::rm_table();
		$want = Permalinks::key( $path );
		$like = '%' . $wpdb->esc_like( Permalinks::key( (string) basename( '/' . $path ) ) ) . '%';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code, hits, status FROM {$t} WHERE status = 'active' AND LOWER(sources) LIKE %s ORDER BY id ASC LIMIT 500", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out = [];
		foreach ( $rows as $r ) {
			foreach ( (array) maybe_unserialize( $r['sources'] ) as $s ) {
				if ( is_array( $s ) && 'exact' === ( $s['comparison'] ?? '' ) && Permalinks::key( Permalinks::clean_path( wp_specialchars_decode( (string) ( $s['pattern'] ?? '' ) ) ) ) === $want ) {
					$out[] = $r;
					break;
				}
			}
		}
		return $out;
	}

	/** Active Rank Math rows whose destination is the path $path on this site. */
	private static function rm_rows_by_target( string $path ): array {
		global $wpdb;
		if ( ! self::rankmath_active() || '' === $path ) {
			return [];
		}
		$t = self::rm_table();
		$want = Permalinks::key( $path );
		$last = (string) basename( '/' . $path );
		$like = '%' . $wpdb->esc_like( Permalinks::key( $last ) ) . '%';
		$like_enc = '%' . $wpdb->esc_like( strtolower( rawurlencode( $last ) ) ) . '%'; // url_to may hold the encoded form.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code, hits, status FROM {$t} WHERE status = 'active' AND (LOWER(url_to) LIKE %s OR LOWER(url_to) LIKE %s) ORDER BY id ASC LIMIT 500", $like, $like_enc ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_values( array_filter( $rows, static function ( $r ) use ( $want ) {
			$p = Permalinks::local_path( (string) $r['url_to'] );
			return null !== $p && Permalinks::key( $p ) === $want;
		} ) );
	}

	private static function rm_set_target( int $id, string $url ): bool {
		try {
			$r = \RankMath\Redirections\Redirection::create( $id );
			if ( ! $r->get_id() ) {
				return false;
			}
			$r->set_nocache( true );
			$r->add_destination( $url );
			return (bool) $r->save();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The sources of a raw Rank Math row, decoded as Rank Math's own reader does. */
	private static function rm_sources( array $row ): array {
		$out = [];
		foreach ( (array) maybe_unserialize( $row['sources'] ) as $s ) {
			if ( is_array( $s ) && isset( $s['pattern'] ) ) {
				$s['pattern'] = wp_specialchars_decode( (string) $s['pattern'] );
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Remove one exact source from a Rank Math row; a row left without sources goes to Rank Math's trash. */
	private static function rm_remove_source( array $row, string $path ): string {
		$want = Permalinks::key( $path );
		$keep = [];
		foreach ( self::rm_sources( $row ) as $s ) {
			$same = 'exact' === ( $s['comparison'] ?? '' ) && Permalinks::key( Permalinks::clean_path( (string) $s['pattern'] ) ) === $want;
			if ( ! $same ) {
				$keep[] = $s;
			}
		}
		try {
			if ( ! $keep ) {
				\RankMath\Redirections\DB::change_status( [ (int) $row['id'] ], 'trashed' );
				return 'trashed';
			}
			$r = new \RankMath\Redirections\Redirection( [ 'id' => (int) $row['id'], 'sources' => [], 'url_to' => (string) $row['url_to'], 'header_code' => (int) $row['header_code'], 'status' => 'active' ], true );
			$r->add_sources( $keep );
			$r->add_destination( (string) $row['url_to'] );
			$r->save();
			return 'source_removed';
		} catch ( \Throwable $e ) {
			return 'failed';
		}
	}

	private static function rm_add( string $src, array $t, int $code, array $out ) {
		$url = 410 === $code ? '' : self::target_url( $t );
		$collapsed = 0;
		if ( '' !== $url ) {
			foreach ( self::rm_rows_by_target( $src ) as $r ) { // Chains: whatever pointed at the source now points at the target.
				$collapsed += self::rm_set_target( (int) $r['id'], $url ) ? 1 : 0;
			}
		}
		$existing = self::rm_rows_by_source( $src );
		try {
			if ( $existing ) { // Same source already redirected (e.g. by Rank Math's own slug watcher): re-point that row.
				$r = \RankMath\Redirections\Redirection::from( [ 'id' => (int) $existing[0]['id'], 'sources' => self::rm_sources( $existing[0] ), 'url_to' => $url, 'header_code' => $code, 'status' => 'active' ] );
				$r->set_nocache( true );
				$id = (int) $r->save();
				$created = false;
			} else {
				$r = new \RankMath\Redirections\Redirection( [ 'id' => 0, 'sources' => [], 'url_to' => '', 'header_code' => $code, 'hits' => 0, 'status' => 'active' ], true );
				$r->add_source( '/' . $src, 'exact', 'case' );
				$r->add_destination( $url );
				$id = (int) $r->save();
				$created = true;
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'lb_redirect_rankmath', 'Rank Math refused the redirect: ' . $e->getMessage(), [ 'status' => 500 ] );
		}
		if ( ! $id ) {
			return new \WP_Error( 'lb_redirect_rankmath', 'Rank Math did not save the redirect.', [ 'status' => 500 ] );
		}
		return $out + [ 'id' => $id, 'created' => $created, 'collapsed' => $collapsed ];
	}

	/** A Rank Math row in tool form. */
	private static function rm_row( array $r ): array {
		$from = [];
		foreach ( (array) maybe_unserialize( $r['sources'] ) as $s ) {
			if ( is_array( $s ) ) {
				$from[] = ( 'exact' === ( $s['comparison'] ?? '' ) ? '/' : '' ) . wp_specialchars_decode( (string) ( $s['pattern'] ?? '' ) ) . ( 'exact' === ( $s['comparison'] ?? '' ) ? '' : ' (' . ( $s['comparison'] ?? '' ) . ')' );
			}
		}
		return [
			'id'       => (int) $r['id'],
			'store'    => 'rankmath',
			'from'     => implode( ', ', $from ),
			'to'       => (string) $r['url_to'],
			'url'      => (string) $r['url_to'],
			'code'     => (int) $r['header_code'],
			'hits'     => (int) $r['hits'],
			'last_hit' => (string) ( $r['last_accessed'] ?? '' ),
			'status'   => (string) $r['status'],
		];
	}

	/* ------------------------------------------------------------------ URL changes (called by Permalinks) */

	/**
	 * An object's URL moved from $old to $new (both site paths without slashes): keep the old one working.
	 *
	 * @return array report { store, redirect?, collapsed, removed[], shadowed[] }
	 */
	public static function uri_changed( string $otype, int $oid, string $old, string $new ): array {
		$rep = [ 'store' => self::store(), 'collapsed' => 0, 'removed' => [], 'shadowed' => [] ];
		if ( '' === $old || Permalinks::key( $old ) === Permalinks::key( $new ) ) {
			return $rep;
		}
		$rel = self::release( $otype, $oid, $new, [ $old ] );
		$rep['removed'] = $rel['removed'];
		$rep['shadowed'] = $rel['shadowed'];
		// Own store: an object target; redirects whose URL target was the old path follow the object from now on (add()
		// collapses them). Rank Math: URL to URL, and add() re-points rows that led to the old URL.
		$r = self::add( $old, [ 'object_type' => $otype, 'object_id' => $oid ], 301, 'auto', false, $rep['store'] );
		if ( is_wp_error( $r ) ) {
			$rep['error'] = $r->get_error_message();
		} else {
			$rep['redirect'] = [ 'id' => $r['id'], 'from' => $r['from'], 'to' => $r['url'] ];
			$rep['collapsed'] += (int) $r['collapsed'];
		}
		return $rep;
	}

	/**
	 * The object now lives at $uri: redirects FROM $uri that lead to this object (or to one of its earlier paths) are
	 * removed in both stores; any other redirect from $uri is reported as shadowed (it never fires in our store; in Rank
	 * Math's it would hide the page, so set-uri refuses it without force).
	 *
	 * @param string[] $own_paths earlier paths of the object (a redirect from $uri to one of them is a loop).
	 * @return array{removed:array,shadowed:array}
	 */
	public static function release( string $otype, int $oid, string $uri, array $own_paths = [], bool $force = false ): array {
		$out = [ 'removed' => [], 'shadowed' => [] ];
		if ( '' === $uri ) {
			return $out;
		}
		$mine = array_map( [ Permalinks::class, 'key' ], array_merge( $own_paths, [ $uri ] ) );
		$row = self::own_by_source( $uri );
		if ( $row ) {
			$t = self::row_target( $row );
			$same = ( 'object' === $t['type'] && $t['object_type'] === $otype && (int) $t['object_id'] === $oid ) || ( 'url' === $t['type'] && null !== $t['path'] && in_array( Permalinks::key( (string) $t['path'] ), $mine, true ) );
			if ( $same || $force ) {
				global $wpdb;
				$wpdb->delete( self::table(), [ 'id' => (int) $row['id'] ] ); // phpcs:ignore WordPress.DB
				$out['removed'][] = [ 'store' => 'own', 'id' => (int) $row['id'], 'from' => '/' . $row['source'] ];
			} else {
				$out['shadowed'][] = [ 'store' => 'own', 'id' => (int) $row['id'], 'from' => '/' . $row['source'], 'to' => self::describe( $t ) ];
			}
		}
		$cur = Permalinks::live_url( $otype, $oid );
		foreach ( self::rm_rows_by_source( $uri ) as $r ) {
			$p = Permalinks::local_path( (string) $r['url_to'] );
			$same = null !== $p && ( in_array( Permalinks::key( $p ), $mine, true ) || ( '' !== $cur && Permalinks::key( $p ) === Permalinks::key( (string) Permalinks::local_path( $cur ) ) ) );
			if ( $same || $force ) {
				$out['removed'][] = [ 'store' => 'rankmath', 'id' => (int) $r['id'], 'from' => '/' . $uri, 'result' => self::rm_remove_source( $r, $uri ) ];
			} else {
				$out['shadowed'][] = [ 'store' => 'rankmath', 'id' => (int) $r['id'], 'from' => '/' . $uri, 'to' => (string) $r['url_to'] ];
			}
		}
		return $out;
	}

	/** Redirects (any store) whose source is $uri and that do not lead to the object: what a new URL would shadow. */
	public static function conflicts( string $otype, int $oid, string $uri ): array {
		$out = [];
		$row = '' !== $uri ? self::own_by_source( $uri ) : null;
		if ( $row ) {
			$t = self::row_target( $row );
			if ( ! ( 'object' === $t['type'] && $t['object_type'] === $otype && (int) $t['object_id'] === $oid ) ) {
				$out[] = [ 'store' => 'own', 'id' => (int) $row['id'], 'from' => '/' . $row['source'], 'to' => self::describe( $t ) ];
			}
		}
		$cur = Permalinks::local_path( Permalinks::live_url( $otype, $oid ) );
		foreach ( '' !== $uri ? self::rm_rows_by_source( $uri ) : [] as $r ) {
			$p = Permalinks::local_path( (string) $r['url_to'] );
			if ( null === $p || null === $cur || Permalinks::key( $p ) !== Permalinks::key( $cur ) ) {
				$out[] = [ 'store' => 'rankmath', 'id' => (int) $r['id'], 'from' => '/' . $uri, 'to' => (string) $r['url_to'] ];
			}
		}
		return $out;
	}

	/** A post or term was deleted: our automatic redirects to it have nowhere to go. */
	public static function object_deleted( string $otype, int $oid ): void {
		global $wpdb;
		if ( self::ready() ) {
			$wpdb->delete( self::table(), [ 'object_type' => $otype, 'object_id' => $oid, 'origin' => 'auto' ] ); // phpcs:ignore WordPress.DB
		}
	}

	/* ------------------------------------------------------------------ front end */

	/**
	 * A redirect from this path in our table: send the visitor on (one indexed query, only for requests WordPress could
	 * not resolve: 404s, and paths no rewrite rule matched that WordPress fell back to the front page for, which happens
	 * when the server reports PHP_SELF as the requested path).
	 */
	public static function maybe_redirect(): void {
		global $wpdb, $wp;
		$unmatched = isset( $wp ) && is_object( $wp ) && '' !== (string) $wp->request && empty( $wp->matched_rule ) && ! Permalinks::matched();
		if ( ( ! is_404() && ! $unmatched ) || ! self::ready() || ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), [ 'GET', 'HEAD' ], true ) ) { // phpcs:ignore
			return;
		}
		$path = Permalinks::request_path();
		if ( null === $path || '' === $path ) {
			return;
		}
		$cands = [ $path => '' ];
		$pb = $GLOBALS['wp_rewrite']->pagination_base ?? 'page';
		if ( preg_match( '#^(.+)/' . preg_quote( $pb, '#' ) . '/(\d+)$#', $path, $m ) ) {
			$cands[ $m[1] ] = $pb . '/' . $m[2];
		}
		$hashes = array_map( [ Permalinks::class, 'hash' ], array_keys( $cands ) );
		$t = self::table();
		$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE source_hash IN ({$in})", $hashes ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return;
		}
		$by = [];
		foreach ( $rows as $r ) {
			$by[ $r['source_hash'] ] = $r;
		}
		foreach ( $cands as $cand => $suffix ) {
			$r = $by[ Permalinks::hash( $cand ) ] ?? null;
			if ( ! $r ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), (int) $r['id'] ) ); // phpcs:ignore WordPress.DB
			if ( 410 === (int) $r['code'] ) {
				global $wp_query;
				$wp_query->set_404(); // The 404 template, with status 410.
				status_header( 410 );
				nocache_headers();
				return;
			}
			$url = self::target_url( self::row_target( $r ) );
			if ( '' === $url ) {
				return;
			}
			if ( '' !== $suffix && null !== Permalinks::local_path( $url ) ) {
				$url = Permalinks::build_url( trim( (string) Permalinks::local_path( $url ) . '/' . $suffix, '/' ) );
			}
			$qs = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore
			if ( '' !== $qs && false === strpos( $url, '?' ) ) {
				$url .= '?' . $qs;
			}
			$internal = null !== Permalinks::local_path( $url );
			$internal ? wp_safe_redirect( $url, (int) $r['code'], 'Launch Builder' ) : wp_redirect( $url, (int) $r['code'], 'Launch Builder' ); // phpcs:ignore WordPress.Security.SafeRedirect -- external targets are set by administrators.
			exit;
		}
	}

	/* ------------------------------------------------------------------ listing / deleting */

	/**
	 * @param array $a { store: active|own|rankmath, search, offset, limit <= 200 }
	 */
	public static function list( array $a = [] ): array {
		global $wpdb;
		$store = (string) ( $a['store'] ?? 'active' );
		$store = 'active' === $store || ! in_array( $store, [ 'own', 'rankmath' ], true ) ? self::store() : $store;
		$limit = max( 1, min( 200, (int) ( $a['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
		$search = trim( (string) ( $a['search'] ?? '' ) );
		if ( 'rankmath' === $store ) {
			if ( ! self::rankmath_active() ) {
				return [ 'store' => 'rankmath', 'total' => 0, 'offset' => $offset, 'redirects' => [], 'note' => 'Rank Math Redirections is not active.' ];
			}
			$t = self::rm_table();
			$where = "status != 'trashed'";
			$args = [];
			if ( '' !== $search ) {
				$where .= ' AND (sources LIKE %s OR url_to LIKE %s)';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $limit, $offset ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			return [ 'store' => 'rankmath', 'total' => $total, 'offset' => $offset, 'redirects' => array_map( [ __CLASS__, 'rm_row' ], $rows ) ];
		}
		if ( ! self::ready() ) {
			return [ 'store' => 'own', 'total' => 0, 'offset' => $offset, 'redirects' => [], 'note' => 'Not installed yet.' ];
		}
		$t = self::table();
		$where = '1=1';
		$args = [];
		if ( '' !== $search ) {
			$where = '(source LIKE %s OR target LIKE %s)';
			$args[] = '%' . $wpdb->esc_like( Permalinks::clean_path( $search ) ?: $search ) . '%';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $limit, $offset ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return [ 'store' => 'own', 'total' => $total, 'offset' => $offset, 'redirects' => array_map( [ __CLASS__, 'own_row' ], $rows ) ];
	}

	/**
	 * Delete one redirect: our row is deleted (returned, so it can be re-created), a Rank Math row goes to Rank Math's
	 * trash (restorable there for 30 days).
	 *
	 * @param array $a { id, store, from }
	 */
	public static function delete( array $a ) {
		global $wpdb;
		$store = (string) ( $a['store'] ?? '' );
		$id = (int) ( $a['id'] ?? 0 );
		$from = isset( $a['from'] ) ? Permalinks::local_path( (string) $a['from'] ) : null;
		if ( ! $id && ( null === $from || '' === $from ) ) {
			return new \WP_Error( 'lb_redirect_delete', 'Give id (with store) or from.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $store, [ 'own', 'rankmath' ], true ) ) {
			$store = $id ? self::store() : '';
		}
		$deleted = [];
		if ( ( '' === $store || 'own' === $store ) && self::ready() ) {
			$t = self::table();
			$row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) : self::own_by_source( (string) $from ); // phpcs:ignore WordPress.DB
			if ( $row ) {
				$wpdb->delete( $t, [ 'id' => (int) $row['id'] ] ); // phpcs:ignore WordPress.DB
				$deleted[] = self::own_row( $row ) + [ 'result' => 'deleted' ];
			}
		}
		if ( ( '' === $store || 'rankmath' === $store ) && self::rankmath_active() ) {
			if ( $id && 'rankmath' === $store ) {
				$t = self::rm_table();
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $row ) {
					\RankMath\Redirections\DB::change_status( [ $id ], 'trashed' );
					$deleted[] = self::rm_row( $row ) + [ 'result' => 'trashed' ];
				}
			} elseif ( null !== $from && '' !== $from ) {
				foreach ( self::rm_rows_by_source( $from ) as $row ) {
					$deleted[] = self::rm_row( $row ) + [ 'result' => self::rm_remove_source( $row, $from ) ];
				}
			}
		}
		if ( ! $deleted ) {
			return new \WP_Error( 'lb_redirect_missing', 'No such redirect.', [ 'status' => 404 ] );
		}
		return [ 'deleted' => $deleted ];
	}

	/* ------------------------------------------------------------------ audit */

	/** Redirects whose source is a live URL, chains and loops (own table; Rank Math exact sources too). */
	public static function audit( array $args ): array {
		global $wpdb;
		unset( $args );
		$out = [];
		if ( self::ready() ) {
			$t = self::table();
			$u = Permalinks::table();
			foreach ( (array) $wpdb->get_results( "SELECT r.id, r.source, u.object_type, u.object_id FROM {$t} r INNER JOIN {$u} u ON u.uri_hash = r.source_hash LIMIT 100", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$out[] = [ 'severity' => 'warning', 'code' => 'redirect_shadowed', 'object_type' => 'redirect', 'id' => (int) $r['id'], 'url' => home_url( '/' . $r['source'] ), 'message' => 'The redirect from /' . $r['source'] . ' never fires: ' . $r['object_type'] . ':' . $r['object_id'] . ' lives at that URL.', 'fix' => 'delete-redirect { id: ' . (int) $r['id'] . ', store: "own" }' ];
			}
			foreach ( (array) $wpdb->get_results( "SELECT a.id, a.source, b.source AS next FROM {$t} a INNER JOIN {$t} b ON b.source_hash = a.target_hash WHERE a.target_hash != '' LIMIT 100", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$loop = Permalinks::key( (string) $r['source'] ) === Permalinks::key( (string) $r['next'] );
				$out[] = [ 'severity' => $loop ? 'error' : 'warning', 'code' => $loop ? 'redirect_loop' : 'redirect_chain', 'object_type' => 'redirect', 'id' => (int) $r['id'], 'url' => home_url( '/' . $r['source'] ), 'message' => '/' . $r['source'] . ' redirects to /' . $r['next'] . ', which redirects again.', 'fix' => 'set-redirect { from: "/' . $r['source'] . '", to: <final target> } collapses it' ];
			}
		}
		if ( self::rankmath_active() ) {
			$t = self::rm_table();
			$rows = (array) $wpdb->get_results( "SELECT id, sources, url_to FROM {$t} WHERE status = 'active' ORDER BY id DESC LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB
			$sources = [];
			foreach ( $rows as $r ) {
				foreach ( (array) maybe_unserialize( $r['sources'] ) as $s ) {
					if ( is_array( $s ) && 'exact' === ( $s['comparison'] ?? '' ) ) {
						$sources[ Permalinks::hash( Permalinks::clean_path( wp_specialchars_decode( (string) $s['pattern'] ) ) ) ] = [ (int) $r['id'], Permalinks::clean_path( wp_specialchars_decode( (string) $s['pattern'] ) ) ];
					}
				}
			}
			$live = Permalinks::hashes_present( array_keys( $sources ) );
			foreach ( $live as $h ) {
				[ $rid, $src ] = $sources[ $h ];
				$out[] = [ 'severity' => 'error', 'code' => 'redirect_shadowed', 'object_type' => 'redirect', 'id' => $rid, 'url' => home_url( '/' . $src ), 'message' => 'Rank Math redirects /' . $src . ' away although a page lives there (visitors never see the page).', 'fix' => 'delete-redirect { from: "/' . $src . '" } or give the page another URL' ];
			}
			foreach ( $rows as $r ) {
				$p = Permalinks::local_path( (string) $r['url_to'] );
				if ( null !== $p && '' !== $p && isset( $sources[ Permalinks::hash( $p ) ] ) ) {
					$out[] = [ 'severity' => $sources[ Permalinks::hash( $p ) ][0] === (int) $r['id'] ? 'error' : 'warning', 'code' => $sources[ Permalinks::hash( $p ) ][0] === (int) $r['id'] ? 'redirect_loop' : 'redirect_chain', 'object_type' => 'redirect', 'id' => (int) $r['id'], 'url' => (string) $r['url_to'], 'message' => 'Rank Math redirect ' . (int) $r['id'] . ' leads to /' . $p . ', which is redirected again.', 'fix' => 'point it at the final URL (set-redirect collapses chains)' ];
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		Abilities::add( 'get-redirects', 'List redirects', 'Redirects of the active store (Rank Math Redirections when its module is on, else Launch Builder\'s own table with hit counts) or of store own|rankmath. Paginated (offset, limit <= 200), search matches source and target.',
			[ 'properties' => [ 'store' => [ 'type' => 'string', 'enum' => [ 'active', 'own', 'rankmath' ] ], 'search' => [ 'type' => 'string' ], 'offset' => [ 'type' => 'integer' ], 'limit' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'tool_get' ], 'edit_pages', $ro );
		Abilities::add( 'set-redirect', 'Add or change a redirect', 'Redirect a path on this site to post:ID, term:ID, another path or an external URL (code 301 default; 302, 307, 308, or 410 gone without a target). Written to the active store. Chains are collapsed (A->B->C becomes A->C, and redirects that pointed at from follow it) and loops are refused; a from where a page lives is refused unless force: true. A target that is a page or entry is stored as that object, so it follows the object when its URL changes. dry_run shows the result without writing.',
			[ 'properties' => [ 'from' => [ 'type' => 'string' ], 'to' => [ 'type' => 'string' ], 'code' => [ 'type' => 'integer', 'enum' => self::CODES ], 'store' => [ 'type' => 'string', 'enum' => [ 'own', 'rankmath' ] ], 'force' => [ 'type' => 'boolean' ], 'dry_run' => [ 'type' => 'boolean' ] ], 'required' => [ 'from' ] ], [ __CLASS__, 'tool_set' ] );
		Abilities::add( 'delete-redirect', 'Delete a redirect', 'Delete a redirect by id (+ store) or by its source path (from: every store). Our rows are deleted and returned (so they can be re-created); Rank Math rows go to Rank Math\'s trash.',
			[ 'properties' => [ 'id' => [ 'type' => 'integer' ], 'store' => [ 'type' => 'string', 'enum' => [ 'own', 'rankmath' ] ], 'from' => [ 'type' => 'string' ] ] ], [ __CLASS__, 'tool_delete' ] );
	}

	public static function tool_get( $input ) {
		return self::list( (array) $input );
	}

	public static function tool_set( $input ) {
		$in = (array) $input;
		$code = isset( $in['code'] ) ? (int) $in['code'] : 301;
		$r = self::add( (string) ( $in['from'] ?? '' ), (string) ( $in['to'] ?? '' ), $code, 'manual', ! empty( $in['dry_run'] ), (string) ( $in['store'] ?? '' ), ! empty( $in['force'] ) );
		if ( ! is_wp_error( $r ) && empty( $r['dry_run'] ) ) {
			Cache::purge_later();
		}
		return $r;
	}

	public static function tool_delete( $input ) {
		return self::delete( (array) $input );
	}
}
