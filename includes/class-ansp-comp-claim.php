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
			/*
			 * ARCHIVED IS THE PORTAL'S OWN "NOT ACTIVE" AND IT GOVERNS HERE TOO.
			 *
			 * 1.27.0 shipped without this check, which meant archiving a
			 * finished production did not stop singers claiming comps against
			 * it - the allowance stayed spendable on a concert that had already
			 * happened. Status is the switch Kim and Tom already use on the
			 * project edit screen; a second, comp-only notion of "still running"
			 * would drift from it the first time someone archived a project and
			 * reasonably expected everything about it to stop.
			 *
			 * Absent meta means active, matching tab-season-materials.php, so
			 * the two screens can never disagree about what is live.
			 */
			if ( class_exists( 'ANSP_Project_Meta' ) && ANSP_Project_Meta::is_archived( $pid ) ) {
				continue;
			}

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
	 * Every live comp order this singer has raised on this project.
	 *
	 * Cancelled and refunded orders are excluded, so voiding a comp gives the
	 * singer their allowance back - which is the whole reason Void exists.
	 *
	 * @param int $user_id
	 * @param int $project_id
	 * @return WC_Order[]
	 */
	public static function claimed_orders( $user_id, $project_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending' ),
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => '_ans_comp_claimant', 'value' => (int) $user_id ),
					array( 'key' => self::META_PROJECT, 'value' => (int) $project_id ),
				),
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * How many TICKETS this singer has already taken on this project.
	 *
	 * COUNTS TICKETS, NOT ORDERS, and that distinction is the whole point.
	 * 1.27.0 counted orders, which was harmless only because every claim was
	 * exactly one ticket. The moment a row can say "3 tickets for the Chens",
	 * counting orders makes an allowance of 2 mean nothing at all - three
	 * orders of four tickets each would read as 3 against a limit of 2 and
	 * still be under it on the fourth.
	 *
	 * get_item_count() is WooCommerce's sum of line-item quantities, which is
	 * the number of seats given away.
	 *
	 * @param int $user_id
	 * @param int $project_id
	 * @return int
	 */
	public static function claimed_count( $user_id, $project_id ) {
		$used = 0;
		foreach ( self::claimed_orders( $user_id, $project_id ) as $order ) {
			$used += (int) $order->get_item_count();
		}
		return $used;
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
	 * Handle a cart submission: several guests, each their own comp order.
	 *
	 * Post/Redirect/Get throughout: a browser refresh must not be able to issue
	 * a second set of tickets, and every failure ends in a redirect carrying a
	 * message rather than a white screen on a phone in a rehearsal room.
	 *
	 * VALIDATE EVERYTHING FIRST, THEN ISSUE. A bad address in row three must
	 * not leave rows one and two already sent - the singer would have no way
	 * to tell which had gone and would resubmit the lot.
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
		if ( ! $project_id ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'badrequest' ) ) );
			exit;
		}

		if ( ! self::engine_available() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'noengine' ) ) );
			exit;
		}

		/*
		 * Re-derive the project and its remaining allowance from the server
		 * rather than trusting the form. The page may have been open in a tab
		 * since this morning; two tabs must not both spend the last comp.
		 */
		$project = null;
		foreach ( self::get_claimable( $user_id ) as $row ) {
			if ( $row['project_id'] === $project_id ) {
				$project = $row;
				break;
			}
		}

		if ( ! $project ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'notallowed' ) ) );
			exit;
		}

		$rows = self::read_rows( $project );

		if ( empty( $rows['valid'] ) && empty( $rows['errors'] ) ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'norows' ) ) );
			exit;
		}

		if ( ! empty( $rows['errors'] ) ) {
			self::remember_rows( $project_id, $rows['raw'] );
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => 'invalid',
						'ansp_comp_msg' => rawurlencode( implode( ' ', $rows['errors'] ) ),
					)
				)
			);
			exit;
		}

		$wanted = 0;
		foreach ( $rows['valid'] as $row ) {
			$wanted += $row['qty'];
		}

		if ( $wanted > $project['remaining'] ) {
			self::remember_rows( $project_id, $rows['raw'] );
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => 'overallowance',
						'ansp_comp_msg' => rawurlencode(
							sprintf(
								/* translators: 1: tickets asked for, 2: tickets remaining. */
								__( 'That asks for %1$d tickets and you have %2$d left.', 'ans-singers-portal' ),
								$wanted,
								$project['remaining']
							)
						),
					)
				)
			);
			exit;
		}

		$user = get_userdata( $user_id );

		$issued = 0;
		$failed = array();

		foreach ( $rows['valid'] as $row ) {
			$result = ans_comp_issue(
				array(
					'performance_id'   => $row['product_id'],
					'qty'              => $row['qty'],
					'recipient_name'   => $row['name'],
					'recipient_email'  => $row['email'],
					'reason'           => sprintf(
						/* translators: 1: singer name, 2: project title, 3: performance title. */
						__( 'Singer comp claimed by %1$s - %2$s, %3$s', 'ans-singers-portal' ),
						$user ? $user->display_name : ( 'user ' . $user_id ),
						get_the_title( $project_id ),
						$row['perf_title']
					),
					'source'           => 'portal-claim',
					'issued_by'        => $user_id,
					'claimant_user_id' => $user_id,
				)
			);

			if ( is_wp_error( $result ) ) {
				$failed[] = $row['name'] . ': ' . $result->get_error_message();
				continue;
			}

			$issued += $row['qty'];

			/*
			 * Stamp the project so this comp counts against the right
			 * allowance. If this write failed the guest would keep the ticket
			 * and the count would not move - generous rather than punitive,
			 * and the order still carries the claimant and the reason text.
			 */
			if ( ! empty( $result['order_id'] ) ) {
				$order = wc_get_order( (int) $result['order_id'] );
				if ( $order ) {
					$order->update_meta_data( self::META_PROJECT, $project_id );
					$order->save();
				}
			}
		}

		if ( $failed ) {
			/*
			 * Partial success is reported as partial. Saying "done" when two of
			 * three went out is the kind of lie that gets found at the door.
			 */
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => $issued ? 'partial' : 'failed',
						'ansp_comp_n'   => $issued,
						'ansp_comp_msg' => rawurlencode( implode( ' | ', $failed ) ),
					)
				)
			);
			exit;
		}

		wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'claimed', 'ansp_comp_n' => $issued ) ) );
		exit;
	}

	/**
	 * Read, sanitise and validate the submitted cart rows.
	 *
	 * Returns valid rows resolved to a ticket product, a list of human errors,
	 * and the raw input so a rejected cart can be handed back to the singer
	 * with their typing intact.
	 *
	 * @param array $project A row from get_claimable().
	 * @return array{valid:array,errors:array,raw:array}
	 */
	protected static function read_rows( $project ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller ran check_admin_referer.
		$names  = isset( $_POST['guest_name'] ) ? (array) wp_unslash( $_POST['guest_name'] ) : array();
		$emails = isset( $_POST['guest_email'] ) ? (array) wp_unslash( $_POST['guest_email'] ) : array();
		$events = isset( $_POST['guest_event'] ) ? (array) wp_unslash( $_POST['guest_event'] ) : array();
		$qtys   = isset( $_POST['guest_qty'] ) ? (array) wp_unslash( $_POST['guest_qty'] ) : array();
		// phpcs:enable

		$valid  = array();
		$errors = array();
		$raw    = array();

		$count = max( count( $names ), count( $emails ), count( $events ), count( $qtys ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$name  = isset( $names[ $i ] ) ? sanitize_text_field( (string) $names[ $i ] ) : '';
			$email = isset( $emails[ $i ] ) ? sanitize_email( (string) $emails[ $i ] ) : '';
			$event = isset( $events[ $i ] ) ? (int) $events[ $i ] : 0;
			$qty   = isset( $qtys[ $i ] ) ? (int) $qtys[ $i ] : 1;

			// A wholly blank line is the spare row nobody filled in, not an error.
			if ( '' === $name && '' === $email ) {
				continue;
			}

			$raw[] = array( 'name' => $name, 'email' => $email, 'event_id' => $event, 'qty' => $qty );
			$label = '' !== $name ? $name : __( 'a guest', 'ans-singers-portal' );

			if ( '' === $name ) {
				$errors[] = sprintf(
					/* translators: %s: the email address entered. */
					__( 'A name is needed for %s.', 'ans-singers-portal' ),
					$email
				);
				continue;
			}

			if ( ! is_email( $email ) ) {
				$errors[] = sprintf(
					/* translators: %s: guest name. */
					__( '%s needs a valid email address - that is where the ticket goes.', 'ans-singers-portal' ),
					$label
				);
				continue;
			}

			if ( $qty < 1 ) {
				$errors[] = sprintf(
					/* translators: %s: guest name. */
					__( '%s needs at least one ticket.', 'ans-singers-portal' ),
					$label
				);
				continue;
			}

			$product_id = 0;
			$perf_title = '';
			foreach ( $project['performances'] as $perf ) {
				if ( (int) $perf['id'] === $event ) {
					$product_id = (int) $perf['product_id'];
					$perf_title = $perf['title'];
					break;
				}
			}

			if ( ! $product_id ) {
				$errors[] = sprintf(
					/* translators: %s: guest name. */
					__( 'Pick a performance for %s.', 'ans-singers-portal' ),
					$label
				);
				continue;
			}

			$valid[] = array(
				'name'       => $name,
				'email'      => $email,
				'event_id'   => $event,
				'qty'        => $qty,
				'product_id' => $product_id,
				'perf_title' => $perf_title,
			);
		}

		return array( 'valid' => $valid, 'errors' => $errors, 'raw' => $raw );
	}

	/**
	 * Hold a rejected cart so the singer gets their typing back.
	 *
	 * A transient keyed to the user, not a query string: guest names and email
	 * addresses are other people's personal data and have no business in a URL,
	 * a browser history or a server log.
	 *
	 * @param int   $project_id
	 * @param array $rows
	 * @return void
	 */
	protected static function remember_rows( $project_id, $rows ) {
		set_transient( 'ansp_comp_rows_' . get_current_user_id(), array( (int) $project_id => $rows ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Take back a rejected cart, once.
	 *
	 * @return array project_id => rows
	 */
	public static function get_returned_rows() {
		$key  = 'ansp_comp_rows_' . get_current_user_id();
		$rows = get_transient( $key );
		if ( ! $rows ) {
			return array();
		}
		delete_transient( $key );
		return is_array( $rows ) ? $rows : array();
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

		$n = isset( $_GET['ansp_comp_n'] ) ? (int) $_GET['ansp_comp_n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.

		if ( 'claimed' === $code ) {
			return array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: %d: number of tickets issued. */
					_n(
						'%d comp ticket is on its way - your guest will get it by email.',
						'%d comp tickets are on their way - your guests will get them by email.',
						max( 1, $n ),
						'ans-singers-portal'
					),
					max( 1, $n )
				),
			);
		}

		if ( 'partial' === $code ) {
			return array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: 1: how many were issued, 2: the failures. */
					__( 'Only %1$d went out. The rest did not: %2$s', 'ans-singers-portal' ),
					$n,
					$msg
				),
			);
		}

		if ( in_array( $code, array( 'invalid', 'overallowance' ), true ) && $msg ) {
			return array( 'type' => 'error', 'text' => $msg );
		}

		$map = array(
			'none_left'     => array( 'error', __( 'You have already used all of your comps for that project.', 'ans-singers-portal' ) ),
			'notallowed'    => array( 'error', __( 'You do not have a comp allowance for that project.', 'ans-singers-portal' ) ),
			'noperformance' => array( 'error', __( 'That performance is not available for a comp.', 'ans-singers-portal' ) ),
			'norows'        => array( 'error', __( 'Add at least one guest before issuing.', 'ans-singers-portal' ) ),
			'invalid'       => array( 'error', __( 'Some rows need fixing before anything can be sent.', 'ans-singers-portal' ) ),
			'overallowance' => array( 'error', __( 'That is more tickets than you have left.', 'ans-singers-portal' ) ),
			'noengine'      => array( 'error', __( 'Comp ticketing is not available on this site right now.', 'ans-singers-portal' ) ),
			'denied'        => array( 'error', __( 'You do not have access to issue comps.', 'ans-singers-portal' ) ),
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
