<?php
/**
 * Phone verification flow.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Phone_Verify
 */
class Orbit_Phone_Verify {

	/**
	 * Code expiry in seconds (10 minutes).
	 *
	 * @var int
	 */
	const CODE_EXPIRY = 600;

	/**
	 * Max verification attempts per code.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Max code requests per phone per hour.
	 *
	 * @var int
	 */
	const MAX_REQUESTS_PER_HOUR = 3;

	/**
	 * Max code requests per user per hour (across all phones).
	 *
	 * @var int
	 */
	const MAX_REQUESTS_PER_USER_PER_HOUR = 5;

	/**
	 * Send a verification code to a phone number.
	 *
	 * The submitted phone number is stored as a *candidate* in the
	 * `wp_orbit_phone_verification` row only — the user's `orbit_phone`
	 * user_meta is NOT touched here. The candidate phone is promoted to
	 * `orbit_phone` (and `orbit_phone_verified` is set to 1) only after
	 * `verify_code()` confirms the code with `hash_equals()`. This means a
	 * previously verified phone number remains the user's bound phone for
	 * all incoming Twilio webhooks (STOP/START, etc.) until the new number
	 * is verified, and abandoning the verification flow leaves the prior
	 * verified state intact.
	 *
	 * Side-effect ordering:
	 *   1. Validate E.164 format.
	 *   2. Per-phone rate limit (MAX_REQUESTS_PER_HOUR).
	 *   3. Per-user rate limit (MAX_REQUESTS_PER_USER_PER_HOUR) — guards
	 *      against a single account pivoting across many target numbers
	 *      to drive Twilio cost or send spam.
	 *   4. Send the SMS via Twilio FIRST. On failure, return the WP_Error
	 *      WITHOUT writing any database state — no phantom verification
	 *      rows accumulate when Twilio is unavailable or misconfigured.
	 *   5. On Twilio success, insert the verification row containing the
	 *      candidate phone, code, and expiry.
	 *
	 * @param int    $user_id User ID.
	 * @param string $phone   Candidate phone number in E.164 format.
	 * @return true|WP_Error True on success; WP_Error with codes
	 *                       `invalid_phone`, `rate_limited`, or any
	 *                       error returned by Orbit_Twilio::send_sms().
	 */
	public static function send_code( $user_id, $phone ) {
		global $wpdb;

		$phone = sanitize_text_field( $phone );

		// Validate E.164 format.
		if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			return new WP_Error( 'invalid_phone', __( 'Phone number must be in E.164 format (e.g., +15551234567).', 'orbit' ) );
		}

		$table        = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// Rate limit: per-phone (MAX_REQUESTS_PER_HOUR per hour).
		$recent_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE phone = %s AND created_at > %s",
				$phone,
				$one_hour_ago
			)
		);

		if ( $recent_count >= self::MAX_REQUESTS_PER_HOUR ) {
			return new WP_Error( 'rate_limited', __( 'Too many verification requests. Please try again later.', 'orbit' ) );
		}

		// Rate limit: per-user (MAX_REQUESTS_PER_USER_PER_HOUR per hour
		// across all phone numbers). Prevents a single account from
		// pivoting across many target numbers to drive Twilio cost.
		$user_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at > %s",
				$user_id,
				$one_hour_ago
			)
		);

		if ( $user_count >= self::MAX_REQUESTS_PER_USER_PER_HOUR ) {
			return new WP_Error( 'rate_limited', __( 'Too many verification requests. Please try again later.', 'orbit' ) );
		}

		// Generate 6-digit code.
		$code       = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CODE_EXPIRY );
		$now        = current_time( 'mysql', true );

		// Send SMS FIRST. If Twilio fails, do not persist anything — this
		// avoids accumulating phantom rows that count against the per-phone
		// rate limit while delivering zero codes to the user.
		$message = sprintf(
			/* translators: %s: verification code */
			__( 'Your Orbit verification code is: %s', 'orbit' ),
			$code
		);

		$sms_result = Orbit_Twilio::send_sms( $phone, $message );

		if ( is_wp_error( $sms_result ) ) {
			return $sms_result;
		}

		// SMS dispatched — now persist the verification row. The candidate
		// phone lives in this row only; it is promoted to user_meta in
		// verify_code() upon successful confirmation.
		$wpdb->insert(
			$table,
			array(
				'user_id'    => absint( $user_id ),
				'phone'      => $phone,
				'code'       => $code,
				'attempts'   => 0,
				'expires_at' => $expires_at,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Verify a code submitted by the user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    The 6-digit code.
	 * @return true|WP_Error True on success.
	 */
	public static function verify_code( $user_id, $code ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$now   = current_time( 'mysql', true );

		// Get the most recent unexpired code for this user.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND expires_at > %s ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$now
			)
		);

		if ( ! $record ) {
			return new WP_Error( 'no_pending_code', __( 'No pending verification code found. Please request a new one.', 'orbit' ) );
		}

		// Check attempts.
		if ( $record->attempts >= self::MAX_ATTEMPTS ) {
			return new WP_Error( 'max_attempts', __( 'Maximum verification attempts exceeded. Please request a new code.', 'orbit' ) );
		}

		// Increment attempts.
		$wpdb->update(
			$table,
			array( 'attempts' => $record->attempts + 1 ),
			array( 'id' => $record->id ),
			array( '%d' ),
			array( '%d' )
		);

		// Compare code.
		if ( ! hash_equals( $record->code, $code ) ) {
			return new WP_Error( 'invalid_code', __( 'Invalid verification code.', 'orbit' ) );
		}

		// Success — promote the candidate phone (stored on the verification
		// row) to the user's bound `orbit_phone` and flag it verified. This
		// is the only place either user_meta key is written during the
		// verification flow.
		$candidate_phone = isset( $record->phone ) ? sanitize_text_field( $record->phone ) : '';

		if ( '' !== $candidate_phone ) {
			update_user_meta( $user_id, 'orbit_phone', $candidate_phone );
		}
		update_user_meta( $user_id, 'orbit_phone_verified', 1 );

		// Clean up verification records for this user.
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		return true;
	}

	/**
	 * Delete verification rows whose `expires_at` is older than 7 days.
	 *
	 * Called from a daily ActionScheduler job
	 * (see Orbit_Notifier::HOOK_CLEANUP_VERIFY) so plaintext phone numbers
	 * from failed/abandoned verification attempts do not accumulate in the
	 * database indefinitely.
	 *
	 * @return int|false Rows deleted, or false on database error.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s",
				$cutoff
			)
		);
	}
}
