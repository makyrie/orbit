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
	 * Clean up notification preferences and static cache after each test.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_NOTIFICATION_PREFERENCES . " WHERE user_id = " . self::$user_id );

		// Reset static cache.
		$reflection = new ReflectionProperty( 'Orbit_Notifier', 'preferences_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, array() );

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
}
