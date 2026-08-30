<?php
/**
 * The join between a Singers Hub Project and the ticketing side.
 *
 * WHY THIS EXISTS. Before this class there were two unconnected notions of
 * "project" on the same site:
 *
 *   - Ticketing: a Tickera `event_category` term. "Rivers & Streams" is term 81,
 *     and every performance (tc_events post) hangs off it. Tickets hang off the
 *     performances.
 *   - The portal: an `ans_project` post. "Rivers & Streams" is post 6553, and
 *     that is where materials, groups and the comp allowance live.
 *
 * Same production, same name, two ids, and nothing joining them. So the comp
 * allowance ("each singer may claim 2") could be read but never spent: nothing
 * could answer "2 of WHAT?".
 *
 * WHY THE CATEGORY AND NOT THE EVENT. Settled with Jonathan 2026-08-30. The
 * instinct was to link a hub project to a Tickera EVENT. That is one level too
 * fine. "Darkness & Light" is four events - Dec 10, 13, 17 and 19 - but it is
 * ONE production, one folder of music, one comp allowance. Linking per event
 * would give singers four "Darkness & Light" projects with four materials lists
 * for music they learn once. The category is already the production, and the
 * events under it are already the performances, so the category is the join and
 * the events come along for free.
 *
 * WHY IT AUTO-CREATES. Kim and Tom build the season in Tickera. Requiring them
 * to then remember to make a matching hub project - and to name it identically -
 * is a step that will be forgotten, and its failure mode is silent: a project
 * with no music, or an allowance nobody can spend. Creating an event now ensures
 * its production exists on the hub side and is linked by ID, not by name.
 *
 * NAME MATCHING IS A FALLBACK, NEVER THE STORED TRUTH. On first contact with an
 * existing category this will adopt a same-named project rather than making a
 * duplicate, because seven productions already exist on both sides. But it then
 * writes the term id into meta and never matches on name again. 1.15.1 was an
 * entire release spent on a name-matching bug ("Chamber Singers" vs "cs"); the
 * same trap is live here, where the category is "Rivers &amp; Streams" and the
 * project is "Rivers & Streams". Match once, store an id, move on.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Project_Ticketing
 */
class ANSP_Project_Ticketing {

	/**
	 * Meta key on an ans_project holding the Tickera event_category term id.
	 */
	const META_CATEGORY = 'ansp_project_event_category';

	/**
	 * The Tickera taxonomy that groups performances into productions.
	 */
	const TAXONOMY = 'event_category';

	/**
	 * The Tickera performance post type.
	 */
	const EVENT_TYPE = 'tc_events';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * set_object_terms, NOT save_post. A tc_events post's categories are
		 * written AFTER save_post has already run, so a save_post hook would
		 * read the terms the event had a moment ago - which on a brand new
		 * event is none at all, the exact case this exists to catch.
		 */
		add_action( 'set_object_terms', array( __CLASS__, 'on_event_terms_set' ), 10, 6 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------------------------------------------------------------------
	 * Reading the link
	 * ------------------------------------------------------------------ */

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

	/**
	 * Which event_category a project is ticketed as.
	 *
	 * @param int $project_id
	 * @return int Term id, or 0 when unlinked.
	 */
	public static function get_category_id( $project_id ) {
		return max( 0, (int) get_post_meta( (int) $project_id, self::META_CATEGORY, true ) );
	}

	/**
	 * The project a category is represented by on the hub side.
	 *
	 * @param int $term_id
	 * @return int Project post id, or 0.
	 */
	public static function get_project_id( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => self::project_type(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'    => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_CATEGORY,
						'value' => $term_id,
					),
				),
			)
		);

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Link a project to a category. Both directions are validated - a link to
	 * a term that does not exist is worse than no link, because it reads as
	 * configured.
	 *
	 * @param int $project_id
	 * @param int $term_id  0 clears the link.
	 * @return true|WP_Error
	 */
	public static function set_category_id( $project_id, $term_id ) {
		$project_id = (int) $project_id;
		$term_id    = (int) $term_id;

		$post = get_post( $project_id );
		if ( ! $post || self::project_type() !== $post->post_type ) {
			return new WP_Error( 'ansp_not_a_project', sprintf( 'Post %d is not a project.', $project_id ), array( 'status' => 404 ) );
		}

		if ( 0 === $term_id ) {
			delete_post_meta( $project_id, self::META_CATEGORY );
			return true;
		}

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return new WP_Error( 'ansp_no_taxonomy', 'The event_category taxonomy is not registered - is the ticketing bridge active?', array( 'status' => 400 ) );
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ansp_no_such_category', sprintf( 'No event_category term with id %d.', $term_id ), array( 'status' => 400 ) );
		}

		/*
		 * One category, one project. Two projects claiming the same category
		 * would make get_project_id() order-dependent, and an allowance would
		 * silently belong to whichever post_date sorted first.
		 */
		$existing = self::get_project_id( $term_id );
		if ( $existing && $existing !== $project_id ) {
			return new WP_Error(
				'ansp_category_taken',
				sprintf( 'Category %d is already linked to project %d ("%s"). Unlink it there first.', $term_id, $existing, get_the_title( $existing ) ),
				array( 'status' => 409 )
			);
		}

		update_post_meta( $project_id, self::META_CATEGORY, $term_id );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Auto-creation
	 * ------------------------------------------------------------------ */

	/**
	 * A performance was filed under one or more categories: make sure each of
	 * those productions exists on the hub side.
	 *
	 * @param int    $object_id
	 * @param array  $terms
	 * @param array  $tt_ids
	 * @param string $taxonomy
	 * @param bool   $append
	 * @param array  $old_tt_ids
	 * @return void
	 */
	public static function on_event_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		if ( self::EVENT_TYPE !== get_post_type( $object_id ) ) {
			return;
		}

		/*
		 * Autosaves and revisions fire this too. Creating a project from a
		 * half-typed autosave is the kind of thing nobody notices until there
		 * are nine of them.
		 */
		if ( wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
			return;
		}

		foreach ( (array) $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, self::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				self::ensure_project_for_category( (int) $term->term_id );
			}
		}
	}

	/**
	 * Guarantee a linked hub project for a category, creating one if needed.
	 *
	 * Order matters and is deliberate:
	 *   1. Already linked by id?      -> use it. The only trustworthy answer.
	 *   2. A project with that title? -> adopt and link it. One-time, for the
	 *      productions that predate this class.
	 *   3. Otherwise                  -> create one, as a DRAFT.
	 *
	 * Draft, not publish: an auto-created project has no materials, no groups
	 * and no allowance. Publishing it would put an empty production in front of
	 * singers because somebody made a ticket. Tom fills it in and publishes it.
	 *
	 * @param int $term_id
	 * @return int Project id, or 0 on failure.
	 */
	public static function ensure_project_for_category( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return 0;
		}

		$linked = self::get_project_id( $term_id );
		if ( $linked ) {
			return $linked;
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		$title = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );

		$adopted = self::find_project_by_title( $title );
		if ( $adopted ) {
			update_post_meta( $adopted, self::META_CATEGORY, $term_id );
			return $adopted;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => self::project_type(),
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return 0;
		}

		update_post_meta( $new_id, self::META_CATEGORY, $term_id );
		return (int) $new_id;
	}

	/**
	 * Find a project by title, comparing decoded and whitespace-collapsed text.
	 *
	 * The two sides disagree on encoding: the category is stored as
	 * "Rivers &amp; Streams" and the project as "Rivers & Streams". A raw
	 * comparison matches nothing and silently creates a duplicate.
	 *
	 * @param string $title
	 * @return int Project id, or 0.
	 */
	protected static function find_project_by_title( $title ) {
		$want = self::normalise( $title );
		if ( '' === $want ) {
			return 0;
		}

		$candidates = get_posts(
			array(
				'post_type'     => self::project_type(),
				'post_status'   => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'   => 200,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		foreach ( $candidates as $pid ) {
			if ( self::normalise( get_the_title( $pid ) ) === $want ) {
				// Never adopt one that is already spoken for.
				if ( ! self::get_category_id( $pid ) ) {
					return (int) $pid;
				}
			}
		}

		return 0;
	}

	/**
	 * Decode entities, collapse whitespace, casefold. Comparison only - never
	 * stored, never displayed.
	 *
	 * @param string $s
	 * @return string
	 */
	protected static function normalise( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s ) );
	}

	/* ---------------------------------------------------------------------
	 * Performances under a project
	 * ------------------------------------------------------------------ */

	/**
	 * The performances belonging to a project, soonest first.
	 *
	 * @param int  $project_id
	 * @param bool $upcoming_only Skip performances whose date has passed.
	 * @return array<int,array> Each: id, title, date, location.
	 */
	public static function get_performances( $project_id, $upcoming_only = true ) {
		$term_id = self::get_category_id( $project_id );
		if ( ! $term_id || ! post_type_exists( self::EVENT_TYPE ) ) {
			return array();
		}

		$events = get_posts(
			array(
				'post_type'     => self::EVENT_TYPE,
				'post_status'   => 'publish',
				'numberposts'   => 100,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'tax_query'     => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		$now = current_time( 'timestamp' );
		$out = array();

		foreach ( $events as $eid ) {
			$when = (string) get_post_meta( $eid, 'event_date_time', true );
			$ts   = $when ? strtotime( $when ) : 0;

			/*
			 * An event with no date sorts last but is never hidden. A missing
			 * date is a data gap, and hiding the performance would turn that
			 * gap into "there are no concerts", which reads as a bug in this
			 * panel rather than a blank field on the event.
			 */
			if ( $upcoming_only && $ts && $ts < $now ) {
				continue;
			}

			$out[] = array(
				'id'       => (int) $eid,
				'title'    => html_entity_decode( get_the_title( $eid ), ENT_QUOTES, 'UTF-8' ),
				'date'     => $when,
				'ts'       => $ts,
				'location' => (string) get_post_meta( $eid, 'event_location', true ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( ! $a['ts'] ) { return 1; }
				if ( ! $b['ts'] ) { return -1; }
				return $a['ts'] <=> $b['ts'];
			}
		);

		return $out;
	}

	/**
	 * The ticket product a comp for this performance should be issued against.
	 *
	 * WHY THE DEAREST TIER. A performance sells Adult / Student / Youth. A comp
	 * is a SEAT, not a discount category - the person walking in is not a
	 * student because the comp was cheap to record. The ledger's job is to show
	 * the retail value forgone, so the honest number is the full-price tier.
	 * Kim can still issue any specific tier by hand from the Comp Tickets
	 * screen; this is only what an unattended singer claim picks.
	 *
	 * Both meta conventions are matched. `repair-tickets` deletes the legacy
	 * `_ticket`/`event_name` keys, so a site with some products repaired and
	 * some not has both live at once (bridge v1.8.1 hit this exact thing).
	 *
	 * @param int $event_id
	 * @return int Product id, or 0.
	 */
	public static function get_ticket_product( $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$products = get_posts(
			array(
				'post_type'     => 'product',
				'post_status'   => 'publish',
				'numberposts'   => 100,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array( 'key' => '_tc_is_ticket', 'value' => 'yes' ),
						array( 'key' => '_ticket', 'value' => 'yes' ),
					),
					array(
						'relation' => 'OR',
						array( 'key' => '_event_name', 'value' => $event_id ),
						array( 'key' => 'event_name', 'value' => $event_id ),
					),
				),
			)
		);

		$best       = 0;
		$best_price = -1.0;

		foreach ( $products as $pid ) {
			$prod = wc_get_product( $pid );
			if ( ! $prod ) {
				continue;
			}
			$price = (float) $prod->get_regular_price();
			if ( $price > $best_price ) {
				$best_price = $price;
				$best       = (int) $pid;
			}
		}

		return $best;
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	/**
	 * Namespace, matching the rest of the plugin.
	 *
	 * @return string
	 */
	protected static function ns() {
		return 'ars-nova/v1';
	}

	/**
	 * Can the caller manage this?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::ns(),
			'/portal/project-ticketing',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::ns(),
			'/portal/project-ticketing/backfill',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_backfill' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::ns(),
			'/portal/project/(?P<id>\d+)/event-category',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_set' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * GET /portal/project-ticketing - every category and where it points.
	 *
	 * @param WP_REST_Request $req
	 * @return array
	 */
	public static function rest_list( $req ) {
		$out = array();

		if ( taxonomy_exists( self::TAXONOMY ) ) {
			$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
			foreach ( (array) $terms as $term ) {
				if ( is_wp_error( $term ) ) {
					continue;
				}
				$pid = self::get_project_id( (int) $term->term_id );
				$out[] = array(
					'term_id'      => (int) $term->term_id,
					'category'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'events'       => (int) $term->count,
					'project_id'   => $pid,
					'project'      => $pid ? get_the_title( $pid ) : null,
					'project_status' => $pid ? get_post_status( $pid ) : null,
				);
			}
		}

		return array(
			'site'       => home_url(),
			'count'      => count( $out ),
			'categories' => $out,
		);
	}

	/**
	 * POST /portal/project-ticketing/backfill - link or create for every
	 * existing category. Dry run unless apply=true.
	 *
	 * @param WP_REST_Request $req
	 * @return array
	 */
	public static function rest_backfill( $req ) {
		$apply = filter_var( $req->get_param( 'apply' ), FILTER_VALIDATE_BOOLEAN );
		$skip  = $req->get_param( 'skip_term_ids' );
		$skip  = is_array( $skip ) ? array_map( 'intval', $skip ) : array();

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return new WP_Error( 'ansp_no_taxonomy', 'event_category is not registered.', array( 'status' => 400 ) );
		}

		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
		$out   = array();

		foreach ( (array) $terms as $term ) {
			if ( is_wp_error( $term ) ) {
				continue;
			}
			$tid = (int) $term->term_id;

			if ( in_array( $tid, $skip, true ) ) {
				$out[] = array( 'term_id' => $tid, 'category' => $term->name, 'action' => 'skipped' );
				continue;
			}

			$linked = self::get_project_id( $tid );
			if ( $linked ) {
				$out[] = array( 'term_id' => $tid, 'category' => $term->name, 'project_id' => $linked, 'action' => 'already_linked' );
				continue;
			}

			$match = self::find_project_by_title( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
			$would = $match ? 'would_adopt' : 'would_create';

			if ( ! $apply ) {
				$out[] = array( 'term_id' => $tid, 'category' => $term->name, 'project_id' => $match ?: null, 'action' => $would );
				continue;
			}

			$pid   = self::ensure_project_for_category( $tid );
			$out[] = array(
				'term_id'    => $tid,
				'category'   => $term->name,
				'project_id' => $pid,
				'action'     => $match ? 'adopted' : 'created',
			);
		}

		return array( 'applied' => $apply, 'count' => count( $out ), 'results' => $out );
	}

	/**
	 * POST /portal/project/{id}/event-category  { term_id }
	 *
	 * @param WP_REST_Request $req
	 * @return array|WP_Error
	 */
	public static function rest_set( $req ) {
		$project_id = (int) $req['id'];
		$term_id    = (int) $req->get_param( 'term_id' );

		$res = self::set_category_id( $project_id, $term_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'project_id' => $project_id,
			'project'    => get_the_title( $project_id ),
			'term_id'    => self::get_category_id( $project_id ),
		);
	}
}
