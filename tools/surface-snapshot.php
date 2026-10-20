<?php
/**
 * Launch Builder's runtime surface as JSON: what a refactor must leave unchanged. Take one before and one after a change
 * on the same site and database, then compare them with tools/surface-diff.php.
 *
 *   wp eval-file tools/surface-snapshot.php --user=admin > before.json
 *   (change the code)
 *   wp eval-file tools/surface-snapshot.php --user=admin > after.json
 *   php tools/surface-diff.php before.json after.json
 *
 * Covers: every class, trait and interface under includes/ (loads, methods with a hash of their source, constants with a
 * hash of their value, properties), the module registry per hook, the three entry types and their meta, the tools, REST
 * routes, blocks, patterns, every WordPress hook with a Launch Builder callback, shortcodes, option names, the keys of
 * site-state and the playbook, audit checks, the Design hub pieces and the module switches. Run it as a user who
 * can manage options (site-state and the playbook read as that user). Restore the database between runs when the code
 * under test writes on boot (migrations), or the option list differs for that reason alone.
 */

use LaunchBuilder as LB;

defined( 'ABSPATH' ) || exit;

$out  = [];
$norm = static function ( $v ) use ( &$norm ) {
	if ( $v instanceof \Closure ) {
		return 'closure';
	}
	if ( is_object( $v ) ) {
		$v = get_object_vars( $v );
	}
	if ( is_array( $v ) ) {
		return array_map( $norm, $v );
	}
	return $v;
};
$root = realpath( LB_DIR ) ?: LB_DIR;
$cb_name = static function ( $cb ) use ( $root ): ?string {
	if ( is_string( $cb ) ) {
		return 0 === stripos( $cb, 'LaunchBuilder\\' ) ? $cb : null;
	}
	if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
		$c = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
		return 0 === stripos( $c, 'LaunchBuilder\\' ) ? $c . '::' . $cb[1] : null;
	}
	if ( $cb instanceof \Closure ) {
		$r = new \ReflectionFunction( $cb );
		$f = (string) $r->getFileName();
		if ( 0 === strpos( $f, $root ) || 0 === strpos( $f, LB_DIR ) ) {
			$scope = $r->getClosureScopeClass();
			return 'closure@' . ( $scope ? $scope->getName() : basename( $f ) );
		}
	}
	return null;
};

// Classes.
$names = [];
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( LB_DIR . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( '.php' === substr( $f->getFilename(), -4 ) && preg_match_all( '/^\s*(?:final\s+|abstract\s+)?(class|trait|interface)\s+([A-Za-z0-9_]+)/m', (string) file_get_contents( $f->getPathname() ), $m ) ) {
		foreach ( $m[2] as $i => $n ) {
			$names[ 'LaunchBuilder\\' . $n ] = $m[1][ $i ];
		}
	}
}
ksort( $names );
foreach ( $names as $n => $kind ) {
	$ok = 'trait' === $kind ? trait_exists( $n ) : ( 'interface' === $kind ? interface_exists( $n ) : class_exists( $n ) );
	$row = [ 'loads' => $ok, 'methods' => [], 'constants' => [], 'props' => [] ];
	if ( $ok && 'class' === $kind ) {
		$rc = new ReflectionClass( $n );
		foreach ( $rc->getMethods() as $m ) {
			$src = implode( '', array_slice( file( $m->getFileName() ), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
			$row['methods'][] = ( $m->isPublic() ? 'public ' : ( $m->isProtected() ? 'protected ' : 'private ' ) ) . ( $m->isStatic() ? 'static ' : '' ) . $m->getName() . '/' . $m->getNumberOfParameters() . ' ' . md5( $src );
		}
		foreach ( $rc->getConstants() as $ck => $cv ) {
			$row['constants'][] = $ck . ' ' . md5( serialize( $cv ) );
		}
		foreach ( $rc->getProperties() as $pr ) {
			$row['props'][] = ( $pr->isStatic() ? 'static ' : '' ) . $pr->getName();
		}
		sort( $row['methods'] );
		sort( $row['constants'] );
		sort( $row['props'] );
	}
	$out['classes'][ $n ] = $row;
}

// Module registry.
foreach ( [ '', 'boot', 'define_tools', 'site_state', 'playbook', 'audit_checks', 'activate', 'deactivate' ] as $m ) {
	$out['modules'][ '' === $m ? 'all' : $m ] = LB\Plugin::modules( $m );
}

// Entry types and meta.
foreach ( [ LB\Entries::SERVICE, LB\Entries::AREA ] as $name ) {
	$pt = get_post_type_object( $name );
	$out['post_types'][ $name ] = $pt ? [ 'args' => $norm( $pt ), 'supports' => get_all_post_type_supports( $name ) ] : null;
	$out['meta'][ $name ] = $norm( get_registered_meta_keys( 'post', $name ) );
}
$tx = get_taxonomy( LB\Entries::CAT );
$out['taxonomies'][ LB\Entries::CAT ] = $tx ? $norm( $tx ) : null;

// Tools.
foreach ( LB\Abilities::defs() as $name => $d ) {
	$out['tools'][ $name ] = [ 'label' => $d['label'], 'cap' => $d['cap'], 'description' => md5( $d['description'] ), 'input' => md5( (string) wp_json_encode( $d['input'] ) ), 'callback' => $cb_name( $d['callback'] ) ?? 'other', 'annotations' => $d['annotations'] ];
}
$out['tool_order'] = array_keys( LB\Abilities::defs() );

// REST routes, blocks, patterns.
$out['rest'] = array_values( array_filter( array_keys( rest_get_server()->get_routes() ), static function ( $r ) { return (bool) preg_match( '#launch|/lb|mcp#i', $r ); } ) );
$out['blocks'] = array_values( array_filter( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ), static function ( $n ) { return 0 === strpos( $n, 'launch/' ); } ) );
LB\Plugin::register_patterns();
$out['patterns'] = array_keys( LB\Plugin::pattern_index() );

// Hooks and shortcodes.
global $wp_filter, $shortcode_tags;
$hooks = [];
foreach ( $wp_filter as $tag => $hook ) {
	foreach ( $hook->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			$n = $cb_name( $cb['function'] );
			if ( $n ) {
				$hooks[] = $tag . ' @' . $prio . ' ' . $n . ' /' . $cb['accepted_args'];
			}
		}
	}
}
sort( $hooks );
$out['hooks'] = $hooks;
$out['shortcodes'] = array_keys( array_filter( $shortcode_tags, static function ( $cb ) use ( $cb_name ) { return null !== $cb_name( $cb ); } ) );

// Option names.
global $wpdb;
$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'launch\\_builder%' OR option_name LIKE 'lb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
sort( $opts );
$out['options'] = $opts;

// Aggregates.
$out['site_state_keys'] = array_keys( (array) LB\Abilities::site_state() );
$out['playbook_keys'] = array_keys( (array) LB\Content::playbook() );
$out['audit_checks'] = array_keys( LB\Audit::checks() );
$out['design_pieces'] = array_keys( LB\Design_Hub::PIECES );
foreach ( LB\Modules::all() as $info ) {
	$out['module_switches'][ (string) ( $info['slug'] ?? $info['class'] ?? '' ) ] = $norm( $info );
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
