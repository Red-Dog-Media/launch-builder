<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side renderer for the launch/rating block. All attributes are re-validated here,
 * so the markup is safe regardless of what was saved in post content.
 */
final class Rating {

	private const STAR_PATH = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';

	public static function render( array $a ): string {
		$type     = self::enum( $a['type'] ?? '', [ 'badge', 'card', 'pill', 'stacked' ], 'badge' );
		$count    = self::int( $a['starCount'] ?? 5, 1, 10 );
		$rating   = max( 0, min( (float) ( $a['rating'] ?? 0 ), $count ) );
		$show     = ! empty( $a['showRating'] );
		$text     = sanitize_text_field( $a['text'] ?? '' );
		$link     = esc_url( is_string( $a['link'] ?? null ) ? $a['link'] : '' );
		$lp       = self::enum( $a['logoPosition'] ?? '', [ 'start', 'end', 'top', 'bottom' ], 'start' );
		$align    = self::enum( $a['contentAlign'] ?? '', [ 'start', 'center', 'end' ], 'start' );
		$mobile   = self::enum( $a['mobile'] ?? '', [ 'stack', 'hide-logos', 'keep' ], 'stack' );
		$bp       = self::enum( (string) ( $a['breakpoint'] ?? '' ), [ '360', '480', '640', '768' ], '480' );
		$logo_fx  = self::enum( $a['logoStyle'] ?? '', [ 'original', 'grayscale', 'grayscale-hover' ], 'original' );
		$max      = self::int( $a['maxWidth'] ?? 0, 0, 1600 );
		$weights  = [ '300', '400', '500', '600', '700', '800' ];

		$vars = [
			'--lb-star'        => self::int( $a['starSize'] ?? 34, 10, 80 ),
			'--lb-star-gap'    => self::int( $a['starGap'] ?? 4, 0, 24 ),
			'--lb-star-color'  => self::color( $a['starColor'] ?? '', '' ),
			'--lb-star-empty'  => self::color( $a['starEmpty'] ?? '', '' ),
			'--lb-rating'      => self::int( $a['ratingSize'] ?? 44, 12, 100 ),
			'--lb-rating-color'=> self::color( $a['ratingColor'] ?? '', '' ),
			'--lb-rating-w'    => self::enum( (string) ( $a['ratingWeight'] ?? '' ), $weights, '300' ),
			'--lb-text'        => self::int( $a['textSize'] ?? 17, 10, 40 ),
			'--lb-text-color'  => self::color( $a['textColor'] ?? '', '' ),
			'--lb-text-w'      => self::enum( (string) ( $a['textWeight'] ?? '' ), $weights, '400' ),
			'--lb-logo-h'      => self::int( $a['logoHeight'] ?? 46, 12, 120 ),
			'--lb-logo-gap'    => self::int( $a['logoGap'] ?? 12, -40, 60 ),
			'--lb-gap'         => self::int( $a['gap'] ?? 22, 0, 80 ),
			'--lb-ms'          => self::int( $a['mobileScale'] ?? 85, 40, 100 ) / 100,
			'--lb-justify'     => [ 'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end' ][ $align ],
			'--lb-maxw'        => $max ? $max . 'px' : '100%',
		];
		$style = '';
		foreach ( $vars as $k => $v ) {
			if ( '' === $v ) {
				continue; // unset colors inherit the theme palette via CSS fallbacks.
			}
			$style .= $k . ':' . $v . ';';
		}

		$shape   = self::enum( $a['shape'] ?? '', [ 'sharp', 'soft', 'round' ], 'soft' );
		$density = self::enum( $a['density'] ?? '', [ 'compact', 'default', 'airy' ], 'default' );
		$classes = [ 'lb-rating', 'lb-rating--' . $type, 'lb-rating--lp-' . $lp, 'lb-rating--fx-' . $logo_fx, 'lb-rating--shape-' . $shape, 'lb-rating--d-' . $density ];
		if ( ! empty( $a['logoDivider'] ) ) {
			$classes[] = 'lb-rating--divider';
		}
		if ( ! $show ) {
			$classes[] = 'lb-rating--no-score';
		}

		$wrapper = get_block_wrapper_attributes(
			[
				'class'       => implode( ' ', $classes ),
				'style'       => $style,
				'data-bp'     => $bp,
				'data-mobile' => $mobile,
			]
		);

		$logos = self::logos( $a['logos'] ?? [] );

		/* translators: 1: rating, 2: max stars */
		$label = sprintf( __( 'Rated %1$s out of %2$s', 'launch-builder' ), self::fmt( $rating ), $count );

		$main_inner  = '';
		if ( $show ) {
			$main_inner .= '<span class="lb-rating__score">' . esc_html( self::fmt( $rating ) ) . '</span>';
		}
		$main_inner .= '<span class="lb-rating__stars" role="img" aria-label="' . esc_attr( $label ) . '">' . self::stars( $rating, $count, self::enum( $a['starStyle'] ?? '', [ 'sharp', 'rounded' ], 'sharp' ) ) . '</span>';
		if ( $text !== '' ) {
			$main_inner .= '<span class="lb-rating__text">' . esc_html( $text ) . '</span>';
		}

		if ( $link ) {
			$rel  = ! empty( $a['newTab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
			$main = '<a class="lb-rating__main" href="' . $link . '"' . $rel . '>' . $main_inner . '</a>';
		} else {
			$main = '<div class="lb-rating__main">' . $main_inner . '</div>';
		}

		return '<div ' . $wrapper . '><div class="lb-rating__inner">' . $logos . $main . '</div></div>';
	}

	private static function stars( float $rating, int $count, string $style ): string {
		$stroke = 'rounded' === $style ? ' stroke="currentColor" stroke-width="2" stroke-linejoin="round"' : '';
		$svg    = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor"' . $stroke . ' d="' . self::STAR_PATH . '"/></svg>';
		$out    = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$pct  = (int) round( max( 0, min( 1, $rating - $i ) ) * 100 );
			$out .= '<span class="lb-rating__star">' . $svg . '<span class="lb-rating__fill" style="width:' . $pct . '%">' . $svg . '</span></span>';
		}
		return $out;
	}

	private static function logos( $items ): string {
		if ( ! is_array( $items ) || ! $items ) {
			return '';
		}
		$out = '';
		foreach ( array_slice( $items, 0, 8 ) as $logo ) {
			if ( ! is_array( $logo ) ) {
				continue;
			}
			$id  = absint( $logo['id'] ?? 0 );
			$alt = is_string( $logo['alt'] ?? null ) ? sanitize_text_field( $logo['alt'] ) : '';
			if ( $id && wp_attachment_is_image( $id ) ) {
				$attrs = [ 'class' => 'lb-rating__logo', 'loading' => 'lazy', 'decoding' => 'async' ];
				if ( '' !== $alt ) {
					$attrs['alt'] = $alt; // Otherwise keep the alt text from the media library.
				}
				$img = wp_get_attachment_image( $id, 'full', false, $attrs );
			} elseif ( is_string( $logo['url'] ?? null ) && '' !== $logo['url'] ) {
				$img = '<img class="lb-rating__logo" src="' . esc_url( $logo['url'] ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">';
			} else {
				continue;
			}
			$vars = '';
			if ( isset( $logo['space'] ) && '' !== $logo['space'] ) {
				$vars .= '--lb-ls:' . self::int( $logo['space'], -60, 80 ) . ';';
			}
			if ( ! empty( $logo['height'] ) ) {
				$vars .= '--lb-lh:' . self::int( $logo['height'], 12, 160 ) . ';';
			}
			$space = $vars ? ' style="' . $vars . '"' : '';
			$href = esc_url( is_string( $logo['link'] ?? null ) ? $logo['link'] : '' );
			$name = '' !== $alt ? $alt : (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( $href ) {
				$out .= '<a class="lb-rating__logo-link"' . $space . ' href="' . $href . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $name ) . '">' . $img . '</a>';
			} else {
				$out .= $space ? preg_replace( '/^<img /', '<img' . $space . ' ', $img ) : $img;
			}
		}
		return $out ? '<div class="lb-rating__logos">' . $out . '</div>' : '';
	}

	private static function fmt( float $n ): string {
		return number_format_i18n( $n, 1 );
	}

	private static function int( $v, int $min, int $max ): int {
		return max( $min, min( $max, (int) $v ) );
	}

	private static function enum( $v, array $allowed, string $default ): string {
		return in_array( $v, $allowed, true ) ? $v : $default;
	}

	private static function color( $v, string $default ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		if ( sanitize_hex_color( $v ) ) {
			return $v;
		}
		if ( preg_match( '/^rgba?\(\s*[\d.\s,%\/]+\)$/', $v ) ) {
			return $v;
		}
		// Theme tokens, optionally with a hex fallback: var(--global-palette3) / var(--global-palette3, #333).
		if ( preg_match( '/^var\(\s*--[a-z0-9-]+\s*(,\s*#[0-9a-fA-F]{3,8}\s*)?\)$/', $v ) ) {
			return $v;
		}
		return $default;
	}
}
