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
					'display_name' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
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

		$display_name = $request->get_param( 'display_name' );
		$email        = $request->get_param( 'email' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'orbit' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( (string) $display_name ) ) {
			return new WP_Error( 'invalid_name', __( 'Please enter your name.', 'orbit' ), array( 'status' => 400 ) );
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

		// Build a unique username from the display name. Same shape as
		// the subscribe flow: lowercased, spaces stripped, then a random
		// 5-digit suffix. Username collisions are likely on multisite
		// where usernames are network-wide; the post-check retry loop
		// below also closes the race between `username_exists()` and
		// `wp_insert_user()` when two requests share a base.
		$base     = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ), true );
		$base     = '' !== $base ? $base : 'orbit-user';
		$username = $base . wp_rand( 10000, 99999 );

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
				return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
			}

			$username = $base . wp_rand( 10000, 99999 );
			++$attempts;
		} while ( $attempts < 5 );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'user_creation_failed',
				__( "We couldn't create your account right now. Please try again in a moment.", 'orbit' ),
				array( 'status' => 503 )
			);
		}

		// On multisite, wp_insert_user creates a network user with no
		// role on the current sub-site — add_user_to_blog() attaches the
		// subscriber role here. On single-site the function isn't loaded
		// (ms-functions.php is multisite-only), and wp_insert_user has
		// already set the role globally, so the call is unnecessary.
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
		}

		// Default timezone — used when formatting "Subscribed Apr 17, 2026"
		// type display strings (the orbit_timezone meta would be set
		// elsewhere if we ever capture the user's actual TZ at signup).
		update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );

		// Auto-log in so the next page render shows the signed-in state.
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Send WP's standard "your account has been created" email with
		// a password-set link. Lets the user come back later if they
		// close the tab before finishing their profile.
		wp_send_new_user_notifications( $user_id, 'user' );

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
