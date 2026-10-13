<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The launch/rating block (Rating widget v2). Server-side render; every attribute is re-validated
 * here, so the markup is safe whatever was saved in post content.
 *
 * v2 attributes: type badge|card|pill|stars (stacked: legacy, render-only), useGlobal (Business Info, default false so
 * existing blocks keep their numbers), source ('' = overall, or a platform key of Business Info reviews[]), rating and
 * text (overrides), link (blank + a platform source = that platform's link), platforms (bundled round logos), size
 * sm|md|lg (md = the old defaults, sm = the old pill, lg = the old card), contentAlign, phoneAlign auto|center|match
 * (phones: auto centres the content inside a box and leaves a plain widget with the alignment around it, center centres
 * the whole widget, match changes nothing), block styles plain|soft|outline|dark|brand, the legacy media "logos" as
 * custom logos.
 *
 * Back-compat: every legacy attribute (LEGACY) is still declared with its old default, and render applies one only
 * when it is present in the block's parsed attributes (the editor never saves defaults), so default-only blocks follow
 * the size preset. A block with none of the v2 attributes is a legacy block: it keeps the old colours (palette-based),
 * corner radius, breakpoint (legacy.css) and one-line pill layout, so existing pages look the same. v2 blocks inherit their
 * colours (score = currentColor, caption 75%, empty stars 22%, filled stars = --lb-star-color) and stack below 420px
 * (one container query). The v2 pill is the hero rating row: overlapping round logos, then score and
 * stars, the caption below them.
 */
final class Rating {

	private const STAR_PATH = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';

	/** Legacy attributes: applied only when present in the parsed attributes; the inspector offers to reset them. */
	const LEGACY = [ 'starCount', 'starStyle', 'starColor', 'starEmpty', 'starSize', 'starGap', 'ratingSize', 'ratingColor', 'ratingWeight', 'textSize', 'textColor', 'textWeight', 'logoHeight', 'logoGap', 'logoPosition', 'logoStyle', 'logoDivider', 'gap', 'maxWidth', 'mobile', 'breakpoint', 'mobileScale', 'shape', 'density', 'showRating', 'newTab' ];

	/** Any of these in the parsed attributes makes a v2 block. */
	const V2 = [ 'useGlobal', 'source', 'platforms', 'size' ];

	/**
	 * Size presets (the values live in style.css; listed here for tools and docs): star, score, caption, logo height,
	 * gap, score weight. md = the old defaults, sm = the old pill variation, lg = the old card variation. The pill (the
	 * hero rating row: round logos, score and stars, caption below) has its own md and lg.
	 */
	const SIZES = [
		'sm'      => [ 'star' => 18, 'rating' => 16, 'text' => 15, 'logo' => 22, 'gap' => 12, 'weight' => '600' ],
		'md'      => [ 'star' => 34, 'rating' => 44, 'text' => 17, 'logo' => 46, 'gap' => 22, 'weight' => '300' ],
		'lg'      => [ 'star' => 34, 'rating' => 56, 'text' => 17, 'logo' => 46, 'gap' => 22, 'weight' => '700' ],
		'pill-md' => [ 'star' => 22, 'rating' => 26, 'text' => 14, 'logo' => 46, 'gap' => 12, 'weight' => '400' ],
		'pill-lg' => [ 'star' => 26, 'rating' => 32, 'text' => 15, 'logo' => 56, 'gap' => 14, 'weight' => '400' ],
	];

	/** Captions the old patterns and block defaults carry (demo numbers). */
	const DEMO_TEXT = [ '887+ Combined Reviews', 'Rated by 887+ happy customers' ];

	public static function boot(): void {
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_data' ] );
	}

	/** Business Info review state and the platform logos for the inspector. */
	public static function editor_data(): void {
		$handle = generate_block_asset_handle( 'launch/rating', 'editorScript' );
		$overall = Company::review_data();
		$platforms = [];
		foreach ( Company::PLATFORMS as $key => $label ) {
			$d = Company::review_data( $key );
			$platforms[] = [ 'key' => $key, 'label' => $label, 'logo' => LB_URL . 'assets/logos/' . $key . '.svg', 'inBusinessInfo' => null !== $d['rating'] ];
		}
		$data = [
			'hasGlobal'       => null !== $overall['rating'],
			'rating'          => $overall['rating'],
			'count'           => $overall['count'],
			'platforms'       => $platforms,
			'legacy'          => self::LEGACY,
			'businessInfoUrl' => admin_url( 'admin.php?page=launch-builder-business#lb-g-reviews' ),
		];
		wp_add_inline_script( $handle, 'window.lbRating = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * @param array          $a     block attributes (defaults applied)
	 * @param \WP_Block|null $block the block, for its parsed attributes (what was actually saved)
	 */
	public static function render( array $a, $block = null ): string {
		$parsed = ( $block instanceof \WP_Block && isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ) ? $block->parsed_block['attrs'] : $a;
		$has = static function ( string $k ) use ( $parsed ): bool {
			return array_key_exists( $k, $parsed );
		};
		$type = self::enum( $a['type'] ?? '', [ 'badge', 'card', 'pill', 'stars', 'stacked' ], 'badge' );
		$v2 = 'stars' === $type || (bool) array_intersect( self::V2, array_keys( $parsed ) );
		$size = self::enum( (string) ( $a['size'] ?? '' ), [ 'sm', 'md', 'lg' ], 'md' );

		/* ---- numbers: block attributes, or Business Info for useGlobal blocks ---- */
		$count = $has( 'starCount' ) ? self::int( $a['starCount'], 1, 10 ) : 5;
		$rating = (float) ( $a['rating'] ?? 4.8 );
		$text = sanitize_text_field( (string) ( $a['text'] ?? '' ) );
		$link = is_string( $a['link'] ?? null ) ? trim( $a['link'] ) : '';
		$demo = false;
		if ( ! empty( $a['useGlobal'] ) ) {
			$source = sanitize_key( (string) ( $a['source'] ?? '' ) );
			$g = Company::review_data( $source );
			if ( null !== $g['rating'] ) {
				if ( ! $has( 'rating' ) ) {
					$rating = (float) $g['rating'];
				}
				if ( ! $has( 'text' ) ) {
					$text = self::caption( $g, $source );
				}
				if ( '' === $link && '' !== $source ) {
					$link = (string) $g['url'];
				}
			} else {
				$demo = ! $has( 'rating' ); // Business Info has no rating yet: the block's own (demo) numbers show.
			}
		}
		$rating = max( 0, min( $rating, $count ) );
		$link = esc_url( $link );
		$show_score = 'stars' !== $type && ( ! $has( 'showRating' ) || ! empty( $a['showRating'] ) );
		if ( 'stars' === $type ) {
			$text = '';
		}

		/* ---- per-block CSS variables: legacy attributes only when saved ---- */
		$vars = [];
		$num = [ 'starSize' => [ '--lbr-star', 10, 80 ], 'starGap' => [ '--lbr-star-gap', 0, 24 ], 'ratingSize' => [ '--lbr-rating', 12, 100 ], 'textSize' => [ '--lbr-text', 10, 40 ], 'logoHeight' => [ '--lbr-logo-h', 12, 120 ], 'logoGap' => [ '--lbr-logo-gap', -40, 60 ], 'gap' => [ '--lbr-gap', 0, 80 ] ];
		foreach ( $num as $k => [ $var, $min, $max ] ) {
			if ( $has( $k ) ) {
				$vars[ $var ] = self::int( $a[ $k ], $min, $max );
			}
		}
		foreach ( [ 'starColor' => '--lbr-star-c', 'starEmpty' => '--lbr-empty-c', 'ratingColor' => '--lbr-score-c', 'textColor' => '--lbr-text-c' ] as $k => $var ) {
			if ( $has( $k ) ) {
				$vars[ $var ] = self::color( $a[ $k ], '' );
			}
		}
		$weights = [ '300', '400', '500', '600', '700', '800' ];
		foreach ( [ 'ratingWeight' => '--lbr-rating-w', 'textWeight' => '--lbr-text-w' ] as $k => $var ) {
			if ( $has( $k ) ) {
				$vars[ $var ] = self::enum( (string) $a[ $k ], $weights, '' );
			}
		}
		if ( $has( 'mobileScale' ) ) {
			$vars['--lbr-ms'] = self::int( $a['mobileScale'], 40, 100 ) / 100;
		}
		$max = $has( 'maxWidth' ) ? self::int( $a['maxWidth'], 0, 1600 ) : 0;
		if ( $max ) {
			$vars['--lbr-maxw'] = $max . 'px';
		}
		$align = self::enum( $a['contentAlign'] ?? '', [ 'start', 'center', 'end' ], 'start' );
		if ( 'start' !== $align ) {
			$vars['--lbr-justify'] = [ 'center' => 'center', 'end' => 'flex-end' ][ $align ];
			$vars['--lbr-ta'] = [ 'center' => 'center', 'end' => 'end' ][ $align ]; // The caption's text alignment when it sits on its own line.
		}
		$style = '';
		foreach ( $vars as $k => $v ) {
			if ( '' !== $v && null !== $v ) {
				$style .= $k . ':' . $v . ';';
			}
		}

		/* ---- classes ---- */
		$lp = $has( 'logoPosition' ) ? self::enum( $a['logoPosition'], [ 'start', 'end', 'top', 'bottom' ], 'start' ) : ( $v2 && 'card' === $type ? 'top' : 'start' );
		$classes = [ 'lb-rating', 'lb-rating--' . $type, 'lb-rating--lp-' . $lp ];
		$classes[] = $v2 ? 'lb-rating--size-' . $size : 'lb-rating--legacy';
		if ( $has( 'logoStyle' ) ) {
			$classes[] = 'lb-rating--fx-' . self::enum( $a['logoStyle'], [ 'original', 'grayscale', 'grayscale-hover' ], 'original' );
		}
		if ( $has( 'shape' ) ) {
			$classes[] = 'lb-rating--shape-' . self::enum( $a['shape'], [ 'sharp', 'soft', 'round' ], 'soft' );
		}
		if ( $has( 'density' ) ) {
			$classes[] = 'lb-rating--d-' . self::enum( $a['density'], [ 'compact', 'default', 'airy' ], 'default' );
		}
		// Phones: the default (content centred inside a box style) is decided by the stylesheet from the block style class, so
		// the editor preview, which never receives className, shows it too. Only the two explicit choices add a class here.
		$phone = $has( 'phoneAlign' ) ? self::enum( $a['phoneAlign'], [ 'auto', 'center', 'match' ], 'auto' ) : 'auto';
		if ( 'center' === $phone ) {
			$classes[] = 'lb-rating--phc';
			$classes[] = 'lb-rating--phone-center';
		} elseif ( 'match' === $phone ) {
			$classes[] = 'lb-rating--ph-match';
		}
		if ( ! empty( $a['logoDivider'] ) && $has( 'logoDivider' ) ) {
			$classes[] = 'lb-rating--divider';
		}
		if ( ! $show_score ) {
			$classes[] = 'lb-rating--no-score';
		}
		$extra = [ 'class' => implode( ' ', $classes ) ];
		if ( ! $v2 || $has( 'breakpoint' ) || $has( 'mobile' ) ) { // The old breakpoints (legacy.css).
			$extra['data-bp'] = self::enum( (string) ( $a['breakpoint'] ?? '' ), [ '360', '480', '640', '768' ], '480' );
			$extra['data-mobile'] = self::enum( $a['mobile'] ?? '', [ 'stack', 'hide-logos', 'keep' ], 'stack' );
		}
		if ( $demo ) {
			$extra['data-lbr-demo'] = '1';
		}
		$wrapper = get_block_wrapper_attributes( $extra );
		if ( '' !== $style ) {
			// Every value above was validated here. WordPress's own style filter drops rgb()/rgba() values, so these
			// declarations are added after the wrapper attributes are built (ahead of any block-support styles).
			$own = esc_attr( $style );
			if ( preg_match( '/(^|\s)style="/', $wrapper ) ) {
				$wrapper = preg_replace_callback( '/(^|\s)style="/', static function ( $m ) use ( $own ) {
					return $m[1] . 'style="' . $own;
				}, $wrapper, 1 );
			} else {
				$wrapper .= ' style="' . $own . '"';
			}
		}

		/* ---- markup ---- */
		$logos = self::platforms( $v2 ? ( $a['platforms'] ?? [] ) : [] ) . self::logos( $a['logos'] ?? [] );
		if ( '' !== $logos ) {
			$names = self::platform_names( $v2 ? ( $a['platforms'] ?? [] ) : [] );
			$logos = '<div class="lb-rating__logos"' . ( '' !== $names ? ' role="img" aria-label="' . esc_attr( $names ) . '"' : '' ) . '>' . $logos . '</div>';
		}

		/* translators: 1: rating, 2: max stars */
		$label = sprintf( __( 'Rated %1$s out of %2$s', 'launch-builder' ), self::fmt( $rating ), $count );
		$inner = '';
		if ( $show_score ) {
			$inner .= '<span class="lb-rating__score">' . esc_html( self::fmt( $rating ) ) . '</span>';
		}
		$inner .= '<span class="lb-rating__stars" role="img" aria-label="' . esc_attr( $label ) . '">' . self::stars( $rating, $count, $has( 'starStyle' ) ? self::enum( $a['starStyle'], [ 'sharp', 'rounded' ], 'sharp' ) : 'sharp' ) . '</span>';
		if ( '' !== $text ) {
			$inner .= '<span class="lb-rating__text">' . esc_html( $text ) . '</span>';
		}
		if ( $link ) {
			$new_tab = $has( 'newTab' ) ? ! empty( $a['newTab'] ) : true;
			$main = '<a class="lb-rating__main" href="' . $link . '"' . ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . $inner . '</a>';
		} else {
			$main = '<div class="lb-rating__main">' . $inner . '</div>';
		}
		return '<div ' . $wrapper . '><div class="lb-rating__inner">' . $logos . $main . '</div></div>';
	}

	/** Caption from Business Info: "132+ Combined Reviews" (several platforms), "887+ Google reviews", "Based on 40+ reviews". */
	private static function caption( array $g, string $source ): string {
		$n = (int) ( $g['count'] ?? 0 );
		if ( '' !== $source ) {
			/* translators: 1: number of reviews, 2: platform name, e.g. Google */
			return $n > 0 ? sprintf( __( '%1$s+ %2$s reviews', 'launch-builder' ), number_format_i18n( $n ), $g['label'] ) : sprintf( /* translators: %s: platform name */ __( 'Rated on %s', 'launch-builder' ), $g['label'] );
		}
		if ( $n <= 0 ) {
			return '';
		}
		if ( (int) $g['platforms'] >= 2 ) {
			/* translators: %s: total number of reviews */
			return sprintf( __( '%s+ Combined Reviews', 'launch-builder' ), number_format_i18n( $n ) );
		}
		return (string) Company::computed_value( 'review_count_line' );
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

	/** Valid platform keys from the attribute, in order, at most 8. */
	private static function platform_keys( $keys ): array {
		$out = [];
		foreach ( is_array( $keys ) ? $keys : [] as $k ) {
			$k = is_string( $k ) ? sanitize_key( $k ) : '';
			if ( isset( Company::PLATFORMS[ $k ] ) && ! in_array( $k, $out, true ) ) {
				$out[] = $k;
			}
		}
		return array_slice( $out, 0, 8 );
	}

	private static function platform_names( $keys ): string {
		$names = array_map( [ Company::class, 'platform_label' ], self::platform_keys( $keys ) );
		return $names ? wp_sprintf_l( '%l', $names ) : '';
	}

	/** The bundled SVG mark of a platform (assets/logos/{key}.svg), inline. */
	public static function logo_svg( string $key ): string {
		static $cache = [];
		if ( ! isset( Company::PLATFORMS[ $key ] ) ) {
			return '';
		}
		if ( ! isset( $cache[ $key ] ) ) {
			$svg = (string) @file_get_contents( LB_DIR . 'assets/logos/' . $key . '.svg' ); // phpcs:ignore -- bundled file.
			$svg = (string) preg_replace( '/^.*?(?=<svg\b)/s', '', $svg );
			$cache[ $key ] = '' === $svg ? '' : (string) preg_replace( '/^<svg\b/', '<svg aria-hidden="true" focusable="false"', trim( $svg ) );
		}
		return $cache[ $key ];
	}

	/** Round platform logos (overlapping, like a review-site badge row). */
	private static function platforms( $keys ): string {
		$out = '';
		foreach ( self::platform_keys( $keys ) as $k ) {
			$svg = self::logo_svg( $k );
			if ( '' !== $svg ) {
				$out .= '<span class="lb-rating__badge lb-rating__badge--' . esc_attr( $k ) . '">' . $svg . '</span>';
			}
		}
		return $out;
	}

	/** Custom logos (the media-library logos of v1 blocks; per-logo height/space still honoured when saved). */
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
				$vars .= '--lbr-ls:' . self::int( $logo['space'], -60, 80 ) . ';';
			}
			if ( ! empty( $logo['height'] ) ) {
				$vars .= '--lbr-lh:' . self::int( $logo['height'], 12, 160 ) . ';';
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
		return $out;
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

	/* ------------------------------------------------------------------ module hooks */

	public static function playbook( array &$pb ): void {
		$pb['rating_widget'] = 'Rating widget (launch/rating): set useGlobal true so the numbers come from Business Info (set-company reviews[] per platform: rating, count, url; the overall rating is count-weighted). type badge|card|pill (the hero rating row: round logos, score and stars, caption below)|stars, size sm|md|lg, source "" (overall) or a platform key (google, facebook, yelp, bbb, angi, nextdoor, houzz, thumbtack: caption and link from that platform), platforms [keys] for the round overlapping logos, contentAlign, phoneAlign auto (default: on phones the content is centred inside a boxed style) | center (the whole widget is centred) | match (no change on phones), block styles plain|soft|outline|dark|brand. Colours follow the surrounding text, so it needs no colour settings on dark heroes. Without Business Info reviews it shows demo numbers (the audit flags them). Never type ratings or counts you were not given.';
	}

	public static function audit_checks(): array {
		return [ 'rating_demo' => [ 'content', [ __CLASS__, 'audit_demo' ], true ] ];
	}

	/** Rating widgets showing demo numbers (block defaults or the old demo captions). */
	public static function audit_demo( array $args ): array {
		global $wpdb;
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ID-cursor batch over published content.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type NOT IN ('revision','attachment','nav_menu_item') AND post_content LIKE %s ORDER BY ID ASC LIMIT %d", (int) ( $args['after_id'] ?? 0 ), '%' . $wpdb->esc_like( 'wp:launch/rating' ) . '%', $limit ) );
		$has_global = null !== Company::review_data()['rating'];
		$findings = [];
		$last = 0;
		$walk = static function ( array $blocks, int &$n ) use ( &$walk, $has_global ) {
			foreach ( $blocks as $b ) {
				if ( 'launch/rating' === ( $b['blockName'] ?? '' ) ) {
					$at = (array) ( $b['attrs'] ?? [] );
					$own = array_key_exists( 'rating', $at ) || array_key_exists( 'text', $at );
					if ( ( ! $own && ( empty( $at['useGlobal'] ) || ! $has_global ) ) || in_array( (string) ( $at['text'] ?? '' ), self::DEMO_TEXT, true ) ) {
						$n++;
					}
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'], $n );
				}
			}
		};
		foreach ( (array) $rows as $row ) {
			$last = (int) $row->ID;
			$n = 0;
			$walk( parse_blocks( (string) $row->post_content ), $n );
			if ( $n ) {
				$findings[] = [ 'severity' => 'warning', 'object_type' => $row->post_type, 'id' => $last, 'url' => (string) get_permalink( $last ), 'message' => sprintf( '"%s": %d rating widget(s) show demo numbers.', $row->post_title, $n ), 'fix' => $has_global ? 'Turn on "Use Business Info" in the widget (useGlobal true) and clear its rating/caption.' : 'Add the review platforms in Business Info (set-company reviews), then set useGlobal true on the widget.' ];
			}
		}
		return [ 'findings' => $findings, 'next_after_id' => count( (array) $rows ) === $limit ? $last : null ];
	}
}
