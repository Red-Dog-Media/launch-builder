<?php
// Runs when the plugin is deleted from the Plugins screen. Removes tokens, logs and settings; site content stays.
// Business Info (launch_builder_company and its source mapping) is kept, because pages print it through tokens, unless
// "Also delete Business Info" (option launch_builder_remove_all_data) was ticked under Launch Builder > Activity and
// safety before deleting the plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Every option the plugin writes is named launch_builder_*; all of them go (the sweep below), so a new option needs no
// edit here. This list documents them (and is deleted through the API first, which also clears the options cache).
$lb_options = [
	// Connection, safety and logs.
	'launch_builder_mcp_token',
	'launch_builder_snapshots',
	'launch_builder_log',
	'launch_builder_power',
	// Placement, design and structure.
	'launch_builder_globals',
	'launch_builder_sidebar_widget',
	'launch_builder_chrome',
	'launch_builder_chrome_map',
	'launch_builder_auto_update_chrome',
	'launch_builder_parked_elements',
	'launch_builder_style',
	'launch_builder_style_css',
	'launch_builder_hubs',
	'launch_builder_urls',
	'launch_builder_duplicate', // Duplicate settings
	// Bookkeeping (plus launch_builder_ledger_<hash> rows and launch_builder_lock_<name> locks).
	'launch_builder_db_version',
	'launch_builder_migrate_state',
	'launch_builder_build_job',
	'launch_builder_golive_run',
	'launch_builder_entries_flushed',
	// Login links (plus one _lb_login_link_* usermeta row per link, removed below).
	'launch_builder_login_links',
	'launch_builder_login_links_enabled',
	// Branding settings and the Kadence White Label bridge bookkeeping. That plugin's own option is never touched.
	'launch_builder_branding',
	'launch_builder_branding_kwl',
];
$lb_keep = [ 'launch_builder_company', 'launch_builder_company_source' ];
$lb_keep[] = 'launch_builder_permalinks'; // URL patterns stay with the URL tables and meta.
if ( get_option( 'launch_builder_remove_all_data' ) ) {
	$lb_options = array_merge( $lb_options, $lb_keep );
	$lb_keep = [];
	// The URL lookup and redirect tables and the URL meta go only with "remove all data".
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lb_uris" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lb_redirects" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_lb_uri', '_lb_uri_mode')" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('_lb_uri', '_lb_uri_mode')" ); // phpcs:ignore WordPress.DB
	delete_post_meta_by_key( '_lb_duplicate_of' ); // The marker on copies goes; the copies stay.
	// The Site Owner role goes with "remove all data"; the accounts that hold it become editors first, never role-less.
	if ( get_role( 'lb_client' ) ) {
		foreach ( get_users( [ 'role' => 'lb_client', 'fields' => 'ID', 'number' => -1 ] ) as $lb_uid ) {
			$lb_user = new WP_User( (int) $lb_uid );
			$lb_user->remove_role( 'lb_client' );
			if ( ! $lb_user->roles ) {
				$lb_user->add_role( 'editor' );
			}
		}
		remove_role( 'lb_client' );
	}
}
wp_clear_scheduled_hook( 'launch_builder_permalinks_cascade' ); // The cascade job never outlives the plugin.
$lb_options[] = 'launch_builder_remove_all_data';
foreach ( $lb_options as $opt ) {
	delete_option( $opt );
}
global $wpdb;
foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'launch\_builder\_%'" ) as $opt ) { // phpcs:ignore
	if ( ! in_array( $opt, $lb_keep, true ) ) {
		delete_option( $opt ); // One by one, so a persistent object cache forgets them too.
	}
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lb\_mcp\_%' OR option_name LIKE '\_transient\_timeout\_lb\_mcp\_%' OR option_name LIKE '\_transient\_lb\_pv\_%' OR option_name LIKE '\_transient\_timeout\_lb\_pv\_%' OR option_name LIKE '\_transient\_lb\_check\_%' OR option_name LIKE '\_transient\_timeout\_lb\_check\_%'" ); // phpcs:ignore
// Login links: one protected usermeta row per link (all sites) and the throttle/flash transients.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_lb\_login\_link\_%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lb\_ll\_%' OR option_name LIKE '\_transient\_timeout\_lb\_ll\_%'" ); // phpcs:ignore
wp_clear_scheduled_hook( 'launch_builder_login_links_gc' );
