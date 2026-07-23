<?php
/**
 * Project details meta box for ans_project.
 *
 * Native fields (no ACF): start/end dates, venue, description, brief link,
 * status (active/archived), plus the Group checkboxes (ans_group) and a
 * Season select (ans_season) — we disabled the default taxonomy meta boxes
 * so everything lives in one tidy panel. A read-only RSVP summary is shown
 * at the bottom.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Project_Meta
 */
class ANSP_Project_Meta {

	/**
	 * Hook the meta box + save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Valid project statuses (value => label).
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'active'   => __( 'Active (shows in Season Materials)', 'ans-singers-portal' ),
			'archived' => __( 'Archived (shows in Past Projects)', 'ans-singers-portal' ),
		);
	}

	/**
	 * Convenience getter for a project meta value.
	 *
	 * @param int    $post_id Project ID.
	 * @param string $key     Un-prefixed key (e.g. 'venue').
	 * @return string
	 */
	public static function get( $post_id, $key ) {
		return (string) get_post_meta( (int) $post_id, 'ansp_project_' . $key, true );
	}

	/**
	 * Is this project archived?
	 *
	 * @param int $post_id Project ID.
	 * @return bool
	 */
	public static function is_archived( $post_id ) {
		return 'archived' === self::get( $post_id, 'status' );
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'ansp_project_details',
			__( 'Project Details', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			ANSP_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the details form.
	 *
	 * @param WP_Post $post Current project.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_project_meta', 'ansp_project_meta_nonce' );

		$date_start  = self::get( $post->ID, 'date_start' );
		$date_end    = self::get( $post->ID, 'date_end' );
		$venue       = self::get( $post->ID, 'venue' );
		$description = self::get( $post->ID, 'description' );
		$brief_url   = self::get( $post->ID, 'brief_url' );
		$status      = self::get( $post->ID, 'status' );
		$status      = array_key_exists( $status, self::statuses() ) ? $status : 'active';

		$group_choices  = ANSP_Taxonomies::get_group_choices();
		$current_groups = wp_get_object_terms( $post->ID, 'ans_group', array( 'fields' => 'slugs' ) );
		$current_groups = is_wp_error( $current_groups ) ? array() : (array) $current_groups;

		$seasons        = get_terms(
			array(
				'taxonomy'   => 'ans_season',
				'hide_empty' => false,
			)
		);
		$seasons        = is_wp_error( $seasons ) ? array() : $seasons;
		$current_season = wp_get_object_terms( $post->ID, 'ans_season', array( 'fields' => 'ids' ) );
		$current_season = ( ! is_wp_error( $current_season ) && ! empty( $current_season ) ) ? (int) $current_season[0] : 0;
		?>
		<table class="form-table ansp-project-meta">
			<tr>
				<th scope="row"><label for="ansp_project_date_start"><?php esc_html_e( 'Start date', 'ans-singers-portal' ); ?></label></th>
				<td><input type="date" id="ansp_project_date_start" name="ansp_project_date_start" value="<?php echo esc_attr( $date_start ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_date_end"><?php esc_html_e( 'End date', 'ans-singers-portal' ); ?></label></th>
				<td><input type="date" id="ansp_project_date_end" name="ansp_project_date_end" value="<?php echo esc_attr( $date_end ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_venue"><?php esc_html_e( 'Venue', 'ans-singers-portal' ); ?></label></th>
				<td><input type="text" class="regular-text" id="ansp_project_venue" name="ansp_project_venue" value="<?php echo esc_attr( $venue ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_description"><?php esc_html_e( 'Description', 'ans-singers-portal' ); ?></label></th>
				<td><textarea class="large-text" rows="4" id="ansp_project_description" name="ansp_project_description"><?php echo esc_textarea( $description ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_brief_url"><?php esc_html_e( 'Project brief link', 'ans-singers-portal' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="ansp_project_brief_url" name="ansp_project_brief_url" value="<?php echo esc_url( $brief_url ); ?>" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Google Doc / Drive link to the project brief.', 'ans-singers-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_status"><?php esc_html_e( 'Status', 'ans-singers-portal' ); ?></label></th>
				<td>
					<select id="ansp_project_status" name="ansp_project_status">
						<?php foreach ( self::statuses() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Groups', 'ans-singers-portal' ); ?></th>
				<td>
					<?php foreach ( $group_choices as $slug => $label ) : ?>
						<label class="ansp-inline-check">
							<input type="checkbox" name="ansp_project_groups[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $current_groups, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Which groups this project belongs to. Singers see the project when their group matches (or when a material inside is shared with them).', 'ans-singers-portal' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ansp_project_season"><?php esc_html_e( 'Season', 'ans-singers-portal' ); ?></label></th>
				<td>
					<select id="ansp_project_season" name="ansp_project_season">
						<option value="0"><?php esc_html_e( '— No season —', 'ans-singers-portal' ); ?></option>
						<?php foreach ( $seasons as $season ) : ?>
							<option value="<?php echo esc_attr( (string) $season->term_id ); ?>" <?php selected( $current_season, (int) $season->term_id ); ?>><?php echo esc_html( $season->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'RSVPs', 'ans-singers-portal' ); ?></th>
				<td>
					<?php
					$counts = ANSP_RSVP::get_counts( $post->ID );
					printf(
						/* translators: 1: yes count, 2: maybe count, 3: no count */
						esc_html__( 'Yes: %1$d · Maybe: %2$d · No: %3$d', 'ans-singers-portal' ),
						(int) $counts['yes'],
						(int) $counts['maybe'],
						(int) $counts['no']
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Nonce-verified save handler for project details + terms.
	 *
	 * @param int     $post_id Project ID.
	 * @param WP_Post $post    Project post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_project_meta_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_project_meta_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_project_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// ---- Scalar meta --------------------------------------------------
		$text_fields = array(
			'date_start' => 'sanitize_text_field',
			'date_end'   => 'sanitize_text_field',
			'venue'      => 'sanitize_text_field',
		);
		foreach ( $text_fields as $key => $sanitizer ) {
			$field = 'ansp_project_' . $key;
			$value = isset( $_POST[ $field ] ) ? call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) ) : '';
			if ( in_array( $key, array( 'date_start', 'date_end' ), true ) && $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$value = ''; // Only accept YYYY-MM-DD.
			}
			if ( '' !== $value ) {
				update_post_meta( $post_id, $field, $value );
			} else {
				delete_post_meta( $post_id, $field );
			}
		}

		$description = isset( $_POST['ansp_project_description'] ) ? wp_kses_post( wp_unslash( $_POST['ansp_project_description'] ) ) : '';
		if ( '' !== $description ) {
			update_post_meta( $post_id, 'ansp_project_description', $description );
		} else {
			delete_post_meta( $post_id, 'ansp_project_description' );
		}

		$brief = isset( $_POST['ansp_project_brief_url'] ) ? esc_url_raw( wp_unslash( $_POST['ansp_project_brief_url'] ) ) : '';
		if ( '' !== $brief ) {
			update_post_meta( $post_id, 'ansp_project_brief_url', $brief );
		} else {
			delete_post_meta( $post_id, 'ansp_project_brief_url' );
		}

		$status = isset( $_POST['ansp_project_status'] ) ? sanitize_key( wp_unslash( $_POST['ansp_project_status'] ) ) : 'active';
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			$status = 'active';
		}
		update_post_meta( $post_id, 'ansp_project_status', $status );

		// ---- Groups (ans_group) ------------------------------------------
		$slugs = array();
		if ( ! empty( $_POST['ansp_project_groups'] ) && is_array( $_POST['ansp_project_groups'] ) ) {
			foreach ( wp_unslash( (array) $_POST['ansp_project_groups'] ) as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised next line.
				$slugs[] = sanitize_title( (string) $slug );
			}
		}
		$term_ids = array();
		foreach ( array_filter( $slugs ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'ans_group' );
			if ( $term instanceof WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		wp_set_object_terms( $post_id, $term_ids, 'ans_group', false );

		// ---- Season (ans_season) -----------------------------------------
		$season_id = isset( $_POST['ansp_project_season'] ) ? absint( $_POST['ansp_project_season'] ) : 0;
		if ( $season_id && get_term( $season_id, 'ans_season' ) instanceof WP_Term ) {
			wp_set_object_terms( $post_id, array( $season_id ), 'ans_season', false );
		} else {
			wp_set_object_terms( $post_id, array(), 'ans_season', false );
		}
	}
}
