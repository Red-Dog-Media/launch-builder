<?php
$brief = json_decode( file_get_contents( $GLOBALS['lbt_args'][0] ), true );
$dry = 'apply' !== ( $GLOBALS['lbt_args'][1] ?? '' );
$r = LaunchBuilder\Builder::build( $brief, $dry );
echo wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
