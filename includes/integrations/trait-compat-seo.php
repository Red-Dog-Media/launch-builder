<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: SEO strings (clean_seo and friends), the Rank Math and Yoast variables, and the Rank Math settings Launch Builder owns.
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Seo {

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
		switch ( $k ) {
			case 'title':
			case 'name':
				return self::plain_title( $p );
			case 'summary':
				return in_array( $ns, [ 'entry', 'page' ], true ) ? $summary() : '';
			case 'url':
				return (string) get_permalink( $p );
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

	/** The profile web addresses Rank Math holds as typed there (its Facebook page and its Additional profiles list), without our overlay. */
	public static function rm_profiles(): array {
		if ( ! self::rm_active() ) {
			return [];
		}
		$raw = self::raw_option( self::RM_OPTIONS['titles'] );
		$lines = array_merge( [ (string) ( $raw['social_url_facebook'] ?? '' ) ], (array) preg_split( '/[\r\n]+/', (string) ( $raw['social_additional_profiles'] ?? '' ) ) );
		$out = [];
		foreach ( $lines as $u ) {
			$u = trim( (string) $u );
			if ( 1 === preg_match( '#^https?://[^\s]+\.[^\s]+$#i', $u ) ) {
				$out[] = esc_url_raw( (string) preg_replace( '#^http://#i', 'https://', $u ) ); // Profiles are https only.
			}
		}
		return array_values( array_unique( $out ) );
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

		$only = $business ? array_values( array_map( 'strval', (array) ( $args['only'] ?? [] ) ) ) : []; // A narrow fill: just these titles keys, and the overlay list keeps what it has.
		$plan = []; // group => key => value
		$owned_now = $only ? array_values( (array) get_option( self::OPT_OWNED, [] ) ) : [];
		$desired = self::rm_desired( $business ? 'business' : 'all' );
		foreach ( $desired as $group => $keys ) {
			$raw = self::raw_option( self::RM_OPTIONS[ $group ] );
			foreach ( $keys as $key => $want ) {
				if ( $only && ( 'titles' !== $group || ! in_array( (string) $key, $only, true ) ) ) {
					continue;
				}
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
}
