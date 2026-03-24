<?php
/**
 * WP-CLI activity commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage activities.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Activity extends Orbit_CLI {

	/**
	 * Create an activity.
	 *
	 * ## OPTIONS
	 *
	 * --profile_id=<id>
	 * : Poster's profile ID.
	 *
	 * --tier=<tier>
	 * : Commitment tier (1, 2, or 3).
	 *
	 * --title=<title>
	 * : Activity title.
	 *
	 * [--description=<desc>]
	 * : Optional description.
	 *
	 * [--location_name=<name>]
	 * : Location name.
	 *
	 * [--location_address=<addr>]
	 * : Location address.
	 *
	 * [--date_time=<datetime>]
	 * : UTC datetime (Y-m-d H:i:s).
	 *
	 * [--date_flexible]
	 * : Mark date as flexible.
	 *
	 * [--show_attendees=<mode>]
	 * : none, count, or names. Default: count.
	 *
	 * [--notify]
	 * : Dispatch notifications after creation. Default: true.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function create( $args, $assoc_args ) {
		$activity_id = Orbit_Activity::create(
			array(
				'profile_id'       => $assoc_args['profile_id'],
				'tier'             => $assoc_args['tier'],
				'title'            => $assoc_args['title'],
				'description'      => isset( $assoc_args['description'] ) ? $assoc_args['description'] : null,
				'location_name'    => isset( $assoc_args['location_name'] ) ? $assoc_args['location_name'] : null,
				'location_address' => isset( $assoc_args['location_address'] ) ? $assoc_args['location_address'] : null,
				'date_time'        => isset( $assoc_args['date_time'] ) ? $assoc_args['date_time'] : null,
				'date_flexible'    => isset( $assoc_args['date_flexible'] ),
				'show_attendees'   => isset( $assoc_args['show_attendees'] ) ? $assoc_args['show_attendees'] : 'count',
			)
		);

		if ( is_wp_error( $activity_id ) ) {
			WP_CLI::error( $activity_id->get_error_message() );
		}

		// Dispatch notifications unless --no-notify.
		$notify = ! isset( $assoc_args['notify'] ) || $assoc_args['notify'];
		if ( $notify ) {
			Orbit_Notifier::dispatch_for_activity( $activity_id );
			WP_CLI::log( 'Notifications dispatched.' );
		}

		WP_CLI::success( "Activity created (ID: {$activity_id})." );
		self::output_item( Orbit_Activity::get( $activity_id ), $assoc_args );
	}

	/**
	 * Get an activity by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Activity ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function get( $args, $assoc_args ) {
		$activity = Orbit_Activity::get( absint( $args[0] ) );

		if ( ! $activity ) {
			WP_CLI::error( 'Activity not found.' );
		}

		self::output_item( $activity, $assoc_args );
	}

	/**
	 * Update an activity.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Activity ID.
	 *
	 * [--title=<title>]
	 * [--description=<desc>]
	 * [--tier=<tier>]
	 * [--location_name=<name>]
	 * [--location_address=<addr>]
	 * [--date_time=<datetime>]
	 * [--date_flexible=<bool>]
	 * [--show_attendees=<mode>]
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function update( $args, $assoc_args ) {
		$id         = absint( $args[0] );
		$update_args = $assoc_args;

		unset( $update_args['format'] );

		$result = Orbit_Activity::update( $id, $update_args );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Activity updated.' );
		self::output_item( Orbit_Activity::get( $id ), $assoc_args );
	}

	/**
	 * Cancel an activity.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Activity ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cancel( $args, $assoc_args ) {
		$result = Orbit_Activity::cancel( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Activity cancelled.' );
		self::output_item( Orbit_Activity::get( absint( $args[0] ) ), $assoc_args );
	}

	/**
	 * List activities.
	 *
	 * ## OPTIONS
	 *
	 * [--profile_id=<id>]
	 * [--status=<status>]
	 * [--tier=<tier>]
	 * [--after=<datetime>]
	 * [--before=<datetime>]
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function list_( $args, $assoc_args ) {
		$activities = Orbit_Activity::list(
			array(
				'profile_id' => isset( $assoc_args['profile_id'] ) ? $assoc_args['profile_id'] : null,
				'status'     => isset( $assoc_args['status'] ) ? $assoc_args['status'] : null,
				'tier'       => isset( $assoc_args['tier'] ) ? $assoc_args['tier'] : null,
				'after'      => isset( $assoc_args['after'] ) ? $assoc_args['after'] : null,
				'before'     => isset( $assoc_args['before'] ) ? $assoc_args['before'] : null,
			)
		);

		self::output_items( $activities, $assoc_args, array( 'id', 'profile_id', 'tier', 'title', 'status', 'date_time', 'created_at' ) );
	}

	/**
	 * List responses for an activity.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Activity ID.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function responses( $args, $assoc_args ) {
		$responses = Orbit_Response::list_by_activity( absint( $args[0] ) );

		self::output_items( $responses, $assoc_args, array( 'id', 'activity_id', 'subscription_id', 'response', 'visibility_override', 'created_at' ) );
	}
}
