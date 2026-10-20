<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Services and service areas: the two post types the site's lists, menus and URLs are built from.
 *
 *  lb_service (hierarchical)  a service and its sub-services, e.g. Air Conditioning > Installation and Replacement.
 *                             URLs /air-conditioning/installation-and-replacement/ come from the URLs module (permastructure
 *                             /%parent%/%postname%/); WordPress alone serves them under /services/.
 *  lb_area (hierarchical)     a service area (/service-areas/rockledge-fl/) and its area service pages: children that
 *                             carry _lb_service, the service they are about (/service-areas/rockledge-fl/ac-installation/,
 *                             "AC Installation in Rockledge, FL"). An area can also sit under another area (a county).
 *
 * Lists of them are Kadence Query Loops placed by Designs; this class owns the types, their fields, finding entries by
 * path and the tools that add them. Fields: the summary is the excerpt for every type (_lb_summary is an alias of it, so
 * a Meta Box field or a REST client using that id keeps working); _lb_icon (a Kadence icon name, case kept) on both;
 * _lb_service on area service pages. When Meta Box registers a field with one of these ids, the Card details box leaves
 * that field to it.
 *
 * Registration: Launch Builder registers the two types only when nothing has yet. When Meta Box's post types hold them
 * (Meta_Box_Sync puts them there, init priority 0), its settings win, so they are edited in Meta Box, except what the
 * lists, URLs and editors here rely on (type_args()): public, in REST, no archive (the hubs are pages), hierarchical, the
 * supports in KEEP_SUPPORTS and a URL base (Meta Box's, else the default) passed through launch_builder_entry_rewrite_args.
 */
final class Entries {

	const SERVICE = 'lb_service';
	const AREA    = 'lb_area';
	const FLUSHED = 'launch_builder_entries_flushed';

	/** The service categories of sites built before v2 (migrate_categories() turns them into parent services). */
	const LEGACY_CAT = 'lb_service_cat';

	/** Entries handled per add-service / add-area call (filter launch_builder_entries_batch); the rest is reported. */
	const BATCH = 100;

	/** Supports services and areas always keep, whoever registers them (summary = excerpt, order, image, meta in REST). */
	const KEEP_SUPPORTS = [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'custom-fields' ];

	/** URL base of each type when the registration that wins gives none (before launch_builder_entry_rewrite_args). */
	const BASES = [ self::SERVICE => 'services', self::AREA => 'service-areas' ];

	/** @var array<int,bool> posts whose REST request also sets the excerpt (the excerpt wins over the _lb_summary alias). */
	private static $rest_excerpt = [];

	/** @var array<string,\WP_Post[]> query results of this request, by args hash. */
	private static $memo = [];

	/** @var bool register() is registering (its own arguments pass type_args() untouched). */
	private static $registering = false;

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush' ], 99 );
		add_filter( 'register_post_type_args', [ __CLASS__, 'type_args' ], 20, 2 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
		// _lb_summary is an alias of the excerpt.
		add_filter( 'get_post_metadata', [ __CLASS__, 'summary_get' ], 10, 4 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'summary_set' ], 10, 4 );
		add_filter( 'add_post_metadata', [ __CLASS__, 'summary_set' ], 10, 4 );
		add_filter( 'delete_post_metadata', [ __CLASS__, 'summary_delete' ], 10, 5 );
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'rest_done' ] );
		// Changes lists and menus show: page caches, the query memo and Nav's auto items.
		add_action( 'post_updated', [ __CLASS__, 'on_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_status' ], 10, 3 );
		add_action( 'deleted_post', [ __CLASS__, 'on_deleted' ], 10, 2 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_meta' ], 10, 3 );
		}
		add_action( 'rwmb_after_save_post', [ __CLASS__, 'on_meta_box_saved' ] );
		add_action( 'template_redirect', [ __CLASS__, 'old_path_redirect' ], 9 );
		Migrate::register_step( 'entries-summary', [ __CLASS__, 'migrate_summary' ] );
		Migrate::register_step( 'entries-categories', [ __CLASS__, 'migrate_categories' ], [ 'after' => [ 'entries-summary' ] ] );
	}

	/* ------------------------------------------------------------------ registration */

	/**
	 * How Launch Builder registers its two types, in registration order: name => { kind (post_type), args }. register()
	 * uses it, and Meta_Box_Sync builds Meta Box's copies from it.
	 */
	public static function definitions(): array {
		$labels = static function ( string $one, string $many ): array {
			return [ 'name' => $many, 'singular_name' => $one, 'menu_name' => $many, 'all_items' => 'All ' . strtolower( $many ), 'add_new_item' => 'Add ' . strtolower( $one ), 'edit_item' => 'Edit ' . strtolower( $one ), 'new_item' => 'New ' . strtolower( $one ), 'view_item' => 'View ' . strtolower( $one ), 'search_items' => 'Search ' . strtolower( $many ), 'not_found' => 'None yet', 'parent_item_colon' => 'Parent ' . strtolower( $one ) . ':' ];
		};
		// Filter launch_builder_entry_rewrite_args ( array rewrite, string post type ): URL bases (rewrite rules are flushed
		// by whoever changes them).
		$rewrite = static function ( string $slug, string $object ): array {
			return (array) apply_filters( 'launch_builder_entry_rewrite_args', [ 'slug' => $slug, 'with_front' => false ], $object );
		};
		$supports = [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'custom-fields' ];
		return [
			self::SERVICE => [ 'kind' => 'post_type', 'args' => [
				'labels' => $labels( 'Service', 'Services' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-hammer', 'menu_position' => 21,
				'supports' => $supports, 'has_archive' => false, 'hierarchical' => true, 'rewrite' => $rewrite( self::BASES[ self::SERVICE ], self::SERVICE ),
			] ],
			self::AREA    => [ 'kind' => 'post_type', 'args' => [
				'labels' => $labels( 'Service area', 'Service areas' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-location-alt', 'menu_position' => 22,
				'supports' => $supports, 'has_archive' => false, 'hierarchical' => true, 'rewrite' => $rewrite( self::BASES[ self::AREA ], self::AREA ),
			] ],
		];
	}

	/**
	 * One of definitions() as WordPress would hold it, without registering it: a WP_Post_Type with every label filled in.
	 * Null for another name.
	 */
	public static function definition_object( string $name ): ?\WP_Post_Type {
		$def = self::definitions()[ $name ] ?? null;
		if ( ! $def ) {
			return null;
		}
		self::$registering = true; // Launch Builder's own arguments: type_args() leaves them as they are.
		$object = new \WP_Post_Type( $name, $def['args'] );
		self::$registering = false;
		return $object;
	}

	public static function register(): void {
		// A type that is registered already (Meta Box, init 0) keeps that registration; see type_args().
		self::$registering = true;
		foreach ( self::definitions() as $name => $def ) {
			if ( ! post_type_exists( $name ) ) {
				register_post_type( $name, $def['args'] );
			}
		}
		self::$registering = false;
		add_post_type_support( 'page', 'excerpt' ); // The summary store of every type (page.summary, page heroes, SEO descriptions).
		$can = static function () { return current_user_can( 'edit_posts' ); };
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			register_post_meta( $pt, '_lb_summary', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $can, 'description' => 'Alias of the excerpt.' ] );
			register_post_meta( $pt, '_lb_icon', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => [ __CLASS__, 'clean_icon' ], 'auth_callback' => $can ] );
		}
		register_post_meta( self::AREA, '_lb_service', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'absint', 'auth_callback' => $can, 'description' => 'Area service pages: the service the page is about.' ] );
		foreach ( self::summary_types() as $t ) {
			add_action( 'rest_insert_' . $t, [ __CLASS__, 'rest_excerpt' ], 10, 2 );
		}
	}

	/**
	 * register_post_type_args: services and areas registered by something else (Meta Box) keep its settings, except what
	 * Launch Builder relies on. register() itself passes untouched (its own arguments already are these).
	 */
	public static function type_args( $args, $post_type ) {
		if ( self::$registering || ! is_array( $args ) || ! in_array( $post_type, [ self::SERVICE, self::AREA ], true ) ) {
			return $args;
		}
		$args = array_merge( $args, [ 'public' => true, 'publicly_queryable' => true, 'show_ui' => true, 'show_in_rest' => true, 'has_archive' => false, 'hierarchical' => true ] );
		$args['supports'] = array_values( array_unique( array_merge( is_array( $args['supports'] ?? null ) ? $args['supports'] : [], self::KEEP_SUPPORTS ) ) );
		$args['rewrite'] = self::kept_rewrite( $args['rewrite'] ?? null, (string) $post_type );
		return $args;
	}

	/** A registration's rewrite with a URL base (its own, else BASES), through launch_builder_entry_rewrite_args. */
	private static function kept_rewrite( $rewrite, string $object ): array {
		$rewrite = is_array( $rewrite ) ? $rewrite : [];
		$base = trim( (string) ( $rewrite['slug'] ?? '' ), '/' );
		$rewrite['slug'] = '' !== $base ? $base : self::BASES[ $object ];
		$rewrite['with_front'] = ! empty( $rewrite['with_front'] );
		return (array) apply_filters( 'launch_builder_entry_rewrite_args', $rewrite, $object );
	}

	/** New permalinks need one rewrite flush, once per plugin version (and after Meta Box takes a type over). */
	public static function maybe_flush(): void {
		if ( get_option( self::FLUSHED ) !== LB_VERSION . '-1' ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSHED, LB_VERSION . '-1', false );
		}
	}

	/* ------------------------------------------------------------------ summary = excerpt (_lb_summary is an alias) */

	/** Post types whose summary is the excerpt (filter launch_builder_summary_post_types). */
	public static function summary_types(): array {
		return (array) apply_filters( 'launch_builder_summary_post_types', [ self::SERVICE, self::AREA, 'page', 'post' ] );
	}

	private static function summary_post( $object_id ): ?\WP_Post {
		$p = $object_id ? get_post( (int) $object_id ) : null;
		return $p instanceof \WP_Post && in_array( $p->post_type, self::summary_types(), true ) ? $p : null;
	}

	/** A _lb_summary row written before the summary moved to the excerpt (read without the alias). */
	private static function legacy_summary( int $id ): string {
		$all = wp_cache_get( $id, 'post_meta' );
		if ( ! is_array( $all ) ) {
			$all = update_meta_cache( 'post', [ $id ] );
			$all = (array) ( $all[ $id ] ?? [] );
		}
		$v = isset( $all['_lb_summary'][0] ) ? maybe_unserialize( $all['_lb_summary'][0] ) : '';
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** get_post_metadata: _lb_summary reads the excerpt (a not yet migrated row while the excerpt is empty). */
	public static function summary_get( $value, $object_id, $meta_key, $single ) {
		if ( '_lb_summary' !== $meta_key ) {
			return $value;
		}
		$p = self::summary_post( $object_id );
		if ( ! $p ) {
			return $value;
		}
		$s = trim( (string) $p->post_excerpt );
		if ( '' === $s ) {
			$s = self::legacy_summary( (int) $p->ID );
		}
		return $single ? [ $s ] : ( '' === $s ? [] : [ $s ] );
	}

	/** update_post_metadata / add_post_metadata: writing _lb_summary writes the excerpt. */
	public static function summary_set( $check, $object_id, $meta_key, $meta_value ) {
		if ( '_lb_summary' !== $meta_key ) {
			return $check;
		}
		$p = self::summary_post( $object_id );
		if ( ! $p ) {
			return $check;
		}
		if ( ! empty( self::$rest_excerpt[ $p->ID ] ) ) {
			return true; // The same REST request sets the excerpt: that is the edit (the block editor sends all meta back).
		}
		$value = is_scalar( $meta_value ) ? sanitize_textarea_field( (string) $meta_value ) : '';
		if ( self::stale_box_write( $p, $value ) ) {
			return true;
		}
		self::write_excerpt( $p, $value );
		return true;
	}

	/**
	 * The block editor saves over REST first and posts the legacy meta boxes (Meta Box fields) right after
	 * (?meta-box-loader=1). A _lb_summary field in such a box still holds the value the page was loaded with, so when the
	 * REST save just changed the excerpt (the Excerpt panel), that older value must not overwrite it.
	 */
	private static function stale_box_write( \WP_Post $p, string $value ): bool {
		if ( empty( $_GET['meta-box-loader'] ) || $value === (string) $p->post_excerpt ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		$key = 'lb_rest_excerpt_' . $p->ID;
		if ( (int) get_transient( $key ) !== get_current_user_id() || ! get_current_user_id() ) {
			return false;
		}
		delete_transient( $key );
		return true;
	}

	/** delete_post_metadata: deleting _lb_summary empties the excerpt (and any legacy row goes too). */
	public static function summary_delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		if ( '_lb_summary' !== $meta_key || $delete_all ) {
			return $check;
		}
		$p = self::summary_post( $object_id );
		if ( ! $p ) {
			return $check;
		}
		if ( empty( self::$rest_excerpt[ $p->ID ] ) && ! self::stale_box_write( $p, '' ) ) {
			self::write_excerpt( $p, '' );
		}
		return '' !== self::legacy_summary( (int) $p->ID ) ? $check : true; // No row to delete still counts as done (REST checks it).
	}

	/** rest_insert_{type}: this request sets the excerpt itself, so a _lb_summary value in its meta is ignored. */
	public static function rest_excerpt( $post, $request ): void {
		if ( $post instanceof \WP_Post && $request instanceof \WP_REST_Request && $request->has_param( 'excerpt' ) ) {
			self::$rest_excerpt[ $post->ID ] = true;
			if ( get_current_user_id() ) { // For the meta-box-loader request that follows (stale_box_write).
				set_transient( 'lb_rest_excerpt_' . $post->ID, get_current_user_id(), 5 * MINUTE_IN_SECONDS );
			}
		}
	}

	/** rest_request_after_callbacks: the excerpt flags belong to one REST request (batch and internal requests share PHP). */
	public static function rest_done( $response ) {
		self::$rest_excerpt = [];
		return $response;
	}

	/** Store an excerpt without the side effects of a full post save (no revision, no modified date). */
	private static function write_excerpt( \WP_Post $p, string $value ): void {
		global $wpdb;
		if ( (string) $p->post_excerpt === $value ) {
			return;
		}
		$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $value ], [ 'ID' => $p->ID ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $p->ID );
		if ( in_array( $p->post_type, [ self::SERVICE, self::AREA ], true ) && 'publish' === $p->post_status ) {
			self::changed( false );
		}
	}

	/** The card summary: the excerpt, else (not yet migrated) the old _lb_summary row, else the start of the text. */
	public static function summary( \WP_Post $p ): string {
		$s = trim( (string) $p->post_excerpt );
		if ( '' === $s ) {
			$s = self::legacy_summary( (int) $p->ID );
		}
		if ( '' === $s ) {
			$s = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 22 );
		}
		return Tokens::for_entry( $s, $p );
	}


	/* ------------------------------------------------------------------ admin fields (plain post meta) */

	/** Kadence icon names keep their case (fe_mapPin): letters, digits and underscores only, else ''. */
	public static function clean_icon( $value ): string {
		$v = trim( is_scalar( $value ) ? (string) $value : '' );
		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $v ) ? $v : '';
	}

	/** Does Meta Box register a field with this id for this post type? (Then it edits the field, not LB's box.) */
	public static function meta_box_owns( string $field_id, string $post_type ): bool {
		$owned = false;
		if ( function_exists( 'rwmb_get_registry' ) ) {
			$reg = rwmb_get_registry( 'field' );
			$owned = is_object( $reg ) && method_exists( $reg, 'get' ) && (bool) $reg->get( $field_id, $post_type, 'post' );
		}
		return (bool) apply_filters( 'launch_builder_field_owned_elsewhere', $owned, $field_id, $post_type );
	}

	/** Fields the Card details box edits for a type (field => meta key), minus those Meta Box owns. */
	public static function box_fields( string $post_type ): array {
		$fields = [ 'icon' => '_lb_icon' ];
		if ( self::AREA === $post_type ) {
			$fields['service'] = '_lb_service';
		}
		return array_filter( $fields, static function ( $meta ) use ( $post_type ) { return ! self::meta_box_owns( $meta, $post_type ); } );
	}

	public static function meta_boxes(): void {
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			if ( self::box_fields( $pt ) ) {
				add_meta_box( 'lb_entry', __( 'Card details', 'launch-builder' ), [ __CLASS__, 'box' ], $pt, 'side' );
			}
		}
	}

	public static function box( \WP_Post $post ): void {
		$fields = self::box_fields( $post->post_type );
		wp_nonce_field( 'lb_entry', 'lb_entry_nonce' );
		echo '<p class="description">' . esc_html__( 'The short summary on cards and in the page banner is the Excerpt. The order in every list is the Order box, then the title.', 'launch-builder' ) . '</p>';
		if ( function_exists( 'rwmb_meta' ) ) {
			echo '<p class="description">' . esc_html__( 'Using Meta Box? Add Launch Builder\'s fields under Meta Box > Custom Fields and Meta Box edits them instead of this box.', 'launch-builder' ) . '</p>';
		}
		if ( isset( $fields['icon'] ) ) {
			echo '<p><label><strong>' . esc_html__( 'Icon', 'launch-builder' ) . '</strong> ' . esc_html__( '(optional Kadence icon name, e.g. fe_settings or fe_mapPin)', 'launch-builder' ) . '<br><input type="text" name="lb_icon" style="width:100%" value="' . esc_attr( (string) get_post_meta( $post->ID, '_lb_icon', true ) ) . '"></label></p>';
		}
		if ( isset( $fields['service'] ) && $post->post_parent ) {
			$cur = (int) get_post_meta( $post->ID, '_lb_service', true );
			echo '<p><label><strong>' . esc_html__( 'This page is about', 'launch-builder' ) . '</strong><br><select name="lb_service" style="width:100%"><option value="0">' . esc_html__( 'Not a service page (a sub-area)', 'launch-builder' ) . '</option>';
			foreach ( self::query( 'service', [ 'limit' => 0, 'status' => 'any' ] ) as $s ) {
				echo '<option value="' . (int) $s->ID . '"' . selected( $cur, (int) $s->ID, false ) . '>' . esc_html( self::label( $s ) ) . '</option>';
			}
			echo '</select></label></p>';
		}
	}

	/** Saves only the fields this box showed and posted (Meta Box fields with the same ids are left to Meta Box). */
	public static function save_meta( int $id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ self::SERVICE, self::AREA ], true ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || empty( $_POST['lb_entry_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lb_entry_nonce'] ) ), 'lb_entry' ) || ! current_user_can( 'edit_post', $id ) ) { // phpcs:ignore
			return;
		}
		$fields = self::box_fields( $post->post_type );
		if ( isset( $fields['icon'], $_POST['lb_icon'] ) ) { // phpcs:ignore
			update_post_meta( $id, '_lb_icon', self::clean_icon( wp_unslash( $_POST['lb_icon'] ) ) ); // phpcs:ignore
		}
		if ( isset( $fields['service'], $_POST['lb_service'] ) ) { // phpcs:ignore
			$sid = self::service_id( absint( $_POST['lb_service'] ) ); // phpcs:ignore
			$sid ? update_post_meta( $id, '_lb_service', $sid ) : delete_post_meta( $id, '_lb_service' );
		}
	}

	/** A service's name with its parents, for pickers: "Air Conditioning > Repair". */
	private static function label( \WP_Post $p ): string {
		$names = [ $p->post_title ];
		foreach ( get_post_ancestors( $p ) as $a ) {
			array_unshift( $names, get_the_title( $a ) );
		}
		return implode( ' > ', $names );
	}

	/* ------------------------------------------------------------------ area service pages */

	/** Is this an area service page (a child of an area that names the service it is about)? */
	public static function is_area_service( $post ): bool {
		$p = get_post( $post );
		return $p instanceof \WP_Post && self::AREA === $p->post_type && $p->post_parent && (int) get_post_meta( $p->ID, '_lb_service', true ) > 0;
	}

	/** The title of an area service page: "AC Installation in Rockledge, FL" (filter launch_builder_area_service_title). */
	public static function area_service_title( \WP_Post $service, \WP_Post $area ): string {
		/* translators: 1: service name, 2: area name */
		return (string) apply_filters( 'launch_builder_area_service_title', sprintf( __( '%1$s in %2$s', 'launch-builder' ), $service->post_title, $area->post_title ), $service, $area );
	}

	/**
	 * Make an area's service pages match a list of services. Each service gets a child page of the area (title "{service}
	 * in {area}", slug = the service's slug, _lb_service = the service, summary = the service's summary until one is
	 * written), created when missing. A page whose service is no longer listed is drafted, never deleted, and comes back
	 * when the service is listed again. Pages someone renamed keep their title.
	 *
	 * @param int[] $service_ids
	 * @return array{created:int[],kept:int[],drafted:int[],published:int[]}
	 */
	public static function sync_area_services( int $area, array $service_ids ): array {
		$out = [ 'created' => [], 'kept' => [], 'drafted' => [], 'published' => [] ];
		$a = get_post( $area );
		if ( ! $a instanceof \WP_Post || self::AREA !== $a->post_type ) {
			return $out;
		}
		$want = array_values( array_unique( array_filter( array_map( 'intval', $service_ids ) ) ) );
		$have = [];
		foreach ( get_posts( [ 'post_type' => self::AREA, 'post_parent' => $area, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => -1, 'meta_key' => '_lb_service' ] ) as $child ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$have[ (int) get_post_meta( $child->ID, '_lb_service', true ) ] = $child;
		}
		$status = 'publish' === $a->post_status ? 'publish' : 'draft';
		foreach ( $want as $i => $sid ) {
			$s = get_post( $sid );
			if ( ! $s instanceof \WP_Post || self::SERVICE !== $s->post_type ) {
				continue;
			}
			if ( isset( $have[ $sid ] ) ) {
				$child = $have[ $sid ];
				if ( 'draft' === $child->post_status && get_post_meta( $child->ID, '_lb_auto_drafted', true ) && 'publish' === $status ) {
					wp_update_post( [ 'ID' => $child->ID, 'post_status' => 'publish' ] );
					delete_post_meta( $child->ID, '_lb_auto_drafted' );
					$out['published'][] = (int) $child->ID;
				} else {
					$out['kept'][] = (int) $child->ID;
				}
				unset( $have[ $sid ] );
				continue;
			}
			$id = wp_insert_post( [ 'post_type' => self::AREA, 'post_parent' => $area, 'post_status' => $status, 'post_title' => self::area_service_title( $s, $a ), 'post_name' => $s->post_name, 'post_excerpt' => (string) $s->post_excerpt, 'menu_order' => $i ], true );
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( (int) $id, '_lb_service', $sid );
				$out['created'][] = (int) $id;
			}
		}
		foreach ( $have as $child ) {
			if ( 'publish' === $child->post_status ) {
				wp_update_post( [ 'ID' => $child->ID, 'post_status' => 'draft' ] );
				update_post_meta( $child->ID, '_lb_auto_drafted', 1 );
				$out['drafted'][] = (int) $child->ID;
			}
		}
		if ( $out['created'] || $out['drafted'] || $out['published'] ) {
			self::changed( true );
		}
		return $out;
	}

	/** The published services an area has pages for (the _lb_service of its published area service pages). */
	public static function area_services( int $area ): array {
		$ids = [];
		foreach ( get_posts( [ 'post_type' => self::AREA, 'post_parent' => $area, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_lb_service', 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] ) as $child ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$ids[] = (int) get_post_meta( $child->ID, '_lb_service', true );
		}
		return array_values( array_filter( $ids ) );
	}

	/* ------------------------------------------------------------------ change tracking (caches, memo, menus) */

	/** Entries changed in a way lists show: purge page caches once, forget the memo, and (menus) mark Nav dirty. */
	public static function changed( bool $menus ): void {
		self::forget();
		Cache::purge_later();
		if ( $menus ) {
			Nav::mark_dirty();
		}
	}

	/** Forget this request's query memo (entries changed). */
	public static function forget(): void {
		self::$memo = [];
	}

	private static function ours( $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, [ self::SERVICE, self::AREA ], true );
	}

	/** post_updated: title, slug, order, parent and status matter to menus; the excerpt and text to lists. */
	public static function on_updated( $id, $after, $before ): void {
		if ( ! self::ours( $after ) || ! $before instanceof \WP_Post || ( 'publish' !== $after->post_status && 'publish' !== $before->post_status ) ) {
			return;
		}
		$menus = false;
		foreach ( [ 'post_title', 'post_name', 'post_status', 'menu_order', 'post_parent' ] as $f ) {
			if ( (string) $after->$f !== (string) $before->$f ) {
				$menus = true;
			}
		}
		$lists = $menus || $after->post_excerpt !== $before->post_excerpt || ( '' === trim( (string) $after->post_excerpt ) && $after->post_content !== $before->post_content );
		if ( 'publish' === $before->post_status && ( $after->post_name !== $before->post_name || (int) $after->post_parent !== (int) $before->post_parent ) ) {
			$old = (string) get_page_uri( $before );
			$new = (string) get_page_uri( $after );
			if ( '' !== $old && $old !== $new ) {
				if ( ! in_array( $old, array_map( 'strval', (array) get_post_meta( (int) $id, '_lb_old_path', false ) ), true ) ) {
					add_post_meta( (int) $id, '_lb_old_path', $old );
				}
				Nav::rename( 'parent', $old, $new );
				do_action( 'launch_builder_entry_moved', (int) $id, $old, $new );
			}
		}
		if ( $lists ) {
			self::changed( $menus );
		}
	}

	/** transition_post_status: an entry was published or left publish (trash, draft, private...). */
	public static function on_status( $new, $old, $post ): void {
		if ( self::ours( $post ) && $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			self::changed( true );
		}
	}

	/** deleted_post: a published entry deleted without going through the trash. */
	public static function on_deleted( $id, $post = null ): void {
		if ( self::ours( $post ) && 'publish' === $post->post_status ) {
			self::changed( true );
		}
	}

	/** Meta that lists show (image, icon, the service of an area service page). */
	public static function on_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, [ '_thumbnail_id', '_lb_icon', '_lb_service' ], true ) ) {
			$p = get_post( (int) $object_id );
			if ( self::ours( $p ) && 'publish' === $p->post_status ) {
				self::changed( false );
			}
		}
	}

	/** Meta Box saved a service or area: its fields can show on cards, so the lists that show it refresh. */
	public static function on_meta_box_saved( $post_id ): void {
		$p = get_post( (int) $post_id );
		if ( self::ours( $p ) && 'publish' === $p->post_status ) {
			self::changed( false );
		}
	}

	/**
	 * 404 under a type's URL base on a path an entry had before it was renamed or moved: 301 to where it is now. (With
	 * the URLs module on, it redirects old URLs itself; this covers sites without it.)
	 */
	public static function old_path_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			$obj = get_post_type_object( $pt );
			$base = trim( (string) ( $obj && is_array( $obj->rewrite ) ? ( $obj->rewrite['slug'] ?? '' ) : '' ), '/' );
			if ( '' === $base || 0 !== strpos( $path . '/', $base . '/' ) ) {
				continue;
			}
			$parts = array_values( array_filter( explode( '/', strtolower( substr( $path, strlen( $base ) ) ) ), 'strlen' ) );
			for ( $n = count( $parts ); $n > 0; $n-- ) { // The longest old path first; the rest of the path follows it.
				$found = get_posts( [ 'post_type' => $pt, 'post_status' => 'publish', 'meta_key' => '_lb_old_path', 'meta_value' => implode( '/', array_slice( $parts, 0, $n ) ), 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( $found ) {
					$rest = array_slice( $parts, $n );
					$target = $rest ? get_page_by_path( get_page_uri( $found[0] ) . '/' . implode( '/', $rest ), OBJECT, $pt ) : $found[0];
					if ( $target instanceof \WP_Post && 'publish' === $target->post_status ) {
						wp_safe_redirect( get_permalink( $target ), 301 );
						exit;
					}
					return;
				}
			}
		}
	}

	/* ------------------------------------------------------------------ finding entries */

	/** A key for brief re-runs: lower case letters, digits and _ - : . / (max 120). */
	public static function clean_key( $key ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_\-:.\/]/', '', strtolower( is_scalar( $key ) ? (string) $key : '' ) ), 0, 120 );
	}

	/** The entry of a type with this _lb_key (any status but trash), or 0. */
	public static function find_by_key( string $post_type, string $key ): int {
		$key = self::clean_key( $key );
		if ( '' === $key ) {
			return 0;
		}
		$p = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $p ? (int) $p[0] : 0;
	}

	/**
	 * An entry by slug among siblings: under $parent when given (0 = top level), else the top-level one, else the only one
	 * with that slug. 0 when not found or ambiguous.
	 */
	private static function find_child( string $post_type, string $slug, ?int $parent ): int {
		if ( '' === $slug ) {
			return 0;
		}
		$ids = get_posts( [ 'post_type' => $post_type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
		$rows = [];
		foreach ( $ids as $id ) {
			$rows[ (int) $id ] = (int) get_post_field( 'post_parent', (int) $id );
		}
		foreach ( $rows as $id => $par ) {
			if ( ( null === $parent ? 0 : $parent ) === $par ) {
				return $id;
			}
		}
		return null === $parent && 1 === count( $rows ) ? (int) array_key_first( $rows ) : 0;
	}

	/**
	 * An entry of a type from a reference: an id, a path ("air-conditioning/repair", "florida/rockledge-fl"), or a slug or
	 * title (the top-level one, else the only one). Any status but trash. 0 when unknown or ambiguous.
	 *
	 * @param int|string $ref
	 */
	public static function entry_id( string $post_type, $ref ): int {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$p = (int) $ref > 0 ? get_post( (int) $ref ) : null; // get_post( 0 ) would be the global post.
			return $p && $post_type === $p->post_type && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		$ref = trim( is_scalar( $ref ) ? (string) $ref : '', " \t/" );
		if ( '' === $ref ) {
			return 0;
		}
		if ( false !== strpos( $ref, '/' ) ) {
			$p = get_page_by_path( implode( '/', array_map( 'sanitize_title', explode( '/', $ref ) ) ), OBJECT, $post_type );
			return $p instanceof \WP_Post && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		return self::find_child( $post_type, sanitize_title( $ref ), null );
	}

	/** @param int|string $ref An area: id, path, slug or title. */
	public static function area_id( $ref ): int {
		return self::entry_id( self::AREA, $ref );
	}

	/** @param int|string $ref A service: id, path, slug or title. */
	public static function service_id( $ref ): int {
		return self::entry_id( self::SERVICE, $ref );
	}

	/* ------------------------------------------------------------------ querying */

	/**
	 * Entries for menus and pickers. $a:
	 *  limit   0 = all, else 1-500 (default 24)
	 *  parent  "top" (top level), an entry id, slug or path (its children), or omitted (all)
	 *  pages   areas: "areas" (default: leave area service pages out), "services" (only them), "all"
	 *  orderby "" | "menu_order" (order, then title) | "title"
	 *  status  "publish" (default) or "any"
	 * An unknown parent lists nothing. Memoised per request.
	 *
	 * @return \WP_Post[]
	 */
	public static function query( string $type, array $a = [] ): array {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$limit = isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? (int) $a['limit'] : 24;
		$args = [ 'post_type' => $pt, 'post_status' => 'any' === ( $a['status'] ?? '' ) ? [ 'publish', 'draft', 'pending', 'private', 'future' ] : 'publish', 'posts_per_page' => $limit <= 0 ? -1 : min( 500, $limit ), 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'no_found_rows' => true, 'ignore_sticky_posts' => true ];
		$parent = $a['parent'] ?? '';
		if ( 'top' === $parent || 0 === $parent || '0' === $parent ) {
			$args['post_parent'] = 0;
		} elseif ( '' !== $parent && null !== $parent ) {
			$pid = self::entry_id( $pt, $parent );
			if ( ! $pid ) {
				return [];
			}
			$args['post_parent'] = $pid;
		}
		if ( self::AREA === $pt ) {
			$pages = (string) ( $a['pages'] ?? 'areas' );
			if ( 'areas' === $pages ) {
				$args['meta_query'] = [ [ 'key' => '_lb_service', 'compare' => 'NOT EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
			} elseif ( 'services' === $pages ) {
				$args['meta_query'] = [ [ 'key' => '_lb_service', 'compare' => 'EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}
		if ( 'title' === ( $a['orderby'] ?? '' ) ) {
			$args['orderby'] = [ 'title' => 'ASC' ];
		}
		$key = md5( (string) wp_json_encode( $args ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = get_posts( $args );
		}
		return self::$memo[ $key ];
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$props = [
			'title'    => [ 'type' => 'string', 'description' => 'Required when the entry is new.' ],
			'slug'     => [ 'type' => 'string' ],
			'key'      => [ 'type' => 'string', 'description' => 'Stable id for re-runs (e.g. from a brief): an entry with this key is updated in place, even when its slug changes (the old URL redirects).' ],
			'parent'   => [ 'type' => 'string', 'description' => 'The entry it sits under: id, slug or path ("air-conditioning", "florida"); "top" moves it to the top level.' ],
			'summary'  => [ 'type' => 'string', 'description' => 'One or two sentences shown on cards and in the page banner (stored as the excerpt).' ],
			'markdown' => [ 'type' => 'string', 'description' => 'The entry text as Markdown (see get-playbook).' ],
			'order'    => [ 'type' => 'integer' ],
			'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'description' => 'New entries default to publish; an update keeps the current status unless this is given.' ],
			'icon'     => [ 'type' => 'string', 'description' => 'Kadence icon name, case kept (fe_settings, fe_mapPin).' ],
			'seo'      => [ 'type' => 'object', 'description' => '{title, description, keyword}' ],
			'items'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Many entries in one call, each with the fields above. At most 100 are handled per call: the result says how many remain (call again with offset).' ],
			'offset'   => [ 'type' => 'integer', 'description' => 'With items: start at this index (from next_offset).' ],
		];
		Abilities::add( 'add-service', 'Add or update a service', 'Creates (or updates, matched by key, else by slug under its parent) a Service. A service with parent is a sub-service (Air Conditioning > Installation and Replacement): it lists under its service and its URL follows it. Every list of services (the sub-service list on a service, menus) picks it up. items = many services in one call (parents first). Returns count, remaining, errors and menus_updated.',
			[ 'properties' => $props ], [ __CLASS__, 'add_service' ], 'edit_pages' );
		Abilities::add( 'add-area', 'Add or update a service area', 'Creates (or updates, matched by key, else by slug under its parent) a Service Area, such as a city. services = the services offered there (ids, slugs or paths): each gets an area service page under the area ("AC Installation in Rockledge, FL" at /service-areas/rockledge-fl/ac-installation/), which the area page lists; a service left out has its page drafted. parent = another area (a county) the area sits under. items = many at once.',
			[ 'properties' => $props + [ 'services' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ], [ __CLASS__, 'add_area' ], 'edit_pages' );
		Abilities::add( 'list-entries', 'List services or service areas', 'The services (with their sub-services) or service areas (with their area service pages) on the site: path, parent, summary, icon, URL and, for area service pages, the service. parent filters ("top" or an entry); pages (areas): areas | services | all. Paginated: limit (max 200) and offset.',
			[ 'properties' => [ 'type' => [ 'type' => 'string', 'enum' => [ 'service', 'area' ] ], 'parent' => [ 'type' => 'string' ], 'pages' => [ 'type' => 'string', 'enum' => [ 'areas', 'services', 'all' ] ], 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ] ], 'limit' => [ 'type' => 'integer' ], 'offset' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'list_entries' ], 'edit_pages', [ 'readonly' => true ] );
		Abilities::add( 'delete-entry', 'Remove a service or service area', 'Moves an entry to the trash (restorable from the admin). Every list and menu stops showing it at once. Its children stay and move up a level.',
			[ 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], [ __CLASS__, 'delete_entry' ], 'edit_pages' );
	}

	private static function upsert( string $type, array $in ) {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$warnings = [];
		$title = sanitize_text_field( is_scalar( $in['title'] ?? '' ) ? (string) ( $in['title'] ?? '' ) : '' );
		$key = self::clean_key( $in['key'] ?? '' );
		$parent = null; // null = not given
		$pref = isset( $in['parent'] ) && is_scalar( $in['parent'] ) ? trim( (string) $in['parent'] ) : '';
		if ( in_array( $pref, [ 'top', '0' ], true ) ) {
			$parent = 0; // Move to (or create at) the top level.
		} elseif ( '' !== $pref ) {
			$parent = self::entry_id( $pt, $in['parent'] );
			if ( ! $parent ) {
				return new \WP_Error( 'lb_parent', 'Unknown parent: ' . sanitize_text_field( $pref ) . ' (give an id, a slug or a path).', [ 'status' => 400 ] );
			}
		}
		$found = '' !== $key ? self::find_by_key( $pt, $key ) : 0;
		$slug = sanitize_title( isset( $in['slug'] ) && is_scalar( $in['slug'] ) && '' !== (string) $in['slug'] ? (string) $in['slug'] : $title );
		if ( ! $found && '' !== $slug ) {
			$found = self::find_child( $pt, $slug, $parent );
			if ( ! $found && null === $parent && count( get_posts( [ 'post_type' => $pt, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 2, 'fields' => 'ids' ] ) ) > 1 ) {
				return new \WP_Error( 'lb_ambiguous', 'Several entries are called "' . $slug . '": give parent (id, slug or path) to say which one.', [ 'status' => 400 ] );
			}
			$other = $found && '' !== $key ? (string) get_post_meta( $found, '_lb_key', true ) : '';
			if ( '' !== $other && $other !== $key ) {
				$found = 0; // That slug belongs to another keyed entry: this key gets its own entry (WordPress makes the slug unique).
			}
		}
		if ( ! $found && '' === $title ) {
			return new \WP_Error( 'lb_title', 'title is required for a new entry.', [ 'status' => 400 ] );
		}
		$before = $found ? get_post( $found ) : null;
		$args = [ 'post_type' => $pt ];
		if ( '' !== $title ) {
			$args['post_title'] = $title;
		}
		if ( ! $found || ( isset( $in['slug'] ) && '' !== $slug ) || ( '' !== $title && ! isset( $in['slug'] ) && '' === $key ) ) {
			$args['post_name'] = $slug; // Found by key: the slug changes only when one is given.
		}
		$status = is_string( $in['status'] ?? null ) && in_array( $in['status'], [ 'publish', 'draft' ], true ) ? $in['status'] : null;
		if ( $status || ! $found ) {
			$args['post_status'] = $status ?: 'publish'; // An update keeps the entry's status unless one is passed.
		}
		if ( isset( $in['order'] ) && is_numeric( $in['order'] ) ) {
			$args['menu_order'] = (int) $in['order'];
		}
		if ( isset( $in['summary'] ) && is_scalar( $in['summary'] ) ) {
			$args['post_excerpt'] = sanitize_text_field( (string) $in['summary'] ); // The one summary store (cards, banner, SEO).
		}
		if ( ! empty( $in['markdown'] ) && is_string( $in['markdown'] ) ) {
			$args['post_content'] = wp_slash( Content::from_markdown( $in['markdown'] ) );
		}
		if ( null !== $parent ) {
			if ( $found && ( $parent === $found || in_array( $found, get_post_ancestors( $parent ), true ) ) ) {
				return new \WP_Error( 'lb_parent', 'An entry cannot sit under itself or one of its own children.', [ 'status' => 400 ] );
			}
			$args['post_parent'] = $parent;
		}
		if ( $found ) {
			$args['ID'] = $found;
			$id = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		if ( '' !== $key ) {
			update_post_meta( $id, '_lb_key', $key );
		}
		if ( isset( $in['icon'] ) ) {
			$icon = self::clean_icon( $in['icon'] );
			if ( '' === $icon && '' !== trim( is_scalar( $in['icon'] ) ? (string) $in['icon'] : '' ) ) {
				$warnings[] = 'icon ignored (the current one is kept): use a Kadence icon name (letters, digits and _), e.g. fe_settings';
			} else {
				update_post_meta( $id, '_lb_icon', $icon ); // '' clears it.
			}
		}
		Compat::write_seo_meta( $id, (array) ( $in['seo'] ?? [] ) ); // The active SEO plugin only; %variables% survive.
		$pages = null;
		if ( self::AREA === $pt && isset( $in['services'] ) && is_array( $in['services'] ) ) {
			$sids = [];
			foreach ( $in['services'] as $ref ) {
				$sid = self::service_id( $ref );
				$sid ? $sids[] = $sid : $warnings[] = 'unknown service: ' . sanitize_text_field( is_scalar( $ref ) ? (string) $ref : '' );
			}
			$pages = self::sync_area_services( $id, $sids );
		}
		$p = get_post( $id );
		$out = [ 'id' => $id, 'title' => $p ? $p->post_title : $title, 'slug' => $p ? $p->post_name : $slug, 'path' => (string) get_page_uri( $id ), 'status' => $p ? $p->post_status : '', 'url' => get_permalink( $id ), 'created' => ! $found ];
		if ( $before && $p && $before->post_name !== $p->post_name ) {
			$out['renamed_from'] = $before->post_name;
		}
		if ( '' !== $key ) {
			$out['key'] = $key;
		}
		if ( null !== $pages ) {
			$out['service_pages'] = array_map( 'count', $pages );
		}
		if ( $warnings ) {
			$out['warnings'] = $warnings;
		}
		return $out;
	}

	private static function add_many( string $type, $input ) {
		$input = (array) $input;
		$items = ! empty( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : [ $input ];
		$max = max( 1, (int) apply_filters( 'launch_builder_entries_batch', self::BATCH ) );
		$offset = ! empty( $input['items'] ) ? max( 0, (int) ( $input['offset'] ?? 0 ) ) : 0;
		$slice = array_slice( $items, $offset, $max );
		$out = [];
		$errors = [];
		foreach ( $slice as $i => $it ) {
			$r = is_array( $it ) ? self::upsert( $type, $it ) : new \WP_Error( 'lb_item', 'Each item must be an object.' );
			if ( is_wp_error( $r ) ) {
				$errors[] = [ 'index' => $offset + $i, 'title' => is_array( $it ) && is_scalar( $it['title'] ?? null ) ? (string) $it['title'] : '', 'error' => $r->get_error_message() ];
				continue;
			}
			$out[] = $r;
		}
		if ( ! $out && $errors ) {
			return new \WP_Error( 'lb_entries', $errors[0]['error'] . ( count( $errors ) > 1 ? ' (+' . ( count( $errors ) - 1 ) . ' more)' : '' ), [ 'status' => 400 ] );
		}
		$remaining = max( 0, count( $items ) - $offset - count( $slice ) );
		$res = [ 'count' => count( $out ), 'remaining' => $remaining, 'entries' => $out ];
		if ( $remaining ) {
			$res['next_offset'] = $offset + count( $slice );
			$res['note'] = sprintf( 'Handled %d of %d items: call again with the same items and offset %d.', count( $slice ), count( $items ), $res['next_offset'] );
		}
		if ( $errors ) {
			$res['errors'] = $errors;
		}
		$res['menus_updated'] = Nav::flush();
		return $res;
	}

	public static function add_service( $input ) {
		return self::add_many( 'service', (array) $input );
	}

	public static function add_area( $input ) {
		return self::add_many( 'area', (array) $input );
	}

	public static function list_entries( $input ) {
		$input = (array) $input;
		$type = 'area' === ( $input['type'] ?? 'service' ) ? 'area' : 'service';
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$limit = max( 1, min( 200, (int) ( $input['limit'] ?? 200 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$status = in_array( $input['status'] ?? 'any', [ 'publish', 'draft', 'any' ], true ) ? ( $input['status'] ?? 'any' ) : 'any';
		$args = [ 'post_type' => $pt, 'post_status' => $status, 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => [ 'parent' => 'ASC', 'menu_order' => 'ASC', 'title' => 'ASC' ], 'ignore_sticky_posts' => true ];
		if ( isset( $input['parent'] ) && '' !== (string) $input['parent'] ) {
			$pid = 'top' === $input['parent'] ? 0 : self::entry_id( $pt, $input['parent'] );
			if ( 'top' !== $input['parent'] && ! $pid ) {
				return new \WP_Error( 'lb_parent', 'Unknown parent.', [ 'status' => 400 ] );
			}
			$args['post_parent'] = $pid;
		}
		$pages = (string) ( $input['pages'] ?? 'all' );
		if ( self::AREA === $pt && 'all' !== $pages ) {
			$args['meta_query'] = [ [ 'key' => '_lb_service', 'compare' => 'services' === $pages ? 'EXISTS' : 'NOT EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$q = new \WP_Query( $args );
		$out = [];
		foreach ( $q->posts as $p ) {
			$row = [ 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'path' => (string) get_page_uri( $p ), 'status' => $p->post_status, 'order' => (int) $p->menu_order, 'url' => get_permalink( $p ), 'summary' => (string) $p->post_excerpt, 'icon' => (string) get_post_meta( $p->ID, '_lb_icon', true ) ];
			$key = (string) get_post_meta( $p->ID, '_lb_key', true );
			if ( '' !== $key ) {
				$row['key'] = $key;
			}
			if ( $p->post_parent ) {
				$row['parent'] = get_the_title( $p->post_parent );
			}
			$sid = self::AREA === $pt ? (int) get_post_meta( $p->ID, '_lb_service', true ) : 0;
			if ( $sid ) {
				$row['service'] = [ 'id' => $sid, 'title' => get_the_title( $sid ) ];
			}
			$out[] = $row;
		}
		$total = (int) $q->found_posts;
		return [ 'type' => $type, 'count' => count( $out ), 'total' => $total, 'offset' => $offset, 'next_offset' => $offset + count( $out ) < $total ? $offset + count( $out ) : null, 'entries' => $out ];
	}

	public static function delete_entry( $input ) {
		$id = (int) ( $input['id'] ?? 0 );
		$p = $id ? get_post( $id ) : null;
		if ( ! $p || ! in_array( $p->post_type, [ self::SERVICE, self::AREA ], true ) || ! current_user_can( 'delete_post', $id ) ) {
			return new \WP_Error( 'lb_entry', 'Unknown service/area, or you cannot remove it.', [ 'status' => 404 ] );
		}
		wp_trash_post( $id );
		return [ 'id' => $id, 'trashed' => true, 'title' => $p->post_title, 'menus_updated' => Nav::flush() ];
	}

	/* ------------------------------------------------------------------ playbook, audit, migration */

	/** Module hook: how services and areas are organised. */
	public static function playbook( array &$pb ): void {
		$pb['entries'] = [
			'services' => 'Services nest: a service (Air Conditioning) and its sub-services (Installation and Replacement) via add-service parent. With the URLs module the URLs follow the nesting at the site root (/air-conditioning/installation-and-replacement/). A service page lists its sub-services (the Designs sub-service list).',
			'areas'    => 'Areas are cities or regions (add-area). services on an area creates one area service page per service under it ("AC Installation in Rockledge, FL" at /service-areas/rockledge-fl/ac-installation/); the area page lists them. A service left out has its page drafted, never deleted.',
			'summary'  => 'The summary of an entry is its excerpt: one or two sentences, shown on cards and in the hero.',
			'icon'     => 'icon is a Kadence icon name such as fe_settings or fe_mapPin.',
		];
	}

	/** Module hook: audit checks this module owns. */
	public static function audit_checks(): array {
		return [
			'entry_missing_summary' => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'summary' ); }, true ],
			'entry_missing_text'    => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'text' ); }, true ],
			'entry_missing_image'   => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'image' ); }, true ],
			'area_service_orphan'   => [ 'entries', [ __CLASS__, 'audit_orphans' ] ],
		];
	}

	/** Published area service pages whose service is not published any more. */
	public static function audit_orphans( $args = [] ): array {
		$out = [];
		foreach ( self::query( 'area', [ 'limit' => 0, 'pages' => 'services' ] ) as $p ) {
			$sid = (int) get_post_meta( $p->ID, '_lb_service', true );
			if ( 'publish' !== get_post_status( $sid ) ) {
				$out[] = [ 'severity' => 'warning', 'code' => 'area_service_orphan', 'object_type' => self::AREA, 'id' => (int) $p->ID, 'url' => (string) get_permalink( $p ), 'message' => sprintf( '"%s" is about a service that is not published any more.', $p->post_title ), 'fix' => 'Publish the service again, or run add-area with the area\'s services to draft this page.' ];
			}
		}
		return $out;
	}

	/** Published entries missing a summary, text or image, by ID batches. */
	private static function audit_entries( $args, string $what ): array {
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$where = static function ( $w ) use ( $after ) { global $wpdb; return $w . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); };
		add_filter( 'posts_where', $where );
		$posts = get_posts( [ 'post_type' => [ self::SERVICE, self::AREA ], 'post_status' => 'publish', 'numberposts' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ] );
		remove_filter( 'posts_where', $where );
		$out = [];
		foreach ( $posts as $p ) {
			$miss = ( 'summary' === $what && '' === trim( (string) $p->post_excerpt ) && '' === self::legacy_summary( (int) $p->ID ) )
				|| ( 'text' === $what && '' === trim( wp_strip_all_tags( (string) $p->post_content ) ) && ! self::is_area_service( $p ) )
				|| ( 'image' === $what && ! has_post_thumbnail( $p ) && ! self::is_area_service( $p ) );
			if ( $miss ) {
				$out[] = [ 'severity' => 'image' === $what ? 'info' : 'warning', 'code' => 'entry_missing_' . $what, 'object_type' => $p->post_type, 'id' => (int) $p->ID, 'url' => (string) get_permalink( $p ), 'message' => sprintf( '"%s" has no %s.', $p->post_title, $what ), 'fix' => 'summary' === $what ? 'add-service/add-area summary (the excerpt).' : ( 'text' === $what ? 'add-service/add-area markdown.' : 'Set a featured image.' ) ];
			}
		}
		return [ 'findings' => $out, 'next_after_id' => count( $posts ) === $limit ? (int) end( $posts )->ID : null ];
	}

	/**
	 * Migration step entries-summary: the summary moves to the excerpt. A _lb_summary row is copied into an empty
	 * excerpt; when the excerpt already holds something else the excerpt is kept, the row's text is kept under
	 * _lb_summary_legacy and reported. Then the row is removed. Direct SQL: the alias filters never see these rows.
	 */
	public static function migrate_summary( $cursor, int $batch ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lb_summary' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", (int) $cursor, max( 1, $batch ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$report = [ 'copied' => 0, 'same' => 0, 'mismatches' => [] ];
		$last = (int) $cursor;
		foreach ( (array) $rows as $r ) {
			$last = (int) $r->meta_id;
			$pid = (int) $r->post_id;
			$raw = maybe_unserialize( $r->meta_value );
			$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			$p = get_post( $pid );
			if ( $p && '' !== $val ) {
				$ex = trim( (string) $p->post_excerpt );
				if ( '' === $ex ) {
					$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $val ], [ 'ID' => $pid ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					clean_post_cache( $pid );
					$report['copied']++;
				} elseif ( $ex === $val ) {
					$report['same']++;
				} else {
					$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $pid, 'meta_key' => '_lb_summary_legacy', 'meta_value' => $val ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
					$report['mismatches'][] = [ 'id' => $pid, 'title' => $p->post_title, 'excerpt' => $ex, 'summary' => $val ];
				}
			}
			$wpdb->delete( $wpdb->postmeta, [ 'meta_id' => (int) $r->meta_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $pid, 'post_meta' );
		}
		if ( count( $report['mismatches'] ) > 20 ) {
			$report['mismatches_more'] = count( $report['mismatches'] ) - 20;
			$report['mismatches'] = array_slice( $report['mismatches'], 0, 20 );
		}
		if ( $rows ) {
			self::changed( false );
		}
		$done = count( (array) $rows ) < max( 1, $batch );
		return [ 'done' => $done, 'cursor' => $last, 'report' => ( $report['copied'] || $report['same'] || $report['mismatches'] ) ? $report : '' ];
	}

	/**
	 * Migration step entries-categories: service categories become parent services. Each category becomes a service
	 * (same name and slug, its description as the summary) and the services in it become its sub-services (a service in
	 * several categories goes under the first). A category that had its own landing page gets its service as a draft, so
	 * the live page keeps its URL until someone moves its text over; the report lists those. The terms themselves are
	 * left in the database. Direct SQL: the taxonomy is no longer registered.
	 */
	public static function migrate_categories( $cursor, int $batch ): array {
		global $wpdb;
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.description FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d", self::LEGACY_CAT, (int) $cursor, max( 1, $batch ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$report = [ 'parents' => [], 'moved' => 0, 'needs_review' => [] ];
		$last = (int) $cursor;
		foreach ( (array) $terms as $t ) {
			$last = (int) $t->term_id;
			$page = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_lb_page' LIMIT 1", (int) $t->term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$live_page = $page && 'publish' === get_post_status( $page );
			$parent = self::find_by_key( self::SERVICE, 'cat:' . $t->slug ) ?: self::find_child( self::SERVICE, (string) $t->slug, 0 );
			if ( ! $parent ) {
				$parent = wp_insert_post( [ 'post_type' => self::SERVICE, 'post_status' => $live_page ? 'draft' : 'publish', 'post_title' => (string) $t->name, 'post_name' => (string) $t->slug, 'post_excerpt' => sanitize_text_field( (string) $t->description ) ], true );
				if ( is_wp_error( $parent ) ) {
					return [ 'done' => false, 'cursor' => (int) $cursor, 'report' => 'category ' . $t->slug . ': ' . $parent->get_error_message() ];
				}
				update_post_meta( (int) $parent, '_lb_key', 'cat:' . $t->slug );
				$report['parents'][] = (string) $t->name;
				if ( $live_page ) {
					$report['needs_review'][] = sprintf( 'Service "%s" was created as a draft because the page "%s" (id %d) still holds that URL: move its text into the service, then publish the service and remove the page.', $t->name, get_the_title( $page ), $page );
				}
			}
			$members = $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = %d AND p.post_type = %s AND p.post_parent = 0 AND p.ID <> %d", (int) $t->term_taxonomy_id, self::SERVICE, (int) $parent ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $members as $sid ) {
				wp_update_post( [ 'ID' => (int) $sid, 'post_parent' => (int) $parent ] );
				$report['moved']++;
			}
		}
		if ( $terms ) {
			self::changed( true );
			delete_option( self::FLUSHED );
		}
		$done = count( (array) $terms ) < max( 1, $batch );
		return [ 'done' => $done, 'cursor' => $last, 'report' => ( $report['parents'] || $report['moved'] ) ? $report : '' ];
	}
}
