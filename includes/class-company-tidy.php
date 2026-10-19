<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The one-time tidy of Business Info (migration step company-tidy). Business Info used to hold copies of facts WordPress
 * already keeps (site title, tagline, site icon), a second spelling of the address and the hours, eight single-network
 * profile links, and the logo for dark backgrounds that only the admin menu uses. This step moves each one to where it
 * belongs, and never loses or silently replaces a value:
 *
 *  - A snapshot of everything it can touch is taken first (undo is the restore-snapshot tool).
 *  - Name equal to the site title is cleared (the name then follows the title); a different name stays as the override.
 *  - Tagline equal to the WordPress tagline is cleared; a WordPress tagline that is empty or still the default takes the
 *    Business Info one; a real WordPress tagline that differs is left alone and Business Info shows ONE notice asking which
 *    to keep (Company_Tidy::tagline_conflict, answered by Company_Admin).
 *  - Site icon: set on WordPress when WordPress has none. When WordPress already has a different one, the Business Info value
 *    stays where it is (hidden, still working) and the difference is reported.
 *  - Logo for dark backgrounds becomes the Branding image for the logo above the admin menu, and is cleared here only once
 *    Branding really holds it. A different menu logo image already in Branding leaves the Business Info value in place.
 *  - A one-line address or hours text that reads cleanly fills the address boxes or opening-hour rows (only when those are
 *    empty). When the boxes and rows would word it differently, the line stays and keeps printing as typed (a pin, see
 *    Company::resolve()) until someone changes a box; anything that does not split stays honored and the screen asks for the boxes.
 *  - The eight single-network profile links join Other profiles; each saved link is cleared only when its token would still
 *    print the same address.
 *  - Values only search engines read (business type, price range, coordinates, description, emergency phone) are first put
 *    into the Rank Math settings they feed with a narrow fill, which never replaces what someone typed there and leaves every
 *    other Rank Math setting alone. A sync that is already running in this request (sync-site runs the migrations inside its own
 *    lock) is fine. When ANOTHER request holds the lock the step still finishes (a step that waits would hold up every later
 *    migration) and the fill is kept as a pending job that a later administrator page load runs.
 * Whatever it moved is listed once on the Business Info screen until the form is saved (note()).
 * The fields themselves are not deleted: they sit in the Off screen group and keep working in tokens, briefs and tools.
 */
final class Company_Tidy {

	const STEP = 'company-tidy';

	/** Option: the address and hours lines kept as typed (field => the text the boxes and rows build), see Company::resolve(). */
	const OPT_PINS = 'launch_builder_company_pins';

	/** Option: what the tidy moved or left, shown once on the Business Info screen until the form is saved. */
	const OPT_NOTE = 'launch_builder_company_tidy_note';

	/** Option: the Rank Math settings (titles keys) whose search-only fill could not run yet because another sync held the lock. */
	const OPT_SEO_PENDING = 'launch_builder_company_tidy_seo';

	/** Fields only search engines read; Rank Math should hold them before their inputs are gone. */
	const SEARCH_ONLY = [ 'business_type', 'price_range', 'lat', 'lng', 'about', 'emergency_phone' ];

	/** The Rank Math settings (titles keys) each search-only field feeds. */
	const SEARCH_KEYS = [
		'business_type'   => [ 'local_business_type' ],
		'price_range'     => [ 'price_range' ],
		'lat'             => [ 'geo' ],
		'lng'             => [ 'geo' ],
		'about'           => [ 'organization_description' ],
		'emergency_phone' => [ 'phone_numbers' ],
	];

	/** US state, district and territory codes and Canadian province codes: a "street, city, XX" line without a ZIP needs one of them. */
	const REGION_CODES = [ 'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR', 'GU', 'VI', 'AS', 'MP', 'AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT' ];

	public static function boot(): void {
		add_action( 'admin_post_lb_company_tagline', [ __CLASS__, 'tagline_action' ] );
		add_action( 'admin_init', [ __CLASS__, 'retry_search_fill' ], 30 ); // After the migrations (priority 10): a fill that had to wait runs on a later page load.
		Migrate::register_step( self::STEP, [ __CLASS__, 'run' ], [ 'after' => [ 'snapshots-v2' ] ] );
	}

	/* ------------------------------------------------------------------ the step */

	/** Migrate step. Safe to repeat: each part does nothing once it has been done. */
	public static function run( $cursor, int $batch ): array {
		unset( $cursor, $batch );
		$raw = get_option( Company::OPT, [] );
		$own = is_array( $raw ) ? $raw : [];
		if ( ! $own ) {
			return [ 'done' => true, 'report' => 'nothing saved in Business Info' ];
		}
		$new = $own;
		$set = []; // WordPress options to write: option => value.
		$back = []; // WordPress option => [ Business Info key, moved label ]: put the Business Info value back when the write does not stick.
		$menu_logo = 0;
		$pins = [];
		$moved = []; // Plain name => what happened.
		$kept = []; // Everything left as it was (the report).
		$seen = []; // The part of it the Business Info screen mentions, in plain words.

		// Site icon.
		if ( self::filled( $new, 'site_icon' ) ) {
			$id = self::attachment( (string) $new['site_icon'] );
			$core = (int) get_option( 'site_icon' );
			if ( $id && ( $core < 1 || ! wp_attachment_is_image( $core ) ) ) {
				$set['site_icon'] = $id;
				$back['site_icon'] = [ 'site_icon', 'site icon' ];
				$moved['site icon'] = 'site icon (now the WordPress site icon)';
				unset( $new['site_icon'] );
			} elseif ( $id && $core === $id ) {
				unset( $new['site_icon'] );
			} elseif ( $id ) { // WordPress has a different one: the Business Info value stays (hidden, still working), nothing is dropped.
				$kept[] = $seen[] = 'Site icon: WordPress has a different one, so Business Info kept its own copy (hidden, and it still works).';
			}
		}

		// Logo for dark backgrounds: the logo above the admin menu.
		if ( self::filled( $new, 'logo_inverse' ) ) {
			$id = self::attachment( (string) $new['logo_inverse'] );
			if ( $id ) {
				$have = (int) ( Branding::stored()['admin']['menu_logo_image'] ?? 0 );
				if ( $have < 1 || ! wp_attachment_is_image( $have ) ) {
					$menu_logo = $id;
					$moved['logo for the admin menu'] = 'logo for dark backgrounds (now the Branding logo above the admin menu)';
					unset( $new['logo_inverse'] ); // Put back below when Branding does not take it.
				} elseif ( $have === $id ) {
					unset( $new['logo_inverse'] );
				} else { // Branding has a different menu logo image: the Business Info value stays (hidden, still working).
					$kept[] = $seen[] = 'Logo for dark backgrounds: the admin menu already has a different logo, so Business Info kept its own copy (hidden, and it still works).';
				}
			}
		}

		// Business name: follows the site title unless it differs.
		$title = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		if ( self::filled( $new, 'name' ) && Company::same_text( (string) $new['name'], $title ) ) {
			unset( $new['name'] );
			$moved['business name'] = 'business name (it now follows the site title)';
		}

		// Tagline: equal clears, a default WordPress tagline takes it, a different one waits for the person's choice.
		if ( self::filled( $new, 'tagline' ) ) {
			$bi = trim( (string) $new['tagline'] );
			$wp = wp_specialchars_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES );
			if ( Company::same_text( $bi, $wp ) ) {
				unset( $new['tagline'] );
			} elseif ( null === Company::real_text( $wp ) && false === strpos( $bi, '{{' ) ) {
				$set['blogdescription'] = $bi;
				$back['blogdescription'] = [ 'tagline', 'tagline' ];
				$moved['tagline'] = 'tagline (now the WordPress tagline)';
				unset( $new['tagline'] );
			} else {
				$kept[] = 'tagline: it differs from the WordPress tagline, so Business Info asks which to keep';
			}
		}

		// The address on one line becomes the address boxes when they are empty and the line reads cleanly.
		$parts_empty = ! self::filled( $new, 'street' ) && ! self::filled( $new, 'locality' ) && ! self::filled( $new, 'region' ) && ! self::filled( $new, 'postal_code' );
		if ( $parts_empty && self::filled( $new, 'address' ) ) {
			$p = self::parse_address( (string) $new['address'] );
			if ( $p ) {
				foreach ( $p as $k => $v ) {
					if ( '' !== $v ) {
						$new[ $k ] = $v;
					}
				}
				$built = implode( ', ', array_filter( [ $p['street'], $p['locality'], trim( $p['region'] . ' ' . $p['postal_code'] ) ], 'strlen' ) );
				if ( self::same_line( $built, (string) $new['address'] ) ) {
					unset( $new['address'] );
				} else {
					$pins['address'] = $built; // The boxes would word it differently: the line keeps printing as it was typed.
				}
				$moved['address'] = 'address (now in the address boxes)';
			} else {
				$kept[] = 'address: the one-line address could not be split, so it stays and Business Info asks for the boxes';
			}
		}

		// The hours on one line become opening-hour rows.
		$rows_empty = empty( $new['hours_spec'] ) && ! self::filled( $new, 'is_24_7' );
		if ( $rows_empty && self::filled( $new, 'hours' ) ) {
			$h = self::parse_hours( (string) $new['hours'] );
			if ( $h && ! empty( $h['always'] ) ) {
				$new['is_24_7'] = '1';
				$built = __( 'Open 24/7', 'launch-builder' );
				if ( self::same_line( $built, (string) $new['hours'] ) ) {
					unset( $new['hours'] );
				} else {
					$pins['hours'] = $built;
				}
				$moved['hours'] = 'hours (open 24/7)';
			} elseif ( $h ) {
				$new['hours_spec'] = $h;
				$built = Company::hours_text( $h );
				if ( self::same_line( $built, (string) $new['hours'] ) ) {
					unset( $new['hours'] );
				} else {
					$pins['hours'] = $built;
				}
				$moved['hours'] = 'hours (now opening-hour rows)';
			} else {
				$kept[] = 'hours: the one-line hours could not be split, so they stay';
			}
		}

		// The eight single-network links join Other profiles.
		$list = Company::normalize_list( 'other_profiles', $new['other_profiles'] ?? [] );
		$before = $list;
		$mine = [];
		foreach ( Company::PROFILE_HOSTS as $k => $hosts ) {
			if ( ! self::filled( $new, $k ) || ctype_digit( trim( (string) $new[ $k ] ) ) ) {
				continue;
			}
			$u = Company::normalize_list( 'other_profiles', [ (string) $new[ $k ] ] );
			if ( ! $u ) {
				continue;
			}
			if ( self::bare( $u[0] ) !== self::bare( (string) $new[ $k ] ) ) { // Cleaning would change more than the https:// part: leave this one as it is.
				$kept[] = $seen[] = ucfirst( $k ) . ' link: it was not moved because it needs a closer look. It is still saved and still works.';
				continue;
			}
			$mine[ $k ] = $u[0];
			if ( ! in_array( $u[0], $list, true ) && count( $list ) < 20 ) {
				$list[] = $u[0];
			}
		}
		foreach ( $mine as $k => $u ) {
			if ( in_array( $u, $list, true ) && Company::profile_url( Company::PROFILE_HOSTS[ $k ], $list ) === $u ) {
				unset( $new[ $k ] ); // Its token now answers from Other profiles with this very address.
			}
		}
		if ( $list !== $before ) {
			$new['other_profiles'] = $list;
			$moved['social links'] = 'profile links (now in Other profiles)';
		}

		$seo_saved = array_values( array_filter( self::SEARCH_ONLY, static function ( $k ) use ( $own ) { return self::filled( $own, $k ); } ) );
		$changed = $new !== $own || $set || $menu_logo || $pins;
		if ( ! $changed && ! $seo_saved && ! $kept ) {
			return [ 'done' => true, 'report' => 'nothing to move' ];
		}

		$report = [];
		if ( $changed ) {
			$report['snapshot'] = Abilities::snapshot( 'company-tidy', [], [ Company::OPT, Company::OPT_SRC, 'site_icon', 'blogname', 'blogdescription', Branding::OPT, self::OPT_PINS, self::OPT_NOTE ] );
			foreach ( $set as $name => $value ) {
				update_option( $name, $value );
				if ( ! self::took( $name, $value ) ) { // The write did not stick: the Business Info value stays and is reported.
					[ $key, $label ] = $back[ $name ];
					$new[ $key ] = $own[ $key ];
					unset( $moved[ $label ] );
					$kept[] = $seen[] = ucfirst( $label ) . ': WordPress did not take it, so Business Info kept its own copy.';
				}
			}
			if ( $menu_logo ) {
				$stored = Branding::store_menu_logo_image( $menu_logo ) && (int) ( Branding::stored()['admin']['menu_logo_image'] ?? 0 ) === $menu_logo;
				if ( ! $stored ) { // Branding did not keep it: the Business Info value stays and is reported.
					$new['logo_inverse'] = $own['logo_inverse'];
					unset( $moved['logo for the admin menu'] );
					$kept[] = $seen[] = 'Logo for dark backgrounds: the admin menu logo could not be saved, so Business Info kept its own copy (hidden, and it still works).';
				}
			}
			if ( $pins ) {
				update_option( self::OPT_PINS, $pins + array_filter( (array) get_option( self::OPT_PINS, [] ), 'is_string' ), true );
			}
			if ( $new !== $own ) {
				update_option( Company::OPT, $new );
			}
			if ( $moved ) {
				$report['moved'] = array_values( $moved );
			}
		}
		if ( $kept ) {
			$report['kept'] = $kept;
		}
		self::remember( array_values( array_diff( array_keys( $moved ), [ 'business name' ] ) ), $seen );

		// Search-only values: Rank Math holds them before their inputs are gone, through a narrow fill of just the settings they feed.
		// The normal fill never replaces a value somebody typed in Rank Math.
		$keys = [];
		foreach ( $seo_saved as $k ) {
			$keys = array_merge( $keys, self::SEARCH_KEYS[ $k ] ?? [] );
		}
		if ( $keys && Compat::rm_active() ) {
			$keys = array_values( array_unique( $keys ) );
			$filled = self::fill_search_only( $keys );
			if ( null === $filled ) { // Another request holds the sync lock: keep the job and let a later page load run it.
				update_option( self::OPT_SEO_PENDING, $keys, true );
				$report['rank_math'] = 'another sync is running; the Rank Math fill runs on a later page load';
			} else {
				delete_option( self::OPT_SEO_PENDING );
				$report['rank_math'] = $filled;
			}
		}
		return [ 'done' => true, 'report' => $report ];
	}

	/**
	 * The narrow Rank Math fill for the search-only settings.
	 *
	 * @param string[] $keys Rank Math titles keys
	 * @return string|null what was done, or null when another request holds the sync lock
	 */
	private static function fill_search_only( array $keys ): ?string {
		$have = Lock::mine( 'sync' ); // sync-site (and the fill tools) run the migrations inside their own lock: do not wait for it.
		if ( ! $have && ! Lock::acquire( 'sync', 120 ) ) {
			return null;
		}
		try {
			$r = Compat::business_sync( [ 'dry_run' => false, 'only' => $keys ] );
			return sprintf( 'filled %d, kept %d', count( (array) ( $r['changes'] ?? [] ) ), count( (array) ( $r['kept'] ?? [] ) ) );
		} finally {
			if ( ! $have ) {
				Lock::release( 'sync' );
			}
		}
	}

	/** admin_init: run a fill that had to wait for the sync lock. Does nothing unless one is waiting. */
	public static function retry_search_fill(): void {
		$keys = get_option( self::OPT_SEO_PENDING );
		if ( ! is_array( $keys ) || ! $keys || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Compat::rm_active() ) {
			return; // Rank Math is off for now; when it is turned on, Business Info fills it by itself.
		}
		if ( null !== self::fill_search_only( array_values( array_filter( array_map( 'strval', $keys ) ) ) ) ) {
			delete_option( self::OPT_SEO_PENDING );
		}
	}

	/* ------------------------------------------------------------------ the note on the Business Info screen */

	/**
	 * What the tidy moved and left, for the one notice on the Business Info screen.
	 *
	 * @return array{moved:string[],kept:string[]}|null
	 */
	public static function note(): ?array {
		$n = get_option( self::OPT_NOTE );
		if ( ! is_array( $n ) || ( empty( $n['moved'] ) && empty( $n['kept'] ) ) ) {
			return null;
		}
		return [ 'moved' => array_values( array_filter( (array) ( $n['moved'] ?? [] ), 'is_string' ) ), 'kept' => array_values( array_filter( (array) ( $n['kept'] ?? [] ), 'is_string' ) ) ];
	}

	/** The notice goes away when the form is saved: the person has looked. */
	public static function forget_note(): void {
		if ( false !== get_option( self::OPT_NOTE, false ) ) {
			delete_option( self::OPT_NOTE );
		}
	}

	/** Keep (or add to) the note. A repeat run adds nothing twice. */
	private static function remember( array $moved, array $kept ): void {
		if ( ! $moved && ! $kept ) {
			return;
		}
		$old = self::note() ?? [ 'moved' => [], 'kept' => [] ];
		$note = [ 'moved' => array_values( array_unique( array_merge( $old['moved'], $moved ) ) ), 'kept' => array_values( array_unique( array_merge( $old['kept'], $kept ) ) ) ];
		if ( $note !== $old ) {
			update_option( self::OPT_NOTE, $note, false );
		}
	}

	/* ------------------------------------------------------------------ small helpers */

	private static function filled( array $a, string $k ): bool {
		if ( ! isset( $a[ $k ] ) ) {
			return false;
		}
		return is_array( $a[ $k ] ) ? [] !== $a[ $k ] : ( is_scalar( $a[ $k ] ) && '' !== trim( (string) $a[ $k ] ) );
	}

	/** Did a WordPress option keep what was written to it? */
	private static function took( string $name, $value ): bool {
		if ( 'site_icon' === $name ) {
			return (int) get_option( 'site_icon' ) === (int) $value;
		}
		return null !== Company::real_text( (string) get_option( $name ) );
	}

	/** The same line, ignoring spaces, entities and a closing dot. */
	private static function same_line( string $a, string $b ): bool {
		return Company::same_text( rtrim( trim( $a ), '.' ), rtrim( trim( $b ), '.' ) );
	}

	/** A web address without its scheme and closing slash, lower case: compares an old link with its cleaned form. */
	private static function bare( string $u ): string {
		return strtolower( rtrim( (string) preg_replace( '#^https?://#i', '', trim( $u ) ), '/' ) );
	}

	/** An image's attachment ID from a saved ID or media address, 0 when it is not one. */
	private static function attachment( string $v ): int {
		$v = trim( $v );
		$id = ctype_digit( $v ) ? (int) $v : (int) attachment_url_to_postid( $v );
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * "123 Main Street, Austin, TX 78701" as boxes, or null when it does not read as street, city, state and ZIP (US style).
	 * A line without a ZIP needs a US state or Canadian province code: "10 Downing St, London, UK" stays a line.
	 *
	 * @return array<string,string>|null street, locality, region, postal_code
	 */
	public static function parse_address( string $line ): ?array {
		$line = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
		if ( 1 === preg_match( '/^(.{3,}?),\s*([^,]{2,}?),\s*([A-Za-z]{2})\.?(?:\s+(\d{5}(?:-\d{4})?))?$/u', $line, $m ) && 1 === preg_match( '/\d/', $m[1] ) ) {
			$zip = isset( $m[4] ) ? $m[4] : '';
			$region = strtoupper( $m[3] );
			if ( '' === $zip && ! in_array( $region, self::REGION_CODES, true ) ) {
				return null;
			}
			return [ 'street' => $m[1], 'locality' => $m[2], 'region' => $region, 'postal_code' => $zip ];
		}
		return null;
	}

	/**
	 * "Mon–Fri 8am–6pm · Sat 9am–2pm" as opening-hour rows, ['always' => true] for "Open 24/7", or null when any part does not read cleanly.
	 *
	 * @return array|null
	 */
	public static function parse_hours( string $line ): ?array {
		$line = trim( $line );
		if ( 1 === preg_match( '#^open\s*24\s*[/x-]\s*7\.?$#i', $line ) ) {
			return [ 'always' => true ];
		}
		$day = '(?:mo|tu|we|th|fr|sa|su)[a-z]{0,6}\.?';
		$time = '(?:\d{1,2}(?::\d{2})?\s*(?:am|pm)|\d{1,2}:\d{2})';
		$rows = [];
		foreach ( (array) preg_split( '/\s*(?:·|\||;|\r\n|\r|\n)\s*/u', $line ) as $seg ) {
			$seg = trim( (string) $seg );
			if ( '' === $seg ) {
				continue;
			}
			$re = '/^(' . $day . '(?:\s*[-–]\s*' . $day . ')?(?:\s*,\s*' . $day . '(?:\s*[-–]\s*' . $day . ')?)*)\s*:?\s+(' . $time . ')\s*(?:[-–]|to)\s*(' . $time . ')$/iu';
			if ( 1 !== preg_match( $re, $seg, $m ) ) {
				return null;
			}
			$days = Company::days( $m[1] );
			$opens = Company::time( $m[2] );
			$closes = Company::time( $m[3] );
			if ( ! $days || '' === $opens || '' === $closes ) {
				return null;
			}
			$rows[] = [ 'days' => $days, 'opens' => $opens, 'closes' => $closes ];
		}
		return $rows && count( $rows ) <= 14 ? $rows : null;
	}

	/* ------------------------------------------------------------------ the tagline question */

	/**
	 * The one thing the tidy cannot decide: Business Info has a tagline of its own that differs from the WordPress tagline.
	 *
	 * @return array{business:string,settings:string}|null
	 */
	public static function tagline_conflict(): ?array {
		$own = get_option( Company::OPT, [] );
		$bi = is_array( $own ) && isset( $own['tagline'] ) && is_scalar( $own['tagline'] ) ? trim( (string) $own['tagline'] ) : '';
		if ( '' === $bi || false !== strpos( $bi, '{{' ) ) {
			return null;
		}
		$wp = Company::real_text( (string) get_option( 'blogdescription' ) );
		return null !== $wp && ! Company::same_text( $bi, $wp ) ? [ 'business' => $bi, 'settings' => $wp ] : null;
	}

	/** admin-post lb_company_tagline: keep the WordPress tagline or the Business Info one. Either way one copy is left. */
	public static function tagline_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'lb_company_tagline', 'lb_tagline_nonce' );
		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		$c = self::tagline_conflict();
		if ( $c && in_array( $choice, [ 'settings', 'business' ], true ) ) {
			Abilities::snapshot( 'company-tagline', [], [ Company::OPT, 'blogdescription' ] );
			if ( 'business' === $choice ) {
				update_option( 'blogdescription', $c['business'] );
			}
			$own = (array) get_option( Company::OPT, [] );
			unset( $own['tagline'] );
			update_option( Company::OPT, $own );
			Cache::flush();
		}
		wp_safe_redirect( add_query_arg( [ 'page' => Company_Admin::PAGE, 'saved' => $c && $choice ? 'tagline' : '' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
