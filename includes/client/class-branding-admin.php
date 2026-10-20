<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Branding screen (Launch Builder > Branding): one form in sections (identity, sign-in screen with a live preview,
 * the Site Owner role, the client dashboard, the admin look, dashboard colours with a live preview, email), plus the
 * guarded "move administrators to the Site Owner role" action and settings export/import.
 *
 * Plain semantic HTML on the shared admin classes (lb-wrap, lb-card); only what is specific to this screen is in
 * assets/css/branding-admin.css and assets/js/branding-admin.js, loaded on this screen alone. Every write checks the
 * capability and a nonce and goes through Branding::apply(), the same validation set-branding uses.
 */
final class Branding_Admin {

	/** Where the one-time result message of a form post is kept (per user). */
	const MSG = 'lb_branding_msg_';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 11 );
		add_action( 'admin_post_lb_branding_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_lb_branding_convert', [ __CLASS__, 'convert' ] );
		add_action( 'admin_post_lb_branding_export', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_lb_branding_import', [ __CLASS__, 'import' ] );
		add_action( 'admin_post_lb_branding_reset_colors', [ __CLASS__, 'reset_colors' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function menu(): void {
		add_submenu_page( Admin::SLUG, __( 'Branding', 'launch-builder' ), __( 'Branding', 'launch-builder' ), 'manage_options', Branding::PAGE, [ __CLASS__, 'page' ], 2 );
	}

	private static function on_screen(): bool {
		return isset( $_GET['page'] ) && Branding::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- reads which screen this is.
	}

	public static function assets(): void {
		if ( ! self::on_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'lb-branding-admin', LB_URL . 'assets/css/branding-admin.css', [], (string) @filemtime( LB_DIR . 'assets/css/branding-admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'lb-branding-admin', LB_URL . 'assets/js/branding-admin.js', [], (string) @filemtime( LB_DIR . 'assets/js/branding-admin.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'lb-branding-colors', LB_URL . 'assets/js/branding-colors.js', [ 'lb-branding-admin' ], (string) @filemtime( LB_DIR . 'assets/js/branding-colors.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-branding-colors', 'window.lbBrandingColors=' . wp_json_encode( Branding_Colors::client_config() + [
			'i18n' => [
				'sidebar_text' => __( 'Sidebar text on the sidebar', 'launch-builder' ),
				'active_text'  => __( 'Text on the active item', 'launch-builder' ),
				'bar_text'     => __( 'Text on the top bar', 'launch-builder' ),
				'link'         => __( 'Links on the page', 'launch-builder' ),
				'button_text'  => __( 'Text on buttons', 'launch-builder' ),
				'active_on_sidebar' => __( 'Active item against the sidebar', 'launch-builder' ),
				'ok'           => __( 'OK', 'launch-builder' ),
				'low'          => __( 'Low contrast', 'launch-builder' ),
				'fixed'        => __( 'Corrected', 'launch-builder' ),
				'all_ok'       => __( 'All text is easy to read.', 'launch-builder' ),
			],
		] ) . ';', 'before' );
		$agency = Branding::agency();
		$client = Branding::client_logo_id();
		$login = Branding::section( 'login' );
		wp_add_inline_script( 'lb-branding-admin', 'window.lbBranding=' . wp_json_encode( [
			'palette' => Style::palette(),
			'logos'   => [
				'client' => Branding::attachment_url( $client, 'large' ),
				'agency' => Branding::attachment_url( (int) $agency['logo_id'], 'large' ),
				'custom' => Branding::attachment_url( Branding::usable_attachment( (int) $login['logo'] ), 'large' ),
				'bg'     => Branding::attachment_url( Branding::usable_attachment( (int) $login['bg_image'] ), 'large' ),
			],
			'names'   => [ 'business' => Branding::business_name()[0], 'agency' => $agency['name'] ],
			'i18n'    => [
				'choose'   => __( 'Choose image', 'launch-builder' ),
				'use'      => __( 'Use this image', 'launch-builder' ),
				'change'   => __( 'Change image', 'launch-builder' ),
				'ok'       => __( 'OK', 'launch-builder' ),
				'low'      => __( 'Low contrast', 'launch-builder' ),
				'fixed'    => __( 'Corrected', 'launch-builder' ),
				'unsaved'  => __( 'You have unsaved changes.', 'launch-builder' ),
				'bt'       => __( 'Button text on button', 'launch-builder' ),
				'tf'       => __( 'Text on form', 'launch-builder' ),
				'lp'       => __( 'Link on page', 'launch-builder' ),
				'bf'       => __( 'Button on form', 'launch-builder' ),
			],
		] ) . ';', 'before' );
	}

	/* ------------------------------------------------------------------ form helpers */

	private static function name( string $path ): string {
		$parts = explode( '.', $path );
		return 'lbb[' . implode( '][', $parts ) . ']';
	}

	private static function id( string $path ): string {
		return 'lbb-' . str_replace( [ '.', '_' ], '-', $path );
	}

	/** Value at a dot path of the form's settings. */
	private static function v( array $form, string $path ) {
		$cur = $form;
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $k, $cur ) ) {
				return null;
			}
			$cur = $cur[ $k ];
		}
		return $cur;
	}

	private static function help_id( string $path ): string {
		return self::id( $path ) . '-help';
	}

	private static function field( string $label, string $path, string $control, string $help = '' ): string {
		$h = '' !== $help ? '<p class="description" id="' . esc_attr( self::help_id( $path ) ) . '">' . esc_html( $help ) . '</p>' : '';
		return '<div class="lbb-field"><label for="' . esc_attr( self::id( $path ) ) . '">' . esc_html( $label ) . '</label><div class="lbb-control">' . $control . $h . '</div></div>';
	}

	private static function aria( string $path, string $help ): string {
		return '' !== $help ? ' aria-describedby="' . esc_attr( self::help_id( $path ) ) . '"' : '';
	}

	private static function text( array $f, string $label, string $path, string $help = '', array $attr = [] ): string {
		$type = $attr['type'] ?? 'text';
		$ph = isset( $attr['placeholder'] ) ? ' placeholder="' . esc_attr( $attr['placeholder'] ) . '"' : '';
		$max = isset( $attr['maxlength'] ) ? ' maxlength="' . (int) $attr['maxlength'] . '"' : '';
		$ctl = '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( (string) self::v( $f, $path ) ) . '"' . $ph . $max . self::aria( $path, $help ) . '>';
		return self::field( $label, $path, $ctl, $help );
	}

	private static function area( array $f, string $label, string $path, string $help = '', int $max = 4096, int $rows = 5 ): string {
		$ctl = '<textarea class="large-text" rows="' . $rows . '" id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '" maxlength="' . $max . '"' . self::aria( $path, $help ) . '>' . esc_textarea( (string) self::v( $f, $path ) ) . '</textarea>';
		return self::field( $label, $path, $ctl, $help );
	}

	private static function check( array $f, string $label, string $path, string $help = '' ): string {
		$on = (bool) self::v( $f, $path );
		$h = '' !== $help ? '<span class="description" id="' . esc_attr( self::help_id( $path ) ) . '">' . esc_html( $help ) . '</span>' : '';
		return '<div class="lbb-check"><input type="hidden" name="' . esc_attr( self::name( $path ) ) . '" value="0"><label><input type="checkbox" id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="1"' . checked( $on, true, false ) . self::aria( $path, $help ) . '> ' . esc_html( $label ) . '</label>' . $h . '</div>';
	}

	private static function select( array $f, string $label, string $path, array $options, string $help = '' ): string {
		$cur = (string) self::v( $f, $path );
		$o = '';
		foreach ( $options as $val => $text ) {
			$o .= '<option value="' . esc_attr( (string) $val ) . '"' . selected( $cur, (string) $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return self::field( $label, $path, '<select id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '"' . self::aria( $path, $help ) . '>' . $o . '</select>', $help );
	}

	/** A group of radio buttons with a description under each choice. */
	private static function radios( array $f, string $legend, string $path, array $options ): string {
		$cur = (string) self::v( $f, $path );
		$out = '<fieldset class="lbb-radios"><legend>' . esc_html( $legend ) . '</legend>';
		foreach ( $options as $val => [ $label, $desc ] ) {
			$id = self::id( $path ) . '-' . $val;
			$out .= '<div class="lbb-radio"><input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( (string) $val ) . '"' . checked( $cur, (string) $val, false ) . ' aria-describedby="' . esc_attr( $id ) . '-d"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><p class="description" id="' . esc_attr( $id ) . '-d">' . esc_html( $desc ) . '</p></div>';
		}
		return $out . '</fieldset>';
	}

	/** A colour: swatch, the text field (empty = the automatic colour) and a button that empties it. */
	private static function color( array $f, string $label, string $path, string $auto, string $help = '', string $reset = '' ): string {
		$val = (string) self::v( $f, $path );
		$swatch = Style::hex( '' !== $val ? $val : $auto ) ?: '#FFFFFF';
		$ctl = '<div class="lbb-color" data-auto="' . esc_attr( $auto ) . '"><input type="color" class="lbb-swatch" value="' . esc_attr( strtolower( $swatch ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: colour name */ __( 'Pick: %s', 'launch-builder' ), $label ) ) . '">'
			. '<input type="text" class="lbb-hex" id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $auto ) . '" maxlength="9" autocomplete="off" spellcheck="false"' . self::aria( $path, $help ) . '>'
			. '<button type="button" class="button-link lbb-auto">' . esc_html( '' !== $reset ? $reset : __( 'Use brand colour', 'launch-builder' ) ) . '</button></div>';
		return self::field( $label, $path, $ctl, $help );
	}

	/** An image from the media library (stores the attachment id). */
	private static function media( array $f, string $label, string $path, string $help = '', int $size = 96 ): string {
		$id = (int) self::v( $f, $path );
		$url = $id ? Branding::attachment_url( Branding::usable_attachment( $id ), 'thumbnail' ) : '';
		$ctl = '<div class="lbb-media" data-path="' . esc_attr( $path ) . '"><span class="lbb-thumb" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px">' . ( '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '' ) . '</span>'
			. '<input type="hidden" id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . $id . '"' . self::aria( $path, $help ) . '>'
			. '<span class="lbb-media-btns"><button type="button" class="button lbb-pick">' . esc_html( $id ? __( 'Change image', 'launch-builder' ) : __( 'Choose image', 'launch-builder' ) ) . '</button> <button type="button" class="button-link lbb-clear"' . ( $id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'launch-builder' ) . '</button></span></div>';
		return self::field( $label, $path, $ctl, $help );
	}

	private static function card( string $id, string $title, string $intro, string $body ): string {
		return '<section class="lb-card lbb-section" id="' . esc_attr( $id ) . '"><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $intro ? '<p class="lbb-intro">' . esc_html( $intro ) . '</p>' : '' ) . $body . '</section>';
	}

	/* ------------------------------------------------------------------ page */

	/** What a top-level menu item is called (update bubbles and tags removed). */
	private static function menu_label( array $item ): string {
		$s = (string) ( $item[0] ?? '' );
		$s = (string) preg_replace( '/<span[^>]*>.*?<\/span>/is', '', $s );
		return trim( wp_strip_all_tags( $s ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$configured = Branding::configured();
		$eff = Branding::settings();
		$form = $configured ? $eff : self::overlay( $eff, Branding::RECOMMENDED );
		$agency = Branding::agency();
		$msg = get_transient( self::MSG . get_current_user_id() );
		if ( $msg ) {
			delete_transient( self::MSG . get_current_user_id() );
		}
		echo self::shared_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- the shared admin stylesheet (static CSS).
		echo '<div class="wrap lb-wrap lbb"><h1>' . esc_html__( 'Branding', 'launch-builder' ) . '</h1><p class="lb-sub">' . esc_html__( 'Make the site look like yours when you hand it over: your sign-in page, a simple dashboard and a Site Owner role for your client. Visitors never see any of it.', 'launch-builder' ) . '</p>';

		if ( Branding::safe() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'LB_BRANDING_SAFE_MODE is set in wp-config.php: every branded surface is off. You can still edit and save these settings.', 'launch-builder' ) . '</p></div>';
		}
		if ( is_array( $msg ) ) {
			echo '<div class="notice notice-' . ( 'error' === $msg[0] ? 'error' : 'success' ) . ' inline is-dismissible"><p>' . esc_html( (string) $msg[1] ) . '</p>';
			foreach ( array_slice( (array) ( $msg[2] ?? [] ), 0, 10 ) as $line ) {
				echo '<p class="lbb-note">' . esc_html( (string) $line ) . '</p>';
			}
			$link = (array) ( $msg[3] ?? [] );
			if ( 2 === count( $link ) && is_string( $link[0] ) && is_string( $link[1] ) ) {
				echo '<p class="lbb-note"><a class="lb-btn lb-btn--sm" href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a></p>';
			}
			echo '</div>';
		}
		if ( ! $configured ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Nothing is branded yet.', 'launch-builder' ) . '</strong> ' . esc_html__( 'Branding is on, but it changes nothing on this site until you save. The recommended setup is ticked below: review it, then press Save settings.', 'launch-builder' ) . '</p></div>';
		}
		foreach ( Branding::all_warnings() as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}

		echo '<nav class="lbb-nav" aria-label="' . esc_attr__( 'Sections', 'launch-builder' ) . '"><ul>';
		foreach ( [ 'lbb-identity' => __( 'Your details', 'launch-builder' ), 'lbb-login' => __( 'Sign-in screen', 'launch-builder' ), 'lbb-clients' => __( 'Site Owner role', 'launch-builder' ), 'lbb-dashboard' => __( 'Client dashboard', 'launch-builder' ), 'lbb-admin' => __( 'Admin look', 'launch-builder' ), 'lbb-colors' => __( 'Dashboard colours', 'launch-builder' ), 'lbb-email' => __( 'Email', 'launch-builder' ), 'lbb-tools' => __( 'Move, export, import', 'launch-builder' ) ] as $a => $l ) {
			echo '<li><a href="#' . esc_attr( $a ) . '">' . esc_html( $l ) . '</a></li>';
		}
		echo '</ul></nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lbb-form"><input type="hidden" name="action" value="lb_branding_save">';
		wp_nonce_field( 'lb_branding_save' );

		echo self::section_identity( $form, $agency );
		echo self::section_login( $form );
		echo self::section_clients( $form );
		echo self::section_dashboard( $form );
		echo self::section_admin( $form );
		echo self::section_colors( $form );
		echo self::section_email( $form );

		echo '<div class="lbb-savebar"><button type="submit" class="button button-primary button-large">' . esc_html__( 'Save settings', 'launch-builder' ) . '</button><span class="lbb-dirty" role="status" aria-live="polite" hidden></span></div></form>';

		// The reset button in the colours card submits this form (the HTML form attribute), so no form sits inside another.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lbb-colors-reset"><input type="hidden" name="action" value="lb_branding_reset_colors">' . wp_nonce_field( 'lb_branding_reset_colors', '_wpnonce', true, false ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- nonce field markup.

		echo self::section_tools();
		echo '</div>';
	}

	/** The shared admin stylesheet from the admin screens, when it can be reached (it is private to that class). */
	private static function shared_css(): string {
		try {
			$m = new \ReflectionMethod( Admin::class, 'css' );
			$m->setAccessible( true );
			return (string) $m->invoke( null );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function overlay( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			$base[ $k ] = is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ? self::overlay( $base[ $k ], $v ) : $v;
		}
		return $base;
	}

	/* ------------------------------------------------------------------ sections */

	private static function section_identity( array $f, array $agency ): string {
		$b = '<div class="lbb-grid">';
		$b .= self::text( $f, __( 'Agency name', 'launch-builder' ), 'agency.name', __( 'Shown in the sign-in credit, the admin footer and the support card.', 'launch-builder' ), [ 'maxlength' => 80, 'placeholder' => Branding::AGENCY['name'] ] );
		$b .= self::text( $f, __( 'Agency website', 'launch-builder' ), 'agency.url', '', [ 'type' => 'url', 'placeholder' => Branding::AGENCY['url'] ] );
		$b .= self::media( $f, __( 'Agency logo', 'launch-builder' ), 'agency.logo', __( 'Wide logo, used on the sign-in screen when you choose it.', 'launch-builder' ) );
		$b .= self::media( $f, __( 'Agency icon (square)', 'launch-builder' ), 'agency.logo_square', __( 'Used in the admin bar. 300 by 300 works everywhere.', 'launch-builder' ) );
		$b .= self::text( $f, __( 'Support email', 'launch-builder' ), 'agency.support_email', '', [ 'type' => 'email', 'maxlength' => 254 ] );
		$b .= self::text( $f, __( 'Support phone', 'launch-builder' ), 'agency.support_phone', '', [ 'maxlength' => 40 ] );
		$b .= self::text( $f, __( 'Support page', 'launch-builder' ), 'agency.support_url', '', [ 'type' => 'url' ] );
		$b .= self::text( $f, __( 'Booking link', 'launch-builder' ), 'agency.booking_url', __( 'A "Book a call" link on the client dashboard.', 'launch-builder' ), [ 'type' => 'url' ] );
		$b .= '</div>';
		[ $bn, $src ] = Branding::business_name();
		$b .= '<p class="lbb-note">' . sprintf( /* translators: 1: business name, 2: where it comes from */ esc_html__( 'The client\'s business name is %1$s (from %2$s). Change it in Business Info; the logo and colours come from the theme (set-brand).', 'launch-builder' ), '<strong>' . esc_html( $bn ) . '</strong>', esc_html( 'business_info' === $src ? __( 'Business Info', 'launch-builder' ) : __( 'the site title', 'launch-builder' ) ) ) . ' <a href="' . esc_url( Admin::url( 'launch-builder-business' ) ) . '">' . esc_html__( 'Open Business Info', 'launch-builder' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped above.
		return self::card( 'lbb-identity', __( 'Your details', 'launch-builder' ), __( 'Who built the site and how a client reaches you. Everything is optional; empty fields use the defaults shown.', 'launch-builder' ), $b );
	}

	private static function section_login( array $f ): string {
		$l = (array) ( $f['login'] ?? [] );
		$vars = Branding_Chrome::login_vars( Branding::section( 'login' ) );
		$auto = [ 'bg' => Style::hex( 'palette8' ), 'form_bg' => Style::hex( 'palette9' ), 'text' => Style::hex( 'palette3' ), 'button_bg' => Style::hex( 'palette1' ), 'button_text' => $vars['colors']['button_text'], 'button_hover_bg' => Style::hex( 'palette2' ), 'link' => Style::hex( 'palette1' ) ];
		$b = '<div class="lbb-login-grid"><div class="lbb-login-fields">';
		$b .= self::check( $f, __( 'Use a branded sign-in screen', 'launch-builder' ), 'login.enabled', __( 'Your logo and colours on the page where people sign in to the site.', 'launch-builder' ) );
		$b .= self::select( $f, __( 'Logo', 'launch-builder' ), 'login.logo_source', [ 'client' => __( 'The client\'s logo (from the theme)', 'launch-builder' ), 'agency' => __( 'The agency logo', 'launch-builder' ), 'custom' => __( 'A different image', 'launch-builder' ) ] );
		$b .= self::media( $f, __( 'Different image', 'launch-builder' ), 'login.logo', __( 'Only used when "A different image" is chosen.', 'launch-builder' ) );
		$b .= self::text( $f, __( 'Logo width (px)', 'launch-builder' ), 'login.logo_width', '', [ 'type' => 'number', 'maxlength' => 3 ] );
		$b .= self::text( $f, __( 'Message under the logo', 'launch-builder' ), 'login.message', '', [ 'maxlength' => 200, 'placeholder' => __( 'Welcome back', 'launch-builder' ) ] );
		$b .= self::color( $f, __( 'Page background', 'launch-builder' ), 'login.bg', (string) $auto['bg'] );
		$b .= self::media( $f, __( 'Background picture', 'launch-builder' ), 'login.bg_image', __( 'Optional. A dark overlay keeps the form readable.', 'launch-builder' ) );
		$b .= self::text( $f, __( 'Picture overlay (0 to 80)', 'launch-builder' ), 'login.bg_overlay', '', [ 'type' => 'number', 'maxlength' => 2 ] );
		$b .= self::color( $f, __( 'Form background', 'launch-builder' ), 'login.form_bg', (string) $auto['form_bg'] );
		$b .= self::color( $f, __( 'Text', 'launch-builder' ), 'login.text', (string) $auto['text'] );
		$b .= self::color( $f, __( 'Button', 'launch-builder' ), 'login.button_bg', (string) $auto['button_bg'] );
		$b .= self::color( $f, __( 'Button text', 'launch-builder' ), 'login.button_text', (string) $auto['button_text'] );
		$b .= self::color( $f, __( 'Button hover', 'launch-builder' ), 'login.button_hover_bg', (string) $auto['button_hover_bg'] );
		$b .= self::color( $f, __( 'Links', 'launch-builder' ), 'login.link', (string) $auto['link'] );
		$b .= self::check( $f, __( 'Show "Website by" credit', 'launch-builder' ), 'login.credit' );
		$b .= self::check( $f, __( 'Hide the "Back to site" link', 'launch-builder' ), 'login.hide_back_link', __( '"Lost your password?" is never hidden.', 'launch-builder' ) );
		$b .= '</div><div class="lbb-login-preview"><h3>' . esc_html__( 'Preview', 'launch-builder' ) . '</h3>';
		$b .= self::preview_markup( $f );
		$b .= '<ul class="lbb-contrast" id="lbb-contrast" aria-live="polite"></ul>';
		$b .= '<p><a class="button" href="' . esc_url( wp_login_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the real sign-in page', 'launch-builder' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'launch-builder' ) . '</span></a></p>';
		$b .= '<p class="description">' . esc_html__( 'Unreadable colour combinations are corrected on the real page and reported here.', 'launch-builder' ) . '</p></div></div>';
		return self::card( 'lbb-login', __( 'Sign-in screen', 'launch-builder' ), '', $b );
	}

	/** A static mock of the sign-in box, styled by the same CSS the real page gets (scoped to .lb-br-mock). */
	private static function preview_markup( array $f ): string {
		$login = (array) ( $f['login'] ?? [] );
		$agency = Branding::agency();
		$css = Branding_Chrome::login_css( array_merge( Branding::section( 'login' ), $login ), '.lb-br-mock' );
		$name = Branding::business_name()[0];
		$h = '<style id="lbb-preview-css">' . $css . '</style>';
		$h .= '<div class="lb-br-mock" id="lbb-mock" role="img" aria-label="' . esc_attr__( 'Preview of the sign-in screen', 'launch-builder' ) . '"><div class="lbb-mock-box">';
		$h .= '<h1><a href="#" tabindex="-1" id="lbb-mock-logo">' . esc_html( $name ) . '</a></h1>';
		$h .= '<p class="lb-login-message" id="lbb-mock-message"' . ( '' === (string) ( $login['message'] ?? '' ) ? ' hidden' : '' ) . '>' . esc_html( (string) ( $login['message'] ?? '' ) ) . '</p>';
		$h .= '<div class="lbb-mock-form"><p><label>' . esc_html__( 'Username or Email Address', 'launch-builder' ) . '</label><input type="text" class="input" tabindex="-1" disabled aria-label="' . esc_attr__( 'Username or Email Address', 'launch-builder' ) . '"></p><p><label>' . esc_html__( 'Password', 'launch-builder' ) . '</label><input type="password" class="input" tabindex="-1" disabled aria-label="' . esc_attr__( 'Password', 'launch-builder' ) . '"></p><p class="submit"><input type="button" class="button button-primary" tabindex="-1" disabled value="' . esc_attr__( 'Log In', 'launch-builder' ) . '"></p></div>';
		$h .= '<p id="nav"><a href="#" tabindex="-1">' . esc_html__( 'Lost your password?', 'launch-builder' ) . '</a></p><p id="backtoblog"><a href="#" tabindex="-1">' . esc_html__( '&larr; Go to site', 'launch-builder' ) . '</a></p>';
		$h .= '<p class="lb-login-credit" id="lbb-mock-credit">' . sprintf( /* translators: %s: agency name */ esc_html__( 'Website by %s', 'launch-builder' ), '<a href="#" tabindex="-1">' . esc_html( $agency['name'] ) . '</a>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		$h .= '</div></div>';
		return $h;
	}

	private static function section_clients( array $f ): string {
		$b = self::check( $f, __( 'Create the Site Owner role', 'launch-builder' ), 'client.role.enabled', __( 'A safe account for your client. They can edit pages, posts, services and photos, but not settings, plugins, themes or users.', 'launch-builder' ) );
		$b .= '<div class="lbb-grid">';
		$b .= self::text( $f, __( 'Role name', 'launch-builder' ), 'client.role.label', '', [ 'maxlength' => 40, 'placeholder' => 'Site Owner' ] );
		$b .= self::select( $f, __( 'SEO box in the editor', 'launch-builder' ), 'client.role.flags.seo_box', [ 'none' => __( 'Hidden', 'launch-builder' ), 'basic' => __( 'Basic fields', 'launch-builder' ) ] );
		$b .= '</div>';
		$b .= self::check( $f, __( 'Can moderate comments', 'launch-builder' ), 'client.role.flags.comments' );
		$b .= self::check( $f, __( 'Can add scripts and unfiltered HTML', 'launch-builder' ), 'client.role.flags.unfiltered_html', __( 'Off is safer: clients cannot paste scripts into pages.', 'launch-builder' ) );
		$b .= '<h3>' . esc_html__( 'What they see', 'launch-builder' ) . '</h3>';
		$b .= self::check( $f, __( 'Hide update and plugin notices', 'launch-builder' ), 'client.hide_nags', __( 'Notices about their own account stay. Administrators always see every notice.', 'launch-builder' ) );
		$b .= self::check( $f, __( 'Hide the Help tab', 'launch-builder' ), 'client.hide_help' );
		$b .= self::check( $f, __( 'Hide Screen Options', 'launch-builder' ), 'client.hide_screen_options' );
		$b .= self::check( $f, __( 'Send them back to the dashboard from screens outside their menu', 'launch-builder' ), 'client.block_hidden_screens' );
		$b .= self::check( $f, __( 'Turn off the Kadence AI features for them', 'launch-builder' ), 'client.ai_off' );
		$b .= self::check( $f, __( 'Hide the Kadence Blocks home page for them', 'launch-builder' ), 'client.blocks_home_off' );
		$b .= self::menus( $f );
		$b .= '<p class="lbb-note">' . esc_html__( 'Administrators are never simplified or redirected. Other roles, the admin bar and dashboard widget lists can be set with set-branding (client.roles).', 'launch-builder' ) . '</p>';
		return self::card( 'lbb-clients', __( 'Site Owner role', 'launch-builder' ), __( 'Give clients a real, limited account instead of hiding things from an administrator.', 'launch-builder' ), $b );
	}

	/** Checkboxes for the Site Owner menu allow-list, built from the live menu. */
	private static function menus( array $f ): string {
		global $menu;
		$profile = Branding_Client::profiles()[ Branding::ROLE ] ?? [ 'menus' => Branding_Client::MENU_DEFAULT ];
		$allowed = (array) $profile['menus'];
		$seen = [];
		$out = '<fieldset class="lbb-menus"><legend>' . esc_html__( 'Menus the Site Owner role can use', 'launch-builder' ) . '</legend><input type="hidden" name="lbb[client][roles][' . esc_attr( Branding::ROLE ) . '][menus][]" value="">';
		foreach ( (array) $menu as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			$label = self::menu_label( $item );
			if ( '' === $slug || '' === $label || false !== strpos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out .= '<label class="lbb-menu"><input type="checkbox" name="lbb[client][roles][' . esc_attr( Branding::ROLE ) . '][menus][]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $allowed, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		foreach ( $allowed as $slug ) {
			if ( ! isset( $seen[ $slug ] ) ) {
				$out .= '<label class="lbb-menu"><input type="checkbox" name="lbb[client][roles][' . esc_attr( Branding::ROLE ) . '][menus][]" value="' . esc_attr( $slug ) . '" checked> ' . esc_html( $slug ) . ' <em>' . esc_html__( '(not on this site)', 'launch-builder' ) . '</em></label>';
			}
		}
		return $out . '<p class="description">' . esc_html__( 'Screens the account has no permission for stay out of reach whatever is ticked.', 'launch-builder' ) . '</p></fieldset>';
	}

	private static function section_dashboard( array $f ): string {
		$b = self::check( $f, __( 'Show a welcome panel on the dashboard', 'launch-builder' ), 'dashboard.welcome.enabled', __( 'A greeting, quick actions and your support details. Clients see only this panel; add "administrator" to preview it yourself with set-branding.', 'launch-builder' ) );
		$b .= '<div class="lbb-grid">' . self::text( $f, __( 'Panel title', 'launch-builder' ), 'dashboard.welcome.title', '', [ 'maxlength' => 80, 'placeholder' => __( 'Welcome', 'launch-builder' ) ] ) . '</div>';
		$b .= self::area( $f, __( 'Message', 'launch-builder' ), 'dashboard.welcome.message', __( 'Short paragraphs, links, lists and bold text. Up to 4 KB.', 'launch-builder' ), 4096, 5 );
		$tiles = [ 'pages' => __( 'Edit pages', 'launch-builder' ), 'posts' => __( 'Write a post', 'launch-builder' ), 'services' => __( 'Services', 'launch-builder' ), 'areas' => __( 'Service areas', 'launch-builder' ), 'media' => __( 'Photos and files', 'launch-builder' ), 'comments' => __( 'Comments', 'launch-builder' ), 'site' => __( 'View the website', 'launch-builder' ), 'profile' => __( 'Profile', 'launch-builder' ) ];
		$sel = (array) self::v( $f, 'dashboard.welcome.tiles' );
		$b .= '<fieldset class="lbb-menus"><legend>' . esc_html__( 'Quick actions', 'launch-builder' ) . '</legend><input type="hidden" name="lbb[dashboard][welcome][tiles][]" value="">';
		foreach ( $tiles as $k => $label ) {
			$b .= '<label class="lbb-menu"><input type="checkbox" name="lbb[dashboard][welcome][tiles][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $sel, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		$b .= '</fieldset>';
		$b .= self::check( $f, __( 'Show your support details in the panel', 'launch-builder' ), 'dashboard.welcome.support_card' );
		$rm = (array) self::v( $f, 'dashboard.admin_remove' );
		$b .= '<fieldset class="lbb-menus"><legend>' . esc_html__( 'Tidy the dashboard for everyone else', 'launch-builder' ) . '</legend><input type="hidden" name="lbb[dashboard][admin_remove][]" value="">';
		foreach ( [ 'dashboard_primary' => __( 'Remove the WordPress Events and News widget', 'launch-builder' ), 'dashboard_quick_press' => __( 'Remove the Quick Draft widget', 'launch-builder' ) ] as $k => $label ) {
			$b .= '<label class="lbb-menu"><input type="checkbox" name="lbb[dashboard][admin_remove][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $rm, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		$b .= '<p class="description">' . esc_html__( 'Site Health and the PHP notice are never removed.', 'launch-builder' ) . '</p></fieldset>';
		$b .= self::check( $f, __( 'Add a small Launch Builder overview widget for administrators', 'launch-builder' ), 'dashboard.lb_widget' );
		return self::card( 'lbb-dashboard', __( 'Client dashboard', 'launch-builder' ), '', $b );
	}

	private static function section_admin( array $f ): string {
		$b = self::check( $f, __( 'Replace the WordPress logo in the admin bar', 'launch-builder' ), 'admin.replace_wp_logo', __( 'With the client\'s logo (or yours below). Its link goes where you choose.', 'launch-builder' ) );
		$b .= '<div class="lbb-grid">';
		$b .= self::select( $f, __( 'Admin bar logo', 'launch-builder' ), 'admin.bar_logo_source', [ 'client' => __( 'The client\'s logo', 'launch-builder' ), 'agency' => __( 'The agency icon', 'launch-builder' ) ] );
		$b .= self::select( $f, __( 'Logo links to', 'launch-builder' ), 'admin.bar_link', [ 'dashboard' => __( 'The dashboard', 'launch-builder' ), 'site' => __( 'The website', 'launch-builder' ), 'agency' => __( 'The agency website (new tab)', 'launch-builder' ) ] );
		$b .= self::text( $f, __( 'Greeting', 'launch-builder' ), 'admin.greeting', __( 'Use %s for the name, for example "Hi, %s". Empty shows the name alone.', 'launch-builder' ), [ 'maxlength' => 40, 'placeholder' => 'Hi, %s' ] );
		$b .= self::text( $f, __( 'Browser tab suffix', 'launch-builder' ), 'admin.title_suffix', __( 'Replaces "WordPress" in the tab title, for example "Client Portal". Empty leaves it unchanged.', 'launch-builder' ), [ 'maxlength' => 40 ] );
		$b .= '</div>';
		$b .= self::check( $f, __( 'Show a logo above the admin menu', 'launch-builder' ), 'admin.menu_logo', __( 'Only people who sign in see it. Visitors never do.', 'launch-builder' ) );
		$b .= self::select( $f, __( 'Which logo', 'launch-builder' ), 'admin.menu_logo_source', [ 'client' => __( 'The client\'s logo', 'launch-builder' ), 'agency' => __( 'The agency icon', 'launch-builder' ) ] );
		$b .= self::media( $f, __( 'Logo for the dark menu', 'launch-builder' ), 'admin.menu_logo_image', __( 'Optional. A logo made for dark backgrounds. It is used when "The client\'s logo" is chosen.', 'launch-builder' ) );
		$b .= self::text( $f, __( 'Footer text', 'launch-builder' ), 'admin.footer_text', __( 'Plain text. {agency}, {agency_url}, {support_phone}, {support_email} and {business} are filled in.', 'launch-builder' ), [ 'maxlength' => 200, 'placeholder' => 'Website by {agency}' ] );
		$b .= self::text( $f, __( 'Footer link', 'launch-builder' ), 'admin.footer_link', __( 'Where {agency} links. Empty uses the agency website.', 'launch-builder' ), [ 'type' => 'url' ] );
		$b .= self::check( $f, __( 'Use the brand colour in the block editor', 'launch-builder' ), 'admin.editor_accent', __( 'Only applied when it has enough contrast on white.', 'launch-builder' ) );
		$b .= self::check( $f, __( 'Hide the WordPress version in the footer for clients', 'launch-builder' ), 'admin.hide_version_for_clients', __( 'Administrators always see it.', 'launch-builder' ) );
		return self::card( 'lbb-admin', __( 'Admin look', 'launch-builder' ), '', $b );
	}

	private static function section_email( array $f ): string {
		$b = self::radios( $f, __( 'Name on emails sent by the site', 'launch-builder' ), 'email.from_name_source', [
			'off'      => [ __( 'Leave as it is', 'launch-builder' ), __( 'WordPress keeps its own sender name.', 'launch-builder' ) ],
			'business' => [ __( 'The business name', 'launch-builder' ), __( 'From Business Info.', 'launch-builder' ) ],
			'agency'   => [ __( 'The agency name', 'launch-builder' ), '' ],
			'custom'   => [ __( 'A name I type', 'launch-builder' ), '' ],
		] );
		$b .= '<div class="lbb-grid">' . self::text( $f, __( 'Custom name', 'launch-builder' ), 'email.from_name', '', [ 'maxlength' => 80 ] );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$b .= self::text( $f, __( 'Sender address', 'launch-builder' ), 'email.from_email', sprintf( /* translators: %s: site domain */ __( 'Must be on this site\'s domain (%s). Only replaces the WordPress default; an SMTP plugin\'s own sender wins.', 'launch-builder' ), $host ), [ 'type' => 'email', 'maxlength' => 254 ] ) . '</div>';
		$smtp = Branding_Chrome::smtp_plugin();
		if ( '' !== $smtp ) {
			$b .= '<p class="lbb-note">' . esc_html__( 'An SMTP plugin is active on this site. Its sender settings take precedence over these.', 'launch-builder' ) . '</p>';
		}
		return self::card( 'lbb-email', __( 'Email', 'launch-builder' ), __( 'Contact form copies, password resets and notifications come from your client instead of "WordPress".', 'launch-builder' ), $b );
	}

	/** The preview's starting look: the same final colours the script computes, as custom properties. */
	private static function colors_preview_style( array $r ): string {
		$v = $r['v'];
		$map = [ '--a-side' => 'sidebar', '--a-side-text' => 'sidebar_text', '--a-sub' => 'sub', '--a-hl' => 'highlight', '--a-hl-text' => 'highlight_text', '--a-bar' => 'topbar', '--a-bar-text' => 'bar_text', '--a-acc' => 'accent', '--a-acc-text' => 'accent_text', '--a-link' => 'link' ];
		$out = '';
		foreach ( $map as $prop => $key ) {
			$out .= $prop . ':' . $v[ $key ] . ';';
		}
		return $out;
	}

	/** The mock of the dashboard the colours are tried on (no links, no focus stops; styled with custom properties set by the script). */
	private static function colors_preview( array $r ): string {
		[ $bn ] = Branding::business_name();
		$items = [ __( 'Dashboard', 'launch-builder' ) => '', __( 'Pages', 'launch-builder' ) => ' is-current', __( 'Posts', 'launch-builder' ) => '', __( 'Media', 'launch-builder' ) => '', __( 'Profile', 'launch-builder' ) => '' ];
		$h = '<div class="lbb-adm" id="lbb-adm" role="img" aria-label="' . esc_attr__( 'Preview of the dashboard colours', 'launch-builder' ) . '" style="' . esc_attr( self::colors_preview_style( $r ) ) . '">';
		$h .= '<div class="lbb-adm-bar"><span class="lbb-adm-dot"></span><span>' . esc_html( $bn ) . '</span><span class="lbb-adm-grow"></span><span>' . esc_html__( 'Hi, Sam', 'launch-builder' ) . '</span></div>';
		$h .= '<div class="lbb-adm-body"><div class="lbb-adm-side">';
		foreach ( $items as $label => $cls ) {
			$h .= '<div class="lbb-adm-item' . $cls . '"><span class="lbb-adm-ico"></span>' . esc_html( $label ) . '</div>';
			if ( '' !== $cls ) {
				$h .= '<div class="lbb-adm-sub"><span>' . esc_html__( 'All pages', 'launch-builder' ) . '</span><span>' . esc_html__( 'Add new', 'launch-builder' ) . '</span></div>';
			}
		}
		$h .= '</div><div class="lbb-adm-main"><p class="lbb-adm-title">' . esc_html__( 'Welcome', 'launch-builder' ) . '</p>';
		$h .= '<p>' . esc_html__( 'Edit your pages, add photos and check your site.', 'launch-builder' ) . ' <span class="lbb-adm-link">' . esc_html__( 'Open a page', 'launch-builder' ) . '</span></p>';
		$h .= '<p class="lbb-adm-actions"><span class="lbb-adm-btn">' . esc_html__( 'Save changes', 'launch-builder' ) . '</span><span class="lbb-adm-btn2">' . esc_html__( 'Cancel', 'launch-builder' ) . '</span></p>';
		$h .= '</div></div></div>';
		return $h;
	}

	/** Where a person picks a colour scheme for their own account. */
	private static function profile_url(): string {
		return admin_url( 'profile.php#color-picker' );
	}

	private static function section_colors( array $f ): string {
		$c = (array) ( $f['admin_colors'] ?? [] );
		$r = Branding_Colors::resolve( $c );
		$d = Branding_Colors::defaults();
		$b = '';
		if ( Branding_Colors::off() && ! Branding::safe() ) {
			$b .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'LB_ADMIN_COLORS_OFF is set in wp-config.php: the dashboard colours are off for everyone. You can still edit and save them here.', 'launch-builder' ) . '</p></div>';
		}
		$b .= '<div class="lbb-colors-grid"><div class="lbb-colors-fields">';
		$b .= self::radios( $f, __( 'Who sees these colours', 'launch-builder' ), 'admin_colors.scope', [
			'clients'  => [ __( 'Only client users (Site Owner role)', 'launch-builder' ), __( 'Your own screens stay as they are, and anyone who picked their own colour scheme keeps it.', 'launch-builder' ) ],
			'everyone' => [ __( 'Everyone', 'launch-builder' ), __( 'Replaces the colour scheme of every account, yours too. Text colours are always kept readable.', 'launch-builder' ) ],
			'nobody'   => [ __( 'Nobody (preview only)', 'launch-builder' ), __( 'Saves the colours without showing them to anyone. A person can still try them by choosing Dashboard colours on their profile page.', 'launch-builder' ) ],
		] );
		$b .= '<p class="description lbb-see-mine">' . esc_html__( 'Want a look first? Save your colours, then', 'launch-builder' ) . ' <a href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'see it on your account: Users, Profile, Dashboard colours', 'launch-builder' ) . '</a>.</p>';
		$b .= '<h3 id="lbb-schemes-h">' . esc_html__( 'Start from', 'launch-builder' ) . '</h3><div class="lbb-schemes" role="group" aria-labelledby="lbb-schemes-h">';
		$cfg = Branding_Colors::client_config();
		foreach ( $cfg['schemes'] as $id => $s ) {
			$dots = '';
			foreach ( [ 'sidebar', 'highlight', 'topbar', 'accent' ] as $k ) {
				$dots .= '<i style="background:' . esc_attr( $s[ $k ] ) . '"></i>';
			}
			$b .= '<button type="button" class="lb-pill lbb-scheme" data-scheme="' . esc_attr( $id ) . '" aria-pressed="false"><span class="lbb-dots" aria-hidden="true">' . $dots . '</span>' . esc_html( $s['label'] ) . '</button>';
		}
		$b .= '</div><input type="hidden" id="' . esc_attr( self::id( 'admin_colors.scheme' ) ) . '" name="' . esc_attr( self::name( 'admin_colors.scheme' ) ) . '" value="' . esc_attr( (string) ( $c['scheme'] ?? '' ) ) . '">';
		$b .= '</div><div class="lbb-colors-preview"><h3>' . esc_html__( 'Preview', 'launch-builder' ) . '</h3>';
		$b .= self::colors_preview( $r );
		$b .= '<ul class="lbb-contrast" id="lbb-colors-contrast"></ul><p class="screen-reader-text" id="lbb-colors-status" role="status" aria-live="polite"></p>';
		$b .= '<p class="description lbb-colors-note">' . esc_html__( 'Text that would be hard to read is corrected automatically, and the contrast is shown here. Colours are saved when you press Save settings.', 'launch-builder' ) . '</p></div>';
		$b .= '<div class="lbb-colors-more"><h3>' . esc_html__( 'Colours', 'launch-builder' ) . '</h3>';
		$reset = __( 'Use default', 'launch-builder' );
		$b .= self::color( $f, __( 'Sidebar background', 'launch-builder' ), 'admin_colors.sidebar', $d['sidebar'], '', $reset );
		$b .= self::color( $f, __( 'Sidebar text', 'launch-builder' ), 'admin_colors.sidebar_text', $r['v']['sidebar_text'], __( 'Empty picks a readable colour for you.', 'launch-builder' ), $reset );
		$b .= self::color( $f, __( 'Active and hover item', 'launch-builder' ), 'admin_colors.highlight', $d['highlight'], '', $reset );
		$b .= self::color( $f, __( 'Top bar', 'launch-builder' ), 'admin_colors.topbar', $d['topbar'], __( 'Inside the dashboard only. The bar on your website is not changed.', 'launch-builder' ), $reset );
		$b .= self::color( $f, __( 'Links and buttons', 'launch-builder' ), 'admin_colors.accent', $d['accent'], '', $reset );
		$b .= '<p class="lbb-reset-row"><button type="submit" class="lb-btn lb-btn--sm" form="lbb-colors-reset">' . esc_html__( 'Reset to WordPress default', 'launch-builder' ) . '</button> <span class="description">' . esc_html__( 'Puts every dashboard colour and the audience back. Other settings stay.', 'launch-builder' ) . '</span></p>';
		$b .= '</div></div>';
		return self::card( 'lbb-colors', __( 'Dashboard colours', 'launch-builder' ), __( 'Restyle the dashboard: the sidebar, the top bar, links and buttons. Only the dashboard changes, never your website.', 'launch-builder' ), $b );
	}

	private static function section_tools(): string {
		$b = '<h3>' . esc_html__( 'Move administrators to the Site Owner role', 'launch-builder' ) . '</h3>';
		$role = get_role( Branding::ROLE );
		$admins = self::convertible();
		if ( ! $role ) {
			$b .= '<p class="lbb-note">' . esc_html__( 'Create the Site Owner role first (Site Owner role section, then Save).', 'launch-builder' ) . '</p>';
		} elseif ( ! $admins ) {
			$b .= '<p class="lbb-note">' . esc_html__( 'There are no other administrators to move.', 'launch-builder' ) . '</p>';
		} else {
			$b .= '<p class="lbb-note">' . esc_html__( 'A client who was given an administrator account can be moved to the safer role. You are never listed, and at least one administrator always remains.', 'launch-builder' ) . '</p>';
			$b .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lb_branding_convert">';
			$b .= wp_nonce_field( 'lb_branding_convert', '_wpnonce', true, false );
			$b .= '<fieldset class="lbb-menus"><legend>' . esc_html__( 'Administrators', 'launch-builder' ) . '</legend>';
			foreach ( $admins as $u ) {
				$b .= '<label class="lbb-menu"><input type="checkbox" name="ids[]" value="' . (int) $u->ID . '"> ' . esc_html( $u->display_name ) . ' <span class="description">(' . esc_html( $u->user_login ) . ')</span></label>';
			}
			$b .= '</fieldset><p><button type="submit" class="button">' . esc_html__( 'Move selected to Site Owner', 'launch-builder' ) . '</button></p></form>';
		}
		$b .= '<h3>' . esc_html__( 'Export and import', 'launch-builder' ) . '</h3><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lb_branding_export' ), 'lb_branding_export' ) ) . '">' . esc_html__( 'Download settings (JSON)', 'launch-builder' ) . '</a></p>';
		$b .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="lbb-import"><input type="hidden" name="action" value="lb_branding_import">' . wp_nonce_field( 'lb_branding_import', '_wpnonce', true, false );
		$b .= '<p><label for="lbb-import-file">' . esc_html__( 'Import a settings file (up to 256 KB)', 'launch-builder' ) . '</label><br><input type="file" id="lbb-import-file" name="file" accept="application/json,.json"> <button type="submit" class="button">' . esc_html__( 'Import', 'launch-builder' ) . '</button></p><p class="description">' . esc_html__( 'Every value is checked like a normal save. Pictures are kept only when the same media library item exists here.', 'launch-builder' ) . '</p></form>';
		return self::card( 'lbb-tools', __( 'Move, export, import', 'launch-builder' ), '', $b );
	}

	/** Administrators who can be moved: not the current user, not a network super admin. @return \WP_User[] */
	private static function convertible(): array {
		$out = [];
		foreach ( get_users( [ 'role' => 'administrator', 'exclude' => [ get_current_user_id() ], 'orderby' => 'display_name', 'number' => 200 ] ) as $u ) {
			if ( ! ( is_multisite() && is_super_admin( $u->ID ) ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ form handlers */

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
	}

	/** $link: an optional [ url, label ] button shown under the message. */
	private static function back( string $type, string $text, array $lines = [], string $anchor = '', array $link = [] ): void {
		set_transient( self::MSG . get_current_user_id(), [ $type, $text, $lines, $link ], MINUTE_IN_SECONDS );
		wp_safe_redirect( Admin::url( Branding::PAGE ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	/** Save the form: the same validation as set-branding. */
	public static function save(): void {
		self::guard( 'lb_branding_save' );
		$in = isset( $_POST['lbb'] ) && is_array( $_POST['lbb'] ) ? wp_unslash( $_POST['lbb'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by Branding::apply().
		$in = is_array( $in ) ? $in : [];
		$menus = $in['client']['roles'][ Branding::ROLE ]['menus'] ?? null;
		if ( is_array( $menus ) ) {
			$menus = self::clean_posted_list( $menus );
			$same = $menus;
			$def = Branding_Client::MENU_DEFAULT;
			if ( ! empty( $in['client']['role']['flags']['comments'] ) ) {
				$def[] = 'edit-comments.php';
			}
			sort( $same );
			sort( $def );
			$in['client']['roles'][ Branding::ROLE ] = $same === $def ? null : [ 'menus' => $menus ]; // The default list needs no override.
		}
		foreach ( [ [ 'dashboard', 'welcome', 'tiles' ], [ 'dashboard', 'admin_remove' ] ] as $path ) {
			$in = self::list_at( $in, $path );
		}
		$r = Branding::apply( $in, [ 'source' => 'screen' ] );
		if ( is_wp_error( $r ) ) {
			self::back( 'error', __( 'Not saved.', 'launch-builder' ), (array) ( $r->get_error_data()['errors'] ?? [ $r->get_error_message() ] ) );
		}
		// An administrator is not in the default audience, so nothing changes on their own screen: say where to look.
		$link = Branding_Colors::customised() && 'everyone' !== (string) Branding::section( 'admin_colors' )['scope'] && ! Branding_Colors::off() ? [ self::profile_url(), __( 'See it on your account: Users, Profile, Dashboard colours', 'launch-builder' ) ] : [];
		self::back( 'success', $r['changed'] ? __( 'Saved. Changes apply from the next page load.', 'launch-builder' ) : __( 'Saved. Nothing changed.', 'launch-builder' ), array_merge( (array) ( $r['warnings'] ?? [] ), isset( $r['role_changes'] ) && $r['role_changes']['created'] ? [ __( 'The Site Owner role was created. Assign it to your client\'s account under Users.', 'launch-builder' ) ] : [] ), '', $link );
	}

	/** The strings of a posted list, without the empty sentinel the form adds so an all-unticked list still arrives. */
	private static function clean_posted_list( array $list ): array {
		return array_values( array_filter( $list, static function ( $x ) { return is_string( $x ) && '' !== $x; } ) );
	}

	/** Clean the posted list at a nested path of the form data. */
	private static function list_at( array $in, array $path ): array {
		$k = array_shift( $path );
		if ( ! isset( $in[ $k ] ) || ! is_array( $in[ $k ] ) ) {
			return $in;
		}
		$in[ $k ] = $path ? self::list_at( $in[ $k ], $path ) : self::clean_posted_list( $in[ $k ] );
		return $in;
	}

	/** Move selected administrators to the Site Owner role (never over MCP). */
	public static function convert(): void {
		self::guard( 'lb_branding_convert' );
		if ( ! current_user_can( 'promote_users' ) || ! get_role( Branding::ROLE ) ) {
			self::back( 'error', __( 'That is not possible right now.', 'launch-builder' ), [], 'lbb-tools' );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		$candidates = [];
		foreach ( self::convertible() as $u ) {
			$candidates[ $u->ID ] = $u;
		}
		$moved = [];
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! isset( $candidates[ $id ] ) ) {
				continue;
			}
			$admins_left = count( get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'exclude' => array_merge( [ $id ], $moved ) ] ) );
			if ( $admins_left < 1 ) {
				self::back( 'error', __( 'Refused: at least one administrator must remain.', 'launch-builder' ), [], 'lbb-tools' );
			}
			$candidates[ $id ]->set_role( Branding::ROLE );
			$moved[] = $id;
			self::log( 'branding.convert', true, 'user ' . $candidates[ $id ]->user_login . ' moved to ' . Branding::ROLE );
		}
		self::back( $moved ? 'success' : 'error', $moved ? sprintf( /* translators: %d: number of users */ _n( '%d administrator moved to the Site Owner role.', '%d administrators moved to the Site Owner role.', count( $moved ), 'launch-builder' ), count( $moved ) ) : __( 'Nobody was selected.', 'launch-builder' ), [], 'lbb-tools' );
	}

	/** One row in the shared activity log (same format as the tool calls). */
	private static function log( string $tool, bool $ok, string $note ): void {
		$log = (array) get_option( 'launch_builder_log', [] );
		array_unshift( $log, [ 't' => time(), 'u' => get_current_user_id(), 'tool' => $tool, 'ok' => $ok, 'note' => $note ] );
		update_option( 'launch_builder_log', array_slice( $log, 0, 200 ), false );
	}

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_branding_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="launch-builder-branding.json"' );
		echo wp_json_encode( Branding::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	public static function import(): void {
		self::guard( 'lb_branding_import' );
		$json = '';
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			if ( (int) ( $_FILES['file']['size'] ?? 0 ) > 262144 ) {
				self::back( 'error', __( 'That file is larger than 256 KB.', 'launch-builder' ), [], 'lbb-tools' );
			}
			$json = (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security, WordPress.WP.AlternativeFunctions -- read once, parsed as JSON only.
		}
		$obj = json_decode( $json, true );
		if ( ! is_array( $obj ) ) {
			self::back( 'error', __( 'That is not a valid settings file.', 'launch-builder' ), [], 'lbb-tools' );
		}
		$r = Branding::tool_set( [ 'import' => $obj ] );
		if ( is_wp_error( $r ) ) {
			self::back( 'error', $r->get_error_message(), (array) ( $r->get_error_data()['errors'] ?? [] ), 'lbb-tools' );
		}
		self::back( 'success', __( 'Imported.', 'launch-builder' ), array_merge( (array) ( $r['warnings'] ?? [] ), array_map( static function ( $d ) { return sprintf( /* translators: %s: reason */ __( 'Skipped: %s', 'launch-builder' ), $d ); }, (array) ( $r['import_dropped'] ?? [] ) ) ), 'lbb-tools' );
	}

	/** Put the dashboard colours back to the WordPress default (and the audience to its default). */
	public static function reset_colors(): void {
		self::guard( 'lb_branding_reset_colors' );
		if ( ! Branding::configured() ) { // Nothing was ever saved: a reset must not switch Branding on.
			self::back( 'success', __( 'Nothing to reset yet. The dashboard colours are already the WordPress default.', 'launch-builder' ), [], 'lbb-colors' );
		}
		$r = Branding::apply( [], [ 'reset' => [ 'admin_colors' ], 'source' => 'screen' ] );
		if ( is_wp_error( $r ) ) {
			self::back( 'error', $r->get_error_message(), [], 'lbb-colors' );
		}
		self::back( 'success', $r['changed'] ? __( 'The dashboard colours are back to the WordPress default.', 'launch-builder' ) : __( 'The dashboard colours were already the WordPress default.', 'launch-builder' ), [], 'lbb-colors' );
	}
}
