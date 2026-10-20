<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Global areas (legacy): a synced pattern shown on every page at a fixed place in the Kadence layout.
 * Edit the synced pattern once and every page updates. Areas map to Kadence hooks.
 *
 * Chrome (class-chrome.php) owns the same places per page role now. An area yields at runtime whenever Chrome has
 * applied the slot it maps to (AREA_SLOTS), so nothing prints twice; the migration chrome-adopt-legacy moves the areas
 * into the chrome map ({synced} designs) and clears them here.
 */
final class Globals {

	private const OPT = 'launch_builder_globals';

	/** area => Kadence hook */
	private const HOOKS = [
		'above_header' => 'kadence_before_header',
		'below_header' => 'kadence_after_header',
		'above_footer' => 'kadence_before_footer',
		'footer'       => 'kadence_after_footer', // Replaces the theme footer (it is hidden).
	];

	/** area => the chrome slot that owns the same place (below_header has none). */
	const AREA_SLOTS = [
		'above_header' => 'notice',
		'above_footer' => 'before_footer',
		'footer'       => 'footer',
	];

	public static function boot(): void {
		if ( ! self::map() || ! Plugin::kadence_theme_active() ) {
			return; // Nothing configured (the option is autoloaded, so this costs no query).
		}
		foreach ( self::HOOKS as $area => $hook ) {
			add_action( $hook, static function () use ( $area ) { self::render( $area ); } );
		}
		add_action( 'wp_head', [ __CLASS__, 'css' ], 99 );
	}

	public static function map(): array {
		return (array) get_option( self::OPT, [] );
	}

	/** Module hook: the area map in site-state. */
	public static function site_state( array &$state ): void {
		$state['global_areas'] = self::map();
	}

	public static function areas(): array {
		return array_keys( self::HOOKS );
	}

	public static function set( string $area, int $block_id ): bool {
		if ( ! isset( self::HOOKS[ $area ] ) ) {
			return false;
		}
		$map = self::map();
		if ( $block_id ) {
			$map[ $area ] = $block_id;
		} else {
			unset( $map[ $area ] );
		}
		update_option( self::OPT, $map, true );
		return true;
	}

	/** Does Chrome own this area's place (its slot has been applied)? */
	private static function yields( string $area ): bool {
		return isset( self::AREA_SLOTS[ $area ] ) && Chrome::applied( self::AREA_SLOTS[ $area ] );
	}

	private static function render( string $area ): void {
		$id = (int) ( self::map()[ $area ] ?? 0 );
		if ( ! $id || 'publish' !== get_post_status( $id ) || is_admin() || self::yields( $area ) ) {
			return;
		}
		echo '<div class="lb-global lb-global--' . esc_attr( $area ) . '">' . do_blocks( '<!-- wp:block {"ref":' . $id . '} /-->' ) . '</div>'; // phpcs:ignore
	}

	public static function css(): void {
		$id = (int) ( self::map()['footer'] ?? 0 );
		if ( $id && 'publish' === get_post_status( $id ) && ! self::yields( 'footer' ) ) { // Only hide the theme footer when ours will actually print.
			echo '<style id="lb-global-footer">#colophon{display:none}</style>' . "\n";
		}
	}
}
