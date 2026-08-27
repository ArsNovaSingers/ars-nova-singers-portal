<?php
/**
 * "Smaller Files" admin screen.
 *
 * Written for Tom, not for an engineer. No jargon: no "candidate", no
 * "staging", no percentages without a plain-language sentence beside them.
 * The question this page asks is "does this still look right?", and the
 * answer comes from opening the PDF, so the two Open buttons come first and
 * the decision buttons come last.
 *
 * Expects $ansp_items, $ansp_groups, $ansp_notice.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_error = is_wp_error( $ansp_items ) ? $ansp_items : null;
$ansp_list  = is_array( $ansp_items ) ? $ansp_items : array();
?>
<div class="wrap ansp-admin-wrap">
	<h1><?php esc_html_e( 'Smaller Files', 'ans-singers-portal' ); ?></h1>

	<p class="description" style="max-width:46em;font-size:14px;">
		<?php esc_html_e( 'Some sheet music is scanned in colour at a much higher resolution than a tablet can show. It looks identical but the file is many times bigger, which makes it slow to download and eats space on every singer\'s iPad. This page offers a smaller copy of any score where the saving is worth having — and never changes anything until you say so.', 'ans-singers-portal' ); ?>
	</p>

	<?php if ( $ansp_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $ansp_notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $ansp_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $ansp_error ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $ansp_error->get_error_message() ); ?></p>
		</div>
	<?php endif; ?>

	<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Check for files that could be smaller', 'ans-singers-portal' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Optimise_Admin::SCAN ); ?>" />
		<?php wp_nonce_field( ANSP_Optimise_Admin::SCAN ); ?>
		<p>
			<label for="ansp-opt-group" class="screen-reader-text"><?php esc_html_e( 'Group', 'ans-singers-portal' ); ?></label>
			<select name="group" id="ansp-opt-group">
				<?php if ( empty( $ansp_groups ) ) : ?>
					<option value=""><?php esc_html_e( '— no groups set up yet —', 'ans-singers-portal' ); ?></option>
				<?php else : ?>
					<?php foreach ( $ansp_groups as $ansp_g ) : ?>
						<option value="<?php echo esc_attr( $ansp_g ); ?>"><?php echo esc_html( $ansp_g ); ?></option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check this group', 'ans-singers-portal' ); ?></button>
			<span class="description" style="margin-left:.5em;">
				<?php esc_html_e( 'This can take a few minutes on large scores. Nothing is changed by checking.', 'ans-singers-portal' ); ?>
			</span>
		</p>
	</form>

	<h2 style="margin-top:2em;"><?php esc_html_e( 'Waiting for your decision', 'ans-singers-portal' ); ?></h2>

	<?php if ( empty( $ansp_list ) && ! $ansp_error ) : ?>
		<p><?php esc_html_e( 'Nothing is waiting. Either everything is already about as small as it should be, or nobody has run a check yet.', 'ans-singers-portal' ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( $ansp_list as $ansp_item ) :
		$ansp_opt   = isset( $ansp_item['optimisation'] ) ? $ansp_item['optimisation'] : array();
		$ansp_ver   = isset( $ansp_opt['verify'] ) ? $ansp_opt['verify'] : array();
		$ansp_src   = isset( $ansp_opt['source'] ) ? $ansp_opt['source'] : array();
		$ansp_id    = isset( $ansp_item['staging_id'] ) ? $ansp_item['staging_id'] : '';
		$ansp_work  = isset( $ansp_opt['work_id'] ) ? $ansp_opt['work_id'] : '';
		$ansp_name  = isset( $ansp_item['group'] ) ? $ansp_item['group'] : '';
		$ansp_title = isset( $ansp_item['source_name'] ) ? preg_replace( '/\.pdf \(optimised\)$/', '', $ansp_item['source_name'] ) : $ansp_id;

		$ansp_before = isset( $ansp_opt['bytes_before'] ) ? (int) $ansp_opt['bytes_before'] : 0;
		$ansp_after  = isset( $ansp_opt['bytes_after'] ) ? (int) $ansp_opt['bytes_after'] : 0;
		$ansp_pct    = $ansp_before ? round( 100 * ( $ansp_before - $ansp_after ) / $ansp_before ) : 0;
		$ansp_times  = $ansp_after ? round( $ansp_before / max( 1, $ansp_after ), 1 ) : 0;

		$ansp_view = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . ANSP_Optimise_Admin::VIEW . '&staging_id=' . rawurlencode( $ansp_id ) ),
			ANSP_Optimise_Admin::VIEW . '_' . $ansp_id
		);
		?>
		<div class="card" style="max-width:none;padding:1rem 1.25rem;margin-bottom:1.25rem;">
			<h3 style="margin-top:0;"><?php echo esc_html( $ansp_title ); ?></h3>

			<p style="font-size:15px;margin:.25rem 0 1rem;">
				<?php
				printf(
					/* translators: 1: current size, 2: new size, 3: how many times smaller */
					esc_html__( 'Currently %1$s. The smaller copy is %2$s — about %3$s times smaller.', 'ans-singers-portal' ),
					'<strong>' . esc_html( size_format( $ansp_before, 1 ) ) . '</strong>',
					'<strong>' . esc_html( size_format( $ansp_after, 1 ) ) . '</strong>',
					esc_html( $ansp_times )
				);
				?>
			</p>

			<?php
			/*
			 * The most important thing on the page. Everything that has gone
			 * wrong with this feature looked fine in numbers and wrong on the
			 * page, so the instruction is to look, and the two links sit above
			 * the buttons that act.
			 */
			?>
			<p style="margin:0 0 .5rem;"><strong><?php esc_html_e( 'Please look at both before deciding:', 'ans-singers-portal' ); ?></strong></p>
			<p>
				<a class="button button-primary" target="_blank" rel="noopener noreferrer"
					href="<?php echo esc_url( $ansp_view . '&which=candidate' ); ?>">
					<?php esc_html_e( 'Open the smaller copy', 'ans-singers-portal' ); ?>
				</a>
				<a class="button" target="_blank" rel="noopener noreferrer"
					href="<?php echo esc_url( $ansp_view . '&which=current' ); ?>">
					<?php esc_html_e( 'Open what singers see now', 'ans-singers-portal' ); ?>
				</a>
			</p>
			<p class="description" style="margin-bottom:1rem;">
				<?php esc_html_e( 'Check a page with small print and one with your own pencil markings. If they read the same, the smaller copy is good.', 'ans-singers-portal' ); ?>
			</p>

			<details style="margin-bottom:1rem;">
				<summary style="cursor:pointer;"><?php esc_html_e( 'What the automatic checks found', 'ans-singers-portal' ); ?></summary>
				<ul style="margin:.5rem 0 0 1.25rem;list-style:disc;">
					<li>
						<?php
						printf(
							/* translators: %d: page count */
							esc_html__( 'Same number of pages (%d) and the same page size — so your markings stay where you put them.', 'ans-singers-portal' ),
							isset( $ansp_ver['pages_after'] ) ? (int) $ansp_ver['pages_after'] : 0
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %s: percentage */
							esc_html__( 'The printed music comes out at %s of the original\'s darkness — anything far from 100%% would mean something had gone wrong.', 'ans-singers-portal' ),
							esc_html( isset( $ansp_ver['ink_ratio'] ) ? round( 100 * (float) $ansp_ver['ink_ratio'] ) . '%' : '?' )
						);
						?>
					</li>
					<li><?php esc_html_e( 'The PDF reader raised no complaints while opening it.', 'ans-singers-portal' ); ?></li>
					<?php if ( ! empty( $ansp_src['colour_images'] ) ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: number of images, 2: dpi */
								esc_html__( 'The original was scanned in colour (%1$d images) at about %2$d dpi. Sheet music only needs grey at 200 dpi, which is where nearly all the saving comes from.', 'ans-singers-portal' ),
								(int) $ansp_src['colour_images'],
								isset( $ansp_src['max_dpi'] ) ? (int) $ansp_src['max_dpi'] : 0
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			</details>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Optimise_Admin::ACTION ); ?>" />
				<input type="hidden" name="staging_id" value="<?php echo esc_attr( $ansp_id ); ?>" />
				<input type="hidden" name="work_id" value="<?php echo esc_attr( $ansp_work ); ?>" />
				<input type="hidden" name="label" value="<?php echo esc_attr( $ansp_title ); ?>" />
				<input type="hidden" name="decision" value="new_edition" />
				<?php wp_nonce_field( ANSP_Optimise_Admin::ACTION ); ?>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Use the smaller copy', 'ans-singers-portal' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( ANSP_Optimise_Admin::ACTION ); ?>" />
				<input type="hidden" name="staging_id" value="<?php echo esc_attr( $ansp_id ); ?>" />
				<input type="hidden" name="label" value="<?php echo esc_attr( $ansp_title ); ?>" />
				<input type="hidden" name="decision" value="reject" />
				<?php wp_nonce_field( ANSP_Optimise_Admin::ACTION ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'No — keep the current one', 'ans-singers-portal' ); ?>
				</button>
			</form>

			<p class="description" style="margin-top:.75rem;">
				<?php esc_html_e( 'Using the smaller copy keeps the file name exactly as it is, so annotations follow it. The current version is kept and can be put back later.', 'ans-singers-portal' ); ?>
			</p>
		</div>
	<?php endforeach; ?>
</div>
