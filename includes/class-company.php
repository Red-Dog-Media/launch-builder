<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Global business info: phone, email, address, hours, buttons, form embed, social links. Set once, used everywhere.
 *
 * Patterns carry tokens instead of copy:
 *   {{lb.phone}}        in text (escaped for you)
 *   #lb-phone_link      as a link target (href="#lb-cta_url")
 * Both are swapped for the current value every time a block is rendered, so changing the value updates every page,
 * synced pattern, header and footer at once. Anything else can use [lb field="phone"].
 *
 * Values come from (first non-empty wins): an optional existing settings option you point us at (for example a Meta Box
 * settings page), then the values saved here, then a visible demo value so an unconfigured site still looks finished.
 */
final class Company {

	const OPT     = 'launch_builder_company';
	const OPT_SRC = 'launch_builder_company_source';

	/** Admin groups, in order (index 5 of each field). The first six are the Business Info screen's sections. */
	const GROUPS = [ 'Basics', 'Locations, hours and licences', 'Buttons and links', 'Form embed', 'Social profiles', 'Writing brief for AI copy', 'Structured address', 'Structured hours', 'Business details', 'Reviews and proof', 'Promotions and financing', 'Logos' ];

	/**
	 * Computed tokens that are not implemented yet: the exact names, published for the
	 * generator and the token catalog. They are not in computed() and do not resolve yet, so get-company does not offer
	 * them for use; Tokens::catalog() lists them as planned (available false).
	 */
	const COMPUTED_PLANNED = [
		'review_count_line' => 'Review count line, e.g. "Based on 887+ reviews" (empty without reviews)',
		'review_line'       => 'Short rating line for header bars (rating and review count)',
		'service_count'     => 'Number of published services',
		'area_count'        => 'Number of published service areas',
	];

	/** @var array<string,string>|null per-request cache of the effective values. */
	private static $cache = null;

	public static function boot(): void {
		add_filter( 'render_block', [ __CLASS__, 'filter_block' ], 20 );
		add_shortcode( 'lb', [ __CLASS__, 'shortcode' ] );
		add_action( 'admin_post_lb_company_save', [ __CLASS__, 'save' ] );
	}

	/**
	 * The Business Info field contract.
	 *
	 * @return array<string,array> key => [ label, type, demo value, aliases used when reading another settings option, help, group ]
	 *   type: text | textarea | url | email | html (resolved and escaped today) and, for the structured fields added for
	 *   the Tokens/schema work, number | bool | date | image (attachment id or URL) | list (array of objects). New fields
	 *   are data only for now: they save and resolve like text, a list field cannot be saved yet, and none has a demo
	 *   value (no fabricated ratings, counts or '#' links).
	 */
	public static function fields(): array {
		return [
			'tagline'    => [ 'Tagline (under page titles)', 'text', 'Trusted local experts in Your City, ST', [ 'tagline', 'slogan', 'brand_tagline' ], 'The line shown under the title in the shared page hero.', 'Basics' ],
			'name'       => [ 'Business name', 'text', 'Your Business', [ 'business_name', 'company_name', 'name' ], '', 'Basics' ],
			'phone'      => [ 'Phone number (as shown)', 'text', '(555) 555-0123', [ 'phone_number', 'phone', 'telephone' ], '', 'Basics' ],
			'phone_link' => [ 'Phone link (tel:)', 'url', '', [ 'linked_phone_number', 'phone_link', 'tel_link' ], 'Leave blank to build it from the phone number.', 'Basics' ],
			'email'      => [ 'Email', 'email', 'hello@yourbusiness.com', [ 'email', 'email_address', 'business_email' ], '', 'Basics' ],
			'address'    => [ 'Address (one line)', 'text', '123 Main Street, Your City, ST 00000', [ 'address', 'street_address', 'full_address' ], 'Street first, then city, state and zip.', 'Locations, hours and licences' ],
			'address2'   => [ 'Second office address', 'text', 'Second City, ST', [ 'address2', 'second_address', 'office_2' ], 'Optional, for footers that list two offices.', 'Locations, hours and licences' ],
			'license'    => [ 'License numbers', 'textarea', 'License # 00000', [ 'license', 'licenses', 'license_numbers', 'licensing' ], 'One per line, for example HVAC License # CAC123456.', 'Locations, hours and licences' ],
			'city'       => [ 'City / area served', 'text', 'Your City, ST', [ 'location', 'city', 'service_area' ], '', 'Locations, hours and licences' ],
			'hours'      => [ 'Hours', 'text', 'Mon–Fri 8am–6pm · Sat 9am–2pm', [ 'hours', 'business_hours', 'opening_hours' ], 'Separate lines with a dot or semicolon.', 'Locations, hours and licences' ],
			'quote_url'  => [ 'Quote / contact page', 'url', '', [ 'quote_page', 'quote_url', 'quote_link', 'contact_page' ], '', 'Buttons and links' ],
			'cta_label'  => [ 'Main button text', 'text', 'Get a Free Quote', [ 'cta_label', 'cta_text', 'button_text' ], 'Every main call-to-action button.', 'Buttons and links' ],
			'cta_url'    => [ 'Main button link', 'url', '', [ 'cta_url', 'cta_link', 'button_url' ], 'Blank = the quote page.', 'Buttons and links' ],
			'cta2_label' => [ 'Second button text', 'text', 'Call Us Today', [ 'cta2_label', 'cta_secondary_label' ], '', 'Buttons and links' ],
			'cta2_url'   => [ 'Second button link', 'url', '', [ 'cta2_url', 'cta_secondary_url' ], 'Blank = the phone link.', 'Buttons and links' ],
			'maps_url'   => [ 'Directions link', 'url', '', [ 'maps_url', 'directions_url', 'google_maps_link' ], 'Blank = a Google Maps search for the address.', 'Locations, hours and licences' ],
			'industry'   => [ 'Industry / what you do', 'text', '', [ 'industry', 'business_type', 'niche' ], 'Brief for writing copy, e.g. residential plumbing.', 'Writing brief for AI copy' ],
			'about'      => [ 'About the business', 'textarea', '', [ 'about', 'description', 'business_description', 'mission' ], 'Two or three sentences: who you serve, what makes you different.', 'Writing brief for AI copy' ],
			'keywords'   => [ 'Keywords', 'text', '', [ 'keywords', 'seo_keywords' ], 'Comma-separated phrases to work into copy.', 'Writing brief for AI copy' ],
			'tone'       => [ 'Tone of voice', 'text', '', [ 'tone', 'tone_of_voice', 'voice' ], 'e.g. friendly, direct, professional.', 'Writing brief for AI copy' ],
			'form_html'  => [ 'Form embed (HTML)', 'html', '', [ 'site_form', 'form_embed', 'form', 'contact_form' ], 'Paste the embed code (iframe and script). Used by the form patterns.', 'Form embed' ],
			'facebook'   => [ 'Facebook URL', 'url', '#', [ 'facebook_url', 'facebook' ], '', 'Social profiles' ],
			'instagram'  => [ 'Instagram URL', 'url', '#', [ 'instagram_url', 'instagram' ], '', 'Social profiles' ],
			'google'     => [ 'Google profile URL', 'url', '#', [ 'google_url', 'google_business_profile', 'google' ], '', 'Social profiles' ],
			'youtube'    => [ 'YouTube URL', 'url', '#', [ 'youtube_url', 'youtube' ], '', 'Social profiles' ],

			// Structured fields: stored as data; nothing renders them yet.
			'emergency_phone' => [ 'Emergency / after-hours phone', 'text', '', [ 'emergency_phone', 'after_hours_phone' ], '', 'Basics' ],
			'street'          => [ 'Street address', 'text', '', [ 'street', 'address_line_1', 'street_line' ], 'When the structured address is set, the one-line address is built from it.', 'Structured address' ],
			'locality'        => [ 'City (locality)', 'text', '', [ 'locality', 'address_city', 'city_name' ], '', 'Structured address' ],
			'region'          => [ 'State / region', 'text', '', [ 'region', 'state', 'address_state', 'province' ], 'e.g. TX', 'Structured address' ],
			'postal_code'     => [ 'ZIP / postal code', 'text', '', [ 'postal_code', 'zip', 'zip_code', 'postcode' ], '', 'Structured address' ],
			'country'         => [ 'Country', 'text', '', [ 'country', 'country_code' ], 'Two-letter code, e.g. US.', 'Structured address' ],
			'lat'             => [ 'Latitude', 'number', '', [ 'lat', 'latitude' ], '', 'Structured address' ],
			'lng'             => [ 'Longitude', 'number', '', [ 'lng', 'lon', 'longitude' ], '', 'Structured address' ],
			'areas_served'    => [ 'Areas served', 'list', '', [ 'areas_served' ], 'List of cities or regions (schema areaServed).', 'Structured address' ],
			'hours_spec'      => [ 'Opening hours (structured)', 'list', '', [ 'hours_spec', 'opening_hours_spec' ], '[{days:["Mo","Tu"], opens:"08:00", closes:"18:00"}]. When set, the one-line hours are built from it.', 'Structured hours' ],
			'holiday_hours'   => [ 'Holiday hours', 'list', '', [ 'holiday_hours', 'special_hours' ], '[{date:"2026-12-25", opens, closes} or {date, closed:true}]', 'Structured hours' ],
			'is_24_7'         => [ 'Open 24/7', 'bool', '', [ 'is_24_7', 'open_24_7', 'always_open' ], '', 'Structured hours' ],
			'price_range'     => [ 'Price range', 'text', '', [ 'price_range' ], 'One to four dollar signs.', 'Business details' ],
			'business_type'   => [ 'Business type (schema)', 'text', '', [ 'schema_business_type', 'local_business_type' ], 'A schema.org LocalBusiness type, e.g. Plumber, HVACBusiness, Electrician.', 'Business details' ],
			'years'           => [ 'Years in business', 'number', '', [ 'years', 'years_in_business' ], 'Leave blank rather than guess.', 'Business details' ],
			'jobs'            => [ 'Jobs completed', 'number', '', [ 'jobs', 'jobs_completed' ], 'Leave blank rather than guess.', 'Business details' ],
			'techs'           => [ 'Technicians', 'number', '', [ 'techs', 'technicians', 'team_size' ], 'Leave blank rather than guess.', 'Business details' ],
			'reviews'         => [ 'Review platforms', 'list', '', [ 'reviews' ], '[{platform:"google", rating:4.9, count:887, url}]', 'Reviews and proof' ],
			'rating'          => [ 'Overall rating', 'number', '', [ 'rating', 'overall_rating', 'google_rating' ], 'e.g. 4.9. Blank = computed from the review platforms. Never fabricated.', 'Reviews and proof' ],
			'review_count'    => [ 'Review count', 'number', '', [ 'review_count', 'reviews_count', 'number_of_reviews' ], 'Blank = computed from the review platforms.', 'Reviews and proof' ],
			'review_url'      => [ 'Leave-a-review link', 'url', '', [ 'review_url', 'review_link' ], '', 'Reviews and proof' ],
			'gbp_place_id'    => [ 'Google Business Profile place ID', 'text', '', [ 'gbp_place_id', 'place_id', 'google_place_id' ], '', 'Reviews and proof' ],
			'promo_text'      => [ 'Promotion text', 'text', '', [ 'promo_text', 'promotion', 'offer_text' ], 'e.g. 50 dollars off any repair this month.', 'Promotions and financing' ],
			'promo_url'       => [ 'Promotion link', 'url', '', [ 'promo_url', 'promotion_url', 'offer_url' ], '', 'Promotions and financing' ],
			'promo_expires'   => [ 'Promotion ends', 'date', '', [ 'promo_expires', 'promotion_expires', 'offer_expires' ], 'YYYY-MM-DD; the promotion stops showing after it.', 'Promotions and financing' ],
			'financing_url'   => [ 'Financing link', 'url', '', [ 'financing_url', 'financing_link' ], '', 'Promotions and financing' ],
			'financing_text'  => [ 'Financing text', 'text', '', [ 'financing_text', 'financing' ], 'e.g. 0% financing for 12 months.', 'Promotions and financing' ],
			'yelp'            => [ 'Yelp URL', 'url', '', [ 'yelp_url', 'yelp' ], '', 'Social profiles' ],
			'tiktok'          => [ 'TikTok URL', 'url', '', [ 'tiktok_url', 'tiktok' ], '', 'Social profiles' ],
			'linkedin'        => [ 'LinkedIn URL', 'url', '', [ 'linkedin_url', 'linkedin' ], '', 'Social profiles' ],
			'x'               => [ 'X (Twitter) URL', 'url', '', [ 'x_url', 'twitter_url', 'twitter' ], '', 'Social profiles' ],
			'nextdoor'        => [ 'Nextdoor URL', 'url', '', [ 'nextdoor_url', 'nextdoor' ], '', 'Social profiles' ],
			'bbb'             => [ 'BBB profile URL', 'url', '', [ 'bbb_url', 'bbb' ], '', 'Social profiles' ],
			'angi'            => [ 'Angi profile URL', 'url', '', [ 'angi_url', 'angi', 'angies_list' ], '', 'Social profiles' ],
			'houzz'           => [ 'Houzz profile URL', 'url', '', [ 'houzz_url', 'houzz' ], '', 'Social profiles' ],
			'logo_inverse'    => [ 'Logo for dark backgrounds', 'image', '', [ 'logo_inverse', 'logo_light', 'logo_white' ], 'Attachment id or URL.', 'Logos' ],
			'site_icon'       => [ 'Site icon', 'image', '', [ 'site_icon', 'favicon' ], 'Attachment id or URL (square, 512px).', 'Logos' ],
		];
	}

	/** Tokens that are computed, not stored. */
	public static function computed(): array {
		return [ 'address_br' => 'Address on two lines', 'hours_br' => 'Hours, one line per entry', 'year' => 'Current year (for copyright lines)' ];
	}

	/* ------------------------------------------------------------------ values */

	private static function tel( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '#';
		}
		if ( 10 === strlen( $digits ) ) {
			return 'tel:+1' . $digits;
		}
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			return 'tel:+' . $digits;
		}
		return 'tel:' . ( 0 === strpos( trim( $phone ), '+' ) ? '+' : '' ) . $digits;
	}

	/** Values read from the optional external settings option (token => string). */
	private static function external(): array {
		$src = get_option( self::OPT_SRC );
		if ( ! is_array( $src ) || empty( $src['option'] ) || ! Abilities::option_readable( $src['option'] ) ) {
			return [];
		}
		$arr = get_option( $src['option'] );
		if ( ! is_array( $arr ) ) {
			return [];
		}
		$map = (array) ( $src['map'] ?? [] );
		$out = [];
		foreach ( self::fields() as $k => $f ) {
			$cands = ( isset( $map[ $k ] ) && '' !== $map[ $k ] ) ? [ $map[ $k ] ] : $f[3];
			foreach ( $cands as $c ) {
				if ( isset( $arr[ $c ] ) && is_scalar( $arr[ $c ] ) && '' !== trim( (string) $arr[ $c ] ) ) {
					$out[ $k ] = (string) $arr[ $c ];
					break;
				}
			}
		}
		return $out;
	}

	/** Where each effective value comes from, for the admin page and get-company. @return array<string,array{value:string,from:string}> */
	public static function resolve(): array {
		$own = (array) get_option( self::OPT, [] );
		$ext = self::external();
		$out = [];
		foreach ( self::fields() as $k => $f ) {
			if ( isset( $ext[ $k ] ) ) {
				$out[ $k ] = [ 'value' => trim( $ext[ $k ] ), 'from' => 'source' ];
			} elseif ( isset( $own[ $k ] ) && '' !== trim( (string) $own[ $k ] ) ) {
				$out[ $k ] = [ 'value' => trim( (string) $own[ $k ] ), 'from' => 'saved' ];
			} else {
				$out[ $k ] = [ 'value' => (string) $f[2], 'from' => 'default' ];
			}
		}
		// Fallbacks between related fields.
		if ( 'default' === $out['phone_link']['from'] ) {
			$out['phone_link'] = [ 'value' => self::tel( $out['phone']['value'] ), 'from' => 'derived' ];
		} elseif ( ! preg_match( '#^(tel:|sms:|mailto:|https?:|/|\#)#i', $out['phone_link']['value'] ) ) {
			$out['phone_link']['value'] = 'tel:' . $out['phone_link']['value'];
		}
		if ( 'default' === $out['cta_url']['from'] ) {
			$out['cta_url'] = [ 'value' => '' !== $out['quote_url']['value'] ? $out['quote_url']['value'] : '#', 'from' => 'derived' ];
		}
		if ( 'default' === $out['cta2_url']['from'] ) {
			$out['cta2_url'] = [ 'value' => $out['phone_link']['value'], 'from' => 'derived' ];
		}
		if ( 'default' === $out['maps_url']['from'] ) {
			$out['maps_url'] = [ 'value' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $out['address']['value'] ), 'from' => 'derived' ];
		}
		return $out;
	}

	/** Effective plain values (unescaped). */
	public static function values(): array {
		if ( null === self::$cache ) {
			self::$cache = array_map( static function ( $r ) { return $r['value']; }, self::resolve() );
		}
		return self::$cache;
	}

	/** Escaped output for a token, or null if the token is unknown. */
	private static function out( string $key ): ?string {
		$v = self::values();
		$fields = self::fields();
		switch ( $key ) {
			case 'year':
				return wp_date( 'Y' );
			case 'address_br':
				return implode( '<br>', array_map( 'esc_html', array_map( 'trim', explode( ',', $v['address'], 2 ) ) ) );
			case 'hours_br':
				return implode( '<br>', array_map( 'esc_html', array_filter( array_map( 'trim', (array) preg_split( '/[·|;\r\n]+/u', $v['hours'] ) ) ) ) );
		}
		if ( ! isset( $fields[ $key ] ) ) {
			return null;
		}
		$val = $v[ $key ];
		switch ( $fields[ $key ][1] ) {
			case 'html':
				if ( '' === $val ) { // Nothing configured yet: tell editors, show visitors nothing.
					return current_user_can( 'edit_posts' ) ? '<div style="border:2px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;color:#64748b;font-size:15px">Your form embed appears here. Paste it under Settings &rarr; Business Info &rarr; Form embed.</div>' : '';
				}
				return $val; // Admin-controlled embed code (iframe/script); stored with kses unless the saver has unfiltered_html.
			case 'url':
				return esc_url( $val );
			case 'textarea':
				return nl2br( esc_html( $val ) );
			default:
				return esc_html( $val );
		}
	}

	/* ------------------------------------------------------------------ rendering */

	public static function filter_block( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$tok  = false !== strpos( $html, '{{lb.' );
		$hash = false !== strpos( $html, '"#lb-' );
		$enc  = false !== strpos( $html, '%7B%7Blb.' );
		if ( ! $tok && ! $hash && ! $enc ) {
			return $html;
		}
		if ( $hash ) {
			$html = preg_replace_callback( '/href="#lb-([a-z0-9_]+)"/', static function ( $m ) {
				$v = self::out( $m[1] );
				return null === $v ? $m[0] : 'href="' . $v . '"';
			}, $html );
		}
		if ( $tok ) {
			$html = preg_replace_callback( '/\{\{lb\.([a-z0-9_]+)\}\}/', static function ( $m ) {
				$v = self::out( $m[1] );
				return null === $v ? $m[0] : $v;
			}, $html );
		}
		if ( $enc ) { // Inside a URL (for example a map query) the token is percent-encoded.
			$html = preg_replace_callback( '/%7B%7Blb\.([a-z0-9_]+)%7D%7D/i', static function ( $m ) {
				$k = strtolower( $m[1] );
				$v = self::values();
				return isset( $v[ $k ] ) ? rawurlencode( wp_strip_all_tags( $v[ $k ] ) ) : $m[0];
			}, $html );
		}
		return $html;
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts( [ 'field' => '' ], $atts, 'lb' );
		$v = self::out( sanitize_key( $a['field'] ) );
		return null === $v ? '' : $v;
	}

	/* ------------------------------------------------------------------ writing */

	private static function clean( string $type, string $v ): string {
		$v = trim( $v );
		switch ( $type ) {
			case 'url':
				return mb_substr( '' === $v ? '' : ( '#' === $v[0] ? '#' . sanitize_key( substr( $v, 1 ) ) : esc_url_raw( $v ) ), 0, 500 );
			case 'email':
				return mb_substr( sanitize_email( $v ), 0, 200 );
			case 'textarea':
				return mb_substr( sanitize_textarea_field( $v ), 0, 2000 );
			case 'html':
				return mb_substr( current_user_can( 'unfiltered_html' ) ? $v : wp_kses_post( $v ), 0, 20000 );
			default:
				return mb_substr( sanitize_text_field( $v ), 0, 300 );
		}
	}

	/** Save values (only known keys). @return string[] keys that changed */
	public static function update( array $values ): array {
		$fields = self::fields();
		$own = (array) get_option( self::OPT, [] );
		$changed = [];
		foreach ( $values as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( ! isset( $fields[ $k ] ) || ! is_scalar( $v ) ) {
				continue;
			}
			$clean = self::clean( $fields[ $k ][1], (string) $v );
			if ( (string) ( $own[ $k ] ?? '' ) !== $clean ) {
				$own[ $k ] = $clean;
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			update_option( self::OPT, $own );
			self::flush();
		}
		return $changed;
	}

	/** Point at another settings option (for example a Meta Box settings page). Pass '' to clear. @return array|\WP_Error the stored source */
	public static function set_source( string $option, array $map = [] ) {
		$option = preg_replace( '/[^A-Za-z0-9_\-]/', '', $option );
		if ( '' === $option ) {
			delete_option( self::OPT_SRC );
			self::flush();
			return [];
		}
		if ( ! Abilities::option_readable( $option ) ) {
			return new \WP_Error( 'lb_refused', 'That option can\'t be used as a source.', [ 'status' => 403 ] );
		}
		$fields = self::fields();
		$clean = [];
		foreach ( $map as $k => $field ) {
			$k = sanitize_key( (string) $k );
			$field = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $field );
			if ( isset( $fields[ $k ] ) && '' !== $field ) {
				$clean[ $k ] = $field;
			}
		}
		$src = [ 'option' => $option, 'map' => $clean ];
		update_option( self::OPT_SRC, $src );
		self::flush();
		return $src;
	}

	/** Changed values must show on cached pages straight away: one coalesced purge for this request (see Cache). */
	private static function flush(): void {
		self::$cache = null;
		Cache::purge_later();
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'get-company', 'Read the global business info', 'Business facts used by every pattern through tokens ({{lb.phone}} in text, #lb-cta_url in link fields): name, phone, email, address, hours, button text and links, form embed, social links. Shows the saved value, the value in effect and where it comes from. Patterns show a visible demo value until a field is set.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_tool' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'set-company', 'Set the global business info', 'Change business facts once and every page, header and footer updates. values: {name, phone, phone_link, email, address, city, hours, quote_url, cta_label, cta_url, cta2_label, cta2_url, maps_url, form_html, facebook, instagram, google, youtube}. Blank link fields fall back (cta_url = quote page, cta2_url = phone link). source (optional): read from another settings option instead, for example a Meta Box settings page: {option:"company_information", map:{phone:"phone_number"}}; unmapped tokens are matched by common names. {option:""} clears it. Use find-options to locate the option.',
			[ 'properties' => [ 'values' => [ 'type' => 'object' ], 'source' => [ 'type' => 'object' ] ] ], [ __CLASS__, 'set_tool' ], 'manage_options' );
	}

	/** Module hook: where each Business Info value comes from, in site-state. */
	public static function site_state( array &$state ): void {
		$state['company'] = array_map( static function ( $r ) { return $r['from']; }, self::resolve() );
	}

	public static function describe(): array {
		$own = (array) get_option( self::OPT, [] );
		$res = self::resolve();
		$rows = [];
		foreach ( self::fields() as $k => $f ) {
			$eff = $res[ $k ]['value'];
			$rows[] = [
				'key'       => $k,
				'label'     => $f[0],
				'type'      => $f[1],
				'token'     => '{{lb.' . $k . '}}',
				'link_token' => 'url' === $f[1] ? '#lb-' . $k : null,
				'saved'     => 'html' === $f[1] ? mb_substr( (string) ( $own[ $k ] ?? '' ), 0, 80 ) : (string) ( $own[ $k ] ?? '' ),
				'effective' => 'html' === $f[1] ? mb_substr( $eff, 0, 80 ) : $eff,
				'from'      => $res[ $k ]['from'],
			];
		}
		$src = get_option( self::OPT_SRC );
		return [
			'fields'   => $rows,
			'computed' => array_map( static function ( $k ) { return '{{lb.' . $k . '}}'; }, array_keys( self::computed() ) ),
			'source'   => is_array( $src ) ? $src : null,
			'usage'    => 'Text: {{lb.phone}}. Link fields: #lb-phone_link, #lb-cta_url. Anywhere else: [lb field="phone"].',
		];
	}

	public static function get_tool(): array {
		return self::describe();
	}

	public static function set_tool( $input ) {
		$values = (array) ( $input['values'] ?? [] );
		$has_src = array_key_exists( 'source', (array) $input );
		if ( ! $values && ! $has_src ) {
			return new \WP_Error( 'lb_empty', 'Give values and/or source.', [ 'status' => 400 ] );
		}
		$snap = Abilities::snapshot( 'set-company', [], [ self::OPT, self::OPT_SRC ] );
		$changed = $values ? self::update( $values ) : [];
		$src_changed = false;
		if ( $has_src ) {
			$s = (array) $input['source'];
			$r = self::set_source( (string) ( $s['option'] ?? '' ), (array) ( $s['map'] ?? [] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$src_changed = true;
		}
		return [ 'changed' => $changed, 'source_changed' => $src_changed, 'snapshot' => $snap ] + self::describe();
	}

	/* ------------------------------------------------------------------ admin page */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_company_save' ) ) {
			wp_die( 'Not allowed.', '', [ 'response' => 403 ] );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified above; every value is sanitised in update()/set_source().
		self::update( (array) ( $post['lb'] ?? [] ) );
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $post['lb_map'] ?? '' ) ) as $line ) {
			if ( false !== strpos( $line, '=' ) ) {
				[ $k, $v ] = array_map( 'trim', explode( '=', $line, 2 ) );
				$map[ $k ] = $v;
			}
		}
		$r = self::set_source( (string) ( $post['lb_source'] ?? '' ), $map );
		Cache::flush(); // Before the redirect, so header-based purges still go out.
		wp_safe_redirect( add_query_arg( [ 'page' => 'launch-builder-business', 'saved' => is_wp_error( $r ) ? 'source-refused' : '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$own = (array) get_option( self::OPT, [] );
		$res = self::resolve();
		$src = (array) get_option( self::OPT_SRC, [] );
		$lines = [];
		foreach ( (array) ( $src['map'] ?? [] ) as $k => $v ) {
			$lines[] = $k . ' = ' . $v;
		}
		echo '<div class="wrap"><h1>Business Info</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo 'source-refused' === $_GET['saved'] ? '<div class="notice notice-error"><p>Saved, but that option can\'t be used as a source.</p></div>' : '<div class="notice notice-success is-dismissible"><p>Saved. Every page now shows the new values.</p></div>'; // phpcs:ignore
		}
		echo '<p>Set these once. Patterns insert them wherever they are needed, so changing a value here updates every page, header and footer. Use <code>{{lb.phone}}</code> in text and <code>#lb-phone_link</code> in link fields, or <code>[lb field="phone"]</code> anywhere else. Fields left blank show a demo value.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_company_save">';
		wp_nonce_field( 'lb_company_save' );
		$groups = [ 'Basics' => [ 'name', 'phone', 'phone_link', 'email' ], 'Locations, hours and licences' => [ 'address', 'address2', 'city', 'hours', 'license', 'maps_url' ], 'Buttons and links' => [ 'quote_url', 'cta_label', 'cta_url', 'cta2_label', 'cta2_url' ], 'Form embed' => [ 'form_html' ], 'Social profiles' => [ 'facebook', 'instagram', 'google', 'youtube' ], 'Writing brief for AI copy' => [ 'industry', 'about', 'keywords', 'tone' ] ];
		$all_fields = self::fields();
		foreach ( $groups as $gtitle => $gkeys ) {
			echo '<h2 class="title" style="margin-top:24px">' . esc_html( $gtitle ) . '</h2><table class="form-table" role="presentation"><tbody>';
			foreach ( $gkeys as $k ) {
				if ( ! isset( $all_fields[ $k ] ) ) {
					continue;
				}
				$f = $all_fields[ $k ];
				$name = 'lb[' . $k . ']';
				echo '<tr><th scope="row"><label for="lb-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
				if ( in_array( $f[1], [ 'html', 'textarea' ], true ) ) {
					echo '<textarea id="lb-' . esc_attr( $k ) . '" name="' . esc_attr( $name ) . '" rows="' . ( 'html' === $f[1] ? 6 : 3 ) . '" class="large-text' . ( 'html' === $f[1] ? ' code' : '' ) . '">' . esc_textarea( (string) ( $own[ $k ] ?? '' ) ) . '</textarea>';
				} else {
					echo '<input id="lb-' . esc_attr( $k ) . '" name="' . esc_attr( $name ) . '" type="text" class="regular-text" style="width:100%;max-width:520px" value="' . esc_attr( (string) ( $own[ $k ] ?? '' ) ) . '" placeholder="' . esc_attr( 'html' === $f[1] ? '' : (string) $f[2] ) . '">';
				}
				$tok = '<code>{{lb.' . esc_html( $k ) . '}}</code>' . ( 'url' === $f[1] ? ' <code>#lb-' . esc_html( $k ) . '</code>' : '' );
				$note = 'source' === $res[ $k ]['from'] ? ' <strong>Value currently comes from the source option below.</strong>' : ( 'derived' === $res[ $k ]['from'] ? ' Currently: <code>' . esc_html( mb_substr( $res[ $k ]['value'], 0, 80 ) ) . '</code>' : '' );
				echo '<p class="description">' . $tok . ( $f[4] ? ' ' . esc_html( $f[4] ) : '' ) . $note . '</p></td></tr>'; // phpcs:ignore -- built from escaped parts above.
			}
			echo '</tbody></table>';
		}

		echo '<h2>Read from an existing settings page (optional)</h2><p>If this site already keeps these facts in another settings option, such as a Meta Box settings page, enter its option name and Business Info reads them from there instead. Tokens are matched by common names (phone_number, business_name, linked_phone_number, quote_page, site_form…); map any others below, one per line, like <code>phone = my_phone_field</code>.</p>';
		echo '<p><input type="text" name="lb_source" class="regular-text" value="' . esc_attr( (string) ( $src['option'] ?? '' ) ) . '" placeholder="option name, e.g. company_information"></p>';
		echo '<p><textarea name="lb_map" rows="4" class="large-text code" placeholder="phone = phone_number">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></p>';
		submit_button( 'Save business info' );
		echo '</form></div>';
	}
}
