<?php
/**
 * REST API subscription controller.
 *
 * Handles subscribe, unsubscribe, subscriptions, subscribers,
 * subscriber management, and notification preferences.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_Subscription
 */
class Orbit_REST_Subscription {

	/**
	 * Register subscription-related routes.
	 */
	public static function register_routes() {
		$ns = Orbit_REST_API::NAMESPACE;

		register_rest_route(
			$ns,
			'/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'share_token'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'           => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'display_name'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'connection_note' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/unsubscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_unsubscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_subscriptions' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$ns,
			'/subscribers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_subscribers' ),
				'permission_callback' => array( __CLASS__, 'can_manage_subscribers' ),
				'args'                => array(
					'status' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/subscribers/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_subscriber' ),
				'permission_callback' => array( __CLASS__, 'can_manage_subscribers' ),
				'args'                => array(
					'action' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/preferences',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_preferences' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'tier1_method'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'tier2_method'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'tier3_method'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'sms_daily_cap' => array(),
					'digest_time'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Handle subscription request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_subscribe( $request ) {
		// Rate limit: 5 subscription attempts per hour per IP.
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( $ip && ! Orbit_Rate_Limiter::attempt( 'subscribe', $ip, 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many subscription attempts. Please try again later.', 'orbit' ), array( 'status' => 429 ) );
		}

		$share_token     = $request->get_param( 'share_token' );
		$email           = $request->get_param( 'email' );
		$display_name    = $request->get_param( 'display_name' );
		$connection_note = $request->get_param( 'connection_note' );

		// Validate email.
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Find profile by share token.
		$profile = Orbit_Profile::get_by_share_token( $share_token );
		if ( ! $profile ) {
			return new WP_Error( 'invalid_token', __( 'Invalid share token.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Check for existing WordPress account.
		$existing_user = get_user_by( 'email', $email );

		if ( $existing_user ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'login_required',
					__( 'An account with this email already exists. Please log in first.', 'orbit' ),
					array(
						'status'    => 409,
						'login_url' => wp_login_url( home_url( '/@' . $profile->slug . '/subscribe?token=' . $share_token ) ),
					)
				);
			}
			$user_id = $existing_user->ID;
		} else {
			// Create new WordPress user.
			$username = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ) . wp_rand( 100, 999 ) );
			$password = wp_generate_password();

			$user_id = wp_create_user( $username, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
			}

			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( $display_name ),
				)
			);

			// Assign subscriber role.
			$user = get_userdata( $user_id );
			$user->add_role( 'orbit_subscriber' );

			// Set default timezone.
			update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );
		}

		// Create subscription.
		$subscription_id = Orbit_Subscription::subscribe(
			array(
				'user_id'         => $user_id,
				'profile_id'      => $profile->id,
				'connection_note' => $connection_note,
			)
		);

		if ( is_wp_error( $subscription_id ) ) {
			return new WP_Error( $subscription_id->get_error_code(), $subscription_id->get_error_message(), array( 'status' => 400 ) );
		}

		// Create default notification preferences.
		Orbit_Notifier::get_or_create_preferences( $user_id );

		$subscription = Orbit_Subscription::get( $subscription_id );

		return new WP_REST_Response(
			array(
				'id'      => $subscription_id,
				'status'  => $subscription->status,
				'message' => 'approved' === $subscription->status
					? __( 'You are now subscribed!', 'orbit' )
					: __( 'Your subscription request has been sent for approval.', 'orbit' ),
			),
			201
		);
	}

	/**
	 * Handle unsubscribe (no auth, via subscription secret).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_unsubscribe( $request ) {
		$token = $request->get_param( 'token' );

		$subscription = Orbit_Subscription::get_by_secret( $token );
		if ( ! $subscription ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'orbit' ), array( 'status' => 400 ) );
		}

		$result = Orbit_Subscription::unsubscribe( $subscription->id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'status'  => 'unsubscribed',
				'message' => __( 'You have been unsubscribed.', 'orbit' ),
			),
			200
		);
	}

	/**
	 * Get current user's subscriptions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_subscriptions( $request ) {
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => get_current_user_id(),
				'per_page' => 100,
			)
		);

		$subscriptions = array_map( array( __CLASS__, 'shape_subscription' ), $subscriptions );

		return new WP_REST_Response( $subscriptions, 200 );
	}

	/**
	 * Get subscribers for the poster's profile.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_subscribers( $request ) {
		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return new WP_REST_Response( array(), 200 );
		}

		$subscribers = Orbit_Subscription::list(
			array(
				'profile_id' => $profile->id,
				'status'     => $request->get_param( 'status' ),
				'per_page'   => 100,
			)
		);

		$subscribers = array_map( array( __CLASS__, 'shape_subscription' ), $subscribers );

		return new WP_REST_Response( $subscribers, 200 );
	}

	/**
	 * Update a subscriber (approve/deny/remove).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function update_subscriber( $request ) {
		$id     = $request->get_param( 'id' );
		$action = $request->get_param( 'action' );

		$subscription = Orbit_Subscription::get( $id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'orbit' ), array( 'status' => 404 ) );
		}

		// Verify poster owns this subscription's profile.
		$profile = Orbit_Profile::get( $subscription->profile_id );
		if ( ! $profile || (int) $profile->user_id !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'Not authorized.', 'orbit' ), array( 'status' => 403 ) );
		}

		switch ( $action ) {
			case 'approve':
				$result = Orbit_Subscription::approve( $id );
				break;
			case 'deny':
				$result = Orbit_Subscription::deny( $id );
				break;
			case 'remove':
				$result = Orbit_Subscription::remove( $id );
				break;
			default:
				return new WP_Error( 'invalid_action', __( 'Action must be approve, deny, or remove.', 'orbit' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( self::shape_subscription( Orbit_Subscription::get( $id ) ), 200 );
	}

	/**
	 * Update notification preferences.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function update_preferences( $request ) {
		$user_id = get_current_user_id();

		$result = Orbit_Notifier::update_preferences( $user_id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		$prefs = Orbit_Notifier::get_or_create_preferences( $user_id );

		return new WP_REST_Response( $prefs, 200 );
	}

	/**
	 * Check if the current user can manage subscribers.
	 *
	 * @return bool True if authorized.
	 */
	public static function can_manage_subscribers() {
		return is_user_logged_in() && current_user_can( 'orbit_manage_subscribers' );
	}

	/**
	 * Strip sensitive fields from a subscription object before returning via API.
	 *
	 * @param object $sub Subscription row object.
	 * @return object Subscription without secret fields.
	 */
	private static function shape_subscription( $sub ) {
		$shaped = clone $sub;
		unset( $shaped->subscription_secret );
		return $shaped;
	}
}
