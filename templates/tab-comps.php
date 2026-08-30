<?php
/**
 * Comp Tickets tab: a singer issues the complimentary tickets Kim allowed them
 * for a project - to named guests, in one go.
 *
 * Rendered by convention from templates/portal.php - the tab id is `comps`, so
 * this file is loaded with no change to the portal shell at all.
 *
 * WHY A CART RATHER THAN A CLAIM BUTTON. 1.27.0 shipped one button per project
 * that issued a single ticket to the singer's own address. Jonathan's words on
 * using it: "having to repeat the process of selecting and claiming a ticket
 * one ticket at a time is dumb." A singer with two comps is usually inviting
 * two different people, often to different nights, and the person going is not
 * the singer.
 *
 * Each ROW is one guest at one performance, and each row becomes its OWN comp
 * order. That is deliberate rather than one order with several lines: void,
 * resend and check-in all operate per order, so one order per guest is what
 * makes "resend Sarah's ticket" a thing that can exist.
 *
 * The rows are validated as a SET before anything is issued - a bad email in
 * row three must not leave rows one and two already sent.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_claimable = ANSP_Comp_Claim::get_claimable( get_current_user_id() );
$ansp_notice    = ANSP_Comp_Claim::get_notice();
$ansp_old       = ANSP_Comp_Claim::get_returned_rows();
?>

<h3 class="ansp-section-title"><?php esc_html_e( 'Comp Tickets', 'ans-singers-portal' ); ?></h3>

<?php if ( $ansp_notice ) : ?>
	<div class="ansp-notice ansp-notice--<?php echo esc_attr( 'success' === $ansp_notice['type'] ? 'success' : 'error' ); ?>">
		<?php echo esc_html( $ansp_notice['text'] ); ?>
	</div>
<?php endif; ?>

<?php if ( empty( $ansp_claimable ) ) : ?>

	<p class="ansp-empty">
		<?php esc_html_e( 'You have no comp tickets to issue right now. Allowances are set per project, and only upcoming performances can be used.', 'ans-singers-portal' ); ?>
	</p>

<?php else : ?>

	<?php foreach ( $ansp_claimable as $ansp_p ) : ?>
		<?php
		$ansp_pid  = (int) $ansp_p['project_id'];
		$ansp_rows = isset( $ansp_old[ $ansp_pid ] ) ? $ansp_old[ $ansp_pid ] : array();
		if ( empty( $ansp_rows ) ) {
			$ansp_rows = array( array( 'name' => '', 'email' => '', 'event_id' => 0, 'qty' => 1 ) );
		}
		?>
		<section class="ansp-comp-card">

			<header class="ansp-comp-head">
				<h4 class="ansp-comp-project"><?php echo esc_html( $ansp_p['project'] ); ?></h4>
				<p class="ansp-comp-count<?php echo $ansp_p['remaining'] < 1 ? ' is-spent' : ''; ?>">
					<?php
					printf(
						/* translators: 1: remaining count, 2: total allowance. */
						esc_html__( '%1$d of %2$d left', 'ans-singers-portal' ),
						(int) $ansp_p['remaining'],
						(int) $ansp_p['allowance']
					);
					?>
				</p>
			</header>

			<?php if ( ! empty( $ansp_p['note'] ) ) : ?>
				<p class="ansp-comp-note"><?php echo esc_html( $ansp_p['note'] ); ?></p>
			<?php endif; ?>

			<?php if ( $ansp_p['remaining'] < 1 ) : ?>

				<p class="ansp-comp-spent">
					<?php esc_html_e( 'You have used all of your comps for this project.', 'ans-singers-portal' ); ?>
				</p>

			<?php else : ?>

				<form
					class="ansp-comp-form"
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					data-ansp-comp-cart
					data-ansp-remaining="<?php echo esc_attr( $ansp_p['remaining'] ); ?>"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Comp_Claim::ACTION ); ?>" />
					<input type="hidden" name="project_id" value="<?php echo esc_attr( $ansp_pid ); ?>" />
					<?php wp_nonce_field( ANSP_Comp_Claim::NONCE ); ?>

					<p class="ansp-comp-intro">
						<?php esc_html_e( 'Who is coming? Each line is one guest at one performance. Their ticket is emailed straight to them.', 'ans-singers-portal' ); ?>
					</p>

					<ul class="ansp-comp-rows" data-ansp-comp-rows>
						<?php foreach ( $ansp_rows as $ansp_i => $ansp_row ) : ?>
							<li class="ansp-comp-row">
								<span class="ansp-comp-field ansp-comp-field--name">
									<label class="ansp-comp-label"><?php esc_html_e( 'Full name', 'ans-singers-portal' ); ?></label>
									<input type="text" name="guest_name[]" value="<?php echo esc_attr( $ansp_row['name'] ); ?>" autocomplete="off" />
								</span>

								<span class="ansp-comp-field ansp-comp-field--email">
									<label class="ansp-comp-label"><?php esc_html_e( 'Their email', 'ans-singers-portal' ); ?></label>
									<input type="email" name="guest_email[]" value="<?php echo esc_attr( $ansp_row['email'] ); ?>" autocomplete="off" />
								</span>

								<span class="ansp-comp-field ansp-comp-field--event">
									<label class="ansp-comp-label"><?php esc_html_e( 'Performance', 'ans-singers-portal' ); ?></label>
									<select name="guest_event[]">
										<?php foreach ( $ansp_p['performances'] as $ansp_perf ) : ?>
											<option
												value="<?php echo esc_attr( $ansp_perf['id'] ); ?>"
												<?php selected( (int) $ansp_row['event_id'], (int) $ansp_perf['id'] ); ?>
											>
												<?php
												$ansp_when = $ansp_perf['ts']
													? date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $ansp_perf['ts'] )
													: '';
												echo esc_html( $ansp_perf['title'] );
												if ( $ansp_when ) {
													echo ' — ' . esc_html( $ansp_when );
												}
												?>
											</option>
										<?php endforeach; ?>
									</select>
								</span>

								<span class="ansp-comp-field ansp-comp-field--qty">
									<label class="ansp-comp-label"><?php esc_html_e( 'Tickets', 'ans-singers-portal' ); ?></label>
									<input
										type="number"
										name="guest_qty[]"
										value="<?php echo esc_attr( max( 1, (int) $ansp_row['qty'] ) ); ?>"
										min="1"
										max="<?php echo esc_attr( $ansp_p['remaining'] ); ?>"
										inputmode="numeric"
										data-ansp-comp-qty
									/>
								</span>

								<span class="ansp-comp-field ansp-comp-field--remove">
									<button
										type="button"
										class="ansp-comp-remove"
										data-ansp-comp-remove
										aria-label="<?php esc_attr_e( 'Remove this guest', 'ans-singers-portal' ); ?>"
										title="<?php esc_attr_e( 'Remove this guest', 'ans-singers-portal' ); ?>"
									>&times;</button>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="ansp-comp-actions">
						<button type="button" class="ansp-btn ansp-btn--ghost ansp-btn--small" data-ansp-comp-add>
							<?php esc_html_e( '+ Add another guest', 'ans-singers-portal' ); ?>
						</button>

						<p class="ansp-comp-tally" data-ansp-comp-tally aria-live="polite"></p>

						<button type="submit" class="ansp-btn ansp-comp-submit">
							<?php esc_html_e( 'Issue comps', 'ans-singers-portal' ); ?>
						</button>
					</div>
				</form>

			<?php endif; ?>

		</section>
	<?php endforeach; ?>

<?php endif; ?>

<?php
/*
 * The "My Comps" ledger lands in the next step. Until then a singer sees what
 * they have left but not what they already sent, which is why this is a
 * deliberate note rather than a silence.
 */

