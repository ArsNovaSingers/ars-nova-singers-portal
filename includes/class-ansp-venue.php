<?php
/**
 * The ans_venue custom post type ("Venues").
 *
 * A venue is a ROOM, not an event. Its capacity, address and access notes belong
 * to the room and change almost never - roughly six records for the whole
 * organisation. Before this existed, the venue was a free-text field typed onto
 * every project (ANSP_Project_Meta's 'venue'), which had already drifted into
 * several spellings of the same handful of places, and capacity was recorded
 * nowhere at all.
 *
 * WHY CAPACITY LIVES HERE AND NOT ON A PERFORMANCE. Capacity is a property of
 * the house. Two performances in the same room do not have different capacities;
 * they have different numbers of seats already sold. Tickera has no capacity
 * mechanism of its own in Bridge mode - its own documentation points at
 * per-variation WooCommerce stock, which gives three separate pools per
 * performance rather than one house - so this record is where the number lives.
 * See claude/season-ops/Season_Wiki_Architecture.md.
 *
 * The CPT is not public: it is reference data for staff, surfaced under the
 * "Singers Portal" admin menu beside Projects, and read by other code. It
 * deliberately reuses the ans_project capability set rather than minting its own,
 * so anyone who can edit a Project can edit a Venue and no role needs changing.
 *
 * ⚠️ THE ADDRESS IS NOT PUBLIC. PROJECT_RULES §8: a private residence hosting a
 * house concert must never have its address published. Every route in this file
 * requires manage capability; nothing here is reachable unauthenticated, and the
 * CPT has no front-end permalink. If a future caller needs a public venue name,
 * expose the NAME - never the address.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Venue
 */
class ANSP_Venue {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'ans_venue';

	/**
	 * Meta key prefix. Kept distinct from ansp_project_ so the two never collide.
	 */
	const META_PREFIX = 'ansp_venue_';

	/**
	 * Nonce action for the meta box.
	 */
	const NONCE = 'ansp_venue_save';

	/**
	 * Editable fields, in display order.
	 *
	 * type: 'int' | 'text' | 'textarea'
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'capacity' => array(
				'label' => __( 'Capacity (seats)', 'ans-singers-portal' ),
				'type'  => 'int',
				'help'  => __( 'How many people the room holds. A property of the room, not of any one performance.', 'ans-singers-portal' ),
			),
			'address'  => array(
				'label' => __( 'Address', 'ans-singers-portal' ),
				'type'  => 'textarea',
				'help'  => __( 'The real street address. Always store it here — the checkbox below decides whether it may be shown publicly.', 'ans-singers-portal' ),
			),
			'address_private' => array(
				'label' => __( 'Private address', 'ans-singers-portal' ),
				'type'  => 'bool',
				'help'  => __( 'Tick for a house concert or any venue whose address must not be public. The address is then sent on tickets and confirmation emails ONLY, and the public site shows the venue name alone.', 'ans-singers-portal' ),
			),
			'notes'    => array(
				'label' => __( 'Access / parking notes', 'ans-singers-portal' ),
				'type'  => 'textarea',
				'help'  => __( 'Parking, entrance, accessibility. Optional.', 'ans-singers-portal' ),
			),
		);
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 10 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Post type
	 * ------------------------------------------------------------------ */

	/**
	 * Register the ans_venue post type.
	 *
	 * Mirrors ANSP_CPT's registration deliberately: not public, shown in the
	 * Singers Portal menu, no REST (this file provides its own routes), and the
	 * ans_project capability set so existing grants already cover it.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Venues', 'ans-singers-portal' ),
				'labels'          => array(
					'name'               => __( 'Venues', 'ans-singers-portal' ),
					'singular_name'      => __( 'Venue', 'ans-singers-portal' ),
					'menu_name'          => __( 'Venues', 'ans-singers-portal' ),
					'add_new'            => __( 'Add New Venue', 'ans-singers-portal' ),
					'add_new_item'       => __( 'Add New Venue', 'ans-singers-portal' ),
					'edit_item'          => __( 'Edit Venue', 'ans-singers-portal' ),
					'new_item'           => __( 'New Venue', 'ans-singers-portal' ),
					'view_item'          => __( 'View Venue', 'ans-singers-portal' ),
					'search_items'       => __( 'Search Venues', 'ans-singers-portal' ),
					'not_found'          => __( 'No venues found.', 'ans-singers-portal' ),
					'not_found_in_trash' => __( 'No venues found in Trash.', 'ans-singers-portal' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'ansp-dashboard', // Nested under the Singers Portal dashboard.
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => false,
				'supports'        => array( 'title' ),
				'capability_type' => array( 'ans_project', 'ans_projects' ),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-location',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin list columns
	 * ------------------------------------------------------------------ */

	/**
	 * Add a Capacity column. The address is deliberately NOT a column - the
	 * venue list is the screen most likely to be shown on a shared screen.
	 *
	 * @param array $cols
	 * @return array
	 */
	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['ansp_capacity'] = __( 'Capacity', 'ans-singers-portal' );
			}
		}
		return $out;
	}

	/**
	 * Render the Capacity column.
	 *
	 * @param string $col
	 * @param int    $post_id
	 * @return void
	 */
	public static function column( $col, $post_id ) {
		if ( 'ansp_capacity' !== $col ) {
			return;
		}
		$cap = self::get_capacity( $post_id );
		echo $cap > 0 ? esc_html( number_format_i18n( $cap ) ) : '<span aria-hidden="true">&#8212;</span>';
	}

	/* ---------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------ */

	/**
	 * Register the details meta box.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ansp-venue-details',
			__( 'Venue details', 'ans-singers-portal' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the details meta box.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::fields() as $key => $field ) {
			$name  = self::META_PREFIX . $key;
			$value = get_post_meta( $post->ID, $name, true );

			echo '<tr>';
			echo '<th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			echo '<td>';

			if ( 'textarea' === $field['type'] ) {
				printf(
					'<textarea id="%1$s" name="%1$s" rows="3" class="large-text">%2$s</textarea>',
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
			} elseif ( 'int' === $field['type'] ) {
				printf(
					'<input type="number" min="0" step="1" id="%1$s" name="%1$s" value="%2$s" class="small-text" />',
					esc_attr( $name ),
					esc_attr( '' === $value ? '' : (string) (int) $value )
				);
			} elseif ( 'bool' === $field['type'] ) {
				/*
				 * The hidden 0 is load-bearing. An unchecked checkbox is not
				 * POSTed at all, and save_meta() skips any field that is not
				 * present - so without this, UNTICKING the box would be a
				 * silent no-op and the address would stay private for ever.
				 * The hidden input guarantees the key is always submitted.
				 */
				printf(
					'<input type="hidden" name="%1$s" value="0" />'
					. '<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
					esc_attr( $name ),
					checked( (string) $value, '1', false ),
					esc_html__( 'Do not show this address publicly', 'ans-singers-portal' )
				);
			} else {
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
			}

			if ( ! empty( $field['help'] ) ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Persist the meta box.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @return void
	 */
	public static function save_meta( $post_id, $post ) {
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

		foreach ( self::fields() as $key => $field ) {
			$name = self::META_PREFIX . $key;
			if ( ! isset( $_POST[ $name ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $name ] );
			self::write_field( $post_id, $key, $raw );
		}
	}

	/* ---------------------------------------------------------------------
	 * Read / write helpers - the single place field rules live
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitise and store one field. Used by both the meta box and REST, so the
	 * two can never disagree about what a valid value is.
	 *
	 * @param int    $post_id
	 * @param string $key   Field key, without the prefix.
	 * @param mixed  $value
	 * @return void
	 */
	public static function write_field( $post_id, $key, $value ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}

		$name = self::META_PREFIX . $key;

		if ( 'int' === $fields[ $key ]['type'] ) {
			$clean = max( 0, (int) $value );
			update_post_meta( $post_id, $name, $clean );
			return;
		}

		if ( 'textarea' === $fields[ $key ]['type'] ) {
			update_post_meta( $post_id, $name, sanitize_textarea_field( (string) $value ) );
			return;
		}

		if ( 'bool' === $fields[ $key ]['type'] ) {
			/*
			 * Stored as '1' or '' rather than as a boolean, so that an absent
			 * value and a false value read identically. Anything a browser or
			 * a REST caller might send for "true" is accepted, because the
			 * cost of a checkbox that silently fails to tick is a private
			 * address quietly going public.
			 */
			$on = in_array(
				strtolower( trim( (string) $value ) ),
				array( '1', 'true', 'yes', 'on' ),
				true
			);
			update_post_meta( $post_id, $name, $on ? '1' : '' );
			return;
		}

		update_post_meta( $post_id, $name, sanitize_text_field( (string) $value ) );
	}

	/**
	 * Is this venue's address private?
	 *
	 * Defaults to FALSE, and that default is the safe one only because the
	 * address is not published anywhere by this plugin - the public string on
	 * a Tickera event is authored separately. A venue nobody has opened is
	 * therefore not leaking anything; it simply has no opinion yet.
	 *
	 * @param int $venue_id
	 * @return bool
	 */
	public static function is_address_private( $venue_id ) {
		$venue_id = (int) $venue_id;
		if ( $venue_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_post_meta( $venue_id, self::META_PREFIX . 'address_private', true );
	}

	/**
	 * Capacity for a venue, as an int. 0 means "not recorded" - never guess.
	 *
	 * @param int $venue_id
	 * @return int
	 */
	public static function get_capacity( $venue_id ) {
		return max( 0, (int) get_post_meta( (int) $venue_id, self::META_PREFIX . 'capacity', true ) );
	}

	/**
	 * The whole record as an array. Public API for other plugins.
	 *
	 * @param int $venue_id
	 * @return array|null Null when the id is not a published venue.
	 */
	public static function get_venue( $venue_id ) {
		$post = get_post( (int) $venue_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$out = array(
			'id'     => (int) $post->ID,
			'name'   => $post->post_title,
			'status' => $post->post_status,
		);

		foreach ( array_keys( self::fields() ) as $key ) {
			$raw = get_post_meta( $post->ID, self::META_PREFIX . $key, true );
			$out[ $key ] = ( 'capacity' === $key ) ? max( 0, (int) $raw ) : (string) $raw;
		}

		return $out;
	}

	/**
	 * Every venue, ordered by name.
	 *
	 * @return array
	 */
	public static function all_venues() {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
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
			$venue = self::get_venue( $id );
			if ( $venue ) {
				$out[] = $venue;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * REST - ars-nova/v1, admin only
	 *
	 * ans_project is show_in_rest=false and its meta is not REST-registered,
	 * which is exactly why ansp_scores_project needed two extra releases before
	 * it could be set without a human in wp-admin. Venues ship with their routes
	 * from the start so that never repeats.
	 *
	 * Reuses ANSP_Rest::can_manage() and ::is_production() behind method_exists
	 * guards rather than reimplementing them - a second definition of "is this
	 * production" is exactly the kind of thing that drifts and then disagrees.
	 * ------------------------------------------------------------------ */

	/**
	 * Namespace the WordPress MCP connector can actually reach.
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
	 * Can the current request manage portal data?
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
	 * Is this the production site?
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
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = self::ns();
		$auth = array( __CLASS__, 'can_manage' );

		register_rest_route(
			$ns,
			'/portal/venues',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_upsert' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			$ns,
			'/portal/venue/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_upsert' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	/**
	 * GET /portal/venues
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function rest_list( $req ) {
		$venues = self::all_venues();
		return rest_ensure_response(
			array(
				'site'   => home_url(),
				'count'  => count( $venues ),
				'venues' => $venues,
			)
		);
	}

	/**
	 * GET /portal/venue/{id}
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $req ) {
		$venue = self::get_venue( (int) $req['id'] );
		if ( ! $venue ) {
			return new WP_Error( 'ansp_venue_not_found', 'No venue with that id.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $venue );
	}

	/**
	 * POST /portal/venues (create) or /portal/venue/{id} (update).
	 *
	 * Production writes are gated behind confirm_production, matching ANSP_Rest.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_upsert( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $req->get_params();
		}

		if ( self::is_production() && empty( $body['confirm_production'] ) ) {
			return new WP_Error(
				'ansp_venue_confirm_production',
				'This is the production site. Pass confirm_production: true to write.',
				array( 'status' => 400 )
			);
		}

		$id = isset( $req['id'] ) ? (int) $req['id'] : 0;

		if ( $id > 0 ) {
			$existing = get_post( $id );
			if ( ! $existing || self::POST_TYPE !== $existing->post_type ) {
				return new WP_Error( 'ansp_venue_not_found', 'No venue with that id.', array( 'status' => 404 ) );
			}
			if ( isset( $body['name'] ) && '' !== trim( (string) $body['name'] ) ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => sanitize_text_field( (string) $body['name'] ),
					)
				);
			}
		} else {
			$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
			if ( '' === trim( $name ) ) {
				return new WP_Error( 'ansp_venue_name_required', 'A venue needs a name.', array( 'status' => 400 ) );
			}
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}

		foreach ( array_keys( self::fields() ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				self::write_field( $id, $key, $body[ $key ] );
			}
		}

		// Read back rather than echoing the request - the read-back is the proof.
		return rest_ensure_response(
			array(
				'ok'    => true,
				'venue' => self::get_venue( $id ),
			)
		);
	}
}
