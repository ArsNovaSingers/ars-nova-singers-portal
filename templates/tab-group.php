<?php
/**
 * One ensemble's tab: This Week's Assignments · Program Materials · Calendar.
 *
 * WHY THIS FILE EXISTS. Before 1.32.0 a group's tab rendered
 * tab-season-materials.php directly, and the calendar lived in a tab of its
 * own alongside Home and Roster. That put a singer's two most time-sensitive
 * questions — "what am I meant to have prepared?" and "when do we rehearse?"
 * — in two different places, neither of them beside the music.
 *
 * Jonathan, 2026-09-03: "The calendars for any group I am part of need to be
 * IN the main group tab not its own separate tab."
 *
 * So the group tab is now a container of three sub-tabs and this template is
 * that container. It deliberately does NOT reimplement the materials list:
 * tab-season-materials.php is 13 KB of project querying, tag filtering, zip
 * selection and mirror merging that works, and rewriting it to add a wrapper
 * would risk all of it for none of the benefit. It is included whole.
 *
 * ⚠️ NESTED SUB-TABS. tab-season-materials.php already uses
 * [data-ansp-subtabs] for its per-project switcher, so this container nests
 * one sub-tab group inside another. assets/portal.js was changed in the same
 * release to resolve a button against its NEAREST [data-ansp-subtabs]
 * ancestor; before that it matched every descendant, so clicking a project
 * would also have reshuffled these three. If you add a third level, check
 * that handler again.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_group_slug = isset( $ansp_group_slug ) ? sanitize_key( (string) $ansp_group_slug ) : '';

$ansp_group_name = '';
if ( '' !== $ansp_group_slug && taxonomy_exists( 'ans_group' ) ) {
	$ansp_gterm = get_term_by( 'slug', $ansp_group_slug, 'ans_group' );
	if ( $ansp_gterm instanceof WP_Term ) {
		$ansp_group_name = $ansp_gterm->name;
	}
}

/*
 * The Calendar sub-tab is offered only when this ensemble actually has one.
 * An empty tab that exists to say "nothing here" is worse than no tab: it
 * reads as a broken feature rather than an unset option.
 */
$ansp_group_calendar = class_exists( 'ANSP_Calendar' )
	? ANSP_Calendar::get_calendar_for_group( $ansp_group_slug )
	: null;

$ansp_sub = array(
	'assignments' => __( "This Week's Assignments", 'ans-singers-portal' ),
	'materials'   => __( 'Program Materials', 'ans-singers-portal' ),
);
if ( is_array( $ansp_group_calendar ) ) {
	$ansp_sub['calendar'] = __( 'Calendar', 'ans-singers-portal' );
}

$ansp_base = 'group-' . ( '' !== $ansp_group_slug ? $ansp_group_slug : 'all' );
?>
<div class="ansp-group-tab ansp-subtabs ansp-subtabs--level1" data-ansp-subtabs>

	<div class="ansp-subtab-nav ansp-subtab-nav--level1" role="tablist" aria-label="
		<?php
		echo esc_attr(
			'' !== $ansp_group_name
				/* translators: %s: ensemble name */
				? sprintf( __( '%s sections', 'ans-singers-portal' ), $ansp_group_name )
				: __( 'Group sections', 'ans-singers-portal' )
		);
		?>
	">
		<?php $ansp_first = true; ?>
		<?php foreach ( $ansp_sub as $ansp_key => $ansp_label ) : ?>
			<button
				type="button"
				class="ansp-subtab ansp-subtab--level1<?php echo $ansp_first ? ' is-active' : ''; ?>"
				role="tab"
				data-ansp-subtab="<?php echo esc_attr( $ansp_base . '-' . $ansp_key ); ?>"
				aria-selected="<?php echo $ansp_first ? 'true' : 'false'; ?>"
			><?php echo esc_html( $ansp_label ); ?></button>
			<?php $ansp_first = false; ?>
		<?php endforeach; ?>
	</div>

	<?php $ansp_first = true; ?>
	<?php foreach ( array_keys( $ansp_sub ) as $ansp_key ) : ?>
		<div
			class="ansp-subtab-panel ansp-subtab-panel--level1<?php echo $ansp_first ? ' is-active' : ''; ?>"
			data-ansp-subpanel="<?php echo esc_attr( $ansp_base . '-' . $ansp_key ); ?>"
			<?php echo $ansp_first ? '' : 'hidden'; ?>
		>
			<?php
			if ( 'assignments' === $ansp_key ) {
				ansp_get_template(
					'group-assignments',
					array(
						'ansp_group_slug' => $ansp_group_slug,
						'ansp_group_name' => $ansp_group_name,
					)
				);
			} elseif ( 'materials' === $ansp_key ) {
				ansp_get_template(
					'tab-season-materials',
					array( 'ansp_group_slug' => $ansp_group_slug )
				);
			} else {
				ansp_get_template(
					'group-calendar',
					array( 'ansp_calendar' => $ansp_group_calendar )
				);
			}
			?>
		</div>
		<?php $ansp_first = false; ?>
	<?php endforeach; ?>

</div>
