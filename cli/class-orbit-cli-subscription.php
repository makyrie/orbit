<?php
/**
 * WP-CLI subscription commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage subscriptions.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Subscription extends Orbit_CLI {

	/**
	 * List subscriptions.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * [--profile_id=<id>]
	 * [--status=<status>]
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
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'    => isset( $assoc_args['user_id'] ) ? $assoc_args['user_id'] : null,
				'profile_id' => isset( $assoc_args['profile_id'] ) ? $assoc_args['profile_id'] : null,
				'status'     => isset( $assoc_args['status'] ) ? $assoc_args['status'] : null,
			)
		);

		self::output_items( $subscriptions, $assoc_args, array( 'id', 'user_id', 'profile_id', 'status', 'visibility_default', 'created_at' ) );
	}

	/**
	 * Approve a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function approve( $args, $assoc_args ) {
		$result = Orbit_Subscription::approve( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription approved.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Deny a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function deny( $args, $assoc_args ) {
		$result = Orbit_Subscription::deny( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription denied.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Remove a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function remove( $args, $assoc_args ) {
		$result = Orbit_Subscription::remove( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription removed.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Create a subscription (subscribe a user to a profile).
	 *
	 * ## OPTIONS
	 *
	 * --user_id=<id>
	 * : Subscriber's WordPress user ID.
	 *
	 * --profile_id=<id>
	 * : Profile ID to subscribe to.
	 *
	 * [--connection_note=<note>]
	 * : Optional connection note.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function create( $args, $assoc_args ) {
		$result = Orbit_Subscription::subscribe(
			array(
				'user_id'         => $assoc_args['user_id'],
				'profile_id'      => $assoc_args['profile_id'],
				'connection_note' => isset( $assoc_args['connection_note'] ) ? $assoc_args['connection_note'] : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		Orbit_Notifier::get_or_create_preferences( $assoc_args['user_id'] );

		WP_CLI::success( "Subscription created (ID: {$result})." );
		self::output_item( Orbit_Subscription::get( $result ), $assoc_args );
	}

	/**
	 * Unsubscribe (subscriber-initiated opt-out).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function unsubscribe( $args, $assoc_args ) {
		$result = Orbit_Subscription::unsubscribe( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Unsubscribed.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}
}
