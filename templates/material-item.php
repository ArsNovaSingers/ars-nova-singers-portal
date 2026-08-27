<?php
/**
 * Renders ONE material as a LIST ROW.
 *
 * v1.14.0 removed every inline preview from this template — the Drive/Docs
 * iframe, the oEmbed video block, <video>, <audio> and <img>. Singers open
 * their music in Drive or download it; they do not read a score through a
 * 900px iframe stacked twelve deep, and the previews pushed the actual links
 * off the bottom of the screen. What replaces them is one dense row per
 * material: select, identify, open, download.
 *
 * Layout: the TITLE leads the row. Everything that classifies the material —
 * the content-type label and its tags — is demoted to the bottom line and
 * right-justified against the Open/Download buttons, so a singer scanning the
 * list reads down a clean column of piece names instead of a column of the
 * word "Sheet music" twelve times.
 *
 * The row keeps `data-ansp-tags` in exactly the shape assets/portal.js has
 * always matched on, so the tag filter carries over untouched. Note that the
 * data attribute carries the EFFECTIVE tags (manual + the auto content-type
 * label) because that is what the filter offers, while the visible chips show
 * the type label once, on its own, plus the manual tags — otherwise the type
 * would appear twice on every row.
 *
 * Expects (via ansp_get_template args): $material (array), $project_id (int)
 * and optionally $selectable (bool, default true). Past Projects passes
 * false: a checkbox outside the download form would be inert, and archived
 * material is the material that is supposed to come DOWN for copyright
 * reasons, so bulk-collecting it is deliberately not made easy.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! isset( $material ) || ! is_array( $material ) ) {
	return;
}

$ansp_project_id = isset( $project_id ) ? (int) $project_id : 0;
$ansp_selectable = isset( $selectable ) ? (bool) $selectable : true;

/*
 * Inside a content-type section the heading already says "Audio", so the
 * per-row chip saying "Recording" is both redundant and, because the two come
 * from different label maps, quietly inconsistent. The caller suppresses it.
 */
$ansp_show_type = isset( $show_type ) ? (bool) $show_type : true;

$ansp_id    = isset( $material['id'] ) ? (string) $material['id'] : '';
$ansp_type  = isset( $material['type'] ) ? (string) $material['type'] : 'drive_link';
$ansp_title = isset( $material['title'] ) ? (string) $material['title'] : '';
$ansp_url   = isset( $material['url'] ) ? (string) $material['url'] : '';
$ansp_note  = isset( $material['note'] ) ? (string) $material['note'] : '';

$ansp_types      = ANSP_Materials::types();
$ansp_type_label = isset( $ansp_types[ $ansp_type ] ) ? $ansp_types[ $ansp_type ] : $ansp_type;

// Effective tags drive the front-end filter (portal.js matches on the
// lowercased data attr); the manual tags alone drive the visible chips.
$ansp_tags     = ANSP_Materials::effective_tags( $material );
$ansp_tags_key = implode( '|', array_map( 'strtolower', $ansp_tags ) );
$ansp_chips    = ANSP_Materials::get_tags( $material );

/*
 * Only offer a checkbox for something that can actually be put in a zip.
 * ANSP_Materials_Zip::is_zippable() is the single answer to that question and
 * the download handler asks it again server-side — a checkbox that silently
 * produced nothing would be worse than no checkbox at all.
 */
$ansp_zippable = ( '' !== $ansp_url && '' !== $ansp_id && ANSP_Materials_Zip::is_zippable( $ansp_url ) );
$ansp_checkbox = ( $ansp_selectable && $ansp_zippable );

// A Google-native doc has no raw bytes and is exported instead. Say so on the
// row rather than letting a PDF appear in the archive unexplained.
$ansp_is_gdoc = ( 1 === preg_match( '#^https?://docs\.google\.com/(?:document|spreadsheets|presentation|drawings)/d/#', $ansp_url ) );
?>
<li class="ansp-matrow<?php echo $ansp_checkbox ? '' : ' ansp-matrow--nozip'; ?>" data-ansp-tags="<?php echo esc_attr( $ansp_tags_key ); ?>">

	<?php if ( $ansp_selectable ) : ?>
	<div class="ansp-matrow-check">
		<?php if ( $ansp_checkbox ) : ?>
			<input
				type="checkbox"
				name="material_ids[]"
				value="<?php echo esc_attr( $ansp_id ); ?>"
				data-ansp-select
				aria-label="<?php
					/* translators: %s: material title. */
					echo esc_attr( sprintf( __( 'Select %s for download', 'ans-singers-portal' ), $ansp_title ) );
				?>"
			/>
		<?php else : ?>
			<span class="ansp-matrow-nozip" aria-hidden="true" title="<?php esc_attr_e( 'This is a link, not a file — it cannot be added to a zip.', 'ans-singers-portal' ); ?>">&ndash;</span>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<span class="ansp-matrow-title">
		<?php echo esc_html( $ansp_title ); ?>
		<?php if ( $ansp_is_gdoc ) : ?>
			<span class="ansp-matrow-hint"><?php esc_html_e( 'Google Doc — downloads as PDF', 'ans-singers-portal' ); ?></span>
		<?php endif; ?>
	</span>

	<?php if ( $ansp_note ) : ?>
		<p class="ansp-material-note"><?php echo esc_html( $ansp_note ); ?></p>
	<?php endif; ?>

	<?php if ( class_exists( 'ANSP_Player' ) && ANSP_Player::is_playable( $material ) ) : ?>
		<div class="ansp-matrow-player">
			<?php
			/*
			 * preload="none" is load-bearing, not tidiness. Nineteen movements on
			 * one project would otherwise be nineteen Drive fetches the moment the
			 * page opened, for tracks nobody asked to hear. Nothing is fetched
			 * until someone presses play; after that the browser streams it
			 * progressively and caches it, so a second listen costs nothing.
			 */
			?>
			<audio controls preload="none"
				src="<?php echo esc_url( ANSP_Player::play_url( $ansp_project_id, $ansp_id ) ); ?>">
				<?php esc_html_e( 'Your browser cannot play this here — use Download instead.', 'ans-singers-portal' ); ?>
			</audio>
		</div>
	<?php endif; ?>

	<div class="ansp-matrow-foot">
		<?php if ( '' === $ansp_url ) : ?>
			<span class="ansp-matrow-hint"><?php esc_html_e( 'No link yet', 'ans-singers-portal' ); ?></span>
		<?php else : ?>
			<a class="ansp-btn ansp-btn--small ansp-btn--ghost" href="<?php echo esc_url( $ansp_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open', 'ans-singers-portal' ); ?>
			</a>
			<?php if ( $ansp_zippable ) : ?>
				<?php
				/*
				 * Downloads route through this site, not straight at Drive.
				 * The files are shared with the service account rather than
				 * with each singer's own Google identity, so a direct Drive
				 * download link works for some people and not others.
				 */
				?>
				<a class="ansp-btn ansp-btn--small" href="<?php echo esc_url( ANSP_Materials_Zip::single_url( $ansp_project_id, $ansp_id ) ); ?>">
					<?php esc_html_e( 'Download', 'ans-singers-portal' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>

		<ul class="ansp-matrow-chips" aria-label="<?php esc_attr_e( 'Type and tags', 'ans-singers-portal' ); ?>">
			<?php if ( $ansp_show_type ) : ?>
			<li class="ansp-tag-chip ansp-tag-chip--type"><?php echo esc_html( $ansp_type_label ); ?></li>
		<?php endif; ?>
			<?php foreach ( $ansp_chips as $ansp_tag ) : ?>
				<li class="ansp-tag-chip"><?php echo esc_html( $ansp_tag ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>

</li>
