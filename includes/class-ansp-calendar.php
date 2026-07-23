<?php
/**
 * Calendars: three Google Calendar IDs stored as options, rendered as
 * per-group embeds with one-click Subscribe (Google / iCal) links.
 *
 * Options:
 * - ansp_calendar_main   : Main Group calendar ID
 * - ansp_calendar_small  : Small Group calendar ID
 * - ansp_calendar_friday : Friday Group calendar ID
 *
 * Special Guests (and singers in no group) see the Main calendar; portal
 * managers see every configured calendar.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Calendar
 */
class ANSP_Calendar {

	/**
	 * Hook option registration.
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		// Let artistic_director / personnel_manager save this options group.
		add_filter( 'option_page_capability_ansp_calendars', array( __CLASS__, 'options_capability' ) );
	}

	/**
	 * Calendar option slots: option suffix => [group slug, label].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function slots() {
		return array(
			'ansp_calendar_main'   => array( 'main', __( 'Main Group calendar', 'ans-singers-portal' ) ),
			'ansp_calendar_small'  => array( 'small', __( 'Small Group calendar', 'ans-singers-portal' ) ),
			'ansp_calendar_friday' => array( 'friday', __( 'Friday Group calendar', 'ans-singers-portal' ) ),
		);
	}

	/**
	 * Register the three options.
	 *
	 * @return void
	 */
	public static function register_settings() {
		foreach ( array_keys( self::slots() ) as $option ) {
			register_setting(
				'ansp_calendars',
				$option,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_calendar_id' ),
					'default'           => '',
				)
			);
		}
	}

	/**
	 * Capability for saving the calendars option page.
	 *
	 * @return string
	 */
	public static function options_capability() {
		return 'ansp_manage_portal';
	}

	/**
	 * Google Calendar IDs look like "something@group.calendar.google.com"
	 * or a plain address; keep a conservative character set.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize_calendar_id( $value ) {
		$value = trim( (string) $value );
		return preg_replace( '/[^A-Za-z0-9@._%+\-]/', '', $value );
	}

	/**
	 * The calendars a user should see: slug => [label, calendar id].
	 *
	 * @param int|null $user_id Viewer.
	 * @return array<string,array{label:string,id:string}>
	 */
	public static function get_calendars_for_user( $user_id = null ) {
		$user_id    = $user_id ? (int) $user_id : get_current_user_id();
		$is_manager = ANSP_Permissions::is_manager( $user_id );
		$groups     = ANSP_Permissions::get_user_group_slugs( $user_id );

		// Special Guests / group-less singers default to the Main calendar.
		$effective = $groups;
		if ( ! $is_manager && ( empty( $effective ) || array( 'special-guests' ) === array_values( array_intersect( $effective, array( 'special-guests' ) ) ) && ! array_intersect( $effective, array( 'main', 'small', 'friday' ) ) ) ) {
			$effective[] = 'main';
		}

		$out = array();
		foreach ( self::slots() as $option => $slot ) {
			list( $slug, $label ) = $slot;
			$id = (string) get_option( $option, '' );
			if ( '' === $id ) {
				continue;
			}
			if ( $is_manager || in_array( $slug, $effective, true ) ) {
				$out[ $slug ] = array(
					'label' => $label,
					'id'    => $id,
				);
			}
		}
		return $out;
	}

	/**
	 * Embed URL for a calendar ID.
	 *
	 * @param string $calendar_id Google Calendar ID.
	 * @return string
	 */
	public static function embed_url( $calendar_id ) {
		return 'https://calendar.google.com/calendar/embed?src=' . rawurlencode( $calendar_id ) . '&ctz=' . rawurlencode( wp_timezone_string() );
	}

	/**
	 * "Add to Google Calendar" subscribe URL.
	 *
	 * @param string $calendar_id Google Calendar ID.
	 * @return string
	 */
	public static function google_subscribe_url( $calendar_id ) {
		return 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( $calendar_id );
	}

	/**
	 * iCal subscription URL (public basic feed).
	 *
	 * @param string $calendar_id Google Calendar ID.
	 * @return string
	 */
	public static function ical_url( $calendar_id ) {
		return 'https://calendar.google.com/calendar/ical/' . rawurlencode( $calendar_id ) . '/public/basic.ics';
	}

	/**
	 * Admin page (registered as a Singers Portal submenu by ANSP_Dashboard).
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'ansp_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage portal calendars.', 'ans-singers-portal' ) );
		}
		?>
		<div class="wrap ansp-admin-wrap">
			<h1><?php esc_html_e( 'Portal Calendars', 'ans-singers-portal' ); ?></h1>
			<p><?php esc_html_e( 'Paste the Google Calendar ID for each group (Google Calendar → Settings → "Integrate calendar" → Calendar ID, e.g. abc123@group.calendar.google.com). Calendars must be public (or shared) for embeds and iCal subscriptions to work.', 'ans-singers-portal' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'ansp_calendars' ); ?>
				<table class="form-table">
					<?php foreach ( self::slots() as $option => $slot ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $slot[1] ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( (string) get_option( $option, '' ) ); ?>" placeholder="abc123@group.calendar.google.com" />
								<?php $current = (string) get_option( $option, '' ); ?>
								<?php if ( $current ) : ?>
									<p class="description">
										<a href="<?php echo esc_url( self::google_subscribe_url( $current ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google subscribe link', 'ans-singers-portal' ); ?></a>
										·
										<a href="<?php echo esc_url( self::ical_url( $current ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'iCal feed', 'ans-singers-portal' ); ?></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save Calendars', 'ans-singers-portal' ) ); ?>
			</form>
		</div>
		<?php
	}
}
