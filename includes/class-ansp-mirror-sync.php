<?php
/**
 * Asking the worker to look at Drive, when a person says there is something new.
 *
 * WHY THIS EXISTS. Tom's 9/10 rehearsal note sat in Drive and never reached a
 * singer: the worker only looks at Drive when asked, and the only thing that
 * asked was a Scan button buried in wp-admin. The last scan of Rivers &
 * Streams had been 2026-09-04.
 *
 * 1.38.0 answered that with an hourly job. Jonathan removed it in 1.38.1 as
 * wasteful: material arrives a few times a week, from a person who knows
 * they just added it. Twenty-four scans a day to catch that is the wrong
 * shape. The fix is to put the button where that person already is - on the
 * project in the Singers Hub - and make it one click. (The hourly job also ran
 * on staging, which shares the production mirror, so staging could publish
 * for real without anybody deciding to. A button does not.)
 *
 *   scan_project()  the one way this site asks the worker to look at a
 *                   project's Drive folder. The Hub's "Check Drive for new
 *                   files" button, the project screen's Scan button and the
 *                   REST route all come through here, so they cannot
 *                   disagree about what gets auto-published. Every call is
 *                   logged.
 *   the Hub button  managers only, on each project that has a Drive folder.
 *   REST            scan, see what is waiting, and decide it, from a session
 *                   that holds no worker token.
 *
 * WHAT AUTO-PUBLISHES, AND WHAT NEVER DOES. Recordings whose identity is
 * certain, and PDFs that match nothing already published IN A FOLDER THIS
 * PROJECT NAMES AS REHEARSAL NOTES. A PDF that could be a new edition of a
 * score always waits for a person - that is the case the Librarian's gates
 * exist for (Device_Sync_Spec R4), and it is untouched here.
 *
 * @package ArsNovaSingersPortal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled and on-demand scans.
 */
class ANSP_Mirror_Sync {

	/** The 1.38.0 hourly job's hook. Kept only so it can be cleared. */
	const CRON_HOOK = 'ansp_mirror_autoscan';
	const OPT_LOG   = 'ansp_mirror_scan_log';
	const LOG_KEEP  = 40;

	/** admin-ajax action and nonce for the Hub button. */
	const AJAX_ACTION = 'ansp_check_drive';

	/** Rounds per project per run. The worker examines 25 changed files a round. */
	const MAX_ROUNDS = 6;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'unschedule_legacy' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_check_drive' ) );
	}

	/**
	 * Remove 1.38.0's hourly job from any site that still has it scheduled.
	 * A plugin update does not run the deactivation hook, so this is what
	 * actually takes it off LIVE and staging.
	 */
	public static function unschedule_legacy() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			self::unschedule();
			delete_option( 'ansp_mirror_autoscan_log' );
		}
	}

	/**
	 * Remove the job. Called on plugin deactivation and by unschedule_legacy().
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* -------------------------------------------------------------------
	 * Scanning
	 * ---------------------------------------------------------------- */

	/**
	 * Worker project names this project reads as rehearsal notes, for one group.
	 *
	 * @param int    $project_id Project.
	 * @param string $group      Worker group.
	 * @return string[]
	 */
	protected static function note_folders( $project_id, $group ) {
		$out = array();
		foreach ( ANSP_Scores_Source::mirror_targets( $project_id, 'rehearsal_note' ) as $target ) {
			if ( in_array( $group, $target['groups'], true ) && '' !== $target['project'] ) {
				$out[] = $target['project'];
			}
		}
		return $out;
	}

	/**
	 * Has this project ever been given a mirror address, or been scanned?
	 *
	 * @param int $project_id Project.
	 * @return bool
	 */
	protected static function has_published_before( $project_id ) {
		foreach ( ANSP_Scores_Source::mirror_kinds() as $kind ) {
			if ( '' !== trim( (string) get_post_meta( $project_id, $kind['meta'], true ) ) ) {
				return true;
			}
		}
		return (bool) ANSP_Scores_Source::found_folders( $project_id );
	}

	/**
	 * Ask the worker to scan one project's Drive folder.
	 *
	 * @param int    $project_id Project.
	 * @param string $actor      Recorded against anything published.
	 * @param int    $rounds     How many 25-file rounds to allow.
	 * @param int    $timeout    Seconds per round.
	 * @return array|WP_Error Summary, plus the last raw response under 'last'.
	 */
	public static function scan_project( $project_id, $actor = 'hub', $rounds = 1, $timeout = 300 ) {
		$project_id = (int) $project_id;
		$folder     = (string) get_post_meta( $project_id, ANSP_Sheet_Music_Box::META_FOLDER_ID, true );
		if ( '' === $folder ) {
			return new WP_Error( 'ansp_no_folder', __( 'This project has no Drive folder set.', 'ans-singers-portal' ) );
		}
		$group = ANSP_Sheet_Music_Box::group_for( $project_id );
		if ( '' === $group ) {
			return new WP_Error( 'ansp_no_group', __( 'This project has no mirror group, so there is nowhere to file its music.', 'ans-singers-portal' ) );
		}

		/*
		 * 1.39.0: a project that has never published gets its own space in
		 * the mirror, named for the project. One that has (any mirror field set,
		 * or a previous scan) keeps the unprefixed scheme, because its published
		 * paths are frozen and moving them would duplicate every file.
		 */
		$prefix = ANSP_Scores_Source::project_prefix( $project_id );
		if ( '' === $prefix && ! self::has_published_before( $project_id ) ) {
			$prefix = ANSP_Scores_Source::default_prefix( $project_id );
			update_post_meta( $project_id, ANSP_Scores_Source::META_PREFIX, $prefix );
		}

		$summary = array(
			'project_id'  => $project_id,
			'prefix'      => $prefix,
			'group'       => $group,
			'folder_id'   => $folder,
			'rounds'      => 0,
			'published'   => array(),
			'staged'      => array(),
			'problems'    => array(),
			'remaining'   => 0,
			'last'        => null,
		);

		for ( $i = 0; $i < max( 1, (int) $rounds ); $i++ ) {
			$res = ANSP_Sheet_Music_Box::worker(
				'/scan',
				'POST',
				array(
					'group'          => $group,
					'folder_id'      => $folder,
					'project_prefix' => $prefix,
					'actor'          => $actor,
					'auto_publish' => array(
						'audio'             => true,
						'new_work_projects' => self::note_folders( $project_id, $group ),
					),
				),
				$timeout
			);
			if ( is_wp_error( $res ) ) {
				if ( 0 === $summary['rounds'] ) {
					self::log( $project_id, $res, $actor );
					return $res;
				}
				$summary['problems'][] = $res->get_error_message();
				break;
			}
			$summary['rounds']++;
			$summary['last'] = $res;
			if ( isset( $res['folders_in_scan'] ) && is_array( $res['folders_in_scan'] ) ) {
				$found = array();
				foreach ( $res['folders_in_scan'] as $folder_key ) {
					$found[] = $group . '/' . (string) $folder_key;
				}
				update_post_meta( $project_id, ANSP_Scores_Source::META_FOUND, $found );
				$summary['folders'] = $found;
			}
			foreach ( (array) ( isset( $res['results'] ) ? $res['results'] : array() ) as $row ) {
				$name    = isset( $row['source_name'] ) ? (string) $row['source_name'] : '';
				$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
				if ( 'published' === $outcome ) {
					$summary['published'][] = $name;
				} elseif ( 'staged' === $outcome ) {
					$summary['staged'][] = $name;
				} elseif ( 'duplicate' !== $outcome ) {
					$summary['problems'][] = $name . ': ' . $outcome . ( isset( $row['detail'] ) ? ' - ' . $row['detail'] : '' );
				}
				if ( ! empty( $row['auto_publish_failed'] ) ) {
					$summary['problems'][] = $name . ': ' . $row['auto_publish_failed'];
				}
			}
			$summary['remaining'] = isset( $res['not_examined_this_run'] ) ? (int) $res['not_examined_this_run'] : 0;
			if ( $summary['remaining'] < 1 ) {
				break;
			}
		}

		if ( $summary['published'] ) {
			// Singers should see new files on their next page load, not in five minutes.
			ANSP_Scores_Source::bust_cache();
		}
		self::log( $project_id, $summary, $actor );
		return $summary;
	}

	/**
	 * Keep a short record of every scan and who asked for it, readable over REST.
	 *
	 * @param int            $project_id Project.
	 * @param array|WP_Error $result     scan_project() result.
	 */
	protected static function log( $project_id, $result, $actor = '' ) {
		$log   = get_option( self::OPT_LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$entry = array(
			'at'         => gmdate( 'c' ),
			'project_id' => $project_id,
			'by'         => $actor,
		);
		if ( is_wp_error( $result ) ) {
			$entry['error'] = $result->get_error_message();
		} else {
			$entry['published'] = $result['published'];
			$entry['staged']    = count( $result['staged'] );
			$entry['problems']  = $result['problems'];
			$entry['remaining'] = $result['remaining'];
		}
		array_unshift( $log, $entry );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_KEEP ), false );
	}

	/* -------------------------------------------------------------------
	 * REST
	 * ---------------------------------------------------------------- */

	/**
	 * Routes under ars-nova/v1.
	 */
	public static function register_routes() {
		$perm = array( 'ANSP_Mirror_Rest', 'can_manage' );
		register_rest_route(
			'ars-nova/v1',
			'/portal/project/(?P<id>\d+)/scan',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( __CLASS__, 'rest_scan' ),
			)
		);
		register_rest_route(
			'ars-nova/v1',
			'/portal/mirror/staging',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( __CLASS__, 'rest_staging' ),
			)
		);
		register_rest_route(
			'ars-nova/v1',
			'/portal/mirror/publish',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( __CLASS__, 'rest_publish' ),
			)
		);
		register_rest_route(
			'ars-nova/v1',
			'/portal/mirror/scans',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( __CLASS__, 'rest_scans' ),
			)
		);
	}

	/**
	 * Refuse a write on production unless it was asked for on purpose.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return true|WP_Error
	 */
	protected static function guard( $req ) {
		if ( ! ANSP_Mirror_Rest::is_production() || filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return true;
		}
		return new WP_Error( 'ansp_production_blocked', 'This is the production site. Resend with confirm_production=true to proceed.', array( 'status' => 403 ) );
	}

	/**
	 * Who to record as having done it.
	 *
	 * @return string
	 */
	protected static function actor() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? (string) $user->user_email : 'hub';
	}

	/**
	 * POST portal/project/<id>/scan  {rounds?}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_scan( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || ANSP_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'ansp_not_a_project', 'That id is not an ans_project.', array( 'status' => 404 ) );
		}
		$rounds = max( 1, min( self::MAX_ROUNDS, (int) $req->get_param( 'rounds' ) ) );
		$result = self::scan_project( $id, self::actor(), $rounds );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		$last = $result['last'];
		unset( $result['last'] );
		$result['ok']      = true;
		$result['ignored'] = isset( $last['ignored'] ) ? $last['ignored'] : array();
		$result['lossless_skipped'] = isset( $last['lossless_skipped'] ) ? $last['lossless_skipped'] : array();
		$result['note']    = 'remaining > 0 means more changed files are waiting: call again. Staged items wait for a person: GET portal/mirror/staging.';
		return rest_ensure_response( $result );
	}

	/**
	 * GET portal/mirror/staging  {group?}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_staging( $req ) {
		$res = ANSP_Sheet_Music_Box::worker( '/staging?state=pending' );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 502 ) );
			return $res;
		}
		$group = (string) $req->get_param( 'group' );
		$items = array();
		foreach ( (array) ( isset( $res['items'] ) ? $res['items'] : array() ) as $item ) {
			if ( '' !== $group && ( ! isset( $item['group'] ) || $item['group'] !== $group ) ) {
				continue;
			}
			$proposal = isset( $item['proposal'] ) ? (array) $item['proposal'] : array();
			$items[]  = array(
				'staging_id'  => isset( $item['staging_id'] ) ? $item['staging_id'] : '',
				'group'       => isset( $item['group'] ) ? $item['group'] : '',
				'project'     => isset( $item['project'] ) ? $item['project'] : '',
				'source_name' => isset( $item['source_name'] ) ? $item['source_name'] : '',
				'media'       => isset( $item['inspected']['media'] ) ? $item['inspected']['media'] : 'pdf',
				'staged_at'   => isset( $item['staged_at'] ) ? $item['staged_at'] : '',
				'decision'    => isset( $proposal['decision'] ) ? $proposal['decision'] : '',
				'work_id'     => isset( $proposal['work_id'] ) ? $proposal['work_id'] : '',
				'proposed'    => isset( $proposal['proposed_canonical'] ) ? $proposal['proposed_canonical'] : ( isset( $proposal['canonical'] ) ? $proposal['canonical'] : '' ),
				'why'         => isset( $proposal['why'] ) ? $proposal['why'] : '',
				'is_optimisation' => ! empty( $item['optimisation'] ),
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'count' => count( $items ), 'items' => $items ) );
	}

	/**
	 * POST portal/mirror/publish  {decisions: [{staging_id, decision, work_id?, canonical?, accept_structure_change?}]}
	 *
	 * Each item is an explicit decision - this is the same worker call the
	 * project screen's Publish button makes, batched.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_publish( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$decisions = $req->get_param( 'decisions' );
		if ( ! is_array( $decisions ) || empty( $decisions ) ) {
			return new WP_Error( 'ansp_publish_empty', 'Send decisions: [{staging_id, decision}].', array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $decisions as $d ) {
			if ( ! is_array( $d ) || empty( $d['staging_id'] ) || empty( $d['decision'] ) ) {
				continue;
			}
			$decision = sanitize_key( (string) $d['decision'] );
			if ( ! in_array( $decision, array( 'new_work', 'new_edition', 'reject' ), true ) ) {
				continue;
			}
			$row = array(
				'staging_id' => sanitize_key( (string) $d['staging_id'] ),
				'decision'   => $decision,
			);
			if ( ! empty( $d['work_id'] ) ) {
				$row['work_id'] = sanitize_key( (string) $d['work_id'] );
			}
			if ( ! empty( $d['canonical'] ) ) {
				$row['canonical'] = sanitize_text_field( (string) $d['canonical'] );
			}
			if ( ! empty( $d['accept_structure_change'] ) ) {
				$row['accept_structure_change'] = true;
			}
			$clean[] = $row;
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'ansp_publish_empty', 'No valid decisions.', array( 'status' => 400 ) );
		}
		// publish-batch answers 200 with failures listed, so a partial failure is data, not an error.
		$res = ANSP_Sheet_Music_Box::worker(
			'/publish-batch',
			'POST',
			array(
				'decisions' => $clean,
				'actor'     => self::actor(),
			),
			120
		);
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 502 ) );
			return $res;
		}
		ANSP_Scores_Source::bust_cache();
		return rest_ensure_response( $res );
	}

	/**
	 * GET portal/mirror/scans - the last 40 scans, newest first, and who asked.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_scans() {
		return rest_ensure_response(
			array(
				'ok'  => true,
				'log' => get_option( self::OPT_LOG, array() ),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * The Hub button
	 * ---------------------------------------------------------------- */

	/**
	 * May this user press the button, and does this project have a folder?
	 *
	 * @param int $project_id Project.
	 * @param int $user_id    User.
	 * @return bool
	 */
	public static function can_check( $project_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! ANSP_Permissions::is_manager( $user_id ) ) {
			return false;
		}
		if ( ! ANSP_Scores_Source::is_configured() ) {
			return false;
		}
		return '' !== (string) get_post_meta( (int) $project_id, ANSP_Sheet_Music_Box::META_FOLDER_ID, true );
	}

	/**
	 * Render the button for one project. Nothing for anyone else.
	 *
	 * @param int $project_id Project.
	 */
	public static function render_button( $project_id ) {
		if ( ! self::can_check( $project_id ) ) {
			return;
		}
		?>
		<div class="ansp-check-drive" data-ansp-check-drive="<?php echo esc_attr( (string) (int) $project_id ); ?>">
			<button type="button" class="ansp-btn ansp-btn--small" data-ansp-check-drive-button>
				<?php esc_html_e( 'Rescan Drive', 'ans-singers-portal' ); ?>
			</button>
			<span class="ansp-check-drive-note">
				<?php esc_html_e( 'Added new materials to this project\'s Drive folder? Click Rescan so they appear here. Only managers see this.', 'ans-singers-portal' ); ?>
			</span>
			<span class="ansp-check-drive-status" data-ansp-check-drive-status role="status" aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * admin-ajax: scan one project now.
	 */
	public static function ajax_check_drive() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		$project_id = isset( $_POST['project_id'] ) ? absint( wp_unslash( $_POST['project_id'] ) ) : 0;
		$post       = $project_id ? get_post( $project_id ) : null;
		if ( ! $post || ANSP_CPT::POST_TYPE !== $post->post_type || ! self::can_check( $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot check Drive for this project.', 'ans-singers-portal' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$user   = wp_get_current_user();
		$result = self::scan_project( $project_id, (string) $user->user_email, self::MAX_ROUNDS );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		$added   = count( $result['published'] );
		$waiting = count( $result['staged'] );
		$parts   = array();
		if ( $added ) {
			/* translators: %d: number of files */
			$parts[] = sprintf( _n( '%d new file added.', '%d new files added.', $added, 'ans-singers-portal' ), $added );
		}
		if ( $waiting ) {
			/* translators: %d: number of files */
			$parts[] = sprintf( _n( '%d updated score is waiting for approval on the project screen.', '%d updated scores are waiting for approval on the project screen.', $waiting, 'ans-singers-portal' ), $waiting );
		}
		if ( $result['remaining'] > 0 ) {
			$parts[] = __( 'More files are still waiting to be checked - press again.', 'ans-singers-portal' );
		}
		if ( $result['problems'] ) {
			$parts[] = __( 'Some files could not be read - see the project screen.', 'ans-singers-portal' );
		}
		if ( ! $parts ) {
			$parts[] = __( 'Nothing new in Drive.', 'ans-singers-portal' );
		}

		wp_send_json_success(
			array(
				'added'   => $added,
				'waiting' => $waiting,
				'message' => implode( ' ', $parts ),
				'reload'  => $added > 0,
			)
		);
	}
}
