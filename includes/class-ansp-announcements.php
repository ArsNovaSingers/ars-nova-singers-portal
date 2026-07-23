<?php
/**
 * Announcements: a lightweight, group-scoped CPT (ans_announcement).
 *
 * Announcements appear on the portal Home tab. Each one is either visible
 * to ALL portal members (meta ansp_all = 1) or scoped to the ans_group
 * terms assigned to it. Visibility is resolved through the same
 * ANSP_Permissions engine used for materials.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Announcements
 */
class ANSP_Announcements {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'ans_announcement';

	/**
	 * Hook registration, meta box and save handler.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 10 );
		add_action( 'init', array( __CLASS__, 'attach_group_taxonomy' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the announcements CPT (not public; portal + dashboard only).
	 *
	 * @return void
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( 'Announcements', 'ans-singers-portal' ),
				'labels'       => array(
					'name'          => __( 'Announcements', 'ans-singers-portal' ),
					'singular_name' => __( 'Announcement', 'ans-singers-portal' ),
					'add_new_item'  => __( 'Add New Announcement', 'ans-singers-portal' ),
					'edit_item'     => __( 'Edit Announcement', 'ans-singers-portal' ),
					'not_found'     => __( 'No announcements found.', 'ans-singers-portal' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'ansp-dashboard',
				'show_in_rest' => false,
				'has_archive'  => false,
				'rewrite'      => false,
				'supports'     => array( 'title', 'editor' ),
				// Uses standard post caps: our AD/PM roles hold edit_posts etc.
				'capability_type' => 'post',
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * Allow group terms on announcements (for scoping).
	 *
	 * @return void
	 */
	public static function attach_group_taxonomy() {
		if ( taxonomy_exists( 'ans_group' ) && post_type_exists( self::POST_TYPE ) ) {
			register_taxonomy_for_object_type( 'ans_group', self::POST_TYPE );
		}
	}

	/**
	 * Register the audience meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'ansp_announcement_audience',
			__( 'Audience', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render ALL + group checkboxes.
	 *
	 * @param WP_Post $post Announcement being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_announcement', 'ansp_announcement_nonce' );

		$all            = '1' === get_post_meta( $post->ID, 'ansp_all', true );
		$group_choices  = ANSP_Taxonomies::get_group_choices();
		$current_groups = wp_get_object_terms( $post->ID, 'ans_group', array( 'fields' => 'slugs' ) );
		$current_groups = is_wp_error( $current_groups ) ? array() : (array) $current_groups;
		?>
		<p>
			<label>
				<input type="checkbox" name="ansp_announcement_all" value="1" <?php checked( $all ); ?> />
				<strong><?php esc_html_e( 'ALL portal members', 'ans-singers-portal' ); ?></strong>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Or scope to specific groups:', 'ans-singers-portal' ); ?></p>
		<?php foreach ( $group_choices as $slug => $label ) : ?>
			<p>
				<label>
					<input type="checkbox" name="ansp_announcement_groups[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $current_groups, true ) ); ?> />
					<?php echo esc_html( $label ); ?>
				</label>
			</p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Nonce-verified save handler for the audience settings.
	 *
	 * @param int     $post_id Announcement ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_announcement_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_announcement_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_save_announcement' ) ) {
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

		if ( ! empty( $_POST['ansp_announcement_all'] ) ) {
			update_post_meta( $post_id, 'ansp_all', '1' );
		} else {
			delete_post_meta( $post_id, 'ansp_all' );
		}

		$term_ids = array();
		if ( ! empty( $_POST['ansp_announcement_groups'] ) && is_array( $_POST['ansp_announcement_groups'] ) ) {
			foreach ( wp_unslash( (array) $_POST['ansp_announcement_groups'] ) as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised next line.
				$slug = sanitize_title( (string) $slug );
				$term = $slug ? get_term_by( 'slug', $slug, 'ans_group' ) : false;
				if ( $term instanceof WP_Term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
		}
		if ( taxonomy_exists( 'ans_group' ) ) {
			wp_set_object_terms( $post_id, $term_ids, 'ans_group', false );
		}
	}

	/**
	 * The announcements a user may see, newest first.
	 *
	 * @param int|null $user_id Viewer.
	 * @param int      $limit   Max number to return.
	 * @return WP_Post[]
	 */
	public static function get_visible( $user_id = null, $limit = 20 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ) * 3, // Over-fetch, then filter by permission.
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$visible = array();
		foreach ( $query->posts as $post ) {
			$slugs = wp_get_object_terms( $post->ID, 'ans_group', array( 'fields' => 'slugs' ) );
			$slugs = is_wp_error( $slugs ) ? array() : (array) $slugs;
			$item  = array(
				'permission' => array(
					// Untargeted announcements default to ALL.
					'all'    => ( '1' === get_post_meta( $post->ID, 'ansp_all', true ) ) || empty( $slugs ),
					'groups' => $slugs,
					'emails' => array(),
				),
			);
			if ( ANSP_Permissions::user_can_see( $item, $user_id ) ) {
				$visible[] = $post;
			}
			if ( count( $visible ) >= $limit ) {
				break;
			}
		}
		return $visible;
	}
}
