<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * build-site: a whole site from one short brief, in this order: Meta Box setup, Business Info, the look, services (with
 * their sub-services), service areas (with their area service pages), pages, menus, then the designs and layouts.
 *
 *   {
 *     "business": { "name": "", "phone": "", "email": "", "address": "", "cta_label": "", "cta_url": "" },
 *     "look": "home-services",
 *     "colors": { "primary": "#2a46c8", "accent": "#18b6b0", "dark": "#0f1c4d" },
 *     "services": [ { "title": "Air Conditioning", "summary": "", "icon": "", "text": "", "children": [ { "title": "Installation and Replacement" } ] } ],
 *     "areas": [ { "title": "Rockledge, FL", "summary": "", "text": "", "services": [ "installation-and-replacement", "repair" ] } ],
 *     "pages": [ { "title": "Home", "type": "home" }, { "title": "About", "text": "" }, { "title": "Contact", "type": "contact" } ]
 *   }
 *
 * Every section is optional. A dry run (the default) checks the brief and says what would be created, updated or kept.
 * Running the same brief twice changes nothing: services, areas and pages are found again by the key the builder gives
 * them (meta _lb_key / _lb_brief_key), and text the builder wrote is replaced only while nobody has edited it since (meta
 * _lb_brief_hash; an edited text is kept and listed). One snapshot covers the settings it changes (restore-snapshot puts
 * them back); the posts it creates are listed in the report.
 */
final class Builder {

	/** Page types a brief may give: type => the page role (home is the front page). */
	const PAGE_TYPES = [ 'page' => '', 'home' => 'home', 'contact' => 'conversion', 'legal' => 'legal', 'thanks' => 'thanks', 'services_hub' => 'services_hub', 'areas_hub' => 'areas_hub' ];

	/** Meta on pages the builder made: its key, and the hash of the text it last wrote (posts of every type). */
	const PAGE_KEY = '_lb_brief_key';
	const HASH     = '_lb_brief_hash';

	/** @var bool a build is running in this request. */
	private static $building = false;

	public static function define_tools(): void {
		Abilities::add( 'build-site', 'Build the site from a brief', 'A whole site from one short brief: business (name, phone, phone_link, email, address, maps_url, cta_label, cta_url), look (auto-shop, home-services, landscaping), colors ({primary, accent, dark} hex, optional), services ([{title, slug, summary, icon, text (Markdown), children: [sub-services, same shape]}]), areas ([{title, slug, summary, text, services: [service slugs or paths]}]: each listed service gets an area service page under the area), pages ([{title, slug, text, type: page|home|contact|legal|thanks|services_hub|areas_hub}]). Steps: Meta Box setup, Business Info, look, services, areas and their service pages, pages, menus, designs and layouts. dry_run (default true) checks the brief and lists what would be created, updated or kept; run it first. Re-running the same brief changes nothing; text edited since the builder wrote it is kept. One snapshot for the settings; created posts are listed.',
			[ 'properties' => [
				'brief'   => [ 'type' => 'object', 'description' => 'The brief (see the description; get-playbook brief has an example).' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Check and plan only (default true).' ],
			], 'required' => [ 'brief' ] ], [ __CLASS__, 'tool' ], 'manage_options' );
	}

	public static function tool( $input ) {
		$input = (array) $input;
		$brief = $input['brief'] ?? null;
		if ( is_string( $brief ) ) {
			$brief = json_decode( $brief, true );
		}
		if ( ! is_array( $brief ) ) {
			return new \WP_Error( 'lb_brief', 'brief must be an object.', [ 'status' => 400 ] );
		}
		return self::build( $brief, ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] ) );
	}

	/** Is a build running in this request? */
	public static function building(): bool {
		return self::$building;
	}

	/* ------------------------------------------------------------------ checking */

	/** Problems that stop a build: [ "path: message" ]. */
	public static function validate( array $b ): array {
		$err = [];
		$known = [ 'business', 'look', 'colors', 'services', 'areas', 'pages' ];
		foreach ( array_diff( array_keys( $b ), $known ) as $k ) {
			$err[] = $k . ': not a brief section (sections: ' . implode( ', ', $known ) . ').';
		}
		if ( isset( $b['business'] ) ) {
			if ( ! is_array( $b['business'] ) ) {
				$err[] = 'business: must be an object.';
			} else {
				foreach ( $b['business'] as $k => $v ) {
					if ( ! isset( Company::FIELDS[ $k ] ) ) {
						$err[] = 'business.' . $k . ': not a Business Info field (' . implode( ', ', array_keys( Company::FIELDS ) ) . ').';
					} elseif ( ! is_scalar( $v ) ) {
						$err[] = 'business.' . $k . ': must be text.';
					} elseif ( '' !== trim( (string) $v ) && '' === Company::clean( (string) $k, $v ) ) {
						$err[] = 'business.' . $k . ': ' . ( 'email' === $k ? 'not an email address.' : 'not a valid link.' );
					}
				}
			}
		}
		if ( isset( $b['look'] ) && ! isset( Design_Hub::LOOKS[ (string) $b['look'] ] ) ) {
			$err[] = 'look: one of ' . implode( ', ', array_keys( Design_Hub::LOOKS ) ) . '.';
		}
		foreach ( (array) ( $b['colors'] ?? [] ) as $k => $v ) {
			if ( ! in_array( $k, [ 'primary', 'accent', 'dark' ], true ) ) {
				$err[] = 'colors.' . $k . ': colours are primary, accent and dark.';
			} elseif ( ! sanitize_hex_color( (string) $v ) ) {
				$err[] = 'colors.' . $k . ': a hex colour such as #1e5bd8.';
			}
		}
		$entries = static function ( string $path, $list, bool $kids ) use ( &$err, &$entries ) {
			if ( null === $list ) {
				return;
			}
			if ( ! is_array( $list ) || array_keys( $list ) !== range( 0, count( $list ) - 1 ) ) {
				$err[] = $path . ': must be a list.';
				return;
			}
			foreach ( $list as $i => $e ) {
				$p = $path . '[' . $i . ']';
				if ( ! is_array( $e ) || '' === trim( (string) ( $e['title'] ?? '' ) ) ) {
					$err[] = $p . ': needs a title.';
					continue;
				}
				foreach ( [ 'title', 'slug', 'summary', 'icon', 'text' ] as $k ) {
					if ( isset( $e[ $k ] ) && ! is_string( $e[ $k ] ) ) {
						$err[] = $p . '.' . $k . ': must be text.';
					}
				}
				if ( isset( $e['children'] ) ) {
					$kids ? $entries( $p . '.children', $e['children'], false ) : $err[] = $p . '.children: only one level of sub-entries.';
				}
				if ( isset( $e['services'] ) && ( ! is_array( $e['services'] ) || array_filter( $e['services'], static function ( $s ) { return ! is_string( $s ); } ) ) ) {
					$err[] = $p . '.services: a list of service slugs or paths.';
				}
			}
		};
		$entries( 'services', $b['services'] ?? null, true );
		$entries( 'areas', $b['areas'] ?? null, true );
		$homes = 0;
		foreach ( (array) ( $b['pages'] ?? [] ) as $i => $pg ) {
			if ( ! is_array( $pg ) || '' === trim( (string) ( $pg['title'] ?? '' ) ) ) {
				$err[] = 'pages[' . $i . ']: needs a title.';
				continue;
			}
			$type = (string) ( $pg['type'] ?? 'page' );
			if ( ! isset( self::PAGE_TYPES[ $type ] ) ) {
				$err[] = 'pages[' . $i . '].type: one of ' . implode( ', ', array_keys( self::PAGE_TYPES ) ) . '.';
			}
			$homes += 'home' === $type ? 1 : 0;
		}
		if ( $homes > 1 ) {
			$err[] = 'pages: only one page can be the home page.';
		}
		// Area services must name services the brief or the site has.
		$known = [];
		foreach ( (array) ( $b['services'] ?? [] ) as $s ) {
			$slug = self::slug( (array) $s );
			$known[ $slug ] = true;
			foreach ( (array) ( $s['children'] ?? [] ) as $c ) {
				$known[ self::slug( (array) $c ) ] = true;
				$known[ $slug . '/' . self::slug( (array) $c ) ] = true;
			}
		}
		foreach ( (array) ( $b['areas'] ?? [] ) as $i => $a ) {
			foreach ( (array) ( is_array( $a ) ? ( $a['services'] ?? [] ) : [] ) as $ref ) {
				$ref = trim( (string) $ref, '/ ' );
				if ( ! isset( $known[ sanitize_title( $ref ) ] ) && ! isset( $known[ implode( '/', array_map( 'sanitize_title', explode( '/', $ref ) ) ) ] ) && ! Entries::service_id( $ref ) ) {
					$err[] = 'areas[' . $i . '].services: no service "' . $ref . '" in the brief or on the site.';
				}
			}
		}
		return $err;
	}

	private static function slug( array $e ): string {
		return sanitize_title( '' !== (string) ( $e['slug'] ?? '' ) ? (string) $e['slug'] : (string) ( $e['title'] ?? '' ) );
	}

	/* ------------------------------------------------------------------ building */

	/** @return array the report (dry run: the plan) */
	public static function build( array $b, bool $dry ): array {
		$errors = self::validate( $b );
		$out = [ 'dry_run' => $dry, 'ok' => ! $errors, 'errors' => $errors, 'steps' => [], 'kept_edits' => [], 'created' => [], 'warnings' => [] ];
		if ( $errors ) {
			$out['next'] = 'Fix the errors, then run the dry run again.';
			return $out;
		}
		if ( ! $dry && ! Lock::acquire( 'build', 300 ) ) {
			$out['ok'] = false;
			$out['errors'][] = 'Another build is running; try again in a few minutes.';
			return $out;
		}
		self::$building = true;
		try {
			if ( ! $dry ) {
				Compat::suppress_indexing( true );
				$out['snapshot'] = Abilities::snapshot( 'build-site', [ 'blocks_header', 'blocks_header_id' ], [ Company::OPT, Roles::OPT_HUBS, 'show_on_front', 'page_on_front', 'blogname', Design_Hub::OPT ] );
			}
			$out['steps']['meta_box'] = self::step_meta_box( $dry );
			$out['steps']['business'] = self::step_business( (array) ( $b['business'] ?? [] ), $dry );
			$out['steps']['services'] = self::step_entries( 'service', (array) ( $b['services'] ?? [] ), $dry, $out );
			$out['steps']['areas'] = self::step_entries( 'area', (array) ( $b['areas'] ?? [] ), $dry, $out );
			$out['steps']['pages'] = self::step_pages( $b, $dry, $out );
			$out['steps']['menus'] = self::step_menus( $b, $dry );
			$out['steps']['urls'] = self::step_urls( $dry );
			$out['steps']['designs'] = self::step_designs( $b, $dry );
			if ( ! $dry ) {
				$out['steps']['seo'] = Compat::rm_active() ? Compat::blueprint_seo( [], [] ) : 'Rank Math is not active';
				Cache::purge_later();
			}
		} finally {
			self::$building = false;
			if ( ! $dry ) {
				Lock::release( 'build' );
			}
		}
		$out['next'] = $dry ? 'Looks right? Run build-site again with dry_run false.' : 'Check the site (audit-site), add photos, then go-live.';
		return $out;
	}

	/** Meta Box: services, areas, their fields and Business Info listed in Meta Box (when its module and Meta Box are on). */
	private static function step_meta_box( bool $dry ) {
		if ( ! Modules::enabled( Meta_Box_Sync::class ) || ! defined( 'RWMB_VER' ) ) {
			return 'skipped (Meta Box or the Meta Box sync module is off)';
		}
		$r = Meta_Box_Sync::run( [ 'dry_run' => $dry ] );
		return is_wp_error( $r ) ? $r->get_error_message() : (string) ( $r['message'] ?? '' );
	}

	private static function step_business( array $biz, bool $dry ) {
		if ( ! $biz ) {
			return 'nothing given';
		}
		$cur = Company::values();
		$change = [];
		foreach ( $biz as $k => $v ) {
			if ( Company::clean( (string) $k, $v ) !== (string) ( $cur[ $k ] ?? '' ) ) {
				$change[] = $k;
			}
		}
		$name = trim( (string) ( $biz['name'] ?? '' ) );
		$title = '' !== $name && get_bloginfo( 'name' ) !== $name; // The site title follows the business name (titles, the knowledge graph).
		if ( $dry || ( ! $change && ! $title ) ) {
			$what = array_merge( $change, $title ? [ 'site title' ] : [] );
			return $what ? 'would set ' . implode( ', ', $what ) : 'unchanged';
		}
		$saved = [];
		if ( $change ) {
			$saved = Company::update( array_intersect_key( $biz, array_flip( $change ) ) )['saved'];
			Company::changed();
		}
		if ( $title ) {
			update_option( 'blogname', sanitize_text_field( $name ) );
			$saved[] = 'site title';
		}
		return 'set ' . implode( ', ', $saved );
	}

	/**
	 * Services or areas (and one level of children). Keys: brief:{type}:{path}. Text and summary are written while they
	 * are what the builder last wrote (or the entry is new).
	 */
	private static function step_entries( string $type, array $list, bool $dry, array &$out ): array {
		$pt = 'area' === $type ? Entries::AREA : Entries::SERVICE;
		$rows = [];
		$walk = static function ( array $items, string $parent_path ) use ( &$walk, &$rows, &$out, $type, $pt, $dry ) {
			foreach ( $items as $i => $e ) {
				$e = (array) $e;
				$slug = self::slug( $e );
				$path = '' !== $parent_path ? $parent_path . '/' . $slug : $slug;
				$key = 'brief:' . $type . ':' . $path;
				$found = Entries::find_by_key( $pt, $key );
				$in = [ 'title' => (string) $e['title'], 'key' => $key, 'order' => $i ];
				if ( '' !== (string) ( $e['slug'] ?? '' ) ) {
					$in['slug'] = $slug;
				}
				$in['parent'] = '' !== $parent_path ? (string) ( Entries::find_by_key( $pt, 'brief:' . $type . ':' . $parent_path ) ?: $parent_path ) : 'top';
				if ( isset( $e['icon'] ) ) {
					$in['icon'] = (string) $e['icon'];
				}
				$text = (string) ( $e['text'] ?? '' );
				$summary = (string) ( $e['summary'] ?? '' );
				$kept = [];
				if ( $found && self::edited( $found ) ) {
					$kept[] = 'text and summary'; // Changed since the builder wrote it: left alone.
				} else {
					if ( '' !== $text ) {
						$in['markdown'] = $text;
					}
					if ( '' !== $summary ) {
						$in['summary'] = $summary;
					}
				}
				if ( 'area' === $type && isset( $e['services'] ) ) {
					$in['services'] = array_values( array_map( 'strval', (array) $e['services'] ) );
				}
				$row = [ 'path' => $path, 'action' => $found ? 'update' : 'create' ];
				if ( $kept ) {
					$out['kept_edits'][] = $type . ' ' . $path . ': ' . implode( ', ', $kept );
				}
				if ( ! $dry ) {
					$r = 'area' === $type ? Entries::add_area( $in ) : Entries::add_service( $in );
					if ( is_wp_error( $r ) ) {
						$row['action'] = 'failed';
						$row['error'] = $r->get_error_message();
					} else {
						$id = (int) ( $r['entries'][0]['id'] ?? 0 );
						$row['id'] = $id;
						if ( ! $found && $id ) {
							$out['created'][] = $id;
						}
						if ( $id && ! $kept ) {
							self::remember( $id );
						}
						foreach ( (array) ( $r['entries'][0]['warnings'] ?? [] ) as $w ) {
							$out['warnings'][] = $type . ' ' . $path . ': ' . $w;
						}
						foreach ( (array) ( $r['errors'] ?? [] ) as $er ) {
							$out['warnings'][] = $type . ' ' . $path . ': ' . ( $er['error'] ?? '' );
						}
					}
				}
				$rows[] = $row;
				if ( ! empty( $e['children'] ) ) {
					$walk( (array) $e['children'], $path );
				}
			}
		};
		$walk( array_values( $list ), '' );
		return $rows;
	}

	/** Pages: found by key (page:{slug}), else by path; text written while unedited; roles, hubs and the front page set. */
	private static function step_pages( array $b, bool $dry, array &$out ): array {
		$pages = array_values( (array) ( $b['pages'] ?? [] ) );
		$types = array_column( array_map( static function ( $p ) { return (array) $p; }, $pages ), 'type' );
		if ( ! empty( $b['areas'] ) && ! in_array( 'areas_hub', $types, true ) && ! Roles::hubs()['areas'] ) {
			$pages[] = [ 'title' => 'Service Areas', 'slug' => 'service-areas', 'type' => 'areas_hub' ]; // Areas need their hub page.
		}
		$rows = [];
		foreach ( $pages as $i => $pg ) {
			$pg = (array) $pg;
			$type = (string) ( $pg['type'] ?? 'page' );
			$slug = self::slug( $pg );
			$key = 'page:' . $slug;
			$ids = get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => self::PAGE_KEY, 'meta_value' => $key ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$found = $ids ? (int) $ids[0] : 0;
			if ( ! $found ) {
				$p = get_page_by_path( $slug, OBJECT, 'page' );
				$found = $p && 'trash' !== $p->post_status ? (int) $p->ID : 0;
			}
			$row = [ 'title' => (string) $pg['title'], 'type' => $type, 'action' => $found ? 'update' : 'create' ];
			$text = (string) ( $pg['text'] ?? '' );
			$write_text = '' !== $text && ( ! $found || ! self::edited( $found ) );
			if ( $found && '' !== $text && ! $write_text ) {
				$out['kept_edits'][] = 'page ' . $slug . ': text';
			}
			if ( $dry ) {
				$rows[] = $row;
				continue;
			}
			$args = [ 'post_type' => 'page', 'post_title' => (string) $pg['title'], 'post_status' => 'publish', 'menu_order' => $i ];
			if ( $write_text ) {
				$args['post_content'] = wp_slash( Content::from_markdown( $text, 'page' === $type ) );
			}
			if ( ! $found ) {
				$args['post_name'] = $slug;
				$id = wp_insert_post( $args, true );
			} else {
				$args['ID'] = $found;
				unset( $args['post_status'] ); // An existing page keeps its status.
				$id = wp_update_post( $args, true );
			}
			if ( is_wp_error( $id ) ) {
				$row['action'] = 'failed';
				$row['error'] = $id->get_error_message();
				$rows[] = $row;
				continue;
			}
			$id = (int) $id;
			$row['id'] = $id;
			update_post_meta( $id, self::PAGE_KEY, $key );
			if ( ! $found ) {
				$out['created'][] = $id;
			}
			if ( $write_text || ! $found ) {
				self::remember( $id );
			}
			$role = self::PAGE_TYPES[ $type ];
			if ( 'home' === $role ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			} elseif ( '' !== $role ) {
				$r = Roles::set_role_tool( [ 'post_id' => $id, 'role' => $role ] );
				if ( is_wp_error( $r ) ) {
					$out['warnings'][] = 'page ' . $slug . ': ' . $r->get_error_message();
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** The main menu: Services (live, two levels), Service areas (live), then the other pages in brief order. */
	private static function step_menus( array $b, bool $dry ) {
		$items = [];
		$hubs = Roles::hubs();
		if ( ! empty( $b['services'] ) || Entries::query( 'service', [ 'limit' => 1 ] ) ) {
			$items[] = [ 'label' => 'Services', 'auto' => 'services', 'depth' => 2, 'limit' => 0 ] + ( $hubs['services'] ? [ 'post_id' => $hubs['services'] ] : [] );
		}
		if ( ! empty( $b['areas'] ) || Entries::query( 'area', [ 'limit' => 1 ] ) ) {
			$items[] = [ 'label' => 'Service Areas', 'auto' => 'areas', 'limit' => 12 ] + ( $hubs['areas'] ? [ 'post_id' => $hubs['areas'] ] : [] );
		}
		foreach ( (array) ( $b['pages'] ?? [] ) as $pg ) {
			$pg = (array) $pg;
			$type = (string) ( $pg['type'] ?? 'page' );
			if ( in_array( $type, [ 'home', 'legal', 'thanks', 'services_hub', 'areas_hub' ], true ) ) {
				continue;
			}
			$p = get_page_by_path( self::slug( $pg ), OBJECT, 'page' );
			$items[] = [ 'label' => (string) $pg['title'], 'url' => $p ? (string) get_permalink( $p ) : '#' ] + ( $p ? [ 'post_id' => (int) $p->ID ] : [] );
		}
		if ( ! $items ) {
			return 'nothing to put in a menu';
		}
		if ( $dry ) {
			return 'would set the main menu: ' . implode( ', ', array_column( $items, 'label' ) );
		}
		$r = Nav::set_navigation( [ 'key' => 'main-menu', 'items' => $items ] );
		return is_wp_error( $r ) ? $r->get_error_message() : 'main menu ' . (int) $r['navigation_id'] . ': ' . implode( ', ', array_column( $items, 'label' ) );
	}

	/** Services at the root of the site (the URLs module). */
	private static function step_urls( bool $dry ) {
		if ( ! Modules::enabled( Permalinks::class ) ) {
			return 'URLs module off: services stay under /services/';
		}
		if ( $dry ) {
			return 'services at the root (/air-conditioning/...), old URLs redirected';
		}
		return Permalinks::migrate_root_services( null, 0 )['report'] ?? '';
	}

	/** The look's designs, colours and layouts (Design hub), then the brief's own colours on top. */
	private static function step_designs( array $b, bool $dry ) {
		$look = (string) ( $b['look'] ?? ( Design_Hub::current_look() ?: 'home-services' ) );
		$r = Design_Hub::set( [ 'look' => $look, 'dry_run' => $dry ] );
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		$out = [ 'look' => $look, 'pieces' => array_map( static function ( $p ) { return $p['action'] . ( '' !== $p['note'] ? ': ' . $p['note'] : '' ); }, $r['pieces'] ) ];
		$colors = (array) ( $b['colors'] ?? [] );
		if ( $colors ) {
			$pal = array_filter( [ 'palette1' => $colors['primary'] ?? '', 'palette2' => $colors['accent'] ?? '', 'palette3' => $colors['dark'] ?? '' ] );
			if ( ! $dry ) {
				Design::set_brand( [ 'palette' => $pal ] );
			}
			$out['colors'] = $pal;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ edits */

	/** The text and summary of a post as the builder compares them. */
	private static function fingerprint( int $id ): string {
		$p = get_post( $id );
		return $p ? md5( (string) $p->post_content . '|' . (string) $p->post_excerpt ) : '';
	}

	/** Remember what the builder wrote. */
	private static function remember( int $id ): void {
		update_post_meta( $id, self::HASH, self::fingerprint( $id ) );
	}

	/** Changed since the builder last wrote it (a post it never wrote counts as changed: it is someone's own). */
	private static function edited( int $id ): bool {
		$h = (string) get_post_meta( $id, self::HASH, true );
		return '' === $h ? '' !== trim( (string) get_post_field( 'post_content', $id ) ) : $h !== self::fingerprint( $id );
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function playbook( array &$pb ): void {
		$pb['brief'] = [
			'tool'    => 'build-site { brief, dry_run }: dry run first, then dry_run false. Every section is optional; re-running is safe.',
			'example' => [
				'business' => [ 'name' => 'Bayside Air', 'phone' => '(321) 555-0142', 'email' => 'hello@baysideair.com', 'address' => '12 Harbor Rd, Rockledge, FL 32955', 'cta_label' => 'Get a Free Quote', 'cta_url' => '/contact/' ],
				'look'     => 'home-services',
				'colors'   => [ 'primary' => '#1e5bd8' ],
				'services' => [ [ 'title' => 'Air Conditioning', 'summary' => 'Cooling repair, replacement and tune-ups.', 'icon' => 'fe_wind', 'text' => '## Fast, honest AC service', 'children' => [ [ 'title' => 'Installation and Replacement' ], [ 'title' => 'Repair' ] ] ] ],
				'areas'    => [ [ 'title' => 'Rockledge, FL', 'summary' => 'AC service across Rockledge.', 'services' => [ 'installation-and-replacement', 'repair' ] ] ],
				'pages'    => [ [ 'title' => 'Home', 'type' => 'home' ], [ 'title' => 'About', 'text' => '## Locally owned since 2009' ], [ 'title' => 'Contact', 'type' => 'contact' ] ],
			],
		];
	}
}
