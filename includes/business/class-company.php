<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Business Info: the eight facts every page prints through tokens ({{lb.phone}}, href="#lb-phone_link").
 *
 * Storage: one option, business_info (field => value). Meta Box's settings page "Business Info" (Meta_Box_Sync creates it,
 * option name business_info) edits the same option, so the values stay with Meta Box if Launch Builder is removed, and
 * Kadence dynamic content can read them there (Site Option fields). Without Meta Box this screen edits them.
 *
 * Reading (get(), resolve()): the stored value, else a value worked out from another field (phone_link from the phone,
 * maps_url from the address), else the site title for the name, else (only while search engines are asked to stay away,
 * setup_mode()) a demo value so designs show something, else ''.
 */
final class Company {

	/** The option the values live in (Meta Box's settings page uses the same name). */
	const OPT = 'business_info';

	/** Where Business Info lived before v2 (read once by migrate_v2()). */
	const LEGACY_OPT = 'launch_builder_company';

	const PAGE = 'launch-builder-business';

	/** The fields: key => [ label, type, demo, help ]. Types: text, tel, email, url. */
	const FIELDS = [
		'name'       => [ 'Company name', 'text', 'Your Company', 'The business name as customers know it. Empty = the site title.' ],
		'phone'      => [ 'Phone', 'tel', '(555) 555-5555', 'The main number, as it should read.' ],
		'phone_link' => [ 'Phone link', 'url', '', 'Empty = a tel: link built from the phone.' ],
		'email'      => [ 'Email', 'email', 'hello@example.com', '' ],
		'address'    => [ 'Address', 'text', '123 Main St, Your City, ST 12345', 'One line.' ],
		'maps_url'   => [ 'Maps link', 'url', '', 'Empty = a Google Maps search for the address.' ],
		'cta_label'  => [ 'Call to action', 'text', 'Get a free quote', 'The main button text on every page.' ],
		'cta_url'    => [ 'Call to action link', 'url', '/contact/', 'Where that button goes: a page path, a full address or tel:.' ],
	];

	/** What a site needs before it goes live. */
	const ESSENTIALS = [ 'name', 'phone', 'email', 'address', 'cta_label', 'cta_url' ];

	/** @var array|null per-request resolve() memo. */
	private static $resolved = null;

	public static function boot(): void {
		add_filter( 'render_block', [ __CLASS__, 'filter_block' ], 20, 2 );
		add_shortcode( 'lb', [ __CLASS__, 'shortcode' ] );
		add_action( 'admin_post_lb_company_save', [ __CLASS__, 'handle_save' ] );
		foreach ( [ 'add_option_' . self::OPT, 'update_option_' . self::OPT, 'delete_option_' . self::OPT ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'changed' ] );
		}
		Migrate::register_step( 'business-info-v2', [ __CLASS__, 'migrate_v2' ] );
	}

	/* ------------------------------------------------------------------ reading */

	/** The fields, labels translated: key => [ label, type, demo, help ]. */
	public static function fields(): array {
		$out = [];
		foreach ( self::FIELDS as $k => $f ) {
			$out[ $k ] = [ __( $f[0], 'launch-builder' ), $f[1], $f[2], '' === $f[3] ? '' : __( $f[3], 'launch-builder' ) ]; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		return $out;
	}

	/** The stored values (only the known fields, as saved). */
	public static function values(): array {
		$raw = get_option( self::OPT, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$out = [];
		foreach ( array_keys( self::FIELDS ) as $k ) {
			$out[ $k ] = is_scalar( $raw[ $k ] ?? null ) ? trim( (string) $raw[ $k ] ) : '';
		}
		return $out;
	}

	/** Demo values print only while the site asks search engines to stay away (Settings > Reading). */
	public static function setup_mode(): bool {
		return (bool) apply_filters( 'launch_builder_company_demo', '0' === (string) get_option( 'blog_public', '1' ) );
	}

	/** Every field resolved: key => { value, from: saved | derived | wordpress | demo | empty }. */
	public static function resolve(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$own = self::values();
		$demo = self::setup_mode();
		$out = [];
		foreach ( self::FIELDS as $k => $f ) {
			if ( '' !== $own[ $k ] ) {
				$out[ $k ] = [ 'value' => $own[ $k ], 'from' => 'saved' ];
				continue;
			}
			$derived = self::derive( $k, $own );
			if ( '' !== $derived ) {
				$out[ $k ] = [ 'value' => $derived, 'from' => 'name' === $k ? 'wordpress' : 'derived' ];
			} elseif ( $demo && '' !== $f[2] ) {
				$out[ $k ] = [ 'value' => $f[2], 'from' => 'demo' ];
			} else {
				$out[ $k ] = [ 'value' => '', 'from' => 'empty' ];
			}
		}
		if ( 'demo' === $out['phone']['from'] && 'derived' !== $out['phone_link']['from'] && 'saved' !== $out['phone_link']['from'] ) {
			$out['phone_link'] = [ 'value' => self::tel( $out['phone']['value'] ), 'from' => 'demo' ];
		}
		if ( 'demo' === $out['address']['from'] && 'empty' === $out['maps_url']['from'] ) {
			$out['maps_url'] = [ 'value' => self::maps( $out['address']['value'] ), 'from' => 'demo' ];
		}
		return self::$resolved = $out;
	}

	/** One field's value (resolved), '' for an unknown key. */
	public static function get( string $key ): string {
		return (string) ( self::resolve()[ $key ]['value'] ?? '' );
	}

	/** A value worked out from the other fields. */
	private static function derive( string $key, array $own ): string {
		switch ( $key ) {
			case 'name':
				return (string) get_bloginfo( 'name' );
			case 'phone_link':
				return self::tel( $own['phone'] );
			case 'maps_url':
				return self::maps( $own['address'] );
		}
		return '';
	}

	/** tel: link for a phone number as people write it ("(555) 123-4567 ext 2" -> tel:+15551234567,2 is not attempted). */
	public static function tel( string $phone ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone );
		return '' === (string) $digits ? '' : 'tel:' . $digits;
	}

	/** A Google Maps search for an address. */
	public static function maps( string $address ): string {
		return '' === trim( $address ) ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	}

	/** Fields whose value is a link (usable as href="#lb-{key}"). */
	public static function is_link_key( string $key ): bool {
		return isset( self::FIELDS[ $key ] ) && 'url' === self::FIELDS[ $key ][1];
	}

	/** Essentials that are still empty or demo. */
	public static function missing(): array {
		$res = self::resolve();
		return array_values( array_filter( self::ESSENTIALS, static function ( $k ) use ( $res ) { return in_array( $res[ $k ]['from'], [ 'empty', 'demo' ], true ); } ) );
	}

	/** render_block: tokens in each block's HTML. */
	public static function filter_block( $html, $block = null ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return Tokens::render_block( $html, is_array( $block ) ? $block : [] );
	}

	/** [lb field="phone"] */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts( [ 'field' => '' ], $atts, 'lb' );
		return Tokens::html( 'lb', sanitize_key( (string) $a['field'] ) );
	}

	/* ------------------------------------------------------------------ writing */

	/** A value cleaned for its type ('' when it is not valid). */
	public static function clean( string $key, $value ): string {
		$v = trim( is_scalar( $value ) ? (string) $value : '' );
		switch ( self::FIELDS[ $key ][1] ?? 'text' ) {
			case 'email':
				return '' === $v ? '' : (string) sanitize_email( $v );
			case 'url':
				if ( '' === $v ) {
					return '';
				}
				if ( 0 === strpos( $v, '/' ) || 0 === strpos( $v, '#' ) ) {
					return esc_url_raw( $v );
				}
				return (string) esc_url_raw( $v, [ 'http', 'https', 'tel', 'mailto', 'sms' ] );
			default:
				return sanitize_text_field( $v );
		}
	}

	/**
	 * Save some fields (others keep their values; '' clears one). Returns { saved, rejected: [key => reason] }.
	 *
	 * @param array<string,mixed> $in
	 */
	public static function update( array $in ): array {
		$cur = (array) get_option( self::OPT, [] );
		$saved = [];
		$rejected = [];
		foreach ( $in as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( ! isset( self::FIELDS[ $k ] ) ) {
				$rejected[ $k ] = 'Not a Business Info field (fields: ' . implode( ', ', array_keys( self::FIELDS ) ) . ').';
				continue;
			}
			$clean = self::clean( $k, $v );
			if ( '' === $clean && '' !== trim( is_scalar( $v ) ? (string) $v : '' ) ) {
				$rejected[ $k ] = 'email' === self::FIELDS[ $k ][1] ? 'Not an email address.' : 'Not a valid link.';
				continue;
			}
			$cur[ $k ] = $clean;
			$saved[] = $k;
		}
		if ( $saved ) {
			update_option( self::OPT, $cur, true );
		}
		return [ 'saved' => $saved, 'rejected' => $rejected ];
	}

	/** The option changed (here, in Meta Box, by a snapshot restore): forget the memo and purge cached pages. */
	public static function changed(): void {
		self::$resolved = null;
		Cache::purge_later();
	}

	/* ------------------------------------------------------------------ admin screen */

	/** The Meta Box settings page that edits Business Info, when there is one: its admin URL, else ''. */
	public static function meta_box_url(): string {
		$pages = (array) apply_filters( 'mb_settings_pages', [] );
		foreach ( $pages as $p ) {
			if ( is_array( $p ) && self::OPT === ( $p['option_name'] ?? $p['id'] ?? '' ) && ! empty( $p['id'] ) ) {
				$parent = (string) ( $p['parent'] ?? '' );
				return '' !== $parent && false !== strpos( $parent, '.php' ) ? admin_url( $parent . ( false !== strpos( $parent, '?' ) ? '&' : '?' ) . 'page=' . $p['id'] ) : admin_url( 'admin.php?page=' . $p['id'] );
			}
		}
		return '';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = self::resolve();
		$own = self::values();
		echo '<div class="wrap lb-wrap lb-biz">';
		echo Dashboard::header( __( 'Business info', 'launch-builder' ), esc_html__( 'Set your details once: every page, header and footer shows them through tokens such as {{lb.phone}}.', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the call.
		$flash = get_transient( 'lb_company_flash_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'lb_company_flash_' . get_current_user_id() );
			foreach ( (array) ( $flash['rejected'] ?? [] ) as $k => $why ) {
				echo '<div class="notice notice-error"><p>' . esc_html( ( self::fields()[ $k ][0] ?? $k ) . ': ' . $why ) . '</p></div>';
			}
			if ( ! empty( $flash['saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Business Info saved.', 'launch-builder' ) . '</p></div>';
			}
		}
		$mb = self::meta_box_url();
		if ( '' !== $mb ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'These values live in Meta Box, so they stay with the site if Launch Builder is ever removed. You can edit them here or on the Meta Box settings page.', 'launch-builder' ) . ' <a href="' . esc_url( $mb ) . '">' . esc_html__( 'Open in Meta Box', 'launch-builder' ) . '</a></p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-form"><input type="hidden" name="action" value="lb_company_save">';
		wp_nonce_field( 'lb_company_save' );
		echo '<section class="lb-panel"><table class="form-table" role="presentation"><tbody>';
		foreach ( self::fields() as $k => $f ) {
			$type = 'url' === $f[1] ? 'text' : $f[1];
			$ph = '' !== $own[ $k ] ? '' : ( 'empty' === $res[ $k ]['from'] ? $f[2] : $res[ $k ]['value'] );
			echo '<tr><th scope="row"><label for="lb-biz-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
			echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="lb-biz-' . esc_attr( $k ) . '" name="biz[' . esc_attr( $k ) . ']" value="' . esc_attr( $own[ $k ] ) . '" placeholder="' . esc_attr( $ph ) . '">';
			echo '<p class="description"><code>{{lb.' . esc_html( $k ) . '}}</code>' . ( '' !== $f[3] ? ' ' . esc_html( $f[3] ) : '' ) . '</p></td></tr>';
		}
		echo '</tbody></table></section>';
		submit_button( __( 'Save Business Info', 'launch-builder' ) );
		echo '</form></div>';
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_company_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$in = (array) wp_unslash( $_POST['biz'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned per field in update().
		$snap = Abilities::snapshot( 'business-info', [], [ self::OPT ] );
		$r = self::update( array_intersect_key( $in, self::FIELDS ) );
		$r['snapshot'] = $snap;
		set_transient( 'lb_company_flash_' . get_current_user_id(), $r, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/* ------------------------------------------------------------------ tools and module hooks */

	public static function define_tools(): void {
		Abilities::add( 'get-company', 'Read Business Info', 'The eight Business Info values with where each comes from (saved, derived, wordpress, demo, empty) and its token: name, phone, phone_link, email, address, maps_url, cta_label, cta_url. missing lists the essentials still empty or demo.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_tool' ], 'edit_pages', [ 'readonly' => true ] );
		Abilities::add( 'set-company', 'Set Business Info', 'Save Business Info: name, phone, phone_link (empty = tel: from the phone), email, address (one line), maps_url (empty = a Google Maps search for the address), cta_label (the main button text), cta_url (where it goes). Only the fields given change; "" clears one. Snapshot first.',
			[ 'properties' => [ 'fields' => [ 'type' => 'object', 'properties' => array_map( static function () { return [ 'type' => 'string' ]; }, self::FIELDS ) ] ], 'required' => [ 'fields' ] ], [ __CLASS__, 'set_tool' ] );
	}

	public static function get_tool(): array {
		$out = [];
		foreach ( self::resolve() as $k => $r ) {
			$out[ $k ] = $r + [ 'token' => '{{lb.' . $k . '}}' ];
		}
		return [ 'fields' => $out, 'missing' => self::missing(), 'meta_box' => '' !== self::meta_box_url() ];
	}

	public static function set_tool( $input ) {
		$fields = (array) ( ( (array) $input )['fields'] ?? [] );
		if ( ! $fields ) {
			return new \WP_Error( 'lb_company', 'fields is required.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-company', [], [ self::OPT ] );
		return self::update( $fields ) + [ 'snapshot' => $snap ];
	}

	public static function site_state( array &$state ): void {
		$state['business'] = array_map( static function ( $r ) { return $r['value']; }, self::resolve() );
		$state['business_missing'] = self::missing();
	}

	public static function playbook( array &$pb ): void {
		$pb['business'] = 'Business Info is eight values (get-company / set-company): name, phone, phone_link, email, address, maps_url, cta_label, cta_url. Print them with {{lb.key}} in any text and href="#lb-phone_link" / "#lb-maps_url" / "#lb-cta_url" in links. Empty links are worked out (tel: from the phone, a map search from the address). Demo values print only while the site is hidden from search engines.';
	}

	public static function audit_checks(): array {
		return [ 'company_missing' => [ 'company', [ __CLASS__, 'audit_missing' ] ] ];
	}

	public static function audit_missing(): array {
		$miss = self::missing();
		if ( ! $miss ) {
			return [];
		}
		return [ [ 'severity' => self::setup_mode() ? 'warning' : 'error', 'code' => 'company_missing', 'object_type' => 'option', 'id' => 0, 'url' => admin_url( 'admin.php?page=' . self::PAGE ), 'message' => 'Business Info is missing: ' . implode( ', ', $miss ) . '.', 'fix' => 'set-company, or Launch Builder > Business Info.' ] ];
	}

	/**
	 * Migration step business-info-v2: the eight values from the pre-v2 option (launch_builder_company), once, into
	 * business_info. The address is the old one-line address, else street, city, state and ZIP joined; the button link is
	 * cta_url, else quote_url. Values already in business_info win. The old option is left as it was.
	 */
	public static function migrate_v2( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$old = get_option( self::LEGACY_OPT, [] );
		if ( ! is_array( $old ) || ! $old ) {
			return [ 'done' => true, 'report' => '' ];
		}
		$s = static function ( string $k ) use ( $old ): string {
			return is_scalar( $old[ $k ] ?? null ) ? trim( (string) $old[ $k ] ) : '';
		};
		$address = $s( 'address' );
		if ( '' === $address ) {
			$city = trim( implode( ', ', array_filter( [ $s( 'locality' ), trim( $s( 'region' ) . ' ' . $s( 'postal_code' ) ) ], 'strlen' ) ) );
			$address = trim( implode( ', ', array_filter( [ $s( 'street' ), $city ], 'strlen' ) ) );
		}
		$map = [ 'name' => $s( 'name' ), 'phone' => $s( 'phone' ), 'phone_link' => $s( 'phone_link' ), 'email' => $s( 'email' ), 'address' => $address, 'maps_url' => $s( 'maps_url' ), 'cta_label' => $s( 'cta_label' ), 'cta_url' => '' !== $s( 'cta_url' ) ? $s( 'cta_url' ) : $s( 'quote_url' ) ];
		$cur = self::values();
		$set = [];
		foreach ( $map as $k => $v ) {
			if ( '' === $cur[ $k ] && '' !== $v && '#' !== $v ) {
				$set[ $k ] = $v;
			}
		}
		$r = $set ? self::update( $set ) : [ 'saved' => [] ];
		return [ 'done' => true, 'report' => $r['saved'] ? 'moved: ' . implode( ', ', $r['saved'] ) : '' ];
	}
}
