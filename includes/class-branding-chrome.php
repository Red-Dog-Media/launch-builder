<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The visible branding surfaces of the Branding module: the sign-in screen, the admin bar logo and greeting, the admin
 * footer, page titles, the editor accent colour, the sender of WordPress emails and Launch Builder's own menu label,
 * icon, plugin row and block category. Nothing here runs unless Branding::active() and the surface's own switch are on.
 *
 * Sign-in screen: only core filters and one inline stylesheet on the "login" handle (no script, no jQuery, nothing
 * printed late). Every rule is scoped to body.login, so interim-login, two-factor and limit-login screens keep their own
 * layout. The generated CSS uses custom properties on the page root, so the settings screen preview can reuse it under
 * another scope and update it live. Colours default to the brand palette; a contrast guard corrects unreadable
 * combinations and reports them.
 */
final class Branding_Chrome {

	/** Elements and attributes an agency icon may use (nothing that can run code or load anything). */
	const SVG_TAGS = [
		'svg'      => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'aria-hidden' => true, 'focusable' => true ],
		'g'        => [ 'fill' => true, 'stroke' => true, 'transform' => true, 'fill-rule' => true, 'clip-rule' => true ],
		'path'     => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'transform' => true, 'fill-rule' => true, 'clip-rule' => true ],
		'circle'   => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
		'rect'     => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
		'polygon'  => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
		'polyline' => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
		'line'     => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ],
	];

	/* ------------------------------------------------------------------ colours */

	/** The candidate with the best contrast on $surface; the first that reaches $min wins. */
	private static function best( string $surface, array $candidates, float $min ): string {
		$best = $candidates[0];
		$bestr = 0.0;
		foreach ( $candidates as $c ) {
			$r = Style::contrast( $c, $surface );
			if ( $r >= $min ) {
				return $c;
			}
			if ( $r > $bestr ) {
				$best = $c;
				$bestr = $r;
			}
		}
		return $best;
	}

	/**
	 * The sign-in screen's colours, from the brand palette unless a colour is set, with the contrast guard applied.
	 *
	 * @param array $l login settings (Branding::section( 'login' ) or the same shape).
	 * @return array{colors:array<string,string>,sources:array<string,string>,warnings:string[],contrast:array<string,float>}
	 */
	public static function login_vars( array $l ): array {
		$pal = Style::palette();
		$slug = static function ( string $s ) use ( $pal ): string {
			return (string) Style::hex( $pal[ $s ] ?? '#FFFFFF' );
		};
		$pick = static function ( $override, string $palette_slug ) use ( $slug ): array {
			if ( is_string( $override ) && '' !== $override ) {
				$h = Style::hex( $override );
				if ( $h ) {
					return [ $h, 'override' ];
				}
			}
			return [ $slug( $palette_slug ), 'palette' ];
		};
		$warn = [];
		$src = [];
		[ $bg, $src['bg'] ] = $pick( $l['bg'] ?? '', 'palette8' );
		[ $form, $src['form_bg'] ] = $pick( $l['form_bg'] ?? '', 'palette9' );
		[ $text, $src['text'] ] = $pick( $l['text'] ?? '', 'palette3' );
		[ $btn, $src['button_bg'] ] = $pick( $l['button_bg'] ?? '', 'palette1' );
		[ $hover, $src['button_hover_bg'] ] = $pick( $l['button_hover_bg'] ?? '', 'palette2' );
		[ $link, $src['link'] ] = $pick( $l['link'] ?? '', 'palette1' );
		$has_image = ! empty( $l['bg_image'] );
		$ratio = [];

		// Text on the form.
		$ratio['text_on_form'] = Style::contrast( $text, $form );
		if ( $ratio['text_on_form'] < Style::MIN_TEXT ) {
			$fixed = self::best( $form, [ $slug( 'palette3' ), '#1D2327', '#FFFFFF' ], Style::MIN_TEXT );
			/* translators: 1: colour, 2: ratio, 3: corrected colour */
			$warn[] = sprintf( __( 'Sign-in text %1$s has %2$s:1 contrast on the form (4.5:1 needed), so %3$s is used.', 'launch-builder' ), $text, $ratio['text_on_form'], $fixed );
			$text = $fixed;
			$src['text'] = 'auto';
			$ratio['text_on_form'] = Style::contrast( $text, $form );
		}

		// Button label on the button.
		$btn_text_over = isset( $l['button_text'] ) && '' !== $l['button_text'] ? Style::hex( $l['button_text'] ) : null;
		$btn_text = $btn_text_over ? $btn_text_over : (string) self::best( $btn, [ $slug( 'palette9' ), $slug( 'palette3' ), '#FFFFFF', '#111111' ], Style::MIN_TEXT );
		$src['button_text'] = $btn_text_over ? 'override' : 'palette';
		$ratio['button_text_on_button'] = Style::contrast( $btn_text, $btn );
		if ( $ratio['button_text_on_button'] < Style::MIN_TEXT ) {
			$fixed = self::best( $btn, [ $slug( 'palette9' ), $slug( 'palette3' ), '#FFFFFF', '#111111' ], Style::MIN_TEXT );
			if ( $btn_text_over ) {
				$warn[] = sprintf( /* translators: 1: colour, 2: ratio, 3: corrected colour */ __( 'Button text %1$s has %2$s:1 contrast on the button colour (4.5:1 needed), so %3$s is used.', 'launch-builder' ), $btn_text, $ratio['button_text_on_button'], $fixed );
			}
			$btn_text = $fixed;
			$src['button_text'] = 'auto';
			$ratio['button_text_on_button'] = Style::contrast( $btn_text, $btn );
		}

		// The button against the form (a UI component needs 3:1) and its hover state.
		$ratio['button_on_form'] = Style::contrast( $btn, $form );
		if ( $ratio['button_on_form'] < Style::MIN_UI ) {
			$warn[] = sprintf( /* translators: 1: ratio */ __( 'The button colour has only %s:1 contrast against the form surface (3:1 needed to see it).', 'launch-builder' ), $ratio['button_on_form'] );
		}
		$ratio['hover_on_form'] = Style::contrast( $hover, $form );
		if ( $ratio['hover_on_form'] < Style::MIN_UI ) {
			$dark = Style::blend( '#000000', $btn, 0.25 );
			$warn[] = sprintf( /* translators: 1: ratio, 2: corrected colour */ __( 'The button hover colour has %1$s:1 contrast against the form (3:1 needed), so %2$s is used.', 'launch-builder' ), $ratio['hover_on_form'], $dark );
			$hover = $dark;
			$src['button_hover_bg'] = 'auto';
			$ratio['hover_on_form'] = Style::contrast( $hover, $form );
		}
		$hover_text = $btn_text;
		if ( Style::contrast( $hover_text, $hover ) < Style::MIN_TEXT ) {
			$hover_text = self::best( $hover, [ $slug( 'palette9' ), $slug( 'palette3' ), '#FFFFFF', '#111111' ], Style::MIN_TEXT );
		}
		$ratio['button_hover_text'] = Style::contrast( $hover_text, $hover );

		// Links sit below the form, on the page background (on the form colour when a background picture is used).
		$link_surface = $has_image ? $form : $bg;
		$ratio['link_on_page'] = Style::contrast( $link, $link_surface );
		if ( $ratio['link_on_page'] < Style::MIN_TEXT ) {
			$fixed = self::best( $link_surface, [ $slug( 'palette2' ), $slug( 'palette3' ), '#1D2327', '#FFFFFF' ], Style::MIN_TEXT );
			$warn[] = sprintf( /* translators: 1: colour, 2: ratio, 3: corrected colour */ __( 'Link colour %1$s has %2$s:1 contrast on the page background (4.5:1 needed), so %3$s is used.', 'launch-builder' ), $link, $ratio['link_on_page'], $fixed );
			$link = $fixed;
			$src['link'] = 'auto';
			$ratio['link_on_page'] = Style::contrast( $link, $link_surface );
		}

		// Text printed on the page background (the business name when there is no logo).
		$logo_text = $has_image ? $text : self::best( $bg, [ $text, $slug( 'palette3' ), '#1D2327', '#FFFFFF' ], Style::MIN_TEXT );
		$ratio['name_on_page'] = Style::contrast( $logo_text, $has_image ? $form : $bg );

		return [
			'colors'   => [ 'bg' => $bg, 'form_bg' => $form, 'text' => $text, 'button_bg' => $btn, 'button_text' => $btn_text, 'button_hover_bg' => $hover, 'button_hover_text' => $hover_text, 'link' => $link, 'logo_text' => $logo_text ],
			'sources'  => $src + [ 'button_hover_text' => 'auto', 'logo_text' => 'auto' ],
			'warnings' => $warn,
			'contrast' => $ratio,
		];
	}

	/** Contrast ratios of the current sign-in colours (for get-branding). @return array<string,float> */
	public static function contrast_report(): array {
		return self::login_vars( Branding::section( 'login' ) )['contrast'];
	}

	/* ------------------------------------------------------------------ sign-in screen */

	/**
	 * The logo the sign-in screen shows: the chosen source, then the others. id 0 = none (the business name is shown as text).
	 *
	 * @return array{id:int,url:string,w:int,h:int,from:string}
	 */
	public static function login_logo( array $l ): array {
		$agency = Branding::agency();
		$by = [ 'client' => Branding::client_logo_id(), 'agency' => (int) $agency['logo_id'], 'custom' => Branding::usable_attachment( (int) ( $l['logo'] ?? 0 ) ) ];
		$order = [ 'client' => [ 'client', 'agency' ], 'agency' => [ 'agency', 'client' ], 'custom' => [ 'custom', 'client', 'agency' ] ][ $l['logo_source'] ?? 'client' ] ?? [ 'client', 'agency' ];
		$width = max( 80, min( 320, (int) ( $l['logo_width'] ?? 200 ) ) );
		foreach ( $order as $from ) {
			$id = $by[ $from ];
			if ( ! $id ) {
				continue;
			}
			$src = wp_get_attachment_image_src( $id, 'large' );
			$url = $src ? (string) $src[0] : (string) wp_get_attachment_url( $id );
			if ( '' === $url ) {
				continue;
			}
			$sw = $src ? (int) $src[1] : 0;
			$sh = $src ? (int) $src[2] : 0;
			$h = $sw > 0 && $sh > 0 ? (int) round( $width * $sh / $sw ) : (int) round( $width * 0.4 );
			if ( $h > 160 ) {
				$width = (int) round( $width * 160 / $h );
				$h = 160;
			}
			return [ 'id' => $id, 'url' => esc_url_raw( $url ), 'w' => $width, 'h' => max( 24, $h ), 'from' => $from ];
		}
		return [ 'id' => 0, 'url' => '', 'w' => $width, 'h' => 0, 'from' => 'none' ];
	}

	/**
	 * The sign-in CSS. $scope: 'body.login' for the real page, or the class of the settings screen's mock (which holds
	 * the same elements: h1 a, form, label, .button-primary, #nav, #backtoblog).
	 */
	public static function login_css( array $l, string $scope = 'body.login' ): string {
		$v = self::login_vars( $l );
		$c = $v['colors'];
		$logo = self::login_logo( $l );
		$bg_img = Branding::usable_attachment( (int) ( $l['bg_image'] ?? 0 ) );
		$P = $scope;
		$o = number_format( max( 0, min( 80, (int) ( $l['bg_overlay'] ?? 40 ) ) ) / 100, 2, '.', '' );
		$vars = '--lbb-bg:' . $c['bg'] . ';--lbb-form:' . $c['form_bg'] . ';--lbb-text:' . $c['text'] . ';--lbb-btn:' . $c['button_bg'] . ';--lbb-btn-text:' . $c['button_text'] . ';--lbb-hover:' . $c['button_hover_bg'] . ';--lbb-hover-text:' . $c['button_hover_text'] . ';--lbb-link:' . $c['link'] . ';--lbb-name:' . $c['logo_text'] . ';--lbb-chip:' . ( $bg_img ? $c['form_bg'] : 'transparent' ) . ';';
		$css = $P . '{' . $vars . 'background-color:var(--lbb-bg);color:var(--lbb-text)';
		if ( $bg_img ) {
			$css .= ';background-image:linear-gradient(rgba(0,0,0,' . $o . '),rgba(0,0,0,' . $o . ')),url("' . Branding::css_url( Branding::attachment_url( $bg_img, 'full' ) ) . '");background-size:cover;background-position:center';
		}
		$css .= '}';
		if ( $logo['id'] ) {
			$css .= $P . ' h1 a{background-image:url("' . Branding::css_url( $logo['url'] ) . '");background-size:contain;background-position:center;background-repeat:no-repeat;width:' . (int) $logo['w'] . 'px;max-width:100%;height:' . (int) $logo['h'] . 'px;margin:0 auto 24px}';
		} else {
			$css .= $P . ' h1 a{background-image:none;text-indent:0;overflow:visible;width:auto;height:auto;font-size:24px;line-height:1.3;font-weight:600;color:var(--lbb-name);text-decoration:none;margin:0 auto 20px}';
		}
		$mock = 'body.login' !== $scope; // the preview mock is a div (a form inside the settings form would close it)
		$css .= $P . ' form' . ( $mock ? ',' . $P . ' .lbb-mock-form' : '' ) . '{background:var(--lbb-form);color:var(--lbb-text);border:0;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.18)}';
		$css .= $P . ' label{color:var(--lbb-text)}';
		$css .= $P . ' form input:focus' . ( $mock ? ',' . $P . ' .lbb-mock-form input:focus' : '' ) . '{border-color:var(--lbb-btn);box-shadow:0 0 0 1px var(--lbb-btn)}';
		$css .= $P . ' .button-primary{background:var(--lbb-btn);border-color:var(--lbb-btn);color:var(--lbb-btn-text);text-shadow:none;box-shadow:none}';
		$css .= $P . ' .button-primary:hover,' . $P . ' .button-primary:focus{background:var(--lbb-hover);border-color:var(--lbb-hover);color:var(--lbb-hover-text)}';
		$css .= $P . ' .button-primary:focus{box-shadow:0 0 0 1px var(--lbb-form),0 0 0 3px var(--lbb-btn)}';
		$css .= $P . ' #nav,' . $P . ' #backtoblog,' . $P . ' .privacy-policy-page-link,' . $P . ' .lb-login-credit{background:var(--lbb-chip);border-radius:6px}';
		$css .= $P . ' #nav a,' . $P . ' #backtoblog a,' . $P . ' .privacy-policy-page-link a,' . $P . ' .lb-login-credit a{color:var(--lbb-link)}';
		$css .= $P . ' #nav a:hover,' . $P . ' #backtoblog a:hover,' . $P . ' .privacy-policy-page-link a:hover,' . $P . ' .lb-login-credit a:hover{color:var(--lbb-link);text-decoration:underline}';
		$css .= $P . ' .message{border-left-color:var(--lbb-btn)}';
		$css .= $P . ' .lb-login-message{color:var(--lbb-name);text-align:center;margin:0 0 16px;font-size:14px}';
		$css .= $P . ' .lb-login-credit{text-align:center;font-size:12px;margin:12px auto;padding:6px 12px;width:fit-content;color:var(--lbb-text)}';
		if ( ! empty( $l['hide_back_link'] ) ) {
			$css .= $P . ' #backtoblog{display:none}';
		}
		return $css;
	}

	/** login_init: attach the filters of the branded sign-in screen. */
	public static function login(): void {
		if ( ! Branding::active() ) {
			return;
		}
		$l = Branding::section( 'login' );
		if ( empty( $l['enabled'] ) ) {
			return;
		}
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_styles' ] );
		add_filter( 'login_headerurl', [ __CLASS__, 'login_url' ] );
		add_filter( 'login_headertext', [ __CLASS__, 'login_text' ] );
		add_filter( 'login_title', [ __CLASS__, 'login_title' ], 10, 2 );
		if ( '' !== $l['message'] ) {
			add_filter( 'login_message', [ __CLASS__, 'login_message' ] );
		}
		if ( ! empty( $l['credit'] ) ) {
			add_action( 'login_footer', [ __CLASS__, 'login_credit' ] );
		}
	}

	public static function login_styles(): void {
		wp_add_inline_style( 'login', self::login_css( Branding::section( 'login' ) ) );
	}

	public static function login_url(): string {
		return home_url( '/' );
	}

	public static function login_text(): string {
		return esc_html( Branding::business_name()[0] );
	}

	/** "{page} ‹ {business}" (core's own line ends with "— WordPress"). */
	public static function login_title( $title, $page = '' ): string {
		return esc_html( (string) $page ) . ' &lsaquo; ' . esc_html( Branding::business_name()[0] );
	}

	public static function login_message( $message ): string {
		$m = Branding::section( 'login' )['message'];
		return '<p class="lb-login-message">' . esc_html( $m ) . '</p>' . $message;
	}

	public static function login_credit(): void {
		$a = Branding::agency();
		$name = esc_html( $a['name'] );
		$link = '' !== $a['url'] ? '<a href="' . esc_url( $a['url'] ) . '" rel="noopener">' . $name . '</a>' : $name;
		echo '<p class="lb-login-credit">' . sprintf( /* translators: %s: agency name (a link) */ esc_html__( 'Website by %s', 'launch-builder' ), $link ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $link is escaped above.
	}

	/* ------------------------------------------------------------------ admin bar */

	/** admin_bar_init: only requests that show the admin bar get here. */
	public static function admin_bar_init(): void {
		if ( ! Branding::active() ) {
			return;
		}
		$a = Branding::section( 'admin' );
		if ( ! empty( $a['replace_wp_logo'] ) ) {
			add_action( 'admin_bar_menu', [ __CLASS__, 'bar_logo' ], 11 );
			add_action( is_admin() ? 'admin_head' : 'wp_head', [ __CLASS__, 'bar_styles' ], 99 );
		}
		if ( '' !== $a['greeting'] ) {
			add_action( 'admin_bar_menu', [ __CLASS__, 'bar_greeting' ], 9999 );
		}
		Branding_Client::bar_init();
	}

	/** @return array{id:int,url:string,from:string} the admin bar / menu logo for a source (agency | client), with fallbacks. */
	public static function bar_logo_choice( string $source ): array {
		$agency = Branding::agency();
		$client = Branding::client_logo_id();
		$ids = 'agency' === $source ? [ 'agency' => (int) $agency['logo_square_id'], 'agency_logo' => (int) $agency['logo_id'], 'client' => $client ] : [ 'client' => $client, 'agency' => (int) $agency['logo_square_id'], 'agency_logo' => (int) $agency['logo_id'] ];
		foreach ( $ids as $from => $id ) {
			if ( $id ) {
				$url = Branding::attachment_url( $id, 'medium' );
				if ( '' !== $url ) {
					return [ 'id' => $id, 'url' => $url, 'from' => $from ];
				}
			}
		}
		$icon = Branding::site_icon_id();
		if ( $icon ) {
			return [ 'id' => $icon, 'url' => Branding::attachment_url( $icon, 'thumbnail' ), 'from' => 'site_icon' ];
		}
		return [ 'id' => 0, 'url' => '', 'from' => 'none' ];
	}

	/** Replace the WordPress logo node with the brand node (image, or the name as text when there is no image). */
	public static function bar_logo( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$a = Branding::section( 'admin' );
		$agency = Branding::agency();
		$choice = self::bar_logo_choice( (string) $a['bar_logo_source'] );
		$name = 'agency' === $a['bar_logo_source'] ? $agency['name'] : Branding::business_name()[0];
		$to_agency = 'agency' === $a['bar_link'] && '' !== $agency['url'];
		$href = 'site' === $a['bar_link'] ? home_url( '/' ) : ( $to_agency ? $agency['url'] : admin_url() );
		$title = $choice['id']
			? '<img src="' . esc_url( $choice['url'] ) . '" alt="" height="20"><span class="screen-reader-text">' . esc_html( $name ) . ( $to_agency ? ' ' . esc_html__( '(opens in a new tab)', 'launch-builder' ) : '' ) . '</span>'
			: '<span class="ab-label">' . esc_html( $name ) . '</span>';
		$bar->remove_node( 'wp-logo' );
		$meta = [ 'class' => 'lb-brand-node' ];
		if ( $to_agency ) {
			$meta['target'] = '_blank';
			$meta['rel'] = 'noopener';
		}
		$bar->add_node( [ 'id' => 'lb-brand', 'title' => $title, 'href' => $href, 'meta' => $meta ] );
	}

	public static function bar_styles(): void {
		echo '<style id="lb-brand-bar">#wpadminbar #wp-admin-bar-lb-brand>.ab-item{display:flex;align-items:center;padding:0 8px;gap:6px}#wpadminbar #wp-admin-bar-lb-brand img{height:20px;width:auto;max-width:140px;object-fit:contain}#wpadminbar #wp-admin-bar-lb-brand .ab-label{font-weight:600}</style>';
	}

	/** Rebuild the account node's greeting from the template ("Hi, %s"), keeping core's avatar markup. */
	public static function bar_greeting( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! $bar->get_node( 'my-account' ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		$tpl = (string) Branding::section( 'admin' )['greeting'];
		$name = '<span class="display-name">' . esc_html( $user->display_name ) . '</span>';
		$html = '' === $tpl ? $name : str_replace( '%s', $name, esc_html( $tpl ) );
		$avatar = get_avatar( $user->ID, 26 );
		$meta = (array) $bar->get_node( 'my-account' )->meta; // Keep what core set (menu title, tabindex...).
		$meta['class'] = $avatar ? 'with-avatar' : '';
		$bar->add_node( [ 'id' => 'my-account', 'title' => $html . $avatar, 'meta' => $meta ] );
	}

	/* ------------------------------------------------------------------ wp-admin chrome */

	/** Register the wp-admin surfaces that are switched on (called once at boot for admin requests). */
	public static function admin(): void {
		if ( ! Branding::active() ) {
			return;
		}
		$a = Branding::section( 'admin' );
		$lb = Branding::section( 'lb' );
		if ( '' !== $a['footer_text'] ) {
			add_filter( 'admin_footer_text', [ __CLASS__, 'footer_text' ], 9999 );
		}
		if ( ! empty( $a['hide_version_for_clients'] ) ) {
			add_filter( 'update_footer', [ __CLASS__, 'footer_version' ], 9999 );
		}
		if ( '' !== $a['title_suffix'] ) {
			add_filter( 'admin_title', [ __CLASS__, 'admin_title' ], 20, 2 );
		}
		if ( ! empty( $a['menu_logo'] ) || ! empty( $a['editor_accent'] ) ) {
			add_action( 'admin_head', [ __CLASS__, 'admin_head' ] );
		}
		if ( ! empty( $lb['enabled'] ) ) {
			add_action( 'admin_menu', [ __CLASS__, 'lb_menu' ], 999 );
			add_filter( 'all_plugins', [ __CLASS__, 'lb_plugin_row' ] );
			add_filter( 'block_categories_all', [ __CLASS__, 'lb_block_category' ], 20 );
		}
	}

	/** Footer text with tokens {agency} {agency_url} {support_phone} {support_email} {business}, each replaced and escaped here. */
	public static function footer_text( $text ): string {
		$a = Branding::section( 'admin' );
		$agency = Branding::agency();
		$parts = preg_split( '/(\{(?:agency|agency_url|support_phone|support_email|business)\})/', (string) $a['footer_text'], -1, PREG_SPLIT_DELIM_CAPTURE );
		$link = '' !== $a['footer_link'] ? $a['footer_link'] : $agency['url'];
		$out = '';
		foreach ( (array) $parts as $p ) {
			switch ( $p ) {
				case '{agency}':
					$out .= '' !== $link ? '<a href="' . esc_url( $link ) . '" rel="noopener">' . esc_html( $agency['name'] ) . '</a>' : esc_html( $agency['name'] );
					break;
				case '{agency_url}':
					$out .= esc_html( $agency['url'] );
					break;
				case '{support_phone}':
					$tel = preg_replace( '/[^0-9+]/', '', $agency['support_phone'] );
					$out .= '' !== $tel ? '<a href="' . esc_url( 'tel:' . $tel ) . '">' . esc_html( $agency['support_phone'] ) . '</a>' : '';
					break;
				case '{support_email}':
					$out .= '' !== $agency['support_email'] ? '<a href="' . esc_url( 'mailto:' . $agency['support_email'] ) . '">' . esc_html( $agency['support_email'] ) . '</a>' : '';
					break;
				case '{business}':
					$out .= esc_html( Branding::business_name()[0] );
					break;
				default:
					$out .= esc_html( $p );
			}
		}
		return '<span id="footer-thankyou">' . $out . '</span>';
	}

	/** The WordPress version in the footer goes for client accounts only; administrators always see it. */
	public static function footer_version( $text ) {
		return Branding_Client::is_client() ? '' : $text;
	}

	/** "{page} ‹ {site} — {suffix}" (core escapes the title where it prints it). */
	public static function admin_title( $title, $page = '' ): string {
		$suffix = (string) Branding::section( 'admin' )['title_suffix'];
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return $page . ' ‹ ' . $site . ( '' !== $suffix ? ' — ' . $suffix : '' );
	}

	/** admin_head: the logo above the admin menu (CSS only) and the editor accent colour. */
	public static function admin_head(): void {
		$a = Branding::section( 'admin' );
		$css = '';
		if ( ! empty( $a['menu_logo'] ) ) {
			$inv = Company::resolve()['logo_inverse'] ?? [];
			$id = 'client' === $a['menu_logo_source'] && ! empty( $inv['id'] ) ? Branding::usable_attachment( (int) $inv['id'] ) : 0;
			$choice = $id ? [ 'id' => $id, 'url' => Branding::attachment_url( $id, 'medium' ) ] : self::bar_logo_choice( (string) $a['menu_logo_source'] );
			if ( $choice['id'] && '' !== $choice['url'] ) {
				$icon = Branding::attachment_url( Branding::site_icon_id(), 'thumbnail' );
				$css .= '#adminmenuwrap::before{content:"";display:block;height:48px;margin:8px 12px 4px;background:url("' . Branding::css_url( $choice['url'] ) . '") no-repeat left center/contain}';
				$css .= '.folded #adminmenuwrap::before{' . ( '' !== $icon ? 'background-image:url("' . Branding::css_url( $icon ) . '");background-position:center' : 'display:none' ) . '}';
				$css .= '@media only screen and (max-width:960px){.auto-fold #adminmenuwrap::before{' . ( '' !== $icon ? 'background-image:url("' . Branding::css_url( $icon ) . '");background-position:center' : 'display:none' ) . '}}';
			}
		}
		if ( ! empty( $a['editor_accent'] ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$accent = Style::hex( 'palette1' );
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() && $accent && Style::contrast( $accent, '#FFFFFF' ) >= Style::MIN_TEXT ) {
				$rgb = sscanf( $accent, '#%2x%2x%2x' );
				$d10 = Style::blend( '#000000', $accent, 0.1 );
				$d20 = Style::blend( '#000000', $accent, 0.2 );
				$css .= 'body.wp-admin{--wp-admin-theme-color:' . $accent . ';--wp-admin-theme-color--rgb:' . implode( ',', $rgb ) . ';--wp-admin-theme-color-darker-10:' . $d10 . ';--wp-admin-theme-color-darker-20:' . $d20 . '}';
			}
		}
		if ( '' !== $css ) {
			echo '<style id="lb-branding-admin">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from validated colours and encoded URLs.
		}
	}

	/* ------------------------------------------------------------------ Launch Builder's own name */

	public static function lb_menu(): void {
		global $menu;
		$lb = Branding::section( 'lb' );
		foreach ( (array) $menu as $k => $item ) {
			if ( ! isset( $item[2] ) || Admin::SLUG !== $item[2] ) {
				continue;
			}
			if ( '' !== $lb['menu_label'] ) {
				$menu[ $k ][0] = esc_html( $lb['menu_label'] );
				$menu[ $k ][3] = $lb['menu_label'];
			}
			$icon = (string) $lb['menu_icon'];
			if ( 'agency' === $icon && '' !== Branding::agency()['icon_svg'] ) {
				$menu[ $k ][6] = 'data:image/svg+xml;base64,' . base64_encode( Branding::agency()['icon_svg'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- WordPress expects a base64 data URI for SVG menu icons.
			} elseif ( 0 === strpos( $icon, 'dashicons-' ) ) {
				$menu[ $k ][6] = $icon;
			}
		}
	}

	/** The plugin's row on the Plugins screen: name, author and description. Version and update source are untouched. */
	public static function lb_plugin_row( $plugins ) {
		$file = plugin_basename( LB_DIR . 'launch-builder.php' );
		if ( ! is_array( $plugins ) || ! isset( $plugins[ $file ] ) ) {
			return $plugins;
		}
		$lb = Branding::section( 'lb' );
		$agency = Branding::agency();
		if ( '' !== $lb['menu_label'] ) {
			$plugins[ $file ]['Name'] = $lb['menu_label'];
			$plugins[ $file ]['Title'] = $lb['menu_label'];
		}
		$plugins[ $file ]['Author'] = $agency['name'];
		$plugins[ $file ]['AuthorName'] = $agency['name'];
		$plugins[ $file ]['AuthorURI'] = $agency['url'];
		if ( '' !== $lb['description'] ) {
			$plugins[ $file ]['Description'] = $lb['description'];
		}
		return $plugins;
	}

	public static function lb_block_category( $categories ) {
		$label = (string) Branding::section( 'lb' )['menu_label'];
		if ( '' === $label || ! is_array( $categories ) ) {
			return $categories;
		}
		foreach ( $categories as $i => $cat ) {
			if ( is_array( $cat ) && 'launch-builder' === ( $cat['slug'] ?? '' ) ) {
				$categories[ $i ]['title'] = $label;
			}
		}
		return $categories;
	}

	/* ------------------------------------------------------------------ email sender */

	/** The site's registrable-domain check for a sender address: same host, or one is a subdomain of the other. */
	public static function same_domain( string $email ): bool {
		if ( ! is_email( $email ) || preg_match( '/[\r\n]/', $email ) ) {
			return false;
		}
		$d = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		$h = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$h = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		if ( '' === $d || '' === $h ) {
			return false;
		}
		return $d === $h || substr( $d, -strlen( '.' . $h ) ) === '.' . $h || substr( $h, -strlen( '.' . $d ) ) === '.' . $d;
	}

	/** Only core's default "WordPress" is replaced; a name another plugin set is left alone. */
	public static function mail_from_name( $name ) {
		if ( 'WordPress' !== $name || ! Branding::active() ) {
			return $name;
		}
		$e = Branding::section( 'email' );
		switch ( $e['from_name_source'] ) {
			case 'business':
				$new = Branding::business_name()[0];
				break;
			case 'agency':
				$new = Branding::agency()['name'];
				break;
			case 'custom':
				$new = (string) $e['from_name'];
				break;
			default:
				$new = '';
		}
		$new = trim( str_replace( [ "\r", "\n" ], ' ', $new ) );
		return '' !== $new ? $new : $name;
	}

	/** Only core's default wordpress@{domain} is replaced, and only by an address on this site's own domain. */
	public static function mail_from( $email ) {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		if ( 'wordpress@' . $host !== $email || ! Branding::active() ) {
			return $email;
		}
		$new = (string) Branding::section( 'email' )['from_email'];
		return '' !== $new && self::same_domain( $new ) ? $new : $email;
	}

	/** Which SMTP plugin (if any) is active: its sender settings win over ours. @return string slug or '' */
	public static function smtp_plugin(): string {
		if ( class_exists( '\WPMailSMTP\Core', false ) || function_exists( 'wp_mail_smtp' ) ) {
			return 'wp_mail_smtp';
		}
		if ( defined( 'FLUENTMAIL' ) || class_exists( '\FluentMail\App\Core\Application', false ) ) {
			return 'fluent_smtp';
		}
		if ( class_exists( 'PostmanOptions', false ) || class_exists( '\PostmanWpMail', false ) ) {
			return 'post_smtp';
		}
		return '';
	}

	/* ------------------------------------------------------------------ svg */

	/**
	 * An agency icon reduced to the allowed shapes, with fill="currentColor" on the root unless it sets one. '' when
	 * nothing usable is left. Never stored or used unless it passes this.
	 */
	public static function clean_svg( string $svg ): string {
		$svg = (string) preg_replace( [ '/<\?xml.*?\?>/s', '/<!DOCTYPE.*?>/is', '/<!--.*?-->/s' ], '', $svg );
		$svg = trim( $svg );
		if ( 0 !== stripos( $svg, '<svg' ) ) {
			return '';
		}
		$clean = trim( wp_kses( $svg, self::SVG_TAGS ) );
		if ( 0 !== stripos( $clean, '<svg' ) || false === stripos( $clean, '</svg>' ) ) {
			return '';
		}
		if ( ! preg_match( '/^<svg\b[^>]*\sfill=/i', $clean ) ) {
			$clean = (string) preg_replace( '/^<svg\b/i', '<svg fill="currentColor"', $clean, 1 );
		}
		return $clean;
	}
}
