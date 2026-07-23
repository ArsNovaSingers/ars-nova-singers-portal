<?php
/**
 * Portal shell: accessible, mobile-first tabbed interface.
 *
 * Tabs: Home/Announcements · My Bio · Roster · Calendar ·
 * Season Materials · Past Projects. Tab switching is handled by
 * assets/portal.js (hash deep-linking, arrow-key navigation).
 *
 * Only ever rendered for logged-in users with portal access
 * (see ANSP_Portal::render_shortcode).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_user = wp_get_current_user();

$ansp_tabs = array(
	'home'             => __( 'Home', 'ans-singers-portal' ),
	'bio'              => __( 'My Bio', 'ans-singers-portal' ),
	'roster'           => __( 'Roster', 'ans-singers-portal' ),
	'calendar'         => __( 'Calendar', 'ans-singers-portal' ),
	'season-materials' => __( 'Season Materials', 'ans-singers-portal' ),
	'past-projects'    => __( 'Past Projects', 'ans-singers-portal' ),
);
?>
<div class="ansp-portal" id="ansp-portal">

	<header class="ansp-portal-header">
		<h2 class="ansp-portal-title"><?php esc_html_e( 'Singers Portal', 'ans-singers-portal' ); ?></h2>
		<p class="ansp-portal-welcome">
			<?php
			/* translators: %s: user display name */
			printf( esc_html__( 'Welcome, %s.', 'ans-singers-portal' ), esc_html( $ansp_user->display_name ) );
			?>
			<a class="ansp-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'ans-singers-portal' ); ?></a>
		</p>
	</header>

	<?php if ( isset( $_GET['ansp_rsvp'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['ansp_rsvp'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag. ?>
		<div class="ansp-notice ansp-notice--success"><?php esc_html_e( 'Your RSVP was saved.', 'ans-singers-portal' ); ?></div>
	<?php endif; ?>

	<nav class="ansp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Portal sections', 'ans-singers-portal' ); ?>">
		<?php $ansp_first = true; ?>
		<?php foreach ( $ansp_tabs as $ansp_tab_id => $ansp_tab_label ) : ?>
			<button
				type="button"
				class="ansp-tab<?php echo $ansp_first ? ' is-active' : ''; ?>"
				id="tab-btn-<?php echo esc_attr( $ansp_tab_id ); ?>"
				role="tab"
				data-ansp-tab="<?php echo esc_attr( $ansp_tab_id ); ?>"
				aria-selected="<?php echo $ansp_first ? 'true' : 'false'; ?>"
				aria-controls="tab-<?php echo esc_attr( $ansp_tab_id ); ?>"
				tabindex="<?php echo $ansp_first ? '0' : '-1'; ?>"
			><?php echo esc_html( $ansp_tab_label ); ?></button>
			<?php $ansp_first = false; ?>
		<?php endforeach; ?>
	</nav>

	<?php $ansp_first = true; ?>
	<?php foreach ( array_keys( $ansp_tabs ) as $ansp_tab_id ) : ?>
		<section
			class="ansp-tab-panel<?php echo $ansp_first ? ' is-active' : ''; ?>"
			id="tab-<?php echo esc_attr( $ansp_tab_id ); ?>"
			role="tabpanel"
			data-ansp-panel="<?php echo esc_attr( $ansp_tab_id ); ?>"
			aria-labelledby="tab-btn-<?php echo esc_attr( $ansp_tab_id ); ?>"
			<?php echo $ansp_first ? '' : 'hidden'; ?>
		>
			<?php ansp_get_template( 'tab-' . $ansp_tab_id ); ?>
		</section>
		<?php $ansp_first = false; ?>
	<?php endforeach; ?>

</div>
