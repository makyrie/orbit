<?php
/**
 * Spam protection for the WordPress registration form.
 *
 * Perihelion deliberately avoids external dependencies for spam handling
 * (CAPTCHAs, Akismet, third-party services). For a small, low-traffic
 * site a hand-rolled honeypot covers the realistic attack surface — drive-
 * by registration bots that spray POSTs against any open `users_can_
 * register=1` install on the internet.
 *
 * Two traps cooperating:
 *
 *   1. **Honeypot field.** A hidden `<input>` named `orbit_url` is added
 *      to the register form, positioned off-screen and labelled "leave
 *      blank". Form-filling bots populate every field they see; humans
 *      never reach it. Non-empty value → reject.
 *
 *   2. **Timestamp field.** A hidden `<input>` named `orbit_form_init`
 *      records when the form rendered. Submissions in under 1.5 seconds
 *      are almost always bots; submissions on a form rendered more than
 *      24 hours ago are stale. Either condition → reject.
 *
 * On bot detection we surface a generic "could not be completed" error
 * so we don't telegraph which trap fired. On a stale form we surface a
 * specific "this form has expired, please reload" error since that's a
 * legitimate user case.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Spam
 */
class Orbit_Spam {

	/**
	 * Reject submissions that arrive in under this many milliseconds
	 * after the form rendered — bots fill and submit instantly, humans
	 * take at least a couple of seconds.
	 */
	const MIN_FILL_MS = 1500;

	/**
	 * Reject submissions on forms rendered more than this many seconds
	 * ago — stale forms, possibly from a saved attack page.
	 */
	const MAX_AGE_SECONDS = DAY_IN_SECONDS;

	/**
	 * Register hooks for spam protection on the WP registration form.
	 */
	public static function register() {
		add_action( 'register_form', array( __CLASS__, 'render_traps' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate_traps' ), 10, 3 );
	}

	/**
	 * Render the honeypot + timestamp fields inside the registration form.
	 */
	public static function render_traps() {
		$init_ms = (int) round( microtime( true ) * 1000 );

		// Honeypot: positioned off-screen and hidden from assistive tech.
		// Labelled so bots that scan for labels still find a "fill me"
		// signal.
		?>
		<p class="orbit-spam-trap" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
			<label for="orbit_url"><?php esc_html_e( 'Website (leave blank)', 'orbit' ); ?></label>
			<input type="text" name="orbit_url" id="orbit_url" tabindex="-1" autocomplete="off" value="">
		</p>
		<input type="hidden" name="orbit_form_init" value="<?php echo esc_attr( $init_ms ); ?>">
		<?php
	}

	/**
	 * Shape-agnostic honeypot + timestamp check.
	 *
	 * Reads `orbit_url` and `orbit_form_init` out of an arbitrary input
	 * map and returns either `null` (clean) or a `WP_Error` describing
	 * the rejection. Lets callers pass `$_POST` directly (WP register
	 * form) or the body of a REST request (Orbit's own signup endpoint)
	 * without each one re-implementing the same logic.
	 *
	 * @param array $post_data Input map (e.g. `$_POST` or `$request->get_params()`).
	 * @return WP_Error|null `WP_Error` on rejection, `null` if clean.
	 */
	public static function check_traps( $post_data ) {
		$honeypot = isset( $post_data['orbit_url'] ) ? wp_unslash( $post_data['orbit_url'] ) : '';

		if ( '' !== trim( (string) $honeypot ) ) {
			return new WP_Error( 'orbit_spam_detected', __( 'Submission could not be completed. Please try again.', 'orbit' ) );
		}

		$init_ms = isset( $post_data['orbit_form_init'] ) ? (int) wp_unslash( $post_data['orbit_form_init'] ) : 0;

		if ( $init_ms <= 0 ) {
			return new WP_Error( 'orbit_spam_detected', __( 'Submission could not be completed. Please try again.', 'orbit' ) );
		}

		$now_ms     = (int) round( microtime( true ) * 1000 );
		$elapsed_ms = $now_ms - $init_ms;

		if ( $elapsed_ms < self::MIN_FILL_MS ) {
			return new WP_Error( 'orbit_spam_detected', __( 'Submission could not be completed. Please try again.', 'orbit' ) );
		}

		if ( $elapsed_ms > self::MAX_AGE_SECONDS * 1000 ) {
			return new WP_Error( 'orbit_form_expired', __( 'This form has expired. Please reload the page and try again.', 'orbit' ) );
		}

		return null;
	}

	/**
	 * Validate honeypot + timestamp on the WP registration form.
	 *
	 * Adapter that bridges `check_traps()` to the `registration_errors`
	 * filter's `WP_Error` accumulator API.
	 *
	 * @param WP_Error $errors               Existing registration errors.
	 * @param string   $sanitized_user_login Sanitized username (unused).
	 * @param string   $user_email           User email (unused).
	 * @return WP_Error Possibly-augmented error object.
	 */
	public static function validate_traps( $errors, $sanitized_user_login, $user_email ) {
		$trap_error = self::check_traps( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP core verifies the registration nonce.

		if ( is_wp_error( $trap_error ) ) {
			$errors->add( $trap_error->get_error_code(), $trap_error->get_error_message() );
		}

		return $errors;
	}
}
