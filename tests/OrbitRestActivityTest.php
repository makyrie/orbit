<?php
/**
 * Tests for the activity REST controller (Orbit_REST_Activity).
 *
 * Covers all 7 routes registered by the controller:
 *   POST   /orbit/v1/activities                    create
 *   GET    /orbit/v1/activities                    list
 *   PATCH  /orbit/v1/activities/{id}               update
 *   DELETE /orbit/v1/activities/{id}               cancel
 *   GET    /orbit/v1/activities/{id}/responses     list responses (owner/admin)
 *   POST   /orbit/v1/respond                       submit response (token or session)
 *   DELETE /orbit/v1/respond                       remove response (session)
 *
 * @package Orbit
 */

class OrbitRestActivityTest extends WP_UnitTestCase {

	/** @var int Poster (admin role, has orbit_create_activity). */
	private static $poster_id;

	/** @var int Second poster for cross-profile checks. */
	private static $other_poster_id;

	/** @var int Approved subscriber. */
	private static $subscriber_id;

	/** @var int Subscriber whose subscription is still pending approval. */
	private static $pending_user_id;

	/** @var int Logged-in user with no subscription to either profile. */
	private static $stranger_id;

	/** @var int Poster's profile (auto-approves subscriptions). */
	private static $profile_id;

	/** @var int Second poster's profile (requires approval). */
	private static $other_profile_id;

	/** @var int Approved subscription (subscriber -> poster). */
	private static $approved_sub_id;

	/** @var int Pending subscription (pending_user -> other_poster). */
	private static $pending_sub_id;

	/**
	 * Class-level fixtures: 4 users, 2 profiles, 2 subscriptions.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		self::$poster_id       = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$other_poster_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id   = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$pending_user_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$stranger_id     = $factory->user->create( array( 'role' => 'subscriber' ) );

		// Clean any stale rows from a prior failed run.
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug IN ('rest-test-poster', 'rest-test-other')"
		);

		self::$profile_id = Orbit_Profile::create(
			array(
				'user_id'          => self::$poster_id,
				'slug'             => 'rest-test-poster',
				'display_name'     => 'REST Test Poster',
				'require_approval' => false,
			)
		);

		self::$other_profile_id = Orbit_Profile::create(
			array(
				'user_id'          => self::$other_poster_id,
				'slug'             => 'rest-test-other',
				'display_name'     => 'REST Test Other',
				'require_approval' => true,
			)
		);

		self::$approved_sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$subscriber_id,
				'profile_id' => self::$profile_id,
			)
		);

		self::$pending_sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$pending_user_id,
				'profile_id' => self::$other_profile_id,
			)
		);
	}

	public static function wpTearDownAfterClass() {
		global $wpdb;

		foreach ( array( self::$profile_id, self::$other_profile_id ) as $profile_id ) {
			if ( ! is_int( $profile_id ) ) {
				continue;
			}
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES . " WHERE profile_id = " . (int) $profile_id );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . (int) $profile_id );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . (int) $profile_id );
		}
	}

	/**
	 * Spin up a fresh REST server before each test so route registrations
	 * are isolated and don't carry across tests.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		// Wipe per-test data; class-level rows (profiles/subs) survive.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES );

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Build and dispatch a REST request as a given user (or anonymous).
	 *
	 * @param string   $method  HTTP method.
	 * @param string   $route   Route path (e.g. '/orbit/v1/activities').
	 * @param array    $params  Request parameters.
	 * @param int|null $user_id User to authenticate as, or null for anonymous.
	 * @return WP_REST_Response
	 */
	private function dispatch( $method, $route, $params = array(), $user_id = null ) {
		wp_set_current_user( $user_id ? (int) $user_id : 0 );

		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Convenience: create an activity for the test poster.
	 */
	private function create_activity( $args = array() ) {
		return Orbit_Activity::create(
			array_merge(
				array(
					'profile_id' => self::$profile_id,
					'tier'       => 2,
					'title'      => 'Test Activity',
				),
				$args
			)
		);
	}

	// ---------------------------------------------------------------- //
	// POST /activities — create_activity
	// ---------------------------------------------------------------- //

	public function test_create_activity_as_owner_returns_201() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 2,
				'title'      => 'Brand new activity',
			),
			self::$poster_id
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Brand new activity', $data->title );
		$this->assertSame( (int) self::$profile_id, (int) $data->profile_id );
		$this->assertSame( 'active', $data->status );
	}

	public function test_create_activity_for_other_profile_returns_403() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$other_profile_id,
				'tier'       => 1,
				'title'      => 'Cross-profile attempt',
			),
			self::$poster_id
		);

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'forbidden', $data['code'] );
	}

	public function test_create_activity_anonymous_returns_401() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 2,
				'title'      => 'Anonymous attempt',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_create_activity_without_create_capability_returns_403() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 2,
				'title'      => 'Subscriber attempt',
			),
			self::$subscriber_id
		);

		// Subscriber role lacks orbit_create_activity — 403, not 401.
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_create_activity_invalid_tier_returns_400() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 9,
				'title'      => 'Bogus tier',
			),
			self::$poster_id
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_tier', $data['code'] );
	}

	public function test_create_activity_missing_title_returns_400() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/activities',
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 2,
			),
			self::$poster_id
		);

		// REST schema enforces required:true at the framework layer.
		$this->assertSame( 400, $response->get_status() );
	}

	// ---------------------------------------------------------------- //
	// GET /activities — get_activities
	// ---------------------------------------------------------------- //

	public function test_list_activities_for_subscriber_returns_subscribed_activities() {
		$activity_id = $this->create_activity( array( 'title' => 'Subscriber-visible' ) );

		$response = $this->dispatch( 'GET', '/orbit/v1/activities', array(), self::$subscriber_id );

		$this->assertSame( 200, $response->get_status() );
		$ids = array_map( function ( $a ) { return (int) $a->id; }, $response->get_data() );
		$this->assertContains( (int) $activity_id, $ids );
	}

	public function test_list_activities_filtered_by_profile_requires_subscription() {
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => self::$other_profile_id,
				'tier'       => 1,
				'title'      => 'Other-profile activity',
			)
		);

		// Stranger has no subscription to other_profile.
		$response = $this->dispatch(
			'GET',
			'/orbit/v1/activities',
			array( 'profile_id' => self::$other_profile_id ),
			self::$stranger_id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	public function test_list_activities_filtered_by_own_profile_succeeds_for_owner() {
		$activity_id = $this->create_activity( array( 'title' => 'Owner self-view' ) );

		$response = $this->dispatch(
			'GET',
			'/orbit/v1/activities',
			array( 'profile_id' => self::$profile_id ),
			self::$poster_id
		);

		$this->assertSame( 200, $response->get_status() );
		$ids = array_map( function ( $a ) { return (int) $a->id; }, $response->get_data() );
		$this->assertContains( (int) $activity_id, $ids );
	}

	// ---------------------------------------------------------------- //
	// PATCH /activities/{id} — update_activity
	// ---------------------------------------------------------------- //

	public function test_update_activity_as_owner_persists_changes() {
		$activity_id = $this->create_activity( array( 'title' => 'Original title' ) );

		$response = $this->dispatch(
			'PATCH',
			'/orbit/v1/activities/' . $activity_id,
			array( 'title' => 'Updated title' ),
			self::$poster_id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated title', $response->get_data()->title );

		// Persisted to DB.
		$reloaded = Orbit_Activity::get( $activity_id );
		$this->assertSame( 'Updated title', $reloaded->title );
	}

	public function test_update_activity_as_non_owner_is_forbidden() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'PATCH',
			'/orbit/v1/activities/' . $activity_id,
			array( 'title' => 'Hijacked' ),
			self::$subscriber_id
		);

		// can_manage_activity returns false → 403 (logged in, but unauthorized).
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_update_nonexistent_activity_is_forbidden() {
		// can_manage_activity returns false for a missing activity.
		$response = $this->dispatch(
			'PATCH',
			'/orbit/v1/activities/9999999',
			array( 'title' => 'Nope' ),
			self::$poster_id
		);

		$this->assertSame( 403, $response->get_status() );
	}

	// ---------------------------------------------------------------- //
	// DELETE /activities/{id} — cancel_activity
	// ---------------------------------------------------------------- //

	public function test_cancel_activity_as_owner_marks_status_cancelled() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'DELETE',
			'/orbit/v1/activities/' . $activity_id,
			array(),
			self::$poster_id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()->status );

		$reloaded = Orbit_Activity::get( $activity_id );
		$this->assertSame( 'cancelled', $reloaded->status );
	}

	public function test_cancel_activity_as_non_owner_is_forbidden() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'DELETE',
			'/orbit/v1/activities/' . $activity_id,
			array(),
			self::$subscriber_id
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'active', Orbit_Activity::get( $activity_id )->status );
	}

	// ---------------------------------------------------------------- //
	// POST /respond — handle_respond
	// ---------------------------------------------------------------- //

	public function test_respond_as_approved_subscriber_records_response() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'POST',
			'/orbit/v1/respond',
			array(
				'activity_id' => $activity_id,
				'response'    => 'going',
			),
			self::$subscriber_id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'going', $response->get_data()['response'] );

		$row = Orbit_Response::get_by_activity_and_subscription( $activity_id, self::$approved_sub_id );
		$this->assertSame( 'going', $row->response );
	}

	public function test_respond_with_valid_action_token_succeeds_anonymous() {
		$activity_id = $this->create_activity();

		$subscription = Orbit_Subscription::get( self::$approved_sub_id );
		$token        = Orbit_Token::generate_action_token(
			$subscription->subscription_secret,
			$activity_id,
			self::$approved_sub_id
		);

		$response = $this->dispatch(
			'POST',
			'/orbit/v1/respond',
			array(
				'activity_id' => $activity_id,
				'response'    => 'maybe',
				'act'         => $token,
			)
			// No user_id — anonymous request, token-authenticated.
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'maybe', $response->get_data()['response'] );
	}

	public function test_respond_with_invalid_action_token_returns_403() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'POST',
			'/orbit/v1/respond',
			array(
				'activity_id' => $activity_id,
				'response'    => 'going',
				'act'         => 'definitely-not-a-real-token',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'invalid_token', $response->get_data()['code'] );
	}

	public function test_respond_as_non_subscriber_returns_403() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'POST',
			'/orbit/v1/respond',
			array(
				'activity_id' => $activity_id,
				'response'    => 'going',
			),
			self::$stranger_id
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_subscribed', $response->get_data()['code'] );
	}

	public function test_respond_to_unknown_activity_returns_404() {
		$response = $this->dispatch(
			'POST',
			'/orbit/v1/respond',
			array(
				'activity_id' => 9999999,
				'response'    => 'going',
			),
			self::$subscriber_id
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] );
	}

	// ---------------------------------------------------------------- //
	// DELETE /respond — handle_remove_response
	// ---------------------------------------------------------------- //

	public function test_remove_response_as_subscriber_deletes_row() {
		$activity_id = $this->create_activity();

		Orbit_Response::set(
			array(
				'activity_id'     => $activity_id,
				'subscription_id' => self::$approved_sub_id,
				'response'        => 'going',
			)
		);

		$response = $this->dispatch(
			'DELETE',
			'/orbit/v1/respond',
			array( 'activity_id' => $activity_id ),
			self::$subscriber_id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull(
			Orbit_Response::get_by_activity_and_subscription( $activity_id, self::$approved_sub_id )
		);
	}

	public function test_remove_response_when_none_exists_returns_404() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'DELETE',
			'/orbit/v1/respond',
			array( 'activity_id' => $activity_id ),
			self::$subscriber_id
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'no_response', $response->get_data()['code'] );
	}

	// ---------------------------------------------------------------- //
	// GET /activities/{id}/responses — get_activity_responses
	// ---------------------------------------------------------------- //

	public function test_get_activity_responses_as_owner_returns_responses() {
		$activity_id = $this->create_activity();

		Orbit_Response::set(
			array(
				'activity_id'     => $activity_id,
				'subscription_id' => self::$approved_sub_id,
				'response'        => 'going',
			)
		);

		$response = $this->dispatch(
			'GET',
			'/orbit/v1/activities/' . $activity_id . '/responses',
			array(),
			self::$poster_id
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'going', $data[0]->response );
	}

	public function test_get_activity_responses_as_non_owner_is_forbidden() {
		$activity_id = $this->create_activity();

		$response = $this->dispatch(
			'GET',
			'/orbit/v1/activities/' . $activity_id . '/responses',
			array(),
			self::$subscriber_id
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
