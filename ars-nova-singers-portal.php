<?php
/**
 * Plugin Name:       Ars Nova Singers Portal
 * Plugin URI:        https://arsnovasingers.org/
 * Description:       Login-gated members portal for the Ars Nova Singers choir: seasons, projects, materials with unlimited free-form tags + a singer-side tag filter, roster, calendars, announcements, RSVPs, front-end singer bios with Gemini "Compose with AI", and the absorbed "singer" directory (CPT, profile details, public bio pages). Groups carry a Google Drive folder mapping — the folder is the access gate for a group's materials. No ACF or other plugin dependencies.
 * Version:           1.13.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ars Nova Singers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ans-singers-portal
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'ANSP_VERSION', '1.13.3' );
define( 'ANSP_FILE', __FILE__ );
define( 'ANSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANSP_URL', plugin_dir_url( __FILE__ ) );

/*
 * -------------------------------------------------------------------------
 * Includes
 * -------------------------------------------------------------------------
 */
require_once ANSP_DIR . 'includes/class-ansp-roles.php';
require_once ANSP_DIR . 'includes/class-ansp-taxonomies.php';
require_once ANSP_DIR . 'includes/class-ansp-group-fields.php';
require_once ANSP_DIR . 'includes/class-ansp-singer-cpt.php';
require_once ANSP_DIR . 'includes/class-ansp-singers-public.php';
require_once ANSP_DIR . 'includes/class-ansp-singer-admin.php';
require_once ANSP_DIR . 'includes/class-ansp-profile-link.php';
require_once ANSP_DIR . 'includes/class-ansp-registration.php';
require_once ANSP_DIR . 'includes/class-ansp-invitations.php';
require_once ANSP_DIR . 'includes/class-ansp-cpt.php';
require_once ANSP_DIR . 'includes/class-ansp-permissions.php';
require_once ANSP_DIR . 'includes/class-ansp-materials.php';
require_once ANSP_DIR . 'includes/class-ansp-project-meta.php';
require_once ANSP_DIR . 'includes/class-ansp-profiles.php';
require_once ANSP_DIR . 'includes/class-ansp-login.php';
require_once ANSP_DIR . 'includes/class-ansp-portal.php';
require_once ANSP_DIR . 'includes/class-ansp-roster.php';
require_once ANSP_DIR . 'includes/class-ansp-calendar.php';
require_once ANSP_DIR . 'includes/class-ansp-announcements.php';
require_once ANSP_DIR . 'includes/class-ansp-notifications.php';
require_once ANSP_DIR . 'includes/class-ansp-rsvp.php';
require_once ANSP_DIR . 'includes/class-ansp-offboarding.php';
require_once ANSP_DIR . 'includes/class-ansp-dashboard.php';
require_once ANSP_DIR . 'includes/class-ansp-bio-editor.php';
require_once ANSP_DIR . 'includes/class-ansp-ai-bio.php';
require_once ANSP_DIR . 'includes/class-ansp-rest.php';

/**
 * Boot the plugin.
 *
 * Every component is a small class that registers its own hooks in its
 * constructor. We instantiate each exactly once on plugins_loaded.
 *
 * @return void
 */
function ansp_init() {
	static $booted = false;

	if ( $booted ) {
		return;
	}
	$booted = true;

	load_plugin_textdomain( 'ans-singers-portal', false, dirname( plugin_basename( ANSP_FILE ) ) . '/languages' );

	new ANSP_Taxonomies();
	new ANSP_Group_Fields(); // Drive folder mapping + filter tag on each Group.
	new ANSP_Singer_CPT(); // Absorbed singer directory — stands down while the old Directory plugin is active.
	new ANSP_Singers_Public(); // Public [ans_singers] page + the Active singer switch.
	new ANSP_Singer_Admin();  // Singers list screen: Quick Edit for parts, groups, Active.
	new ANSP_Profile_Link();  // user <-> singer profile link (drives the My Bio tab).
	new ANSP_Registration();  // code-gated self-registration.
	new ANSP_Invitations();   // send codes + track who acted on them.
	new ANSP_CPT();
	new ANSP_Materials();
	new ANSP_Project_Meta();
	new ANSP_Profiles();
	new ANSP_Login();
	new ANSP_Portal();
	new ANSP_Announcements();
	new ANSP_Calendar();
	new ANSP_Notifications();
	new ANSP_RSVP();
	new ANSP_Offboarding();
	new ANSP_Dashboard();
	new ANSP_Bio_Editor();
	new ANSP_AI_Bio();
	new ANSP_REST();     // Admin-only REST surface: everything the portal owns, reachable by API.
}
add_action( 'plugins_loaded', 'ansp_init' );

/**
 * Activation routine.
 *
 * - Creates the portal roles (singer, artistic_director, personnel_manager).
 * - Registers the taxonomies + CPTs directly (activation runs after
 *   plugins_loaded, so our init hooks have not fired) and flushes rewrites.
 * - Seeds the four default Groups idempotently.
 * - Creates the /portal/ page containing the shortcode if one does not exist.
 *
 * @return void
 */
function ansp_activate() {
	ANSP_Roles::create_roles();

	// Register content types so flush_rewrite_rules() knows about them.
	ANSP_Taxonomies::register_taxonomies();
	ANSP_Singer_CPT::register_post_type(); // Guarded: no-op while the old Directory plugin is active.
	ANSP_Taxonomies::attach_to_singer();
	ANSP_CPT::register_post_type();
	ANSP_Announcements::register_post_type();

	ANSP_Taxonomies::seed_default_groups();

	ansp_maybe_create_portal_page();

	flush_rewrite_rules();
}
register_activation_hook( ANSP_FILE, 'ansp_activate' );

/**
 * Deactivation routine. Flush rewrites only — roles and data are kept.
 *
 * @return void
 */
function ansp_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( ANSP_FILE, 'ansp_deactivate' );

/**
 * Create the /portal/ page holding the [ans_singers_portal] shortcode if it
 * does not already exist. Idempotent: re-activation reuses the stored page.
 *
 * @return int Page ID (0 on failure).
 */
function ansp_maybe_create_portal_page() {
	$page_id = (int) get_option( 'ansp_portal_page_id', 0 );

	if ( $page_id ) {
		$page = get_post( $page_id );
		if ( $page instanceof WP_Post && 'trash' !== $page->post_status ) {
			return $page_id;
		}
	}

	// A page at /portal/ may already exist (e.g. created by hand).
	$existing = get_page_by_path( 'portal', OBJECT, 'page' );
	if ( $existing instanceof WP_Post && 'trash' !== $existing->post_status ) {
		update_option( 'ansp_portal_page_id', (int) $existing->ID );
		return (int) $existing->ID;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => __( 'Singers Portal', 'ans-singers-portal' ),
			'post_name'    => 'portal',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[ans_singers_portal]',
		)
	);

	if ( is_wp_error( $page_id ) || ! $page_id ) {
		return 0;
	}

	update_option( 'ansp_portal_page_id', (int) $page_id );

	return (int) $page_id;
}
