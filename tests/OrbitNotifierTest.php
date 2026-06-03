<?php
/**
 * Tests for Orbit_Notifier.
 *
 * @package Orbit
 */

class OrbitNotifierTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private static $user_id;

	/**
	 * Set up test fixtures.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$user_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Clean up notification preferences, log, subscriptions, activities,
	 * profiles, and the static cache after each test. Wider than v1.5
	 * because dispatch and observability tests below create their own
	 * fixtures that would otherwise leak between tests.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_NOTIFICATION_PREFERENCES );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_NOTIFICATION_LOG );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES );

		// Reset static cache.
		$reflection = new ReflectionProperty( 'Orbit_Notifier', 'preferences_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, array() );

		// Remove any per-test filters added inline.
		remove_all_filters( 'orbit_notification_method' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_mail' );
		remove_all_actions( 'orbit_notification_sent' );
		remove_all_actions( 'orbit_notification_failed' );
		remove_all_actions( 'orbit_notification_coerced' );

		parent::tear_down();
	}

	/**
	 * Test get_or_create_preferences creates defaults for new user.
	 */
	public function test_creates_default_preferences() {
		$prefs = Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$this->assertIsObject( $prefs );
		$this->assertSame( 'digest', $prefs->tier1_method );
		$this->assertSame( 'digest', $prefs->tier2_method );
		$this->assertSame( 'sms', $prefs->tier3_method );
		$this->assertNull( $prefs->sms_daily_cap );
		$this->assertSame( '18:00:00', $prefs->digest_time );
	}

	/**
	 * Test get_or_create_preferences returns cached result on second call.
	 */
	public function test_preferences_cached_within_request() {
		$prefs1 = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$prefs2 = Orbit_Notifier::get_or_create_preferences( self::$user_id );

		// Same object reference — cache hit.
		$this->assertSame( $prefs1, $prefs2 );
	}

	/**
	 * Test resolve_notification_method returns correct method per tier.
	 *
	 * With Orbit_Features::sms_enabled() === true (option flipped on),
	 * tier3 resolves to the stored 'sms' preference. With sms_enabled()
	 * === false (the default during the pre-approval window), tier3
	 * coerces to 'email' — that path is exercised in
	 * test_kill_switch_coerces_sms_to_email() below.
	 */
	public function test_resolve_notification_method_defaults() {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, '1' );

		// Ensure defaults exist.
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$this->assertSame( 'digest', Orbit_Notifier::resolve_notification_method( self::$user_id, 1 ) );
		$this->assertSame( 'digest', Orbit_Notifier::resolve_notification_method( self::$user_id, 2 ) );
		$this->assertSame( 'sms', Orbit_Notifier::resolve_notification_method( self::$user_id, 3 ) );

		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
	}

	/**
	 * Kill-switch: when Orbit_Features::sms_enabled() is false, a stored
	 * tier3_method of 'sms' is coerced to 'email' in-flight. The DB row
	 * is NOT mutated — the user's intended preference is preserved.
	 */
	public function test_kill_switch_coerces_sms_to_email() {
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$this->assertFalse( Orbit_Features::sms_enabled() );
		$this->assertSame( 'email', Orbit_Notifier::resolve_notification_method( self::$user_id, 3 ) );

		// Underlying preference row is still 'sms'.
		$reflection = new ReflectionProperty( 'Orbit_Notifier', 'preferences_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, array() );
		$prefs = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertSame( 'sms', $prefs->tier3_method );
	}

	/**
	 * Kill-switch fires orbit_notification_coerced for audit signal.
	 */
	public function test_kill_switch_fires_coerced_action() {
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$fired = 0;
		$captured_user = null;
		$captured_tier = null;
		add_action(
			'orbit_notification_coerced',
			function ( $user_id, $tier, $context ) use ( &$fired, &$captured_user, &$captured_tier ) {
				++$fired;
				$captured_user = $user_id;
				$captured_tier = $tier;
			},
			10,
			3
		);

		Orbit_Notifier::resolve_notification_method( self::$user_id, 3, array( 'activity_id' => 42 ) );

		$this->assertSame( 1, $fired );
		$this->assertSame( self::$user_id, $captured_user );
		$this->assertSame( 3, $captured_tier );
	}

	/**
	 * Filter `orbit_notification_method` is invoked with the resolved
	 * method and can override it.
	 */
	public function test_notification_method_filter_overrides() {
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		add_filter(
			'orbit_notification_method',
			static function ( $method, $user_id, $tier, $context ) {
				return 'none';
			},
			10,
			4
		);

		$this->assertSame( 'none', Orbit_Notifier::resolve_notification_method( self::$user_id, 3 ) );

		remove_all_filters( 'orbit_notification_method' );
	}

	/**
	 * Test update_preferences changes methods.
	 */
	public function test_update_preferences() {
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$result = Orbit_Notifier::update_preferences(
			self::$user_id,
			array(
				'tier1_method' => 'email',
				'tier3_method' => 'digest',
			)
		);

		$this->assertTrue( $result );

		// Clear cache to read from DB.
		$reflection = new ReflectionProperty( 'Orbit_Notifier', 'preferences_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, array() );

		$prefs = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertSame( 'email', $prefs->tier1_method );
		$this->assertSame( 'digest', $prefs->tier3_method );
	}

	/**
	 * Test sms_daily_cap null is stored correctly (not as 0).
	 */
	public function test_sms_daily_cap_null_storage() {
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		// Set a cap, then set it back to null.
		Orbit_Notifier::update_preferences( self::$user_id, array( 'sms_daily_cap' => 5 ) );

		// Clear cache.
		$reflection = new ReflectionProperty( 'Orbit_Notifier', 'preferences_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, array() );

		$prefs = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertSame( 5, (int) $prefs->sms_daily_cap );

		// Set back to null.
		Orbit_Notifier::update_preferences( self::$user_id, array( 'sms_daily_cap' => null ) );

		$reflection->setValue( null, array() );

		$prefs = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertNull( $prefs->sms_daily_cap );
	}

	/**
	 * Helper: create a profile owned by a freshly created user.
	 *
	 * @param string $slug Slug to use for the profile.
	 * @return int Profile ID.
	 */
	private function create_profile_with_owner( $slug = 'poster-001' ) {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		return Orbit_Profile::create(
			array(
				'user_id'          => $owner_id,
				'slug'             => $slug,
				'display_name'     => 'Poster',
				'require_approval' => false,
			)
		);
	}

	/**
	 * Helper: insert an approved subscription row directly so we can quickly
	 * stage 501 rows without paying per-call validation overhead.
	 *
	 * @param int $user_id    Subscriber user ID.
	 * @param int $profile_id Profile being subscribed to.
	 * @return int Subscription row ID.
	 */
	private function insert_approved_subscription( $user_id, $profile_id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'user_id'             => absint( $user_id ),
				'profile_id'          => absint( $profile_id ),
				'status'              => 'approved',
				'visibility_default'  => 'anonymous',
				'subscription_secret' => wp_generate_password( 32, false, false ),
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Dispatch must paginate above DISPATCH_BATCH_SIZE and log every
	 * subscriber in the fan-out.
	 *
	 * Creates DISPATCH_BATCH_SIZE + 1 approved subscribers on a tier-1
	 * activity (default tier1_method = 'digest', which writes a queued row
	 * to the notification log synchronously inside dispatch_to_subscriber()
	 * — no ActionScheduler dependency needed).
	 */
	public function test_process_dispatch_paginates_above_batch_size() {
		global $wpdb;

		$profile_id  = $this->create_profile_with_owner( 'paginate-poster' );
		$total       = Orbit_Notifier::DISPATCH_BATCH_SIZE + 1;
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => $profile_id,
				'tier'       => 1,
				'title'      => 'Big fan-out',
			)
		);
		$this->assertIsInt( $activity_id );

		for ( $i = 0; $i < $total; $i++ ) {
			$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			$this->insert_approved_subscription( $uid, $profile_id );
		}

		Orbit_Notifier::process_dispatch( $activity_id );

		$log_table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$logged    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE activity_id = %d AND method = 'digest'",
				$activity_id
			)
		);

		$this->assertSame( $total, $logged, 'Every subscriber across the batch boundary must be logged.' );
	}

	/**
	 * `orbit_notification_sent` fires with the expected positional args
	 * (including the new idempotency_key tail arg) when email handoff
	 * succeeds.
	 *
	 * We short-circuit wp_mail via the `pre_wp_mail` filter so no actual
	 * mailer is required and the email path returns true → status = 'sent'.
	 */
	public function test_orbit_notification_sent_fires_on_email_success() {
		$profile_id  = $this->create_profile_with_owner( 'sent-poster' );
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => $profile_id,
				'tier'       => 2,
				'title'      => 'Sent fixture',
			)
		);
		$this->assertIsInt( $activity_id );

		$this->insert_approved_subscription( self::$user_id, $profile_id );

		// Force wp_mail() to "succeed" without touching the network.
		add_filter( 'pre_wp_mail', '__return_true' );

		$captured = array();
		add_action(
			'orbit_notification_sent',
			function ( $uid, $aid, $method, $log_id, $key ) use ( &$captured ) {
				$captured[] = compact( 'uid', 'aid', 'method', 'log_id', 'key' );
			},
			10,
			5
		);

		Orbit_Notifier::process_immediate_notification( self::$user_id, $activity_id, 'email' );

		$this->assertCount( 1, $captured, 'orbit_notification_sent should fire exactly once on success.' );
		$this->assertSame( self::$user_id, $captured[0]['uid'] );
		$this->assertSame( $activity_id, $captured[0]['aid'] );
		$this->assertSame( 'email', $captured[0]['method'] );
		$this->assertIsInt( $captured[0]['log_id'] );
		$this->assertGreaterThan( 0, $captured[0]['log_id'] );
		$this->assertSame(
			self::$user_id . '|' . $activity_id . '|email',
			$captured[0]['key'],
			'Idempotency key must be "{user_id}|{activity_id}|{method}".'
		);
	}

	/**
	 * `orbit_notification_failed` fires with the WP_Error tail arg and the
	 * idempotency key when wp_mail() reports failure.
	 */
	public function test_orbit_notification_failed_fires_on_email_failure() {
		$profile_id  = $this->create_profile_with_owner( 'failed-poster' );
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => $profile_id,
				'tier'       => 2,
				'title'      => 'Failed fixture',
			)
		);
		$this->assertIsInt( $activity_id );

		$this->insert_approved_subscription( self::$user_id, $profile_id );

		// Short-circuit wp_mail() to return false → send_immediate_email()
		// returns WP_Error → status = 'failed'.
		add_filter( 'pre_wp_mail', '__return_false' );

		$captured = array();
		add_action(
			'orbit_notification_failed',
			function ( $uid, $aid, $method, $log_id, $error, $key ) use ( &$captured ) {
				$captured[] = compact( 'uid', 'aid', 'method', 'log_id', 'error', 'key' );
			},
			10,
			6
		);

		Orbit_Notifier::process_immediate_notification( self::$user_id, $activity_id, 'email' );

		$this->assertCount( 1, $captured, 'orbit_notification_failed should fire exactly once on failure.' );
		$this->assertSame( self::$user_id, $captured[0]['uid'] );
		$this->assertSame( $activity_id, $captured[0]['aid'] );
		$this->assertSame( 'email', $captured[0]['method'] );
		$this->assertInstanceOf( 'WP_Error', $captured[0]['error'] );
		$this->assertSame(
			self::$user_id . '|' . $activity_id . '|email',
			$captured[0]['key'],
			'Idempotency key must be "{user_id}|{activity_id}|{method}".'
		);
	}
}
