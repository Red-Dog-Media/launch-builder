<?php
set_current_screen( 'toplevel_page_launch-builder' );
foreach ( [ [ 'LaunchBuilder\Design_Hub_Admin', 'page' ], [ 'LaunchBuilder\Dashboard', 'page_dashboard' ], [ 'LaunchBuilder\Company', 'page' ], [ 'LaunchBuilder\Admin', 'page_start' ], [ 'LaunchBuilder\Admin', 'page_health' ], [ 'LaunchBuilder\Dashboard', 'page_modules' ] ] as $cb ) {
	ob_start();
	try { call_user_func( $cb ); $html = ob_get_clean(); echo implode( '::', $cb ), ': ', strlen( $html ), " bytes\n"; }
	catch ( \Throwable $e ) { ob_end_clean(); echo implode( '::', $cb ), ': ERROR ', $e->getMessage(), ' @ ', $e->getFile(), ':', $e->getLine(), "\n"; }
}
