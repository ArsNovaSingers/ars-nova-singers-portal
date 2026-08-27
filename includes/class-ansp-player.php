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
 * WHY THERE IS NO `Accept-Ranges` HEADER, since its absence looks like an
 * oversight: fetch_material() re-downloads the entire file from Drive on every
 * request. Honouring a Range request would therefore re-fetch sixteen megabytes
 * per seek, which is far worse than a scrub bar that is limited for the first
 * few seconds while the file buffers. Progressive playback starts almost
 * immediately either way, and once the file has arrived the browser can seek
 * anywhere in it. Add Range only alongside a server-side cache, or once audio
 * lives in the Cloud Storage mirror, which handles it natively.
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

		$got = ANSP_Materials_Zip::fetch_material( $row );
		if ( is_wp_error( $got ) ) {
			self::bail( $got->get_error_message(), 502 );
		}

		self::stream_inline( $got['path'], $got['name'], $got['mime'] );
	}

	/**
	 * Send the file with `inline`, and let the browser cache it.
	 *
	 * The one header that differs from the download path is Content-Disposition.
	 * The caching is the second half of the answer: a singer working through a
	 * movement will press play more than once, and without a private cache every
	 * replay would pull the whole file from Drive again.
	 *
	 * @param string $path     Absolute path to the fetched file.
	 * @param string $filename Name, used only for the disposition hint.
	 * @param string $mime     Content type.
	 * @return void
	 */
	protected static function stream_inline( $path, $filename, $mime ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=3600' );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . $size );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
