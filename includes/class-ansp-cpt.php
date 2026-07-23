<?php
/**
 * The ans_project custom post type ("Projects").
 *
 * Projects are the unit of a season: a concert set, tour, recording, etc.
 * Each project carries dates/venue/brief meta (ANSP_Project_Meta), a
 * repeatable materials list with free-form tags (ANSP_Materials),
 * group + season terms, and RSVP data (ANSP_RSVP).
 *
 * The CPT is not public: singers read projects through the portal
 * shortcode, never through front-end permalinks. It lives under the
 * "Singers Portal" admin menu and uses its own capability set granted to
 * artistic_director + administrator (see ANSP_Roles).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_CPT
 */
class ANSP_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'ans_project';

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 10 );
	}

	/**
	 * Register the ans_project post type.
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
				'label'           => __( 'Projects', 'ans-singers-portal' ),
				'labels'          => array(
					'name'               => __( 'Projects', 'ans-singers-portal' ),
					'singular_name'      => __( 'Project', 'ans-singers-portal' ),
					'menu_name'          => __( 'Projects', 'ans-singers-portal' ),
					'add_new'            => __( 'Add New Project', 'ans-singers-portal' ),
					'add_new_item'       => __( 'Add New Project', 'ans-singers-portal' ),
					'edit_item'          => __( 'Edit Project', 'ans-singers-portal' ),
					'new_item'           => __( 'New Project', 'ans-singers-portal' ),
					'view_item'          => __( 'View Project', 'ans-singers-portal' ),
					'search_items'       => __( 'Search Projects', 'ans-singers-portal' ),
					'not_found'          => __( 'No projects found.', 'ans-singers-portal' ),
					'not_found_in_trash' => __( 'No projects found in Trash.', 'ans-singers-portal' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'ansp-dashboard', // Nested under the Singers Portal dashboard.
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => false,
				'supports'        => array( 'title' ),
				'taxonomies'      => array( 'ans_group', 'ans_season' ),
				'capability_type' => array( 'ans_project', 'ans_projects' ),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-format-audio',
			)
		);
	}
}
