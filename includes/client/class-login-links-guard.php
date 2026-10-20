<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The always-on part of Login links: listed in Plugin::MODULES right after Login_Links (so the registry has decided
 * about Login_Links before this boots), not switchable, and it adds nothing to the login page or the front end. The Modules registry does not boot a module
 * that is off, but these must keep working while Login links is off:
 *  - LB_LOGIN_LINKS in wp-config.php (filter launch_builder_module_enabled; and Login_Links::boot() here, for "true"
 *    while the registry has the module off, since the registry decides before this filter exists);
 *  - the notice while the old login-link plugin is active (its links stop the moment it is deactivated) and the
 *    audit check for its plain-text codes;
 *  - Users > Login links for administrators (it says how to turn the module on), site-state, playbook, WP-CLI;
 *  - clearing the GC cron when the plugin is deactivated.
 */
final class Login_Links_Guard {

	const MODULE = [
		'slug'        => 'login-links-guard',
		'label'       => 'Login links: safety checks',
		'description' => 'Always on and light: warns while the old login-link plugin is active, reports its plain-text codes in the audit, and keeps the Login links switch reachable. Adds nothing to the login page.',
		'toggle'      => false,
	];

	public static function boot(): void {
		add_filter( 'launch_builder_module_enabled', [ Login_Links::class, 'module_switch' ], 10, 3 );
		Login_Links::boot(); // Runs once: a no-op when the registry booted it already or when the module is off.
		add_action( 'admin_notices', [ Login_Links::class, 'notices' ] );
		add_action( 'admin_menu', [ Login_Links::class, 'menu' ] );
		add_action( 'admin_post_lb_ll_enable', [ Login_Links::class, 'handle_enable' ] );
		add_action( 'admin_post_lb_ll_legacy_delete', [ Login_Links::class, 'handle_legacy_delete' ] );
		if ( ! Login_Links::enabled() ) {
			add_action( 'admin_init', [ Login_Links::class, 'unschedule' ] );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'launch-builder login-link', Login_Links_CLI::class );
		}
	}

	/** Module hook: plugin deactivated (Login_Links' own deactivate() is skipped while the module is off). */
	public static function deactivate(): void {
		Login_Links::deactivate();
	}

	public static function site_state( array &$state ): void {
		$state['login_links'] = Login_Links::state();
	}

	public static function playbook( array &$pb ): void {
		$pb['login_links'] = Login_Links::playbook_text();
	}

	/** The old plugin's plain-text codes are a risk whether or not Login links is on. */
	public static function audit_checks(): array {
		return [ 'login_links_legacy_plaintext' => [ 'security', [ Login_Links::class, 'audit_legacy' ] ] ];
	}
}
