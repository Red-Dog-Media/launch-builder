<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * wp launch-builder login-link <create|list|revoke|extend|gc>. Run as a user with --user=<login>; the same rules apply
 * as in wp-admin (no CLI bypass). Only Login_Links_Guard registers it.
 */
final class Login_Links_CLI {

	private function me(): \WP_User {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->exists() ) {
			\WP_CLI::error( 'Run as a user with the global flag, e.g. --user=admin.' );
		}
		if ( ! Login_Links::enabled() ) {
			\WP_CLI::error( 'Login links are off. ' . Login_Links::how_to_enable() );
		}
		return $u;
	}

	private function user( string $who ): \WP_User {
		$u = ctype_digit( $who ) ? get_userdata( (int) $who ) : ( is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who ) );
		if ( ! $u instanceof \WP_User ) {
			\WP_CLI::error( 'No such user: ' . $who );
		}
		return $u;
	}

	/**
	 * Make a sign-in link for a user. The link is printed once (only a hash is stored).
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * [--days=<days>]
	 * : Lifetime in days (1-30). Default: the site setting (7).
	 *
	 * [--single-use]
	 * : The link works once.
	 *
	 * [--max-uses=<n>]
	 * : The link works up to n times (0 = until it expires).
	 *
	 * [--redirect=<path>]
	 * : Page of this site to open after sign-in, e.g. /my-account/.
	 *
	 * [--note=<text>]
	 * : Note shown in the list (120 characters).
	 *
	 * [--email]
	 * : Email the link to the account's own address instead of printing it.
	 *
	 * [--porcelain]
	 * : Print only the URL.
	 */
	public function create( $args, $assoc ) {
		$me = $this->me();
		$target = $this->user( (string) ( $args[0] ?? '' ) );
		$r = Login_Links::create(
			$target->ID,
			[
				'days'     => $assoc['days'] ?? '',
				'max_uses' => isset( $assoc['single-use'] ) ? 1 : (int) ( $assoc['max-uses'] ?? 0 ),
				'redirect' => (string) ( $assoc['redirect'] ?? '' ),
				'note'     => (string) ( $assoc['note'] ?? '' ),
				'email'    => isset( $assoc['email'] ),
			],
			$me->ID
		);
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		if ( $r['emailed'] ) {
			\WP_CLI::success( 'Link ' . $r['id'] . ' emailed to ' . $target->user_login . "'s own address." );
			return;
		}
		if ( isset( $assoc['porcelain'] ) ) {
			\WP_CLI::line( $r['url'] );
			return;
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' for ' . $target->user_login . ', valid until ' . gmdate( 'Y-m-d H:i', (int) $r['record']['expires_at'] ) . ' UTC. Anyone with it can sign in as ' . $target->user_login . '. It is shown once:' );
		\WP_CLI::line( $r['url'] );
	}

	/**
	 * List links (metadata only, never secrets).
	 *
	 * [--account=<user>]
	 * : Only this account (ID, login or email). WP-CLI's global --user selects who runs the command.
	 *
	 * [--status=<status>]
	 * : active (default) or all.
	 *
	 * [--format=<format>]
	 * : table (default), json or csv.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$me = $this->me();
		$uid = 0;
		if ( ! empty( $assoc['account'] ) ) {
			$uid = $this->user( (string) $assoc['account'] )->ID;
		}
		if ( ! user_can( $me, Login_Links::CAP ) && $uid !== $me->ID ) {
			$uid = $me->ID; // Without the manage capability you see only links to your own account.
		}
		$list = Login_Links::list_links( [ 'user_id' => $uid, 'status' => 'all' === ( $assoc['status'] ?? '' ) ? 'all' : 'active', 'limit' => 500 ] );
		$items = array_map(
			static function ( $p ) {
				foreach ( [ 'created_at', 'expires_at', 'last_used_at', 'revoked_at' ] as $k ) {
					$p[ $k ] = $p[ $k ] ? gmdate( 'Y-m-d H:i', $p[ $k ] ) : '';
				}
				return $p;
			},
			$list['links']
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $items, [ 'id', 'user_login', 'status', 'expires_at', 'uses', 'max_uses', 'last_used_at', 'created_by_login', 'redirect', 'note' ] );
	}

	/**
	 * Revoke one link, every link of an account, or every link on the site.
	 *
	 * [<id>]
	 * : Link id (from list).
	 *
	 * [--account=<user>]
	 * : Revoke every link of this account.
	 *
	 * [--all]
	 * : Revoke every link on the site (needs manage_options).
	 *
	 * [--sign-out]
	 * : Also end the sessions the links started.
	 */
	public function revoke( $args, $assoc ) {
		$me = $this->me();
		$sign_out = isset( $assoc['sign-out'] );
		if ( isset( $assoc['all'] ) ) {
			if ( ! user_can( $me, 'manage_options' ) ) {
				\WP_CLI::error( 'Revoking every link needs manage_options.' );
			}
			\WP_CLI::success( Login_Links::revoke_many( 0, $me->ID, 'revoke_all' ) . ' link(s) revoked.' );
			return;
		}
		if ( ! empty( $assoc['account'] ) ) {
			$u = $this->user( (string) $assoc['account'] );
			if ( $u->ID !== $me->ID && ! ( user_can( $me, Login_Links::CAP ) && user_can( $me, 'edit_user', $u->ID ) ) ) {
				\WP_CLI::error( 'You cannot revoke links of ' . $u->user_login . '.' );
			}
			\WP_CLI::success( Login_Links::revoke_many( $u->ID, $me->ID, 'manual', $sign_out ) . ' link(s) revoked.' );
			return;
		}
		$r = Login_Links::revoke( (string) ( $args[0] ?? '' ), $me->ID, 'manual', $sign_out );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' revoked.' );
	}

	/**
	 * Move a link's expiry to <days> from now (same URL).
	 *
	 * <id>
	 * : Link id.
	 *
	 * --days=<days>
	 * : New lifetime from now (1-30).
	 */
	public function extend( $args, $assoc ) {
		$me = $this->me();
		$r = Login_Links::extend( (string) ( $args[0] ?? '' ), (int) ( $assoc['days'] ?? 0 ), $me->ID );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( 'Link ' . $r['id'] . ' now expires ' . gmdate( 'Y-m-d H:i', (int) $r['expires_at'] ) . ' UTC.' );
	}

	/** Delete links that expired, were revoked or used up more than 30 days ago. */
	public function gc( $args, $assoc ) {
		$me = $this->me();
		if ( ! user_can( $me, 'manage_options' ) ) {
			\WP_CLI::error( 'gc needs manage_options.' );
		}
		\WP_CLI::success( Login_Links::gc() . ' old link(s) deleted.' );
	}
}
