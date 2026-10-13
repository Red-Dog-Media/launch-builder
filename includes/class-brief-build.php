<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a whole site from a validated brief (build-from-brief). The work is split into parts that run in a fixed order and
 * resume from a stored job:
 *
 *   identity    site title, Business Info, permalinks, discouraging search engines while the brief says launch.indexing false
 *   design      palette, fonts, style preset, buttons, page style
 *   entries     service categories, services and service areas (keyed, so re-runs update in place)
 *   media       images imported once per URL, then bound to the logo, site icon and entries
 *   pages       the sitemap: content, roles, hubs, category pages, front page, legal texts
 *   chrome      the shared designs per page role (set-chrome)
 *   menus       the header menu and the footer / legal menus
 *   seo         SEO templates, local SEO, URL structure and redirects
 *   conversions Kadence Conversions items (the exit-intent popup is on unless the brief turns it off)
 *
 * Every part reuses the module that owns it (Company, Design, Style, Entries, Content, Patterns, Chrome, Nav, Compat,
 * Conversions, Permalinks, Redirects, Roles) and nothing is written twice: pages and entries carry a stable key, a hash of the
 * brief text they were built from and a ledger row, so an unchanged brief changes nothing, an object an admin edited since
 * is kept, and an admin's draft is never republished. A dry run builds the same plan without writing.
 */
final class Brief_Build {

	/** Option holding the current job (not autoloaded). Rank Math Instant Indexing reads brief.launch.indexing from it. */
	const OPT = 'launch_builder_build_job';

	/** Parts in the order they run. */
	const PHASES = [ 'identity', 'design', 'entries', 'media', 'pages', 'chrome', 'menus', 'seo', 'conversions' ];

	/** Seconds of work per call, and items per call for the parts that go object by object. */
	const BUDGET = 20.0;
	const ITEMS  = 25;
	const PAGES  = 10;
	const IMAGES = 6;

	/** Rows kept in the stored job report. */
	const MAX_ROWS = 400;

	/** @var array<string,array>|null the stored job of this request. */
	private static $job = null;

	/* ------------------------------------------------------------------ the tool */

	/** build-from-brief. */
	public static function tool( $input ) {
		$input = (array) $input;
		$dry = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$phase = isset( $input['phase'] ) ? (string) $input['phase'] : 'all';
		if ( 'all' !== $phase && ! in_array( $phase, self::PHASES, true ) ) {
			return new \WP_Error( 'lb_phase', 'phase must be all, ' . implode( ', ', self::PHASES ) . '.', [ 'status' => 400 ] );
		}
		if ( isset( $input['brief'] ) && ! is_array( $input['brief'] ) ) {
			return new \WP_Error( 'lb_invalid_args', 'brief must be an object.', [ 'status' => 400 ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore -- a part may take longer than the default limit.
		}
		if ( ! Lock::acquire( 'build', 150 ) ) {
			return new \WP_Error( 'lb_busy', 'Another build is running; call again in a moment (with the same job).', [ 'status' => 409 ] );
		}
		try {
			if ( isset( $input['brief'] ) ) {
				$chk = Brief_Validate::check( $input['brief'] );
				if ( $chk['errors'] ) {
					return [ 'valid' => false, 'done' => true, 'dry_run' => $dry, 'errors' => array_slice( $chk['errors'], 0, 100 ), 'error_count' => count( $chk['errors'] ), 'warnings' => array_slice( $chk['warnings'], 0, 50 ), 'next' => 'Nothing was written. Fix the errors (each names the path in the brief) and call build-from-brief again.' ];
				}
				$job = self::new_job( $chk, $dry, $phase, ! empty( $input['prune'] ), ! empty( $input['force'] ) );
			} else {
				$job = self::load( isset( $input['job'] ) ? (string) $input['job'] : '' );
				if ( ! $job ) {
					return new \WP_Error( 'lb_no_job', 'No such job. Call build-from-brief with a brief to start one.', [ 'status' => 404 ] );
				}
				if ( 'all' !== $phase ) {
					if ( ! in_array( $phase, $job['phases'], true ) ) {
						return new \WP_Error( 'lb_phase', 'This job does not run the part ' . $phase . '.', [ 'status' => 400 ] );
					}
					$job['phase'] = $phase;
					$job['cursor'] = isset( $input['cursor'] ) ? max( 0, (int) $input['cursor'] ) : 0;
					$job['status'] = 'running';
					$job['done_phases'] = array_values( array_diff( $job['done_phases'], [ $phase ] ) );
				} elseif ( isset( $input['cursor'] ) ) {
					$job['cursor'] = max( 0, (int) $input['cursor'] );
				}
			}
			return self::run( $job, 'all' === $phase ? '' : $phase );
		} finally {
			Lock::release( 'build' );
		}
	}

	/** The brief builder's own filter for Compat::indexing_suppressed(): a build that is not finished holds Instant Indexing back. */
	public static function suppress_while_building( $why ) {
		if ( $why ) {
			return $why;
		}
		$job = get_option( self::OPT, null );
		return is_array( $job ) && empty( $job['dry_run'] ) && 'running' === ( $job['status'] ?? '' ) && time() - (int) ( $job['touched'] ?? 0 ) < 6 * HOUR_IN_SECONDS;
	}

	/** Go-live: the brief no longer holds indexing back. */
	public static function set_indexing( bool $on ): void {
		$job = get_option( self::OPT, null );
		if ( is_array( $job ) && isset( $job['brief']['launch']['indexing'] ) ) {
			$job['brief']['launch']['indexing'] = $on;
			update_option( self::OPT, $job, false );
		}
	}

	/** The stored job (or null). */
	public static function job(): ?array {
		$job = get_option( self::OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/* ------------------------------------------------------------------ the job */

	private static function new_job( array $chk, bool $dry, string $phase, bool $prune, bool $force ): array {
		$phases = 'all' === $phase ? self::PHASES : [ $phase ];
		$id = 'j' . gmdate( 'ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		return [
			'id'          => $id,
			'created'     => gmdate( 'c' ),
			'touched'     => time(),
			'user'        => get_current_user_id(),
			'dry_run'     => $dry,
			'status'      => 'running',
			'phases'      => $phases,
			'done_phases' => [],
			'phase'       => $phases[0],
			'cursor'      => 0,
			'prune'       => $prune,
			'force'       => $force,
			'brief'       => $chk['brief'],
			'brief_hash'  => md5( (string) wp_json_encode( $chk['brief'] ) ),
			'snapshot'    => '',
			'state'       => [ 'media' => [], 'pages' => [], 'services' => [], 'areas' => [], 'cats' => [], 'menus' => [] ],
			'report'      => [
				'totals'       => [],
				'by_type'      => [],
				'rows'         => [],
				'rows_dropped' => 0,
				'copy_needed'  => [],
				'copy_dropped' => 0,
				'warnings'     => array_map( static function ( $w ) { return $w['path'] . ': ' . $w['message']; }, array_slice( $chk['warnings'], 0, 60 ) ),
				'deferred'     => $chk['deferred'],
				'modules_off'  => $chk['modules_off'],
				'notes'        => [],
			],
		];
	}

	/** The job named $id, or the stored one when $id is empty. */
	private static function load( string $id ): ?array {
		$job = self::job();
		if ( ! $job ) {
			return null;
		}
		return '' === $id || 'last' === $id || $id === ( $job['id'] ?? '' ) ? $job : null;
	}

	private static function save( array $job ): void {
		$job['touched'] = time();
		update_option( self::OPT, $job, false );
		self::$job = $job;
	}

	/* ------------------------------------------------------------------ the loop */

	private static function run( array $job, string $only ): array {
		$dry = (bool) $job['dry_run'];
		$deadline = microtime( true ) + self::BUDGET;
		$new_rows = [];
		$warn_before = count( $job['report']['warnings'] );
		if ( ! $dry ) {
			Compat::suppress_indexing( true );
			if ( '' === $job['snapshot'] ) {
				$job['snapshot'] = self::snapshot( $job['brief'] );
			}
		}
		// The stored job is saved first: a part that runs out of memory or time still leaves a job to continue from.
		self::save( $job );
		$ran = [];
		while ( 'running' === $job['status'] ) {
			$phase = $job['phase'];
			$ran[] = $phase;
			$res = self::run_phase( $job, $phase, $deadline );
			foreach ( $res['rows'] as $r ) {
				$new_rows[] = $r;
			}
			if ( ! $res['done'] ) {
				$job['cursor'] = (int) $res['cursor'];
				self::save( $job );
				break;
			}
			$job['done_phases'][] = $phase;
			$job['cursor'] = 0;
			$next = null;
			foreach ( $job['phases'] as $p ) {
				if ( ! in_array( $p, $job['done_phases'], true ) ) {
					$next = $p;
					break;
				}
			}
			if ( null === $next ) {
				$job['status'] = 'done';
				self::finish( $job );
				self::save( $job );
				break;
			}
			$job['phase'] = $next;
			self::save( $job );
			if ( '' !== $only || microtime( true ) >= $deadline ) {
				break;
			}
		}
		return self::result( $job, $new_rows, array_slice( $job['report']['warnings'], $warn_before ), $ran );
	}

	private static function result( array $job, array $rows, array $warnings, array $ran ): array {
		$done = 'done' === $job['status'];
		$todo = array_values( array_diff( $job['phases'], $job['done_phases'] ) );
		$out = [
			'job'        => $job['id'],
			'dry_run'    => (bool) $job['dry_run'],
			'valid'      => true,
			'done'       => $done,
			'phases_run' => array_values( array_unique( $ran ) ),
			'next_phase' => $done ? null : $job['phase'],
			'cursor'     => $done ? 0 : (int) $job['cursor'],
			'parts_left' => $todo,
			'report'     => [
				'rows'     => array_slice( $rows, 0, 100 ),
				'rows_more' => max( 0, count( $rows ) - 100 ),
				'totals'   => $job['report']['totals'],
				'warnings' => array_slice( $warnings, 0, 60 ),
			],
		];
		if ( ! $job['dry_run'] ) {
			$out['snapshot'] = $job['snapshot'];
		}
		if ( $done ) {
			$r = $job['report'];
			$changes = (int) ( $r['totals']['create'] ?? 0 ) + (int) ( $r['totals']['update'] ?? 0 ) + (int) ( $r['totals']['prune'] ?? 0 );
			$out['report']['changes'] = $changes;
			$out['report']['by_type'] = $r['by_type'];
			$out['report']['copy_needed'] = array_slice( $r['copy_needed'], 0, 80 );
			$out['report']['copy_needed_total'] = count( $r['copy_needed'] ) + (int) $r['copy_dropped'];
			$out['report']['deferred'] = $r['deferred'];
			$out['report']['modules_off'] = $r['modules_off'];
			$out['report']['notes'] = $r['notes'];
			$out['report']['indexing_held_back'] = self::held_back();
			if ( $job['dry_run'] ) {
				$out['next'] = $changes ? 'This was a dry run: nothing was written. Fix the copy_needed list in the brief, then call build-from-brief again with dry_run false.' : 'Nothing would change.';
			} else {
				$out['next'] = 'Built. Run audit-site (or inspect-page on each role), write the remaining copy, then go-live.';
			}
		} else {
			$out['next'] = 'Not finished: call build-from-brief again with job "' . $job['id'] . '" (the cursor is stored).';
		}
		return $out;
	}

	/** Instant Indexing submissions held back so far (Compat counts them). */
	private static function held_back(): array {
		$h = get_option( Compat::OPT_INDEXNOW, [] );
		return [ 'count' => (int) ( is_array( $h ) ? ( $h['count'] ?? 0 ) : 0 ), 'last_url' => is_array( $h ) ? (string) ( $h['last_url'] ?? '' ) : '', 'held_back_now' => (bool) Compat::indexing_suppressed() ];
	}

	/** Housekeeping when the last part is done. */
	private static function finish( array &$job ): void {
		if ( $job['dry_run'] ) {
			return;
		}
		flush_rewrite_rules( false );
		$ids = Nav::flush();
		if ( $ids ) {
			$job['report']['notes'][] = 'Menus rebuilt: ' . count( $ids ) . '.';
		}
		Cache::purge_later();
		$still = Abilities::get_snapshot( (string) $job['snapshot'] );
		if ( ! $still ) {
			$job['report']['warnings'][] = 'The snapshot ' . $job['snapshot'] . ' was pruned while the build ran (only the newest 30 are kept).';
		}
	}

	private static function run_phase( array &$job, string $phase, float $deadline ): array {
		$rows = [];
		try {
			switch ( $phase ) {
				case 'identity':
					return self::phase_identity( $job );
				case 'design':
					return self::phase_design( $job );
				case 'entries':
					return self::phase_entries( $job, $deadline );
				case 'media':
					return self::phase_media( $job, $deadline );
				case 'pages':
					return self::phase_pages( $job, $deadline );
				case 'chrome':
					return self::phase_chrome( $job );
				case 'menus':
					return self::phase_menus( $job );
				case 'seo':
					return self::phase_seo( $job );
				case 'conversions':
					return self::phase_conversions( $job );
			}
		} catch ( \Throwable $e ) {
			self::add( $job, $rows, [ 'type' => $phase, 'key' => $phase, 'action' => 'error', 'detail' => $e->getMessage() ] );
			$job['report']['warnings'][] = $phase . ': ' . $e->getMessage();
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/** Record one row of the plan or the report. */
	private static function add( array &$job, array &$rows, array $row ): void {
		$row += [ 'action' => 'keep' ];
		$job['report']['totals'][ $row['action'] ] = (int) ( $job['report']['totals'][ $row['action'] ] ?? 0 ) + 1;
		$t = (string) ( $row['type'] ?? '' );
		$job['report']['by_type'][ $t ][ $row['action'] ] = (int) ( $job['report']['by_type'][ $t ][ $row['action'] ] ?? 0 ) + 1;
		if ( count( $job['report']['rows'] ) < self::MAX_ROWS ) {
			$job['report']['rows'][] = $row;
		} else {
			$job['report']['rows_dropped']++;
		}
		$rows[] = $row;
	}

	private static function warn( array &$job, string $msg ): void {
		if ( count( $job['report']['warnings'] ) < 200 ) {
			$job['report']['warnings'][] = $msg;
		}
	}

	private static function copy_needed( array &$job, array $item ): void {
		foreach ( (array) $item['needs'] as $k => $v ) { // A few examples per reason keep the report small.
			if ( is_array( $v ) ) {
				$item['needs'][ $k ] = array_slice( $v, 0, 3 );
			}
		}
		if ( count( $job['report']['copy_needed'] ) < 300 ) {
			$job['report']['copy_needed'][] = $item;
		} else {
			$job['report']['copy_dropped']++;
		}
	}

	/* ------------------------------------------------------------------ snapshot */

	/** One snapshot of everything the build may change, taken before the first write. */
	private static function snapshot( array $b ): string {
		$mods = [ 'custom_logo', 'base_font', 'heading_font', 'blocks_header', 'blocks_header_id' ];
		$options = [ 'blogname', 'blogdescription', 'page_on_front', 'show_on_front', 'permalink_structure', 'blog_public', 'site_icon', Company::OPT, 'kadence_global_palette', Roles::OPT_HUBS, Chrome::OPT_MODE, Chrome::OPT_MAP, Chrome::OPT_INDEX, Style::OPT, Compat::OPT_TEMPLATES, Permalinks::OPT ];
		$posts = [];
		foreach ( $b['sitemap']['pages'] ?? [] as $pg ) {
			$id = self::find_page( $pg );
			if ( $id ) {
				$posts[] = $id;
			}
		}
		foreach ( [ 'services' => Entries::SERVICE, 'areas' => Entries::AREA ] as $t => $pt ) {
			foreach ( $b['entries'][ $t ] ?? [] as $e ) {
				$id = Entries::find_by_key( $pt, $e['key'] );
				if ( ! $id ) {
					$hit = get_posts( [ 'post_type' => $pt, 'name' => $e['slug'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
					$id = $hit ? (int) $hit[0] : 0;
				}
				if ( $id ) {
					$posts[] = $id;
				}
			}
		}
		return Abilities::snapshot( 'build-from-brief', $mods, $options, array_slice( array_values( array_unique( $posts ) ), 0, 150 ) );
	}

	/* ------------------------------------------------------------------ shared helpers */

	/** A stable hash of the text and fields an object was built from. */
	private static function spec_hash( array $spec, array $drop = [] ): string {
		foreach ( array_merge( [ '_p' ], $drop ) as $d ) {
			unset( $spec[ $d ] );
		}
		return md5( (string) wp_json_encode( self::canon( $spec ) ) );
	}

	private static function canon( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		if ( array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			ksort( $v );
		}
		return array_map( [ __CLASS__, 'canon' ], $v );
	}

	/** Hash of an object's stored state, for the ledger (an admin's edit changes it). */
	public static function state_hash( int $id ): string {
		$p = get_post( $id );
		if ( ! $p ) {
			return '';
		}
		$svc = array_map( 'intval', (array) get_post_meta( $id, '_lb_services', false ) );
		sort( $svc );
		return Ledger::hash_value( [ $p->post_title, $p->post_content, $p->post_excerpt, (int) $p->post_parent, (int) $p->menu_order, (string) get_post_meta( $id, '_lb_icon', true ), get_post_meta( $id, Compat::META_FAQ, true ), $svc, (string) get_post_meta( $id, Roles::META, true ) ] );
	}

	private static function lkey( string $kind, string $key ): string {
		return 'brief:' . $kind . ':' . $key;
	}

	/** The id of the page of a spec: by key, else by path (an unkeyed page that is already there is adopted). */
	private static function find_page( array $spec ): int {
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $spec['key'], 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $ids ) {
			return (int) $ids[0];
		}
		$p = get_page_by_path( $spec['path'], OBJECT, 'page' );
		if ( $p && 'trash' !== $p->post_status ) {
			$k = (string) get_post_meta( $p->ID, '_lb_key', true );
			return '' === $k || $k === $spec['key'] ? (int) $p->ID : 0;
		}
		return 0;
	}

	/** The id a page key stands for: this run's state, else the site. */
	private static function page_id( array $job, string $key ): int {
		if ( isset( $job['state']['pages'][ $key ] ) ) {
			return (int) $job['state']['pages'][ $key ];
		}
		foreach ( $job['brief']['sitemap']['pages'] ?? [] as $pg ) {
			if ( $pg['key'] === $key ) {
				return self::find_page( $pg );
			}
		}
		if ( 0 === strpos( $key, 'path:' ) ) {
			$p = get_page_by_path( substr( $key, 5 ), OBJECT, 'page' );
			return $p ? (int) $p->ID : 0;
		}
		// A page that is on the site but not in this brief: by its key, else by the path a default key spells.
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $ids ) {
			return (int) $ids[0];
		}
		if ( 0 === strpos( $key, 'page:' ) ) {
			$p = get_page_by_path( substr( $key, 5 ), OBJECT, 'page' );
			return $p && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		return 0;
	}

	/** An area or service by key: this run's state, the _lb_key, else the slug or path a default key spells. */
	private static function entry_by_key( array $job, string $type, string $key ): int {
		$st = 'area' === $type ? 'areas' : 'services';
		if ( ! empty( $job['state'][ $st ][ $key ] ) ) {
			return (int) $job['state'][ $st ][ $key ];
		}
		$id = Entries::find_by_key( 'area' === $type ? Entries::AREA : Entries::SERVICE, $key );
		if ( $id ) {
			return $id;
		}
		if ( 'area' === $type && 0 === strpos( $key, 'area:' ) ) {
			$p = get_page_by_path( substr( $key, 5 ), OBJECT, Entries::AREA );
			return $p && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		if ( 'service' === $type && 0 === strpos( $key, 'svc:' ) ) {
			$hit = get_posts( [ 'post_type' => Entries::SERVICE, 'name' => substr( $key, 4 ), 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
			return $hit ? (int) $hit[0] : 0;
		}
		return 0;
	}

	/** The media key's attachment id (imported this run or before). */
	private static function media_id( array $job, string $key ): int {
		if ( '' === $key ) {
			return 0;
		}
		if ( isset( $job['state']['media'][ $key ] ) ) {
			return (int) $job['state']['media'][ $key ];
		}
		return self::attachment_by_key( $key );
	}

	private static function attachment_by_key( string $key ): int {
		$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lb_media_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : 0;
	}

	/** Reasons a text still needs writing: [[placeholders]] and unchanged pattern default copy. */
	public static function copy_reasons( string $content ): array {
		$out = [];
		if ( false !== strpos( $content, '[[' ) ) {
			$html = (string) preg_replace( '/<!--.*?-->/s', '', $content );
			if ( preg_match_all( '/\[\[(?=[^\]]*[A-Za-z])[^\[\]\n]{1,200}\]\]/', $html, $m ) ) {
				$out['placeholders'] = array_slice( array_values( array_unique( $m[0] ) ), 0, 8 );
			}
		}
		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			$defaults = Audit::default_texts();
			$hits = [];
			foreach ( Patterns::slots( parse_blocks( $content ) ) as $s ) {
				if ( ! in_array( $s['field'], Audit::COPY_FIELDS, true ) ) {
					continue;
				}
				$n = Audit::norm_text( (string) $s['value'] );
				if ( strlen( $n ) >= 30 && isset( $defaults[ md5( $n ) ] ) ) {
					$hits[] = mb_substr( $n, 0, 60 ) . ' (' . $defaults[ md5( $n ) ] . ')';
				}
			}
			if ( $hits ) {
				$out['default_copy'] = array_slice( $hits, 0, 8 );
				$out['default_copy_count'] = count( $hits );
			}
		}
		return $out;
	}

	private static function has_placeholder( $v ): bool {
		return is_string( $v ) && false !== strpos( $v, '[[' );
	}

	/* ------------------------------------------------------------------ identity */

	private static function phase_identity( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$b = $job['brief'];
		$rows = [];
		$biz = $b['business'];
		$title = (string) ( $biz['site_title'] ?? $biz['name'] ?? '' );
		$tag = (string) ( $biz['site_tagline'] ?? $biz['tagline'] ?? '' );
		unset( $biz['site_title'], $biz['site_tagline'] );
		if ( '' !== $title && get_option( 'blogname' ) !== $title ) {
			$dry || update_option( 'blogname', $title );
			self::add( $job, $rows, [ 'type' => 'site', 'key' => 'title', 'action' => 'update', 'detail' => $title ] );
		} else {
			self::add( $job, $rows, [ 'type' => 'site', 'key' => 'title', 'action' => 'keep' ] );
		}
		if ( '' !== $tag && get_option( 'blogdescription' ) !== $tag ) {
			$dry || update_option( 'blogdescription', $tag );
			self::add( $job, $rows, [ 'type' => 'site', 'key' => 'tagline', 'action' => 'update', 'detail' => $tag ] );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			if ( ! $dry ) {
				global $wp_rewrite;
				$wp_rewrite->set_permalink_structure( '/%postname%/' );
			}
			self::add( $job, $rows, [ 'type' => 'site', 'key' => 'permalinks', 'action' => 'update', 'detail' => '/%postname%/' ] );
		}
		// The form embed: Business Info holds one; the first given form supplies it.
		if ( empty( $biz['form_html'] ) ) {
			foreach ( [ 'quote', 'contact', 'booking', 'careers' ] as $f ) {
				if ( ! empty( $b['forms'][ $f ]['embed'] ) ) {
					$biz['form_html'] = $b['forms'][ $f ]['embed'];
					break;
				}
			}
		}
		foreach ( [ 'quote' ] as $f ) {
			if ( empty( $biz['quote_url'] ) && ! empty( $b['forms'][ $f ]['url'] ) ) {
				$biz['quote_url'] = $b['forms'][ $f ]['url'];
			}
		}
		if ( empty( $biz['business_type'] ) && ! empty( $b['seo']['schema_type'] ) ) {
			$biz['business_type'] = $b['seo']['schema_type'];
		}
		$saved = (array) get_option( Company::OPT, [] );
		$fields = Company::fields();
		$diff = [];
		foreach ( $biz as $k => $v ) {
			if ( ! isset( $fields[ $k ] ) ) {
				continue;
			}
			if ( 'list' === $fields[ $k ][1] ) {
				$same = wp_json_encode( Company::normalize_list( $k, $v ) ) === wp_json_encode( $saved[ $k ] ?? [] );
			} else {
				$same = (string) ( $saved[ $k ] ?? '' ) === Company::clean( $k, $fields[ $k ][1], (string) ( is_bool( $v ) ? ( $v ? '1' : '' ) : $v ) );
			}
			if ( ! $same ) {
				$diff[] = $k;
			}
		}
		if ( $diff ) {
			if ( ! $dry ) {
				$changed = Company::update( $biz );
				foreach ( Company::rejected() as $k => $why ) {
					self::warn( $job, 'business.' . $k . ': ' . $why );
				}
				$diff = $changed;
			}
			self::add( $job, $rows, [ 'type' => 'business', 'key' => 'business', 'action' => $diff ? 'update' : 'keep', 'detail' => 'fields: ' . implode( ', ', $diff ) ] );
		} else {
			self::add( $job, $rows, [ 'type' => 'business', 'key' => 'business', 'action' => 'keep' ] );
		}
		// While the brief says indexing is off the site stays hidden from search engines.
		if ( isset( $b['launch']['indexing'] ) && false === $b['launch']['indexing'] ) {
			if ( '0' !== (string) get_option( 'blog_public', '1' ) ) {
				$dry || update_option( 'blog_public', '0' );
				self::add( $job, $rows, [ 'type' => 'site', 'key' => 'search-engines', 'action' => 'update', 'detail' => 'discouraged until go-live' ] );
			}
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/* ------------------------------------------------------------------ design */

	/** slug => colour of the palette Kadence uses now. */
	public static function palette_now(): array {
		$cur = get_option( 'kadence_global_palette' );
		$pal = is_string( $cur ) ? json_decode( $cur, true ) : ( is_array( $cur ) ? $cur : null );
		if ( ! is_array( $pal ) ) {
			return [];
		}
		$active = in_array( $pal['active'] ?? '', [ 'palette', 'second-palette', 'third-palette' ], true ) ? $pal['active'] : 'palette';
		$out = [];
		foreach ( (array) ( $pal[ $active ] ?? [] ) as $e ) {
			if ( isset( $e['slug'], $e['color'] ) ) {
				$out[ (string) $e['slug'] ] = strtoupper( (string) $e['color'] );
			}
		}
		return $out;
	}

	private static function phase_design( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$brand = $job['brief']['brand'];
		$rows = [];
		if ( ! $brand ) {
			self::add( $job, $rows, [ 'type' => 'brand', 'key' => 'brand', 'action' => 'skip', 'detail' => 'no brand section' ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		$args = [];
		if ( ! empty( $brand['palette'] ) ) {
			$cur = self::palette_now();
			$want = array_diff_assoc( $brand['palette'], $cur );
			if ( $want ) {
				$args['palette'] = $brand['palette'];
			}
		}
		foreach ( (array) ( $brand['fonts'] ?? [] ) as $role => $spec ) {
			$mod = 'headings' === $role ? 'heading_font' : 'base_font';
			$cur = get_theme_mod( $mod, [] );
			$cur = is_array( $cur ) ? $cur : [];
			if ( strtolower( (string) ( $cur['family'] ?? '' ) ) !== strtolower( $spec['family'] ) || ( isset( $spec['weight'] ) && (string) ( $cur['weight'] ?? '' ) !== (string) $spec['weight'] ) ) {
				$args['fonts'][ $role ] = $spec;
			}
		}
		if ( $args ) {
			if ( ! $dry ) {
				$r = Design::set_brand( $args );
				if ( is_wp_error( $r ) ) {
					self::warn( $job, 'brand: ' . $r->get_error_message() );
					self::add( $job, $rows, [ 'type' => 'brand', 'key' => 'palette-fonts', 'action' => 'error', 'detail' => $r->get_error_message() ] );
				} else {
					self::add( $job, $rows, [ 'type' => 'brand', 'key' => 'palette-fonts', 'action' => 'update', 'detail' => implode( ', ', $r['changed'] ) ] );
				}
			} else {
				self::add( $job, $rows, [ 'type' => 'brand', 'key' => 'palette-fonts', 'action' => 'update', 'detail' => implode( ', ', array_keys( $args ) ) ] );
			}
		} elseif ( ! empty( $brand['palette'] ) || ! empty( $brand['fonts'] ) ) {
			self::add( $job, $rows, [ 'type' => 'brand', 'key' => 'palette-fonts', 'action' => 'keep' ] );
		}
		if ( ! empty( $brand['style'] ) && ( '' !== $brand['style']['preset'] || $brand['style']['tokens'] ) ) {
			$st = Style::get();
			$same = ( '' === $brand['style']['preset'] || $st['preset'] === $brand['style']['preset'] ) && Ledger::hash_value( $brand['style']['tokens'] ) === Ledger::hash_value( array_intersect_key( $st['tokens'], $brand['style']['tokens'] ) );
			if ( $same ) {
				self::add( $job, $rows, [ 'type' => 'style', 'key' => 'style', 'action' => 'keep', 'detail' => $st['preset'] ] );
			} elseif ( $dry ) {
				self::add( $job, $rows, [ 'type' => 'style', 'key' => 'style', 'action' => 'update', 'detail' => $brand['style']['preset'] ] );
			} else {
				$a = [ 'tokens' => $brand['style']['tokens'], 'apply_buttons' => empty( $brand['buttons'] ) ];
				if ( '' !== $brand['style']['preset'] ) {
					$a['preset'] = $brand['style']['preset'];
				}
				$r = Style::tool_set( $a );
				if ( is_wp_error( $r ) ) {
					self::warn( $job, 'style: ' . $r->get_error_message() );
					self::add( $job, $rows, [ 'type' => 'style', 'key' => 'style', 'action' => 'error', 'detail' => $r->get_error_message() ] );
				} else {
					self::add( $job, $rows, [ 'type' => 'style', 'key' => 'style', 'action' => 'update', 'detail' => (string) ( $r['preset'] ?? '' ) ] );
				}
			}
		}
		foreach ( [ 'buttons' => 'set_buttons', 'page' => 'set_page_style' ] as $k => $fn ) {
			if ( empty( $brand[ $k ] ) ) {
				continue;
			}
			if ( $dry ) {
				self::add( $job, $rows, [ 'type' => 'design', 'key' => $k, 'action' => 'update', 'detail' => 'applied again on every run' ] );
				continue;
			}
			$r = Design::$fn( is_string( $brand[ $k ] ) ? [ 'preset' => $brand[ $k ] ] : (array) $brand[ $k ] );
			if ( is_wp_error( $r ) ) {
				self::warn( $job, 'brand.' . $k . ': ' . $r->get_error_message() );
				self::add( $job, $rows, [ 'type' => 'design', 'key' => $k, 'action' => 'error', 'detail' => $r->get_error_message() ] );
			} else {
				self::add( $job, $rows, [ 'type' => 'design', 'key' => $k, 'action' => 'update' ] );
			}
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/* ------------------------------------------------------------------ entries */

	/** Items in the order they are made: categories, services, areas (parents first). */
	private static function entry_items( array $b ): array {
		$out = [];
		foreach ( $b['entries']['categories'] as $c ) {
			$out[] = [ 'category', $c ];
		}
		foreach ( $b['entries']['services'] as $s ) {
			$out[] = [ 'service', $s ];
		}
		foreach ( $b['entries']['areas'] as $a ) {
			$out[] = [ 'area', $a ];
		}
		return $out;
	}

	private static function phase_entries( array &$job, float $deadline ): array {
		$items = self::entry_items( $job['brief'] );
		$rows = [];
		$i = (int) $job['cursor'];
		$n = 0;
		for ( ; $i < count( $items ); $i++ ) {
			if ( $n >= self::ITEMS || ( $n > 0 && microtime( true ) >= $deadline ) ) {
				return [ 'done' => false, 'cursor' => $i, 'rows' => $rows ];
			}
			$n++;
			[ $type, $spec ] = $items[ $i ];
			try {
				if ( 'category' === $type ) {
					self::do_category( $job, $rows, $spec );
				} else {
					self::do_entry( $job, $rows, $type, $spec );
				}
			} catch ( \Throwable $e ) {
				self::add( $job, $rows, [ 'type' => $type, 'key' => (string) ( $spec['key'] ?? $spec['slug'] ?? '' ), 'action' => 'error', 'detail' => $e->getMessage() ] );
			}
		}
		self::prune_entries( $job, $rows ); // drafts them with prune, otherwise lists them and notes that prune: true would
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	private static function do_category( array &$job, array &$rows, array $c ): void {
		$dry = (bool) $job['dry_run'];
		$t = get_term_by( 'slug', $c['slug'], Entries::CAT );
		if ( $t instanceof \WP_Term ) {
			$job['state']['cats'][ $c['slug'] ] = (int) $t->term_id;
			if ( $t->name !== $c['name'] ) {
				$dry || wp_update_term( $t->term_id, Entries::CAT, [ 'name' => $c['name'] ] );
				self::add( $job, $rows, [ 'type' => 'category', 'key' => $c['slug'], 'title' => $c['name'], 'action' => 'update', 'detail' => 'renamed' ] );
			} else {
				self::add( $job, $rows, [ 'type' => 'category', 'key' => $c['slug'], 'title' => $c['name'], 'action' => 'keep' ] );
			}
			return;
		}
		if ( ! $dry ) {
			$r = wp_insert_term( $c['name'], Entries::CAT, [ 'slug' => $c['slug'] ] );
			if ( is_wp_error( $r ) ) {
				self::add( $job, $rows, [ 'type' => 'category', 'key' => $c['slug'], 'action' => 'error', 'detail' => $r->get_error_message() ] );
				return;
			}
			$job['state']['cats'][ $c['slug'] ] = (int) $r['term_id'];
		}
		self::add( $job, $rows, [ 'type' => 'category', 'key' => $c['slug'], 'title' => $c['name'], 'action' => 'create' ] );
	}

	/** The id of an entry by key, else (services) by slug / (areas) by path. */
	private static function find_entry( string $type, array $spec ): int {
		$pt = 'area' === $type ? Entries::AREA : Entries::SERVICE;
		$id = Entries::find_by_key( $pt, $spec['key'] );
		if ( $id ) {
			return $id;
		}
		if ( 'area' === $type ) {
			$p = get_page_by_path( $spec['path'], OBJECT, Entries::AREA );
			if ( $p && 'trash' !== $p->post_status ) {
				$k = (string) get_post_meta( $p->ID, '_lb_key', true );
				return '' === $k || $k === $spec['key'] ? (int) $p->ID : 0;
			}
			return 0;
		}
		$hit = get_posts( [ 'post_type' => $pt, 'name' => $spec['slug'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
		if ( $hit ) {
			$k = (string) get_post_meta( (int) $hit[0], '_lb_key', true );
			return '' === $k || $k === $spec['key'] ? (int) $hit[0] : 0;
		}
		return 0;
	}

	/** The key of a page or entry: its _lb_key, else the key a brief gives it by default ('' for no post). */
	private static function key_of( int $id ): string {
		$p = $id ? get_post( $id ) : null;
		if ( ! $p ) {
			return '';
		}
		$k = (string) get_post_meta( $id, '_lb_key', true );
		if ( '' !== $k ) {
			return $k;
		}
		return Brief_Export::default_key( $p );
	}

	/** The FAQs stored on an entry as written (tokens unresolved), as a list of { q, a }. */
	public static function faqs_raw( int $id ): array {
		$raw = get_post_meta( $id, Compat::META_FAQ, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			$row = (array) $row;
			$q = trim( (string) ( $row['q'] ?? ( $row['question'] ?? '' ) ) );
			$a = trim( (string) ( $row['a'] ?? ( $row['answer'] ?? '' ) ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = [ 'q' => $q, 'a' => $a ];
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/** A post's slug as a brief states it (a draft without a slug has the one its title gives). */
	private static function slug_of( \WP_Post $p ): string {
		return '' !== (string) $p->post_name ? (string) $p->post_name : sanitize_title( $p->post_title );
	}

	/** The current values of an entry, shaped like a spec (only what a brief sets). */
	private static function entry_now( int $id, string $type ): array {
		$p = get_post( $id );
		$now = [ 'title' => $p->post_title, 'slug' => self::slug_of( $p ), 'status' => $p->post_status, 'summary' => (string) $p->post_excerpt, 'order' => (int) $p->menu_order, 'icon' => (string) get_post_meta( $id, '_lb_icon', true ), 'faqs' => self::faqs_raw( $id ) ];
		$seo = [];
		foreach ( [ 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'keyword' => 'rank_math_focus_keyword' ] as $f => $mk ) {
			$v = (string) get_post_meta( $id, $mk, true );
			if ( '' !== $v ) {
				$seo[ $f ] = $v;
			}
		}
		$now['seo'] = $seo;
		if ( 'service' === $type ) {
			$terms = wp_get_object_terms( $id, Entries::CAT );
			$cats = is_wp_error( $terms ) ? [] : array_values( wp_list_pluck( $terms, 'slug' ) );
			sort( $cats );
			$now['categories'] = $cats;
		} else {
			$now['parent_key'] = self::key_of( (int) $p->post_parent );
			$keys = [];
			foreach ( array_map( 'intval', (array) get_post_meta( $id, '_lb_services', false ) ) as $sid ) {
				$k = self::key_of( $sid );
				if ( '' !== $k ) {
					$keys[] = $k;
				}
			}
			sort( $keys );
			$now['services'] = $keys;
		}
		return $now;
	}

	private static function do_entry( array &$job, array &$rows, string $type, array $spec ): void {
		$dry = (bool) $job['dry_run'];
		$pt = 'area' === $type ? Entries::AREA : Entries::SERVICE;
		$stateKey = 'area' === $type ? 'areas' : 'services';
		$id = self::find_entry( $type, $spec );
		$lkey = self::lkey( 'area' === $type ? 'area' : 'svc', $spec['key'] );
		$keep_content = 'keep' === $spec['content'];
		$cur = $id ? get_post( $id ) : null;
		$row_l = $id ? Ledger::get( $lkey ) : null;
		if ( $cur && $row_l && Ledger::is_edited( $lkey, self::state_hash( $id ) ) && empty( $job['force'] ) ) {
			$job['state'][ $stateKey ][ $spec['key'] ] = $id;
			self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $spec['title'], 'action' => 'kept_edits', 'detail' => 'edited since the last build' ] );
			return;
		}
		// What still needs writing before the entry can be published.
		$why = [];
		if ( ! $keep_content ) {
			if ( '' === trim( $spec['markdown'] ) ) {
				$why['text'] = 'no text (markdown)';
			} elseif ( self::has_placeholder( $spec['markdown'] ) ) {
				$why['placeholders'] = self::copy_reasons( $spec['markdown'] )['placeholders'] ?? [ '[[...]]' ];
			}
			if ( '' === trim( $spec['summary'] ) ) {
				$why['summary'] = 'no summary';
			}
		}
		if ( self::has_placeholder( $spec['summary'] ) ) {
			$why['summary'] = 'summary has a [[placeholder]]';
		}
		foreach ( $spec['seo'] as $f => $v ) {
			if ( self::has_placeholder( $v ) ) {
				$why['seo'] = 'seo ' . $f . ' has a [[placeholder]]';
			}
		}
		// The status: placeholders keep it a draft; an admin's draft stays one; otherwise as the brief says (new = publish).
		$want_status = $why ? 'draft' : ( '' !== $spec['status'] ? $spec['status'] : ( $cur ? $cur->post_status : 'publish' ) );
		if ( $cur && ! $why && '' === $spec['status'] ) {
			$admin_drafted = $row_l && 'publish' === ( $row_l['status'] ?? '' ) && 'draft' === $cur->post_status;
			$unowned_draft = ! $row_l && 'draft' === $cur->post_status;
			$want_status = $admin_drafted || $unowned_draft ? 'draft' : ( 'draft' === $cur->post_status ? 'publish' : $cur->post_status );
		}
		if ( $why ) {
			self::copy_needed( $job, [ 'type' => $type, 'key' => $spec['key'], 'title' => $spec['title'], 'needs' => $why ] );
		}
		$parent_id = 0;
		if ( 'area' === $type && '' !== $spec['parent_key'] ) {
			$parent_id = self::entry_by_key( $job, 'area', $spec['parent_key'] );
		}
		$svc_ids = [];
		if ( 'area' === $type ) {
			foreach ( $spec['services'] as $sk ) {
				$sid = self::entry_by_key( $job, 'service', $sk );
				if ( $sid ) {
					$svc_ids[] = $sid;
				} elseif ( ! $dry ) {
					self::warn( $job, 'area ' . $spec['key'] . ': service ' . $sk . ' does not exist yet.' );
				}
			}
		}
		$hash = self::spec_hash( $spec, [ 'image' ] );
		$diffs = [];
		if ( $cur ) {
			$now = self::entry_now( $id, $type );
			foreach ( [ 'title', 'slug' ] as $f ) {
				if ( $now[ $f ] !== $spec[ $f ] ) {
					$diffs[] = $f;
				}
			}
			if ( $now['status'] !== $want_status ) {
				$diffs[] = 'status';
			}
			if ( '' !== $spec['summary'] && $now['summary'] !== $spec['summary'] ) {
				$diffs[] = 'summary';
			}
			if ( $now['order'] !== $spec['order'] ) {
				$diffs[] = 'order';
			}
			if ( '' !== $spec['icon'] && $now['icon'] !== $spec['icon'] ) {
				$diffs[] = 'icon';
			}
			if ( $spec['faqs'] && $now['faqs'] !== $spec['faqs'] ) {
				$diffs[] = 'faqs';
			}
			foreach ( $spec['seo'] as $f => $v ) {
				if ( ( $now['seo'][ $f ] ?? '' ) !== $v ) {
					$diffs[] = 'seo.' . $f;
				}
			}
			if ( 'service' === $type ) {
				$want = array_map( 'sanitize_title', $spec['categories'] );
				sort( $want );
				if ( $want && $now['categories'] !== $want ) {
					$diffs[] = 'categories';
				}
			} else {
				if ( $now['parent_key'] !== $spec['parent_key'] ) {
					$diffs[] = 'parent';
				}
				if ( $spec['has_services'] ) {
					$want = $spec['services'];
					sort( $want );
					if ( $now['services'] !== $want ) {
						$diffs[] = 'services';
					}
				}
			}
			if ( ! $keep_content && '' !== trim( $spec['markdown'] ) && (string) get_post_meta( $id, '_lb_brief_hash', true ) !== $hash ) {
				$diffs[] = 'text';
			}
		}
		$title = $spec['title'];
		if ( $cur && ! $diffs ) {
			self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $title, 'action' => 'keep' ] );
			$job['state'][ $stateKey ][ $spec['key'] ] = $id;
			return;
		}
		$action = $cur ? 'update' : 'create';
		if ( $dry ) {
			self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $title, 'action' => $action, 'detail' => $cur ? implode( ', ', $diffs ) : $want_status ] );
			$job['state'][ $stateKey ][ $spec['key'] ] = $id;
			return;
		}
		$in = [ 'title' => $spec['title'], 'slug' => $spec['slug'], 'key' => $spec['key'], 'order' => $spec['order'], 'status' => $want_status ];
		if ( '' !== $spec['summary'] ) {
			$in['summary'] = $spec['summary'];
		}
		if ( '' !== $spec['icon'] ) {
			$in['icon'] = $spec['icon'];
		}
		if ( $spec['seo'] ) {
			$in['seo'] = $spec['seo'];
		}
		if ( ! $keep_content && '' !== trim( $spec['markdown'] ) ) {
			$in['markdown'] = $spec['markdown'];
		}
		if ( 'service' === $type ) {
			if ( $spec['categories'] ) {
				$in['categories'] = $spec['categories'];
			}
			$r = Entries::add_service( [ 'items' => [ $in ] ] );
		} else {
			$in['parent'] = $parent_id ? (string) $parent_id : 'top';
			if ( $spec['has_services'] ) {
				$in['services'] = array_map( 'strval', $svc_ids );
			}
			$r = Entries::add_area( [ 'items' => [ $in ] ] );
		}
		if ( is_wp_error( $r ) ) {
			self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $title, 'action' => 'error', 'detail' => $r->get_error_message() ] );
			return;
		}
		$e = $r['entries'][0] ?? null;
		if ( ! $e ) {
			self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $title, 'action' => 'error', 'detail' => (string) ( $r['errors'][0]['error'] ?? 'not written' ) ] );
			return;
		}
		$nid = (int) $e['id'];
		foreach ( (array) ( $e['warnings'] ?? [] ) as $w ) {
			self::warn( $job, $type . ' ' . $spec['key'] . ': ' . $w );
		}
		if ( $spec['faqs'] ) {
			update_post_meta( $nid, Compat::META_FAQ, $spec['faqs'] );
		}
		update_post_meta( $nid, '_lb_brief', '1' );
		if ( ! $keep_content ) { // content "keep": the stored text is not touched and its hash stays as it was.
			update_post_meta( $nid, '_lb_brief_hash', $hash );
		}
		Ledger::record( $lkey, $pt, $nid, self::state_hash( $nid ), (string) get_post_status( $nid ) );
		$job['state'][ $stateKey ][ $spec['key'] ] = $nid;
		self::add( $job, $rows, [ 'type' => $type, 'key' => $spec['key'], 'title' => $title, 'action' => $action, 'detail' => $cur ? implode( ', ', $diffs ) : (string) get_post_status( $nid ), 'id' => $nid ] );
	}

	/** Entries built from an earlier brief that this brief no longer lists. */
	private static function prune_entries( array &$job, array &$rows ): void {
		$dry = (bool) $job['dry_run'];
		$keep = [];
		foreach ( [ 'services', 'areas' ] as $t ) {
			foreach ( $job['brief']['entries'][ $t ] as $e ) {
				$keep[ $e['key'] ] = true;
			}
		}
		$count = 0;
		foreach ( [ 'service' => Entries::SERVICE, 'area' => Entries::AREA ] as $type => $pt ) {
			$ids = get_posts( [ 'post_type' => $pt, 'post_status' => [ 'publish', 'draft' ], 'meta_key' => '_lb_brief', 'meta_value' => '1', 'numberposts' => 300, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $ids as $id ) {
				$k = self::key_of( (int) $id );
				if ( '' === $k || isset( $keep[ $k ] ) ) {
					continue;
				}
				$count++;
				if ( 'publish' !== get_post_status( (int) $id ) ) {
					continue;
				}
				if ( ! $dry && ! empty( $job['prune'] ) ) {
					wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'draft' ] );
					Ledger::mark_pruned( self::lkey( 'area' === $type ? 'area' : 'svc', $k ) );
				}
				self::add( $job, $rows, [ 'type' => $type, 'key' => $k, 'title' => get_the_title( (int) $id ), 'action' => 'prune', 'detail' => $dry || empty( $job['prune'] ) ? 'would become a draft (prune: true)' : 'drafted' ] );
			}
		}
		if ( ! $dry && empty( $job['prune'] ) && $count ) {
			$job['report']['notes'][] = $count . ' entries built from an earlier brief are not in this one; pass prune: true to draft them.';
		}
	}

	/* ------------------------------------------------------------------ media */

	private static function phase_media( array &$job, float $deadline ): array {
		$dry = (bool) $job['dry_run'];
		$media = $job['brief']['media'];
		$rows = [];
		$i = (int) $job['cursor'];
		$n = 0;
		for ( ; $i < count( $media ); $i++ ) {
			if ( $n >= self::IMAGES || ( $n > 0 && microtime( true ) >= $deadline ) ) {
				return [ 'done' => false, 'cursor' => $i, 'rows' => $rows ];
			}
			$n++;
			$m = $media[ $i ];
			try {
				$src = esc_url_raw( $m['url'] );
				$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lb_src', 'meta_value' => $src, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$id = $ids ? (int) $ids[0] : 0;
				if ( $id ) {
					$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
					$act = ( '' !== $m['alt'] && $alt !== $m['alt'] ) || self::attachment_by_key( $m['key'] ) !== $id ? 'update' : 'keep';
					if ( ! $dry && 'update' === $act ) {
						'' !== $m['alt'] ? update_post_meta( $id, '_wp_attachment_image_alt', $m['alt'] ) : null;
						$old = self::attachment_by_key( $m['key'] );
						if ( $old && $old !== $id ) {
							delete_post_meta( $old, '_lb_media_key' );
						}
						update_post_meta( $id, '_lb_media_key', $m['key'] );
					}
					$job['state']['media'][ $m['key'] ] = $id;
					self::add( $job, $rows, [ 'type' => 'media', 'key' => $m['key'], 'action' => $act, 'id' => $id ] );
					continue;
				}
				if ( $dry ) {
					self::add( $job, $rows, [ 'type' => 'media', 'key' => $m['key'], 'action' => 'create', 'detail' => $m['url'] ] );
					continue;
				}
				$r = Abilities::sideload_media( [ 'url' => $m['url'], 'title' => $m['key'], 'alt' => $m['alt'] ] );
				if ( is_wp_error( $r ) ) {
					self::warn( $job, 'media ' . $m['key'] . ': ' . $r->get_error_message() );
					self::add( $job, $rows, [ 'type' => 'media', 'key' => $m['key'], 'action' => 'error', 'detail' => $r->get_error_message() ] );
					continue;
				}
				$old = self::attachment_by_key( $m['key'] );
				if ( $old && $old !== (int) $r['id'] ) {
					delete_post_meta( $old, '_lb_media_key' );
				}
				update_post_meta( (int) $r['id'], '_lb_media_key', $m['key'] );
				$job['state']['media'][ $m['key'] ] = (int) $r['id'];
				self::add( $job, $rows, [ 'type' => 'media', 'key' => $m['key'], 'action' => 'create', 'id' => (int) $r['id'] ] );
			} catch ( \Throwable $e ) {
				self::add( $job, $rows, [ 'type' => 'media', 'key' => $m['key'], 'action' => 'error', 'detail' => $e->getMessage() ] );
			}
		}
		self::bind_media( $job, $rows );
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/** Bind imported images to the logo, the site icon, entries and pages. */
	private static function bind_media( array &$job, array &$rows ): void {
		$dry = (bool) $job['dry_run'];
		$b = $job['brief'];
		$logo = [ 'logo' => (string) ( $b['brand']['logo'] ?? '' ), 'logo_inverse' => (string) ( $b['brand']['logo_inverse'] ?? '' ), 'site_icon' => (string) ( $b['brand']['site_icon'] ?? '' ) ];
		foreach ( $b['media'] as $m ) {
			if ( in_array( $m['use'], [ 'logo', 'logo_inverse', 'site_icon' ], true ) && '' === $logo[ $m['use'] ] ) {
				$logo[ $m['use'] ] = $m['key'];
			}
		}
		foreach ( $logo as $use => $key ) {
			if ( '' === $key ) {
				continue;
			}
			$id = self::media_id( $job, $key );
			if ( ! $id ) {
				if ( ! $dry ) {
					self::warn( $job, 'brand.' . $use . ': the image "' . $key . '" is not imported.' );
				}
				self::add( $job, $rows, [ 'type' => 'brand', 'key' => $use, 'action' => $dry ? 'update' : 'error', 'detail' => 'media ' . $key ] );
				continue;
			}
			$saved = (array) get_option( Company::OPT, [] );
			$cur = 'logo' === $use ? (int) get_theme_mod( 'custom_logo' ) : ( 'site_icon' === $use ? (int) get_option( 'site_icon' ) : (int) ( $saved['logo_inverse'] ?? 0 ) );
			if ( $cur === $id ) {
				self::add( $job, $rows, [ 'type' => 'brand', 'key' => $use, 'action' => 'keep' ] );
				continue;
			}
			if ( ! $dry ) {
				if ( 'logo' === $use ) {
					Design::set_brand( [ 'logo_attachment_id' => $id ] );
				} elseif ( 'site_icon' === $use ) {
					update_option( 'site_icon', $id );
					Company::update( [ 'site_icon' => (string) $id ] );
				} else {
					Company::update( [ 'logo_inverse' => (string) $id ] );
				}
			}
			self::add( $job, $rows, [ 'type' => 'brand', 'key' => $use, 'action' => 'update', 'id' => $id ] );
		}
		// Entry images and featured images.
		$feature = [];
		foreach ( [ 'service' => 'services', 'area' => 'areas' ] as $type => $t ) {
			foreach ( $b['entries'][ $t ] as $e ) {
				if ( '' !== $e['image'] ) {
					$feature[ $e['key'] ] = [ $type, $e['image'] ];
				}
			}
		}
		foreach ( $b['media'] as $m ) {
			if ( 0 === strpos( $m['use'], 'featured:' ) ) {
				$feature[ substr( $m['use'], 9 ) ] = [ 'page', $m['key'] ];
			}
		}
		foreach ( $feature as $target => [ $type, $mkey ] ) {
			$pid = 0;
			if ( 'service' === $type ) {
				$pid = (int) ( $job['state']['services'][ $target ] ?? Entries::find_by_key( Entries::SERVICE, $target ) );
			} elseif ( 'area' === $type ) {
				$pid = (int) ( $job['state']['areas'][ $target ] ?? Entries::find_by_key( Entries::AREA, $target ) );
			} else {
				$pid = self::page_id( $job, $target );
				if ( ! $pid ) {
					$pid = (int) ( $job['state']['services'][ $target ] ?? Entries::find_by_key( Entries::SERVICE, $target ) ) ?: (int) ( $job['state']['areas'][ $target ] ?? Entries::find_by_key( Entries::AREA, $target ) );
				}
			}
			$mid = self::media_id( $job, $mkey );
			if ( ! $pid || ! $mid ) {
				self::add( $job, $rows, [ 'type' => 'image', 'key' => $target, 'action' => $dry ? 'update' : 'skip', 'detail' => $pid ? 'image not imported' : 'target not built' ] );
				continue;
			}
			if ( (int) get_post_thumbnail_id( $pid ) === $mid ) {
				self::add( $job, $rows, [ 'type' => 'image', 'key' => $target, 'action' => 'keep' ] );
				continue;
			}
			$dry || set_post_thumbnail( $pid, $mid );
			self::add( $job, $rows, [ 'type' => 'image', 'key' => $target, 'action' => 'update', 'id' => $mid ] );
		}
	}

	/* ------------------------------------------------------------------ pages */

	/** Does a recipe-derived section belong to the shared hero (placed once by the chrome)? */
	private static function is_hero_section( string $slug ): bool {
		return 'breadcrumb-bar' === $slug || 'hero' === ( Plugin::pattern_meta( $slug )['slot'] ?? '' );
	}

	/**
	 * The content of one page spec.
	 *
	 * @return array{content:string,type:string,sections:int,warnings:string[]}
	 */
	private static function build_page( array $spec, array $vars, bool $strip, array $job ): array {
		$sections = $spec['sections'];
		$recipe = Content::recipes()[ $spec['recipe'] ] ?? null;
		$md = (string) $spec['markdown'];
		if ( ! $sections && $recipe ) {
			foreach ( $recipe['sections'] as $rs ) {
				if ( '{markdown}' === $rs ) {
					'' !== $md ? $sections[] = [ 'markdown' => $md ] : null;
				} elseif ( ! ( $strip && self::is_hero_section( $rs ) ) ) {
					$sections[] = 'service-body' === $rs && '' !== $md ? [ 'pattern' => $rs, 'markdown' => $md ] : [ 'pattern' => $rs ];
				}
			}
		} elseif ( ! $sections && '' !== $md ) {
			$sections[] = [ 'markdown' => $md ];
		}
		$has_md = false;
		foreach ( $sections as $s ) {
			$has_md = $has_md || ! empty( $s['markdown'] );
		}
		$layout = $spec['layout'];
		$type = $spec['type'] ?: ( $recipe['type'] ?? ( $spec['front'] ? 'landing' : ( $has_md ? ( count( $sections ) > 1 ? 'wide' : 'text' ) : 'landing' ) ) );
		$layout['type'] = isset( Content::TYPES[ $type ] ) ? $type : 'landing';
		$chunks = [];
		$warnings = [];
		$used = [];
		$legal_notice = ! empty( $spec['legal'] ) && ! $spec['legal']['reviewed'];
		if ( $legal_notice ) {
			$chunks[] = Launch_Legal::notice_block();
		}
		foreach ( $sections as $sec ) {
			if ( empty( $sec['pattern'] ) && ! empty( $sec['markdown'] ) ) {
				$chunks[] = Content::from_markdown( (string) $sec['markdown'], 'wide' === $layout['type'] );
				continue;
			}
			if ( ! empty( $sec['use'] ) ) {
				$bid = Blueprint::find_synced( (string) $sec['use'] );
				$bid ? $chunks[] = '<!-- wp:block {"ref":' . $bid . '} /-->' : $warnings[] = 'unknown synced key ' . $sec['use'];
				continue;
			}
			$slots = (array) ( $sec['slots'] ?? [] );
			foreach ( $slots as $sk => $sv ) {
				if ( is_string( $sv ) && 0 === strpos( $sv, 'media:' ) ) { // media:<key> becomes the image URL.
					$mid = self::media_id( $job, substr( $sv, 6 ) );
					$slots[ $sk ] = $mid ? (string) wp_get_attachment_url( $mid ) : $sv;
				}
			}
			$b = Patterns::build_pattern( (string) $sec['pattern'], $slots, array_merge( $spec['replace'], (array) ( $sec['replace'] ?? [] ) ), $vars, (string) ( $sec['markdown'] ?? '' ) );
			if ( is_wp_error( $b ) ) {
				$warnings[] = $sec['pattern'] . ': ' . $b->get_error_message();
				continue;
			}
			$chunks[] = $b['content'];
			$warn = $b['warnings'];
			foreach ( array_keys( $spec['replace'] ) as $pk ) {
				$at = array_search( 'No slot has the text: ' . mb_substr( (string) $pk, 0, 70 ), $warn, true );
				if ( false !== $at ) {
					unset( $warn[ $at ] );
				} else {
					$used[ $pk ] = true;
				}
			}
			foreach ( $warn as $w ) {
				$warnings[] = $sec['pattern'] . ': ' . $w;
			}
		}
		foreach ( array_diff( array_keys( $spec['replace'] ), array_keys( $used ) ) as $pk ) {
			$warnings[] = 'replace text matched nothing on the page: ' . mb_substr( (string) $pk, 0, 70 );
		}
		return [ 'content' => implode( "\n\n", $chunks ), 'type' => $layout['type'], 'layout' => $layout, 'sections' => count( $chunks ), 'warnings' => $warnings ];
	}

	/** The role's current state of a page, in the vocabulary of the brief. */
	public static function role_of( int $id ): string {
		$why = Roles::explain_page( $id )[1];
		if ( 'hub' === $why ) {
			$h = Roles::hubs();
			return (int) $h['services'] === $id ? 'services_hub' : 'areas_hub';
		}
		if ( 'category' === $why ) {
			return 'category';
		}
		if ( 'front_page' === $why ) {
			return 'home';
		}
		$meta = (string) get_post_meta( $id, Roles::META, true );
		return in_array( $meta, Roles::PAGE_ROLES, true ) ? $meta : 'page';
	}

	private static function page_now( int $id ): array {
		$p = get_post( $id );
		$seo = [];
		foreach ( [ 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'keyword' => 'rank_math_focus_keyword' ] as $f => $mk ) {
			$v = (string) get_post_meta( $id, $mk, true );
			if ( '' !== $v ) {
				$seo[ $f ] = $v;
			}
		}
		$rob = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rob ) && $rob ) {
			$seo['robots'] = Compat::robots_list( $rob );
		}
		return [ 'title' => $p->post_title, 'slug' => self::slug_of( $p ), 'status' => $p->post_status, 'parent_key' => self::key_of( (int) $p->post_parent ), 'order' => (int) $p->menu_order, 'role' => self::role_of( $id ), 'seo' => $seo, 'front' => (int) get_option( 'page_on_front' ) === $id && 'page' === get_option( 'show_on_front' ) ];
	}

	private static function phase_pages( array &$job, float $deadline ): array {
		$dry = (bool) $job['dry_run'];
		$pages = $job['brief']['sitemap']['pages'];
		$rows = [];
		$vars = Blueprint::business_vars( (array) $job['brief']['business'] );
		$pref = (string) ( $job['brief']['chrome']['mode'] ?? '' );
		$strip = 'elements' === ( in_array( $pref, [ 'elements', 'patterns' ], true ) ? $pref : Chrome::mode() ); // Elements mode: the shared hero is placed once, so recipe pages carry none.
		$forms = $dry && post_type_exists( 'kadence_form' ) ? get_posts( [ 'post_type' => 'kadence_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) : null;
		$i = (int) $job['cursor'];
		$n = 0;
		for ( ; $i < count( $pages ); $i++ ) {
			if ( $n >= self::PAGES || ( $n > 0 && microtime( true ) >= $deadline ) ) {
				self::drop_new_forms( $forms );
				return [ 'done' => false, 'cursor' => $i, 'rows' => $rows ];
			}
			$n++;
			try {
				self::do_page( $job, $rows, $pages[ $i ], $vars, $strip );
			} catch ( \Throwable $e ) {
				self::add( $job, $rows, [ 'type' => 'page', 'key' => $pages[ $i ]['key'], 'title' => $pages[ $i ]['title'], 'action' => 'error', 'detail' => $e->getMessage() ] );
				self::warn( $job, 'page ' . $pages[ $i ]['key'] . ': ' . $e->getMessage() );
			}
		}
		self::prune_pages( $job, $rows );
		self::drop_new_forms( $forms );
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/** A dry run builds the pages to read their copy, and building a form pattern makes its Kadence form post: remove the ones it made. */
	private static function drop_new_forms( ?array $before ): void {
		if ( null === $before ) {
			return;
		}
		foreach ( get_posts( [ 'post_type' => 'kadence_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) as $fid ) {
			if ( ! in_array( $fid, $before, true ) ) {
				wp_delete_post( (int) $fid, true );
			}
		}
	}

	private static function do_page( array &$job, array &$rows, array $spec, array $vars, bool $strip ): void {
		$dry = (bool) $job['dry_run'];
		$id = self::find_page( $spec );
		$cur = $id ? get_post( $id ) : null;
		$lkey = self::lkey( 'page', $spec['key'] );
		$row_l = $id ? Ledger::get( $lkey ) : null;
		$was_edited = $cur && $row_l && Ledger::is_edited( $lkey, self::state_hash( $id ) );
		if ( $was_edited && empty( $job['force'] ) ) {
			$job['state']['pages'][ $spec['key'] ] = $id;
			self::add( $job, $rows, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $spec['title'], 'action' => 'kept_edits', 'detail' => 'edited since the last build' ] );
			return;
		}
		$vhash = md5( (string) wp_json_encode( self::canon( $vars ) ) ) . ( $strip ? 's' : 'n' );
		$hash = self::spec_hash( $spec, [ 'order', 'seo', 'status', 'front', 'role', 'category' ] ) . $vhash;
		$build = 'build' === $spec['content'];
		$rebuild = $build && ( ! $cur || $was_edited || (string) get_post_meta( $id, '_lb_brief_hash', true ) !== $hash ); // force: an edited page is built again even when the brief has not changed
		$built = null;
		$reasons = [];
		if ( $rebuild ) {
			$built = self::build_page( $spec, $vars, $strip, $job );
			foreach ( $built['warnings'] as $w ) {
				self::warn( $job, 'page ' . $spec['key'] . ': ' . $w );
			}
			$reasons = '' === trim( $built['content'] ) ? [ 'empty' => 'no content: give sections, a recipe or markdown' ] : self::copy_reasons( $built['content'] );
		} elseif ( $cur && $build ) {
			$reasons = self::copy_reasons( (string) $cur->post_content ); // Unchanged brief: what is stored is what was built.
		}
		foreach ( $spec['seo'] as $f => $v ) {
			if ( self::has_placeholder( $v ) ) {
				$reasons['seo'] = 'seo ' . $f . ' has a [[placeholder]]';
			}
		}
		if ( self::has_placeholder( $spec['title'] ) ) {
			$reasons['title'] = 'the title has a [[placeholder]]';
		}
		$review = ! empty( $spec['legal'] ) && ! $spec['legal']['reviewed'];
		if ( $review ) {
			$reasons['review'] = 'generated legal text: the client must review it before it is published';
		}
		if ( 'keep' === $spec['content'] && ! $cur ) {
			$reasons['empty'] = 'no content: give sections, a recipe or markdown';
		}
		if ( $reasons ) {
			self::copy_needed( $job, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $spec['title'], 'needs' => $reasons ] );
		}
		// The status: copy still to write or a review pending = draft; an admin's draft stays one; otherwise as the brief says.
		$want = $reasons ? 'draft' : ( '' !== $spec['status'] ? $spec['status'] : ( $cur ? $cur->post_status : 'publish' ) );
		if ( $cur && ! $reasons && '' === $spec['status'] ) {
			$admin_drafted = $row_l && 'publish' === ( $row_l['status'] ?? '' ) && 'draft' === $cur->post_status;
			$unowned_draft = ! $row_l && 'draft' === $cur->post_status;
			$want = $admin_drafted || $unowned_draft ? 'draft' : ( 'draft' === $cur->post_status ? 'publish' : $cur->post_status );
		}
		if ( $cur && $reasons && 'publish' !== $cur->post_status && ! in_array( $cur->post_status, [ 'draft' ], true ) ) {
			$want = $cur->post_status;
		}
		$parent = '' !== $spec['parent_key'] ? self::page_id( $job, $spec['parent_key'] ) : 0;
		$diffs = [];
		if ( $cur ) {
			$now = self::page_now( $id );
			foreach ( [ 'title', 'slug' ] as $f ) {
				if ( $now[ $f ] !== $spec[ $f ] ) {
					$diffs[] = $f;
				}
			}
			if ( $now['status'] !== $want ) {
				$diffs[] = 'status';
			}
			if ( $now['parent_key'] !== $spec['parent_key'] ) {
				$diffs[] = 'parent';
			}
			if ( $now['order'] !== $spec['order'] ) {
				$diffs[] = 'order';
			}
			foreach ( $spec['seo'] as $f => $v ) {
				if ( ( $now['seo'][ $f ] ?? null ) !== $v ) {
					$diffs[] = 'seo.' . $f;
				}
			}
			if ( '' !== $spec['role'] && $now['role'] !== $spec['role'] && ! ( 'page' === $spec['role'] && 'page' === $now['role'] ) ) {
				$diffs[] = 'role';
			}
			if ( $spec['front'] && ! $now['front'] ) {
				$diffs[] = 'front page';
			}
			if ( $rebuild ) {
				$diffs[] = 'content';
			}
		}
		$title = $spec['title'];
		if ( $cur && ! $diffs ) {
			$job['state']['pages'][ $spec['key'] ] = $id;
			self::add( $job, $rows, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $title, 'action' => 'keep' ] );
			return;
		}
		$action = $cur ? 'update' : 'create';
		if ( $dry ) {
			$job['state']['pages'][ $spec['key'] ] = $id;
			self::add( $job, $rows, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $title, 'action' => $action, 'detail' => $cur ? implode( ', ', $diffs ) : $want ] );
			return;
		}
		$args = [ 'post_type' => 'page', 'post_title' => $spec['title'], 'post_name' => $spec['slug'], 'post_status' => $want, 'post_parent' => $parent, 'menu_order' => $spec['order'] ];
		if ( null !== $built ) {
			$args['post_content'] = wp_slash( $built['content'] );
		}
		if ( $cur ) {
			$args['ID'] = $id;
			$res = wp_update_post( $args, true );
		} else {
			$res = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $res ) ) {
			self::add( $job, $rows, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $title, 'action' => 'error', 'detail' => $res->get_error_message() ] );
			return;
		}
		$id = (int) $res;
		update_post_meta( $id, '_lb_key', $spec['key'] );
		update_post_meta( $id, '_lb_brief', '1' );
		if ( null !== $built ) {
			Content::set_layout( $id, $built['layout'] );
			update_post_meta( $id, '_lb_brief_hash', $hash );
		}
		if ( $spec['seo'] ) {
			Compat::write_seo_meta( $id, $spec['seo'] );
		}
		$job['state']['pages'][ $spec['key'] ] = $id;
		// Roles, hubs and category pages go through the role tool, which keeps the hubs option and the term map right.
		self::apply_role( $job, $rows, $id, $spec, $cur );
		if ( $spec['front'] ) {
			$front = (int) get_option( 'page_on_front' );
			$front_live = $front && 'publish' === get_post_status( $front );
			if ( 'publish' === get_post_status( $id ) || ! $front_live ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			} else {
				self::warn( $job, 'page ' . $spec['key'] . ' is the home page but still a draft: the live front page was left as it is until this one is published (run the build again).' );
			}
		}
		Ledger::record( $lkey, 'page', $id, self::state_hash( $id ), (string) get_post_status( $id ) );
		self::add( $job, $rows, [ 'type' => 'page', 'key' => $spec['key'], 'title' => $title, 'action' => $action, 'detail' => $cur ? implode( ', ', $diffs ) : (string) get_post_status( $id ), 'id' => $id ] );
	}

	/** Give a built page the role its spec asks for (and the service category it leads, and the hub it is). */
	private static function apply_role( array &$job, array &$rows, int $id, array $spec, ?\WP_Post $before ): void {
		unset( $rows, $before );
		$role = $spec['role'];
		if ( '' === $role || 'home' === $role ) {
			return;
		}
		$now = self::role_of( $id );
		if ( 'page' === $role ) {
			if ( ! in_array( $now, [ 'page', 'home' ], true ) ) {
				Roles::set_role_tool( [ 'post_id' => $id, 'role' => 'page' ] ); // Leaves a hub, a category page or another explicit role.
			}
			return;
		}
		if ( $now === $role && 'category' !== $role ) {
			return;
		}
		$a = [ 'post_id' => $id, 'role' => $role ];
		if ( 'category' === $role ) {
			$a['term'] = sanitize_title( $spec['category'] );
			$term = get_term_by( 'slug', $a['term'], Entries::CAT );
			if ( $term && (int) get_term_meta( $term->term_id, '_lb_page', true ) === $id && 'category' === $now ) {
				return;
			}
		}
		$r = Roles::set_role_tool( $a );
		if ( is_wp_error( $r ) ) {
			self::warn( $job, 'page ' . $spec['key'] . ' role ' . $role . ': ' . $r->get_error_message() );
		}
	}

	/** Pages built from an earlier brief that this brief no longer lists. */
	private static function prune_pages( array &$job, array &$rows ): void {
		$dry = (bool) $job['dry_run'];
		$keep = [];
		foreach ( $job['brief']['sitemap']['pages'] as $pg ) {
			$keep[ $pg['key'] ] = true;
		}
		$count = 0;
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft' ], 'meta_key' => '_lb_brief', 'meta_value' => '1', 'numberposts' => 300, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $ids as $id ) {
			$k = self::key_of( (int) $id );
			if ( '' === $k || isset( $keep[ $k ] ) || 'publish' !== get_post_status( (int) $id ) ) {
				continue;
			}
			$count++;
			if ( ! $dry && ! empty( $job['prune'] ) ) {
				wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'draft' ] );
				Ledger::mark_pruned( self::lkey( 'page', $k ) );
			}
			self::add( $job, $rows, [ 'type' => 'page', 'key' => $k, 'title' => get_the_title( (int) $id ), 'action' => 'prune', 'detail' => $dry || empty( $job['prune'] ) ? 'would become a draft (prune: true)' : 'drafted' ] );
		}
		if ( ! $dry && empty( $job['prune'] ) && $count ) {
			$job['report']['notes'][] = $count . ' pages built from an earlier brief are not in this one; pass prune: true to draft them.';
		}
	}

	/* ------------------------------------------------------------------ chrome */

	private static function phase_chrome( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$ch = $job['brief']['chrome'];
		$rows = [];
		$args = [ 'map' => (array) ( $ch['map'] ?? [] ) ];
		if ( isset( $ch['mode'] ) ) {
			$args['mode'] = $ch['mode'];
		}
		if ( $dry ) {
			$args['dry_run'] = true;
		} else {
			$args['apply'] = true;
			if ( ! empty( $job['force'] ) ) {
				$args['force'] = [ 'all' ];
				$args['confirm_all'] = true;
			}
		}
		$r = Chrome::set_tool( $args );
		if ( is_wp_error( $r ) ) {
			self::warn( $job, 'chrome: ' . $r->get_error_message() );
			self::add( $job, $rows, [ 'type' => 'chrome', 'key' => 'chrome', 'action' => 'error', 'detail' => $r->get_error_message() ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		$rep = $dry ? (array) ( $r['plan'] ?? [] ) : (array) ( $r['applied'] ?? [] );
		$changed = 0;
		foreach ( (array) ( $rep['groups'] ?? [] ) as $g ) {
			$acts = [];
			foreach ( [ 'store', 'element' ] as $w ) {
				if ( ! empty( $g[ $w ]['action'] ) ) {
					$acts[] = $g[ $w ]['action'];
				}
			}
			$act = in_array( 'create', $acts, true ) ? 'create' : ( array_intersect( [ 'update', 'republish' ], $acts ) ? 'update' : ( in_array( 'kept_edits', $acts, true ) ? 'kept_edits' : 'keep' ) );
			$changed += in_array( $act, [ 'create', 'update' ], true ) ? 1 : 0;
			self::add( $job, $rows, [ 'type' => 'chrome', 'key' => (string) ( $g['key'] ?? '' ), 'action' => $act, 'detail' => '' !== (string) ( $g['title'] ?? '' ) ? (string) $g['title'] : ( $g['slot'] ?? '' ) . ': ' . implode( ', ', (array) ( $g['roles'] ?? [] ) ) ] ); // The plain name; the key stays the group key.
		}
		foreach ( (array) ( $rep['compile_failed'] ?? [] ) as $f ) {
			self::warn( $job, 'chrome ' . ( $f['slot'] ?? '' ) . ' (' . implode( ', ', (array) ( $f['roles'] ?? [] ) ) . '): ' . ( $f['error'] ?? 'compile failed' ) );
		}
		if ( ! empty( $rep['compile_failed'] ) ) {
			self::add( $job, $rows, [ 'type' => 'chrome', 'key' => 'compile_failed', 'action' => 'info', 'detail' => count( $rep['compile_failed'] ) . ' design(s) did not compile (see warnings)' ] );
		}
		if ( ! empty( $ch['map'] ) || isset( $ch['mode'] ) ) {
			$stored = get_option( Chrome::OPT_MAP, [] );
			self::add( $job, $rows, [ 'type' => 'chrome', 'key' => 'map', 'action' => $dry && ( self::map_differs( (array) $stored, (array) ( $ch['map'] ?? [] ) ) || ( isset( $ch['mode'] ) && (string) get_option( Chrome::OPT_MODE, 'auto' ) !== $ch['mode'] ) ) ? 'update' : 'keep' ] );
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/** Does a partial chrome map say something the stored map does not? */
	private static function map_differs( array $stored, array $want ): bool {
		foreach ( $want as $slot => $v ) {
			$cur = $stored[ $slot ] ?? null;
			if ( is_array( $v ) && is_array( $cur ) && ! empty( $v ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) && 'crumbs' !== $slot && ! isset( $v['pattern'] ) && ! isset( $v['sections'] ) && ! isset( $v['synced'] ) && ! isset( $v['adopt'] ) ) {
				foreach ( $v as $role => $d ) {
					if ( Ledger::hash_value( $cur[ $role ] ?? null ) !== Ledger::hash_value( $d ) ) {
						return true;
					}
				}
				continue;
			}
			if ( Ledger::hash_value( $cur ) !== Ledger::hash_value( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ menus */

	/** Menu items as nav items: page keys become post ids and permalinks. */
	private static function nav_items( array $items, array $job, array &$sig ): array {
		$out = [];
		foreach ( $items as $it ) {
			$n = [ 'label' => $it['label'] ];
			if ( isset( $it['page'] ) ) {
				$pid = self::page_id( $job, $it['page'] );
				$st = $pid ? get_post_status( $pid ) : 'missing';
				$sig[] = $it['page'] . ':' . $st;
				if ( $pid ) {
					$n['post_id'] = $pid;
					$n['url'] = (string) get_permalink( $pid );
				}
			}
			foreach ( [ 'url', 'auto', 'category', 'parent', 'limit' ] as $f ) {
				if ( isset( $it[ $f ] ) ) {
					$n[ $f ] = $it[ $f ];
				}
			}
			if ( ! empty( $it['children'] ) ) {
				$n['children'] = self::nav_items( $it['children'], $job, $sig );
			}
			$out[] = $n;
		}
		return $out;
	}

	/** The main menu a brief gets when it names none: top-level content pages, categories with their services, the areas hub. */
	private static function default_main_menu( array $b ): array {
		$out = [];
		foreach ( $b['sitemap']['pages'] as $pg ) {
			if ( '' !== $pg['parent_key'] || in_array( $pg['role'], [ 'home', 'legal', 'thanks', 'conversion' ], true ) ) {
				continue;
			}
			$it = [ 'label' => $pg['title'], 'page' => $pg['key'] ];
			if ( 'category' === $pg['role'] && '' !== $pg['category'] ) {
				$it['auto'] = 'services';
				$it['category'] = $pg['category'];
			} elseif ( 'areas_hub' === $pg['role'] ) {
				$it['auto'] = 'areas';
				$it['limit'] = 8;
			} elseif ( 'services_hub' === $pg['role'] ) {
				$it['auto'] = 'services';
			}
			$out[] = $it;
		}
		return $out;
	}

	private static function nav_post( string $key ): int {
		$ids = get_posts( [ 'post_type' => 'kadence_navigation', 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => 'nav-' . $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : 0;
	}

	private static function phase_menus( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$b = $job['brief'];
		$rows = [];
		if ( ! post_type_exists( 'kadence_navigation' ) ) {
			self::add( $job, $rows, [ 'type' => 'menu', 'key' => 'menus', 'action' => 'skip', 'detail' => 'Kadence navigation is not available (Kadence Blocks)' ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		$main = $b['menus']['main'] ?? null;
		if ( null === $main ) {
			if ( self::nav_post( 'main-menu' ) ) {
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => 'main', 'action' => 'keep', 'detail' => 'the brief names no main menu: the existing one is kept' ] );
			} else {
				$main = self::default_main_menu( $b );
			}
		}
		$explicit = (string) ( $b['menus']['header'] ?? '' );
		if ( null !== $main && $main ) {
			$sig = [];
			$items = self::nav_items( $main, $job, $sig );
			$header = $explicit;
			if ( '' === $header ) { // The header design follows the style preset and how long the menu is.
				$len = 0;
				foreach ( $main as $it ) {
					$len += mb_strlen( $it['label'] );
				}
				$header = Brief::default_header( (string) ( $b['brand']['style']['preset'] ?? Style::get()['preset'] ), count( $main ), $len );
			}
			self::write_menu( $job, $rows, 'main', $main, $items, $sig, $explicit, $header );
		}
		foreach ( [ 'footer', 'legal' ] as $name ) {
			if ( empty( $b['menus'][ $name ] ) ) {
				continue;
			}
			$sig = [];
			$items = self::nav_items( $b['menus'][ $name ], $job, $sig );
			self::write_menu( $job, $rows, $name, $b['menus'][ $name ], $items, $sig, '', '' );
		}
		if ( ! $dry && ! empty( $b['brand']['nav'] ) && self::nav_post( 'main-menu' ) ) {
			$r = Design::set_nav_style( (array) $b['brand']['nav'] );
			if ( is_wp_error( $r ) ) {
				self::warn( $job, 'brand.nav: ' . $r->get_error_message() );
			} else {
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => 'nav-style', 'action' => 'update' ] );
			}
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	/**
	 * Write one menu unless it already holds this (hash of the brief items, the explicit header and the status of the pages it
	 * links to), or an admin edited it since the build wrote it.
	 *
	 * @param array  $brief_items The items as the brief names them (page keys).
	 * @param array  $items       The same as nav items (post ids and permalinks).
	 * @param string $explicit    The header pattern the brief names ('' = chosen from the style).
	 * @param string $header      The header pattern to write.
	 */
	private static function write_menu( array &$job, array &$rows, string $name, array $brief_items, array $items, array $sig, string $explicit, string $header ): void {
		$dry = (bool) $job['dry_run'];
		$key = 'main' === $name ? 'main-menu' : $name;
		$lkey = self::lkey( 'menu', $name );
		$hash = md5( (string) wp_json_encode( self::canon( [ $brief_items, $explicit ] ) ) . implode( '|', $sig ) );
		$nid = self::nav_post( $key );
		if ( $nid ) {
			$row_l = Ledger::get( $lkey );
			// The same items already (however the menu was made): nothing to write. An explicit header is compared by hash.
			$same = '' === $explicit ? Brief_Export::menu_equals( $nid, $items ) : ( $row_l && (string) get_post_meta( $nid, '_lb_brief_hash', true ) === $hash );
			if ( $same ) {
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => 'keep' ] );
				return;
			}
			if ( $row_l && Ledger::is_edited( $lkey, self::menu_state_hash( $nid ) ) && empty( $job['force'] ) ) {
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => 'kept_edits', 'detail' => 'edited since the last build' ] );
				return;
			}
		}
		$action = $nid ? 'update' : 'create';
		if ( $dry ) {
			self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => $action, 'detail' => count( $items ) . ' items' ] );
			return;
		}
		if ( 'main' === $name ) {
			$r = Nav::set_header( [ 'pattern' => $header, 'nav' => $items, 'vars' => Blueprint::business_vars( (array) $job['brief']['business'] ) ] );
			if ( is_wp_error( $r ) ) {
				self::warn( $job, 'menu main: ' . $r->get_error_message() );
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => 'error', 'detail' => $r->get_error_message() ] );
				return;
			}
			foreach ( (array) ( $r['warnings'] ?? [] ) as $w ) {
				self::warn( $job, 'menu main: ' . $w );
			}
			$nid = (int) $r['navigation_id'];
		} else {
			$r = Nav::set_navigation( [ 'key' => $key, 'items' => $items ] );
			if ( is_wp_error( $r ) ) {
				self::warn( $job, 'menu ' . $name . ': ' . $r->get_error_message() );
				self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => 'error', 'detail' => $r->get_error_message() ] );
				return;
			}
			foreach ( (array) ( $r['warnings'] ?? [] ) as $w ) {
				self::warn( $job, 'menu ' . $name . ': ' . $w );
			}
			$nid = (int) $r['navigation_id'];
		}
		update_post_meta( $nid, '_lb_brief_hash', $hash );
		Ledger::record( $lkey, 'kadence_navigation', $nid, self::menu_state_hash( $nid ), (string) get_post_status( $nid ) );
		self::add( $job, $rows, [ 'type' => 'menu', 'key' => $name, 'action' => $action, 'detail' => count( $items ) . ' items', 'id' => $nid ] );
	}

	/** What an admin's edit of a menu changes: its items without the generated children (those follow the entries on their own). */
	private static function menu_state_hash( int $nid ): string {
		Brief_Export::reset();
		return Ledger::hash_value( Brief_Export::menu_items( $nid ) );
	}

	/* ------------------------------------------------------------------ seo, urls, redirects */

	private static function phase_seo( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$b = $job['brief'];
		$seo = $b['seo'];
		$rows = [];
		$offset = (int) $job['cursor'];
		if ( 0 === $offset ) {
			$args = [ 'dry_run' => $dry ];
			if ( ! empty( $seo['templates'] ) ) {
				$args['templates'] = $seo['templates'];
			}
			if ( isset( $seo['enable_local_seo'] ) ) {
				$args['local_seo'] = (bool) $seo['enable_local_seo'];
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				self::add( $job, $rows, [ 'type' => 'seo', 'key' => 'sync', 'action' => 'skip', 'detail' => 'needs manage_options' ] );
			} else {
				$r = Compat::sync_seo( $args );
				if ( isset( $r['error'] ) ) {
					self::warn( $job, 'seo: ' . $r['error'] );
					self::add( $job, $rows, [ 'type' => 'seo', 'key' => 'sync', 'action' => 'error', 'detail' => $r['error'] ] );
				} else {
					foreach ( (array) $r['warnings'] as $w ) {
						self::warn( $job, 'seo: ' . $w );
					}
					$n = count( (array) ( $r['rank_math']['changes'] ?? [] ) ) + (int) ( $r['pages']['totals']['set'] ?? 0 ) + (int) ( ! empty( $r['templates']['changed'] ) );
					self::add( $job, $rows, [ 'type' => 'seo', 'key' => 'sync', 'action' => $n ? 'update' : 'keep', 'detail' => 'Rank Math keys ' . count( (array) ( $r['rank_math']['changes'] ?? [] ) ) . ', pages ' . (int) ( $r['pages']['totals']['set'] ?? 0 ) . ( empty( $r['rank_math']['via'] ) && ! Compat::rm_active() ? ' (Rank Math is not active: templates are stored)' : '' ) ] );
					$next = $r['pages']['next_offset'] ?? null;
					if ( null !== $next ) {
						return [ 'done' => false, 'cursor' => (int) $next, 'rows' => $rows ];
					}
				}
			}
		} else {
			$p = Compat::apply_role_templates( [ 'dry_run' => $dry, 'offset' => $offset ] );
			self::add( $job, $rows, [ 'type' => 'seo', 'key' => 'pages@' . $offset, 'action' => ! empty( $p['totals']['set'] ) ? 'update' : 'keep' ] );
			if ( null !== ( $p['next_offset'] ?? null ) ) {
				return [ 'done' => false, 'cursor' => (int) $p['next_offset'], 'rows' => $rows ];
			}
		}
		self::apply_urls( $job, $rows );
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}

	private static function apply_urls( array &$job, array &$rows ): void {
		$dry = (bool) $job['dry_run'];
		$u = $job['brief']['urls'];
		if ( ! $u ) {
			return;
		}
		if ( ! Modules::enabled( Permalinks::class ) ) {
			self::add( $job, $rows, [ 'type' => 'urls', 'key' => 'urls', 'action' => 'skip', 'detail' => 'the Permalinks module is off' ] );
			return;
		}
		// Only what is not in place yet goes to the Permalinks module (so a re-run changes nothing).
		$s = Permalinks::settings();
		$todo = [];
		foreach ( (array) ( $u['permastructures'] ?? [] ) as $t => $st ) {
			$cur = $s['permastructures']['post_types'][ $t ] ?? ( $s['permastructures']['taxonomies'][ $t ] ?? '' );
			if ( trim( (string) $cur, '/' ) !== trim( (string) $st, '/' ) ) {
				$todo['permastructures'][ $t ] = $st;
			}
		}
		foreach ( (array) ( $u['settings'] ?? [] ) as $k => $v ) {
			if ( ! array_key_exists( $k, $s ) || $s[ $k ] !== $v ) {
				$todo['settings'][ $k ] = $v;
			}
		}
		$own = 'own' === Redirects::store();
		foreach ( (array) ( $u['redirects'] ?? [] ) as $r ) {
			$path = Permalinks::local_path( (string) $r['from'] );
			$there = null !== $path && ( $own ? (bool) Redirects::own_by_source( $path ) : (int) ( Redirects::list( [ 'search' => $path, 'limit' => 5 ] )['total'] ?? 0 ) > 0 );
			if ( ! $there ) {
				$todo['redirects'][] = $r;
			}
		}
		if ( ! empty( $u['old_sitemap_url'] ) ) {
			$done = Ledger::get( 'brief:old-sitemap' );
			if ( ! $done || ! hash_equals( (string) $done['hash'], md5( (string) $u['old_sitemap_url'] ) ) ) {
				$todo['old_sitemap_url'] = $u['old_sitemap_url'];
			}
		}
		if ( ! $todo ) {
			self::add( $job, $rows, [ 'type' => 'urls', 'key' => 'urls', 'action' => 'keep' ] );
			return;
		}
		$what = implode( ', ', array_keys( $todo ) );
		if ( $dry ) {
			self::add( $job, $rows, [ 'type' => 'urls', 'key' => 'urls', 'action' => 'update', 'detail' => $what ] );
			return;
		}
		$r = Permalinks::blueprint_urls( [ 'urls' => $todo ], [] );
		if ( is_wp_error( $r ) ) {
			self::warn( $job, 'urls: ' . $r->get_error_message() );
			self::add( $job, $rows, [ 'type' => 'urls', 'key' => 'urls', 'action' => 'error', 'detail' => $r->get_error_message() ] );
			return;
		}
		foreach ( (array) ( $r['warnings'] ?? [] ) as $w ) {
			self::warn( $job, 'urls: ' . $w );
		}
		if ( isset( $todo['old_sitemap_url'] ) ) {
			Ledger::record( 'brief:old-sitemap', 'option', self::OPT, md5( (string) $todo['old_sitemap_url'] ), 'set' );
		}
		self::add( $job, $rows, [ 'type' => 'urls', 'key' => 'urls', 'action' => 'update', 'detail' => $what ] );
	}

	/* ------------------------------------------------------------------ conversions */

	private static function phase_conversions( array &$job ): array {
		$dry = (bool) $job['dry_run'];
		$rows = [];
		$conv = $job['brief']['conversions'];
		if ( ! Modules::enabled( Conversions::class ) ) {
			self::add( $job, $rows, [ 'type' => 'conversion', 'key' => 'conversions', 'action' => 'skip', 'detail' => 'the Conversions module is off' ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		if ( ! Conversions::active() ) {
			self::warn( $job, 'Kadence Conversions is not active: the exit-intent popup and other conversion items were not created.' );
			self::add( $job, $rows, [ 'type' => 'conversion', 'key' => 'conversions', 'action' => 'skip', 'detail' => 'Kadence Conversions is not active' ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		$items = Conversions::brief_items( $conv );
		if ( ! $items ) {
			self::add( $job, $rows, [ 'type' => 'conversion', 'key' => 'conversions', 'action' => 'skip', 'detail' => 'none asked for' ] );
			return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
		}
		if ( $dry ) {
			foreach ( $items as $i => $it ) {
				$items[ $i ]['dry_run'] = true;
			}
		}
		if ( ! empty( $job['force'] ) ) {
			foreach ( $items as $i => $it ) {
				$items[ $i ]['force'] = true;
			}
		}
		$r = Conversions::apply_items( $items );
		foreach ( (array) $r['warnings'] as $w ) {
			self::warn( $job, $w );
		}
		foreach ( (array) $r['items'] as $it ) {
			$st = (string) ( $it['status'] ?? '' );
			$act = in_array( $st, [ 'created', 'would_create' ], true ) ? 'create' : ( in_array( $st, [ 'updated', 'would_update' ], true ) ? 'update' : ( 'kept_edits' === $st ? 'kept_edits' : ( 'unchanged' === $st ? 'keep' : 'info' ) ) );
			self::add( $job, $rows, [ 'type' => 'conversion', 'key' => (string) ( $it['key'] ?? '' ), 'action' => $act, 'detail' => $st ] );
		}
		return [ 'done' => true, 'cursor' => 0, 'rows' => $rows ];
	}
}
