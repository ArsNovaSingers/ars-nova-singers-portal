<?php
/**
 * Login flow for singers.
 *
 * - After login, users with the "singer" role are redirected to the portal
 *   page instead of wp-admin.
 * - Singers cannot browse wp-admin at all (admin-ajax.php and
 *   admin-post.php remain available for the portal's own form handlers).
 * - The admin bar is hidden for singers on the front end.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Login
 */
class ANSP_Login {

	/**
	 * Hook redirects and the wp-admin blocker.
	 */
	public function __construct() {
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'block_wp_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
	}

	/**
	 * Is this user a portal-only singer (has the singer role and no
	 * back-end capabilities)?
	 *
	 * @param WP_User|false|null $user User object.
	 * @return bool
	 */
	protected function is_portal_only_singer( $user ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		if ( ! in_array( 'singer', (array) $user->roles, true ) ) {
			return false;
		}
		// Anyone who can edit content or manage the portal keeps admin access.
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'ansp_manage_portal' ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Send singers to /portal/ after login.
	 *
	 * @param string             $redirect_to           Requested redirect.
	 * @param string             $requested_redirect_to Original request value.
	 * @param WP_User|WP_Error   $user                  Logged-in user.
	 * @return string
	 */
	public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && $this->is_portal_only_singer( $user ) ) {
			return ansp_get_portal_url();
		}
		return $redirect_to;
	}

	/**
	 * Bounce singers out of wp-admin to the portal page.
	 *
	 * @return void
	 */
	public function block_wp_admin() {
		if ( wp_doing_ajax() ) {
			return; // AJAX endpoints stay usable.
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return; // Front-end form handlers (bio save, RSVP) run through admin-post.
		}
		if ( $this->is_portal_only_singer( wp_get_current_user() ) ) {
			wp_safe_redirect( ansp_get_portal_url() );
			exit;
		}
	}

	/**
	 * Hide the admin bar for portal-only singers.
	 *
	 * @param bool $show Current visibility.
	 * @return bool
	 */
	public function maybe_hide_admin_bar( $show ) {
		if ( is_user_logged_in() && $this->is_portal_only_singer( wp_get_current_user() ) ) {
			return false;
		}
		return $show;
	}
}
