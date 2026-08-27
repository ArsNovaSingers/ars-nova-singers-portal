<?php
/**
 * Bulk download: hand a singer their selected materials as one .zip.
 *
 * WHY THIS IS SERVER-SIDE. Materials live in Google Drive and are shared with
 * this site's service account — not necessarily with each singer's own Google
 * identity. A download link pointing straight at Drive therefore works for
 * some people and not others, and when it fails it looks like a broken link
 * rather than a permissions problem. Every download here is fetched with the
 * service-account token and streamed back through WordPress instead, so what
 * a singer can SEE in the portal is exactly what they can DOWNLOAD.
 *
 * Two entry points, both on admin-post.php, both logged-in only. No _nopriv
 * handler is registered, so a logged-out request reaches nothing at all:
 *
 *   ansp_download_materials   POST   many materials  -> one .zip
 *   ansp_material_download    GET    one material    -> streamed as itself
 *
 * The submitted id list is NEVER trusted. Both handlers re-derive the caller's
 * visible set with ANSP_Permissions::get_visible_materials() and intersect, so
 * a hand-crafted request cannot reach another group's music.
 *
 * Bytes never sit in PHP memory. Each file is streamed to a temp file by the
 * HTTP API ('stream' => true), moved into a staging directory under its final
 * name, and added to the archive from disk. Peak memory is one response's
 * headers, not the size of the archive.
 *
 * BOUNDARY: this covers Google Drive items and files hosted on this site.
 * It does not, and cannot, package YouTube/Vimeo links or arbitrary third-party
 * URLs — those have no file to fetch. is_zippable() is the single answer to
 * "can this go in a zip", and the template asks it before drawing a checkbox.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Materials_Zip
 */
class ANSP_Materials_Zip {

	/**
	 * admin-post action for the multi-file zip (POST).
	 */
	const ACTION_ZIP = 'ansp_download_materials';

	/**
	 * admin-post action for a single-file download (GET).
	 */
	const ACTION_SINGLE = 'ansp_material_download';

	/**
	 * Drive scope needed to read a shared file.
	 */
	const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

	/**
	 * Hook both handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION_ZIP, array( $this, 'handle_zip' ) );
		add_action( 'admin_post_' . self::ACTION_SINGLE, array( $this, 'handle_single' ) );
	}

	/* ---------------------------------------------------------------------
	 * Limits
	 * ------------------------------------------------------------------ */

	/**
	 * Most materials allowed in one archive.
	 *
	 * @return int
	 */
	public static function max_files() {
		return (int) apply_filters( 'ansp_zip_max_files', 40 );
	}

	/**
	 * Ceiling on any single file, in bytes.
	 *
	 * @return int
	 */
	public static function max_file_bytes() {
		return (int) apply_filters( 'ansp_zip_max_file_bytes', 100 * 1024 * 1024 );
	}

	/**
	 * Ceiling on one archive's total fetched bytes.
	 *
	 * @return int
	 */
	public static function max_total_bytes() {
		return (int) apply_filters( 'ansp_zip_max_total_bytes', 300 * 1024 * 1024 );
	}

	/* ---------------------------------------------------------------------
	 * Source resolution — the one definition of "can this be downloaded"
	 * ------------------------------------------------------------------ */

	/**
	 * Work out what, if anything, we can fetch for a material URL.
	 *
	 * Deliberately broader than ANSP_Materials::drive_file_id(), which only
	 * recognises drive.google.com. Tom's rehearsal docs are Google Docs on
	 * docs.google.com and they matter as much as the scores do.
	 *
	 * @param string $url Stored material URL.
	 * @return array|null array('kind'=>'drive','id'=>..) | array('kind'=>'url','url'=>..) | null.
	 */
	public static function resolve_source( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}

		// Google editors (Docs / Sheets / Slides). Whether the item is a
		// native doc or an uploaded file is decided later, by its mimeType.
		if ( preg_match( '#^https?://docs\.google\.com/(?:document|spreadsheets|presentation|drawings)/d/([a-zA-Z0-9_-]+)#', $url, $m ) ) {
			return array( 'kind' => 'drive', 'id' => $m[1] );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// Drive share links, in all three shapes Tom's files actually use.
		if ( 'drive.google.com' === $host || 'drive.usercontent.google.com' === $host ) {
			if ( preg_match( '#/file/d/([a-zA-Z0-9_-]+)#', $url, $m ) ) {
				return array( 'kind' => 'drive', 'id' => $m[1] );
			}
			if ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m ) ) {
				return array( 'kind' => 'drive', 'id' => $m[1] );
			}
			return null; // A Drive FOLDER link, or something we cannot name.
		}

		// A file hosted on this site (e.g. a media-library PDF).
		$self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $host === $self && preg_match( '#\.[a-z0-9]{2,5}$#i', $path ) ) {
			return array( 'kind' => 'url', 'url' => $url );
		}

		/**
		 * Not a shape this class knows. Ask, rather than deciding alone.
		 *
		 * Sheet music published to the device-sync mirror is a file in every
		 * sense and lives on neither Drive nor this host, so without this hook it
		 * was classed as a link and singers were shown a dash reading "this is a
		 * link, not a file" over their own scores.
		 *
		 * @param array|null $source array('kind'=>'drive'|'url', ...) or null.
		 * @param string     $url    The material URL that was not recognised.
		 */
		return apply_filters( 'ansp_zip_source', null, $url );
	}

	/**
	 * Can this material be put in an archive at all?
	 *
	 * Used by templates/material-item.php to decide whether to draw a
	 * checkbox, so a singer is never offered a selection that would quietly
	 * fail to arrive.
	 *
	 * @param string $url Stored material URL.
	 * @return bool
	 */
	public static function is_zippable( $url ) {
		return null !== self::resolve_source( $url );
	}

	/* ---------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch one material to a temp file.
	 *
	 * @param array  $material Material row.
	 * @return array|WP_Error array('path'=>string,'name'=>string,'mime'=>string).
	 */
	public static function fetch_material( $material ) {
		$url = isset( $material['url'] ) ? (string) $material['url'] : '';
		$src = self::resolve_source( $url );

		if ( null === $src ) {
			return new WP_Error(
				'ansp_zip_not_a_file',
				__( 'This is a link, not a file we can download.', 'ans-singers-portal' )
			);
		}

		$fallback = isset( $material['title'] ) ? (string) $material['title'] : 'material';

		if ( 'drive' === $src['kind'] ) {
			return self::fetch_drive( $src['id'], $fallback );
		}
		return self::fetch_url( $src['url'], $fallback );
	}

	/**
	 * Fetch a Drive item using the Google Connector's service-account token.
	 *
	 * Google-native documents have no raw bytes, so they are EXPORTED (to PDF)
	 * rather than skipped — a rehearsal doc is usually the single most useful
	 * thing in the project and dropping it would make the feature pointless.
	 *
	 * @param string $file_id  Drive file id.
	 * @param string $fallback Name to use when Drive gives us none.
	 * @return array|WP_Error
	 */
	protected static function fetch_drive( $file_id, $fallback ) {
		if ( ! function_exists( 'ansg_get_access_token' ) ) {
			return new WP_Error(
				'ansp_zip_no_google',
				__( 'The Ars Nova Google Connector is not active, so Drive files cannot be fetched.', 'ans-singers-portal' )
			);
		}

		$token = ansg_get_access_token( self::DRIVE_SCOPE );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$headers = array( 'Authorization' => 'Bearer ' . $token );
		$base    = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id );

		// 1. Metadata: what is it, what is it called, how big is it.
		$meta_resp = wp_remote_get(
			$base . '?fields=' . rawurlencode( 'name,mimeType,size' ) . '&supportsAllDrives=true',
			array( 'timeout' => 25, 'headers' => $headers )
		);
		if ( is_wp_error( $meta_resp ) ) {
			return $meta_resp;
		}
		$meta_code = (int) wp_remote_retrieve_response_code( $meta_resp );
		$meta      = json_decode( wp_remote_retrieve_body( $meta_resp ), true );
		if ( 200 !== $meta_code || ! is_array( $meta ) ) {
			$why = ( is_array( $meta ) && isset( $meta['error']['message'] ) )
				? $meta['error']['message']
				: 'HTTP ' . $meta_code;
			return new WP_Error(
				'ansp_zip_drive_meta',
				sprintf(
					/* translators: %s: the reason Google gave. */
					__( 'Drive would not describe this file (%s). It probably is not shared with the service account.', 'ans-singers-portal' ),
					$why
				)
			);
		}

		$mime = isset( $meta['mimeType'] ) ? (string) $meta['mimeType'] : '';
		$name = ( isset( $meta['name'] ) && '' !== $meta['name'] ) ? (string) $meta['name'] : $fallback;

		if ( 0 === strpos( $mime, 'application/vnd.google-apps' ) ) {
			$exports = array(
				'application/vnd.google-apps.document'     => 'application/pdf',
				'application/vnd.google-apps.spreadsheet'  => 'application/pdf',
				'application/vnd.google-apps.presentation' => 'application/pdf',
				'application/vnd.google-apps.drawing'      => 'application/pdf',
			);
			if ( ! isset( $exports[ $mime ] ) ) {
				return new WP_Error(
					'ansp_zip_drive_native',
					__( 'This Google item has no downloadable form.', 'ans-singers-portal' )
				);
			}
			$fetch_url = $base . '/export?mimeType=' . rawurlencode( $exports[ $mime ] );
			$name      = self::force_extension( $name, 'pdf' );
			$mime      = $exports[ $mime ];
		} else {
			if ( isset( $meta['size'] ) && (int) $meta['size'] > self::max_file_bytes() ) {
				return new WP_Error(
					'ansp_zip_too_big',
					sprintf(
						/* translators: %s: human-readable size limit. */
						__( 'Larger than the %s per-file limit.', 'ans-singers-portal' ),
						size_format( self::max_file_bytes() )
					)
				);
			}
			$fetch_url = $base . '?alt=media&supportsAllDrives=true';
			$name      = self::ensure_extension( $name, $mime );
		}

		return self::stream_to_temp( $fetch_url, $headers, $name, $mime );
	}

	/**
	 * Fetch a file hosted on this site.
	 *
	 * @param string $url      Absolute URL on this host.
	 * @param string $fallback Name to use when the path gives us none.
	 * @return array|WP_Error
	 */
	protected static function fetch_url( $url, $fallback ) {
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$type = wp_check_filetype( $name );
		$mime = ! empty( $type['type'] ) ? (string) $type['type'] : 'application/octet-stream';

		return self::stream_to_temp( $url, array(), $name, $mime );
	}

	/**
	 * Stream a URL straight to a temp file. Nothing is buffered in memory.
	 *
	 * @param string $url     URL to fetch.
	 * @param array  $headers Request headers.
	 * @param string $name    Filename to record for this item.
	 * @param string $mime    MIME type to record for this item.
	 * @return array|WP_Error
	 */
	protected static function stream_to_temp( $url, $headers, $name, $mime ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( 'ansp-material' );
		if ( ! $tmp ) {
			return new WP_Error( 'ansp_zip_no_temp', __( 'Could not create a temporary file on the server.', 'ans-singers-portal' ) );
		}

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $tmp,
			)
		);

		if ( is_wp_error( $resp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'ansp_zip_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The file could not be downloaded (HTTP %d).', 'ans-singers-portal' ),
					$code
				)
			);
		}

		return array(
			'path' => $tmp,
			'name' => sanitize_file_name( $name ),
			'mime' => $mime,
		);
	}

	/* ---------------------------------------------------------------------
	 * Filenames
	 * ------------------------------------------------------------------ */

	/**
	 * Replace whatever extension a name has with this one.
	 *
	 * @param string $name Filename.
	 * @param string $ext  Extension without the dot.
	 * @return string
	 */
	protected static function force_extension( $name, $ext ) {
		$stem = preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', (string) $name );
		if ( '' === $stem ) {
			$stem = 'file';
		}
		return $stem . '.' . $ext;
	}

	/**
	 * Give a name an extension if it has none, inferred from its MIME type.
	 *
	 * @param string $name Filename.
	 * @param string $mime MIME type.
	 * @return string
	 */
	protected static function ensure_extension( $name, $mime ) {
		if ( preg_match( '/\.[A-Za-z0-9]{1,5}$/', (string) $name ) ) {
			return $name;
		}
		foreach ( wp_get_mime_types() as $exts => $type ) {
			if ( $type === $mime ) {
				$first = explode( '|', $exts );
				return $name . '.' . $first[0];
			}
		}
		return $name;
	}

	/**
	 * Make a name unique within an archive: "score.pdf" then "score (2).pdf".
	 *
	 * @param string $name Desired filename.
	 * @param array  $used Names already taken (lowercased keys, by reference).
	 * @return string
	 */
	protected static function unique_name( $name, &$used ) {
		$key = strtolower( $name );
		if ( ! isset( $used[ $key ] ) ) {
			$used[ $key ] = true;
			return $name;
		}

		$ext  = '';
		$stem = $name;
		if ( preg_match( '/^(.*)(\.[A-Za-z0-9]{1,5})$/', $name, $m ) ) {
			$stem = $m[1];
			$ext  = $m[2];
		}
		$n = 2;
		do {
			$try = $stem . ' (' . $n . ')' . $ext;
			$key = strtolower( $try );
			$n++;
		} while ( isset( $used[ $key ] ) && $n < 500 );

		$used[ $key ] = true;
		return $try;
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Shared front door: verify the caller and return their visible materials
	 * for a project, keyed by material id.
	 *
	 * @param int $project_id Project post ID.
	 * @return array<string,array>|WP_Error
	 */
	protected static function visible_by_id( $project_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'ansp_zip_auth', __( 'Please sign in to download materials.', 'ans-singers-portal' ) );
		}

		$project = get_post( $project_id );
		if ( ! $project instanceof WP_Post || ANSP_CPT::POST_TYPE !== $project->post_type ) {
			return new WP_Error( 'ansp_zip_no_project', __( 'That project does not exist.', 'ans-singers-portal' ) );
		}
		if ( ! ANSP_Permissions::user_can_see( $project, $user_id ) ) {
			return new WP_Error( 'ansp_zip_denied', __( 'You do not have access to that project.', 'ans-singers-portal' ) );
		}

		$out = array();
		foreach ( ANSP_Permissions::get_visible_materials( $project_id, $user_id ) as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id ) {
				$out[ $id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * POST handler: build and stream a zip of the selected materials.
	 *
	 * @return void
	 */
	public function handle_zip() {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'ansp_zip_' . $project_id, 'ansp_zip_nonce' );

		$visible = self::visible_by_id( $project_id );
		if ( is_wp_error( $visible ) ) {
			self::bail( $visible->get_error_message() );
		}

		$requested = isset( $_POST['material_ids'] ) ? (array) wp_unslash( $_POST['material_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on the next line.
		$requested = array_map( 'sanitize_key', $requested );

		// The intersection is the whole security model: whatever was posted,
		// only ids the caller can actually see survive.
		$chosen = array();
		foreach ( $requested as $id ) {
			if ( isset( $visible[ $id ] ) ) {
				$chosen[] = $visible[ $id ];
			}
		}

		if ( empty( $chosen ) ) {
			self::bail( __( 'Nothing was selected to download.', 'ans-singers-portal' ) );
		}
		if ( count( $chosen ) > self::max_files() ) {
			self::bail(
				sprintf(
					/* translators: %d: maximum number of files. */
					__( 'Please select %d materials or fewer at a time.', 'ans-singers-portal' ),
					self::max_files()
				)
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$stage = self::make_stage_dir();
		if ( is_wp_error( $stage ) ) {
			self::bail( $stage->get_error_message() );
		}

		$used    = array();
		$added   = 0;
		$total   = 0;
		$skipped = array();

		foreach ( $chosen as $material ) {
			$title = isset( $material['title'] ) ? (string) $material['title'] : '(untitled)';

			$got = self::fetch_material( $material );
			if ( is_wp_error( $got ) ) {
				$skipped[] = $title . ' — ' . $got->get_error_message();
				continue;
			}

			$size = (int) @filesize( $got['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $total + $size > self::max_total_bytes() ) {
				@unlink( $got['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$skipped[] = $title . ' — ' . __( 'would push the archive over its size limit', 'ans-singers-portal' );
				continue;
			}

			$name = self::unique_name( $got['name'], $used );
			if ( @rename( $got['path'], trailingslashit( $stage ) . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$added++;
				$total += $size;
			} else {
				@unlink( $got['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$skipped[] = $title . ' — ' . __( 'could not be staged on the server', 'ans-singers-portal' );
			}
		}

		if ( ! $added ) {
			self::rmdir_recursive( $stage );
			self::bail(
				__( 'None of the selected materials could be downloaded.', 'ans-singers-portal' )
				. ( $skipped ? ' ' . implode( '; ', $skipped ) : '' )
			);
		}

		// Anything that did not make it is named INSIDE the archive. A silent
		// omission is the one outcome worth ruling out: a singer who thinks
		// they have all their music and does not is worse off than one who
		// can see exactly what is missing.
		if ( $skipped ) {
			$notice  = __( 'These materials could not be included:', 'ans-singers-portal' ) . "\r\n\r\n";
			$notice .= '- ' . implode( "\r\n- ", $skipped ) . "\r\n\r\n";
			$notice .= __( 'Open them from the portal instead, or tell the office.', 'ans-singers-portal' ) . "\r\n";
			file_put_contents( trailingslashit( $stage ) . 'NOT-INCLUDED.txt', $notice ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$project  = get_post( $project_id );
		$base     = sanitize_file_name( html_entity_decode( get_the_title( $project ), ENT_QUOTES, 'UTF-8' ) );
		$zip_name = ( '' !== $base ? $base : 'materials' ) . '-materials.zip';

		$archive = self::build_archive( $stage );
		self::rmdir_recursive( $stage );

		if ( is_wp_error( $archive ) ) {
			self::bail( $archive->get_error_message() );
		}

		self::stream_file_out( $archive, $zip_name, 'application/zip', true );
	}

	/**
	 * GET handler: stream ONE material back as itself.
	 *
	 * Same fetch path as the zip, so if a singer's single download works the
	 * bulk download works, and if it fails both fail the same way.
	 *
	 * @return void
	 */
	public function handle_single() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'ansp_material_' . $project_id );

		$visible = self::visible_by_id( $project_id );
		if ( is_wp_error( $visible ) ) {
			self::bail( $visible->get_error_message() );
		}

		$id = isset( $_GET['material_id'] ) ? sanitize_key( wp_unslash( $_GET['material_id'] ) ) : '';
		if ( '' === $id || ! isset( $visible[ $id ] ) ) {
			self::bail( __( 'That material is not available to you.', 'ans-singers-portal' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$got = self::fetch_material( $visible[ $id ] );
		if ( is_wp_error( $got ) ) {
			self::bail( $got->get_error_message() );
		}

		self::stream_file_out( $got['path'], $got['name'], $got['mime'], true );
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * Create a private staging directory under the system temp dir.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	protected static function make_stage_dir() {
		$dir = trailingslashit( get_temp_dir() ) . 'ansp-zip-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ansp_zip_no_stage', __( 'Could not create a temporary folder on the server.', 'ans-singers-portal' ) );
		}
		return $dir;
	}

	/**
	 * Zip a staging directory.
	 *
	 * ZipArchive is used when the PHP extension is present; WordPress's own
	 * bundled PclZip is the fallback, so a host without the extension cannot
	 * turn this feature off.
	 *
	 * @param string $stage Directory whose contents become the archive.
	 * @return string|WP_Error Path to the archive.
	 */
	protected static function build_archive( $stage ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$archive = wp_tempnam( 'ansp-archive' );
		if ( ! $archive ) {
			return new WP_Error( 'ansp_zip_no_temp', __( 'Could not create the archive file.', 'ans-singers-portal' ) );
		}

		$entries = array_values( array_diff( (array) scandir( $stage ), array( '.', '..' ) ) );
		if ( empty( $entries ) ) {
			return new WP_Error( 'ansp_zip_empty', __( 'There was nothing to put in the archive.', 'ans-singers-portal' ) );
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			// OVERWRITE because wp_tempnam() has already created the file.
			if ( true !== $zip->open( $archive, ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'ansp_zip_open', __( 'Could not open the archive for writing.', 'ans-singers-portal' ) );
			}
			foreach ( $entries as $entry ) {
				$zip->addFile( trailingslashit( $stage ) . $entry, $entry );
			}
			$zip->close();
			return $archive;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$pcl  = new PclZip( $archive );
		$list = array();
		foreach ( $entries as $entry ) {
			$list[] = trailingslashit( $stage ) . $entry;
		}
		$result = $pcl->create( $list, PCLZIP_OPT_REMOVE_ALL_PATH );
		if ( 0 === $result ) {
			return new WP_Error( 'ansp_zip_pclzip', __( 'Could not build the archive.', 'ans-singers-portal' ) );
		}
		return $archive;
	}

	/**
	 * Send a file to the browser and stop.
	 *
	 * @param string $path     Absolute path to the file.
	 * @param string $filename Name the browser should save it as.
	 * @param string $mime     Content type.
	 * @param bool   $delete   Delete $path after sending.
	 * @return void
	 */
	protected static function stream_file_out( $path, $filename, $mime, $delete = false ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . $size );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		if ( $delete ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		exit;
	}

	/**
	 * Remove a staging directory and everything in it.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	protected static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = trailingslashit( $dir ) . $entry;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Stop with a readable message and a way back to the portal.
	 *
	 * @param string $message Human-facing explanation.
	 * @return void
	 */
	protected static function bail( $message ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Download', 'ans-singers-portal' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * URL helpers for templates
	 * ------------------------------------------------------------------ */

	/**
	 * Nonced URL that downloads one material through this site.
	 *
	 * @param int    $project_id  Project post ID.
	 * @param string $material_id Material row id.
	 * @return string
	 */
	public static function single_url( $project_id, $material_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION_SINGLE,
					'project_id'  => (int) $project_id,
					'material_id' => rawurlencode( $material_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'ansp_material_' . (int) $project_id
		);
	}
}
