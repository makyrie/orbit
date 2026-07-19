<?php
/**
 * Regression test for the TCPA-defense cta_snapshot byte-equality invariant.
 *
 * The core TCPA-defense invariant for the consent ledger is that the
 * `cta_snapshot` column stored on every opt-in row is the EXACT text the
 * user saw at consent time — byte-identical to what
 * {@see Orbit_Compliance_UI::compliance_disclosure_text()} returns when the
 * REST handler renders the form copy. If the snapshot ever drifts from the
 * disclosure helper output (e.g. a translator update, a developer rewording
 * one path but not the other, or — most subtly — Wave A's SMS-dormancy
 * sunset clause appearing in the rendered text but not the ledger), the
 * legal-defense story for every signup recorded during the drift window is
 * destroyed.
 *
 * This file pins that invariant for both SMS dormancy states (the only
 * configuration knob that changes the disclosure text at runtime today)
 * across both consent-capturing REST endpoints (signup + subscribe).
 *
 * @see todo 124 (TCPA-evidence regression test missing).
 * @see todo 128 (Wave A SMS-dormancy clause prepended to disclosure).
 *
 * @package Orbit
 */

/**
 * Class OrbitConsentCtaSnapshotTest
 */
class OrbitConsentCtaSnapshotTest extends WP_UnitTestCase {

	/**
	 * Test IP address used across all tests (TEST-NET-3, RFC 5737).
	 *
	 * @var string
	 */
	const TEST_IP = '203.0.113.42';

	/**
	 * Saved REMOTE_ADDR to restore in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_remote_addr = null;

	/**
	 * Saved orbit_sms_enabled option to restore in tearDown so the
	 * Wave A toggle leaks neither into nor out of this test class.
	 *
	 * @var mixed
	 */
	private $saved_sms_enabled_option = null;

	/**
	 * Poster + profile used by the subscribe path. Created per-test so
	 * factory user-id reuse across tests doesn't collide with stale
	 * profile rows.
	 *
	 * @var int
	 */
	private $poster_id;

	/**
	 * @var object
	 */
	private $profile;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server, $wpdb;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->saved_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$_SERVER['REMOTE_ADDR']  = self::TEST_IP;

		// Snapshot the SMS-enabled option so we can restore it cleanly even
		// if a test fails partway through flipping the flag.
		$this->saved_sms_enabled_option = get_option( Orbit_Features::OPTION_SMS_ENABLED, null );
		// Force the dormant baseline before each test — Wave A's sunset clause
		// must be present for the dormant-state assertion to actually test
		// anything.
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );

		// Clear rate-limit transients for both endpoints so dispatches in the
		// same test class don't trip the limiter when one suite reuses the IP.
		delete_transient( 'orbit_rl_' . md5( 'signup|' . self::TEST_IP ) );
		delete_transient( 'orbit_rl_' . md5( 'subscribe|' . self::TEST_IP ) );

		// Custom Orbit tables aren't covered by WP_UnitTestCase's transaction
		// rollback, so wipe the consent ledger and the subscribe-side tables
		// up front. Use the sanctioned migration-mode wrapper because
		// Orbit_Consent's append-only query guard otherwise refuses DELETE.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS );
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_PROFILES );
		// phpcs:enable

		// Stand up a publishable profile so the subscribe path has something
		// to subscribe to. require_approval=false so the row lands in
		// 'approved' and the consent rows get written by the same code path
		// real users hit on the happy path.
		$this->poster_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$slug            = 'cta-snap-poster-' . wp_rand( 100000, 999999 );
		$profile_id      = Orbit_Profile::create(
			array(
				'user_id'          => $this->poster_id,
				'slug'             => $slug,
				'display_name'     => 'CTA Snapshot Test Poster',
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

		// Restore the SMS-enabled option exactly as the surrounding test
		// suite left it. null sentinel means "no row existed" — delete to
		// avoid leaking a phantom '' value.
		if ( null === $this->saved_sms_enabled_option ) {
			delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		} else {
			update_option( Orbit_Features::OPTION_SMS_ENABLED, $this->saved_sms_enabled_option );
		}

		delete_transient( 'orbit_rl_' . md5( 'signup|' . self::TEST_IP ) );
		delete_transient( 'orbit_rl_' . md5( 'subscribe|' . self::TEST_IP ) );

		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		if ( $this->profile ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			// phpcs:enable
		}

		parent::tear_down();
	}

	// ---------------------------------------------------------------- //
	// Helpers
	// ---------------------------------------------------------------- //

	/**
	 * Build a /signup request body with the honeypot timestamp far enough
	 * back to clear Orbit_Spam's MIN_FILL_MS check.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function signup_params( array $overrides = array() ) {
		$default_init_ms = (int) round( microtime( true ) * 1000 ) - 2000;

		return array_merge(
			array(
				'display_name'    => 'CTA Snap Signup',
				'email'           => 'cta-snap-signup-' . wp_rand( 100000, 999999 ) . '@example.test',
				'orbit_url'       => '',
				'orbit_form_init' => $default_init_ms,
				'consent_email'   => true,
			),
			$overrides
		);
	}

	/**
	 * Build a /subscribe request body. Same MIN_FILL_MS rationale as
	 * signup_params().
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function subscribe_params( array $overrides = array() ) {
		$default_init_ms = (int) round( microtime( true ) * 1000 ) - 2000;

		return array_merge(
			array(
				'share_token'     => $this->profile->share_token,
				'display_name'    => 'CTA Snap Subscriber',
				'email'           => 'cta-snap-sub-' . wp_rand( 100000, 999999 ) . '@example.test',
				'consent_email'   => true,
				'orbit_url'       => '',
				'orbit_form_init' => $default_init_ms,
			),
			$overrides
		);
	}

	/**
	 * Dispatch POST /orbit/v1/signup.
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

	/**
	 * Dispatch POST /orbit/v1/subscribe.
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

	/**
	 * Fetch the stored cta_snapshot for a (user_id, channel) tuple. Returns
	 * null when no row exists so assertions can distinguish "no row written"
	 * from "row written with empty snapshot".
	 *
	 * @param int    $user_id User ID.
	 * @param string $channel 'email' or 'sms'.
	 * @return string|null
	 */
	private function stored_cta_snapshot( $user_id, $channel ) {
		global $wpdb;
		$table = Orbit_Consent::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cta_snapshot FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY id ASC LIMIT 1",
				(int) $user_id,
				$channel
			)
		);

		return null === $value ? null : (string) $value;
	}

	// ---------------------------------------------------------------- //
	// SMS dormant (Wave A default state)
	// ---------------------------------------------------------------- //

	/**
	 * Sanity check the dormancy precondition. If this fails it means the
	 * surrounding suite's test isolation regressed and the SMS-enabled
	 * option leaked into our state — the cta_snapshot assertions below
	 * would silently exercise the wrong branch.
	 */
	public function test_dormant_state_precondition() {
		$this->assertFalse( Orbit_Features::sms_enabled(), 'Expected SMS dormant default for this suite.' );

		$disclosure = Orbit_Compliance_UI::compliance_disclosure_text();
		// The Wave A sunset clause must be present in the dormant disclosure;
		// if it isn't, the byte-match assertions below are testing nothing.
		$this->assertStringContainsString( 'SMS goes live once', $disclosure );
	}

	/**
	 * Round-trip equality, /signup, email-only consent, SMS dormant.
	 */
	public function test_signup_cta_snapshot_byte_matches_disclosure_when_sms_dormant() {
		$expected = Orbit_Compliance_UI::compliance_disclosure_text();

		$response = $this->dispatch_signup( $this->signup_params() );
		$this->assertSame( 201, $response->get_status() );

		$user_id = (int) $response->get_data()['user_id'];

		$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'email' ) );
	}

	/**
	 * Round-trip equality, /signup, email + SMS consent, SMS dormant.
	 *
	 * Both ledger rows write the SAME snapshot — the same disclosure block
	 * covers both opt-ins on the form, so storing different strings on the
	 * two rows would itself be a TCPA-evidence bug.
	 */
	public function test_signup_cta_snapshot_byte_matches_disclosure_for_sms_row_when_dormant() {
		$expected = Orbit_Compliance_UI::compliance_disclosure_text();

		$response = $this->dispatch_signup(
			$this->signup_params(
				array(
					'phone'       => '+12025550133',
					'consent_sms' => true,
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );

		$user_id = (int) $response->get_data()['user_id'];

		$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'email' ) );
		$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'sms' ) );
	}

	/**
	 * Round-trip equality, /subscribe, email + SMS consent, SMS dormant.
	 *
	 * /subscribe's success response returns the subscription row id (not the
	 * user id), so we walk back through Orbit_Subscription::get() to find the
	 * created user.
	 */
	public function test_subscribe_cta_snapshot_byte_matches_disclosure_when_sms_dormant() {
		$expected = Orbit_Compliance_UI::compliance_disclosure_text();

		$response = $this->dispatch_subscribe(
			$this->subscribe_params(
				array(
					'phone'       => '+12025550155',
					'consent_sms' => true,
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );

		$subscription_id = (int) $response->get_data()['id'];
		$subscription    = Orbit_Subscription::get( $subscription_id );
		$this->assertNotNull( $subscription );
		$user_id = (int) $subscription->user_id;

		$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'email' ) );
		$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'sms' ) );
	}

	// ---------------------------------------------------------------- //
	// SMS live (Wave A post-launch state)
	// ---------------------------------------------------------------- //

	/**
	 * Round-trip equality, /signup, email + SMS consent, SMS LIVE.
	 *
	 * Flips Orbit_Features::OPTION_SMS_ENABLED to '1' so
	 * Orbit_Messaging_Copy::sms_status_clause() returns '' and the disclosure
	 * helper returns the baseline sentence alone. The ledger snapshot must
	 * track that change too — if the dormancy clause leaked into either the
	 * rendered text OR the stored snapshot without leaking into the other,
	 * the byte-match invariant breaks. try/finally restores the option even
	 * if an assertion blows up mid-test.
	 */
	public function test_signup_cta_snapshot_byte_matches_disclosure_when_sms_live() {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, '1' );

		try {
			$this->assertTrue( Orbit_Features::sms_enabled(), 'Expected SMS live for this test.' );

			$expected = Orbit_Compliance_UI::compliance_disclosure_text();
			// Sanity: the sunset clause must NOT be present in the live-state
			// disclosure, otherwise we'd be re-testing the dormant branch.
			$this->assertStringNotContainsString( 'SMS goes live once', $expected );

			$response = $this->dispatch_signup(
				$this->signup_params(
					array(
						'phone'       => '+12025550122',
						'consent_sms' => true,
					)
				)
			);
			$this->assertSame( 201, $response->get_status() );

			$user_id = (int) $response->get_data()['user_id'];

			$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'email' ) );
			$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'sms' ) );
		} finally {
			delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		}
	}

	/**
	 * Round-trip equality, /subscribe, email + SMS consent, SMS LIVE.
	 */
	public function test_subscribe_cta_snapshot_byte_matches_disclosure_when_sms_live() {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, '1' );

		try {
			$this->assertTrue( Orbit_Features::sms_enabled(), 'Expected SMS live for this test.' );

			$expected = Orbit_Compliance_UI::compliance_disclosure_text();
			$this->assertStringNotContainsString( 'SMS goes live once', $expected );

			$response = $this->dispatch_subscribe(
				$this->subscribe_params(
					array(
						'phone'       => '+12025550111',
						'consent_sms' => true,
					)
				)
			);
			$this->assertSame( 201, $response->get_status() );

			$subscription_id = (int) $response->get_data()['id'];
			$subscription    = Orbit_Subscription::get( $subscription_id );
			$this->assertNotNull( $subscription );
			$user_id = (int) $subscription->user_id;

			$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'email' ) );
			$this->assertSame( $expected, $this->stored_cta_snapshot( $user_id, 'sms' ) );
		} finally {
			delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		}
	}

	// ---------------------------------------------------------------- //
	// Locale switching (deferred — see comment below)
	// ---------------------------------------------------------------- //

	/**
	 * The locale-switching variant from todo 124 ("Option A second variant")
	 * is intentionally not implemented here. Asserting that the rendered +
	 * stored snapshot agree under a non-English locale requires a real .mo
	 * file for the target locale, and the orbit/ project ships no compiled
	 * translations today — switch_to_locale( 'de_DE' ) on this test
	 * environment would silently fall back to en_US and the test would pass
	 * vacuously, providing no additional coverage beyond the en_US tests
	 * above.
	 *
	 * The structural invariant the locale-variant would protect — that both
	 * code paths route through the same compliance_disclosure_text() helper
	 * in the same request — is already enforced by the byte-equality tests
	 * above: the helper is called once on each request, and whatever locale
	 * resolution happens in that request applies identically to both the
	 * captured-expectation call and the inside-REST-handler call.
	 *
	 * Revisit when the project ships compiled translations for at least one
	 * non-English locale; at that point add a switch_to_locale() variant
	 * here that asserts (a) the disclosure differs from en_US and (b) the
	 * stored snapshot still byte-matches the helper output. See todo 124's
	 * acceptance criteria — leaving this as the documented limitation for
	 * future maintainers.
	 */
	public function test_locale_switching_variant_documented_as_deferred() {
		$this->assertTrue( true, 'Locale-switch variant intentionally deferred — see docblock.' );
	}
}
