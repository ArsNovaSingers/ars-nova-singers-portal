<?php
/**
 * REST surface for the sheet-music mirror.
 *
 * Everything the mirror needs in order to work was, until this file, reachable
 * only by a human in wp-admin: the worker URL, the token, and the per-project
 * address. `ans_project` is registered with `show_in_rest => false` and
 * `ansp_scores_project` is not a REST-registered meta key, so the generic
 * connector tools cannot see or write either of them - they fail in the
 * confusing way, reporting that the resource does not exist.
 *
 * These routes register into `ars-nova/v1` on `rest_api_init`, which is the
 * pattern class-ansp-profile-link.php and class-ansp-singers-public.php already
 * follow, and which the WordPress connector is allow-listed to call. Nothing
 * here touches class-ansp-rest.php.
 *
 * The token can be written and can never be read back. A read reports only
 * whether one is set, where it came from, and a short fingerprint - enough to
 * tell two environments apart, useless to anyone who intercepts it.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and write the mirror's configuration over REST.
 */
class ANSP_Mirror_Rest {

	const NS = 'ars-nova/v1';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Who may call any of this: the same people who may manage the portal.
	 *
	 * Deferred to ANSP_Rest rather than reimplemented. A second definition of
	 * "may manage" is exactly the kind of thing that drifts and then quietly
	 * disagrees with the first one.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		if ( method_exists( 'ANSP_Rest', 'can_manage' ) ) {
			return ANSP_Rest::can_manage();
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Is this the public production site? Same answer ANSP_Rest gives.
	 *
	 * @return bool
	 */
	public static function is_production() {
		if ( method_exists( 'ANSP_Rest', 'is_production' ) ) {
			return ANSP_Rest::is_production();
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return in_array(
			strtolower( (string) $host ),
			array( 'arsnovasingers.org', 'www.arsnovasingers.org', 'arsnovasingers.kinsta.cloud' ),
			true
		);
	}

	/**
	 * Refuse a write on production unless it was asked for on purpose.
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
	 */
	public static function register_routes() {
		$perm = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::NS,
			'/portal/mirror',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'get_mirror' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'set_mirror' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/portal/mirror/projects',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'list_project_mirrors' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/portal/dav',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'get_dav' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'set_dav' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/portal/project/(?P<id>\d+)/mirror',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'get_project_mirror' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => array( __CLASS__, 'set_project_mirror' ),
				),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * WebDAV panel
	 * ---------------------------------------------------------------- */

	/**
	 * What the WebDAV panel is configured to show, and where it would point.
	 *
	 * Returns the resolved address per configured group as well as the raw
	 * settings, because "what did I set" and "what will a singer actually see"
	 * are different questions and only the second one is ever the bug.
	 *
	 * No password is stored here or returned by this route. The credential
	 * lives in the worker's own secret; this side knows only a username.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_dav() {
		$settings = ANSP_Dav::settings();
		$base     = ANSP_Dav::base_url();

		$resolved = array();
		foreach ( $settings['users'] as $group => $username ) {
			$resolved[] = array(
				'group'    => $group,
				'username' => $username,
				'url'      => ANSP_Dav::url_for_group( $group ),
			);
		}

		return rest_ensure_response(
			array(
				'ok'            => true,
				'enabled'       => $settings['enabled'],
				'base'          => $base,
				'base_source'   => '' !== $settings['base'] ? 'override' : 'derived from the worker URL',
				'show_password' => $settings['show_password'],
				'note'          => $settings['note'],
				'groups'        => $resolved,
				'usable'        => $settings['enabled'] && '' !== $base && ! empty( $settings['users'] ),
			)
		);
	}

	/**
	 * Configure the WebDAV panel.
	 *
	 * `users` is a map of mirror group id => username, and it REPLACES the map
	 * rather than merging into it. Merging would make removing a group's
	 * credential impossible over REST, and a stale credential left visible on
	 * a page is the failure that matters here.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_dav( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$changes = array();
		foreach ( array( 'enabled', 'show_password', 'base', 'note', 'users' ) as $field ) {
			if ( null !== $req->get_param( $field ) ) {
				$changes[ $field ] = $req->get_param( $field );
			}
		}

		ANSP_Dav::update( $changes );
		return self::get_dav();
	}

	/* -------------------------------------------------------------------
	 * Mirror settings
	 * ---------------------------------------------------------------- */

	/**
	 * What is configured, and what the worker actually answers with.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_mirror() {
		$token  = ANSP_Scores_Source::worker_token();
		$probe  = ANSP_Scores_Source::configured_mirror_groups();
		$terms  = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $slug ) {
				$probe[] = (string) $slug;
			}
		}
		$probe = array_values( array_unique( array_filter( array_map( 'trim', $probe ) ) ) );

		$groups = array();
		$total  = 0;
		foreach ( $probe as $group ) {
			$scores   = ANSP_Scores_Source::library( $group );
			$total   += count( $scores );
			$projects = array();
			foreach ( $scores as $score ) {
				if ( empty( $score['project'] ) ) {
					continue;
				}
				$path = $group . '/' . (string) $score['project'];
				$projects[ $path ] = isset( $projects[ $path ] ) ? $projects[ $path ] + 1 : 1;
			}
			$groups[] = array(
				'group'    => $group,
				'scores'   => count( $scores ),
				'projects' => (object) $projects,
			);
		}

		return rest_ensure_response(
			array(
				'ok'                => true,
				'configured'        => ANSP_Scores_Source::is_configured(),
				'worker_url'        => ANSP_Scores_Source::worker_url(),
				'worker_url_source' => defined( ANSP_Scores_Source::CONST_URL ) ? 'constant' : 'option',
				'token_set'         => '' !== $token,
				'token_source'      => '' === $token
					? 'none'
					: ( defined( ANSP_Scores_Source::CONST_TOKEN ) ? 'constant' : 'option' ),
				'token_fingerprint' => '' === $token ? '' : substr( hash( 'sha256', $token ), 0, 8 ),
				'is_production'     => self::is_production(),
				'asked'             => $probe,
				'groups'            => $groups,
				'total_scores'      => $total,
				'note'              => 'The worker has no endpoint listing its groups, so this asks about every mirror folder already named on a project plus every WordPress group slug. A folder nobody has named here cannot appear. The token is never returned; token_fingerprint is the first 8 hex of its sha256, for comparing environments.',
			)
		);
	}

	/**
	 * Set the worker URL and/or the token.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_mirror( $req ) {
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$changed = array();

		if ( null !== $req->get_param( 'worker_url' ) ) {
			$url = esc_url_raw( trim( (string) $req->get_param( 'worker_url' ) ) );
			if ( '' !== $url && ! preg_match( '#^https://#i', $url ) ) {
				return new WP_Error(
					'ansp_mirror_url_insecure',
					'The worker URL must be https. Signed URLs and a bearer token both travel over it.',
					array( 'status' => 400 )
				);
			}
			update_option( ANSP_Scores_Source::OPT_URL, $url );
			$changed[] = 'worker_url';
		}

		if ( filter_var( $req->get_param( 'clear_token' ), FILTER_VALIDATE_BOOLEAN ) ) {
			update_option( ANSP_Scores_Source::OPT_TOKEN, '' );
			$changed[] = 'token_cleared';
		} elseif ( null !== $req->get_param( 'token' ) ) {
			$token = trim( (string) $req->get_param( 'token' ) );
			if ( '' === $token ) {
				return new WP_Error(
					'ansp_mirror_token_empty',
					'Refusing to store an empty token. Send clear_token=true if that is what you meant.',
					array( 'status' => 400 )
				);
			}
			update_option( ANSP_Scores_Source::OPT_TOKEN, $token );
			$changed[] = 'token';
		}

		if ( $changed ) {
			ANSP_Scores_Source::bust_cache();
		}

		$out = self::get_mirror()->get_data();
		$out['changed'] = $changed;
		return rest_ensure_response( $out );
	}

	/* -------------------------------------------------------------------
	 * Per-project address
	 * ---------------------------------------------------------------- */

	/**
	 * Resolve a project id from the route, or explain why not.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return int|WP_Error
	 */
	protected static function project_id( $req ) {
		$id   = (int) $req->get_param( 'id' );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'ans_project' !== $post->post_type ) {
			return new WP_Error(
				'ansp_not_a_project',
				'That id is not an ans_project. GET ars-nova/v1 portal/projects lists them.',
				array( 'status' => 404 )
			);
		}
		return $id;
	}

	/**
	 * One project's mirror address, resolved, with what the worker has for it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_project_mirror( $req ) {
		$id = self::project_id( $req );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( self::describe_project( $id, true ) );
	}

	/**
	 * Set or clear one project's mirror address.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_project_mirror( $req ) {
		$id = self::project_id( $req );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$guard = self::guard( $req );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( null === $req->get_param( 'value' ) ) {
			return new WP_Error(
				'ansp_mirror_value_missing',
				'Send value="group/project", or value="" to clear it.',
				array( 'status' => 400 )
			);
		}

		$value = trim( sanitize_text_field( (string) $req->get_param( 'value' ) ) );
		$before = (string) get_post_meta( $id, ANSP_Scores_Source::META_PROJECT, true );
		if ( '' === $value ) {
			delete_post_meta( $id, ANSP_Scores_Source::META_PROJECT );
		} else {
			update_post_meta( $id, ANSP_Scores_Source::META_PROJECT, $value );
		}

		$out = self::describe_project( $id, true );
		$out['previous_value'] = $before;
		return rest_ensure_response( $out );
	}

	/**
	 * Every project's mirror address in one call, so a mismatch is visible at a glance.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_project_mirrors() {
		$ids  = get_posts(
			array(
				'post_type'        => 'ans_project',
				'post_status'      => 'any',
				'numberposts'      => 200,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$rows = array();
		foreach ( (array) $ids as $id ) {
			$rows[] = self::describe_project( (int) $id, false );
		}
		return rest_ensure_response(
			array(
				'ok'         => true,
				'configured' => ANSP_Scores_Source::is_configured(),
				'count'      => count( $rows ),
				'projects'   => $rows,
				'note'       => 'matching_scores of 0 with a non-empty value means the address is wrong, not that the mirror is empty. GET portal/mirror lists the addresses the worker actually has.',
			)
		);
	}

	/**
	 * The shape both project routes return.
	 *
	 * @param int  $id        Project post ID.
	 * @param bool $with_names Include the matching canonical names, not just a count.
	 * @return array
	 */
	protected static function describe_project( $id, $with_names ) {
		$value  = (string) get_post_meta( $id, ANSP_Scores_Source::META_PROJECT, true );
		$target = ANSP_Scores_Source::mirror_target( $id );

		$matching = array();
		$offered  = array();
		foreach ( $target['groups'] as $group ) {
			foreach ( ANSP_Scores_Source::library( $group ) as $score ) {
				if ( ! empty( $score['project'] ) ) {
					$offered[ $group . '/' . (string) $score['project'] ] = true;
				}
				if ( ANSP_Scores_Source::score_belongs_to_project( $score, $target['project'] )
					&& ! empty( $score['canonical'] ) ) {
					$matching[ (string) $score['canonical'] ] = true;
				}
			}
		}

		$row = array(
			'project_id'       => (int) $id,
			'title'            => get_the_title( $id ),
			'value'            => $value,
			'value_is_set'     => '' !== $value,
			'resolved_groups'  => array_values( $target['groups'] ),
			'resolved_project' => $target['project'],
			'matching_scores'  => count( $matching ),
			'addresses_offered_by_these_groups' => array_keys( $offered ),
		);
		if ( $with_names ) {
			$row['matching'] = array_keys( $matching );
		}
		return $row;
	}
}
