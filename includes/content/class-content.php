<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page content: bare text pages written as Markdown, the layout each kind of page uses, and the playbook an AI agent
 * reads first. Text-heavy pages are plain core blocks (so the theme's typography applies) with bullet lists
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
		Abilities::add( 'get-playbook', 'Read the site-building playbook', 'Call this FIRST. How to build a whole site well with this plugin: the order of work, the content model (services, sub-services, service areas), the Design hub, how pages are typed (landing, text, wide, sidebar), how to write text pages in Markdown, and what is global (Business Info, the look, header, footer) and what is per page.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'playbook' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'add-text', 'Add bare text to a page (Markdown)', 'Appends (or prepends) text content to a page, written as Markdown. # and ## become H2, ### H3, #### H4; "- " bullets become the stylised icon list, "* " plain bullets; "1." numbered lists, "> " quotes, "---" a divider, "![alt](url)" an image, **bold**, *italic*, [text](url). Tokens like {{lb.phone}} and links like [Call us](#lb-phone_link) work. Use it for service pages, location pages and any text-heavy page; start with set-page-layout type "text".',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'markdown' => [ 'type' => 'string' ], 'position' => [ 'type' => 'string', 'enum' => [ 'append', 'prepend' ] ] ], 'required' => [ 'post_id', 'markdown' ] ], [ __CLASS__, 'add_text' ], 'edit_pages' );

		Abilities::add( 'set-page-layout', 'Set how a page is laid out', 'type: "landing" (no title, full width, for pattern pages like the home page), "text" (theme title banner, readable narrow column, for bare text pages), "wide" (theme title banner, normal width, for text plus media patterns), "sidebar" (theme title banner, text with the theme sidebar on the right: the Design hub\'s sidebar piece fills it). Optional overrides: title ("show"|"hide"), width ("normal"|"narrow"|"fullwidth"|"right"|"left" = with the theme sidebar), style ("boxed"|"unboxed"), padding ("show"|"hide"), transparent_header (bool).',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'type' => [ 'type' => 'string', 'enum' => array_keys( self::TYPES ) ], 'title' => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ], 'width' => [ 'type' => 'string', 'enum' => [ 'normal', 'narrow', 'fullwidth', 'left', 'right' ] ], 'style' => [ 'type' => 'string', 'enum' => [ 'boxed', 'unboxed' ] ], 'padding' => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ], 'transparent_header' => [ 'type' => 'boolean' ] ], 'required' => [ 'post_id' ] ], [ __CLASS__, 'set_layout_tool' ], 'edit_pages' );
	}

	/* ------------------------------------------------------------------ page layout */

	/**
	 * Apply a page type and/or overrides to a page's Kadence layout meta. Every layout write in the plugin goes through
	 * here (set-page-layout, build-site pages, apply-pattern), and the filter launch_builder_layout_meta
	 * ( array $meta key => value, int $post_id, array $layout resolved type + overrides ) may change what is written.
	 *
	 * @return string[] meta keys written
	 */
	public static function set_layout( int $post_id, array $l ): array {
		$in = $l; // The caller's own keys go to the filter too, so it can tell an explicit title from a page type's.
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
		$meta = (array) apply_filters( 'launch_builder_layout_meta', $meta, $post_id, $l, $in );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}
		return array_map( 'strval', array_keys( $meta ) );
	}

	public static function set_layout_tool( $input ) {
		$id = (int) ( $input['post_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, [ 'page', 'post', Entries::SERVICE, Entries::AREA ], true ) || ! current_user_can( 'edit_post', $id ) ) {
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

	/* ------------------------------------------------------------------ playbook */

	/** The get-playbook tool: the base playbook, then whatever each module adds (module hook playbook( array &$pb )). */
	public static function playbook(): array {
		$pb = [
			'model'   => 'Launch Builder sets the site up; Kadence and Meta Box run it. Everything it creates is a native object that keeps working without it: services and service areas are Meta Box post types, Business Info is a Meta Box settings page, the header is a Kadence block header, heroes, the breadcrumb bar, the service and area lists, the before-footer call to action and the footer are Kadence Elements, lists are Kadence Query Loops, and layouts are Customizer settings.',
			'summary' => 'Build a client site with build-site: one short brief in (business, look, colours, services, areas, pages), dry run first, then build. Or step by step in the order below. Whatever repeats on every page is set once and inherited; pages hold only their own content.',
			'order'   => [
				'1. get-playbook (this), site-state, get-company.',
				'2. Whole site: build-site with the brief (dry_run true first, fix the listed errors, then dry_run false), then audit-site and go-live.',
				'3. Business Info (set-company): company name, phone, phone link, email, address, maps link, call to action label and link. Never type these into page copy: use tokens such as {{lb.phone}}.',
				'4. Look: set-design with a look (auto-shop, home-services, landscaping) places every piece in that look; change single pieces with set-design pieces. Colours and fonts: set-brand; buttons: set-buttons.',
				'5. Services (add-service, with parent for a sub-service) and service areas (add-area, with services for its area service pages).',
				'6. Other pages: add-text (Markdown) or apply-pattern; contact and thank-you pages get their role with set-page-role.',
				'7. Menus: set-navigation. Images: sideload-media. Then audit-site.',
			],
			'content_model' => [
				'services' => 'A service (/air-conditioning/) can have sub-services (/air-conditioning/installation-and-replacement/). The service page lists its sub-services (the Design hub\'s sub-service list).',
				'areas'    => 'The /service-areas/ page lists every top-level service area (/service-areas/rockledge-fl/). An area lists its area service pages (/service-areas/rockledge-fl/ac-installation/: "AC Installation in Rockledge, FL"), which are its child pages.',
				'summary'  => 'Every service and area has a summary (the excerpt): one or two sentences used on cards, in heroes and as the SEO description.',
			],
			'page_types' => [
				'landing' => 'Full width, no theme title. Pattern pages: home, contact.',
				'text'    => 'Theme title + narrow readable column. Bare Markdown text with the stylised icon list.',
				'wide'    => 'Theme title + normal width for text mixed with media patterns.',
				'sidebar' => 'Theme title + text with the theme sidebar beside it: the standard for pages, services and areas.',
			],
			'text_pages' => [
				'markdown' => 'Headings: # or ## = H2, ### = H3. "- item" = stylised icon list (included, benefits). "* item" = plain bullet. "1." = numbered steps. "> " = quote. [text](url). **bold**. Bold lead-ins: "- **Water test**: what happens". Tokens: {{lb.phone}}; links [Call us](#lb-phone_link), [Get a quote](#lb-cta_url).',
				'where'    => 'add-text appends Markdown to any page, service or area; add-service and add-area take text as Markdown.',
			],
			'rules' => [
				'Buttons in patterns use Kadence "Theme Base": they follow set-buttons. Never hard-code button colours.',
				'Colours are palette slugs: palette1 primary/action, palette2 accent, palette3 dark brand (headings, dark bands), palette4-6 text greys, palette7-8 light tints, palette9 white. Set them with set-brand; never hex in patterns.',
				'Tokens: {{lb.name}} {{lb.phone}} {{lb.email}} {{lb.address}} {{lb.cta_label}}; links #lb-phone_link #lb-maps_url #lb-cta_url. get-company lists them.',
				'Use replace (default text) or slots (get-pattern ids) to change pattern copy. Warnings name anything that did not match.',
			],
			'tools_by_job'  => [
				'Start'          => 'get-playbook, build-site',
				'Business facts' => 'get-company, set-company',
				'Look'           => 'get-designs, set-design, set-brand, set-buttons, set-nav-style, set-page-style, set-block-defaults',
				'Structure'      => 'set-navigation, set-page-role',
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
}
