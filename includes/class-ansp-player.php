<?php
/**
 * Play a rehearsal recording on the page.
 *
 * Opening a track sent a singer to a new tab. With nineteen movements on one
 * project that is nineteen round trips away from the page they are reading.
 *
 * This serves the same bytes as the Download button, with one header changed:
 * `inline` instead of `attachment`, so an <audio> element will play it rather
 * than the browser saving it. Nothing else differs, and deliberately so - the
 * permission decision, the Drive fetch and the failure modes are all the ones
 * that already work.
 *
 * Note 1.14.0 removed inline previews on purpose. That was about IFRAMES 460 to
 * 940 pixels tall turning twelve materials into several screens; a native audio
 * control is about forty pixels and `preload="none"` means nothing is fetched
 * until someone actually presses play. The reason for that removal does not
 * apply here.
 *
 * RANGE, AND WHY THE FIRST VERSION OF THIS WAS WRONG. v1.18.0 shipped without
 * `Accept-Ranges`, reasoning that fetch_material() re-downloads the whole file
 * from Drive per request so honouring Range would re-fetch sixteen megabytes
 * per seek. The stated cost was "a scrub bar limited for the first few
 * seconds". That was wrong twice over: without Range a browser cannot seek AT
 * ALL, and when its buffer runs out it has no way to ask for more, so playback
 * simply stops mid-track. Jonathan reported exactly those two symptoms.
 *
 * The mistake was optimising against a cost instead of removing it. The file is
 * now cached on local disk on first play, so ranges are served from disk, seeks
 * are instant, playback never stalls, and a second listen never touches Drive.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline playback for audio materials.
 */
class ANSP_Player {

	/** admin-post action name. */
	const ACTION = 'ansp_material_play';

	/**
	 * Material types that get a player.
	 *
	 * Type rather than file extension, because the type is what the admin chose
	 * and the URL is often a Drive link with no extension at all.
	 */
	const PLAYABLE_TYPES = array( 'recording' );

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Should this row get a player?
	 *
	 * A row with no id cannot be addressed by the endpoint, and a row whose URL
	 * is a web link rather than a file has no bytes to stream - is_zippable() is
	 * already the single answer to that second question, so it is asked here
	 * rather than answered a second way.
	 *
	 * @param array $material Material row.
	 * @return bool
	 */
	public static function is_playable( $material ) {
		if ( ! is_array( $material ) ) {
			return false;
		}
		$type = isset( $material['type'] ) ? (string) $material['type'] : '';
		if ( ! in_array( $type, self::PLAYABLE_TYPES, true ) ) {
			return false;
		}
		$id  = isset( $material['id'] ) ? (string) $material['id'] : '';
		$url = isset( $material['url'] ) ? (string) $material['url'] : '';
		if ( '' === $id || '' === $url ) {
			return false;
		}
		return class_exists( 'ANSP_Materials_Zip' ) && ANSP_Materials_Zip::is_zippable( $url );
	}

	/**
	 * Nonced URL that streams one material for playback.
	 *
	 * Shares the download nonce action deliberately: it is the same permission
	 * question about the same project, and a second nonce would be a second
	 * thing to get wrong.
	 *
	 * @param int    $project_id  Project post ID.
	 * @param string $material_id Material row id.
	 * @return string
	 */
	public static function play_url( $project_id, $material_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION,
					'project_id'  => (int) $project_id,
					'material_id' => rawurlencode( $material_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'ansp_material_' . (int) $project_id
		);
	}

	/**
	 * Stream one material for playback.
	 *
	 * Permission is asked of ANSP_Permissions rather than re-derived here, so
	 * this endpoint can only ever serve what the page would already have shown
	 * the same person. A submitted id is never trusted: the visible set is built
	 * first and the id is looked up inside it.
	 */
	public static function handle() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'ansp_material_' . $project_id );

		$id = isset( $_GET['material_id'] ) ? sanitize_key( wp_unslash( $_GET['material_id'] ) ) : '';
		if ( ! $project_id || '' === $id ) {
			self::bail( __( 'That playback link is incomplete.', 'ans-singers-portal' ), 400 );
		}

		$row = null;
		foreach ( (array) ANSP_Permissions::get_visible_materials( $project_id ) as $candidate ) {
			if ( isset( $candidate['id'] ) && (string) $candidate['id'] === $id ) {
				$row = $candidate;
				break;
			}
		}
		if ( null === $row ) {
			self::bail( __( 'That recording is not available to you.', 'ans-singers-portal' ), 403 );
		}
		if ( ! self::is_playable( $row ) ) {
			self::bail( __( 'That material is not a recording that can be played here.', 'ans-singers-portal' ), 415 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$cached = self::ensure_cached( $row );
		if ( is_wp_error( $cached ) ) {
			self::bail( $cached->get_error_message(), 502 );
		}

		$name = isset( $row['title'] ) ? (string) $row['title'] : 'recording';
		self::stream_range( $cached, $name, 'audio/mpeg' );
	}

	/**
	 * Where cached audio lives.
	 *
	 * The system temp directory, NOT the uploads folder. Uploads are served
	 * directly by the web server, so a cached recording sitting there would be
	 * reachable by anyone who guessed the URL - which would quietly undo the
	 * permission check this endpoint exists to make.
	 *
	 * @return string Path with a trailing slash, or '' if it cannot be made.
	 */
	protected static function cache_dir() {
		$dir = trailingslashit( get_temp_dir() ) . 'ansp-audio';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		return trailingslashit( $dir );
	}

	/**
	 * The cache filename for one material.
	 *
	 * Keyed on the row id AND its URL, so pointing a row at a different file
	 * produces a different cache entry rather than serving the old audio for
	 * ever. Salted so the name cannot be derived from public information.
	 *
	 * @param array $row Material row.
	 * @return string '' when the cache is unavailable.
	 */
	protected static function cached_path( $row ) {
		$dir = self::cache_dir();
		if ( '' === $dir ) {
			return '';
		}
		$id  = isset( $row['id'] ) ? (string) $row['id'] : '';
		$url = isset( $row['url'] ) ? (string) $row['url'] : '';
		return $dir . sha1( wp_salt( 'nonce' ) . '|' . $id . '|' . $url ) . '.audio';
	}

	/**
	 * Make sure the bytes are on local disk, fetching from Drive only once.
	 *
	 * This is the whole fix. The first version of this endpoint re-downloaded
	 * the file from Drive on every request, which is why it could not honour a
	 * Range request - and without Range a browser cannot seek at all, and stops
	 * dead when its buffer runs out. Caching removes the cost that made Range
	 * look expensive, so both problems go together.
	 *
	 * @param array $row Material row.
	 * @return string|WP_Error Absolute path to the cached file.
	 */
	protected static function ensure_cached( $row ) {
		$path = self::cached_path( $row );

		if ( '' !== $path && is_readable( $path ) && filesize( $path ) > 0 ) {
			return $path;
		}

		$got = ANSP_Materials_Zip::fetch_material( $row );
		if ( is_wp_error( $got ) ) {
			return $got;
		}

		// No cache available: serve the fetched copy directly this once rather
		// than failing. A player that works without a cache is worth more than
		// a tidy invariant.
		if ( '' === $path ) {
			return $got['path'];
		}

		if ( ! @rename( $got['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $got['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $got['path'];
			}
			@unlink( $got['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::sweep_cache();
		return $path;
	}

	/**
	 * Drop cached audio nobody has played for a month.
	 *
	 * Runs after a cache write, which is rare, rather than on a schedule - a
	 * cache that grows without limit is a disk-full incident waiting for a
	 * concert week.
	 *
	 * @return void
	 */
	protected static function sweep_cache() {
		$dir = self::cache_dir();
		if ( '' === $dir ) {
			return;
		}
		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		foreach ( (array) glob( $dir . '*.audio' ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Work out which bytes a Range header is asking for.
	 *
	 * Pulled out as a pure function because this is where the bugs live, and
	 * header() cannot be read back in a test. Two cases are easy to get wrong
	 * and both matter:
	 *
	 * - `bytes=-500` means the LAST 500 bytes, not the first 500.
	 * - `bytes=0-` is what Chrome sends FIRST, for the whole file. It still has
	 *   to be answered 206 with a Content-Range. Answering 200 there is what
	 *   makes a media element decide the server cannot seek.
	 *
	 * @param string $header Raw Range header, '' when absent.
	 * @param int    $size   File size in bytes.
	 * @return array|false array( start, end, partial ), or false for 416.
	 */
	protected static function parse_range( $header, $size ) {
		$whole = array(
			'start'   => 0,
			'end'     => $size > 0 ? $size - 1 : 0,
			'partial' => false,
		);
		if ( $size <= 0 || '' === (string) $header ) {
			return $whole;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( (string) $header ), $m ) ) {
			// Unparseable, or a multipart range no media element ever sends.
			// The whole file is a valid answer to any Range request.
			return $whole;
		}
		$has_from = '' !== $m[1];
		$has_to   = '' !== $m[2];
		if ( ! $has_from && ! $has_to ) {
			return $whole;
		}
		if ( $has_from ) {
			$start = (int) $m[1];
			$end   = $has_to ? (int) $m[2] : $size - 1;
		} else {
			$start = max( 0, $size - (int) $m[2] );
			$end   = $size - 1;
		}
		$end = min( $end, $size - 1 );
		if ( $start > $end || $start >= $size ) {
			return false;
		}
		return array(
			'start'   => $start,
			'end'     => $end,
			'partial' => true,
		);
	}

	/**
	 * Serve the file, honouring a Range request.
	 *
	 * Range is the difference between a player you can scrub and one that plays
	 * from the top and stops. `Accept-Ranges: bytes` is what tells the browser
	 * it may ask for the middle of a track at all; without it the scrub bar is
	 * inert and playback ends wherever the buffer happens to end.
	 *
	 * Only a single range is honoured. Multipart ranges are legal and no media
	 * element asks for them, so the whole file is returned instead - which is a
	 * valid answer to any Range request and cannot corrupt playback.
	 *
	 * @param string $path     Absolute path to the cached file.
	 * @param string $filename Name, for the disposition hint.
	 * @param string $mime     Content type.
	 * @return void
	 */
	protected static function stream_range( $path, $filename, $mime ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$range = self::parse_range( $header, $size );

		if ( false === $range ) {
			header( 'Content-Range: bytes */' . $size );
			status_header( 416 );
			exit;
		}

		$start   = $range['start'];
		$end     = $range['end'];
		$partial = $range['partial'];
		$length  = $end - $start + 1;

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'Accept-Ranges: bytes' );
		if ( $partial ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		if ( $size > 0 ) {
			header( 'Content-Length: ' . $length );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			status_header( 500 );
			exit;
		}
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 262144, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary audio.
			flush();
			$remaining -= strlen( $chunk );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Stop, with a status and a sentence rather than a blank page.
	 *
	 * @param string $message Why.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	protected static function bail( $message, $status ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Recording', 'ans-singers-portal' ),
			array( 'response' => (int) $status )
		);
	}
}
