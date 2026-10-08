<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Simulated render context: "render this as if it were a page of role X (and this post)". The pattern preview, editor
 * previews, the audit and inspect-page use it so role-dependent output (crumbs, heroes, tokens) is what a real page of
 * that role would show. Roles::current() returns the simulated role before looking at the request.
 *
 * Simulations nest: simulate() pushes, clear() pops the innermost one, current() is the innermost (an audit that
 * previews a pattern keeps its own simulation). Call clear() once for every simulate() that returned true.
 */
final class Context {

	/** @var array<int,array{role:string,post:?\WP_Post}> active simulations, innermost last. */
	private static $stack = [];

	/**
	 * Simulate a role (and optionally the object being rendered).
	 *
	 * @param string                $role a key of Roles::ROLES.
	 * @param int|\WP_Post|null     $post sample object (for tokens such as {{page.title}}); null or 0 = none (never the
	 *                                    global post).
	 * @return bool false when the role is unknown (nothing changes, nothing to clear).
	 */
	public static function simulate( string $role, $post = null ): bool {
		if ( ! isset( Roles::ROLES[ $role ] ) ) {
			return false;
		}
		$p = $post ? get_post( $post ) : null;
		self::$stack[] = [ 'role' => $role, 'post' => $p instanceof \WP_Post ? $p : null ];
		return true;
	}

	/** End the innermost simulation (the one before it, if any, is active again). */
	public static function clear(): void {
		array_pop( self::$stack );
	}

	/** @return array{role:string,post:?\WP_Post}|null the innermost active simulation, or null. */
	public static function current(): ?array {
		return self::$stack ? end( self::$stack ) : null;
	}
}
