<?php
/**
 * Twilio API wrapper.
 *
 * Uses wp_remote_post() — no SDK dependency.
 * Expects ORBIT_TWILIO_ACCOUNT_SID, ORBIT_TWILIO_AUTH_TOKEN, and ORBIT_TWILIO_FROM_NUMBER
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
		if ( ! defined( 'ORBIT_TWILIO_ACCOUNT_SID' ) || ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) || ! defined( 'ORBIT_TWILIO_FROM_NUMBER' ) ) {
			return new WP_Error( 'twilio_not_configured', __( 'Twilio credentials are not configured.', 'orbit' ) );
		}

		$url = sprintf(
			'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
			ORBIT_TWILIO_ACCOUNT_SID
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( ORBIT_TWILIO_ACCOUNT_SID . ':' . ORBIT_TWILIO_AUTH_TOKEN ),
				),
				'body'    => array(
					'To'   => $to,
					'From' => ORBIT_TWILIO_FROM_NUMBER,
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
	 * Validate a Twilio webhook request signature against a specific URL.
	 *
	 * Twilio's signature scheme requires the *exact* URL the customer
	 * registered (including query string). Hard-coding one URL would mean
	 * a future second webhook route (e.g. delivery status callbacks)
	 * couldn't reuse this method without bypassing validation. The URL
	 * is therefore a parameter — each caller passes the route it serves.
	 *
	 * Important: callers MUST pass the full URL including any query string
	 * exactly as registered with Twilio. Twilio computes the signature
	 * over `URL + sorted(body_params)`, where query-string params are
	 * embedded in the URL itself rather than appended as separate fields.
	 * Future routes that register webhooks with query parameters must
	 * therefore pass `rest_url( ... ) . '?foo=bar'` (or equivalent) here.
	 *
	 * @param WP_REST_Request $request      The incoming request.
	 * @param string          $expected_url The exact URL Twilio used to
	 *                                      compute the signature (must
	 *                                      match the registered webhook
	 *                                      URL on the Twilio console,
	 *                                      including any query string).
	 * @return bool True if valid.
	 */
	public static function validate_webhook( $request, $expected_url ) {
		if ( ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) ) {
			return false;
		}

		$signature = $request->get_header( 'X-Twilio-Signature' );
		if ( ! $signature ) {
			return false;
		}

		$params = $request->get_body_params();

		// Fallback for JSON bodies — Twilio's standard webhook is
		// form-encoded, but other tools (e.g. internal replay scripts)
		// may submit application/json. get_body_params() returns empty
		// for non-form content types; get_json_params() handles JSON.
		if ( empty( $params ) ) {
			$json_params = $request->get_json_params();
			$params      = is_array( $json_params ) ? $json_params : array();
		}

		// Sort params by key.
		ksort( $params );

		$data = $expected_url;
		foreach ( $params as $key => $value ) {
			// Reject array-typed values (e.g. crafted `Body[]=foo&Body[]=bar`
			// inputs) cleanly rather than triggering a "Array to string
			// conversion" PHP notice on concatenation.
			if ( is_array( $value ) ) {
				return false;
			}
			$data .= $key . $value;
		}

		$expected = base64_encode( hash_hmac( 'sha1', $data, ORBIT_TWILIO_AUTH_TOKEN, true ) );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Handle an incoming Twilio webhook (STOP/START/HELP keywords).
	 *
	 * Returns a structured array; callers compose TwiML via
	 * self::twiml_reply() for keywords that require an outbound reply
	 * (HELP and the STOP/START confirmation messages CTIA requires).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array {
	 *     @type string $status       'opted_out' | 'opted_in' | 'helped' | 'ignored'.
	 *     @type string $reason       (When status='ignored') Why it was ignored.
	 *     @type int    $user_id      (When applicable) The affected user ID.
	 *     @type string $twiml_reply  (Optional) TwiML body to send back to Twilio.
	 * }
	 */
	public static function handle_incoming( $request ) {
		$from = sanitize_text_field( $request->get_param( 'From' ) );
		$body = strtoupper( trim( sanitize_text_field( $request->get_param( 'Body' ) ) ) );

		if ( empty( $from ) ) {
			return array(
				'status' => 'ignored',
				'reason' => 'no_from',
			);
		}

		// HELP is keyword-only and does not require a registered user.
		// CTIA Messaging Principles require a HELP reply with brand,
		// program, frequency, support contact, and STOP reminder.
		if ( self::is_help_keyword( $body ) ) {
			return array(
				'status'      => 'helped',
				'twiml_reply' => self::twiml_reply( self::help_reply_body() ),
			);
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
			return array(
				'status' => 'ignored',
				'reason' => 'unknown_number',
			);
		}

		$user_id = (int) $users[0]->ID;

		// TCPA-compliant STOP handling — confirm with brand-prefixed
		// reply per CTIA Messaging Principles.
		if ( self::is_stop_keyword( $body ) ) {
			update_user_meta( $user_id, 'orbit_sms_opted_out', 1 );

			// Append the TCPA audit-trail row for the SMS channel. The
			// user_meta above is the operational flag (cheap to read on
			// every send); the ledger row is the immutable evidence we
			// need when Twilio or a court asks "when did this user opt
			// out?". Inbound webhook POSTs originate from Twilio's IPs,
			// so override IP/UA to avoid logging Twilio's infrastructure
			// as if it were the user's.
			$consent_recorded = Orbit_Consent::record(
				$user_id,
				'sms',
				'opt_out',
				array(
					'source'       => 'sms_stop',
					'cta_snapshot' => 'inbound SMS keyword: STOP',
					'ip'           => '',
					'user_agent'   => 'twilio-webhook',
				)
			);

			if ( is_wp_error( $consent_recorded ) ) {
				// Best-effort: the operational opt-out already succeeded
				// above. Log so the missing audit row is observable in
				// ops without breaking the CTIA STOP confirmation reply.
				error_log( 'Orbit_Twilio: failed to record SMS opt_out ledger row for user ' . $user_id . ': ' . $consent_recorded->get_error_message() );
			}

			return array(
				'status'      => 'opted_out',
				'user_id'     => $user_id,
				'twiml_reply' => self::twiml_reply( self::stop_reply_body() ),
			);
		}

		if ( self::is_start_keyword( $body ) ) {
			delete_user_meta( $user_id, 'orbit_sms_opted_out' );

			$consent_recorded = Orbit_Consent::record(
				$user_id,
				'sms',
				're_opt_in',
				array(
					'source'       => 'sms_start',
					'cta_snapshot' => 'inbound SMS keyword: START',
					'ip'           => '',
					'user_agent'   => 'twilio-webhook',
				)
			);

			if ( is_wp_error( $consent_recorded ) ) {
				error_log( 'Orbit_Twilio: failed to record SMS re_opt_in ledger row for user ' . $user_id . ': ' . $consent_recorded->get_error_message() );
			}

			return array(
				'status'      => 'opted_in',
				'user_id'     => $user_id,
				'twiml_reply' => self::twiml_reply( self::start_reply_body() ),
			);
		}

		return array(
			'status' => 'ignored',
			'reason' => 'unrecognized_keyword',
		);
	}

	/**
	 * The set of inbound keywords recognized as STOP.
	 *
	 * Per CTIA: STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT must all
	 * trigger an immediate opt-out. Inbound keyword is normalized to
	 * upper-case before this check.
	 *
	 * @param string $body Upper-cased inbound message body.
	 * @return bool
	 */
	protected static function is_stop_keyword( $body ) {
		return in_array(
			$body,
			array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ),
			true
		);
	}

	/**
	 * The set of inbound keywords recognized as START.
	 *
	 * @param string $body Upper-cased inbound message body.
	 * @return bool
	 */
	protected static function is_start_keyword( $body ) {
		return in_array( $body, array( 'START', 'YES', 'UNSTOP' ), true );
	}

	/**
	 * The set of inbound keywords recognized as HELP.
	 *
	 * @param string $body Upper-cased inbound message body.
	 * @return bool
	 */
	protected static function is_help_keyword( $body ) {
		return in_array( $body, array( 'HELP', 'INFO' ), true );
	}

	/**
	 * Brand-prefixed HELP reply body.
	 *
	 * Required by CTIA: brand + program + frequency + support + STOP.
	 *
	 * @return string
	 */
	protected static function help_reply_body() {
		$brand   = defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' );
		$support = get_option( 'admin_email' );

		return sprintf(
			/* translators: 1: brand name, 2: support email */
			__( '%1$s: Creator notifications. Up to 10 msgs/week. Msg & data rates may apply. Support: %2$s. Reply STOP to unsubscribe.', 'orbit' ),
			$brand,
			$support
		);
	}

	/**
	 * Brand-prefixed STOP confirmation reply body.
	 *
	 * @return string
	 */
	protected static function stop_reply_body() {
		$brand = defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' );

		return sprintf(
			/* translators: %s: brand name */
			__( "%s: You've been unsubscribed and will receive no further messages. Reply START to resubscribe.", 'orbit' ),
			$brand
		);
	}

	/**
	 * Brand-prefixed START re-subscribe confirmation reply body.
	 *
	 * @return string
	 */
	protected static function start_reply_body() {
		$brand = defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' );

		return sprintf(
			/* translators: %s: brand name */
			__( "%s: You're re-subscribed. Reply STOP to unsubscribe, HELP for help.", 'orbit' ),
			$brand
		);
	}

	/**
	 * Wrap a plain-text reply body in TwiML.
	 *
	 * @param string $body Reply body.
	 * @return string TwiML XML document.
	 */
	protected static function twiml_reply( $body ) {
		return sprintf(
			'<?xml version="1.0" encoding="UTF-8"?><Response><Message>%s</Message></Response>',
			esc_html( $body )
		);
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
