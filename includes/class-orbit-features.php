<?php
/**
 * Feature flag helpers for runtime gating of platform-level capabilities.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static facade for feature flag checks.
 *
 * The primary current consumer is SMS dispatch. While the messaging service
 * is awaiting A2P 10DLC approval, sms_enabled() returns false and the
 * notifier's resolve_notification_method() coerces every sms-preferring
 * subscriber to email. After approval, ops flips the option and SMS
 * resumes for users whose stored preference is sms — no data migration.
 */
class Orbit_Features {

	/**
	 * Option name controlling whether SMS delivery is active.
	 *
	 * Stored in wp_options as '0' or '1'. Default is '0' so a fresh install
	 * never sends SMS until ops opts in. The constant ORBIT_SMS_ENABLED is a
	 * hard override: when defined and falsy, SMS is disabled regardless of
	 * the option — useful for compliance freezes that ops must not lift via
	 * the admin UI.
	 */
	const OPTION_SMS_ENABLED = 'orbit_sms_enabled';

	/**
	 * Whether subscriber-notification SMS is currently enabled.
	 *
	 * Read path:
	 * - If ORBIT_SMS_ENABLED constant is defined and falsy → return false
	 *   (compliance-freeze hard override; cannot be lifted via WP-CLI).
	 * - Otherwise return ( option === '1' ).
	 *
	 * Note: this gates *subscriber* SMS only. Operational SMS (phone
	 * verification codes, STOP/HELP TwiML replies) routes through
	 * Orbit_Twilio and Orbit_Phone_Verify directly and is not gated.
	 *
	 * @return bool True if subscriber-notification SMS may be dispatched.
	 */
	public static function sms_enabled() {
		if ( defined( 'ORBIT_SMS_ENABLED' ) && ! ORBIT_SMS_ENABLED ) {
			return false;
		}

		return '1' === get_option( self::OPTION_SMS_ENABLED, '0' );
	}
}
