<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Services and service areas as post types, so lists on the site are queries, not copies.
 *
 *  lb_service (+ taxonomy lb_service_cat)   /services/{service}/
 *  lb_area (hierarchical)                   /service-areas/{city}/
 *
 * The dynamic block launch/entries lists them (cards, checklist, pills, links, columns, category cards), so adding or
 * removing an entry updates every section that shows the list. Each entry is rendered through one template (a synced
 * pattern, service_template / area_template) with the launch/post-body block holding the entry's own text.
 * Fields are plain post meta (_lb_summary, _lb_icon), so a Meta Box field group using the same ids works as well.
 */
final class Entries {

	const SERVICE = 'lb_service';
	const AREA    = 'lb_area';
	const CAT     = 'lb_service_cat';
	const FLUSHED = 'launch_builder_entries_flushed';

	/** @var string|null raw block markup of the entry being rendered through its template. */
	private static $body = null;

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush' ], 99 );
		add_filter( 'the_content', [ __CLASS__, 'wrap' ], 8 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ registration */

	public static function register(): void {
		$labels = static function ( string $one, string $many ): array {
			return [ 'name' => $many, 'singular_name' => $one, 'menu_name' => $many, 'all_items' => 'All ' . strtolower( $many ), 'add_new_item' => 'Add ' . strtolower( $one ), 'edit_item' => 'Edit ' . strtolower( $one ), 'new_item' => 'New ' . strtolower( $one ), 'view_item' => 'View ' . strtolower( $one ), 'search_items' => 'Search ' . strtolower( $many ), 'not_found' => 'None yet' ];
		};
		$supports = [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'custom-fields' ];
		register_post_type( self::SERVICE, [
			'labels' => $labels( 'Service', 'Services' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-hammer', 'menu_position' => 21,
			'supports' => $supports, 'has_archive' => false, 'hierarchical' => false, 'rewrite' => [ 'slug' => 'services', 'with_front' => false ],
		] );
		register_taxonomy( self::CAT, [ self::SERVICE ], [
			'labels' => [ 'name' => 'Service categories', 'singular_name' => 'Service category', 'menu_name' => 'Categories', 'all_items' => 'All categories', 'add_new_item' => 'Add category' ],
			'hierarchical' => true, 'public' => true, 'show_in_rest' => true, 'show_admin_column' => true, 'rewrite' => [ 'slug' => 'service-category', 'with_front' => false ],
		] );
		register_post_type( self::AREA, [
			'labels' => $labels( 'Service area', 'Service areas' ), 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-location-alt', 'menu_position' => 22,
			'supports' => $supports, 'has_archive' => false, 'hierarchical' => true, 'rewrite' => [ 'slug' => 'service-areas', 'with_front' => false ],
		] );
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			foreach ( [ '_lb_summary', '_lb_icon' ] as $m ) {
				register_post_meta( $pt, $m, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => static function () { return current_user_can( 'edit_posts' ); } ] );
			}
		}
		register_term_meta( self::CAT, '_lb_page', [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => static function () { return current_user_can( 'manage_categories' ); } ] );
	}

	/** New permalinks need one rewrite flush, once per plugin version. */
	public static function maybe_flush(): void {
		if ( get_option( self::FLUSHED ) !== LB_VERSION . '-1' ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSHED, LB_VERSION . '-1', false );
		}
	}

	/* ------------------------------------------------------------------ admin fields (plain post meta) */

	public static function meta_boxes(): void {
		foreach ( [ self::SERVICE, self::AREA ] as $pt ) {
			add_meta_box( 'lb_entry', 'Card details', [ __CLASS__, 'box' ], $pt, 'side' );
		}
	}

	public static function box( \WP_Post $post ): void {
		wp_nonce_field( 'lb_entry', 'lb_entry_nonce' );
		echo '<p><label><strong>Short summary</strong> (shown on cards)<br><textarea name="lb_summary" rows="3" style="width:100%">' . esc_textarea( (string) get_post_meta( $post->ID, '_lb_summary', true ) ) . '</textarea></label></p>';
		echo '<p><label><strong>Icon</strong> (optional, e.g. fe_settings)<br><input type="text" name="lb_icon" style="width:100%" value="' . esc_attr( (string) get_post_meta( $post->ID, '_lb_icon', true ) ) . '"></label></p>';
		echo '<p class="description">Saved as plain post meta <code>_lb_summary</code> and <code>_lb_icon</code>. Order = the Order box (menu order).</p>';
	}

	public static function save_meta( int $id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ self::SERVICE, self::AREA ], true ) || empty( $_POST['lb_entry_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lb_entry_nonce'] ) ), 'lb_entry' ) || ! current_user_can( 'edit_post', $id ) ) { // phpcs:ignore
			return;
		}
		update_post_meta( $id, '_lb_summary', sanitize_text_field( wp_unslash( $_POST['lb_summary'] ?? '' ) ) ); // phpcs:ignore
		update_post_meta( $id, '_lb_icon', sanitize_key( wp_unslash( $_POST['lb_icon'] ?? '' ) ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ template for single entries */

	/** Singles are rendered through a synced pattern; the entry's own text goes where launch/post-body is. */
	public static function wrap( $content ) {
		if ( ! is_singular( [ self::SERVICE, self::AREA ] ) || ! in_the_loop() || ! is_main_query() ) {
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
			return current_user_can( 'edit_posts' ) ? '<p class="lb-entries-empty">The text of this entry appears here.</p>' : '';
		}
		return '<div class="lb-post-body">' . do_blocks( $raw ) . '</div>';
	}

	/* ------------------------------------------------------------------ querying */

	private static function id_by_slug( string $type_pt, string $slug_or_title ): int {
		$p = get_posts( [ 'post_type' => $type_pt, 'name' => sanitize_title( $slug_or_title ), 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
		return $p ? (int) $p[0] : 0;
	}

	/** @return \WP_Post[] */
	public static function query( string $type, array $a = [] ): array {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$args = [ 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 100, (int) ( $a['limit'] ?? 24 ) ) ), 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'no_found_rows' => true ];
		if ( 'title' === ( $a['orderby'] ?? '' ) ) {
			$args['orderby'] = [ 'title' => 'ASC' ];
		}
		$cat = (string) ( $a['category'] ?? '' );
		if ( self::SERVICE === $pt && '' !== $cat ) {
			$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $cat ) ) ] ];
		}
		$parent = (string) ( $a['parent'] ?? '' );
		if ( self::AREA === $pt && '' !== $parent ) {
			$args['post_parent'] = 'top' === $parent ? 0 : ( is_numeric( $parent ) ? (int) $parent : self::id_by_slug( self::AREA, $parent ) );
		}
		$cur = (int) get_the_ID();
		if ( ! empty( $a['related'] ) && $cur && self::SERVICE === get_post_type( $cur ) && self::SERVICE === $pt ) { // Same category as the page being viewed.
			$terms = wp_get_object_terms( $cur, self::CAT, [ 'fields' => 'ids' ] );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'term_id', 'terms' => $terms ] ];
			}
		}
		if ( ( ! empty( $a['excludeCurrent'] ) || ! empty( $a['related'] ) ) && $cur && $pt === get_post_type( $cur ) ) {
			$args['post__not_in'] = [ $cur ];
		}
		return get_posts( $args );
	}

	private static function summary( \WP_Post $p ): string {
		$s = (string) get_post_meta( $p->ID, '_lb_summary', true );
		if ( '' === $s ) {
			$s = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 22 );
		}
		return $s;
	}

	private static function pin(): string {
		return '<svg class="lb-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
	}

	/** HTML for the launch/entries block. */
	public static function render( array $a ): string {
		$type = 'area' === ( $a['type'] ?? 'service' ) ? 'area' : 'service';
		$layout = in_array( $a['layout'] ?? 'cards', [ 'cards', 'checklist', 'pills', 'links', 'columns', 'category-cards', 'tiles' ], true ) ? $a['layout'] : 'cards';
		$cols = max( 1, min( 4, (int) ( $a['columns'] ?? 3 ) ) );
		if ( 'category-cards' === $layout && 'service' === $type ) {
			return self::render_categories( $a, $cols );
		}
		$posts = self::query( $type, $a );
		if ( ! $posts ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lb-entries-empty">No ' . ( 'area' === $type ? 'service areas' : 'services' ) . ' yet. Add some under ' . ( 'area' === $type ? 'Service areas' : 'Services' ) . ' and this list fills itself.</p>' : '';
		}
		$tplid = '' !== (string) ( $a['template'] ?? '' ) ? Blueprint::find_synced( sanitize_key( (string) $a['template'] ) ) : 0;
		if ( $tplid && 'publish' === get_post_status( $tplid ) ) { // Your own design: a synced pattern rendered once per entry.
			return self::render_custom( $posts, (string) get_post_field( 'post_content', $tplid ), $cols );
		}
		$more = esc_html( '' !== trim( (string) ( $a['linkLabel'] ?? '' ) ) ? (string) $a['linkLabel'] : 'Learn more' );
		$show = ! isset( $a['showSummary'] ) || $a['showSummary'];
		$style = ' style="--lb-cols:' . $cols . '"';
		$h = '';
		switch ( $layout ) {
			case 'pills':
				$h = '<div class="lb-entries lb-pills">';
				foreach ( $posts as $p ) {
					$h .= '<a class="lb-pill" href="' . esc_url( get_permalink( $p ) ) . '">' . ( 'area' === $type ? self::pin() : '' ) . esc_html( get_the_title( $p ) ) . '</a>';
				}
				return $h . '</div>';
			case 'links':
			case 'checklist':
			case 'columns':
			case 'tiles':
				$cls = [ 'links' => 'lb-links', 'checklist' => 'lb-check', 'columns' => 'lb-cols', 'tiles' => 'lb-tiles' ][ $layout ];
				$h = '<ul class="lb-entries ' . $cls . '"' . $style . '>';
				foreach ( $posts as $p ) {
					$h .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . ( 'columns' === $layout && 'area' === $type ? self::pin() : '' ) . esc_html( get_the_title( $p ) ) . '</a></li>';
				}
				return $h . '</ul>';
		}
		$h = '<div class="lb-entries lb-cards"' . $style . '>';
		foreach ( $posts as $p ) {
			$img = has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, 'medium_large' ) : '';
			$sum = $show ? self::summary( $p ) : '';
			$h .= '<article class="lb-card"><a href="' . esc_url( get_permalink( $p ) ) . '">' . $img . '<h3>' . esc_html( get_the_title( $p ) ) . '</h3>' . ( '' !== $sum ? '<p>' . esc_html( $sum ) . '</p>' : '' ) . '<span class="lb-more">' . $more . ' &rarr;</span></a></article>';
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
	private static function render_categories( array $a, int $cols ): string {
		$args = [ 'taxonomy' => self::CAT, 'hide_empty' => true, 'orderby' => 'name' ];
		if ( ! empty( $a['category'] ) ) {
			$args['slug'] = array_map( 'sanitize_title', explode( ',', (string) $a['category'] ) );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lb-entries-empty">No service categories with services yet.</p>' : '';
		}
		$more = esc_html( '' !== trim( (string) ( $a['linkLabel'] ?? '' ) ) ? (string) $a['linkLabel'] : 'View services' );
		$h = '<div class="lb-entries lb-cards lb-catcards" style="--lb-cols:' . $cols . '">';
		foreach ( $terms as $t ) {
			$page = (int) get_term_meta( $t->term_id, '_lb_page', true );
			$url = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : get_term_link( $t );
			$items = self::query( 'service', [ 'category' => $t->slug, 'limit' => (int) ( $a['limit'] ?? 6 ) ] );
			$h .= '<article class="lb-card"><div class="lb-card-in"><h3><a href="' . esc_url( $url ) . '">' . esc_html( $t->name ) . '</a></h3><ul class="lb-check">';
			foreach ( $items as $p ) {
				$h .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
			}
			$h .= '</ul><a class="lb-more" href="' . esc_url( $url ) . '">' . $more . ' &rarr;</a></div></article>';
		}
		return $h . '</div>';
	}

	/** Apply attribute overrides ({category, limit, ...}) to every launch/entries block in a built pattern. */
	public static function apply_attrs( array &$blocks, array $attrs ): void {
		$ok = [];
		foreach ( [ 'type' => [ 'service', 'area' ], 'layout' => [ 'cards', 'checklist', 'pills', 'links', 'columns', 'category-cards', 'tiles' ] ] as $k => $allowed ) {
			if ( isset( $attrs[ $k ] ) && in_array( $attrs[ $k ], $allowed, true ) ) {
				$ok[ $k ] = $attrs[ $k ];
			}
		}
		foreach ( [ 'category', 'parent', 'linkLabel', 'orderby', 'template' ] as $k ) {
			if ( isset( $attrs[ $k ] ) && is_scalar( $attrs[ $k ] ) ) {
				$ok[ $k ] = sanitize_text_field( (string) $attrs[ $k ] );
			}
		}
		foreach ( [ 'limit' => [ 1, 100 ], 'columns' => [ 1, 4 ] ] as $k => [ $lo, $hi ] ) {
			if ( isset( $attrs[ $k ] ) ) {
				$ok[ $k ] = max( $lo, min( $hi, (int) $attrs[ $k ] ) );
			}
		}
		foreach ( [ 'showSummary', 'excludeCurrent', 'related' ] as $k ) {
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
		$props = [ 'title' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'summary' => [ 'type' => 'string', 'description' => 'One or two sentences shown on cards.' ], 'markdown' => [ 'type' => 'string', 'description' => 'The entry text as Markdown (see get-playbook).' ], 'order' => [ 'type' => 'integer' ], 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ], 'icon' => [ 'type' => 'string' ], 'seo' => [ 'type' => 'object', 'description' => '{title, description, keyword}' ] ];
		Abilities::add( 'add-service', 'Add or update a service', 'Creates (or updates, matched by slug) a Service entry. Every list of services on the site (cards, checklists, menus of category cards, related rows) picks it up automatically, and it gets its own page from the service template. category = a service category name (created if new). items = an array of services to add in one call (each with the same fields).',
			[ 'properties' => $props + [ 'category' => [ 'type' => 'string' ], 'items' => [ 'type' => 'array' ] ] ], [ __CLASS__, 'add_service' ], 'edit_pages' );
		Abilities::add( 'add-area', 'Add or update a service area', 'Creates (or updates, matched by slug) a Service Area entry such as a city. Every service-area list on the site picks it up automatically, and it gets its own page from the area template. parent = another area (a county, for example) by name or slug. items = an array to add many at once.',
			[ 'properties' => $props + [ 'parent' => [ 'type' => 'string' ], 'items' => [ 'type' => 'array' ] ] ], [ __CLASS__, 'add_area' ], 'edit_pages' );
		Abilities::add( 'list-entries', 'List services or service areas', 'The services or service areas on the site (what the dynamic lists show), with their category/parent and URL.',
			[ 'properties' => [ 'type' => [ 'type' => 'string', 'enum' => [ 'service', 'area' ] ], 'category' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ] ] ] ], [ __CLASS__, 'list_entries' ], 'edit_pages', [ 'readonly' => true ] );
		Abilities::add( 'delete-entry', 'Remove a service or service area', 'Moves an entry to the trash (restorable from the admin). Every list stops showing it at once.',
			[ 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], [ __CLASS__, 'delete_entry' ], 'edit_pages' );
	}

	private static function upsert( string $type, array $in ) {
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$title = sanitize_text_field( $in['title'] ?? '' );
		if ( '' === $title ) {
			return new \WP_Error( 'lb_title', 'title is required.', [ 'status' => 400 ] );
		}
		$slug = sanitize_title( $in['slug'] ?? $title );
		$found = get_posts( [ 'post_type' => $pt, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
		$args = [ 'post_type' => $pt, 'post_title' => $title, 'post_name' => $slug, 'post_status' => in_array( $in['status'] ?? 'publish', [ 'publish', 'draft' ], true ) ? ( $in['status'] ?? 'publish' ) : 'publish' ];
		if ( isset( $in['order'] ) ) {
			$args['menu_order'] = (int) $in['order'];
		}
		if ( isset( $in['summary'] ) ) {
			$args['post_excerpt'] = sanitize_text_field( (string) $in['summary'] ); // the template banner shows the excerpt
		}
		if ( ! empty( $in['markdown'] ) ) {
			$args['post_content'] = wp_slash( Content::from_markdown( (string) $in['markdown'] ) );
		}
		if ( self::AREA === $pt && ! empty( $in['parent'] ) ) {
			$pid = self::id_by_slug( self::AREA, (string) $in['parent'] );
			if ( $pid ) {
				$args['post_parent'] = $pid;
			}
		}
		if ( $found ) {
			$args['ID'] = (int) $found[0];
			$id = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( isset( $in['summary'] ) ) {
			update_post_meta( $id, '_lb_summary', sanitize_text_field( (string) $in['summary'] ) );
		}
		if ( isset( $in['icon'] ) ) {
			update_post_meta( $id, '_lb_icon', sanitize_key( (string) $in['icon'] ) );
		}
		$seo = (array) ( $in['seo'] ?? [] );
		if ( ! empty( $seo['title'] ) ) {
			update_post_meta( $id, 'rank_math_title', sanitize_text_field( $seo['title'] ) );
			update_post_meta( $id, '_yoast_wpseo_title', sanitize_text_field( $seo['title'] ) );
		}
		if ( ! empty( $seo['description'] ) ) {
			update_post_meta( $id, 'rank_math_description', sanitize_text_field( $seo['description'] ) );
			update_post_meta( $id, '_yoast_wpseo_metadesc', sanitize_text_field( $seo['description'] ) );
		}
		if ( ! empty( $seo['keyword'] ) ) {
			update_post_meta( $id, 'rank_math_focus_keyword', sanitize_text_field( $seo['keyword'] ) );
		}
		if ( self::SERVICE === $pt && ! empty( $in['category'] ) ) {
			$cn = sanitize_text_field( (string) $in['category'] );
			$term = term_exists( sanitize_title( $cn ), self::CAT );
			if ( ! $term ) {
				$term = wp_insert_term( $cn, self::CAT );
			}
			$tid = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
			if ( $tid ) {
				wp_set_object_terms( $id, [ $tid ], self::CAT );
			}
		}
		$title_mode = 'elements' === get_option( Chrome::OPT_MODE, 'patterns' ) ? 'show' : 'hide'; // elements mode: the shared hero element is the title area
		// Filter launch_builder_entry_layout ( array layout for Content::set_layout, int id, bool created ): chrome decides.
		Content::set_layout( (int) $id, (array) apply_filters( 'launch_builder_entry_layout', $found ? [ 'title' => $title_mode ] : [ 'type' => 'landing', 'title' => $title_mode ], (int) $id, ! $found ) );

		return [ 'id' => (int) $id, 'title' => $title, 'slug' => $slug, 'url' => get_permalink( $id ), 'created' => ! $found ];
	}

	private static function add_many( string $type, $input ) {
		$items = ! empty( $input['items'] ) ? (array) $input['items'] : [ (array) $input ];
		$out = [];
		foreach ( array_slice( $items, 0, 100 ) as $it ) {
			$r = self::upsert( $type, (array) $it );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$out[] = $r;
		}
		return [ 'count' => count( $out ), 'entries' => $out, 'note' => 'Lists of ' . ( 'area' === $type ? 'service areas' : 'services' ) . ' on the site include these now.' ];
	}

	public static function add_service( $input ) {
		return self::add_many( 'service', (array) $input );
	}

	public static function add_area( $input ) {
		return self::add_many( 'area', (array) $input );
	}

	public static function list_entries( $input ) {
		$type = 'area' === ( $input['type'] ?? 'service' ) ? 'area' : 'service';
		$pt = 'area' === $type ? self::AREA : self::SERVICE;
		$args = [ 'post_type' => $pt, 'post_status' => ( $input['status'] ?? 'any' ), 'numberposts' => 200, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ];
		if ( ! empty( $input['category'] ) && self::SERVICE === $pt ) {
			$args['tax_query'] = [ [ 'taxonomy' => self::CAT, 'field' => 'slug', 'terms' => sanitize_title( $input['category'] ) ] ];
		}
		$out = [];
		foreach ( get_posts( $args ) as $p ) {
			$row = [ 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status, 'url' => get_permalink( $p ), 'summary' => (string) get_post_meta( $p->ID, '_lb_summary', true ) ];
			if ( self::SERVICE === $pt ) {
				$row['category'] = implode( ', ', wp_get_object_terms( $p->ID, self::CAT, [ 'fields' => 'names' ] ) );
			} elseif ( $p->post_parent ) {
				$row['parent'] = get_the_title( $p->post_parent );
			}
			$out[] = $row;
		}
		return [ 'type' => $type, 'count' => count( $out ), 'entries' => $out ];
	}

	public static function delete_entry( $input ) {
		$id = (int) ( $input['id'] ?? 0 );
		$p = $id ? get_post( $id ) : null;
		if ( ! $p || ! in_array( $p->post_type, [ self::SERVICE, self::AREA ], true ) || ! current_user_can( 'delete_post', $id ) ) {
			return new \WP_Error( 'lb_entry', 'Unknown service/area, or you cannot remove it.', [ 'status' => 404 ] );
		}
		wp_trash_post( $id );
		return [ 'id' => $id, 'trashed' => true, 'title' => $p->post_title ];
	}
}
