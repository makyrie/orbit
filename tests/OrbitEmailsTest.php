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
		remove_all_filters( 'orbit_email_welcome_poster_html' );
		remove_all_filters( 'orbit_email_welcome_subscriber_html' );
		remove_all_filters( 'orbit_email_subscription_approved_html' );
		remove_all_filters( 'orbit_email_new_subscriber_html' );
		remove_all_filters( 'orbit_email_footer_html' );

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

	/**
	 * Assert an action is enqueued as an ASYNC job — one that runs via a
	 * background loopback request within seconds — rather than a delayed
	 * single action that waits for the next system-cron tick.
	 *
	 * ActionScheduler tags async actions with an ActionScheduler_NullSchedule
	 * (no scheduled date), whereas a `time()`-scheduled single action carries
	 * a concrete ActionScheduler_SimpleSchedule. Asserting on the schedule
	 * type locks in the fast-dispatch intent, not just "the job exists".
	 *
	 * @param string $hook The action hook.
	 * @param array  $args The action arguments.
	 */
	private function assert_action_enqueued_async( $hook, array $args ) {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'args'   => $args,
				'group'  => 'orbit',
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		$this->assertNotEmpty(
			$actions,
			"Expected a pending {$hook} action to be enqueued."
		);

		$action = reset( $actions );
		$this->assertInstanceOf(
			'ActionScheduler_NullSchedule',
			$action->get_schedule(),
			"Expected {$hook} to be dispatched as an async action (runs within seconds), not a delayed scheduled action."
		);
	}

	// ---------------------------------------------------------------- //
	// #45 — Welcome email
	// ---------------------------------------------------------------- //

	/**
	 * Poster welcome: branded subject, the poster copy, and a working
	 * set-your-password link addressed to the right user.
	 *
	 * The recipient holds `orbit_subscriber` (what signup now assigns, see
	 * #54) yet still gets the POSTER welcome, because routing keys on the
	 * absence of poster context — not on role.
	 */
	public function test_welcome_poster_is_well_formed() {
		reset_phpmailer_instance();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber', // Signup role; no poster context ⇒ poster welcome.
				'display_name' => 'Pat Poster',
				'user_login'   => 'patposter',
				'user_email'   => 'pat@example.test',
			)
		);

		// No poster profile ID threaded (signup-style).
		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );

		$sent = $mailer->get_sent();
		$this->assertSame( 'pat@example.test', $sent->to[0][0] );
		$this->assertSame( 'Welcome to Perihelion', $sent->subject );

		// Multipart: the message advertises an HTML part.
		$this->assertStringContainsString( 'multipart/alternative', $sent->header );

		// Plaintext AltBody keeps the warm copy with the link inline.
		$this->assertStringContainsString( 'Hi Pat Poster,', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'friends you already have', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'wp-login.php?action=rp', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'login=patposter', $this->alt_body( $mailer ) );

		// Branded HTML part: the serif wordmark and the set-password button
		// carrying the same reset link (URL hidden in the button only).
		$this->assertStringContainsString( 'Perihelion', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Century Gothic', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Set your password', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'wp-login.php?action=rp', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'login=patposter', $this->html_body( $mailer ) );
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

		// Plaintext AltBody names the poster.
		$this->assertStringContainsString( 'Hi Sam Subscriber,', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( "Jordan Rivers's plans", $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Jordan Rivers will get your request and approve it', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'wp-login.php?action=rp', $this->alt_body( $mailer ) );

		// HTML part names the poster and carries the set-password button.
		// (The apostrophe in "Rivers's" is HTML-escaped in the HTML part.)
		$this->assertStringContainsString( 'Jordan Rivers', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Set your password', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'wp-login.php?action=rp', $this->html_body( $mailer ) );
	}

	/**
	 * Subscriber welcome with poster context but an unresolvable profile:
	 * the subscriber branch is still taken (poster_profile_id > 0), and the
	 * poster-agnostic fallback wording is used with no poster name leaking in.
	 */
	public function test_welcome_subscriber_uses_fallback_when_profile_missing() {
		reset_phpmailer_instance();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Casey Sub',
				'user_login'   => 'caseysub',
				'user_email'   => 'casey@example.test',
			)
		);

		// A poster profile ID that doesn't resolve to a profile (e.g. the
		// poster's profile was deleted between subscribe and send): routing
		// still picks the subscriber welcome, which then falls back to
		// poster-agnostic wording.
		Orbit_User_Notifications::send_new_user_notification( $user_id, 999999 );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );

		$sent = $mailer->get_sent();
		$this->assertStringContainsString( 'hear about the people you follow', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( "They'll get your request and approve it", $this->alt_body( $mailer ) );

		// HTML falls back to the poster-agnostic wording too. (The apostrophe
		// in "They'll" is HTML-escaped in the HTML part.)
		$this->assertStringContainsString( 'the people you follow', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'get your request and approve it', $this->html_body( $mailer ) );
	}

	/**
	 * A signup-style user (role `orbit_subscriber`, no poster context) gets
	 * the POSTER welcome — role does NOT route the copy. Guards against a
	 * regression of #45 now that signup users are also `orbit_subscriber`.
	 */
	public function test_welcome_orbit_subscriber_without_context_gets_poster_copy() {
		reset_phpmailer_instance();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Robin Signup',
				'user_login'   => 'robinsignup',
				'user_email'   => 'robin-signup@example.test',
			)
		);

		// No poster profile ID: signup (poster onboarding).
		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		// Poster copy, not subscriber copy — in both parts.
		$this->assertStringContainsString( 'friends you already have', $this->alt_body( $mailer ) );
		$this->assertStringNotContainsString( 'hear about', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'friends you already have', $this->html_body( $mailer ) );
		$this->assertStringNotContainsString( 'hear about', $this->html_body( $mailer ) );
	}

	/**
	 * The welcome PLAINTEXT body copy is overridable via its apply_filters()
	 * hook — the override lands in the multipart AltBody. The HTML part is
	 * built independently and is unaffected by the plaintext filter.
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
				'role'       => 'orbit_subscriber', // Signup role; no poster context ⇒ poster welcome.
				'user_login' => 'filterposter',
				'user_email' => 'filter@example.test',
			)
		);

		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'CUSTOM POSTER BODY', trim( $this->alt_body( $mailer ) ) );

		// The HTML part still renders the branded template (not the override).
		$this->assertStringContainsString( 'Set your password', $this->html_body( $mailer ) );
	}

	/**
	 * The welcome HTML body copy is overridable via its own apply_filters()
	 * hook — the override lands in the multipart HTML Body, leaving the
	 * plaintext AltBody untouched.
	 */
	public function test_welcome_poster_html_is_filterable() {
		reset_phpmailer_instance();

		add_filter(
			'orbit_email_welcome_poster_html',
			static function () {
				return '<p>CUSTOM POSTER HTML</p>';
			}
		);

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'orbit_subscriber',
				'user_login' => 'filterposterhtml',
				'user_email' => 'filter-html@example.test',
			)
		);

		Orbit_User_Notifications::send_new_user_notification( $user_id );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertStringContainsString( 'CUSTOM POSTER HTML', $this->html_body( $mailer ) );
		// Plaintext AltBody keeps the default warm copy.
		$this->assertStringContainsString( 'friends you already have', $this->alt_body( $mailer ) );
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

		// ...and enqueued ASYNC so it dispatches within seconds, not on the
		// next ~15-minute system-cron tick.
		$this->assert_action_enqueued_async(
			Orbit_Emails::HOOK_SEND_APPROVED,
			array( 'subscription_id' => (int) $sub_id )
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
		$this->assertStringContainsString( 'multipart/alternative', $sent->header );

		// Plaintext AltBody: warm copy with the dashboard URL inline.
		$this->assertStringContainsString( 'Hi Lee Subscriber,', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( home_url( '/dashboard/' ), $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'saying nothing is a complete answer', $this->alt_body( $mailer ) );

		// HTML part: the dashboard button carries the link, wordmark present.
		$this->assertStringContainsString( 'Perihelion', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Go to your dashboard', $this->html_body( $mailer ) );
		$this->assertStringContainsString( home_url( '/dashboard/' ), $this->html_body( $mailer ) );
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

		// ...and enqueued ASYNC so it dispatches within seconds, not on the
		// next ~15-minute system-cron tick.
		$this->assert_action_enqueued_async(
			Orbit_Emails::HOOK_SEND_NEW_SUBSCRIBER,
			array( 'subscription_id' => (int) $sub_id )
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

		// Plaintext AltBody: warm copy, /subscribers/ link inline, note line.
		$this->assertStringContainsString( 'Hi Dana Poster,', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( home_url( '/subscribers/' ), $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'They added: "We met at the climbing gym."', $this->alt_body( $mailer ) );

		// HTML part: the review button carries the link, note rendered.
		$this->assertStringContainsString( 'Review the request', $this->html_body( $mailer ) );
		$this->assertStringContainsString( home_url( '/subscribers/' ), $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'We met at the climbing gym.', $this->html_body( $mailer ) );
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

		$mailer = tests_retrieve_phpmailer_instance();
		// No note line in either part.
		$this->assertStringNotContainsString( 'They added:', $this->alt_body( $mailer ) );
		$this->assertStringNotContainsString( 'They added:', $this->html_body( $mailer ) );
		$this->assertStringContainsString( 'Nico Subscriber asked to subscribe', $this->alt_body( $mailer ) );
		$this->assertStringContainsString( 'Nico Subscriber asked to subscribe', $this->html_body( $mailer ) );
	}
}
