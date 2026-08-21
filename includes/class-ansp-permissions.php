<?php
/**
 * Visibility engine.
 *
 * Answers one question everywhere: "can this user see this item?", where an
 * item is either a material row (array), a group-scoped pseudo-item (array
 * with a 'permission' key but no material fields — used by Announcements),
 * or an ans_project post/ID.
 *
 * Rules (v1.2.0 — the tag + filter model):
 * - Administrators, artistic_director and personnel_manager always see everything.
 * - MATERIALS are gated by their Groups only: a material is visible when it
 *   has no group checked (everyone) or the user's groups intersect its groups.
 *   Tags on materials are purely for front-end filtering, not access control.
 *   Legacy per-material `permission` data (v1.0–v1.1) is ignored.
 * - PROJECTS stay lightly scoped by the ans_group taxonomy: a project is
 *   visible when its own group tags intersect the user's groups (an untagged
 *   project counts as "all").
 * - ANNOUNCEMENTS (group-scoped pseudo-items) are visible when marked ALL or
 *   when the user's groups intersect the announcement's groups.
 * - A singer's groups are the ans_group terms on THEIR linked singer profile
 *   (user meta ansp_singer_profile → post terms).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Permissions
 */
class ANSP_Permissions {

	/**
	 * Is this user a portal manager (sees everything)?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_manager( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		return user_can( $user, 'manage_options' )
			|| user_can( $user, 'ansp_manage_portal' )
			|| user_can( $user, 'ansp_manage_roster' );
	}

	/**
	 * The ans_group slugs a user belongs to, via their linked singer profile.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return string[] Group slugs (empty when unlinked / no groups).
	 */
	public static function get_user_group_slugs( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$profile_id = (int) get_user_meta( $user_id, 'ansp_singer_profile', true );
		if ( ! $profile_id || ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}

		$slugs = wp_get_object_terms( $profile_id, 'ans_group', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $slugs ) || ! is_array( $slugs ) ) {
			return array();
		}
		return array_map( 'strval', $slugs );
	}

	/**
	 * The groups a viewer should get their own Materials tab for.
	 *
	 * A singer in one group gets one tab and never learns the others exist.
	 * Someone in both — the February concert pairs the full choir with
	 * Chamber Singers — gets both, which is the whole point: their music
	 * lives in two different places and always has.
	 *
	 * Managers see every group, because the person setting materials up has
	 * to be able to look at what each group will actually see.
	 *
	 * Returns terms, not slugs, so the caller can label a tab with the
	 * group's own name. Rename the group in wp-admin and the tab follows —
	 * nothing here hardcodes "Chamber Singers".
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return WP_Term[] Group terms, empty when the viewer has none.
	 */
	public static function get_visible_groups( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id || ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}

		if ( self::is_manager( $user_id ) ) {
			/*
			 * Managers get every group that actually has projects — not every
			 * group that exists.
			 *
			 * The groups on this site are not two ensembles; they are Full
			 * Chorus, Chamber Singers, Ensemble Singers, Board Member, High
			 * School Apprentice and Administrator. Handing an admin one
			 * Materials tab per group would put six tabs across the top, most
			 * of them permanently empty, and bury the two that hold music.
			 *
			 * hide_empty is about the taxonomy count, which includes singers,
			 * so it cannot answer "does this group have PROJECTS". Ask that
			 * directly.
			 */
			$terms = get_terms(
				array(
					'taxonomy'   => 'ans_group',
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				return array();
			}

			$with_projects = array();
			foreach ( $terms as $term ) {
				$found = get_posts(
					array(
						'post_type'      => ANSP_CPT::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'tax_query'      => array(
							array(
								'taxonomy' => 'ans_group',
								'field'    => 'term_id',
								'terms'    => (int) $term->term_id,
							),
						),
					)
				);
				if ( ! empty( $found ) ) {
					$with_projects[] = $term;
				}
			}

			return $with_projects;
		}

		$slugs = self::get_user_group_slugs( $user_id );
		if ( empty( $slugs ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
				'slug'       => $slugs,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * The voice parts of a user's linked singer profile (canonical `parts`
	 * meta, with the legacy single `voice_part` fallback).
	 *
	 * Kept for template/roster use — no longer used for material gating.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return string[] Voice part names (empty when unlinked / none set).
	 */
	public static function get_user_voice_parts( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$profile_id = (int) get_user_meta( $user_id, 'ansp_singer_profile', true );
		if ( ! $profile_id ) {
			return array();
		}
		return ANSP_Singer_CPT::get_parts( $profile_id );
	}

	/**
	 * Core check: can a user see a material row, a group-scoped pseudo-item,
	 * or a project?
	 *
	 * @param array|int|WP_Post $item    Material row / pseudo-item array, or
	 *                                   an ans_project post/ID.
	 * @param int|null          $user_id User ID (defaults to current user).
	 * @return bool
	 */
	public static function user_can_see( $item, $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false; // Never expose anything to logged-out visitors.
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( self::is_manager( $user_id ) ) {
			return true;
		}

		// Portal access is required for everyone else.
		if ( ! user_can( $user, 'ansp_view_portal' ) ) {
			return false;
		}

		// ---- Array items --------------------------------------------------
		if ( is_array( $item ) ) {
			// Material rows (they carry material fields — url/type/title/tags)
			// are ALWAYS visible to portal users since v1.2.0. Any legacy
			// `permission` key on old saved rows is ignored, never fatal.
			if ( isset( $item['url'] ) || isset( $item['type'] ) || isset( $item['title'] ) || isset( $item['tags'] ) || isset( $item['groups'] ) ) {
				// Material row: GATED by its Groups only (none checked = everyone).
				// Tags are filter-only, never access control.
				$mgroups = ANSP_Materials::get_groups( $item );
				if ( empty( $mgroups ) ) {
					return true;
				}
				return (bool) array_intersect( $mgroups, self::get_user_group_slugs( $user_id ) );
			}

			// Group-scoped pseudo-items (Announcements): visible when marked
			// ALL or when the user's groups intersect the item's groups.
			$perm = isset( $item['permission'] ) && is_array( $item['permission'] ) ? $item['permission'] : array();
			return self::group_permission_matches( $perm, $user_id );
		}

		// ---- Project ------------------------------------------------------
		$post = get_post( $item );
		if ( ! $post instanceof WP_Post || ANSP_CPT::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		$project_groups = wp_get_object_terms( $post->ID, 'ans_group', array( 'fields' => 'slugs' ) );
		$project_groups = is_wp_error( $project_groups ) ? array() : (array) $project_groups;

		// Untagged project → treated as visible to every portal user.
		if ( empty( $project_groups ) ) {
			return true;
		}

		$user_groups = self::get_user_group_slugs( $user_id );
		return (bool) array_intersect( $project_groups, $user_groups );
	}

	/**
	 * Does a group-scoped permission array (ALL flag + group slugs) match this
	 * user? Used by Announcements only — materials are no longer gated.
	 *
	 * @param array $perm    Raw permission array ('all' bool, 'groups' slugs).
	 * @param int   $user_id User ID.
	 * @return bool
	 */
	protected static function group_permission_matches( $perm, $user_id ) {
		if ( ! empty( $perm['all'] ) ) {
			return true;
		}

		$groups = array();
		if ( ! empty( $perm['groups'] ) && is_array( $perm['groups'] ) ) {
			foreach ( $perm['groups'] as $slug ) {
				$slug = sanitize_title( (string) $slug );
				if ( '' !== $slug ) {
					$groups[] = $slug;
				}
			}
		}
		if ( empty( $groups ) ) {
			return false;
		}
		return (bool) array_intersect( $groups, self::get_user_group_slugs( $user_id ) );
	}

	/**
	 * The material rows of a project that this user may see.
	 *
	 * Materials are gated by their Groups: a portal user sees a material with
	 * no group checked (everyone) or whose groups intersect theirs. Managers
	 * see all. Logged-out visitors get nothing.
	 *
	 * @param int      $project_id Project post ID.
	 * @param int|null $user_id    User ID (defaults to current user).
	 * @return array[] Visible material rows.
	 */
	public static function get_visible_materials( $project_id, $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$is_manager = self::is_manager( $user_id );
		if ( ! $is_manager && ! user_can( $user_id, 'ansp_view_portal' ) ) {
			return array();
		}
		$all = ANSP_Materials::get_materials( (int) $project_id );
		if ( $is_manager ) {
			return $all;
		}
		$user_groups = self::get_user_group_slugs( $user_id );
		$visible     = array();
		foreach ( $all as $row ) {
			$groups = ANSP_Materials::get_groups( $row );
			if ( empty( $groups ) || array_intersect( $groups, $user_groups ) ) {
				$visible[] = $row;
			}
		}
		return $visible;
	}
}

/**
 * Template-friendly wrapper: can a user see a material row or project?
 *
 * @param array|int|WP_Post $item    Material row array or project post/ID.
 * @param int|null          $user_id User ID (defaults to current user).
 * @return bool
 */
function ansp_user_can_see( $item, $user_id = null ) {
	return ANSP_Permissions::user_can_see( $item, $user_id );
}

/**
 * Template-friendly wrapper: the group slugs for a user.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return string[]
 */
function ansp_get_user_group_slugs( $user_id = null ) {
	return ANSP_Permissions::get_user_group_slugs( $user_id );
}

/**
 * Template-friendly wrapper: the voice parts for a user (via their linked
 * singer profile's canonical `parts` meta).
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return string[]
 */
function ansp_get_user_voice_parts( $user_id = null ) {
	return ANSP_Permissions::get_user_voice_parts( $user_id );
}
