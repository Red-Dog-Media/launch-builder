<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: What Rank Math and Yoast print: the sync (sync-seo), role templates on pages, front-end title, description and robots,
 * schema in Rank Math's graph, sitemaps, IndexNow suppression and the editor's seo-context route.
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Seo_Output {

	/* ------------------------------------------------------------------ the sync (settings + Kadence + pages) */

	/**
	 * Full SEO sync: Rank Math settings, Kadence local fonts, role templates on pages. Templates passed in are validated,
	 * stored (snapshot) and applied.
	 *
	 * @param array $args { dry_run, templates, local_seo, pages (default true), force[], offset }
	 */
	public static function sync_seo( array $args ): array {
		$dry = ! empty( $args['dry_run'] );
		$report = [ 'dry_run' => $dry, 'warnings' => [] ];
		if ( isset( $args['templates'] ) ) {
			$t = self::store_templates( (array) $args['templates'], $dry );
			if ( is_wp_error( $t ) ) {
				return [ 'error' => $t->get_error_message() ];
			}
			$report['templates'] = $t;
			$report['warnings'] = array_merge( $report['warnings'], $t['warnings'] );
		}
		$force = (array) ( $args['force'] ?? [] );
		$report['rank_math'] = self::rank_math_settings( [ 'dry_run' => $dry, 'force' => array_filter( $force, 'is_string' ), 'local_seo' => $args['local_seo'] ?? null ] );
		$report['warnings'] = array_merge( $report['warnings'], $report['rank_math']['warnings'] );
		if ( ! empty( $report['rank_math']['snapshot'] ) ) {
			$report['snapshot'] = $report['rank_math']['snapshot'];
		}
		$report['yoast'] = self::yoast_settings( $dry );
		$report['kadence'] = self::kadence_settings( $dry, in_array( 'all', $force, true ) || in_array( 'kadence.load_fonts_local', $force, true ) );
		if ( ! array_key_exists( 'pages', $args ) || false !== $args['pages'] ) {
			$report['pages'] = self::apply_role_templates( [ 'dry_run' => $dry, 'force' => $force, 'offset' => (int) ( $args['offset'] ?? 0 ) ] );
		}
		if ( ! $dry ) {
			Cache::purge_later();
		}
		return $report;
	}

	/** Validate and (unless dry) store role templates. Fields cleaned with clean_seo(); '' removes a field. */
	private static function store_templates( array $in, bool $dry ) {
		$cur = self::templates();
		$new = $cur;
		$warnings = [];
		foreach ( $in as $role => $fields ) {
			$role = (string) $role;
			if ( ! isset( Roles::ROLES[ $role ] ) ) {
				/* translators: 1: role, 2: list of roles */
				return new \WP_Error( 'lb_role', sprintf( __( 'Unknown role "%1$s". Roles: %2$s.', 'launch-builder' ), $role, implode( ', ', array_keys( Roles::ROLES ) ) ) );
			}
			foreach ( (array) $fields as $field => $value ) {
				if ( ! in_array( $field, [ 'title', 'description' ], true ) ) {
					continue;
				}
				$value = self::clean_seo( $value );
				if ( strlen( $value ) > 320 ) {
					/* translators: 1: role, 2: field */
					return new \WP_Error( 'lb_template', sprintf( __( 'The %1$s %2$s template is too long (320 characters at most).', 'launch-builder' ), $role, $field ) );
				}
				if ( '' === $value ) {
					unset( $new[ $role ][ $field ] );
					continue;
				}
				$new[ $role ][ $field ] = $value;
				$unknown = self::unknown_vars( $value );
				if ( $unknown ) {
					/* translators: 1: role, 2: field, 3: variables */
					$warnings[] = sprintf( __( '%1$s %2$s: nothing replaces %3$s.', 'launch-builder' ), $role, $field, '%' . implode( '%, %', $unknown ) . '%' );
				}
				if ( preg_match( '/\s[|\-–—•·]\s/u', $value ) ) {
					/* translators: 1: role, 2: field */
					$warnings[] = sprintf( __( '%1$s %2$s uses a literal separator: use %%sep%% so it follows the SEO plugin setting.', 'launch-builder' ), $role, $field );
				}
				if ( false !== strpos( $value, '{{' ) ) {
					/* translators: 1: role, 2: field */
					$warnings[] = sprintf( __( '%1$s %2$s contains a {{token}}: SEO templates use %%variables%% (see get-playbook seo).', 'launch-builder' ), $role, $field );
				}
			}
			if ( isset( $new[ $role ] ) && ! $new[ $role ] ) {
				unset( $new[ $role ] );
			}
		}
		ksort( $new );
		if ( ! $dry && $new !== $cur ) {
			Abilities::snapshot( 'sync-seo templates', [], [ self::OPT_TEMPLATES ] );
			update_option( self::OPT_TEMPLATES, $new, false );
		}
		return [ 'stored' => $new, 'changed' => $new !== $cur, 'warnings' => $warnings ];
	}

	/** Yoast: service/area templates in wpseo_titles (translated to %%x%%), Kadence elements out of Yoast's sitemap by filter. */
	private static function yoast_settings( bool $dry ): array {
		if ( ! self::yoast_active() ) {
			return [ 'active' => false ];
		}
		$opt = get_option( 'wpseo_titles', [] );
		$opt = is_array( $opt ) ? $opt : [];
		$changes = [];
		foreach ( [ 'service' => Entries::SERVICE, 'area' => Entries::AREA ] as $role => $pt ) {
			foreach ( [ 'title' => 'title-' . $pt, 'description' => 'metadesc-' . $pt ] as $field => $key ) {
				$want = self::to_yoast( self::clean_seo( (string) ( self::templates()[ $role ][ $field ] ?? '' ) ) ); // Stored templates only (Yoast parity is unverified).
				if ( '' !== $want && ( $opt[ $key ] ?? null ) !== $want && ! Ledger::is_edited( 'yoast:titles:' . $key, Ledger::hash_value( $opt[ $key ] ?? null ) ) ) {
					$changes[ $key ] = [ 'old' => $opt[ $key ] ?? null, 'new' => $want ];
					$opt[ $key ] = $want;
				}
			}
		}
		if ( ! $dry && $changes ) {
			Abilities::snapshot( 'sync-seo yoast', [], [ 'wpseo_titles' ] );
			update_option( 'wpseo_titles', $opt );
			foreach ( array_keys( $changes ) as $key ) {
				Ledger::record( 'yoast:titles:' . $key, 'option', 'wpseo_titles', Ledger::hash_value( $opt[ $key ] ), 'set' );
			}
		}
		return [ 'active' => true, 'changes' => $changes, 'note' => 'Yoast parity is partial (meta, templates, variables, sitemap exclusions); schema and breadcrumbs follow Rank Math only.' ];
	}

	/** Kadence: fonts are Kadence's to load locally (Perfmatters Local Fonts stays off). Microdata is filtered at runtime. */
	private static function kadence_settings( bool $dry, bool $force ): array {
		if ( ! Plugin::kadence_theme_active() ) {
			return [ 'active' => false ];
		}
		$out = [ 'active' => true, 'microdata' => (bool) apply_filters( 'kadence_microdata', true, 'launch-builder-report' ) ? 'on' : 'off (Rank Math outputs the schema)' ];
		$cur = get_theme_mod( 'load_fonts_local', null );
		$key = 'kadence:load_fonts_local';
		if ( true === (bool) $cur ) {
			$out['load_fonts_local'] = 'ok';
			if ( ! $dry && ! Ledger::get( $key ) ) {
				Ledger::record( $key, 'theme_mod', 'load_fonts_local', Ledger::hash_value( $cur ), 'set' );
			}
		} elseif ( Ledger::is_edited( $key, Ledger::hash_value( $cur ) ) && ! $force ) {
			$out['load_fonts_local'] = 'kept_edit';
		} else {
			$out['load_fonts_local'] = $dry ? 'would turn on' : 'turned on';
			if ( ! $dry ) {
				$out['snapshot'] = Abilities::snapshot( 'sync-seo kadence', [ 'load_fonts_local' ] );
				set_theme_mod( 'load_fonts_local', true );
				Ledger::record( $key, 'theme_mod', 'load_fonts_local', Ledger::hash_value( get_theme_mod( 'load_fonts_local', null ) ), 'set' );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ role templates on pages */

	/**
	 * Write each page's SEO title/description from its role's template (Rank Math meta, Yoast copy translated), and the
	 * role robots default (thanks: noindex,follow). _lb_seo_tpl records md5 of what was written per field; a later run
	 * updates a field only while it still equals that (or is empty and never written), removes it when the role has no
	 * template any more, and reports the rest as kept edits. Variables stay unresolved in the meta (Rank Math resolves
	 * them per request).
	 *
	 * @param array $args { dry_run, force: (int|string)[] page ids or 'all', offset }
	 */
	public static function apply_role_templates( array $args = [] ): array {
		$dry = ! empty( $args['dry_run'] );
		$force = (array) ( $args['force'] ?? [] );
		$all = in_array( 'all', $force, true );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$limit = 300;
		$targets = self::meta_targets();
		$rm_reads = in_array( 'rankmath', $targets, true );
		$ids = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ], 'numberposts' => $limit + 1, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ] );
		$next = count( $ids ) > $limit ? $offset + $limit : null;
		$ids = array_slice( $ids, 0, $limit );
		$rows = [];
		$totals = [ 'pages' => count( $ids ), 'set' => 0, 'kept' => 0, 'removed' => 0 ];
		$h = static function ( $v ): string { return md5( (string) wp_json_encode( $v ) ); };
		foreach ( $ids as $id ) {
			$role = Roles::of( $id );
			$tpl = json_decode( (string) get_post_meta( $id, self::META_TPL, true ), true );
			$tpl = is_array( $tpl ) ? $tpl : [];
			$forced = $all || in_array( (int) $id, array_map( 'intval', array_filter( $force, 'is_numeric' ) ), true );
			$want = [
				'title'       => self::clean_seo( self::role_template( $role, 'title' ) ),
				'description' => self::clean_seo( self::role_template( $role, 'description' ) ),
				'robots'      => 'thanks' === $role ? self::ROLE_ROBOTS['thanks'] : [],
			];
			if ( isset( self::OPTION_ROLES[ $role ] ) ) { // Roles whose templates live in options (never on a page).
				$want['title'] = '';
				$want['description'] = '';
			}
			$row = [ 'id' => (int) $id, 'role' => $role, 'set' => [], 'kept' => [], 'removed' => [] ];
			$keys = [ 'title' => [ 'rank_math_title', '_yoast_wpseo_title' ], 'description' => [ 'rank_math_description', '_yoast_wpseo_metadesc' ], 'robots' => [ 'rank_math_robots', '_yoast_wpseo_meta-robots-noindex' ] ];
			foreach ( $want as $field => $value ) {
				// The stored value is read from Rank Math's key, or (Yoast only) from Yoast's, back in Rank Math syntax.
				if ( $rm_reads ) {
					$cur = get_post_meta( $id, $keys[ $field ][0], true );
					$cur = 'robots' === $field ? ( is_array( $cur ) ? array_values( $cur ) : [] ) : (string) $cur;
				} elseif ( 'robots' === $field ) {
					$cur = '1' === (string) get_post_meta( $id, $keys[ $field ][1], true ) ? self::ROLE_ROBOTS['thanks'] : [];
				} else {
					$cur = (string) preg_replace( '/%%([A-Za-z][A-Za-z0-9_-]*)%%/', '%$1%', (string) get_post_meta( $id, $keys[ $field ][1], true ) );
				}
				$empty = ( [] === $cur || '' === $cur );
				$lb_wrote = isset( $tpl[ $field ] ) && $tpl[ $field ] === $h( $cur );
				$value_empty = ( [] === $value || '' === $value );
				if ( $value_empty ) {
					if ( $lb_wrote && ! $empty ) {
						$row['removed'][] = $field;
						if ( ! $dry ) {
							delete_post_meta( $id, $keys[ $field ][0] );
							if ( in_array( 'yoast', $targets, true ) ) {
								delete_post_meta( $id, $keys[ $field ][1] );
							}
						}
					}
					unset( $tpl[ $field ] );
					continue;
				}
				if ( $h( $cur ) === $h( $value ) ) {
					$tpl[ $field ] = $h( $value );
					continue;
				}
				if ( ! ( ( $empty && ! isset( $tpl[ $field ] ) ) || $lb_wrote || $forced ) ) {
					$row['kept'][] = $field;
					continue;
				}
				$row['set'][] = $field;
				$tpl[ $field ] = $h( $value );
				if ( $dry ) {
					continue;
				}
				if ( in_array( 'rankmath', $targets, true ) ) {
					update_post_meta( $id, $keys[ $field ][0], 'robots' === $field ? $value : wp_slash( $value ) );
				}
				if ( in_array( 'yoast', $targets, true ) ) {
					update_post_meta( $id, $keys[ $field ][1], 'robots' === $field ? ( in_array( 'noindex', $value, true ) ? '1' : '2' ) : wp_slash( self::to_yoast( $value ) ) );
				}
			}
			if ( ! $dry ) {
				$tpl ? update_post_meta( $id, self::META_TPL, wp_slash( (string) wp_json_encode( $tpl ) ) ) : delete_post_meta( $id, self::META_TPL );
			}
			$totals['set'] += $row['set'] ? 1 : 0;
			$totals['kept'] += $row['kept'] ? 1 : 0;
			$totals['removed'] += $row['removed'] ? 1 : 0;
			if ( $row['set'] || $row['kept'] || $row['removed'] ) {
				$row['title'] = get_the_title( $id );
				$rows[] = $row;
			}
		}
		return [ 'totals' => $totals, 'pages' => array_slice( $rows, 0, 100 ), 'next_offset' => $next ];
	}

	/* ------------------------------------------------------------------ front-end title / description / robots */

	/** The singular post being viewed (front end). */
	private static function viewed_post(): ?\WP_Post {
		if ( ! did_action( 'wp' ) || ! is_singular() ) {
			return null;
		}
		$p = get_queried_object();
		return $p instanceof \WP_Post ? $p : null;
	}

	/** Pages without their own SEO title get their role's template (pages created after the last sync). */
	public static function rm_title( $title ) {
		$p = self::viewed_post();
		if ( ! $p || 'page' !== $p->post_type || '' !== (string) get_post_meta( $p->ID, 'rank_math_title', true ) ) {
			return $title;
		}
		$role = Roles::current();
		$tpl = isset( self::OPTION_ROLES[ $role ] ) ? '' : self::role_template( $role, 'title' );
		$out = '' !== $tpl ? self::rm_replace( $tpl, $p ) : '';
		return '' !== $out ? $out : $title;
	}

	/**
	 * Description precedence. Services and areas: their own rank_math_description, else the post type template (role
	 * template) even when an excerpt exists (Rank Math would take the excerpt first); pages: their own, else the role
	 * template, else Rank Math's value.
	 */
	public static function rm_description( $desc ) {
		$p = self::viewed_post();
		if ( ! $p || '' !== (string) get_post_meta( $p->ID, 'rank_math_description', true ) ) {
			return $desc;
		}
		$tpl = '';
		if ( in_array( $p->post_type, [ Entries::SERVICE, Entries::AREA ], true ) ) {
			$tpl = class_exists( '\RankMath\Helper' ) ? (string) \RankMath\Helper::get_settings( 'titles.pt_' . $p->post_type . '_description' ) : '';
			if ( '' === $tpl ) {
				$tpl = self::role_template( Entries::SERVICE === $p->post_type ? 'service' : 'area', 'description' );
			}
		} elseif ( 'page' === $p->post_type ) {
			$role = Roles::current();
			$tpl = isset( self::OPTION_ROLES[ $role ] ) ? '' : self::role_template( $role, 'description' );
		}
		$out = '' !== $tpl ? self::rm_replace( $tpl, $p ) : '';
		return '' !== $out ? $out : $desc;
	}

	/** The robots default for the current role when the object has none of its own. */
	private static function role_robots(): array {
		$role = Roles::current();
		if ( ! isset( self::ROLE_ROBOTS[ $role ] ) ) {
			return [];
		}
		$p = self::viewed_post();
		if ( $p ) {
			$own = get_post_meta( $p->ID, 'rank_math_robots', true );
			if ( is_array( $own ) && $own ) {
				return [];
			}
		}
		return self::ROLE_ROBOTS[ $role ];
	}

	/** Rank Math robots: thanks noindex,follow; search and 404 noindex (unless the page sets its own). */
	public static function rm_robots( $robots ) {
		$want = self::role_robots();
		if ( ! $want || ! is_array( $robots ) ) {
			return $robots;
		}
		$robots['index'] = 'noindex';
		$robots['follow'] = in_array( 'nofollow', $want, true ) ? 'nofollow' : 'follow';
		return $robots;
	}

	/** Yoast robots (array form, Yoast 14+). */
	public static function yoast_robots( $robots ) {
		$want = self::role_robots();
		if ( ! $want || ! is_array( $robots ) ) {
			return $robots;
		}
		$robots['index'] = 'noindex';
		return $robots;
	}

	/* ------------------------------------------------------------------ schema in Rank Math's graph */

	/** WebSite/WebPage/publisher entities on services, areas and pages (pages are not Articles any more). */
	public static function rm_global_entities( $can, $jsonld = null ) {
		unset( $jsonld );
		if ( is_singular( [ Entries::SERVICE, Entries::AREA, 'page' ] ) ) {
			return true;
		}
		return $can;
	}

	/** The publisher @id Rank Math uses. */
	private static function publisher_id( array $data = [] ): string {
		if ( ! empty( $data['publisher']['@id'] ) ) {
			return (string) $data['publisher']['@id'];
		}
		$type = class_exists( '\RankMath\Helper' ) ? \RankMath\Helper::get_settings( 'titles.knowledgegraph_type' ) : 'company';
		return home_url( 'company' === $type ? '/#organization' : '/#person' );
	}

	/**
	 * rank_math/json_ld (priority 20, before Rank Math connects entities at 99): the default Service entity for services
	 * (Rank Math only builds Article/Product defaults; editor-saved Service schemas are left alone), areaServed on the
	 * publisher, and the per-entry FAQPage. Never AggregateRating, never a second LocalBusiness.
	 */
	public static function rm_json_ld( $data, $jsonld = null ) {
		unset( $jsonld );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$p = self::viewed_post();
		if ( $p && Entries::SERVICE === $p->post_type && ! post_password_required( $p ) && ! isset( $data['richSnippet'] ) && self::uses_default_service( $p ) ) {
			$name = (string) \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_snippet_name', '%seo_title%' );
			$desc = (string) \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_snippet_desc', '%seo_description%' );
			$entity = array_filter( [
				'@type'       => 'Service',
				'name'        => self::rm_replace( '' !== $name ? $name : '%seo_title%', $p ),
				'description' => self::rm_replace( '' !== $desc ? $desc : '%seo_description%', $p ),
				'isPrimary'   => true,
			] );
			$data['richSnippet'] = apply_filters( 'rank_math/snippet/rich_snippet_service_entity', $entity );
		}
		if ( isset( $data['publisher'] ) && is_array( $data['publisher'] ) && empty( $data['publisher']['areaServed'] ) ) {
			$areas = self::areas_served( 0 );
			if ( $areas ) {
				$data['publisher']['areaServed'] = $areas;
			}
		}
		if ( $p && ! post_password_required( $p ) ) {
			$faq = self::faq_items( $p->ID );
			if ( $faq && ! self::has_faq_markup( $p ) ) {
				$data['lb-faq'] = [
					'@type'      => 'FAQPage',
					'mainEntity' => array_map( static function ( $qa ) { return [ '@type' => 'Question', 'name' => $qa['q'], 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $qa['a'] ] ]; }, $faq ),
				];
			}
		}
		return $data;
	}

	/** Does this service get Rank Math's default schema (no stored schema, no per-post type, default type service)? */
	private static function uses_default_service( \WP_Post $p ): bool {
		if ( 'service' !== \RankMath\Helper::get_settings( 'titles.pt_' . Entries::SERVICE . '_default_rich_snippet' ) || metadata_exists( 'post', $p->ID, 'rank_math_rich_snippet' ) ) {
			return false;
		}
		if ( class_exists( '\RankMath\Schema\DB' ) && \RankMath\Schema\DB::get_schemas( $p->ID ) ) {
			return false;
		}
		return true;
	}

	/** Enrich every Service entity on a service page: serviceType, category, provider (the publisher), areaServed. */
	public static function rm_service_entity( $entity ) {
		$p = self::viewed_post();
		if ( ! is_array( $entity ) || ! $p || Entries::SERVICE !== $p->post_type ) {
			return $entity;
		}
		if ( empty( $entity['serviceType'] ) ) {
			$entity['serviceType'] = self::plain_title( $p );
		}
		$parent = $p->post_parent ? get_post( $p->post_parent ) : null; // A sub-service: its service is the category.
		if ( $parent && Entries::SERVICE === $parent->post_type && empty( $entity['category'] ) ) {
			$entity['category'] = self::plain_title( $parent );
		}
		if ( empty( $entity['provider'] ) ) {
			$entity['provider'] = [ '@id' => self::publisher_id() ];
		}
		if ( empty( $entity['areaServed'] ) ) {
			$areas = self::areas_served( $p->ID );
			if ( $areas ) {
				$entity['areaServed'] = $areas;
			}
		}
		unset( $entity['aggregateRating'], $entity['review'] );
		return $entity;
	}

	/** A post title as plain text (texturized entities decoded) for JSON-LD. */
	private static function plain_title( $post ): string {
		return html_entity_decode( self::clean_seo( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * areaServed as Place nodes: the published areas with a page for a service (an area service page about it, or about
	 * one of its sub-services); for the publisher (0) and for a service no area has a page for, every top-level area.
	 */
	public static function areas_served( int $service_id ): array {
		if ( isset( self::$areas[ $service_id ] ) ) {
			return self::$areas[ $service_id ];
		}
		$areas = [];
		if ( $service_id ) {
			$ids = array_merge( [ $service_id ], array_map( 'intval', get_children( [ 'post_parent' => $service_id, 'post_type' => Entries::SERVICE, 'post_status' => 'publish', 'fields' => 'ids' ] ) ) );
			$pages = get_posts( [ 'post_type' => Entries::AREA, 'post_status' => 'publish', 'numberposts' => 200, 'meta_query' => [ [ 'key' => '_lb_service', 'value' => $ids, 'compare' => 'IN' ] ], 'suppress_filters' => false ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $pages as $pg ) {
				$a = $pg->post_parent ? get_post( $pg->post_parent ) : null;
				if ( $a && 'publish' === $a->post_status ) {
					$areas[ $a->ID ] = $a;
				}
			}
		}
		if ( ! $areas ) {
			$areas = Entries::query( 'area', [ 'parent' => 'top', 'limit' => 100 ] );
		}
		$out = [];
		foreach ( $areas as $a ) {
			$out[] = [ '@type' => 'Place', 'name' => self::plain_title( $a ), 'url' => get_permalink( $a ) ];
		}
		return self::$areas[ $service_id ] = array_slice( $out, 0, 50 );
	}

	/** Per-entry FAQs from _lb_faq: [{q, a}] (also {question, answer}), tokens resolved as text, 30 at most. */
	public static function faq_items( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_FAQ, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			$row = (array) $row;
			$q = self::faq_text( $row['q'] ?? ( $row['question'] ?? '' ), $post_id );
			$a = self::faq_text( $row['a'] ?? ( $row['answer'] ?? '' ), $post_id );
			if ( '' !== $q && '' !== $a ) {
				$out[] = [ 'q' => $q, 'a' => $a ];
			}
		}
		return array_slice( $out, 0, 30 );
	}

	private static function faq_text( $s, int $post_id ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( false !== strpos( $s, '{{' ) || false !== strpos( $s, '#lb-' ) ) {
			Tokens::push( $post_id );
			$s = Tokens::replace( $s, 'text' );
			Tokens::pop();
		}
		return self::clean_seo( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) );
	}

	/** Is there FAQ markup already on this page (a faqSchema accordion or an SEO plugin FAQ block, in the content or the designs placed on it)? */
	public static function has_faq_markup( \WP_Post $p ): bool {
		$sources = [ (string) $p->post_content, Design_Hub::role_markup( Roles::of( $p->ID ) ) ];
		foreach ( $sources as $c ) {
			if ( false !== strpos( $c, '"faqSchema":true' ) || false !== strpos( $c, '<!-- wp:rank-math/faq-block' ) || false !== strpos( $c, '<!-- wp:yoast/faq-block' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Rank Math breadcrumb items from a Launch Builder trail: [name, url, hide_in_schema => false], last url = canonical. */
	public static function rank_math_crumbs( array $trail ): array {
		$out = [];
		$last = count( $trail ) - 1;
		foreach ( array_values( $trail ) as $i => $t ) {
			$t = (array) $t;
			$url = (string) ( $t['url'] ?? '' );
			if ( $i === $last && '' === $url ) {
				$url = (string) wp_get_canonical_url();
			}
			$out[] = [ self::clean_seo( $t['title'] ?? '' ), $url, 'hide_in_schema' => false ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ sitemaps */

	/** Kadence/Launch Builder template types never appear in an XML sitemap (Rank Math and Yoast). */
	public static function exclude_post_type( $exclude, $type ) {
		return in_array( (string) $type, self::NON_SEO_TYPES, true ) ? true : $exclude;
	}

	/** The service categories of sites built before v2 stay out of sitemaps. */
	public static function exclude_taxonomy( $exclude, $taxonomy ) {
		if ( Entries::LEGACY_CAT === (string) $taxonomy ) {
			return true; // Service categories of sites built before v2 (now parent services).
		}
		return $exclude;
	}

	/** Thank-you pages are out of Rank Math's sitemap (unless explicitly set to index). */
	public static function rm_sitemap_entry( $url, $type = '', $object = null ) {
		// Rank Math passes a plain row object from its own query (ID, post_type, ...), not a WP_Post.
		if ( 'post' !== $type || ! is_object( $object ) || empty( $object->ID ) || 'page' !== ( $object->post_type ?? '' ) ) {
			return $url;
		}
		if ( 'thanks' === (string) get_post_meta( $object->ID, Roles::META, true ) ) {
			$own = get_post_meta( $object->ID, 'rank_math_robots', true );
			if ( ! is_array( $own ) || ! in_array( 'index', $own, true ) ) {
				return false;
			}
		}
		return $url;
	}

	/* ------------------------------------------------------------------ IndexNow */

	/** Builders call this to keep IndexNow quiet for the rest of the request. */
	public static function suppress_indexing( bool $on = true ): void {
		self::$suppress = $on;
	}

	/** Is IndexNow suppressed now (build running, site not public, brief.launch.indexing false)? */
	public static function indexing_suppressed(): bool {
		$why = self::$suppress || '0' === (string) get_option( 'blog_public', '1' ) || Lock::held( 'build' );
		if ( ! $why ) {
			$job = get_option( 'launch_builder_build_job', null );
			$why = is_array( $job ) && isset( $job['brief']['launch']['indexing'] ) && false === $job['brief']['launch']['indexing'];
		}
		return (bool) apply_filters( 'launch_builder_suppress_indexing', $why );
	}

	/** rank_math/instant_indexing/publish_url: false while suppressed (counted). */
	public static function indexnow( $url, $post = null ) {
		unset( $post );
		if ( ! $url || ! self::indexing_suppressed() ) {
			return $url;
		}
		if ( 0 === self::$suppressed++ ) {
			add_action( 'shutdown', [ __CLASS__, 'save_indexnow' ] );
		}
		self::$last_url = (string) $url;
		return false;
	}

	public static function save_indexnow(): void {
		$s = get_option( self::OPT_INDEXNOW, [] );
		$s = is_array( $s ) ? $s : [];
		update_option( self::OPT_INDEXNOW, [ 'count' => (int) ( $s['count'] ?? 0 ) + self::$suppressed, 'last_url' => self::$last_url, 'last_time' => time() ], false );
		self::$suppressed = 0;
	}

	/* ------------------------------------------------------------------ editor: seo-context */

	public static function routes(): void {
		register_rest_route( 'launch-builder/v1', '/seo-context/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'seo_context_rest' ],
			'permission_callback' => static function ( \WP_REST_Request $r ) { return current_user_can( 'edit_post', (int) $r['id'] ); },
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		] );
	}

	public static function seo_context_rest( \WP_REST_Request $r ) {
		$post = get_post( (int) $r['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'lb_not_found', __( 'No such post.', 'launch-builder' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( self::seo_context( $post ) );
	}

	/**
	 * What Rank Math's content analysis should also see for a post: token values (ns.key => text) and the designs the
	 * Design hub places around the content (hero, lists) with tokens resolved, as HTML.
	 */
	public static function seo_context( \WP_Post $post ): array {
		$tokens = [];
		foreach ( self::seo_vars() as $row ) {
			$v = self::var_value( $row['key'], $post );
			if ( '' !== $v ) {
				$tokens[ $row['key'] ] = $v;
			}
		}
		$role = Roles::of( $post->ID );
		$markup = '' !== $role ? Design_Hub::role_markup( $role, [ 'hero', 'list' ] ) : '';
		$html = '' !== $markup ? self::render_for( $markup, $role, $post ) : '';
		$html = trim( wp_kses_post( (string) preg_replace( '#<(script|style|svg)\b[^>]*>.*?</\1>#is', '', $html ) ) );
		return [ 'id' => $post->ID, 'role' => $role, 'tokens' => $tokens, 'chrome_html' => mb_substr( $html, 0, 30000 ) ];
	}

	/** Render block markup as it would appear for $post in $role. */
	private static function render_for( string $markup, string $role, \WP_Post $post ): string {
		$prev = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$simulated = Context::simulate( $role, $post );
		Tokens::push( $post );
		try {
			$html = do_blocks( $markup );
		} finally {
			Tokens::pop();
			if ( $simulated ) {
				Context::clear();
			}
			$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$prev instanceof \WP_Post ? setup_postdata( $prev ) : wp_reset_postdata();
		}
		return (string) $html;
	}

	/** Block editor: the Rank Math analysis filter (only with Rank Math, only for editable posts). */
	public static function editor_assets(): void {
		$post = get_post();
		if ( ! self::rm_active() || ! $post || ! in_array( $post->post_type, [ 'page', 'post', Entries::SERVICE, Entries::AREA ], true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		wp_enqueue_script( 'launch-builder-seo-context', LB_URL . 'assets/js/seo-context.js', [ 'wp-hooks', 'wp-api-fetch' ], LB_VERSION, true );
		wp_localize_script( 'launch-builder-seo-context', 'lbSeoContext', [ 'postId' => (int) $post->ID ] );
	}

	/** Classic editor screens get the same script. */
	public static function classic_assets( $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$post = get_post();
		if ( $post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return; // enqueue_block_editor_assets handles it.
		}
		self::editor_assets();
	}
}
