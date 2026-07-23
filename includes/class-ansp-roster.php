<?php
/**
 * Roster: query visible singers and honor per-field privacy.
 *
 * Singers see roster entries filtered to their own group(s); portal
 * managers see everyone. Inactive (offboarded) profiles are hidden.
 * Each card only exposes the fields flagged "visible to choir".
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_Roster
 */
class ANSP_Roster {

	/**
	 * The singer profiles a user may see on the roster.
	 *
	 * @param int|null $user_id Viewer (defaults to current user).
	 * @return WP_Post[]
	 */
	public static function get_visible_singers( $user_id = null ) {
		if ( ! post_type_exists( 'singer' ) ) {
			return array(); // Host CPT absent — never fatal.
		}

		$user_id    = $user_id ? (int) $user_id : get_current_user_id();
		$is_manager = ANSP_Permissions::is_manager( $user_id );

		$args = array(
			'post_type'              => 'singer',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			// Exclude offboarded/inactive profiles.
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => 'ansp_inactive',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'ansp_inactive',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		if ( ! $is_manager ) {
			$slugs = ANSP_Permissions::get_user_group_slugs( $user_id );
			if ( empty( $slugs ) ) {
				return array(); // No group yet → nothing to show (privacy-first).
			}
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'ans_group',
					'field'    => 'slug',
					'terms'    => $slugs,
				),
			);
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * The choir-visible display fields for one roster card.
	 * Voice part is always included; privacy-toggled fields only when the
	 * singer (or Personnel Manager) flagged them visible. Managers see all.
	 *
	 * @param int  $profile_id  Singer profile ID.
	 * @param bool $show_hidden True for managers (bypass privacy flags).
	 * @return array<string,array{label:string,value:string,type:string}>
	 */
	public static function get_card_fields( $profile_id, $show_hidden = false ) {
		$profile_id = (int) $profile_id;
		$out        = array();

		// Voice part(s): canonical `parts` meta (legacy voice_part fallback).
		// Always visible — no privacy toggle, like v1.0's voice part field.
		$parts = class_exists( 'ANSP_Singer_CPT' ) ? ANSP_Singer_CPT::parts_display( $profile_id ) : '';
		if ( '' !== $parts ) {
			$out['voice_part'] = array(
				'label' => __( 'Voice part', 'ans-singers-portal' ),
				'value' => $parts,
				'type'  => 'text',
			);
		}

		// Portal contact fields (ansp_email / ansp_phone) with privacy.
		foreach ( ANSP_Profiles::fields() as $key => $field ) {
			if ( ! $show_hidden && ! ANSP_Profiles::is_field_visible( $profile_id, $key ) ) {
				continue;
			}
			$value = ANSP_Profiles::get_field( $profile_id, $key );
			if ( '' === $value ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => $field['label'],
				'value' => $value,
				'type'  => $field['type'],
			);
		}

		// Canonical profile-detail fields (privacy key => meta key).
		$canonical = array(
			'pronouns'       => array( __( 'Pronouns', 'ans-singers-portal' ), 'pronouns', 'text' ),
			'years'          => array( __( 'Years with the group', 'ans-singers-portal' ), 'years_with_group', 'text' ),
			'favorite_piece' => array( __( 'Favorite piece', 'ans-singers-portal' ), 'favorite_piece', 'text' ),
			'favorite_quote' => array( __( 'Favorite quote', 'ans-singers-portal' ), 'favorite_quote', 'text' ),
		);
		foreach ( $canonical as $privacy_key => $def ) {
			if ( ! $show_hidden && ! ANSP_Profiles::is_field_visible( $profile_id, $privacy_key ) ) {
				continue;
			}
			$value = (string) get_post_meta( $profile_id, $def[1], true );
			if ( '' === $value || '0' === $value ) {
				continue;
			}
			$out[ $privacy_key ] = array(
				'label' => $def[0],
				'value' => $value,
				'type'  => $def[2],
			);
		}

		// Bio: canonical storage is the singer post's content.
		if ( $show_hidden || ANSP_Profiles::is_field_visible( $profile_id, 'bio' ) ) {
			$post = get_post( $profile_id );
			$bio  = $post instanceof WP_Post ? trim( (string) $post->post_content ) : '';
			if ( '' !== $bio ) {
				$out['bio'] = array(
					'label' => __( 'Bio', 'ans-singers-portal' ),
					'value' => $bio,
					'type'  => 'textarea',
				);
			}
		}

		return $out;
	}

	/**
	 * Group names for a profile (for the card badges).
	 *
	 * @param int $profile_id Singer profile ID.
	 * @return string[]
	 */
	public static function get_group_names( $profile_id ) {
		$terms = wp_get_object_terms( (int) $profile_id, 'ans_group' );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'name' );
	}
}
