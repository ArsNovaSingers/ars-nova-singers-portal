<?php
/**
 * Renders ONE material with an inline preview, prioritising ease of viewing:
 *
 * - Google Drive file links → Drive preview iframe
 *   (https://drive.google.com/file/d/FILEID/preview, ID parsed from the link)
 * - Google Docs/Sheets/Slides → /preview iframe
 * - Video links → oEmbed (YouTube/Vimeo/…) or a direct <video> for files
 * - Images → inline <img>
 * - Direct audio files → <audio controls>
 * - Everything else → a styled open/download button
 *
 * Expects (via ansp_get_template args): $material (array), $project_id (int).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! isset( $material ) || ! is_array( $material ) ) {
	return;
}

$ansp_type  = isset( $material['type'] ) ? (string) $material['type'] : 'drive_link';
$ansp_title = isset( $material['title'] ) ? (string) $material['title'] : '';
$ansp_url   = isset( $material['url'] ) ? (string) $material['url'] : '';
$ansp_note  = isset( $material['note'] ) ? (string) $material['note'] : '';

$ansp_types      = ANSP_Materials::types();
$ansp_type_label = isset( $ansp_types[ $ansp_type ] ) ? $ansp_types[ $ansp_type ] : $ansp_type;

// Effective tags (manual tags + auto content-type label) drive the chips and
// the front-end tag filter (portal.js matches on the lowercased data attr).
$ansp_tags     = ANSP_Materials::effective_tags( $material );
$ansp_tags_key = implode( '|', array_map( 'strtolower', $ansp_tags ) );

$ansp_preview_url = ANSP_Materials::preview_url( $ansp_url );
$ansp_path        = strtolower( (string) wp_parse_url( $ansp_url, PHP_URL_PATH ) );
$ansp_is_image    = ( 'image' === $ansp_type ) && preg_match( '/\.(jpe?g|png|gif|webp|svg)$/', $ansp_path );
$ansp_is_audio    = preg_match( '/\.(mp3|m4a|ogg|wav)$/', $ansp_path );
$ansp_is_videofile = preg_match( '/\.(mp4|webm|mov)$/', $ansp_path );

// Tags allowed when printing oEmbed markup (iframes are not in the default
// wp_kses_post list, so we extend it deliberately for trusted oEmbed HTML).
$ansp_embed_kses = array(
	'iframe' => array(
		'src'             => true,
		'title'           => true,
		'width'           => true,
		'height'          => true,
		'frameborder'     => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'loading'         => true,
		'referrerpolicy'  => true,
	),
);

// A Drive/Docs preview is a reading surface, not a thumbnail — it gets the
// full width of the materials grid instead of one column of two.
$ansp_wide_class = $ansp_preview_url ? ' ansp-material--wide' : '';
?>
<article class="ansp-material ansp-material--<?php echo esc_attr( $ansp_type ); ?><?php echo esc_attr( $ansp_wide_class ); ?>" data-ansp-tags="<?php echo esc_attr( $ansp_tags_key ); ?>">
	<header class="ansp-material-head">
		<span class="ansp-material-type"><?php echo esc_html( $ansp_type_label ); ?></span>
		<h5 class="ansp-material-title"><?php echo esc_html( $ansp_title ); ?></h5>
	</header>

	<?php if ( ! empty( $ansp_tags ) ) : ?>
		<ul class="ansp-material-tags" aria-label="<?php esc_attr_e( 'Tags', 'ans-singers-portal' ); ?>">
			<?php foreach ( $ansp_tags as $ansp_tag ) : ?>
				<li class="ansp-tag-chip"><?php echo esc_html( $ansp_tag ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $ansp_note ) : ?>
		<p class="ansp-material-note"><?php echo esc_html( $ansp_note ); ?></p>
	<?php endif; ?>

	<?php if ( '' === $ansp_url ) : ?>
		<p class="ansp-empty"><?php esc_html_e( 'No link provided for this material yet.', 'ans-singers-portal' ); ?></p>
	<?php elseif ( $ansp_preview_url ) : ?>
		<div class="ansp-embed ansp-embed--drive">
			<iframe
				src="<?php echo esc_url( $ansp_preview_url ); ?>"
				title="<?php echo esc_attr( $ansp_title ); ?>"
				loading="lazy"
				allow="autoplay"
				allowfullscreen
			></iframe>
		</div>
	<?php elseif ( 'video_link' === $ansp_type && ! $ansp_is_videofile ) : ?>
		<?php $ansp_oembed = wp_oembed_get( $ansp_url, array( 'width' => 800 ) ); ?>
		<?php if ( $ansp_oembed ) : ?>
			<div class="ansp-embed ansp-embed--video">
				<?php echo wp_kses( $ansp_oembed, $ansp_embed_kses ); ?>
			</div>
		<?php endif; ?>
	<?php elseif ( $ansp_is_videofile ) : ?>
		<div class="ansp-embed ansp-embed--video">
			<video controls preload="metadata" src="<?php echo esc_url( $ansp_url ); ?>"></video>
		</div>
	<?php elseif ( $ansp_is_image ) : ?>
		<figure class="ansp-material-image">
			<a href="<?php echo esc_url( $ansp_url ); ?>" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( $ansp_url ); ?>" alt="<?php echo esc_attr( $ansp_title ); ?>" loading="lazy" />
			</a>
		</figure>
	<?php elseif ( $ansp_is_audio ) : ?>
		<div class="ansp-material-audio">
			<audio controls preload="none" src="<?php echo esc_url( $ansp_url ); ?>"></audio>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $ansp_url ) : ?>
		<p class="ansp-material-actions">
			<a class="ansp-btn ansp-btn--small" href="<?php echo esc_url( $ansp_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open / Download', 'ans-singers-portal' ); ?>
			</a>
		</p>
	<?php endif; ?>
</article>
