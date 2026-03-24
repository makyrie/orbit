<?php
/**
 * WP-CLI subscriber commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage subscriber users.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Subscriber extends Orbit_CLI {

	/**
	 * List a user's subscriptions.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function subscriptions( $args, $assoc_args ) {
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => absint( $args[0] ),
				'per_page' => 100,
			)
		);

		self::output_items( $subscriptions, $assoc_args, array( 'id', 'profile_id', 'status', 'visibility_default', 'created_at' ) );
	}

	/**
	 * Get subscriber details.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function get( $args, $assoc_args ) {
		$user_id = absint( $args[0] );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$prefs = Orbit_Notifier::get_or_create_preferences( $user_id );

		$data = (object) array(
			'user_id'             => $user_id,
			'display_name'        => $user->display_name,
			'email'               => $user->user_email,
			'phone'               => get_user_meta( $user_id, 'orbit_phone', true ),
			'phone_verified'      => (bool) get_user_meta( $user_id, 'orbit_phone_verified', true ),
			'timezone'            => get_user_meta( $user_id, 'orbit_timezone', true ),
			'sms_opted_out'       => (bool) get_user_meta( $user_id, 'orbit_sms_opted_out', true ),
			'tier1_method'        => $prefs->tier1_method,
			'tier2_method'        => $prefs->tier2_method,
			'tier3_method'        => $prefs->tier3_method,
			'sms_daily_cap'       => $prefs->sms_daily_cap,
			'digest_time'         => $prefs->digest_time,
			'subscription_count'  => Orbit_Subscription::count( array( 'user_id' => $user_id, 'status' => 'approved' ) ),
		);

		self::output_item( $data, $assoc_args );
	}

	/**
	 * Set notification preferences.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--tier1_method=<method>]
	 * [--tier2_method=<method>]
	 * [--tier3_method=<method>]
	 * [--sms_daily_cap=<cap>]
	 * [--digest_time=<time>]
	 * [--format=<format>]
	 *
	 * @subcommand set-preferences
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function set_preferences( $args, $assoc_args ) {
		$user_id = absint( $args[0] );
		$prefs   = $assoc_args;

		unset( $prefs['format'] );

		$result = Orbit_Notifier::update_preferences( $user_id, $prefs );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Preferences updated.' );
		self::output_item( Orbit_Notifier::get_or_create_preferences( $user_id ), $assoc_args );
	}

	/**
	 * Upgrade a user to poster role.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * @subcommand set-role
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function set_role( $args, $assoc_args ) {
		$user_id = absint( $args[0] );

		Orbit_Roles::upgrade_to_poster( $user_id );

		WP_CLI::success( "User {$user_id} upgraded to poster role." );
	}
}
