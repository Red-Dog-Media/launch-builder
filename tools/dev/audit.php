<?php
$after = 0; $all = []; $n = 0;
do {
	$r = LaunchBuilder\Audit::run( [ 'after_id' => $after, 'limit' => 100, 'codes' => array_values( array_diff( array_keys( LaunchBuilder\Audit::checks() ), [ 'one_h1' ] ) ) ] );
	$all = array_merge( $all, $r['findings'] ?? [] );
	$after = $r['next_after_id'] ?? null;
} while ( null !== $after && ++$n < 20 );
$by = [];
foreach ( $all as $f ) { $by[ $f['code'] ][] = ( $f['severity'] ?? '' ) . ': ' . mb_substr( (string) ( $f['message'] ?? '' ), 0, 110 ); }
foreach ( $by as $c => $l ) { echo $c, ' (', count( $l ), ")\n  ", implode( "\n  ", array_slice( $l, 0, 2 ) ), "\n"; }
echo 'checks: ', count( LaunchBuilder\Audit::checks() ), "\n";
