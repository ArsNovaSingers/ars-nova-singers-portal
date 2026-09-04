<?php
/**
 * Calendar tab: Google Calendar embeds for the viewer's group(s) with
 * one-click Subscribe (Google / iCal) links.
 *
 * ⚠️ NO LONGER ROUTED TO as of 1.32.0. Calendars moved inside the ensemble
 * tab they belong to — templates/group-calendar.php renders one calendar,
 * chosen by the tab it sits in. portal.php no longer registers a `calendar`
 * tab, so nothing includes this file.
 *
 * Kept rather than deleted, on the same footing as tab-past-projects.php:
 * restoring the old behaviour is re-adding one line to the $ansp_tabs array,
 * and ANSP_Calendar::get_calendars_for_user() — which this uses — is still
 * live and still correct. Delete both together if the multi-calendar view is
 * ever formally dropped.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_calendars = ANSP_Calendar::get_calendars_for_user( get_current_user_id() );
?>
<h3 class="ansp-section-title"><?php esc_html_e( 'Calendar', 'ans-singers-portal' ); ?></h3>

<?php if ( empty( $ansp_calendars ) ) : ?>
	<p class="ansp-empty"><?php esc_html_e( 'No calendar has been set up for your group yet.', 'ans-singers-portal' ); ?></p>
<?php else : ?>
	<?php foreach ( $ansp_calendars as $ansp_cal ) : ?>
		<section class="ansp-calendar-block">
			<div class="ansp-calendar-head">
				<h4 class="ansp-calendar-title"><?php echo esc_html( $ansp_cal['label'] ); ?></h4>
				<p class="ansp-calendar-subscribe">
					<a class="ansp-btn ansp-btn--small" href="<?php echo esc_url( ANSP_Calendar::google_subscribe_url( $ansp_cal['id'] ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Add to Google Calendar', 'ans-singers-portal' ); ?>
					</a>
					<a class="ansp-btn ansp-btn--small ansp-btn--ghost" href="<?php echo esc_url( ANSP_Calendar::ical_url( $ansp_cal['id'] ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Subscribe (iCal)', 'ans-singers-portal' ); ?>
					</a>
				</p>
			</div>
			<div class="ansp-embed ansp-embed--calendar">
				<iframe
					src="<?php echo esc_url( ANSP_Calendar::embed_url( $ansp_cal['id'] ) ); ?>"
					title="<?php echo esc_attr( $ansp_cal['label'] ); ?>"
					frameborder="0"
					scrolling="no"
					loading="lazy"
				></iframe>
			</div>
		</section>
	<?php endforeach; ?>
<?php endif; ?>
