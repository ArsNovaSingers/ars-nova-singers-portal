<?php
/**
 * Singer profiles: user↔profile linking, group assignment, portal-only
 * contact fields and per-field roster privacy on the "singer" CPT.
 *
 * Since v1.1.0 the Portal no longer duplicates any profile-detail fields:
 * voice part(s), years, favorite piece/quote, pronouns and profession live
 * in the CANONICAL singer meta (parts, years_with_group, favorite_piece,
 * favorite_quote, pronouns, profession — see ANSP_Singer_CPT), the bio is
 * the singer post's content, and the headshot is its Featured Image. This
 * class keeps only what is portal-specific:
 *
 * - user meta  ansp_singer_profile : singer post ID linked to the account.
 * - post meta  ansp_user_id        : reverse link (user ID) on the profile.
 * - post meta  ansp_email, ansp_phone (contact, portal-only),
 *              ansp_inactive, ansp_privacy (array of roster-visibility flags
 *              for email/phone/pronouns/years/favorite_piece/favorite_quote/bio).
 *
 * Also provides the "Send portal invite" action used by the Personnel
 * Manager to create/link an account for a profile and email an invitation.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Profiles
 */
class ANSP_Profiles {

	/**
	 * Hook meta box, save handler and the invite action.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_singer', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_post_ansp_send_invite', array( $this, 'handle_send_invite' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Portal-only contact field definitions shared by the admin meta box,
	 * the front-end bio editor and the roster renderer.
	 *
	 * NOTE (v1.1.0): all duplicate profile-detail fields (voice part, years,
	 * favorite piece/quote, pronouns, bio, headshot URL) were REMOVED — those
	 * now live in the canonical singer meta / post content / featured image
	 * owned by ANSP_Singer_CPT. Only truly portal-specific contact fields
	 * remain here.
	 *
	 * 'private_toggle' => true means the field has a "visible to choir"
	 * checkbox (per-field privacy).
	 *
	 * @return array<string,array>
	 */
	public static function fields() {
		return array(
			'email' => array(
				'label'          => __( 'Email', 'ans-singers-portal' ),
				'type'           => 'email',
				'private_toggle' => true,
			),
			'phone' => array(
				'label'          => __( 'Phone', 'ans-singers-portal' ),
				'type'           => 'tel',
				'private_toggle' => true,
			),
		);
	}

	/**
	 * Every key that may carry a "visible to choir" privacy flag — the two
	 * portal contact fields plus the canonical profile-detail fields that the
	 * roster can display.
	 *
	 * @return string[]
	 */
	public static function privacy_keys() {
		return array( 'email', 'phone', 'pronouns', 'years', 'favorite_piece', 'favorite_quote', 'bio' );
	}

	/**
	 * Default per-field privacy: contact details hidden, the rest shared.
	 *
	 * @return array<string,string>
	 */
	public static function default_privacy() {
		return array(
			'pronouns'       => '1',
			'years'          => '1',
			'favorite_piece' => '1',
			'favorite_quote' => '1',
			'bio'            => '1',
		);
	}

	/**
	 * The saved privacy array for a profile (with defaults).
	 *
	 * @param int $post_id Profile ID.
	 * @return array<string,string>
	 */
	public static function get_privacy( $post_id ) {
		$privacy = get_post_meta( (int) $post_id, 'ansp_privacy', true );
		return is_array( $privacy ) ? $privacy : self::default_privacy();
	}

	/**
	 * Is a field flagged "visible to choir" on this profile?
	 *
	 * @param int    $post_id Profile ID.
	 * @param string $key     Field key.
	 * @return bool
	 */
	public static function is_field_visible( $post_id, $key ) {
		if ( ! in_array( (string) $key, self::privacy_keys(), true ) ) {
			return true; // Fields without a toggle are always shown.
		}
		$privacy = self::get_privacy( $post_id );
		return ! empty( $privacy[ $key ] );
	}

	/**
	 * A profile field value.
	 *
	 * @param int    $post_id Profile ID.
	 * @param string $key     Field key (un-prefixed).
	 * @return string
	 */
	public static function get_field( $post_id, $key ) {
		return (string) get_post_meta( (int) $post_id, 'ansp_' . $key, true );
	}

	/**
	 * The singer profile post linked to a user.
	 *
	 * @param int $user_id User ID.
	 * @return WP_Post|null
	 */
	public static function get_profile_for_user( $user_id ) {
		$profile_id = (int) get_user_meta( (int) $user_id, 'ansp_singer_profile', true );
		if ( ! $profile_id ) {
			return null;
		}
		$post = get_post( $profile_id );
		if ( $post instanceof WP_Post && 'singer' === $post->post_type && 'trash' !== $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * The user ID linked to a singer profile.
	 *
	 * @param int $post_id Profile ID.
	 * @return int 0 when unlinked.
	 */
	public static function get_user_for_profile( $post_id ) {
		return (int) get_post_meta( (int) $post_id, 'ansp_user_id', true );
	}

	/**
	 * Best headshot markup source for a profile: the Featured Image (the
	 * canonical headshot). Falls back to the legacy ansp_headshot_url meta
	 * (read-only — the editable field was removed in 1.1.0) so old data
	 * still renders.
	 *
	 * @param int $post_id Profile ID.
	 * @return string URL or ''.
	 */
	public static function get_headshot_url( $post_id ) {
		$thumb_id = get_post_thumbnail_id( (int) $post_id );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'medium' );
			if ( $src ) {
				return $src;
			}
		}
		return (string) get_post_meta( (int) $post_id, 'ansp_headshot_url', true );
	}

	/**
	 * Register the profile meta box on the third-party "singer" CPT.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		if ( ! post_type_exists( 'singer' ) ) {
			return; // Never fatal when the host plugin is absent.
		}
		add_meta_box(
			'ansp_singer_profile',
			__( 'Portal Account, Groups & Contact (Ars Nova Singers Portal)', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			'singer',
			'normal',
			'high'
		);
	}

	/**
	 * Render the portal panel: account link, groups, contact fields with
	 * privacy toggles, active flag and the invite button. Profile details
	 * (voice parts, years, favorites) live in the separate "Singer Profile
	 * Details" box; the bio is the content editor; the headshot is the
	 * Featured Image.
	 *
	 * @param WP_Post $post Singer profile being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_profile', 'ansp_profile_nonce' );

		$linked_user    = self::get_user_for_profile( $post->ID );
		$group_choices  = ANSP_Taxonomies::get_group_choices();
		$current_groups = wp_get_object_terms( $post->ID, 'ans_group', array( 'fields' => 'slugs' ) );
		$current_groups = is_wp_error( $current_groups ) ? array() : (array) $current_groups;
		$privacy        = self::get_privacy( $post->ID );
		$inactive       = '1' === get_post_meta( $post->ID, 'ansp_inactive', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="ansp_user_id"><?php esc_html_e( 'Linked user account', 'ans-singers-portal' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'ansp_user_id',
							'id'                => 'ansp_user_id',
							'selected'          => $linked_user,
							'show_option_none'  => __( '— Not linked —', 'ans-singers-portal' ),
							'option_none_value' => 0,
							'role__in'          => array( 'singer', 'artistic_director', 'personnel_manager', 'administrator' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'The WordPress account this singer signs in with. Stored as user meta ansp_singer_profile / post meta ansp_user_id.', 'ans-singers-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Groups', 'ans-singers-portal' ); ?></th>
				<td>
					<?php foreach ( $group_choices as $slug => $label ) : ?>
						<label class="ansp-inline-check">
							<input type="checkbox" name="ansp_profile_groups[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $current_groups, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<?php foreach ( self::fields() as $key => $field ) : ?>
				<tr>
					<th scope="row"><label for="ansp_field_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td>
						<?php $value = self::get_field( $post->ID, $key ); ?>
						<?php if ( 'textarea' === $field['type'] ) : ?>
							<textarea class="large-text" rows="4" id="ansp_field_<?php echo esc_attr( $key ); ?>" name="ansp_field_<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="<?php echo esc_attr( $field['type'] ); ?>" class="regular-text" id="ansp_field_<?php echo esc_attr( $key ); ?>" name="ansp_field_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
						<?php endif; ?>
						<?php if ( ! empty( $field['private_toggle'] ) ) : ?>
							<label class="ansp-privacy-toggle">
								<input type="checkbox" name="ansp_privacy[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $privacy[ $key ] ) ); ?> />
								<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
							</label>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ans-singers-portal' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ansp_inactive" value="1" <?php checked( $inactive ); ?> />
						<?php esc_html_e( 'Inactive (hidden from roster; see also user offboarding on the Users screen)', 'ans-singers-portal' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Portal invite', 'ans-singers-portal' ); ?></th>
				<td>
					<?php
					$invite_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'     => 'ansp_send_invite',
								'profile_id' => (int) $post->ID,
							),
							admin_url( 'admin-post.php' )
						),
						'ansp_send_invite_' . (int) $post->ID
					);
					?>
					<a href="<?php echo esc_url( $invite_url ); ?>" class="button">
						<?php esc_html_e( 'Send / resend portal invite', 'ans-singers-portal' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Creates (or reuses) an account for the Email field above, links it to this profile and emails an invitation with a set-password link. Save the profile first if you just changed the email.', 'ans-singers-portal' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Nonce-verified save handler for the profile panel.
	 *
	 * @param int     $post_id Profile ID.
	 * @param WP_Post $post    Profile post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_profile_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_profile_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_profile' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// ---- User link (both directions) ---------------------------------
		$new_user = isset( $_POST['ansp_user_id'] ) ? absint( $_POST['ansp_user_id'] ) : 0;
		$old_user = self::get_user_for_profile( $post_id );
		if ( $old_user && $old_user !== $new_user ) {
			delete_user_meta( $old_user, 'ansp_singer_profile' );
		}
		if ( $new_user && get_userdata( $new_user ) ) {
			update_post_meta( $post_id, 'ansp_user_id', $new_user );
			update_user_meta( $new_user, 'ansp_singer_profile', $post_id );
		} else {
			delete_post_meta( $post_id, 'ansp_user_id' );
		}

		// ---- Groups -------------------------------------------------------
		$term_ids = array();
		if ( ! empty( $_POST['ansp_profile_groups'] ) && is_array( $_POST['ansp_profile_groups'] ) ) {
			foreach ( wp_unslash( (array) $_POST['ansp_profile_groups'] ) as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised next line.
				$slug = sanitize_title( (string) $slug );
				$term = $slug ? get_term_by( 'slug', $slug, 'ans_group' ) : false;
				if ( $term instanceof WP_Term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
		}
		if ( taxonomy_exists( 'ans_group' ) ) {
			wp_set_object_terms( $post_id, $term_ids, 'ans_group', false );
		}

		// ---- Bio fields ---------------------------------------------------
		foreach ( self::fields() as $key => $field ) {
			$post_key = 'ansp_field_' . $key;
			$raw      = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below by type.
			$value    = self::sanitize_field( $key, $field['type'], $raw );
			if ( '' !== $value ) {
				update_post_meta( $post_id, 'ansp_' . $key, $value );
			} else {
				delete_post_meta( $post_id, 'ansp_' . $key );
			}
		}

		// ---- Privacy ------------------------------------------------------
		// This admin box only renders toggles for the contact fields, so we
		// MERGE those keys into the stored privacy array instead of replacing
		// it — the singer's own toggles for pronouns/years/favorites/bio
		// (saved from the front-end bio editor) must survive an admin save.
		$raw_privacy = isset( $_POST['ansp_privacy'] ) ? wp_unslash( (array) $_POST['ansp_privacy'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- boolean flags only, whitelisted below.
		$privacy     = self::get_privacy( $post_id );
		foreach ( array_keys( self::fields() ) as $contact_key ) {
			if ( ! empty( $raw_privacy[ $contact_key ] ) ) {
				$privacy[ $contact_key ] = '1';
			} else {
				unset( $privacy[ $contact_key ] );
			}
		}
		update_post_meta( $post_id, 'ansp_privacy', $privacy );

		// ---- Active flag --------------------------------------------------
		if ( ! empty( $_POST['ansp_inactive'] ) ) {
			update_post_meta( $post_id, 'ansp_inactive', '1' );
		} else {
			delete_post_meta( $post_id, 'ansp_inactive' );
		}
	}

	/**
	 * Sanitise one bio field by type.
	 *
	 * @param string $key   Field key.
	 * @param string $type  Field type.
	 * @param mixed  $value Raw (unslashed) value.
	 * @return string
	 */
	public static function sanitize_field( $key, $type, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );
			case 'url':
				return esc_url_raw( $value );
			case 'number':
				return '' === trim( $value ) ? '' : (string) absint( $value );
			case 'textarea':
				return 'bio' === $key ? wp_kses_post( $value ) : sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitise a submitted privacy array against the known privacy keys
	 * (contact fields + canonical profile-detail fields).
	 *
	 * @param array $raw Raw privacy input.
	 * @return array<string,string>
	 */
	public static function sanitize_privacy( $raw ) {
		$clean = array();
		foreach ( self::privacy_keys() as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$clean[ $key ] = '1';
			}
		}
		return $clean;
	}

	/**
	 * admin-post handler: create/link an account for a profile and send the
	 * invitation email (Personnel Manager / Artistic Director / admin only).
	 *
	 * @return void
	 */
	public function handle_send_invite() {
		if ( ! current_user_can( 'ansp_manage_roster' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to send portal invites.', 'ans-singers-portal' ) );
		}

		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		check_admin_referer( 'ansp_send_invite_' . $profile_id );

		$profile = get_post( $profile_id );
		$back    = $profile_id ? get_edit_post_link( $profile_id, 'raw' ) : admin_url();
		if ( ! $back ) {
			$back = admin_url();
		}

		if ( ! $profile instanceof WP_Post || 'singer' !== $profile->post_type ) {
			wp_safe_redirect( add_query_arg( 'ansp_notice', 'invite_bad_profile', $back ) );
			exit;
		}

		$email = sanitize_email( self::get_field( $profile_id, 'email' ) );
		if ( ! $email || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'ansp_notice', 'invite_no_email', $back ) );
			exit;
		}

		// Reuse an account if one exists for this email; otherwise create one.
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			$login      = $base_login ? $base_login : 'singer';
			$suffix     = 1;
			while ( username_exists( $login ) ) {
				$login = $base_login . (string) $suffix;
				$suffix++;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'display_name' => get_the_title( $profile_id ),
					'role'         => 'singer',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				wp_safe_redirect( add_query_arg( 'ansp_notice', 'invite_failed', $back ) );
				exit;
			}
			$user = get_userdata( $user_id );
		}

		// Link both directions.
		update_post_meta( $profile_id, 'ansp_user_id', (int) $user->ID );
		update_user_meta( $user->ID, 'ansp_singer_profile', $profile_id );

		// Build the set-password link.
		$key       = get_password_reset_key( $user );
		$reset_url = '';
		if ( ! is_wp_error( $key ) ) {
			$reset_url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
		}

		$subject = (string) get_option( 'ansp_invite_email_subject', '' );
		$body    = (string) get_option( 'ansp_invite_email_body', '' );
		if ( '' === trim( $subject ) ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Your %s Singers Portal account', 'ans-singers-portal' ), get_bloginfo( 'name' ) );
		}
		if ( '' === trim( $body ) ) {
			$body = __( "Hi {name},\n\nYou now have access to the Singers Portal.\n\nSet your password: {set_password_url}\nThen sign in and visit the portal: {portal_url}\n\nSee you there,\n{site_name}", 'ans-singers-portal' );
		}

		$replacements = array(
			'{name}'             => get_the_title( $profile_id ),
			'{portal_url}'       => ansp_get_portal_url(),
			'{set_password_url}' => $reset_url,
			'{site_name}'        => get_bloginfo( 'name' ),
		);
		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body );

		wp_mail( $email, wp_specialchars_decode( $subject, ENT_QUOTES ), $body );

		wp_safe_redirect( add_query_arg( 'ansp_notice', 'invite_sent', $back ) );
		exit;
	}

	/**
	 * Admin notices for invite outcomes (whitelisted codes only).
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! isset( $_GET['ansp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$code     = sanitize_key( wp_unslash( $_GET['ansp_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'invite_sent'        => array( 'success', __( 'Portal invite sent.', 'ans-singers-portal' ) ),
			'invite_no_email'    => array( 'error', __( 'This profile has no valid email address — add one and save first.', 'ans-singers-portal' ) ),
			'invite_bad_profile' => array( 'error', __( 'Invalid singer profile.', 'ans-singers-portal' ) ),
			'invite_failed'      => array( 'error', __( 'Could not create a user account for this profile.', 'ans-singers-portal' ) ),
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
