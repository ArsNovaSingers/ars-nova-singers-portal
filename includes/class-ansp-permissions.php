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
	 * A user's EFFECTIVE group slugs: the groups on their profile PLUS every
	 * ancestor of those groups.
	 *
	 * ans_group is a tree. Top-level terms are ensembles and make Materials
	 * tabs; children are labels that roll up — an Ensemble Singer and a High
	 * School Apprentice both sing in the full chorus. Every access check in
	 * this class is an array_intersect against the user's groups, so without
	 * this expansion a singer tagged ONLY with a child group is HIDDEN from
	 * their own ensemble's music: intersect(['main'], ['ensemble-singers'])
	 * is empty. That is the opposite of what nesting is supposed to mean.
	 *
	 * Inheritance runs one way, upward. Being in the full chorus does not
	 * grant Ensemble Singers material; being an Ensemble Singer grants full
	 * chorus material.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return string[] Group slugs, own + inherited, deduped.
	 */
	public static function get_user_effective_group_slugs( $user_id = null ) {
		$slugs = self::get_user_group_slugs( $user_id );
		if ( empty( $slugs ) || ! taxonomy_exists( 'ans_group' ) ) {
			return $slugs;
		}

		$out = $slugs;
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'ans_group' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			foreach ( get_ancestors( (int) $term->term_id, 'ans_group', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, 'ans_group' );
				if ( $ancestor instanceof WP_Term && ! in_array( $ancestor->slug, $out, true ) ) {
					$out[] = $ancestor->slug;
				}
			}
		}

		return $out;
	}

	/**
	 * The top-level ancestor of a group term, or the term itself when it is
	 * already top-level. This is the term whose name becomes a tab label.
	 *
	 * @param WP_Term $term Group term.
	 * @return WP_Term
	 */
	protected static function top_level_group( $term ) {
		$ancestors = get_ancestors( (int) $term->term_id, 'ans_group', 'taxonomy' );
		if ( empty( $ancestors ) ) {
			return $term;
		}
		// get_ancestors() returns nearest-first, so the last entry is the root.
		$top = get_term( (int) end( $ancestors ), 'ans_group' );
		return $top instanceof WP_Term ? $top : $term;
	}

	/**
	 * Does this group, or anything nested under it, have a published project?
	 *
	 * Descendants count: a project tagged only "Ensemble Singers" is a reason
	 * for the "Ars Nova Singers" tab to exist. hide_empty cannot answer this
	 * because the taxonomy count includes singers.
	 *
	 * @param int $term_id Group term ID.
	 * @return bool
	 */
	protected static function group_has_projects( $term_id ) {
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
						'taxonomy'         => 'ans_group',
						'field'            => 'term_id',
						'terms'            => (int) $term_id,
						'include_children' => true,
					),
				),
			)
		);

		return ! empty( $found );
	}

	/**
	 * The groups a viewer gets their own Materials tab for.
	 *
	 * TOP-LEVEL GROUPS MAKE TABS. Children never do — they roll up into their
	 * parent's tab. An Ensemble Singer opens "Ars Nova Singers" and finds the
	 * full chorus's projects plus anything tagged for Ensemble, which is what
	 * nesting them under it is supposed to mean.
	 *
	 * The rule is "has no parent", NOT "has children": Chamber Singers has no
	 * children and still needs its own tab.
	 *
	 * Two things suppress a top-level group:
	 *
	 * - The "Do not create a tab" checkbox. An explicit opt-out for a group
	 *   that scopes projects without naming an ensemble — Board Member, whose
	 *   materials belong in the Board Portal and not in front of singers.
	 * - Having no projects anywhere in its subtree. This is also what keeps a
	 *   term someone created by accident (Add New Group defaults Parent to
	 *   "None") from silently appearing as a tab: it stays invisible until
	 *   somebody also tags a project to it.
	 *
	 * Managers get every top-level group that survives those two tests, not
	 * one per group they happen to belong to.
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return WP_Term[] Top-level groups, in taxonomy order.
	 */
	public static function get_visible_groups( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id || ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}

		if ( self::is_manager( $user_id ) ) {
			$candidates = get_terms(
				array(
					'taxonomy'   => 'ans_group',
					'hide_empty' => false,
					'parent'     => 0,
				)
			);
			if ( is_wp_error( $candidates ) ) {
				return array();
			}
		} else {
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
			if ( is_wp_error( $terms ) ) {
				return array();
			}

			// Resolve each membership up to the term that owns the tab, and
			// dedupe — someone in both Ensemble Singers and the full chorus
			// must not get the same tab twice.
			$candidates = array();
			$seen       = array();
			foreach ( $terms as $term ) {
				$top = self::top_level_group( $term );
				if ( ! in_array( (int) $top->term_id, $seen, true ) ) {
					$seen[]       = (int) $top->term_id;
					$candidates[] = $top;
				}
			}
		}

		$out = array();
		foreach ( $candidates as $term ) {
			if ( get_term_meta( (int) $term->term_id, ANSP_Group_Fields::META_NO_TAB, true ) ) {
				continue;
			}
			if ( ! self::group_has_projects( (int) $term->term_id ) ) {
				continue;
			}
			$out[] = $term;
		}

		return $out;
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
				return (bool) array_intersect( $mgroups, self::get_user_effective_group_slugs( $user_id ) );
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

		$user_groups = self::get_user_effective_group_slugs( $user_id );
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
		return (bool) array_intersect( $groups, self::get_user_effective_group_slugs( $user_id ) );
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
			return apply_filters( 'ansp_visible_materials', $all, (int) $project_id, $user_id );
		}
		$user_groups = self::get_user_effective_group_slugs( $user_id );
		$visible     = array();
		foreach ( $all as $row ) {
			$groups = ANSP_Materials::get_groups( $row );
			if ( empty( $groups ) || array_intersect( $groups, $user_groups ) ) {
				$visible[] = $row;
			}
		}

		/**
		 * The visible materials for one project, after group gating.
		 *
		 * Exists so published sheet music from the device-sync mirror can be shown
		 * alongside hand-entered materials without a second copy being written into
		 * post meta. See ANSP_Scores_Source.
		 *
		 * Anything hooked here has already had the permission decision made for it
		 * by the code above; a filter MUST NOT be used to widen access. It is for
		 * adding rows the caller is already entitled to see.
		 *
		 * @param array[] $visible    Rows this user may see.
		 * @param int     $project_id Project post ID.
		 * @param int     $user_id    Viewer.
		 */
		return apply_filters( 'ansp_visible_materials', $visible, (int) $project_id, $user_id );
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
