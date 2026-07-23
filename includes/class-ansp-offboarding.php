<?php
/**
 * Offboarding: deactivate a singer without deleting anything.
 *
 * Deactivation:
 * - strips the user's roles (they can no longer see the portal),
 * - flags their linked profile inactive (hidden from the roster).
 *
 * Reinstating restores the singer role and clears the flag. Actions are
 * exposed as row links on the Users screen for Personnel Manager /
 * Artistic Director / administrators, via nonce-verified admin-post
 * handlers. No posts, meta, or accounts are ever deleted.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Offboarding
 */
class ANSP_Offboarding {

	/**
	 * Hook row actions, handlers and notices.
	 */
	public function __construct() {
		add_filter( 'user_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_ansp_offboard', array( $this, 'handle_offboard' ) );
		add_action( 'admin_post_ansp_reinstate', array( $this, 'handle_reinstate' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * May the current user offboard singers?
	 *
	 * @return bool
	 */
	protected function can_manage() {
		return current_user_can( 'ansp_manage_roster' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Add Deactivate/Reinstate links on the Users list.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_User $user    The row's user.
	 * @return array
	 */
	public function row_actions( $actions, $user ) {
		if ( ! $this->can_manage() || ! $user instanceof WP_User ) {
			return $actions;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return $actions; // Never offboard administrators from here.
		}

		$has_singer_role = in_array( 'singer', (array) $user->roles, true );
		$has_profile     = (int) get_user_meta( $user->ID, 'ansp_singer_profile', true ) > 0;

		if ( $has_singer_role ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'ansp_offboard',
						'user_id' => (int) $user->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'ansp_offboard_' . (int) $user->ID
			);
			$actions['ansp_offboard'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Deactivate (Portal)', 'ans-singers-portal' ) . '</a>';
		} elseif ( $has_profile && empty( $user->roles ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'ansp_reinstate',
						'user_id' => (int) $user->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'ansp_reinstate_' . (int) $user->ID
			);
			$actions['ansp_reinstate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Reinstate (Portal)', 'ans-singers-portal' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Deactivate: remove roles + hide profile, keep everything stored.
	 *
	 * @param int $user_id User to offboard.
	 * @return bool
	 */
	public static function deactivate_singer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$user->set_role( '' ); // No role → no portal, no login destinations.

		$profile_id = (int) get_user_meta( $user->ID, 'ansp_singer_profile', true );
		if ( $profile_id && get_post( $profile_id ) ) {
			update_post_meta( $profile_id, 'ansp_inactive', '1' );
		}
		return true;
	}

	/**
	 * Reinstate: restore the singer role and unhide the profile.
	 *
	 * @param int $user_id User to reinstate.
	 * @return bool
	 */
	public static function reactivate_singer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$user->set_role( 'singer' );

		$profile_id = (int) get_user_meta( $user->ID, 'ansp_singer_profile', true );
		if ( $profile_id && get_post( $profile_id ) ) {
			delete_post_meta( $profile_id, 'ansp_inactive' );
		}
		return true;
	}

	/**
	 * admin-post: offboard.
	 *
	 * @return void
	 */
	public function handle_offboard() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to offboard singers.', 'ans-singers-portal' ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'ansp_offboard_' . $user_id );

		$ok = self::deactivate_singer( $user_id );
		wp_safe_redirect( add_query_arg( 'ansp_notice', $ok ? 'offboarded' : 'offboard_failed', admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * admin-post: reinstate.
	 *
	 * @return void
	 */
	public function handle_reinstate() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to reinstate singers.', 'ans-singers-portal' ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'ansp_reinstate_' . $user_id );

		$ok = self::reactivate_singer( $user_id );
		wp_safe_redirect( add_query_arg( 'ansp_notice', $ok ? 'reinstated' : 'reinstate_failed', admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Whitelisted admin notices for offboarding outcomes.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! isset( $_GET['ansp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$code     = sanitize_key( wp_unslash( $_GET['ansp_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'offboarded'       => array( 'success', __( 'Singer deactivated: their portal access was removed and their profile is hidden from the roster. Nothing was deleted.', 'ans-singers-portal' ) ),
			'offboard_failed'  => array( 'error', __( 'Could not deactivate that user.', 'ans-singers-portal' ) ),
			'reinstated'       => array( 'success', __( 'Singer reinstated with portal access.', 'ans-singers-portal' ) ),
			'reinstate_failed' => array( 'error', __( 'Could not reinstate that user.', 'ans-singers-portal' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}
}
