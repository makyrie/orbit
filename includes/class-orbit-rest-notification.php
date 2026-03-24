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
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_twilio_incoming( $request ) {
		if ( ! Orbit_Twilio::validate_webhook( $request ) ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid webhook signature.', 'orbit' ), array( 'status' => 403 ) );
		}

		Orbit_Twilio::handle_incoming( $request );

		// Twilio expects TwiML XML response.
		header( 'Content-Type: text/xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
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
