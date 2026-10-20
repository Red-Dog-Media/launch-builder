<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Launch tools: go-live (final audit, then the site is switched on for search engines), detokenize (Business Info baked
 * into the content before the plugin is removed) and convert-page-to-entry (a page becomes a service or service area, its
 * old URL redirects). Every writer snapshots first.
 */
final class Launch {

	/** CSS class of the notice that says a generated legal text (sites built before v2) still has to be reviewed. */
	const REVIEW_CLASS = 'lb-review-notice';

	/** Progress of a go-live audit that needs more than one call. */
	const OPT_RUN = 'launch_builder_golive_run';

	/** When go-live last made the site public (a build never lowers indexing once this is set and the site is public). */
	const OPT_LIVE = 'launch_builder_live';

	/** Post meta copied from a page to the entry that replaces it. */
	const COPY_META = [ 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_canonical_url', 'rank_math_pillar_content', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_canonical', '_thumbnail_id' ];

	/** Meta keys that carry SEO text (detokenize scope seo). */
	const SEO_META = [ 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ];

	/** Post types detokenize reads, by scope. */
	const CONTENT_TYPES = [ 'page', 'post', 'lb_service', 'lb_area' ];
	const DESIGN_TYPES  = [ 'wp_block', 'kadence_element', 'kadence_header', 'kadence_navigation', 'kadence_form', 'kadence_conversions' ];

	public static function boot(): void {
		Audit::register_check( 'legal_review_pending', 'content', [ __CLASS__, 'audit_legal' ] );
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'go-live', 'Launch the site', 'Last step of a build. Runs the site audit (failures only) and REFUSES when it finds errors unless force is true; then makes the site visible to search engines (Settings > Reading), stops holding back Rank Math Instant Indexing submissions, refreshes the sitemap and purges the caches, and reports what it did, with one needs_a_human list (logo, site icon, photos, legal review, WordPress sample content, Rank Math setup). A long audit stops after about 25 seconds and says so: call again with the after_id it returns. scope narrows the audit (default everything). Snapshots the settings first.',
			[ 'properties' => [
				'force'    => [ 'type' => 'boolean', 'description' => 'Go live even though the audit reports errors.' ],
				'after_id' => [ 'type' => 'integer', 'description' => 'Continue a long audit here (the after_id of the previous result); 0 starts.' ],
				'scope'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Audit scopes to run (default all).' ],
			] ], [ __CLASS__, 'go_live' ], 'manage_options' );

		Abilities::add( 'detokenize', 'Bake Business Info into the content', 'Run this BEFORE Launch Builder is removed from a site. It replaces the dynamic tokens ({{lb.phone}}, {{site.name}}, #lb-cta_url links, [lb field="phone"] shortcodes) with their current values: scope content = pages, posts, services and areas (page, entry, area and service tokens resolve against the post itself), designs = synced patterns, elements, headers, menus and forms (only Business Info and site values; tokens that depend on the page being viewed stay and are listed), seo = the %lb_...% variables in page titles and descriptions, all = every one of them. Tokens whose Business Info value is still the demo value are left alone and listed. The result also lists what cannot be baked (blocks of Launch Builder before v2, which stop working without the plugin). prepare-removal runs the whole hand-off. dry_run (default true) writes nothing. Posts are snapshotted first; call again with next_offset until it is null.',
			[ 'properties' => [
				'scope'   => [ 'type' => 'string', 'enum' => [ 'content', 'designs', 'seo', 'all' ], 'description' => 'What to bake (default content).' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Default true: list what would change.' ],
				'offset'  => [ 'type' => 'integer', 'description' => 'Start here (next_offset of the previous call).' ],
				'limit'   => [ 'type' => 'integer', 'description' => 'Posts per call, 1-100 (default 40).' ],
			] ], [ __CLASS__, 'detokenize' ], 'manage_options' );

		Abilities::add( 'prepare-removal', 'Prepare to remove Launch Builder', 'Hands the site over to Kadence and Meta Box before the plugin is removed. Reports, and with dry_run false does: Meta Box holds the services, areas, their fields and Business Info (sync-meta-box); Business Info tokens and other tokens are baked into the content and the designs (detokenize scope all, first batch; call detokenize with next_offset for the rest). Returns the hand-off pack: the services\' root URLs mapped to the URLs WordPress will use without the plugin (url_map, plus .htaccess lines to paste after removal; add them before deactivating only as Rank Math redirections, never while Launch Builder runs), and lists_snippet: a small mu-plugin that keeps the "children of this page" lists working.',
			[ 'properties' => [
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Report only (default true).' ],
			] ], [ __CLASS__, 'prepare_removal' ], 'manage_options' );

		Abilities::add( 'convert-page-to-entry', 'Turn a page into a service or service area', 'Moves a page into a service or service area entry: its content, excerpt (summary), SEO fields, featured image and slug go to the new entry (so every list and menu picks it up), the old page becomes a draft and its old URL gets a 301 redirect to the entry. Not for the front page, the posts page or hub pages. parent: the service (for a sub-service) or area it goes under (id, slug or path). dry_run returns the plan without writing. Snapshots first.',
			[ 'properties' => [
				'post_id'    => [ 'type' => 'integer', 'description' => 'The page to convert.' ],
				'type'       => [ 'type' => 'string', 'enum' => [ 'service', 'area' ] ],
				'parent'     => [ 'type' => 'string', 'description' => 'The service or area it goes under: id, slug or path.' ],
				'dry_run'    => [ 'type' => 'boolean' ],
			], 'required' => [ 'post_id', 'type' ] ], [ __CLASS__, 'convert_page' ], 'manage_options' );

	}

	/** get-playbook: how to launch. */
	public static function playbook( array &$pb ): void {
		$pb['launch'] = [
			'go_live'       => 'go-live runs the audit and refuses on errors (force overrides), then sets the site public, refreshes the sitemap and purges caches, and lists what only a person can do (needs_a_human). Once a site is live a build never hides it again, whatever launch.indexing says. Until then Rank Math Instant Indexing submissions are held back while a build is running, while Settings > Reading discourages search engines and when the brief says launch.indexing false.',
			'before_removal' => 'prepare-removal hands the site over to Kadence and Meta Box before the plugin is removed (dry run first): tokens baked into the content (detokenize), redirects for the service URLs, and the two snippets that keep root service URLs and "children of this page" lists working. convert-page-to-entry turns an old page into a service or area entry and redirects its URL.',
		];
		$pb['tools_by_job']['Launch'] = 'go-live, prepare-removal, detokenize, convert-page-to-entry';
	}

	/* ------------------------------------------------------------------ go-live */

	/** go-live. */
	public static function go_live( $input = [] ) {
		$input = (array) $input;
		$force = ! empty( $input['force'] );
		$after = max( 0, (int) ( $input['after_id'] ?? 0 ) );
		$scope = [];
		if ( isset( $input['scope'] ) ) {
			if ( ! is_array( $input['scope'] ) || array_filter( $input['scope'], static function ( $v ) { return ! is_string( $v ); } ) ) {
				return new \WP_Error( 'lb_invalid_args', 'scope must be a list of strings.', [ 'status' => 400 ] );
			}
			$scope = array_values( array_filter( array_map( 'sanitize_key', $input['scope'] ) ) );
		}
		if ( ! Lock::acquire( 'build', 300 ) ) {
			return new \WP_Error( 'lb_busy', 'A build or launch is running; try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			$run = get_option( self::OPT_RUN, [] );
			$run = is_array( $run ) ? $run : [];
			if ( 0 === $after || (int) ( $run['next_after_id'] ?? -1 ) !== $after ) {
				if ( $after > 0 ) {
					return new \WP_Error( 'lb_golive_cursor', 'after_id is not where the last audit stopped; call go-live without after_id to start again.', [ 'status' => 409 ] );
				}
				$run = [ 'started' => gmdate( 'c' ), 'scope' => $scope, 'pages' => 0, 'totals' => [ 'error' => 0, 'warning' => 0, 'info' => 0 ], 'by_code' => [], 'errors' => [], 'next_after_id' => 0 ];
			}
			$deadline = microtime( true ) + 25.0;
			do {
				$args = [ 'only_failures' => true, 'after_id' => $after, 'limit' => 50, 'max_findings' => 200 ];
				if ( $scope ) {
					$args['scope'] = $scope;
				}
				$r = Audit::run_cached( $args );
				$run['pages']++;
				foreach ( (array) $r['totals'] as $sev => $n ) {
					$run['totals'][ $sev ] = ( $run['totals'][ $sev ] ?? 0 ) + (int) $n;
				}
				foreach ( (array) $r['by_code'] as $code => $n ) {
					$run['by_code'][ $code ] = ( $run['by_code'][ $code ] ?? 0 ) + (int) $n;
				}
				foreach ( (array) $r['findings'] as $f ) {
					if ( 'error' === ( $f['severity'] ?? '' ) && count( $run['errors'] ) < 60 ) {
						$run['errors'][] = [ 'code' => (string) ( $f['code'] ?? '' ), 'title' => (string) ( $f['title'] ?? '' ), 'id' => $f['id'] ?? null, 'url' => (string) ( $f['url'] ?? '' ), 'message' => mb_substr( (string) ( $f['message'] ?? '' ), 0, 220 ), 'fix' => mb_substr( (string) ( $f['fix'] ?? '' ), 0, 160 ) ];
					}
				}
				$after = null === $r['next_after_id'] ? null : (int) $r['next_after_id'];
				$run['next_after_id'] = $after;
			} while ( null !== $after && microtime( true ) < $deadline );
			update_option( self::OPT_RUN, $run, false );
			if ( null !== $after ) {
				return [ 'live' => false, 'done' => false, 'after_id' => $after, 'audited_pages' => $run['pages'], 'totals' => $run['totals'], 'note' => 'The audit is not finished: call go-live again with after_id ' . $after . '.' ];
			}
			delete_option( self::OPT_RUN );
			$render = self::rendered_check(); // The front end as a visitor gets it, not only what is stored.
			$run['errors'] = array_merge( (array) $run['errors'], $render['problems'] );
			$errors = (int) $run['totals']['error'] + count( $render['problems'] );
			$report = [ 'live' => false, 'done' => true, 'forced' => false, 'audit' => [ 'totals' => $run['totals'], 'by_code' => $run['by_code'], 'pages' => $run['pages'] ], 'rendered' => [ 'pages_checked' => $render['checked'], 'problems' => count( $render['problems'] ) ], 'needs_a_human' => self::needs_human() ];
			if ( $errors > 0 && ! $force ) {
				$report['refused'] = true;
				$report['errors'] = $run['errors'];
				$report['next'] = 'Fix the errors (each says how), then call go-live again. force: true launches anyway.';
				return $report;
			}
			$report['forced'] = $errors > 0;
			$was = (string) get_option( 'blog_public', '1' );
			$report['snapshot'] = Abilities::snapshot( 'go-live', [], [ 'blog_public' ] );
			update_option( 'blog_public', '1' );
			update_option( self::OPT_LIVE, gmdate( 'c' ), false );
			flush_rewrite_rules( false );
			Cache::purge_later();
			Cache::flush(); // Purges the page caches and Rank Math's sitemap cache now, not at the end of the request.
			$rm = defined( 'RANK_MATH_VERSION' );
			$held = get_option( Compat::OPT_INDEXNOW, [] );
			$report['live'] = true;
			$report['indexing'] = [ 'before' => '1' === $was ? 'public' : 'discouraged', 'now' => 'public' ];
			$report['sitemap'] = $rm ? home_url( '/sitemap_index.xml' ) : home_url( '/wp-sitemap.xml' );
			$report['instant_indexing_held_back'] = [ 'count' => (int) ( is_array( $held ) ? ( $held['count'] ?? 0 ) : 0 ), 'last_url' => is_array( $held ) ? (string) ( $held['last_url'] ?? '' ) : '', 'note' => 'Submissions Rank Math Instant Indexing would have sent while the site was being built or discouraged.' ];
			$report['next'] = 'Submit the sitemap in Google Search Console and Bing Webmaster Tools, check the site as a visitor' . ( $report['needs_a_human'] ? ', and give the owner the needs_a_human list.' : '.' );
			return $report;
		} finally {
			Lock::release( 'build' );
		}
	}

	/* ------------------------------------------------------------------ state */

	/**
	 * Has go-live made the site public (and is it still public)? A build never lowers indexing on a live site.
	 *
	 * A site that went live before the live date was kept has no such option; its go-live snapshot says the same, so the date
	 * is taken from there (once). A public setting alone proves nothing: a new WordPress site is public until it is told otherwise.
	 */
	public static function is_live(): bool {
		if ( '1' !== (string) get_option( 'blog_public', '1' ) ) {
			return false;
		}
		if ( '' !== (string) get_option( self::OPT_LIVE, '' ) ) {
			return true;
		}
		foreach ( Abilities::snapshots() as $s ) {
			if ( 'go-live' === (string) ( $s['label'] ?? '' ) ) {
				update_option( self::OPT_LIVE, '' !== (string) ( $s['time'] ?? '' ) ? (string) $s['time'] : gmdate( 'c' ), false );
				return true;
			}
		}
		return false;
	}

	/** Is this post still the sample page or the first post WordPress made, untouched? */
	public static function is_wp_default( \WP_Post $p ): bool {
		$sample = 'page' === $p->post_type && 'sample-page' === $p->post_name && 'Sample Page' === $p->post_title && false !== strpos( (string) $p->post_content, 'This is an example page' );
		$hello = 'post' === $p->post_type && 'hello-world' === $p->post_name && 'Hello world!' === $p->post_title && false !== strpos( (string) $p->post_content, 'Welcome to WordPress' );
		if ( ! $sample && ! $hello ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $p->ID, '_lb_key', true ) || (int) get_option( 'page_on_front' ) === (int) $p->ID || (int) get_option( 'page_for_posts' ) === (int) $p->ID ) {
			return false; // A build or an admin put it to use.
		}
		return abs( strtotime( (string) $p->post_modified_gmt ) - strtotime( (string) $p->post_date_gmt ) ) <= 120; // Saving it again moves the modified date.
	}

	/** The sample page and the first post when they are still untouched and not in the trash. @return \WP_Post[] */
	public static function wordpress_defaults(): array {
		$out = [];
		foreach ( [ [ 'page', 'sample-page' ], [ 'post', 'hello-world' ] ] as [ $type, $slug ] ) {
			$hit = get_posts( [ 'post_type' => $type, 'name' => $slug, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => 1, 'suppress_filters' => true ] );
			if ( $hit && self::is_wp_default( $hit[0] ) ) {
				$out[] = $hit[0];
			}
		}
		return $out;
	}

	/** Is a form embed a stand-in (REPLACE_WITH_FORM_ID, YOUR_FORM_ID, FORM_ID_HERE, <FORM_ID>, an .example host...)? A real embed's own form id (data-form-id="abc", formId: "abc") is not one. */
	public static function placeholder_embed( string $html ): bool {
		return '' !== trim( $html ) && (bool) preg_match( '/REPLACE[_-]?WITH|YOUR[_-]?(FORM|ID)|FORM[_-]?ID[_-]?HERE|[<\[{]\s*FORM[_-]?ID\s*[>\]}]|\.(example|invalid|test|localhost)(?![a-z0-9-])|example\.(com|org|net)/i', $html );
	}

	/** How many pages, entries and shared designs still hold a placeholder picture. */
	public static function placeholder_pictures(): int {
		global $wpdb;
		$types = array_values( array_filter( [ 'page', Entries::SERVICE, Entries::AREA, 'wp_block', 'kadence_element', 'kadence_header' ], 'post_type_exists' ) );
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($ph) AND post_status IN ('publish','draft','private') AND post_content LIKE %s", array_merge( $types, [ '%assets/placeholders/%' ] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Everything only a person can do before the site is ready, as one list: [ { id, text, where } ]. The build report and
	 * go-live both carry it, so the assistant does not have to piece it together from findings.
	 *
	 * @return array<int,array{id:string,text:string,where:string}>
	 */
	public static function needs_human(): array {
		global $wpdb;
		$out = [];
		$add = static function ( string $id, string $text, string $where ) use ( &$out ) {
			$out[] = [ 'id' => $id, 'text' => $text, 'where' => $where ];
		};
		if ( ! (int) get_theme_mod( 'custom_logo' ) ) {
			$add( 'logo', 'The logo is not set.', 'Appearance, Customize, Site identity (or set-brand logo_attachment_id).' );
		}
		if ( ! (int) get_option( 'site_icon' ) ) {
			$add( 'site_icon', 'The site icon is not set.', 'Appearance, Customize, Site identity.' );
		}
		$pics = self::placeholder_pictures();
		if ( $pics ) {
			$add( 'photos', $pics . ' page(s) or design(s) still show grey placeholder pictures. Real photos need the client\'s own pictures.', 'sideload-media, then set the picture in the page, the service or area (featured image), or the design in Kadence.' );
		}
		$review = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s", '%' . $wpdb->esc_like( self::REVIEW_CLASS ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $review ) {
			$add( 'legal', $review . ' legal page(s) (privacy, terms, accessibility) are generated text that the client must read before they go live.', 'Pages, the legal pages: read them, delete the review notice block, then publish. Until then the footer links lead nowhere for visitors.' );
		}
		$samples = self::wordpress_defaults();
		if ( $samples ) {
			$add( 'wordpress_samples', implode( ' and ', array_map( static function ( $p ) { return '"' . $p->post_title . '"'; }, $samples ) ) . ' from WordPress ' . ( 1 === count( $samples ) ? 'is' : 'are' ) . ' still published and listed in the sitemap.', 'Trash them (a build does it on its first run), or keep them on purpose.' );
		}
		if ( Compat::rm_active() && ! Compat::rm_ready() ) {
			$add( 'rank_math_wizard', 'The Rank Math setup wizard is not finished, so Rank Math prints no titles, descriptions, schema or sitemap yet.', 'wp-admin, Rank Math: connect a free account or choose "Skip". An assistant cannot do this step.' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ legal texts */

	/** Audit check legal_review_pending: generated legal texts whose review notice is still in the page. */
	public static function audit_legal( $args = [] ): array {
		unset( $args );
		global $wpdb;
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY ID ASC LIMIT 20", '%' . $wpdb->esc_like( self::REVIEW_CLASS ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = [];
		foreach ( $ids as $id ) {
			$p = get_post( (int) $id );
			if ( ! $p ) {
				continue;
			}
			$live = in_array( $p->post_status, Audit::LIVE, true );
			$out[] = Audit::post_finding( $p, $live ? 'error' : 'warning', 'legal_review_pending', $live ? 'A generated legal text is live and still carries the "client must review" notice.' : 'A generated legal text is waiting for the client to review it (the page stays a draft).', 'Have the business owner read it, delete the review notice block, then publish the page.' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ prepare-removal */

	/** prepare-removal: what keeps working without Launch Builder, and the hand-off pack for what needs it. */
	public static function prepare_removal( $input = [] ) {
		$input = (array) $input;
		$dry = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$out = [ 'dry_run' => $dry ];

		// 1. Meta Box holds the types, the fields and Business Info.
		if ( defined( 'RWMB_VER' ) ) {
			$mb = Meta_Box_Sync::run( [ 'dry_run' => $dry ] );
			$out['meta_box'] = is_wp_error( $mb ) ? $mb->get_error_message() : array_map( static function ( $o ) { return $o['state'] . ( 'none' !== ( $o['action'] ?? 'none' ) ? ' (' . $o['action'] . ')' : '' ); }, $mb['objects'] );
		} else {
			$out['meta_box'] = 'Meta Box is not active: without Launch Builder the services, areas and Business Info would have no screen (their data stays in the database). Activate Meta Box AIO and run this again.';
		}

		// 2. Tokens baked in.
		$dt = self::detokenize( [ 'scope' => 'all', 'dry_run' => $dry ] );
		$out['detokenize'] = is_wp_error( $dt ) ? $dt->get_error_message() : array_intersect_key( $dt, array_flip( [ 'totals', 'next_offset', 'skipped_demo', 'dynamic' ] ) );

		// 3. Root URLs of services: WordPress alone serves them under the post type base.
		$map = [];
		foreach ( get_posts( [ 'post_type' => Entries::SERVICE, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'ID', 'order' => 'ASC' ] ) as $p ) {
			$now = Permalinks::current_uri( 'post', (int) $p->ID );
			$native = Permalinks::native_path( 'post', (int) $p->ID );
			if ( null !== $now && null !== $native && trim( $now, '/' ) !== trim( $native, '/' ) ) {
				$map[] = [ 'from' => '/' . trim( $now, '/' ) . '/', 'to' => '/' . trim( $native, '/' ) . '/' ];
			}
		}
		$out['url_map'] = $map;
		$out['htaccess'] = $map ? "# Services moved from the root of the site (Launch Builder removed)\n" . implode( "\n", array_map( static function ( $m ) { return 'Redirect 301 ' . $m['from'] . ' ' . home_url( $m['to'] ); }, $map ) ) : '';

		// 4. Lists: the "children of this page" filter as a mu-plugin.
		$out['lists'] = count( array_filter( Query_Lists::made(), static function ( $id ) { return 'kadence_query' === get_post_type( $id ); } ) ) . ' list(s) use Launch Builder\'s "children of this page" setting.';
		$out['lists_snippet'] = Query_Lists::snippet();
		$out['next'] = $dry ? 'Run again with dry_run false to move everything into Meta Box and bake the tokens.' : 'Save lists_snippet as wp-content/mu-plugins/service-lists.php, deactivate Launch Builder, then add the htaccess lines (or the same redirects in Rank Math).';
		return $out;
	}

	/* ------------------------------------------------------------------ convert-page-to-entry */

	/** convert-page-to-entry. */
	public static function convert_page( $input ) {
		$input = (array) $input;
		$id = (int) ( $input['post_id'] ?? 0 );
		$type = (string) ( $input['type'] ?? '' );
		$dry = ! empty( $input['dry_run'] );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( ! $page || 'page' !== $page->post_type || in_array( $page->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return new \WP_Error( 'lb_no_page', 'No such page.', [ 'status' => 404 ] );
		}
		if ( ! in_array( $type, [ 'service', 'area' ], true ) ) {
			return new \WP_Error( 'lb_type', 'type must be service or area.', [ 'status' => 400 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that page.', [ 'status' => 403 ] );
		}
		if ( isset( $input['parent'] ) && ! is_string( $input['parent'] ) ) {
			return new \WP_Error( 'lb_invalid_args', 'parent must be a string.', [ 'status' => 400 ] );
		}
		$why = Roles::explain_page( $id )[1];
		if ( in_array( $why, [ 'front_page', 'posts_page', 'hub' ], true ) ) {
			return new \WP_Error( 'lb_page_role', 'That page is the ' . ( [ 'front_page' => 'front page', 'posts_page' => 'posts page', 'hub' => 'services or areas hub' ][ $why ] ) . ': give that role to another page first (set-page-role), then convert it.', [ 'status' => 409 ] );
		}
		$pt = 'area' === $type ? Entries::AREA : Entries::SERVICE;
		$slug = (string) $page->post_name;
		$clash = get_posts( [ 'post_type' => $pt, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
		$key = 'converted:page:' . $id;
		if ( $clash && Entries::find_by_key( $pt, $key ) !== (int) $clash[0] ) {
			return new \WP_Error( 'lb_slug_taken', 'A ' . $type . ' with the slug "' . $slug . '" already exists (#' . (int) $clash[0] . ').', [ 'status' => 409 ] );
		}
		$old_path = Permalinks::local_path( (string) get_permalink( $id ) );
		$copy = [];
		foreach ( self::COPY_META as $mk ) {
			if ( metadata_exists( 'post', $id, $mk ) ) {
				$copy[] = $mk;
			}
		}
		$children = count( get_children( [ 'post_parent' => $id, 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'private', 'pending', 'future' ], 'numberposts' => 5, 'fields' => 'ids' ] ) );
		$plan = [
			'page'      => [ 'id' => $id, 'title' => $page->post_title, 'status' => $page->post_status, 'path' => $old_path ],
			'entry'     => [ 'type' => $type, 'slug' => $slug, 'status' => 'publish' === $page->post_status ? 'publish' : 'draft', 'moves' => array_merge( [ 'content', 'title', 'slug', 'excerpt' ], $copy ) ],
			'redirect'  => 'publish' === $page->post_status && $old_path ? [ 'from' => '/' . $old_path, 'to' => 'the new entry', 'code' => 301 ] : null,
			'old_page'  => 'becomes a draft (kept as a backup)',
			'warnings'  => $children ? [ 'The page has child pages; they stay where they are.' ] : [],
		];
		if ( $dry ) {
			return [ 'dry_run' => true ] + $plan;
		}
		$snap = Abilities::snapshot( 'convert-page-to-entry', [], [ Roles::OPT_HUBS ], [ $id ] );
		$entry_in = [
			'title'   => $page->post_title,
			'slug'    => $slug,
			'key'     => $key,
			'order'   => (int) $page->menu_order,
			'status'  => $plan['entry']['status'],
			'summary' => (string) $page->post_excerpt,
		];
		if ( isset( $input['parent'] ) && '' !== trim( (string) $input['parent'] ) ) {
			$entry_in['parent'] = trim( (string) $input['parent'] );
		}
		$r = 'area' === $type ? Entries::add_area( $entry_in ) : Entries::add_service( $entry_in );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$eid = (int) ( $r['entries'][0]['id'] ?? 0 );
		if ( ! $eid ) {
			return new \WP_Error( 'lb_convert', 'The entry could not be created: ' . wp_json_encode( $r['errors'] ?? [] ), [ 'status' => 500 ] );
		}
		$u = wp_update_post( [ 'ID' => $eid, 'post_content' => wp_slash( (string) $page->post_content ) ], true );
		if ( is_wp_error( $u ) ) {
			return $u;
		}
		foreach ( $copy as $mk ) {
			$v = get_post_meta( $id, $mk, true );
			update_post_meta( $eid, $mk, is_string( $v ) ? wp_slash( $v ) : $v );
		}
		update_post_meta( $eid, '_lb_converted_from', $id );
		update_post_meta( $id, '_lb_converted_to', $eid );
		$redirect = null;
		if ( 'publish' === $page->post_status ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] ); // The old URL must not lead to a live page for the redirect to apply.
			if ( $old_path ) {
				$redirect = Redirects::add( $old_path, 'post:' . $eid, 301, 'manual', false, '', true );
				$redirect = is_wp_error( $redirect ) ? [ 'error' => $redirect->get_error_message() ] : [ 'from' => $redirect['from'], 'to' => $redirect['url'], 'code' => 301, 'store' => $redirect['store'] ];
			}
		} else {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
		}
		$menus = Nav::flush();
		Cache::purge_later();
		return [
			'dry_run'      => false,
			'entry'        => [ 'id' => $eid, 'type' => $type, 'url' => get_permalink( $eid ), 'status' => get_post_status( $eid ) ],
			'page'         => [ 'id' => $id, 'status' => 'draft' ],
			'redirect'     => $redirect,
			'menus_updated' => $menus,
			'snapshot'     => $snap,
			'warnings'     => $plan['warnings'],
			'note'         => 'Menu links that point at the old page URL keep working through the redirect; change them to the entry (or use auto items).',
		];
	}

	/* ------------------------------------------------------------------ detokenize */

	/**
	 * The pages a visitor sees, fetched and read for unresolved tokens and copy placeholders: the home page and the first
	 * published page, service and service area. A page that cannot be fetched is skipped (the audit covers what is stored).
	 *
	 * @return array{checked:int,problems:array<int,array>}
	 */
	private static function rendered_check(): array {
		$urls = [ home_url( '/' ) ];
		foreach ( [ 'page', Entries::SERVICE, Entries::AREA ] as $pt ) {
			$ids = get_posts( [ 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] );
			if ( $ids ) {
				$urls[] = (string) get_permalink( (int) $ids[0] );
			}
		}
		$problems = [];
		$checked = 0;
		foreach ( array_values( array_unique( array_filter( $urls ) ) ) as $u ) {
			$o = Inspect::inspect( $u, [ 'check_links' => false ] );
			if ( ! is_array( $o ) || empty( $o['text'] ) ) {
				continue;
			}
			$checked++;
			$tok = (array) ( $o['text']['tokens'] ?? [] );
			$ph = (array) ( $o['text']['placeholders'] ?? [] );
			if ( $tok ) {
				$problems[] = [ 'code' => 'rendered_token', 'title' => '', 'id' => null, 'url' => $u, 'message' => 'Unresolved tokens are printed on this page: ' . implode( ', ', array_slice( $tok, 0, 5 ) ) ];
			}
			if ( $ph ) {
				$problems[] = [ 'code' => 'rendered_placeholder', 'title' => '', 'id' => null, 'url' => $u, 'message' => 'Copy placeholders are printed on this page: ' . implode( ', ', array_slice( $ph, 0, 5 ) ) ];
			}
		}
		return [ 'checked' => $checked, 'problems' => $problems ];
	}

	/** detokenize. */
	public static function detokenize( $input = [] ) {
		$input = (array) $input;
		$scope = (string) ( $input['scope'] ?? 'content' );
		if ( ! in_array( $scope, [ 'content', 'designs', 'seo', 'all' ], true ) ) {
			return new \WP_Error( 'lb_scope', 'scope must be content, designs, seo or all.', [ 'status' => 400 ] );
		}
		$dry = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 40 ) ) );
		$types = [];
		if ( in_array( $scope, [ 'content', 'seo', 'all' ], true ) ) {
			$types = array_merge( $types, self::CONTENT_TYPES );
		}
		if ( in_array( $scope, [ 'designs', 'all' ], true ) ) {
			$types = array_merge( $types, self::DESIGN_TYPES );
		}
		$types = array_values( array_filter( array_unique( $types ), 'post_type_exists' ) );
		$do_content = in_array( $scope, [ 'content', 'designs', 'all' ], true );
		$do_seo = in_array( $scope, [ 'seo', 'all' ], true );
		$q = new \WP_Query( [ 'post_type' => $types, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false, 'ignore_sticky_posts' => true, 'suppress_filters' => true ] );
		$total = (int) $q->found_posts;
		$demo = self::demo_keys();
		$items = [];
		$totals = [ 'scanned' => 0, 'changed' => 0, 'tokens' => 0, 'seo_fields' => 0 ];
		$dynamic = [ 'blocks' => [], 'tokens' => [] ];
		$skipped_demo = [];
		$snap_ids = [];
		$plans = [];
		$uid = get_current_user_id();
		wp_set_current_user( 0 ); // Tokens are baked as a visitor sees them: no editor markers for empty values.
		try {
		foreach ( $q->posts as $p ) {
			$totals['scanned']++;
			$is_design = in_array( $p->post_type, self::DESIGN_TYPES, true );
			$plan = [ 'id' => (int) $p->ID, 'type' => $p->post_type, 'title' => get_the_title( $p ), 'tokens' => 0, 'seo' => [], 'new_content' => null, 'new_meta' => [] ];
			if ( $do_content && ( $is_design || in_array( $p->post_type, self::CONTENT_TYPES, true ) ) ) {
				$res = self::bake_post( $p, $is_design, $demo );
				$plan['tokens'] = $res['n'];
				if ( null !== $res['content'] && $res['content'] !== $p->post_content ) {
					$plan['new_content'] = $res['content'];
				}
				foreach ( $res['dynamic_blocks'] as $b => $n ) {
					$dynamic['blocks'][ $b ] = ( $dynamic['blocks'][ $b ] ?? 0 ) + $n;
				}
				foreach ( $res['left'] as $t => $n ) {
					$dynamic['tokens'][ $t ] = ( $dynamic['tokens'][ $t ] ?? 0 ) + $n;
				}
				foreach ( $res['demo'] as $k ) {
					$skipped_demo[ $k ] = ( $skipped_demo[ $k ] ?? 0 ) + 1;
				}
			}
			if ( $do_seo && ! $is_design ) {
				foreach ( self::SEO_META as $mk ) {
					$v = get_post_meta( $p->ID, $mk, true );
					if ( is_string( $v ) && '' !== $v && ( false !== strpos( $v, 'lb_' ) || false !== strpos( $v, '{{' ) ) ) {
						$nv = self::bake_seo( $v, $p );
						if ( $nv !== $v ) {
							$plan['new_meta'][ $mk ] = $nv;
							$plan['seo'][] = $mk;
						}
					}
				}
			}
			if ( null !== $plan['new_content'] || $plan['new_meta'] ) {
				$totals['changed']++;
				$totals['tokens'] += $plan['tokens'];
				$totals['seo_fields'] += count( $plan['new_meta'] );
				$snap_ids[] = (int) $p->ID;
				$plans[] = $plan;
				if ( count( $items ) < 100 ) {
					$items[] = [ 'id' => $plan['id'], 'type' => $plan['type'], 'title' => $plan['title'], 'tokens_baked' => $plan['tokens'], 'seo_fields' => $plan['seo'] ];
				}
			}
		}
		} finally {
			wp_set_current_user( $uid );
		}
		$snapshot = null;
		if ( ! $dry && $plans ) {
			$snapshot = Abilities::snapshot( 'detokenize', [], [], $snap_ids );
			foreach ( $plans as $plan ) {
				$args = [ 'ID' => $plan['id'] ];
				if ( null !== $plan['new_content'] ) {
					$args['post_content'] = wp_slash( $plan['new_content'] );
				}
				if ( count( $args ) > 1 ) {
					wp_update_post( $args );
				}
				foreach ( $plan['new_meta'] as $mk => $nv ) {
					update_post_meta( $plan['id'], $mk, wp_slash( $nv ) );
				}
			}
			Cache::purge_later();
		}
		$next = $offset + count( $q->posts ) < $total ? $offset + count( $q->posts ) : null;
		arsort( $dynamic['blocks'] );
		arsort( $dynamic['tokens'] );
		return [
			'dry_run'     => $dry,
			'scope'       => $scope,
			'totals'      => $totals + [ 'posts_in_scope' => $total ],
			'items'       => $items,
			'remaining'   => [
				'blocks_that_need_the_plugin' => $dynamic['blocks'],
				'tokens_left_as_they_are'     => $dynamic['tokens'],
				'demo_values_not_baked'       => $skipped_demo,
			],
			'snapshot'    => $snapshot,
			'next_offset' => $next,
			'note'        => $dry ? 'Nothing was written. Call again with dry_run false to bake these.' : ( $next ? 'Call again with offset ' . $next . '.' : 'Done for this scope.' ),
		];
	}

	/** Business Info keys whose current value is the demo value (they are never baked). */
	private static function demo_keys(): array {
		$out = [];
		if ( ! Company::setup_mode() ) {
			return $out; // A public site prints nothing for a blank field, so that is what gets baked (or its fallback).
		}
		foreach ( Company::resolve() as $k => $row ) {
			if ( 'default' === ( $row['from'] ?? '' ) ) {
				$out[] = (string) $k;
			}
		}
		return $out;
	}

	/**
	 * One post's content with its tokens baked.
	 *
	 * @param bool     $restricted Designs: only Business Info and site tokens (the rest depends on the page being viewed).
	 * @param string[] $demo       Business Info keys on their demo value.
	 * @return array{content:?string,n:int,left:array,dynamic_blocks:array,demo:string[]}
	 */
	private static function bake_post( \WP_Post $p, bool $restricted, array $demo ): array {
		$raw = (string) $p->post_content;
		$out = [ 'content' => null, 'n' => 0, 'left' => [], 'dynamic_blocks' => [], 'demo' => [] ];
		if ( false !== strpos( $raw, 'wp:launch/' ) && preg_match_all( '/<!-- wp:(launch\/[a-z0-9-]+)/', $raw, $m ) ) {
			$out['dynamic_blocks'] = array_count_values( $m[1] );
		}
		$hit = false !== strpos( $raw, '{{' ) || false !== stripos( $raw, '%7B%7B' ) || false !== strpos( $raw, '#lb-' ) || false !== strpos( $raw, '[lb ' ) || false !== strpos( $raw, '#entry-url' ) || false !== strpos( $raw, '#page-' ) || false !== strpos( $raw, '#area-url' ) || false !== strpos( $raw, '#service-' ) || false !== strpos( $raw, '#site-url' );
		if ( ! $hit ) {
			return $out;
		}
		$before = count( Audit::tokens_in( $raw ) );
		$sim = false;
		if ( ! $restricted ) {
			Tokens::push( $p );
			$sim = Context::simulate( Roles::of( $p->ID ), $p );
		}
		try {
			$blocks = parse_blocks( $raw );
			$out['demo'] = [];
			$blocks = self::bake_blocks( $blocks, $restricted, $demo, $out['demo'] );
			$new = serialize_blocks( $blocks );
		} finally {
			if ( $sim ) {
				Context::clear();
			}
			if ( ! $restricted ) {
				Tokens::pop();
			}
		}
		$out['demo'] = array_values( array_unique( $out['demo'] ) );
		$left = Audit::tokens_in( $new );
		foreach ( $left as $raw_token => $key ) {
			$k = null === $key ? $raw_token : $key;
			$out['left'][ $k ] = ( $out['left'][ $k ] ?? 0 ) + 1;
		}
		$shortcodes = (int) preg_match_all( '/\[lb\s+field=/i', $raw ) - (int) preg_match_all( '/\[lb\s+field=/i', $new );
		$out['n'] = max( 0, $before - count( $left ) ) + max( 0, $shortcodes );
		$out['content'] = $out['n'] > 0 ? $new : null; // Nothing baked: the saved markup is left exactly as it is.
		return $out;
	}

	/** Bake every block of a tree (attributes and saved HTML). */
	private static function bake_blocks( array $blocks, bool $restricted, array $demo, array &$demo_hit ): array {
		foreach ( $blocks as &$b ) {
			if ( empty( $b['blockName'] ) ) {
				if ( isset( $b['innerHTML'] ) && is_string( $b['innerHTML'] ) ) {
					$b['innerHTML'] = self::bake_string( $b['innerHTML'], 'html', $restricted, $demo, $demo_hit );
				}
				if ( ! empty( $b['innerContent'] ) ) {
					$b['innerContent'] = array_map( function ( $c ) use ( $restricted, $demo, &$demo_hit ) { return is_string( $c ) ? self::bake_string( $c, 'html', $restricted, $demo, $demo_hit ) : $c; }, $b['innerContent'] );
				}
				continue;
			}
			if ( ! empty( $b['attrs'] ) && is_array( $b['attrs'] ) ) {
				$b['attrs'] = self::bake_attrs( $b['attrs'], $restricted, $demo, $demo_hit );
			}
			if ( isset( $b['innerHTML'] ) && is_string( $b['innerHTML'] ) ) {
				$b['innerHTML'] = self::bake_string( $b['innerHTML'], 'html', $restricted, $demo, $demo_hit );
			}
			if ( ! empty( $b['innerContent'] ) ) {
				$b['innerContent'] = array_map( function ( $c ) use ( $restricted, $demo, &$demo_hit ) { return is_string( $c ) ? self::bake_string( $c, 'html', $restricted, $demo, $demo_hit ) : $c; }, $b['innerContent'] );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$b['innerBlocks'] = self::bake_blocks( $b['innerBlocks'], $restricted, $demo, $demo_hit );
			}
		}
		unset( $b );
		return $blocks;
	}

	/** Bake the string values of a block's attributes (links as links, text as text). */
	private static function bake_attrs( array $attrs, bool $restricted, array $demo, array &$demo_hit ): array {
		foreach ( $attrs as $k => $v ) {
			if ( is_array( $v ) ) {
				$attrs[ $k ] = self::bake_attrs( $v, $restricted, $demo, $demo_hit );
			} elseif ( is_string( $v ) && '' !== $v ) {
				$is_url = in_array( (string) $k, [ 'url', 'link', 'href', 'src', 'ctaUrl' ], true ) || 0 === strpos( ltrim( $v ), '#lb-' ) || 0 === strpos( ltrim( $v ), '{{' );
				$attrs[ $k ] = self::bake_string( $v, $is_url ? 'url' : 'text', $restricted, $demo, $demo_hit );
			}
		}
		return $attrs;
	}

	/** One string through the token engine. Tokens that must stay are hidden from it and put back afterwards. */
	private static function bake_string( string $s, string $context, bool $restricted, array $demo, array &$demo_hit ): string {
		if ( false === strpos( $s, '{{' ) && false === stripos( $s, '%7B%7B' ) && false === strpos( $s, '#' ) && false === strpos( $s, '[lb' ) ) {
			return $s;
		}
		if ( $restricted ) { // A design is shown on many pages: whatever depends on the page being viewed stays a token.
			$s = (string) preg_replace( '/\{\{(\s*)(page|entry|area|service|meta)\./i', "\x01\$1\$2.", $s );
			$s = (string) preg_replace( '/%7B%7B(page|entry|area|service|meta)\./i', "\x02\$1.", $s );
			$s = (string) preg_replace( '/(["\'])#(entry-url|page-url|page-parent_url|area-url|service-category_url)\1/', "\$1\x03\$2\$1", $s );
		}
		if ( $demo ) {
			$alt = implode( '|', array_map( 'preg_quote', $demo ) );
			$s = (string) preg_replace_callback( '/\{\{(\s*)lb\.(' . $alt . ')\b/i', static function ( $m ) use ( &$demo_hit ) { $demo_hit[] = strtolower( $m[2] ); return "\x01" . $m[1] . 'lb.' . $m[2]; }, $s );
			$s = (string) preg_replace_callback( '/(["\'])#lb-(' . $alt . ')\1/i', static function ( $m ) use ( &$demo_hit ) { $demo_hit[] = strtolower( $m[2] ); return $m[1] . "\x03" . 'lb-' . $m[2] . $m[1]; }, $s );
		}
		$s = (string) preg_replace_callback( '/\[lb\s+field=["\']([a-z0-9_]+)["\']\s*\]/i', static function ( $m ) use ( $demo, &$demo_hit ) {
			if ( in_array( strtolower( $m[1] ), $demo, true ) ) {
				$demo_hit[] = strtolower( $m[1] );
				return $m[0];
			}
			return Tokens::html( 'lb', strtolower( $m[1] ) );
		}, $s );
		$s = Tokens::replace( $s, $context );
		return str_replace( [ "\x01", "\x02", "\x03" ], [ '{{', '%7B%7B', '#' ], $s );
	}

	/** %lb_x% / %%lb_x%% variables in an SEO string, replaced with their current value. */
	private static function bake_seo( string $v, \WP_Post $p ): string {
		$vars = Compat::seo_vars();
		$v = (string) preg_replace_callback( '/%{1,2}(lb_[A-Za-z0-9_-]+)%{1,2}/', static function ( $m ) use ( $vars, $p ) {
			if ( ! isset( $vars[ $m[1] ] ) ) {
				return $m[0];
			}
			$val = Compat::var_value( $vars[ $m[1] ]['key'], $p );
			return '' !== $val ? $val : $m[0];
		}, $v );
		return Tokens::plain_for( $v, $p ); // Tokens typed by hand into the SEO field.
	}
}
