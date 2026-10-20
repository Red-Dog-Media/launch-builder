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

	/** Query arg of the Launch Builder view on the Kadence Elements list. */
	const VIEW = 'lb_managed';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		// The Elements Launch Builder placed are named for the piece ("Service hero"), so the Elements list says whose they are.
		add_filter( 'views_edit-kadence_element', [ __CLASS__, 'element_views' ], 20 );
		add_filter( 'manage_kadence_element_posts_columns', [ __CLASS__, 'element_columns' ], 20 );
		add_action( 'manage_kadence_element_posts_custom_column', [ __CLASS__, 'element_column' ], 10, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'element_filter' ], 20 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'keep_view' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 25, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'element_note' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_note' ] );
	}

	/* ------------------------------------------------------------------ the Kadence Elements screens */

	/** The design piece an Element placed by the Design hub is ('' for any other Element). */
	private static function piece_of( int $id ): string {
		$piece = (string) get_post_meta( $id, '_lb_piece', true );
		return isset( Design_Hub::PIECES[ $piece ] ) && 'element' === Design_Hub::PIECES[ $piece ]['kind'] && in_array( $id, Design_Hub::element_ids(), true ) ? $piece : '';
	}

	private static function view_on(): bool {
		return ! empty( $_GET[ self::VIEW ] ); // phpcs:ignore WordPress.Security.NonceVerification -- a read-only list filter.
	}

	/** "Launch Builder (N)" next to All / Published: only the Elements the Design hub placed. */
	public static function element_views( $views ) {
		$views = (array) $views;
		$n = count( Design_Hub::element_ids() );
		if ( ! $n ) {
			return $views;
		}
		$on = self::view_on();
		if ( $on ) {
			foreach ( $views as $k => $v ) {
				$views[ $k ] = str_replace( [ ' class="current"', ' aria-current="page"' ], '', (string) $v );
			}
		}
		$url = add_query_arg( [ 'post_type' => 'kadence_element', self::VIEW => 1 ], admin_url( 'edit.php' ) );
		$views['lb_managed'] = '<a href="' . esc_url( $url ) . '"' . ( $on ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Launch Builder', 'launch-builder' ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		return $views;
	}

	/** The list form carries the view along, so a search or a bulk action stays inside it. */
	public static function keep_view( $post_type = '' ): void {
		if ( self::view_on() && 'kadence_element' === $post_type ) {
			echo '<input type="hidden" name="' . esc_attr( self::VIEW ) . '" value="1">';
		}
	}

	/** The Launch Builder view: only the Elements the Design hub placed, in the order of the Design hub. */
	public static function element_filter( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! self::view_on() || 'kadence_element' !== $query->get( 'post_type' ) ) {
			return;
		}
		$ids = Design_Hub::element_ids();
		$query->set( 'post__in', $ids ? $ids : [ 0 ] );
		if ( empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a read-only list order.
			$query->set( 'orderby', 'post__in' );
		}
	}

	/** "Shows on" straight after the title. */
	public static function element_columns( $cols ): array {
		$out = [];
		$done = false;
		foreach ( (array) $cols as $k => $label ) {
			$out[ $k ] = $label;
			if ( 'title' === $k ) {
				$out['lb_shows_on'] = esc_html__( 'Shows on', 'launch-builder' );
				$done = true;
			}
		}
		if ( ! $done ) {
			$out['lb_shows_on'] = esc_html__( 'Shows on', 'launch-builder' );
		}
		return $out;
	}

	/** The cell: the page types in plain words, and which design it is. */
	public static function element_column( $column, $post_id ): void {
		if ( 'lb_shows_on' !== $column ) {
			return;
		}
		$piece = self::piece_of( (int) $post_id );
		if ( '' === $piece ) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not placed by Launch Builder', 'launch-builder' ) . '</span>';
			return;
		}
		$look = (string) get_post_meta( (int) $post_id, '_lb_look', true );
		$look = Design_Hub::LOOKS[ $look ] ?? '';
		/* translators: %s: name of a look, such as "Home services" */
		$what = '' !== $look ? sprintf( __( 'Design hub · %s look', 'launch-builder' ), $look ) : __( 'Design hub', 'launch-builder' );
		echo '<strong>' . esc_html( Design_Hub::shows_on( $piece ) ) . '</strong><br><span class="description">' . esc_html( $what ) . '</span>';
	}

	/** A link from the Element's row to its place in the Design hub (where its design is swapped). */
	public static function row_actions( $actions, $post ) {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || 'kadence_element' !== $post->post_type || ! current_user_can( 'edit_theme_options' ) ) {
			return $actions;
		}
		$piece = self::piece_of( (int) $post->ID );
		if ( '' === $piece ) {
			return $actions;
		}
		$link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '#piece-' . $piece ) ) . '">' . esc_html__( 'Change design', 'launch-builder' ) . '</a>';
		$out = [];
		foreach ( $actions as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'edit' === $k ) {
				$out['lb_design'] = $link;
			}
		}
		return isset( $out['lb_design'] ) ? $out : [ 'lb_design' => $link ] + $actions;
	}

	/** The words of the note on an Element's edit screen, with where it leads ('' = no note for this post). @return array{text:string,url:string,label:string}|null */
	public static function note( int $id ): ?array {
		$piece = self::piece_of( $id );
		if ( '' === $piece || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		$look = Design_Hub::LOOKS[ (string) get_post_meta( $id, '_lb_look', true ) ] ?? '';
		/* translators: 1: design piece such as "Service hero", 2: look such as "Home services" (may be empty), 3: page types such as "Service pages" */
		$text = sprintf( __( 'This is the %1$s%2$s that Launch Builder placed. It shows on: %3$s. Edit it here like any Kadence Element: Launch Builder keeps your changes when you pick designs again and asks before replacing them. To leave one page out, add it under Hide On in this Element\'s settings.', 'launch-builder' ), Design_Hub::PIECES[ $piece ]['label'], '' !== $look ? ' (' . $look . ')' : '', Design_Hub::shows_on( $piece ) );
		return [ 'text' => $text, 'url' => current_user_can( 'edit_theme_options' ) ? admin_url( 'admin.php?page=' . self::PAGE . '#piece-' . $piece ) : '', 'label' => __( 'Change design in the Design hub', 'launch-builder' ) ];
	}

	private static function editing_id(): int {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'kadence_element' !== $screen->post_type ) {
			return 0;
		}
		return isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- reading which post is open.
	}

	/** The note at the top of the classic edit screen. */
	public static function element_note(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id = self::editing_id();
		$n = $id && ! ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ? self::note( $id ) : null;
		if ( ! $n ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( $n['text'] ) . ( '' !== $n['url'] ? ' <a href="' . esc_url( $n['url'] ) . '">' . esc_html( $n['label'] ) . '</a>' : '' ) . '</p></div>';
	}

	/** In the block editor the same note is an editor notice (the editor covers page-level notices). */
	public static function editor_note(): void {
		$id = self::editing_id();
		$n = $id ? self::note( $id ) : null;
		if ( ! $n ) {
			return;
		}
		$actions = '' !== $n['url'] ? [ [ 'label' => $n['label'], 'url' => $n['url'] ] ] : [];
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; // Safe inside a script element whatever the words hold.
		wp_register_script( 'lb-element-note', false, [ 'wp-data', 'wp-dom-ready', 'wp-notices' ], LB_VERSION, true );
		wp_enqueue_script( 'lb-element-note' );
		wp_add_inline_script( 'lb-element-note', 'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("info",' . wp_json_encode( $n['text'], $flags ) . ',{id:"lb-element-note",isDismissible:true,actions:' . wp_json_encode( $actions, $flags ) . '.map(function(a){return{label:a.label,url:a.url,variant:"primary"};})});});' );
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
