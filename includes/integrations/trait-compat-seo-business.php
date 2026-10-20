<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: Business Info in Rank Math: the read-time overlay of the knowledge graph and Local SEO keys, and fill-rank-math (preview,
 * fill, undo).
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Seo_Business {

	/* ------------------------------------------------------------------ Business Info overlay */

	/** Real (non-demo) Business Info value. */
	private static function biz( array $resolved, string $key ): string {
		if ( 'country' === $key && isset( $resolved[ $key ] ) && 'site' === $resolved[ $key ]['from'] ) {
			return ''; // A country guessed from the site language is a hint for the screen: a business can sit in another country than its site language.
		}
		return isset( $resolved[ $key ] ) && 'default' !== $resolved[ $key ]['from'] ? trim( (string) $resolved[ $key ]['value'] ) : '';
	}

	/**
	 * A Business Info list field (hours_spec, areas_served...) as Business Info reads it: the saved rows, or the rows from
	 * another settings page when one is set as the source, so those reach Rank Math and the schema too.
	 */
	public static function biz_list( string $key ): array {
		return Company::list_value( $key );
	}

	/**
	 * Rank Math titles keys built from Business Info (only keys with a real value). Valid only after init (logo, contact
	 * page and the business type list need WordPress loaded); before that it is empty.
	 */
	public static function identity(): array {
		if ( null !== self::$identity ) {
			return self::$identity;
		}
		if ( ! did_action( 'init' ) ) {
			return [];
		}
		$r = Company::resolve();
		$v = static function ( string $k ) use ( $r ): string { return self::biz( $r, $k ); };
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
		if ( '' !== $v( 'about' ) ) {
			$out['organization_description'] = $v( 'about' );
		}
		if ( is_email( $v( 'email' ) ) ) {
			$out['email'] = $v( 'email' );
		}
		$phone = self::schema_phone( $v( 'phone' ) );
		if ( '' !== $phone ) {
			$out['phone'] = $phone;
			$out['phone_numbers'] = [ [ 'type' => 'customer support', 'number' => $phone ] ];
			$em = self::schema_phone( $v( 'emergency_phone' ) );
			if ( '' !== $em && $em !== $phone ) {
				$out['phone_numbers'][] = [ 'type' => 'emergency', 'number' => $em ];
			}
		}
		$socials = [];
		foreach ( [ 'facebook', 'instagram', 'youtube', 'google', 'linkedin', 'x', 'yelp', 'tiktok', 'nextdoor', 'bbb', 'angi', 'houzz' ] as $s ) {
			$u = $v( $s );
			if ( preg_match( '#^https?://[^\s]+\.[^\s]+#i', $u ) ) {
				$socials[ $s ] = esc_url_raw( $u );
			}
		}
		if ( isset( $socials['facebook'] ) ) {
			$out['social_url_facebook'] = $socials['facebook'];
			unset( $socials['facebook'] );
		}
		$more = array_values( $socials );
		foreach ( Company::list_value( 'other_profiles' ) as $u ) { // Other profiles: the addresses typed in the one box (a network that has its own token is already in the list above).
			if ( preg_match( '#^https?://[^\s]+\.[^\s]+#i', (string) $u ) && ! in_array( (string) $u, $more, true ) ) {
				$more[] = esc_url_raw( (string) $u );
			}
		}
		if ( $more ) {
			$out['social_additional_profiles'] = implode( "\n", $more );
		}
		$type = self::business_type( $v( 'business_type' ) );
		if ( '' !== $type ) {
			$out['local_business_type'] = $type;
		}
		$addr = array_filter( [ 'streetAddress' => $v( 'street' ), 'addressLocality' => $v( 'locality' ), 'addressRegion' => $v( 'region' ), 'postalCode' => $v( 'postal_code' ), 'addressCountry' => $v( 'country' ) ], 'strlen' );
		if ( isset( $addr['streetAddress'] ) || isset( $addr['addressLocality'] ) ) {
			$out['local_address'] = $addr;
		}
		$hours = self::opening_hours( self::biz_list( 'hours_spec' ), in_array( strtolower( $v( 'is_24_7' ) ), [ '1', 'true', 'yes', 'on' ], true ) );
		if ( $hours ) {
			$out['opening_hours'] = $hours;
		}
		if ( preg_match( '/^\${1,4}$/', $v( 'price_range' ) ) ) {
			$out['price_range'] = $v( 'price_range' );
		}
		if ( is_numeric( $v( 'lat' ) ) && is_numeric( $v( 'lng' ) ) ) {
			$out['geo'] = (float) $v( 'lat' ) . ',' . (float) $v( 'lng' );
		}
		$contact = '' !== $v( 'quote_url' ) ? url_to_postid( $v( 'quote_url' ) ) : 0;
		if ( $contact && 'page' === get_post_type( $contact ) ) {
			$out['local_seo_contact_page'] = $contact;
		}
		return self::$identity = $out;
	}

	/** Forget the per-request overlay values (after a Business Info change in the same request). */
	public static function flush_identity(): void {
		self::$identity = null;
	}

	/** Phone in Rank Math's international format (+1-512-555-0123) from a displayed number; '' when it has no digits. */
	private static function schema_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
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

	/** Rank Math business type key ("Plumber", "HVACBusiness") for a Business Info value, or '' when Rank Math does not know it. */
	private static function business_type( string $t ): string {
		static $types = null;
		if ( '' === $t || ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'choices_business_types' ) ) {
			return '';
		}
		if ( null === $types ) {
			$types = array_keys( (array) \RankMath\Helper::choices_business_types() );
		}
		$want = strtolower( (string) preg_replace( '/[^A-Za-z]/', '', $t ) );
		foreach ( $types as $k ) {
			if ( strtolower( (string) $k ) === $want ) {
				return (string) $k;
			}
		}
		return '';
	}

	/**
	 * Rank Math opening_hours rows [{day: 'Monday', time: '08:00-18:00'}] from Business Info hours_spec
	 * [{days: ['Mo','Tu'] | 'Mo-Fr', opens: '08:00', closes: '18:00'}]; open 24/7 gives every day 00:00-23:59.
	 */
	public static function opening_hours( array $spec, bool $always = false ): array {
		$days = [ 'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday' ];
		if ( $always ) {
			return array_map( static function ( $d ) { return [ 'day' => $d, 'time' => '00:00-23:59' ]; }, array_values( $days ) );
		}
		$order = array_keys( $days );
		$out = [];
		foreach ( $spec as $row ) {
			$row = (array) $row;
			$open = (string) ( $row['opens'] ?? '' );
			$close = (string) ( $row['closes'] ?? '' );
			if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $open ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $close ) ) {
				continue;
			}
			$list = $row['days'] ?? [];
			if ( is_string( $list ) ) {
				$list = preg_split( '/\s*,\s*/', $list );
			}
			$expanded = [];
			foreach ( (array) $list as $d ) {
				$parts = array_map( static function ( $x ) { return substr( strtolower( trim( (string) $x ) ), 0, 2 ); }, explode( '-', (string) $d ) );
				if ( 2 === count( $parts ) && false !== array_search( $parts[0], $order, true ) && false !== array_search( $parts[1], $order, true ) ) {
					$a = array_search( $parts[0], $order, true );
					$b = array_search( $parts[1], $order, true );
					for ( $i = $a; $i <= $b; $i++ ) {
						$expanded[] = $order[ $i ];
					}
				} elseif ( isset( $days[ $parts[0] ] ) ) {
					$expanded[] = $parts[0];
				}
			}
			foreach ( array_unique( $expanded ) as $d ) {
				$out[] = [ 'day' => $days[ $d ], 'time' => sprintf( '%05s-%05s', $open, $close ) ];
			}
		}
		return $out;
	}

	/**
	 * Filter option_rank-math-options-titles: Business Info values over the keys Launch Builder owns (not in wp-admin,
	 * where Rank Math's settings screen must show and save what is stored; never while Launch Builder reads raw).
	 */
	public static function overlay_titles( $value ) {
		if ( self::$raw || ! is_array( $value ) || is_admin() || ! did_action( 'init' ) ) {
			return $value;
		}
		$owned = get_option( self::OPT_OWNED, [] );
		if ( ! is_array( $owned ) || ! $owned ) {
			return $value;
		}
		$id = self::identity();
		foreach ( $owned as $k ) {
			if ( array_key_exists( $k, $id ) ) {
				$value[ $k ] = $id[ $k ];
			}
		}
		return $value;
	}

	/** init p1: Rank Math may have cached its settings before init (without the overlay): read them again. */
	public static function refresh_rm_settings(): void {
		if ( is_admin() || ! self::rm_active() || ! get_option( self::OPT_OWNED, [] ) ) {
			return;
		}
		if ( isset( rank_math()->settings ) && method_exists( rank_math()->settings, 'reset' ) ) {
			rank_math()->settings->reset();
		}
	}

	/**
	 * A titles save by anyone but Launch Builder: an owned key whose value changed to something other than the Business
	 * Info value was edited by an admin, so it stops being overlaid (the ledger reports it as a kept edit).
	 */
	public static function titles_saved( $old, $new ): void {
		if ( self::$writing || ! is_array( $new ) ) {
			return;
		}
		$owned = get_option( self::OPT_OWNED, [] );
		if ( ! is_array( $owned ) || ! $owned ) {
			return;
		}
		$old = is_array( $old ) ? $old : [];
		$id = self::identity();
		$keep = [];
		foreach ( $owned as $k ) {
			$now = $new[ $k ] ?? null;
			if ( self::same( $old[ $k ] ?? null, $now ) || ( array_key_exists( $k, $id ) && self::same( $id[ $k ], $now ) ) ) {
				$keep[] = $k;
			}
		}
		if ( $keep !== $owned ) {
			update_option( self::OPT_OWNED, $keep, true );
		}
	}

	/* ------------------------------------------------------------------ Business Info -> Rank Math (preview, fill, undo) */

	/**
	 * What Business Info fills in Rank Math, one entry per setting a person sees in Rank Math's Local SEO tab: label, the
	 * Rank Math titles keys behind it, the Business Info fields it comes from (or a plain-words source).
	 *
	 * @return array<string,array{label:string,keys:string[],from:string[],from_text:string}>
	 */
	public static function business_map(): array {
		return [
			'type'     => [ 'label' => __( 'Site type', 'launch-builder' ), 'keys' => [ 'knowledgegraph_type' ], 'from' => [], 'from_text' => __( 'A business, not a person', 'launch-builder' ) ],
			'name'     => [ 'label' => __( 'Business name', 'launch-builder' ), 'keys' => [ 'knowledgegraph_name' ], 'from' => [ 'name' ], 'from_text' => '' ],
			'url'      => [ 'label' => __( 'Website address', 'launch-builder' ), 'keys' => [ 'url' ], 'from' => [], 'from_text' => __( 'This site\'s address', 'launch-builder' ) ],
			'logo'     => [ 'label' => __( 'Logo', 'launch-builder' ), 'keys' => [ 'knowledgegraph_logo', 'knowledgegraph_logo_id' ], 'from' => [], 'from_text' => __( 'The site logo (Appearance, Customize)', 'launch-builder' ) ],
			'about'    => [ 'label' => __( 'Description', 'launch-builder' ), 'keys' => [ 'organization_description' ], 'from' => [ 'about' ], 'from_text' => '' ],
			'email'    => [ 'label' => __( 'Email', 'launch-builder' ), 'keys' => [ 'email' ], 'from' => [ 'email' ], 'from_text' => '' ],
			'phones'   => [ 'label' => __( 'Phone numbers', 'launch-builder' ), 'keys' => [ 'phone_numbers', 'phone' ], 'from' => [ 'phone', 'emergency_phone' ], 'from_text' => '' ],
			'facebook' => [ 'label' => __( 'Facebook page', 'launch-builder' ), 'keys' => [ 'social_url_facebook' ], 'from' => [ 'facebook' ], 'from_text' => '' ],
			'profiles' => [ 'label' => __( 'Other profiles', 'launch-builder' ), 'keys' => [ 'social_additional_profiles' ], 'from' => [ 'instagram', 'youtube', 'google', 'other_profiles', 'linkedin', 'x', 'yelp', 'tiktok', 'nextdoor', 'bbb', 'angi', 'houzz' ], 'from_text' => '' ],
			'btype'    => [ 'label' => __( 'Business type', 'launch-builder' ), 'keys' => [ 'local_business_type' ], 'from' => [ 'business_type' ], 'from_text' => '' ],
			'address'  => [ 'label' => __( 'Address', 'launch-builder' ), 'keys' => [ 'local_address' ], 'from' => [ 'street', 'locality', 'region', 'postal_code', 'country' ], 'from_text' => '' ],
			'hours'    => [ 'label' => __( 'Opening hours', 'launch-builder' ), 'keys' => [ 'opening_hours' ], 'from' => [ 'hours_spec', 'is_24_7' ], 'from_text' => '' ],
			'price'    => [ 'label' => __( 'Price range', 'launch-builder' ), 'keys' => [ 'price_range' ], 'from' => [ 'price_range' ], 'from_text' => '' ],
			'geo'      => [ 'label' => __( 'Map coordinates', 'launch-builder' ), 'keys' => [ 'geo' ], 'from' => [ 'lat', 'lng' ], 'from_text' => '' ],
			'contact'  => [ 'label' => __( 'Contact page', 'launch-builder' ), 'keys' => [ 'local_seo_contact_page' ], 'from' => [ 'quote_url' ], 'from_text' => '' ],
		];
	}

	/** Which Rank Math settings each Business Info field feeds: field key => list of setting names. */
	public static function business_feeds(): array {
		$out = [];
		foreach ( self::business_map() as $m ) {
			foreach ( $m['from'] as $k ) {
				$out[ $k ][] = (string) $m['label'];
			}
		}
		return $out;
	}

	/** What Rank Math cannot take from Business Info (or Launch Builder does not send), in plain words. */
	public static function business_cannot(): array {
		return [
			__( 'Holiday hours: Rank Math only has opening hours for a normal week.', 'launch-builder' ),
			__( 'A second office address: Rank Math\'s free version has one location (Rank Math Pro adds more).', 'launch-builder' ),
			__( 'Star ratings and review counts: Launch Builder never adds them to your business schema, because search engines want ratings that visitors can see on the page.', 'launch-builder' ),
			__( 'Areas served: Rank Math has no setting for it, so Launch Builder adds it to your schema itself.', 'launch-builder' ),
			__( 'License numbers and years in business: Rank Math has no matching setting. Its Additional Info list (legal name, founding date, tax ID, number of employees) stays yours too, because Business Info has no field that means the same.', 'launch-builder' ),
			__( 'Your X (Twitter) username for share cards: the profile link goes in as a profile, but the username box stays yours.', 'launch-builder' ),
			__( 'The Google Maps key, the About page, the website name and Rank Math\'s own display options.', 'launch-builder' ),
		];
	}

	/** Rank Math's business type key for Business Info's value ('Plumber'), or '' when Rank Math has no such type. */
	public static function rm_business_type( string $t ): string {
		return self::business_type( $t );
	}

	/** Rank Math's business types as key => label (empty without Rank Math). */
	public static function rm_business_types(): array {
		if ( ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'choices_business_types' ) ) {
			return [];
		}
		return array_map( 'strval', (array) \RankMath\Helper::choices_business_types() );
	}

	/** A short human text for a Rank Math setting value (the preview table). */
	private static function business_show( string $key, $v ): string {
		if ( null === $v || '' === $v || [] === $v ) {
			return '';
		}
		switch ( $key ) {
			case 'local_address':
				return is_array( $v ) ? implode( ', ', array_filter( array_map( 'strval', array_values( $v ) ), 'strlen' ) ) : (string) $v;
			case 'opening_hours':
				return self::show_hours( $v );
			case 'phone_numbers':
				$out = [];
				foreach ( (array) $v as $r ) {
					$r = (array) $r;
					$n = (string) ( $r['number'] ?? '' );
					if ( '' !== $n ) {
						$out[] = $n . ( ! empty( $r['type'] ) ? ' (' . $r['type'] . ')' : '' );
					}
				}
				return implode( ', ', $out );
			case 'social_additional_profiles':
				return implode( ', ', array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', (string) $v ) ) ) );
			case 'knowledgegraph_logo':
				return wp_basename( (string) $v );
			case 'knowledgegraph_logo_id':
				return '';
			case 'local_seo_contact_page':
				$title = (int) $v ? get_the_title( (int) $v ) : '';
				return '' !== $title ? $title . ' (#' . (int) $v . ')' : (string) $v;
		}
		return is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v );
	}

	/** Opening hours rows as text: "Mon–Fri 08:00-18:00; Sat 09:00-14:00". */
	private static function show_hours( $rows ): string {
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$order = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
		$by = [];
		foreach ( $rows as $r ) {
			$r = (array) $r;
			$t = (string) ( $r['time'] ?? '' );
			$i = array_search( (string) ( $r['day'] ?? '' ), $order, true );
			if ( '' !== $t && false !== $i ) {
				$by[ $t ][] = (int) $i;
			}
		}
		$parts = [];
		foreach ( $by as $t => $days ) {
			sort( $days );
			$runs = [];
			foreach ( $days as $i ) {
				$last = count( $runs ) - 1;
				if ( $last >= 0 && end( $runs[ $last ] ) === $i - 1 ) {
					$runs[ $last ][] = $i;
				} else {
					$runs[] = [ $i ];
				}
			}
			$labels = [];
			foreach ( $runs as $run ) {
				$a = substr( $order[ $run[0] ], 0, 3 );
				$b = substr( $order[ end( $run ) ], 0, 3 );
				$labels[] = count( $run ) >= 3 ? $a . '–' . $b : implode( ', ', array_map( static function ( $i ) use ( $order ) { return substr( $order[ $i ], 0, 3 ); }, $run ) );
			}
			$parts[] = implode( ', ', $labels ) . ' ' . $t;
		}
		return implode( '; ', $parts );
	}

	/**
	 * Every Business Info setting in Rank Math with what it holds now, what Business Info would put there and what would
	 * happen: one row per business_map() entry.
	 *   status  match (same already) | fill (empty or Rank Math's own starting value) | update (Launch Builder's earlier
	 *           value, Business Info changed since) | yours (set in Rank Math, kept unless forced) | empty (Business Info
	 *           has nothing for it yet)
	 *   action  write | keep | none
	 *
	 * @param string[] $force 'titles.key' or 'all' (the same list sync-seo takes)
	 */
	public static function business_rows( array $force = [] ): array {
		$raw = self::raw_option( self::RM_OPTIONS['titles'] );
		$want = self::rm_desired( 'business' )['titles'];
		$fields = Company::fields();
		$res = Company::resolve();
		$rank = [ 'empty' => 0, 'match' => 1, 'fill' => 2, 'update' => 3, 'yours' => 4 ];
		$rows = [];
		foreach ( self::business_map() as $id => $m ) {
			$status = 'empty';
			$writes = [];
			$keeps = [];
			foreach ( $m['keys'] as $key ) {
				if ( ! array_key_exists( $key, $want ) ) {
					continue;
				}
				$st = self::key_state( 'titles', $key, $raw, $want[ $key ] );
				$coarse = in_array( $st, [ 'edited', 'own' ], true ) ? 'yours' : $st;
				if ( $rank[ $coarse ] > $rank[ $status ] ) {
					$status = $coarse;
				}
				if ( 'fill' === $st || 'update' === $st || ( 'yours' === $coarse && ( in_array( 'all', $force, true ) || in_array( 'titles.' . $key, $force, true ) ) ) ) {
					$writes[] = $key;
				} elseif ( 'yours' === $coarse ) {
					$keeps[] = $key;
				}
			}
			$from = [];
			foreach ( $m['from'] as $k ) {
				if ( isset( $res[ $k ], $fields[ $k ] ) && 'default' !== $res[ $k ]['from'] && '' !== trim( (string) $res[ $k ]['value'] ) && ! ( 'country' === $k && 'site' === $res[ $k ]['from'] ) ) {
					$from[] = [ 'key' => $k, 'label' => (string) $fields[ $k ][0] ];
				}
			}
			$main = $m['keys'][0];
			$rows[] = [
				'id'        => (string) $id,
				'label'     => (string) $m['label'],
				'keys'      => $m['keys'],
				'from'      => $from,
				'from_text' => (string) $m['from_text'],
				'status'    => $status,
				'action'    => $writes ? 'write' : ( $keeps ? 'keep' : 'none' ),
				'writes'    => $writes,
				'keeps'     => $keeps,
				'current'   => self::business_show( $main, $raw[ $main ] ?? null ),
				'wanted'    => array_key_exists( $main, $want ) ? self::business_show( $main, $want[ $main ] ) : '',
				'by'        => 'yours' === $status ? ( self::edited_since_write( $m['keys'] ) ? 'edited' : 'own' ) : '',
			];
		}
		return $rows;
	}

	/** Did Launch Builder write one of these keys before (so a different value now is a change somebody made in Rank Math)? */
	private static function edited_since_write( array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( null !== Ledger::get( self::ledger_key( 'titles', $key ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Expand the force list the screen and tools send: row ids and 'titles.key' names become 'titles.key', or 'all'. */
	private static function business_force( array $force ): array {
		$map = self::business_map();
		$out = [];
		foreach ( $force as $f ) {
			$f = (string) $f;
			if ( 'all' === $f ) {
				return [ 'all' ];
			}
			if ( isset( $map[ $f ] ) ) {
				foreach ( $map[ $f ]['keys'] as $k ) {
					$out[] = 'titles.' . $k;
				}
			} elseif ( 0 === strpos( $f, 'titles.' ) && in_array( substr( $f, 7 ), self::BUSINESS_KEYS, true ) ) {
				$out[] = $f;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Preview or write the Business Info settings in Rank Math (the Business Info screen's Rank Math card, fill-rank-math).
	 * A dry run (the default) writes nothing. A real run takes a snapshot, writes only what Launch Builder may (see
	 * key_state), records what it wrote and what it replaced so undo_business_fill() can put it back.
	 *
	 * @param array $args { dry_run: bool (default true), force: string[] (row ids, 'titles.key' or 'all'), local_seo: ?bool,
	 *                      only: string[] (Rank Math titles keys: a narrow fill that leaves every other setting, and the overlay list, alone;
	 *                      it adds to the last fill's undo record instead of replacing it) }
	 */
	public static function business_sync( array $args = [] ): array {
		$dry = ! array_key_exists( 'dry_run', $args ) || ! empty( $args['dry_run'] );
		$only = array_values( array_intersect( array_map( 'strval', (array) ( $args['only'] ?? [] ) ), self::BUSINESS_KEYS ) );
		$force = self::business_force( array_map( 'strval', (array) ( $args['force'] ?? [] ) ) );
		$local = array_key_exists( 'local_seo', $args ) && null !== $args['local_seo'] ? (bool) $args['local_seo'] : null;
		$out = [ 'active' => self::rm_active(), 'ready' => self::rm_ready(), 'version' => self::rm_version(), 'dry_run' => $dry, 'local_seo' => [ 'on' => self::rm_module( 'local-seo' ), 'change' => null ], 'rows' => [], 'counts' => [], 'changes' => [], 'kept' => [], 'warnings' => [], 'cannot' => self::business_cannot() ];
		if ( ! $out['active'] ) {
			$out['warnings'][] = __( 'Rank Math is not active on this site, so there is nothing to fill. When it is turned on, Business Info fills it automatically.', 'launch-builder' );
			return $out;
		}
		$before = [ 'raw' => self::raw_option( self::RM_OPTIONS['titles'] ), 'owned' => (array) get_option( self::OPT_OWNED, [] ), 'module' => self::rm_module( 'local-seo' ), 'ledger' => [] ];
		foreach ( self::BUSINESS_KEYS as $k ) {
			$before['ledger'][ self::ledger_key( 'titles', $k ) ] = Ledger::get( self::ledger_key( 'titles', $k ) );
		}
		$r = self::rank_math_settings( [ 'scope' => 'business', 'dry_run' => $dry, 'force' => $force, 'local_seo' => $local, 'label' => 'fill-rank-math', 'only' => $only ] );
		$out['changes'] = $r['changes'];
		$out['kept'] = $r['kept'];
		$out['warnings'] = $r['warnings'];
		$out['via'] = $r['via'];
		if ( ! empty( $r['snapshot'] ) ) {
			$out['snapshot'] = $r['snapshot'];
		}
		foreach ( (array) $r['modules'] as $m ) {
			if ( 'local-seo' === $m['module'] ) {
				$out['local_seo']['change'] = $m['new'] ? 'on' : 'off';
			}
		}
		if ( ! $dry && ( $r['changes'] || $r['modules'] ) && ! empty( $r['snapshot'] ) ) {
			$keys = [];
			$was = [];
			foreach ( $r['changes'] as $c ) {
				$k = substr( (string) $c['key'], 7 ); // After "titles.".
				$keys[] = $k;
				$was[ $k ] = array_key_exists( $k, $before['raw'] ) ? [ 'v' => $before['raw'][ $k ] ] : null;
			}
			$rec = [
				'time'     => time(),
				'user'     => get_current_user_id(),
				'snapshot' => (string) $r['snapshot'],
				'keys'     => $keys,
				'before'   => $was,
				'ledger'   => array_intersect_key( $before['ledger'], array_flip( array_map( static function ( $k ) { return self::ledger_key( 'titles', $k ); }, $keys ) ) ),
				'owned'    => $before['owned'],
				'module'   => $out['local_seo']['change'] ? [ 'local-seo' => $before['module'] ] : [],
			];
			$last = $only ? get_option( self::OPT_FILL, null ) : null;
			if ( is_array( $last ) && ! empty( $last['keys'] ) && is_array( $last['before'] ?? null ) ) { // A narrow fill joins the earlier undo record; what that one saved stays what Undo puts back.
				$rec = [
					'time'     => (int) ( $last['time'] ?? $rec['time'] ),
					'user'     => (int) ( $last['user'] ?? $rec['user'] ),
					'snapshot' => (string) ( $last['snapshot'] ?? $rec['snapshot'] ),
					'keys'     => array_values( array_unique( array_merge( (array) $last['keys'], $keys ) ) ),
					'before'   => (array) $last['before'] + $was,
					'ledger'   => (array) ( $last['ledger'] ?? [] ) + $rec['ledger'],
					'owned'    => (array) ( $last['owned'] ?? $before['owned'] ),
					'module'   => (array) ( $last['module'] ?? [] ) + $rec['module'],
				];
			}
			update_option( self::OPT_FILL, $rec, false );
		}
		$out['rows'] = self::business_rows( $force );
		foreach ( [ 'match', 'fill', 'update', 'yours', 'empty' ] as $st ) {
			$out['counts'][ $st ] = count( array_filter( $out['rows'], static function ( $row ) use ( $st ) { return $st === $row['status']; } ) );
		}
		$out['last_fill'] = self::last_fill();
		return $out;
	}

	/** The last fill that can still be undone: { time, user, snapshot, keys } or null. */
	public static function last_fill(): ?array {
		$rec = get_option( self::OPT_FILL, null );
		if ( ! is_array( $rec ) || empty( $rec['keys'] ) ) {
			return null;
		}
		return [ 'time' => (int) ( $rec['time'] ?? 0 ), 'user' => (int) ( $rec['user'] ?? 0 ), 'snapshot' => (string) ( $rec['snapshot'] ?? '' ), 'keys' => array_values( (array) $rec['keys'] ) ];
	}

	/**
	 * Put back what the last fill replaced. A setting somebody changed in Rank Math since the fill is left as it is.
	 *
	 * @return array|\WP_Error { reverted: string[], left: string[], local_seo: ?string }
	 */
	public static function undo_business_fill() {
		$rec = get_option( self::OPT_FILL, null );
		if ( ! is_array( $rec ) || empty( $rec['before'] ) || ! is_array( $rec['before'] ) ) {
			return new \WP_Error( 'lb_nothing', __( 'There is no Rank Math fill to undo.', 'launch-builder' ), [ 'status' => 404 ] );
		}
		if ( ! self::rm_active() ) {
			return new \WP_Error( 'lb_rm_off', __( 'Rank Math is not active, so there is nothing to put back.', 'launch-builder' ), [ 'status' => 409 ] );
		}
		$out = [ 'reverted' => [], 'left' => [], 'local_seo' => null ];
		self::$raw = true;
		self::$writing = true;
		try {
			$raw = self::raw_option( self::RM_OPTIONS['titles'] );
			foreach ( $rec['before'] as $key => $b ) {
				$key = (string) $key;
				if ( ! in_array( $key, self::BUSINESS_KEYS, true ) || ! array_key_exists( $key, $raw ) ) {
					continue;
				}
				if ( Ledger::is_edited( self::ledger_key( 'titles', $key ), Ledger::hash_value( $raw[ $key ] ) ) ) {
					$out['left'][] = $key; // Changed in Rank Math after the fill: not ours to undo.
					continue;
				}
				if ( is_array( $b ) && array_key_exists( 'v', $b ) ) {
					$raw[ $key ] = $b['v'];
				} else {
					unset( $raw[ $key ] );
				}
				$out['reverted'][] = $key;
			}
			update_option( self::RM_OPTIONS['titles'], $raw );
			if ( isset( $rec['module']['local-seo'] ) && self::rm_module( 'local-seo' ) !== (bool) $rec['module']['local-seo'] ) {
				self::rm_write( [], [ 'local-seo' => (bool) $rec['module']['local-seo'] ] );
				$out['local_seo'] = $rec['module']['local-seo'] ? 'on' : 'off';
			}
		} finally {
			self::$writing = false;
			self::$raw = false;
		}
		foreach ( (array) ( $rec['ledger'] ?? [] ) as $lk => $row ) {
			if ( ! is_array( $row ) ) {
				Ledger::forget( (string) $lk );
			} else {
				Ledger::record( (string) $lk, (string) $row['type'], $row['id'], (string) $row['hash'], (string) $row['status'] );
			}
		}
		$written = array_values( array_intersect( array_map( 'strval', array_keys( $rec['before'] ) ), $out['reverted'] ) );
		$owned = array_values( array_diff( (array) get_option( self::OPT_OWNED, [] ), $written ) );
		self::set_owned( array_merge( $owned, array_intersect( (array) ( $rec['owned'] ?? [] ), $written ) ) );
		delete_option( self::OPT_FILL );
		self::rm_reset();
		self::flush_identity();
		Cache::purge_later();
		return $out;
	}
}
