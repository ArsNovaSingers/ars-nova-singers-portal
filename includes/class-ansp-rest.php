<?php
/**
 * Ars Nova Singers Portal — admin-only REST surface.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every content type this plugin owns is registered with show_in_rest => false:
 * ans_project, ans_announcement, ans_group, ans_season, and the materials array
 * that lives in post meta. That was a reasonable default — none of it should be
 * public — but it also meant NOTHING could be read or written by command. Every
 * change had to be made by hand in wp-admin.
 *
 * The cost was not theoretical. On 2026-08-21 a single evening's work stalled
 * repeatedly on it: a material could not be attached to a project, an
 * announcement could not be posted, a group's name could not be read to find out
 * what the groups were even called, and an access code's group could not be set.
 * Each one became a numbered instruction for a human to follow instead.
 *
 * NAMESPACE — DO NOT "TIDY" THIS
 * ------------------------------
 * These routes register into `ars-nova/v1`, which belongs to the ars-nova-bridge
 * plugin, rather than a namespace of this plugin's own. That is deliberate and
 * load-bearing: the WordPress MCP connector accepts exactly four namespaces
 * (ars-nova/v1, ans-ops/v1, ans-notes/v1, ansg/v1). A tidier `ansp/v1` would be
 * correct-looking and completely unreachable. ars-nova-media made the same
 * choice for the same reason.
 *
 * All routes sit under /portal/ so they cannot collide with the bridge's own.
 *
 * SAFETY
 * ------
 * - Admin-only: ansp_manage_portal, or manage_options.
 * - Destructive calls on production require confirm_production=true, matching
 *   ars-nova-ops.
 * - Deletes trash rather than erase, so a mistake is recoverable.
 * - Credential-shaped option values are masked on read.
 *
 * @package ArsNovaSingersPortal
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the portal's admin REST surface.
 */
class ANSP_REST {

	/** Namespace the connector can actually reach. See the class docblock. */
	const NS = 'ars-nova/v1';

	/** Option keys this plugin owns, exposed through /portal/settings. */
	const SETTINGS = array(
		'ansp_current_season',
		'ansp_portal_page_id',
		'ansp_calendar_main',
		'ansp_calendar_small',
		'ansp_calendar_friday',
		'ansp_invite_email_subject',
		'ansp_invite_email_body',
		'ansp_registration_notify',
	);

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Only people who already administer the portal.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'ansp_manage_portal' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Is this the public production site?
	 *
	 * @return bool
	 */
	public static function is_production() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$prod = apply_filters(
			'ansp_production_hosts',
			array( 'arsnovasingers.org', 'www.arsnovasingers.org', 'arsnovasingers.kinsta.cloud' )
		);
		return in_array( strtolower( (string) $host ), array_map( 'strtolower', $prod ), true );
	}

	/**
	 * Gate a destructive call on production behind an explicit flag.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return true|WP_Error
	 */
	protected static function guard( $req ) {
		if ( ! self::is_production() ) {
			return true;
		}
		if ( filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return true;
		}
		return new WP_Error(
			'ansp_production_blocked',
			'This is the production site. Resend with confirm_production=true to proceed.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$perm = array( __CLASS__, 'can_manage' );

		$routes = array(
			'/portal/status'        => array( 'GET' => 'status' ),
			'/portal/groups'        => array( 'GET' => 'get_groups', 'POST' => 'update_group' ),
			'/portal/seasons'       => array( 'GET' => 'get_seasons', 'POST' => 'update_season' ),
			'/portal/projects'      => array( 'GET' => 'get_projects', 'POST' => 'save_project' ),
			'/portal/materials'     => array( 'GET' => 'get_materials', 'POST' => 'save_materials' ),
			'/portal/announcements' => array( 'GET' => 'get_announcements', 'POST' => 'save_announcement' ),
			'/portal/singers'       => array( 'GET' => 'get_singers', 'POST' => 'save_singer' ),
			'/portal/settings'      => array( 'GET' => 'get_settings', 'POST' => 'save_settings' ),
			'/portal/codes'         => array( 'GET' => 'get_codes', 'POST' => 'save_code' ),
		);

		foreach ( $routes as $route => $methods ) {
			$args = array();
			foreach ( $methods as $method => $callback ) {
				$args[] = array(
					'methods'             => $method,
					'permission_callback' => $perm,
					'callback'            => array( $this, $callback ),
				);
			}
			register_rest_route( self::NS, $route, $args );
		}

		// Trashing is its own route so it can never be reached by a stray POST.
		register_rest_route(
			self::NS,
			'/portal/trash',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'trash_post' ),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * Status
	 * ---------------------------------------------------------------- */

	/**
	 * Sanity check and a count of everything this plugin owns.
	 *
	 * @return array
	 */
	public function status() {
		$season = ANSP_Taxonomies::get_current_season();

		return array(
			'ok'             => true,
			'plugin'         => 'ars-nova-singers-portal',
			'version'        => defined( 'ANSP_VERSION' ) ? ANSP_VERSION : '',
			'site'           => home_url(),
			'is_production'  => self::is_production(),
			'portal_page'    => (int) get_option( 'ansp_portal_page_id', 0 ),
			'current_season' => $season instanceof WP_Term
				? array( 'term_id' => (int) $season->term_id, 'name' => $season->name, 'slug' => $season->slug )
				: null,
			'season_pinned'  => (bool) get_option( 'ansp_current_season' ),
			'counts'         => array(
				'groups'        => (int) wp_count_terms( array( 'taxonomy' => 'ans_group', 'hide_empty' => false ) ),
				'seasons'       => (int) wp_count_terms( array( 'taxonomy' => 'ans_season', 'hide_empty' => false ) ),
				'projects'      => (int) wp_count_posts( ANSP_CPT::POST_TYPE )->publish,
				'announcements' => (int) wp_count_posts( ANSP_Announcements::POST_TYPE )->publish,
				'singers'       => post_type_exists( 'singer' ) ? (int) wp_count_posts( 'singer' )->publish : 0,
			),
		);
	}

	/* -------------------------------------------------------------------
	 * Groups
	 * ---------------------------------------------------------------- */

	/**
	 * Every group, with its Drive folder mapping and filter tag.
	 *
	 * This is the read that was impossible before: ans_group is not in REST, so
	 * there was no way to answer "what are the groups called?" without opening
	 * wp-admin.
	 *
	 * @return array
	 */
	public function get_groups() {
		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return array( 'count' => 0, 'items' => array() );
		}

		$terms = get_terms( array( 'taxonomy' => 'ans_group', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array( 'count' => 0, 'items' => array(), 'error' => $terms->get_error_message() );
		}

		$items = array();
		foreach ( $terms as $t ) {
			$items[] = array(
				'term_id'           => (int) $t->term_id,
				'name'              => $t->name,
				'slug'              => $t->slug,
				'description'       => $t->description,
				'singer_count'      => (int) $t->count,
				'drive_folder_id'   => get_term_meta( $t->term_id, 'ansp_group_drive_folder_id', true ),
				'drive_folder_name' => get_term_meta( $t->term_id, 'ansp_group_drive_folder_name', true ),
				'drive_status'      => get_term_meta( $t->term_id, 'ansp_group_drive_status', true ),
				'filter_tag'        => get_term_meta( $t->term_id, 'ansp_group_tag', true ),
			);
		}

		return array( 'count' => count( $items ), 'items' => $items );
	}

	/**
	 * Rename a group, or set its Drive folder / filter tag.
	 *
	 * Renaming matters more than it looks: group names are singer-facing tab
	 * labels, so a typo in a term name is a typo in front of the whole choir.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function update_group( $req ) {
		$term_id = (int) $req->get_param( 'term_id' );
		$slug    = sanitize_key( (string) $req->get_param( 'slug' ) );

		if ( ! $term_id && '' !== $slug ) {
			$found = get_term_by( 'slug', $slug, 'ans_group' );
			if ( $found instanceof WP_Term ) {
				$term_id = (int) $found->term_id;
			}
		}
		if ( ! $term_id ) {
			return new WP_Error( 'ansp_no_group', 'Pass term_id or slug of an existing group.', array( 'status' => 400 ) );
		}

		$name = $req->get_param( 'name' );
		if ( null !== $name && '' !== trim( (string) $name ) ) {
			wp_update_term( $term_id, 'ans_group', array( 'name' => sanitize_text_field( (string) $name ) ) );
		}

		$folder = $req->get_param( 'drive_folder' );
		if ( null !== $folder ) {
			$parsed = ANSP_Group_Fields::parse_folder_id( (string) $folder );
			update_term_meta( $term_id, 'ansp_group_drive_folder_id', $parsed );
		}

		$tag = $req->get_param( 'filter_tag' );
		if ( null !== $tag ) {
			update_term_meta( $term_id, 'ansp_group_tag', ANSP_Group_Fields::normalize_tag( (string) $tag ) );
		}

		$term = get_term( $term_id, 'ans_group' );
		return array(
			'ok'         => true,
			'term_id'    => $term_id,
			'name'       => $term instanceof WP_Term ? $term->name : '',
			'slug'       => $term instanceof WP_Term ? $term->slug : '',
			'drive_folder_id' => get_term_meta( $term_id, 'ansp_group_drive_folder_id', true ),
			'filter_tag'      => get_term_meta( $term_id, 'ansp_group_tag', true ),
		);
	}

	/* -------------------------------------------------------------------
	 * Seasons
	 * ---------------------------------------------------------------- */

	/**
	 * Every season, flagging which one the portal is currently pinned to.
	 *
	 * @return array
	 */
	public function get_seasons() {
		if ( ! taxonomy_exists( 'ans_season' ) ) {
			return array( 'count' => 0, 'items' => array() );
		}

		$terms   = get_terms( array( 'taxonomy' => 'ans_season', 'hide_empty' => false ) );
		$current = ANSP_Taxonomies::get_current_season();
		$pinned  = (int) get_option( 'ansp_current_season', 0 );

		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = array(
					'term_id'    => (int) $t->term_id,
					'name'       => $t->name,
					'slug'       => $t->slug,
					'is_current' => ( $current instanceof WP_Term && (int) $current->term_id === (int) $t->term_id ),
					'brief_url'  => ANSP_Taxonomies::get_season_brief_url( $t->term_id ),
				);
			}
		}

		return array(
			'count'  => count( $items ),
			'pinned' => $pinned,
			'note'   => $pinned
				? 'Season is pinned explicitly.'
				: 'No season pinned - the portal falls back to the newest term, which changes silently when a season is added.',
			'items'  => $items,
		);
	}

	/**
	 * Pin the current season, or rename one.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function update_season( $req ) {
		$term_id = (int) $req->get_param( 'term_id' );
		if ( ! $term_id ) {
			return new WP_Error( 'ansp_no_season', 'Pass term_id.', array( 'status' => 400 ) );
		}

		$name = $req->get_param( 'name' );
		if ( null !== $name && '' !== trim( (string) $name ) ) {
			wp_update_term( $term_id, 'ans_season', array( 'name' => sanitize_text_field( (string) $name ) ) );
		}

		if ( filter_var( $req->get_param( 'set_current' ), FILTER_VALIDATE_BOOLEAN ) ) {
			update_option( 'ansp_current_season', $term_id );
		}

		return $this->get_seasons();
	}

	/* -------------------------------------------------------------------
	 * Projects
	 * ---------------------------------------------------------------- */

	/**
	 * Shape one project for output.
	 *
	 * @param WP_Post $p Project post.
	 * @return array
	 */
	protected function project_payload( $p ) {
		$id = (int) $p->ID;
		return array(
			'id'          => $id,
			'title'       => get_the_title( $p ),
			'status'      => $p->post_status,
			'season'      => wp_get_object_terms( $id, 'ans_season', array( 'fields' => 'names' ) ),
			'groups'      => wp_get_object_terms( $id, 'ans_group', array( 'fields' => 'slugs' ) ),
			'group_names' => wp_get_object_terms( $id, 'ans_group', array( 'fields' => 'names' ) ),
			'date_start'  => ANSP_Project_Meta::get( $id, 'date_start' ),
			'date_end'    => ANSP_Project_Meta::get( $id, 'date_end' ),
			'venue'       => ANSP_Project_Meta::get( $id, 'venue' ),
			'description' => ANSP_Project_Meta::get( $id, 'description' ),
			'brief_url'   => ANSP_Project_Meta::get( $id, 'brief_url' ),
			'project_status' => ANSP_Project_Meta::get( $id, 'status' ),
			'material_count' => count( ANSP_Materials::get_materials( $id ) ),
		);
	}

	/**
	 * List projects, or fetch one with its materials.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function get_projects( $req ) {
		$id = (int) $req->get_param( 'id' );

		if ( $id ) {
			$p = get_post( $id );
			if ( ! $p || ANSP_CPT::POST_TYPE !== $p->post_type ) {
				return array( 'error' => 'Not a project.', 'id' => $id );
			}
			$out              = $this->project_payload( $p );
			$out['materials'] = ANSP_Materials::get_materials( $id );
			return $out;
		}

		$posts = get_posts(
			array(
				'post_type'      => ANSP_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$items[] = $this->project_payload( $p );
		}

		return array( 'count' => count( $items ), 'items' => $items );
	}

	/**
	 * Create or update a project.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_project( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id    = (int) $req->get_param( 'id' );
		$title = $req->get_param( 'title' );

		if ( ! $id ) {
			if ( null === $title || '' === trim( (string) $title ) ) {
				return new WP_Error( 'ansp_no_title', 'A new project needs a title.', array( 'status' => 400 ) );
			}
			$id = wp_insert_post(
				array(
					'post_type'   => ANSP_CPT::POST_TYPE,
					'post_title'  => sanitize_text_field( (string) $title ),
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$id = (int) $id;
		} elseif ( null !== $title && '' !== trim( (string) $title ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( (string) $title ) ) );
		}

		$p = get_post( $id );
		if ( ! $p || ANSP_CPT::POST_TYPE !== $p->post_type ) {
			return new WP_Error( 'ansp_not_project', 'Not a project.', array( 'status' => 404 ) );
		}

		foreach ( array( 'date_start', 'date_end', 'venue', 'description', 'brief_url', 'status' ) as $key ) {
			$val = $req->get_param( $key );
			if ( null !== $val ) {
				update_post_meta( $id, 'ansp_project_' . $key, sanitize_text_field( (string) $val ) );
			}
		}

		$season = $req->get_param( 'season' );
		if ( null !== $season ) {
			wp_set_object_terms( $id, is_array( $season ) ? $season : array( $season ), 'ans_season', false );
		}

		$groups = $req->get_param( 'groups' );
		if ( null !== $groups ) {
			$groups = is_array( $groups ) ? $groups : array_map( 'trim', explode( ',', (string) $groups ) );
			wp_set_object_terms( $id, array_filter( $groups ), 'ans_group', false );
		}

		// WP_REST_Request's third constructor argument is attributes, not params,
		// so the id has to be set explicitly or this would echo every project back.
		$echo = new WP_REST_Request( 'GET', '' );
		$echo->set_param( 'id', $id );

		return $this->get_projects( $echo );
	}

	/* -------------------------------------------------------------------
	 * Materials
	 * ---------------------------------------------------------------- */

	/**
	 * Read a project's materials.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function get_materials( $req ) {
		$id = (int) $req->get_param( 'project_id' );
		if ( ! $id ) {
			return array( 'error' => 'Pass project_id.' );
		}
		return array(
			'project_id' => $id,
			'title'      => get_the_title( $id ),
			'materials'  => ANSP_Materials::get_materials( $id ),
			'types'      => array_keys( ANSP_Materials::types() ),
		);
	}

	/**
	 * Add, replace or remove a project's materials.
	 *
	 * Default is APPEND, because the common case is "Tom sent this week's doc"
	 * and a default of replace would quietly delete the rest of the season's
	 * materials on a one-row call. Pass replace=true to set the whole array.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_materials( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id = (int) $req->get_param( 'project_id' );
		if ( ! $id || ANSP_CPT::POST_TYPE !== get_post_type( $id ) ) {
			return new WP_Error( 'ansp_not_project', 'Pass project_id of a real project.', array( 'status' => 400 ) );
		}

		$incoming = $req->get_param( 'materials' );
		$remove   = $req->get_param( 'remove_id' );
		$existing = ANSP_Materials::get_materials( $id );

		if ( null !== $remove ) {
			$remove = (string) $remove;
			$kept   = array();
			foreach ( $existing as $row ) {
				if ( ! isset( $row['id'] ) || (string) $row['id'] !== $remove ) {
					$kept[] = $row;
				}
			}
			update_post_meta( $id, ANSP_Materials::META_KEY, $kept );
			return $this->get_materials( $req );
		}

		if ( ! is_array( $incoming ) ) {
			return new WP_Error( 'ansp_no_materials', 'Pass materials as an array of rows, or remove_id.', array( 'status' => 400 ) );
		}

		$types = ANSP_Materials::types();
		$clean = array();
		foreach ( $incoming as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'drive_link';
			if ( ! isset( $types[ $type ] ) ) {
				$type = 'drive_link';
			}
			$clean[] = array(
				'id'     => isset( $row['id'] ) && '' !== $row['id'] ? sanitize_text_field( (string) $row['id'] ) : uniqid( 'ansp_' ),
				'type'   => $type,
				'title'  => isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '',
				'url'    => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
				'note'   => isset( $row['note'] ) ? sanitize_text_field( (string) $row['note'] ) : '',
				'tags'   => ANSP_Materials::sanitize_tags( isset( $row['tags'] ) ? $row['tags'] : array() ),
				'groups' => ANSP_Materials::get_groups( $row ),
			);
		}

		$replace = filter_var( $req->get_param( 'replace' ), FILTER_VALIDATE_BOOLEAN );
		$final   = $replace ? $clean : array_merge( $existing, $clean );

		update_post_meta( $id, ANSP_Materials::META_KEY, $final );

		return $this->get_materials( $req );
	}

	/* -------------------------------------------------------------------
	 * Announcements
	 * ---------------------------------------------------------------- */

	/**
	 * List announcements.
	 *
	 * @return array
	 */
	public function get_announcements() {
		$posts = get_posts(
			array(
				'post_type'      => ANSP_Announcements::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$items[] = array(
				'id'      => (int) $p->ID,
				'title'   => get_the_title( $p ),
				'status'  => $p->post_status,
				'date'    => $p->post_date,
				'all'     => (bool) get_post_meta( $p->ID, 'ansp_all', true ),
				'groups'  => wp_get_object_terms( $p->ID, 'ans_group', array( 'fields' => 'slugs' ) ),
				'excerpt' => wp_trim_words( wp_strip_all_tags( $p->post_content ), 40 ),
			);
		}

		return array( 'count' => count( $items ), 'items' => $items );
	}

	/**
	 * Create or update an announcement.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_announcement( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id      = (int) $req->get_param( 'id' );
		$title   = $req->get_param( 'title' );
		$content = $req->get_param( 'content' );
		$status  = sanitize_key( (string) $req->get_param( 'status' ) );
		$status  = in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'publish';

		$data = array( 'post_type' => ANSP_Announcements::POST_TYPE, 'post_status' => $status );
		if ( null !== $title ) {
			$data['post_title'] = sanitize_text_field( (string) $title );
		}
		if ( null !== $content ) {
			$data['post_content'] = wp_kses_post( (string) $content );
		}

		if ( $id ) {
			$data['ID'] = $id;
			$res        = wp_update_post( $data, true );
		} else {
			if ( empty( $data['post_title'] ) ) {
				return new WP_Error( 'ansp_no_title', 'A new announcement needs a title.', array( 'status' => 400 ) );
			}
			$res = wp_insert_post( $data, true );
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (int) $res;

		// Visible to everyone unless specific groups are named. A new singer may
		// have no group yet, so "all" is the safe default for a welcome notice.
		$all = $req->get_param( 'all' );
		if ( null !== $all ) {
			update_post_meta( $id, 'ansp_all', filter_var( $all, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0 );
		}

		$groups = $req->get_param( 'groups' );
		if ( null !== $groups ) {
			$groups = is_array( $groups ) ? $groups : array_map( 'trim', explode( ',', (string) $groups ) );
			wp_set_object_terms( $id, array_filter( $groups ), 'ans_group', false );
		}

		return array( 'ok' => true, 'id' => $id, 'permalink' => get_permalink( $id ), 'announcements' => $this->get_announcements() );
	}

	/* -------------------------------------------------------------------
	 * Singers
	 * ---------------------------------------------------------------- */

	/**
	 * List singer profiles with their groups and linked user.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function get_singers( $req ) {
		if ( ! post_type_exists( 'singer' ) ) {
			return array( 'count' => 0, 'items' => array(), 'note' => 'The singer post type is not registered.' );
		}

		$search = (string) $req->get_param( 'search' );
		$posts  = get_posts(
			array(
				'post_type'      => 'singer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => $search,
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$items[] = array(
				'id'          => (int) $p->ID,
				'name'        => get_the_title( $p ),
				'slug'        => $p->post_name,
				'status'      => $p->post_status,
				'permalink'   => get_permalink( $p ),
				'groups'      => wp_get_object_terms( $p->ID, 'ans_group', array( 'fields' => 'slugs' ) ),
				'group_names' => wp_get_object_terms( $p->ID, 'ans_group', array( 'fields' => 'names' ) ),
				'has_photo'   => (bool) get_post_thumbnail_id( $p->ID ),
			);
		}

		return array( 'count' => count( $items ), 'items' => $items );
	}

	/**
	 * Update a singer profile's groups or status.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_singer( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id = (int) $req->get_param( 'id' );
		if ( ! $id || 'singer' !== get_post_type( $id ) ) {
			return new WP_Error( 'ansp_not_singer', 'Pass id of a singer profile.', array( 'status' => 400 ) );
		}

		$groups = $req->get_param( 'groups' );
		if ( null !== $groups ) {
			$groups = is_array( $groups ) ? $groups : array_map( 'trim', explode( ',', (string) $groups ) );
			wp_set_object_terms( $id, array_filter( $groups ), 'ans_group', false );
		}

		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
		}

		return $this->get_singers( new WP_REST_Request( 'GET', '' ) );
	}

	/* -------------------------------------------------------------------
	 * Trash
	 * ---------------------------------------------------------------- */

	/**
	 * Trash any post this plugin owns.
	 *
	 * Trash, never delete: WordPress keeps it for 30 days, so a wrong id is an
	 * inconvenience rather than a loss. Restricted to this plugin's own post
	 * types so it can never be pointed at a page or a WooCommerce order.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function trash_post( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id = (int) $req->get_param( 'id' );
		if ( ! $id ) {
			return new WP_Error( 'ansp_no_id', 'Pass id.', array( 'status' => 400 ) );
		}

		$type    = get_post_type( $id );
		$allowed = array( ANSP_CPT::POST_TYPE, ANSP_Announcements::POST_TYPE, 'singer' );
		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error(
				'ansp_wrong_type',
				sprintf( 'Refusing to trash a "%s". This route only handles: %s.', $type, implode( ', ', $allowed ) ),
				array( 'status' => 400 )
			);
		}

		$res = wp_trash_post( $id );
		if ( ! $res ) {
			return new WP_Error( 'ansp_trash_failed', 'WordPress refused to trash that post.', array( 'status' => 500 ) );
		}

		return array( 'ok' => true, 'id' => $id, 'type' => $type, 'status' => get_post_status( $id ), 'note' => 'Trashed, not deleted - recoverable from wp-admin.' );
	}

	/* -------------------------------------------------------------------
	 * Settings + access codes
	 * ---------------------------------------------------------------- */

	/**
	 * The plugin's own options.
	 *
	 * @return array
	 */
	public function get_settings() {
		$out = array();
		foreach ( self::SETTINGS as $key ) {
			$out[ $key ] = get_option( $key, '' );
		}
		// Never return the key itself; confirming it EXISTS is the useful part.
		$out['ansp_gemini_api_key'] = get_option( 'ansp_gemini_api_key' ) ? '(set)' : '(not set)';

		return array( 'settings' => $out );
	}

	/**
	 * Update the plugin's options.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_settings( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$written = array();
		foreach ( self::SETTINGS as $key ) {
			$val = $req->get_param( $key );
			if ( null !== $val ) {
				update_option( $key, sanitize_text_field( (string) $val ) );
				$written[] = $key;
			}
		}

		return array( 'ok' => true, 'written' => $written, 'settings' => $this->get_settings() );
	}

	/**
	 * Access codes, with the code itself masked.
	 *
	 * The group each code assigns is the operationally useful part and is shown
	 * in full; the code is the secret and is not.
	 *
	 * @return array
	 */
	public function get_codes() {
		$codes = ANSP_Registration::get_codes();
		$out   = array();

		foreach ( $codes as $key => $def ) {
			$code = (string) $def['code'];
			$out[ $key ] = array(
				'label'      => $def['label'],
				'role'       => $def['role'],
				'group'      => $def['group'],
				'enabled'    => (bool) $def['enabled'],
				'expires'    => $def['expires'],
				'max_uses'   => (int) $def['max_uses'],
				'uses'       => (int) $def['uses'],
				'code_masked' => '' === $code ? '(none)' : substr( $code, 0, 4 ) . str_repeat( '*', max( 0, strlen( $code ) - 4 ) ),
			);
		}

		return array( 'codes' => $out, 'note' => 'Codes are masked. Read the full value from the Access Codes screen.' );
	}

	/**
	 * Set a code's group, enabled state, expiry or use cap.
	 *
	 * Deliberately cannot set the code STRING - rotating a code is a decision a
	 * human should make on the screen that also shows who has used it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function save_code( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$key   = sanitize_key( (string) $req->get_param( 'key' ) );
		$codes = ANSP_Registration::get_codes();

		if ( '' === $key || ! isset( $codes[ $key ] ) ) {
			return new WP_Error(
				'ansp_no_code',
				sprintf( 'Pass key of an existing code: %s', implode( ', ', array_keys( $codes ) ) ),
				array( 'status' => 400 )
			);
		}

		$group = $req->get_param( 'group' );
		if ( null !== $group ) {
			$codes[ $key ]['group'] = sanitize_key( (string) $group );
		}

		$enabled = $req->get_param( 'enabled' );
		if ( null !== $enabled ) {
			$codes[ $key ]['enabled'] = filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
		}

		$expires = $req->get_param( 'expires' );
		if ( null !== $expires ) {
			$codes[ $key ]['expires'] = sanitize_text_field( (string) $expires );
		}

		$max = $req->get_param( 'max_uses' );
		if ( null !== $max ) {
			$codes[ $key ]['max_uses'] = absint( $max );
		}

		update_option( 'ansp_access_codes', $codes );

		return array( 'ok' => true, 'key' => $key, 'codes' => $this->get_codes() );
	}
}
