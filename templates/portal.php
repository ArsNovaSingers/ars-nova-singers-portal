<?php
/**
 * Portal shell: accessible, mobile-first tabbed interface.
 *
 * Tabs: Home/Announcements · My Bio · Roster · Calendar ·
 * Season Materials. (Past Projects is built but hidden.) Tab switching is handled by
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

	/*
	 * Past Projects — HIDDEN 2026-08-20 at Jonathan's request.
	 * Nothing was removed: templates/tab-past-projects.php is intact and the
	 * "Archived" project status still works in wp-admin. Uncomment this one
	 * line (or hook the filter below) to bring the tab back for singers.
	 */
	// 'past-projects'    => __( 'Past Projects', 'ans-singers-portal' ),
);

/*
 * One Materials tab per group the viewer is in.
 *
 * A singer in the full choir sees one tab and never learns Chamber Singers
 * exists. Someone in both gets both — which is the real shape of the thing,
 * since the two ensembles rehearse separately and their music has always
 * lived in different places.
 *
 * Labels come from the group's own name, so renaming a group in wp-admin
 * renames the tab. Nothing here hardcodes an ensemble.
 *
 * A viewer with no group at all still gets one unscoped Materials tab, so a
 * singer who registers before anyone assigns them still sees whatever is
 * shared with everyone rather than an empty portal.
 */
/*
 * Which portal this is — and, since 1.21.0, the ONLY heading on the page.
 *
 * The WordPress page was titled "Singers Portal" and this line said the same
 * thing, so the top of the page spent two headings and most of the first
 * screen saying it twice. The theme's page title is now hidden on /portal/
 * (Kadence per-page `_kad_post_title` = `hide`, set on that page alone) and
 * this is what a singer reads.
 *
 * That is why the default is the organisation's name rather than the
 * audience's: it is the page's title now, not a subtitle under one. Board
 * members still get their own name here, and their own tab set via the
 * ansp_portal_tabs filter, rather than a second page to maintain — which is
 * the reason this stays a variable instead of becoming static markup.
 */
$ansp_portal_name = __( 'Ars Nova Portal', 'ans-singers-portal' );
if ( in_array( 'ans_board', (array) $ansp_user->roles, true ) ) {
	$ansp_portal_name = __( 'Board Portal', 'ans-singers-portal' );
}

/**
 * Filter the portal's heading — the page's only title.
 *
 * @param string  $ansp_portal_name Portal name.
 * @param WP_User $ansp_user        Current user.
 */
$ansp_portal_name = apply_filters( 'ansp_portal_name', $ansp_portal_name, $ansp_user );

$ansp_groups = ANSP_Permissions::get_visible_groups( $ansp_user->ID );

if ( empty( $ansp_groups ) ) {
	$ansp_tabs['season-materials'] = __( 'Season Materials', 'ans-singers-portal' );
} else {
	foreach ( $ansp_groups as $ansp_group ) {
		$ansp_tabs[ 'materials-' . $ansp_group->slug ] = $ansp_group->name;
	}
}

/**
 * Filter the singer-facing portal tabs.
 *
 * @param array<string,string> $ansp_tabs tab id => label.
 */
$ansp_tabs = apply_filters( 'ansp_portal_tabs', $ansp_tabs );
?>
<div class="ansp-portal" id="ansp-portal">

	<header class="ansp-portal-header">
		<?php
		/*
		 * <h1>, not <h2>. With the theme's page title hidden this is the only
		 * heading on the page, and a page whose top-level heading is an <h2>
		 * reads to a screen reader as a document that starts mid-outline.
		 *
		 * ⚠️ This is coupled to the Kadence per-page setting. Installing this
		 * plugin version somewhere the page title is still shown gives you two
		 * <h1>s and the duplicate title back. When this reaches LIVE, set
		 * `_kad_post_title` to `hide` on the /portal/ page there too.
		 */
		?>
		<h1 class="ansp-portal-title"><?php echo esc_html( $ansp_portal_name ); ?></h1>
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
			<?php
			/*
			 * materials-<group-slug> tabs all render the same template,
			 * scoped to their group. Everything else maps to tab-<id>.php.
			 */
			if ( 0 === strpos( $ansp_tab_id, 'materials-' ) ) {
				ansp_get_template(
					'tab-season-materials',
					array( 'ansp_group_slug' => substr( $ansp_tab_id, strlen( 'materials-' ) ) )
				);
			} else {
				ansp_get_template( 'tab-' . $ansp_tab_id );
			}
			?>
		</section>
		<?php $ansp_first = false; ?>
	<?php endforeach; ?>

</div>
