<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page content: bare text pages written as Markdown, the layout each kind of page uses, page recipes, and the playbook an
 * AI agent reads first. Text-heavy pages are plain core blocks (so the theme's typography applies) with bullet lists
 * turned into the stylised Kadence icon list; the page title and width come from the theme, not from a hero pattern.
 */
final class Content {

	/** Page types: how Kadence lays the page out. landing = full-bleed patterns, text = readable column, wide = text plus media/sidebar patterns. */
	const TYPES = [
		'landing' => [ 'title' => 'hide', 'width' => 'fullwidth', 'style' => 'unboxed', 'padding' => 'hide' ],
		'text'    => [ 'title' => 'show', 'width' => 'narrow', 'style' => 'unboxed', 'padding' => 'show' ],
		'wide'    => [ 'title' => 'show', 'width' => 'normal', 'style' => 'unboxed', 'padding' => 'show' ],
		'sidebar' => [ 'title' => 'show', 'width' => 'right', 'style' => 'unboxed', 'padding' => 'show' ],
	];

	public static function define_tools(): void {
		Abilities::add( 'get-playbook', 'Read the site-building playbook', 'Call this FIRST. How to build a whole site well with this plugin: the order of work, which pattern to use for what, how pages are typed (landing, text, wide), how to write text pages in Markdown, what is global (business info, buttons, menu, page banner) and what is per page.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'playbook' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'add-text', 'Add bare text to a page (Markdown)', 'Appends (or prepends) text content to a page, written as Markdown. # and ## become H2, ### H3, #### H4; "- " bullets become the stylised icon list, "* " plain bullets; "1." numbered lists, "> " quotes, "---" a divider, "![alt](url)" an image, **bold**, *italic*, [text](url). Tokens like {{lb.phone}} and links like [Call us](#lb-phone_link) work. Use it for service pages, location pages and any text-heavy page; start with set-page-layout type "text".',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'markdown' => [ 'type' => 'string' ], 'position' => [ 'type' => 'string', 'enum' => [ 'append', 'prepend' ] ] ], 'required' => [ 'post_id', 'markdown' ] ], [ __CLASS__, 'add_text' ], 'edit_pages' );

		Abilities::add( 'set-page-layout', 'Set how a page is laid out', 'type: "landing" (no title, full width, for pattern pages like the home page), "text" (theme title banner, readable narrow column, for bare text pages), "wide" (theme title banner, normal width, for text plus media patterns), "sidebar" (theme title banner, text with the theme sidebar on the right: set-sidebar fills it). Optional overrides: title ("show"|"hide"), width ("normal"|"narrow"|"fullwidth"|"right"|"left" = with the theme sidebar), style ("boxed"|"unboxed"), padding ("show"|"hide"), transparent_header (bool).',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'type' => [ 'type' => 'string', 'enum' => array_keys( self::TYPES ) ], 'title' => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ], 'width' => [ 'type' => 'string', 'enum' => [ 'normal', 'narrow', 'fullwidth', 'left', 'right' ] ], 'style' => [ 'type' => 'string', 'enum' => [ 'boxed', 'unboxed' ] ], 'padding' => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ], 'transparent_header' => [ 'type' => 'boolean' ] ], 'required' => [ 'post_id' ] ], [ __CLASS__, 'set_layout_tool' ], 'edit_pages' );
	}

	/* ------------------------------------------------------------------ page layout */

	/**
	 * Apply a page type and/or overrides to a page's Kadence layout meta. Every layout write in the plugin goes through
	 * here (set-page-layout, build-site pages, apply-pattern, entries), and the filter launch_builder_layout_meta
	 * ( array $meta key => value, int $post_id, array $layout resolved type + overrides ) may change what is written
	 * (for example to map title to _lb_hero).
	 *
	 * @return string[] meta keys written
	 */
	public static function set_layout( int $post_id, array $l ): array {
		$l = array_merge( isset( $l['type'], self::TYPES[ $l['type'] ] ) ? self::TYPES[ $l['type'] ] : [], array_filter( $l, static function ( $v, $k ) { return 'type' !== $k && null !== $v; }, ARRAY_FILTER_USE_BOTH ) );
		$map = [
			'title'   => [ '_kad_post_title', [ 'show', 'hide' ] ],
			'width'   => [ '_kad_post_layout', [ 'normal', 'narrow', 'fullwidth', 'left', 'right' ] ],
			'style'   => [ '_kad_post_content_style', [ 'boxed', 'unboxed' ] ],
			'padding' => [ '_kad_post_vertical_padding', [ 'show', 'hide' ] ],
		];
		$meta = [];
		foreach ( $map as $k => [ $key, $allowed ] ) {
			if ( isset( $l[ $k ] ) && in_array( $l[ $k ], $allowed, true ) ) {
				$meta[ $key ] = $l[ $k ];
			}
		}
		if ( isset( $l['transparent_header'] ) ) {
			$meta['_kad_post_transparent'] = $l['transparent_header'] ? 'enable' : 'disable';
		}
		$meta = (array) apply_filters( 'launch_builder_layout_meta', $meta, $post_id, $l );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}
		return array_map( 'strval', array_keys( $meta ) );
	}

	public static function set_layout_tool( $input ) {
		$id = (int) ( $input['post_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, [ 'page', 'post' ], true ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'lb_post', 'Unknown page, or you cannot edit it.', [ 'status' => 404 ] );
		}
		$w = self::set_layout( $id, (array) $input );
		return $w ? [ 'post_id' => $id, 'written' => $w ] : new \WP_Error( 'lb_empty', 'Give a type and/or layout overrides.', [ 'status' => 400 ] );
	}

	/* ------------------------------------------------------------------ markdown -> blocks */

	private static function url( string $u ): string {
		$u = html_entity_decode( $u, ENT_QUOTES );
		if ( Tokens::is_url_token( $u ) ) {
			return $u; // Tokens are resolved when the page renders.
		}
		return esc_url( $u ) ?: '#';
	}

	private static function inline( string $s ): string {
		$s = esc_html( trim( $s ) );
		$s = (string) preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)\)/', static function ( $m ) { return '<a href="' . self::url( $m[2] ) . '">' . $m[1] . '</a>'; }, $s );
		$s = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s );
		$s = (string) preg_replace( '/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/s', '<em>$1</em>', $s );
		return (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
	}

	/** The Kadence icon list: taken from the text-checklist pattern so it is always the real, styled block. */
	private static function iconlist( array $items ): string {
		$tpl = Patterns::pattern_content( 'text-checklist' );
		$list = null;
		if ( $tpl ) {
			$find = static function ( array $blocks ) use ( &$find ) {
				foreach ( $blocks as $b ) {
					if ( 'kadence/iconlist' === ( $b['blockName'] ?? '' ) ) {
						return $b;
					}
					if ( ! empty( $b['innerBlocks'] ) && ( $f = $find( $b['innerBlocks'] ) ) ) {
						return $f;
					}
				}
				return null;
			};
			$list = $find( parse_blocks( $tpl ) );
		}
		if ( ! $list || empty( $list['innerBlocks'][0] ) ) { // Kadence not available: fall back to a plain list.
			return self::core_list( $items, false );
		}
		$proto = $list['innerBlocks'][0];
		$old = (string) ( $proto['attrs']['uniqueID'] ?? '' );
		$prefix = false !== strpos( $old, '_' ) ? strstr( $old, '_', true ) : '0';
		$kids = [];
		foreach ( $items as $text ) {
			$b = $proto;
			$new = $prefix . '_' . substr( md5( wp_rand() . $text ), 0, 6 ) . '-' . substr( md5( (string) wp_rand() ), 0, 2 );
			if ( $old ) {
				$b['attrs']['uniqueID'] = $new;
				$b['innerHTML'] = str_replace( $old, $new, (string) $b['innerHTML'] );
				$b['innerContent'] = array_map( static function ( $c ) use ( $old, $new ) { return is_string( $c ) ? str_replace( $old, $new, $c ) : $c; }, $b['innerContent'] );
			}
			Patterns::set_text( $b, self::inline( $text ) );
			$kids[] = $b;
		}
		$ic = $list['innerContent'];
		$sep = isset( $ic[2] ) && is_string( $ic[2] ) ? $ic[2] : "\n\n";
		$mid = [];
		foreach ( $kids as $i => $_ ) {
			$mid[] = null;
			if ( $i < count( $kids ) - 1 ) {
				$mid[] = $sep;
			}
		}
		$list['innerBlocks'] = $kids;
		$list['innerContent'] = array_merge( [ (string) reset( $ic ) ], $mid, [ (string) end( $ic ) ] );
		return serialize_block( $list );
	}

	private static function core_list( array $items, bool $ordered ): string {
		$li = '';
		foreach ( $items as $t ) {
			$li .= "<!-- wp:list-item -->\n<li>" . self::inline( $t ) . "</li>\n<!-- /wp:list-item -->";
		}
		$tag = $ordered ? 'ol' : 'ul';
		return '<!-- wp:list' . ( $ordered ? ' {"ordered":true}' : '' ) . " -->\n<{$tag} class=\"wp-block-list\">{$li}</{$tag}>\n<!-- /wp:list -->";
	}

	/** Markdown (a practical subset) to serialized core/Kadence block markup. */
	public static function from_markdown( string $md, bool $constrain = false ): string {
		$lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", trim( $md ) ) );
		$n = count( $lines );
		$out = [];
		$i = 0;
		$starts_block = static function ( string $l ): bool {
			return (bool) preg_match( '/^(#{1,4}\s|---+\s*$|>\s?|[-*+]\s+|\d+[.)]\s+|!\[[^\]]*\]\([^)\s]+\)\s*$)/', ltrim( $l ) );
		};
		while ( $i < $n ) {
			$line = rtrim( $lines[ $i ] );
			if ( '' === trim( $line ) ) {
				$i++;
				continue;
			}
			if ( preg_match( '/^(#{1,4})\s+(.+)$/', $line, $m ) ) {
				$level = max( 2, min( 4, strlen( $m[1] ) + ( 1 === strlen( $m[1] ) ? 1 : 0 ) ) ); // The page title is the H1.
				$out[] = '<!-- wp:heading' . ( 2 === $level ? '' : ' {"level":' . $level . '}' ) . " -->\n<h{$level} class=\"wp-block-heading\">" . self::inline( $m[2] ) . "</h{$level}>\n<!-- /wp:heading -->";
				$i++;
			} elseif ( preg_match( '/^---+\s*$/', $line ) ) {
				$out[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
				$i++;
			} elseif ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)\s*$/', $line, $m ) ) {
				$out[] = "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"" . esc_url( $m[2] ) . '" alt="' . esc_attr( $m[1] ) . "\"/></figure>\n<!-- /wp:image -->";
				$i++;
			} elseif ( '>' === $line[0] ) {
				$q = [];
				while ( $i < $n && '>' === ( ltrim( $lines[ $i ] )[0] ?? '' ) ) {
					$q[] = trim( ltrim( ltrim( $lines[ $i ] ), '>' ) );
					$i++;
				}
				$out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>" . self::inline( implode( ' ', $q ) ) . "</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->";
			} elseif ( preg_match( '/^([-*+])\s+/', $line, $m ) ) {
				$marker = $m[1];
				$items = [];
				while ( $i < $n && preg_match( '/^' . preg_quote( $marker, '/' ) . '\s+(.*)$/', rtrim( $lines[ $i ] ), $mm ) ) {
					$items[] = $mm[1];
					$i++;
				}
				$out[] = '-' === $marker ? self::iconlist( $items ) : self::core_list( $items, false );
			} elseif ( preg_match( '/^\d+[.)]\s+/', $line ) ) {
				$items = [];
				while ( $i < $n && preg_match( '/^\d+[.)]\s+(.*)$/', rtrim( $lines[ $i ] ), $mm ) ) {
					$items[] = $mm[1];
					$i++;
				}
				$out[] = self::core_list( $items, true );
			} else {
				$p = [ trim( $line ) ];
				$i++;
				while ( $i < $n && '' !== trim( $lines[ $i ] ) && ! $starts_block( $lines[ $i ] ) ) {
					$p[] = trim( $lines[ $i ] );
					$i++;
				}
				$out[] = "<!-- wp:paragraph -->\n<p>" . self::inline( implode( ' ', $p ) ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		$body = implode( "\n\n", $out );
		// On wide pages the prose stays at a readable width (left aligned) while media/sidebar patterns use the full width.
		return $constrain ? '<!-- wp:group {"layout":{"type":"constrained","contentSize":"800px","justifyContent":"left"}} -->' . "\n" . '<div class="wp-block-group">' . $body . "</div>\n<!-- /wp:group -->" : $body;
	}

	/** Remove top-level blocks that carry the page title (a hero), for sites that use the shared hero element. */
	public static function strip_hero( array &$blocks ): void {
		$has_title = static function ( array $b ) use ( &$has_title ): bool {
			if ( in_array( $b['blockName'] ?? '', [ 'core/post-title', 'launch/breadcrumbs' ], true ) ) {
				return true;
			}
			foreach ( (array) ( $b['innerBlocks'] ?? [] ) as $in ) {
				if ( $has_title( $in ) ) {
					return true;
				}
			}
			return false;
		};
		$blocks = array_values( array_filter( $blocks, static function ( $b ) use ( $has_title ) { return ! $has_title( $b ); } ) );
	}

	/** Fill the markdown text area (a core/group with class lb-markdown) of a built pattern. @return bool whether one was found */
	public static function inject_markdown( array &$blocks, string $md ): bool {
		foreach ( $blocks as &$b ) {
			if ( 'core/group' === ( $b['blockName'] ?? '' ) && false !== strpos( (string) ( $b['attrs']['className'] ?? '' ), 'lb-markdown' ) ) {
				$new = array_values( array_filter( parse_blocks( self::from_markdown( $md ) ), static function ( $x ) { return ! empty( $x['blockName'] ); } ) );
				$ic = $b['innerContent'];
				$mid = [];
				foreach ( $new as $i => $_ ) {
					$mid[] = null;
					if ( $i < count( $new ) - 1 ) {
						$mid[] = "\n\n";
					}
				}
				$b['innerBlocks'] = $new;
				$b['innerContent'] = array_merge( [ (string) reset( $ic ) ], $mid, [ (string) end( $ic ) ] );
				return true;
			}
			if ( ! empty( $b['innerBlocks'] ) && self::inject_markdown( $b['innerBlocks'], $md ) ) {
				return true;
			}
		}
		return false;
	}

	public static function add_text( $input ) {
		$id = (int) ( $input['post_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'lb_post', 'Unknown page, or you cannot edit it.', [ 'status' => 404 ] );
		}
		$md = (string) ( $input['markdown'] ?? '' );
		if ( '' === trim( $md ) || strlen( $md ) > 200000 ) {
			return new \WP_Error( 'lb_empty', 'markdown is required (max 200 KB).', [ 'status' => 400 ] );
		}
		$blocks = self::from_markdown( $md, 'normal' === get_post_meta( $id, '_kad_post_layout', true ) ); // wide page: keep prose readable
		$new = 'prepend' === ( $input['position'] ?? 'append' ) ? $blocks . "\n\n" . $post->post_content : trim( $post->post_content ) . "\n\n" . $blocks;
		wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( trim( $new ) ) ] );
		return [ 'post_id' => $id, 'blocks_added' => substr_count( $blocks, '<!-- wp:' ) - substr_count( $blocks, '<!-- wp:list-item' ), 'url' => get_permalink( $id ) ];
	}

	/* ------------------------------------------------------------------ recipes + playbook */

	/**
	 * Standard page templates. Service businesses repeat the same sections on every site, so each kind of page has a fixed
	 * recipe; the agent only fills the copy. The global "bottom stack" (reviews, offers, service areas) is NOT part of a
	 * recipe: it is shown above the footer on every page, home included.
	 */
	public static function recipes(): array {
		return [
			'home'             => [ 'type' => 'landing', 'sections' => [ 'hero-photo', 'divider-band', 'services-dynamic-categories', 'divider-band', 'media-left', 'trust', 'checklist-cta' ], 'note' => 'Photo hero with trust line and two buttons; accent stripe; category cards with checklists; the story band ("locally owned, built by real experts"); trust badges; fast-response checklist. Reviews, offers and service areas come from the global bottom stack: do not repeat them.' ],
			'about'            => [ 'type' => 'wide', 'sections' => [ 'media-left', 'trust', 'media-right', 'stats' ], 'note' => 'Story, proof, values with images, numbers.' ],
			'service-category' => [ 'type' => 'landing', 'sections' => [ 'hero-inner', 'services-dynamic-cards', 'media-left', 'checklist-cta', 'faq' ], 'note' => 'One per service category (Cooling, Plumbing...): banner, a LIVE list of that category\'s services (set slots {"@entries":{"category":"slug"}}), a differentiator, the response checklist, FAQ.' ],
			'service-detail'   => [ 'type' => 'landing', 'sections' => [ 'hero-inner', 'service-body', 'faq', 'services-more' ], 'note' => 'The standard service page: banner with the page title as H1 and two buttons; a wide Markdown text column beside the booking-form card; FAQ; related services. Text outline: intro, "Signs you need...", "Understanding ... in [region]", "How it works" (numbered), "Benefits of ... in [city]", "Why choose [brand]", then the FAQ section.' ],
			'service-detail-simple' => [ 'type' => 'text', 'sections' => [ '{markdown}', 'text-callout' ], 'note' => 'Lightweight bare-text page (theme title banner + narrow column) for minor services or blog-like pages.' ],
			'location'         => [ 'type' => 'landing', 'sections' => [ 'hero-inner', 'service-body', 'services-more' ], 'note' => 'One page per city for local SEO: unique intro, the services offered there, why locals choose you, nearby areas as links.' ],
			'service-area'     => [ 'type' => 'wide', 'sections' => [ 'areas-dynamic-map-list', 'areas-dynamic-cards' ], 'note' => 'Hub page: map plus a LIVE list of every Service Area entry.' ],
			'contact'          => [ 'type' => 'landing', 'sections' => [ 'contact-embed', 'map-contact' ], 'note' => 'Details beside the Business Info form embed, then a map.' ],
			'quote'            => [ 'type' => 'landing', 'sections' => [ 'hero-form' ], 'note' => 'Request-a-quote landing page built around the Business Info form embed.' ],
			'faq'              => [ 'type' => 'wide', 'sections' => [ 'faq' ], 'note' => 'Accordion of questions.' ],
			'reviews'          => [ 'type' => 'wide', 'sections' => [ 'reviewcards', 'testimonials' ], 'note' => 'Platform ratings and written testimonials.' ],
		];
	}

	/** Per-pattern guidance, keyed by category, for the playbook. */
	private static function pattern_guide(): array {
		return [
			'heroes'   => 'hero-photo (home), hero-inner (service/category/location pages; the page title is the H1), hero-form (quote page), hero-split / hero / hero-dark, hero-slider + hero-splitcontent (Kadence Blocks Pro).',
			'services' => 'services-cards-checklist (the home "what we do" band: 4 category cards with 5-item checklists), services-image-cards, services (3 icon cards), services-tiles, services-process, services-checklist, services-more (related services row), services-image-overlay (Pro).',
			'content'  => 'service-body (the service page text area: pass markdown, beside the booking-form card), media-left / media-right / media-dark / media-alternating (text with images), text-sidebar-cta, text-two-column, divider-band (accent stripe between big sections), sidebar-contact / sidebar-services / sidebar-form (for the THEME sidebar via set-sidebar).',
			'text'     => 'Bare text for "text" pages: text-section, text-checklist, text-two-lists, text-steps, text-callout. add-text with Markdown makes the same blocks.',
			'areas'    => 'area-pills / area-cards / area-columns / area-map-list / area-dark-type. area-map-list works well in the bottom stack.',
			'maps'     => 'contact-embed (details + Business Info form), map-contact, map-floating, map-two-locations, map-dark-card, contact, contact-form.',
			'cta'      => 'checklist-cta (fast-response checklist), offers-cards (promotions), cta, cta-brand-card, cta-phone, cta-form, cta-simple, cta-quote-modal (Pro). Buttons read Business Info.',
			'footers'  => 'footer-licensing (offices, licences, links, booking: for trades), footer-dark / footer-light / footer-areas / footer-contact-bar / footer-centered / footer-cta-dark / footer-cta-light / footer-form / footer-minimal. Set with set-synced-pattern + set-global-area footer.',
			'headers'  => 'Applied with set-header: header-utility (rating + promo bar, nav with dropdowns, phone, button), header-basic, -topbar, -centered, -minimal (sticky), -split, -phone, -dark, -announcement.',
			'proof'    => 'testimonials-carousel (brand band), testimonials, testimonials-slider (Pro), reviewcards, trust (rating badge), stats, faq, rating-* presets.',
			'ratings'  => 'The Rating Widget block in every preset.',
		];
	}

	/** The get-playbook tool: the base playbook, then whatever each module adds (module hook playbook( array &$pb )). */
	public static function playbook(): array {
		$pb = [
			'model'   => 'PATTERNS are designs (a hero, a footer, a service template). ELEMENTS (Kadence Pro) are placements: where and when a design shows (all pages but the home page, every service entry, the footer, before the footer). With Pro, put each shared design in an element (set-element with a pattern, or build-site elements): hero + breadcrumb bar on replace_hero_header, entry templates on replace_single_content, the footer on replace_footer, the reviews/offers/areas stack on kadence_before_footer. Edit that one element and every targeted page follows. Without Pro the same designs are synced patterns shown through set-global-area. Services and service areas are ENTRIES (post types) and every list of them is a live query.',
			'summary' => 'Build a client site in this order. Whatever repeats on every page is set once and inherited; pages hold only their own content. The fastest path: new-blueprint (client brief in, full blueprint out), rewrite the copy, build-site.',
			'order'   => [
				'1. get-playbook (this), site-state, get-company.',
				'2. new-blueprint with the client brief (name, city, industry, services grouped by category, service areas, brand colours, style). It returns a complete blueprint with the standard pages, menu, global stack and design. Rewrite every [[bracketed]] placeholder and the pattern copy to the client\'s voice, then build-site. Or write the blueprint yourself with the recipes below.',
				'3. Business facts live in Business Info (company in the blueprint, or set-company): name, phone, email, address(es), hours, licences, quote page, button text/links, social links, form embed, plus industry/about/keywords/tone as your writing brief. Never type these into page copy: patterns read them (tokens such as {{lb.phone}}).',
				'4. Look: set-brand (palette, fonts), set-buttons, set-nav-style (menu + dropdown), set-page-style, set-block-defaults. build-site applies blueprint.design.',
				'5. Structure: set-header (menu children become the dropdown), the footer, and the global bottom stack (a synced stack of patterns above the footer on EVERY page). Optional theme sidebar: set-sidebar.',
				'6. Pages from recipes. Service pages = hero-inner + service-body (markdown) + faq. Minor pages can be bare text (type "text").',
				'7. Images: sideload-media then fill image slots; placeholders are grey boxes. SEO per page. Check the report and each page URL.',
			],
			'standard_site' => [
				'entries' => 'Services and service areas are ENTRIES (post types), not hand-built pages: add-service / add-area (or blueprint entries) create them; each gets its own page from the service_template / area_template synced patterns, and every list (home category cards, category pages, related services, service-area pills and columns, the bottom stack) is a live query, so adding or removing an entry updates everything. Use markdown for each entry text. Categories: services have a category (a taxonomy) that groups them.',
				'sitemap' => 'Home; About; a landing page per service category (lists its services live); the Service areas hub (lists areas live); Contact; FAQ. Each service and each city is an entry with its own page. Header menu: categories with their services as dropdowns, About, Service areas, Contact, then the phone and the booking button.',
				'home' => 'hero-photo > divider-band > services-cards-checklist > divider-band > story (media-left) > trust > checklist-cta, then the global bottom stack.',
				'service_page' => 'hero-inner (title is the H1, two buttons, rating) > service-body (Markdown text + booking-form card) > faq > services-more, then the global bottom stack. The text uses the standard H2 outline: Signs you need X / Understanding conditions in [region] / How X works (numbered) / Benefits of X in [city] (bold lead-ins) / Why choose [brand] for X (bold lead-ins) / FAQ.',
				'bottom_stack' => 'One synced stack above the footer on every page: testimonials-carousel, offers-cards, area-map-list (service areas). Edit it once, every page updates.',
				'header_footer' => 'header-utility (rating + promo bar) and footer-licensing (offices, licence numbers, links, booking button) are the standard pair for licensed trades.',
			],
			'page_types' => [
				'landing' => 'Full width, no theme title. Pattern pages: home, service pages, category pages, contact, quote.',
				'text'    => 'Theme title banner + narrow readable column. Bare Markdown text with the stylised icon list.',
				'wide'    => 'Theme title banner + normal width for text mixed with media patterns.',
				'sidebar' => 'Theme title banner + text with the theme sidebar beside it (set-sidebar).',
			],
			'text_pages' => [
				'markdown' => 'Headings: # or ## = H2, ### = H3. "- item" = stylised icon list (included, benefits). "* item" = plain bullet. "1." = numbered steps. "> " = quote. [text](url). **bold**. Bold lead-ins: "- **Water test**: what happens". Tokens: {{lb.phone}}; links [Call us](#lb-phone_link), [Get a quote](#lb-cta_url).',
				'where' => 'service-body takes markdown (apply-pattern markdown, or a blueprint section {pattern:"service-body", markdown:"..."}); add-text appends it to any page.',
			],
			'rules' => [
				'Buttons in patterns use Kadence "Theme Base": they follow set-buttons. Never hard-code button colours.',
				'Rows inherit the theme max width (set-page-style site.content_width). Backgrounds stay full-bleed.',
				'Colours are palette slugs: palette1 primary/action, palette2 accent (stripes), palette3 dark brand (headings, dark bands), palette4-6 text greys, palette7-8 light tints, palette9 white. Set them with set-brand; never hex in patterns.',
				'Fonts, sizes and line height inherit from the theme.',
				'Tokens: text {{lb.phone}} {{lb.email}} {{lb.address}} {{lb.address2}} {{lb.hours}} {{lb.license}} {{lb.name}} {{lb.cta_label}} {{lb.year}}; links #lb-phone_link #lb-cta_url #lb-cta2_url #lb-maps_url #lb-facebook. get-company lists all.',
				'Use replace (default text) or slots (get-pattern ids) to change pattern copy. Warnings name anything that did not match.',
			],
			'recipes'       => self::recipes(),
			'pattern_guide' => self::pattern_guide(),
			'tools_by_job'  => [
				'Start'          => 'get-playbook, new-blueprint, build-site',
				'Business facts' => 'get-company, set-company',
				'Look'           => 'set-brand, set-buttons, set-nav-style, set-page-style, set-block-defaults',
				'Structure'      => 'set-header, set-navigation, set-synced-pattern, set-global-area, set-sidebar, insert-synced, set-element (Kadence Pro), set-pro-modules',
				'Pages'          => 'apply-pattern, add-text, set-page-layout, save-pattern',
				'Services/areas' => 'add-service, add-area, list-entries, delete-entry',
				'Utilities'      => 'sideload-media, find-options, get/set-option, rest-request, restore-snapshot',
			],
		];
		foreach ( Plugin::modules( 'playbook' ) as $module ) {
			if ( __CLASS__ !== $module ) {
				$module::playbook( $pb );
			}
		}
		return $pb;
	}

	/* ------------------------------------------------------------------ brief: thin delegates (moved to class-brief.php) */

	/** @deprecated Moved to Brief::palette(). */
	public static function palette( array $c ): array {
		return Brief::palette( $c );
	}

	/** @deprecated Moved to Brief::new_blueprint(). */
	public static function new_blueprint( $input ) {
		return Brief::new_blueprint( $input );
	}
}
