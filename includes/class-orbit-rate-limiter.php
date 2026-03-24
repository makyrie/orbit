<?php
/**
 * Rate limiting via WordPress transients.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Rate_Limiter
 */
class Orbit_Rate_Limiter {

	/**
	 * Check if an action is rate-limited.
	 *
	 * @param string $action     Action identifier (e.g., 'subscribe', 'verify_phone').
	 * @param string $identifier Unique identifier (e.g., IP address, phone number).
	 * @param int    $max_count  Maximum allowed actions in the window.
	 * @param int    $window     Time window in seconds.
	 * @return bool True if the action is allowed (not rate-limited).
	 */
	public static function check( $action, $identifier, $max_count, $window ) {
		$key   = self::get_key( $action, $identifier );
		$count = (int) get_transient( $key );

		return $count < $max_count;
	}

	/**
	 * Record an action for rate limiting.
	 *
	 * @param string $action     Action identifier.
	 * @param string $identifier Unique identifier.
	 * @param int    $window     Time window in seconds.
	 */
	public static function record( $action, $identifier, $window ) {
		$key   = self::get_key( $action, $identifier );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, $window );
	}

	/**
	 * Check and record in one step. Returns false if rate-limited.
	 *
	 * @param string $action     Action identifier.
	 * @param string $identifier Unique identifier.
	 * @param int    $max_count  Maximum allowed actions.
	 * @param int    $window     Time window in seconds.
	 * @return bool True if allowed, false if rate-limited.
	 */
	public static function attempt( $action, $identifier, $max_count, $window ) {
		if ( ! self::check( $action, $identifier, $max_count, $window ) ) {
			return false;
		}

		self::record( $action, $identifier, $window );

		return true;
	}

	/**
	 * Generate a transient key.
	 *
	 * @param string $action     Action identifier.
	 * @param string $identifier Unique identifier.
	 * @return string Transient key.
	 */
	private static function get_key( $action, $identifier ) {
		return 'orbit_rl_' . md5( $action . '|' . $identifier );
	}
}
