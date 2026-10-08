<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned data migrations: ordered, idempotent, resumable steps that bring an existing site's data up to what this
 * version of the plugin expects (legacy chrome -> chrome map, page heroes -> _lb_hero, ...). "Nothing missed on update".
 *
 * A step is registered by the module that owns the data, in its boot():
 *   Migrate::register_step( 'chrome-adopt-legacy', [ Chrome::class, 'migrate_adopt' ] );
 *   Migrate::register_step( 'chrome-reapply', [ Chrome::class, 'migrate_reapply' ], [ 'every_version' => true, 'after' => [ 'chrome-adopt-legacy' ] ] );
 * and is called as  callable( mixed $cursor, int $batch ): array|\WP_Error  returning
 *   [ 'done' => bool, 'cursor' => mixed (where to resume; ignored once done), 'report' => string|array (what changed) ]
 * Each call handles at most $batch (50) objects and must be safe to repeat; a step snapshots what it changes itself
 * (Abilities::snapshot for options/mods, the Ledger for posts).
 *
 * Options: every_version (run again on each LB_VERSION: its done-mark is "id@version"), after (step ids that must run
 * first; otherwise steps run in registration order, i.e. module boot order). A step that returns a WP_Error or throws
 * stops the run (later steps wait for it); the error is kept in the state's 'failed' list (shown in site-state and as
 * an admin notice) until the step succeeds, and wp-admin page loads leave it alone for an hour (RETRY_AFTER) so a
 * broken step cannot slow down or break wp-admin. rerun( id ) runs a done step again (e.g. from a module's activate()).
 *
 * When it runs: on admin_init for a user with manage_options (never on front-end, AJAX or REST requests), from WP-CLI
 * (wp launch-builder migrate) and from tools that call run(). The option launch_builder_db_version holds the version
 * the data is at: LB_VERSION plus a signature of the step ids, so a new step registered later runs even without a
 * version bump. Plugin activation deletes it (activate()), so the steps are checked again. Progress (done steps,
 * cursors, failures, last report) lives in launch_builder_migrate_state.
 */
final class Migrate {

	const OPT_VERSION = 'launch_builder_db_version';
	const OPT_STATE   = 'launch_builder_migrate_state';
	const BATCH       = 50;
	const RETRY_AFTER = 3600; // Seconds wp-admin waits before trying a failed step again (CLI and tools do not wait).

	/** @var array<string,array{cb:callable,every_version:bool,after:string[]}> step id => definition, in registration order. 'noop' is the runner's self-test. */
	private static $steps = [ 'noop' => [ 'cb' => [ __CLASS__, 'noop_step' ], 'every_version' => false, 'after' => [] ] ];

	public static function boot(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_run' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	/**
	 * Add a step (call it from a module's boot()). Ids are unique; re-registering an id replaces it.
	 *
	 * @param string   $id       [a-z0-9_-].
	 * @param callable $callable see the class comment.
	 * @param array    $opts     { every_version?: bool, after?: string[] }
	 * @return bool false for an empty id.
	 */
	public static function register_step( string $id, callable $callable, array $opts = [] ): bool {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return false;
		}
		self::$steps[ $id ] = [
			'cb'            => $callable,
			'every_version' => ! empty( $opts['every_version'] ),
			'after'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $opts['after'] ?? [] ) ) ) ),
		];
		return true;
	}

	/** @return string[] registered step ids, in run order (registration order, moved after the steps they name in 'after'). */
	public static function steps(): array {
		$ids = array_keys( self::$steps );
		$out = [];
		for ( $pass = 0; count( $out ) < count( $ids ) && $pass <= count( $ids ); $pass++ ) {
			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $out, true ) && ! array_diff( array_intersect( self::$steps[ $id ]['after'], $ids ), $out ) ) {
					$out[] = $id;
				}
			}
		}
		return array_values( array_unique( array_merge( $out, $ids ) ) ); // A cycle in 'after' falls back to registration order.
	}

	/** The mark a finished step leaves in the state: its id, or id@LB_VERSION for every_version steps. */
	private static function done_key( string $id ): string {
		return empty( self::$steps[ $id ]['every_version'] ) ? $id : $id . '@' . LB_VERSION;
	}

	/** The db version the registered steps lead to. */
	public static function target(): string {
		return LB_VERSION . '+' . substr( md5( implode( ',', self::steps() ) ), 0, 8 );
	}

	/** Is there anything to run? (One autoloaded option read.) */
	public static function needed(): bool {
		return get_option( self::OPT_VERSION ) !== self::target();
	}

	/** @return string[] ids of the steps that have not finished for this version. */
	public static function pending(): array {
		$done = self::state()['done'];
		return array_values( array_filter( self::steps(), static function ( $id ) use ( $done ) { return ! in_array( self::done_key( $id ), $done, true ); } ) );
	}

	/**
	 * admin_init: run pending steps for administrators, with a small time budget per page load. After a failure it
	 * waits RETRY_AFTER seconds (WP-CLI and tools still run).
	 */
	public static function maybe_run(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! self::needed() ) {
			return;
		}
		$failed = self::failed();
		if ( $failed && max( array_map( static function ( $f ) { return (int) ( $f['time'] ?? 0 ); }, $failed ) ) > time() - self::RETRY_AFTER ) {
			return;
		}
		self::run( 10.0 );
	}

	/**
	 * Run pending steps until all are done or the time budget is used up (call again to continue). Every call runs at
	 * least one batch, whatever the budget.
	 *
	 * @return array{complete:bool,version:string,target:string,ran:array,errors:array,busy?:bool}
	 */
	public static function run( float $max_seconds = 20.0 ): array {
		$max = max( 1.0, $max_seconds );
		if ( ! Lock::acquire( 'migrate', (int) ceil( $max ) + 120 ) ) { // The lock outlives the budget plus one slow batch.
			return [ 'complete' => false, 'busy' => true, 'version' => (string) get_option( self::OPT_VERSION ), 'target' => self::target(), 'ran' => [], 'errors' => [] ];
		}
		try {
			$state = self::state();
			// Done-marks of every_version steps for other versions are history: drop them (and their cursors), and the
			// failures of steps no longer registered.
			$current = static function ( $k ) { return false === strpos( (string) $k, '@' ) || '@' . LB_VERSION === substr( (string) $k, -strlen( '@' . LB_VERSION ) ); };
			$state['done'] = array_values( array_filter( $state['done'], $current ) );
			$state['cursor'] = array_filter( $state['cursor'], $current, ARRAY_FILTER_USE_KEY );
			$state['failed'] = array_intersect_key( $state['failed'], self::$steps );
			$start = microtime( true );
			$batches = 0;
			$ran = [];
			$errors = [];
			foreach ( self::steps() as $id ) {
				$key = self::done_key( $id );
				if ( in_array( $key, $state['done'], true ) ) {
					continue;
				}
				do {
					if ( $batches && microtime( true ) - $start > $max ) {
						break 2; // Out of time: resume from the stored cursor next run.
					}
					$batches++;
					try {
						$r = call_user_func( self::$steps[ $id ]['cb'], $state['cursor'][ $key ] ?? null, self::BATCH );
					} catch ( \Throwable $e ) { // A broken step must never break the request that runs it (wp-admin).
						$r = new \WP_Error( 'lb_migrate_exception', get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
					}
					if ( is_wp_error( $r ) ) {
						$errors[ $id ] = $r->get_error_message();
						$state['failed'][ $id ] = [ 'time' => time(), 'error' => $errors[ $id ] ];
						break 2;
					}
					unset( $state['failed'][ $id ] );
					$r = (array) $r;
					if ( isset( $r['report'] ) && '' !== $r['report'] && [] !== $r['report'] ) {
						$ran[ $id ][] = $r['report'];
					}
					$done = ! empty( $r['done'] );
					if ( $done ) {
						$state['done'][] = $key;
						unset( $state['cursor'][ $key ] );
					} else {
						$state['cursor'][ $key ] = $r['cursor'] ?? null;
					}
					update_option( self::OPT_STATE, $state, false ); // Persist after every batch so a timeout loses nothing.
				} while ( ! $done );
			}
			$complete = ! array_diff( array_map( [ __CLASS__, 'done_key' ], self::steps() ), $state['done'] );
			if ( $complete ) {
				update_option( self::OPT_VERSION, self::target(), true );
			}
			$report = [ 'complete' => $complete, 'version' => (string) get_option( self::OPT_VERSION ), 'target' => self::target(), 'ran' => $ran, 'errors' => $errors ];
			$state['last'] = [ 'time' => gmdate( 'c' ), 'report' => $report ];
			update_option( self::OPT_STATE, $state, false );
			return $report;
		} finally {
			Lock::release( 'migrate' );
		}
	}

	/**
	 * Run a step again (it must still be idempotent): forgets its done-mark, cursor and failure, and the db version, so
	 * the next run (admin_init, CLI or a tool) picks it up. For example a module's activate() re-running its own step.
	 */
	public static function rerun( string $id ): void {
		$id = sanitize_key( $id );
		$state = self::state();
		$mine = static function ( $k ) use ( $id ) { return $k !== $id && 0 !== strpos( (string) $k, $id . '@' ); };
		$state['done'] = array_values( array_filter( $state['done'], $mine ) );
		$state['cursor'] = array_filter( $state['cursor'], $mine, ARRAY_FILTER_USE_KEY );
		unset( $state['failed'][ $id ] );
		update_option( self::OPT_STATE, $state, false );
		delete_option( self::OPT_VERSION );
	}

	/** Progress: done-marks, cursors of unfinished steps, failed steps (id => {time, error}) and the last run's report. */
	public static function state(): array {
		$s = get_option( self::OPT_STATE, [] );
		$s = is_array( $s ) ? $s : [];
		return [ 'done' => array_values( (array) ( $s['done'] ?? [] ) ), 'cursor' => (array) ( $s['cursor'] ?? [] ), 'failed' => (array) ( $s['failed'] ?? [] ), 'last' => $s['last'] ?? null ];
	}

	/** @return array<string,array{time:int,error:string}> failing steps that are still registered (id => failure). */
	public static function failed(): array {
		return array_intersect_key( self::state()['failed'], self::$steps );
	}

	/** Module hook (Plugin::activate): check every step again on the next run and retry failed ones straight away. */
	public static function activate(): void {
		$state = self::state();
		if ( $state['failed'] ) {
			$state['failed'] = [];
			update_option( self::OPT_STATE, $state, false );
		}
		delete_option( self::OPT_VERSION );
	}

	/** Module hook: migration status in site-state. */
	public static function site_state( array &$state ): void {
		$state['migrations'] = [ 'version' => (string) get_option( self::OPT_VERSION ), 'target' => self::target(), 'pending' => self::pending(), 'failed' => self::failed() ];
	}

	/** Admin notice for administrators while a step is failing. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$failed = self::failed();
		if ( ! $failed ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Launch Builder could not finish updating its data.</strong> It tries again every hour while an administrator uses wp-admin (or run <code>wp launch-builder migrate</code>).</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $failed as $id => $f ) {
			echo '<li><code>' . esc_html( (string) $id ) . '</code>: ' . esc_html( (string) ( $f['error'] ?? '' ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** The runner's self-test step: proves the pipeline works and changes nothing. */
	public static function noop_step( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		return [ 'done' => true, 'report' => 'self-test: runner ok, nothing changed' ];
	}
}
