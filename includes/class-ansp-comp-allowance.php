<?php
/**
 * Comps per singer, set on the Project.
 *
 * Season Wiki step 2. Settled with Jonathan 2026-08-25:
 * "Comps per singer, per project: set manually by Kim, ON THE PROJECT, not on a
 * new page."
 *
 * THERE ARE TWO DOORS ON ONE ENGINE, and this is the second one:
 *
 *   1. ans-comp-tickets' admin screen - Kim names a person and issues one or
 *      more comps. One-off: a donor, a reviewer, a guest of the composer.
 *   2. THIS - Kim sets a number per singer on the Project, and singers claim
 *      their own from the portal. Group allocation, no per-person work.
 *
 * The comp plugin AUTHORS NOTHING. It asks the Project how many comps a singer
 * gets and the Performance's Venue how many seats exist. That is what stops it
 * being a second system with its own drifting copy of the numbers.
 *
 * WHY A SEPARATE META BOX RATHER THAN EDITING ANSP_Project_Meta.
 * The spec says "one field in the box Tom already opens". This registers its own
 * box on the same edit screen instead, for two reasons. First, collisions:
 * class-ansp-project-meta.php is the most contended file in this plugin - 1.24.0
 * changed the project edit screen the same day this was written. Second, and
 * independent of that: the allowance is KIM's concern (ticketing) while Project
 * Details is TOM's (dates, venue, brief). Different owner, different box. Kim
 * still finds it on the screen she expects.
 *
 * Two save_post handlers on one post type is fine - each verifies its own nonce
 * and writes only its own keys.
 *
 * NOT HERE, deliberately: claim tracking and the singer-facing claim panel.
 * Those are step 3b. This gives the allowance a home; it does not yet give
 * anyone a button.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Comp_Allowance
 */
class ANSP_Comp_Allowance {

	/**
	 * Meta key holding the per-singer comp allowance for a project.
	 */
	const META_KEY = 'ansp_project_comps_per_singer';

	/**
	 * Meta key for Kim's optional note about the allowance.
	 */
	const META_NOTE = 'ansp_project_comp_note';

	/**
	 * Nonce action.
	 */
	const NONCE = 'ansp_save_comp_allowance';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::post_type(), array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * The project post type, read from ANSP_CPT rather than hardcoded.
	 *
	 * @return string
	 */
	public static function post_type() {
		if ( class_exists( 'ANSP_CPT' ) && defined( 'ANSP_CPT::POST_TYPE' ) ) {
			return constant( 'ANSP_CPT::POST_TYPE' );
		}
		return 'ans_project';
	}

	/* ---------------------------------------------------------------------
	 * Read API - what ans-comp-tickets calls
	 * ------------------------------------------------------------------ */

	/**
	 * How many comps each singer gets on this project.
	 *
	 * 0 IS A REAL ANSWER meaning "no comps on this project". It never means
	 * "not configured" - there is no such state, because an unset field and a
	 * deliberate zero should behave identically at the point of issue.
	 *
	 * @param int $project_id
	 * @return int Zero or more.
	 */
	public static function get_allowance( $project_id ) {
		$project_id = (int) $project_id;
		if ( $project_id <= 0 ) {
			return 0;
		}

		$post = get_post( $project_id );
		if ( ! $post || self::post_type() !== $post->post_type ) {
			return 0;
		}

		return max( 0, (int) get_post_meta( $project_id, self::META_KEY, true ) );
	}

	/**
	 * Kim's note about the allowance, if any.
	 *
	 * @param int $project_id
	 * @return string
	 */
	public static function get_note( $project_id ) {
		return (string) get_post_meta( (int) $project_id, self::META_NOTE, true );
	}

	/**
	 * The whole allowance record for a project.
	 *
	 * @param int $project_id
	 * @return array|null Null when the id is not a project.
	 */
	public static function get_record( $project_id ) {
		$post = get_post( (int) $project_id );
		if ( ! $post || self::post_type() !== $post->post_type ) {
			return null;
		}

		return array(
			'project_id'        => (int) $post->ID,
			'project'           => $post->post_title,
			'comps_per_singer'  => self::get_allowance( $post->ID ),
			'note'              => self::get_note( $post->ID ),
		);
	}

	/**
	 * Write the allowance. Single place the value is sanitised, so the meta box
	 * and REST can never disagree about what is valid.
	 *
	 * @param int   $project_id
	 * @param mixed $value
	 * @return int The stored value.
	 */
	public static function set_allowance( $project_id, $value ) {
		$clean = max( 0, (int) $value );
		update_post_meta( (int) $project_id, self::META_KEY, $clean );
		return $clean;
	}

	/**
	 * Write the note.
	 *
	 * @param int   $project_id
	 * @param mixed $value
	 * @return string
	 */
	public static function set_note( $project_id, $value ) {
		$clean = sanitize_text_field( (string) $value );
		update_post_meta( (int) $project_id, self::META_NOTE, $clean );
		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------ */

	/**
	 * Register the box on the project edit screen.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ansp-comp-allowance',
			__( 'Comp tickets', 'ans-singers-portal' ),
			array( __CLASS__, 'render' ),
			self::post_type(),
			'side',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		$allowance = self::get_allowance( $post->ID );
		$note      = self::get_note( $post->ID );

		echo '<p><label for="' . esc_attr( self::META_KEY ) . '"><strong>'
			. esc_html__( 'Comp tickets per singer', 'ans-singers-portal' )
			. '</strong></label><br />';

		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%1$s" value="%2$s" class="small-text" />',
			esc_attr( self::META_KEY ),
			esc_attr( (string) $allowance )
		);
		echo '</p>';

		echo '<p class="description">'
			. esc_html__( 'How many complimentary tickets each singer may claim for this project. 0 means none - that is a real answer, not an empty field.', 'ans-singers-portal' )
			. '</p>';

		echo '<p><label for="' . esc_attr( self::META_NOTE ) . '">'
			. esc_html__( 'Note (optional)', 'ans-singers-portal' )
			. '</label><br />';

		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="widefat" placeholder="%3$s" />',
			esc_attr( self::META_NOTE ),
			esc_attr( $note ),
			esc_attr__( 'e.g. Opening night only', 'ans-singers-portal' )
		);
		echo '</p>';

		echo '<p class="description">'
			. esc_html__( 'Singers cannot claim these yet - the claim panel is not built. Setting a number now is safe and is what it will read.', 'ans-singers-portal' )
			. '</p>';
	}

	/**
	 * Save the box.
	 *
	 * Verifies its OWN nonce and writes only its OWN keys, so it coexists with
	 * ANSP_Project_Meta's handler on the same post type.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
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

		if ( isset( $_POST[ self::META_KEY ] ) ) {
			self::set_allowance( $post_id, wp_unslash( $_POST[ self::META_KEY ] ) );
		}

		if ( isset( $_POST[ self::META_NOTE ] ) ) {
			self::set_note( $post_id, wp_unslash( $_POST[ self::META_NOTE ] ) );
		}
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
			'/portal/comp-allowances',
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
			'/portal/project/(?P<id>\d+)/comp-allowance',
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
	 * GET /portal/comp-allowances - every project and its allowance.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function rest_list( $req ) {
		$ids = get_posts(
			array(
				'post_type'        => self::post_type(),
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
	 * GET /portal/project/{id}/comp-allowance
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $req ) {
		$record = self::get_record( (int) $req['id'] );
		if ( ! $record ) {
			return new WP_Error( 'ansp_comp_allowance_not_found', 'No project with that id.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $record );
	}

	/**
	 * POST /portal/project/{id}/comp-allowance
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_set( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $req->get_params();
		}

		if ( self::is_production() && empty( $body['confirm_production'] ) ) {
			return new WP_Error(
				'ansp_comp_allowance_confirm_production',
				'This is the production site. Pass confirm_production: true to write.',
				array( 'status' => 400 )
			);
		}

		$project_id = (int) $req['id'];
		$record     = self::get_record( $project_id );
		if ( ! $record ) {
			return new WP_Error( 'ansp_comp_allowance_not_found', 'No project with that id.', array( 'status' => 404 ) );
		}

		if ( array_key_exists( 'comps_per_singer', $body ) ) {
			self::set_allowance( $project_id, $body['comps_per_singer'] );
		}

		if ( array_key_exists( 'note', $body ) ) {
			self::set_note( $project_id, $body['note'] );
		}

		// Read back rather than echoing the request - the read-back is the proof.
		return rest_ensure_response(
			array(
				'ok'      => true,
				'project' => self::get_record( $project_id ),
			)
		);
	}
}
