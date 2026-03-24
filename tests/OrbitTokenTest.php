<?php
/**
 * Tests for Orbit_Token.
 *
 * @package Orbit
 */

class OrbitTokenTest extends WP_UnitTestCase {

	/**
	 * Test random token generation returns 32-char alphanumeric string.
	 */
	public function test_generate_random_returns_32_chars() {
		$token = Orbit_Token::generate_random();

		$this->assertSame( 32, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]+$/', $token );
	}

	/**
	 * Test that two generated random tokens are unique.
	 */
	public function test_generate_random_returns_unique_tokens() {
		$token1 = Orbit_Token::generate_random();
		$token2 = Orbit_Token::generate_random();

		$this->assertNotSame( $token1, $token2 );
	}

	/**
	 * Test action token generation produces expected format.
	 */
	public function test_generate_action_token_format() {
		$token = Orbit_Token::generate_action_token( 'test_secret', 1, 42, time() + 3600 );

		// Format: {subscription_id}.{base64(expiry)}:{hmac}
		$this->assertStringContainsString( '.', $token );
		$this->assertStringContainsString( ':', $token );

		// Subscription ID should be the prefix.
		$dot_pos = strpos( $token, '.' );
		$this->assertSame( '42', substr( $token, 0, $dot_pos ) );
	}

	/**
	 * Test action token validates successfully with correct inputs.
	 */
	public function test_validate_action_token_success() {
		$secret      = 'my_subscription_secret';
		$activity_id = 1;
		$sub_id      = 99;
		$expiry      = time() + 3600;

		$token = Orbit_Token::generate_action_token( $secret, $activity_id, $sub_id, $expiry );
		$valid = Orbit_Token::validate_action_token( $token, $secret, $activity_id );

		$this->assertTrue( $valid );
	}

	/**
	 * Test action token fails with wrong secret.
	 */
	public function test_validate_action_token_wrong_secret() {
		$token = Orbit_Token::generate_action_token( 'correct_secret', 1, 42, time() + 3600 );
		$valid = Orbit_Token::validate_action_token( $token, 'wrong_secret', 1 );

		$this->assertFalse( $valid );
	}

	/**
	 * Test action token fails with wrong activity ID.
	 */
	public function test_validate_action_token_wrong_activity() {
		$token = Orbit_Token::generate_action_token( 'secret', 1, 42, time() + 3600 );
		$valid = Orbit_Token::validate_action_token( $token, 'secret', 999 );

		$this->assertFalse( $valid );
	}

	/**
	 * Test expired action token is rejected.
	 */
	public function test_validate_action_token_expired() {
		$token = Orbit_Token::generate_action_token( 'secret', 1, 42, time() - 1 );
		$valid = Orbit_Token::validate_action_token( $token, 'secret', 1 );

		$this->assertFalse( $valid );
	}

	/**
	 * Test malformed token is rejected.
	 */
	public function test_validate_action_token_malformed() {
		$this->assertFalse( Orbit_Token::validate_action_token( 'garbage', 'secret', 1 ) );
		$this->assertFalse( Orbit_Token::validate_action_token( '', 'secret', 1 ) );
		$this->assertFalse( Orbit_Token::validate_action_token( 'no-dot-here:hmac', 'secret', 1 ) );
	}

	/**
	 * Test extract_subscription_id returns correct ID.
	 */
	public function test_extract_subscription_id() {
		$token = Orbit_Token::generate_action_token( 'secret', 1, 42, time() + 3600 );

		$this->assertSame( 42, Orbit_Token::extract_subscription_id( $token ) );
	}

	/**
	 * Test extract_subscription_id returns null for malformed token.
	 */
	public function test_extract_subscription_id_malformed() {
		$this->assertNull( Orbit_Token::extract_subscription_id( 'no_dot' ) );
		$this->assertNull( Orbit_Token::extract_subscription_id( '' ) );
	}
}
