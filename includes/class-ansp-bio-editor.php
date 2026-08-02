<?php
/**
 * Front-end bio editor: the form handler behind the "My Bio" portal tab.
 *
 * Singers edit their OWN linked singer profile from the front end (they
 * never enter wp-admin). Since v1.1.0 every field maps to CANONICAL
 * storage — the same place the admin edit screen and the public bio page
 * read from, so nothing is duplicated:
 *
 * - Display name    → post_title (wp_update_post)
 * - Bio             → post_content (wp_update_post)
 * - Headshot        → Featured Image (media_handle_upload)
 * - Voice part(s)   → meta `parts` (checkboxes, 7 canonical options)
 * - Years           → meta `years_with_group`
 * - Favorite piece  → meta `favorite_piece`
 * - Favorite quote  → meta `favorite_quote`
 * - Pronouns        → meta `pronouns`
 * - Email / Phone   → portal contact meta ansp_email / ansp_phone
 * - Privacy toggles → meta ansp_privacy (roster visibility flags)
 *
 * Saves run through admin-post.php with a nonce; a singer can only ever
 * edit the profile linked to their own account.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Bio_Editor
 */
class ANSP_Bio_Editor {

	/**
	 * Hook admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_ansp_save_bio', array( $this, 'handle_save' ) );
		add_action( 'admin_post_nopriv_ansp_save_bio', array( $this, 'handle_nopriv' ) );
	}

	/**
	 * Redirect back to the bio tab with a whitelisted status code.
	 *
	 * @param string $code Status code.
	 * @return void
	 */
	protected function redirect( $code ) {
		wp_safe_redirect( add_query_arg( 'ansp_bio', rawurlencode( $code ), ansp_get_portal_url() . '#tab-bio' ) );
		exit;
	}

	/**
	 * Save handler.
	 *
	 * @return void
	 */
	public function handle_save() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->handle_nopriv();
			return;
		}

		if ( ! current_user_can( 'ansp_edit_own_bio' ) && ! ANSP_Permissions::is_manager( $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit a portal bio.', 'ans-singers-portal' ) );
		}

		$nonce = isset( $_POST['ansp_bio_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_bio_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_bio' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'ans-singers-portal' ) );
		}

		// Users may only ever edit their OWN linked profile from here.
		$profile = ANSP_Profiles::get_profile_for_user( $user_id );
		if ( ! $profile instanceof WP_Post ) {
			$this->redirect( 'no_profile' );
		}
		$profile_id = (int) $profile->ID;

		// ---- Required fields ---------------------------------------------
		$display_name = isset( $_POST['ansp_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_display_name'] ) ) : '';
		$email        = isset( $_POST['ansp_field_email'] ) ? sanitize_email( wp_unslash( $_POST['ansp_field_email'] ) ) : '';

		$raw_parts = isset( $_POST['ans_parts'] ) && is_array( $_POST['ans_parts'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['ans_parts'] ) )
			: array();
		$parts     = array_values( array_intersect( ansp_voice_part_options(), $raw_parts ) );

		$phone       = isset( $_POST['ansp_field_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_field_phone'] ) ) : '';
		$year_joined = isset( $_POST['ansp_year_joined'] ) ? absint( $_POST['ansp_year_joined'] ) : 0;
		$bio_raw     = isset( $_POST['ansp_bio'] ) ? trim( (string) wp_unslash( $_POST['ansp_bio'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked for emptiness only; sanitised below.

		/*
		 * Server-side validation, not just the browser's `required`.
		 * The HTML attribute is a convenience — anything can POST here.
		 */
		$year_ok = ( $year_joined >= ansp_founding_year() && $year_joined <= (int) current_time( 'Y' ) );

		if (
			'' === $display_name
			|| empty( $parts )
			|| ! is_email( $email )
			|| '' === $phone
			|| '' === wp_strip_all_tags( $bio_raw )
			|| ! $year_ok
		) {
			$this->redirect( 'missing_required' );
		}

		// ---- Display name + bio → the singer post itself ------------------
		$bio = isset( $_POST['ansp_bio'] ) ? wp_kses_post( wp_unslash( $_POST['ansp_bio'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post sanitises.
		wp_update_post(
			array(
				'ID'           => $profile_id,
				'post_title'   => $display_name,
				'post_content' => $bio,
			)
		);

		// ---- Canonical profile-detail meta (same keys as the admin box) ---
		update_post_meta( $profile_id, 'parts', $parts );

		/*
		 * Years with the group is CALCULATED from the join year, never typed.
		 * `years_with_group` is still written so anything already reading it
		 * (roster, public bio page, exports) keeps working — but it is now a
		 * derived cache, and the join year is the source of truth.
		 */
		update_post_meta( $profile_id, 'year_joined', $year_joined );
		update_post_meta( $profile_id, 'years_with_group', max( 0, (int) current_time( 'Y' ) - $year_joined ) );

		$fav = isset( $_POST['ans_fav'] ) ? sanitize_text_field( wp_unslash( $_POST['ans_fav'] ) ) : '';
		if ( '' !== $fav ) {
			update_post_meta( $profile_id, 'favorite_piece', $fav );
		} else {
			delete_post_meta( $profile_id, 'favorite_piece' );
		}

		$quote = isset( $_POST['ans_quote'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ans_quote'] ) ) : '';
		if ( '' !== $quote ) {
			update_post_meta( $profile_id, 'favorite_quote', $quote );
		} else {
			delete_post_meta( $profile_id, 'favorite_quote' );
		}

		$pronouns = isset( $_POST['ansp_pronouns'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_pronouns'] ) ) : '';
		if ( '' !== $pronouns ) {
			update_post_meta( $profile_id, 'pronouns', $pronouns );
		} else {
			delete_post_meta( $profile_id, 'pronouns' );
		}

		// ---- Portal contact fields (ansp_email / ansp_phone) --------------
		foreach ( ANSP_Profiles::fields() as $key => $field ) {
			$post_key = 'ansp_field_' . $key;
			$raw      = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by type below.
			$value    = ANSP_Profiles::sanitize_field( $key, $field['type'], $raw );
			if ( '' !== $value ) {
				update_post_meta( $profile_id, 'ansp_' . $key, $value );
			} else {
				delete_post_meta( $profile_id, 'ansp_' . $key );
			}
		}

		// ---- Public-page visibility (separate from roster privacy) --------
		$public_raw   = isset( $_POST['ansp_public'] ) ? (array) wp_unslash( $_POST['ansp_public'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys checked against a whitelist below.
		$public_flags = array();
		foreach ( array_keys( ansp_public_field_keys() ) as $public_key ) {
			// Unticked boxes are absent from POST, so record an explicit
			// false rather than leaving the key out — ansp_is_field_public()
			// treats a missing key as "visible".
			$public_flags[ $public_key ] = ! empty( $public_raw[ $public_key ] );
		}
		update_post_meta( $profile_id, 'ansp_public', $public_flags );

		// ---- Privacy toggles (full set — this form renders every toggle) --
		update_post_meta(
			$profile_id,
			'ansp_privacy',
			ANSP_Profiles::sanitize_privacy( isset( $_POST['ansp_privacy'] ) ? wp_unslash( (array) $_POST['ansp_privacy'] ) : array() ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in helper.
		);

		// ---- Headshot upload → Featured Image -----------------------------
		$upload_error = false;
		if ( ! empty( $_FILES['ansp_headshot']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = media_handle_upload(
				'ansp_headshot',
				$profile_id,
				array(),
				array(
					'test_form' => false,
					'mimes'     => array(
						'jpg|jpeg|jpe' => 'image/jpeg',
						'png'          => 'image/png',
						'gif'          => 'image/gif',
						'webp'         => 'image/webp',
					),
				)
			);
			if ( is_wp_error( $attachment_id ) ) {
				$upload_error = true;
			} else {
				set_post_thumbnail( $profile_id, (int) $attachment_id );
			}
		}

		// Graceful when no headshot yet: everything else is saved, and the
		// singer gets a friendly nudge (legacy URL meta counts as present).
		$has_headshot = has_post_thumbnail( $profile_id )
			|| '' !== (string) get_post_meta( $profile_id, 'ansp_headshot_url', true );

		if ( $upload_error ) {
			$this->redirect( 'upload_failed' );
		}
		if ( ! $has_headshot ) {
			$this->redirect( 'missing_headshot' );
		}

		$this->redirect( 'saved' );
	}

	/**
	 * Logged-out submissions bounce to login.
	 *
	 * @return void
	 */
	public function handle_nopriv() {
		wp_safe_redirect( wp_login_url( ansp_get_portal_url() ) );
		exit;
	}

	/**
	 * Human-readable status message for the ?ansp_bio= code (used by
	 * templates/tab-bio.php). Returns array( type, message ) or null.
	 *
	 * @param string $code Status code from the query string.
	 * @return array{0:string,1:string}|null
	 */
	public static function status_message( $code ) {
		$map = array(
			'saved'            => array( 'success', __( 'Your bio was saved. Thank you!', 'ans-singers-portal' ) ),
			'no_profile'       => array( 'error', __( 'Your account is not linked to a singer profile yet — please contact the Personnel Manager.', 'ans-singers-portal' ) ),
			'missing_required' => array( 'error', __( 'Display name, at least one voice part and a valid email are required.', 'ans-singers-portal' ) ),
			'missing_headshot' => array( 'error', __( 'Please upload a headshot photo. Everything else was saved.', 'ans-singers-portal' ) ),
			'upload_failed'    => array( 'error', __( 'The photo could not be uploaded (JPG, PNG, GIF or WebP only). Everything else was saved.', 'ans-singers-portal' ) ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : null;
	}
}
