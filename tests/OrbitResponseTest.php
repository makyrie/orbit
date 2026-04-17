<?php
/**
 * Tests for Orbit_Response.
 *
 * @package Orbit
 */

class OrbitResponseTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private static $poster_id;

	/**
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * @var int
	 */
	private static $profile_id;

	/**
	 * @var int
	 */
	private static $subscription_id;

	/**
	 * @var int
	 */
	private static $activity_id;

	/**
	 * Set up test fixtures.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		self::$poster_id     = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		// Clean up any stale data from prior runs.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug = 'resp-test-poster'" );

		self::$profile_id = Orbit_Profile::create(
			array(
				'user_id'          => self::$poster_id,
				'slug'             => 'resp-test-poster',
				'display_name'     => 'Response Test Poster',
				'require_approval' => false,
			)
		);

		self::$subscription_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		self::$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 3,
				'title'      => 'Test Activity',
			)
		);
	}

	/**
	 * Clean up class-level fixtures.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		if ( is_int( self::$profile_id ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . self::$profile_id );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES . " WHERE profile_id = " . self::$profile_id );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . self::$profile_id );
		}
	}

	/**
	 * Clean up response rows after each test.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES );
		parent::tear_down();
	}

	/**
	 * Test setting a response.
	 */
	public function test_set_response() {
		$result = Orbit_Response::set(
			array(
				'activity_id'     => self::$activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Test upsert idempotency — setting response twice updates, doesn't duplicate.
	 */
	public function test_upsert_idempotency() {
		$id1 = Orbit_Response::set(
			array(
				'activity_id'     => self::$activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$id2 = Orbit_Response::set(
			array(
				'activity_id'     => self::$activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'maybe',
			)
		);

		// Same record ID — updated, not duplicated.
		$this->assertSame( $id1, $id2 );

		// Verify the response was updated.
		$response = Orbit_Response::get_by_activity_and_subscription( self::$activity_id, self::$subscription_id );
		$this->assertSame( 'maybe', $response->response );
	}

	/**
	 * Test response to cancelled activity is rejected.
	 */
	public function test_cancelled_activity_rejected() {
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 2,
				'title'      => 'Cancelled Activity',
			)
		);

		Orbit_Activity::cancel( $activity_id );

		$result = Orbit_Response::set(
			array(
				'activity_id'     => $activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'activity_cancelled', $result->get_error_code() );
	}

	/**
	 * Test response requires approved subscription.
	 */
	public function test_unapproved_subscription_rejected() {
		$pending_user = self::factory()->user->create();
		$profile_id   = Orbit_Profile::create(
			array(
				'user_id'          => self::factory()->user->create(),
				'slug'             => 'approval-test',
				'display_name'     => 'Approval Test',
				'require_approval' => true,
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $pending_user,
				'profile_id' => $profile_id,
			)
		);

		// Subscription is pending, not approved.
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => $profile_id,
				'tier'       => 1,
				'title'      => 'Restricted Activity',
			)
		);

		$result = Orbit_Response::set(
			array(
				'activity_id'     => $activity_id,
				'subscription_id' => $sub_id,
				'response'        => 'going',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_approved', $result->get_error_code() );
	}

	/**
	 * Test response with wrong profile is rejected.
	 */
	public function test_profile_mismatch_rejected() {
		// Create a second profile.
		$other_profile_id = Orbit_Profile::create(
			array(
				'user_id'      => self::factory()->user->create(),
				'slug'         => 'other-profile',
				'display_name' => 'Other Profile',
			)
		);

		$other_activity = Orbit_Activity::create(
			array(
				'profile_id' => $other_profile_id,
				'tier'       => 1,
				'title'      => 'Other Activity',
			)
		);

		// Try to respond using a subscription to a different profile.
		$result = Orbit_Response::set(
			array(
				'activity_id'     => $other_activity,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'activity_profile_mismatch', $result->get_error_code() );
	}

	/**
	 * Test removing a response.
	 */
	public function test_remove_response() {
		$response_id = Orbit_Response::set(
			array(
				'activity_id'     => self::$activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$result = Orbit_Response::remove( $response_id );
		$this->assertTrue( $result );

		$response = Orbit_Response::get_by_activity_and_subscription( self::$activity_id, self::$subscription_id );
		$this->assertNull( $response );
	}

	/**
	 * Test batch count by activity IDs.
	 */
	public function test_count_by_activity_ids() {
		// Set a response.
		Orbit_Response::set(
			array(
				'activity_id'     => self::$activity_id,
				'subscription_id' => self::$subscription_id,
				'response'        => 'going',
			)
		);

		$counts = Orbit_Response::count_by_activity_ids( array( self::$activity_id ) );

		$this->assertArrayHasKey( self::$activity_id, $counts );
		$this->assertSame( 1, $counts[ self::$activity_id ]['going'] );
		$this->assertSame( 0, $counts[ self::$activity_id ]['maybe'] );
		$this->assertSame( 1, $counts[ self::$activity_id ]['total'] );
	}
}
