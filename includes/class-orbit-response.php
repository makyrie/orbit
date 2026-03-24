<?php
/**
 * Response handling.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Response
 */
class Orbit_Response {

	/**
	 * Valid response values.
	 *
	 * @var array
	 */
	const VALID_RESPONSES = array( 'going', 'maybe' );

	/**
	 * Valid visibility override values.
	 *
	 * @var array
	 */
	const VALID_VISIBILITY_OVERRIDES = array( 'anonymous', 'visible', 'default' );

	/**
	 * Set a response (upsert — create or update).
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE on (activity_id, subscription_id).
	 *
	 * @param array $args {
	 *     @type int    $activity_id        Required. Activity ID.
	 *     @type int    $subscription_id    Required. Subscription ID.
	 *     @type string $response           Required. 'going' or 'maybe'.
	 *     @type string $visibility_override Optional. Default 'default'.
	 * }
	 * @return int|WP_Error Response ID on success, WP_Error on failure.
	 */
	public static function set( $args ) {
		global $wpdb;

		if ( empty( $args['activity_id'] ) || empty( $args['subscription_id'] ) || empty( $args['response'] ) ) {
			return new WP_Error( 'missing_required', __( 'activity_id, subscription_id, and response are required.', 'orbit' ) );
		}

		$response = $args['response'];
		if ( ! in_array( $response, self::VALID_RESPONSES, true ) ) {
			return new WP_Error( 'invalid_response', __( 'Response must be going or maybe.', 'orbit' ) );
		}

		$visibility_override = isset( $args['visibility_override'] ) ? $args['visibility_override'] : 'default';
		if ( ! in_array( $visibility_override, self::VALID_VISIBILITY_OVERRIDES, true ) ) {
			return new WP_Error( 'invalid_visibility', __( 'Visibility override must be anonymous, visible, or default.', 'orbit' ) );
		}

		$activity_id     = absint( $args['activity_id'] );
		$subscription_id = absint( $args['subscription_id'] );

		// Validate subscription is approved.
		$subscription = Orbit_Subscription::get( $subscription_id );
		if ( ! $subscription || 'approved' !== $subscription->status ) {
			return new WP_Error( 'subscription_not_approved', __( 'Subscription must be approved to respond.', 'orbit' ) );
		}

		// Validate activity is active.
		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found.', 'orbit' ) );
		}

		if ( 'cancelled' === $activity->status ) {
			return new WP_Error( 'activity_cancelled', __( 'Cannot respond to a cancelled activity.', 'orbit' ) );
		}

		// Validate activity belongs to the profile the subscriber is subscribed to.
		if ( (int) $activity->profile_id !== (int) $subscription->profile_id ) {
			return new WP_Error( 'activity_profile_mismatch', __( 'Activity does not belong to the subscribed profile.', 'orbit' ) );
		}

		$table = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$now   = current_time( 'mysql', true );

		// Use INSERT ... ON DUPLICATE KEY UPDATE for idempotent upsert.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (activity_id, subscription_id, response, visibility_override, created_at, updated_at)
			VALUES (%d, %d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE response = VALUES(response), visibility_override = VALUES(visibility_override), updated_at = VALUES(updated_at)",
			$activity_id,
			$subscription_id,
			$response,
			$visibility_override,
			$now,
			$now
		);

		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to set response.', 'orbit' ) );
		}

		// Return the response ID (either new insert or existing).
		$response_row = self::get_by_activity_and_subscription( $activity_id, $subscription_id );

		return $response_row ? (int) $response_row->id : 0;
	}

	/**
	 * Remove a response.
	 *
	 * @param int $id Response ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function remove( $id ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to remove response.', 'orbit' ) );
		}

		return true;
	}

	/**
	 * Get a response by activity and subscription.
	 *
	 * @param int $activity_id     Activity ID.
	 * @param int $subscription_id Subscription ID.
	 * @return object|null Response row or null.
	 */
	public static function get_by_activity_and_subscription( $activity_id, $subscription_id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_RESPONSES;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE activity_id = %d AND subscription_id = %d",
				$activity_id,
				$subscription_id
			)
		);
	}

	/**
	 * List responses for an activity.
	 *
	 * @param int $activity_id Activity ID.
	 * @return array Array of response rows.
	 */
	public static function list_by_activity( $activity_id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_RESPONSES;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE activity_id = %d ORDER BY created_at ASC",
				$activity_id
			)
		);
	}

	/**
	 * List responses for a user (across all activities).
	 *
	 * @param int $user_id User ID.
	 * @return array Array of response rows joined with subscription data.
	 */
	public static function list_by_user( $user_id ) {
		global $wpdb;

		$responses_table     = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$subscriptions_table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM {$responses_table} r
				INNER JOIN {$subscriptions_table} s ON r.subscription_id = s.id
				WHERE s.user_id = %d
				ORDER BY r.created_at DESC",
				$user_id
			)
		);
	}

	/**
	 * Count responses for an activity, optionally filtered by response type.
	 *
	 * @param int         $activity_id Activity ID.
	 * @param string|null $response    Optional. 'going' or 'maybe'.
	 * @return int Count.
	 */
	public static function count_by_activity( $activity_id, $response = null ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_RESPONSES;

		if ( $response && in_array( $response, self::VALID_RESPONSES, true ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE activity_id = %d AND response = %s",
					$activity_id,
					$response
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE activity_id = %d",
				$activity_id
			)
		);
	}
}
