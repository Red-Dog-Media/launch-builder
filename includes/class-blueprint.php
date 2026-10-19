<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Site blueprint: one JSON describing a whole starter site. Idempotent (pages and synced patterns are matched by key).
 */
final class Blueprint {

	public static function define_tools(): void {
		Abilities::add( 'set-synced-pattern', 'Create or update a synced pattern', 'Make a pattern that syncs everywhere it is used (edit once, every page updates). key identifies it; re-running updates it. Reference it from a page with insert-synced, or from a blueprint section as {use:key}.',
			[ 'properties' => [ 'key' => [ 'type' => 'string' ], 'pattern' => [ 'type' => 'string' ], 'sections' => [ 'type' => 'array', 'description' => 'Instead of one pattern: a stack [{pattern, replace, slots}] shown as one synced block.' ], 'title' => [ 'type' => 'string' ], 'slots' => [ 'type' => 'object' ], 'replace' => [ 'type' => 'object' ] ], 'required' => [ 'key' ] ], [ __CLASS__, 'set_synced' ], 'edit_pages' );

		Abilities::add( 'insert-synced', 'Insert a synced pattern into a page', 'Adds a reference to a synced pattern (by key) to a page.',
			[ 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'key' => [ 'type' => 'string' ], 'position' => [ 'type' => 'string', 'enum' => [ 'append', 'prepend' ] ] ], 'required' => [ 'post_id', 'key' ] ], [ __CLASS__, 'insert_synced' ], 'edit_pages' );

		Abilities::add( 'set-pro-modules', 'Switch Kadence Theme Kit Pro modules on or off', 'Needs Kadence Theme Kit Pro. Modules: elements, conditional_headers, header_addons (sticky/transparent headers), mega_menu, adv_pages, scripts, dark_mode, infinite, localgravatars, archive_meta, woocommerce_addons. They take effect on the NEXT request.',
			[ 'properties' => [ 'modules' => [ 'type' => 'object', 'description' => '{ "elements": true, "mega_menu": false }' ] ], 'required' => [ 'modules' ] ], [ __CLASS__, 'set_pro_modules' ], 'manage_options' );

		Abilities::add( 'set-element', 'Show a synced pattern with a Kadence Pro Element', 'Needs Kadence Theme Kit Pro. Places a synced pattern (by key) at a Kadence hook, optionally only on chosen pages. hook examples: kadence_after_header, kadence_before_content, kadence_after_content (below page content), kadence_before_footer, replace_footer, replace_header, kadence_single_after_entry_content. pages: page slugs to limit it to (omit = whole site); hide_pages: slugs to exclude. key identifies the element (re-running updates it). Without Pro use set-global-area instead.',
			[ 'properties' => [
				'key'        => [ 'type' => 'string' ],
				'synced_key' => [ 'type' => 'string', 'description' => 'Key of a synced pattern (set-synced-pattern).' ],
				'hook'       => [ 'type' => 'string' ],
				'priority'   => [ 'type' => 'integer' ],
				'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Show on every entry of these post types, e.g. ["page","lb_service","lb_area"].' ],
				'hide_front' => [ 'type' => 'boolean', 'description' => 'Do not show on the front page.' ],
				'pages'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'hide_pages' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'pattern'    => [ 'type' => 'string', 'description' => 'Put this pattern straight into the element (instead of synced_key).' ],
				'sections'   => [ 'type' => 'array', 'description' => 'Or several patterns: [{pattern, slots, replace, markdown}].' ],
				'slots'      => [ 'type' => 'object' ],
				'replace'    => [ 'type' => 'object' ],
				'title'      => [ 'type' => 'string' ],
			], 'required' => [ 'key', 'hook' ] ], [ __CLASS__, 'set_element' ], 'edit_theme_options' );

		Abilities::add( 'set-global-area', 'Show a synced pattern on every page', 'Areas: above_header, below_header, above_footer, footer (footer replaces the theme footer). Pass key "" to clear an area.',
			[ 'properties' => [ 'area' => [ 'type' => 'string', 'enum' => Globals::areas() ], 'key' => [ 'type' => 'string' ] ], 'required' => [ 'area', 'key' ] ], [ __CLASS__, 'set_area' ], 'edit_theme_options' );

		Abilities::add( 'build-site', 'Build a whole site from a blueprint', 'One call to set up site identity, brand, header + menu, synced/global patterns, footer, pages (made of patterns with filled slots), SEO meta and the front page. Idempotent. Blueprint: {site:{title,tagline,permalink}, company:{name,phone,email,address,city,hours,quote_url,cta_label,cta_url,cta2_label,cta2_url,maps_url,form_html,facebook,instagram,google,youtube} (GLOBAL business info: patterns use tokens, so changing it later updates every page; see get-company), company_source:{option,map} (optional: read these from another settings option), business:{name,phone,email,address,hours,city,cities:[...]} (older name for the same facts; cities fills the city names in service-area patterns), brand:{palette,fonts,logo_url}, header:{pattern,slots,replace,nav:[{label,page|url,children}]}, synced:{key:{pattern,slots,replace} or {sections:[{pattern,replace}]} for a stack of several patterns}, areas:{above_footer:key,footer:key}, entries:{services:[{title,category,summary,markdown,order}], areas:[{title,parent,summary,markdown,order}], categories:[{name,slug,page}]} (SERVICES AND AREAS AS POST TYPES: the live lists and the per-entry pages come from these; add synced keys service_template and area_template), sidebar:"synced_key" (theme sidebar widget area; shown on pages with type "sidebar"), pro_modules:{elements:true,...}, elements:{key:{pattern|sections:[{pattern,replace}] (content built into the element) or synced,hook,pages:[slugs],post_types:["page","lb_service","lb_area"],hide_front,hide_pages,priority}}, chrome:"elements" (shared hero via Kadence Pro hook replace_hero_header + breadcrumb bar via kadence_before_content, placed once for every page and entry; needs Theme Kit Pro) or "patterns" (default: hero and breadcrumb patterns on each page) (Kadence Pro: per-page placement of a synced pattern), design:{buttons:{primary:{background,radius,...},secondary,outline}, nav:{links:{...},dropdown:{...}}, page:"banner"|{title,content,site}, style:"clean"|"bold"|"sharp"|"soft"|{preset,tokens} (style DNA, see set-style), block_defaults:"standard" to also set Kadence block defaults} (the global look; see set-buttons, set-nav-style, set-page-style), pages:[{title,slug,front,parent,status,type:"landing"|"text"|"wide"|"sidebar",recipe,markdown,replace,seo:{title,description,keyword},sections:[{pattern,slots,replace,markdown?}|{use:key}|{markdown:"..."}]}]} (a pattern section may carry markdown for its text area: service-body). Page type: landing = full-width pattern pages (home, contact, quote); text = theme title banner + readable column with bare Markdown text (default for inner pages); wide = text plus media patterns; sidebar = text with the theme sidebar (the sidebar pattern) beside it. recipe (home, about, service-detail, location, contact, quote, faq, reviews...) supplies starting sections; get-playbook explains. Returns a report with page URLs.',
			[ 'properties' => [ 'blueprint' => [ 'type' => 'object' ] ], 'required' => [ 'blueprint' ] ], [ __CLASS__, 'build' ], 'manage_options' );
	}

	/* ------------------------------------------------------------------ synced patterns */

	public static function find_synced( string $key ): int {
		$f = get_posts( [ 'post_type' => 'wp_block', 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => 'synced-' . $key, 'numberposts' => 1, 'fields' => 'ids' ] );
		return $f ? (int) $f[0] : 0;
	}

	public static function set_synced( $input ) {
		$key = sanitize_key( $input['key'] ?? '' );
		if ( ! empty( $input['sections'] ) ) { // A synced stack: several patterns in one synced block (for example reviews + offers + service areas above the footer).
			$parts = [];
			$warns = [];
			foreach ( (array) $input['sections'] as $sec ) {
				$sec = (array) $sec;
				$one = Patterns::build_pattern( sanitize_key( $sec['pattern'] ?? '' ), (array) ( $sec['slots'] ?? [] ), (array) ( $sec['replace'] ?? [] ), (array) ( $input['vars'] ?? [] ), (string) ( $sec['markdown'] ?? '' ) );
				if ( is_wp_error( $one ) ) {
					return $one;
				}
				$parts[] = $one['content'];
				$warns = array_merge( $warns, array_map( static function ( $w ) use ( $sec ) { return ( $sec['pattern'] ?? '' ) . ': ' . $w; }, $one['warnings'] ) );
			}
			$built = [ 'content' => implode( "\n\n", $parts ), 'applied' => [], 'warnings' => $warns ];
		} else {
			$built = Patterns::build_pattern( sanitize_key( $input['pattern'] ?? '' ), (array) ( $input['slots'] ?? [] ), (array) ( $input['replace'] ?? [] ), (array) ( $input['vars'] ?? [] ) );
		}
		if ( is_wp_error( $built ) || '' === $key ) {
			return is_wp_error( $built ) ? $built : new \WP_Error( 'lb_key', 'key is required.', [ 'status' => 400 ] );
		}
		$id = Abilities::upsert_post( 'wp_block', 'synced-' . $key, sanitize_text_field( $input['title'] ?? ( 'Launch Builder: ' . $key ) ), $built['content'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return [ 'id' => $id, 'key' => $key, 'applied' => $built['applied'], 'warnings' => $built['warnings'], 'edit_url' => get_edit_post_link( $id, 'raw' ) ];
	}

	public static function insert_synced( $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$bid = self::find_synced( sanitize_key( $input['key'] ?? '' ) );
		if ( ! $bid || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'lb_not_found', 'Unknown synced key or you cannot edit that post.', [ 'status' => 404 ] );
		}
		$ref = '<!-- wp:block {"ref":' . $bid . '} /-->';
		$cur = (string) get_post_field( 'post_content', $post_id );
		$new = 'prepend' === ( $input['position'] ?? 'append' ) ? $ref . "\n\n" . $cur : $cur . "\n\n" . $ref;
		wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $new ) ] );
		return [ 'post_id' => $post_id, 'synced_id' => $bid ];
	}

	private const PRO_MODULES = [ 'conditional_headers', 'elements', 'adv_pages', 'header_addons', 'mega_menu', 'woocommerce_addons', 'scripts', 'infinite', 'localgravatars', 'archive_meta', 'dark_mode' ];

	public static function set_pro_modules( $input ) {
		if ( ! Plugin::requirement_met( 'kadence-pro' ) ) {
			return new \WP_Error( 'lb_no_pro', 'Kadence Theme Kit Pro is not active.', [ 'status' => 412 ] );
		}
		$cur = json_decode( (string) get_option( 'kadence_pro_theme_config', '' ), true );
		$cur = is_array( $cur ) ? $cur : [];
		$changed = [];
		foreach ( (array) ( $input['modules'] ?? [] ) as $k => $v ) {
			if ( in_array( $k, self::PRO_MODULES, true ) && ( $cur[ $k ] ?? null ) !== (bool) $v ) {
				$cur[ $k ] = (bool) $v;
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			$snap = Abilities::snapshot( 'set-pro-modules', [], [ 'kadence_pro_theme_config' ] );
			update_option( 'kadence_pro_theme_config', wp_json_encode( $cur ) );
		}
		return [ 'modules' => $cur, 'changed' => $changed, 'snapshot' => $snap ?? null, 'note' => $changed ? 'Modules load on the next request. Call again before using what they add (e.g. set-element).' : 'No change.' ];
	}

	/** Page slugs -> a Kadence Elements rule "show/hide on these page IDs". */
	private static function page_rules( array $slugs ): array {
		$ids = [];
		foreach ( $slugs as $slug ) {
			$p = get_page_by_path( sanitize_title( (string) $slug ), OBJECT, 'page' );
			if ( $p && 'trash' !== $p->post_status ) {
				$ids[] = (int) $p->ID; // Kadence compares these strictly, so they must be integers.
			}
		}
		return $ids ? [ [ 'rule' => 'singular|page', 'select' => 'ids', 'ids' => $ids ] ] : [];
	}

	public static function set_element( $input ) {
		if ( ! Plugin::requirement_met( 'kadence-pro' ) || ! post_type_exists( 'kadence_element' ) ) {
			return new \WP_Error( 'lb_no_pro', 'Kadence Theme Kit Pro (Elements) is not active. Use set-global-area for a site-wide area without Pro.', [ 'status' => 412 ] );
		}
		$key = sanitize_key( $input['key'] ?? '' );
		$hook = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $input['hook'] ?? '' ) ) );
		if ( '' === $key || '' === $hook ) {
			return new \WP_Error( 'lb_args', 'key and hook are required.', [ 'status' => 400 ] );
		}
		// Content: pattern(s) built straight into the element (edit it right there), or a reference to a synced pattern.
		$sections = (array) ( $input['sections'] ?? [] );
		if ( ! $sections && ! empty( $input['pattern'] ) ) {
			$sections = [ [ 'pattern' => $input['pattern'], 'slots' => (array) ( $input['slots'] ?? [] ), 'replace' => (array) ( $input['replace'] ?? [] ) ] ];
		}
		$bid = 0;
		$warnings = [];
		if ( $sections ) {
			$parts = [];
			foreach ( $sections as $sec ) {
				$sec = (array) $sec;
				$one = Patterns::build_pattern( sanitize_key( $sec['pattern'] ?? '' ), (array) ( $sec['slots'] ?? [] ), (array) ( $sec['replace'] ?? [] ), [], (string) ( $sec['markdown'] ?? '' ) );
				if ( is_wp_error( $one ) ) {
					return $one;
				}
				$parts[] = $one['content'];
				$warnings = array_merge( $warnings, array_map( static function ( $w ) use ( $sec ) { return ( $sec['pattern'] ?? '' ) . ': ' . $w; }, $one['warnings'] ) );
			}
			$content = implode( "\n\n", $parts );
		} else {
			$bid = self::find_synced( sanitize_key( $input['synced_key'] ?? '' ) );
			if ( ! $bid ) {
				return new \WP_Error( 'lb_args', 'Give a pattern (or sections), or synced_key of an existing synced pattern.', [ 'status' => 400 ] );
			}
			$content = '<!-- wp:block {"ref":' . $bid . '} /-->';
		}
		$pages = array_slice( array_map( 'sanitize_title', (array) ( $input['pages'] ?? [] ) ), 0, 50 );
		$pts = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [] ) ), 'post_type_exists' ) );
		$show = $pages ? self::page_rules( $pages ) : [ [ 'rule' => 'general|site' ] ];
		if ( ! $pages && $pts ) { // Everything of these post types (for example every page and every service).
			$show = array_map( static function ( $pt ) { return [ 'rule' => 'singular|' . $pt, 'select' => 'all' ]; }, $pts );
		}
		if ( $pages && ! $show ) {
			return new \WP_Error( 'lb_no_pages', 'None of the given page slugs exist yet.', [ 'status' => 400 ] );
		}
		$id = Abilities::upsert_post( 'kadence_element', 'element-' . $key, sanitize_text_field( $input['title'] ?? ( 'Launch Builder: ' . $key ) ), $content );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_kad_element_type', 'default' );
		update_post_meta( $id, '_kad_element_hook', $hook );
		update_post_meta( $id, '_kad_element_hook_priority', (int) ( $input['priority'] ?? 10 ) );
		update_post_meta( $id, '_kad_element_show_conditionals', wp_slash( wp_json_encode( $show ) ) );
		$hide = self::page_rules( (array) ( $input['hide_pages'] ?? [] ) );
		if ( ! empty( $input['hide_front'] ) ) {
			$hide[] = [ 'rule' => 'general|front_page' ];
		}
		$hide ? update_post_meta( $id, '_kad_element_hide_conditionals', wp_slash( wp_json_encode( $hide ) ) ) : delete_post_meta( $id, '_kad_element_hide_conditionals' );
		return [ 'element_id' => $id, 'hook' => $hook, 'shows_on' => $pages ?: ( $pts ?: 'whole site' ), 'content' => $bid ? 'synced pattern #' . $bid : 'built from pattern(s)', 'warnings' => $warnings, 'edit_url' => get_edit_post_link( $id, 'raw' ) ];
	}

	public static function set_area( $input ) {
		$area = (string) ( $input['area'] ?? '' );
		$key = sanitize_key( $input['key'] ?? '' );
		$id = $key ? self::find_synced( $key ) : 0;
		if ( $key && ! $id ) {
			return new \WP_Error( 'lb_not_found', 'No synced pattern with key ' . $key . '. Create it with set-synced-pattern.', [ 'status' => 404 ] );
		}
		if ( ! Globals::set( $area, $id ) ) {
			return new \WP_Error( 'lb_area', 'Unknown area.', [ 'status' => 400 ] );
		}
		return [ 'area' => $area, 'synced_id' => $id ];
	}

	/* ------------------------------------------------------------------ blueprint */

	private static function resolve_nav( array $items, array $pages, array &$warnings = [] ): array {
		$out = [];
		foreach ( $items as $it ) {
			$it = (array) $it;
			if ( ! empty( $it['page'] ) ) {
				$slug = sanitize_title( (string) $it['page'] );
				if ( isset( $pages[ $slug ] ) ) {
					$it['url'] = get_permalink( $pages[ $slug ] );
				} else {
					$warnings[] = 'menu: no page with slug "' . $slug . '" in this blueprint (link left as ' . ( $it['url'] ?? '#' ) . ')';
				}
			}
			if ( ! empty( $it['children'] ) ) {
				$it['children'] = self::resolve_nav( (array) $it['children'], $pages, $warnings );
			}
			$out[] = $it;
		}
		return $out;
	}

	/** Map the placeholders every pattern uses for business facts to the real values. */
	public static function business_vars( array $biz ): array {
		$v = [];
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$phone = sanitize_text_field( $biz['phone'] ?? '' );
		$email = sanitize_email( $biz['email'] ?? '' );
		$addr = sanitize_text_field( $biz['address'] ?? '' );
		$hours = sanitize_text_field( $biz['hours'] ?? '' );
		$city = sanitize_text_field( $biz['city'] ?? '' );
		if ( $name ) {
			$v['Your Business'] = $name;
		}
		if ( $phone ) {
			$v['(555) 555-0123'] = $phone;
			$v['tel:5555550123'] = 'tel:' . preg_replace( '/[^0-9+]/', '', ( 0 === strpos( $phone, '+' ) ? '' : '+1' ) . $phone );
		}
		if ( $email ) {
			$v['hello@yourbusiness.com'] = $email;
		}
		if ( $addr ) {
			$parts = array_map( 'trim', explode( ',', $addr, 2 ) );
			$v['123 Main Street<br>Your City, ST 00000'] = count( $parts ) > 1 ? $parts[0] . '<br>' . $parts[1] : $addr;
			$v['123 Main Street, Your City, ST 00000'] = $addr;
			$v['123 Main Street, Your City, ST'] = $addr;
			if ( count( $parts ) > 1 && ! $city ) {
				$v['Your City, ST'] = $parts[1];
			}
		}
		if ( $city ) {
			$v['Your City, ST'] = $city;
		}
		$defaults = [ 'Springfield', 'Riverton', 'Oakdale', 'Fairview', 'Lakeside', 'Greenville', 'Maplewood', 'Brookfield', 'Hillcrest', 'Westport' ];
		foreach ( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $biz['cities'] ?? [] ) ) ) ) as $i => $c ) {
			if ( isset( $defaults[ $i ] ) ) {
				$v[ $defaults[ $i ] ] = $c; // The nth default city name becomes the nth city you serve.
			}
		}
		if ( $hours ) {
			$v['Mon–Fri 8am–6pm · Sat 9am–2pm'] = $hours;
			$v['Mon–Fri 8am–6pm<br>Sat 9am–2pm'] = str_replace( ' · ', '<br>', $hours );
			$v['Mon–Fri 8am–6pm'] = $hours;
		}
		return $v;
	}

	/** Built-in build-site phases, in the order they run. A module step names one of these as its 'at' ('start' = before anything is written). */
	const STEP_ANCHORS = [ 'start', 'company', 'identity', 'brand', 'design', 'entries', 'pages_created', 'synced', 'pages', 'categories', 'pro_modules', 'elements', 'header', 'nav_design', 'done' ];

	/**
	 * Steps contributed by modules (module hook blueprint_steps()). Each module returns a list of
	 *   [ 'id' => string, 'at' => one of STEP_ANCHORS, 'run' => callable( array $blueprint, array $ctx ): array|\WP_Error ]
	 * A step runs right after its anchor phase. $ctx = { ids: page slug => id, synced: key => wp_block id, vars: the legacy
	 * placeholder map }. The returned array lands in the report under steps.<id>; its 'warnings' (string[]) are merged
	 * into the report warnings, and a WP_Error becomes one warning. Steps must be idempotent, like build-site itself.
	 *
	 * @return array<string,array[]> anchor => steps
	 */
	public static function steps(): array {
		$out = [];
		foreach ( Plugin::modules( 'blueprint_steps' ) as $module ) {
			foreach ( (array) $module::blueprint_steps() as $st ) {
				$st = (array) $st;
				if ( ! empty( $st['id'] ) && in_array( $st['at'] ?? '', self::STEP_ANCHORS, true ) && is_callable( $st['run'] ?? null ) ) {
					$out[ $st['at'] ][] = $st;
				}
			}
		}
		return $out;
	}

	/** The function build() calls after each phase: runs the module steps anchored there. */
	private static function steps_runner( array $bp, array $vars, array &$report, &$ids, &$synced ): callable {
		$steps = self::steps();
		return static function ( string $at ) use ( $steps, $bp, $vars, &$report, &$ids, &$synced ): void {
			foreach ( $steps[ $at ] ?? [] as $st ) {
				$r = call_user_func( $st['run'], $bp, [ 'ids' => (array) $ids, 'synced' => (array) $synced, 'vars' => $vars ] );
				$id = sanitize_key( (string) $st['id'] );
				if ( is_wp_error( $r ) ) {
					$report['warnings'][] = 'step ' . $id . ': ' . $r->get_error_message();
					continue;
				}
				$r = (array) $r;
				foreach ( (array) ( $r['warnings'] ?? [] ) as $w ) {
					$report['warnings'][] = 'step ' . $id . ': ' . $w;
				}
				unset( $r['warnings'] );
				$report['steps'][ $id ] = $r;
			}
		};
	}

	public static function build( $input ) {
		/*
		 * Filter launch_builder_blueprint ( array $blueprint, array $input ): modules translate or complete the blueprint
		 * before anything is read from it (e.g. legacy elements/areas/header keys -> the chrome map, a style preset ->
		 * design.block_defaults false), so they never need to edit this method.
		 */
		$bp = (array) apply_filters( 'launch_builder_blueprint', (array) ( $input['blueprint'] ?? [] ), (array) $input );
		$pages_in = array_slice( (array) ( $bp['pages'] ?? [] ), 0, 60 );
		if ( ! $pages_in ) {
			return new \WP_Error( 'lb_bp', 'Blueprint needs at least one page.', [ 'status' => 400 ] );
		}
		if ( ! Plugin::kadence_blocks_active() ) {
			return new \WP_Error( 'lb_no_kadence', 'Kadence Blocks is not active; the patterns need it. Activate it (and the Kadence theme) first.', [ 'status' => 412 ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore -- big blueprints on slow hosts.
		}
		$report = [ 'warnings' => [] ];
		if ( ! Plugin::kadence_theme_active() ) {
			$report['warnings'][] = 'The Kadence theme is not active: header, global areas and Kadence page layout will not take effect.';
		}
		$vars = self::business_vars( (array) ( $bp['business'] ?? [] ) );
		$report['snapshot'] = Abilities::snapshot( 'build-site',
			[ 'blocks_header', 'blocks_header_id', 'custom_logo', 'base_font', 'heading_font' ],
			[ 'kadence_global_palette', 'blogname', 'blogdescription', 'page_on_front', 'show_on_front', 'permalink_structure', 'launch_builder_globals', 'launch_builder_company', 'launch_builder_company_source' ] );

		$step = self::steps_runner( $bp, $vars, $report, $ids, $synced ); // Module steps (blueprint_steps) run after the phase they name.
		$step( 'start' );

		// 0. Global business info. Patterns read it through tokens ({{lb.phone}}), so it is set before anything is built.
		$company = (array) ( $bp['company'] ?? [] );
		foreach ( (array) ( $bp['business'] ?? [] ) as $k => $v ) { // 'business' is the older name for the same facts.
			if ( in_array( $k, [ 'name', 'phone', 'email', 'address', 'hours', 'city' ], true ) && ! isset( $company[ $k ] ) ) {
				$company[ $k ] = $v;
			}
		}
		if ( $company ) {
			$report['company'] = Company::update( $company );
		}
		if ( ! empty( $bp['company_source'] ) ) {
			$cs = (array) $bp['company_source'];
			$r = Company::set_source( (string) ( $cs['option'] ?? '' ), (array) ( $cs['map'] ?? [] ) );
			if ( is_wp_error( $r ) ) {
				$report['warnings'][] = 'company_source: ' . $r->get_error_message();
			}
		}

		$step( 'company' );

		// 1. Site identity.
		$site = (array) ( $bp['site'] ?? [] );
		if ( isset( $site['title'] ) ) {
			update_option( 'blogname', sanitize_text_field( $site['title'] ) );
		}
		if ( isset( $site['tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $site['tagline'] ) );
		}
		Company::follow_site(); // A business name or tagline that is the same text as the title or tagline just set follows them instead of being a second copy.
		if ( ! empty( $site['permalink'] ) ) {
			$structure = sanitize_text_field( $site['permalink'] );
			if ( preg_match( '#%[a-z_]+%#', $structure ) ) {
				global $wp_rewrite;
				$wp_rewrite->set_permalink_structure( $structure ); // Applies immediately so get_permalink() below is correct.
			} else {
				$report['warnings'][] = 'site.permalink needs a % tag such as /%postname%/ (left unchanged).';
			}
		}

		$step( 'identity' );

		// 2. Brand.
		$brand = (array) ( $bp['brand'] ?? [] );
		if ( $brand ) {
			$args = [ 'palette' => (array) ( $brand['palette'] ?? [] ), 'fonts' => (array) ( $brand['fonts'] ?? [] ) ];
			if ( ! empty( $brand['logo_url'] ) ) {
				$have = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_lb_src', 'meta_value' => esc_url_raw( $brand['logo_url'] ), 'numberposts' => 1, 'fields' => 'ids' ] );
				if ( $have ) {
					$args['logo_attachment_id'] = (int) $have[0]; // Already imported on an earlier run.
				} else {
					$m = Abilities::sideload_media( [ 'url' => $brand['logo_url'], 'title' => 'Logo' ] );
					is_wp_error( $m ) ? $report['warnings'][] = 'logo: ' . $m->get_error_message() : $args['logo_attachment_id'] = $m['id'];
				}
			}
			$r = Design::set_brand( $args );
			is_wp_error( $r ) ? $report['warnings'][] = 'brand: ' . $r->get_error_message() : $report['brand'] = $r['changed'];
		}

		$step( 'brand' );

		// 2b. Design layer: block defaults, theme buttons, title banner / page layout. (The menu style runs after the header exists.)
		$design = (array) ( $bp['design'] ?? [] );
		if ( ! empty( $design['block_defaults'] ) ) { // Block defaults only when the blueprint asks.
			$r = Design::set_block_defaults( [ 'preset' => 'standard' ] );
			is_wp_error( $r ) ? $report['warnings'][] = 'block_defaults: ' . $r->get_error_message() : $report['design']['block_defaults'] = $r['blocks'];
		}
		if ( ! empty( $design['buttons'] ) ) {
			$r = Design::set_buttons( (array) $design['buttons'] );
			is_wp_error( $r ) ? $report['warnings'][] = 'design.buttons: ' . $r->get_error_message() : $report['design']['buttons'] = $r['changed'];
		}
		if ( ! empty( $design['page'] ) ) {
			$r = Design::set_page_style( is_string( $design['page'] ) ? [ 'preset' => $design['page'] ] : (array) $design['page'] );
			is_wp_error( $r ) ? $report['warnings'][] = 'design.page: ' . $r->get_error_message() : $report['design']['page'] = $r['changed'];
		}

		update_option( 'launch_builder_chrome', 'elements' === ( $bp['chrome'] ?? 'patterns' ) ? 'elements' : 'patterns' );
		$step( 'design' );
		// 2c. Services and service areas (post types): the live lists on the site read these.
		$ent_in = (array) ( $bp['entries'] ?? [] );
		foreach ( [ 'services' => 'add_service', 'areas' => 'add_area' ] as $ek => $efn ) {
			if ( ! empty( $ent_in[ $ek ] ) ) {
				// add-service/add-area take 100 items per call and return {count, remaining, next_offset, errors}.
				$off = 0;
				do {
					$er = Entries::$efn( [ 'items' => array_values( (array) $ent_in[ $ek ] ), 'offset' => $off ] );
					if ( is_wp_error( $er ) ) {
						$report['warnings'][] = 'entries.' . $ek . ': ' . $er->get_error_message();
						break;
					}
					$report['entries'][ $ek ] = ( $report['entries'][ $ek ] ?? 0 ) + (int) $er['count'];
					foreach ( (array) ( $er['errors'] ?? [] ) as $ee ) {
						$report['warnings'][] = 'entries.' . $ek . '[' . (int) $ee['index'] . ']: ' . $ee['error'];
					}
					$off = (int) ( $er['next_offset'] ?? 0 );
				} while ( $off > 0 );
			}
		}

		$step( 'entries' );

		// 3. Pages (pass 1: make sure each exists so links and the menu can resolve).
		$ids = [];
		foreach ( $pages_in as $pg ) {
			$pg = (array) $pg;
			$slug = sanitize_title( $pg['slug'] ?? ( $pg['title'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $ids[ $slug ] ) ) {
				$report['warnings'][] = 'page slug "' . $slug . '" appears twice in the blueprint; the second is ignored.';
				continue;
			}
			$existing = get_page_by_path( ( ! empty( $pg['parent'] ) ? sanitize_title( $pg['parent'] ) . '/' : '' ) . $slug, OBJECT, 'page' ); // child pages live under their parent's path
			if ( $existing && 'trash' !== $existing->post_status ) {
				$ids[ $slug ] = (int) $existing->ID;
				continue;
			}
			$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => sanitize_text_field( $pg['title'] ?? $slug ), 'post_name' => $slug ], true );
			if ( is_wp_error( $id ) ) {
				$report['warnings'][] = 'page ' . $slug . ': ' . $id->get_error_message();
				continue;
			}
			$ids[ $slug ] = (int) $id;
		}

		$step( 'pages_created' );

		// 4. Synced patterns + global areas.
		$synced = [];
		foreach ( (array) ( $bp['synced'] ?? [] ) as $key => $spec ) {
			$spec = (array) $spec;
			$r = self::set_synced( [ 'key' => $key, 'pattern' => $spec['pattern'] ?? '', 'sections' => $spec['sections'] ?? [], 'slots' => $spec['slots'] ?? [], 'replace' => $spec['replace'] ?? [], 'vars' => $vars, 'title' => $spec['title'] ?? null ] );
			if ( is_wp_error( $r ) ) {
				$report['warnings'][] = 'synced ' . $key . ': ' . $r->get_error_message();
				continue;
			}
			$synced[ sanitize_key( $key ) ] = $r['id'];
			array_map( static function ( $w ) use ( &$report, $key ) { $report['warnings'][] = "synced $key: $w"; }, $r['warnings'] );
		}
		foreach ( (array) ( $bp['areas'] ?? [] ) as $area => $key ) {
			if ( '' === (string) $key ) { // An empty key clears the area (for example when Elements place these instead).
				Globals::set( (string) $area, 0 );
				continue;
			}
			$bid = $synced[ sanitize_key( (string) $key ) ] ?? self::find_synced( sanitize_key( (string) $key ) );
			if ( ! $bid || ! Globals::set( (string) $area, $bid ) ) {
				$report['warnings'][] = "area $area: unknown area or synced key";
			}
		}

		// 4b. Theme sidebar: a synced pattern shown on every page whose layout has a sidebar.
		if ( ! empty( $bp['sidebar'] ) ) {
			$sb = is_array( $bp['sidebar'] ) ? $bp['sidebar'] : [ 'key' => $bp['sidebar'] ];
			$r = Design::set_sidebar( [ 'key' => $sb['key'] ?? ( $sb['synced'] ?? '' ), 'sidebar' => $sb['sidebar'] ?? 'sidebar-primary' ] );
			is_wp_error( $r ) ? $report['warnings'][] = 'sidebar: ' . $r->get_error_message() : $report['sidebar'] = $r['sidebar'];
		}

		$step( 'synced' );

		// 5. Pages (pass 2: content, SEO, layout, front page).
		$report['pages'] = [];
		foreach ( $pages_in as $pg ) {
			$pg = (array) $pg;
			$slug = sanitize_title( $pg['slug'] ?? ( $pg['title'] ?? '' ) );
			if ( ! isset( $ids[ $slug ] ) ) {
				continue;
			}
			$chunks = [];
			$sections = (array) ( $pg['sections'] ?? [] );
			$recipe = Content::recipes()[ sanitize_key( $pg['recipe'] ?? '' ) ] ?? null;
			if ( ! $sections && $recipe ) { // A recipe is only a starting set of sections; markdown fills its {markdown} slot.
				foreach ( $recipe['sections'] as $rs ) {
					if ( '{markdown}' === $rs ) {
						! empty( $pg['markdown'] ) ? $sections[] = [ 'markdown' => $pg['markdown'] ] : null;
					} else {
						$sections[] = 'service-body' === $rs && ! empty( $pg['markdown'] ) ? [ 'pattern' => $rs, 'markdown' => $pg['markdown'] ] : [ 'pattern' => $rs ];
					}
				}
			} elseif ( ! $sections && ! empty( $pg['markdown'] ) ) {
				$sections[] = [ 'markdown' => $pg['markdown'] ];
			}
			$page_used = [];
				$has_md = false;
			foreach ( $sections as $sec ) {
				$has_md = $has_md || ! empty( ( (array) $sec )['markdown'] );
			}
			// Page type: landing (pattern pages), text (bare text under the theme title banner) or wide (text + media patterns).
			$layout = is_array( $pg['layout'] ?? null ) ? $pg['layout'] : ( 'boxed' === ( $pg['layout'] ?? '' ) ? [ 'style' => 'boxed' ] : [] );
			$type = $pg['type'] ?? ( $recipe['type'] ?? ( ! empty( $pg['front'] ) ? 'landing' : ( $has_md ? ( count( $sections ) > 1 ? 'wide' : 'text' ) : 'landing' ) ) );
			$layout['type'] = isset( Content::TYPES[ $type ] ) ? $type : 'landing';
			if ( isset( $pg['transparent_header'] ) ) {
				$layout['transparent_header'] = (bool) $pg['transparent_header'];
			}
			foreach ( $sections as $sec ) {
				$sec = (array) $sec;
				if ( empty( $sec['pattern'] ) && ! empty( $sec['markdown'] ) ) { // a bare markdown section (a pattern section with markdown fills that pattern's text area)
					$chunks[] = Content::from_markdown( (string) $sec['markdown'], 'wide' === $layout['type'] );
					continue;
				}
				if ( ! empty( $sec['use'] ) ) {
					$bid = $synced[ sanitize_key( $sec['use'] ) ] ?? self::find_synced( sanitize_key( $sec['use'] ) );
					$bid ? $chunks[] = '<!-- wp:block {"ref":' . $bid . '} /-->' : $report['warnings'][] = "page $slug: unknown synced key " . $sec['use'];
					continue;
				}
				$b = Patterns::build_pattern( sanitize_key( $sec['pattern'] ?? '' ), (array) ( $sec['slots'] ?? [] ), array_merge( (array) ( $pg['replace'] ?? [] ), (array) ( $sec['replace'] ?? [] ) ), $vars, (string) ( $sec['markdown'] ?? '' ) );
				if ( is_wp_error( $b ) ) {
					$report['warnings'][] = "page $slug: " . $b->get_error_message();
					continue;
				}
				$chunks[] = $b['content'];
				$warn = $b['warnings'];
				foreach ( array_keys( (array) ( $pg['replace'] ?? [] ) ) as $pk ) { // A page-level replace is only an error if no section on the page used it.
					$at = array_search( 'No slot has the text: ' . mb_substr( (string) $pk, 0, 70 ), $warn, true );
					if ( false !== $at ) {
						unset( $warn[ $at ] );
					} else {
						$page_used[ $pk ] = true;
					}
				}
				array_map( static function ( $w ) use ( &$report, $slug, $sec ) { $report['warnings'][] = "page $slug ({$sec['pattern']}): $w"; }, $warn );
			}
			foreach ( array_diff( array_keys( (array) ( $pg['replace'] ?? [] ) ), array_keys( $page_used ) ) as $pk ) {
					$report['warnings'][] = "page $slug: replace text matched nothing on the page: " . mb_substr( (string) $pk, 0, 70 );
				}
				$args = [ 'ID' => $ids[ $slug ], 'post_title' => sanitize_text_field( $pg['title'] ?? $slug ), 'post_status' => in_array( $pg['status'] ?? 'publish', [ 'draft', 'publish', 'private' ], true ) ? $pg['status'] ?? 'publish' : 'publish' ];
			if ( $chunks ) {
				$args['post_content'] = wp_slash( implode( "\n\n", $chunks ) );
			}
			if ( ! empty( $pg['parent'] ) && isset( $ids[ sanitize_title( $pg['parent'] ) ] ) ) {
				$args['post_parent'] = $ids[ sanitize_title( $pg['parent'] ) ];
			}
			$upd = wp_update_post( $args, true );
			if ( is_wp_error( $upd ) ) {
				$report['warnings'][] = 'page ' . $slug . ': ' . $upd->get_error_message();
				continue;
			}
			Content::set_layout( $ids[ $slug ], $layout );
			Compat::write_seo_meta( $ids[ $slug ], (array) ( $pg['seo'] ?? [] ) ); // The active SEO plugin only; %variables% survive.
			if ( ! empty( $pg['front'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $ids[ $slug ] );
			}
			$report['pages'][] = [ 'slug' => $slug, 'id' => $ids[ $slug ], 'type' => $layout['type'], 'status' => get_post_status( $ids[ $slug ] ), 'url' => get_permalink( $ids[ $slug ] ), 'sections' => count( $chunks ) ];
		}

		$step( 'pages' );

		// 5c. Service categories point at their landing pages.
		foreach ( (array) ( $ent_in['categories'] ?? [] ) as $ec ) {
			$ec = (array) $ec;
			$eterm = get_term_by( 'slug', sanitize_title( $ec['slug'] ?? ( $ec['name'] ?? '' ) ), Entries::CAT );
			$epid = $ids[ sanitize_title( $ec['page'] ?? '' ) ] ?? 0;
			if ( $eterm && $epid ) {
				update_term_meta( $eterm->term_id, '_lb_page', $epid );
			}
		}

		$step( 'categories' );

		// 5a. Kadence Pro modules (take effect on the next request).
		if ( ! empty( $bp['pro_modules'] ) ) {
			$pm = self::set_pro_modules( [ 'modules' => (array) $bp['pro_modules'] ] );
			if ( is_wp_error( $pm ) ) {
				$report['warnings'][] = 'pro_modules: ' . $pm->get_error_message();
			} elseif ( $pm['changed'] ) {
				$report['rerun_needed'] = true;
				$report['warnings'][] = 'Kadence Pro modules enabled (' . implode( ', ', $pm['changed'] ) . '): run build-site again so elements and other module features can be applied (it is idempotent).';
			}
		}

		$step( 'pro_modules' );

		// 5b. Kadence Pro Elements (per-page placement of synced patterns); needs Theme Kit Pro.
		foreach ( (array) ( $bp['elements'] ?? [] ) as $ekey => $el ) {
			$el = (array) $el;
			$r = self::set_element( [ 'key' => $ekey, 'synced_key' => $el['synced'] ?? '', 'hook' => $el['hook'] ?? '', 'priority' => $el['priority'] ?? 10, 'pages' => $el['pages'] ?? [], 'hide_pages' => $el['hide_pages'] ?? [], 'post_types' => $el['post_types'] ?? [], 'hide_front' => ! empty( $el['hide_front'] ), 'pattern' => $el['pattern'] ?? '', 'sections' => $el['sections'] ?? [], 'slots' => $el['slots'] ?? [], 'replace' => $el['replace'] ?? [] ] );
			is_wp_error( $r ) ? $report['warnings'][] = 'element ' . $ekey . ': ' . $r->get_error_message() : $report['elements'][ $ekey ] = [ 'id' => $r['element_id'], 'hook' => $r['hook'] ];
		}

		$step( 'elements' );

		// 6. Header + menu (after pages exist so menu links resolve to real permalinks).
		$header = (array) ( $bp['header'] ?? [] );
		if ( ! empty( $header['pattern'] ) ) {
			$r = Nav::set_header( [
				'pattern' => $header['pattern'],
				'slots'   => $header['slots'] ?? [],
				'replace' => $header['replace'] ?? [],
				'vars'    => $vars,
				'nav'     => self::resolve_nav( (array) ( $header['nav'] ?? [] ), $ids, $report['warnings'] ),
			] );
			is_wp_error( $r ) ? $report['warnings'][] = 'header: ' . $r->get_error_message() : $report['header'] = [ 'id' => $r['header_id'], 'navigation_id' => $r['navigation_id'] ];
		}

		$step( 'header' );

		// 6b. Menu + dropdown design (needs the menu from step 6).
		if ( ! empty( $design['nav'] ) ) {
			$nav_design = (array) $design['nav'];
			$hn = (array) ( Plugin::pattern_extra( (string) ( $header['pattern'] ?? '' ) )['header_nav'] ?? [] );
			if ( ! empty( $hn['links'] ) ) { // The header's own menu colours win: they are what keeps it readable.
				$nav_design['links'] = array_merge( (array) ( $nav_design['links'] ?? [] ), (array) $hn['links'] );
			}
			$r = Design::set_nav_style( $nav_design );
			is_wp_error( $r ) ? $report['warnings'][] = 'design.nav: ' . $r->get_error_message() : $report['design']['nav'] = $r['menu_settings'];
		}

		$step( 'nav_design' );
		$step( 'done' );

		flush_rewrite_rules( false );
		$report['areas'] = Globals::map();
		$report['home'] = home_url( '/' );
		return $report;
	}
}
