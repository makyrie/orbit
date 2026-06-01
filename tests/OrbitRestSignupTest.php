<?php
/**
 * Tests for the signup REST controller (Orbit_REST_Signup).
 *
 * Covers POST /orbit/v1/signup — public, anonymous endpoint that creates
 * a WordPress user account, attaches it to the current site, and auto-
 * logs the user in.
 *
 * @package Orbit
 */

class OrbitRestSignupTest extends WP_UnitTestCase {

	/**
	 * Test IP address used across all tests (TEST-NET-3, RFC 5737).
	 *
	 * @var string
	 */
	const TEST_IP = '203.0.113.10';

	/**
	 * Saved REMOTE_ADDR to restore in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_remote_addr = null;

	/**
	 * Spin up a fresh REST server before each test so route registrations
	 * are isolated and don't carry across tests. Also fix the client IP so
	 * the rate limiter's keying is deterministic.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->saved_remote_addr   = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$_SERVER['REMOTE_ADDR']    = self::TEST_IP;

		$this->clear_rate_limit_transient();
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		if ( null === $this->saved_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_remote_addr;
		}

		$this->clear_rate_limit_transient();

		parent::tear_down();
	}

	/**
	 * Wipe the signup rate-limit transient so each test starts from zero
	 * attempts. The limiter keys its transients as
	 * `orbit_rl_<md5(action|identifier)>` (see Orbit_Rate_Limiter::get_key).
	 */
	private function clear_rate_limit_transient() {
		$key = 'orbit_rl_' . md5( 'signup|' . self::TEST_IP );
		delete_transient( $key );
	}

	/**
	 * Build a request body for /signup. By default the timestamp is set
	 * far enough in the past to clear the honeypot's "too fast" check.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Request parameters ready for set_param().
	 */
	private function signup_params( array $overrides = array() ) {
		// 2 seconds ago — comfortably over the 1500ms MIN_FILL_MS threshold.
		$default_init_ms = (int) round( microtime( true ) * 1000 ) - 2000;

		return array_merge(
			array(
				'display_name'    => 'New Signup',
				'email'           => 'new-signup-' . wp_rand( 100000, 999999 ) . '@example.test',
				'orbit_url'       => '',
				'orbit_form_init' => $default_init_ms,
			),
			$overrides
		);
	}

	/**
	 * Dispatch a POST /orbit/v1/signup request with the given parameters.
	 *
	 * @param array $params Body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch_signup( array $params ) {
		$request = new WP_REST_Request( 'POST', '/orbit/v1/signup' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	// ---------------------------------------------------------------- //
	// Happy path
	// ---------------------------------------------------------------- //

	public function test_happy_path_creates_user_and_logs_in() {
		$email    = 'happy-path-' . wp_rand( 100000, 999999 ) . '@example.test';
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => 'Happy Path User',
					'email'        => $email,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'created', $data['status'] );
		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertArrayHasKey( 'redirect_url', $data );
		$this->assertStringContainsString( '/edit-profile/', $data['redirect_url'] );

		// User exists with correct display name + subscriber role.
		$user = get_user_by( 'id', (int) $data['user_id'] );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertSame( 'Happy Path User', $user->display_name );
		$this->assertSame( $email, $user->user_email );
		$this->assertContains( 'subscriber', (array) $user->roles );

		// Auto-login took effect.
		$this->assertTrue( is_user_logged_in() );
		$this->assertSame( (int) $data['user_id'], get_current_user_id() );
	}

	public function test_happy_path_sets_orbit_timezone_meta() {
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => 'Timezone User',
					'email'        => 'tz-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$user_id = $response->get_data()['user_id'];

		$tz = get_user_meta( $user_id, 'orbit_timezone', true );
		$this->assertNotEmpty( $tz );
		$this->assertSame( wp_timezone_string(), $tz );
	}

	// ---------------------------------------------------------------- //
	// Honeypot / timestamp traps
	// ---------------------------------------------------------------- //

	public function test_honeypot_filled_returns_400() {
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'orbit_url' => 'https://spammer.test/',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'orbit_spam_detected', $response->get_data()['code'] );
		$this->assertFalse( is_user_logged_in() );
	}

	public function test_too_fast_submission_returns_400() {
		// init_ms ~ now means elapsed < MIN_FILL_MS (1500ms).
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'orbit_form_init' => (int) round( microtime( true ) * 1000 ),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'orbit_spam_detected', $response->get_data()['code'] );
	}

	public function test_stale_form_returns_400_with_form_expired() {
		// init_ms > 24h ago.
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'orbit_form_init' => (int) round( microtime( true ) * 1000 ) - ( ( DAY_IN_SECONDS + 60 ) * 1000 ),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'orbit_form_expired', $response->get_data()['code'] );
	}

	// ---------------------------------------------------------------- //
	// Rate limit
	// ---------------------------------------------------------------- //

	public function test_rate_limit_kicks_in_on_sixth_attempt() {
		// Burn 5 valid attempts (limiter window allows 5/hour/IP).
		// Use a unique email per attempt so the first 5 don't fail for
		// other reasons.
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $this->dispatch_signup(
				$this->signup_params(
					array(
						'display_name' => 'Rate User ' . $i,
						'email'        => 'rate-' . $i . '-' . wp_rand( 100000, 999999 ) . '@example.test',
					)
				)
			);
			// Don't care about the exact status here, only that each
			// attempt counted towards the limit. The first one is 201;
			// after that the user is already logged in, so the handler
			// short-circuits to the 200 "already_signed_in" branch —
			// but `Orbit_Rate_Limiter::attempt` still records the hit
			// because it runs first.
			$this->assertContains( $response->get_status(), array( 200, 201, 429 ) );
		}

		// Reset the auth state so this 6th call hits the limiter, not
		// the already-logged-in short-circuit.
		wp_set_current_user( 0 );

		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => 'Sixth Attempt',
					'email'        => 'sixth-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			)
		);

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['code'] );
	}

	// ---------------------------------------------------------------- //
	// Validation errors
	// ---------------------------------------------------------------- //

	public function test_email_already_exists_returns_409_with_login_url() {
		$existing_email = 'existing-' . wp_rand( 100000, 999999 ) . '@example.test';
		$this->factory->user->create(
			array(
				'user_email' => $existing_email,
				'user_login' => 'existing-user-' . wp_rand( 100000, 999999 ),
			)
		);

		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => 'Duplicate',
					'email'        => $existing_email,
				)
			)
		);

		$this->assertSame( 409, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'login_required', $data['code'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'login_url', $data['data'] );
		$this->assertNotEmpty( $data['data']['login_url'] );
	}

	public function test_invalid_email_returns_400() {
		// 'foo@' survives sanitize_email() with content but fails is_email().
		// This routes through the controller's explicit invalid_email check
		// rather than the REST framework's "missing required param" path.
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'email' => 'foo@',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_email', $data['code'] );
	}

	public function test_empty_display_name_returns_400() {
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => '   ',
					'email'        => 'whitespace-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_name', $response->get_data()['code'] );
	}

	// ---------------------------------------------------------------- //
	// Edge cases
	// ---------------------------------------------------------------- //

	public function test_non_latin_display_name_falls_back_to_orbit_user_prefix() {
		$email    = 'kanji-' . wp_rand( 100000, 999999 ) . '@example.test';
		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					// Pure CJK characters — sanitize_user strips these and
					// the source code falls back to the "orbit-user" base.
					'display_name' => '日本語',
					'email'        => $email,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$user_id = $response->get_data()['user_id'];
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( 'WP_User', $user );

		// Username must start with 'orbit-user' and end with 5 digits.
		$this->assertMatchesRegularExpression( '/^orbit-user\d{5}$/', $user->user_login );

		// Display name should still preserve the original input.
		$this->assertSame( '日本語', $user->display_name );
	}

	// ---------------------------------------------------------------- //
	// Already-logged-in short-circuit
	// ---------------------------------------------------------------- //

	public function test_already_logged_in_returns_200_with_redirect() {
		$existing_user_id = $this->factory->user->create();
		wp_set_current_user( $existing_user_id );

		$new_email = 'logged-in-' . wp_rand( 100000, 999999 ) . '@example.test';
		$response  = $this->dispatch_signup(
			$this->signup_params(
				array(
					'display_name' => 'Should Not Matter',
					'email'        => $new_email,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'already_signed_in', $data['status'] );
		$this->assertArrayHasKey( 'redirect_url', $data );
		$this->assertStringContainsString( '/edit-profile/', $data['redirect_url'] );

		// We should NOT have created a brand-new user matching the posted email.
		$this->assertFalse( get_user_by( 'email', $new_email ) );

		// Current user remains the pre-existing user, not a freshly minted one.
		$this->assertSame( $existing_user_id, get_current_user_id() );
	}
}
