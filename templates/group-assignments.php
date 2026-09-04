<?php
/**
 * "This Week's Assignments" — the few things a singer is meant to have
 * prepared, lifted out of the full materials list.
 *
 * WHAT COUNTS AS AN ASSIGNMENT, and why it is a tag rather than a new field.
 * Tom already marks these. The portal HANDOFF records his working pattern as
 * an `_Assignment` document pinned at the top of each project, and the
 * filename convention reserves `_` for tags. So an assignment is any material
 * whose tags include "assignment" — nothing new for him to learn, nothing to
 * migrate, and a piece stops being an assignment by having the tag removed.
 *
 * Adding a dedicated is_assignment field would have been tidier in the
 * abstract and worse in practice: it would be a second place to say the same
 * thing, and the one Tom does not currently use.
 *
 * ⚠️ TAGS NEVER GATE ACCESS. This panel filters rows the permission engine
 * has ALREADY decided this viewer may see — it narrows a visible list, it
 * never widens one. Materials come from ANSP_Permissions::get_visible_materials()
 * exactly as the Program Materials sub-tab gets them. Keep it that way; the
 * moment a tag decides visibility, a typo becomes an exposure.
 *
 * Expected in scope:
 *   $ansp_group_slug string ans_group slug, '' for unscoped.
 *   $ansp_group_name string Display name, for the empty state.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_group_slug = isset( $ansp_group_slug ) ? sanitize_key( (string) $ansp_group_slug ) : '';
$ansp_group_name = isset( $ansp_group_name ) ? (string) $ansp_group_name : '';
$ansp_viewer_id  = get_current_user_id();

/**
 * Does a material row read as an assignment?
 *
 * Substring, case-insensitive, so `_Assignment`, `Assignment`, `assignments`
 * and `Week 3 Assignment` all land. Deliberately generous: a missed
 * assignment is a singer arriving unprepared, while a false positive is one
 * extra row in a short list. The costs are not symmetrical.
 *
 * `updated` MATCHES TOO, and that is the point of it. ANSP_Scores_Source tags
 * a mirror row "Updated" when the worker publishes a new version of a score —
 * which is precisely the event "Tom issued a new PDF". Before this, a singer
 * only saw a re-issued score if somebody also remembered to hand-tag it, and
 * nobody ever did. This makes the newest sheet music surface here on its own,
 * with no habit for Tom to acquire.
 *
 * Note what this still does NOT do: it cannot read the Singers' Hub doc and
 * pull the PDF links out of it. Those links live inside a Google Doc on a
 * shared drive, and the portal holds no Google credentials — by design, that
 * is the whole reason the scores worker exists. So the doc is LINKED rather
 * than parsed; see the Hub card below.
 */
if ( ! function_exists( 'ansp_row_is_assignment' ) ) {
	function ansp_row_is_assignment( $row ) {
		if ( ! class_exists( 'ANSP_Materials' ) ) {
			return false;
		}
		$tags = ANSP_Materials::get_tags( $row );
		if ( ! is_array( $tags ) ) {
			return false;
		}
		foreach ( $tags as $tag ) {
			$tag = (string) $tag;
			if ( false !== stripos( $tag, 'assignment' ) || false !== stripos( $tag, 'updated' ) ) {
				return true;
			}
		}
		return false;
	}
}

// Same project set the Program Materials sub-tab shows: this season, this
// group (children included), published, not archived.
$ansp_a_season = class_exists( 'ANSP_Taxonomies' ) ? ANSP_Taxonomies::get_current_season() : null;

$ansp_a_args = array(
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

$ansp_a_tax = array();
if ( $ansp_a_season instanceof WP_Term ) {
	$ansp_a_tax[] = array(
		'taxonomy' => 'ans_season',
		'field'    => 'term_id',
		'terms'    => (int) $ansp_a_season->term_id,
	);
}
if ( '' !== $ansp_group_slug && taxonomy_exists( 'ans_group' ) ) {
	$ansp_a_term = get_term_by( 'slug', $ansp_group_slug, 'ans_group' );
	if ( $ansp_a_term instanceof WP_Term ) {
		$ansp_a_tax[] = array(
			'taxonomy'         => 'ans_group',
			'field'            => 'term_id',
			'terms'            => (int) $ansp_a_term->term_id,
			'include_children' => true,
		);
	}
}
if ( ! empty( $ansp_a_tax ) ) {
	$ansp_a_tax['relation']  = 'AND';
	$ansp_a_args['tax_query'] = $ansp_a_tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
}

$ansp_a_query = new WP_Query( $ansp_a_args );
$ansp_a_found = array();
$ansp_a_hubs  = array();
$ansp_a_notes = array();

foreach ( $ansp_a_query->posts as $ansp_a_project ) {
	if ( ! ansp_user_can_see( $ansp_a_project, $ansp_viewer_id ) ) {
		continue;
	}
	$ansp_a_pid = (int) $ansp_a_project->ID;

	/*
	 * The Singers' Hub doc for this project, if Tom's is recorded on it.
	 * Gathered inside the SAME permission-checked loop as the materials so a
	 * singer can never be shown the hub doc of a project they cannot see.
	 */
	$ansp_a_hub = class_exists( 'ANSP_Project_Meta' )
		? ANSP_Project_Meta::get( $ansp_a_pid, 'hub_doc_url' )
		: '';
	if ( '' !== $ansp_a_hub ) {
		$ansp_a_hubs[] = array(
			'title' => get_the_title( $ansp_a_project ),
			'url'   => $ansp_a_hub,
		);
	}

	$ansp_a_rows = ANSP_Permissions::get_visible_materials( $ansp_a_pid, $ansp_viewer_id );

	/*
	 * Rehearsal notes are pulled OUT of the ordinary assignment list and shown
	 * on their own above it. They are not tagged and never will be — they arrive
	 * from a mirror sub-folder, and the folder is what types them — so they would
	 * otherwise never reach this panel at all.
	 */
	$ansp_a_hits = array();
	foreach ( (array) $ansp_a_rows as $ansp_a_row ) {
		$ansp_a_type = isset( $ansp_a_row['type'] ) ? (string) $ansp_a_row['type'] : '';
		if ( 'rehearsal_note' === $ansp_a_type ) {
			$ansp_a_row['project_title'] = get_the_title( $ansp_a_project );
			$ansp_a_row['project_id']    = $ansp_a_pid;
			$ansp_a_notes[]              = $ansp_a_row;
			continue;
		}
		if ( ansp_row_is_assignment( $ansp_a_row ) ) {
			$ansp_a_hits[] = $ansp_a_row;
		}
	}

	if ( $ansp_a_hits ) {
		$ansp_a_found[] = array(
			'project' => $ansp_a_project,
			'rows'    => array_values( $ansp_a_hits ),
		);
	}
}

/*
 * Newest first. A note with no readable date sorts last rather than vanishing —
 * an unreadable date is a naming slip, not a reason to hide a singer's notes.
 */
usort(
	$ansp_a_notes,
	static function ( $a, $b ) {
		$da = isset( $a['rehearsal_date'] ) ? (string) $a['rehearsal_date'] : '';
		$db = isset( $b['rehearsal_date'] ) ? (string) $b['rehearsal_date'] : '';
		if ( $da === $db ) {
			return 0;
		}
		if ( '' === $da ) {
			return 1;
		}
		if ( '' === $db ) {
			return -1;
		}
		return strcmp( $db, $da );
	}
);

$ansp_a_latest   = ! empty( $ansp_a_notes ) ? array_shift( $ansp_a_notes ) : null;
$ansp_a_previous = $ansp_a_notes;

/**
 * A rehearsal note's date, formatted for display, or its title as a fallback.
 *
 * @param array $note One note row.
 * @return string
 */
if ( ! function_exists( 'ansp_note_label' ) ) {
	function ansp_note_label( $note ) {
		$date = isset( $note['rehearsal_date'] ) ? (string) $note['rehearsal_date'] : '';
		if ( '' !== $date ) {
			$stamp = strtotime( $date . ' 12:00:00' );
			if ( $stamp ) {
				return wp_date( get_option( 'date_format' ), $stamp );
			}
		}
		return isset( $note['title'] ) ? (string) $note['title'] : '';
	}
}
?>

<?php if ( null !== $ansp_a_latest ) : ?>
	<section class="ansp-notes">
		<h4 class="ansp-notes-title">
			<?php esc_html_e( 'Rehearsal notes', 'ans-singers-portal' ); ?>
			<span class="ansp-notes-date"><?php echo esc_html( ansp_note_label( $ansp_a_latest ) ); ?></span>
		</h4>

		<?php if ( ! empty( $ansp_a_latest['url'] ) ) : ?>
			<?php
			/*
			 * The newest note is rendered in the page rather than linked.
			 *
			 * ⚠️ This is a deliberate, NARROW exception to the v1.14.0 decision
			 * that stripped every inline preview from material-item.php. That
			 * decision was right about scores: twelve stacked iframes pushed the
			 * real links off the screen. It does not hold for a single document
			 * that a singer is meant to READ on arrival. Exactly one note is
			 * embedded, on one sub-tab. Do not generalise this.
			 *
			 * The src is the durable score link, which 302s to a freshly signed
			 * mirror URL — so nothing here expires and no credential is in the
			 * markup. If the browser or storage declines to display it inline,
			 * the buttons below still work; that is why they are not hidden
			 * behind the embed.
			 */
			?>
			<div class="ansp-note-embed">
				<iframe src="<?php echo esc_url( $ansp_a_latest['url'] ); ?>"
					title="<?php echo esc_attr( sprintf( /* translators: %s: rehearsal date */ __( 'Rehearsal notes, %s', 'ans-singers-portal' ), ansp_note_label( $ansp_a_latest ) ) ); ?>"
					loading="lazy"></iframe>
			</div>
			<p class="ansp-note-actions">
				<a class="ansp-btn" href="<?php echo esc_url( $ansp_a_latest['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open in a new tab', 'ans-singers-portal' ); ?>
				</a>
				<span class="ansp-note-hint">
					<?php esc_html_e( 'If the notes do not appear above, open them here.', 'ans-singers-portal' ); ?>
				</span>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $ansp_a_previous ) ) : ?>
			<details class="ansp-notes-history">
				<summary>
					<?php
					printf(
						/* translators: %d: number of earlier rehearsal notes */
						esc_html( _n( '%d earlier note', '%d earlier notes', count( $ansp_a_previous ), 'ans-singers-portal' ) ),
						count( $ansp_a_previous )
					);
					?>
				</summary>
				<ul class="ansp-notes-list">
					<?php foreach ( $ansp_a_previous as $ansp_a_note ) : ?>
						<li>
							<?php if ( ! empty( $ansp_a_note['url'] ) ) : ?>
								<a href="<?php echo esc_url( $ansp_a_note['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( ansp_note_label( $ansp_a_note ) ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( ansp_note_label( $ansp_a_note ) ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( ! empty( $ansp_a_hubs ) ) : ?>
	<?php foreach ( $ansp_a_hubs as $ansp_a_hub_item ) : ?>
		<section class="ansp-hub-doc">
			<h4 class="ansp-hub-doc-title"><?php esc_html_e( "This week, from Tom", 'ans-singers-portal' ); ?></h4>
			<p class="ansp-hub-doc-note">
				<?php
				printf(
					/* translators: %s: project title */
					esc_html__( "The Singers' Hub document for %s is where the current PDFs, click tracks and rehearsal recordings are posted. It is updated as the week goes.", 'ans-singers-portal' ),
					esc_html( $ansp_a_hub_item['title'] )
				);
				?>
			</p>
			<p>
				<a class="ansp-btn" href="<?php echo esc_url( $ansp_a_hub_item['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( "Open the Singers' Hub", 'ans-singers-portal' ); ?>
				</a>
			</p>
		</section>
	<?php endforeach; ?>
<?php endif; ?>


<?php if ( empty( $ansp_a_found ) && empty( $ansp_a_hubs ) && null === $ansp_a_latest ) : ?>
	<p class="ansp-empty">
		<?php
		if ( '' !== $ansp_group_name ) {
			printf(
				/* translators: %s: ensemble name */
				esc_html__( 'Nothing is marked as an assignment for %s right now. Everything posted for this season is under Program Materials.', 'ans-singers-portal' ),
				esc_html( $ansp_group_name )
			);
		} else {
			esc_html_e( 'Nothing is marked as an assignment right now. Everything posted for this season is under Program Materials.', 'ans-singers-portal' );
		}
		?>
	</p>
<?php elseif ( ! empty( $ansp_a_found ) ) : ?>
	<?php foreach ( $ansp_a_found as $ansp_a_block ) : ?>
		<section class="ansp-assignment-block">
			<h4 class="ansp-assignment-project"><?php echo esc_html( get_the_title( $ansp_a_block['project'] ) ); ?></h4>
			<?php
			/*
			 * selectable = false. The zip picker belongs to Program Materials,
			 * where the whole set lives; two checkbox lists on one screen
			 * feeding one "Download selected" button would be ambiguous about
			 * which selection it acts on.
			 */
			ansp_get_template(
				'materials-list',
				array(
					'materials'  => $ansp_a_block['rows'],
					'project_id' => (int) $ansp_a_block['project']->ID,
					'selectable' => false,
					'list_class' => 'ansp-materials--assignments',
				)
			);
			?>
		</section>
	<?php endforeach; ?>
<?php endif; ?>
