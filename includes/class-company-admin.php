<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Business Info screen (Launch Builder > Business Info): a short form on the shared admin look.
 *
 * Up front: Your business, Address and hours, Reviews, Social profiles and Buttons and form (what people type for their
 * pages). A few extras sit under a collapsed "More options"; the fields in Company::OFF_SCREEN have no input at all (WordPress
 * holds the site title, tagline and site icon; Rank Math holds what only search engines read). The Rank Math card is one
 * sentence, Fill and Undo buttons, and the table of what would change behind "See what changes" (the work is
 * Compat::business_sync() and Compat::undo_business_fill(), the same as the fill-rank-math tool).
 *
 * One form saves everything (admin-post lb_company_save, nonce lb_company_save, manage_options). The Rank Math buttons
 * belong to a second, empty form (lb_company_rm) through the form attribute, so the sticky Save bar and those buttons
 * never share a submit. Values are validated and sanitised by Company::update(), exactly as set-company does.
 */
final class Company_Admin {

	const PAGE = 'launch-builder-business';

	/** One-time result of a Rank Math fill or undo (per user). */
	const MSG = 'lb_company_msg_';

	/** Where the note about the last profile import is kept (so its Undo button survives the redirect). */
	const OPT_IMPORT_UNDO = 'launch_builder_company_import_undo';

	public static function boot(): void {
		add_action( 'admin_post_lb_company_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_lb_company_rm', [ __CLASS__, 'rank_math_action' ] );
		add_action( 'admin_post_lb_company_mb', [ __CLASS__, 'mb_action' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	private static function on_screen(): bool {
		return isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- reads which screen this is.
	}

	public static function assets(): void {
		if ( ! self::on_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'lb-company-admin', LB_URL . 'assets/js/company-admin.js', [ 'lb-admin' ], (string) @filemtime( LB_DIR . 'assets/js/company-admin.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-company-admin', 'window.lbCompany=' . wp_json_encode( [
			'i18n' => [
				'choose'   => __( 'Choose image', 'launch-builder' ),
				'change'   => __( 'Change image', 'launch-builder' ),
				'use'      => __( 'Use this image', 'launch-builder' ),
				'copied'   => __( 'Copied', 'launch-builder' ),
				'unsaved'  => __( 'You have unsaved changes.', 'launch-builder' ),
				'saveRm'   => __( 'Save your changes first, so Rank Math gets them.', 'launch-builder' ),
				'removeRow' => __( 'Remove this row', 'launch-builder' ),
			],
		] ) . ';', 'before' );
	}

	/* ------------------------------------------------------------------ saving */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_company_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified above; every value is sanitised in Company::update() and Company::set_source().
		$values = (array) ( $post['lb'] ?? [] );
		foreach ( (array) ( $post['lb_lists'] ?? [] ) as $list ) { // Lists shown on the screen whose rows were all removed.
			$list = sanitize_key( (string) $list );
			if ( ! isset( $values[ $list ] ) ) {
				$values[ $list ] = [];
			}
		}
		Company::update( $values );
		$rejected = Company::rejected();
		Company_Tidy::forget_note(); // The person has looked at the form and saved it: the "we moved these" notice has done its job.
		$map = [];
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $post['lb_map'] ?? '' ) ) as $line ) {
			if ( false !== strpos( $line, '=' ) ) {
				[ $k, $v ] = array_map( 'trim', explode( '=', $line, 2 ) );
				$map[ $k ] = $v;
			}
		}
		$r = Company::set_source( (string) ( $post['lb_source'] ?? '' ), $map, ! empty( $post['lb_source_network'] ) );
		Cache::flush(); // Before the redirect, so header-based purges still go out.
		$state = is_wp_error( $r ) ? 'source-refused' : ( $rejected ? 'rejected' : '1' );
		if ( $rejected ) { // Say which values were not used and why; whatever was saved before stays.
			$all = Company::fields();
			$lines = [];
			foreach ( $rejected as $k => $why ) {
				$lines[] = ( isset( $all[ $k ] ) ? $all[ $k ][0] : $k ) . ': ' . $why;
			}
			self::remember( [ 'kind' => 'warn', 'text' => __( 'Saved, except for what is listed here. That was left out.', 'launch-builder' ), 'lines' => $lines ] );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'saved' => $state ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The Rank Math buttons: preview (a page view with the table), fill, undo. */
	public static function rank_math_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'lb_company_rm', 'lb_rm_nonce' );
		$post = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified above; ids are matched against Compat::business_map().
		$do = sanitize_key( (string) ( $post['do'] ?? '' ) );
		$back = [ 'page' => self::PAGE ];
		if ( 'preview' === $do ) {
			$back['rm'] = 'preview';
		} elseif ( 'import' === $do ) {
			self::remember( self::import_message() );
			$back['rm'] = 'done';
		} elseif ( 'undo_import' === $do ) {
			self::remember( self::import_undo_message() );
			$back['rm'] = 'done';
		} elseif ( 'apply' === $do || 'undo' === $do ) {
			if ( ! Lock::acquire( 'sync', 120 ) ) {
				self::remember( [ 'kind' => 'bad', 'text' => __( 'Another sync is running. Try again in a minute.', 'launch-builder' ), 'lines' => [] ] );
			} else {
				try {
					self::remember( 'apply' === $do ? self::fill_message( $post ) : self::undo_message() );
				} finally {
					Lock::release( 'sync' );
				}
			}
			$back['rm'] = 'done';
		}
		wp_safe_redirect( add_query_arg( $back, admin_url( 'admin.php' ) ) . '#lb-rm' );
		exit;
	}

	/** Bring the profile addresses Rank Math holds into Business Info (a snapshot first; nothing already here is replaced). */
	private static function import_message(): array {
		$new = self::profiles_to_import();
		if ( ! $new ) {
			return [ 'kind' => 'ok', 'text' => __( 'Nothing to import. Business Info already has every profile Rank Math lists.', 'launch-builder' ), 'lines' => [] ];
		}
		$cur = Company::values();
		$set = [];
		$others = Company::list_value( 'other_profiles' );
		foreach ( $new as $u ) {
			$host = strtolower( (string) preg_replace( '#^www\.#', '', (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
			$key = '';
			foreach ( [ 'facebook' => 'facebook.com', 'instagram' => 'instagram.com', 'youtube' => 'youtube.com' ] as $k => $h ) {
				if ( ( $host === $h || '.' . $h === substr( $host, -strlen( '.' . $h ) ) ) && '' === (string) ( $cur[ $k ] ?? '' ) && ! isset( $set[ $k ] ) ) {
					$key = $k;
				}
			}
			if ( '' !== $key ) {
				$set[ $key ] = $u;
			} elseif ( ! in_array( $u, $others, true ) ) {
				$others[] = $u;
			}
		}
		$snap = Abilities::snapshot( 'import-profiles', [], [ Company::OPT ] );
		$set['other_profiles'] = $others;
		Company::update( $set );
		update_option( self::OPT_IMPORT_UNDO, [ 'id' => $snap, 'time' => time(), 'hash' => md5( (string) wp_json_encode( get_option( Company::OPT, [] ) ) ) ], false );
		/* translators: %d: number of profiles */
		return [ 'kind' => 'ok', 'text' => sprintf( _n( 'Imported %d profile from Rank Math.', 'Imported %d profiles from Rank Math.', count( $new ), 'launch-builder' ), count( $new ) ), 'lines' => [ __( 'Changed your mind? Undo the import below. Rank Math keeps its own list either way.', 'launch-builder' ) ], 'undo_import' => true ];
	}

	/** Put Business Info back to how it was before the last profile import (the snapshot the import took). */
	private static function import_undo_message(): array {
		$u = get_option( self::OPT_IMPORT_UNDO );
		$id = is_array( $u ) && time() - (int) ( $u['time'] ?? 0 ) < self::UNDO_TTL ? (string) ( $u['id'] ?? '' ) : '';
		if ( '' === $id || ! Abilities::get_snapshot( $id ) ) {
			delete_option( self::OPT_IMPORT_UNDO );
			return [ 'kind' => 'warn', 'text' => __( 'There is nothing to undo any more.', 'launch-builder' ), 'lines' => [] ];
		}
		if ( md5( (string) wp_json_encode( get_option( Company::OPT, [] ) ) ) !== (string) ( $u['hash'] ?? '' ) ) { // Business Info changed since: putting the snapshot back would undo that too.
			delete_option( self::OPT_IMPORT_UNDO );
			return [ 'kind' => 'warn', 'text' => __( 'Business Info has changed since the import, so nothing was put back. Remove the profiles you do not want by hand.', 'launch-builder' ), 'lines' => [] ];
		}
		$r = Abilities::restore_snapshot( [ 'id' => $id ] );
		delete_option( self::OPT_IMPORT_UNDO );
		Cache::flush();
		return is_wp_error( $r )
			? [ 'kind' => 'bad', 'text' => $r->get_error_message(), 'lines' => [] ]
			: [ 'kind' => 'ok', 'text' => __( 'Put back the profiles you had before the import.', 'launch-builder' ), 'lines' => [] ];
	}

	/** Run the fill and describe the result for the person. */
	private static function fill_message( array $post ): array {
		$force = array_map( 'sanitize_key', (array) ( $post['rm_force'] ?? [] ) );
		$args = [ 'dry_run' => false, 'force' => $force ];
		if ( ! empty( $post['rm_local'] ) ) {
			$args['local_seo'] = true;
		}
		$r = Compat::business_sync( $args );
		if ( empty( $r['active'] ) ) {
			return [ 'kind' => 'warn', 'text' => __( 'Rank Math is not active on this site, so nothing was filled.', 'launch-builder' ), 'lines' => [] ];
		}
		$labels = self::setting_labels( array_column( (array) $r['changes'], 'key' ) );
		$lines = [];
		if ( $labels ) {
			/* translators: %s: list of Rank Math settings, e.g. "Business name, Address" */
			$lines[] = sprintf( __( 'Filled in: %s.', 'launch-builder' ), implode( ', ', $labels ) );
		}
		if ( 'on' === ( $r['local_seo']['change'] ?? null ) ) {
			$lines[] = __( 'Rank Math\'s Local SEO module is now on.', 'launch-builder' );
		}
		$kept = self::setting_labels( array_column( (array) $r['kept'], 'key' ) );
		if ( $kept ) {
			/* translators: %s: list of Rank Math settings */
			$lines[] = sprintf( __( 'Kept what you set in Rank Math: %s.', 'launch-builder' ), implode( ', ', $kept ) );
		}
		foreach ( (array) $r['warnings'] as $w ) {
			$lines[] = (string) $w;
		}
		if ( ! $labels && 'on' !== ( $r['local_seo']['change'] ?? null ) ) {
			return [ 'kind' => 'ok', 'text' => __( 'Nothing needed filling. Rank Math already has what Business Info can give it.', 'launch-builder' ), 'lines' => $lines ];
		}
		return [
			'kind'  => 'ok',
			'text'  => $labels ? sprintf( /* translators: %d: number of settings */ _n( 'Rank Math now has %d setting from Business Info.', 'Rank Math now has %d settings from Business Info.', count( $labels ), 'launch-builder' ), count( $labels ) ) : __( 'Rank Math\'s Local SEO module is now on.', 'launch-builder' ),
			'lines' => $lines,
			'undo'  => true,
		];
	}

	private static function undo_message(): array {
		$r = Compat::undo_business_fill();
		if ( is_wp_error( $r ) ) {
			return [ 'kind' => 'warn', 'text' => $r->get_error_message(), 'lines' => [] ];
		}
		$lines = [];
		if ( $r['left'] ) {
			$lines[] = __( 'Left as they are, because you changed them in Rank Math after the fill:', 'launch-builder' ) . ' ' . implode( ', ', self::setting_labels( $r['left'] ) ) . '.';
		}
		if ( $r['local_seo'] ) {
			$lines[] = 'on' === $r['local_seo'] ? __( 'Rank Math\'s Local SEO module is back on.', 'launch-builder' ) : __( 'Rank Math\'s Local SEO module is back off.', 'launch-builder' );
		}
		return [ 'kind' => 'ok', 'text' => __( 'Put back what the last fill replaced.', 'launch-builder' ), 'lines' => $lines ];
	}

	/** Rank Math setting names, as the preview table shows them, for a list of titles keys ('titles.name' or 'name'). */
	private static function setting_labels( array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$key = (string) $key;
			$key = 0 === strpos( $key, 'titles.' ) ? substr( $key, 7 ) : $key;
			foreach ( Compat::business_map() as $m ) {
				if ( in_array( $key, $m['keys'], true ) ) {
					$out[ $m['label'] ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	private static function remember( array $msg ): void {
		set_transient( self::MSG . get_current_user_id(), $msg, 10 * MINUTE_IN_SECONDS );
	}

	private static function recall(): ?array {
		$key = self::MSG . get_current_user_id();
		$msg = get_transient( $key );
		if ( false === $msg ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $msg ) ? $msg : null;
	}

	/* ------------------------------------------------------------------ small pieces */

	private static function slug( string $group ): string {
		return 'lb-g-' . sanitize_title( $group );
	}

	/** A card: title, a one-line help, then the content. $id is the anchor the section links jump to. */
	private static function card( string $id, string $title, string $help, string $body, string $chips = '' ): string {
		return '<section class="lb-panel lb-card--form" id="' . esc_attr( $id ) . '"><div class="lb-panel__head"><h2>' . esc_html( $title ) . '</h2>' . $chips . '</div>' . ( '' !== $help ? '<p class="lb-help lb-help--lead">' . $help . '</p>' : '' ) . $body . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $help, $body and $chips are built from escaped parts.
	}

	/** A grid of fields: keys in the order given. */
	private static function fields_grid( array $keys, array $own, array $res, array $wide = [] ): string {
		$all = Company::fields();
		$h = '<div class="lb-fields">';
		foreach ( $keys as $k ) {
			if ( isset( $all[ $k ] ) ) {
				$h .= self::field( $k, $all[ $k ], $own, $res, in_array( $k, $wide, true ) );
			}
		}
		return $h . '</div>';
	}

	/** One labelled control. */
	private static function field( string $k, array $f, array $own, array $res, bool $wide = false ): string {
		if ( 'source' === ( $res[ $k ]['from'] ?? '' ) ) {
			return self::from_source( $k, $f, $res, $wide );
		}
		$name = 'lb[' . $k . ']';
		$id = 'lb-' . $k;
		$help_id = $id . '-help';
		$val = (string) ( $own[ $k ] ?? '' );
		$type = $f[1];
		$long = in_array( $type, [ 'html', 'textarea', 'list' ], true );
		$d = ' aria-describedby="' . esc_attr( $help_id ) . '"';
		$demo = (string) $f[2];
		if ( 'name' === $k ) { // The blank box shows what it follows: the site title.
			$demo = (string) Company::real_text( (string) get_option( 'blogname' ) ) ?: $demo;
		} elseif ( 'country' === $k ) { // The blank box shows the guess from the site language; search engines get a country only when one is typed.
			$demo = (string) Company::locale_country();
		}
		$ph = '' !== $demo ? ' placeholder="' . esc_attr( $demo ) . '"' : '';
		$h = '<div class="lb-field' . ( $wide || $long ? ' lb-field--wide' : '' ) . ( 'bool' === $type ? ' lb-field--check' : '' ) . '" data-key="' . esc_attr( $k ) . '">';
		if ( 'bool' === $type ) {
			$h .= '<input type="hidden" name="' . esc_attr( $name ) . '" value=""><label class="lb-check"><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="checkbox" value="1"' . checked( '1', $val, false ) . $d . '> <span>' . esc_html( $f[0] ) . '</span></label>';
		} else {
			$h .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $f[0] ) . '</label>';
			switch ( $type ) {
				case 'html':
				case 'textarea':
					$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . ( 'html' === $type ? 6 : 3 ) . '"' . ( 'html' === $type ? ' class="code" spellcheck="false"' : '' ) . ( 'html' === $type ? '' : $ph ) . $d . '>' . esc_textarea( $val ) . '</textarea>';
					break;
				case 'list': // other_profiles, areas_served: one per line.
					$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" placeholder="' . esc_attr( 'other_profiles' === $k ? "https://www.yelp.com/biz/your-business\nhttps://www.linkedin.com/company/your-business" : __( 'Austin, TX', 'launch-builder' ) ) . '"' . ( 'other_profiles' === $k ? ' inputmode="url" spellcheck="false"' : '' ) . $d . '>' . esc_textarea( implode( "\n", (array) ( $own[ $k ] ?? [] ) ) ) . '</textarea>';
					break;
				case 'number':
					$step = in_array( $k, [ 'lat', 'lng' ], true ) ? 'any' : ( 'rating' === $k ? 'any' : '1' );
					$range = 'rating' === $k ? ' min="0" max="5"' : ( 'lat' === $k ? ' min="-90" max="90"' : ( 'lng' === $k ? ' min="-180" max="180"' : ' min="0"' ) );
					$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="number" step="' . esc_attr( $step ) . '"' . $range . ' value="' . esc_attr( $val ) . '"' . $d . '>';
					break;
				case 'date':
					$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="date" value="' . esc_attr( $val ) . '"' . $d . '>';
					break;
				case 'image':
					$h .= self::image_control( $k, $val, (string) $res[ $k ]['value'], $d );
					break;
				default:
					if ( 'price_range' === $k ) {
						$h .= self::price_select( $id, $name, $val, $d );
					} else {
						// autocomplete off everywhere: these are the business's facts, not the signed-in person's.
						$mode = in_array( $k, [ 'phone', 'emergency_phone' ], true ) ? ' inputmode="tel"' : ( 'url' === $type ? ' inputmode="url" spellcheck="false"' : '' );
						$list = 'business_type' === $k ? ' list="lb-business-types" spellcheck="false"' : '';
						$h .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . ( 'email' === $type ? 'email' : 'text' ) . '" value="' . esc_attr( $val ) . '"' . $ph . $mode . $list . ' autocomplete="off"' . $d . '>';
					}
			}
		}
		$h .= '<p class="lb-help" id="' . esc_attr( $help_id ) . '">' . self::help( $k, $f, $res ) . '</p></div>';
		return $h;
	}

	/** The help line of a field: the fixed help, then where the value in effect comes from when it is not the plain saved one. */
	private static function help( string $k, array $f, array $res ): string {
		$h = esc_html( (string) $f[4] );
		$from = $res[ $k ]['from'];
		$now = '' !== $res[ $k ]['value'] ? '<code>' . esc_html( mb_substr( $res[ $k ]['value'], 0, 90 ) ) . '</code>' : '';
		if ( 'derived' === $from && '' !== $now ) {
			$h .= ' ' . esc_html__( 'Shown now:', 'launch-builder' ) . ' ' . $now;
		} elseif ( 'computed' === $from && '' !== $now ) {
			$h .= ' ' . esc_html__( 'Built from the fields above:', 'launch-builder' ) . ' ' . $now;
		} elseif ( 'site' === $from && '' !== $now && 'country' === $k ) {
			$h .= ' ' . esc_html__( 'Guess from your site language:', 'launch-builder' ) . ' ' . $now;
		} elseif ( 'site' === $from && '' !== $now && 'name' !== $k ) {
			$h .= ' ' . esc_html__( 'Shown now:', 'launch-builder' ) . ' ' . $now;
		} elseif ( 'expired' === $from ) {
			$h .= ' <strong>' . esc_html__( 'The promotion has ended, so nothing shows.', 'launch-builder' ) . '</strong>';
		}
		if ( 'business_type' === $k ) {
			$have = Compat::rm_business_types();
			$cur = (string) ( get_option( Company::OPT, [] )['business_type'] ?? '' );
			if ( $have && '' !== $cur && '' === Compat::rm_business_type( $cur ) ) {
				$h .= ' <strong class="lb-help--warn">' . esc_html__( 'Rank Math has no type with that name, so it keeps its own.', 'launch-builder' ) . '</strong>';
			}
		}
		return trim( $h );
	}

	private static function price_select( string $id, string $name, string $val, string $d ): string {
		$opts = [ '' => __( 'Not set', 'launch-builder' ), '$' => __( '$ (low)', 'launch-builder' ), '$$' => __( '$$ (moderate)', 'launch-builder' ), '$$$' => __( '$$$ (high)', 'launch-builder' ), '$$$$' => __( '$$$$ (premium)', 'launch-builder' ) ];
		if ( '' !== $val && ! isset( $opts[ $val ] ) ) {
			$opts[ $val ] = $val; // Keep a value saved some other way instead of dropping it.
		}
		$h = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $d . '>';
		foreach ( $opts as $v => $label ) {
			$h .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( $val, (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h . '</select>';
	}

	/** An image field: the saved ID or URL, a preview, and a button that opens the media library. */
	private static function image_control( string $k, string $val, string $url, string $d ): string {
		$id = 'lb-' . $k;
		$h = '<div class="lb-image" data-lb-image>';
		$h .= '<span class="lb-image__preview' . ( '' === $url ? ' is-empty' : '' ) . '" data-lb-image-preview>' . ( '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '' ) . '</span>';
		$h .= '<span class="lb-image__controls"><input id="' . esc_attr( $id ) . '" name="' . esc_attr( 'lb[' . $k . ']' ) . '" type="text" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr__( 'Image ID or address', 'launch-builder' ) . '" autocomplete="off" spellcheck="false"' . $d . '>';
		$h .= '<button type="button" class="lb-btn lb-btn--sm" data-lb-pick>' . esc_html( '' === $val ? __( 'Choose image', 'launch-builder' ) : __( 'Change image', 'launch-builder' ) ) . '</button>';
		$h .= '<button type="button" class="lb-btn lb-btn--sm lb-btn--ghost" data-lb-clear' . ( '' === $val ? ' hidden' : '' ) . '>' . esc_html__( 'Remove', 'launch-builder' ) . '</button></span></div>';
		return $h;
	}

	/* ------------------------------------------------------------------ repeaters (reviews, opening hours) */

	private static function repeater( string $k, array $rows ): string {
		$add = [ 'reviews' => __( 'Add a review site', 'launch-builder' ), 'hours_spec' => __( 'Add opening hours', 'launch-builder' ) ][ $k ];
		$empty = [ 'reviews' => __( 'No review sites yet.', 'launch-builder' ), 'hours_spec' => __( 'No opening hours yet.', 'launch-builder' ) ][ $k ];
		$h = '<div class="lb-rep" data-lb-rep="' . esc_attr( $k ) . '"><input type="hidden" name="lb_lists[]" value="' . esc_attr( $k ) . '"><div class="lb-rep__rows" data-empty="' . esc_attr( $empty ) . '">';
		$i = 0;
		foreach ( $rows as $row ) {
			$h .= self::repeater_row( $k, (string) $i++, (array) $row );
		}
		$h .= '</div><template id="lb-tpl-' . esc_attr( $k ) . '">' . self::repeater_row( $k, '__i__', [] ) . '</template>';
		$h .= '<p class="lb-rep__add"><button type="button" class="lb-btn lb-btn--sm" data-lb-add="' . esc_attr( $k ) . '">' . Dashboard::icon( 'plus' ) . esc_html( $add ) . '</button></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		return $h;
	}

	private static function repeater_row( string $k, string $i, array $row ): string {
		$n = static function ( string $sub ) use ( $k, $i ): string {
			return esc_attr( 'lb[' . $k . '][' . $i . '][' . $sub . ']' );
		};
		$cell = static function ( string $label, string $control, string $class = '' ): string {
			return '<label class="lb-rep__cell' . ( '' !== $class ? ' ' . $class : '' ) . '"><span>' . esc_html( $label ) . '</span>' . $control . '</label>';
		};
		$h = '<div class="lb-rep__row lb-rep__row--' . esc_attr( $k ) . '">';
		if ( 'reviews' === $k ) {
			$cur = (string) ( $row['platform'] ?? '' );
			$opts = '<option value="">' . esc_html__( 'Choose…', 'launch-builder' ) . '</option>';
			$known = Company::PLATFORMS + ( '' !== $cur && ! isset( Company::PLATFORMS[ $cur ] ) ? [ $cur => Company::platform_label( $cur ) ] : [] );
			foreach ( $known as $key => $label ) {
				$opts .= '<option value="' . esc_attr( $key ) . '"' . selected( $cur, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$h .= $cell( __( 'Review site', 'launch-builder' ), '<select name="' . $n( 'platform' ) . '">' . $opts . '</select>' );
			$h .= $cell( __( 'Rating (0 to 5)', 'launch-builder' ), '<input type="number" step="any" min="0" max="5" name="' . $n( 'rating' ) . '" value="' . esc_attr( null !== ( $row['rating'] ?? null ) ? (string) $row['rating'] : '' ) . '">', 'lb-rep__cell--sm' );
			$h .= $cell( __( 'Reviews', 'launch-builder' ), '<input type="number" step="1" min="0" name="' . $n( 'count' ) . '" value="' . esc_attr( null !== ( $row['count'] ?? null ) ? (string) $row['count'] : '' ) . '">', 'lb-rep__cell--sm' );
			$h .= $cell( __( 'Link to your profile', 'launch-builder' ), '<input type="text" name="' . $n( 'url' ) . '" value="' . esc_attr( (string) ( $row['url'] ?? '' ) ) . '" placeholder="https://" inputmode="url" autocomplete="off">', 'lb-rep__cell--grow' );
		} else {
			global $wp_locale;
			$days = '<fieldset class="lb-days"><legend>' . esc_html__( 'Days', 'launch-builder' ) . '</legend>';
			foreach ( Company::DAYS as $pos => $d ) {
				$label = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $pos + 1 ) % 7 ) ) : $d;
				$days .= '<label class="lb-day"><input type="checkbox" name="' . esc_attr( 'lb[' . $k . '][' . $i . '][days][]' ) . '" value="' . esc_attr( $d ) . '"' . checked( in_array( $d, (array) ( $row['days'] ?? [] ), true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
			}
			$h .= $days . '</fieldset>';
			$h .= $cell( __( 'Opens', 'launch-builder' ), '<input type="time" name="' . $n( 'opens' ) . '" value="' . esc_attr( (string) ( $row['opens'] ?? '' ) ) . '">', 'lb-rep__cell--sm' );
			$h .= $cell( __( 'Closes', 'launch-builder' ), '<input type="time" name="' . $n( 'closes' ) . '" value="' . esc_attr( (string) ( $row['closes'] ?? '' ) ) . '">', 'lb-rep__cell--sm' );
		}
		return $h . '<button type="button" class="lb-iconbtn lb-rep__x" data-lb-remove aria-label="' . esc_attr__( 'Remove this row', 'launch-builder' ) . '">' . Dashboard::icon( 'x' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
	}

	/* ------------------------------------------------------------------ the screen */

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$own = (array) get_option( Company::OPT, [] );
		$res = Company::resolve();
		$all = Company::fields();
		$by = [];
		foreach ( $all as $k => $f ) {
			$by[ $f[5] ][] = $k;
		}

		echo '<div class="wrap lb-wrap lb-biz">';
		echo Dashboard::header( __( 'Business info', 'launch-builder' ), esc_html__( 'Set your details once. Every page, header and footer shows them, and search engines can read them too.', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the call.
		self::notices( $all );

		echo '<nav class="lb-jump" aria-label="' . esc_attr__( 'Sections', 'launch-builder' ) . '">';
		foreach ( [ 'Basics' => __( 'Your business', 'launch-builder' ), 'Address and hours' => __( 'Address and hours', 'launch-builder' ), 'Reviews' => __( 'Reviews', 'launch-builder' ), 'Social profiles' => __( 'Social profiles', 'launch-builder' ), 'Buttons and form' => __( 'Buttons and form', 'launch-builder' ) ] as $g => $label ) {
			echo '<a href="#' . esc_attr( self::slug( $g ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<a href="#lb-rm">' . esc_html__( 'Rank Math', 'launch-builder' ) . '</a>';
		if ( self::mb_panel_wanted( Company::meta_box_overview( true ) ) ) {
			echo '<a href="#lb-mb">' . esc_html__( 'Meta Box', 'launch-builder' ) . '</a>';
		}
		echo '<a href="#lb-g-more">' . esc_html__( 'More options', 'launch-builder' ) . '</a></nav>';

		echo self::meta_box_panel(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lb-biz-form" class="lb-form"><input type="hidden" name="action" value="lb_company_save">';
		wp_nonce_field( 'lb_company_save' );

		echo self::section_basics( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo self::section_address( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_reviews( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_social( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_buttons( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_rank_math(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_more( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="lb-savebar"><button type="submit" class="lb-btn lb-btn--primary" id="lb-save">' . Dashboard::icon( 'check' ) . esc_html__( 'Save changes', 'launch-builder' ) . '</button><span class="lb-savebar__note" id="lb-save-note" role="status" aria-live="polite" data-idle="' . esc_attr__( 'Saved changes show on every page straight away.', 'launch-builder' ) . '">' . esc_html__( 'Saved changes show on every page straight away.', 'launch-builder' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		echo '</form>';

		// The Rank Math buttons submit this empty form (they sit in the cards above through the form attribute).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lb-rm-form"><input type="hidden" name="action" value="lb_company_rm">';
		wp_nonce_field( 'lb_company_rm', 'lb_rm_nonce', false );
		echo '</form>';

		echo self::section_tokens( $all ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	/** Saved / rejected / refused notices, the Rank Math result and the "still sample text" reminder. */
	private static function notices( array $all ): void {
		$saved = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( 'source-refused' === $saved ) {
			echo '<div class="lb-flash lb-flash--bad" role="alert"><p>' . esc_html__( 'Saved, but that option can\'t be used as a source.', 'launch-builder' ) . '</p></div>';
		} elseif ( '1' === $saved ) {
			echo '<div class="lb-flash lb-flash--ok" role="status"><p>' . esc_html__( 'Saved. Every page now shows the new values.', 'launch-builder' ) . '</p></div>';
		} elseif ( 'tagline' === $saved ) {
			echo '<div class="lb-flash lb-flash--ok" role="status"><p>' . esc_html__( 'Done. The tagline now lives in Settings, General.', 'launch-builder' ) . '</p></div>';
		}
		$tc = Company_Tidy::tagline_conflict();
		if ( $tc ) { // The one question the tidy could not answer by itself.
			echo '<div class="lb-flash lb-flash--warn lb-flash--tagline" id="lb-tagline-q" role="status"><div><p><strong>' . esc_html__( 'Your tagline differs from Settings, General. Keep which?', 'launch-builder' ) . '</strong></p>';
			/* translators: 1: tagline saved in Business Info, 2: tagline from Settings, General */
			echo '<p>' . esc_html( sprintf( __( 'Business Info says "%1$s". Settings, General says "%2$s".', 'launch-builder' ), mb_substr( $tc['business'], 0, 140 ), mb_substr( $tc['settings'], 0, 140 ) ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-mb__form"><input type="hidden" name="action" value="lb_company_tagline">' . wp_nonce_field( 'lb_company_tagline', 'lb_tagline_nonce', true, false ) . '<button type="submit" name="choice" value="settings" class="lb-btn lb-btn--sm">' . esc_html__( 'Keep the Settings tagline', 'launch-builder' ) . '</button> <button type="submit" name="choice" value="business" class="lb-btn lb-btn--sm">' . esc_html__( 'Keep the Business Info one', 'launch-builder' ) . '</button></form></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- nonce field markup and escaped text.
		}
		$tn = Company_Tidy::note();
		if ( $tn ) { // What the update moved for the person, listed once; it goes away when the form is saved.
			echo '<div class="lb-flash lb-flash--warn lb-flash--tidy" id="lb-tidy-note" role="status"><div>';
			if ( $tn['moved'] ) {
				/* translators: 1: number of values, 2: what was moved, for example "address, hours, social links" */
				echo '<p><strong>' . esc_html( sprintf( _n( 'We moved %1$d value into the new boxes: %2$s. Check it.', 'We moved %1$d values into the new boxes: %2$s. Check them.', count( $tn['moved'] ), 'launch-builder' ), count( $tn['moved'] ), implode( ', ', $tn['moved'] ) ) ) . '</strong></p>';
			}
			foreach ( $tn['kept'] as $line ) {
				echo '<p>' . esc_html( $line ) . '</p>';
			}
			echo '</div></div>';
		}
		$msg = self::recall();
		if ( $msg ) {
			$kind = in_array( $msg['kind'] ?? '', [ 'ok', 'warn', 'bad' ], true ) ? $msg['kind'] : 'ok';
			echo '<div class="lb-flash lb-flash--' . esc_attr( $kind ) . '" role="status"><p><strong>' . esc_html( (string) ( $msg['text'] ?? '' ) ) . '</strong>';
			foreach ( (array) ( $msg['lines'] ?? [] ) as $line ) {
				echo '<br>' . esc_html( (string) $line );
			}
			echo '</p>';
			if ( ! empty( $msg['undo_import'] ) ) { // The profile import can be taken back.
				echo '<button type="submit" form="lb-rm-form" name="do" value="undo_import" class="lb-btn lb-btn--sm">' . Dashboard::icon( 'undo' ) . esc_html__( 'Undo the import', 'launch-builder' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
			}
			echo '</div>';
		}
		$res = Company::resolve();
		$sample = [];
		$say = [ 'address' => __( 'Address', 'launch-builder' ), 'hours' => __( 'Opening hours', 'launch-builder' ) ]; // The one-line fields have no input: name what a person fills in.
		foreach ( Company::essentials() as $k ) {
			if ( isset( $res[ $k ] ) && 'default' === $res[ $k ]['from'] ) {
				$sample[] = $say[ $k ] ?? $all[ $k ][0];
			}
		}
		if ( $sample ) {
			/* translators: %s: comma-separated field names */
			echo '<div class="lb-flash lb-flash--warn"><p>' . esc_html( sprintf( __( 'Your pages still show sample text for: %s.', 'launch-builder' ), implode( ', ', $sample ) ) ) . '</p></div>';
		}
	}

	private static function section_basics( array $by, array $own, array $res ): string {
		$body = self::fields_grid( [ 'name', 'phone', 'email' ], $own, $res );
		$body .= self::wordpress_facts( $res );
		return self::card( self::slug( 'Basics' ), __( 'Your business', 'launch-builder' ), esc_html__( 'Fields left empty show sample text on your pages until you fill them in.', 'launch-builder' ), $body );
	}

	/** What WordPress holds for you (read-only here): the tagline, the logo and the site icon, with where to change them. */
	private static function wordpress_facts( array $res ): string {
		$tag = $res['tagline'];
		$link = '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings, General', 'launch-builder' ) . '</a>';
		if ( 'default' !== $tag['from'] && '' !== $tag['value'] ) {
			/* translators: 1: the tagline, 2: link to Settings, General */
			$line = sprintf( esc_html__( 'Tagline: %1$s. Change it in %2$s.', 'launch-builder' ), '<strong>' . esc_html( mb_substr( $tag['value'], 0, 120 ) ) . '</strong>', $link );
		} else {
			/* translators: %s: link to Settings, General */
			$line = sprintf( esc_html__( 'Tagline: not set. Add one in %s.', 'launch-builder' ), $link );
		}
		/* translators: %s: link to Appearance, Customize */
		$line .= ' ' . sprintf( esc_html__( 'Logo and site icon: %s.', 'launch-builder' ), '<a href="' . esc_url( admin_url( 'customize.php' ) ) . '">' . esc_html__( 'Appearance, Customize', 'launch-builder' ) . '</a>' );
		return '<p class="lb-help lb-help--note" id="lb-wp-facts">' . $line . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	private static function section_address( array $by, array $own, array $res ): string {
		$parts = [ 'street', 'locality', 'region', 'postal_code' ];
		$body = self::fields_grid( $parts, $own, $res, [ 'street' ] );
		$from = $res['address']['from'];
		if ( 'computed' === $from ) {
			/* translators: %s: the address as one line */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site as: %s', 'launch-builder' ), $res['address']['value'] ) ) . '</p>';
		} elseif ( 'source' === $from ) {
			$body .= self::from_source( 'address', Company::fields()['address'], $res, true );
		} elseif ( 'saved' === $from ) {
			$body .= '<p class="lb-help lb-help--note">' . esc_html__( 'Your address is saved as one line. Fill in the boxes above so search engines can read it too.', 'launch-builder' ) . ' <code>' . esc_html( mb_substr( $res['address']['value'], 0, 120 ) ) . '</code></p>';
		}
		$body .= '<h3 class="lb-subhead">' . esc_html__( 'Opening hours', 'launch-builder' ) . '</h3>';
		if ( 'source' === ( $res['hours_spec']['from'] ?? '' ) ) {
			$body .= self::from_source( 'hours_spec', Company::fields()['hours_spec'], $res, true );
		} else {
			$body .= '<p class="lb-help lb-help--lead">' . esc_html__( 'Add one row for each set of days with the same hours. Days without a row count as closed.', 'launch-builder' ) . '</p>';
			$body .= self::repeater( 'hours_spec', (array) ( $own['hours_spec'] ?? [] ) );
		}
		$body .= self::fields_grid( [ 'is_24_7' ], $own, $res );
		if ( 'computed' === $res['hours']['from'] ) {
			/* translators: %s: the hours as one line */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site as: %s', 'launch-builder' ), $res['hours']['value'] ) ) . '</p>';
		} elseif ( 'saved' === $res['hours']['from'] ) {
			$body .= '<p class="lb-help lb-help--note">' . esc_html__( 'Your hours are saved as one line. Add opening hours above and they replace it.', 'launch-builder' ) . ' <code>' . esc_html( mb_substr( $res['hours']['value'], 0, 120 ) ) . '</code></p>';
		}
		return self::card( self::slug( 'Address and hours' ), __( 'Address and hours', 'launch-builder' ), esc_html__( 'Search engines and maps read these too, so fill in the separate fields.', 'launch-builder' ), $body );
	}

	private static function section_reviews( array $by, array $own, array $res ): string {
		$body = '<p class="lb-help lb-help--lead">' . esc_html__( 'Add one row for each site where you have reviews. Not sure of the numbers? Leave it empty. We never make up a rating.', 'launch-builder' ) . '</p>';
		if ( 'source' === ( $res['reviews']['from'] ?? '' ) ) {
			$body .= self::from_source( 'reviews', Company::fields()['reviews'], $res, true );
		} else {
			$body .= self::repeater( 'reviews', (array) ( $own['reviews'] ?? [] ) );
		}
		$d = Company::review_data();
		if ( null !== $d['rating'] || null !== $d['count'] ) {
			/* translators: 1: rating, 2: number of reviews */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site: %1$s stars from %2$s reviews.', 'launch-builder' ), null !== $d['rating'] ? number_format_i18n( $d['rating'], 1 ) : '–', null !== $d['count'] ? number_format_i18n( $d['count'] ) : '–' ) ) . '</p>';
			if ( 'computed' === $d['from'] ) { // Worked out from the rows, so it can differ from the number on any one site.
				$body .= '<p class="lb-help lb-help--note">' . esc_html__( 'We work this out from the rows above: the average of the ratings, counting sites with more reviews for more, and all the reviews added up. So it can differ from the rating on any one site.', 'launch-builder' ) . '</p>';
			}
			if ( empty( $res['reviews']['data'] ) ) { // A rating typed in an earlier version, with no review rows behind it.
				$body .= '<p class="lb-help lb-help--note">' . esc_html__( 'These numbers were typed in earlier and no review site backs them. Add your review sites above to work them out from those instead.', 'launch-builder' ) . '</p>';
			}
		} else {
			$body .= '<p class="lb-now">' . esc_html__( 'No rating yet. Rating widgets set to use Business Info show sample numbers to editors only until you add a review site.', 'launch-builder' ) . '</p>';
		}
		return self::card( self::slug( 'Reviews' ), __( 'Reviews', 'launch-builder' ), '', $body );
	}

	private static function section_social( array $by, array $own, array $res ): string {
		$body = self::fields_grid( [ 'facebook', 'instagram', 'youtube', 'google' ], $own, $res );
		$body .= self::fields_grid( [ 'other_profiles' ], $own, $res, [ 'other_profiles' ] );
		$body .= self::import_profiles();
		return self::card( self::slug( 'Social profiles' ), __( 'Social profiles', 'launch-builder' ), esc_html__( 'Full web addresses. A profile you leave empty is left out wherever it would show.', 'launch-builder' ), $body );
	}

	/** "Rank Math already lists N profiles. Import them?" when Rank Math holds profile addresses that Business Info does not. */
	private static function import_profiles(): string {
		$new = self::profiles_to_import();
		if ( ! $new ) {
			return '';
		}
		/* translators: %d: number of profile addresses */
		$say = sprintf( _n( 'Rank Math already lists %d profile that is not here.', 'Rank Math already lists %d profiles that are not here.', count( $new ), 'launch-builder' ), count( $new ) );
		return '<div class="lb-flash lb-flash--import"><p>' . esc_html( $say ) . '</p><button type="submit" form="lb-rm-form" name="do" value="import" class="lb-btn lb-btn--sm" data-lb-rm>' . Dashboard::icon( 'plus' ) . esc_html__( 'Import them', 'launch-builder' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
	}

	/** Profile addresses Rank Math holds that Business Info does not: [ 'facebook' => url|'', 'others' => url[] ]. */
	private static function profiles_to_import(): array {
		if ( ! Compat::rm_active() ) {
			return [];
		}
		$have = array_merge( Company::list_value( 'other_profiles' ), array_filter( array_map( static function ( $k ) { return (string) ( Company::values()[ $k ] ?? '' ); }, array_merge( [ 'facebook', 'instagram', 'youtube', 'google' ], array_keys( Company::PROFILE_HOSTS ) ) ), 'strlen' ) );
		$have = array_map( static function ( $u ) { return strtolower( rtrim( preg_replace( '#^https?://(www\.)?#i', '', (string) $u ), '/' ) ); }, $have );
		$out = [];
		foreach ( Compat::rm_profiles() as $u ) {
			if ( ! in_array( strtolower( rtrim( (string) preg_replace( '#^https?://(www\.)?#i', '', $u ), '/' ) ), $have, true ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	private static function section_buttons( array $by, array $own, array $res ): string {
		$body = self::fields_grid( [ 'cta_label', 'quote_url' ], $own, $res );
		$body .= self::fields_grid( [ 'form_html' ], $own, $res, [ 'form_html' ] );
		return self::card( self::slug( 'Buttons and form' ), __( 'Buttons and form', 'launch-builder' ), esc_html__( 'Your main button, and the form your contact pages show.', 'launch-builder' ), $body );
	}

	/* ------------------------------------------------------------------ the Rank Math tool */

	/** The Rank Math card: one sentence about where things stand, Fill and Undo, and the table of what would change behind "See what changes". */
	private static function section_rank_math(): string {
		$chips = Compat::rm_active() ? '<span class="lb-chip lb-chip--on">' . esc_html__( 'Rank Math is on', 'launch-builder' ) . '</span>' : '<span class="lb-chip lb-chip--plain">' . esc_html__( 'Rank Math is off', 'launch-builder' ) . '</span>';
		return '<section class="lb-panel lb-card--form" id="lb-rm"><div class="lb-panel__head"><h2>' . esc_html__( 'Rank Math', 'launch-builder' ) . '</h2>' . $chips . '</div>' . self::rank_math_panel() . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	private static function rank_math_panel(): string {
		if ( ! Compat::rm_active() ) {
			return '<p class="lb-help lb-help--lead">' . esc_html__( 'Rank Math is off. Turn it on and Business Info fills in its Local SEO settings. Without it, this site sends no business details to search engines.', 'launch-builder' ) . '</p>';
		}
		$rm = isset( $_GET['rm'] ) ? sanitize_key( wp_unslash( $_GET['rm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$sync = Compat::business_sync( [ 'dry_run' => true ] );
		$c = $sync['counts'];
		$todo = (int) ( $c['fill'] ?? 0 ) + (int) ( $c['update'] ?? 0 );
		if ( ! $sync['ready'] ) {
			$say = '<strong class="lb-help--warn">' . esc_html__( 'Rank Math\'s setup is not finished, so it prints nothing yet. Finish or skip its setup wizard first.', 'launch-builder' ) . '</strong>';
		} elseif ( $todo ) {
			/* translators: %d: number of Rank Math settings */
			$say = esc_html( sprintf( _n( 'Rank Math is missing %d setting that Business Info can fill in.', 'Rank Math is missing %d settings that Business Info can fill in.', $todo, 'launch-builder' ), $todo ) );
		} elseif ( ! empty( $c['yours'] ) ) {
			/* translators: %d: number of Rank Math settings the person set in Rank Math */
			$say = esc_html( sprintf( _n( 'Rank Math has what Business Info can give it. %d setting you set there is kept.', 'Rank Math has what Business Info can give it. %d settings you set there are kept.', (int) $c['yours'], 'launch-builder' ), (int) $c['yours'] ) );
		} else {
			$say = esc_html__( 'Rank Math already has everything Business Info can give it.', 'launch-builder' );
		}
		if ( $sync['ready'] && ! $sync['local_seo']['on'] ) {
			$say .= ' <strong class="lb-help--warn">' . esc_html__( 'Its Local SEO module is off, so it keeps your address, hours and phone numbers to itself.', 'launch-builder' ) . '</strong>';
		}
		$h = '<p class="lb-help lb-help--lead lb-rm__say">' . $say . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$last = $sync['last_fill'] ?? null;
		$h .= '<div class="lb-rm__actions"><button type="submit" form="lb-rm-form" name="do" value="apply" class="lb-btn lb-btn--primary" data-lb-rm>' . Dashboard::icon( 'check' ) . esc_html__( 'Fill Rank Math', 'launch-builder' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		if ( $last ) {
			/* translators: %s: date and time of the last fill */
			$h .= '<button type="submit" form="lb-rm-form" name="do" value="undo" class="lb-btn" data-lb-rm>' . Dashboard::icon( 'undo' ) . esc_html( sprintf( __( 'Undo the fill from %s', 'launch-builder' ), wp_date( 'M j, g:ia', (int) $last['time'] ) ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}
		$h .= '</div><p class="lb-help lb-rm__dirty" id="lb-rm-dirty" hidden></p>';
		$h .= '<details class="lb-disclose lb-rm__see" id="lb-rm-see"' . ( in_array( $rm, [ 'preview', 'done' ], true ) ? ' open' : '' ) . '><summary>' . Dashboard::icon( 'chevron' ) . esc_html__( 'See what changes', 'launch-builder' ) . '</summary>' . self::rank_math_table( $sync );
		$h .= '<h4>' . esc_html__( 'What Rank Math cannot take from Business Info', 'launch-builder' ) . '</h4><ul class="lb-bullets">';
		foreach ( $sync['cannot'] as $line ) {
			$h .= '<li>' . esc_html( $line ) . '</li>';
		}
		return $h . '</ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/** The table behind "See what changes": one row per Rank Math setting. Fill Rank Math (above) applies it. */
	private static function rank_math_table( array $sync ): string {
		$say = [
			'fill'   => [ __( 'Will be filled in', 'launch-builder' ), 'warn' ],
			'update' => [ __( 'Will be updated', 'launch-builder' ), 'warn' ],
			'yours'  => [ __( 'You set this: kept', 'launch-builder' ), 'plain' ],
			'match'  => [ __( 'Already matches', 'launch-builder' ), 'ok' ],
			'empty'  => [ __( 'Nothing to send yet', 'launch-builder' ), 'plain' ],
		];
		$h = '<div class="lb-rm__preview"><p class="lb-help">' . esc_html__( 'Business Info can fill in Rank Math\'s Local SEO settings for you. It never replaces what you typed in Rank Math unless you tick the box. Nothing is written until you press Fill Rank Math.', 'launch-builder' ) . '</p>';
		$h .= '<div class="lb-tablewrap"><table class="lb-table lb-rmtable"><thead><tr><th>' . esc_html__( 'Rank Math setting', 'launch-builder' ) . '</th><th>' . esc_html__( 'Comes from', 'launch-builder' ) . '</th><th>' . esc_html__( 'In Rank Math now', 'launch-builder' ) . '</th><th>' . esc_html__( 'Rank Math will get', 'launch-builder' ) . '</th><th>' . esc_html__( 'What happens', 'launch-builder' ) . '</th></tr></thead><tbody>';
		foreach ( $sync['rows'] as $row ) {
			$from = $row['from'] ? implode( ', ', array_map( static function ( $f ) { return $f['label']; }, $row['from'] ) ) : ( '' !== $row['from_text'] ? $row['from_text'] : __( 'Nothing filled in yet', 'launch-builder' ) );
			$st = $say[ $row['status'] ];
			$cell = '<span class="lb-chip lb-chip--' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span>';
			if ( 'yours' === $row['status'] ) {
				$cell .= '<label class="lb-check lb-check--sm"><input type="checkbox" form="lb-rm-form" name="rm_force[]" value="' . esc_attr( $row['id'] ) . '"> <span>' . esc_html__( 'Replace it with Business Info', 'launch-builder' ) . '</span></label>';
			}
			$h .= '<tr data-status="' . esc_attr( $row['status'] ) . '"><th scope="row">' . esc_html( $row['label'] ) . '</th>';
			$h .= '<td data-label="' . esc_attr__( 'Comes from', 'launch-builder' ) . '">' . esc_html( $from ) . '</td>';
			$h .= '<td data-label="' . esc_attr__( 'In Rank Math now', 'launch-builder' ) . '">' . ( '' !== $row['current'] ? '<code>' . esc_html( mb_substr( $row['current'], 0, 120 ) ) . '</code>' : '<span class="lb-muted">' . esc_html__( 'Empty', 'launch-builder' ) . '</span>' ) . '</td>';
			$h .= '<td data-label="' . esc_attr__( 'Rank Math will get', 'launch-builder' ) . '">' . ( 'match' === $row['status'] ? '<span class="lb-muted">' . esc_html__( 'Same', 'launch-builder' ) . '</span>' : ( '' !== $row['wanted'] ? '<code>' . esc_html( mb_substr( $row['wanted'], 0, 120 ) ) . '</code>' : '<span class="lb-muted">–</span>' ) ) . '</td>';
			$h .= '<td data-label="' . esc_attr__( 'What happens', 'launch-builder' ) . '">' . $cell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		$h .= '</tbody></table></div>';
		if ( ! $sync['local_seo']['on'] ) {
			$h .= '<label class="lb-check"><input type="checkbox" form="lb-rm-form" name="rm_local" value="1" checked> <span>' . esc_html__( 'Also turn on Rank Math\'s Local SEO module (Rank Math needs it to print your address, hours and phone numbers).', 'launch-builder' ) . '</span></label>';
		}
		foreach ( (array) $sync['warnings'] as $w ) {
			$h .= '<p class="lb-help">' . esc_html( (string) $w ) . '</p>';
		}
		return $h . '</div>';
	}

	/* ------------------------------------------------------------------ Meta Box panel */

	/** Where the note about the last source change is kept (so the Undo button survives the redirect). */
	const OPT_UNDO = 'launch_builder_company_mb_undo';

	/** How long the Undo note stays: later it would put back a source that other changes have moved on from. */
	const UNDO_TTL = DAY_IN_SECONDS;

	/** @var bool true while the panel changes the source itself (use, stop, match more), so its own Undo note is kept. */
	private static $own_change = false;

	/** Is the panel changing the source right now? Any other change of the source removes the Undo note (Company::option_changed()). */
	public static function own_change(): bool {
		return self::$own_change;
	}

	/** The Undo note when it is still good: a snapshot that exists, made less than UNDO_TTL ago. */
	private static function undo_note(): ?array {
		$u = get_option( self::OPT_UNDO );
		if ( is_array( $u ) && ! empty( $u['id'] ) && time() - (int) ( $u['time'] ?? 0 ) < self::UNDO_TTL && Abilities::get_snapshot( (string) $u['id'] ) ) {
			return $u;
		}
		if ( false !== $u ) {
			delete_option( self::OPT_UNDO );
		}
		return null;
	}

	/** admin-post: use a settings page as the source, stop using it, or put the last change back. */
	public static function mb_action(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_company_mb', 'lb_mb_nonce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified above; every value is checked against what Meta Box reports before it is used.
		$do = sanitize_key( (string) ( $post['do'] ?? '' ) );
		if ( 'undo' === $do ) {
			self::remember( self::mb_undo() );
		} elseif ( 'stop' === $do ) {
			self::remember( self::mb_change( '', [], false ) );
		} elseif ( 'broaden' === $do ) {
			self::remember( self::mb_broaden() );
		} elseif ( 'use' === $do ) {
			$option = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $post['option'] ?? '' ) );
			$offered = array_column( Company::meta_box_pages(), 'option' );
			$cur = (array) get_option( Company::OPT_SRC, [] );
			if ( ! in_array( $option, $offered, true ) && $option !== (string) ( $cur['option'] ?? '' ) && ! in_array( $option, array_column( Company::candidate_options(), 'option' ), true ) ) {
				self::remember( [ 'kind' => 'bad', 'text' => __( 'That settings page is not one Launch Builder found, so nothing was changed.', 'launch-builder' ), 'lines' => [] ] );
			} else {
				$net = false;
				foreach ( Company::meta_box_pages() as $p ) {
					$net = $net || ( $p['option'] === $option && $p['network'] );
				}
				$map = [];
				foreach ( (array) ( $post['keep'] ?? [] ) as $bi => $sid ) { // Choices made earlier (or in More options) stay.
					$map[ sanitize_key( (string) $bi ) ] = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sid );
				}
				foreach ( (array) ( $post['map'] ?? [] ) as $sid => $bi ) { // The field choices made in this panel: source field => Business Info key.
					$bi = sanitize_key( (string) $bi );
					$sid = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sid );
					if ( '' !== $bi && '' !== $sid && ! isset( $map[ $bi ] ) ) {
						$map[ $bi ] = $sid;
					}
				}
				self::remember( self::mb_change( $option, array_intersect_key( $map, Company::fields() ), $net ) );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE ], admin_url( 'admin.php' ) ) . '#lb-mb' );
		exit;
	}

	/** Point Business Info at a settings option ('' = stop), after a snapshot, and describe the result for the person. */
	private static function mb_change( string $option, array $map, bool $network ): array {
		$snap = Abilities::snapshot( 'meta-box-source', [], [ Company::OPT_SRC ] );
		self::$own_change = true;
		try {
			$r = Company::set_source( $option, $map, $network );
		} finally {
			self::$own_change = false;
		}
		if ( is_wp_error( $r ) ) {
			return [ 'kind' => 'bad', 'text' => __( 'That option can\'t be used as a source, so nothing was changed.', 'launch-builder' ), 'lines' => [] ];
		}
		update_option( self::OPT_UNDO, [ 'id' => $snap, 'time' => time() ], false );
		Cache::flush();
		if ( '' === $option ) {
			return [ 'kind' => 'ok', 'text' => __( 'Business Info no longer reads from a settings page. The values saved here show again.', 'launch-builder' ), 'lines' => [] ];
		}
		$all = Company::fields();
		$names = [];
		foreach ( array_keys( Company::source()['values'] ) as $k ) {
			$names[] = $all[ $k ][0];
		}
		/* translators: %s: the settings page name */
		$text = sprintf( __( 'Business Info now reads from %s.', 'launch-builder' ), Company::source_label() );
		$lines = $names
			/* translators: %s: Business Info field names */
			? [ sprintf( __( 'These fields now come from there: %s.', 'launch-builder' ), implode( ', ', $names ) ) ]
			: [ __( 'It filled no field yet. Pick a Business Info field for each of its fields below, or check that the page holds saved values.', 'launch-builder' ) ];
		return [ 'kind' => $names ? 'ok' : 'warn', 'text' => $text, 'lines' => $lines ];
	}

	/** Match more fields: the saved source reads the broader field names from now on (after a snapshot, so Undo works). */
	private static function mb_broaden(): array {
		$before = array_keys( Company::source()['values'] );
		$snap = Abilities::snapshot( 'meta-box-source', [], [ Company::OPT_SRC ] );
		self::$own_change = true;
		try {
			$done = Company::broaden_source();
		} finally {
			self::$own_change = false;
		}
		if ( ! $done ) {
			return [ 'kind' => 'warn', 'text' => __( 'Nothing to change: Business Info already matches more fields.', 'launch-builder' ), 'lines' => [] ];
		}
		update_option( self::OPT_UNDO, [ 'id' => $snap, 'time' => time() ], false );
		Cache::flush();
		$all = Company::fields();
		$names = [];
		foreach ( array_diff( array_keys( Company::source()['values'] ), $before ) as $k ) {
			$names[] = $all[ $k ][0];
		}
		return [
			'kind'  => 'ok',
			'text'  => __( 'Business Info now matches more field names.', 'launch-builder' ),
			/* translators: %s: Business Info field names */
			'lines' => $names ? [ sprintf( __( 'These fields now come from the page too: %s.', 'launch-builder' ), implode( ', ', $names ) ) ] : [ __( 'No other field matched.', 'launch-builder' ) ],
		];
	}

	private static function mb_undo(): array {
		$u = self::undo_note();
		$id = is_array( $u ) ? (string) $u['id'] : '';
		if ( '' === $id ) {
			return [ 'kind' => 'warn', 'text' => __( 'There is nothing to undo any more.', 'launch-builder' ), 'lines' => [] ];
		}
		$r = Abilities::restore_snapshot( [ 'id' => $id ] );
		delete_option( self::OPT_UNDO );
		Cache::flush();
		return is_wp_error( $r )
			? [ 'kind' => 'bad', 'text' => $r->get_error_message(), 'lines' => [] ]
			: [ 'kind' => 'ok', 'text' => __( 'Put back how Business Info read its values before.', 'launch-builder' ), 'lines' => [] ];
	}

	/** The small form that carries the nonce and what to do (use / stop / undo). */
	private static function mb_form_open( string $do, string $option = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-mb__form"><input type="hidden" name="action" value="lb_company_mb">' . ( '' !== $do ? '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">' : '' ) // A form with several buttons has no hidden "do": each button says what it does.
			. ( '' !== $option ? '<input type="hidden" name="option" value="' . esc_attr( $option ) . '">' : '' )
			. wp_nonce_field( 'lb_company_mb', 'lb_mb_nonce', true, false );
	}

	/** Should the Meta Box panel show? When Meta Box is on, or a source is saved (so it can be stopped). */
	private static function mb_panel_wanted( array $o ): bool {
		$src = (array) get_option( Company::OPT_SRC, [] );
		return $o['active'] || $o['pages'] || '' !== (string) ( $src['option'] ?? '' );
	}

	/**
	 * The Meta Box panel: whether Meta Box is on, every settings page it found with its option name and fields, which Business
	 * Info field each would fill, one button to use a page as the source (a confirm step first, an Undo afterwards), and, when
	 * it finds nothing, what Launch Builder looked for.
	 */
	private static function meta_box_panel(): string {
		$o = Company::meta_box_overview( true );
		if ( ! self::mb_panel_wanted( $o ) ) {
			return '';
		}
		$labels = [];
		foreach ( Company::fields() as $k => $f ) {
			$labels[ $k ] = $f[0];
		}
		$src = (array) get_option( Company::OPT_SRC, [] );
		$chips = $o['active']
			/* translators: %s: Meta Box version */
			? '<span class="lb-chip lb-chip--ok">' . esc_html( '' !== $o['version'] ? sprintf( __( 'Meta Box %s is active', 'launch-builder' ), $o['version'] ) : __( 'Meta Box is active', 'launch-builder' ) ) . '</span>'
			: '<span class="lb-chip lb-chip--warn">' . esc_html__( 'Meta Box is not active', 'launch-builder' ) . '</span>';
		if ( '' !== (string) ( $src['option'] ?? '' ) ) {
			$chips .= '<span class="lb-chip lb-chip--on">' . esc_html( sprintf( /* translators: %s: settings page name */ __( 'Reading from %s', 'launch-builder' ), Company::source_label() ) ) . '</span>';
		}
		$h = '<section class="lb-panel lb-mb" id="lb-mb"><div class="lb-panel__head"><h2>' . esc_html__( 'Meta Box', 'launch-builder' ) . '</h2><span class="lb-mb__chips">' . $chips . '</span></div>';
		$h .= '<p class="lb-help lb-help--lead">' . esc_html__( 'If your business facts live on a Meta Box settings page, Launch Builder can read them from there. Pick the page below and Business Info shows its values, so you only keep them in one place.', 'launch-builder' ) . '</p>';

		$undo = self::undo_note();
		if ( $undo ) {
			/* translators: %s: how long ago, for example "5 minutes" */
			$h .= '<div class="lb-flash"><p>' . esc_html( sprintf( __( 'You changed where Business Info reads from %s ago.', 'launch-builder' ), human_time_diff( (int) ( $undo['time'] ?? time() ) ) ) ) . '</p>' . self::mb_form_open( 'undo' ) . '<button type="submit" class="lb-btn lb-btn--sm">' . Dashboard::icon( 'undo' ) . esc_html__( 'Undo', 'launch-builder' ) . '</button></form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}

		foreach ( $o['pages'] as $p ) {
			$h .= self::mb_page( $p, $labels, $src );
		}
		if ( $o['active'] && ! $o['pages'] ) {
			$h .= self::mb_nothing( $o );
		} elseif ( ! $o['active'] && '' !== (string) ( $src['option'] ?? '' ) ) {
			$h .= '<p class="lb-help">' . esc_html( sprintf( /* translators: %s: option name */ __( 'Meta Box is not active, so Business Info reads the saved option %s directly. Turn Meta Box on to see its settings pages here.', 'launch-builder' ), (string) $src['option'] ) ) . '</p>';
		}
		if ( $o['pages'] ) { // What was looked at, so it can be compared with what is on the site.
			$h .= '<p class="lb-help lb-mb__looked">' . esc_html( sprintf( /* translators: 1: number of pages, 2: loaded or not loaded */ __( 'Launch Builder asked Meta Box for settings pages (the mb_settings_pages list) and found %1$d. Settings Page add-on: %2$s.', 'launch-builder' ), count( $o['pages'] ), $o['extension'] ? __( 'loaded', 'launch-builder' ) : __( 'not seen as a separate add-on', 'launch-builder' ) ) ) . '</p>';
		}
		return $h . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/** One settings page: its option, how many fields, a table of what each field fills, and the button. */
	private static function mb_page( array $p, array $labels, array $src ): string {
		$names = array_map( static function ( $k ) use ( $labels ) { return $labels[ $k ] ?? $k; }, array_keys( $p['filled'] ) );
		$h = '<div class="lb-mbpage' . ( $p['in_use'] ? ' is-source' : '' ) . '"><div class="lb-mbpage__head"><div><h3>' . esc_html( $p['title'] ) . '</h3><p class="lb-help">';
		$bits = [
			'<code>' . esc_html( $p['option'] ) . '</code>',
			esc_html( sprintf( /* translators: %d: number of fields */ _n( '%d field', '%d fields', count( $p['fields'] ), 'launch-builder' ), count( $p['fields'] ) ) ),
			esc_html( sprintf( /* translators: %d: number of Business Info fields */ _n( 'Fills %d Business Info field', 'Fills %d Business Info fields', $p['count'], 'launch-builder' ), $p['count'] ) ),
		];
		if ( '' !== $p['url'] ) {
			$bits[] = '<a href="' . esc_url( $p['url'] ) . '">' . esc_html__( 'Open the page', 'launch-builder' ) . '</a>';
		}
		$h .= implode( ' &middot; ', $bits );
		$h .= '</p></div>' . ( $p['in_use'] ? '<span class="lb-chip lb-chip--ok">' . esc_html__( 'Business Info reads from this page', 'launch-builder' ) . '</span>' : '' ) . '</div>';
		if ( ! $p['saved'] ) {
			$h .= '<p class="lb-help lb-help--warn">' . esc_html__( 'Nothing is saved on this page yet. Fill it in and press its Save button in Meta Box first, then come back.', 'launch-builder' ) . '</p>';
		}
		if ( ! $p['fields'] ) {
			return $h . '<p class="lb-help">' . esc_html__( 'Meta Box did not report any fields for this page.', 'launch-builder' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		$h .= self::mb_form_open( '', $p['option'] );
		foreach ( (array) ( $p['in_use'] ? ( $src['map'] ?? [] ) : [] ) as $bi => $sid ) { // Field choices made earlier stay.
			$h .= '<input type="hidden" name="keep[' . esc_attr( (string) $bi ) . ']" value="' . esc_attr( (string) $sid ) . '">';
		}
		$h .= '<div class="lb-tablewrap"><table class="lb-table lb-rmtable lb-mbtable"><thead><tr><th>' . esc_html__( 'Meta Box field', 'launch-builder' ) . '</th><th>' . esc_html__( 'Fills in Business Info', 'launch-builder' ) . '</th><th>' . esc_html__( 'Value on the page', 'launch-builder' ) . '</th></tr></thead><tbody>';
		$opts = '<option value="">' . esc_html__( 'Not used', 'launch-builder' ) . '</option>';
		$extra = '';
		foreach ( Company::fields() as $k => $f ) { // The fields with no input here can still be filled from a settings page.
			if ( Company::on_screen( $k ) ) {
				$opts .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</option>';
			} else {
				$extra .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</option>';
			}
		}
		$opts .= '<optgroup label="' . esc_attr__( 'Other details', 'launch-builder' ) . '">' . $extra . '</optgroup>';
		foreach ( $p['fields'] as $row ) {
			$fills = '';
			if ( $row['fills'] ) {
				foreach ( $row['fills'] as $bi ) {
					$fills .= '<span class="lb-chip lb-chip--' . ( 'filled' === $row['state'] ? 'ok' : 'warn' ) . '">' . esc_html( $labels[ $bi ] ?? $bi ) . '</span> ';
				}
				if ( 'empty' === $row['state'] ) {
					$fills .= '<span class="lb-help">' . esc_html__( 'empty on the page', 'launch-builder' ) . '</span>';
				}
			} else {
				$fills = '<label class="lb-mbpick"><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: Meta Box field name */ __( 'Business Info field for %s', 'launch-builder' ), $row['name'] ) ) . '</span><select name="map[' . esc_attr( $row['id'] ) . ']">' . $opts . '</select></label>';
			}
			$h .= '<tr data-state="' . esc_attr( $row['state'] ) . '"><th scope="row">' . esc_html( $row['name'] ) . ' <code>' . esc_html( $row['id'] ) . '</code></th><td data-label="' . esc_attr__( 'Fills in Business Info', 'launch-builder' ) . '">' . $fills . '</td><td data-label="' . esc_attr__( 'Value on the page', 'launch-builder' ) . '">' . ( '' !== $row['value'] ? esc_html( $row['value'] ) : '<span class="lb-muted">–</span>' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div>';
		if ( $p['skipped'] ) {
			$h .= '<div class="lb-flash lb-flash--warn"><div><p>' . esc_html__( 'Some values on that page could not be used:', 'launch-builder' ) . '</p><ul class="lb-bullets">';
			foreach ( $p['skipped'] as $s ) {
				$h .= '<li><code>' . esc_html( $s['source_key'] ) . '</code> &rarr; ' . esc_html( $labels[ $s['key'] ] ?? $s['key'] ) . ': ' . esc_html( $s['reason'] ) . '</li>';
			}
			$h .= '</ul></div></div>';
		}
		if ( $p['in_use'] ) {
			if ( ! empty( $p['more'] ) ) { // A source saved before the broader field names: it keeps its older matching until someone chooses.
				$more = implode( ', ', array_map( static function ( $k ) use ( $labels ) { return $labels[ $k ] ?? $k; }, $p['more'] ) );
				/* translators: %s: Business Info field names */
				$h .= '<div class="lb-flash"><div><p>' . esc_html( sprintf( __( 'Business Info still matches field names the way it did when you connected this page. Match more fields to also use the page for: %s.', 'launch-builder' ), $more ) ) . '</p><button type="submit" name="do" value="broaden" class="lb-btn lb-btn--sm">' . esc_html__( 'Match more fields', 'launch-builder' ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			$h .= '<p class="lb-help">' . wp_kses( __( 'In your page text, <code>{{meta.field_id}}</code> shows a field of this page. Tokens read only this page, not your other settings pages.', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';
			$h .= '<div class="lb-mb__actions"><button type="submit" name="do" value="use" class="lb-btn lb-btn--primary">' . Dashboard::icon( 'check' ) . esc_html__( 'Save field choices', 'launch-builder' ) . '</button><button type="submit" name="do" value="stop" class="lb-btn lb-btn--ghost">' . esc_html__( 'Stop reading from this page', 'launch-builder' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		} else {
			$what = $names ? implode( ', ', $names ) : __( 'none yet (pick a Business Info field for the ones that did not match)', 'launch-builder' );
			$h .= '<details class="lb-confirm"><summary class="lb-btn lb-btn--primary">' . Dashboard::icon( 'bolt' ) . esc_html__( 'Use this page as the source', 'launch-builder' ) . '</summary><div class="lb-confirm__body"><p>' . esc_html( sprintf( /* translators: %s: Business Info field names */ __( 'Business Info will show the values from this page for: %s. What you typed here stays saved and shows again if you stop. You can undo this straight afterwards.', 'launch-builder' ), $what ) ) . '</p><button type="submit" name="do" value="use" class="lb-btn lb-btn--primary">' . esc_html__( 'Yes, use this page', 'launch-builder' ) . '</button></div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}
		return $h . '</form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/** Meta Box is on but no settings page was found: say what was looked for, and offer saved options that look like business facts. */
	private static function mb_nothing( array $o ): string {
		$h = '<div class="lb-flash lb-flash--warn"><div><p><strong>' . esc_html__( 'Meta Box is on, but Launch Builder found no settings pages.', 'launch-builder' ) . '</strong></p><p>' . esc_html__( 'It looked for:', 'launch-builder' ) . '</p><ul class="lb-bullets">';
		$h .= '<li>' . esc_html__( 'Settings pages in the list Meta Box keeps (the mb_settings_pages filter, which pages built in Meta Box > Settings Pages and pages added by code both use): none.', 'launch-builder' ) . '</li>';
		$h .= '<li>' . esc_html( sprintf( /* translators: %s: loaded or not */ __( 'The Settings Page add-on: %s.', 'launch-builder' ), $o['extension'] ? __( 'loaded', 'launch-builder' ) : __( 'not seen as a separate add-on (Meta Box AIO bundles it, so this can be normal)', 'launch-builder' ) ) ) . '</li>';
		$h .= '<li>' . esc_html( sprintf( /* translators: %d: number of fields */ __( 'Field groups attached to a settings page: %d fields.', 'launch-builder' ), $o['registered'] ) ) . '</li>';
		$h .= '<li>' . esc_html__( 'Saved options with four or more names that look like business details (phone, email, address, hours…):', 'launch-builder' ) . ' ' . esc_html( $o['candidates'] ? implode( ', ', array_column( $o['candidates'], 'option' ) ) : __( 'none', 'launch-builder' ) ) . '.</li></ul>';
		$h .= '<p>' . esc_html__( 'If your settings page exists, send us its option name and field ids (Meta Box > Tools > Export gives both) and we will match them.', 'launch-builder' ) . '</p></div></div>';
		foreach ( $o['candidates'] as $c ) {
			$h .= '<div class="lb-mbpage"><div class="lb-mbpage__head"><div><h3>' . esc_html( $c['option'] ) . '</h3><p class="lb-help">' . esc_html( sprintf( /* translators: %d: number of fields */ _n( 'Looks like business details: %d field matches.', 'Looks like business details: %d fields match.', $c['count'], 'launch-builder' ), $c['count'] ) ) . '</p></div>' . self::mb_form_open( '', $c['option'] ) . '<button type="submit" name="do" value="use" class="lb-btn">' . esc_html__( 'Use this option as the source', 'launch-builder' ) . '</button></form></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		return $h;
	}

	/** A value that comes from the source: shown as it is with a note, not as an input (editing it here would do nothing). */
	private static function from_source( string $k, array $f, array $res, bool $wide ): string {
		$type = $f[1];
		$val = (string) ( $res[ $k ]['value'] ?? '' );
		$mb = (bool) Company::source()['meta_box'];
		$chip = '<span class="lb-chip lb-chip--src">' . esc_html( $mb ? __( 'From Meta Box', 'launch-builder' ) : __( 'From another settings page', 'launch-builder' ) ) . '</span>';
		if ( 'image' === $type ) {
			$shown = '' !== $val ? '<span class="lb-image__preview"><img src="' . esc_url( $val ) . '" alt=""></span>' : '';
		} elseif ( 'bool' === $type ) {
			$shown = '<span class="lb-src__value">' . esc_html( '1' === $val ? __( 'Yes', 'launch-builder' ) : __( 'No', 'launch-builder' ) ) . '</span>';
		} else {
			$shown = '<span class="lb-src__value">' . esc_html( mb_substr( $val, 0, 160 ) ) . '</span>';
		}
		$url = Company::source_url();
		$edit = '' !== $url ? '<a class="lb-link" href="' . esc_url( $url ) . '">' . esc_html( sprintf( /* translators: %s: settings page name, for example Company Info */ __( 'Edit in %s', 'launch-builder' ), Company::source_label() ) ) . '</a>. ' : '';
		$help = $edit . ( '' !== $url
			? sprintf( /* translators: %s: link to the Meta Box panel */ esc_html__( 'To type it here instead, stop reading from that page under %s.', 'launch-builder' ), '<a href="#lb-mb">' . esc_html__( 'Meta Box', 'launch-builder' ) . '</a>' )
			: esc_html__( 'To type it here instead, stop reading from that page under More options.', 'launch-builder' ) );
		return '<div class="lb-field lb-field--src' . ( $wide || in_array( $type, [ 'html', 'textarea', 'list' ], true ) ? ' lb-field--wide' : '' ) . '" data-key="' . esc_attr( $k ) . '" data-from="source"><span class="lb-field__label">' . esc_html( $f[0] ) . '</span><div class="lb-src">' . $shown . $chip . '</div><p class="lb-help">' . wp_kses( $help, [ 'a' => [ 'href' => [], 'class' => [] ] ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/* ------------------------------------------------------------------ More options */

	private static function section_more( array $by, array $own, array $res ): string {
		$groups = [
			'More address'       => [ __( 'Country and second office', 'launch-builder' ), __( 'Fill these in only if they apply. A second office address also fills the second map.', 'launch-builder' ) ],
			'Wording and links' => [ __( 'Links and wording', 'launch-builder' ), __( 'Each of these has a sensible fallback, so leave them empty unless you want something different.', 'launch-builder' ) ],
			'Offers'            => [ __( 'Promotion', 'launch-builder' ), __( 'One short line that a notice bar can show. Leave it empty for no promotion.', 'launch-builder' ) ],
		];
		$body = '';
		foreach ( Company::MORE_GROUPS as $g ) {
			if ( empty( $by[ $g ] ) ) {
				continue;
			}
			$body .= '<section class="lb-more__group" id="' . esc_attr( self::slug( $g ) ) . '"><h3 class="lb-subhead">' . esc_html( $groups[ $g ][0] ) . '</h3><p class="lb-help lb-help--lead">' . esc_html( $groups[ $g ][1] ) . '</p>' . self::fields_grid( $by[ $g ], $own, $res ) . '</section>';
		}
		$body .= self::source_group();
		$body .= self::off_screen_note( $by, $own );
		return '<details class="lb-panel lb-more" id="lb-g-more" data-lb-more><summary><span class="lb-more__title"><h2>' . esc_html__( 'More options', 'launch-builder' ) . '</h2><span class="lb-help">' . esc_html__( 'Country, a second office, extra links, the area you serve and a promotion line. You can skip all of these.', 'launch-builder' ) . '</span></span>' . Dashboard::icon( 'chevron' ) . '</summary><div class="lb-more__body">' . $body . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/**
	 * Reading the values from another settings page: a drop-down of the Meta Box settings pages (and the page already in use).
	 * Shown only when there is a page to pick or a source to stop; the Meta Box panel above does the same with more detail.
	 */
	private static function source_group(): string {
		$src = (array) get_option( Company::OPT_SRC, [] );
		$pages = Company::meta_box_pages();
		$cur = (string) ( $src['option'] ?? '' );
		if ( ! $pages && '' === $cur ) {
			return '';
		}
		$read = Company::source();
		$lines = [];
		foreach ( (array) ( $src['map'] ?? [] ) as $k => $v ) {
			$lines[] = $k . ' = ' . $v;
		}
		$opts = '<option value="">' . esc_html__( 'None. Use the values saved here', 'launch-builder' ) . '</option>';
		$known = [];
		foreach ( $pages as $page ) {
			$known[] = $page['option'];
			$opts .= '<option value="' . esc_attr( $page['option'] ) . '"' . selected( $cur, $page['option'], false ) . '>' . esc_html( $page['title'] . ' (' . $page['option'] . ')' ) . '</option>';
		}
		if ( '' !== $cur && ! in_array( $cur, $known, true ) ) { // A source that is not a Meta Box page (set by a tool): keep it, so saving does not drop it.
			$opts .= '<option value="' . esc_attr( $cur ) . '" selected>' . esc_html( $cur ) . '</option>';
		}
		$h = '<section class="lb-more__group" id="lb-g-source"><h3 class="lb-subhead">' . esc_html__( 'Read from another settings page', 'launch-builder' ) . '</h3>';
		$h .= '<p class="lb-help lb-help--lead">' . esc_html__( 'Keep these details on another settings page, like a Meta Box page? Pick it here and Business Info reads from it.', 'launch-builder' ) . '</p>';
		$h .= '<div class="lb-fields"><div class="lb-field lb-field--wide"><label for="lb-source">' . esc_html__( 'Settings page', 'launch-builder' ) . '</label><select id="lb-source" name="lb_source">' . $opts . '</select></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		if ( is_multisite() ) {
			$h .= '<div class="lb-field lb-field--wide lb-field--check"><label class="lb-check"><input type="checkbox" name="lb_source_network" value="1"' . checked( ! empty( $src['network'] ), true, false ) . '> <span>' . esc_html__( 'Network option (shared by every site)', 'launch-builder' ) . '</span></label></div>';
		}
		$h .= '</div>';
		$h .= '<details class="lb-disclose"' . ( $lines ? ' open' : '' ) . '><summary>' . Dashboard::icon( 'chevron' ) . esc_html__( 'Match fields by hand', 'launch-builder' ) . '</summary><div class="lb-fields"><div class="lb-field lb-field--wide"><label for="lb-map">' . esc_html__( 'Field names', 'launch-builder' ) . '</label><textarea id="lb-map" name="lb_map" rows="3" class="code" placeholder="phone = phone_number" spellcheck="false" aria-describedby="lb-map-help">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea><p class="lb-help" id="lb-map-help">' . wp_kses( __( 'Fields are matched by name already. Add one line for any that are not, like <code>phone = my_phone_field</code>.', 'launch-builder' ), [ 'code' => [] ] ) . '</p></div></div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Dashboard::icon is static markup.
		if ( $read['skipped'] ) {
			$h .= '<div class="lb-flash lb-flash--warn"><div><p>' . esc_html__( 'Some values on that page could not be used:', 'launch-builder' ) . '</p><ul class="lb-bullets">';
			foreach ( $read['skipped'] as $sk ) {
				$h .= '<li><code>' . esc_html( $sk['source_key'] ) . '</code> &rarr; ' . esc_html( $sk['key'] ) . ': ' . esc_html( $sk['reason'] ) . '</li>';
			}
			$h .= '</ul></div></div>';
		}
		return $h . '</section>';
	}

	/** A line about saved values that have no field here (holiday hours, the Google place ID). */
	private static function off_screen_note( array $by, array $own ): string {
		$saved = [];
		$all = Company::fields();
		foreach ( (array) ( $by[ Company::OFF_SCREEN ] ?? [] ) as $k ) {
			if ( isset( $own[ $k ] ) && ( is_array( $own[ $k ] ) ? [] !== $own[ $k ] : '' !== trim( (string) $own[ $k ] ) ) ) {
				$saved[] = $all[ $k ][0];
			}
		}
		if ( ! $saved ) {
			return '';
		}
		/* translators: %s: comma-separated names of saved values */
		return '<p class="lb-help lb-help--note">' . esc_html( sprintf( __( 'Also saved, with no field on this screen: %s. They still work in your pages, and the Launch Builder tools can change them.', 'launch-builder' ), implode( ', ', $saved ) ) ) . '</p>';
	}

	/* ------------------------------------------------------------------ using the values in text */

	/** A short, collapsed guide: the common tokens first (click to copy), the rest behind a second disclosure. */
	private static function section_tokens( array $all ): string {
		$labels = [];
		foreach ( $all as $k => $f ) {
			$labels[ $k ] = [ (string) $f[0], 'url' === $f[1] && Company::is_link_key( $k ) ];
		}
		foreach ( Company::computed() as $k => $d ) {
			$labels[ $k ] = [ (string) $d, false ];
		}
		$item = static function ( string $k ) use ( $labels ): string {
			$h = '<li><span class="lb-tokens__chips"><button type="button" class="lb-chip lb-chip--plain lb-tokenchip" data-lb-token="' . esc_attr( '{{lb.' . $k . '}}' ) . '" title="' . esc_attr__( 'Copy', 'launch-builder' ) . '">{{lb.' . esc_html( $k ) . '}}</button>';
			if ( $labels[ $k ][1] ) {
				$h .= '<button type="button" class="lb-chip lb-chip--plain lb-tokenchip" data-lb-token="' . esc_attr( '#lb-' . $k ) . '" title="' . esc_attr__( 'Copy', 'launch-builder' ) . '">#lb-' . esc_html( $k ) . '</button>';
			}
			return $h . '</span><span class="lb-tokens__what">' . esc_html( $labels[ $k ][0] ) . '</span></li>';
		};
		$common = array_values( array_filter( Company::COMMON, static function ( $k ) use ( $labels ) { return isset( $labels[ $k ] ); } ) );
		$adv = array_values( array_diff( array_keys( $labels ), $common ) );
		$h = '<details class="lb-panel lb-tokens"><summary><span class="lb-more__title"><h2>' . esc_html__( 'Use these in your own text', 'launch-builder' ) . '</h2><span class="lb-help">' . esc_html__( 'Patterns already insert your details. This is for text you write yourself.', 'launch-builder' ) . '</span></span>' . Dashboard::icon( 'chevron' ) . '</summary><div class="lb-more__body">'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		$h .= '<p class="lb-help lb-help--lead">' . wp_kses( __( 'Type <code>{{lb.phone}}</code> in your text, or <code>#lb-phone_link</code> in a link. The page shows your current phone number. Click a token to copy it.', 'launch-builder' ), [ 'code' => [] ] ) . '</p><ul class="lb-tokens__list">';
		foreach ( $common as $k ) {
			$h .= $item( $k ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		$h .= '</ul>';
		/* translators: %d: number of tokens */
		$h .= '<details class="lb-disclose"><summary>' . Dashboard::icon( 'chevron' ) . esc_html( sprintf( __( 'Show %d more tokens', 'launch-builder' ), count( $adv ) ) ) . '</summary><ul class="lb-tokens__list">'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		foreach ( $adv as $k ) {
			$h .= $item( $k ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $h . '</ul></details></div></details>';
	}
}
