<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Meta Box sync: services, service areas and service categories as Meta Box post types and a taxonomy, and the Card
 * details fields as Meta Box field groups, so they are listed and edited under Meta Box like the site's other types.
 *
 *  lb_service, lb_area   a post of type mb-post-type (MB Custom Post Types; its settings are JSON in post_content)
 *  lb_service_cat        a post of type mb-taxonomy
 *  card-details          a Meta Box Builder field group (post type meta-box): _lb_icon on services and areas
 *  services-offered      a field group: _lb_services on areas (Post field, multiple, one stored row per service)
 *
 * Who registers: once a type is in Meta Box, Meta Box registers it (init 0) with its settings and Entries::register()
 * leaves it alone, keeping only what Launch Builder relies on (Entries::type_args()). A copy that is drafted, trashed or
 * not read (MB Custom Post Types off) simply hands the type back to Launch Builder. The field groups use the ids the cards
 * read, so Meta Box edits the same stored values and the Card details box steps aside (Entries::meta_box_owns()).
 *
 * Copies are built from Launch Builder's own definitions (Entries::definitions(), every label filled in) and the field
 * groups below, converted with Meta Box Builder's own parser and saved the way its Save button does. Each copy has a
 * Ledger row (meta-box-sync:<key>), so a sync tells its own copies from ones changed in Meta Box:
 *  missing      not in Meta Box: the sync adds it
 *  synced       Launch Builder's copy, unchanged since: the sync updates it when Launch Builder's definition changes
 *  edited       changed in Meta Box: kept (force overwrites it)
 *  off          the copy is a draft or in the Trash: Launch Builder registers the type; kept (force publishes it again)
 *  renamed      the copy's slug was changed in Meta Box: Launch Builder registers the type again; force puts the slug back
 *  yours        made in Meta Box by hand (or the fields come from code or another field group): never touched
 *  unavailable  this site's Meta Box cannot hold it (note says why)
 * Writes are dry runs unless asked (sync-meta-box, the notice on Meta Box's screens, wp launch-builder meta-box-sync);
 * copies that are updated are snapshotted first; undo moves this sync's copies to the Trash. Saving, trashing or
 * restoring one of these types in Meta Box flushes the rewrite rules on the next request (Meta Box does not).
 */
final class Meta_Box_Sync {

	const MODULE = [
		'slug'        => 'meta-box-sync',
		'label'       => 'Meta Box sync',
		'description' => 'Lists Services, Service areas and Service categories under Meta Box > Post Types and Taxonomies, and the Card details fields under Meta Box > Custom Fields, so they are edited there. Launch Builder keeps the few settings its lists and URLs need.',
		'note'        => 'Needs Meta Box with MB Custom Post Types (and MB Builder for the fields), as in Meta Box Lite or AIO. Nothing changes until you add them from a Meta Box screen.',
		'toggle'      => true,
		'default'     => true,
	];

	/** Ledger key prefix (one row per copy). */
	const LEDGER = 'meta-box-sync:';

	/** Meta Box's own post types: where each kind of copy lives. */
	const MB_TYPES = [ 'post_type' => 'mb-post-type', 'taxonomy' => 'mb-taxonomy', 'fields' => 'meta-box' ];

	/** Meta keys of a field group (Meta Box Builder): what a copy's hash covers. */
	const FIELD_META = [ 'settings', 'fields' ];

	/** Label keys Meta Box's post type editor shows (others are left to WordPress). */
	const PT_LABELS = [ 'name', 'singular_name', 'add_new', 'add_new_item', 'edit_item', 'new_item', 'view_item', 'view_items', 'search_items', 'not_found', 'not_found_in_trash', 'parent_item_colon', 'all_items', 'archives', 'attributes', 'insert_into_item', 'uploaded_to_this_item', 'featured_image', 'set_featured_image', 'remove_featured_image', 'use_featured_image', 'menu_name', 'filter_items_list', 'filter_by_date', 'items_list_navigation', 'items_list', 'item_published', 'item_published_privately', 'item_reverted_to_draft', 'item_scheduled', 'item_updated' ];

	/** Label keys Meta Box's taxonomy editor shows. */
	const TAX_LABELS = [ 'name', 'singular_name', 'menu_name', 'search_items', 'popular_items', 'all_items', 'parent_item', 'parent_item_colon', 'edit_item', 'view_item', 'update_item', 'add_new_item', 'new_item_name', 'separate_items_with_commas', 'add_or_remove_items', 'choose_from_most_used', 'not_found', 'no_terms', 'filter_by_item', 'items_list_navigation', 'items_list', 'most_used', 'back_to_items' ];

	const ACTION = 'lb_mb_sync';
	const FLASH  = 'lb_mb_sync_flash_';

	public static function boot(): void {
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		foreach ( [ 'mb-post-type', 'mb-taxonomy' ] as $type ) {
			add_action( 'save_post_' . $type, [ __CLASS__, 'mb_saved' ], 10, 2 );
		}
		add_action( 'transition_post_status', [ __CLASS__, 'mb_status_changed' ], 10, 3 );
		add_action( 'deleted_post', [ __CLASS__, 'mb_deleted' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ what Meta Box can hold */

	/** { post_types, fields } this site's Meta Box can hold, and why not. Meaningful after init (Meta Box registers at 0). */
	public static function support(): array {
		$mb = defined( 'RWMB_VER' );
		$types = $mb && post_type_exists( self::MB_TYPES['post_type'] ) && post_type_exists( self::MB_TYPES['taxonomy'] );
		$fields = $mb && post_type_exists( self::MB_TYPES['fields'] ) && class_exists( '\MBBParser\Unparsers\MetaBox' ) && class_exists( '\MBB\RestApi\Save' ) && method_exists( '\MBB\RestApi\Save', 'parse' );
		$why = '';
		if ( ! $mb ) {
			$why = __( 'Meta Box is not active.', 'launch-builder' );
		} elseif ( ! $types && ! $fields ) {
			$why = __( 'Meta Box is active without MB Custom Post Types or MB Builder (both come with Meta Box Lite and Meta Box AIO).', 'launch-builder' );
		} elseif ( ! $types ) {
			$why = __( 'MB Custom Post Types is not active, so post types and taxonomies cannot be listed in Meta Box.', 'launch-builder' );
		} elseif ( ! $fields ) {
			$why = __( 'MB Builder is not active, so the fields cannot be listed under Meta Box > Custom Fields.', 'launch-builder' );
		}
		return [ 'meta_box' => $mb, 'post_types' => $types, 'fields' => $fields, 'note' => $why ];
	}

	/* ------------------------------------------------------------------ the objects */

	/**
	 * The field groups, in Meta Box's own format (what a field group exports as JSON): key => definition. The ids are the
	 * ones the cards read; descriptions say what each value does.
	 */
	public static function field_groups(): array {
		return [
			'card-details'     => [
				'id'         => 'launch-builder-card-details',
				'title'      => 'Card details',
				'post_types' => [ Entries::SERVICE, Entries::AREA ],
				'context'    => 'side',
				'priority'   => 'default',
				'fields'     => [
					[ 'id' => '_lb_icon', 'name' => 'Icon', 'type' => 'text', 'desc' => 'The icon on cards and lists: a Kadence icon name such as fe_settings or fe_mapPin (letters, digits and _). The short summary on cards is the Excerpt; the order is the Order box, then the title.' ],
				],
			],
			'services-offered' => [
				'id'         => 'launch-builder-services-offered',
				'title'      => 'Services offered here',
				'post_types' => [ Entries::AREA ],
				'context'    => 'side',
				'priority'   => 'default',
				'fields'     => [
					[ 'id' => '_lb_services', 'name' => 'Services', 'type' => 'post', 'post_type' => [ Entries::SERVICE ], 'field_type' => 'select_advanced', 'multiple' => true, 'clone' => false, 'placeholder' => 'Every service', 'desc' => 'The services this area page and its lists show. None = every service.' ],
				],
			],
		];
	}

	/** Every object the sync handles: key => { kind (post_type | taxonomy | fields), label }. */
	public static function objects(): array {
		$out = [];
		foreach ( Entries::definitions() as $name => $def ) {
			$labels = (array) ( $def['args']['labels'] ?? [] );
			$out[ $name ] = [ 'kind' => $def['kind'], 'label' => (string) ( $labels['name'] ?? $name ) ];
		}
		foreach ( self::field_groups() as $key => $g ) {
			$out[ $key ] = [ 'kind' => 'fields', 'label' => sprintf( '%s (%s)', $g['title'], __( 'fields', 'launch-builder' ) ) ];
		}
		return $out;
	}

	/** Meta Box post type settings (its editor's JSON) for lb_service or lb_area, from Launch Builder's definition. */
	public static function post_type_settings( string $name ): array {
		$pt = Entries::definition_object( $name );
		if ( ! $pt instanceof \WP_Post_Type ) {
			return [];
		}
		$labels = (array) $pt->labels;
		$rewrite = is_array( $pt->rewrite ) ? $pt->rewrite : [];
		$icon = (string) ( $pt->menu_icon ?: 'dashicons-admin-generic' );
		$settings = [
			'slug'                => $name,
			'function_name'       => 'launch_builder_register_' . $name,
			'text_domain'         => 'launch-builder',
			'label'               => (string) $pt->label,
			'labels'              => array_map( 'strval', array_intersect_key( $labels, array_flip( self::PT_LABELS ) ) ),
			'description'         => (string) $pt->description,
			'public'              => (bool) $pt->public,
			'hierarchical'        => (bool) $pt->hierarchical,
			'exclude_from_search' => (bool) $pt->exclude_from_search,
			'publicly_queryable'  => (bool) $pt->publicly_queryable,
			'show_ui'             => (bool) $pt->show_ui,
			'show_in_menu'        => is_string( $pt->show_in_menu ) ? $pt->show_in_menu : (bool) $pt->show_in_menu,
			'show_in_nav_menus'   => (bool) $pt->show_in_nav_menus,
			'show_in_admin_bar'   => (bool) $pt->show_in_admin_bar,
			'show_in_rest'        => (bool) $pt->show_in_rest,
			'rest_base'           => is_string( $pt->rest_base ) ? $pt->rest_base : '',
			'menu_position'       => null === $pt->menu_position ? '' : (int) $pt->menu_position,
			'icon_type'           => 'dashicons',
			'icon'                => $icon,
			'capability_type'     => is_string( $pt->capability_type ) ? $pt->capability_type : 'post',
			'supports'            => array_values( (array) ( Entries::definitions()[ $name ]['args']['supports'] ?? [] ) ),
			'taxonomies'          => Entries::SERVICE === $name ? [ Entries::CAT ] : [],
			'has_archive'         => false,
			'archive_slug'        => '',
			'rewrite'             => [ 'slug' => (string) ( $rewrite['slug'] ?? Entries::BASES[ $name ] ), 'with_front' => ! empty( $rewrite['with_front'] ) ],
			'query_var'           => false !== $pt->query_var,
			'can_export'          => (bool) $pt->can_export,
			'delete_with_user'    => (bool) $pt->delete_with_user,
		];
		if ( 0 === strpos( $icon, 'data:image/svg' ) ) {
			$settings['icon_type'] = 'svg';
			$settings['icon_svg'] = $icon;
		} elseif ( 0 !== strpos( $icon, 'dashicons-' ) ) {
			$settings['icon_type'] = 'custom';
			$settings['icon_custom'] = $icon;
		}
		return $settings;
	}

	/** Meta Box taxonomy settings for lb_service_cat, from Launch Builder's definition. */
	public static function taxonomy_settings( string $name ): array {
		$tx = Entries::definition_object( $name );
		if ( ! $tx instanceof \WP_Taxonomy ) {
			return [];
		}
		$rewrite = is_array( $tx->rewrite ) ? $tx->rewrite : [];
		$cap = (array) $tx->cap;
		return [
			'slug'               => $name,
			'types'              => array_values( (array) $tx->object_type ),
			'function_name'      => 'launch_builder_register_' . $name,
			'text_domain'        => 'launch-builder',
			'labels'             => array_map( 'strval', array_intersect_key( (array) $tx->labels, array_flip( self::TAX_LABELS ) ) ),
			'description'        => (string) $tx->description,
			'public'             => (bool) $tx->public,
			'publicly_queryable' => (bool) $tx->publicly_queryable,
			'hierarchical'       => (bool) $tx->hierarchical,
			'show_ui'            => (bool) $tx->show_ui,
			'show_in_menu'       => (bool) $tx->show_in_menu,
			'show_in_nav_menus'  => (bool) $tx->show_in_nav_menus,
			'meta_box_cb'        => false === $tx->meta_box_cb ? false : ( $tx->hierarchical ? 'post_categories_meta_box' : 'post_tags_meta_box' ),
			'show_in_rest'       => (bool) $tx->show_in_rest,
			'rest_base'          => is_string( $tx->rest_base ) ? $tx->rest_base : '',
			'show_tagcloud'      => (bool) $tx->show_tagcloud,
			'show_in_quick_edit' => (bool) $tx->show_in_quick_edit,
			'show_admin_column'  => (bool) $tx->show_admin_column,
			'rewrite'            => [ 'slug' => (string) ( $rewrite['slug'] ?? Entries::BASES[ $name ] ), 'with_front' => ! empty( $rewrite['with_front'] ), 'hierarchical' => ! empty( $rewrite['hierarchical'] ) ],
			'query_var'          => false !== $tx->query_var,
			'sort'               => (bool) $tx->sort,
			'capabilities'       => [
				'manage_terms' => (string) ( $cap['manage_terms'] ?? 'manage_categories' ),
				'edit_terms'   => (string) ( $cap['edit_terms'] ?? 'manage_categories' ),
				'delete_terms' => (string) ( $cap['delete_terms'] ?? 'manage_categories' ),
				'assign_terms' => (string) ( $cap['assign_terms'] ?? 'edit_posts' ),
			],
		];
	}

	/** What Launch Builder needs from a copy (shown on the copy's edit screen and returned by the tool). */
	public static function kept( string $key ): array {
		if ( in_array( $key, [ Entries::SERVICE, Entries::AREA ], true ) ) {
			return [
				__( 'Public, publicly queryable, shown in the admin and in the REST API (the block editor and the lists need it).', 'launch-builder' ),
				__( 'No archive: the services and service areas hubs are pages.', 'launch-builder' ),
				Entries::AREA === $key ? __( 'Hierarchical, so an area can sit under another.', 'launch-builder' ) : __( 'Not hierarchical.', 'launch-builder' ),
				/* translators: %s: list of editor features */
				sprintf( __( 'Supports %s (more can be added).', 'launch-builder' ), implode( ', ', Entries::KEEP_SUPPORTS ) ),
				__( 'A URL base: the one set here, else the default.', 'launch-builder' ),
				/* translators: %s: post type slug */
				sprintf( __( 'Keep the slug %s: the lists, menus and URLs use this exact post type (renamed, it would be a new, empty one).', 'launch-builder' ), $key ),
			];
		}
		if ( Entries::CAT === $key ) {
			return [
				__( 'Attached to Services, hierarchical, public and in the REST API.', 'launch-builder' ),
				__( 'A URL base: the one set here, else the default.', 'launch-builder' ),
				/* translators: %s: taxonomy slug */
				sprintf( __( 'Keep the slug %s: category pages and lists use this exact taxonomy.', 'launch-builder' ), $key ),
			];
		}
		$ids = wp_list_pluck( self::field_groups()[ $key ]['fields'] ?? [], 'id' );
		/* translators: %s: field ids */
		return [ sprintf( __( 'Keep the field ids %s and their type: the cards read these exact meta keys. Labels, descriptions and placement are yours to change.', 'launch-builder' ), implode( ', ', $ids ) ) ];
	}

	/* ------------------------------------------------------------------ status */

	/**
	 * Where each object stands: key => { kind, label, state, id, slug, edit_url, note }. See the class comment for the states.
	 *
	 * @return array<string,array>
	 */
	public static function status(): array {
		$support = self::support();
		$out = [];
		foreach ( self::objects() as $key => $o ) {
			$row = [ 'kind' => $o['kind'], 'label' => $o['label'], 'state' => 'missing', 'id' => 0, 'slug' => '', 'edit_url' => '', 'note' => '' ];
			if ( ! ( 'fields' === $o['kind'] ? $support['fields'] : $support['post_types'] ) ) {
				$row['state'] = 'unavailable';
				$row['note'] = (string) $support['note'];
				$out[ $key ] = $row;
				continue;
			}
			$mine = self::copy( $key, $o['kind'] );
			if ( $mine ) {
				$row['id'] = (int) $mine->ID;
				$row['slug'] = self::slug_of( $mine, $o['kind'] );
				$row['edit_url'] = (string) get_edit_post_link( $mine->ID, 'raw' );
				if ( 'publish' !== $mine->post_status ) {
					$row['state'] = 'off';
					$row['note'] = 'trash' === $mine->post_status ? __( 'In the Trash: Launch Builder registers it.', 'launch-builder' ) : __( 'Not published: Launch Builder registers it.', 'launch-builder' );
				} elseif ( 'fields' !== $o['kind'] && $row['slug'] !== $key ) {
					$row['state'] = 'renamed';
					/* translators: 1: new slug, 2: the slug Launch Builder needs */
					$row['note'] = sprintf( __( 'Its slug was changed to %1$s, so it is a new, empty type; Launch Builder registers %2$s again.', 'launch-builder' ), $row['slug'], $key );
				} elseif ( Ledger::is_edited( self::LEDGER . $key, self::hash( (int) $mine->ID, $o['kind'] ) ) ) {
					$row['state'] = 'edited';
					$row['note'] = __( 'Changed in Meta Box: Meta Box\'s settings are used.', 'launch-builder' );
				} else {
					$row['state'] = 'synced';
				}
			} else {
				$theirs = self::foreign( $key, $o['kind'] );
				if ( $theirs ) {
					$row['state'] = 'yours';
					$row['id'] = (int) $theirs->ID;
					$row['slug'] = self::slug_of( $theirs, $o['kind'] );
					$row['edit_url'] = (string) get_edit_post_link( $theirs->ID, 'raw' );
					$row['note'] = __( 'Already in Meta Box (made there): left as it is.', 'launch-builder' );
				} elseif ( 'fields' === $o['kind'] && self::fields_owned( $key ) ) {
					$row['state'] = 'yours';
					$row['note'] = __( 'Meta Box already has these fields (from another field group or from code): left as they are.', 'launch-builder' );
				}
			}
			$out[ $key ] = $row;
		}
		return $out;
	}

	/** Objects the sync would add now (state missing). */
	public static function missing(): array {
		return array_keys( array_filter( self::status(), static function ( $r ) { return 'missing' === $r['state']; } ) );
	}

	/** This sync's copy of an object (its Ledger row's post, in any status but deleted), or null. */
	private static function copy( string $key, string $kind ): ?\WP_Post {
		$row = Ledger::get( self::LEDGER . $key );
		$p = $row ? get_post( (int) $row['id'] ) : null;
		if ( ! $p instanceof \WP_Post || self::MB_TYPES[ $kind ] !== $p->post_type ) {
			if ( $row ) {
				Ledger::forget( self::LEDGER . $key ); // Deleted for good: the row is stale.
			}
			return null;
		}
		return $p;
	}

	/** A published Meta Box object for this key that this sync did not make. */
	private static function foreign( string $key, string $kind ): ?\WP_Post {
		$posts = get_posts( [ 'post_type' => self::MB_TYPES[ $kind ], 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true, 'update_post_term_cache' => false ] );
		$want = 'fields' === $kind ? self::field_groups()[ $key ]['id'] : $key;
		foreach ( $posts as $p ) {
			if ( self::slug_of( $p, $kind ) === $want ) {
				return $p;
			}
		}
		return null;
	}

	/** The slug a Meta Box object registers: the post type or taxonomy name in its settings, or a field group's id. */
	private static function slug_of( \WP_Post $p, string $kind ): string {
		if ( 'fields' === $kind ) {
			return (string) $p->post_name;
		}
		$s = json_decode( (string) $p->post_content, true );
		return is_array( $s ) ? (string) ( $s['slug'] ?? '' ) : '';
	}

	/** Does Meta Box register every field of this group for every post type of it already (another group or code)? */
	private static function fields_owned( string $key ): bool {
		$g = self::field_groups()[ $key ];
		foreach ( $g['post_types'] as $pt ) {
			foreach ( $g['fields'] as $f ) {
				if ( ! Entries::meta_box_owns( $f['id'], $pt ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Hash of a copy as stored (what the Ledger compares). */
	private static function hash( int $id, string $kind ): string {
		return 'fields' === $kind ? Ledger::hash_post( $id, self::FIELD_META ) : Ledger::hash_post( $id );
	}

	/* ------------------------------------------------------------------ sync */

	/**
	 * Add the objects to Meta Box (or plan it). $args: dry_run (default true), force (true or a list of keys: overwrite
	 * edited, off and renamed copies), only (keys), undo (move this sync's copies to the Trash).
	 *
	 * @return array|\WP_Error { dry_run, support, objects: key => { kind, label, state, action, id, edit_url, note }, snapshot, message }
	 */
	public static function run( array $args = [] ) {
		$dry = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		if ( ! empty( $args['undo'] ) ) {
			return self::undo( $dry );
		}
		$only = array_values( array_filter( array_map( 'strval', (array) ( $args['only'] ?? [] ) ) ) );
		$force = $args['force'] ?? false;
		$forced = static function ( string $key ) use ( $force ): bool {
			return true === $force || ( is_array( $force ) && ( in_array( $key, $force, true ) || in_array( 'all', $force, true ) ) );
		};
		$status = self::status();
		$plan = [];
		foreach ( $status as $key => $row ) {
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$action = 'none';
			switch ( $row['state'] ) {
				case 'missing':
					$action = 'create';
					break;
				case 'synced':
					$action = self::current_matches( $key, $row ) ? 'unchanged' : 'update';
					break;
				case 'edited':
				case 'off':
				case 'renamed':
					$action = $forced( $key ) ? 'update' : 'keep';
					break;
				case 'yours':
					$action = 'leave';
					break;
				default:
					$action = 'skip';
			}
			$plan[ $key ] = $row + [ 'action' => $action ];
		}
		$out = [ 'dry_run' => $dry, 'support' => self::support(), 'objects' => $plan, 'snapshot' => '' ];
		$todo = array_filter( $plan, static function ( $r ) { return in_array( $r['action'], [ 'create', 'update' ], true ); } );
		if ( $dry || ! $todo ) {
			$out['message'] = $todo ? self::summary( $todo, true ) : __( 'Nothing to add or update in Meta Box.', 'launch-builder' );
			return $out;
		}
		if ( ! Lock::acquire( 'meta-box-sync', 120 ) ) {
			return new \WP_Error( 'lb_busy', __( 'A Meta Box sync is already running. Try again in a minute.', 'launch-builder' ), [ 'status' => 409 ] );
		}
		try {
			$updates = array_filter( $todo, static function ( $r ) { return 'update' === $r['action'] && $r['id']; } );
			if ( $updates ) {
				$posts = [];
				foreach ( $updates as $key => $r ) {
					$posts[] = [ 'id' => (int) $r['id'], 'meta' => 'fields' === $r['kind'] ? array_merge( self::FIELD_META, [ 'meta_box' ] ) : [], 'ledger' => self::LEDGER . $key ];
				}
				$out['snapshot'] = Abilities::snapshot( 'sync-meta-box', [], [], $posts );
			}
			foreach ( $todo as $key => $r ) {
				$id = 'fields' === $r['kind'] ? self::write_fields( $key, (int) $r['id'] ) : self::write_type( $key, $r['kind'], (int) $r['id'] );
				if ( is_wp_error( $id ) ) {
					$out['objects'][ $key ]['action'] = 'failed';
					$out['objects'][ $key ]['note'] = $id->get_error_message();
					continue;
				}
				$p = get_post( $id );
				Ledger::record( self::LEDGER . $key, self::MB_TYPES[ $r['kind'] ], $id, self::hash( $id, $r['kind'] ), 'publish', $p ? (string) $p->post_title : '' );
				$out['objects'][ $key ]['id'] = $id;
				$out['objects'][ $key ]['state'] = 'synced';
				$out['objects'][ $key ]['note'] = '';
				$out['objects'][ $key ]['edit_url'] = (string) get_edit_post_link( $id, 'raw' );
			}
		} finally {
			Lock::release( 'meta-box-sync' );
		}
		self::changed();
		$out['message'] = self::summary( $todo, false );
		return $out;
	}

	/** Is a synced copy what Launch Builder would write now? */
	private static function current_matches( string $key, array $row ): bool {
		if ( 'fields' === $row['kind'] ) {
			$mb = get_post_meta( (int) $row['id'], 'meta_box', true );
			return is_array( $mb ) && Ledger::hash_value( self::comparable_group( $mb ) ) === Ledger::hash_value( self::comparable_group( self::field_groups()[ $key ] ) );
		}
		$now = json_decode( (string) get_post_field( 'post_content', (int) $row['id'], 'raw' ), true );
		return is_array( $now ) && Ledger::hash_value( $now ) === Ledger::hash_value( self::settings( $key, $row['kind'] ) );
	}

	/** The parts of a field group definition the sync owns (ids, titles, types, fields), for comparing. */
	private static function comparable_group( array $g ): array {
		$fields = [];
		foreach ( (array) ( $g['fields'] ?? [] ) as $f ) {
			$f = (array) $f;
			$f = array_intersect_key( $f, array_flip( [ 'id', 'name', 'type', 'desc', 'post_type', 'field_type', 'multiple', 'clone', 'placeholder' ] ) );
			$fields[] = array_filter( $f, static function ( $v ) { return false !== $v && '' !== $v && null !== $v; } ); // Meta Box leaves out values that are its defaults.
		}
		return [ 'id' => (string) ( $g['id'] ?? '' ), 'title' => (string) ( $g['title'] ?? '' ), 'post_types' => array_values( (array) ( $g['post_types'] ?? [] ) ), 'fields' => $fields ];
	}

	private static function settings( string $key, string $kind ): array {
		return 'taxonomy' === $kind ? self::taxonomy_settings( $key ) : self::post_type_settings( $key );
	}

	/** Write (create or update) the Meta Box copy of a post type or taxonomy. @return int|\WP_Error post id */
	private static function write_type( string $key, string $kind, int $id ) {
		$settings = self::settings( $key, $kind );
		if ( ! $settings ) {
			return new \WP_Error( 'lb_mb_sync', 'Unknown type ' . $key . '.' );
		}
		$post = [
			'post_type'    => self::MB_TYPES[ $kind ],
			'post_status'  => 'publish',
			'post_title'   => 'taxonomy' === $kind ? (string) $settings['labels']['name'] : (string) $settings['label'],
			'post_name'    => $key,
			'post_content' => wp_slash( (string) wp_json_encode( $settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		];
		if ( $id ) {
			$post['ID'] = $id;
		}
		return $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
	}

	/**
	 * Write a field group the way Meta Box Builder's Save button does: its own parser turns the definition into the
	 * builder's settings and fields, the registration data is parsed from those, then mbb_after_save (local JSON files).
	 *
	 * @return int|\WP_Error post id
	 */
	private static function write_fields( string $key, int $id ) {
		$def = self::field_groups()[ $key ] ?? null;
		if ( ! $def ) {
			return new \WP_Error( 'lb_mb_sync', 'Unknown field group ' . $key . '.' );
		}
		$unparser = new \MBBParser\Unparsers\MetaBox( $def );
		$unparser->unparse();
		$data = (array) $unparser->get_settings();
		$post = [ 'post_type' => self::MB_TYPES['fields'], 'post_status' => 'publish', 'post_title' => $def['title'], 'post_name' => $def['id'], 'post_content' => '' ];
		if ( $id ) {
			$post['ID'] = $id;
		}
		$id = $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$parser = \MBB\RestApi\Save::parse( get_post( $id ), (array) ( $data['fields'] ?? [] ), (array) ( $data['settings'] ?? [] ), $def['title'], $def['id'] );
		do_action( 'mbb_after_save', $parser, $id, [ 'fields' => $data['fields'] ?? [], 'settings' => $data['settings'] ?? [], 'post_title' => $def['title'], 'post_name' => $def['id'] ] );
		if ( class_exists( '\MBB\JsonService' ) && method_exists( '\MBB\JsonService', 'clear_cache' ) ) {
			\MBB\JsonService::clear_cache();
		}
		return (int) $id;
	}

	/**
	 * Move this sync's copies to the Trash (Launch Builder registers the types again). A copy already in the Trash is only
	 * let go of, so a later sync adds it afresh.
	 */
	private static function undo( bool $dry ): array {
		$out = [ 'dry_run' => $dry, 'undo' => true, 'objects' => [] ];
		foreach ( self::status() as $key => $row ) {
			$action = 'none';
			if ( in_array( $row['state'], [ 'synced', 'edited', 'off', 'renamed' ], true ) && $row['id'] ) {
				$action = 'trash' === get_post_status( $row['id'] ) ? 'release' : 'trash';
			}
			$out['objects'][ $key ] = $row + [ 'action' => $action ];
			if ( 'trash' === $action && 'edited' === $row['state'] ) {
				$out['objects'][ $key ]['note'] = __( 'Changed in Meta Box since: it goes to the Trash with those changes (restore it from there to keep them).', 'launch-builder' );
			}
		}
		$todo = array_filter( $out['objects'], static function ( $r ) { return 'none' !== $r['action']; } );
		$trash = wp_list_pluck( array_filter( $todo, static function ( $r ) { return 'trash' === $r['action']; } ), 'label' );
		if ( $dry || ! $todo ) {
			$out['message'] = $trash
				/* translators: %s: list of names */
				? sprintf( __( 'Would move to the Trash: %s. Launch Builder then registers them itself again.', 'launch-builder' ), implode( ', ', $trash ) )
				: ( $todo ? __( 'Would forget the copies already in the Trash.', 'launch-builder' ) : __( 'There is nothing of Launch Builder\'s in Meta Box to remove.', 'launch-builder' ) );
			return $out;
		}
		foreach ( $todo as $key => $r ) {
			if ( 'release' === $r['action'] || wp_trash_post( (int) $r['id'] ) ) {
				Ledger::forget( self::LEDGER . $key );
				$out['objects'][ $key ]['state'] = 'missing';
				$out['objects'][ $key ]['note'] = '';
			} else {
				$out['objects'][ $key ]['action'] = 'failed';
			}
		}
		if ( class_exists( '\MBB\JsonService' ) && method_exists( '\MBB\JsonService', 'clear_cache' ) ) {
			\MBB\JsonService::clear_cache();
		}
		self::changed();
		$out['message'] = $trash
			/* translators: %s: list of names */
			? sprintf( __( 'Moved to the Trash: %s. Launch Builder registers them itself again.', 'launch-builder' ), implode( ', ', $trash ) )
			: __( 'Forgot the copies already in the Trash.', 'launch-builder' );
		return $out;
	}

	/** One sentence about what a sync adds or updates. */
	private static function summary( array $todo, bool $dry ): string {
		$add = wp_list_pluck( array_filter( $todo, static function ( $r ) { return 'create' === $r['action']; } ), 'label' );
		$upd = wp_list_pluck( array_filter( $todo, static function ( $r ) { return 'update' === $r['action']; } ), 'label' );
		$parts = [];
		if ( $add ) {
			/* translators: %s: list of names */
			$parts[] = sprintf( $dry ? __( 'Would add to Meta Box: %s.', 'launch-builder' ) : __( 'Added to Meta Box: %s.', 'launch-builder' ), implode( ', ', $add ) );
		}
		if ( $upd ) {
			/* translators: %s: list of names */
			$parts[] = sprintf( $dry ? __( 'Would update: %s.', 'launch-builder' ) : __( 'Updated: %s.', 'launch-builder' ), implode( ', ', $upd ) );
		}
		return implode( ' ', $parts );
	}

	/** Registration changes on the next request: flush the rewrite rules then (Entries::maybe_flush) and drop page caches. */
	private static function changed(): void {
		delete_option( Entries::FLUSHED );
		Cache::purge_later();
	}

	/* ------------------------------------------------------------------ Meta Box editing our types */

	/** save_post_mb-post-type / save_post_mb-taxonomy: one of ours (by slug, now or before) changed. */
	public static function mb_saved( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && self::is_entry_copy( $post ) ) {
			delete_option( Entries::FLUSHED );
		}
	}

	public static function mb_status_changed( $new, $old, $post ): void {
		if ( $new !== $old && $post instanceof \WP_Post && in_array( $post->post_type, [ 'mb-post-type', 'mb-taxonomy' ], true ) && self::is_entry_copy( $post ) ) {
			delete_option( Entries::FLUSHED );
		}
	}

	public static function mb_deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && in_array( $post->post_type, [ 'mb-post-type', 'mb-taxonomy' ], true ) && self::is_entry_copy( $post ) ) {
			delete_option( Entries::FLUSHED );
		}
	}

	/** Is this Meta Box post type or taxonomy one of the three (by its slug, or because this sync made it)? */
	private static function is_entry_copy( \WP_Post $p ): bool {
		if ( in_array( self::slug_of( $p, 'post_type' ), [ Entries::SERVICE, Entries::AREA, Entries::CAT ], true ) ) {
			return true;
		}
		foreach ( [ Entries::SERVICE, Entries::AREA, Entries::CAT ] as $key ) {
			$row = Ledger::get( self::LEDGER . $key );
			if ( $row && (int) $row['id'] === (int) $p->ID ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * On Meta Box's lists (Post Types, Taxonomies, Custom Fields): what is not there yet, with a button that adds it. On
	 * the edit screen of one of the copies: what Launch Builder keeps. After a sync: the result.
	 */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$kinds = [ 'edit-mb-post-type' => 'post_type', 'edit-mb-taxonomy' => 'taxonomy', 'edit-meta-box' => 'fields' ];
		$flash = get_transient( self::FLASH . get_current_user_id() );
		if ( is_array( $flash ) && 2 === count( $flash ) && ( isset( $kinds[ $screen->id ] ) || in_array( $screen->post_type, self::MB_TYPES, true ) ) ) {
			delete_transient( self::FLASH . get_current_user_id() );
			echo '<div class="notice notice-' . ( 'error' === $flash[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $flash[1] ) . '</p></div>';
		}
		if ( isset( $kinds[ $screen->id ] ) ) {
			self::list_notice( $kinds[ $screen->id ] );
		} elseif ( 'post' === $screen->base && in_array( $screen->post_type, self::MB_TYPES, true ) ) {
			self::edit_notice();
		}
	}

	/** Meta Box's list screen: what of this kind is not in Meta Box yet; the button adds everything that is missing. */
	private static function list_notice( string $kind ): void {
		$missing = array_filter( self::status(), static function ( $r ) { return 'missing' === $r['state']; } );
		$here = array_filter( $missing, static function ( $r ) use ( $kind ) { return $kind === $r['kind']; } );
		if ( ! $here ) {
			return;
		}
		$others = array_diff_key( $missing, $here );
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Launch Builder', 'launch-builder' ) . ':</strong> ';
		if ( 'fields' === $kind ) {
			/* translators: %s: list of field group names */
			echo esc_html( sprintf( __( '%s are not listed here yet. Add them to edit the card fields of services and service areas with Meta Box; the values already stored stay as they are.', 'launch-builder' ), implode( ', ', wp_list_pluck( $here, 'label' ) ) ) );
		} elseif ( 'taxonomy' === $kind ) {
			/* translators: %s: taxonomy name */
			echo esc_html( sprintf( __( '%s are registered by Launch Builder, so they are not listed here yet. Add them to edit their labels and URL base in Meta Box; your categories stay as they are.', 'launch-builder' ), implode( ', ', wp_list_pluck( $here, 'label' ) ) ) );
		} else {
			/* translators: %s: list of post type names */
			echo esc_html( sprintf( __( '%s are registered by Launch Builder, so they are not listed here yet. Add them to edit their labels, menu, icon and URL base in Meta Box; your services and areas stay as they are.', 'launch-builder' ), implode( ', ', wp_list_pluck( $here, 'label' ) ) ) );
		}
		if ( $others ) {
			/* translators: %s: list of names */
			echo ' ' . esc_html( sprintf( __( 'The button also adds %s.', 'launch-builder' ), implode( ', ', wp_list_pluck( $others, 'label' ) ) ) );
		}
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 10px">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="return" value="' . esc_attr( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '">'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_keys( $missing ) as $key ) {
			echo '<input type="hidden" name="only[]" value="' . esc_attr( $key ) . '">';
		}
		submit_button( __( 'Add to Meta Box', 'launch-builder' ), 'primary small', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Not wanted? Switch off Meta Box sync under Launch Builder > Modules.', 'launch-builder' ) . '</span></form></div>';
	}

	/** The edit screen of one of the copies: what Launch Builder keeps whatever the settings say. */
	private static function edit_notice(): void {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		foreach ( self::status() as $key => $row ) {
			if ( (int) $row['id'] !== (int) $post->ID || in_array( $row['state'], [ 'missing', 'unavailable' ], true ) ) {
				continue;
			}
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Launch Builder uses this.', 'launch-builder' ) . '</strong> '
				. esc_html__( 'Change what you like; Launch Builder relies on these:', 'launch-builder' ) . '</p><ul style="list-style:disc;margin-left:2em">';
			foreach ( self::kept( $key ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
			if ( '' !== $row['note'] && 'synced' !== $row['state'] ) {
				echo '<p>' . esc_html( $row['note'] ) . '</p>';
			}
			echo '</div>';
			return;
		}
	}

	/** admin-post.php?action=lb_mb_sync: run the sync for the objects the notice listed. */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$only = array_map( 'sanitize_key', (array) wp_unslash( $_POST['only'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$r = self::run( [ 'dry_run' => false, 'only' => $only ] );
		if ( is_wp_error( $r ) ) {
			set_transient( self::FLASH . get_current_user_id(), [ 'error', $r->get_error_message() ], 5 * MINUTE_IN_SECONDS );
		} else {
			$failed = array_filter( $r['objects'], static function ( $o ) { return 'failed' === $o['action']; } );
			$msg = (string) ( $r['message'] ?? '' );
			foreach ( $failed as $o ) {
				$msg .= ' ' . $o['label'] . ': ' . $o['note'];
			}
			set_transient( self::FLASH . get_current_user_id(), [ $failed ? 'error' : 'success', trim( $msg ) ], 5 * MINUTE_IN_SECONDS );
		}
		$back = wp_validate_redirect( esc_url_raw( (string) wp_unslash( $_POST['return'] ?? '' ) ), admin_url( 'edit.php?post_type=mb-post-type' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( $back );
		exit;
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function define_tools(): void {
		Abilities::add( 'sync-meta-box', 'Edit services and areas in Meta Box', 'List Services, Service areas and Service categories under Meta Box > Post Types and Taxonomies, and the Card details fields (icon; services offered per area) under Meta Box > Custom Fields, so they are edited in Meta Box. Needs MB Custom Post Types for the types and MB Builder for the fields (both in Meta Box Lite and AIO). Each object comes back with its state: missing, synced (Launch Builder\'s copy, unchanged), edited (changed in Meta Box: kept), off (a draft or in the Trash: Launch Builder registers it), renamed (its slug changed: Launch Builder registers the type again), yours (made in Meta Box: never touched), unavailable. Once a type is in Meta Box, Meta Box registers it with its settings; Launch Builder keeps only what its lists and URLs need (kept lists it). Content and stored values never move. dry_run (default true) returns the plan; force (true or keys) overwrites edited, off and renamed copies (snapshot first); only limits the keys; undo moves this sync\'s copies to the Trash, after which Launch Builder registers the types itself again.',
			[ 'properties' => [
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Report the plan without writing (default true).' ],
				'only'    => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( self::objects() ) ], 'description' => 'Limit to these objects.' ],
				'force'   => [ 'type' => [ 'boolean', 'array' ], 'items' => [ 'type' => 'string' ], 'description' => 'Overwrite copies changed, drafted, trashed or renamed in Meta Box: true, or a list of keys.' ],
				'undo'    => [ 'type' => 'boolean', 'description' => 'Move this sync\'s copies to the Trash (with dry_run false).' ],
			] ], [ __CLASS__, 'tool' ], 'manage_options' );
	}

	public static function tool( $input = [] ) {
		$r = self::run( (array) $input );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( $r['objects'] as $key => $o ) {
			$r['objects'][ $key ]['kept'] = self::kept( $key );
		}
		return $r;
	}

	/** site-state: where each object stands (only when Meta Box is active). */
	public static function site_state( array &$state ): void {
		if ( ! defined( 'RWMB_VER' ) ) {
			return;
		}
		$state['meta_box_sync'] = array_map( static function ( $r ) { return $r['state']; }, self::status() );
	}

	/** get-playbook: how services and areas meet Meta Box. */
	public static function playbook( array &$pb ): void {
		$pb['meta_box'] = (array) ( $pb['meta_box'] ?? [] );
		$pb['meta_box']['sync'] = 'sync-meta-box lists services, areas and service categories in Meta Box (Post Types, Taxonomies) and the Card details fields as field groups, so the client edits them there. Meta Box then registers them; Launch Builder keeps the slug, public/REST, no archive, areas hierarchical, the editor supports it needs and a URL base. Run it dry first; it never touches what was changed or made in Meta Box unless forced; undo removes its copies. Prefer it to pasting the example field group.';
	}

	/** Audit: a type whose Meta Box copy was renamed (its lists and URLs then use an empty type of the old name). */
	public static function audit_checks(): array {
		return [ 'meta_box_type_renamed' => [ 'content', [ __CLASS__, 'check_renamed' ] ] ];
	}

	public static function check_renamed(): array {
		$out = [];
		foreach ( self::status() as $key => $row ) {
			if ( 'renamed' === $row['state'] ) {
				$out[] = [ 'severity' => 'warning', 'object_type' => 'meta_box', 'id' => $row['id'], 'url' => $row['edit_url'], 'message' => $row['label'] . ': ' . $row['note'], 'fix' => 'Set its slug back to ' . $key . ' in Meta Box, or run sync-meta-box with force: ["' . $key . '"].' ];
			}
		}
		return $out;
	}
}
