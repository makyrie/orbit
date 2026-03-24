<?php
/**
 * WP-CLI response commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage activity responses.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Response extends Orbit_CLI {

	/**
	 * Set a response (idempotent create/update).
	 *
	 * ## OPTIONS
	 *
	 * --activity_id=<id>
	 * : Activity ID.
	 *
	 * --subscription_id=<id>
	 * : Subscription ID.
	 *
	 * --response=<response>
	 * : going or maybe.
	 *
	 * [--visibility_override=<vis>]
	 * : anonymous, visible, or default.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function set( $args, $assoc_args ) {
		$result = Orbit_Response::set(
			array(
				'activity_id'        => $assoc_args['activity_id'],
				'subscription_id'    => $assoc_args['subscription_id'],
				'response'           => $assoc_args['response'],
				'visibility_override' => isset( $assoc_args['visibility_override'] ) ? $assoc_args['visibility_override'] : 'default',
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Response set (ID: {$result})." );
	}

	/**
	 * Remove a response.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Response ID.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function remove( $args, $assoc_args ) {
		$result = Orbit_Response::remove( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Response removed.' );
	}

	/**
	 * List responses for a user.
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
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function list_( $args, $assoc_args ) {
		$responses = Orbit_Response::list_by_user( absint( $args[0] ) );

		self::output_items( $responses, $assoc_args, array( 'id', 'activity_id', 'subscription_id', 'response', 'visibility_override', 'created_at' ) );
	}
}
