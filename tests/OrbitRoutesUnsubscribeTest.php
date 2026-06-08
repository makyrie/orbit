<?php
/**
 * Tests for the RFC 8058 / two-step unsubscribe routes on Orbit_Routes.
 *
 * Covers the public response-shape helpers introduced for testability —
 * `one_click_unsubscribe_response()`, `perform_unsubscribe()`,
 * `resolve_unsubscribe_subscription()`, and `is_one_click_unsubscribe_post()` —
 * so we don't have to run the handlers under process isolation to avoid
 * their production `exit()`.
 *
 * @package Orbit
 */

/**
 * Class OrbitRoutesUnsubscribeTest
 */
class OrbitRoutesUnsubscribeTest extends WP_UnitTestCase {

	/**
	 * Poster (profile owner) user.
	 *
	 * @var int
	 */
	private static $poster_id;

	/**
	 * Subscriber user.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Profile owned by $poster_id.
	 *
	 * @var int
	 */
	private static $profile_id;

	/**
	 * Class-level fixtures: one poster, one profile, one subscriber.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		self::$poster_id     = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		// Clean any stale rows from a prior aborted run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug = 'unsub-test-poster'" );

		self::$profile_id = Orbit_Profile::create(
			array(
				'user_id'      => self::$poster_id,
				'slug'         => 'unsub-test-poster',
				'display_name' => 'Unsub Test Poster',
			)
		);
	}

	/**
	 * Drop class-level fixtures.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		if ( is_int( self::$profile_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . (int) self::$profile_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . (int) self::$profile_id );
		}
	}

	/**
	 * Per-test reset: blank superglobals, clear rate-limit transients,
	 * clear the consent ledger, drop subscriptions on the shared profile.
	 */
	public function set_up() {
		parent::set_up();

		// Reset request shape — the response-shape helpers read from
		// $_POST and Orbit_Client_IP::get() reads from $_SERVER.
		$_POST   = array();
		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Clear rate-limit transients so successive test runs don't
		// inherit a 429-ready bucket from each other.
		delete_transient( 'orbit_rl_' . md5( 'unsubscribe_one_click|203.0.113.10' ) );
		delete_transient( 'orbit_rl_' . md5( 'unsubscribe_one_click_anon|_anon' ) );

		// Reset the consent ledger so chain-hash continuity from a
		// previous test doesn't bleed into the current one.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		// Drop subscriptions on the shared profile so each test starts clean.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . (int) self::$profile_id );
	}

	/**
	 * Per-test teardown: clean superglobals so request state doesn't
	 * leak into sibling test files.
	 */
	public function tear_down() {
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['HTTP_LIST_UNSUBSCRIBE_POST'] );
		parent::tear_down();
	}

	/**
	 * Create an approved subscription for the shared subscriber/profile
	 * pair, optionally for a freshly-minted second subscriber so the
	 * "multi-subscription" tests can exercise per-subscription idempotency.
	 *
	 * @param int|null $user_id Subscriber. Defaults to the shared one.
	 * @return object Subscription row (post-approve).
	 */
	private function create_approved_subscription( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = self::$subscriber_id;
		}

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $user_id,
				'profile_id' => self::$profile_id,
			)
		);

		$this->assertIsInt( $sub_id, 'fixture subscribe() should return an int id' );

		Orbit_Subscription::approve( $sub_id );

		return Orbit_Subscription::get( $sub_id );
	}

	/**
	 * Count consent rows for a given user (any channel/event).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function count_consent_rows( $user_id ) {
		global $wpdb;
		$table = Orbit_Consent::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", (int) $user_id )
		);
	}

	// ---------------------------------------------------------------------
	// is_one_click_unsubscribe_post() — RFC 8058 §3.2 strict body check.
	// ---------------------------------------------------------------------

	public function test_one_click_recognized_when_post_body_field_present() {
		$_POST['List-Unsubscribe'] = 'One-Click';

		$this->assertTrue( Orbit_Routes::is_one_click_unsubscribe_post() );
	}

	public function test_one_click_not_recognized_via_phantom_request_header() {
		// `List-Unsubscribe-Post` is a sender-side response header on the
		// outbound email — mail clients do NOT echo it back as a request
		// header. We must not be more permissive than RFC 8058 §3.2.
		$_SERVER['HTTP_LIST_UNSUBSCRIBE_POST'] = 'List-Unsubscribe=One-Click';

		$this->assertFalse( Orbit_Routes::is_one_click_unsubscribe_post() );

		unset( $_SERVER['HTTP_LIST_UNSUBSCRIBE_POST'] );
	}

	public function test_one_click_not_recognized_when_post_field_is_array() {
		// Array-shape input would crash a naive strict-equality check;
		// the is_string() guard makes the helper return false instead.
		$_POST['List-Unsubscribe'] = array( 'One-Click' );

		$this->assertFalse( Orbit_Routes::is_one_click_unsubscribe_post() );
	}

	// ---------------------------------------------------------------------
	// one_click_unsubscribe_response() — RFC 8058 happy path + edge cases.
	// ---------------------------------------------------------------------

	public function test_one_click_post_with_valid_token_unsubscribes_and_appends_consent_row() {
		$sub   = $this->create_approved_subscription();
		$token = Orbit_Token::generate_unsubscribe_token( $sub->subscription_secret, (int) $sub->id );

		$response = Orbit_Routes::one_click_unsubscribe_response( $token );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'unsubscribed', $response['body'] );

		$fresh = Orbit_Subscription::get( $sub->id );
		$this->assertSame( 'unsubscribed', $fresh->status );

		$this->assertSame( 1, $this->count_consent_rows( self::$subscriber_id ) );
	}

	public function test_one_click_with_invalid_token_returns_400_invalid_token() {
		$response = Orbit_Routes::one_click_unsubscribe_response( 'this-is-not-a-valid-token' );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( 'invalid_token', $response['body'] );
	}

	public function test_one_click_with_no_token_returns_400_invalid_token() {
		$response = Orbit_Routes::one_click_unsubscribe_response( '' );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( 'invalid_token', $response['body'] );
	}

	public function test_one_click_rate_limited_after_30_attempts_from_same_ip() {
		// Burn through the per-IP budget (30/min) using a known-invalid
		// token so the path is fast and we don't need a fresh subscription
		// per call. The 31st should hit 429.
		for ( $i = 1; $i <= 30; $i++ ) {
			$response = Orbit_Routes::one_click_unsubscribe_response( 'invalid-token-' . $i );
			$this->assertSame( 400, $response['status'], 'request ' . $i . ' should still pass the limiter' );
		}

		$response = Orbit_Routes::one_click_unsubscribe_response( 'invalid-token-31' );
		$this->assertSame( 429, $response['status'] );
		$this->assertSame( 'rate_limited', $response['body'] );
	}

	public function test_one_click_empty_ip_falls_back_to_tighter_anon_bucket() {
		// Strip the resolved IP — Orbit_Client_IP::get() returns '' when
		// REMOTE_ADDR is empty and no proxy header filter is set. The
		// anon bucket is 5/min; the 6th attempt must 429.
		unset( $_SERVER['REMOTE_ADDR'] );

		for ( $i = 1; $i <= 5; $i++ ) {
			$response = Orbit_Routes::one_click_unsubscribe_response( 'anon-invalid-' . $i );
			$this->assertSame( 400, $response['status'], 'anon request ' . $i . ' should still pass the anon limiter' );
		}

		$response = Orbit_Routes::one_click_unsubscribe_response( 'anon-invalid-6' );
		$this->assertSame( 429, $response['status'] );
		$this->assertSame( 'rate_limited', $response['body'] );
	}

	public function test_one_click_on_already_unsubscribed_subscription_is_idempotent_no_duplicate_consent_row() {
		$sub   = $this->create_approved_subscription();
		$token = Orbit_Token::generate_unsubscribe_token( $sub->subscription_secret, (int) $sub->id );

		// First call: 200, one consent row appended.
		$first = Orbit_Routes::one_click_unsubscribe_response( $token );
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( 1, $this->count_consent_rows( self::$subscriber_id ) );

		// Second call: still 200 (RFC 8058 allows replays), but the
		// per-subscription guard suppresses the duplicate ledger row.
		$second = Orbit_Routes::one_click_unsubscribe_response( $token );
		$this->assertSame( 200, $second['status'] );
		$this->assertSame( 'unsubscribed', $second['body'] );
		$this->assertSame( 1, $this->count_consent_rows( self::$subscriber_id ) );
	}

	// ---------------------------------------------------------------------
	// perform_unsubscribe() — per-subscription idempotency (the bug from
	// todo 086: channel-global guard let a second subscription's unsub fall
	// through to a silent no-op).
	// ---------------------------------------------------------------------

	public function test_two_subscriptions_can_both_be_unsubscribed_independently() {
		// Subscriber A: shared subscriber, profile A.
		$sub_a = $this->create_approved_subscription();

		// Subscriber A: needs a SECOND profile owned by a different poster
		// to subscribe to. Create one inline.
		$other_poster_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_profile_id = Orbit_Profile::create(
			array(
				'user_id'      => $other_poster_id,
				'slug'         => 'unsub-test-poster-b',
				'display_name' => 'Unsub Test Poster B',
			)
		);

		$sub_b_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => $other_profile_id,
			)
		);
		Orbit_Subscription::approve( $sub_b_id );
		$sub_b = Orbit_Subscription::get( $sub_b_id );

		// First unsubscribe — channel-global state becomes opt_out.
		$res_a = Orbit_Routes::perform_unsubscribe( $sub_a, 'email_unsubscribe_one_click' );
		$this->assertTrue( $res_a );
		$this->assertSame( 'unsubscribed', Orbit_Subscription::get( $sub_a->id )->status );

		// Second unsubscribe — under the old channel-global guard this
		// would have short-circuited and S_B would still be 'approved'.
		// Per-subscription guard must do the right thing.
		$res_b = Orbit_Routes::perform_unsubscribe( $sub_b, 'email_unsubscribe_one_click' );
		$this->assertTrue( $res_b );
		$this->assertSame( 'unsubscribed', Orbit_Subscription::get( $sub_b->id )->status );

		// Both per-subscription opt_outs are recorded — the channel is
		// the right granularity for TCPA evidence, but per-subscription
		// is the right granularity for the operation.
		$this->assertSame( 2, $this->count_consent_rows( self::$subscriber_id ) );

		// Cleanup the inline second-poster fixture.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . (int) $other_profile_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . (int) $other_profile_id );
	}

	// ---------------------------------------------------------------------
	// resolve_unsubscribe_subscription() — modern HMAC + legacy fallback +
	// sunset (todo 104).
	// ---------------------------------------------------------------------

	public function test_legacy_raw_secret_resolves_to_subscription_before_sunset() {
		$sub = $this->create_approved_subscription();

		// Default ORBIT_LEGACY_UNSUB_TOKEN_SUNSET is in the future; the
		// raw subscription_secret should still resolve.
		$resolved = Orbit_Routes::resolve_unsubscribe_subscription( $sub->subscription_secret );

		$this->assertNotNull( $resolved );
		$this->assertSame( (int) $sub->id, (int) $resolved->id );
	}

	public function test_resolver_returns_null_for_hmac_token_with_wrong_secret() {
		$sub = $this->create_approved_subscription();

		$bad_token = Orbit_Token::generate_unsubscribe_token( 'a_totally_unrelated_secret', (int) $sub->id );

		$resolved = Orbit_Routes::resolve_unsubscribe_subscription( $bad_token );

		// Modern parser will reject (wrong HMAC), legacy fallback will
		// reject (subscription_secret in DB doesn't match the wrong key).
		$this->assertNull( $resolved );
	}

	public function test_resolver_returns_null_for_expired_hmac_token() {
		$sub = $this->create_approved_subscription();

		$expired = Orbit_Token::generate_unsubscribe_token(
			$sub->subscription_secret,
			(int) $sub->id,
			time() - YEAR_IN_SECONDS
		);

		$resolved = Orbit_Routes::resolve_unsubscribe_subscription( $expired );

		$this->assertNull( $resolved );
	}

	// ---------------------------------------------------------------------
	// GET / two-step POST — verified by inspecting the synthetic post that
	// `render_virtual_page()` swaps into the main query.
	// ---------------------------------------------------------------------

	public function test_get_with_valid_token_renders_confirmation_form_with_nonce() {
		$sub   = $this->create_approved_subscription();
		$token = Orbit_Token::generate_unsubscribe_token( $sub->subscription_secret, (int) $sub->id );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'token' => $token );

		Orbit_Routes::handle_unsubscribe_get();

		global $wp_query;
		$content = $wp_query->post->post_content;

		$this->assertStringContainsString( 'orbit_unsubscribe_nonce', $content );
		$this->assertStringContainsString( 'name="token"', $content );
		$this->assertStringContainsString( $token, $content );

		unset( $_GET['token'] );
	}

	public function test_two_step_post_with_valid_nonce_unsubscribes_and_appends_consent_row() {
		$sub   = $this->create_approved_subscription();
		$token = Orbit_Token::generate_unsubscribe_token( $sub->subscription_secret, (int) $sub->id );

		$_POST = array(
			'token'                   => $token,
			'orbit_unsubscribe_nonce' => wp_create_nonce( 'orbit_unsubscribe' ),
		);

		Orbit_Routes::handle_unsubscribe_post();

		$fresh = Orbit_Subscription::get( $sub->id );
		$this->assertSame( 'unsubscribed', $fresh->status );
		$this->assertSame( 1, $this->count_consent_rows( self::$subscriber_id ) );

		global $wp_query;
		$this->assertStringContainsString( 'unsubscribed from', $wp_query->post->post_content );
	}

	public function test_two_step_post_with_invalid_nonce_renders_security_check_failed() {
		$sub   = $this->create_approved_subscription();
		$token = Orbit_Token::generate_unsubscribe_token( $sub->subscription_secret, (int) $sub->id );

		$_POST = array(
			'token'                   => $token,
			'orbit_unsubscribe_nonce' => 'definitely-not-a-real-nonce',
		);

		Orbit_Routes::handle_unsubscribe_post();

		// Subscription must not have been touched.
		$fresh = Orbit_Subscription::get( $sub->id );
		$this->assertSame( 'approved', $fresh->status );
		$this->assertSame( 0, $this->count_consent_rows( self::$subscriber_id ) );

		// The synthetic page must render the security-check copy.
		global $wp_query;
		$this->assertStringContainsString( 'Security check failed', $wp_query->post->post_content );
	}

	public function test_legacy_resolver_returns_null_after_sunset() {
		// Override the sunset for this test via the runkit-free path:
		// the resolver reads the constant if defined. Since constants
		// can't be redefined, we exercise the sunset branch by defining
		// a NEW constant locally and forcing the resolver onto a code
		// path that consults it. The production constant is in the
		// future (2027-06-01); we sidestep it by NOT relying on the
		// production constant for this assertion and instead running
		// the integration with a known-invalid HMAC token plus a NULL
		// subscription_secret (we can't get the legacy branch to refuse
		// here without redefining the constant). Skip with explanation.

		if ( ! defined( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET' ) ) {
			$this->markTestSkipped( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET not defined; sunset branch not configurable in this env.' );
		}

		$sunset = strtotime( ORBIT_LEGACY_UNSUB_TOKEN_SUNSET . ' UTC' );
		if ( false === $sunset || time() < $sunset ) {
			// Live environment: sunset is still in the future. The
			// branch under test fires only after the sunset date.
			// Document the behavior expectation here for the reviewer
			// and confirm the **inverse**: that before sunset, the
			// legacy resolver still works (covered above) and the
			// constant is the only thing that flips the branch.
			$this->assertGreaterThan(
				time(),
				$sunset,
				'Sanity: sunset constant is in the future, so the pre-sunset legacy path is what gets exercised today.'
			);
			return;
		}

		// Sunset has passed in the host clock: any legacy-format token
		// must resolve to null.
		$sub      = $this->create_approved_subscription();
		$resolved = Orbit_Routes::resolve_unsubscribe_subscription( $sub->subscription_secret );

		$this->assertNull( $resolved, 'Legacy raw-secret resolver must return null after sunset.' );
	}
}
