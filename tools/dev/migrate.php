<?php
$r = LaunchBuilder\Migrate::run( 60 );
echo wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
foreach ( [ 'lb_service', 'lb_area' ] as $pt ) {
	foreach ( get_posts( [ 'post_type' => $pt, 'post_status' => 'any', 'numberposts' => 30 ] ) as $p ) {
		echo $pt, ' ', $p->ID, ' ', $p->post_name, ' parent=', $p->post_parent, ' ', get_permalink( $p ), "\n";
	}
}
echo 'business_info: ', wp_json_encode( get_option( 'business_info' ) ), "\n";
