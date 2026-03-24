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

		// For 'names' mode, resolve each response's visibility.
		foreach ( $responses as $response ) {
			$effective_visibility = self::resolve_effective_visibility( $response );

			$entry = array(
				'response'    => $response->response,
				'visible'     => false,
				'display_name' => null,
			);

			if ( 'visible' === $effective_visibility ) {
				$entry['visible'] = true;
				$subscription     = Orbit_Subscription::get( $response->subscription_id );

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
	 * Resolve the effective visibility for a response.
	 *
	 * Priority: per-activity visibility_override > subscription visibility_default.
	 *
	 * @param object $response Response row (must include visibility_override and subscription_id).
	 * @return string 'anonymous' or 'visible'.
	 */
	private static function resolve_effective_visibility( $response ) {
		// If the response has a non-default override, use it.
		if ( 'default' !== $response->visibility_override ) {
			return $response->visibility_override;
		}

		// Fall back to subscription default.
		$subscription = Orbit_Subscription::get( $response->subscription_id );

		if ( $subscription ) {
			return $subscription->visibility_default;
		}

		return 'anonymous';
	}
}
