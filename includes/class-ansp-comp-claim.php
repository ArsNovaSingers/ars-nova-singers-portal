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
	 * Order meta: which PERFORMANCE (tc_events post) the comp was for.
	 *
	 * The engine records the ticket PRODUCT, because a product is what a comp
	 * is issued against. The ledger has to say "Friday the 9th" rather than
	 * "Adult ticket", and deriving the event back out of the product means
	 * re-walking the two ticket-meta conventions that have caught this codebase
	 * three times already. Recording it once at claim time is the cheap end of
	 * that trade; ledger_row() still falls back to the ticket instance so comps
	 * issued before 1.29.0 name their night too.
	 */
	const META_EVENT = '_ans_comp_event';

	/**
	 * Order meta: mysql timestamps of every resend, oldest first.
	 */
	const META_RESENT = '_ans_comp_resent';

	/**
	 * How many times a singer may resend one comp before the address is the
	 * problem and sending it again a sixth time will not fix it.
	 */
	const RESEND_MAX = 5;

	/**
	 * Seconds between resends of the same comp. Stops a double tap on a phone
	 * putting two identical emails in a guest's inbox.
	 */
	const RESEND_COOLDOWN = 60;

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
	 * Nonce action for the ledger's per-comp fix-and-resend form.
	 */
	const NONCE_MANAGE = 'ansp_manage_comp';

	/**
	 * admin-post action name for the ledger.
	 */
	const ACTION_MANAGE = 'ansp_manage_comp';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'ansp_portal_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_claim' ) );
		add_action( 'admin_post_' . self::ACTION_MANAGE, array( __CLASS__, 'handle_manage' ) );
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

		/*
		 * 1.29.0 widened this. Before the ledger existed, "nothing to claim"
		 * and "nothing to see" were the same thing. They are not any more: a
		 * singer who has spent both comps still needs the tab, because the
		 * tickets they already sent - and the button to resend one to a guest
		 * who says it never arrived - live behind it. Hiding the tab at the
		 * exact moment the ledger becomes the only useful thing on it would be
		 * the worst possible timing.
		 */
		if ( ! self::get_claimable( get_current_user_id() ) && ! self::get_ledger( get_current_user_id() ) ) {
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
	 * @param int $project_id 0 for every project - what the ledger asks for.
	 * @return WC_Order[]
	 */
	public static function claimed_orders( $user_id, $project_id = 0 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$meta = array(
			'relation' => 'AND',
			array( 'key' => '_ans_comp_claimant', 'value' => (int) $user_id ),
		);

		/*
		 * A project id narrows this to one allowance, which is what the count
		 * needs. The LEDGER deliberately passes 0: a comp sent against a
		 * production that has since been archived is still a comp this singer
		 * sent, and the guest may still write to ask where their ticket went.
		 */
		if ( $project_id ) {
			$meta[] = array( 'key' => self::META_PROJECT, 'value' => (int) $project_id );
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending' ),
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => $meta,
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

					/*
					 * The guest-facing message, if the singer wrote one. NOT
					 * the reason above: reason is the internal record of why a
					 * comp was given and is read by the office; this is a note
					 * TO the person receiving it, in the singer's own words.
					 * Passing '' is the same as not passing it - the engine
					 * stores nothing and the email prints nothing.
					 */
					'recipient_note'   => $row['note'],
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
					$order->update_meta_data( self::META_EVENT, (int) $row['event_id'] );
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
		$notes  = isset( $_POST['guest_note'] ) ? (array) wp_unslash( $_POST['guest_note'] ) : array();
		// phpcs:enable

		$valid  = array();
		$errors = array();
		$raw    = array();

		$count = max( count( $names ), count( $emails ), count( $events ), count( $qtys ), count( $notes ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$name  = isset( $names[ $i ] ) ? sanitize_text_field( (string) $names[ $i ] ) : '';
			$email = isset( $emails[ $i ] ) ? sanitize_email( (string) $emails[ $i ] ) : '';
			$event = isset( $events[ $i ] ) ? (int) $events[ $i ] : 0;
			$qty   = isset( $qtys[ $i ] ) ? (int) $qtys[ $i ] : 1;
			$note  = isset( $notes[ $i ] ) ? self::clean_note( (string) $notes[ $i ] ) : '';

			/*
			 * A wholly blank line is the spare row nobody filled in, not an
			 * error. A note alone does not rescue it: a message with nobody to
			 * send it to is still an empty row, and treating it as a real one
			 * would produce "a name is needed for" with no address to name.
			 */
			if ( '' === $name && '' === $email ) {
				continue;
			}

			$raw[] = array( 'name' => $name, 'email' => $email, 'event_id' => $event, 'qty' => $qty, 'note' => $note );
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
				'note'       => $note,
				'product_id' => $product_id,
				'perf_title' => $perf_title,
			);
		}

		return array( 'valid' => $valid, 'errors' => $errors, 'raw' => $raw );
	}

	/**
	 * Tidy an optional note to a guest.
	 *
	 * Kim's request, 2026-08-30, for both her admin screen and this one. The
	 * engine sanitises and length-caps it again on the way in and escapes it
	 * again on the way out - three passes on one field, deliberately, because
	 * this is free text written by a singer, mailed to an address outside the
	 * organisation, and read by nobody in between.
	 *
	 * ANS_COMP_NOTE_MAX is the engine's own limit. Reading it rather than
	 * restating it means the two cannot drift; when the engine is not loaded
	 * this method is not reachable anyway, because the tab is gone.
	 *
	 * @param string $note
	 * @return string
	 */
	protected static function clean_note( $note ) {
		$note = sanitize_textarea_field( $note );
		$max  = defined( 'ANS_COMP_NOTE_MAX' ) ? (int) ANS_COMP_NOTE_MAX : 500;

		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $note, 0, $max ) );
		}
		return trim( substr( $note, 0, $max ) );
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

	/* ---------------------------------------------------------------------
	 * "My Comps" - what this singer already sent
	 *
	 * WHAT THIS CAN AND CANNOT KNOW, because the columns must not overclaim.
	 *
	 *   SENT      - certain. The order exists and WooCommerce mailed it.
	 *   RESENT    - certain. We recorded every resend ourselves.
	 *   AT THE DOOR - certain. Tickera stamps `tc_checkins` on the ticket
	 *                 instance when it is scanned, so a Pass there is a person
	 *                 who actually walked in.
	 *   RECEIVED / OPENED - NOT KNOWABLE HERE, and so it is not a column.
	 *                 Jonathan asked for "whether received and used". Used we
	 *                 have. Received we do not: wp_mail returns whether the
	 *                 message was handed to the mailer, which is not whether it
	 *                 arrived and is certainly not whether anyone read it. That
	 *                 needs delivery-tracking (Mandrill, or sending through
	 *                 Mailchimp) and is step 3. A green "received" tick derived
	 *                 from wp_mail's return value would be a comfortable lie,
	 *                 and the day it mattered - a guest at the door with no
	 *                 ticket - it would be the reason nobody looked further.
	 * ------------------------------------------------------------------ */

	/**
	 * Every comp this singer has sent, newest first.
	 *
	 * @param int $user_id
	 * @return array<int,array>
	 */
	public static function get_ledger( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( $user_id <= 0 || ! self::engine_available() ) {
			return array();
		}

		$rows = array();
		foreach ( self::claimed_orders( $user_id, 0 ) as $order ) {
			$rows[] = self::ledger_row( $order );
		}

		return $rows;
	}

	/**
	 * One ledger line, read from the order and its tickets.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected static function ledger_row( $order ) {
		$order_id = (int) $order->get_id();

		$tickets = self::order_ticket_ids( $order_id );
		$used    = 0;
		foreach ( $tickets as $tid ) {
			if ( self::is_checked_in( $tid ) ) {
				$used++;
			}
		}

		$resends = $order->get_meta( self::META_RESENT );
		$resends = is_array( $resends ) ? $resends : array();

		$project_id = (int) $order->get_meta( self::META_PROJECT );
		$perf       = self::order_performance( $order, $tickets );

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		return array(
			'order_id'    => $order_id,
			'guest'       => $name,
			'email'       => (string) $order->get_billing_email(),
			'qty'         => (int) $order->get_item_count(),
			'project_id'  => $project_id,
			'project'     => $project_id ? get_the_title( $project_id ) : '',
			'performance' => $perf['title'],
			'when'        => $perf['ts'],
			'location'    => $perf['location'],
			'note'        => (string) $order->get_meta( '_ans_comp_note' ),
			'sent'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'resends'     => count( $resends ),
			'last_resent' => $resends ? strtotime( (string) end( $resends ) ) : 0,
			'tickets'     => count( $tickets ),
			'used'        => $used,
			'can_resend'  => count( $resends ) < self::RESEND_MAX,
		);
	}

	/**
	 * The Tickera ticket instances belonging to an order.
	 *
	 * Trashed instances are excluded deliberately: voiding a comp trashes its
	 * tickets, and a voided comp must not read as a live one in the ledger.
	 *
	 * @param int $order_id
	 * @return int[]
	 */
	protected static function order_ticket_ids( $order_id ) {
		if ( ! post_type_exists( 'tc_tickets_instances' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'     => 'tc_tickets_instances',
				'post_parent'   => (int) $order_id,
				'post_status'   => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'   => 50,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Has this ticket been scanned at the door?
	 *
	 * Prefers the ticketing bridge's own reader so a fix to the check-in shape
	 * lands in one place. The inline fallback is the same read - `tc_checkins`
	 * is a list of attempts and only a 'Pass' is an admission - and exists so
	 * the ledger degrades to "not yet" rather than fatalling if the bridge is
	 * ever deactivated.
	 *
	 * @param int $ticket_id
	 * @return bool
	 */
	protected static function is_checked_in( $ticket_id ) {
		if ( function_exists( 'ans_rvt_is_checked_in' ) ) {
			return (bool) ans_rvt_is_checked_in( $ticket_id );
		}

		$checkins = get_post_meta( (int) $ticket_id, 'tc_checkins', true );
		if ( empty( $checkins ) || ! is_array( $checkins ) ) {
			return false;
		}

		foreach ( $checkins as $c ) {
			if ( is_array( $c ) && isset( $c['status'] ) && 'Pass' === $c['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which night this comp is for.
	 *
	 * @param WC_Order $order
	 * @param int[]    $tickets Already-loaded instance ids, to avoid a second query.
	 * @return array{title:string,ts:int,location:string}
	 */
	protected static function order_performance( $order, $tickets = array() ) {
		$event_id = (int) $order->get_meta( self::META_EVENT );

		/*
		 * Comps issued before 1.29.0 carry no event id, and there are real ones
		 * on staging. The ticket instance knows - `event_id` on the instance is
		 * the performance, which is the one thing on it that is not ambiguous.
		 */
		if ( ! $event_id ) {
			foreach ( $tickets as $tid ) {
				$event_id = (int) get_post_meta( $tid, 'event_id', true );
				if ( $event_id ) {
					break;
				}
			}
		}

		if ( ! $event_id || ! get_post( $event_id ) ) {
			return array( 'title' => '', 'ts' => 0, 'location' => '' );
		}

		$when = (string) get_post_meta( $event_id, 'event_date_time', true );

		return array(
			'title'    => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
			'ts'       => $when ? (int) strtotime( $when ) : 0,
			'location' => (string) get_post_meta( $event_id, 'event_location', true ),
		);
	}

	/**
	 * The order behind a ledger action, or null if it is not this singer's.
	 *
	 * THE WHOLE SECURITY BOUNDARY OF THE LEDGER IS THIS METHOD. Order ids are
	 * sequential and guessable, and both ledger actions take one from a form.
	 * Without the claimant check any singer could read a guest's email address
	 * off someone else's comp, change it, and have the ticket mailed to
	 * themselves. Nonce and capability are necessary and nowhere near
	 * sufficient - every singer holds both.
	 *
	 * @param int $order_id
	 * @param int $user_id
	 * @return WC_Order|null
	 */
	protected static function owned_order( $order_id, $user_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return null;
		}

		if ( 'yes' !== $order->get_meta( '_ans_comp' ) ) {
			return null; // Not a comp at all. A singer has no business here.
		}

		if ( (int) $order->get_meta( '_ans_comp_claimant' ) !== (int) $user_id ) {
			return null;
		}

		return $order;
	}

	/**
	 * Fix a guest's details, resend their ticket, or both.
	 *
	 * WHY A CORRECTED EMAIL RESENDS ITSELF. Jonathan's ask was "a way to send
	 * reminder email which sends it again with the ability to EDIT the email in
	 * case the singer entered the wrong email the first time". Someone fixing a
	 * typo has already decided the ticket should go; making them save, then
	 * find the row again, then press a second button invites them to stop after
	 * the first step and believe the guest now has a ticket. So changing the
	 * ADDRESS sends. Changing only the name does not - the ticket already went
	 * to the right inbox and a second copy would only confuse.
	 *
	 * @return void
	 */
	public static function handle_manage() {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! is_user_logged_in() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'denied' ) ) );
			exit;
		}

		if ( ! user_can( $user_id, 'ansp_view_portal' ) && ! ( class_exists( 'ANSP_Permissions' ) && ANSP_Permissions::is_manager( $user_id ) ) ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'denied' ) ) );
			exit;
		}

		check_admin_referer( self::NONCE_MANAGE );

		if ( ! self::engine_available() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'noengine' ) ) );
			exit;
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = self::owned_order( $order_id, $user_id );

		if ( ! $order ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'notyours' ) ) );
			exit;
		}

		$mode = isset( $_POST['ansp_comp_mode'] ) ? sanitize_key( wp_unslash( $_POST['ansp_comp_mode'] ) ) : 'resend';

		if ( 'update' === $mode ) {
			self::do_update( $order );
			exit;
		}

		self::do_resend( $order, 'resent' );
		exit;
	}

	/**
	 * Apply a details correction, and resend when the address moved.
	 *
	 * @param WC_Order $order
	 * @return void  Always redirects.
	 */
	protected static function do_update( $order ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller ran check_admin_referer.
		$name  = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';
		$email = isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '';
		// phpcs:enable

		if ( '' === $name ) {
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => 'invalid',
						'ansp_comp_msg' => rawurlencode( __( 'A guest still needs a name.', 'ans-singers-portal' ) ),
					)
				)
			);
			exit;
		}

		if ( ! is_email( $email ) ) {
			wp_safe_redirect(
				self::redirect_url(
					array(
						'ansp_comp'     => 'invalid',
						'ansp_comp_msg' => rawurlencode( __( 'That does not look like an email address - the ticket has nowhere to go.', 'ans-singers-portal' ) ),
					)
				)
			);
			exit;
		}

		$was = strtolower( (string) $order->get_billing_email() );

		$parts = explode( ' ', $name, 2 );
		$order->set_billing_first_name( $parts[0] );
		$order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
		$order->set_billing_email( $email );

		$order->add_order_note(
			sprintf(
				/* translators: 1: previous email, 2: new email. */
				__( 'Singer corrected the comp recipient: %1$s to %2$s', 'ans-singers-portal' ),
				$was ? $was : __( '(none)', 'ans-singers-portal' ),
				$email
			)
		);
		$order->save();

		/*
		 * The ticket PDF itself needs no reissue. It carries "Comp from
		 * <singer>" rather than the guest's name - which is exactly why that
		 * was the right thing to print on it - so a corrected name changes
		 * nothing about the ticket, only about who it is addressed to.
		 */
		if ( $was !== strtolower( $email ) ) {
			self::do_resend( $order, 'fixed_and_sent' );
			exit;
		}

		wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'fixed' ) ) );
		exit;
	}

	/**
	 * Send the completed-order email again - the one carrying the ticket PDFs.
	 *
	 * WooCommerce's own admin "resend order emails" does exactly this: fetch
	 * the mailer's email objects and trigger one by class. The
	 * woocommerce_before/after_resend_order_emails actions are fired too,
	 * because that is where the Tickera bridge and anything else listens for
	 * "this is a resend, not a new order".
	 *
	 * @param WC_Order $order
	 * @param string   $success_code Notice code on success.
	 * @return void  Always redirects.
	 */
	protected static function do_resend( $order, $success_code = 'resent' ) {
		$log = $order->get_meta( self::META_RESENT );
		$log = is_array( $log ) ? $log : array();

		if ( count( $log ) >= self::RESEND_MAX ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'resend_max' ) ) );
			exit;
		}

		$last = $log ? (int) strtotime( (string) end( $log ) ) : 0;
		if ( $last && ( time() - $last ) < self::RESEND_COOLDOWN ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'resend_wait' ) ) );
			exit;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'resend_failed' ) ) );
			exit;
		}

		$emails = WC()->mailer()->get_emails();
		if ( empty( $emails['WC_Email_Customer_Completed_Order'] ) ) {
			wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => 'resend_failed' ) ) );
			exit;
		}

		do_action( 'woocommerce_before_resend_order_emails', $order, 'customer_completed_order' );
		$emails['WC_Email_Customer_Completed_Order']->trigger( $order->get_id() );
		do_action( 'woocommerce_after_resend_order_email', $order, 'customer_completed_order' );

		$log[] = current_time( 'mysql' );
		$order->update_meta_data( self::META_RESENT, $log );
		$order->add_order_note(
			sprintf(
				/* translators: %s: recipient email address. */
				__( 'Singer resent the comp ticket to %s', 'ans-singers-portal' ),
				$order->get_billing_email()
			)
		);
		$order->save();

		wp_safe_redirect( self::redirect_url( array( 'ansp_comp' => $success_code ) ) );
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
			'resent'         => array( 'success', __( 'Sent again. It is on its way to the same address.', 'ans-singers-portal' ) ),
			'fixed'          => array( 'success', __( 'Details updated. The ticket had already gone to that address, so nothing was sent again.', 'ans-singers-portal' ) ),
			'fixed_and_sent' => array( 'success', __( 'Address corrected and the ticket sent to the new one.', 'ans-singers-portal' ) ),
			'resend_wait'    => array( 'error', __( 'That one has just gone out. Give it a minute before sending again.', 'ans-singers-portal' ) ),
			'resend_max'     => array( 'error', __( 'That comp has been sent as many times as it can be. If it is still not arriving the address is probably wrong - fix it here, or ask the office.', 'ans-singers-portal' ) ),
			'resend_failed'  => array( 'error', __( 'The email could not be sent again. Please tell the office.', 'ans-singers-portal' ) ),
			'notyours'       => array( 'error', __( 'That comp is not one of yours.', 'ans-singers-portal' ) ),
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
