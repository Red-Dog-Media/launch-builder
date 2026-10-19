<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The brief: client facts in, a whole site out. build-from-brief validates the brief, builds the site in resumable parts
 * (see Brief_Build) and reports a plan first (dry run); export-brief writes the site back as a brief (see Brief_Export). The
 * simple path stays: new-blueprint turns a short client brief into a build-site blueprint with the standard pages, menu,
 * entries, chrome and design style. Modules describe the brief data they consume through the module hook
 * brief_schema( array &$schema ) (see schema()).
 */
final class Brief {

	public static function define_tools(): void {
		Abilities::add( 'build-from-brief', 'Build a whole site from one brief', 'The standard way to build a site: ONE brief in, the whole site out. Sections: business (Business Info), brand (palette or colours, fonts, style preset, logo), seo (templates per role, schema type, per-page SEO), sitemap (pages with role, recipe or sections, markdown, slot values), entries (service categories, services and areas with summary, markdown, icon, image, faqs), content, media [{key, url, alt, use}] (each image imported once), forms, menus (main, footer, legal with auto items), chrome (the chrome map), urls and migrate (URL structure, redirects, old sitemap), legal (privacy, terms, accessibility generated from templates, sms_consent), launch {indexing}, conversions (the exit-intent popup is on unless exit_intent is false). Call it first with dry_run true (the default): the whole brief is validated and every problem comes back as a list of {path, message}; a valid brief returns the plan (create, update, keep, prune per object) and copy_needed, the pages and entries that still hold [[placeholders]] or unchanged pattern copy and would be created as drafts. Write the copy into the brief (markdown per page, service and area; slots or replace per section), then call it with dry_run false. Work is done in parts of at most 25 objects or about 20 seconds: while done is false call again with job (the job id) until it is true; phase runs one part (' . implode( ', ', Brief_Build::PHASES ) . '), cursor continues it. It takes a snapshot before the first write, reuses every owner module (Business Info, Design, Style, Entries, Patterns, Chrome, Nav, SEO sync, Conversions, URLs) and is idempotent: pages and entries are matched by key, an unchanged brief changes nothing, an object edited since the last build is kept (force overwrites) and a page an admin drafted is not republished. prune drafts pages and entries an earlier brief built that this one no longer lists. Rank Math Instant Indexing is held back while a build runs and while launch.indexing is false. Then audit-site and go-live.',
			[ 'properties' => [
				'brief'   => self::schema(),
				'job'     => [ 'type' => 'string', 'description' => 'The job id from a previous call, to continue it.' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Default true: validate and plan, write nothing.' ],
				'phase'   => [ 'type' => 'string', 'enum' => array_merge( [ 'all' ], Brief_Build::PHASES ), 'description' => 'Run one part only (default all).' ],
				'cursor'  => [ 'type' => 'integer', 'description' => 'Continue a part at this item (the cursor of the previous result).' ],
				'prune'   => [ 'type' => 'boolean', 'description' => 'Draft pages and entries an earlier brief built that this brief no longer lists.' ],
				'force'   => [ 'type' => 'boolean', 'description' => 'Overwrite objects an admin edited since the last build.' ],
			] ], [ Brief_Build::class, 'tool' ], 'manage_options' );

		Abilities::add( 'export-brief', 'Export the site as a brief', 'The current site as a brief: Business Info, brand, SEO templates, pages (role, status, SEO, order), service categories, services and areas, imported media, menus, the chrome map, URL structure and the launch state. Two exports of an unchanged site are identical, and build-from-brief with dry_run on the export reports no changes. Pages and entries carry content "keep" (their text is left alone when built from the export); include_content adds the text of services and areas as Markdown (converted from the blocks, so it can differ in small ways). sections limits what is returned; lists are cut at limit (default 150): call again with offset.',
			[ 'properties' => [
				'sections'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'business, brand, seo, sitemap, entries, media, menus, chrome, urls, launch (default all).' ],
				'include_content' => [ 'type' => 'boolean', 'description' => 'Add services and areas text as Markdown.' ],
				'offset'          => [ 'type' => 'integer' ],
				'limit'           => [ 'type' => 'integer', 'description' => '1-300 (default 150).' ],
			] ], [ Brief_Export::class, 'tool' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'new-blueprint', 'Turn a client brief into a starter blueprint', 'Give the client brief and get back a complete blueprint for build-site: the standard pages (home, about, a page per service category and per service, service areas hub and city pages, contact, FAQ), the header menu with dropdowns, a global bottom stack (reviews, offers, service areas) and footer, brand palette derived from the colours, and a design style. Copy is left as [[bracketed]] placeholders and default pattern text for you to rewrite. brief: {name, city, state, industry, services:[{name, children:["Service", ...]}] (categories with their services, or a flat list), areas:["City", ...], colors:{primary, accent, dark} (hex), style:"bold"|"clean"|"soft", phone, email, address, hours, about, tone, keywords}.',
			[ 'properties' => [ 'name' => [ 'type' => 'string' ], 'city' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ], 'industry' => [ 'type' => 'string' ], 'services' => [ 'type' => 'array' ], 'areas' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'colors' => [ 'type' => 'object' ], 'style' => [ 'type' => 'string', 'enum' => [ 'bold', 'clean', 'soft' ] ], 'phone' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ], 'address' => [ 'type' => 'string' ], 'hours' => [ 'type' => 'string' ], 'about' => [ 'type' => 'string' ], 'tone' => [ 'type' => 'string' ], 'keywords' => [ 'type' => 'string' ] ], 'required' => [ 'name', 'city' ] ], [ __CLASS__, 'new_blueprint' ], 'edit_pages', [ 'readonly' => true ] );
	}

	/**
	 * The brief schema: the top-level sections as typed objects, then whatever each module adds through its
	 * brief_schema() hook. It is the schema of the brief property of build-from-brief.
	 *
	 * @return array JSON schema (type object)
	 */
	public static function schema(): array {
		$str = [ 'type' => 'string' ];
		$int = [ 'type' => 'integer' ];
		$bool = [ 'type' => 'boolean' ];
		$obj = static function ( string $desc, array $props = [] ): array {
			return $props ? [ 'type' => 'object', 'description' => $desc, 'properties' => $props ] : [ 'type' => 'object', 'description' => $desc ];
		};
		$list = static function ( string $desc, array $items = [ 'type' => 'object' ] ): array {
			return [ 'type' => 'array', 'description' => $desc, 'items' => $items ];
		};
		$business = [];
		foreach ( Company::fields() as $k => $f ) {
			$business[ $k ] = 'list' === $f[1] ? [ 'type' => 'array', 'items' => [ 'type' => [ 'object', 'string' ] ], 'description' => (string) $f[0] ] : [ 'type' => [ 'string', 'number', 'boolean' ], 'description' => (string) $f[0] ];
		}
		$business['site_title'] = [ 'type' => 'string', 'description' => 'The WordPress site title (Settings, General). Leave it out and business.name is written as the site title. Give both only when the business name is meant to differ from the title.' ];
		$business['site_tagline'] = [ 'type' => 'string', 'description' => 'The WordPress tagline (Settings, General). Leave it out and business.tagline is written as the tagline. Give both only when the Business Info tagline is meant to differ.' ];
		$seo_page = $obj( 'Per-page SEO.', [ 'title' => $str, 'description' => $str, 'keyword' => $str, 'robots' => $list( 'e.g. ["noindex","follow"]', $str ) ] );
		$section = $obj( 'A pattern section of a page.', [
			'pattern'  => [ 'type' => 'string', 'description' => 'Pattern slug (list-patterns).' ],
			'slots'    => [ 'type' => 'object', 'description' => 'Slot id => value (get-pattern lists the ids); "media:<key>" in an image slot is the imported image.' ],
			'replace'  => [ 'type' => 'object', 'description' => 'Default text => new text.' ],
			'markdown' => [ 'type' => 'string', 'description' => 'Text for the pattern\'s text area (service-body).' ],
			'use'      => [ 'type' => 'string', 'description' => 'Key of a synced pattern to show instead.' ],
		] );
		$page = $obj( 'A page. Nest children, or name the parent by key or path.', [
			'key'      => [ 'type' => 'string', 'description' => 'Stable id for re-runs (default page:<path>).' ],
			'title'    => $str,
			'slug'     => $str,
			'parent'   => [ 'type' => 'string', 'description' => 'Key or path of the parent page (when not nested).' ],
			'role'     => [ 'type' => 'string', 'enum' => Brief_Validate::PAGE_ROLES ],
			'recipe'   => [ 'type' => 'string', 'description' => 'A page recipe (get-playbook lists them).' ],
			'type'     => [ 'type' => 'string', 'enum' => array_keys( Content::TYPES ) ],
			'sections' => $list( 'Patterns to build the page from (instead of the recipe).', $section ),
			'markdown' => [ 'type' => 'string', 'description' => 'The page text; fills the recipe\'s text section.' ],
			'replace'  => [ 'type' => 'object', 'description' => 'Default text => new text, for every section.' ],
			'layout'   => [ 'type' => 'object' ],
			'seo'      => $seo_page,
			'status'   => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private' ] ],
			'order'    => $int,
			'front'    => $bool,
			'content'  => [ 'type' => 'string', 'enum' => [ 'build', 'keep' ], 'description' => 'keep = leave the stored text alone.' ],
			'category' => [ 'type' => 'string', 'description' => 'For role category: the service category slug.' ],
			'children' => $list( 'Child pages.' ),
		] );
		$entry = [
			'key'      => [ 'type' => 'string', 'description' => 'Stable id for re-runs.' ],
			'title'    => $str,
			'slug'     => $str,
			'summary'  => [ 'type' => 'string', 'description' => 'One or two sentences (the excerpt).' ],
			'markdown' => [ 'type' => 'string', 'description' => 'The entry text.' ],
			'icon'     => [ 'type' => 'string', 'description' => 'Kadence icon name.' ],
			'image'    => [ 'type' => 'string', 'description' => 'A media key or an image URL.' ],
			'faqs'     => $list( '[{q, a}]', $obj( 'A question and its answer.', [ 'q' => $str, 'a' => $str ] ) ),
			'seo'      => $seo_page,
			'order'    => $int,
			'status'   => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
			'content'  => [ 'type' => 'string', 'enum' => [ 'build', 'keep' ] ],
		];
		$menu_item = $obj( 'A menu item: a page (key, path or slug), a url, or an auto list.', [
			'label'    => $str,
			'page'     => [ 'type' => 'string' ],
			'url'      => $str,
			'auto'     => [ 'type' => 'string', 'enum' => [ 'services', 'areas' ] ],
			'category' => $str,
			'parent'   => $str,
			'limit'    => $int,
			'children' => $list( 'Dropdown items.' ),
		] );
		$schema = [
			'type'       => 'object',
			'properties' => [
				'version'  => [ 'type' => 'integer', 'description' => 'Brief format version (1).' ],
				'business' => $obj( 'Business Info: every Company field (see get-company). business.name is written as the WordPress site title and business.tagline as the WordPress tagline (they are one value; the build keeps a Business Info copy only when it differs). name is required unless site_title is given.', $business ),
				'brand'    => $obj( 'Palette or 2-3 colours, fonts, logo, style.', [
					'palette'      => [ 'type' => 'object', 'description' => 'palette1..palette9 => hex colour.' ],
					'colors'       => $obj( 'Two or three brand colours (the palette is derived).', [ 'primary' => $str, 'accent' => $str, 'dark' => $str ] ),
					'fonts'        => $obj( 'Font families.', [ 'headings' => [ 'type' => [ 'string', 'object' ] ], 'body' => [ 'type' => [ 'string', 'object' ] ], 'weights' => $list( 'e.g. [400, 700]', $int ) ] ),
					'logo'         => [ 'type' => 'string', 'description' => 'A media key or an image URL.' ],
					'logo_inverse' => [ 'type' => 'string', 'description' => 'Logo for dark backgrounds, shown above the admin menu (Branding): a media key or URL.' ],
					'site_icon'    => [ 'type' => 'string', 'description' => 'Site icon: a media key or URL.' ],
					'logo_width'   => $int,
					'style'        => [ 'type' => [ 'string', 'object' ], 'description' => 'A style preset (clean, bold, sharp, soft) or { preset, tokens } (see set-style).' ],
					'buttons'      => [ 'type' => 'object', 'description' => 'See set-buttons.' ],
					'nav'          => [ 'type' => 'object', 'description' => 'Menu and dropdown look (see set-nav-style).' ],
					'page'         => [ 'type' => [ 'string', 'object' ], 'description' => 'Page banner style (see set-page-style).' ],
				] ),
				'seo'      => $obj( 'SEO.', [
					'templates'        => [ 'type' => 'object', 'description' => 'role => { title, description } with Rank Math %variables% and %sep%.' ],
					'pages'            => [ 'type' => 'object', 'description' => 'page key, path or slug => { title, description, keyword, robots }.' ],
					'schema_type'      => [ 'type' => 'string', 'description' => 'A schema.org LocalBusiness type, e.g. Plumber.' ],
					'og_image'         => [ 'type' => 'string', 'description' => 'A media key or an image URL.' ],
					'enable_local_seo' => $bool,
				] ),
				'sitemap'  => $obj( 'The pages.', [ 'pages' => $list( 'Pages, nested through children.', $page ) ] ),
				'entries'  => $obj( 'Service categories, services and areas.', [
					'categories' => $list( '[{name, slug, page}]; categories used by services are created anyway.', $obj( 'A service category and its landing page.', [ 'name' => $str, 'slug' => $str, 'page' => [ 'type' => 'string', 'description' => 'Key or path of its landing page.' ] ] ) ),
					'services'   => $list( 'Services, each with category or categories (or grouped: { category, items: [...] }).', $obj( 'A service.', $entry + [ 'category' => $str, 'categories' => $list( 'Category names or slugs.', $str ) ] ) ),
					'areas'      => $list( 'Service areas, nested through children or named by parent (key or path).', $obj( 'A service area.', $entry + [ 'parent' => $str, 'services' => $list( 'Services offered (titles, slugs or keys); none listed = all.', $str ), 'children' => $list( 'Child areas.' ) ] ) ),
				] ),
				'content'  => $obj( 'testimonials, team, offers, guarantees, badges, brands, gallery, videos, financing, stats, faqs.global. Accepted and stored; pattern families read them as they take data.', [
					'testimonials' => $list( 'Written testimonials.' ),
					'team'         => $list( 'People.' ),
					'offers'       => $list( 'Promotions (expires = end date).' ),
					'guarantees'   => $list( 'Guarantees.' ),
					'badges'       => $list( 'Trust badges.' ),
					'brands'       => $list( 'Brands carried.' ),
					'gallery'      => $list( 'Images.' ),
					'videos'       => $list( 'Videos.' ),
					'financing'    => [ 'type' => 'object' ],
					'stats'        => [ 'type' => 'object' ],
					'faqs'         => $obj( 'Questions.', [ 'global' => $list( '[{q, a}]' ) ] ),
				] ),
				'media'    => $list( '[{key, url, alt, use}]: each image is imported once (matched by URL); use = logo, logo_inverse, site_icon, og_image or featured:<page or entry key>.', $obj( 'An image.', [ 'key' => $str, 'url' => $str, 'alt' => $str, 'use' => $str ] ) ),
				'forms'    => $obj( 'Form embeds (code or { embed, url }): quote, contact, booking, careers; fallback kadence|none.', [
					'quote'    => [ 'type' => [ 'string', 'object' ] ],
					'contact'  => [ 'type' => [ 'string', 'object' ] ],
					'booking'  => [ 'type' => [ 'string', 'object' ] ],
					'careers'  => [ 'type' => [ 'string', 'object' ] ],
					'fallback' => [ 'type' => 'string', 'enum' => [ 'kadence', 'none' ] ],
				] ),
				'menus'    => $obj( 'Menus. Without main, the first build makes one from the sitemap.', [
					'main'   => $list( 'The header menu.', $menu_item ),
					'footer' => $list( 'A footer menu (kept as a navigation).', $menu_item ),
					'legal'  => $list( 'A legal links menu (kept as a navigation).', $menu_item ),
					'header' => [ 'type' => 'string', 'description' => 'Header pattern slug. Default: header-main (logo, menu, phone and button on one row) for up to ' . self::HEADER_MAX_ITEMS . ' top-level menu items and about ' . self::HEADER_MAX_LABELS . ' characters of labels in total, header-phone (menu in its own bar) when the menu is longer than either limit.' ],
				] ),
				'chrome'   => $obj( 'The chrome map (see set-chrome), optionally with mode auto|elements|patterns.', [ 'mode' => [ 'type' => 'string', 'enum' => [ 'auto', 'elements', 'patterns' ] ], 'map' => [ 'type' => 'object' ] ] ),
				'urls'     => $obj( 'URL structure and redirects.', [ 'permastructures' => [ 'type' => 'object' ], 'redirects' => $list( '[{from, to, code}]' ), 'old_sitemap_url' => $str, 'settings' => [ 'type' => 'object' ] ] ),
				'migrate'  => $obj( 'Moving from an old site.', [ 'old_sitemap_url' => $str, 'redirects' => $list( '[{from, to, code}]' ) ] ),
				'legal'    => $obj( 'Legal pages: privacy, terms, accessibility ({ generate: true } or { markdown }, reviewed, title, slug) and sms_consent (true or { program, frequency, help }). Generated texts stay drafts until reviewed.', [
					'privacy'       => [ 'type' => [ 'boolean', 'object' ] ],
					'terms'         => [ 'type' => [ 'boolean', 'object' ] ],
					'accessibility' => [ 'type' => [ 'boolean', 'object' ] ],
					'sms_consent'   => [ 'type' => [ 'boolean', 'object' ] ],
				] ),
				'launch'   => $obj( 'Launch state.', [ 'indexing' => [ 'type' => 'boolean', 'description' => 'false keeps the site hidden from search engines and holds Instant Indexing back until go-live.' ] ] ),
			],
			'required'   => [ 'business' ],
		];
		foreach ( Plugin::modules( 'brief_schema' ) as $module ) {
			$module::brief_schema( $schema );
		}
		return $schema;
	}

	/** Most top-level menu items the one-row header holds (six to eight fit at 1280 px and up), and the most label characters. */
	const HEADER_MAX_ITEMS  = 8;
	const HEADER_MAX_LABELS = 110;

	/**
	 * The header pattern for a style preset and a menu of $items top-level items with $label_len label characters: the
	 * main-row header (logo, menu, phone and button on one row) for every style. A longer menu gets the two-row header on
	 * purpose (logo, phone and button on top, the menu in its own bar), never a wrapped one-row header.
	 */
	public static function default_header( string $preset, int $items, int $label_len ): string {
		$map = [ 'bold' => 'bold', 'sharp' => 'bold', 'clean' => 'clean', 'soft' => 'soft' ];
		$st = self::styles()[ $map[ $preset ] ?? 'clean' ];
		return $items > self::HEADER_MAX_ITEMS || $label_len > self::HEADER_MAX_LABELS ? 'header-phone' : $st['header'];
	}

	/** Module hook: how to build a whole site from a brief. */
	public static function playbook( array &$pb ): void {
		$pb['brief'] = [
			'use_it_first' => 'build-from-brief builds a whole site from ONE brief: business facts, brand, SEO, sitemap, entries, menus, chrome, legal pages and launch settings. Use it first; new-blueprint + build-site stay as the simple path for a quick starter site.',
			'order'        => [
				'1. get-playbook, site-state. Gather the client facts (name, phone, address, hours, licences, services by category, areas, colours, tone, existing site).',
				'2. Write the brief (the schema is the brief property of build-from-brief) and call build-from-brief { brief } with the default dry_run true. Fix every error (each names its path in the brief), then read the plan and copy_needed.',
				'3. Copy pass: write the text the plan says is missing into the brief: markdown for every page, service and area, summaries, slot values or replace per section, FAQs. A page or entry that still holds [[placeholders]] or unchanged pattern copy is created as a DRAFT and listed in copy_needed. Re-run the dry run until copy_needed is what you accept.',
				'4. Build: build-from-brief { brief, dry_run: false }. While done is false call it again with the job id; each call does up to 25 objects or about 20 seconds. A snapshot is taken before the first write (restore-snapshot undoes it).',
				'5. Check: audit-site (fix errors), inspect-page per role, preview-urls for screenshots. export-brief writes the site back as a brief to keep or diff.',
				'6. go-live: refuses on audit errors (force overrides), then makes the site public and refreshes the sitemap.',
			],
			'rules'        => [
				'Give every page and entry a key when its slug may change: the key is how a re-run finds it (the old URL then redirects).',
				'Re-running is safe: unchanged objects are kept, objects an admin edited since the last build are kept, an admin-drafted page is not republished. force overwrites; prune drafts what the brief no longer lists.',
				'Never put business facts in copy: use Business Info (business) and tokens such as {{lb.phone}}.',
				'Legal pages: legal.privacy / terms / accessibility generate token-filled texts that carry a "client must review" notice and stay drafts until reviewed; sms_consent adds the text message consent wording.',
				'launch.indexing false keeps the site hidden from search engines and holds Instant Indexing back until go-live.',
			],
		];
		$pb['tools_by_job']['Whole site'] = 'build-from-brief (dry run first), export-brief, go-live';
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
			// Every style defaults to a main-row-only header; header-utility has a top bar.
			'bold'  => [ 'header' => 'header-main', 'footer' => 'footer-licensing',
				'buttons' => [ 'primary' => [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => 'palette9', 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true, 'shadow' => 'soft' ], 'secondary' => [ 'background' => 'palette3', 'color' => 'palette9', 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true ], 'outline' => [ 'color' => 'palette1', 'border_color' => 'palette1', 'border_width' => 2, 'radius' => 'pill', 'padding' => 'lg', 'weight' => 800, 'uppercase' => true ] ],
				'dropdown' => [ 'background' => 'palette9', 'hover_background' => 'palette8', 'color' => 'palette3', 'hover_color' => 'palette1', 'width' => 280, 'radius' => 14, 'shadow' => 'strong', 'reveal' => 'fade-up', 'divider' => true, 'padding' => [ 13, 22 ], 'spacing' => 8 ], 'links' => [ 'color' => 'palette3', 'hover' => 'palette1', 'active' => 'palette1', 'style' => 'underline', 'weight' => 700 ], 'banner' => 220 ],
			'clean' => [ 'header' => 'header-main', 'footer' => 'footer-dark',
				'buttons' => [ 'primary' => [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => 'palette9', 'radius' => 8, 'padding' => 'md', 'weight' => 600, 'uppercase' => false, 'shadow' => 'none' ], 'secondary' => [ 'background' => 'palette7', 'color' => 'palette3', 'radius' => 8, 'padding' => 'md', 'weight' => 600 ], 'outline' => [ 'color' => 'palette1', 'border_color' => 'palette1', 'border_width' => 2, 'radius' => 8, 'padding' => 'md', 'weight' => 600 ] ],
				'dropdown' => [ 'background' => 'palette9', 'hover_background' => 'palette8', 'color' => 'palette3', 'hover_color' => 'palette1', 'width' => 250, 'radius' => 8, 'shadow' => 'soft', 'reveal' => 'fade', 'divider' => false, 'padding' => [ 11, 18 ], 'spacing' => 6 ], 'links' => [ 'color' => 'palette3', 'hover' => 'palette1', 'active' => 'palette1', 'style' => 'standard', 'weight' => 600 ], 'banner' => 170 ],
			'soft'  => [ 'header' => 'header-main', 'footer' => 'footer-light',
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
		$header_pattern = self::default_header( $style, count( $nav ), $label_len );
		if ( 'header-phone' === $header_pattern ) {
			$todo[] = 'The menu is long, so the two-row header (header-phone: logo, phone and button on top, menu below) is used. To use the one-row ' . $st['header'] . ', keep the top level to ' . self::HEADER_MAX_ITEMS . ' items and about ' . self::HEADER_MAX_LABELS . ' characters of labels in total.';
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
