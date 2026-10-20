<?php
// Run on a seeded disposable WordPress site: wp eval-file tools/dev/query-regression.php --user=admin
// Exercises real query posts and compares the removal snippet with the plugin's runtime filter.
use LaunchBuilder\Query_Lists;

$cases = [];
foreach ( get_posts( [ 'post_type' => 'kadence_query', 'numberposts' => -1 ] ) as $query_post ) {
	$meta = get_post_meta( $query_post->ID, '_kad_query_query', true );
	$pt = $meta['postType'][0] ?? 'lb_service';
	foreach ( get_posts( [ 'post_type' => $pt, 'numberposts' => -1 ] ) as $viewed ) {
		$_GET[ $query_post->ID . '_query_exclude_post_id' ] = $viewed->ID;
		$input = [ 'post_type' => [ $pt ], 'post__not_in' => [ $viewed->ID, 999999 ], 'post_parent__in' => [ 999999 ] ];
		$expected = Query_Lists::query_vars( $input, [], $query_post->ID );
		$mode = get_post_meta( $query_post->ID, '_lb_query', true );
		if ( 'top' === $mode && in_array( $viewed->ID, $expected['post__not_in'], true ) ) {
			throw new RuntimeException( 'A top-level list excluded the current post.' );
		}
		if ( ! in_array( 999999, $expected['post__not_in'], true ) ) {
			throw new RuntimeException( 'An unrelated exclusion was removed.' );
		}
		$cases[] = [ $query_post->ID, $viewed->ID, $input, [], $expected ];
		$explicit_meta = [ 'exclude' => [ (string) $viewed->ID ] ];
		$explicit = Query_Lists::query_vars( $input, $explicit_meta, $query_post->ID );
		if ( ! in_array( $viewed->ID, $explicit['post__not_in'], true ) ) {
			throw new RuntimeException( 'An editor-saved exclusion of the current post was removed.' );
		}
		$cases[] = [ $query_post->ID, $viewed->ID, $input, $explicit_meta, $explicit ];
		unset( $_GET[ $query_post->ID . '_query_exclude_post_id' ] );
	}
}
if ( ! $cases ) {
	throw new RuntimeException( 'Seed the sample brief and place a look before running this check.' );
}
remove_filter( 'kadence_blocks_pro_query_loop_query_vars', [ Query_Lists::class, 'query_vars' ], 10 );
$snippet = wp_tempnam( 'service-lists.php' );
file_put_contents( $snippet, Query_Lists::snippet() );
try {
	require $snippet;
	foreach ( $cases as [ $ql_id, $viewed, $input, $query_meta, $expected ] ) {
		$_GET[ $ql_id . '_query_exclude_post_id' ] = $viewed;
		$actual = apply_filters( 'kadence_blocks_pro_query_loop_query_vars', $input, $query_meta, $ql_id );
		if ( $expected != $actual ) {
			throw new RuntimeException( 'Removal snippet differs for query ' . $ql_id . ', viewed post ' . $viewed );
		}
		unset( $_GET[ $ql_id . '_query_exclude_post_id' ] );
	}
} finally {
	unlink( $snippet );
}
echo count( $cases ) . " query/snippet parity cases passed.\n";
