<?php
/**
 * Tests for the subscription REST controller (Orbit_REST_Subscription).
 *
 * Covers POST /orbit/v1/subscribe — public endpoint that creates (or attaches
 * an existing) WordPress user to a poster's profile, captures consent for
 * email (required) and optionally SMS, stashes a pending phone number on the
 * user, and creates the subscription row.
 *
 * Mirrors the structure and conventions of OrbitRestSignupTest. The
 * transaction-rollback canary lives in OrbitTransactionSafetyCanaryTest
 * (todo 118); the rollback method here defers to it via markTestSkipped.
 *
 * @package Orbit
 */

class OrbitRestSubscriptionTest extends WP_UnitTestCase {

	/**
	 * Test IP address used across all tests (TEST-NET-3, RFC 5737).
	 *
	 * @var string
	 */
	const TEST_IP = '203.0.113.20';

	/**
	 * Saved REMOTE_ADDR to restore in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_remote_addr = null;

	/**
	 * Poster user ID owning the test profile.
	 *
	 * @var int
	 */
	private $poster_id;

	/**
	 * Test profile row (created per-test so share_token rotation across
	 * tests doesn't cause cross-contamination).
	 *
	 * @var object
	 */
	private $profile;

	/**
	 * Spin up a fresh REST server before each test so route registrations
	 * are isolated and don't carry across tests. Also fix the client IP so
	 * the rate limiter's keying is deterministic and create a single
	 * publishable profile to subscribe to.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->saved_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$_SERVER['REMOTE_ADDR']  = self::TEST_IP;

		$this->clear_rate_limit_transient();

		// Clear orbit-side state that WP_UnitTestCase's transactional rollback
		// doesn't catch (custom tables persist between PHPUnit runs and across
		// tests within the same run since WP rolls back wp_* but not the
		// orbit prefix). Without this, user IDs that the factory reuses
		// can collide with profiles created in prior test invocations.
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS );
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_PROFILES );

		// Create a poster + profile to subscribe to. require_approval=false
		// so the happy-path response status is the more useful "approved".
		$this->poster_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$slug            = 'sub-poster-' . wp_rand( 100000, 999999 );
		$profile_id      = Orbit_Profile::create(
			array(
				'user_id'          => $this->poster_id,
				'slug'             => $slug,
				'display_name'     => 'Sub Test Poster',
				'require_approval' => false,
			)
		);
		$this->profile   = Orbit_Profile::get( $profile_id );
	}

	public function tear_down() {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		if ( null === $this->saved_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_remote_addr;
		}

		$this->clear_rate_limit_transient();

		// Clean up subscription / profile rows for this test.
		if ( $this->profile ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . ' WHERE profile_id = %d',
					$this->profile->id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . ' WHERE id = %d',
					$this->profile->id
				)
			);
		}

		parent::tear_down();
	}

	/**
	 * Wipe the subscribe rate-limit transient so each test starts from zero
	 * attempts. The limiter keys its transients as
	 * `orbit_rl_<md5(action|identifier)>` (see Orbit_Rate_Limiter::get_key).
	 */
	private function clear_rate_limit_transient() {
		$key = 'orbit_rl_' . md5( 'subscribe|' . self::TEST_IP );
		delete_transient( $key );
	}

	/**
	 * Build a request body for /subscribe. Sensible defaults for the happy
	 * path — tests override fields they want to exercise. By default the
	 * timestamp is set far enough in the past to clear Orbit_Spam's "too
	 * fast" check.
	 *
	 * @param array $overrides Field overrides.
	 * @return array Request parameters ready for set_param().
	 */
	private function subscribe_params( array $overrides = array() ) {
		// 2 seconds ago — comfortably over the 1500ms MIN_FILL_MS threshold.
		$default_init_ms = (int) round( microtime( true ) * 1000 ) - 2000;

		return array_merge(
			array(
				'share_token'     => $this->profile->share_token,
				'display_name'    => 'New Subscriber',
				'email'           => 'new-sub-' . wp_rand( 100000, 999999 ) . '@example.test',
				'consent_email'   => true,
				'orbit_url'       => '',
				'orbit_form_init' => $default_init_ms,
			),
			$overrides
		);
	}

	/**
	 * Dispatch a POST /orbit/v1/subscribe request with the given parameters.
	 *
	 * @param array $params Body parameters.
	 * @return WP_REST_Response
	 */
	private function dispatch_subscribe( array $params ) {
		$request = new WP_REST_Request( 'POST', '/orbit/v1/subscribe' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	// ---------------------------------------------------------------- //
	// Happy path
	// ---------------------------------------------------------------- //

	public function test_happy_path_creates_subscription() {
		$email    = 'happy-sub-' . wp_rand( 100000, 999999 ) . '@example.test';
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Happy Subscriber',
					'email'        => $email,
					'phone'        => '+12025550144',
					'consent_sms'  => true,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		// Profile was created with require_approval=false, so the row lands
		// in 'approved'.
		$this->assertSame( 'approved', $data['status'] );

		// Subscription row exists and ties the new user to the profile.
		$sub = Orbit_Subscription::get( (int) $data['id'] );
		$this->assertNotNull( $sub );
		$this->assertSame( (int) $this->profile->id, (int) $sub->profile_id );

		$user_id = (int) $sub->user_id;
		$this->assertGreaterThan( 0, $user_id );

		$user = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertSame( $email, $user->user_email );

		// Both consent rows are recorded in the ledger.
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user_id, 'email' ) );
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user_id, 'sms' ) );

		// Pending phone (not the verified slot) was stashed for later
		// promotion by Orbit_Phone_Verify.
		$this->assertSame( '+12025550144', get_user_meta( $user_id, 'orbit_phone_pending', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'orbit_phone', true ) );
	}

	// ---------------------------------------------------------------- //
	// Consent capture
	// ---------------------------------------------------------------- //

	public function test_missing_consent_email_returns_400() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'consent_email' => false,
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'consent_required', $response->as_error()->get_error_code() );
	}

	public function test_consent_sms_without_phone_returns_400() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'consent_sms' => true,
					// No 'phone' override — defaults to empty.
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'consent_sms_without_phone', $response->as_error()->get_error_code() );
	}

	public function test_invalid_phone_format_returns_400() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'phone' => '555-555-1234',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_phone', $response->as_error()->get_error_code() );
	}

	public function test_phone_without_sms_consent_writes_only_email_row() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'phone'       => '+12025550155',
					'consent_sms' => false,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$sub     = Orbit_Subscription::get( (int) $response->get_data()['id'] );
		$user_id = (int) $sub->user_id;

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user_id, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $user_id, 'sms' ) );
		// Phone is still stashed so the user can opt in to SMS later.
		$this->assertSame( '+12025550155', get_user_meta( $user_id, 'orbit_phone_pending', true ) );
	}

	// ---------------------------------------------------------------- //
	// Honeypot / timestamp traps
	// ---------------------------------------------------------------- //

	public function test_honeypot_field_filled_returns_400() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'orbit_url' => 'https://spammer.test/',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'orbit_spam_detected', $response->as_error()->get_error_code() );
	}

	public function test_too_fast_submission_returns_400() {
		// init_ms ~ now means elapsed < MIN_FILL_MS (1500ms).
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'orbit_form_init' => (int) round( microtime( true ) * 1000 ),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'orbit_spam_detected', $response->as_error()->get_error_code() );
	}

	// ---------------------------------------------------------------- //
	// Logged-in user flow
	// ---------------------------------------------------------------- //

	public function test_logged_in_user_subscribes_to_profile_existing_account() {
		// Pre-create a user that matches the posted email, then log them in.
		// The handler should reuse their account rather than try to create
		// a new one, and still write the email consent row + subscription.
		$email   = 'logged-in-sub-' . wp_rand( 100000, 999999 ) . '@example.test';
		$user_id = $this->factory->user->create(
			array(
				'user_email' => $email,
				'user_login' => 'logged-in-sub-' . wp_rand( 100000, 999999 ),
			)
		);
		wp_set_current_user( $user_id );

		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Existing Account',
					'email'        => $email,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$sub = Orbit_Subscription::get( (int) $response->get_data()['id'] );
		$this->assertNotNull( $sub );
		$this->assertSame( $user_id, (int) $sub->user_id );

		// Email consent row is written against the existing account.
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user_id, 'email' ) );
	}

	// ---------------------------------------------------------------- //
	// Validation errors
	// ---------------------------------------------------------------- //

	public function test_invalid_email_returns_400() {
		// 'foo@' survives sanitize_email() with non-empty content but fails
		// is_email(), so it hits the controller's explicit invalid_email
		// check rather than the REST framework's "missing required param"
		// path.
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'email' => 'foo@',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->as_error()->get_error_code() );
	}

	// ---------------------------------------------------------------- //
	// Rate limit
	// ---------------------------------------------------------------- //

	public function test_rate_limit_kicks_in_after_threshold() {
		// Burn 5 valid attempts (limiter window allows 5/hour/IP). Each
		// attempt uses a unique email so it gets past validation and
		// counts as a real subscribe — the limiter increments before any
		// other DB work, so even a hypothetical inner failure would still
		// burn the slot.
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $this->dispatch_subscribe(
				$this->subscribe_params(
					array(
						'display_name' => 'Rate Sub ' . $i,
						'email'        => 'rate-sub-' . $i . '-' . wp_rand( 100000, 999999 ) . '@example.test',
					)
				)
			);
			// Accept 201 (new subscribe), 409 (existing email — shouldn't
			// happen here but harmless) and 429 (already throttled). The
			// point is each attempt counted toward the limit.
			$this->assertContains( $response->get_status(), array( 201, 409, 429 ) );
		}

		// 6th attempt should be rate-limited regardless of body validity.
		wp_set_current_user( 0 );

		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Sixth Attempt',
					'email'        => 'sixth-sub-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			)
		);

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->as_error()->get_error_code() );
	}

	// ---------------------------------------------------------------- //
	// redirect_url in response (todo 125)
	// ---------------------------------------------------------------- //

	public function test_new_account_response_includes_dashboard_redirect_url() {
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Redirect New',
					'email'        => 'redirect-new-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'redirect_url', $data );
		$this->assertSame( home_url( '/dashboard/' ), $data['redirect_url'] );
	}

	public function test_existing_logged_in_response_redirects_to_profile_permalink() {
		$email   = 'redirect-existing-' . wp_rand( 100000, 999999 ) . '@example.test';
		$user_id = $this->factory->user->create(
			array(
				'user_email' => $email,
				'user_login' => 'redirect-existing-' . wp_rand( 100000, 999999 ),
			)
		);
		wp_set_current_user( $user_id );

		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Existing',
					'email'        => $email,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'redirect_url', $data );
		// Profile permalink shape is `/@<slug>/`, matching the rewrite
		// rule in Orbit_Routes::add_rewrite_rules().
		$this->assertSame( home_url( '/@' . $this->profile->slug . '/' ), $data['redirect_url'] );
	}

	// ---------------------------------------------------------------- //
	// orbit_phone_pending guard for logged-in resubscribers (todo 126)
	// ---------------------------------------------------------------- //

	/**
	 * A logged-in user POSTing to /subscribe with a `phone` field in
	 * the body must NOT have their existing `orbit_phone_pending`
	 * overwritten. The form for the existing-logged-in branch doesn't
	 * even render a phone field, but a hand-crafted REST call shouldn't
	 * be allowed to mutate phone state via this endpoint.
	 */
	public function test_logged_in_subscribe_does_not_overwrite_existing_pending_phone() {
		$email   = 'phone-guard-' . wp_rand( 100000, 999999 ) . '@example.test';
		$user_id = $this->factory->user->create(
			array(
				'user_email' => $email,
				'user_login' => 'phone-guard-' . wp_rand( 100000, 999999 ),
			)
		);

		// Pre-seed a known pending phone, then sign in as them.
		$original_phone = '+12025550111';
		update_user_meta( $user_id, 'orbit_phone_pending', $original_phone );
		update_user_meta( $user_id, 'orbit_phone_pending_at', time() - 600 );

		wp_set_current_user( $user_id );

		// Hand-craft a subscribe POST that *does* carry a phone field —
		// the form wouldn't, but a direct REST client could.
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'Hand-Crafted',
					'email'        => $email,
					'phone'        => '+12025559999',
					'consent_sms'  => false,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		// The pre-existing pending phone is untouched.
		$this->assertSame(
			$original_phone,
			get_user_meta( $user_id, 'orbit_phone_pending', true ),
			'Existing orbit_phone_pending must not be overwritten by the subscribe endpoint for a logged-in user.'
		);
	}

	public function test_new_account_subscribe_writes_pending_phone() {
		// Sanity check the other half of the gate: new-account branch
		// still stashes the pending phone (the legitimate use case).
		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'display_name' => 'New With Phone',
					'email'        => 'new-with-phone-' . wp_rand( 100000, 999999 ) . '@example.test',
					'phone'        => '+12025550222',
					'consent_sms'  => true,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$sub = Orbit_Subscription::get( (int) $response->get_data()['id'] );
		$this->assertSame( '+12025550222', get_user_meta( (int) $sub->user_id, 'orbit_phone_pending', true ) );
	}

	// ---------------------------------------------------------------- //
	// Error-code preservation through the rollback catch (todo 116)
	// ---------------------------------------------------------------- //

	/**
	 * A failure injected below the controller layer must come back
	 * with the *original* code preserved and a generic translated
	 * user-facing message — never the raw inner error string.
	 *
	 * Inject via `pre_user_login` returning empty: inside
	 * `wp_create_user` → `wp_insert_user`, core emits
	 * `empty_user_login` (see WP user.php at the `'empty_user_login'`
	 * branch).
	 */
	public function test_below_controller_failure_preserves_code_and_returns_generic_message() {
		$swap_login = function ( $login ) {
			return '';
		};
		add_filter( 'pre_user_login', $swap_login, 10, 1 );

		try {
			$response = $this->dispatch_subscribe(
				$this->subscribe_params(
					array(
						'display_name' => 'Forced Failure',
						'email'        => 'forced-sub-' . wp_rand( 100000, 999999 ) . '@example.test',
					)
				)
			);
		} finally {
			remove_filter( 'pre_user_login', $swap_login, 10 );
		}

		$this->assertSame( 500, $response->get_status() );

		$data = $response->get_data();
		// Code preserved from the inner WP_Error — not the legacy
		// generic `subscribe_failed`.
		$this->assertSame( 'empty_user_login', $data['code'] );
		// User-facing message is the controller's translated copy.
		$this->assertStringContainsString( 'subscription', $data['message'] );
		$this->assertStringNotContainsString( 'database', strtolower( $data['message'] ) );
		$this->assertStringNotContainsString( 'mysql', strtolower( $data['message'] ) );
	}

	// ---------------------------------------------------------------- //
	// Transaction rollback
	// ---------------------------------------------------------------- //

	public function test_transaction_rollback_on_consent_failure() {
		// Transaction-rollback coverage for the provisioning endpoints
		// lives in OrbitTransactionSafetyCanaryTest (todo 118 canary).
		// That test uses a sentinel `user_register` hook and the
		// `orbit_consent_ip_salt_resolved` filter to force a deterministic
		// mid-transaction failure, then asserts the rollback path actually
		// rolls back hook-side DML — the invariant subscribe and signup
		// both depend on. Re-dispatching /orbit/v1/subscribe here would
		// assert the same MySQL behavior twice with no additional coverage;
		// see AGENTS.md → "Transactional Boundaries" for the canonical rule.
		$this->markTestSkipped(
			'Covered by OrbitTransactionSafetyCanaryTest — see todo 118.'
		);
	}

	// ---------------------------------------------------------------- //
	// Deferred new-user notification (todo 119)
	// ---------------------------------------------------------------- //

	/**
	 * When subscribe creates a brand-new user, the welcome email must
	 * NOT be sent synchronously from the REST handler. Hook the
	 * `wp_new_user_notification_email` filter and assert it never fires
	 * during the request — that's the behavior change todo 119 enforces.
	 * If ActionScheduler is loaded, additionally confirm the deferred
	 * job is enqueued.
	 */
	public function test_happy_path_defers_welcome_email_to_action_scheduler() {
		$filter_fired = false;
		$capture      = function ( $email ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $email;
		};
		add_filter( 'wp_new_user_notification_email', $capture, 10, 1 );

		try {
			$email    = 'defer-sub-' . wp_rand( 100000, 999999 ) . '@example.test';
			$response = $this->dispatch_subscribe(
				$this->subscribe_params(
					array(
						'display_name' => 'Defer Subscriber',
						'email'        => $email,
					)
				)
			);

			$this->assertSame( 201, $response->get_status() );

			$sub     = Orbit_Subscription::get( (int) $response->get_data()['id'] );
			$user_id = (int) $sub->user_id;
			$this->assertGreaterThan( 0, $user_id );

			// The synchronous mail path must NOT have fired during the
			// REST request — that's the whole point of todo 119.
			$this->assertFalse(
				$filter_fired,
				'wp_send_new_user_notifications fired synchronously from the subscribe REST handler; it should be deferred to ActionScheduler.'
			);

			// When AS is loaded (the real production path), the job should
			// be on the schedule, with the poster's profile ID threaded
			// through the payload so the subscriber welcome can name them.
			if ( function_exists( 'as_has_scheduled_action' ) ) {
				$this->assertTrue(
					as_has_scheduled_action(
						'orbit_send_new_user_notification',
						array(
							'user_id'           => $user_id,
							'poster_profile_id' => (int) $sub->profile_id,
						),
						'orbit'
					),
					'Expected orbit_send_new_user_notification to be scheduled with the poster profile ID for the new user.'
				);
			}
		} finally {
			remove_filter( 'wp_new_user_notification_email', $capture, 10 );
		}
	}
}
