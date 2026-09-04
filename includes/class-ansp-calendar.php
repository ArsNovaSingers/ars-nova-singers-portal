<?php
/**
 * Calendars: one Google Calendar ID per ensemble, stored as options and
 * rendered as an embed with one-click Subscribe (Google / iCal) links.
 *
 * Options are named `ansp_calendar_<group_slug>` and the set of them is
 * derived from the ans_group taxonomy at runtime — see slots(). There is no
 * fixed list; adding an ensemble in wp-admin adds its calendar field.
 *
 * Since 1.32.0 a calendar is shown INSIDE its own group's Materials tab
 * rather than in a separate Calendar tab of its own. A singer looking for
 * the rehearsal schedule looks where that ensemble's music is.
 *
 * Portal managers see every configured calendar; a singer sees the calendars
 * of the groups they hold, ancestors included.
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
	 * Calendar option slots: option name => [group slug, label].
	 *
	 * DERIVED FROM THE LIVE TAXONOMY, NOT HARDCODED. Until 1.32.0 this
	 * returned three fixed slots — `main`, `small` and `friday` — the group
	 * slugs the portal was first designed against. Those groups do not exist
	 * on the production site, where the tree is `ans` (with `es` / `fc` / `hs`
	 * beneath it) and `cs`.
	 *
	 * The consequence was not a cosmetic mislabel. get_calendars_for_user()
	 * matches a slot's slug against the viewer's own group slugs, so a slug
	 * nobody holds can never match: every singer got an empty Calendar tab and
	 * the settings screen offered three fields that could not reach anybody.
	 * Nothing errored, which is why it survived so long — the same shape as
	 * every other bug this branch has recorded. An empty result is not an
	 * answer.
	 *
	 * One slot per TAB-MAKING group — top-level, and not opted out of a tab —
	 * so the calendar list is exactly the ensembles a singer already gets a
	 * Materials tab for. Add an ensemble in wp-admin and its calendar field
	 * appears by itself; there is no second list to keep in step.
	 *
	 * Sub-groups deliberately get no slot. An Ensemble Singer rehearses on the
	 * Ars Nova calendar; giving `es` its own would split one schedule in two
	 * and leave singers guessing which is authoritative.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function slots() {
		if ( ! taxonomy_exists( 'ans_group' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'ans_group',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			/*
			 * The same opt-out the Materials tabs honour. Board Member has its
			 * own portal and no business in a singer's calendar list.
			 */
			if ( class_exists( 'ANSP_Group_Fields' )
				&& get_term_meta( (int) $term->term_id, ANSP_Group_Fields::META_NO_TAB, true ) ) {
				continue;
			}
			$out[ self::option_name( $term->slug ) ] = array( $term->slug, $term->name );
		}

		return $out;
	}

	/**
	 * Option name holding one group's calendar ID.
	 *
	 * `ansp_calendar_<slug>` is what the pre-1.32.0 fixed slots were already
	 * called, so a site whose groups kept their old slugs keeps its saved
	 * values and nothing needs migrating.
	 *
	 * @param string $group_slug ans_group slug.
	 * @return string
	 */
	public static function option_name( $group_slug ) {
		return 'ansp_calendar_' . str_replace( '-', '_', sanitize_key( (string) $group_slug ) );
	}

	/**
	 * One group's calendar, or null when that group has none configured.
	 *
	 * Used by the Calendar sub-tab inside a group's own Materials tab, which
	 * is where a calendar now lives: a singer looks for the schedule beside
	 * that ensemble's music, not in a separate tab that mixes every group's
	 * calendars together.
	 *
	 * @param string $group_slug ans_group slug.
	 * @return array{label:string,id:string}|null
	 */
	public static function get_calendar_for_group( $group_slug ) {
		$group_slug = sanitize_key( (string) $group_slug );
		if ( '' === $group_slug ) {
			return null;
		}

		$id = trim( (string) get_option( self::option_name( $group_slug ), '' ) );
		if ( '' === $id ) {
			return null;
		}

		$label = $group_slug;
		if ( taxonomy_exists( 'ans_group' ) ) {
			$term = get_term_by( 'slug', $group_slug, 'ans_group' );
			if ( $term instanceof WP_Term ) {
				$label = $term->name;
			}
		}

		return array(
			'label' => $label,
			'id'    => $id,
		);
	}

	/**
	 * Register one option per ensemble.
	 *
	 * Runs on admin_init, which is after taxonomies are registered, so
	 * slots() can safely query terms here.
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

		/*
		 * EFFECTIVE slugs, so ancestors count. Calendar slots exist only for
		 * top-level groups, and a singer is usually tagged with a child —
		 * `es`, `fc`, `hs`. Matching on their own slugs alone would mean an
		 * Ensemble Singer never matched the `ans` slot and saw no calendar at
		 * all, which is the same intersect-against-the-wrong-level mistake
		 * ANSP_Permissions::get_user_effective_group_slugs() exists to fix.
		 */
		$effective = ANSP_Permissions::get_user_effective_group_slugs( $user_id );

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
			<p><?php esc_html_e( 'One calendar per ensemble. Paste the Google Calendar ID (Google Calendar → Settings → "Integrate calendar" → Calendar ID, e.g. abc123@group.calendar.google.com). Calendars must be public (or shared) for embeds and iCal subscriptions to work.', 'ans-singers-portal' ); ?></p>
			<p class="description"><?php esc_html_e( 'This list comes from your ensembles, so it is always in step with them. Each calendar appears inside that ensemble\'s own tab in the Singers Hub.', 'ans-singers-portal' ); ?></p>
			<?php if ( empty( self::slots() ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No ensembles are set up yet, so there is nothing to give a calendar to. Add a top-level group under Singers Portal → Groups first.', 'ans-singers-portal' ); ?></p></div>
			<?php endif; ?>
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
