<?php
/**
 * Tests for Orbit_Twilio webhook handling.
 *
 * Covers:
 * - validate_webhook URL pinning (signature for /incoming MUST NOT
 *   validate against any other URL).
 * - HELP / STOP / START TwiML replies per CTIA.
 * - Inbound STOP/START toggles orbit_sms_opted_out user_meta.
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
}
