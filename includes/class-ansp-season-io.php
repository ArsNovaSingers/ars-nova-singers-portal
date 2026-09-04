<?php
/**
 * Season export / import — a season as one JSON document.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-09-04 the LIVE site was found with every project except one stripped
 * of its season and its groups. Springs & Gears had lost the Chamber Singers
 * assignment made hours earlier; five concert projects had never had one. The
 * portal scopes everything it shows by ANSP_Taxonomies::get_current_season(),
 * so the moment those terms went missing the whole choir opened the Singers Hub
 * and found nothing in it.
 *
 * The plugin's own code was fine. The *data* was the fragile part, and there
 * was no way to answer "what did this look like yesterday?" or to put it back
 * without retyping it in wp-admin one project at a time. That is what this file
 * fixes. Jonathan asked for it in the same breath: "so we can back up and bulk
 * edit the season data and then upload it to save a season in case a database
 * loses its info."
 *
 * WHAT A SNAPSHOT CONTAINS
 * ------------------------
 * Everything that decides what a singer sees, and nothing that can be derived:
 * the season term, every group term with its Drive mapping and tab flags, and
 * every project in the season with its terms, its meta and its materials array.
 *
 * IT DOES NOT CONTAIN FILES. Materials are links — to Drive, to the mirror —
 * and the mirror is the source of truth for published scores. A snapshot
 * restores the *index*; it has never held a PDF and should not start.
 *
 * IDENTITY IS BY SLUG, NOT BY ID
 * ------------------------------
 * Post and term IDs are local to one database. Staging's `ans_group` slugs are
 * already known to differ from LIVE's, and a snapshot that matched on ID would
 * either silently overwrite an unrelated post or refuse to import anywhere but
 * its origin. Projects match on post_name, then on exact title; terms match on
 * slug. IDs are exported for reference and never used to match.
 *
 * IMPORT IS DRY-RUN BY DEFAULT
 * ----------------------------
 * A restore is exactly the sort of operation someone runs while alarmed, at
 * speed, against the wrong environment. `dry_run` defaults to TRUE and reports
 * what it would do; you have to ask twice to change anything, and on production
 * three times (confirm_production, per ANSP_REST's guard).
 *
 * NOTHING IS EVER DELETED. Import creates and updates. A project present here
 * but absent from the snapshot is reported as `extra` and left alone — the one
 * thing worse than a lost season is a restore that removes the work somebody
 * did after the backup was taken.
 *
 * @package ArsNovaSingersPortal
 * @since   1.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Season snapshot reader and writer.
 */
class ANSP_Season_IO {

	/** Same namespace as ANSP_REST, and for the same reason — see that class. */
	const NS = 'ars-nova/v1';

	/**
	 * Snapshot format marker.
	 *
	 * Import refuses a document that does not carry this. It is cheap, and it is
	 * what stops somebody pasting a Tickera export or half a WordPress dump into
	 * the import route and watching it half-succeed.
	 */
	const FORMAT = 'ans-portal-season/1';

	/** Project meta keys that travel, un-prefixed. */
	const PROJECT_META = array(
		'date_start',
		'date_end',
		'venue',
		'description',
		'brief_url',
		'hub_doc_url',
		'status',
	);

	/**
	 * Hook up.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the two routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$perm = array( 'ANSP_REST', 'can_manage' );

		register_rest_route(
			self::NS,
			'/portal/season/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'export' ),
			)
		);

		register_rest_route(
			self::NS,
			'/portal/season/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'import' ),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * Export
	 * ---------------------------------------------------------------- */

	/**
	 * Resolve the `season` parameter to a term.
	 *
	 * Accepts a term_id, a slug, or nothing — in which case the pinned current
	 * season is used, which is almost always what the caller meant.
	 *
	 * @param mixed $raw Season identifier.
	 * @return WP_Term|null
	 */
	protected static function resolve_season( $raw ) {
		if ( null === $raw || '' === $raw ) {
			$term = ANSP_Taxonomies::get_current_season();
			return $term instanceof WP_Term ? $term : null;
		}
		if ( is_numeric( $raw ) ) {
			$term = get_term( (int) $raw, 'ans_season' );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}
		$term = get_term_by( 'slug', sanitize_title( (string) $raw ), 'ans_season' );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Every group term, shaped for a snapshot.
	 *
	 * Groups are exported WHOLE rather than only those used by the season's
	 * projects. They are the permission engine; a snapshot that restored the
	 * projects but not the group tree would put the projects back and still
	 * leave nobody able to see them.
	 *
	 * @return array[]
	 */
	protected static function export_groups() {
		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
				'orderby'    => 'parent',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$parent_slug = '';
			if ( $term->parent ) {
				$parent = get_term( (int) $term->parent, 'ans_group' );
				if ( $parent instanceof WP_Term ) {
					$parent_slug = $parent->slug;
				}
			}
			$out[] = array(
				'term_id'           => (int) $term->term_id,
				'name'              => $term->name,
				'slug'              => $term->slug,
				'description'       => $term->description,
				'parent_slug'       => $parent_slug,
				'no_tab'            => (bool) get_term_meta( $term->term_id, ANSP_Group_Fields::META_NO_TAB, true ),
				'filter_tag'        => (string) get_term_meta( $term->term_id, ANSP_Group_Fields::META_TAG, true ),
				'drive_folder_id'   => (string) get_term_meta( $term->term_id, ANSP_Group_Fields::META_FOLDER_ID, true ),
				'drive_folder_name' => (string) get_term_meta( $term->term_id, ANSP_Group_Fields::META_FOLDER_NAME, true ),
			);
		}
		return $out;
	}

	/**
	 * One project, shaped for a snapshot.
	 *
	 * @param WP_Post $post Project.
	 * @return array
	 */
	protected static function export_project( $post ) {
		$id = (int) $post->ID;

		$meta = array();
		foreach ( self::PROJECT_META as $key ) {
			$meta[ $key ] = ANSP_Project_Meta::get( $id, $key );
		}

		$seasons = wp_get_object_terms( $id, 'ans_season', array( 'fields' => 'slugs' ) );
		$groups  = wp_get_object_terms( $id, 'ans_group', array( 'fields' => 'slugs' ) );

		return array(
			'id'              => $id,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'post_status'     => $post->post_status,
			'menu_order'      => (int) $post->menu_order,
			'season_slugs'    => is_wp_error( $seasons ) ? array() : array_values( (array) $seasons ),
			'group_slugs'     => is_wp_error( $groups ) ? array() : array_values( (array) $groups ),
			'meta'            => $meta,
			/*
			 * Which worker-side folder the sheet-music mirror reads for this
			 * project. Losing this is what made the Chamber tab show zero
			 * scores on 2026-09-03 even with the mirror healthy, so it travels.
			 */
			'scores_project'  => class_exists( 'ANSP_Scores_Source' )
				? (string) get_post_meta( $id, ANSP_Scores_Source::META_PROJECT, true )
				: '',
			'materials'       => array_values( (array) ANSP_Materials::get_materials( $id ) ),
		);
	}

	/**
	 * GET /portal/season/export
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function export( $req ) {
		$season = self::resolve_season( $req->get_param( 'season' ) );
		if ( ! $season instanceof WP_Term ) {
			return new WP_Error(
				'ansp_no_season',
				'No season to export. Pass ?season=<term_id or slug>, or pin one first.',
				array( 'status' => 404 )
			);
		}

		/*
		 * `all_projects=1` exports projects with NO season as well.
		 *
		 * This is not a tidy option, it is the lesson from the incident: the
		 * five concert projects that had lost their season were invisible to a
		 * season-scoped query, so a season export alone would have backed up
		 * the site's problem rather than its content.
		 */
		$all = filter_var( $req->get_param( 'all_projects' ), FILTER_VALIDATE_BOOLEAN );

		$args = array(
			'post_type'      => ANSP_CPT::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		if ( ! $all ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'ans_season',
					'field'    => 'term_id',
					'terms'    => (int) $season->term_id,
				),
			);
		}

		$projects = array();
		foreach ( get_posts( $args ) as $post ) {
			$projects[] = self::export_project( $post );
		}

		return array(
			'format'         => self::FORMAT,
			'exported'       => gmdate( 'c' ),
			'site'           => home_url(),
			'plugin_version' => defined( 'ANSP_VERSION' ) ? ANSP_VERSION : '',
			'scope'          => $all ? 'all_projects' : 'season',
			'pinned_season'  => (int) get_option( 'ansp_current_season', 0 ),
			'season'         => array(
				'term_id'     => (int) $season->term_id,
				'name'        => $season->name,
				'slug'        => $season->slug,
				'description' => $season->description,
			),
			'groups'         => self::export_groups(),
			'projects'       => $projects,
		);
	}

	/* -------------------------------------------------------------------
	 * Import
	 * ---------------------------------------------------------------- */

	/**
	 * Find the project a snapshot entry refers to, in this database.
	 *
	 * Slug first, then exact title. Never by the exported id — see the class
	 * docblock.
	 *
	 * @param array $entry Snapshot project entry.
	 * @return WP_Post|null
	 */
	protected static function match_project( $entry ) {
		$slug = isset( $entry['slug'] ) ? sanitize_title( (string) $entry['slug'] ) : '';
		if ( '' !== $slug ) {
			$found = get_posts(
				array(
					'post_type'      => ANSP_CPT::POST_TYPE,
					'post_status'    => 'any',
					'name'           => $slug,
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}

		$title = isset( $entry['title'] ) ? (string) $entry['title'] : '';
		if ( '' === $title ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'      => ANSP_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $found as $post ) {
			if ( $post->post_title === $title ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Ensure a term exists in a taxonomy, by slug, and return its id.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @param string $name     Name to create it with, if missing.
	 * @param bool   $apply    False to only report.
	 * @return array{term_id:int,created:bool}
	 */
	protected static function ensure_term( $taxonomy, $slug, $name, $apply ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'term_id' => 0, 'created' => false );
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term instanceof WP_Term ) {
			return array( 'term_id' => (int) $term->term_id, 'created' => false );
		}
		if ( ! $apply ) {
			return array( 'term_id' => 0, 'created' => true );
		}
		$made = wp_insert_term(
			'' !== $name ? $name : $slug,
			$taxonomy,
			array( 'slug' => $slug )
		);
		if ( is_wp_error( $made ) ) {
			return array( 'term_id' => 0, 'created' => false );
		}
		return array( 'term_id' => (int) $made['term_id'], 'created' => true );
	}

	/**
	 * Sanitise a snapshot's materials array before it is written back.
	 *
	 * A snapshot is a FILE. It may have been hand-edited, produced by an AI, or
	 * carried between sites, so it is untrusted input and gets the same
	 * treatment the admin form's rows get. Reuses ANSP_Materials' own helpers so
	 * there is one definition of a valid row rather than two that drift.
	 *
	 * Mirror rows are dropped: ANSP_Scores_Source merges those in at render time
	 * and never stores them, so importing one would create a stale duplicate of
	 * a score the worker is already publishing.
	 *
	 * @param mixed $rows Raw materials from the snapshot.
	 * @return array[]
	 */
	protected static function sanitize_materials( $rows ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['source'] ) && 'scores-mirror' === $row['source'] ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( 0 === strpos( $id, ANSP_Scores_Source::ID_PREFIX ) ) {
				continue;
			}

			$title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$url   = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			if ( '' === $title && '' === $url ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
			if ( ! array_key_exists( $type, ANSP_Materials::types() ) ) {
				$type = 'drive_link';
			}
			if ( '' === $id ) {
				$id = uniqid( 'ansp_', false );
			}

			$clean[] = array(
				'id'     => $id,
				'type'   => $type,
				'title'  => $title,
				'url'    => $url,
				'note'   => isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '',
				'piece'  => isset( $row['piece'] ) ? trim( sanitize_text_field( $row['piece'] ) ) : '',
				'tags'   => ANSP_Materials::sanitize_tags( isset( $row['tags'] ) ? $row['tags'] : array() ),
				'groups' => ANSP_Materials::get_groups( array( 'groups' => isset( $row['groups'] ) ? $row['groups'] : array() ) ),
			);
		}
		return $clean;
	}

	/**
	 * POST /portal/season/import
	 *
	 * Parameters:
	 *   snapshot          object  The document produced by /portal/season/export.
	 *   dry_run           bool    Default TRUE. Report only.
	 *   materials         string  'replace' (default) or 'skip'.
	 *   create_missing    bool    Default TRUE. Create projects absent here.
	 *   pin_season        bool    Default FALSE. Also set ansp_current_season.
	 *   confirm_production bool   Required on production for a real run.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public function import( $req ) {
		$snapshot = $req->get_param( 'snapshot' );
		if ( is_string( $snapshot ) ) {
			$decoded  = json_decode( $snapshot, true );
			$snapshot = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $snapshot ) ) {
			return new WP_Error( 'ansp_no_snapshot', 'Pass a snapshot object (or a JSON string) in `snapshot`.', array( 'status' => 400 ) );
		}
		if ( ! isset( $snapshot['format'] ) || self::FORMAT !== $snapshot['format'] ) {
			return new WP_Error(
				'ansp_bad_format',
				'Not a season snapshot. Expected format "' . self::FORMAT . '".',
				array( 'status' => 400 )
			);
		}

		$dry = null === $req->get_param( 'dry_run' )
			? true
			: filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

		// A real run on production still has to pass ANSP_REST's own guard.
		if ( ! $dry ) {
			$guard = ANSP_REST::guard( $req );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}
		$apply = ! $dry;

		$materials_mode = 'skip' === $req->get_param( 'materials' ) ? 'skip' : 'replace';
		$create_missing = null === $req->get_param( 'create_missing' )
			? true
			: filter_var( $req->get_param( 'create_missing' ), FILTER_VALIDATE_BOOLEAN );
		$pin_season     = filter_var( $req->get_param( 'pin_season' ), FILTER_VALIDATE_BOOLEAN );

		$log = array(
			'dry_run'    => $dry,
			'source'     => isset( $snapshot['site'] ) ? (string) $snapshot['site'] : '',
			'exported'   => isset( $snapshot['exported'] ) ? (string) $snapshot['exported'] : '',
			'target'     => home_url(),
			'groups'     => array( 'created' => array(), 'existing' => array() ),
			'seasons'    => array( 'created' => array(), 'existing' => array() ),
			'projects'   => array( 'created' => array(), 'updated' => array(), 'skipped' => array() ),
			'extra_here' => array(),
			'warnings'   => array(),
		);

		/* ---- Groups first: they are the permission engine ---------------- */
		foreach ( (array) ( isset( $snapshot['groups'] ) ? $snapshot['groups'] : array() ) as $g ) {
			if ( ! is_array( $g ) || empty( $g['slug'] ) ) {
				continue;
			}
			$res = self::ensure_term( 'ans_group', $g['slug'], isset( $g['name'] ) ? (string) $g['name'] : '', $apply );
			if ( $res['created'] ) {
				$log['groups']['created'][] = (string) $g['slug'];
			} else {
				$log['groups']['existing'][] = (string) $g['slug'];
			}

			// Parent + term meta only on a real run, and only for terms we have.
			if ( $apply && $res['term_id'] ) {
				if ( ! empty( $g['parent_slug'] ) ) {
					$parent = get_term_by( 'slug', sanitize_title( (string) $g['parent_slug'] ), 'ans_group' );
					if ( $parent instanceof WP_Term ) {
						wp_update_term( $res['term_id'], 'ans_group', array( 'parent' => (int) $parent->term_id ) );
					}
				}
				$meta_map = array(
					ANSP_Group_Fields::META_NO_TAB       => ! empty( $g['no_tab'] ) ? 1 : '',
					ANSP_Group_Fields::META_TAG          => isset( $g['filter_tag'] ) ? sanitize_text_field( (string) $g['filter_tag'] ) : '',
					ANSP_Group_Fields::META_FOLDER_ID    => isset( $g['drive_folder_id'] ) ? sanitize_text_field( (string) $g['drive_folder_id'] ) : '',
					ANSP_Group_Fields::META_FOLDER_NAME  => isset( $g['drive_folder_name'] ) ? sanitize_text_field( (string) $g['drive_folder_name'] ) : '',
				);
				foreach ( $meta_map as $key => $value ) {
					if ( '' === $value ) {
						delete_term_meta( $res['term_id'], $key );
					} else {
						update_term_meta( $res['term_id'], $key, $value );
					}
				}
			}
		}

		/* ---- The season term --------------------------------------------- */
		$season_slug = isset( $snapshot['season']['slug'] ) ? (string) $snapshot['season']['slug'] : '';
		$season_name = isset( $snapshot['season']['name'] ) ? (string) $snapshot['season']['name'] : '';
		$season_res  = self::ensure_term( 'ans_season', $season_slug, $season_name, $apply );
		if ( $season_res['created'] ) {
			$log['seasons']['created'][] = $season_slug;
		} elseif ( '' !== $season_slug ) {
			$log['seasons']['existing'][] = $season_slug;
		}

		/* ---- Projects ----------------------------------------------------- */
		$seen_ids = array();

		foreach ( (array) ( isset( $snapshot['projects'] ) ? $snapshot['projects'] : array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$title    = isset( $entry['title'] ) ? (string) $entry['title'] : '';
			$existing = self::match_project( $entry );

			if ( ! $existing && ! $create_missing ) {
				$log['projects']['skipped'][] = $title . ' (not here, create_missing off)';
				continue;
			}

			if ( ! $existing ) {
				$log['projects']['created'][] = $title;
				if ( ! $apply ) {
					continue;
				}
				$new_id = wp_insert_post(
					array(
						'post_type'   => ANSP_CPT::POST_TYPE,
						'post_title'  => sanitize_text_field( $title ),
						'post_name'   => isset( $entry['slug'] ) ? sanitize_title( (string) $entry['slug'] ) : '',
						'post_status' => isset( $entry['post_status'] ) ? sanitize_key( (string) $entry['post_status'] ) : 'publish',
						'menu_order'  => isset( $entry['menu_order'] ) ? (int) $entry['menu_order'] : 0,
					),
					true
				);
				if ( is_wp_error( $new_id ) ) {
					$log['warnings'][] = 'Could not create "' . $title . '": ' . $new_id->get_error_message();
					continue;
				}
				$existing = get_post( (int) $new_id );
			} else {
				$log['projects']['updated'][] = $title;
			}

			if ( ! $existing instanceof WP_Post ) {
				continue;
			}
			$pid        = (int) $existing->ID;
			$seen_ids[] = $pid;

			if ( ! $apply ) {
				continue;
			}

			// Terms.
			if ( ! empty( $entry['season_slugs'] ) ) {
				wp_set_object_terms( $pid, array_map( 'sanitize_title', (array) $entry['season_slugs'] ), 'ans_season', false );
			}
			if ( ! empty( $entry['group_slugs'] ) ) {
				wp_set_object_terms( $pid, array_map( 'sanitize_title', (array) $entry['group_slugs'] ), 'ans_group', false );
			}

			// Meta.
			foreach ( self::PROJECT_META as $key ) {
				if ( ! isset( $entry['meta'][ $key ] ) ) {
					continue;
				}
				$value = (string) $entry['meta'][ $key ];
				$value = in_array( $key, array( 'brief_url', 'hub_doc_url' ), true )
					? esc_url_raw( $value )
					: sanitize_text_field( $value );
				if ( '' === $value ) {
					delete_post_meta( $pid, 'ansp_project_' . $key );
				} else {
					update_post_meta( $pid, 'ansp_project_' . $key, $value );
				}
			}

			// Mirror mapping.
			if ( isset( $entry['scores_project'] ) ) {
				$sp = sanitize_text_field( (string) $entry['scores_project'] );
				if ( '' === $sp ) {
					delete_post_meta( $pid, ANSP_Scores_Source::META_PROJECT );
				} else {
					update_post_meta( $pid, ANSP_Scores_Source::META_PROJECT, $sp );
				}
			}

			// Materials.
			if ( 'replace' === $materials_mode && isset( $entry['materials'] ) ) {
				$rows = self::sanitize_materials( $entry['materials'] );
				if ( $rows ) {
					update_post_meta( $pid, ANSP_Materials::META_KEY, $rows );
				} else {
					delete_post_meta( $pid, ANSP_Materials::META_KEY );
				}
			}
		}

		/* ---- What is here but not in the snapshot ------------------------- */
		foreach ( get_posts(
			array(
				'post_type'      => ANSP_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		) as $post ) {
			if ( ! in_array( (int) $post->ID, $seen_ids, true ) ) {
				$log['extra_here'][] = $post->post_title;
			}
		}

		/* ---- Optionally pin ------------------------------------------------ */
		if ( $pin_season ) {
			$term = get_term_by( 'slug', sanitize_title( $season_slug ), 'ans_season' );
			if ( $term instanceof WP_Term ) {
				$log['pinned'] = (int) $term->term_id;
				if ( $apply ) {
					update_option( 'ansp_current_season', (int) $term->term_id );
				}
			} else {
				$log['warnings'][] = 'Could not pin: season "' . $season_slug . '" not found.';
			}
		}

		if ( $dry ) {
			$log['note'] = 'Nothing was changed. Resend with dry_run=false to apply.';
		}

		return $log;
	}
}
