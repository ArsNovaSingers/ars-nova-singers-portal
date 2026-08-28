<?php
/**
 * "Sheet music" — the one panel on a project where sheet music comes in.
 *
 * THE WHOLE FLOW, IN ONE PLACE, IN THIS ORDER:
 *
 *   1. Set root folder   pick the project's Google Drive folder (browse or paste)
 *   2. Scan              read that folder, propose a name for each new PDF
 *   3. Review            edit any proposed name; oversized files carry a
 *                        warning and an Optimize button
 *   4. Approve           the piece is created and published under that name
 *
 * WHY IT IS HERE AND NOT ON A MENU OF ITS OWN
 *
 * The first cut of the optimisation work put "Smaller Files" in the sidebar as
 * its own screen. That was wrong, and Jonathan said so: file size is not a
 * task somebody sets out to do. It is a thing you notice about a file while
 * you are adding it. Splitting it into a separate destination meant the person
 * adding music had to know that a second screen existed, go to it, and match
 * up rows by name. So the warning now sits on the row it belongs to, and the
 * button that fixes it is next to the warning.
 *
 * OPTIMISE BEFORE PUBLISHING, NOT AFTER
 *
 * Because the button is here, it acts on the staged file rather than a
 * published one - the smaller version is what gets published in the first
 * place. No singer downloads the large one, and there is no second version
 * created purely to replace a file that was only ever too big by accident.
 *
 * NOTHING HERE PUBLISHES BY ITSELF. Scanning reads Drive and proposes.
 * Optimising rewrites a staged file that no singer can see. Only Approve makes
 * something visible, and it goes through the worker's ordinary publish, so it
 * inherits R3 ordering, the page-count gate, version history and rollback.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sheet-music panel on an ans_project.
 */
class ANSP_Sheet_Music_Box {

	/** Drive folder chosen for this project. */
	const META_FOLDER_ID   = 'ansp_drive_folder_id';
	const META_FOLDER_NAME = 'ansp_drive_folder_name';

	/** Admin-ajax actions. */
	const AJAX_BROWSE   = 'ansp_sm_browse';
	const AJAX_SCAN     = 'ansp_sm_scan';
	const AJAX_OPTIMISE = 'ansp_sm_optimise';
	const AJAX_APPROVE  = 'ansp_sm_approve';
	const AJAX_PENDING  = 'ansp_sm_pending';
	const AJAX_SAVE_FOLDER = 'ansp_sm_save_folder';

	/**
	 * A file bigger than this gets the warning and the Optimize button.
	 *
	 * 30 MB, because that is where a real ceiling sits rather than a taste
	 * judgement: Cloud Run refuses to serve a single response over 32 MiB, so
	 * a published score above it cannot be downloaded whole over WebDAV. Files
	 * under this are left alone however they were made.
	 */
	const BIG_FILE_BYTES = 31457280;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		/*
		 * Written out rather than derived from the action name. The first
		 * version built the method name with substr(), got the offset wrong by
		 * three characters, and registered six callbacks that did not exist -
		 * which fails as "the buttons do nothing", with no error anywhere.
		 * A map is longer and cannot be off by three.
		 */
		$handlers = array(
			self::AJAX_BROWSE      => 'handle_sm_browse',
			self::AJAX_SAVE_FOLDER => 'handle_sm_save_folder',
			self::AJAX_SCAN        => 'handle_sm_scan',
			self::AJAX_PENDING     => 'handle_sm_pending',
			self::AJAX_OPTIMISE    => 'handle_sm_optimise',
			self::AJAX_APPROVE     => 'handle_sm_approve',
		);
		foreach ( $handlers as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Capability. Same as the rest of the portal admin.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'ansp_manage_portal' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Register the meta box on projects.
	 */
	public static function add_box() {
		if ( ! class_exists( 'ANSP_CPT' ) ) {
			return;
		}
		add_meta_box(
			'ansp-sheet-music',
			__( 'Sheet music', 'ans-singers-portal' ),
			array( __CLASS__, 'render' ),
			ANSP_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Scripts, only on a project edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! class_exists( 'ANSP_CPT' ) || ANSP_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'ansp-sheet-music',
			ANSP_URL . 'assets/sheet-music.js',
			array(),
			ANSP_VERSION,
			true
		);
		wp_enqueue_style(
			'ansp-sheet-music',
			ANSP_URL . 'assets/sheet-music.css',
			array(),
			ANSP_VERSION
		);
		wp_localize_script(
			'ansp-sheet-music',
			'anspSheetMusic',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ansp_sheet_music' ),
				'postId'   => get_the_ID(),
				'bigBytes' => self::BIG_FILE_BYTES,
			)
		);
	}

	/* -------------------------------------------------------------------
	 * Worker
	 * ---------------------------------------------------------------- */

	/**
	 * Call the worker. One place that knows the address and the token.
	 *
	 * @param string $path   Path.
	 * @param string $method GET|POST.
	 * @param array  $body   Body for POST.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error
	 */
	protected static function worker( $path, $method = 'GET', $body = null, $timeout = 30 ) {
		if ( ! class_exists( 'ANSP_Scores_Source' ) ) {
			return new WP_Error( 'ansp_no_source', __( 'The sheet-music integration is not available.', 'ans-singers-portal' ) );
		}
		$base  = ANSP_Scores_Source::worker_url();
		$token = ANSP_Scores_Source::worker_token();
		if ( '' === $base || '' === $token ) {
			return new WP_Error(
				'ansp_not_configured',
				__( 'The sheet-music service is not set up yet. Add its address and token under Singers Portal → Sheet Music Mirror.', 'ans-singers-portal' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$res = wp_remote_request( untrailingslashit( $base ) . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$why = is_array( $json ) && ! empty( $json['error'] ) ? $json['error'] : 'HTTP ' . $code;
			return new WP_Error( 'ansp_worker', $why );
		}
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'ansp_worker', __( 'The service returned something unreadable.', 'ans-singers-portal' ) );
		}
		return $json;
	}

	/**
	 * Shared guard for every ajax handler.
	 *
	 * @return int Project id.
	 */
	protected static function guard() {
		check_ajax_referer( 'ansp_sheet_music', 'nonce' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'ans-singers-portal' ) ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That project could not be edited.', 'ans-singers-portal' ) ), 403 );
		}
		return $post_id;
	}

	/**
	 * Turn whatever a person pasted into a Drive folder id.
	 *
	 * People paste the address bar, not an id. Every one of these is a real
	 * shape a Drive URL takes, and refusing them all because they are "not an
	 * id" would be the pedantic answer to a reasonable action.
	 *
	 * @param string $raw Pasted text.
	 * @return string Folder id, or ''.
	 */
	public static function folder_id_from( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#/folders/([A-Za-z0-9_-]{10,})#', $raw, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([A-Za-z0-9_-]{10,})#', $raw, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#^[A-Za-z0-9_-]{10,}$#', $raw ) ) {
			return $raw;
		}
		return '';
	}

	/* -------------------------------------------------------------------
	 * Ajax
	 * ---------------------------------------------------------------- */

	/**
	 * Browse Drive folders.
	 */
	public static function handle_sm_browse() {
		self::guard();
		$parent = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';
		$res    = self::worker( '/drive/folders' . ( '' !== $parent ? '?parent=' . rawurlencode( $parent ) : '' ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Remember the chosen folder on the project.
	 */
	public static function handle_sm_save_folder() {
		$post_id = self::guard();
		$raw     = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : '';
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$id      = self::folder_id_from( $raw );

		if ( '' === $id ) {
			wp_send_json_error(
				array(
					'message' => __( 'That does not look like a Google Drive folder. Paste the address of the folder, or use Browse.', 'ans-singers-portal' ),
				)
			);
		}

		// Confirm it exists and we can see it BEFORE saving. Saving an
		// unreachable id means the failure surfaces later, at scan time, when
		// it reads as "scanning is broken" rather than "that folder is wrong".
		$check = self::worker( '/drive/folders?parent=' . rawurlencode( $id ) );
		if ( is_wp_error( $check ) ) {
			wp_send_json_error( array( 'message' => $check->get_error_message() ) );
		}

		if ( '' === $name && ! empty( $check['at']['name'] ) ) {
			$name = sanitize_text_field( $check['at']['name'] );
		}

		update_post_meta( $post_id, self::META_FOLDER_ID, $id );
		update_post_meta( $post_id, self::META_FOLDER_NAME, $name );

		wp_send_json_success( array( 'id' => $id, 'name' => $name ) );
	}

	/**
	 * Scan the project's folder.
	 */
	public static function handle_sm_scan() {
		$post_id = self::guard();

		$folder = (string) get_post_meta( $post_id, self::META_FOLDER_ID, true );
		if ( '' === $folder ) {
			wp_send_json_error( array( 'message' => __( 'Set the root folder first.', 'ans-singers-portal' ) ) );
		}

		$group = self::group_for( $post_id );
		if ( '' === $group ) {
			wp_send_json_error(
				array(
					'message' => __( 'This project has no group, so there is nowhere to file its music. Set the project\'s group first.', 'ans-singers-portal' ),
				)
			);
		}

		// Scanning downloads and fingerprints every changed PDF, and these run
		// to hundreds of megabytes. Minutes, not seconds.
		$res = self::worker( '/scan', 'POST', array( 'group' => $group, 'folder_id' => $folder ), 300 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( self::pending_payload( $group, $res ) );
	}

	/**
	 * What is waiting for this project's group.
	 */
	public static function handle_sm_pending() {
		$post_id = self::guard();
		$group   = self::group_for( $post_id );
		wp_send_json_success( self::pending_payload( $group, null ) );
	}

	/**
	 * Make one staged file smaller.
	 */
	public static function handle_sm_optimise() {
		$post_id    = self::guard();
		$staging_id = isset( $_POST['staging_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_id'] ) ) : '';
		if ( '' === $staging_id ) {
			wp_send_json_error( array( 'message' => __( 'No file was named.', 'ans-singers-portal' ) ) );
		}

		$res = self::worker( '/optimise/staged', 'POST', array( 'staging_id' => $staging_id ), 300 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$group   = self::group_for( $post_id );
		$payload = self::pending_payload( $group, null );
		$payload['result'] = $res;
		wp_send_json_success( $payload );
	}

	/**
	 * Approve one file: create the piece and publish it.
	 */
	public static function handle_sm_approve() {
		$post_id    = self::guard();
		$staging_id = isset( $_POST['staging_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_id'] ) ) : '';
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$canonical  = isset( $_POST['canonical'] ) ? sanitize_text_field( wp_unslash( $_POST['canonical'] ) ) : '';
		$work_id    = isset( $_POST['work_id'] ) ? sanitize_text_field( wp_unslash( $_POST['work_id'] ) ) : '';

		if ( '' === $staging_id || ! in_array( $decision, array( 'new_work', 'new_edition', 'reject' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That request was incomplete.', 'ans-singers-portal' ) ) );
		}

		$user = wp_get_current_user();
		$body = array(
			'staging_id' => $staging_id,
			'decision'   => $decision,
			'actor'      => $user && $user->user_login ? $user->user_login : 'wp-admin',
		);
		if ( 'new_work' === $decision ) {
			if ( '' === $canonical ) {
				wp_send_json_error( array( 'message' => __( 'Give the piece a name before adding it.', 'ans-singers-portal' ) ) );
			}
			$body['canonical'] = $canonical;
		}
		if ( 'new_edition' === $decision ) {
			$body['work_id'] = $work_id;
		}

		$res = self::worker( '/publish', 'POST', $body, 120 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$group   = self::group_for( $post_id );
		$payload = self::pending_payload( $group, null );
		$payload['result'] = $res;
		wp_send_json_success( $payload );
	}

	/* -------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * The mirror group this project files into.
	 *
	 * Read from the project's stated mirror address first, because that is the
	 * field that says it outright. Only if that is unset does it fall back to
	 * the WordPress group. Those two are NOT the same name - Chamber Singers is
	 * `cs` in WordPress and `chamber-singers` in the mirror - and v1.15.0
	 * shipped a feature that silently found nothing by assuming they matched.
	 *
	 * @param int $post_id Project.
	 * @return string
	 */
	public static function group_for( $post_id ) {
		if ( class_exists( 'ANSP_Scores_Source' ) ) {
			$target = ANSP_Scores_Source::mirror_target( (int) $post_id );
			if ( ! empty( $target['groups'][0] ) ) {
				return (string) $target['groups'][0];
			}
		}
		return '';
	}

	/**
	 * Everything the panel needs to draw the waiting list.
	 *
	 * @param string     $group Mirror group.
	 * @param array|null $scan  A scan response, if this call did one.
	 * @return array
	 */
	protected static function pending_payload( $group, $scan ) {
		$items = array();
		$res   = self::worker( '/staging?state=pending' );
		if ( ! is_wp_error( $res ) ) {
			foreach ( (array) ( isset( $res['items'] ) ? $res['items'] : array() ) as $item ) {
				if ( $group && isset( $item['group'] ) && $item['group'] !== $group ) {
					continue;
				}
				$items[] = self::shape( $item );
			}
		}
		return array(
			'group' => $group,
			'items' => $items,
			'scan'  => $scan,
		);
	}

	/**
	 * Flatten one staged item into what the panel shows.
	 *
	 * @param array $item Staging item.
	 * @return array
	 */
	protected static function shape( $item ) {
		$proposal = isset( $item['proposal'] ) ? $item['proposal'] : array();
		$opt      = isset( $item['optimisation'] ) ? $item['optimisation'] : array();
		$size     = isset( $item['source_size'] ) ? (int) $item['source_size'] : 0;

		return array(
			'staging_id'  => isset( $item['staging_id'] ) ? $item['staging_id'] : '',
			'source_name' => isset( $item['source_name'] ) ? $item['source_name'] : '',
			'project'     => isset( $item['project'] ) ? $item['project'] : '',
			'pages'       => isset( $item['inspected']['page_count'] ) ? (int) $item['inspected']['page_count'] : 0,
			'size'        => $size,
			'size_human'  => size_format( $size, 1 ),
			'is_big'      => $size > self::BIG_FILE_BYTES,
			'optimised'   => ! empty( $opt['applied_before_publishing'] ),
			'saved_human' => ! empty( $opt['bytes_before'] ) ? size_format( (int) $opt['bytes_before'] - (int) $opt['bytes_after'], 1 ) : '',
			'was_human'   => ! empty( $opt['bytes_before'] ) ? size_format( (int) $opt['bytes_before'], 1 ) : '',
			'decision'    => isset( $proposal['decision'] ) ? $proposal['decision'] : 'new_work',
			'work_id'     => isset( $proposal['work_id'] ) ? $proposal['work_id'] : '',
			'why'         => isset( $proposal['why'] ) ? $proposal['why'] : '',
			'confidence'  => isset( $proposal['confidence'] ) ? $proposal['confidence'] : '',
			'proposed'    => isset( $proposal['proposed_canonical'] ) ? $proposal['proposed_canonical'] : '',
		);
	}

	/**
	 * The panel.
	 *
	 * @param WP_Post $post Project.
	 */
	public static function render( $post ) {
		$folder_id   = (string) get_post_meta( $post->ID, self::META_FOLDER_ID, true );
		$folder_name = (string) get_post_meta( $post->ID, self::META_FOLDER_NAME, true );
		$group       = self::group_for( $post->ID );

		ansp_get_template(
			'admin-sheet-music',
			array(
				'ansp_folder_id'   => $folder_id,
				'ansp_folder_name' => $folder_name,
				'ansp_group'       => $group,
			)
		);
	}
}
