<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat: Business Info in Rank Math's knowledge graph. sync-seo writes these keys with the rest of the settings
 * Launch Builder owns; a key changed in Rank Math afterwards is kept (Ledger). Only the facts Business Info has a real
 * value for are written: the business name, email, phone, the logo and the contact page (the call-to-action link, when
 * it is a page). Rank Math's Local SEO screen keeps everything else (address parts, hours, business type).
 */
trait Compat_Seo_Business {

	/**
	 * Rank Math titles keys built from Business Info (only keys with a real value). Valid only after init (the logo and
	 * the contact page need WordPress loaded); before that it is empty.
	 */
	public static function identity(): array {
		if ( null !== self::$identity ) {
			return self::$identity;
		}
		if ( ! did_action( 'init' ) ) {
			return [];
		}
		$r = Company::resolve();
		$v = static function ( string $k ) use ( $r ): string {
			return isset( $r[ $k ] ) && in_array( $r[ $k ]['from'], [ 'saved', 'derived', 'wordpress' ], true ) ? trim( (string) $r[ $k ]['value'] ) : '';
		};
		$out = [ 'url' => home_url( '/' ) ];
		if ( '' !== $v( 'name' ) ) {
			$out['knowledgegraph_name'] = $v( 'name' );
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
		if ( $logo_url ) {
			$out['knowledgegraph_logo'] = $logo_url;
			$out['knowledgegraph_logo_id'] = $logo;
		}
		if ( is_email( $v( 'email' ) ) ) {
			$out['email'] = $v( 'email' );
		}
		$phone = self::schema_phone( $v( 'phone' ) );
		if ( '' !== $phone ) {
			$out['phone'] = $phone;
			$out['phone_numbers'] = [ [ 'type' => 'customer support', 'number' => $phone ] ];
		}
		$contact = '' !== $v( 'cta_url' ) ? url_to_postid( $v( 'cta_url' ) ) : 0;
		if ( $contact && 'page' === get_post_type( $contact ) ) {
			$out['local_seo_contact_page'] = $contact;
		}
		return self::$identity = $out;
	}

	/** Forget the per-request values (after a Business Info change in the same request). */
	public static function flush_identity(): void {
		self::$identity = null;
	}

	/** Phone in Rank Math's international format (+1-512-555-0123) from a displayed number; '' when it has no digits. */
	private static function schema_phone( string $phone ): string {
		$d = (string) preg_replace( '/\D+/', '', $phone );
		if ( strlen( $d ) < 7 ) {
			return '';
		}
		if ( 10 === strlen( $d ) && 0 !== strpos( trim( $phone ), '+' ) ) {
			$d = '1' . $d;
		}
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			return '+1-' . substr( $d, 1, 3 ) . '-' . substr( $d, 4, 3 ) . '-' . substr( $d, 7 );
		}
		return '+' . $d;
	}
}
