<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Module switches: which optional modules run on this site.
 *
 * Contract (other modules depend on it):
 *  - A module class may declare
 *      const MODULE = [ 'slug' => 'duplicate', 'label' => 'Duplicate', 'description' => '...', 'toggle' => true,
 *                       'default' => false, 'note' => 'optional extra line for the Modules screen' ];
 *    A class that declares the array can be switched on and off unless it says 'toggle' => false ('id' is read as an
 *    alias of 'slug'; a plain string constant is just the slug, always on). Without the constant a module is always
 *    on. The core modules (CORE) are never toggleable, whatever their constant says.
 *  - 'new_install' => true on a default-off module switches it on when the plugin is activated on a site that has no
 *    Launch Builder state yet (first_activation()). A site that already has state keeps the module's default, so an update
 *    never changes what a site does.
 *  - A disabled module is not booted and none of its hooks run: Plugin::modules() skips it, so it registers no tools,
 *    WordPress hooks, site-state keys, playbook entries, audit checks, blueprint steps or migrations.
 *  - Storage: the small autoloaded option launch_builder_disabled_modules holds only the choices that differ from a
 *    module's default: the list of slugs switched off, plus under the key 'enabled' the default-off slugs switched on,
 *    e.g. [ 'duplicate', 'enabled' => [ 'login-links' ] ]. Every other module follows 'default' from its constant
 *    (true when the constant has none). Read state with Modules::enabled(), not the option. Also read, never written:
 *    [ slug => true ] (off) / [ slug => false ] (on). Filter launch_builder_module_enabled( $on, $slug, $class ) can
 *    force a state (for example from a constant in wp-config.php).
 *  - Changes take effect on the next request. Switching a module on calls its activate() (if any), switching it off
 *    calls its deactivate() (if any), so a module can schedule migrations or park what must not outlive it.
 *  - Tools: get-modules (read) and set-modules { enable[], disable[] } (manage_options, snapshotted). Screen:
 *    Launch Builder > Modules. site-state lists every module's state under 'modules'.
 */
final class Modules {

	const OPT = 'launch_builder_disabled_modules';

	/** Never toggleable: the rest of the plugin is built on them. */
	const CORE = [ 'roles', 'chrome', 'breadcrumbs', 'tokens', 'company', 'style', 'entries', 'nav', 'patterns', 'audit', 'mcp' ];

	/** Labels and descriptions for the built-in classes that declare no MODULE constant (Modules screen, get-modules). */
	const BUILTIN = [
		'abilities'   => [ 'Tools and undo', 'The tool registry an AI assistant or dashboard calls, settings snapshots and restore, the REST proxy and media import.' ],
		'mcp'         => [ 'Connection', 'The /mcp endpoint, connection tokens and the activity log.' ],
		'patterns'    => [ 'Patterns', 'The pattern library, editable slots and the build pipeline every page writer uses.' ],
		'nav'         => [ 'Menus and header', 'Navigation menus and the block header, with live service and area items.' ],
		'globals'     => [ 'Global areas (legacy)', 'Synced patterns placed above the footer or as the footer on sites built before the chrome map.' ],
		'blueprint'   => [ 'Site builder', 'build-site, synced patterns and Kadence Elements placement.' ],
		'company'     => [ 'Business Info', 'Business facts (name, phone, hours, links) that every page reads through tokens.' ],
		'rating'      => [ 'Rating widget', 'The rating widget block.' ],
		'design'      => [ 'Design tools', 'Brand palette and fonts, buttons, menu style, page style, sidebar, block defaults.' ],
		'content'     => [ 'Content tools', 'Markdown pages, page layouts, page recipes and the playbook.' ],
		'brief'       => [ 'Briefs', 'Client brief in, site blueprint out.' ],
		'entries'     => [ 'Services and areas', 'The service and service area post types and every live list of them.' ],
		'admin'       => [ 'Admin screens', 'The Launch Builder screens in wp-admin and the pattern preview.' ],
		'roles'       => [ 'Page roles', 'What each page is (home, service, area, conversion...), which decides its shared designs.' ],
		'breadcrumbs' => [ 'Breadcrumbs', 'The breadcrumb trail and its one BreadcrumbList.' ],
		'chrome'      => [ 'Shared designs', 'Headers, heroes, breadcrumb bars, templates and footers per page role.' ],
		'tokens'      => [ 'Dynamic text', 'Tokens such as {{lb.phone}} resolved everywhere at render time.' ],
		'style'       => [ 'Style DNA', 'Radius, shadows, spacing and section styles as CSS variables.' ],
		'cache'       => [ 'Cache purge', 'One page-cache purge per request after changes.' ],
		'migrate'     => [ 'Data migrations', 'Versioned, resumable data updates after a plugin update.' ],
		'audit'       => [ 'Site health', 'The site audit, page inspection, preview links and sync.' ],
		'inspect'     => [ 'Page inspection', 'Loads a page like a visitor and reports what it printed.' ],
		'compat'      => [ 'Compatibility', 'Rank Math, Meta Box, Perfmatters and cache plugins.' ],
		'modules'     => [ 'Modules', 'These switches.' ],
		'dashboard'   => [ 'Dashboard', 'The dashboard, the Modules page, the WordPress Dashboard widget and the shared admin look.' ],
	];

	/** @var array<string,array> class => info (per request). */
	private static $info = [];

	/** @var array<string,int>|null slug => 0|1 stored choices (per request; reset by set()). */
	private static $choices = null;

	/* ------------------------------------------------------------------ registry */

	/** Short class name ('LaunchBuilder\Login_Links' -> 'Login_Links'). */
	private static function short( string $class ): string {
		$p = strrpos( $class, '\\' );
		return false === $p ? $class : substr( $class, $p + 1 );
	}

	/**
	 * What a module class says about itself (its MODULE constant), with defaults.
	 *
	 * @return array{class:string,slug:string,label:string,description:string,note:string,toggle:bool,default:bool,core:bool,new_install:bool}
	 */
	public static function info( string $class ): array {
		$class = ltrim( $class, '\\' );
		if ( isset( self::$info[ $class ] ) ) {
			return self::$info[ $class ];
		}
		$m = [];
		$declared = false;
		if ( class_exists( $class ) && defined( $class . '::MODULE' ) ) {
			$c = constant( $class . '::MODULE' );
			if ( is_array( $c ) ) {
				$m = $c;
				$declared = true;
			} elseif ( is_string( $c ) && '' !== $c ) {
				$m = [ 'slug' => $c ]; // A bare slug: named, not switchable.
			}
		}
		$slug = sanitize_key( (string) ( $m['slug'] ?? $m['id'] ?? strtolower( str_replace( '_', '-', self::short( $class ) ) ) ) );
		$core = in_array( $slug, self::CORE, true );
		$builtin = self::BUILTIN[ $slug ] ?? null;
		$info = [
			'class'       => $class,
			'slug'        => $slug,
			'label'       => (string) ( $m['label'] ?? ( $builtin ? $builtin[0] : str_replace( '_', ' ', self::short( $class ) ) ) ),
			'description' => (string) ( $m['description'] ?? ( $builtin ? $builtin[1] : '' ) ),
			'note'        => (string) ( $m['note'] ?? '' ),
			'toggle'      => ! $core && $declared && ( ! array_key_exists( 'toggle', $m ) || ! empty( $m['toggle'] ) ),
			'default'     => array_key_exists( 'default', $m ) ? (bool) $m['default'] : true,
			'core'        => $core,
			'new_install' => ! empty( $m['new_install'] ),
		];
		if ( ! $info['toggle'] ) {
			$info['default'] = true; // A module that cannot be switched off is on.
		}
		self::$info[ $class ] = $info;
		return $info;
	}

	/** Every module class (Plugin::MODULES through the launch_builder_modules filter) that exists, enabled or not. @return string[] */
	public static function classes(): array {
		$out = [];
		foreach ( (array) apply_filters( 'launch_builder_modules', Plugin::MODULES ) as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && ! in_array( ltrim( $class, '\\' ), $out, true ) ) {
				$out[] = ltrim( $class, '\\' );
			}
		}
		return $out;
	}

	/** The stored choices, cleaned: slug => 0 (switched off) | 1 (switched on). Off wins when a slug is in both lists. */
	public static function choices(): array {
		if ( null === self::$choices ) {
			$raw = get_option( self::OPT, [] );
			$off = [];
			$on = [];
			foreach ( is_array( $raw ) ? $raw : [] as $k => $v ) {
				if ( 'enabled' === $k ) {
					foreach ( is_array( $v ) ? $v : [] as $s ) {
						$on[] = is_string( $s ) ? sanitize_key( $s ) : '';
					}
				} elseif ( is_int( $k ) ) {
					$off[] = is_string( $v ) ? sanitize_key( $v ) : '';
				} elseif ( $v ) {
					$off[] = sanitize_key( (string) $k ); // [ slug => true ]: off.
				} else {
					$on[] = sanitize_key( (string) $k );
				}
			}
			$out = [];
			foreach ( array_filter( $on ) as $s ) {
				$out[ $s ] = 1;
			}
			foreach ( array_filter( $off ) as $s ) {
				$out[ $s ] = 0;
			}
			self::$choices = $out;
		}
		return self::$choices;
	}

	/** The option value for choices (slug => 0|1): [ off slugs..., 'enabled' => [ on slugs ] ], sorted; [] = nothing stored. */
	private static function stored_form( array $choices ): array {
		$off = array_keys( array_filter( $choices, static function ( $v ) { return ! $v; } ) );
		$on = array_keys( array_filter( $choices ) );
		sort( $off );
		sort( $on );
		$store = array_map( 'strval', $off );
		if ( $on ) {
			$store['enabled'] = array_map( 'strval', $on );
		}
		return $store;
	}

	/**
	 * Does this site already have Launch Builder state? Any option named launch_builder_* counts (the database version, the
	 * module choices, a token, settings): the plugin writes one the first time it runs. One indexed lookup.
	 */
	public static function has_state(): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( 'launch_builder_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Plugin activation, before any module's activate(): on a site with no Launch Builder state, switch on the default-off
	 * modules that ask for it ('new_install'), through the same explicit choice the Modules screen writes. A site that
	 * already has state (an update, a reactivation) is left exactly as it is.
	 *
	 * @return bool true when this was a new install
	 */
	public static function first_activation(): bool {
		if ( self::has_state() ) {
			return false;
		}
		$choices = self::choices();
		foreach ( self::classes() as $class ) {
			$i = self::info( $class );
			if ( $i['toggle'] && $i['new_install'] && ! $i['default'] && ! array_key_exists( $i['slug'], $choices ) ) {
				$choices[ $i['slug'] ] = 1;
			}
		}
		if ( $choices ) {
			update_option( self::OPT, self::stored_form( $choices ), true );
		}
		self::$choices = null;
		return true;
	}

	/** Is this module class on? Modules that cannot be toggled always are. */
	public static function enabled( string $class ): bool {
		$i = self::info( $class );
		if ( ! $i['toggle'] ) {
			return true;
		}
		$c = self::choices();
		$on = array_key_exists( $i['slug'], $c ) ? (bool) $c[ $i['slug'] ] : $i['default'];
		return (bool) apply_filters( 'launch_builder_module_enabled', $on, $i['slug'], $i['class'] );
	}

	/** Same, by slug ('' / unknown slugs = false). */
	public static function slug_enabled( string $slug ): bool {
		foreach ( self::classes() as $class ) {
			if ( self::info( $class )['slug'] === $slug ) {
				return self::enabled( $class );
			}
		}
		return false;
	}

	/** Every module with its state, in MODULES order. @return array<int,array> */
	public static function all(): array {
		$out = [];
		$c = self::choices();
		foreach ( self::classes() as $class ) {
			$i = self::info( $class );
			$i['enabled'] = self::enabled( $class );
			$i['choice'] = $i['toggle'] && array_key_exists( $i['slug'], $c ) ? ( $c[ $i['slug'] ] ? 'on' : 'off' ) : 'default';
			$out[] = $i;
		}
		return $out;
	}

	/**
	 * Switch modules on and off. Unknown or non-toggleable slugs refuse the whole call. The option keeps only choices
	 * that differ from the module's default.
	 *
	 * @return array|\WP_Error { changed: slug => bool, hooks: slug => string, modules, snapshot }
	 */
	public static function set( array $enable, array $disable ) {
		$enable = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $enable ) ) ) ) );
		$disable = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $disable ) ) ) ) );
		if ( ! $enable && ! $disable ) {
			return new \WP_Error( 'lb_empty', __( 'Name at least one module in enable or disable.', 'launch-builder' ), [ 'status' => 400 ] );
		}
		$both = array_intersect( $enable, $disable );
		if ( $both ) {
			return new \WP_Error( 'lb_conflict', sprintf( /* translators: %s: module slugs */ __( 'A module cannot be enabled and disabled at once: %s.', 'launch-builder' ), implode( ', ', $both ) ), [ 'status' => 400 ] );
		}
		$by_slug = [];
		foreach ( self::classes() as $class ) {
			$i = self::info( $class );
			$by_slug[ $i['slug'] ] = $i;
		}
		$bad = [];
		foreach ( array_merge( $enable, $disable ) as $slug ) {
			if ( ! isset( $by_slug[ $slug ] ) ) {
				$bad[] = $slug . ' (unknown)';
			} elseif ( ! $by_slug[ $slug ]['toggle'] ) {
				$bad[] = $slug . ( $by_slug[ $slug ]['core'] ? ' (core, always on)' : ' (cannot be switched off)' );
			}
		}
		if ( $bad ) {
			return new \WP_Error( 'lb_bad_module', sprintf( /* translators: %s: module slugs */ __( 'These modules cannot be switched: %s. Use get-modules for the list.', 'launch-builder' ), implode( ', ', $bad ) ), [ 'status' => 400 ] );
		}
		$before = self::states();
		$choices = self::choices();
		foreach ( [ 1 => $enable, 0 => $disable ] as $on => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( (bool) $on === $by_slug[ $slug ]['default'] ) {
					unset( $choices[ $slug ] ); // Back to the default: nothing to store.
				} else {
					$choices[ $slug ] = $on;
				}
			}
		}
		$snap = Abilities::snapshot( 'set-modules', [], [ self::OPT ] );
		if ( $choices ) {
			update_option( self::OPT, self::stored_form( $choices ), true );
		} else {
			delete_option( self::OPT );
		}
		$r = self::after_change( $before );
		return [ 'changed' => $r['changed'], 'hooks' => $r['hooks'], 'snapshot' => $snap, 'note' => __( 'Changes take effect from the next request.', 'launch-builder' ), 'modules' => self::rows() ];
	}

	/** Every module's state now: slug => on. */
	public static function states(): array {
		$out = [];
		foreach ( self::classes() as $class ) {
			$out[ self::info( $class )['slug'] ] = self::enabled( $class );
		}
		return $out;
	}

	/**
	 * After the option changed (set(), restore-snapshot): re-read it, call activate() / deactivate() of every module whose
	 * state differs from $before (slug => on, from states()), fire launch_builder_module_toggled, purge page caches.
	 *
	 * @return array{changed:array<string,bool>,hooks:array<string,string>}
	 */
	public static function after_change( array $before ): array {
		self::$choices = null;
		$changed = [];
		$hooks = [];
		foreach ( self::classes() as $class ) {
			$i = self::info( $class );
			$slug = $i['slug'];
			$now = self::enabled( $class );
			if ( ! array_key_exists( $slug, $before ) || $now === (bool) $before[ $slug ] ) {
				continue;
			}
			$changed[ $slug ] = $now;
			$hook = $now ? 'activate' : 'deactivate';
			if ( method_exists( $class, $hook ) ) {
				try {
					call_user_func( [ $class, $hook ] );
					$hooks[ $slug ] = $hook;
				} catch ( \Throwable $e ) {
					$hooks[ $slug ] = $hook . ' failed: ' . $e->getMessage();
				}
			}
			do_action( 'launch_builder_module_toggled', $slug, $now, $class );
		}
		if ( $changed ) {
			Cache::purge_later(); // Front-end output may change with the module.
		}
		return [ 'changed' => $changed, 'hooks' => $hooks ];
	}

	/** Compact rows for tools and site-state. */
	private static function rows(): array {
		return array_map(
			static function ( $m ) {
				return [ 'slug' => $m['slug'], 'label' => $m['label'], 'enabled' => $m['enabled'], 'toggle' => $m['toggle'], 'core' => $m['core'], 'default' => $m['default'], 'choice' => $m['choice'] ];
			},
			self::all()
		);
	}

	/* ------------------------------------------------------------------ module hooks */

	public static function define_tools(): void {
		Abilities::add( 'get-modules', 'List modules', 'Every Launch Builder module with its state: enabled, whether it can be switched (toggle), core, default and the stored choice. Core modules are always on.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_tool' ], 'manage_options', [ 'readonly' => true ] );

		Abilities::add( 'set-modules', 'Switch modules on or off', 'Enable or disable optional modules by slug (see get-modules). A disabled module registers no tools or hooks. Snapshotted; takes effect from the next request.',
			[ 'properties' => [
				'enable'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Module slugs to switch on.' ],
				'disable' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Module slugs to switch off.' ],
			] ], [ __CLASS__, 'set_tool' ], 'manage_options' );
	}

	public static function get_tool(): array {
		$rows = [];
		foreach ( self::all() as $m ) {
			unset( $m['class'] );
			$rows[] = $m;
		}
		return [ 'modules' => $rows ];
	}

	public static function set_tool( $input ) {
		$input = (array) $input;
		foreach ( [ 'enable', 'disable' ] as $k ) {
			if ( isset( $input[ $k ] ) && ! is_array( $input[ $k ] ) ) {
				return new \WP_Error( 'lb_invalid_args', $k . ' must be a list of module slugs.', [ 'status' => 400 ] );
			}
			foreach ( (array) ( $input[ $k ] ?? [] ) as $v ) {
				if ( ! is_string( $v ) ) {
					return new \WP_Error( 'lb_invalid_args', $k . ' must be a list of module slugs.', [ 'status' => 400 ] );
				}
			}
		}
		return self::set( (array) ( $input['enable'] ?? [] ), (array) ( $input['disable'] ?? [] ) );
	}

	/** Module hook: module states in site-state. */
	public static function site_state( array &$state ): void {
		$out = [];
		foreach ( self::all() as $m ) {
			$out[ $m['slug'] ] = [ 'enabled' => $m['enabled'], 'toggle' => $m['toggle'] ];
		}
		$state['modules'] = $out;
	}

	/** Module hook: where the switches are, in get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['tools_by_job']['Modules'] = 'get-modules, set-modules';
	}
}
