<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Site audit, sync and their registries.
 *
 * Checks are registered by the module that owns the data (Chrome and Roles checks by Chrome, token
 * checks by Tokens...): Audit::register_check( code, scope, callable, batched ) or the module hook
 * audit_checks(): [ code => [ scope, callable, batched? ] ]. Precedence for one code: the built-in generic checks
 * below < module audit_checks() < register_check(), so an owner can replace a built-in check by registering its code.
 *
 * A check is callable( array $args ): findings[] (or { findings, next_after_id }), each finding
 *   { severity: error|warning|info, code, object_type (post|option|navigation|menu|site|check), id, url, title,
 *     message, fix, ...extra }
 * Site-wide checks run once, on the first page of a run (after_id 0). Batched checks run on every page and look only
 * at posts in the page's ID window: args after_id (exclusive) and until_id (inclusive; PHP_INT_MAX on the last page),
 * plus limit. Audit::window_posts( $args, $types, $statuses ) returns those posts. The runner picks the window: the
 * next `limit` posts (by ID) of the audited post types (Audit::post_types(), filter launch_builder_audit_post_types),
 * so every batched check sees each post exactly once over a run. A batched check that cannot finish its window may
 * return { findings, next_after_id: X } (X inside the window): the page then ends at X for every check (findings
 * about later posts are dropped and reported by the next page). Findings of batched checks about posts (object_type
 * 'post' or a post type name, numeric id) after the window are dropped the same way, so checks written for the plain
 * after_id/limit contract (each querying its own `limit` posts) stay correct and report each post once.
 *
 * run( { scope[], codes[], after_id, limit <= 100, max_findings <= 200, only_failures } ) =>
 *   { findings, next_after_id (null = done), totals {error, warning, info}, by_code, checks, codes_run, window,
 *     truncated, dropped }
 * totals and by_code count every finding of the page, also those past max_findings or hidden by only_failures.
 * run_cached() also merges the page into the option launch_builder_audit_results (runs without a codes filter; a run
 * starts at after_id 0 and continues while after_id equals the stored next_after_id), which Site health shows.
 *
 * Sync: sync-site { fixes[], dry_run = true } runs registered fixes under Lock 'sync'. A fix is
 * callable( bool $dry_run, array $args ): { changed, items, ... } | WP_Error, registered with
 * Audit::register_fix( id, callable, [ label, description, cap, default ] ) or the module hook
 * sync_fixes(): [ id => [ callable, opts ] ]. Built in: migrations (pending data migrations), menus (Nav::flush()).
 */
final class Audit {

	const OPT_RESULTS = 'launch_builder_audit_results';

	/** Findings kept in the cached results (Site health). */
	const CACHE_FINDINGS = 500;

	/** Statuses whose content counts as live for the content checks. */
	const LIVE = [ 'publish', 'future', 'private' ];

	/** Tokens resolved outside the token engine (per-entry templates of launch/entries, Entries::render_custom). */
	const RESOLVED_ELSEWHERE = [ 'entry.title', 'entry.summary', 'entry.image', 'entry.url' ];

	/** Demo copy besides Business Info's demo values. */
	const DEMO_EXTRA = [ 'Lorem ipsum', '887+ Combined Reviews', '555-0123', 'yourbusiness.com', 'Your City, ST' ];

	/** Business Info fields a live site must not leave at the demo value (the name reads the site title, so it shows here only while that is a WordPress placeholder). */
	const ESSENTIAL = [ 'name', 'phone', 'email', 'address', 'city', 'hours' ];

	/** Slot fields that hold copy (default_copy_left). */
	const COPY_FIELDS = [ 'text', 'title', 'quote', 'label', 'learn_more' ];

	/** @var array<string,array{scope:string,callable:callable,batched:bool}> */
	private static $checks = [];

	/** @var array<string,array> fix id => definition */
	private static $fixes = [];

	/** @var array<string,array> "after:until" => window rows (ID, post_type, post_status) */
	private static $win = [];

	/** @var array<int,array> post id => parsed blocks (per request) */
	private static $blocks = [];

	/** @var array per-request caches */
	private static $memo = [];

	/* ------------------------------------------------------------------ registry */

	/**
	 * Register a check under a code (unique; re-registering replaces it) and a scope (content, company, menus, chrome,
	 * seo, render, ...). $batched: the check pages through posts with the window args (see the class comment).
	 */
	public static function register_check( string $code, string $scope, callable $callable, bool $batched = false ): void {
		self::$checks[ sanitize_key( $code ) ] = [ 'scope' => sanitize_key( $scope ), 'callable' => $callable, 'batched' => $batched ];
	}

	/** @return array<string,array{scope:string,callable:callable,batched:bool}> every check, built-ins first (see precedence). */
	public static function checks(): array {
		$all = self::builtin_checks();
		foreach ( Plugin::modules( 'audit_checks' ) as $module ) {
			foreach ( (array) $module::audit_checks() as $code => $def ) {
				$def = array_values( (array) $def );
				if ( isset( $def[1] ) && is_callable( $def[1] ) ) {
					$all[ sanitize_key( (string) $code ) ] = [ 'scope' => sanitize_key( (string) $def[0] ), 'callable' => $def[1], 'batched' => ! empty( $def[2] ) ];
				}
			}
		}
		foreach ( self::$checks as $code => $c ) {
			$all[ $code ] = $c;
		}
		return $all;
	}

	/** The generic checks this module ships (owners replace them by registering the same code). */
	private static function builtin_checks(): array {
		$c = static function ( string $scope, string $method, bool $batched = false ): array {
			return [ 'scope' => $scope, 'callable' => [ __CLASS__, $method ], 'batched' => $batched ];
		};
		return [
			'token_unresolved'        => $c( 'content', 'check_tokens', true ),
			'placeholder_left'        => $c( 'content', 'check_placeholders', true ),
			'demo_left'               => $c( 'content', 'check_demo', true ),
			'default_copy_left'       => $c( 'content', 'check_default_copy', true ),
			'placeholder_image'       => $c( 'content', 'check_placeholder_images', true ),
			'demo_claims_left'        => $c( 'content', 'check_demo_claims', true ),
			'form_embed_placeholder'  => $c( 'company', 'check_form_embed' ),
			'brand_missing'           => $c( 'company', 'check_brand' ),
			'wordpress_sample_content' => $c( 'content', 'check_wordpress_samples' ),
			'duplicate_uniqueid'      => $c( 'content', 'check_uniqueids', true ),
			'company_default'         => $c( 'company', 'check_company' ),
			'menu_dead_link'          => $c( 'menus', 'check_menus' ),
			'elements_module_off'     => $c( 'chrome', 'check_elements_module' ),
			'seo_rm_types_missing'    => $c( 'seo', 'check_rm_types' ),
			'seo_elements_in_sitemap' => $c( 'seo', 'check_rm_elements' ),
			'one_h1'                  => $c( 'render', 'check_one_h1', true ),
		];
	}

	/** Post types the runner pages through (the window). Filter launch_builder_audit_post_types. */
	public static function post_types(): array {
		if ( isset( self::$memo['types'] ) ) {
			return self::$memo['types'];
		}
		$types = array_values( array_diff( get_post_types( [ 'show_ui' => true ] ), [ 'attachment' ] ) );
		foreach ( [ 'wp_block', 'kadence_element', 'kadence_header', 'kadence_navigation', 'kadence_form', 'kadence_conversions' ] as $t ) {
			if ( post_type_exists( $t ) && ! in_array( $t, $types, true ) ) {
				$types[] = $t;
			}
		}
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'launch_builder_audit_post_types', $types ) ) ) );
		self::$memo['types'] = $types ? $types : [ 'page' ];
		return self::$memo['types'];
	}

	/**
	 * The window after $after: up to $limit audited posts by ID.
	 *
	 * @return array{0:int,1:?int,2:int[]} until_id (PHP_INT_MAX = last page), next_after_id, ids
	 */
	public static function window( int $after, int $limit ): array {
		global $wpdb;
		$types = self::post_types();
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($ph) AND post_status NOT IN ('auto-draft','inherit','trash') ORDER BY ID ASC LIMIT %d", array_merge( [ $after ], $types, [ $limit + 1 ] ) ) ); // phpcs:ignore
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$until = $more && $rows ? (int) end( $rows )->ID : PHP_INT_MAX;
		self::$win[ $after . ':' . $until ] = $rows;
		return [ $until, $more ? $until : null, array_map( static function ( $r ) { return (int) $r->ID; }, $rows ) ];
	}

	/** How many posts a full run pages through (for progress bars). */
	public static function count_posts(): int {
		global $wpdb;
		$types = self::post_types();
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($ph) AND post_status NOT IN ('auto-draft','inherit','trash')", $types ) ); // phpcs:ignore
	}

	/**
	 * Posts of the current window (args after_id / until_id) with these types (default all audited) and statuses.
	 *
	 * @return \WP_Post[]
	 */
	public static function window_posts( array $args, array $types = [], array $statuses = self::LIVE ): array {
		global $wpdb;
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$until = (int) ( $args['until_id'] ?? PHP_INT_MAX );
		$key = $after . ':' . $until;
		if ( ! isset( self::$win[ $key ] ) ) {
			$all = self::post_types();
			$ph = implode( ',', array_fill( 0, count( $all ), '%s' ) );
			self::$win[ $key ] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ($ph) AND post_status NOT IN ('auto-draft','inherit','trash') ORDER BY ID ASC LIMIT 1000", array_merge( [ $after, $until ], $all ) ) ); // phpcs:ignore
		}
		$ids = [];
		foreach ( self::$win[ $key ] as $r ) {
			if ( (int) $r->ID > $until || ( $types && ! in_array( $r->post_type, $types, true ) ) || ( $statuses && ! in_array( $r->post_status, $statuses, true ) ) ) {
				continue;
			}
			$ids[] = (int) $r->ID;
		}
		if ( ! $ids ) {
			return [];
		}
		_prime_post_caches( $ids, false, false );
		return array_values( array_filter( array_map( 'get_post', $ids ) ) );
	}

	/* ------------------------------------------------------------------ runner */

	/**
	 * Run one page of the audit (see the class comment for args and result).
	 */
	public static function run( array $args = [] ): array {
		$scopes = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $args['scope'] ?? [] ) ) ) ) );
		$codes = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $args['codes'] ?? [] ) ) ) ) );
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$max = max( 1, min( 200, (int) ( $args['max_findings'] ?? 200 ) ) );
		$only = ! empty( $args['only_failures'] );
		[ $until, $next, $ids ] = self::window( $after, $limit );
		$args = array_merge( $args, [ 'after_id' => $after, 'limit' => $limit, 'until_id' => $until ] );
		$collected = [];
		$ran = [];
		$batched_ran = false;
		foreach ( self::checks() as $code => $c ) {
			$batched = ! empty( $c['batched'] );
			if ( ( $scopes && ! in_array( $c['scope'], $scopes, true ) ) || ( $codes && ! in_array( $code, $codes, true ) ) || ( ! $batched && $after > 0 ) ) {
				continue; // Out of scope, or a site-wide check that already ran on the first page.
			}
			$ran[] = $code;
			$batched_ran = $batched_ran || $batched;
			try {
				$out = call_user_func( $c['callable'], $args );
			} catch ( \Throwable $e ) {
				$out = new \WP_Error( 'lb_check_failed', $e->getMessage() );
			}
			if ( is_wp_error( $out ) ) {
				$out = [ [ 'severity' => 'error', 'code' => 'audit_check_failed', 'object_type' => 'check', 'id' => $code, 'message' => 'The check ' . $code . ' failed: ' . $out->get_error_message() ] ];
			}
			if ( is_array( $out ) && array_key_exists( 'next_after_id', $out ) ) { // A batching check: { findings, next_after_id }.
				$stop = $out['next_after_id'];
				if ( $batched && null !== $stop && (int) $stop > $after && (int) $stop < $until ) {
					$until = (int) $stop; // It could not finish the window: the page ends there for every check.
					$args['until_id'] = $until;
				}
				$out = (array) ( $out['findings'] ?? [] );
			}
			foreach ( (array) $out as $f ) {
				$collected[] = [ $code, (array) $f, $batched ];
			}
		}
		$findings = [];
		$totals = [ 'error' => 0, 'warning' => 0, 'info' => 0 ];
		$by_code = [];
		$dropped = 0;
		foreach ( $collected as [ $code, $f, $batched ] ) {
			$f += [ 'severity' => 'warning', 'code' => $code ];
			if ( $batched && (int) ( $f['id'] ?? 0 ) > $until && self::about_post( $f ) ) {
				continue; // About a post after this page's window: a later page reports it.
			}
			$sev = in_array( $f['severity'], [ 'error', 'warning', 'info' ], true ) ? $f['severity'] : 'warning';
			$f['severity'] = $sev;
			$totals[ $sev ]++;
			$by_code[ (string) $f['code'] ] = ( $by_code[ (string) $f['code'] ] ?? 0 ) + 1;
			if ( $only && 'info' === $sev ) {
				continue;
			}
			if ( count( $findings ) >= $max ) {
				$dropped++;
				continue;
			}
			$findings[] = $f;
		}
		$next = $batched_ran && PHP_INT_MAX !== $until ? $until : null;
		return [
			'findings'      => $findings,
			'next_after_id' => $next,
			'totals'        => $totals,
			'by_code'       => $by_code,
			'checks'        => count( $ran ),
			'codes_run'     => $ran,
			'window'        => [ 'after_id' => $after, 'until_id' => PHP_INT_MAX === $until ? null : $until, 'posts' => count( array_filter( $ids, static function ( $id ) use ( $until ) { return $id <= $until; } ) ) ],
			'truncated'     => $dropped > 0,
			'dropped'       => $dropped,
		];
	}

	/**
	 * Is a finding about a post (so it belongs to the window holding that post's ID)? object_type 'post', none, or a
	 * post type name ('page', 'lb_service', 'attachment'...: several modules use the post type), with a numeric id.
	 */
	private static function about_post( array $f ): bool {
		if ( ! isset( $f['id'] ) || ! is_numeric( $f['id'] ) || (int) $f['id'] <= 0 ) {
			return false;
		}
		$t = (string) ( $f['object_type'] ?? 'post' );
		return '' === $t || 'post' === $t || post_type_exists( $t );
	}

	/** Run one page and merge it into the cached results (Site health). */
	public static function run_cached( array $args = [] ): array {
		$r = self::run( $args );
		if ( ! empty( $args['codes'] ) ) {
			$r['cached'] = false; // A spot check of named codes: the stored run (Site health) stays as it is.
			return $r;
		}
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$scope = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $args['scope'] ?? [] ) ) ) ) );
		sort( $scope );
		$key = md5( (string) wp_json_encode( [ $scope, ! empty( $args['only_failures'] ), (array) ( $args['codes'] ?? [] ) ] ) );
		$cache = get_option( self::OPT_RESULTS );
		if ( 0 === $after ) {
			$cache = [ 'key' => $key, 'scope' => $scope, 'started' => gmdate( 'c' ), 'user' => get_current_user_id(), 'pages' => 0, 'totals' => [ 'error' => 0, 'warning' => 0, 'info' => 0 ], 'by_code' => [], 'findings' => [], 'not_kept' => 0, 'next_after_id' => 0 ];
		} elseif ( ! is_array( $cache ) || ( $cache['key'] ?? '' ) !== $key || (int) ( $cache['next_after_id'] ?? -1 ) !== $after ) {
			$r['cached'] = false; // Not the continuation of the stored run: report it, keep the stored run as it is.
			return $r;
		}
		$cache['pages']++;
		foreach ( $r['totals'] as $sev => $n ) {
			$cache['totals'][ $sev ] = ( $cache['totals'][ $sev ] ?? 0 ) + $n;
		}
		foreach ( $r['by_code'] as $code => $n ) {
			$cache['by_code'][ $code ] = ( $cache['by_code'][ $code ] ?? 0 ) + $n;
		}
		$room = max( 0, self::CACHE_FINDINGS - count( $cache['findings'] ) );
		$cache['findings'] = array_merge( $cache['findings'], array_slice( $r['findings'], 0, $room ) );
		$cache['not_kept'] += max( 0, count( $r['findings'] ) - $room ) + $r['dropped'];
		$cache['next_after_id'] = $r['next_after_id'];
		$cache['complete'] = null === $r['next_after_id'];
		$cache['updated'] = gmdate( 'c' );
		update_option( self::OPT_RESULTS, $cache, false );
		$r['cached'] = true;
		return $r;
	}

	/** The cached results of the last run (empty array when there is none). */
	public static function results(): array {
		$c = get_option( self::OPT_RESULTS );
		return is_array( $c ) ? $c : [];
	}

	/* ------------------------------------------------------------------ helpers for checks */

	/** Parsed blocks of a post (once per request). */
	public static function blocks( \WP_Post $p ): array {
		if ( ! isset( self::$blocks[ $p->ID ] ) ) {
			self::$blocks[ $p->ID ] = false === strpos( $p->post_content, '<!-- wp:' ) ? [] : parse_blocks( $p->post_content );
		}
		return self::$blocks[ $p->ID ];
	}

	/** Call $cb( $block ) for every block in a tree. */
	public static function walk( array $blocks, callable $cb ): void {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$cb( $b );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], $cb );
			}
		}
	}

	/** A finding about a post. */
	public static function post_finding( \WP_Post $p, string $sev, string $code, string $msg, string $fix = '', array $extra = [] ): array {
		$title = get_the_title( $p );
		return [
			'severity'    => $sev,
			'code'        => $code,
			'object_type' => 'post',
			'post_type'   => $p->post_type,
			'id'          => (int) $p->ID,
			'title'       => '' !== $title ? $title : '(no title)',
			'url'         => 'publish' === $p->post_status && is_post_type_viewable( $p->post_type ) && ! in_array( $p->post_type, [ 'kadence_element', 'wp_block', 'kadence_header', 'kadence_navigation', 'kadence_form' ], true ) ? (string) get_permalink( $p ) : '',
			'message'     => $msg,
			'fix'         => $fix,
		] + $extra;
	}

	/** Text of a slot value, comparable: tags stripped, entities decoded, whitespace collapsed. */
	public static function norm_text( string $v ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** Demo copy that must not reach a live page: Business Info demo values and a few known demo strings. */
	public static function demo_strings(): array {
		if ( ! isset( self::$memo['demo'] ) ) {
			$out = self::DEMO_EXTRA;
			foreach ( Company::fields() as $k => $f ) {
				$d = trim( (string) ( $f[2] ?? '' ) );
				if ( in_array( $k, [ 'cta_label', 'cta2_label' ], true ) || strlen( $d ) < 6 || '#' === $d ) {
					continue; // Button labels such as "Get a Free Quote" are fine copy.
				}
				$out[] = $d;
			}
			self::$memo['demo'] = array_values( array_unique( $out ) );
		}
		return self::$memo['demo'];
	}

	/* ------------------------------------------------------------------ content checks (batched) */

	/** token => available, from the token catalog (Tokens::catalog). */
	private static function token_catalog(): array {
		if ( ! isset( self::$memo['tokens'] ) ) {
			$cat = [];
			foreach ( Tokens::catalog() as $r ) {
				$cat[ strtolower( $r['ns'] . '.' . $r['key'] ) ] = ! empty( $r['available'] );
			}
			self::$memo['tokens'] = $cat;
		}
		return self::$memo['tokens'];
	}

	/** Tokens in stored content: raw => 'ns.key' (null = malformed). Text, URL-encoded and hash-link forms. */
	public static function tokens_in( string $c ): array {
		$out = [];
		$add = static function ( string $raw, string $inner ) use ( &$out ) {
			$key = strtolower( trim( explode( '|', $inner, 2 )[0] ) );
			$out[ $raw ] = preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $key ) ? $key : null;
		};
		if ( false !== strpos( $c, '{{' ) && preg_match_all( '/\{\{([^{}]{1,100})\}\}/', $c, $m ) ) {
			foreach ( $m[1] as $i => $inner ) {
				$add( $m[0][ $i ], $inner );
			}
		}
		if ( false !== stripos( $c, '%7B%7B' ) && preg_match_all( '/%7B%7B(.{1,100}?)%7D%7D/i', $c, $m ) ) {
			foreach ( $m[1] as $i => $inner ) {
				$add( $m[0][ $i ], rawurldecode( $inner ) );
			}
		}
		if ( false !== strpos( $c, '#' ) && preg_match_all( '/["\']#(lb-[a-z0-9_]+|entry-url|page-url|page-parent_url|area-url|service-category_url|site-url)["\']/', $c, $m ) ) {
			foreach ( $m[1] as $h ) {
				$out[ '#' . $h ] = 0 === strpos( $h, 'lb-' ) ? 'lb.' . substr( $h, 3 ) : (string) preg_replace( '/^([a-z]+)-/', '$1.', $h );
			}
		}
		return $out;
	}

	public static function check_tokens( array $args ): array {
		$cat = self::token_catalog();
		$out = [];
		foreach ( self::window_posts( $args ) as $p ) {
			$bad = [];
			$planned = [];
			foreach ( self::tokens_in( (string) $p->post_content ) as $raw => $t ) {
				if ( null === $t ) {
					$bad[] = $raw;
				} elseif ( in_array( $t, self::RESOLVED_ELSEWHERE, true ) ) {
					continue;
				} elseif ( isset( $cat[ $t ] ) ) {
					if ( ! $cat[ $t ] ) {
						$planned[] = $raw;
					}
				} elseif ( 0 !== strpos( $t, 'meta.' ) ) { // meta.* are per-site Meta Box fields: not verifiable here.
					$bad[] = $raw;
				}
			}
			if ( $bad ) {
				$out[] = self::post_finding( $p, 'error', 'token_unresolved', 'Unknown tokens (they print raw or as nothing): ' . implode( ', ', array_slice( array_unique( $bad ), 0, 8 ) ), 'Use a token from the catalog (get-company lists the Business Info ones) or remove it.' );
			}
			if ( $planned ) {
				$out[] = self::post_finding( $p, 'warning', 'token_unresolved', 'Tokens this version does not resolve yet: ' . implode( ', ', array_slice( array_unique( $planned ), 0, 8 ) ), 'Replace them with available tokens until the token engine resolves them.' );
			}
		}
		return $out;
	}

	public static function check_placeholders( array $args ): array {
		$out = [];
		foreach ( self::window_posts( $args ) as $p ) {
			if ( false === strpos( $p->post_content, '[[' ) ) {
				continue;
			}
			$html = (string) preg_replace( '/<!--.*?-->/s', '', $p->post_content ); // Block comments hold JSON, which may nest arrays.
			if ( preg_match_all( '/\[\[(?=[^\]]*[A-Za-z])[^\[\]\n]{1,200}\]\]/', $html, $m ) ) {
				$list = array_values( array_unique( $m[0] ) );
				$out[] = self::post_finding( $p, 'error', 'placeholder_left', count( $m[0] ) . ' copy placeholder(s) left: ' . implode( ', ', array_slice( $list, 0, 5 ) ), 'Write the copy (apply-pattern slots or replace, add-text) or keep the page as a draft.', [ 'placeholders' => array_slice( $list, 0, 20 ) ] );
			}
		}
		return $out;
	}

	public static function check_demo( array $args ): array {
		$demo = self::demo_strings();
		$v = Company::values();
		$rated = '' !== trim( (string) ( $v['rating'] ?? '' ) ) || '' !== trim( (string) ( $v['review_count'] ?? '' ) );
		$out = [];
		foreach ( self::window_posts( $args ) as $p ) {
			$c = (string) $p->post_content;
			$hits = [];
			foreach ( $demo as $d ) {
				if ( false !== stripos( $c, $d ) ) {
					$hits[] = $d;
				}
			}
			$ratings = 0;
			if ( false !== strpos( $c, 'wp:launch/rating' ) ) {
				self::walk( self::blocks( $p ), static function ( $b ) use ( &$ratings, $rated ) {
					$a = (array) ( $b['attrs'] ?? [] );
					if ( 'launch/rating' === $b['blockName'] && ! array_key_exists( 'text', $a ) && ! array_key_exists( 'rating', $a ) && ( empty( $a['useGlobal'] ) || ! $rated ) ) {
						$ratings++;
					}
				} );
			}
			if ( $ratings ) {
				$hits[] = $ratings . ' rating widget(s) on the demo rating and review count';
			}
			if ( $hits ) {
				$out[] = self::post_finding( $p, 'warning', 'demo_left', 'Demo copy in the content: ' . implode( '; ', array_slice( $hits, 0, 6 ) ), 'Replace it with real copy or a Business Info token ({{lb.name}}, {{lb.phone}}...), and fill the review figures in Business Info.' );
			}
		}
		return $out;
	}

	/** md5( normalised default text ) => pattern slug(s), for every built-in pattern's copy slots (cached per pattern set). */
	public static function default_texts(): array {
		return self::pattern_defaults()['copy'];
	}

	/**
	 * md5( normalised text ) => pattern slug(s), for default texts that make a claim a visitor takes as fact: a number or a
	 * price ("Backed by 2,500+ five-star reviews", "$50 off"), and a count-up number with its label ("887" "Google reviews").
	 */
	public static function demo_claims(): array {
		return self::pattern_defaults()['claims'];
	}

	/** The default copy of every built-in pattern: { copy, claims }, each md5 => "slug, slug" (at most three slugs). */
	private static function pattern_defaults(): array {
		if ( isset( self::$memo['defaults'] ) ) {
			return self::$memo['defaults'];
		}
		$files = glob( LB_DIR . 'patterns/*.php' ) ?: [];
		$stamp = 0;
		foreach ( $files as $f ) {
			$stamp = max( $stamp, (int) filemtime( $f ) );
		}
		$key = 'lb_audit_defaults2_' . md5( LB_VERSION . '|' . $stamp . '|' . count( $files ) . '|' . implode( ',', (array) get_option( 'active_plugins', [] ) ) );
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) || ! isset( $hit['copy'], $hit['claims'] ) ) {
			$list = [ 'copy' => [], 'claims' => [] ];
			$add = static function ( string $kind, string $hash, string $slug ) use ( &$list ) {
				$have = isset( $list[ $kind ][ $hash ] ) ? explode( ', ', $list[ $kind ][ $hash ] ) : [];
				if ( ! in_array( $slug, $have, true ) && count( $have ) < 3 ) {
					$have[] = $slug;
				}
				$list[ $kind ][ $hash ] = implode( ', ', $have );
			};
			foreach ( Patterns::all() as $slug => $p ) {
				if ( ! empty( $p['custom'] ) ) {
					continue; // Per-site patterns are this site's own copy.
				}
				$slots = Patterns::slots( parse_blocks( (string) ( $p['content'] ?? '' ) ) );
				$by_path = [];
				foreach ( $slots as $s ) {
					$by_path[ strstr( $s['id'], ':', true ) ][ $s['field'] ] = (string) $s['value'];
				}
				foreach ( $slots as $s ) {
					$v = (string) $s['value'];
					if ( false !== strpos( $v, '{{' ) || false !== strpos( $v, '[[' ) ) {
						continue;
					}
					$n = self::norm_text( $v );
					if ( in_array( $s['field'], self::COPY_FIELDS, true ) ) {
						if ( strlen( $n ) >= 30 && str_word_count( $n ) >= 5 ) {
							$add( 'copy', md5( $n ), (string) $slug );
						}
						if ( strlen( $n ) >= 4 && preg_match( '/\d|\$/', $n ) ) {
							$add( 'claims', md5( $n ), (string) $slug );
						}
					} elseif ( 'number' === $s['field'] ) {
						$label = self::norm_text( (string) ( $by_path[ strstr( $s['id'], ':', true ) ]['label'] ?? '' ) );
						$add( 'claims', md5( $label . '|' . $n ), (string) $slug );
					}
				}
			}
			$hit = $list;
			set_transient( $key, $hit, DAY_IN_SECONDS );
		}
		self::$memo['defaults'] = $hit;
		return $hit;
	}

	public static function check_default_copy( array $args ): array {
		$posts = self::window_posts( $args );
		if ( ! $posts ) {
			return [];
		}
		$defaults = self::default_texts();
		$out = [];
		foreach ( $posts as $p ) {
			$hits = [];
			foreach ( Patterns::slots( self::blocks( $p ) ) as $s ) {
				if ( ! in_array( $s['field'], self::COPY_FIELDS, true ) ) {
					continue;
				}
				$n = self::norm_text( (string) $s['value'] );
				if ( strlen( $n ) >= 30 && isset( $defaults[ md5( $n ) ] ) ) {
					$hits[] = [ 'slot' => $s['id'], 'pattern' => $defaults[ md5( $n ) ], 'text' => mb_substr( $n, 0, 160 ) ];
				}
			}
			if ( $hits ) {
				$out[] = self::post_finding( $p, 'warning', 'default_copy_left', count( $hits ) . ' text slot(s) in ' . ( 'wp_block' === $p->post_type ? 'this saved design' : 'this content' ) . ' still carry a stock pattern\'s default copy, e.g. "' . mb_substr( $hits[0]['text'], 0, 70 ) . '" (same text in the stock pattern ' . $hits[0]['pattern'] . ').', 'Rewrite it for this business (apply-pattern slots or replace on the page, set-synced-pattern for shared designs).', [ 'slots' => array_slice( $hits, 0, 10 ) ] );
			}
		}
		return $out;
	}

	public static function check_placeholder_images( array $args ): array {
		$out = [];
		foreach ( self::window_posts( $args ) as $p ) {
			$n = substr_count( $p->post_content, 'assets/placeholders/' );
			if ( $n ) {
				$out[] = self::post_finding( $p, 'warning', 'placeholder_image', $n . ' placeholder image(s) left.', 'Import real photos (sideload-media) and fill the image slots.' );
			}
		}
		return $out;
	}

	/** Default text of a stock pattern that states a number or a price (a review count, "$50 off", a count-up figure) and is still on the page. */
	public static function check_demo_claims( array $args ): array {
		$claims = self::demo_claims();
		$out = [];
		foreach ( self::window_posts( $args, [], [ 'publish', 'future', 'private', 'draft' ] ) as $p ) {
			if ( false === strpos( (string) $p->post_content, '<!-- wp:' ) ) {
				continue;
			}
			$slots = Patterns::slots( self::blocks( $p ) );
			$by_path = [];
			foreach ( $slots as $s ) {
				$by_path[ strstr( $s['id'], ':', true ) ][ $s['field'] ] = (string) $s['value'];
			}
			$hits = [];
			foreach ( $slots as $s ) {
				$n = self::norm_text( (string) $s['value'] );
				if ( in_array( $s['field'], self::COPY_FIELDS, true ) && strlen( $n ) >= 4 && isset( $claims[ md5( $n ) ] ) ) {
					$hits[] = [ 'slot' => $s['id'], 'text' => mb_substr( $n, 0, 100 ), 'pattern' => $claims[ md5( $n ) ] ];
				} elseif ( 'number' === $s['field'] ) {
					$key = md5( self::norm_text( (string) ( $by_path[ strstr( $s['id'], ':', true ) ]['label'] ?? '' ) ) . '|' . $n );
					if ( isset( $claims[ $key ] ) ) {
						$hits[] = [ 'slot' => $s['id'], 'text' => $n . ' ' . self::norm_text( (string) ( $by_path[ strstr( $s['id'], ':', true ) ]['label'] ?? '' ) ), 'pattern' => $claims[ $key ] ];
					}
				}
			}
			if ( $hits ) {
				$live = in_array( $p->post_status, self::LIVE, true );
				$out[] = self::post_finding( $p, $live ? 'warning' : 'info', 'demo_claims_left', count( $hits ) . ' demo claim(s) a visitor would take as fact (a count, a rating or a price from the stock pattern), e.g. "' . $hits[0]['text'] . '".', 'Replace them with the business\'s real figures, or take the design off until it has real content (build-from-brief leaves the testimonial and offer designs out of the default stack until content.testimonials and content.offers exist).', [ 'slots' => array_slice( $hits, 0, 10 ) ] );
			}
		}
		return $out;
	}

	/** The form embed in Business Info is a stand-in (REPLACE_WITH_FORM_ID, an example host): the forms do not work. */
	public static function check_form_embed(): array {
		$html = (string) ( Company::values()['form_html'] ?? '' );
		if ( ! Launch::placeholder_embed( $html ) ) {
			return [];
		}
		return [ [ 'severity' => 'warning', 'code' => 'form_embed_placeholder', 'object_type' => 'option', 'id' => Company::OPT, 'title' => 'Business Info: form embed', 'url' => admin_url( 'admin.php?page=launch-builder-business' ), 'message' => 'The form embed is a stand-in (it still holds a placeholder such as REPLACE_WITH_FORM_ID), so the quote, contact and booking forms do not work.', 'fix' => 'Paste the real embed code: forms.quote.embed in the brief, or set-company values.form_html.' ] ];
	}

	/** No logo or no site icon. */
	public static function check_brand(): array {
		$out = [];
		$base = [ 'object_type' => 'option', 'url' => admin_url( 'customize.php?autofocus[section]=title_tagline' ), 'severity' => 'warning', 'code' => 'brand_missing' ];
		if ( ! (int) get_theme_mod( 'custom_logo' ) ) {
			$out[] = $base + [ 'id' => 'custom_logo', 'title' => 'Logo', 'message' => 'No logo is set, so the header shows the site title as text.', 'fix' => 'Import the logo (media entry with use "logo") or set it in Appearance, Customize, Site identity.' ];
		}
		if ( ! (int) get_option( 'site_icon' ) ) {
			$out[] = $base + [ 'id' => 'site_icon', 'title' => 'Site icon', 'message' => 'No site icon is set (the small picture in browser tabs and search results).', 'fix' => 'Import it (media entry with use "site_icon") or set it in Appearance, Customize, Site identity.' ];
		}
		return $out;
	}

	/** The sample page and the first post WordPress made, still published (and in the sitemap). */
	public static function check_wordpress_samples( array $args ): array {
		unset( $args );
		$out = [];
		foreach ( Launch::wordpress_defaults() as $p ) {
			if ( in_array( $p->post_status, self::LIVE, true ) ) {
				$out[] = self::post_finding( $p, 'warning', 'wordpress_sample_content', '"' . $p->post_title . '" is WordPress sample content, still published: visitors and search engines can find it.', 'Trash it (a build-from-brief does this on its first run).' );
			}
		}
		return $out;
	}

	/** Kadence uniqueID => attribute hashes, for one block tree. */
	private static function uids( array $blocks ): array {
		$out = [];
		self::walk( $blocks, static function ( $b ) use ( &$out ) {
			$a = (array) ( $b['attrs'] ?? [] );
			if ( 0 === strpos( (string) $b['blockName'], 'kadence/' ) && ! empty( $a['uniqueID'] ) && is_string( $a['uniqueID'] ) ) {
				$u = $a['uniqueID'];
				unset( $a['uniqueID'] );
				$out[ $u ][] = md5( (string) wp_json_encode( $a ) );
			}
		} );
		return $out;
	}

	/** uniqueID => [ {id, type, title} ] for the shared designs that print on many pages (elements, block headers, LB synced patterns). */
	private static function template_uids(): array {
		if ( isset( self::$memo['tpl_uids'] ) ) {
			return self::$memo['tpl_uids'];
		}
		$map = [];
		$ids = get_posts( [ 'post_type' => [ 'kadence_element', 'kadence_header', 'wp_block' ], 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] );
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || ( 'wp_block' === $p->post_type && '' === (string) get_post_meta( $id, '_lb_key', true ) ) ) {
				continue; // A user's own synced pattern prints only where it is inserted.
			}
			foreach ( array_keys( self::uids( self::blocks( $p ) ) ) as $u ) {
				$map[ $u ][] = [ 'id' => (int) $id, 'type' => $p->post_type, 'title' => get_the_title( $p ) ];
			}
		}
		self::$memo['tpl_uids'] = $map;
		return $map;
	}

	public static function check_uniqueids( array $args ): array {
		$out = [];
		$posts = self::window_posts( $args );
		if ( ! $posts ) {
			return [];
		}
		$tpl = self::template_uids();
		foreach ( $posts as $p ) {
			if ( false === strpos( $p->post_content, '"uniqueID"' ) ) {
				continue;
			}
			$uids = self::uids( self::blocks( $p ) );
			$diff = [];
			$same = [];
			foreach ( $uids as $u => $hashes ) {
				if ( count( $hashes ) > 1 ) {
					count( array_unique( $hashes ) ) > 1 ? $diff[] = $u : $same[] = $u;
				}
			}
			if ( $diff ) {
				$out[] = self::post_finding( $p, 'warning', 'duplicate_uniqueid', count( $diff ) . ' Kadence block ID(s) are used by blocks with different settings in this content (' . implode( ', ', array_slice( $diff, 0, 5 ) ) . '); Kadence prints the styles of the first one only.', 'Re-insert the duplicated section (the editor gives it new IDs) or rebuild it with apply-pattern.' );
			} elseif ( $same ) {
				$out[] = self::post_finding( $p, 'info', 'duplicate_uniqueid', count( $same ) . ' Kadence block ID(s) repeat in this content with identical settings (' . implode( ', ', array_slice( $same, 0, 5 ) ) . ').', 'Harmless while the copies stay identical; editing one changes how both look.' );
			}
			$shared = [];
			foreach ( array_keys( $uids ) as $u ) {
				foreach ( $tpl[ $u ] ?? [] as $t ) {
					if ( $t['id'] !== (int) $p->ID ) {
						$shared[ $u ] = $t;
						break;
					}
				}
			}
			if ( $shared ) {
				$first = reset( $shared );
				$out[] = self::post_finding( $p, 'warning', 'duplicate_uniqueid', count( $shared ) . ' Kadence block ID(s) are shared with ' . $first['type'] . ' #' . $first['id'] . ' "' . $first['title'] . '", a shared design that prints on many pages; where both print, one of them loses its styles.', 'Rebuild one of them so it gets its own IDs (Launch Builder re-keys IDs whenever it writes a design).', [ 'shared_with' => array_values( array_unique( array_column( $shared, 'id' ) ) ) ] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ site-wide checks */

	public static function check_company(): array {
		$res = Company::resolve();
		$fields = Company::fields();
		$out = [];
		$f = static function ( string $sev, string $key, string $msg, string $fix ) {
			return [ 'severity' => $sev, 'code' => 'company_default', 'object_type' => 'option', 'id' => Company::OPT, 'title' => 'Business Info: ' . $key, 'url' => admin_url( 'admin.php?page=launch-builder-business' ), 'message' => $msg, 'fix' => $fix, 'field' => $key ];
		};
		foreach ( $fields as $k => $def ) {
			if ( 'default' !== ( $res[ $k ]['from'] ?? '' ) || '' === trim( (string) $def[2] ) || '#' === trim( (string) $def[2] ) ) {
				continue;
			}
			$essential = in_array( $k, self::ESSENTIAL, true );
			if ( 'name' === $k ) { // Nothing is typed and the site title is empty or a WordPress placeholder: a public page prints no name at all.
				$public = ! Company::setup_mode();
				$out[] = $f( $public ? 'error' : 'warning', $k, 'The site title is empty or still a WordPress placeholder, so ' . ( $public ? 'pages print no business name.' : 'pages show the demo name "' . $def[2] . '".' ), 'Set the site title (Settings, General), or set-company values.name.' );
				continue;
			}
			$out[] = $f( $essential ? 'warning' : 'info', $k, $def[0] . ' is still the demo value "' . $def[2] . '"' . ( $essential ? ', and every page that shows it prints that.' : '.' ), 'set-company values.' . $k . ' (or Launch Builder > Business Info).' );
		}
		$socials = [];
		foreach ( [ 'facebook', 'instagram', 'google', 'youtube' ] as $k ) {
			if ( isset( $res[ $k ] ) && '#' === trim( (string) $res[ $k ]['value'] ) ) {
				$socials[] = $k;
			}
		}
		if ( $socials ) {
			$out[] = $f( 'info', 'socials', 'Social links still point at "#": ' . implode( ', ', $socials ) . '. Icons using them go nowhere.', 'set-company with the profile URLs, or remove those icons.' );
		}
		if ( '#' === trim( (string) ( $res['cta_url']['value'] ?? '' ) ) ) {
			$out[] = $f( 'warning', 'cta_url', 'The main button link has no target ("#"): set the quote page or the button link.', 'set-company values.quote_url (a page URL) or values.cta_url.' );
		}
		return $out;
	}

	public static function check_menus(): array {
		$out = [];
		$mk = static function ( string $sev, string $type, $id, string $menu, string $label, string $msg ) {
			return [ 'severity' => $sev, 'code' => 'menu_dead_link', 'object_type' => $type, 'id' => $id, 'title' => $menu . ': ' . ( '' !== $label ? $label : '(no label)' ), 'url' => 'navigation' === $type ? (string) get_edit_post_link( (int) $id, 'raw' ) : admin_url( 'nav-menus.php?menu=' . (int) $id ), 'message' => $msg, 'fix' => 'Point the item at a published page or entry (set-header nav items, or edit the menu), or remove it.' ];
		};
		if ( post_type_exists( 'kadence_navigation' ) ) {
			foreach ( get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'ID', 'order' => 'ASC' ] ) as $nav ) {
				$name = get_the_title( $nav ) ?: 'Navigation #' . $nav->ID;
				$check = static function ( array $blocks ) use ( &$check, &$out, $nav, $name, $mk ) {
					foreach ( $blocks as $b ) {
						if ( 'kadence/navigation-link' !== ( $b['blockName'] ?? '' ) ) {
							if ( ! empty( $b['innerBlocks'] ) ) {
								$check( $b['innerBlocks'] );
							}
							continue;
						}
						$a = (array) ( $b['attrs'] ?? [] );
						$label = wp_strip_all_tags( (string) ( $a['label'] ?? '' ) );
						$kids = array_filter( (array) ( $b['innerBlocks'] ?? [] ), static function ( $k ) { return 'kadence/navigation-link' === ( $k['blockName'] ?? '' ); } );
						if ( empty( $a['isMegaMenu'] ) ) {
							$url = trim( (string) ( $a['url'] ?? '' ) );
							if ( 'post-type' === ( $a['kind'] ?? '' ) && ! empty( $a['id'] ) ) {
								$st = get_post_status( (int) $a['id'] );
								if ( 'publish' !== $st ) {
									$out[] = $mk( 'warning', 'navigation', $nav->ID, $name, $label, '"' . $label . '" links to ' . ( $st ? 'a ' . $st . ' item' : 'a deleted item' ) . ' (#' . (int) $a['id'] . ').' );
								}
							} elseif ( '' === $url || '#' === $url ) {
								$out[] = $kids
									? $mk( 'info', 'navigation', $nav->ID, $name, $label, '"' . $label . '" opens a dropdown but links nowhere ("#"): a tap on a phone does nothing but open the dropdown.' )
									: $mk( 'warning', 'navigation', $nav->ID, $name, $label, '"' . $label . '" links nowhere ("#").' );
							}
							if ( count( $out ) >= 100 ) {
								return;
							}
						}
						if ( $kids ) {
							$check( $kids );
						}
					}
				};
				$check( parse_blocks( $nav->post_content ) );
			}
		}
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = (array) wp_get_nav_menu_items( $menu->term_id, [ 'post_status' => 'publish' ] );
			$parents = array_flip( array_map( static function ( $i ) { return (int) $i->menu_item_parent; }, $items ) );
			foreach ( $items as $it ) {
				$label = (string) $it->title;
				if ( ! empty( $it->_invalid ) ) {
					$out[] = $mk( 'warning', 'menu', $menu->term_id, $menu->name, $label, '"' . $label . '" points at something that no longer exists.' );
				} elseif ( 'post_type' === $it->type && 'publish' !== get_post_status( (int) $it->object_id ) ) {
					$out[] = $mk( 'warning', 'menu', $menu->term_id, $menu->name, $label, '"' . $label . '" links to an unpublished item (#' . (int) $it->object_id . ').' );
				} elseif ( 'custom' === $it->type && in_array( trim( (string) $it->url ), [ '', '#' ], true ) ) {
					$out[] = isset( $parents[ (int) $it->ID ] )
						? $mk( 'info', 'menu', $menu->term_id, $menu->name, $label, '"' . $label . '" opens a submenu but links nowhere ("#").' )
						: $mk( 'warning', 'menu', $menu->term_id, $menu->name, $label, '"' . $label . '" links nowhere ("#").' );
				}
			}
		}
		return array_slice( $out, 0, 100 );
	}

	public static function check_elements_module(): array {
		global $wpdb;
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lb_key' WHERE p.post_type = 'kadence_element' AND p.post_status = 'publish'" ); // phpcs:ignore
		$pref = (string) get_option( Chrome::OPT_MODE, 'auto' );
		$mode = Chrome::mode();
		if ( 'elements' === $mode || ( ! $n && 'elements' !== $pref ) ) {
			return [];
		}
		$why = Plugin::requirement_met( 'kadence-pro' ) ? 'the Elements module of Kadence Pro (Theme Kit Pro) is switched off' : 'Kadence Pro (Theme Kit Pro) is not active';
		$base = [ 'code' => 'elements_module_off', 'object_type' => 'site', 'id' => 'kadence_pro_theme_config', 'title' => 'Kadence Pro Elements', 'url' => admin_url( 'admin.php?page=kadence-pro' ) ];
		if ( $n ) {
			return [ $base + [ 'severity' => 'error', 'message' => $n . ' Launch Builder element(s) (heroes, templates, footers...) exist, but ' . $why . ', so they do not print.', 'fix' => 'Turn the Elements module on (set-pro-modules { elements: true }) or switch the shared designs to patterns mode.' ] ];
		}
		return [ $base + [ 'severity' => 'warning', 'message' => 'The shared designs are set to Kadence Elements, but ' . $why . '; patterns mode is used instead.', 'fix' => 'Turn the Elements module on, or set the chrome preference to patterns.' ] ];
	}

	/** Is Rank Math active? */
	private static function rank_math(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' );
	}

	private static function rm_sitemap_on(): bool {
		return in_array( 'sitemap', (array) get_option( 'rank_math_modules', [] ), true );
	}

	public static function check_rm_types(): array {
		if ( ! self::rank_math() ) {
			return [];
		}
		$titles = (array) get_option( 'rank-math-options-titles', [] );
		$sitemap = (array) get_option( 'rank-math-options-sitemap', [] );
		$out = [];
		foreach ( [ Entries::SERVICE => 'services', Entries::AREA => 'service areas' ] as $pt => $label ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			$miss = [];
			if ( ! isset( $titles[ 'pt_' . $pt . '_title' ] ) ) {
				$miss[] = 'title and description templates (and schema type)';
			}
			if ( self::rm_sitemap_on() && 'on' !== ( $sitemap[ 'pt_' . $pt . '_sitemap' ] ?? '' ) ) {
				$miss[] = 'sitemap inclusion';
			}
			if ( $miss ) {
				$out[] = [ 'severity' => 'warning', 'code' => 'seo_rm_types_missing', 'object_type' => 'option', 'id' => 'rank-math-options-titles', 'title' => 'Rank Math: ' . $label, 'url' => admin_url( 'admin.php?page=rank-math-options-titles' ), 'message' => 'Rank Math has no settings for ' . $label . ' (' . $pt . '): ' . implode( ' and ', $miss ) . ' missing, so they are left out of the sitemap and print no schema.', 'fix' => 'Let Launch Builder write the Rank Math settings for its post types (the compatibility sync), or save Rank Math > Titles & Meta and Sitemap Settings once.' ];
			}
		}
		return $out;
	}

	public static function check_rm_elements(): array {
		if ( ! self::rank_math() || ! self::rm_sitemap_on() ) {
			return [];
		}
		$sitemap = (array) get_option( 'rank-math-options-sitemap', [] );
		$out = [];
		foreach ( [ 'kadence_element', 'kadence_conversions' ] as $pt ) {
			$o = get_post_type_object( $pt );
			if ( ! $o || ! $o->public || 'on' !== ( $sitemap[ 'pt_' . $pt . '_sitemap' ] ?? '' ) || apply_filters( 'rank_math/sitemap/exclude_post_type', false, $pt ) ) {
				continue;
			}
			$out[] = [ 'severity' => 'warning', 'code' => 'seo_elements_in_sitemap', 'object_type' => 'option', 'id' => 'rank-math-options-sitemap', 'title' => 'Rank Math sitemap: ' . $pt, 'url' => admin_url( 'admin.php?page=rank-math-options-sitemap' ), 'message' => 'The Rank Math sitemap lists ' . $pt . ' posts: layout pieces, not pages (visitors are redirected away from them).', 'fix' => 'Exclude ' . $pt . ' from the sitemap (Rank Math > Sitemap Settings, or the compatibility sync).' ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ render check (batched) */

	/** post id => roles it is the sample of; and the roles without a sample post (role => url). */
	private static function role_samples(): array {
		if ( isset( self::$memo['samples'] ) ) {
			return self::$memo['samples'];
		}
		$posts = [];
		$routes = [];
		foreach ( array_keys( Roles::ROLES ) as $role ) {
			if ( 'other' === $role ) {
				continue;
			}
			$p = in_array( $role, [ 'search', 'notfound' ], true ) ? null : Roles::sample( $role );
			if ( $p ) {
				$posts[ (int) $p->ID ][] = $role;
				continue;
			}
			$r = Inspect::role_url( $role );
			if ( $r && empty( $r['post_id'] ) ) {
				$routes[ $role ] = $r['url'];
			} elseif ( $r ) {
				$posts[ (int) $r['post_id'] ][] = $role;
			}
		}
		self::$memo['samples'] = [ $posts, $routes ];
		return self::$memo['samples'];
	}

	/**
	 * One H1 per page, read from the stored content for EVERY page and entry in this window (no request needed): two H1s in
	 * the page itself, an own hero next to the shared one, or a page set to use its own hero that has none.
	 */
	public static function check_h1_static( array $args ): array {
		$out = [];
		foreach ( self::window_posts( $args, [ 'page', Entries::SERVICE, Entries::AREA ], [ 'publish' ] ) as $p ) {
			$role = Roles::of( (int) $p->ID );
			if ( '' === $role || 'other' === $role ) {
				continue;
			}
			$n = Chrome::h1_count( (string) $p->post_content );
			$flag = (string) get_post_meta( $p->ID, Chrome::HERO_META, true );
			$shared = '' === $flag && ! in_array( Chrome::design( 'hero', $role ), [ false, null, '', 'theme' ], true );
			if ( $n >= 2 ) {
				$out[] = self::post_finding( $p, 'error', 'one_h1', 'The page itself prints ' . $n . ' H1 headings (exactly one expected).', 'Keep one H1; the others become H2.', [ 'h1_count' => $n ] );
			} elseif ( 1 === $n && $shared ) {
				$out[] = self::post_finding( $p, 'error', 'one_h1', 'Two H1 headings: the shared hero for ' . $role . ' pages prints one and the page has its own hero.', 'Set the page to use its own hero (set-page-layout with title hide), or take the hero section out of the page; a build-from-brief does this by itself.', [ 'h1_count' => 2 ] );
			} elseif ( 0 === $n && 'content' === $flag ) {
				$out[] = self::post_finding( $p, 'error', 'one_h1', 'No H1: the page is set to use its own hero but its content has no heading level 1.', 'Add the hero section back, or let the page follow its role again (set-page-layout with title show).', [ 'h1_count' => 0 ] );
			}
		}
		return $out;
	}

	/** One H1 per page: the stored content of every page (see check_h1_static), then the page a visitor gets for the sample of each role in this window (the routes without a page on the last window). */
	public static function check_one_h1( array $args ): array {
		$static = self::check_h1_static( $args );
		$flagged = array_column( $static, 'id' );
		[ $posts, $routes ] = self::role_samples();
		$until = (int) ( $args['until_id'] ?? PHP_INT_MAX );
		$after = (int) ( $args['after_id'] ?? 0 );
		$todo = [];
		foreach ( $posts as $id => $roles ) {
			if ( $id > $after && $id <= $until && 'publish' === get_post_status( $id ) ) {
				$todo[] = [ 'id' => $id, 'roles' => $roles, 'url' => (string) get_permalink( $id ) ];
			}
		}
		usort( $todo, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
		if ( PHP_INT_MAX === $until ) {
			foreach ( $routes as $role => $url ) {
				$todo[] = [ 'id' => null, 'roles' => [ $role ], 'url' => $url ];
			}
		}
		$out = $static;
		$t0 = microtime( true );
		foreach ( $todo as $i => $t ) {
			if ( $i > 0 && null !== $t['id'] && microtime( true ) - $t0 > 15 ) {
				return [ 'findings' => $out, 'next_after_id' => (int) $todo[ $i - 1 ]['id'] ]; // Out of time: the page ends at the last page loaded.
			}
			if ( null !== $t['id'] && in_array( $t['id'], $flagged, true ) ) {
				continue; // The stored content already says what is wrong with this page.
			}
			$r = Inspect::inspect( $t['url'], [ 'check_links' => false, 'post_id' => $t['id'] ] );
			$base = [ 'code' => 'one_h1', 'object_type' => $t['id'] ? 'post' : 'route', 'id' => $t['id'] ? $t['id'] : $t['roles'][0], 'title' => ( $t['id'] ? get_the_title( $t['id'] ) . ' ' : '' ) . '(' . implode( ', ', $t['roles'] ) . ')', 'url' => $t['url'] ];
			if ( is_wp_error( $r ) ) {
				$out[] = $base + [ 'severity' => 'info', 'message' => 'Could not load the page to count its H1s: ' . $r->get_error_message(), 'fix' => 'Check that the site can request its own pages (loopback).' ];
				continue;
			}
			$n = (int) ( $r['headings']['h1_count'] ?? 0 );
			if ( 1 !== $n ) {
				$out[] = $base + [ 'severity' => 'error', 'message' => ( 0 === $n ? 'No H1 on the ' : $n . ' H1 headings on the ' ) . implode( '/', $t['roles'] ) . ' page (exactly one expected).', 'fix' => 'One H1 per page: the hero prints the page title as H1; other headings are H2/H3.', 'h1' => $r['headings']['h1'] ?? [] ];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ sync */

	/** Register a fix for sync-site. opts: label, description, cap (default manage_options), default (run when no fixes are named; true). */
	public static function register_fix( string $id, callable $callable, array $opts = [] ): void {
		self::$fixes[ sanitize_key( $id ) ] = self::fix_def( $callable, $opts );
	}

	private static function fix_def( callable $callable, array $opts ): array {
		return [ 'callable' => $callable, 'label' => (string) ( $opts['label'] ?? '' ), 'description' => (string) ( $opts['description'] ?? '' ), 'cap' => (string) ( $opts['cap'] ?? 'manage_options' ), 'default' => ! array_key_exists( 'default', $opts ) || ! empty( $opts['default'] ) ];
	}

	/** Every fix: built-ins < module sync_fixes() < register_fix(). */
	public static function fixes(): array {
		$all = [
			'migrations' => self::fix_def( [ __CLASS__, 'fix_migrations' ], [ 'label' => 'Data migrations', 'description' => 'Run pending data migrations (they also run when an administrator opens wp-admin).' ] ),
			'menus'      => self::fix_def( [ __CLASS__, 'fix_menus' ], [ 'label' => 'Automatic menu items', 'description' => 'Rebuild the service and area items of the menus from the live entries.' ] ),
		];
		foreach ( Plugin::modules( 'sync_fixes' ) as $module ) {
			foreach ( (array) $module::sync_fixes() as $id => $def ) {
				$def = is_callable( $def ) ? [ $def, [] ] : array_values( (array) $def );
				if ( isset( $def[0] ) && is_callable( $def[0] ) ) {
					$all[ sanitize_key( (string) $id ) ] = self::fix_def( $def[0], (array) ( $def[1] ?? [] ) );
				}
			}
		}
		foreach ( self::$fixes as $id => $def ) {
			$all[ $id ] = $def;
		}
		return $all;
	}

	/**
	 * sync-site: run fixes (dry run by default: each fix reports what it would change).
	 *
	 * @return array|\WP_Error
	 */
	public static function sync( array $args ) {
		$dry = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		$all = self::fixes();
		$want = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $args['fixes'] ?? [] ) ) ) ) );
		$unknown = array_diff( $want, array_keys( $all ) );
		if ( $unknown ) {
			return new \WP_Error( 'lb_unknown_fix', 'Unknown fixes: ' . implode( ', ', $unknown ) . '. Available: ' . implode( ', ', array_keys( $all ) ) . '.', [ 'status' => 400 ] );
		}
		if ( ! $want ) {
			$want = array_keys( array_filter( $all, static function ( $f ) { return $f['default']; } ) );
		}
		if ( ! $dry && ! Lock::acquire( 'sync', 300 ) ) {
			return new \WP_Error( 'lb_busy', 'Another sync is running. Try again in a moment.', [ 'status' => 409 ] );
		}
		$report = [];
		try {
			foreach ( $want as $id ) {
				$f = $all[ $id ];
				if ( ! current_user_can( $f['cap'] ) ) {
					$report[ $id ] = [ 'error' => 'Your user may not run this fix (' . $f['cap'] . ').' ];
					continue;
				}
				try {
					$r = call_user_func( $f['callable'], $dry, $args );
				} catch ( \Throwable $e ) {
					$r = new \WP_Error( 'lb_fix_failed', $e->getMessage() );
				}
				$report[ $id ] = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : (array) $r;
			}
		} finally {
			if ( ! $dry ) {
				Lock::release( 'sync' );
			}
		}
		$out = [ 'dry_run' => $dry, 'fixes' => $report, 'available' => array_map( static function ( $f ) { return [ 'label' => $f['label'], 'description' => $f['description'], 'default' => $f['default'] ]; }, $all ) ];
		if ( $dry ) {
			$out['next'] = 'Nothing was changed. Call sync-site again with dry_run false to apply.';
		}
		return $out;
	}

	public static function fix_migrations( bool $dry, array $args = [] ) {
		unset( $args );
		if ( $dry ) {
			$p = Migrate::pending();
			return [ 'changed' => count( $p ), 'items' => $p, 'failed' => array_keys( Migrate::failed() ) ];
		}
		$r = Migrate::run( 20.0 );
		if ( ! empty( $r['busy'] ) ) {
			return new \WP_Error( 'lb_busy', 'Another migration run holds the lock.' );
		}
		return [ 'changed' => count( $r['ran'] ), 'items' => $r['ran'], 'errors' => $r['errors'], 'complete' => $r['complete'] ];
	}

	public static function fix_menus( bool $dry, array $args = [] ): array {
		unset( $args );
		if ( $dry ) {
			return [ 'changed' => null, 'items' => [], 'note' => 'Applying rebuilds the generated items of every marked menu link (Nav::flush); nothing is listed in advance.' ];
		}
		$ids = Nav::flush( true );
		return [ 'changed' => count( $ids ), 'items' => $ids ];
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function define_tools(): void {
		Abilities::add( 'audit-site', 'Audit the site', 'Checks content, Business Info, menus, shared designs, SEO settings and rendered pages, one page at a time: call with after_id 0, then again with next_after_id until it is null (each page looks at up to `limit` posts by ID and returns at most max_findings findings; totals and by_code count all). Findings: {severity, code, object_type, id, title, url, message, fix}. scope narrows it (content, company, menus, chrome, seo, render, ... see list_checks); render loads sample pages and is the slow part. Results are also kept for Launch Builder > Site health.',
			[ 'properties' => [
				'scope'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Check scopes to run (default all).' ],
				'codes'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Only these check codes.' ],
				'after_id'      => [ 'type' => 'integer', 'description' => '0 to start; then next_after_id from the previous page.' ],
				'limit'         => [ 'type' => 'integer', 'description' => 'Posts per page, 1-100 (default 50).' ],
				'max_findings'  => [ 'type' => 'integer', 'description' => 'Findings returned per page, 1-200 (default 200).' ],
				'only_failures' => [ 'type' => 'boolean', 'description' => 'Leave out info findings (still counted).' ],
				'list_checks'   => [ 'type' => 'boolean', 'description' => 'Return the registered checks, scopes and sync fixes instead of running.' ],
			] ], [ __CLASS__, 'audit_tool' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'sync-site', 'Repair drift', 'Runs registered fixes (migrations, automatic menu items, and whatever other modules register). dry_run defaults to true and reports what each fix would change; call again with dry_run false to apply. Fixes snapshot what they change.',
			[ 'properties' => [
				'fixes'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Fix ids (default: every default fix). audit-site list_checks lists them.' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Default true.' ],
			] ], [ __CLASS__, 'sync_tool' ], 'manage_options' );
	}

	public static function audit_tool( $input = [] ) {
		$input = (array) $input;
		foreach ( [ 'scope', 'codes' ] as $k ) {
			if ( isset( $input[ $k ] ) && ( ! is_array( $input[ $k ] ) || array_filter( $input[ $k ], static function ( $v ) { return ! is_string( $v ); } ) ) ) {
				return new \WP_Error( 'lb_invalid_args', $k . ' must be a list of strings.', [ 'status' => 400 ] );
			}
		}
		if ( ! empty( $input['list_checks'] ) ) {
			$checks = [];
			foreach ( self::checks() as $code => $c ) {
				$checks[] = [ 'code' => $code, 'scope' => $c['scope'], 'batched' => ! empty( $c['batched'] ) ];
			}
			return [ 'checks' => $checks, 'scopes' => array_values( array_unique( array_column( $checks, 'scope' ) ) ), 'fixes' => array_map( static function ( $f ) { return [ 'label' => $f['label'], 'description' => $f['description'], 'default' => $f['default'] ]; }, self::fixes() ) ];
		}
		return self::run_cached( $input );
	}

	public static function sync_tool( $input = [] ) {
		$input = (array) $input;
		if ( isset( $input['fixes'] ) && ( ! is_array( $input['fixes'] ) || array_filter( $input['fixes'], static function ( $v ) { return ! is_string( $v ); } ) ) ) {
			return new \WP_Error( 'lb_invalid_args', 'fixes must be a list of fix ids.', [ 'status' => 400 ] );
		}
		return self::sync( $input );
	}

	/** Module hook: the last audit run in site-state. */
	public static function site_state( array &$state ): void {
		$r = self::results();
		$state['audit'] = $r ? [ 'updated' => $r['updated'] ?? null, 'complete' => ! empty( $r['complete'] ), 'totals' => $r['totals'] ?? [], 'scope' => $r['scope'] ?? [] ] : null;
	}

	/** Module hook: how to check the work, in get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['tools_by_job']['Check'] = 'audit-site (paged by after_id), inspect-page, preview-urls, sync-site (dry run first)';
		$pb['check'] = [
			'audit'   => 'audit-site checks content (unknown tokens, [[placeholders]], demo copy, unchanged pattern copy, placeholder images, duplicate Kadence IDs), Business Info demo values, dead menu links, Kadence Pro Elements, Rank Math settings and one H1 per role. Start with after_id 0 and repeat with next_after_id until it is null; fix errors before go-live.',
			'inspect' => 'inspect-page { url | post_id | role } shows what one page prints for a visitor: role, which shared design printed in each slot, crumbs, title/description/canonical/robots, JSON-LD types, H1/H2, raw tokens, placeholders, demo text, dead/broken/tel/mailto links, images without alt, forms. Its issues list is the per-page definition of done.',
			'preview' => 'preview-urls gives one URL per role and per shared design: screenshot each at 1440 and 390 px wide.',
			'sync'    => 'sync-site { fixes, dry_run: true } reports what it would repair; run it again with dry_run false.',
		];
	}
}
