<?php
/**
 * Tests for Orbit_Emails — the transactional lifecycle emails
 * (welcome, subscription-approved, new-subscriber).
 *
 * These let wp_mail() run through WordPress's MockPHPMailer so the actual
 * rendered message is inspected, rather than short-circuiting delivery.
 *
 * @package Orbit
 */

class OrbitEmailsTest extends WP_UnitTestCase {

	/**
	 * Clean up Orbit tables between tests so subscription/profile fixtures
	 * created here don't leak.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES );

		remove_all_filters( 'orbit_email_welcome_poster_body' );
		remove_all_filters( 'orbit_email_welcome_subscriber_body' );
		remove_all_filters( 'orbit_email_subscription_approved_body' );
		remove_all_filters( 'orbit_email_new_subscriber_body' );

		parent::tear_down();
	}

	/**
	 * Helper: create a poster user + their profile.
	 *
	 * @param string $slug         Profile slug.
	 * @param string $display_name Poster display name.
	 * @return object The created profile row.
	 */
	private function create_poster_profile( $slug = 'poster-a', $display_name = 'Alex Poster' ) {
		$owner_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_poster',
				'display_name' => $display_name,
				'user_email'   => $slug . '-owner@example.test',
			)
		);

		$profile_id = Orbit_Profile::create(
			array(
				'user_id'          => $owner_id,
				'slug'             => $slug,
				'display_name'     => $display_name,
				'require_approval' => true,
			)
		);

		return Orbit_Profile::get( $profile_id );
	}

	// ---------------------------------------------------------------- //
	// #45 — Welcome email
	// ---------------------------------------------------------------- //

	/**
	 * Poster welcome: branded subject, the poster copy, and a working
	 * set-your-password link addressed to the right user.
	 */
	public function test_welcome_poster_is_well_formed() {
		reset_phpmailer_instance();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber', // Signup gives WP core subscriber, not orbit_subscriber.
				'display_name' => 'Pat Poster',
				'user_login'   => 'patposter',
				'user_email'   => 'pat@example.test',
			)
		);

		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );

		$sent = $mailer->get_sent();
		$this->assertSame( 'pat@example.test', $sent->to[0][0] );
		$this->assertSame( 'Welcome to Perihelion', $sent->subject );
		$this->assertStringContainsString( 'Hi Pat Poster,', $sent->body );
		$this->assertStringContainsString( 'friends you already have', $sent->body );

		// A working set-your-password link addressed to this user.
		$this->assertStringContainsString( 'wp-login.php?action=rp', $sent->body );
		$this->assertStringContainsString( 'login=patposter', $sent->body );
	}

	/**
	 * Subscriber welcome with poster context: subscriber wording plus the
	 * poster's display name appear.
	 */
	public function test_welcome_subscriber_names_poster_when_context_present() {
		reset_phpmailer_instance();

		$profile = $this->create_poster_profile( 'named-poster', 'Jordan Rivers' );

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Sam Subscriber',
				'user_login'   => 'samsub',
				'user_email'   => 'sam@example.test',
			)
		);

		Orbit_User_Notifications::send_new_user_notification( $user_id, (int) $profile->id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );

		$sent = $mailer->get_sent();
		$this->assertSame( 'Welcome to Perihelion', $sent->subject );
		$this->assertStringContainsString( 'Hi Sam Subscriber,', $sent->body );
		$this->assertStringContainsString( "Jordan Rivers's plans", $sent->body );
		$this->assertStringContainsString( 'Jordan Rivers will get your request and approve it', $sent->body );
		$this->assertStringContainsString( 'wp-login.php?action=rp', $sent->body );
	}

	/**
	 * Subscriber welcome without poster context: poster-agnostic fallback
	 * wording is used and no poster name leaks in.
	 */
	public function test_welcome_subscriber_uses_fallback_without_poster_context() {
		reset_phpmailer_instance();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Casey Sub',
				'user_login'   => 'caseysub',
				'user_email'   => 'casey@example.test',
			)
		);

		// No poster profile ID threaded.
		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );

		$sent = $mailer->get_sent();
		$this->assertStringContainsString( 'hear about the people you follow', $sent->body );
		$this->assertStringContainsString( "They'll get your request and approve it", $sent->body );
	}

	/**
	 * The welcome body copy is overridable via its apply_filters() hook.
	 */
	public function test_welcome_poster_body_is_filterable() {
		reset_phpmailer_instance();

		add_filter(
			'orbit_email_welcome_poster_body',
			static function () {
				return 'CUSTOM POSTER BODY';
			}
		);

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'filterposter',
				'user_email' => 'filter@example.test',
			)
		);

		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'CUSTOM POSTER BODY', trim( $sent->body ) );
	}

	// ---------------------------------------------------------------- //
	// #43 — Approval email
	// ---------------------------------------------------------------- //

	/**
	 * Approving a pending subscription DEFERS the subscriber email via
	 * ActionScheduler (nothing sent inline), scheduling the job with the
	 * subscription ID.
	 */
	public function test_approval_defers_subscriber_email() {
		$profile = $this->create_poster_profile( 'approve-poster', 'Robin Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Lee Subscriber',
				'user_email'   => 'lee@example.test',
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $subscriber_id,
				'profile_id' => (int) $profile->id,
			)
		);
		$this->assertIsInt( $sub_id );

		// Reset AFTER creating the pending subscription so the poster's
		// deferred new-request job doesn't interfere here.
		reset_phpmailer_instance();

		$result = Orbit_Subscription::approve( $sub_id );
		$this->assertTrue( $result );

		// Nothing sent inline — the send is deferred.
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent, 'Approval email must be deferred, not sent inline.' );

		// The AS job is scheduled with the subscription ID.
		$this->assertTrue(
			as_has_scheduled_action(
				Orbit_Emails::HOOK_SEND_APPROVED,
				array( 'subscription_id' => (int) $sub_id ),
				'orbit'
			),
			'Expected orbit_send_subscription_approved to be scheduled.'
		);
	}

	/**
	 * Running the deferred approval job emails exactly one message to the
	 * subscriber, with the poster name in the subject and the dashboard link
	 * + closing reassurance in the body.
	 */
	public function test_approval_job_emails_subscriber() {
		$profile = $this->create_poster_profile( 'approve-poster-2', 'Robin Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Lee Subscriber',
				'user_email'   => 'lee@example.test',
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $subscriber_id,
				'profile_id' => (int) $profile->id,
			)
		);
		$this->assertIsInt( $sub_id );
		Orbit_Subscription::approve( $sub_id );

		reset_phpmailer_instance();

		// Invoke the AS callback directly.
		Orbit_Emails::dispatch_subscription_approved( $sub_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent, 'Exactly one email should be sent by the approval job.' );

		$sent = $mailer->get_sent();
		$this->assertSame( 'lee@example.test', $sent->to[0][0] );
		$this->assertSame( 'Robin Poster approved you on Perihelion', $sent->subject );
		$this->assertStringContainsString( 'Hi Lee Subscriber,', $sent->body );
		$this->assertStringContainsString( home_url( '/dashboard/' ), $sent->body );
		$this->assertStringContainsString( 'saying nothing is a complete answer', $sent->body );
	}

	/**
	 * Denial must NOT email the subscriber and must NOT schedule an approval
	 * job (denial copy is deferred).
	 */
	public function test_deny_does_not_email_subscriber() {
		$profile = $this->create_poster_profile( 'deny-poster', 'Morgan Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'       => 'orbit_subscriber',
				'user_email' => 'deny-sub@example.test',
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $subscriber_id,
				'profile_id' => (int) $profile->id,
			)
		);
		$this->assertIsInt( $sub_id );

		reset_phpmailer_instance();

		$result = Orbit_Subscription::deny( $sub_id );
		$this->assertTrue( $result );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent, 'Denial must not send any email.' );
		$this->assertFalse(
			as_has_scheduled_action(
				Orbit_Emails::HOOK_SEND_APPROVED,
				array( 'subscription_id' => (int) $sub_id ),
				'orbit'
			),
			'Denial must not schedule an approval email.'
		);
	}

	// ---------------------------------------------------------------- //
	// #44 — New-subscriber request email
	// ---------------------------------------------------------------- //

	/**
	 * A pending subscribe() DEFERS the poster email via ActionScheduler
	 * (nothing sent inline) and schedules the job with the subscription ID.
	 */
	public function test_new_request_defers_poster_email() {
		$profile = $this->create_poster_profile( 'req-poster', 'Dana Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Kai Subscriber',
				'user_email'   => 'kai@example.test',
			)
		);

		reset_phpmailer_instance();

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'         => $subscriber_id,
				'profile_id'      => (int) $profile->id,
				'connection_note' => 'We met at the climbing gym.',
			)
		);
		$this->assertIsInt( $sub_id );

		// Nothing sent inline — the send is deferred.
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 0, $mailer->mock_sent, 'New-subscriber email must be deferred, not sent inline.' );

		$this->assertTrue(
			as_has_scheduled_action(
				Orbit_Emails::HOOK_SEND_NEW_SUBSCRIBER,
				array( 'subscription_id' => (int) $sub_id ),
				'orbit'
			),
			'Expected orbit_send_new_subscriber to be scheduled.'
		);
	}

	/**
	 * Running the deferred new-subscriber job emails the poster, with the
	 * subscriber's name in the subject, the /subscribers/ link, and the
	 * connection note line.
	 */
	public function test_new_request_job_emails_poster_with_note() {
		$profile = $this->create_poster_profile( 'req-poster-note', 'Dana Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Kai Subscriber',
				'user_email'   => 'kai@example.test',
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'         => $subscriber_id,
				'profile_id'      => (int) $profile->id,
				'connection_note' => 'We met at the climbing gym.',
			)
		);
		$this->assertIsInt( $sub_id );

		reset_phpmailer_instance();

		// Invoke the AS callback directly.
		Orbit_Emails::dispatch_new_subscriber( $sub_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent, 'Exactly one email should be sent to the poster.' );

		$sent = $mailer->get_sent();
		// Addressed to the poster (the profile owner).
		$poster = get_userdata( (int) $profile->user_id );
		$this->assertSame( $poster->user_email, $sent->to[0][0] );
		$this->assertSame( 'Kai Subscriber would like to follow your plans', $sent->subject );
		$this->assertStringContainsString( 'Hi Dana Poster,', $sent->body );
		$this->assertStringContainsString( home_url( '/subscribers/' ), $sent->body );
		$this->assertStringContainsString( 'They added: "We met at the climbing gym."', $sent->body );
	}

	/**
	 * The deferred new-subscriber job with no connection note omits the
	 * "They added" line entirely.
	 */
	public function test_new_request_job_omits_note_line_without_note() {
		$profile = $this->create_poster_profile( 'req-poster-2', 'Quinn Poster' );

		$subscriber_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Nico Subscriber',
				'user_email'   => 'nico@example.test',
			)
		);

		$sub_id = Orbit_Subscription::subscribe(
			array(
				'user_id'    => $subscriber_id,
				'profile_id' => (int) $profile->id,
			)
		);
		$this->assertIsInt( $sub_id );

		reset_phpmailer_instance();

		Orbit_Emails::dispatch_new_subscriber( $sub_id );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringNotContainsString( 'They added:', $sent->body );
		$this->assertStringContainsString( 'Nico Subscriber asked to subscribe', $sent->body );
	}
}
