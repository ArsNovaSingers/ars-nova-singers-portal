<?php
/**
 * My Bio tab: front-end editor for the logged-in singer's own profile.
 *
 * Posts (multipart) to admin-post.php → ANSP_Bio_Editor::handle_save().
 * Every field maps to CANONICAL storage (v1.1.0): bio → post content,
 * headshot → Featured Image, voice parts → `parts`, years →
 * `years_with_group`, favorite piece/quote → `favorite_piece` /
 * `favorite_quote`, pronouns → `pronouns`, contact → ansp_email/ansp_phone.
 * Includes the Gemini "Compose with AI" helper next to the bio field.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$ansp_user_id = get_current_user_id();
$ansp_profile = ANSP_Profiles::get_profile_for_user( $ansp_user_id );

// Status notice from a previous save (whitelisted codes only).
if ( isset( $_GET['ansp_bio'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
	$ansp_status = ANSP_Bio_Editor::status_message( sanitize_key( wp_unslash( $_GET['ansp_bio'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $ansp_status ) :
		?>
		<div class="ansp-notice ansp-notice--<?php echo esc_attr( $ansp_status[0] ); ?>"><?php echo esc_html( $ansp_status[1] ); ?></div>
		<?php
	endif;
endif;
?>

<h3 class="ansp-section-title"><?php esc_html_e( 'My Bio', 'ans-singers-portal' ); ?></h3>

<?php if ( ! $ansp_profile instanceof WP_Post ) : ?>
	<p class="ansp-empty">
		<?php esc_html_e( 'Your account is not linked to a singer profile yet. Please contact the Personnel Manager and we will set you up.', 'ans-singers-portal' ); ?>
	</p>
<?php else : ?>
	<?php
	$ansp_profile_id = (int) $ansp_profile->ID;
	$ansp_privacy    = ANSP_Profiles::get_privacy( $ansp_profile_id );
	$ansp_headshot   = ANSP_Profiles::get_headshot_url( $ansp_profile_id );
	$ansp_parts      = ANSP_Singer_CPT::get_parts( $ansp_profile_id );
	$ansp_years      = get_post_meta( $ansp_profile_id, 'years_with_group', true );
	$ansp_fav        = (string) get_post_meta( $ansp_profile_id, 'favorite_piece', true );
	$ansp_quote      = (string) get_post_meta( $ansp_profile_id, 'favorite_quote', true );
	$ansp_pronouns   = (string) get_post_meta( $ansp_profile_id, 'pronouns', true );
	$ansp_bio_text   = (string) $ansp_profile->post_content;
	?>
	<form class="ansp-bio-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="ansp_save_bio" />
		<?php wp_nonce_field( 'ansp_save_bio', 'ansp_bio_nonce' ); ?>

		<div class="ansp-bio-headshot">
			<?php if ( $ansp_headshot ) : ?>
				<img class="ansp-headshot" src="<?php echo esc_url( $ansp_headshot ); ?>" alt="<?php echo esc_attr( get_the_title( $ansp_profile ) ); ?>" loading="lazy" />
			<?php else : ?>
				<div class="ansp-headshot ansp-headshot--placeholder" aria-hidden="true"></div>
			<?php endif; ?>
			<label class="ansp-field">
				<span class="ansp-field-label"><?php esc_html_e( 'Headshot (JPG/PNG, required)', 'ans-singers-portal' ); ?> <em>*</em></span>
				<input type="file" name="ansp_headshot" accept="image/jpeg,image/png,image/gif,image/webp" />
			</label>
		</div>

		<label class="ansp-field">
			<span class="ansp-field-label"><?php esc_html_e( 'Display name', 'ans-singers-portal' ); ?> <em>*</em></span>
			<input type="text" name="ansp_display_name" required value="<?php echo esc_attr( get_the_title( $ansp_profile ) ); ?>" />
		</label>

		<fieldset class="ansp-field ansp-field--parts">
			<legend class="ansp-field-label"><?php esc_html_e( 'Voice part(s)', 'ans-singers-portal' ); ?> <em>*</em></legend>
			<?php foreach ( ansp_voice_part_options() as $ansp_part_option ) : ?>
				<label class="ansp-inline-check">
					<input type="checkbox" name="ans_parts[]" value="<?php echo esc_attr( $ansp_part_option ); ?>" <?php checked( in_array( $ansp_part_option, $ansp_parts, true ) ); ?> />
					<?php echo esc_html( $ansp_part_option ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<?php foreach ( ANSP_Profiles::fields() as $ansp_key => $ansp_field ) : ?>
			<?php
			$ansp_value    = ANSP_Profiles::get_field( $ansp_profile_id, $ansp_key );
			$ansp_required = ( 'email' === $ansp_key );
			?>
			<div class="ansp-field">
				<label>
					<span class="ansp-field-label">
						<?php echo esc_html( $ansp_field['label'] ); ?>
						<?php if ( $ansp_required ) : ?><em>*</em><?php endif; ?>
					</span>
					<input
						type="<?php echo esc_attr( $ansp_field['type'] ); ?>"
						name="ansp_field_<?php echo esc_attr( $ansp_key ); ?>"
						value="<?php echo esc_attr( $ansp_value ); ?>"
						<?php echo $ansp_required ? 'required' : ''; ?>
					/>
				</label>
				<?php if ( ! empty( $ansp_field['private_toggle'] ) ) : ?>
					<label class="ansp-privacy-toggle">
						<input type="checkbox" name="ansp_privacy[<?php echo esc_attr( $ansp_key ); ?>]" value="1" <?php checked( ! empty( $ansp_privacy[ $ansp_key ] ) ); ?> />
						<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
					</label>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<div class="ansp-field">
			<label>
				<span class="ansp-field-label"><?php esc_html_e( 'Pronouns', 'ans-singers-portal' ); ?></span>
				<?php
				/*
				 * A datalist, deliberately NOT a <select>. The common choices
				 * are one click away, but the field still accepts anything
				 * typed — a fixed list would silently exclude anyone whose
				 * pronouns are not on it, which is the one field where that
				 * matters most.
				 */
				?>
				<input type="text" name="ansp_pronouns" list="ansp-pronoun-options" value="<?php echo esc_attr( $ansp_pronouns ); ?>" autocomplete="off" />
				<datalist id="ansp-pronoun-options">
					<?php foreach ( ansp_pronoun_suggestions() as $ansp_option ) : ?>
						<option value="<?php echo esc_attr( $ansp_option ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<span class="ansp-field-hint"><?php esc_html_e( 'Pick a common option or type your own. Leave blank to show none.', 'ans-singers-portal' ); ?></span>
			</label>
			<label class="ansp-privacy-toggle">
				<input type="checkbox" name="ansp_privacy[pronouns]" value="1" <?php checked( ! empty( $ansp_privacy['pronouns'] ) ); ?> />
				<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
			</label>
		</div>

		<div class="ansp-field">
			<label>
				<span class="ansp-field-label"><?php esc_html_e( 'Years with the group', 'ans-singers-portal' ); ?></span>
				<input type="number" min="0" name="ans_years" value="<?php echo esc_attr( '' === $ansp_years ? '' : (string) absint( $ansp_years ) ); ?>" />
			</label>
			<label class="ansp-privacy-toggle">
				<input type="checkbox" name="ansp_privacy[years]" value="1" <?php checked( ! empty( $ansp_privacy['years'] ) ); ?> />
				<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
			</label>
		</div>

		<div class="ansp-field">
			<label>
				<span class="ansp-field-label"><?php esc_html_e( 'Favorite piece', 'ans-singers-portal' ); ?></span>
				<input type="text" name="ans_fav" value="<?php echo esc_attr( $ansp_fav ); ?>" />
			</label>
			<label class="ansp-privacy-toggle">
				<input type="checkbox" name="ansp_privacy[favorite_piece]" value="1" <?php checked( ! empty( $ansp_privacy['favorite_piece'] ) ); ?> />
				<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
			</label>
		</div>

		<div class="ansp-field">
			<label>
				<span class="ansp-field-label"><?php esc_html_e( 'Favorite quote', 'ans-singers-portal' ); ?></span>
				<textarea name="ans_quote" rows="2"><?php echo esc_textarea( $ansp_quote ); ?></textarea>
			</label>
			<label class="ansp-privacy-toggle">
				<input type="checkbox" name="ansp_privacy[favorite_quote]" value="1" <?php checked( ! empty( $ansp_privacy['favorite_quote'] ) ); ?> />
				<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
			</label>
		</div>

		<div class="ansp-field ansp-field--bio">
			<label>
				<span class="ansp-field-label"><?php esc_html_e( 'Bio', 'ans-singers-portal' ); ?></span>
				<textarea id="ansp_bio" name="ansp_bio" rows="6"><?php echo esc_textarea( $ansp_bio_text ); ?></textarea>
			</label>

			<div class="ansp-ai-compose">
				<label class="ansp-field">
					<span class="ansp-field-label"><?php esc_html_e( 'Notes for the AI (optional — e.g. day job, hometown, how long you\'ve sung, fun fact)', 'ans-singers-portal' ); ?></span>
					<input type="text" id="ansp-ai-notes" placeholder="<?php esc_attr_e( 'e.g. "science teacher, loves Byrd, joined in 2019, two cats"', 'ans-singers-portal' ); ?>" />
				</label>
				<button type="button" class="ansp-btn ansp-btn--secondary" id="ansp-ai-compose">
					<span class="ansp-ai-spinner" aria-hidden="true" hidden></span>
					<?php esc_html_e( 'Compose with AI', 'ans-singers-portal' ); ?>
				</button>
				<span class="ansp-ai-status" id="ansp-ai-status" role="status" aria-live="polite"></span>
			</div>

			<label class="ansp-privacy-toggle">
				<input type="checkbox" name="ansp_privacy[bio]" value="1" <?php checked( ! empty( $ansp_privacy['bio'] ) ); ?> />
				<?php esc_html_e( 'Visible to choir on the roster', 'ans-singers-portal' ); ?>
			</label>
		</div>

		<p class="ansp-form-actions">
			<button type="submit" class="ansp-btn"><?php esc_html_e( 'Save my bio', 'ans-singers-portal' ); ?></button>
		</p>
	</form>
<?php endif; ?>
