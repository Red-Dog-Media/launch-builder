<?php
/**
 * Plugin Name:       Launch Builder
 * Plugin URI:        https://github.com/Red-Dog-Media/launch-builder
 * Description:       A block toolkit for client sites: Kadence patterns, reputation widgets, global business info, and an MCP endpoint that builds whole sites.
 * Version:           0.10.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            LaunchLocal Digital Marketing
 * Author URI:        https://golaunchlocal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       launch-builder
 * Update URI:        https://github.com/Red-Dog-Media/launch-builder
 */

namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

define( 'LB_VERSION', '0.10.0' ); // Always equal to the Version header above; tools/release.mjs updates both.
define( 'LB_DIR', plugin_dir_path( __FILE__ ) );
define( 'LB_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: LaunchBuilder\Foo_Bar lives in includes/class-foo-bar.php. Every class in this plugin follows that rule,
 * so adding a class is adding a file; Plugin::MODULES says which classes take part in boot, tools, site-state and so on.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$name = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			return; // No sub-namespaces.
		}
		$file = LB_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

Plugin::boot();
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'launch-builder', CLI::class );
}
