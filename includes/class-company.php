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
 * The screen (Company_Admin) shows a field up front only when people type it for their pages (see GROUPS); a few options sit
 * under "More options", and OFF_SCREEN fields have no input at all. Every field keeps its token whatever the screen does:
 * the engine never depends on where a field is shown.
 *
 * WordPress already holds some of these facts, so they are read, never copied: with no value saved here, the business name
 * is the site title, the tagline is the WordPress tagline, the country is a guess from the site language and the site icon is
 * the WordPress site icon (resolve(), from = site). A WordPress placeholder (the default title or tagline) is not used. The
 * country guess is only a hint: Rank Math and structured data get a country that was typed (or came from a source), never the
 * guess, because a business can sit in a different country than its site language.
 */
final class Company {

	const OPT     = 'launch_builder_company';
	const OPT_SRC = 'launch_builder_company_source';

	/**
	 * Names a settings option may use that older versions did not match (field => names). A source saved before they existed keeps
	 * the older, exact matching until someone chooses "Match more fields" in the Meta Box panel (the source then carries
	 * match = 2), so an update never changes what an existing source fills.
	 */
	const ADDED_NAMES = [
		'about'       => [ 'about_us' ],
		'street'      => [ 'street_address', 'address_1', 'address1' ],
		'locality'    => [ 'city', 'town' ],
		'postal_code' => [ 'zipcode' ],
	];

	/**
	 * Screen groups, in screen order (index 5 of each field). The first five are always visible; MORE_GROUPS sit under the
	 * collapsed "More options"; OFF_SCREEN holds fields that only the tokens, briefs and the assistant tools use.
	 */
	const GROUPS = [ 'Basics', 'Address and hours', 'Reviews', 'Social profiles', 'Buttons and form', 'More address', 'Wording and links', 'Offers', 'Off screen' ];

	/** The groups under "More options", in order. */
	const MORE_GROUPS = [ 'More address', 'Wording and links', 'Offers' ];

	/**
	 * Business Info values a page still shows as sample text until they are set. The name is added by essentials(): the site title
	 * supplies it, so it counts only while that title is a WordPress placeholder.
	 */
	const ESSENTIALS = [ 'phone', 'email', 'address', 'hours', 'cta_label' ];

	/** List fields typed as one entry per line (the screen posts a string; JSON arrays are accepted too). */
	const LINE_LISTS = [ 'areas_served', 'other_profiles' ];

	/** Hosts of the networks that used to have their own input. A web address in Other profiles with one of these answers that token. */
	const PROFILE_HOSTS = [
		'yelp'     => [ 'yelp.com' ],
		'tiktok'   => [ 'tiktok.com' ],
		'linkedin' => [ 'linkedin.com' ],
		'x'        => [ 'x.com', 'twitter.com' ],
		'nextdoor' => [ 'nextdoor.com' ],
		'bbb'      => [ 'bbb.org' ],
		'angi'     => [ 'angi.com', 'angieslist.com' ],
		'houzz'    => [ 'houzz.com' ],
	];

	/** Site titles and taglines WordPress or a fresh install fills in. They are placeholders, not a business name or tagline. */
	const WP_PLACEHOLDERS = [ 'my wordpress website', 'my wordpress blog', 'wordpress', 'wordpress site', 'my wordpress site', 'just another wordpress site', 'site title', 'my blog', 'my site', 'your site title', 'a wordpress site' ];

	/** The group of fields with no input on the screen: nothing a person would type or show on a page by hand. */
	const OFF_SCREEN = 'Off screen';

	/**
	 * Tokens people type most often. get-tokens lists these first and marks every other Business Info token advanced
	 * (they all keep working). New fields are advanced until added here.
	 */
	const COMMON = [ 'name', 'phone', 'phone_link', 'email', 'address', 'address_br', 'hours', 'hours_br', 'tagline', 'cta_label', 'cta_url', 'cta2_label', 'cta2_url', 'quote_url', 'city', 'year', 'facebook', 'instagram', 'google', 'youtube', 'maps_url', 'form_html', 'rating', 'review_count', 'review_line', 'review_count_line' ];

	/**
	 * The computed tokens published for the generator and the token catalog. All four are implemented and listed by
	 * computed(); the constant stays as the contract other code reads.
	 */
	const COMPUTED_PLANNED = [
		'review_count_line' => 'Review count line, e.g. "Based on 887+ reviews" (empty without reviews)',
		'review_line'       => 'Short rating line for header bars, e.g. "4.9 rating | 887+ reviews" (empty unless rating and count are set)',
		'service_count'     => 'Number of published services',
		'area_count'        => 'Number of published service areas',
		'emergency_phone_link' => 'Phone link for the emergency number (empty until one is set; a link to it is left out while it is empty)',
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

	/** @var array|null per-request cache of the source read: {values, skipped, option, network, meta_box, matched, empty, exists}. */
	private static $source = null;

	/** @var array|null per-request cache of the Meta Box settings pages. */
	private static $mb_pages = null;

	/** @var array|null per-request cache of meta_box_overview(). */
	private static $mb_overview = null;

	/** @var array<string,string> keys refused by the last update() and why. */
	private static $rejected = [];

	public static function boot(): void {
		add_filter( 'render_block', [ __CLASS__, 'filter_block' ], 20, 2 );
		add_shortcode( 'lb', [ __CLASS__, 'shortcode' ] );
		Company_Admin::boot();
		Company_Tidy::boot();
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
			// Your business
			'name'       => [ 'Business name', 'text', 'Your Business', [ 'business_name', 'company_name', 'name' ], 'Leave blank to use the site title (Settings, General).', 'Basics' ],
			'phone'      => [ 'Phone number', 'text', '(555) 555-0123', [ 'phone_number', 'phone', 'telephone' ], 'As you want it to look on the site.', 'Basics' ],
			'email'      => [ 'Email', 'email', 'hello@yourbusiness.com', [ 'email', 'email_address', 'business_email' ], '', 'Basics' ],

			// Address and hours
			'street'      => [ 'Street address', 'text', '', [ 'street', 'street_address', 'address_line_1', 'address_1', 'address1', 'street_line' ], '', 'Address and hours' ],
			'locality'    => [ 'City', 'text', '', [ 'locality', 'address_city', 'city_name', 'city', 'town' ], '', 'Address and hours' ],
			'region'      => [ 'State', 'text', '', [ 'region', 'state', 'address_state', 'province' ], 'For example TX.', 'Address and hours' ],
			'postal_code' => [ 'ZIP code', 'text', '', [ 'postal_code', 'zip', 'zip_code', 'zipcode', 'postcode' ], '', 'Address and hours' ],
			'hours_spec'  => [ 'Opening hours', 'list', '', [ 'hours_spec', 'opening_hours_spec' ], '[{days:["Mo","Tu"], opens:"08:00", closes:"18:00"}]. When set, the one-line hours are built from it.', 'Address and hours' ],
			'is_24_7'     => [ 'Open 24/7', 'bool', '', [ 'is_24_7', 'open_24_7', 'always_open' ], 'The one-line hours then read "Open 24/7".', 'Address and hours' ],

			// Reviews
			'reviews'    => [ 'Review sites', 'list', '', [ 'reviews' ], '[{platform:"google", rating:4.9, count:887, url}]', 'Reviews' ],

			// Social profiles (an empty one is left out wherever it would show)
			'facebook'       => [ 'Facebook', 'url', '', [ 'facebook_url', 'facebook' ], 'Leave blank and no Facebook link shows anywhere.', 'Social profiles' ],
			'instagram'      => [ 'Instagram', 'url', '', [ 'instagram_url', 'instagram' ], '', 'Social profiles' ],
			'google'         => [ 'Google Business Profile', 'url', '', [ 'google_url', 'google_business_profile', 'google' ], '', 'Social profiles' ],
			'youtube'        => [ 'YouTube', 'url', '', [ 'youtube_url', 'youtube' ], '', 'Social profiles' ],
			'other_profiles' => [ 'Other profiles', 'list', '', [ 'other_profiles', 'other_social', 'additional_profiles' ], 'One web address per line: Yelp, LinkedIn, TikTok, X, Nextdoor and so on. Search engines get them.', 'Social profiles' ],

			// Buttons and the contact form
			'cta_label' => [ 'Main button text', 'text', 'Get a Free Quote', [ 'cta_label', 'cta_text', 'button_text' ], 'Every main call-to-action button.', 'Buttons and form' ],
			'quote_url' => [ 'Quote or contact page', 'url', '', [ 'quote_page', 'quote_url', 'quote_link', 'contact_page' ], 'Where the main button goes when it has no link of its own.', 'Buttons and form' ],
			'form_html' => [ 'Form embed code', 'html', '', [ 'site_form', 'form_embed', 'form', 'contact_form' ], 'Paste the embed code (iframe and script). The form patterns show it.', 'Buttons and form' ],

			// More options (each one has a sensible fallback)
			'country'  => [ 'Country', 'text', '', [ 'country', 'country_code' ], 'Two-letter code, for example CA. Search engines get a country only when you type it here.', 'More address' ],
			'address2' => [ 'Second office address', 'text', 'Second City, ST', [ 'address2', 'second_address', 'office_2' ], 'For footers and the second map that show two offices.', 'More address' ],
			'cta_url'    => [ 'Main button link', 'url', '', [ 'cta_url', 'cta_link', 'button_url' ], 'Leave blank to use the quote or contact page.', 'Wording and links' ],
			'cta2_label' => [ 'Second button text', 'text', 'Call Us Today', [ 'cta2_label', 'cta_secondary_label' ], '', 'Wording and links' ],
			'cta2_url'   => [ 'Second button link', 'url', '', [ 'cta2_url', 'cta_secondary_url' ], 'Leave blank to call the phone number.', 'Wording and links' ],
			'maps_url'   => [ 'Directions link', 'url', '', [ 'maps_url', 'directions_url', 'google_maps_link' ], 'Leave blank for a Google Maps search of the address.', 'Wording and links' ],
			'license'    => [ 'License numbers', 'textarea', 'License # 00000', [ 'license', 'licenses', 'license_numbers', 'licensing' ], 'One per line, for example HVAC License # CAC123456.', 'Wording and links' ],
			'city'       => [ 'Area you serve', 'text', 'Your City, ST', [ 'location', 'city', 'service_area' ], 'Leave blank to use the city and state of the address.', 'Wording and links' ],
			'promo_text' => [ 'Promotion line', 'text', '', [ 'promo_text', 'promotion', 'offer_text' ], 'One short line, for example 50 dollars off any repair this month.', 'Offers' ],

			// Off screen: no input here. Each is still saved, read and used through tokens, briefs and the assistant tools, so a
			// value saved by an earlier version keeps printing. Name, tagline and site icon read through to WordPress (so does the
			// country, as a guess from the site language).
			'tagline'         => [ 'Tagline', 'text', 'Trusted local experts in Your City, ST', [ 'tagline', 'slogan', 'brand_tagline' ], 'Comes from Settings, General unless one is saved here.', 'Off screen' ],
			'site_icon'      => [ 'Site icon', 'image', '', [ 'site_icon', 'favicon' ], 'Comes from WordPress (Appearance, Customize).', 'Off screen' ],
			'logo_inverse'    => [ 'Logo for dark backgrounds', 'image', '', [ 'logo_inverse', 'logo_light', 'logo_white' ], 'Moved to Branding (the logo above the admin menu).', 'Off screen' ],
			'about'           => [ 'Short description', 'textarea', '', [ 'about', 'about_us', 'description', 'business_description', 'mission' ], 'Search engines can show it. Rank Math has its own box.', 'Off screen' ],
			'review_url'      => [ 'Leave-a-review link', 'url', '', [ 'review_url', 'review_link' ], 'Where happy customers go to write a review.', 'Off screen' ],
			'yelp'            => [ 'Yelp', 'url', '', [ 'yelp_url', 'yelp' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'tiktok'          => [ 'TikTok', 'url', '', [ 'tiktok_url', 'tiktok' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'linkedin'        => [ 'LinkedIn', 'url', '', [ 'linkedin_url', 'linkedin' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'x'               => [ 'X (Twitter)', 'url', '', [ 'x_url', 'twitter_url', 'twitter' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'nextdoor'        => [ 'Nextdoor', 'url', '', [ 'nextdoor_url', 'nextdoor' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'bbb'             => [ 'Better Business Bureau', 'url', '', [ 'bbb_url', 'bbb' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'angi'            => [ 'Angi', 'url', '', [ 'angi_url', 'angi', 'angies_list' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'houzz'           => [ 'Houzz', 'url', '', [ 'houzz_url', 'houzz' ], 'Add it to Other profiles instead.', 'Off screen' ],
			'business_type'   => [ 'Business type', 'text', '', [ 'schema_business_type', 'local_business_type' ], 'Search engines use it. Rank Math has its own box.', 'Off screen' ],
			'price_range'     => [ 'Price range', 'text', '', [ 'price_range' ], 'One to four dollar signs.', 'Off screen' ],
			'phone_link'      => [ 'Phone link', 'url', '', [ 'linked_phone_number', 'phone_link', 'tel_link' ], 'Built from the phone number when blank.', 'Off screen' ],
			'address'         => [ 'Address on one line', 'text', '123 Main Street, Your City, ST 00000', [ 'address', 'street_address', 'full_address' ], 'Built from the address fields when those are filled in.', 'Off screen' ],
			'hours'           => [ 'Hours on one line', 'text', 'Mon–Fri 8am–6pm · Sat 9am–2pm', [ 'hours', 'business_hours', 'opening_hours' ], 'Built from the opening hours when those are filled in.', 'Off screen' ],
			'emergency_phone' => [ 'Emergency or after-hours phone', 'text', '', [ 'emergency_phone', 'after_hours_phone' ], 'Search engines list it as your emergency number.', 'Off screen' ],
			'areas_served'    => [ 'Areas served', 'list', '', [ 'areas_served' ], 'Cities or regions, one per line. Used for search engines when you have no service area pages.', 'Off screen' ],
			'lat'             => [ 'Latitude', 'number', '', [ 'lat', 'latitude' ], 'With the longitude, tells search engines where you are. Not shown on the site.', 'Off screen' ],
			'lng'             => [ 'Longitude', 'number', '', [ 'lng', 'lon', 'longitude' ], '', 'Off screen' ],
			'rating'          => [ 'Overall rating', 'number', '', [ 'rating', 'overall_rating', 'google_rating' ], 'For example 4.9. Leave blank to work it out from the review sites. Never invented.', 'Off screen' ],
			'review_count'    => [ 'Review count', 'number', '', [ 'review_count', 'reviews_count', 'number_of_reviews' ], 'Leave blank to add up the review sites.', 'Off screen' ],
			'years'           => [ 'Years in business', 'number', '', [ 'years', 'years_in_business' ], 'Leave blank rather than guess.', 'Off screen' ],
			'jobs'            => [ 'Jobs completed', 'number', '', [ 'jobs', 'jobs_completed' ], 'Leave blank rather than guess.', 'Off screen' ],
			'techs'           => [ 'Technicians', 'number', '', [ 'techs', 'technicians', 'team_size' ], 'Leave blank rather than guess.', 'Off screen' ],
			'promo_url'       => [ 'Promotion link', 'url', '', [ 'promo_url', 'promotion_url', 'offer_url' ], '', 'Off screen' ],
			'promo_expires'   => [ 'Promotion ends', 'date', '', [ 'promo_expires', 'promotion_expires', 'offer_expires' ], 'The promotion text and link stop showing after this day.', 'Off screen' ],
			'financing_url'   => [ 'Financing link', 'url', '', [ 'financing_url', 'financing_link' ], '', 'Off screen' ],
			'financing_text'  => [ 'Financing text', 'text', '', [ 'financing_text', 'financing' ], 'For example 0% financing for 12 months.', 'Off screen' ],
			'industry'        => [ 'What you do', 'text', '', [ 'industry', 'business_type', 'niche' ], 'For example residential plumbing. Pages fall back to it where they name the service.', 'Off screen' ],
			'keywords'        => [ 'Keywords', 'text', '', [ 'keywords', 'seo_keywords' ], 'Comma-separated phrases to work into copy.', 'Off screen' ],
			'tone'            => [ 'Tone of voice', 'text', '', [ 'tone', 'tone_of_voice', 'voice' ], 'For example friendly, direct, professional.', 'Off screen' ],
			'holiday_hours'   => [ 'Holiday hours', 'list', '', [ 'holiday_hours', 'special_hours' ], '[{date:"2026-12-25", closed:true} or {date, opens, closes}]', 'Off screen' ],
			'gbp_place_id'    => [ 'Google Business Profile place ID', 'text', '', [ 'gbp_place_id', 'place_id', 'google_place_id' ], '', 'Off screen' ],
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

	/**
	 * The essentials a page needs set: the business name first, then ESSENTIALS. The name counts as missing only while nothing is
	 * typed and the site title is empty or a WordPress placeholder (a public page then prints no name at all).
	 *
	 * @return string[]
	 */
	public static function essentials(): array {
		return array_merge( [ 'name' ], self::ESSENTIALS );
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
		foreach ( self::meta_box_pages() as $page ) {
			if ( $page['option'] === $option ) {
				return [ 'id' => $page['ids'][0], 'network' => $page['network'] ];
			}
		}
		return null;
	}

	/**
	 * The Meta Box settings pages on this site, one entry per option (several pages can share an option, and then share its
	 * fields): option, ids (the page ids), title, network, parent (as registered). They come from the same filter the MB
	 * Settings Page extension reads, mb_settings_pages (pages built in the Meta Box screens and pages added by code alike).
	 * A page without an option_name stores its values under its id, as the extension does. Empty without Meta Box.
	 *
	 * @return array<int,array{option:string,ids:string[],title:string,network:bool,parent:string}>
	 */
	public static function meta_box_pages(): array {
		if ( null !== self::$mb_pages ) {
			return self::$mb_pages;
		}
		$out = [];
		if ( function_exists( 'rwmb_get_value' ) || has_filter( 'mb_settings_pages' ) ) {
			foreach ( array_slice( (array) apply_filters( 'mb_settings_pages', [] ), 0, 60 ) as $page ) {
				$page = (array) $page;
				$id = isset( $page['id'] ) && is_scalar( $page['id'] ) ? (string) $page['id'] : '';
				$option = ! empty( $page['option_name'] ) && is_scalar( $page['option_name'] ) ? (string) $page['option_name'] : $id;
				if ( '' === $option || 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $option ) || ! Abilities::option_readable( $option ) ) {
					continue; // Options that hold secrets (or that WordPress keeps for itself) are never offered, previewed or read.
				}
				$network = ! empty( $page['network'] );
				$key = ( $network ? 'n:' : 's:' ) . $option;
				$title = '';
				foreach ( [ 'menu_title', 'page_title' ] as $t ) {
					if ( '' === $title && ! empty( $page[ $t ] ) && is_scalar( $page[ $t ] ) ) {
						$title = trim( wp_strip_all_tags( (string) $page[ $t ] ) );
					}
				}
				if ( isset( $out[ $key ] ) ) {
					$out[ $key ]['ids'][] = $id;
					continue;
				}
				$out[ $key ] = [ 'option' => $option, 'ids' => [ $id ], 'title' => '' !== $title ? $title : ( '' !== $id ? $id : $option ), 'network' => $network, 'parent' => isset( $page['parent'] ) && is_scalar( $page['parent'] ) ? (string) $page['parent'] : '' ];
			}
		}
		$out = array_values( $out );
		if ( did_action( 'init' ) ) { // Pages are registered on init: an earlier answer would be incomplete, so it is not kept.
			self::$mb_pages = $out;
		}
		return $out;
	}

	/** Where to edit a settings page in the admin (the page Meta Box adds), '' when it cannot be worked out. */
	public static function meta_box_page_url( array $page ): string {
		$id = (string) ( $page['ids'][0] ?? '' );
		if ( '' === $id ) {
			return '';
		}
		$parent = (string) ( $page['parent'] ?? '' );
		$base = ! empty( $page['network'] ) ? 'network_admin_url' : 'admin_url';
		$file = '' !== $parent ? (string) strtok( $parent, '?' ) : '';
		if ( '' !== $file && '.php' === substr( $file, -4 ) ) { // A page under a WordPress menu (Settings, Appearance, a post type).
			return call_user_func( $base, $parent . ( false !== strpos( $parent, '?' ) ? '&' : '?' ) . 'page=' . rawurlencode( $id ) );
		}
		return call_user_func( $base, 'admin.php?page=' . rawurlencode( $id ) );
	}

	/**
	 * For each Business Info field, the keys of a settings option ($have) that can fill it, best first. A mapped key stands
	 * alone. Otherwise the usual names (the fourth part of fields()) must match exactly first, then the same names with case,
	 * dashes and a leading company_, business_ or site_ ignored (company_phone fills Phone number), and a social profile may
	 * end in _link, _page or _profile.
	 *
	 * @param string[] $have keys of the option
	 * @param array<string,string> $map Business Info key => key in the option
	 * @param bool $broad false = the older matching of a source saved before the broader names (exact names only, ADDED_NAMES left out)
	 * @return array<string,string[]>
	 */
	public static function candidates( array $have, array $map = [], bool $broad = true ): array {
		$have = array_map( 'strval', $have );
		$norm = [];
		foreach ( $have as $h ) {
			$norm[ self::key_norm( $h ) ][] = $h;
		}
		$out = [];
		foreach ( self::fields() as $k => $f ) {
			if ( isset( $map[ $k ] ) && '' !== (string) $map[ $k ] ) {
				$out[ $k ] = in_array( (string) $map[ $k ], $have, true ) ? [ (string) $map[ $k ] ] : [];
				continue;
			}
			$names = $f[3];
			if ( ! $broad ) {
				$out[ $k ] = array_values( array_intersect( array_diff( $names, self::ADDED_NAMES[ $k ] ?? [] ), $have ) );
				continue;
			}
			if ( in_array( $k, self::SOCIALS, true ) ) {
				$names = array_merge( $names, [ $k . '_link', $k . '_page', $k . '_profile' ] );
			}
			$c = array_values( array_intersect( $names, $have ) );
			foreach ( $names as $name ) {
				foreach ( $norm[ self::key_norm( $name ) ] ?? [] as $h ) {
					if ( ! in_array( $h, $c, true ) ) {
						$c[] = $h;
					}
				}
			}
			$out[ $k ] = $c;
		}
		return $out;
	}

	/** A settings key without its case, dashes and a leading company_, business_, biz_ or site_. */
	private static function key_norm( string $key ): string {
		$k = strtolower( (string) preg_replace( '/[\s\-]+/', '_', trim( $key ) ) );
		return (string) preg_replace( '/^(company|business|biz|site)_/', '', $k );
	}

	/**
	 * Convert a source value to the field's type. Arrays are never coerced into scalar fields; numeric URL values are
	 * a post or attachment ID and must resolve.
	 *
	 * @return array{0:bool,1:mixed,2:string} [ ok, value, reason when not ok ]
	 */
	private static function convert( string $key, string $type, $raw ): array {
		if ( 'list' === $type ) {
			if ( is_string( $raw ) && in_array( $key, self::LINE_LISTS, true ) ) {
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
	 * @return array{values:array,skipped:array,option:string,network:bool,meta_box:bool,matched:array,empty:array,exists:bool}
	 */
	public static function source(): array {
		if ( null !== self::$source ) {
			return self::$source;
		}
		$src = get_option( self::OPT_SRC );
		if ( ! is_array( $src ) || empty( $src['option'] ) || ! Abilities::option_readable( $src['option'] ) ) {
			return self::$source = self::read_option( '', false );
		}
		$option = (string) $src['option'];
		$mb = self::meta_box_page( $option );
		$network = is_multisite() && ( ! empty( $src['network'] ) || ( $mb && $mb['network'] ) );
		return self::$source = self::read_option( $option, $network, (array) ( $src['map'] ?? [] ), null !== $mb, ! empty( $src['match'] ) );
	}

	/**
	 * Read one settings option as Business Info values (what source() does for the saved source; the Meta Box panel uses it
	 * to show what each settings page would fill before anyone picks one).
	 *
	 * values: Business Info key => converted value. skipped: values that could not be used. matched: Business Info key =>
	 * the key in the option that filled it. empty: Business Info key => a key that matches but holds nothing.
	 *
	 * @param array<string,string> $map Business Info key => key in the option (anything else is matched by name)
	 * @param bool $broad false = the older name matching (see candidates()), for a source saved before the broader names
	 * @return array{values:array,skipped:array,option:string,network:bool,meta_box:bool,matched:array,empty:array,exists:bool}
	 */
	public static function read_option( string $option, bool $network, array $map = [], bool $meta_box = false, bool $broad = true ): array {
		$out = [ 'values' => [], 'skipped' => [], 'option' => $option, 'network' => $network, 'meta_box' => $meta_box, 'matched' => [], 'empty' => [], 'exists' => false ];
		if ( '' === $option ) {
			return $out;
		}
		$arr = $network ? get_site_option( $option ) : get_option( $option );
		if ( ! is_array( $arr ) ) {
			return $out;
		}
		$out['exists'] = true;
		$cands = self::candidates( array_keys( $arr ), $map, $broad );
		foreach ( self::fields() as $k => $f ) {
			foreach ( $cands[ $k ] as $c ) {
				$raw = $arr[ $c ];
				if ( $meta_box && function_exists( 'rwmb_get_value' ) ) {
					$typed = rwmb_get_value( $c, [ 'object_type' => $network ? 'network_setting' : 'setting' ], $option );
					// Meta Box's typed value, unless it would turn a single stored value into an array (an image field
					// returns its attachment as an array; the stored ID converts better).
					if ( self::filled( $typed ) && ( 'list' === $f[1] || ! is_array( $typed ) || is_array( $raw ) ) ) {
						$raw = $typed;
					}
				}
				if ( ! self::filled( $raw ) && ! is_bool( $raw ) ) {
					$out['empty'][ $k ] = $c;
					continue; // Empty: try the next name.
				}
				[ $ok, $val, $why ] = self::convert( $k, $f[1], $raw );
				if ( $ok && self::filled( $val ) ) {
					$out['values'][ $k ] = $val;
					$out['matched'][ $k ] = $c;
					unset( $out['empty'][ $k ] );
					break;
				}
				if ( ! $ok ) {
					$out['skipped'][] = [ 'key' => $k, 'source_key' => $c, 'reason' => $why ];
				}
			}
		}
		return $out;
	}

	/**
	 * Where each effective value comes from, for the admin page and get-company.
	 * from: source | saved | site (read from WordPress: title, tagline, language, site icon) | default | derived (from another field) | computed (from structured fields) | expired.
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
			} elseif ( null !== ( $site = self::site_value( $k ) ) ) { // WordPress already holds it: the site title, tagline, language, site icon.
				$v = $site;
				$from = 'site';
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

		// A network that no longer has its own input answers from Other profiles, by web address.
		foreach ( self::PROFILE_HOSTS as $k => $hosts ) {
			if ( 'default' === $out[ $k ]['from'] ) {
				$url = self::profile_url( $hosts, (array) $out['other_profiles']['data'] );
				if ( '' !== $url ) {
					$out[ $k ] = [ 'value' => $url, 'from' => 'derived' ];
				}
			}
		}

		// The logo for dark backgrounds moved to Branding (the logo above the admin menu): a token that still names it prints that image.
		if ( 'default' === $out['logo_inverse']['from'] ) {
			$menu_logo = (int) ( Branding::stored()['admin']['menu_logo_image'] ?? 0 );
			$menu_url = $menu_logo > 0 && wp_attachment_is_image( $menu_logo ) ? (string) wp_get_attachment_url( $menu_logo ) : '';
			if ( '' !== $menu_url ) {
				$out['logo_inverse'] = [ 'value' => $menu_url, 'from' => 'derived', 'id' => $menu_logo ];
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
		// A street line builds the one-line address. City, state and ZIP without a street build it only when no one-line address was
		// given; with one, that line is the street part (a settings page with address, city, state and ZIP fields), unless it
		// already names the city. A state and ZIP alone are not an address: they never replace the one-line address.
		$given = 'default' !== $out['address']['from'] ? $out['address']['value'] : '';
		$tail = trim( $parts['region'] . ' ' . $parts['postal_code'] );
		$street = '' !== $parts['street'] ? $parts['street'] : ( '' !== $given && '' !== $parts['locality'] && false === stripos( $given, $parts['locality'] ) ? $given : '' );
		if ( '' !== $street || ( '' !== $parts['locality'] && '' === $given ) ) {
			$out['address'] = [ 'value' => implode( ', ', array_filter( [ $street, $parts['locality'], $tail ], 'strlen' ) ), 'from' => 'computed' ];
		}
		if ( 'default' === $out['city']['from'] && '' !== $parts['locality'] ) {
			$out['city'] = [ 'value' => implode( ', ', array_filter( [ $parts['locality'], $parts['region'] ], 'strlen' ) ), 'from' => 'derived' ];
		}

		// Opening hours (or 24/7) -> the one-line hours.
		if ( '1' === $out['is_24_7']['value'] ) {
			$out['hours'] = [ 'value' => __( 'Open 24/7', 'launch-builder' ), 'from' => 'computed' ];
		} elseif ( $out['hours_spec']['data'] ) {
			$out['hours'] = [ 'value' => $out['hours_spec']['value'], 'from' => 'computed' ];
		}

		// An address or hours line the tidy split into boxes and rows but kept as it was typed (the boxes would word it differently):
		// it keeps printing while the boxes and rows still build exactly what they built then. Change a box and the built text takes over.
		$pins = (array) get_option( Company_Tidy::OPT_PINS, [] );
		foreach ( [ 'address', 'hours' ] as $k ) {
			if ( 'computed' === $out[ $k ]['from'] && isset( $pins[ $k ] ) && $pins[ $k ] === $out[ $k ]['value'] && isset( $own[ $k ] ) && is_scalar( $own[ $k ] ) && '' !== trim( (string) $own[ $k ] ) ) {
				$out[ $k ]['value'] = trim( (string) $own[ $k ] );
			}
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

	/**
	 * What WordPress itself holds for a field that reads through to it, or null: the site title (name), the tagline, the
	 * country of the site language and the site icon (an attachment ID). A placeholder WordPress fills in is not a value.
	 */
	private static function site_value( string $key ): ?string {
		switch ( $key ) {
			case 'name':
				return self::real_text( (string) get_option( 'blogname' ) );
			case 'tagline':
				return self::real_text( (string) get_option( 'blogdescription' ) );
			case 'country':
				return self::locale_country();
			case 'site_icon':
				$id = (int) get_option( 'site_icon' );
				return $id > 0 && wp_attachment_is_image( $id ) ? (string) $id : null;
		}
		return null;
	}

	/** The site title or tagline as text, or null when it is empty or a placeholder (WP_PLACEHOLDERS, or the default tagline in the site language). */
	public static function real_text( string $text ): ?string {
		$text = trim( wp_specialchars_decode( $text, ENT_QUOTES ) );
		if ( '' === $text ) {
			return null;
		}
		$low = mb_strtolower( rtrim( $text, '.' ) );
		return in_array( $low, self::WP_PLACEHOLDERS, true ) || in_array( $low, self::localized_placeholders(), true ) ? null : $text;
	}

	/**
	 * The default tagline WordPress fills in, as the site language words it (a site installed in Spanish or German keeps that text),
	 * lower case and without the final dot. It is core's own translation, looked up under the site language even when the person
	 * reading has another one.
	 *
	 * @return string[]
	 */
	private static function localized_placeholders(): array {
		static $cache = [];
		$locale = (string) get_locale();
		if ( ! isset( $cache[ $locale ] ) ) {
			$switched = function_exists( 'switch_to_locale' ) && '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
			$cache[ $locale ] = [ mb_strtolower( rtrim( trim( wp_specialchars_decode( (string) __( 'Just another WordPress site' ), ENT_QUOTES ) ), '.' ) ) ]; // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.WP.I18n.NonSingularStringLiteralText -- core's own string and text domain.
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		return $cache[ $locale ];
	}

	/** The same text, ignoring runs of spaces and character entities (empty text is never the same). */
	public static function same_text( string $a, string $b ): bool {
		$n = static function ( string $t ): string { return trim( (string) preg_replace( '/\s+/u', ' ', wp_specialchars_decode( $t, ENT_QUOTES ) ) ); };
		return '' !== $n( $a ) && $n( $a ) === $n( $b );
	}

	/**
	 * Drop a saved business name or tagline that says the same as the WordPress site title or tagline, so it follows them from
	 * now on (the site builders call this after they set the title; nothing is lost, the text is the same).
	 *
	 * @return string[] the keys dropped
	 */
	public static function follow_site(): array {
		$own = get_option( self::OPT, [] );
		if ( ! is_array( $own ) ) {
			return [];
		}
		$gone = [];
		foreach ( [ 'name' => 'blogname', 'tagline' => 'blogdescription' ] as $k => $opt ) {
			if ( isset( $own[ $k ] ) && is_scalar( $own[ $k ] ) && self::same_text( (string) $own[ $k ], (string) get_option( $opt ) ) ) {
				unset( $own[ $k ] );
				$gone[] = $k;
			}
		}
		if ( $gone ) {
			update_option( self::OPT, $own );
			self::flush();
		}
		return $gone;
	}

	/**
	 * The two-letter country of the site language (en_US gives US), or null when the language names none (en, de). Only a guess: the
	 * Business Info screen shows it as the placeholder of the Country box, and Rank Math gets a country only when one was typed.
	 */
	public static function locale_country(): ?string {
		return 1 === preg_match( '/^[a-z]{2,3}[_-]([A-Za-z]{2})(?:[_-]|$)/', (string) get_locale(), $m ) ? strtoupper( $m[1] ) : null;
	}

	/** The first web address in $urls whose host is one of $hosts (or a subdomain of it), else ''. */
	public static function profile_url( array $hosts, array $urls ): string {
		foreach ( $urls as $u ) {
			$host = strtolower( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) );
			$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
			foreach ( $hosts as $h ) {
				if ( $host === $h || '.' . $h === substr( $host, -strlen( '.' . $h ) ) ) {
					return (string) $u;
				}
			}
		}
		return '';
	}

	/** A profile web address as stored: https:// added to a bare domain, an http:// address upgraded to https://, '' when it is not an address. */
	public static function profile_clean( string $u ): string {
		$u = trim( $u );
		if ( '' === $u || 1 === preg_match( '/\s/', $u ) ) {
			return '';
		}
		if ( 1 === preg_match( '#^http://#i', $u ) ) {
			$u = 'https://' . substr( $u, 7 ); // Profiles are https only.
		} elseif ( 1 !== preg_match( '#^https://#i', $u ) ) {
			if ( 1 !== preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $u ) ) {
				return '';
			}
			$u = 'https://' . $u;
		}
		$u = esc_url_raw( $u );
		return '' !== $u && '' !== (string) wp_parse_url( $u, PHP_URL_HOST ) ? mb_substr( $u, 0, 300 ) : '';
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
			case 'emergency_phone_link':
				return '' === trim( (string) $v['emergency_phone'] ) ? '' : self::tel( (string) $v['emergency_phone'] );
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
	public static function time( $v ): string {
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
	public static function days( $v ): array {
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
			if ( is_array( $json ) ) {
				$rows = $json;
			} elseif ( 'areas_served' === $key ) {
				$rows = preg_split( '/\r\n|\r|\n|;/', $rows ); // One per line ("Austin, TX" stays whole).
			} else {
				$rows = 'other_profiles' === $key ? self::profile_words( $rows ) : []; // Web addresses never hold spaces, but they can hold commas (a place or people page).
			}
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
			case 'other_profiles':
				foreach ( $rows as $r ) {
					$r = is_array( $r ) ? self::pick( $r, [ 'url', 'link', 'href' ] ) : $r;
					$u = is_scalar( $r ) ? self::profile_clean( (string) $r ) : '';
					if ( '' !== $u && ! in_array( $u, $out, true ) ) {
						$out[] = $u;
					}
				}
				return array_slice( $out, 0, 20 );
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

	/** The one-line hours that opening-hour rows build ('' when no row is usable). */
	public static function hours_text( array $rows ): string {
		return self::list_text( 'hours_spec', self::normalize_list( 'hours_spec', $rows ) );
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
			case 'other_profiles':
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
				if ( is_string( $v ) && ! in_array( $k, self::LINE_LISTS, true ) && '' !== trim( $v ) && ! is_array( json_decode( $v, true ) ) ) {
					self::$rejected[ $k ] = 'A list: give an array of rows (or JSON).';
					continue; // Never clear a list because of a stray string.
				}
				$clean = self::normalize_list( $k, $v );
				if ( 'other_profiles' === $k ) {
					$left_out = self::profile_dropped( $v );
					if ( $left_out ) { // The rest is saved; say what was not a web address instead of dropping it silently.
						self::$rejected[ $k ] = 'Left out, because ' . ( 1 === count( $left_out ) ? 'it is not a web address' : 'they are not web addresses' ) . ': ' . implode( ', ', $left_out ) . '.';
					}
				}
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

	/** The words of an Other profiles text: split on spaces and line breaks only (an address can hold commas), a trailing comma or semicolon dropped. */
	private static function profile_words( string $text ): array {
		$words = [];
		foreach ( (array) preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$w = rtrim( (string) $w, ',;' );
			if ( '' !== $w ) {
				$words[] = $w;
			}
		}
		return $words;
	}

	/**
	 * The words of an Other profiles value that are not web addresses, one note per line of the box. At most five, shortened, so the
	 * note stays short.
	 *
	 * @param mixed $v the text of the box (one address per line), JSON, or a list
	 * @return string[]
	 */
	private static function profile_dropped( $v ): array {
		$json = is_string( $v ) ? json_decode( $v, true ) : null;
		$v = is_array( $json ) ? $json : $v;
		$bad = [];
		if ( is_string( $v ) ) {
			foreach ( (array) preg_split( '/\r\n|\r|\n/', $v ) as $line ) {
				$no = array_values( array_filter( self::profile_words( (string) $line ), static function ( $w ) { return '' === self::profile_clean( $w ); } ) );
				if ( $no ) {
					$bad[] = implode( ' ', $no );
				}
			}
		} else {
			foreach ( (array) $v as $r ) {
				$r = is_array( $r ) ? self::pick( $r, [ 'url', 'link', 'href' ] ) : $r;
				$s = is_scalar( $r ) ? trim( (string) $r ) : '';
				if ( '' !== $s && '' === self::profile_clean( $s ) ) {
					$bad[] = $s;
				}
			}
		}
		return array_slice( array_map( static function ( $s ) { return mb_substr( (string) $s, 0, 60 ); }, array_values( array_unique( $bad ) ) ), 0, 5 );
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
		$old = get_option( self::OPT_SRC );
		$match = is_array( $old ) && ( $old['option'] ?? '' ) === $option ? (int) ( $old['match'] ?? 0 ) : 2; // The same source keeps its matching; a new one gets the broader names.
		$src = [ 'option' => $option, 'map' => $clean ];
		if ( $match ) {
			$src['match'] = $match;
		}
		if ( $network ) {
			$src['network'] = true;
		}
		update_option( self::OPT_SRC, $src );
		self::flush();
		return $src;
	}

	/** Match more fields: the saved source uses the broader names from now on. @return bool false when there is no source or it already does */
	public static function broaden_source(): bool {
		$src = get_option( self::OPT_SRC );
		if ( ! is_array( $src ) || empty( $src['option'] ) || ! empty( $src['match'] ) ) {
			return false;
		}
		$src['match'] = 2;
		update_option( self::OPT_SRC, $src );
		self::flush();
		return true;
	}

	/** Changed values must show on cached pages straight away: one coalesced purge for this request (see Cache). */
	private static function flush(): void {
		self::$cache = null;
		self::$resolved = null;
		self::$source = null;
		self::$mb_pages = null;
		self::$mb_overview = null;
		Compat::mb_reset();
		Tokens::reset();
		Cache::purge_later();
	}

	/** updated_option / added_option / update_site_option: Business Info or its source option changed. */
	public static function option_changed( $option ): void {
		if ( Branding::OPT === $option ) { // The logo above the admin menu answers {{lb.logo_inverse}}: only the values kept in memory are stale.
			self::$cache = null;
			self::$resolved = null;
			return;
		}
		// blog_public decides whether blank fields show demo values; the site title, tagline, icon and language are what the name,
		// tagline, site icon and country read through to (and a pinned address or hours line decides what those two print).
		if ( self::OPT === $option || self::OPT_SRC === $option || Company_Tidy::OPT_PINS === $option || in_array( $option, [ 'blog_public', 'blogname', 'blogdescription', 'site_icon', 'WPLANG' ], true ) ) {
			if ( self::OPT_SRC === $option && ! Company_Admin::own_change() ) {
				delete_option( Company_Admin::OPT_UNDO ); // The source changed some other way: the panel's Undo would put back an old state.
			}
			self::flush();
			return;
		}
		$src = get_option( self::OPT_SRC );
		if ( is_array( $src ) && ! empty( $src['option'] ) && $src['option'] === $option ) {
			self::flush();
		} elseif ( Compat::mb_active() && in_array( $option, Compat::mb_token_options(), true ) ) {
			self::flush(); // A settings page that {{meta.*}} tokens read (the source, or one added with the launch_builder_meta_token_pages filter): cached pages show its new values.
		}
	}

	/**
	 * What Business Info can see of Meta Box, for the Meta Box panel and get-company: whether Meta Box and its settings page
	 * extension are there, every settings page with its fields and what each field would fill, and (only when Meta Box is on
	 * but no settings page was found) saved options that look like business facts. A settings page that is not the source
	 * yet is read with automatic name matching; the source page is read with its saved field choices.
	 *
	 * Each page: option, ids, title, network, url, fields (id, name, type, fills[] Business Info keys, value preview, state:
	 * filled | empty | none), filled (Business Info key => value), count, in_use, saved (the option exists), skipped.
	 *
	 * @param bool $with_candidates look for saved options that read like business facts when no settings page is found (a scan of the
	 *                              autoloaded options: only the Business Info screen asks for it)
	 * @return array{active:bool,version:string,extension:bool,filter:bool,registered:int,pages:array,candidates:array,source:array}
	 */
	public static function meta_box_overview( bool $with_candidates = false ): array {
		if ( null !== self::$mb_overview && ( ! $with_candidates || self::$mb_overview['scanned'] ) ) {
			return self::$mb_overview;
		}
		$src = (array) get_option( self::OPT_SRC, [] );
		$out = [
			'active'     => Compat::mb_active(),
			'version'    => defined( 'RWMB_VER' ) ? (string) RWMB_VER : '',
			'extension'  => class_exists( 'MBSP\Loader', false ),
			'filter'     => (bool) has_filter( 'mb_settings_pages' ),
			'registered' => 0,
			'pages'      => [],
			'candidates' => [],
			'scanned'    => false,
			'source'     => $src,
		];
		foreach ( self::meta_box_pages() as $page ) {
			$is_src = ( $src['option'] ?? '' ) === $page['option'];
			$net = $page['network'] && is_multisite();
			$fields = Compat::mb_setting_fields( $page['option'], $net );
			$read = self::read_option( $page['option'], $net, $is_src ? (array) ( $src['map'] ?? [] ) : [], true, ! $is_src || ! empty( $src['match'] ) );
			$more = [];
			if ( $is_src && empty( $src['match'] ) ) { // A source saved before the broader names: which fields they would add.
				$wide = self::read_option( $page['option'], $net, (array) ( $src['map'] ?? [] ), true, true );
				$more = array_values( array_diff( array_keys( $wide['values'] ), array_keys( $read['values'] ) ) );
			}
			$fills = [];
			foreach ( $read['matched'] + $read['empty'] as $bi => $key ) {
				$fills[ $key ][] = $bi;
			}
			if ( ! $fields && $read['exists'] ) { // Fields not registered (yet): list the saved keys instead.
				foreach ( $fills as $key => $unused ) {
					$fields[ $key ] = [ 'id' => (string) $key, 'type' => '', 'name' => (string) $key, 'clone' => false ];
				}
			}
			$rows = [];
			foreach ( $fields as $id => $f ) {
				$bis = $fills[ $id ] ?? [];
				$filled = array_values( array_filter( $bis, static function ( $bi ) use ( $read, $id ) { return isset( $read['matched'][ $bi ] ) && (string) $read['matched'][ $bi ] === (string) $id; } ) );
				$preview = '';
				if ( $filled ) {
					$v = $read['values'][ $filled[0] ];
					$preview = is_array( $v ) ? implode( ', ', array_map( static function ( $x ) { return is_scalar( $x ) ? (string) $x : ''; }, array_slice( $v, 0, 3 ) ) ) : (string) $v;
				}
				$rows[] = [
					'id'      => (string) $id,
					'name'    => $f['name'],
					'type'    => $f['type'],
					'fills'   => $bis,
					'value'   => '' !== $preview ? mb_substr( wp_strip_all_tags( $preview ), 0, 80 ) : '',
					'state'   => $filled ? 'filled' : ( $bis ? 'empty' : 'none' ),
				];
			}
			$out['registered'] += count( $fields );
			$out['pages'][] = [
				'option'  => $page['option'],
				'ids'     => $page['ids'],
				'title'   => $page['title'],
				'network' => $net,
				'url'     => self::meta_box_page_url( $page ),
				'fields'  => $rows,
				'filled'  => $read['values'],
				'count'   => count( $read['values'] ),
				'in_use'  => $is_src,
				'saved'   => $read['exists'],
				'skipped' => $read['skipped'],
				'more'    => $more,
			];
		}
		if ( $with_candidates && $out['active'] && ! $out['pages'] ) {
			$out['candidates'] = self::candidate_options();
			$out['scanned'] = true;
		} elseif ( $with_candidates ) {
			$out['scanned'] = true;
		}
		return self::$mb_overview = $out;
	}

	/**
	 * Saved options (autoloaded ones, the kind a settings page leaves behind) whose keys look like business facts: at least four
	 * Business Info fields match by name, one of them the name, phone or email. Never this plugin's own options.
	 *
	 * @return array<int,array{option:string,count:int,fills:string[]}> best first, at most five
	 */
	public static function candidate_options(): array {
		$cached = get_transient( 'launch_builder_mb_candidates' ); // The scan unserializes every autoloaded array option: once in a while is plenty.
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found = [];
		foreach ( (array) wp_load_alloptions() as $name => $raw ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, 'launch_builder_' ) || ! is_string( $raw ) || 'a:' !== substr( $raw, 0, 2 ) || strlen( $raw ) > 200000 || ! Abilities::option_readable( $name ) ) {
				continue;
			}
			$val = @unserialize( $raw, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- options WordPress itself unserializes; no objects.
			if ( ! is_array( $val ) ) {
				continue;
			}
			$hit = [];
			foreach ( self::candidates( array_keys( $val ) ) as $bi => $keys ) {
				if ( $keys && self::filled( $val[ $keys[0] ] ) ) {
					$hit[] = $bi;
				}
			}
			if ( count( $hit ) >= 4 && array_intersect( $hit, [ 'name', 'phone', 'email' ] ) ) {
				$found[] = [ 'option' => $name, 'count' => count( $hit ), 'fills' => $hit ];
			}
		}
		usort( $found, static function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$found = array_slice( $found, 0, 5 );
		set_transient( 'launch_builder_mb_candidates', $found, 5 * MINUTE_IN_SECONDS );
		return $found;
	}

	/** The Meta Box facts get-company reports: is it there, which settings pages, what each fills, which one is the source. */
	private static function meta_box_summary(): array {
		$o = self::meta_box_overview();
		if ( ! current_user_can( 'manage_options' ) ) { // Option names and what they hold are for administrators; an editor only learns that Meta Box is there.
			return [ 'active' => $o['active'], 'version' => $o['version'], 'settings_page_extension' => $o['extension'], 'pages' => [], 'candidates' => [] ];
		}
		$pages = [];
		foreach ( $o['pages'] as $p ) {
			$pages[] = [ 'option' => $p['option'], 'title' => $p['title'], 'in_use' => $p['in_use'], 'fields' => count( $p['fields'] ), 'fills' => array_keys( $p['filled'] ), 'saved' => $p['saved'] ];
		}
		return [ 'active' => $o['active'], 'version' => $o['version'], 'settings_page_extension' => $o['extension'], 'pages' => $pages, 'candidates' => $o['candidates'] ];
	}

	/** A short name for the saved source, for the "From Meta Box" notes: the settings page title, else the option name; '' without a source. */
	public static function source_label(): string {
		$read = self::source();
		if ( '' === $read['option'] ) {
			return '';
		}
		foreach ( self::meta_box_pages() as $page ) {
			if ( $page['option'] === $read['option'] ) {
				return $page['title'];
			}
		}
		return $read['option'];
	}

	/** Where the source's values are edited: the Meta Box settings page, else ''. */
	public static function source_url(): string {
		$read = self::source();
		foreach ( self::meta_box_pages() as $page ) {
			if ( $page['option'] === $read['option'] ) {
				return self::meta_box_page_url( $page );
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ tools */

	public static function define_tools(): void {
		Abilities::add( 'get-company', 'Read the global business info', 'Business facts used by every pattern through tokens ({{lb.phone}} in text, #lb-cta_url in link fields): name, phone, email, structured address and opening hours, review platforms, button text and links, promotions, form embed, social links. Shows the saved value, the value in effect and where it comes from (source, saved, site = read from WordPress, default, derived, computed, expired); source_skipped lists source-option values that could not be used. Without a value saved here, name is the site title, tagline is the WordPress tagline, country is a guess from the site language (Rank Math and structured data get only a country that was set) and site_icon is the WordPress site icon; other_profiles takes one web address per line (an address can hold commas). Patterns show a visible demo value for contact fields until set; ratings, counts and social links never have one. Each field carries its screen group, advanced (rarely typed by hand; the token still works), on_screen (false = no input on the Business Info screen) and rank_math (the Rank Math settings it feeds; fill-rank-math previews and fills them).',
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

	/** Link tokens whose link (with its text) is left out of a page while they have no value: the social profiles and the emergency phone. */
	public static function hides_when_empty( string $key ): bool {
		return 'emergency_phone_link' === $key || in_array( $key, self::SOCIALS, true );
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
			'meta_box'       => self::meta_box_summary(),
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
