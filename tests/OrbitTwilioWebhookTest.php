<?php
/**
 * Tests for Orbit_Twilio webhook handling.
 *
 * Covers:
 * - validate_webhook URL pinning (signature for /incoming MUST NOT
 *   validate against any other URL).
 * - validate_webhook accepts JSON-encoded bodies and rejects array-typed
 *   params without emitting PHP notices (TODO 106).
 * - HELP / STOP / START TwiML replies per CTIA.
 * - Inbound STOP/START toggles orbit_sms_opted_out user_meta AND appends
 *   the matching consent ledger row for the SMS channel (TODO 085).
 * - REST controller returns 204 (not 403) on invalid-signature requests
 *   so Twilio doesn't retry for 24h (TODO 094).
 *
 * @package Orbit
 */

/**
 * Class OrbitTwilioWebhookTest
 */
class OrbitTwilioWebhookTest extends WP_UnitTestCase {

	/**
	 * Test auth token (the real ORBIT_TWILIO_AUTH_TOKEN is undefined in
	 * the test environment — we define it for the duration of the suite
	 * via reflection on the validator's constant gate).
	 */
	const TEST_AUTH_TOKEN = 'test_auth_token_12345';

	public function set_up() {
		parent::set_up();

		// The validator short-circuits to false when ORBIT_TWILIO_AUTH_TOKEN
		// is undefined. Define it once for the test suite.
		if ( ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) ) {
			define( 'ORBIT_TWILIO_AUTH_TOKEN', self::TEST_AUTH_TOKEN );
		}
	}

	/**
	 * Build a signature the way Twilio does for a given URL + params.
	 */
	protected function compute_signature( $url, $params ) {
		ksort( $params );
		$data = $url;
		foreach ( $params as $k => $v ) {
			$data .= $k . $v;
		}
		return base64_encode( hash_hmac( 'sha1', $data, self::TEST_AUTH_TOKEN, true ) );
	}

	/**
	 * Make a fake REST request with body params + signature header.
	 */
	protected function make_request( $url, $params, $signature_for_url = null ) {
		$signature_for_url = $signature_for_url ?: $url;
		$request           = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( $params );
		$request->set_header( 'X-Twilio-Signature', $this->compute_signature( $signature_for_url, $params ) );
		return $request;
	}

	public function test_validate_webhook_passes_with_correct_url() {
		$url     = rest_url( 'orbit/v1/twilio/incoming' );
		$request = $this->make_request( $url, array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		$this->assertTrue( Orbit_Twilio::validate_webhook( $request, $url ) );
	}

	public function test_validate_webhook_fails_with_wrong_url() {
		$incoming_url = rest_url( 'orbit/v1/twilio/incoming' );
		$status_url   = rest_url( 'orbit/v1/twilio/status' );

		// Sign for /incoming, validate against /status — must fail.
		$request = $this->make_request( $incoming_url, array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		$this->assertFalse( Orbit_Twilio::validate_webhook( $request, $status_url ) );
	}

	public function test_validate_webhook_fails_with_no_signature() {
		$url     = rest_url( 'orbit/v1/twilio/incoming' );
		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		$this->assertFalse( Orbit_Twilio::validate_webhook( $request, $url ) );
	}

	public function test_stop_keyword_sets_opted_out_and_returns_twiml() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'orbit_phone', '+15005550006' );

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		$result = Orbit_Twilio::handle_incoming( $request );

		$this->assertSame( 'opted_out', $result['status'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertTrue( Orbit_Twilio::is_sms_opted_out( $user_id ) );
		$this->assertStringContainsString( '<Message>', $result['twiml_reply'] );
		$this->assertStringContainsString( 'Perihelion', $result['twiml_reply'] );
		$this->assertStringContainsString( 'unsubscribed', strtolower( $result['twiml_reply'] ) );
	}

	public function test_start_keyword_clears_opt_out_and_returns_twiml() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'orbit_phone', '+15005550006' );
		update_user_meta( $user_id, 'orbit_sms_opted_out', 1 );

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'START' ) );

		$result = Orbit_Twilio::handle_incoming( $request );

		$this->assertSame( 'opted_in', $result['status'] );
		$this->assertFalse( Orbit_Twilio::is_sms_opted_out( $user_id ) );
		$this->assertStringContainsString( '<Message>', $result['twiml_reply'] );
		$this->assertStringContainsString( 'Perihelion', $result['twiml_reply'] );
	}

	public function test_help_keyword_returns_twiml_without_user_lookup() {
		// HELP should reply even for an unknown number — per CTIA, HELP
		// is mandatory regardless of subscription state.
		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'HELP' ) );

		$result = Orbit_Twilio::handle_incoming( $request );

		$this->assertSame( 'helped', $result['status'] );
		$this->assertStringContainsString( '<Message>', $result['twiml_reply'] );
		$this->assertStringContainsString( 'Perihelion', $result['twiml_reply'] );
		$this->assertStringContainsString( 'STOP', $result['twiml_reply'] );
	}

	public function test_unknown_keyword_is_ignored() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'orbit_phone', '+15005550006' );

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'lunch?' ) );

		$result = Orbit_Twilio::handle_incoming( $request );

		$this->assertSame( 'ignored', $result['status'] );
		$this->assertArrayNotHasKey( 'twiml_reply', $result );
	}

	public function test_stop_from_unknown_number_is_ignored() {
		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		$result = Orbit_Twilio::handle_incoming( $request );

		$this->assertSame( 'ignored', $result['status'] );
		$this->assertSame( 'unknown_number', $result['reason'] );
	}

	/**
	 * TODO 085 — STOP webhook must append a consent ledger row with
	 * channel='sms', event='opt_out', source='sms_stop'. The user_meta
	 * flag alone is mutable and ephemeral; the ledger is the immutable
	 * TCPA-defense record.
	 */
	public function test_stop_appends_consent_ledger_row_for_sms_channel() {
		global $wpdb;

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'orbit_phone', '+15005550006' );

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'STOP' ) );

		Orbit_Twilio::handle_incoming( $request );

		$table = Orbit_Consent::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, channel, event, source FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY id DESC LIMIT 1",
				$user_id,
				'sms'
			)
		);

		$this->assertNotNull( $row, 'Expected an SMS ledger row after STOP webhook.' );
		$this->assertSame( 'sms', $row->channel );
		$this->assertSame( 'opt_out', $row->event );
		$this->assertSame( 'sms_stop', $row->source );
		$this->assertSame( (string) $user_id, (string) $row->user_id );
		$this->assertSame( array(), Orbit_Consent::verify_chain( $user_id, 'sms' ) );
	}

	/**
	 * TODO 085 — START webhook must append a re_opt_in ledger row with
	 * source='sms_start'. Tested independently of STOP so we don't depend
	 * on the ordering of two writes against the same chain.
	 */
	public function test_start_appends_consent_ledger_row_for_sms_channel() {
		global $wpdb;

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'orbit_phone', '+15005550006' );
		update_user_meta( $user_id, 'orbit_sms_opted_out', 1 );

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'START' ) );

		Orbit_Twilio::handle_incoming( $request );

		$table = Orbit_Consent::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, channel, event, source FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY id DESC LIMIT 1",
				$user_id,
				'sms'
			)
		);

		$this->assertNotNull( $row, 'Expected an SMS ledger row after START webhook.' );
		$this->assertSame( 'sms', $row->channel );
		$this->assertSame( 're_opt_in', $row->event );
		$this->assertSame( 'sms_start', $row->source );
		$this->assertSame( (string) $user_id, (string) $row->user_id );
		$this->assertSame( array(), Orbit_Consent::verify_chain( $user_id, 'sms' ) );
	}

	/**
	 * TODO 094 — Bad-signature requests must return 204 (not 403) so
	 * Twilio doesn't retry for 24h on routing misconfigurations.
	 */
	public function test_invalid_signature_returns_204_to_avoid_twilio_retry_storm() {
		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params( array( 'From' => '+15005550006', 'Body' => 'STOP' ) );
		$request->set_header( 'X-Twilio-Signature', 'this-is-not-a-valid-signature' );

		// The handler echoes TwiML and exits on the happy path. The 403
		// path returned early via WP_Error before — now it returns early
		// via WP_REST_Response( null, 204 ) without echoing or exiting,
		// so we can assert directly on the response.
		$response = Orbit_REST_Notification::handle_twilio_incoming( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 204, $response->get_status() );
	}

	/**
	 * TODO 106 — validate_webhook must accept JSON-encoded bodies as a
	 * fallback when get_body_params() is empty. Twilio's standard webhook
	 * is form-encoded, but internal replay tools may submit JSON; the
	 * signature math is identical and should validate.
	 */
	public function test_validate_webhook_accepts_json_body_params() {
		$url    = rest_url( 'orbit/v1/twilio/incoming' );
		$params = array(
			'Body' => 'STOP',
			'From' => '+15005550006',
		);

		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		$request->set_header( 'X-Twilio-Signature', $this->compute_signature( $url, $params ) );

		// Sanity-check the precondition: get_body_params() returns empty
		// for JSON content type, so we're actually exercising the
		// get_json_params() fallback branch.
		$this->assertSame( array(), $request->get_body_params() );

		$this->assertTrue( Orbit_Twilio::validate_webhook( $request, $url ) );
	}

	/**
	 * TODO 106 — Array-typed body params (e.g. crafted `Body[]=foo` input)
	 * must return false cleanly rather than triggering an "Array to string
	 * conversion" PHP notice when concatenated into the signature payload.
	 */
	public function test_validate_webhook_rejects_array_typed_params_without_notice() {
		$url     = rest_url( 'orbit/v1/twilio/incoming' );
		$request = new WP_REST_Request( 'POST', '/orbit/v1/twilio/incoming' );
		$request->set_body_params(
			array(
				'From' => '+15005550006',
				// Crafted array value — would normally trigger a PHP
				// "Array to string conversion" notice on concatenation.
				'Body' => array( 'STOP', 'STOP' ),
			)
		);
		$request->set_header( 'X-Twilio-Signature', 'irrelevant-because-we-reject-before-comparing' );

		// Promote PHP notices to exceptions for the duration of this
		// call so an Array-to-string notice would fail the test loudly.
		set_error_handler(
			static function ( $errno, $errstr ) {
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_NOTICE | E_WARNING | E_USER_NOTICE | E_USER_WARNING
		);

		try {
			$result = Orbit_Twilio::validate_webhook( $request, $url );
		} finally {
			restore_error_handler();
		}

		$this->assertFalse( $result );
	}
}
