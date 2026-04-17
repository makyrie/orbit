<?php
/**
 * Tests for Orbit_Subscription.
 *
 * @package Orbit
 */

class OrbitSubscriptionTest extends WP_UnitTestCase {

	/**
	 * Poster user ID.
	 *
	 * @var int
	 */
	private static $poster_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Profile ID.
	 *
	 * @var int
	 */
	private static $profile_id;

	/**
	 * Set up test fixtures.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		self::$poster_id     = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		// Clean up any stale data from prior runs.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug = 'test-poster'" );

		self::$profile_id = Orbit_Profile::create(
			array(
				'user_id'      => self::$poster_id,
				'slug'         => 'test-poster',
				'display_name' => 'Test Poster',
			)
		);
	}

	/**
	 * Clean up class-level fixtures.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		if ( is_int( self::$profile_id ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . self::$profile_id );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . self::$profile_id );
		}
	}

	/**
	 * Clean up subscription rows after each test.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE user_id = " . self::$subscriber_id );
		parent::tear_down();
	}

	/**
	 * Test basic subscribe creates a pending subscription.
	 */
	public function test_subscribe_creates_pending() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$this->assertIsInt( $sub_id );

		$sub = Orbit_Subscription::get( $sub_id );
		$this->assertSame( 'pending', $sub->status );
		$this->assertNotEmpty( $sub->subscription_secret );
	}

	/**
	 * Test approve changes status from pending to approved.
	 */
	public function test_approve_subscription() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$result = Orbit_Subscription::approve( $sub_id );
		$this->assertTrue( $result );

		$sub = Orbit_Subscription::get( $sub_id );
		$this->assertSame( 'approved', $sub->status );
	}

	/**
	 * Test deny changes status from pending to denied.
	 */
	public function test_deny_subscription() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$result = Orbit_Subscription::deny( $sub_id );
		$this->assertTrue( $result );

		$sub = Orbit_Subscription::get( $sub_id );
		$this->assertSame( 'denied', $sub->status );
	}

	/**
	 * Test unsubscribe from approved state.
	 */
	public function test_unsubscribe() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		Orbit_Subscription::approve( $sub_id );
		$result = Orbit_Subscription::unsubscribe( $sub_id );
		$this->assertTrue( $result );

		$sub = Orbit_Subscription::get( $sub_id );
		$this->assertSame( 'unsubscribed', $sub->status );
	}

	/**
	 * Test re-subscribe reactivates existing record.
	 */
	public function test_resubscribe_reactivates() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		Orbit_Subscription::approve( $sub_id );
		Orbit_Subscription::unsubscribe( $sub_id );

		// Re-subscribe should reuse same record.
		$new_sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$this->assertSame( $sub_id, $new_sub_id );

		$sub = Orbit_Subscription::get( $sub_id );
		$this->assertSame( 'pending', $sub->status );
	}

	/**
	 * Test self-subscription is prevented.
	 */
	public function test_self_subscription_prevented() {
		$result = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$poster_id,
				'profile_id' => self::$profile_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'self_subscription', $result->get_error_code() );
	}

	/**
	 * Test duplicate active subscription is rejected.
	 */
	public function test_duplicate_subscription_rejected() {
		Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$result = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'already_subscribed', $result->get_error_code() );
	}

	/**
	 * Test invalid status transition is rejected.
	 */
	public function test_invalid_transition_rejected() {
		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		Orbit_Subscription::approve( $sub_id );

		// Can't approve an already-approved subscription.
		$result = Orbit_Subscription::approve( $sub_id );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_transition', $result->get_error_code() );
	}
}
