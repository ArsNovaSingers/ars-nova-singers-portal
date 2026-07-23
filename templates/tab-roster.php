<?php
/**
 * Roster tab: cards for the singers in the viewer's group(s),
 * showing only "visible to choir" fields.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_viewer_id  = get_current_user_id();
$ansp_is_manager = ANSP_Permissions::is_manager( $ansp_viewer_id );
$ansp_singers    = ANSP_Roster::get_visible_singers( $ansp_viewer_id );
?>
<h3 class="ansp-section-title"><?php esc_html_e( 'Roster', 'ans-singers-portal' ); ?></h3>

<?php if ( ! post_type_exists( 'singer' ) ) : ?>
	<p class="ansp-empty"><?php esc_html_e( 'The roster is not available on this site yet.', 'ans-singers-portal' ); ?></p>
<?php elseif ( empty( $ansp_singers ) ) : ?>
	<p class="ansp-empty"><?php esc_html_e( 'No roster entries to show yet. If you expected to see your group here, contact the Personnel Manager.', 'ans-singers-portal' ); ?></p>
<?php else : ?>
	<div class="ansp-roster-grid">
		<?php foreach ( $ansp_singers as $ansp_singer ) : ?>
			<?php
			$ansp_pid      = (int) $ansp_singer->ID;
			$ansp_headshot = ANSP_Profiles::get_headshot_url( $ansp_pid );
			$ansp_fields   = ANSP_Roster::get_card_fields( $ansp_pid, $ansp_is_manager );
			$ansp_groups   = ANSP_Roster::get_group_names( $ansp_pid );
			?>
			<article class="ansp-roster-card">
				<?php if ( $ansp_headshot ) : ?>
					<img class="ansp-roster-photo" src="<?php echo esc_url( $ansp_headshot ); ?>" alt="<?php echo esc_attr( get_the_title( $ansp_singer ) ); ?>" loading="lazy" />
				<?php else : ?>
					<div class="ansp-roster-photo ansp-roster-photo--placeholder" aria-hidden="true">
						<span><?php echo esc_html( mb_substr( get_the_title( $ansp_singer ), 0, 1 ) ); ?></span>
					</div>
				<?php endif; ?>
				<h4 class="ansp-roster-name"><?php echo esc_html( get_the_title( $ansp_singer ) ); ?></h4>
				<?php if ( ! empty( $ansp_groups ) ) : ?>
					<p class="ansp-badges">
						<?php foreach ( $ansp_groups as $ansp_group_name ) : ?>
							<span class="ansp-badge"><?php echo esc_html( $ansp_group_name ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<dl class="ansp-roster-fields">
					<?php foreach ( $ansp_fields as $ansp_key => $ansp_field ) : ?>
						<div class="ansp-roster-field">
							<dt><?php echo esc_html( $ansp_field['label'] ); ?></dt>
							<dd>
								<?php if ( 'email' === $ansp_field['type'] ) : ?>
									<a href="<?php echo esc_url( 'mailto:' . $ansp_field['value'] ); ?>"><?php echo esc_html( $ansp_field['value'] ); ?></a>
								<?php elseif ( 'tel' === $ansp_field['type'] ) : ?>
									<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $ansp_field['value'] ) ); ?>"><?php echo esc_html( $ansp_field['value'] ); ?></a>
								<?php elseif ( 'bio' === $ansp_key ) : ?>
									<?php echo wp_kses_post( wpautop( $ansp_field['value'] ) ); ?>
								<?php else : ?>
									<?php echo esc_html( $ansp_field['value'] ); ?>
								<?php endif; ?>
							</dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
