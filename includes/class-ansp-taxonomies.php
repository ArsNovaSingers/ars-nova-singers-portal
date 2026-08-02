<?php
/**
 * Taxonomies: ans_group (Groups) and ans_season (Seasons).
 *
 * - ans_group is attached to the EXISTING third-party "singer" CPT and to
 *   our ans_project CPT (and, additionally, to ans_announcement so
 *   announcements can be group-scoped).
 * - ans_season is attached to ans_project only. Each season term carries a
 *   "Season brief" URL in term meta (ansp_brief_url).
 * - Seeds the four default groups idempotently on activation.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Taxonomies
 */
class ANSP_Taxonomies {

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 5 );
		// Late attach: the "singer" CPT is registered by another plugin whose
		// load order we do not control, so we retry at init:20 and whenever a
		// post type registers.
		add_action( 'init', array( __CLASS__, 'attach_to_singer' ), 20 );
		add_action( 'registered_post_type', array( __CLASS__, 'maybe_attach_on_register' ), 10, 1 );

		// Season "brief" URL term-meta field.
		add_action( 'ans_season_add_form_fields', array( __CLASS__, 'render_add_brief_field' ) );
		add_action( 'ans_season_edit_form_fields', array( __CLASS__, 'render_edit_brief_field' ), 10, 1 );
		add_action( 'created_ans_season', array( __CLASS__, 'save_brief_field' ) );
		add_action( 'edited_ans_season', array( __CLASS__, 'save_brief_field' ) );
	}

	/**
	 * Default group slugs => labels, seeded on activation.
	 *
	 * @return array<string,string>
	 */
	public static function default_groups() {
		return array(
			'main'           => __( 'Main Group', 'ans-singers-portal' ),
			'small'          => __( 'Small Group', 'ans-singers-portal' ),
			'friday'         => __( 'Friday Group', 'ans-singers-portal' ),
			'special-guests' => __( 'Special Guests', 'ans-singers-portal' ),
		);
	}

	/**
	 * Register ans_group + ans_season.
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		$tax_caps = array(
			'manage_terms' => 'ansp_manage_portal',
			'edit_terms'   => 'ansp_manage_portal',
			'delete_terms' => 'ansp_manage_portal',
			'assign_terms' => 'edit_posts',
		);

		if ( ! taxonomy_exists( 'ans_group' ) ) {
			// Attach to "singer" only when it already exists at this point;
			// attach_to_singer() covers the case where it registers later.
			$group_objects = array( 'ans_project' );
			if ( post_type_exists( 'singer' ) ) {
				$group_objects[] = 'singer';
			}

			register_taxonomy(
				'ans_group',
				$group_objects,
				array(
					'label'             => __( 'Groups', 'ans-singers-portal' ),
					'labels'            => array(
						'name'          => __( 'Groups', 'ans-singers-portal' ),
						'singular_name' => __( 'Group', 'ans-singers-portal' ),
						'menu_name'     => __( 'Groups', 'ans-singers-portal' ),
						'add_new_item'  => __( 'Add New Group', 'ans-singers-portal' ),
						'edit_item'     => __( 'Edit Group', 'ans-singers-portal' ),
					),
					'public'            => false,
					'show_ui'           => true,
					'show_in_menu'      => false, // Surfaced via the Singers Portal dashboard.
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'hierarchical'      => true,
					'rewrite'           => false,
					'meta_box_cb'       => false, // We render our own group UIs.
					// Suppress WordPress's own taxonomy checklist in Quick Edit:
					// ANSP_Singer_Admin renders its own group checkboxes, and
					// two competing group checklists in one panel is worse
					// than either alone.
					'show_in_quick_edit' => false,
					'capabilities'      => $tax_caps,
				)
			);
		}

		if ( ! taxonomy_exists( 'ans_season' ) ) {
			register_taxonomy(
				'ans_season',
				array( 'ans_project' ),
				array(
					'label'             => __( 'Seasons', 'ans-singers-portal' ),
					'labels'            => array(
						'name'          => __( 'Seasons', 'ans-singers-portal' ),
						'singular_name' => __( 'Season', 'ans-singers-portal' ),
						'menu_name'     => __( 'Seasons', 'ans-singers-portal' ),
						'add_new_item'  => __( 'Add New Season', 'ans-singers-portal' ),
						'edit_item'     => __( 'Edit Season', 'ans-singers-portal' ),
					),
					'public'            => false,
					'show_ui'           => true,
					'show_in_menu'      => false,
					'show_admin_column' => true,
					'show_in_rest'      => false,
					'hierarchical'      => true,
					'rewrite'           => false,
					'meta_box_cb'       => false,
					'capabilities'      => $tax_caps,
				)
			);
		}

	}

	/**
	 * Attach ans_group to the third-party "singer" CPT once it exists.
	 * Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function attach_to_singer() {
		if ( taxonomy_exists( 'ans_group' ) && post_type_exists( 'singer' ) ) {
			register_taxonomy_for_object_type( 'ans_group', 'singer' );
		}
	}

	/**
	 * registered_post_type callback: catch the moment "singer" registers.
	 *
	 * @param string $post_type The post type just registered.
	 * @return void
	 */
	public static function maybe_attach_on_register( $post_type ) {
		if ( 'singer' === $post_type ) {
			self::attach_to_singer();
		}
	}

	/**
	 * Seed the four default groups. Idempotent — existing terms are kept.
	 *
	 * @return void
	 */
	public static function seed_default_groups() {
		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return;
		}
		foreach ( self::default_groups() as $slug => $label ) {
			if ( ! term_exists( $slug, 'ans_group' ) ) {
				wp_insert_term( $label, 'ans_group', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * All group terms as slug => name (falls back to the seed list before
	 * the taxonomy has terms, e.g. on a brand-new install mid-activation).
	 *
	 * @return array<string,string>
	 */
	public static function get_group_choices() {
		$choices = array();
		if ( taxonomy_exists( 'ans_group' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'ans_group',
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$choices[ $term->slug ] = $term->name;
				}
			}
		}
		if ( empty( $choices ) ) {
			$choices = self::default_groups();
		}
		return $choices;
	}

	/**
	 * The current season term, from the ansp_current_season option with a
	 * fallback to the most recently created season.
	 *
	 * @return WP_Term|null
	 */
	public static function get_current_season() {
		$term_id = (int) get_option( 'ansp_current_season', 0 );
		if ( $term_id ) {
			$term = get_term( $term_id, 'ans_season' );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_season',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
				'number'     => 1,
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return $terms[0];
		}
		return null;
	}

	/**
	 * Season brief URL for a season term.
	 *
	 * @param int $term_id Season term ID.
	 * @return string
	 */
	public static function get_season_brief_url( $term_id ) {
		return (string) get_term_meta( (int) $term_id, 'ansp_brief_url', true );
	}

	/**
	 * "Add season" screen field for the brief URL.
	 *
	 * @return void
	 */
	public static function render_add_brief_field() {
		?>
		<div class="form-field">
			<label for="ansp_brief_url"><?php esc_html_e( 'Season brief URL', 'ans-singers-portal' ); ?></label>
			<input type="url" name="ansp_brief_url" id="ansp_brief_url" value="" placeholder="https://" />
			<p class="description"><?php esc_html_e( 'Link to the season brief (Google Doc/Drive). Shown at the top of the Season Materials tab.', 'ans-singers-portal' ); ?></p>
		</div>
		<?php
	}

	/**
	 * "Edit season" screen field for the brief URL.
	 *
	 * @param WP_Term $term Season term being edited.
	 * @return void
	 */
	public static function render_edit_brief_field( $term ) {
		$url = self::get_season_brief_url( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><label for="ansp_brief_url"><?php esc_html_e( 'Season brief URL', 'ans-singers-portal' ); ?></label></th>
			<td>
				<input type="url" name="ansp_brief_url" id="ansp_brief_url" value="<?php echo esc_url( $url ); ?>" placeholder="https://" />
				<p class="description"><?php esc_html_e( 'Link to the season brief (Google Doc/Drive). Shown at the top of the Season Materials tab.', 'ans-singers-portal' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the brief URL for a season term. Term forms carry core nonces
	 * verified upstream; we additionally require the manage capability.
	 *
	 * @param int $term_id Term ID being saved.
	 * @return void
	 */
	public static function save_brief_field( $term_id ) {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['ansp_brief_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core term nonce verified by edit-tags.php.
			$url = esc_url_raw( wp_unslash( $_POST['ansp_brief_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $url ) {
				update_term_meta( $term_id, 'ansp_brief_url', $url );
			} else {
				delete_term_meta( $term_id, 'ansp_brief_url' );
			}
		}
	}
}
