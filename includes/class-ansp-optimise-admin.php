<?php
/**
 * "Smaller files" — the screen where Tom approves an optimised score.
 *
 * The worker can already make a 110 MB score into a 9 MB one and prove the
 * result still renders. What it cannot do is decide, and per Jonathan's
 * 2026-08-25 ruling it must not: optimisation is offered per file and applied
 * only by a person. Until this screen existed that person had to make a REST
 * call, which meant it was never going to be Tom.
 *
 * WHAT THIS SCREEN IS FOR, AND WHY IT LEADS WITH THE PDFs
 *
 * The failure mode of this whole feature is a candidate that looks like a
 * triumph in a table and is unreadable on the page. It happened twice while
 * the optimiser was being built: once with re-deflated image streams that
 * rendered blank, once with a CMYK inversion array that rendered every page as
 * a photographic negative. Both were smaller. Both kept their page count and
 * page geometry. Both would have sailed through a screen that showed only
 * numbers.
 *
 * So the two buttons that matter most here are not Approve and Reject - they
 * are "Open the new one" and "Open the current one". The numbers are context.
 * The PDF is the evidence.
 *
 * WHAT APPROVING DOES
 *
 * Nothing bespoke. It calls the worker's ordinary POST /publish with
 * decision=new_edition, the same call a Drive intake uses, so an approved
 * candidate goes through R3 ordering (bytes to _versions/ before the published
 * pointer moves), the section 3.4 page-count gate, and the version history.
 * The previous version stays retrievable and can be rolled back. Nothing is
 * deleted, ever (R1).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen for reviewing optimisation candidates.
 */
class ANSP_Optimise_Admin {

	const SLUG       = 'ansp-smaller-files';
	const ACTION     = 'ansp_optimise_decide';
	const SCAN       = 'ansp_optimise_scan';
	const VIEW       = 'ansp_optimise_view';
	const NOTICE_KEY = 'ansp_optimise_notice';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_post_' . self::SCAN, array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_' . self::VIEW, array( __CLASS__, 'handle_view' ) );
	}

	/**
	 * Who may see and use this. Same capability as the rest of the portal
	 * admin, so Tom (artistic_director) and Zahnay (personnel_manager) qualify
	 * without a new role being invented for one screen.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'ansp_manage_portal' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Add the submenu.
	 */
	public static function add_page() {
		if ( ! class_exists( 'ANSP_Dashboard' ) ) {
			return;
		}
		add_submenu_page(
			ANSP_Dashboard::MENU_SLUG,
			__( 'Smaller Files', 'ans-singers-portal' ),
			__( 'Smaller Files', 'ans-singers-portal' ),
			'ansp_manage_portal',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* -------------------------------------------------------------------
	 * Talking to the worker
	 * ---------------------------------------------------------------- */

	/**
	 * One place that knows how to call the worker.
	 *
	 * The token is read server-side and never rendered. Deferred to
	 * ANSP_Scores_Source so there is a single definition of where the worker
	 * lives and what it is called with.
	 *
	 * @param string $path   Path after the base, e.g. '/staging'.
	 * @param string $method GET or POST.
	 * @param array  $body   JSON body for POST.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected static function worker( $path, $method = 'GET', $body = null ) {
		if ( ! class_exists( 'ANSP_Scores_Source' ) ) {
			return new WP_Error( 'ansp_no_source', __( 'The mirror integration is not available.', 'ans-singers-portal' ) );
		}
		$base  = ANSP_Scores_Source::worker_url();
		$token = ANSP_Scores_Source::worker_token();
		if ( '' === $base || '' === $token ) {
			return new WP_Error(
				'ansp_not_configured',
				__( 'The sheet-music worker is not configured yet — set its address and token under Singers Portal → Sheet Music Mirror.', 'ans-singers-portal' )
			);
		}

		$args = array(
			'method'  => $method,
			// Optimisation runs are slow by nature: a 110 MB score is opened,
			// every page re-encoded and the result verified. The default 5
			// seconds would report a timeout on work that is proceeding
			// normally, which reads as breakage.
			'timeout' => ( 'POST' === $method ) ? 300 : 30,
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
			$why = is_array( $json ) && ! empty( $json['error'] ) ? $json['error'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ansp_worker_error', $why );
		}
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'ansp_worker_error', __( 'The worker returned something that was not JSON.', 'ans-singers-portal' ) );
		}
		return $json;
	}

	/**
	 * Pending items that are optimisation candidates.
	 *
	 * The staging queue also holds Drive intake decisions. Those are a
	 * different question for a different screen, so anything without an
	 * `optimisation` block is filtered out here rather than shown with the
	 * wrong buttons attached to it.
	 *
	 * @return array|WP_Error
	 */
	public static function pending() {
		$res = self::worker( '/staging?state=pending' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( (array) ( isset( $res['items'] ) ? $res['items'] : array() ) as $item ) {
			if ( ! empty( $item['optimisation'] ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	/**
	 * Approve or reject one candidate.
	 */
	public static function handle_decision() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ans-singers-portal' ) );
		}
		check_admin_referer( self::ACTION );

		$staging_id = isset( $_POST['staging_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_id'] ) ) : '';
		$work_id    = isset( $_POST['work_id'] ) ? sanitize_text_field( wp_unslash( $_POST['work_id'] ) ) : '';
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : $staging_id;

		if ( '' === $staging_id || ! in_array( $decision, array( 'new_edition', 'reject' ), true ) ) {
			self::notice( 'error', __( 'That request did not name a candidate and a decision.', 'ans-singers-portal' ) );
			self::back();
		}

		$user = wp_get_current_user();
		$body = array(
			'staging_id' => $staging_id,
			'decision'   => $decision,
			'actor'      => $user && $user->user_login ? $user->user_login : 'wp-admin',
		);
		if ( 'new_edition' === $decision ) {
			$body['work_id'] = $work_id;
		}

		$res = self::worker( '/publish', 'POST', $body );

		if ( is_wp_error( $res ) ) {
			self::notice(
				'error',
				sprintf(
					/* translators: 1: score name, 2: error message */
					__( '%1$s was NOT changed. The worker said: %2$s', 'ans-singers-portal' ),
					$label,
					$res->get_error_message()
				)
			);
			self::back();
		}

		if ( 'reject' === $decision ) {
			self::notice(
				'success',
				sprintf(
					/* translators: %s: score name */
					__( 'Discarded the smaller copy of %s. The file singers see is unchanged.', 'ans-singers-portal' ),
					$label
				)
			);
		} else {
			self::notice(
				'success',
				sprintf(
					/* translators: 1: score name, 2: version number */
					__( '%1$s is now published as version %2$s. The previous version is kept and can be put back.', 'ans-singers-portal' ),
					$label,
					isset( $res['version'] ) ? $res['version'] : '?'
				)
			);
		}
		self::back();
	}

	/**
	 * Look for new candidates.
	 */
	public static function handle_scan() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ans-singers-portal' ) );
		}
		check_admin_referer( self::SCAN );

		$group = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		if ( '' === $group ) {
			self::notice( 'error', __( 'Choose a group to check first.', 'ans-singers-portal' ) );
			self::back();
		}

		$res = self::worker( '/optimise/scan', 'POST', array( 'group' => $group ) );
		if ( is_wp_error( $res ) ) {
			self::notice( 'error', $res->get_error_message() );
			self::back();
		}

		$offered = isset( $res['offered'] ) ? (int) $res['offered'] : 0;
		$saved   = isset( $res['would_save_bytes'] ) ? (int) $res['would_save_bytes'] : 0;
		if ( $offered > 0 ) {
			self::notice(
				'success',
				sprintf(
					/* translators: 1: number of files, 2: human-readable size */
					_n(
						'%1$d file could be made smaller, saving %2$s. Nothing has changed yet — look at each one below.',
						'%1$d files could be made smaller, saving %2$s in total. Nothing has changed yet — look at each one below.',
						$offered,
						'ans-singers-portal'
					),
					$offered,
					size_format( $saved, 1 )
				)
			);
		} else {
			self::notice(
				'success',
				sprintf(
					/* translators: %d: number of files checked */
					__( 'Checked %d files. Nothing worth changing — they are already about as small as they should be.', 'ans-singers-portal' ),
					isset( $res['examined'] ) ? (int) $res['examined'] : 0
				)
			);
		}
		self::back();
	}

	/**
	 * Park a message for the next page load and go back.
	 *
	 * A transient rather than a query argument: the worker's error text can be
	 * a sentence, and sentences do not belong in a URL where they get
	 * truncated and re-encoded.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	protected static function notice( $type, $message ) {
		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	/**
	 * Redirect back to the screen.
	 */
	protected static function back() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/* -------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/**
	 * The screen.
	 */
	public static function render() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ans-singers-portal' ) );
		}

		$notice = get_transient( self::NOTICE_KEY . '_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( self::NOTICE_KEY . '_' . get_current_user_id() );
		}

		$items  = self::pending();
		$groups = class_exists( 'ANSP_Scores_Source' ) ? ANSP_Scores_Source::configured_mirror_groups() : array();

		ansp_get_template(
			'admin-optimise',
			array(
				'ansp_items'  => $items,
				'ansp_groups' => $groups,
				'ansp_notice' => $notice,
			)
		);
	}

	/**
	 * Open a candidate, or the file it would replace.
	 *
	 * The signed URL is minted HERE, at click time, and the browser is sent
	 * straight to it. It is deliberately NOT fetched while rendering the list.
	 *
	 * That is the same lesson the Hub's Open button cost us on 2026-08-27: a
	 * signed URL is a credential with a fifteen-minute expiry, an href is a
	 * promise with no expiry at all, and a page that sits open through a
	 * conversation about whether to approve something is exactly the page whose
	 * links will have died. Rendering eight of them would also spend eight
	 * signatures to produce links that are stale before anyone clicks one.
	 */
	public static function handle_view() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ans-singers-portal' ) );
		}

		$staging_id = isset( $_GET['staging_id'] ) ? sanitize_text_field( wp_unslash( $_GET['staging_id'] ) ) : '';
		$which      = isset( $_GET['which'] ) ? sanitize_key( wp_unslash( $_GET['which'] ) ) : 'candidate';
		check_admin_referer( self::VIEW . '_' . $staging_id );

		$res = self::worker( '/staging/' . rawurlencode( $staging_id ) . '/url' );
		if ( is_wp_error( $res ) ) {
			self::notice( 'error', $res->get_error_message() );
			self::back();
		}

		$key = ( 'current' === $which ) ? 'current_url' : 'candidate_url';
		if ( empty( $res[ $key ] ) ) {
			self::notice( 'error', __( 'That file could not be opened — the worker did not return a link for it.', 'ans-singers-portal' ) );
			self::back();
		}

		// Not wp_safe_redirect: the destination is Google Cloud Storage, which
		// is by definition not an allowed host for this site. The URL came
		// from our own worker over an authenticated call, not from the request.
		nocache_headers();
		wp_redirect( esc_url_raw( $res[ $key ] ), 302 );
		exit;
	}
}
