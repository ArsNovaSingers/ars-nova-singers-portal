<?php
/**
 * The PUBLIC singers page — [ans_singers].
 *
 * Replaces the hand-typed markup on page 259, where 41 people were maintained
 * by hand in 15+ two-column blocks with raw <img src> headshots.
 *
 * Two pieces of state, both living on the existing `singer` CPT:
 *
 *  - `ansp_active`          — the "Active singer" switch. Jonathan, 2026-08-02:
 *                             checking/unchecking this is what puts a singer on
 *                             the public page. Replaces any roster sign-off gate.
 *  - `ansp_roster_section`  — `ensemble` (default) or `apprentice`, so the High
 *                             School Apprentices keep their own block.
 *
 * DEFAULT IS ACTIVE. Absent meta means active, deliberately: all 41 published
 * singers are already on the public page today, so a fresh install reproduces
 * the current page exactly and nobody has to tick 41 boxes to avoid a
 * regression. Removing someone is the explicit act, not including them.
 *
 * This class is PUBLIC-PAGE ONLY. It does not touch `ans_group`, the portal
 * permissions engine, or anything a logged-in singer sees.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Singers_Public
 */
class ANSP_Singers_Public {

	const META_ACTIVE  = 'ansp_active';
	const META_SECTION = 'ansp_roster_section';

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 7 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_singer', array( $this, 'save' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'ans_singers', array( __CLASS__, 'shortcode' ) );

		// At-a-glance column on the Singers list screen.
		add_filter( 'manage_singer_posts_columns', array( __CLASS__, 'admin_column' ) );
		add_action( 'manage_singer_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
	}

	/**
	 * Register the two meta keys.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta(
			'singer',
			self::META_ACTIVE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			'singer',
			self::META_SECTION,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Is this singer shown on the public page?
	 *
	 * Absent meta === active. Only an explicit 'no' hides someone.
	 *
	 * @param int $post_id Singer post ID.
	 * @return bool
	 */
	public static function is_active( $post_id ) {
		return 'no' !== get_post_meta( (int) $post_id, self::META_ACTIVE, true );
	}

	/**
	 * Which roster section a singer belongs to. Absent meta === ensemble.
	 *
	 * @param int $post_id Singer post ID.
	 * @return string 'ensemble' | 'apprentice'
	 */
	public static function get_section( $post_id ) {
		$value = get_post_meta( (int) $post_id, self::META_SECTION, true );
		return ( 'apprentice' === $value ) ? 'apprentice' : 'ensemble';
	}

	/**
	 * Add the meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'ansp_singer_public',
			__( 'Public Singers Page', 'ans-singers-portal' ),
			array( __CLASS__, 'render_meta_box' ),
			'singer',
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_save_singer_public', 'ansp_singer_public_nonce' );

		$active  = self::is_active( $post->ID );
		$section = self::get_section( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="ansp_active" value="yes" <?php checked( $active ); ?> />
				<strong><?php esc_html_e( 'Active singer', 'ans-singers-portal' ); ?></strong>
			</label>
		</p>
		<p class="description" style="margin-top:-8px;">
			<?php esc_html_e( 'Ticked = shown on the public Singers page. Untick to remove them from the page without deleting the profile.', 'ans-singers-portal' ); ?>
		</p>
		<p style="margin-top:14px;">
			<label for="ansp_roster_section"><strong><?php esc_html_e( 'Roster section', 'ans-singers-portal' ); ?></strong></label><br />
			<select name="ansp_roster_section" id="ansp_roster_section" style="width:100%;">
				<option value="ensemble" <?php selected( $section, 'ensemble' ); ?>><?php esc_html_e( 'Meet the Singers', 'ans-singers-portal' ); ?></option>
				<option value="apprentice" <?php selected( $section, 'apprentice' ); ?>><?php esc_html_e( 'High School Apprentice', 'ans-singers-portal' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_singer_public_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ansp_singer_public_nonce'] ) ), 'ansp_save_singer_public' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'singer' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Checked => active, which is the default, so we store nothing.
		// Unchecked => an explicit 'no'.
		if ( isset( $_POST['ansp_active'] ) ) {
			delete_post_meta( $post_id, self::META_ACTIVE );
		} else {
			update_post_meta( $post_id, self::META_ACTIVE, 'no' );
		}

		$section = isset( $_POST['ansp_roster_section'] ) ? sanitize_key( wp_unslash( $_POST['ansp_roster_section'] ) ) : 'ensemble';
		if ( 'apprentice' === $section ) {
			update_post_meta( $post_id, self::META_SECTION, 'apprentice' );
		} else {
			delete_post_meta( $post_id, self::META_SECTION );
		}
	}

	/**
	 * Add an "On page" column to the Singers list screen.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function admin_column( $columns ) {
		$columns['ansp_public'] = __( 'On page', 'ans-singers-portal' );
		return $columns;
	}

	/**
	 * Render the "On page" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function admin_column_content( $column, $post_id ) {
		if ( 'ansp_public' !== $column ) {
			return;
		}
		if ( ! self::is_active( $post_id ) ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'Hidden', 'ans-singers-portal' ) . '</span>';
			return;
		}
		echo esc_html(
			'apprentice' === self::get_section( $post_id )
				? __( 'Yes — Apprentice', 'ans-singers-portal' )
				: __( 'Yes', 'ans-singers-portal' )
		);
	}

	/**
	 * The voice parts of a singer, as a display string.
	 *
	 * @param int $post_id Singer post ID.
	 * @return string
	 */
	protected static function parts_string( $post_id ) {
		$parts = array();

		if ( class_exists( 'ANSP_Singer_CPT' ) && method_exists( 'ANSP_Singer_CPT', 'get_parts' ) ) {
			$parts = (array) ANSP_Singer_CPT::get_parts( $post_id );
		}
		if ( empty( $parts ) ) {
			$raw = get_post_meta( $post_id, 'parts', true );
			if ( is_array( $raw ) ) {
				$parts = $raw;
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$parts = array( $raw );
			}
		}
		if ( empty( $parts ) ) {
			$legacy = get_post_meta( $post_id, 'voice_part', true );
			if ( $legacy ) {
				$parts = array( $legacy );
			}
		}

		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) );

		return implode( '/', $parts );
	}

	/**
	 * `[ans_singers]` — render the public singers grid.
	 *
	 * Attributes:
	 *   section (optional) `ensemble` (default) or `apprentice`.
	 *   columns (optional) desktop columns. Default 2, matching the old page.
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'section' => 'ensemble',
				'columns' => '2',
			),
			$atts,
			'ans_singers'
		);

		$section = ( 'apprentice' === sanitize_key( $atts['section'] ) ) ? 'apprentice' : 'ensemble';
		$columns = max( 1, min( 4, (int) $atts['columns'] ) );

		$singers = get_posts(
			array(
				'post_type'      => 'singer',
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		/*
		 * Filtering happens in PHP rather than via meta_query.
		 *
		 * Both meta keys are ABSENT for the common case (active, ensemble), and
		 * a meta_query comparing on an absent key needs NOT EXISTS branches that
		 * are easy to get subtly wrong. Reading 41 rows and filtering here is
		 * cheap, obvious, and cannot silently drop anyone — which is the whole
		 * risk on a page that must reproduce the existing roster exactly.
		 */
		$singers = array_filter(
			$singers,
			function ( $singer ) use ( $section ) {
				return self::is_active( $singer->ID ) && $section === self::get_section( $singer->ID );
			}
		);

		if ( empty( $singers ) ) {
			return '';
		}

		self::styles();

		ob_start();
		echo '<div class="ans-singers ans-singers--cols-' . esc_attr( (string) $columns ) . '">';

		foreach ( $singers as $singer ) {
			// Respect the singer's own "Show on the public Singers page"
			// choice. Absent meta means visible, so existing profiles are
			// unaffected until someone actively opts out.
			$pronouns   = ansp_is_field_public( $singer->ID, 'pronouns' )
				? (string) get_post_meta( $singer->ID, 'pronouns', true )
				: '';
			$profession = (string) get_post_meta( $singer->ID, 'profession', true );
			$parts      = self::parts_string( $singer->ID );

			echo '<div class="ans-singer">';

			if ( has_post_thumbnail( $singer->ID ) ) {
				echo '<div class="ans-singer__photo">';
				echo wp_kses_post(
					get_the_post_thumbnail(
						$singer->ID,
						'thumbnail',
						array( 'loading' => 'lazy', 'class' => 'ans-singer__img' )
					)
				);
				echo '</div>';
			}

			echo '<p class="ans-singer__name"><strong>' . esc_html( get_the_title( $singer ) ) . '</strong>';
			if ( $pronouns ) {
				echo ' <span class="ans-singer__pronouns">(' . esc_html( $pronouns ) . ')</span>';
			}
			echo '</p>';

			$line = $parts;
			if ( $parts && $profession ) {
				$line = $parts . ' & ' . $profession;
			} elseif ( ! $parts ) {
				$line = $profession;
			}
			if ( $line ) {
				echo '<p class="ans-singer__detail">' . esc_html( $line ) . '</p>';
			}

			echo '</div>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * REST: bulk-set the two public-page fields.
	 *
	 * Registered into `ars-nova/v1` because that is a namespace the WordPress
	 * connector's ans_rest_call is permitted to reach, so the roster can be
	 * adjusted without a connector rebuild.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'ars-nova/v1',
			'/singers/set',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_set' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'singers' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * REST handler for /singers/set.
	 *
	 * Each row: { id | title, active (bool), section ('ensemble'|'apprentice') }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_set( $request ) {
		$rows    = (array) $request->get_param( 'singers' );
		$results = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;
			$id  = isset( $row['id'] ) ? (int) $row['id'] : 0;

			if ( ! $id && ! empty( $row['title'] ) ) {
				$found = get_posts(
					array(
						'post_type'      => 'singer',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'title'          => sanitize_text_field( (string) $row['title'] ),
						'fields'         => 'ids',
					)
				);
				$id    = ! empty( $found ) ? (int) $found[0] : 0;
			}

			if ( ! $id || 'singer' !== get_post_type( $id ) ) {
				$results[] = array(
					'row'   => isset( $row['title'] ) ? $row['title'] : $id,
					'error' => 'not found',
				);
				continue;
			}

			if ( array_key_exists( 'active', $row ) ) {
				if ( $row['active'] ) {
					delete_post_meta( $id, self::META_ACTIVE );
				} else {
					update_post_meta( $id, self::META_ACTIVE, 'no' );
				}
			}

			if ( ! empty( $row['section'] ) ) {
				if ( 'apprentice' === sanitize_key( (string) $row['section'] ) ) {
					update_post_meta( $id, self::META_SECTION, 'apprentice' );
				} else {
					delete_post_meta( $id, self::META_SECTION );
				}
			}

			$results[] = array(
				'id'      => $id,
				'title'   => get_the_title( $id ),
				'active'  => self::is_active( $id ),
				'section' => self::get_section( $id ),
			);
		}

		return new WP_REST_Response(
			array(
				'count'   => count( $results ),
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Print the grid CSS once per request.
	 *
	 * @return void
	 */
	public static function styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style id="ans-singers-css">
			.ans-singers { display: grid; gap: 2rem 1.5rem; margin: 2rem 0; }
			.ans-singers--cols-1 { grid-template-columns: 1fr; }
			.ans-singers--cols-2 { grid-template-columns: repeat(2, 1fr); }
			.ans-singers--cols-3 { grid-template-columns: repeat(3, 1fr); }
			.ans-singers--cols-4 { grid-template-columns: repeat(4, 1fr); }
			@media (max-width: 900px) { .ans-singers { grid-template-columns: repeat(2, 1fr); } }
			@media (max-width: 560px) { .ans-singers { grid-template-columns: 1fr; } }
			.ans-singer { text-align: center; }
			.ans-singer__photo { margin: 0 0 .6rem; }
			.ans-singer__img { border-radius: 50%; width: 160px; height: 160px; object-fit: cover; }
			.ans-singer__name { margin: 0 0 .15rem; }
			.ans-singer__pronouns { font-weight: 400; font-size: .9em; opacity: .75; }
			.ans-singer__detail { margin: 0; font-size: .95rem; opacity: .9; }
		</style>
		<?php
	}
}
