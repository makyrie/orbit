<?php
/**
 * REST API coordinator.
 *
 * Delegates route registration to resource-based controllers
 * and provides shared permission callbacks.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_API
 */
class Orbit_REST_API {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'orbit/v1';

	/**
	 * Register all REST API routes by delegating to controllers.
	 */
	public static function register_routes() {
		Orbit_REST_Subscription::register_routes();
		Orbit_REST_Activity::register_routes();
		Orbit_REST_Profile::register_routes();
		Orbit_REST_Notification::register_routes();
		Orbit_REST_Signup::register_routes();
	}

	/**
	 * Check if the current user is an admin.
	 *
	 * @return bool True if admin.
	 */
	public static function is_admin() {
		return is_user_logged_in() && current_user_can( 'orbit_admin' );
	}
}
