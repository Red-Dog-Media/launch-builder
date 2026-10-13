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
 *
 * The screen (Company_Admin) shows a field up front only when people would show it on the site or it feeds structured
 * data and Rank Math (see GROUPS); the rest sit under "More options", and OFF_SCREEN fields have no input at all. Every
 * field keeps its token whatever the screen does: the engine never depends on where a field is shown.
 */
final class Company {

	const OPT     = 'launch_builder_company';
	const OPT_SRC = 'launch_builder_company_source';

	/**
	 * Screen groups, in screen order (index 5 of each field). The first six are always visible; MORE_GROUPS sit under the
	 * collapsed "More options"; OFF_SCREEN holds fields that only the tokens and the assistant tools use.
	 */
	const GROUPS = [ 'Basics', 'Address and hours', 'Reviews', 'Social profiles', 'Logos', 'Search engines', 'Form embed', 'Wording and links', 'Search engine details', 'Numbers and claims', 'Offers', 'Writing brief', 'Off screen' ];

	/** The groups under "More options", in order. */
	const MORE_GROUPS = [ 'Form embed', 'Wording and links', 'Search engine details', 'Numbers and claims', 'Offers', 'Writing brief' ];

	/** The group of fields with no input on the screen: nothing a person would type or show on a page by hand. */
	const OFF_SCREEN = 'Off screen';

	/**
	 * Tokens people type most often. get-tokens lists these first and marks every other Business Info token advanced
	 * (they all keep working). New fields are advanced until added here.
	 */
	const COMMON = [ 'name', 'phone', 'phone_link', 'email', 'address', 'address_br', 'hours', 'hours_br', 'tagline', 'cta_label', 'cta_url', 'cta2_label', 'cta2_url', 'quote_url', 'city', 'year', 'facebook', 'instagram', 'google', 'youtube', 'maps_url', 'form_html', 'rating', 'review_count', 'review_line', 'review_count_line', 'review_url' ];

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
		Company_Admin::boot();
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
			// Basics
			'name'       => [ 'Business name', 'text', 'Your Business', [ 'business_name', 'company_name', 'name' ], '', 'Basics' ],
			'phone'      => [ 'Phone number', 'text', '(555) 555-0123', [ 'phone_number', 'phone', 'telephone' ], 'As you want it to look on the site.', 'Basics' ],
			'email'      => [ 'Email', 'email', 'hello@yourbusiness.com', [ 'email', 'email_address', 'business_email' ], '', 'Basics' ],
			'tagline'    => [ 'Tagline', 'text', 'Trusted local experts in Your City, ST', [ 'tagline', 'slogan', 'brand_tagline' ], 'The line shown under the title in the page banner.', 'Basics' ],
			'about'      => [ 'Short description', 'textarea', '', [ 'about', 'description', 'business_description', 'mission' ], 'Two or three sentences: who you serve and what makes you different. Search engines can show it too.', 'Basics' ],
			'cta_label'  => [ 'Main button text', 'text', 'Get a Free Quote', [ 'cta_label', 'cta_text', 'button_text' ], 'Every main call-to-action button.', 'Basics' ],
			'cta_url'    => [ 'Main button link', 'url', '', [ 'cta_url', 'cta_link', 'button_url' ], 'Leave blank to use the quote or contact page.', 'Basics' ],
			'cta2_label' => [ 'Second button text', 'text', 'Call Us Today', [ 'cta2_label', 'cta_secondary_label' ], '', 'Basics' ],
			'cta2_url'   => [ 'Second button link', 'url', '', [ 'cta2_url', 'cta_secondary_url' ], 'Leave blank to call the phone number.', 'Basics' ],
			'quote_url'  => [ 'Quote or contact page', 'url', '', [ 'quote_page', 'quote_url', 'quote_link', 'contact_page' ], 'Where the main button goes when it has no link of its own.', 'Basics' ],

			// Address and hours
			'street'      => [ 'Street address', 'text', '', [ 'street', 'address_line_1', 'street_line' ], '', 'Address and hours' ],
			'locality'    => [ 'City', 'text', '', [ 'locality', 'address_city', 'city_name' ], '', 'Address and hours' ],
			'region'      => [ 'State', 'text', '', [ 'region', 'state', 'address_state', 'province' ], 'For example TX.', 'Address and hours' ],
			'postal_code' => [ 'ZIP code', 'text', '', [ 'postal_code', 'zip', 'zip_code', 'postcode' ], '', 'Address and hours' ],
			'country'     => [ 'Country', 'text', '', [ 'country', 'country_code' ], 'Two-letter code, for example US.', 'Address and hours' ],
			'hours_spec'  => [ 'Opening hours', 'list', '', [ 'hours_spec', 'opening_hours_spec' ], '[{days:["Mo","Tu"], opens:"08:00", closes:"18:00"}]. When set, the one-line hours are built from it.', 'Address and hours' ],
			'is_24_7'     => [ 'Open 24/7', 'bool', '', [ 'is_24_7', 'open_24_7', 'always_open' ], 'The one-line hours then read "Open 24/7".', 'Address and hours' ],

			// Reviews
			'reviews'    => [ 'Review sites', 'list', '', [ 'reviews' ], '[{platform:"google", rating:4.9, count:887, url}]', 'Reviews' ],
			'review_url' => [ 'Leave-a-review link', 'url', '', [ 'review_url', 'review_link' ], 'Where happy customers go to write a review.', 'Reviews' ],

			// Social profiles (an empty one is left out wherever it would show)
			'facebook'  => [ 'Facebook', 'url', '', [ 'facebook_url', 'facebook' ], 'Leave blank and no Facebook link shows anywhere.', 'Social profiles' ],
			'instagram' => [ 'Instagram', 'url', '', [ 'instagram_url', 'instagram' ], '', 'Social profiles' ],
			'google'    => [ 'Google Business Profile', 'url', '', [ 'google_url', 'google_business_profile', 'google' ], '', 'Social profiles' ],
			'youtube'   => [ 'YouTube', 'url', '', [ 'youtube_url', 'youtube' ], '', 'Social profiles' ],
			'yelp'      => [ 'Yelp', 'url', '', [ 'yelp_url', 'yelp' ], '', 'Social profiles' ],
			'tiktok'    => [ 'TikTok', 'url', '', [ 'tiktok_url', 'tiktok' ], '', 'Social profiles' ],
			'linkedin'  => [ 'LinkedIn', 'url', '', [ 'linkedin_url', 'linkedin' ], '', 'Social profiles' ],
			'x'         => [ 'X (Twitter)', 'url', '', [ 'x_url', 'twitter_url', 'twitter' ], '', 'Social profiles' ],
			'nextdoor'  => [ 'Nextdoor', 'url', '', [ 'nextdoor_url', 'nextdoor' ], '', 'Social profiles' ],
			'bbb'       => [ 'Better Business Bureau', 'url', '', [ 'bbb_url', 'bbb' ], '', 'Social profiles' ],
			'angi'      => [ 'Angi', 'url', '', [ 'angi_url', 'angi', 'angies_list' ], '', 'Social profiles' ],
			'houzz'     => [ 'Houzz', 'url', '', [ 'houzz_url', 'houzz' ], '', 'Social profiles' ],

			// Logos
			'logo_inverse' => [ 'Logo for dark backgrounds', 'image', '', [ 'logo_inverse', 'logo_light', 'logo_white' ], 'Used where the logo sits on a dark band.', 'Logos' ],
			'site_icon'    => [ 'Site icon', 'image', '', [ 'site_icon', 'favicon' ], 'A square image, at least 512 pixels wide.', 'Logos' ],

			// Search engines
			'business_type' => [ 'Business type', 'text', '', [ 'schema_business_type', 'local_business_type' ], 'The closest match, for example Plumber, HVACBusiness or Electrician. Search engines use it.', 'Search engines' ],
			'price_range'   => [ 'Price range', 'text', '', [ 'price_range' ], 'One to four dollar signs.', 'Search engines' ],

			// More options: the contact form
			'form_html' => [ 'Form embed code', 'html', '', [ 'site_form', 'form_embed', 'form', 'contact_form' ], 'Paste the embed code (iframe and script). The form patterns show it.', 'Form embed' ],

			// More options: wording and links (each one has a sensible fallback)
			'phone_link' => [ 'Phone link', 'url', '', [ 'linked_phone_number', 'phone_link', 'tel_link' ], 'Leave blank to build it from the phone number.', 'Wording and links' ],
			'address'    => [ 'Address on one line', 'text', '123 Main Street, Your City, ST 00000', [ 'address', 'street_address', 'full_address' ], 'Street first, then city, state and zip. Built from the address fields when those are filled in.', 'Wording and links' ],
			'address2'   => [ 'Second office address', 'text', 'Second City, ST', [ 'address2', 'second_address', 'office_2' ], 'For footers that list two offices.', 'Wording and links' ],
			'city'       => [ 'Main city or area', 'text', 'Your City, ST', [ 'location', 'city', 'service_area' ], 'Leave blank to use the city and state of the address.', 'Wording and links' ],
			'hours'      => [ 'Hours on one line', 'text', 'Mon–Fri 8am–6pm · Sat 9am–2pm', [ 'hours', 'business_hours', 'opening_hours' ], 'Separate lines with a dot or semicolon. Built from the opening hours when those are filled in.', 'Wording and links' ],
			'maps_url'   => [ 'Directions link', 'url', '', [ 'maps_url', 'directions_url', 'google_maps_link' ], 'Leave blank for a Google Maps search of the address.', 'Wording and links' ],
			'license'    => [ 'License numbers', 'textarea', 'License # 00000', [ 'license', 'licenses', 'license_numbers', 'licensing' ], 'One per line, for example HVAC License # CAC123456.', 'Wording and links' ],

			// More options: details only search engines read
			'emergency_phone' => [ 'Emergency or after-hours phone', 'text', '', [ 'emergency_phone', 'after_hours_phone' ], 'Search engines list it as your emergency number.', 'Search engine details' ],
			'areas_served'    => [ 'Areas served', 'list', '', [ 'areas_served' ], 'Cities or regions, one per line. Used for search engines when you have no service area pages.', 'Search engine details' ],
			'lat'             => [ 'Latitude', 'number', '', [ 'lat', 'latitude' ], 'With the longitude, tells search engines where you are. Not shown on the site.', 'Search engine details' ],
			'lng'             => [ 'Longitude', 'number', '', [ 'lng', 'lon', 'longitude' ], '', 'Search engine details' ],

			// More options: numbers you can show
			'rating'       => [ 'Overall rating', 'number', '', [ 'rating', 'overall_rating', 'google_rating' ], 'For example 4.9. Leave blank to work it out from the review sites. Never invented.', 'Numbers and claims' ],
			'review_count' => [ 'Review count', 'number', '', [ 'review_count', 'reviews_count', 'number_of_reviews' ], 'Leave blank to add up the review sites.', 'Numbers and claims' ],
			'years'        => [ 'Years in business', 'number', '', [ 'years', 'years_in_business' ], 'Leave blank rather than guess.', 'Numbers and claims' ],
			'jobs'         => [ 'Jobs completed', 'number', '', [ 'jobs', 'jobs_completed' ], 'Leave blank rather than guess.', 'Numbers and claims' ],
			'techs'        => [ 'Technicians', 'number', '', [ 'techs', 'technicians', 'team_size' ], 'Leave blank rather than guess.', 'Numbers and claims' ],

			// More options: offers
			'promo_text'     => [ 'Promotion text', 'text', '', [ 'promo_text', 'promotion', 'offer_text' ], 'For example 50 dollars off any repair this month.', 'Offers' ],
			'promo_url'      => [ 'Promotion link', 'url', '', [ 'promo_url', 'promotion_url', 'offer_url' ], '', 'Offers' ],
			'promo_expires'  => [ 'Promotion ends', 'date', '', [ 'promo_expires', 'promotion_expires', 'offer_expires' ], 'The promotion text and link stop showing after this day.', 'Offers' ],
			'financing_url'  => [ 'Financing link', 'url', '', [ 'financing_url', 'financing_link' ], '', 'Offers' ],
			'financing_text' => [ 'Financing text', 'text', '', [ 'financing_text', 'financing' ], 'For example 0% financing for 12 months.', 'Offers' ],

			// More options: notes for the person or assistant writing the copy (not shown on the site)
			'industry' => [ 'What you do', 'text', '', [ 'industry', 'business_type', 'niche' ], 'For example residential plumbing. Pages fall back to it where they name the service.', 'Writing brief' ],
			'keywords' => [ 'Keywords', 'text', '', [ 'keywords', 'seo_keywords' ], 'Comma-separated phrases to work into copy.', 'Writing brief' ],
			'tone'     => [ 'Tone of voice', 'text', '', [ 'tone', 'tone_of_voice', 'voice' ], 'For example friendly, direct, professional.', 'Writing brief' ],

			// Off screen: still saved, read and used through tokens and the assistant tools
			'holiday_hours' => [ 'Holiday hours', 'list', '', [ 'holiday_hours', 'special_hours' ], '[{date:"2026-12-25", closed:true} or {date, opens, closes}]', 'Off screen' ],
			'gbp_place_id'  => [ 'Google Business Profile place ID', 'text', '', [ 'gbp_place_id', 'place_id', 'google_place_id' ], '', 'Off screen' ],
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

	/** Is this a token people rarely type by hand? get-tokens marks it advanced and lists it after the common ones; it keeps working. */
	public static function is_advanced( string $key ): bool {
		return ! in_array( $key, self::COMMON, true );
	}

	/** Does the Business Info screen have an input for this field? */
	public static function on_screen( string $key ): bool {
		$f = self::fields();
		return isset( $f[ $key ] ) && self::OFF_SCREEN !== $f[ $key ][5];
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
	/**
	 * Is the site still being set up? While search engines are asked to stay away (Settings > Reading, or a brief that has not
	 * gone live yet), a blank field shows a demo value so the design reads as finished. Once the site is public a blank field
	 * prints nothing: demo text never reaches visitors or search engines. Filter launch_builder_company_demo overrides it.
	 */
	public static function setup_mode(): bool {
		return (bool) apply_filters( 'launch_builder_company_demo', '0' === (string) get_option( 'blog_public', '1' ) );
	}

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
				$v = 'list' === $f[1] || ! self::setup_mode() ? ( 'list' === $f[1] ? [] : '' ) : (string) $f[2]; // A public site never prints a demo value.
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
				if ( 'lat' === $key || 'lng' === $key ) { // A map position off the globe would make the search engine data invalid.
					return abs( (float) $v ) <= ( 'lat' === $key ? 90 : 180 ) ? (string) ( 0 + $v ) : '';
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
			if ( '' === $clean && '' !== trim( (string) $v ) && in_array( $type, [ 'number', 'email', 'date' ], true ) ) { // A typo never wipes the value that was saved.
				$why = [ 'number' => 'Not a valid number.', 'email' => 'Not an email address.', 'date' => 'Not a date (use YYYY-MM-DD).' ][ $type ];
				self::$rejected[ $k ] = in_array( $k, [ 'lat', 'lng' ], true ) && is_numeric( $v ) ? 'Latitude runs from -90 to 90 and longitude from -180 to 180.' : $why;
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
		if ( self::OPT === $option || self::OPT_SRC === $option || 'blog_public' === $option ) { // blog_public decides whether blank fields show demo values
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
		Abilities::add( 'get-company', 'Read the global business info', 'Business facts used by every pattern through tokens ({{lb.phone}} in text, #lb-cta_url in link fields): name, phone, email, structured address and opening hours, review platforms, button text and links, promotions, form embed, social links. Shows the saved value, the value in effect and where it comes from (source, saved, default, derived, computed, expired); source_skipped lists source-option values that could not be used. Patterns show a visible demo value for contact fields until set; ratings, counts and social links never have one. Each field carries its screen group, advanced (rarely typed by hand; the token still works), on_screen (false = no input on the Business Info screen) and rank_math (the Rank Math settings it feeds; fill-rank-math previews and fills them).',
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
		$feeds = Compat::business_feeds();
		foreach ( self::fields() as $k => $f ) {
			$eff = 'list' === $f[1] ? $res[ $k ]['data'] : $res[ $k ]['value'];
			$saved = $own[ $k ] ?? ( 'list' === $f[1] ? [] : '' );
			$rows[] = [
				'key'        => $k,
				'label'      => $f[0],
				'type'       => $f[1],
				'token'      => '{{lb.' . $k . '}}',
				'link_token' => 'url' === $f[1] && self::is_link_key( $k ) ? '#lb-' . $k : null,
				'group'      => $f[5],
				'advanced'   => self::is_advanced( $k ),
				'on_screen'  => self::on_screen( $k ),
				'rank_math'  => $feeds[ $k ] ?? [],
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

	/** The Business Info screen (the Admin menu points here; the screen itself is Company_Admin). */
	public static function page(): void {
		Company_Admin::page();
	}
}
