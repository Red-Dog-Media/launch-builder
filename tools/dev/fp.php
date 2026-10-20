<?php
// Fingerprint of what a build touches: posts (type, parent, name, title, content, excerpt, status), Business Info, menus.
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_type, post_parent, post_name, post_title, MD5(post_content) c, MD5(post_excerpt) e, post_status, menu_order FROM {$wpdb->posts} WHERE post_type IN ('page','lb_service','lb_area','kadence_navigation','mb-post-type','meta-box','mb-settings-page','kadence_element','kadence_header','kadence_query','kadence_query_card') AND post_status <> 'auto-draft' ORDER BY ID", ARRAY_A );
echo md5( wp_json_encode( $rows ) ), ' posts=', count( $rows ), ' biz=', md5( wp_json_encode( get_option( 'business_info' ) ) ), "\n";
foreach ( $rows as $r ) { echo implode( '|', $r ), "\n"; }
