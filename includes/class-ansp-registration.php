<?php
/**
 * Code-gated self-registration.
 *
 * A singer enters an access code, their name, email and a password. The
 * system creates the user, creates their `singer` profile, links the two and
 * logs them in — no admin step in between.
 *
 * WHY A CODE AND NOT OPEN REGISTRATION. Materials in this portal are open to
 * every authenticated singer (decision 2026-08-02: gatekeeping per file moves
 * work onto Tom and Zahnay and generates "I can't see that one file" email).
 * That makes the ACCOUNT the only boundary protecting copyrighted sheet music,
 * so registration cannot be open to the public. The code is that boundary.
 *
 * Consequences that follow, and are handled here:
 *  - the code is a shared secret and WILL eventually be forwarded, so it has
 *    an expiry, a max-use cap, a visible log and a one-click regenerate;
 *  - registration attempts are rate-limited per IP so the code cannot be
 *    brute-forced;
 *  - every registration emails the admins, so a leak is noticed in minutes
 *    rather than at the end of a season.
 *
 * Codes are per ROLE. The code determines what gets created — there is
 * deliberately no "which role do you want?" choice on the form, because that
 * would let a singer-code holder request board access and would require an
 * approval workflow to undo the risk it created.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Registration
 */
class ANSP_Registration {

	const OPT_CODES      = 'ansp_access_codes';
	const OPT_LOG        = 'ansp_registration_log';
	const OPT_NOTIFY     = 'ansp_registration_notify';
	const OPT_HISTORY    = 'ansp_code_history';
	const LOG_MAX        = 500;
	const RATE_LIMIT     = 6;    // Attempts per window, per IP.
	const RATE_WINDOW    = 900;  // 15 minutes.
	const MIN_PASSWORD   = 10;

	/**
	 * Hook admin screen, front-end form and the submit handler.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ), 20 );
		add_filter( 'ansp_login_prompt_extra', array( __CLASS__, 'render_register_panel' ) );
		add_action( 'admin_post_nopriv_ansp_register', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_ansp_register', array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Default code definitions.
	 *
	 * `singer` is enabled now. `board` ships DISABLED but structurally
	 * present, so it can be switched on later without a code change —
	 * Jonathan, 2026-08-02: start with singers, add board manually for the
	 * few people who are both.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function default_codes() {
		return array(
			'singer' => array(
				'label'     => __( 'Singer', 'ans-singers-portal' ),
				'role'      => 'singer',
				'group'     => '',
				'profile'   => true,
				'code'      => '',
				'enabled'   => false,
				'expires'   => '',
				'max_uses'  => 0,
				'uses'      => 0,
			),
		);
	}

	/**
	 * The shape every code shares. Custom codes are merged over this, so a
	 * code stored before v1.10.0 (no group, no profile flag) still resolves.
	 *
	 * @return array<string,mixed>
	 */
	public static function code_defaults() {
		return array(
			'label'    => '',
			'role'     => 'singer',
			'group'    => '',
			'profile'  => true,
			'code'     => '',
			'enabled'  => false,
			'expires'  => '',
			'max_uses' => 0,
			'uses'     => 0,
		);
	}

	/**
	 * Stored codes, merged over the defaults.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_codes() {
		$stored = get_option( self::OPT_CODES, array() );
		$codes  = self::default_codes();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}

				$base = isset( $codes[ $key ] ) ? $codes[ $key ] : self::code_defaults();
				$row  = array_merge( $base, $row );

				// A stored role must be one that actually exists, or the account
				// comes out with no role at all and the singer is locked out of
				// the portal with an unhelpful message. This is exactly how the
				// old hardcoded 'ans_board' code failed.
				if ( ! get_role( (string) $row['role'] ) ) {
					$row['role'] = 'singer';
				}
				$row['group'] = sanitize_key( (string) $row['group'] );

				$codes[ $key ] = $row;
			}
		}

		/**
		 * Filter the access codes.
		 *
		 * @param array<string,array<string,mixed>> $codes
		 */
		return apply_filters( 'ansp_access_codes', $codes );
	}

	/**
	 * Codes that carry a group, keyed by group slug. Used by the admin screen
	 * to warn when two codes point at the same group.
	 *
	 * @return array<string,string> group slug => code key.
	 */
	public static function codes_by_group() {
		$map = array();
		foreach ( self::get_codes() as $key => $def ) {
			$slug = isset( $def['group'] ) ? (string) $def['group'] : '';
			if ( '' !== $slug && ! isset( $map[ $slug ] ) ) {
				$map[ $slug ] = $key;
			}
		}
		return $map;
	}

	/**
	 * Match a submitted code against the enabled, valid codes.
	 *
	 * @param string $submitted Raw submitted code.
	 * @return string|WP_Error Code key on success.
	 */
	public static function match_code( $submitted ) {
		$submitted = trim( (string) $submitted );

		if ( '' === $submitted ) {
			return new WP_Error( 'no_code', __( 'Please enter your access code.', 'ans-singers-portal' ) );
		}

		foreach ( self::get_codes() as $key => $def ) {
			if ( empty( $def['enabled'] ) || '' === (string) $def['code'] ) {
				continue;
			}
			// Constant-time compare, case-insensitive for human-typed codes.
			if ( ! hash_equals( strtoupper( (string) $def['code'] ), strtoupper( $submitted ) ) ) {
				continue;
			}
			if ( ! empty( $def['expires'] ) && current_time( 'Y-m-d' ) > (string) $def['expires'] ) {
				return new WP_Error( 'expired', __( 'That access code has expired. Please ask for the current one.', 'ans-singers-portal' ) );
			}
			if ( ! empty( $def['max_uses'] ) && (int) $def['uses'] >= (int) $def['max_uses'] ) {
				return new WP_Error( 'exhausted', __( 'That access code has reached its limit. Please ask for a new one.', 'ans-singers-portal' ) );
			}
			return $key;
		}

		return new WP_Error( 'bad_code', __( 'That access code was not recognised.', 'ans-singers-portal' ) );
	}

	/**
	 * The requester's IP, for rate limiting only.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Rate-limit key for this IP.
	 *
	 * @return string
	 */
	protected static function rate_key() {
		return 'ansp_reg_' . md5( self::client_ip() );
	}

	/**
	 * Has this IP exceeded the attempt limit?
	 *
	 * @return bool
	 */
	protected static function rate_limited() {
		return (int) get_transient( self::rate_key() ) >= self::RATE_LIMIT;
	}

	/**
	 * Record an attempt against the rate limit.
	 *
	 * @return void
	 */
	protected static function bump_rate() {
		$key = self::rate_key();
		$n   = (int) get_transient( $key );
		set_transient( $key, $n + 1, self::RATE_WINDOW );
	}

	/**
	 * The "First time here? Register" panel on the logged-out portal card.
	 *
	 * @param string $extra Existing extra markup.
	 * @return string
	 */
	public static function render_register_panel( $extra ) {
		$has_open_code = false;
		foreach ( self::get_codes() as $def ) {
			if ( ! empty( $def['enabled'] ) && '' !== (string) $def['code'] ) {
				$has_open_code = true;
				break;
			}
		}
		if ( ! $has_open_code ) {
			return $extra; // Nothing to register into — show nothing at all.
		}

		$error  = isset( $_GET['ansp_reg_error'] ) ? sanitize_text_field( wp_unslash( $_GET['ansp_reg_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$name   = isset( $_GET['ansp_name'] ) ? sanitize_text_field( wp_unslash( $_GET['ansp_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email  = isset( $_GET['ansp_email'] ) ? sanitize_email( wp_unslash( $_GET['ansp_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_open = ( '' !== $error );

		ob_start();
		?>
		<div class="ansp-register">
			<details class="ansp-register__details" <?php echo $is_open ? 'open' : ''; ?>>
				<summary class="ansp-register__summary"><?php esc_html_e( 'First time here? Register', 'ans-singers-portal' ); ?></summary>

				<p class="ansp-register__intro">
					<?php esc_html_e( 'You will need the access code you were given. If you do not have one, contact the Personnel Manager.', 'ans-singers-portal' ); ?>
				</p>

				<?php if ( $error ) : ?>
					<div class="ansp-notice ansp-notice--error"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ansp-register__form">
					<input type="hidden" name="action" value="ansp_register" />
					<?php wp_nonce_field( 'ansp_register', 'ansp_register_nonce' ); ?>

					<p>
						<label for="ansp_code"><?php esc_html_e( 'Access code', 'ans-singers-portal' ); ?></label><br />
						<input type="text" name="ansp_code" id="ansp_code" required autocomplete="off" />
					</p>
					<p>
						<label for="ansp_name"><?php esc_html_e( 'Full name', 'ans-singers-portal' ); ?></label><br />
						<input type="text" name="ansp_name" id="ansp_name" required value="<?php echo esc_attr( $name ); ?>" autocomplete="name" />
					</p>
					<p>
						<label for="ansp_email"><?php esc_html_e( 'Email', 'ans-singers-portal' ); ?></label><br />
						<input type="email" name="ansp_email" id="ansp_email" required value="<?php echo esc_attr( $email ); ?>" autocomplete="email" />
					</p>
					<p>
						<label for="ansp_password"><?php esc_html_e( 'Choose a password', 'ans-singers-portal' ); ?></label><br />
						<input type="password" name="ansp_password" id="ansp_password" required autocomplete="new-password" />
						<span class="description">
							<?php
							printf(
								/* translators: %d: minimum characters */
								esc_html__( 'At least %d characters.', 'ans-singers-portal' ),
								(int) self::MIN_PASSWORD
							);
							?>
						</span>
					</p>

					<?php // Honeypot — real people never fill this in. ?>
					<p class="ansp-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
						<label for="ansp_website"><?php esc_html_e( 'Website', 'ans-singers-portal' ); ?></label>
						<input type="text" name="ansp_website" id="ansp_website" tabindex="-1" autocomplete="off" />
					</p>

					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create my account', 'ans-singers-portal' ); ?></button></p>
				</form>
			</details>
		</div>
		<style>
			.ansp-register { margin-top: 1.25rem; border-top: 1px solid rgba(0,0,0,.12); padding-top: 1rem; }
			.ansp-register__summary { cursor: pointer; font-weight: 600; }
			.ansp-register__intro { font-size: .92rem; opacity: .85; }
			.ansp-register__form label { font-weight: 600; }
			.ansp-register__form input[type="text"],
			.ansp-register__form input[type="email"],
			.ansp-register__form input[type="password"] { width: 100%; }
			.ansp-notice--error { background: #fdecea; border-left: 4px solid #b32d2e; padding: .6rem .8rem; margin: .6rem 0; }
		</style>
		<?php
		return $extra . (string) ob_get_clean();
	}

	/**
	 * Bounce back to the portal with an error message.
	 *
	 * @param string $message Human-readable error.
	 * @param array  $keep    Field values worth preserving.
	 * @return void
	 */
	protected static function fail( $message, $keep = array() ) {
		$args = array_merge( array( 'ansp_reg_error' => $message ), $keep );
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), ansp_get_portal_url() ) );
		exit;
	}

	/**
	 * Handle the registration submit.
	 *
	 * @return void
	 */
	public static function handle_submit() {
		if ( ! isset( $_POST['ansp_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_register_nonce'] ) ), 'ansp_register' ) ) {
			self::fail( __( 'Your session expired. Please try again.', 'ans-singers-portal' ) );
		}

		// Honeypot: silently succeed-looking, never create anything.
		if ( ! empty( $_POST['ansp_website'] ) ) {
			wp_safe_redirect( ansp_get_portal_url() );
			exit;
		}

		if ( self::rate_limited() ) {
			self::fail( __( 'Too many attempts. Please wait a few minutes and try again.', 'ans-singers-portal' ) );
		}
		self::bump_rate();

		$code_raw = isset( $_POST['ansp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_code'] ) ) : '';
		$name     = isset( $_POST['ansp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_name'] ) ) : '';
		$email    = isset( $_POST['ansp_email'] ) ? sanitize_email( wp_unslash( $_POST['ansp_email'] ) ) : '';
		$password = isset( $_POST['ansp_password'] ) ? (string) wp_unslash( $_POST['ansp_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered.

		$keep = array(
			'ansp_name'  => $name,
			'ansp_email' => $email,
		);

		$code_key = self::match_code( $code_raw );
		if ( is_wp_error( $code_key ) ) {
			self::fail( $code_key->get_error_message(), $keep );
		}

		if ( '' === $name ) {
			self::fail( __( 'Please enter your full name.', 'ans-singers-portal' ), $keep );
		}
		if ( ! is_email( $email ) ) {
			self::fail( __( 'Please enter a valid email address.', 'ans-singers-portal' ), $keep );
		}
		if ( email_exists( $email ) ) {
			self::fail( __( 'An account already exists for that email. Try signing in, or use "Forgot your password?".', 'ans-singers-portal' ), $keep );
		}
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			self::fail(
				sprintf(
					/* translators: %d: minimum characters */
					__( 'Please choose a password of at least %d characters.', 'ans-singers-portal' ),
					(int) self::MIN_PASSWORD
				),
				$keep
			);
		}

		$codes = self::get_codes();
		$def   = $codes[ $code_key ];

		// Username from the email local part, made unique.
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = $base ? $base : 'singer';
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => trim( (string) strstr( $name, ' ', true ) ),
				'last_name'    => trim( (string) strrchr( $name, ' ' ) ),
				'role'         => $def['role'],
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::fail( $user_id->get_error_message(), $keep );
		}
		$user_id = (int) $user_id;

		// Create and link the matching profile.
		$profile_id = 0;
		if ( ! empty( $def['profile'] ) ) {
			$profile_id = wp_insert_post(
				array(
					'post_type'   => 'singer',
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( ! is_wp_error( $profile_id ) ) {
				$profile_id = (int) $profile_id;
				update_user_meta( $user_id, ANSP_Profile_Link::META, $profile_id );

				/*
				 * Put the singer in the code's group.
				 *
				 * This is the whole point of a per-group code: whoever redeems
				 * the Chamber Singers code lands in Chamber Singers without the
				 * Personnel Manager touching the record. Group membership lives
				 * on the singer PROFILE (that is where ANSP_Permissions reads
				 * it from), not on the WP user.
				 *
				 * Silently skipped when the code carries no group, or when the
				 * stored slug no longer matches a real term — a renamed or
				 * deleted group must never cost someone their account.
				 */
				$group_slug = isset( $def['group'] ) ? sanitize_key( (string) $def['group'] ) : '';
				if ( '' !== $group_slug && taxonomy_exists( 'ans_group' ) ) {
					$term = get_term_by( 'slug', $group_slug, 'ans_group' );
					if ( $term instanceof WP_Term ) {
						wp_set_object_terms( $profile_id, array( (int) $term->term_id ), 'ans_group', false );
					}
				}
			} else {
				$profile_id = 0;
			}
		}

		// Burn one use of the code.
		$codes[ $code_key ]['uses'] = (int) $def['uses'] + 1;
		update_option( self::OPT_CODES, $codes );

		self::log_registration( $user_id, $name, $email, $code_key );
		self::notify_admins( $name, $email, $def['label'], $user_id, $profile_id );

		// Sign them straight in — the point of self-registration is no waiting.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		wp_safe_redirect( ansp_get_portal_url() . '#bio' );
		exit;
	}

	/**
	 * Append to the registration log.
	 *
	 * @param int    $user_id  New user ID.
	 * @param string $name     Display name.
	 * @param string $email    Email.
	 * @param string $code_key Which code was used.
	 * @return void
	 */
	protected static function log_registration( $user_id, $name, $email, $code_key ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'user_id' => (int) $user_id,
				'name'    => $name,
				'email'   => $email,
				'code'    => $code_key,
				'ip'      => self::client_ip(),
			)
		);
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, 0, self::LOG_MAX );
		}
		update_option( self::OPT_LOG, $log );
	}

	/**
	 * Email the admins that someone registered.
	 *
	 * Notification, deliberately NOT approval: whoever handed out the code has
	 * already made the decision. This exists so a leaked code is noticed in
	 * minutes rather than at the end of a season.
	 *
	 * @param string $name       Display name.
	 * @param string $email      Email.
	 * @param string $role_label Human label of the code used.
	 * @param int    $user_id    New user ID.
	 * @param int    $profile_id New profile ID (0 if none).
	 * @return void
	 */
	protected static function notify_admins( $name, $email, $role_label, $user_id, $profile_id ) {
		$to = get_option( self::OPT_NOTIFY, '' );
		$to = $to ? array_filter( array_map( 'trim', explode( ',', (string) $to ) ) ) : array( get_option( 'admin_email' ) );
		if ( empty( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: name, 2: role label */
			__( '[Ars Nova Portal] %1$s registered as %2$s', 'ans-singers-portal' ),
			$name,
			$role_label
		);

		$lines = array(
			sprintf( 'Name:  %s', $name ),
			sprintf( 'Email: %s', $email ),
			sprintf( 'Role:  %s', $role_label ),
			'',
			sprintf( 'User:    %s', admin_url( 'user-edit.php?user_id=' . (int) $user_id ) ),
		);
		if ( $profile_id ) {
			$lines[] = sprintf( 'Profile: %s', admin_url( 'post.php?post=' . (int) $profile_id . '&action=edit' ) );
			$lines[] = '';
			$lines[] = 'They are live on the public Singers page now. Untick "Active singer" on the profile to remove them.';
		}
		$lines[] = '';
		$lines[] = 'If you were not expecting this, the access code may have been shared. Regenerate it under Singers Portal > Access Codes.';

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Register the Access Codes admin screen.
	 *
	 * @return void
	 */
	public static function add_admin_page() {
		add_submenu_page(
			'ansp-dashboard',
			__( 'Access Codes', 'ans-singers-portal' ),
			__( 'Access Codes', 'ans-singers-portal' ),
			'ansp_manage_portal',
			'ansp-access-codes',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render (and save) the Access Codes screen.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage access codes.', 'ans-singers-portal' ) );
		}

		$saved = false;

		if ( isset( $_POST['ansp_codes_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_codes_nonce'] ) ), 'ansp_save_codes' ) ) {
			$codes = self::get_codes();

			foreach ( array_keys( $codes ) as $key ) {
				if ( isset( $_POST[ 'code_' . $key ] ) ) {
					$new = sanitize_text_field( wp_unslash( $_POST[ 'code_' . $key ] ) );
					/*
					 * Changing the code retires the old one, so the use count
					 * has to start again. Doing it automatically rather than
					 * via a checkbox removes a way to get this wrong: a fresh
					 * code inheriting an exhausted counter would refuse every
					 * registration with "reached its limit", which is a
					 * miserable thing to debug.
					 */
					if ( $new !== (string) $codes[ $key ]['code'] ) {
						self::archive_code( $key, $codes[ $key ] );
						$codes[ $key ]['uses']    = 0;
						$codes[ $key ]['created'] = current_time( 'mysql' );
					}
					$codes[ $key ]['code'] = $new;
				}
				$codes[ $key ]['enabled']  = ! empty( $_POST[ 'enabled_' . $key ] );
				$codes[ $key ]['expires']  = isset( $_POST[ 'expires_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'expires_' . $key ] ) ) : '';
				$codes[ $key ]['max_uses'] = isset( $_POST[ 'max_uses_' . $key ] ) ? absint( $_POST[ 'max_uses_' . $key ] ) : 0;

				// Which group this code drops a new singer into. '' = none.
				if ( isset( $_POST[ 'group_' . $key ] ) ) {
					$codes[ $key ]['group'] = sanitize_key( wp_unslash( $_POST[ 'group_' . $key ] ) );
				}
				// Custom codes own their label; the built-in 'singer' one does not.
				if ( 'singer' !== $key && isset( $_POST[ 'label_' . $key ] ) ) {
					$codes[ $key ]['label'] = sanitize_text_field( wp_unslash( $_POST[ 'label_' . $key ] ) );
				}
				// Retire a custom code entirely.
				if ( 'singer' !== $key && ! empty( $_POST[ 'delete_' . $key ] ) ) {
					self::archive_code( $key, $codes[ $key ] );
					unset( $codes[ $key ] );
				}
			}

			/*
			 * Add a code. Keyed by its group slug so "one code per group" is
			 * structurally true rather than a convention someone has to
			 * remember — adding a second code for a group overwrites the
			 * first instead of quietly creating a rival.
			 */
			$new_label = isset( $_POST['ansp_new_label'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_new_label'] ) ) : '';
			$new_group = isset( $_POST['ansp_new_group'] ) ? sanitize_key( wp_unslash( $_POST['ansp_new_group'] ) ) : '';
			if ( '' !== $new_label ) {
				$new_key = $new_group ? $new_group : sanitize_key( $new_label );
				if ( '' !== $new_key && 'singer' !== $new_key ) {
					$codes[ $new_key ] = array_merge(
						self::code_defaults(),
						array(
							'label'   => $new_label,
							'role'    => 'singer',
							'group'   => $new_group,
							'profile' => true,
							'code'    => self::generate_code(),
							'enabled' => true,
							'created' => current_time( 'mysql' ),
						)
					);
				}
			}

			update_option( self::OPT_CODES, $codes );
			update_option( self::OPT_NOTIFY, isset( $_POST['ansp_notify'] ) ? sanitize_text_field( wp_unslash( $_POST['ansp_notify'] ) ) : '' );
			$saved = true;
		}

		$codes  = self::get_codes();
		$log    = get_option( self::OPT_LOG, array() );
		$notify = (string) get_option( self::OPT_NOTIFY, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Access Codes', 'ans-singers-portal' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'ans-singers-portal' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Anyone with an enabled code can create their own account and will be signed in immediately. Treat a code like a key to the music library: share it directly with the people who should have it, set an expiry, and regenerate it each season.', 'ans-singers-portal' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'ansp_save_codes', 'ansp_codes_nonce' ); ?>

				<?php $ansp_group_choices = ANSP_Taxonomies::get_group_choices(); ?>
				<?php foreach ( $codes as $key => $def ) : ?>
					<h2><?php echo esc_html( $def['label'] ); ?></h2>
					<?php if ( 'singer' !== $key ) : ?>
						<p>
							<label>
								<?php esc_html_e( 'Name shown here:', 'ans-singers-portal' ); ?>
								<input type="text" class="regular-text" name="label_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $def['label'] ); ?>" />
							</label>
							<label style="margin-left:1.5rem;color:#b32d2e;">
								<input type="checkbox" name="delete_<?php echo esc_attr( $key ); ?>" value="1" />
								<?php esc_html_e( 'Delete this code on save', 'ans-singers-portal' ); ?>
							</label>
						</p>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'ans-singers-portal' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enabled_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $def['enabled'] ) ); ?> />
									<?php esc_html_e( 'Allow registration with this code', 'ans-singers-portal' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="code_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Code', 'ans-singers-portal' ); ?></label></th>
							<td>
								<input type="text" class="regular-text ansp-code-field" id="code_<?php echo esc_attr( $key ); ?>" name="code_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $def['code'] ); ?>" />
								<button type="button" class="button ansp-generate" data-target="code_<?php echo esc_attr( $key ); ?>" style="margin-left:8px;">
									<?php esc_html_e( 'Generate random code', 'ans-singers-portal' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Generating replaces the code in the box — nothing changes until you press Save. Saving a different code resets its use count and immediately stops the old one working.', 'ans-singers-portal' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="group_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Puts the singer in', 'ans-singers-portal' ); ?></label></th>
							<td>
								<select id="group_<?php echo esc_attr( $key ); ?>" name="group_<?php echo esc_attr( $key ); ?>">
									<option value=""><?php esc_html_e( '— no group —', 'ans-singers-portal' ); ?></option>
									<?php foreach ( $ansp_group_choices as $ansp_g_slug => $ansp_g_name ) : ?>
										<option value="<?php echo esc_attr( $ansp_g_slug ); ?>" <?php selected( (string) $def['group'], (string) $ansp_g_slug ); ?>><?php echo esc_html( $ansp_g_name ); ?></option>
									<?php endforeach; ?>

				<hr />
				<h2><?php esc_html_e( 'Add a code', 'ans-singers-portal' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ansp_new_label"><?php esc_html_e( 'Name', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ansp_new_label" name="ansp_new_label" value="" placeholder="<?php esc_attr_e( 'e.g. Chamber Singers', 'ans-singers-portal' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to add nothing. A random code is generated and enabled on save — you can change it afterwards.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ansp_new_group"><?php esc_html_e( 'Puts the singer in', 'ans-singers-portal' ); ?></label></th>
						<td>
							<select id="ansp_new_group" name="ansp_new_group">
								<option value=""><?php esc_html_e( '— no group —', 'ans-singers-portal' ); ?></option>
								<?php foreach ( $ansp_group_choices as $ansp_g_slug => $ansp_g_name ) : ?>
									<option value="<?php echo esc_attr( $ansp_g_slug ); ?>"><?php echo esc_html( $ansp_g_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'One code per group. Adding a second code for a group replaces the first.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
				</table>
								</select>
								<p class="description">
									<?php esc_html_e( 'Anyone who registers with this code is added to this group automatically — no need to edit their record afterwards. Leave as "no group" and they arrive with none, which means they see only materials shared with everyone.', 'ans-singers-portal' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="expires_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Expires', 'ans-singers-portal' ); ?></label></th>
							<td>
								<input type="date" id="expires_<?php echo esc_attr( $key ); ?>" name="expires_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $def['expires'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank for no expiry. A season end date is a sensible default.', 'ans-singers-portal' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_uses_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Maximum uses', 'ans-singers-portal' ); ?></label></th>
							<td>
								<input type="number" min="0" step="1" id="max_uses_<?php echo esc_attr( $key ); ?>" name="max_uses_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $def['max_uses'] ); ?>" />
								<p class="description">
									<?php
									printf(
										/* translators: %d: uses so far */
										esc_html__( '0 means unlimited. Used %d times so far.', 'ans-singers-portal' ),
										(int) $def['uses']
									);
									?>
								</p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Notifications', 'ans-singers-portal' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ansp_notify"><?php esc_html_e( 'Notify these addresses', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="ansp_notify" name="ansp_notify" value="<?php echo esc_attr( $notify ); ?>" placeholder="a@example.org, b@example.org" />
							<p class="description"><?php esc_html_e( 'Comma-separated. Emailed on every registration. Leave blank to use the site admin address.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<script>
			( function () {
				// Same alphabet as ANSP_Registration::generate_code() — no 0/O
				// or 1/I/L, because these get read aloud at rehearsal and typed
				// off a printed sheet.
				var ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

				function randomCode() {
					var out = 'ANS-';
					var bytes = new Uint32Array( 8 );
					// crypto, not Math.random — this string is the only thing
					// protecting the music library.
					window.crypto.getRandomValues( bytes );
					for ( var i = 0; i < 8; i++ ) {
						if ( i === 4 ) {
							out += '-';
						}
						out += ALPHABET.charAt( bytes[ i ] % ALPHABET.length );
					}
					return out;
				}

				document.querySelectorAll( '.ansp-generate' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var field = document.getElementById( button.getAttribute( 'data-target' ) );
						if ( ! field ) {
							return;
						}
						field.value = randomCode();
						field.focus();
						field.select();
					} );
				} );
			}() );
			</script>

			<h2><?php esc_html_e( 'Recent registrations', 'ans-singers-portal' ); ?></h2>
			<?php if ( empty( $log ) || ! is_array( $log ) ) : ?>
				<p><?php esc_html_e( 'Nobody has registered yet.', 'ans-singers-portal' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'Name', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'Email', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'Code', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'IP', 'ans-singers-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $log, 0, 100 ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ); ?></td>
								<td>
									<?php if ( ! empty( $entry['user_id'] ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $entry['user_id'] ) ); ?>">
											<?php echo esc_html( isset( $entry['name'] ) ? $entry['name'] : '' ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( isset( $entry['name'] ) ? $entry['name'] : '' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( isset( $entry['email'] ) ? $entry['email'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['code'] ) ? $entry['code'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['ip'] ) ? $entry['ip'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			/**
			 * Render after the codes and registration log — used by
			 * ANSP_Invitations for the send form, the invitation tracker and
			 * the exports.
			 */
			do_action( 'ansp_after_access_codes' );
			?>

			<h2><?php esc_html_e( 'Retired codes', 'ans-singers-portal' ); ?></h2>
			<?php $ansp_history = self::get_history(); ?>
			<?php if ( empty( $ansp_history ) ) : ?>
				<p><?php esc_html_e( 'No codes have been retired yet. When you change a code, the old one is archived here.', 'ans-singers-portal' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'These no longer work. Kept so that a singer quoting an old code can be told when it was retired.', 'ans-singers-portal' ); ?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'For', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'Times used', 'ans-singers-portal' ); ?></th>
							<th><?php esc_html_e( 'Retired', 'ans-singers-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ansp_history as $ansp_old ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $ansp_old['code'] ) ? $ansp_old['code'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $ansp_old['label'] ) ? $ansp_old['label'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $ansp_old['uses'] ) ? (string) $ansp_old['uses'] : '0' ); ?></td>
								<td><?php echo esc_html( isset( $ansp_old['retired'] ) ? $ansp_old['retired'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Archive a code that is being replaced.
	 *
	 * Retired codes are kept so an invitation sent last season can still be
	 * explained: "that code was retired on 12 August, here is the current one."
	 * Without this, a singer quoting an old code is unexplainable.
	 *
	 * @param string               $key Code key.
	 * @param array<string,mixed>  $def The code definition being retired.
	 * @return void
	 */
	public static function archive_code( $key, $def ) {
		if ( '' === (string) $def['code'] ) {
			return; // Never had a code — nothing to retire.
		}

		$history = get_option( self::OPT_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		array_unshift(
			$history,
			array(
				'key'     => $key,
				'label'   => isset( $def['label'] ) ? $def['label'] : $key,
				'code'    => (string) $def['code'],
				'uses'    => (int) $def['uses'],
				'created' => isset( $def['created'] ) ? $def['created'] : '',
				'retired' => current_time( 'mysql' ),
			)
		);

		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, 0, 100 );
		}

		update_option( self::OPT_HISTORY, $history );
	}

	/**
	 * Retired codes, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history() {
		$history = get_option( self::OPT_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Generate a readable code, e.g. ANS-7K4Q-2WMB.
	 *
	 * Excludes characters that are easily confused when read aloud or typed
	 * from a printed sheet (0/O, 1/I/L).
	 *
	 * @return string
	 */
	public static function generate_code() {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$out      = '';
		for ( $i = 0; $i < 8; $i++ ) {
			if ( 4 === $i ) {
				$out .= '-';
			}
			$out .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
		}
		return 'ANS-' . $out;
	}
}
