<?php
// Run only on a disposable seeded database: this check deliberately edits and replaces native designs.
use LaunchBuilder\Abilities;
use LaunchBuilder\Design_Hub;
use LaunchBuilder\Query_Lists;

$assert = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$checks = 0;
foreach ( [ 'areas', 'sidebar' ] as $piece ) {
	foreach ( [ 'card', 'query' ] as $edit ) {
	$input = [ 'pieces' => [ $piece => 'home-services' ], 'layouts' => false ];
	$initial = Design_Hub::set( $input + [ 'force' => true ] );
	$assert( ! is_wp_error( $initial ), 'Could not seed ' . $piece );
	$posts = get_posts( [ 'post_type' => [ 'kadence_query', 'kadence_query_card' ], 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_lb_key', 'meta_value' => 'design-' . $piece . '-list-', 'meta_compare' => 'LIKE' ] );
	$query = null;
	$card = null;
	foreach ( $posts as $post ) {
		if ( 'kadence_query' === $post->post_type ) { $query = $post; } else { $card = $post; }
	}
	$assert( $query && $card, 'Missing query/card for ' . $piece );
	$edited_content = $card->post_content;
	$meta = get_post_meta( $query->ID, '_kad_query_query', true );
	if ( 'card' === $edit ) {
		$edited_content .= "\n<!-- wp:paragraph --><p>Editor-owned card text</p><!-- /wp:paragraph -->";
		wp_update_post( [ 'ID' => $card->ID, 'post_content' => wp_slash( $edited_content ) ] );
	} else {
		$meta['perPage'] = '3';
		update_post_meta( $query->ID, '_kad_query_query', $meta );
	}
	$dry = Design_Hub::set( $input + [ 'dry_run' => true ] );
	$assert( 'keep' === $dry['pieces'][ $piece ]['action'], 'Dry run did not protect native list edits: ' . $piece );
	$kept = Design_Hub::set( $input );
	$assert( 'keep' === $kept['pieces'][ $piece ]['action'], 'Reapplying a design did not keep edited list posts: ' . $piece );
	$assert( $edited_content === get_post_field( 'post_content', $card->ID, 'raw' ), 'Card text overwritten: ' . $piece );
	$assert( $meta === get_post_meta( $query->ID, '_kad_query_query', true ), 'Query settings overwritten: ' . $piece );
	$checks += 4;
	$forced = Design_Hub::set( $input + [ 'force' => true ] );
	$assert( 'update' === $forced['pieces'][ $piece ]['action'], 'Explicit replacement failed: ' . $piece );
	$assert( 'card' === $edit ? $edited_content !== get_post_field( 'post_content', $card->ID, 'raw' ) : $meta !== get_post_meta( $query->ID, '_kad_query_query', true ), 'Force kept old native list edit: ' . $piece );
	Abilities::restore_snapshot( [ 'id' => $forced['snapshot'] ] );
	$assert( $edited_content === get_post_field( 'post_content', $card->ID, 'raw' ), 'Undo did not restore card text: ' . $piece );
	$assert( $meta === get_post_meta( $query->ID, '_kad_query_query', true ), 'Undo did not restore query settings: ' . $piece );
	$checks += 4;
	}
}
$input = [ 'pieces' => [ 'sidebar' => 'home-services' ], 'layouts' => false ];
Design_Hub::set( $input + [ 'force' => true ] );
$state = get_option( Design_Hub::OPT );
$number = $state['sidebar'][0];
$widgets = get_option( 'widget_block' );
$widgets[ $number ]['content'] .= '<!-- wp:paragraph --><p>Editor-owned sidebar text</p><!-- /wp:paragraph -->';
update_option( 'widget_block', $widgets );
$kept = Design_Hub::set( $input );
$assert( 'keep' === $kept['pieces']['sidebar']['action'], 'Edited sidebar widget was not kept.' );
$assert( $widgets === get_option( 'widget_block' ), 'Edited sidebar widget was overwritten.' );
$forced = Design_Hub::set( $input + [ 'force' => true ] );
$assert( $widgets !== get_option( 'widget_block' ), 'Explicit sidebar replacement did not apply.' );
Abilities::restore_snapshot( [ 'id' => $forced['snapshot'] ] );
$assert( $widgets === get_option( 'widget_block' ), 'Undo did not restore edited sidebar widget.' );
$checks += 4;
echo $checks . " native design ownership/undo checks passed.\n";
