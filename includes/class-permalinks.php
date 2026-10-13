<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Permalinks: custom URLs, URL patterns per type, a URL lookup table and redirects.
 *
 * Every public post and term has ONE URI (a site path without slashes, e.g. "plumbing/drain-cleaning"):
 *  - custom: set by hand (set-uri, the editor's URL panel, the term screen, find-replace, import); stored as post/term
 *    meta _lb_uri with _lb_uri_mode = custom; never regenerated.
 *  - auto: built from the permastructure of its post type / taxonomy (set-permastructure), stored as _lb_uri and rebuilt
 *    whenever an input changes (slug, parent, category, primary category, the structure itself).
 *  - native: no structure and no custom URI: WordPress's own permalink (nothing is stored in meta).
 *
 * Lookup table {prefix}lb_uris (uri_hash, uri, object_type, object_id, subtype, source, lang) holds the current URI of
 * every PUBLISHED public post and every term of a public taxonomy, so a request resolves with ONE indexed query
 * (memoised per request and cached in the object cache under a last_changed key) and duplicates/conflicts are one GROUP
 * BY away. Nothing large is autoloaded: the settings option (permastructures + switches) is a few hundred bytes.
 * The row is also the "before" state: when an object's URI changes, the old one gets a 301 (Redirects::uri_changed),
 * including descendants whose URI depends on it (child pages/areas, services in a renamed category).
 *
 * Request: the 'request' filter (priority 1) maps the requested path (decoded, case-insensitive, trailing slash and
 * duplicate slashes ignored) plus an optional suffix (page/N, /N, comment-page-N, feed, embed, trackback,
 * attachment/slug, registered endpoints) to the object's query vars; paths that only match native rows, or nothing,
 * are left to WordPress. Matched requests skip redirect_canonical; this class redirects them to the canonical form
 * (case, slashes) itself, and redirects native URLs of objects that have their own URI (e.g. /services/x/ -> /plumbing/x/).
 * Links: post_link / page_link / post_type_link / term_link (priority 20) return the URI, so get_permalink() and
 * get_term_link() (Rank Math sitemaps, canonicals, breadcrumbs, menus, Kadence) all print it; url_to_postid() knows it.
 */
final class Permalinks {

	/** Modules screen (Modules contract): one switch for custom URLs, URL patterns and the URL tools. */
	const MODULE = [
		'slug'        => 'permalinks',
		'label'       => 'URLs',
		'description' => 'Custom URLs for pages, entries and terms, URL patterns per type (e.g. services under their category), automatic 301s when a URL changes, conflict checks and bulk URL tools.',
		'default'     => true,
		'note'        => 'Off: every URL goes back to the WordPress default and custom URLs stop answering until it is on again.',
	];

	const TABLE      = 'lb_uris';
	const OPT        = 'launch_builder_permalinks';
	const OPT_DB     = 'launch_builder_permalinks_db';
	const DB_VERSION = '1';
	const META       = '_lb_uri';
	const META_MODE  = '_lb_uri_mode';
	const QUEUE      = 'launch_builder_permalinks_queue';
	const CRON       = 'launch_builder_permalinks_cascade';
	const CACHE_GRP  = 'launch_builder_uris';
	const CASCADE_MAX = 300;

	/** First path segments (and exact paths) no URI may take: they belong to WordPress, the REST API, feeds, sitemaps, /mcp. */
	const RESERVED = [ 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-cron.php', 'wp-trackback.php', 'wp-comments-post.php', 'wp-links-opml.php', 'wp-mail.php', 'xmlrpc.php', 'index.php', 'feed', 'comments', 'trackback', 'embed', 'search', 'author', 'page', 'mcp', 'robots.txt', 'favicon.ico', 'sitemap.xml', 'sitemap_index.xml', 'wp-sitemap.xml', 'sitemap.xsl', 'main-sitemap.xsl', 'ads.txt', '.well-known' ];

	const DEFAULTS = [
		'trailing_slash'  => 'keep', // keep (follow the WordPress permalink structure) | always | never
		'lowercase'       => true,
		'transliterate'   => true,
		'stop_words'      => false,
		'stop_word_list'  => '',
		'slug_sync'       => true,
		'auto_redirect'   => true,
		'redirect_store'  => 'auto', // auto (Rank Math when its Redirections module is on) | own | rankmath
		'permastructures' => [ 'post_types' => [], 'taxonomies' => [] ],
	];

	/** Options that move WordPress's own URLs (see native_urls_changed()). */
	const NATIVE_URL_OPTIONS = [ 'permalink_structure', 'category_base', 'tag_base', 'woocommerce_permalinks', 'rank-math-options-general' ];

	const STOP_WORDS ='a,an,and,are,as,at,be,but,by,for,from,how,in,into,is,it,of,on,or,our,that,the,this,to,was,what,when,where,which,who,why,with,your';

	/** @var int >0 while computing native links (our link filters step aside). */
	private static $bypass = 0;
	/** @var int >0 while we write our own meta (meta hooks step aside). */
	private static $writing = 0;
	/** @var array|null the request we resolved: { row, ep, base, path }. */
	private static $matched = null;
	/** @var array<string,array> lookup memo. */
	private static $memo = [];
	/** @var array<string,array> settings per blog. */
	private static $settings = [];
	/** @var array<string,bool> objects created in this request (no redirect from the URI they had for a moment). */
	private static $fresh = [];
	/** @var array<string,bool> refresh() recursion guard. */
	private static $visiting = [];
	/** @var int objects refreshed by cascades in this request. */
	private static $cascaded = 0;
	/** @var array<int,array> term slug/parent before an edit. */
	private static $term_before = [];
	/** @var array|null in-memory override of the permastructures (dry runs). */
	private static $override = null;
	/** @var array<string,bool> objects being deleted (their meta deletions are not edits). */
	private static $deleting = [];
	/** @var array<int,bool> term forms already saved in this request. */
	private static $term_saved = [];

	/* ------------------------------------------------------------------ boot */

	public static function boot(): void {
		add_filter( 'request', [ __CLASS__, 'resolve_request' ], 1 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'redirect_canonical' ], 20 );
		add_action( 'template_redirect', [ __CLASS__, 'canonical' ], 1 );
		add_filter( 'post_link', [ __CLASS__, 'post_link' ], 20, 3 );
		add_filter( 'page_link', [ __CLASS__, 'page_link' ], 20, 3 );
		add_filter( 'post_type_link', [ __CLASS__, 'post_type_link' ], 20, 4 );
		add_filter( 'term_link', [ __CLASS__, 'term_link' ], 20, 3 );
		add_filter( 'url_to_postid', [ __CLASS__, 'url_to_postid' ], 20 );

		add_action( 'wp_after_insert_post', [ __CLASS__, 'post_saved' ], 20, 4 );
		add_action( 'set_object_terms', [ __CLASS__, 'terms_set' ], 20, 6 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $h ) {
			add_action( $h, [ __CLASS__, 'post_meta_changed' ], 20, 4 );
		}
		foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $h ) {
			add_action( $h, [ __CLASS__, 'term_meta_changed' ], 20, 4 );
		}
		add_action( 'before_delete_post', [ __CLASS__, 'post_deleting' ], 1, 1 );
		add_action( 'pre_delete_term', [ __CLASS__, 'term_deleting' ], 1, 1 );
		add_action( 'deleted_post', [ __CLASS__, 'post_deleted' ], 20, 1 );
		add_action( 'created_term', [ __CLASS__, 'term_created' ], 20, 3 );
		add_action( 'edit_terms', [ __CLASS__, 'term_before_edit' ], 20, 2 );
		add_action( 'edited_term', [ __CLASS__, 'term_edited' ], 20, 3 );
		add_action( 'delete_term', [ __CLASS__, 'term_deleted' ], 20, 5 );
		add_action( self::CRON, [ __CLASS__, 'run_queue' ] );
		add_action( 'switch_blog', [ __CLASS__, 'forget' ] );
		add_action( 'update_option_' . self::OPT, [ __CLASS__, 'forget' ] );
		add_action( 'add_option_' . self::OPT, [ __CLASS__, 'forget' ] );
		foreach ( self::NATIVE_URL_OPTIONS as $opt ) { // WordPress's own URLs moved: the native rows are re-indexed.
			add_action( 'update_option_' . $opt, [ __CLASS__, 'native_urls_changed' ], 10, 3 );
			add_action( 'add_option_' . $opt, [ __CLASS__, 'native_urls_changed' ], 10, 0 );
			add_action( 'delete_option_' . $opt, [ __CLASS__, 'native_urls_changed' ], 10, 0 );
		}

		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_init', [ __CLASS__, 'term_fields' ] );
		add_action( 'admin_post_lb_permalinks', [ __CLASS__, 'admin_post' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );

		Migrate::register_step( 'permalinks-install', [ __CLASS__, 'migrate_install' ], [ 'every_version' => true ] );
		Migrate::register_step( 'permalinks-index', [ __CLASS__, 'migrate_index' ], [ 'after' => [ 'permalinks-install' ] ] );

		Audit::register_check( 'uri_duplicate', 'permalinks', [ __CLASS__, 'audit_duplicates' ] );
		Audit::register_check( 'uri_reserved', 'permalinks', [ __CLASS__, 'audit_reserved' ], true );
		Audit::register_check( 'uri_index_pending', 'permalinks', [ __CLASS__, 'audit_index' ] );
		Audit::register_check( 'redirect_health', 'permalinks', [ Redirects::class, 'audit' ] );
	}

	/** Module hook: create the tables now and index everything again on the next migration run. */
	public static function activate(): void {
		self::install();
		Migrate::rerun( 'permalinks-index' );
	}

	public static function forget(): void {
		self::$settings = [];
		self::$memo = [];
	}

	/**
	 * A setting that changes WordPress's own URLs was saved (permalink structure, category/tag base, WooCommerce bases,
	 * Rank Math's "strip category base"): the index rows of objects on WordPress URLs are stale, so the index migration
	 * runs again (next wp-admin load by an administrator, or wp launch-builder migrate). Until then those paths simply
	 * resolve through WordPress, as they would without this module.
	 */
	public static function native_urls_changed( $old = null, $new = null, $option = '' ): void {
		if ( 'rank-math-options-general' === $option ) {
			$was = is_array( $old ) ? (string) ( $old['strip_category_base'] ?? '' ) : '';
			$now = is_array( $new ) ? (string) ( $new['strip_category_base'] ?? '' ) : '';
			if ( $was === $now ) {
				return; // Some other Rank Math setting.
			}
		}
		if ( self::installed() ) {
			self::forget();
			Migrate::rerun( 'permalinks-index' );
		}
	}

	/* ------------------------------------------------------------------ tables */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function installed(): bool {
		return self::DB_VERSION === (string) get_option( self::OPT_DB );
	}

	/** Are we resolving and filtering links (tables installed and pretty permalinks on)? */
	public static function active(): bool {
		return self::installed() && '' !== (string) get_option( 'permalink_structure' );
	}

	/** Create or update both tables (dbDelta). */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uri_hash char(32) NOT NULL DEFAULT '',
  uri varchar(1000) NOT NULL DEFAULT '',
  object_type varchar(10) NOT NULL DEFAULT 'post',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subtype varchar(32) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'native',
  lang varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY object (object_type,object_id),
  KEY uri_hash (uri_hash),
  KEY subtype (subtype)
) {$charset};" );
		dbDelta( Redirects::schema( $charset ) );
		$ok = $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) && Redirects::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Redirects::table() ) ) ); // phpcs:ignore WordPress.DB
		if ( $ok ) {
			update_option( self::OPT_DB, self::DB_VERSION, true );
		}
		self::changed();
		return $ok;
	}

	/** Migration step: tables (every version, dbDelta is idempotent). */
	public static function migrate_install( $cursor, int $batch ) {
		unset( $cursor, $batch );
		return self::install() ? [ 'done' => true, 'report' => 'URI and redirect tables ready' ] : new \WP_Error( 'lb_permalinks_install', 'Could not create the lb_uris / lb_redirects tables.' );
	}

	/**
	 * Migration step: index every published public post and every public term into the lookup table (and give objects
	 * of types with a permastructure their automatic URI), then drop rows of objects that are gone. No redirects here.
	 * Cursor { phase: posts|terms|prune, after }.
	 */
	public static function migrate_index( $cursor, int $batch ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_index', 'Tables not installed.' );
		}
		$c = is_array( $cursor ) ? $cursor : [];
		$c += [ 'phase' => 'posts', 'after' => 0, 'n' => 0 ];
		$after = (int) ( $c['after'] ?? 0 );
		$n = (int) ( $c['n'] ?? 0 );
		$batch = max( 1, $batch );
		if ( 'posts' === $c['phase'] ) {
			$types = self::post_types();
			$ids = [];
			if ( $types ) {
				$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( $types, [ $after, $batch ] ) ) ) ); // phpcs:ignore WordPress.DB
			}
			foreach ( $ids as $id ) {
				self::refresh( 'post', $id, [ 'cascade' => false, 'redirect' => false ] );
			}
			$n += count( $ids );
			$next = count( $ids ) < $batch ? [ 'phase' => 'terms', 'after' => 0, 'n' => $n ] : [ 'phase' => 'posts', 'after' => (int) end( $ids ), 'n' => $n ];
			return [ 'done' => false, 'cursor' => $next, 'report' => '' ];
		}
		if ( 'terms' === $c['phase'] ) {
			$taxes = self::taxonomies();
			$ids = [];
			if ( $taxes ) {
				$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$in}) AND term_id > %d ORDER BY term_id ASC LIMIT %d", array_merge( $taxes, [ $after, $batch ] ) ) ) ); // phpcs:ignore WordPress.DB
			}
			foreach ( $ids as $id ) {
				self::refresh( 'term', $id, [ 'cascade' => false, 'redirect' => false ] );
			}
			$n += count( $ids );
			$next = count( $ids ) < $batch ? [ 'phase' => 'prune', 'after' => 0, 'n' => $n ] : [ 'phase' => 'terms', 'after' => (int) end( $ids ), 'n' => $n ];
			return [ 'done' => false, 'cursor' => $next, 'report' => '' ];
		}
		$t = self::table();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$pruned = (int) ( $c['pruned'] ?? 0 );
		foreach ( $rows as $r ) {
			if ( ! self::live( (string) $r['object_type'], (int) $r['object_id'] ) ) {
				self::delete_row( (string) $r['object_type'], (int) $r['object_id'] );
				$pruned++;
			}
		}
		if ( count( $rows ) < $batch ) {
			return [ 'done' => true, 'report' => sprintf( 'indexed %d objects, removed %d stale rows', $n, $pruned ) ];
		}
		$last = end( $rows );
		return [ 'done' => false, 'cursor' => [ 'phase' => 'prune', 'after' => (int) $last['id'], 'n' => $n, 'pruned' => $pruned ], 'report' => '' ];
	}

	/* ------------------------------------------------------------------ settings */

	public static function settings(): array {
		$b = (string) get_current_blog_id();
		if ( ! isset( self::$settings[ $b ] ) ) {
			$s = get_option( self::OPT, [] );
			$s = is_array( $s ) ? $s : [];
			$out = array_merge( self::DEFAULTS, array_intersect_key( $s, self::DEFAULTS ) );
			$ps = isset( $s['permastructures'] ) && is_array( $s['permastructures'] ) ? $s['permastructures'] : [];
			$out['permastructures'] = [ 'post_types' => (array) ( $ps['post_types'] ?? [] ), 'taxonomies' => (array) ( $ps['taxonomies'] ?? [] ) ];
			foreach ( [ 'lowercase', 'transliterate', 'stop_words', 'slug_sync', 'auto_redirect' ] as $k ) {
				$out[ $k ] = (bool) $out[ $k ];
			}
			self::$settings[ $b ] = $out;
		}
		$out = self::$settings[ $b ];
		if ( null !== self::$override ) {
			$out['permastructures'] = self::$override;
		}
		return $out;
	}

	private static function save_settings( array $s ): void {
		$keep = array_intersect_key( $s, self::DEFAULTS );
		update_option( self::OPT, $keep, true ); // Small (switches + a few structures): autoloaded on purpose, read on every request.
		self::forget();
	}

	/** The structure of a post type ('post') or taxonomy ('term'), '' = WordPress default. */
	public static function structure( string $kind, string $name ): string {
		$ps = self::settings()['permastructures'];
		return (string) ( 'term' === $kind ? ( $ps['taxonomies'][ $name ] ?? '' ) : ( $ps['post_types'][ $name ] ?? '' ) );
	}

	/** Does the structure of $name use the tag %$tag%? */
	private static function uses_tag( string $kind, string $name, string $tag ): bool {
		return false !== strpos( self::structure( $kind, $name ), '%' . $tag . '%' );
	}

	/* ------------------------------------------------------------------ which objects */

	/** Public post types whose URLs we manage (filter launch_builder_permalink_post_types). */
	public static function post_types(): array {
		$skip = array_merge( [ 'attachment', 'kadence_conversions' ], Roles::TEMPLATE_TYPES );
		$types = array_values( array_diff( array_keys( get_post_types( [ 'public' => true ] ) ), $skip ) );
		return array_values( array_filter( (array) apply_filters( 'launch_builder_permalink_post_types', $types ), 'is_string' ) );
	}

	/** Public taxonomies whose URLs we manage (filter launch_builder_permalink_taxonomies). */
	public static function taxonomies(): array {
		$types = array_values( array_diff( array_keys( get_taxonomies( [ 'public' => true ] ) ), [ 'post_format' ] ) );
		return array_values( array_filter( (array) apply_filters( 'launch_builder_permalink_taxonomies', $types ), 'is_string' ) );
	}

	public static function managed_post_type( string $pt ): bool {
		return in_array( $pt, self::post_types(), true );
	}

	public static function managed_taxonomy( string $tax ): bool {
		return in_array( $tax, self::taxonomies(), true );
	}

	private static function is_front( \WP_Post $p ): bool {
		return 'page' === $p->post_type && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $p->ID;
	}

	/** Does an object have a public URL right now (published post of a managed type, or a term of a managed taxonomy)? */
	public static function live( string $type, int $id ): bool {
		if ( 'term' === $type ) {
			$t = get_term( $id );
			return $t instanceof \WP_Term && self::managed_taxonomy( $t->taxonomy );
		}
		$p = get_post( $id );
		return $p instanceof \WP_Post && 'publish' === $p->post_status && self::managed_post_type( $p->post_type ) && ! self::is_front( $p );
	}

	/** A term whose URL is its category page (the term_link rule for service categories): we leave its link alone. */
	private static function category_page( \WP_Term $t ): bool {
		if ( Entries::CAT !== $t->taxonomy ) {
			return false;
		}
		$page = (int) get_term_meta( $t->term_id, '_lb_page', true );
		return $page && 'publish' === get_post_status( $page );
	}

	private static function meta( string $type, int $id, string $key ): string {
		return (string) ( 'term' === $type ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true ) );
	}

	private static function write_meta( string $type, int $id, string $key, string $value ): void {
		self::$writing++;
		try {
			'term' === $type ? update_term_meta( $id, $key, wp_slash( $value ) ) : update_post_meta( $id, $key, wp_slash( $value ) );
		} finally {
			self::$writing--;
		}
	}

	private static function delete_meta( string $type, int $id, string $key ): void {
		self::$writing++;
		try {
			'term' === $type ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
		} finally {
			self::$writing--;
		}
	}

	/** post:ID / term:ID */
	private static function ref( string $type, int $id ): string {
		return ( 'term' === $type ? 'term' : 'post' ) . ':' . $id;
	}

	/** { object, type, id, title } of an object for tool results. */
	public static function describe( string $type, int $id ): array {
		if ( 'term' === $type ) {
			$t = get_term( $id );
			return [ 'object' => self::ref( 'term', $id ), 'type' => $t instanceof \WP_Term ? $t->taxonomy : '', 'id' => $id, 'title' => $t instanceof \WP_Term ? $t->name : '' ];
		}
		$p = get_post( $id );
		return [ 'object' => self::ref( 'post', $id ), 'type' => $p ? $p->post_type : '', 'id' => $id, 'title' => $p ? get_the_title( $p ) : '' ];
	}

	/**
	 * An object reference from a tool: 12 / "12" (post), post:12, page:12, term:7, {post_type}:{slug or path},
	 * {taxonomy}:{slug}, or a path / URL on this site.
	 *
	 * @return array{type:string,id:int}|\WP_Error
	 */
	public static function parse_object( $ref ) {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$ref = 'post:' . (int) $ref;
		}
		$ref = trim( (string) $ref );
		$found = null;
		if ( preg_match( '/^(post|page|term):(\d+)$/', $ref, $m ) ) {
			$type = 'term' === $m[1] ? 'term' : 'post';
			$found = [ 'type' => $type, 'id' => (int) $m[2] ];
		} elseif ( preg_match( '/^([a-z0-9_-]+):(.+)$/', $ref, $m ) && ! preg_match( '#^https?$#i', $m[1] ) ) {
			if ( post_type_exists( $m[1] ) ) {
				$p = get_page_by_path( trim( $m[2], '/' ), OBJECT, $m[1] );
				$found = $p ? [ 'type' => 'post', 'id' => (int) $p->ID ] : null;
			} elseif ( taxonomy_exists( $m[1] ) ) {
				$t = get_term_by( 'slug', sanitize_title( basename( trim( $m[2], '/' ) ) ), $m[1] );
				$found = $t ? [ 'type' => 'term', 'id' => (int) $t->term_id ] : null;
			}
		} else {
			$path = self::local_path( $ref );
			if ( null !== $path && '' !== $path ) {
				$row = self::lookup_uri( $path );
				if ( $row ) {
					$found = [ 'type' => (string) $row['object_type'], 'id' => (int) $row['object_id'] ];
				} else {
					self::$bypass++;
					$pid = url_to_postid( home_url( '/' . self::encode_path( $path ) . '/' ) );
					self::$bypass--;
					$found = $pid ? [ 'type' => 'post', 'id' => (int) $pid ] : null;
				}
			}
		}
		if ( ! $found || ( 'term' === $found['type'] ? ! ( get_term( $found['id'] ) instanceof \WP_Term ) : ! get_post( $found['id'] ) ) ) {
			return new \WP_Error( 'lb_object', 'Unknown object: use post:ID, term:ID, {post_type}:{slug}, {taxonomy}:{slug} or a URL of this site.', [ 'status' => 404 ] );
		}
		$ok = 'term' === $found['type'] ? self::managed_taxonomy( (string) get_term( $found['id'] )->taxonomy ) : self::managed_post_type( (string) get_post_type( $found['id'] ) );
		if ( ! $ok ) {
			return new \WP_Error( 'lb_object_type', 'That object\'s type has no public URLs managed here.', [ 'status' => 400 ] );
		}
		return $found;
	}

	private static function can_edit( array $o ): bool {
		return 'term' === $o['type'] ? current_user_can( 'edit_term', $o['id'] ) : current_user_can( 'edit_post', $o['id'] );
	}

	/* ------------------------------------------------------------------ paths */

	/** A site path in canonical storage form: decoded UTF-8, no query/fragment, single slashes, no leading/trailing slash. */
	public static function clean_path( string $path ): string {
		$path = (string) preg_replace( '/[?#].*$/s', '', $path );
		$path = rawurldecode( $path );
		$path = (string) wp_check_invalid_utf8( $path, true );
		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		return trim( $path, "/ \t\n\r\0\x0B" );
	}

	/** Lookup key: case-insensitive. */
	public static function key( string $path ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
	}

	public static function hash( string $path ): string {
		return md5( self::key( self::clean_path( $path ) ) );
	}

	/** Path on this site of a URL or path (null when it is another host or not a page URL). Relative paths are home-relative. */
	public static function local_path( string $url ): ?string {
		$s = trim( $url );
		if ( '' === $s || preg_match( '/^(mailto|tel|javascript|data|sms):/i', $s ) ) {
			return null;
		}
		$home = (string) home_url( '/' );
		$home_path = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$abs = (bool) preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $s );
		if ( $abs ) {
			$host = strtolower( (string) wp_parse_url( $s, PHP_URL_HOST ) );
			$mine = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
			if ( '' === $host || preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $mine ) ) {
				return null;
			}
			$s = (string) wp_parse_url( $s, PHP_URL_PATH );
		}
		$path = self::clean_path( $s );
		if ( '' !== $home_path ) {
			if ( 0 === stripos( $path . '/', $home_path . '/' ) ) {
				$path = trim( (string) substr( $path, strlen( $home_path ) ), '/' );
			} elseif ( $abs ) {
				return null; // Same host, outside WordPress.
			}
		}
		return $path;
	}

	/** The requested path (relative to home) of this request, or null. */
	public static function request_path(): ?string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' === $uri ? null : self::local_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	public static function encode_path( string $uri ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $uri ) ) );
	}

	/** Apply the trailing slash policy to a URL (never on file-like paths such as feed.xml or page.html). */
	private static function slash( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#\.[A-Za-z0-9]{1,5}$#', $path ) ) {
			return untrailingslashit( $url );
		}
		switch ( self::settings()['trailing_slash'] ) {
			case 'always':
				return trailingslashit( $url );
			case 'never':
				return untrailingslashit( $url );
		}
		return user_trailingslashit( $url );
	}

	/** Absolute URL of a site path. */
	public static function build_url( string $uri ): string {
		$uri = trim( $uri, '/' );
		return '' === $uri ? home_url( '/' ) : self::slash( home_url( '/' . self::encode_path( $uri ) ) );
	}

	/** Is this path (or its first segment) reserved for WordPress, feeds, sitemaps, the REST API or a post type archive? */
	public static function reserved( string $path ): bool {
		$k = self::key( self::clean_path( $path ) );
		if ( '' === $k ) {
			return false;
		}
		$first = strtok( $k, '/' );
		$list = array_merge( self::RESERVED, [ strtolower( trim( rest_get_url_prefix(), '/' ) ) ] );
		if ( in_array( $first, $list, true ) || in_array( $k, $list, true ) || preg_match( '/(^|\/)[^\/]*sitemap[^\/]*\.(xml|xsl)$|^wp-/', $k ) ) {
			return true;
		}
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pto ) {
			if ( $pto->has_archive ) {
				$slug = true === $pto->has_archive ? ( is_array( $pto->rewrite ) && ! empty( $pto->rewrite['slug'] ) ? $pto->rewrite['slug'] : $pto->name ) : (string) $pto->has_archive;
				if ( self::key( trim( $slug, '/' ) ) === $k ) {
					return true;
				}
			}
		}
		return (bool) apply_filters( 'launch_builder_uri_reserved', false, $k );
	}

	/**
	 * A URI typed by a person or a tool, made safe: per segment tags stripped, accents transliterated (setting), spaces to
	 * dashes, only letters, digits and - _ . ~ kept, lower-cased (setting). '' when nothing is left.
	 */
	public static function sanitize_uri( string $uri ): string {
		$s = self::settings();
		$path = self::local_path( $uri );
		if ( null === $path ) {
			$path = self::clean_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		}
		$out = [];
		foreach ( explode( '/', $path ) as $seg ) {
			$seg = wp_strip_all_tags( $seg );
			if ( $s['transliterate'] ) {
				$seg = remove_accents( $seg );
			}
			$seg = (string) preg_replace( '/[\s+]+/u', '-', $seg );
			$clean = @preg_replace( '/[^\p{L}\p{N}\-_.~]+/u', '', $seg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$seg = null === $clean ? (string) preg_replace( '/[^A-Za-z0-9\-_.~]+/', '', $seg ) : $clean;
			$seg = trim( (string) preg_replace( '/-{2,}/', '-', $seg ), '-' );
			if ( $s['lowercase'] ) {
				$seg = self::key( $seg );
			}
			if ( '' !== $seg && '.' !== $seg && '..' !== $seg ) {
				$out[] = $seg;
			}
		}
		return implode( '/', $out );
	}

	/* ------------------------------------------------------------------ lookup */

	/** Bump the cache generation after any write to the URI table. */
	private static function changed(): void {
		self::$memo = [];
		wp_cache_set_last_changed( self::CACHE_GRP );
	}

	/** Rows ordering for one hash: custom before auto before native, current language first, then oldest. */
	private static function rank( array $a, array $b ): int {
		$o = [ 'custom' => 0, 'auto' => 1, 'native' => 2 ];
		$c = ( $o[ $a['source'] ] ?? 3 ) <=> ( $o[ $b['source'] ] ?? 3 );
		if ( 0 !== $c ) {
			return $c;
		}
		$l = self::current_lang();
		$c = ( (string) $b['lang'] === $l ) <=> ( (string) $a['lang'] === $l );
		return 0 !== $c ? $c : ( (int) $a['id'] <=> (int) $b['id'] );
	}

	/**
	 * Rows for some paths: ONE indexed query (memoised per request, cached in the object cache until the table changes).
	 *
	 * @return array<string,array[]> hash => rows, best first
	 */
	public static function lookup( array $paths ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return [];
		}
		$hashes = [];
		foreach ( $paths as $p ) {
			$hashes[ self::hash( (string) $p ) ] = true;
		}
		$hashes = array_keys( $hashes );
		sort( $hashes );
		if ( ! $hashes ) {
			return [];
		}
		$mk = get_current_blog_id() . ':' . implode( ',', $hashes );
		if ( isset( self::$memo[ $mk ] ) ) {
			return self::$memo[ $mk ];
		}
		$ck = 'uris:' . wp_cache_get_last_changed( self::CACHE_GRP ) . ':' . md5( $mk );
		$rows = wp_cache_get( $ck, 'launch_builder' );
		if ( ! is_array( $rows ) ) {
			$t = self::table();
			$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, uri_hash, uri, object_type, object_id, subtype, source, lang FROM {$t} WHERE uri_hash IN ({$in})", $hashes ), ARRAY_A ); // phpcs:ignore WordPress.DB
			wp_cache_set( $ck, $rows, 'launch_builder', HOUR_IN_SECONDS );
		}
		$by = [];
		foreach ( $rows as $r ) {
			$by[ $r['uri_hash'] ][] = $r;
		}
		foreach ( $by as $h => $list ) {
			usort( $list, [ __CLASS__, 'rank' ] );
			$by[ $h ] = $list;
		}
		if ( count( self::$memo ) > 200 ) {
			self::$memo = [];
		}
		self::$memo[ $mk ] = $by;
		return $by;
	}

	/** The object that owns a path (best row), or null. */
	public static function lookup_uri( string $path ): ?array {
		$rows = self::lookup( [ $path ] )[ self::hash( $path ) ] ?? [];
		return $rows ? $rows[0] : null;
	}

	/** Every row on a path. */
	public static function owners( string $path ): array {
		return self::lookup( [ $path ] )[ self::hash( $path ) ] ?? [];
	}

	/** Which of these hashes are live URIs (for audits). */
	public static function hashes_present( array $hashes ): array {
		global $wpdb;
		$out = [];
		if ( ! self::installed() ) {
			return $out;
		}
		$t = self::table();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$out = array_merge( $out, (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT uri_hash FROM {$t} WHERE uri_hash IN ({$in})", $chunk ) ) ); // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	private static function row( string $type, int $id ): ?array {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE object_type = %s AND object_id = %d LIMIT 1", $type, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ?: null;
	}

	private static function write_row( string $type, int $id, string $uri, string $source ): void {
		global $wpdb;
		$t = self::table();
		if ( 'term' === $type ) {
			$term = get_term( $id );
			$sub = $term instanceof \WP_Term ? $term->taxonomy : '';
		} else {
			$sub = (string) get_post_type( $id );
		}
		$data = [ 'uri_hash' => self::hash( $uri ), 'uri' => $uri, 'object_type' => $type, 'object_id' => $id, 'subtype' => $sub, 'source' => $source, 'lang' => self::lang( $type, $id, $sub ) ];
		$ex = self::row( $type, $id );
		if ( $ex ) {
			$wpdb->update( $t, $data, [ 'id' => (int) $ex['id'] ] ); // phpcs:ignore WordPress.DB
		} elseif ( ! $wpdb->insert( $t, $data ) ) { // phpcs:ignore WordPress.DB
			$ex = self::row( $type, $id ); // Lost a race on the unique key: update the winner.
			if ( $ex ) {
				$wpdb->update( $t, $data, [ 'id' => (int) $ex['id'] ] ); // phpcs:ignore WordPress.DB
			}
		}
		self::changed();
	}

	private static function delete_row( string $type, int $id ): void {
		global $wpdb;
		if ( self::installed() ) {
			$wpdb->delete( self::table(), [ 'object_type' => $type, 'object_id' => $id ] ); // phpcs:ignore WordPress.DB
			self::changed();
		}
	}

	/** Language of an object (Polylang / WPML / filter launch_builder_uri_lang), '' when the site is monolingual. */
	private static function lang( string $type, int $id, string $sub ): string {
		$l = '';
		if ( 'term' === $type && function_exists( 'pll_get_term_language' ) ) {
			$l = (string) pll_get_term_language( $id );
		} elseif ( 'post' === $type && function_exists( 'pll_get_post_language' ) ) {
			$l = (string) pll_get_post_language( $id );
		} elseif ( has_filter( 'wpml_element_language_code' ) ) {
			$l = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => $sub ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		return substr( (string) apply_filters( 'launch_builder_uri_lang', $l, $type, $id ), 0, 20 );
	}

	private static function current_lang(): string {
		$l = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$l = (string) pll_current_language();
		} elseif ( has_filter( 'wpml_current_language' ) ) {
			$l = (string) apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		return (string) apply_filters( 'launch_builder_uri_current_lang', $l );
	}

	/* ------------------------------------------------------------------ URIs of objects */

	/** WordPress's own path for an object (our link filters bypassed); null for drafts, plain permalinks, other hosts. */
	public static function native_path( string $type, int $id ): ?string {
		self::$bypass++;
		try {
			if ( 'term' === $type ) {
				$t = get_term( $id );
				$url = $t instanceof \WP_Term ? get_term_link( $t ) : '';
			} else {
				$url = get_permalink( $id );
			}
		} finally {
			self::$bypass--;
		}
		return self::path_of_link( $url );
	}

	private static function path_of_link( $url ): ?string {
		if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '?' ) ) {
			return null;
		}
		return self::local_path( $url );
	}

	/** The object's URI now (meta or native), live or not. */
	public static function current_uri( string $type, int $id ): ?string {
		$m = self::meta( $type, $id, self::META );
		return '' !== $m ? $m : self::native_path( $type, $id );
	}

	/** The public URL of a live object ('' when it is not live). */
	public static function live_url( string $type, int $id ): string {
		if ( ! self::live( $type, $id ) ) {
			return '';
		}
		$u = 'term' === $type ? get_term_link( (int) $id ) : get_permalink( $id );
		return is_string( $u ) ? $u : '';
	}

	/** Primary term of a post in a taxonomy: Rank Math primary, Yoast primary, else the first by name. */
	public static function primary_term( int $post_id, string $tax ): ?\WP_Term {
		$terms = get_the_terms( $post_id, $tax );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}
		foreach ( [ 'rank_math_primary_' . $tax, '_yoast_wpseo_primary_' . $tax ] as $k ) {
			$tid = (int) get_post_meta( $post_id, $k, true );
			foreach ( $tid ? $terms : [] as $t ) {
				if ( (int) $t->term_id === $tid ) {
					return $t;
				}
			}
		}
		usort( $terms, static function ( $a, $b ) { $c = strcasecmp( $a->name, $b->name ); return 0 !== $c ? $c : ( $a->term_id <=> $b->term_id ); } );
		return $terms[0];
	}

	/** Ancestor slugs + own slug of a term, top down. */
	private static function term_path( \WP_Term $t ): string {
		$parts = [ rawurldecode( $t->slug ) ];
		foreach ( get_ancestors( $t->term_id, $t->taxonomy, 'taxonomy' ) as $aid ) {
			$a = get_term( $aid, $t->taxonomy );
			if ( $a instanceof \WP_Term ) {
				array_unshift( $parts, rawurldecode( $a->slug ) );
			}
		}
		return implode( '/', $parts );
	}

	private static function stop_words( string $slug ): string {
		$s = self::settings();
		if ( ! $s['stop_words'] ) {
			return $slug;
		}
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( '' !== trim( (string) $s['stop_word_list'] ) ? (string) $s['stop_word_list'] : self::STOP_WORDS ) ) ) );
		$words = explode( '-', $slug );
		$kept = array_values( array_filter( $words, static function ( $w ) use ( $list ) { return ! in_array( strtolower( $w ), $list, true ); } ) );
		return $kept ? implode( '-', $kept ) : $slug;
	}

	/** Allowed tags of a structure. */
	public static function tags( string $kind, string $name ): array {
		if ( 'term' === $kind ) {
			return [ 'term', 'slug', 'term_id', 'parent', 'parent_uri', 'taxonomy', $name ];
		}
		return array_merge( [ 'postname', 'post_id', 'parent', 'parent_uri', 'lb_area_parent', 'post_type', 'year', 'monthnum', 'day', 'author' ], get_object_taxonomies( $name ) );
	}

	/**
	 * The automatic URI of an object from its type's permastructure ('' when the type has none).
	 */
	public static function generate( string $type, int $id ): string {
		if ( 'term' === $type ) {
			$t = get_term( $id );
			if ( ! $t instanceof \WP_Term ) {
				return '';
			}
			$st = self::structure( 'term', $t->taxonomy );
			if ( '' === $st ) {
				return '';
			}
			$parents = '';
			if ( $t->parent ) {
				$p = get_term( $t->parent, $t->taxonomy );
				$parents = $p instanceof \WP_Term ? self::term_path( $p ) : '';
			}
			$tax = get_taxonomy( $t->taxonomy );
			$map = [
				'%term%'                => rawurldecode( $t->slug ),
				'%slug%'                => rawurldecode( $t->slug ),
				'%term_id%'             => (string) $t->term_id,
				'%parent%'              => $parents,
				'%parent_uri%'          => $t->parent ? (string) self::current_uri( 'term', (int) $t->parent ) : '',
				'%taxonomy%'            => $tax && is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ? trim( $tax->rewrite['slug'], '/' ) : $t->taxonomy,
				'%' . $t->taxonomy . '%' => self::term_path( $t ),
			];
			return self::finish( strtr( $st, $map ) );
		}
		$p = get_post( $id );
		if ( ! $p instanceof \WP_Post ) {
			return '';
		}
		$st = self::structure( 'post', $p->post_type );
		if ( '' === $st ) {
			return '';
		}
		$slug = '' !== $p->post_name ? rawurldecode( $p->post_name ) : sanitize_title( $p->post_title );
		if ( '' === $slug ) {
			$slug = (string) $p->ID;
		}
		$ancestors = [];
		foreach ( array_reverse( get_post_ancestors( $p ) ) as $aid ) {
			$ancestors[] = rawurldecode( (string) get_post_field( 'post_name', $aid ) );
		}
		$pto = get_post_type_object( $p->post_type );
		$time = strtotime( '0000-00-00 00:00:00' === $p->post_date ? current_time( 'mysql' ) : $p->post_date );
		$map = [
			'%postname%'       => self::stop_words( $slug ),
			'%post_id%'        => (string) $p->ID,
			'%parent%'         => implode( '/', array_filter( $ancestors, 'strlen' ) ),
			'%parent_uri%'     => $p->post_parent ? (string) self::current_uri( 'post', (int) $p->post_parent ) : '',
			'%lb_area_parent%' => $ancestors ? (string) end( $ancestors ) : '',
			'%post_type%'      => $pto && is_array( $pto->rewrite ) && ! empty( $pto->rewrite['slug'] ) ? trim( $pto->rewrite['slug'], '/' ) : $p->post_type,
			'%year%'           => gmdate( 'Y', $time ),
			'%monthnum%'       => gmdate( 'm', $time ),
			'%day%'            => gmdate( 'd', $time ),
			'%author%'         => (string) get_the_author_meta( 'user_nicename', (int) $p->post_author ),
		];
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			if ( false !== strpos( $st, '%' . $tax . '%' ) ) {
				$term = self::primary_term( $p->ID, $tax );
				$map[ '%' . $tax . '%' ] = $term ? self::term_path( $term ) : '';
			}
		}
		return self::finish( strtr( $st, $map ) );
	}

	/** Collapse empty segments; segments keep the case/characters of the slugs they came from. */
	private static function finish( string $uri ): string {
		$parts = array_filter( explode( '/', self::clean_path( $uri ) ), 'strlen' );
		$uri = implode( '/', $parts );
		return self::settings()['lowercase'] ? self::key( $uri ) : $uri;
	}

	/** $uri if no other object owns it and it is not reserved, else $uri-2, -3, ... */
	private static function unique( string $uri, string $type, int $id ): string {
		$base = $uri;
		for ( $i = 2; $i < 100; $i++ ) {
			$taken = self::reserved( $uri );
			foreach ( $taken ? [] : self::owners( $uri ) as $r ) {
				if ( ! ( $r['object_type'] === $type && (int) $r['object_id'] === $id ) ) {
					$taken = true;
					break;
				}
			}
			if ( ! $taken ) {
				return $uri;
			}
			$uri = $base . '-' . $i;
		}
		return $uri;
	}

	/**
	 * What the object's URI is (or will be once published): [uri|null, source]. $write stores an automatic URI in meta and
	 * clears stale meta (refresh), false only computes (dry runs, the editor panel).
	 */
	private static function compute( string $type, int $id, bool $write ): array {
		$mode = self::meta( $type, $id, self::META_MODE );
		$meta = self::meta( $type, $id, self::META );
		if ( 'custom' === $mode && '' !== $meta ) {
			return [ $meta, 'custom' ];
		}
		$gen = self::generate( $type, $id );
		if ( '' !== $gen ) {
			$gen = self::unique( $gen, $type, $id );
			if ( $write ) {
				if ( $gen !== $meta ) {
					self::write_meta( $type, $id, self::META, $gen );
				}
				if ( '' !== $mode ) {
					self::delete_meta( $type, $id, self::META_MODE );
				}
			}
			return [ $gen, 'auto' ];
		}
		if ( $write ) {
			if ( '' !== $meta ) {
				self::delete_meta( $type, $id, self::META );
			}
			if ( '' !== $mode ) {
				self::delete_meta( $type, $id, self::META_MODE );
			}
			$native = self::native_path( $type, $id );
		} else {
			self::$bypass++;
			try {
				$native = 'term' === $type ? self::path_of_link( get_term_link( (int) $id ) ) : self::path_of_link( get_permalink( $id ) );
			} finally {
				self::$bypass--;
			}
		}
		return [ null === $native || '' === $native ? null : $native, 'native' ];
	}

	/**
	 * Bring one object's URI, meta and index row up to date. When a live object's URI moved, the old URI gets a 301
	 * (unless the object was created in this request) and dependants are refreshed (children, posts in a category).
	 *
	 * @param array $o { cascade: bool, redirect: bool, old: ?string (old path when the object had no row), force_cascade: bool }
	 */
	public static function refresh( string $type, int $id, array $o = [] ): array {
		$o += [ 'cascade' => true, 'redirect' => true, 'old' => null, 'force_cascade' => false ];
		$key = self::ref( $type, $id );
		if ( ! self::installed() || isset( self::$visiting[ $key ] ) || isset( self::$deleting[ $key ] ) ) {
			return [];
		}
		self::$visiting[ $key ] = true;
		try {
			$row = self::row( $type, $id );
			$old = $row ? (string) $row['uri'] : ( is_string( $o['old'] ) ? $o['old'] : null );
			if ( ! self::live( $type, $id ) ) {
				if ( $row ) {
					self::delete_row( $type, $id );
					Cache::purge_later();
				}
				return [ 'object' => $key, 'live' => false ];
			}
			[ $new, $source ] = self::compute( $type, $id, true );
			if ( null === $new ) {
				if ( $row ) {
					self::delete_row( $type, $id );
				}
				return [ 'object' => $key, 'live' => true, 'uri' => null ];
			}
			$rep = [ 'object' => $key, 'old' => $old, 'new' => $new, 'source' => $source ];
			if ( ! $row || (string) $row['uri'] !== $new || (string) $row['source'] !== $source ) {
				self::write_row( $type, $id, $new, $source );
				Cache::purge_later();
			}
			$moved = null !== $old && '' !== $old && self::key( $old ) !== self::key( $new );
			if ( $moved && $o['redirect'] && empty( self::$fresh[ $key ] ) && self::settings()['auto_redirect'] ) {
				$rep['redirect'] = Redirects::uri_changed( $type, $id, $old, $new );
			}
			if ( $o['cascade'] && ( $moved || $o['force_cascade'] ) ) {
				$rep['cascaded'] = self::cascade( $type, $id );
			}
			return $rep;
		} finally {
			unset( self::$visiting[ $key ] );
		}
	}

	/** Objects whose URI depends on this one: child posts/terms, posts whose structure uses this term's taxonomy. */
	private static function dependents( string $type, int $id ): array {
		$out = [];
		if ( 'term' === $type ) {
			$t = get_term( $id );
			if ( ! $t instanceof \WP_Term ) {
				return [];
			}
			if ( is_taxonomy_hierarchical( $t->taxonomy ) ) {
				foreach ( (array) get_terms( [ 'taxonomy' => $t->taxonomy, 'parent' => $id, 'hide_empty' => false, 'fields' => 'ids' ] ) as $cid ) {
					$out[] = 'term:' . (int) $cid;
				}
			}
			$types = array_values( array_filter( self::post_types(), static function ( $pt ) use ( $t ) { return self::uses_tag( 'post', $pt, $t->taxonomy ); } ) );
			if ( $types ) {
				foreach ( (array) get_objects_in_term( $id, $t->taxonomy ) as $oid ) {
					if ( in_array( get_post_type( (int) $oid ), $types, true ) ) {
						$out[] = 'post:' . (int) $oid;
					}
				}
			}
			return $out;
		}
		$p = get_post( $id );
		if ( ! $p || ! is_post_type_hierarchical( $p->post_type ) ) {
			return [];
		}
		foreach ( get_posts( [ 'post_type' => $p->post_type, 'post_parent' => $id, 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 1000, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] ) as $cid ) {
			$out[] = 'post:' . (int) $cid;
		}
		return $out;
	}

	private static function cascade( string $type, int $id ): int {
		$keys = self::dependents( $type, $id );
		$n = 0;
		foreach ( $keys as $i => $k ) {
			if ( self::$cascaded >= self::CASCADE_MAX ) {
				self::enqueue( array_slice( $keys, $i ) );
				break;
			}
			self::$cascaded++;
			$n++;
			[ $t, $oid ] = explode( ':', $k );
			self::refresh( $t, (int) $oid, [ 'force_cascade' => 'term' === $t || is_post_type_hierarchical( (string) get_post_type( (int) $oid ) ) ] );
		}
		return $n;
	}

	/** Large cascades continue in WP-Cron (objects keep their old, still working URIs until then). */
	private static function enqueue( array $keys ): void {
		$q = get_option( self::QUEUE, [] );
		$q = array_values( array_unique( array_merge( is_array( $q ) ? $q : [], $keys ) ) );
		update_option( self::QUEUE, $q, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 15, self::CRON );
		}
	}

	public static function run_queue(): void {
		$q = get_option( self::QUEUE, [] );
		$q = is_array( $q ) ? $q : [];
		$batch = array_splice( $q, 0, 200 );
		update_option( self::QUEUE, $q, false );
		self::$cascaded = 0;
		foreach ( $batch as $k ) {
			if ( preg_match( '/^(post|term):(\d+)$/', (string) $k, $m ) ) {
				self::refresh( $m[1], (int) $m[2], [ 'force_cascade' => true ] );
			}
		}
		$left = get_option( self::QUEUE, [] );
		if ( $left && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 15, self::CRON );
		} elseif ( ! $left ) {
			delete_option( self::QUEUE );
		}
	}

	/**
	 * Set an object's URI by hand, or back to automatic ($uri '' / null / 'auto').
	 *
	 * @param array $opt { force: allow a URI another object uses / that a redirect elsewhere starts from, dry_run }
	 * @return array|\WP_Error { object, title, old, new, url, mode, redirect?, dry_run }
	 */
	public static function set_uri( string $type, int $id, ?string $uri, array $opt = [] ) {
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_install', 'The URL tables are not installed yet (open wp-admin once or run the migrations).', [ 'status' => 503 ] );
		}
		$dry = ! empty( $opt['dry_run'] );
		$force = ! empty( $opt['force'] );
		$before = self::row( $type, $id );
		$before = $before ? (string) $before['uri'] : (string) self::current_uri( $type, $id );
		$out = self::describe( $type, $id ) + [ 'old' => '' === $before ? '' : '/' . $before, 'dry_run' => $dry ];
		if ( null === $uri || '' === trim( $uri ) || 'auto' === strtolower( trim( $uri ) ) ) {
			$mode = self::meta( $type, $id, self::META_MODE );
			if ( $dry ) {
				[ $new ] = self::compute( $type, $id, false );
				if ( 'custom' === $mode ) { // compute() returned the custom one: what would auto give?
					$gen = self::generate( $type, $id );
					$new = '' !== $gen ? self::unique( $gen, $type, $id ) : self::native_path( $type, $id );
				}
				return $out + [ 'new' => null === $new ? '' : '/' . $new, 'mode' => 'auto' ];
			}
			if ( '' !== $mode ) {
				self::delete_meta( $type, $id, self::META_MODE );
			}
			$rep = self::refresh( $type, $id, [ 'old' => $before ] );
			$now = self::current_uri( $type, $id );
			return $out + [ 'new' => null === $now ? '' : '/' . $now, 'url' => self::live_url( $type, $id ), 'mode' => '' !== self::meta( $type, $id, self::META ) ? 'auto' : 'native', 'redirect' => $rep['redirect'] ?? null, 'cascaded' => $rep['cascaded'] ?? 0 ];
		}
		$clean = self::sanitize_uri( $uri );
		if ( '' === $clean ) {
			return new \WP_Error( 'lb_uri_empty', 'That URI is empty once cleaned (use letters, digits, - and /).', [ 'status' => 400 ] );
		}
		if ( self::reserved( $clean ) ) {
			return new \WP_Error( 'lb_uri_reserved', 'That URI is reserved (WordPress, the REST API, feeds, sitemaps or a post type archive use it).', [ 'status' => 400 ] );
		}
		foreach ( self::owners( $clean ) as $r ) {
			if ( ! ( $r['object_type'] === $type && (int) $r['object_id'] === $id ) && ! $force ) {
				return new \WP_Error( 'lb_uri_taken', 'That URI is already used by ' . wp_json_encode( self::describe( (string) $r['object_type'], (int) $r['object_id'] ) ) . '. Pass force: true to share it anyway (the audit will flag it).', [ 'status' => 409, 'owner' => self::ref( (string) $r['object_type'], (int) $r['object_id'] ) ] );
			}
		}
		$conf = array_values( array_filter( Redirects::conflicts( $type, $id, $clean ), static function ( $c ) { return 'rankmath' === $c['store']; } ) );
		if ( $conf && ! $force ) {
			return new \WP_Error( 'lb_uri_redirected', 'A Rank Math redirect sends that URL elsewhere (' . wp_json_encode( $conf ) . '); it would hide the page. Pass force: true to remove that redirect source.', [ 'status' => 409 ] );
		}
		if ( $dry ) {
			return $out + [ 'new' => '/' . $clean, 'url' => self::build_url( $clean ), 'mode' => 'custom', 'redirects_removed' => $conf ];
		}
		self::write_meta( $type, $id, self::META, $clean );
		self::write_meta( $type, $id, self::META_MODE, 'custom' );
		if ( $force ) {
			Redirects::release( $type, $id, $clean, [ $before ], true );
		}
		$rep = self::refresh( $type, $id, [ 'old' => $before ] );
		$slug = self::sync_slug( $type, $id, $clean );
		return $out + [ 'new' => '/' . $clean, 'url' => self::live_url( $type, $id ) ?: self::build_url( $clean ), 'mode' => 'custom', 'live' => self::live( $type, $id ), 'redirect' => $rep['redirect'] ?? null, 'cascaded' => $rep['cascaded'] ?? 0, 'slug' => $slug ];
	}

	/** Setting slug_sync: the native slug follows the last segment of a custom URI. Returns the slug now. */
	private static function sync_slug( string $type, int $id, string $uri ): string {
		$segs = explode( '/', $uri );
		$want = sanitize_title( (string) end( $segs ) );
		if ( 'term' === $type ) {
			$t = get_term( $id );
			if ( ! $t instanceof \WP_Term ) {
				return '';
			}
			if ( self::settings()['slug_sync'] && '' !== $want && $want !== $t->slug ) {
				$r = wp_update_term( $id, $t->taxonomy, [ 'slug' => $want ] );
				if ( ! is_wp_error( $r ) ) {
					return (string) get_term( $id )->slug;
				}
			}
			return $t->slug;
		}
		$p = get_post( $id );
		if ( ! $p ) {
			return '';
		}
		if ( self::settings()['slug_sync'] && '' !== $want && $want !== $p->post_name ) {
			wp_update_post( [ 'ID' => $id, 'post_name' => $want ] );
			return (string) get_post_field( 'post_name', $id );
		}
		return $p->post_name;
	}

	/* ------------------------------------------------------------------ change hooks */

	public static function post_saved( $post_id, $post, $update, $post_before ): void {
		if ( ! $post instanceof \WP_Post || ! self::installed() || ! self::managed_post_type( $post->post_type ) ) {
			return;
		}
		$key = self::ref( 'post', (int) $post_id );
		if ( ! $update ) {
			self::$fresh[ $key ] = true;
		}
		$old = null;
		$force = false;
		if ( $post_before instanceof \WP_Post ) {
			$force = $post_before->post_name !== $post->post_name || (int) $post_before->post_parent !== (int) $post->post_parent;
			if ( $post_before->post_name !== $post->post_name && '' !== $post->post_name && self::settings()['slug_sync'] && 'custom' === self::meta( 'post', (int) $post_id, self::META_MODE ) ) {
				self::follow_slug( 'post', (int) $post_id, rawurldecode( $post_before->post_name ), rawurldecode( $post->post_name ) );
			}
			if ( 'publish' === $post_before->post_status && ! self::row( 'post', (int) $post_id ) ) {
				self::$bypass++;
				$old = self::path_of_link( get_permalink( $post_before ) );
				self::$bypass--;
			}
		}
		self::refresh( 'post', (int) $post_id, [ 'old' => $old, 'force_cascade' => $force ] );
	}

	/** A custom URI whose last segment was the old slug takes the new slug. */
	private static function follow_slug( string $type, int $id, string $old_slug, string $new_slug ): void {
		$uri = self::meta( $type, $id, self::META );
		$segs = explode( '/', $uri );
		if ( '' === $uri || self::key( (string) end( $segs ) ) !== self::key( $old_slug ) ) {
			return;
		}
		$segs[ count( $segs ) - 1 ] = $new_slug;
		$new = implode( '/', $segs );
		foreach ( self::owners( $new ) as $r ) {
			if ( ! ( $r['object_type'] === $type && (int) $r['object_id'] === $id ) ) {
				return; // Taken: keep the custom URI as it is.
			}
		}
		self::write_meta( $type, $id, self::META, $new );
	}

	public static function terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $append );
		$p = get_post( (int) $object_id );
		if ( ! $p || ! self::installed() || ! self::managed_post_type( $p->post_type ) || ! self::uses_tag( 'post', $p->post_type, (string) $taxonomy ) ) {
			return;
		}
		$a = array_map( 'intval', (array) $tt_ids );
		$b = array_map( 'intval', (array) $old_tt_ids );
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			self::refresh( 'post', (int) $object_id );
		}
	}

	public static function post_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( self::$writing || ! self::installed() ) {
			return;
		}
		$key = (string) $meta_key;
		if ( self::META === $key || self::META_MODE === $key ) {
			self::external_meta( 'post', (int) $object_id, self::META === $key ? $meta_value : null );
		} elseif ( preg_match( '/^(?:rank_math_primary_|_yoast_wpseo_primary_)(.+)$/', $key, $m ) ) {
			$p = get_post( (int) $object_id );
			if ( $p && self::managed_post_type( $p->post_type ) && self::uses_tag( 'post', $p->post_type, $m[1] ) ) {
				self::refresh( 'post', $p->ID );
			}
		}
	}

	public static function term_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( ! self::$writing && self::installed() && ( self::META === $meta_key || self::META_MODE === $meta_key ) ) {
			self::external_meta( 'term', (int) $object_id, self::META === $meta_key ? $meta_value : null );
		}
	}

	/**
	 * Someone else wrote our meta (an import, a "duplicate post" tool copying every meta key...). A copied URI that another
	 * object owns is dropped; any other written URI counts as a custom one.
	 */
	private static function external_meta( string $type, int $id, $value ): void {
		$uri = self::meta( $type, $id, self::META );
		if ( '' !== $uri ) {
			foreach ( self::owners( $uri ) as $r ) {
				if ( ! ( $r['object_type'] === $type && (int) $r['object_id'] === $id ) ) {
					self::delete_meta( $type, $id, self::META );
					self::delete_meta( $type, $id, self::META_MODE );
					break;
				}
			}
			if ( null !== $value && '' !== self::meta( $type, $id, self::META ) && 'custom' !== self::meta( $type, $id, self::META_MODE ) ) {
				self::write_meta( $type, $id, self::META_MODE, 'custom' );
			}
		}
		self::refresh( $type, $id );
	}

	public static function post_deleting( $post_id ): void {
		self::$deleting[ self::ref( 'post', (int) $post_id ) ] = true;
	}

	public static function term_deleting( $term_id ): void {
		self::$deleting[ self::ref( 'term', (int) $term_id ) ] = true;
	}

	public static function post_deleted( $post_id ): void {
		self::delete_row( 'post', (int) $post_id );
		Redirects::object_deleted( 'post', (int) $post_id );
	}

	public static function term_created( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		if ( self::installed() && self::managed_taxonomy( (string) $taxonomy ) ) {
			self::$fresh[ self::ref( 'term', (int) $term_id ) ] = true;
			self::refresh( 'term', (int) $term_id );
		}
	}

	public static function term_before_edit( $term_id, $taxonomy ): void {
		$t = get_term( (int) $term_id, (string) $taxonomy );
		if ( $t instanceof \WP_Term ) {
			self::$term_before[ (int) $term_id ] = [ 'slug' => $t->slug, 'parent' => (int) $t->parent ];
		}
	}

	public static function term_edited( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		$term_id = (int) $term_id;
		if ( ! self::installed() || ! self::managed_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		$t = get_term( $term_id, (string) $taxonomy );
		$was = self::$term_before[ $term_id ] ?? null;
		unset( self::$term_before[ $term_id ] );
		$force = $was && $t instanceof \WP_Term && ( $was['slug'] !== $t->slug || $was['parent'] !== (int) $t->parent );
		if ( $was && $t instanceof \WP_Term && $was['slug'] !== $t->slug && self::settings()['slug_sync'] && 'custom' === self::meta( 'term', $term_id, self::META_MODE ) ) {
			self::follow_slug( 'term', $term_id, rawurldecode( $was['slug'] ), rawurldecode( $t->slug ) );
		}
		if ( self::term_form_posted( $term_id ) ) {
			self::term_form_save( $term_id, $force );
			return;
		}
		self::refresh( 'term', $term_id, [ 'force_cascade' => $force ] );
	}

	public static function term_deleted( $term, $tt_id, $taxonomy, $deleted_term, $object_ids ): void {
		unset( $tt_id, $deleted_term );
		self::delete_row( 'term', (int) $term );
		Redirects::object_deleted( 'term', (int) $term );
		foreach ( (array) $object_ids as $oid ) {
			$p = get_post( (int) $oid );
			if ( $p && self::managed_post_type( $p->post_type ) && self::uses_tag( 'post', $p->post_type, (string) $taxonomy ) ) {
				self::refresh( 'post', (int) $oid );
			}
		}
	}

	/* ------------------------------------------------------------------ links */

	public static function post_link( $link, $post, $leavename = false ) {
		unset( $leavename );
		$sample = is_object( $post ) && isset( $post->filter ) && 'sample' === $post->filter;
		$post = get_post( $post );
		return $post ? self::post_url( (string) $link, $post, $sample ) : $link;
	}

	public static function page_link( $link, $post_id, $sample = false ) {
		$post = get_post( $post_id );
		return $post ? self::post_url( (string) $link, $post, (bool) $sample ) : $link;
	}

	public static function post_type_link( $link, $post, $leavename = false, $sample = false ) {
		unset( $leavename );
		$post = get_post( $post );
		return $post ? self::post_url( (string) $link, $post, (bool) $sample ) : $link;
	}

	/**
	 * $sample is core's "sample" flag (a post object with filter 'sample': the editor's sample permalink, but also Rank
	 * Math's sitemap, which marks every post it lists that way). It only means "a draft may get its future URL". The
	 * editable form (slug as %postname% / %pagename%) is returned only when the caller asked core for it ($leavename):
	 * core then leaves the placeholder in $link, which is what we test.
	 */
	private static function post_url( string $link, \WP_Post $post, bool $sample ): string {
		if ( self::$bypass || ! self::active() || ! self::managed_post_type( $post->post_type ) || self::is_front( $post ) ) {
			return $link;
		}
		$placeholder = false !== strpos( $link, '%postname%' ) || false !== strpos( $link, '%pagename%' ) || false !== strpos( $link, '%' . $post->post_type . '%' );
		if ( ! $placeholder ) {
			if ( 'publish' === $post->post_status ) {
				$uri = (string) get_post_meta( $post->ID, self::META, true );
				return '' === $uri ? $link : self::build_url( $uri );
			}
			if ( ! $sample ) {
				return $link; // Drafts keep WordPress's preview link.
			}
			[ $uri, $source ] = self::compute( 'post', (int) $post->ID, false );
			return null === $uri || 'native' === $source ? $link : self::build_url( $uri );
		}
		// Editor (sample permalink): the planned URI, with the slug as %postname% / %pagename% where the editor can edit it.
		[ $uri, $source ] = self::compute( 'post', (int) $post->ID, false );
		if ( null === $uri || 'native' === $source ) {
			return $link;
		}
		$stored = rawurldecode( (string) get_post_field( 'post_name', $post->ID ) );
		if ( '' === $stored ) {
			return self::build_url( $uri );
		}
		$tail = $stored;
		$tag = '%postname%';
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$tag = '%pagename%'; // Core puts the native parent path in front of %pagename%.
			$parents = [];
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $aid ) {
				$parents[] = rawurldecode( (string) get_post_field( 'post_name', $aid ) );
			}
			$tail = implode( '/', array_merge( $parents, [ $stored ] ) );
		}
		$k = self::key( $uri );
		$kt = self::key( $tail );
		if ( $k !== $kt && substr( $k, -strlen( '/' . $kt ) ) !== '/' . $kt ) {
			return self::build_url( $uri ); // The URI does not end with the slug: show it whole.
		}
		$prefix = trim( (string) substr( $uri, 0, strlen( $uri ) - strlen( $tail ) ), '/' );
		$base = '' === $prefix ? home_url( '/' ) : trailingslashit( home_url( '/' . self::encode_path( $prefix ) ) );
		return self::slash( $base . $tag );
	}

	public static function term_link( $link, $term, $taxonomy ) {
		if ( self::$bypass || ! $term instanceof \WP_Term || ! self::active() || ! self::managed_taxonomy( (string) $taxonomy ) || self::category_page( $term ) ) {
			return $link;
		}
		$uri = (string) get_term_meta( $term->term_id, self::META, true );
		return '' === $uri ? $link : self::build_url( $uri );
	}

	/** url_to_postid() for our URIs: hand it a ?p= URL it understands. */
	public static function url_to_postid( $url ) {
		if ( self::$bypass || ! self::active() || ! is_string( $url ) ) {
			return $url;
		}
		$path = self::local_path( $url );
		if ( null === $path || '' === $path ) {
			return $url;
		}
		$row = self::lookup_uri( $path );
		if ( ! $row || 'post' !== $row['object_type'] || 'native' === $row['source'] ) {
			return $url;
		}
		return add_query_arg( 'p', (int) $row['object_id'], home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ requests */

	/**
	 * Candidate base paths with the suffix they carry: the whole path first, then path minus page/N, comment-page-N,
	 * feed, embed, trackback, attachment/slug, /N and registered endpoints.
	 */
	private static function candidates( string $path ): array {
		global $wp_rewrite;
		$out = [ [ 'base' => $path, 'ep' => [] ] ];
		$pb = preg_quote( (string) ( $wp_rewrite->pagination_base ?? 'page' ), '#' );
		$cb = preg_quote( (string) ( $wp_rewrite->comments_pagination_base ?? 'comment-page' ), '#' );
		$feeds = implode( '|', array_map( static function ( $f ) { return preg_quote( (string) $f, '#' ); }, (array) ( $wp_rewrite->feeds ?? [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ] ) ) );
		$rx = [
			[ "#^(.+)/{$pb}/(\\d+)$#", 'paged' ],
			[ "#^(.+)/{$cb}-(\\d+)$#", 'cpage' ],
			[ "#^(.+?)/(?:feed/)?({$feeds})$#", 'feed' ],
			[ '#^(.+)/(embed)$#', 'embed' ],
			[ '#^(.+)/(trackback)$#', 'tb' ],
			[ '#^(.+)/attachment/([^/]+)$#', 'attachment' ],
			[ '#^(.+)/(\d+)$#', 'page' ],
		];
		foreach ( $rx as [ $re, $var ] ) {
			if ( preg_match( $re, $path, $m ) ) {
				$val = in_array( $var, [ 'paged', 'cpage', 'page' ], true ) ? (int) $m[2] : ( 'embed' === $var || 'tb' === $var ? 1 : $m[2] );
				$out[] = [ 'base' => $m[1], 'ep' => [ $var => $val ] ];
			}
		}
		foreach ( (array) ( $wp_rewrite->endpoints ?? [] ) as $e ) {
			if ( isset( $e[1], $e[2] ) && preg_match( '#^(.+?)/' . preg_quote( (string) $e[1], '#' ) . '(?:/(.*))?$#', $path, $m ) ) {
				$out[] = [ 'base' => $m[1], 'ep' => [ '_endpoint' => [ (string) $e[2] => (string) ( $m[2] ?? '' ) ], '_mask' => (int) $e[0] ] ];
			}
		}
		return $out;
	}

	private static function ep_mask( array $row ): int {
		if ( 'term' === $row['object_type'] ) {
			if ( 'category' === $row['subtype'] ) {
				return EP_CATEGORIES;
			}
			if ( 'post_tag' === $row['subtype'] ) {
				return EP_TAGS;
			}
			$tax = get_taxonomy( (string) $row['subtype'] );
			return $tax && is_array( $tax->rewrite ) && isset( $tax->rewrite['ep_mask'] ) ? (int) $tax->rewrite['ep_mask'] : EP_NONE;
		}
		if ( 'page' === $row['subtype'] ) {
			return EP_PAGES;
		}
		$pto = get_post_type_object( (string) $row['subtype'] );
		return $pto && is_array( $pto->rewrite ) && isset( $pto->rewrite['ep_mask'] ) ? (int) $pto->rewrite['ep_mask'] : EP_PERMALINK;
	}

	private static function endpoint_ok( array $row, array $ep ): bool {
		$post = 'post' === $row['object_type'];
		foreach ( [ 'page', 'cpage', 'tb', 'embed', 'attachment' ] as $only_posts ) {
			if ( isset( $ep[ $only_posts ] ) && ! $post ) {
				return false;
			}
		}
		if ( isset( $ep['_endpoint'] ) ) {
			return (bool) ( (int) $ep['_mask'] & self::ep_mask( $row ) );
		}
		return true;
	}

	/** The LB-owned object a path addresses: { row, ep, base, path } or null (no match, or WordPress's own URL). */
	public static function match( string $path ): ?array {
		if ( '' === $path || self::reserved( $path ) ) {
			return null;
		}
		$cands = self::candidates( $path );
		$rows = self::lookup( array_column( $cands, 'base' ) );
		foreach ( $cands as $c ) {
			foreach ( $rows[ self::hash( $c['base'] ) ] ?? [] as $row ) {
				if ( 'native' === $row['source'] ) {
					return null; // WordPress resolves its own URLs.
				}
				if ( self::endpoint_ok( $row, $c['ep'] ) && self::live( (string) $row['object_type'], (int) $row['object_id'] ) ) {
					return [ 'row' => $row, 'ep' => $c['ep'], 'base' => $c['base'], 'path' => $path ];
				}
			}
		}
		return null;
	}

	/** 'request' filter: our URIs win over whatever rewrite rule WordPress matched. */
	public static function resolve_request( $qv ) {
		global $wp;
		self::$matched = null;
		if ( ! is_array( $qv ) || ! self::active() || ! isset( $wp ) || ! is_object( $wp ) ) {
			return $qv;
		}
		foreach ( [ 'rest_route', 'sitemap', 'sitemap-subtype', 'sitemap-stylesheet', 'sitemap_n', 'xsl', 'robots', 'favicon', 'wc-api' ] as $k ) {
			if ( isset( $qv[ $k ] ) ) {
				return $qv;
			}
		}
		$path = self::clean_path( (string) $wp->request );
		$hit = '' === $path ? null : self::match( $path );
		if ( ! $hit ) {
			return $qv;
		}
		$vars = self::query_vars( $hit );
		if ( ! $vars ) {
			return $qv;
		}
		foreach ( $qv as $k => $v ) { // Keep what came in the query string (search, filters, utm...).
			if ( 'error' !== $k && ! isset( $vars[ $k ] ) && ( isset( $_GET[ $k ] ) || isset( $_POST[ $k ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$vars[ $k ] = $v;
			}
		}
		self::$matched = $hit;
		return $vars;
	}

	private static function query_vars( array $hit ): array {
		$row = $hit['row'];
		$id = (int) $row['object_id'];
		if ( 'term' === $row['object_type'] ) {
			$t = get_term( $id );
			if ( ! $t instanceof \WP_Term ) {
				return [];
			}
			$tax = get_taxonomy( $t->taxonomy );
			$vars = $tax && $tax->query_var ? [ $tax->query_var => $t->slug ] : [ 'taxonomy' => $t->taxonomy, 'term' => $t->slug ];
		} else {
			$p = get_post( $id );
			if ( ! $p ) {
				return [];
			}
			if ( 'page' === $p->post_type ) {
				$shop = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
				$vars = $shop && $shop === $id ? [ 'post_type' => 'product' ] : [ 'page_id' => $id ];
			} elseif ( 'post' === $p->post_type ) {
				$vars = [ 'p' => $id ];
			} else {
				$vars = [ 'p' => $id, 'post_type' => $p->post_type ];
			}
		}
		foreach ( $hit['ep'] as $k => $v ) {
			if ( '_mask' === $k ) {
				continue;
			}
			if ( '_endpoint' === $k ) {
				$vars = array_merge( $vars, (array) $v );
				continue;
			}
			if ( 'attachment' === $k ) {
				return [ 'attachment' => (string) $v ];
			}
			$vars[ $k ] = $v;
		}
		return $vars;
	}

	/** Did this request resolve to one of our URIs? */
	public static function matched(): bool {
		return null !== self::$matched;
	}

	/** Requests we resolved are canonicalised by canonical(), not by WordPress (which would guess from the native URL). */
	public static function redirect_canonical( $url ) {
		return self::$matched ? false : $url;
	}

	/** The suffix a canonical URL keeps ('' none, null = do not canonicalise this request). */
	private static function suffix( array $ep ): ?string {
		global $wp_rewrite;
		if ( ! $ep ) {
			return '';
		}
		if ( isset( $ep['paged'] ) && 1 === count( $ep ) ) {
			return (int) $ep['paged'] > 1 ? ( $wp_rewrite->pagination_base ?? 'page' ) . '/' . (int) $ep['paged'] : '';
		}
		if ( isset( $ep['page'] ) && 1 === count( $ep ) ) {
			return (int) $ep['page'] > 1 ? (string) (int) $ep['page'] : '';
		}
		return null;
	}

	/**
	 * template_redirect (priority 1): (1) a request we resolved is sent to its canonical form (case, slashes, page/1);
	 * (2) a native URL of an object that has its own URI (/services/x/, ?p=12) is sent to that URI.
	 */
	public static function canonical(): void {
		if ( ! self::active() || is_admin() || is_preview() || is_customize_preview() || ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), [ 'GET', 'HEAD' ], true ) ) { // phpcs:ignore
			return;
		}
		$req = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore
		if ( '' === $req || false !== stripos( $req, 'index.php' ) || is_404() ) {
			return;
		}
		$qs = (string) wp_unslash( $_SERVER['QUERY_STRING'] ?? '' ); // phpcs:ignore
		if ( self::$matched ) {
			$suffix = self::suffix( self::$matched['ep'] );
			if ( null === $suffix ) {
				return;
			}
			$want = self::build_url( trim( self::$matched['row']['uri'] . '/' . $suffix, '/' ) );
			if ( rawurldecode( $req ) !== rawurldecode( (string) wp_parse_url( $want, PHP_URL_PATH ) ) ) {
				self::go( $want, $qs );
			}
			return;
		}
		$obj = null;
		$type = 'post';
		if ( is_singular() ) {
			$o = get_queried_object();
			$obj = $o instanceof \WP_Post ? $o : null;
		} elseif ( is_home() && ! is_front_page() && get_option( 'page_for_posts' ) ) {
			$obj = get_post( (int) get_option( 'page_for_posts' ) );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$o = get_queried_object();
			$obj = $o instanceof \WP_Term ? $o : null;
			$type = 'term';
		} elseif ( function_exists( 'is_shop' ) && function_exists( 'wc_get_page_id' ) && is_shop() ) {
			$obj = get_post( (int) wc_get_page_id( 'shop' ) );
		}
		if ( ! $obj || is_feed() || is_embed() || is_trackback() || is_attachment() || get_query_var( 'cpage' ) ) {
			return;
		}
		$id = 'term' === $type ? (int) $obj->term_id : (int) $obj->ID;
		$uri = self::meta( $type, $id, self::META );
		if ( '' === $uri || ( 'term' === $type && self::category_page( $obj ) ) || ! self::live( $type, $id ) ) {
			return;
		}
		$own = self::lookup_uri( $uri );
		if ( ! $own || $own['object_type'] !== $type || (int) $own['object_id'] !== $id ) {
			return; // Its URI would not lead back here (shared): leave the native URL alone.
		}
		$paged = (int) get_query_var( 'paged' );
		$page = (int) get_query_var( 'page' );
		$suffix = $paged > 1 ? ( $GLOBALS['wp_rewrite']->pagination_base ?? 'page' ) . '/' . $paged : ( $page > 1 && is_singular() ? (string) $page : '' );
		$want = self::build_url( trim( $uri . '/' . $suffix, '/' ) );
		if ( rawurldecode( $req ) === rawurldecode( (string) wp_parse_url( $want, PHP_URL_PATH ) ) ) {
			return;
		}
		$drop = [ 'p', 'page_id', 'name', 'pagename', 'post_type', 'cat', 'tag', 'taxonomy', 'term', 'paged', 'page', 'attachment_id' ];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( $tax->query_var ) {
				$drop[] = $tax->query_var;
			}
		}
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pto ) {
			if ( $pto->query_var ) {
				$drop[] = $pto->query_var;
			}
		}
		parse_str( $qs, $q );
		$q = array_diff_key( (array) $q, array_flip( $drop ) );
		self::go( $want, $q ? http_build_query( $q ) : '' );
	}

	private static function go( string $url, string $qs ): void {
		if ( '' !== $qs ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $qs;
		}
		if ( wp_safe_redirect( $url, 301, 'Launch Builder' ) ) {
			exit;
		}
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		$types = [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Post types and/or taxonomies (e.g. ["lb_service", "lb_service_cat"]); empty = all.' ];
		Abilities::add( 'get-permalinks', 'List URLs', 'Every public post and term with its URL path and where the path comes from (custom = set by hand, auto = from the permastructure of its type, native = WordPress). Filter by type (post type or taxonomy), source, search (part of the path) or duplicates_only. Paginated: offset, limit <= 200. The first page also returns the settings and permastructures.',
			[ 'properties' => [ 'type' => [ 'type' => 'string' ], 'source' => [ 'type' => 'string', 'enum' => [ 'custom', 'auto', 'native' ] ], 'search' => [ 'type' => 'string' ], 'duplicates_only' => [ 'type' => 'boolean' ], 'offset' => [ 'type' => 'integer' ], 'limit' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'tool_get' ], 'edit_pages', $ro );
		Abilities::add( 'set-uri', 'Set the URL of a page, entry or term', 'Give one object (post:ID, term:ID, {post_type}:{slug}, {taxonomy}:{slug} or its current URL) its own URL path, e.g. uri "plumbing/drain-cleaning". The old URL gets a 301 (Rank Math Redirections or our table). uri "" or "auto" goes back to the automatic URL (permastructure, else WordPress). Refuses reserved paths and paths another object uses (force: true shares it; the audit flags it). With slug sync on, the slug follows the last segment. dry_run shows the result.',
			[ 'properties' => [ 'object' => [ 'type' => 'string' ], 'uri' => [ 'type' => 'string' ], 'force' => [ 'type' => 'boolean' ], 'dry_run' => [ 'type' => 'boolean' ] ], 'required' => [ 'object', 'uri' ] ], [ __CLASS__, 'tool_set_uri' ], 'edit_posts' );
		Abilities::add( 'set-permastructure', 'Set the URL pattern of a post type or taxonomy', 'type = a post type (lb_service, lb_area, page, post, product...) or taxonomy (lb_service_cat, category...). structure uses tags: posts %postname% (required, or %post_id%), %parent% (ancestor slugs), %parent_uri% (the parent\'s full URL path), %lb_area_parent% (direct parent slug), %post_type%, %year%, %monthnum%, %day%, %author%, %{taxonomy}% (primary term with its parents, e.g. %lb_service_cat%); terms %term% (or %slug%, %term_id%), %parent%, %parent_uri%, %taxonomy%, %{taxonomy}% (term path incl. parents); plus static text. Examples: services "/%lb_service_cat%/%postname%/", areas "/service-areas/%parent%/%postname%/". structure "" = WordPress default. Existing objects are regenerated (first 200 now, next_cursor for regenerate-uris) and their old URLs 301. dry_run previews.',
			[ 'properties' => [ 'type' => [ 'type' => 'string' ], 'structure' => [ 'type' => 'string' ], 'regenerate' => [ 'type' => 'boolean' ], 'dry_run' => [ 'type' => 'boolean' ] ], 'required' => [ 'type', 'structure' ] ], [ __CLASS__, 'tool_set_structure' ] );
		Abilities::add( 'regenerate-uris', 'Rebuild URLs from the permastructures', 'Recompute the automatic URL of every object of the given types (all types when empty) from their permastructures; custom URLs are kept unless include_custom. dry_run (DEFAULT true) returns the diff [{object, title, old, new}] without writing; dry_run false writes and adds 301s. Paged: pass next_cursor back as cursor; limit <= 200.',
			[ 'properties' => [ 'types' => $types, 'include_custom' => [ 'type' => 'boolean' ], 'dry_run' => [ 'type' => 'boolean' ], 'cursor' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'tool_regenerate' ] );
		Abilities::add( 'find-replace-uris', 'Find and replace in URLs', 'Replace text in URL paths (e.g. find "service-areas/" replace "areas/"), optionally as a regex (regex: true; PCRE without delimiters). Changed URLs become custom URLs and their old URLs 301; paths that would collide with another object are skipped and listed. types limits it to post types / taxonomies; include_native false leaves WordPress-default URLs alone. dry_run (DEFAULT true) returns the diff. Paged with cursor / next_cursor.',
			[ 'properties' => [ 'find' => [ 'type' => 'string' ], 'replace' => [ 'type' => 'string' ], 'regex' => [ 'type' => 'boolean' ], 'types' => $types, 'include_native' => [ 'type' => 'boolean' ], 'dry_run' => [ 'type' => 'boolean' ], 'cursor' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer' ] ], 'required' => [ 'find', 'replace' ] ], [ __CLASS__, 'tool_find_replace' ] );
		Abilities::add( 'import-url-map', 'Map old URLs to this site (301s)', 'Old site migration: give pairs [{from, to?}] and/or old_sitemap_url (a public XML sitemap or sitemap index) or sitemap_xml (the XML itself). Each old URL (any host; its path counts) that does not exist here is mapped to an object: the given to (post:ID, term:ID, path or URL), else the object with exactly the same slug, else the best title match (score >= min_score, default 0.5). dry_run (DEFAULT true) returns mapped / unmatched / already_live; dry_run false creates the 301s (code) in the active redirect store. offset/limit (<= 500) page through long lists.',
			[ 'properties' => [ 'pairs' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ], 'old_sitemap_url' => [ 'type' => 'string' ], 'sitemap_xml' => [ 'type' => 'string' ], 'dry_run' => [ 'type' => 'boolean' ], 'code' => [ 'type' => 'integer', 'enum' => [ 301, 302, 307, 308 ] ], 'min_score' => [ 'type' => 'number' ], 'offset' => [ 'type' => 'integer' ], 'limit' => [ 'type' => 'integer' ] ] ], [ __CLASS__, 'tool_import' ] );
		Abilities::add( 'set-permalink-settings', 'URL settings', 'trailing_slash keep (follow the WordPress structure) | always | never; lowercase, transliterate (accents to ASCII), stop_words (+ stop_word_list, comma separated) apply to new and regenerated URLs; slug_sync keeps the native slug equal to the last segment of a custom URL; auto_redirect (301 on every URL change); redirect_store auto (Rank Math Redirections when its module is on) | own | rankmath. Snapshotted (restore-snapshot undoes it).',
			[ 'properties' => [ 'trailing_slash' => [ 'type' => 'string', 'enum' => [ 'keep', 'always', 'never' ] ], 'lowercase' => [ 'type' => 'boolean' ], 'transliterate' => [ 'type' => 'boolean' ], 'stop_words' => [ 'type' => 'boolean' ], 'stop_word_list' => [ 'type' => 'string' ], 'slug_sync' => [ 'type' => 'boolean' ], 'auto_redirect' => [ 'type' => 'boolean' ], 'redirect_store' => [ 'type' => 'string', 'enum' => [ 'auto', 'own', 'rankmath' ] ] ] ], [ __CLASS__, 'tool_settings' ] );
	}

	/** Settings + structures in tool form. */
	private static function settings_out(): array {
		$s = self::settings();
		$s['redirect_store_active'] = Redirects::store();
		$s['active'] = self::active();
		return $s;
	}

	public static function tool_get( $input ) {
		global $wpdb;
		$in = (array) $input;
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_install', 'The URL tables are not installed yet (open wp-admin once or run the migrations).', [ 'status' => 503 ] );
		}
		$limit = max( 1, min( 200, (int) ( $in['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $in['offset'] ?? 0 ) );
		$t = self::table();
		$where = [ '1=1' ];
		$args = [];
		if ( ! empty( $in['type'] ) ) {
			$where[] = 'subtype = %s';
			$args[] = (string) $in['type'];
		}
		if ( ! empty( $in['source'] ) && in_array( $in['source'], [ 'custom', 'auto', 'native' ], true ) ) {
			$where[] = 'source = %s';
			$args[] = (string) $in['source'];
		}
		if ( isset( $in['search'] ) && '' !== trim( (string) $in['search'] ) ) {
			$where[] = 'uri LIKE %s';
			$args[] = '%' . $wpdb->esc_like( self::clean_path( (string) $in['search'] ) ) . '%';
		}
		if ( ! empty( $in['duplicates_only'] ) ) {
			$where[] = "uri_hash IN (SELECT uri_hash FROM {$t} GROUP BY uri_hash HAVING COUNT(*) > 1)";
		}
		$w = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $args ) : "SELECT COUNT(*) FROM {$t} WHERE {$w}" ); // phpcs:ignore WordPress.DB
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY uri ASC, id ASC LIMIT %d OFFSET %d", array_merge( $args, [ $limit, $offset ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$dups = [];
		if ( $rows ) {
			$hs = array_values( array_unique( array_column( $rows, 'uri_hash' ) ) );
			$in2 = implode( ',', array_fill( 0, count( $hs ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT uri_hash, COUNT(*) AS n FROM {$t} WHERE uri_hash IN ({$in2}) GROUP BY uri_hash", $hs ), ARRAY_A ) as $d ) { // phpcs:ignore WordPress.DB
				$dups[ $d['uri_hash'] ] = (int) $d['n'];
			}
			_prime_post_caches( array_map( 'intval', array_column( array_filter( $rows, static function ( $r ) { return 'post' === $r['object_type']; } ), 'object_id' ) ), false, false );
		}
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = self::describe( (string) $r['object_type'], (int) $r['object_id'] ) + [ 'uri' => '/' . $r['uri'], 'url' => self::build_url( (string) $r['uri'] ), 'source' => (string) $r['source'], 'duplicate' => ( $dups[ $r['uri_hash'] ] ?? 1 ) > 1 ];
		}
		$res = [ 'total' => $total, 'offset' => $offset, 'count' => count( $out ), 'next_offset' => $offset + count( $out ) < $total ? $offset + count( $out ) : null, 'urls' => $out ];
		if ( 0 === $offset ) {
			$res['settings'] = self::settings_out();
			$res['counts'] = [];
			foreach ( (array) $wpdb->get_results( "SELECT source, COUNT(*) AS n FROM {$t} GROUP BY source", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
				$res['counts'][ $c['source'] ] = (int) $c['n'];
			}
		}
		return $res;
	}

	public static function tool_set_uri( $input ) {
		$in = (array) $input;
		$o = self::parse_object( $in['object'] ?? '' );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		if ( ! self::can_edit( $o ) ) {
			return new \WP_Error( 'lb_forbidden', 'You cannot edit that object.', [ 'status' => 403 ] );
		}
		if ( ! empty( $in['force'] ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'lb_forbidden', 'force (sharing a URL with another object or removing a redirect) needs an administrator.', [ 'status' => 403 ] );
		}
		$r = self::set_uri( $o['type'], $o['id'], isset( $in['uri'] ) ? (string) $in['uri'] : null, [ 'force' => ! empty( $in['force'] ), 'dry_run' => ! empty( $in['dry_run'] ) ] );
		if ( ! is_wp_error( $r ) && empty( $r['dry_run'] ) ) {
			Nav::flush( true );
		}
		return $r;
	}

	/**
	 * Check a structure string for a post type / taxonomy.
	 *
	 * @return string|\WP_Error normalised structure ('' = WordPress default)
	 */
	public static function validate_structure( string $kind, string $name, string $structure ) {
		$s = trim( $structure );
		if ( '' === $s ) {
			return '';
		}
		preg_match_all( '/%([a-z0-9_-]+)%/', $s, $m );
		$allowed = self::tags( $kind, $name );
		$bad = array_values( array_diff( $m[1], $allowed ) );
		if ( $bad ) {
			return new \WP_Error( 'lb_structure_tags', 'Unknown tags: %' . implode( '%, %', $bad ) . '%. Allowed for ' . $name . ': %' . implode( '%, %', $allowed ) . '%.', [ 'status' => 400 ] );
		}
		$need = 'term' === $kind ? [ 'term', 'slug', 'term_id', $name ] : [ 'postname', 'post_id' ];
		if ( ! array_intersect( $need, $m[1] ) ) {
			return new \WP_Error( 'lb_structure_slug', 'The structure needs one of %' . implode( '%, %', $need ) . '% so every object gets its own URL.', [ 'status' => 400 ] );
		}
		$static = (string) preg_replace( '/%[a-z0-9_-]+%/', '', $s );
		if ( preg_match( '/[^\p{L}\p{N}\-_.~\/]/u', $static ) ) {
			return new \WP_Error( 'lb_structure_chars', 'Static text may only use letters, digits, - _ . ~ and /.', [ 'status' => 400 ] );
		}
		$norm = '/' . trim( (string) preg_replace( '#/{2,}#', '/', $s ), '/' ) . '/';
		$first = strtok( trim( $norm, '/' ), '/' );
		if ( false !== $first && false === strpos( $first, '%' ) && self::reserved( $first ) ) {
			return new \WP_Error( 'lb_structure_reserved', 'The structure starts with a reserved path (' . $first . ').', [ 'status' => 400 ] );
		}
		return $norm;
	}

	public static function tool_set_structure( $input ) {
		$in = (array) $input;
		$name = sanitize_key( (string) ( $in['type'] ?? '' ) );
		$kind = self::managed_post_type( $name ) ? 'post' : ( self::managed_taxonomy( $name ) ? 'term' : '' );
		if ( '' === $kind ) {
			return new \WP_Error( 'lb_structure_type', 'type must be a public post type (' . implode( ', ', self::post_types() ) . ') or taxonomy (' . implode( ', ', self::taxonomies() ) . ').', [ 'status' => 400 ] );
		}
		$st = self::validate_structure( $kind, $name, (string) ( $in['structure'] ?? '' ) );
		if ( is_wp_error( $st ) ) {
			return $st;
		}
		$s = self::settings();
		$group = 'term' === $kind ? 'taxonomies' : 'post_types';
		$prev = (string) ( $s['permastructures'][ $group ][ $name ] ?? '' );
		$ps = $s['permastructures'];
		if ( '' === $st ) {
			unset( $ps[ $group ][ $name ] );
		} else {
			$ps[ $group ][ $name ] = $st;
		}
		$out = [ 'type' => $name, 'kind' => 'term' === $kind ? 'taxonomy' : 'post_type', 'structure' => $st, 'previous' => $prev, 'dry_run' => ! empty( $in['dry_run'] ) ];
		if ( ! empty( $in['dry_run'] ) ) {
			self::$override = $ps;
			try {
				$out['preview'] = self::regenerate( [ $name ], true, false, '', 50 );
			} finally {
				self::$override = null;
			}
			return $out;
		}
		$out['snapshot'] = Abilities::snapshot( 'set-permastructure ' . $name, [], [ self::OPT ] );
		$s['permastructures'] = $ps;
		self::save_settings( $s );
		if ( ! isset( $in['regenerate'] ) || ! empty( $in['regenerate'] ) ) {
			$out['regenerated'] = self::regenerate( [ $name ], false, false, '', 200 );
			Nav::flush( true );
		}
		return $out;
	}

	public static function tool_regenerate( $input ) {
		$in = (array) $input;
		$dry = ! isset( $in['dry_run'] ) || ! empty( $in['dry_run'] );
		$r = self::regenerate( (array) ( $in['types'] ?? [] ), $dry, ! empty( $in['include_custom'] ), (string) ( $in['cursor'] ?? '' ), (int) ( $in['limit'] ?? 100 ) );
		if ( ! is_wp_error( $r ) && ! $dry ) {
			Nav::flush( true );
		}
		return $r;
	}

	/** Normalise a types list: [ [kind, name], ... ] (all managed types when empty). */
	private static function type_list( array $types ) {
		$out = [];
		$types = array_filter( array_map( 'sanitize_key', array_map( 'strval', $types ) ) );
		if ( ! $types ) {
			$types = array_merge( self::post_types(), self::taxonomies() );
		}
		foreach ( $types as $n ) {
			if ( self::managed_post_type( $n ) ) {
				$out[] = [ 'post', $n ];
			} elseif ( self::managed_taxonomy( $n ) ) {
				$out[] = [ 'term', $n ];
			} else {
				return new \WP_Error( 'lb_types', 'Unknown or private type: ' . $n, [ 'status' => 400 ] );
			}
		}
		return $out;
	}

	/** Ids of a type after $after (published posts / terms), ascending. */
	private static function ids_of( string $kind, string $name, int $after, int $limit ): array {
		global $wpdb;
		if ( 'term' === $kind ) {
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id > %d ORDER BY term_id ASC LIMIT %d", $name, $after, $limit ) ) ); // phpcs:ignore WordPress.DB
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $name, $after, $limit ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Recompute automatic URIs (see regenerate-uris).
	 *
	 * @return array|\WP_Error { dry_run, changes[], unchanged, skipped_custom, next_cursor }
	 */
	public static function regenerate( array $types, bool $dry, bool $include_custom, string $cursor, int $limit ) {
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_install', 'The URL tables are not installed yet.', [ 'status' => 503 ] );
		}
		$list = self::type_list( $types );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$limit = max( 1, min( 200, $limit ) );
		[ $ti, $after ] = array_map( 'intval', array_pad( explode( ':', $cursor ), 2, 0 ) );
		$changes = [];
		$unchanged = 0;
		$skipped = 0;
		$seen = 0;
		$next = null;
		for ( ; $ti < count( $list ); $ti++, $after = 0 ) {
			[ $kind, $name ] = $list[ $ti ];
			$ids = self::ids_of( $kind, $name, $after, $limit - $seen + 1 );
			foreach ( $ids as $id ) {
				if ( $seen >= $limit ) {
					$next = $ti . ':' . $after;
					break 2;
				}
				$seen++;
				$after = $id;
				$custom = 'custom' === self::meta( $kind, $id, self::META_MODE );
				if ( $custom && ! $include_custom ) {
					$skipped++;
					continue;
				}
				$row = self::row( $kind, $id );
				$old = $row ? (string) $row['uri'] : (string) self::current_uri( $kind, $id );
				$gen = self::generate( $kind, $id );
				$new = '' !== $gen ? self::unique( $gen, $kind, $id ) : (string) self::native_path( $kind, $id );
				if ( $custom && '' === $gen ) { // A custom URI going back to native: the native path is what WordPress gives.
					$new = (string) self::native_path( $kind, $id );
				}
				if ( self::key( $old ) === self::key( $new ) && ( ! $row || $row['uri'] === $new ) && ! $custom ) {
					$unchanged++;
					continue;
				}
				$change = self::describe( $kind, $id ) + [ 'old' => '/' . $old, 'new' => '/' . $new ];
				if ( ! $dry ) {
					if ( $custom ) {
						self::delete_meta( $kind, $id, self::META_MODE );
					}
					$rep = self::refresh( $kind, $id );
					$change['new'] = '/' . (string) ( $rep['new'] ?? $new );
					if ( ! empty( $rep['redirect']['redirect'] ) ) {
						$change['redirect'] = $rep['redirect']['redirect'];
					}
				}
				$changes[] = $change;
			}
		}
		return [ 'dry_run' => $dry, 'changes' => $changes, 'changed' => count( $changes ), 'unchanged' => $unchanged, 'skipped_custom' => $skipped, 'next_cursor' => $next ];
	}

	public static function tool_find_replace( $input ) {
		global $wpdb;
		$in = (array) $input;
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_install', 'The URL tables are not installed yet.', [ 'status' => 503 ] );
		}
		$find = (string) ( $in['find'] ?? '' );
		$repl = (string) ( $in['replace'] ?? '' );
		$regex = ! empty( $in['regex'] );
		$dry = ! isset( $in['dry_run'] ) || ! empty( $in['dry_run'] );
		$native = ! isset( $in['include_native'] ) || ! empty( $in['include_native'] );
		if ( '' === $find ) {
			return new \WP_Error( 'lb_find', 'find is required.', [ 'status' => 400 ] );
		}
		$pattern = '';
		if ( $regex ) {
			$pattern = '#' . str_replace( '#', '\#', $find ) . '#u';
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'lb_regex', 'find is not a valid regular expression.', [ 'status' => 400 ] );
			}
		}
		$types = self::type_list( (array) ( $in['types'] ?? [] ) );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$names = array_column( $types, 1 );
		$limit = max( 1, min( 200, (int) ( $in['limit'] ?? 100 ) ) );
		$after = (int) ( $in['cursor'] ?? 0 );
		$t = self::table();
		$where = 'id > %d';
		$args = [ $after ];
		$where .= ' AND subtype IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')';
		$args = array_merge( $args, $names );
		if ( ! $native ) {
			$where .= " AND source != 'native'";
		}
		if ( ! $regex ) {
			$where .= ' AND uri LIKE %s';
			$args[] = '%' . $wpdb->esc_like( trim( self::clean_path( $find ) ?: $find ) ) . '%';
		}
		$args[] = $limit;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id ASC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$changes = [];
		$conflicts = [];
		foreach ( $rows as $r ) {
			$uri = (string) $r['uri'];
			$new = $regex ? (string) preg_replace( $pattern, $repl, $uri ) : str_replace( $find, $repl, $uri );
			$new = self::sanitize_uri( $new );
			if ( $new === $uri || '' === $new ) {
				continue;
			}
			$type = (string) $r['object_type'];
			$id = (int) $r['object_id'];
			$res = self::set_uri( $type, $id, $new, [ 'dry_run' => $dry ] );
			if ( is_wp_error( $res ) ) {
				$conflicts[] = self::describe( $type, $id ) + [ 'old' => '/' . $uri, 'new' => '/' . $new, 'error' => $res->get_error_message() ];
				continue;
			}
			$changes[] = self::describe( $type, $id ) + [ 'old' => '/' . $uri, 'new' => (string) $res['new'] ] + ( empty( $res['redirect']['redirect'] ) ? [] : [ 'redirect' => $res['redirect']['redirect'] ] );
		}
		$last = $rows ? end( $rows ) : null;
		if ( ! $dry && $changes ) {
			Nav::flush( true );
		}
		return [ 'dry_run' => $dry, 'changes' => $changes, 'changed' => count( $changes ), 'skipped' => $conflicts, 'next_cursor' => count( $rows ) === $limit && $last ? (string) $last['id'] : null ];
	}

	public static function tool_settings( $input ) {
		$in = (array) $input;
		$s = self::settings();
		$before = $s;
		if ( isset( $in['trailing_slash'] ) && in_array( $in['trailing_slash'], [ 'keep', 'always', 'never' ], true ) ) {
			$s['trailing_slash'] = $in['trailing_slash'];
		}
		foreach ( [ 'lowercase', 'transliterate', 'stop_words', 'slug_sync', 'auto_redirect' ] as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( isset( $in['stop_word_list'] ) ) {
			$s['stop_word_list'] = implode( ',', array_filter( array_map( 'sanitize_title', explode( ',', (string) $in['stop_word_list'] ) ) ) );
		}
		if ( isset( $in['redirect_store'] ) && in_array( $in['redirect_store'], [ 'auto', 'own', 'rankmath' ], true ) ) {
			$s['redirect_store'] = $in['redirect_store'];
		}
		$snap = Abilities::snapshot( 'set-permalink-settings', [], [ self::OPT ] );
		self::save_settings( $s );
		$changed = array_keys( array_filter( array_intersect_key( $s, self::DEFAULTS ), static function ( $v, $k ) use ( $before ) { return 'permastructures' !== $k && $before[ $k ] !== $v; }, ARRAY_FILTER_USE_BOTH ) );
		$out = [ 'settings' => self::settings_out(), 'changed' => $changed, 'snapshot' => $snap ];
		if ( array_intersect( $changed, [ 'lowercase', 'transliterate', 'stop_words', 'stop_word_list' ] ) ) {
			$out['note'] = 'Existing automatic URLs keep their form until regenerate-uris { dry_run: false } rebuilds them.';
		}
		if ( in_array( 'trailing_slash', $changed, true ) ) {
			Cache::purge_later();
		}
		return $out;
	}

	/* ------------------------------------------------------------------ import-url-map */

	/** Paths of an old site's sitemap (index followed, max 25 child sitemaps, 5000 URLs). */
	private static function sitemap_urls( string $xml, bool $follow, array &$errors, int $depth = 0 ): array {
		$out = [];
		if ( "\x1f\x8b" === substr( $xml, 0, 2 ) && function_exists( 'gzdecode' ) ) {
			$xml = (string) gzdecode( $xml );
		}
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $xml, $m );
		$locs = array_map( static function ( $l ) { return trim( html_entity_decode( (string) $l, ENT_QUOTES | ENT_XML1, 'UTF-8' ) ); }, $m[1] );
		if ( false !== stripos( $xml, '<sitemapindex' ) ) {
			if ( ! $follow || $depth > 0 ) {
				$errors[] = 'A sitemap index needs old_sitemap_url (its child sitemaps are fetched).';
				return [];
			}
			foreach ( array_slice( $locs, 0, 25 ) as $child ) {
				$body = self::fetch( $child, $errors );
				if ( null !== $body ) {
					$out = array_merge( $out, self::sitemap_urls( $body, false, $errors, $depth + 1 ) );
				}
			}
			return array_slice( $out, 0, 5000 );
		}
		return array_slice( $locs, 0, 5000 );
	}

	/** GET a public URL (wp_safe_remote_get: no local or private hosts). */
	private static function fetch( string $url, array &$errors ): ?string {
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			$errors[] = 'Not a public http(s) URL: ' . $url;
			return null;
		}
		$r = wp_safe_remote_get( $url, [ 'timeout' => 15, 'redirection' => 3, 'limit_response_size' => 10 * MB_IN_BYTES ] );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			$errors[] = 'Could not fetch ' . $url . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : ' (HTTP ' . (int) wp_remote_retrieve_response_code( $r ) . ')' );
			return null;
		}
		return (string) wp_remote_retrieve_body( $r );
	}

	/** Word overlap (Dice) of two slugs, 0..1. */
	private static function similarity( string $a, string $b ): float {
		$stop = explode( ',', self::STOP_WORDS );
		$wa = array_values( array_unique( array_diff( array_filter( explode( '-', $a ) ), $stop ) ) );
		$wb = array_values( array_unique( array_diff( array_filter( explode( '-', $b ) ), $stop ) ) );
		if ( ! $wa || ! $wb ) {
			return 0.0;
		}
		$common = count( array_intersect( $wa, $wb ) );
		$dice = 2 * $common / ( count( $wa ) + count( $wb ) );
		similar_text( implode( ' ', $wa ), implode( ' ', $wb ), $pct );
		return round( max( $dice, $pct / 100 * 0.9 ), 3 );
	}

	public static function tool_import( $input ) {
		global $wpdb;
		$in = (array) $input;
		if ( ! self::installed() ) {
			return new \WP_Error( 'lb_permalinks_install', 'The URL tables are not installed yet.', [ 'status' => 503 ] );
		}
		$dry = ! isset( $in['dry_run'] ) || ! empty( $in['dry_run'] );
		$code = in_array( (int) ( $in['code'] ?? 301 ), [ 301, 302, 307, 308 ], true ) ? (int) ( $in['code'] ?? 301 ) : 301;
		$min = isset( $in['min_score'] ) ? max( 0.0, min( 1.0, (float) $in['min_score'] ) ) : 0.5;
		$errors = [];
		$list = [];
		foreach ( (array) ( $in['pairs'] ?? [] ) as $p ) {
			if ( is_string( $p ) ) {
				$list[] = [ $p, '' ];
			} elseif ( is_array( $p ) ) {
				$list[] = [ (string) ( $p['from'] ?? $p['old'] ?? '' ), (string) ( $p['to'] ?? $p['new'] ?? '' ) ];
			}
		}
		if ( ! empty( $in['sitemap_xml'] ) ) {
			foreach ( self::sitemap_urls( (string) $in['sitemap_xml'], true, $errors ) as $u ) {
				$list[] = [ $u, '' ];
			}
		}
		if ( ! empty( $in['old_sitemap_url'] ) ) {
			$body = self::fetch( (string) $in['old_sitemap_url'], $errors );
			foreach ( null === $body ? [] : self::sitemap_urls( $body, true, $errors ) as $u ) {
				$list[] = [ $u, '' ];
			}
		}
		$seen = [];
		$uniq = [];
		foreach ( $list as [ $from, $to ] ) {
			$path = self::clean_path( (string) ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $from ) ? wp_parse_url( $from, PHP_URL_PATH ) : $from ) );
			if ( '' === $path || isset( $seen[ self::key( $path ) ] ) ) {
				continue;
			}
			$seen[ self::key( $path ) ] = true;
			$uniq[] = [ $path, $to ];
		}
		$offset = max( 0, (int) ( $in['offset'] ?? 0 ) );
		$limit = max( 1, min( 500, (int) ( $in['limit'] ?? 500 ) ) );
		$total = count( $uniq );
		$uniq = array_slice( $uniq, $offset, $limit );
		// Candidates: every live object, by slug (last segment of its URI and its native slug).
		$t = self::table();
		$rows = (array) $wpdb->get_results( "SELECT object_type, object_id, uri, subtype FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_slug = [];
		foreach ( $rows as $r ) {
			$segs = explode( '/', (string) $r['uri'] );
			$by_slug[ self::key( (string) end( $segs ) ) ][] = $r;
		}
		$titles = null;
		$mapped = [];
		$live = [];
		$unmatched = [];
		foreach ( $uniq as [ $path, $to ] ) {
			$here = self::lookup_uri( $path );
			if ( $here && '' === $to ) {
				$live[] = [ 'from' => '/' . $path, 'object' => self::ref( (string) $here['object_type'], (int) $here['object_id'] ) ];
				continue;
			}
			$target = null;
			$method = '';
			$score = 1.0;
			if ( '' !== $to ) {
				$parsed = Redirects::parse_target( $to );
				if ( in_array( $parsed['type'], [ 'object', 'url' ], true ) ) {
					$target = $parsed;
					$method = 'given';
				}
			}
			$segs = explode( '/', $path );
			$slug = sanitize_title( (string) preg_replace( '/\.(html?|php|aspx?)$/i', '', (string) end( $segs ) ) );
			if ( ! $target && '' !== $slug && ! empty( $by_slug[ self::key( $slug ) ] ) ) {
				$cands = $by_slug[ self::key( $slug ) ];
				usort( $cands, static function ( $a, $b ) use ( $path ) { return similar_text( $path, (string) $b['uri'] ) <=> similar_text( $path, (string) $a['uri'] ); } );
				$target = [ 'type' => 'object', 'object_type' => (string) $cands[0]['object_type'], 'object_id' => (int) $cands[0]['object_id'], 'url' => '', 'path' => '' ];
				$method = 'slug';
			}
			if ( ! $target && '' !== $slug ) {
				if ( null === $titles ) {
					$titles = self::titles( $rows );
				}
				$best = null;
				foreach ( $titles as $k => $title_slug ) {
					$sc = self::similarity( $slug, $title_slug );
					if ( ! $best || $sc > $best[1] ) {
						$best = [ $k, $sc ];
					}
				}
				if ( $best && $best[1] >= $min ) {
					[ $ot, $oid ] = explode( ':', $best[0] );
					$target = [ 'type' => 'object', 'object_type' => $ot, 'object_id' => (int) $oid, 'url' => '', 'path' => '' ];
					$method = 'title';
					$score = $best[1];
				}
			}
			if ( ! $target ) {
				$unmatched[] = '/' . $path;
				continue;
			}
			$row = [ 'from' => '/' . $path, 'to' => Redirects::describe( $target ), 'url' => Redirects::target_url( $target ), 'method' => $method, 'score' => $score ];
			if ( ! $dry ) {
				$r = Redirects::add( $path, 'object' === $target['type'] ? [ 'object_type' => $target['object_type'], 'object_id' => $target['object_id'] ] : ( null === $target['path'] ? $target['url'] : '/' . $target['path'] ), $code, 'import' );
				if ( is_wp_error( $r ) ) {
					$row['error'] = $r->get_error_message();
				} else {
					$row['redirect_id'] = $r['id'];
					$row['store'] = $r['store'];
				}
			}
			$mapped[] = $row;
		}
		if ( ! $dry && $mapped ) {
			Cache::purge_later();
		}
		$next = $offset + count( $uniq ) < $total ? $offset + count( $uniq ) : null;
		return [ 'dry_run' => $dry, 'total' => $total, 'offset' => $offset, 'next_offset' => $next, 'mapped' => $mapped, 'already_live' => $live, 'unmatched' => $unmatched, 'errors' => $errors, 'store' => Redirects::store() ];
	}

	/** object ref => sanitize_title(title) for every row. */
	private static function titles( array $rows ): array {
		global $wpdb;
		$out = [];
		$posts = array_map( 'intval', array_column( array_filter( $rows, static function ( $r ) { return 'post' === $r['object_type']; } ), 'object_id' ) );
		foreach ( array_chunk( $posts, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})", $chunk ), ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
				$out[ 'post:' . (int) $p['ID'] ] = sanitize_title( (string) $p['post_title'] );
			}
		}
		$terms = array_map( 'intval', array_column( array_filter( $rows, static function ( $r ) { return 'term' === $r['object_type']; } ), 'object_id' ) );
		foreach ( array_chunk( $terms, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ({$in})", $chunk ), ARRAY_A ) as $tm ) { // phpcs:ignore WordPress.DB
				$out[ 'term:' . (int) $tm['term_id'] ] = sanitize_title( (string) $tm['name'] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function site_state( array &$state ): void {
		global $wpdb;
		$counts = [];
		if ( self::installed() ) {
			$t = self::table();
			foreach ( (array) $wpdb->get_results( "SELECT source, COUNT(*) AS n FROM {$t} GROUP BY source", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
				$counts[ $c['source'] ] = (int) $c['n'];
			}
			$counts['duplicate_paths'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT uri_hash FROM {$t} GROUP BY uri_hash HAVING COUNT(*) > 1) d" ); // phpcs:ignore WordPress.DB
		}
		$s = self::settings();
		$state['permalinks'] = [ 'active' => self::active(), 'permastructures' => $s['permastructures'], 'trailing_slash' => $s['trailing_slash'], 'redirect_store' => Redirects::store(), 'counts' => $counts ];
	}

	public static function playbook( array &$pb ): void {
		$pb['urls'] = 'URLs: every page, entry and term can have its own URL path. Set a pattern per type with set-permastructure (services "/%lb_service_cat%/%postname%/" puts a service under its category, e.g. /plumbing/drain-cleaning/; areas "/service-areas/%parent%/%postname%/"), one object with set-uri, many with regenerate-uris / find-replace-uris (dry_run first; both default to dry runs). Every URL change leaves a 301 (Rank Math Redirections when its module is on, else our table) and chains are collapsed. Moving a site: import-url-map with the old sitemap maps old URLs to the new pages. get-permalinks lists URLs; audit check uri_duplicate flags two objects on one URL.';
		$pb['tools_by_job']['URLs and redirects'] = 'get-permalinks, set-uri, set-permastructure, regenerate-uris, find-replace-uris, import-url-map, set-permalink-settings, get-redirects, set-redirect, delete-redirect';
	}

	public static function brief_schema( array &$schema ): void {
		$schema['properties']['urls'] = [
			'type'        => 'object',
			'description' => 'URL structure and redirects (Permalinks module).',
			'properties'  => [
				'permastructures' => [ 'type' => 'object', 'description' => 'post type or taxonomy => structure, e.g. {"lb_service": "/%lb_service_cat%/%postname%/", "lb_area": "/service-areas/%parent%/%postname%/"}.', 'additionalProperties' => [ 'type' => 'string' ] ],
				'redirects'       => [ 'type' => 'array', 'description' => '[{from, to, code}] extra redirects (to = path, URL, post:ID or term:ID).', 'items' => [ 'type' => 'object' ] ],
				'old_sitemap_url' => [ 'type' => 'string', 'description' => 'The old site\'s XML sitemap: its URLs are mapped to the new pages with 301s (import-url-map).' ],
				'settings'        => [ 'type' => 'object', 'description' => 'set-permalink-settings values (trailing_slash, lowercase, ...).' ],
			],
		];
	}

	/** build-site: blueprint key urls {settings, permastructures, redirects, old_sitemap_url} applied at the end. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'urls', 'at' => 'done', 'run' => [ __CLASS__, 'blueprint_urls' ] ] ];
	}

	public static function blueprint_urls( array $bp, array $ctx ) {
		unset( $ctx );
		$u = isset( $bp['urls'] ) && is_array( $bp['urls'] ) ? $bp['urls'] : [];
		if ( ! array_intersect_key( $u, array_flip( [ 'settings', 'permastructures', 'redirects', 'old_sitemap_url' ] ) ) ) {
			return [ 'skipped' => 'no urls in the blueprint' ];
		}
		if ( ! self::installed() ) {
			self::install();
		}
		$out = [ 'warnings' => [] ];
		if ( ! empty( $u['settings'] ) && is_array( $u['settings'] ) ) {
			$out['settings'] = self::tool_settings( $u['settings'] )['changed'];
		}
		foreach ( (array) ( $u['permastructures'] ?? [] ) as $type => $st ) {
			$r = self::tool_set_structure( [ 'type' => (string) $type, 'structure' => (string) $st ] );
			if ( is_wp_error( $r ) ) {
				$out['warnings'][] = 'permastructure ' . $type . ': ' . $r->get_error_message();
			} else {
				$out['permastructures'][ $type ] = [ 'structure' => $r['structure'], 'changed' => $r['regenerated']['changed'] ?? 0, 'next_cursor' => $r['regenerated']['next_cursor'] ?? null ];
			}
		}
		foreach ( (array) ( $u['redirects'] ?? [] ) as $rd ) {
			$rd = (array) $rd;
			$r = Redirects::add( (string) ( $rd['from'] ?? '' ), (string) ( $rd['to'] ?? '' ), (int) ( $rd['code'] ?? 301 ), 'import' );
			if ( is_wp_error( $r ) ) {
				$out['warnings'][] = 'redirect ' . ( $rd['from'] ?? '' ) . ': ' . $r->get_error_message();
			}
		}
		if ( ! empty( $u['old_sitemap_url'] ) ) {
			$r = self::tool_import( [ 'old_sitemap_url' => (string) $u['old_sitemap_url'], 'dry_run' => false ] );
			$out['import'] = is_wp_error( $r ) ? $r->get_error_message() : [ 'mapped' => count( $r['mapped'] ), 'unmatched' => count( $r['unmatched'] ), 'next_offset' => $r['next_offset'] ];
			if ( ! is_wp_error( $r ) ) {
				$out['warnings'] = array_merge( $out['warnings'], $r['errors'] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ audit */

	/** Two or more objects on one URL. */
	public static function audit_duplicates( array $args ): array {
		global $wpdb;
		unset( $args );
		if ( ! self::installed() ) {
			return [];
		}
		$t = self::table();
		$rows = (array) $wpdb->get_results( "SELECT u.* FROM {$t} u INNER JOIN (SELECT uri_hash FROM {$t} GROUP BY uri_hash HAVING COUNT(*) > 1 LIMIT 100) d ON d.uri_hash = u.uri_hash ORDER BY u.uri_hash, u.id", ARRAY_A ); // phpcs:ignore WordPress.DB
		$groups = [];
		foreach ( $rows as $r ) {
			$groups[ $r['uri_hash'] ][] = $r;
		}
		$out = [];
		foreach ( $groups as $list ) {
			usort( $list, [ __CLASS__, 'rank' ] );
			$names = array_map( static function ( $r ) { $d = self::describe( (string) $r['object_type'], (int) $r['object_id'] ); return $d['object'] . ' "' . $d['title'] . '" (' . $d['type'] . ', ' . $r['source'] . ')'; }, $list );
			$winner = self::describe( (string) $list[0]['object_type'], (int) $list[0]['object_id'] );
			$out[] = [
				'severity'    => 'error',
				'code'        => 'uri_duplicate',
				'object_type' => (string) $list[1]['object_type'],
				'id'          => (int) $list[1]['object_id'],
				'url'         => self::build_url( (string) $list[0]['uri'] ),
				'message'     => '/' . $list[0]['uri'] . ' is used by ' . implode( ' and ', $names ) . '; visitors get ' . $winner['object'] . ' ("' . $winner['title'] . '").',
				'fix'         => 'set-uri { object: "' . self::ref( (string) $list[1]['object_type'], (int) $list[1]['object_id'] ) . '", uri: <another path> }',
			];
		}
		return $out;
	}

	/** URIs (custom or automatic) on reserved paths or a post type archive. Batched by row id. */
	public static function audit_reserved( array $args ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return [ 'findings' => [], 'next_after_id' => null ];
		}
		$t = self::table();
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d AND source != 'native' ORDER BY id ASC LIMIT %d", (int) ( $args['after_id'] ?? 0 ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out = [];
		foreach ( $rows as $r ) {
			if ( self::reserved( (string) $r['uri'] ) ) {
				$out[] = [ 'severity' => 'error', 'code' => 'uri_reserved', 'object_type' => (string) $r['object_type'], 'id' => (int) $r['object_id'], 'url' => self::build_url( (string) $r['uri'] ), 'message' => '/' . $r['uri'] . ' is reserved (WordPress, REST API, feeds, sitemaps or an archive): the object cannot be reached reliably.', 'fix' => 'set-uri { object: "' . self::ref( (string) $r['object_type'], (int) $r['object_id'] ) . '", uri: <another path> }' ];
			}
		}
		$last = $rows ? end( $rows ) : null;
		return [ 'findings' => $out, 'next_after_id' => count( $rows ) === $limit && $last ? (int) $last['id'] : null ];
	}

	public static function audit_index( array $args ): array {
		unset( $args );
		if ( ! self::installed() ) {
			return [ [ 'severity' => 'warning', 'code' => 'uri_index_pending', 'object_type' => 'site', 'id' => 0, 'url' => '', 'message' => 'The URL tables are not installed: custom URLs and redirects are off.', 'fix' => 'Open wp-admin as an administrator (migrations run) or wp launch-builder migrate.' ] ];
		}
		$out = [];
		if ( in_array( 'permalinks-index', Migrate::pending(), true ) ) {
			$out[] = [ 'severity' => 'warning', 'code' => 'uri_index_pending', 'object_type' => 'site', 'id' => 0, 'url' => '', 'message' => 'Not every page and term is in the URL index yet (migration permalinks-index pending): duplicate checks are incomplete.', 'fix' => 'Open wp-admin as an administrator or run wp launch-builder migrate.' ];
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = [ 'severity' => 'warning', 'code' => 'uri_index_pending', 'object_type' => 'site', 'id' => 0, 'url' => '', 'message' => 'Plain permalinks are on: custom URLs need pretty permalinks (Settings > Permalinks).', 'fix' => 'Choose a permalink structure such as /%postname%/.' ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ REST (editor panel) */

	public static function routes(): void {
		$obj = [ 'type' => 'string', 'required' => true ];
		register_rest_route( 'launch-builder/v1', '/uri', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_get' ], 'permission_callback' => [ __CLASS__, 'rest_can' ], 'args' => [ 'object' => $obj ] ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_set' ], 'permission_callback' => [ __CLASS__, 'rest_can' ], 'args' => [ 'object' => $obj, 'uri' => [ 'type' => 'string', 'default' => '' ], 'check' => [ 'type' => 'boolean', 'default' => false ] ] ],
		] );
	}

	public static function rest_can( \WP_REST_Request $r ): bool {
		$o = self::parse_object( (string) $r->get_param( 'object' ) );
		return ! is_wp_error( $o ) && self::can_edit( $o );
	}

	/** What the URL panel shows for an object. */
	public static function info( string $type, int $id ): array {
		[ $uri, $source ] = self::compute( $type, $id, false );
		$mode = 'custom' === self::meta( $type, $id, self::META_MODE ) && '' !== self::meta( $type, $id, self::META ) ? 'custom' : $source;
		$gen = self::generate( $type, $id );
		$auto = '' !== $gen ? self::unique( $gen, $type, $id ) : (string) self::native_path( $type, $id );
		$live = self::live( $type, $id );
		$name = 'term' === $type ? ( get_term( $id )->taxonomy ?? '' ) : (string) get_post_type( $id );
		return self::describe( $type, $id ) + [
			'uri'       => null === $uri ? '' : $uri,
			'url'       => $live ? self::live_url( $type, $id ) : ( null === $uri ? '' : self::build_url( $uri ) ),
			'mode'      => $mode,
			'auto'      => $auto,
			'live'      => $live,
			'structure' => self::structure( 'term' === $type ? 'term' : 'post', $name ),
			'slug'      => 'term' === $type ? (string) ( get_term( $id )->slug ?? '' ) : (string) get_post_field( 'post_name', $id ),
			'home'      => home_url( '/' ),
		];
	}

	public static function rest_get( \WP_REST_Request $r ) {
		$o = self::parse_object( (string) $r->get_param( 'object' ) );
		return is_wp_error( $o ) ? $o : rest_ensure_response( self::info( $o['type'], $o['id'] ) );
	}

	public static function rest_set( \WP_REST_Request $r ) {
		$o = self::parse_object( (string) $r->get_param( 'object' ) );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		$res = self::set_uri( $o['type'], $o['id'], (string) $r->get_param( 'uri' ), [ 'dry_run' => (bool) $r->get_param( 'check' ) ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$res['info'] = self::info( $o['type'], $o['id'] );
		return rest_ensure_response( $res );
	}

	/** Block editor: the "URL" panel in the document sidebar (assets/js/permalinks.js, plain wp.* globals). */
	public static function editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::installed() || ! self::managed_post_type( (string) $screen->post_type ) ) {
			return;
		}
		wp_enqueue_script( 'lb-permalinks', LB_URL . 'assets/js/permalinks.js', [ 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-editor', 'wp-edit-post' ], (string) @filemtime( LB_DIR . 'assets/js/permalinks.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-permalinks', 'window.lbPermalinks = ' . wp_json_encode( [
			'types' => self::post_types(),
			'i18n'  => [
				'title'   => __( 'URL', 'launch-builder' ),
				'label'   => __( 'URL path', 'launch-builder' ),
				'save'    => __( 'Save URL', 'launch-builder' ),
				'reset'   => __( 'Use automatic URL', 'launch-builder' ),
				'custom'  => __( 'Custom URL (kept when the slug or category changes).', 'launch-builder' ),
				'auto'    => __( 'Automatic URL from the URL pattern of this type.', 'launch-builder' ),
				'native'  => __( 'WordPress default URL.', 'launch-builder' ),
				'draft'   => __( 'Goes live when the post is published.', 'launch-builder' ),
				'saved'   => __( 'URL saved.', 'launch-builder' ),
				'moved'   => __( 'The old URL now redirects here.', 'launch-builder' ),
				'autoIs'  => __( 'Automatic:', 'launch-builder' ),
				'unsaved' => __( 'Save the post once to set its URL.', 'launch-builder' ),
			],
		] ) . ';', 'before' );
	}

	/* ------------------------------------------------------------------ term screens */

	public static function term_fields(): void {
		foreach ( self::taxonomies() as $tax ) {
			add_action( $tax . '_edit_form_fields', [ __CLASS__, 'term_field' ], 20, 2 );
		}
	}

	public static function term_field( $term, $taxonomy ): void {
		unset( $taxonomy );
		if ( ! $term instanceof \WP_Term || ! self::installed() || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		$info = self::info( 'term', (int) $term->term_id );
		wp_nonce_field( 'lb_term_uri_' . $term->term_id, 'lb_term_uri_nonce' );
		echo '<tr class="form-field lb-term-uri"><th scope="row"><label for="lb_uri">' . esc_html__( 'URL path', 'launch-builder' ) . '</label></th><td>';
		echo '<code>' . esc_html( home_url( '/' ) ) . '</code><input type="text" name="lb_uri" id="lb_uri" value="' . esc_attr( 'custom' === $info['mode'] ? $info['uri'] : '' ) . '" placeholder="' . esc_attr( $info['auto'] ) . '" style="max-width:420px">';
		echo '<input type="hidden" name="lb_uri_was" value="' . esc_attr( 'custom' === $info['mode'] ? $info['uri'] : '' ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave empty for the automatic URL (shown greyed out). A changed URL redirects the old one (301).', 'launch-builder' );
		if ( $info['url'] ) {
			echo ' ' . esc_html__( 'Now:', 'launch-builder' ) . ' <a href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $info['url'] ) . '</a>';
		}
		echo '</p></td></tr>';
	}

	private static function term_form_posted( int $term_id ): bool {
		return isset( $_POST['lb_term_uri_nonce'], $_POST['lb_uri'], $_POST['tag_ID'] ) && (int) $_POST['tag_ID'] === $term_id // phpcs:ignore WordPress.Security.NonceVerification
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lb_term_uri_nonce'] ) ), 'lb_term_uri_' . $term_id ) && current_user_can( 'edit_term', $term_id );
	}

	private static function term_form_save( int $term_id, bool $force_cascade ): void {
		if ( isset( self::$term_saved[ $term_id ] ) ) { // set_uri() may update the slug, which comes back through edited_term.
			self::refresh( 'term', $term_id, [ 'force_cascade' => $force_cascade ] );
			return;
		}
		self::$term_saved[ $term_id ] = true;
		$uri = trim( (string) wp_unslash( $_POST['lb_uri'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in term_form_posted; sanitize_uri() cleans it.
		$was = trim( (string) wp_unslash( $_POST['lb_uri_was'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( $uri === $was ) {
			self::refresh( 'term', $term_id, [ 'force_cascade' => $force_cascade ] );
			return;
		}
		$r = self::set_uri( 'term', $term_id, '' === $uri ? null : $uri );
		if ( is_wp_error( $r ) ) {
			set_transient( 'lb_uri_notice_' . get_current_user_id(), [ 'error', $r->get_error_message() ], 120 );
			self::refresh( 'term', $term_id, [ 'force_cascade' => $force_cascade ] );
		}
	}

	public static function admin_notice(): void {
		$key = 'lb_uri_notice_' . get_current_user_id();
		$n = get_transient( $key );
		if ( is_array( $n ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . ( 'error' === $n[0] ? 'error' : 'success' ) . ' is-dismissible"><p><strong>' . esc_html__( 'URL', 'launch-builder' ) . ':</strong> ' . esc_html( (string) $n[1] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ admin screen */

	public static function menu(): void {
		add_submenu_page( Admin::SLUG, __( 'URLs', 'launch-builder' ), __( 'URLs', 'launch-builder' ), 'manage_options', 'launch-builder-urls', [ __CLASS__, 'page' ] );
	}

	private static function page_url( string $tab = 'urls', array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'launch-builder-urls', 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'urls'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = [ 'urls' => __( 'URLs', 'launch-builder' ), 'redirects' => __( 'Redirects', 'launch-builder' ), 'bulk' => __( 'Bulk tools', 'launch-builder' ), 'settings' => __( 'Settings', 'launch-builder' ) ];
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'urls';
		echo '<div class="wrap lb-wrap lb-set lb-urls">';
		echo Dashboard::header( __( 'URLs', 'launch-builder' ), esc_html__( 'The address of every page, post and term, the redirects for old addresses, and the patterns that build new ones.', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		if ( ! self::installed() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The URL tables are being installed; reload this page.', 'launch-builder' ) . '</p></div>';
		}
		echo '<nav class="lb-tabnav" aria-label="' . esc_attr__( 'Sections', 'launch-builder' ) . '">';
		foreach ( $tabs as $k => $label ) {
			echo '<a href="' . esc_url( self::page_url( $k ) ) . '"' . ( $k === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		$res = get_transient( 'lb_urls_result_' . get_current_user_id() );
		if ( is_array( $res ) && ( $res['tab'] ?? '' ) === $tab ) {
			delete_transient( 'lb_urls_result_' . get_current_user_id() );
			self::print_result( $res );
		}
		switch ( $tab ) {
			case 'redirects':
				self::tab_redirects();
				break;
			case 'bulk':
				self::tab_bulk();
				break;
			case 'settings':
				self::tab_settings();
				break;
			default:
				self::tab_urls();
		}
		echo '</div>';
	}

	private static function print_result( array $res ): void {
		$data = $res['data'] ?? [];
		if ( isset( $res['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $res['error'] ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success inline"><p>' . esc_html( (string) ( $res['message'] ?? '' ) ) . '</p></div>';
		$changes = (array) ( $data['changes'] ?? [] );
		if ( $changes ) {
			echo '<section class="lb-card lb-card--table"><div class="lb-panel__head"><h2>' . ( ! empty( $data['dry_run'] ) ? esc_html__( 'Preview (nothing changed yet)', 'launch-builder' ) : esc_html__( 'Changed', 'launch-builder' ) ) . '</h2></div><table class="widefat striped lb-diff rwd"><thead><tr><th>' . esc_html__( 'Object', 'launch-builder' ) . '</th><th>' . esc_html__( 'Old', 'launch-builder' ) . '</th><th>' . esc_html__( 'New', 'launch-builder' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $changes, 0, 200 ) as $c ) {
				echo '<tr><td data-label="' . esc_attr__( 'Object', 'launch-builder' ) . '">' . esc_html( (string) ( $c['title'] ?? '' ) ) . ' <small>' . esc_html( (string) ( $c['object'] ?? '' ) ) . '</small></td><td data-label="' . esc_attr__( 'Old', 'launch-builder' ) . '"><del><code>' . esc_html( (string) $c['old'] ) . '</code></del></td><td data-label="' . esc_attr__( 'New', 'launch-builder' ) . '"><ins><code>' . esc_html( (string) $c['new'] ) . '</code></ins></td></tr>';
			}
			echo '</tbody></table>';
			foreach ( (array) ( $data['skipped'] ?? [] ) as $s ) {
				echo '<p>' . esc_html( (string) ( $s['old'] ?? '' ) . ' -> ' . ( $s['new'] ?? '' ) . ': ' . ( $s['error'] ?? '' ) ) . '</p>';
			}
			echo '</section>';
		}
	}

	private static function tab_urls(): void {
		$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$dups = ! empty( $_GET['dups'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per = 50;
		$data = self::installed() ? self::tool_get( [ 'search' => $s, 'type' => $type, 'source' => in_array( $source, [ 'custom', 'auto', 'native' ], true ) ? $source : '', 'duplicates_only' => $dups, 'offset' => ( $paged - 1 ) * $per, 'limit' => $per ] ) : [ 'total' => 0, 'urls' => [] ];
		if ( is_wp_error( $data ) ) {
			$data = [ 'total' => 0, 'urls' => [] ];
		}
		echo '<form method="get" class="lb-card lb-formrow lb-filterbar"><input type="hidden" name="page" value="launch-builder-urls"><input type="hidden" name="tab" value="urls">';
		echo '<label class="lb-search"><span class="screen-reader-text">' . esc_html__( 'Part of a URL', 'launch-builder' ) . '</span>' . Dashboard::icon( 'search' ) . '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Part of a URL', 'launch-builder' ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- the icon is static markup.
		echo '<label class="screen-reader-text" for="lb-urls-type">' . esc_html__( 'Type', 'launch-builder' ) . '</label><select id="lb-urls-type" name="type"><option value="">' . esc_html__( 'All types', 'launch-builder' ) . '</option>';
		foreach ( array_merge( self::post_types(), self::taxonomies() ) as $n ) {
			$o = get_post_type_object( $n ) ?: get_taxonomy( $n );
			echo '<option value="' . esc_attr( $n ) . '"' . selected( $type, $n, false ) . '>' . esc_html( $o ? $o->labels->name : $n ) . '</option>';
		}
		echo '</select><label class="screen-reader-text" for="lb-urls-source">' . esc_html__( 'Source', 'launch-builder' ) . '</label><select id="lb-urls-source" name="source"><option value="">' . esc_html__( 'Any source', 'launch-builder' ) . '</option>';
		foreach ( [ 'custom' => __( 'Custom', 'launch-builder' ), 'auto' => __( 'Automatic', 'launch-builder' ), 'native' => __( 'WordPress', 'launch-builder' ) ] as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $source, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><label class="lb-check"><input type="checkbox" name="dups" value="1"' . checked( $dups, true, false ) . '> ' . esc_html__( 'Duplicates only', 'launch-builder' ) . '</label>';
		echo '<button type="submit" class="lb-btn">' . esc_html__( 'Filter', 'launch-builder' ) . '</button>';
		echo '</form>';
		$chips = [ 'custom' => 'accent', 'auto' => 'ok', 'native' => 'plain' ];
		echo '<section class="lb-card lb-card--table"><div class="lb-panel__head"><h2>' . esc_html__( 'All URLs', 'launch-builder' ) . '</h2><span class="lb-chip lb-chip--plain">' . esc_html( sprintf( /* translators: %d: number of URLs */ _n( '%d URL', '%d URLs', (int) $data['total'], 'launch-builder' ), (int) $data['total'] ) ) . '</span></div>';
		echo '<table class="widefat striped rwd"><thead><tr><th>' . esc_html__( 'URL', 'launch-builder' ) . '</th><th>' . esc_html__( 'Title', 'launch-builder' ) . '</th><th>' . esc_html__( 'Type', 'launch-builder' ) . '</th><th>' . esc_html__( 'Source', 'launch-builder' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $data['urls'] as $u ) {
			$edit = 'term' === strtok( $u['object'], ':' ) ? get_edit_term_link( (int) $u['id'] ) : get_edit_post_link( (int) $u['id'] );
			echo '<tr><td data-label="' . esc_attr__( 'URL', 'launch-builder' ) . '"><a href="' . esc_url( $u['url'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( $u['uri'] ) . '</code></a>' . ( $u['duplicate'] ? ' <span class="lb-chip lb-chip--bad">' . esc_html__( 'duplicate', 'launch-builder' ) . '</span>' : '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Title', 'launch-builder' ) . '">' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $u['title'] ) . '</a>' : esc_html( $u['title'] ) ) . '</td><td data-label="' . esc_attr__( 'Type', 'launch-builder' ) . '">' . esc_html( $u['type'] ) . '</td><td data-label="' . esc_attr__( 'Source', 'launch-builder' ) . '"><span class="lb-chip lb-chip--' . esc_attr( $chips[ $u['source'] ] ?? 'plain' ) . '">' . esc_html( $u['source'] ) . '</span></td></tr>';
		}
		if ( ! $data['urls'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing found.', 'launch-builder' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( (int) $data['total'] / $per );
		if ( $pages > 1 ) {
			echo '<nav class="lb-pager tablenav-pages" aria-label="' . esc_attr__( 'Pages of results', 'launch-builder' ) . '">' . wp_kses_post( (string) paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ] ) ) . '</nav>';
		}
		echo '</section>';
	}

	private static function form_open( string $op ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_permalinks"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		wp_nonce_field( 'lb_permalinks_' . $op );
	}

	private static function types_checkboxes( string $name ): void {
		echo '<div class="lb-checks">';
		foreach ( array_merge( self::post_types(), self::taxonomies() ) as $n ) {
			$o = get_post_type_object( $n ) ?: get_taxonomy( $n );
			echo '<label class="lb-check"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $n ) . '"> ' . esc_html( $o ? $o->labels->name : $n ) . '</label>';
		}
		echo '</div>';
	}

	private static function tab_bulk(): void {
		echo '<section class="lb-card"><h2>' . esc_html__( 'Regenerate URLs', 'launch-builder' ) . '</h2><p class="lb-card__sub">' . esc_html__( 'Rebuild automatic URLs from the URL patterns (Settings tab). Custom URLs stay unless you include them. Old URLs redirect (301).', 'launch-builder' ) . '</p>';
		self::form_open( 'regenerate' );
		echo '<div class="lb-rows"><div class="lb-row" role="group" aria-labelledby="lb-bulk-types"><div class="lb-row__label" id="lb-bulk-types">' . esc_html__( 'What to rebuild', 'launch-builder' ) . '</div><div class="lb-row__control">';
		self::types_checkboxes( 'types' );
		echo '</div></div><div class="lb-row" role="group" aria-labelledby="lb-bulk-opts"><div class="lb-row__label" id="lb-bulk-opts">' . esc_html__( 'Options', 'launch-builder' ) . '</div><div class="lb-row__control"><div class="lb-choices">';
		echo '<label class="lb-check"><input type="checkbox" name="include_custom" value="1"> ' . esc_html__( 'Also reset custom URLs to automatic', 'launch-builder' ) . '</label>';
		echo '<label class="lb-check"><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Preview only (dry run)', 'launch-builder' ) . '</label>';
		echo '</div></div></div></div><div class="lb-formrow" style="margin-top:16px"><button type="submit" class="lb-btn lb-btn--primary">' . esc_html__( 'Run', 'launch-builder' ) . '</button></div>';
		echo '</form></section>';
		echo '<section class="lb-card"><h2>' . esc_html__( 'Find and replace', 'launch-builder' ) . '</h2><p class="lb-card__sub">' . esc_html__( 'Changed URLs become custom URLs; old URLs redirect (301). Collisions are skipped and listed.', 'launch-builder' ) . '</p>';
		self::form_open( 'replace' );
		echo '<div class="lb-rows"><div class="lb-row"><div class="lb-row__label">' . esc_html__( 'Change', 'launch-builder' ) . '</div><div class="lb-row__control"><div class="lb-formrow">';
		echo '<label class="screen-reader-text" for="lb-bulk-find">' . esc_html__( 'Find', 'launch-builder' ) . '</label><input type="text" id="lb-bulk-find" name="find" placeholder="' . esc_attr__( 'Find', 'launch-builder' ) . '" required>';
		echo '<span class="lb-arrow" aria-hidden="true">&rarr;</span>';
		echo '<label class="screen-reader-text" for="lb-bulk-replace">' . esc_html__( 'Replace with', 'launch-builder' ) . '</label><input type="text" id="lb-bulk-replace" name="replace" placeholder="' . esc_attr__( 'Replace with', 'launch-builder' ) . '">';
		echo '<label class="lb-check"><input type="checkbox" name="regex" value="1"> ' . esc_html__( 'Regular expression', 'launch-builder' ) . '</label>';
		echo '</div></div></div><div class="lb-row" role="group" aria-labelledby="lb-bulk-ftypes"><div class="lb-row__label" id="lb-bulk-ftypes">' . esc_html__( 'Where', 'launch-builder' ) . '</div><div class="lb-row__control">';
		self::types_checkboxes( 'types' );
		echo '</div></div><div class="lb-row"><div class="lb-row__label">' . esc_html__( 'Options', 'launch-builder' ) . '</div><div class="lb-row__control"><div class="lb-choices"><label class="lb-check"><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Preview only (dry run)', 'launch-builder' ) . '</label></div></div></div></div>';
		echo '<div class="lb-formrow" style="margin-top:16px"><button type="submit" class="lb-btn lb-btn--primary">' . esc_html__( 'Run', 'launch-builder' ) . '</button></div>';
		echo '</form></section>';
	}

	private static function tab_redirects(): void {
		$list = Redirects::list( [ 'limit' => 100 ] );
		echo '<section class="lb-card"><h2>' . esc_html__( 'Add a redirect', 'launch-builder' ) . '</h2>';
		self::form_open( 'redirect' );
		echo '<div class="lb-formrow" style="margin-top:12px"><label class="screen-reader-text" for="lb-redir-from">' . esc_html__( 'From', 'launch-builder' ) . '</label><input type="text" id="lb-redir-from" name="from" placeholder="/old-path/" required>';
		echo '<span class="lb-arrow" aria-hidden="true">&rarr;</span>';
		echo '<label class="screen-reader-text" for="lb-redir-to">' . esc_html__( 'To', 'launch-builder' ) . '</label><input type="text" id="lb-redir-to" name="to" class="lb-wide" placeholder="/new-path/ or https://...">';
		echo '<label class="screen-reader-text" for="lb-redir-code">' . esc_html__( 'Code', 'launch-builder' ) . '</label><select id="lb-redir-code" name="code">';
		foreach ( Redirects::CODES as $c ) {
			echo '<option value="' . (int) $c . '">' . (int) $c . '</option>';
		}
		echo '</select><button type="submit" class="lb-btn lb-btn--primary">' . esc_html__( 'Add', 'launch-builder' ) . '</button></div></form>';
		echo '<p class="lb-hint">' . esc_html( sprintf( /* translators: %s: store name */ __( 'New redirects go to: %s.', 'launch-builder' ), 'rankmath' === $list['store'] ? 'Rank Math Redirections' : __( 'Launch Builder redirects table', 'launch-builder' ) ) ) . '</p></section>';
		$total = (int) ( $list['total'] ?? count( (array) $list['redirects'] ) );
		echo '<section class="lb-card lb-card--table"><div class="lb-panel__head"><h2>' . esc_html__( 'Redirects', 'launch-builder' ) . '</h2><span class="lb-chip lb-chip--plain">' . esc_html( sprintf( /* translators: %d: number of redirects */ _n( '%d redirect', '%d redirects', $total, 'launch-builder' ), $total ) ) . '</span></div>';
		echo '<table class="widefat striped rwd"><thead><tr><th>' . esc_html__( 'From', 'launch-builder' ) . '</th><th>' . esc_html__( 'To', 'launch-builder' ) . '</th><th class="lb-num">' . esc_html__( 'Code', 'launch-builder' ) . '</th><th class="lb-num">' . esc_html__( 'Hits', 'launch-builder' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'launch-builder' ) . '</span></th></tr></thead><tbody>';
		foreach ( (array) $list['redirects'] as $r ) {
			echo '<tr><td data-label="' . esc_attr__( 'From', 'launch-builder' ) . '"><code>' . esc_html( (string) $r['from'] ) . '</code></td><td data-label="' . esc_attr__( 'To', 'launch-builder' ) . '"><code>' . esc_html( (string) ( $r['url'] ?: $r['to'] ) ) . '</code></td><td class="lb-num" data-label="' . esc_attr__( 'Code', 'launch-builder' ) . '">' . (int) $r['code'] . '</td><td class="lb-num" data-label="' . esc_attr__( 'Hits', 'launch-builder' ) . '">' . (int) $r['hits'] . '</td><td class="lb-end">';
			self::form_open( 'delete_redirect' );
			echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="store" value="' . esc_attr( (string) $r['store'] ) . '">';
			echo '<button type="submit" class="lb-btn lb-btn--sm lb-btn--danger">' . esc_html__( 'Delete', 'launch-builder' ) . '</button>';
			echo '</form></td></tr>';
		}
		if ( ! $list['redirects'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No redirects yet.', 'launch-builder' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $total > count( (array) $list['redirects'] ) ) {
			echo '<p class="lb-hint" style="padding:0 22px 16px">' . esc_html( sprintf( /* translators: %d: number of redirects shown */ __( 'Showing the newest %d.', 'launch-builder' ), count( (array) $list['redirects'] ) ) ) . '</p>';
		}
		echo '</section>';
	}

	private static function tab_settings(): void {
		$s = self::settings();
		self::form_open( 'settings' );
		echo '<section class="lb-card"><h2>' . esc_html__( 'URL patterns', 'launch-builder' ) . '</h2><p class="lb-card__sub">' . esc_html__( 'Leave a box empty to use the WordPress default. Saving a changed pattern rebuilds the first 200 URLs at once (the rest from the Bulk tools tab). Old URLs redirect.', 'launch-builder' ) . '</p>';
		$tags = [
			__( 'Posts and pages', 'launch-builder' ) => [ '%postname%', '%parent%', '%parent_uri%', '%lb_area_parent%', '%post_type%', '%year%', '%monthnum%', '%day%', '%author%', '%{taxonomy}%' ],
			__( 'Terms', 'launch-builder' )           => [ '%term%', '%parent%', '%parent_uri%', '%taxonomy%', '%{taxonomy}%' ],
		];
		echo '<div class="lb-tags">';
		foreach ( $tags as $head => $list ) {
			echo '<p><b>' . esc_html( $head ) . ':</b> ';
			foreach ( $list as $tag ) {
				echo '<code>' . esc_html( $tag ) . '</code> ';
			}
			echo '</p>';
		}
		echo '<p class="lb-hint">' . esc_html__( 'Use %{taxonomy}% with a taxonomy name, for example %lb_service_cat%.', 'launch-builder' ) . '</p></div>';
		echo '<div class="lb-rows">';
		foreach ( self::post_types() as $pt ) {
			$o = get_post_type_object( $pt );
			$native = (string) self::native_example( 'post', $pt );
			echo '<div class="lb-row"><div class="lb-row__label"><label for="lb-ps-post-' . esc_attr( $pt ) . '">' . esc_html( $o ? $o->labels->name : $pt ) . '</label> <small class="lb-code-label">' . esc_html( $pt ) . '</small></div><div class="lb-row__control"><input type="text" class="regular-text" id="lb-ps-post-' . esc_attr( $pt ) . '" name="ps_post[' . esc_attr( $pt ) . ']" value="' . esc_attr( (string) ( $s['permastructures']['post_types'][ $pt ] ?? '' ) ) . '" placeholder="' . esc_attr( $native ) . '"></div></div>';
		}
		foreach ( self::taxonomies() as $tax ) {
			$o = get_taxonomy( $tax );
			echo '<div class="lb-row"><div class="lb-row__label"><label for="lb-ps-term-' . esc_attr( $tax ) . '">' . esc_html( $o ? $o->labels->name : $tax ) . '</label> <small class="lb-code-label">' . esc_html( $tax ) . '</small></div><div class="lb-row__control"><input type="text" class="regular-text" id="lb-ps-term-' . esc_attr( $tax ) . '" name="ps_term[' . esc_attr( $tax ) . ']" value="' . esc_attr( (string) ( $s['permastructures']['taxonomies'][ $tax ] ?? '' ) ) . '" placeholder="' . esc_attr( (string) self::native_example( 'term', $tax ) ) . '"></div></div>';
		}
		echo '</div></section>';
		echo '<section class="lb-card"><h2>' . esc_html__( 'Behaviour', 'launch-builder' ) . '</h2><div class="lb-rows">';
		echo '<div class="lb-row"><div class="lb-row__label"><label for="lb-trailing">' . esc_html__( 'Trailing slash', 'launch-builder' ) . '</label></div><div class="lb-row__control"><select id="lb-trailing" name="trailing_slash">';
		foreach ( [ 'keep' => __( 'Follow the WordPress permalink setting', 'launch-builder' ), 'always' => __( 'Always', 'launch-builder' ), 'never' => __( 'Never', 'launch-builder' ) ] as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['trailing_slash'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></div></div>';
		echo '<div class="lb-row" role="group" aria-labelledby="lb-tidy"><div class="lb-row__label" id="lb-tidy">' . esc_html__( 'Tidy-ups', 'launch-builder' ) . '</div><div class="lb-row__control"><div class="lb-choices">';
		$checks = [ 'lowercase' => __( 'Lower-case URLs', 'launch-builder' ), 'transliterate' => __( 'Turn accented letters into plain ones (é to e)', 'launch-builder' ), 'stop_words' => __( 'Strip stop words (a, the, of...) from automatic URLs', 'launch-builder' ), 'slug_sync' => __( 'Keep the slug equal to the last part of a custom URL', 'launch-builder' ), 'auto_redirect' => __( 'Redirect (301) the old URL whenever a URL changes', 'launch-builder' ) ];
		foreach ( $checks as $k => $l ) {
			echo '<label class="lb-check"><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( (bool) $s[ $k ], true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '</div></div></div>';
		echo '<div class="lb-row"><div class="lb-row__label"><label for="lb-stopwords">' . esc_html__( 'Stop words', 'launch-builder' ) . '</label></div><div class="lb-row__control"><input type="text" class="large-text" id="lb-stopwords" name="stop_word_list" value="' . esc_attr( (string) $s['stop_word_list'] ) . '" placeholder="' . esc_attr( self::STOP_WORDS ) . '"></div></div>';
		echo '<div class="lb-row"><div class="lb-row__label"><label for="lb-redir-store">' . esc_html__( 'Redirects go to', 'launch-builder' ) . '</label></div><div class="lb-row__control"><select id="lb-redir-store" name="redirect_store">';
		foreach ( [ 'auto' => __( 'Rank Math Redirections when active, else Launch Builder', 'launch-builder' ), 'own' => __( 'Launch Builder redirects table', 'launch-builder' ), 'rankmath' => __( 'Rank Math Redirections', 'launch-builder' ) ] as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['redirect_store'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></div></div></div></section>';
		echo '<div class="lb-savebar"><p>' . esc_html__( 'Saving a changed pattern updates the URLs that use it.', 'launch-builder' ) . '</p><button type="submit" class="lb-btn lb-btn--primary">' . esc_html__( 'Save settings', 'launch-builder' ) . '</button></div>';
		echo '</form>';
	}

	/** WordPress's own URL of the first object of a type, as an example. */
	private static function native_example( string $kind, string $name ): string {
		if ( 'term' === $kind ) {
			$t = get_terms( [ 'taxonomy' => $name, 'number' => 1, 'hide_empty' => false ] );
			return $t && ! is_wp_error( $t ) ? '/' . (string) self::native_path( 'term', (int) $t[0]->term_id ) . '/' : '';
		}
		$p = get_posts( [ 'post_type' => $name, 'numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids' ] );
		return $p ? '/' . (string) self::native_path( 'post', (int) $p[0] ) . '/' : '';
	}

	/** admin-post.php?action=lb_permalinks: the URLs screen's forms. */
	public static function admin_post(): void {
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_permalinks_' . $op ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value is validated by the tool it is passed to.
		$tab = 'urls';
		$res = [];
		switch ( $op ) {
			case 'regenerate':
				$tab = 'bulk';
				$r = self::regenerate( array_map( 'sanitize_key', (array) ( $p['types'] ?? [] ) ), ! empty( $p['dry_run'] ), ! empty( $p['include_custom'] ), '', 200 );
				$res = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'data' => $r, 'message' => sprintf( /* translators: 1: changed count, 2: unchanged count */ __( '%1$d URLs to change, %2$d unchanged.', 'launch-builder' ), $r['changed'], $r['unchanged'] ) . ( $r['next_cursor'] ? ' ' . __( 'More remain: run again.', 'launch-builder' ) : '' ) ];
				break;
			case 'replace':
				$tab = 'bulk';
				$r = self::tool_find_replace( [ 'find' => (string) ( $p['find'] ?? '' ), 'replace' => (string) ( $p['replace'] ?? '' ), 'regex' => ! empty( $p['regex'] ), 'types' => array_map( 'sanitize_key', (array) ( $p['types'] ?? [] ) ), 'dry_run' => ! empty( $p['dry_run'] ), 'limit' => 200 ] );
				$res = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'data' => $r, 'message' => sprintf( /* translators: %d: changed count */ __( '%d URLs matched.', 'launch-builder' ), $r['changed'] ) ];
				break;
			case 'redirect':
				$tab = 'redirects';
				$r = Redirects::add( (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), (int) ( $p['code'] ?? 301 ) );
				$res = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'message' => sprintf( /* translators: 1: from, 2: to */ __( 'Redirect saved: %1$s to %2$s.', 'launch-builder' ), $r['from'], $r['url'] ?: $r['to'] ) ];
				break;
			case 'delete_redirect':
				$tab = 'redirects';
				$r = Redirects::delete( [ 'id' => (int) ( $p['id'] ?? 0 ), 'store' => sanitize_key( (string) ( $p['store'] ?? '' ) ) ] );
				$res = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'message' => __( 'Redirect deleted.', 'launch-builder' ) ];
				break;
			case 'settings':
				$tab = 'settings';
				$res = self::save_settings_form( $p );
				break;
		}
		set_transient( 'lb_urls_result_' . get_current_user_id(), $res + [ 'tab' => $tab ], 300 );
		wp_safe_redirect( self::page_url( $tab ) );
		exit;
	}

	private static function save_settings_form( array $p ): array {
		$errors = [];
		$in = [ 'trailing_slash' => (string) ( $p['trailing_slash'] ?? 'keep' ), 'stop_word_list' => (string) ( $p['stop_word_list'] ?? '' ), 'redirect_store' => (string) ( $p['redirect_store'] ?? 'auto' ) ];
		foreach ( [ 'lowercase', 'transliterate', 'stop_words', 'slug_sync', 'auto_redirect' ] as $k ) {
			$in[ $k ] = ! empty( $p[ $k ] );
		}
		self::tool_settings( $in );
		$changed = 0;
		foreach ( [ 'post' => (array) ( $p['ps_post'] ?? [] ), 'term' => (array) ( $p['ps_term'] ?? [] ) ] as $kind => $list ) {
			foreach ( $list as $name => $st ) {
				$name = sanitize_key( (string) $name );
				if ( (string) $st === self::structure( $kind, $name ) || '/' . trim( (string) $st, '/' ) . '/' === self::structure( $kind, $name ) ) {
					continue;
				}
				$r = self::tool_set_structure( [ 'type' => $name, 'structure' => (string) $st ] );
				if ( is_wp_error( $r ) ) {
					$errors[] = $name . ': ' . $r->get_error_message();
				} else {
					$changed += (int) ( $r['regenerated']['changed'] ?? 0 );
				}
			}
		}
		if ( $errors ) {
			return [ 'error' => implode( ' ', $errors ) ];
		}
		return [ 'message' => sprintf( /* translators: %d: number of URLs */ __( 'Settings saved. %d URLs changed.', 'launch-builder' ), $changed ) ];
	}
}
