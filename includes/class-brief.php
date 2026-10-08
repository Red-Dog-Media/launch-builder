<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The brief: client facts in, a site plan out. Today that is new-blueprint (a brief becomes a build-site blueprint with
 * the standard pages, menu, entries, chrome and design style). Modules describe the brief data they
 * consume through the module hook
 * brief_schema( array &$schema ) (see schema()).
 */
final class Brief {

	public static function define_tools(): void {
		Abilities::add( 'new-blueprint', 'Turn a client brief into a starter blueprint', 'Give the client brief and get back a complete blueprint for build-site: the standard pages (home, about, a page per service category and per service, service areas hub and city pages, contact, FAQ), the header menu with dropdowns, a global bottom stack (reviews, offers, service areas) and footer, brand palette derived from the colours, and a design style. Copy is left as [[bracketed]] placeholders and default pattern text for you to rewrite. brief: {name, city, state, industry, services:[{name, children:["Service", ...]}] (categories with their services, or a flat list), areas:["City", ...], colors:{primary, accent, dark} (hex), style:"bold"|"clean"|"soft", phone, email, address, hours, about, tone, keywords}.',
			[ 'properties' => [ 'name' => [ 'type' => 'string' ], 'city' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ], 'industry' => [ 'type' => 'string' ], 'services' => [ 'type' => 'array' ], 'areas' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'colors' => [ 'type' => 'object' ], 'style' => [ 'type' => 'string', 'enum' => [ 'bold', 'clean', 'soft' ] ], 'phone' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ], 'address' => [ 'type' => 'string' ], 'hours' => [ 'type' => 'string' ], 'about' => [ 'type' => 'string' ], 'tone' => [ 'type' => 'string' ], 'keywords' => [ 'type' => 'string' ] ], 'required' => [ 'name', 'city' ] ], [ __CLASS__, 'new_blueprint' ], 'edit_pages', [ 'readonly' => true ] );
	}

	/**
	 * The brief schema: the top-level sections as typed objects, then whatever each module adds. Not a
	 * tool input yet.
	 *
	 * @return array JSON schema (type object)
	 */
	public static function schema(): array {
		$section = static function ( string $desc ): array {
			return [ 'type' => 'object', 'description' => $desc, 'properties' => [] ];
		};
		$schema = [
			'type'       => 'object',
			'properties' => [
				'business' => $section( 'Business Info: every Company field (see get-company).' ),
				'brand'    => $section( 'palette or 2-3 colours, fonts {headings, body, weights}, logo, logo_inverse, site_icon, logo_width, style preset + tokens.' ),
				'seo'      => $section( 'Title/description templates per role, per-page SEO, schema type, og image, enable_local_seo.' ),
				'sitemap'  => $section( 'Pages tree with role, recipe/sections, markdown and slot values.' ),
				'entries'  => $section( 'Services by category (summary, markdown, icon, image, faqs) and the areas tree with the services offered.' ),
				'content'  => $section( 'testimonials, team, offers, guarantees, financing, badges, brands, faqs, stats, gallery, videos.' ),
				'media'    => [ 'type' => 'array', 'description' => '[{key, url, alt, use}] sideloaded once, keyed by URL.', 'items' => [ 'type' => 'object' ] ],
				'forms'    => $section( 'quote, contact, booking, careers, fallback.' ),
				'menus'    => $section( 'main, footer, legal; auto items.' ),
				'chrome'   => $section( 'Overrides of the chrome map (see Chrome::DEFAULT_MAP).' ),
				'urls'     => $section( 'URL bases for services, areas and categories.' ),
				'migrate'  => $section( 'old_sitemap_url, redirects.' ),
				'legal'    => $section( 'privacy / terms: generate or markdown; sms_consent.' ),
				'launch'   => $section( 'indexing (false while building).' ),
			],
		];
		foreach ( Plugin::modules( 'brief_schema' ) as $module ) {
			$module::brief_schema( $schema );
		}
		return $schema;
	}

	/* ------------------------------------------------------------------ new-blueprint */

	private static function mix( string $a, string $b, float $t ): string {
		$p = static function ( string $h ) { $h = ltrim( $h, '#' ); if ( 3 === strlen( $h ) ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; } return [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) ]; };
		[ $r1, $g1, $b1 ] = $p( $a );
		[ $r2, $g2, $b2 ] = $p( $b );
		return sprintf( '#%02X%02X%02X', (int) round( $r1 + ( $r2 - $r1 ) * $t ), (int) round( $g1 + ( $g2 - $g1 ) * $t ), (int) round( $b1 + ( $b2 - $b1 ) * $t ) );
	}

	/** Brand colours in, the nine-colour Kadence palette out. */
	public static function palette( array $c ): array {
		$hex = static function ( $v, $d ) { $v = sanitize_hex_color( (string) $v ); return $v ? $v : $d; };
		$primary = $hex( $c['primary'] ?? '', '#0F766E' );
		$dark = $hex( $c['dark'] ?? '', self::mix( $primary, '#000000', 0.8 ) );
		$accent = $hex( $c['accent'] ?? '', self::mix( $primary, '#000000', 0.2 ) );
		return [
			'palette1' => $primary, 'palette2' => $accent, 'palette3' => $dark,
			'palette4' => self::mix( $dark, '#FFFFFF', 0.18 ), 'palette5' => self::mix( $dark, '#FFFFFF', 0.38 ), 'palette6' => self::mix( $dark, '#FFFFFF', 0.58 ),
			'palette7' => self::mix( $primary, '#FFFFFF', 0.88 ), 'palette8' => self::mix( $primary, '#FFFFFF', 0.94 ), 'palette9' => '#FFFFFF',
		];
	}

	private static function styles(): array {
		return [
			'bold'  => [ 'header' => 'header-utility', 'footer' => 'footer-licensing',
				'buttons' => [ 'primary' => [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => 'palette9', 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true, 'shadow' => 'soft' ], 'secondary' => [ 'background' => 'palette3', 'color' => 'palette9', 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true ], 'outline' => [ 'color' => 'palette1', 'border_color' => 'palette1', 'border_width' => 2, 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true ] ],
				'dropdown' => [ 'background' => 'palette9', 'hover_background' => 'palette8', 'color' => 'palette3', 'hover_color' => 'palette1', 'width' => 280, 'radius' => 14, 'shadow' => 'strong', 'reveal' => 'fade-up', 'divider' => true, 'padding' => [ 13, 22 ], 'spacing' => 8 ], 'links' => [ 'color' => 'palette3', 'hover' => 'palette1', 'active' => 'palette1', 'style' => 'underline', 'weight' => 700 ], 'banner' => 220 ],
			'clean' => [ 'header' => 'header-minimal', 'footer' => 'footer-dark',
				'buttons' => [ 'primary' => [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => 'palette9', 'radius' => 8, 'padding' => 'md', 'weight' => 600, 'uppercase' => false, 'shadow' => 'none' ], 'secondary' => [ 'background' => 'palette7', 'color' => 'palette3', 'radius' => 8, 'padding' => 'md', 'weight' => 600 ], 'outline' => [ 'color' => 'palette1', 'border_color' => 'palette1', 'border_width' => 2, 'radius' => 8, 'padding' => 'md', 'weight' => 600 ] ],
				'dropdown' => [ 'background' => 'palette9', 'hover_background' => 'palette8', 'color' => 'palette3', 'hover_color' => 'palette1', 'width' => 250, 'radius' => 8, 'shadow' => 'soft', 'reveal' => 'fade', 'divider' => false, 'padding' => [ 11, 18 ], 'spacing' => 6 ], 'links' => [ 'color' => 'palette3', 'hover' => 'palette1', 'active' => 'palette1', 'style' => 'standard', 'weight' => 600 ], 'banner' => 170 ],
			'soft'  => [ 'header' => 'header-split', 'footer' => 'footer-light',
				'buttons' => [ 'primary' => [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => 'palette9', 'radius' => 'pill', 'padding' => 'md', 'weight' => 700, 'uppercase' => false, 'shadow' => 'soft' ], 'secondary' => [ 'background' => 'palette7', 'color' => 'palette3', 'radius' => 'pill', 'padding' => 'md', 'weight' => 700 ], 'outline' => [ 'color' => 'palette1', 'border_color' => 'palette1', 'border_width' => 2, 'radius' => 'pill', 'padding' => 'md', 'weight' => 700 ] ],
				'dropdown' => [ 'background' => 'palette9', 'hover_background' => 'palette8', 'color' => 'palette3', 'hover_color' => 'palette1', 'width' => 270, 'radius' => 18, 'shadow' => 'soft', 'reveal' => 'fade-up', 'divider' => false, 'padding' => [ 12, 20 ], 'spacing' => 8 ], 'links' => [ 'color' => 'palette3', 'hover' => 'palette1', 'active' => 'palette1', 'style' => 'underline', 'weight' => 600 ], 'banner' => 190 ],
		];
	}

	/** The standard H2 outline for a service page, as Markdown with [[placeholders]] the agent must rewrite. */
	private static function service_outline( string $svc, string $city, string $region, string $brand ): string {
		return "[[Two or three sentences introducing {$svc} in {$city}: who it is for, what the customer gets, and one local detail.]]\n\n"
			. "## Signs You Need {$svc}\n\n- **[[Sign one]]**: [[what the homeowner notices]]\n- **[[Sign two]]**: [[what the homeowner notices]]\n- **[[Sign three]]**: [[what the homeowner notices]]\n- **[[Sign four]]**: [[what the homeowner notices]]\n\n"
			. "[[One sentence that turns the signs into a call to action, with the phone link.]]\n\n"
			. "## Understanding {$svc} Conditions in {$region}\n\n[[Two short paragraphs on the local conditions (climate, water, codes, housing stock) that make this service matter here.]]\n\n"
			. "## How {$svc} Works\n\n1. **[[Step one]]**: [[what we do]]\n2. **[[Step two]]**: [[what we do]]\n3. **[[Step three]]**: [[what we do]]\n4. **[[Step four]]**: [[what we do]]\n\n"
			. "## Benefits of {$svc} in {$city}\n\n- **[[Benefit one]]**: [[one sentence]]\n- **[[Benefit two]]**: [[one sentence]]\n- **[[Benefit three]]**: [[one sentence]]\n- **[[Benefit four]]**: [[one sentence]]\n\n"
			. "## Why Choose {$brand} for {$svc}\n\n- **[[Reason one]]**: [[one sentence]]\n- **[[Reason two]]**: [[one sentence]]\n- **[[Reason three]]**: [[one sentence]]\n\n"
			. "[[A closing line that invites the reader to act.]] [Call {{lb.phone}}](#lb-phone_link) or [book online](#lb-cta_url).\n\n## Frequently Asked Questions\n\n### [[Question one about {$svc}?]]\n\n[[Answer: two or three sentences.]]\n\n### [[Question two?]]\n\n[[Answer.]]\n\n### [[Question three?]]\n\n[[Answer.]]";
	}

	private static function faq_replace( string $svc ): array {
		return [
			'Frequently asked questions' => 'Frequently Asked Questions',
			'Common question number 1?' => "[[How much does {$svc} cost?]]", 'Common question number 2?' => "[[How long does {$svc} take?]]",
			'Common question number 3?' => "[[Do you guarantee your {$svc} work?]]", 'Common question number 4?' => "[[What should I do before the visit?]]",
			'A clear, short answer. Two or three sentences is plenty.' => '[[A clear, short answer. Two or three sentences is plenty.]]',
		];
	}

	public static function new_blueprint( $input ) {
		$name = sanitize_text_field( $input['name'] ?? '' );
		$city = sanitize_text_field( $input['city'] ?? '' );
		if ( '' === $name || '' === $city ) {
			return new \WP_Error( 'lb_brief', 'name and city are required.', [ 'status' => 400 ] );
		}
		$state = sanitize_text_field( $input['state'] ?? '' );
		$place = $state ? "{$city}, {$state}" : $city;
		$industry = sanitize_text_field( $input['industry'] ?? 'home services' );
		$style = in_array( $input['style'] ?? '', [ 'bold', 'clean', 'soft' ], true ) ? $input['style'] : 'clean';
		$st = self::styles()[ $style ];
		// Shared page chrome: with Kadence Pro the hero and the breadcrumb bar are placed once as Elements for every page and entry; without it each page carries its own.
		$chrome = isset( $input['chrome'] ) && in_array( $input['chrome'], [ 'patterns', 'elements' ], true ) ? $input['chrome'] : ( Plugin::requirement_met( 'kadence-pro' ) ? 'elements' : 'patterns' );
		$el = 'elements' === $chrome;

		// Services: [{name, children:[...]}] categories, or a flat list of names.
		$cats = [];
		foreach ( (array) ( $input['services'] ?? [] ) as $s ) {
			$s = is_array( $s ) ? $s : [ 'name' => (string) $s ];
			$n = sanitize_text_field( $s['name'] ?? '' );
			if ( '' !== $n ) {
				$cats[] = [ 'name' => $n, 'children' => array_values( array_filter( array_map( 'sanitize_text_field', array_map( static function ( $c ) { return is_array( $c ) ? ( $c['name'] ?? '' ) : $c; }, (array) ( $s['children'] ?? [] ) ) ) ) ) ];
			}
		}
		$areas = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $input['areas'] ?? [] ) ) ) ), 0, 40 );
		$cats = array_slice( $cats, 0, 8 );

		// Services and areas are ENTRIES (post types): every list on the site, and the menu below, is generated from them.
		$entries = [ 'categories' => [], 'services' => [], 'areas' => [] ];
		$nav = [ [ 'label' => 'About', 'page' => 'about' ] ];
		$pages = [];
		$home = [ 'title' => 'Home', 'slug' => 'home', 'front' => true, 'type' => 'landing', 'seo' => [ 'title' => "{$industry} in {$place} | {$name}", 'description' => "[[One sentence, 150 characters: who {$name} is, what it does and where.]]" ], 'sections' => [] ];
		foreach ( Content::recipes()['home']['sections'] as $sec ) {
			$home['sections'][] = [ 'pattern' => $sec ];
		}
		$home['replace'] = [
			'A real person answers, day or night' => '[[Home page headline: the main promise in six to ten words]]',
			'Trusted services in Your City, ST and surrounding areas' => "[[Trusted {$industry} in {$place} and surrounding areas]]",
			'Live phone support  •  No voicemail  •  Open 7 days a week' => '[[Trust line: three short proof points separated by bullets]]',
		];
		$pages[] = $home;
		$pages[] = [ 'title' => 'About', 'slug' => 'about', 'recipe' => 'about', 'seo' => [ 'title' => "About {$name} | {$place}" ], 'replace' => [ 'Why choose us' => '[[Eyebrow, e.g. Locally owned]]', 'A heading that states the benefit' => "[[About heading: who {$name} is]]", 'Our approach' => '[[Eyebrow, e.g. Our promise]]' ] ];
		$todo = [ 'Rewrite every [[bracketed]] placeholder; delete a section you do not need.', 'Replace grey placeholder images (sideload-media, then image slots).', 'Rewrite the pattern copy that is still the default (get-pattern lists each slot).', 'Services and areas are entries: add more any time with add-service / add-area and every list, card and related row updates by itself. (The header menu is built once; add new entries to it with set-navigation.)' ];

		foreach ( $cats as $cat ) {
			$cslug = sanitize_title( $cat['name'] );
			$entries['categories'][] = [ 'name' => $cat['name'], 'slug' => $cslug, 'page' => $cslug ];
			$pages[] = [ 'title' => $cat['name'], 'slug' => $cslug, 'type' => 'landing', 'seo' => [ 'title' => "{$cat['name']} in {$place} | {$name}", 'description' => "[[One sentence about {$cat['name']} in {$place}.]]" ],
				'layout' => [ 'title' => $el ? 'show' : 'hide' ], // elements mode: the shared hero element is the title area
				'sections' => array_merge( $el ? [] : [ [ 'pattern' => 'hero-inner' ], [ 'pattern' => 'breadcrumb-bar' ] ], [ [ 'pattern' => 'services-dynamic-tiles', 'slots' => [ '@entries' => [ 'category' => $cslug ] ] ], [ 'pattern' => 'media-left' ], [ 'pattern' => 'checklist-cta' ], [ 'pattern' => 'faq' ] ] ),
				'replace' => array_merge( self::faq_replace( $cat['name'] ), [ 'Why choose us' => '[[Eyebrow]]', 'A heading that states the benefit' => "[[Why choose {$name} for {$cat['name']}]]" ] ) ];
			$item = [ 'label' => $cat['name'], 'page' => $cslug ];
			foreach ( $cat['children'] as $svc ) {
				$sslug = sanitize_title( $svc );
				$entries['services'][] = [ 'title' => $svc, 'slug' => $sslug, 'category' => $cat['name'], 'summary' => "[[One sentence about {$svc} in {$city}.]]", 'markdown' => self::service_outline( $svc, $city, $state ?: $city, $name ) ];
				$item['children'][] = [ 'label' => $svc, 'url' => '/services/' . $sslug . '/' ];
			}
			$nav[] = $item;
		}
		if ( $areas ) {
			$pages[] = [ 'title' => 'Service Areas', 'slug' => 'service-areas', 'recipe' => 'service-area', 'seo' => [ 'title' => "Service Areas | {$name}" ] ];
			$svc_names = [];
			foreach ( $cats as $cat ) {
				$svc_names = array_merge( $svc_names, $cat['children'] ? $cat['children'] : [ $cat['name'] ] );
			}
			$list = $svc_names ? '- ' . implode( "\n- ", array_slice( $svc_names, 0, 8 ) ) : '- [[Service]]';
			$area_item = [ 'label' => 'Service areas', 'page' => 'service-areas' ];
			foreach ( $areas as $i => $area ) {
				$aslug = sanitize_title( $area );
				$entries['areas'][] = [ 'title' => $area, 'slug' => $aslug, 'order' => $i, 'summary' => "[[One sentence about {$industry} in {$area}.]]",
					'markdown' => "[[Two sentences introducing {$name} in {$area}: how long you have served it and one local detail.]]\n\n## Services We Offer in {$area}\n\n{$list}\n\n## Why {$area} Homeowners Choose {$name}\n\n- **[[Reason one]]**: [[one sentence]]\n- **[[Reason two]]**: [[one sentence]]\n- **[[Reason three]]**: [[one sentence]]\n\n[[A closing line that invites the reader to act.]] [Call {{lb.phone}}](#lb-phone_link)." ];
				if ( $i < 8 ) {
					$area_item['children'][] = [ 'label' => $area, 'url' => '/service-areas/' . $aslug . '/' ];
				}
			}
			$nav[] = $area_item;
		}
		$pages[] = [ 'title' => 'Contact', 'slug' => 'contact', 'recipe' => 'contact', 'layout' => [ 'title' => $el ? 'show' : 'hide' ], 'seo' => [ 'title' => "Contact {$name} | {$place}" ] ];
		$pages[] = [ 'title' => 'FAQ', 'slug' => 'faq', 'recipe' => 'faq', 'seo' => [ 'title' => "FAQ | {$name}" ], 'replace' => self::faq_replace( $industry ) ];

		$company = array_filter( [
			'name' => $name, 'industry' => $industry, 'city' => $place,
			'phone' => sanitize_text_field( $input['phone'] ?? '' ), 'email' => sanitize_email( $input['email'] ?? '' ), 'address' => sanitize_text_field( $input['address'] ?? '' ), 'hours' => sanitize_text_field( $input['hours'] ?? '' ),
			'about' => sanitize_textarea_field( $input['about'] ?? '' ), 'tone' => sanitize_text_field( $input['tone'] ?? '' ), 'keywords' => sanitize_text_field( $input['keywords'] ?? '' ),
		] );
		$label_len = 0;
		foreach ( $nav as $nv ) {
			$label_len += mb_strlen( $nv['label'] );
		}
		$long_menu = count( $nav ) > 6 || $label_len > 64;
		$header_pattern = $long_menu ? 'header-phone' : $st['header'];
		if ( $long_menu ) {
			$todo[] = 'The menu is long, so the two-row header (header-phone: logo, phone and button on top, menu below) is used. Shorten the menu to use the one-row ' . $st['header'] . '.';
		}
		$bp = [
			'site'    => [ 'title' => $name, 'tagline' => "{$industry} in {$place}", 'permalink' => '/%postname%/' ],
			'company' => $company,
			'brand'   => [ 'palette' => self::palette( (array) ( $input['colors'] ?? [] ) ) ],
			'design'  => [
				'buttons' => $st['buttons'],
				'nav'     => [ 'links' => $st['links'], 'dropdown' => $st['dropdown'], 'theme' => true ],
				'page'    => [ 'preset' => 'banner', 'title' => [ 'height' => $st['banner'] ] ],
			],
			'header'  => [ 'pattern' => $header_pattern, 'nav' => $nav ],
			'entries' => $entries,
			'chrome'  => $chrome,
			'synced'  => $el ? [] : [
				'bottom_stack'     => [ 'sections' => [ [ 'pattern' => 'testimonials-carousel' ], [ 'pattern' => 'offers-cards' ], [ 'pattern' => 'areas-dynamic-map-list' ] ] ],
				'site_footer'      => [ 'pattern' => $st['footer'] ],
				'service_template' => [ 'pattern' => 'template-service' ],
				'area_template'    => [ 'pattern' => 'template-area' ],
			],
			'pro_modules' => $el ? [ 'elements' => true ] : [],
			// Patterns are the designs; Elements are where and when they show (Kadence's own mechanism).
			'elements' => $el ? [
				'page_hero'        => [ 'sections' => [ [ 'pattern' => 'hero-element' ], [ 'pattern' => 'breadcrumb-bar' ] ], 'hook' => 'replace_hero_header', 'post_types' => [ 'page', Entries::SERVICE, Entries::AREA ], 'hide_front' => true ],
				'service_template' => [ 'pattern' => 'template-service', 'slots' => [ '@no_hero' => true ], 'hook' => 'replace_single_content', 'post_types' => [ Entries::SERVICE ] ],
				'area_template'    => [ 'pattern' => 'template-area', 'slots' => [ '@no_hero' => true ], 'hook' => 'replace_single_content', 'post_types' => [ Entries::AREA ] ],
				'site_footer'      => [ 'pattern' => $st['footer'], 'hook' => 'replace_footer' ],
				'bottom_stack'     => [ 'sections' => [ [ 'pattern' => 'testimonials-carousel' ], [ 'pattern' => 'offers-cards' ], [ 'pattern' => 'areas-dynamic-map-list' ] ], 'hook' => 'kadence_before_footer' ],
			] : [],
			'areas'   => $el ? [ 'above_footer' => '', 'footer' => '' ] : [ 'above_footer' => 'bottom_stack', 'footer' => 'site_footer' ],
			'pages'   => $pages,
		];
		return [
			'blueprint' => $bp,
			'style'     => $style,
			'pages'     => count( $pages ),
			'entries'   => [ 'services' => count( $entries['services'] ), 'areas' => count( $entries['areas'] ) ],
			'chrome'    => $chrome,
			'todo'      => array_merge( $todo, $el ? [ 'Elements mode (Kadence Pro): hero + breadcrumbs (replace_hero_header), the service and area templates (replace_single_content), the footer (replace_footer) and the bottom stack (kadence_before_footer) are Elements, each holding a pattern. Edit one element and every targeted page follows. If build-site reports rerun_needed, run it once more so the elements are created.' ] : [] ),
			'next'      => 'Rewrite the [[placeholders]] and default pattern copy, fill company facts (set-company: phone, email, address, hours, licences, quote page, form embed), then build-site with this blueprint. build-site is idempotent, so re-run it as you refine.',
		];
	}
}
