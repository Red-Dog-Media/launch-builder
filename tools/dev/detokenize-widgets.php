<?php
// Run on a disposable seeded site with Business Info and a generated sidebar.
use LaunchBuilder\Abilities;
use LaunchBuilder\Launch;

$before = get_option( 'widget_block', [] );
$dry = Launch::detokenize( [ 'scope' => 'designs', 'dry_run' => true ] );
if ( is_wp_error( $dry ) || empty( $dry['totals']['changed_widgets'] ) ) {
	throw new RuntimeException( 'Seed a sidebar with Business Info tokens before this check.' );
}
if ( get_option( 'widget_block', [] ) !== $before ) {
	throw new RuntimeException( 'Dry run changed block widgets.' );
}
$applied = Launch::detokenize( [ 'scope' => 'designs', 'dry_run' => false ] );
if ( is_wp_error( $applied ) || empty( $applied['snapshot'] ) || get_option( 'widget_block', [] ) === $before ) {
	throw new RuntimeException( 'Widget baking or its snapshot failed.' );
}
$after = wp_json_encode( get_option( 'widget_block', [] ) );
if ( false !== strpos( $after, '{{lb.phone}}' ) || false !== strpos( $after, '#lb-phone_link' ) ) {
	throw new RuntimeException( 'Sidebar Business Info tokens were not baked.' );
}
Abilities::restore_snapshot( [ 'id' => $applied['snapshot'] ] );
if ( get_option( 'widget_block', [] ) !== $before ) {
	throw new RuntimeException( 'Undo did not restore the original widget option.' );
}
echo "4 widget dry-run/bake/undo checks passed.\n";
