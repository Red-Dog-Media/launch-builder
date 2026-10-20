<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * The Design hub screen: one row per design piece, the three looks side by side as live previews, the one in use
 * ticked. "Use" places that piece (Design_Hub::set); the buttons on top place a whole look with its colours. A piece
 * changed in Kadence since it was placed is marked, and replacing it needs a confirmation (force).
 */
final class Design_Hub_Admin {

	/** Page slug (the old Pattern preview screen's, so old links still land here). */
	const PAGE = 'launch-builder-patterns';

	const ACTION = 'lb_design';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/** A preview of one pattern in a frame (the Admin preview route renders it as a page of its role). */
	private static function frame( string $slug, string $title ): string {
		$url = add_query_arg( [ 'lb_preview' => $slug ], home_url( '/' ) );
		return '<div class="lb-dh-frame"><iframe src="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '" loading="lazy" tabindex="-1" scrolling="no"></iframe></div>';
	}

	private static function form( array $fields, string $label, string $class = 'button', string $confirm = '' ): string {
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-dh-form"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">' . wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $out . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$info = Design_Hub::get_tool();
		echo '<div class="wrap lb-wrap lb-dh">';
		echo Dashboard::header( __( 'Design hub', 'launch-builder' ), esc_html__( 'Every design piece of the site in three looks. Choosing one writes a Kadence header, Element or sidebar widget, so the site keeps it without Launch Builder. Edit it later in Kadence like any other.', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the call.
		$flash = get_transient( 'lb_design_flash_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'lb_design_flash_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( 'error' === $flash[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $flash[1] ) . '</p></div>';
		}
		if ( ! $info['elements_module'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Kadence Pro (Theme Kit Pro) Elements module is off: heroes, the breadcrumb bar, lists, the call to action and the footer cannot be placed until it is on (Kadence > Pro Add-ons > Elements).', 'launch-builder' ) . '</p></div>';
		}
		if ( ! $info['query_loop'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Kadence Blocks Pro is not active: the lists (sub-services, service areas, area services) need its Query Loop.', 'launch-builder' ) . '</p></div>';
		}

		// Whole looks.
		echo '<section class="lb-panel"><div class="lb-panel__head"><h2>' . esc_html__( 'Use one look everywhere', 'launch-builder' ) . '</h2><span class="lb-note" style="margin:0">' . esc_html__( 'Places every piece in that look and sets its colours and buttons. Pieces changed in Kadence are kept.', 'launch-builder' ) . '</span></div><div class="lb-dh-looks">';
		foreach ( Design_Hub::LOOKS as $look => $label ) {
			$sw = '';
			foreach ( [ 'palette1', 'palette2', 'palette3', 'palette7', 'palette9' ] as $p ) {
				$sw .= '<span style="background:' . esc_attr( Design_Hub::LOOK_STYLE[ $look ]['palette'][ $p ] ) . '"></span>';
			}
			$on = $info['look'] === $look;
			echo '<div class="lb-dh-look' . ( $on ? ' is-on' : '' ) . '"><div class="lb-dh-swatches" aria-hidden="true">' . $sw . '</div><b>' . esc_html( $label ) . '</b>'; // phpcs:ignore WordPress.Security.EscapeOutput -- swatches built from escaped parts.
			echo $on ? '<span class="lb-chip">' . esc_html__( 'In use', 'launch-builder' ) . '</span>' : self::form( [ 'look' => $look ], __( 'Use this look', 'launch-builder' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
		}
		echo '</div></section>';

		// One row per piece.
		foreach ( $info['pieces'] as $piece => $p ) {
			$shows = implode( ', ', array_map( [ Roles::class, 'label' ], array_slice( $p['shows_on'], 0, 6 ) ) ) . ( count( $p['shows_on'] ) > 6 ? '…' : '' );
			echo '<section class="lb-panel lb-dh-piece" id="piece-' . esc_attr( $piece ) . '"><div class="lb-panel__head"><h2>' . esc_html( $p['label'] ) . '</h2><span class="lb-note" style="margin:0">' . esc_html( $p['placed_as'] . ' · ' . $shows ) . '</span>';
			if ( $p['edited'] ) {
				echo '<span class="lb-chip lb-chip--warn">' . esc_html__( 'Changed in Kadence', 'launch-builder' ) . '</span>';
			}
			if ( $p['id'] ) {
				echo ' <a class="lb-link" href="' . esc_url( (string) get_edit_post_link( (int) $p['id'], 'raw' ) ) . '">' . esc_html__( 'Edit in Kadence', 'launch-builder' ) . '</a>';
			}
			echo '</div>';
			if ( null !== $p['placement'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-dh-placement"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="piece" value="' . esc_attr( $piece ) . '">' . wp_nonce_field( self::ACTION, '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<label>' . esc_html__( 'Where in the page', 'launch-builder' ) . ' <select name="placement">';
				foreach ( [ 'top' => __( 'Above the text', 'launch-builder' ), 'middle' => __( 'After the second paragraph', 'launch-builder' ), 'bottom' => __( 'Below the text', 'launch-builder' ) ] as $v => $l ) {
					echo '<option value="' . esc_attr( $v ) . '"' . selected( $p['placement'], $v, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select></label> <button type="submit" class="button"' . ( $p['look'] ? '' : ' disabled' ) . '>' . esc_html__( 'Move', 'launch-builder' ) . '</button></form>';
			}
			echo '<div class="lb-dh-row">';
			foreach ( $p['looks'] as $look => $l ) {
				echo '<figure class="lb-dh-card' . ( $p['look'] === $look ? ' is-on' : '' ) . '">';
				if ( null === $l ) {
					echo '<div class="lb-dh-frame lb-dh-frame--none">' . esc_html__( 'Not available in this look', 'launch-builder' ) . '</div>';
				} else {
					echo self::frame( $l['pattern'], $p['label'] . ' · ' . Design_Hub::LOOKS[ $look ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				}
				echo '<figcaption><b>' . esc_html( Design_Hub::LOOKS[ $look ] ) . '</b>';
				if ( $p['look'] === $look ) {
					echo '<span class="lb-chip">' . esc_html__( 'In use', 'launch-builder' ) . '</span>';
					if ( $p['edited'] ) {
						echo self::form( [ 'piece' => $piece, 'piece_look' => $look, 'force' => 1 ], __( 'Restore', 'launch-builder' ), 'button', __( 'Replace the version changed in Kadence with this design?', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				} elseif ( null !== $l ) {
					$confirm = $p['edited'] ? __( 'This piece was changed in Kadence. Replace it with this design?', 'launch-builder' ) : '';
					echo self::form( [ 'piece' => $piece, 'piece_look' => $look, 'force' => $p['edited'] ? 1 : 0 ], __( 'Use', 'launch-builder' ), 'button button-primary', $confirm ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</figcaption></figure>';
			}
			echo '</div>';
			if ( $p['look'] ) {
				echo self::form( [ 'piece' => $piece, 'piece_look' => 'none' ], __( 'Take it off the site', 'launch-builder' ), 'button-link lb-dh-remove', __( 'Take this piece off the site? (An Element goes to the Trash.)', 'launch-builder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/** admin-post.php?action=lb_design: a whole look, one piece in a look, or a list's placement. */
	public static function handle(): void {
		if ( ! current_user_can( 'edit_theme_options' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'Not allowed.', 'launch-builder' ), '', [ 'response' => 403 ] );
		}
		$look = sanitize_key( (string) wp_unslash( $_POST['look'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$piece = sanitize_key( (string) wp_unslash( $_POST['piece'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$input = [ 'force' => ! empty( $_POST['force'] ) ];
		if ( '' !== $look ) {
			$input['look'] = $look;
		} elseif ( '' !== $piece ) {
			$c = [];
			if ( isset( $_POST['piece_look'] ) ) {
				$c['look'] = sanitize_key( (string) wp_unslash( $_POST['piece_look'] ) );
			}
			if ( isset( $_POST['placement'] ) ) {
				$c['placement'] = sanitize_key( (string) wp_unslash( $_POST['placement'] ) );
			}
			$input['pieces'] = [ $piece => $c ];
			$input['layouts'] = false;
		}
		$r = Design_Hub::set( $input );
		if ( is_wp_error( $r ) ) {
			$flash = [ 'error', $r->get_error_message() ];
		} else {
			$done = [];
			$bad = [];
			foreach ( $r['pieces'] as $k => $row ) {
				$label = Design_Hub::PIECES[ $k ]['label'];
				if ( in_array( $row['action'], [ 'create', 'update', 'remove' ], true ) ) {
					$done[] = $label;
				} elseif ( in_array( $row['action'], [ 'failed', 'skip', 'keep' ], true ) ) {
					$bad[] = $label . ': ' . $row['note'];
				}
			}
			/* translators: %s: list of design pieces */
			$msg = $done ? sprintf( __( 'Placed: %s.', 'launch-builder' ), implode( ', ', $done ) ) : __( 'Nothing changed.', 'launch-builder' );
			$flash = [ $bad && ! $done ? 'error' : 'success', trim( $msg . ' ' . implode( ' ', $bad ) ) ];
		}
		set_transient( 'lb_design_flash_' . get_current_user_id(), $flash, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . ( '' !== $piece ? '#piece-' . $piece : '' ) ) );
		exit;
	}
}
