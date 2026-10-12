<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Kadence uniqueIDs for markup Launch Builder writes into a post (a page, a Kadence element, a synced pattern, a block
 * header).
 *
 * Kadence keys its per-block CSS by the block's uniqueID (printed once per ID per page) and its editor regenerates an ID
 * of the form {a}_{b} whose prefix is not the post being edited. Pattern files carry the IDs they were generated with, so
 * two writes of one pattern share IDs (the second instance on a page gets the first one's CSS) and opening the written
 * post in the editor changes every ID (a false "edited" for the Ledger).
 *
 * rekey() gives every block that has a uniqueID the ID {target_post_id}_{h6}, h6 = the first 6 hex characters of
 * md5( salt | block path ), so:
 *  - the prefix is the post the markup lives in: the editor keeps the IDs (no re-key on open, no false kept_edits);
 *  - the result is deterministic: writing the same design to the same post again gives byte-identical markup;
 *  - blocks at different paths (two sections built from one pattern included) never share an ID.
 * The ID is replaced in the block's comment attributes and, by string substitution, in its own saved HTML (class names
 * such as kadence-column{ID}, kt-adv-heading{ID}, kb-btns{ID}, data-kb-block="kb-adv-heading{ID}", kt-modal{ID}-content).
 * The result is parsed again and validated (every block keeps its place, every new ID is unique and present, no old ID
 * is left anywhere); on any doubt the markup is NOT changed and a WP_Error says why.
 */
final class Unique_Ids {

	/**
	 * Re-key every Kadence uniqueID in $markup for the post it is written into.
	 *
	 * @param string $markup    serialized blocks.
	 * @param int    $target_id the post the markup is stored in (> 0).
	 * @param string $salt      what is being written (pattern slug, chrome key...): part of the hash.
	 * @return string|\WP_Error the re-keyed markup (unchanged when it holds no uniqueID).
	 */
	public static function rekey( string $markup, int $target_id, string $salt = '' ) {
		if ( $target_id <= 0 ) {
			return new \WP_Error( 'lb_rekey_target', 'rekey needs the id of the post the markup is written into.' );
		}
		if ( false === strpos( $markup, '"uniqueID"' ) ) {
			return $markup;
		}
		$blocks = parse_blocks( $markup );
		$map = []; // path => [ old, new ]
		self::walk( $blocks, '', $target_id, $salt, $map );
		if ( ! $map ) {
			return $markup;
		}
		$news = array_column( $map, 1 );
		if ( count( $news ) !== count( array_unique( $news ) ) ) {
			return new \WP_Error( 'lb_rekey_collision', 'Two blocks would get the same uniqueID.' );
		}
		$out = serialize_blocks( $blocks );

		// Old IDs still in the markup (a block that mentions another block's ID in its own HTML): replace those that map to
		// exactly one new ID, longest first so an ID that is a prefix of another is never cut short.
		$olds = [];
		foreach ( $map as [ $old, $new ] ) {
			$olds[ $old ][] = $new;
		}
		$single = array_filter( $olds, static function ( $n ) { return 1 === count( array_unique( $n ) ); } );
		$left = array_filter( array_keys( $single ), static function ( $old ) use ( $out ) { return false !== strpos( $out, $old ); } );
		if ( $left ) {
			usort( $left, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			$re = '/(?<![0-9])(' . implode( '|', array_map( static function ( $s ) { return preg_quote( $s, '/' ); }, $left ) ) . ')(?![0-9a-z_])/';
			$out = (string) preg_replace_callback( $re, static function ( $m ) use ( $single ) { return $single[ $m[1] ][0]; }, $out );
		}

		// Validate: same block tree, every block's new ID in place, nothing old left (the target prefix never collides with
		// an old ID because the h6 part has no dash, while every Kadence-made ID has one).
		$check = [];
		self::collect( parse_blocks( $out ), '', $check );
		$want = [];
		foreach ( $map as $path => [ , $new ] ) {
			$want[ $path ] = $new;
		}
		if ( $check !== $want ) {
			return new \WP_Error( 'lb_rekey_invalid', 'The re-keyed markup does not parse back to the same blocks.' );
		}
		foreach ( array_keys( $olds ) as $old ) {
			if ( ! in_array( $old, $news, true ) && preg_match( '/(?<![0-9])' . preg_quote( $old, '/' ) . '(?![0-9a-z_])/', $out ) ) {
				return new \WP_Error( 'lb_rekey_left', 'An old uniqueID is still in the markup: ' . $old );
			}
		}
		return $out;
	}

	/** The new ID of the block at $path (exactly one underscore; [0-9a-f] after it). */
	public static function id_for( int $target_id, string $salt, string $path ): string {
		return $target_id . '_' . substr( md5( $salt . '|' . $path ), 0, 6 );
	}

	/** Give each block with a uniqueID its new ID (attrs + its own HTML); $map collects path => [ old, new ]. */
	private static function walk( array &$blocks, string $prefix, int $target_id, string $salt, array &$map ): void {
		$i = 0;
		foreach ( $blocks as &$b ) {
			if ( empty( $b['blockName'] ) ) {
				continue; // Whitespace between blocks has no path of its own.
			}
			$path = '' === $prefix ? (string) $i : $prefix . '-' . $i;
			$i++;
			$old = isset( $b['attrs']['uniqueID'] ) && is_string( $b['attrs']['uniqueID'] ) ? $b['attrs']['uniqueID'] : '';
			if ( '' !== $old ) {
				$new = self::id_for( $target_id, $salt, $path );
				$b['attrs']['uniqueID'] = $new;
				if ( $old !== $new ) {
					$re = '/(?<![0-9])' . preg_quote( $old, '/' ) . '(?![0-9a-z_])/';
					$b['innerHTML'] = (string) preg_replace( $re, $new, (string) ( $b['innerHTML'] ?? '' ) );
					foreach ( (array) ( $b['innerContent'] ?? [] ) as $k => $chunk ) {
						if ( is_string( $chunk ) ) {
							$b['innerContent'][ $k ] = (string) preg_replace( $re, $new, $chunk );
						}
					}
				}
				$map[ $path ] = [ $old, $new ];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], $path, $target_id, $salt, $map );
			}
		}
		unset( $b );
	}

	/** path => uniqueID of every block that has one (same path scheme as walk()). */
	private static function collect( array $blocks, string $prefix, array &$out ): void {
		$i = 0;
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$path = '' === $prefix ? (string) $i : $prefix . '-' . $i;
			$i++;
			if ( isset( $b['attrs']['uniqueID'] ) && is_string( $b['attrs']['uniqueID'] ) && '' !== $b['attrs']['uniqueID'] ) {
				$out[ $path ] = $b['attrs']['uniqueID'];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::collect( $b['innerBlocks'], $path, $out );
			}
		}
	}
}
