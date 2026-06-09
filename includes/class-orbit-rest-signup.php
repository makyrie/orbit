<?php
/**
 * REST API account-signup controller.
 *
 * Powers the `[orbit_sign_up]` form on the marketing surface. Creates
 * a WordPress user account, attaches it to the current site (matters
 * on multisite installs — `wp_create_user` alone produces a network
 * user with no role on this sub-site), auto-logs the user in, and
 * emails them WordPress's standard "your account has been created"
 * link so they can come back later to set a permanent password.
 *
 * Designed as an alternative to `users_can_register=1` + wp-signup.php
 * for sites that want a branded, single-step sign-up without enabling
 * registration network-wide.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_Signup
 */
class Orbit_REST_Signup {

	/**
	 * Register the signup route.
	 */
	public static function register_routes() {
		$ns = Orbit_REST_API::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/signup',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_signup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'display_name'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'         => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'         => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_email' => array(
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'consent_sms'   => array(
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Handle a sign-up POST.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_signup( $request ) {
		// Honeypot + timestamp check.
		$trap_error = Orbit_Spam::check_traps( $request->get_params() );
		if ( is_wp_error( $trap_error ) ) {
			return new WP_Error(
				$trap_error->get_error_code(),
				$trap_error->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Rate limit: 5 signup attempts per hour per IP — same envelope
		// as subscribe, since both create WP user accounts and we don't
		// want either to be a spray-target.
		$ip = Orbit_Client_IP::get();
		if ( $ip && ! Orbit_Rate_Limiter::attempt( 'signup', $ip, 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many sign-up attempts. Please try again later.', 'orbit' ),
				array( 'status' => 429 )
			);
		}

		// Already logged in? Bounce them where they were going.
		if ( is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'status'       => 'already_signed_in',
					'message'      => __( "You're already signed in.", 'orbit' ),
					'redirect_url' => home_url( '/edit-profile/' ),
				),
				200
			);
		}

		$display_name  = $request->get_param( 'display_name' );
		$email         = $request->get_param( 'email' );
		$phone         = trim( (string) $request->get_param( 'phone' ) );
		$consent_email = (bool) $request->get_param( 'consent_email' );
		$consent_sms   = (bool) $request->get_param( 'consent_sms' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'orbit' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( (string) $display_name ) ) {
			return new WP_Error( 'invalid_name', __( 'Please enter your name.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Email consent is required to create an account that we'd
		// otherwise send a password-set email to.
		if ( ! $consent_email ) {
			return new WP_Error( 'consent_required', __( 'You must agree to receive notifications by email to create an account.', 'orbit' ), array( 'status' => 400 ) );
		}

		if ( '' !== $phone && ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			return new WP_Error( 'invalid_phone', __( 'Phone number must be in E.164 format, like +12025550123.', 'orbit' ), array( 'status' => 400 ) );
		}

		if ( $consent_sms && '' === $phone ) {
			return new WP_Error( 'consent_sms_without_phone', __( 'To opt in to SMS, please provide a phone number.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Email collision → tell the user clearly and offer a login link.
		// Deliberate enumeration tradeoff: UX > marginal privacy benefit on
		// an invite-driven product where account existence is low-value info.
		if ( get_user_by( 'email', $email ) ) {
			return new WP_Error(
				'login_required',
				__( 'An account with this email already exists. Try logging in instead.', 'orbit' ),
				array(
					'status'    => 409,
					'login_url' => wp_login_url( home_url( '/edit-profile/' ) ),
				)
			);
		}

		global $wpdb;

		// Cache the disclosure the user agreed to. Stored verbatim on
		// each consent ledger row — see Orbit_Shortcodes::compliance_
		// disclosure_text() for the canonical source.
		$cta_snapshot = Orbit_Shortcodes::compliance_disclosure_text();

		// Build a unique username from the display name. Same shape as
		// the subscribe flow: lowercased, spaces stripped, then a random
		// 5-digit suffix. Username collisions are likely on multisite
		// where usernames are network-wide; the post-check retry loop
		// below also closes the race between username_exists() and
		// wp_insert_user() when two requests share a base.
		$base     = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ), true );
		$base     = '' !== $base ? $base : 'orbit-user';
		$username = $base . wp_rand( 10000, 99999 );

		// Wrap user creation + ms-attach + meta + consent rows in a
		// transaction so a partial failure can't leave a half-created
		// account with no audit trail (or vice versa). Auth-cookie setup
		// and the password-set email are deferred until after COMMIT —
		// they can't be rolled back and we only want them on the happy
		// path.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$attempts = 0;
			$user_id  = null;
			do {
				$user_id = wp_insert_user(
					array(
						'user_login'   => $username,
						'user_pass'    => wp_generate_password(),
						'user_email'   => $email,
						'display_name' => $display_name,
						'role'         => 'subscriber',
					)
				);

				if ( ! is_wp_error( $user_id ) ) {
					break;
				}

				if ( 'existing_user_login' !== $user_id->get_error_code() ) {
					// Forward the structured WP_Error through the carrier
					// exception so the catch can branch on the original
					// code (e.g. surface `existing_user_email` race losers
					// as 409 login_required — see todo 127).
					throw new Orbit_Rolled_Back_Exception( $user_id );
				}

				$username = $base . wp_rand( 10000, 99999 );
				++$attempts;
			} while ( $attempts < 5 );

			if ( is_wp_error( $user_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'user_creation_failed',
					__( "We couldn't create your account right now. Please try again in a moment.", 'orbit' ),
					array( 'status' => 503 )
				);
			}

			// On multisite, wp_insert_user creates a network user with no
			// role on the current sub-site — add_user_to_blog() attaches
			// the subscriber role here. On single-site the function isn't
			// loaded (ms-functions.php is multisite-only), and
			// wp_insert_user has already set the role globally, so the
			// call is unnecessary.
			if ( is_multisite() ) {
				add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
			}

			update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );

			if ( '' !== $phone ) {
				// Pair the pending phone with an explicit timestamp so the
				// daily GC cron (Orbit_Notifier::cleanup_pending_phones())
				// can reap abandoned signups — usermeta has no native
				// updated_at, so this companion key is required.
				update_user_meta( $user_id, 'orbit_phone_pending', $phone );
				update_user_meta( $user_id, 'orbit_phone_pending_at', time() );
			}

			$email_consent = Orbit_Consent::record(
				$user_id,
				'email',
				'opt_in',
				array(
					'source'       => 'signup',
					'cta_snapshot' => $cta_snapshot,
				)
			);
			if ( is_wp_error( $email_consent ) ) {
				throw new Orbit_Rolled_Back_Exception( $email_consent );
			}

			if ( $consent_sms ) {
				$sms_consent = Orbit_Consent::record(
					$user_id,
					'sms',
					'opt_in',
					array(
						'source'       => 'signup',
						'cta_snapshot' => $cta_snapshot,
					)
				);
				if ( is_wp_error( $sms_consent ) ) {
					throw new Orbit_Rolled_Back_Exception( $sms_consent );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Orbit_Rolled_Back_Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			$inner_error = $e->wp_error;
			$inner_code  = (string) $inner_error->get_error_code();

			// Log the full inner error server-side so operators can still
			// see the raw failure (MySQL fragment, third-party hook string,
			// etc.) without exposing it to the anonymous caller.
			error_log(
				sprintf(
					'[orbit] signup rolled back: code=%s message=%s data=%s',
					$inner_code,
					$inner_error->get_error_message(),
					wp_json_encode( $inner_error->get_error_data() )
				)
			);

			// Email race: a concurrent signup grabbed the same email
			// between the upfront `get_user_by('email')` check and
			// `wp_insert_user`. Surface the same 409 login_required UX
			// as the steady-state duplicate path (see todo 127).
			if ( 'existing_user_email' === $inner_code ) {
				return new WP_Error(
					'login_required',
					__( 'An account with this email already exists. Try logging in instead.', 'orbit' ),
					array(
						'status'    => 409,
						'login_url' => wp_login_url( home_url( '/edit-profile/' ) ),
					)
				);
			}

			// Anything else: preserve the original failure code so the
			// client can branch on it, but substitute a generic, translated
			// user-facing message — raw MySQL / hook strings must not
			// reach an anonymous REST response (see todo 116).
			return new WP_Error(
				$inner_code,
				__( "We couldn't complete your sign-up. Please try again in a moment.", 'orbit' ),
				array( 'status' => 500 )
			);
		} catch ( Throwable $e ) {
			// Defensive: a non-Orbit exception escaped from a helper
			// (unlikely in steady state). Same response shape but with
			// a generic code since we have no structured WP_Error to
			// preserve.
			$wpdb->query( 'ROLLBACK' );
			error_log( '[orbit] signup unexpected throwable: ' . $e->getMessage() );
			return new WP_Error(
				'signup_failed',
				__( "We couldn't complete your sign-up. Please try again in a moment.", 'orbit' ),
				array( 'status' => 500 )
			);
		}

		// Side effects below run after COMMIT — they can't be rolled back.
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Defer the welcome email so SMTP latency doesn't block the HTTP
		// response (see todo 119). The job runs on the next AS tick; if
		// ActionScheduler somehow isn't loaded, fall back to the sync
		// path so users still get their password-set link.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				'orbit_send_new_user_notification',
				array( 'user_id' => $user_id ),
				'orbit'
			);
		} else {
			// Fallback: AS not loaded — should not happen in production.
			wp_send_new_user_notifications( $user_id, 'user' );
		}

		return new WP_REST_Response(
			array(
				'status'       => 'created',
				'user_id'      => $user_id,
				'message'      => __( "Account created. Check your email for a link to set your password — but you can keep going now.", 'orbit' ),
				// JS will use this to forward the new user to the next
				// step, where they pick a slug and bio.
				'redirect_url' => home_url( '/edit-profile/' ),
			),
			201
		);
	}
}
