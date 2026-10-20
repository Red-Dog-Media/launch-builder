<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Named locks with a time-to-live, so two MCP sessions (or an admin and a tool) never run the same long job at once:
 *   if ( ! Lock::acquire( 'chrome', 120 ) ) { return new \WP_Error( 'lb_busy', ..., [ 'status' => 409 ] ); }
 *   try { ... } finally { Lock::release( 'chrome' ); }
 * Names used by the plan: chrome, build, sync, migrate.
 *
 * Storage: one non-autoloaded option per lock (launch_builder_lock_<name>) holding "<expiry>:<owner token>". It works
 * like add_option() (which fails when the option exists) but uses INSERT IGNORE so two requests racing for the same lock
 * cannot both win. An expired lock is taken over; a crashed holder therefore blocks for at most its TTL. release() only
 * deletes the lock this request took, so a holder that overran its TTL cannot free the next holder's lock.
 */
final class Lock {

	const PREFIX = 'launch_builder_lock_';

	/** @var array<string,string> option name => value this request wrote (its ownership proof). */
	private static $mine = [];

	/**
	 * Take the lock. Returns false while another holder's lock is still valid.
	 *
	 * @param string $name lock name ([a-z0-9_-]).
	 * @param int    $ttl  seconds until the lock expires on its own (1 .. 3600).
	 */
	public static function acquire( string $name, int $ttl = 60 ): bool {
		global $wpdb;
		$opt = self::option( $name );
		$ttl = max( 1, min( 3600, $ttl ) );
		$now = time();
		// Clear an expired lock first (only if it is still the expired one). Expiry values are 10-digit timestamps, so a
		// string comparison orders them correctly on MySQL and SQLite alike.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s", $opt, (string) $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$val = ( $now + $ttl ) . ':' . wp_generate_password( 12, false );
		$won = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, $val ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== $won ) {
			return false;
		}
		self::$mine[ $opt ] = $val;
		return true;
	}

	/** Release a lock this request took (false, and nothing deleted, when it is not ours or not held). */
	public static function release( string $name ): bool {
		global $wpdb;
		$opt = self::option( $name );
		if ( ! isset( self::$mine[ $opt ] ) ) {
			return false;
		}
		$done = (bool) $wpdb->delete( $wpdb->options, [ 'option_name' => $opt, 'option_value' => self::$mine[ $opt ] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$mine[ $opt ] );
		wp_cache_delete( $opt, 'options' );
		return $done;
	}

	/** Did this request take the lock (and not release it)? Code that runs inside a caller's locked section can tell, instead of waiting for a lock its own caller holds. */
	public static function mine( string $name ): bool {
		return isset( self::$mine[ self::option( $name ) ] );
	}

	/** Is the lock held (and not expired)? */
	public static function held( string $name ): bool {
		global $wpdb;
		$exp = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $exp && (int) $exp >= time();
	}

	private static function option( string $name ): string {
		return self::PREFIX . substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $name ) ), 0, 40 );
	}
}
