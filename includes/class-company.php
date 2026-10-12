<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Global business info: phone, email, address, hours, reviews, buttons, form embed, social links. Set once, used
 * everywhere.
 *
 * Patterns carry tokens instead of copy (the engine is Tokens; this class owns the data):
 *   {{lb.phone}}        in text (escaped for you)
 *   #lb-phone_link      as a link target (href="#lb-cta_url"), for link fields only
 * Both are swapped for the current value every time a block is rendered, so changing the value updates every page,
 * synced pattern, header and footer at once. Anything else can use [lb field="phone"].
 *
 * Values come from (first non-empty wins): an optional existing settings option you point us at (for example a Meta Box
 * settings page), then the values saved here, then a visible demo value so an unconfigured site still looks finished.
 * Claims (ratings, review counts, years, jobs) and social links have no demo value: an empty social link renders
 * nothing, and nothing ever invents a rating.
 *
 * Structured fields feed the display strings: when the structured address is set, {{lb.address}} is built from it; when
 * opening hours (hours_spec) or 24/7 are set, {{lb.hours}} is built from them; the overall rating and review count are
 * computed from the review platforms (reviews[]) unless set explicitly.
 */
final class Company {

	const OPT     = 'launch_builder_company';
	const OPT_SRC = 'launch_builder_company_source';

	/** Admin groups, in screen order (index 5 of each field). */
	const GROUPS = [ 'Basics', 'Address', 'Service area and licences', 'Hours', 'Reviews', 'Business details', 'Buttons and links', 'Promotions and financing', 'Social profiles', 'Logos', 'Form embed', 'Writing brief' ];

	/**
	 * The computed tokens published for the generator and the token catalog. All four are implemented and listed by
	 * computed(); the constant stays as the contract other code reads.
	 */
	const COMPUTED_PLANNED = [
		'review_count_line' => 'Review count line, e.g. "Based on 887+ reviews" (empty without reviews)',
		'review_line'       => 'Short rating line for header bars, e.g. "4.9 rating | 887+ reviews" (empty unless rating and count are set)',
		'service_count'     => 'Number of published services',
		'area_count'        => 'Number of published service areas',
	];

	/** Social profile fields: an empty one renders nothing (its link, icon or social-link block is left out). */
	const SOCIALS = [ 'facebook', 'instagram', 'google', 'youtube', 'yelp', 'tiktok', 'linkedin', 'x', 'nextdoor', 'bbb', 'angi', 'houzz' ];

	/** Review platforms (reviews[].platform keys) and their names. The rating widget bundles a logo for each. */
	const PLATFORMS = [ 'google' => 'Google', 'facebook' => 'Facebook', 'yelp' => 'Yelp', 'bbb' => 'BBB', 'angi' => 'Angi', 'nextdoor' => 'Nextdoor', 'houzz' => 'Houzz', 'thumbtack' => 'Thumbtack' ];

	/** Days in schema.org order (hours_spec[].days). */
	const DAYS = [ 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ];

	/** @var array<string,string>|null per-request cache of the effective values. */
	private static $cache = null;

	/** @var array|null per-request cache of resolve(). */
	private static $resolved = null;

	/** @var array|null per-request cache of the source read: {values, skipped, option, network, meta_box}. */
	private static $source = null;

	/** @var array<string,string> keys refused by the last update() and why. */
	private static $rejected = [];

	public static function boot(): void {
		add_filter( 'render_block', [ __CLASS__, 'filter_block' ], 20, 2 );
		add_shortcode( 'lb', [ __CLASS__, 'shortcode' ] );
		add_action( 'admin_post_lb_company_save', [ __CLASS__, 'save' ] );
		// Edits made outside these tools (set-option, restore-snapshot, a Meta Box settings page used as the source) must
		// show on cached pages too.
		add_action( 'updated_option', [ __CLASS__, 'option_changed' ] );
		add_action( 'added_option', [ __CLASS__, 'option_changed' ] );
		add_action( 'deleted_option', [ __CLASS__, 'option_changed' ] );
		add_action( 'update_site_option', [ __CLASS__, 'option_changed' ] );
	}

	/**
	 * The Business Info field contract.
	 *
	 * @return array<string,array> key => [ label, type, demo value, aliases used when reading another settings option, help, group ]
	 *   type: text | textarea | url | email | html | number | bool | date | image (attachment id or URL) | list (array).
	 *   Claims and socials have no demo value (no fabricated ratings, counts or '#' links).
	 */
	public static function fields(): array {
		return [
			'tagline'    => [ 'Tagline (under page titles)', 'text', 'Trusted local experts in Your City, ST', [ 'tagline', 'slogan', 'brand_tagline' ], 'The line shown under the title in the shared page hero.', 'Basics' ],
			'name'       => [ 'Business name', 'text', 'Your Business', [ 'business_name', 'company_name', 'name' ], '', 'Basics' ],
			'phone'      => [ 'Phone number (as shown)', 'text', '(555) 555-0123', [ 'phone_number', 'phone', 'telephone' ], '', 'Basics' ],
			'phone_link' => [ 'Phone link (tel:)', 'url', '', [ 'linked_phone_number', 'phone_link', 'tel_link' ], 'Leave blank to build it from the phone number.', 'Basics' ],
			'email'      => [ 'Email', 'email', 'hello@yourbusiness.com', [ 'email', 'email_address', 'business_email' ], '', 'Basics' ],
			'address'    => [ 'Address (one line)', 'text', '123 Main Street, Your City, ST 00000', [ 'address', 'street_address', 'full_address' ], 'Street first, then city, state and zip. Built from the structured address when that is set.', 'Address' ],
			'address2'   => [ 'Second office address', 'text', 'Second City, ST', [ 'address2', 'second_address', 'office_2' ], 'Optional, for footers that list two offices.', 'Address' ],
			'license'    => [ 'License numbers', 'textarea', 'License # 00000', [ 'license', 'licenses', 'license_numbers', 'licensing' ], 'One per line, for example HVAC License # CAC123456.', 'Service area and licences' ],
			'city'       => [ 'City / area served', 'text', 'Your City, ST', [ 'location', 'city', 'service_area' ], 'Blank = the city and state of the structured address.', 'Service area and licences' ],
			'hours'      => [ 'Hours (one line)', 'text', 'Mon–Fri 8am–6pm · Sat 9am–2pm', [ 'hours', 'business_hours', 'opening_hours' ], 'Separate lines with a dot or semicolon. Built from the opening hours when those are set.', 'Hours' ],
			'quote_url'  => [ 'Quote / contact page', 'url', '', [ 'quote_page', 'quote_url', 'quote_link', 'contact_page' ], '', 'Buttons and links' ],
			'cta_label'  => [ 'Main button text', 'text', 'Get a Free Quote', [ 'cta_label', 'cta_text', 'button_text' ], 'Every main call-to-action button.', 'Buttons and links' ],
			'cta_url'    => [ 'Main button link', 'url', '', [ 'cta_url', 'cta_link', 'button_url' ], 'Blank = the quote page.', 'Buttons and links' ],
			'cta2_label' => [ 'Second button text', 'text', 'Call Us Today', [ 'cta2_label', 'cta_secondary_label' ], '', 'Buttons and links' ],
			'cta2_url'   => [ 'Second button link', 'url', '', [ 'cta2_url', 'cta_secondary_url' ], 'Blank = the phone link.', 'Buttons and links' ],
			'maps_url'   => [ 'Directions link', 'url', '', [ 'maps_url', 'directions_url', 'google_maps_link' ], 'Blank = a Google Maps search for the address.', 'Address' ],
			'industry'   => [ 'Industry / what you do', 'text', '', [ 'industry', 'business_type', 'niche' ], 'For whoever writes the copy, e.g. residential plumbing.', 'Writing brief' ],
			'about'      => [ 'About the business', 'textarea', '', [ 'about', 'description', 'business_description', 'mission' ], 'Two or three sentences: who you serve, what makes you different.', 'Writing brief' ],
			'keywords'   => [ 'Keywords', 'text', '', [ 'keywords', 'seo_keywords' ], 'Comma-separated phrases to work into copy.', 'Writing brief' ],
			'tone'       => [ 'Tone of voice', 'text', '', [ 'tone', 'tone_of_voice', 'voice' ], 'e.g. friendly, direct, professional.', 'Writing brief' ],
			'form_html'  => [ 'Form embed (HTML)', 'html', '', [ 'site_form', 'form_embed', 'form', 'contact_form' ], 'Paste the embed code (iframe and script). Used by the form patterns.', 'Form embed' ],
			'facebook'   => [ 'Facebook URL', 'url', '', [ 'facebook_url', 'facebook' ], 'Blank = no Facebook link anywhere.', 'Social profiles' ],
			'instagram'  => [ 'Instagram URL', 'url', '', [ 'instagram_url', 'instagram' ], '', 'Social profiles' ],
			'google'     => [ 'Google profile URL', 'url', '', [ 'google_url', 'google_business_profile', 'google' ], '', 'Social profiles' ],
			'youtube'    => [ 'YouTube URL', 'url', '', [ 'youtube_url', 'youtube' ], '', 'Social profiles' ],

			'emergency_phone' => [ 'Emergency / after-hours phone', 'text', '', [ 'emergency_phone', 'after_hours_phone' ], '', 'Basics' ],
			'street'          => [ 'Street address', 'text', '', [ 'street', 'address_line_1', 'street_line' ], 'When the structured address is set, the one-line address is built from it.', 'Address' ],
			'locality'        => [ 'City (locality)', 'text', '', [ 'locality', 'address_city', 'city_name' ], '', 'Address' ],
			'region'          => [ 'State / region', 'text', '', [ 'region', 'state', 'address_state', 'province' ], 'e.g. TX', 'Address' ],
			'postal_code'     => [ 'ZIP / postal code', 'text', '', [ 'postal_code', 'zip', 'zip_code', 'postcode' ], '', 'Address' ],
			'country'         => [ 'Country', 'text', '', [ 'country', 'country_code' ], 'Two-letter code, e.g. US.', 'Address' ],
			'lat'             => [ 'Latitude', 'number', '', [ 'lat', 'latitude' ], '', 'Address' ],
			'lng'             => [ 'Longitude', 'number', '', [ 'lng', 'lon', 'longitude' ], '', 'Address' ],
			'areas_served'    => [ 'Areas served', 'list', '', [ 'areas_served' ], 'Cities or regions, one per line (schema areaServed).', 'Service area and licences' ],
			'hours_spec'      => [ 'Opening hours', 'list', '', [ 'hours_spec', 'opening_hours_spec' ], '[{days:["Mo","Tu"], opens:"08:00", closes:"18:00"}]. When set, the one-line hours are built from it.', 'Hours' ],
			'holiday_hours'   => [ 'Holiday hours', 'list', '', [ 'holiday_hours', 'special_hours' ], '[{date:"2026-12-25", closed:true} or {date, opens, closes}]', 'Hours' ],
			'is_24_7'         => [ 'Open 24/7', 'bool', '', [ 'is_24_7', 'open_24_7', 'always_open' ], 'The one-line hours then read "Open 24/7".', 'Hours' ],
			'price_range'     => [ 'Price range', 'text', '', [ 'price_range' ], 'One to four dollar signs.', 'Business details' ],
			'business_type'   => [ 'Business type (schema)', 'text', '', [ 'schema_business_type', 'local_business_type' ], 'A schema.org LocalBusiness type, e.g. Plumber, HVACBusiness, Electrician.', 'Business details' ],
			'years'           => [ 'Years in business', 'number', '', [ 'years', 'years_in_business' ], 'Leave blank rather than guess.', 'Business details' ],
			'jobs'            => [ 'Jobs completed', 'number', '', [ 'jobs', 'jobs_completed' ], 'Leave blank rather than guess.', 'Business details' ],
			'techs'           => [ 'Technicians', 'number', '', [ 'techs', 'technicians', 'team_size' ], 'Leave blank rather than guess.', 'Business details' ],
			'reviews'         => [ 'Review platforms', 'list', '', [ 'reviews' ], '[{platform:"google", rating:4.9, count:887, url}]', 'Reviews' ],
			'rating'          => [ 'Overall rating', 'number', '', [ 'rating', 'overall_rating', 'google_rating' ], 'e.g. 4.9. Blank = computed from the review platforms (weighted by review count). Never invented.', 'Reviews' ],
			'review_count'    => [ 'Review count', 'number', '', [ 'review_count', 'reviews_count', 'number_of_reviews' ], 'Blank = the sum of the review platforms.', 'Reviews' ],
			'review_url'      => [ 'Leave-a-review link', 'url', '', [ 'review_url', 'review_link' ], '', 'Reviews' ],
			'gbp_place_id'    => [ 'Google Business Profile place ID', 'text', '', [ 'gbp_place_id', 'place_id', 'google_place_id' ], '', 'Reviews' ],
			'promo_text'      => [ 'Promotion text', 'text', '', [ 'promo_text', 'promotion', 'offer_text' ], 'e.g. 50 dollars off any repair this month.', 'Promotions and financing' ],
			'promo_url'       => [ 'Promotion link', 'url', '', [ 'promo_url', 'promotion_url', 'offer_url' ], '', 'Promotions and financing' ],
			'promo_expires'   => [ 'Promotion ends', 'date', '', [ 'promo_expires', 'promotion_expires', 'offer_expires' ], 'The promotion text and link stop showing after this day.', 'Promotions and financing' ],
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
			'logo_inverse'    => [ 'Logo for dark backgrounds', 'image', '', [ 'logo_inverse', 'logo_light', 'logo_white' ], 'Attachment ID or URL.', 'Logos' ],
			'site_icon'       => [ 'Site icon', 'image', '', [ 'site_icon', 'favicon' ], 'Attachment ID or URL (square, 512px).', 'Logos' ],
		];
	}

	/** Tokens that are computed, not stored. */
	public static function computed(): array {
		return [
			'address_br' => 'Address on two lines',
			'hours_br'   => 'Hours, one line per entry',
			'year'       => 'Current year (for copyright lines)',
		] + self::COMPUTED_PLANNED;
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

	/** Is a stored/source value set (non-empty string, or non-empty array)? */
	private static function filled( $v ): bool {
		return is_array( $v ) ? [] !== $v : ( is_scalar( $v ) && '' !== trim( (string) $v ) );
	}

	/**
	 * The Meta Box settings page registered for $option (MB Settings Page extension), or null.
	 *
	 * @return array{id:string,network:bool}|null
	 */
	private static function meta_box_page( string $option ): ?array {
		if ( ! function_exists( 'rwmb_get_value' ) && ! has_filter( 'mb_settings_pages' ) ) {
			return null;
		}
		foreach ( (array) apply_filters( 'mb_settings_pages', [] ) as $page ) {
			$page = (array) $page;
			$name = (string) ( $page['option_name'] ?? ( $page['id'] ?? '' ) );
			if ( '' !== $name && $name === $option ) {
				return [ 'id' => (string) ( $page['id'] ?? $name ), 'network' => ! empty( $page['network'] ) ];
			}
		}
		return null;
	}

	/**
	 * Convert a source value to the field's type. Arrays are never coerced into scalar fields; numeric URL values are
	 * a post or attachment ID and must resolve.
	 *
	 * @return array{0:bool,1:mixed,2:string} [ ok, value, reason when not ok ]
	 */
	private static function convert( string $key, string $type, $raw ): array {
		if ( 'list' === $type ) {
			if ( is_string( $raw ) && 'areas_served' === $key ) {
				return [ true, self::normalize_list( $key, $raw ), '' ];
			}
			if ( ! is_array( $raw ) ) {
				return [ false, null, 'expected a list' ];
			}
			$list = self::normalize_list( $key, $raw );
			return $list ? [ true, $list, '' ] : [ false, null, 'no usable rows in the list' ];
		}
		if ( is_bool( $raw ) ) {
			$raw = $raw ? '1' : '';
		}
		if ( ! is_scalar( $raw ) ) {
			return [ false, null, is_array( $raw ) ? 'a list or group, not a single value' : 'not a single value' ];
		}
		$v = trim( (string) $raw );
		switch ( $type ) {
			case 'url':
				if ( ctype_digit( $v ) && 'phone_link' !== $key ) {
					$p = get_post( (int) $v );
					if ( $p && 'attachment' === $p->post_type ) {
						$u = (string) wp_get_attachment_url( $p->ID );
						return '' !== $u ? [ true, $u, '' ] : [ false, null, 'attachment without a file' ];
					}
					if ( $p && 'publish' === $p->post_status ) {
						return [ true, (string) get_permalink( $p ), '' ];
					}
					return [ false, null, 'post or attachment ID ' . $v . ' does not resolve' ];
				}
				return [ true, $v, '' ];
			case 'image':
				if ( ctype_digit( $v ) ) {
					return wp_attachment_is_image( (int) $v ) ? [ true, $v, '' ] : [ false, null, 'attachment ' . $v . ' is not an image' ];
				}
				return [ true, $v, '' ];
			case 'number':
				return is_numeric( $v ) ? [ true, $v, '' ] : [ false, null, 'not a number' ];
			case 'bool':
				return [ true, in_array( strtolower( $v ), [ '1', 'true', 'yes', 'on' ], true ) ? '1' : '', '' ];
			case 'email':
				return is_email( $v ) ? [ true, $v, '' ] : [ false, null, 'not an email address' ];
			case 'date':
				$d = self::date( $v );
				return '' !== $d ? [ true, $d, '' ] : [ false, null, 'not a date (YYYY-MM-DD)' ];
		}
		return [ true, $v, '' ];
	}

	/**
	 * Values read from the optional external settings option, converted by field type. Meta Box settings pages are read
	 * through rwmb_get_value() (typed values, cloneable groups); network settings pages with get_site_option().
	 *
	 * @return array{values:array,skipped:array,option:string,network:bool,meta_box:bool}
	 */
	public static function source(): array {
		if ( null !== self::$source ) {
			return self::$source;
		}
		$empty = [ 'values' => [], 'skipped' => [], 'option' => '', 'network' => false, 'meta_box' => false ];
		$src = get_option( self::OPT_SRC );
		if ( ! is_array( $src ) || empty( $src['option'] ) || ! Abilities::option_readable( $src['option'] ) ) {
			return self::$source = $empty;
		}
		$option = (string) $src['option'];
		$mb = self::meta_box_page( $option );
		$network = is_multisite() && ( ! empty( $src['network'] ) || ( $mb && $mb['network'] ) );
		$arr = $network ? get_site_option( $option ) : get_option( $option );
		$out = [ 'option' => $option, 'network' => $network, 'meta_box' => null !== $mb ] + $empty;
		if ( ! is_array( $arr ) ) {
			return self::$source = $out;
		}
		$map = (array) ( $src['map'] ?? [] );
		foreach ( self::fields() as $k => $f ) {
			$cands = ( isset( $map[ $k ] ) && '' !== $map[ $k ] ) ? [ (string) $map[ $k ] ] : $f[3];
			foreach ( $cands as $c ) {
				if ( ! array_key_exists( $c, $arr ) ) {
					continue;
				}
				$raw = $arr[ $c ];
				if ( $mb && function_exists( 'rwmb_get_value' ) ) {
					$typed = rwmb_get_value( $c, [ 'object_type' => $network ? 'network_setting' : 'setting' ], $option );
					// Meta Box's typed value, unless it would turn a single stored value into an array (an image field
					// returns its attachment as an array; the stored ID converts better).
					if ( self::filled( $typed ) && ( 'list' === $f[1] || ! is_array( $typed ) || is_array( $raw ) ) ) {
						$raw = $typed;
					}
				}
				if ( ! self::filled( $raw ) && ! is_bool( $raw ) ) {
					continue; // Empty: try the next name.
				}
				[ $ok, $val, $why ] = self::convert( $k, $f[1], $raw );
				if ( $ok && self::filled( $val ) ) {
					$out['values'][ $k ] = $val;
					break;
				}
				if ( ! $ok ) {
					$out['skipped'][] = [ 'key' => $k, 'source_key' => $c, 'reason' => $why ];
				}
			}
		}
		return self::$source = $out;
	}

	/**
	 * Where each effective value comes from, for the admin page and get-company.
	 * from: source | saved | default | derived (from another field) | computed (from structured fields) | expired.
	 * List fields also carry 'data' (the normalised rows); image fields 'id' when the value is an attachment.
	 *
	 * @return array<string,array{value:string,from:string}>
	 */
	public static function resolve(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$own = (array) get_option( self::OPT, [] );
		$ext = self::source()['values'];
		$out = [];
		foreach ( self::fields() as $k => $f ) {
			if ( array_key_exists( $k, $ext ) ) {
				$v = $ext[ $k ];
				$from = 'source';
			} elseif ( isset( $own[ $k ] ) && self::filled( $own[ $k ] ) ) {
				$v = $own[ $k ];
				$from = 'saved';
			} else {
				$v = 'list' === $f[1] ? [] : (string) $f[2];
				$from = 'default';
			}
			if ( 'list' === $f[1] ) {
				$data = self::normalize_list( $k, $v );
				$out[ $k ] = [ 'value' => self::list_text( $k, $data ), 'from' => $data ? $from : 'default', 'data' => $data ];
				continue;
			}
			$out[ $k ] = [ 'value' => is_scalar( $v ) ? trim( (string) $v ) : '', 'from' => $from ];
			if ( 'url' === $f[1] && 'phone_link' !== $k && '' !== $out[ $k ]['value'] && ctype_digit( $out[ $k ]['value'] ) ) { // A page ID: its current address.
				$conv = self::convert( $k, 'url', $out[ $k ]['value'] );
				$out[ $k ]['value'] = $conv[0] ? (string) $conv[1] : '';
			}
			if ( 'image' === $f[1] && ctype_digit( $out[ $k ]['value'] ) ) {
				$out[ $k ]['id'] = (int) $out[ $k ]['value'];
				$out[ $k ]['value'] = (string) wp_get_attachment_url( (int) $out[ $k ]['value'] );
			}
		}

		// A promotion past its end date shows nothing.
		$ends = self::date( $out['promo_expires']['value'] );
		if ( '' !== $ends && $ends < wp_date( 'Y-m-d' ) ) {
			foreach ( [ 'promo_text', 'promo_url' ] as $k ) {
				$out[ $k ] = [ 'value' => '', 'from' => 'expired' ];
			}
		}

		// Structured address -> the one-line address (and the city when that is not set).
		$parts = [];
		foreach ( [ 'street', 'locality', 'region', 'postal_code' ] as $k ) {
			$parts[ $k ] = 'default' === $out[ $k ]['from'] ? '' : $out[ $k ]['value'];
		}
		if ( implode( '', $parts ) !== '' ) {
			$line = implode( ', ', array_filter( [ $parts['street'], $parts['locality'], trim( $parts['region'] . ' ' . $parts['postal_code'] ) ], 'strlen' ) );
			$out['address'] = [ 'value' => $line, 'from' => 'computed' ];
			if ( 'default' === $out['city']['from'] && '' !== $parts['locality'] ) {
				$out['city'] = [ 'value' => implode( ', ', array_filter( [ $parts['locality'], $parts['region'] ], 'strlen' ) ), 'from' => 'derived' ];
			}
		}

		// Opening hours (or 24/7) -> the one-line hours.
		if ( '1' === $out['is_24_7']['value'] ) {
			$out['hours'] = [ 'value' => __( 'Open 24/7', 'launch-builder' ), 'from' => 'computed' ];
		} elseif ( $out['hours_spec']['data'] ) {
			$out['hours'] = [ 'value' => $out['hours_spec']['value'], 'from' => 'computed' ];
		}

		// Review platforms -> overall rating and count (unless set explicitly).
		$stats = self::review_stats( $out['reviews']['data'] );
		if ( 'default' === $out['rating']['from'] && null !== $stats['rating'] ) {
			$out['rating'] = [ 'value' => number_format( $stats['rating'], 1, '.', '' ), 'from' => 'computed' ];
		}
		if ( 'default' === $out['review_count']['from'] && null !== $stats['count'] ) {
			$out['review_count'] = [ 'value' => (string) $stats['count'], 'from' => 'computed' ];
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
		return self::$resolved = $out;
	}

	/** Effective plain values (unescaped strings; list fields as their display text). */
	public static function values(): array {
		if ( null === self::$cache ) {
			self::$cache = array_map( static function ( $r ) { return $r['value']; }, self::resolve() );
		}
		return self::$cache;
	}

	/** The normalised rows of a list field (reviews, hours_spec, holiday_hours, areas_served). */
	public static function list_value( string $key ): array {
		$r = self::resolve();
		return isset( $r[ $key ]['data'] ) ? (array) $r[ $key ]['data'] : [];
	}

	/**
	 * Plain value of a computed token, or null when $key is not one. address_br/hours_br give the one-line value here;
	 * lines() gives their parts.
	 */
	public static function computed_value( string $key ): ?string {
		$v = self::values();
		switch ( $key ) {
			case 'year':
				return wp_date( 'Y' );
			case 'address_br':
				return $v['address'];
			case 'hours_br':
				return $v['hours'];
			case 'service_count':
				return (string) (int) ( wp_count_posts( Entries::SERVICE )->publish ?? 0 );
			case 'area_count':
				return (string) (int) ( wp_count_posts( Entries::AREA )->publish ?? 0 );
			case 'review_count_line':
				$n = (int) $v['review_count'];
				/* translators: %s: number of reviews, e.g. 887 */
				return $n > 0 ? sprintf( _n( 'Based on %s+ review', 'Based on %s+ reviews', $n, 'launch-builder' ), number_format_i18n( $n ) ) : '';
			case 'review_line':
				$n = (int) $v['review_count'];
				if ( '' === $v['rating'] || $n <= 0 ) {
					return ''; // Only real numbers: both rating and count must be set (saved, source or computed).
				}
				/* translators: 1: rating, e.g. 4.9, 2: number of reviews, e.g. 2,500 */
				return sprintf( __( '%1$s rating | %2$s+ reviews', 'launch-builder' ), number_format_i18n( (float) $v['rating'], 1 ), number_format_i18n( $n ) );
		}
		return null;
	}

	/** The lines of address_br / hours_br (unescaped), or null for other keys. */
	public static function lines( string $key ): ?array {
		$v = self::values();
		if ( 'address_br' === $key ) {
			return array_map( 'trim', explode( ',', $v['address'], 2 ) );
		}
		if ( 'hours_br' === $key ) {
			return array_values( array_filter( array_map( 'trim', (array) preg_split( '/[·|;\r\n]+/u', $v['hours'] ) ) ) );
		}
		return null;
	}

	/**
	 * Review numbers for the rating widget: overall (platform '') or one platform from reviews[].
	 *
	 * @return array{rating:?float,count:?int,url:string,label:string,platforms:int,from:string}
	 */
	public static function review_data( string $platform = '' ): array {
		$r = self::resolve();
		if ( '' === $platform ) {
			$rated = array_filter( $r['reviews']['data'], static function ( $row ) { return (int) ( $row['count'] ?? 0 ) > 0; } );
			return [
				'rating'    => '' !== $r['rating']['value'] ? (float) $r['rating']['value'] : null,
				'count'     => '' !== $r['review_count']['value'] ? (int) $r['review_count']['value'] : null,
				'url'       => $r['review_url']['value'],
				'label'     => '',
				'platforms' => count( $rated ),
				'from'      => $r['rating']['from'],
			];
		}
		foreach ( $r['reviews']['data'] as $row ) {
			if ( $platform === $row['platform'] ) {
				return [ 'rating' => $row['rating'], 'count' => $row['count'], 'url' => (string) $row['url'], 'label' => self::platform_label( $platform ), 'platforms' => 1, 'from' => $r['reviews']['from'] ];
			}
		}
		return [ 'rating' => null, 'count' => null, 'url' => '', 'label' => self::platform_label( $platform ), 'platforms' => 0, 'from' => 'default' ];
	}

	public static function platform_label( string $key ): string {
		return self::PLATFORMS[ $key ] ?? ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Count-weighted average rating and summed count of review rows (rows without a count weigh in only when no row has
	 * one).
	 *
	 * @return array{rating:?float,count:?int}
	 */
	private static function review_stats( array $rows ): array {
		$sum = 0.0;
		$weight = 0;
		$plain = [];
		$count = 0;
		$counted = false;
		foreach ( $rows as $row ) {
			$c = isset( $row['count'] ) && null !== $row['count'] ? (int) $row['count'] : null;
			if ( null !== $c ) {
				$count += $c;
				$counted = true;
			}
			if ( ! isset( $row['rating'] ) || null === $row['rating'] ) {
				continue;
			}
			if ( null !== $c && $c > 0 ) {
				$sum += (float) $row['rating'] * $c;
				$weight += $c;
			} else {
				$plain[] = (float) $row['rating'];
			}
		}
		$rating = $weight > 0 ? $sum / $weight : ( $plain ? array_sum( $plain ) / count( $plain ) : null );
		return [ 'rating' => null === $rating ? null : round( $rating, 1 ), 'count' => $counted && $count > 0 ? $count : null ];
	}

	/* ------------------------------------------------------------------ lists */

	/** First non-empty value of $row under any of $keys. */
	private static function pick( array $row, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && '' !== $row[ $k ] ) {
				return $row[ $k ];
			}
		}
		return null;
	}

	/** 'YYYY-MM-DD' for a valid date, else ''. */
	private static function date( $v ): string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
	}

	/** 'HH:MM' (24h, 24:00 allowed) or ''. */
	private static function time( $v ): string {
		$v = is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '';
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/', $v, $m ) ) { // 8am, 8:30 pm
			$h = (int) $m[1] % 12 + ( 'pm' === $m[3] ? 12 : 0 );
			$v = $h . ':' . ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : '00' ); // An unmatched middle group is '', not unset.
		}
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $v, $m ) || (int) $m[1] > 24 || (int) $m[2] > 59 || ( 24 === (int) $m[1] && 0 !== (int) $m[2] ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', $m[1], $m[2] );
	}

	/** Days in schema.org form (Mo..Su), week order, from a list or a string like "Mo-Fr", "Monday, Wednesday". */
	private static function days( $v ): array {
		$names = is_array( $v ) ? $v : preg_split( '/[,\s]+/', (string) preg_replace( '/\s*[-–]\s*/u', '-', (string) $v ) );
		$idx = static function ( $d ) {
			$d = strtolower( substr( trim( (string) $d ), 0, 2 ) );
			$i = array_search( $d, [ 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ], true );
			return false === $i ? null : (int) $i;
		};
		$on = [];
		foreach ( (array) $names as $n ) {
			if ( ! is_scalar( $n ) ) {
				continue;
			}
			$range = preg_split( '/\s*[-–]\s*/u', trim( (string) $n ) );
			if ( 2 === count( $range ) ) {
				[ $a, $b ] = [ $idx( $range[0] ), $idx( $range[1] ) ];
				if ( null !== $a && null !== $b ) {
					for ( $i = $a; ; $i = ( $i + 1 ) % 7 ) {
						$on[ $i ] = true;
						if ( $i === $b ) {
							break;
						}
					}
				}
				continue;
			}
			$i = $idx( $n );
			if ( null !== $i ) {
				$on[ $i ] = true;
			}
		}
		ksort( $on );
		return array_map( static function ( $i ) { return self::DAYS[ $i ]; }, array_keys( $on ) );
	}

	/**
	 * Validate and normalise a list field. Unknown sub-keys are dropped, rows without their key fact are skipped.
	 *
	 * @param mixed $rows array, JSON string, or (areas_served) a newline/comma separated string
	 */
	public static function normalize_list( string $key, $rows ): array {
		if ( is_string( $rows ) ) {
			$json = json_decode( $rows, true );
			$rows = is_array( $json ) ? $json : ( 'areas_served' === $key ? preg_split( '/\r\n|\r|\n|;/', $rows ) : [] ); // One per line ("Austin, TX" stays whole).
		}
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		switch ( $key ) {
			case 'areas_served':
				foreach ( $rows as $r ) {
					$r = is_array( $r ) ? self::pick( $r, [ 'name', 'title', 'city' ] ) : $r;
					$s = is_scalar( $r ) ? mb_substr( sanitize_text_field( (string) $r ), 0, 120 ) : '';
					if ( '' !== $s && ! in_array( $s, $out, true ) ) {
						$out[] = $s;
					}
				}
				return array_slice( $out, 0, 100 );
			case 'reviews':
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$p = sanitize_key( (string) self::pick( $r, [ 'platform', 'name', 'site', 'source' ] ) );
					foreach ( array_keys( self::PLATFORMS ) as $known ) {
						if ( 0 === strpos( $p, $known ) ) {
							$p = $known; // googlebusinessprofile -> google, angieslist -> angi
						}
					}
					$p = 'betterbusinessbureau' === $p ? 'bbb' : $p;
					if ( '' === $p ) {
						continue;
					}
					$rating = self::pick( $r, [ 'rating', 'stars', 'score', 'average' ] );
					$count = self::pick( $r, [ 'count', 'reviews', 'review_count', 'total' ] );
					$url = self::pick( $r, [ 'url', 'link', 'href' ] );
					$out[] = [
						'platform' => mb_substr( $p, 0, 30 ),
						'rating'   => is_numeric( $rating ) ? round( max( 0, min( 5, (float) $rating ) ), 2 ) : null,
						'count'    => is_numeric( $count ) ? max( 0, (int) $count ) : null,
						'url'      => is_string( $url ) ? mb_substr( esc_url_raw( trim( $url ) ), 0, 500 ) : '',
					];
				}
				return array_slice( $out, 0, 20 );
			case 'hours_spec':
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$days = self::days( self::pick( $r, [ 'days', 'day', 'dayOfWeek' ] ) ?? [] );
					$opens = self::time( self::pick( $r, [ 'opens', 'open', 'from' ] ) );
					$closes = self::time( self::pick( $r, [ 'closes', 'close', 'to' ] ) );
					if ( $days && '' !== $opens && '' !== $closes ) {
						$out[] = [ 'days' => $days, 'opens' => $opens, 'closes' => $closes ];
					}
				}
				return array_slice( $out, 0, 14 );
			case 'holiday_hours':
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$date = self::date( self::pick( $r, [ 'date', 'day' ] ) );
					if ( '' === $date ) {
						continue;
					}
					$opens = self::time( self::pick( $r, [ 'opens', 'open', 'from' ] ) );
					$closes = self::time( self::pick( $r, [ 'closes', 'close', 'to' ] ) );
					$closed = ! empty( $r['closed'] ) && ! in_array( $r['closed'], [ '0', 'false', 'no' ], true );
					$out[] = ( $closed || '' === $opens || '' === $closes ) ? [ 'date' => $date, 'closed' => true ] : [ 'date' => $date, 'opens' => $opens, 'closes' => $closes ];
				}
				return array_slice( $out, 0, 30 );
		}
		return [];
	}

	/** '8am', '8:30am', '12pm' in the site language. */
	private static function time_label( string $hhmm ): string {
		[ $h, $m ] = array_map( 'intval', explode( ':', $hhmm ) );
		$ts = gmmktime( $h % 24, $m, 0, 1, 1, 2000 );
		return wp_date( $m ? 'g:ia' : 'ga', $ts, new \DateTimeZone( 'UTC' ) );
	}

	/** Display text of a list field ({{lb.hours_spec}}, {{lb.areas_served}}...). */
	private static function list_text( string $key, array $data ): string {
		global $wp_locale;
		$day = static function ( string $d ) use ( $wp_locale ): string {
			$n = ( array_search( $d, self::DAYS, true ) + 1 ) % 7; // WP_Locale: 0 = Sunday
			return $wp_locale ? (string) $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $n ) ) : $d;
		};
		switch ( $key ) {
			case 'areas_served':
				return implode( ', ', $data );
			case 'reviews':
				return implode( ' · ', array_map( static function ( $r ) { return self::platform_label( $r['platform'] ) . ( null !== $r['rating'] ? ' ' . number_format_i18n( (float) $r['rating'], 1 ) : '' ) . ( null !== $r['count'] ? ' (' . number_format_i18n( (int) $r['count'] ) . ')' : '' ); }, $data ) );
			case 'hours_spec':
				$parts = [];
				foreach ( $data as $row ) {
					$idx = array_map( static function ( $d ) { return (int) array_search( $d, self::DAYS, true ); }, $row['days'] );
					$runs = [];
					foreach ( $idx as $i ) {
						$last = count( $runs ) - 1;
						if ( $last >= 0 && end( $runs[ $last ] ) === $i - 1 ) {
							$runs[ $last ][] = $i;
						} else {
							$runs[] = [ $i ];
						}
					}
					$labels = [];
					foreach ( $runs as $run ) {
						if ( count( $run ) >= 3 ) {
							$labels[] = $day( self::DAYS[ $run[0] ] ) . '–' . $day( self::DAYS[ end( $run ) ] );
						} else {
							foreach ( $run as $i ) {
								$labels[] = $day( self::DAYS[ $i ] );
							}
						}
					}
					$parts[] = implode( ', ', $labels ) . ' ' . self::time_label( $row['opens'] ) . '–' . self::time_label( $row['closes'] );
				}
				return implode( ' · ', $parts );
			case 'holiday_hours':
				return implode( ' · ', array_map( static function ( $r ) {
					$d = wp_date( 'M j', (int) strtotime( $r['date'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
					return $d . ': ' . ( ! empty( $r['closed'] ) ? __( 'Closed', 'launch-builder' ) : self::time_label( $r['opens'] ) . '–' . self::time_label( $r['closes'] ) );
				}, $data ) );
		}
		return '';
	}

	/* ------------------------------------------------------------------ rendering (engine: Tokens) */

	/** render_block filter: every token in the block's HTML (see Tokens::render_block). */
	public static function filter_block( $html, $block = null ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return Tokens::render_block( $html, is_array( $block ) ? $block : [] );
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts( [ 'field' => '' ], $atts, 'lb' );
		return Tokens::html( 'lb', sanitize_key( (string) $a['field'] ) );
	}

	/* ------------------------------------------------------------------ writing */

	public static function clean( string $key, string $type, string $v ): string {
		$v = trim( $v );
		switch ( $type ) {
			case 'url':
				if ( '' !== $v && ctype_digit( $v ) ) {
					return mb_substr( $v, 0, 30 ); // A page or attachment ID (resolved to its current address when read); digits in phone_link become tel:.
				}
				return mb_substr( '' === $v ? '' : ( '#' === $v[0] ? '#' . sanitize_key( substr( $v, 1 ) ) : esc_url_raw( $v ) ), 0, 500 );
			case 'email':
				return mb_substr( sanitize_email( $v ), 0, 200 );
			case 'textarea':
				return mb_substr( sanitize_textarea_field( $v ), 0, 2000 );
			case 'html':
				return mb_substr( current_user_can( 'unfiltered_html' ) ? $v : wp_kses_post( $v ), 0, 20000 );
			case 'number':
				if ( ! is_numeric( $v ) ) {
					return '';
				}
				if ( 'rating' === $key ) {
					return (string) round( max( 0, min( 5, (float) $v ) ), 2 );
				}
				if ( in_array( $key, [ 'review_count', 'years', 'jobs', 'techs' ], true ) ) {
					return (string) max( 0, (int) $v );
				}
				return (string) ( 0 + $v );
			case 'bool':
				return in_array( strtolower( $v ), [ '1', 'true', 'yes', 'on' ], true ) ? '1' : '';
			case 'date':
				return self::date( $v );
			case 'image':
				return ctype_digit( $v ) ? (string) absint( $v ) : mb_substr( esc_url_raw( $v ), 0, 500 );
			default:
				return mb_substr( sanitize_text_field( $v ), 0, 300 );
		}
	}

	/**
	 * Save values (only known keys). List fields take arrays (or JSON); an empty array clears them. Text fields may hold
	 * tokens of the lb, site, area and service namespaces only (they have no page or entry of their own); a value with
	 * other tokens is refused (see rejected()).
	 *
	 * @return string[] keys that changed
	 */
	public static function update( array $values ): array {
		$fields = self::fields();
		$own = (array) get_option( self::OPT, [] );
		$changed = [];
		self::$rejected = [];
		foreach ( $values as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( ! isset( $fields[ $k ] ) ) {
				continue;
			}
			$type = $fields[ $k ][1];
			if ( 'list' === $type ) {
				if ( ! is_array( $v ) && ! is_string( $v ) ) {
					continue;
				}
				if ( is_string( $v ) && 'areas_served' !== $k && '' !== trim( $v ) && ! is_array( json_decode( $v, true ) ) ) {
					self::$rejected[ $k ] = 'A list: give an array of rows (or JSON).';
					continue; // Never clear a list because of a stray string.
				}
				$clean = self::normalize_list( $k, $v );
				if ( wp_json_encode( $own[ $k ] ?? [] ) !== wp_json_encode( $clean ) ) {
					if ( $clean ) {
						$own[ $k ] = $clean;
					} else {
						unset( $own[ $k ] );
					}
					$changed[] = $k;
				}
				continue;
			}
			if ( is_bool( $v ) ) {
				$v = $v ? '1' : '';
			}
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$clean = self::clean( $k, $type, (string) $v );
			if ( in_array( $type, [ 'text', 'textarea' ], true ) && preg_match( '/\{\{\s*(page|entry|meta)\./', $clean ) ) {
				self::$rejected[ $k ] = 'Business Info values may use lb, site, area and service tokens only.';
				continue;
			}
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

	/** @return array<string,string> keys the last update() refused, with the reason. */
	public static function rejected(): array {
		return self::$rejected;
	}

	/**
	 * Point at another settings option (for example a Meta Box settings page). Pass '' to clear. $network reads a
	 * network (site) option on multisite; a Meta Box network settings page is detected by itself.
	 *
	 * @return array|\WP_Error the stored source
	 */
	public static function set_source( string $option, array $map = [], bool $network = false ) {
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
		if ( $network ) {
			$src['network'] = true;
		}
		update_option( self::OPT_SRC, $src );
		self::flush();
		return $src;
	}

	/** Changed values must show on cached pages straight away: one coalesced purge for this request (see Cache). */
	private static function flush(): void {
		self::$cache = null;
		self::$resolved = null;
		self::$source = null;
		Tokens::reset();
		Cache::purge_later();
	}

	/** updated_option / added_option / update_site_option: Business Info or its source option changed. */
	public static function option_changed( $option ): void {
		if ( self::OPT === $option || self::OPT_SRC === $option ) {
			self::flush();
			return;
		}
		$src = get_option( self::OPT_SRC );
		if ( is_array( $src ) && ! empty( $src['option'] ) && $src['option'] === $option ) {
			self::flush();
		}
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'get-company', 'Read the global business info', 'Business facts used by every pattern through tokens ({{lb.phone}} in text, #lb-cta_url in link fields): name, phone, email, structured address and opening hours, review platforms, button text and links, promotions, form embed, social links. Shows the saved value, the value in effect and where it comes from (source, saved, default, derived, computed, expired); source_skipped lists source-option values that could not be used. Patterns show a visible demo value for contact fields until set; ratings, counts and social links never have one.',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_tool' ], 'edit_pages', [ 'readonly' => true ] );

		Abilities::add( 'set-company', 'Set the global business info', 'Change business facts once and every page, header and footer updates. values: any get-company key, e.g. {name, phone, email, street, locality, region, postal_code, country, hours_spec:[{days:["Mo","Tu","We","Th","Fr"], opens:"08:00", closes:"18:00"}], is_24_7, reviews:[{platform:"google", rating:4.9, count:887, url}], review_url, cta_label, quote_url, promo_text, promo_expires, facebook...}. List fields take arrays ([] clears). Blank link fields fall back (cta_url = quote page, cta2_url = phone link); address/hours are built from the structured fields and rating/review_count from reviews when those are set. Never invent ratings or counts. Text values may use {{lb.*}}, {{site.*}}, {{area.*}}, {{service.*}} tokens only. source (optional): read from another settings option instead, for example a Meta Box settings page: {option:"company_information", map:{phone:"phone_number"}, network:false}; unmapped keys are matched by common names and values are converted by field type. {option:""} clears it. Use find-options to locate the option.',
			[ 'properties' => [ 'values' => [ 'type' => 'object' ], 'source' => [ 'type' => 'object' ] ] ], [ __CLASS__, 'set_tool' ], 'manage_options' );
	}

	/** Module hook: where each Business Info value comes from, in site-state. */
	public static function site_state( array &$state ): void {
		$state['company'] = array_map( static function ( $r ) { return $r['from']; }, self::resolve() );
	}

	/** Can $key be written as a #lb-key link target? (link fields: *_url, *_link, maps_url and the social profiles). */
	public static function is_link_key( string $key ): bool {
		return (bool) preg_match( '/(_url|_link)$/', $key ) || in_array( $key, self::SOCIALS, true );
	}

	public static function describe(): array {
		$own = (array) get_option( self::OPT, [] );
		$res = self::resolve();
		$rows = [];
		foreach ( self::fields() as $k => $f ) {
			$eff = 'list' === $f[1] ? $res[ $k ]['data'] : $res[ $k ]['value'];
			$saved = $own[ $k ] ?? ( 'list' === $f[1] ? [] : '' );
			$rows[] = [
				'key'        => $k,
				'label'      => $f[0],
				'type'       => $f[1],
				'token'      => '{{lb.' . $k . '}}',
				'link_token' => 'url' === $f[1] && self::is_link_key( $k ) ? '#lb-' . $k : null,
				'saved'      => 'html' === $f[1] ? mb_substr( (string) $saved, 0, 80 ) : $saved,
				'effective'  => 'html' === $f[1] ? mb_substr( (string) $eff, 0, 80 ) : $eff,
				'from'       => $res[ $k ]['from'],
			];
		}
		$computed = [];
		foreach ( array_keys( self::computed() ) as $k ) {
			$computed[] = '{{lb.' . $k . '}}';
		}
		$src = get_option( self::OPT_SRC );
		$read = self::source();
		return [
			'fields'         => $rows,
			'computed'       => $computed,
			'source'         => is_array( $src ) ? $src + [ 'meta_box' => $read['meta_box'] ] : null,
			'source_skipped' => $read['skipped'],
			'usage'          => 'Text: {{lb.phone}} (fallback: {{lb.years|10}}). Link fields: #lb-phone_link, #lb-cta_url, #lb-facebook. Anywhere else: [lb field="phone"]. get-tokens lists every token (also page, entry, area, service, site).',
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
		if ( $has_src ) { // Validate before anything is written, so a refused source never leaves values half-applied.
			$s = (array) $input['source'];
			$opt = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $s['option'] ?? '' ) );
			if ( '' !== $opt && ! Abilities::option_readable( $opt ) ) {
				return new \WP_Error( 'lb_refused', 'That option can\'t be used as a source.', [ 'status' => 403 ] );
			}
		}
		$snap = Abilities::snapshot( 'set-company', [], [ self::OPT, self::OPT_SRC ] );
		$changed = $values ? self::update( $values ) : [];
		$rejected = self::rejected();
		$src_changed = false;
		if ( $has_src ) {
			$s = (array) $input['source'];
			$r = self::set_source( (string) ( $s['option'] ?? '' ), (array) ( $s['map'] ?? [] ), ! empty( $s['network'] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$src_changed = true;
		}
		return [ 'changed' => $changed, 'rejected' => $rejected, 'source_changed' => $src_changed, 'snapshot' => $snap ] + self::describe();
	}

	/* ------------------------------------------------------------------ admin page */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_company_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified above; every value is sanitised in update()/set_source().
		$values = (array) ( $post['lb'] ?? [] );
		foreach ( (array) ( $post['lb_lists'] ?? [] ) as $list ) { // Lists shown on the screen whose rows were all removed.
			$list = sanitize_key( (string) $list );
			if ( ! isset( $values[ $list ] ) ) {
				$values[ $list ] = [];
			}
		}
		self::update( $values );
		$rejected = self::rejected();
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $post['lb_map'] ?? '' ) ) as $line ) {
			if ( false !== strpos( $line, '=' ) ) {
				[ $k, $v ] = array_map( 'trim', explode( '=', $line, 2 ) );
				$map[ $k ] = $v;
			}
		}
		$r = self::set_source( (string) ( $post['lb_source'] ?? '' ), $map, ! empty( $post['lb_source_network'] ) );
		Cache::flush(); // Before the redirect, so header-based purges still go out.
		$state = is_wp_error( $r ) ? 'source-refused' : ( $rejected ? 'rejected' : '1' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'launch-builder-business', 'saved' => $state, 'lb_rejected' => implode( ',', array_keys( $rejected ) ) ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** One labelled input row of the Business Info screen. */
	private static function field_row( string $k, array $f, array $own, array $res ): string {
		$name = 'lb[' . $k . ']';
		$id = 'lb-' . $k;
		$val = (string) ( $own[ $k ] ?? '' );
		$type = $f[1];
		$h = '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
		switch ( $type ) {
			case 'html':
			case 'textarea':
				$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . ( 'html' === $type ? 6 : 3 ) . '" class="large-text' . ( 'html' === $type ? ' code' : '' ) . '" placeholder="' . esc_attr( 'html' === $type ? '' : (string) $f[2] ) . '">' . esc_textarea( $val ) . '</textarea>';
				break;
			case 'bool':
				$h .= '<input type="hidden" name="' . esc_attr( $name ) . '" value=""><label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="checkbox" value="1"' . checked( '1', $val, false ) . '> ' . esc_html__( 'Yes', 'launch-builder' ) . '</label>';
				break;
			case 'number':
				$step = in_array( $k, [ 'lat', 'lng' ], true ) ? 'any' : ( 'rating' === $k ? '0.1' : '1' );
				$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="number" step="' . esc_attr( $step ) . '"' . ( 'rating' === $k ? ' min="0" max="5"' : '' ) . ' class="regular-text" style="max-width:200px" value="' . esc_attr( $val ) . '">';
				break;
			case 'date':
				$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="date" value="' . esc_attr( $val ) . '">';
				break;
			case 'list': // areas_served: one per line.
				$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( implode( "\n", (array) ( $own[ $k ] ?? [] ) ) ) . '</textarea>';
				break;
			default:
				$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . ( 'email' === $type ? 'email' : 'text' ) . '" class="regular-text" style="width:100%;max-width:520px" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( (string) $f[2] ) . '">';
				if ( 'image' === $type && '' !== $res[ $k ]['value'] ) {
					$h .= '<br><img src="' . esc_url( $res[ $k ]['value'] ) . '" alt="" style="max-height:48px;margin-top:8px;background:#1d2327;padding:6px;border-radius:4px">';
				}
		}
		$tok = '<code>{{lb.' . esc_html( $k ) . '}}</code>' . ( self::is_link_key( $k ) && 'url' === $type ? ' <code>#lb-' . esc_html( $k ) . '</code>' : '' );
		$from = $res[ $k ]['from'];
		$now = '' !== $res[ $k ]['value'] ? '<code>' . esc_html( mb_substr( $res[ $k ]['value'], 0, 90 ) ) . '</code>' : '';
		$notes = [
			'source'   => '<strong>' . esc_html__( 'Value currently comes from the source option below.', 'launch-builder' ) . '</strong>',
			'derived'  => $now ? esc_html__( 'Currently:', 'launch-builder' ) . ' ' . $now : '',
			'computed' => $now ? esc_html__( 'Built from the fields above:', 'launch-builder' ) . ' ' . $now : '',
			'expired'  => '<strong>' . esc_html__( 'The promotion has ended, so this shows nothing.', 'launch-builder' ) . '</strong>',
		];
		$help = $f[4] ? ' ' . esc_html( $f[4] ) : '';
		$h .= '<p class="description">' . $tok . $help . ( isset( $notes[ $from ] ) && '' !== $notes[ $from ] ? ' ' . $notes[ $from ] : '' ) . '</p></td></tr>';
		return $h;
	}

	/** A repeater table (reviews, hours_spec, holiday_hours): rows, an empty template row, an add button. */
	private static function repeater( string $k, array $rows ): string {
		$cols = [
			'reviews'       => [ __( 'Platform', 'launch-builder' ), __( 'Rating (0–5)', 'launch-builder' ), __( 'Number of reviews', 'launch-builder' ), __( 'Profile or reviews link', 'launch-builder' ) ],
			'hours_spec'    => [ __( 'Days', 'launch-builder' ), __( 'Opens', 'launch-builder' ), __( 'Closes', 'launch-builder' ) ],
			'holiday_hours' => [ __( 'Date', 'launch-builder' ), __( 'Opens', 'launch-builder' ), __( 'Closes', 'launch-builder' ), __( 'Closed all day', 'launch-builder' ) ],
		][ $k ];
		$add = [ 'reviews' => __( 'Add a platform', 'launch-builder' ), 'hours_spec' => __( 'Add opening hours', 'launch-builder' ), 'holiday_hours' => __( 'Add a holiday', 'launch-builder' ) ][ $k ];
		$h = '<input type="hidden" name="lb_lists[]" value="' . esc_attr( $k ) . '"><table class="widefat striped lb-rep" data-lb-rep="' . esc_attr( $k ) . '"><thead><tr>';
		foreach ( $cols as $c ) {
			$h .= '<th>' . esc_html( $c ) . '</th>';
		}
		$h .= '<th class="lb-rep-x"><span class="screen-reader-text">' . esc_html__( 'Remove', 'launch-builder' ) . '</span></th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $row ) {
			$h .= self::repeater_row( $k, (string) $i++, (array) $row );
		}
		$h .= '</tbody></table><template id="lb-tpl-' . esc_attr( $k ) . '">' . self::repeater_row( $k, '__i__', [] ) . '</template>';
		$h .= '<p><button type="button" class="button" data-lb-add="' . esc_attr( $k ) . '">' . esc_html( $add ) . '</button></p>';
		return $h;
	}

	private static function repeater_row( string $k, string $i, array $row ): string {
		$n = static function ( string $sub ) use ( $k, $i ): string {
			return esc_attr( 'lb[' . $k . '][' . $i . '][' . $sub . ']' );
		};
		$cell = static function ( string $html ): string {
			return '<td>' . $html . '</td>';
		};
		$h = '<tr>';
		if ( 'reviews' === $k ) {
			$cur = (string) ( $row['platform'] ?? '' );
			$opts = '<option value="">' . esc_html__( 'Choose…', 'launch-builder' ) . '</option>';
			$known = self::PLATFORMS + ( '' !== $cur && ! isset( self::PLATFORMS[ $cur ] ) ? [ $cur => self::platform_label( $cur ) ] : [] );
			foreach ( $known as $key => $label ) {
				$opts .= '<option value="' . esc_attr( $key ) . '"' . selected( $cur, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$h .= $cell( '<select name="' . $n( 'platform' ) . '" aria-label="' . esc_attr__( 'Platform', 'launch-builder' ) . '">' . $opts . '</select>' );
			$h .= $cell( '<input type="number" step="0.1" min="0" max="5" style="width:90px" name="' . $n( 'rating' ) . '" value="' . esc_attr( null !== ( $row['rating'] ?? null ) ? (string) $row['rating'] : '' ) . '" aria-label="' . esc_attr__( 'Rating', 'launch-builder' ) . '">' );
			$h .= $cell( '<input type="number" step="1" min="0" style="width:110px" name="' . $n( 'count' ) . '" value="' . esc_attr( null !== ( $row['count'] ?? null ) ? (string) $row['count'] : '' ) . '" aria-label="' . esc_attr__( 'Number of reviews', 'launch-builder' ) . '">' );
			$h .= $cell( '<input type="text" class="regular-text" name="' . $n( 'url' ) . '" value="' . esc_attr( (string) ( $row['url'] ?? '' ) ) . '" placeholder="https://" aria-label="' . esc_attr__( 'Link', 'launch-builder' ) . '">' );
		} elseif ( 'hours_spec' === $k ) {
			global $wp_locale;
			$days = '';
			foreach ( self::DAYS as $pos => $d ) {
				$label = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $pos + 1 ) % 7 ) ) : $d;
				$days .= '<label class="lb-day"><input type="checkbox" name="' . esc_attr( 'lb[' . $k . '][' . $i . '][days][]' ) . '" value="' . esc_attr( $d ) . '"' . checked( in_array( $d, (array) ( $row['days'] ?? [] ), true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
			}
			$h .= $cell( $days );
			$h .= $cell( '<input type="time" name="' . $n( 'opens' ) . '" value="' . esc_attr( (string) ( $row['opens'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Opens', 'launch-builder' ) . '">' );
			$h .= $cell( '<input type="time" name="' . $n( 'closes' ) . '" value="' . esc_attr( (string) ( $row['closes'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Closes', 'launch-builder' ) . '">' );
		} else {
			$h .= $cell( '<input type="date" name="' . $n( 'date' ) . '" value="' . esc_attr( (string) ( $row['date'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Date', 'launch-builder' ) . '">' );
			$h .= $cell( '<input type="time" name="' . $n( 'opens' ) . '" value="' . esc_attr( (string) ( $row['opens'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Opens', 'launch-builder' ) . '">' );
			$h .= $cell( '<input type="time" name="' . $n( 'closes' ) . '" value="' . esc_attr( (string) ( $row['closes'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Closes', 'launch-builder' ) . '">' );
			$h .= $cell( '<label><input type="checkbox" name="' . $n( 'closed' ) . '" value="1"' . checked( ! empty( $row['closed'] ), true, false ) . '> ' . esc_html__( 'Closed', 'launch-builder' ) . '</label>' );
		}
		return $h . '<td class="lb-rep-x"><button type="button" class="button-link button-link-delete" data-lb-remove aria-label="' . esc_attr__( 'Remove this row', 'launch-builder' ) . '">' . esc_html__( 'Remove', 'launch-builder' ) . '</button></td></tr>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$own = (array) get_option( self::OPT, [] );
		$res = self::resolve();
		$src = (array) get_option( self::OPT_SRC, [] );
		$read = self::source();
		$lines = [];
		foreach ( (array) ( $src['map'] ?? [] ) as $k => $v ) {
			$lines[] = $k . ' = ' . $v;
		}
		echo '<style>.lb-biz .form-table th{width:220px}.lb-biz h2.title{margin-top:28px;padding-top:18px;border-top:1px solid #dcdcde}.lb-biz h2.title:first-of-type{border-top:0}.lb-rep{max-width:900px;margin-top:4px}.lb-rep td,.lb-rep th{vertical-align:middle}.lb-rep .lb-rep-x{width:70px;text-align:right}.lb-day{display:inline-block;margin:2px 10px 2px 0;white-space:nowrap}.lb-biz .lb-now{background:#f0f6fc;border-left:4px solid #72aee6;padding:8px 12px;margin:8px 0 0;max-width:880px}.lb-biz nav.lb-jump{margin:12px 0 0;display:flex;flex-wrap:wrap;gap:6px 14px}</style>';
		echo '<div class="wrap lb-wrap lb-biz"><h1>' . esc_html__( 'Business Info', 'launch-builder' ) . '</h1>';
		$saved = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( 'source-refused' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Saved, but that option can\'t be used as a source.', 'launch-builder' ) . '</p></div>';
		} elseif ( 'rejected' === $saved ) {
			$keys = isset( $_GET['lb_rejected'] ) ? sanitize_text_field( wp_unslash( $_GET['lb_rejected'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			/* translators: %s: comma-separated field keys */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Saved, except %s: Business Info values can use lb, site, area and service tokens only.', 'launch-builder' ), $keys ) ) . '</p></div>';
		} elseif ( '1' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved. Every page now shows the new values.', 'launch-builder' ) . '</p></div>';
		}
		echo '<p>' . wp_kses( __( 'Set these once. Patterns insert them wherever they are needed, so changing a value here updates every page, header and footer. Use <code>{{lb.phone}}</code> in text and <code>#lb-phone_link</code> in link fields, or <code>[lb field="phone"]</code> anywhere else. Contact fields left blank show a demo value; ratings, review counts and social links never do (an empty social link is left out).', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';

		$all = self::fields();
		$by_group = [];
		foreach ( $all as $k => $f ) {
			$by_group[ $f[5] ][] = $k;
		}
		$titles = [
			'Basics'                    => __( 'Basics', 'launch-builder' ),
			'Address'                   => __( 'Address', 'launch-builder' ),
			'Service area and licences' => __( 'Service area and licences', 'launch-builder' ),
			'Hours'                     => __( 'Hours', 'launch-builder' ),
			'Reviews'                   => __( 'Reviews', 'launch-builder' ),
			'Business details'          => __( 'Business details', 'launch-builder' ),
			'Buttons and links'         => __( 'Buttons and links', 'launch-builder' ),
			'Promotions and financing'  => __( 'Promotions and financing', 'launch-builder' ),
			'Social profiles'           => __( 'Social profiles', 'launch-builder' ),
			'Logos'                     => __( 'Logos', 'launch-builder' ),
			'Form embed'                => __( 'Form embed', 'launch-builder' ),
			'Writing brief'             => __( 'Writing brief', 'launch-builder' ),
		];
		$intro = [
			'Address'       => __( 'Fill in the structured address and the one-line address is built from it (and used for maps and search engines).', 'launch-builder' ),
			'Hours'         => __( 'Add opening hours (or tick Open 24/7) and the one-line hours are built from them. Days without a row are shown as closed to search engines.', 'launch-builder' ),
			'Reviews'       => __( 'One row per review site, with the numbers shown on that site. The overall rating (weighted by review count) and the total count are worked out from these unless you type them below. Leave this empty rather than guess: nothing ever invents a rating.', 'launch-builder' ),
			'Writing brief' => __( 'Notes for whoever writes the copy (you, a copywriter or an AI assistant). Not shown on the site.', 'launch-builder' ),
		];
		echo '<nav class="lb-jump" aria-label="' . esc_attr__( 'Sections', 'launch-builder' ) . '">';
		foreach ( self::GROUPS as $g ) {
			echo '<a href="#lb-g-' . esc_attr( sanitize_title( $g ) ) . '">' . esc_html( $titles[ $g ] ?? $g ) . '</a>';
		}
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_company_save">';
		wp_nonce_field( 'lb_company_save' );
		foreach ( self::GROUPS as $g ) {
			if ( empty( $by_group[ $g ] ) ) {
				continue;
			}
			echo '<h2 class="title" id="lb-g-' . esc_attr( sanitize_title( $g ) ) . '">' . esc_html( $titles[ $g ] ?? $g ) . '</h2>';
			if ( isset( $intro[ $g ] ) ) {
				echo '<p class="description" style="max-width:880px">' . esc_html( $intro[ $g ] ) . '</p>';
			}
			$reps = array_values( array_intersect( $by_group[ $g ], [ 'reviews', 'hours_spec', 'holiday_hours' ] ) );
			foreach ( $reps as $k ) {
				echo '<h3 style="margin:16px 0 4px">' . esc_html( $all[ $k ][0] ) . '</h3>';
				echo self::repeater( $k, (array) ( $own[ $k ] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				if ( isset( $res[ $k ]['from'] ) && 'source' === $res[ $k ]['from'] ) {
					echo '<p class="description"><strong>' . esc_html__( 'Rows currently come from the source option below:', 'launch-builder' ) . '</strong> ' . esc_html( $res[ $k ]['value'] ) . '</p>';
				}
			}
			if ( 'Reviews' === $g ) {
				$d = self::review_data();
				if ( null !== $d['rating'] || null !== $d['count'] ) {
					/* translators: 1: rating, 2: number of reviews */
					echo '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site: %1$s stars from %2$s reviews.', 'launch-builder' ), null !== $d['rating'] ? number_format_i18n( $d['rating'], 1 ) : '–', null !== $d['count'] ? number_format_i18n( $d['count'] ) : '–' ) ) . '</p>';
				} else {
					echo '<p class="lb-now">' . esc_html__( 'No rating yet. Rating widgets set to use Business Info show demo numbers to editors only until you add a review platform.', 'launch-builder' ) . '</p>';
				}
			}
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $by_group[ $g ] as $k ) {
				if ( in_array( $k, $reps, true ) ) {
					continue;
				}
				echo self::field_row( $k, $all[ $k ], $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			echo '</tbody></table>';
		}

		echo '<h2 class="title" id="lb-g-source">' . esc_html__( 'Read from an existing settings page (optional)', 'launch-builder' ) . '</h2><p style="max-width:880px">' . wp_kses( __( 'If this site already keeps these facts in another settings option, such as a Meta Box settings page, enter its option name and Business Info reads them from there instead. Fields are matched by common names (phone_number, business_name, linked_phone_number, quote_page, site_form…); map any others below, one per line, like <code>phone = my_phone_field</code>. Values are converted by type: a page ID in a link field becomes that page\'s address, an image ID stays an image.', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';
		echo '<p><input type="text" name="lb_source" class="regular-text" value="' . esc_attr( (string) ( $src['option'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'option name, e.g. company_information', 'launch-builder' ) . '"></p>';
		if ( is_multisite() ) {
			echo '<p><label><input type="checkbox" name="lb_source_network" value="1"' . checked( ! empty( $src['network'] ), true, false ) . '> ' . esc_html__( 'Network option (shared by every site)', 'launch-builder' ) . '</label></p>';
		}
		echo '<p><textarea name="lb_map" rows="4" class="large-text code" placeholder="phone = phone_number">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></p>';
		if ( $read['meta_box'] ) {
			echo '<p class="description">' . esc_html__( 'This is a Meta Box settings page: values are read through Meta Box.', 'launch-builder' ) . '</p>';
		}
		if ( $read['skipped'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some values in the source could not be used:', 'launch-builder' ) . '</p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $read['skipped'] as $s ) {
				echo '<li><code>' . esc_html( $s['source_key'] ) . '</code> &rarr; ' . esc_html( $s['key'] ) . ': ' . esc_html( $s['reason'] ) . '</li>';
			}
			echo '</ul></div>';
		}
		submit_button( __( 'Save business info', 'launch-builder' ) );
		echo '</form></div>';
		$js = 'document.addEventListener("click",function(e){var a=e.target.closest("[data-lb-add]");if(a){var k=a.getAttribute("data-lb-add"),t=document.getElementById("lb-tpl-"+k),b=document.querySelector("[data-lb-rep=\'"+k+"\'] tbody");if(t&&b){var w=document.createElement("tbody");w.innerHTML=t.innerHTML.replace(/__i__/g,"n"+Date.now());var r=w.firstElementChild;b.appendChild(r);var f=r.querySelector("input,select");if(f){f.focus()}}}var x=e.target.closest("[data-lb-remove]");if(x){x.closest("tr").remove()}});';
		wp_print_inline_script_tag( $js );
	}
}
