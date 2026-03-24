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
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'orbit_send_immediate_notification' );
		as_unschedule_all_actions( 'orbit_send_daily_digest' );
		as_unschedule_all_actions( 'orbit_mark_past_activities' );
		as_unschedule_all_actions( 'orbit_cleanup_notification_log' );
		as_unschedule_all_actions( 'orbit_dispatch_activity_notifications' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( ORBIT_PLUGIN_FILE, 'orbit_deactivate' );

/**
 * Database upgrade mechanism.
 *
 * Compares the stored DB version against the current plugin version.
 * On mismatch, re-runs table creation (dbDelta is safe for updates)
 * and re-registers roles/capabilities.
 */
function orbit_maybe_upgrade() {
	$installed_version = get_option( 'orbit_db_version' );

	if ( $installed_version !== ORBIT_VERSION ) {
		Orbit_Activator::create_tables();
		Orbit_Roles::register();
		update_option( 'orbit_db_version', ORBIT_VERSION );
	}
}
add_action( 'plugins_loaded', 'orbit_maybe_upgrade' );

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
 * Handles the full cascade: activities, cross-user responses, cross-user
 * subscriptions, notification preferences, notification log entries,
 * phone verification records, and profile deletion.
 */
add_action( 'delete_user', array( 'Orbit_Privacy', 'cleanup_user_data' ) );
