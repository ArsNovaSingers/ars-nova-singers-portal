<?php
/**
 * Roles & capabilities.
 *
 * Creates the three portal roles on activation and grants the custom
 * capabilities used across the plugin:
 *
 * - ansp_view_portal   : may view the front-end portal.
 * - ansp_edit_own_bio  : may edit their own singer bio from the front end.
 * - ansp_manage_portal : may use the wp-admin Singers Portal dashboard
 *                        (projects, seasons, groups, calendars, settings).
 * - ansp_manage_roster : may manage singer profiles, group membership,
 *                        emails and invites (Personnel Manager).
 *
 * The ans_project CPT uses its own capability set (edit_ans_projects, …)
 * granted to artistic_director and administrator.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Roles
 */
class ANSP_Roles {

	/**
	 * All primitive capabilities generated for the ans_project CPT
	 * (capability_type "ans_project" with map_meta_cap enabled).
	 *
	 * @return string[]
	 */
	public static function project_caps() {
		return array(
			'edit_ans_project',
			'read_ans_project',
			'delete_ans_project',
			'edit_ans_projects',
			'edit_others_ans_projects',
			'publish_ans_projects',
			'read_private_ans_projects',
			'delete_ans_projects',
			'delete_private_ans_projects',
			'delete_published_ans_projects',
			'delete_others_ans_projects',
			'edit_private_ans_projects',
			'edit_published_ans_projects',
		);
	}

	/**
	 * Portal-specific capabilities (used by uninstall cleanup).
	 *
	 * @return string[]
	 */
	public static function portal_caps() {
		return array(
			'ansp_view_portal',
			'ansp_edit_own_bio',
			'ansp_manage_portal',
			'ansp_manage_roster',
		);
	}

	/**
	 * Create (or refresh) the three portal roles and grant administrator
	 * every portal capability. Removing then re-adding a role refreshes its
	 * capability list without touching user→role assignments.
	 *
	 * @return void
	 */
	public static function create_roles() {

		// ------------------------------------------------------------------
		// Singer: front-end portal only. No wp-admin (enforced in
		// ANSP_Login), no editing beyond their own bio via the portal form.
		// upload_files lets the front-end bio form store a headshot.
		// ------------------------------------------------------------------
		remove_role( 'singer' );
		add_role(
			'singer',
			__( 'Singer', 'ans-singers-portal' ),
			array(
				'read'              => true,
				'upload_files'      => true,
				'ansp_view_portal'  => true,
				'ansp_edit_own_bio' => true,
			)
		);

		// ------------------------------------------------------------------
		// Artistic Director (Tom): full Singers Portal dashboard — projects,
		// materials + tags, seasons/groups, roster, calendars,
		// announcements, notifications, RSVP overview.
		// ------------------------------------------------------------------
		$ad_caps = array(
			'read'               => true,
			'upload_files'       => true,
			'edit_posts'         => true,
			'edit_others_posts'  => true,
			'edit_published_posts' => true,
			'publish_posts'      => true,
			'delete_posts'       => true,
			'delete_published_posts' => true,
			'ansp_view_portal'   => true,
			'ansp_edit_own_bio'  => true,
			'ansp_manage_portal' => true,
			'ansp_manage_roster' => true,
		);
		foreach ( self::project_caps() as $cap ) {
			$ad_caps[ $cap ] = true;
		}
		remove_role( 'artistic_director' );
		add_role( 'artistic_director', __( 'Artistic Director', 'ans-singers-portal' ), $ad_caps );

		// ------------------------------------------------------------------
		// Personnel Manager (Zahnay): manages all singer profiles, group
		// membership, emails and account invites. The generic edit_* post
		// caps cover the third-party "singer" CPT (capability_type "post").
		// ------------------------------------------------------------------
		remove_role( 'personnel_manager' );
		add_role(
			'personnel_manager',
			__( 'Personnel Manager', 'ans-singers-portal' ),
			array(
				'read'                 => true,
				'upload_files'         => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'list_users'           => true,
				'create_users'         => true,
				'ansp_view_portal'     => true,
				'ansp_edit_own_bio'    => true,
				'ansp_manage_portal'   => true,
				'ansp_manage_roster'   => true,
			)
		);

		// ------------------------------------------------------------------
		// Administrators receive every portal + project capability.
		// ------------------------------------------------------------------
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::portal_caps(), self::project_caps() ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the portal roles and strip portal caps from administrator.
	 * Only called from uninstall.php when the site owner opts in.
	 *
	 * @return void
	 */
	public static function remove_roles() {
		remove_role( 'singer' );
		remove_role( 'artistic_director' );
		remove_role( 'personnel_manager' );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::portal_caps(), self::project_caps() ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
