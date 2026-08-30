<?php
/**
 * Comp Tickets tab: a singer claims the complimentary tickets Kim allowed them
 * for a project.
 *
 * Rendered by convention from templates/portal.php - the tab id is `comps`, so
 * this file is loaded with no change to the portal shell at all.
 *
 * One card per project the singer has an unspent allowance on. The count is
 * stated before the picker rather than after it, because "you have 1 left" is
 * the thing that decides whether they read the rest of the card.
 *
 * The form posts to admin-post.php rather than back to the portal page. A comp
 * issues a real WooCommerce order and generates a real scannable ticket; that
 * must not run inside a page render where output has already started and a
 * redirect is no longer possible.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_claimable = ANSP_Comp_Claim::get_claimable( get_current_user_id() );
$ansp_notice    = ANSP_Comp_Claim::get_notice();
?>

<h3 class="ansp-section-title"><?php esc_html_e( 'Comp Tickets', 'ans-singers-portal' ); ?></h3>

<?php if ( $ansp_notice ) : ?>
	<div class="ansp-notice ansp-notice--<?php echo esc_attr( 'success' === $ansp_notice['type'] ? 'success' : 'error' ); ?>">
		<?php echo esc_html( $ansp_notice['text'] ); ?>
	</div>
<?php endif; ?>

<?php if ( empty( $ansp_claimable ) ) : ?>

	<p class="ansp-empty">
		<?php esc_html_e( 'You have no comp tickets to claim right now. Allowances are set per project, and only upcoming performances can be claimed.', 'ans-singers-portal' ); ?>
	</p>

<?php else : ?>

	<p class="ansp-comp-intro">
		<?php esc_html_e( 'Your ticket is emailed to you as a PDF as soon as you claim it. Claim one seat at a time.', 'ans-singers-portal' ); ?>
	</p>

	<?php foreach ( $ansp_claimable as $ansp_p ) : ?>
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
					<?php esc_html_e( 'You have claimed all of your comps for this project.', 'ans-singers-portal' ); ?>
				</p>

			<?php else : ?>

				<form
					class="ansp-comp-form"
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Comp_Claim::ACTION ); ?>" />
					<input type="hidden" name="project_id" value="<?php echo esc_attr( $ansp_p['project_id'] ); ?>" />
					<?php wp_nonce_field( ANSP_Comp_Claim::NONCE ); ?>

					<label class="ansp-comp-label" for="ansp-comp-event-<?php echo esc_attr( $ansp_p['project_id'] ); ?>">
						<?php esc_html_e( 'Which performance?', 'ans-singers-portal' ); ?>
					</label>

					<select
						class="ansp-comp-select"
						id="ansp-comp-event-<?php echo esc_attr( $ansp_p['project_id'] ); ?>"
						name="event_id"
						required
					>
						<?php foreach ( $ansp_p['performances'] as $ansp_perf ) : ?>
							<option value="<?php echo esc_attr( $ansp_perf['id'] ); ?>">
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

					<button type="submit" class="ansp-btn ansp-comp-submit">
						<?php esc_html_e( 'Claim my ticket', 'ans-singers-portal' ); ?>
					</button>
				</form>

			<?php endif; ?>

		</section>
	<?php endforeach; ?>

<?php endif; ?>
