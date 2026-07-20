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
	 * The copy is keyed on the poster context threaded through by the caller,
	 * NOT on the recipient's role: signup and subscribe users now both hold
	 * `orbit_subscriber`, so role can no longer distinguish the two flows (see
	 * #54). A caller-supplied poster profile ID (`$poster_profile_id > 0`) means
	 * this is a subscribe (subscriber onboarding), so they get the subscriber
	 * welcome — threaded with the poster's display name when the profile
	 * resolves, falling back to poster-agnostic wording when it doesn't. No
	 * poster context (`0`) means this is a signup (poster onboarding), so they
	 * get the poster welcome.
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

		// Route on poster context, not role: a poster profile ID means this
		// is a subscribe (subscriber onboarding); its absence means a signup
		// (poster onboarding). See #54.
		if ( (int) $poster_profile_id > 0 ) {
			$body = self::welcome_subscriber_body( $name, $link, (int) $poster_profile_id, $user );
			$html = self::welcome_subscriber_html( $name, $link, (int) $poster_profile_id, $user );
		} else {
			$body = self::welcome_poster_body( $name, $link, $user );
			$html = self::welcome_poster_html( $name, $link, $user );
		}

		$preheader = __( 'Your Perihelion account is ready — set your password to get started.', 'orbit' );

		return self::send_html(
			$user->user_email,
			$subject,
			Orbit_Email_Template::wrap( $html, $preheader ),
			$body
		);
	}

	/**
	 * Build the poster welcome HTML inner content.
	 *
	 * Mirrors welcome_poster_body(): same approved copy, but the set-password
	 * URL lives ONLY in the button (hidden), not inline.
	 *
	 * @param string  $name The recipient's display name.
	 * @param string  $link The set-your-password URL.
	 * @param WP_User $user The recipient (passed to the filter for context).
	 * @return string HTML fragment for the card interior.
	 */
	private static function welcome_poster_html( $name, $link, $user ) {
		$inner = Orbit_Email_Template::paragraph(
			/* translators: %s: recipient display name */
			sprintf( __( 'Hi %s,', 'orbit' ), $name )
		);
		$inner .= Orbit_Email_Template::paragraph( __( 'Your Perihelion account is ready. Set your password to get started:', 'orbit' ) );
		$inner .= Orbit_Email_Template::button( __( 'Set your password', 'orbit' ), $link );
		$inner .= Orbit_Email_Template::paragraph( __( "Perihelion is a calmer way to make plans with the friends you already have — no feed, no notifications begging you back. Add a short profile, share your personal link with the people you'd like to invite, and post whenever something comes to mind.", 'orbit' ) );
		$inner .= Orbit_Email_Template::paragraph_muted( __( "See you out there,\nPerihelion", 'orbit' ) );

		/**
		 * Filter the poster welcome email HTML inner content.
		 *
		 * @param string  $inner The rendered HTML card interior.
		 * @param WP_User $user  The recipient.
		 * @param string  $link  The set-your-password URL.
		 */
		return apply_filters( 'orbit_email_welcome_poster_html', $inner, $user, $link );
	}

	/**
	 * Build the subscriber welcome HTML inner content.
	 *
	 * Mirrors welcome_subscriber_body(): names the poster when the profile
	 * resolves, otherwise poster-agnostic wording. The set-password URL lives
	 * ONLY in the button.
	 *
	 * @param string  $name              The recipient's display name.
	 * @param string  $link              The set-your-password URL.
	 * @param int     $poster_profile_id Poster profile ID, or 0 for no context.
	 * @param WP_User $user              The recipient (passed to the filter).
	 * @return string HTML fragment for the card interior.
	 */
	private static function welcome_subscriber_html( $name, $link, $poster_profile_id, $user ) {
		$poster_name = '';
		if ( $poster_profile_id > 0 ) {
			$profile = Orbit_Profile::get( $poster_profile_id );
			if ( $profile ) {
				$poster_name = $profile->display_name;
			}
		}

		if ( '' !== $poster_name ) {
			$plans_phrase = sprintf(
				/* translators: %s: poster display name */
				__( "%s's plans", 'orbit' ),
				$poster_name
			);
			$approve_phrase = sprintf(
				/* translators: %s: poster display name */
				__( '%s will get your request and approve it', 'orbit' ),
				$poster_name
			);
		} else {
			$plans_phrase   = __( 'the people you follow', 'orbit' );
			$approve_phrase = __( "They'll get your request and approve it", 'orbit' );
		}

		$inner = Orbit_Email_Template::paragraph(
			/* translators: %s: recipient display name */
			sprintf( __( 'Hi %s,', 'orbit' ), $name )
		);
		$inner .= Orbit_Email_Template::paragraph(
			sprintf(
				/* translators: %s: whose plans they'll hear about (poster name or "the people you follow") */
				__( "You're all set to hear about %s. Set your password here:", 'orbit' ),
				$plans_phrase
			)
		);
		$inner .= Orbit_Email_Template::button( __( 'Set your password', 'orbit' ), $link );
		$inner .= Orbit_Email_Template::paragraph(
			sprintf(
				/* translators: %s: who will approve the request (poster name or "They") */
				__( '%s — then their activities show up on your dashboard. You\'ll only ever hear from the people you choose, and "maybe" (or nothing at all) is always a fine answer.', 'orbit' ),
				$approve_phrase
			)
		);
		$inner .= Orbit_Email_Template::paragraph_muted( __( "See you out there,\nPerihelion", 'orbit' ) );

		/**
		 * Filter the subscriber welcome email HTML inner content.
		 *
		 * @param string  $inner       The rendered HTML card interior.
		 * @param WP_User $user        The recipient.
		 * @param string  $poster_name The poster's display name, or '' when unknown.
		 * @param string  $link        The set-your-password URL.
		 */
		return apply_filters( 'orbit_email_welcome_subscriber_html', $inner, $user, $poster_name, $link );
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

		$inner = Orbit_Email_Template::paragraph(
			/* translators: %s: recipient display name */
			sprintf( __( 'Hi %s,', 'orbit' ), $name )
		);
		$inner .= Orbit_Email_Template::paragraph(
			sprintf(
				/* translators: %s: poster display name */
				__( "%s approved your subscription — you're in. Their activities will start showing up on your dashboard:", 'orbit' ),
				$poster_name
			)
		);
		$inner .= Orbit_Email_Template::button( __( 'Go to your dashboard', 'orbit' ), $dashboard_link );
		$inner .= Orbit_Email_Template::paragraph( __( 'When something sounds good, tap "I\'m in" or "Maybe." If it\'s not for you, no reply needed — saying nothing is a complete answer.', 'orbit' ) );
		$inner .= Orbit_Email_Template::paragraph_muted( __( 'Perihelion', 'orbit' ) );

		/**
		 * Filter the subscription-approved email HTML inner content.
		 *
		 * @param string $inner          The rendered HTML card interior.
		 * @param object $subscription   The subscription row.
		 * @param string $poster_name    The poster's display name.
		 * @param string $dashboard_link The dashboard URL.
		 */
		$inner = apply_filters( 'orbit_email_subscription_approved_html', $inner, $subscription, $poster_name, $dashboard_link );

		$preheader = sprintf(
			/* translators: %s: poster display name */
			__( '%s approved your subscription on Perihelion.', 'orbit' ),
			$poster_name
		);

		return self::send_html(
			$subscriber->user_email,
			$subject,
			Orbit_Email_Template::wrap( $inner, $preheader ),
			$body
		);
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

		$inner = Orbit_Email_Template::paragraph(
			/* translators: %s: poster display name */
			sprintf( __( 'Hi %s,', 'orbit' ), $poster_name )
		);
		$inner .= Orbit_Email_Template::paragraph(
			sprintf(
				/* translators: %s: subscriber display name */
				__( '%s asked to subscribe to your activities on Perihelion.', 'orbit' ),
				$name
			)
		);
		if ( '' !== $note ) {
			$inner .= Orbit_Email_Template::paragraph(
				sprintf(
					/* translators: %s: the subscriber's connection note */
					__( 'They added: "%s"', 'orbit' ),
					$note
				)
			);
		}
		$inner .= Orbit_Email_Template::paragraph( __( "Approve or decline whenever suits you — until you do, they won't see your activities:", 'orbit' ) );
		$inner .= Orbit_Email_Template::button( __( 'Review the request', 'orbit' ), $subscribers_link );
		$inner .= Orbit_Email_Template::paragraph_muted( __( 'Perihelion', 'orbit' ) );

		/**
		 * Filter the new-subscriber email HTML inner content.
		 *
		 * @param string $inner            The rendered HTML card interior.
		 * @param object $subscription     The subscription row.
		 * @param string $note             The connection note, or '' when absent.
		 * @param string $subscribers_link The subscribers management URL.
		 */
		$inner = apply_filters( 'orbit_email_new_subscriber_html', $inner, $subscription, $note, $subscribers_link );

		$preheader = sprintf(
			/* translators: %s: subscriber display name */
			__( '%s would like to follow your plans on Perihelion.', 'orbit' ),
			$name
		);

		return self::send_html(
			$poster->user_email,
			$subject,
			Orbit_Email_Template::wrap( $inner, $preheader ),
			$body
		);
	}

	/**
	 * ActionScheduler hook names for the deferred lifecycle sends.
	 */
	const HOOK_SEND_APPROVED       = 'orbit_send_subscription_approved';
	const HOOK_SEND_NEW_SUBSCRIBER = 'orbit_send_new_subscriber';

	/**
	 * Action handler: a subscription's status changed.
	 *
	 * Defers the approval email (pending → approved only) to ActionScheduler
	 * so the poster's "Approve" REST response isn't blocked on SMTP latency —
	 * the recipient is the subscriber, not the poster waiting on the request,
	 * so there is no reason to send inline (mirrors the deferred welcome, see
	 * todo 119). Falls back to a synchronous send only when AS isn't loaded.
	 *
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

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK_SEND_APPROVED,
				array( 'subscription_id' => (int) $id ),
				'orbit'
			);
		} else {
			// Fallback: AS not loaded — should not happen in production.
			self::dispatch_subscription_approved( (int) $id );
		}
	}

	/**
	 * Action handler: a subscription was requested.
	 *
	 * Emails the poster only when the request is pending (require_approval);
	 * an auto-approved subscription needs no poster action. Defers the send
	 * to ActionScheduler so the subscriber's HTTP request isn't blocked on
	 * the poster's notification (see todo 119). Falls back to a synchronous
	 * send only when AS isn't loaded.
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

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK_SEND_NEW_SUBSCRIBER,
				array( 'subscription_id' => (int) $id ),
				'orbit'
			);
		} else {
			// Fallback: AS not loaded — should not happen in production.
			self::dispatch_new_subscriber( (int) $id );
		}
	}

	/**
	 * ActionScheduler callback: send the deferred subscription-approved email.
	 *
	 * Re-loads the subscription by ID and no-ops if it's gone. Minor
	 * staleness between enqueue and execution is acceptable.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function dispatch_subscription_approved( $subscription_id ) {
		$subscription = Orbit_Subscription::get( (int) $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		self::send_subscription_approved( $subscription );
	}

	/**
	 * ActionScheduler callback: send the deferred new-subscriber email.
	 *
	 * Re-loads the subscription by ID and no-ops if it's gone. Minor
	 * staleness between enqueue and execution is acceptable.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function dispatch_new_subscriber( $subscription_id ) {
		$subscription = Orbit_Subscription::get( (int) $subscription_id );
		if ( ! $subscription ) {
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

	/**
	 * Send a multipart (HTML + plaintext) email.
	 *
	 * Sends the branded HTML as the primary body while carrying the warm
	 * plaintext as the multipart/alternative fallback (PHPMailer's `AltBody`).
	 * A scoped `phpmailer_init` closure sets `AltBody` for THIS send only and
	 * is removed immediately afterward, so the plaintext never leaks onto
	 * unrelated wp_mail() calls.
	 *
	 * Verified delivery behavior: wp-mail-smtp's SendGrid mailer emits a
	 * multipart body (text/plain + text/html) from PHPMailer's Body + AltBody,
	 * and Local's default mailer builds the multipart natively — so both the
	 * production and dev paths produce a proper multipart/alternative message.
	 *
	 * `$extra_headers` is merged AFTER the `text/html` Content-Type so callers
	 * can pass through headers that must survive the switch to HTML — notably
	 * Orbit_Notifier's RFC 8058 `List-Unsubscribe` / `List-Unsubscribe-Post`
	 * headers.
	 *
	 * @param string $to            Recipient email address.
	 * @param string $subject       Subject line.
	 * @param string $html          Full HTML document (primary body).
	 * @param string $plaintext     Plaintext fallback (becomes AltBody).
	 * @param array  $extra_headers Optional extra headers merged with the
	 *                              text/html Content-Type header.
	 * @return bool True on wp_mail() success.
	 */
	public static function send_html( $to, $subject, $html, $plaintext, array $extra_headers = array() ) {
		$set_alt_body = static function ( $phpmailer ) use ( $plaintext ) {
			// AltBody is PHPMailer's public property for the plaintext
			// multipart/alternative part; casing is fixed by the library.
			$phpmailer->AltBody = $plaintext; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		add_action( 'phpmailer_init', $set_alt_body );

		$headers = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $extra_headers );
		$sent    = wp_mail( $to, $subject, $html, $headers );

		remove_action( 'phpmailer_init', $set_alt_body );

		return (bool) $sent;
	}
}
