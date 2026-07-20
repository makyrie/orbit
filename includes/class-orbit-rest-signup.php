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

		// Cache the disclosure the user agreed to. Stored verbatim on
		// each consent ledger row — see Orbit_Compliance_UI::compliance_
		// disclosure_text() for the canonical source.
		$cta_snapshot = Orbit_Compliance_UI::compliance_disclosure_text();

		// Build a unique username from the display name. Same shape as
		// the subscribe flow: lowercased, spaces stripped, then a random
		// 5-digit suffix. Username collisions are likely on multisite
		// where usernames are network-wide; the provisioning service's
		// retry loop closes the race between username_exists() and
		// wp_insert_user() when two requests share a base.
		$base     = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ), true );
		$base     = '' !== $base ? $base : 'orbit-user';
		$username = $base . wp_rand( 10000, 99999 );

		// Hand off the full transactional envelope to the provisioning
		// service: wp_insert_user (with retry-on-collision), multisite
		// role attach, timezone + optional pending-phone meta, email +
		// optional sms consent rows, all inside one START TRANSACTION
		// / COMMIT — or ROLLBACK with the original WP_Error preserved.
		// The service does NOT touch the auth cookie or the welcome
		// email synchronization; both are the controller's job below.
		$consents = array(
			'email' => array(
				'state'        => 'opt_in',
				'source'       => 'signup',
				'cta_snapshot' => $cta_snapshot,
			),
		);
		if ( $consent_sms ) {
			$consents['sms'] = array(
				'state'        => 'opt_in',
				'source'       => 'signup',
				'cta_snapshot' => $cta_snapshot,
			);
		}

		$user_id = Orbit_User_Provisioning::create_user_with_consent(
			array(
				'user_login'              => $username,
				'user_email'              => $email,
				'display_name'            => $display_name,
				// orbit_subscriber (NOT core 'subscriber') carries the
				// orbit_subscribe capability the profile-creation gate
				// (POST /orbit/v1/profiles/me) requires; core subscriber
				// does not, so a signup poster would otherwise be stuck at
				// rest_forbidden and never reach upgrade_to_poster(). See #54.
				'role'                    => 'orbit_subscriber',
				'phone_pending'           => $phone,
				'username_retry_attempts' => 5,
			),
			$consents,
			array(
				// REST handler defers the welcome email via ActionScheduler
				// after we return — keep the service quiet here so the
				// auth-cookie write happens FIRST and we keep the same
				// observable ordering as the legacy handler.
				'send_welcome_email' => false,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$inner_code = (string) $user_id->get_error_code();

			// Log the full inner error server-side so operators can still
			// see the raw failure (MySQL fragment, third-party hook string,
			// etc.) without exposing it to the anonymous caller.
			error_log(
				sprintf(
					'[orbit] signup rolled back: code=%s message=%s data=%s',
					$inner_code,
					$user_id->get_error_message(),
					wp_json_encode( $user_id->get_error_data() )
				)
			);

			// Retry-loop exhaustion: the service returns a 503-tagged
			// WP_Error with code `user_creation_failed`. Forward as-is.
			$data = $user_id->get_error_data();
			if ( 'user_creation_failed' === $inner_code && is_array( $data ) && isset( $data['status'] ) ) {
				return $user_id;
			}

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
			Orbit_User_Notifications::send_new_user_notification( $user_id );
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
