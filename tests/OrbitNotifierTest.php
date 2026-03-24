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

		Orbit_Activator::create_tables();
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
	 */
	public function test_resolve_notification_method_defaults() {
		// Ensure defaults exist.
		Orbit_Notifier::get_or_create_preferences( self::$user_id );

		$this->assertSame( 'digest', Orbit_Notifier::resolve_notification_method( self::$user_id, 1 ) );
		$this->assertSame( 'digest', Orbit_Notifier::resolve_notification_method( self::$user_id, 2 ) );
		$this->assertSame( 'sms', Orbit_Notifier::resolve_notification_method( self::$user_id, 3 ) );
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
