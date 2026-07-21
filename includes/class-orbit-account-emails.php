<?php
/**
 * Branded, human account-lifecycle emails.
 *
 * WordPress core sends several account emails as plain, unstyled text that
 * greet the user by their auto-generated `user_login` (e.g.
 * "nadiaokonkwo24580" — a handle the person never chose and sees nowhere
 * else) and route help to the site admin's personal address. These filters
 * re-render the ones our members actually receive through the Perihelion
 * brand template, greet people by the display name they picked, and send
 * help to the public /contact/ page.
 *
 * Covered:
 *  - password_change_email  — "your password was changed" confirmation (HTML)
 *  - email_change_email      — "your email address was changed" confirmation (HTML)
 *  - retrieve_password_*     — the "Lost your password?" reset link (polished
 *                              plaintext; the reset flow has no per-message
 *                              headers hook, so we keep it text and just fix
 *                              the greeting, tone, and support link)
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Account_Emails
 */
class Orbit_Account_Emails {

	/**
	 * Register the WordPress-core email filters.
	 */
	public static function register() {
		add_filter( 'password_change_email', array( __CLASS__, 'password_change_email' ), 10, 2 );
		add_filter( 'email_change_email', array( __CLASS__, 'email_change_email' ), 10, 2 );
		add_filter( 'retrieve_password_title', array( __CLASS__, 'retrieve_password_title' ), 10, 3 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'retrieve_password_message' ), 10, 4 );
	}

	/**
	 * Brand the "your password was changed" confirmation.
	 *
	 * @param array $email Email parts (to, subject, message, headers).
	 * @param mixed $user  The original user data (array or object).
	 * @return array Modified email parts.
	 */
	public static function password_change_email( $email, $user ) {
		$name = self::display_name( $user );

		$inner  = Orbit_Email_Template::paragraph( sprintf( /* translators: %s: recipient's name */ __( 'Hi %s,', 'orbit' ), $name ) );
		$inner .= Orbit_Email_Template::paragraph( __( 'This is a quick confirmation that your Perihelion password was just changed.', 'orbit' ) );
		$inner .= Orbit_Email_Template::paragraph( __( "If that was you, you're all set — there's nothing else to do.", 'orbit' ) );
		$inner .= Orbit_Email_Template::paragraph( __( "If it wasn't you, please get in touch right away so we can help keep your account safe:", 'orbit' ) );
		$inner .= Orbit_Email_Template::button( __( 'Contact us', 'orbit' ), self::contact_url() );

		$email['subject'] = __( 'Your Perihelion password was changed', 'orbit' );
		$email['message'] = Orbit_Email_Template::wrap( $inner, __( 'Your Perihelion password was just changed.', 'orbit' ) );
		$email['headers'] = self::with_html_content_type( $email['headers'] );

		return $email;
	}

	/**
	 * Brand the "your email address was changed" confirmation.
	 *
	 * @param array $email Email parts (to, subject, message, headers).
	 * @param mixed $user  The original user data (array or object).
	 * @return array Modified email parts.
	 */
	public static function email_change_email( $email, $user ) {
		$name = self::display_name( $user );

		$inner  = Orbit_Email_Template::paragraph( sprintf( /* translators: %s: recipient's name */ __( 'Hi %s,', 'orbit' ), $name ) );
		$inner .= Orbit_Email_Template::paragraph( __( 'This is a quick confirmation that the email address on your Perihelion account was just changed.', 'orbit' ) );
		$inner .= Orbit_Email_Template::paragraph( __( "If that was you, you're all set. If it wasn't, please get in touch right away:", 'orbit' ) );
		$inner .= Orbit_Email_Template::button( __( 'Contact us', 'orbit' ), self::contact_url() );

		$email['subject'] = __( 'Your Perihelion email address was changed', 'orbit' );
		$email['message'] = Orbit_Email_Template::wrap( $inner, __( 'Your Perihelion email address was just changed.', 'orbit' ) );
		$email['headers'] = self::with_html_content_type( $email['headers'] );

		return $email;
	}

	/**
	 * Subject line for the "Lost your password?" reset email.
	 *
	 * @param string $title     Default title.
	 * @param string $user_login User login (unused).
	 * @param mixed  $user_data  User object (unused).
	 * @return string Branded subject.
	 */
	public static function retrieve_password_title( $title, $user_login = '', $user_data = null ) {
		return __( 'Set a new Perihelion password', 'orbit' );
	}

	/**
	 * Body for the "Lost your password?" reset email.
	 *
	 * Kept as plaintext (the reset flow exposes no per-message headers filter),
	 * but greeted by display name, on-brand, and pointed at /contact/ for help.
	 *
	 * @param string $message    Default message.
	 * @param string $key        Password reset key.
	 * @param string $user_login User login (needed to build the reset URL).
	 * @param mixed  $user_data  User object.
	 * @return string Branded plaintext message.
	 */
	public static function retrieve_password_message( $message, $key, $user_login, $user_data ) {
		$name = self::display_name( $user_data );

		// Mirror core's reset URL construction (and Orbit's own set-password link).
		$reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user_login ), 'login' );

		$lines = array(
			sprintf( /* translators: %s: recipient's name */ __( 'Hi %s,', 'orbit' ), $name ),
			'',
			__( 'Someone asked to reset the password for your Perihelion account. If that was you, set a new one here:', 'orbit' ),
			$reset_url,
			'',
			__( "If you didn't ask for this, you can safely ignore this email — your password won't change.", 'orbit' ),
			'',
			sprintf( /* translators: %s: contact page URL */ __( 'Need a hand? %s', 'orbit' ), self::contact_url() ),
			'',
			__( '— Perihelion', 'orbit' ),
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Extract a person's chosen display name from array-or-object user data,
	 * falling back to a friendly generic — never to the raw user_login.
	 *
	 * @param mixed $user User data (array or object).
	 * @return string Display name, or "there".
	 */
	private static function display_name( $user ) {
		$name = '';

		if ( is_array( $user ) && isset( $user['display_name'] ) ) {
			$name = $user['display_name'];
		} elseif ( is_object( $user ) && isset( $user->display_name ) ) {
			$name = $user->display_name;
		}

		$name = trim( (string) $name );

		return '' !== $name ? $name : __( 'there', 'orbit' );
	}

	/**
	 * The public contact page URL used for account-help links.
	 *
	 * @return string
	 */
	private static function contact_url() {
		return home_url( '/contact/' );
	}

	/**
	 * Add a text/html Content-Type to an email's headers (array or string),
	 * so the branded HTML body renders instead of showing as raw markup.
	 *
	 * @param array|string $headers Existing headers.
	 * @return array|string Headers including the HTML content type.
	 */
	private static function with_html_content_type( $headers ) {
		$content_type = 'Content-Type: text/html; charset=UTF-8';

		if ( empty( $headers ) ) {
			return array( $content_type );
		}

		if ( is_array( $headers ) ) {
			$headers[] = $content_type;
			return $headers;
		}

		return rtrim( $headers ) . "\r\n" . $content_type;
	}
}
