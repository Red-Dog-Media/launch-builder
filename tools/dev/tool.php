<?php
// Usage: run.php <site> tool.php <tool-name> [json-input] [--short]
$name = $GLOBALS['lbt_args'][0] ?? '';
$in = json_decode( $GLOBALS['lbt_args'][1] ?? '{}', true ) ?: [];
$a = wp_get_ability( 'launch-builder/' . $name );
if ( ! $a ) { echo "no ability $name\n"; exit( 1 ); }
$r = $a->execute( $in );
echo is_wp_error( $r ) ? 'WP_Error ' . $r->get_error_code() . ': ' . $r->get_error_message() . "\n" : wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
