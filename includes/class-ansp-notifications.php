<?php
/**
 * Notifications: email the affected group(s) about a project, ONLY when a
 * human ticks the box and only while notifications are switched on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS USED TO DO, AND WHY IT DOESN'T ANY MORE
 *
 * Until 1.34.0 this class hooked `transition_post_status` and emailed the whole
 * affected group AUTOMATICALLY the first time an ans_project reached `publish`.
 * There was no opt-in, no preview, no record of what had been sent, and no way
 * to turn it off short of deactivating the plugin.
 *
 * That hook fires on EVERY path that publishes a project — the wp-admin editor,
 * the `portal/projects` REST writer, a season-snapshot import, WP-CLI, any
 * plugin calling wp_insert_post(). None of those look like "announce this to
 * the choir", and three of them are things a maintenance script does in bulk.
 *
 * Jonathan, 2026-09-04: "Every time we create a project it sends emails to
 * everybody who is a member of the group related to that project. this must
 * stop. I got multiple complaints today at choir."
 *
 * ⚠️ DO NOT RE-ADD A transition_post_status HOOK HERE. Publishing a record is
 * not the same act as telling fifty people about it, and the whole failure was
 * that one silently meant the other. If an automatic announcement is ever
 * wanted again it needs to be a deliberate, visible, rate-limited thing with
 * its own setting — not a side effect of a post status changing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT SENDING NOW REQUIRES — all four, every time
 *
 * 1. The master switch (`ansp_notify_enabled`) is on. It ships OFF.
 * 2. Someone ticked "Email the affected group(s)" in the wp-admin meta box.
 * 3. That form's nonce verifies — which is what makes REST, imports, WP-CLI and
 *    any other programmatic write structurally incapable of sending. They never
 *    post the nonce, so they can never reach the send.
 * 4. The project is published.
 *
 * Every send is recorded in `ansp_notify_log` so "who got emailed, and when?"
 * has an answer. On 2026-09-04 it did not.
 *
 * Recipients: every singer-role user who can see the project, per the
 * ANSP_Permissions engine.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Notifications
 */
class ANSP_Notifications {

	/**
	 * Master switch. Ships OFF — see the docblock.
	 *
	 * An option rather than a constant so it can be turned off from the API the
	 * moment something goes wrong, without waiting for a release. That is the
	 * capability whose absence made 2026-09-04 worse than it needed to be: the
	 * only way to stop the sending was to stop publishing projects.
	 */
	const OPT_ENABLED = 'ansp_notify_enabled';

	/** Rolling record of what was sent, to whom, and when. Capped. */
	const OPT_LOG = 'ansp_notify_log';

	/** How many send records to keep. */
	const LOG_MAX = 50;

	/**
	 * Prevent double-sends within a single request.
	 *
	 * @var array<int,bool>
	 */
	protected static $sent = array();

	/**
	 * Are notifications switched on at all?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/**
	 * Who would be emailed about this project, without sending anything.
	 *
	 * Split out of notify_project() so the meta box can say "this will email 37
	 * singers" BEFORE anybody ticks the box. A number in front of a person is
	 * the cheapest protection against a surprise mailout there is.
	 *
	 * @param int $project_id Project ID.
	 * @return string[] Lower-cased email addresses.
	 */
	public static function get_recipients( $project_id ) {
		$project_id = (int) $project_id;
		$recipients = array();

		$singers = get_users( array( 'role' => 'singer', 'fields' => 'all' ) );
		foreach ( $singers as $singer ) {
			if ( $singer->user_email && ANSP_Permissions::user_can_see( $project_id, $singer->ID ) ) {
				$recipients[ strtolower( $singer->user_email ) ] = true;
			}
		}
		return array_keys( $recipients );
	}

	/**
	 * Read the send log and the current switch state.
	 *
	 * A READ-ONLY route, deliberately. Turning notifications on or off goes
	 * through `portal/settings` like every other option; this exists so the
	 * question that had no answer on 2026-09-04 — "who was emailed about what,
	 * and when?" — can be asked without database access.
	 *
	 * With `?project_id=` it also reports who WOULD be emailed about that
	 * project, without sending anything. That is the check to run before
	 * turning the switch back on.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public function rest_read( $req ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$out = array(
			'enabled'    => self::is_enabled(),
			'option'     => self::OPT_ENABLED,
			'note'       => self::is_enabled()
				? 'Notifications are ON. A save with the box ticked will email singers.'
				: 'Notifications are OFF. Nothing in this plugin will email a singer.',
			'automatic'  => false,
			'automatic_note' => 'Publishing a project never emails anyone. Removed in 1.34.0.',
			'log_count'  => count( $log ),
			'log'        => array_slice( $log, 0, 20 ),
		);

		$project_id = (int) $req->get_param( 'project_id' );
		if ( $project_id ) {
			$recipients = self::get_recipients( $project_id );
			$out['preview'] = array(
				'project_id' => $project_id,
				'title'      => get_the_title( $project_id ),
				'would_email' => count( $recipients ),
				'recipients'  => $recipients,
				'note'        => 'Nothing was sent. This is who would be emailed if somebody ticked the box.',
			);
		}

		return $out;
	}

	/**
	 * Hook meta box, save handler and first-publish trigger.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . ANSP_CPT::POST_TYPE, array( $this, 'maybe_notify_on_save' ), 30, 2 );

		/*
		 * NO transition_post_status HOOK. Removed in 1.34.0 — see the class
		 * docblock. This is the line that emailed the choir every time anything
		 * published a project, including bulk imports. Leaving the absence
		 * commented so nobody re-adds it thinking it was an oversight.
		 */

		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'ars-nova/v1',
					'/portal/notifications',
					array(
						'methods'             => 'GET',
						'permission_callback' => array( 'ANSP_REST', 'can_manage' ),
						'callback'            => array( $this, 'rest_read' ),
					)
				);
			}
		);
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

		$enabled = self::is_enabled();
		$count   = 'publish' === $post->post_status ? count( self::get_recipients( $post->ID ) ) : 0;
		?>
		<?php if ( ! $enabled ) : ?>
			<p class="description">
				<strong><?php esc_html_e( 'Singer emails are switched OFF.', 'ans-singers-portal' ); ?></strong><br />
				<?php esc_html_e( 'Nothing on this screen will email anybody. Turn them on by setting the ansp_notify_enabled option.', 'ans-singers-portal' ); ?>
			</p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="ansp_notify" value="1" <?php disabled( ! $enabled ); ?> />
				<?php esc_html_e( 'Email the affected group(s) about this project when saving', 'ans-singers-portal' ); ?>
			</label>
		</p>
		<?php if ( $enabled ) : ?>
			<p class="description">
				<?php if ( 'publish' !== $post->post_status ) : ?>
					<?php esc_html_e( 'This project is not published, so nothing will be sent.', 'ans-singers-portal' ); ?>
				<?php elseif ( $count ) : ?>
					<strong>
						<?php
						printf(
							/* translators: %d: number of singers who would be emailed */
							esc_html( _n( 'Ticking this box emails %d singer.', 'Ticking this box emails %d singers.', $count, 'ans-singers-portal' ) ),
							(int) $count
						);
						?>
					</strong>
				<?php else : ?>
					<?php esc_html_e( 'No singer can currently see this project, so nothing would be sent.', 'ans-singers-portal' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Publishing a project never emails anyone on its own. An email is only ever sent because somebody ticked this box on this screen.', 'ans-singers-portal' ); ?>
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
	 * Send the notification email for a project.
	 *
	 * @param int  $project_id Project ID.
	 * @param bool $is_new     True on first publish (wording changes).
	 * @return int Number of recipients emailed.
	 */
	public static function notify_project( $project_id, $is_new = false ) {
		$project_id = (int) $project_id;

		/*
		 * The kill switch is checked HERE, not only at the call site.
		 *
		 * This method is public and static, so a future caller — another
		 * plugin, a CLI command, a well-meaning patch — can reach it without
		 * going through the meta box. One check at the only place that can
		 * actually call wp_mail() is worth more than three at the callers.
		 */
		if ( ! self::is_enabled() ) {
			return 0;
		}

		if ( isset( self::$sent[ $project_id ] ) ) {
			return 0; // Already handled in this request.
		}
		self::$sent[ $project_id ] = true;

		$project = get_post( $project_id );
		if ( ! $project instanceof WP_Post || 'publish' !== $project->post_status ) {
			return 0;
		}

		// v1.2.0: per-material email grants were removed with the permission
		// model — recipients are simply the singers who can see the project.
		$recipients = self::get_recipients( $project_id );
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
		foreach ( $recipients as $email ) {
			if ( wp_mail( $email, $subject, $body ) ) {
				$count++;
			}
		}

		self::log_send( $project_id, $title, $recipients, $count );

		return $count;
	}

	/**
	 * Record a send, so "who got emailed about what, and when?" has an answer.
	 *
	 * On 2026-09-04 several singers reported unexpected email and there was no
	 * way to confirm what had gone out, to whom, or how often — the sending
	 * left no trace anywhere. This is that trace.
	 *
	 * Addresses are stored because the question people actually ask is "did
	 * *I* get one". Capped at LOG_MAX entries so an option row cannot grow
	 * without bound.
	 *
	 * @param int      $project_id Project.
	 * @param string   $title      Project title at send time.
	 * @param string[] $recipients Addresses attempted.
	 * @param int      $sent       How many wp_mail() accepted.
	 * @return void
	 */
	protected static function log_send( $project_id, $title, $recipients, $sent ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'       => gmdate( 'c' ),
				'project_id' => (int) $project_id,
				'title'      => (string) $title,
				'attempted'  => count( $recipients ),
				'sent'       => (int) $sent,
				'by'         => get_current_user_id(),
				'recipients' => array_values( $recipients ),
			)
		);

		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}
}
