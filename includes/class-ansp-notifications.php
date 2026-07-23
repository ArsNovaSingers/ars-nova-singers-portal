<?php
/**
 * Notifications: email the affected group(s) when a project (and its
 * materials) is published or updated.
 *
 * - On FIRST publish of an ans_project, an email goes out automatically.
 * - On subsequent saves, the editor opts in via the "Send update email"
 *   checkbox (side meta box with its own nonce) so routine edits don't spam
 *   the choir.
 *
 * Recipients: every singer-role user who can see the project (per the
 * ANSP_Permissions engine, based on their groups/email) plus any raw
 * emails granted on individual materials (Special Guests without accounts).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Notifications
 */
class ANSP_Notifications {

	/**
	 * Prevent double-sends within a single request.
	 *
	 * @var array<int,bool>
	 */
	protected static $sent = array();

	/**
	 * Hook meta box, save handler and first-publish trigger.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( $this, 'maybe_notify_on_save' ), 30, 2 );
		add_action( 'transition_post_status', array( $this, 'notify_on_first_publish' ), 10, 3 );
	}

	/**
	 * Register the side meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'ansp_notifications',
			__( 'Notify Singers', 'ans-singers-portal' ),
			array( $this, 'render_meta_box' ),
			ANSP_CPT::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the one-shot "send email" checkbox.
	 *
	 * @param WP_Post $post Current project.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ansp_notify', 'ansp_notify_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="ansp_notify" value="1" />
				<?php esc_html_e( 'Email the affected group(s) about this update when saving', 'ans-singers-portal' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'The first publish always sends an email automatically. For later edits, tick this box to notify singers who can see this project or its materials.', 'ans-singers-portal' ); ?>
		</p>
		<?php
	}

	/**
	 * Save-time opt-in notification.
	 *
	 * @param int     $post_id Project ID.
	 * @param WP_Post $post    Project post.
	 * @return void
	 */
	public function maybe_notify_on_save( $post_id, $post ) {
		if ( ! isset( $_POST['ansp_notify_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ansp_notify_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ansp_notify' ) ) {
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
		if ( empty( $_POST['ansp_notify'] ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		self::notify_project( $post_id, false );
	}

	/**
	 * Automatic email the first time a project is published.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function notify_on_first_publish( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || ANSP_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		self::notify_project( $post->ID, true );
	}

	/**
	 * Send the notification email for a project.
	 *
	 * @param int  $project_id Project ID.
	 * @param bool $is_new     True on first publish (wording changes).
	 * @return int Number of recipients emailed.
	 */
	public static function notify_project( $project_id, $is_new = false ) {
		$project_id = (int) $project_id;
		if ( isset( self::$sent[ $project_id ] ) ) {
			return 0; // Already handled in this request.
		}
		self::$sent[ $project_id ] = true;

		$project = get_post( $project_id );
		if ( ! $project instanceof WP_Post || 'publish' !== $project->post_status ) {
			return 0;
		}

		$recipients = array();

		// Singer-role account holders who can see this project.
		$singers = get_users(
			array(
				'role'   => 'singer',
				'fields' => 'all',
			)
		);
		foreach ( $singers as $singer ) {
			if ( $singer->user_email && ANSP_Permissions::user_can_see( $project_id, $singer->ID ) ) {
				$recipients[ strtolower( $singer->user_email ) ] = true;
			}
		}

		// v1.2.0: per-material email grants were removed with the permission
		// model — recipients are simply the singers who can see the project.

		if ( empty( $recipients ) ) {
			return 0;
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = wp_specialchars_decode( get_the_title( $project_id ), ENT_QUOTES );

		$subject = $is_new
			/* translators: 1: site name, 2: project title */
			? sprintf( __( '[%1$s] New project: %2$s', 'ans-singers-portal' ), $site, $title )
			/* translators: 1: site name, 2: project title */
			: sprintf( __( '[%1$s] Project updated: %2$s', 'ans-singers-portal' ), $site, $title );

		$lines   = array();
		$lines[] = $is_new
			/* translators: %s: project title */
			? sprintf( __( 'A new project is available in the Singers Portal: %s', 'ans-singers-portal' ), $title )
			/* translators: %s: project title */
			: sprintf( __( 'Materials or details were updated for: %s', 'ans-singers-portal' ), $title );

		$venue = ANSP_Project_Meta::get( $project_id, 'venue' );
		$start = ANSP_Project_Meta::get( $project_id, 'date_start' );
		if ( $start ) {
			/* translators: %s: date */
			$lines[] = sprintf( __( 'Starts: %s', 'ans-singers-portal' ), $start );
		}
		if ( $venue ) {
			/* translators: %s: venue */
			$lines[] = sprintf( __( 'Venue: %s', 'ans-singers-portal' ), $venue );
		}

		$materials = ANSP_Materials::get_materials( $project_id );
		if ( $materials ) {
			$lines[] = '';
			$lines[] = __( 'Materials:', 'ans-singers-portal' );
			foreach ( $materials as $material ) {
				if ( ! empty( $material['title'] ) ) {
					$lines[] = ' - ' . $material['title'];
				}
			}
		}

		$lines[] = '';
		/* translators: %s: portal URL */
		$lines[] = sprintf( __( 'Open the portal: %s', 'ans-singers-portal' ), ansp_get_portal_url() );
		$lines[] = '';
		$lines[] = $site;

		$body = implode( "\n", $lines );

		$count = 0;
		foreach ( array_keys( $recipients ) as $email ) {
			if ( wp_mail( $email, $subject, $body ) ) {
				$count++;
			}
		}
		return $count;
	}
}
