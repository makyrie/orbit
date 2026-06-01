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
}
