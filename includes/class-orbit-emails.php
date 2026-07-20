<?php
/**
 * Transactional lifecycle emails.
 *
 * Static facade (like Orbit_Consent / Orbit_Features) for the plugin's
 * 1:1 relationship emails: the account welcome, the "your subscription was
 * approved" note to a subscriber, and the "someone wants to follow you"
 * note to a poster.
 *
 * These are transactional 1:1 messages, NOT bulk/subscription mail, so —
 * unlike Orbit_Notifier's activity/digest sends — they deliberately do NOT
 * carry RFC 8058 List-Unsubscribe headers and do NOT override the From
 * address (wp-mail-smtp owns From in production). Each message's visible
 * body copy is wrapped in an `apply_filters()` hook so a site can override
 * the wording without patching the plugin.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Emails
 */
class Orbit_Emails {

	/**
	 * Send the role-aware Perihelion welcome email.
	 *
	 * Replaces WordPress's boilerplate new-user notification with a branded
	 * welcome that still carries a WORKING "set your password" link — built
	 * exactly the way core builds its reset link so the `wp-login.php?action=rp`
	 * flow accepts it.
	 *
	 * The copy is role-aware: a subscriber (role `orbit_subscriber`) gets the
	 * subscriber welcome, threaded with the poster's display name when the
	 * caller supplies the poster's profile ID; everyone else (posters signing
	 * up via the marketing form, who hold WordPress's core `subscriber` role)
	 * gets the poster welcome.
	 *
	 * If `get_password_reset_key()` fails we fall back to core's
	 * `wp_send_new_user_notifications()` so account setup never breaks.
	 *
	 * @param WP_User $user              The freshly created user.
	 * @param int     $poster_profile_id Optional. Profile ID of the poster the
	 *                                   subscriber signed up to follow. 0 when
	 *                                   there is no poster context (signup).
	 * @return bool True if the welcome mail was handed to wp_mail().
	 */
	public static function send_welcome( $user, $poster_profile_id = 0 ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		// Build a working set-your-password link the same way core does in
		// retrieve_password(): the raw reset key, then the rp action URL.
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			// Never break account setup — fall back to core's notification
			// so the user still receives a usable password-set link.
			wp_send_new_user_notifications( $user->ID, 'user' );
			return false;
		}

		$link = network_site_url(
			'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$subject = __( 'Welcome to Perihelion', 'orbit' );
		$name    = $user->display_name;

		if ( in_array( 'orbit_subscriber', (array) $user->roles, true ) ) {
			$body = self::welcome_subscriber_body( $name, $link, (int) $poster_profile_id, $user );
		} else {
			$body = self::welcome_poster_body( $name, $link, $user );
		}

		return self::send_plaintext( $user->user_email, $subject, $body );
	}

	/**
	 * Build the poster welcome body.
	 *
	 * @param string  $name The recipient's display name.
	 * @param string  $link The set-your-password URL.
	 * @param WP_User $user The recipient (passed to the filter for context).
	 * @return string
	 */
	private static function welcome_poster_body( $name, $link, $user ) {
		$body = sprintf(
			"Hi %1\$s,\n\n"
			. "Your Perihelion account is ready. Set your password to get started:\n%2\$s\n\n"
			. "Perihelion is a calmer way to make plans with the friends you already have — no feed, no notifications begging you back. Add a short profile, share your personal link with the people you'd like to invite, and post whenever something comes to mind.\n\n"
			. "See you out there,\nPerihelion",
			$name,
			$link
		);

		/**
		 * Filter the poster welcome email body.
		 *
		 * @param string  $body The rendered plaintext body.
		 * @param WP_User $user The recipient.
		 * @param string  $link The set-your-password URL.
		 */
		return apply_filters( 'orbit_email_welcome_poster_body', $body, $user, $link );
	}

	/**
	 * Build the subscriber welcome body.
	 *
	 * When the poster's profile is known the copy names them; otherwise it
	 * falls back to poster-agnostic wording.
	 *
	 * @param string  $name              The recipient's display name.
	 * @param string  $link              The set-your-password URL.
	 * @param int     $poster_profile_id Poster profile ID, or 0 for no context.
	 * @param WP_User $user              The recipient (passed to the filter).
	 * @return string
	 */
	private static function welcome_subscriber_body( $name, $link, $poster_profile_id, $user ) {
		$poster_name = '';
		if ( $poster_profile_id > 0 ) {
			$profile = Orbit_Profile::get( $poster_profile_id );
			if ( $profile ) {
				$poster_name = $profile->display_name;
			}
		}

		if ( '' !== $poster_name ) {
			$plans_phrase   = sprintf( "%s's plans", $poster_name );
			$approve_phrase = sprintf( '%s will get your request and approve it', $poster_name );
		} else {
			$plans_phrase   = 'the people you follow';
			$approve_phrase = "They'll get your request and approve it";
		}

		$body = sprintf(
			"Hi %1\$s,\n\n"
			. "You're all set to hear about %2\$s. Set your password here:\n%3\$s\n\n"
			. "%4\$s — then their activities show up on your dashboard. You'll only ever hear from the people you choose, and \"maybe\" (or nothing at all) is always a fine answer.\n\n"
			. "See you out there,\nPerihelion",
			$name,
			$plans_phrase,
			$link,
			$approve_phrase
		);

		/**
		 * Filter the subscriber welcome email body.
		 *
		 * @param string  $body        The rendered plaintext body.
		 * @param WP_User $user        The recipient.
		 * @param string  $poster_name The poster's display name, or '' when unknown.
		 * @param string  $link        The set-your-password URL.
		 */
		return apply_filters( 'orbit_email_welcome_subscriber_body', $body, $user, $poster_name, $link );
	}

	/**
	 * Notify a subscriber that their subscription was approved.
	 *
	 * @param object $subscription Subscription row.
	 * @return bool True if the mail was handed to wp_mail().
	 */
	public static function send_subscription_approved( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return false;
		}

		$subscriber = get_userdata( (int) $subscription->user_id );
		if ( ! $subscriber ) {
			return false;
		}

		$profile     = Orbit_Profile::get( (int) $subscription->profile_id );
		$poster_name = $profile ? $profile->display_name : __( 'Someone', 'orbit' );

		$name           = $subscriber->display_name;
		$dashboard_link = home_url( '/dashboard/' );

		$subject = sprintf(
			/* translators: %s: poster display name */
			__( '%s approved you on Perihelion', 'orbit' ),
			$poster_name
		);

		$body = sprintf(
			"Hi %1\$s,\n\n"
			. "%2\$s approved your subscription — you're in. Their activities will start showing up on your dashboard:\n%3\$s\n\n"
			. "When something sounds good, tap \"I'm in\" or \"Maybe.\" If it's not for you, no reply needed — saying nothing is a complete answer.\n\n"
			. 'Perihelion',
			$name,
			$poster_name,
			$dashboard_link
		);

		/**
		 * Filter the subscription-approved email body.
		 *
		 * @param string $body           The rendered plaintext body.
		 * @param object $subscription   The subscription row.
		 * @param string $poster_name    The poster's display name.
		 * @param string $dashboard_link The dashboard URL.
		 */
		$body = apply_filters( 'orbit_email_subscription_approved_body', $body, $subscription, $poster_name, $dashboard_link );

		return self::send_plaintext( $subscriber->user_email, $subject, $body );
	}

	/**
	 * Notify a poster that someone requested a subscription.
	 *
	 * @param object $subscription Subscription row.
	 * @return bool True if the mail was handed to wp_mail().
	 */
	public static function send_new_subscriber( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return false;
		}

		$profile = Orbit_Profile::get( (int) $subscription->profile_id );
		if ( ! $profile ) {
			return false;
		}

		$poster = get_userdata( (int) $profile->user_id );
		if ( ! $poster ) {
			return false;
		}

		$subscriber = get_userdata( (int) $subscription->user_id );
		if ( ! $subscriber ) {
			return false;
		}

		$poster_name      = $poster->display_name;
		$name             = $subscriber->display_name;
		$subscribers_link = home_url( '/subscribers/' );

		$note      = isset( $subscription->connection_note ) ? trim( (string) $subscription->connection_note ) : '';
		$note_line = '';
		if ( '' !== $note ) {
			// The line plus a trailing blank line; omitted entirely when no
			// note so we never render an empty `They added: ""`.
			$note_line = sprintf( 'They added: "%s"', $note ) . "\n\n";
		}

		$subject = sprintf(
			/* translators: %s: subscriber display name */
			__( '%s would like to follow your plans', 'orbit' ),
			$name
		);

		$body = sprintf(
			"Hi %1\$s,\n\n"
			. "%2\$s asked to subscribe to your activities on Perihelion.\n"
			. '%3$s'
			. "Approve or decline whenever suits you — until you do, they won't see your activities:\n%4\$s\n\n"
			. 'Perihelion',
			$poster_name,
			$name,
			$note_line,
			$subscribers_link
		);

		/**
		 * Filter the new-subscriber email body.
		 *
		 * @param string $body             The rendered plaintext body.
		 * @param object $subscription     The subscription row.
		 * @param string $note             The connection note, or '' when absent.
		 * @param string $subscribers_link The subscribers management URL.
		 */
		$body = apply_filters( 'orbit_email_new_subscriber_body', $body, $subscription, $note, $subscribers_link );

		return self::send_plaintext( $poster->user_email, $subject, $body );
	}

	/**
	 * Action handler: a subscription's status changed.
	 *
	 * Sends the approval email on the pending → approved transition only.
	 * Denials, removals, and unsubscribes are intentionally silent for now —
	 * the denial email is deferred (see #43). Do NOT add a deny/remove/
	 * unsubscribe branch here until that copy is approved.
	 *
	 * @param int    $id         Subscription ID.
	 * @param string $new_status The status just written.
	 * @param string $old_status The status before the change.
	 */
	public static function on_subscription_status_changed( $id, $new_status, $old_status ) {
		if ( 'approved' !== $new_status || 'pending' !== $old_status ) {
			return;
		}

		$subscription = Orbit_Subscription::get( (int) $id );
		if ( ! $subscription ) {
			return;
		}

		self::send_subscription_approved( $subscription );
	}

	/**
	 * Action handler: a subscription was requested.
	 *
	 * Emails the poster only when the request is pending (require_approval);
	 * an auto-approved subscription needs no poster action.
	 *
	 * @param int $id Subscription ID.
	 */
	public static function on_subscription_requested( $id ) {
		$subscription = Orbit_Subscription::get( (int) $id );
		if ( ! $subscription ) {
			return;
		}

		if ( 'pending' !== $subscription->status ) {
			return;
		}

		self::send_new_subscriber( $subscription );
	}

	/**
	 * Send a plaintext transactional email.
	 *
	 * No RFC 8058 List-Unsubscribe headers (those are for bulk/subscription
	 * mail) and no From override (wp-mail-smtp owns From in production).
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Subject line.
	 * @param string $body    Plaintext body.
	 * @return bool True on wp_mail() success.
	 */
	private static function send_plaintext( $to, $subject, $body ) {
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}
}
