<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership ledger: what Launch Builder wrote outside its own options, so a re-run can tell its own work from an admin's
 * edits. One row per key (the same stable key the writer uses, e.g. chrome-hero-1a2b3c4d):
 *   { type, id, hash, status, pruned_by_lb, last_write }
 *   type   what the object is: a post type (kadence_element, wp_block, kadence_navigation...), 'option' or 'theme_mod'
 *   id     post id, or the option name
 *   hash   hash of the STORED state right after LB wrote it (hash_post / hash_option / hash_mods, read back after saving,
 *          so kses or other save filters do not make a fresh object look edited)
 *   status the status LB left it in (publish, draft...; 'set' for options)
 *   title  optional: the post title LB last wrote, so a later write can tell its own title from one a person changed
 *          (record() keeps it when a caller does not pass one)
 *
 * Writers' rules: before overwriting, if is_edited() -> report kept_edits and skip; never resurrect
 * an object that is trashed or that an admin drafted; prune only rows whose hash still matches, and mark_pruned() them;
 * forget() a row when its object is gone for good.
 *
 * Storage: one non-autoloaded option per row, launch_builder_ledger_<md5(key)>, holding the row plus its key. Every
 * write touches exactly one option, so writers of different keys (chrome, nav, style, compat, often in different
 * requests at once) never lose each other's rows, and a write costs the same however many rows exist.
 */
final class Ledger {

	const PREFIX = 'launch_builder_ledger_';

	/** @return array<string,array> every row, by key (one query). */
	public static function all(): array {
		global $wpdb;
		$vals = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = [];
		foreach ( (array) $vals as $v ) {
			$r = maybe_unserialize( $v );
			if ( is_array( $r ) && isset( $r['key'] ) ) {
				$k = (string) $r['key'];
				unset( $r['key'] );
				$rows[ $k ] = $r;
			}
		}
		ksort( $rows );
		return $rows;
	}

	/** @return array|null the row for $key. */
	public static function get( string $key ): ?array {
		$r = get_option( self::option( $key ), null );
		if ( ! is_array( $r ) ) {
			return null;
		}
		unset( $r['key'] );
		return $r;
	}

	/**
	 * Record (or replace) what LB just wrote.
	 *
	 * @param string     $key    stable writer key.
	 * @param string     $type   post type, 'option' or 'theme_mod'.
	 * @param int|string $id     post id or option name.
	 * @param string     $hash   hash of the stored state after the write.
	 * @param string     $status status LB left the object in.
	 * @param string|null $title the post title LB wrote (null keeps the one already recorded, if any).
	 */
	public static function record( string $key, string $type, $id, string $hash, string $status, ?string $title = null ): void {
		$row = [
			'type'         => $type,
			'id'           => is_numeric( $id ) ? (int) $id : (string) $id,
			'hash'         => $hash,
			'status'       => $status,
			'pruned_by_lb' => false,
			'last_write'   => time(),
		];
		if ( null === $title ) {
			$old = self::get( $key );
			$title = $old && isset( $old['title'] ) ? (string) $old['title'] : null;
		}
		if ( null !== $title ) {
			$row['title'] = $title;
		}
		self::put( $key, $row );
	}

	/** The post title LB last wrote for $key (null when none was recorded, e.g. a row from before titles were tracked). */
	public static function title( string $key ): ?string {
		$row = self::get( $key );
		return $row && isset( $row['title'] ) ? (string) $row['title'] : null;
	}

	/**
	 * record() for a batch: key => [ type, id, hash, status ] (list or the same names as keys).
	 *
	 * @return int rows written.
	 */
	public static function record_many( array $rows ): int {
		$n = 0;
		foreach ( $rows as $key => $r ) {
			$r = array_values( (array) $r );
			if ( count( $r ) >= 4 ) {
				self::record( (string) $key, (string) $r[0], $r[1], (string) $r[2], (string) $r[3] );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Has the object changed since LB last wrote it? False when there is no row (LB never wrote it, so it is not "edited";
	 * it is foreign or new and the caller decides).
	 */
	public static function is_edited( string $key, string $current_hash ): bool {
		$row = self::get( $key );
		return null !== $row && ! hash_equals( (string) $row['hash'], $current_hash );
	}

	/** Mark a row as drafted by LB itself (a prune), so it is not mistaken for an admin's draft. @return bool row existed */
	public static function mark_pruned( string $key ): bool {
		$row = self::get( $key );
		if ( null === $row ) {
			return false;
		}
		$row['pruned_by_lb'] = true;
		$row['status'] = 'draft';
		$row['last_write'] = time();
		self::put( $key, $row );
		return true;
	}

	/** Drop a row. @return bool row existed */
	public static function forget( string $key ): bool {
		return delete_option( self::option( $key ) );
	}

	/** The option that holds $key's row. */
	public static function option( string $key ): string {
		return self::PREFIX . md5( $key );
	}

	private static function put( string $key, array $row ): void {
		update_option( self::option( $key ), [ 'key' => $key ] + $row, false );
	}

	/* ------------------------------------------------------------------ hashes of stored state */

	/** Hash of a post's stored content plus the given meta keys (all values of each key, as stored). */
	public static function hash_post( int $post_id, array $meta_keys = [] ): string {
		$state = [ 'content' => (string) get_post_field( 'post_content', $post_id, 'raw' ), 'meta' => [] ];
		foreach ( $meta_keys as $k ) {
			$state['meta'][ (string) $k ] = get_post_meta( $post_id, (string) $k, false );
		}
		return self::hash_value( $state );
	}

	/** Hash of an option, or only of some of its sub-keys (missing sub-keys count as null). */
	public static function hash_option( string $option, array $sub_keys = [] ): string {
		$v = get_option( $option, null );
		if ( $sub_keys ) {
			$arr = is_array( $v ) ? $v : [];
			$v = [];
			foreach ( $sub_keys as $k ) {
				$v[ (string) $k ] = $arr[ $k ] ?? null;
			}
		}
		return self::hash_value( $v );
	}

	/** Hash of some theme mods of the active theme. */
	public static function hash_mods( array $keys ): string {
		$v = [];
		foreach ( $keys as $k ) {
			$v[ (string) $k ] = get_theme_mod( (string) $k, null );
		}
		return self::hash_value( $v );
	}

	/** Order-independent hash of any value (associative arrays are key-sorted at every depth). */
	public static function hash_value( $value ): string {
		$canon = static function ( $v ) use ( &$canon ) {
			if ( is_object( $v ) ) {
				$v = (array) $v;
			}
			if ( ! is_array( $v ) ) {
				return $v;
			}
			if ( array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
				ksort( $v );
			}
			return array_map( $canon, $v );
		};
		return md5( (string) wp_json_encode( $canon( $value ) ) );
	}
}
