<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Page roles: what kind of page is being shown (home, a service, a contact page...). Designs place the native Kadence
 * objects by them (Element rules, page layouts), and tokens, previews and the site check read them, so a role is
 * resolved in one place.
 *
 * Where a role comes from:
 *  - front page = home, posts page = blog (never overridable); services, areas and posts by post type;
 *  - other pages: the _lb_role page meta (page|legal|conversion|thanks), else the hubs option (services_hub/areas_hub),
 *    else page;
 *  - WooCommerce pages, other post types and other archives: other (no chrome). Opt a post type in with the filter
 *    launch_builder_role_post_types ( [ post_type => role ] ).
 *
 * Roles::current() resolution order (memoised only once the main query exists, i.e. after the 'wp' action):
 *  1. Context::simulate() value.
 *  2. After 'wp' on the front end: the main query (front page, 404, search, category archive, blog views, singulars).
 *  3. No main query (REST, admin, AJAX, CLI, cron): the request post_id (REST/admin/AJAX only, readable posts only), else
 *     the global $post (the block renderer and core/post-template set it, so block context lands here too).
 *  4. Template posts (kadence_element, wp_block, kadence_header, kadence_navigation, kadence_form): the first role in
 *     their _lb_roles meta; sample() gives a matching object to render them with.
 *  5. Otherwise ''. The result passes through the filter launch_builder_role ( $role, ?WP_Post ).
 */
final class Roles {

	/** Every role, slug => label. */
	const ROLES = [
		'home'         => 'Home page',
		'page'         => 'Standard page',
		'legal'        => 'Legal page',
		'services_hub' => 'Services hub',
		'areas_hub'    => 'Service areas hub',
		'service'      => 'Service',
		'area'         => 'Service area',
		'conversion'   => 'Contact page',
		'thanks'       => 'Thank-you page',
		'post'         => 'Blog post',
		'blog'         => 'Blog and archives',
		'search'       => 'Search results',
		'notfound'     => 'Not found (404)',
		'other'        => 'Other',
	];

	/** Roles a page can be given with the _lb_role meta. Hubs live in the hubs option. */
	const PAGE_ROLES = [ 'page', 'legal', 'conversion', 'thanks' ];

	/** Roles set-page-role accepts ('auto' = forget the explicit role and the hub entry). */
	const ASSIGNABLE = [ 'auto', 'page', 'legal', 'conversion', 'thanks', 'services_hub', 'areas_hub' ];

	/** Post types that hold designs rather than content (their role comes from _lb_roles). */
	const TEMPLATE_TYPES = [ 'kadence_element', 'wp_block', 'kadence_header', 'kadence_navigation', 'kadence_form' ];

	const META     = '_lb_role';
	const OPT_HUBS = 'launch_builder_hubs';

	/** Hub slot => the usual page path (derive_hubs() fills empty slots from these). */
	const HUB_PATHS = [ 'services' => 'services', 'areas' => 'service-areas' ];

	/** Why a page has its role, for people (the meta box and the editor panel). */
	const WHY = [ 'front_page' => 'it is the front page', 'posts_page' => 'it is the posts page', 'meta' => 'set here', 'woo' => 'it is a WooCommerce page', 'hub' => 'set as a hub (set-page-role)', 'default' => 'automatic' ];

	/** @var string|null role of the main query, once known. */
	private static $memo = null;

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'add_meta_boxes_page', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_page', [ __CLASS__, 'save_box' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'meta_changed' ], 10, 3 );
		}
		add_filter( 'manage_page_posts_columns', [ __CLASS__, 'column' ] );
		add_action( 'manage_page_posts_custom_column', [ __CLASS__, 'column_value' ], 10, 2 );
		Migrate::register_step( 'roles-derive', [ __CLASS__, 'migrate_derive' ] );
	}

	/* ------------------------------------------------------------------ resolution */

	/** The role of the current request ('' when there is nothing to resolve). */
	public static function current(): string {
		$sim = Context::current();
		if ( $sim ) {
			return $sim['role'];
		}
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$main = did_action( 'wp' ) && ! is_admin();
		if ( $main ) {
			[ $role, $post ] = self::from_query();
		} else {
			$post = self::request_post();
			$role = $post ? self::resolve( $post ) : '';
		}
		$role = (string) apply_filters( 'launch_builder_role', $role, $post );
		if ( $main ) {
			self::$memo = $role;
		}
		return $role;
	}

	/** The role of a post by id (same rules, no request context). '' for unknown posts. */
	public static function of( $id ): string {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return '';
		}
		return (string) apply_filters( 'launch_builder_role', self::resolve( $post ), $post );
	}

	/** Human label for a role. */
	public static function label( string $role ): string {
		return self::ROLES[ $role ] ?? ( '' === $role ? 'None' : $role );
	}

	/**
	 * Post type => role for singular content. Filter launch_builder_role_post_types adds more (unknown types are 'other').
	 *
	 * @return array<string,string>
	 */
	public static function post_types(): array {
		$map = (array) apply_filters( 'launch_builder_role_post_types', [ 'page' => 'page', 'post' => 'post', Entries::SERVICE => 'service', Entries::AREA => 'area' ] );
		return array_filter( $map, static function ( $r ) { return is_string( $r ) && isset( self::ROLES[ $r ] ); } );
	}

	/** @return array{0:string,1:?\WP_Post} role (unfiltered) and the object it came from, for the main query. */
	private static function from_query(): array {
		$obj = get_queried_object();
		$post = $obj instanceof \WP_Post ? $obj : null;
		if ( is_front_page() ) {
			return [ 'home', $post ];
		}
		if ( is_404() ) {
			return [ 'notfound', null ];
		}
		if ( is_search() ) {
			return [ 'search', null ];
		}
		if ( is_home() || is_category() || is_tag() || is_date() || is_author() ) {
			return [ 'blog', $post ];
		}
		if ( is_singular() && $post ) {
			return [ self::resolve( $post ), $post ];
		}
		return [ 'other', $post ]; // Other archives (other post types, other taxonomies, WooCommerce shop...).
	}

	/** The post to resolve when there is no main query: request post_id (REST/admin/AJAX), else the global $post. */
	private static function request_post(): ?\WP_Post {
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( isset( $_REQUEST['post_id'] ) && ( $rest || is_admin() || wp_doing_ajax() ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only lookup.
			$id = absint( wp_unslash( $_REQUEST['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$p = $id ? get_post( $id ) : null;
			if ( $p && current_user_can( 'read_post', $id ) ) {
				return $p;
			}
		}
		$p = get_post();
		return $p instanceof \WP_Post ? $p : null;
	}

	/** Role of a post, unfiltered (the rules in the class comment). */
	private static function resolve( \WP_Post $post ): string {
		if ( in_array( $post->post_type, self::TEMPLATE_TYPES, true ) ) {
			return self::template_roles( $post->ID )[0] ?? '';
		}
		if ( 'page' === $post->post_type ) {
			return self::explain_page( $post->ID )[0];
		}
		return self::post_types()[ $post->post_type ] ?? 'other';
	}

	/**
	 * A page's role and where it came from: front_page | posts_page | meta | woo | hub | default.
	 * WordPress honours the front and posts pages only when "Your homepage displays" is a static page (show_on_front).
	 *
	 * @param int  $id       page id.
	 * @param bool $use_meta false = the role the page would have without its _lb_role (what "Automatic" means for it).
	 * @return array{0:string,1:string}
	 */
	public static function explain_page( int $id, bool $use_meta = true ): array {
		$static = 'page' === get_option( 'show_on_front' );
		if ( $static && $id === (int) get_option( 'page_on_front' ) ) {
			return [ 'home', 'front_page' ];
		}
		if ( $static && $id && $id === (int) get_option( 'page_for_posts' ) ) {
			return [ 'blog', 'posts_page' ];
		}
		$meta = $use_meta ? (string) get_post_meta( $id, self::META, true ) : '';
		if ( in_array( $meta, self::PAGE_ROLES, true ) ) {
			return [ $meta, 'meta' ];
		}
		if ( self::is_woo_page( $id ) ) {
			return [ 'other', 'woo' ];
		}
		$hubs = self::hubs();
		if ( $hubs['services'] && $id === $hubs['services'] ) {
			return [ 'services_hub', 'hub' ];
		}
		if ( $hubs['areas'] && $id === $hubs['areas'] ) {
			return [ 'areas_hub', 'hub' ];
		}
		return [ 'page', 'default' ];
	}

	/** Roles a template post (element, synced pattern...) is designed for, from its _lb_roles meta (array, JSON or CSV). */
	public static function template_roles( int $id ): array {
		$raw = get_post_meta( $id, '_lb_roles', true );
		if ( is_string( $raw ) ) {
			$dec = json_decode( $raw, true );
			$raw = is_array( $dec ) ? $dec : explode( ',', $raw );
		}
		$out = [];
		foreach ( (array) $raw as $r ) {
			$r = sanitize_key( (string) $r );
			if ( isset( self::ROLES[ $r ] ) ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function is_woo_page( int $id ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $wp ) {
			if ( $id === (int) wc_get_page_id( $wp ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A published object to render a role's designs with (template posts, previews): the first entry of that type, the hub
	 * page, a page with that role... null when the role has no object (search, notfound, other) or none exists yet.
	 */
	public static function sample( string $role ): ?\WP_Post {
		$first = static function ( array $args ): ?\WP_Post {
			$p = get_posts( $args + [ 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] );
			return $p ? $p[0] : null;
		};
		$page = static function ( int $id ): ?\WP_Post {
			$p = $id ? get_post( $id ) : null;
			return $p && 'publish' === $p->post_status ? $p : null;
		};
		switch ( $role ) {
			case 'service':
				return $first( [ 'post_type' => Entries::SERVICE ] );
			case 'area':
				return $first( [ 'post_type' => Entries::AREA ] );
			case 'post':
				return $first( [ 'post_type' => 'post', 'orderby' => 'date', 'order' => 'DESC' ] );
			case 'home':
				return 'page' === get_option( 'show_on_front' ) ? $page( (int) get_option( 'page_on_front' ) ) : null;
			case 'blog':
				return 'page' === get_option( 'show_on_front' ) ? $page( (int) get_option( 'page_for_posts' ) ) : null;
			case 'services_hub':
				return $page( self::hubs()['services'] );
			case 'areas_hub':
				return $page( self::hubs()['areas'] );
			case 'legal':
			case 'conversion':
			case 'thanks':
				return $first( [ 'post_type' => 'page', 'meta_key' => self::META, 'meta_value' => $role ] );
			case 'page':
				foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 30, 'orderby' => 'menu_order title', 'order' => 'ASC' ] ) as $p ) {
					if ( 'page' === self::explain_page( $p->ID )[0] ) {
						return $p;
					}
				}
				return null;
		}
		return null;
	}

	/* ------------------------------------------------------------------ hubs */

	/** @return array{services:int,areas:int} hub page ids (0 = none). */
	public static function hubs(): array {
		$h = get_option( self::OPT_HUBS, [] );
		$h = is_array( $h ) ? $h : [];
		return [ 'services' => (int) ( $h['services'] ?? 0 ), 'areas' => (int) ( $h['areas'] ?? 0 ) ];
	}

	/* ------------------------------------------------------------------ derived roles (migration, builder) */

	/** A page whose role is only the default (no _lb_role, not a hub, front, posts or WooCommerce page). */
	private static function plain_page( int $id ): bool {
		return 'default' === self::explain_page( $id )[1];
	}

	/**
	 * Fill EMPTY hub slots from the published pages at /services/ and /service-areas/. Never replaces a hub, never takes
	 * a page that has a role of its own. Snapshots the hubs option before writing.
	 *
	 * @return string[] what changed.
	 */
	public static function derive_hubs( string $label ): array {
		$hubs = self::hubs();
		$new = $hubs;
		$out = [];
		foreach ( self::HUB_PATHS as $slot => $path ) {
			$p = $new[ $slot ] ? null : get_page_by_path( $path, OBJECT, 'page' );
			if ( $p && 'publish' === $p->post_status && ! in_array( (int) $p->ID, $new, true ) && self::plain_page( (int) $p->ID ) ) {
				$new[ $slot ] = (int) $p->ID;
				$out[] = $slot . ' hub = page ' . $p->ID . ' (/' . $path . '/)';
			}
		}
		if ( $new !== $hubs ) {
			Abilities::snapshot( $label, [], [ self::OPT_HUBS ] );
			update_option( self::OPT_HUBS, $new, true );
		}
		return $out;
	}

	/**
	 * Migration step roles-derive (runs once per site): empty hub slots from /services/ and /service-areas/, and the
	 * WordPress privacy policy page becomes legal when it has no role of its own.
	 */
	public static function migrate_derive( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$out = self::derive_hubs( 'migrate roles-derive' );
		$priv = (int) get_option( 'wp_page_for_privacy_policy' );
		$pp = $priv ? get_post( $priv ) : null;
		if ( $pp && 'page' === $pp->post_type && 'trash' !== $pp->post_status && self::plain_page( $priv ) ) {
			update_post_meta( $priv, self::META, 'legal' );
			$out[] = 'page ' . $priv . ' (privacy policy) = legal';
		}
		return [ 'done' => true, 'report' => $out ? implode( '; ', $out ) : 'nothing to derive' ];
	}

	/* ------------------------------------------------------------------ page meta, meta box, list column */

	public static function register_meta(): void {
		register_post_meta(
			'page',
			self::META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				// '' = automatic. The editor sends the whole meta object back on save, so '' must validate.
				'show_in_rest'      => [ 'schema' => [ 'type' => 'string', 'enum' => array_merge( [ '' ], self::PAGE_ROLES ) ] ],
				'sanitize_callback' => static function ( $v ) { return in_array( $v, self::PAGE_ROLES, true ) ? $v : ''; },
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) { return current_user_can( 'edit_post', (int) $post_id ); },
			]
		);
	}

	/**
	 * The classic-editor "Page role" box. It is a back-compat box: the block editor never renders it (it shows the
	 * editor_panel() instead), so page edits there stay out of the legacy meta-box save (an extra POST on every save).
	 */
	public static function meta_box(): void {
		add_meta_box( 'lb_page_role', 'Page role', [ __CLASS__, 'box' ], 'page', 'side', 'default', [ '__back_compat_meta_box' => true ] );
	}

	public static function box( \WP_Post $post ): void {
		wp_nonce_field( 'lb_page_role', 'lb_page_role_nonce' );
		[ $role, $source ] = self::explain_page( $post->ID );
		$meta = (string) get_post_meta( $post->ID, self::META, true );
		$locked = in_array( $source, [ 'front_page', 'posts_page' ], true );
		echo '<p><label for="lb-role"><strong>Role</strong></label><br><select id="lb-role" name="lb_role" style="width:100%"' . ( $locked ? ' disabled' : '' ) . '>';
		echo '<option value="">Automatic</option>';
		foreach ( self::PAGE_ROLES as $r ) {
			echo '<option value="' . esc_attr( $r ) . '"' . selected( $meta, $r, false ) . '>' . esc_html( self::ROLES[ $r ] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">Currently: <strong>' . esc_html( self::label( $role ) ) . '</strong> (' . esc_html( self::WHY[ $source ] ?? $source ) . '). The role decides which designs the page gets (Launch Builder &gt; Designs) and its layout.</p>';
	}

	/** Classic-editor save. Writes only when the role changed (the purge comes from meta_changed()). */
	public static function save_box( int $post_id, \WP_Post $post ): void {
		if ( empty( $_POST['lb_page_role_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lb_page_role_nonce'] ) ), 'lb_page_role' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['lb_role'] ) ) {
			return; // Disabled select (front or posts page): nothing posted, nothing to change.
		}
		$role = sanitize_key( wp_unslash( $_POST['lb_role'] ) );
		$new = in_array( $role, self::PAGE_ROLES, true ) ? $role : '';
		if ( (string) get_post_meta( $post_id, self::META, true ) === $new ) {
			return;
		}
		'' === $new ? delete_post_meta( $post_id, self::META ) : update_post_meta( $post_id, self::META, $new );
	}

	/**
	 * Block editor: a "Page role" panel in the document sidebar of pages, bound to the registered _lb_role meta (saved with
	 * the post over REST, so no legacy meta box). The page's current and automatic roles are passed in for the help text.
	 */
	public static function editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post = get_post();
		if ( ! $screen || ! $post || $screen->post_type !== $post->post_type || 'page' !== $post->post_type ) {
			return;
		}
		[ $role, $source ] = self::explain_page( $post->ID );
		[ $auto, $auto_source ] = self::explain_page( $post->ID, false );
		$labels = [];
		foreach ( self::PAGE_ROLES as $r ) {
			$labels[ $r ] = self::ROLES[ $r ];
		}
		$data = [
			'type'    => 'page',
			'meta'    => self::META,
			'locked'  => in_array( $source, [ 'front_page', 'posts_page' ], true ),
			'current' => [ 'label' => self::label( $role ), 'why' => self::WHY[ $source ] ?? $source ],
			'auto'    => [ 'label' => self::label( $auto ), 'why' => self::WHY[ $auto_source ] ?? $auto_source ],
			'roles'   => $labels,
		];
		wp_enqueue_script( 'lb-page-role', LB_URL . 'assets/js/page-role.js', [ 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor', 'wp-edit-post' ], (string) @filemtime( LB_DIR . 'assets/js/page-role.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-page-role', 'window.lbPageRole = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/** Any write that changes a page's role (meta box, editor panel over REST, tools) purges the page caches once. */
	public static function meta_changed( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids, $object_id );
		if ( self::META === $meta_key ) {
			Cache::purge_later();
		}
	}

	public static function column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				$out['lb_role'] = 'Role';
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out['lb_role'] ) ) {
			$out['lb_role'] = 'Role';
		}
		return $out;
	}

	public static function column_value( string $col, int $post_id ): void {
		if ( 'lb_role' === $col ) {
			echo esc_html( self::label( self::of( $post_id ) ) );
		}
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'set-page-role', 'Set a page\'s role', 'Say what kind of page a page is, so it gets the right designs and layout. role: page (standard), legal (privacy, terms), conversion (contact, quote, booking: full width, no sidebar), thanks (thank-you pages: not indexed), services_hub / areas_hub (the page listing all services / all service areas; stored in the hubs setting), auto (forget an explicit role or hub, back to automatic). The front page is always home and the posts page always blog. Give post_id or path.',
			[ 'properties' => [
				'post_id' => [ 'type' => 'integer', 'description' => 'The page id (or give path).' ],
				'path'    => [ 'type' => 'string', 'description' => 'The page path instead of post_id, e.g. "contact" or "services/drains".' ],
				'role'    => [ 'type' => 'string', 'enum' => self::ASSIGNABLE ],
			], 'required' => [ 'role' ] ], [ __CLASS__, 'set_role_tool' ], 'edit_pages' );

		Abilities::add( 'get-roles', 'List page roles', 'The role of every page (paginated), with where it comes from (front_page, posts_page, meta, woo, hub, default), and the hub pages.',
			[ 'properties' => [
				'offset' => [ 'type' => 'integer', 'description' => 'Skip this many pages (default 0).' ],
				'limit'  => [ 'type' => 'integer', 'description' => 'Pages per call, 1-200 (default 100).' ],
			] ], [ __CLASS__, 'get_roles_tool' ], 'edit_pages', [ 'readonly' => true ] );
	}

	public static function set_role_tool( $input ) {
		$input = (array) $input;
		$role = (string) ( $input['role'] ?? '' );
		if ( ! in_array( $role, self::ASSIGNABLE, true ) ) {
			return new \WP_Error( 'lb_role', 'role must be one of: ' . implode( ', ', self::ASSIGNABLE ) . '.', [ 'status' => 400 ] );
		}
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( ! $id && isset( $input['path'] ) && is_string( $input['path'] ) && '' !== trim( $input['path'], '/ ' ) ) {
			$path = implode( '/', array_map( 'sanitize_title', explode( '/', trim( $input['path'], '/ ' ) ) ) );
			$p = get_page_by_path( $path, OBJECT, 'page' );
			$id = $p ? (int) $p->ID : 0;
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'lb_no_page', 'No such page. Give post_id or path of a page (services and areas have fixed roles).', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that page.', [ 'status' => 403 ] );
		}
		$fixed = self::explain_page( $id )[1];
		if ( in_array( $fixed, [ 'front_page', 'posts_page' ], true ) ) {
			return new \WP_Error( 'lb_role_fixed', 'The front page is always home and the posts page always blog.', [ 'status' => 409 ] );
		}
		$hubs = self::hubs();
		$previous = [ 'role' => self::of( $id ), 'meta' => (string) get_post_meta( $id, self::META, true ), 'hubs' => $hubs ];
		$warnings = [];
		$snap = null;
		$changed = false; // Only a real change purges the page caches.
		$write_hubs = static function ( array $new ) use ( $hubs, &$snap, &$changed ) {
			if ( $new === $hubs ) {
				return true;
			}
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'lb_forbidden', 'Changing the hub pages needs the edit_theme_options capability.', [ 'status' => 403 ] );
			}
			$snap = Abilities::snapshot( 'set-page-role', [], [ self::OPT_HUBS ] );
			update_option( self::OPT_HUBS, $new, true );
			$changed = true;
			return true;
		};
		$unhub = $hubs;
		foreach ( $unhub as $k => $hid ) {
			if ( $hid === $id ) {
				$unhub[ $k ] = 0; // A page holds one role: leaving a hub when it becomes something else.
			}
		}
		if ( in_array( $role, [ 'services_hub', 'areas_hub' ], true ) ) {
			$unhub[ 'services_hub' === $role ? 'services' : 'areas' ] = $id;
		}
		$ok = $write_hubs( $unhub );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$meta_now = (string) get_post_meta( $id, self::META, true );
		if ( in_array( $role, self::PAGE_ROLES, true ) ) {
			if ( $meta_now !== $role ) {
				update_post_meta( $id, self::META, $role );
				$changed = true;
			}
		} elseif ( '' !== $meta_now ) {
			delete_post_meta( $id, self::META ); // Hub and auto follow the derived rules, which an explicit role would override.
			$changed = true;
		}
		if ( $changed ) {
			Cache::purge_later();
		}
		$resolved = self::of( $id );
		if ( 'auto' !== $role && $resolved !== $role ) {
			$warnings[] = 'The page now resolves to ' . $resolved . ' (a filter or another rule decides it).';
		}
		return [ 'post_id' => $id, 'title' => get_the_title( $id ), 'role' => $role, 'resolved' => $resolved, 'changed' => $changed, 'previous' => $previous, 'snapshot' => $snap, 'warnings' => $warnings ];
	}

	public static function get_roles_tool( $input = [] ): array {
		$input = (array) $input;
		$limit = max( 1, min( 200, (int) ( $input['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$q = new \WP_Query( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'ignore_sticky_posts' => true ] );
		$pages = [];
		foreach ( $q->posts as $p ) {
			[ $role, $source ] = self::explain_page( $p->ID );
			$filtered = self::of( $p->ID );
			$pages[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'path' => get_page_uri( $p ), 'status' => $p->post_status, 'role' => $filtered, 'source' => $filtered === $role ? $source : 'filter' ];
		}
		$hub = static function ( int $id ) { return $id ? [ 'id' => $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ) ] : null; };
		$h = self::hubs();
		$total = (int) $q->found_posts;
		return [
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
			'pages'       => $pages,
			'hubs'        => [ 'services' => $hub( $h['services'] ), 'areas' => $hub( $h['areas'] ) ],
			'roles'       => self::ROLES,
		];
	}

	/* ------------------------------------------------------------------ module hooks */

	/** Module hook: role summary in site-state. */
	public static function site_state( array &$state ): void {
		$counts = [];
		foreach ( (array) ( $state['pages'] ?? [] ) as $p ) {
			$r = self::of( (int) ( $p['id'] ?? 0 ) );
			$counts[ $r ] = ( $counts[ $r ] ?? 0 ) + 1;
		}
		ksort( $counts );
		$state['roles'] = [ 'hubs' => self::hubs(), 'pages_by_role' => $counts ];
	}

	/** Module hook: how roles work, in get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['page_roles'] = 'Every page has a ROLE that decides which designs and layout it gets: home (front page), page, legal, services_hub, areas_hub, service and area (entries), conversion (contact, quote, booking: full width), thanks (not indexed), post, blog, search, notfound, other. Most are automatic; set the rest with set-page-role (contact and thank-you pages, the two hubs) and check them with get-roles.';
		$pb['tools_by_job']['Page roles'] = 'get-roles, set-page-role';
	}
}
