<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Provision a site over SSH (no HTTP API needed). The global --user flag selects the administrator:
 *   wp plugin install launch-builder.zip --activate
 *   wp launch-builder token --user=admin --porcelain      # prints a fresh MCP token
 *   wp launch-builder blueprint blueprint.json --user=admin
 *   wp launch-builder migrate --user=admin                # pending data migrations (also run from wp-admin)
 *   wp launch-builder audit --user=admin --only-failures  # the site audit (exit code 1 on errors)
 *   wp launch-builder sync --user=admin [--apply]         # repair drift (dry run unless --apply)
 *   wp launch-builder inspect /contact/ --user=admin      # what one page prints for a visitor
 *   wp launch-builder modules [list|enable|disable] <slug> --user=admin
 */
final class CLI {

	private function admin_or_bail(): \WP_User {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			\WP_CLI::error( 'Run as an administrator with the global flag, e.g. --user=admin.' );
		}
		return $user;
	}

	/**
	 * Create (or replace) the MCP token for the --user administrator.
	 *
	 * [--porcelain]
	 * : Print only the token.
	 */
	public function token( $args, $assoc ) {
		$user = $this->admin_or_bail();
		$t = Mcp::create_token( (int) $user->ID );
		if ( isset( $assoc['porcelain'] ) ) {
			\WP_CLI::line( $t );
			return;
		}
		\WP_CLI::success( 'Token created for ' . $user->user_login . ' (valid 180 days). Copy it now; only its hash is stored: ' . $t );
		\WP_CLI::line( 'Endpoint: ' . home_url( '/mcp' ) );
	}

	/**
	 * Apply a site blueprint JSON file.
	 *
	 * <file>
	 * : Path to the blueprint JSON.
	 */
	public function blueprint( $args, $assoc ) {
		$this->admin_or_bail();
		if ( ! is_readable( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Cannot read the blueprint file.' );
		}
		$bp = json_decode( (string) file_get_contents( $args[0] ), true );
		if ( ! is_array( $bp ) ) {
			\WP_CLI::error( 'Blueprint is not valid JSON.' );
		}
		$r = Blueprint::build( [ 'blueprint' => $bp ] );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		foreach ( $r['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		\WP_CLI::success( count( $r['pages'] ?? [] ) . ' pages built. Snapshot ' . $r['snapshot'] . ' (restore-snapshot undoes settings changes).' );
		\WP_CLI::line( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Run pending data migrations (they also run on their own when an administrator opens wp-admin).
	 *
	 * [--seconds=<seconds>]
	 * : Time budget for this run (default 60). Run again to continue an unfinished migration.
	 */
	public function migrate( $args, $assoc ) {
		$this->admin_or_bail();
		$r = Migrate::run( (float) ( $assoc['seconds'] ?? 60 ) );
		if ( ! empty( $r['busy'] ) ) {
			\WP_CLI::error( 'Another migration run holds the lock. Try again in a moment.' );
		}
		foreach ( $r['errors'] as $id => $msg ) {
			\WP_CLI::warning( $id . ': ' . $msg );
		}
		\WP_CLI::line( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( $r['complete'] ) {
			\WP_CLI::success( 'Data is at version ' . $r['version'] . '.' );
		} else {
			\WP_CLI::warning( 'Not finished yet: run it again.' );
		}
	}

	/** Show what is installed. */
	public function status() {
		$s = Abilities::site_state();
		\WP_CLI::line( wp_json_encode( [ 'wp' => $s['wp_version'], 'theme' => $s['theme'], 'launch_builder' => $s['launch_builder'], 'kadence_pro' => $s['kadence_pro'], 'modules' => $s['modules'] ?? null ], JSON_PRETTY_PRINT ) );
	}

	/**
	 * Audit the whole site (every page of the run) and print the findings. Exits with code 1 when there are errors.
	 *
	 * [--scope=<scopes>]
	 * : Comma-separated scopes (content, company, menus, chrome, seo, render, ...). Default: all.
	 *
	 * [--only-failures]
	 * : Leave out info findings.
	 *
	 * [--limit=<n>]
	 * : Posts per page (1-100, default 50).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp launch-builder audit --user=admin --only-failures
	 *     wp launch-builder audit --user=admin --scope=content,seo --format=json
	 */
	public function audit( $args, $assoc ) {
		$this->admin_or_bail();
		$base = [ 'scope' => array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['scope'] ?? '' ) ) ) ), 'only_failures' => isset( $assoc['only-failures'] ), 'limit' => (int) ( $assoc['limit'] ?? 50 ), 'max_findings' => 200 ];
		$after = 0;
		$findings = [];
		$totals = [ 'error' => 0, 'warning' => 0, 'info' => 0 ];
		$pages = 0;
		do {
			$r = Audit::run_cached( $base + [ 'after_id' => $after ] );
			$pages++;
			$findings = array_merge( $findings, $r['findings'] );
			foreach ( $r['totals'] as $k => $n ) {
				$totals[ $k ] += $n;
			}
			if ( $r['dropped'] ) {
				\WP_CLI::warning( $r['dropped'] . ' findings on page ' . $pages . ' were counted but not listed; use a smaller --limit to see them all.' );
			}
			$after = $r['next_after_id'];
		} while ( null !== $after && $pages < 10000 );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( [ 'totals' => $totals, 'findings' => $findings ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} elseif ( $findings ) {
			$rows = array_map( static function ( $f ) { return [ 'severity' => $f['severity'], 'code' => $f['code'], 'where' => trim( ( $f['object_type'] ?? '' ) . ' ' . ( $f['id'] ?? '' ) . ' ' . ( $f['title'] ?? '' ) ), 'message' => $f['message'] ?? '' ]; }, $findings );
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'severity', 'code', 'where', 'message' ] );
		}
		\WP_CLI::line( sprintf( '%d errors, %d warnings, %d info (%d pages).', $totals['error'], $totals['warning'], $totals['info'], $pages ) );
		if ( $totals['error'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Repair drift with the registered fixes. Dry run unless --apply.
	 *
	 * [--fixes=<ids>]
	 * : Comma-separated fix ids (default: every default fix). `wp launch-builder sync --list` shows them.
	 *
	 * [--apply]
	 * : Change things (without it, each fix only reports what it would do).
	 *
	 * [--list]
	 * : List the fixes.
	 */
	public function sync( $args, $assoc ) {
		$this->admin_or_bail();
		if ( isset( $assoc['list'] ) ) {
			foreach ( Audit::fixes() as $id => $f ) {
				\WP_CLI::line( $id . ( $f['default'] ? '' : ' (not default)' ) . ': ' . $f['description'] );
			}
			return;
		}
		$r = Audit::sync( [ 'fixes' => array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['fixes'] ?? '' ) ) ) ), 'dry_run' => ! isset( $assoc['apply'] ) ] );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $r['fixes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$r['dry_run'] ? \WP_CLI::success( 'Dry run: nothing changed. Add --apply to run the fixes.' ) : \WP_CLI::success( 'Fixes applied.' );
	}

	/**
	 * Inspect one page the way a visitor gets it.
	 *
	 * <target>
	 * : A URL or path of this site, a post id, or a role (home, page, service, area, category, conversion, ...).
	 *
	 * [--no-links]
	 * : Skip checking internal links.
	 */
	public function inspect( $args, $assoc ) {
		$this->admin_or_bail();
		$t = (string) ( $args[0] ?? '' );
		$in = ctype_digit( $t ) ? [ 'post_id' => (int) $t ] : ( isset( Roles::ROLES[ $t ] ) ? [ 'role' => $t ] : [ 'url' => $t ] );
		$r = Inspect::page_tool( $in + [ 'check_links' => ! isset( $assoc['no-links'] ) ] );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * List modules or switch optional ones on and off.
	 *
	 * [<action>]
	 * : list (default), enable or disable.
	 *
	 * [<slug>...]
	 * : Module slugs for enable/disable.
	 */
	public function modules( $args, $assoc ) {
		$this->admin_or_bail();
		$action = (string) ( $args[0] ?? 'list' );
		if ( 'list' === $action ) {
			$rows = array_map( static function ( $m ) { return [ 'slug' => $m['slug'], 'label' => $m['label'], 'enabled' => $m['enabled'] ? 'yes' : 'no', 'toggle' => $m['toggle'] ? 'yes' : ( $m['core'] ? 'core' : 'no' ), 'default' => $m['default'] ? 'on' : 'off' ]; }, Modules::all() );
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'label', 'enabled', 'toggle', 'default' ] );
			return;
		}
		if ( ! in_array( $action, [ 'enable', 'disable' ], true ) || count( $args ) < 2 ) {
			\WP_CLI::error( 'Usage: wp launch-builder modules [list|enable|disable] <slug>...' );
		}
		$slugs = array_slice( $args, 1 );
		$r = 'enable' === $action ? Modules::set( $slugs, [] ) : Modules::set( [], $slugs );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( $r['changed'] ? 'Switched: ' . wp_json_encode( $r['changed'] ) . '. Snapshot ' . $r['snapshot'] . '.' : 'Nothing changed.' );
	}
}
