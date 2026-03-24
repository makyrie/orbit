<?php
/**
 * Twilio API wrapper.
 *
 * Uses wp_remote_post() — no SDK dependency.
 * Expects ORBIT_TWILIO_SID, ORBIT_TWILIO_AUTH_TOKEN, and ORBIT_TWILIO_FROM
 * to be defined in wp-config.php.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Twilio
 */
class Orbit_Twilio {

	/**
	 * Send an SMS via Twilio.
	 *
	 * @param string $to   Recipient phone number in E.164 format.
	 * @param string $body SMS message body.
	 * @return true|WP_Error True on success.
	 */
	public static function send_sms( $to, $body ) {
		if ( ! defined( 'ORBIT_TWILIO_SID' ) || ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) || ! defined( 'ORBIT_TWILIO_FROM' ) ) {
			return new WP_Error( 'twilio_not_configured', __( 'Twilio credentials are not configured.', 'orbit' ) );
		}

		$url = sprintf(
			'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
			ORBIT_TWILIO_SID
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( ORBIT_TWILIO_SID . ':' . ORBIT_TWILIO_AUTH_TOKEN ),
				),
				'body'    => array(
					'To'   => $to,
					'From' => ORBIT_TWILIO_FROM,
					'Body' => $body,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$message      = isset( $body_decoded['message'] ) ? $body_decoded['message'] : __( 'Unknown Twilio error.', 'orbit' );

			return new WP_Error( 'twilio_api_error', $message, array( 'status' => $code ) );
		}

		return true;
	}

	/**
	 * Validate a Twilio webhook request signature.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool True if valid.
	 */
	public static function validate_webhook( $request ) {
		if ( ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) ) {
			return false;
		}

		$signature = $request->get_header( 'X-Twilio-Signature' );
		if ( ! $signature ) {
			return false;
		}

		$url    = rest_url( 'orbit/v1/twilio/incoming' );
		$params = $request->get_body_params();

		// Sort params by key.
		ksort( $params );

		$data = $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . $value;
		}

		$expected = base64_encode( hash_hmac( 'sha1', $data, ORBIT_TWILIO_AUTH_TOKEN, true ) );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Handle an incoming Twilio webhook (STOP/START keywords).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array Response data.
	 */
	public static function handle_incoming( $request ) {
		$from = sanitize_text_field( $request->get_param( 'From' ) );
		$body = strtoupper( trim( sanitize_text_field( $request->get_param( 'Body' ) ) ) );

		if ( empty( $from ) ) {
			return array( 'status' => 'ignored', 'reason' => 'no_from' );
		}

		// Find user by phone number.
		$users = get_users(
			array(
				'meta_key'   => 'orbit_phone',
				'meta_value' => $from,
				'number'     => 1,
			)
		);

		if ( empty( $users ) ) {
			return array( 'status' => 'ignored', 'reason' => 'unknown_number' );
		}

		$user_id = $users[0]->ID;

		// TCPA-compliant STOP/START handling.
		if ( 'STOP' === $body || 'STOPALL' === $body || 'UNSUBSCRIBE' === $body || 'CANCEL' === $body || 'END' === $body || 'QUIT' === $body ) {
			update_user_meta( $user_id, 'orbit_sms_opted_out', 1 );

			return array( 'status' => 'opted_out', 'user_id' => $user_id );
		}

		if ( 'START' === $body || 'YES' === $body || 'UNSTOP' === $body ) {
			delete_user_meta( $user_id, 'orbit_sms_opted_out' );

			return array( 'status' => 'opted_in', 'user_id' => $user_id );
		}

		return array( 'status' => 'ignored', 'reason' => 'unrecognized_keyword' );
	}

	/**
	 * Check if a user has opted out of SMS via STOP.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if opted out.
	 */
	public static function is_sms_opted_out( $user_id ) {
		return (bool) get_user_meta( $user_id, 'orbit_sms_opted_out', true );
	}
}
