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
	// Bookkeeping (plus launch_builder_ledger_<hash> rows and launch_builder_lock_<name> locks).
	'launch_builder_db_version',
	'launch_builder_migrate_state',
	'launch_builder_build_job',
	'launch_builder_entries_flushed',
];
$lb_keep = [ 'launch_builder_company', 'launch_builder_company_source' ];
if ( get_option( 'launch_builder_remove_all_data' ) ) {
	$lb_options = array_merge( $lb_options, $lb_keep );
	$lb_keep = [];
}
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
