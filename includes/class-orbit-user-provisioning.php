<?php
/**
 * Shared transactional user-provisioning service.
 *
 * Owns the single transactional envelope used by both account-creation
 * surfaces (REST /signup, REST /subscribe new-account branch, and their
 * CLI equivalents):
 *
 *   START TRANSACTION
 *     → wp_insert_user (optionally retrying on `existing_user_login`)
 *     → add_user_to_blog (multisite-only role pin)
 *     → orbit_timezone meta
 *     → orbit_phone_pending + companion timestamp (optional)
 *     → Orbit_Consent::record (per channel passed in $consents)
 *   COMMIT
 *
 * On any failure inside the try, the original WP_Error is forwarded
 * through Orbit_Rolled_Back_Exception so the caller's response layer
 * can branch on the original error code (e.g. `existing_user_email`
 * → 409 login_required). The carrier exception preserves the structured
 * failure across the catch (see todo 116).
 *
 * What this class does NOT own:
 *
 *   - The auth cookie / wp_set_current_user step. REST handlers own
 *     the request lifecycle and call `wp_set_auth_cookie` AFTER this
 *     returns. CLI has no session.
 *   - The subscription row / notifier preferences (Orbit_Subscription
 *     ::subscribe + Orbit_Notifier::get_or_create_preferences). Those
 *     are subscribe-specific and stay in the subscribe handler so the
 *     service stays focused on "create user + stamp consent".
 *   - The welcome email's enqueue is optional and gated by
 *     `$opts['send_welcome_email']` — handled AFTER COMMIT either via
 *     ActionScheduler (default) or synchronously (CLI fallback).
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_User_Provisioning
 *
 * Single-method service that wraps the shared account-creation envelope.
 */
class Orbit_User_Provisioning {

	/**
	 * Create a WordPress user and stamp consent rows in a single transaction.
	 *
	 * @param array $userdata {
	 *     User-row inputs forwarded to wp_insert_user.
	 *
	 *     @type string $user_login              Required. Initial username candidate.
	 *     @type string $user_email              Required.
	 *     @type string $display_name            Required.
	 *     @type string $role                    Required.
	 *     @type string $user_pass               Optional. Auto-generated via wp_generate_password() if absent.
	 *     @type string $phone_pending           Optional. E.164 phone stashed as `orbit_phone_pending`
	 *                                           with a companion `_at` timestamp. Empty/missing skips the write.
	 *     @type int    $username_retry_attempts Optional. Number of times to retry on `existing_user_login`
	 *                                           by appending a fresh wp_rand(10000, 99999) suffix to a
	 *                                           computed base. Default 0 (no retry — subscribe's behavior).
	 *                                           Signup uses 5.
	 * }
	 * @param array $consents {
	 *     Per-channel consent payload. Pass an empty array to skip ledger writes.
	 *
	 *     @type array $email {
	 *         @type string $state        Defaults to 'opt_in'.
	 *         @type string $source       Provenance label (e.g. 'subscribe', 'signup', 'cli').
	 *         @type string $cta_snapshot Disclosure text the user agreed to.
	 *         @type string $ip           Optional IP override (defaults to Orbit_Client_IP::get()).
	 *         @type string $user_agent   Optional user-agent override.
	 *         @type string $program      Optional program identifier.
	 *     }
	 *     @type array $sms Same shape as email.
	 * }
	 * @param array $opts {
	 *     Behavior knobs.
	 *
	 *     @type bool $send_welcome_email     Whether to fire the password-set email after COMMIT.
	 *                                        Default true. CLI seed scripts pass false.
	 *     @type bool $schedule_welcome_async Whether to enqueue via ActionScheduler. Default true.
	 *                                        Falls back to sync `wp_send_new_user_notifications` when
	 *                                        AS isn't loaded. CLI may explicitly want sync delivery.
	 * }
	 * @return int|WP_Error User ID on success; WP_Error on validation/transaction failure.
	 *                       The original (inner) error code is preserved so callers can branch on
	 *                       it (e.g. map `existing_user_email` to a 409 response).
	 */
	public static function create_user_with_consent( array $userdata, array $consents, array $opts = array() ) {
		global $wpdb;

		// Required-field guard. Surfaces an early WP_Error so callers
		// don't have to read the wp_insert_user source to learn which
		// keys are mandatory.
		foreach ( array( 'user_login', 'user_email', 'display_name', 'role' ) as $required ) {
			if ( empty( $userdata[ $required ] ) ) {
				return new WP_Error(
					'orbit_provisioning_missing_field',
					sprintf( 'Required field "%s" missing.', $required )
				);
			}
		}

		$retry_attempts = isset( $userdata['username_retry_attempts'] )
			? (int) $userdata['username_retry_attempts']
			: 0;
		$phone_pending  = isset( $userdata['phone_pending'] ) ? (string) $userdata['phone_pending'] : '';

		// Cache the original login as the retry base so each retry
		// reseeds from the same prefix the caller chose. wp_insert_user
		// runs through its own sanitization, so the base we store here
		// is the one before any post-insert filter mangles it.
		$base_login = (string) $userdata['user_login'];

		// Strip provisioning-only keys before forwarding to wp_insert_user
		// — that function ignores unknown keys today, but leaking
		// service-internal vocabulary into core's userdata is a smell.
		unset( $userdata['username_retry_attempts'], $userdata['phone_pending'] );

		// Default password when caller didn't supply one. wp_insert_user
		// would generate its own fallback, but it tracks the password as
		// "the user set this themselves" — which would short-circuit the
		// password-set email's "set your password" framing. Generating
		// here matches both legacy handlers' shape.
		if ( empty( $userdata['user_pass'] ) ) {
			$userdata['user_pass'] = wp_generate_password();
		}

		$opts = array_merge(
			array(
				'send_welcome_email'     => true,
				'schedule_welcome_async' => true,
			),
			$opts
		);

		// Declared in the outer scope so the catch can evict the
		// Orbit_Notifier preferences cache on rollback if a row landed.
		$user_id = 0;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$attempts = 0;
			$user_id  = null;

			// Retry loop: if the caller opted in (retry_attempts > 0),
			// re-suffix the username on `existing_user_login` collisions.
			// On retry_attempts=0 the loop runs exactly once and any
			// WP_Error propagates immediately.
			do {
				$user_id = wp_insert_user( $userdata );

				if ( ! is_wp_error( $user_id ) ) {
					break;
				}

				if ( 'existing_user_login' !== $user_id->get_error_code() || $retry_attempts < 1 ) {
					// Forward the structured WP_Error through the carrier
					// exception so the catch can branch on the original
					// code (e.g. surface `existing_user_email` race losers
					// as 409 login_required).
					throw new Orbit_Rolled_Back_Exception( $user_id );
				}

				$userdata['user_login'] = $base_login . wp_rand( 10000, 99999 );
				++$attempts;
			} while ( $attempts < $retry_attempts );

			// Loop exhausted with WP_Error still set — every retry was
			// also a username collision. Treat as a soft service failure
			// (503) so the client can back off and try again.
			if ( is_wp_error( $user_id ) ) {
				return self::rollback_and_return_soft_failure( $user_id );
			}

			// Multisite: wp_insert_user creates a network user with no
			// role on the current sub-site — add_user_to_blog() pins the
			// role here. On single-site the function isn't loaded
			// (ms-functions.php is multisite-only) and wp_insert_user has
			// already set the role globally.
			if ( is_multisite() ) {
				add_user_to_blog( get_current_blog_id(), $user_id, (string) $userdata['role'] );
			}

			update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );

			if ( '' !== $phone_pending ) {
				// Pair the pending phone with an explicit timestamp so the
				// daily GC cron (Orbit_Notifier::cleanup_pending_phones())
				// can reap abandoned signups — usermeta has no native
				// updated_at, so this companion key is required.
				update_user_meta( $user_id, 'orbit_phone_pending', $phone_pending );
				update_user_meta( $user_id, 'orbit_phone_pending_at', time() );
			}

			// Consent ledger rows — one per channel the caller provided.
			// The channel key (email|sms) doubles as the column value to
			// keep the call sites symmetrical.
			foreach ( $consents as $channel => $payload ) {
				if ( ! is_array( $payload ) ) {
					continue;
				}

				$state = isset( $payload['state'] ) ? (string) $payload['state'] : 'opt_in';
				$args  = $payload;
				unset( $args['state'] );

				$result = Orbit_Consent::record( $user_id, $channel, $state, $args );
				if ( is_wp_error( $result ) ) {
					throw new Orbit_Rolled_Back_Exception( $result );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Orbit_Rolled_Back_Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			// Evict the Orbit_Notifier preferences cache if a row was
			// inserted before the throw. get_or_create_preferences()
			// populates the static cache immediately after the INSERT —
			// after ROLLBACK the row is gone but the cache entry would
			// otherwise survive the request and serve a phantom hit on
			// any retry. Guarded so we don't pass a WP_Error from a
			// failed wp_insert_user() through the int cast.
			if ( is_int( $user_id ) && $user_id > 0 ) {
				Orbit_Notifier::forget_preferences( $user_id );
			}

			return $e->wp_error;
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			if ( is_int( $user_id ) && $user_id > 0 ) {
				Orbit_Notifier::forget_preferences( $user_id );
			}

			// Defensive: a non-Orbit exception escaped from a helper
			// (unlikely in steady state). Surface as a generic WP_Error
			// so callers don't have to special-case throwables in their
			// error-mapping switch.
			return new WP_Error(
				'orbit_provisioning_unexpected_failure',
				$e->getMessage()
			);
		}

		// Side effects below run after COMMIT — they can't be rolled
		// back, and we want them to fire only on the happy path. The
		// welcome email's enqueue is governed by `$opts`; auth cookie
		// is intentionally NOT touched here (the REST handlers set it
		// after this returns; CLI has no session).
		if ( $opts['send_welcome_email'] ) {
			if ( $opts['schedule_welcome_async'] && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time(),
					'orbit_send_new_user_notification',
					array( 'user_id' => (int) $user_id ),
					'orbit'
				);
			} else {
				// Sync fallback: AS not loaded (shouldn't happen in
				// production) or caller explicitly opted out of async.
				wp_send_new_user_notifications( (int) $user_id, 'user' );
			}
		}

		return (int) $user_id;
	}

	/**
	 * Helper for the retry-loop exhaustion branch.
	 *
	 * Issues ROLLBACK and returns a soft-failure WP_Error so the
	 * outer try block doesn't have to remember the rollback statement
	 * itself. Kept private — this is an internal control-flow detail
	 * of `create_user_with_consent()`, not a general utility.
	 *
	 * @param WP_Error $last_error The WP_Error from the final retry attempt.
	 * @return WP_Error
	 */
	private static function rollback_and_return_soft_failure( WP_Error $last_error ) {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );

		return new WP_Error(
			'user_creation_failed',
			__( "We couldn't create the account right now. Please try again in a moment.", 'orbit' ),
			array(
				'status'     => 503,
				'inner_code' => $last_error->get_error_code(),
			)
		);
	}
}
