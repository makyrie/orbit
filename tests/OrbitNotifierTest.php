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
		remove_all_filters( 'orbit_email_footer_html' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_mail' );
		remove_all_actions( 'orbit_notification_sent' );
		remove_all_actions( 'orbit_notification_failed' );
		remove_all_actions( 'orbit_notification_coerced' );

		parent::tear_down();
	}

	/**
	 * The multipart plaintext part (PHPMailer's AltBody).
	 *
	 * @param object $mailer The MockPHPMailer instance.
	 * @return string
	 */
	private function alt_body( $mailer ) {
		return (string) $mailer->AltBody; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API property.
	}

	/**
	 * The multipart HTML part (PHPMailer's Body).
	 *
	 * @param object $mailer The MockPHPMailer instance.
	 * @return string
	 */
	private function html_body( $mailer ) {
		return (string) $mailer->Body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API property.
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
	 * forget_preferences() evicts the static cache entry so the next
	 * get_or_create_preferences() call re-queries the database.
	 *
	 * Rollback-safety contract for callers that wrap the get-or-create
	 * call in a transaction: after ROLLBACK the in-memory cache entry
	 * still points at the now-vanished row, so the catch path must
	 * evict it to keep cache and DB consistent. This test simulates
	 * that workflow: prime the cache, drop the underlying DB row
	 * (standing in for the rollback), then assert that the next read
	 * either re-queries the DB (creating a new row) or returns the
	 * fresh row rather than the stale cached one.
	 */
	public function test_forget_preferences_evicts_cache_so_next_read_requeries_db() {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;

		// Prime cache via first call (also inserts the default row).
		$prefs1 = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertIsObject( $prefs1 );

		// Second call hits cache — same instance.
		$prefs2 = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertSame( $prefs1, $prefs2, 'Sanity: cache should be primed before eviction.' );

		// Simulate a transaction rollback by deleting the underlying row
		// directly. The cache entry still points at $prefs1 until we
		// explicitly evict.
		$wpdb->delete( $table, array( 'user_id' => self::$user_id ), array( '%d' ) );

		// Without eviction, get_or_create_preferences() would still
		// return the cached (now-phantom) object referencing a missing
		// row. After forget_preferences(), the next call must re-query
		// the DB — finding no row, it re-creates the default row.
		Orbit_Notifier::forget_preferences( self::$user_id );

		$prefs3 = Orbit_Notifier::get_or_create_preferences( self::$user_id );
		$this->assertIsObject( $prefs3 );
		$this->assertNotSame( $prefs1, $prefs3, 'Eviction must force a DB requery — new object instance expected.' );

		// And the row exists again (re-created from defaults), confirming
		// the eviction-then-reread path went through the INSERT branch.
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				self::$user_id
			)
		);
		$this->assertSame( 1, $row_count );
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

		$fired         = 0;
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

	/**
	 * The immediate activity email a real subscriber receives is well-formed:
	 * addressed to the subscriber, subject "{poster}: {title}", body carries
	 * the activity title plus Respond and Unsubscribe links, and the RFC 8058
	 * one-click unsubscribe headers are present so Gmail/Yahoo accept it.
	 *
	 * Unlike the sent/failed observability tests above, this one lets wp_mail()
	 * run through WordPress's MockPHPMailer so we inspect the actual rendered
	 * message rather than short-circuiting delivery. This is the pre-launch
	 * guard against shipping a broken or headerless email to trial users.
	 */
	public function test_immediate_email_is_well_formed_with_unsubscribe_headers() {
		reset_phpmailer_instance();

		$profile_id  = $this->create_profile_with_owner( 'email-shape-poster' );
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id'  => $profile_id,
				'tier'        => 2,
				'title'       => 'Saturday morning bike ride',
				'description' => 'Meet at the fountain.',
			)
		);
		$this->assertIsInt( $activity_id );

		$this->insert_approved_subscription( self::$user_id, $profile_id );

		$result = Orbit_Notifier::send_immediate_email( self::$user_id, $activity_id );
		$this->assertTrue( $result, 'send_immediate_email() should report success under MockPHPMailer.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotFalse( $sent, 'Exactly one email must have been handed to the mailer.' );

		// Addressed to the subscriber.
		$subscriber = get_userdata( self::$user_id );
		$this->assertSame( $subscriber->user_email, $sent->to[0][0] );

		// Subject is "{poster}: {title}".
		$this->assertSame( 'Poster: Saturday morning bike ride', $sent->subject );

		// Multipart: the message advertises an HTML part.
		$this->assertStringContainsString( 'multipart/alternative', $sent->header );

		// Plaintext AltBody keeps the warm copy: opener, activity, RSVP
		// invitation + link, the "silence is fine" reassurance, and the
		// unsubscribe link inline.
		$this->assertStringContainsString( 'just shared something on Perihelion', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Saturday morning bike ride', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Want in?', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( '/activity/', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Saying nothing is always a fine answer', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Unsubscribe:', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( '/unsubscribe/?token=', $this->alt_body( $mailer ) );

		// Branded HTML part: wordmark, activity title, the Respond button
		// carrying the action link, and the footer unsubscribe link.
		$this->assertStringContainsString( 'Perihelion', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Century Gothic', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Saturday morning bike ride', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Respond', $this->html_body( $mailer ) );
		$this->assertStringContainsString( '/activity/', $this->html_body( $mailer ) );
		$this->assertStringContainsString( '/unsubscribe/?token=', $this->html_body( $mailer ) );

		// RFC 8058 one-click unsubscribe headers (Gmail/Yahoo 2026 bulk-sender
		// rules) survive the switch to a text/html Content-Type.
		$this->assertStringContainsString( 'List-Unsubscribe:', $sent->header );
		$this->assertStringContainsString(
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			$sent->header
		);
	}

	/**
	 * Helper: create a profile with a specific display name so digest
	 * grouping can be asserted against a known poster header.
	 *
	 * @param string $slug         Profile slug.
	 * @param string $display_name Poster display name.
	 * @return int Profile ID.
	 */
	private function create_named_profile( $slug, $display_name ) {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		return Orbit_Profile::create(
			array(
				'user_id'          => $owner_id,
				'slug'             => $slug,
				'display_name'     => $display_name,
				'require_approval' => false,
			)
		);
	}

	/**
	 * Helper: insert a queued digest log row for a user/activity so
	 * send_digest() picks it up.
	 *
	 * @param int $user_id     Subscriber user ID.
	 * @param int $activity_id Activity ID.
	 */
	private function queue_digest_item( $user_id, $activity_id ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG,
			array(
				'user_id'     => absint( $user_id ),
				'activity_id' => absint( $activity_id ),
				'method'      => 'digest',
				'status'      => 'queued',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * The daily digest a subscriber receives is warm and well-formed:
	 * a subject naming who posted and how many, the "here's what the people
	 * you follow are up to" opener, a clean per-poster header (no `--- Name ---`
	 * ASCII dividers), each queued item's title and tier label, the
	 * "silence is a complete answer" closer, the manage-subscriptions link,
	 * and the RFC 8058 one-click unsubscribe headers.
	 *
	 * Runs wp_mail() through MockPHPMailer so the actual rendered digest is
	 * inspected rather than short-circuited.
	 */
	public function test_digest_email_is_warm_and_well_formed() {
		reset_phpmailer_instance();

		// Two posters so grouping-by-poster is exercised.
		$profile_a = $this->create_named_profile( 'digest-poster-a', 'Ada' );
		$profile_b = $this->create_named_profile( 'digest-poster-b', 'Grace' );
		$this->assertIsInt( $profile_a );
		$this->assertIsInt( $profile_b );

		$activity_a1 = Orbit_Activity::create(
			array(
				'profile_id' => $profile_a,
				'tier'       => 2,
				'title'      => 'Sunday farmers market',
				'date_time'  => '2026-08-01 09:00:00',
			)
		);
		$activity_a2 = Orbit_Activity::create(
			array(
				'profile_id'    => $profile_a,
				'tier'          => 1,
				'title'         => 'Evening board games',
				'location_name' => 'The Corner Cafe',
			)
		);
		$activity_b1 = Orbit_Activity::create(
			array(
				'profile_id' => $profile_b,
				'tier'       => 3,
				'title'      => 'Weekend hiking trip',
			)
		);
		$this->assertIsInt( $activity_a1 );
		$this->assertIsInt( $activity_a2 );
		$this->assertIsInt( $activity_b1 );

		$this->insert_approved_subscription( self::$user_id, $profile_a );
		$this->insert_approved_subscription( self::$user_id, $profile_b );

		$this->queue_digest_item( self::$user_id, $activity_a1 );
		$this->queue_digest_item( self::$user_id, $activity_a2 );
		$this->queue_digest_item( self::$user_id, $activity_b1 );

		$result = Orbit_Notifier::send_digest( self::$user_id );
		$this->assertTrue( $result, 'send_digest() should report success under MockPHPMailer.' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotFalse( $sent, 'Exactly one digest email must have been handed to the mailer.' );

		// Addressed to the subscriber.
		$subscriber = get_userdata( self::$user_id );
		$this->assertSame( $subscriber->user_email, $sent->to[0][0] );

		// Subject names who posted and how many, e.g. "Grace and Ada posted
		// 3 activities". Poster order follows the tier-desc digest ordering,
		// so assert on the parts rather than a fixed order.
		$this->assertStringContainsString( 'Ada', $sent->subject );
		$this->assertStringContainsString( 'Grace', $sent->subject );
		$this->assertStringContainsString( '3 activities', $sent->subject );

		// Multipart: the digest advertises an HTML part.
		$this->assertStringContainsString( 'multipart/alternative', $sent->header );

		$tier_labels = Orbit_Activity::get_tier_labels();

		// Plaintext AltBody: warm opener, per-poster headers, each item's
		// title + tier label, warm closer, manage link — and no ASCII divider.
		$this->assertStringContainsString(
			"Here's what the people you follow are up to",
			$this->alt_body( $mailer )
		);
		$this->assertStringContainsString( 'Ada', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Grace', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Sunday farmers market', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Evening board games', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Weekend hiking trip', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( $tier_labels[2], $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'silence is a complete answer', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Manage your subscriptions:', $this->alt_body( $mailer ) );
		$this->assertStringNotContainsString( '--- Ada ---', $this->alt_body( $mailer ) );
		$this->assertStringNotContainsString( '--- Grace ---', $this->alt_body( $mailer ) );

		// Branded HTML part: wordmark, both poster headings, each activity as a
		// tappable title linking to its page, the warm closer, and the
		// manage-subscriptions footer.
		$this->assertStringContainsString( 'Perihelion', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Century Gothic', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Ada', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Grace', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Sunday farmers market', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Weekend hiking trip', $this->html_body( $mailer ) );
		$this->assertStringContainsString( '/activity/', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'silence is a complete answer', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Manage your subscriptions', $this->html_body( $mailer ) );

		// RFC 8058 one-click unsubscribe headers survive the rewrite.
		$this->assertStringContainsString( 'List-Unsubscribe:', $sent->header );
		$this->assertStringContainsString(
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			$sent->header
		);
	}

	/**
	 * cleanup_pending_phones() reaps unverified pending-phone meta older
	 * than the configured max age and leaves fresh rows in place.
	 *
	 * Default max age is 30 days; we seed one stale row (35 days old) and
	 * one fresh row (1 day old) and assert only the stale pair is gone
	 * after the GC runs.
	 */
	public function test_cleanup_pending_phones_purges_only_stale_rows() {
		$stale_user_id = self::factory()->user->create();
		$fresh_user_id = self::factory()->user->create();

		// Stale: 35 days old — beyond the 30-day default.
		update_user_meta( $stale_user_id, 'orbit_phone_pending', '+15550000001' );
		update_user_meta( $stale_user_id, 'orbit_phone_pending_at', time() - ( 35 * DAY_IN_SECONDS ) );

		// Fresh: 1 day old.
		update_user_meta( $fresh_user_id, 'orbit_phone_pending', '+15550000002' );
		update_user_meta( $fresh_user_id, 'orbit_phone_pending_at', time() - DAY_IN_SECONDS );

		$reaped = Orbit_Notifier::cleanup_pending_phones();

		$this->assertSame( 1, $reaped, 'Exactly one stale row should be reaped.' );

		// Stale user: both keys gone.
		$this->assertSame( '', (string) get_user_meta( $stale_user_id, 'orbit_phone_pending', true ) );
		$this->assertSame( '', (string) get_user_meta( $stale_user_id, 'orbit_phone_pending_at', true ) );

		// Fresh user: both keys preserved.
		$this->assertSame( '+15550000002', get_user_meta( $fresh_user_id, 'orbit_phone_pending', true ) );
		$this->assertNotSame( '', (string) get_user_meta( $fresh_user_id, 'orbit_phone_pending_at', true ) );
	}

	/**
	 * The `orbit_pending_phone_max_age` filter overrides the 30-day
	 * default — a 1-second max age should reap a row that's only an
	 * hour old.
	 */
	public function test_cleanup_pending_phones_honors_max_age_filter() {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, 'orbit_phone_pending', '+15550000003' );
		update_user_meta( $user_id, 'orbit_phone_pending_at', time() - HOUR_IN_SECONDS );

		add_filter(
			'orbit_pending_phone_max_age',
			static function () {
				return 1;
			}
		);

		$reaped = Orbit_Notifier::cleanup_pending_phones();

		remove_all_filters( 'orbit_pending_phone_max_age' );

		$this->assertSame( 1, $reaped );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'orbit_phone_pending', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'orbit_phone_pending_at', true ) );
	}
}
