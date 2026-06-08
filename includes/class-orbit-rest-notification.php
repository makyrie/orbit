<?php
/**
 * REST API notification controller.
 *
 * Handles phone verification, Twilio webhooks, and notification log.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_Notification
 */
class Orbit_REST_Notification {

	/**
	 * Register notification-related routes.
	 */
	public static function register_routes() {
		$ns = Orbit_REST_API::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/twilio/incoming',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_twilio_incoming' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/verify-phone',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_verify_phone' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'phone' => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'code'  => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_phone_status' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);

		register_rest_route(
			$ns,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_notifications' ),
				'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
				'args'                => array(
					'user_id' => array( 'sanitize_callback' => 'absint' ),
					'method'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'status'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Handle incoming Twilio webhook.
	 *
	 * Validates the signature against the exact URL the route serves,
	 * then dispatches keyword handling. Returns TwiML to Twilio — empty
	 * `<Response>` for keywords with no reply, or a `<Message>` body for
	 * HELP / STOP / START confirmations per CTIA.
	 *
	 * Invalid-signature requests return 204 No Content rather than 403.
	 * Twilio's retry policy treats 4xx/5xx as "retry for up to 24h with
	 * exponential backoff" — a misconfigured Messaging Service URL during
	 * a routing change would otherwise flood this endpoint with hours of
	 * bad-signature retries. 204 silently drops the request from Twilio's
	 * perspective and avoids the retry storm. Ops still gets signal via
	 * the internal error_log() entry below.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_twilio_incoming( $request ) {
		$expected_url = rest_url( Orbit_REST_API::API_NAMESPACE . '/twilio/incoming' );

		if ( ! Orbit_Twilio::validate_webhook( $request, $expected_url ) ) {
			// Log so ops keeps visibility on bad-signature traffic without
			// returning a status code that Twilio will retry.
			error_log( 'Orbit_Twilio: rejected incoming webhook with invalid signature.' );
			return new WP_REST_Response( null, 204 );
		}

		$result = Orbit_Twilio::handle_incoming( $request );

		// Twilio expects TwiML XML response.
		$reply = isset( $result['twiml_reply'] ) && '' !== $result['twiml_reply']
			? $result['twiml_reply']
			: '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TwiML XML is built internally via twiml_reply() with esc_html() on the body.
		header( 'Content-Type: text/xml; charset=UTF-8' );
		echo $reply; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Handle phone verification (send code or verify code).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_verify_phone( $request ) {
		$user_id = get_current_user_id();
		$phone   = $request->get_param( 'phone' );
		$code    = $request->get_param( 'code' );

		if ( $phone ) {
			$result = Orbit_Phone_Verify::send_code( $user_id, $phone );

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
			}

			return new WP_REST_Response(
				array( 'message' => __( 'Verification code sent.', 'orbit' ) ),
				200
			);
		}

		if ( $code ) {
			$result = Orbit_Phone_Verify::verify_code( $user_id, $code );

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
			}

			return new WP_REST_Response(
				array(
					'verified' => true,
					'message'  => __( 'Phone number verified.', 'orbit' ),
				),
				200
			);
		}

		return new WP_Error( 'missing_params', __( 'Provide either phone or code.', 'orbit' ), array( 'status' => 400 ) );
	}

	/**
	 * Get the current phone verification state for the logged-in user.
	 *
	 * Inspection primitive: agents and the UI can call this instead of
	 * re-deriving state from raw user_meta and `defined()` checks.
	 *
	 * @return WP_REST_Response Response.
	 */
	public static function get_phone_status() {
		$user_id = get_current_user_id();
		$payload = self::build_phone_status( $user_id );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Build the phone verification state payload for a user.
	 *
	 * Returned shape:
	 * - phone (string|null): currently-verified phone, or null.
	 * - verified (bool): whether the user's phone is verified.
	 * - state (string): one of `no_phone`, `pending`, `verified`, `unavailable`.
	 * - twilio_configured (bool): whether all Twilio constants are defined.
	 * - pending_phone (string|null): candidate phone in the latest non-expired row, or null.
	 * - pending_code_expires_at (string|null): ISO 8601 expiry of the latest non-expired row, or null.
	 *
	 * State derivation:
	 * - `unavailable` if Twilio is not configured (other fields filled best-effort).
	 * - `verified` if `orbit_phone_verified` is truthy AND `orbit_phone` is non-empty.
	 * - `pending` if there is a non-expired row in the verification table for this user.
	 * - `no_phone` otherwise.
	 *
	 * @param int $user_id User ID.
	 * @return array Phone status payload.
	 */
	public static function build_phone_status( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		$twilio_configured = defined( 'ORBIT_TWILIO_ACCOUNT_SID' )
			&& defined( 'ORBIT_TWILIO_AUTH_TOKEN' )
			&& defined( 'ORBIT_TWILIO_FROM_NUMBER' );

		$phone    = get_user_meta( $user_id, 'orbit_phone', true );
		$verified = (bool) get_user_meta( $user_id, 'orbit_phone_verified', true );

		// Look up the latest non-expired pending verification row, if any.
		$pending_phone           = null;
		$pending_code_expires_at = null;

		if ( $user_id > 0 ) {
			$table = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
			$now   = current_time( 'mysql', true );

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT phone, expires_at FROM {$table} WHERE user_id = %d AND expires_at > %s ORDER BY created_at DESC LIMIT 1",
					$user_id,
					$now
				)
			);

			if ( $row ) {
				$pending_phone           = $row->phone ? (string) $row->phone : null;
				$pending_code_expires_at = $row->expires_at
					? mysql_to_rfc3339( $row->expires_at )
					: null;
			}
		}

		// Derive state.
		if ( ! $twilio_configured ) {
			$state = 'unavailable';
		} elseif ( $verified && ! empty( $phone ) ) {
			$state = 'verified';
		} elseif ( null !== $pending_phone || null !== $pending_code_expires_at ) {
			$state = 'pending';
		} else {
			$state = 'no_phone';
		}

		return array(
			'phone'                   => ! empty( $phone ) ? (string) $phone : null,
			'verified'                => $verified,
			'state'                   => $state,
			'twilio_configured'       => $twilio_configured,
			'pending_phone'           => $pending_phone,
			'pending_code_expires_at' => $pending_code_expires_at,
		);
	}

	/**
	 * Get notification log.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_notifications( $request ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$where  = array( '1=1' );
		$values = array();

		if ( $request->get_param( 'user_id' ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $request->get_param( 'user_id' ) );
		}

		if ( $request->get_param( 'method' ) ) {
			$where[]  = 'method = %s';
			$values[] = sanitize_text_field( $request->get_param( 'method' ) );
		}

		if ( $request->get_param( 'status' ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 100";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		$logs = $wpdb->get_results( $sql );

		return new WP_REST_Response( $logs, 200 );
	}
}
