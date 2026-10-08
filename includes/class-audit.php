<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Site audit: checks registered by the module that owns the data (chrome and role checks by Chrome,
 * token checks by Tokens...), run in ID-cursor batches so a large site never times out or overflows the MCP output.
 *
 * A check is callable( array $args ): array of findings, each
 *   { severity: error|warning|info, code, object_type, id, url, message, fix }
 * Modules register checks either by calling Audit::register_check() (e.g. in boot()) or through the module hook
 * audit_checks(): [ code => [ scope, callable, batched? ] ].
 * Batched checks walk objects by ID: they read args after_id and limit, return { findings, next_after_id } (null when
 * finished) and run on every page of the run. Other checks (site-wide) run only on the first page (after_id 0), so a
 * paged audit reports each of their findings once.
 *
 * With no checks registered, run() reports nothing. The audit-site tool wraps run().
 */
final class Audit {

	/** @var array<string,array{scope:string,callable:callable,batched:bool}> */
	private static $checks = [];

	/**
	 * Register a check under a code (unique; re-registering replaces it) and a scope (pages, entries, chrome, ...).
	 * $batched: the check pages through objects with after_id/limit (see the class comment).
	 */
	public static function register_check( string $code, string $scope, callable $callable, bool $batched = false ): void {
		self::$checks[ sanitize_key( $code ) ] = [ 'scope' => sanitize_key( $scope ), 'callable' => $callable, 'batched' => $batched ];
	}

	/** @return array<string,array{scope:string,callable:callable,batched:bool}> registered checks plus those from audit_checks(). */
	public static function checks(): array {
		$all = self::$checks;
		foreach ( Plugin::modules( 'audit_checks' ) as $module ) {
			foreach ( (array) $module::audit_checks() as $code => $def ) {
				$def = array_values( (array) $def );
				if ( isset( $def[1] ) && is_callable( $def[1] ) ) {
					$all[ sanitize_key( (string) $code ) ] = [ 'scope' => sanitize_key( (string) $def[0] ), 'callable' => $def[1], 'batched' => ! empty( $def[2] ) ];
				}
			}
		}
		return $all;
	}

	/**
	 * Run the checks.
	 *
	 * @param array $args { scope: string[] (default all), after_id: int, limit: int <= 100, max_findings: int <= 200,
	 *                    only_failures: bool }
	 * @return array{findings:array,next_after_id:?int,totals:array<string,int>,checks:int}
	 */
	public static function run( array $args = [] ): array {
		$scopes = array_filter( array_map( 'sanitize_key', (array) ( $args['scope'] ?? [] ) ) );
		$args['after_id'] = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$args['limit'] = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$max = max( 1, min( 200, (int) ( $args['max_findings'] ?? 200 ) ) );
		$only = ! empty( $args['only_failures'] );
		$findings = [];
		$totals = [ 'error' => 0, 'warning' => 0, 'info' => 0 ];
		$next = null;
		$ran = 0;
		foreach ( self::checks() as $code => $c ) {
			if ( ( $scopes && ! in_array( $c['scope'], $scopes, true ) ) || ( empty( $c['batched'] ) && $args['after_id'] > 0 ) ) {
				continue; // Out of scope, or a site-wide check that already ran on the first page.
			}
			$ran++;
			$out = call_user_func( $c['callable'], $args );
			if ( is_array( $out ) && array_key_exists( 'next_after_id', $out ) ) { // A batching check: { findings, next_after_id }.
				if ( null !== $out['next_after_id'] ) { // Where to continue (null = finished); the smallest cursor wins.
					$next = null === $next ? (int) $out['next_after_id'] : min( $next, (int) $out['next_after_id'] );
				}
				$out = (array) ( $out['findings'] ?? [] );
			}
			foreach ( (array) $out as $f ) {
				$f = (array) $f + [ 'severity' => 'warning', 'code' => $code ];
				$sev = in_array( $f['severity'], [ 'error', 'warning', 'info' ], true ) ? $f['severity'] : 'warning';
				$totals[ $sev ]++;
				if ( ( $only && 'info' === $sev ) || count( $findings ) >= $max ) {
					continue;
				}
				$findings[] = $f;
			}
		}
		return [ 'findings' => $findings, 'next_after_id' => $next, 'totals' => $totals, 'checks' => $ran ];
	}
}
