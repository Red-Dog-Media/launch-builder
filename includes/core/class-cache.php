<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page-cache purging, coalesced: writers call Cache::purge_later() as often as they like and the caches are purged once
 * per request, by flush() before the response goes out (so header-based purges such as LiteSpeed's still reach the
 * server): the MCP routes call it, and boot() runs it before every REST response (tools over the WP Abilities API, the
 * editor's saves) and before every redirect (wp-admin saves). The shutdown action is the fallback.
 *
 * Purged: SiteGround, LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, Nginx Helper, the Cloudflare plugin, the
 * Rank Math sitemap cache and Perfmatters' used CSS. Anything else hooks the action launch_builder_cache_purge.
 */
final class Cache {

	/** Module hook: flush a waiting purge before REST responses and redirects (no-op when nothing waits). */
	public static function boot(): void {
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'flush_through' ], 999 );
		add_filter( 'wp_redirect', [ __CLASS__, 'flush_through' ], 999 );
	}

	/**
	 * Filter callback: flush, then pass the value on unchanged.
	 *
	 * @param mixed $value REST response or redirect location.
	 * @return mixed
	 */
	public static function flush_through( $value ) {
		self::flush();
		return $value;
	}

	/** @var bool a purge is waiting. */
	private static $pending = false;

	/** @var bool the shutdown fallback is registered. */
	private static $hooked = false;

	/** Ask for one purge before this request ends. */
	public static function purge_later(): void {
		self::$pending = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ __CLASS__, 'flush' ], 0 );
		}
	}

	/**
	 * Run the waiting purge now (no-op when nothing is waiting).
	 *
	 * @return bool whether a purge ran.
	 */
	public static function flush(): bool {
		if ( ! self::$pending ) {
			return false;
		}
		self::$pending = false;
		self::purge_all();
		return true;
	}

	/** Purge every known page cache (the list Company used to run inline, plus Nginx Helper, Cloudflare, Rank Math, Perfmatters). */
	private static function purge_all(): void {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'rt_nginx_helper_purge_all' ); // Nginx Helper.
		if ( class_exists( '\CF\WordPress\Hooks' ) ) { // The Cloudflare plugin (purges through its own API credentials).
			try {
				$cf = new \CF\WordPress\Hooks();
				if ( method_exists( $cf, 'purgeCacheEverything' ) ) {
					$cf->purgeCacheEverything();
				}
			} catch ( \Throwable $e ) { // A purge failure must never break the request that triggered it.
				unset( $e );
			}
		}
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
		// Perfmatters "Remove Unused CSS": per-page used CSS is rebuilt on the next visit (method checked against 2.4.8).
		if ( defined( 'PERFMATTERS_CACHE_DIR' ) && is_dir( PERFMATTERS_CACHE_DIR . 'css' ) && class_exists( '\Perfmatters\CSS' ) && method_exists( '\Perfmatters\CSS', 'clear_used_css' ) ) {
			try {
				\Perfmatters\CSS::clear_used_css();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		do_action( 'launch_builder_cache_purge' );
	}
}
