<?php
/**
 * Past Projects tab: archived projects the viewer could see, read-only
 * (materials still viewable, no RSVP form).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_viewer_id = get_current_user_id();

$ansp_query = new WP_Query(
	array(
		'post_type'      => ANSP_CPT::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => 'ansp_project_status',
				'value' => 'archived',
			),
		),
	)
);

$ansp_projects = array();
foreach ( $ansp_query->posts as $ansp_maybe ) {
	if ( ansp_user_can_see( $ansp_maybe, $ansp_viewer_id ) ) {
		$ansp_projects[] = $ansp_maybe;
	}
}
?>
<h3 class="ansp-section-title"><?php esc_html_e( 'Past Projects', 'ans-singers-portal' ); ?></h3>

<?php if ( empty( $ansp_projects ) ) : ?>
	<p class="ansp-empty"><?php esc_html_e( 'No archived projects yet.', 'ans-singers-portal' ); ?></p>
<?php else : ?>
	<div class="ansp-past-projects">
		<?php foreach ( $ansp_projects as $ansp_project ) : ?>
			<?php
			$ansp_pid       = (int) $ansp_project->ID;
			$ansp_materials = ANSP_Permissions::get_visible_materials( $ansp_pid, $ansp_viewer_id );
			$ansp_start     = ANSP_Project_Meta::get( $ansp_pid, 'date_start' );
			$ansp_venue     = ANSP_Project_Meta::get( $ansp_pid, 'venue' );
			$ansp_seasons   = wp_get_object_terms( $ansp_pid, 'ans_season' );
			$ansp_seasons   = is_wp_error( $ansp_seasons ) ? array() : $ansp_seasons;
			?>
			<details class="ansp-past-project">
				<summary>
					<span class="ansp-past-title"><?php echo esc_html( get_the_title( $ansp_project ) ); ?></span>
					<span class="ansp-past-meta">
						<?php if ( ! empty( $ansp_seasons ) ) : ?>
							<span class="ansp-badge"><?php echo esc_html( $ansp_seasons[0]->name ); ?></span>
						<?php endif; ?>
						<?php if ( $ansp_start ) : ?>
							<span><?php echo esc_html( $ansp_start ); ?></span>
						<?php endif; ?>
						<?php if ( $ansp_venue ) : ?>
							<span><?php echo esc_html( $ansp_venue ); ?></span>
						<?php endif; ?>
					</span>
				</summary>
				<?php if ( empty( $ansp_materials ) ) : ?>
					<p class="ansp-empty"><?php esc_html_e( 'No materials for this project.', 'ans-singers-portal' ); ?></p>
				<?php else : ?>
					<?php
					ansp_get_template(
						'materials-list',
						array(
							'materials'  => $ansp_materials,
							'project_id' => $ansp_pid,
							// Read-only archive: no checkbox, no bulk zip.
							'selectable' => false,
							'list_class' => 'ansp-materials--noselect',
						)
					);
					?>
				<?php endif; ?>
			</details>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
