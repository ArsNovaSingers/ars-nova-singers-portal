<?php
/**
 * Front-end portal: the [ans_singers_portal] shortcode.
 *
 * Renders the tabbed member area (Home/Announcements, My Bio, Roster,
 * Calendar, Season Materials, Past Projects) from the templates/ directory.
 * Logged-out visitors only ever see a branded login prompt.
 *
 * Assets are registered globally but enqueued only when the shortcode is
 * present on the page being viewed.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Portal
 */
class ANSP_Portal {

	/**
	 * Hook shortcode + assets.
	 */
	public function __construct() {
		add_shortcode( 'ans_singers_portal', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (and conditionally enqueue) the portal assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'ansp-portal', ANSP_URL . 'assets/portal.css', array(), ANSP_VERSION );
		wp_register_script( 'ansp-portal', ANSP_URL . 'assets/portal.js', array(), ANSP_VERSION, true );

		// Config for the "Compose with AI" bio helper (assets/portal.js).
		wp_localize_script(
			'ansp-portal',
			'anspPortal',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'aiNonce'      => wp_create_nonce( 'ansp_ai_bio' ),
				'hasGeminiKey' => '' !== trim( (string) get_option( 'ansp_gemini_api_key', '' ) ),
				'composing'    => __( 'Composing your draft…', 'ans-singers-portal' ),
				'noKeyMessage' => __( "AI compose isn't set up yet — add a Gemini API key in Singers Portal → Settings.", 'ans-singers-portal' ),
				'aiError'      => __( 'Something went wrong while composing. Please try again.', 'ans-singers-portal' ),
			)
		);

		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'ans_singers_portal' ) ) {
				wp_enqueue_style( 'ansp-portal' );
				wp_enqueue_script( 'ansp-portal' );
			}
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * @return string Portal (or login prompt) markup.
	 */
	public function render_shortcode() {
		// Belt-and-braces: enqueue even if the shortcode is rendered outside
		// the main content (widget, block template, etc.).
		wp_enqueue_style( 'ansp-portal' );
		wp_enqueue_script( 'ansp-portal' );

		if ( ! is_user_logged_in() ) {
			return $this->render_login_prompt();
		}

		$user_id = get_current_user_id();
		if ( ! user_can( $user_id, 'ansp_view_portal' ) && ! ANSP_Permissions::is_manager( $user_id ) ) {
			return '<div class="ansp-portal ansp-portal--denied"><p>'
				. esc_html__( 'Your account does not have Singers Portal access. Please contact the Personnel Manager.', 'ans-singers-portal' )
				. '</p></div>';
		}

		ob_start();
		ansp_get_template( 'portal' );
		return (string) ob_get_clean();
	}

	/**
	 * Branded login prompt for logged-out visitors. Nothing member-facing
	 * is exposed here.
	 *
	 * @return string
	 */
	protected function render_login_prompt() {
		$form = wp_login_form(
			array(
				'echo'     => false,
				'redirect' => ansp_get_portal_url(),
				'remember' => true,
			)
		);

		$html  = '<div class="ansp-portal ansp-portal--login">';
		$html .= '<div class="ansp-login-card">';
		$html .= '<h2 class="ansp-login-title">' . esc_html__( 'Singers Portal', 'ans-singers-portal' ) . '</h2>';
		$html .= '<p class="ansp-login-sub">' . esc_html__( 'Members only. Please sign in to see rehearsal materials, the roster and calendars.', 'ans-singers-portal' ) . '</p>';
		$html .= $form; // Core-generated, safe markup.
		$html .= '<p class="ansp-login-lost"><a href="' . esc_url( wp_lostpassword_url( ansp_get_portal_url() ) ) . '">' . esc_html__( 'Forgot your password?', 'ans-singers-portal' ) . '</a></p>';
		$html .= '</div></div>';

		return $html;
	}
}

/**
 * URL of the portal page (falls back to /portal/).
 *
 * @return string
 */
function ansp_get_portal_url() {
	$page_id = (int) get_option( 'ansp_portal_page_id', 0 );
	if ( $page_id ) {
		$url = get_permalink( $page_id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/portal/' );
}

/**
 * Load a template from templates/, exposing $args to it.
 *
 * @param string $template Template name without extension (e.g. 'tab-home').
 * @param array  $args     Variables to extract into the template scope.
 * @return void
 */
function ansp_get_template( $template, $args = array() ) {
	$file = ANSP_DIR . 'templates/' . sanitize_file_name( $template ) . '.php';
	if ( ! file_exists( $file ) ) {
		return;
	}
	if ( is_array( $args ) && $args ) {
		extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- controlled template scope.
	}
	include $file;
}
