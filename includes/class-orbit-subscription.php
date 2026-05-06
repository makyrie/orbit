<?php
/**
 * Subscription management.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Subscription
 */
class Orbit_Subscription {

	/**
	 * Valid subscription statuses.
	 *
	 * @var array
	 */
	const VALID_STATUSES = array( 'pending', 'approved', 'denied', 'unsubscribed' );

	/**
	 * Subscribe a user to a profile.
	 *
	 * Handles re-subscription: if a record exists (from a prior unsubscribe or deny),
	 * reactivates it instead of inserting a duplicate.
	 *
	 * @param array $args {
	 *     @type int    $user_id         Required. Subscriber's user ID.
	 *     @type int    $profile_id      Required. Profile to subscribe to.
	 *     @type string $connection_note  Optional. "How do you know this person?"
	 * }
	 * @return int|WP_Error Subscription ID on success, WP_Error on failure.
	 */
	public static function subscribe( $args ) {
		global $wpdb;

		if ( empty( $args['user_id'] ) || empty( $args['profile_id'] ) ) {
			return new WP_Error( 'missing_required', __( 'user_id and profile_id are required.', 'orbit' ) );
		}

		$user_id    = absint( $args['user_id'] );
		$profile_id = absint( $args['profile_id'] );

		// Prevent self-subscription.
		$profile = Orbit_Profile::get( $profile_id );
		if ( ! $profile ) {
			return new WP_Error( 'profile_not_found', __( 'Profile not found.', 'orbit' ) );
		}

		if ( (int) $profile->user_id === $user_id ) {
			return new WP_Error( 'self_subscription', __( 'You cannot subscribe to your own profile.', 'orbit' ) );
		}

		$table           = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$connection_note = isset( $args['connection_note'] ) ? sanitize_textarea_field( $args['connection_note'] ) : null;

		if ( $connection_note && mb_strlen( $connection_note ) > 500 ) {
			return new WP_Error( 'note_too_long', __( 'Connection note must be 500 characters or fewer.', 'orbit' ) );
		}

		// Check for existing subscription (handles re-subscription).
		$existing = self::get_by_user_and_profile( $user_id, $profile_id );

		if ( $existing ) {
			if ( 'approved' === $existing->status || 'pending' === $existing->status ) {
				return new WP_Error( 'already_subscribed', __( 'You are already subscribed to this profile.', 'orbit' ) );
			}

			// Re-subscribe: reactivate existing record.
			$new_status = $profile->require_approval ? 'pending' : 'approved';
			$now        = current_time( 'mysql', true );

			$wpdb->update(
				$table,
				array(
					'status'          => $new_status,
					'connection_note' => $connection_note,
					'updated_at'      => $now,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing->id;
		}

		// New subscription.
		$status = $profile->require_approval ? 'pending' : 'approved';
		$now    = current_time( 'mysql', true );

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'             => $user_id,
				'profile_id'          => $profile_id,
				'connection_note'     => $connection_note,
				'status'              => $status,
				'visibility_default'  => 'anonymous',
				'subscription_secret' => Orbit_Token::generate_random(),
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create subscription.', 'orbit' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 * @return object|null Subscription row or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get multiple subscriptions by IDs in a single query.
	 *
	 * @param array $ids Array of subscription IDs.
	 * @return array Associative array keyed by subscription ID.
	 */
	public static function get_by_ids( $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values       = array_map( 'absint', $ids );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN ({$placeholders})",
				...$values
			)
		);

		$keyed = array();
		foreach ( $results as $row ) {
			$keyed[ (int) $row->id ] = $row;
		}

		return $keyed;
	}

	/**
	 * Get a subscription by user ID and profile ID.
	 *
	 * @param int $user_id    User ID.
	 * @param int $profile_id Profile ID.
	 * @return object|null Subscription row or null.
	 */
	public static function get_by_user_and_profile( $user_id, $profile_id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND profile_id = %d",
				$user_id,
				$profile_id
			)
		);
	}

	/**
	 * Get a subscription by its secret.
	 *
	 * @param string $secret Subscription secret.
	 * @return object|null Subscription row or null.
	 */
	public static function get_by_secret( $secret ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE subscription_secret = %s", $secret )
		);
	}

	/**
	 * Approve a subscription.
	 *
	 * @param int $id Subscription ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function approve( $id ) {
		return self::change_status( $id, 'approved', array( 'pending' ) );
	}

	/**
	 * Deny a subscription.
	 *
	 * @param int $id Subscription ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function deny( $id ) {
		return self::change_status( $id, 'denied', array( 'pending' ) );
	}

	/**
	 * Remove a subscription (poster action).
	 *
	 * @param int $id Subscription ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function remove( $id ) {
		return self::change_status( $id, 'denied', array( 'approved', 'pending' ) );
	}

	/**
	 * Unsubscribe (subscriber action, no auth required with valid secret).
	 *
	 * @param int $id Subscription ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function unsubscribe( $id ) {
		return self::change_status( $id, 'unsubscribed', array( 'approved', 'pending' ) );
	}

	/**
	 * List subscriptions with filters.
	 *
	 * @param array $args {
	 *     @type int    $profile_id Filter by profile.
	 *     @type int    $user_id    Filter by subscriber.
	 *     @type string $status     Filter by status.
	 *     @type int    $per_page   Results per page. Default 20.
	 *     @type int    $page       Page number. Default 1.
	 * }
	 * @return array Array of subscription rows.
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'profile_id' => null,
				'user_id'    => null,
				'status'     => null,
				'per_page'   => 20,
				'page'       => 1,
			)
		);

		$table  = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$where  = array( '1=1' );
		$values = array();

		if ( $args['profile_id'] ) {
			$where[]  = 'profile_id = %d';
			$values[] = absint( $args['profile_id'] );
		}

		if ( $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$offset   = max( 0, ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] ) );
		$per_page = absint( $args['per_page'] );

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";

		$values[] = $per_page;
		$values[] = $offset;

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * Count subscriptions with filters.
	 *
	 * @param array $args Same filters as list().
	 * @return int Count.
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'profile_id' => null,
				'user_id'    => null,
				'status'     => null,
			)
		);

		$table  = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$where  = array( '1=1' );
		$values = array();

		if ( $args['profile_id'] ) {
			$where[]  = 'profile_id = %d';
			$values[] = absint( $args['profile_id'] );
		}

		if ( $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get human-readable subscription status labels.
	 *
	 * @return array Associative array of status key => translated label.
	 */
	public static function get_status_labels() {
		return array(
			'approved' => __( 'Approved', 'orbit' ),
			'pending'  => __( 'Pending', 'orbit' ),
		);
	}

	/**
	 * Change subscription status with valid-from-status enforcement.
	 *
	 * @param int    $id             Subscription ID.
	 * @param string $new_status     Target status.
	 * @param array  $valid_from     Allowed current statuses.
	 * @return bool|WP_Error True on success.
	 */
	private static function change_status( $id, $new_status, $valid_from ) {
		global $wpdb;

		$subscription = self::get( $id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'orbit' ) );
		}

		if ( ! in_array( $subscription->status, $valid_from, true ) ) {
			return new WP_Error(
				'invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Cannot change status from %1$s to %2$s.', 'orbit' ),
					$subscription->status,
					$new_status
				)
			);
		}

		$table  = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$result = $wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to update subscription status.', 'orbit' ) );
		}

		return true;
	}
}
