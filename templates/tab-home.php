<?php
/**
 * Home / Announcements tab: group-scoped announcements, newest first.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_announcements = ANSP_Announcements::get_visible( get_current_user_id(), 20 );
?>
<h3 class="ansp-section-title"><?php esc_html_e( 'Announcements', 'ans-singers-portal' ); ?></h3>

<?php if ( empty( $ansp_announcements ) ) : ?>
	<p class="ansp-empty"><?php esc_html_e( 'No announcements right now — check back soon.', 'ans-singers-portal' ); ?></p>
<?php else : ?>
	<ul class="ansp-announcements">
		<?php foreach ( $ansp_announcements as $ansp_announcement ) : ?>
			<li class="ansp-announcement">
				<div class="ansp-announcement-head">
					<h4 class="ansp-announcement-title"><?php echo esc_html( get_the_title( $ansp_announcement ) ); ?></h4>
					<time class="ansp-announcement-date" datetime="<?php echo esc_attr( get_the_date( 'c', $ansp_announcement ) ); ?>">
						<?php echo esc_html( get_the_date( '', $ansp_announcement ) ); ?>
					</time>
				</div>
				<?php
				$ansp_terms = wp_get_object_terms( $ansp_announcement->ID, 'ans_group' );
				if ( ! is_wp_error( $ansp_terms ) && ! empty( $ansp_terms ) ) :
					?>
					<p class="ansp-badges">
						<?php foreach ( $ansp_terms as $ansp_term ) : ?>
							<span class="ansp-badge"><?php echo esc_html( $ansp_term->name ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<div class="ansp-announcement-body">
					<?php echo wp_kses_post( wpautop( $ansp_announcement->post_content ) ); ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
