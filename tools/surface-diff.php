<?php
/**
 * Compare two runs of tools/surface-snapshot.php: prints what was added (+), removed (-) or changed (~), and exits 1 when
 * anything differs. A pure move of code (a file renamed, a method moved into a trait) prints nothing: methods are
 * compared by their source text, not by where it lives.
 *
 *   php tools/surface-diff.php before.json after.json
 */

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php tools/surface-diff.php before.json after.json\n" );
	exit( 2 );
}
$a = json_decode( (string) file_get_contents( $argv[1] ), true );
$b = json_decode( (string) file_get_contents( $argv[2] ), true );
if ( ! is_array( $a ) || ! is_array( $b ) ) {
	fwrite( STDERR, "Both files must be JSON from tools/surface-snapshot.php.\n" );
	exit( 2 );
}

$lines = [];
$short = static function ( $v ): string {
	$s = is_string( $v ) ? $v : (string) json_encode( $v, JSON_UNESCAPED_SLASHES );
	return strlen( $s ) > 160 ? substr( $s, 0, 157 ) . '...' : $s;
};
$is_list = static function ( $v ): bool {
	return is_array( $v ) && array_keys( $v ) === range( 0, count( $v ) - 1 );
};
$walk = static function ( $x, $y, string $path ) use ( &$walk, &$lines, $short, $is_list ) {
	if ( $is_list( $x ) && $is_list( $y ) && ! array_filter( array_merge( $x, $y ), 'is_array' ) ) {
		foreach ( array_diff( $x, $y ) as $v ) {
			$lines[] = '- ' . $path . ': ' . $short( $v );
		}
		foreach ( array_diff( $y, $x ) as $v ) {
			$lines[] = '+ ' . $path . ': ' . $short( $v );
		}
		if ( ! array_diff( $x, $y ) && ! array_diff( $y, $x ) && $x !== $y ) {
			$lines[] = '~ ' . $path . ': same items, different order';
		}
		return;
	}
	if ( is_array( $x ) && is_array( $y ) ) {
		$keys = array_unique( array_merge( array_keys( $x ), array_keys( $y ) ) );
		sort( $keys );
		foreach ( $keys as $k ) {
			if ( ! array_key_exists( $k, $x ) ) {
				$lines[] = '+ ' . $path . '/' . $k . ': ' . $short( $y[ $k ] );
			} elseif ( ! array_key_exists( $k, $y ) ) {
				$lines[] = '- ' . $path . '/' . $k . ': ' . $short( $x[ $k ] );
			} else {
				$walk( $x[ $k ], $y[ $k ], $path . '/' . $k );
			}
		}
		return;
	}
	if ( $x !== $y ) {
		$lines[] = '~ ' . $path . ': ' . $short( $x ) . ' -> ' . $short( $y );
	}
};
$walk( $a, $b, '' );
echo $lines ? implode( "\n", $lines ) . "\n" : "No differences.\n";
exit( $lines ? 1 : 0 );
