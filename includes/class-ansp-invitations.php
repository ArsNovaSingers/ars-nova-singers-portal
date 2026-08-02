<?php
/**
 * Invitations: send an access code to people, then track what happened.
 *
 * Sending a code is easy. Knowing who you sent it to, who acted on it and who
 * is still sitting on it is the part that actually saves work — otherwise
 * chasing 36 singers means reconstructing from memory and sent-mail.
 *
 * STATUS IS DERIVED, NEVER STORED. An invitation records only what we did
 * (address, code, when, who sent it). Whether that person has an account is
 * worked out live from the users table at render time. Storing a status field
 * would mean maintaining it on every registration, deletion and email change,
 * and it would be wrong the first time someone registers with a different
 * address than the one we mailed.
 *
 * Derived statuses:
 *   pending     - no user with that email address yet
 *   no profile  - user exists but is not linked to a singer profile
 *   hidden      - registered and linked, but Active singer is unticked
 *   active      - registered, linked and live on the public Singers page
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Invitations
 */
class ANSP_Invitations {

	const OPT_INVITES = 'ansp_invitations';
	const MAX_INVITES = 2000;

	/**
	 * Hook the admin block and the handlers.
	 */
	public function __construct() {
		add_action( 'ansp_after_access_codes', array( __CLASS__, 'render_block' ) );
		add_action( 'admin_post_ansp_send_invites', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_ansp_export_invites', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * All invitations, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_invites() {
		$invites = get_option( self::OPT_INVITES, array() );
		return is_array( $invites ) ? $invites : array();
	}

	/**
	 * Work out where an invited person has got to.
	 *
	 * @param string $email Invited address.
	 * @return array<string,mixed> { status, label, user_id, profile_id }
	 */
	public static function derive_status( $email ) {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return array(
				'status'     => 'pending',
				'label'      => __( 'Pending', 'ans-singers-portal' ),
				'user_id'    => 0,
				'profile_id' => 0,
			);
		}

		$profile_id = class_exists( 'ANSP_Profile_Link' )
			? ANSP_Profile_Link::get_profile_id( $user->ID )
			: 0;

		if ( ! $profile_id ) {
			return array(
				'status'     => 'no-profile',
				'label'      => __( 'No profile', 'ans-singers-portal' ),
				'user_id'    => (int) $user->ID,
				'profile_id' => 0,
			);
		}

		$active = class_exists( 'ANSP_Singers_Public' )
			? ANSP_Singers_Public::is_active( $profile_id )
			: true;

		return array(
			'status'     => $active ? 'active' : 'hidden',
			'label'      => $active ? __( 'Active', 'ans-singers-portal' ) : __( 'Hidden', 'ans-singers-portal' ),
			'user_id'    => (int) $user->ID,
			'profile_id' => (int) $profile_id,
		);
	}

	/**
	 * Every invitation with its live status attached.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_invites_with_status() {
		$out = array();
		foreach ( self::get_invites() as $invite ) {
			$email  = isset( $invite['email'] ) ? (string) $invite['email'] : '';
			$status = self::derive_status( $email );
			$out[]  = array_merge( $invite, $status );
		}
		return $out;
	}

	/**
	 * Counts by status, for the summary line.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with status.
	 * @return array<string,int>
	 */
	protected static function tally( $rows ) {
		$counts = array(
			'pending'    => 0,
			'no-profile' => 0,
			'hidden'     => 0,
			'active'     => 0,
		);
		foreach ( $rows as $row ) {
			$key = isset( $row['status'] ) ? $row['status'] : 'pending';
			if ( isset( $counts[ $key ] ) ) {
				++$counts[ $key ];
			}
		}
		return $counts;
	}

	/**
	 * Render the invitations block on the Access Codes screen.
	 *
	 * @return void
	 */
	public static function render_block() {
		$codes = ANSP_Registration::get_codes();
		$rows  = self::get_invites_with_status();
		$tally = self::tally( $rows );

		$sent    = isset( $_GET['ansp_sent'] ) ? absint( $_GET['ansp_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$failed  = isset( $_GET['ansp_failed'] ) ? absint( $_GET['ansp_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['ansp_skipped'] ) ? absint( $_GET['ansp_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<hr style="margin:28px 0;" />
		<h2><?php esc_html_e( 'Send invitations', 'ans-singers-portal' ); ?></h2>

		<?php if ( $sent || $failed || $skipped ) : ?>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: sent, 2: skipped, 3: failed */
						esc_html__( 'Sent %1$d. Skipped %2$d (already invited or already registered). Failed %3$d.', 'ans-singers-portal' ),
						(int) $sent,
						(int) $skipped,
						(int) $failed
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ansp_send_invites" />
			<?php wp_nonce_field( 'ansp_send_invites', 'ansp_invites_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ansp_invite_code"><?php esc_html_e( 'Which code', 'ans-singers-portal' ); ?></label></th>
					<td>
						<select name="ansp_invite_code" id="ansp_invite_code">
							<?php foreach ( $codes as $key => $def ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php disabled( empty( $def['enabled'] ) || '' === (string) $def['code'] ); ?>>
									<?php
									echo esc_html( $def['label'] );
									if ( empty( $def['enabled'] ) ) {
										echo ' — ' . esc_html__( 'disabled', 'ans-singers-portal' );
									} elseif ( '' === (string) $def['code'] ) {
										echo ' — ' . esc_html__( 'no code set', 'ans-singers-portal' );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'A disabled code cannot be sent — enable it and save first, or the recipient gets a code that will not work.', 'ans-singers-portal' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ansp_invite_emails"><?php esc_html_e( 'Email addresses', 'ans-singers-portal' ); ?></label></th>
					<td>
						<textarea name="ansp_invite_emails" id="ansp_invite_emails" rows="6" class="large-text" placeholder="one@example.org, two@example.org&#10;three@example.org"></textarea>
						<p class="description"><?php esc_html_e( 'Separated by commas, spaces or new lines. Anyone already invited or already registered is skipped automatically, so it is safe to paste the whole roster again.', 'ans-singers-portal' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ansp_invite_note"><?php esc_html_e( 'Personal note', 'ans-singers-portal' ); ?></label></th>
					<td>
						<textarea name="ansp_invite_note" id="ansp_invite_note" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Optional. Appears above the code in the email.', 'ans-singers-portal' ); ?>"></textarea>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Send invitations', 'ans-singers-portal' ), 'secondary' ); ?>
		</form>

		<h2><?php esc_html_e( 'Who we have invited', 'ans-singers-portal' ); ?></h2>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No invitations sent yet.', 'ans-singers-portal' ); ?></p>
		<?php else : ?>
			<p>
				<strong><?php echo esc_html( (string) count( $rows ) ); ?></strong> <?php esc_html_e( 'invited', 'ans-singers-portal' ); ?> —
				<?php
				printf(
					/* translators: 1: active, 2: hidden, 3: no profile, 4: pending */
					esc_html__( '%1$d active, %2$d hidden, %3$d with no profile, %4$d still pending.', 'ans-singers-portal' ),
					(int) $tally['active'],
					(int) $tally['hidden'],
					(int) $tally['no-profile'],
					(int) $tally['pending']
				);
				?>
			</p>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansp_export_invites&format=csv' ), 'ansp_export_invites' ) ); ?>">
					<?php esc_html_e( 'Download CSV', 'ans-singers-portal' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansp_export_invites&format=json' ), 'ansp_export_invites' ) ); ?>">
					<?php esc_html_e( 'Download JSON', 'ans-singers-portal' ); ?>
				</a>
				<button type="button" class="button" id="ansp-copy-claude">
					<?php esc_html_e( 'Copy for Claude', 'ans-singers-portal' ); ?>
				</button>
				<span id="ansp-copy-status" style="margin-left:8px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( '"Copy for Claude" puts a short prompt plus the full data on your clipboard — paste it into a chat. It copies rather than opening a link because a URL cannot reliably carry this much data.', 'ans-singers-portal' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'ans-singers-portal' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ans-singers-portal' ); ?></th>
						<th><?php esc_html_e( 'Code', 'ans-singers-portal' ); ?></th>
						<th><?php esc_html_e( 'Invited', 'ans-singers-portal' ); ?></th>
						<th><?php esc_html_e( 'By', 'ans-singers-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$colour = '#646970';
						if ( 'active' === $row['status'] ) {
							$colour = '#008a20';
						} elseif ( 'hidden' === $row['status'] ) {
							$colour = '#996800';
						} elseif ( 'no-profile' === $row['status'] ) {
							$colour = '#b32d2e';
						}
						?>
						<tr>
							<td><?php echo esc_html( isset( $row['email'] ) ? $row['email'] : '' ); ?></td>
							<td>
								<strong style="color:<?php echo esc_attr( $colour ); ?>;"><?php echo esc_html( $row['label'] ); ?></strong>
								<?php if ( ! empty( $row['user_id'] ) ) : ?>
									— <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $row['user_id'] ) ); ?>"><?php esc_html_e( 'user', 'ans-singers-portal' ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $row['profile_id'] ) ) : ?>
									· <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['profile_id'] . '&action=edit' ) ); ?>"><?php esc_html_e( 'profile', 'ans-singers-portal' ); ?></a>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( isset( $row['code'] ) ? $row['code'] : '' ); ?></code></td>
							<td><?php echo esc_html( isset( $row['sent_at'] ) ? $row['sent_at'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $row['sent_by'] ) ? $row['sent_by'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<script>
			( function () {
				var button = document.getElementById( 'ansp-copy-claude' );
				var status = document.getElementById( 'ansp-copy-status' );
				if ( ! button ) {
					return;
				}
				var payload = <?php echo wp_json_encode( self::claude_payload( $rows ) ); ?>;
				button.addEventListener( 'click', function () {
					navigator.clipboard.writeText( payload ).then( function () {
						status.textContent = <?php echo wp_json_encode( __( 'Copied — paste into Claude.', 'ans-singers-portal' ) ); ?>;
					} ).catch( function () {
						status.textContent = <?php echo wp_json_encode( __( 'Could not copy — use Download JSON instead.', 'ans-singers-portal' ) ); ?>;
					} );
				} );
			}() );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * The prompt + data blob for the "Copy for Claude" button.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with status.
	 * @return string
	 */
	protected static function claude_payload( $rows ) {
		$slim = array();
		foreach ( $rows as $row ) {
			$slim[] = array(
				'email'   => isset( $row['email'] ) ? $row['email'] : '',
				'status'  => isset( $row['status'] ) ? $row['status'] : '',
				'code'    => isset( $row['code'] ) ? $row['code'] : '',
				'sent_at' => isset( $row['sent_at'] ) ? $row['sent_at'] : '',
			);
		}

		$prompt = "Here is the Ars Nova Singers Portal invitation tracker.\n\n"
			. "Statuses: pending = invited but no account yet; no-profile = account exists but is not linked to a singer profile; "
			. "hidden = registered but not shown on the public Singers page; active = registered and public.\n\n"
			. "Please tell me who still needs chasing, who is stuck in a broken state, and draft a short reminder email for the pending group.\n\n";

		return $prompt . wp_json_encode( $slim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Send invitations.
	 *
	 * @return void
	 */
	public static function handle_send() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send invitations.', 'ans-singers-portal' ) );
		}
		if ( ! isset( $_POST['ansp_invites_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_invites_nonce'] ) ), 'ansp_send_invites' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ans-singers-portal' ) );
		}

		$key   = isset( $_POST['ansp_invite_code'] ) ? sanitize_key( wp_unslash( $_POST['ansp_invite_code'] ) ) : '';
		$raw   = isset( $_POST['ansp_invite_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ansp_invite_emails'] ) ) : '';
		$note  = isset( $_POST['ansp_invite_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ansp_invite_note'] ) ) : '';
		$codes = ANSP_Registration::get_codes();

		$back = admin_url( 'admin.php?page=ansp-access-codes' );

		if ( ! isset( $codes[ $key ] ) || empty( $codes[ $key ]['enabled'] ) || '' === (string) $codes[ $key ]['code'] ) {
			wp_safe_redirect( add_query_arg( array( 'ansp_sent' => 0, 'ansp_failed' => 0, 'ansp_skipped' => 0 ), $back ) );
			exit;
		}

		$def  = $codes[ $key ];
		$code = (string) $def['code'];

		// Split on commas, whitespace and new lines.
		$parts = preg_split( '/[\s,;]+/', $raw );
		$parts = is_array( $parts ) ? array_filter( array_map( 'trim', $parts ) ) : array();

		$invites  = self::get_invites();
		$existing = array();
		foreach ( $invites as $inv ) {
			if ( ! empty( $inv['email'] ) ) {
				$existing[ strtolower( (string) $inv['email'] ) ] = true;
			}
		}

		$sender  = wp_get_current_user();
		$sent    = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( $parts as $email ) {
			$email = sanitize_email( $email );
			if ( ! is_email( $email ) ) {
				++$failed;
				continue;
			}
			// Idempotent: re-pasting the roster must not spam anyone.
			if ( isset( $existing[ strtolower( $email ) ] ) || email_exists( $email ) ) {
				++$skipped;
				continue;
			}

			if ( self::send_one( $email, $code, $def, $note ) ) {
				++$sent;
				$existing[ strtolower( $email ) ] = true;
				array_unshift(
					$invites,
					array(
						'email'   => $email,
						'code'    => $code,
						'code_key' => $key,
						'sent_at' => current_time( 'mysql' ),
						'sent_by' => $sender ? $sender->display_name : '',
					)
				);
			} else {
				++$failed;
			}
		}

		if ( count( $invites ) > self::MAX_INVITES ) {
			$invites = array_slice( $invites, 0, self::MAX_INVITES );
		}
		update_option( self::OPT_INVITES, $invites );

		wp_safe_redirect(
			add_query_arg(
				array(
					'ansp_sent'    => $sent,
					'ansp_failed'  => $failed,
					'ansp_skipped' => $skipped,
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Send one invitation email.
	 *
	 * @param string              $email Recipient.
	 * @param string              $code  Access code.
	 * @param array<string,mixed> $def   Code definition.
	 * @param string              $note  Optional personal note.
	 * @return bool
	 */
	protected static function send_one( $email, $code, $def, $note ) {
		$subject = __( 'Your Ars Nova Singers Portal access code', 'ans-singers-portal' );

		$lines = array();
		if ( '' !== trim( $note ) ) {
			$lines[] = trim( $note );
			$lines[] = '';
		}
		$lines[] = __( 'You can now set up your Ars Nova Singers Portal account.', 'ans-singers-portal' );
		$lines[] = '';
		$lines[] = sprintf( __( 'Access code: %s', 'ans-singers-portal' ), $code );
		$lines[] = sprintf( __( 'Portal: %s', 'ans-singers-portal' ), ansp_get_portal_url() );
		$lines[] = '';
		$lines[] = __( 'Open the portal, choose "First time here? Register", and enter the code along with your name, email and a password of your choosing. You will be signed in straight away.', 'ans-singers-portal' );
		if ( ! empty( $def['expires'] ) ) {
			$lines[] = '';
			$lines[] = sprintf( __( 'This code expires on %s.', 'ans-singers-portal' ), (string) $def['expires'] );
		}
		$lines[] = '';
		$lines[] = __( 'Please keep the code to yourself — it opens the choir\'s music library.', 'ans-singers-portal' );

		return (bool) wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Download the tracker as CSV or JSON.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export invitations.', 'ans-singers-portal' ) );
		}
		check_admin_referer( 'ansp_export_invites' );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		$rows   = self::get_invites_with_status();
		$stamp  = gmdate( 'Ymd-His' );

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="ans-invitations-' . $stamp . '.json"' );
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ans-invitations-' . $stamp . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'status', 'code', 'code_key', 'sent_at', 'sent_by', 'user_id', 'profile_id' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					isset( $row['email'] ) ? $row['email'] : '',
					isset( $row['status'] ) ? $row['status'] : '',
					isset( $row['code'] ) ? $row['code'] : '',
					isset( $row['code_key'] ) ? $row['code_key'] : '',
					isset( $row['sent_at'] ) ? $row['sent_at'] : '',
					isset( $row['sent_by'] ) ? $row['sent_by'] : '',
					isset( $row['user_id'] ) ? $row['user_id'] : 0,
					isset( $row['profile_id'] ) ? $row['profile_id'] : 0,
				)
			);
		}
		fclose( $out );
		exit;
	}
}
