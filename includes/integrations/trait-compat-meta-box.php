<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: Meta Box: the field allow-list behind {{meta.*}} tokens, settings-page fields and Card details ownership.
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Meta_Box {

	/* ------------------------------------------------------------------ Meta Box */

	/** [ kind, sub ] for mb_fields_for(): post type, 'term:{taxonomy}', 'setting:{option}', 'user', or an object. */
	private static function mb_target( $object_type ): array {
		if ( $object_type instanceof \WP_Post ) {
			return [ 'post', $object_type->post_type ];
		}
		if ( $object_type instanceof \WP_Term ) {
			return [ 'term', $object_type->taxonomy ];
		}
		$s = (string) $object_type;
		if ( 'user' === $s ) {
			return [ 'user', 'user' ];
		}
		if ( preg_match( '/^(term|setting|network_setting):(.+)$/', $s, $m ) ) {
			return [ $m[1], $m[2] ];
		}
		return [ 'post', $s ];
	}

	/** Forget what mb_fields_for() and friends remembered (the source changed, or a settings page was registered late). */
	public static function mb_reset(): void {
		self::$mb = [];
	}

	/**
	 * The fields of one Meta Box settings page, as Meta Box registered them (the MB Settings Page extension files them under
	 * the page's option name): id => { id, type, name, clone }. Layout-only fields, passwords and secret-looking ids are left
	 * out, like in mb_fields_for(); groups stay (Business Info can read a list from one). Empty without Meta Box or before it
	 * registers its fields (init priority 20).
	 *
	 * @return array<string,array{id:string,type:string,name:string,clone:bool}>
	 */
	public static function mb_setting_fields( string $option, bool $network = false ): array {
		$by = self::mb_registry( ( $network ? 'network_setting:' : 'setting:' ) . $option );
		$out = [];
		foreach ( (array) $by as $id => $f ) {
			$f = (array) $f;
			$type = (string) ( $f['type'] ?? 'text' );
			$id = (string) ( $f['id'] ?? $id );
			if ( '' === $id || 'password' === $type || in_array( $type, self::MB_LAYOUT_TYPES, true ) || preg_match( self::MB_SECRET_ID, $id ) ) {
				continue;
			}
			$out[ $id ] = [ 'id' => $id, 'type' => $type, 'name' => wp_strip_all_tags( (string) ( $f['name'] ?? $id ) ), 'clone' => ! empty( $f['clone'] ) ];
		}
		return $out;
	}

	/**
	 * The option names of the settings pages that {{meta.*}} tokens may read: the page Business Info reads from, plus any a
	 * site adds with the launch_builder_meta_token_pages filter (array $options, array $source: return option names). Other
	 * settings pages are never read by a token: anyone who can write a post could otherwise print any field of any page.
	 *
	 * @return string[]
	 */
	public static function mb_token_options(): array {
		if ( isset( self::$mb['token_options'] ) ) {
			return self::$mb['token_options'];
		}
		$src = (array) get_option( Company::OPT_SRC, [] );
		$out = isset( $src['option'] ) && is_string( $src['option'] ) && '' !== $src['option'] ? [ $src['option'] ] : [];
		foreach ( (array) apply_filters( 'launch_builder_meta_token_pages', [], $src ) as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$out[] = $name;
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( did_action( 'init' ) ) {
			self::$mb['token_options'] = $out;
		}
		return $out;
	}

	/**
	 * The fields {{meta.id}} may print on a page that is not an entry, from the settings pages in mb_token_options(): id =>
	 * { option, network, field }. The page Business Info reads from comes first, so its field wins when two pages use the
	 * same id. Only text-like types (MB_TOKEN_TYPES) and ids that do not read like a secret (MB_SECRET_ID).
	 *
	 * @return array<string,array{option:string,network:bool,field:array}>
	 */
	public static function mb_setting_index(): array {
		if ( ! self::mb_active() || ! did_action( 'init' ) ) {
			return [];
		}
		if ( isset( self::$mb['settings_index'] ) ) {
			return self::$mb['settings_index'];
		}
		$allowed = self::mb_token_options();
		$pages = array_values( array_filter( Company::meta_box_pages(), static function ( $p ) use ( $allowed ) { return in_array( $p['option'], $allowed, true ); } ) );
		usort( $pages, static function ( $a, $b ) use ( $allowed ) {
			return (int) ( ( $allowed[0] ?? '' ) === $b['option'] ) - (int) ( ( $allowed[0] ?? '' ) === $a['option'] );
		} );
		$out = [];
		foreach ( $pages as $p ) {
			foreach ( self::mb_setting_fields( $p['option'], $p['network'] ) as $id => $f ) {
				if ( ! isset( $out[ $id ] ) && in_array( $f['type'], self::MB_TOKEN_TYPES, true ) && ! $f['clone'] && ! empty( self::mb_fields_for( ( $p['network'] ? 'network_setting:' : 'setting:' ) . $p['option'] )[ $id ] ) ) { // The same allow-list as the other {{meta.*}} tokens.
					$out[ $id ] = [ 'option' => $p['option'], 'network' => $p['network'], 'field' => $f ];
				}
			}
		}
		if ( ! doing_action( 'init' ) ) {
			self::$mb['settings_index'] = $out;
		}
		return $out;
	}

	/**
	 * The Meta Box field allow-list for an object type (what {{meta.id}} may resolve): field id => { id, type, name,
	 * clone, multiple, storage, contexts[], shape }. $object_type: a post type ('lb_service'), 'term:{taxonomy}',
	 * 'setting:{option_name}', 'user', or a WP_Post/WP_Term. Only fields registered with Meta Box for exactly that type;
	 * layout-only fields, passwords and secret-looking ids are left out. Empty without Meta Box or before it registers its
	 * fields (init priority 20).
	 *
	 * contexts: where a value may be printed ('html', 'attr', 'text', 'url'); wysiwyg/oembed/map/osm are html only (pass
	 * through wp_kses_post). shape: scalar | list (cloneable or multiple: join with ', ').
	 */
	public static function mb_fields_for( $object_type ): array {
		$by = self::mb_registry( $object_type );
		if ( null === $by ) {
			return [];
		}
		[ $kind, $sub ] = self::mb_target( $object_type );
		$memo = 'allowed:' . $kind . ':' . $sub; // Every {{meta.*}} token asks, so keep the answer for the request.
		if ( isset( self::$mb[ $memo ] ) ) {
			return self::$mb[ $memo ];
		}
		$out = [];
		foreach ( $by as $id => $f ) {
			$f = (array) $f;
			$type = (string) ( $f['type'] ?? 'text' );
			$id = (string) ( $f['id'] ?? $id );
			if ( '' === $id || in_array( $type, [ 'password', 'heading', 'divider', 'custom_html', 'button', 'tab', 'hidden', 'sidebar', 'background' ], true ) || preg_match( '/(pass|secret|token|salt|_key$|api_?key|private|licen[sc]e_?(key|code)|credential)/i', $id ) ) {
				continue; // A license number is a fact to show; a license key is a secret.
			}
			$list = ! empty( $f['clone'] ) || ! empty( $f['multiple'] ) || in_array( $type, [ 'checkbox_list', 'image_advanced', 'file_advanced', 'image', 'file', 'image_upload', 'file_upload', 'video', 'autocomplete', 'key_value', 'group', 'fieldset_text' ], true );
			if ( in_array( $type, [ 'wysiwyg', 'oembed', 'map', 'osm' ], true ) ) {
				$contexts = [ 'html' ];
			} elseif ( in_array( $type, [ 'url', 'single_image', 'file_input' ], true ) && ! $list ) {
				$contexts = [ 'html', 'attr', 'text', 'url' ];
			} elseif ( in_array( $type, [ 'image_advanced', 'image', 'image_upload', 'file_advanced', 'file', 'file_upload', 'video' ], true ) ) {
				$contexts = [ 'html', 'attr', 'text', 'url' ]; // A picture or file list gives the address of its first file.
			} elseif ( in_array( $type, [ 'group', 'key_value', 'fieldset_text' ], true ) ) {
				$contexts = [];
			} else {
				$contexts = $list ? [ 'html', 'text' ] : [ 'html', 'attr', 'text' ];
			}
			if ( ! $contexts ) {
				continue;
			}
			$out[ $id ] = [
				'id'       => $id,
				'type'     => $type,
				'name'     => wp_strip_all_tags( (string) ( $f['name'] ?? $id ) ),
				'clone'    => ! empty( $f['clone'] ),
				'multiple' => ! empty( $f['multiple'] ),
				'storage'  => isset( $f['storage'] ) && is_object( $f['storage'] ) && false !== stripos( get_class( $f['storage'] ), 'table' ) ? 'custom_table' : 'meta',
				'contexts' => $contexts,
				'shape'    => $list ? 'list' : 'scalar',
			];
		}
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			self::$mb[ $memo ] = $out;
		}
		return $out;
	}

	/**
	 * Does Meta Box (or the launch_builder_field_owned_elsewhere filter) own field $id for this post type? One answer for
	 * the Card details box and for get-compat: this asks Entries::meta_box_owns(), the check the box itself uses. Any
	 * registered field counts, whatever its type.
	 */
	public static function mb_owns_field( string $post_type, string $id ): bool {
		return Entries::meta_box_owns( $id, $post_type );
	}

	/**
	 * Meta Box in one place, for get-compat and the admin Compatibility card: whether it is on, its version, the allowed
	 * token fields per type (id, type) and which Card details fields it owns.
	 *
	 * @return array{active:bool,version:string,fields:array<string,array>,owns_card_fields:array<string,bool>,settings_pages:array}
	 */
	public static function mb_status(): array {
		$out = [ 'active' => self::mb_active(), 'version' => defined( 'RWMB_VER' ) ? (string) RWMB_VER : '', 'fields' => [], 'owns_card_fields' => [], 'settings_pages' => [] ];
		if ( ! $out['active'] ) {
			return $out;
		}
		$src = (array) get_option( Company::OPT_SRC, [] );
		foreach ( Company::meta_box_pages() as $p ) { // Settings pages (Meta Box AIO, MB Settings Page): where Business Info can read from.
			$out['settings_pages'][] = [ 'title' => $p['title'], 'option' => $p['option'], 'fields' => count( self::mb_setting_fields( $p['option'], $p['network'] && is_multisite() ) ), 'business_info_source' => ( $src['option'] ?? '' ) === $p['option'] ];
		}
		foreach ( [ Entries::SERVICE, Entries::AREA, 'page' ] as $pt ) {
			$out['fields'][ $pt ] = array_values( array_map( static function ( $f ) { return [ 'id' => $f['id'], 'type' => $f['type'], 'shape' => $f['shape'], 'contexts' => $f['contexts'] ]; }, self::mb_fields_for( $pt ) ) );
		}
		$out['owns_card_fields'] = array_filter( [ '_lb_summary' => self::mb_owns_field( Entries::SERVICE, '_lb_summary' ), '_lb_icon' => self::mb_owns_field( Entries::SERVICE, '_lb_icon' ), '_lb_services' => self::mb_owns_field( Entries::AREA, '_lb_services' ) ] );
		return $out;
	}

	/** Raw Meta Box fields for an object type (field id => settings), or null when unavailable yet. */
	private static function mb_registry( $object_type ): ?array {
		if ( ! function_exists( 'rwmb_get_registry' ) || ! did_action( 'init' ) ) {
			return null;
		}
		[ $kind, $sub ] = self::mb_target( $object_type );
		$memo = $kind . ':' . $sub;
		if ( isset( self::$mb[ $memo ] ) ) {
			return self::$mb[ $memo ];
		}
		$reg = rwmb_get_registry( 'field' );
		if ( ! is_object( $reg ) || ! method_exists( $reg, 'get_by_object_type' ) ) {
			return null;
		}
		$by = (array) $reg->get_by_object_type( $kind );
		$fields = isset( $by[ $sub ] ) && is_array( $by[ $sub ] ) ? $by[ $sub ] : [];
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			self::$mb[ $memo ] = $fields; // Meta Box registers on init; memoise only after it.
		}
		return $fields;
	}
}
