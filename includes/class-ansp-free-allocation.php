<?php
/**
 * Free ticket allocation, per PERFORMANCE, edited on the Project.
 *
 * Student and youth tickets went to $0 for the 2026/27 season (Kim, relayed by
 * Jonathan 2026-09-18). Free tickets with no ceiling are a room-capacity
 * problem, so each performance gets a hard TOTAL of free seats per tier.
 *
 * BLANK MEANS UNLIMITED. 0 MEANS NOBODY. Those are different answers and the
 * difference is load-bearing, which is why the value is handled as a STRING and
 * never cast through max( 0, (int) $v ).
 *
 * That is deliberately NOT what ANSP_Comp_Allowance does. The comp allowance
 * says "0 is a real answer, there is no unset state" and casts to int, so a
 * blank field there means zero comps. Copying that here would turn every
 * unfilled box into "no free tickets" and silently take the tier off sale on
 * every performance nobody had got to yet. ANSP_Event_Venue::get_capacity()
 * uses a THIRD convention (0 = not recorded = unlimited). Three sibling fields,
 * three conventions; this docblock exists so the next person does not assume.
 *
 * WHY THE NUMBER LIVES ON THE PERFORMANCE AND THE UI LIVES ON THE PROJECT.
 * A comp allowance is per production - "Darkness & Light is four events but one
 * comp allowance" (Jonathan, 2026-08-30). A free-seat cap is the opposite: each
 * night has its own room and its own WooCommerce product, and stock is per
 * product, so the number has to be per performance or it cannot be enforced at
 * all. The meta therefore sits on the tc_events post, exactly where
 * ANSP_Event_Venue puts its own per-performance field. But nobody wants to open
 * sixteen performance posts to set a season, so the EDITOR is one box on the
 * project, listing that production's performances. Storage per performance,
 * editing per project.
 *
 * WHAT IT DRIVES. WooCommerce `_stock` on that performance's ticket product for
 * that tier, found by `_ans_tier` meta and never by product name. There is no
 * second capacity field on this install - the ticketing HANDOFF settled that
 * against LIVE on 2026-09-07 - so `_stock` is the only lever there is.
 *
 * THE ARITHMETIC, AND THE BUG IT AVOIDS. The field is a TOTAL; `_stock` is
 * REMAINING. Stock is therefore always recomputed as ( total - already issued )
 * rather than read back, so re-saving the screen after six tickets have sold
 * does not quietly reset the allocation to its starting number. The computation
 * is idempotent: saving twice with no change produces the same stock twice.
 *
 * FAILS OPEN. No WooCommerce, no product, no tier match, or a blank field - all
 * leave stock management OFF, which is the state every one of these products is
 * in today. A members plugin must never be able to stop a public sale, which is
 * the same rule ANSP_Event_Venue states in its own header.
 *
 * @package ArsNovaSingersPortal
 * @since   1.41.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Free_Allocation
 */
class ANSP_Free_Allocation {

	/**
	 * The Tickera performance post type.
	 */
	const EVENT_TYPE = 'tc_events';

	/**
	 * Nonce action for the project-screen box.
	 */
	const NONCE = 'ansp_free_allocation_save';

	/**
	 * Meta keys on a tc_events post, one per concession tier.
	 *
	 * No leading underscore, deliberately, matching `ansp_event_venue` and
	 * `ans_event_kind`: Kim can see and correct the value in the Custom Fields
	 * panel without waiting on a plugin release.
	 *
	 * An ABSENT key and an EMPTY string both mean unlimited, and they mean it
	 * identically, so there is no third state to get wrong. A hard zero is
	 * stored as the string '0', which is why get_post_meta()'s return value is
	 * compared with '' rather than run through empty().
	 *
	 * @return array<string,string> tier slug => meta key
	 */
	public static function tiers() {
		return array(
			'student' => 'ansp_event_free_student',
			'youth'   => 'ansp_event_free_youth',
		);
	}

	/**
	 * Human labels for the two tiers, for the admin box only.
	 *
	 * @return array<string,string>
	 */
	public static function tier_labels() {
		return array(
			'student' => __( 'Student', 'ans-singers-portal' ),
			'youth'   => __( 'Youth (18 & under)', 'ans-singers-portal' ),
		);
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::project_type(), array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * The project post type, read from ANSP_CPT rather than hardcoded.
	 *
	 * @return string
	 */
	public static function project_type() {
		if ( class_exists( 'ANSP_CPT' ) && defined( 'ANSP_CPT::POST_TYPE' ) ) {
			return constant( 'ANSP_CPT::POST_TYPE' );
		}
		return 'ans_project';
	}

	/* ---------------------------------------------------------------------
	 * Read API - what the admin box, REST and the stock writer all call
	 * ------------------------------------------------------------------ */

	/**
	 * The free-ticket allocation for one performance and one tier.
	 *
	 * @param int    $event_id tc_events post ID.
	 * @param string $tier     'student' or 'youth'.
	 * @return int|null Seats allowed, or NULL for unlimited. 0 means nobody.
	 */
	public static function get_allocation( $event_id, $tier ) {
		$tiers = self::tiers();

		if ( ! isset( $tiers[ $tier ] ) ) {
			return null;
		}

		$raw = get_post_meta( (int) $event_id, $tiers[ $tier ], true );

		// '' and a missing key both mean unlimited, and mean it identically.
		// '0' is a real answer and must survive this check, so compare the
		// string rather than calling empty().
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}

		return max( 0, (int) $raw );
	}

	/**
	 * Store an allocation.
	 *
	 * @param int    $event_id tc_events post ID.
	 * @param string $tier     'student' or 'youth'.
	 * @param mixed  $value    Integer-ish for a cap; '' or null for unlimited.
	 * @return int|null What was stored, in get_allocation()'s vocabulary.
	 */
	public static function set_allocation( $event_id, $tier, $value ) {
		$tiers = self::tiers();

		if ( ! isset( $tiers[ $tier ] ) ) {
			return null;
		}

		$event_id = (int) $event_id;
		$key      = $tiers[ $tier ];

		if ( null === $value || '' === trim( (string) $value ) ) {
			delete_post_meta( $event_id, $key );
			self::sync_event( $event_id );
			return null;
		}

		$clean = max( 0, (int) $value );
		update_post_meta( $event_id, $key, (string) $clean );
		self::sync_event( $event_id );

		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * The ticket product behind a performance + tier
	 * ------------------------------------------------------------------ */

	/**
	 * The ticket product for one performance and one tier.
	 *
	 * Targeted by `_ans_tier` meta and NEVER by product name. Names carry the
	 * tier after an em-dash ('... - Oct 9, Mountain View - Student') and a
	 * rename would silently break the match; `_ans_tier` was backfilled in
	 * August precisely so tier stopped being a display string.
	 *
	 * @param int    $event_id tc_events post ID.
	 * @param string $tier     'student' or 'youth'.
	 * @return int Product ID, or 0.
	 */
	public static function product_for( $event_id, $tier ) {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => '_tc_is_ticket',
						'value' => 'yes',
					),
					array(
						'key'   => '_event_name',
						'value' => (string) (int) $event_id,
					),
					array(
						'key'   => '_ans_tier',
						'value' => $tier,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * How many of that product have actually been issued.
	 *
	 * WooCommerce's own `total_sales`, which it maintains across payment and
	 * refund status transitions. It is the same population the ticketing
	 * HANDOFF means by "tickets on completed orders" - deliberately NOT the
	 * count of ticket CODES, which is larger whenever a checkout fails, and
	 * which is not a capacity signal.
	 *
	 * @param WC_Product $product Product object.
	 * @return int
	 */
	public static function issued_count( $product ) {
		return max( 0, (int) $product->get_total_sales() );
	}

	/* ---------------------------------------------------------------------
	 * The stock writer
	 * ------------------------------------------------------------------ */

	/**
	 * Push both tiers' allocations for one performance into WooCommerce stock.
	 *
	 * Stock is RECOMPUTED, never read back: remaining = total - issued. That
	 * is what makes a no-op save a no-op. Reading the current `_stock` and
	 * treating it as the total would reset the allocation to its starting
	 * number every time somebody opened the screen and pressed Update.
	 *
	 * @param int $event_id tc_events post ID.
	 * @return array<string,array> Per-tier record of what was written.
	 */
	public static function sync_event( $event_id ) {
		$out = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $out;   // No WooCommerce: do nothing, block nothing.
		}

		foreach ( array_keys( self::tiers() ) as $tier ) {
			$allowed    = self::get_allocation( $event_id, $tier );
			$product_id = self::product_for( $event_id, $tier );

			if ( ! $product_id ) {
				$out[ $tier ] = array( 'product' => 0, 'note' => 'no product for this tier' );
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				$out[ $tier ] = array( 'product' => $product_id, 'note' => 'product would not load' );
				continue;
			}

			if ( null === $allowed ) {
				// Unlimited. Hand the product back to WooCommerce untouched:
				// stock management off is the state all 32 concession products
				// are in today, so this is a return to normal, not a new one.
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
				$product->set_stock_status( 'instock' );
				$product->save();

				$out[ $tier ] = array(
					'product'   => $product_id,
					'allocated' => null,
					'issued'    => self::issued_count( $product ),
					'stock'     => null,
					'note'      => 'unlimited',
				);
				continue;
			}

			$issued    = self::issued_count( $product );
			$remaining = max( 0, $allowed - $issued );

			$product->set_manage_stock( true );
			$product->set_backorders( 'no' );
			$product->set_stock_quantity( $remaining );
			$product->set_stock_status( $remaining > 0 ? 'instock' : 'outofstock' );
			$product->save();

			$out[ $tier ] = array(
				'product'   => $product_id,
				'allocated' => $allowed,
				'issued'    => $issued,
				'stock'     => $remaining,
				'note'      => $allowed < $issued ? 'allocation is below tickets already issued' : '',
			);
		}

		return $out;
	}

	/**
	 * Everything the UI and REST need to describe one performance.
	 *
	 * @param int $event_id tc_events post ID.
	 * @return array
	 */
	public static function describe_event( $event_id ) {
		$event_id = (int) $event_id;
		$rows     = array();

		foreach ( array_keys( self::tiers() ) as $tier ) {
			$allowed    = self::get_allocation( $event_id, $tier );
			$product_id = self::product_for( $event_id, $tier );
			$issued     = 0;

			if ( $product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				$issued  = $product ? self::issued_count( $product ) : 0;
			}

			$rows[ $tier ] = array(
				'allocated' => $allowed,
				'issued'    => $issued,
				'remaining' => null === $allowed ? null : max( 0, $allowed - $issued ),
				'product'   => $product_id,
			);
		}

		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * The project screen: one box, a row per performance
	 * ------------------------------------------------------------------ */

	/**
	 * Register the box on the project edit screen.
	 *
	 * Its own box rather than an edit to ANSP_Project_Meta, for the reason
	 * ANSP_Comp_Allowance gives: project-meta is the most contended file in
	 * this plugin, and the allowance is Kim's concern (ticketing) rather than
	 * Tom's (dates, venue, brief). Different owner, different box, own nonce.
	 *
	 * 'normal' context rather than 'side': this one is a table of performances,
	 * not a single number, and it does not fit the sidebar column.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ansp-free-allocation',
			__( 'Free ticket allocation', 'ans-singers-portal' ),
			array( __CLASS__, 'render' ),
			self::project_type(),
			'normal',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post The project.
	 * @return void
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		if ( ! class_exists( 'ANSP_Project_Ticketing' ) ) {
			echo '<p>' . esc_html__( 'Ticketing link unavailable, so this project\'s performances cannot be listed.', 'ans-singers-portal' ) . '</p>';
			return;
		}

		// false = include performances whose date has passed. Kim needs to see
		// the whole production, not only what is still upcoming.
		$performances = ANSP_Project_Ticketing::get_performances( $post->ID, false );

		if ( empty( $performances ) ) {
			echo '<p>' . esc_html__( 'No performances are linked to this project yet. Once its Tickera event category has performances, they will appear here.', 'ans-singers-portal' ) . '</p>';
			return;
		}

		$labels = self::tier_labels();

		echo '<p class="description">'
			. esc_html__( 'A hard total of free tickets for each performance. LEAVE BLANK FOR UNLIMITED. 0 means none at all — that is a different answer from blank, and both are kept.', 'ans-singers-portal' )
			. '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Performance', 'ans-singers-portal' ) . '</th>';

		foreach ( $labels as $label ) {
			echo '<th style="width:26%">' . esc_html( $label ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $performances as $p ) {
			$rows = self::describe_event( $p['id'] );

			echo '<tr><td><strong>' . esc_html( $p['title'] ) . '</strong>';

			if ( ! empty( $p['date'] ) ) {
				echo '<br /><span class="description">' . esc_html( $p['date'] );
				if ( ! empty( $p['venue'] ) ) {
					echo ' — ' . esc_html( $p['venue'] );
				}
				echo '</span>';
			}

			echo '</td>';

			foreach ( array_keys( $labels ) as $tier ) {
				self::render_cell( (int) $p['id'], $tier, $rows[ $tier ] );
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description">'
			. esc_html__( 'Saving recalculates each ticket product\'s stock as (total − already issued), so re-saving without changing anything does not reset an allocation that has been selling.', 'ans-singers-portal' )
			. '</p>';
	}

	/**
	 * One tier cell: the input, plus what is actually happening underneath it.
	 *
	 * The running count is printed because the field is a TOTAL while the
	 * thing it drives is a REMAINDER. Without it, somebody who has sold six
	 * seats out of twenty sees the number 20 and has no way to tell whether
	 * that means twenty left or twenty ever.
	 *
	 * @param int    $event_id tc_events post ID.
	 * @param string $tier     Tier slug.
	 * @param array  $row      One row of describe_event().
	 * @return void
	 */
	protected static function render_cell( $event_id, $tier, $row ) {
		$name = sprintf( 'ansp_free_alloc[%d][%s]', $event_id, $tier );

		echo '<td>';

		if ( empty( $row['product'] ) ) {
			echo '<span class="description">' . esc_html__( 'no ticket for this tier', 'ans-singers-portal' ) . '</span></td>';
			return;
		}

		printf(
			'<input type="number" min="0" step="1" name="%1$s" value="%2$s" class="small-text" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( null === $row['allocated'] ? '' : (string) $row['allocated'] ),
			esc_attr__( 'unlimited', 'ans-singers-portal' )
		);

		echo '<br /><span class="description">';

		if ( null === $row['allocated'] ) {
			printf(
				/* translators: %d: tickets already issued. */
				esc_html__( 'Unlimited · %d issued', 'ans-singers-portal' ),
				(int) $row['issued']
			);
		} else {
			printf(
				/* translators: 1: allocated total, 2: issued, 3: remaining. */
				esc_html__( '%1$d allocated · %2$d issued · %3$d remaining', 'ans-singers-portal' ),
				(int) $row['allocated'],
				(int) $row['issued'],
				(int) $row['remaining']
			);

			if ( $row['allocated'] < $row['issued'] ) {
				echo '<br /><strong style="color:#b32d2e">'
					. esc_html__( 'Below tickets already issued — no further free tickets will be available.', 'ans-singers-portal' )
					. '</strong>';
			} elseif ( 0 === (int) $row['remaining'] ) {
				echo '<br /><strong>' . esc_html__( 'Allocation exhausted — this tier is off sale.', 'ans-singers-portal' ) . '</strong>';
			}
		}

		echo '</span></td>';
	}

	/**
	 * Save the box.
	 *
	 * Verifies its OWN nonce and writes only its OWN keys, so it coexists with
	 * ANSP_Project_Meta's and ANSP_Comp_Allowance's handlers on the same post
	 * type. Note the keys it writes live on tc_events posts, not on the project
	 * being saved - the project screen is the editor, not the owner.
	 *
	 * @param int     $post_id Project ID.
	 * @param WP_Post $post    Project.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['ansp_free_alloc'] ) || ! is_array( $_POST['ansp_free_alloc'] ) ) {
			return;
		}

		$submitted = wp_unslash( $_POST['ansp_free_alloc'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per value below.
		$tiers     = self::tiers();

		foreach ( $submitted as $event_id => $values ) {
			$event_id = (int) $event_id;

			if ( $event_id <= 0 || ! is_array( $values ) ) {
				continue;
			}

			// Only accept performances that really belong to this project.
			// Without this the form could be pointed at any event on the site.
			if ( ! self::event_belongs_to_project( $event_id, (int) $post_id ) ) {
				continue;
			}

			foreach ( $values as $tier => $value ) {
				$tier = sanitize_key( $tier );

				if ( ! isset( $tiers[ $tier ] ) ) {
					continue;
				}

				self::set_allocation( $event_id, $tier, sanitize_text_field( (string) $value ) );
			}
		}
	}

	/**
	 * Is this performance really one of that project's?
	 *
	 * @param int $event_id   tc_events post ID.
	 * @param int $project_id Project ID.
	 * @return bool
	 */
	public static function event_belongs_to_project( $event_id, $project_id ) {
		if ( ! class_exists( 'ANSP_Project_Ticketing' ) ) {
			return false;
		}

		foreach ( ANSP_Project_Ticketing::get_performances( $project_id, false ) as $p ) {
			if ( (int) $p['id'] === (int) $event_id ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * REST - ars-nova/v1, admin only
	 * ------------------------------------------------------------------ */

	/**
	 * Namespace the connector can reach.
	 *
	 * @return string
	 */
	private static function ns() {
		if ( class_exists( 'ANSP_Rest' ) && defined( 'ANSP_Rest::NS' ) ) {
			return constant( 'ANSP_Rest::NS' );
		}
		return 'ars-nova/v1';
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		if ( class_exists( 'ANSP_Rest' ) && method_exists( 'ANSP_Rest', 'can_manage' ) ) {
			return (bool) ANSP_Rest::can_manage();
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Is this production?
	 *
	 * @return bool
	 */
	public static function is_production() {
		if ( class_exists( 'ANSP_Rest' ) && method_exists( 'ANSP_Rest', 'is_production' ) ) {
			return (bool) ANSP_Rest::is_production();
		}
		return false;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = self::ns();
		$auth = array( __CLASS__, 'can_manage' );

		register_rest_route(
			$ns,
			'/portal/free-allocations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			$ns,
			'/portal/project/(?P<id>\d+)/free-allocation',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_set' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	/**
	 * The whole allocation record for one project.
	 *
	 * @param int $project_id Project ID.
	 * @return array|null Null when the id is not a project.
	 */
	public static function get_record( $project_id ) {
		$post = get_post( (int) $project_id );

		if ( ! $post || self::project_type() !== $post->post_type ) {
			return null;
		}

		$performances = class_exists( 'ANSP_Project_Ticketing' )
			? ANSP_Project_Ticketing::get_performances( $post->ID, false )
			: array();

		$out = array();

		foreach ( $performances as $p ) {
			$out[] = array(
				'event'       => (int) $p['id'],
				'title'       => $p['title'],
				'date'        => isset( $p['date'] ) ? $p['date'] : '',
				'venue'       => isset( $p['venue'] ) ? $p['venue'] : '',
				'allocations' => self::describe_event( (int) $p['id'] ),
			);
		}

		return array(
			'project_id'   => (int) $post->ID,
			'project'      => $post->post_title,
			'performances' => $out,
			'note'         => 'allocated null means UNLIMITED. 0 means none. Those differ.',
		);
	}

	/**
	 * GET /portal/free-allocations
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function rest_list( $req ) {
		$ids = get_posts(
			array(
				'post_type'        => self::project_type(),
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$out = array();

		foreach ( $ids as $id ) {
			$record = self::get_record( $id );

			if ( $record ) {
				$out[] = $record;
			}
		}

		return rest_ensure_response(
			array(
				'site'     => home_url(),
				'count'    => count( $out ),
				'projects' => $out,
			)
		);
	}

	/**
	 * GET /portal/project/{id}/free-allocation
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $req ) {
		$record = self::get_record( (int) $req['id'] );

		if ( ! $record ) {
			return new WP_Error( 'ansp_free_allocation_not_found', 'No project with that id.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $record );
	}

	/**
	 * POST /portal/project/{id}/free-allocation
	 *
	 * Body: { allocations: { "<event_id>": { student: 20, youth: null } } }
	 * A null or empty-string value means unlimited; 0 means none.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_set( $req ) {
		$body = $req->get_json_params();

		if ( ! is_array( $body ) ) {
			$body = $req->get_params();
		}

		if ( self::is_production() && empty( $body['confirm_production'] ) ) {
			return new WP_Error(
				'ansp_free_allocation_confirm_production',
				'This is the production site. Pass confirm_production: true to write.',
				array( 'status' => 400 )
			);
		}

		$project_id = (int) $req['id'];

		if ( ! self::get_record( $project_id ) ) {
			return new WP_Error( 'ansp_free_allocation_not_found', 'No project with that id.', array( 'status' => 404 ) );
		}

		$tiers   = self::tiers();
		$applied = array();

		if ( isset( $body['allocations'] ) && is_array( $body['allocations'] ) ) {
			foreach ( $body['allocations'] as $event_id => $values ) {
				$event_id = (int) $event_id;

				if ( ! is_array( $values ) || ! self::event_belongs_to_project( $event_id, $project_id ) ) {
					continue;
				}

				foreach ( $values as $tier => $value ) {
					$tier = sanitize_key( $tier );

					if ( ! isset( $tiers[ $tier ] ) ) {
						continue;
					}

					self::set_allocation( $event_id, $tier, $value );
					$applied[] = $event_id . ':' . $tier;
				}
			}
		}

		// Read back rather than echoing the request - the read-back is the proof.
		return rest_ensure_response(
			array(
				'ok'      => true,
				'applied' => $applied,
				'project' => self::get_record( $project_id ),
			)
		);
	}
}
