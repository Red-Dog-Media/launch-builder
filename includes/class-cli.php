<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Provision a site over SSH (no HTTP API needed). The global --user flag selects the administrator:
 *   wp plugin install launch-builder.zip --activate
 *   wp launch-builder token --user=admin --porcelain      # prints a fresh MCP token
 *   wp launch-builder blueprint blueprint.json --user=admin
 *   wp launch-builder migrate --user=admin                # pending data migrations (also run from wp-admin)
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
		\WP_CLI::line( wp_json_encode( [ 'wp' => $s['wp_version'], 'theme' => $s['theme'], 'launch_builder' => $s['launch_builder'], 'kadence_pro' => $s['kadence_pro'] ], JSON_PRETTY_PRINT ) );
	}
}
