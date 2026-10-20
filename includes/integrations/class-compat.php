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
 *
 * Files: this one holds the constants, the per-request state, the module hooks, detection, the audit checks and the
 * tools; each integration's methods live in a trait next to it (trait-compat-seo.php, -seo-business, -seo-output,
 * -kadence, -meta-box, -perfmatters). They are all methods of Compat, so callers and hooks name Compat as before.
 */
final class Compat {

	// The integrations, one file each (includes/integrations/trait-compat-*.php); constants and state stay here.
	use Compat_Seo, Compat_Seo_Business, Compat_Seo_Output, Compat_Kadence, Compat_Meta_Box, Compat_Perfmatters;

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

	/** Field types that only lay a settings page out: they hold no value. */
	const MB_LAYOUT_TYPES = [ 'heading', 'divider', 'custom_html', 'button', 'tab', 'sidebar', 'background', 'backup' ];

	/** Field ids of a settings page that are never listed or printed: they read like a secret, a login, an address a service is reached at or a bank detail. */
	const MB_SECRET_ID = '/(pass|pwd|auth(?!or)|bearer|webhook|smtp|dsn|account|client_?id|endpoint|secret|token|salt|_key$|api_?key|(^|_)api(_|$)|private|licen[sc]e_?(key|code)|credential|iban|routing|ssn|card_?(number|no)|cvv)/i';

	/** The only field types a {{meta.*}} token reads from a settings page: text-like values a visitor could read anyway. Lists, files, relations and groups never print from a settings page. */
	const MB_TOKEN_TYPES = [ 'text', 'textarea', 'number', 'range', 'select', 'select_advanced', 'radio', 'button_group', 'url', 'email', 'tel', 'date', 'datetime', 'time', 'color', 'checkbox', 'switch', 'wysiwyg' ];

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

	/** @var string last suppressed URL. */
	private static $last_url = '';

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
				unset( $mb['fields'], $mb['owns_card_fields'], $mb['settings_pages'] ); // Same shape as before: just active and version.
			} elseif ( Modules::enabled( Meta_Box_Sync::class ) ) {
				$mb['sync'] = array_map( static function ( $r ) { return [ 'state' => $r['state'], 'note' => $r['note'] ]; }, Meta_Box_Sync::status() ); // See sync-meta-box.
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
