<?php
/**
 * Carrier exception used to abort a transactional REST handler while
 * preserving the original WP_Error.
 *
 * The signup and subscribe handlers wrap user provisioning in a
 * `START TRANSACTION` / `COMMIT` block. When any step fails they need
 * a control-flow signal that triggers a `ROLLBACK` in the catch — and
 * they want to forward the *structured* failure (code + message + data)
 * to the response layer, not just a flattened message string. The
 * previous shape — `throw new RuntimeException( $wp_error->get_error_message() )`
 * — leaked raw internal strings (MySQL fragments, third-party hook
 * debug output) to anonymous callers and dropped the original error
 * code, collapsing every failure to a generic `signup_failed` /
 * `subscribe_failed`.
 *
 * This class lets the catch:
 *
 *   1. Inspect the original WP_Error code and branch on it (e.g. map
 *      `existing_user_email` to a 409 `login_required` response).
 *   2. Log the inner WP_Error server-side at full fidelity.
 *   3. Return a controller-translated, user-facing message to the
 *      client — never raw `get_error_message()` output for codes that
 *      originate below the controller layer.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Rolled_Back_Exception
 *
 * Carries a WP_Error across a try/catch boundary inside a transactional
 * REST handler.
 */
class Orbit_Rolled_Back_Exception extends RuntimeException {

	/**
	 * The original WP_Error that triggered the rollback.
	 *
	 * Public by design: the catch block reads `$e->wp_error` to map
	 * the underlying code/data into a response.
	 *
	 * @var WP_Error
	 */
	public $wp_error;

	/**
	 * @param WP_Error $wp_error The originating error. The exception
	 *                           message defaults to the error code so
	 *                           it lands in logs/stack traces as a
	 *                           stable identifier, never the raw
	 *                           message text.
	 */
	public function __construct( WP_Error $wp_error ) {
		$this->wp_error = $wp_error;
		parent::__construct( (string) $wp_error->get_error_code() );
	}
}
