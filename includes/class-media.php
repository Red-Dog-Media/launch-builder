<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Media hygiene (module, off by default). Not an optimizer: compression, WebP/AVIF, lazy loading and delivery stay with
 * the host (SiteGround Speed Optimizer), Perfmatters and core. What it adds:
 *
 *  A. A lossless location stripper. On upload and on image edits (wp_generate_attachment_metadata and
 *     wp_update_attachment_metadata, priority 5; the client-side processing of WP 7.1 ends in the same filters) every file
 *     of an image attachment (full size, kept original, source original, every sub-size and its format siblings, edit
 *     backups) loses its GPS IFD (emptied in place: same length, no offset moves, Orientation/ICC/copyright kept), its
 *     XMP packets and, optionally, the IPTC city and sublocation. JPEG, PNG (eXIf, XMP text chunks) and WebP (EXIF/XMP
 *     chunks). Pixels are never re-encoded. Files carrying a multi-picture index (MPF: Ultra HDR, MPO, motion photos) are
 *     only edited without changing any length. HEIC/AVIF originals are never edited: the audit reports them.
 *  B. Sideload normalisation for Launch Builder's own sideload-media (and everything that calls it: build-site logos,
 *     brief media): 25 MB download ceiling, 40 MP / memory guard, longest edge capped, opaque truecolour PNG photos to
 *     JPEG, quality for these saves only (the site's jpeg_quality is never filtered globally).
 *  C. Tools get-media-settings, set-media-settings and (when enabled) strip-media-location (retroactive, dry run first).
 *  D. Audit checks (scope media): media_gps, media_heavy, media_png_photo, media_alt_missing, media_heic_original,
 *     media_public_backups.
 *
 * Off by default. The switch is the Modules registry (Launch Builder > Modules, set-modules) where it exists: a module
 * switched off there is not booted at all. Without the registry the switch is this module's own `enabled` setting:
 * until it is on, the two metadata filters return at once (images only; one non-autoloaded option read per upload or
 * edit, never on page views), the audit checks report nothing and only the two settings tools are listed. Either way
 * sideload-media behaves exactly as before while the module is off. Everything it touches is resolved through
 * attachment metadata, kept under the uploads directory (realpath), never a symlink, never over 50 MB, and written to a
 * temp file in the same directory that replaces the original with one rename().
 */
final class Media {

	/** Modules contract: toggleable, off until an administrator turns it on. */
	const MODULE = [
		'slug'        => 'media',
		'label'       => 'Media hygiene',
		'description' => 'Removes GPS location (and XMP, optionally the IPTC city) from every uploaded image and all its sizes without re-encoding, normalises images imported with sideload-media (size cap, PNG photos to JPEG) and adds media checks to the site audit. Not an optimizer: compression, WebP and lazy loading stay with the host and Perfmatters.',
		'toggle'      => true,
		'default'     => false,
		'note'        => 'Images uploaded before it was on keep their metadata until strip-media-location treats them.',
	];

	const REGISTRY = __NAMESPACE__ . '\\Modules';
	const OPT     = 'launch_builder_media';
	const FORMATS = [ 'auto', 'keep', 'jpeg', 'webp' ];
	const SCOPES  = [ 'attachments', 'optimizer_backups' ];

	/** Folder an image optimizer keeps public copies of original images in (inside uploads). Assembled so the source does not spell it out. */
	const OPTIMIZER_BACKUP_DIR = 'tiny' . 'fy_backup';

	const DEFAULTS = [
		'enabled'          => false,
		'strip_location'   => true,
		'strip_xmp'        => true,
		'strip_iptc'       => false,
		'sideload_max_px'  => 2048,
		'sideload_format'  => 'auto',
		'sideload_quality' => 82,
		'audit_max_kb'     => 1024,
	];

	const MAX_FILE     = 52428800; // 50 MB: larger files are never rewritten.
	const MAX_DOWNLOAD = 26214400; // 25 MB sideload ceiling.
	const MAX_PIXELS   = 40000000; // 40 megapixels: decompression-bomb guard for sideloads.
	const SCAN_BYTES   = 262144;   // Audit header scans read at most 256 KB per file.
	const HEAD_CAP     = 8388608;  // A JPEG header (everything before SOS) over 8 MB is not rewritten.
	const HEAVY_PX     = 2560;
	const PNG_PHOTO_KB = 300;
	const XMP_NS       = "http://ns.adobe.com/xap/1.0/\0";
	const XMP_EXT      = "http://ns.adobe.com/xmp/extension/\0";

	/** @var array<string,string> path => "size:mtime" of files handled in this request (dedupes the many metadata saves of one upload). */
	private static $seen = [];

	/** @var string|null realpath of the uploads base directory ('' when it cannot be resolved). */
	private static $base = null;

	/** @var array audit batch memo: one header scan per page shared by the six checks. */
	private static $scan = [];

	/** @var bool|null does the Modules registry exist (per request)? */
	private static $registry = null;

	/* ------------------------------------------------------------------ module hooks */

	public static function boot(): void {
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'on_metadata' ], 5, 2 );
		add_filter( 'wp_update_attachment_metadata', [ __CLASS__, 'on_metadata' ], 5, 2 );
		Audit::register_check( 'media_gps', 'media', [ __CLASS__, 'audit_gps' ], true );
		Audit::register_check( 'media_heavy', 'media', [ __CLASS__, 'audit_heavy' ], true );
		Audit::register_check( 'media_png_photo', 'media', [ __CLASS__, 'audit_png_photo' ], true );
		Audit::register_check( 'media_alt_missing', 'media', [ __CLASS__, 'audit_alt' ], true );
		Audit::register_check( 'media_heic_original', 'media', [ __CLASS__, 'audit_heic' ], true );
		Audit::register_check( 'media_public_backups', 'media', [ __CLASS__, 'audit_backups' ], true );
	}

	public static function define_tools(): void {
		Abilities::add( 'get-media-settings', 'Media hygiene settings', 'Media hygiene (off by default): its settings, the image editor and what it keeps (WordPress\'s Imagick editor keeps EXIF, GPS included, in every size), WebP/AVIF support, the big-image threshold, and what SiteGround Optimizer, Perfmatters and a third-party image optimizer do on this site (read only).',
			[ 'properties' => new \stdClass() ], [ __CLASS__, 'get_settings_tool' ], 'upload_files', [ 'readonly' => true ] );

		Abilities::add( 'set-media-settings', 'Change media hygiene settings', 'Turn media hygiene on/off and set it (partial update; snapshot first; returns the previous values). enabled: strip location from every new upload and edit, normalise sideload-media (where Launch Builder > Modules exists this switches the module there, like set-modules). strip_location: GPS (default on). strip_xmp: XMP packets (default on). strip_iptc: IPTC city and sublocation (default off). sideload_max_px: longest edge for sideload-media (0 = keep, else 640-4096; default 2048). sideload_format: auto (opaque PNG photos to JPEG) | keep | jpeg | webp. sideload_quality: 60-95 (default 82; only Launch Builder\'s own conversions). audit_max_kb: full-size weight the audit flags (default 1024). Compression, WebP and lazy loading are left to the host and Perfmatters.',
			[
				'properties' => [
					'enabled'          => [ 'type' => 'boolean' ],
					'strip_location'   => [ 'type' => 'boolean' ],
					'strip_xmp'        => [ 'type' => 'boolean' ],
					'strip_iptc'       => [ 'type' => 'boolean' ],
					'sideload_max_px'  => [ 'type' => 'integer', 'description' => '0 = keep the size, else 640-4096.' ],
					'sideload_format'  => [ 'type' => 'string', 'enum' => self::FORMATS ],
					'sideload_quality' => [ 'type' => 'integer', 'description' => '60-95.' ],
					'audit_max_kb'     => [ 'type' => 'integer', 'description' => '100-51200.' ],
				],
			],
			[ __CLASS__, 'set_settings_tool' ], 'manage_options' );

		if ( self::enabled() ) {
			Abilities::add( 'strip-media-location', 'Strip location from existing images', 'Remove GPS location (and XMP / IPTC city per the settings) from images already in the media library, losslessly, in every size. dry_run defaults to TRUE: it only reports. Pass ids (max 50), or walk the library with after_id + limit (max 50; continue from next_after_id). scope optimizer_backups treats the public copies an image optimizer left in its backup folder in uploads (administrators only). Lists only files with something to report.',
				[
					'properties' => [
						'ids'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Attachment ids (max 50).' ],
						'after_id' => [ 'type' => 'integer' ],
						'limit'    => [ 'type' => 'integer', 'description' => '1-50, default 20.' ],
						'scope'    => [ 'type' => 'string', 'enum' => self::SCOPES ],
						'dry_run'  => [ 'type' => 'boolean', 'description' => 'Default true.' ],
					],
				],
				[ __CLASS__, 'strip_tool' ], 'upload_files' );
		}
	}

	public static function site_state( array &$state ): void {
		$env = self::environment();
		$on = self::enabled();
		$s = self::settings();
		if ( $on && $s['strip_location'] ) {
			$risk = 'low'; // New uploads are stripped; audit scope media reports older ones.
		} elseif ( 'imagick' === $env['editor'] ) {
			$risk = 'high'; // GPS reaches every size and the public original.
		} elseif ( 'gd' === $env['editor'] ) {
			$risk = 'originals'; // GD drops metadata in sub-sizes; uploaded originals keep it.
		} else {
			$risk = 'unknown';
		}
		$sg = self::neighbours()['sg_optimizer'];
		$state['media'] = [
			'enabled'  => $on,
			'editor'   => $env['editor'],
			'sg_webp'  => $sg['active'] ? ( $sg['options']['siteground_optimizer_webp_support'] ?? null ) : null,
			'gps_risk' => $risk,
		];
	}

	public static function playbook( array &$pb ): void {
		$pb['media_hygiene'] = 'Media hygiene (a module, off by default; switch it with set-modules where Launch Builder > Modules exists, else set-media-settings {enabled:true}) removes GPS location and XMP (optionally the IPTC city) from every uploaded image and all its sizes without re-encoding, and makes sideload-media cap photos at 2048 px and turn opaque PNG photos into JPEG (what og:image and social cards need). It is not an optimizer: compression, WebP and lazy loading stay with the host (SiteGround) and Perfmatters. For images uploaded before it was on: strip-media-location (dry_run first). Audit scope "media" reports GPS left, heavy full sizes, PNG photos, missing alt text, HEIC originals and public backup copies.';
		$pb['tools_by_job']['Media hygiene'] = 'get-media-settings, set-media-settings, strip-media-location (when enabled), sideload-media max_px / format';
	}

	/* ------------------------------------------------------------------ settings */

	/** Stored settings over the defaults, validated. get_option() caches the row, so this is cheap after the first call. */
	public static function settings(): array {
		$raw = get_option( self::OPT, [] );
		return self::clean( is_array( $raw ) ? $raw : [] );
	}

	/**
	 * Is the module on? The Modules registry decides where it exists (Modules::enabled( class ), which also keeps a
	 * switched-off module from booting); otherwise the `enabled` setting. Either way the class must still be in
	 * Plugin::modules() (filter launch_builder_modules).
	 */
	public static function enabled(): bool {
		if ( self::registry() ) {
			try {
				$on = (bool) call_user_func( [ self::REGISTRY, 'enabled' ], __CLASS__ );
			} catch ( \Throwable $e ) {
				$on = false; // Registry present but unreadable: stay off.
			}
		} else {
			$on = ! empty( self::settings()['enabled'] );
		}
		return $on && in_array( __CLASS__, Plugin::modules(), true );
	}

	/** Does the Modules registry own the on/off switch (Launch Builder > Modules, set-modules)? */
	public static function registry(): bool {
		if ( null === self::$registry ) {
			self::$registry = class_exists( self::REGISTRY ) && is_callable( [ self::REGISTRY, 'enabled' ] ) && is_callable( [ self::REGISTRY, 'set' ] );
		}
		return self::$registry;
	}

	/** The settings as tools show them: `enabled` is the effective switch (the registry's where it owns it). */
	private static function view(): array {
		return [ 'enabled' => self::enabled() ] + self::settings();
	}

	/** Known keys of $in over $base, each clamped or validated (unknown keys and invalid values are ignored). */
	private static function clean( array $in, array $base = self::DEFAULTS ): array {
		$s = array_merge( self::DEFAULTS, array_intersect_key( $base, self::DEFAULTS ) );
		foreach ( [ 'enabled', 'strip_location', 'strip_xmp', 'strip_iptc' ] as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( isset( $in['sideload_max_px'] ) && is_numeric( $in['sideload_max_px'] ) ) {
			$s['sideload_max_px'] = self::clamp_px( (int) $in['sideload_max_px'] );
		}
		if ( isset( $in['sideload_format'] ) && in_array( $in['sideload_format'], self::FORMATS, true ) ) {
			$s['sideload_format'] = $in['sideload_format'];
		}
		if ( isset( $in['sideload_quality'] ) && is_numeric( $in['sideload_quality'] ) ) {
			$s['sideload_quality'] = max( 60, min( 95, (int) $in['sideload_quality'] ) );
		}
		if ( isset( $in['audit_max_kb'] ) && is_numeric( $in['audit_max_kb'] ) ) {
			$s['audit_max_kb'] = max( 100, min( 51200, (int) $in['audit_max_kb'] ) );
		}
		return $s;
	}

	private static function clamp_px( int $px ): int {
		return $px <= 0 ? 0 : max( 640, min( 4096, $px ) );
	}

	/* ------------------------------------------------------------------ tools */

	/** get-media-settings. */
	public static function get_settings_tool( $input = [] ): array {
		$s = self::view();
		$env = self::environment();
		$nb = self::neighbours();
		$notes = [];
		if ( ! self::enabled() ) {
			$notes[] = 'Media hygiene is off: uploads keep their metadata as WordPress leaves it.';
		}
		if ( 'imagick' === $env['editor'] ) {
			$notes[] = 'This server resizes with Imagick, which keeps EXIF (GPS included) in every generated size.';
		}
		if ( $nb['optimizer']['backup_dir_exists'] ) {
			$notes[] = 'The backup folder an image optimizer keeps in uploads holds public copies of original images (with their GPS). Strip them with strip-media-location scope=optimizer_backups, or delete the folder once the optimizer is gone.';
		}
		if ( $nb['optimizer']['htaccess_block'] ) {
			$notes[] = 'uploads/.htaccess contains a tiny-compress-images block (WebP/AVIF rewrites) next to the host\'s own rules. It is reported, never edited.';
		}
		if ( $nb['optimizer']['active'] ) {
			$notes[] = 'A third-party image optimizer is active. Launch Builder never reads its API key. When it is removed, its uninstall leaves the tiny_compress_images post meta and the .webp/.avif files behind.';
		}
		return [ 'settings' => $s, 'active' => $s['enabled'], 'switch' => self::registry() ? 'modules' : 'setting', 'environment' => $env, 'neighbours' => $nb, 'notes' => $notes ];
	}

	/** set-media-settings: partial update, validated and clamped, snapshotted (Launch Builder's own option: no ledger row). */
	public static function set_settings_tool( $input ) {
		$input = (array) $input;
		$known = array_intersect_key( $input, self::DEFAULTS );
		if ( ! $known ) {
			return new \WP_Error( 'lb_empty', 'Pass at least one of: ' . implode( ', ', array_keys( self::DEFAULTS ) ) . '.', [ 'status' => 400 ] );
		}
		foreach ( [ 'enabled', 'strip_location', 'strip_xmp', 'strip_iptc' ] as $k ) {
			if ( array_key_exists( $k, $known ) && ! is_bool( $known[ $k ] ) ) {
				return new \WP_Error( 'lb_invalid_args', $k . ' must be true or false.', [ 'status' => 400 ] );
			}
		}
		foreach ( [ 'sideload_max_px', 'sideload_quality', 'audit_max_kb' ] as $k ) {
			if ( array_key_exists( $k, $known ) && ( ! is_numeric( $known[ $k ] ) || (int) $known[ $k ] < 0 ) ) {
				return new \WP_Error( 'lb_invalid_args', $k . ' must be a whole number of 0 or more.', [ 'status' => 400 ] );
			}
		}
		if ( array_key_exists( 'sideload_format', $known ) && ! in_array( $known['sideload_format'], self::FORMATS, true ) ) {
			return new \WP_Error( 'lb_invalid_args', 'sideload_format must be one of: ' . implode( ', ', self::FORMATS ) . '.', [ 'status' => 400 ] );
		}
		$prev = self::view();
		$registry = self::registry();
		$switch = array_key_exists( 'enabled', $known ) ? (bool) $known['enabled'] : null;
		if ( $registry ) {
			unset( $known['enabled'] ); // The registry owns the switch: the stored `enabled` value is not used.
		}
		$stored = self::settings();
		$new = self::clean( $known, $stored );
		$clamped = [];
		foreach ( [ 'sideload_max_px', 'sideload_quality', 'audit_max_kb' ] as $k ) {
			if ( array_key_exists( $k, $known ) && (int) $known[ $k ] !== $new[ $k ] ) {
				$clamped[ $k ] = $new[ $k ];
			}
		}
		$out = [];
		if ( $registry && null !== $switch && $switch !== $prev['enabled'] ) { // Through the registry: same rules, snapshot and hooks as set-modules.
			$slug = self::MODULE['slug'];
			$res = call_user_func( [ self::REGISTRY, 'set' ], $switch ? [ $slug ] : [], $switch ? [] : [ $slug ] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$out['modules'] = [ 'changed' => (object) ( (array) ( $res['changed'] ?? [] ) ), 'snapshot' => $res['snapshot'] ?? '', 'note' => 'Switched in Launch Builder > Modules (set-modules); the module\'s tools follow from the next request.' ];
		}
		$snap = '';
		if ( $known && ( $new !== $stored || false === get_option( self::OPT ) ) ) { // Nothing changed: nothing written, no snapshot.
			$snap = Abilities::snapshot( 'set-media-settings', [], [ self::OPT ] );
			if ( false === get_option( self::OPT ) ) {
				add_option( self::OPT, $new, '', false );
			} else {
				update_option( self::OPT, $new, false );
			}
		}
		$now = self::view();
		$changed = array_keys( array_filter( $now, static function ( $v, $k ) use ( $prev ) { return $prev[ $k ] !== $v; }, ARRAY_FILTER_USE_BOTH ) );
		return [ 'settings' => $now, 'previous' => $prev, 'changed' => $changed, 'clamped' => (object) $clamped, 'active' => $now['enabled'], 'switch' => $registry ? 'modules' : 'setting', 'snapshot' => $snap ] + $out;
	}

	/** strip-media-location (registered only while enabled). */
	public static function strip_tool( $input ) {
		$input = (array) $input;
		$scope = (string) ( $input['scope'] ?? 'attachments' );
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new \WP_Error( 'lb_invalid_args', 'scope must be attachments or optimizer_backups.', [ 'status' => 400 ] );
		}
		if ( 'optimizer_backups' === $scope && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'lb_forbidden', 'Only administrators can treat the image optimizer backup copies.', [ 'status' => 403 ] );
		}
		if ( array_key_exists( 'dry_run', $input ) && ! is_bool( $input['dry_run'] ) ) {
			return new \WP_Error( 'lb_invalid_args', 'dry_run must be true or false.', [ 'status' => 400 ] );
		}
		$dry = ! array_key_exists( 'dry_run', $input ) || $input['dry_run'];
		$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 20 ) ) );
		$next = null;
		$skipped = 0;
		if ( array_key_exists( 'ids', $input ) ) {
			if ( ! is_array( $input['ids'] ) || ! $input['ids'] || count( $input['ids'] ) > 50 ) {
				return new \WP_Error( 'lb_invalid_args', 'ids must list 1 to 50 attachment ids.', [ 'status' => 400 ] );
			}
			$ids = [];
			foreach ( $input['ids'] as $raw ) {
				if ( ! is_numeric( $raw ) || (int) $raw <= 0 ) {
					return new \WP_Error( 'lb_invalid_args', 'ids must be positive integers.', [ 'status' => 400 ] );
				}
				$ids[ (int) $raw ] = (int) $raw;
			}
			foreach ( $ids as $id ) { // Object-level check on every id, before anything is read (no IDOR).
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return new \WP_Error( 'lb_forbidden', 'You cannot edit attachment ' . $id . '.', [ 'status' => 403 ] );
				}
				if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
					return new \WP_Error( 'lb_not_found', 'Not an image attachment: ' . $id . '.', [ 'status' => 404 ] );
				}
			}
			$ids = array_values( $ids );
		} else {
			$after = max( 0, (int) ( $input['after_id'] ?? 0 ) );
			$all = self::image_ids( $after, $limit );
			$next = count( $all ) === $limit ? (int) end( $all ) : null;
			$ids = [];
			foreach ( $all as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) {
					$ids[] = $id;
				} else {
					$skipped++; // Never listed: the caller may not see other users' files.
				}
			}
		}
		if ( ! Lock::acquire( 'media', 120 ) ) {
			return new \WP_Error( 'lb_busy', 'Another media job is running. Try again in a minute.', [ 'status' => 409 ] );
		}
		try {
			$s = self::settings();
			$flags = [ 'gps' => true, 'xmp' => $s['strip_xmp'], 'iptc' => $s['strip_iptc'] ];
			$base = self::base();
			$items = [];
			$tot = [ 'attachments' => count( $ids ), 'files' => 0, 'with_gps' => 0, 'with_xmp' => 0, 'changed' => 0, 'would_change' => 0, 'errors' => 0 ];
			foreach ( $ids as $id ) {
				$meta = wp_get_attachment_metadata( $id );
				$meta = is_array( $meta ) ? $meta : [];
				$files = self::files_of( $id, $meta );
				if ( 'optimizer_backups' === $scope ) {
					$copies = [];
					foreach ( $files as $f ) {
						$real = self::safe_path( $f['abs'] );
						$copy = $real ? $base . '/' . self::OPTIMIZER_BACKUP_DIR . '/' . self::rel( $real ) : '';
						if ( '' !== $copy && is_file( $copy ) ) {
							$copies[] = [ 'abs' => $copy, 'role' => 'optimizer:' . $f['role'], 'meta' => null ];
						}
					}
					$files = $copies;
				}
				$rows = [];
				$clean = 0;
				$meta_changed = false;
				foreach ( $files as $f ) {
					$r = self::process_file( $f['abs'], $flags, $dry );
					if ( ! $dry ) {
						self::$seen[ $f['abs'] ] = self::sig( $f['abs'] ); // The metadata save below need not look again.
					}
					$tot['files']++;
					$gps = $r['gps'] || $r['xmp_gps'];
					$tot['with_gps'] += $gps ? 1 : 0;
					$tot['with_xmp'] += $r['xmp'] ? 1 : 0;
					$tot['changed'] += $r['changed'] ? 1 : 0;
					$tot['would_change'] += $r['would_change'] ? 1 : 0;
					$tot['errors'] += isset( $r['error'] ) ? 1 : 0;
					if ( $r['changed'] && $f['meta'] ) {
						$meta = self::set_size( $meta, $f['meta'], $r['bytes'] );
						$meta_changed = true;
					}
					if ( ! $gps && ! $r['xmp'] && ! $r['iptc'] && ! $r['changed'] && ! isset( $r['error'] ) ) {
						$clean++;
						continue;
					}
					$row = [ 'file' => $r['file'], 'role' => $f['role'], 'type' => $r['type'], 'had_gps' => $gps, 'had_xmp' => $r['xmp'], 'had_iptc_location' => $r['iptc'], 'changed' => $r['changed'], 'bytes' => $r['bytes'] ];
					if ( $dry ) {
						$row['would_change'] = $r['would_change'];
					}
					if ( isset( $r['error'] ) ) {
						$row['error'] = $r['error'];
					}
					$rows[] = $row;
				}
				if ( $meta_changed ) {
					wp_update_attachment_metadata( $id, $meta );
				}
				$items[] = [ 'id' => $id, 'files' => $rows, 'clean_files' => $clean ];
			}
			if ( $tot['changed'] ) {
				Cache::purge_later();
			}
		} finally {
			Lock::release( 'media' );
		}
		return [ 'dry_run' => $dry, 'scope' => $scope, 'flags' => $flags, 'items' => $items, 'totals' => $tot, 'next_after_id' => $next, 'skipped_forbidden' => $skipped ];
	}

	/* ------------------------------------------------------------------ A. upload / edit hook */

	/**
	 * wp_generate_attachment_metadata + wp_update_attachment_metadata (priority 5): strip every file the metadata names.
	 * Core saves the metadata after each sub-size it makes, so files are handled as they appear (the original before any
	 * sub-size is cut from it); the seen-set keeps each file to one pass. Changed file sizes are written back into the
	 * metadata being saved.
	 */
	public static function on_metadata( $meta, $id = 0 ) {
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) || ! self::enabled() ) {
			return $meta; // Images only (videos, audio and PDFs carry no 'file'); off = nothing else happens.
		}
		$s = self::settings();
		$flags = [ 'gps' => $s['strip_location'], 'xmp' => $s['strip_xmp'], 'iptc' => $s['strip_iptc'] ];
		if ( ! array_filter( $flags ) ) {
			return $meta;
		}
		foreach ( self::files_of( (int) $id, $meta ) as $f ) {
			if ( ( self::$seen[ $f['abs'] ] ?? '' ) === self::sig( $f['abs'] ) ) {
				continue;
			}
			$r = self::process_file( $f['abs'], $flags );
			self::$seen[ $f['abs'] ] = self::sig( $f['abs'] );
			if ( $r['changed'] && $f['meta'] ) {
				$meta = self::set_size( $meta, $f['meta'], $r['bytes'] );
			}
		}
		return $meta;
	}

	/** "size:mtime" of a file, freshly stat'ed (the seen-set key). */
	private static function sig( string $abs ): string {
		clearstatcache( true, $abs );
		return @filesize( $abs ) . ':' . @filemtime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Every file of an image attachment, deduplicated, as [ abs, role, meta ] (meta = key path of its stored filesize).
	 * Names come from the metadata and must be bare file names in the main file's directory.
	 */
	public static function files_of( int $id, ?array $meta = null ): array {
		$meta = null === $meta ? wp_get_attachment_metadata( $id ) : $meta;
		$meta = is_array( $meta ) ? $meta : [];
		$main = ( isset( $meta['file'] ) && is_string( $meta['file'] ) && '' !== $meta['file'] ) ? $meta['file'] : (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $main ) {
			return [];
		}
		$uploads = wp_get_upload_dir();
		$abs = path_is_absolute( $main ) ? $main : path_join( $uploads['basedir'], $main );
		$dir = dirname( $abs );
		$out = [];
		$add = static function ( $name, string $role, $key ) use ( &$out, $dir ) {
			if ( ! is_string( $name ) || '' === $name || wp_basename( $name ) !== $name || '.' === $name[0] ) {
				return;
			}
			$p = $dir . '/' . $name;
			if ( ! isset( $out[ $p ] ) ) {
				$out[ $p ] = [ 'abs' => $p, 'role' => $role, 'meta' => $key ];
			}
		};
		$add( wp_basename( $abs ), 'full', isset( $meta['filesize'] ) ? [ 'filesize' ] : null );
		$add( $meta['original_image'] ?? '', 'original', null );
		$add( $meta['source_image'] ?? '', 'source', null );
		foreach ( (array) ( $meta['sources'] ?? [] ) as $mime => $src ) {
			$add( is_array( $src ) ? ( $src['file'] ?? '' ) : '', 'full:' . $mime, isset( $src['filesize'] ) ? [ 'sources', $mime, 'filesize' ] : null );
		}
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$add( $size['file'] ?? '', (string) $name, isset( $size['filesize'] ) ? [ 'sizes', $name, 'filesize' ] : null );
			foreach ( (array) ( $size['sources'] ?? [] ) as $mime => $src ) {
				$add( is_array( $src ) ? ( $src['file'] ?? '' ) : '', $name . ':' . $mime, isset( $src['filesize'] ) ? [ 'sizes', $name, 'sources', $mime, 'filesize' ] : null );
			}
		}
		$backups = $id ? get_post_meta( $id, '_wp_attachment_backup_sizes', true ) : [];
		foreach ( is_array( $backups ) ? $backups : [] as $key => $b ) {
			$add( is_array( $b ) ? ( $b['file'] ?? '' ) : '', 'backup:' . $key, null );
		}
		return array_values( $out );
	}

	/** Write a new byte size at a key path of the metadata (only where a size is already stored). */
	private static function set_size( array $meta, array $path, int $bytes ): array {
		$ref = &$meta;
		foreach ( $path as $k ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $k, $ref ) ) {
				return $meta;
			}
			$ref = &$ref[ $k ];
		}
		$ref = $bytes;
		unset( $ref );
		return $meta;
	}

	/* ------------------------------------------------------------------ A. the stripper */

	/**
	 * Scan one file and, unless $dry, remove what $flags ask for: gps (the EXIF GPS IFD), xmp (XMP packets), iptc (IPTC
	 * city and sublocation). No flags = scan only. $cap > 0 limits how much is read (audit header scans).
	 *
	 * @return array{file:string,type:string,gps:bool|null,xmp:bool,xmp_gps:bool,iptc:bool,changed:bool,would_change:bool,bytes:int,error?:string}
	 *         gps null = unknown (HEIC/AVIF without a readable EXIF block).
	 */
	public static function process_file( string $abs, array $flags = [], bool $dry = false, int $cap = 0 ): array {
		$r = self::result( '' );
		$flags = [ 'gps' => ! empty( $flags['gps'] ), 'xmp' => ! empty( $flags['xmp'] ), 'iptc' => ! empty( $flags['iptc'] ) ];
		$path = self::safe_path( $abs );
		if ( null === $path ) {
			$r['error'] = ( file_exists( $abs ) || is_link( $abs ) ) ? 'refused' : 'missing'; // Refused: a symlink, or outside uploads.
			return $r;
		}
		$r['file'] = self::rel( $path );
		$size = (int) filesize( $path );
		$r['bytes'] = $size;
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			$r['error'] = 'unreadable';
			return $r;
		}
		$magic = (string) fread( $fh, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$cap = $cap > 0 ? $cap : self::HEAD_CAP;
		$plan = null;
		if ( 0 === strncmp( $magic, "\xFF\xD8\xFF", 3 ) ) {
			$plan = self::jpeg( $fh, $size, $flags, $cap );
		} elseif ( 0 === strncmp( $magic, "\x89PNG\r\n\x1A\n", 8 ) ) {
			$plan = self::png( $fh, $size, $flags, $cap );
		} elseif ( 'RIFF' === substr( $magic, 0, 4 ) && 'WEBP' === substr( $magic, 8, 4 ) ) {
			$plan = self::webp( $fh, $size, $flags, $cap );
		} elseif ( 'ftyp' === substr( $magic, 4, 4 ) && preg_match( '/^(heic|heix|hevc|heim|heis|hevm|hevs|mif1|msf1|avif|avis)$/', substr( $magic, 8, 4 ) ) ) {
			$plan = self::heif( $fh, $size, substr( $magic, 8, 4 ), $cap );
		} else {
			$plan = [ [ 'type' => 'other' ], null ];
		}
		$r = array_merge( $r, $plan[0] );
		$pieces = $plan[1];
		if ( null === $pieces || isset( $r['error'] ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $r;
		}
		$r['would_change'] = true;
		if ( $dry ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $r;
		}
		if ( $size > self::MAX_FILE ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$r['error'] = 'too_big';
			return $r;
		}
		$done = self::rewrite( $path, $fh, $pieces ); // Closes $fh.
		if ( true !== $done ) {
			$r['error'] = $done;
			return $r;
		}
		clearstatcache( true, $path );
		$r['changed'] = true;
		$r['bytes'] = (int) filesize( $path );
		return $r;
	}

	/** fread() that returns '' for a length of 0 (PHP 8 throws on it). */
	private static function read( $fh, int $n ): string {
		return $n > 0 ? (string) fread( $fh, $n ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function result( string $type ): array {
		return [ 'file' => '', 'type' => $type, 'gps' => false, 'xmp' => false, 'xmp_gps' => false, 'iptc' => false, 'changed' => false, 'would_change' => false, 'bytes' => 0 ];
	}

	/**
	 * JPEG: walk the markers from SOI to SOS. GPS IFD emptied in place inside every APP1 Exif segment; APP1 XMP segments
	 * dropped; IPTC city/sublocation removed from APP13. With an MPF segment every edit keeps its length (XMP and IPTC
	 * values blanked instead of removed), because the multi-picture offsets must not move.
	 *
	 * @return array{0:array,1:array|null} result fields and the pieces to write (null = nothing to change)
	 */
	private static function jpeg( $fh, int $size, array $flags, int $cap ): array {
		$r = [ 'type' => 'jpeg', 'gps' => false, 'xmp' => false, 'xmp_gps' => false, 'iptc' => false ];
		$limit = min( $size, $cap );
		fseek( $fh, 0 );
		$buf = '';
		do {
			$chunk = fread( $fh, (int) min( 262144, $limit - strlen( $buf ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$buf .= $chunk;
			$p = self::jpeg_segments( $buf );
		} while ( 'more' === $p['state'] && strlen( $buf ) < $limit );
		if ( ! isset( $p ) || 'bad' === $p['state'] ) {
			$r['error'] = 'corrupt';
			return [ $r, null ];
		}
		$complete = 'ok' === $p['state'];
		if ( ! $complete && strlen( $buf ) >= $size ) {
			$r['error'] = 'truncated';
			return [ $r, null ];
		}
		$mpf = false;
		foreach ( $p['segs'] as $sg ) {
			if ( 0xE2 === $sg[0] && "MPF\0" === substr( $buf, $sg[1] + 4, 4 ) ) {
				$mpf = true;
			}
		}
		$edits = []; // segment offset => new payload (null = drop)
		foreach ( $p['segs'] as list( $m, $off, $len ) ) {
			$data = (string) substr( $buf, $off + 4, $len - 4 );
			if ( 0xE1 === $m && 0 === strncmp( $data, "Exif\0\0", 6 ) ) {
				$t = self::tiff_gps( (string) substr( $data, 6 ), $flags['gps'] );
				if ( $t['bad'] ) {
					$r['error'] = 'bad_exif'; // Inconsistent offsets: never write.
					return [ $r, null ];
				}
				if ( $t['gps'] ) {
					$r['gps'] = true;
					if ( $flags['gps'] ) {
						$edits[ $off ] = "Exif\0\0" . $t['data'];
					}
				}
			} elseif ( 0xE1 === $m && ( 0 === strncmp( $data, self::XMP_NS, strlen( self::XMP_NS ) ) || 0 === strncmp( $data, self::XMP_EXT, strlen( self::XMP_EXT ) ) ) ) {
				$ns = 0 === strncmp( $data, self::XMP_NS, strlen( self::XMP_NS ) ) ? strlen( self::XMP_NS ) : strlen( self::XMP_EXT );
				if ( '' === trim( (string) substr( $data, $ns ), " \t\r\n\0" ) ) {
					continue; // Already blanked.
				}
				$r['xmp'] = true;
				if ( preg_match( '/GPS(Latitude|Longitude)/', $data ) ) {
					$r['xmp_gps'] = true;
				}
				if ( $flags['xmp'] ) {
					$edits[ $off ] = $mpf ? substr( $data, 0, $ns ) . str_repeat( ' ', strlen( $data ) - $ns ) : null;
				}
			} elseif ( 0xED === $m && 0 === strncmp( $data, "Photoshop 3.0\0", 14 ) ) {
				$i = self::iptc( $data, $flags['iptc'], $mpf );
				if ( ! $i['bad'] && $i['found'] ) { // A malformed IPTC block is left alone; it never blocks the GPS edit.
					$r['iptc'] = true;
					if ( $flags['iptc'] && ( ! $mpf || strlen( $i['data'] ) === strlen( $data ) ) ) {
						$edits[ $off ] = $i['data'];
					}
				}
			}
		}
		if ( ! $edits || ! $complete ) {
			return [ $r, null ];
		}
		$sos = $p['sos'];
		if ( $mpf ) { // Same bytes, same length: payloads replaced in place.
			$head = substr( $buf, 0, $sos );
			foreach ( $edits as $off => $data ) {
				$head = substr_replace( $head, (string) $data, $off + 4, strlen( (string) $data ) );
			}
			if ( strlen( $head ) !== $sos ) {
				$r['error'] = 'layout';
				return [ $r, null ];
			}
		} else {
			$head = "\xFF\xD8";
			foreach ( $p['segs'] as list( $m, $off, $len ) ) {
				if ( ! array_key_exists( $off, $edits ) ) {
					$head .= substr( $buf, $off, $len );
				} elseif ( null !== $edits[ $off ] ) {
					$head .= "\xFF" . chr( $m ) . pack( 'n', strlen( $edits[ $off ] ) + 2 ) . $edits[ $off ];
				}
			}
		}
		return [ $r, [ $head, [ $sos, $size - $sos ] ] ];
	}

	/**
	 * Segments of a JPEG header in $b: [ [marker, offset, total length], ... ] and the SOS offset.
	 * state: ok (SOS found) | more (the buffer ends inside the header) | bad.
	 */
	private static function jpeg_segments( string $b ): array {
		$n = strlen( $b );
		$segs = [];
		if ( $n < 4 || "\xFF\xD8" !== substr( $b, 0, 2 ) ) {
			return [ 'state' => 'bad', 'segs' => [] ];
		}
		$p = 2;
		for ( $guard = 0; $guard < 4096; $guard++ ) {
			if ( $p + 4 > $n ) {
				return [ 'state' => 'more', 'segs' => $segs ];
			}
			if ( "\xFF" !== $b[ $p ] ) {
				return [ 'state' => 'bad', 'segs' => $segs ];
			}
			$m = ord( $b[ $p + 1 ] );
			if ( 0xFF === $m ) {
				$p++; // Fill byte.
				continue;
			}
			if ( 0x01 === $m || 0xD8 === $m || ( $m >= 0xD0 && $m <= 0xD7 ) ) {
				$p += 2; // Stand-alone marker.
				continue;
			}
			if ( 0xD9 === $m ) {
				return [ 'state' => 'bad', 'segs' => $segs ]; // EOI before any scan.
			}
			if ( 0xDA === $m ) {
				return [ 'state' => 'ok', 'segs' => $segs, 'sos' => $p ];
			}
			$len = unpack( 'n', substr( $b, $p + 2, 2 ) )[1];
			if ( $len < 2 ) {
				return [ 'state' => 'bad', 'segs' => $segs ];
			}
			if ( $p + 2 + $len > $n ) {
				return [ 'state' => 'more', 'segs' => $segs ];
			}
			$segs[] = [ $m, $p, 2 + $len ];
			$p += 2 + $len;
		}
		return [ 'state' => 'bad', 'segs' => $segs ];
	}

	/**
	 * Find (and with $clear remove) the GPS IFD of a TIFF/EXIF block, in place: the GPS entries, their out-of-line values
	 * and its next-IFD pointer are zeroed, and the GPSInfo entry leaves IFD0 (later entries and the next-IFD pointer move
	 * up 12 bytes inside IFD0, so nothing outside it moves). Every offset is bounds-checked; anything inconsistent = bad.
	 *
	 * @return array{gps:bool,bad:bool,data:string}
	 */
	private static function tiff_gps( string $t, bool $clear ): array {
		$n = strlen( $t );
		$bad = [ 'gps' => false, 'bad' => true, 'data' => $t ];
		if ( $n < 8 ) {
			return $bad;
		}
		$bo = substr( $t, 0, 2 );
		if ( 'II' === $bo ) {
			$f16 = 'v';
			$f32 = 'V';
		} elseif ( 'MM' === $bo ) {
			$f16 = 'n';
			$f32 = 'N';
		} else {
			return $bad;
		}
		$u16 = static function ( int $o ) use ( &$t, $f16 ): int {
			return unpack( $f16, substr( $t, $o, 2 ) )[1];
		};
		$u32 = static function ( int $o ) use ( &$t, $f32 ): int {
			return unpack( $f32, substr( $t, $o, 4 ) )[1];
		};
		if ( 42 !== $u16( 2 ) ) {
			return $bad;
		}
		$ifd = $u32( 4 );
		if ( $ifd < 8 || $ifd + 2 > $n ) {
			return $bad;
		}
		$cnt = $u16( $ifd );
		if ( $cnt > 1024 || $ifd + 2 + 12 * $cnt > $n ) {
			return $bad;
		}
		$k = -1;
		for ( $i = 0; $i < $cnt; $i++ ) {
			if ( 0x8825 === $u16( $ifd + 2 + 12 * $i ) ) {
				$k = $i;
				break;
			}
		}
		if ( $k < 0 ) {
			return [ 'gps' => false, 'bad' => false, 'data' => $t ];
		}
		$gps = $u32( $ifd + 2 + 12 * $k + 8 );
		if ( 0 === $gps ) {
			return [ 'gps' => false, 'bad' => false, 'data' => $t ];
		}
		if ( $gps < 8 || $gps + 2 > $n ) {
			return $bad;
		}
		$gc = $u16( $gps );
		if ( 0 === $gc ) {
			return [ 'gps' => false, 'bad' => false, 'data' => $t ]; // An empty GPS IFD: nothing to remove.
		}
		if ( $gc > 256 || $gps + 2 + 12 * $gc > $n ) {
			return $bad;
		}
		$ifd_end = $ifd + 2 + 12 * $cnt + 4;
		$zero = [ [ $gps, 2 + 12 * $gc + ( $gps + 2 + 12 * $gc + 4 <= $n ? 4 : 0 ) ] ];
		$sizes = [ 1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4 ];
		for ( $i = 0; $i < $gc; $i++ ) {
			$e = $gps + 2 + 12 * $i;
			$type = $u16( $e + 2 );
			$count = $u32( $e + 4 );
			if ( ! isset( $sizes[ $type ] ) || $count > $n ) {
				return $bad;
			}
			$len = $sizes[ $type ] * $count;
			if ( $len > 4 ) {
				$off = $u32( $e + 8 );
				if ( $off < 8 || $off + $len > $n ) {
					return $bad;
				}
				$zero[] = [ $off, $len ];
			}
		}
		foreach ( $zero as list( $o, $l ) ) { // Nothing to zero may overlap IFD0 (it is rewritten below).
			if ( $o < $ifd_end && $o + $l > $ifd ) {
				return $bad;
			}
		}
		if ( ! $clear ) {
			return [ 'gps' => true, 'bad' => false, 'data' => $t ];
		}
		foreach ( $zero as list( $o, $l ) ) {
			$t = substr_replace( $t, str_repeat( "\0", $l ), $o, $l );
		}
		// IFD0 without the GPSInfo entry: shift the later entries and the next-IFD pointer up, zero the freed 12 bytes.
		$entries = (string) substr( $t, $ifd + 2, 12 * $cnt );
		$entries = substr( $entries, 0, 12 * $k ) . substr( $entries, 12 * ( $k + 1 ) );
		$next = $ifd + 2 + 12 * $cnt + 4 <= $n ? substr( $t, $ifd + 2 + 12 * $cnt, 4 ) : "\0\0\0\0";
		$block = pack( $f16, $cnt - 1 ) . $entries . $next . str_repeat( "\0", 12 );
		$block = substr( $block, 0, min( strlen( $block ), $n - $ifd ) );
		$t = substr_replace( $t, $block, $ifd, strlen( $block ) );
		return [ 'gps' => true, 'bad' => false, 'data' => $t ];
	}

	/**
	 * IPTC city (2:90) and sublocation (2:92) inside an APP13 "Photoshop 3.0" block: found (non-blank)? and, with $strip,
	 * the block without them ($blank: values replaced by spaces, same length). Copyright and every other record stay.
	 *
	 * @return array{found:bool,bad:bool,data:string}
	 */
	private static function iptc( string $d, bool $strip, bool $blank ): array {
		$n = strlen( $d );
		$bad = [ 'found' => false, 'bad' => true, 'data' => $d ];
		$out = substr( $d, 0, 14 );
		$p = 14;
		$found = false;
		for ( $guard = 0; $guard < 1000 && $p + 12 <= $n && '8BIM' === substr( $d, $p, 4 ); $guard++ ) {
			$rid = unpack( 'n', substr( $d, $p + 4, 2 ) )[1];
			$nl = ord( $d[ $p + 6 ] );
			$nb = 1 + $nl + ( ( 1 + $nl ) % 2 );
			$q = $p + 6 + $nb;
			if ( $q + 4 > $n ) {
				return $bad;
			}
			$sz = unpack( 'N', substr( $d, $q, 4 ) )[1];
			if ( $q + 4 + $sz > $n ) {
				return $bad;
			}
			$res = (string) substr( $d, $q + 4, $sz );
			if ( 0x0404 === $rid ) {
				$x = self::iptc_records( $res, $strip, $blank );
				if ( $x['bad'] ) {
					return $bad;
				}
				$found = $found || $x['found'];
				$res = $x['data'];
			}
			$out .= substr( $d, $p, 6 + $nb ) . pack( 'N', strlen( $res ) ) . $res . ( strlen( $res ) % 2 ? "\0" : '' );
			$p = $q + 4 + $sz + ( $sz % 2 );
		}
		$out .= (string) substr( $d, min( $p, $n ) );
		return [ 'found' => $found, 'bad' => false, 'data' => $out ];
	}

	private static function iptc_records( string $r, bool $strip, bool $blank ): array {
		$n = strlen( $r );
		$p = 0;
		$out = '';
		$found = false;
		for ( $guard = 0; $guard < 10000 && $p < $n; $guard++ ) {
			if ( "\x1C" !== $r[ $p ] ) {
				$out .= substr( $r, $p ); // Padding or unknown bytes: kept as they are.
				$p = $n;
				break;
			}
			if ( $p + 5 > $n ) {
				return [ 'found' => false, 'bad' => true, 'data' => $r ];
			}
			$rec = ord( $r[ $p + 1 ] );
			$ds = ord( $r[ $p + 2 ] );
			$len = unpack( 'n', substr( $r, $p + 3, 2 ) )[1];
			$hdr = 5;
			if ( $len & 0x8000 ) { // Extended dataset: the next (len & 0x7FFF) bytes hold the length.
				$k = $len & 0x7FFF;
				if ( $k < 1 || $k > 4 || $p + 5 + $k > $n ) {
					return [ 'found' => false, 'bad' => true, 'data' => $r ];
				}
				$len = 0;
				for ( $i = 0; $i < $k; $i++ ) {
					$len = ( $len << 8 ) | ord( $r[ $p + 5 + $i ] );
				}
				$hdr = 5 + $k;
			}
			if ( $p + $hdr + $len > $n ) {
				return [ 'found' => false, 'bad' => true, 'data' => $r ];
			}
			$loc = 2 === $rec && ( 90 === $ds || 92 === $ds ) && '' !== trim( (string) substr( $r, $p + $hdr, $len ) );
			$found = $found || $loc;
			if ( ! ( $loc && $strip ) ) {
				$out .= substr( $r, $p, $hdr + $len );
			} elseif ( $blank ) {
				$out .= substr( $r, $p, $hdr ) . str_repeat( ' ', $len );
			}
			$p += $hdr + $len;
		}
		return [ 'found' => $found, 'bad' => false, 'data' => $out ];
	}

	/**
	 * PNG: walk the chunks to IEND (headers only, data read just for the chunks that matter). eXIf: GPS IFD removed in
	 * place, CRC recomputed. XMP text chunks (XML:com.adobe.xmp, Raw profile type xmp) dropped; ImageMagick's
	 * "Raw profile type exif/APP1" text chunks dropped when they hold GPS (or cannot be read). IDAT and every other chunk
	 * are copied byte for byte with their own CRC.
	 */
	private static function png( $fh, int $size, array $flags, int $cap ): array {
		$r = [ 'type' => 'png', 'gps' => false, 'xmp' => false, 'xmp_gps' => false, 'iptc' => false, 'color' => null, 'trns' => false, 'w' => 0, 'h' => 0 ];
		$edits = []; // chunk offset => [ old data length, new chunk bytes|null ]
		$p = 8;
		$read = 8;
		$iend = false;
		for ( $guard = 0; $guard < 200000 && $p + 12 <= $size; $guard++ ) {
			fseek( $fh, $p );
			$h = (string) fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$read += 8;
			if ( strlen( $h ) < 8 ) {
				break;
			}
			$len = unpack( 'N', substr( $h, 0, 4 ) )[1];
			$type = substr( $h, 4, 4 );
			if ( $len > 0x7FFFFFFF || $p + 12 + $len > $size || ! preg_match( '/^[A-Za-z]{4}$/', $type ) || ( 0 === $guard && 'IHDR' !== $type ) ) {
				$r['error'] = 'corrupt';
				return [ $r, null ];
			}
			if ( 'IHDR' === $type && $len >= 13 ) {
				$d = (string) fread( $fh, 13 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$r['w'] = unpack( 'N', substr( $d, 0, 4 ) )[1];
				$r['h'] = unpack( 'N', substr( $d, 4, 4 ) )[1];
				$r['color'] = ord( $d[9] );
			} elseif ( 'tRNS' === $type ) {
				$r['trns'] = true;
			} elseif ( 'eXIf' === $type ) {
				if ( $len > 4194304 || $read + $len > $cap ) {
					$r['error'] = 'exif_too_large';
					return [ $r, null ];
				}
				$d = self::read( $fh, $len );
				$read += $len;
				$pre = 0 === strncmp( $d, "Exif\0\0", 6 ) ? 6 : 0;
				$t = self::tiff_gps( (string) substr( $d, $pre ), $flags['gps'] );
				if ( $t['bad'] ) {
					$r['error'] = 'bad_exif';
					return [ $r, null ];
				}
				if ( $t['gps'] ) {
					$r['gps'] = true;
					if ( $flags['gps'] ) {
						$new = substr( $d, 0, $pre ) . $t['data'];
						$edits[ $p ] = [ $len, pack( 'N', strlen( $new ) ) . 'eXIf' . $new . pack( 'N', crc32( 'eXIf' . $new ) ) ];
					}
				}
			} elseif ( 'iTXt' === $type || 'tEXt' === $type || 'zTXt' === $type ) {
				$kw = strtolower( (string) strstr( self::read( $fh, (int) min( $len, 80 ) ) . "\0", "\0", true ) );
				$read += min( $len, 80 );
				if ( 'xml:com.adobe.xmp' === $kw || 'raw profile type xmp' === $kw ) {
					$r['xmp'] = true;
					if ( $len <= 4194304 && $read + $len <= $cap ) {
						fseek( $fh, $p + 8 );
						$d = self::png_text( self::read( $fh, $len ), $type );
						$read += $len;
						$r['xmp_gps'] = $r['xmp_gps'] || ( null !== $d && (bool) preg_match( '/GPS(Latitude|Longitude)/', $d ) );
					}
					if ( $flags['xmp'] ) {
						$edits[ $p ] = [ $len, null ];
					}
				} elseif ( 'raw profile type exif' === $kw || 'raw profile type app1' === $kw ) {
					$g = null;
					if ( $len <= 4194304 && $read + $len <= $cap ) {
						fseek( $fh, $p + 8 );
						$g = self::raw_profile_gps( self::read( $fh, $len ), $type );
						$read += $len;
					}
					if ( false !== $g ) { // GPS, or unreadable: treated as location data.
						$r['gps'] = true;
						if ( $flags['gps'] ) {
							$edits[ $p ] = [ $len, null ];
						}
					}
				}
			} elseif ( 'IEND' === $type ) {
				$iend = true;
				break;
			}
			$p += 12 + $len;
			if ( $read > $cap ) {
				break; // Scan budget used up (audit): report what was seen.
			}
		}
		if ( ! $iend ) {
			if ( $read <= $cap ) {
				$r['error'] = 'truncated';
			}
			return [ $r, null ];
		}
		if ( ! $edits ) {
			return [ $r, null ];
		}
		ksort( $edits );
		$pieces = [];
		$at = 0;
		foreach ( $edits as $off => list( $len, $bytes ) ) {
			$pieces[] = [ $at, $off - $at ];
			if ( null !== $bytes ) {
				$pieces[] = $bytes;
			}
			$at = $off + 12 + $len;
		}
		$pieces[] = [ $at, $size - $at ];
		return [ $r, $pieces ];
	}

	/** The text of a PNG text chunk (data = keyword onwards), null when it cannot be read. */
	private static function png_text( string $d, string $type ): ?string {
		$z = strpos( $d, "\0" );
		if ( false === $z ) {
			return null;
		}
		$rest = (string) substr( $d, $z + 1 );
		if ( 'tEXt' === $type ) {
			return $rest;
		}
		if ( 'zTXt' === $type ) {
			$u = function_exists( 'gzuncompress' ) ? @gzuncompress( (string) substr( $rest, 1 ), 8388608 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false === $u ? null : $u;
		}
		// iTXt: compression flag, method, language\0, translated keyword\0, text.
		if ( strlen( $rest ) < 2 ) {
			return null;
		}
		$compressed = "\x01" === $rest[0];
		$rest = (string) substr( $rest, 2 );
		for ( $i = 0; $i < 2; $i++ ) {
			$z = strpos( $rest, "\0" );
			if ( false === $z ) {
				return null;
			}
			$rest = (string) substr( $rest, $z + 1 );
		}
		if ( ! $compressed ) {
			return $rest;
		}
		$u = function_exists( 'gzuncompress' ) ? @gzuncompress( $rest, 8388608 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $u ? null : $u;
	}

	/** Does an ImageMagick "Raw profile type exif" chunk hold GPS? null = cannot tell. */
	private static function raw_profile_gps( string $d, string $type ): ?bool {
		$text = self::png_text( $d, $type );
		if ( null === $text || ! preg_match( '/^\s*[a-z0-9]+\s+(\d+)\s+([0-9a-fA-F\s]+)$/s', $text, $m ) ) {
			return null;
		}
		$bin = hex2bin( (string) preg_replace( '/\s+/', '', $m[2] ) );
		if ( false === $bin ) {
			return null;
		}
		$pos = strpos( $bin, "Exif\0\0" );
		$t = self::tiff_gps( false === $pos ? $bin : (string) substr( $bin, $pos + 6 ), false );
		return $t['bad'] ? null : $t['gps'];
	}

	/**
	 * WebP (RIFF): GPS removed in place inside the EXIF chunk; the XMP chunk dropped (VP8X XMP flag cleared, RIFF size
	 * adjusted). Image chunks are copied byte for byte.
	 */
	private static function webp( $fh, int $size, array $flags, int $cap ): array {
		$r = [ 'type' => 'webp', 'gps' => false, 'xmp' => false, 'xmp_gps' => false, 'iptc' => false ];
		fseek( $fh, 0 );
		$h = (string) fread( $fh, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$riff = unpack( 'V', substr( $h, 4, 4 ) )[1];
		$end = 8 + $riff;
		if ( $end > $size || $riff < 4 ) {
			$r['error'] = 'truncated';
			return [ $r, null ];
		}
		$chunks = [];
		$vp8x = null;
		$p = 12;
		$read = 12;
		for ( $guard = 0; $guard < 100000 && $p + 8 <= $end; $guard++ ) {
			fseek( $fh, $p );
			$c = (string) fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$read += 8;
			$cc = substr( $c, 0, 4 );
			$len = unpack( 'V', substr( $c, 4, 4 ) )[1];
			$tot = 8 + $len + ( $len % 2 );
			if ( $p + 8 + $len > $end ) {
				$r['error'] = 'corrupt';
				return [ $r, null ];
			}
			$chunk = [ 'cc' => $cc, 'off' => $p, 'tot' => min( $tot, $end - $p ), 'new' => false ];
			if ( 'VP8X' === $cc && $len >= 10 ) {
				$vp8x = count( $chunks );
				$chunk['data'] = self::read( $fh, $len );
			} elseif ( 'EXIF' === $cc ) {
				if ( $len > 4194304 || $read + $len > $cap ) {
					$r['error'] = 'exif_too_large';
					return [ $r, null ];
				}
				$d = self::read( $fh, $len );
				$read += $len;
				$pre = 0 === strncmp( $d, "Exif\0\0", 6 ) ? 6 : 0;
				$t = self::tiff_gps( (string) substr( $d, $pre ), $flags['gps'] );
				if ( $t['bad'] ) {
					$r['error'] = 'bad_exif';
					return [ $r, null ];
				}
				if ( $t['gps'] ) {
					$r['gps'] = true;
					if ( $flags['gps'] ) {
						$chunk['new'] = 'EXIF' . pack( 'V', $len ) . substr( $d, 0, $pre ) . $t['data'] . ( $len % 2 ? "\0" : '' );
					}
				}
			} elseif ( 'XMP ' === $cc ) {
				$r['xmp'] = true;
				if ( $len <= 4194304 && $read + $len <= $cap ) {
					$r['xmp_gps'] = (bool) preg_match( '/GPS(Latitude|Longitude)/', self::read( $fh, $len ) );
					$read += $len;
				}
				if ( $flags['xmp'] ) {
					$chunk['new'] = '';
				}
			}
			$chunks[] = $chunk;
			$p += $tot;
			if ( $read > $cap ) {
				return [ $r, null ]; // Scan budget used up.
			}
		}
		$changed = array_filter( $chunks, static function ( $c ) { return false !== $c['new']; } );
		if ( ! $changed ) {
			return [ $r, null ];
		}
		$dropped_xmp = (bool) array_filter( $chunks, static function ( $c ) { return 'XMP ' === $c['cc'] && '' === $c['new']; } );
		if ( $dropped_xmp && null !== $vp8x ) {
			$d = $chunks[ $vp8x ]['data'];
			$d[0] = chr( ord( $d[0] ) & ~0x04 );
			$chunks[ $vp8x ]['new'] = 'VP8X' . pack( 'V', strlen( $chunks[ $vp8x ]['data'] ) ) . $d . ( strlen( $d ) % 2 ? "\0" : '' );
		}
		$body = 0;
		$pieces = [ '' ];
		foreach ( $chunks as $c ) {
			if ( false === $c['new'] ) {
				$pieces[] = [ $c['off'], $c['tot'] ];
				$body += $c['tot'];
			} elseif ( '' !== $c['new'] ) {
				$pieces[] = $c['new'];
				$body += strlen( $c['new'] );
			}
		}
		$pieces[0] = 'RIFF' . pack( 'V', 4 + $body ) . 'WEBP';
		if ( $end < $size ) {
			$pieces[] = [ $end, $size - $end ]; // Trailing bytes after the RIFF container, kept.
		}
		return [ $r, $pieces ];
	}

	/** HEIC/HEIF/AVIF: never edited. GPS looked up in the first EXIF block found in the header bytes (null = unknown). */
	private static function heif( $fh, int $size, string $brand, int $cap ): array {
		$r = [ 'type' => in_array( $brand, [ 'avif', 'avis' ], true ) ? 'avif' : 'heif', 'gps' => null ];
		fseek( $fh, 0 );
		$buf = self::read( $fh, (int) min( $size, $cap, self::SCAN_BYTES * 4 ) );
		$at = 0;
		for ( $i = 0; $i < 4; $i++ ) {
			$pos = strpos( $buf, "Exif\0\0", $at );
			if ( false === $pos ) {
				break;
			}
			$t = self::tiff_gps( (string) substr( $buf, $pos + 6 ), false );
			if ( ! $t['bad'] ) {
				$r['gps'] = $t['gps'];
				break;
			}
			$at = $pos + 6;
		}
		return [ $r, null ];
	}

	/**
	 * Write $pieces (strings, or [offset, length] ranges of the source) to a temp file next to $path, then rename it over
	 * $path. Closes $src. On any failure the original is untouched and the temp file removed.
	 *
	 * @return true|string error code
	 */
	private static function rewrite( string $path, $src, array $pieces ) {
		$dir = dirname( $path );
		if ( ! is_writable( $path ) || ! wp_is_writable( $dir ) ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'not_writable';
		}
		$tmp = $dir . '/.lb-media-' . wp_generate_password( 12, false, false ) . '.tmp';
		$out = @fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $out ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'not_writable';
		}
		$ok = true;
		$total = 0;
		foreach ( $pieces as $pc ) {
			if ( is_string( $pc ) ) {
				$ok = $ok && strlen( $pc ) === (int) fwrite( $out, $pc ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$total += strlen( $pc );
				continue;
			}
			list( $o, $l ) = $pc;
			if ( $l <= 0 ) {
				continue;
			}
			$ok = $ok && 0 === fseek( $src, $o ) && $l === (int) stream_copy_to_stream( $src, $out, $l );
			$total += $l;
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache( true, $tmp );
		if ( ! $ok || (int) filesize( $tmp ) !== $total ) {
			wp_delete_file( $tmp );
			return 'write_failed';
		}
		$perms = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $perms ) {
			@chmod( $tmp, $perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );
			return 'rename_failed';
		}
		return true;
	}

	/** realpath of $abs when it is a regular file under the uploads directory and not a symlink; null otherwise. */
	private static function safe_path( string $abs ): ?string {
		$base = self::base();
		if ( '' === $base || '' === $abs || is_link( $abs ) ) {
			return null;
		}
		$real = realpath( $abs );
		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}
		$real = wp_normalize_path( $real );
		return 0 === strpos( $real, $base . '/' ) ? $real : null;
	}

	private static function base(): string {
		if ( null === self::$base ) {
			$b = realpath( wp_get_upload_dir()['basedir'] );
			self::$base = $b ? untrailingslashit( wp_normalize_path( $b ) ) : '';
		}
		return self::$base;
	}

	/** Path relative to the uploads directory (never an absolute path in tool output). */
	private static function rel( string $real ): string {
		$base = self::base();
		return 0 === strpos( $real, $base . '/' ) ? substr( $real, strlen( $base ) + 1 ) : wp_basename( $real );
	}

	/* ------------------------------------------------------------------ B. sideload normalisation */

	/**
	 * The import step of sideload-media (Abilities::sideload_media). Exactly media_sideload_image() as before unless the
	 * module is on or the call passes max_px / format. Then: download (25 MB ceiling), refuse more than 40 megapixels or
	 * the memory budget, resize to max_px and/or convert (auto: opaque truecolour PNG -> JPEG), import, and report
	 * bytes_in, bytes_out, mime_in, mime_out, w, h (+ normalized, notes) in $info.
	 *
	 * @param array $input the tool input (max_px, format).
	 * @param array $info  filled with the report.
	 * @return int|\WP_Error attachment id
	 */
	public static function sideload( string $url, int $post_id, string $title, array $input, &$info = [] ) {
		$info = [];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$on = self::enabled();
		if ( ! $on && ! array_key_exists( 'max_px', $input ) && ! array_key_exists( 'format', $input ) ) {
			return media_sideload_image( $url, $post_id, $title, 'id' ); // Back-compat: unchanged behaviour.
		}
		$opts = self::sideload_options( $input, $on );
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}
		$exts = array_map( 'preg_quote', (array) apply_filters( 'image_sideload_extensions', [ 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' ], $url ) );
		if ( ! preg_match( '/[^\?]+\.(' . implode( '|', $exts ) . ')\b/i', $url, $m ) ) {
			return new \WP_Error( 'image_sideload_failed', 'Invalid image URL.' );
		}
		$limit = static function ( $args ) {
			$args['limit_response_size'] = self::MAX_DOWNLOAD + 1;
			return $args;
		};
		add_filter( 'http_request_args', $limit );
		$tmp = download_url( $url );
		remove_filter( 'http_request_args', $limit );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$id = self::import_file( $tmp, wp_basename( $m[0] ), $post_id, $title, $opts, $info );
		if ( ! is_wp_error( $id ) ) {
			add_post_meta( $id, '_source_url', $url ); // As media_sideload_image() does.
		}
		return $id;
	}

	/**
	 * max_px / format / quality for one sideload: explicit input first, then the settings (when on), else unchanged.
	 *
	 * @return array|\WP_Error { max_px, format, quality }
	 */
	public static function sideload_options( array $input, bool $on ) {
		$s = self::settings();
		if ( array_key_exists( 'max_px', $input ) && ( ! is_numeric( $input['max_px'] ) || (int) $input['max_px'] < 0 ) ) {
			return new \WP_Error( 'lb_invalid_args', 'max_px must be 0 (keep the size) or 640-4096.', [ 'status' => 400 ] );
		}
		if ( array_key_exists( 'format', $input ) && ! in_array( $input['format'], self::FORMATS, true ) ) {
			return new \WP_Error( 'lb_invalid_args', 'format must be one of: ' . implode( ', ', self::FORMATS ) . '.', [ 'status' => 400 ] );
		}
		return [
			'max_px'  => array_key_exists( 'max_px', $input ) ? self::clamp_px( (int) $input['max_px'] ) : ( $on ? $s['sideload_max_px'] : 0 ),
			'format'  => array_key_exists( 'format', $input ) ? $input['format'] : ( $on ? $s['sideload_format'] : 'keep' ),
			'quality' => $s['sideload_quality'],
		];
	}

	/**
	 * Normalise a downloaded file and import it (media_handle_sideload). $tmp is consumed (moved or deleted).
	 *
	 * @param array $opts { max_px, format, quality }
	 * @return int|\WP_Error
	 */
	public static function import_file( string $tmp, string $name, int $post_id, string $title, array $opts, &$info = [] ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$info = [];
		$cleanup = [ $tmp ];
		try {
			$bytes_in = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $bytes_in > self::MAX_DOWNLOAD ) {
				return new \WP_Error( 'lb_too_large', 'That image is larger than 25 MB.', [ 'status' => 400 ] );
			}
			$dim = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $dim || empty( $dim['mime'] ) || $dim[0] < 1 || $dim[1] < 1 ) {
				return new \WP_Error( 'image_sideload_failed', 'Not an image.' );
			}
			list( $w, $h ) = $dim;
			$mime = $dim['mime'];
			if ( $w * $h > self::MAX_PIXELS ) {
				return new \WP_Error( 'lb_too_large', sprintf( 'That image is %1$d x %2$d px (%3$.0f megapixels); the limit is %4$d.', $w, $h, $w * $h / 1e6, self::MAX_PIXELS / 1e6 ), [ 'status' => 400 ] );
			}
			$max = (int) ( $opts['max_px'] ?? 0 );
			$format = (string) ( $opts['format'] ?? 'keep' );
			$notes = [];
			$file = $tmp;
			$out_name = $name;
			$convert = ( 'webp' === $format && ! in_array( $mime, [ 'image/webp', 'image/gif' ], true ) )
				|| ( 'image/png' === $mime && in_array( $format, [ 'auto', 'jpeg' ], true ) )
				|| ( 'image/webp' === $mime && 'jpeg' === $format );
			$may_decode = ( $max > 0 && max( $w, $h ) > $max ) || $convert;
			if ( $may_decode && 'image/gif' !== $mime && ! self::memory_ok( $tmp, $mime, $w, $h, $max, $format ) ) { // Only when pixels will be decoded.
				return new \WP_Error( 'lb_too_large', sprintf( 'That image is %1$d x %2$d px: too large for the memory this server gives image processing.', $w, $h ), [ 'status' => 400 ] );
			}
			$plan = $may_decode ? self::plan( $tmp, $mime, $w, $h, $max, $format ) : [ 'resize' => false, 'to' => null, 'notes' => [] ];
			$notes = $plan['notes'];
			if ( $plan['resize'] || $plan['to'] ) {
				$ext = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' ][ $mime ] ?? 'img';
				$src = $tmp . '.' . $ext;
				if ( @copy( $tmp, $src ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$cleanup[] = $src;
					$editor = wp_get_image_editor( $src );
					if ( is_wp_error( $editor ) ) {
						$notes[] = 'No image editor could open it: imported unchanged.';
					} else {
						if ( 'image/jpeg' === $mime ) {
							$editor->maybe_exif_rotate(); // GD drops EXIF on save: bake the orientation into the pixels first.
						}
						if ( $plan['resize'] ) {
							$editor->resize( $max, $max, false );
						}
						$to = $plan['to'] ?: $mime;
						$stem = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
						$stem = '' === $stem ? 'image' : $stem;
						$dest = trailingslashit( get_temp_dir() ) . 'lbm-' . wp_generate_password( 6, false, false ) . '-' . $stem . '.' . ( [ 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/png' => 'png' ][ $to ] ?? $ext );
						$saved = self::save_as( $editor, $dest, $to, (int) ( $opts['quality'] ?? 82 ) );
						if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
							$notes[] = 'The converted image could not be saved: imported unchanged.';
						} else {
							$cleanup[] = $saved['path'];
							if ( $plan['resize'] || (int) filesize( $saved['path'] ) < $bytes_in ) {
								$file = $saved['path'];
								$out_name = $stem . '.' . pathinfo( $saved['path'], PATHINFO_EXTENSION );
							} else {
								$notes[] = 'Converting would not make it smaller: imported unchanged.';
							}
						}
					}
				}
			}
			$id = media_handle_sideload( [ 'name' => $out_name, 'tmp_name' => $file ], $post_id, $title ); // Same title rule as media_sideload_image().
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$meta = wp_get_attachment_metadata( $id );
			$attached = get_attached_file( $id );
			$info = [
				'bytes_in'   => $bytes_in,
				'bytes_out'  => $attached && is_file( $attached ) ? (int) filesize( $attached ) : 0,
				'mime_in'    => $mime,
				'mime_out'   => (string) get_post_mime_type( $id ),
				'w'          => (int) ( $meta['width'] ?? 0 ),
				'h'          => (int) ( $meta['height'] ?? 0 ),
				'normalized' => $file !== $tmp,
			];
			if ( $notes ) {
				$info['notes'] = $notes;
			}
			return $id;
		} finally {
			foreach ( $cleanup as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
	}

	/**
	 * What to do with one image: resize (longest edge over max_px; never GIFs, which may be animated) and convert to
	 * (auto: opaque truecolour/grey PNG -> JPEG; jpeg: PNG or WebP without transparency -> JPEG; webp: anything but
	 * GIF/WebP when the editor supports WebP; keep: nothing).
	 */
	private static function plan( string $file, string $mime, int $w, int $h, int $max, string $format ): array {
		$p = [ 'resize' => $max > 0 && max( $w, $h ) > $max, 'to' => null, 'notes' => [] ];
		if ( 'image/gif' === $mime ) {
			if ( $p['resize'] ) {
				$p['notes'][] = 'GIF imported unchanged (it may be animated).';
			}
			$p['resize'] = false;
			return $p;
		}
		if ( 'webp' === $format ) {
			if ( 'image/webp' !== $mime ) {
				if ( wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
					$p['to'] = 'image/webp';
				} else {
					$p['notes'][] = 'This server cannot write WebP: the format was kept.';
				}
			}
			return $p;
		}
		if ( 'auto' !== $format && 'jpeg' !== $format ) {
			return $p;
		}
		if ( 'image/png' === $mime ) {
			$info = self::png_header( $file );
			$photo = 'jpeg' === $format || in_array( $info['color'], [ 0, 2, 4, 6 ], true ); // Palette PNGs are graphics: auto keeps them.
			if ( $photo && ! self::png_alpha( $file, $info ) ) {
				$p['to'] = 'image/jpeg';
			} elseif ( 'jpeg' === $format ) {
				$p['notes'][] = 'It has transparency: kept as PNG.';
			}
		} elseif ( 'image/webp' === $mime && 'jpeg' === $format ) {
			if ( self::webp_alpha( $file ) ) {
				$p['notes'][] = 'It has transparency: kept as WebP.';
			} else {
				$p['to'] = 'image/jpeg';
			}
		}
		return $p;
	}

	/** PNG colour type and tRNS presence (both precede the image data). */
	private static function png_header( string $file ): array {
		$out = [ 'color' => null, 'trns' => false ];
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return $out;
		}
		$size = (int) filesize( $file );
		$plan = self::png( $fh, $size, [ 'gps' => false, 'xmp' => false, 'iptc' => false ], self::SCAN_BYTES );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out['color'] = $plan[0]['color'] ?? null;
		$out['trns'] = ! empty( $plan[0]['trns'] );
		return $out;
	}

	/** Does a PNG use transparency? tRNS = yes; grey/truecolour without alpha = no; with an alpha channel: sampled. */
	private static function png_alpha( string $file, array $info ): bool {
		if ( $info['trns'] ) {
			return true;
		}
		if ( in_array( $info['color'], [ 0, 2, 3 ], true ) ) {
			return false;
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return true; // Cannot look: assume it is a cut-out.
		}
		$im = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $im ) {
			return true;
		}
		$w = imagesx( $im );
		$h = imagesy( $im );
		$alpha = false;
		$sx = max( 1, intdiv( $w, 96 ) );
		$sy = max( 1, intdiv( $h, 96 ) );
		$probe = static function ( int $x, int $y ) use ( $im ): bool {
			return 0 !== ( ( imagecolorat( $im, $x, $y ) >> 24 ) & 0x7F );
		};
		for ( $y = 0; $y < $h && ! $alpha; $y += $sy ) { // A grid over the image...
			for ( $x = 0; $x < $w; $x += $sx ) {
				if ( $probe( $x, $y ) ) {
					$alpha = true;
					break;
				}
			}
		}
		for ( $x = 0; $x < $w && ! $alpha; $x++ ) { // ...and every pixel of the four edges (cut-outs and rounded corners).
			$alpha = $probe( $x, 0 ) || $probe( $x, $h - 1 );
		}
		for ( $y = 0; $y < $h && ! $alpha; $y++ ) {
			$alpha = $probe( 0, $y ) || $probe( $w - 1, $y );
		}
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $im ); // A no-op since PHP 8 (deprecated in 8.5).
		}
		return $alpha;
	}

	/** WebP transparency flag (VP8X alpha flag, VP8L alpha_is_used bit; lossy VP8 has none). */
	private static function webp_alpha( string $file ): bool {
		$h = (string) @file_get_contents( $file, false, null, 0, 32 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$cc = substr( $h, 12, 4 );
		if ( 'VP8X' === $cc ) {
			return (bool) ( ord( $h[20] ?? "\0" ) & 0x10 );
		}
		if ( 'VP8L' === $cc && strlen( $h ) >= 25 ) {
			return (bool) ( ( unpack( 'V', substr( $h, 21, 4 ) )[1] >> 28 ) & 1 );
		}
		return 'VP8 ' !== $cc;
	}

	/**
	 * Enough memory for the pixels this import decodes? GD keeps about 4 bytes per pixel (5 counted, for overhead) inside
	 * PHP's memory_limit: the decoded image, an upright copy while an EXIF-rotated JPEG is turned, the resized copy and
	 * WebP's encode buffer. Imagick's pixels live outside that limit (ImageMagick enforces its own resource limits), so
	 * there only the GD transparency probe of a PNG with an alpha channel counts. WordPress raises the limit for image
	 * work first.
	 */
	private static function memory_ok( string $file, string $mime, int $w, int $h, int $max, string $format ): bool {
		$px = $w * $h;
		$scale = ( $max > 0 && max( $w, $h ) > $max ) ? $max / max( $w, $h ) : 1.0;
		$out = (int) ceil( $px * $scale * $scale );
		$need = 0;
		$class = function_exists( '_wp_image_editor_choose' ) ? (string) _wp_image_editor_choose( [ 'mime_type' => $mime, 'methods' => [ 'resize', 'save' ] ] ) : '';
		if ( false === stripos( $class, 'imagick' ) ) {
			$rot = 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) && (int) ( @exif_read_data( $file )['Orientation'] ?? 1 ) > 1; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$need = $px * 5 * ( $rot ? 2 : 1 ) + ( $scale < 1 ? $out * 4 : 0 ) + ( 'webp' === $format ? $out * 4 : 0 );
		}
		if ( 'image/png' === $mime && function_exists( 'imagecreatefrompng' ) && in_array( self::png_header( $file )['color'], [ 4, 6 ], true ) ) {
			$need = max( $need, $px * 5 ); // The transparency probe decodes it once with GD.
		}
		if ( ! $need ) {
			return true;
		}
		wp_raise_memory_limit( 'image' );
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit <= 0 || memory_get_usage( true ) + $need < $limit;
	}

	/**
	 * Save with Launch Builder's own quality. A format change makes the editor re-read its default quality from the
	 * wp_editor_set_quality / jpeg_quality filters, so the value is supplied through filters added just around this one
	 * save and removed right after: the site's quality settings stay as they are for everything else.
	 */
	private static function save_as( $editor, string $dest, string $mime, int $quality ) {
		$q = static function () use ( $quality ) {
			return $quality;
		};
		add_filter( 'wp_editor_set_quality', $q, PHP_INT_MAX );
		add_filter( 'jpeg_quality', $q, PHP_INT_MAX );
		try {
			$editor->set_quality( $quality );
			return $editor->save( $dest, $mime );
		} finally {
			remove_filter( 'wp_editor_set_quality', $q, PHP_INT_MAX );
			remove_filter( 'jpeg_quality', $q, PHP_INT_MAX );
		}
	}

	/* ------------------------------------------------------------------ C. environment and neighbours (read only) */

	/** Image editor, what it keeps, formats, big-image threshold. */
	private static function environment(): array {
		$class = function_exists( '_wp_image_editor_choose' ) ? (string) _wp_image_editor_choose( [ 'mime_type' => 'image/jpeg', 'methods' => [ 'resize', 'save' ] ] ) : '';
		$editor = '' === $class ? 'none' : ( false !== stripos( $class, 'imagick' ) ? 'imagick' : ( false !== stripos( $class, 'gd' ) ? 'gd' : 'other' ) );
		$threshold = apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 );
		return [
			'editor'                   => $editor,
			'editor_class'             => $class,
			'keeps_exif_in_sizes'      => 'imagick' === $editor, // WP_Image_Editor_Imagick::strip_meta() keeps the exif, xmp and iptc profiles.
			'webp'                     => wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ),
			'avif'                     => wp_image_editor_supports( [ 'mime_type' => 'image/avif' ] ),
			'heic'                     => wp_image_editor_supports( [ 'mime_type' => 'image/heic' ] ),
			'big_image_size_threshold' => $threshold ? (int) $threshold : 0,
			'exif_extension'           => function_exists( 'exif_read_data' ),
		];
	}

	/** What the neighbours do (read only): SiteGround Optimizer media options, Perfmatters lazy loading, image optimizer leftovers. */
	private static function neighbours(): array {
		global $wpdb;
		$slugs = [];
		foreach ( array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) as $f ) {
			$slugs[] = dirname( (string) $f );
		}
		$sg = [ 'active' => in_array( 'sg-cachepress', $slugs, true ), 'options' => [] ];
		if ( $sg['active'] ) { // Option names are read by pattern, not assumed: whatever media-related switches this version has.
			$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'siteground\_optimizer\_%' ORDER BY option_name LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$n = (string) $row['option_name'];
				if ( preg_match( '/image|webp|avif|lazy|resize|compress|quality|backup|media/', $n ) && ! preg_match( '/key|token|secret|pass/', $n ) && strlen( (string) $row['option_value'] ) <= 64 && ! is_serialized( $row['option_value'] ) ) {
					$sg['options'][ $n ] = $row['option_value'];
				}
			}
		}
		$pm = [ 'active' => in_array( 'perfmatters', $slugs, true ), 'lazyload' => [] ];
		if ( $pm['active'] ) {
			$o = get_option( 'perfmatters_options', [] );
			foreach ( (array) ( is_array( $o ) ? ( $o['lazyload'] ?? [] ) : [] ) as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$pm['lazyload'][ $k ] = $v;
				}
			}
		}
		$basedir = wp_get_upload_dir()['basedir'];
		$ht = $basedir . '/.htaccess';
		$htaccess = is_file( $ht ) && is_readable( $ht ) ? (string) @file_get_contents( $ht, false, null, 0, 65536 ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return [
			'sg_optimizer' => $sg,
			'perfmatters'  => $pm,
			'optimizer'    => [
				'active'            => in_array( 'tiny-compress-images', $slugs, true ),
				'backup_dir_exists' => is_dir( $basedir . '/' . self::OPTIMIZER_BACKUP_DIR ),
				'htaccess_block'    => false !== strpos( $htaccess, 'tiny-compress-images' ),
			],
		];
	}

	/* ------------------------------------------------------------------ D. audit checks (batched by attachment id) */

	/** Image attachment ids after $after, ascending. */
	private static function image_ids( int $after, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d", 'image/%', $after, $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** One header scan per audit page (at most 256 KB read per file), shared by the six checks. */
	private static function batch( array $args ): array {
		$after = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$key = $after . ':' . $limit;
		if ( ! isset( self::$scan[ $key ] ) ) {
			$ids = self::image_ids( $after, $limit );
			$rows = [];
			foreach ( $ids as $id ) {
				$meta = wp_get_attachment_metadata( $id );
				$meta = is_array( $meta ) ? $meta : [];
				$files = [];
				foreach ( self::files_of( $id, $meta ) as $f ) {
					$files[] = $f + self::process_file( $f['abs'], [], true, self::SCAN_BYTES );
				}
				$rows[ $id ] = [ 'meta' => $meta, 'files' => $files ];
			}
			self::$scan = [ $key => [ 'rows' => $rows, 'next' => count( $ids ) === $limit ? (int) end( $ids ) : null ] ];
		}
		return self::$scan[ $key ];
	}

	/** Run $fn( $id, $row ) over the page's attachments (nothing while the module is off). */
	private static function each( array $args, callable $fn ): array {
		if ( ! self::enabled() ) {
			return [ 'findings' => [], 'next_after_id' => null ];
		}
		$b = self::batch( $args );
		$out = [];
		foreach ( $b['rows'] as $id => $row ) {
			foreach ( (array) $fn( (int) $id, $row ) as $f ) {
				$out[] = $f;
			}
		}
		return [ 'findings' => $out, 'next_after_id' => $b['next'] ];
	}

	private static function finding( string $severity, string $code, int $id, string $message, string $fix ): array {
		return [ 'severity' => $severity, 'code' => $code, 'object_type' => 'attachment', 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ), 'message' => $message, 'fix' => $fix ];
	}

	private static function names( array $files ): string {
		$names = array_map( static function ( $f ) { return $f['file']; }, array_slice( $files, 0, 5 ) );
		return implode( ', ', $names ) . ( count( $files ) > 5 ? ', ...' : '' );
	}

	/** media_gps: JPEG/PNG/WebP files of the image that still hold GPS (EXIF or XMP). */
	public static function audit_gps( array $args ): array {
		return self::each( $args, static function ( int $id, array $row ) {
			$hits = array_values( array_filter( $row['files'], static function ( $f ) {
				return in_array( $f['type'], [ 'jpeg', 'png', 'webp' ], true ) && ( $f['gps'] || $f['xmp_gps'] );
			} ) );
			if ( ! $hits ) {
				return [];
			}
			/* translators: 1: number of files, 2: file names */
			$msg = sprintf( __( '%1$d file(s) of this image still hold GPS location: %2$s', 'launch-builder' ), count( $hits ), self::names( $hits ) );
			/* translators: %d: attachment id */
			return [ self::finding( 'warning', 'media_gps', $id, $msg, sprintf( __( 'Run strip-media-location with ids [%d] and dry_run false.', 'launch-builder' ), $id ) ) ];
		} );
	}

	/** media_heavy: the full size WordPress serves is over 2560 px or over audit_max_kb. */
	public static function audit_heavy( array $args ): array {
		$kb = self::settings()['audit_max_kb'];
		return self::each( $args, static function ( int $id, array $row ) use ( $kb ) {
			$full = array_values( array_filter( $row['files'], static function ( $f ) { return 'full' === $f['role']; } ) );
			if ( ! $full || isset( $full[0]['error'] ) ) {
				return [];
			}
			$w = (int) ( $row['meta']['width'] ?? 0 );
			$h = (int) ( $row['meta']['height'] ?? 0 );
			$size = (int) $full[0]['bytes'];
			if ( max( $w, $h ) <= self::HEAVY_PX && $size <= $kb * 1024 ) {
				return [];
			}
			/* translators: 1: file, 2: width, 3: height, 4: size in KB */
			$msg = sprintf( __( 'Full size %1$s is %2$d x %3$d px, %4$d KB.', 'launch-builder' ), $full[0]['file'], $w, $h, (int) round( $size / 1024 ) );
			return [ self::finding( 'warning', 'media_heavy', $id, $msg, __( 'Replace it with a version of at most 2560 px (2048 px is plenty for a full-width hero) at quality 82; sideload-media does that for imported images.', 'launch-builder' ) ) ];
		} );
	}

	/** media_png_photo: a full-size PNG without transparency over 300 KB (a photo saved as PNG). */
	public static function audit_png_photo( array $args ): array {
		return self::each( $args, static function ( int $id, array $row ) {
			foreach ( $row['files'] as $f ) {
				if ( 'full' === $f['role'] && 'png' === $f['type'] && in_array( $f['color'] ?? null, [ 0, 2 ], true ) && empty( $f['trns'] ) && $f['bytes'] > self::PNG_PHOTO_KB * 1024 ) {
					/* translators: 1: file, 2: size in KB */
					$msg = sprintf( __( '%1$s is a %2$d KB PNG without transparency: a photo saved as PNG. Social cards and og:image use this file as it is.', 'launch-builder' ), $f['file'], (int) round( $f['bytes'] / 1024 ) );
					return [ self::finding( 'warning', 'media_png_photo', $id, $msg, __( 'Re-import it with sideload-media (format auto or jpeg) and swap it in, or upload a JPEG.', 'launch-builder' ) ) ];
				}
			}
			return [];
		} );
	}

	/** media_alt_missing: an image without alternative text. */
	public static function audit_alt( array $args ): array {
		return self::each( $args, static function ( int $id ) {
			if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				return [];
			}
			return [ self::finding( 'info', 'media_alt_missing', $id, __( 'This image has no alternative text.', 'launch-builder' ), __( 'Describe the image in its Alternative Text (sideload-media alt, or the media library). Purely decorative images can stay empty.', 'launch-builder' ) ) ];
		} );
	}

	/** media_heic_original: a HEIC/HEIF/AVIF file of the image (kept original or source) holding GPS (or unreadable). */
	public static function audit_heic( array $args ): array {
		return self::each( $args, static function ( int $id, array $row ) {
			$out = [];
			foreach ( $row['files'] as $f ) {
				if ( ! in_array( $f['type'], [ 'heif', 'avif' ], true ) || false === $f['gps'] ) {
					continue;
				}
				$known = true === $f['gps'];
				/* translators: %s: file */
				$msg = sprintf( $known ? __( '%s holds GPS location and cannot be cleaned without re-encoding.', 'launch-builder' ) : __( '%s may hold GPS location (its metadata could not be read).', 'launch-builder' ), $f['file'] );
				$out[] = self::finding( $known ? 'warning' : 'info', 'media_heic_original', $id, $msg, __( 'Upload a JPEG instead (export from the phone with location off), or delete the original file once the JPEG version is in use.', 'launch-builder' ) );
			}
			return $out;
		} );
	}

	/** media_public_backups: public backup copies of originals (an image optimizer's backup folder in uploads, SiteGround-style .bak files). */
	public static function audit_backups( array $args ): array {
		$res = self::each( $args, static function ( int $id, array $row ) {
			$base = self::base();
			$found = [];
			foreach ( $row['files'] as $f ) {
				if ( ! in_array( $f['role'], [ 'full', 'original' ], true ) || '' === $f['file'] ) {
					continue;
				}
				$ext = pathinfo( $f['file'], PATHINFO_EXTENSION );
				foreach ( [ $base . '/' . self::OPTIMIZER_BACKUP_DIR . '/' . $f['file'], $base . '/' . substr( $f['file'], 0, -strlen( $ext ) ) . 'bak.' . $ext ] as $copy ) {
					if ( '' !== $ext && is_file( $copy ) ) {
						$scan = self::process_file( $copy, [], true, self::SCAN_BYTES );
						$found[] = $scan['file'] . ( $scan['gps'] || $scan['xmp_gps'] ? ' (GPS)' : '' );
					}
				}
			}
			if ( ! $found ) {
				return [];
			}
			/* translators: %s: file names */
			$msg = sprintf( __( 'Public backup copies of this image: %s', 'launch-builder' ), implode( ', ', $found ) );
			return [ self::finding( 'warning', 'media_public_backups', $id, $msg, __( 'Delete the copies once the optimizer that made them is gone, or strip them (strip-media-location scope optimizer_backups).', 'launch-builder' ) ) ];
		} );
		if ( self::enabled() && 0 === (int) ( $args['after_id'] ?? 0 ) && is_dir( self::base() . '/' . self::OPTIMIZER_BACKUP_DIR ) ) {
			array_unshift( $res['findings'], [ 'severity' => 'warning', 'code' => 'media_public_backups', 'object_type' => 'site', 'id' => 0, 'url' => '', 'message' => __( 'The backup folder an image optimizer keeps in uploads holds public, predictable copies of original images, with their GPS location.', 'launch-builder' ), 'fix' => __( 'Strip them with strip-media-location scope optimizer_backups, or delete the folder once the optimizer is removed.', 'launch-builder' ) ] );
		}
		return $res;
	}
}
