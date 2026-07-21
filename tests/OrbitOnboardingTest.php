<?php
/**
 * Tests for the post-signup onboarding handoff and the "Create your profile"
 * copy parity fixes (issues #46 and #47).
 *
 * Issue #46 — after a poster creates their profile, the REST create sets a
 * one-time `orbit_show_welcome` flag and returns a `redirect_url` pointing at
 * the dashboard; the dashboard shortcode then renders a one-time welcome
 * callout and clears the flag.
 *
 * Issue #47 — the profile-creation form (rendered by edit_profile() when the
 * viewer has no profile yet) matches the polished edit view: sentence-case
 * heading, intro paragraph, required note, and the require-approval help text.
 *
 * @package Orbit
 */

class OrbitOnboardingTest extends WP_UnitTestCase {

	/**
	 * User ID used across tests. Created fresh per test.
	 *
	 * @var int
	 */
	private $user_id;

	public function set_up() {
		parent::set_up();

		// A freshly-signed-up user holds orbit_subscriber (see #54), which
		// carries the orbit_subscribe capability the self-service create
		// endpoint gates on — no manual cap grant needed.
		$this->user_id = self::factory()->user->create(
			array(
				'role'         => 'orbit_subscriber',
				'display_name' => 'Test Poster',
			)
		);
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Build a WP_REST_Request for POST /profiles/me with the given params.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Request
	 */
	private function create_profile_request( array $params ) {
		$request = new WP_REST_Request( 'POST', '/orbit/v1/profiles/me' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// ---------------------------------------------------------------- //
	// #46 — REST create sets welcome flag + returns dashboard redirect
	// ---------------------------------------------------------------- //

	public function test_create_own_profile_sets_welcome_flag_and_returns_redirect() {
		wp_set_current_user( $this->user_id );

		$response = Orbit_REST_Profile::create_own_profile(
			$this->create_profile_request(
				array(
					'slug'         => 'test-poster',
					'display_name' => 'Test Poster',
					'bio'          => 'Just here to test.',
				)
			)
		);

		$this->assertNotWPError( $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();

		// One-time welcome flag is set for the current user.
		$this->assertEquals( 1, (int) get_user_meta( $this->user_id, 'orbit_show_welcome', true ) );

		// Response carries the dashboard redirect + a success message so the
		// JS form handler can forward the user there.
		$this->assertArrayHasKey( 'redirect_url', (array) $data );
		$this->assertSame( home_url( '/dashboard/' ), $data->redirect_url );
		$this->assertNotEmpty( $data->message );
	}

	// ---------------------------------------------------------------- //
	// #46 — dashboard renders the callout once, then clears the flag
	// ---------------------------------------------------------------- //

	public function test_dashboard_renders_welcome_callout_when_flag_set() {
		$profile_id = Orbit_Profile::create(
			array(
				'user_id'      => $this->user_id,
				'slug'         => 'welcome-poster',
				'display_name' => 'Welcome Poster',
			)
		);
		$this->assertIsInt( $profile_id );

		$profile = Orbit_Profile::get( $profile_id );
		update_user_meta( $this->user_id, 'orbit_show_welcome', 1 );

		wp_set_current_user( $this->user_id );

		$output = Orbit_Shortcodes::dashboard( array() );

		// Heading (esc_html escapes the apostrophe), share link (with unique
		// id), and the primary CTA. The invite link is now the memorable
		// /hi/<code> URL, not the raw share token.
		$heading = esc_html( "You're all set up." );
		$this->assertStringContainsString( $heading, $output );
		$this->assertStringContainsString( 'orbit-welcome-share-link', $output );
		$this->assertStringContainsString( Orbit_Profile::share_url( $profile ), $output );
		$this->assertStringContainsString( '/hi/' . $profile->share_code, $output );
		$this->assertStringContainsString( 'Post your first activity', $output );
		$this->assertStringContainsString( esc_url( home_url( '/new-activity/' ) ), $output );

		// Flag is cleared after the first render.
		$this->assertSame( '', get_user_meta( $this->user_id, 'orbit_show_welcome', true ) );

		// Second render must NOT show the callout again.
		$second = Orbit_Shortcodes::dashboard( array() );
		$this->assertStringNotContainsString( $heading, $second );
		$this->assertStringNotContainsString( 'orbit-welcome-share-link', $second );
	}

	public function test_dashboard_omits_callout_when_flag_absent() {
		Orbit_Profile::create(
			array(
				'user_id'      => $this->user_id,
				'slug'         => 'no-flag-poster',
				'display_name' => 'No Flag Poster',
			)
		);

		wp_set_current_user( $this->user_id );

		$output = Orbit_Shortcodes::dashboard( array() );

		$this->assertStringNotContainsString( 'orbit-welcome-callout', $output );
		$this->assertStringNotContainsString( "You're all set up.", $output );
	}

	public function test_dashboard_clears_flag_even_without_profile() {
		// Flag set but the user has no profile — the callout can't be built,
		// so it must not render, but the flag should still be cleared so it
		// doesn't linger and re-fire later.
		update_user_meta( $this->user_id, 'orbit_show_welcome', 1 );

		wp_set_current_user( $this->user_id );

		$output = Orbit_Shortcodes::dashboard( array() );

		$this->assertStringNotContainsString( 'orbit-welcome-callout', $output );
		$this->assertSame( '', get_user_meta( $this->user_id, 'orbit_show_welcome', true ) );
	}

	// ---------------------------------------------------------------- //
	// #47 — create-profile form copy parity
	// ---------------------------------------------------------------- //

	public function test_create_profile_form_uses_polished_copy() {
		// A user with no profile hits edit_profile(), which delegates to the
		// (private) create_profile_form().
		wp_set_current_user( $this->user_id );

		$output = Orbit_Shortcodes::edit_profile( array() );

		// Sentence-case heading (not the old "Create Your Profile").
		$this->assertStringContainsString( 'Create your profile', $output );
		$this->assertStringNotContainsString( 'Create Your Profile', $output );

		// Intro paragraph + required note + review-each-subscriber help text.
		$this->assertStringContainsString( 'Last step. This is how you&#039;ll show up', $output );
		$this->assertStringContainsString( 'orbit-form-required-note', $output );
		$this->assertStringContainsString( 'Review each subscriber before they can see your activities', $output );

		// Live slug preview is preserved.
		$this->assertStringContainsString( 'id="orbit-slug-preview"', $output );

		// Submit button is sentence case.
		$this->assertStringContainsString( '>Create profile<', $output );
	}
}
