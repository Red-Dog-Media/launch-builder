<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Colour helpers: the active Kadence palette, colour parsing, blending and WCAG contrast. Shared by Branding (dashboard
 * colours) and Design (palette and buttons). Kadence's Style Library reads the same theme palette as its starting point.
 */
final class Color {

	/** Kadence's default palette (used when the site has never saved one). */
	const DEFAULT_PALETTE = [ 'palette1' => '#2B6CB0', 'palette2' => '#215387', 'palette3' => '#1A202C', 'palette4' => '#2D3748', 'palette5' => '#4A5568', 'palette6' => '#718096', 'palette7' => '#EDF2F7', 'palette8' => '#F7FAFC', 'palette9' => '#FFFFFF' ];

	/** Minimum contrast: a control against its surface (non-text, WCAG 1.4.11) and body text (WCAG 1.4.3). */
	const MIN_UI   = 3.0;
	const MIN_TEXT = 4.5;

	/** @var array<string,string>|null per-request memo of palette(). */
	private static $palette = null;

	/** The active Kadence palette, slug => #hex (Kadence's defaults where the site has none). */
	public static function palette(): array {
		if ( null !== self::$palette ) {
			return self::$palette;
		}
		$pal = self::DEFAULT_PALETTE;
		$raw = get_option( 'kadence_global_palette', '' );
		$opt = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( is_array( $opt ) ) {
			$active = in_array( $opt['active'] ?? '', [ 'palette', 'second-palette', 'third-palette' ], true ) ? $opt['active'] : 'palette';
			$list = ! empty( $opt[ $active ] ) && is_array( $opt[ $active ] ) ? $opt[ $active ] : (array) ( $opt['palette'] ?? [] );
			foreach ( $list as $e ) {
				if ( is_array( $e ) && isset( $e['slug'], $e['color'] ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $e['color'] ) ) {
					$pal[ (string) $e['slug'] ] = (string) $e['color'];
				}
			}
		}
		return self::$palette = $pal;
	}

	/** Forget the palette memo after a change in this request. */
	public static function forget(): void {
		self::$palette = null;
	}

	/**
	 * A colour as #rrggbb: palette slugs, var(--global-paletteN...), #hex and rgb(a) (alpha blended over $over).
	 * Null for transparent, empty and unknown values.
	 */
	public static function hex( $c, string $over = '#FFFFFF' ): ?string {
		$c = strtolower( trim( (string) $c ) );
		if ( '' === $c || 'transparent' === $c ) {
			return null;
		}
		if ( preg_match( '/^(?:var\(\s*--global-)?(palette\d{1,2})\b/', $c, $m ) ) {
			$p = self::palette();
			return isset( $p[ $m[1] ] ) ? self::hex( $p[ $m[1] ], $over ) : null;
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $c, $m ) ) {
			$c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $c, $m ) ) {
			return isset( $m[2] ) ? self::blend( '#' . $m[1], $over, hexdec( $m[2] ) / 255 ) : '#' . strtoupper( $m[1] );
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+%?)\s*)?\)$/', $c, $m ) ) {
			$hex = sprintf( '#%02X%02X%02X', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
			$a = isset( $m[4] ) ? ( '%' === substr( $m[4], -1 ) ? (float) $m[4] / 100 : (float) $m[4] ) : 1.0;
			return $a >= 1 ? $hex : self::blend( $hex, $over, $a );
		}
		return null;
	}

	/** $a over $b at alpha $alpha. */
	public static function blend( string $a, string $b, float $alpha ): string {
		$ra = self::rgb( $a );
		$rb = self::rgb( $b );
		$o = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$o[] = (int) round( $ra[ $i ] * $alpha + $rb[ $i ] * ( 1 - $alpha ) );
		}
		return vsprintf( '#%02X%02X%02X', $o );
	}

	private static function rgb( string $hex ): array {
		$h = ltrim( (string) self::hex( $hex ), '#' );
		return 6 === strlen( $h ) ? [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) ] : [ 0, 0, 0 ];
	}

	/** WCAG relative luminance of a #hex colour. */
	public static function luminance( string $hex ): float {
		$l = 0.0;
		foreach ( self::rgb( $hex ) as $i => $c ) {
			$c /= 255;
			$c = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			$l += [ 0.2126, 0.7152, 0.0722 ][ $i ] * $c;
		}
		return $l;
	}

	/** WCAG contrast ratio of two colours (anything hex() reads); 1.0 when either is unknown. */
	public static function contrast( $a, $b ): float {
		$ha = self::hex( $a );
		$hb = self::hex( $b );
		if ( ! $ha || ! $hb ) {
			return 1.0;
		}
		$la = self::luminance( $ha );
		$lb = self::luminance( $hb );
		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/** Is a colour dark (light text reads on it)? */
	public static function is_dark( $c ): bool {
		$h = self::hex( $c );
		return $h && self::luminance( $h ) < 0.18;
	}
}
