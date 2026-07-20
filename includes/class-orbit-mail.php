<?php
/**
 * Mail-transport tuning for the SendGrid delivery path.
 *
 * SendGrid rewrites every link through its click-tracking redirect
 * (e.g. `url9847.perihelion.social/ls/click?...`). In a plaintext email that
 * shows as a long, unreadable URL that undercuts Perihelion's friendly tone,
 * and it also injects an open-tracking pixel. Perihelion doesn't want tracking
 * on its transactional mail, so we disable click + open tracking for OUR sends
 * only — per message, via wp-mail-smtp's SendGrid request-body filter
 * (`Sendgrid\Mailer::get_body()` applies the filter on the body array before
 * JSON-encoding it). This never touches SendGrid's account-level tracking
 * setting, so other senders on a shared account are unaffected.
 *
 * No-ops unless wp-mail-smtp's active mailer is SendGrid, so it is inert on
 * Local, on other SMTP providers, and in tests.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Mail
 */
class Orbit_Mail {

	/**
	 * Register the wp-mail-smtp body filter.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_mail_smtp_providers_mailer_get_body', array( __CLASS__, 'disable_sendgrid_tracking' ), 10, 2 );
	}

	/**
	 * Add SendGrid `tracking_settings` to disable click + open tracking.
	 *
	 * wp-mail-smtp passes the SendGrid request body as an array here (it is
	 * JSON-encoded immediately afterward), so we add the key to the array.
	 *
	 * @param mixed  $body   The mailer request body — an array for SendGrid at
	 *                       this stage; passed through untouched for anything else.
	 * @param string $mailer The active wp-mail-smtp mailer slug.
	 * @return mixed The body, with tracking disabled when applicable.
	 */
	public static function disable_sendgrid_tracking( $body, $mailer ) {
		if ( 'sendgrid' !== $mailer || ! is_array( $body ) ) {
			return $body;
		}

		/**
		 * Filter whether Orbit disables SendGrid click/open tracking on its sends.
		 *
		 * Return false to leave SendGrid's tracking behavior alone (e.g. if the
		 * account-level setting is already configured as desired).
		 *
		 * @param bool $disable Whether to disable tracking. Default true.
		 */
		if ( ! apply_filters( 'orbit_disable_sendgrid_tracking', true ) ) {
			return $body;
		}

		$body['tracking_settings'] = array(
			'click_tracking' => array(
				'enable'      => false,
				'enable_text' => false,
			),
			'open_tracking'  => array(
				'enable' => false,
			),
		);

		return $body;
	}
}
