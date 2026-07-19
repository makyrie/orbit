<?php
/**
 * Visibility resolution logic.
 *
 * Resolves what a viewer sees based on:
 * 1. Poster's show_attendees setting (none / count / names).
 * 2. Each responder's effective visibility (per-activity override > subscription default).
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Privacy
 */
class Orbit_Privacy {

	/**
	 * Resolve visible responses for an activity from a viewer's perspective.
	 *
	 * @param object   $activity   Activity row (must include show_attendees).
	 * @param array    $responses  Array of response rows.
	 * @param int|null $viewer_id  The viewer's user ID (null for unauthenticated).
	 * @return array {
	 *     @type string $visibility_mode 'none', 'count', or 'names'.
	 *     @type int    $total_count     Total response count.
	 *     @type int    $going_count     Going response count.
	 *     @type int    $maybe_count     Maybe response count.
	 *     @type array  $visible_responses Array of resolved response data (only for 'names' mode).
	 * }
	 */
	public static function resolve_responses( $activity, $responses, $viewer_id = null ) {
		$result = array(
			'visibility_mode'   => $activity->show_attendees,
			'total_count'       => count( $responses ),
			'going_count'       => 0,
			'maybe_count'       => 0,
			'visible_responses' => array(),
		);

		foreach ( $responses as $response ) {
			if ( 'going' === $response->response ) {
				++$result['going_count'];
			} else {
				++$result['maybe_count'];
			}
		}

		// If poster has disabled attendee visibility, return counts only.
		if ( 'none' === $activity->show_attendees ) {
			$result['total_count'] = 0;
			$result['going_count'] = 0;
			$result['maybe_count'] = 0;

			return $result;
		}

		// For 'count' mode, we already have the counts.
		if ( 'count' === $activity->show_attendees ) {
			return $result;
		}

		// For 'names' mode, batch-load subscriptions and users, then resolve visibility.
		$sub_ids          = array_unique( array_map( function ( $r ) {
			return (int) $r->subscription_id;
		}, $responses ) );
		$subscriptions_map = Orbit_Subscription::get_by_ids( $sub_ids );

		// Pre-populate WordPress user cache.
		$user_ids = array();
		foreach ( $subscriptions_map as $sub ) {
			$user_ids[] = (int) $sub->user_id;
		}
		if ( ! empty( $user_ids ) ) {
			cache_users( $user_ids );
		}

		foreach ( $responses as $response ) {
			$subscription         = isset( $subscriptions_map[ (int) $response->subscription_id ] ) ? $subscriptions_map[ (int) $response->subscription_id ] : null;
			$effective_visibility = self::resolve_effective_visibility( $response, $subscription );

			$entry = array(
				'response'     => $response->response,
				'visible'      => false,
				'display_name' => null,
			);

			if ( 'visible' === $effective_visibility ) {
				$entry['visible'] = true;

				if ( $subscription ) {
					$user = get_userdata( $subscription->user_id );
					if ( $user ) {
						$entry['display_name'] = $user->display_name;
					}
				}
			}

			$result['visible_responses'][] = $entry;
		}

		return $result;
	}

	/**
	 * Check if a viewer can see the activity's location address.
	 *
	 * Location address is restricted to approved subscribers.
	 *
	 * @param int      $activity_id The activity ID.
	 * @param int|null $viewer_id   The viewer's user ID (null = unauthenticated).
	 * @return bool True if the viewer can see the address.
	 */
	public static function can_view_location_address( $activity_id, $viewer_id = null ) {
		if ( ! $viewer_id ) {
			return false;
		}

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return false;
		}

		// Poster can always see their own activity's address.
		$profile = Orbit_Profile::get( $activity->profile_id );
		if ( $profile && (int) $profile->user_id === $viewer_id ) {
			return true;
		}

		// Check if viewer has an approved subscription to this profile.
		$subscription = Orbit_Subscription::get_by_user_and_profile( $viewer_id, $activity->profile_id );

		return $subscription && 'approved' === $subscription->status;
	}

	/**
	 * Clean up all Orbit data for a user being deleted.
	 *
	 * Cascade order:
	 * 1. If the user is a poster, delete all activities for their profile,
	 *    all responses to those activities (from any user), and all
	 *    subscriptions TO that profile (from any user).
	 * 2. Delete the user's own responses (via their subscriptions).
	 * 3. Delete the user's own subscriptions.
	 * 4. Delete notification preferences, notification log, and phone
	 *    verification records.
	 * 5. Delete the profile record.
	 * 6. Clean up usermeta.
	 * 7. Redact PII from the consent ledger but preserve the row for
	 *    TCPA evidence (user_id, channel, event, program, policy versions,
	 *    and the hash chain are kept).
	 *
	 * @param int $user_id The WordPress user ID being deleted.
	 */
	public static function cleanup_user_data( $user_id ) {
		global $wpdb;

		$profiles_table      = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$subscriptions_table = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$activities_table    = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$responses_table     = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$notif_prefs_table   = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;
		$notif_log_table     = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$phone_verify_table  = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;

		// Find the user's profile (if they are a poster).
		$profile_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$profiles_table} WHERE user_id = %d",
				$user_id
			)
		);

		// If the user has a poster profile, cascade-delete profile-owned data.
		if ( $profile_id ) {
			// Get all activity IDs for this profile.
			$activity_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$activities_table} WHERE profile_id = %d",
					$profile_id
				)
			);

			// Delete all responses to those activities (from any user).
			if ( ! empty( $activity_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$responses_table} WHERE activity_id IN ({$placeholders})",
						...$activity_ids
					)
				);

				// Delete notification log entries for those activities.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$notif_log_table} WHERE activity_id IN ({$placeholders})",
						...$activity_ids
					)
				);
			}

			// Delete all activities for this profile.
			$wpdb->delete( $activities_table, array( 'profile_id' => $profile_id ), array( '%d' ) );

			// Delete all subscriptions TO this profile (from any user).
			$wpdb->delete( $subscriptions_table, array( 'profile_id' => $profile_id ), array( '%d' ) );
		}

		// Delete notification preferences.
		$wpdb->delete( $notif_prefs_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete notification log entries for this user.
		$wpdb->delete( $notif_log_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete phone verification records.
		$wpdb->delete( $phone_verify_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete responses via subscriptions owned by this user.
		$subscription_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$subscriptions_table} WHERE user_id = %d",
				$user_id
			)
		);

		if ( ! empty( $subscription_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $subscription_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$responses_table} WHERE subscription_id IN ({$placeholders})",
					...$subscription_ids
				)
			);
		}

		// Delete the user's own subscriptions.
		$wpdb->delete( $subscriptions_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Delete the profile record.
		$wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Clean up usermeta.
		delete_user_meta( $user_id, 'orbit_phone' );
		delete_user_meta( $user_id, 'orbit_phone_verified' );
		// `orbit_phone_pending` holds an unverified candidate phone written
		// at sign-up / subscribe time; without explicit deletion here,
		// GDPR Article 17 erasure would leak a phone number for any user
		// who never completed SMS verification. The `_at` companion is
		// the GC cron's age signal — purge both.
		delete_user_meta( $user_id, 'orbit_phone_pending' );
		delete_user_meta( $user_id, 'orbit_phone_pending_at' );
		delete_user_meta( $user_id, 'orbit_timezone' );
		delete_user_meta( $user_id, 'orbit_sms_opted_out' );

		/*
		 * Redact PII from the consent ledger while preserving the row for
		 * TCPA evidence. This keeps user_id, channel, event, program,
		 * policy versions, and the hash chain in place but clears the
		 * hashed/PII-bearing columns and stamps redacted_at_utc.
		 *
		 * The redaction mutates hash inputs (cta_snapshot, ip_hash,
		 * user_agent), so Orbit_Consent::verify_chain() will report these
		 * rows as broken until v1.7's chain-versioning lands and teaches
		 * verify_chain() to recognize rows where redacted_at_utc IS NOT
		 * NULL as expected-redacted. This is a deliberate v1.6.0 trade-off
		 * favouring GDPR Article 17 erasure over full hash-chain integrity
		 * through redaction.
		 *
		 * The append-only query guard is relaxed via with_migration_mode()
		 * because this UPDATE is legitimate maintenance, not a
		 * back-dated ledger write.
		 *
		 * If the user has zero consent ledger rows the UPDATE simply
		 * affects 0 rows and returns without error.
		 */
		Orbit_Consent::with_migration_mode(
			function () use ( $user_id ) {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET ip_hash = '',
							user_agent = '',
							cta_snapshot = %s,
							redacted_at_utc = %s
						WHERE user_id = %d AND redacted_at_utc IS NULL",
						'[redacted per user deletion]',
						gmdate( 'Y-m-d H:i:s' ),
						$user_id
					)
				);
			}
		);
	}

	/**
	 * Resolve the effective visibility for a response.
	 *
	 * Priority: per-activity visibility_override > subscription visibility_default.
	 *
	 * @param object      $response     Response row (must include visibility_override).
	 * @param object|null $subscription Pre-loaded subscription, or null to fetch.
	 * @return string 'anonymous' or 'visible'.
	 */
	private static function resolve_effective_visibility( $response, $subscription = null ) {
		// If the response has a non-default override, use it.
		if ( 'default' !== $response->visibility_override ) {
			return $response->visibility_override;
		}

		// Fall back to subscription default.
		if ( null === $subscription ) {
			$subscription = Orbit_Subscription::get( $response->subscription_id );
		}

		if ( $subscription ) {
			return $subscription->visibility_default;
		}

		return 'anonymous';
	}
}
