<?php
/**
 * The "Singers Portal" admin dashboard — the control panel for
 * administrators, Tom (artistic_director) and Zahnay (personnel_manager).
 *
 * Top-level menu (dashicons-groups) with:
 * - Dashboard landing page: counts + quick links.
 * - Projects / Announcements: attached automatically by their CPTs
 *   (show_in_menu = 'ansp-dashboard').
 * - Seasons & Groups: the taxonomy screens.
 * - Roster: the singer post list (when the host CPT exists).
 * - Calendars: ANSP_Calendar options form.
 * - Settings: current season, invite email text,
 *   offboarding shortcuts.
 *
 * Singers never see this menu (capability ansp_manage_portal).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Dashboard
 */
class ANSP_Dashboard {

	/**
	 * Menu slug of the top-level page.
	 */
	const MENU_SLUG = 'ansp-dashboard';

	/**
	 * Hook menus, settings and admin assets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_ansp_settings', array( __CLASS__, 'options_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Capability for saving the settings option page (lets the AD/PM roles
	 * save without manage_options).
	 *
	 * @return string
	 */
	public static function options_capability() {
		return 'ansp_manage_portal';
	}

	/**
	 * Reorder the dashboard submenu so "Projects" sits directly below
	 * "Seasons" (Projects is auto-attached by the CPT's show_in_menu, so we
	 * move it into place after all menus are registered).
	 *
	 * @return void
	 */
	public function reorder_submenu() {
		global $submenu;
		if ( empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}
		$items         = $submenu[ self::MENU_SLUG ];
		$seasons_slug  = 'edit-tags.php?taxonomy=ans_season&post_type=ans_project';
		$projects_slug = 'edit.php?post_type=ans_project';

		// Pull the Projects entry out.
		$projects_item = null;
		foreach ( $items as $key => $item ) {
			if ( isset( $item[2] ) && $projects_slug === $item[2] ) {
				$projects_item = $item;
				unset( $items[ $key ] );
				break;
			}
		}
		if ( null === $projects_item ) {
			return;
		}

		// Re-insert it immediately after the Seasons entry.
		$rebuilt  = array();
		$inserted = false;
		foreach ( $items as $item ) {
			$rebuilt[] = $item;
			if ( ! $inserted && isset( $item[2] ) && $seasons_slug === $item[2] ) {
				$rebuilt[]  = $projects_item;
				$inserted   = true;
			}
		}
		if ( ! $inserted ) {
			$rebuilt[] = $projects_item; // Seasons not found — keep Projects rather than lose it.
		}

		$submenu[ self::MENU_SLUG ] = array_values( $rebuilt );
	}

	/**
	 * Register the menu tree.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Singers Portal', 'ans-singers-portal' ),
			__( 'Singers Portal', 'ans-singers-portal' ),
			'ansp_manage_portal',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-groups',
			3
		);

		// Rename the auto-created first submenu entry to "Dashboard".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'ans-singers-portal' ),
			__( 'Dashboard', 'ans-singers-portal' ),
			'ansp_manage_portal',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		// Taxonomy screens (Projects + Announcements attach themselves).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Seasons', 'ans-singers-portal' ),
			__( 'Seasons', 'ans-singers-portal' ),
			'ansp_manage_portal',
			'edit-tags.php?taxonomy=ans_season&post_type=ans_project'
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Groups', 'ans-singers-portal' ),
			__( 'Groups', 'ans-singers-portal' ),
			'ansp_manage_portal',
			'edit-tags.php?taxonomy=ans_group&post_type=ans_project'
		);

		// Roster: only when the host "singer" CPT exists.
		if ( post_type_exists( 'singer' ) ) {
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Singers', 'ans-singers-portal' ),
				__( 'Singers', 'ans-singers-portal' ),
				'edit_posts',
				'edit.php?post_type=singer'
			);
		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Calendars', 'ans-singers-portal' ),
			__( 'Calendars', 'ans-singers-portal' ),
			'ansp_manage_portal',
			'ansp-calendars',
			array( 'ANSP_Calendar', 'render_admin_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'ans-singers-portal' ),
			__( 'Settings', 'ans-singers-portal' ),
			'ansp_manage_portal',
			'ansp-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'ansp_settings',
			'ansp_current_season',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			'ansp_settings',
			'ansp_invite_email_subject',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'ansp_settings',
			'ansp_invite_email_body',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);
		register_setting(
			'ansp_settings',
			'ansp_gemini_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Checkbox sanitizer ('1' or '').
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_checkbox( $value ) {
		return empty( $value ) ? '' : '1';
	}

	/**
	 * Enqueue admin CSS/JS only on plugin screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_plugin_screen = false;
		if ( in_array( (string) $screen->post_type, array( ANSP_CPT::POST_TYPE, 'singer', ANSP_Announcements::POST_TYPE ), true ) ) {
			$is_plugin_screen = true;
		}
		if ( isset( $screen->taxonomy ) && in_array( (string) $screen->taxonomy, array( 'ans_group', 'ans_season' ), true ) ) {
			$is_plugin_screen = true;
		}
		if ( false !== strpos( (string) $screen->id, 'ansp' ) ) {
			$is_plugin_screen = true;
		}
		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_style( 'ansp-admin', ANSP_URL . 'assets/admin.css', array(), ANSP_VERSION );
		wp_enqueue_script( 'ansp-admin', ANSP_URL . 'assets/admin.js', array( 'jquery' ), ANSP_VERSION, true );
	}

	/**
	 * Landing page: counts + quick links.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view the Singers Portal dashboard.', 'ans-singers-portal' ) );
		}

		$project_counts = wp_count_posts( ANSP_CPT::POST_TYPE );
		$projects       = isset( $project_counts->publish ) ? (int) $project_counts->publish : 0;

		$singers = 0;
		if ( post_type_exists( 'singer' ) ) {
			$singer_counts = wp_count_posts( 'singer' );
			$singers       = isset( $singer_counts->publish ) ? (int) $singer_counts->publish : 0;
		}

		$announcement_counts = wp_count_posts( ANSP_Announcements::POST_TYPE );
		$announcements       = isset( $announcement_counts->publish ) ? (int) $announcement_counts->publish : 0;

		$groups  = wp_count_terms( array( 'taxonomy' => 'ans_group', 'hide_empty' => false ) );
		$groups  = is_wp_error( $groups ) ? 0 : (int) $groups;
		$seasons = wp_count_terms( array( 'taxonomy' => 'ans_season', 'hide_empty' => false ) );
		$seasons = is_wp_error( $seasons ) ? 0 : (int) $seasons;

		$singer_accounts = count( get_users( array( 'role' => 'singer', 'fields' => 'ID' ) ) );

		$current_season = ANSP_Taxonomies::get_current_season();
		$portal_page_id = (int) get_option( 'ansp_portal_page_id', 0 );

		$cards = array(
			array( __( 'Projects', 'ans-singers-portal' ), $projects, admin_url( 'edit.php?post_type=' . ANSP_CPT::POST_TYPE ) ),
			array( __( 'Singer profiles', 'ans-singers-portal' ), $singers, post_type_exists( 'singer' ) ? admin_url( 'edit.php?post_type=singer' ) : '' ),
			array( __( 'Singer accounts', 'ans-singers-portal' ), $singer_accounts, current_user_can( 'list_users' ) ? admin_url( 'users.php?role=singer' ) : '' ),
			array( __( 'Groups', 'ans-singers-portal' ), $groups, admin_url( 'edit-tags.php?taxonomy=ans_group&post_type=ans_project' ) ),
			array( __( 'Seasons', 'ans-singers-portal' ), $seasons, admin_url( 'edit-tags.php?taxonomy=ans_season&post_type=ans_project' ) ),
			array( __( 'Announcements', 'ans-singers-portal' ), $announcements, admin_url( 'edit.php?post_type=' . ANSP_Announcements::POST_TYPE ) ),
		);
		?>
		<div class="wrap ansp-admin-wrap">
			<h1><?php esc_html_e( 'Singers Portal', 'ans-singers-portal' ); ?></h1>
			<p>
				<?php
				if ( $current_season instanceof WP_Term ) {
					/* translators: %s: season name */
					printf( esc_html__( 'Current season: %s.', 'ans-singers-portal' ), '<strong>' . esc_html( $current_season->name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				} else {
					esc_html_e( 'No current season set yet — add one under Seasons, then pick it in Settings.', 'ans-singers-portal' );
				}
				?>
				<?php if ( $portal_page_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $portal_page_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View the portal page →', 'ans-singers-portal' ); ?></a>
				<?php endif; ?>
			</p>

			<div class="ansp-cards">
				<?php foreach ( $cards as $card ) : ?>
					<div class="ansp-card">
						<span class="ansp-card-count"><?php echo esc_html( (string) $card[1] ); ?></span>
						<span class="ansp-card-label"><?php echo esc_html( $card[0] ); ?></span>
						<?php if ( ! empty( $card[2] ) ) : ?>
							<a class="ansp-card-link" href="<?php echo esc_url( $card[2] ); ?>"><?php esc_html_e( 'Manage', 'ans-singers-portal' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Quick links', 'ans-singers-portal' ); ?></h2>
			<ul class="ansp-quick-links">
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . ANSP_CPT::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add a Project (materials + tags)', 'ans-singers-portal' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . ANSP_Announcements::POST_TYPE ) ); ?>"><?php esc_html_e( 'Post an Announcement', 'ans-singers-portal' ); ?></a></li>
				<?php if ( post_type_exists( 'singer' ) ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=singer' ) ); ?>"><?php esc_html_e( 'Manage Singers (profiles, groups, invites)', 'ans-singers-portal' ); ?></a></li>
				<?php else : ?>
					<li><em><?php esc_html_e( 'The "singer" profile post type is not active on this site — roster features are dormant until its plugin is enabled.', 'ans-singers-portal' ); ?></em></li>
				<?php endif; ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ansp-calendars' ) ); ?>"><?php esc_html_e( 'Set up the Google Calendars', 'ans-singers-portal' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ansp-settings' ) ); ?>"><?php esc_html_e( 'Portal Settings (current season, invites, offboarding)', 'ans-singers-portal' ); ?></a></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage portal settings.', 'ans-singers-portal' ) );
		}

		$seasons = get_terms(
			array(
				'taxonomy'   => 'ans_season',
				'hide_empty' => false,
			)
		);
		$seasons = is_wp_error( $seasons ) ? array() : $seasons;

		$current_season = (int) get_option( 'ansp_current_season', 0 );
		$subject        = (string) get_option( 'ansp_invite_email_subject', '' );
		$body           = (string) get_option( 'ansp_invite_email_body', '' );
		$gemini_key     = (string) get_option( 'ansp_gemini_api_key', '' );
		?>
		<div class="wrap ansp-admin-wrap">
			<h1><?php esc_html_e( 'Singers Portal Settings', 'ans-singers-portal' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'ansp_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ansp_current_season"><?php esc_html_e( 'Current season', 'ans-singers-portal' ); ?></label></th>
						<td>
							<select id="ansp_current_season" name="ansp_current_season">
								<option value="0"><?php esc_html_e( '— Latest season (automatic) —', 'ans-singers-portal' ); ?></option>
								<?php foreach ( $seasons as $season ) : ?>
									<option value="<?php echo esc_attr( (string) $season->term_id ); ?>" <?php selected( $current_season, (int) $season->term_id ); ?>><?php echo esc_html( $season->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The season whose projects show on the "Season Materials" tab.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ansp_invite_email_subject"><?php esc_html_e( 'Invite email subject', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="ansp_invite_email_subject" name="ansp_invite_email_subject" value="<?php echo esc_attr( $subject ); ?>" placeholder="<?php esc_attr_e( 'Your {site_name} Singers Portal account', 'ans-singers-portal' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ansp_invite_email_body"><?php esc_html_e( 'Invite email body', 'ans-singers-portal' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="8" id="ansp_invite_email_body" name="ansp_invite_email_body" placeholder="<?php esc_attr_e( "Hi {name}, set your password: {set_password_url} …", 'ans-singers-portal' ); ?>"><?php echo esc_textarea( $body ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Placeholders: {name}, {portal_url}, {set_password_url}, {site_name}. Leave blank for the built-in default.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ansp_gemini_api_key"><?php esc_html_e( 'Gemini API key', 'ans-singers-portal' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ansp_gemini_api_key" name="ansp_gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Powers the "Compose with AI" button on the My Bio tab (Google Gemini drafts a 2–4 sentence bio from the singer\'s notes). Create a free key at aistudio.google.com. Leave blank to disable AI compose.', 'ans-singers-portal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Offboarding', 'ans-singers-portal' ); ?></th>
						<td>
							<p>
								<?php esc_html_e( 'To offboard a singer, use the "Deactivate (Portal)" link on the Users screen: it removes their portal access and hides their profile from the roster without deleting anything. "Reinstate (Portal)" undoes it.', 'ans-singers-portal' ); ?>
							</p>
							<?php if ( current_user_can( 'list_users' ) ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'users.php?role=singer' ) ); ?>"><?php esc_html_e( 'Open singer accounts', 'ans-singers-portal' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'ans-singers-portal' ) ); ?>
			</form>
		</div>
		<?php
	}
}
