<?php
/**
 * Tests for the unsubscribe-token variant on Orbit_Token.
 *
 * @package Orbit
 */

/**
 * Class OrbitTokenUnsubscribeTest
 */
class OrbitTokenUnsubscribeTest extends WP_UnitTestCase {

	public function test_token_validates_for_correct_subscription_secret_and_id() {
		$secret           = 'a_secret_value_for_testing_token_handling';
		$subscription_id  = 42;

		$token = Orbit_Token::generate_unsubscribe_token( $secret, $subscription_id );

		$this->assertTrue( Orbit_Token::validate_unsubscribe_token( $token, $secret, $subscription_id ) );
	}

	public function test_token_fails_for_wrong_secret() {
		$token = Orbit_Token::generate_unsubscribe_token( 'right_secret', 42 );

		$this->assertFalse( Orbit_Token::validate_unsubscribe_token( $token, 'wrong_secret', 42 ) );
	}

	public function test_token_fails_for_wrong_subscription_id() {
		$secret = 'a_secret_value';
		$token  = Orbit_Token::generate_unsubscribe_token( $secret, 42 );

		$this->assertFalse( Orbit_Token::validate_unsubscribe_token( $token, $secret, 99 ) );
	}

	public function test_token_fails_after_expiry() {
		$secret = 'a_secret_value';
		// Generated with an expiry that's already in the past.
		$past  = time() - HOUR_IN_SECONDS;
		$token = Orbit_Token::generate_unsubscribe_token( $secret, 42, $past );

		$this->assertFalse( Orbit_Token::validate_unsubscribe_token( $token, $secret, 42 ) );
	}

	public function test_unsubscribe_and_action_tokens_are_not_interchangeable() {
		// Domain separation: an action token must NEVER pass unsubscribe
		// validation, and vice versa.
		$secret           = 'a_secret_value';
		$subscription_id  = 42;
		$activity_id      = 7;

		$action_token = Orbit_Token::generate_action_token( $secret, $activity_id, $subscription_id );
		$unsub_token  = Orbit_Token::generate_unsubscribe_token( $secret, $subscription_id );

		$this->assertFalse(
			Orbit_Token::validate_unsubscribe_token( $action_token, $secret, $subscription_id ),
			'An action token must not validate as an unsubscribe token'
		);
		$this->assertFalse(
			Orbit_Token::validate_action_token( $unsub_token, $secret, $activity_id ),
			'An unsubscribe token must not validate as an action token'
		);
	}

	public function test_extract_subscription_id_works_on_unsubscribe_token() {
		// Both action tokens and unsubscribe tokens embed the
		// subscription_id as a prefix for O(1) lookup. The same extract
		// helper works for both.
		$token = Orbit_Token::generate_unsubscribe_token( 'secret', 137 );

		$this->assertSame( 137, Orbit_Token::extract_subscription_id( $token ) );
	}

	/**
	 * Regression: the stored MySQL `date_time` is UTC, and compute_default_expiry()
	 * must interpret it as UTC regardless of the PHP default timezone. Previously
	 * the code used strtotime(), which interprets the bare datetime in the
	 * server-local TZ and shifts the expiry by the UTC offset.
	 *
	 * We exercise it via the new $activity_date_time parameter on
	 * generate_action_token() so the test doesn't need to insert a real
	 * activities row, and decode the expiry that was embedded in the token.
	 */
	public function test_action_token_expiry_interprets_activity_datetime_as_utc() {
		$original_tz = date_default_timezone_get();
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Intentional: simulating non-UTC server TZ for regression test.
		date_default_timezone_set( 'America/Los_Angeles' );

		try {
			$secret             = 'a_secret_value';
			$activity_id        = 7;
			$subscription_id    = 42;
			$activity_date_time = '2026-06-01 00:00:00'; // UTC midnight.

			$token = Orbit_Token::generate_action_token(
				$secret,
				$activity_id,
				$subscription_id,
				null,
				$activity_date_time
			);

			// Extract the expiry embedded in the token: {sub_id}.{base64(expiry)}:{hmac}.
			$dot_pos        = strpos( $token, '.' );
			$remaining      = substr( $token, $dot_pos + 1 );
			$parts          = explode( ':', $remaining, 2 );
			$expiry_in_token = (int) base64_decode( $parts[0], true );

			// Expected: UTC 2026-06-01 00:00:00 + 7 days.
			$expected = strtotime( '2026-06-08 00:00:00 UTC' );

			$this->assertSame( $expected, $expiry_in_token );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restoring original TZ.
			date_default_timezone_set( $original_tz );
		}
	}
}
