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
}
