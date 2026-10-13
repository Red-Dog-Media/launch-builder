<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Services and service areas as post types, so lists on the site are queries, not copies.
 *
 *  lb_service (+ taxonomy lb_service_cat)   /services/{service}/
 *  lb_area (hierarchical)                   /service-areas/{city}/
 *
 * The dynamic block launch/entries lists them in one of the layouts in LAYOUTS (the single allow-list: the block, the
 * @entries pattern slot and the editor all read it), so adding or removing an entry updates every section that shows
 * the list, and Nav keeps the auto items of menus in step. Each entry is rendered through one template (a synced
 * pattern, service_template / area_template) with the launch/post-body block holding the entry's own text.
 *
 * Fields: the summary is the post excerpt for every type (pages included); _lb_summary is only an alias of it (metadata
 * filters), so a Meta Box field or a REST client using that id keeps working. _lb_icon (a Kadence icon name, case
 * kept) and _lb_services (areas: one integer row per service offered there, none = every service) are plain post meta.
 * When Meta Box registers a field with one of these ids, the Card details box leaves that field to it.
 */
final class Entries {

	const SERVICE = 'lb_service';
	const AREA    = 'lb_area';
	const CAT     = 'lb_service_cat';
	const FLUSHED = 'launch_builder_entries_flushed';

	/**
	 * The one allow-list of list layouts: render(), apply_attrs() (the @entries slot) and the block editor (passed by
	 * editor_data()). Labels: layout_labels().
	 */
	const LAYOUTS = [ 'cards', 'icon-cards', 'image-cards', 'category-cards', 'pills', 'chips', 'tiles', 'checklist', 'columns', 'columns-links', 'links', 'footer-links', 'menu', 'matrix' ];

	/** Item styles (block attribute variant; '' = the default look). */
	const VARIANTS = [ 'outline', 'shadow', 'filled', 'minimal', 'dark' ];

	/** Layouts that end with a "View all" link (to the hub, category page or parent area) when the limit cuts the list. */
	const VIEW_ALL = [ 'footer-links', 'menu' ];

	/** Values of category / parent / inArea that follow the object being viewed instead of naming one. */
	const FOLLOW = [ 'current', 'children', 'siblings' ];

	/** Entries handled per add-service / add-area call (filter launch_builder_entries_batch); the rest is reported. */
	const BATCH = 100;

	/** @var string|null raw block markup of the entry being rendered through its template. */
	private static $body = null;

	/** @var array<string,\WP_Post[]> query results of this request, by args hash. */
	private static $memo = [];

	/** @var array<int,int>|null published area id => parent id (this request). */
	private static $tree = null;

	/** @var array<int,bool> posts whose REST request also sets the excerpt (the excerpt wins over the _lb_summary alias). */
	private static $rest_excerpt = [];

	/** @var array<int,string> service category slugs before an edit (term id => slug). */
	private static $term_slugs = [];

	/** @var array<string,bool> svc-{slug} anchors already printed on this page. */
	private static $anchors = [];

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush' ], 99 );
		add_filter( 'the_content', [ __CLASS__, 'wrap' ], 8 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
		// _lb_summary is an alias of the excerpt.
		add_filter( 'get_post_metadata', [ __CLASS__, 'summary_get' ], 10, 4 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'summary_set' ], 10, 4 );
		add_filter( 'add_post_metadata', [ __CLASS__, 'summary_set' ], 10, 4 );
		add_filter( 'delete_post_metadata', [ __CLASS__, 'summary_delete' ], 10, 5 );
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'rest_done' ] );
		// Changes the lists and menus show: page caches, the query memo and Nav's auto items.
		add_action( 'post_updated', [ __CLASS__, 'on_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_status' ], 10, 3 );
		add_action( 'set_object_terms', [ __CLASS__, 'on_terms' ], 10, 6 );
		add_action( 'deleted_post', [ __CLASS__, 'on_deleted' ], 10, 2 );
		add_action( 'edit_terms', [ __CLASS__, 'term_before' ], 10, 2 );
		add_action( 'edited_terms', [ __CLASS__, 'term_after' ], 10, 2 );
		add_action( 'created_' . self::CAT, [ __CLASS__, 'term_changed' ] );
		add_action( 'delete_' . self::CAT, [ __CLASS__, 'term_changed' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_meta' ], 10, 3 );
		}
		add_action( 'rwmb_after_save_post', [ __CLASS__, 'on_meta_box_saved' ] ); // A Meta Box field a card shows ({{meta.x}}) may have changed.
		// Where "View all" links go: the hubs and the category pages.
		add_action( 'add_option_' . Roles::OPT_HUBS, [ __CLASS__, 'term_changed' ], 10, 0 );
		add_action( 'update_option_' . Roles::OPT_HUBS, [ __CLASS__, 'term_changed' ], 10, 0 );
		foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_term_meta' ], 10, 3 );
		}
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_data' ] );
		add_action( 'template_redirect', [ __CLASS__, 'old_path_redirect' ], 9 );
		Migrate::register_step( 'entries-summary', [ __CLASS__, 'migrate_summary' ], [ 'after' => [ 'chrome-page-heroes' ] ] );
	}

	/* ------------------------------------------------------------------ registration */

	public static function register(): void {
		$labels = static function ( string $one, string $many ): array {
			return [ 'name' => $many, 'singular_name' => $one, 'menu_name' => $many, 'all_items' => 'All ' . strtolower( $many ), 'add_new_item' => 'Add ' . strtolower( $one ), 'edit_item' => 'Edit ' . strtolower( $one ), 'new_item' => 'New ' . strtolower( $one ), 'view_item' => 'View ' . strtolower( $one ), 'search_items' => 'Search ' . strtolower( $many ), 'not_found' => 'None yet' ];
		};
		// Filter launch_builder_entry_rewrite_args ( array rewrite, string post type or taxonomy ): URL bases (the permalinks
		// module owns them; rewrite rules are flushed by whoever changes them).
		$rewrite = static function ( string $slug, string $object ): array {
			return (array) apply_filters( 'launch_builder_entry_rewrite_args', [ 'slug' => $slug, 'with_front' => false ], $object );
		};
		$supports = [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'custom-fields' ];
		register_post_type( self::SERVICE, [
			'labels' => $labels( 'Service', 'Services' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-hammer', 'menu_position' => 21,
			'supports' => $supports, 'has_archive' => false, 'hierarchical' => false, 'rewrite' => $rewrite( 'services', self::SERVICE ),
		] );
		register_taxonomy( self::CAT, [ self::SERVICE ], [
			'labels' => [ 'name' => 'Service categories', 'singular_name' => 'Service category', 'menu_name' => 'Categories', 'all_items' => 'All categories', 'add_new_item' => 'Add category' ],
			'hierarchical' => true, 'public' => true, 'show_in_rest' => true, 'show_admin_column' => true, 'rewrite' => $rewrite( 'service-category', self::CAT ),
		] );
		register_post_type( self::AREA, [
			'labels' => $labels( 'Service area', 'Service areas' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-location-alt', 'menu_position' => 22,
			'supports' => $supports, 'has_archive' => false, 'hierarchical' => true, 'rewrite' => $rewrite( 'service-areas', self::AREA ),
		] );
		add_post_type_support( 'page', 'excerpt' ); // The summary store of every type (page.summary, page heroes, SEO descriptions).
		$can = static function () { return current_user_can( 'edit_posts' ); };
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			register_post_meta( $pt, '_lb_summary', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $can, 'description' => 'Alias of the excerpt.' ] );
			register_post_meta( $pt, '_lb_icon', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => [ __CLASS__, 'clean_icon' ], 'auth_callback' => $can ] );
		}
		register_post_meta( self::AREA, '_lb_services', [ 'type' => 'integer', 'single' => false, 'show_in_rest' => true, 'sanitize_callback' => 'absint', 'auth_callback' => $can, 'description' => 'Services offered in this area (none = all).' ] );
		register_term_meta( self::CAT, '_lb_page', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => static function () { return current_user_can( 'manage_categories' ); } ] );
		foreach ( self::summary_types() as $t ) {
			add_action( 'rest_insert_' . $t, [ __CLASS__, 'rest_excerpt' ], 10, 2 );
		}
	}

	/** New permalinks need one rewrite flush, once per plugin version. */
	public static function maybe_flush(): void {
		if ( get_option( self::FLUSHED ) !== LB_VERSION . '-1' ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSHED, LB_VERSION . '-1', false );
		}
	}

	/** Layout => label, for the editor and the playbook (same keys as LAYOUTS). */
	public static function layout_labels(): array {
		return [
			'cards'          => __( 'Cards', 'launch-builder' ),
			'icon-cards'     => __( 'Cards with icons', 'launch-builder' ),
			'image-cards'    => __( 'Cards with images', 'launch-builder' ),
			'category-cards' => __( 'Category cards with checklists (services)', 'launch-builder' ),
			'pills'          => __( 'Pills (buttons)', 'launch-builder' ),
			'chips'          => __( 'Chips (small tags)', 'launch-builder' ),
			'tiles'          => __( 'Tiles with check icons', 'launch-builder' ),
			'checklist'      => __( 'Check list', 'launch-builder' ),
			'columns'        => __( 'Columns of links', 'launch-builder' ),
			'columns-links'  => __( 'Grouped columns of links (by category or parent area)', 'launch-builder' ),
			'links'          => __( 'Plain links', 'launch-builder' ),
			'footer-links'   => __( 'Footer links (with "View all")', 'launch-builder' ),
			'menu'           => __( 'Menu panel (mega menus, with "View all")', 'launch-builder' ),
			'matrix'         => __( 'Services by area (links to each area page)', 'launch-builder' ),
		];
	}

	/** Variant => label (VARIANTS). */
	public static function variant_labels(): array {
		return [
			'outline' => __( 'Outline', 'launch-builder' ),
			'shadow'  => __( 'Shadow', 'launch-builder' ),
			'filled'  => __( 'Filled', 'launch-builder' ),
			'minimal' => __( 'Minimal', 'launch-builder' ),
			'dark'    => __( 'Dark', 'launch-builder' ),
		];
	}

	/** The block editor gets the allow-lists from here (one source). */
	public static function editor_data(): void {
		$handle = generate_block_asset_handle( 'launch/entries', 'editorScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		$opt = static function ( array $labels ): array {
			$out = [];
			foreach ( $labels as $value => $label ) {
				$out[] = [ 'value' => $value, 'label' => $label ];
			}
			return $out;
		};
		$layouts = array_intersect_key( self::layout_labels(), array_flip( self::LAYOUTS ) );
		$data = [ 'layouts' => $opt( $layouts ), 'variants' => $opt( self::variant_labels() ), 'viewAll' => self::VIEW_ALL ];
		wp_add_inline_script( $handle, 'window.launchBuilderEntries = ' . wp_json_encode( $data ) . ';', 'before' );
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
			$fields['services'] = '_lb_services';
		}
		return array_filter( $fields, static function ( $meta ) use ( $post_type ) { return ! self::meta_box_owns( $meta, $post_type ); } );
	}

	/**
	 * One line about Meta Box for the Card details box: what it edits instead of this box, or how to hand a field over.
	 *
	 * @param array<string,string> $shown box_fields() of the type
	 */
	private static function meta_box_note( string $post_type, array $shown ): string {
		if ( ! function_exists( 'rwmb_meta' ) ) {
			return '';
		}
		$taken = [];
		if ( ! isset( $shown['icon'] ) ) {
			$taken[] = __( 'Icon', 'launch-builder' );
		}
		if ( self::AREA === $post_type && ! isset( $shown['services'] ) ) {
			$taken[] = __( 'Services offered', 'launch-builder' );
		}
		if ( $taken ) {
			/* translators: %s: field names, for example "Icon" */
			return sprintf( __( 'Meta Box edits %s, so this box leaves it out.', 'launch-builder' ), implode( ', ', $taken ) );
		}
		return self::AREA === $post_type
			? __( 'Using Meta Box? A field with the id _lb_icon or _lb_services takes over from this box.', 'launch-builder' )
			: __( 'Using Meta Box? A field with the id _lb_icon takes over from this box.', 'launch-builder' );
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
		echo '<p class="description">' . esc_html__( 'The short summary on cards and in the page banner is the Excerpt.', 'launch-builder' ) . '</p>';
		$note = self::meta_box_note( $post->post_type, $fields );
		if ( '' !== $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
		if ( isset( $fields['icon'] ) ) {
			echo '<p><label><strong>' . esc_html__( 'Icon', 'launch-builder' ) . '</strong> ' . esc_html__( '(optional Kadence icon name, e.g. fe_settings or fe_mapPin; letters, digits and _)', 'launch-builder' ) . '<br><input type="text" name="lb_icon" style="width:100%" value="' . esc_attr( (string) get_post_meta( $post->ID, '_lb_icon', true ) ) . '"></label></p>';
		}
		if ( isset( $fields['services'] ) ) {
			$all = get_posts( [ 'post_type' => self::SERVICE, 'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ], 'numberposts' => -1, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] );
			$have = array_map( 'intval', (array) get_post_meta( $post->ID, '_lb_services', false ) );
			echo '<input type="hidden" name="lb_services_sent" value="1"><p><strong>' . esc_html__( 'Services offered here', 'launch-builder' ) . '</strong><br><span class="description">' . esc_html__( 'None ticked = every service.', 'launch-builder' ) . '</span></p>';
			echo '<div style="max-height:180px;overflow:auto;border:1px solid #dcdcde;padding:6px 8px">';
			foreach ( $all as $s ) {
				echo '<label style="display:block"><input type="checkbox" name="lb_services[]" value="' . (int) $s->ID . '"' . checked( in_array( (int) $s->ID, $have, true ), true, false ) . '> ' . esc_html( get_the_title( $s ) ) . '</label>';
			}
			echo '</div>';
			$stale = count( array_diff( $have, array_map( static function ( $s ) { return (int) $s->ID; }, $all ) ) );
			if ( $stale ) {
				/* translators: %d: number of services that no longer exist */
				echo '<p class="description">' . esc_html( sprintf( _n( '%d listed service no longer exists; saving drops it.', '%d listed services no longer exist; saving drops them.', $stale, 'launch-builder' ), $stale ) ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Order in every list = the Order box (menu order), then the title.', 'launch-builder' ) . '</p>';
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
		if ( isset( $fields['services'], $_POST['lb_services_sent'] ) ) { // phpcs:ignore
			self::set_services( $id, array_map( 'absint', (array) wp_unslash( $_POST['lb_services'] ?? [] ) ) ); // phpcs:ignore
		}
	}

	/** Replace an area's _lb_services rows (one integer row per service; written only when the set changes). */
	public static function set_services( int $area, array $ids ): bool {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids );
		$have = array_values( array_unique( array_map( 'intval', (array) get_post_meta( $area, '_lb_services', false ) ) ) );
		sort( $have );
		if ( $ids === $have ) {
			return false;
		}
		delete_post_meta( $area, '_lb_services' );
		foreach ( $ids as $sid ) {
			add_post_meta( $area, '_lb_services', $sid );
		}
		return true;
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
		self::$tree = null;
	}

	private static function ours( $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, [ self::SERVICE, self::AREA ], true );
	}

	/** post_updated: title, slug, order, parent and status matter to menus; the excerpt, text and those to lists. */
	public static function on_updated( $id, $after, $before ): void {
		if ( ! $before instanceof \WP_Post || ! $after instanceof \WP_Post || ( 'publish' !== $after->post_status && 'publish' !== $before->post_status ) ) {
			return;
		}
		if ( 'page' === $after->post_type ) { // A hub or category page moved: "View all" links point at it.
			$moved = $after->post_name !== $before->post_name || (int) $after->post_parent !== (int) $before->post_parent || $after->post_status !== $before->post_status;
			if ( $moved && ( in_array( (int) $id, Roles::hubs(), true ) || isset( Roles::catmap()[ (int) $id ] ) ) ) {
				self::changed( true );
			}
			return;
		}
		if ( ! self::ours( $after ) ) {
			return;
		}
		$menus = false;
		foreach ( [ 'post_title', 'post_name', 'post_status', 'menu_order', 'post_parent' ] as $f ) {
			if ( (string) $after->$f !== (string) $before->$f ) {
				$menus = true;
			}
		}
		$lists = $menus || $after->post_excerpt !== $before->post_excerpt || ( '' === trim( (string) $after->post_excerpt ) && $after->post_content !== $before->post_content );
		if ( self::AREA === $after->post_type && 'publish' === $before->post_status && ( $after->post_name !== $before->post_name || (int) $after->post_parent !== (int) $before->post_parent ) ) {
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

	/** set_object_terms: a published service's categories changed. */
	public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( self::CAT !== $taxonomy ) {
			return;
		}
		$p = get_post( (int) $object_id );
		$a = array_map( 'intval', (array) $tt_ids );
		$b = array_map( 'intval', (array) $old_tt_ids );
		sort( $a );
		sort( $b );
		if ( $p && 'publish' === $p->post_status && $a !== $b ) {
			self::changed( true );
		}
	}

	/** deleted_post: a published entry deleted without going through the trash. */
	public static function on_deleted( $id, $post = null ): void {
		if ( self::ours( $post ) && 'publish' === $post->post_status ) {
			self::changed( true );
		}
	}

	public static function term_before( $term_id, $taxonomy ): void {
		if ( self::CAT === $taxonomy ) {
			$t = get_term( (int) $term_id, self::CAT );
			if ( $t instanceof \WP_Term ) {
				self::$term_slugs[ (int) $term_id ] = $t->slug;
			}
		}
	}

	/** edited_terms: a category slug rename moves the menus' category markers along with it. */
	public static function term_after( $term_id, $taxonomy ): void {
		if ( self::CAT !== $taxonomy ) {
			return;
		}
		clean_term_cache( (int) $term_id, self::CAT ); // edited_terms fires before WordPress refreshes its term cache.
		$t = get_term( (int) $term_id, self::CAT );
		$old = self::$term_slugs[ (int) $term_id ] ?? '';
		if ( $t instanceof \WP_Term && '' !== $old && $old !== $t->slug ) {
			Nav::rename( 'category', $old, $t->slug );
		}
		unset( self::$term_slugs[ (int) $term_id ] );
		self::changed( true );
	}

	/** A category was created or deleted, or the hubs changed. */
	public static function term_changed(): void {
		self::changed( true );
	}

	/** A category's page (_lb_page) changed: category "View all" links point at it. */
	public static function on_term_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( '_lb_page' === $meta_key ) {
			$t = get_term( (int) $object_id );
			if ( $t instanceof \WP_Term && self::CAT === $t->taxonomy ) {
				self::changed( true );
			}
		}
	}

	/** Meta that lists show (image, icon, services offered). */
	public static function on_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, [ '_thumbnail_id', '_lb_icon', '_lb_services' ], true ) ) {
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

	/** 404 under the areas base whose path an area had before it was renamed or moved: 301 to where it is now. */
	public static function old_path_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$obj = get_post_type_object( self::AREA );
		$base = trim( (string) ( $obj && is_array( $obj->rewrite ) ? ( $obj->rewrite['slug'] ?? '' ) : '' ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		if ( '' === $base || 0 !== strpos( $path . '/', $base . '/' ) ) {
			return;
		}
		$parts = array_values( array_filter( explode( '/', strtolower( substr( $path, strlen( $base ) ) ) ), 'strlen' ) );
		for ( $n = count( $parts ); $n > 0; $n-- ) { // The longest old path first; the rest of the path follows it.
			$found = get_posts( [ 'post_type' => self::AREA, 'post_status' => 'publish', 'meta_key' => '_lb_old_path', 'meta_value' => implode( '/', array_slice( $parts, 0, $n ) ), 'numberposts' => 1 ] );
			if ( $found ) {
				$rest = array_slice( $parts, $n );
				$target = $rest ? get_page_by_path( get_page_uri( $found[0] ) . '/' . implode( '/', $rest ), OBJECT, self::AREA ) : $found[0];
				if ( $target instanceof \WP_Post && 'publish' === $target->post_status ) {
					wp_safe_redirect( get_permalink( $target ), 301 );
					exit;
				}
				return;
			}
		}
	}

	/* ------------------------------------------------------------------ template for single entries */

	/** Singles are rendered through a synced pattern; the entry's own text goes where launch/post-body is. */
	public static function wrap( $content ) {
		// Not for excerpts built from the content (core/post-excerpt in the template banner) and only for the entry itself.
		if ( ! is_singular( [ self::SERVICE, self::AREA ] ) || ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		// Filter launch_builder_entry_template ( int synced pattern id, ?WP_Post entry ): the chrome map's template slot.
		$tpl = (int) apply_filters( 'launch_builder_entry_template', Blueprint::find_synced( is_singular( self::AREA ) ? 'area_template' : 'service_template' ), get_post() );
		if ( ! $tpl || 'publish' !== get_post_status( $tpl ) ) {
			return $content;
		}
		self::$body = (string) $content; // Raw block markup (this filter runs before do_blocks).
		return (string) get_post_field( 'post_content', $tpl );
	}

	/** The entry's text, rendered. Used by the launch/post-body block. */
	public static function post_body(): string {
		$raw = null !== self::$body ? (string) self::$body : ( is_singular( [ self::SERVICE, self::AREA ] ) ? (string) get_post_field( 'post_content', get_queried_object_id() ) : '' ); // Elements that replace the single content do not pass through the_content.
		if ( '' === trim( $raw ) ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lb-entries-empty">' . esc_html__( 'The text of this entry appears here.', 'launch-builder' ) . '</p>' : '';
		}
		return '<div class="lb-post-body">' . do_blocks( $raw ) . '</div>';
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
		$p = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'meta_key' => '_lb_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids' ] );
		return $p ? (int) $p[0] : 0;
	}

	/**
	 * An area by slug among siblings: under $parent when given (0 = top level), else the top-level one, else the only one
	 * with that slug. 0 when not found or ambiguous.
	 */
	private static function find_area( string $slug, ?int $parent ): int {
		if ( '' === $slug ) {
			return 0;
		}
		$rows = self::parent_map( [ 'post_type' => self::AREA, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1 ] );
		foreach ( $rows as $id => $par ) {
			if ( ( null === $parent ? 0 : $parent ) === $par ) {
				return $id;
			}
		}
		return null === $parent && 1 === count( $rows ) ? (int) array_key_first( $rows ) : 0;
	}

	/**
	 * Post id => parent id for the posts a query finds. (The 'id=>parent' fields value of WP_Query changed shape in
	 * WordPress 7, so the parent is read from the posts themselves.)
	 *
	 * @param array $args get_posts() arguments.
	 * @return array<int,int>
	 */
	private static function parent_map( array $args ): array {
		$ids = get_posts( [ 'fields' => 'ids' ] + $args );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = (int) get_post_field( 'post_parent', (int) $id );
		}
		return $out;
	}

	/**
	 * An area from a reference: an id, a path ("texas/travis-county/austin"), or a slug or title (the top-level one,
	 * else the only one). Any status but trash. 0 when unknown or ambiguous.
	 *
	 * @param int|string $ref
	 */
	public static function area_id( $ref ): int {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$p = (int) $ref > 0 ? get_post( (int) $ref ) : null; // get_post( 0 ) would be the global post.
			return $p && self::AREA === $p->post_type && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		$ref = trim( is_scalar( $ref ) ? (string) $ref : '', " \t/" );
		if ( '' === $ref ) {
			return 0;
		}
		if ( false !== strpos( $ref, '/' ) ) {
			$p = get_page_by_path( implode( '/', array_map( 'sanitize_title', explode( '/', $ref ) ) ), OBJECT, self::AREA );
			return $p instanceof \WP_Post && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		return self::find_area( sanitize_title( $ref ), null );
	}

	/** A service from an id, slug or title (any status but trash), or 0. */
	public static function service_id( $ref ): int {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$p = (int) $ref > 0 ? get_post( (int) $ref ) : null;
			return $p && self::SERVICE === $p->post_type && 'trash' !== $p->post_status ? (int) $p->ID : 0;
		}
		$slug = sanitize_title( is_scalar( $ref ) ? (string) $ref : '' );
		if ( '' === $slug ) {
			return 0;
		}
		$p = get_posts( [ 'post_type' => self::SERVICE, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
		return $p ? (int) $p[0] : 0;
	}

	/** Published area id => parent id (memoised per request). */
	private static function tree(): array {
		if ( null === self::$tree ) {
			self::$tree = self::parent_map( [ 'post_type' => self::AREA, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
		}
		return self::$tree;
	}

	/** Ids of the published areas below an area, any depth. */
	public static function descendants( int $area ): array {
		$kids = [];
		foreach ( self::tree() as $id => $parent ) {
			$kids[ $parent ][] = $id;
		}
		$out = [];
		$todo = $kids[ $area ] ?? [];
		while ( $todo ) {
			$id = array_shift( $todo );
			if ( isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = $id;
			$todo = array_merge( $todo, $kids[ $id ] ?? [] );
		}
		return array_values( $out );
	}

	/** Published service ids an area offers (its _lb_services rows that still exist); [] = every service. */
	public static function area_services( int $area ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $area, '_lb_services', false ) ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$live = array_map( static function ( $p ) { return (int) $p->ID; }, self::query( 'service', [ 'limit' => 0 ] ) );
		return array_values( array_intersect( $ids, $live ) ); // Stale ids (trashed, deleted) are ignored; the audit lists them.
	}

	/** The role a template post (synced pattern, element...) is designed for: _lb_roles, else its legacy key. */
	private static function template_role( \WP_Post $p ): string {
		$roles = Roles::template_roles( (int) $p->ID );
		if ( $roles ) {
			return $roles[0];
		}
		$key = (string) get_post_meta( $p->ID, '_lb_key', true );
		return false !== strpos( $key, 'area_template' ) ? 'area' : ( false !== strpos( $key, 'service_template' ) ? 'service' : '' );
	}

	/**
	 * The object "current" filters follow: a simulated context (previews, audit), the object tokens resolve against (a
	 * per-entry design's entry, the loop's post, the queried post), the queried term on archives, the block's postId
	 * context, the global post (editor previews). A design (synced pattern, element) stands for a sample of its role.
	 *
	 * @param \WP_Block|null $block
	 * @return \WP_Post|\WP_Term|null
	 */
	public static function current_object( $block = null ) {
		$sim = Context::current();
		if ( $sim ) {
			return $sim['post'] ?: Roles::sample( (string) $sim['role'] );
		}
		$p = Tokens::context();
		if ( ! $p && did_action( 'wp' ) && ! is_admin() ) {
			$q = get_queried_object();
			if ( $q instanceof \WP_Term ) {
				return $q;
			}
		}
		if ( ! $p && $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$p = get_post( (int) $block->context['postId'] );
		}
		if ( ! $p ) {
			$g = get_post();
			$p = $g instanceof \WP_Post ? $g : null;
		}
		if ( $p && in_array( $p->post_type, Roles::TEMPLATE_TYPES, true ) ) {
			$role = self::template_role( $p );
			$sample = '' !== $role ? Roles::sample( $role ) : null;
			return $sample ?: $p;
		}
		return $p;
	}

	/** Service category term ids "current" means for an object ([] = none). */
	private static function current_terms( $obj ): array {
		if ( $obj instanceof \WP_Term ) {
			return self::CAT === $obj->taxonomy ? [ (int) $obj->term_id ] : [];
		}
		if ( ! $obj instanceof \WP_Post ) {
			return [];
		}
		if ( self::SERVICE === $obj->post_type ) {
			$t = wp_get_object_terms( $obj->ID, self::CAT, [ 'fields' => 'ids' ] );
			return is_wp_error( $t ) ? [] : array_map( 'intval', $t );
		}
		$map = Roles::catmap();
		return 'page' === $obj->post_type && isset( $map[ $obj->ID ] ) ? [ (int) $map[ $obj->ID ] ] : [];
	}

	/* ------------------------------------------------------------------ querying */

	/**
	 * Published entries for a list. $a (block attributes or tool args):
	 *  limit      0 = all, else 1-500 (default 24)
	 *  orderby    '' | 'menu_order' (order, then title) | 'title'; with ids and no orderby: the order of ids
	 *  category   service category slugs (comma separated), or "current" (the category page / archive / service viewed)
	 *  parent     areas: "top", an area id, slug or path, or "current" (children of the area viewed; on the areas hub:
	 *             the top level), "children" (every area below it, any depth; hub: all), "siblings" (same parent, itself
	 *             left out)
	 *  inArea     services: "current" or an area (id, slug, path): the services it offers (_lb_services; none = all).
	 *             Alias in_area.
	 *  ids        only these ids (comma separated or array)
	 *  related    services: same category as the service viewed (replaces category); excludeCurrent: leave it out
	 * Anything named that does not exist (unknown parent area, "current" with nothing to follow) lists nothing. Filter
	 * launch_builder_entries_query_args ( array WP_Query args, string type, array $a ). Memoised per request.
	 *
	 * @param \WP_Block|null $block
	 * @return \WP_Post[]
	 */
	public static function query( string $type, array $a = [], $block = null ): array {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$limit = isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? (int) $a['limit'] : 24;
		$args = [ 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => $limit <= 0 ? -1 : min( 500, $limit ), 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'no_found_rows' => true, 'ignore_sticky_posts' => true ];
		$got = false;
		$cur = null;
		$current = static function () use ( &$got, &$cur, $block ) {
			if ( ! $got ) {
				$got = true;
				$cur = self::current_object( $block );
			}
			return $cur;
		};
		$in = null; // post__in (null = no restriction)
		$narrow = static function ( array $ids ) use ( &$in ): void {
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			$in = null === $in ? $ids : array_values( array_intersect( $in, $ids ) );
		};
		$not = [];

		$cat = trim( is_scalar( $a['category'] ?? '' ) ? (string) ( $a['category'] ?? '' ) : '' );
		if ( self::SERVICE === $pt && '' !== $cat ) {
			if ( 'current' === $cat ) {
				$terms = self::current_terms( $current() );
				if ( ! $terms ) {
					return [];
				}
				$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'term_id', 'terms' => $terms ] ];
			} else {
				$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $cat ) ) ] ];
			}
		}

		$parent = trim( is_scalar( $a['parent'] ?? '' ) ? (string) ( $a['parent'] ?? '' ) : '' );
		if ( self::AREA === $pt && '' !== $parent ) {
			if ( 'top' === $parent ) {
				$args['post_parent'] = 0;
			} elseif ( in_array( $parent, self::FOLLOW, true ) ) {
				$o = $current();
				$hub = $o instanceof \WP_Post && 'page' === $o->post_type && (int) $o->ID === Roles::hubs()['areas'];
				if ( $hub ) {
					if ( 'siblings' === $parent ) {
						return [];
					}
					if ( 'current' === $parent ) {
						$args['post_parent'] = 0;
					}
				} elseif ( $o instanceof \WP_Post && self::AREA === $o->post_type ) {
					if ( 'current' === $parent ) {
						$args['post_parent'] = (int) $o->ID;
					} elseif ( 'children' === $parent ) {
						$d = self::descendants( (int) $o->ID );
						if ( ! $d ) {
							return [];
						}
						$narrow( $d );
					} else {
						$args['post_parent'] = (int) $o->post_parent;
						$not[] = (int) $o->ID;
					}
				} else {
					return [];
				}
			} else {
				$pid = self::area_id( $parent );
				if ( ! $pid ) {
					return []; // An unknown parent lists nothing (never every top-level area).
				}
				$args['post_parent'] = $pid;
			}
		}

		$in_area = self::in_area_arg( $a );
		if ( self::SERVICE === $pt && '' !== $in_area ) {
			$aid = 'current' === $in_area ? 0 : self::area_id( $in_area );
			$area = 'current' === $in_area ? $current() : ( $aid ? get_post( $aid ) : null );
			if ( ! $area instanceof \WP_Post || self::AREA !== $area->post_type ) {
				return [];
			}
			$offered = self::area_services( (int) $area->ID );
			if ( $offered ) {
				$narrow( $offered );
			}
		}

		$by_ids = false;
		$ids = $a['ids'] ?? '';
		if ( '' !== $ids && [] !== $ids && null !== $ids ) {
			$list = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : explode( ',', (string) $ids ) ) ) );
			if ( ! $list ) {
				return [];
			}
			$narrow( $list );
			$by_ids = $list;
		}

		$orderby = (string) ( $a['orderby'] ?? '' );
		if ( 'title' === $orderby ) {
			$args['orderby'] = [ 'title' => 'ASC' ];
		} elseif ( $by_ids && '' === $orderby ) {
			$args['orderby'] = 'post__in';
		}

		if ( ! empty( $a['related'] ) && self::SERVICE === $pt ) { // Same category as the service being viewed.
			$o = $current();
			if ( $o instanceof \WP_Post && self::SERVICE === $o->post_type ) {
				$terms = wp_get_object_terms( $o->ID, self::CAT, [ 'fields' => 'ids' ] );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'term_id', 'terms' => $terms ] ];
				}
			}
		}
		if ( ! empty( $a['excludeCurrent'] ) || ! empty( $a['related'] ) ) {
			$o = $current();
			if ( $o instanceof \WP_Post && $pt === $o->post_type ) {
				$not[] = (int) $o->ID;
			}
		}

		if ( null !== $in ) {
			$in = array_values( array_diff( $in, $not ) );
			if ( ! $in ) {
				return [];
			}
			$args['post__in'] = $in;
			if ( 'post__in' === $args['orderby'] && is_array( $by_ids ) ) {
				$args['post__in'] = array_values( array_intersect( $by_ids, $in ) );
			}
		} elseif ( $not ) {
			$args['post__not_in'] = array_values( array_unique( $not ) );
		}

		$args = (array) apply_filters( 'launch_builder_entries_query_args', $args, $type, $a );
		$key = md5( (string) wp_json_encode( $args ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = get_posts( $args );
		}
		return self::$memo[ $key ];
	}

	/* ------------------------------------------------------------------ rendering */

	private static function pin(): string {
		return '<svg class="lb-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
	}

	/** The entry's _lb_icon as an inline SVG (Kadence's icon set), else a neutral pin (areas) or check (services). */
	public static function icon_html( \WP_Post $p ): string {
		$name = self::clean_icon( get_post_meta( $p->ID, '_lb_icon', true ) );
		$svg = '';
		if ( '' !== $name && class_exists( '\Kadence_Blocks_Svg_Render' ) ) {
			$line = 0 === strpos( $name, 'fe_' );
			$svg = (string) \Kadence_Blocks_Svg_Render::render( $name, $line ? 'none' : 'currentColor', $line ? 2 : false, '', true );
		}
		if ( '' === $svg ) {
			$svg = self::AREA === $p->post_type ? self::pin() : '<svg class="lb-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
		}
		return '<span class="lb-entry-icon" aria-hidden="true">' . $svg . '</span>';
	}

	/**
	 * id="svc-{slug}" on the first service item of an area page (targets of the matrix layout's links). Not on menu and
	 * footer-links lists: a header mega menu renders before the content and must not take the anchor.
	 */
	private static function anchor( \WP_Post $p ): string {
		if ( self::SERVICE !== $p->post_type || ! did_action( 'wp' ) || ! is_singular( self::AREA ) || isset( self::$anchors[ $p->post_name ] ) ) {
			return '';
		}
		self::$anchors[ $p->post_name ] = true;
		return ' id="' . esc_attr( 'svc-' . $p->post_name ) . '"';
	}

	/** Where "View all" goes for a list: the category page, the parent area, or the hub. [url, label] or null. */
	private static function view_all( string $type, array $a, $block = null ): ?array {
		$label = trim( (string) ( $a['linkLabel'] ?? '' ) );
		$url = '';
		if ( 'service' === $type ) {
			$cat = trim( (string) ( $a['category'] ?? '' ) );
			$terms = 'current' === $cat ? self::current_terms( self::current_object( $block ) ) : ( '' !== $cat && false === strpos( $cat, ',' ) ? array_filter( [ (int) ( get_term_by( 'slug', sanitize_title( $cat ), self::CAT )->term_id ?? 0 ) ] ) : [] );
			if ( 1 === count( $terms ) ) {
				$page = (int) get_term_meta( (int) reset( $terms ), '_lb_page', true );
				$url = $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
			}
			$hub = Roles::hubs()['services'];
			$label = '' !== $label ? $label : __( 'View all services', 'launch-builder' );
		} else {
			$parent = trim( (string) ( $a['parent'] ?? '' ) );
			if ( '' !== $parent && 'top' !== $parent && ! in_array( $parent, self::FOLLOW, true ) ) {
				$pid = self::area_id( $parent );
				$url = $pid && 'publish' === get_post_status( $pid ) ? (string) get_permalink( $pid ) : '';
			} elseif ( 'current' === $parent || 'children' === $parent ) {
				$o = self::current_object( $block );
				$url = $o instanceof \WP_Post && self::AREA === $o->post_type ? (string) get_permalink( $o ) : '';
			}
			$hub = Roles::hubs()['areas'];
			$label = '' !== $label ? $label : __( 'View all areas', 'launch-builder' );
		}
		if ( '' === $url && $hub && 'publish' === get_post_status( $hub ) ) {
			$url = (string) get_permalink( $hub );
		}
		return '' !== $url ? [ $url, $label ] : null;
	}

	/** inArea, else its query-arg spelling in_area (a block always carries inArea, '' by default). */
	private static function in_area_arg( array $a ): string {
		foreach ( [ 'inArea', 'in_area' ] as $k ) {
			$v = isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? trim( (string) $a[ $k ] ) : '';
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/** What an editor sees where a list has nothing to show (visitors see nothing). */
	private static function empty_note( string $type, array $a ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$follows = 'current' === ( $a['category'] ?? '' ) || in_array( $a['parent'] ?? '', self::FOLLOW, true ) || 'current' === self::in_area_arg( $a );
		if ( $follows ) {
			$msg = __( 'Nothing to list here. This list follows the page it is shown on (its service category or service area), so it fills in on those pages.', 'launch-builder' );
		} elseif ( 'area' === $type ) {
			$msg = __( 'No service areas to list. Add some under Service areas (or check the list\'s filter) and this list fills itself.', 'launch-builder' );
		} else {
			$msg = __( 'No services to list. Add some under Services (or check the list\'s filter) and this list fills itself.', 'launch-builder' );
		}
		return '<p class="lb-entries-empty">' . esc_html( $msg ) . '</p>';
	}

	/**
	 * HTML for the launch/entries block.
	 *
	 * @param array          $a     block attributes (see block.json and query()).
	 * @param \WP_Block|null $block the block (its postId context helps "current" in editor previews).
	 */
	public static function render( array $a, $block = null ): string {
		$type = 'area' === ( $a['type'] ?? 'service' ) ? 'area' : 'service';
		$layout = in_array( $a['layout'] ?? 'cards', self::LAYOUTS, true ) ? (string) $a['layout'] : 'cards';
		$cols = max( 1, min( 4, (int) ( $a['columns'] ?? 3 ) ) );
		$variant = in_array( $a['variant'] ?? '', self::VARIANTS, true ) ? ' lb-v-' . $a['variant'] : '';
		$icons = 'icon-cards' === $layout || ! empty( $a['showIcon'] );
		if ( 'category-cards' === $layout && 'service' === $type ) {
			return self::render_categories( $a, $cols, $variant, $block );
		}
		if ( 'matrix' === $layout ) {
			return self::render_matrix( $type, $a, $cols, $block );
		}
		$limit = isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? (int) $a['limit'] : 12;
		$cut = in_array( $layout, self::VIEW_ALL, true ) && $limit > 0;
		$posts = self::query( $type, $cut ? [ 'limit' => $limit + 1 ] + $a : $a, $block );
		$more = $cut && count( $posts ) > $limit ? self::view_all( $type, $a, $block ) : null;
		if ( $cut ) {
			$posts = array_slice( $posts, 0, $limit );
		}
		if ( ! $posts ) {
			return self::empty_note( $type, $a );
		}
		$tplid = '' !== (string) ( $a['template'] ?? '' ) ? Blueprint::find_synced( sanitize_key( (string) $a['template'] ) ) : 0;
		if ( $tplid && 'publish' === get_post_status( $tplid ) ) { // Your own design: a synced pattern rendered once per entry.
			return self::render_custom( $posts, (string) get_post_field( 'post_content', $tplid ), $cols );
		}
		$more_label = esc_html( '' !== trim( (string) ( $a['linkLabel'] ?? '' ) ) ? (string) $a['linkLabel'] : __( 'Learn more', 'launch-builder' ) );
		$show = ! isset( $a['showSummary'] ) || $a['showSummary'];
		$style = ' style="--lb-cols:' . $cols . '"';
		$link = static function ( \WP_Post $p ): string { return esc_url( get_permalink( $p ) ); };
		$title = static function ( \WP_Post $p ): string { return esc_html( get_the_title( $p ) ); };
		$icon = static function ( \WP_Post $p ) use ( $icons ): string { return $icons ? self::icon_html( $p ) : ''; };
		$h = '';
		switch ( $layout ) {
			case 'pills':
			case 'chips':
				$cls = 'pills' === $layout ? 'lb-pill' : 'lb-chip';
				$h = '<div class="lb-entries lb-' . $layout . $variant . '">';
				foreach ( $posts as $p ) {
					$h .= '<a class="' . $cls . '" href="' . $link( $p ) . '"' . self::anchor( $p ) . '>' . ( $icons ? self::icon_html( $p ) : ( 'area' === $type && 'pills' === $layout ? self::pin() : '' ) ) . $title( $p ) . '</a>';
				}
				return $h . '</div>';
			case 'links':
			case 'checklist':
			case 'columns':
			case 'tiles':
			case 'footer-links':
				$cls = [ 'links' => 'lb-links', 'checklist' => 'lb-check', 'columns' => 'lb-cols', 'tiles' => 'lb-tiles', 'footer-links' => 'lb-footer-links' ][ $layout ];
				$h = '<ul class="lb-entries ' . $cls . $variant . '"' . ( 'footer-links' === $layout ? '' : $style ) . '>';
				foreach ( $posts as $p ) {
					$h .= '<li' . ( 'footer-links' === $layout ? '' : self::anchor( $p ) ) . '><a href="' . $link( $p ) . '">' . ( $icons ? self::icon_html( $p ) : ( 'columns' === $layout && 'area' === $type ? self::pin() : '' ) ) . $title( $p ) . '</a></li>';
				}
				if ( $more ) {
					$h .= '<li class="lb-viewall"><a href="' . esc_url( $more[0] ) . '">' . esc_html( $more[1] ) . ' &rarr;</a></li>';
				}
				return $h . '</ul>';
			case 'menu':
				$h = '<ul class="lb-entries lb-menu' . $variant . '"' . $style . '>';
				foreach ( $posts as $p ) {
					$sum = $show ? self::summary( $p ) : '';
					$h .= '<li><a class="lb-menu-link" href="' . $link( $p ) . '">' . $icon( $p ) . '<span class="lb-menu-text"><span class="lb-menu-title">' . $title( $p ) . '</span>' . ( '' !== $sum ? '<span class="lb-menu-desc">' . esc_html( wp_trim_words( $sum, 14 ) ) . '</span>' : '' ) . '</span></a></li>';
				}
				if ( $more ) {
					$h .= '<li class="lb-viewall"><a class="lb-menu-link" href="' . esc_url( $more[0] ) . '"><span class="lb-menu-text"><span class="lb-menu-title">' . esc_html( $more[1] ) . ' &rarr;</span></span></a></li>';
				}
				return $h . '</ul>';
			case 'columns-links':
				return self::render_grouped( $type, $posts, $cols, $variant );
			case 'image-cards':
				$h = '<div class="lb-entries lb-cards lb-image-cards' . $variant . '"' . $style . '>';
				foreach ( $posts as $p ) {
					$img = has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, 'medium_large', [ 'loading' => 'lazy' ] ) : '<span class="lb-entry-media-empty"></span>';
					$sum = $show ? self::summary( $p ) : '';
					$h .= '<article class="lb-card lb-entry-card lb-image-card"' . self::anchor( $p ) . '><a href="' . $link( $p ) . '"><span class="lb-entry-media">' . $img . '</span><span class="lb-entry-body">' . $icon( $p ) . '<h3>' . $title( $p ) . '</h3>' . ( '' !== $sum ? '<p>' . esc_html( $sum ) . '</p>' : '' ) . '<span class="lb-more">' . $more_label . ' &rarr;</span></span></a></article>';
				}
				return $h . '</div>';
		}
		// cards, icon-cards
		$h = '<div class="lb-entries lb-cards' . ( 'icon-cards' === $layout ? ' lb-icon-cards' : '' ) . $variant . '"' . $style . '>';
		foreach ( $posts as $p ) {
			$img = 'cards' === $layout && has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, 'medium_large' ) : '';
			$sum = $show ? self::summary( $p ) : '';
			$h .= '<article class="lb-card lb-entry-card' . ( 'icon-cards' === $layout ? ' lb-icon-card' : '' ) . '"' . self::anchor( $p ) . '><a href="' . $link( $p ) . '">' . $img . $icon( $p ) . '<h3>' . $title( $p ) . '</h3>' . ( '' !== $sum ? '<p>' . esc_html( $sum ) . '</p>' : '' ) . '<span class="lb-more">' . $more_label . ' &rarr;</span></a></article>';
		}
		return $h . '</div>';
	}

	/**
	 * columns-links: services grouped by category (heading = the category page, or its archive), areas grouped under
	 * their parent area when both are in the list (areas whose parent is not listed come first, without a heading).
	 */
	private static function render_grouped( string $type, array $posts, int $cols, string $variant ): string {
		$groups = []; // key => [title, url, posts[]]
		if ( 'service' === $type ) {
			foreach ( $posts as $p ) {
				$terms = get_the_terms( $p, self::CAT );
				if ( ! $terms || is_wp_error( $terms ) ) {
					$groups['~'] = $groups['~'] ?? [ __( 'More services', 'launch-builder' ), '', [] ];
					$groups['~'][2][] = $p;
					continue;
				}
				foreach ( $terms as $t ) {
					if ( ! isset( $groups[ $t->name . '|' . $t->term_id ] ) ) {
						$page = (int) get_term_meta( $t->term_id, '_lb_page', true );
						$url = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : get_term_link( $t );
						$groups[ $t->name . '|' . $t->term_id ] = [ $t->name, is_wp_error( $url ) ? '' : (string) $url, [] ];
					}
					$groups[ $t->name . '|' . $t->term_id ][2][] = $p;
				}
			}
			uksort( $groups, 'strnatcasecmp' );
		} else {
			$listed = [];
			foreach ( $posts as $p ) {
				$listed[ (int) $p->ID ] = $p;
			}
			$groups[''] = [ '', '', [] ];
			foreach ( $posts as $p ) {
				$parent = (int) $p->post_parent;
				if ( $parent && isset( $listed[ $parent ] ) ) {
					$groups[ $parent ] = $groups[ $parent ] ?? [ get_the_title( $listed[ $parent ] ), (string) get_permalink( $listed[ $parent ] ), [] ];
					$groups[ $parent ][2][] = $p;
				}
			}
			foreach ( $posts as $p ) { // Areas that are neither listed under a parent nor a heading themselves.
				if ( ! isset( $groups[ (int) $p->ID ] ) && ! ( $p->post_parent && isset( $listed[ (int) $p->post_parent ] ) ) ) {
					$groups[''][2][] = $p;
				}
			}
			if ( ! $groups[''][2] ) {
				unset( $groups[''] );
			}
		}
		$h = '<div class="lb-entries lb-colinks' . $variant . '" style="--lb-cols:' . $cols . '">';
		foreach ( $groups as $g ) {
			$h .= '<div class="lb-colinks-group">';
			if ( '' !== $g[0] ) {
				$h .= '<p class="lb-colinks-head">' . ( '' !== $g[1] ? '<a href="' . esc_url( $g[1] ) . '">' . esc_html( $g[0] ) . '</a>' : esc_html( $g[0] ) ) . '</p>';
			}
			$h .= '<ul>';
			foreach ( $g[2] as $p ) {
				$h .= '<li' . self::anchor( $p ) . '><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
			}
			$h .= '</ul></div>';
		}
		return $h . '</div>';
	}

	/**
	 * matrix: one group per listed entry, linking each service offered in an area to that area's page at #svc-{slug}
	 * (lists of services on area pages carry those anchors). type area: rows are areas, cells their services; type
	 * service: rows are services, cells the areas offering them. No new URLs.
	 */
	private static function render_matrix( string $type, array $a, int $cols, $block ): string {
		$rows = self::query( $type, $a, $block );
		if ( ! $rows ) {
			return self::empty_note( $type, $a );
		}
		$areas = 'area' === $type ? $rows : self::query( 'area', [ 'limit' => 0 ] );
		$services = 'service' === $type ? $rows : self::query( 'service', [ 'limit' => 0 ] );
		$offers = [];
		foreach ( $areas as $ar ) {
			$offers[ (int) $ar->ID ] = self::area_services( (int) $ar->ID );
		}
		$h = '<div class="lb-entries lb-matrix" style="--lb-cols:' . max( 1, min( 4, $cols ) ) . '">';
		foreach ( $rows as $r ) {
			$cells = '';
			foreach ( 'area' === $type ? $services : $areas as $c ) {
				$area = 'area' === $type ? $r : $c;
				$svc = 'area' === $type ? $c : $r;
				if ( $offers[ (int) $area->ID ] && ! in_array( (int) $svc->ID, $offers[ (int) $area->ID ], true ) ) {
					continue;
				}
				$cells .= '<li><a href="' . esc_url( get_permalink( $area ) . '#svc-' . $svc->post_name ) . '">' . esc_html( get_the_title( $c ) ) . '</a></li>';
			}
			if ( '' !== $cells ) {
				$h .= '<section class="lb-matrix-row"><p class="lb-matrix-head"><a href="' . esc_url( get_permalink( $r ) ) . '">' . esc_html( get_the_title( $r ) ) . '</a></p><ul class="lb-matrix-cells">' . $cells . '</ul></section>';
			}
		}
		return $h . '</div>';
	}

	/**
	 * Per-entry template: the raw blocks of a synced pattern are rendered once per entry and these tokens are filled in:
	 * {{entry.title}} {{entry.summary}} {{entry.image}} {{entry.url}}; use href="#entry-url" for the link.
	 */
	private static function render_custom( array $posts, string $raw, int $cols ): string {
		$h = '<div class="lb-entries lb-custom" style="--lb-cols:' . $cols . '">';
		foreach ( $posts as $p ) {
			$img = get_the_post_thumbnail_url( $p, 'large' );
			Tokens::push( $p ); // Inner blocks resolve entry.* tokens against this entry.
			$html = do_blocks( $raw );
			Tokens::pop();
			$html = str_replace( [ 'href="#entry-url"', '{{entry.url}}' ], [ 'href="' . esc_url( get_permalink( $p ) ) . '"', esc_url( get_permalink( $p ) ) ], $html );
			$html = str_replace( [ '{{entry.title}}', '{{entry.summary}}', '{{entry.image}}' ], [ esc_html( get_the_title( $p ) ), esc_html( self::summary( $p ) ), $img ? esc_url( $img ) : '' ], $html );
			$h .= '<div class="lb-item">' . $html . '</div>';
		}
		return $h . '</div>';
	}

	/** @deprecated Moved to Breadcrumbs::render() (the trail is Breadcrumbs::trail()). */
	public static function breadcrumbs(): string {
		return Breadcrumbs::render();
	}

	/** One card per service category with that category's services as a checklist. */
	private static function render_categories( array $a, int $cols, string $variant = '', $block = null ): string {
		$args = [ 'taxonomy' => self::CAT, 'hide_empty' => true, 'orderby' => 'name' ];
		$cat = trim( (string) ( $a['category'] ?? '' ) );
		if ( 'current' === $cat ) {
			$ids = self::current_terms( self::current_object( $block ) );
			if ( ! $ids ) {
				return self::empty_note( 'service', $a );
			}
			$args['include'] = $ids;
		} elseif ( '' !== $cat ) {
			$args['slug'] = array_map( 'sanitize_title', explode( ',', $cat ) );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lb-entries-empty">' . esc_html__( 'No service categories with services yet.', 'launch-builder' ) . '</p>' : '';
		}
		$more = esc_html( '' !== trim( (string) ( $a['linkLabel'] ?? '' ) ) ? (string) $a['linkLabel'] : __( 'View services', 'launch-builder' ) );
		$h = '<div class="lb-entries lb-cards lb-catcards' . $variant . '" style="--lb-cols:' . $cols . '">';
		foreach ( $terms as $t ) {
			$page = (int) get_term_meta( $t->term_id, '_lb_page', true );
			$url = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : get_term_link( $t );
			$items = self::query( 'service', [ 'category' => $t->slug, 'limit' => isset( $a['limit'] ) && is_numeric( $a['limit'] ) ? (int) $a['limit'] : 6 ] );
			$h .= '<article class="lb-card lb-entry-card"><div class="lb-card-in"><h3><a href="' . esc_url( $url ) . '">' . esc_html( $t->name ) . '</a></h3><ul class="lb-check">';
			foreach ( $items as $p ) {
				$h .= '<li' . self::anchor( $p ) . '><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
			}
			$h .= '</ul><a class="lb-more" href="' . esc_url( $url ) . '">' . $more . ' &rarr;</a></div></article>';
		}
		return $h . '</div>';
	}

	/** Apply attribute overrides ({category, limit, ...}) to every launch/entries block in a built pattern. */
	public static function apply_attrs( array &$blocks, array $attrs ): void {
		$ok = [];
		foreach ( [ 'type' => [ 'service', 'area' ], 'layout' => self::LAYOUTS, 'variant' => array_merge( [ '' ], self::VARIANTS ), 'orderby' => [ '', 'menu_order', 'title' ] ] as $k => $allowed ) {
			if ( isset( $attrs[ $k ] ) && in_array( $attrs[ $k ], $allowed, true ) ) {
				$ok[ $k ] = $attrs[ $k ];
			}
		}
		if ( ! isset( $attrs['inArea'] ) && isset( $attrs['in_area'] ) ) {
			$attrs['inArea'] = $attrs['in_area']; // The query-arg spelling: stored as the block attribute.
		}
		foreach ( [ 'category', 'parent', 'linkLabel', 'template', 'inArea' ] as $k ) {
			if ( isset( $attrs[ $k ] ) && is_scalar( $attrs[ $k ] ) ) {
				$ok[ $k ] = sanitize_text_field( (string) $attrs[ $k ] );
			}
		}
		if ( isset( $attrs['ids'] ) && ( is_array( $attrs['ids'] ) || is_scalar( $attrs['ids'] ) ) ) {
			$ok['ids'] = implode( ',', array_filter( array_map( 'absint', is_array( $attrs['ids'] ) ? $attrs['ids'] : explode( ',', (string) $attrs['ids'] ) ) ) );
		}
		foreach ( [ 'limit' => [ 0, 100 ], 'columns' => [ 1, 4 ] ] as $k => [ $lo, $hi ] ) {
			if ( isset( $attrs[ $k ] ) && is_numeric( $attrs[ $k ] ) ) {
				$ok[ $k ] = max( $lo, min( $hi, (int) $attrs[ $k ] ) );
			}
		}
		foreach ( [ 'showSummary', 'excludeCurrent', 'related', 'showIcon' ] as $k ) {
			if ( isset( $attrs[ $k ] ) ) {
				$ok[ $k ] = (bool) $attrs[ $k ];
			}
		}
		foreach ( $blocks as &$b ) {
			if ( 'launch/entries' === ( $b['blockName'] ?? '' ) ) {
				$b['attrs'] = array_merge( (array) ( $b['attrs'] ?? [] ), $ok );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::apply_attrs( $b['innerBlocks'], $attrs );
			}
		}
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$props = [
			'title'    => [ 'type' => 'string', 'description' => 'Required when the entry is new.' ],
			'slug'     => [ 'type' => 'string' ],
			'key'      => [ 'type' => 'string', 'description' => 'Stable id for re-runs (e.g. from a brief): an entry with this key is updated in place, even when its slug changes (the old URL redirects).' ],
			'summary'  => [ 'type' => 'string', 'description' => 'One or two sentences shown on cards and in the page banner (stored as the excerpt).' ],
			'markdown' => [ 'type' => 'string', 'description' => 'The entry text as Markdown (see get-playbook).' ],
			'order'    => [ 'type' => 'integer' ],
			'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'description' => 'New entries default to publish; an update keeps the current status unless this is given.' ],
			'icon'     => [ 'type' => 'string', 'description' => 'Kadence icon name, case kept (fe_settings, fe_mapPin).' ],
			'seo'      => [ 'type' => 'object', 'description' => '{title, description, keyword}' ],
			'items'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Many entries in one call, each with the fields above. At most 100 are handled per call: the result says how many remain (call again with offset).' ],
			'offset'   => [ 'type' => 'integer', 'description' => 'With items: start at this index (from next_offset).' ],
		];
		Abilities::add( 'add-service', 'Add or update a service', 'Creates (or updates, matched by key, else slug) a Service entry. Every list of services on the site (cards, checklists, menus with auto items, category cards, related rows) picks it up automatically, and it gets its own page from the service template. categories = service category names or slugs (created if new; category = one name, older form). items = many services in one call. Returns count, remaining (items left for another call), errors and menus_updated.',
			[ 'properties' => $props + [ 'category' => [ 'type' => 'string' ], 'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ], [ __CLASS__, 'add_service' ], 'edit_pages' );
		Abilities::add( 'add-area', 'Add or update a service area', 'Creates (or updates, matched by key, else by slug under its parent) a Service Area entry such as a city. Every service-area list on the site picks it up automatically, and it gets its own page from the area template. parent = another area (a county, for example) by id, slug or path ("texas/travis-county"). services = the services offered there (ids, slugs or titles; none = every service): lists with inArea "current" show them. items = many at once (see count/remaining).',
			[ 'properties' => $props + [ 'parent' => [ 'type' => 'string' ], 'services' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ], [ __CLASS__, 'add_area' ], 'edit_pages' );
		Abilities::add( 'list-entries', 'List services or service areas', 'The services or service areas on the site (what the dynamic lists show), with their categories/parent path, summary, icon and URL. Paginated: limit (max 200) and offset; total and next_offset say what is left.',
			[ 'properties' => [ 'type' => [ 'type' => 'string', 'enum' => [ 'service', 'area' ] ], 'category' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ] ], 'limit' => [ 'type' => 'integer' ], 'offset' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'list_entries' ], 'edit_pages', [ 'readonly' => true ] );
		Abilities::add( 'delete-entry', 'Remove a service or service area', 'Moves an entry to the trash (restorable from the admin). Every list and auto menu item stops showing it at once.',
			[ 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], [ __CLASS__, 'delete_entry' ], 'edit_pages' );
	}

	/** Service category term ids for names or slugs (created when new). */
	private static function term_ids( array $names, array &$warnings ): array {
		$out = [];
		foreach ( $names as $n ) {
			$n = sanitize_text_field( is_scalar( $n ) ? (string) $n : '' );
			if ( '' === $n ) {
				continue;
			}
			$t = get_term_by( 'slug', sanitize_title( $n ), self::CAT ) ?: get_term_by( 'name', $n, self::CAT );
			if ( $t instanceof \WP_Term ) {
				$out[] = (int) $t->term_id;
				continue;
			}
			$new = wp_insert_term( $n, self::CAT );
			if ( is_wp_error( $new ) ) {
				$warnings[] = 'category "' . $n . '": ' . $new->get_error_message();
			} else {
				$out[] = (int) $new['term_id'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function upsert( string $type, array $in ) {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$warnings = [];
		$title = sanitize_text_field( is_scalar( $in['title'] ?? '' ) ? (string) ( $in['title'] ?? '' ) : '' );
		$key = self::clean_key( $in['key'] ?? '' );
		$parent = null; // null = not given
		$pref = self::AREA === $pt && isset( $in['parent'] ) && is_scalar( $in['parent'] ) ? trim( (string) $in['parent'] ) : '';
		if ( in_array( $pref, [ 'top', '0' ], true ) ) {
			$parent = 0; // Move to (or create at) the top level.
		} elseif ( '' !== $pref ) {
			$parent = self::area_id( $in['parent'] );
			if ( ! $parent ) {
				return new \WP_Error( 'lb_parent', 'Unknown parent area: ' . sanitize_text_field( (string) $in['parent'] ) . ' (give an id, a slug or a path such as texas/travis-county).', [ 'status' => 400 ] );
			}
		}
		$found = '' !== $key ? self::find_by_key( $pt, $key ) : 0;
		$slug = sanitize_title( isset( $in['slug'] ) && is_scalar( $in['slug'] ) && '' !== (string) $in['slug'] ? (string) $in['slug'] : $title );
		if ( ! $found && '' !== $slug ) {
			if ( self::AREA === $pt ) {
				$found = self::find_area( $slug, $parent );
				if ( ! $found && null === $parent && count( get_posts( [ 'post_type' => self::AREA, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 2, 'fields' => 'ids' ] ) ) > 1 ) {
					return new \WP_Error( 'lb_ambiguous', 'Several areas are called "' . $slug . '": give parent (id, slug or path) to say which one.', [ 'status' => 400 ] );
				}
			} else {
				$hit = get_posts( [ 'post_type' => self::SERVICE, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
				$found = $hit ? (int) $hit[0] : 0;
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
		if ( self::AREA === $pt && null !== $parent ) {
			if ( $found && $parent === $found ) {
				return new \WP_Error( 'lb_parent', 'An area cannot be its own parent.', [ 'status' => 400 ] );
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
		if ( self::AREA === $pt && isset( $in['services'] ) && is_array( $in['services'] ) ) {
			$sids = [];
			foreach ( $in['services'] as $ref ) {
				$sid = self::service_id( $ref );
				$sid ? $sids[] = $sid : $warnings[] = 'unknown service: ' . sanitize_text_field( is_scalar( $ref ) ? (string) $ref : '' );
			}
			self::set_services( $id, $sids );
		}
		Compat::write_seo_meta( $id, (array) ( $in['seo'] ?? [] ) ); // The active SEO plugin only; %variables% survive.
		if ( self::SERVICE === $pt && ( ! empty( $in['categories'] ) || ! empty( $in['category'] ) ) ) {
			$names = ! empty( $in['categories'] ) ? (array) $in['categories'] : [ $in['category'] ];
			$tids = self::term_ids( $names, $warnings );
			if ( $tids ) {
				wp_set_object_terms( $id, $tids, self::CAT );
			}
		}
		if ( ! $found ) {
			// Filter launch_builder_entry_layout ( array layout for Content::set_layout, int id, bool created ): the chrome
			// decides the title area (no _kad_post_title is written here any more).
			$layout = (array) apply_filters( 'launch_builder_entry_layout', [ 'width' => 'fullwidth', 'style' => 'unboxed', 'padding' => 'hide' ], $id, true );
		} else {
			$layout = (array) apply_filters( 'launch_builder_entry_layout', [], $id, false );
		}
		if ( $layout ) {
			Content::set_layout( $id, $layout );
		}
		$p = get_post( $id );
		$out = [ 'id' => $id, 'title' => $p ? $p->post_title : $title, 'slug' => $p ? $p->post_name : $slug, 'status' => $p ? $p->post_status : '', 'url' => get_permalink( $id ), 'created' => ! $found ];
		if ( self::AREA === $pt ) {
			$out['path'] = (string) get_page_uri( $id );
		}
		if ( $before && $p && $before->post_name !== $p->post_name ) {
			$out['renamed_from'] = $before->post_name;
		}
		if ( '' !== $key ) {
			$out['key'] = $key;
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
		wp_defer_term_counting( true );
		try {
			foreach ( $slice as $i => $it ) {
				$r = is_array( $it ) ? self::upsert( $type, $it ) : new \WP_Error( 'lb_item', 'Each item must be an object.' );
				if ( is_wp_error( $r ) ) {
					$errors[] = [ 'index' => $offset + $i, 'title' => is_array( $it ) && is_scalar( $it['title'] ?? null ) ? (string) $it['title'] : '', 'error' => $r->get_error_message() ];
					continue;
				}
				$out[] = $r;
			}
		} finally {
			wp_defer_term_counting( false );
		}
		if ( ! $out && $errors ) {
			return new \WP_Error( 'lb_entries', $errors[0]['error'] . ( count( $errors ) > 1 ? ' (+' . ( count( $errors ) - 1 ) . ' more)' : '' ), [ 'status' => 400 ] );
		}
		$remaining = max( 0, count( $items ) - $offset - count( $slice ) );
		$res = [ 'count' => count( $out ), 'remaining' => $remaining, 'entries' => $out ];
		if ( $remaining ) {
			$res['next_offset'] = $offset + count( $slice );
			$res['note'] = sprintf( 'Handled %d of %d items: call again with the same items and offset %d.', count( $slice ), count( $items ), $res['next_offset'] );
		} else {
			$res['note'] = 'Lists of ' . ( 'area' === $type ? 'service areas' : 'services' ) . ' on the site include these now.';
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
		$args = [ 'post_type' => $pt, 'post_status' => $status, 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'ignore_sticky_posts' => true ];
		if ( ! empty( $input['category'] ) && self::SERVICE === $pt ) {
			$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'slug', 'terms' => sanitize_title( (string) $input['category'] ) ] ];
		}
		$q = new \WP_Query( $args );
		$out = [];
		foreach ( $q->posts as $p ) {
			$row = [ 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status, 'order' => (int) $p->menu_order, 'url' => get_permalink( $p ), 'summary' => (string) $p->post_excerpt, 'icon' => (string) get_post_meta( $p->ID, '_lb_icon', true ) ];
			$key = (string) get_post_meta( $p->ID, '_lb_key', true );
			if ( '' !== $key ) {
				$row['key'] = $key;
			}
			if ( self::SERVICE === $pt ) {
				$terms = wp_get_object_terms( $p->ID, self::CAT );
				$terms = is_wp_error( $terms ) ? [] : $terms;
				$row['category'] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
				$row['categories'] = array_values( wp_list_pluck( $terms, 'slug' ) );
			} else {
				$row['path'] = (string) get_page_uri( $p );
				if ( $p->post_parent ) {
					$row['parent'] = get_the_title( $p->post_parent );
				}
				$row['services'] = array_values( array_map( 'intval', (array) get_post_meta( $p->ID, '_lb_services', false ) ) );
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

	/** Module hook: how to use the live lists. */
	public static function playbook( array &$pb ): void {
		$pb['live_lists'] = [
			'block'    => 'launch/entries lists services or service areas live (attributes type service|area, layout, variant, showIcon, columns, limit, filters). New lists and patterns set limit explicitly (0 = all; the block default is 12). In patterns use the slot {"@entries": {...attributes}}.',
			'layouts'  => self::layout_labels(),
			'variants' => self::VARIANTS,
			'filters'  => 'category: slugs or "current" (the category page/archive/service being viewed); parent (areas): "top", an area id/slug/path, "current" (children of the area viewed), "children" (all below it), "siblings"; inArea (services): "current" or an area = the services offered there (add-area services; none = all); ids; orderby menu_order|title; related; excludeCurrent. Unknown names list nothing.',
			'matrix'   => 'Links each service offered in an area to that area page at #svc-{slug} (no new URLs).',
		];
	}

	/** Module hook: audit checks this module owns. */
	public static function audit_checks(): array {
		return [
			'entry_stale_services'   => [ 'entries', [ __CLASS__, 'audit_stale_services' ] ],
			'entry_missing_summary'  => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'summary' ); }, true ],
			'entry_missing_text'     => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'text' ); }, true ],
			'entry_missing_image'    => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'image' ); }, true ],
			'entry_missing_category' => [ 'entries', static function ( $args ) { return self::audit_entries( $args, 'category' ); }, true ],
		];
	}

	/** Areas whose _lb_services rows name services that are gone (they are ignored when listing). */
	public static function audit_stale_services( $args = [] ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')", '_lb_services', self::AREA ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$live = array_flip( array_map( static function ( $p ) { return (int) $p->ID; }, self::query( 'service', [ 'limit' => 0 ] ) ) );
		$stale = [];
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $live[ (int) $r->meta_value ] ) ) {
				$stale[ (int) $r->post_id ][] = (int) $r->meta_value;
			}
		}
		$out = [];
		foreach ( $stale as $area => $ids ) {
			$out[] = [ 'severity' => 'warning', 'code' => 'entry_stale_services', 'object_type' => self::AREA, 'id' => $area, 'url' => (string) get_permalink( $area ), 'message' => sprintf( '"%s" lists services that are not published any more (ids %s); lists ignore them.', get_the_title( $area ), implode( ', ', $ids ) ), 'fix' => 'add-area with services (or the Card details box) to set the list again.' ];
		}
		return $out;
	}

	/** Published entries missing a summary, text, image or (services) a category, by ID batches. */
	private static function audit_entries( $args, string $what ): array {
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$where = static function ( $w ) use ( $after ) { global $wpdb; return $w . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); };
		add_filter( 'posts_where', $where );
		$posts = get_posts( [ 'post_type' => 'category' === $what ? [ self::SERVICE ] : [ self::SERVICE, self::AREA ], 'post_status' => 'publish', 'numberposts' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ] );
		remove_filter( 'posts_where', $where );
		$out = [];
		foreach ( $posts as $p ) {
			$miss = ( 'summary' === $what && '' === trim( (string) $p->post_excerpt ) && '' === self::legacy_summary( (int) $p->ID ) )
				|| ( 'text' === $what && '' === trim( wp_strip_all_tags( (string) $p->post_content ) ) )
				|| ( 'image' === $what && ! has_post_thumbnail( $p ) )
				|| ( 'category' === $what && ! wp_get_object_terms( $p->ID, self::CAT, [ 'fields' => 'ids' ] ) );
			if ( $miss ) {
				$out[] = [ 'severity' => 'image' === $what ? 'info' : 'warning', 'code' => 'entry_missing_' . $what, 'object_type' => $p->post_type, 'id' => (int) $p->ID, 'url' => (string) get_permalink( $p ), 'message' => sprintf( '"%s" has no %s.', $p->post_title, 'text' === $what ? 'text' : $what ), 'fix' => 'summary' === $what ? 'add-service/add-area summary (the excerpt).' : ( 'text' === $what ? 'add-service/add-area markdown.' : ( 'image' === $what ? 'Set a featured image.' : 'add-service categories.' ) ) ];
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
}
