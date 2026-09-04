<?php
/**
 * One ensemble's calendar, rendered inside that ensemble's own tab.
 *
 * The predecessor (tab-calendar.php) looped over every calendar the viewer
 * could see and stacked them in a single Calendar tab. That was the right
 * shape when calendars lived on their own; it is the wrong one now, because
 * a singer in two ensembles had to work out which of two embeds applied to
 * the music they were looking at. Here the answer is structural: the
 * calendar is inside the tab, so it can only be that group's.
 *
 * Rendered only when the group has a calendar configured — tab-group.php
 * omits the sub-tab entirely otherwise — so this template has no empty state
 * of its own beyond a defensive guard.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! isset( $ansp_calendar ) || ! is_array( $ansp_calendar ) || empty( $ansp_calendar['id'] ) ) {
	return;
}
?>
<section class="ansp-calendar-block">
	<div class="ansp-calendar-head">
		<p class="ansp-calendar-subscribe">
			<a class="ansp-btn ansp-btn--small" href="<?php echo esc_url( ANSP_Calendar::google_subscribe_url( $ansp_calendar['id'] ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Add to Google Calendar', 'ans-singers-portal' ); ?>
			</a>
			<a class="ansp-btn ansp-btn--small ansp-btn--ghost" href="<?php echo esc_url( ANSP_Calendar::ical_url( $ansp_calendar['id'] ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Subscribe (iCal)', 'ans-singers-portal' ); ?>
			</a>
		</p>
	</div>
	<div class="ansp-embed ansp-embed--calendar">
		<iframe
			src="<?php echo esc_url( ANSP_Calendar::embed_url( $ansp_calendar['id'] ) ); ?>"
			title="<?php echo esc_attr( $ansp_calendar['label'] ); ?>"
			frameborder="0"
			scrolling="no"
			loading="lazy"
		></iframe>
	</div>
</section>
