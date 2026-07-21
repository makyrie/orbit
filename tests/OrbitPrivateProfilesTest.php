<?php
/**
 * Tests for the private-profiles feature: memorable share codes, the /hi/
 * invite link, and the owner/approved-only access rules that keep a profile
 * and its activities hidden from everyone else.
 *
 * @package Orbit
 */

class OrbitPrivateProfilesTest extends WP_UnitTestCase {

	/**
	 * Profile owner (poster).
	 *
	 * @var int
	 */
	private static $owner_id;

	/**
	 * Approved subscriber.
	 *
	 * @var int
	 */
	private static $approved_id;

	/**
	 * Pending subscriber.
	 *
	 * @var int
	 */
	private static $pending_id;

	/**
	 * Logged-in user with no relationship to the profile.
	 *
	 * @var int
	 */
	private static $stranger_id;

	/**
	 * Profile ID under test.
	 *
	 * @var int
	 */
	private static $profile_id;

	/**
	 * An activity belonging to the profile.
	 *
	 * @var int
	 */
	private static $activity_id;

	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		self::$owner_id    = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$approved_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$pending_id  = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$stranger_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug = 'private-poster'" );

		self::$profile_id = Orbit_Profile::create(
			array(
				'user_id'      => self::$owner_id,
				'slug'         => 'private-poster',
				'display_name' => 'Private Poster',
			)
		);

		self::$activity_id = Orbit_Activity::create(
			array(
				'profile_id' => self::$profile_id,
				'tier'       => 1,
				'title'      => 'Secret picnic',
			)
		);

		$approved_sub = Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$approved_id,
				'profile_id' => self::$profile_id,
			)
		);
		Orbit_Subscription::approve( $approved_sub );

		Orbit_Subscription::subscribe(
			array(
				'user_id'    => self::$pending_id,
				'profile_id' => self::$profile_id,
			)
		);
	}

	public static function wpTearDownAfterClass() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . " WHERE profile_id = " . (int) self::$profile_id );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES . " WHERE profile_id = " . (int) self::$profile_id );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE id = " . (int) self::$profile_id );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		unset( $_GET['act'] );
		parent::tear_down();
	}

	/*
	 * --------------------------------------------------------------------
	 *  Share-code generation
	 * --------------------------------------------------------------------
	 */

	public function test_generate_returns_three_lowercase_words() {
		$code = Orbit_Share_Code::generate( '__return_false' );

		$this->assertMatchesRegularExpression( '/^[a-z]+-[a-z]+-[a-z]+$/', $code );
	}

	public function test_generate_appends_suffix_when_space_exhausted() {
		// A predicate that claims every candidate is taken forces the bounded
		// retry loop to fall through to the random-suffix guarantee.
		$code = Orbit_Share_Code::generate( '__return_true' );

		$this->assertMatchesRegularExpression( '/^[a-z]+-[a-z]+-[a-z]+-[A-Za-z0-9]{4}$/', $code );
	}

	public function test_generated_codes_are_reasonably_unique() {
		$seen = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$seen[ Orbit_Share_Code::generate( '__return_false' ) ] = true;
		}

		// The keyspace dwarfs 50 draws; collisions should be vanishingly rare.
		$this->assertGreaterThanOrEqual( 48, count( $seen ) );
	}

	/*
	 * --------------------------------------------------------------------
	 *  Profile share-code plumbing
	 * --------------------------------------------------------------------
	 */

	public function test_create_assigns_a_share_code() {
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertNotEmpty( $profile->share_code );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $profile->share_code );
	}

	public function test_get_by_share_code_resolves_the_profile() {
		$profile = Orbit_Profile::get( self::$profile_id );

		$found = Orbit_Profile::get_by_share_code( $profile->share_code );
		$this->assertNotNull( $found );
		$this->assertSame( (int) self::$profile_id, (int) $found->id );
	}

	public function test_get_by_share_code_returns_null_for_empty_and_unknown() {
		$this->assertNull( Orbit_Profile::get_by_share_code( '' ) );
		$this->assertNull( Orbit_Profile::get_by_share_code( 'no-such-code' ) );
	}

	public function test_share_url_points_at_hi_route() {
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertSame(
			home_url( '/hi/' . rawurlencode( $profile->share_code ) ),
			Orbit_Profile::share_url( $profile )
		);
	}

	public function test_share_url_falls_back_to_legacy_token_link() {
		$legacy = (object) array(
			'share_code'  => '',
			'slug'        => 'legacy',
			'share_token' => 'RAWTOKEN123',
		);

		$this->assertSame(
			home_url( '/@legacy/subscribe?token=RAWTOKEN123' ),
			Orbit_Profile::share_url( $legacy )
		);
	}

	public function test_reroll_changes_code_and_retires_the_old_one() {
		$before = Orbit_Profile::get( self::$profile_id )->share_code;

		$new = Orbit_Profile::reroll_share_code( self::$profile_id );
		$this->assertIsString( $new );
		$this->assertNotSame( $before, $new );

		// The new code resolves; the old one no longer does.
		$this->assertNotNull( Orbit_Profile::get_by_share_code( $new ) );
		$this->assertNull( Orbit_Profile::get_by_share_code( $before ) );
	}

	/*
	 * --------------------------------------------------------------------
	 *  Profile access rules
	 * --------------------------------------------------------------------
	 */

	public function test_logged_out_visitor_cannot_see_profile() {
		wp_set_current_user( 0 );
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertFalse( Orbit_Routes::viewer_can_see_profile( $profile ) );
	}

	public function test_owner_can_see_profile() {
		wp_set_current_user( self::$owner_id );
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertTrue( Orbit_Routes::viewer_can_see_profile( $profile ) );
	}

	public function test_approved_subscriber_can_see_profile() {
		wp_set_current_user( self::$approved_id );
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertTrue( Orbit_Routes::viewer_can_see_profile( $profile ) );
	}

	public function test_pending_subscriber_cannot_see_profile() {
		wp_set_current_user( self::$pending_id );
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertFalse( Orbit_Routes::viewer_can_see_profile( $profile ) );
	}

	public function test_unrelated_user_cannot_see_profile() {
		wp_set_current_user( self::$stranger_id );
		$profile = Orbit_Profile::get( self::$profile_id );

		$this->assertFalse( Orbit_Routes::viewer_can_see_profile( $profile ) );
	}

	/*
	 * --------------------------------------------------------------------
	 *  Activity access rules
	 * --------------------------------------------------------------------
	 */

	public function test_owner_can_see_activity() {
		wp_set_current_user( self::$owner_id );
		$activity = Orbit_Activity::get( self::$activity_id );

		$this->assertTrue( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_approved_subscriber_can_see_activity() {
		wp_set_current_user( self::$approved_id );
		$activity = Orbit_Activity::get( self::$activity_id );

		$this->assertTrue( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_pending_subscriber_cannot_see_activity() {
		wp_set_current_user( self::$pending_id );
		$activity = Orbit_Activity::get( self::$activity_id );

		$this->assertFalse( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_stranger_without_token_cannot_see_activity() {
		wp_set_current_user( 0 );
		$activity = Orbit_Activity::get( self::$activity_id );

		$this->assertFalse( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_valid_action_token_grants_activity_view_when_logged_out() {
		wp_set_current_user( 0 );

		$sub = Orbit_Subscription::get_by_user_and_profile( self::$approved_id, self::$profile_id );
		$this->assertSame( 'approved', $sub->status );

		$_GET['act'] = Orbit_Token::generate_action_token(
			$sub->subscription_secret,
			self::$activity_id,
			$sub->id
		);

		$activity = Orbit_Activity::get( self::$activity_id );
		$this->assertTrue( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_action_token_for_pending_subscriber_is_rejected() {
		wp_set_current_user( 0 );

		$sub = Orbit_Subscription::get_by_user_and_profile( self::$pending_id, self::$profile_id );
		$this->assertSame( 'pending', $sub->status );

		$_GET['act'] = Orbit_Token::generate_action_token(
			$sub->subscription_secret,
			self::$activity_id,
			$sub->id
		);

		$activity = Orbit_Activity::get( self::$activity_id );
		$this->assertFalse( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}

	public function test_tampered_action_token_is_rejected() {
		wp_set_current_user( 0 );

		$sub = Orbit_Subscription::get_by_user_and_profile( self::$approved_id, self::$profile_id );

		// A token minted against the wrong secret must not validate.
		$_GET['act'] = Orbit_Token::generate_action_token(
			'not-the-real-secret',
			self::$activity_id,
			$sub->id
		);

		$activity = Orbit_Activity::get( self::$activity_id );
		$this->assertFalse( Orbit_Routes::viewer_can_see_activity( $activity ) );
	}
}
