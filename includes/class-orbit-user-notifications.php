<?php
/**
 * Deferred user-notification dispatch.
 *
 * The signup and subscribe REST handlers used to call
 * `wp_send_new_user_notifications()` inline, after COMMIT but before
 * returning the response. That function performs blocking SMTP I/O via
 * `wp_mail()` → `PHPMailer::send()` and can hang for seconds when the
 * relay is misconfigured — long enough for the browser's 30s fetch
 * timeout to fire, leaving the user with a "timeout" error even though
 * the account was created and the auth cookie was set.
 *
 * This class is the ActionScheduler-side handler. The REST controllers
 * enqueue an `orbit_send_new_user_notification` job and return
 * immediately; the mail goes out on the next AS tick. The actual message
 * is the branded, role-aware Perihelion welcome rendered by
 * `Orbit_Emails::send_welcome()` — the subscribe path threads the poster's
 * profile ID through the job payload so the subscriber welcome can name the
 * poster they signed up to follow.
 *
 * Idempotency: if the same job runs twice (rare — AS de-dupes by hook+
 * args+group on enqueue), the user gets two welcome emails. No state
 * corruption.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_User_Notifications
 */
class Orbit_User_Notifications {

	/**
	 * Send the branded Perihelion welcome for a freshly created account.
	 *
	 * Invoked by ActionScheduler via the `orbit_send_new_user_notification`
	 * hook. The user-meta required by the welcome email (locale, display
	 * name) is persisted before the COMMIT that schedules this job, so
	 * everything the mail templates need is already in place.
	 *
	 * Guards against a deleted user between enqueue and execution — if
	 * the row is gone, silently drop the job rather than fataling on the
	 * downstream `get_userdata()`.
	 *
	 * @param int $user_id           The newly created user's ID.
	 * @param int $poster_profile_id Optional. Profile ID of the poster a
	 *                               subscriber signed up to follow. Threaded
	 *                               through the job payload by the subscribe
	 *                               path; 0 (the default) for the signup path
	 *                               so a bare `user_id` job still works.
	 * @return void
	 */
	public static function send_new_user_notification( $user_id, $poster_profile_id = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		Orbit_Emails::send_welcome( $user, (int) $poster_profile_id );
	}
}
