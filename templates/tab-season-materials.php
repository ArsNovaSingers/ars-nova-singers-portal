<?php
/**
 * Season Materials tab.
 *
 * Shows the current season's brief link, then a "Projects" sub-tab menu:
 * one sub-tab per non-archived project the viewer can see (titles pulled
 * from the ans_project posts). Selecting a project reveals ALL its
 * materials (v1.2.0: materials are no longer gated per-user) as a plain
 * LIST (v1.14.0 — inline previews removed), plus a "Filter by tag" control
 * built from the union of the project's effective tags, and a selection
 * toolbar that packages the chosen materials into one .zip.
 * (The RSVP form is built but hidden — see below.)
 *
 * The list sits inside a form posting to admin-post.php. The toolbar's
 * Select all / Select none only ever touch rows the tag filter is currently
 * showing, and assets/portal.js clears the checkbox of any row the filter
 * hides — a hidden checkbox is still submitted by the browser, so unticking
 * it is what stops a filtered-out material arriving in the archive anyway.
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
$ansp_group_term = null;
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
if ( $ansp_group_term instanceof WP_Term ) {
	/*
	 * This group's projects, AND those of anything nested under it. A tab
	 * belongs to a top-level group, so an "Ensemble Singers" project has to
	 * surface inside the "Ars Nova Singers" tab or nesting means nothing.
	 *
	 * By term_id, not slug: include_children is dependable on IDs, and this
	 * is not a place to hope WordPress resolves it the way we assumed.
	 *
	 * Chamber Singers still never appears in a full-choir tab — it is a
	 * separate top-level tree, not a descendant.
	 */
	$ansp_tax[] = array(
		'taxonomy'         => 'ans_group',
		'field'            => 'term_id',
		'terms'            => (int) $ansp_group_term->term_id,
		'include_children' => true,
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

					// Is anything here actually downloadable? A project of pure
					// YouTube links should not grow a zip button that can only
					// ever refuse.
					$ansp_any_zippable = false;
					foreach ( $ansp_materials as $ansp_material ) {
						if ( ! empty( $ansp_material['id'] ) && ! empty( $ansp_material['url'] ) && ANSP_Materials_Zip::is_zippable( $ansp_material['url'] ) ) {
							$ansp_any_zippable = true;
							break;
						}
					}
					?>
					<div class="ansp-materials-wrap" data-ansp-material-filter-scope>
						<?php if ( ! empty( $ansp_tag_union ) ) : ?>
							<fieldset class="ansp-tagfilter" data-ansp-tagfilter>
								<legend class="ansp-tagfilter-legend"><?php esc_html_e( 'Filter by tag', 'ans-singers-portal' ); ?></legend>
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

						<form class="ansp-materials-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Materials_Zip::ACTION_ZIP ); ?>" />
							<input type="hidden" name="project_id" value="<?php echo esc_attr( (string) $ansp_pid ); ?>" />
							<?php wp_nonce_field( 'ansp_zip_' . $ansp_pid, 'ansp_zip_nonce' ); ?>

							<?php if ( $ansp_any_zippable ) : ?>
								<div class="ansp-matbar" data-ansp-matbar>
									<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-select-all><?php esc_html_e( 'Select all', 'ans-singers-portal' ); ?></button>
									<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-select-none><?php esc_html_e( 'Select none', 'ans-singers-portal' ); ?></button>
									<button type="submit" class="ansp-btn ansp-btn--small" data-ansp-zip-submit disabled><?php esc_html_e( 'Download selected (.zip)', 'ans-singers-portal' ); ?></button>
									<span class="ansp-matbar-count" data-ansp-select-count aria-live="polite"></span>
								</div>
							<?php endif; ?>

							<?php
							ansp_get_template(
								'materials-list',
								array(
									'materials'  => $ansp_materials,
									'project_id' => $ansp_pid,
								)
							);
							?>
						</form>

						<p class="ansp-empty ansp-tagfilter-empty" data-ansp-tagfilter-empty hidden><?php esc_html_e( 'No materials match the selected tags.', 'ans-singers-portal' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php $ansp_first = false; ?>
		<?php endforeach; ?>
	</div>

	<?php
	/*
	 * The bulk-sync address, once per tab rather than once per project.
	 *
	 * A tab is one group and the mirror holds one folder per group, so
	 * repeating this under every project would be the same address printed
	 * four times. It sits below the projects deliberately: nearly everyone
	 * taps a file and reads it, and this must not be what a singer scrolls
	 * past to reach their music.
	 *
	 * Which folder it points at is read from the projects themselves, not
	 * from this tab's WordPress group slug — those are different names and
	 * assuming otherwise is what made v1.15.0 find nothing at all.
	 */
	$ansp_dav_panel = class_exists( 'ANSP_Dav' ) ? ANSP_Dav::panel_for( $ansp_projects ) : null;
	if ( is_array( $ansp_dav_panel ) ) {
		// The key is the variable name: ansp_get_template() extracts $args
		// verbatim, with no prefix added.
		ansp_get_template( 'dav-panel', array( 'ansp_dav' => $ansp_dav_panel ) );
	}
	?>
<?php endif; ?>
