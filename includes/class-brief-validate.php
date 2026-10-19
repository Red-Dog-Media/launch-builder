<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Brief validation and normalisation. check() reads the WHOLE brief before anything is written and returns every problem
 * as a list of { path, message } (paths such as sitemap.pages[2].recipe), warnings, the sections whose module is off, and
 * the normalised brief the builder works from: every page and entry has a stable key and path, references (menu items,
 * category pages, services offered in an area, media) are resolved, and nothing is left to guess later.
 */
final class Brief_Validate {

	const MAX_PAGES   = 120;
	const MAX_ENTRIES = 400;
	const MAX_MEDIA   = 200;
	const MAX_DEPTH   = 4;
	const MAX_TEXT    = 200000;

	/** Page roles a brief page may name. */
	const PAGE_ROLES = [ 'home', 'page', 'legal', 'conversion', 'thanks', 'services_hub', 'areas_hub', 'category' ];

	/** Brief sections written by Launch Builder itself (modules add theirs through the brief_schema hook). */
	const SECTIONS = [ 'version', 'business', 'brand', 'seo', 'sitemap', 'entries', 'content', 'media', 'forms', 'menus', 'chrome', 'urls', 'migrate', 'legal', 'launch', 'conversions', 'branding' ];

	/** content keys: lists of items the pattern families will read once they take data. */
	const CONTENT_KEYS = [ 'testimonials', 'team', 'offers', 'guarantees', 'badges', 'brands', 'gallery', 'videos' ];

	/** @var array[] collected problems of the current check. */
	private static $e = [];
	private static $w = [];

	private static function err( string $path, string $msg ): void {
		self::$e[] = [ 'path' => $path, 'message' => $msg ];
	}

	private static function warn( string $path, string $msg ): void {
		self::$w[] = [ 'path' => $path, 'message' => $msg ];
	}

	private static function is_list( $v ): bool {
		return is_array( $v ) && ( [] === $v || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
	}

	/** An object: an associative array (JSON {} arrives as an empty array, so [] counts as an empty object too). */
	private static function is_obj( $v ): bool {
		return is_array( $v ) && ( [] === $v || ! self::is_list( $v ) );
	}

	/** A text value: scalar to string (null when it is not scalar). */
	private static function text( $v, string $path, int $max = 500, bool $required = false ): ?string {
		if ( null === $v ) {
			if ( $required ) {
				self::err( $path, 'required.' );
			}
			return null;
		}
		if ( is_bool( $v ) || ! is_scalar( $v ) ) {
			self::err( $path, 'must be text.' );
			return null;
		}
		$s = trim( wp_strip_all_tags( (string) $v ) );
		if ( mb_strlen( $s ) > $max ) {
			self::err( $path, 'is longer than ' . $max . ' characters.' );
			return null;
		}
		if ( $required && '' === $s ) {
			self::err( $path, 'must not be empty.' );
			return null;
		}
		return $s;
	}

	/** Long text (Markdown): kept as written, only size and type are checked. */
	private static function long_text( $v, string $path ): ?string {
		if ( null === $v ) {
			return null;
		}
		if ( ! is_string( $v ) ) {
			self::err( $path, 'must be text.' );
			return null;
		}
		if ( strlen( $v ) > self::MAX_TEXT ) {
			self::err( $path, 'is longer than 200 KB.' );
			return null;
		}
		return $v;
	}

	private static function key( $v, string $path ): string {
		if ( null === $v || '' === $v ) {
			return '';
		}
		if ( ! is_string( $v ) ) {
			self::err( $path, 'key must be text.' );
			return '';
		}
		$k = Entries::clean_key( $v );
		if ( '' === $k ) {
			self::err( $path, 'key may use lower case letters, digits and _ - : . / only.' );
		}
		return $k;
	}

	/**
	 * Validate and normalise a brief.
	 *
	 * @param mixed $brief The decoded brief.
	 * @return array{errors:array,warnings:array,modules_off:array,brief:array,deferred:array}
	 */
	public static function check( $brief ): array {
		self::$e = [];
		self::$w = [];
		$off = [];
		$deferred = [];
		if ( ! is_array( $brief ) || ( $brief && self::is_list( $brief ) ) ) {
			self::err( '', 'The brief must be an object with sections such as business, sitemap and entries.' );
			return [ 'errors' => self::$e, 'warnings' => [], 'modules_off' => [], 'brief' => [], 'deferred' => [] ];
		}
		$described = array_keys( (array) ( Brief::schema()['properties'] ?? [] ) ); // Sections modules describe through brief_schema.
		foreach ( array_keys( $brief ) as $k ) {
			if ( in_array( $k, self::SECTIONS, true ) ) {
				continue;
			}
			if ( in_array( $k, $described, true ) ) {
				$deferred[ (string) $k ] = 'Described by a module (brief_schema) but not applied by the brief builder: nothing is written for it.';
				self::warn( (string) $k, 'A module describes this section but the brief builder does not apply it yet; nothing is written for it.' );
			} else {
				self::warn( (string) $k, 'Unknown section; it is ignored. Sections: ' . implode( ', ', self::SECTIONS ) . '.' );
			}
		}
		$out = [ 'version' => 1 ];
		$out['business'] = self::business( $brief['business'] ?? [] );
		$media = self::media( $brief['media'] ?? [] );
		$out['brand'] = self::brand( $brief['brand'] ?? [] );
		$out['seo'] = self::seo( $brief['seo'] ?? [] );
		$pages = self::sitemap( $brief['sitemap'] ?? [] );
		$ent = self::entries( $brief['entries'] ?? [] );
		$out['content'] = self::content( $brief['content'] ?? [], $deferred );
		$out['forms'] = self::forms( $brief['forms'] ?? [], $deferred );
		$out['launch'] = self::launch( $brief['launch'] ?? [] );
		$legal = self::legal( $brief['legal'] ?? [], $pages, $out['business'] );
		$out['legal'] = $legal['legal'];
		$pages = $legal['pages'];
		$out['chrome'] = self::chrome( $brief['chrome'] ?? [] );
		$out['urls'] = self::urls( $brief['urls'] ?? [], $brief['migrate'] ?? [], $off );
		$out['conversions'] = self::conversions( $brief['conversions'] ?? null, $off );
		$out['branding'] = self::branding( $brief['branding'] ?? [] );

		// Cross references: pages, entries, menus and media must agree.
		$page_ref = self::page_refs( $pages );
		$ent = self::resolve_entries( $ent, $page_ref );
		$pages = self::resolve_pages( $pages, $ent );
		$out['menus'] = self::menus( $brief['menus'] ?? [], $page_ref );
		$out['seo'] = self::seo_pages( $out['seo'], $pages );
		$out['sitemap'] = [ 'pages' => $pages ];
		$out['entries'] = $ent;
		$out['media'] = self::resolve_media( $media, $out, $deferred );

		foreach ( $off as $o ) {
			self::warn( $o['section'], $o['note'] );
		}
		return [ 'errors' => self::$e, 'warnings' => self::$w, 'modules_off' => $off, 'brief' => $out, 'deferred' => $deferred ];
	}

	/* ------------------------------------------------------------------ business */

	private static function business( $in ): array {
		if ( ! self::is_obj( $in ) ) {
			if ( [] !== $in ) {
				self::err( 'business', 'must be an object of Business Info fields (see get-company).' );
			} else {
				self::err( 'business.name', 'required: the business name.' );
			}
			return [];
		}
		$fields = Company::fields();
		$out = [];
		foreach ( $in as $k => $v ) {
			$k = (string) $k;
			$p = 'business.' . $k;
			if ( 'site_title' === $k || 'site_tagline' === $k ) { // The WordPress title and tagline when they differ from the business name and Business Info tagline.
				$t = self::text( $v, $p, 120 );
				if ( null !== $t ) {
					$out[ $k ] = $t;
				}
				continue;
			}
			if ( ! isset( $fields[ $k ] ) ) {
				self::warn( $p, 'Not a Business Info field; ignored (get-company lists them).' );
				continue;
			}
			$type = $fields[ $k ][1];
			if ( 'list' === $type ) {
				if ( ! is_array( $v ) && ! ( is_string( $v ) && 'areas_served' === $k ) ) {
					self::err( $p, 'must be a list.' );
					continue;
				}
				$out[ $k ] = $v;
				continue;
			}
			if ( is_bool( $v ) ) {
				$out[ $k ] = $v ? '1' : '';
				continue;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				$out[ $k ] = (string) $v;
				continue;
			}
			if ( ! is_string( $v ) ) {
				self::err( $p, 'must be text.' );
				continue;
			}
			$v = trim( $v );
			if ( strlen( $v ) > ( 'html' === $type ? 20000 : 2000 ) ) {
				self::err( $p, 'is too long.' );
				continue;
			}
			if ( 'email' === $type && '' !== $v && ! is_email( $v ) ) {
				self::err( $p, 'is not an email address.' );
				continue;
			}
			if ( false !== strpos( $v, '[[' ) ) {
				self::warn( $p, 'Still holds a [[placeholder]]; it ships as written.' );
			}
			$out[ $k ] = $v;
		}
		if ( '' === trim( (string) ( $out['name'] ?? '' ) ) && '' === trim( (string) ( $out['site_title'] ?? '' ) ) ) { // The name follows the WordPress site title, so a brief may give just that.
			self::err( 'business.name', 'required: the business name (or business.site_title, which the name then follows).' );
		}
		foreach ( [ 'phone', 'email', 'address', 'hours' ] as $k ) {
			if ( '' === trim( (string) ( $out[ $k ] ?? '' ) ) && ! ( 'address' === $k && ! empty( $out['street'] ) ) && ! ( 'hours' === $k && ( ! empty( $out['hours_spec'] ) || ! empty( $out['is_24_7'] ) ) ) ) {
				self::warn( 'business.' . $k, 'Not given: pages show a demo value for it until Business Info has one.' );
			}
		}
		ksort( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ brand */

	private static function hex( $v, string $path ): ?string {
		$h = is_string( $v ) ? sanitize_hex_color( trim( $v ) ) : null;
		if ( ! $h ) {
			self::err( $path, 'must be a hex colour such as #0F766E.' );
			return null;
		}
		return strtoupper( $h );
	}

	private static function brand( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'brand', 'must be an object.' );
			return [];
		}
		$out = [];
		if ( isset( $in['palette'] ) ) {
			if ( ! self::is_obj( $in['palette'] ) ) {
				self::err( 'brand.palette', 'must be an object palette1..palette9 => hex colour.' );
			} else {
				foreach ( $in['palette'] as $slug => $hex ) {
					if ( ! preg_match( '/^palette([1-9]|10)$/', (string) $slug ) ) {
						self::err( 'brand.palette.' . $slug, 'is not a palette slot (palette1 to palette9).' );
						continue;
					}
					$h = self::hex( $hex, 'brand.palette.' . $slug );
					if ( $h ) {
						$out['palette'][ (string) $slug ] = $h;
					}
				}
			}
		}
		if ( isset( $in['colors'] ) ) {
			if ( ! self::is_obj( $in['colors'] ) ) {
				self::err( 'brand.colors', 'must be an object { primary, accent, dark } of hex colours.' );
			} else {
				$cols = [];
				foreach ( $in['colors'] as $k => $hex ) {
					if ( ! in_array( (string) $k, [ 'primary', 'accent', 'dark' ], true ) ) {
						self::warn( 'brand.colors.' . $k, 'Ignored (primary, accent and dark are used).' );
						continue;
					}
					$h = self::hex( $hex, 'brand.colors.' . $k );
					if ( $h ) {
						$cols[ (string) $k ] = $h;
					}
				}
				if ( $cols && empty( $out['palette'] ) ) {
					$out['palette'] = Brief::palette( $cols );
				}
				if ( $cols ) {
					$out['colors'] = $cols;
				}
			}
		}
		if ( isset( $in['fonts'] ) ) {
			if ( ! self::is_obj( $in['fonts'] ) ) {
				self::err( 'brand.fonts', 'must be an object { headings, body, weights }.' );
			} else {
				$weights = [];
				if ( isset( $in['fonts']['weights'] ) ) {
					foreach ( (array) $in['fonts']['weights'] as $wt ) {
						if ( is_numeric( $wt ) && (int) $wt >= 100 && (int) $wt <= 900 ) {
							$weights[] = (int) $wt;
						} else {
							self::err( 'brand.fonts.weights', 'weights are numbers from 100 to 900.' );
						}
					}
				}
				foreach ( [ 'headings', 'body' ] as $role ) {
					if ( ! isset( $in['fonts'][ $role ] ) ) {
						continue;
					}
					$f = $in['fonts'][ $role ];
					$f = is_string( $f ) ? [ 'family' => $f ] : $f;
					if ( ! self::is_obj( $f ) ) {
						self::err( 'brand.fonts.' . $role, 'must be a font family name or { family, weight }.' );
						continue;
					}
					$fam = self::text( $f['family'] ?? null, 'brand.fonts.' . $role . '.family', 80, true );
					if ( null === $fam ) {
						continue;
					}
					$spec = [ 'family' => $fam ];
					$wt = $f['weight'] ?? ( 'headings' === $role && $weights ? max( $weights ) : null );
					if ( null !== $wt ) {
						if ( is_numeric( $wt ) || 'regular' === $wt ) {
							$spec['weight'] = (string) $wt;
						} else {
							self::err( 'brand.fonts.' . $role . '.weight', 'must be a number such as 700.' );
						}
					}
					$out['fonts'][ $role ] = $spec;
				}
			}
		}
		foreach ( [ 'logo', 'logo_inverse', 'site_icon' ] as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$t = self::text( $in[ $k ], 'brand.' . $k, 500 );
				if ( null !== $t && '' !== $t ) {
					$out[ $k ] = $t;
				}
			}
		}
		if ( isset( $in['logo_width'] ) ) {
			if ( is_numeric( $in['logo_width'] ) && (int) $in['logo_width'] >= 20 && (int) $in['logo_width'] <= 800 ) {
				$out['logo_width'] = (int) $in['logo_width'];
			} else {
				self::err( 'brand.logo_width', 'must be a width in pixels, 20 to 800.' );
			}
		}
		if ( isset( $in['style'] ) ) {
			$s = is_string( $in['style'] ) ? [ 'preset' => $in['style'] ] : $in['style'];
			if ( ! self::is_obj( $s ) ) {
				self::err( 'brand.style', 'must be a preset name or { preset, tokens }.' );
			} else {
				$preset = $s['preset'] ?? '';
				if ( '' !== $preset && ( ! is_string( $preset ) || ! in_array( $preset, Style::PRESETS, true ) ) ) {
					self::err( 'brand.style.preset', 'must be one of ' . implode( ', ', Style::PRESETS ) . '.' );
				} elseif ( isset( $s['tokens'] ) && ! self::is_obj( $s['tokens'] ) && [] !== $s['tokens'] ) {
					self::err( 'brand.style.tokens', 'must be an object (see get-style).' );
				} else {
					$out['style'] = [ 'preset' => (string) $preset, 'tokens' => (array) ( $s['tokens'] ?? [] ) ];
				}
			}
		}
		foreach ( [ 'buttons', 'nav' ] as $k ) {
			if ( isset( $in[ $k ] ) ) {
				self::is_obj( $in[ $k ] ) ? $out[ $k ] = $in[ $k ] : self::err( 'brand.' . $k, 'must be an object (see set-' . ( 'nav' === $k ? 'nav-style' : 'buttons' ) . ').' );
			}
		}
		if ( isset( $in['page'] ) ) {
			is_string( $in['page'] ) || self::is_obj( $in['page'] ) ? $out['page'] = $in['page'] : self::err( 'brand.page', 'must be a preset name or an object (see set-page-style).' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ seo */

	private static function seo( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'seo', 'must be an object.' );
			return [];
		}
		$out = [];
		if ( isset( $in['templates'] ) ) {
			if ( ! self::is_obj( $in['templates'] ) ) {
				self::err( 'seo.templates', 'must be an object role => { title, description }.' );
			} else {
				foreach ( $in['templates'] as $role => $t ) {
					$p = 'seo.templates.' . $role;
					if ( ! isset( Roles::ROLES[ (string) $role ] ) ) {
						self::err( $p, 'Unknown role (roles: ' . implode( ', ', array_keys( Roles::ROLES ) ) . ').' );
						continue;
					}
					if ( ! self::is_obj( $t ) ) {
						self::err( $p, 'must be { title, description }.' );
						continue;
					}
					foreach ( $t as $f => $val ) {
						if ( ! in_array( (string) $f, [ 'title', 'description' ], true ) ) {
							self::warn( $p . '.' . $f, 'Ignored (title and description are used).' );
							continue;
						}
						$s = self::text( $val, $p . '.' . $f, 320 );
						if ( null === $s ) {
							continue;
						}
						$out['templates'][ (string) $role ][ (string) $f ] = $s;
						$unknown = Compat::unknown_vars( $s );
						if ( $unknown ) {
							self::warn( $p . '.' . $f, 'Nothing replaces %' . implode( '%, %', $unknown ) . '%.' );
						}
						if ( preg_match( '/\s[|\-–—•·]\s/u', $s ) ) {
							self::warn( $p . '.' . $f, 'Uses a literal separator: write %sep% so it follows the SEO plugin setting.' );
						}
						if ( false !== strpos( $s, '{{' ) ) {
							self::warn( $p . '.' . $f, 'Contains a {{token}}: SEO templates use %variables%.' );
						}
					}
				}
			}
		}
		if ( isset( $in['pages'] ) ) {
			if ( ! self::is_obj( $in['pages'] ) ) {
				self::err( 'seo.pages', 'must be an object page key, path or slug => { title, description, keyword, robots }.' );
			} else {
				foreach ( $in['pages'] as $ref => $s ) {
					$one = self::page_seo( $s, 'seo.pages.' . $ref );
					if ( $one ) {
						$out['pages'][ (string) $ref ] = $one;
					}
				}
			}
		}
		if ( isset( $in['schema_type'] ) ) {
			$t = self::text( $in['schema_type'], 'seo.schema_type', 60 );
			if ( $t ) {
				$out['schema_type'] = $t;
			}
		}
		if ( isset( $in['og_image'] ) ) {
			$t = self::text( $in['og_image'], 'seo.og_image', 500 );
			if ( $t ) {
				$out['og_image'] = $t;
			}
		}
		if ( array_key_exists( 'enable_local_seo', $in ) ) {
			if ( is_bool( $in['enable_local_seo'] ) ) {
				$out['enable_local_seo'] = $in['enable_local_seo'];
			} else {
				self::err( 'seo.enable_local_seo', 'must be true or false.' );
			}
		}
		return $out;
	}

	/** One page's SEO: { title, description, keyword (or focus), robots[] }. */
	private static function page_seo( $s, string $path ): array {
		if ( null === $s || [] === $s ) {
			return [];
		}
		if ( ! self::is_obj( $s ) ) {
			self::err( $path, 'must be { title, description, keyword, robots }.' );
			return [];
		}
		$out = [];
		foreach ( [ 'title' => 320, 'description' => 400, 'keyword' => 160 ] as $f => $max ) {
			$v = $s[ $f ] ?? ( 'keyword' === $f ? ( $s['focus'] ?? null ) : null );
			if ( null === $v ) {
				continue;
			}
			$t = self::text( $v, $path . '.' . $f, $max );
			if ( null !== $t && '' !== $t ) {
				$out[ $f ] = $t;
			}
		}
		if ( isset( $s['robots'] ) ) {
			if ( ! is_array( $s['robots'] ) && ! is_string( $s['robots'] ) ) {
				self::err( $path . '.robots', 'must be a list such as ["noindex","follow"].' );
			} else {
				$out['robots'] = Compat::robots_list( $s['robots'] );
			}
		}
		ksort( $out );
		return $out;
	}

	/** seo.pages entries are merged into the pages they name. */
	private static function seo_pages( array $seo, array &$pages ): array {
		if ( empty( $seo['pages'] ) ) {
			return $seo;
		}
		$ref = self::page_refs( $pages );
		foreach ( $seo['pages'] as $r => $s ) {
			$key = $ref[ $r ] ?? null;
			if ( null === $key ) {
				self::err( 'seo.pages.' . $r, 'No page with this key, path or slug in the sitemap.' );
				continue;
			}
			foreach ( $pages as $i => $pg ) {
				if ( $pg['key'] === $key ) {
					$pages[ $i ]['seo'] = $pg['seo'] + $s; // The page's own SEO wins.
					ksort( $pages[ $i ]['seo'] );
				}
			}
		}
		unset( $seo['pages'] );
		return $seo;
	}

	/* ------------------------------------------------------------------ sitemap */

	private static function sitemap( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		$list = self::is_list( $in ) ? $in : ( is_array( $in ) ? ( $in['pages'] ?? null ) : null );
		if ( null === $list ) {
			if ( self::is_obj( $in ) ) {
				self::err( 'sitemap.pages', 'required: the list of pages.' );
			} else {
				self::err( 'sitemap', 'must be { pages: [...] } or a list of pages.' );
			}
			return [];
		}
		if ( ! self::is_list( $list ) ) {
			self::err( 'sitemap.pages', 'must be a list.' );
			return [];
		}
		$flat = [];
		self::page_list( $list, 'sitemap.pages', '', '', 1, $flat );
		$flat = self::settle_pages( $flat );
		if ( count( $flat ) > self::MAX_PAGES ) {
			self::err( 'sitemap.pages', 'Too many pages (' . count( $flat ) . '; at most ' . self::MAX_PAGES . ').' );
		}
		$paths = [];
		$keys = [];
		$front = 0;
		foreach ( $flat as $pg ) {
			if ( isset( $paths[ $pg['path'] ] ) ) {
				self::err( $pg['_p'], 'Two pages have the path "' . $pg['path'] . '".' );
			}
			$paths[ $pg['path'] ] = true;
			if ( isset( $keys[ $pg['key'] ] ) ) {
				self::err( $pg['_p'] . '.key', 'The key "' . $pg['key'] . '" is used twice.' );
			}
			$keys[ $pg['key'] ] = true;
			$front += ( ! empty( $pg['front'] ) || 'home' === $pg['role'] ) ? 1 : 0;
		}
		if ( $front > 1 ) {
			self::err( 'sitemap.pages', 'More than one home page (role home or front true).' );
		}
		return $flat;
	}

	private static function page_list( array $list, string $path, string $parent_path, string $parent_key, int $depth, array &$flat ): void {
		if ( $depth > self::MAX_DEPTH ) {
			self::err( $path, 'Pages are nested more than ' . self::MAX_DEPTH . ' levels deep.' );
			return;
		}
		foreach ( $list as $i => $pg ) {
			$p = $path . '[' . $i . ']';
			if ( ! self::is_obj( $pg ) ) {
				self::err( $p, 'must be an object { title, slug, ... }.' );
				continue;
			}
			$title = self::text( $pg['title'] ?? null, $p . '.title', 200, true );
			$slug_in = self::text( $pg['slug'] ?? null, $p . '.slug', 200 );
			$slug = sanitize_title( '' !== (string) $slug_in ? (string) $slug_in : (string) $title );
			if ( null !== $title && '' === $slug ) {
				self::err( $p . '.slug', 'The title gives no slug: add a slug.' );
			}
			$pref = '';
			if ( '' === $parent_key && isset( $pg['parent'] ) ) { // A flat page names its parent by key or path.
				is_string( $pg['parent'] ) ? $pref = trim( $pg['parent'], '/ ' ) : self::err( $p . '.parent', 'must be the key or path of the parent page.' );
			}
			$full = ( '' !== $parent_path ? $parent_path . '/' : '' ) . $slug;
			$key = self::key( $pg['key'] ?? null, $p . '.key' );
			$role = $pg['role'] ?? '';
			if ( '' !== $role && ( ! is_string( $role ) || ! in_array( $role, self::PAGE_ROLES, true ) ) ) {
				self::err( $p . '.role', 'must be one of ' . implode( ', ', self::PAGE_ROLES ) . '.' );
				$role = '';
			}
			$recipe = $pg['recipe'] ?? '';
			$recipes = Content::recipes();
			if ( '' !== $recipe && ( ! is_string( $recipe ) || ! isset( $recipes[ $recipe ] ) ) ) {
				self::err( $p . '.recipe', 'Unknown recipe (recipes: ' . implode( ', ', array_keys( $recipes ) ) . ').' );
				$recipe = '';
			}
			if ( '' === $role && '' !== $recipe ) {
				$derived = Roles::RECIPE_ROLES[ $recipe ] ?? '';
				$role = in_array( $derived, self::PAGE_ROLES, true ) ? $derived : '';
			}
			$type = $pg['type'] ?? '';
			if ( '' !== $type && ( ! is_string( $type ) || ! isset( Content::TYPES[ $type ] ) ) ) {
				self::err( $p . '.type', 'must be one of ' . implode( ', ', array_keys( Content::TYPES ) ) . '.' );
				$type = '';
			}
			$status = $pg['status'] ?? '';
			if ( '' !== $status && ( ! is_string( $status ) || ! in_array( $status, [ 'draft', 'publish', 'private' ], true ) ) ) {
				self::err( $p . '.status', 'must be draft, publish or private.' );
				$status = '';
			}
			$content = $pg['content'] ?? '';
			if ( '' !== $content && 'keep' !== $content && 'build' !== $content ) {
				self::err( $p . '.content', 'must be "keep" (leave the existing text alone) or "build".' );
				$content = '';
			}
			$markdown = self::long_text( $pg['markdown'] ?? null, $p . '.markdown' );
			$replace = [];
			if ( isset( $pg['replace'] ) ) {
				self::is_obj( $pg['replace'] ) ? $replace = array_map( 'strval', array_filter( $pg['replace'], 'is_scalar' ) ) : self::err( $p . '.replace', 'must be an object default text => new text.' );
			}
			$sections = self::sections( $pg['sections'] ?? [], $p . '.sections' );
			$layout = [];
			if ( isset( $pg['layout'] ) ) {
				if ( self::is_obj( $pg['layout'] ) || is_string( $pg['layout'] ) ) {
					$layout = is_string( $pg['layout'] ) ? [ 'style' => $pg['layout'] ] : $pg['layout'];
				} else {
					self::err( $p . '.layout', 'must be an object (see set-page-layout).' );
				}
			}
			$category = '';
			if ( isset( $pg['category'] ) ) {
				$category = (string) self::text( $pg['category'], $p . '.category', 120 );
			}
			if ( 'category' === $role && '' === $category ) {
				$category = '?'; // Resolved against entries.categories[].page later.
			}
			if ( '' !== $content && 'keep' === $content && ( $sections || $markdown ) ) {
				self::warn( $p . '.content', 'content "keep" ignores the sections and markdown of this page.' );
			}
			$order = isset( $pg['order'] ) && is_numeric( $pg['order'] ) ? (int) $pg['order'] : (int) $i;
			$spec = [
				'key'        => '' !== $key ? $key : ( '' !== $pref ? '' : 'page:' . $full ),
				'title'      => (string) $title,
				'slug'       => $slug,
				'path'       => $full,
				'parent_key' => $parent_key,
				'parent_ref' => $pref,
				'role'       => (string) $role,
				'recipe'     => (string) $recipe,
				'type'       => (string) $type,
				'sections'   => $sections,
				'markdown'   => (string) $markdown,
				'replace'    => $replace,
				'layout'     => $layout,
				'seo'        => self::page_seo( $pg['seo'] ?? null, $p . '.seo' ),
				'status'     => (string) $status,
				'front'      => ! empty( $pg['front'] ) || 'home' === $role,
				'order'      => $order,
				'content'    => '' !== $content ? $content : ( $sections || $recipe || '' !== (string) $markdown ? 'build' : 'keep' ),
				'category'   => $category,
				'_p'         => $p,
			];
			if ( 'home' === $role ) {
				$spec['role'] = 'home';
			}
			$flat[] = $spec;
			if ( isset( $pg['children'] ) ) {
				if ( self::is_list( $pg['children'] ) ) {
					self::page_list( $pg['children'], $p . '.children', $full, $spec['key'], $depth + 1, $flat );
				} else {
					self::err( $p . '.children', 'must be a list of pages.' );
				}
			}
		}
	}

	/**
	 * Flat pages name their parent by key or path: resolve them, give every page its path and key, and order the list so
	 * a parent always comes before its children (nested pages keep the order they were written in).
	 */
	private static function settle_pages( array $flat ): array {
		foreach ( $flat as $i => $p ) {
			$flat[ $i ]['settled'] = '' === $p['parent_ref'];
		}
		for ( $pass = 0; $pass < 8; $pass++ ) {
			$progress = false;
			foreach ( $flat as $i => $p ) {
				if ( $flat[ $i ]['settled'] ) {
					continue;
				}
				foreach ( $flat as $j => $q ) {
					if ( $q['settled'] && $j !== $i && ( ( '' !== $q['key'] && $q['key'] === $p['parent_ref'] ) || $q['path'] === $p['parent_ref'] ) ) {
						$flat[ $i ]['parent_key'] = $q['key'];
						$flat[ $i ]['path'] = $q['path'] . '/' . $p['slug'];
						$flat[ $i ]['key'] = '' !== $p['key'] ? $p['key'] : 'page:' . $flat[ $i ]['path'];
						$flat[ $i ]['settled'] = true;
						$progress = true;
						break;
					}
				}
			}
			if ( ! $progress ) {
				break;
			}
		}
		foreach ( $flat as $i => $p ) {
			if ( ! $p['settled'] ) {
				$ex = get_page_by_path( $p['parent_ref'], OBJECT, 'page' ); // A parent that is on the site already.
				if ( $ex && 'trash' !== $ex->post_status ) {
					$flat[ $i ]['parent_key'] = self::site_key( (int) $ex->ID );
					$flat[ $i ]['path'] = (string) get_page_uri( $ex ) . '/' . $p['slug'];
					$flat[ $i ]['key'] = '' !== $p['key'] ? $p['key'] : 'page:' . $flat[ $i ]['path'];
				} else {
					self::err( $p['_p'] . '.parent', 'No page with the key or path "' . $p['parent_ref'] . '" in the sitemap or on the site.' );
					$flat[ $i ]['key'] = '' !== $p['key'] ? $p['key'] : 'page:' . $p['path'];
				}
			}
			unset( $flat[ $i ]['settled'], $flat[ $i ]['parent_ref'] );
		}
		$kids = [];
		foreach ( $flat as $i => $p ) {
			$kids[ $p['parent_key'] ][] = $i;
		}
		$keys = array_column( $flat, 'key' );
		$out = [];
		$seen = [];
		$visit = static function ( string $pk ) use ( &$visit, &$out, &$seen, $kids, $flat ) {
			foreach ( $kids[ $pk ] ?? [] as $i ) {
				if ( isset( $seen[ $i ] ) ) {
					continue;
				}
				$seen[ $i ] = true;
				$out[] = $flat[ $i ];
				$visit( $flat[ $i ]['key'] );
			}
		};
		$visit( '' );
		foreach ( $kids as $pk => $list ) { // A parent that is not in the list (an error reported above): keep the page anyway.
			if ( '' !== $pk && ! in_array( $pk, $keys, true ) ) {
				foreach ( $list as $i ) {
					if ( ! isset( $seen[ $i ] ) ) {
						$seen[ $i ] = true;
						$out[] = $flat[ $i ];
					}
				}
			}
		}
		return $out;
	}

	private static function sections( $in, string $path ): array {
		if ( [] === $in || null === $in ) {
			return [];
		}
		if ( ! self::is_list( $in ) ) {
			self::err( $path, 'must be a list of sections.' );
			return [];
		}
		$out = [];
		$all = Patterns::index();
		foreach ( $in as $i => $s ) {
			$p = $path . '[' . $i . ']';
			if ( ! self::is_obj( $s ) ) {
				self::err( $p, 'must be an object { pattern, slots, replace, markdown } or { use } or { markdown }.' );
				continue;
			}
			$one = [];
			if ( isset( $s['use'] ) ) {
				$one['use'] = sanitize_key( (string) ( is_scalar( $s['use'] ) ? $s['use'] : '' ) );
				if ( '' === $one['use'] ) {
					self::err( $p . '.use', 'must be the key of a synced pattern.' );
				}
			}
			if ( isset( $s['pattern'] ) ) {
				$slug = is_string( $s['pattern'] ) ? sanitize_key( $s['pattern'] ) : '';
				$known = Plugin::pattern_index();
				if ( '' !== $slug && ! isset( $all[ $slug ] ) && isset( $known[ $slug ] ) ) {
					self::err( $p . '.pattern', $slug . ' needs ' . ( '' !== (string) $known[ $slug ]['requires'] ? $known[ $slug ]['requires'] : 'a plugin' ) . ', which is not active.' );
					continue;
				}
				if ( '' === $slug || ! isset( $all[ $slug ] ) ) {
					self::err( $p . '.pattern', 'Unknown pattern "' . ( is_scalar( $s['pattern'] ) ? $s['pattern'] : '?' ) . '" (list-patterns lists them).' );
					continue;
				}
				if ( in_array( 'launch-builder-headers', (array) ( $all[ $slug ]['categories'] ?? [] ), true ) ) {
					self::err( $p . '.pattern', $slug . ' is a header pattern: headers come from menus and the chrome map.' );
					continue;
				}
				$one['pattern'] = $slug;
			}
			foreach ( [ 'slots', 'replace' ] as $k ) {
				if ( isset( $s[ $k ] ) ) {
					if ( ! self::is_obj( $s[ $k ] ) && [] !== $s[ $k ] ) {
						self::err( $p . '.' . $k, 'must be an object.' );
					} else {
						$one[ $k ] = (array) $s[ $k ];
					}
				}
			}
			if ( isset( $s['markdown'] ) ) {
				$md = self::long_text( $s['markdown'], $p . '.markdown' );
				if ( null !== $md ) {
					$one['markdown'] = $md;
				}
			}
			if ( ! isset( $one['pattern'] ) && ! isset( $one['use'] ) && ! isset( $one['markdown'] ) ) {
				self::err( $p, 'needs a pattern, a synced key (use) or markdown.' );
				continue;
			}
			$out[] = $one;
		}
		return $out;
	}

	/** ref (key, path or unambiguous slug) => key, for pages. */
	/** The key of a page, service or area that is on the site: its _lb_key, else the key a brief gives it by default. */
	private static function site_key( int $id ): string {
		$k = (string) get_post_meta( $id, '_lb_key', true );
		$p = get_post( $id );
		return '' !== $k ? $k : ( $p ? Brief_Export::default_key( $p ) : '' );
	}

	private static function page_refs( array $pages ): array {
		$map = [];
		$slugs = [];
		foreach ( $pages as $pg ) {
			$map[ $pg['key'] ] = $pg['key'];
			$map[ $pg['path'] ] = $pg['key'];
			$slugs[ $pg['slug'] ][] = $pg['key'];
		}
		foreach ( $slugs as $s => $keys ) {
			if ( 1 === count( $keys ) && ! isset( $map[ $s ] ) ) {
				$map[ $s ] = $keys[0];
			}
		}
		return $map;
	}

	private static function resolve_pages( array $pages, array $ent ): array {
		$cat_page = [];
		foreach ( $ent['categories'] as $c ) {
			if ( '' !== $c['page_key'] ) {
				$cat_page[ $c['page_key'] ] = $c['slug'];
			}
		}
		foreach ( $pages as $i => $pg ) {
			if ( '' === $pg['role'] && isset( $cat_page[ $pg['key'] ] ) ) { // A category names this page as its landing page.
				$pages[ $i ]['role'] = 'category';
				$pages[ $i ]['category'] = $cat_page[ $pg['key'] ];
				$pg = $pages[ $i ];
			}
			if ( 'category' === $pg['role'] ) {
				if ( '?' === $pg['category'] ) {
					$pages[ $i ]['category'] = $cat_page[ $pg['key'] ] ?? '';
				}
				if ( '' === $pages[ $i ]['category'] ) {
					self::err( $pg['_p'] . '.category', 'A category page needs category (a service category slug) or an entries.categories item whose page is this page.' );
				} elseif ( ! self::cat_exists( $ent, sanitize_title( $pages[ $i ]['category'] ) ) ) {
					self::err( $pg['_p'] . '.category', 'Unknown service category "' . $pages[ $i ]['category'] . '" (not in entries and not on the site).' );
				}
			}
			unset( $pages[ $i ]['_p'] );
		}
		return $pages;
	}

	private static function cat_exists( array $ent, string $slug ): bool {
		foreach ( $ent['categories'] as $c ) {
			if ( $c['slug'] === $slug ) {
				return true;
			}
		}
		return (bool) get_term_by( 'slug', $slug, Entries::CAT );
	}

	/* ------------------------------------------------------------------ entries */

	private static function entries( $in ): array {
		$out = [ 'categories' => [], 'services' => [], 'areas' => [] ];
		if ( [] === $in ) {
			return $out;
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'entries', 'must be an object { categories, services, areas }.' );
			return $out;
		}
		foreach ( array_keys( $in ) as $k ) {
			if ( ! isset( $out[ $k ] ) ) {
				self::warn( 'entries.' . $k, 'Unknown (categories, services and areas are used).' );
			}
		}
		$cat_names = [];
		if ( isset( $in['categories'] ) ) {
			if ( ! self::is_list( $in['categories'] ) ) {
				self::err( 'entries.categories', 'must be a list of { name, slug, page, summary }.' );
			} else {
				foreach ( $in['categories'] as $i => $c ) {
					$p = 'entries.categories[' . $i . ']';
					$c = is_string( $c ) ? [ 'name' => $c ] : $c;
					if ( ! self::is_obj( $c ) ) {
						self::err( $p, 'must be a name or { name, slug, page }.' );
						continue;
					}
					$name = self::text( $c['name'] ?? null, $p . '.name', 120, true );
					if ( null === $name ) {
						continue;
					}
					$slug = sanitize_title( (string) self::text( $c['slug'] ?? null, $p . '.slug', 120 ) ?: $name );
					$out['categories'][] = [ 'name' => $name, 'slug' => $slug, 'page' => (string) self::text( $c['page'] ?? null, $p . '.page', 200 ), 'page_key' => '', 'order' => $i, 'summary' => (string) self::text( $c['summary'] ?? null, $p . '.summary', 500 ) ];
					$cat_names[ $slug ] = $name;
				}
			}
		}
		$out['services'] = self::services( $in['services'] ?? [] );
		foreach ( $out['services'] as $s ) {
			foreach ( $s['categories'] as $cn ) {
				if ( ! isset( $cat_names[ sanitize_title( $cn ) ] ) ) {
					$cat_names[ sanitize_title( $cn ) ] = $cn;
					$out['categories'][] = [ 'name' => $cn, 'slug' => sanitize_title( $cn ), 'page' => '', 'page_key' => '', 'order' => count( $out['categories'] ), 'summary' => '' ];
				}
			}
		}
		$out['areas'] = self::areas( $in['areas'] ?? [] );
		if ( count( $out['services'] ) + count( $out['areas'] ) > self::MAX_ENTRIES ) {
			self::err( 'entries', 'Too many entries (at most ' . self::MAX_ENTRIES . ' services and areas together).' );
		}
		return $out;
	}

	private static function faqs( $in, string $path ): array {
		if ( null === $in || [] === $in ) {
			return [];
		}
		if ( ! self::is_list( $in ) ) {
			self::err( $path, 'must be a list of { q, a }.' );
			return [];
		}
		$out = [];
		foreach ( $in as $i => $f ) {
			$q = is_array( $f ) ? ( $f['q'] ?? $f['question'] ?? null ) : null;
			$a = is_array( $f ) ? ( $f['a'] ?? $f['answer'] ?? null ) : null;
			$q = self::text( $q, $path . '[' . $i . '].q', 300, true );
			$a = self::text( $a, $path . '[' . $i . '].a', 2000, true );
			if ( null !== $q && null !== $a ) {
				$out[] = [ 'q' => $q, 'a' => $a ];
			}
		}
		if ( count( $out ) > 30 ) {
			self::err( $path, 'At most 30 questions per entry.' );
		}
		return $out;
	}

	/** Fields every entry spec shares. */
	private static function entry_common( array $e, string $p ): array {
		$out = [];
		$out['summary'] = (string) self::text( $e['summary'] ?? null, $p . '.summary', 600 );
		$out['markdown'] = (string) self::long_text( $e['markdown'] ?? null, $p . '.markdown' );
		$out['icon'] = '';
		if ( isset( $e['icon'] ) ) {
			if ( is_string( $e['icon'] ) && ( '' === $e['icon'] || '' !== Entries::clean_icon( $e['icon'] ) ) ) {
				$out['icon'] = $e['icon'];
			} else {
				self::err( $p . '.icon', 'must be a Kadence icon name (letters, digits and _), e.g. fe_settings.' );
			}
		}
		$out['faqs'] = self::faqs( $e['faqs'] ?? null, $p . '.faqs' );
		$out['seo'] = self::page_seo( $e['seo'] ?? null, $p . '.seo' );
		$out['image'] = (string) self::text( $e['image'] ?? null, $p . '.image', 500 );
		$status = $e['status'] ?? '';
		if ( '' !== $status && ( ! is_string( $status ) || ! in_array( $status, [ 'draft', 'publish' ], true ) ) ) {
			self::err( $p . '.status', 'must be draft or publish.' );
			$status = '';
		}
		$out['status'] = (string) $status;
		$content = $e['content'] ?? '';
		if ( '' !== $content && 'keep' !== $content && 'build' !== $content ) {
			self::err( $p . '.content', 'must be "keep" or "build".' );
			$content = '';
		}
		$out['content'] = '' !== $content ? $content : 'build';
		return $out;
	}

	private static function services( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_list( $in ) ) {
			self::err( 'entries.services', 'must be a list of services.' );
			return [];
		}
		$flat = [];
		foreach ( $in as $i => $s ) {
			$p = 'entries.services[' . $i . ']';
			if ( ! self::is_obj( $s ) ) {
				self::err( $p, 'must be an object { title, category, summary, markdown }.' );
				continue;
			}
			if ( isset( $s['items'] ) ) { // A category group: { category: "Cooling", items: [...] }.
				$cat = (string) self::text( $s['category'] ?? $s['name'] ?? null, $p . '.category', 120, true );
				if ( ! self::is_list( $s['items'] ) ) {
					self::err( $p . '.items', 'must be a list of services.' );
					continue;
				}
				foreach ( $s['items'] as $j => $it ) {
					if ( self::is_obj( $it ) ) {
						$it += [ 'category' => $cat ];
						$flat[] = [ $it, $p . '.items[' . $j . ']' ];
					} else {
						self::err( $p . '.items[' . $j . ']', 'must be an object.' );
					}
				}
				continue;
			}
			$flat[] = [ $s, $p ];
		}
		$out = [];
		$slugs = [];
		$keys = [];
		foreach ( $flat as $n => [ $s, $p ] ) {
			$title = self::text( $s['title'] ?? null, $p . '.title', 200, true );
			if ( null === $title ) {
				continue;
			}
			$slug = sanitize_title( (string) self::text( $s['slug'] ?? null, $p . '.slug', 200 ) ?: $title );
			$key = self::key( $s['key'] ?? null, $p . '.key' );
			$key = '' !== $key ? $key : 'svc:' . $slug;
			if ( isset( $slugs[ $slug ] ) ) {
				self::err( $p . '.slug', 'Two services have the slug "' . $slug . '".' );
			}
			if ( isset( $keys[ $key ] ) ) {
				self::err( $p . '.key', 'The key "' . $key . '" is used twice.' );
			}
			$slugs[ $slug ] = true;
			$keys[ $key ] = true;
			$cats = [];
			foreach ( [ 'categories', 'category' ] as $ck ) {
				if ( isset( $s[ $ck ] ) ) {
					foreach ( (array) $s[ $ck ] as $cn ) {
						$t = self::text( $cn, $p . '.' . $ck, 120 );
						if ( $t ) {
							$cats[] = $t;
						}
					}
				}
			}
			$row = [ 'key' => $key, 'title' => $title, 'slug' => $slug, 'categories' => array_values( array_unique( $cats ) ), 'order' => isset( $s['order'] ) && is_numeric( $s['order'] ) ? (int) $s['order'] : $n ] + self::entry_common( $s, $p );
			$row['_p'] = $p;
			$out[] = $row;
		}
		return $out;
	}

	private static function areas( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_list( $in ) ) {
			self::err( 'entries.areas', 'must be a list of areas.' );
			return [];
		}
		$flat = [];
		self::area_list( $in, 'entries.areas', '', '', 1, $flat );
		$paths = [];
		$keys = [];
		foreach ( $flat as $a ) {
			if ( isset( $paths[ $a['path'] ] ) ) {
				self::err( $a['_p'], 'Two areas have the path "' . $a['path'] . '".' );
			}
			if ( isset( $keys[ $a['key'] ] ) ) {
				self::err( $a['_p'] . '.key', 'The key "' . $a['key'] . '" is used twice.' );
			}
			$paths[ $a['path'] ] = true;
			$keys[ $a['key'] ] = true;
		}
		return $flat;
	}

	private static function area_list( array $list, string $path, string $parent_path, string $parent_key, int $depth, array &$flat ): void {
		if ( $depth > self::MAX_DEPTH ) {
			self::err( $path, 'Areas are nested more than ' . self::MAX_DEPTH . ' levels deep.' );
			return;
		}
		foreach ( $list as $i => $a ) {
			$p = $path . '[' . $i . ']';
			$a = is_string( $a ) ? [ 'title' => $a ] : $a;
			if ( ! self::is_obj( $a ) ) {
				self::err( $p, 'must be a title or an object { title, parent, services, summary, markdown }.' );
				continue;
			}
			$title = self::text( $a['title'] ?? null, $p . '.title', 200, true );
			if ( null === $title ) {
				continue;
			}
			$slug = sanitize_title( (string) self::text( $a['slug'] ?? null, $p . '.slug', 200 ) ?: $title );
			$ppath = $parent_path;
			$pref = '';
			if ( '' === $ppath && isset( $a['parent'] ) ) {
				$pref = (string) self::text( $a['parent'], $p . '.parent', 200 ); // Resolved to a key later.
			}
			$full = ( '' !== $ppath ? $ppath . '/' : '' ) . $slug;
			$key = self::key( $a['key'] ?? null, $p . '.key' );
			$services = [];
			if ( isset( $a['services'] ) ) {
				if ( ! self::is_list( $a['services'] ) ) {
					self::err( $p . '.services', 'must be a list of service titles, slugs or keys.' );
				} else {
					foreach ( $a['services'] as $s ) {
						$t = self::text( $s, $p . '.services', 200 );
						if ( $t ) {
							$services[] = $t;
						}
					}
				}
			}
			$row = [
				'key'        => '' !== $key ? $key : 'area:' . $full,
				'title'      => $title,
				'slug'       => $slug,
				'path'       => $full,
				'parent_key' => $parent_key,
				'parent_ref' => $pref,
				'depth'      => $depth,
				'order'      => isset( $a['order'] ) && is_numeric( $a['order'] ) ? (int) $a['order'] : (int) $i,
				'services'   => $services,
				'has_services' => isset( $a['services'] ),
				'_p'         => $p,
			] + self::entry_common( $a, $p );
			$flat[] = $row;
			if ( isset( $a['children'] ) ) {
				if ( self::is_list( $a['children'] ) ) {
					self::area_list( $a['children'], $p . '.children', $full, $row['key'], $depth + 1, $flat );
				} else {
					self::err( $p . '.children', 'must be a list of areas.' );
				}
			}
		}
	}

	/** Resolve category page refs, area parents and the services an area offers. */
	private static function resolve_entries( array $ent, array $page_ref ): array {
		foreach ( $ent['categories'] as $i => $c ) {
			if ( '' !== $c['page'] ) {
				$k = $page_ref[ $c['page'] ] ?? null;
				if ( null === $k ) {
					$ex = get_page_by_path( trim( $c['page'], '/' ), OBJECT, 'page' );
					if ( ! $ex ) {
						self::err( 'entries.categories[' . $i . '].page', 'No page "' . $c['page'] . '" in the sitemap or on the site.' );
					}
					$ent['categories'][ $i ]['page_key'] = '';
				} else {
					$ent['categories'][ $i ]['page_key'] = $k;
				}
			}
		}
		$svc = [];
		foreach ( $ent['services'] as $s ) {
			$svc[ $s['key'] ] = $s['key'];
			$svc[ $s['slug'] ] = $s['key'];
			$svc[ strtolower( $s['title'] ) ] = $s['key'];
		}
		$by_key = [];
		$by_path = [];
		foreach ( $ent['areas'] as $a ) {
			$by_key[ $a['key'] ] = $a;
			$by_path[ $a['path'] ] = $a['key'];
		}
		foreach ( $ent['areas'] as $i => $a ) {
			if ( '' !== $a['parent_ref'] ) {
				$k = $by_key[ $a['parent_ref'] ]['key'] ?? ( $by_path[ trim( $a['parent_ref'], '/' ) ] ?? null );
				if ( null === $k ) {
					$site = Entries::area_id( $a['parent_ref'] ); // An area that is on the site already.
					$k = $site ? self::site_key( $site ) : null;
				}
				if ( null === $k ) {
					self::err( $a['_p'] . '.parent', 'No area "' . $a['parent_ref'] . '" in entries.areas or on the site.' );
				} elseif ( $k === $a['key'] ) {
					self::err( $a['_p'] . '.parent', 'An area cannot be its own parent.' );
				} else {
					$ent['areas'][ $i ]['parent_key'] = $k;
				}
			}
			$keys = [];
			foreach ( $a['services'] as $ref ) {
				$k = $svc[ $ref ] ?? ( $svc[ sanitize_title( $ref ) ] ?? ( $svc[ strtolower( $ref ) ] ?? null ) );
				if ( null === $k ) {
					$site = Entries::service_id( $ref ); // A service that is on the site already.
					$k = $site ? self::site_key( $site ) : null;
				}
				if ( null === $k ) {
					self::err( $a['_p'] . '.services', 'Unknown service "' . $ref . '" (it must be in entries.services or on the site).' );
				} else {
					$keys[] = $k;
				}
			}
			$ent['areas'][ $i ]['services'] = array_values( array_unique( $keys ) );
			unset( $ent['areas'][ $i ]['parent_ref'] );
		}
		// Parents first: the builder creates areas in this order.
		$depth = [];
		$order = [];
		foreach ( $ent['areas'] as $i => $a ) {
			$d = 1;
			$k = $a['parent_key'];
			$guard = 0;
			while ( '' !== $k && $guard++ < 12 ) {
				$d++;
				$k = $by_key[ $k ]['parent_key'] ?? '';
			}
			if ( $guard >= 12 ) {
				self::err( $a['_p'] . '.parent', 'The parents of this area loop.' );
			}
			$depth[ $i ] = $d;
			$order[ $i ] = $i;
		}
		uksort( $order, static function ( $x, $y ) use ( $depth ) {
			return $depth[ $x ] <=> $depth[ $y ] ?: $x <=> $y;
		} );
		$sorted = [];
		foreach ( array_keys( $order ) as $i ) {
			$a = $ent['areas'][ $i ];
			$a['depth'] = $depth[ $i ];
			$sorted[] = $a;
		}
		$ent['areas'] = $sorted;
		foreach ( [ 'services', 'areas' ] as $k ) {
			foreach ( $ent[ $k ] as $i => $row ) {
				unset( $ent[ $k ][ $i ]['_p'] );
			}
		}
		return $ent;
	}

	/* ------------------------------------------------------------------ content, forms, launch, legal */

	/** Content keys a design reads, with the designs that read them. Every other key is accepted and stored, and warns. */
	const CONTENT_USED = [
		'testimonials' => 'the testimonial carousel and grid designs (the shared before-footer stack, the reviews page)',
		'offers'       => 'the offers design (the shared before-footer stack)',
	];

	/** One testimonial: { name, place, text, rating, source, service } (aliases: quote, author, city, location). */
	private static function testimonial( $item, string $p ): ?array {
		if ( is_string( $item ) ) {
			$item = [ 'text' => $item ];
		}
		if ( ! self::is_obj( $item ) ) {
			self::err( $p, 'must be { name, place, text, rating } or the quote text.' );
			return null;
		}
		$pick = static function ( array $names ) use ( $item ) {
			foreach ( $names as $n ) {
				if ( isset( $item[ $n ] ) ) {
					return $item[ $n ];
				}
			}
			return null;
		};
		$known = [ 'name', 'author', 'place', 'city', 'location', 'text', 'quote', 'rating', 'source', 'service' ];
		foreach ( array_diff( array_keys( $item ), $known ) as $k ) {
			self::warn( $p . '.' . $k, 'Unknown testimonial key; ignored. Keys: name, place, text, rating, source, service.' );
		}
		$text = self::text( $pick( [ 'text', 'quote' ] ), $p . '.text', 600, true );
		$name = self::text( $pick( [ 'name', 'author' ] ), $p . '.name', 120 ); // A quote without a name is fine: the card shows the quote.
		$out = [ 'name' => (string) $name, 'place' => (string) self::text( $pick( [ 'place', 'city', 'location' ] ), $p . '.place', 120 ), 'text' => (string) $text, 'rating' => null, 'source' => (string) self::text( $item['source'] ?? null, $p . '.source', 60 ), 'service' => (string) self::text( $item['service'] ?? null, $p . '.service', 120 ) ];
		if ( isset( $item['rating'] ) && '' !== $item['rating'] ) {
			if ( is_numeric( $item['rating'] ) && (float) $item['rating'] >= 1 && (float) $item['rating'] <= 5 ) {
				$out['rating'] = (float) $item['rating'];
			} else {
				self::err( $p . '.rating', 'must be a number from 1 to 5.' );
			}
		}
		return $out;
	}

	/** One offer: { title, amount, text, expires, button, url } (aliases: price, discount, description, ends, cta, link). */
	private static function offer( $item, string $p ): ?array {
		if ( ! self::is_obj( $item ) || [] === $item ) {
			self::err( $p, 'must be { title, amount, text, expires } (for example { "amount": "$50 off", "title": "A first service call", "text": "New customers, one per home.", "expires": "2027-03-31" }).' );
			return null;
		}
		$pick = static function ( array $names ) use ( $item ) {
			foreach ( $names as $n ) {
				if ( isset( $item[ $n ] ) ) {
					return $item[ $n ];
				}
			}
			return null;
		};
		$known = [ 'title', 'amount', 'price', 'discount', 'text', 'description', 'conditions', 'expires', 'ends', 'button', 'cta', 'url', 'link' ];
		foreach ( array_diff( array_keys( $item ), $known ) as $k ) {
			self::warn( $p . '.' . $k, 'Unknown offer key; ignored. Keys: title, amount, text, expires, button, url.' );
		}
		$out = [
			'title'  => (string) self::text( $pick( [ 'title' ] ), $p . '.title', 160 ),
			'amount' => (string) self::text( $pick( [ 'amount', 'price', 'discount' ] ), $p . '.amount', 60 ),
			'text'   => (string) self::text( $pick( [ 'text', 'description', 'conditions' ] ), $p . '.text', 300 ),
			'expires' => '',
			'button' => (string) self::text( $pick( [ 'button', 'cta' ] ), $p . '.button', 60 ),
			'url'    => (string) self::text( $pick( [ 'url', 'link' ] ), $p . '.url', 500 ),
		];
		if ( '' === $out['title'] && '' === $out['text'] ) { // The card needs words: a title or the conditions.
			self::err( $p, 'needs a title or a text (the conditions of the offer).' );
		}
		$ex = $pick( [ 'expires', 'ends' ] );
		if ( null !== $ex && '' !== $ex ) {
			$ts = is_string( $ex ) ? strtotime( $ex ) : false;
			$ts ? $out['expires'] = gmdate( 'Y-m-d', $ts ) : self::err( $p . '.expires', 'must be a date such as 2027-03-31.' );
		}
		return $out;
	}

	private static function content( $in, array &$deferred ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'content', 'must be an object.' );
			return [];
		}
		$out = [];
		foreach ( $in as $k => $v ) {
			$k = (string) $k;
			$p = 'content.' . $k;
			if ( in_array( $k, self::CONTENT_KEYS, true ) ) {
				if ( ! self::is_list( $v ) ) {
					self::err( $p, 'must be a list.' );
					continue;
				}
				if ( 'testimonials' === $k || 'offers' === $k ) {
					$rows = [];
					foreach ( $v as $i => $item ) {
						$one = 'offers' === $k ? self::offer( $item, $p . '[' . $i . ']' ) : self::testimonial( $item, $p . '[' . $i . ']' );
						null === $one ?: $rows[] = $one;
					}
					$out[ $k ] = $rows;
					continue;
				}
				foreach ( $v as $i => $item ) {
					if ( ! is_array( $item ) && ! is_string( $item ) ) {
						self::err( $p . '[' . $i . ']', 'must be an object or text.' );
					}
				}
				$out[ $k ] = $v;
			} elseif ( 'faqs' === $k ) {
				if ( ! self::is_obj( $v ) ) {
					self::err( $p, 'must be { global: [ { q, a } ] }.' );
					continue;
				}
				$out['faqs'] = [ 'global' => self::faqs( $v['global'] ?? null, $p . '.global' ) ];
			} elseif ( in_array( $k, [ 'financing', 'stats' ], true ) ) {
				self::is_obj( $v ) ? $out[ $k ] = $v : self::err( $p, 'must be an object.' );
			} else {
				self::warn( $p, 'Unknown content key; ignored.' );
			}
		}
		foreach ( array_keys( $out ) as $k ) {
			if ( ! isset( self::CONTENT_USED[ $k ] ) ) {
				$deferred[ 'content.' . $k ] = 'content.' . $k . ' is accepted but nothing uses it yet, so nothing is written to the site.';
				self::warn( 'content.' . $k, 'content.' . $k . ' is accepted but nothing uses it yet: nothing is written to the site for it.' );
			}
		}
		return $out;
	}

	private static function forms( $in, array &$deferred ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'forms', 'must be an object { quote, contact, booking, careers, fallback }.' );
			return [];
		}
		$out = [];
		foreach ( $in as $k => $v ) {
			$k = (string) $k;
			$p = 'forms.' . $k;
			if ( in_array( $k, [ 'quote', 'contact', 'booking', 'careers' ], true ) ) {
				$v = is_string( $v ) ? [ 'embed' => $v ] : $v;
				if ( ! self::is_obj( $v ) ) {
					self::err( $p, 'must be the form embed code or { embed, url }.' );
					continue;
				}
				$one = [];
				if ( isset( $v['embed'] ) ) {
					is_string( $v['embed'] ) && strlen( $v['embed'] ) <= 20000 ? $one['embed'] = trim( $v['embed'] ) : self::err( $p . '.embed', 'must be the embed code (text, up to 20 KB).' );
				}
				if ( isset( $v['url'] ) ) {
					is_string( $v['url'] ) && '' !== trim( $v['url'] ) ? $one['url'] = trim( $v['url'] ) : self::err( $p . '.url', 'must be a URL or path.' );
				}
				$out[ $k ] = $one;
			} elseif ( 'fallback' === $k ) {
				in_array( $v, [ 'kadence', 'none' ], true ) ? $out['fallback'] = $v : self::err( $p, 'must be "kadence" or "none".' );
			} else {
				self::warn( $p, 'Unknown form; ignored.' );
			}
		}
		foreach ( [ 'contact', 'booking', 'careers' ] as $k ) {
			if ( isset( $out[ $k ]['embed'] ) && ( 'contact' !== $k || isset( $out['quote']['embed'] ) ) ) {
				$deferred[ 'forms.' . $k ] = 'Form patterns read one embed (Business Info, taken from forms.quote, else contact, booking, careers); a separate embed per form is not placed yet.';
				self::warn( 'forms.' . $k, 'forms.' . $k . ' is accepted but nothing uses it yet: form patterns read the one embed in Business Info (forms.quote, else contact, booking, careers).' );
			}
		}
		return $out;
	}

	private static function launch( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'launch', 'must be an object { indexing }.' );
			return [];
		}
		$out = [];
		if ( array_key_exists( 'indexing', $in ) ) {
			is_bool( $in['indexing'] ) ? $out['indexing'] = $in['indexing'] : self::err( 'launch.indexing', 'must be true or false.' );
		}
		return $out;
	}

	/** legal: privacy / terms / accessibility { generate, markdown, reviewed, title, slug } and sms_consent. */
	private static function legal( $in, array $pages, array $business ): array {
		$out = [];
		if ( [] === $in ) {
			return [ 'legal' => [], 'pages' => $pages ];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'legal', 'must be an object { privacy, terms, accessibility, sms_consent }.' );
			return [ 'legal' => [], 'pages' => $pages ];
		}
		$sms = $in['sms_consent'] ?? false;
		if ( ! is_bool( $sms ) && ! self::is_obj( $sms ) ) {
			self::err( 'legal.sms_consent', 'must be true, false or { program, frequency, help }.' );
			$sms = false;
		}
		$out['sms_consent'] = $sms;
		$privacy_path = '/privacy-policy/';
		foreach ( Launch_Legal::TYPES as $t ) {
			if ( ! isset( $in[ $t ] ) ) {
				continue;
			}
			$p = 'legal.' . $t;
			$spec = true === $in[ $t ] ? [ 'generate' => true ] : $in[ $t ];
			if ( ! self::is_obj( $spec ) ) {
				self::err( $p, 'must be true or { generate, markdown, reviewed, title, slug }.' );
				continue;
			}
			$md = self::long_text( $spec['markdown'] ?? null, $p . '.markdown' );
			$gen = ! empty( $spec['generate'] ) || null === $md;
			if ( null !== $md && ! empty( $spec['generate'] ) ) {
				self::warn( $p, 'Both generate and markdown given: the markdown is used.' );
				$gen = false;
			}
			$reviewed = null !== $md || ! empty( $spec['reviewed'] );
			$page = Launch_Legal::page( $t );
			$title = (string) ( self::text( $spec['title'] ?? null, $p . '.title', 200 ) ?: $page['title'] );
			$slug = sanitize_title( (string) ( self::text( $spec['slug'] ?? null, $p . '.slug', 200 ) ?: $page['slug'] ) );
			$idx = null;
			foreach ( $pages as $i => $pg ) {
				if ( $pg['recipe'] === $t || $pg['key'] === 'legal:' . $t ) {
					$idx = $i;
					break;
				}
			}
			if ( null === $idx ) {
				$pages[] = [ 'key' => 'legal:' . $t, 'title' => $title, 'slug' => $slug, 'path' => $slug, 'parent_key' => '', 'role' => 'legal', 'recipe' => $t, 'type' => '', 'sections' => [], 'markdown' => '', 'replace' => [], 'layout' => [], 'seo' => [], 'status' => '', 'front' => false, 'order' => 900 + count( $pages ), 'content' => 'build', 'category' => '', '_p' => $p ];
				$idx = count( $pages ) - 1;
				foreach ( array_slice( $pages, 0, $idx ) as $other ) {
					if ( $other['path'] === $slug ) {
						self::err( $p . '.slug', 'A page with the path "' . $slug . '" exists in the sitemap: give the legal page another slug or put recipe ' . $t . ' on that page.' );
					}
				}
			}
			if ( 'privacy' === $t ) {
				$privacy_path = '/' . $pages[ $idx ]['path'] . '/';
			}
			$pages[ $idx ]['legal'] = [ 'type' => $t, 'generate' => $gen, 'reviewed' => $reviewed ];
			if ( ! $gen ) {
				$pages[ $idx ]['markdown'] = (string) $md;
			}
			$pages[ $idx ]['role'] = 'legal';
			$pages[ $idx ]['content'] = 'build';
			$out[ $t ] = [ 'generate' => $gen, 'reviewed' => $reviewed ];
		}
		if ( $out && ( empty( $business['email'] ) || empty( $business['phone'] ) ) && array_intersect_key( $out, array_flip( Launch_Legal::TYPES ) ) ) {
			self::warn( 'legal', 'Business Info has no email or phone yet: the contact lines of the legal texts show demo values until it does.' );
		}
		// Generated text is built now so the page hash and the copy checks see exactly what is written.
		foreach ( $pages as $i => $pg ) {
			if ( ! empty( $pg['legal']['generate'] ) ) {
				$pages[ $i ]['markdown'] = Launch_Legal::markdown( $pg['legal']['type'], $sms, $privacy_path );
			}
		}
		return [ 'legal' => $out, 'pages' => $pages ];
	}

	/* ------------------------------------------------------------------ chrome, urls, conversions */

	private static function chrome( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'chrome', 'must be an object (the chrome map, optionally with mode).' );
			return [];
		}
		$mode = null;
		if ( isset( $in['mode'] ) ) {
			if ( is_string( $in['mode'] ) && in_array( $in['mode'], [ 'auto', 'elements', 'patterns' ], true ) ) {
				$mode = $in['mode'];
			} else {
				self::err( 'chrome.mode', 'must be auto, elements or patterns.' );
			}
			unset( $in['mode'] );
		}
		$map = isset( $in['map'] ) && self::is_obj( $in['map'] ) && 1 === count( $in ) ? $in['map'] : $in;
		[ $clean, $errors ] = Chrome::validate_map( $map );
		foreach ( $errors as $e ) {
			self::err( 'chrome', $e );
		}
		$out = [];
		if ( $clean ) {
			$out['map'] = $clean;
		}
		if ( null !== $mode ) {
			$out['mode'] = $mode;
		}
		return $out;
	}

	private static function urls( $urls, $migrate, array &$off ): array {
		$out = [];
		$has = ( is_array( $urls ) && $urls ) || ( is_array( $migrate ) && $migrate );
		if ( ! $has ) {
			return [];
		}
		if ( ! Modules::enabled( Permalinks::class ) ) {
			$off[] = [ 'section' => 'urls', 'module' => 'permalinks', 'note' => 'The Permalinks module is switched off: urls and migrate are not applied (switch it on under Launch Builder > Modules).' ];
		}
		if ( [] !== $urls && ! self::is_obj( $urls ) ) {
			self::err( 'urls', 'must be an object { permastructures, redirects, old_sitemap_url, settings }.' );
			$urls = [];
		}
		if ( [] !== $migrate && ! self::is_obj( $migrate ) ) {
			self::err( 'migrate', 'must be an object { old_sitemap_url, redirects }.' );
			$migrate = [];
		}
		foreach ( array_keys( (array) $urls ) as $k ) {
			if ( ! in_array( $k, [ 'permastructures', 'redirects', 'old_sitemap_url', 'settings' ], true ) ) {
				self::warn( 'urls.' . $k, 'Ignored (permastructures, redirects, old_sitemap_url and settings are used).' );
			}
		}
		if ( isset( $urls['permastructures'] ) ) {
			if ( ! self::is_obj( $urls['permastructures'] ) ) {
				self::err( 'urls.permastructures', 'must be an object post type or taxonomy => structure.' );
			} else {
				foreach ( $urls['permastructures'] as $t => $st ) {
					if ( ! is_string( $st ) ) {
						self::err( 'urls.permastructures.' . $t, 'must be a structure such as /%postname%/.' );
					} else {
						$out['permastructures'][ (string) $t ] = $st;
					}
				}
			}
		}
		if ( isset( $urls['settings'] ) ) {
			self::is_obj( $urls['settings'] ) ? $out['settings'] = $urls['settings'] : self::err( 'urls.settings', 'must be an object (see set-permalink-settings).' );
		}
		$redirects = [];
		foreach ( [ 'urls' => $urls, 'migrate' => $migrate ] as $sec => $src ) {
			if ( isset( $src['redirects'] ) ) {
				if ( ! self::is_list( $src['redirects'] ) ) {
					self::err( $sec . '.redirects', 'must be a list of { from, to, code }.' );
					continue;
				}
				foreach ( $src['redirects'] as $i => $r ) {
					$p = $sec . '.redirects[' . $i . ']';
					if ( ! self::is_obj( $r ) || ! isset( $r['from'], $r['to'] ) || ! is_string( $r['from'] ) || ! is_string( $r['to'] ) || '' === trim( $r['from'] ) ) {
						self::err( $p, 'must be { from, to, code }: from and to are paths, URLs, post:ID or term:ID.' );
						continue;
					}
					$code = isset( $r['code'] ) ? (int) $r['code'] : 301;
					if ( ! in_array( $code, [ 301, 302, 307, 308, 410 ], true ) ) {
						self::err( $p . '.code', 'must be 301, 302, 307, 308 or 410.' );
						continue;
					}
					$redirects[] = [ 'from' => trim( $r['from'] ), 'to' => trim( $r['to'] ), 'code' => $code ];
				}
			}
			if ( isset( $src['old_sitemap_url'] ) ) {
				if ( is_string( $src['old_sitemap_url'] ) && wp_http_validate_url( $src['old_sitemap_url'] ) ) {
					$out['old_sitemap_url'] = $src['old_sitemap_url'];
				} else {
					self::err( $sec . '.old_sitemap_url', 'must be the full URL of the old site\'s XML sitemap.' );
				}
			}
		}
		if ( $redirects ) {
			$out['redirects'] = $redirects;
		}
		return $out;
	}

	/**
	 * branding { enabled, dashboard_colors { scheme, scope }, agency_credit, agency { name, url }, site_owner_role }: switches the
	 * Branding module on or off and saves the settings it names. Returns { enabled: bool|null, settings: Branding settings tree }.
	 */
	private static function branding( $in ): array {
		$out = [ 'enabled' => null, 'settings' => [] ];
		if ( [] === $in || null === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'branding', 'must be an object { enabled, dashboard_colors, agency_credit, agency, site_owner_role }.' );
			return [];
		}
		foreach ( array_diff( array_keys( $in ), [ 'enabled', 'dashboard_colors', 'agency_credit', 'agency', 'site_owner_role' ] ) as $k ) {
			self::warn( 'branding.' . $k, 'Unknown branding key; ignored. Keys: enabled, dashboard_colors, agency_credit, agency, site_owner_role.' );
		}
		if ( array_key_exists( 'enabled', $in ) ) {
			is_bool( $in['enabled'] ) ? $out['enabled'] = $in['enabled'] : self::err( 'branding.enabled', 'must be true or false.' );
		}
		if ( isset( $in['dashboard_colors'] ) ) {
			$c = $in['dashboard_colors'];
			if ( ! self::is_obj( $c ) ) {
				self::err( 'branding.dashboard_colors', 'must be { scheme, scope }.' );
			} else {
				if ( isset( $c['scheme'] ) ) {
					in_array( $c['scheme'], Branding_Colors::SCHEME_IDS, true ) ? $out['settings']['admin_colors']['scheme'] = $c['scheme'] : self::err( 'branding.dashboard_colors.scheme', 'must be one of ' . implode( ', ', array_filter( Branding_Colors::SCHEME_IDS ) ) . ', or "" for no ready-made scheme (the WordPress default colours). "brand" is the colours of the brand.' );
				}
				if ( isset( $c['scope'] ) ) {
					in_array( $c['scope'], [ 'clients', 'everyone', 'nobody' ], true ) ? $out['settings']['admin_colors']['scope'] = $c['scope'] : self::err( 'branding.dashboard_colors.scope', 'must be clients, everyone or nobody.' );
				}
			}
		}
		if ( array_key_exists( 'agency_credit', $in ) ) {
			is_bool( $in['agency_credit'] ) ? $out['settings']['login']['credit'] = $in['agency_credit'] : self::err( 'branding.agency_credit', 'must be true or false (the "Website by" line on the sign-in screen).' );
		}
		if ( isset( $in['agency'] ) ) {
			if ( ! self::is_obj( $in['agency'] ) ) {
				self::err( 'branding.agency', 'must be { name, url }.' );
			} else {
				$name = self::text( $in['agency']['name'] ?? null, 'branding.agency.name', 80 );
				$url = self::text( $in['agency']['url'] ?? null, 'branding.agency.url', 300 );
				null !== $name && '' !== $name ? $out['settings']['agency']['name'] = $name : null;
				null !== $url && '' !== $url ? $out['settings']['agency']['url'] = $url : null;
			}
		}
		if ( array_key_exists( 'site_owner_role', $in ) ) {
			$r = $in['site_owner_role'];
			if ( is_bool( $r ) ) {
				$out['settings']['client']['role']['enabled'] = $r;
			} elseif ( self::is_obj( $r ) ) {
				$out['settings']['client']['role']['enabled'] = true;
				$label = self::text( $r['label'] ?? null, 'branding.site_owner_role.label', 40 );
				null !== $label && '' !== $label ? $out['settings']['client']['role']['label'] = $label : null;
			} else {
				self::err( 'branding.site_owner_role', 'must be true, false or { label }.' );
			}
		}
		return $out;
	}

	private static function conversions( $in, array &$off ) {
		if ( null === $in ) {
			return null; // Absent: the default exit-intent popup (Conversions::brief_items).
		}
		if ( false !== $in && ! is_array( $in ) ) {
			self::err( 'conversions', 'must be a list of items or { exit_intent, items }.' );
			return null;
		}
		if ( ! Modules::enabled( Conversions::class ) ) {
			$off[] = [ 'section' => 'conversions', 'module' => 'conversions', 'note' => 'The Conversions module is switched off: conversions are not applied.' ];
		}
		return $in;
	}

	/* ------------------------------------------------------------------ menus */

	private static function menus( $in, array $page_ref ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_obj( $in ) ) {
			self::err( 'menus', 'must be an object { main, footer, legal }.' );
			return [];
		}
		$out = [];
		foreach ( $in as $k => $v ) {
			$k = (string) $k;
			if ( 'header' === $k ) { // The header design (pattern slug).
				$all = Patterns::index();
				if ( is_string( $v ) && isset( $all[ sanitize_key( $v ) ] ) && in_array( 'launch-builder-headers', (array) ( $all[ sanitize_key( $v ) ]['categories'] ?? [] ), true ) ) {
					$out['header'] = sanitize_key( $v );
				} else {
					self::err( 'menus.header', 'must be a header pattern slug (list-patterns, category headers).' );
				}
				continue;
			}
			if ( ! in_array( $k, [ 'main', 'footer', 'legal' ], true ) ) {
				self::warn( 'menus.' . $k, 'Unknown menu (main, footer and legal are used).' );
				continue;
			}
			if ( ! self::is_list( $v ) ) {
				self::err( 'menus.' . $k, 'must be a list of menu items.' );
				continue;
			}
			$out[ $k ] = self::menu_items( $v, 'menus.' . $k, $page_ref, 1 );
		}
		return $out;
	}

	private static function menu_items( array $items, string $path, array $page_ref, int $depth ): array {
		$out = [];
		if ( $depth > 4 ) {
			self::err( $path, 'Menus are nested more than 4 levels deep.' );
			return [];
		}
		foreach ( $items as $i => $it ) {
			$p = $path . '[' . $i . ']';
			if ( ! self::is_obj( $it ) ) {
				self::err( $p, 'must be an object { label, page | url | auto }.' );
				continue;
			}
			// An auto list inside a dropdown (depth 2 or more; the top level is depth 1) needs no label: it adds its items to that dropdown (no extra link of its own).
			$splice = $depth > 1 && isset( $it['auto'] ) && ( ! isset( $it['label'] ) || '' === trim( (string) $it['label'] ) );
			$label = $splice ? '' : self::text( $it['label'] ?? null, $p . '.label', 120, true );
			if ( null === $label ) {
				continue;
			}
			$row = [ 'label' => $label ];
			if ( isset( $it['page'] ) ) {
				$ref = self::text( $it['page'], $p . '.page', 200 );
				if ( null !== $ref ) {
					$k = $page_ref[ $ref ] ?? ( $page_ref[ trim( $ref, '/' ) ] ?? null );
					if ( null !== $k ) {
						$row['page'] = $k;
					} elseif ( get_page_by_path( trim( $ref, '/' ), OBJECT, 'page' ) ) {
						$row['page'] = 'path:' . trim( $ref, '/' ); // A page that is already on the site.
					} else {
						self::err( $p . '.page', 'No page "' . $ref . '" in the sitemap or on the site.' );
					}
				}
			}
			if ( isset( $it['url'] ) ) {
				$u = self::text( $it['url'], $p . '.url', 500 );
				if ( $u ) {
					$row['url'] = $u;
				}
			}
			if ( isset( $it['auto'] ) ) {
				if ( in_array( $it['auto'], [ 'services', 'areas' ], true ) ) {
					$row['auto'] = $it['auto'];
					foreach ( [ 'category', 'parent' ] as $f ) {
						if ( isset( $it[ $f ] ) ) {
							$t = self::text( $it[ $f ], $p . '.' . $f, 200 );
							if ( $t ) {
								$row[ $f ] = $t;
							}
						}
					}
					if ( isset( $it['limit'] ) ) {
						is_numeric( $it['limit'] ) && (int) $it['limit'] >= 0 ? $row['limit'] = (int) $it['limit'] : self::err( $p . '.limit', 'must be 0 (all) or a number.' );
					}
				} else {
					self::err( $p . '.auto', 'must be "services" or "areas".' );
				}
			}
			if ( isset( $it['children'] ) ) {
				self::is_list( $it['children'] ) ? $row['children'] = self::menu_items( $it['children'], $p . '.children', $page_ref, $depth + 1 ) : self::err( $p . '.children', 'must be a list.' );
			}
			if ( ! isset( $row['page'] ) && ! isset( $row['url'] ) && ! isset( $row['auto'] ) && empty( $row['children'] ) ) {
				self::warn( $p, 'Has no page, url or children: it links nowhere.' );
			}
			if ( ! empty( $row['children'] ) ) { // The unlabelled auto lists of the dropdown become the parent's own list.
				$kids = [];
				foreach ( $row['children'] as $kid ) {
					if ( '' === $kid['label'] && isset( $kid['auto'] ) ) {
						if ( isset( $row['auto'] ) ) {
							self::warn( $p . '.children', 'This item already lists ' . $row['auto'] . ' itself, so a second automatic list in its dropdown is ignored.' );
						} else {
							$row = array_merge( $row, array_intersect_key( $kid, array_flip( [ 'auto', 'category', 'parent', 'limit' ] ) ) );
						}
						continue;
					}
					$kids[] = $kid;
				}
				$row['children'] = $kids;
				if ( ! $kids ) {
					unset( $row['children'] );
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ media */

	private static function media( $in ): array {
		if ( [] === $in ) {
			return [];
		}
		if ( ! self::is_list( $in ) ) {
			self::err( 'media', 'must be a list of { key, url, alt, use }.' );
			return [];
		}
		if ( count( $in ) > self::MAX_MEDIA ) {
			self::err( 'media', 'Too many media items (at most ' . self::MAX_MEDIA . ').' );
			return [];
		}
		$out = [];
		$keys = [];
		foreach ( $in as $i => $m ) {
			$p = 'media[' . $i . ']';
			if ( ! self::is_obj( $m ) ) {
				self::err( $p, 'must be { key, url, alt, use }.' );
				continue;
			}
			$key = self::key( $m['key'] ?? null, $p . '.key' );
			if ( '' === $key ) {
				self::err( $p . '.key', 'required.' );
				continue;
			}
			if ( isset( $keys[ $key ] ) ) {
				self::err( $p . '.key', 'The key "' . $key . '" is used twice.' );
				continue;
			}
			$keys[ $key ] = true;
			$url = self::text( $m['url'] ?? null, $p . '.url', 1000, true );
			if ( null !== $url && ( ! wp_http_validate_url( $url ) || ! preg_match( '#^https?://#i', $url ) ) ) {
				self::err( $p . '.url', 'must be a full http(s) URL of an image.' );
				continue;
			}
			$use = (string) self::text( $m['use'] ?? null, $p . '.use', 120 );
			if ( '' !== $use && ! preg_match( '/^(logo|logo_inverse|site_icon|og_image|featured:.+)$/', $use ) ) {
				self::err( $p . '.use', 'must be logo, logo_inverse, site_icon, og_image or featured:<page or entry key>.' );
				$use = '';
			}
			$out[] = [ 'key' => $key, 'url' => (string) $url, 'alt' => (string) self::text( $m['alt'] ?? null, $p . '.alt', 300 ), 'use' => $use ];
		}
		return $out;
	}

	/**
	 * Every media reference (brand, entries, SEO) must be a media key or a URL; URLs become implicit media items so the
	 * builder imports each image once. The media list is then final.
	 */
	private static function resolve_media( array $media, array &$brief, array &$deferred ): array {
		$by_key = [];
		$by_url = [];
		foreach ( $media as $m ) {
			$by_key[ $m['key'] ] = true;
			$by_url[ $m['url'] ] = $m['key'];
		}
		$ref = static function ( string $r, string $path ) use ( &$media, &$by_key, &$by_url ): string {
			if ( '' === $r ) {
				return '';
			}
			if ( isset( $by_key[ $r ] ) ) {
				return $r;
			}
			if ( preg_match( '#^https?://#i', $r ) && wp_http_validate_url( $r ) ) {
				if ( ! isset( $by_url[ $r ] ) ) {
					$key = 'url:' . substr( md5( $r ), 0, 10 );
					$media[] = [ 'key' => $key, 'url' => $r, 'alt' => '', 'use' => '' ];
					$by_key[ $key ] = true;
					$by_url[ $r ] = $key;
				}
				return $by_url[ $r ];
			}
			self::err( $path, 'Unknown media key "' . $r . '" (list it under media, or give the image URL).' );
			return '';
		};
		foreach ( [ 'logo', 'logo_inverse', 'site_icon' ] as $k ) {
			if ( isset( $brief['brand'][ $k ] ) ) {
				$brief['brand'][ $k ] = $ref( $brief['brand'][ $k ], 'brand.' . $k );
			}
		}
		if ( isset( $brief['brand']['logo_width'] ) ) {
			$deferred['brand.logo_width'] = 'Accepted; the logo width is a Customizer setting this version does not write.';
		}
		if ( isset( $brief['seo']['og_image'] ) ) {
			$brief['seo']['og_image'] = $ref( $brief['seo']['og_image'], 'seo.og_image' );
			$deferred['seo.og_image'] = 'Accepted and imported; the SEO integration has no writer for the default social image yet.';
		}
		foreach ( [ 'services', 'areas' ] as $t ) {
			foreach ( $brief['entries'][ $t ] as $i => $row ) {
				if ( '' !== $row['image'] ) {
					$brief['entries'][ $t ][ $i ]['image'] = $ref( $row['image'], 'entries.' . $t . '[' . $i . '].image' );
				}
			}
		}
		foreach ( $media as $i => $m ) {
			if ( 0 === strpos( $m['use'], 'featured:' ) ) {
				$target = substr( $m['use'], 9 );
				$found = false;
				foreach ( $brief['sitemap']['pages'] as $pg ) {
					$found = $found || $pg['key'] === $target || $pg['path'] === $target;
				}
				foreach ( [ 'services', 'areas' ] as $t ) {
					foreach ( $brief['entries'][ $t ] as $row ) {
						$found = $found || $row['key'] === $target;
					}
				}
				if ( ! $found ) {
					self::err( 'media[' . $i . '].use', 'No page or entry with the key "' . $target . '".' );
				}
			}
		}
		return $media;
	}
}
