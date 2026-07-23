<?php
/**
 * "Compose with AI" — Gemini-powered bio drafting for the My Bio tab.
 *
 * A nonce-protected AJAX endpoint (wp_ajax_ansp_ai_bio) takes a short
 * "notes" string, adds the singer's name and voice part(s), asks Google
 * Gemini for a warm 2–4 sentence choir-member bio and returns the draft as
 * JSON. The singer then edits the draft in the bio field before saving —
 * nothing is stored automatically.
 *
 * - Model: gemini-2.0-flash by default, filterable via
 *   apply_filters( 'ansp_gemini_model', 'gemini-2.0-flash' ).
 * - API key: option ansp_gemini_api_key (Singers Portal → Settings).
 * - All failure modes return a friendly JSON error message.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class ANSP_AI_Bio
 */
class ANSP_AI_Bio {

	/**
	 * Hook the AJAX action (logged-in users only — no nopriv handler).
	 */
	public function __construct() {
		add_action( 'wp_ajax_ansp_ai_bio', array( $this, 'handle' ) );
	}

	/**
	 * The Gemini model to use (filterable).
	 *
	 * @return string
	 */
	public static function model() {
		$model = apply_filters( 'ansp_gemini_model', 'gemini-2.0-flash' );
		$model = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $model );
		return '' !== $model ? $model : 'gemini-2.0-flash';
	}

	/**
	 * AJAX handler: verify nonce + capability, call Gemini, return the draft.
	 *
	 * @return void
	 */
	public function handle() {
		check_ajax_referer( 'ansp_ai_bio', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! current_user_can( 'ansp_edit_own_bio' ) && ! ANSP_Permissions::is_manager( $user_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to use AI compose.', 'ans-singers-portal' ) ),
				403
			);
		}

		$api_key = trim( (string) get_option( 'ansp_gemini_api_key', '' ) );
		if ( '' === $api_key ) {
			wp_send_json_error(
				array( 'message' => __( "AI compose isn't set up yet — add a Gemini API key in Singers Portal → Settings.", 'ans-singers-portal' ) )
			);
		}

		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$notes = mb_substr( $notes, 0, 1500 );
		} else {
			$notes = substr( $notes, 0, 1500 );
		}

		// The singer's name + voice part(s) from their linked profile.
		$name  = '';
		$parts = '';
		$profile = ANSP_Profiles::get_profile_for_user( $user_id );
		if ( $profile instanceof WP_Post ) {
			$name = get_the_title( $profile );
			if ( class_exists( 'ANSP_Singer_CPT' ) ) {
				$parts = ANSP_Singer_CPT::parts_display( $profile->ID );
			}
		}
		if ( '' === $name ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->display_name : '';
		}

		$prompt  = "Write a warm, friendly biography for a member of the Ars Nova Singers, a professional chamber choir. ";
		$prompt .= "The bio must be 2 to 4 sentences, written in the third person, suitable for the choir's public website. ";
		$prompt .= "Do not use markdown, headings or quotation marks around the bio — return ONLY the bio text itself.\n\n";
		if ( '' !== $name ) {
			$prompt .= 'Singer name: ' . $name . "\n";
		}
		if ( '' !== $parts ) {
			$prompt .= 'Voice part: ' . $parts . "\n";
		}
		if ( '' !== $notes ) {
			$prompt .= 'Notes from the singer to work from: ' . $notes . "\n";
		} else {
			$prompt .= "No extra notes were provided — keep it graceful and general.\n";
		}

		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. rawurlencode( self::model() )
			. ':generateContent?key=' . rawurlencode( $api_key );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'contents' => array(
							array(
								'parts' => array(
									array( 'text' => $prompt ),
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not reach the AI service. Please check your connection and try again.', 'ans-singers-portal' ) )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = '';
			if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
				$detail = ' (' . sanitize_text_field( (string) $body['error']['message'] ) . ')';
			}
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: HTTP status code, 2: optional API error detail */
						__( 'The AI service returned an error (HTTP %1$d)%2$s. Please try again in a moment.', 'ans-singers-portal' ),
						$code,
						$detail
					),
				)
			);
		}

		// Parse candidates[0].content.parts[].text.
		$text = '';
		if ( is_array( $body ) && ! empty( $body['candidates'][0]['content']['parts'] ) && is_array( $body['candidates'][0]['content']['parts'] ) ) {
			foreach ( $body['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		$text = trim( sanitize_textarea_field( $text ) );

		if ( '' === $text ) {
			wp_send_json_error(
				array( 'message' => __( 'The AI service did not return a usable draft. Please try again.', 'ans-singers-portal' ) )
			);
		}

		wp_send_json_success( array( 'bio' => $text ) );
	}
}
