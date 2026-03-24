<?php
/**
 * Token generation and validation.
 *
 * Handles three token types:
 * - Share tokens: random 32-char strings for subscription links.
 * - Subscription secrets: random 32-char strings, stable per subscription.
 * - Action tokens: HMAC-SHA256, activity-scoped, time-limited.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Token
 */
class Orbit_Token {

	/**
	 * Default action token expiry: 7 days in seconds.
	 *
	 * @var int
	 */
	const ACTION_TOKEN_EXPIRY_DATED = 7 * DAY_IN_SECONDS;

	/**
	 * Expiry for dateless activities: 30 days in seconds.
	 *
	 * @var int
	 */
	const ACTION_TOKEN_EXPIRY_DATELESS = 30 * DAY_IN_SECONDS;

	/**
	 * Generate a random token for share tokens or subscription secrets.
	 *
	 * @return string 32-character alphanumeric string.
	 */
	public static function generate_random() {
		return wp_generate_password( 32, false );
	}

	/**
	 * Generate an action token for a specific activity and subscription.
	 *
	 * Format: {base64(expiry_timestamp)}:{hmac_hex}
	 *
	 * @param string   $subscription_secret The subscription's secret.
	 * @param int      $activity_id         The activity ID.
	 * @param int|null $expiry_timestamp    Optional explicit expiry. Defaults based on activity date.
	 * @return string The composite action token.
	 */
	public static function generate_action_token( $subscription_secret, $activity_id, $expiry_timestamp = null ) {
		if ( null === $expiry_timestamp ) {
			$expiry_timestamp = self::compute_default_expiry( $activity_id );
		}

		$hmac = self::compute_hmac( $subscription_secret, $activity_id, $expiry_timestamp );

		return base64_encode( (string) $expiry_timestamp ) . ':' . $hmac;
	}

	/**
	 * Validate an action token.
	 *
	 * @param string $token               The composite action token.
	 * @param string $subscription_secret  The subscription's secret.
	 * @param int    $activity_id          The activity ID.
	 * @return bool True if valid and not expired.
	 */
	public static function validate_action_token( $token, $subscription_secret, $activity_id ) {
		$parts = explode( ':', $token, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$expiry_decoded = base64_decode( $parts[0], true );

		if ( false === $expiry_decoded ) {
			return false;
		}

		$expiry_timestamp = (int) $expiry_decoded;

		// Check expiry.
		if ( time() > $expiry_timestamp ) {
			return false;
		}

		// Recompute and compare HMAC.
		$expected_hmac = self::compute_hmac( $subscription_secret, $activity_id, $expiry_timestamp );

		return hash_equals( $expected_hmac, $parts[1] );
	}

	/**
	 * Compute HMAC-SHA256 for an action token.
	 *
	 * @param string $subscription_secret The subscription secret (key).
	 * @param int    $activity_id         The activity ID.
	 * @param int    $expiry_timestamp    The expiry timestamp.
	 * @return string Hex-encoded HMAC.
	 */
	private static function compute_hmac( $subscription_secret, $activity_id, $expiry_timestamp ) {
		$data = $activity_id . '|' . $expiry_timestamp;

		return hash_hmac( 'sha256', $data, $subscription_secret );
	}

	/**
	 * Compute default expiry for an activity.
	 *
	 * - Dated activities: 7 days after the activity date_time.
	 * - Dateless activities: 30 days from now.
	 *
	 * @param int $activity_id The activity ID.
	 * @return int Unix timestamp.
	 */
	private static function compute_default_expiry( $activity_id ) {
		global $wpdb;

		$table     = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$date_time = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT date_time FROM {$table} WHERE id = %d",
				$activity_id
			)
		);

		if ( $date_time ) {
			return strtotime( $date_time ) + self::ACTION_TOKEN_EXPIRY_DATED;
		}

		return time() + self::ACTION_TOKEN_EXPIRY_DATELESS;
	}
}
