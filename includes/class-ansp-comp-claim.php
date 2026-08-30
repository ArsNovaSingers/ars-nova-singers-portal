<?php
/**
 * The singer-facing comp claim panel. Season Wiki step 3b.
 *
 * 1.26.0 gave the allowance a home ("each singer may claim 2 for this project")
 * and said out loud that nothing could spend it. This is the button.
 *
 * WHAT THIS DOES NOT DO: issue tickets. `ans_comp_issue()` in ans-comp-tickets
 * is the only thing on this site that creates a comp, and it stays that way.
 * Every guard it owns - published parent event, _tc_is_ticket, valid email,
 * read-back verification of the generated tickets, Mailchimp suppression,
 * silencing the untrue "payment failed" admin email - is inherited by calling
 * it rather than reimplemented here. A second issuing path would drift from the
 * first on the day someone fixed a bug in only one of them.
 *
 * The engine was already built for this: it accepts `source => 'portal-claim'`
 * and `claimant_user_id`, and records both on the order. So claims count
 * themselves and no new storage is invented for them.
 *
 * THE ONE THING THE ENGINE DOES NOT RECORD is which PROJECT a claim was against,
 * because the engine knows about performances and has never heard of the hub.
 * This class stamps ANSP_Comp_Claim::META_PROJECT on the order immediately
 * after a successful issue. That is what makes "you have used 1 of your 2 for
 * Rivers & Streams" answerable.
 *
 * FAILS CLOSED, EVERYWHERE. If ans-comp-tickets is not active, if WooCommerce
 * is missing, if the project is unlinked, if the performance has no ticket
 * product - the tab does not appear and the handler refuses. On LIVE today the
 * comp plugin is not installed at all, so this whole feature is correctly
 * invisible there until it is.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Comp_Claim
 */
class ANSP_Comp_Claim {

	/**
	 * Order meta: which hub project this comp was claimed against.
	 */
	const META_PROJECT = '_ans_comp_project';

	/**
	 * The tab id. templates/tab-comps.php renders it by convention.
	 */
	const TAB_ID = 'comps';

	/**
	 * Nonce action for the claim form.
	 */
	const NONCE = 'ansp_claim_comp';

	/**
	 * admin-post action name.
	 */
	const ACTION = 'ansp_claim_comp';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ansp_portal_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_claim' ) );
	}

	/* ---------------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------------ */

	/**
	 * Is the comp engine actually here and usable?
	 *
	 * @return bool
	 */
	public static function engine_available() {
		return function_exists( 'ans_comp_issue' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Add the tab, but only when there is something behind it.
	 *
	 * A tab that always appears and always says "nothing here" trains people to
	 * stop opening it, which is how the one time it DOES have something gets
	 * missed.
	 *
	 * @param array<string,string> $tabs
	 * @return array<string,string>
	 */
	public static function add_tab( $tabs ) {
		if ( ! self::engine_available() ) {
			return $tabs;
		}
		if ( ! self::get_claimable( get_current_user_id() ) ) {
			return $tabs;
		}

		$tabs[ self::TAB_ID ] = __( 'Comp Tickets', 'ans-singers-portal' );
		return $tabs;
	}

	/* ---------------------------------------------------------------------
	 * What this singer may claim
	 * ------------------------------------------------------------------ */

	/**
	 * Every project this user has an unspent allowance on.
	 *
	 * @param int $user_id
	 * @return array<int,array>
	 */
	public static function get_claimable( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( $user_id <= 0 || ! class_exists( 'ANSP_Comp_Allowance' ) || ! class_exists( 'ANSP_Project_Ticketing' ) ) {
			return array();
		}

		$projects = get_posts(
			array(
				'post_type'     => ANSP_Project_Ticketing::project_type(),
				'post_status'   => 'publish',
				'numberposts'   => 100,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		$out = array();

		foreach ( $projects as $pid ) {
			$allowance = ANSP_Comp_Allowance::get_allowance( $pid );
			if ( $allowance <= 0 ) {
				continue;
			}

			/*
			 * Group gating is the portal's existing answer to "should this
			 * singer see this project at all", and a comp allowance must not
			 * become a side door around it.
			 */
			if ( ! self::user_can_see_project( $pid, $user_id ) ) {
				continue;
			}

			$performances = ANSP_Project_Ticketing::get_performances( $pid, true );
			$claimable    = array();

			foreach ( $performances as $perf ) {
				$product = ANSP_Project_Ticketing::get_ticket_product( $perf['id'] );
				if ( $product ) {
					$perf['product_id'] = $product;
					$claimable[]        = $perf;
				}
			}

			if ( empty( $claimable ) ) {
				continue;
			}

			$used = self::claimed_count( $user_id, $pid );
			$out[] = array(
				'project_id'   => (int) $pid,
				'project'      => get_the_title( $pid ),
				'allowance'    => $allowance,
				'used'         => $used,
				'remaining'    => max( 0, $allowance - $used ),
				'note'         => ANSP_Comp_Allowance::get_note( $pid ),
				'performances' => $claimable,
			);
		}

		return $out;
	}

	/**
	 * Does this singer have access to this project?
	 *
	 * Delegates to the permissions class when it offers an answer, and
	 * otherwise allows - the project list is already published-only and the
	 * whole panel sits behind the portal's own capability gate.
	 *
	 * @param int $project_id
	 * @param int $user_id
	 * @return bool
	 */
	protected static function user_can_see_project( $project_id, $user_id ) {
		if ( class_exists( 'ANSP_Permissions' ) && method_exists( 'ANSP_Permissions', 'user_can_see' ) ) {
			$post = get_post( $project_id );
			if ( $post ) {
				return (bool) ANSP_Permissions::user_can_see( $post, $user_id );
			}
		}
		return true;
	}

	/**
	 * How many comps this singer has already claimed on this project.
	 *
	 * Counts ORDERS, not tickets: the allowance is "how many times may you
	 * claim", and a claim is one order. Cancelled and refunded orders are
	 * excluded, so a voided comp gives the singer their claim back - which is
	 * the whole reason Void exists.
	 *
	 * @param int $user_id
	 * @param int $project_id
	 * @return int
	 */
	public static function claimed_count( $user_id, $project_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending' ),
				'return'     => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => '_ans_comp_claimant', 'value' => (int) $user_id ),
					array( 'key' => self::META_PROJECT, 'value' => (int) $project_id ),
				),
			)
		);

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/* ---------------------------------------------------------------------
	 * The write
	 * ------------------------------------------------------------------ */

	/**
	 * Where to send the singer back to.
	 *
	 * @param array $args
	 * @return string
	 */
	protected static function redirect_url( $args = array() ) {
		$base = function_exists( 'ansp_get_portal_url' ) ? ansp_get_portal_url() : home_url( '/' );
		return add_query_arg( $args, $base ) . '#tab-' . self::TAB_ID;
	}

	/**
	 * Handle a claim submission.
	 *
	 * Post/Redirect/Get throughout: a browser refresh must not be able to issue
	 * a second ticket, and the failure modes here all end in a redirect
	 * carrying a message rather than a white screen on a phone in a rehearsal
	 * room.
	 *
	 * @return void
	 */
	public static function handle_claim() {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! is_user_logged_in() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'denied' ) ) );
			exit;
		}

		// The same gate the portal shell itself applies.
		if ( ! user_can( $user_id, 'ansp_view_portal' ) && ! ( class_exists( 'ANSP_Permissions' ) && ANSP_Permissions::is_manager( $user_id ) ) ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'denied' ) ) );
			exit;
		}

		check_admin_referer( self::NONCE );

		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$event_id   = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

		if ( ! $project_id || ! $event_id ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'badrequest' ) ) );
			exit;
		}

		if ( ! self::engine_available() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'noengine' ) ) );
			exit;
		}

		/*
		 * Re-derive everything from the project id rather than trusting the
		 * form. The remaining count especially: the page may have been open in
		 * a tab since this morning, and two tabs must not both spend the last
		 * claim.
		 */
		$claimable = self::get_claimable( $user_id );
		$project   = null;
		foreach ( $claimable as $row ) {
			if ( $row['project_id'] === $project_id ) {
				$project = $row;
				break;
			}
		}

		if ( ! $project ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'notallowed' ) ) );
			exit;
		}

		if ( $project['remaining'] < 1 ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'none_left' ) ) );
			exit;
		}

		$product_id = 0;
		$perf_title = '';
		foreach ( $project['performances'] as $perf ) {
			if ( (int) $perf['id'] === $event_id ) {
				$product_id = (int) $perf['product_id'];
				$perf_title = $perf['title'];
				break;
			}
		}

		if ( ! $product_id ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'noperformance' ) ) );
			exit;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'noemail' ) ) );
			exit;
		}

		$result = ans_comp_issue(
			array(
				'performance_id'   => $product_id,
				'qty'              => 1,
				'recipient_name'   => $user->display_name,
				'recipient_email'  => $user->user_email,
				'reason'           => sprintf(
					/* translators: 1: project title, 2: performance title. */
					__( 'Singer comp claimed from the portal - %1$s, %2$s', 'ans-singers-portal' ),
					get_the_title( $project_id ),
					$perf_title
				),
				'source'           => 'portal-claim',
				'issued_by'        => $user_id,
				'claimant_user_id' => $user_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => 'failed',
						'ansp_comp_msg' => rawurlencode( $result->get_error_message() ),
					)
				)
			);
			exit;
		}

		/*
		 * Stamp the project so this claim counts against the right allowance.
		 * If this write were to fail the singer would keep the ticket and the
		 * claim would not count - generous rather than punitive, and the order
		 * still carries the claimant and the reason text, so a ledger reader
		 * can still see what happened.
		 */
		if ( ! empty( $result['order_id'] ) ) {
			$order = wc_get_order( (int) $result['order_id'] );
			if ( $order ) {
				$order->update_meta_data( self::META_PROJECT, $project_id );
				$order->save();
			}
		}

		wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'claimed' ) ) );
		exit;
	}

	/**
	 * Human-readable result of the last claim, for the panel to show.
	 *
	 * @return array{type:string,text:string}|null
	 */
	public static function get_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flag on a redirect.
		if ( ! isset( $_GET['ansp_comp'] ) ) {
			return null;
		}
		$code = sanitize_key( wp_unslash( $_GET['ansp_comp'] ) );
		$msg  = isset( $_GET['ansp_comp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ansp_comp_msg'] ) ) : '';
		// phpcs:enable

		$map = array(
			'claimed'       => array( 'success', __( 'Your comp ticket is on its way - check your email for the PDF.', 'ans-singers-portal' ) ),
			'none_left'     => array( 'error', __( 'You have already claimed all of your comps for that project.', 'ans-singers-portal' ) ),
			'notallowed'    => array( 'error', __( 'You do not have a comp allowance for that project.', 'ans-singers-portal' ) ),
			'noperformance' => array( 'error', __( 'That performance is not available for a comp.', 'ans-singers-portal' ) ),
			'noemail'       => array( 'error', __( 'Your account has no valid email address, so a ticket could not be sent. Please contact the office.', 'ans-singers-portal' ) ),
			'noengine'      => array( 'error', __( 'Comp ticketing is not available on this site right now.', 'ans-singers-portal' ) ),
			'denied'        => array( 'error', __( 'You do not have access to claim comps.', 'ans-singers-portal' ) ),
			'badrequest'    => array( 'error', __( 'Something was missing from that request. Please try again.', 'ans-singers-portal' ) ),
		);

		if ( 'failed' === $code ) {
			return array(
				'type' => 'error',
				'text' => $msg
					? sprintf(
						/* translators: %s: error detail from the ticketing engine. */
						__( 'The ticket could not be issued: %s', 'ans-singers-portal' ),
						$msg
					)
					: __( 'The ticket could not be issued. Please contact the office.', 'ans-singers-portal' ),
			);
		}

		if ( ! isset( $map[ $code ] ) ) {
			return null;
		}

		return array( 'type' => $map[ $code ][0], 'text' => $map[ $code ][1] );
	}
}
