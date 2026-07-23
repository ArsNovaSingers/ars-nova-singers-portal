<?php
/**
 * RSVP: lightweight yes/no/maybe per project.
 *
 * Storage (both directions for cheap lookups):
 * - user meta    ansp_rsvp_{project_id}  => 'yes'|'no'|'maybe'
 * - project meta ansp_rsvps              => [ user_id => [response, time] ]
 *
 * The front-end form posts to admin-post.php (allowed for singers by
 * ANSP_Login) with a per-project nonce.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_RSVP
 */
class ANSP_RSVP {

	/**
	 * Hook the admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_ansp_rsvp', array( $this, 'handle_rsvp' ) );
		add_action( 'admin_post_nopriv_ansp_rsvp', array( $this, 'handle_nopriv' ) );
	}

	/**
	 * Valid responses (value => label).
	 *
	 * @return array<string,string>
	 */
	public static function responses() {
		return array(
			'yes'   => __( 'Yes', 'ans-singers-portal' ),
			'maybe' => __( 'Maybe', 'ans-singers-portal' ),
			'no'    => __( 'No', 'ans-singers-portal' ),
		);
	}

	/**
	 * A user's current response for a project.
	 *
	 * @param int      $project_id Project ID.
	 * @param int|null $user_id    User ID (defaults to current user).
	 * @return string ''|'yes'|'no'|'maybe'
	 */
	public static function get_user_response( $project_id, $user_id = null ) {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$response = (string) get_user_meta( $user_id, 'ansp_rsvp_' . (int) $project_id, true );
		return array_key_exists( $response, self::responses() ) ? $response : '';
	}

	/**
	 * Aggregate counts for a project.
	 *
	 * @param int $project_id Project ID.
	 * @return array{yes:int,no:int,maybe:int}
	 */
	public static function get_counts( $project_id ) {
		$counts = array(
			'yes'   => 0,
			'no'    => 0,
			'maybe' => 0,
		);
		$rsvps  = get_post_meta( (int) $project_id, 'ansp_rsvps', true );
		if ( is_array( $rsvps ) ) {
			foreach ( $rsvps as $entry ) {
				if ( is_array( $entry ) && isset( $entry['response'] ) && isset( $counts[ $entry['response'] ] ) ) {
					$counts[ $entry['response'] ]++;
				}
			}
		}
		return $counts;
	}

	/**
	 * Render the RSVP form for a project (echoes markup; caller escapes ctx).
	 *
	 * @param int $project_id Project ID.
	 * @return void
	 */
	public static function render_form( $project_id ) {
		$project_id = (int) $project_id;
		$current    = self::get_user_response( $project_id );
		?>
		<form class="ansp-rsvp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ansp_rsvp" />
			<input type="hidden" name="ansp_project_id" value="<?php echo esc_attr( (string) $project_id ); ?>" />
			<input type="hidden" name="ansp_redirect" value="<?php echo esc_url( ansp_get_portal_url() . '#tab-season-materials' ); ?>" />
			<?php wp_nonce_field( 'ansp_rsvp_' . $project_id, 'ansp_rsvp_nonce' ); ?>
			<span class="ansp-rsvp-label"><?php esc_html_e( 'Will you take part?', 'ans-singers-portal' ); ?></span>
			<span class="ansp-rsvp-options">
				<?php foreach ( self::responses() as $value => $label ) : ?>
					<label class="ansp-rsvp-option">
						<input type="radio" name="ansp_response" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> required />
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</span>
			<button type="submit" class="ansp-btn ansp-btn--small"><?php esc_html_e( 'Save RSVP', 'ans-singers-portal' ); ?></button>
			<?php if ( $current ) : ?>
				<span class="ansp-rsvp-current">
					<?php
					/* translators: %s: current RSVP answer */
					printf( esc_html__( 'Your answer: %s', 'ans-singers-portal' ), esc_html( self::responses()[ $current ] ) );
					?>
				</span>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Logged-in RSVP submission.
	 *
	 * @return void
	 */
	public function handle_rsvp() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_safe_redirect( wp_login_url( ansp_get_portal_url() ) );
			exit;
		}

		$project_id = isset( $_POST['ansp_project_id'] ) ? absint( $_POST['ansp_project_id'] ) : 0;
		$nonce      = isset( $_POST['ansp_rsvp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_rsvp_nonce'] ) ) : '';
		if ( ! $project_id || ! wp_verify_nonce( $nonce, 'ansp_rsvp_' . $project_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'ans-singers-portal' ) );
		}

		$response = isset( $_POST['ansp_response'] ) ? sanitize_key( wp_unslash( $_POST['ansp_response'] ) ) : '';
		if ( ! array_key_exists( $response, self::responses() ) ) {
			wp_die( esc_html__( 'Invalid RSVP response.', 'ans-singers-portal' ) );
		}

		// The user must actually be allowed to see the project.
		if ( ! ANSP_Permissions::user_can_see( $project_id, $user_id ) ) {
			wp_die( esc_html__( 'You do not have access to this project.', 'ans-singers-portal' ) );
		}

		update_user_meta( $user_id, 'ansp_rsvp_' . $project_id, $response );

		$rsvps = get_post_meta( $project_id, 'ansp_rsvps', true );
		if ( ! is_array( $rsvps ) ) {
			$rsvps = array();
		}
		$rsvps[ $user_id ] = array(
			'response' => $response,
			'time'     => time(),
		);
		update_post_meta( $project_id, 'ansp_rsvps', $rsvps );

		$redirect = isset( $_POST['ansp_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['ansp_redirect'] ) ) : '';
		if ( ! $redirect || 0 !== strpos( $redirect, home_url() ) ) {
			$redirect = ansp_get_portal_url();
		}
		wp_safe_redirect( add_query_arg( 'ansp_rsvp', 'saved', $redirect ) );
		exit;
	}

	/**
	 * Logged-out submissions go to the login screen.
	 *
	 * @return void
	 */
	public function handle_nopriv() {
		wp_safe_redirect( wp_login_url( ansp_get_portal_url() ) );
		exit;
	}
}
