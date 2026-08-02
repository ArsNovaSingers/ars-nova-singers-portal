<?php
/**
 * Absorbed "Ars Nova Singer Directory" functionality (v1.1.0).
 *
 * The Portal now OWNS the `singer` custom post type, its meta, the admin
 * "Singer Profile Details" meta box, the Voice Part admin column and the
 * public bio page — ported verbatim (same slugs, meta keys, nonce action
 * and field names) from the old standalone Directory plugin so no data
 * migration is ever needed.
 *
 * TRANSITION GUARD: every piece of absorbed behaviour is skipped while the
 * old Directory plugin is still active (detected via
 * function_exists( 'ans_register_singer_cpt' )). The moment the old plugin
 * is deactivated, this class takes over seamlessly. The `singer` CPT is
 * never registered twice.
 *
 * Canonical meta keys (unchanged from the Directory plugin):
 * - parts            (array of voice parts)
 * - years_with_group (int)
 * - favorite_piece   (string)
 * - favorite_quote   (string)
 * - pronouns         (string)
 * - profession       (string)
 * - voice_part       (string, legacy single-value field)
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical voice part options (identical to the old Directory plugin's
 * ans_singer_part_options()).
 *
 * @return string[]
 */
function ansp_voice_part_options() {
	return array( 'Soprano', 'Mezzo-Soprano', 'Alto', 'Countertenor', 'Tenor', 'Baritone', 'Bass' );
}

/**
 * Common pronoun choices, offered as SUGGESTIONS only.
 *
 * Rendered as a <datalist>, never a <select>. The field must keep accepting
 * free text: a closed list would silently exclude anyone whose pronouns are
 * not on it, and this is the one field where getting that wrong is personal
 * rather than merely inconvenient.
 *
 * Seeded from the values singers have actually entered on this site, so the
 * list matches the choir rather than a generic template.
 *
 * @return string[]
 */
function ansp_pronoun_suggestions() {
	return apply_filters(
		'ansp_pronoun_suggestions',
		array(
			'She/Her',
			'She/Her/Hers',
			'He/Him',
			'He/Him/His',
			'They/Them',
			'They/Them/Theirs',
			'She/They',
			'He/They',
			'Any pronouns',
		)
	);
}

/**
 * Is the OLD standalone "Ars Nova Singer Directory" plugin still active?
 *
 * @return bool
 */
function ansp_legacy_directory_active() {
	return function_exists( 'ans_register_singer_cpt' );
}

/**
 * Class ANSP_Singer_CPT
 */
class ANSP_Singer_CPT {

	/**
	 * Hook everything — but ONLY when the old Directory plugin is inactive.
	 * (Constructed on plugins_loaded, so all plugins' functions exist by now.)
	 */
	public function __construct() {
		if ( ansp_legacy_directory_active() ) {
			return; // Old plugin still owns the singer CPT — stand down.
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 6 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_singer', array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_singer_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_singer_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'bio_page_content' ), 20 );
		add_action( 'wp_head', array( $this, 'bio_page_css' ) );
	}

	/**
	 * Register the `singer` CPT — identical args to the old Directory plugin.
	 * Never registers twice (guarded against the old plugin AND any other
	 * registrant).
	 *
	 * @return void
	 */
	public static function register_post_type() {
		if ( ansp_legacy_directory_active() || post_type_exists( 'singer' ) ) {
			return;
		}

		register_post_type(
			'singer',
			array(
				'labels'          => array(
					'name'          => __( 'Singers', 'ans-singers-portal' ),
					'singular_name' => __( 'Singer', 'ans-singers-portal' ),
					'menu_name'     => __( 'Singers', 'ans-singers-portal' ),
					'add_new'       => __( 'Add Singer', 'ans-singers-portal' ),
					'add_new_item'  => __( 'Add Singer', 'ans-singers-portal' ),
					'edit_item'     => __( 'Edit Singer Profile', 'ans-singers-portal' ),
					'new_item'      => __( 'New Singer', 'ans-singers-portal' ),
					'view_item'     => __( 'View Profile', 'ans-singers-portal' ),
					'all_items'     => __( 'All Singers', 'ans-singers-portal' ),
					'search_items'  => __( 'Search Singers', 'ans-singers-portal' ),
					'not_found'     => __( 'No singers found.', 'ans-singers-portal' ),
				),
				'public'          => true,
				'has_archive'     => true,
				'menu_icon'       => 'dashicons-groups',
				'menu_position'   => 22,
				'show_in_menu'    => false, // No standalone "Singers" top-level menu; reached via the "Singers Portal" dashboard.
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author', 'custom-fields' ),
				'show_in_rest'    => true,
				'rest_base'       => 'singers',
				'rewrite'         => array( 'slug' => 'singers' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Register the canonical singer meta (same keys as the Directory plugin).
	 *
	 * @return void
	 */
	public static function register_meta() {
		if ( ansp_legacy_directory_active() ) {
			return;
		}

		$auth = static function () {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta(
			'singer',
			'parts',
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'singer',
			'years_with_group',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);

		$string_keys = array( 'favorite_piece', 'favorite_quote', 'pronouns', 'profession', 'voice_part' );
		foreach ( $string_keys as $key ) {
			register_post_meta(
				'singer',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * The voice parts saved on a singer profile, with legacy fallback.
	 *
	 * @param int $post_id Singer profile ID.
	 * @return string[] Valid voice part names (possibly empty).
	 */
	public static function get_parts( $post_id ) {
		$parts = get_post_meta( (int) $post_id, 'parts', true );
		$valid = array();
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( in_array( (string) $part, ansp_voice_part_options(), true ) ) {
					$valid[] = (string) $part;
				}
			}
		}
		if ( $valid ) {
			return $valid;
		}
		// Legacy single-value field.
		$legacy = (string) get_post_meta( (int) $post_id, 'voice_part', true );
		return '' !== $legacy ? array( $legacy ) : array();
	}

	/**
	 * Display string for a profile's voice part(s) — "Soprano / Alto".
	 *
	 * @param int $post_id Singer profile ID.
	 * @return string
	 */
	public static function parts_display( $post_id ) {
		return implode( ' / ', self::get_parts( $post_id ) );
	}

	/**
	 * Register the absorbed "Singer Profile Details" meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		if ( ansp_legacy_directory_active() || ! post_type_exists( 'singer' ) ) {
			return;
		}
		add_meta_box(
			'ansp_singer_details',
			__( 'Singer Profile Details', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			'singer',
			'normal',
			'high'
		);
	}

	/**
	 * Render the details meta box — same field names as the old plugin
	 * (ans_parts[], ans_years, ans_fav, ans_quote / nonce ans_singer_nonce).
	 *
	 * @param WP_Post $post Singer profile being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ans_singer_save', 'ans_singer_nonce' );

		$parts = self::get_parts( $post->ID );
		$years = get_post_meta( $post->ID, 'years_with_group', true );
		$fav   = (string) get_post_meta( $post->ID, 'favorite_piece', true );
		$quote = (string) get_post_meta( $post->ID, 'favorite_quote', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Voice part(s)', 'ans-singers-portal' ); ?></th>
				<td>
					<?php foreach ( ansp_voice_part_options() as $option ) : ?>
						<label class="ansp-inline-check">
							<input type="checkbox" name="ans_parts[]" value="<?php echo esc_attr( $option ); ?>" <?php checked( in_array( $option, $parts, true ) ); ?> />
							<?php echo esc_html( $option ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ans_years"><?php esc_html_e( 'Years with the group', 'ans-singers-portal' ); ?></label></th>
				<td>
					<input type="number" min="0" id="ans_years" name="ans_years" value="<?php echo esc_attr( '' === $years ? '' : (string) absint( $years ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ans_fav"><?php esc_html_e( 'Favorite piece', 'ans-singers-portal' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="ans_fav" name="ans_fav" value="<?php echo esc_attr( $fav ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ans_quote"><?php esc_html_e( 'Favorite quote', 'ans-singers-portal' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="3" id="ans_quote" name="ans_quote"><?php echo esc_textarea( $quote ); ?></textarea>
				</td>
			</tr>
		</table>
		<p class="description">
			<?php esc_html_e( 'Your bio goes in the main content editor above. Your headshot is the Featured Image.', 'ans-singers-portal' ); ?>
		</p>
		<?php
	}

	/**
	 * Nonce-verified save handler (same sanitisation as the old plugin).
	 *
	 * @param int     $post_id Singer profile ID.
	 * @param WP_Post $post    Profile post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ansp_legacy_directory_active() ) {
			return;
		}
		if ( ! isset( $_POST['ans_singer_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ans_singer_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ans_singer_save' ) ) {
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

		// Voice parts: intersect with the valid options.
		$raw_parts = isset( $_POST['ans_parts'] ) && is_array( $_POST['ans_parts'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['ans_parts'] ) )
			: array();
		$parts     = array_values( array_intersect( ansp_voice_part_options(), $raw_parts ) );
		if ( $parts ) {
			update_post_meta( $post_id, 'parts', $parts );
		} else {
			delete_post_meta( $post_id, 'parts' );
		}

		// Years with the group.
		$years_raw = isset( $_POST['ans_years'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ans_years'] ) ) ) : '';
		if ( '' !== $years_raw ) {
			update_post_meta( $post_id, 'years_with_group', absint( $years_raw ) );
		} else {
			delete_post_meta( $post_id, 'years_with_group' );
		}

		// Favorite piece / quote.
		$fav = isset( $_POST['ans_fav'] ) ? sanitize_text_field( wp_unslash( $_POST['ans_fav'] ) ) : '';
		if ( '' !== $fav ) {
			update_post_meta( $post_id, 'favorite_piece', $fav );
		} else {
			delete_post_meta( $post_id, 'favorite_piece' );
		}

		$quote = isset( $_POST['ans_quote'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ans_quote'] ) ) : '';
		if ( '' !== $quote ) {
			update_post_meta( $post_id, 'favorite_quote', $quote );
		} else {
			delete_post_meta( $post_id, 'favorite_quote' );
		}
	}

	/**
	 * Insert the "Voice Part" column after Title on the Singers list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function admin_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['voice_part'] = __( 'Voice Part', 'ans-singers-portal' );
			}
		}
		return $out;
	}

	/**
	 * Render the Voice Part column: parts array, or the legacy field.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Row post ID.
	 * @return void
	 */
	public function admin_column_content( $column, $post_id ) {
		if ( 'voice_part' !== $column ) {
			return;
		}
		echo esc_html( self::parts_display( $post_id ) );
	}

	/**
	 * Public bio page: wrap the singer's content with headshot, parts,
	 * pronouns, years, favorite piece and quote (ported from the Directory
	 * plugin). Runs only on the main-loop content of a single singer.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function bio_page_content( $content ) {
		if ( ansp_legacy_directory_active() ) {
			return $content;
		}
		if ( ! is_singular( 'singer' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id  = get_the_ID();
		$parts    = self::parts_display( $post_id );
		$pronouns = (string) get_post_meta( $post_id, 'pronouns', true );
		$years    = get_post_meta( $post_id, 'years_with_group', true );
		$fav      = (string) get_post_meta( $post_id, 'favorite_piece', true );
		$quote    = (string) get_post_meta( $post_id, 'favorite_quote', true );

		$html = '<div class="ans-singer-bio">';

		if ( has_post_thumbnail( $post_id ) ) {
			$html .= '<figure class="ans-singer-figure">' . get_the_post_thumbnail( $post_id, 'large' ) . '</figure>';
		}

		$html .= '<div class="ans-singer-text">';

		$part_line = $parts;
		if ( '' !== $pronouns ) {
			$part_line .= ( '' !== $part_line ? ' · ' : '' ) . $pronouns;
		}
		if ( '' !== $part_line ) {
			$html .= '<p class="ans-singer-part">' . esc_html( $part_line ) . '</p>';
		}

		if ( '' !== (string) $years && absint( $years ) > 0 ) {
			$html .= '<p class="ans-singer-years">' . esc_html(
				sprintf(
					/* translators: %d: number of years */
					_n( '%d year with Ars Nova Singers', '%d years with Ars Nova Singers', absint( $years ), 'ans-singers-portal' ),
					absint( $years )
				)
			) . '</p>';
		}

		$html .= $content;

		if ( '' !== $fav || '' !== $quote ) {
			$html .= '<div class="ans-singer-extra">';
			if ( '' !== $fav ) {
				$html .= '<p><strong>' . esc_html__( 'Favorite piece:', 'ans-singers-portal' ) . '</strong> ' . esc_html( $fav ) . '</p>';
			}
			if ( '' !== $quote ) {
				$html .= '<blockquote class="ans-singer-quote">' . esc_html( $quote ) . '</blockquote>';
			}
			$html .= '</div>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The bio-page CSS (ported verbatim from the Directory plugin), printed
	 * only on single singer pages.
	 *
	 * @return void
	 */
	public function bio_page_css() {
		if ( ansp_legacy_directory_active() || ! is_singular( 'singer' ) ) {
			return;
		}
		?>
		<style id="ansp-singer-bio-css">
			.ans-singer-bio { display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap; }
			.ans-singer-figure { margin: 0; flex: 0 0 auto; }
			.ans-singer-figure img { max-width: 340px; height: auto; border-radius: 6px; }
			.ans-singer-text { flex: 1 1 320px; min-width: 0; }
			.ans-singer-part { color: #0e1b3a; font-weight: 600; font-size: 1.2rem; margin: 0 0 .25rem; }
			.ans-singer-years { color: #6b7280; margin: 0 0 1rem; }
			.ans-singer-extra { border-top: 1px solid #e5e7eb; margin-top: 1.5rem; padding-top: 1rem; }
			.ans-singer-quote { border-left: 3px solid #0e1b3a; margin: .75rem 0 0; padding-left: 1rem; font-style: italic; }
			@media (max-width: 768px) {
				.ans-singer-bio { flex-direction: column; }
				.ans-singer-figure img { max-width: 100%; }
			}
		</style>
		<?php
	}
}
