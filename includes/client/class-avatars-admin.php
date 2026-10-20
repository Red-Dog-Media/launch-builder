<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Profile pictures screen (Launch Builder > Profile pictures, shown while the Local avatars module is on): every
 * ready-made picture in one grid, the picture people without a photo get (the current one is marked), the owner's own
 * pictures from the Media Library, and the Gravatar switch. One form, one save (admin-post), the same rules and the same
 * option as the Settings > Discussion section (Avatars::save_screen()). The look is the shared admin stylesheet.
 */
final class Avatars_Admin {

	const PAGE = 'launch-builder-avatars';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 11 );
		add_action( 'admin_post_lb_avatars_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function url(): string {
		return Admin::url( self::PAGE );
	}

	public static function menu(): void {
		add_submenu_page( Admin::SLUG, __( 'Profile pictures', 'launch-builder' ), __( 'Profile pictures', 'launch-builder' ), 'manage_options', self::PAGE, [ __CLASS__, 'page' ] );
	}

	private static function on_screen(): bool {
		return isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- reads which screen this is.
	}

	public static function assets(): void {
		if ( ! self::on_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'lb-avatars-admin', LB_URL . 'assets/admin/lb-avatars.js', [ 'lb-admin' ], (string) @filemtime( LB_DIR . 'assets/admin/lb-avatars.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'lb-avatars-admin', 'window.lbAvatars=' . wp_json_encode( [
			'drawn' => array_keys( Avatars::drawn_choices() ),
			'max'   => Avatars::LIBRARY_MAX,
			'i18n'  => [
				'title'  => __( 'Add pictures from the Media Library', 'launch-builder' ),
				'button' => __( 'Add to the list', 'launch-builder' ),
				'remove' => __( 'Remove from the list', 'launch-builder' ),
				'none'   => __( 'You have not added any pictures yet.', 'launch-builder' ),
				'added'  => __( 'Added. Save to keep them.', 'launch-builder' ),
				'gone'   => __( 'Removed. Save to keep the change.', 'launch-builder' ),
				'unsaved' => __( 'You have unsaved changes.', 'launch-builder' ),
				/* translators: %d: number of pictures the list holds */
				'full'   => sprintf( __( 'The list holds up to %d pictures. Remove one before adding another.', 'launch-builder' ), Avatars::LIBRARY_MAX ),
			],
		] ) . ';', 'before' );
	}

	/* ------------------------------------------------------------------ saving */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lb_avatars_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$in = [
			'never_gravatar' => ! empty( $_POST['never_gravatar'] ),
			'default'        => isset( $_POST['default'] ) ? sanitize_text_field( wp_unslash( $_POST['default'] ) ) : '',
			'anchor'         => isset( $_POST['anchor'] ) ? sanitize_key( wp_unslash( $_POST['anchor'] ) ) : 'top',
			'self_service'   => ! empty( $_POST['self_service'] ),
			'library'        => isset( $_POST['library'] ) && is_array( $_POST['library'] ) ? array_map( 'absint', wp_unslash( $_POST['library'] ) ) : [],
		];
		$r = Avatars::save_screen( $in );
		$args = [ 'page' => self::PAGE, 'saved' => '1' ];
		if ( $r['rejected'] ) {
			$args['skipped'] = (string) count( $r['rejected'] );
		}
		if ( ! empty( $r['dropped'] ) ) {
			$args['capped'] = (string) (int) $r['dropped'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ the screen */

	/** One selectable picture: a radio in a tile. */
	private static function tile( string $value, string $label, string $url, bool $on, string $extra = '' ): string {
		$img = '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="" width="64" height="64" loading="lazy">' : '<span class="lb-tile__blank" aria-hidden="true"></span>';
		return '<label class="lb-tile"' . $extra . '><input type="radio" name="default" value="' . esc_attr( $value ) . '"' . checked( $on, true, false ) . '><span class="lb-tile__img">' . $img . '</span><span class="lb-tile__name">' . esc_html( $label ) . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $extra is a fixed attribute string built here.
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Avatars::settings();
		$presets = Avatars::presets();
		$cur = is_string( $s['default'] ) ? $s['default'] : ( is_int( $s['default'] ) ? (string) $s['default'] : '' );

		echo '<div class="wrap lb-wrap lb-pics-screen">';
		echo Dashboard::header( __( 'Profile pictures', 'launch-builder' ), esc_html__( 'Choose the pictures people can pick from, and what shows for anyone without a photo.', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the call.

		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		if ( isset( $_GET['saved'] ) ) {
			$skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
			echo '<div class="lb-flash lb-flash--ok" role="status"><p>' . esc_html__( 'Saved. The pictures show on every page straight away.', 'launch-builder' ) . '</p></div>';
			$capped = isset( $_GET['capped'] ) ? absint( wp_unslash( $_GET['capped'] ) ) : 0;
			if ( $capped ) {
				/* translators: 1: number of pictures the list holds, 2: number of pictures left out */
				echo '<div class="lb-flash lb-flash--warn" role="status"><p>' . esc_html( sprintf( _n( 'The list holds up to %1$d pictures, so %2$d picture was left out. Remove one you no longer need and add it again.', 'The list holds up to %1$d pictures, so %2$d pictures were left out. Remove some you no longer need and add them again.', $capped, 'launch-builder' ), Avatars::LIBRARY_MAX, $capped ) ) . '</p></div>';
			}
			if ( $skipped ) {
				/* translators: %d: number of images */
				echo '<div class="lb-flash lb-flash--warn" role="status"><p>' . esc_html( sprintf( _n( '%d image could not be added. Use a JPEG, PNG, WebP, AVIF or GIF image that you may edit.', '%d images could not be added. Use JPEG, PNG, WebP, AVIF or GIF images that you may edit.', $skipped, 'launch-builder' ), $skipped ) ) . '</p></div>';
			}
		}
		// phpcs:enable
		if ( Avatars::sla_active() ) {
			echo '<div class="lb-flash lb-flash--warn"><p>' . esc_html__( 'The other avatars plugin is active, so it shows the photos for now. What you save here is kept and applies once it is deactivated.', 'launch-builder' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lb-avatars-form" class="lb-form"><input type="hidden" name="action" value="lb_avatars_save">';
		wp_nonce_field( 'lb_avatars_save' );

		// 1. What people without a photo get.
		$grid = '<div class="lb-tiles" role="radiogroup" aria-label="' . esc_attr__( 'Picture for people without a photo', 'launch-builder' ) . '" data-lb-tiles>';
		foreach ( $presets as $slug => $p ) {
			$grid .= self::tile( Avatars::PRESET_PREFIX . $slug, $p['label'], 'library' === $p['kind'] ? Avatars::preset_url( $slug, 96 ) : $p['url'], Avatars::PRESET_PREFIX . $slug === $cur, ' data-slug="' . esc_attr( $slug ) . '"' );
		}
		$grid .= '</div>';
		$drawn = '<h3 class="lb-subhead">' . esc_html__( 'Drawn by the site for each person', 'launch-builder' ) . '</h3><div class="lb-tiles" role="radiogroup" aria-label="' . esc_attr__( 'Drawn pictures', 'launch-builder' ) . '">';
		foreach ( Avatars::drawn_choices() as $key => $d ) {
			$drawn .= self::tile( $key, $d['label'], $d['url'], $key === $cur );
		}
		if ( is_int( $s['default'] ) ) {
			/* translators: %d: Media Library image number */
			$drawn .= self::tile( (string) $s['default'], sprintf( __( 'Chosen image (#%d)', 'launch-builder' ), $s['default'] ), (string) wp_get_attachment_image_url( (int) $s['default'], 'thumbnail' ), true );
		}
		$drawn .= '</div>';
		$help = esc_html__( 'This shows for everyone who has not added a photo of their own: commenters, new users and anyone who has not picked a picture. People can still pick another one on their own profile.', 'launch-builder' );
		if ( $s['gravatar'] ) {
			$help .= ' <strong>' . esc_html__( 'Gravatar is still on, so a ready-made picture only shows for people who have no Gravatar, and only on a secure (https) site. Turn on "Never load pictures from Gravatar" below to show it for everyone.', 'launch-builder' ) . '</strong>';
		}
		echo self::card( 'lb-pics-default', __( 'Picture for people without a photo', 'launch-builder' ), $help, $grid . $drawn ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

		// 2. The owner's own pictures.
		$own = '<div class="lb-tiles lb-tiles--own" data-lb-own>';
		foreach ( $presets as $slug => $p ) {
			if ( 'library' !== $p['kind'] ) {
				continue;
			}
			$own .= self::own_tile( (int) $p['att'], $p['label'], Avatars::preset_url( $slug, 96 ) );
		}
		$own .= '</div>';
		$own .= '<p class="lb-help" data-lb-own-empty' . ( count( array_filter( $presets, static function ( $p ) { return 'library' === $p['kind']; } ) ) ? ' hidden' : '' ) . '>' . esc_html__( 'You have not added any pictures yet.', 'launch-builder' ) . '</p>';
		$own .= '<p class="lb-own__actions"><button type="button" class="lb-btn" data-lb-add-pictures>' . Dashboard::icon( 'plus' ) . esc_html__( 'Add pictures from the Media Library', 'launch-builder' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		$own .= '<p class="lb-help">' . esc_html__( 'Team photos, brand marks and icons all work. Use JPEG, PNG, WebP, AVIF or GIF images: WordPress does not take SVG files in the Media Library. Pictures you add show in the grid above and on every profile. Removing one only takes it off the list; the image stays in the Media Library.', 'launch-builder' ) . '</p>';
		echo self::card( 'lb-pics-own', __( 'Your own pictures', 'launch-builder' ), esc_html__( 'Add pictures from the Media Library so people can choose them too.', 'launch-builder' ), $own ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

		// 3. Gravatar, crop, who may change their own.
		$set = '<div class="lb-fields">';
		$set .= '<div class="lb-field lb-field--wide lb-field--check"><label class="lb-check"><input type="checkbox" name="never_gravatar" value="1"' . checked( ! $s['gravatar'], true, false ) . '> <span>' . esc_html__( 'Never load pictures from Gravatar', 'launch-builder' ) . '</span></label><p class="lb-help">' . esc_html__( 'New sites start with this ticked. Neither visitors\' browsers nor this server contact Gravatar, and everyone without a photo gets the picture chosen above. Untick it and people who have a Gravatar show it again.', 'launch-builder' ) . '</p></div>';
		$set .= '<div class="lb-field"><label for="lb-anchor">' . esc_html__( 'Square crop keeps', 'launch-builder' ) . '</label><select id="lb-anchor" name="anchor"><option value="top"' . selected( $s['anchor'], 'top', false ) . '>' . esc_html__( 'the top (faces)', 'launch-builder' ) . '</option><option value="center"' . selected( $s['anchor'], 'center', false ) . '>' . esc_html__( 'the centre', 'launch-builder' ) . '</option></select><p class="lb-help">' . esc_html__( 'How a photo that is not square is cut.', 'launch-builder' ) . '</p></div>';
		$set .= '<div class="lb-field lb-field--check"><label class="lb-check"><input type="checkbox" name="self_service" value="1"' . checked( $s['self_service'], true, false ) . '> <span>' . esc_html__( 'People may choose their own picture', 'launch-builder' ) . '</span></label><p class="lb-help">' . esc_html__( 'Anyone can pick from the list. People who can upload files can also use a photo of their own.', 'launch-builder' ) . '</p></div>';
		$set .= '</div>';
		echo self::card( 'lb-pics-more', __( 'Gravatar and photos', 'launch-builder' ), '', $set ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.

		echo '<div class="lb-savebar"><button type="submit" class="lb-btn lb-btn--primary" id="lb-save">' . Dashboard::icon( 'check' ) . esc_html__( 'Save changes', 'launch-builder' ) . '</button><span class="lb-savebar__note" id="lb-save-note" role="status" aria-live="polite">' . esc_html__( 'Changes show on every page straight away.', 'launch-builder' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
		echo '</form></div>';
	}

	/** A picture the owner added: its image, name, a remove button and the hidden field that keeps it in the list. */
	private static function own_tile( int $att, string $label, string $url ): string {
		return '<div class="lb-tile lb-tile--own" data-att="' . esc_attr( (string) $att ) . '"><span class="lb-tile__img"><img src="' . esc_url( $url ) . '" alt="" width="64" height="64" loading="lazy"></span><span class="lb-tile__name">' . esc_html( $label ) . '</span><button type="button" class="lb-tile__x" data-lb-remove-picture aria-label="' . esc_attr( sprintf( /* translators: %s: name of the picture */ __( 'Remove %s from the list', 'launch-builder' ), $label ) ) . '">' . Dashboard::icon( 'x' ) . '</button><input type="hidden" name="library[]" value="' . esc_attr( (string) $att ) . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon markup.
	}

	private static function card( string $id, string $title, string $help, string $body ): string {
		return '<section class="lb-panel lb-card--form" id="' . esc_attr( $id ) . '"><div class="lb-panel__head"><h2>' . esc_html( $title ) . '</h2></div>' . ( '' !== $help ? '<p class="lb-help lb-help--lead">' . $help . '</p>' : '' ) . $body . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $help and $body are built from escaped parts.
	}
}
