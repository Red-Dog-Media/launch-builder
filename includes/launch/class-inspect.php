<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page inspection: load a page of this site the way an anonymous visitor gets it (loopback GET) and report what it
 * printed. Read-only; only URLs of this site are fetched.
 *
 * Contracts other modules print for it:
 *  - Role: <body> carries the class lb-role-{role} (Roles::current()), added here on body_class unless a class starting
 *    with lb-role- is already there (filter launch_builder_role_body_class returns false to turn it off).
 *  - Design slots: whatever prints a shared design may mark it so a page can say which design printed where:
 *      <div data-lb-slot="hero" data-lb-key="design-hero-service" data-lb-source="element" data-lb-id="123">...</div>
 *    data-lb-slot (required) = header | hero | crumbs | list | before_footer | footer; data-lb-key = the design key
 *    (_lb_key); data-lb-source = element | synced | pattern | theme; data-lb-id = the element post id. Without marks,
 *    Kadence Pro's own <!-- [element-ID] --> comments are read and mapped through the element's _lb_slot / _lb_key meta
 *    (the Design hub writes both; else its hook), and the theme's own title banner (section.entry-hero), header
 *    (#masthead) and footer (#colophon) are recognised. Wrappers of sites built before v2 (.lb-chrome--{slot},
 *    .lb-global--{area}) are still read. A mark naming an element id replaces that element's comment.
 *
 * Tools: inspect-page { url | post_id | role, check_links, max_links } and preview-urls { roles[], designs }.
 */
final class Inspect {

	const UA = 'LaunchBuilder-Inspect/1.0';

	/** Kadence Pro element hook => design slot (for elements without _lb_slot meta). */
	const HOOK_SLOTS = [ 'replace_header' => 'header', 'kadence_before_header' => 'notice', 'replace_hero_header' => 'hero', 'kadence_hero_header' => 'crumbs', 'replace_single_content' => 'template', 'replace_404' => 'notfound_body', 'kadence_before_footer' => 'before_footer', 'replace_footer' => 'footer', 'fixed_on_footer' => 'sticky_bar' ];

	/** Legacy global areas (Globals) => chrome slot. */
	const GLOBAL_SLOTS = [ 'above_header' => 'notice', 'below_header' => 'below_header', 'above_footer' => 'before_footer', 'footer' => 'footer' ];

	/** Third-party form embeds recognised by their iframe/script source. */
	const FORM_HOSTS = '#leadconnectorhq\.com|msgsndr\.com|form_embed|typeform\.com|jotform|docs\.google\.com/forms|forms\.office\.com|hubspot|calendly\.com|gohighlevel|housecallpro|servicetitan|jobber#i';

	public static function boot(): void {
		add_filter( 'body_class', [ __CLASS__, 'body_class' ], 20 );
	}

	/** Contract: the front end's body carries lb-role-{role}. */
	public static function body_class( $classes ) {
		$classes = is_array( $classes ) ? $classes : [];
		if ( is_admin() || ! apply_filters( 'launch_builder_role_body_class', true ) ) {
			return $classes;
		}
		foreach ( $classes as $c ) {
			if ( 0 === strpos( (string) $c, 'lb-role-' ) ) {
				return $classes; // Already printed (by a theme or another plugin).
			}
		}
		$role = Roles::current();
		if ( '' !== $role ) {
			$classes[] = 'lb-role-' . sanitize_html_class( $role );
		}
		return $classes;
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		Abilities::add( 'inspect-page', 'Inspect a page as a visitor sees it', 'Loads one page of this site anonymously and reports what it printed: role, which shared design each chrome slot printed (element/synced ids and keys), breadcrumb trail, title/description/canonical/robots as output, JSON-LD types (and duplicate BreadcrumbLists), H1 count and H2 outline, unresolved tokens, [[placeholders]] and demo text, dead # / broken internal / tel: / mailto: links, images without alt or still placeholders, and forms. Give url (this site only), post_id (published) or role (a sample page of that role). Read-only.',
			[ 'properties' => [
				'url'         => [ 'type' => 'string', 'description' => 'A URL or path on this site.' ],
				'post_id'     => [ 'type' => 'integer' ],
				'role'        => [ 'type' => 'string', 'enum' => array_keys( Roles::ROLES ) ],
				'check_links' => [ 'type' => 'boolean', 'description' => 'HEAD-check internal links (default false: on a slow host it can take most of the time; pass true when you want it, it stops after about 25 seconds and says how many links it left unchecked).' ],
				'max_links'   => [ 'type' => 'integer', 'description' => 'Internal links to check, at most 50 (default 20).' ],
			] ], [ __CLASS__, 'page_tool' ], 'edit_pages', $ro );

		Abilities::add( 'preview-urls', 'URLs to screenshot', 'One URL per page role (a sample page of each role) and one per design piece the Design hub placed (a page that shows it), for screenshots at 1440 and 390 px wide. Roles without a page yet are listed with url null.',
			[ 'properties' => [
				'roles'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Only these roles (default all).' ],
				'designs' => [ 'type' => 'boolean', 'description' => 'Also list one URL per chrome design (default true).' ],
			] ], [ __CLASS__, 'preview_urls' ], 'edit_pages', $ro );
	}

	/** inspect-page callback. */
	public static function page_tool( $input ) {
		$input = (array) $input;
		$t = self::target( $input );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$opts = [
			'check_links' => ! empty( $input['check_links'] ),
			'max_links'   => max( 0, min( 50, (int) ( $input['max_links'] ?? 20 ) ) ),
		];
		return self::inspect( $t['url'], $opts + [ 'post_id' => $t['post_id'], 'role' => $t['role'] ] );
	}

	/**
	 * Resolve { url | post_id | role } to a URL of this site.
	 *
	 * @return array{url:string,post_id:?int,role:?string}|\WP_Error
	 */
	public static function target( array $in ) {
		if ( ! empty( $in['url'] ) ) {
			$url = self::own_url( (string) $in['url'] );
			if ( ! $url ) {
				return new \WP_Error( 'lb_bad_url', 'Only URLs of this site can be inspected (same scheme, host and port as the home URL, or a path).', [ 'status' => 400 ] );
			}
			return [ 'url' => $url, 'post_id' => null, 'role' => null ];
		}
		if ( ! empty( $in['post_id'] ) ) {
			$id = (int) $in['post_id'];
			$p = get_post( $id );
			if ( ! $p || 'publish' !== $p->post_status || ! is_post_publicly_viewable( $p ) ) {
				return new \WP_Error( 'lb_not_public', 'That post is not published (a visitor cannot see it). Inspect published posts only.', [ 'status' => 400 ] );
			}
			return [ 'url' => (string) get_permalink( $p ), 'post_id' => $id, 'role' => null ];
		}
		if ( ! empty( $in['role'] ) ) {
			$role = sanitize_key( (string) $in['role'] );
			if ( ! isset( Roles::ROLES[ $role ] ) ) {
				return new \WP_Error( 'lb_bad_role', 'Unknown role. One of: ' . implode( ', ', array_keys( Roles::ROLES ) ), [ 'status' => 400 ] );
			}
			$r = self::role_url( $role );
			if ( ! $r ) {
				return new \WP_Error( 'lb_no_sample', 'No published page has the role ' . $role . ' yet.', [ 'status' => 404 ] );
			}
			return [ 'url' => $r['url'], 'post_id' => $r['post_id'], 'role' => $role ];
		}
		return new \WP_Error( 'lb_invalid_args', 'Give url, post_id or role.', [ 'status' => 400 ] );
	}

	/** A URL (or path) of this site, normalised; '' for anything else. */
	public static function own_url( string $u ): string {
		$u = trim( $u );
		if ( '' === $u || preg_match( '/[\s<>"\\\\]/', $u ) ) {
			return '';
		}
		if ( '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
			$home = wp_parse_url( home_url() );
			$base = rtrim( (string) ( $home['path'] ?? '' ), '/' );
			$u = home_url( 0 === strpos( $u, $base . '/' ) && '' !== $base ? substr( $u, strlen( $base ) ) : $u );
		}
		$a = wp_parse_url( $u );
		$h = wp_parse_url( home_url() );
		if ( ! $a || empty( $a['host'] ) || ! in_array( strtolower( (string) ( $a['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}
		$port = static function ( $p ) { return (int) ( $p['port'] ?? ( 'https' === strtolower( (string) ( $p['scheme'] ?? '' ) ) ? 443 : 80 ) ); };
		if ( strtolower( $a['host'] ) !== strtolower( (string) ( $h['host'] ?? '' ) ) || $port( $a ) !== $port( $h ) ) {
			return '';
		}
		return (string) preg_replace( '/#.*$/', '', $u );
	}

	/**
	 * A URL that shows a role: its sample object (Roles::sample) or, for roles without one, the matching route.
	 *
	 * @return array{url:string,post_id:?int}|null
	 */
	public static function role_url( string $role ): ?array {
		switch ( $role ) {
			case 'home':
				return [ 'url' => home_url( '/' ), 'post_id' => 'page' === get_option( 'show_on_front' ) ? ( (int) get_option( 'page_on_front' ) ?: null ) : null ];
			case 'search':
				return [ 'url' => add_query_arg( 's', 'lb-inspect', home_url( '/' ) ), 'post_id' => null ];
			case 'notfound':
				return [ 'url' => home_url( '/lb-inspect-not-found-' . strtolower( wp_generate_password( 8, false ) ) . '/' ), 'post_id' => null ];
			case 'other':
				return null;
		}
		$p = Roles::sample( $role );
		if ( $p ) {
			return [ 'url' => (string) get_permalink( $p ), 'post_id' => (int) $p->ID ];
		}
		if ( 'blog' === $role ) {
			if ( 'posts' === get_option( 'show_on_front' ) ) {
				return null; // The posts page is the front page: that is the home role.
			}
			$cats = get_categories( [ 'hide_empty' => true, 'number' => 1 ] );
			return $cats ? [ 'url' => (string) get_category_link( $cats[0] ), 'post_id' => null ] : null;
		}
		return null;
	}

	/** preview-urls callback. */
	public static function preview_urls( $input = [] ): array {
		$input = (array) $input;
		$want = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $input['roles'] ?? [] ) ) ) ) );
		$roles = [];
		foreach ( array_keys( Roles::ROLES ) as $role ) {
			if ( 'other' === $role || ( $want && ! in_array( $role, $want, true ) ) ) {
				continue;
			}
			$r = self::role_url( $role );
			$roles[] = [ 'role' => $role, 'label' => Roles::label( $role ), 'url' => $r['url'] ?? null, 'post_id' => $r['post_id'] ?? null, 'title' => ! empty( $r['post_id'] ) ? get_the_title( (int) $r['post_id'] ) : null ];
		}
		$out = [ 'roles' => $roles, 'viewports' => [ [ 'name' => 'desktop', 'width' => 1440 ], [ 'name' => 'mobile', 'width' => 390, 'height' => 844, 'device_scale_factor' => 2, 'mobile' => true ] ] ];
		if ( ! array_key_exists( 'designs', $input ) || ! empty( $input['designs'] ) ) {
			$designs = [];
			foreach ( Design_Hub::placements() as $piece => $pl ) {
				$role = (string) ( $pl['roles'][0] ?? '' );
				$r = '' !== $role ? self::role_url( $role ) : null;
				$designs[] = [ 'piece' => $piece, 'pattern' => $pl['pattern'], 'id' => $pl['id'], 'roles' => $pl['roles'], 'url' => $r['url'] ?? null, 'shown_on' => $r ? $role : null ];
			}
			$out['designs'] = $designs;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ fetch + parse */

	/**
	 * Anonymous GET of a URL of this site (no login cookies; redirects followed by hand, same site only).
	 *
	 * @return array{status:int,url:string,redirects:array,html:string,ms:int,bytes:int}|\WP_Error
	 */
	public static function fetch( string $url, float $timeout = 20.0 ) {
		$t0 = microtime( true );
		$redirects = [];
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$r = wp_remote_get( $url, self::http_args( $timeout ) );
			if ( is_wp_error( $r ) ) {
				return new \WP_Error( 'lb_fetch_failed', 'The site could not load its own page (' . $r->get_error_message() . '). Loopback requests may be blocked by the host or a firewall.', [ 'status' => 502 ] );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$loc = (string) wp_remote_retrieve_header( $r, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $loc ) {
				$next = self::own_url( \WP_Http::make_absolute_url( $loc, $url ) );
				$redirects[] = [ 'status' => $code, 'to' => $next ? $next : $loc ];
				if ( ! $next ) {
					break; // Off-site: report it, do not follow.
				}
				$url = $next;
				continue;
			}
			$html = (string) wp_remote_retrieve_body( $r );
			return [ 'status' => $code, 'url' => $url, 'redirects' => $redirects, 'html' => $html, 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'bytes' => strlen( $html ) ];
		}
		return [ 'status' => isset( $code ) ? $code : 0, 'url' => $url, 'redirects' => $redirects, 'html' => '', 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'bytes' => 0 ];
	}

	/**
	 * Request arguments: anonymous (no cookies of the current user), no cache, local SSL as core loopbacks do. The
	 * playground_auto_login_already_happened cookie stops WordPress Playground's dev auto-login (harmless elsewhere).
	 */
	private static function http_args( float $timeout, string $method = 'GET' ): array {
		return [
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'          => self::UA,
			'headers'             => [ 'Cache-Control' => 'no-cache', 'Accept' => 'text/html' ],
			'cookies'             => [ 'playground_auto_login_already_happened' => '1' ],
			'limit_response_size' => 4194304,
		];
	}

	/** @var \DOMXPath|null the document parse() is reading. */
	private static $xp = null;

	/** Nodes matching an XPath expression in the document being parsed. @return \DOMNode[] */
	private static function q( string $expr, $ctx = null ): array {
		$r = self::$xp ? ( $ctx ? self::$xp->query( $expr, $ctx ) : self::$xp->query( $expr ) ) : false;
		return $r ? iterator_to_array( $r ) : [];
	}

	private static function cnt( string $expr, $ctx = null ): int {
		return count( self::q( $expr, $ctx ) );
	}

	/** Trimmed value of the first node (attribute) matching. */
	private static function attr( string $expr ): string {
		foreach ( self::q( $expr ) as $n ) {
			return trim( (string) $n->nodeValue );
		}
		return '';
	}

	/** Visible text of a node, whitespace collapsed. */
	private static function text( $node, int $max = 160 ): string {
		$t = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $node ? $node->textContent : '' ) ) );
		return mb_strlen( $t ) > $max ? mb_substr( $t, 0, $max - 1 ) . '…' : $t;
	}

	/** XPath test for a class name. */
	private static function cls( string $c ): string {
		return "contains(concat(' ', normalize-space(@class), ' '), ' " . $c . " ')";
	}

	/**
	 * Inspect a URL of this site.
	 *
	 * @param array $opts check_links (bool), max_links (int), post_id/role (what was asked for, reported back).
	 * @return array|\WP_Error
	 */
	public static function inspect( string $url, array $opts = [] ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return new \WP_Error( 'lb_no_dom', 'inspect-page needs the PHP DOM extension, which this server does not have.', [ 'status' => 501 ] );
		}
		$f = self::fetch( $url );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$out = [ 'url' => $url, 'final_url' => $f['url'], 'status' => $f['status'], 'redirects' => $f['redirects'], 'ms' => $f['ms'], 'bytes' => $f['bytes'] ];
		if ( isset( $opts['role'] ) && $opts['role'] ) {
			$out['asked_role'] = $opts['role'];
		}
		if ( '' === $f['html'] ) {
			$out['issues'] = [ [ 'severity' => 'error', 'code' => 'no_html', 'message' => 'The page returned no HTML (status ' . $f['status'] . ').' ] ];
			return $out;
		}
		return $out + self::parse( $f['html'], $f['url'], $opts + [ 'status' => $f['status'] ] );
	}

	/** Everything inspect-page reports about one HTML document. */
	public static function parse( string $html, string $url, array $opts = [] ): array {
		$prev = libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		self::$xp = new \DOMXPath( $dom );
		$out = [];

		// Role (body class contract), with the post-id fallback for themes that do not print body_class.
		$body_class = self::attr( '//body/@class' );
		$role = preg_match( '/(?:^|\s)lb-role-([a-z0-9_-]+)/', $body_class, $m ) ? $m[1] : '';
		if ( '' === $role && ! empty( $opts['post_id'] ) ) {
			$role = Roles::of( (int) $opts['post_id'] );
		}
		$out['role'] = $role;

		// SEO as output.
		$out['seo'] = [
			'title'       => self::text( self::q( '//title' )[0] ?? null, 300 ),
			'description' => self::attr( "//meta[translate(@name,'DESCRIPTON','descripton')='description']/@content" ),
			'canonical'   => self::attr( "//link[translate(@rel,'CANONIL','canonil')='canonical']/@href" ),
			'robots'      => self::attr( "//meta[translate(@name,'ROBTS','robts')='robots']/@content" ),
			'og_title'    => self::attr( "//meta[@property='og:title']/@content" ),
		];

		// JSON-LD types, BreadcrumbLists (JSON-LD and microdata).
		$types = [];
		$crumb_lists = 0;
		$ld_text = '';
		$ld_errors = 0;
		$walk = static function ( $v ) use ( &$walk, &$types, &$crumb_lists ) {
			if ( ! is_array( $v ) ) {
				return;
			}
			if ( isset( $v['@type'] ) ) {
				foreach ( (array) $v['@type'] as $t ) {
					$t = (string) $t;
					$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
					if ( 'BreadcrumbList' === $t ) {
						$crumb_lists++;
					}
				}
			}
			foreach ( $v as $k => $child ) {
				if ( is_array( $child ) && '@context' !== $k ) {
					$walk( $child );
				}
			}
		};
		foreach ( self::q( "//script[translate(@type,'APLICTONJSD+','aplictonjsd+')='application/ld+json']" ) as $s ) {
			$raw = (string) $s->textContent;
			$ld_text .= $raw;
			$j = json_decode( $raw, true );
			null === $j ? $ld_errors++ : $walk( $j );
		}
		$micro = 0;
		foreach ( self::q( '//*[@itemtype]' ) as $n ) {
			if ( false !== stripos( (string) $n->getAttribute( 'itemtype' ), 'BreadcrumbList' ) ) {
				$micro++;
			}
		}
		$out['schema'] = [ 'types' => $types, 'breadcrumb_lists' => $crumb_lists + $micro, 'breadcrumb_microdata' => $micro, 'invalid_json_ld' => $ld_errors ];

		// Headings.
		$h1 = [];
		foreach ( self::q( '//h1' ) as $n ) {
			$h1[] = self::text( $n, 120 );
		}
		$h2 = [];
		foreach ( self::q( '//h2' ) as $n ) {
			if ( count( $h2 ) < 40 ) {
				$h2[] = self::text( $n, 100 );
			}
		}
		$out['headings'] = [ 'h1_count' => count( $h1 ), 'h1' => array_slice( $h1, 0, 5 ), 'h2' => $h2, 'h2_count' => self::cnt( '//h2' ) ];

		// Breadcrumb trails as printed.
		$crumbs = [];
		$sources = [ 'lb' => '//nav[' . self::cls( 'lb-crumbs' ) . ']', 'rankmath' => '//*[' . self::cls( 'rank-math-breadcrumb' ) . ']', 'kadence' => '//*[' . self::cls( 'kadence-breadcrumbs' ) . ' and not(ancestor::*[' . self::cls( 'kadence-breadcrumbs' ) . '])]', 'yoast' => '//*[@id="breadcrumbs" or ' . self::cls( 'yoast-breadcrumbs' ) . ']' ];
		foreach ( $sources as $src => $expr ) {
			foreach ( self::q( $expr ) as $nav ) {
				if ( 'kadence' === $src && false !== strpos( (string) $nav->getAttribute( 'class' ), 'rankmath-bc-wrap' ) ) {
					continue; // Kadence's wrapper around Rank Math's crumbs: counted as rankmath.
				}
				$items = [];
				foreach ( self::q( './/a | .//li[not(.//a)] | .//span[@aria-current] | .//*[' . self::cls( 'last' ) . ']', $nav ) as $it ) {
					$title = self::text( $it, 80 );
					if ( '' === $title && 'a' === $it->nodeName && false !== strpos( (string) $it->getAttribute( 'class' ), 'home' ) ) {
						$title = (string) ( $it->getAttribute( 'aria-label' ) ?: 'Home' );
					}
					if ( '' !== $title ) {
						$items[] = [ 'title' => $title, 'url' => 'a' === $it->nodeName ? (string) $it->getAttribute( 'href' ) : '' ];
					}
				}
				$crumbs[] = [ 'source' => $src, 'items' => $items ];
			}
		}
		$out['crumbs'] = $crumbs;

		// Designs: which design printed in each slot.
		$out['chrome'] = self::chrome( $html );

		// Where to look for tokens and demo copy: the HTML without scripts and styles (attributes such as href count),
		// plus the JSON-LD. [[placeholders]] only in visible text and alt/title (data attributes may hold JSON arrays).
		$plain = (string) preg_replace( '#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$hay = $plain . "\n" . $ld_text;
		$visible = [];
		foreach ( self::q( '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::template)] | //img/@alt | //*[@title]/@title' ) as $n ) {
			$visible[] = (string) $n->nodeValue;
		}
		$visible = implode( "\n", $visible );
		preg_match_all( '/\{\{\s*[a-z0-9_]+(?:\.[a-z0-9_]+)?\s*(?:\|[^{}]{0,60})?\}\}|%7B%7B[a-z0-9_.]{1,60}(?:%7C[^%]{0,60})?%7D%7D/i', $hay, $tm );
		preg_match_all( '/\[\[[^\[\]\n]{1,120}\]\]/', $visible, $pm );
		$demo = [];
		foreach ( Audit::demo_strings() as $d ) {
			if ( false !== stripos( $hay, $d ) ) {
				$demo[] = $d;
			}
		}
		$out['text'] = [ 'tokens' => array_slice( array_values( array_unique( $tm[0] ) ), 0, 20 ), 'placeholders' => array_slice( array_values( array_unique( $pm[0] ) ), 0, 20 ), 'demo' => $demo ];
		$phone = trim( (string) ( Company::values()['phone'] ?? '' ) );

		// Links.
		$dead = [];
		$tel = [];
		$mail = [];
		$internal = [];
		$external = 0;
		$total = 0;
		$page = preg_replace( '/#.*$/', '', $url );
		foreach ( self::q( '//a' ) as $a ) {
			$total++;
			$href = trim( (string) $a->getAttribute( 'href' ) );
			$label = self::text( $a, 60 );
			if ( '' === $label ) {
				$label = (string) ( $a->getAttribute( 'aria-label' ) ?: $a->getAttribute( 'title' ) );
			}
			if ( '' === $href || '#' === $href || 0 === stripos( $href, 'javascript:' ) ) {
				$toggle = $a->hasAttribute( 'aria-controls' ) || $a->hasAttribute( 'aria-expanded' ) || $a->hasAttribute( 'data-toggle-target' ) || 'button' === $a->getAttribute( 'role' );
				if ( ! $toggle && ! $a->hasAttribute( 'name' ) ) {
					$in_nav = (bool) self::cnt( 'ancestor::nav | ancestor::*[' . self::cls( 'wp-block-kadence-navigation' ) . ']', $a );
					$dead[] = [ 'text' => $label, 'href' => $href, 'in_menu' => $in_nav ];
				}
				continue;
			}
			if ( 0 === stripos( $href, 'tel:' ) ) {
				$digits = preg_replace( '/\D+/', '', substr( $href, 4 ) );
				$tel[ $href ] = [ 'href' => $href, 'valid' => strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ];
				continue;
			}
			if ( 0 === stripos( $href, 'mailto:' ) ) {
				$mail[ $href ] = $href;
				continue;
			}
			if ( '#' === $href[0] ) {
				continue; // In-page anchor.
			}
			$abs = \WP_Http::make_absolute_url( $href, $url );
			$own = self::own_url( $abs );
			if ( $own ) {
				$own = (string) preg_replace( '/#.*$/', '', $own );
				if ( $own !== $page && ! preg_match( '#/wp-(admin|login\.php|json)|/feed/?$|[?&](add-to-cart|replytocom)=#', $own ) && ! isset( $internal[ $own ] ) ) {
					// Links in the page's own content are checked first (header and footer links repeat on every page).
					$in_content = (bool) self::cnt( 'ancestor::main | ancestor::*[@id="primary"] | ancestor::*[' . self::cls( 'entry-content' ) . ']', $a ) && ! self::cnt( 'ancestor::header | ancestor::footer | ancestor::nav', $a );
					$internal[ $own ] = [ $label, $in_content ];
				}
			} elseif ( preg_match( '#^https?://#i', $abs ) ) {
				$external++;
			}
		}
		$out['links'] = [ 'total' => $total, 'internal' => count( $internal ), 'external' => $external, 'dead' => array_slice( $dead, 0, 20 ), 'dead_count' => count( $dead ), 'tel' => array_values( $tel ), 'mailto' => array_values( $mail ), 'phone_printed' => '' !== $phone && false !== strpos( $visible, $phone ) ];
		if ( ! empty( $opts['check_links'] ) && $internal ) {
			$first = array_filter( $internal, static function ( $v ) { return $v[1]; } );
			$ordered = array_map( static function ( $v ) { return $v[0]; }, $first + array_diff_key( $internal, $first ) );
			$out['links']['checked'] = self::check_links( $ordered, (int) ( $opts['max_links'] ?? 20 ) );
		}

		// Images.
		$imgs = 0;
		$no_alt = [];
		$empty_alt = 0;
		$ph = [];
		foreach ( self::q( '//img' ) as $img ) {
			$imgs++;
			$src = (string) ( $img->getAttribute( 'src' ) ?: $img->getAttribute( 'data-src' ) );
			if ( ! $img->hasAttribute( 'alt' ) ) {
				$no_alt[] = $src;
			} elseif ( '' === trim( (string) $img->getAttribute( 'alt' ) ) ) {
				$empty_alt++;
			}
			if ( false !== strpos( $src, 'assets/placeholders/' ) ) {
				$ph[] = $src;
			}
		}
		$out['images'] = [ 'total' => $imgs, 'missing_alt' => count( $no_alt ), 'missing_alt_src' => array_slice( array_values( array_unique( $no_alt ) ), 0, 10 ), 'empty_alt' => $empty_alt, 'placeholders' => count( $ph ), 'placeholder_refs' => substr_count( $html, 'assets/placeholders/' ) ];

		// Forms.
		$forms = 0;
		foreach ( self::q( '//form' ) as $form ) {
			$role_attr = strtolower( (string) $form->getAttribute( 'role' ) );
			$cls = (string) $form->getAttribute( 'class' );
			if ( 'search' !== $role_attr && false === strpos( $cls, 'search-form' ) && ! self::cnt( './/input[@type="search" or @name="s"]', $form ) ) {
				$forms++;
			}
		}
		$embeds = 0;
		foreach ( self::q( '//iframe[@src] | //iframe[@data-src] | //script[@src]' ) as $n ) {
			if ( preg_match( self::FORM_HOSTS, (string) ( $n->getAttribute( 'src' ) ?: $n->getAttribute( 'data-src' ) ) ) ) {
				$embeds++;
			}
		}
		$out['forms'] = [ 'forms' => $forms, 'embeds' => $embeds, 'present' => ( $forms + $embeds ) > 0 ];

		$out['issues'] = self::issues( $out, $opts + [ 'url' => $url ] );
		return $out;
	}

	/**
	 * Design slots printed: data-lb-slot marks, Kadence element comments (unless a mark already names that id), the
	 * lb-chrome--{slot} wrappers of the patterns-mode printer, legacy global areas, theme parts.
	 */
	private static function chrome( string $html ): array {
		$slots = [];
		$marked = [];
		$add = static function ( string $slot, array $row ) use ( &$slots ) {
			$slots[ $slot ][] = $row;
		};
		foreach ( self::q( '//*[@data-lb-slot]' ) as $n ) {
			$id = (int) $n->getAttribute( 'data-lb-id' );
			if ( $id ) {
				$marked[ $id ] = true;
			}
			$add( sanitize_key( (string) $n->getAttribute( 'data-lb-slot' ) ), array_filter( [
				'source' => sanitize_key( (string) $n->getAttribute( 'data-lb-source' ) ) ?: 'mark',
				'id'     => $id ?: null,
				'key'    => sanitize_text_field( (string) $n->getAttribute( 'data-lb-key' ) ) ?: null,
				'nested' => (bool) self::cnt( 'ancestor::*[@data-lb-slot]', $n ) ?: null,
			] ) );
		}
		foreach ( self::q( "//*[contains(@class, 'lb-chrome--') and not(@data-lb-slot)]" ) as $n ) {
			if ( preg_match( '/(?:^|\s)lb-chrome--([a-z_]+)/', (string) $n->getAttribute( 'class' ), $cm ) ) {
				$add( $cm[1], array_filter( [ 'source' => 'lb', 'nested' => (bool) self::cnt( 'ancestor::*[@data-lb-slot or contains(@class, "lb-chrome--")]', $n ) ?: null ] ) );
			}
		}
		if ( preg_match_all( '/<!-- \[element-(\d+)\] -->/', $html, $m ) ) {
			foreach ( array_diff( array_unique( array_map( 'intval', $m[1] ) ), array_keys( $marked ) ) as $id ) {
				$slot = sanitize_key( (string) get_post_meta( $id, '_lb_slot', true ) );
				$hook = (string) get_post_meta( $id, '_kad_element_hook', true );
				if ( '' === $slot ) {
					$hook_key = 'custom' === $hook ? (string) get_post_meta( $id, '_kad_element_hook_custom', true ) : $hook;
					$slot = self::HOOK_SLOTS[ $hook_key ] ?? ( '' !== $hook_key ? 'hook:' . sanitize_key( $hook_key ) : 'element' );
				}
				$key = (string) get_post_meta( $id, '_lb_key', true );
				$add( $slot, array_filter( [ 'source' => 'element', 'id' => $id, 'key' => '' !== $key ? $key : null, 'title' => get_the_title( $id ) ?: null, 'lb' => '' !== $key ] ) );
			}
		}
		foreach ( self::q( "//*[contains(@class, 'lb-global--')]" ) as $n ) {
			if ( preg_match( '/lb-global--([a-z_]+)/', (string) $n->getAttribute( 'class' ), $gm ) ) {
				$add( self::GLOBAL_SLOTS[ $gm[1] ] ?? $gm[1], [ 'source' => 'global', 'area' => $gm[1] ] );
			}
		}
		if ( self::cnt( '//section[' . self::cls( 'entry-hero' ) . ']' ) ) {
			$add( 'hero', [ 'source' => 'theme', 'what' => 'Kadence title banner' ] );
		}
		if ( self::cnt( '//header[@id="masthead"]' ) ) {
			$add( 'header', [ 'source' => 'theme', 'what' => self::cnt( '//header[@id="masthead"]//*[' . self::cls( 'wp-block-kadence-header' ) . ']' ) ? 'Kadence block header' : 'Kadence header' ] );
		}
		if ( self::cnt( '//footer[@id="colophon"]' ) ) {
			$hidden = (bool) preg_match( '/#colophon\s*\{\s*display\s*:\s*none/i', $html );
			$add( 'footer', [ 'source' => 'theme', 'what' => 'Kadence footer' . ( $hidden ? ' (hidden by CSS)' : '' ) ] );
		}
		return $slots;
	}

	/**
	 * HEAD-check internal links (GET when HEAD is refused), within a time budget.
	 *
	 * @param array<string,string> $links url => link text
	 */
	private static function check_links( array $links, int $max ): array {
		$broken = [];
		$ok = 0;
		$t0 = microtime( true );
		$checked = 0;
		foreach ( $links as $u => $label ) {
			if ( $checked >= $max || microtime( true ) - $t0 > 25 ) {
				break;
			}
			$checked++;
			$r = wp_remote_request( $u, [ 'redirection' => 3 ] + self::http_args( 8.0, 'HEAD' ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( in_array( $code, [ 405, 501 ], true ) ) {
				$r = wp_remote_request( $u, [ 'redirection' => 3, 'limit_response_size' => 65536 ] + self::http_args( 8.0 ) );
				$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			}
			if ( $code >= 200 && $code < 400 ) {
				$ok++;
			} else {
				$broken[] = [ 'url' => $u, 'text' => $label, 'status' => $code, 'error' => is_wp_error( $r ) ? $r->get_error_message() : null ];
			}
		}
		return [ 'checked' => $checked, 'ok' => $ok, 'broken' => $broken, 'unchecked' => max( 0, count( $links ) - $checked ) ];
	}

	/** Findings in the audit format, plus the per-role definition-of-done checks they come from. */
	private static function issues( array $o, array $opts ): array {
		$is = [];
		$add = static function ( string $sev, string $code, string $msg, $detail = null ) use ( &$is ) {
			$is[] = array_filter( [ 'severity' => $sev, 'code' => $code, 'message' => $msg, 'detail' => $detail ], static function ( $v ) { return null !== $v; } );
		};
		$role = (string) ( $o['role'] ?? '' );
		$status = (int) ( $opts['status'] ?? 200 );
		if ( 'notfound' !== $role && 200 !== $status ) {
			$add( 'error', 'http_status', 'The page answered HTTP ' . $status . '.' );
		}
		$h1 = (int) $o['headings']['h1_count'];
		if ( 1 !== $h1 ) {
			$add( 'error', 'one_h1', 0 === $h1 ? 'No H1 on the page.' : $h1 . ' H1 headings on the page (one expected).', $o['headings']['h1'] );
		}
		if ( $o['schema']['breadcrumb_lists'] > 1 ) {
			$add( 'error', 'duplicate_breadcrumblist', $o['schema']['breadcrumb_lists'] . ' BreadcrumbLists (JSON-LD plus microdata); exactly one is expected.' );
		}
		if ( $o['schema']['invalid_json_ld'] ) {
			$add( 'error', 'invalid_json_ld', $o['schema']['invalid_json_ld'] . ' JSON-LD block(s) are not valid JSON.' );
		}
		if ( $o['text']['tokens'] ) {
			$add( 'error', 'token_unresolved', 'Unresolved tokens are printed: ' . implode( ', ', $o['text']['tokens'] ), $o['text']['tokens'] );
		}
		if ( $o['text']['placeholders'] ) {
			$add( 'error', 'placeholder_left', 'Copy placeholders are printed: ' . implode( ', ', array_slice( $o['text']['placeholders'], 0, 5 ) ) );
		}
		if ( $o['text']['demo'] ) {
			$add( 'warning', 'demo_left', 'Demo text is printed: ' . implode( ', ', $o['text']['demo'] ) . '. Fill Business Info (set-company) or rewrite the copy.' );
		}
		if ( preg_match( '/\{\{|%7B%7B|\[\[/i', $o['seo']['title'] . ' ' . $o['seo']['description'] ) ) {
			$add( 'error', 'seo_raw_token', 'The title or meta description contains a raw token or placeholder.' );
		}
		if ( $o['links']['dead_count'] ) {
			$add( 'warning', 'dead_link', $o['links']['dead_count'] . ' link(s) go nowhere (# or empty).', array_slice( $o['links']['dead'], 0, 10 ) );
		}
		if ( ! empty( $o['links']['checked']['broken'] ) ) {
			$add( 'error', 'broken_link', count( $o['links']['checked']['broken'] ) . ' internal link(s) are broken.', $o['links']['checked']['broken'] );
		}
		foreach ( $o['links']['tel'] as $t ) {
			if ( ! $t['valid'] ) {
				$add( 'warning', 'bad_tel_link', 'Phone link without a usable number: ' . $t['href'] );
			}
		}
		if ( ! empty( $o['links']['phone_printed'] ) && ! $o['links']['tel'] ) {
			$add( 'warning', 'phone_not_tel', 'The phone number is printed but not linked with tel:.' );
		}
		if ( $o['images']['missing_alt'] ) {
			$add( 'warning', 'image_no_alt', $o['images']['missing_alt'] . ' image(s) have no alt attribute.', $o['images']['missing_alt_src'] );
		}
		if ( $o['images']['placeholders'] || $o['images']['placeholder_refs'] ) {
			$add( 'warning', 'placeholder_image', 'Placeholder images are still on the page (' . max( $o['images']['placeholders'], $o['images']['placeholder_refs'] ) . ').' );
		}
		if ( 'conversion' === $role && ! $o['forms']['present'] ) {
			$add( 'error', 'form_missing', 'A conversion page without a form or form embed.' );
		}
		$heroes = array_values( array_filter( (array) ( $o['chrome']['hero'] ?? [] ), static function ( $h ) { return empty( $h['nested'] ); } ) );
		if ( count( $heroes ) > 1 ) {
			$add( 'warning', 'double_hero', count( $heroes ) . ' heroes are printed (' . implode( ', ', array_map( static function ( $h ) { return ( $h['source'] ?? '' ) . ( isset( $h['id'] ) ? ' #' . $h['id'] : '' ); }, $heroes ) ) . '); one expected.', $heroes );
		} elseif ( ! $heroes && '' !== $role ) {
			$pid = (int) ( $opts['post_id'] ?? 0 );
			if ( ! $pid && ! empty( $opts['url'] ) ) {
				$pid = (int) url_to_postid( (string) $opts['url'] );
			}
			unset( $pid );
			if ( '' !== Design_Hub::role_markup( $role, [ 'hero' ] ) ) {
				$add( 'info', 'hero_missing', 'No hero printed on this ' . $role . ' page, though the Design hub places one on it (is the Kadence Elements module on?).' );
			}
		}
		if ( '' !== $role ) {
			$want = Design_Hub::crumbs_on( $role );
			$have = (bool) $o['crumbs'];
			if ( $have && 'home' === $role ) {
				$add( 'warning', 'crumbs_on_excluded_role', 'Breadcrumbs are printed on the home page, which should have none.' );
			} elseif ( ! $have && $want ) {
				$add( 'info', 'crumbs_missing', 'No breadcrumb trail on a ' . $role . ' page, though the Design hub places the breadcrumb bar on it.' );
			}
			$robots = strtolower( $o['seo']['robots'] );
			if ( 'thanks' === $role && false === strpos( $robots, 'noindex' ) ) {
				$add( 'info', 'thanks_indexable', 'The thank-you page can be indexed (noindex expected).' );
			} elseif ( ! in_array( $role, [ 'thanks', 'search', 'notfound' ], true ) && false !== strpos( $robots, 'noindex' ) ) {
				$add( 'warning', 'noindex', 'The page asks search engines not to index it (robots: ' . $o['seo']['robots'] . ').' );
			}
		}
		return $is;
	}
}
