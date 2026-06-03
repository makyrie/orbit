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
	 * Unsubscribe token expiry: 1 year. Long enough that an unread email
	 * months later still works; short enough that a stolen mail spool
	 * doesn't grant indefinite unsub capability.
	 *
	 * @var int
	 */
	const UNSUBSCRIBE_TOKEN_EXPIRY = 365 * DAY_IN_SECONDS;

	/**
	 * Domain-separation string for unsubscribe tokens.
	 *
	 * Mixed into the HMAC payload so an unsubscribe token cannot be
	 * misvalidated as an action (RSVP) token and vice versa, even though
	 * both share the subscription_secret key.
	 *
	 * @var string
	 */
	const UNSUBSCRIBE_DOMAIN = 'unsubscribe';

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
	 * Format: {subscription_id}.{base64(expiry_timestamp)}:{hmac_hex}
	 *
	 * @param string      $subscription_secret The subscription's secret.
	 * @param int         $activity_id         The activity ID.
	 * @param int         $subscription_id     The subscription ID (embedded in token for O(1) lookup).
	 * @param int|null    $expiry_timestamp    Optional explicit expiry. Defaults based on activity date.
	 * @param string|null $activity_date_time  Optional MySQL UTC datetime string ('Y-m-d H:i:s') for the
	 *                                         activity. When supplied along with a null $expiry_timestamp,
	 *                                         avoids a re-query in compute_default_expiry().
	 * @return string The composite action token.
	 */
	public static function generate_action_token( $subscription_secret, $activity_id, $subscription_id, $expiry_timestamp = null, $activity_date_time = null ) {
		if ( null === $expiry_timestamp ) {
			$expiry_timestamp = self::compute_default_expiry( $activity_id, $activity_date_time );
		}

		$hmac = self::compute_hmac( $subscription_secret, $activity_id, $expiry_timestamp );

		return $subscription_id . '.' . base64_encode( (string) $expiry_timestamp ) . ':' . $hmac;
	}

	/**
	 * Validate an action token.
	 *
	 * Parses format: {subscription_id}.{base64(expiry)}:{hmac}
	 *
	 * @param string $token               The composite action token.
	 * @param string $subscription_secret  The subscription's secret.
	 * @param int    $activity_id          The activity ID.
	 * @return bool True if valid and not expired.
	 */
	public static function validate_action_token( $token, $subscription_secret, $activity_id ) {
		$dot_pos = strpos( $token, '.' );
		if ( false === $dot_pos ) {
			return false;
		}

		$remaining = substr( $token, $dot_pos + 1 );
		$parts     = explode( ':', $remaining, 2 );

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
	 * Extract the subscription ID from an action token.
	 *
	 * @param string $token The composite action token.
	 * @return int|null Subscription ID, or null if token format is invalid.
	 */
	public static function extract_subscription_id( $token ) {
		$dot_pos = strpos( $token, '.' );
		if ( false === $dot_pos ) {
			return null;
		}

		return absint( substr( $token, 0, $dot_pos ) );
	}

	/**
	 * Generate an unsubscribe token for a subscription.
	 *
	 * Format: {subscription_id}.{base64(expiry)}:{hmac_hex}
	 *
	 * Same composite shape as action tokens (for parser reuse and O(1)
	 * lookup via embedded subscription_id), but the HMAC payload includes
	 * the UNSUBSCRIBE_DOMAIN constant so an action token can never be
	 * misvalidated as an unsubscribe and vice versa.
	 *
	 * @param string   $subscription_secret The subscription secret.
	 * @param int      $subscription_id     The subscription ID.
	 * @param int|null $expiry_timestamp    Optional explicit expiry. Defaults to 1 year from now.
	 * @return string The composite unsubscribe token.
	 */
	public static function generate_unsubscribe_token( $subscription_secret, $subscription_id, $expiry_timestamp = null ) {
		if ( null === $expiry_timestamp ) {
			$expiry_timestamp = time() + self::UNSUBSCRIBE_TOKEN_EXPIRY;
		}

		$hmac = self::compute_unsubscribe_hmac( $subscription_secret, $subscription_id, $expiry_timestamp );

		return $subscription_id . '.' . base64_encode( (string) $expiry_timestamp ) . ':' . $hmac;
	}

	/**
	 * Validate an unsubscribe token.
	 *
	 * @param string $token               The composite unsubscribe token.
	 * @param string $subscription_secret The subscription secret.
	 * @param int    $subscription_id     The subscription ID.
	 * @return bool True if valid and not expired.
	 */
	public static function validate_unsubscribe_token( $token, $subscription_secret, $subscription_id ) {
		$dot_pos = strpos( $token, '.' );
		if ( false === $dot_pos ) {
			return false;
		}

		$remaining = substr( $token, $dot_pos + 1 );
		$parts     = explode( ':', $remaining, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$expiry_decoded = base64_decode( $parts[0], true );

		if ( false === $expiry_decoded ) {
			return false;
		}

		$expiry_timestamp = (int) $expiry_decoded;

		if ( time() > $expiry_timestamp ) {
			return false;
		}

		$expected_hmac = self::compute_unsubscribe_hmac( $subscription_secret, $subscription_id, $expiry_timestamp );

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
	 * Compute HMAC-SHA256 for an unsubscribe token.
	 *
	 * Domain-separated from action tokens via UNSUBSCRIBE_DOMAIN constant
	 * mixed into the HMAC payload.
	 *
	 * @param string $subscription_secret The subscription secret (key).
	 * @param int    $subscription_id     The subscription ID.
	 * @param int    $expiry_timestamp    The expiry timestamp.
	 * @return string Hex-encoded HMAC.
	 */
	private static function compute_unsubscribe_hmac( $subscription_secret, $subscription_id, $expiry_timestamp ) {
		$data = self::UNSUBSCRIBE_DOMAIN . '|' . $subscription_id . '|' . $expiry_timestamp;

		return hash_hmac( 'sha256', $data, $subscription_secret );
	}

	/**
	 * Compute default expiry for an activity.
	 *
	 * - Dated activities: 7 days after the activity date_time.
	 * - Dateless activities: 30 days from now.
	 *
	 * The stored MySQL `date_time` column is always UTC ('Y-m-d H:i:s'). We
	 * construct a DateTime with an explicit UTC timezone rather than calling
	 * strtotime(), which would otherwise interpret the string in PHP's
	 * default timezone and shift the expiry by the server's UTC offset.
	 *
	 * If the date_time cannot be parsed (corruption, NULL, etc.) we fall
	 * back to the dateless expiry — safer than continuing with a
	 * wrong-timezone interpretation.
	 *
	 * @param int         $activity_id        The activity ID.
	 * @param string|null $activity_date_time Optional pre-fetched MySQL UTC datetime
	 *                                        ('Y-m-d H:i:s'). When supplied, skips the
	 *                                        per-call DB query for the same activity.
	 * @return int Unix timestamp.
	 */
	private static function compute_default_expiry( $activity_id, $activity_date_time = null ) {
		if ( null === $activity_date_time ) {
			global $wpdb;

			$table              = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
			$activity_date_time = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT date_time FROM {$table} WHERE id = %d",
					$activity_id
				)
			);
		}

		if ( $activity_date_time ) {
			$utc = new DateTimeZone( 'UTC' );
			$dt  = DateTime::createFromFormat( 'Y-m-d H:i:s', $activity_date_time, $utc );

			if ( $dt ) {
				return $dt->getTimestamp() + self::ACTION_TOKEN_EXPIRY_DATED;
			}
		}

		return time() + self::ACTION_TOKEN_EXPIRY_DATELESS;
	}
}
