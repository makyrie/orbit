<?php
/**
 * Tests for Orbit_Mail — SendGrid tracking suppression.
 *
 * @package Orbit
 */

class OrbitMailTest extends WP_UnitTestCase {

	/**
	 * Remove any inline filter added by a test.
	 */
	public function tear_down() {
		remove_all_filters( 'orbit_disable_sendgrid_tracking' );
		parent::tear_down();
	}

	/**
	 * SendGrid sends get tracking_settings with click + open tracking disabled.
	 */
	public function test_sendgrid_body_gets_tracking_disabled() {
		$body = array( 'personalizations' => array(), 'content' => array() );

		$out = Orbit_Mail::disable_sendgrid_tracking( $body, 'sendgrid' );

		$this->assertArrayHasKey( 'tracking_settings', $out );
		$this->assertFalse( $out['tracking_settings']['click_tracking']['enable'] );
		$this->assertFalse( $out['tracking_settings']['click_tracking']['enable_text'] );
		$this->assertFalse( $out['tracking_settings']['open_tracking']['enable'] );
		// Original keys preserved.
		$this->assertArrayHasKey( 'personalizations', $out );
	}

	/**
	 * Non-SendGrid mailers are left completely untouched.
	 */
	public function test_other_mailer_body_untouched() {
		$body = array( 'foo' => 'bar' );

		$this->assertSame( $body, Orbit_Mail::disable_sendgrid_tracking( $body, 'smtp' ) );
		$this->assertSame( $body, Orbit_Mail::disable_sendgrid_tracking( $body, 'mailgun' ) );
	}

	/**
	 * A non-array body (already-encoded, or an unexpected shape) is passed
	 * through untouched rather than fataling.
	 */
	public function test_non_array_body_untouched() {
		$json = '{"already":"encoded"}';

		$this->assertSame( $json, Orbit_Mail::disable_sendgrid_tracking( $json, 'sendgrid' ) );
	}

	/**
	 * The `orbit_disable_sendgrid_tracking` filter can opt out.
	 */
	public function test_filter_can_reenable_tracking() {
		add_filter( 'orbit_disable_sendgrid_tracking', '__return_false' );

		$body = array( 'content' => array() );
		$out  = Orbit_Mail::disable_sendgrid_tracking( $body, 'sendgrid' );

		$this->assertArrayNotHasKey( 'tracking_settings', $out );
		$this->assertSame( $body, $out );
	}
}
