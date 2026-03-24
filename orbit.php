<?php
/**
 * Plugin Name: Orbit
 * Description: Person-centric social activity tool. Subscribe to people, get notified about their activities, respond with lightweight going/maybe actions.
 * Version:     1.0.0
 * Author:      Orbit
 * License:     GPL-2.0-or-later
 * Text Domain: orbit
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'ORBIT_VERSION', '1.0.0' );
define( 'ORBIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORBIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORBIT_PLUGIN_FILE', __FILE__ );

/**
 * Table name constants (without $wpdb->prefix).
 */
define( 'ORBIT_TABLE_PROFILES', 'orbit_profiles' );
define( 'ORBIT_TABLE_SUBSCRIPTIONS', 'orbit_subscriptions' );
define( 'ORBIT_TABLE_ACTIVITIES', 'orbit_activities' );
define( 'ORBIT_TABLE_RESPONSES', 'orbit_responses' );
define( 'ORBIT_TABLE_NOTIFICATION_PREFERENCES', 'orbit_notification_preferences' );
define( 'ORBIT_TABLE_NOTIFICATION_LOG', 'orbit_notification_log' );
define( 'ORBIT_TABLE_PHONE_VERIFICATION', 'orbit_phone_verification' );

/**
 * Autoload dependencies via Composer.
 */
if ( file_exists( ORBIT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ORBIT_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Include plugin classes.
 */
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-activator.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-roles.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-token.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-profile.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-activity.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-subscription.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-response.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-privacy.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-twilio.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-phone-verify.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-notifier.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-api.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rate-limiter.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-routes.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-shortcodes.php';

/**
 * Register WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-profile.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-activity.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-subscription.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-subscriber.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-response.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-notification.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-status.php';

	WP_CLI::add_command( 'orbit profile', 'Orbit_CLI_Profile' );
	WP_CLI::add_command( 'orbit activity', 'Orbit_CLI_Activity' );
	WP_CLI::add_command( 'orbit subscription', 'Orbit_CLI_Subscription' );
	WP_CLI::add_command( 'orbit subscriber', 'Orbit_CLI_Subscriber' );
	WP_CLI::add_command( 'orbit response', 'Orbit_CLI_Response' );
	WP_CLI::add_command( 'orbit notification', 'Orbit_CLI_Notification' );
	WP_CLI::add_command( 'orbit status', 'Orbit_CLI_Status' );
}

/**
 * Activation hook.
 */
function orbit_activate() {
	Orbit_Activator::activate();
	Orbit_Roles::register();
	flush_rewrite_rules();
}
register_activation_hook( ORBIT_PLUGIN_FILE, 'orbit_activate' );

/**
 * Deactivation hook.
 */
function orbit_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( ORBIT_PLUGIN_FILE, 'orbit_deactivate' );

/**
 * Initialize roles on every load (ensures capabilities are current).
 */
add_action( 'init', array( 'Orbit_Roles', 'register' ) );

/**
 * Register ActionScheduler hooks and schedule recurring jobs.
 */
Orbit_Notifier::register_hooks();
add_action( 'init', array( 'Orbit_Notifier', 'schedule_recurring_jobs' ) );

/**
 * Register REST API routes.
 */
add_action( 'rest_api_init', array( 'Orbit_REST_API', 'register_routes' ) );

/**
 * Register custom routes and shortcodes.
 */
Orbit_Routes::register();
add_action( 'init', array( 'Orbit_Shortcodes', 'register' ) );

/**
 * Clean up Orbit data when a WordPress user is deleted.
 *
 * Handles the cascade defined in the spec: removes subscriptions, responses,
 * notification preferences, notification log entries, phone verification records,
 * and soft-deletes profiles (notifying subscribers).
 */
add_action(
	'delete_user',
	function ( $user_id ) {
		global $wpdb;

		$profiles_table       = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$subscriptions_table  = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$responses_table      = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$notif_prefs_table    = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;
		$notif_log_table      = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$phone_verify_table   = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;

		// Delete notification preferences.
		$wpdb->delete( $notif_prefs_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete notification log entries.
		$wpdb->delete( $notif_log_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete phone verification records.
		$wpdb->delete( $phone_verify_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete responses via subscriptions owned by this user.
		$subscription_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$subscriptions_table} WHERE user_id = %d",
				$user_id
			)
		);

		if ( ! empty( $subscription_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $subscription_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$responses_table} WHERE subscription_id IN ({$placeholders})",
					...$subscription_ids
				)
			);
		}

		// Delete subscriptions.
		$wpdb->delete( $subscriptions_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Soft-delete profile if user is a poster (delete the profile record).
		$wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Clean up usermeta.
		delete_user_meta( $user_id, 'orbit_phone' );
		delete_user_meta( $user_id, 'orbit_phone_verified' );
		delete_user_meta( $user_id, 'orbit_timezone' );
		delete_user_meta( $user_id, 'orbit_sms_opted_out' );
	}
);
