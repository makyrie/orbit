<?php
/**
 * WP-CLI status command.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * System status overview.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Status extends Orbit_CLI {

	/**
	 * Show system status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp orbit status
	 *     $ wp orbit status --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$profiles_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES
		);

		$active_activities = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES . " WHERE status = 'active'"
		);

		$total_activities = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES
		);

		$approved_subscriptions = Orbit_Subscription::count( array( 'status' => 'approved' ) );
		$pending_subscriptions  = Orbit_Subscription::count( array( 'status' => 'pending' ) );

		$total_responses = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES
		);

		$twilio_configured = defined( 'ORBIT_TWILIO_SID' ) && defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) && defined( 'ORBIT_TWILIO_FROM' );
		$action_scheduler  = function_exists( 'as_has_scheduled_action' );

		// Recent notification stats.
		$log_table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$today     = gmdate( 'Y-m-d 00:00:00' );

		$today_sms = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE method = 'sms' AND created_at >= %s",
				$today
			)
		);

		$today_email = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE method = 'email' AND created_at >= %s",
				$today
			)
		);

		$failed_recent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE status = 'failed' AND created_at >= %s",
				$today
			)
		);

		$status = (object) array(
			'version'                 => ORBIT_VERSION,
			'profiles'                => $profiles_count,
			'activities_total'        => $total_activities,
			'activities_active'       => $active_activities,
			'subscriptions_approved'  => $approved_subscriptions,
			'subscriptions_pending'   => $pending_subscriptions,
			'responses'               => $total_responses,
			'today_sms_sent'          => $today_sms,
			'today_email_sent'        => $today_email,
			'today_failed'            => $failed_recent,
			'twilio_configured'       => $twilio_configured,
			'action_scheduler_active' => $action_scheduler,
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
		} else {
			$items = array();
			foreach ( get_object_vars( $status ) as $key => $value ) {
				$items[] = (object) array(
					'key'   => $key,
					'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value,
				);
			}
			self::output_items( $items, $assoc_args, array( 'key', 'value' ) );
		}
	}
}
