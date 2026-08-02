<?php
/**
 * Links a WordPress user to their `singer` profile.
 *
 * This is the join that the whole identity model hangs on:
 *
 *   WP user  = the login and permissions
 *   singer   = the profile content they maintain
 *   user meta `ansp_singer_profile` = the link between them
 *
 * Without it the portal's My Bio tab renders "Your account is not linked to a
 * singer profile yet", and ANSP_Permissions::get_user_group_slugs() returns an
 * empty array — so the user is effectively a logged-in stranger.
 *
 * The meta key is NOT new. It is the key the bio editor and the permissions
 * engine already read; this class just makes it settable by a human instead of
 * only by hand in the database.
 *
 * Matching is by DISPLAY NAME, not email: the `singer` CPT stores no email
 * address (its meta is parts, years_with_group, favorite_piece, favorite_quote,
 * pronouns, profession), so the user's display name against the post title is
 * the only signal available.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Profile_Link
 */
class ANSP_Profile_Link {

	const META = 'ansp_singer_profile';

	/**
	 * Hook the admin UI and the REST route.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_field' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Surface the link on the Users list so gaps are visible at a glance.
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_content' ), 10, 3 );
	}

	/**
	 * The singer profile ID linked to a user, or 0.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_profile_id( $user_id ) {
		$id = (int) get_user_meta( (int) $user_id, self::META, true );
		return ( $id && 'singer' === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * Every singer profile as id => title, for the picker.
	 *
	 * @return array<int,string>
	 */
	protected static function profile_choices() {
		$out = array();
		$posts = get_posts(
			array(
				'post_type'      => 'singer',
				'post_status'    => 'any',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $p ) {
			$out[ $p->ID ] = get_the_title( $p );
		}
		return $out;
	}

	/**
	 * Find the singer profile whose title matches a user's display name.
	 *
	 * Exact, case-insensitive. Deliberately strict: a fuzzy match that links
	 * the wrong person would expose one singer's profile to another, which is
	 * a far worse failure than leaving the link unset for a human to fix.
	 *
	 * @param WP_User $user User.
	 * @return int Profile ID, or 0 when there is no unambiguous match.
	 */
	public static function guess_profile_for_user( $user ) {
		if ( ! $user instanceof WP_User ) {
			return 0;
		}

		$candidates = array_filter(
			array(
				$user->display_name,
				trim( $user->first_name . ' ' . $user->last_name ),
			)
		);

		foreach ( $candidates as $name ) {
			$matches = get_posts(
				array(
					'post_type'      => 'singer',
					'post_status'    => 'any',
					'posts_per_page' => 2,
					'title'          => $name,
					'fields'         => 'ids',
				)
			);
			// Exactly one match, or it is not unambiguous enough to trust.
			if ( 1 === count( $matches ) ) {
				return (int) $matches[0];
			}
		}

		return 0;
	}

	/**
	 * Render the picker on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public static function render_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
			return;
		}
		// Only managers may change the link; a singer sees it read-only.
		$can_edit = current_user_can( 'edit_users' ) || current_user_can( 'ansp_manage_roster' );
		$current  = self::get_profile_id( $user->ID );
		?>
		<h2><?php esc_html_e( 'Singers Portal', 'ans-singers-portal' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ansp_singer_profile"><?php esc_html_e( 'Linked singer profile', 'ans-singers-portal' ); ?></label></th>
				<td>
					<?php if ( $can_edit ) : ?>
						<?php wp_nonce_field( 'ansp_save_profile_link', 'ansp_profile_link_nonce' ); ?>
						<select name="ansp_singer_profile" id="ansp_singer_profile">
							<option value="0"><?php esc_html_e( '— not linked —', 'ans-singers-portal' ); ?></option>
							<?php foreach ( self::profile_choices() as $id => $title ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, $id ); ?>>
									<?php echo esc_html( $title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Connects this login to the profile they edit on the portal\'s My Bio tab. Without it, My Bio shows "not linked yet".', 'ans-singers-portal' ); ?>
						</p>
					<?php else : ?>
						<p>
							<?php
							echo $current
								? esc_html( get_the_title( $current ) )
								: esc_html__( 'Not linked. Contact the Personnel Manager.', 'ans-singers-portal' );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the picker.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public static function save_field( $user_id ) {
		if ( ! isset( $_POST['ansp_profile_link_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_profile_link_nonce'] ) ), 'ansp_save_profile_link' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_users' ) && ! current_user_can( 'ansp_manage_roster' ) ) {
			return;
		}

		$profile_id = isset( $_POST['ansp_singer_profile'] ) ? (int) $_POST['ansp_singer_profile'] : 0;

		if ( $profile_id && 'singer' === get_post_type( $profile_id ) ) {
			update_user_meta( $user_id, self::META, $profile_id );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * Add a "Singer profile" column to the Users list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function users_column( $columns ) {
		$columns['ansp_profile'] = __( 'Singer profile', 'ans-singers-portal' );
		return $columns;
	}

	/**
	 * Render the Users list column.
	 *
	 * @param string $output      Current output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function users_column_content( $output, $column_name, $user_id ) {
		if ( 'ansp_profile' !== $column_name ) {
			return $output;
		}
		$id = self::get_profile_id( $user_id );
		if ( $id ) {
			return esc_html( get_the_title( $id ) );
		}
		return '<span style="color:#b32d2e;">' . esc_html__( 'not linked', 'ans-singers-portal' ) . '</span>';
	}

	/**
	 * REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'ars-nova/v1',
			'/singers/link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_link' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_users' );
				},
			)
		);

		register_rest_route(
			'ars-nova/v1',
			'/singers/links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => function () {
					return current_user_can( 'list_users' );
				},
			)
		);
	}

	/**
	 * GET /singers/links — who is linked, who is not.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_list() {
		$out = array();
		foreach ( get_users( array( 'number' => 200 ) ) as $user ) {
			$linked = self::get_profile_id( $user->ID );
			$out[]  = array(
				'user_id'      => $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
				'profile_id'   => $linked,
				'profile'      => $linked ? get_the_title( $linked ) : '',
				'suggestion'   => $linked ? 0 : self::guess_profile_for_user( $user ),
			);
		}
		return new WP_REST_Response(
			array(
				'count' => count( $out ),
				'users' => $out,
			),
			200
		);
	}

	/**
	 * POST /singers/link — set links.
	 *
	 * Body: { links: [{ user_id, singer_id? , singer_title? }], auto: bool, dry_run: bool }
	 *
	 * With auto=true, every user that has no link gets one if — and only if —
	 * their display name matches exactly one singer profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_link( $request ) {
		$links   = (array) $request->get_param( 'links' );
		$auto    = (bool) $request->get_param( 'auto' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$results = array();

		if ( $auto ) {
			foreach ( get_users( array( 'number' => 200 ) ) as $user ) {
				if ( self::get_profile_id( $user->ID ) ) {
					continue; // Never overwrite an existing link.
				}
				$guess = self::guess_profile_for_user( $user );
				if ( ! $guess ) {
					$results[] = array(
						'user'   => $user->user_login,
						'action' => 'no unambiguous match',
					);
					continue;
				}
				if ( ! $dry_run ) {
					update_user_meta( $user->ID, self::META, $guess );
				}
				$results[] = array(
					'user'    => $user->user_login,
					'action'  => $dry_run ? 'would link' : 'linked',
					'profile' => get_the_title( $guess ),
				);
			}
		}

		foreach ( $links as $row ) {
			$row     = (array) $row;
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;

			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				$results[] = array(
					'user'   => $user_id,
					'action' => 'user not found',
				);
				continue;
			}

			$profile_id = isset( $row['singer_id'] ) ? (int) $row['singer_id'] : 0;
			if ( ! $profile_id && ! empty( $row['singer_title'] ) ) {
				$found      = get_posts(
					array(
						'post_type'      => 'singer',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'title'          => sanitize_text_field( (string) $row['singer_title'] ),
						'fields'         => 'ids',
					)
				);
				$profile_id = ! empty( $found ) ? (int) $found[0] : 0;
			}

			if ( ! $profile_id || 'singer' !== get_post_type( $profile_id ) ) {
				$results[] = array(
					'user'   => $user_id,
					'action' => 'singer profile not found',
				);
				continue;
			}

			if ( ! $dry_run ) {
				update_user_meta( $user_id, self::META, $profile_id );
			}

			$results[] = array(
				'user'    => get_userdata( $user_id )->user_login,
				'action'  => $dry_run ? 'would link' : 'linked',
				'profile' => get_the_title( $profile_id ),
			);
		}

		return new WP_REST_Response(
			array(
				'dry_run' => $dry_run,
				'count'   => count( $results ),
				'results' => $results,
			),
			200
		);
	}
}
