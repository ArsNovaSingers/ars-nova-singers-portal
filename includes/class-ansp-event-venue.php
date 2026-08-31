<?php
/**
 * Performance -> Venue link.
 *
 * THE GAP THIS CLOSES. ANSP_Venue gave a venue a record: a name, an address,
 * and a capacity. ANSP_Project_Ticketing linked a portal Project to its Tickera
 * event_category, so a Project can list its performances. Both shipped. Nothing
 * connected the last hop, so every Venue record was an orphan and no capacity
 * could ever be read through a performance.
 *
 * WHY A REAL ID AND NOT STRING MATCHING. Tickera stores the location as a single
 * free-text string per event - `event_location` - and has no venue object at all.
 * That data model is why 18 live events resolve to 7 distinct strings for about
 * 6 real rooms: it requires retyping the address on every performance, so drift
 * is the guaranteed outcome rather than anyone's mistake. Matching on that string
 * at read time would inherit the same fragility. The string is matched ONCE, by
 * the backfill below, and the answer is stored as an id.
 *
 * `event_location` stays exactly where it is and keeps its job. It is Tickera's
 * own required field and the ticket template reads it, so it cannot be replaced -
 * only fed. This class is the first half of that: it establishes which Venue a
 * performance is at. A later materialiser writes `event_location` FROM the venue
 * record so nobody types it again.
 *
 * FAILS OPEN, ALWAYS. Capacity 0 means "not recorded", never "sell nothing", and
 * a performance with no venue link is unlimited rather than blocked. A members
 * plugin must never be able to stop a box-office action or a public sale.
 *
 * @package ars-nova-singers-portal
 * @since   1.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ANSP_Event_Venue {

	/**
	 * Meta key on a `tc_events` post holding its Venue post id.
	 *
	 * No leading underscore, deliberately, matching `ans_perk` and
	 * `ans_event_kind`: Kim can see and correct it in the Custom Fields panel
	 * without waiting on a plugin release.
	 */
	const META = 'ansp_event_venue';

	/**
	 * The Tickera performance post type.
	 */
	const EVENT_TYPE = 'tc_events';

	/**
	 * Tickera's own free-text location field. Read, never written here.
	 */
	const LOCATION_META = 'event_location';

	const NONCE = 'ansp_event_venue_save';

	/**
	 * Register. Everything hangs off hooks that already exist; no existing
	 * behaviour is edited.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::EVENT_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------------------------------------------------------------------
	 * Read API - what everything else should call
	 * ------------------------------------------------------------------ */

	/**
	 * The Venue post id linked to a performance, or 0.
	 *
	 * Verifies the target still exists and is really a venue. A venue that was
	 * deleted leaves a dangling id behind, and returning it would hand callers
	 * a post id that resolves to nothing - or worse, to whatever later took
	 * that id. Cheap to check, and it makes every caller below honest.
	 *
	 * @param int $event_id
	 * @return int
	 */
	public static function get_venue_id( $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			return 0;
		}

		$venue_id = (int) get_post_meta( $event_id, self::META, true );
		if ( $venue_id <= 0 ) {
			return 0;
		}

		if ( ! class_exists( 'ANSP_Venue' ) ) {
			return 0;
		}

		if ( get_post_type( $venue_id ) !== ANSP_Venue::POST_TYPE ) {
			return 0;
		}

		return $venue_id;
	}

	/**
	 * Capacity for a performance, resolved through its venue.
	 *
	 * 0 means NOT RECORDED and must be treated as unlimited. There is no third
	 * "unconfigured" state, on purpose: an unset field and a deliberate zero
	 * have to behave identically at the point of use, or the difference becomes
	 * a bug nobody can see. Since no venue has a real seat count yet, an
	 * implementation that read 0 as a hard limit would block every sale and
	 * every comp on the system the moment it shipped.
	 *
	 * @param int $event_id
	 * @return int Seats, or 0 for "not recorded / no limit".
	 */
	public static function get_capacity( $event_id ) {
		$venue_id = self::get_venue_id( $event_id );
		if ( ! $venue_id ) {
			return 0;
		}

		if ( ! class_exists( 'ANSP_Venue' ) || ! method_exists( 'ANSP_Venue', 'get_capacity' ) ) {
			return 0;
		}

		return (int) ANSP_Venue::get_capacity( $venue_id );
	}

	/**
	 * The venue's display name for a performance, or '' if unlinked.
	 *
	 * @param int $event_id
	 * @return string
	 */
	public static function get_venue_name( $event_id ) {
		$venue_id = self::get_venue_id( $event_id );
		if ( ! $venue_id ) {
			return '';
		}
		return html_entity_decode( (string) get_the_title( $venue_id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Link a performance to a venue. Pass 0 to unlink.
	 *
	 * @param int $event_id
	 * @param int $venue_id
	 * @return bool
	 */
	public static function set_venue_id( $event_id, $venue_id ) {
		$event_id = (int) $event_id;
		$venue_id = (int) $venue_id;

		if ( $event_id <= 0 || get_post_type( $event_id ) !== self::EVENT_TYPE ) {
			return false;
		}

		if ( $venue_id <= 0 ) {
			delete_post_meta( $event_id, self::META );
			return true;
		}

		if ( ! class_exists( 'ANSP_Venue' ) || get_post_type( $venue_id ) !== ANSP_Venue::POST_TYPE ) {
			return false;
		}

		update_post_meta( $event_id, self::META, $venue_id );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Timestamps - the one correct way to read an event date
	 * ------------------------------------------------------------------ */

	/**
	 * Parse a Tickera event date into a real timestamp.
	 *
	 * WHY THIS EXISTS. Tickera stores `event_date_time` as a NAIVE SITE-LOCAL
	 * wall clock - "2026-10-09 19:30" means 7:30pm in Denver, with nothing in
	 * the string saying so. WordPress unconditionally calls
	 * date_default_timezone_set('UTC'), so a bare strtotime() reads that as
	 * 7:30pm UTC and lands 6-7 hours off, following daylight saving.
	 *
	 * The ticketing branch already paid for this once: the season-packages page
	 * showed every performance six or seven hours early, while the concert pages
	 * rendered the SAME database row correctly - because they made the identical
	 * wrong parse and then rendered with a function whose legacy handling did
	 * not re-apply the offset, so the two errors cancelled. The page that looked
	 * right was right by accident, and that is what made it expensive: the
	 * correct-looking page is the one people check against.
	 *
	 * Parse in wp_timezone(), render with wp_date(). Never strtotime().
	 *
	 * @param string $raw `Y-m-d H:i` in site-local time.
	 * @return int Unix timestamp, or 0 when absent or unparseable.
	 */
	public static function local_ts( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return 0;
		}

		try {
			$dt = new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}

		return (int) $dt->getTimestamp();
	}

	/* ---------------------------------------------------------------------
	 * String matching - used ONCE, by the backfill
	 * ------------------------------------------------------------------ */

	/**
	 * Normalise a venue string for comparison.
	 *
	 * Lowercased, punctuation and whitespace collapsed, so that
	 * "St. Paul Center for Music and the Arts, 1600 Grant Street, Denver, CO 80203"
	 * and "St Paul Center for Music and the Arts" compare equal on their leading
	 * words. Encoding is decoded first because the two sides disagree: an event
	 * string can carry &#038; where the venue title has a literal ampersand, and
	 * a raw comparison matches nothing while looking like it should.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function normalise( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = strtolower( $s );
		$s = str_replace( array( '—', '–', '’', '‘', '“', '”' ), array( '-', '-', "'", "'", '"', '"' ), $s );
		$s = preg_replace( '/[^a-z0-9]+/u', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', (string) $s ) );
	}

	/**
	 * Best venue match for a free-text location string.
	 *
	 * Deliberately conservative, and it returns HOW it matched so a caller can
	 * decide whether to trust it. Three passes, strongest first:
	 *
	 *   exact   - normalised event string equals normalised venue title
	 *   prefix  - the event string STARTS WITH the venue title. This is the
	 *             real-world case: "Dairy Arts Center, 2590 Walnut St, Boulder"
	 *             against the venue "Dairy Arts Center"
	 *   address - the venue's stored address appears in the event string
	 *
	 * The prefix pass takes the LONGEST matching title, which is the whole
	 * reason it is written this way: "Grace Gamm Theater, Dairy Arts Center,
	 * 2590 Walnut St" starts with neither "Dairy Arts Center" nor "Grace Gamm
	 * Theater" alphabetically-first, and picking the shorter match would file
	 * both Dairy rooms under one record - silently collapsing a distinction
	 * that may be real. Merging two rooms is lossy; splitting them later is not.
	 *
	 * @param string $location Free-text `event_location`.
	 * @return array{venue_id:int,how:string,venue:string}
	 */
	public static function match_venue( $location ) {
		$none = array(
			'venue_id' => 0,
			'how'      => 'no_match',
			'venue'    => '',
		);

		$needle = self::normalise( $location );
		if ( '' === $needle || ! class_exists( 'ANSP_Venue' ) ) {
			return $none;
		}

		$index = self::venue_index();
		if ( empty( $index ) ) {
			return $none;
		}

		// Pass 1 - exact.
		foreach ( $index as $vid => $v ) {
			if ( '' !== $v['n_title'] && $v['n_title'] === $needle ) {
				return array(
					'venue_id' => (int) $vid,
					'how'      => 'exact',
					'venue'    => $v['title'],
				);
			}
		}

		// Pass 2 - the event string starts with the venue title. Longest wins.
		$best     = 0;
		$best_len = 0;
		foreach ( $index as $vid => $v ) {
			$t = $v['n_title'];
			if ( '' === $t ) {
				continue;
			}
			if ( 0 === strpos( $needle, $t ) && strlen( $t ) > $best_len ) {
				$best     = (int) $vid;
				$best_len = strlen( $t );
			}
		}
		if ( $best ) {
			return array(
				'venue_id' => $best,
				'how'      => 'prefix',
				'venue'    => $index[ $best ]['title'],
			);
		}

		// Pass 3 - the venue's address appears in the event string. Longest wins.
		$best     = 0;
		$best_len = 0;
		foreach ( $index as $vid => $v ) {
			$a = $v['n_address'];
			if ( '' === $a || strlen( $a ) < 8 ) {
				continue;
			}
			if ( false !== strpos( $needle, $a ) && strlen( $a ) > $best_len ) {
				$best     = (int) $vid;
				$best_len = strlen( $a );
			}
		}
		if ( $best ) {
			return array(
				'venue_id' => $best,
				'how'      => 'address',
				'venue'    => $index[ $best ]['title'],
			);
		}

		return $none;
	}

	/**
	 * Every venue as id => array( title, normalised title, normalised address ).
	 *
	 * @return array
	 */
	public static function venue_index() {
		if ( ! class_exists( 'ANSP_Venue' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'     => ANSP_Venue::POST_TYPE,
				'post_status'   => array( 'publish', 'draft', 'private' ),
				'numberposts'   => 200,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		$out = array();
		foreach ( $posts as $p ) {
			$title   = html_entity_decode( (string) $p->post_title, ENT_QUOTES, 'UTF-8' );
			$address = (string) get_post_meta( $p->ID, ANSP_Venue::META_PREFIX . 'address', true );

			$out[ (int) $p->ID ] = array(
				'title'      => $title,
				'n_title'    => self::normalise( $title ),
				'n_address'  => self::normalise( $address ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Admin - a venue picker on the performance screen
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		if ( ! post_type_exists( self::EVENT_TYPE ) ) {
			return;
		}

		add_meta_box(
			'ansp-event-venue',
			__( 'Venue', 'ans-singers-portal' ),
			array( __CLASS__, 'render_meta_box' ),
			self::EVENT_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * The picker. Shows Tickera's own location string underneath, read-only,
	 * because that is what actually prints on the ticket - and if the two ever
	 * disagree, the person looking at this box is the one who can say which is
	 * right.
	 *
	 * @param WP_Post $post
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$current  = self::get_venue_id( $post->ID );
		$index    = self::venue_index();
		$location = (string) get_post_meta( $post->ID, self::LOCATION_META, true );

		if ( empty( $index ) ) {
			echo '<p>' . esc_html__( 'No venues exist yet. Add one under Singers Portal > Venues.', 'ans-singers-portal' ) . '</p>';
			return;
		}

		echo '<select name="' . esc_attr( self::META ) . '" style="width:100%">';
		echo '<option value="0">' . esc_html__( '— not set —', 'ans-singers-portal' ) . '</option>';
		foreach ( $index as $vid => $v ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $vid,
				selected( $current, (int) $vid, false ),
				esc_html( $v['title'] )
			);
		}
		echo '</select>';

		if ( $current ) {
			$cap = self::get_capacity( $post->ID );
			echo '<p style="margin:8px 0 0;font-size:12px;color:#646970;">';
			if ( $cap > 0 ) {
				printf(
					/* translators: %d: seat count */
					esc_html__( 'Capacity: %d seats.', 'ans-singers-portal' ),
					(int) $cap
				);
			} else {
				echo esc_html__( 'Capacity not recorded yet — treated as no limit.', 'ans-singers-portal' );
			}
			echo '</p>';
		}

		echo '<p style="margin:10px 0 0;font-size:12px;color:#646970;">';
		echo '<strong>' . esc_html__( 'Prints on the ticket:', 'ans-singers-portal' ) . '</strong><br>';
		echo '' !== trim( $location )
			? esc_html( $location )
			: '<em>' . esc_html__( 'nothing — this event has no location set', 'ans-singers-portal' ) . '</em>';
		echo '</p>';
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$venue_id = isset( $_POST[ self::META ] ) ? (int) $_POST[ self::META ] : 0;
		self::set_venue_id( $post_id, $venue_id );
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	protected static function ns() {
		return 'ars-nova/v1';
	}

	public static function can_manage() {
		if ( class_exists( 'ANSP_Rest' ) && method_exists( 'ANSP_Rest', 'can_manage' ) ) {
			return (bool) ANSP_Rest::can_manage();
		}
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		$auth = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::ns(),
			'/portal/event-venues',
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'rest_list' ),
			)
		);

		register_rest_route(
			self::ns(),
			'/portal/event-venues/backfill',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'rest_backfill' ),
			)
		);

		register_rest_route(
			self::ns(),
			'/portal/event/(?P<id>\d+)/venue',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $auth,
					'callback'            => array( __CLASS__, 'rest_get' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $auth,
					'callback'            => array( __CLASS__, 'rest_set' ),
				),
			)
		);
	}

	/**
	 * Every performance, its location string, and what it is linked to.
	 */
	public static function rest_list( $req ) {
		if ( ! post_type_exists( self::EVENT_TYPE ) ) {
			return new WP_Error( 'ansp_no_events', 'tc_events is not registered - is Tickera active?', array( 'status' => 400 ) );
		}

		$events = get_posts(
			array(
				'post_type'     => self::EVENT_TYPE,
				'post_status'   => array( 'publish', 'draft', 'private' ),
				'numberposts'   => 200,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		$rows    = array();
		$linked  = 0;
		foreach ( $events as $e ) {
			$vid = self::get_venue_id( $e->ID );
			if ( $vid ) {
				$linked++;
			}

			$rows[] = array(
				'event_id' => (int) $e->ID,
				'title'    => html_entity_decode( get_the_title( $e->ID ), ENT_QUOTES, 'UTF-8' ),
				'status'   => $e->post_status,
				'date'     => (string) get_post_meta( $e->ID, 'event_date_time', true ),
				'location' => (string) get_post_meta( $e->ID, self::LOCATION_META, true ),
				'venue_id' => $vid,
				'venue'    => $vid ? self::get_venue_name( $e->ID ) : null,
				'capacity' => self::get_capacity( $e->ID ),
			);
		}

		return array(
			'count'    => count( $rows ),
			'linked'   => $linked,
			'unlinked' => count( $rows ) - $linked,
			'events'   => $rows,
		);
	}

	/**
	 * POST /portal/event-venues/backfill
	 *
	 * Dry run unless apply=true, in the shape this project already uses: it
	 * resolves every event, reports the link it WOULD write and how it matched,
	 * and writes nothing. A green dry run here means the write will land.
	 *
	 * Never overwrites an existing link unless force=true. A hand-set venue is
	 * a human decision and a string matcher does not get to overrule it.
	 */
	public static function rest_backfill( $req ) {
		$apply = filter_var( $req->get_param( 'apply' ), FILTER_VALIDATE_BOOLEAN );
		$force = filter_var( $req->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

		if ( ! post_type_exists( self::EVENT_TYPE ) ) {
			return new WP_Error( 'ansp_no_events', 'tc_events is not registered - is Tickera active?', array( 'status' => 400 ) );
		}

		if ( empty( self::venue_index() ) ) {
			return new WP_Error( 'ansp_no_venues', 'No venue records exist. Create them under Singers Portal > Venues first.', array( 'status' => 400 ) );
		}

		$events = get_posts(
			array(
				'post_type'     => self::EVENT_TYPE,
				'post_status'   => array( 'publish', 'draft', 'private' ),
				'numberposts'   => 200,
				'no_found_rows' => true,
			)
		);

		$out     = array();
		$changed = 0;

		foreach ( $events as $e ) {
			$existing = self::get_venue_id( $e->ID );
			$location = (string) get_post_meta( $e->ID, self::LOCATION_META, true );
			$title    = html_entity_decode( get_the_title( $e->ID ), ENT_QUOTES, 'UTF-8' );

			if ( $existing && ! $force ) {
				$out[] = array(
					'event_id' => (int) $e->ID,
					'title'    => $title,
					'venue_id' => $existing,
					'venue'    => self::get_venue_name( $e->ID ),
					'action'   => 'already_linked',
				);
				continue;
			}

			$m = self::match_venue( $location );

			if ( ! $m['venue_id'] ) {
				$out[] = array(
					'event_id' => (int) $e->ID,
					'title'    => $title,
					'location' => $location,
					'action'   => 'no_match',
				);
				continue;
			}

			if ( ! $apply ) {
				$out[] = array(
					'event_id' => (int) $e->ID,
					'title'    => $title,
					'location' => $location,
					'venue_id' => $m['venue_id'],
					'venue'    => $m['venue'],
					'matched'  => $m['how'],
					'action'   => 'would_link',
				);
				continue;
			}

			self::set_venue_id( $e->ID, $m['venue_id'] );
			$changed++;

			$out[] = array(
				'event_id' => (int) $e->ID,
				'title'    => $title,
				'location' => $location,
				'venue_id' => $m['venue_id'],
				'venue'    => $m['venue'],
				'matched'  => $m['how'],
				'action'   => 'linked',
			);
		}

		return array(
			'applied' => $apply,
			'count'   => count( $out ),
			'changed' => $changed,
			'note'    => $apply ? 'Applied.' : 'DRY RUN — nothing written. Re-send with apply: true.',
			'results' => $out,
		);
	}

	public static function rest_get( $req ) {
		$id = (int) $req['id'];
		if ( get_post_type( $id ) !== self::EVENT_TYPE ) {
			return new WP_Error( 'ansp_not_an_event', 'That id is not a tc_events post.', array( 'status' => 400 ) );
		}

		return array(
			'event_id' => $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'location' => (string) get_post_meta( $id, self::LOCATION_META, true ),
			'venue_id' => self::get_venue_id( $id ),
			'venue'    => self::get_venue_name( $id ),
			'capacity' => self::get_capacity( $id ),
			'note'     => 'capacity 0 means NOT RECORDED and is treated as no limit, never as zero seats.',
		);
	}

	public static function rest_set( $req ) {
		$id  = (int) $req['id'];
		$vid = (int) $req->get_param( 'venue_id' );

		if ( ! self::set_venue_id( $id, $vid ) ) {
			return new WP_Error( 'ansp_link_failed', 'Could not link - check the event id is a tc_events post and the venue id is an ans_venue post.', array( 'status' => 400 ) );
		}

		return array(
			'ok'       => true,
			'event_id' => $id,
			'venue_id' => self::get_venue_id( $id ),
			'venue'    => self::get_venue_name( $id ),
			'capacity' => self::get_capacity( $id ),
		);
	}
}
