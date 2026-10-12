<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Duplicate: safe copies of pages, posts, services, service areas, synced patterns and other public post types.
 *
 * One code path for every entry point (row action, bulk action, admin bar, block editor menu, MCP tool duplicate-post):
 * Duplicate::run( $source_id, $args ) and Duplicate::plan() for dry runs. A copy is always inserted as a draft with
 * wp_slash()'d data (block JSON survives), then gets the source's terms (by ID) and meta through the meta API (hooks
 * fire, multi-value keys keep their order), minus an exclude list: edit locks, old slugs, Launch Builder identity keys
 * (_lb_key and friends, so LB re-runs keep updating the ORIGINAL), SEO canonical/score keys and, with seo=reset (the
 * default), the SEO title/description/focus keyword. Kadence uniqueIDs are re-keyed to {new_id}_{hash} so the copy never
 * shares per-block CSS with its source and Kadence's editor keeps the IDs. Only then is the status changed (publish or
 * pending), so status listeners see a complete post.
 *
 * Security: one gate (check_source() + prepare()) for every entry point: edit_post on the source (copying exposes all
 * meta, so read access is not enough), create_posts of the type, publish_posts / edit_others_posts when asked for, a
 * type allow-list with a hard deny-list, a kses guard for users without unfiltered_html, per-post nonces on links and
 * core's nonce on bulk actions, absint() on every ID, no raw SQL on user input, escaped errors.
 *
 * Kadence's own duplicators (Elements, Advanced Pages, Conversions) stay in charge of their screens; this module only
 * removes LB identity keys from what they copy and re-keys uniqueIDs on the copy (their filters and actions).
 *
 * Storage: option launch_builder_duplicate (settings, not autoloaded), post meta _lb_duplicate_of on copies.
 */
final class Duplicate {

	/** Modules contract (see the Modules screen): toggleable, on by default. */
	const MODULE = [
		'slug'        => 'duplicate',
		'label'       => 'Duplicate',
		'description' => 'Duplicate pages, posts, services, service areas and synced patterns as drafts (row action, bulk action, admin bar, block editor menu and the duplicate-post tool). SEO and Launch Builder identity meta are reset and Kadence block IDs are re-keyed on every copy.',
		'toggle'      => true,
		'default'     => true,
	];

	const OPT      = 'launch_builder_duplicate';
	const META     = '_lb_duplicate_of';
	const ACTION   = 'lb_duplicate';
	const BULK     = 'lb_duplicate_bulk'; // The list-table bulk action: it must not share the row action's name (admin_action_<name> runs on edit.php too).
	const PAGE     = 'launch-builder-duplicate';
	const MAX_TOOL = 20;
	const MAX_BULK = 50;

	/** Enabled by default (plus every other public post type that is not denied). */
	const DEFAULT_TYPES = [ 'page', 'post', 'lb_service', 'lb_area', 'wp_block' ];

	/** Never duplicated, whatever the settings say. */
	const DENY_TYPES = [ 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'wp_navigation', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription' ];

	/** Kadence keeps its own Duplicate link for these; only the tool may copy them. */
	const KADENCE_TYPES = [ 'kadence_element', 'kadence_adv_page', 'kadence_conversions' ];

	/** Meta never copied (exact keys, or prefixes ending in *). */
	const EXCLUDE_META = [
		// Core bookkeeping.
		'_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_*', '_wp_desired_post_slug', '_encloseme', '_pingme', '_oembed_*', self::META,
		// Launch Builder identity: a copy must never be mistaken for the object LB manages.
		'_lb_key', '_lb_slot', '_lb_roles', '_lb_hash', '_lb_custom_slug', '_lb_uri', '_lb_src',
		// SEO bookkeeping (a copied canonical would point the copy at its source).
		'rank_math_canonical_url', 'rank_math_seo_score', 'rank_math_internal_links_processed', 'rank_math_contentai_score', 'rank_math_og_content_image', 'rank_math_analytic_*',
		'_yoast_wpseo_canonical', '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_yoast_wpseo_inclusive_language_score', '_yoast_wpseo_estimated-reading-time-minutes', '_yoast_wpseo_wordproof_timestamp', '_yoast_indexnow_last_ping', '_yoast_wpseo_zapier_trigger_sent',
	];

	/** Also left out with seo=reset (the default): the SEO plugin falls back to its post-type templates. */
	const EXCLUDE_SEO_RESET = [
		'_lb_seo_tpl',
		'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_ca_keyword', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_breadcrumb_title', 'rank_math_pillar_content',
		'_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_focuskeywords', '_yoast_wpseo_keywordsynonyms', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_bctitle', '_yoast_wpseo_is_cornerstone',
	];

	/** LB identity keys removed from what Kadence's own duplicators copy. */
	const LB_IDENTITY = [ '_lb_key', '_lb_slot', '_lb_roles', '_lb_hash', '_lb_custom_slug', '_lb_uri', self::META ];

	/** Kadence Blocks Pro per-post code: raw JS printed on the page (kses guard) and CSS that names uniqueIDs (re-keyed). */
	const CUSTOM_JS = [ '_kad_blocks_head_custom_js', '_kad_blocks_body_custom_js', '_kad_blocks_footer_custom_js' ];
	const CSS_META  = [ '_kad_blocks_custom_css' ];

	/** Kadence Blocks optimizer state: only the "excluded" preference (-1) belongs to the content. */
	const OPTIMIZER_META = '_kb_optimizer_status';

	const EXCLUDE_TAX = [ 'post_translations', 'term_translations', 'nav_menu', 'link_category' ];

	/** Blocks that point at another object (copied as a reference, not cloned): block => attribute. */
	const SHARED_REFS = [ 'kadence/advanced-form' => 'id', 'kadence/navigation' => 'id', 'kadence/header' => 'id', 'core/block' => 'ref', 'core/navigation' => 'ref' ];

	/** The type's "category" for overrides.category. */
	const CATEGORY_TAX = [ 'lb_service' => 'lb_service_cat', 'post' => 'category' ];

	/** Other duplicator plugins that can clash. The first has known security flaws (SQL injection, XSS, CSRF by any logged-in user). Slugs are assembled from parts so the list reads as data. */
	const CONFLICTS = [
		'duplicate' . '-page-and-post/duplicate' . '-page-and-post.php' => [ 'Duplicate Page and Post', 'critical' ],
		'duplicate-post/duplicate-post.php'                              => [ 'Yoast Duplicate Post', 'info' ],
		'duplicate' . '-page/duplicatepage.php'                          => [ 'Duplicate Page', 'info' ],
	];

	/** Block comment delimiters (the expression WP_Block_Parser uses). */
	const BLOCK_RE = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/** uniqueIDs that are re-keyed: exactly one underscore (Kadence's {post}_{id} shape and the generator's {hash}_{path}). */
	const ID_RE = '/^[A-Za-z0-9]+_[A-Za-z0-9-]+$/';

	/** @var array|null settings memo */
	private static $settings = null;

	/** @var string[]|null ui_types() memo */
	private static $types = null;

	/* ------------------------------------------------------------------ module */

	/** Is the module switched on? Modules::enabled() decides (it also keeps a disabled module from booting at all). */
	public static function enabled(): bool {
		return Modules::enabled( __CLASS__ );
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 20, 2 );
		add_filter( 'page_row_actions', [ __CLASS__, 'row_action' ], 20, 2 );
		add_action( 'admin_action_' . self::ACTION, [ __CLASS__, 'admin_action' ] );
		add_action( 'load-edit.php', [ __CLASS__, 'bulk_register' ] );
		add_filter( 'removable_query_args', [ __CLASS__, 'removable_args' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 90 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_assets' ] );
		add_action( 'post_submitbox_misc_actions', [ __CLASS__, 'submitbox' ] );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_post_lb_duplicate_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'add_option_' . self::OPT, [ __CLASS__, 'reset' ] );
		add_action( 'update_option_' . self::OPT, [ __CLASS__, 'reset' ] );
		add_action( 'delete_option_' . self::OPT, [ __CLASS__, 'reset' ] );
		foreach ( [ 'elements', 'adv_pages', 'conversions' ] as $k ) {
			add_filter( "kadence_{$k}_duplicate_post_exclude_list", [ __CLASS__, 'kadence_exclude' ] );
			add_action( "kadence_{$k}_duplicate_post", [ __CLASS__, 'kadence_duplicated' ], 10, 2 );
		}
	}

	/** _lb_duplicate_of: protected, not in REST, written only by this module. */
	public static function register_meta(): void {
		register_post_meta(
			'',
			self::META,
			[
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			]
		);
	}

	/* ------------------------------------------------------------------ settings and types */

	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$s = get_option( self::OPT, [] );
		$s = is_array( $s ) ? $s : [];
		self::$settings = [
			'types'     => isset( $s['types'] ) && is_array( $s['types'] ) ? array_values( array_map( 'strval', $s['types'] ) ) : null, // null = defaults
			'suffix'    => isset( $s['suffix'] ) && is_string( $s['suffix'] ) ? $s['suffix'] : null, // null = translated "(Copy)"
			'seo'       => 'keep' === ( $s['seo'] ?? '' ) ? 'keep' : 'reset',
			'exclude'   => array_values( array_filter( array_map( 'strval', (array) ( $s['exclude'] ?? [] ) ), [ __CLASS__, 'valid_pattern' ] ) ),
			'redirect'  => 'list' === ( $s['redirect'] ?? '' ) ? 'list' : 'edit',
			'admin_bar' => ! isset( $s['admin_bar'] ) || ! empty( $s['admin_bar'] ),
		];
		return self::$settings;
	}

	/** Forget the memoised settings and types (after the option changes). */
	public static function reset(): void {
		self::$settings = null;
		self::$types = null;
	}

	public static function valid_pattern( $p ): bool {
		return is_string( $p ) && (bool) preg_match( '/^[A-Za-z0-9_.:-]+\*?$/', $p ) && strlen( $p ) <= 191;
	}

	/** Post types the settings screen offers (shown in wp-admin, not denied, not Kadence's own). */
	public static function candidate_types(): array {
		$out = [];
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $name => $pto ) {
			if ( ! in_array( $name, self::DENY_TYPES, true ) && ! in_array( $name, self::KADENCE_TYPES, true ) ) {
				$out[ $name ] = $pto;
			}
		}
		return $out;
	}

	/** Types with a Duplicate link in wp-admin (filter launch_builder_duplicate_types). Memoised once post types exist. */
	public static function ui_types(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}
		$types = self::settings()['types'];
		if ( null === $types ) {
			$types = array_merge( self::DEFAULT_TYPES, array_keys( get_post_types( [ 'public' => true, 'show_ui' => true ] ) ) );
		}
		$types = (array) apply_filters( 'launch_builder_duplicate_types', array_values( array_unique( $types ) ) );
		$candidates = self::candidate_types();
		$types = array_values( array_filter( $types, static function ( $t ) use ( $candidates ) { return is_string( $t ) && isset( $candidates[ $t ] ); } ) );
		if ( did_action( 'wp_loaded' ) ) {
			self::$types = $types;
		}
		return $types;
	}

	/** Types the duplicate-post tool may copy: the enabled ones plus Kadence's (whose admin screens keep their own link). */
	public static function tool_types(): array {
		$types = self::ui_types();
		foreach ( self::KADENCE_TYPES as $t ) {
			$pto = get_post_type_object( $t );
			if ( $pto && $pto->show_ui ) {
				$types[] = $t;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/* ------------------------------------------------------------------ the gate */

	private static function err( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Can the current user duplicate this post (in the admin UI, or with the tool)? Checks existence, edit_post (before
	 * anything that would describe the post), status, type and create_posts.
	 *
	 * @param int|\WP_Post $post
	 * @return \WP_Post|\WP_Error
	 */
	public static function check_source( $post, string $context = 'admin' ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return self::err( 'lb_duplicate_not_found', __( 'That post does not exist.', 'launch-builder' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to duplicate that post.', 'launch-builder' ), 403 );
		}
		if ( 'revision' === $post->post_type || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return self::err( 'lb_duplicate_type', __( 'Revisions and autosaves cannot be duplicated.', 'launch-builder' ), 400 );
		}
		if ( in_array( $post->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
			return self::err( 'lb_duplicate_status', __( 'Trashed posts and unsaved drafts cannot be duplicated.', 'launch-builder' ), 400 );
		}
		$pto = get_post_type_object( $post->post_type );
		$types = 'tool' === $context ? self::tool_types() : self::ui_types();
		if ( ! $pto || ! $pto->show_ui || in_array( $post->post_type, self::DENY_TYPES, true ) || ! in_array( $post->post_type, $types, true ) ) {
			/* translators: %s: post type name */
			return self::err( 'lb_duplicate_type', sprintf( __( 'Posts of type "%s" cannot be duplicated here.', 'launch-builder' ), $post->post_type ), 400 );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to create posts of that type.', 'launch-builder' ), 403 );
		}
		return $post;
	}

	/** Cheap yes/no for links (row action, admin bar, editor menu). */
	public static function can_duplicate( $post, string $context = 'admin' ): bool {
		return ! is_wp_error( self::check_source( $post, $context ) );
	}

	/** Raw (unescaped) nonce'd admin URL that duplicates a post. */
	public static function link( int $post_id, array $extra = [] ): string {
		return add_query_arg( array_merge( [ 'action' => self::ACTION, 'post' => $post_id ], $extra, [ '_wpnonce' => wp_create_nonce( self::ACTION . '_' . $post_id ) ] ), admin_url( 'admin.php' ) );
	}

	/** Does a meta key match one of the patterns (exact, or a prefix ending in *)? */
	public static function matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $p ) {
			if ( '*' === substr( $p, -1 ) ? 0 === strpos( $key, substr( $p, 0, -1 ) ) : $key === $p ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate everything and work out what the copy will be, without writing. Used by run() and plan().
	 *
	 * @param array $args context admin|tool, title, slug, status draft|pending|publish, parent, author me|original,
	 *                    seo reset|keep, featured_image, taxonomies, meta, rekey_kadence, exclude_meta[], key,
	 *                    allow_filtered, excerpt, category[], number (copy number when several copies are made)
	 * @return array|\WP_Error
	 */
	private static function prepare( int $source_id, array $args ) {
		$context = 'tool' === ( $args['context'] ?? '' ) ? 'tool' : 'admin';
		$src = self::check_source( $source_id, $context );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$pto = get_post_type_object( $src->post_type );
		$set = self::settings();
		$uid = get_current_user_id();
		$warnings = [];

		$status = (string) ( $args['status'] ?? 'draft' );
		if ( ! in_array( $status, [ 'draft', 'pending', 'publish' ], true ) ) {
			return self::err( 'lb_duplicate_bad_status', __( 'status must be draft, pending or publish.', 'launch-builder' ), 400 );
		}
		if ( 'publish' === $status && ! current_user_can( $pto->cap->publish_posts ) ) {
			return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to publish posts of that type.', 'launch-builder' ), 403 );
		}

		$author = $uid;
		if ( 'original' === ( $args['author'] ?? 'me' ) && (int) $src->post_author !== $uid ) {
			if ( ! current_user_can( $pto->cap->edit_others_posts ) ) {
				return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to keep the original author.', 'launch-builder' ), 403 );
			}
			if ( get_userdata( (int) $src->post_author ) ) {
				$author = (int) $src->post_author;
			} else {
				$warnings[] = 'original_author_missing';
			}
		}

		$parent = (int) $src->post_parent;
		if ( isset( $args['parent'] ) && null !== $args['parent'] ) {
			$parent = absint( $args['parent'] );
			if ( ! is_post_type_hierarchical( $src->post_type ) ) {
				return self::err( 'lb_duplicate_bad_parent', __( 'That post type has no parents.', 'launch-builder' ), 400 );
			}
			if ( $parent ) {
				$par = get_post( $parent );
				if ( ! $par || $par->post_type !== $src->post_type || in_array( $par->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
					return self::err( 'lb_duplicate_bad_parent', __( 'parent must be an existing post of the same type.', 'launch-builder' ), 400 );
				}
				if ( ! current_user_can( 'edit_post', $parent ) ) {
					return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to use that parent.', 'launch-builder' ), 403 );
				}
			}
		}

		$title = isset( $args['title'] ) && '' !== trim( (string) $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
		if ( '' === $title ) {
			$suffix = null === $set['suffix'] ? __( '(Copy)', 'launch-builder' ) : trim( $set['suffix'] );
			$title = trim( $src->post_title . ( '' === $suffix ? '' : ' ' . $suffix ) );
		}
		$number = max( 1, (int) ( $args['number'] ?? 1 ) );
		if ( $number > 1 ) {
			$title .= ' ' . $number;
		}

		$slug = '';
		if ( isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ) {
			$slug = sanitize_title( (string) $args['slug'] );
			if ( '' === $slug ) {
				return self::err( 'lb_duplicate_bad_slug', __( 'slug is empty once sanitized.', 'launch-builder' ), 400 );
			}
		}

		$key = '';
		if ( isset( $args['key'] ) && '' !== (string) $args['key'] ) {
			$key = (string) $args['key'];
			if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $key ) ) {
				return self::err( 'lb_duplicate_bad_key', __( 'key must be 1-64 characters: a-z, 0-9 and hyphens.', 'launch-builder' ), 400 );
			}
			$taken = get_posts( [ 'post_type' => $src->post_type, 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $taken ) {
				return self::err( 'lb_duplicate_key_taken', __( 'Another post of that type already has that key.', 'launch-builder' ), 409 );
			}
		}

		$excerpt = null;
		if ( isset( $args['excerpt'] ) && is_string( $args['excerpt'] ) ) {
			$excerpt = sanitize_textarea_field( $args['excerpt'] );
		}

		$cat_tax = '';
		$cat_ids = null;
		if ( isset( $args['category'] ) && null !== $args['category'] && [] !== $args['category'] ) {
			$cat_tax = (string) ( self::CATEGORY_TAX[ $src->post_type ] ?? '' );
			$tax = $cat_tax ? get_taxonomy( $cat_tax ) : null;
			if ( ! $tax || ! is_object_in_taxonomy( $src->post_type, $cat_tax ) ) {
				return self::err( 'lb_duplicate_no_category', __( 'That post type has no category to override.', 'launch-builder' ), 400 );
			}
			if ( ! current_user_can( $tax->cap->assign_terms ) ) {
				return self::err( 'lb_duplicate_forbidden', __( 'You are not allowed to assign those categories.', 'launch-builder' ), 403 );
			}
			$cat_ids = [];
			foreach ( (array) $args['category'] as $c ) {
				$term = null;
				if ( is_int( $c ) || ( is_string( $c ) && ctype_digit( $c ) ) ) {
					$term = get_term( (int) $c, $cat_tax );
				} elseif ( is_string( $c ) && '' !== trim( $c ) ) {
					$term = get_term_by( 'slug', sanitize_title( $c ), $cat_tax ) ?: get_term_by( 'name', sanitize_text_field( $c ), $cat_tax );
				}
				if ( ! $term instanceof \WP_Term ) {
					return self::err( 'lb_duplicate_bad_term', __( 'category names a term that does not exist (use an existing category ID, slug or name).', 'launch-builder' ), 400 );
				}
				$cat_ids[] = (int) $term->term_id;
			}
			$cat_ids = array_values( array_unique( $cat_ids ) );
		}

		$opt = static function ( string $k, bool $default ) use ( $args ): bool {
			return array_key_exists( $k, $args ) ? (bool) $args[ $k ] : $default;
		};
		$copy_meta = $opt( 'meta', true );
		$featured = $opt( 'featured_image', true );
		$seo = in_array( $args['seo'] ?? '', [ 'reset', 'keep' ], true ) ? $args['seo'] : $set['seo'];

		// Meta: what is copied and what is left out.
		$extra = [];
		foreach ( (array) ( $args['exclude_meta'] ?? [] ) as $p ) {
			if ( ! self::valid_pattern( $p ) ) {
				return self::err( 'lb_duplicate_bad_exclude', __( 'exclude_meta entries are meta keys (letters, digits, _ . : -), optionally ending in * for a prefix.', 'launch-builder' ), 400 );
			}
			$extra[] = $p;
		}
		$patterns = array_merge( self::EXCLUDE_META, 'reset' === $seo ? self::EXCLUDE_SEO_RESET : [], $set['exclude'], $extra );
		/** Filter launch_builder_duplicate_exclude_meta ( string[] patterns, WP_Post source, array args ): keys never copied (exact, or prefix*). */
		$patterns = array_values( array_filter( (array) apply_filters( 'launch_builder_duplicate_exclude_meta', $patterns, $src, $args ), 'is_string' ) );
		$unfiltered = current_user_can( 'unfiltered_html' );
		$copy = [];
		$skipped = [];
		$has_js = false;
		$all = get_post_meta( $src->ID );
		foreach ( is_array( $all ) ? $all : [] as $k => $values ) {
			$k = (string) $k;
			$structural = '_thumbnail_id' === $k || '_wp_page_template' === $k;
			$values = array_values( (array) $values );
			$skip = self::matches( $k, $patterns )
				|| ( ! $copy_meta && ! $structural )
				|| ( '_thumbnail_id' === $k && ! $featured )
				|| ( self::OPTIMIZER_META === $k && '-1' !== (string) ( $values[0] ?? '' ) )
				|| ( null !== $cat_ids && ( 'rank_math_primary_' . $cat_tax === $k || '_yoast_wpseo_primary_' . $cat_tax === $k ) && ! in_array( (int) ( $values[0] ?? 0 ), $cat_ids, true ) );
			if ( ! $skip && in_array( $k, self::CUSTOM_JS, true ) && '' !== trim( (string) ( $values[0] ?? '' ) ) ) {
				$has_js = true;
				$skip = ! $unfiltered; // Raw page scripts are never handed to an account that may not write them.
			}
			if ( $skip ) {
				$skipped[] = $k;
				continue;
			}
			$copy[ $k ] = $values;
		}

		// kses guard: a user without unfiltered_html would get scripts, iframes and forms stripped from the copy.
		if ( ! $unfiltered ) {
			$content = (string) $src->post_content;
			$filtered = '' !== $content && wp_kses_post( $content ) !== $content;
			if ( $filtered || $has_js ) {
				if ( empty( $args['allow_filtered'] ) ) {
					return self::err( 'lb_duplicate_filtered', __( 'This post contains markup your account cannot save (such as scripts, iframes, forms or custom page code). It would be removed from the copy. Duplicate it anyway with allow_filtered, or ask an administrator.', 'launch-builder' ), 403 );
				}
				if ( $filtered ) {
					$warnings[] = 'content_filtered';
				}
				if ( $has_js ) {
					$warnings[] = 'custom_js_dropped';
				}
			}
		}

		// Terms (by ID).
		$terms = [];
		/** Filter launch_builder_duplicate_exclude_taxonomies ( string[], WP_Post source ): taxonomies never copied. */
		$skip_tax = (array) apply_filters( 'launch_builder_duplicate_exclude_taxonomies', self::EXCLUDE_TAX, $src );
		foreach ( get_object_taxonomies( $src->post_type ) as $tx ) {
			if ( in_array( $tx, $skip_tax, true ) || ( ! $opt( 'taxonomies', true ) && 'post_format' !== $tx && $tx !== $cat_tax ) ) {
				continue;
			}
			if ( $tx === $cat_tax && null !== $cat_ids ) {
				$terms[ $tx ] = $cat_ids;
				continue;
			}
			$ids = wp_get_object_terms( $src->ID, $tx, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $ids ) && $ids ) {
				$terms[ $tx ] = array_map( 'intval', $ids );
			}
		}

		return [
			'src'      => $src,
			'context'  => $context,
			'title'    => $title,
			'slug'     => $slug,
			'status'   => $status,
			'author'   => $author,
			'parent'   => $parent,
			'excerpt'  => $excerpt,
			'key'      => $key,
			'seo'      => $seo,
			'rekey'    => $opt( 'rekey_kadence', true ),
			'meta'     => $copy,
			'skipped'  => array_values( array_unique( $skipped ) ),
			'terms'    => $terms,
			'warnings' => $warnings,
			'args'     => $args,
		];
	}

	/**
	 * Duplicate a post. The single code path behind every entry point.
	 *
	 * @return array|\WP_Error { source_id, id, type, title, status, slug, edit_url, preview_url, copied {meta_keys, terms},
	 *                         skipped_meta, rekeyed {old: new}, shared_refs, warnings }
	 */
	public static function run( int $source_id, array $args = [] ) {
		$p = self::prepare( absint( $source_id ), $args );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		global $wpdb;
		$src = $p['src'];
		$warnings = $p['warnings'];

		$slug = $p['slug'];
		if ( '' !== $slug ) {
			$unique = wp_unique_post_slug( $slug, 0, 'publish', $src->post_type, $p['parent'] ); // Drafts keep any slug: never give a copy one that is taken.
			if ( $unique !== $slug ) {
				$warnings[] = 'slug_adjusted';
				$slug = $unique;
			}
		}

		$new = wp_insert_post(
			wp_slash(
				[
					'post_type'             => $src->post_type,
					'post_status'           => 'draft', // Always a draft first: the status changes once terms and meta are in place.
					'post_title'            => $p['title'],
					'post_name'             => $slug,
					'post_content'          => $src->post_content,
					'post_excerpt'          => null !== $p['excerpt'] ? $p['excerpt'] : $src->post_excerpt,
					'post_content_filtered' => $src->post_content_filtered,
					'post_parent'           => $p['parent'],
					'menu_order'            => (int) $src->menu_order,
					'comment_status'        => $src->comment_status,
					'ping_status'           => $src->ping_status,
					'post_password'         => $src->post_password,
					'post_author'           => $p['author'],
				]
			),
			true
		);
		if ( is_wp_error( $new ) || ! $new ) {
			return self::err( 'lb_duplicate_insert_failed', __( 'The copy could not be created.', 'launch-builder' ) . ( is_wp_error( $new ) ? ' ' . $new->get_error_message() : '' ), 500 );
		}
		$new = (int) $new;

		// Kadence uniqueIDs, on the content as stored (after any kses), so the direct write cannot bypass a filter.
		$rk = [ 'map' => [], 'first' => [], 'shared_refs' => [], 'ok' => true, 'changed' => false ];
		$stored = (string) get_post_field( 'post_content', $new, 'raw' );
		if ( $p['rekey'] ) {
			$rk = self::rekey_content( $stored, $new );
			if ( $rk['changed'] ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $rk['content'] ], [ 'ID' => $new ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $new );
			}
			if ( ! $rk['ok'] ) {
				$warnings[] = 'rekey_failed';
			}
		} else {
			$rk['shared_refs'] = self::rekey( $stored, $new )['shared_refs'];
		}

		// Terms by ID; counts are recomputed once at the end.
		$term_count = 0;
		wp_defer_term_counting( true );
		foreach ( $p['terms'] as $tx => $ids ) {
			$r = wp_set_object_terms( $new, $ids, $tx );
			if ( is_wp_error( $r ) ) {
				$warnings[] = 'terms_failed:' . $tx;
			} else {
				$term_count += count( $ids );
			}
		}
		wp_defer_term_counting( false );

		// Meta through the API: hooks fire, sanitize_meta runs, multi-value keys keep their order.
		$meta_count = 0;
		foreach ( $p['meta'] as $k => $values ) {
			foreach ( $values as $raw ) {
				$v = maybe_unserialize( $raw );
				if ( $rk['first'] && is_string( $v ) && in_array( $k, self::CSS_META, true ) ) {
					$v = self::map_ids( $v, $rk['first'] );
				}
				try {
					add_post_meta( $new, wp_slash( $k ), self::deep_slash( $v ) ); // add_post_meta unslashes the key as well as the value.
				} catch ( \Throwable $e ) {
					$warnings[] = 'meta_failed:' . $k;
				}
			}
			$meta_count++;
		}
		add_post_meta( $new, self::META, (int) $src->ID, true );
		if ( '' !== $p['key'] ) {
			update_post_meta( $new, '_lb_key', $p['key'] );
		}

		// The status changes last, so transition_post_status, sitemaps, indexing and menus see a complete post.
		if ( 'draft' !== $p['status'] ) {
			$u = wp_update_post( [ 'ID' => $new, 'post_status' => $p['status'] ], true );
			if ( is_wp_error( $u ) ) {
				$warnings[] = 'status_failed';
			}
		}
		clean_post_cache( $new );
		$post = get_post( $new );
		/** Action launch_builder_duplicated ( int new id, WP_Post source, array args ). */
		do_action( 'launch_builder_duplicated', $new, $src, $p['args'] );
		if ( $post && 'publish' === $post->post_status ) {
			Cache::purge_later();
		}

		return [
			'source_id'    => (int) $src->ID,
			'id'           => $new,
			'type'         => $src->post_type,
			'title'        => $post ? $post->post_title : $p['title'],
			'status'       => $post ? $post->post_status : 'draft',
			'slug'         => $post ? $post->post_name : $slug,
			'edit_url'     => (string) ( get_edit_post_link( $new, 'raw' ) ?: admin_url( 'post.php?post=' . $new . '&action=edit' ) ),
			'preview_url'  => (string) ( $post && 'publish' === $post->post_status ? get_permalink( $new ) : get_preview_post_link( $new ) ),
			'copied'       => [ 'meta_keys' => $meta_count, 'terms' => $term_count ],
			'skipped_meta' => $p['skipped'],
			'rekeyed'      => $rk['map'],
			'shared_refs'  => $rk['shared_refs'],
			'warnings'     => array_values( array_unique( $warnings ) ),
		];
	}

	/** Dry run: what run() would do, written nowhere. */
	public static function plan( int $source_id, array $args = [] ) {
		$p = self::prepare( absint( $source_id ), $args );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$src = $p['src'];
		$slug = $p['slug'];
		$warnings = $p['warnings'];
		if ( '' !== $slug ) {
			$unique = wp_unique_post_slug( $slug, 0, 'publish', $src->post_type, $p['parent'] );
			if ( $unique !== $slug ) {
				$warnings[] = 'slug_adjusted';
				$slug = $unique;
			}
		}
		$rk = self::rekey( (string) $src->post_content, 0 );
		$ids = array_keys( $rk['map'] );
		return [
			'dry_run'      => true,
			'source_id'    => (int) $src->ID,
			'type'         => $src->post_type,
			'title'        => $p['title'],
			'status'       => $p['status'],
			'slug'         => $slug,
			'author'       => $p['author'],
			'parent'       => $p['parent'],
			'would_copy'   => [ 'meta_keys' => count( $p['meta'] ), 'terms' => array_sum( array_map( 'count', $p['terms'] ) ) ],
			'skipped_meta' => $p['skipped'],
			'rekey'        => [ 'enabled' => $p['rekey'], 'count' => $rk['count'], 'ids' => array_slice( $ids, 0, 100 ) ],
			'shared_refs'  => $rk['shared_refs'],
			'warnings'     => array_values( array_unique( $warnings ) ),
		];
	}

	/** wp_slash() for any value, objects included (add_post_meta unslashes with map_deep, so this is its exact inverse). */
	private static function deep_slash( $v ) {
		return map_deep(
			$v,
			static function ( $x ) {
				return is_string( $x ) ? addslashes( $x ) : $x;
			}
		);
	}

	/* ------------------------------------------------------------------ Kadence uniqueIDs */

	/**
	 * Re-key the content of a copy. Uses Unique_Ids::rekey( $content, $post_id ) when that class exists (string, or an
	 * array with 'content') and its result passes the same validation; otherwise self::rekey().
	 *
	 * @return array{content:string,map:array,first:array,count:int,shared_refs:array,ok:bool,changed:bool}
	 */
	public static function rekey_content( string $content, int $new_id ): array {
		$own = self::rekey( $content, $new_id );
		$cls = __NAMESPACE__ . '\\Unique_Ids';
		if ( $own['count'] && class_exists( $cls ) && is_callable( [ $cls, 'rekey' ] ) ) {
			try {
				$r = call_user_func( [ $cls, 'rekey' ], $content, $new_id );
				$out = is_array( $r ) ? ( $r['content'] ?? null ) : $r;
				$pairs = is_string( $out ) ? self::id_pairs( $content, $out, $new_id ) : null;
				foreach ( (array) $pairs as [ $old ] ) {
					if ( substr_count( $out, $old ) > substr_count( $own['content'], $old ) ) {
						$pairs = null; // Markup (class names, data attributes) still names the source's IDs.
						break;
					}
				}
				if ( $pairs ) {
					$map = [];
					$first = [];
					foreach ( $pairs as [ $old, $new ] ) {
						$map[ $old ] = isset( $map[ $old ] ) ? array_merge( (array) $map[ $old ], [ $new ] ) : $new;
						$first[ $old ] = $first[ $old ] ?? $new;
					}
					return [ 'content' => $out, 'map' => $map, 'first' => $first, 'count' => count( $pairs ), 'shared_refs' => $own['shared_refs'], 'ok' => true, 'changed' => $out !== $content ];
				}
			} catch ( \Throwable $e ) { // A different signature: use our own.
				unset( $e );
			}
		}
		return $own;
	}

	/**
	 * Re-key Kadence uniqueIDs ({a}_{b} on kadence/* blocks) to {new_id}_{6 hex}-{2 hex}. Only the block's own attribute
	 * and its own markup (between its delimiters, children excluded) change; every other byte of the content is kept.
	 * An ID used by several blocks gets a new ID per block. The prefix equals the post ID, so Kadence's editor keeps
	 * the IDs (no phantom edit on first open) and the copy never shares per-block CSS with its source.
	 *
	 * @return array{content:string,map:array,first:array,count:int,shared_refs:array,ok:bool,changed:bool}
	 */
	public static function rekey( string $content, int $new_id ): array {
		$res = [ 'content' => $content, 'map' => [], 'first' => [], 'count' => 0, 'shared_refs' => [], 'ok' => true, 'changed' => false ];
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $res;
		}
		$tokens = [];
		if ( false === preg_match_all( self::BLOCK_RE, $content, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL ) ) {
			$res['ok'] = false;
			return $res;
		}
		// Pass 1: blocks in document order (= parse order), their paths, IDs and references.
		$blocks = [];
		$names = [];
		$seen = [];
		$stack = [];
		$top = 0;
		foreach ( $tokens as $i => $t ) {
			if ( null !== $t['closer'][0] ) {
				array_pop( $stack );
				continue;
			}
			$name = ( null === $t['namespace'][0] ? 'core/' : $t['namespace'][0] ) . $t['name'][0];
			if ( $stack ) {
				$n = count( $stack ) - 1;
				$path = $stack[ $n ]['path'] . '-' . $stack[ $n ]['kids'];
				$stack[ $n ]['kids']++;
			} else {
				$path = (string) $top++;
			}
			$attrs = null !== $t['attrs'][0] ? json_decode( $t['attrs'][0], true ) : [];
			$attrs = is_array( $attrs ) ? $attrs : [];
			$names[ $i ] = $name;
			if ( isset( self::SHARED_REFS[ $name ] ) && ! empty( $attrs[ self::SHARED_REFS[ $name ] ] ) && is_scalar( $attrs[ self::SHARED_REFS[ $name ] ] ) ) {
				$res['shared_refs'][] = [ 'block' => $name, 'ref' => (int) $attrs[ self::SHARED_REFS[ $name ] ] ];
			}
			if ( isset( $attrs['uniqueID'] ) && is_string( $attrs['uniqueID'] ) ) {
				$seen[ $attrs['uniqueID'] ] = true;
				if ( 0 === strpos( $name, 'kadence/' ) && preg_match( self::ID_RE, $attrs['uniqueID'] ) ) {
					$blocks[ $i ] = [ 'old' => $attrs['uniqueID'], 'path' => $path ];
				}
			}
			if ( null === $t['void'][0] ) {
				$stack[] = [ 'path' => $path, 'kids' => 0, 'tok' => $i ];
			}
		}
		if ( ! $blocks ) {
			return $res;
		}
		// Pass 2: new IDs (deterministic, unique in the document, never one the source uses).
		$used = $seen;
		$occ = [];
		foreach ( $blocks as $i => $b ) {
			$n = 0;
			do {
				$h = md5( $b['old'] . '|' . $new_id . '|' . $b['path'] . ( $n ? '|' . $n : '' ) );
				$new = $new_id . '_' . substr( $h, 0, 6 ) . '-' . substr( md5( $b['path'] . '|' . $b['old'] . '|' . $n ), 0, 2 );
				$n++;
			} while ( isset( $used[ $new ] ) );
			$used[ $new ] = true;
			$blocks[ $i ]['new'] = $new;
			$occ[ $b['old'] ][] = $new;
		}
		$unique = [];
		foreach ( $occ as $old => $news ) {
			if ( 1 === count( $news ) ) {
				$unique[ $old ] = $news[0];
			}
			$res['map'][ $old ] = 1 === count( $news ) ? $news[0] : $news;
			$res['first'][ $old ] = $news[0];
		}
		$re = self::id_regex( array_keys( $occ ) );
		// Pass 3: rebuild. Text between delimiters belongs to the innermost open block.
		$buf = '';
		$pos = 0;
		$stack = [];
		$fail = false;
		$swap = static function ( string $text, $owner ) use ( $blocks, $unique, $re, &$fail ): string {
			if ( '' === $text || null === $owner || ! isset( $blocks[ $owner ] ) ) {
				return $text;
			}
			$old = $blocks[ $owner ]['old'];
			$new = $blocks[ $owner ]['new'];
			$r = preg_replace_callback( $re, static function ( $m ) use ( $old, $new, $unique ) { return $m[1] === $old ? $new : ( $unique[ $m[1] ] ?? $m[1] ); }, $text );
			if ( null === $r ) {
				$fail = true;
				return $text;
			}
			return $r;
		};
		foreach ( $tokens as $i => $t ) {
			[ $tok, $at ] = $t[0];
			$buf .= $swap( (string) substr( $content, $pos, $at - $pos ), $stack ? end( $stack ) : null );
			$pos = $at + strlen( $tok );
			if ( null !== $t['closer'][0] ) {
				array_pop( $stack );
				$buf .= $tok;
				continue;
			}
			if ( isset( $blocks[ $i ] ) ) {
				$cnt = 0;
				$tok = preg_replace( '/("uniqueID"\s*:\s*")' . preg_quote( $blocks[ $i ]['old'], '/' ) . '"/', '${1}' . $blocks[ $i ]['new'] . '"', $tok, 1, $cnt );
				if ( 1 !== $cnt || null === $tok ) {
					$fail = true;
					$tok = $t[0][0];
				}
			}
			$buf .= $tok;
			if ( null === $t['void'][0] ) {
				$stack[] = $i;
			}
		}
		$buf .= $swap( (string) substr( $content, $pos ), $stack ? end( $stack ) : null );

		// Validate against WordPress' own parser: same blocks, same attributes, only the expected IDs changed.
		$expect = [];
		foreach ( $names as $i => $name ) {
			$expect[] = [ $name, isset( $blocks[ $i ] ) ? $blocks[ $i ]['new'] : null ];
		}
		if ( $fail || ! self::same_blocks( $content, $buf, $expect ) ) {
			$res['ok'] = false;
			$res['map'] = [];
			$res['first'] = [];
			return $res;
		}
		$res['content'] = $buf;
		$res['count'] = count( $blocks );
		$res['changed'] = $buf !== $content;
		return $res;
	}

	/** Regex matching any of the IDs as a whole token (longest first; no digit before, no letter/digit after). */
	private static function id_regex( array $ids ): string {
		usort( $ids, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		return '/(?<![0-9])(' . implode( '|', array_map( static function ( $id ) { return preg_quote( (string) $id, '/' ); }, $ids ) ) . ')(?![A-Za-z0-9])/';
	}

	/** Apply an old => new ID map to free text (the post's custom CSS). */
	public static function map_ids( string $text, array $first ): string {
		if ( ! $first || '' === $text ) {
			return $text;
		}
		$r = preg_replace_callback( self::id_regex( array_keys( $first ) ), static function ( $m ) use ( $first ) { return $first[ $m[1] ] ?? $m[1]; }, $text );
		return null === $r ? $text : $r;
	}

	/** Named blocks in parse order: [ name, attrs ]. */
	private static function flat( array $blocks, array &$out = [] ): array {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$out[] = [ (string) $b['blockName'], (array) ( $b['attrs'] ?? [] ) ];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::flat( $b['innerBlocks'], $out );
			}
		}
		return $out;
	}

	/**
	 * Same block tree before and after, apart from uniqueIDs; $expect lists [ name, new uniqueID|null ] per block in
	 * order (null = unchanged).
	 */
	private static function same_blocks( string $before, string $after, array $expect ): bool {
		$a = self::flat( parse_blocks( $before ) );
		$b = self::flat( parse_blocks( $after ) );
		if ( count( $a ) !== count( $b ) || count( $a ) !== count( $expect ) ) {
			return false;
		}
		foreach ( $a as $k => [ $name, $attrs ] ) {
			[ $name2, $attrs2 ] = $b[ $k ];
			if ( $name !== $name2 || $name !== $expect[ $k ][0] ) {
				return false;
			}
			$want = null === $expect[ $k ][1] ? ( $attrs['uniqueID'] ?? null ) : $expect[ $k ][1];
			if ( ( $attrs2['uniqueID'] ?? null ) !== $want ) {
				return false;
			}
			unset( $attrs['uniqueID'], $attrs2['uniqueID'] );
			if ( $attrs != $attrs2 ) { // phpcs:ignore Universal.Operators.StrictComparisons -- key order is not compared.
				return false;
			}
		}
		return true;
	}

	/**
	 * For a re-key done elsewhere: the [old, new] uniqueID pairs, or null when the result is not a valid re-key (block
	 * tree or other attributes changed, a new ID without the {post_id}_ prefix, or an ID still shared with the source).
	 */
	private static function id_pairs( string $before, string $after, int $new_id ): ?array {
		$a = self::flat( parse_blocks( $before ) );
		$b = self::flat( parse_blocks( $after ) );
		if ( count( $a ) !== count( $b ) ) {
			return null;
		}
		$old_ids = [];
		foreach ( $a as [ , $attrs ] ) {
			if ( isset( $attrs['uniqueID'] ) && is_string( $attrs['uniqueID'] ) ) {
				$old_ids[ $attrs['uniqueID'] ] = true;
			}
		}
		$pairs = [];
		foreach ( $a as $k => [ $name, $attrs ] ) {
			[ $name2, $attrs2 ] = $b[ $k ];
			$o = $attrs['uniqueID'] ?? null;
			$n = $attrs2['uniqueID'] ?? null;
			unset( $attrs['uniqueID'], $attrs2['uniqueID'] );
			if ( $name !== $name2 || $attrs != $attrs2 ) { // phpcs:ignore Universal.Operators.StrictComparisons
				return null;
			}
			if ( $o !== $n ) {
				if ( ! is_string( $n ) || 0 !== strpos( $n, $new_id . '_' ) || isset( $old_ids[ $n ] ) ) {
					return null;
				}
				$pairs[] = [ (string) $o, $n ];
			} elseif ( is_string( $o ) && 0 === strpos( $name, 'kadence/' ) && preg_match( self::ID_RE, $o ) ) {
				return null; // A Kadence ID left shared with the source.
			}
		}
		return $pairs;
	}

	/* ------------------------------------------------------------------ Kadence's own duplicators */

	/** Kadence Elements / Advanced Pages / Conversions duplicators: never copy LB identity keys. */
	public static function kadence_exclude( $list ): array {
		return array_values( array_unique( array_merge( (array) $list, self::LB_IDENTITY ) ) );
	}

	/**
	 * After Kadence duplicated an element/page/conversion: re-key its uniqueIDs, mark the copy, and drop the source's
	 * slug that Kadence copies onto the draft (a draft keeps any slug, so path lookups could find the copy).
	 */
	public static function kadence_duplicated( $new_id, $post = null ): void {
		$new_id = absint( $new_id );
		if ( ! $new_id || ! current_user_can( 'edit_post', $new_id ) ) {
			return;
		}
		global $wpdb;
		$fields = [];
		$rk = self::rekey_content( (string) get_post_field( 'post_content', $new_id, 'raw' ), $new_id );
		if ( $rk['changed'] ) {
			$fields['post_content'] = $rk['content'];
		}
		$copy = get_post( $new_id );
		if ( $copy && $post instanceof \WP_Post && 'draft' === $copy->post_status && '' !== $post->post_name && $copy->post_name === $post->post_name ) {
			$fields['post_name'] = '';
		}
		if ( $fields ) {
			$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $new_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no second save_post/revision; only IDs and the draft slug change.
			clean_post_cache( $new_id );
		}
		if ( $rk['first'] ) {
			foreach ( self::CSS_META as $k ) {
				$css = get_post_meta( $new_id, $k, true );
				if ( is_string( $css ) && '' !== $css ) {
					update_post_meta( $new_id, $k, wp_slash( self::map_ids( $css, $rk['first'] ) ) );
				}
			}
		}
		if ( $post instanceof \WP_Post ) {
			update_post_meta( $new_id, self::META, (int) $post->ID );
		}
	}

	/* ------------------------------------------------------------------ admin: row action, handler, bulk */

	public static function row_action( $actions, $post ): array {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || ! self::can_duplicate( $post ) ) {
			return $actions;
		}
		$actions[ self::ACTION ] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::link( $post->ID ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Duplicate &#8220;%s&#8221;', 'launch-builder' ), wp_strip_all_tags( get_the_title( $post ) ) ) ),
			esc_html__( 'Duplicate', 'launch-builder' )
		);
		return $actions;
	}

	/** admin.php?action=lb_duplicate&post=ID&_wpnonce=… The raw request is never echoed. */
	public static function admin_action(): void {
		$id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next.
		check_admin_referer( self::ACTION . '_' . $id );
		$allow = ! empty( $_REQUEST['allow_filtered'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$r = self::run( $id, [ 'context' => 'admin', 'allow_filtered' => $allow ] );
		if ( is_wp_error( $r ) ) {
			$msg = '<p>' . esc_html( $r->get_error_message() ) . '</p>';
			if ( 'lb_duplicate_filtered' === $r->get_error_code() ) {
				$msg .= '<p><a href="' . esc_url( self::link( $id, [ 'allow_filtered' => 1 ] ) ) . '">' . esc_html__( 'Duplicate anyway (that markup is left out of the copy)', 'launch-builder' ) . '</a></p>';
			}
			$data = $r->get_error_data();
			wp_die( $msg, esc_html__( 'Duplicate', 'launch-builder' ), [ 'response' => (int) ( $data['status'] ?? 400 ), 'back_link' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		$to = 'list' === self::settings()['redirect']
			? add_query_arg( [ 'post_type' => $r['type'], 'lb_duplicated' => 1 ], admin_url( 'edit.php' ) )
			: $r['edit_url'];
		wp_safe_redirect( $to );
		exit;
	}

	/** load-edit.php: the bulk action on enabled types. */
	public static function bulk_register(): void {
		$screen = get_current_screen();
		$type = $screen ? (string) $screen->post_type : '';
		$pto = $type ? get_post_type_object( $type ) : null;
		if ( ! $pto || ! in_array( $type, self::ui_types(), true ) || ! current_user_can( $pto->cap->create_posts ) ) {
			return;
		}
		add_filter( "bulk_actions-edit-{$type}", [ __CLASS__, 'bulk_actions' ] );
		add_filter( "handle_bulk_actions-edit-{$type}", [ __CLASS__, 'bulk_handle' ], 10, 3 );
	}

	public static function bulk_actions( $actions ): array {
		$actions = (array) $actions;
		$actions[ self::BULK ] = __( 'Duplicate', 'launch-builder' );
		return $actions;
	}

	/** Core checked the bulk-posts nonce; every item is still checked on its own. At most MAX_BULK per request. */
	public static function bulk_handle( $sendback, $doaction, $post_ids ) {
		if ( self::BULK !== $doaction ) {
			return $sendback;
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
		$take = array_slice( $ids, 0, self::MAX_BULK );
		$ok = 0;
		$failed = 0;
		foreach ( $take as $id ) {
			is_wp_error( self::run( $id, [ 'context' => 'admin' ] ) ) ? $failed++ : $ok++;
		}
		return add_query_arg(
			[
				'lb_duplicated'  => $ok,
				'lb_dup_failed'  => $failed,
				'lb_dup_skipped' => count( $ids ) - count( $take ),
			],
			remove_query_arg( [ 'lb_duplicated', 'lb_dup_failed', 'lb_dup_skipped' ], (string) $sendback )
		);
	}

	public static function removable_args( $args ): array {
		return array_merge( (array) $args, [ 'lb_duplicated', 'lb_dup_failed', 'lb_dup_skipped' ] );
	}

	/** Bulk/list result notice on edit.php, plus notices about other duplicate plugins. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit' === $screen->base && isset( $_GET['lb_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			$ok = absint( wp_unslash( $_GET['lb_duplicated'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$failed = isset( $_GET['lb_dup_failed'] ) ? absint( wp_unslash( $_GET['lb_dup_failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$skipped = isset( $_GET['lb_dup_skipped'] ) ? absint( wp_unslash( $_GET['lb_dup_skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %d: number of drafts created */
			$msg = sprintf( _n( '%d draft copy created.', '%d draft copies created.', $ok, 'launch-builder' ), $ok );
			if ( $failed ) {
				/* translators: %d: number of posts */
				$msg .= ' ' . sprintf( _n( '%d post could not be duplicated (no permission, or a type or status that cannot be copied).', '%d posts could not be duplicated (no permission, or a type or status that cannot be copied).', $failed, 'launch-builder' ), $failed );
			}
			if ( $skipped ) {
				/* translators: 1: number of posts, 2: maximum per request */
				$msg .= ' ' . sprintf( _n( '%1$d post was skipped: at most %2$d are duplicated at once.', '%1$d posts were skipped: at most %2$d are duplicated at once.', $skipped, 'launch-builder' ), $skipped, self::MAX_BULK );
			}
			printf( '<div class="notice notice-%s is-dismissible lb-duplicate-notice"><p>%s</p></div>', esc_attr( $failed ? 'warning' : 'success' ), esc_html( $msg ) );
		}
		if ( ! current_user_can( 'activate_plugins' ) || ! $screen ) {
			return;
		}
		$here = in_array( $screen->base, [ 'dashboard', 'plugins', 'edit' ], true ) || false !== strpos( (string) $screen->id, self::PAGE );
		foreach ( self::conflicts() as $c ) {
			if ( 'critical' === $c['level'] && $here ) {
				/* translators: %s: plugin name */
				printf( '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>', esc_html( sprintf( __( '%s is active.', 'launch-builder' ), $c['name'] ) ), esc_html__( 'It lets any logged-in user (subscribers included) run SQL injection, read private posts and trigger copies through forged links. Deactivate and delete it: Launch Builder includes a safe Duplicate.', 'launch-builder' ) );
			} elseif ( 'info' === $c['level'] && ( 'plugins' === $screen->base || false !== strpos( (string) $screen->id, self::PAGE ) ) ) {
				/* translators: %s: plugin name */
				printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( '%s is active as well as the Launch Builder Duplicate module, so lists show two Duplicate links. Consider deactivating one.', 'launch-builder' ), $c['name'] ) ) );
			}
		}
	}

	/** Active duplicate plugins: [ { plugin, name, level } ]. */
	public static function conflicts(): array {
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		$out = [];
		foreach ( self::CONFLICTS as $file => [ $name, $level ] ) {
			if ( in_array( $file, $active, true ) ) {
				$out[] = [ 'plugin' => $file, 'name' => $name, 'level' => $level ];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ admin bar, block editor, "Copy of" */

	public static function admin_bar( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) || ! self::settings()['admin_bar'] ) {
			return;
		}
		$post = null;
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && 'post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
				$post = get_post();
			}
		} elseif ( is_singular() ) {
			$post = get_queried_object();
		}
		if ( ! $post instanceof \WP_Post || ! self::can_duplicate( $post ) ) {
			return;
		}
		$bar->add_node(
			[
				'id'     => 'lb-duplicate',
				'parent' => $bar->get_node( 'edit' ) ? 'edit' : false,
				'title'  => esc_html__( 'Duplicate', 'launch-builder' ),
				'href'   => self::link( $post->ID ),
				'meta'   => [ 'title' => esc_attr__( 'Create a draft copy of this', 'launch-builder' ) ],
			]
		);
	}

	/** Block editor (post.php only): "Duplicate" in the More menu and the "Copy of" line. */
	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post = get_post();
		if ( ! $screen || 'post' !== $screen->base || 'post.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! $post instanceof \WP_Post ) {
			return;
		}
		$can = self::can_duplicate( $post );
		$source = self::source_info( $post );
		if ( ! $can && ! $source ) {
			return;
		}
		wp_enqueue_script( 'lb-duplicate', LB_URL . 'assets/js/duplicate.js', [ 'wp-plugins', 'wp-element', 'wp-data', 'wp-editor', 'wp-edit-post' ], (string) @filemtime( LB_DIR . 'assets/js/duplicate.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script(
			'lb-duplicate',
			'window.lbDuplicate = ' . wp_json_encode(
				[
					'url'    => $can ? self::link( $post->ID ) : '',
					'source' => $source,
					'i18n'   => [
						'label'  => __( 'Duplicate', 'launch-builder' ),
						'dirty'  => __( 'You have unsaved changes. The copy is made from the last saved version. Continue?', 'launch-builder' ),
						/* translators: %d: post ID */
						'copyOf' => __( 'Copy of #%d', 'launch-builder' ),
					],
				]
			) . ';',
			'before'
		);
	}

	/** { id, title, url } of the post this one was copied from (url only when the user can edit it), or null. */
	private static function source_info( \WP_Post $post ): ?array {
		$src_id = (int) get_post_meta( $post->ID, self::META, true );
		$src = $src_id ? get_post( $src_id ) : null;
		if ( ! $src ) {
			return null;
		}
		$can = current_user_can( 'edit_post', $src_id );
		return [ 'id' => $src_id, 'title' => $can || current_user_can( 'read_post', $src_id ) ? wp_strip_all_tags( $src->post_title ) : '', 'url' => $can ? (string) get_edit_post_link( $src_id, 'raw' ) : '' ];
	}

	/** Classic editor: "Copy of #ID" in the publish box. */
	public static function submitbox( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$s = self::source_info( $post );
		if ( ! $s ) {
			return;
		}
		/* translators: %d: post ID */
		$label = esc_html( sprintf( __( 'Copy of #%d', 'launch-builder' ), $s['id'] ) );
		$title = '' !== $s['title'] ? ' ' . esc_html( $s['title'] ) : '';
		echo '<div class="misc-pub-section lb-duplicate-of">' . ( $s['url'] ? '<a href="' . esc_url( $s['url'] ) . '">' . $label . '</a>' : $label ) . $title . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/* ------------------------------------------------------------------ settings screen */

	public static function menu(): void {
		add_submenu_page( Admin::SLUG, __( 'Duplicate', 'launch-builder' ), __( 'Duplicate', 'launch-builder' ), 'manage_options', self::PAGE, [ __CLASS__, 'page' ] );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		$on = self::ui_types();
		echo '<div class="wrap"><h1>' . esc_html__( 'Duplicate', 'launch-builder' ) . '</h1>';
		echo '<p>' . esc_html__( 'A "Duplicate" link on list rows, in the bulk actions, the admin bar and the block editor menu creates a draft copy. Copies keep content, layout, categories, featured image and template; they never keep the source\'s Launch Builder key, edit locks, old URLs or SEO canonical, and Kadence block IDs are renewed so styles never clash.', 'launch-builder' ) . '</p>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'launch-builder' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_duplicate_settings">';
		wp_nonce_field( 'lb_duplicate_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'launch-builder' ) . '</th><td><fieldset>';
		foreach ( self::candidate_types() as $name => $pto ) {
			printf( '<label style="display:inline-block;min-width:220px;margin:0 12px 6px 0"><input type="checkbox" name="types[]" value="%s" %s> %s <code>%s</code></label>', esc_attr( $name ), checked( in_array( $name, $on, true ), true, false ), esc_html( $pto->labels->name ), esc_html( $name ) );
		}
		echo '<p class="description">' . esc_html__( 'Kadence Elements, Advanced Pages and Conversions keep their own Duplicate link; Launch Builder removes its identity keys from those copies and renews their block IDs.', 'launch-builder' ) . '</p></fieldset></td></tr>';
		printf( '<tr><th scope="row"><label for="lb-dup-suffix">%s</label></th><td><input id="lb-dup-suffix" name="suffix" type="text" class="regular-text" value="%s" placeholder="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Title suffix', 'launch-builder' ), esc_attr( null === $s['suffix'] ? __( '(Copy)', 'launch-builder' ) : $s['suffix'] ), esc_attr__( '(Copy)', 'launch-builder' ), esc_html__( 'Added to the title of every copy. Leave it empty for no suffix.', 'launch-builder' ) );
		echo '<tr><th scope="row">' . esc_html__( 'SEO of copies', 'launch-builder' ) . '</th><td><fieldset>';
		printf( '<label><input type="radio" name="seo" value="reset" %s> %s</label><br>', checked( 'reset', $s['seo'], false ), esc_html__( 'Reset: no SEO title, description or focus keyword (the post-type templates apply). Recommended.', 'launch-builder' ) );
		printf( '<label><input type="radio" name="seo" value="keep" %s> %s</label>', checked( 'keep', $s['seo'], false ), esc_html__( 'Keep them (the canonical URL and scores are always left out).', 'launch-builder' ) );
		echo '</fieldset></td></tr>';
		printf( '<tr><th scope="row"><label for="lb-dup-exclude">%s</label></th><td><textarea id="lb-dup-exclude" name="exclude" rows="4" class="large-text code">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Also leave out', 'launch-builder' ), esc_textarea( implode( "\n", $s['exclude'] ) ), esc_html__( 'Meta keys never copied, one per line. End a line with * to match a prefix (for example _my_plugin_*).', 'launch-builder' ) );
		echo '<tr><th scope="row">' . esc_html__( 'After duplicating', 'launch-builder' ) . '</th><td><fieldset>';
		printf( '<label><input type="radio" name="redirect" value="edit" %s> %s</label><br>', checked( 'edit', $s['redirect'], false ), esc_html__( 'Open the copy in the editor', 'launch-builder' ) );
		printf( '<label><input type="radio" name="redirect" value="list" %s> %s</label>', checked( 'list', $s['redirect'], false ), esc_html__( 'Stay on the list', 'launch-builder' ) );
		echo '</fieldset></td></tr>';
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="admin_bar" value="1" %s> %s</label></td></tr>', esc_html__( 'Admin bar', 'launch-builder' ), checked( $s['admin_bar'], true, false ), esc_html__( 'Show "Duplicate" under Edit in the admin bar', 'launch-builder' ) );
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		$c = self::conflicts();
		if ( $c ) {
			echo '<h2>' . esc_html__( 'Other duplicate plugins', 'launch-builder' ) . '</h2><ul>';
			foreach ( $c as $row ) {
				echo '<li>' . esc_html( $row['name'] ) . ' <code>' . esc_html( $row['plugin'] ) . '</code></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_duplicate_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$candidates = self::candidate_types();
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ?? [] ) ), static function ( $t ) use ( $candidates ) { return isset( $candidates[ $t ] ); } ) );
		$exclude = array_values( array_unique( array_filter( array_map( 'trim', (array) preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['exclude'] ?? '' ) ) ), [ __CLASS__, 'valid_pattern' ] ) ) );
		update_option(
			self::OPT,
			[
				'types'     => $types,
				'suffix'    => sanitize_text_field( (string) wp_unslash( $_POST['suffix'] ?? '' ) ),
				'seo'       => 'keep' === ( $_POST['seo'] ?? '' ) ? 'keep' : 'reset',
				'exclude'   => array_slice( $exclude, 0, 100 ),
				'redirect'  => 'list' === ( $_POST['redirect'] ?? '' ) ? 'list' : 'edit',
				'admin_bar' => ! empty( $_POST['admin_bar'] ),
			],
			false
		);
		self::reset();
		wp_safe_redirect( add_query_arg( 'updated', 1, Admin::url( self::PAGE ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ tool, site state, playbook, audit */

	public static function define_tools(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$b = [ 'type' => 'boolean' ];
		Abilities::add(
			'duplicate-post',
			'Duplicate a post, page, service or area',
			'Create copies of posts, pages, services (lb_service), service areas (lb_area), synced patterns and Kadence elements. A copy starts as a draft titled "<title> (Copy)" with an empty slug, keeps content, layout, categories, featured image and template, and never keeps the source\'s _lb_key, edit locks, old slugs or SEO canonical; with seo=reset (default) its SEO title/description/focus keyword are cleared too. Kadence block IDs are re-keyed (the old -> new map is returned). Give post_id (or ids for several sources, or count for several copies of one), and optionally title, slug, status, key (a new _lb_key so a later brief run adopts the copy) and overrides { excerpt, parent, category }. dry_run reports what would be copied. Returns the new ids with edit and preview URLs; per-item errors do not stop the batch.',
			[
				'properties' => [
					'post_id'        => [ 'type' => 'integer', 'description' => 'Source post ID.' ],
					'id'             => [ 'type' => 'integer', 'description' => 'Alias of post_id.' ],
					'ids'            => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'minItems' => 1, 'maxItems' => self::MAX_TOOL, 'description' => 'Several sources (one copy each). Not with title, slug, key or count.' ],
					'count'          => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TOOL, 'description' => 'Copies of one source (default 1). Copies after the first get " 2", " 3"... after the title.' ],
					'title'          => [ 'type' => 'string', 'maxLength' => 200 ],
					'slug'           => [ 'type' => 'string', 'maxLength' => 200, 'description' => 'Made unique if taken (warning slug_adjusted). Default: empty until published.' ],
					'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'pending', 'publish' ], 'description' => 'Default draft. publish only after the copy is complete.' ],
					'parent'         => [ 'type' => 'integer', 'description' => 'Hierarchical types (pages, areas): new parent ID, 0 = top level.' ],
					'author'         => [ 'type' => 'string', 'enum' => [ 'me', 'original' ], 'description' => 'Default me.' ],
					'seo'            => [ 'type' => 'string', 'enum' => [ 'reset', 'keep' ], 'description' => 'Default reset (from the Duplicate settings).' ],
					'featured_image' => $b + [ 'description' => 'Copy the featured image (default true).' ],
					'taxonomies'     => $b + [ 'description' => 'Copy categories/tags/terms (default true).' ],
					'meta'           => $b + [ 'description' => 'Copy custom fields (default true; the featured image and page template are copied either way).' ],
					'rekey_kadence'  => $b + [ 'description' => 'Re-key Kadence block uniqueIDs (default true).' ],
					'exclude_meta'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 50, 'description' => 'Extra meta keys to leave out; a trailing * matches a prefix.' ],
					'key'            => [ 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,64}$', 'description' => 'Set _lb_key on the copy (refused when another post of the type has it).' ],
					'allow_filtered' => $b + [ 'description' => 'For users without unfiltered_html: copy anyway when scripts/iframes/forms would be stripped (default false).' ],
					'dry_run'        => $b + [ 'description' => 'Report what would be copied; write nothing (default false).' ],
					'overrides'      => [
						'type'       => 'object',
						'properties' => [
							'excerpt'  => [ 'type' => 'string', 'description' => 'Summary of the copy.' ],
							'parent'   => [ 'type' => 'integer', 'description' => 'Same as parent.' ],
							'category' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replace the category (lb_service_cat for services, category for posts): existing term IDs, slugs or names.' ],
						],
					],
				],
			],
			[ __CLASS__, 'tool' ],
			'edit_posts'
		);
	}

	/** duplicate-post: { items: [...], errors: [{ source_id, code, message }] }. */
	public static function tool( $input ) {
		$in = is_array( $input ) ? $input : [];
		$single = $in['post_id'] ?? ( $in['id'] ?? null );
		$many = isset( $in['ids'] ) ? (array) $in['ids'] : null;
		if ( ( null === $single ) === ( null === $many ) ) {
			return self::err( 'lb_duplicate_args', 'Give exactly one of post_id (or id) and ids.', 400 );
		}
		$count = isset( $in['count'] ) ? (int) $in['count'] : 1;
		if ( $count < 1 || $count > self::MAX_TOOL ) {
			return self::err( 'lb_duplicate_args', 'count must be 1-' . self::MAX_TOOL . '.', 400 );
		}
		if ( null !== $many ) {
			$ids = array_values( array_unique( array_map( 'absint', $many ) ) );
			if ( ! $ids || count( $ids ) > self::MAX_TOOL || in_array( 0, $ids, true ) ) {
				return self::err( 'lb_duplicate_args', 'ids must hold 1-' . self::MAX_TOOL . ' post IDs.', 400 );
			}
			foreach ( [ 'title', 'slug', 'key' ] as $k ) {
				if ( isset( $in[ $k ] ) && '' !== $in[ $k ] ) {
					return self::err( 'lb_duplicate_args', $k . ' works with a single post_id only.', 400 );
				}
			}
			if ( 1 !== $count ) {
				return self::err( 'lb_duplicate_args', 'count works with a single post_id only.', 400 );
			}
		} else {
			$ids = [ absint( $single ) ];
			if ( ! $ids[0] ) {
				return self::err( 'lb_duplicate_args', 'post_id must be a post ID.', 400 );
			}
			if ( $count > 1 && isset( $in['key'] ) && '' !== $in['key'] ) {
				return self::err( 'lb_duplicate_args', 'key gives one copy an identity: use it with count 1.', 400 );
			}
		}
		$ov = isset( $in['overrides'] ) && is_array( $in['overrides'] ) ? $in['overrides'] : [];
		if ( isset( $ov['parent'], $in['parent'] ) && (int) $ov['parent'] !== (int) $in['parent'] ) {
			return self::err( 'lb_duplicate_args', 'parent and overrides.parent differ.', 400 );
		}
		if ( isset( $in['exclude_meta'] ) && ( ! is_array( $in['exclude_meta'] ) || count( $in['exclude_meta'] ) > 50 ) ) {
			return self::err( 'lb_duplicate_args', 'exclude_meta holds at most 50 keys.', 400 );
		}
		$args = [ 'context' => 'tool' ];
		foreach ( [ 'title', 'slug', 'status', 'author', 'seo', 'key', 'exclude_meta', 'featured_image', 'taxonomies', 'meta', 'rekey_kadence', 'allow_filtered' ] as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$args[ $k ] = $in[ $k ];
			}
		}
		if ( array_key_exists( 'parent', $in ) || array_key_exists( 'parent', $ov ) ) {
			$args['parent'] = (int) ( $ov['parent'] ?? $in['parent'] );
		}
		if ( isset( $ov['excerpt'] ) ) {
			$args['excerpt'] = (string) $ov['excerpt'];
		}
		if ( isset( $ov['category'] ) ) {
			$args['category'] = is_array( $ov['category'] ) ? $ov['category'] : [ $ov['category'] ];
		}
		$dry = ! empty( $in['dry_run'] );
		$items = [];
		$errors = [];
		$entries = false;
		foreach ( $ids as $id ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$r = $dry ? self::plan( $id, $args + [ 'number' => $n ] ) : self::run( $id, $args + [ 'number' => $n ] );
				if ( is_wp_error( $r ) ) {
					$errors[] = [ 'source_id' => $id, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ];
					if ( in_array( $r->get_error_code(), [ 'lb_duplicate_forbidden', 'lb_duplicate_not_found', 'lb_duplicate_type', 'lb_duplicate_status', 'lb_duplicate_filtered', 'lb_duplicate_key_taken' ], true ) ) {
						break; // The same answer for every further copy of this source.
					}
					continue;
				}
				$items[] = $r;
				$entries = $entries || ( ! $dry && 'publish' === $r['status'] && in_array( $r['type'], [ 'lb_service', 'lb_area' ], true ) );
			}
		}
		$out = [ 'items' => $items, 'errors' => $errors ];
		if ( $dry ) {
			$out['dry_run'] = true;
		}
		if ( $entries && method_exists( Nav::class, 'flush' ) ) {
			$out['menus_updated'] = Nav::flush();
		}
		return $out;
	}

	public static function site_state( array &$state ): void {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'draft'", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$state['duplicates'] = [ 'enabled_types' => self::ui_types(), 'tool_types' => self::tool_types(), 'drafts_pending' => $pending ];
		$c = self::conflicts();
		if ( $c ) {
			foreach ( $c as $row ) {
				$state['conflicts'][] = [ 'module' => 'duplicate', 'plugin' => $row['plugin'], 'level' => $row['level'], 'advice' => 'critical' === $row['level'] ? 'Deactivate and delete it: SQL injection, private-post disclosure and CSRF by any logged-in user. Use duplicate-post / the Duplicate link instead.' : 'A second Duplicate link; consider deactivating one.' ];
			}
		}
	}

	public static function playbook( array &$pb ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$pb['duplicate'] = 'To create a new service, area or page from an existing one: duplicate-post { post_id, title, slug, key, seo: "reset" } (status draft by default), then rewrite its text with add-text / apply-pattern and its SEO with the SEO tools, then publish. Never publish a copy that still has the source\'s title or text. Copies never keep the source\'s _lb_key, so later LB runs update the original, not the copy; pass key to give the copy its own. Kadence block IDs are re-keyed automatically (rekeyed map in the result); shared_refs lists forms/synced patterns/menus the copy still points at.';
		$pb['tools_by_job']['Duplicate'] = 'duplicate-post';
	}

	public static function audit_checks(): array {
		if ( ! self::enabled() ) {
			return [];
		}
		return [
			'duplicate_stale_drafts' => [ 'site', [ __CLASS__, 'audit_stale' ] ],
			'duplicate_same_title'   => [ 'site', [ __CLASS__, 'audit_same_title' ] ],
		];
	}

	/** Draft copies nobody has touched for 30 days. */
	public static function audit_stale( array $args = [] ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_type, p.post_title FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'draft' AND p.post_modified_gmt < %s ORDER BY p.ID LIMIT 50", self::META, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'severity' => 'info', 'code' => 'duplicate_stale_drafts', 'object_type' => $r->post_type, 'id' => (int) $r->ID, 'url' => (string) get_edit_post_link( (int) $r->ID, 'raw' ), 'message' => 'Draft copy "' . $r->post_title . '" has not been edited for 30 days.', 'fix' => 'Finish and publish it, or trash it.' ];
		}
		return $out;
	}

	/** Published copies whose title still equals their source's. */
	public static function audit_same_title( array $args = [] ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_type, p.post_title, s.ID AS src FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s INNER JOIN {$wpdb->posts} s ON s.ID = m.meta_value WHERE p.post_status = 'publish' AND s.post_title = p.post_title ORDER BY p.ID LIMIT 50", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'severity' => 'warning', 'code' => 'duplicate_same_title', 'object_type' => $r->post_type, 'id' => (int) $r->ID, 'url' => (string) get_permalink( (int) $r->ID ), 'message' => 'Published copy #' . (int) $r->ID . ' has the same title as its source #' . (int) $r->src . '.', 'fix' => 'Give the copy its own title, text and SEO.' ];
		}
		return $out;
	}
}
