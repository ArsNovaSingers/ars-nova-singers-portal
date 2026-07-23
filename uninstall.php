<?php
/**
 * Uninstall handler for Ars Nova Singers Portal.
 *
 * DEFAULT BEHAVIOUR: KEEP EVERYTHING. Projects, materials, announcements,
 * roles, user↔profile links, RSVPs and taxonomy terms all survive an
 * uninstall so the choir's data is never lost by accident.
 *
 * To opt in to cleanup, add this to wp-config.php BEFORE deleting the
 * plugin:
 *
 *     define( 'ANSP_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * With the flag set, plugin OPTIONS are removed and the custom roles are
 * dropped. Posts/meta/terms are still intentionally left in place (delete
 * them manually if truly desired).
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ANSP_REMOVE_DATA_ON_UNINSTALL' ) || ! ANSP_REMOVE_DATA_ON_UNINSTALL ) {
	return; // Keep all data (default).
}

// ---- Options ---------------------------------------------------------------
$ansp_options = array(
	'ansp_portal_page_id',
	'ansp_current_season',
	'ansp_default_permission_all',
	'ansp_invite_email_subject',
	'ansp_invite_email_body',
	'ansp_calendar_main',
	'ansp_calendar_small',
	'ansp_calendar_friday',
	'ansp_gemini_api_key',
);
foreach ( $ansp_options as $ansp_option ) {
	delete_option( $ansp_option );
}

// ---- Roles -----------------------------------------------------------------
require_once __DIR__ . '/includes/class-ansp-roles.php';
if ( class_exists( 'ANSP_Roles' ) ) {
	ANSP_Roles::remove_roles();
}
