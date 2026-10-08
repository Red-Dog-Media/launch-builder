<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Global areas: a synced pattern shown on every page at a fixed place in the Kadence layout.
 * Edit the synced pattern once and every page updates. Areas map to Kadence hooks.
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

	private static function render( string $area ): void {
		$id = (int) ( self::map()[ $area ] ?? 0 );
		if ( ! $id || 'publish' !== get_post_status( $id ) || is_admin() ) {
			return;
		}
		echo '<div class="lb-global lb-global--' . esc_attr( $area ) . '">' . do_blocks( '<!-- wp:block {"ref":' . $id . '} /-->' ) . '</div>'; // phpcs:ignore
	}

	public static function css(): void {
		$id = (int) ( self::map()['footer'] ?? 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) { // Only hide the theme footer when ours will actually print.
			echo '<style id="lb-global-footer">#colophon{display:none}</style>' . "\n";
		}
	}
}
