<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Business Info screen (Launch Builder > Business Info): a short form on the shared admin look.
 *
 * Up front: Basics, Address and hours, Reviews, Social profiles, Logos and Search engines (each field there is either
 * shown on the site or read by search engines). Everything else sits under a collapsed "More options"; the fields in
 * Company::OFF_SCREEN have no input at all. The Search engines card also holds the Rank Math tool: a preview of what
 * Business Info would put into Rank Math's Local SEO settings, a fill that keeps whatever a person set there, and an
 * undo (the work is Compat::business_sync() and Compat::undo_business_fill(), the same as the fill-rank-math tool).
 *
 * One form saves everything (admin-post lb_company_save, nonce lb_company_save, manage_options). The Rank Math buttons
 * belong to a second, empty form (lb_company_rm) through the form attribute, so the sticky Save bar and those buttons
 * never share a submit. Values are validated and sanitised by Company::update(), exactly as set-company does.
 */
final class Company_Admin {

	const PAGE = 'launch-builder-business';

	/** One-time result of a Rank Math fill or undo (per user). */
	const MSG = 'lb_company_msg_';

	/** Business Info values a page still shows as sample text until they are set. */
	const ESSENTIALS = [ 'name', 'phone', 'email', 'address', 'hours', 'cta_label' ];

	public static function boot(): void {
		add_action( 'admin_post_lb_company_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_lb_company_rm', [ __CLASS__, 'rank_math_action' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	private static function on_screen(): bool {
		return isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- reads which screen this is.
	}

	public static function assets(): void {
		if ( ! self::on_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
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
			self::remember( [ 'kind' => 'warn', 'text' => __( 'Saved, except for the values listed here. They keep what was saved before.', 'launch-builder' ), 'lines' => $lines ] );
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
		$name = 'lb[' . $k . ']';
		$id = 'lb-' . $k;
		$help_id = $id . '-help';
		$val = (string) ( $own[ $k ] ?? '' );
		$type = $f[1];
		$long = in_array( $type, [ 'html', 'textarea', 'list' ], true );
		$d = ' aria-describedby="' . esc_attr( $help_id ) . '"';
		$ph = '' !== (string) $f[2] ? ' placeholder="' . esc_attr( (string) $f[2] ) . '"' : '';
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
				case 'list': // areas_served: one per line.
					$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" placeholder="' . esc_attr__( 'Austin, TX', 'launch-builder' ) . '"' . $d . '>' . esc_textarea( implode( "\n", (array) ( $own[ $k ] ?? [] ) ) ) . '</textarea>';
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
		if ( 'source' === $from ) {
			$h .= ' <strong>' . esc_html__( 'The value in use comes from the settings page named in More options.', 'launch-builder' ) . '</strong>';
		} elseif ( 'derived' === $from && '' !== $now ) {
			$h .= ' ' . esc_html__( 'Shown now:', 'launch-builder' ) . ' ' . $now;
		} elseif ( 'computed' === $from && '' !== $now ) {
			$h .= ' ' . esc_html__( 'Built from the fields above:', 'launch-builder' ) . ' ' . $now;
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
		foreach ( [ 'Basics' => __( 'Basics', 'launch-builder' ), 'Address and hours' => __( 'Address and hours', 'launch-builder' ), 'Reviews' => __( 'Reviews', 'launch-builder' ), 'Social profiles' => __( 'Social profiles', 'launch-builder' ), 'Logos' => __( 'Logos', 'launch-builder' ), 'Search engines' => __( 'Search engines', 'launch-builder' ) ] as $g => $label ) {
			echo '<a href="#' . esc_attr( self::slug( $g ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<a href="#lb-g-more">' . esc_html__( 'More options', 'launch-builder' ) . '</a></nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lb-biz-form" class="lb-form"><input type="hidden" name="action" value="lb_company_save">';
		wp_nonce_field( 'lb_company_save' );

		echo self::section_basics( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo self::section_address( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_reviews( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_social( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_logos( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::section_search( $by, $own, $res ); // phpcs:ignore WordPress.Security.EscapeOutput
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
		}
		$msg = self::recall();
		if ( $msg ) {
			$kind = in_array( $msg['kind'] ?? '', [ 'ok', 'warn', 'bad' ], true ) ? $msg['kind'] : 'ok';
			echo '<div class="lb-flash lb-flash--' . esc_attr( $kind ) . '" role="status"><p><strong>' . esc_html( (string) ( $msg['text'] ?? '' ) ) . '</strong>';
			foreach ( (array) ( $msg['lines'] ?? [] ) as $line ) {
				echo '<br>' . esc_html( (string) $line );
			}
			echo '</p></div>';
		}
		$res = Company::resolve();
		$sample = [];
		foreach ( self::ESSENTIALS as $k ) {
			if ( isset( $res[ $k ] ) && 'default' === $res[ $k ]['from'] ) {
				$sample[] = $all[ $k ][0];
			}
		}
		if ( $sample ) {
			/* translators: %s: comma-separated field names */
			echo '<div class="lb-flash lb-flash--warn"><p>' . esc_html( sprintf( __( 'Your pages still show sample text for: %s.', 'launch-builder' ), implode( ', ', $sample ) ) ) . '</p></div>';
		}
	}

	private static function section_basics( array $by, array $own, array $res ): string {
		$buttons = [ 'cta_label', 'cta_url', 'cta2_label', 'cta2_url', 'quote_url' ];
		$main = array_values( array_diff( (array) ( $by['Basics'] ?? [] ), $buttons ) );
		$body = self::fields_grid( $main, $own, $res, [ 'about' ] );
		$body .= '<h3 class="lb-subhead">' . esc_html__( 'Buttons', 'launch-builder' ) . '</h3>' . self::fields_grid( $buttons, $own, $res );
		return self::card( self::slug( 'Basics' ), __( 'Basics', 'launch-builder' ), esc_html__( 'Fields left empty show sample text on your pages until you fill them in.', 'launch-builder' ), $body );
	}

	private static function section_address( array $by, array $own, array $res ): string {
		$keys = (array) ( $by['Address and hours'] ?? [] );
		$parts = array_values( array_intersect( [ 'street', 'locality', 'region', 'postal_code', 'country' ], $keys ) );
		$body = self::fields_grid( $parts, $own, $res, [ 'street' ] );
		$line = $res['address']['value'];
		if ( 'computed' === $res['address']['from'] ) {
			/* translators: %s: the address as one line */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site as: %s', 'launch-builder' ), $line ) ) . '</p>';
		} elseif ( 'default' !== $res['address']['from'] ) {
			$body .= '<p class="lb-help">' . esc_html__( 'Your address is saved as one line (see More options). Fill in the fields above as well and search engines can read it too.', 'launch-builder' ) . '</p>';
		}
		$body .= '<h3 class="lb-subhead">' . esc_html__( 'Opening hours', 'launch-builder' ) . '</h3>';
		$body .= '<p class="lb-help lb-help--lead">' . esc_html__( 'Add one row for each set of days with the same hours. Days without a row count as closed.', 'launch-builder' ) . '</p>';
		$body .= self::repeater( 'hours_spec', (array) ( $own['hours_spec'] ?? [] ) );
		if ( 'source' === ( $res['hours_spec']['from'] ?? '' ) ) {
			$body .= '<p class="lb-help"><strong>' . esc_html__( 'Rows currently come from the settings page named in More options:', 'launch-builder' ) . '</strong> ' . esc_html( $res['hours_spec']['value'] ) . '</p>';
		}
		$body .= self::fields_grid( [ 'is_24_7' ], $own, $res );
		if ( 'computed' === $res['hours']['from'] ) {
			/* translators: %s: the hours as one line */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site as: %s', 'launch-builder' ), $res['hours']['value'] ) ) . '</p>';
		}
		return self::card( self::slug( 'Address and hours' ), __( 'Address and hours', 'launch-builder' ), esc_html__( 'Search engines and maps read these too, so fill in the separate fields.', 'launch-builder' ), $body );
	}

	private static function section_reviews( array $by, array $own, array $res ): string {
		$body = '<p class="lb-help lb-help--lead">' . esc_html__( 'One row for each site where you have reviews, with the numbers shown on that site. Leave it empty rather than guess: nothing ever invents a rating.', 'launch-builder' ) . '</p>';
		$body .= self::repeater( 'reviews', (array) ( $own['reviews'] ?? [] ) );
		if ( 'source' === ( $res['reviews']['from'] ?? '' ) ) {
			$body .= '<p class="lb-help"><strong>' . esc_html__( 'Rows currently come from the settings page named in More options:', 'launch-builder' ) . '</strong> ' . esc_html( $res['reviews']['value'] ) . '</p>';
		}
		$body .= self::fields_grid( [ 'review_url' ], $own, $res );
		$d = Company::review_data();
		if ( null !== $d['rating'] || null !== $d['count'] ) {
			/* translators: 1: rating, 2: number of reviews */
			$body .= '<p class="lb-now">' . esc_html( sprintf( __( 'Shown on the site: %1$s stars from %2$s reviews.', 'launch-builder' ), null !== $d['rating'] ? number_format_i18n( $d['rating'], 1 ) : '–', null !== $d['count'] ? number_format_i18n( $d['count'] ) : '–' ) ) . '</p>';
		} else {
			$body .= '<p class="lb-now">' . esc_html__( 'No rating yet. Rating widgets set to use Business Info show sample numbers to editors only until you add a review site.', 'launch-builder' ) . '</p>';
		}
		return self::card( self::slug( 'Reviews' ), __( 'Reviews', 'launch-builder' ), '', $body );
	}

	private static function section_social( array $by, array $own, array $res ): string {
		$keys = (array) ( $by['Social profiles'] ?? [] );
		$first = array_slice( $keys, 0, 4 );
		$rest = array_slice( $keys, 4 );
		$body = self::fields_grid( $first, $own, $res );
		$open = false;
		foreach ( $rest as $k ) {
			$open = $open || '' !== (string) ( $own[ $k ] ?? '' );
		}
		if ( $rest ) {
			$body .= '<details class="lb-disclose"' . ( $open ? ' open' : '' ) . '><summary>' . Dashboard::icon( 'chevron' ) . esc_html__( 'More profiles', 'launch-builder' ) . '</summary>' . self::fields_grid( $rest, $own, $res ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}
		return self::card( self::slug( 'Social profiles' ), __( 'Social profiles', 'launch-builder' ), esc_html__( 'Full web addresses. A profile you leave empty is left out wherever it would show, and search engines get the rest.', 'launch-builder' ), $body );
	}

	private static function section_logos( array $by, array $own, array $res ): string {
		return self::card( self::slug( 'Logos' ), __( 'Logos', 'launch-builder' ), esc_html__( 'Your main logo is set in Appearance, Customize. These two cover the other places.', 'launch-builder' ), self::fields_grid( (array) ( $by['Logos'] ?? [] ), $own, $res ) );
	}

	/** Business type and price range, and the Rank Math tool. */
	private static function section_search( array $by, array $own, array $res ): string {
		$body = self::fields_grid( (array) ( $by['Search engines'] ?? [] ), $own, $res );
		$types = Compat::rm_business_types();
		if ( ! $types ) { // Without Rank Math: the types a local service business usually needs.
			$types = [ 'Plumber' => 'Plumber', 'Electrician' => 'Electrician', 'HVACBusiness' => 'HVAC business', 'RoofingContractor' => 'Roofing contractor', 'GeneralContractor' => 'General contractor', 'HousePainter' => 'House painter', 'Locksmith' => 'Locksmith', 'MovingCompany' => 'Moving company', 'LocalBusiness' => 'Local business' ];
		}
		$body .= '<datalist id="lb-business-types">';
		foreach ( $types as $key => $label ) {
			$body .= '<option value="' . esc_attr( (string) $key ) . '">' . esc_html( (string) $label ) . '</option>';
		}
		$body .= '</datalist>';
		$body .= self::rank_math_panel();
		$chips = Compat::rm_active() ? '<span class="lb-chip lb-chip--on">' . esc_html__( 'Rank Math is on', 'launch-builder' ) . '</span>' : '<span class="lb-chip lb-chip--plain">' . esc_html__( 'Rank Math is off', 'launch-builder' ) . '</span>';
		return self::card( self::slug( 'Search engines' ), __( 'Search engines', 'launch-builder' ), esc_html__( 'Search engines learn about your business from structured data. These two help them place you.', 'launch-builder' ), $body, $chips );
	}

	/* ------------------------------------------------------------------ the Rank Math tool */

	private static function rank_math_panel(): string {
		$h = '<div class="lb-rm" id="lb-rm"><h3 class="lb-subhead">' . esc_html__( 'Rank Math', 'launch-builder' ) . '</h3>';
		if ( ! Compat::rm_active() ) {
			return $h . '<p class="lb-help lb-help--lead">' . esc_html__( 'Rank Math is not active on this site. When you turn it on, Business Info fills its Local SEO settings by itself. Without Rank Math, this site prints no business structured data.', 'launch-builder' ) . '</p></div>';
		}
		$rm = isset( $_GET['rm'] ) ? sanitize_key( wp_unslash( $_GET['rm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$sync = Compat::business_sync( [ 'dry_run' => true ] );
		$c = $sync['counts'];
		$h .= '<p class="lb-help lb-help--lead">' . esc_html__( 'Business Info can fill in Rank Math\'s Local SEO settings for you: name, description, logo, email, phone numbers, profiles, business type, address, opening hours, price range, map coordinates and contact page. It never replaces something you typed into Rank Math unless you tick it.', 'launch-builder' ) . '</p>';
		if ( ! $sync['ready'] ) {
			$h .= '<p class="lb-help"><strong class="lb-help--warn">' . esc_html__( 'Rank Math\'s setup is not finished, so it prints nothing yet. Finish or skip its setup wizard first.', 'launch-builder' ) . '</strong></p>';
		}
		$bits = [];
		/* translators: %d: number of Rank Math settings */
		$words = [ 'fill' => __( '%d to fill in', 'launch-builder' ), 'update' => __( '%d to update', 'launch-builder' ), 'yours' => __( '%d you set in Rank Math (kept)', 'launch-builder' ), 'match' => __( '%d already match', 'launch-builder' ), 'empty' => __( '%d with nothing to send yet', 'launch-builder' ) ];
		foreach ( $words as $st => $text ) {
			if ( ! empty( $c[ $st ] ) ) {
				$bits[] = '<span class="lb-chip lb-chip--' . ( 'fill' === $st || 'update' === $st ? 'warn' : ( 'match' === $st ? 'ok' : 'plain' ) ) . '">' . esc_html( sprintf( $text, (int) $c[ $st ] ) ) . '</span>';
			}
		}
		$h .= '<p class="lb-rm__counts" aria-label="' . esc_attr__( 'Summary', 'launch-builder' ) . '">' . implode( ' ', $bits ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$local_on = $sync['local_seo']['on'];
		$h .= '<p class="lb-help">' . ( $local_on ? esc_html__( 'Rank Math\'s Local SEO module is on, so it prints your address, hours and phone numbers.', 'launch-builder' ) : '<strong class="lb-help--warn">' . esc_html__( 'Rank Math\'s Local SEO module is off. Until it is on, Rank Math keeps your address, hours and phone numbers to itself.', 'launch-builder' ) . '</strong>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.

		$last = $sync['last_fill'] ?? null;
		$h .= '<div class="lb-rm__actions">';
		if ( 'preview' !== $rm ) {
			$h .= '<button type="submit" form="lb-rm-form" name="do" value="preview" class="lb-btn lb-btn--primary" data-lb-rm>' . Dashboard::icon( 'search' ) . esc_html__( 'Fill Rank Math from Business Info', 'launch-builder' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}
		if ( $last ) {
			/* translators: %s: date and time of the last fill */
			$h .= '<button type="submit" form="lb-rm-form" name="do" value="undo" class="lb-btn" data-lb-rm>' . Dashboard::icon( 'undo' ) . esc_html( sprintf( __( 'Undo the fill from %s', 'launch-builder' ), wp_date( 'M j, g:ia', (int) $last['time'] ) ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		}
		$h .= '</div><p class="lb-help lb-rm__dirty" id="lb-rm-dirty" hidden></p>';

		if ( 'preview' === $rm ) {
			$h .= self::rank_math_table( $sync );
		}
		$h .= '<details class="lb-disclose lb-rm__cannot"><summary>' . Dashboard::icon( 'chevron' ) . esc_html__( 'What Rank Math cannot take from Business Info', 'launch-builder' ) . '</summary><ul class="lb-bullets">';
		foreach ( $sync['cannot'] as $line ) {
			$h .= '<li>' . esc_html( $line ) . '</li>';
		}
		return $h . '</ul></details></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/** The preview: one row per Rank Math setting, then the fill button. */
	private static function rank_math_table( array $sync ): string {
		$say = [
			'fill'   => [ __( 'Will be filled in', 'launch-builder' ), 'warn' ],
			'update' => [ __( 'Will be updated', 'launch-builder' ), 'warn' ],
			'yours'  => [ __( 'You set this: kept', 'launch-builder' ), 'plain' ],
			'match'  => [ __( 'Already matches', 'launch-builder' ), 'ok' ],
			'empty'  => [ __( 'Nothing to send yet', 'launch-builder' ), 'plain' ],
		];
		$h = '<div class="lb-rm__preview"><h4>' . esc_html__( 'What would change', 'launch-builder' ) . '</h4><p class="lb-help">' . esc_html__( 'Nothing is written until you press Fill Rank Math below.', 'launch-builder' ) . '</p>';
		$h .= '<div class="lb-tablewrap"><table class="lb-table lb-rmtable"><thead><tr><th>' . esc_html__( 'Rank Math setting', 'launch-builder' ) . '</th><th>' . esc_html__( 'Comes from', 'launch-builder' ) . '</th><th>' . esc_html__( 'In Rank Math now', 'launch-builder' ) . '</th><th>' . esc_html__( 'Rank Math will get', 'launch-builder' ) . '</th><th>' . esc_html__( 'What happens', 'launch-builder' ) . '</th></tr></thead><tbody>';
		$will = 0;
		foreach ( $sync['rows'] as $row ) {
			$from = $row['from'] ? implode( ', ', array_map( static function ( $f ) { return $f['label']; }, $row['from'] ) ) : ( '' !== $row['from_text'] ? $row['from_text'] : __( 'Nothing filled in yet', 'launch-builder' ) );
			$st = $say[ $row['status'] ];
			$will += 'write' === $row['action'] ? 1 : 0;
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
		$h .= '<div class="lb-rm__actions"><button type="submit" form="lb-rm-form" name="do" value="apply" class="lb-btn lb-btn--primary" data-lb-rm>' . Dashboard::icon( 'check' ) . esc_html__( 'Fill Rank Math', 'launch-builder' ) . '</button><a class="lb-btn lb-btn--ghost" href="' . esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) . '#lb-rm' ) . '">' . esc_html__( 'Cancel', 'launch-builder' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		if ( 0 === $will && $sync['local_seo']['on'] ) {
			$h .= '<p class="lb-help">' . esc_html__( 'Nothing needs filling right now. Fill Rank Math still checks again and writes only what is missing.', 'launch-builder' ) . '</p>';
		}
		return $h . '</div>';
	}

	/* ------------------------------------------------------------------ More options */

	private static function section_more( array $by, array $own, array $res ): string {
		$groups = [
			'Form embed'            => [ __( 'Contact form', 'launch-builder' ), __( 'The embed code your form service gives you (iframe and script). The form patterns show it.', 'launch-builder' ) ],
			'Wording and links'     => [ __( 'Wording and links', 'launch-builder' ), __( 'Each of these has a sensible fallback, so leave them empty unless you want something different.', 'launch-builder' ) ],
			'Search engine details' => [ __( 'Search engine details', 'launch-builder' ), __( 'Search engines read these. They are not shown on the site.', 'launch-builder' ) ],
			'Numbers and claims'    => [ __( 'Numbers you can show', 'launch-builder' ), __( 'Shown only where you add them to a page. Leave empty rather than guess.', 'launch-builder' ) ],
			'Offers'                => [ __( 'Offers and financing', 'launch-builder' ), __( 'A promotion shows until its end date.', 'launch-builder' ) ],
			'Writing brief'         => [ __( 'Writing brief', 'launch-builder' ), __( 'Notes for whoever writes the copy (you, a copywriter or an AI assistant). Not shown on the site.', 'launch-builder' ) ],
		];
		$body = '';
		foreach ( Company::MORE_GROUPS as $g ) {
			if ( empty( $by[ $g ] ) ) {
				continue;
			}
			$wide = 'Form embed' === $g ? [ 'form_html' ] : [];
			$body .= '<section class="lb-more__group" id="' . esc_attr( self::slug( $g ) ) . '"><h3 class="lb-subhead">' . esc_html( $groups[ $g ][0] ) . '</h3><p class="lb-help lb-help--lead">' . esc_html( $groups[ $g ][1] ) . '</p>' . self::fields_grid( $by[ $g ], $own, $res, $wide ) . '</section>';
		}
		$body .= self::source_group();
		$body .= self::off_screen_note( $by, $own );
		return '<details class="lb-panel lb-more" id="lb-g-more" data-lb-more><summary><span class="lb-more__title"><h2>' . esc_html__( 'More options', 'launch-builder' ) . '</h2><span class="lb-help">' . esc_html__( 'Contact form, links, numbers, offers and notes. You can skip all of these.', 'launch-builder' ) . '</span></span>' . Dashboard::icon( 'chevron' ) . '</summary><div class="lb-more__body">' . $body . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/** Reading the values from another settings page (a Meta Box settings page, for example). */
	private static function source_group(): string {
		$src = (array) get_option( Company::OPT_SRC, [] );
		$read = Company::source();
		$lines = [];
		foreach ( (array) ( $src['map'] ?? [] ) as $k => $v ) {
			$lines[] = $k . ' = ' . $v;
		}
		$h = '<section class="lb-more__group" id="lb-g-source"><h3 class="lb-subhead">' . esc_html__( 'Read from another settings page', 'launch-builder' ) . '</h3>';
		$h .= '<p class="lb-help lb-help--lead">' . wp_kses( __( 'If this site already keeps these facts on another settings page, such as a Meta Box settings page, enter its option name and Business Info reads them from there instead. Fields are matched by common names (phone_number, business_name, linked_phone_number…). Map any others below, one per line, like <code>phone = my_phone_field</code>.', 'launch-builder' ), [ 'code' => [] ] ) . '</p>';
		$h .= '<div class="lb-fields"><div class="lb-field lb-field--wide"><label for="lb-source">' . esc_html__( 'Option name', 'launch-builder' ) . '</label><input type="text" id="lb-source" name="lb_source" value="' . esc_attr( (string) ( $src['option'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'for example company_information', 'launch-builder' ) . '" autocomplete="off" spellcheck="false"></div>';
		if ( is_multisite() ) {
			$h .= '<div class="lb-field lb-field--wide lb-field--check"><label class="lb-check"><input type="checkbox" name="lb_source_network" value="1"' . checked( ! empty( $src['network'] ), true, false ) . '> <span>' . esc_html__( 'Network option (shared by every site)', 'launch-builder' ) . '</span></label></div>';
		}
		$h .= '<div class="lb-field lb-field--wide"><label for="lb-map">' . esc_html__( 'Field names', 'launch-builder' ) . '</label><textarea id="lb-map" name="lb_map" rows="3" class="code" placeholder="phone = phone_number" spellcheck="false">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></div></div>';
		if ( $read['meta_box'] ) {
			$h .= '<p class="lb-help">' . esc_html__( 'This is a Meta Box settings page: values are read through Meta Box.', 'launch-builder' ) . '</p>';
		}
		if ( $read['skipped'] ) {
			$h .= '<div class="lb-flash lb-flash--warn"><div><p>' . esc_html__( 'Some values on that page could not be used:', 'launch-builder' ) . '</p><ul class="lb-bullets">';
			foreach ( $read['skipped'] as $s ) {
				$h .= '<li><code>' . esc_html( $s['source_key'] ) . '</code> &rarr; ' . esc_html( $s['key'] ) . ': ' . esc_html( $s['reason'] ) . '</li>';
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
		$h .= '<p class="lb-help lb-help--lead">' . wp_kses( __( 'Type a token such as <code>{{lb.phone}}</code> in text, or <code>#lb-phone_link</code> in a link field, and the page shows your current value. Change it here and every page follows. Click a token to copy it.', 'launch-builder' ), [ 'code' => [] ] ) . '</p><ul class="lb-tokens__list">';
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
