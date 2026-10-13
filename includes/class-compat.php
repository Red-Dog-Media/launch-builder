<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Third-party compatibility: Rank Math (settings, variables, templates, schema, sitemap, robots,
 * Instant Indexing, editor analysis), Yoast (meta syntax, variables, sitemap), Kadence (microdata, local fonts, hero
 * image priority), Meta Box (field allow-list and ownership helpers) and Perfmatters (exclusions and a risk report).
 *
 * Rank Math settings are written server-side as key-level merges of the raw options: only the keys Launch Builder owns,
 * 'on'/'off'/scalar values, through Rank Math's own WP Abilities when they exist and accept the value unchanged (direct
 * update_option otherwise), then rank_math()->settings->reset() and the sitemap cache invalidation. The four options are
 * snapshotted first. Every key Launch Builder wrote is in the Ledger, so a value an admin changed later is reported as a
 * kept edit and left alone (unless forced).
 *
 * Business Info values (name, logo, email, phone, socials, structured address, hours, price range, geo, business type)
 * are also overlaid at read time (filter option_rank-math-options-titles, front end and REST only), so later Business
 * Info edits reach the knowledge graph without a re-sync; a key an admin changes in Rank Math stops being overlaid.
 *
 * SEO strings are cleaned with clean_seo() (tags and whitespace only), never sanitize_text_field(), which deletes any
 * %xx sequence and so destroys variables such as %category% or %date%.
 */
final class Compat {

	/** Role templates: role => { title, description } (Rank Math %variables%, %sep% for the separator). */
	const OPT_TEMPLATES = 'launch_builder_seo_templates';

	/** Rank Math titles keys Launch Builder keeps in step with Business Info at read time (autoloaded list). */
	const OPT_OWNED = 'launch_builder_seo_owned';

	/** IndexNow submissions Launch Builder suppressed: { count, last_url, last_time }. */
	const OPT_INDEXNOW = 'launch_builder_indexnow_suppressed';

	/** Per-page record of the SEO values a role template wrote: JSON { title, description, robots } => md5. */
	const META_TPL = '_lb_seo_tpl';

	/** Per-entry FAQs: list of { q, a } (array or JSON string). Emitted as FAQPage in Rank Math's graph. */
	const META_FAQ = '_lb_faq';

	/** Rank Math option groups. */
	const RM_OPTIONS = [
		'general' => 'rank-math-options-general',
		'titles'  => 'rank-math-options-titles',
		'sitemap' => 'rank-math-options-sitemap',
	];

	const RM_MODULES = 'rank_math_modules';

	/** Sitemap keys: services and areas in, categories (301 to their page) and Kadence Elements out. */
	const RM_SITEMAP = [
		'pt_lb_service_sitemap'      => 'on',
		'pt_lb_area_sitemap'         => 'on',
		'tax_lb_service_cat_sitemap' => 'off',
		'pt_kadence_element_sitemap' => 'off',
	];

	/** Titles keys with fixed values. Title/description templates and Business Info keys come on top. */
	const RM_TITLES = [
		'pt_lb_service_primary_taxonomy'      => 'lb_service_cat',
		'pt_lb_service_add_meta_box'          => 'on',
		'pt_lb_area_add_meta_box'             => 'on',
		'pt_lb_service_default_rich_snippet'  => 'service',
		'pt_lb_service_default_snippet_name'  => '%seo_title%',
		'pt_lb_service_default_snippet_desc'  => '%seo_description%',
		'pt_lb_area_default_rich_snippet'     => 'off',
		'pt_page_default_rich_snippet'        => 'off',
		'tax_lb_service_cat_add_meta_box'     => 'off',
		'pt_kadence_element_add_meta_box'     => 'off',
		'pt_kadence_element_link_suggestions' => 'off',
		'knowledgegraph_type'                 => 'company',
	];

	/** Business Info keys overlaid in rank-math-options-titles (see identity()). */
	const OVERLAY_KEYS = [ 'url', 'knowledgegraph_name', 'knowledgegraph_logo', 'knowledgegraph_logo_id', 'organization_description', 'email', 'phone', 'social_url_facebook', 'social_additional_profiles', 'local_business_type', 'local_address', 'opening_hours', 'phone_numbers', 'price_range', 'geo', 'local_seo_contact_page' ];

	/** Every Rank Math titles key Business Info fills: the overlay keys plus the site type (see business_map()). */
	const BUSINESS_KEYS = [ 'knowledgegraph_type', 'url', 'knowledgegraph_name', 'knowledgegraph_logo', 'knowledgegraph_logo_id', 'organization_description', 'email', 'phone', 'social_url_facebook', 'social_additional_profiles', 'local_business_type', 'local_address', 'opening_hours', 'phone_numbers', 'price_range', 'geo', 'local_seo_contact_page' ];

	/** What the last "fill Rank Math from Business Info" wrote and what it replaced: { time, user, snapshot, before, ledger, owned, module }. */
	const OPT_FILL = 'launch_builder_rm_fill';

	/** A Meta Box field group that takes over the Card details fields (shown by get-playbook meta_box). */
	const META_BOX_EXAMPLE = <<<'PHP'
		add_filter( 'rwmb_meta_boxes', function ( $boxes ) {
			$boxes[] = [
				'title'      => 'Card details',
				'post_types' => [ 'lb_service', 'lb_area' ],
				'fields'     => [
					[ 'id' => '_lb_icon', 'name' => 'Icon', 'type' => 'text' ],
					[ 'id' => '_lb_summary', 'name' => 'Summary', 'type' => 'textarea' ],
				],
			];
			$boxes[] = [
				'title'      => 'Services offered',
				'post_types' => [ 'lb_area' ],
				'fields'     => [
					[ 'id' => '_lb_services', 'name' => 'Services offered', 'type' => 'post', 'post_type' => 'lb_service', 'field_type' => 'select_advanced', 'multiple' => true, 'clone' => false ],
				],
			];
			return $boxes;
		} );
		PHP;

	/** Templates used for the service/area Rank Math keys until the brief or sync-seo gives others. */
	const DEFAULT_TEMPLATES = [
		'service' => [ 'title' => '%title% %sep% %sitename%', 'description' => '%lb_entry_summary%' ],
		'area'    => [ 'title' => '%title% %sep% %sitename%', 'description' => '%lb_entry_summary%' ],
	];

	/** Roles whose templates go to Rank Math options instead of per-page meta: role => [ title key, description key ]. */
	const OPTION_ROLES = [
		'service'  => [ 'pt_lb_service_title', 'pt_lb_service_description' ],
		'area'     => [ 'pt_lb_area_title', 'pt_lb_area_description' ],
		'search'   => [ 'search_title', '' ],
		'notfound' => [ '404_title', '' ],
	];

	/** Robots per role when the object has none of its own (Rank Math shape: index/follow keyed). */
	const ROLE_ROBOTS = [ 'thanks' => [ 'noindex', 'follow' ], 'search' => [ 'noindex', 'follow' ], 'notfound' => [ 'noindex', 'follow' ] ];

	/** Robots directives a brief or tool may store (rank_math_robots is always an array). */
	const ROBOTS = [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ];

	/** Kadence and Launch Builder template post types: never in sitemaps or SEO boxes. */
	const NON_SEO_TYPES = [ 'kadence_element', 'wp_block', 'kadence_header', 'kadence_navigation', 'kadence_form', 'kadence_conversions' ];

	/**
	 * Selectors that only exist after JavaScript runs (verified in Kadence theme 1.x navigation.min.js, Kadence Blocks
	 * kt-accordion/kt-tabs/kb-header-block/kb-navigation-block/kb-off-canvas-trigger/kb-splide-init, Blocks Pro
	 * kt-modal-init and Kadence Conversions 1.1.4). Perfmatters "Remove Unused CSS" builds used CSS from the server HTML
	 * and keeps a selector only when an excluded name is followed by a space, '.', ':', ',', '[' or the end, so every
	 * name is listed exactly (no wildcards).
	 */
	const PM_RUCSS_SELECTORS = [
		// Kadence theme header, drawers, menus.
		'.popup-drawer', '.show-drawer', '.showing-popup-drawer-from-left', '.showing-popup-drawer-from-right', '.showing-popup-drawer-from-full', '.active', '.opened', '.toggle-show', '.menu-item--toggled-on', '.menu-item--has-toggle', '.sub-menu-edge', '.pop-animated', '.scroll-visible', '.dropdown-nav-special-toggle', '.kadence-scrollbar-fixer',
		// Sticky / transparent headers (theme and Kadence Blocks header).
		'.item-is-fixed', '.item-is-stuck', '.item-at-start', '.item-hidden-above', '.header-is-fixed', '.child-is-fixed', '.kadence-sticky-header', '.kb-header-sticky-wrapper', '.kb-header-placeholder-wrapper', '.kb-scrollbar-fixer',
		// Accordion and tabs.
		'.kt-accordion-panel-active', '.kt-accordion-panel-hidden', '.kt-panel-is-collapsing', '.kt-panel-is-expanding', '.kt-tab-title-active', '.kt-tab-title-inactive', '.kt-tab-inner-content', '.kt-active-tab-1', '.kt-active-tab-2', '.kt-active-tab-3', '.kt-active-tab-4', '.kt-active-tab-5', '.kt-active-tab-6',
		// Sliders (Splide).
		'.splide', '.kb-splide', '.splide-initialized', '.is-initialized', '.is-active', '.is-visible', '.is-prev', '.is-next', '.is-rendered', '.is-overflow',
		// Modals and off-canvas (Blocks Pro, Kadence Blocks).
		'.kt-modal-open', '.is-open', '.kb-modal-open', '.show-off-canvas', '.kb-smc-open',
		// Kadence Conversions popups, banners, slide-ins.
		'.kc-visible', '.kc-closing-visible', '.kc-is-initialized', '.kc-banner-top-offset',
	];

	/** Scripts Delay JavaScript ("delay all") must not hold back: the form embed, Kadence menus/sliders/modals, Conversions. */
	const PM_DELAY_EXCLUSIONS = [ 'form_embed.js', 'leadconnectorhq', 'msgsndr', 'kadence-navigation', 'navigation.min.js', 'kb-navigation-block', 'kb-header-block', 'kb-off-canvas-trigger', 'kt-accordion', 'kt-tabs', 'splide', 'kadence-slide-init', 'kt-modal-init', 'kadence-blocks-pro-modal', 'kadence-conversions' ];

	/** Lazy-load exclusions (matched anywhere in the tag): the form embed iframe. */
	const PM_LAZY_EXCLUSIONS = [ 'leadconnectorhq', 'msgsndr', 'form_embed' ];

	/** @var bool read Rank Math options without the Business Info overlay. */
	private static $raw = false;

	/** @var bool Launch Builder is writing Rank Math options (the save watcher ignores it). */
	private static $writing = false;

	/** @var array|null per-request Business Info overlay values. */
	private static $identity = null;

	/** @var bool IndexNow suppressed for this request (builds). */
	private static $suppress = false;

	/** @var int IndexNow submissions suppressed in this request. */
	private static $suppressed = 0;

	/** @var array<string,array> mb_fields_for() memo. */
	private static $mb = [];

	/** @var bool inside kadence_hero_header / first hero image already prioritised. */
	private static $in_hero = false;
	private static $hero_done = false;

	/** @var array<int,array> areas served per service id. */
	private static $areas = [];

	/* ------------------------------------------------------------------ module hooks */

	public static function boot(): void {
		// Rank Math settings: Business Info overlay + watcher for admin edits; variables; migration step.
		add_filter( 'option_' . self::RM_OPTIONS['titles'], [ __CLASS__, 'overlay_titles' ], 20 );
		add_action( 'update_option_' . self::RM_OPTIONS['titles'], [ __CLASS__, 'titles_saved' ], 10, 2 );
		add_action( 'init', [ __CLASS__, 'refresh_rm_settings' ], 1 );
		add_action( 'rank_math/vars/register_extra_replacements', [ __CLASS__, 'register_rm_vars' ] );
		add_action( 'wpseo_register_extra_replacements', [ __CLASS__, 'register_yoast_vars' ] );
		add_action( 'activated_plugin', [ __CLASS__, 'plugin_activated' ] );
		add_action( 'update_option_' . Company::OPT, [ __CLASS__, 'flush_identity' ] );
		add_action( 'add_option_' . Company::OPT, [ __CLASS__, 'flush_identity' ] );
		Migrate::register_step( 'compat-seo', [ __CLASS__, 'migrate_seo' ], [ 'every_version' => true ] );
		foreach ( self::AUDIT_CHECKS as $code => $c ) {
			Audit::register_check( $code, $c[0], [ __CLASS__, $c[1] ] );
		}

		// Front-end SEO output: templates per role, description precedence for entries, role robots.
		add_filter( 'rank_math/frontend/title', [ __CLASS__, 'rm_title' ], 5 );
		add_filter( 'rank_math/frontend/description', [ __CLASS__, 'rm_description' ], 5 );
		add_filter( 'rank_math/frontend/robots', [ __CLASS__, 'rm_robots' ], 5 );
		add_filter( 'wpseo_robots_array', [ __CLASS__, 'yoast_robots' ], 5 );

		// Schema inside Rank Math's graph.
		add_filter( 'rank_math/schema/add_global_entities', [ __CLASS__, 'rm_global_entities' ], 10, 2 );
		add_filter( 'rank_math/json_ld', [ __CLASS__, 'rm_json_ld' ], 20, 2 );
		add_filter( 'rank_math/snippet/rich_snippet_service_entity', [ __CLASS__, 'rm_service_entity' ], 20 );

		// Sitemaps: template types and redirected category archives out, thanks pages out.
		add_filter( 'rank_math/sitemap/exclude_post_type', [ __CLASS__, 'exclude_post_type' ], 10, 2 );
		add_filter( 'rank_math/sitemap/exclude_taxonomy', [ __CLASS__, 'exclude_taxonomy' ], 10, 2 );
		add_filter( 'rank_math/sitemap/entry', [ __CLASS__, 'rm_sitemap_entry' ], 10, 3 );
		add_filter( 'wpseo_sitemap_exclude_post_type', [ __CLASS__, 'exclude_post_type' ], 10, 2 );
		add_filter( 'wpseo_sitemap_exclude_taxonomy', [ __CLASS__, 'exclude_taxonomy' ], 10, 2 );

		// IndexNow off during builds and while the site is not public.
		add_filter( 'rank_math/instant_indexing/publish_url', [ __CLASS__, 'indexnow' ], 99, 2 );

		// Kadence: no theme microdata next to Rank Math's JSON-LD; the hero's first image loads first.
		add_filter( 'kadence_microdata', [ __CLASS__, 'kadence_microdata' ], 20 );
		add_action( 'kadence_hero_header', [ __CLASS__, 'hero_open' ], 0 );
		add_action( 'kadence_hero_header', [ __CLASS__, 'hero_close' ], PHP_INT_MAX );
		add_filter( 'render_block', [ __CLASS__, 'hero_image' ], 30 );

		// Editor: Rank Math content analysis sees the role chrome and token values.
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'classic_assets' ] );

		// Perfmatters: keep what Kadence, the form embed and Launch Builder's REST routes need.
		add_filter( 'perfmatters_rucss_excluded_selectors', [ __CLASS__, 'pm_rucss' ] );
		add_filter( 'perfmatters_delay_js_exclusions', [ __CLASS__, 'pm_delay' ] );
		add_filter( 'perfmatters_lazyload_excluded_attributes', [ __CLASS__, 'pm_lazy' ] );
		add_filter( 'perfmatters_rest_api_exceptions', [ __CLASS__, 'pm_rest' ] );
	}

	/** Module hook (plugin activated): run the SEO sync again at the next wp-admin view. */
	public static function activate(): void {
		Migrate::rerun( 'compat-seo' );
	}

	/** Rank Math (or Yoast) activated after Launch Builder: its per-type keys are missing until the next sync. */
	public static function plugin_activated( $plugin ): void {
		if ( in_array( (string) $plugin, [ 'seo-by-rank-math/rank-math.php', 'wordpress-seo/wp-seo.php' ], true ) ) {
			Migrate::rerun( 'compat-seo' );
		}
	}

	public static function define_tools(): void {
		Abilities::add( 'get-compat', 'Check third-party compatibility', 'How Launch Builder fits the plugins on this site: the active SEO plugin; Rank Math modules and every setting Launch Builder owns (sitemap, titles, schema, knowledge graph) with its current and wanted value and status (ok, missing, differs, kept_edit); role SEO templates; IndexNow submissions suppressed; Kadence microdata and local fonts; Meta Box fields per entry type; the Perfmatters risk report. section: rank_math | kadence | meta_box | perfmatters | all (default).',
			[ 'properties' => [
				'section' => [ 'type' => 'string', 'enum' => [ 'all', 'rank_math', 'kadence', 'meta_box', 'perfmatters' ], 'description' => 'Limit the report (default all).' ],
			] ], [ __CLASS__, 'get_compat_tool' ], 'manage_options', [ 'readonly' => true ] );

		Abilities::add( 'sync-seo', 'Sync SEO settings', 'Write the Rank Math settings Launch Builder owns (services and areas in the sitemap, categories and Kadence Elements out; Service schema for services; pages not Articles; knowledge graph and local business from Business Info; title/description templates for services and areas), Kadence local fonts, and the role SEO templates on pages (rank_math_title/description written from the role template; pages whose SEO an editor changed are kept and reported; thank-you pages noindex). Snapshot first. dry_run (default true) returns the plan. templates: {role: {title, description}} with Rank Math variables (%title%, %sep%, %sitename%, %lb_phone%, %lb_city%, %lb_entry_summary%...; see get-playbook seo); roles: home, page, legal, services_hub, areas_hub, category, service, area, conversion, thanks, search, notfound. local_seo: true enables Rank Math\'s Local SEO module (false disables it; omit to leave it). force: setting keys (e.g. "titles.knowledgegraph_name"), page ids, or "all" to overwrite kept edits.',
			[ 'properties' => [
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'Report the plan without writing (default true).' ],
				'templates' => [ 'type' => 'object', 'description' => 'Role templates to store and apply: {role: {title, description}}. An empty string removes a field.', 'additionalProperties' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ] ] ] ],
				'local_seo' => [ 'type' => 'boolean', 'description' => 'Turn Rank Math\'s Local SEO module on (true) or off (false).' ],
				'pages'     => [ 'type' => 'boolean', 'description' => 'Apply role templates and robots defaults to pages (default true).' ],
				'force'     => [ 'type' => 'array', 'items' => [ 'type' => [ 'string', 'integer' ] ], 'description' => 'Overwrite kept edits for these setting keys / page ids, or "all".' ],
				'offset'    => [ 'type' => 'integer', 'description' => 'Pages to skip (pages are processed 300 per call; next_offset says where to continue).' ],
			] ], [ __CLASS__, 'sync_seo_tool' ], 'manage_options' );

		Abilities::add( 'fill-rank-math', 'Fill Rank Math from Business Info', 'Fill Rank Math\'s Local SEO and knowledge graph settings from Business Info: business name, description, logo, email, phone numbers, Facebook and other profiles, business type, address, opening hours, price range, map coordinates and contact page. dry_run (default true) lists every setting with what Rank Math holds now, what Business Info would put there and what would happen: fill (empty or Rank Math\'s own starting value), update (Launch Builder\'s earlier value), yours (set in Rank Math: kept unless forced), match, empty (Business Info has nothing yet). A real run takes a snapshot first and records what it wrote and what it replaced; a value changed in Rank Math afterwards is never overwritten. local_seo: true also turns on Rank Math\'s Local SEO module, which Rank Math needs before it prints the address, hours and phone numbers. force: row ids (name, phones, address...), setting keys (titles.knowledgegraph_name) or "all" to replace values set in Rank Math. undo: true puts back what the last fill replaced (settings changed in Rank Math since are left alone). The same preview, fill and undo are on the Business Info screen. cannot lists what Rank Math cannot take from Business Info.',
			[ 'properties' => [
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'List what would change without writing (default true).' ],
				'force'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replace values set in Rank Math: row ids, titles.<key> names, or "all".' ],
				'local_seo' => [ 'type' => 'boolean', 'description' => 'Turn Rank Math\'s Local SEO module on (true) or off (false).' ],
				'undo'      => [ 'type' => 'boolean', 'description' => 'Put back what the last fill replaced. This runs at once: dry_run does not apply to it.' ],
			] ], [ __CLASS__, 'fill_rank_math_tool' ], 'manage_options' );

		Abilities::add( 'preview-seo', 'Preview SEO title and description', 'The SEO title, meta description and robots a post, page, service or area gets, with Rank Math variables and tokens resolved, and where each comes from (meta, role template, post type template). Give template_title / template_description to try a template on that object without saving it; unknown_vars lists variables nothing would replace.',
			[ 'properties' => [
				'post_id'              => [ 'type' => 'integer' ],
				'path'                 => [ 'type' => 'string', 'description' => 'Page path instead of post_id, e.g. "contact".' ],
				'template_title'       => [ 'type' => 'string' ],
				'template_description' => [ 'type' => 'string' ],
			] ], [ __CLASS__, 'preview_seo_tool' ], 'edit_posts', [ 'readonly' => true ] );
	}

	/** site-state: a short compatibility summary. */
	public static function site_state( array &$state ): void {
		$rm = self::rm_active();
		$out = [ 'seo_plugin' => self::seo_plugins() ? implode( ',', self::seo_plugins() ) : 'none' ];
		if ( $rm ) {
			$st = self::rm_status();
			$out['rank_math'] = [ 'version' => self::rm_version(), 'ready' => self::rm_ready(), 'modules_off' => $st['modules_off'], 'keys_to_sync' => $st['to_sync'], 'kept_edits' => $st['kept'] ];
		}
		$out['meta_box'] = self::mb_active();
		if ( self::pm_active() ) {
			$out['perfmatters_risks'] = count( self::perfmatters_report()['risks'] );
		}
		$state['compat'] = $out;
	}

	/** get-playbook: how SEO works with Launch Builder. */
	public static function playbook( array &$pb ): void {
		$pb['seo'] = [
			'owner'      => 'Rank Math owns titles, descriptions, sitemap and schema. Launch Builder configures it (sync-seo) and feeds it; never write SEO plugin options with set-option.',
			'templates'  => 'Title/description templates are per role: sync-seo {templates: {service: {title, description}, area: {...}, page: {...}, conversion: {...}, thanks: {...}}}. Service/area templates go to Rank Math settings; page roles are written to each page (pages an editor changed are kept). Use %sep% for the separator, never a literal "|" or "-".',
			'variables'  => 'Every token has a Rank Math variable: {{lb.phone}} = %lb_phone%, {{lb.city}} = %lb_city%, {{area.name}} = %lb_area_name%, {{service.name}} = %lb_service_name%, {{entry.summary}} = %lb_entry_summary%. Reuse Rank Math\'s own %title%, %sitename%, %sep%, %primary_taxonomy_terms%, %parent_title%. In Yoast the same variables are %%lb_phone%%. Use variables (not {{tokens}}) in SEO fields.',
			'entries'    => 'For services and areas the role template wins over the summary (excerpt) unless the entry has its own SEO description. Services carry a Service entity (provider = the business, areaServed = the areas offering it) inside Rank Math\'s graph; never add AggregateRating or a second LocalBusiness.',
			'faq'        => 'FAQ schema: Kadence accordion faqSchema only on page-specific FAQs; per-entry FAQs (_lb_faq) are emitted by Launch Builder only when the page has no faqSchema accordion.',
			'robots'     => 'Thank-you pages are noindex,follow and out of the sitemap; search and 404 are noindex.',
			'check'      => 'preview-seo shows the resolved title/description for any page; get-compat lists every Rank Math key Launch Builder owns and its status.',
			'indexing'   => 'IndexNow (Rank Math Instant Indexing) is suppressed while the site discourages search engines and during builds.',
			'business'   => 'Business Info fills Rank Math\'s Local SEO and knowledge graph settings (name, description, logo, email, phones, profiles, business type, address, hours, price range, coordinates, contact page): fill-rank-math lists what it would do (dry_run, the default), writes it, and undoes the last write. It keeps anything set in Rank Math unless forced. Rank Math prints address, hours and phones only while its Local SEO module is on (local_seo: true). Never add a second LocalBusiness schema.',
		];
		$pb['tools_by_job']['SEO setup'] = [ 'sync-seo', 'fill-rank-math', 'preview-seo', 'get-compat' ];
		$pb['meta_box'] = [
			'ids'     => 'Card details and Meta Box: a Meta Box field with exactly these ids takes over from the Card details box and edits the same stored data. _lb_icon (services and areas): type Text, a Kadence icon name such as fe_mapPin (anything else is saved as empty). _lb_services (areas only): type Post, post type lb_service, Multiple on, Clone off (one stored row per service; a cloneable field is stored as one serialized row and read wrongly). _lb_summary (services and areas): type Textarea; it is the Excerpt, and line breaks are flattened. A field with another id (icon, service_icon) does not take over: both boxes show and only the _lb_ ids feed the cards.',
			'example' => "A ready-to-paste field group (a small plugin or the theme's functions file; Meta Box Builder takes the same ids and settings):\n" . self::META_BOX_EXAMPLE,
			'tokens'  => '{{meta.field_id}} and {{meta.field_id|fallback}} print a Meta Box field of the page or entry being shown (block text, links, image addresses, SEO titles); always give a fallback on text that matters. Works: text, number, select, radio, checkbox, URL, date and simple lists (comma-separated). A picture gives its full-size address (a gallery or file list gives the first file). Rich text, maps and embeds print as safe HTML. A Post field prints the titles of its published posts, a taxonomy field its term names. Not available: password fields, ids that look like secrets (pass, secret, token, api key, license key), groups, key-value fields and user fields (they print nothing), and there is no {{meta.group.sub}} syntax.',
			'tools'   => 'add-service, add-area, list-entries and the brief read and write title, text, summary, order, icon and services only. They never touch other Meta Box fields (use rest-request, or Meta Box\'s own REST extension, for those).',
			'off'     => 'With Meta Box deactivated nothing is lost: values stay in the database, Card details comes back with Icon and Services, and {{meta.*}} tokens print their fallback or nothing (the site check warns about the ones without a fallback).',
		];
	}

	/** Audit checks, registered with Audit::register_check() from boot(). Site-wide, not batched. */
	const AUDIT_CHECKS = [
		'seo_rm_types_missing'    => [ 'seo', 'check_rm_keys' ],
		'seo_elements_in_sitemap' => [ 'seo', 'check_elements_sitemap' ],
		'seo_rm_modules'          => [ 'seo', 'check_rm_modules' ],
		'seo_tokens_in_meta'      => [ 'seo', 'check_tokens_in_meta' ],
		'seo_unknown_vars'        => [ 'seo', 'check_unknown_vars' ],
		'seo_indexing_private'    => [ 'seo', 'check_indexing' ],
		'perfmatters_risk'        => [ 'performance', 'check_perfmatters' ],
	];

	/** build-site: IndexNow off for the whole build; the SEO sync once everything exists. */
	public static function blueprint_steps(): array {
		return [
			[ 'id' => 'compat-start', 'at' => 'start', 'run' => [ __CLASS__, 'blueprint_start' ] ],
			[ 'id' => 'seo', 'at' => 'done', 'run' => [ __CLASS__, 'blueprint_seo' ] ],
		];
	}

	public static function blueprint_start( array $bp, array $ctx ): array {
		unset( $bp, $ctx );
		self::suppress_indexing( true );
		return [ 'indexnow_suppressed' => true ];
	}

	public static function blueprint_seo( array $bp, array $ctx ): array {
		unset( $ctx );
		$seo = (array) ( $bp['seo'] ?? [] );
		$args = [ 'dry_run' => false ];
		if ( isset( $seo['templates'] ) && is_array( $seo['templates'] ) ) {
			$args['templates'] = $seo['templates'];
		}
		if ( isset( $seo['enable_local_seo'] ) ) {
			$args['local_seo'] = (bool) $seo['enable_local_seo'];
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ 'warnings' => [ 'SEO settings not synced: that needs the manage_options capability (run sync-seo as an administrator).' ] ];
		}
		$r = self::sync_seo( $args );
		if ( isset( $r['error'] ) ) {
			return [ 'warnings' => [ 'seo.templates: ' . $r['error'] ] ];
		}
		return [ 'changed' => count( $r['rank_math']['changes'] ?? [] ), 'kept' => count( $r['rank_math']['kept'] ?? [] ), 'pages' => $r['pages']['totals'] ?? [], 'snapshot' => $r['snapshot'] ?? '', 'warnings' => $r['warnings'] ];
	}

	/** Migration step (every version, and again whenever Rank Math or Launch Builder is activated). */
	public static function migrate_seo( $cursor, int $batch ): array {
		unset( $batch );
		if ( ! self::rm_active() && ! self::yoast_active() ) {
			return [ 'done' => true, 'report' => 'no SEO plugin active' ];
		}
		$offset = max( 0, (int) $cursor );
		$msg = '';
		if ( 0 === $offset ) { // Settings once, then pages in batches of 300 (cursor = page offset).
			$r = self::sync_seo( [ 'dry_run' => false, 'pages' => false ] );
			$msg = sprintf( 'Rank Math keys changed %d, kept %d; ', count( $r['rank_math']['changes'] ?? [] ), count( $r['rank_math']['kept'] ?? [] ) );
		}
		$p = self::apply_role_templates( [ 'offset' => $offset ] );
		return [ 'done' => null === $p['next_offset'], 'cursor' => $p['next_offset'], 'report' => $msg . sprintf( 'pages set %d, kept %d, removed %d', $p['totals']['set'], $p['totals']['kept'], $p['totals']['removed'] ) ];
	}

	/* ------------------------------------------------------------------ detection */

	public static function rm_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) && function_exists( 'rank_math' );
	}

	/**
	 * Is Rank Math actually running? A fresh install whose account is neither connected nor skipped in its setup wizard
	 * (option rank_math_registration_skip) loads nothing: no titles, schema, sitemap, variables or abilities. Its options
	 * can still be written; they apply once the setup is finished.
	 */
	public static function rm_ready(): bool {
		return self::rm_active() && isset( rank_math()->variables );
	}

	public static function rm_version(): string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
	}

	public static function rm_module( string $id ): bool {
		return in_array( $id, (array) get_option( self::RM_MODULES, [] ), true );
	}

	public static function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/** Active SEO plugins: 'rankmath', 'yoast'. */
	public static function seo_plugins(): array {
		$p = [];
		if ( self::rm_active() ) {
			$p[] = 'rankmath';
		}
		if ( self::yoast_active() ) {
			$p[] = 'yoast';
		}
		return $p;
	}

	public static function mb_active(): bool {
		return function_exists( 'rwmb_meta' );
	}

	public static function pm_active(): bool {
		return defined( 'PERFMATTERS_VERSION' );
	}

	/* ------------------------------------------------------------------ SEO strings */

	/**
	 * Clean an SEO string (title, description, focus keyword, template): tags stripped, whitespace collapsed, invalid
	 * UTF-8 dropped. %variables% (and any other % sequence) survive; never use sanitize_text_field() on SEO strings.
	 */
	public static function clean_seo( $s ): string {
		$s = wp_check_invalid_utf8( is_scalar( $s ) ? (string) $s : '' );
		$s = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $s );
		return trim( wp_strip_all_tags( (string) $s, true ) );
	}

	/** Rank Math variables (%x%) to Yoast syntax (%%x%%). Already-translated variables are not doubled. */
	public static function to_yoast( string $s ): string {
		$s = (string) preg_replace( '/%%([A-Za-z][A-Za-z0-9_-]*)%%/', '%$1%', $s );
		return (string) preg_replace( '/%([A-Za-z][A-Za-z0-9_-]*)%/', '%%$1%%', $s );
	}

	/** Where SEO meta is written: the active SEO plugins, Rank Math when none is active (our stack, read once installed). */
	private static function meta_targets(): array {
		$p = self::seo_plugins();
		return $p ? $p : [ 'rankmath' ];
	}

	/**
	 * Write per-object SEO meta for the active SEO plugin(s) only, cleaned with clean_seo() so variables survive; Yoast
	 * copies are translated to %%x%%. $seo: title, description, keyword, robots (list of directives).
	 *
	 * @return string[] meta keys written
	 */
	public static function write_seo_meta( int $post_id, array $seo ): array {
		$targets = self::meta_targets();
		$written = [];
		$put = static function ( string $key, $value ) use ( $post_id, &$written ) {
			update_post_meta( $post_id, $key, $value );
			$written[] = $key;
		};
		foreach ( [ 'title' => [ 'rank_math_title', '_yoast_wpseo_title' ], 'description' => [ 'rank_math_description', '_yoast_wpseo_metadesc' ], 'keyword' => [ 'rank_math_focus_keyword', '_yoast_wpseo_focuskw' ] ] as $field => $keys ) {
			$v = self::clean_seo( $seo[ $field ] ?? '' );
			if ( '' === $v ) {
				continue;
			}
			if ( in_array( 'rankmath', $targets, true ) ) {
				$put( $keys[0], wp_slash( $v ) );
			}
			if ( in_array( 'yoast', $targets, true ) ) {
				$put( $keys[1], wp_slash( 'keyword' === $field ? $v : self::to_yoast( $v ) ) );
			}
		}
		if ( ! empty( $seo['robots'] ) ) {
			$robots = self::robots_list( $seo['robots'] );
			if ( $robots ) {
				if ( in_array( 'rankmath', $targets, true ) ) {
					$put( 'rank_math_robots', $robots );
				}
				if ( in_array( 'yoast', $targets, true ) ) {
					$put( '_yoast_wpseo_meta-robots-noindex', in_array( 'noindex', $robots, true ) ? '1' : '2' );
					$put( '_yoast_wpseo_meta-robots-nofollow', in_array( 'nofollow', $robots, true ) ? '1' : '0' );
				}
			}
		}
		return $written;
	}

	/** A robots list from a list or a comma string: known directives only, index/noindex and follow/nofollow exclusive. */
	public static function robots_list( $in ): array {
		$list = is_array( $in ) ? $in : explode( ',', (string) $in );
		$out = [];
		foreach ( $list as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( in_array( $d, self::ROBOTS, true ) ) {
				$out[ $d ] = true;
			}
		}
		if ( isset( $out['noindex'] ) ) {
			unset( $out['index'] );
		}
		if ( isset( $out['nofollow'] ) ) {
			unset( $out['follow'] );
		}
		return array_keys( $out );
	}

	/* ------------------------------------------------------------------ variables */

	/**
	 * Every Launch Builder SEO variable: name (without %) => { key: 'ns.key', token: '{{ns.key}}', name, description }.
	 * Mapping: {{lb.key}} = %lb_key%, {{ns.key}} = %lb_ns_key% for site/page/entry/area/service. List and HTML Business
	 * Info fields and the two-line (_br) values have no variable (SEO strings are one line of text).
	 */
	public static function seo_vars(): array {
		static $vars = null;
		if ( null !== $vars ) {
			return $vars;
		}
		$vars = [];
		$add = static function ( string $ns, string $key, string $label ) use ( &$vars ) {
			$name = 'lb' === $ns ? 'lb_' . $key : 'lb_' . $ns . '_' . $key;
			if ( isset( $vars[ $name ] ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) {
				return;
			}
			$token = '{{' . $ns . '.' . $key . '}}';
			$vars[ $name ] = [
				'key'         => $ns . '.' . $key,
				'token'       => $token,
				'name'        => 'Launch Builder: ' . ( '' !== $label ? $label : $ns . '.' . $key ),
				/* translators: %s: the Launch Builder token, e.g. {{lb.phone}} */
				'description' => sprintf( __( 'Same value as the token %s.', 'launch-builder' ), $token ),
			];
		};
		foreach ( Tokens::NAMESPACES as $ns => $keys ) {
			if ( 'lb' === $ns || 'meta' === $ns ) {
				continue;
			}
			foreach ( $keys as $k ) {
				$add( $ns, $k, $ns . ' ' . str_replace( '_', ' ', $k ) );
			}
		}
		foreach ( Company::fields() as $k => $f ) {
			if ( ! in_array( $f[1], [ 'list', 'html' ], true ) ) {
				$add( 'lb', (string) $k, (string) $f[0] );
			}
		}
		foreach ( Company::computed() as $k => $label ) {
			if ( '_br' !== substr( (string) $k, -3 ) ) {
				$add( 'lb', (string) $k, (string) $label );
			}
		}
		return $vars;
	}

	/** '{{ns.key}}' (or 'ns.key') => '%lb_…%', or '' when there is no variable for it. */
	public static function var_for_token( string $token ): string {
		$key = trim( preg_replace( '/^\{\{\s*|\s*(\|[^}]*)?\}\}$/', '', trim( $token ) ) );
		foreach ( self::seo_vars() as $name => $row ) {
			if ( $row['key'] === $key ) {
				return '%' . $name . '%';
			}
		}
		return '';
	}

	/** '%lb_…%' (or the bare name) => '{{ns.key}}', or ''. */
	public static function token_for_var( string $var ): string {
		$name = trim( $var, "% \t" );
		$vars = self::seo_vars();
		return isset( $vars[ $name ] ) ? $vars[ $name ]['token'] : '';
	}

	/** Plain-text value of a variable for an explicit object (post, term, Rank Math/Yoast argument object). */
	public static function var_value( string $key, $obj = null ): string {
		if ( is_array( $obj ) || ( is_object( $obj ) && ! ( $obj instanceof \WP_Post ) && ! ( $obj instanceof \WP_Term ) ) ) {
			// Rank Math passes a stdClass built from its defaults (ID '' for terms and archives): never get_post( 0 ),
			// which would fall back to the global post.
			$o = (array) $obj;
			$pid = (int) ( $o['ID'] ?? 0 );
			$tid = (int) ( $o['term_id'] ?? 0 );
			$obj = $pid > 0 ? get_post( $pid ) : ( $tid > 0 ? get_term( $tid ) : null );
		}
		if ( ! ( $obj instanceof \WP_Post ) && ! ( $obj instanceof \WP_Term ) ) {
			$obj = null;
		}
		$v = Tokens::seo_value( $key, $obj );
		if ( '' === $v && 0 !== strpos( $key, 'lb.' ) && false !== strpos( $key, '.' ) && ! self::tokens_live() ) {
			$v = self::interim_value( $key, $obj );
		}
		return self::clean_seo( $v );
	}

	/**
	 * Does Tokens::seo_value() resolve the namespaces beyond lb.* yet? Until it does, interim_value()
	 * gives the SEO variables the values the token engine will give (site, page, entry, area, service), so the default
	 * entry templates (%lb_entry_summary%) never print an empty description.
	 */
	private static function tokens_live(): bool {
		static $live = null;
		if ( null === $live ) {
			$live = '' !== Tokens::seo_value( 'site.name' ) || '' === trim( (string) get_bloginfo( 'name' ) );
		}
		return $live;
	}

	/** Interim plain-text values for the non-lb token namespaces (see tokens_live()); '' for anything else. */
	private static function interim_value( string $key, $obj ): string {
		[ $ns, $k ] = explode( '.', $key, 2 );
		if ( 'site' === $ns ) {
			$site = [ 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'tagline' => get_bloginfo( 'description' ) ];
			return (string) ( $site[ $k ] ?? '' );
		}
		$p = $obj instanceof \WP_Post ? $obj : null;
		if ( ! $p ) {
			return '';
		}
		$entry = in_array( $p->post_type, [ Entries::SERVICE, Entries::AREA ], true );
		if ( ( 'area' === $ns && Entries::AREA !== $p->post_type ) || ( 'service' === $ns && Entries::SERVICE !== $p->post_type ) || ( 'entry' === $ns && ! $entry ) ) {
			return '';
		}
		$summary = static function () use ( $p ): string {
			$s = (string) get_post_meta( $p->ID, '_lb_summary', true );
			if ( '' === trim( $s ) ) {
				$s = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $p->post_content ) ) ), 22 );
			}
			return $s;
		};
		$cat = static function () use ( $p ): ?\WP_Term {
			return Entries::SERVICE === $p->post_type ? self::primary_category( $p->ID ) : null;
		};
		switch ( $k ) {
			case 'title':
			case 'name':
				return self::plain_title( $p );
			case 'summary':
				return in_array( $ns, [ 'entry', 'page' ], true ) ? $summary() : '';
			case 'url':
				return (string) get_permalink( $p );
			case 'category':
				return $cat() ? $cat()->name : '';
			case 'category_url':
				$t = $cat();
				$link = $t ? get_term_link( $t ) : '';
				return is_string( $link ) ? $link : '';
			case 'parent':
				$parent = $p->post_parent ? get_post( $p->post_parent ) : null;
				return $parent ? self::plain_title( $parent ) : '';
			case 'parent_url':
				return $p->post_parent ? (string) get_permalink( $p->post_parent ) : '';
			case 'role':
				return 'page' === $ns ? Roles::of( $p->ID ) : '';
			case 'role_label':
				return 'page' === $ns ? Roles::label( Roles::of( $p->ID ) ) : '';
			case 'type':
				return 'entry' === $ns ? ( Entries::SERVICE === $p->post_type ? 'service' : 'area' ) : '';
		}
		return '';
	}

	/** Rank Math: register %lb_…% on rank_math/vars/register_extra_replacements (front end at wp, admin, or after setup()). */
	public static function register_rm_vars(): void {
		if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
			return;
		}
		$example_post = is_admin() ? get_post() : null;
		foreach ( self::seo_vars() as $name => $row ) {
			$key = $row['key'];
			rank_math_register_var_replacement(
				$name,
				[
					'name'        => $row['name'],
					'description' => $row['description'],
					'variable'    => $name,
					'example'     => $example_post ? self::var_value( $key, $example_post ) : '',
					'nocache'     => true, // Resolved per object: the replacer's per-template cache would repeat the first post's value.
				],
				static function ( $var_args = [], $obj = null ) use ( $key ) {
					unset( $var_args );
					return self::var_value( $key, $obj );
				}
			);
		}
	}

	/** Yoast: the same variables as %%lb_…%% (callback receives the variable name and Yoast's post object). */
	public static function register_yoast_vars(): void {
		if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
			return;
		}
		foreach ( self::seo_vars() as $name => $row ) {
			$key = $row['key'];
			wpseo_register_var_replacement(
				'%%' . $name . '%%',
				static function ( $var = '', $args = null ) use ( $key ) {
					unset( $var );
					return self::var_value( $key, $args );
				},
				'advanced',
				$row['description']
			);
		}
	}

	/** Make sure Rank Math's variables (and ours) are registered in REST/CLI/cron contexts. */
	private static function rm_setup_vars(): bool {
		if ( ! self::rm_active() || ! isset( rank_math()->variables ) ) {
			return false;
		}
		$v = rank_math()->variables;
		if ( method_exists( $v, 'setup' ) ) {
			$v->setup(); // No-op when already set up.
		}
		return true;
	}

	/** Rank Math replace_vars() for an object, cleaned; '' without Rank Math. */
	private static function rm_replace( string $template, $obj ): string {
		if ( '' === $template || ! self::rm_setup_vars() || ! class_exists( '\RankMath\Helper' ) ) {
			return '';
		}
		return self::clean_seo( \RankMath\Helper::replace_vars( $template, $obj ) );
	}

	/**
	 * Without a running Rank Math (not installed, or its setup pending): our %lb_*% variables plus the few core ones
	 * every SEO plugin has (%title%, %sitename%, %sitedesc%, %sep% as " - ", %excerpt%); anything else stays as written.
	 */
	private static function basic_replace( string $template, \WP_Post $post ): string {
		$s = self::clean_seo( $template );
		$s = (string) preg_replace_callback(
			'/%(lb_[A-Za-z0-9_-]+)%/',
			static function ( $m ) use ( $post ) {
				$row = self::seo_vars()[ $m[1] ] ?? null;
				return $row ? self::var_value( $row['key'], $post ) : $m[0];
			},
			$s
		);
		$excerpt = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( $post->post_content ) ), 30 );
		$s = strtr( $s, [ '%title%' => self::plain_title( $post ), '%sitename%' => get_bloginfo( 'name' ), '%sitedesc%' => get_bloginfo( 'description' ), '%sep%' => '-', '%excerpt%' => self::clean_seo( $excerpt ) ] );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Variables in a template that nothing replaces (neither Rank Math's nor ours): brief validation lists them.
	 *
	 * @return string[] names without %
	 */
	public static function unknown_vars( string $template ): array {
		if ( ! preg_match_all( '/%([A-Za-z][A-Za-z0-9_-]*)(\([^)%]*\))?%/', $template, $m ) ) {
			return [];
		}
		$known = array_keys( self::seo_vars() );
		if ( self::rm_setup_vars() && method_exists( rank_math()->variables, 'get_replacements' ) ) {
			$known = array_merge( $known, array_keys( (array) rank_math()->variables->get_replacements() ) );
		} else {
			$known = array_merge( $known, [ 'sep', 'title', 'parent_title', 'sitename', 'sitedesc', 'excerpt', 'excerpt_only', 'seo_title', 'seo_description', 'url', 'primary_taxonomy_terms', 'category', 'categories', 'term', 'term_description', 'search_query', 'page', 'date', 'modified', 'currentyear', 'currentmonth', 'currentday', 'focuskw', 'keywords', 'id', 'name', 'org_name', 'org_url', 'org_logo', 'post_thumbnail', 'customfield', 'customterm', 'customterm_desc', 'count', 'filename', 'currenttime', 'currentdate', 'pt_single', 'pt_plural' ] );
		}
		$out = [];
		foreach ( $m[1] as $name ) {
			if ( ! in_array( $name, $known, true ) ) {
				$out[ $name ] = true;
			}
		}
		return array_keys( $out );
	}

	/* ------------------------------------------------------------------ Rank Math settings */

	/** A Rank Math option as stored (no Business Info overlay). */
	private static function raw_option( string $name ): array {
		$was = self::$raw;
		self::$raw = true;
		$v = get_option( $name, [] );
		self::$raw = $was;
		return is_array( $v ) ? $v : [];
	}

	/** Role templates (stored option). */
	public static function templates(): array {
		$t = get_option( self::OPT_TEMPLATES, [] );
		return is_array( $t ) ? $t : [];
	}

	/** A role's template field ('' when none). Service/area fall back to DEFAULT_TEMPLATES. */
	public static function role_template( string $role, string $field ): string {
		$t = self::templates();
		if ( isset( $t[ $role ][ $field ] ) && is_string( $t[ $role ][ $field ] ) ) {
			return $t[ $role ][ $field ];
		}
		return (string) ( self::DEFAULT_TEMPLATES[ $role ][ $field ] ?? '' );
	}

	/**
	 * Everything Launch Builder wants in Rank Math's options: group => key => value. Business Info keys appear only when
	 * Business Info has a real (non-demo) value for them.
	 */
	public static function rm_desired( string $scope = 'all' ): array {
		if ( 'business' === $scope ) { // Only what Business Info fills (the Business Info screen's Rank Math card, fill-rank-math).
			$own = [ 'knowledgegraph_type' => self::RM_TITLES['knowledgegraph_type'] ] + self::identity();
			return [ 'titles' => array_intersect_key( $own, array_flip( self::BUSINESS_KEYS ) ) ];
		}
		$titles = self::RM_TITLES;
		foreach ( self::OPTION_ROLES as $role => $keys ) {
			foreach ( [ 'title' => $keys[0], 'description' => $keys[1] ] as $field => $key ) {
				$tpl = '' !== $key ? self::clean_seo( self::role_template( $role, $field ) ) : '';
				if ( '' !== $tpl ) {
					$titles[ $key ] = $tpl;
				}
			}
		}
		return [ 'sitemap' => self::RM_SITEMAP, 'titles' => $titles + self::identity() ];
	}

	/** Status of every owned key without writing: [ rows, to_sync, kept, modules_off ]. */
	public static function rm_status(): array {
		$rows = [];
		$to_sync = 0;
		$kept = 0;
		foreach ( self::rm_desired() as $group => $keys ) {
			$raw = self::raw_option( self::RM_OPTIONS[ $group ] );
			foreach ( $keys as $key => $want ) {
				$st = self::key_status( $group, $key, $raw, $want );
				$rows[] = [ 'key' => $group . '.' . $key, 'status' => $st, 'value' => self::show( $raw[ $key ] ?? null ), 'wanted' => self::show( $want ) ];
				if ( 'kept_edit' === $st ) {
					$kept++;
				} elseif ( 'ok' !== $st ) {
					$to_sync++;
				}
			}
		}
		$off = array_values( array_filter( [ 'sitemap', 'rich-snippet' ], static function ( $m ) { return ! self::rm_module( $m ); } ) );
		return [ 'rows' => $rows, 'to_sync' => $to_sync, 'kept' => $kept, 'modules_off' => $off ];
	}

	/** Short display of a setting value. */
	private static function show( $v ) {
		if ( is_array( $v ) ) {
			return $v;
		}
		return null === $v ? null : (string) $v;
	}

	private static function same( $a, $b ): bool {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return Ledger::hash_value( $a ) === Ledger::hash_value( $b );
	}

	/** ok | missing | differs | kept_edit for one key. */
	private static function key_status( string $group, string $key, array $raw, $want ): string {
		switch ( self::key_state( $group, $key, $raw, $want ) ) {
			case 'match':
				return 'ok';
			case 'edited':
			case 'own':
				return 'kept_edit';
			case 'fill':
				return array_key_exists( $key, $raw ) ? 'differs' : 'missing'; // Rank Math's screens always save every key: an absent key was reset, never an admin choice.
		}
		return 'differs';
	}

	/**
	 * Where one Rank Math key stands against the value Launch Builder wants:
	 *   match   already equal
	 *   fill    absent, empty or still Rank Math's own starting value
	 *   update  Launch Builder wrote the current value earlier and nobody changed it since
	 *   edited  Launch Builder wrote it earlier and somebody changed it in Rank Math since
	 *   own     somebody typed it into Rank Math and Launch Builder never wrote it (Business Info keys only)
	 * edited and own are kept unless forced.
	 */
	private static function key_state( string $group, string $key, array $raw, $want ): string {
		if ( ! array_key_exists( $key, $raw ) ) {
			return 'fill';
		}
		$cur = $raw[ $key ];
		if ( self::same( $cur, $want ) ) {
			return 'match';
		}
		$lk = self::ledger_key( $group, $key );
		if ( null !== Ledger::get( $lk ) ) {
			return Ledger::is_edited( $lk, Ledger::hash_value( $cur ) ) ? 'edited' : 'update';
		}
		$blank = self::rm_blank( $cur ) || self::rm_starting_value( $key, $cur );
		if ( 'titles' === $group && in_array( $key, self::BUSINESS_KEYS, true ) && ! $blank ) {
			if ( in_array( $key, (array) get_option( self::OPT_OWNED, [] ), true ) ) {
				return 'update'; // Launch Builder already overlays this key: it is ours, whether or not a ledger row exists yet.
			}
			return 'own';
		}
		return $blank ? 'fill' : 'update';
	}

	/** Is a Rank Math setting value empty (nothing, '', or a list of empty rows)? */
	private static function rm_blank( $v ): bool {
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( ! self::rm_blank( $x ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $v || '' === trim( (string) $v ) || '0' === (string) $v;
	}

	/** Is this the value Rank Math fills in by itself when it is installed (so nobody chose it)? */
	private static function rm_starting_value( string $key, $cur ): bool {
		switch ( $key ) {
			case 'knowledgegraph_type':
				return 'person' === $cur;
			case 'knowledgegraph_name':
				$name = (string) get_bloginfo( 'name' );
				// The site title now, or what a new WordPress site is called before anyone renames it.
				return in_array( trim( (string) $cur ), [ $name, wp_specialchars_decode( $name ), 'My WordPress Website', 'My WordPress Blog', 'WordPress', 'Just another WordPress site' ], true );
			case 'url':
				return untrailingslashit( (string) $cur ) === untrailingslashit( home_url() );
			case 'local_business_type':
				return 'Organization' === $cur;
			case 'opening_hours': // Every day 09:00-17:00.
				return is_array( $cur ) && 7 === count( $cur ) && ! array_filter( $cur, static function ( $r ) { return ! is_array( $r ) || '09:00-17:00' !== (string) ( $r['time'] ?? '' ); } );
		}
		return false;
	}

	private static function ledger_key( string $group, string $key ): string {
		return 'rankmath:' . $group . ':' . $key;
	}

	/**
	 * Merge the owned keys into Rank Math's options (see the class comment). Returns the change list, kept edits and how
	 * each key was written.
	 *
	 * @param array $args { dry_run: bool, force: string[] ('group.key' or 'all'), local_seo: ?bool, label: string, scope: 'all' | 'business' }
	 */
	public static function rank_math_settings( array $args = [] ): array {
		$out = [ 'active' => self::rm_active(), 'version' => self::rm_version(), 'changes' => [], 'kept' => [], 'ok' => 0, 'via' => [], 'modules' => [], 'warnings' => [] ];
		if ( ! $out['active'] ) {
			return $out;
		}
		$dry = ! empty( $args['dry_run'] );
		$force = array_map( 'strval', (array) ( $args['force'] ?? [] ) );
		$forced = static function ( string $k ) use ( $force ): bool { return in_array( 'all', $force, true ) || in_array( $k, $force, true ); };
		$business = 'business' === (string) ( $args['scope'] ?? 'all' );

		$plan = []; // group => key => value
		$owned_now = [];
		$desired = self::rm_desired( $business ? 'business' : 'all' );
		foreach ( $desired as $group => $keys ) {
			$raw = self::raw_option( self::RM_OPTIONS[ $group ] );
			foreach ( $keys as $key => $want ) {
				$st = self::key_status( $group, $key, $raw, $want );
				$id = $group . '.' . $key;
				if ( 'ok' === $st ) {
					$out['ok']++;
					if ( 'titles' === $group && in_array( $key, self::OVERLAY_KEYS, true ) ) {
						$owned_now[] = $key;
					}
					continue;
				}
				if ( 'kept_edit' === $st && ! $forced( $id ) ) {
					$out['kept'][] = [ 'key' => $id, 'value' => self::show( $raw[ $key ] ?? null ), 'wanted' => self::show( $want ) ];
					continue;
				}
				$plan[ $group ][ $key ] = $want;
				$out['changes'][] = [ 'key' => $id, 'old' => self::show( $raw[ $key ] ?? null ), 'new' => self::show( $want ) ];
				if ( 'titles' === $group && in_array( $key, self::OVERLAY_KEYS, true ) ) {
					$owned_now[] = $key;
				}
			}
		}

		// Local SEO module only when asked.
		$modules = [];
		if ( isset( $args['local_seo'] ) && null !== $args['local_seo'] ) {
			$on = (bool) $args['local_seo'];
			if ( $on !== self::rm_module( 'local-seo' ) ) {
				$modules['local-seo'] = $on;
				$out['modules'][] = [ 'module' => 'local-seo', 'old' => ! $on, 'new' => $on ];
			}
		}
		foreach ( $business ? [ 'rich-snippet' ] : [ 'sitemap', 'rich-snippet' ] as $m ) {
			if ( ! self::rm_module( $m ) ) {
				/* translators: %s: Rank Math module id */
				$out['warnings'][] = sprintf( __( 'Rank Math module "%s" is off: the sitemap/schema settings are stored but have no effect until it is on.', 'launch-builder' ), $m );
			}
		}
		if ( $business && ! self::rm_module( 'local-seo' ) && empty( $modules['local-seo'] ) ) {
			$out['warnings'][] = __( 'Rank Math\'s Local SEO module is off: the address, hours, phone numbers and business type are stored, but Rank Math prints them only once that module is on.', 'launch-builder' );
		}
		if ( ! self::rm_ready() ) {
			$out['warnings'][] = __( 'Rank Math is active but not set up (its setup wizard was neither finished nor skipped): the settings are stored, but Rank Math outputs nothing until then.', 'launch-builder' );
		}
		if ( '' === ( self::identity()['knowledgegraph_name'] ?? '' ) ) {
			$out['warnings'][] = __( 'Business Info has no business name yet: the knowledge graph keeps the site title.', 'launch-builder' );
		}

		if ( $dry || ( ! $plan && ! $modules ) ) {
			if ( ! $dry ) {
				self::set_owned( $owned_now );
			}
			return $out;
		}

		self::$raw = true;
		$out['snapshot'] = Abilities::snapshot( (string) ( $args['label'] ?? 'sync-seo' ), [], array_merge( array_values( self::RM_OPTIONS ), [ self::RM_MODULES ] ) );
		self::$writing = true;
		try {
			$out['via'] = self::rm_write( $plan, $modules );
		} finally {
			self::$writing = false;
			self::$raw = false;
		}
		// Ledger: one row per owned key, hash of what is stored now (re-read).
		$rows = [];
		foreach ( $plan as $group => $keys ) {
			$raw = self::raw_option( self::RM_OPTIONS[ $group ] );
			foreach ( array_keys( $keys ) as $key ) {
				$rows[ self::ledger_key( $group, $key ) ] = [ 'option', self::RM_OPTIONS[ $group ], Ledger::hash_value( $raw[ $key ] ?? null ), 'set' ];
				if ( ! self::same( $raw[ $key ] ?? null, $keys[ $key ] ) ) {
					/* translators: %s: setting key */
					$out['warnings'][] = sprintf( __( '%s did not keep the value written (another plugin filters it).', 'launch-builder' ), $group . '.' . $key );
				}
			}
		}
		Ledger::record_many( $rows );
		self::set_owned( $owned_now );
		self::rm_reset();
		return $out;
	}

	/** Remember which Business Info keys are overlaid (only when the list changed). */
	private static function set_owned( array $keys ): void {
		$keys = array_values( array_unique( array_intersect( $keys, self::OVERLAY_KEYS ) ) );
		sort( $keys );
		$old = get_option( self::OPT_OWNED, null );
		if ( $old !== $keys ) {
			update_option( self::OPT_OWNED, $keys, true );
		}
	}

	/** Reset Rank Math's in-memory settings and the sitemap cache after a write. */
	private static function rm_reset(): void {
		if ( self::rm_active() && isset( rank_math()->settings ) && method_exists( rank_math()->settings, 'reset' ) ) {
			rank_math()->settings->reset();
		}
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}

	/**
	 * Write the plan: Rank Math's own abilities first (sitemap post types/taxonomies, per-type SEO settings, website
	 * identity, modules) for the keys they cover and whose values they store unchanged, then a direct key-level merge for
	 * every key still not equal to the wanted value.
	 *
	 * @return array<string,string> 'group.key' => 'ability:<name>' | 'direct'
	 */
	private static function rm_write( array $plan, array $modules ): array {
		$via = [];
		$safe = static function ( $v ): bool { return is_string( $v ) && sanitize_text_field( $v ) === $v; };
		$calls = [];
		// Sitemap inclusion: one call per post type / taxonomy (the ability refuses the whole call when one of them is not
		// public, e.g. kadence_element on some Kadence versions).
		foreach ( (array) ( $plan['sitemap'] ?? [] ) as $key => $v ) {
			if ( preg_match( '/^(pt|tax)_(.+)_sitemap$/', $key, $m ) ) {
				$name = 'rank-math/set-sitemap-settings#' . $key;
				$calls[ $name ][ 'pt' === $m[1] ? 'post_types' : 'taxonomies' ][ $m[2] ] = 'on' === $v;
				$calls[ $name ]['_keys'][] = 'sitemap.' . $key;
			}
		}
		// Per post type settings: one call per type, so one unknown type does not block the others.
		foreach ( (array) ( $plan['titles'] ?? [] ) as $key => $v ) {
			if ( preg_match( '/^pt_(.+)_(title|description|default_rich_snippet|add_meta_box)$/', $key, $m ) && ( 'add_meta_box' === $m[2] || $safe( $v ) ) ) {
				$name = 'rank-math/set-post-type-seo-settings#' . $m[1];
				$calls[ $name ]['post_types'][ $m[1] ][ $m[2] ] = 'add_meta_box' === $m[2] ? 'on' === $v : $v;
				$calls[ $name ]['_keys'][] = 'titles.' . $key;
			} elseif ( in_array( $key, [ 'knowledgegraph_type', 'knowledgegraph_name', 'local_business_type' ], true ) && $safe( $v ) ) {
				$calls['rank-math/set-website-identity'][ $key ] = $v;
				$calls['rank-math/set-website-identity']['_keys'][] = 'titles.' . $key;
			} elseif ( 'url' === $key && is_string( $v ) && esc_url_raw( $v ) === $v ) {
				$calls['rank-math/set-website-identity']['url'] = $v;
				$calls['rank-math/set-website-identity']['_keys'][] = 'titles.url';
			}
		}
		if ( $modules ) {
			$calls['rank-math/set-module-status'] = [ 'modules' => $modules, '_keys' => [ 'modules' ] ];
		}
		foreach ( $calls as $name => $input ) {
			$keys = (array) $input['_keys'];
			unset( $input['_keys'] );
			$ability = explode( '#', $name )[0];
			$r = self::run_ability( $ability, $input );
			$tag = null === $r ? '' : ( is_wp_error( $r ) ? 'direct (ability error: ' . $r->get_error_message() . ')' : 'ability:' . $ability );
			foreach ( $keys as $k ) {
				if ( '' !== $tag ) {
					$via[ $k ] = $tag;
				}
			}
		}
		// Whatever the abilities did not store exactly: direct key-level merge on the raw option.
		foreach ( $plan as $group => $keys ) {
			$opt = self::RM_OPTIONS[ $group ];
			$raw = self::raw_option( $opt );
			$changed = false;
			foreach ( $keys as $key => $v ) {
				if ( ! array_key_exists( $key, $raw ) || ! self::same( $raw[ $key ], $v ) ) {
					$raw[ $key ] = $v;
					$changed = true;
					$id = $group . '.' . $key;
					$via[ $id ] = isset( $via[ $id ] ) && 0 === strpos( $via[ $id ], 'direct (' ) ? $via[ $id ] : 'direct';
				} elseif ( ! isset( $via[ $group . '.' . $key ] ) ) {
					$via[ $group . '.' . $key ] = 'direct';
				}
			}
			if ( $changed ) {
				update_option( $opt, $raw );
			}
		}
		if ( $modules ) {
			$active = array_values( (array) get_option( self::RM_MODULES, [] ) );
			$want = $active;
			foreach ( $modules as $m => $on ) {
				$want = $on ? array_values( array_unique( array_merge( $want, [ $m ] ) ) ) : array_values( array_diff( $want, [ $m ] ) );
			}
			if ( array_diff( $want, $active ) || array_diff( $active, $want ) ) {
				update_option( self::RM_MODULES, $want );
				$via['modules'] = 'direct';
			}
		}
		return $via;
	}

	/**
	 * Run a WordPress ability (Rank Math's), or null when the Abilities API or the ability is missing. Rank Math reports
	 * validation problems as { error: { code, message } }, which becomes a WP_Error.
	 *
	 * @return mixed|null|\WP_Error
	 */
	private static function run_ability( string $name, array $input ) {
		if ( ! function_exists( 'wp_get_ability' ) || ! did_action( 'init' ) ) {
			return null;
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return null;
		}
		try {
			$r = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'lb_ability', $e->getMessage() );
		}
		if ( is_array( $r ) && ! empty( $r['error'] ) ) {
			return new \WP_Error( 'lb_ability', (string) ( $r['error']['message'] ?? 'error' ) );
		}
		return $r;
	}

	/* ------------------------------------------------------------------ Business Info overlay */

	/** Real (non-demo) Business Info value. */
	private static function biz( array $resolved, string $key ): string {
		return isset( $resolved[ $key ] ) && 'default' !== $resolved[ $key ]['from'] ? trim( (string) $resolved[ $key ]['value'] ) : '';
	}

	/**
	 * A Business Info list field (hours_spec, areas_served...) as Business Info reads it: the saved rows, or the rows from
	 * another settings page when one is set as the source, so those reach Rank Math and the schema too.
	 */
	public static function biz_list( string $key ): array {
		return Company::list_value( $key );
	}

	/**
	 * Rank Math titles keys built from Business Info (only keys with a real value). Valid only after init (logo, contact
	 * page and the business type list need WordPress loaded); before that it is empty.
	 */
	public static function identity(): array {
		if ( null !== self::$identity ) {
			return self::$identity;
		}
		if ( ! did_action( 'init' ) ) {
			return [];
		}
		$r = Company::resolve();
		$v = static function ( string $k ) use ( $r ): string { return self::biz( $r, $k ); };
		$out = [ 'url' => home_url( '/' ) ];
		if ( '' !== $v( 'name' ) ) {
			$out['knowledgegraph_name'] = $v( 'name' );
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
		if ( $logo_url ) {
			$out['knowledgegraph_logo'] = $logo_url;
			$out['knowledgegraph_logo_id'] = $logo;
		}
		if ( '' !== $v( 'about' ) ) {
			$out['organization_description'] = $v( 'about' );
		}
		if ( is_email( $v( 'email' ) ) ) {
			$out['email'] = $v( 'email' );
		}
		$phone = self::schema_phone( $v( 'phone' ) );
		if ( '' !== $phone ) {
			$out['phone'] = $phone;
			$out['phone_numbers'] = [ [ 'type' => 'customer support', 'number' => $phone ] ];
			$em = self::schema_phone( $v( 'emergency_phone' ) );
			if ( '' !== $em && $em !== $phone ) {
				$out['phone_numbers'][] = [ 'type' => 'emergency', 'number' => $em ];
			}
		}
		$socials = [];
		foreach ( [ 'facebook', 'instagram', 'youtube', 'google', 'linkedin', 'x', 'yelp', 'tiktok', 'nextdoor', 'bbb', 'angi', 'houzz' ] as $s ) {
			$u = $v( $s );
			if ( preg_match( '#^https?://[^\s]+\.[^\s]+#i', $u ) ) {
				$socials[ $s ] = esc_url_raw( $u );
			}
		}
		if ( isset( $socials['facebook'] ) ) {
			$out['social_url_facebook'] = $socials['facebook'];
			unset( $socials['facebook'] );
		}
		if ( $socials ) {
			$out['social_additional_profiles'] = implode( "\n", $socials );
		}
		$type = self::business_type( $v( 'business_type' ) );
		if ( '' !== $type ) {
			$out['local_business_type'] = $type;
		}
		$addr = array_filter( [ 'streetAddress' => $v( 'street' ), 'addressLocality' => $v( 'locality' ), 'addressRegion' => $v( 'region' ), 'postalCode' => $v( 'postal_code' ), 'addressCountry' => $v( 'country' ) ], 'strlen' );
		if ( isset( $addr['streetAddress'] ) || isset( $addr['addressLocality'] ) ) {
			$out['local_address'] = $addr;
		}
		$hours = self::opening_hours( self::biz_list( 'hours_spec' ), in_array( strtolower( $v( 'is_24_7' ) ), [ '1', 'true', 'yes', 'on' ], true ) );
		if ( $hours ) {
			$out['opening_hours'] = $hours;
		}
		if ( preg_match( '/^\${1,4}$/', $v( 'price_range' ) ) ) {
			$out['price_range'] = $v( 'price_range' );
		}
		if ( is_numeric( $v( 'lat' ) ) && is_numeric( $v( 'lng' ) ) ) {
			$out['geo'] = (float) $v( 'lat' ) . ',' . (float) $v( 'lng' );
		}
		$contact = '' !== $v( 'quote_url' ) ? url_to_postid( $v( 'quote_url' ) ) : 0;
		if ( $contact && 'page' === get_post_type( $contact ) ) {
			$out['local_seo_contact_page'] = $contact;
		}
		return self::$identity = $out;
	}

	/** Forget the per-request overlay values (after a Business Info change in the same request). */
	public static function flush_identity(): void {
		self::$identity = null;
	}

	/** Phone in Rank Math's international format (+1-512-555-0123) from a displayed number; '' when it has no digits. */
	private static function schema_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		if ( strlen( $d ) < 7 ) {
			return '';
		}
		if ( 10 === strlen( $d ) && 0 !== strpos( trim( $phone ), '+' ) ) {
			$d = '1' . $d;
		}
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			return '+1-' . substr( $d, 1, 3 ) . '-' . substr( $d, 4, 3 ) . '-' . substr( $d, 7 );
		}
		return '+' . $d;
	}

	/** Rank Math business type key ("Plumber", "HVACBusiness") for a Business Info value, or '' when Rank Math does not know it. */
	private static function business_type( string $t ): string {
		static $types = null;
		if ( '' === $t || ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'choices_business_types' ) ) {
			return '';
		}
		if ( null === $types ) {
			$types = array_keys( (array) \RankMath\Helper::choices_business_types() );
		}
		$want = strtolower( (string) preg_replace( '/[^A-Za-z]/', '', $t ) );
		foreach ( $types as $k ) {
			if ( strtolower( (string) $k ) === $want ) {
				return (string) $k;
			}
		}
		return '';
	}

	/**
	 * Rank Math opening_hours rows [{day: 'Monday', time: '08:00-18:00'}] from Business Info hours_spec
	 * [{days: ['Mo','Tu'] | 'Mo-Fr', opens: '08:00', closes: '18:00'}]; open 24/7 gives every day 00:00-23:59.
	 */
	public static function opening_hours( array $spec, bool $always = false ): array {
		$days = [ 'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday' ];
		if ( $always ) {
			return array_map( static function ( $d ) { return [ 'day' => $d, 'time' => '00:00-23:59' ]; }, array_values( $days ) );
		}
		$order = array_keys( $days );
		$out = [];
		foreach ( $spec as $row ) {
			$row = (array) $row;
			$open = (string) ( $row['opens'] ?? '' );
			$close = (string) ( $row['closes'] ?? '' );
			if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $open ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $close ) ) {
				continue;
			}
			$list = $row['days'] ?? [];
			if ( is_string( $list ) ) {
				$list = preg_split( '/\s*,\s*/', $list );
			}
			$expanded = [];
			foreach ( (array) $list as $d ) {
				$parts = array_map( static function ( $x ) { return substr( strtolower( trim( (string) $x ) ), 0, 2 ); }, explode( '-', (string) $d ) );
				if ( 2 === count( $parts ) && false !== array_search( $parts[0], $order, true ) && false !== array_search( $parts[1], $order, true ) ) {
					$a = array_search( $parts[0], $order, true );
					$b = array_search( $parts[1], $order, true );
					for ( $i = $a; $i <= $b; $i++ ) {
						$expanded[] = $order[ $i ];
					}
				} elseif ( isset( $days[ $parts[0] ] ) ) {
					$expanded[] = $parts[0];
				}
			}
			foreach ( array_unique( $expanded ) as $d ) {
				$out[] = [ 'day' => $days[ $d ], 'time' => sprintf( '%05s-%05s', $open, $close ) ];
			}
		}
		return $out;
	}

	/**
	 * Filter option_rank-math-options-titles: Business Info values over the keys Launch Builder owns (not in wp-admin,
	 * where Rank Math's settings screen must show and save what is stored; never while Launch Builder reads raw).
	 */
	public static function overlay_titles( $value ) {
		if ( self::$raw || ! is_array( $value ) || is_admin() || ! did_action( 'init' ) ) {
			return $value;
		}
		$owned = get_option( self::OPT_OWNED, [] );
		if ( ! is_array( $owned ) || ! $owned ) {
			return $value;
		}
		$id = self::identity();
		foreach ( $owned as $k ) {
			if ( array_key_exists( $k, $id ) ) {
				$value[ $k ] = $id[ $k ];
			}
		}
		return $value;
	}

	/** init p1: Rank Math may have cached its settings before init (without the overlay): read them again. */
	public static function refresh_rm_settings(): void {
		if ( is_admin() || ! self::rm_active() || ! get_option( self::OPT_OWNED, [] ) ) {
			return;
		}
		if ( isset( rank_math()->settings ) && method_exists( rank_math()->settings, 'reset' ) ) {
			rank_math()->settings->reset();
		}
	}

	/**
	 * A titles save by anyone but Launch Builder: an owned key whose value changed to something other than the Business
	 * Info value was edited by an admin, so it stops being overlaid (the ledger reports it as a kept edit).
	 */
	public static function titles_saved( $old, $new ): void {
		if ( self::$writing || ! is_array( $new ) ) {
			return;
		}
		$owned = get_option( self::OPT_OWNED, [] );
		if ( ! is_array( $owned ) || ! $owned ) {
			return;
		}
		$old = is_array( $old ) ? $old : [];
		$id = self::identity();
		$keep = [];
		foreach ( $owned as $k ) {
			$now = $new[ $k ] ?? null;
			if ( self::same( $old[ $k ] ?? null, $now ) || ( array_key_exists( $k, $id ) && self::same( $id[ $k ], $now ) ) ) {
				$keep[] = $k;
			}
		}
		if ( $keep !== $owned ) {
			update_option( self::OPT_OWNED, $keep, true );
		}
	}

	/* ------------------------------------------------------------------ Business Info -> Rank Math (preview, fill, undo) */

	/**
	 * What Business Info fills in Rank Math, one entry per setting a person sees in Rank Math's Local SEO tab: label, the
	 * Rank Math titles keys behind it, the Business Info fields it comes from (or a plain-words source).
	 *
	 * @return array<string,array{label:string,keys:string[],from:string[],from_text:string}>
	 */
	public static function business_map(): array {
		return [
			'type'     => [ 'label' => __( 'Site type', 'launch-builder' ), 'keys' => [ 'knowledgegraph_type' ], 'from' => [], 'from_text' => __( 'A business, not a person', 'launch-builder' ) ],
			'name'     => [ 'label' => __( 'Business name', 'launch-builder' ), 'keys' => [ 'knowledgegraph_name' ], 'from' => [ 'name' ], 'from_text' => '' ],
			'url'      => [ 'label' => __( 'Website address', 'launch-builder' ), 'keys' => [ 'url' ], 'from' => [], 'from_text' => __( 'This site\'s address', 'launch-builder' ) ],
			'logo'     => [ 'label' => __( 'Logo', 'launch-builder' ), 'keys' => [ 'knowledgegraph_logo', 'knowledgegraph_logo_id' ], 'from' => [], 'from_text' => __( 'The site logo (Appearance, Customize)', 'launch-builder' ) ],
			'about'    => [ 'label' => __( 'Description', 'launch-builder' ), 'keys' => [ 'organization_description' ], 'from' => [ 'about' ], 'from_text' => '' ],
			'email'    => [ 'label' => __( 'Email', 'launch-builder' ), 'keys' => [ 'email' ], 'from' => [ 'email' ], 'from_text' => '' ],
			'phones'   => [ 'label' => __( 'Phone numbers', 'launch-builder' ), 'keys' => [ 'phone_numbers', 'phone' ], 'from' => [ 'phone', 'emergency_phone' ], 'from_text' => '' ],
			'facebook' => [ 'label' => __( 'Facebook page', 'launch-builder' ), 'keys' => [ 'social_url_facebook' ], 'from' => [ 'facebook' ], 'from_text' => '' ],
			'profiles' => [ 'label' => __( 'Other profiles', 'launch-builder' ), 'keys' => [ 'social_additional_profiles' ], 'from' => [ 'instagram', 'youtube', 'google', 'linkedin', 'x', 'yelp', 'tiktok', 'nextdoor', 'bbb', 'angi', 'houzz' ], 'from_text' => '' ],
			'btype'    => [ 'label' => __( 'Business type', 'launch-builder' ), 'keys' => [ 'local_business_type' ], 'from' => [ 'business_type' ], 'from_text' => '' ],
			'address'  => [ 'label' => __( 'Address', 'launch-builder' ), 'keys' => [ 'local_address' ], 'from' => [ 'street', 'locality', 'region', 'postal_code', 'country' ], 'from_text' => '' ],
			'hours'    => [ 'label' => __( 'Opening hours', 'launch-builder' ), 'keys' => [ 'opening_hours' ], 'from' => [ 'hours_spec', 'is_24_7' ], 'from_text' => '' ],
			'price'    => [ 'label' => __( 'Price range', 'launch-builder' ), 'keys' => [ 'price_range' ], 'from' => [ 'price_range' ], 'from_text' => '' ],
			'geo'      => [ 'label' => __( 'Map coordinates', 'launch-builder' ), 'keys' => [ 'geo' ], 'from' => [ 'lat', 'lng' ], 'from_text' => '' ],
			'contact'  => [ 'label' => __( 'Contact page', 'launch-builder' ), 'keys' => [ 'local_seo_contact_page' ], 'from' => [ 'quote_url' ], 'from_text' => '' ],
		];
	}

	/** Which Rank Math settings each Business Info field feeds: field key => list of setting names. */
	public static function business_feeds(): array {
		$out = [];
		foreach ( self::business_map() as $m ) {
			foreach ( $m['from'] as $k ) {
				$out[ $k ][] = (string) $m['label'];
			}
		}
		return $out;
	}

	/** What Rank Math cannot take from Business Info (or Launch Builder does not send), in plain words. */
	public static function business_cannot(): array {
		return [
			__( 'Holiday hours: Rank Math only has opening hours for a normal week.', 'launch-builder' ),
			__( 'A second office address: Rank Math\'s free version has one location (Rank Math Pro adds more).', 'launch-builder' ),
			__( 'Star ratings and review counts: Launch Builder never adds them to your business schema, because search engines want ratings that visitors can see on the page.', 'launch-builder' ),
			__( 'Areas served: Rank Math has no setting for it, so Launch Builder adds it to your schema itself.', 'launch-builder' ),
			__( 'License numbers and years in business: Rank Math has no matching setting. Its Additional Info list (legal name, founding date, tax ID, number of employees) stays yours too, because Business Info has no field that means the same.', 'launch-builder' ),
			__( 'Your X (Twitter) username for share cards: the profile link goes in as a profile, but the username box stays yours.', 'launch-builder' ),
			__( 'The Google Maps key, the About page, the website name and Rank Math\'s own display options.', 'launch-builder' ),
		];
	}

	/** Rank Math's business type key for Business Info's value ('Plumber'), or '' when Rank Math has no such type. */
	public static function rm_business_type( string $t ): string {
		return self::business_type( $t );
	}

	/** Rank Math's business types as key => label (empty without Rank Math). */
	public static function rm_business_types(): array {
		if ( ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'choices_business_types' ) ) {
			return [];
		}
		return array_map( 'strval', (array) \RankMath\Helper::choices_business_types() );
	}

	/** A short human text for a Rank Math setting value (the preview table). */
	private static function business_show( string $key, $v ): string {
		if ( null === $v || '' === $v || [] === $v ) {
			return '';
		}
		switch ( $key ) {
			case 'local_address':
				return is_array( $v ) ? implode( ', ', array_filter( array_map( 'strval', array_values( $v ) ), 'strlen' ) ) : (string) $v;
			case 'opening_hours':
				return self::show_hours( $v );
			case 'phone_numbers':
				$out = [];
				foreach ( (array) $v as $r ) {
					$r = (array) $r;
					$n = (string) ( $r['number'] ?? '' );
					if ( '' !== $n ) {
						$out[] = $n . ( ! empty( $r['type'] ) ? ' (' . $r['type'] . ')' : '' );
					}
				}
				return implode( ', ', $out );
			case 'social_additional_profiles':
				return implode( ', ', array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', (string) $v ) ) ) );
			case 'knowledgegraph_logo':
				return wp_basename( (string) $v );
			case 'knowledgegraph_logo_id':
				return '';
			case 'local_seo_contact_page':
				$title = (int) $v ? get_the_title( (int) $v ) : '';
				return '' !== $title ? $title . ' (#' . (int) $v . ')' : (string) $v;
		}
		return is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v );
	}

	/** Opening hours rows as text: "Mon–Fri 08:00-18:00; Sat 09:00-14:00". */
	private static function show_hours( $rows ): string {
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$order = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
		$by = [];
		foreach ( $rows as $r ) {
			$r = (array) $r;
			$t = (string) ( $r['time'] ?? '' );
			$i = array_search( (string) ( $r['day'] ?? '' ), $order, true );
			if ( '' !== $t && false !== $i ) {
				$by[ $t ][] = (int) $i;
			}
		}
		$parts = [];
		foreach ( $by as $t => $days ) {
			sort( $days );
			$runs = [];
			foreach ( $days as $i ) {
				$last = count( $runs ) - 1;
				if ( $last >= 0 && end( $runs[ $last ] ) === $i - 1 ) {
					$runs[ $last ][] = $i;
				} else {
					$runs[] = [ $i ];
				}
			}
			$labels = [];
			foreach ( $runs as $run ) {
				$a = substr( $order[ $run[0] ], 0, 3 );
				$b = substr( $order[ end( $run ) ], 0, 3 );
				$labels[] = count( $run ) >= 3 ? $a . '–' . $b : implode( ', ', array_map( static function ( $i ) use ( $order ) { return substr( $order[ $i ], 0, 3 ); }, $run ) );
			}
			$parts[] = implode( ', ', $labels ) . ' ' . $t;
		}
		return implode( '; ', $parts );
	}

	/**
	 * Every Business Info setting in Rank Math with what it holds now, what Business Info would put there and what would
	 * happen: one row per business_map() entry.
	 *   status  match (same already) | fill (empty or Rank Math's own starting value) | update (Launch Builder's earlier
	 *           value, Business Info changed since) | yours (set in Rank Math, kept unless forced) | empty (Business Info
	 *           has nothing for it yet)
	 *   action  write | keep | none
	 *
	 * @param string[] $force 'titles.key' or 'all' (the same list sync-seo takes)
	 */
	public static function business_rows( array $force = [] ): array {
		$raw = self::raw_option( self::RM_OPTIONS['titles'] );
		$want = self::rm_desired( 'business' )['titles'];
		$fields = Company::fields();
		$res = Company::resolve();
		$rank = [ 'empty' => 0, 'match' => 1, 'fill' => 2, 'update' => 3, 'yours' => 4 ];
		$rows = [];
		foreach ( self::business_map() as $id => $m ) {
			$status = 'empty';
			$writes = [];
			$keeps = [];
			foreach ( $m['keys'] as $key ) {
				if ( ! array_key_exists( $key, $want ) ) {
					continue;
				}
				$st = self::key_state( 'titles', $key, $raw, $want[ $key ] );
				$coarse = in_array( $st, [ 'edited', 'own' ], true ) ? 'yours' : $st;
				if ( $rank[ $coarse ] > $rank[ $status ] ) {
					$status = $coarse;
				}
				if ( 'fill' === $st || 'update' === $st || ( 'yours' === $coarse && ( in_array( 'all', $force, true ) || in_array( 'titles.' . $key, $force, true ) ) ) ) {
					$writes[] = $key;
				} elseif ( 'yours' === $coarse ) {
					$keeps[] = $key;
				}
			}
			$from = [];
			foreach ( $m['from'] as $k ) {
				if ( isset( $res[ $k ], $fields[ $k ] ) && 'default' !== $res[ $k ]['from'] && '' !== trim( (string) $res[ $k ]['value'] ) ) {
					$from[] = [ 'key' => $k, 'label' => (string) $fields[ $k ][0] ];
				}
			}
			$main = $m['keys'][0];
			$rows[] = [
				'id'        => (string) $id,
				'label'     => (string) $m['label'],
				'keys'      => $m['keys'],
				'from'      => $from,
				'from_text' => (string) $m['from_text'],
				'status'    => $status,
				'action'    => $writes ? 'write' : ( $keeps ? 'keep' : 'none' ),
				'writes'    => $writes,
				'keeps'     => $keeps,
				'current'   => self::business_show( $main, $raw[ $main ] ?? null ),
				'wanted'    => array_key_exists( $main, $want ) ? self::business_show( $main, $want[ $main ] ) : '',
				'by'        => 'yours' === $status ? ( self::edited_since_write( $m['keys'] ) ? 'edited' : 'own' ) : '',
			];
		}
		return $rows;
	}

	/** Did Launch Builder write one of these keys before (so a different value now is a change somebody made in Rank Math)? */
	private static function edited_since_write( array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( null !== Ledger::get( self::ledger_key( 'titles', $key ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Expand the force list the screen and tools send: row ids and 'titles.key' names become 'titles.key', or 'all'. */
	private static function business_force( array $force ): array {
		$map = self::business_map();
		$out = [];
		foreach ( $force as $f ) {
			$f = (string) $f;
			if ( 'all' === $f ) {
				return [ 'all' ];
			}
			if ( isset( $map[ $f ] ) ) {
				foreach ( $map[ $f ]['keys'] as $k ) {
					$out[] = 'titles.' . $k;
				}
			} elseif ( 0 === strpos( $f, 'titles.' ) && in_array( substr( $f, 7 ), self::BUSINESS_KEYS, true ) ) {
				$out[] = $f;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Preview or write the Business Info settings in Rank Math (the Business Info screen's Rank Math card, fill-rank-math).
	 * A dry run (the default) writes nothing. A real run takes a snapshot, writes only what Launch Builder may (see
	 * key_state), records what it wrote and what it replaced so undo_business_fill() can put it back.
	 *
	 * @param array $args { dry_run: bool (default true), force: string[] (row ids, 'titles.key' or 'all'), local_seo: ?bool }
	 */
	public static function business_sync( array $args = [] ): array {
		$dry = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		$force = self::business_force( array_map( 'strval', (array) ( $args['force'] ?? [] ) ) );
		$local = array_key_exists( 'local_seo', $args ) && null !== $args['local_seo'] ? (bool) $args['local_seo'] : null;
		$out = [ 'active' => self::rm_active(), 'ready' => self::rm_ready(), 'version' => self::rm_version(), 'dry_run' => $dry, 'local_seo' => [ 'on' => self::rm_module( 'local-seo' ), 'change' => null ], 'rows' => [], 'counts' => [], 'changes' => [], 'kept' => [], 'warnings' => [], 'cannot' => self::business_cannot() ];
		if ( ! $out['active'] ) {
			$out['warnings'][] = __( 'Rank Math is not active on this site, so there is nothing to fill. When it is turned on, Business Info fills it automatically.', 'launch-builder' );
			return $out;
		}
		$before = [ 'raw' => self::raw_option( self::RM_OPTIONS['titles'] ), 'owned' => (array) get_option( self::OPT_OWNED, [] ), 'module' => self::rm_module( 'local-seo' ), 'ledger' => [] ];
		foreach ( self::BUSINESS_KEYS as $k ) {
			$before['ledger'][ self::ledger_key( 'titles', $k ) ] = Ledger::get( self::ledger_key( 'titles', $k ) );
		}
		$r = self::rank_math_settings( [ 'scope' => 'business', 'dry_run' => $dry, 'force' => $force, 'local_seo' => $local, 'label' => 'fill-rank-math' ] );
		$out['changes'] = $r['changes'];
		$out['kept'] = $r['kept'];
		$out['warnings'] = $r['warnings'];
		$out['via'] = $r['via'];
		if ( ! empty( $r['snapshot'] ) ) {
			$out['snapshot'] = $r['snapshot'];
		}
		foreach ( (array) $r['modules'] as $m ) {
			if ( 'local-seo' === $m['module'] ) {
				$out['local_seo']['change'] = $m['new'] ? 'on' : 'off';
			}
		}
		if ( ! $dry && ( $r['changes'] || $r['modules'] ) && ! empty( $r['snapshot'] ) ) {
			$keys = [];
			$was = [];
			foreach ( $r['changes'] as $c ) {
				$k = substr( (string) $c['key'], 7 ); // After "titles.".
				$keys[] = $k;
				$was[ $k ] = array_key_exists( $k, $before['raw'] ) ? [ 'v' => $before['raw'][ $k ] ] : null;
			}
			update_option( self::OPT_FILL, [
				'time'     => time(),
				'user'     => get_current_user_id(),
				'snapshot' => (string) $r['snapshot'],
				'keys'     => $keys,
				'before'   => $was,
				'ledger'   => array_intersect_key( $before['ledger'], array_flip( array_map( static function ( $k ) { return self::ledger_key( 'titles', $k ); }, $keys ) ) ),
				'owned'    => $before['owned'],
				'module'   => $out['local_seo']['change'] ? [ 'local-seo' => $before['module'] ] : [],
			], false );
		}
		$out['rows'] = self::business_rows( $force );
		foreach ( [ 'match', 'fill', 'update', 'yours', 'empty' ] as $st ) {
			$out['counts'][ $st ] = count( array_filter( $out['rows'], static function ( $row ) use ( $st ) { return $st === $row['status']; } ) );
		}
		$out['last_fill'] = self::last_fill();
		return $out;
	}

	/** The last fill that can still be undone: { time, user, snapshot, keys } or null. */
	public static function last_fill(): ?array {
		$rec = get_option( self::OPT_FILL, null );
		if ( ! is_array( $rec ) || empty( $rec['keys'] ) ) {
			return null;
		}
		return [ 'time' => (int) ( $rec['time'] ?? 0 ), 'user' => (int) ( $rec['user'] ?? 0 ), 'snapshot' => (string) ( $rec['snapshot'] ?? '' ), 'keys' => array_values( (array) $rec['keys'] ) ];
	}

	/**
	 * Put back what the last fill replaced. A setting somebody changed in Rank Math since the fill is left as it is.
	 *
	 * @return array|\WP_Error { reverted: string[], left: string[], local_seo: ?string }
	 */
	public static function undo_business_fill() {
		$rec = get_option( self::OPT_FILL, null );
		if ( ! is_array( $rec ) || empty( $rec['before'] ) || ! is_array( $rec['before'] ) ) {
			return new \WP_Error( 'lb_nothing', __( 'There is no Rank Math fill to undo.', 'launch-builder' ), [ 'status' => 404 ] );
		}
		if ( ! self::rm_active() ) {
			return new \WP_Error( 'lb_rm_off', __( 'Rank Math is not active, so there is nothing to put back.', 'launch-builder' ), [ 'status' => 409 ] );
		}
		$out = [ 'reverted' => [], 'left' => [], 'local_seo' => null ];
		self::$raw = true;
		self::$writing = true;
		try {
			$raw = self::raw_option( self::RM_OPTIONS['titles'] );
			foreach ( $rec['before'] as $key => $b ) {
				$key = (string) $key;
				if ( ! in_array( $key, self::BUSINESS_KEYS, true ) || ! array_key_exists( $key, $raw ) ) {
					continue;
				}
				if ( Ledger::is_edited( self::ledger_key( 'titles', $key ), Ledger::hash_value( $raw[ $key ] ) ) ) {
					$out['left'][] = $key; // Changed in Rank Math after the fill: not ours to undo.
					continue;
				}
				if ( is_array( $b ) && array_key_exists( 'v', $b ) ) {
					$raw[ $key ] = $b['v'];
				} else {
					unset( $raw[ $key ] );
				}
				$out['reverted'][] = $key;
			}
			update_option( self::RM_OPTIONS['titles'], $raw );
			if ( isset( $rec['module']['local-seo'] ) && self::rm_module( 'local-seo' ) !== (bool) $rec['module']['local-seo'] ) {
				self::rm_write( [], [ 'local-seo' => (bool) $rec['module']['local-seo'] ] );
				$out['local_seo'] = $rec['module']['local-seo'] ? 'on' : 'off';
			}
		} finally {
			self::$writing = false;
			self::$raw = false;
		}
		foreach ( (array) ( $rec['ledger'] ?? [] ) as $lk => $row ) {
			if ( ! is_array( $row ) ) {
				Ledger::forget( (string) $lk );
			} else {
				Ledger::record( (string) $lk, (string) $row['type'], $row['id'], (string) $row['hash'], (string) $row['status'] );
			}
		}
		$written = array_values( array_intersect( array_map( 'strval', array_keys( $rec['before'] ) ), $out['reverted'] ) );
		$owned = array_values( array_diff( (array) get_option( self::OPT_OWNED, [] ), $written ) );
		self::set_owned( array_merge( $owned, array_intersect( (array) ( $rec['owned'] ?? [] ), $written ) ) );
		delete_option( self::OPT_FILL );
		self::rm_reset();
		self::flush_identity();
		Cache::purge_later();
		return $out;
	}

	/* ------------------------------------------------------------------ the sync (settings + Kadence + pages) */

	/**
	 * Full SEO sync: Rank Math settings, Kadence local fonts, role templates on pages. Templates passed in are validated,
	 * stored (snapshot) and applied.
	 *
	 * @param array $args { dry_run, templates, local_seo, pages (default true), force[], offset }
	 */
	public static function sync_seo( array $args ): array {
		$dry = ! empty( $args['dry_run'] );
		$report = [ 'dry_run' => $dry, 'warnings' => [] ];
		if ( isset( $args['templates'] ) ) {
			$t = self::store_templates( (array) $args['templates'], $dry );
			if ( is_wp_error( $t ) ) {
				return [ 'error' => $t->get_error_message() ];
			}
			$report['templates'] = $t;
			$report['warnings'] = array_merge( $report['warnings'], $t['warnings'] );
		}
		$force = (array) ( $args['force'] ?? [] );
		$report['rank_math'] = self::rank_math_settings( [ 'dry_run' => $dry, 'force' => array_filter( $force, 'is_string' ), 'local_seo' => $args['local_seo'] ?? null ] );
		$report['warnings'] = array_merge( $report['warnings'], $report['rank_math']['warnings'] );
		if ( ! empty( $report['rank_math']['snapshot'] ) ) {
			$report['snapshot'] = $report['rank_math']['snapshot'];
		}
		$report['yoast'] = self::yoast_settings( $dry );
		$report['kadence'] = self::kadence_settings( $dry, in_array( 'all', $force, true ) || in_array( 'kadence.load_fonts_local', $force, true ) );
		if ( ! array_key_exists( 'pages', $args ) || false !== $args['pages'] ) {
			$report['pages'] = self::apply_role_templates( [ 'dry_run' => $dry, 'force' => $force, 'offset' => (int) ( $args['offset'] ?? 0 ) ] );
		}
		if ( ! $dry ) {
			Cache::purge_later();
		}
		return $report;
	}

	/** Validate and (unless dry) store role templates. Fields cleaned with clean_seo(); '' removes a field. */
	private static function store_templates( array $in, bool $dry ) {
		$cur = self::templates();
		$new = $cur;
		$warnings = [];
		foreach ( $in as $role => $fields ) {
			$role = (string) $role;
			if ( ! isset( Roles::ROLES[ $role ] ) ) {
				/* translators: 1: role, 2: list of roles */
				return new \WP_Error( 'lb_role', sprintf( __( 'Unknown role "%1$s". Roles: %2$s.', 'launch-builder' ), $role, implode( ', ', array_keys( Roles::ROLES ) ) ) );
			}
			foreach ( (array) $fields as $field => $value ) {
				if ( ! in_array( $field, [ 'title', 'description' ], true ) ) {
					continue;
				}
				$value = self::clean_seo( $value );
				if ( strlen( $value ) > 320 ) {
					/* translators: 1: role, 2: field */
					return new \WP_Error( 'lb_template', sprintf( __( 'The %1$s %2$s template is too long (320 characters at most).', 'launch-builder' ), $role, $field ) );
				}
				if ( '' === $value ) {
					unset( $new[ $role ][ $field ] );
					continue;
				}
				$new[ $role ][ $field ] = $value;
				$unknown = self::unknown_vars( $value );
				if ( $unknown ) {
					/* translators: 1: role, 2: field, 3: variables */
					$warnings[] = sprintf( __( '%1$s %2$s: nothing replaces %3$s.', 'launch-builder' ), $role, $field, '%' . implode( '%, %', $unknown ) . '%' );
				}
				if ( preg_match( '/\s[|\-–—•·]\s/u', $value ) ) {
					/* translators: 1: role, 2: field */
					$warnings[] = sprintf( __( '%1$s %2$s uses a literal separator: use %%sep%% so it follows the SEO plugin setting.', 'launch-builder' ), $role, $field );
				}
				if ( false !== strpos( $value, '{{' ) ) {
					/* translators: 1: role, 2: field */
					$warnings[] = sprintf( __( '%1$s %2$s contains a {{token}}: SEO templates use %%variables%% (see get-playbook seo).', 'launch-builder' ), $role, $field );
				}
			}
			if ( isset( $new[ $role ] ) && ! $new[ $role ] ) {
				unset( $new[ $role ] );
			}
		}
		ksort( $new );
		if ( ! $dry && $new !== $cur ) {
			Abilities::snapshot( 'sync-seo templates', [], [ self::OPT_TEMPLATES ] );
			update_option( self::OPT_TEMPLATES, $new, false );
		}
		return [ 'stored' => $new, 'changed' => $new !== $cur, 'warnings' => $warnings ];
	}

	/** Yoast: service/area templates in wpseo_titles (translated to %%x%%), Kadence elements out of Yoast's sitemap by filter. */
	private static function yoast_settings( bool $dry ): array {
		if ( ! self::yoast_active() ) {
			return [ 'active' => false ];
		}
		$opt = get_option( 'wpseo_titles', [] );
		$opt = is_array( $opt ) ? $opt : [];
		$changes = [];
		foreach ( [ 'service' => Entries::SERVICE, 'area' => Entries::AREA ] as $role => $pt ) {
			foreach ( [ 'title' => 'title-' . $pt, 'description' => 'metadesc-' . $pt ] as $field => $key ) {
				$want = self::to_yoast( self::clean_seo( (string) ( self::templates()[ $role ][ $field ] ?? '' ) ) ); // Stored templates only (Yoast parity is unverified).
				if ( '' !== $want && ( $opt[ $key ] ?? null ) !== $want && ! Ledger::is_edited( 'yoast:titles:' . $key, Ledger::hash_value( $opt[ $key ] ?? null ) ) ) {
					$changes[ $key ] = [ 'old' => $opt[ $key ] ?? null, 'new' => $want ];
					$opt[ $key ] = $want;
				}
			}
		}
		if ( ! $dry && $changes ) {
			Abilities::snapshot( 'sync-seo yoast', [], [ 'wpseo_titles' ] );
			update_option( 'wpseo_titles', $opt );
			foreach ( array_keys( $changes ) as $key ) {
				Ledger::record( 'yoast:titles:' . $key, 'option', 'wpseo_titles', Ledger::hash_value( $opt[ $key ] ), 'set' );
			}
		}
		return [ 'active' => true, 'changes' => $changes, 'note' => 'Yoast parity is partial (meta, templates, variables, sitemap exclusions); schema and breadcrumbs follow Rank Math only.' ];
	}

	/** Kadence: fonts are Kadence's to load locally (Perfmatters Local Fonts stays off). Microdata is filtered at runtime. */
	private static function kadence_settings( bool $dry, bool $force ): array {
		if ( ! Plugin::kadence_theme_active() ) {
			return [ 'active' => false ];
		}
		$out = [ 'active' => true, 'microdata' => (bool) apply_filters( 'kadence_microdata', true, 'launch-builder-report' ) ? 'on' : 'off (Rank Math outputs the schema)' ];
		$cur = get_theme_mod( 'load_fonts_local', null );
		$key = 'kadence:load_fonts_local';
		if ( true === (bool) $cur ) {
			$out['load_fonts_local'] = 'ok';
			if ( ! $dry && ! Ledger::get( $key ) ) {
				Ledger::record( $key, 'theme_mod', 'load_fonts_local', Ledger::hash_value( $cur ), 'set' );
			}
		} elseif ( Ledger::is_edited( $key, Ledger::hash_value( $cur ) ) && ! $force ) {
			$out['load_fonts_local'] = 'kept_edit';
		} else {
			$out['load_fonts_local'] = $dry ? 'would turn on' : 'turned on';
			if ( ! $dry ) {
				$out['snapshot'] = Abilities::snapshot( 'sync-seo kadence', [ 'load_fonts_local' ] );
				set_theme_mod( 'load_fonts_local', true );
				Ledger::record( $key, 'theme_mod', 'load_fonts_local', Ledger::hash_value( get_theme_mod( 'load_fonts_local', null ) ), 'set' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ role templates on pages */

	/**
	 * Write each page's SEO title/description from its role's template (Rank Math meta, Yoast copy translated), and the
	 * role robots default (thanks: noindex,follow). _lb_seo_tpl records md5 of what was written per field; a later run
	 * updates a field only while it still equals that (or is empty and never written), removes it when the role has no
	 * template any more, and reports the rest as kept edits. Variables stay unresolved in the meta (Rank Math resolves
	 * them per request).
	 *
	 * @param array $args { dry_run, force: (int|string)[] page ids or 'all', offset }
	 */
	public static function apply_role_templates( array $args = [] ): array {
		$dry = ! empty( $args['dry_run'] );
		$force = (array) ( $args['force'] ?? [] );
		$all = in_array( 'all', $force, true );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$limit = 300;
		$targets = self::meta_targets();
		$rm_reads = in_array( 'rankmath', $targets, true );
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ], 'numberposts' => $limit + 1, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] );
		$next = count( $ids ) > $limit ? $offset + $limit : null;
		$ids = array_slice( $ids, 0, $limit );
		$rows = [];
		$totals = [ 'pages' => count( $ids ), 'set' => 0, 'kept' => 0, 'removed' => 0 ];
		$h = static function ( $v ): string { return md5( (string) wp_json_encode( $v ) ); };
		foreach ( $ids as $id ) {
			$role = Roles::of( $id );
			$tpl = json_decode( (string) get_post_meta( $id, self::META_TPL, true ), true );
			$tpl = is_array( $tpl ) ? $tpl : [];
			$forced = $all || in_array( (int) $id, array_map( 'intval', array_filter( $force, 'is_numeric' ) ), true );
			$want = [
				'title'       => self::clean_seo( self::role_template( $role, 'title' ) ),
				'description' => self::clean_seo( self::role_template( $role, 'description' ) ),
				'robots'      => 'thanks' === $role ? self::ROLE_ROBOTS['thanks'] : [],
			];
			if ( isset( self::OPTION_ROLES[ $role ] ) ) { // Roles whose templates live in options (never on a page).
				$want['title'] = '';
				$want['description'] = '';
			}
			$row = [ 'id' => (int) $id, 'role' => $role, 'set' => [], 'kept' => [], 'removed' => [] ];
			$keys = [ 'title' => [ 'rank_math_title', '_yoast_wpseo_title' ], 'description' => [ 'rank_math_description', '_yoast_wpseo_metadesc' ], 'robots' => [ 'rank_math_robots', '_yoast_wpseo_meta-robots-noindex' ] ];
			foreach ( $want as $field => $value ) {
				// The stored value is read from Rank Math's key, or (Yoast only) from Yoast's, back in Rank Math syntax.
				if ( $rm_reads ) {
					$cur = get_post_meta( $id, $keys[ $field ][0], true );
					$cur = 'robots' === $field ? ( is_array( $cur ) ? array_values( $cur ) : [] ) : (string) $cur;
				} elseif ( 'robots' === $field ) {
					$cur = '1' === (string) get_post_meta( $id, $keys[ $field ][1], true ) ? self::ROLE_ROBOTS['thanks'] : [];
				} else {
					$cur = (string) preg_replace( '/%%([A-Za-z][A-Za-z0-9_-]*)%%/', '%$1%', (string) get_post_meta( $id, $keys[ $field ][1], true ) );
				}
				$empty = ( [] === $cur || '' === $cur );
				$lb_wrote = isset( $tpl[ $field ] ) && $tpl[ $field ] === $h( $cur );
				$value_empty = ( [] === $value || '' === $value );
				if ( $value_empty ) {
					if ( $lb_wrote && ! $empty ) {
						$row['removed'][] = $field;
						if ( ! $dry ) {
							delete_post_meta( $id, $keys[ $field ][0] );
							if ( in_array( 'yoast', $targets, true ) ) {
								delete_post_meta( $id, $keys[ $field ][1] );
							}
						}
					}
					unset( $tpl[ $field ] );
					continue;
				}
				if ( $h( $cur ) === $h( $value ) ) {
					$tpl[ $field ] = $h( $value );
					continue;
				}
				if ( ! ( ( $empty && ! isset( $tpl[ $field ] ) ) || $lb_wrote || $forced ) ) {
					$row['kept'][] = $field;
					continue;
				}
				$row['set'][] = $field;
				$tpl[ $field ] = $h( $value );
				if ( $dry ) {
					continue;
				}
				if ( in_array( 'rankmath', $targets, true ) ) {
					update_post_meta( $id, $keys[ $field ][0], 'robots' === $field ? $value : wp_slash( $value ) );
				}
				if ( in_array( 'yoast', $targets, true ) ) {
					update_post_meta( $id, $keys[ $field ][1], 'robots' === $field ? ( in_array( 'noindex', $value, true ) ? '1' : '2' ) : wp_slash( self::to_yoast( $value ) ) );
				}
			}
			if ( ! $dry ) {
				$tpl ? update_post_meta( $id, self::META_TPL, wp_slash( (string) wp_json_encode( $tpl ) ) ) : delete_post_meta( $id, self::META_TPL );
			}
			$totals['set'] += $row['set'] ? 1 : 0;
			$totals['kept'] += $row['kept'] ? 1 : 0;
			$totals['removed'] += $row['removed'] ? 1 : 0;
			if ( $row['set'] || $row['kept'] || $row['removed'] ) {
				$row['title'] = get_the_title( $id );
				$rows[] = $row;
			}
		}
		return [ 'totals' => $totals, 'pages' => array_slice( $rows, 0, 100 ), 'next_offset' => $next ];
	}

	/* ------------------------------------------------------------------ front-end title / description / robots */

	/** The singular post being viewed (front end). */
	private static function viewed_post(): ?\WP_Post {
		if ( ! did_action( 'wp' ) || ! is_singular() ) {
			return null;
		}
		$p = get_queried_object();
		return $p instanceof \WP_Post ? $p : null;
	}

	/** Pages without their own SEO title get their role's template (pages created after the last sync). */
	public static function rm_title( $title ) {
		$p = self::viewed_post();
		if ( ! $p || 'page' !== $p->post_type || '' !== (string) get_post_meta( $p->ID, 'rank_math_title', true ) ) {
			return $title;
		}
		$role = Roles::current();
		$tpl = isset( self::OPTION_ROLES[ $role ] ) ? '' : self::role_template( $role, 'title' );
		$out = '' !== $tpl ? self::rm_replace( $tpl, $p ) : '';
		return '' !== $out ? $out : $title;
	}

	/**
	 * Description precedence. Services and areas: their own rank_math_description, else the post type template (role
	 * template) even when an excerpt exists (Rank Math would take the excerpt first); pages: their own, else the role
	 * template, else Rank Math's value.
	 */
	public static function rm_description( $desc ) {
		$p = self::viewed_post();
		if ( ! $p || '' !== (string) get_post_meta( $p->ID, 'rank_math_description', true ) ) {
			return $desc;
		}
		$tpl = '';
		if ( in_array( $p->post_type, [ Entries::SERVICE, Entries::AREA ], true ) ) {
			$tpl = class_exists( '\RankMath\Helper' ) ? (string) \RankMath\Helper::get_settings( 'titles.pt_' . $p->post_type . '_description' ) : '';
			if ( '' === $tpl ) {
				$tpl = self::role_template( Entries::SERVICE === $p->post_type ? 'service' : 'area', 'description' );
			}
		} elseif ( 'page' === $p->post_type ) {
			$role = Roles::current();
			$tpl = isset( self::OPTION_ROLES[ $role ] ) ? '' : self::role_template( $role, 'description' );
		}
		$out = '' !== $tpl ? self::rm_replace( $tpl, $p ) : '';
		return '' !== $out ? $out : $desc;
	}

	/** The robots default for the current role when the object has none of its own. */
	private static function role_robots(): array {
		$role = Roles::current();
		if ( ! isset( self::ROLE_ROBOTS[ $role ] ) ) {
			return [];
		}
		$p = self::viewed_post();
		if ( $p ) {
			$own = get_post_meta( $p->ID, 'rank_math_robots', true );
			if ( is_array( $own ) && $own ) {
				return [];
			}
		}
		return self::ROLE_ROBOTS[ $role ];
	}

	/** Rank Math robots: thanks noindex,follow; search and 404 noindex (unless the page sets its own). */
	public static function rm_robots( $robots ) {
		$want = self::role_robots();
		if ( ! $want || ! is_array( $robots ) ) {
			return $robots;
		}
		$robots['index'] = 'noindex';
		$robots['follow'] = in_array( 'nofollow', $want, true ) ? 'nofollow' : 'follow';
		return $robots;
	}

	/** Yoast robots (array form, Yoast 14+). */
	public static function yoast_robots( $robots ) {
		$want = self::role_robots();
		if ( ! $want || ! is_array( $robots ) ) {
			return $robots;
		}
		$robots['index'] = 'noindex';
		return $robots;
	}

	/* ------------------------------------------------------------------ schema in Rank Math's graph */

	/** WebSite/WebPage/publisher entities on services, areas and pages (pages are not Articles any more). */
	public static function rm_global_entities( $can, $jsonld = null ) {
		unset( $jsonld );
		if ( is_singular( [ Entries::SERVICE, Entries::AREA, 'page' ] ) ) {
			return true;
		}
		return $can;
	}

	/** The publisher @id Rank Math uses. */
	private static function publisher_id( array $data = [] ): string {
		if ( ! empty( $data['publisher']['@id'] ) ) {
			return (string) $data['publisher']['@id'];
		}
		$type = class_exists( '\RankMath\Helper' ) ? \RankMath\Helper::get_settings( 'titles.knowledgegraph_type' ) : 'company';
		return home_url( 'company' === $type ? '/#organization' : '/#person' );
	}

	/**
	 * rank_math/json_ld (priority 20, before Rank Math connects entities at 99): the default Service entity for services
	 * (Rank Math only builds Article/Product defaults; editor-saved Service schemas are left alone), areaServed on the
	 * publisher, and the per-entry FAQPage. Never AggregateRating, never a second LocalBusiness.
	 */
	public static function rm_json_ld( $data, $jsonld = null ) {
		unset( $jsonld );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$p = self::viewed_post();
		if ( $p && Entries::SERVICE === $p->post_type && ! post_password_required( $p ) && ! isset( $data['richSnippet'] ) && self::uses_default_service( $p ) ) {
			$name = (string) \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_snippet_name', '%seo_title%' );
			$desc = (string) \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_snippet_desc', '%seo_description%' );
			$entity = array_filter( [
				'@type'       => 'Service',
				'name'        => self::rm_replace( '' !== $name ? $name : '%seo_title%', $p ),
				'description' => self::rm_replace( '' !== $desc ? $desc : '%seo_description%', $p ),
				'isPrimary'   => true,
			] );
			$data['richSnippet'] = apply_filters( 'rank_math/snippet/rich_snippet_service_entity', $entity );
		}
		if ( isset( $data['publisher'] ) && is_array( $data['publisher'] ) && empty( $data['publisher']['areaServed'] ) ) {
			$areas = self::areas_served( 0 );
			if ( $areas ) {
				$data['publisher']['areaServed'] = $areas;
			}
		}
		if ( $p && ! post_password_required( $p ) ) {
			$faq = self::faq_items( $p->ID );
			if ( $faq && ! self::has_faq_markup( $p ) ) {
				$data['lb-faq'] = [
					'@type'      => 'FAQPage',
					'mainEntity' => array_map( static function ( $qa ) { return [ '@type' => 'Question', 'name' => $qa['q'], 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $qa['a'] ] ]; }, $faq ),
				];
			}
		}
		return $data;
	}

	/** Does this service get Rank Math's default schema (no stored schema, no per-post type, default type service)? */
	private static function uses_default_service( \WP_Post $p ): bool {
		if ( 'service' !== \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_rich_snippet' ) || metadata_exists( 'post', $p->ID, 'rank_math_rich_snippet' ) ) {
			return false;
		}
		if ( class_exists( '\RankMath\Schema\DB' ) && \RankMath\Schema\DB::get_schemas( $p->ID ) ) {
			return false;
		}
		return true;
	}

	/** Enrich every Service entity on a service page: serviceType, category, provider (the publisher), areaServed. */
	public static function rm_service_entity( $entity ) {
		$p = self::viewed_post();
		if ( ! is_array( $entity ) || ! $p || Entries::SERVICE !== $p->post_type ) {
			return $entity;
		}
		if ( empty( $entity['serviceType'] ) ) {
			$entity['serviceType'] = self::plain_title( $p );
		}
		$cat = self::primary_category( $p->ID );
		if ( $cat && empty( $entity['category'] ) ) {
			$entity['category'] = $cat->name;
		}
		if ( empty( $entity['provider'] ) ) {
			$entity['provider'] = [ '@id' => self::publisher_id() ];
		}
		if ( empty( $entity['areaServed'] ) ) {
			$areas = self::areas_served( $p->ID );
			if ( $areas ) {
				$entity['areaServed'] = $areas;
			}
		}
		unset( $entity['aggregateRating'], $entity['review'] );
		return $entity;
	}

	/** A post title as plain text (texturized entities decoded) for JSON-LD. */
	private static function plain_title( $post ): string {
		return html_entity_decode( self::clean_seo( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Primary service category: Rank Math's / Yoast's primary term, else the first by name. */
	private static function primary_category( int $id ): ?\WP_Term {
		foreach ( [ 'rank_math_primary_' . Entries::CAT, '_yoast_wpseo_primary_' . Entries::CAT ] as $k ) {
			$t = (int) get_post_meta( $id, $k, true );
			if ( $t ) {
				$term = get_term( $t, Entries::CAT );
				if ( $term instanceof \WP_Term ) {
					return $term;
				}
			}
		}
		$terms = wp_get_object_terms( $id, Entries::CAT, [ 'orderby' => 'name' ] );
		return $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
	}

	/**
	 * areaServed as Place nodes: the published areas offering a service (an area without _lb_services offers all), for
	 * the publisher (0) every published area; else Business Info areas_served; else the Business Info city.
	 */
	public static function areas_served( int $service_id ): array {
		if ( isset( self::$areas[ $service_id ] ) ) {
			return self::$areas[ $service_id ];
		}
		$out = [];
		$areas = get_posts( [ 'post_type' => Entries::AREA, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'suppress_filters' => false ] );
		foreach ( $areas as $a ) {
			if ( $service_id ) {
				$ids = [];
				foreach ( (array) get_post_meta( $a->ID, '_lb_services', false ) as $row ) {
					foreach ( is_array( $row ) ? $row : explode( ',', (string) $row ) as $x ) {
						$ids[] = (int) $x;
					}
				}
				$ids = array_filter( $ids );
				if ( $ids && ! in_array( $service_id, $ids, true ) ) {
					continue;
				}
			}
			$out[] = [ '@type' => 'Place', 'name' => self::plain_title( $a ), 'url' => get_permalink( $a ) ];
		}
		if ( ! $out ) {
			foreach ( self::biz_list( 'areas_served' ) as $name ) {
				$name = self::clean_seo( is_array( $name ) ? ( $name['name'] ?? '' ) : $name );
				if ( '' !== $name ) {
					$out[] = [ '@type' => 'Place', 'name' => $name ];
				}
			}
		}
		if ( ! $out ) {
			$city = self::biz( Company::resolve(), 'city' );
			if ( '' !== $city ) {
				$out[] = [ '@type' => 'Place', 'name' => $city ];
			}
		}
		return self::$areas[ $service_id ] = array_slice( $out, 0, 50 );
	}

	/** Per-entry FAQs from _lb_faq: [{q, a}] (also {question, answer}), tokens resolved as text, 30 at most. */
	public static function faq_items( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_FAQ, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			$row = (array) $row;
			$q = self::faq_text( $row['q'] ?? ( $row['question'] ?? '' ), $post_id );
			$a = self::faq_text( $row['a'] ?? ( $row['answer'] ?? '' ), $post_id );
			if ( '' !== $q && '' !== $a ) {
				$out[] = [ 'q' => $q, 'a' => $a ];
			}
		}
		return array_slice( $out, 0, 30 );
	}

	private static function faq_text( $s, int $post_id ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( false !== strpos( $s, '{{' ) || false !== strpos( $s, '#lb-' ) ) {
			Tokens::push( $post_id );
			$s = Tokens::replace( $s, 'text' );
			Tokens::pop();
		}
		return self::clean_seo( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) );
	}

	/** Is there FAQ markup already on this page (a faqSchema accordion or an SEO plugin FAQ block, in the content or the role template)? */
	public static function has_faq_markup( \WP_Post $p ): bool {
		$sources = [ (string) $p->post_content ];
		$role = Roles::of( $p->ID );
		if ( in_array( $role, [ 'service', 'area' ], true ) ) {
			$sources[] = self::design_markup( Chrome::design( 'template', $role ) );
		}
		foreach ( $sources as $c ) {
			if ( false !== strpos( $c, '"faqSchema":true' ) || false !== strpos( $c, '<!-- wp:rank-math/faq-block' ) || false !== strpos( $c, '<!-- wp:yoast/faq-block' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Rank Math breadcrumb items from a Launch Builder trail: [name, url, hide_in_schema => false], last url = canonical. */
	public static function rank_math_crumbs( array $trail ): array {
		$out = [];
		$last = count( $trail ) - 1;
		foreach ( array_values( $trail ) as $i => $t ) {
			$t = (array) $t;
			$url = (string) ( $t['url'] ?? '' );
			if ( $i === $last && '' === $url ) {
				$url = (string) wp_get_canonical_url();
			}
			$out[] = [ self::clean_seo( $t['title'] ?? '' ), $url, 'hide_in_schema' => false ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ sitemaps */

	/** Kadence/Launch Builder template types never appear in an XML sitemap (Rank Math and Yoast). */
	public static function exclude_post_type( $exclude, $type ) {
		return in_array( (string) $type, self::NON_SEO_TYPES, true ) ? true : $exclude;
	}

	/** Service categories stay out while any category has its own page (the archive 301s to it). */
	public static function exclude_taxonomy( $exclude, $taxonomy ) {
		if ( Entries::CAT === (string) $taxonomy && Roles::catmap() ) {
			return true;
		}
		return $exclude;
	}

	/** Thank-you pages are out of Rank Math's sitemap (unless explicitly set to index). */
	public static function rm_sitemap_entry( $url, $type = '', $object = null ) {
		// Rank Math passes a plain row object from its own query (ID, post_type, ...), not a WP_Post.
		if ( 'post' !== $type || ! is_object( $object ) || empty( $object->ID ) || 'page' !== ( $object->post_type ?? '' ) ) {
			return $url;
		}
		if ( 'thanks' === (string) get_post_meta( $object->ID, Roles::META, true ) ) {
			$own = get_post_meta( $object->ID, 'rank_math_robots', true );
			if ( ! is_array( $own ) || ! in_array( 'index', $own, true ) ) {
				return false;
			}
		}
		return $url;
	}

	/* ------------------------------------------------------------------ IndexNow */

	/** Builders call this to keep IndexNow quiet for the rest of the request. */
	public static function suppress_indexing( bool $on = true ): void {
		self::$suppress = $on;
	}

	/** Is IndexNow suppressed now (build running, site not public, brief.launch.indexing false)? */
	public static function indexing_suppressed(): bool {
		$why = self::$suppress || '0' === (string) get_option( 'blog_public', '1' ) || Lock::held( 'build' );
		if ( ! $why ) {
			$job = get_option( 'launch_builder_build_job', null );
			$why = is_array( $job ) && isset( $job['brief']['launch']['indexing'] ) && false === $job['brief']['launch']['indexing'];
		}
		return (bool) apply_filters( 'launch_builder_suppress_indexing', $why );
	}

	/** rank_math/instant_indexing/publish_url: false while suppressed (counted). */
	public static function indexnow( $url, $post = null ) {
		unset( $post );
		if ( ! $url || ! self::indexing_suppressed() ) {
			return $url;
		}
		if ( 0 === self::$suppressed++ ) {
			add_action( 'shutdown', [ __CLASS__, 'save_indexnow' ] );
		}
		self::$last_url = (string) $url;
		return false;
	}

	/** @var string last suppressed URL. */
	private static $last_url = '';

	public static function save_indexnow(): void {
		$s = get_option( self::OPT_INDEXNOW, [] );
		$s = is_array( $s ) ? $s : [];
		update_option( self::OPT_INDEXNOW, [ 'count' => (int) ( $s['count'] ?? 0 ) + self::$suppressed, 'last_url' => self::$last_url, 'last_time' => time() ], false );
		self::$suppressed = 0;
	}

	/* ------------------------------------------------------------------ Kadence */

	/** No Kadence microdata while Rank Math outputs JSON-LD (two descriptions of one page disagree). */
	public static function kadence_microdata( $on ) {
		return self::rm_active() ? false : $on;
	}

	public static function hero_open(): void {
		self::$in_hero = true;
	}

	public static function hero_close(): void {
		self::$in_hero = false;
	}

	/** The first image printed in the hero (kadence_hero_header) loads eagerly with high fetch priority (LCP). */
	public static function hero_image( $html ) {
		if ( ! self::$in_hero || self::$hero_done || ! is_string( $html ) || false === stripos( $html, '<img' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$t = new \WP_HTML_Tag_Processor( $html );
		if ( ! $t->next_tag( 'img' ) ) {
			return $html;
		}
		self::$hero_done = true;
		if ( 'lazy' === strtolower( (string) $t->get_attribute( 'loading' ) ) ) {
			$t->remove_attribute( 'loading' );
		}
		if ( null === $t->get_attribute( 'fetchpriority' ) ) {
			$t->set_attribute( 'fetchpriority', 'high' );
		}
		return $t->get_updated_html();
	}

	/* ------------------------------------------------------------------ editor: seo-context */

	public static function routes(): void {
		register_rest_route( 'launch-builder/v1', '/seo-context/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'seo_context_rest' ],
			'permission_callback' => static function ( \WP_REST_Request $r ) { return current_user_can( 'edit_post', (int) $r['id'] ); },
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		] );
	}

	public static function seo_context_rest( \WP_REST_Request $r ) {
		$post = get_post( (int) $r['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'lb_not_found', __( 'No such post.', 'launch-builder' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( self::seo_context( $post ) );
	}

	/**
	 * What Rank Math's content analysis should also see for a post: token values (ns.key => text) and the role chrome
	 * that renders around the content (hero, and the entry template for services/areas) with tokens resolved, as HTML.
	 */
	public static function seo_context( \WP_Post $post ): array {
		$tokens = [];
		foreach ( self::seo_vars() as $row ) {
			$v = self::var_value( $row['key'], $post );
			if ( '' !== $v ) {
				$tokens[ $row['key'] ] = $v;
			}
		}
		$role = Roles::of( $post->ID );
		$html = '';
		$slots = in_array( $role, [ 'service', 'area' ], true ) ? [ 'hero', 'template' ] : [ 'hero' ];
		foreach ( $slots as $slot ) {
			$markup = '' !== $role ? self::design_markup( Chrome::design( $slot, $role ) ) : '';
			if ( '' !== $markup ) {
				$html .= self::render_for( $markup, $role, $post ) . "\n";
			}
		}
		$html = trim( wp_kses_post( (string) preg_replace( '#<(script|style|svg)\b[^>]*>.*?</\1>#is', '', $html ) ) );
		return [ 'id' => $post->ID, 'role' => $role, 'tokens' => $tokens, 'chrome_html' => mb_substr( $html, 0, 30000 ) ];
	}

	/** Render block markup as it would appear for $post in $role. */
	private static function render_for( string $markup, string $role, \WP_Post $post ): string {
		$prev = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$simulated = Context::simulate( $role, $post );
		Tokens::push( $post );
		try {
			$html = do_blocks( $markup );
		} finally {
			Tokens::pop();
			if ( $simulated ) {
				Context::clear();
			}
			$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$prev instanceof \WP_Post ? setup_postdata( $prev ) : wp_reset_postdata();
		}
		return (string) $html;
	}

	/** Block markup of a chrome DESIGN: "slug" | {pattern} | {sections} | {synced: key} | {adopt: element id}. */
	public static function design_markup( $d ): string {
		if ( is_string( $d ) ) {
			return ( '' === $d || 'theme' === $d ) ? '' : self::pattern_markup( $d );
		}
		if ( ! is_array( $d ) ) {
			return '';
		}
		if ( isset( $d['pattern'] ) ) {
			return self::pattern_markup( (string) $d['pattern'] );
		}
		if ( isset( $d['sections'] ) ) {
			return implode( "\n", array_map( [ __CLASS__, 'design_markup' ], (array) $d['sections'] ) );
		}
		if ( isset( $d['synced'] ) ) {
			$key = (string) $d['synced'];
			$ids = get_posts( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_lb_key', 'value' => [ $key, 'synced-' . $key ], 'compare' => 'IN' ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			return $ids ? (string) get_post_field( 'post_content', (int) $ids[0] ) : '';
		}
		if ( isset( $d['adopt'] ) ) {
			$p = get_post( (int) $d['adopt'] );
			return $p && 'publish' === $p->post_status ? (string) $p->post_content : '';
		}
		return '';
	}

	private static function pattern_markup( string $slug ): string {
		Plugin::register_patterns();
		$p = \WP_Block_Patterns_Registry::get_instance()->get_registered( 'launch-builder/' . sanitize_title( $slug ) );
		return is_array( $p ) ? (string) ( $p['content'] ?? '' ) : '';
	}

	/** Block editor: the Rank Math analysis filter (only with Rank Math, only for editable posts). */
	public static function editor_assets(): void {
		$post = get_post();
		if ( ! self::rm_active() || ! $post || ! in_array( $post->post_type, [ 'page', 'post', Entries::SERVICE, Entries::AREA ], true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		wp_enqueue_script( 'launch-builder-seo-context', LB_URL . 'assets/js/seo-context.js', [ 'wp-hooks', 'wp-api-fetch' ], LB_VERSION, true );
		wp_localize_script( 'launch-builder-seo-context', 'lbSeoContext', [ 'postId' => (int) $post->ID ] );
	}

	/** Classic editor screens get the same script. */
	public static function classic_assets( $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$post = get_post();
		if ( $post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return; // enqueue_block_editor_assets handles it.
		}
		self::editor_assets();
	}

	/* ------------------------------------------------------------------ Meta Box */

	/** [ kind, sub ] for mb_fields_for(): post type, 'term:{taxonomy}', 'setting:{option}', 'user', or an object. */
	private static function mb_target( $object_type ): array {
		if ( $object_type instanceof \WP_Post ) {
			return [ 'post', $object_type->post_type ];
		}
		if ( $object_type instanceof \WP_Term ) {
			return [ 'term', $object_type->taxonomy ];
		}
		$s = (string) $object_type;
		if ( 'user' === $s ) {
			return [ 'user', 'user' ];
		}
		if ( preg_match( '/^(term|setting):(.+)$/', $s, $m ) ) {
			return [ $m[1], $m[2] ];
		}
		return [ 'post', $s ];
	}

	/**
	 * The Meta Box field allow-list for an object type (what {{meta.id}} may resolve): field id => { id, type, name,
	 * clone, multiple, storage, contexts[], shape }. $object_type: a post type ('lb_service'), 'term:{taxonomy}',
	 * 'setting:{option_name}', 'user', or a WP_Post/WP_Term. Only fields registered with Meta Box for exactly that type;
	 * layout-only fields, passwords and secret-looking ids are left out. Empty without Meta Box or before it registers its
	 * fields (init priority 20).
	 *
	 * contexts: where a value may be printed ('html', 'attr', 'text', 'url'); wysiwyg/oembed/map/osm are html only (pass
	 * through wp_kses_post). shape: scalar | list (cloneable or multiple: join with ', ').
	 */
	public static function mb_fields_for( $object_type ): array {
		$by = self::mb_registry( $object_type );
		if ( null === $by ) {
			return [];
		}
		[ $kind, $sub ] = self::mb_target( $object_type );
		$memo = 'allowed:' . $kind . ':' . $sub; // Every {{meta.*}} token asks, so keep the answer for the request.
		if ( isset( self::$mb[ $memo ] ) ) {
			return self::$mb[ $memo ];
		}
		$out = [];
		foreach ( $by as $id => $f ) {
			$f = (array) $f;
			$type = (string) ( $f['type'] ?? 'text' );
			$id = (string) ( $f['id'] ?? $id );
			if ( '' === $id || in_array( $type, [ 'password', 'heading', 'divider', 'custom_html', 'button', 'tab', 'hidden', 'sidebar', 'background' ], true ) || preg_match( '/(pass|secret|token|salt|_key$|api_?key|private|licen[sc]e_?(key|code)|credential)/i', $id ) ) {
				continue; // A license number is a fact to show; a license key is a secret.
			}
			$list = ! empty( $f['clone'] ) || ! empty( $f['multiple'] ) || in_array( $type, [ 'checkbox_list', 'image_advanced', 'file_advanced', 'image', 'file', 'image_upload', 'file_upload', 'video', 'autocomplete', 'key_value', 'group', 'fieldset_text' ], true );
			if ( in_array( $type, [ 'wysiwyg', 'oembed', 'map', 'osm' ], true ) ) {
				$contexts = [ 'html' ];
			} elseif ( in_array( $type, [ 'url', 'single_image', 'file_input' ], true ) && ! $list ) {
				$contexts = [ 'html', 'attr', 'text', 'url' ];
			} elseif ( in_array( $type, [ 'image_advanced', 'image', 'image_upload', 'file_advanced', 'file', 'file_upload', 'video' ], true ) ) {
				$contexts = [ 'html', 'attr', 'text', 'url' ]; // A picture or file list gives the address of its first file.
			} elseif ( in_array( $type, [ 'group', 'key_value', 'fieldset_text' ], true ) ) {
				$contexts = [];
			} else {
				$contexts = $list ? [ 'html', 'text' ] : [ 'html', 'attr', 'text' ];
			}
			if ( ! $contexts ) {
				continue;
			}
			$out[ $id ] = [
				'id'       => $id,
				'type'     => $type,
				'name'     => wp_strip_all_tags( (string) ( $f['name'] ?? $id ) ),
				'clone'    => ! empty( $f['clone'] ),
				'multiple' => ! empty( $f['multiple'] ),
				'storage'  => isset( $f['storage'] ) && is_object( $f['storage'] ) && false !== stripos( get_class( $f['storage'] ), 'table' ) ? 'custom_table' : 'meta',
				'contexts' => $contexts,
				'shape'    => $list ? 'list' : 'scalar',
			];
		}
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			self::$mb[ $memo ] = $out;
		}
		return $out;
	}

	/**
	 * Does Meta Box (or the launch_builder_field_owned_elsewhere filter) own field $id for this post type? One answer for
	 * the Card details box and for get-compat: this asks Entries::meta_box_owns(), the check the box itself uses. Any
	 * registered field counts, whatever its type.
	 */
	public static function mb_owns_field( string $post_type, string $id ): bool {
		return Entries::meta_box_owns( $id, $post_type );
	}

	/**
	 * Meta Box in one place, for get-compat and the admin Compatibility card: whether it is on, its version, the allowed
	 * token fields per type (id, type) and which Card details fields it owns.
	 *
	 * @return array{active:bool,version:string,fields:array<string,array>,owns_card_fields:array<string,bool>}
	 */
	public static function mb_status(): array {
		$out = [ 'active' => self::mb_active(), 'version' => defined( 'RWMB_VER' ) ? (string) RWMB_VER : '', 'fields' => [], 'owns_card_fields' => [] ];
		if ( ! $out['active'] ) {
			return $out;
		}
		foreach ( [ Entries::SERVICE, Entries::AREA, 'page' ] as $pt ) {
			$out['fields'][ $pt ] = array_values( array_map( static function ( $f ) { return [ 'id' => $f['id'], 'type' => $f['type'], 'shape' => $f['shape'], 'contexts' => $f['contexts'] ]; }, self::mb_fields_for( $pt ) ) );
		}
		$out['owns_card_fields'] = array_filter( [ '_lb_summary' => self::mb_owns_field( Entries::SERVICE, '_lb_summary' ), '_lb_icon' => self::mb_owns_field( Entries::SERVICE, '_lb_icon' ), '_lb_services' => self::mb_owns_field( Entries::AREA, '_lb_services' ) ] );
		return $out;
	}

	/** Raw Meta Box fields for an object type (field id => settings), or null when unavailable yet. */
	private static function mb_registry( $object_type ): ?array {
		if ( ! function_exists( 'rwmb_get_registry' ) || ! did_action( 'init' ) ) {
			return null;
		}
		[ $kind, $sub ] = self::mb_target( $object_type );
		$memo = $kind . ':' . $sub;
		if ( isset( self::$mb[ $memo ] ) ) {
			return self::$mb[ $memo ];
		}
		$reg = rwmb_get_registry( 'field' );
		if ( ! is_object( $reg ) || ! method_exists( $reg, 'get_by_object_type' ) ) {
			return null;
		}
		$by = (array) $reg->get_by_object_type( $kind );
		$fields = isset( $by[ $sub ] ) && is_array( $by[ $sub ] ) ? $by[ $sub ] : [];
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			self::$mb[ $memo ] = $fields; // Meta Box registers on init; memoise only after it.
		}
		return $fields;
	}

	/* ------------------------------------------------------------------ Perfmatters */

	/** What Launch Builder adds to Perfmatters through its filters ([] for a list turns it off). */
	public static function pm_lists(): array {
		$lists = [ 'rucss' => self::PM_RUCSS_SELECTORS, 'delay' => self::PM_DELAY_EXCLUSIONS, 'lazy' => self::PM_LAZY_EXCLUSIONS, 'rest' => [ '/launch-builder/v1' ] ];
		$lists = apply_filters( 'launch_builder_perfmatters_exclusions', $lists );
		return is_array( $lists ) ? $lists : [];
	}

	private static function pm_merge( $list, string $which ): array {
		$add = (array) ( self::pm_lists()[ $which ] ?? [] );
		return array_values( array_unique( array_merge( is_array( $list ) ? $list : [], array_map( 'strval', $add ) ) ) );
	}

	public static function pm_rucss( $selectors ) {
		return self::pm_merge( $selectors, 'rucss' );
	}

	public static function pm_delay( $exclusions ) {
		return self::pm_merge( $exclusions, 'delay' );
	}

	public static function pm_lazy( $attributes ) {
		return self::pm_merge( $attributes, 'lazy' );
	}

	/** Perfmatters "Disable REST API" never blocks Launch Builder's own routes (each checks its own permissions). */
	public static function pm_rest( $exceptions ) {
		return self::pm_merge( $exceptions, 'rest' );
	}

	/**
	 * Read perfmatters_options and report every setting that can break a Launch Builder site, with what Launch Builder
	 * already handles through Perfmatters' filters and what the site owner must change.
	 *
	 * @return array{active:bool,version:string,auto:array,risks:array,settings:array}
	 */
	public static function perfmatters_report(): array {
		$o = get_option( 'perfmatters_options', [] );
		$o = is_array( $o ) ? $o : [];
		$a = (array) ( $o['assets'] ?? [] );
		$l = (array) ( $o['lazyload'] ?? [] );
		$f = (array) ( $o['fonts'] ?? [] );
		$lists = self::pm_lists();
		$on = static function ( $v ): bool { return ! empty( $v ) && '0' !== (string) $v; };
		$lines = static function ( $v ): array { return array_values( array_filter( array_map( 'trim', is_array( $v ) ? $v : preg_split( '/\r?\n/', (string) $v ) ) ) ); };
		$risks = [];
		$risk = static function ( string $code, string $severity, string $setting, string $message, string $fix ) use ( &$risks ) {
			$risks[] = compact( 'code', 'severity', 'setting', 'message', 'fix' );
		};

		if ( $on( $a['remove_unused_css'] ?? '' ) ) {
			$missing = array_values( array_diff( self::PM_RUCSS_SELECTORS, array_merge( (array) ( $lists['rucss'] ?? [] ), $lines( $a['rucss_excluded_selectors'] ?? '' ) ) ) );
			$missing
				? $risk( 'rucss_selectors', 'warning', 'assets.remove_unused_css', 'Remove Unused CSS strips states that Kadence adds with JavaScript (mobile drawer, dropdowns, sticky header, accordion, tabs, sliders, modals, Conversions popups).', 'Add to Excluded Selectors, one per line: ' . implode( ' ', $missing ) )
				: $risk( 'rucss_selectors', 'info', 'assets.remove_unused_css', 'Remove Unused CSS is on; Launch Builder adds the Kadence and Kadence Conversions state selectors through perfmatters_rucss_excluded_selectors.', 'None. After changing styles or chrome, Launch Builder clears the used CSS.' );
		}
		if ( $on( $a['delay_js'] ?? '' ) ) {
			if ( 'all' === ( $a['delay_js_behavior'] ?? '' ) ) {
				$missing = array_values( array_diff( self::PM_DELAY_EXCLUSIONS, array_merge( (array) ( $lists['delay'] ?? [] ), $lines( $a['delay_js_exclusions'] ?? '' ) ) ) );
				$missing
					? $risk( 'delay_js', 'warning', 'assets.delay_js', 'Delay All Scripts holds back the form embed, Kadence menus/sliders/modals and Kadence Conversions until the first interaction.', 'Add to Excluded from Delay: ' . implode( ' ', $missing ) )
					: $risk( 'delay_js', 'info', 'assets.delay_js', 'Delay All Scripts is on; Launch Builder excludes the form embed, Kadence menus/sliders/modals and Kadence Conversions through perfmatters_delay_js_exclusions.', 'None.' );
			} else {
				$bad = array_values( array_filter( $lines( $a['delay_js_inclusions'] ?? [] ), static function ( $s ) { return (bool) preg_match( '/form_embed|leadconnector|msgsndr|kadence|kt-|kb-|splide|conversions/i', $s ); } ) );
				if ( $bad ) {
					$risk( 'delay_js_inclusions', 'warning', 'assets.delay_js_inclusions', 'Delayed Scripts includes scripts that forms, menus or popups need on load: ' . implode( ', ', $bad ) . '.', 'Remove those lines from Delayed Scripts.' );
				}
			}
		}
		if ( $on( $l['lazy_loading_iframes'] ?? '' ) ) {
			$risk( 'lazy_iframes', ( $lists['lazy'] ?? [] ) ? 'info' : 'warning', 'lazyload.lazy_loading_iframes', 'Lazy loading iframes delays the form embed iframe.', ( $lists['lazy'] ?? [] ) ? 'None: Launch Builder excludes leadconnectorhq/msgsndr/form_embed iframes.' : 'Add leadconnectorhq and msgsndr to Exclude from Lazy Loading.' );
		}
		if ( $on( $l['lazy_loading'] ?? '' ) && (int) ( $l['exclude_leading_images'] ?? 0 ) < 2 ) {
			$risk( 'leading_images', 'warning', 'lazyload.exclude_leading_images', 'Images are lazy-loaded from the first one, including the logo and the hero image (Largest Contentful Paint).', 'Set Exclude Leading Images to 2 or more. (The first hero image already gets fetchpriority=high, which Perfmatters never lazy-loads.)' );
		}
		if ( $on( $l['css_background_images'] ?? '' ) && (int) ( $l['css_background_exclude_leading'] ?? 0 ) < 1 ) {
			$sel = implode( ' ', $lines( $l['css_background_selectors'] ?? [] ) );
			if ( preg_match( '/kt-row-has-bg|kb-row-layout|rowlayout|hero|kt-inside-inner-col/i', $sel ) ) {
				$risk( 'css_background', 'warning', 'lazyload.css_background_images', 'Lazy CSS background images (' . $sel . ') delays hero row backgrounds.', 'Set Exclude Leading Elements (CSS background images) to 1 or more, or remove the row selector.' );
			}
		}
		if ( $on( $o['disable_google_maps'] ?? '' ) ) {
			$risk( 'google_maps', 'info', 'disable_google_maps', 'Disable Google Maps removes the Maps JavaScript API. Launch Builder map patterns are iframe embeds and keep working; a Kadence Google Maps block in JavaScript mode stops.', 'Keep map sections as iframe embeds.' );
		}
		if ( $on( $f['local_google_fonts'] ?? '' ) ) {
			$risk( 'local_fonts', 'warning', 'fonts.local_google_fonts', 'Perfmatters Local Fonts processes the Google Fonts that Kadence already loads locally (Kadence "Load Google Fonts Locally" owns fonts on Launch Builder sites).', 'Turn Perfmatters Local Fonts off.' );
		}
		if ( $on( $f['disable_google_fonts'] ?? '' ) && ! get_theme_mod( 'load_fonts_local', false ) ) {
			$risk( 'google_fonts_off', 'error', 'fonts.disable_google_fonts', 'Google Fonts are disabled and Kadence is not loading them locally: the brand fonts fall back to system fonts.', 'Turn on Kadence "Load Google Fonts Locally" (sync-seo does) or turn Disable Google Fonts off.' );
		}
		if ( in_array( (string) ( $o['disable_rest_api'] ?? '' ), [ 'disable_non_admins', 'disable_logged_out' ], true ) ) {
			$risk( 'rest_api', 'disable_non_admins' === $o['disable_rest_api'] ? 'warning' : 'info', 'disable_rest_api', 'Disable REST API blocks /wp-json for ' . ( 'disable_non_admins' === $o['disable_rest_api'] ? 'everyone but administrators (editors lose the block editor)' : 'logged-out visitors' ) . '. Launch Builder\'s own routes are added to Perfmatters\' exceptions.', 'Connect MCP clients to /mcp (not a /wp-json URL). Prefer "Disable when logged out".' );
		}
		if ( $on( $o['remove_global_styles'] ?? '' ) ) {
			$risk( 'global_styles', 'warning', 'remove_global_styles', 'Remove Global Styles drops WordPress\'s layout and preset CSS (core buttons, columns, group gaps and has-*-color classes used by core blocks).', 'Check pages built from core blocks after turning it on, or keep it off.' );
		}
		if ( $on( $o['disable_embeds'] ?? '' ) ) {
			$risk( 'embeds', 'info', 'disable_embeds', 'Disable Embeds turns off oEmbed: core/embed blocks (YouTube, Vimeo) render as plain links.', 'Use video blocks with an iframe or keep embeds on where videos are used.' );
		}
		$settings = [
			'remove_unused_css' => $on( $a['remove_unused_css'] ?? '' ), 'delay_js' => $on( $a['delay_js'] ?? '' ), 'delay_js_behavior' => (string) ( $a['delay_js_behavior'] ?? '' ),
			'lazy_loading' => $on( $l['lazy_loading'] ?? '' ), 'lazy_loading_iframes' => $on( $l['lazy_loading_iframes'] ?? '' ), 'exclude_leading_images' => (int) ( $l['exclude_leading_images'] ?? 0 ),
			'css_background_images' => $on( $l['css_background_images'] ?? '' ), 'local_google_fonts' => $on( $f['local_google_fonts'] ?? '' ), 'disable_google_maps' => $on( $o['disable_google_maps'] ?? '' ),
			'disable_rest_api' => (string) ( $o['disable_rest_api'] ?? '' ), 'remove_global_styles' => $on( $o['remove_global_styles'] ?? '' ),
		];
		return [
			'active'       => self::pm_active(),
			'version'      => defined( 'PERFMATTERS_VERSION' ) ? (string) PERFMATTERS_VERSION : '',
			'option_found' => (bool) $o,
			'auto'         => [ 'rucss_selectors' => count( (array) ( $lists['rucss'] ?? [] ) ), 'delay_exclusions' => (array) ( $lists['delay'] ?? [] ), 'lazy_exclusions' => (array) ( $lists['lazy'] ?? [] ), 'rest_exceptions' => (array) ( $lists['rest'] ?? [] ) ],
			'risks'        => $risks,
			'settings'     => $settings,
		];
	}

	/* ------------------------------------------------------------------ audit checks */

	private static function finding( string $severity, string $message, string $fix, string $type = 'site', $id = 0, string $url = '' ): array {
		return [ 'severity' => $severity, 'object_type' => $type, 'id' => $id, 'url' => $url, 'message' => $message, 'fix' => $fix ];
	}

	public static function check_rm_keys( array $args = [] ): array {
		unset( $args );
		if ( ! self::rm_active() ) {
			return [];
		}
		$out = [];
		$overlaid = (array) get_option( self::OPT_OWNED, [] );
		foreach ( self::rm_status()['rows'] as $r ) {
			if ( 'differs' === $r['status'] && in_array( substr( $r['key'], strlen( 'titles.' ) ), $overlaid, true ) && 0 === strpos( $r['key'], 'titles.' ) ) {
				/* translators: %s: setting key */
				$out[] = self::finding( 'info', sprintf( __( 'Rank Math setting %s still stores an older Business Info value; visitors already see the current one (overlaid at read time).', 'launch-builder' ), $r['key'] ), 'Run sync-seo to store it.', 'option', $r['key'] );
			} elseif ( in_array( $r['status'], [ 'missing', 'differs' ], true ) ) {
				/* translators: 1: setting key, 2: status */
				$out[] = self::finding( 'warning', sprintf( __( 'Rank Math setting %1$s is %2$s (services/areas sitemap, schema or knowledge graph).', 'launch-builder' ), $r['key'], $r['status'] ), 'Run sync-seo.', 'option', $r['key'] );
			} elseif ( 'kept_edit' === $r['status'] ) {
				/* translators: %s: setting key */
				$out[] = self::finding( 'info', sprintf( __( 'Rank Math setting %s holds a value set in Rank Math, so Launch Builder keeps it.', 'launch-builder' ), $r['key'] ), 'sync-seo with force ["' . $r['key'] . '"] restores the Launch Builder value.', 'option', $r['key'] );
			}
		}
		return $out;
	}

	public static function check_elements_sitemap( array $args = [] ): array {
		unset( $args );
		$out = [];
		if ( self::rm_active() ) {
			if ( ! apply_filters( 'rank_math/sitemap/exclude_post_type', false, 'kadence_element' ) ) {
				$out[] = self::finding( 'error', 'Kadence Elements can appear in the Rank Math sitemap (they redirect visitors to the home page).', 'Something removed Launch Builder\'s sitemap filter; set Sitemap > Kadence Elements off.' );
			} elseif ( 'on' === ( self::raw_option( self::RM_OPTIONS['sitemap'] )['pt_kadence_element_sitemap'] ?? '' ) ) {
				$out[] = self::finding( 'info', 'Rank Math\'s sitemap setting for Kadence Elements is on; Launch Builder keeps them out by filter.', 'Run sync-seo to turn the setting off too.' );
			}
		}
		if ( self::yoast_active() && ! apply_filters( 'wpseo_sitemap_exclude_post_type', false, 'kadence_element' ) ) {
			$out[] = self::finding( 'error', 'Kadence Elements can appear in the Yoast sitemap.', 'Restore Launch Builder\'s wpseo_sitemap_exclude_post_type filter.' );
		}
		return $out;
	}

	public static function check_rm_modules( array $args = [] ): array {
		unset( $args );
		if ( ! self::rm_active() ) {
			return [];
		}
		$out = [];
		if ( ! self::rm_ready() ) {
			$out[] = self::finding( 'error', __( 'Rank Math is active but not set up: it outputs no titles, descriptions, schema or sitemap until its setup wizard is finished or skipped.', 'launch-builder' ), 'Open Rank Math in wp-admin and connect a free account or choose "Skip" in the setup wizard.' );
		}
		if ( ! self::rm_module( 'rich-snippet' ) ) {
			$out[] = self::finding( 'warning', 'Rank Math\'s Schema module is off: no Service schema on services, no WebPage/Organization graph, no breadcrumb schema from Rank Math.', 'Turn on Rank Math > Dashboard > Schema.' );
		}
		if ( ! self::rm_module( 'sitemap' ) ) {
			$out[] = self::finding( 'warning', 'Rank Math\'s Sitemap module is off: services and areas are in no XML sitemap.', 'Turn on Rank Math > Dashboard > Sitemap.' );
		}
		return $out;
	}

	public static function check_tokens_in_meta( array $args = [] ): array {
		unset( $args );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ('rank_math_title','rank_math_description','_yoast_wpseo_title','_yoast_wpseo_metadesc','rank_math_facebook_description','rank_math_twitter_description') AND meta_value LIKE '%{{%' LIMIT 50" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = [];
		foreach ( (array) $rows as $r ) {
			/* translators: %s: meta key */
			$out[] = self::finding( 'warning', sprintf( __( '%s contains a {{token}}: SEO plugins print it literally.', 'launch-builder' ), $r->meta_key ), 'Use the %variable% form instead (for example %lb_phone%); see get-playbook seo.', get_post_type( (int) $r->post_id ), (int) $r->post_id, (string) get_permalink( (int) $r->post_id ) );
		}
		return $out;
	}

	public static function check_unknown_vars( array $args = [] ): array {
		unset( $args );
		$out = [];
		$templates = self::templates();
		if ( self::rm_active() ) {
			$titles = self::raw_option( self::RM_OPTIONS['titles'] );
			foreach ( self::OPTION_ROLES as $keys ) {
				foreach ( array_filter( $keys ) as $k ) {
					$templates['rank_math'][ $k ] = (string) ( $titles[ $k ] ?? '' );
				}
			}
		}
		foreach ( $templates as $role => $fields ) {
			foreach ( (array) $fields as $field => $tpl ) {
				$u = self::unknown_vars( (string) $tpl );
				if ( $u ) {
					/* translators: 1: template, 2: variables */
					$out[] = self::finding( 'warning', sprintf( __( 'SEO template %1$s uses variables nothing replaces: %2$s.', 'launch-builder' ), $role . '.' . $field, '%' . implode( '%, %', $u ) . '%' ), 'Fix the template with sync-seo templates.' );
				}
			}
		}
		return $out;
	}

	public static function check_indexing( array $args = [] ): array {
		unset( $args );
		if ( ! self::rm_active() || ! self::rm_module( 'instant-indexing' ) ) {
			return [];
		}
		$s = get_option( self::OPT_INDEXNOW, [] );
		$n = is_array( $s ) ? (int) ( $s['count'] ?? 0 ) : 0;
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			/* translators: %d: count */
			return [ self::finding( 'info', sprintf( __( 'Rank Math Instant Indexing is on while search engines are discouraged; Launch Builder suppresses IndexNow (%d submissions so far).', 'launch-builder' ), $n ), 'At go-live: allow search engines, then submit the sitemap.' ) ];
		}
		return [];
	}

	public static function check_perfmatters( array $args = [] ): array {
		unset( $args );
		if ( ! self::pm_active() && ! get_option( 'perfmatters_options' ) ) {
			return [];
		}
		$out = [];
		foreach ( self::perfmatters_report()['risks'] as $r ) {
			$out[] = self::finding( $r['severity'], 'Perfmatters ' . $r['setting'] . ': ' . $r['message'], $r['fix'], 'option', 'perfmatters_options' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ tools */

	public static function get_compat_tool( $input = [] ): array {
		$section = (string) ( ( (array) $input )['section'] ?? 'all' );
		$all = 'all' === $section;
		$out = [ 'seo_plugins' => self::seo_plugins() ];
		if ( $all || 'rank_math' === $section ) {
			$rm = [ 'active' => self::rm_active() ];
			if ( $rm['active'] ) {
				$st = self::rm_status();
				$rm += [
					'ready'          => self::rm_ready() ? true : 'no: Rank Math loads nothing until its setup wizard is finished or skipped (account connection); the settings below are stored and apply then',
					'version'        => self::rm_version(),
					'modules'        => array_values( (array) get_option( self::RM_MODULES, [] ) ),
					'abilities'      => function_exists( 'wp_get_ability' ) && did_action( 'init' ) && wp_get_ability( 'rank-math/set-sitemap-settings' ) ? 'available' : 'not available (direct writes)',
					'settings'       => $st['rows'],
					'to_sync'        => $st['to_sync'],
					'kept_edits'     => $st['kept'],
					'overlay_keys'   => array_values( (array) get_option( self::OPT_OWNED, [] ) ),
					'last_fill'      => self::last_fill(),
					'templates'      => self::templates() + array_diff_key( self::DEFAULT_TEMPLATES, self::templates() ),
					'indexnow'       => [ 'suppressed_now' => self::indexing_suppressed(), 'suppressed' => get_option( self::OPT_INDEXNOW, [ 'count' => 0 ] ) ],
					'breadcrumbs_on' => current_theme_supports( 'rank-math-breadcrumbs' ) || 'on' === ( self::raw_option( self::RM_OPTIONS['general'] )['breadcrumbs'] ?? '' ),
				];
			}
			$out['rank_math'] = $rm;
			$out['yoast'] = [ 'active' => self::yoast_active() ];
		}
		if ( $all || 'kadence' === $section ) {
			$out['kadence'] = Plugin::kadence_theme_active() ? [ 'microdata' => (bool) apply_filters( 'kadence_microdata', true, 'launch-builder-report' ), 'load_fonts_local' => (bool) get_theme_mod( 'load_fonts_local', false ) ] : [ 'active' => false ];
		}
		if ( $all || 'meta_box' === $section ) {
			$mb = self::mb_status();
			if ( ! $mb['active'] ) {
				unset( $mb['fields'], $mb['owns_card_fields'] ); // Same shape as before: just active and version.
			}
			$out['meta_box'] = $mb;
		}
		if ( $all || 'perfmatters' === $section ) {
			$out['perfmatters'] = self::perfmatters_report();
		}
		return $out;
	}

	public static function sync_seo_tool( $input = [] ) {
		$input = (array) $input;
		$args = [ 'dry_run' => ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] ) ];
		foreach ( [ 'templates', 'local_seo', 'pages', 'force', 'offset' ] as $k ) {
			if ( array_key_exists( $k, $input ) ) {
				$args[ $k ] = $input[ $k ];
			}
		}
		if ( isset( $args['templates'] ) && ! is_array( $args['templates'] ) ) {
			return new \WP_Error( 'lb_templates', 'templates must be an object {role: {title, description}}.', [ 'status' => 400 ] );
		}
		if ( isset( $args['force'] ) && ! is_array( $args['force'] ) ) {
			return new \WP_Error( 'lb_force', 'force must be a list.', [ 'status' => 400 ] );
		}
		if ( ! $args['dry_run'] && ! Lock::acquire( 'sync', 120 ) ) {
			return new \WP_Error( 'lb_locked', 'Another sync is running; try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			$r = self::sync_seo( $args );
		} finally {
			if ( ! $args['dry_run'] ) {
				Lock::release( 'sync' );
			}
		}
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'lb_seo', $r['error'], [ 'status' => 400 ] );
		}
		return $r;
	}

	public static function fill_rank_math_tool( $input = [] ) {
		$input = (array) $input;
		if ( isset( $input['force'] ) && ! is_array( $input['force'] ) ) {
			return new \WP_Error( 'lb_force', 'force must be a list.', [ 'status' => 400 ] );
		}
		$undo = ! empty( $input['undo'] );
		$dry = ! $undo && ( ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] ) );
		if ( ! $dry && ! Lock::acquire( 'sync', 120 ) ) {
			return new \WP_Error( 'lb_locked', 'Another sync is running; try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			if ( $undo ) {
				return self::undo_business_fill();
			}
			$args = [ 'dry_run' => $dry, 'force' => (array) ( $input['force'] ?? [] ) ];
			if ( array_key_exists( 'local_seo', $input ) ) {
				$args['local_seo'] = (bool) $input['local_seo'];
			}
			return self::business_sync( $args );
		} finally {
			if ( ! $dry ) {
				Lock::release( 'sync' );
			}
		}
	}

	public static function preview_seo_tool( $input = [] ) {
		$input = (array) $input;
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( ! $id && ! empty( $input['path'] ) && is_string( $input['path'] ) ) {
			$p = get_page_by_path( trim( $input['path'], '/ ' ), OBJECT, [ 'page', Entries::SERVICE, Entries::AREA, 'post' ] );
			$id = $p ? (int) $p->ID : 0;
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'lb_not_found', 'No such post (give post_id or path).', [ 'status' => 404 ] );
		}
		return self::preview( $post, (string) ( $input['template_title'] ?? '' ), (string) ( $input['template_description'] ?? '' ) );
	}

	/**
	 * Resolved SEO title/description/robots for a post, following the same precedence as the front end: own meta, then
	 * (pages) the role template / (services, areas) the post type template, then Rank Math's post type template; tokens
	 * left in the result are resolved as text.
	 */
	public static function preview( \WP_Post $post, string $try_title = '', string $try_desc = '' ): array {
		$role = Roles::of( $post->ID );
		$rm = self::rm_active();
		$titles = $rm ? self::raw_option( self::RM_OPTIONS['titles'] ) : [];
		$pick = static function ( array $cands ) {
			foreach ( $cands as $from => $tpl ) {
				if ( '' !== trim( (string) $tpl ) ) {
					return [ $from, (string) $tpl ];
				}
			}
			return [ 'none', '' ];
		};
		$entry = in_array( $post->post_type, [ Entries::SERVICE, Entries::AREA ], true );
		$pt_title = (string) ( $titles[ 'pt_' . $post->post_type . '_title' ] ?? '%title% %sep% %sitename%' );
		$pt_desc = (string) ( $titles[ 'pt_' . $post->post_type . '_description' ] ?? '' );
		[ $tf, $tt ] = $pick( [ 'try' => $try_title, 'meta' => get_post_meta( $post->ID, 'rank_math_title', true ), 'role_template' => ( 'page' === $post->post_type && ! isset( self::OPTION_ROLES[ $role ] ) ) ? self::role_template( $role, 'title' ) : '', 'post_type_template' => $pt_title ] );
		[ $df, $dt ] = $pick( [ 'try' => $try_desc, 'meta' => get_post_meta( $post->ID, 'rank_math_description', true ), 'role_template' => $entry ? $pt_desc : ( 'page' === $post->post_type && ! isset( self::OPTION_ROLES[ $role ] ) ? self::role_template( $role, 'description' ) : '' ), 'excerpt' => $post->post_excerpt, 'post_type_template' => $pt_desc ] );
		$ready = self::rm_ready();
		$resolve = static function ( string $tpl ) use ( $post, $ready ): string {
			$s = $ready ? self::rm_replace( $tpl, $post ) : self::basic_replace( $tpl, $post );
			if ( false !== strpos( $s, '{{' ) ) {
				Tokens::push( $post );
				$s = Tokens::replace( $s, 'text' );
				Tokens::pop();
			}
			return self::clean_seo( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) );
		};
		$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
		$robots = is_array( $robots ) && $robots ? array_values( $robots ) : ( self::ROLE_ROBOTS[ $role ] ?? [ 'index', 'follow' ] );
		return [
			'id'                 => $post->ID,
			'role'               => $role,
			'seo_plugin'         => self::seo_plugins() ? self::seo_plugins()[0] : 'none',
			'resolved_by'        => $ready ? 'rank_math' : 'launch_builder (Rank Math not running: only %lb_*%, %title%, %sitename%, %sitedesc%, %sep%, %excerpt% resolved)',
			'title'              => $resolve( $tt ),
			'title_source'       => $tf,
			'title_template'     => $tt,
			'description'        => $resolve( $dt ),
			'description_source' => $df,
			'description_template' => $dt,
			'robots'             => $robots,
			'unknown_vars'       => array_values( array_unique( array_merge( self::unknown_vars( $tt ), self::unknown_vars( $dt ) ) ) ),
		];
	}
}
