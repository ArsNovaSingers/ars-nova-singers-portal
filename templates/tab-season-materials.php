<?php
/**
 * Season Materials tab.
 *
 * Shows the current season's brief link, then a "Projects" sub-tab menu:
 * one sub-tab per non-archived project the viewer can see (titles pulled
 * from the ans_project posts). Selecting a project reveals ALL its
 * materials (v1.2.0: materials are no longer gated per-user) rendered by
 * material-item.php with inline previews, plus a "Filter by tag" control
 * built from the union of the project's effective tags. (The RSVP form is
 * built but hidden — see below.)
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_viewer_id = get_current_user_id();
$ansp_season    = ANSP_Taxonomies::get_current_season();

/*
 * Which group's materials this tab shows.
 *
 * Passed in by portal.php as `ansp_group_slug` for each materials-<slug>
 * tab. Empty means "don't scope by group" — the fallback for a viewer with
 * no group assigned, who should still see whatever is shared with everyone
 * rather than an empty portal.
 */
$ansp_group_slug = isset( $ansp_group_slug ) ? sanitize_key( (string) $ansp_group_slug ) : '';
$ansp_group      = '';
if ( '' !== $ansp_group_slug && taxonomy_exists( 'ans_group' ) ) {
	$ansp_group_term = get_term_by( 'slug', $ansp_group_slug, 'ans_group' );
	if ( $ansp_group_term instanceof WP_Term ) {
		$ansp_group = $ansp_group_term->name;
	}
}

// ---- Query current-season, non-archived projects --------------------------
$ansp_args = array(
	'post_type'      => ANSP_CPT::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'menu_order title',
	'order'          => 'ASC',
	'no_found_rows'  => true,
	'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'relation' => 'OR',
		array(
			'key'     => 'ansp_project_status',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => 'ansp_project_status',
			'value'   => 'archived',
			'compare' => '!=',
		),
	),
);
$ansp_tax = array();
if ( $ansp_season instanceof WP_Term ) {
	$ansp_tax[] = array(
		'taxonomy' => 'ans_season',
		'field'    => 'term_id',
		'terms'    => (int) $ansp_season->term_id,
	);
}
if ( '' !== $ansp_group_slug ) {
	// Only this group's projects. A Chamber Singers tab must never show a
	// full-choir project, and vice versa — that separation is the reason
	// these are two tabs rather than one list.
	$ansp_tax[] = array(
		'taxonomy' => 'ans_group',
		'field'    => 'slug',
		'terms'    => $ansp_group_slug,
	);
}
if ( ! empty( $ansp_tax ) ) {
	$ansp_tax['relation']      = 'AND';
	$ansp_args['tax_query']    = $ansp_tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
}

$ansp_query    = new WP_Query( $ansp_args );
$ansp_projects = array();
foreach ( $ansp_query->posts as $ansp_maybe ) {
	if ( ansp_user_can_see( $ansp_maybe, $ansp_viewer_id ) ) {
		$ansp_projects[] = $ansp_maybe;
	}
}
?>
<h3 class="ansp-section-title">
	<?php
	if ( '' !== $ansp_group && $ansp_season instanceof WP_Term ) {
		/* translators: 1: group name, 2: season name */
		printf( esc_html__( '%1$s — %2$s', 'ans-singers-portal' ), esc_html( $ansp_group ), esc_html( $ansp_season->name ) );
	} elseif ( '' !== $ansp_group ) {
		printf( esc_html__( '%s — Materials', 'ans-singers-portal' ), esc_html( $ansp_group ) );
	} elseif ( $ansp_season instanceof WP_Term ) {
		/* translators: %s: season name */
		printf( esc_html__( 'Season Materials — %s', 'ans-singers-portal' ), esc_html( $ansp_season->name ) );
	} else {
		esc_html_e( 'Season Materials', 'ans-singers-portal' );
	}
	?>
</h3>

<?php
if ( $ansp_season instanceof WP_Term ) {
	$ansp_brief = ANSP_Taxonomies::get_season_brief_url( $ansp_season->term_id );
	if ( $ansp_brief ) :
		?>
		<p class="ansp-season-brief">
			<a class="ansp-btn ansp-btn--ghost" href="<?php echo esc_url( $ansp_brief ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open the Season Brief', 'ans-singers-portal' ); ?>
			</a>
		</p>
		<?php
	endif;
}
?>

<?php if ( empty( $ansp_projects ) ) : ?>
	<p class="ansp-empty">
		<?php
		if ( '' !== $ansp_group ) {
			/* translators: %s: group name */
			printf( esc_html__( 'Nothing has been posted for %s this season yet.', 'ans-singers-portal' ), esc_html( $ansp_group ) );
		} else {
			esc_html_e( 'No projects are available to you this season yet.', 'ans-singers-portal' );
		}
		?>
	</p>
<?php else : ?>
	<div class="ansp-subtabs" data-ansp-subtabs>
		<div class="ansp-subtab-nav" role="tablist" aria-label="<?php esc_attr_e( 'Projects', 'ans-singers-portal' ); ?>">
			<?php $ansp_first = true; ?>
			<?php foreach ( $ansp_projects as $ansp_project ) : ?>
				<button
					type="button"
					class="ansp-subtab<?php echo $ansp_first ? ' is-active' : ''; ?>"
					role="tab"
					data-ansp-subtab="project-<?php echo esc_attr( $ansp_group_slug . '-' . (string) $ansp_project->ID ); ?>"
					aria-selected="<?php echo $ansp_first ? 'true' : 'false'; ?>"
				><?php echo esc_html( get_the_title( $ansp_project ) ); ?></button>
				<?php $ansp_first = false; ?>
			<?php endforeach; ?>
		</div>

		<?php $ansp_first = true; ?>
		<?php foreach ( $ansp_projects as $ansp_project ) : ?>
			<?php
			$ansp_pid       = (int) $ansp_project->ID;
			$ansp_materials = ANSP_Permissions::get_visible_materials( $ansp_pid, $ansp_viewer_id );
			$ansp_start     = ANSP_Project_Meta::get( $ansp_pid, 'date_start' );
			$ansp_end       = ANSP_Project_Meta::get( $ansp_pid, 'date_end' );
			$ansp_venue     = ANSP_Project_Meta::get( $ansp_pid, 'venue' );
			$ansp_desc      = ANSP_Project_Meta::get( $ansp_pid, 'description' );
			$ansp_brief_url = ANSP_Project_Meta::get( $ansp_pid, 'brief_url' );
			?>
			<div
				class="ansp-subtab-panel<?php echo $ansp_first ? ' is-active' : ''; ?>"
				data-ansp-subpanel="project-<?php echo esc_attr( $ansp_group_slug . '-' . (string) $ansp_pid ); ?>"
				role="tabpanel"
				<?php echo $ansp_first ? '' : 'hidden'; ?>
			>
				<div class="ansp-project-meta">
					<?php if ( $ansp_start || $ansp_end ) : ?>
						<span class="ansp-project-dates">
							<?php
							if ( $ansp_start && $ansp_end && $ansp_start !== $ansp_end ) {
								/* translators: 1: start date, 2: end date */
								printf( esc_html__( '%1$s – %2$s', 'ans-singers-portal' ), esc_html( $ansp_start ), esc_html( $ansp_end ) );
							} else {
								echo esc_html( $ansp_start ? $ansp_start : $ansp_end );
							}
							?>
						</span>
					<?php endif; ?>
					<?php if ( $ansp_venue ) : ?>
						<span class="ansp-project-venue"><?php echo esc_html( $ansp_venue ); ?></span>
					<?php endif; ?>
					<?php if ( $ansp_brief_url ) : ?>
						<a class="ansp-btn ansp-btn--small ansp-btn--ghost" href="<?php echo esc_url( $ansp_brief_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Project brief', 'ans-singers-portal' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( $ansp_desc ) : ?>
					<div class="ansp-project-description"><?php echo wp_kses_post( wpautop( $ansp_desc ) ); ?></div>
				<?php endif; ?>

				<?php
				/*
				 * RSVP form — HIDDEN 2026-08-20 at Jonathan's request. Materials is
				 * for getting your music, not for answering a question. The whole
				 * RSVP feature is intact (includes/class-ansp-rsvp.php, saved
				 * responses, the admin view) — only this one call is commented out,
				 * so nothing already answered is lost. Filter below to re-enable.
				 */
				if ( apply_filters( 'ansp_show_project_rsvp', false, $ansp_pid ) ) {
					ANSP_RSVP::render_form( $ansp_pid );
				}
				?>

				<?php if ( empty( $ansp_materials ) ) : ?>
					<p class="ansp-empty"><?php esc_html_e( 'No materials for this project yet.', 'ans-singers-portal' ); ?></p>
				<?php else : ?>
					<?php
					// Union of effective tags (manual tags + auto content-type)
					// across this project's materials, deduped case-insensitively.
					$ansp_tag_union = array();
					$ansp_tag_seen  = array();
					foreach ( $ansp_materials as $ansp_material ) {
						foreach ( ANSP_Materials::effective_tags( $ansp_material ) as $ansp_tag ) {
							$ansp_tag_key = strtolower( $ansp_tag );
							if ( ! isset( $ansp_tag_seen[ $ansp_tag_key ] ) ) {
								$ansp_tag_seen[ $ansp_tag_key ] = true;
								$ansp_tag_union[]               = $ansp_tag;
							}
						}
					}
					natcasesort( $ansp_tag_union );
					$ansp_tag_union = array_values( $ansp_tag_union );
					?>
					<div class="ansp-materials-wrap" data-ansp-material-filter-scope>
						<?php if ( ! empty( $ansp_tag_union ) ) : ?>
							<fieldset class="ansp-tagfilter" data-ansp-tagfilter>
								<legend class="ansp-tagfilter-legend"><?php esc_html_e( 'Filter by tag', 'ans-singers-portal' ); ?></legend>
								<div class="ansp-tagfilter-bulk">
									<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-tagfilter-all><?php esc_html_e( 'All', 'ans-singers-portal' ); ?></button>
									<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-tagfilter-none><?php esc_html_e( 'None', 'ans-singers-portal' ); ?></button>
								</div>
								<div class="ansp-tagfilter-options">
									<?php foreach ( $ansp_tag_union as $ansp_tag ) : ?>
										<label class="ansp-tagfilter-option">
											<input
												type="checkbox"
												checked
												data-ansp-tagfilter-tag="<?php echo esc_attr( strtolower( $ansp_tag ) ); ?>"
											/>
											<span><?php echo esc_html( $ansp_tag ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endif; ?>
						<div class="ansp-materials" data-ansp-materials>
							<?php
							foreach ( $ansp_materials as $ansp_material ) {
								ansp_get_template(
									'material-item',
									array(
										'material'   => $ansp_material,
										'project_id' => $ansp_pid,
									)
								);
							}
							?>
						</div>
						<p class="ansp-empty ansp-tagfilter-empty" data-ansp-tagfilter-empty hidden><?php esc_html_e( 'No materials match the selected tags.', 'ans-singers-portal' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php $ansp_first = false; ?>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
