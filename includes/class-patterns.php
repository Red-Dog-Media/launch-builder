<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Patterns as data: the registry of built-in and per-site custom patterns, their editable slots (text, links, images,
 * numbers) and the build pipeline every writer uses (fill slots, materialize forms, live-list overrides, markdown).
 *
 * Slot ids are "path:field" (path = block indexes from the root, e.g. 0.1.2:text). build_pattern() is the one place a
 * pattern becomes markup: apply-pattern, synced patterns, elements, blueprints and set-header all go through it.
 * Pseudo-slots (directives): '@entries' = launch/entries attribute overrides; '@no_hero' = drop top-level blocks that
 * carry the page title (shared hero placement).
 */
final class Patterns {

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];

		Abilities::add( 'list-patterns', 'List patterns', 'List Launch Builder patterns as compact rows (slug, title, category, description, and roles/slot/variant_of/style when set), 100 per page. Filter by category (heroes, services, areas, maps, cta, footers, proof, faq, process, offers, trust, templates, ...), role (a page role: the designs made for it), slot (a chrome slot: header, hero, crumbs, template, footer, ...), style (preset) or variant_of (a pattern and its variations). with_slots adds each pattern\'s editable slot count (slower). Page with offset/next_offset.',
			[ 'properties' => [
				'category'   => [ 'type' => 'string' ],
				'role'       => [ 'type' => 'string', 'description' => 'A page role (see get-roles): home, page, legal, services_hub, areas_hub, category, service, area, conversion, thanks, post, blog, search, notfound.' ],
				'slot'       => [ 'type' => 'string', 'description' => 'A chrome slot: header, notice, hero, crumbs, template, notfound_body, before_footer, footer, sticky_bar, conversion.' ],
				'style'      => [ 'type' => 'string', 'description' => 'A style preset: clean, bold, sharp, soft.' ],
				'variant_of' => [ 'type' => 'string', 'description' => 'A pattern slug: returns it and the patterns that are its variations.' ],
				'with_slots' => [ 'type' => 'boolean' ],
				'limit'      => [ 'type' => 'integer', 'description' => 'Rows per page, 1-200 (default 100).' ],
				'offset'     => [ 'type' => 'integer' ],
			] ], [ __CLASS__, 'list_patterns' ], 'edit_pages', $ro );

		Abilities::add( 'get-pattern', 'Describe a pattern', 'Return a pattern\'s editable slots (text, links, images, numbers) with ids, and optionally its block markup.',
			[ 'properties' => [ 'slug' => [ 'type' => 'string' ], 'include_content' => [ 'type' => 'boolean' ] ], 'required' => [ 'slug' ] ], [ __CLASS__, 'get_pattern' ], 'edit_pages', $ro );

		Abilities::add( 'apply-pattern', 'Apply a pattern to a page', 'Insert a pattern into a page (creating the page if post_id is 0), filling slots by id and/or by replacing default text. Use get-pattern first.',
			[ 'properties' => [
				'slug'     => [ 'type' => 'string' ],
				'markdown' => [ 'type' => 'string', 'description' => 'Page text as Markdown, placed into the pattern text area (service-body has one).' ],
				'post_id'  => [ 'type' => 'integer', 'description' => '0 creates a new page (title required).' ],
				'title'    => [ 'type' => 'string' ],
				'status'   => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private' ] ],
				'position' => [ 'type' => 'string', 'enum' => [ 'append', 'prepend', 'replace' ] ],
				'slots'    => [ 'type' => 'object', 'description' => 'Map of slot id => new value.' ],
				'replace'  => [ 'type' => 'object', 'description' => 'Map of default text => new text (replaces every slot with that exact value).' ],
				'full_width' => [ 'type' => 'boolean', 'description' => 'Set the Kadence page layout to full width, unboxed, no title (default true on new pages).' ],
			], 'required' => [ 'slug' ] ], [ __CLASS__, 'apply_pattern' ], 'edit_pages' );

		Abilities::add( 'save-pattern', 'Save a custom pattern for this site', 'Store block markup as a reusable per-site pattern (then use it in apply-pattern, synced patterns and blueprints). Give content (block markup) or from_post_id to capture a page\'s content. Markup is validated: only registered blocks, no scripts.',
			[ 'properties' => [ 'slug' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ], 'category' => [ 'type' => 'string', 'description' => 'heroes, services, areas, maps, cta, footers, proof or custom' ], 'content' => [ 'type' => 'string' ], 'from_post_id' => [ 'type' => 'integer' ] ], 'required' => [ 'slug', 'title' ] ], [ __CLASS__, 'save_pattern' ], 'edit_pages' );

		Abilities::add( 'delete-pattern', 'Delete a custom pattern', 'Remove a per-site custom pattern (built-in patterns cannot be deleted).',
			[ 'properties' => [ 'slug' => [ 'type' => 'string' ] ], 'required' => [ 'slug' ] ], [ __CLASS__, 'delete_pattern' ], 'edit_pages' );
	}

	/* ------------------------------------------------------------------ registry */

	/** @var array|null per-request index(); reset when custom patterns change. */
	private static $icache = null;

	/** @var array|null per-request all() (with content); reset when custom patterns change. */
	private static $pcache = null;

	/**
	 * Every usable pattern by slug WITHOUT reading pattern files: the built-in launch-builder/* patterns whose required
	 * plugins are active (Plugin::pattern_index(), from the header cache) plus this site's custom ones. Rows: slug, name,
	 * title, description, categories[], keywords[], inserter, roles[], slot, variant_of, style, transparent, requires,
	 * custom (+ post_id and content for custom patterns). Use content( $slug ) for the markup.
	 */
	public static function index(): array {
		if ( null !== self::$icache ) {
			return self::$icache;
		}
		$out = [];
		foreach ( Plugin::pattern_index() as $slug => $row ) {
			if ( ! empty( $row['available'] ) ) {
				$out[ $slug ] = $row + [ 'custom' => false ];
			}
		}
		// Per-site custom patterns (saved with save-pattern): unsynced wp_block posts carrying _lb_custom_slug.
		foreach ( get_posts( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'meta_key' => '_lb_custom_slug', 'numberposts' => 200 ] ) as $post ) {
			$slug = (string) get_post_meta( $post->ID, '_lb_custom_slug', true );
			if ( '' === $slug || isset( $out[ $slug ] ) ) {
				continue;
			}
			$cat = (string) get_post_meta( $post->ID, '_lb_custom_category', true ) ?: 'custom';
			$out[ $slug ] = [
				'slug'        => $slug,
				'name'        => 'launch-builder/' . $slug,
				'title'       => $post->post_title,
				'description' => (string) get_post_meta( $post->ID, '_lb_custom_desc', true ),
				'categories'  => [ 'launch-builder-' . $cat ],
				'keywords'    => [ 'custom' ],
				'inserter'    => true,
				'roles'       => [],
				'slot'        => '',
				'variant_of'  => '',
				'style'       => '',
				'transparent' => false,
				'requires'    => '',
				'custom'      => true,
				'post_id'     => $post->ID,
				'content'     => $post->post_content,
			];
		}
		self::$icache = $out;
		return $out;
	}

	/** A pattern's markup (reads only that pattern's file), or null when there is no such pattern. */
	public static function content( string $slug ): ?string {
		$index = self::index();
		if ( ! isset( $index[ $slug ] ) ) {
			return null;
		}
		if ( ! empty( $index[ $slug ]['custom'] ) ) {
			return (string) $index[ $slug ]['content'];
		}
		Plugin::register_patterns(); // Idempotent: public page views skip it, tools need it.
		$p = \WP_Block_Patterns_Registry::get_instance()->get_registered( 'launch-builder/' . $slug );
		return $p ? (string) $p['content'] : null;
	}

	/**
	 * Every pattern with its content: index() rows plus 'content' (reads every pattern file; prefer index() and
	 * content() in new code). Kept for callers that need the markup of all patterns.
	 */
	public static function all(): array {
		if ( null !== self::$pcache ) {
			return self::$pcache;
		}
		$out = [];
		foreach ( self::index() as $slug => $row ) {
			$row['content'] = self::content( $slug ) ?? '';
			$out[ $slug ] = $row;
		}
		self::$pcache = $out;
		return $out;
	}

	/** The categories of a pattern without the launch-builder prefix, the primary one first ('launch-builder' itself dropped). */
	private static function short_categories( array $p ): array {
		return array_values( array_filter( array_map( static function ( $c ) { return preg_replace( '/^launch-builder-?/', '', (string) $c ); }, (array) ( $p['categories'] ?? [] ) ) ) );
	}

	/** The role the admin preview renders a pattern as: a valid $override, else the first of its Roles: header, else ''. */
	public static function preview_role( string $slug, string $override = '' ): string {
		if ( '' !== $override && isset( Roles::ROLES[ $override ] ) ) {
			return $override;
		}
		$roles = (array) ( self::index()[ $slug ]['roles'] ?? [] );
		return (string) ( $roles[0] ?? '' );
	}

	/** @return array|\WP_Error */
	public static function list_patterns( $input = [] ) {
		$input = (array) $input;
		$want = [];
		foreach ( [ 'category', 'role', 'slot', 'style', 'variant_of' ] as $k ) {
			$want[ $k ] = isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? sanitize_key( (string) $input[ $k ] ) : '';
		}
		if ( '' !== $want['role'] && ! isset( Roles::ROLES[ $want['role'] ] ) ) {
			return new \WP_Error( 'lb_bad_role', 'Unknown role. Roles: ' . implode( ', ', array_keys( Roles::ROLES ) ) . '.', [ 'status' => 400 ] );
		}
		if ( '' !== $want['slot'] && ! isset( Chrome::SLOTS[ $want['slot'] ] ) && 'conversion' !== $want['slot'] ) {
			return new \WP_Error( 'lb_bad_slot', 'Unknown slot. Slots: ' . implode( ', ', array_keys( Chrome::SLOTS ) ) . ', conversion.', [ 'status' => 400 ] );
		}
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 100;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$index = self::index();
		$match = [];
		foreach ( $index as $slug => $p ) {
			$cats = self::short_categories( $p );
			if ( ( '' !== $want['category'] && ! in_array( $want['category'], $cats, true ) )
				|| ( '' !== $want['role'] && ! in_array( $want['role'], (array) $p['roles'], true ) )
				|| ( '' !== $want['slot'] && $want['slot'] !== $p['slot'] )
				|| ( '' !== $want['style'] && $want['style'] !== $p['style'] )
				|| ( '' !== $want['variant_of'] && $want['variant_of'] !== $p['variant_of'] && $want['variant_of'] !== $slug ) ) {
				continue;
			}
			$match[] = $slug;
		}
		$items = [];
		foreach ( array_slice( $match, $offset, $limit ) as $slug ) {
			$p = $index[ $slug ];
			$row = [ 'slug' => $slug, 'title' => $p['title'], 'category' => self::short_categories( $p )[0] ?? '', 'description' => $p['description'] ];
			foreach ( [ 'roles', 'slot', 'variant_of', 'style' ] as $k ) {
				if ( ! empty( $p[ $k ] ) ) {
					$row[ $k ] = $p[ $k ];
				}
			}
			if ( ! empty( $p['transparent'] ) ) {
				$row['transparent'] = true;
			}
			if ( empty( $p['inserter'] ) ) {
				$row['inserter'] = false;
			}
			if ( ! empty( $p['custom'] ) ) {
				$row['custom'] = true;
			}
			if ( ! empty( $input['with_slots'] ) ) {
				$row['slots'] = count( self::slots( parse_blocks( (string) self::content( $slug ) ) ) );
			}
			$items[] = $row;
		}
		$next = $offset + count( $items );
		$out = [ 'count' => count( $items ), 'total' => count( $match ), 'offset' => $offset, 'next_offset' => $next < count( $match ) ? $next : null, 'patterns' => $items ];
		if ( 0 === $offset && Plugin::unavailable() ) {
			$out['unavailable'] = Plugin::unavailable(); // Exist in the plugin but need a companion plugin (e.g. Kadence Blocks Pro).
		}
		return $out;
	}

	public static function get_pattern( $input ) {
		$index = self::index();
		$slug = sanitize_key( $input['slug'] ?? '' );
		if ( ! isset( $index[ $slug ] ) ) {
			return new \WP_Error( 'lb_no_pattern', 'Unknown pattern: ' . $slug . '. Use list-patterns.', [ 'status' => 404 ] );
		}
		$p = $index[ $slug ];
		$content = (string) self::content( $slug );
		$out = [
			'slug'        => $slug,
			'title'       => $p['title'],
			'description' => $p['description'] ?? '',
			'category'    => self::short_categories( $p )[0] ?? '',
			'roles'       => (array) $p['roles'],
			'slot'        => $p['slot'],
			'variant_of'  => $p['variant_of'],
			'style'       => $p['style'],
			'transparent' => ! empty( $p['transparent'] ),
			'slots'       => self::slots( parse_blocks( $content ) ),
		];
		if ( ! empty( $input['include_content'] ) ) {
			$out['content'] = $content;
		}
		return $out;
	}

	/** Editable fields per block type: field => [attr key or null, is HTML inner text]. */
	private static function field_map( string $name ): array {
		switch ( $name ) {
			case 'kadence/advancedheading':
			case 'core/heading':
			case 'core/paragraph':
				return [ 'text' => [ null, true ], 'link' => [ 'link', false ] ];
			case 'kadence/singlebtn':
				return [ 'text' => [ 'text', false ], 'link' => [ 'link', false ] ];
			case 'kadence/infobox':
				return [ 'title' => [ null, 'class:kt-blocks-info-box-title' ], 'text' => [ null, 'class:kt-blocks-info-box-text' ], 'learn_more' => [ null, 'class:kt-blocks-info-box-learnmore' ], 'link' => [ 'link', false ] ];
			case 'kadence/listitem':
				return [ 'text' => [ null, 'class:kt-svg-icon-list-text' ], 'link' => [ 'link', false ] ];
			case 'kadence/testimonial':
				return [ 'quote' => [ 'content', false ], 'name' => [ 'name', false ], 'role' => [ 'occupation', false ] ];
			case 'kadence/pane':
				return [ 'title' => [ null, 'class:kt-blocks-accordion-title' ] ];
			case 'kadence/googlemaps':
				return [ 'location' => [ 'location', false ] ];
			case 'kadence/countup':
				return [ 'label' => [ 'title', false ], 'number' => [ 'end', false ], 'suffix' => [ 'suffix', false ] ];
			case 'launch/rating':
				return [ 'caption' => [ 'text', false ], 'rating' => [ 'rating', false ], 'link' => [ 'link', false ] ];
			case 'core/image':
				return [ 'image' => [ null, false ], 'alt' => [ 'alt', false ] ];
			case 'core/social-link':
				return [ 'url' => [ 'url', false ] ];
			case 'kadence/advanced-form-text':
			case 'kadence/advanced-form-email':
			case 'kadence/advanced-form-telephone':
			case 'kadence/advanced-form-textarea':
				return [ 'label' => [ 'label', false ], 'placeholder' => [ 'placeholder', false ] ];
			case 'kadence/advanced-form-submit':
				return [ 'text' => [ 'text', false ] ];
		}
		return [];
	}

	private static function class_re( string $cls ): string {
		return '/(<(\\w+)[^>]*\\sclass="[^"]*(?<![\\w-])' . preg_quote( $cls, '/' ) . '(?![\\w-])[^"]*"[^>]*>)(.*?)(<\\/\\2>)/s';
	}

	private static function html_of( array $b ): string {
		return implode( '', array_filter( $b['innerContent'] ?? [], 'is_string' ) );
	}

	private static function read_field( array $b, string $field, ?string $attr, $is_html ) {
		$a = $b['attrs'] ?? [];
		$html = self::html_of( $b );
		if ( is_string( $is_html ) ) {
			return preg_match( self::class_re( substr( $is_html, 6 ) ), $html, $m ) ? $m[3] : null;
		}
		if ( $is_html ) {
			return preg_match( '/^\s*<(\w+)[^>]*>(.*)<\/\1>\s*$/s', $html, $m ) ? $m[2] : null;
		}
		if ( 'image' === $field ) {
			return preg_match( '/<img[^>]*\ssrc="([^"]*)"/', $html, $m ) ? $m[1] : null;
		}
		if ( $attr && array_key_exists( $attr, $a ) ) {
			return $a[ $attr ];
		}
		// Values equal to the block default are not saved in the markup; read them from the block type.
		if ( $attr && ! empty( $b['blockName'] ) ) {
			$bt = \WP_Block_Type_Registry::get_instance()->get_registered( $b['blockName'] );
			$def = $bt && isset( $bt->attributes[ $attr ]['default'] ) ? $bt->attributes[ $attr ]['default'] : null;
			if ( is_scalar( $def ) && '' !== $def ) {
				return $def;
			}
		}
		return null;
	}

	/** Flat list of editable slots with stable ids "path:field". */
	public static function slots( array $blocks, string $prefix = '' ): array {
		$out = [];
		foreach ( $blocks as $i => $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			foreach ( self::field_map( $b['blockName'] ) as $field => [ $attr, $is_html ] ) {
				$val = self::read_field( $b, $field, $attr, $is_html );
				if ( null === $val || '' === $val || ( 'link' === $field && '' === (string) $val ) ) {
					continue;
				}
				$out[] = [ 'id' => $path . ':' . $field, 'block' => $b['blockName'], 'field' => $field, 'value' => is_scalar( $val ) ? $val : wp_json_encode( $val ) ];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$out = array_merge( $out, self::slots( $b['innerBlocks'], $path ) );
			}
		}
		return $out;
	}

	private static function &block_at( array &$blocks, array $path ) {
		$ref = &$blocks;
		foreach ( $path as $n => $idx ) {
			if ( $n > 0 ) {
				$ref = &$ref['innerBlocks'];
			}
			$ref = &$ref[ $idx ];
		}
		return $ref;
	}

	private static function write_field( array &$b, string $field, ?string $attr, $is_html, $new ): bool {
		if ( ! is_scalar( $new ) ) {
			return false; // Slots take text, numbers or URLs only.
		}
		$old = self::read_field( $b, $field, $attr, $is_html );
		if ( null === $old ) {
			return false;
		}
		$esc = static function ( $v ) { return esc_html( (string) $v ); };
		if ( is_string( $is_html ) ) {
			$re = self::class_re( substr( $is_html, 6 ) );
			$b['innerContent'] = array_map( static function ( $c ) use ( $re, $new ) {
				return is_string( $c ) ? preg_replace_callback( $re, static function ( $m ) use ( $new ) { return $m[1] . wp_kses_post( $new ) . $m[4]; }, $c, 1 ) : $c;
			}, $b['innerContent'] );
			return true;
		}
		if ( $is_html ) {
			$b['innerContent'] = array_map( static function ( $c ) use ( $new ) {
				return is_string( $c ) ? preg_replace_callback( '/^(\s*<\w+[^>]*>)(.*)(<\/\w+>\s*)$/s', static function ( $m ) use ( $new ) { return $m[1] . wp_kses_post( $new ) . $m[3]; }, $c ) : $c;
			}, $b['innerContent'] );
			return true;
		}
		if ( 'image' === $field ) {
			$url = esc_url_raw( (string) $new );
			$b['innerContent'] = array_map( static function ( $c ) use ( $old, $url ) { return is_string( $c ) ? str_replace( $old, $url, $c ) : $c; }, $b['innerContent'] );
			return true;
		}
		$is_link = in_array( $field, [ 'link', 'url' ], true );
		if ( $is_link ) {
			$newv = esc_url_raw( (string) $new );
		} elseif ( is_numeric( $old ) ) {
			if ( ! is_numeric( $new ) ) {
				return false; // A number slot needs a plain number (no thousands separators).
			}
			$newv = $new + 0;
		} else {
			$newv = wp_kses_post( (string) $new );
		}
		$b['attrs'][ $attr ] = $newv;
		$b['innerContent'] = array_map( static function ( $c ) use ( $old, $newv, $is_link, $esc ) {
			if ( ! is_string( $c ) ) {
				return $c;
			}
			if ( $is_link ) {
				return str_replace( [ 'href="' . $old . '"', "href='" . $old . "'" ], 'href="' . esc_url( $newv ) . '"', $c );
			}
			if ( is_numeric( $old ) ) {
				return $c; // Numbers live in data-* attributes (below); never blind-replace digits in the markup.
			}
			return str_replace( $esc( $old ), $esc( $newv ), $c );
		}, $b['innerContent'] );
		if ( in_array( $attr, [ 'end', 'start', 'suffix', 'prefix' ], true ) ) {
			$b['innerContent'] = array_map( static function ( $c ) use ( $attr, $old, $newv ) { return is_string( $c ) ? str_replace( 'data-' . $attr . '="' . $old . '"', 'data-' . $attr . '="' . esc_attr( $newv ) . '"', $c ) : $c; }, $b['innerContent'] );
		}
		if ( 'alt' === $field ) {
			$b['innerContent'] = array_map( static function ( $c ) use ( $old, $newv ) { return is_string( $c ) ? str_replace( 'alt="' . $old . '"', 'alt="' . esc_attr( $newv ) . '"', $c ) : $c; }, $b['innerContent'] );
		}
		return true;
	}

	/** Markup of a built-in or custom pattern by slug, or null. */
	public static function pattern_content( string $slug ): ?string {
		return self::content( $slug );
	}

	/** Set the visible text of a block that has a "text" slot (list item, heading, button...). */
	public static function set_text( array &$b, string $text ): bool {
		$map = self::field_map( $b['blockName'] ?? '' );
		return isset( $map['text'] ) && self::write_field( $b, 'text', $map['text'][0], $map['text'][1], $text );
	}

	/** Fill slots (by id and/or by default text) in parsed blocks. @return array{0:array,1:array} applied ids, warnings */
	public static function fill( array &$blocks, array $by_id, array $by_value, array $vars = [] ): array {
		$applied = [];
		$warnings = [];
		$targets = [];
		$used = [];
		foreach ( self::slots( $blocks ) as $s ) {
			if ( array_key_exists( $s['id'], $by_id ) ) {
				$targets[ $s['id'] ] = $by_id[ $s['id'] ];
			} elseif ( array_key_exists( (string) $s['value'], $by_value ) ) {
				$targets[ $s['id'] ] = $by_value[ (string) $s['value'] ];
				$used[ (string) $s['value'] ] = true;
			} elseif ( $vars && is_string( $s['value'] ) ) {
				$nv = strtr( $s['value'], $vars ); // longest placeholder first
				if ( $nv !== $s['value'] ) {
					$targets[ $s['id'] ] = $nv;
				}
			}
		}
		foreach ( array_diff_key( $by_value, $used ) as $k => $_ ) {
			$warnings[] = 'No slot has the text: ' . mb_substr( (string) $k, 0, 70 );
		}
		foreach ( array_diff_key( $by_id, $targets ) as $id => $v ) {
			$warnings[] = 'Unknown slot id: ' . $id;
		}
		foreach ( $targets as $id => $new ) {
			[ $path, $field ] = explode( ':', $id, 2 );
			$ref = &self::block_at( $blocks, array_map( 'intval', explode( '.', $path ) ) );
			$map = self::field_map( $ref['blockName'] ?? '' );
			if ( isset( $map[ $field ] ) && self::write_field( $ref, $field, $map[ $field ][0], $map[ $field ][1], $new ) ) {
				$applied[] = $id;
			} else {
				$warnings[] = 'Could not set ' . $id;
			}
			unset( $ref );
		}
		return [ $applied, $warnings ];
	}

	/**
	 * Kadence's advanced form keeps its fields in a kadence_form post and the page only references it by id.
	 * Patterns carry the form inline (so they stay portable); applying one creates the form post and swaps in the reference.
	 * The post key is a hash of the form content, so re-applying the same form re-uses the same post.
	 */
	private static function materialize_forms( array &$blocks ): void {
		foreach ( $blocks as &$b ) {
			if ( 'kadence/advanced-form' === ( $b['blockName'] ?? '' ) && empty( $b['attrs']['id'] ) && ! empty( $b['innerBlocks'] ) && post_type_exists( 'kadence_form' ) ) {
				$content = serialize_blocks( [ $b ] );
				$id = Abilities::upsert_post( 'kadence_form', 'form-' . substr( md5( $content ), 0, 12 ), 'Launch Builder form', $content );
				if ( ! is_wp_error( $id ) ) {
					$b['attrs']['id'] = (int) $id;
					$b['innerBlocks'] = [];
					$b['innerContent'] = [];
					$b['innerHTML'] = '';
				}
			} elseif ( ! empty( $b['innerBlocks'] ) ) {
				self::materialize_forms( $b['innerBlocks'] );
			}
		}
	}

	/**
	 * Build markup from a pattern with slots filled. The one build pipeline: fill slots (by id, by default text, then the
	 * legacy $vars placeholders), materialize inline forms, apply '@entries' overrides, strip the hero for '@no_hero',
	 * inject markdown into the pattern's text area.
	 *
	 * $opts['no_hero'] (default true): treat '@no_hero' as the strip-hero directive. apply-pattern passes false, so for it
	 * '@no_hero' stays an ordinary (unknown) slot id exactly as it always has.
	 *
	 * @return array|\WP_Error {content, applied, warnings}
	 */
	public static function build_pattern( string $slug, array $slots = [], array $replace = [], array $vars = [], string $markdown = '', array $opts = [] ) {
		$all = self::index();
		if ( ! isset( $all[ $slug ] ) ) {
			return new \WP_Error( 'lb_no_pattern', 'Unknown pattern: ' . $slug . '. Use list-patterns.', [ 'status' => 404 ] );
		}
		if ( in_array( 'launch-builder-headers', (array) ( $all[ $slug ]['categories'] ?? [] ), true ) ) {
			return new \WP_Error( 'lb_is_header', $slug . ' is a header pattern. Use set-header (or the blueprint header section).', [ 'status' => 400 ] );
		}
		if ( empty( $all[ $slug ]['custom'] ) && ! Plugin::kadence_blocks_active() ) {
			return new \WP_Error( 'lb_no_kadence', 'Kadence Blocks is not active; these patterns need it.', [ 'status' => 412 ] );
		}
		$blocks = parse_blocks( (string) self::content( $slug ) );
		$ent = isset( $slots['@entries'] ) ? (array) $slots['@entries'] : []; // Attribute overrides for the live lists (launch/entries) in the pattern.
		$hero_directive = ! isset( $opts['no_hero'] ) || $opts['no_hero'];
		$no_hero = $hero_directive && ! empty( $slots['@no_hero'] ); // Elements mode: the shared hero element is the page hero, so patterns drop their own.
		unset( $slots['@entries'] );
		if ( $hero_directive ) {
			unset( $slots['@no_hero'] );
		}
		[ $applied, $warnings ] = self::fill( $blocks, $slots, $replace, $vars );
		self::materialize_forms( $blocks );
		if ( $ent ) {
			Entries::apply_attrs( $blocks, $ent );
		}
		if ( $no_hero ) {
			Content::strip_hero( $blocks );
		}
		if ( '' !== trim( $markdown ) && ! Content::inject_markdown( $blocks, $markdown ) ) {
			$warnings[] = 'This pattern has no text area for markdown (use service-body, or add-text on the page).';
		}
		$content = serialize_blocks( $blocks );
		if ( ! empty( $opts['target_id'] ) ) { // opts.target_id = the post the markup is written into: Kadence uniqueIDs become {target}_{h6} (Unique_Ids::rekey).
			$rekeyed = Unique_Ids::rekey( $content, (int) $opts['target_id'], (string) ( $opts['salt'] ?? $slug ) ); // salt: slug + section index when one post gets several sections.
			$content = is_wp_error( $rekeyed ) ? $content : $rekeyed;
		}
		return [ 'content' => $content, 'applied' => $applied, 'warnings' => $warnings ];
	}

	public static function apply_pattern( $input ) {
		$all = self::index();
		$slug = sanitize_key( $input['slug'] ?? '' );
		if ( ! isset( $all[ $slug ] ) ) {
			return new \WP_Error( 'lb_no_pattern', 'Unknown pattern: ' . $slug, [ 'status' => 404 ] );
		}
		if ( in_array( 'launch-builder-headers', (array) ( $all[ $slug ]['categories'] ?? [] ), true ) ) {
			return new \WP_Error( 'lb_is_header', 'That is a header pattern. Use set-header.', [ 'status' => 400 ] );
		}
		if ( empty( $all[ $slug ]['custom'] ) && ! Plugin::kadence_blocks_active() ) {
			return new \WP_Error( 'lb_no_kadence', 'Kadence Blocks is not active; these patterns need it.', [ 'status' => 412 ] );
		}
		$status = isset( $input['status'] ) ? (string) $input['status'] : null;
		if ( null !== $status && ! in_array( $status, [ 'draft', 'publish', 'private' ], true ) ) {
			return new \WP_Error( 'lb_status', 'status must be draft, publish or private.', [ 'status' => 400 ] );
		}
		if ( in_array( $status, [ 'publish', 'private' ], true ) && ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot publish pages.', [ 'status' => 403 ] );
		}
		// The shared pipeline. apply-pattern has never taken $vars or the '@no_hero' directive, and still does not.
		$built = self::build_pattern( $slug, (array) ( $input['slots'] ?? [] ), (array) ( $input['replace'] ?? [] ), [], (string) ( $input['markdown'] ?? '' ), [ 'no_hero' => false ] );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$content = $built['content'];
		$applied = $built['applied'];
		$warnings = $built['warnings'];

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$creating = 0 === $post_id;
		if ( $creating ) {
			$title = sanitize_text_field( $input['title'] ?? '' );
			if ( '' === $title ) {
				return new \WP_Error( 'lb_title', 'title is required when post_id is 0.', [ 'status' => 400 ] );
			}
			$post_id = wp_insert_post( [ 'post_type' => 'page', 'post_title' => $title, 'post_status' => $status ?? 'draft', 'post_content' => wp_slash( $content ) ], true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error( 'lb_forbidden', 'You cannot edit that post.', [ 'status' => 403 ] );
			}
			$existing = (string) get_post_field( 'post_content', $post_id );
			$pos = $input['position'] ?? 'append';
			$merged = 'replace' === $pos ? $content : ( 'prepend' === $pos ? $content . "\n\n" . $existing : $existing . "\n\n" . $content );
			$r = wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $merged ), 'post_status' => $status ?? get_post_status( $post_id ) ], true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( $input['full_width'] ?? $creating ) {
			Content::set_layout( (int) $post_id, [ 'width' => 'fullwidth', 'style' => 'unboxed', 'title' => 'hide' ] );
		}
		return [ 'post_id' => $post_id, 'created' => $creating, 'applied' => $applied, 'warnings' => $warnings, 'edit_url' => get_edit_post_link( $post_id, 'raw' ), 'url' => get_permalink( $post_id ) ];
	}

	/** Forget the per-request pattern cache (after a custom pattern is saved or deleted). */
	public static function reset(): void {
		self::$pcache = null;
		self::$icache = null;
	}

	/* ------------------------------------------------------------------ custom patterns */

	/** @return true|\WP_Error */
	private static function validate_markup( string $content ) {
		if ( strlen( $content ) > 300000 ) {
			return new \WP_Error( 'lb_too_big', 'Markup is over 300 KB.', [ 'status' => 413 ] );
		}
		// Defence in depth only. Safety on save still comes from WordPress's own kses for users without unfiltered_html.
		$probe = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/<\s*(script|iframe|object|embed|style|link|meta|base|form)\b|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|<[^>]*[\s\/"\']on[a-z]{3,}\s*=/i', $probe ) ) {
			return new \WP_Error( 'lb_unsafe', 'Markup contains script, iframe/embed, form tags or inline event handlers.', [ 'status' => 400 ] );
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		$bad = [];
		$walk = static function ( array $blocks ) use ( &$walk, &$bad, $registry ) {
			foreach ( $blocks as $b ) {
				if ( ! empty( $b['blockName'] ) && ! $registry->is_registered( $b['blockName'] ) ) {
					$bad[ $b['blockName'] ] = true;
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		if ( $bad ) {
			return new \WP_Error( 'lb_unknown_blocks', 'Unregistered blocks: ' . implode( ', ', array_keys( $bad ) ) . '. Is the plugin that provides them active?', [ 'status' => 400 ] );
		}
		return true;
	}

	public static function save_pattern( $input ) {
		$slug = sanitize_key( $input['slug'] ?? '' );
		if ( '' === $slug ) {
			return new \WP_Error( 'lb_slug', 'slug is required.', [ 'status' => 400 ] );
		}
		if ( isset( Plugin::pattern_index()[ $slug ] ) ) { // Also the built-ins hidden because a companion plugin is off.
			return new \WP_Error( 'lb_slug_taken', 'That slug belongs to a built-in pattern. Pick another.', [ 'status' => 409 ] );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( '' === $content && ! empty( $input['from_post_id'] ) ) {
			$pid = (int) $input['from_post_id'];
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				return new \WP_Error( 'lb_forbidden', 'You cannot read that post.', [ 'status' => 403 ] );
			}
			$content = (string) get_post_field( 'post_content', $pid );
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'lb_empty', 'Provide content or from_post_id.', [ 'status' => 400 ] );
		}
		$ok = self::validate_markup( $content );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$id = Abilities::upsert_post( 'wp_block', 'custom-' . $slug, sanitize_text_field( $input['title'] ?? $slug ), $content );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' ); // A normal pattern, not a synced one.
		update_post_meta( $id, '_lb_custom_slug', $slug );
		update_post_meta( $id, '_lb_custom_category', sanitize_key( $input['category'] ?? 'custom' ) );
		update_post_meta( $id, '_lb_custom_desc', sanitize_text_field( $input['description'] ?? '' ) );
		self::reset();
		return [ 'slug' => $slug, 'id' => $id, 'slots' => count( self::slots( parse_blocks( $content ) ) ) ];
	}

	public static function delete_pattern( $input ) {
		$slug = sanitize_key( $input['slug'] ?? '' );
		$all = self::index();
		if ( empty( $all[ $slug ]['custom'] ) ) {
			return new \WP_Error( 'lb_not_custom', 'Not a custom pattern.', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'delete_post', (int) $all[ $slug ]['post_id'] ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot delete that.', [ 'status' => 403 ] );
		}
		wp_delete_post( (int) $all[ $slug ]['post_id'], true );
		self::reset();
		return [ 'deleted' => $slug ];
	}
}
