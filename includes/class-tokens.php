<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic text: one engine for {{ns.key}} tokens, their URL-encoded and #hash-link forms, in blocks, SEO strings and
 * summaries.
 *
 * replace() returns exactly what Company's render_block filter produces
 * today (only lb.* tokens resolve), push()/pop() keep a stack of the object being rendered (Entries::render_custom
 * already pushes each entry), context() reports that object, catalog() lists the token contract, seo_value() resolves
 * lb.* as plain text.
 */
final class Tokens {

	/**
	 * Namespaces and their keys (the contract). lb = Business Info (every Company field, plus computed ones); meta = Meta
	 * Box fields registered for the context object's type (resolved per site, so no fixed keys).
	 */
	const NAMESPACES = [
		'lb'      => [],
		'site'    => [ 'name', 'url', 'tagline' ],
		'page'    => [ 'title', 'url', 'summary', 'parent', 'parent_url', 'role', 'role_label' ],
		'entry'   => [ 'title', 'summary', 'url', 'image', 'icon', 'category', 'category_url', 'parent', 'parent_url', 'type' ],
		'area'    => [ 'name', 'parent', 'url', 'list' ],
		'service' => [ 'name', 'category', 'category_url' ],
		'meta'    => [],
	];

	/** Hash links that resolve (in href="..." with single or double quotes). Other # anchors are never touched. */
	const HASH_LINKS = [ '#lb-{key}', '#entry-url', '#page-url', '#page-parent_url', '#area-url', '#service-category_url', '#site-url' ];

	/** Namespaces allowed inside summaries (no page/entry context there). */
	const SUMMARY_NAMESPACES = [ 'lb', 'site', 'area', 'service' ];

	/** @var \WP_Post[] objects being rendered, innermost last. */
	private static $stack = [];

	/**
	 * Replace every token in $html. $context is how values are escaped: 'html' (default), 'attr', 'url' or 'text'
	 * (plain, for SEO strings). Currently identical to Company's render_block filter for every context.
	 */
	public static function replace( string $html, string $context = 'html' ): string {
		unset( $context );
		return (string) Company::filter_block( $html );
	}

	/**
	 * Is $u a link target that stays as written in stored content because tokens resolve it at render time (Markdown
	 * links in add-text/build-site keep it instead of escaping it to '#')? Currently {{lb.key}} and #lb-key.
	 */
	public static function is_url_token( string $u ): bool {
		return (bool) ( preg_match( '/^\{\{lb\.[a-z0-9_]+\}\}$/', $u ) || preg_match( '/^#lb-[a-z0-9_]+$/', $u ) );
	}

	/**
	 * Make $post the object tokens such as {{entry.title}} resolve against (until pop()). Currently it only records
	 * it.
	 *
	 * @param int|\WP_Post $post
	 */
	public static function push( $post ): void {
		$p = get_post( $post );
		self::$stack[] = $p instanceof \WP_Post ? $p : null;
	}

	/** Undo the last push(). @return \WP_Post|null the object that was on top. */
	public static function pop(): ?\WP_Post {
		return array_pop( self::$stack );
	}

	/** The object tokens resolve against: the stack top, else the loop's post (in the loop), else the queried post. */
	public static function context(): ?\WP_Post {
		if ( self::$stack ) {
			return end( self::$stack ) ?: null;
		}
		if ( in_the_loop() ) {
			$p = get_post();
			return $p instanceof \WP_Post ? $p : null;
		}
		$q = did_action( 'wp' ) ? get_queried_object() : null;
		return $q instanceof \WP_Post ? $q : null;
	}

	/**
	 * Every token with its description, where it may be used and (for lb.*) its current value. Namespaces other than lb,
	 * and the planned computed lb tokens (Company::COMPUTED_PLANNED), are listed as planned (available false) until the
	 * engine resolves them.
	 *
	 * @return array<int,array{token:string,ns:string,key:string,description:string,contexts:string[],available:bool,value:?string}>
	 */
	public static function catalog(): array {
		$rows = [];
		$values = Company::values();
		foreach ( Company::fields() as $k => $f ) {
			$rows[] = [ 'token' => '{{lb.' . $k . '}}', 'ns' => 'lb', 'key' => $k, 'description' => (string) $f[0], 'contexts' => [ 'html', 'attr', 'url', 'text', 'summary' ], 'available' => true, 'value' => (string) ( $values[ $k ] ?? '' ) ];
		}
		foreach ( Company::computed() as $k => $label ) {
			$rows[] = [ 'token' => '{{lb.' . $k . '}}', 'ns' => 'lb', 'key' => $k, 'description' => (string) $label, 'contexts' => [ 'html' ], 'available' => true, 'value' => null ];
		}
		foreach ( Company::COMPUTED_PLANNED as $k => $label ) {
			$rows[] = [ 'token' => '{{lb.' . $k . '}}', 'ns' => 'lb', 'key' => $k, 'description' => (string) $label, 'contexts' => [ 'html', 'text' ], 'available' => false, 'value' => null ];
		}
		foreach ( self::NAMESPACES as $ns => $keys ) {
			foreach ( 'lb' === $ns ? [] : $keys as $k ) {
				$rows[] = [ 'token' => '{{' . $ns . '.' . $k . '}}', 'ns' => $ns, 'key' => $k, 'description' => '', 'contexts' => in_array( $ns, self::SUMMARY_NAMESPACES, true ) ? [ 'html', 'attr', 'url', 'text', 'summary' ] : [ 'html', 'attr', 'url', 'text' ], 'available' => false, 'value' => null ];
			}
		}
		return $rows;
	}

	/**
	 * A token's plain-text value for SEO strings (titles, descriptions, Open Graph), resolved against an explicit object.
	 * $key is 'ns.key' (or a bare lb key). Currently lb.* only; anything else is ''.
	 *
	 * @param string             $key
	 * @param \WP_Post|\WP_Term|null $obj
	 */
	public static function seo_value( string $key, $obj = null ): string {
		unset( $obj );
		$key = 0 === strpos( $key, 'lb.' ) ? substr( $key, 3 ) : $key;
		if ( false !== strpos( $key, '.' ) ) {
			return '';
		}
		$v = Company::values();
		return isset( $v[ $key ] ) ? trim( wp_strip_all_tags( (string) $v[ $key ] ) ) : '';
	}
}
