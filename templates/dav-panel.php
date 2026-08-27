<?php
/**
 * The "sync all of this" panel.
 *
 * Collapsed by default and placed under the projects rather than above them.
 * The overwhelming majority of singers will tap a file and read it; this is
 * for the ones who want the season on a tablet in one action, and it should
 * not be the first thing anyone has to scroll past to reach their music.
 *
 * Expects $ansp_dav: url, username, group, note.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( empty( $ansp_dav['url'] ) || empty( $ansp_dav['username'] ) ) {
	return;
}
?>
<details class="ansp-dav">
	<summary class="ansp-dav-summary">
		<?php esc_html_e( 'Sync this music to your tablet (WebDAV)', 'ans-singers-portal' ); ?>
	</summary>

	<div class="ansp-dav-body">
		<p class="ansp-dav-lead">
			<?php esc_html_e( 'Every score above, in one folder your tablet can copy from — and copy again later, pulling down only what has changed. You will need a file app that can connect to a WebDAV server.', 'ans-singers-portal' ); ?>
		</p>

		<dl class="ansp-dav-fields">
			<dt><?php esc_html_e( 'Server address', 'ans-singers-portal' ); ?></dt>
			<dd>
				<?php
				/*
				 * Rendered as text with a copy button, not as a link. A tap on
				 * an <a> would open it in the browser, which prompts for the
				 * password and then shows raw XML — the exact "it is broken"
				 * report this panel would otherwise generate. The address is
				 * for pasting into a file app.
				 */
				?>
				<code class="ansp-dav-value" data-ansp-copy-value="<?php echo esc_attr( $ansp_dav['url'] ); ?>"><?php echo esc_html( $ansp_dav['url'] ); ?></code>
				<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-copy="<?php echo esc_attr( $ansp_dav['url'] ); ?>">
					<?php esc_html_e( 'Copy', 'ans-singers-portal' ); ?>
				</button>
			</dd>

			<dt><?php esc_html_e( 'Username', 'ans-singers-portal' ); ?></dt>
			<dd>
				<code class="ansp-dav-value"><?php echo esc_html( $ansp_dav['username'] ); ?></code>
				<button type="button" class="ansp-btn ansp-btn--small ansp-btn--ghost" data-ansp-copy="<?php echo esc_attr( $ansp_dav['username'] ); ?>">
					<?php esc_html_e( 'Copy', 'ans-singers-portal' ); ?>
				</button>
			</dd>

			<dt><?php esc_html_e( 'Password', 'ans-singers-portal' ); ?></dt>
			<dd class="ansp-dav-muted">
				<?php esc_html_e( 'Sent to you separately — it is deliberately not printed on this page.', 'ans-singers-portal' ); ?>
			</dd>
		</dl>

		<?php if ( ! empty( $ansp_dav['note'] ) ) : ?>
			<p class="ansp-dav-note"><?php echo esc_html( $ansp_dav['note'] ); ?></p>
		<?php endif; ?>

		<p class="ansp-dav-muted">
			<?php esc_html_e( 'Read-only: nothing your tablet does can change or delete the choir\'s music. Files keep the exact names your annotations are attached to, so a re-sync lands on the same file rather than a new copy.', 'ans-singers-portal' ); ?>
		</p>
	</div>
</details>
