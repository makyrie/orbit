<?php
/**
 * WP-CLI base command registration.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Orbit — person-centric social activity tool.
 *
 * ## EXAMPLES
 *
 *     # Check system status.
 *     $ wp orbit status
 *
 *     # Create a poster profile.
 *     $ wp orbit profile create --user_id=1 --slug=sarah-k --display_name="Sarah K"
 *
 *     # List activities for a profile.
 *     $ wp orbit activity list --profile_id=1 --format=json
 *
 * @when after_wp_load
 */
class Orbit_CLI extends WP_CLI_Command {

	/**
	 * Output a single item in the specified format.
	 *
	 * @param object $item   The item to display.
	 * @param array  $assoc_args Associative args (must contain --format).
	 */
	protected static function output_item( $item, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $item, JSON_PRETTY_PRINT ) );
		} elseif ( 'table' === $format || 'csv' === $format ) {
			$fields    = array_keys( get_object_vars( $item ) );
			$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
			$formatter->display_items( array( $item ) );
		} else {
			WP_CLI::line( wp_json_encode( $item, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Output a list of items in the specified format.
	 *
	 * @param array $items      Array of objects.
	 * @param array $assoc_args Associative args (must contain --format).
	 * @param array $fields     Fields to display.
	 */
	protected static function output_items( $items, $assoc_args, $fields = array() ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::line( 'No items found.' );
			return;
		}

		if ( empty( $fields ) && ! empty( $items ) ) {
			$first  = is_array( $items ) ? reset( $items ) : $items;
			$fields = is_object( $first ) ? array_keys( get_object_vars( $first ) ) : array();
		}

		$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
		$formatter->display_items( $items );
	}
}
