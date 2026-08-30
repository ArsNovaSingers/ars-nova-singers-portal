<?php
/**
 * "My Comps" - what this singer has already sent, and the two things they can
 * still do about it.
 *
 * Jonathan's ask, 2026-08-30: "Below the comps cart should be the comp ledger
 * with the title 'My Comps' with the data of what was sent and an update area
 * where the singer can see if the comp was received and used - and a way to
 * 'send reminder email' which sends it again with the ability to EDIT the email
 * in case the singer entered the wrong email the first time."
 *
 * RECEIVED IS NOT A COLUMN HERE, AND THAT IS DELIBERATE. Sent we know. Used we
 * know - Tickera stamps the ticket when it is scanned at the door. Received we
 * do not: WordPress can only report that a message was handed to the mail
 * server, which is not delivery and is certainly not that anyone opened it.
 * Printing a "received" tick from that would be a comfortable lie, and the one
 * time it mattered - a guest at the door with no ticket - it is the reason
 * nobody would look further. Real delivery tracking is step 3.
 *
 * THE FIX-DETAILS FORM IS A <details>, CLOSED BY DEFAULT. The common case is
 * reading the ledger, not editing it, and a page of open text inputs invites
 * accidental edits on a phone.
 *
 * Included from tab-comps.php rather than being a tab of its own: a singer
 * thinking about comps wants both halves on one screen, and the cart above is
 * where the number in "1 of 2 left" comes from.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_ledger = ANSP_Comp_Claim::get_ledger( get_current_user_id() );

if ( empty( $ansp_ledger ) ) {
	return;
}

$ansp_dfmt = get_option( 'date_format' );
$ansp_tfmt = get_option( 'time_format' );
?>

<section class="ansp-ledger">

	<h4 class="ansp-ledger-title"><?php esc_html_e( 'My Comps', 'ans-singers-portal' ); ?></h4>

	<p class="ansp-ledger-intro">
		<?php esc_html_e( 'Everything you have sent. "At the door" fills in when your guest\'s ticket is scanned on the night.', 'ans-singers-portal' ); ?>
	</p>

	<ul class="ansp-ledger-list">
		<?php foreach ( $ansp_ledger as $ansp_row ) : ?>
			<li class="ansp-ledger-item">

				<div class="ansp-ledger-who">
					<span class="ansp-ledger-guest"><?php echo esc_html( $ansp_row['guest'] ); ?></span>
					<span class="ansp-ledger-email"><?php echo esc_html( $ansp_row['email'] ); ?></span>
				</div>

				<div class="ansp-ledger-what">
					<?php if ( $ansp_row['performance'] ) : ?>
						<span class="ansp-ledger-perf">
							<?php
							echo esc_html( $ansp_row['performance'] );
							if ( $ansp_row['when'] ) {
								echo ' — ' . esc_html( date_i18n( $ansp_dfmt . ', ' . $ansp_tfmt, $ansp_row['when'] ) );
							}
							if ( $ansp_row['location'] ) {
								echo ' · ' . esc_html( $ansp_row['location'] );
							}
							?>
						</span>
					<?php elseif ( $ansp_row['project'] ) : ?>
						<span class="ansp-ledger-perf"><?php echo esc_html( $ansp_row['project'] ); ?></span>
					<?php endif; ?>

					<span class="ansp-ledger-qty">
						<?php
						printf(
							/* translators: %d: number of tickets. */
							esc_html( _n( '%d ticket', '%d tickets', max( 1, (int) $ansp_row['qty'] ), 'ans-singers-portal' ) ),
							(int) $ansp_row['qty']
						);
						?>
					</span>
				</div>

				<div class="ansp-ledger-state">
					<span class="ansp-ledger-sent">
						<?php
						printf(
							/* translators: %s: the date the comp was sent. */
							esc_html__( 'Sent %s', 'ans-singers-portal' ),
							esc_html( $ansp_row['sent'] ? date_i18n( $ansp_dfmt, $ansp_row['sent'] ) : '—' )
						);

						if ( $ansp_row['resends'] ) {
							echo ' · ';
							printf(
								/* translators: 1: number of resends, 2: date of the most recent one. */
								esc_html( _n( 'resent once (%2$s)', 'resent %1$d times (last %2$s)', (int) $ansp_row['resends'], 'ans-singers-portal' ) ),
								(int) $ansp_row['resends'],
								esc_html( $ansp_row['last_resent'] ? date_i18n( $ansp_dfmt, $ansp_row['last_resent'] ) : '' )
							);
						}
						?>
					</span>

					<?php if ( $ansp_row['used'] > 0 ) : ?>
						<span class="ansp-ledger-badge is-used">
							<?php
							if ( $ansp_row['used'] >= $ansp_row['tickets'] ) {
								esc_html_e( 'At the door ✓', 'ans-singers-portal' );
							} else {
								printf(
									/* translators: 1: tickets scanned, 2: tickets sent. */
									esc_html__( 'At the door: %1$d of %2$d', 'ans-singers-portal' ),
									(int) $ansp_row['used'],
									(int) $ansp_row['tickets']
								);
							}
							?>
						</span>
					<?php else : ?>
						<span class="ansp-ledger-badge"><?php esc_html_e( 'Not used yet', 'ans-singers-portal' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $ansp_row['note'] ) : ?>
					<p class="ansp-ledger-note">
						<?php
						printf(
							/* translators: %s: the note the singer wrote to their guest. */
							esc_html__( 'Your note: %s', 'ans-singers-portal' ),
							esc_html( $ansp_row['note'] )
						);
						?>
					</p>
				<?php endif; ?>

				<div class="ansp-ledger-actions">

					<?php if ( $ansp_row['can_resend'] ) : ?>
						<form
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							class="ansp-ledger-form ansp-ledger-form--resend"
						>
							<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Comp_Claim::ACTION_MANAGE ); ?>" />
							<input type="hidden" name="ansp_comp_mode" value="resend" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( $ansp_row['order_id'] ); ?>" />
							<?php wp_nonce_field( ANSP_Comp_Claim::NONCE_MANAGE ); ?>
							<button type="submit" class="ansp-btn ansp-btn--ghost ansp-btn--small">
								<?php esc_html_e( 'Send it again', 'ans-singers-portal' ); ?>
							</button>
						</form>
					<?php else : ?>
						<p class="ansp-ledger-maxed">
							<?php esc_html_e( 'Sent as many times as it can be — if it is still not arriving, the address is probably wrong.', 'ans-singers-portal' ); ?>
						</p>
					<?php endif; ?>

					<details class="ansp-ledger-fix">
						<summary><?php esc_html_e( 'Wrong name or email?', 'ans-singers-portal' ); ?></summary>

						<form
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							class="ansp-ledger-form ansp-ledger-form--fix"
						>
							<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Comp_Claim::ACTION_MANAGE ); ?>" />
							<input type="hidden" name="ansp_comp_mode" value="update" />
							<input type="hidden" name="order_id" value="<?php echo esc_attr( $ansp_row['order_id'] ); ?>" />
							<?php wp_nonce_field( ANSP_Comp_Claim::NONCE_MANAGE ); ?>

							<span class="ansp-comp-field">
								<label class="ansp-comp-label" for="ansp-fix-name-<?php echo esc_attr( $ansp_row['order_id'] ); ?>">
									<?php esc_html_e( 'Full name', 'ans-singers-portal' ); ?>
								</label>
								<input
									type="text"
									id="ansp-fix-name-<?php echo esc_attr( $ansp_row['order_id'] ); ?>"
									name="guest_name"
									value="<?php echo esc_attr( $ansp_row['guest'] ); ?>"
								/>
							</span>

							<span class="ansp-comp-field">
								<label class="ansp-comp-label" for="ansp-fix-email-<?php echo esc_attr( $ansp_row['order_id'] ); ?>">
									<?php esc_html_e( 'Their email', 'ans-singers-portal' ); ?>
								</label>
								<input
									type="email"
									id="ansp-fix-email-<?php echo esc_attr( $ansp_row['order_id'] ); ?>"
									name="guest_email"
									value="<?php echo esc_attr( $ansp_row['email'] ); ?>"
								/>
							</span>

							<button type="submit" class="ansp-btn ansp-btn--small">
								<?php esc_html_e( 'Save', 'ans-singers-portal' ); ?>
							</button>

							<p class="ansp-ledger-hint">
								<?php esc_html_e( 'Change the email and the ticket is sent to the new address straight away.', 'ans-singers-portal' ); ?>
							</p>
						</form>
					</details>

				</div>

			</li>
		<?php endforeach; ?>
	</ul>

</section>
