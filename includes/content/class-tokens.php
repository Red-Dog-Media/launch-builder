<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic text: one engine for {{ns.key}} tokens, their URL-encoded and #hash-link forms, in blocks, SEO strings and
 * summaries.
 *
 * Syntax
 *   {{ns.key}}              the value, escaped for where it stands (text, attribute, link target)
 *   {{ns.key|fallback}}     the fallback when the value is empty or unknown ({{ns.key|}} = nothing, no marker)
 *   %7B%7Bns.key%7D%7D      inside URLs that were percent-encoded (map embeds): the value, percent-encoded
 *   href="#lb-cta_url"      hash links, for link values only: #lb-{key} for the Business Info links (phone_link,
 *                           maps_url, cta_url), #entry-url, #page-url, #page-parent_url, #area-url, #service-url,
 *                           #site-url (single or double quotes). Every other # anchor is left alone.
 *
 * Rules
 *   - Cheap prefilter (strpos) before any regex; every token is replaced the first time a block's HTML is seen, so the
 *     render_block filter of each ancestor never matches it again. Unknown or empty tokens print the fallback, else
 *     nothing for visitors and a small dashed marker for users who can edit posts (text only, never in attributes).
 *   - Text values resolve one more level of tokens (lb, site, area and service only: a summary may say {{area.name}}),
 *     then are escaped once: esc_html in text, esc_attr in attributes, esc_url for link values (javascript: is
 *     dropped). Textarea line breaks only in text.
 *   - The object tokens resolve against (entry.*): the Tokens stack top (push/pop, e.g. each card of a query loop), else
 *     the global post in the loop / REST / feed rendering, else the queried object. page.* is the page being viewed
 *     (Context::simulate() post in previews). SEO filters and seo_value() use the queried or an explicit object.
 *
 * Namespaces: lb (Business Info), site, page, entry, area, service, meta (Meta Box fields registered for the
 * object's type and allowed by Compat::mb_fields_for(), read with rwmb_meta and an explicit object ID; nothing without
 * Meta Box). catalog() lists them all.
 */
final class Tokens {

	/** Namespaces and their keys (the contract). lb = the Business Info fields (Company::FIELDS); meta = per site. */
	const NAMESPACES = [
		'lb'      => [],
		'site'    => [ 'name', 'url', 'tagline', 'year' ],
		'page'    => [ 'title', 'url', 'summary', 'parent', 'parent_url' ],
		'entry'   => [ 'title', 'summary', 'url', 'image', 'icon', 'parent', 'parent_url', 'type' ],
		'area'    => [ 'name', 'parent', 'url', 'list' ],
		'service' => [ 'name', 'url', 'summary' ],
		'meta'    => [],
	];

	/** Hash links that resolve (in href="..." with single or double quotes). Other # anchors are never touched. */
	const HASH_LINKS = [ '#lb-{key}', '#entry-url', '#page-url', '#page-parent_url', '#area-url', '#service-url', '#site-url' ];

	/** Namespaces allowed inside summaries and Business Info text (no page/entry context there). */
	const SUMMARY_NAMESPACES = [ 'lb', 'site', 'area', 'service' ];

	/** Context tokens whose value is a URL (escaped with esc_url; usable as link targets). */
	const URL_KEYS = [ 'site.url', 'page.url', 'page.parent_url', 'entry.url', 'entry.image', 'entry.parent_url', 'area.url', 'service.url' ];

	/** Attributes whose value is a URL: a whole-value token there is escaped as a link, a hash link resolves there. */
	const URL_ATTRS = [ 'href', 'src', 'action', 'formaction', 'poster', 'cite', 'data-href', 'data-src', 'data-url' ];

	/** What the context tokens mean (catalog). */
	const DESCRIPTIONS = [
		'site.name'            => 'Site title (Settings > General)',
		'site.url'             => 'Home page URL',
		'site.tagline'         => 'Site tagline (Settings > General)',
		'site.year'            => 'The current year (copyright lines)',
		'page.title'           => 'Title of the page being viewed (term name on archives)',
		'page.url'             => 'URL of the page being viewed',
		'page.summary'         => 'Excerpt of the page being viewed, else the site tagline',
		'page.parent'          => 'Title of the parent page (or parent service / parent area)',
		'page.parent_url'      => 'URL of the parent page',
		'entry.title'          => 'Title of the entry being rendered (each card of a query loop, else the current post)',
		'entry.summary'        => 'Summary of the entry (excerpt, else the first words of its text)',
		'entry.url'            => 'URL of the entry',
		'entry.image'          => 'Featured image URL of the entry (large size)',
		'entry.icon'           => 'Icon name of the entry (_lb_icon)',
		'entry.parent'         => 'Title of the parent entry (parent service or area)',
		'entry.parent_url'     => 'URL of the parent entry',
		'entry.type'           => 'Kind of entry (Service, Service area, Page, Post)',
		'area.name'            => 'The service area being viewed or rendered (on an area service page: its area)',
		'area.parent'          => 'Parent area of that area (e.g. the county)',
		'area.url'             => 'URL of that area, else the service-areas hub page',
		'area.list'            => 'Comma list of areas: the sub-areas of the current area, else the top-level areas',
		'service.name'         => 'The service being viewed or rendered (on an area service page: the service it is about)',
		'service.url'          => 'URL of that service',
		'service.summary'      => 'Summary of that service',
	];

	/** {{ns.key}} / {{ns.key|fallback}}: groups 1 ns, 2 key, 3 '|' when a fallback is given, 4 fallback. */
	private const TOKEN = '(?i:\{\{\s*(lb|site|page|entry|area|service|meta)\.([A-Za-z0-9_-]+)\s*(?:(\|)([^{}]*?))?\s*\}\})'; // Case does not matter: {{LB.Phone}} is {{lb.phone}}.

	/** Percent-encoded form: groups 1 ns, 2 key, 3 '%7C' when a fallback is given, 4 encoded fallback. */
	private const ENCODED = '%7B%7B(lb|site|page|entry|area|service|meta)\.([A-Za-z0-9_-]+)(?:(%7C)(.*?))?%7D%7D';

	/** @var bool inside a non-block text filter (a token that reads a title must not resolve itself again). */
	private static $busy = false;

	/** @var array<int,\WP_Post|null> objects being rendered, innermost last. */
	private static $stack = [];

	/** @var array<int,mixed> the global $post before each push(). */
	private static $saved = [];

	/** @var array<string,array|null> per-request lookups (cleared by reset()). */
	private static $memo = [];

	public static function boot(): void {
		// Priority 1: a core/post-template loop inside an entry template adds its own priority-1 filter later, so it
		// still wins for its items.
		add_filter( 'render_block_context', [ __CLASS__, 'block_context' ], 1 );
		$seo = [ 'rank_math/frontend/title', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_title', 'rank_math/opengraph/twitter/twitter_description', 'wpseo_title', 'wpseo_metadesc', 'wpseo_opengraph_title', 'wpseo_opengraph_desc', 'wpseo_twitter_title', 'wpseo_twitter_description' ];
		foreach ( $seo as $hook ) {
			add_filter( $hook, [ __CLASS__, 'seo' ], 20 );
		}
		add_filter( 'get_the_excerpt', [ __CLASS__, 'excerpt' ], 20, 2 );
		// Text outside the blocks that visitors and crawlers read: titles, menus, widgets, image alt text. wp-admin keeps the raw tokens.
		foreach ( [ 'the_title', 'widget_title', 'nav_menu_item_title' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'text_html' ], 20 );
		}
		foreach ( [ 'single_post_title', 'single_term_title', 'single_cat_title', 'single_tag_title' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'text_plain' ], 20 );
		}
		add_filter( 'document_title_parts', [ __CLASS__, 'title_parts' ], 20 );
		add_filter( 'nav_menu_link_attributes', [ __CLASS__, 'menu_link_attributes' ], 20 );
		foreach ( [ 'widget_text', 'widget_text_content', 'widget_custom_html_content' ] as $hook ) {
			add_filter( $hook, [ __CLASS__, 'widget_html' ], 20 );
		}
		add_filter( 'wp_get_attachment_image_attributes', [ __CLASS__, 'image_attributes' ], 20 );
		add_action( 'clean_post_cache', [ __CLASS__, 'reset' ] );
		add_action( 'clean_term_cache', [ __CLASS__, 'reset' ] );
	}

	/** Forget per-request lookups (after Business Info, a post or a term changed). */
	public static function reset(): void {
		self::$memo = [];
	}

	/* ------------------------------------------------------------------ public API */

	/**
	 * Replace every token in $s. $context: 'html' (default, block HTML: each token escaped for where it stands), 'attr'
	 * or 'url' ($s is one attribute value / link target), 'text' (plain, unescaped, for SEO strings; the SEO plugin
	 * escapes).
	 */
	public static function replace( string $s, string $context = 'html' ): string {
		if ( '' === $s ) {
			return $s;
		}
		switch ( $context ) {
			case 'text':
				return self::has( $s ) ? self::plain( $s, self::ctx() ) : $s;
			case 'attr':
			case 'url':
				return ( self::has( $s ) || null !== self::hash( trim( $s ) ) ) ? self::attr_value( $s, 'url' === $context, self::ctx() ) : $s;
		}
		return self::has( $s ) ? self::html_pass( $s, self::ctx() ) : $s;
	}

	/** render_block (via Company::filter_block): the tokens in one block's HTML. */
	public static function render_block( string $html, array $block = [] ): string {
		if ( 'kadence/googlemaps' === ( $block['blockName'] ?? '' ) ) { // A map that follows Business Info (its location is a token) with no address yet prints nothing, not a map of nowhere.
			$loc = $block['attrs']['location'] ?? null;
			if ( is_string( $loc ) && false !== strpos( $loc, '{{' ) && '' === trim( self::replace( $loc, 'text' ) ) ) {
				return '';
			}
		}
		return self::replace( $html );
	}

	/** One token as block HTML (the [lb field="..."] shortcode). Unknown keys give ''. */
	public static function html( string $ns, string $key ): string {
		if ( ! isset( self::NAMESPACES[ $ns ] ) ) {
			return '';
		}
		$ctx = self::ctx();
		$r = self::lookup( $ns, $key, $ctx );
		return null === $r ? '' : self::format( $r, 'html', null, $ns, $key );
	}

	/**
	 * Is $u a link target that stays as written in stored content because tokens resolve it at render time (Markdown
	 * links in add-text/build-site keep it instead of escaping it to '#')? Any {{ns.key}} token (optionally after
	 * mailto:, tel: or sms:) and the allow-listed hash links.
	 */
	public static function is_url_token( string $u ): bool {
		$u = trim( $u );
		return (bool) preg_match( '/^(?:(?:mailto|tel|sms):)?' . self::TOKEN . '$/', $u ) || null !== self::hash( $u );
	}

	/**
	 * Make $post the object tokens such as {{entry.title}} resolve against (until pop()). Also sets the global post and
	 * its postdata (restored by pop()), and core/post-* blocks rendered meanwhile get it as their postId context.
	 *
	 * @param int|\WP_Post $post
	 */
	public static function push( $post ): void {
		$p = get_post( $post );
		$p = $p instanceof \WP_Post ? $p : null;
		self::$stack[] = $p;
		self::$saved[] = $GLOBALS['post'] ?? null;
		if ( $p ) {
			$GLOBALS['post'] = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored by pop().
			setup_postdata( $p );
		}
	}

	/** Undo the last push(). @return \WP_Post|null the object that was on top. */
	public static function pop(): ?\WP_Post {
		if ( ! self::$stack ) {
			return null;
		}
		$top = array_pop( self::$stack );
		$prev = array_pop( self::$saved );
		if ( $top ) {
			$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restoring.
			if ( $prev instanceof \WP_Post ) {
				setup_postdata( $prev );
			}
		}
		return $top ?: null;
	}

	/**
	 * The object entry.* resolves against: the stack top, else the simulated post (previews), else the post in the loop
	 * (or REST/feed rendering), else the queried post.
	 */
	public static function context(): ?\WP_Post {
		if ( self::$stack ) {
			return end( self::$stack ) ?: null;
		}
		$sim = Context::current();
		if ( $sim && $sim['post'] instanceof \WP_Post ) {
			return $sim['post'];
		}
		if ( in_the_loop() || self::rest() || is_feed() ) {
			$p = get_post();
			return $p instanceof \WP_Post ? $p : null;
		}
		$q = did_action( 'wp' ) ? get_queried_object() : null;
		return $q instanceof \WP_Post ? $q : null;
	}

	/**
	 * The page being viewed (page.*): the simulated post (pattern previews, audits), else the queried object on the
	 * front end (a post or, on archives, a term), else the global post (REST and wp-admin: the block renderer sets it).
	 *
	 * @return \WP_Post|\WP_Term|null
	 */
	public static function page_object() {
		$sim = Context::current();
		if ( $sim ) {
			return $sim['post'];
		}
		if ( did_action( 'wp' ) && ! is_admin() && ! self::rest() ) {
			$q = get_queried_object();
			return ( $q instanceof \WP_Post || $q instanceof \WP_Term ) ? $q : null;
		}
		$p = get_post();
		return $p instanceof \WP_Post ? $p : null;
	}

	/**
	 * Every token with its description, kind, where it may be used, its link form and its current value. Values are
	 * resolved against $obj (post/term or ID) when given, else against the current request.
	 *
	 * @param int|\WP_Post|\WP_Term|null $obj
	 * @return array<int,array{token:string,ns:string,key:string,description:string,kind:string,contexts:string[],link_token:?string,advanced:bool,available:bool,value:?string}>
	 */
	public static function catalog( $obj = null ): array {
		$ctx = null === $obj ? self::ctx() : self::ctx_for( self::object( $obj ) );
		$rows = [];
		foreach ( Company::fields() as $k => $f ) {
			$rows[] = self::row( 'lb', $k, $f[0] . ( '' !== $f[3] ? '. ' . $f[3] : '' ), $ctx );
		}
		foreach ( self::DESCRIPTIONS as $tok => $desc ) {
			[ $ns, $k ] = explode( '.', $tok, 2 );
			$rows[] = self::row( $ns, $k, $desc, $ctx );
		}
		$meta_obj = $ctx['entry'] ?: ( $ctx['page'] instanceof \WP_Post ? $ctx['page'] : null );
		$meta_rows = 0;
		if ( $meta_obj && function_exists( 'rwmb_meta' ) ) { // Only what Compat::mb_fields_for() allows, the same list the tokens read.
			foreach ( Compat::mb_fields_for( $meta_obj ) as $id => $field ) {
				$id = (string) $id;
				if ( '' !== $id && '_' !== $id[0] ) {
					$rows[] = self::row( 'meta', $id, 'Meta Box field: ' . $field['name'], $ctx );
					$meta_rows++;
				}
			}
		}
		if ( function_exists( 'rwmb_meta' ) ) { // Fields of Meta Box settings pages work on every page.
			$have = [];
			foreach ( $rows as $r ) {
				if ( 'meta' === $r['ns'] ) {
					$have[ $r['key'] ] = true;
				}
			}
			foreach ( array_slice( array_diff_key( Compat::mb_setting_index(), $have ), 0, 40, true ) as $id => $set ) {
				$rows[] = self::row( 'meta', (string) $id, 'Meta Box settings page field: ' . $set['field']['name'], $ctx );
				$meta_rows++;
			}
		}
		if ( ! $meta_rows ) { // Nothing to list for this object: still say how the token works.
			$rows[] = [ 'token' => '{{meta.<field_id>}}', 'ns' => 'meta', 'key' => '<field_id>', 'description' => 'A Meta Box field registered for the post type of the object being rendered, or a field of a Meta Box settings page (nothing without Meta Box)', 'kind' => 'text', 'contexts' => [ 'html', 'attr', 'text' ], 'link_token' => null, 'advanced' => false, 'available' => function_exists( 'rwmb_meta' ), 'value' => null ];
		}
		return $rows;
	}

	/**
	 * A token's plain-text value for SEO strings (titles, descriptions, Open Graph), resolved against an explicit object
	 * (falling back to the queried object only when $obj is empty). $key is 'ns.key', '{{ns.key}}' or a bare lb key.
	 *
	 * @param string                    $key
	 * @param int|\WP_Post|\WP_Term|null $obj
	 */
	public static function seo_value( string $key, $obj = null ): string {
		$key = trim( $key );
		if ( preg_match( '/^\{\{\s*(.+?)\s*\}\}$/', $key, $m ) ) {
			$key = $m[1];
		}
		if ( false === strpos( $key, '.' ) ) {
			$key = 'lb.' . $key;
		}
		[ $ns, $k ] = explode( '.', $key, 2 );
		if ( ! isset( self::NAMESPACES[ $ns ] ) ) {
			return '';
		}
		$ctx = self::ctx_for( $obj ? self::object( $obj ) : self::page_object() );
		return trim( self::format( self::lookup( $ns, $k, $ctx ), 'plain', null, $ns, $k ) );
	}

	/* ------------------------------------------------------------------ hooks */

	/** render_block_context (priority 1): core/post-* blocks inside a push()ed render show that entry. */
	public static function block_context( $context ) {
		if ( ! self::$stack || ! is_array( $context ) ) {
			return $context;
		}
		$top = end( self::$stack );
		if ( $top instanceof \WP_Post ) {
			$context['postId'] = $top->ID;
			$context['postType'] = $top->post_type;
		}
		return $context;
	}

	/** Rank Math / Yoast titles, descriptions and Open Graph: plain values for the page being viewed. */
	public static function seo( $s ) {
		if ( ! is_string( $s ) || ! self::has( $s ) ) {
			return $s;
		}
		return self::plain( $s, self::ctx_for( self::page_object() ) );
	}

	/** Every token in a plain-text value, resolved against one post (baking SEO fields). */
	public static function plain_for( string $s, \WP_Post $p ): string {
		return self::has( $s ) ? self::plain( $s, self::ctx_for( $p ) ) : $s;
	}

	/** An entry's summary on a card: its tokens resolve against that entry, not against the page the card is on. */
	public static function for_entry( string $s, \WP_Post $p ): string {
		return self::has( $s ) ? self::plain( $s, self::ctx_for( $p ), array_merge( self::SUMMARY_NAMESPACES, [ 'entry', 'page' ] ) ) : $s;
	}

	/** get_the_excerpt: summaries are plain text; only lb, site, area and service tokens resolve there. */
	public static function excerpt( $excerpt, $post = null ) {
		if ( ! is_string( $excerpt ) || false === strpos( $excerpt, '{{' ) && false === stripos( $excerpt, '%7B%7B' ) ) {
			return $excerpt;
		}
		$p = get_post( $post );
		return self::plain( $excerpt, self::ctx_for( $p ?: self::page_object() ), self::SUMMARY_NAMESPACES );
	}

	/** Titles, widget titles, menu labels: HTML text, so each value is escaped. Not in wp-admin (editors fix the raw token there). */
	public static function text_html( $s ) {
		if ( ! is_string( $s ) || self::$busy || is_admin() || ! self::has( $s ) ) {
			return $s;
		}
		self::$busy = true; // {{page.title}} reads the title, which comes back through this filter.
		try {
			return self::plain( $s, self::ctx(), null, true );
		} finally {
			self::$busy = false;
		}
	}

	/** The title of the page or archive for the <title> element and the like: plain text (WordPress escapes it where it prints it). */
	public static function text_plain( $s ) {
		if ( ! is_string( $s ) || self::$busy || is_admin() || ! self::has( $s ) ) {
			return $s;
		}
		self::$busy = true;
		try {
			return self::plain( $s, self::ctx() );
		} finally {
			self::$busy = false;
		}
	}

	/** document_title_parts: every part is plain text. */
	public static function title_parts( $parts ) {
		if ( ! is_array( $parts ) || is_admin() ) {
			return $parts;
		}
		foreach ( $parts as $k => $v ) {
			if ( is_string( $v ) ) {
				$parts[ $k ] = self::text_plain( $v );
			}
		}
		return $parts;
	}

	/** A classic menu item's link: #lb-phone_link and the other allow-listed hash links resolve (braces do not survive saving a menu URL). */
	public static function menu_link_attributes( $atts ) {
		if ( ! is_array( $atts ) || empty( $atts['href'] ) || ! is_string( $atts['href'] ) || is_admin() ) {
			return $atts;
		}
		$atts['href'] = self::replace( $atts['href'], 'url' );
		return $atts;
	}

	/** Legacy text and custom HTML widgets: block-style HTML. */
	public static function widget_html( $html ) {
		if ( ! is_string( $html ) || is_admin() || ! self::has( $html ) ) {
			return $html;
		}
		return self::replace( $html );
	}

	/** wp_get_attachment_image_attributes: the alt text is plain text (core escapes it where it prints it). */
	public static function image_attributes( $attr ) {
		if ( ! is_array( $attr ) || empty( $attr['alt'] ) || ! is_string( $attr['alt'] ) || is_admin() ) {
			return $attr;
		}
		$attr['alt'] = self::text_plain( $attr['alt'] );
		return $attr;
	}

	/* ------------------------------------------------------------------ engine */

	private static function rest(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
	}

	/** @param int|\WP_Post|\WP_Term $obj @return \WP_Post|\WP_Term|null */
	private static function object( $obj ) {
		if ( $obj instanceof \WP_Post || $obj instanceof \WP_Term ) {
			return $obj;
		}
		$p = $obj ? get_post( (int) $obj ) : null;
		return $p instanceof \WP_Post ? $p : null;
	}

	/** The resolution context of the current request. */
	private static function ctx(): array {
		return [ 'page' => self::page_object(), 'entry' => self::context(), 'nested' => false, 'explicit' => false ];
	}

	/** A context for one explicit object (it is both the page and the entry). */
	private static function ctx_for( $o ): array {
		return [ 'page' => ( $o instanceof \WP_Post || $o instanceof \WP_Term ) ? $o : null, 'entry' => $o instanceof \WP_Post ? $o : null, 'nested' => false, 'explicit' => true ];
	}

	/** Prefilter: could $s hold a token? (strpos first; a short anchored regex only when the cheap test passes.) */
	private static function has( string $s ): bool {
		if ( false !== strpos( $s, '{{' ) && preg_match( '/\{\{\s*(?:lb|site|page|entry|area|service|meta)\./i', $s ) ) {
			return true;
		}
		if ( false !== stripos( $s, '%7B%7B' ) ) {
			return true;
		}
		foreach ( [ '#lb-', '#entry-url', '#page-', '#area-url', '#service-url', '#site-url' ] as $prefix ) { // '#' is in almost every block (colours, '#' links): plain strpos only.
			if ( false !== strpos( $s, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** [ns, key] for an allow-listed hash link ('#lb-cta_url', '#entry-url'...), else null. */
	private static function hash( string $v ): ?array {
		if ( ! preg_match( '/^#(?:lb-([a-z0-9_]+)|(entry|page|area|service|site)-(url|parent_url))$/', $v, $m ) ) {
			return null;
		}
		if ( '' !== $m[1] ) {
			return Company::is_link_key( $m[1] ) ? [ 'lb', $m[1] ] : null;
		}
		$pair = $m[2] . '.' . $m[3];
		return in_array( $pair, [ 'entry.url', 'page.url', 'page.parent_url', 'area.url', 'service.url', 'site.url' ], true ) ? [ $m[2], $m[3] ] : null;
	}

	/** Block HTML: tags (attribute values, hash links) and text tokens in one pass, then percent-encoded tokens. */
	private static function html_pass( string $html, array $ctx ): string {
		if ( false !== stripos( $html, 'ld+json' ) ) { // Structured data is JSON, not HTML: an apostrophe must not come out as &#039;.
			$json = (string) preg_replace_callback(
				'#(<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
				static function ( $m ) use ( $ctx ) {
					return $m[1] . self::json_pass( $m[2], $ctx ) . $m[3];
				},
				$html
			);
			if ( '' !== $json ) {
				$html = $json;
			}
		}
		$out = preg_replace_callback(
			'/(<[a-zA-Z][^>]*>)|' . self::TOKEN . '/',
			static function ( $m ) use ( $ctx ) {
				if ( '' !== $m[1] ) {
					return self::tag( $m[1], $ctx );
				}
				return self::token( $m[2], $m[3], ( $m[4] ?? '' ) === '|' ? (string) ( $m[5] ?? '' ) : null, 'html', $ctx );
			},
			$html
		);
		if ( null === $out ) {
			return $html; // PCRE limit: leave the block as it was.
		}
		if ( false !== stripos( $out, '%7B%7B' ) ) {
			$out = self::encoded( $out, $ctx );
		}
		return $out;
	}

	/** Tokens inside a JSON-LD block: each value as the inside of a JSON string (quotes, slashes and tags escaped). */
	private static function json_pass( string $json, array $ctx ): string {
		if ( ! self::has( $json ) ) {
			return $json;
		}
		return (string) preg_replace_callback(
			'/' . self::TOKEN . '/',
			static function ( $t ) use ( $ctx ) {
				$v = self::token( $t[1], $t[2], ( $t[3] ?? '' ) === '|' ? (string) ( $t[4] ?? '' ) : null, 'plain', $ctx );
				return substr( (string) wp_json_encode( $v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE ), 1, -1 );
			},
			$json
		);
	}

	/** Tokens and hash links inside one tag's quoted attribute values. */
	private static function tag( string $tag, array $ctx ): string {
		if ( false === strpos( $tag, '{{' ) && false === strpos( $tag, '#' ) ) {
			return $tag;
		}
		$new = preg_replace_callback(
			'/(\s)([^\s"\'>\/=]+)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/',
			static function ( $a ) use ( $ctx ) {
				$q = substr( $a[0], -1 );
				$val = '"' === $q ? $a[4] : ( $a[5] ?? '' );
				$is_url = in_array( strtolower( $a[2] ), self::URL_ATTRS, true );
				$out = $val;
				if ( $is_url && '' !== $val && '#' === $val[0] ) {
					$h = self::hash( $val );
					if ( $h ) {
						$out = self::token( $h[0], $h[1], null, 'url', $ctx );
					}
				} elseif ( false !== strpos( $val, '{{' ) ) {
					$out = self::attr_value( $val, $is_url, $ctx );
				}
				return $a[1] . $a[2] . $a[3] . $q . $out . $q;
			},
			$tag
		);
		return null === $new ? $tag : $new;
	}

	/**
	 * One attribute value: tokens escaped for attributes; a value that is exactly one token in a URL attribute is a link
	 * (esc_url for link values); a whole-value hash link resolves when $is_url.
	 */
	private static function attr_value( string $val, bool $is_url, array $ctx ): string {
		if ( $is_url && '' !== $val && '#' === $val[0] ) {
			$h = self::hash( $val );
			return $h ? self::token( $h[0], $h[1], null, 'url', $ctx ) : $val;
		}
		if ( false === strpos( $val, '{{' ) ) {
			return false !== stripos( $val, '%7B%7B' ) ? self::encoded( $val, $ctx ) : $val;
		}
		$whole = $is_url && preg_match( '/^\s*' . self::TOKEN . '\s*$/', $val );
		$out = (string) preg_replace_callback(
			'/' . self::TOKEN . '/',
			static function ( $t ) use ( $ctx, $whole ) {
				return self::token( $t[1], $t[2], ( $t[3] ?? '' ) === '|' ? (string) ( $t[4] ?? '' ) : null, $whole ? 'url' : 'attr', $ctx );
			},
			$val
		);
		if ( false !== stripos( $out, '%7B%7B' ) ) {
			$out = self::encoded( $out, $ctx );
		}
		return $is_url ? self::safe_link( $out ) : $out;
	}

	/**
	 * A link target built from tokens may not start with a scheme WordPress does not allow (javascript:, data:...), also
	 * when the scheme came from a text value ("{{entry.title}}/x") or is hidden by entities or whitespace.
	 */
	private static function safe_link( string $v ): string {
		$probe = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/', $probe, $m ) && ! in_array( $m[1], wp_allowed_protocols(), true ) ) {
			return '';
		}
		return $v;
	}

	/** Percent-encoded tokens (%7B%7Bns.key%7D%7D, e.g. inside a map embed URL). */
	private static function encoded( string $s, array $ctx ): string {
		return (string) preg_replace_callback(
			'/' . self::ENCODED . '/i',
			static function ( $m ) use ( $ctx ) {
				$ns = strtolower( $m[1] );
				$key = 'meta' === $ns ? $m[2] : strtolower( $m[2] );
				return self::token( $ns, $key, ( $m[3] ?? '' ) !== '' ? rawurldecode( (string) ( $m[4] ?? '' ) ) : null, 'enc', $ctx );
			},
			$s
		);
	}

	/** Plain text (SEO strings, summaries): unescaped values, no markers. $allowed limits the namespaces. */
	private static function plain( string $s, array $ctx, ?array $allowed = null, bool $esc = false ): string {
		$s = (string) preg_replace_callback(
			'/' . self::TOKEN . '/',
			static function ( $t ) use ( $ctx, $allowed, $esc ) {
				if ( $allowed && ! in_array( strtolower( $t[1] ), $allowed, true ) ) {
					return '';
				}
				$v = self::token( $t[1], $t[2], ( $t[3] ?? '' ) === '|' ? (string) ( $t[4] ?? '' ) : null, 'plain', $ctx );
				return $esc ? esc_html( $v ) : $v;
			},
			$s
		);
		if ( false !== stripos( $s, '%7B%7B' ) ) {
			$s = (string) preg_replace_callback(
				'/' . self::ENCODED . '/i',
				static function ( $m ) use ( $ctx, $allowed, $esc ) {
					$ns = strtolower( $m[1] );
					if ( $allowed && ! in_array( $ns, $allowed, true ) ) {
						return '';
					}
					$v = self::token( $ns, 'meta' === $ns ? $m[2] : strtolower( $m[2] ), ( $m[3] ?? '' ) !== '' ? rawurldecode( (string) ( $m[4] ?? '' ) ) : null, 'plain', $ctx );
					return $esc ? esc_html( $v ) : $v;
				},
				$s
			);
		}
		return $s;
	}

	private static function token( string $ns, string $key, ?string $fallback, string $out, array $ctx ): string {
		$ns = strtolower( $ns );
		if ( 'meta' !== $ns ) {
			$key = strtolower( $key ); // Meta Box field ids keep their case; everything else is lower case.
		}
		return self::format( self::lookup( $ns, $key, $ctx ), $out, $fallback, $ns, $key );
	}

	/**
	 * A value escaped for its output: 'html' (text node), 'attr' (attribute value), 'url' (a whole link target), 'enc'
	 * (percent-encoded), 'plain' (unescaped text).
	 *
	 * @param array|null $r lookup() result ({v, kind, lines?}) or null for an unknown token.
	 */
	private static function format( ?array $r, string $out, ?string $fallback, string $ns, string $key ): string {
		if ( null === $r || '' === trim( (string) $r['v'] ) ) {
			if ( null !== $fallback ) {
				$fb = trim( $fallback );
				switch ( $out ) {
					case 'plain':
						return $fb;
					case 'enc':
						return rawurlencode( $fb );
					case 'html':
						return esc_html( $fb );
				}
				return esc_attr( $fb );
			}
			if ( 'html' !== $out || ! current_user_can( 'edit_posts' ) ) {
				return '';
			}
			if ( $r && 'html' === $r['kind'] && 'lb' === $ns ) { // The form embed: say where to paste it.
				return '<div style="border:2px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;color:#64748b;font-size:15px">' . esc_html__( 'Your form embed appears here. Paste it under Launch Builder → Business Info → More options → Form embed.', 'launch-builder' ) . '</div>';
			}
			$title = null === $r ? __( 'Unknown token: visitors see nothing here.', 'launch-builder' ) : __( 'Empty token: visitors see nothing here. Set the value, or give a fallback after a | sign.', 'launch-builder' );
			return '<span class="lb-token-empty" title="' . esc_attr( $title ) . '" style="display:inline-block;padding:0 .3em;border:1px dashed currentColor;border-radius:3px;opacity:.65;font:500 .8em/1.4 ui-monospace,Menlo,Consolas,monospace">' . esc_html( $ns . '.' . $key ) . '</span>';
		}
		$v = (string) $r['v'];
		$kind = $r['kind'];
		switch ( $out ) {
			case 'plain':
				if ( 'url' === $kind ) {
					return $v;
				}
				if ( 'lines' === $kind ) {
					return implode( ', ', $r['lines'] );
				}
				return trim( (string) preg_replace( '/\s*\R\s*/', ' ', wp_strip_all_tags( $v ) ) );
			case 'enc':
				return rawurlencode( wp_strip_all_tags( $v ) );
			case 'html':
				switch ( $kind ) {
					case 'url':
						return esc_url( $v );
					case 'html':
						return $v; // Admin-controlled embed code (stored with kses unless the saver has unfiltered_html).
					case 'multi':
						return nl2br( esc_html( $v ) );
					case 'lines':
						return implode( '<br>', array_map( 'esc_html', $r['lines'] ) );
				}
				return esc_html( $v );
		}
		// attr / url
		switch ( $kind ) {
			case 'url':
				return esc_url( $v );
			case 'html':
				return esc_attr( wp_strip_all_tags( $v ) );
			case 'multi':
				return esc_attr( (string) preg_replace( '/\s*\R\s*/', ' ', $v ) );
		}
		return esc_attr( $v ); // lines: the one-line value.
	}

	/** A text value, with one more level of lb/site/area/service tokens resolved (never deeper). */
	private static function val( string $v, string $kind, array $ctx ): array {
		if ( in_array( $kind, [ 'text', 'multi' ], true ) && false !== strpos( $v, '{{' ) ) {
			if ( empty( $ctx['nested'] ) ) {
				$inner = $ctx;
				$inner['nested'] = true;
				$v = self::plain( $v, $inner, self::SUMMARY_NAMESPACES );
			} else { // Second level: tokens inside a nested value print their fallback or nothing (never resolved further).
				$v = (string) preg_replace_callback(
					'/' . self::TOKEN . '/',
					static function ( $t ) {
						return ( $t[3] ?? '' ) === '|' ? trim( (string) ( $t[4] ?? '' ) ) : '';
					},
					$v
				);
			}
		}
		return [ 'v' => $v, 'kind' => $kind ];
	}

	/**
	 * Resolve one token: {v, kind} (v may be ''), or null when the namespace has no such key. kind: text | url | html |
	 * multi (line breaks) | lines (+ 'lines' parts: the _br tokens).
	 */
	private static function lookup( string $ns, string $key, array $ctx ): ?array {
		$ns = strtolower( $ns );
		if ( 'meta' !== $ns ) {
			$key = strtolower( $key );
		}
		$id = static function ( $o ): string {
			return $o instanceof \WP_Post ? 'p' . $o->ID : ( $o instanceof \WP_Term ? 't' . $o->term_id : '0' );
		};
		$mk = $ns . '.' . $key . '|' . $id( $ctx['entry'] ) . '|' . $id( $ctx['page'] ) . '|' . ( empty( $ctx['nested'] ) ? 0 : 1 ) . ( empty( $ctx['explicit'] ) ? '' : 'x' );
		if ( array_key_exists( $mk, self::$memo ) ) {
			return self::$memo[ $mk ];
		}
		switch ( $ns ) {
			case 'lb':
				$r = self::lookup_lb( $key, $ctx );
				break;
			case 'site':
				if ( 'year' === $key ) {
					$r = self::val( wp_date( 'Y' ), 'text', $ctx );
					break;
				}
				$r = in_array( $key, self::NAMESPACES['site'], true ) ? self::val( 'url' === $key ? home_url( '/' ) : (string) get_bloginfo( 'name' === $key ? 'name' : 'description' ), 'url' === $key ? 'url' : 'text', $ctx ) : null;
				break;
			case 'page':
				$r = self::lookup_page( $key, $ctx );
				break;
			case 'entry':
				$r = self::lookup_entry( $key, $ctx );
				break;
			case 'area':
				$r = self::lookup_area( $key, $ctx );
				break;
			case 'service':
				$r = self::lookup_service( $key, $ctx );
				break;
			case 'meta':
				$r = self::lookup_meta( $key, $ctx );
				break;
			default:
				$r = null;
		}
		if ( count( self::$memo ) > 500 ) {
			self::$memo = [];
		}
		return self::$memo[ $mk ] = $r;
	}

	private static function lookup_lb( string $key, array $ctx ): ?array {
		$fields = Company::fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return null;
		}
		return self::val( Company::get( $key ), 'url' === $fields[ $key ][1] ? 'url' : 'text', $ctx );
	}

	private static function title( $o ): string {
		if ( $o instanceof \WP_Term ) {
			return (string) $o->name;
		}
		return $o instanceof \WP_Post ? (string) get_the_title( $o ) : '';
	}

	private static function url( $o ): string {
		if ( $o instanceof \WP_Term ) {
			$u = get_term_link( $o );
			return is_wp_error( $u ) ? '' : (string) $u;
		}
		return $o instanceof \WP_Post ? (string) get_permalink( $o ) : '';
	}

	/** Parent object: parent post, or parent term. @return \WP_Post|\WP_Term|null */
	private static function parent( $o ) {
		if ( $o instanceof \WP_Post && $o->post_parent ) {
			$p = get_post( $o->post_parent );
			return $p && 'publish' === $p->post_status ? $p : null;
		}
		if ( $o instanceof \WP_Term && $o->parent ) {
			$t = get_term( $o->parent, $o->taxonomy );
			return $t instanceof \WP_Term ? $t : null;
		}
		return null;
	}

	/** Entry summary: the summary field / excerpt, else the first words of the text. */
	private static function entry_summary( \WP_Post $p ): string {
		$s = (string) get_post_meta( $p->ID, '_lb_summary', true );
		if ( '' === $s ) {
			$s = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 22 );
		}
		return $s;
	}

	private static function lookup_page( string $key, array $ctx ): ?array {
		if ( ! in_array( $key, self::NAMESPACES['page'], true ) ) {
			return null;
		}
		$o = $ctx['page'];
		$url_kind = in_array( 'page.' . $key, self::URL_KEYS, true ) ? 'url' : 'text';
		if ( ! $o ) {
			return [ 'v' => '', 'kind' => $url_kind ];
		}
		switch ( $key ) {
			case 'title':
				return self::val( self::title( $o ), 'text', $ctx );
			case 'url':
				return [ 'v' => self::url( $o ), 'kind' => 'url' ];
			case 'summary':
				$s = $o instanceof \WP_Post ? trim( (string) $o->post_excerpt ) : trim( wp_strip_all_tags( (string) $o->description ) );
				if ( '' === $s && $o instanceof \WP_Post ) {
					$s = trim( (string) get_post_meta( $o->ID, '_lb_summary', true ) );
				}
				return self::val( '' !== $s ? $s : (string) get_bloginfo( 'description' ), 'text', $ctx );
			case 'parent':
				return self::val( self::title( self::parent( $o ) ), 'text', $ctx );
			case 'parent_url':
				return [ 'v' => self::url( self::parent( $o ) ), 'kind' => 'url' ];
		}
		return null;
	}

	private static function lookup_entry( string $key, array $ctx ): ?array {
		if ( ! in_array( $key, self::NAMESPACES['entry'], true ) ) {
			return null;
		}
		$p = $ctx['entry'];
		$url_kind = in_array( 'entry.' . $key, self::URL_KEYS, true ) ? 'url' : 'text';
		if ( ! $p instanceof \WP_Post ) {
			return [ 'v' => '', 'kind' => $url_kind ];
		}
		switch ( $key ) {
			case 'title':
				return self::val( self::title( $p ), 'text', $ctx );
			case 'summary':
				return self::val( self::entry_summary( $p ), 'text', $ctx );
			case 'url':
				return [ 'v' => self::url( $p ), 'kind' => 'url' ];
			case 'image':
				return [ 'v' => (string) get_the_post_thumbnail_url( $p, 'large' ), 'kind' => 'url' ];
			case 'icon':
				return [ 'v' => (string) get_post_meta( $p->ID, '_lb_icon', true ), 'kind' => 'text' ];
			case 'parent':
				return self::val( self::title( self::parent( $p ) ), 'text', $ctx );
			case 'parent_url':
				return [ 'v' => self::url( self::parent( $p ) ), 'kind' => 'url' ];
			case 'type':
				$pto = get_post_type_object( $p->post_type );
				return [ 'v' => $pto ? (string) $pto->labels->singular_name : $p->post_type, 'kind' => 'text' ];
		}
		return null;
	}

	/** The entry or page of a post type in context (the rendered entry first, then the page being viewed). */
	private static function current_of( string $post_type, array $ctx ): ?\WP_Post {
		foreach ( [ $ctx['entry'], $ctx['page'] ] as $o ) {
			if ( $o instanceof \WP_Post && $post_type === $o->post_type ) {
				return $o;
			}
		}
		return null;
	}

	/** The area in context: the area being viewed or rendered; on an area service page, its parent area. */
	private static function current_area( array $ctx ): ?\WP_Post {
		$a = self::current_of( Entries::AREA, $ctx );
		if ( $a && Entries::is_area_service( $a ) ) {
			$parent = get_post( $a->post_parent );
			return $parent instanceof \WP_Post ? $parent : null;
		}
		return $a;
	}

	/** The service in context: the service being viewed or rendered; on an area service page, the service it is about. */
	private static function current_service( array $ctx ): ?\WP_Post {
		$s = self::current_of( Entries::SERVICE, $ctx );
		if ( $s ) {
			return $s;
		}
		$a = self::current_of( Entries::AREA, $ctx );
		$sid = $a ? (int) get_post_meta( $a->ID, '_lb_service', true ) : 0;
		$p = $sid ? get_post( $sid ) : null;
		return $p instanceof \WP_Post ? $p : null;
	}

	private static function lookup_area( string $key, array $ctx ): ?array {
		if ( ! in_array( $key, self::NAMESPACES['area'], true ) ) {
			return null;
		}
		$a = self::current_area( $ctx );
		switch ( $key ) {
			case 'name':
				return self::val( $a ? self::title( $a ) : '', 'text', $ctx );
			case 'parent':
				return self::val( $a ? self::title( self::parent( $a ) ) : '', 'text', $ctx );
			case 'url':
				if ( $a ) {
					return [ 'v' => self::url( $a ), 'kind' => 'url' ];
				}
				$hub = (int) ( Roles::hubs()['areas'] ?? 0 );
				return [ 'v' => $hub && 'publish' === get_post_status( $hub ) ? (string) get_permalink( $hub ) : '', 'kind' => 'url' ];
			case 'list':
				$list = $a ? Entries::query( 'area', [ 'parent' => (int) $a->ID, 'limit' => 50 ] ) : [];
				$list = $list ? $list : Entries::query( 'area', [ 'parent' => 'top', 'limit' => 50 ] );
				return [ 'v' => implode( ', ', array_map( static function ( $p ) { return wp_strip_all_tags( get_the_title( $p ) ); }, $list ) ), 'kind' => 'text' ];
		}
		return null;
	}

	private static function lookup_service( string $key, array $ctx ): ?array {
		if ( ! in_array( $key, self::NAMESPACES['service'], true ) ) {
			return null;
		}
		$s = self::current_service( $ctx );
		switch ( $key ) {
			case 'name':
				return self::val( $s ? self::title( $s ) : '', 'text', $ctx );
			case 'url':
				return [ 'v' => $s ? self::url( $s ) : '', 'kind' => 'url' ];
			case 'summary':
				return self::val( $s ? self::entry_summary( $s ) : '', 'text', $ctx );
		}
		return null;
	}

	/**
	 * meta.*: a Meta Box field registered for the object's post type, read with an explicit object ID. Only the fields in
	 * Compat::mb_fields_for() resolve (no password or secret-looking ids, no groups): anything else is an unknown token.
	 * What a value becomes: a picture gives its full-size address (a gallery or file list its first file), a post or
	 * taxonomy field gives the published titles or the term names, rich text, maps and embeds give HTML that went through
	 * kses, everything else gives plain text (a list joins with commas).
	 */
	private static function lookup_meta( string $key, array $ctx ): ?array {
		if ( ! function_exists( 'rwmb_meta' ) ) {
			return null;
		}
		$o = $ctx['entry'] ?: ( $ctx['page'] instanceof \WP_Post ? $ctx['page'] : null );
		$allowed = $o ? Compat::mb_fields_for( $o ) : [];
		if ( isset( $allowed[ $key ] ) ) {
			return self::meta_value( $key, (string) $allowed[ $key ]['type'], [], $o->ID );
		}
		// A field of the Meta Box settings page Business Info reads from works too, on any page (other pages only through the
		// launch_builder_meta_token_pages filter): its value is read from the page's option.
		$set = Compat::mb_setting_index()[ $key ] ?? null;
		if ( $set && in_array( (string) $set['field']['type'], [ 'checkbox', 'switch' ], true ) ) { // A tick box prints as yes or no.
			$on = rwmb_meta( $key, [ 'object_type' => $set['network'] ? 'network_setting' : 'setting' ], $set['option'] );
			return [ 'v' => $on && '0' !== $on ? __( 'Yes', 'launch-builder' ) : __( 'No', 'launch-builder' ), 'kind' => 'text' ];
		}
		return $set ? self::meta_value( $key, (string) $set['field']['type'], [ 'object_type' => $set['network'] ? 'network_setting' : 'setting' ], $set['option'] ) : null;
	}

	/** The value of one allowed Meta Box field as the token engine prints it ($args: rwmb_meta() arguments; $object_id: a post ID, or the option name of a settings page). */
	private static function meta_value( string $key, string $type, array $args, $object_id ): array {
		if ( 'wysiwyg' === $type ) { // The formatted text (paragraphs, shortcodes), not the stored source.
			$html = function_exists( 'rwmb_the_value' ) ? rwmb_the_value( $key, $args, $object_id, false ) : rwmb_meta( $key, $args, $object_id );
			return [ 'v' => self::kses_meta( is_scalar( $html ) ? (string) $html : '' ), 'kind' => 'html' ];
		}
		$v = rwmb_meta( $key, $args, $object_id );
		if ( in_array( $type, [ 'oembed', 'map', 'osm' ], true ) ) { // rwmb_meta() already returns the finished HTML for these.
			return [ 'v' => self::kses_meta( is_scalar( $v ) ? (string) $v : '', 'oembed' === $type ), 'kind' => 'html' ];
		}
		if ( in_array( $type, [ 'url', 'file_input', 'single_image', 'image_advanced', 'image', 'image_upload', 'file_advanced', 'file', 'file_upload', 'video' ], true ) ) {
			return [ 'v' => self::first_url( $v ), 'kind' => 'url' ];
		}
		if ( 'post' === $type ) {
			return [ 'v' => implode( ', ', self::post_titles( $v ) ), 'kind' => 'text' ];
		}
		if ( in_array( $type, [ 'taxonomy', 'taxonomy_advanced' ], true ) ) {
			return [ 'v' => implode( ', ', self::term_names( $v ) ), 'kind' => 'text' ];
		}
		if ( 'user' === $type ) { // A person's details are not site content: nothing prints.
			return [ 'v' => '', 'kind' => 'text' ];
		}
		if ( is_array( $v ) ) {
			$v = implode( ', ', array_filter( array_map( static function ( $x ) { return is_scalar( $x ) ? (string) $x : ''; }, $v ), 'strlen' ) );
		}
		return is_scalar( $v ) ? [ 'v' => (string) $v, 'kind' => 'textarea' === $type ? 'multi' : 'text' ] : [ 'v' => '', 'kind' => 'text' ];
	}

	/** HTML from a Meta Box field through kses: the post tags, plus an iframe for an embed. */
	private static function kses_meta( string $html, bool $embed = false ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		$allowed = wp_kses_allowed_html( 'post' );
		if ( $embed ) {
			$allowed['iframe'] = [ 'src' => true, 'width' => true, 'height' => true, 'title' => true, 'frameborder' => true, 'allowfullscreen' => true, 'allow' => true, 'loading' => true, 'referrerpolicy' => true, 'class' => true, 'style' => true ];
		}
		return wp_kses( $html, $allowed );
	}

	/** The address of a Meta Box file value: a string as it is, a picture's full size, else the first file's address. */
	private static function first_url( $v ): string {
		if ( is_string( $v ) ) {
			return trim( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( [ 'full_url', 'url', 'src' ] as $k ) {
				if ( isset( $v[ $k ] ) && is_string( $v[ $k ] ) && '' !== $v[ $k ] ) {
					return $v[ $k ];
				}
			}
			foreach ( $v as $item ) { // A gallery or file list: the first one that has an address.
				if ( is_array( $item ) || is_string( $item ) ) {
					$u = self::first_url( $item );
					if ( '' !== $u ) {
						return $u;
					}
				}
			}
		}
		return '';
	}

	/** Titles of the published, unprotected posts a Meta Box post field points at (ids, numeric strings, posts or a list of them). */
	private static function post_titles( $v ): array {
		$out = [];
		foreach ( is_array( $v ) ? $v : [ $v ] as $item ) {
			$id = $item instanceof \WP_Post ? $item->ID : ( is_scalar( $item ) && ctype_digit( (string) $item ) ? (int) $item : 0 );
			$p = $id ? get_post( $id ) : null;
			if ( $p && 'publish' === $p->post_status && '' === $p->post_password && is_post_type_viewable( $p->post_type ) ) {
				$out[] = wp_strip_all_tags( get_the_title( $p ) );
			}
		}
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/** Names of the terms a Meta Box taxonomy field holds (term objects, ids or a list of them), public taxonomies only. */
	private static function term_names( $v ): array {
		$out = [];
		foreach ( is_array( $v ) ? $v : [ $v ] as $item ) {
			$t = $item instanceof \WP_Term ? $item : ( is_scalar( $item ) && ctype_digit( (string) $item ) ? get_term( (int) $item ) : null );
			if ( $t instanceof \WP_Term ) {
				$tax = get_taxonomy( $t->taxonomy );
				if ( $tax && $tax->public ) {
					$out[] = $t->name;
				}
			}
		}
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/** One catalog row. */
	private static function row( string $ns, string $key, string $desc, array $ctx ): array {
		$r = self::lookup( $ns, $key, $ctx );
		$kind = $r ? $r['kind'] : 'text';
		if ( in_array( $kind, [ 'html', 'lines' ], true ) ) {
			$contexts = [ 'html' ];
		} else {
			$contexts = 'url' === $kind ? [ 'html', 'attr', 'url', 'text' ] : [ 'html', 'attr', 'text' ];
			if ( in_array( $ns, self::SUMMARY_NAMESPACES, true ) ) {
				$contexts[] = 'summary';
			}
		}
		$link = null;
		if ( 'lb' === $ns && 'url' === $kind && Company::is_link_key( $key ) ) {
			$link = '#lb-' . $key;
		} elseif ( in_array( '#' . $ns . '-' . $key, self::HASH_LINKS, true ) ) {
			$link = '#' . $ns . '-' . $key;
		}
		$value = $r ? self::format( $r, 'plain', null, $ns, $key ) : null;
		return [
			'token'       => '{{' . $ns . '.' . $key . '}}',
			'ns'          => $ns,
			'key'         => $key,
			'description' => $desc,
			'kind'        => $kind,
			'contexts'    => $contexts,
			'link_token'  => $link,
			'available'   => null !== $r,
			'value'       => null === $value ? null : mb_substr( $value, 0, 160 ),
		];
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function define_tools(): void {
		Abilities::add( 'get-tokens', 'List the dynamic text tokens', 'Every token text can use, with what it means, where it may be used (contexts: html = block text, attr = attribute, url = link target, text = SEO titles/descriptions, summary = inside summaries) and its current value. Syntax: {{ns.key}}, {{ns.key|fallback}}; Business Info links also as href="#lb-cta_url"; context links #entry-url, #page-url, #page-parent_url, #area-url, #service-url, #site-url. Namespaces: lb (Business Info), site, page (page being viewed), entry (each card of a query loop, else the current post), area, service, meta (Meta Box fields). post_id resolves the values for that page or entry; ns filters.',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer', 'description' => 'Resolve values for this post (page, service, area).' ], 'ns' => [ 'type' => 'string', 'enum' => array_keys( self::NAMESPACES ) ] ] ], [ __CLASS__, 'tokens_tool' ], 'edit_pages', [ 'readonly' => true ] );
	}

	public static function tokens_tool( $input = [] ) {
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( $id && ( ! get_post( $id ) || ! current_user_can( 'edit_post', $id ) ) ) {
			return new \WP_Error( 'lb_post', 'Unknown post, or you cannot edit it.', [ 'status' => 404 ] );
		}
		$rows = self::catalog( $id ?: null );
		$ns = sanitize_key( (string) ( $input['ns'] ?? '' ) );
		if ( '' !== $ns ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $ns ) { return $ns === $r['ns']; } ) );
		}
		return [
			'syntax'     => '{{ns.key}} or {{ns.key|fallback}} in text and attributes; %7B%7Bns.key%7D%7D inside encoded URLs; href="#lb-key" for link fields; empty or unknown tokens print the fallback, else nothing (editors see a dashed marker).',
			'hash_links' => self::HASH_LINKS,
			'summary_namespaces' => self::SUMMARY_NAMESPACES,
			'tokens'     => $rows,
		];
	}

	public static function playbook( array &$pb ): void {
		$pb['tokens'] = 'Dynamic text: {{lb.*}} Business Info (name, phone, phone_link, email, address, maps_url, cta_label, cta_url), {{site.name}}, {{page.title}}/{{page.summary}}/{{page.parent}} (page being viewed), {{entry.title}}/{{entry.summary}}/{{entry.url}}/{{entry.image}} (each card of a query loop, else the current post), {{area.name}} (the area; on an area service page its area), {{service.name}}/{{service.url}} (the service; on an area service page the service it is about). Fallbacks: {{lb.phone|Call us}}. Links: href="#lb-cta_url", "#lb-phone_link", "#lb-maps_url", "#entry-url", "#page-url", "#service-url". Summaries may only use lb, site, area and service tokens. Tokens resolve in blocks, titles, menus, widgets, image alt text and Rank Math/Yoast titles and descriptions. Demo values show only while the site is hidden from search engines. Before removing the plugin, detokenize writes every value into the content.';
		$pb['tools_by_job']['Dynamic text'] = 'get-tokens, get-company, set-company';
	}

	public static function audit_checks(): array {
		return [
			'token_unresolved' => [ 'content', [ __CLASS__, 'audit_unresolved' ], true ],
		];
	}

	/** Published content with tokens that resolve to nothing (unknown keys, namespaces not allowed in summaries, hash links that never resolve). */
	public static function audit_unresolved( array $args ): array {
		global $wpdb;
		$types = [ 'page', 'post', Entries::SERVICE, Entries::AREA, 'wp_block', 'kadence_element' ];
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- $in is a list of %s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( [ (int) ( $args['after_id'] ?? 0 ) ], $types, [ $limit ] ) ) );
		$findings = [];
		$last = 0;
		foreach ( (array) $rows as $row ) {
			$last = (int) $row->ID;
			$ctx = self::ctx_for( get_post( $last ) );
			$bad = [];
			if ( preg_match_all( '/' . self::TOKEN . '/', (string) $row->post_content, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $t ) {
					if ( 'meta' === strtolower( $t[1] ) && ! function_exists( 'rwmb_meta' ) ) { // Meta Box is off: the value is gone, only a fallback shows.
						if ( '|' !== ( $t[3] ?? '' ) ) {
							$bad[ $t[0] ] = 'Meta Box is not active';
						}
					} elseif ( null === self::lookup( $t[1], $t[2], $ctx ) ) {
						$bad[ $t[0] ] = 'unknown';
					}
				}
			}
			if ( preg_match_all( '/' . self::TOKEN . '/', (string) $row->post_excerpt, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $t ) {
					if ( ! in_array( strtolower( $t[1] ), self::SUMMARY_NAMESPACES, true ) || null === self::lookup( $t[1], $t[2], $ctx ) ) {
						$bad[ $t[0] ] = 'not allowed in a summary';
					}
				}
			}
			if ( preg_match_all( '/href=\\\\?["\']#lb-([a-z0-9_]+)\\\\?["\']/', (string) $row->post_content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $k ) {
					if ( ! Company::is_link_key( $k ) ) {
						$bad[ '#lb-' . $k ] = 'hash links work for link fields only (use {{lb.' . $k . '}} in text)';
					}
				}
			}
			// Anything shaped like a token that is not one (a mistyped namespace, a stray tag inside the braces) prints as it is.
			foreach ( [ 'the body' => (string) $row->post_content, 'the summary' => (string) $row->post_excerpt, 'the title' => (string) $row->post_title ] as $where => $text ) {
				if ( false === strpos( $text, '{{' ) ) {
					continue;
				}
				$rest = (string) preg_replace( '/' . self::TOKEN . '/', '', $text );
				if ( preg_match_all( '/\{\{\s*[A-Za-z_]+\.[^{}]{1,80}\}\}/', $rest, $m2 ) ) {
					foreach ( array_unique( $m2[0] ) as $raw ) {
						$bad[ $raw ] = 'not a token, so it prints as typed (' . $where . ')';
					}
				}
			}
			// The title and the SEO title and description go through the same tokens.
			foreach ( [ 'the title' => (string) $row->post_title, 'the SEO title' => (string) get_post_meta( $last, 'rank_math_title', true ) . ' ' . (string) get_post_meta( $last, '_yoast_wpseo_title', true ), 'the SEO description' => (string) get_post_meta( $last, 'rank_math_description', true ) . ' ' . (string) get_post_meta( $last, '_yoast_wpseo_metadesc', true ) ] as $where => $text ) {
				if ( false !== strpos( $text, '{{' ) && preg_match_all( '/' . self::TOKEN . '/', $text, $mt, PREG_SET_ORDER ) ) {
					foreach ( $mt as $t ) {
						if ( null === self::lookup( $t[1], $t[2], $ctx ) && '|' !== ( $t[3] ?? '' ) ) {
							$bad[ $t[0] ] = 'unknown (' . $where . ')';
						}
					}
				}
			}
			if ( $bad ) {
				$list = [];
				foreach ( $bad as $tok => $why ) {
					$list[] = $tok . ' (' . $why . ')';
				}
				$findings[] = [ 'severity' => 'warning', 'object_type' => $row->post_type, 'id' => $last, 'url' => 'publish' === get_post_status( $last ) ? (string) get_permalink( $last ) : '', 'message' => '"' . $row->post_title . '" has tokens that print nothing: ' . implode( ', ', array_slice( $list, 0, 10 ) ), 'fix' => 'Use a key listed by get-tokens, give a fallback {{ns.key|text}}, or turn Meta Box on for {{meta.*}} tokens.' ];
			}
		}
		return [ 'findings' => $findings, 'next_after_id' => count( (array) $rows ) === $limit ? $last : null ];
	}


}
