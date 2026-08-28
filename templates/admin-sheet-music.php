<?php
/**
 * The "Sheet music" panel on a project.
 *
 * Four numbered steps in the order they are done, on one screen. Everything
 * after step 1 is drawn by assets/sheet-music.js from what the service
 * returns, because the list changes as you scan, optimise and approve, and a
 * page reload between each would lose your place.
 *
 * Expects $ansp_folder_id, $ansp_folder_name, $ansp_group.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="ansp-sm" data-ansp-sm>

	<?php if ( '' === $ansp_group ) : ?>
		<div class="notice notice-warning inline" style="margin:0 0 1rem;">
			<p>
				<?php esc_html_e( 'This project has no sheet-music folder address yet, so there is nowhere to file its music. Set "Mirror address" below (for example: chamber-singers/26-27 CS), save the project, then come back.', 'ans-singers-portal' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- 1 ------------------------------------------------------------ -->
	<h4 class="ansp-sm-step"><span>1</span><?php esc_html_e( 'Root folder', 'ans-singers-portal' ); ?></h4>
	<p class="ansp-sm-current" data-ansp-sm-current>
		<?php if ( '' !== $ansp_folder_id ) : ?>
			<strong><?php echo esc_html( '' !== $ansp_folder_name ? $ansp_folder_name : $ansp_folder_id ); ?></strong>
			<span class="ansp-sm-muted"><?php echo esc_html( $ansp_folder_id ); ?></span>
		<?php else : ?>
			<em><?php esc_html_e( 'No folder set yet.', 'ans-singers-portal' ); ?></em>
		<?php endif; ?>
	</p>
	<p>
		<button type="button" class="button" data-ansp-sm-setfolder>
			<?php echo '' !== $ansp_folder_id ? esc_html__( 'Change root folder', 'ans-singers-portal' ) : esc_html__( 'Set root folder', 'ans-singers-portal' ); ?>
		</button>
	</p>

	<div class="ansp-sm-picker" data-ansp-sm-picker hidden>
		<p class="ansp-sm-muted"><?php esc_html_e( 'Browse to the folder, or paste its address from Google Drive.', 'ans-singers-portal' ); ?></p>
		<p>
			<input type="text" class="regular-text" data-ansp-sm-paste
				placeholder="<?php esc_attr_e( 'https://drive.google.com/drive/folders/…', 'ans-singers-portal' ); ?>" />
			<button type="button" class="button" data-ansp-sm-usepaste><?php esc_html_e( 'Use this', 'ans-singers-portal' ); ?></button>
		</p>
		<div class="ansp-sm-crumb" data-ansp-sm-crumb></div>
		<ul class="ansp-sm-folders" data-ansp-sm-folders></ul>
	</div>

	<!-- 2 ------------------------------------------------------------ -->
	<h4 class="ansp-sm-step"><span>2</span><?php esc_html_e( 'Scan for music', 'ans-singers-portal' ); ?></h4>
	<p>
		<button type="button" class="button button-primary" data-ansp-sm-scan
			<?php disabled( '' === $ansp_folder_id || '' === $ansp_group ); ?>>
			<?php esc_html_e( 'Scan this folder', 'ans-singers-portal' ); ?>
		</button>
		<span class="ansp-sm-muted" style="margin-left:.5em;">
			<?php esc_html_e( 'Reads the folder and proposes a name for anything new. Changes nothing.', 'ans-singers-portal' ); ?>
		</span>
	</p>

	<!-- 3 & 4 -------------------------------------------------------- -->
	<h4 class="ansp-sm-step"><span>3</span><?php esc_html_e( 'Check the names, then add each piece', 'ans-singers-portal' ); ?></h4>
	<div class="ansp-sm-status" data-ansp-sm-status></div>
	<div data-ansp-sm-list></div>
</div>
