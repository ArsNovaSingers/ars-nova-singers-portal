<?php
/**
 * Roster tab: cards for the singers in the viewer's group(s),
 * showing only "visible to choir" fields.
 *
 * TWO THINGS HERE ARE DELIBERATE AND EASY TO UNDO BY ACCIDENT.
 *
 * 1. THE PHOTO IS RENDERED BY wp_get_attachment_image(), NOT A HAND-WRITTEN
 *    <img src>. A hand-written src names exactly one file, so the browser has
 *    no smaller candidate for a phone and no larger one for a retina laptop —
 *    it stretches whatever it was given. That is what made these photos look
 *    "reduced then blown up": the src was WordPress's `medium` (300px) inside
 *    a card about 360px wide. wp_get_attachment_image() emits a srcset of every
 *    size the upload actually has, and the `sizes` attribute below tells the
 *    browser how wide the box really is at each breakpoint. If you replace this
 *    with an <img> tag again, the blur comes back.
 *
 * 2. THE BIO IS NOT IN THE <dl>. Every other card field is a short label/value
 *    pair rendered inline ("Voice part: Soprano"). A bio is paragraphs, and as
 *    a <dd> it inherited `display: inline` and ran the card to whatever length
 *    the longest bio happened to be. It now renders as its own block, clamped
 *    to four lines by .ansp-roster-bio, with the profile link reading as its
 *    continuation. Jonathan, 2026-09-04: "maybe 4 lines teaser plus a more
 *    link that goes to their page."
 *
 *    The clamp is CSS, not a substr. Truncating server-side means guessing how
 *    many characters make four lines at an unknown font size in an unknown
 *    column width, and being wrong in both directions — a two-line card next
 *    to a six-line one. The full text stays in the DOM and the browser clamps
 *    it to exactly four lines at whatever width the card ended up.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_viewer_id  = get_current_user_id();
$ansp_is_manager = ANSP_Permissions::is_manager( $ansp_viewer_id );
$ansp_singers    = ANSP_Roster::get_visible_singers( $ansp_viewer_id );

/*
 * How wide a roster photo actually is, per breakpoint, so the browser can pick
 * a sensible file. Mirrors .ansp-roster-grid in assets/portal.css — 3 columns
 * from 960px, 2 from 600px, 1 below that — and the card's 1px border and gap.
 * KEEP THESE TWO IN STEP: a `sizes` that disagrees with the CSS is how you get
 * a sharp phone and a soft desktop.
 */
$ansp_photo_sizes = '(min-width: 960px) 360px, (min-width: 600px) 46vw, 92vw';
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
			$ansp_pid         = (int) $ansp_singer->ID;
			$ansp_name        = get_the_title( $ansp_singer );
			$ansp_headshot_id = ANSP_Profiles::get_headshot_id( $ansp_pid );
			$ansp_headshot    = ANSP_Profiles::get_headshot_url( $ansp_pid );
			$ansp_fields      = ANSP_Roster::get_card_fields( $ansp_pid, $ansp_is_manager );
			$ansp_groups      = ANSP_Roster::get_group_names( $ansp_pid );

			/*
			 * Bio comes out of the field list and is rendered on its own below.
			 * Pulled here rather than filtered in ANSP_Roster::get_card_fields()
			 * so the bio's privacy rule stays in one place — that method still
			 * decides whether this viewer may see it at all.
			 */
			$ansp_bio = isset( $ansp_fields['bio']['value'] ) ? (string) $ansp_fields['bio']['value'] : '';
			unset( $ansp_fields['bio'] );

			// Public bio page — only for published profiles, so an unpublished
			// or draft singer never gets a link that 404s.
			$ansp_bio_link = ( 'publish' === get_post_status( $ansp_singer ) ) ? get_permalink( $ansp_singer ) : '';

			$ansp_img_attr = array(
				'class'   => 'ansp-roster-photo',
				'alt'     => $ansp_name,
				'loading' => 'lazy',
				'sizes'   => $ansp_photo_sizes,
			);
			?>
			<article class="ansp-roster-card">
				<?php if ( $ansp_headshot_id || $ansp_headshot ) : ?>
					<?php
					/*
					 * The attachment path gets a srcset. The meta-URL path is a
					 * bare external URL with no sizes to offer, so it stays a
					 * plain <img> — nothing better is available for it.
					 */
					$ansp_photo_html = $ansp_headshot_id
						? wp_get_attachment_image( $ansp_headshot_id, ANSP_Profiles::HEADSHOT_SIZE, false, $ansp_img_attr )
						: sprintf(
							'<img class="ansp-roster-photo" src="%1$s" alt="%2$s" loading="lazy" />',
							esc_url( $ansp_headshot ),
							esc_attr( $ansp_name )
						);
					?>
					<?php if ( $ansp_bio_link ) : ?>
						<a class="ansp-roster-photo-link" href="<?php echo esc_url( $ansp_bio_link ); ?>" tabindex="-1" aria-hidden="true">
							<?php echo $ansp_photo_html; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- built above from wp_get_attachment_image() or an esc_url()'d sprintf. ?>
						</a>
					<?php else : ?>
						<?php echo $ansp_photo_html; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- as above. ?>
					<?php endif; ?>
				<?php else : ?>
					<div class="ansp-roster-photo ansp-roster-photo--placeholder" aria-hidden="true">
						<span><?php echo esc_html( mb_substr( $ansp_name, 0, 1 ) ); ?></span>
					</div>
				<?php endif; ?>
				<h4 class="ansp-roster-name">
					<?php if ( $ansp_bio_link ) : ?>
						<a class="ansp-roster-name-link" href="<?php echo esc_url( $ansp_bio_link ); ?>"><?php echo esc_html( $ansp_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $ansp_name ); ?>
					<?php endif; ?>
				</h4>
				<?php if ( ! empty( $ansp_groups ) ) : ?>
					<p class="ansp-badges">
						<?php foreach ( $ansp_groups as $ansp_group_name ) : ?>
							<span class="ansp-badge"><?php echo esc_html( $ansp_group_name ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $ansp_fields ) ) : ?>
					<dl class="ansp-roster-fields">
						<?php foreach ( $ansp_fields as $ansp_key => $ansp_field ) : ?>
							<div class="ansp-roster-field">
								<dt><?php echo esc_html( $ansp_field['label'] ); ?></dt>
								<dd>
									<?php if ( 'email' === $ansp_field['type'] ) : ?>
										<a href="<?php echo esc_url( 'mailto:' . $ansp_field['value'] ); ?>"><?php echo esc_html( $ansp_field['value'] ); ?></a>
									<?php elseif ( 'tel' === $ansp_field['type'] ) : ?>
										<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $ansp_field['value'] ) ); ?>"><?php echo esc_html( $ansp_field['value'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $ansp_field['value'] ); ?>
									<?php endif; ?>
								</dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
				<?php if ( '' !== $ansp_bio ) : ?>
					<div class="ansp-roster-bio">
						<?php
						/*
						 * wp_strip_all_tags() before wpautop(): a bio pasted from
						 * Word can arrive carrying headings, lists and spans, and
						 * a stray <h2> inside a clamped four-line block makes one
						 * card three times the height of its neighbours. Plain
						 * paragraphs are what a teaser needs.
						 */
						echo wp_kses_post( wpautop( wp_strip_all_tags( $ansp_bio ) ) );
						?>
					</div>
				<?php endif; ?>
				<?php if ( $ansp_bio_link ) : ?>
					<p class="ansp-roster-more">
						<a href="<?php echo esc_url( $ansp_bio_link ); ?>">
							<?php
							if ( '' !== $ansp_bio ) {
								/* translators: %s: singer's name */
								printf( esc_html__( 'Read more about %s', 'ans-singers-portal' ), esc_html( $ansp_name ) );
							} else {
								esc_html_e( 'View full profile', 'ans-singers-portal' );
							}
							?>
							&rarr;
						</a>
					</p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
