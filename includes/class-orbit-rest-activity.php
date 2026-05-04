<?php
/**
 * REST API activity controller.
 *
 * Handles activity CRUD, responses, and response removal.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_Activity
 */
class Orbit_REST_Activity {

	/**
	 * Register activity-related routes.
	 */
	public static function register_routes() {
		$ns = Orbit_REST_API::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/respond',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_respond' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'activity_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'response'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'act'         => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/activities',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_activities' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'profile_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'status'     => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tier'       => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'per_page'   => array(
							'required'          => false,
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'       => array(
							'required'          => false,
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_activity' ),
					'permission_callback' => array( __CLASS__, 'can_create_activity' ),
					'args'                => array(
						'profile_id'       => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'tier'             => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'title'            => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'      => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'audience'         => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'location_name'    => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'location_address' => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'url'              => array(
							'required'          => false,
							'sanitize_callback' => 'esc_url_raw',
						),
						'date_time'        => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date_flexible'    => array(
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'show_attendees'   => array(
							'required'          => false,
							'default'           => 'count',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/activities/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update_activity' ),
					'permission_callback' => array( __CLASS__, 'can_manage_activity' ),
					'args'                => array(
						'title'            => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'description'      => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
						'audience'         => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
						'location_name'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'location_address' => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
						'url'              => array( 'sanitize_callback' => 'esc_url_raw' ),
						'date_time'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'date_flexible'    => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
						'show_attendees'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'cancel_activity' ),
					'permission_callback' => array( __CLASS__, 'can_manage_activity' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/activities/(?P<id>\d+)/responses',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_activity_responses' ),
				'permission_callback' => array( __CLASS__, 'can_manage_activity' ),
			)
		);

		register_rest_route(
			$ns,
			'/respond',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_remove_response' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'activity_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Handle response submission (via action token or logged-in user).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_respond( $request ) {
		// Rate limit unauthenticated response attempts.
		if ( ! is_user_logged_in() ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			if ( $ip && ! Orbit_Rate_Limiter::attempt( 'respond', $ip, 30, HOUR_IN_SECONDS ) ) {
				return new WP_Error( 'rate_limited', __( 'Too many response attempts. Please try again later.', 'orbit' ), array( 'status' => 429 ) );
			}
		}

		$activity_id = $request->get_param( 'activity_id' );
		$response    = $request->get_param( 'response' );
		$act_token   = $request->get_param( 'act' );

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'orbit' ), array( 'status' => 404 ) );
		}

		$subscription = null;

		if ( $act_token ) {
			// Extract subscription ID from token for O(1) lookup.
			$sub_id = Orbit_Token::extract_subscription_id( $act_token );
			if ( ! $sub_id ) {
				return new WP_Error( 'invalid_token', __( 'Invalid or expired action token.', 'orbit' ), array( 'status' => 403 ) );
			}

			$subscription = Orbit_Subscription::get( $sub_id );
			if ( ! $subscription || 'approved' !== $subscription->status || (int) $subscription->profile_id !== (int) $activity->profile_id ) {
				return new WP_Error( 'invalid_token', __( 'Invalid or expired action token.', 'orbit' ), array( 'status' => 403 ) );
			}

			if ( ! Orbit_Token::validate_action_token( $act_token, $subscription->subscription_secret, $activity_id ) ) {
				return new WP_Error( 'invalid_token', __( 'Invalid or expired action token.', 'orbit' ), array( 'status' => 403 ) );
			}
		} elseif ( is_user_logged_in() ) {
			$subscription = Orbit_Subscription::get_by_user_and_profile( get_current_user_id(), $activity->profile_id );

			if ( ! $subscription || 'approved' !== $subscription->status ) {
				return new WP_Error( 'not_subscribed', __( 'You must be an approved subscriber to respond.', 'orbit' ), array( 'status' => 403 ) );
			}
		} else {
			return new WP_Error( 'unauthorized', __( 'Authentication required.', 'orbit' ), array( 'status' => 401 ) );
		}

		$result = Orbit_Response::set(
			array(
				'activity_id'     => $activity_id,
				'subscription_id' => $subscription->id,
				'response'        => $response,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'id'       => $result,
				'response' => $response,
				'message'  => __( 'Response recorded.', 'orbit' ),
			),
			200
		);
	}

	/**
	 * Get activities for the logged-in subscriber.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_activities( $request ) {
		$user_id    = get_current_user_id();
		$profile_id = $request->get_param( 'profile_id' );

		if ( $profile_id ) {
			$subscription = Orbit_Subscription::get_by_user_and_profile( $user_id, $profile_id );
			$profile      = Orbit_Profile::get( $profile_id );
			$is_poster    = $profile && (int) $profile->user_id === $user_id;

			if ( ! $is_poster && ( ! $subscription || 'approved' !== $subscription->status ) ) {
				return new WP_REST_Response( array(), 200 );
			}

			$activities = Orbit_Activity::list(
				array(
					'profile_id' => $profile_id,
					'status'     => $request->get_param( 'status' ),
					'tier'       => $request->get_param( 'tier' ),
					'per_page'   => $request->get_param( 'per_page' ),
					'page'       => $request->get_param( 'page' ),
				)
			);

			return new WP_REST_Response( $activities, 200 );
		}

		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);

		$own_profile = Orbit_Profile::get_by_user_id( $user_id );

		$profile_ids = array_map( function ( $s ) {
			return (int) $s->profile_id;
		}, $subscriptions );

		if ( $own_profile ) {
			$profile_ids[] = (int) $own_profile->id;
		}

		if ( empty( $profile_ids ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$profile_ids    = array_unique( $profile_ids );
		$all_activities = Orbit_Activity::list_by_profile_ids(
			$profile_ids,
			array(
				'status'   => $request->get_param( 'status' ) ? $request->get_param( 'status' ) : 'active',
				'tier'     => $request->get_param( 'tier' ),
				'per_page' => $request->get_param( 'per_page' ) ?: 20,
				'page'     => $request->get_param( 'page' ) ?: 1,
			)
		);

		return new WP_REST_Response( $all_activities, 200 );
	}

	/**
	 * Create an activity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function create_activity( $request ) {
		$profile_id = $request->get_param( 'profile_id' );
		$profile    = Orbit_Profile::get( $profile_id );

		if ( ! $profile || (int) $profile->user_id !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'You can only create activities for your own profile.', 'orbit' ), array( 'status' => 403 ) );
		}

		$activity_id = Orbit_Activity::create(
			array(
				'profile_id'       => $profile_id,
				'tier'             => $request->get_param( 'tier' ),
				'title'            => $request->get_param( 'title' ),
				'description'      => $request->get_param( 'description' ),
				'audience'         => $request->get_param( 'audience' ),
				'location_name'    => $request->get_param( 'location_name' ),
				'location_address' => $request->get_param( 'location_address' ),
				'url'              => $request->get_param( 'url' ),
				'date_time'        => $request->get_param( 'date_time' ),
				'date_flexible'    => $request->get_param( 'date_flexible' ),
				'show_attendees'   => $request->get_param( 'show_attendees' ),
			)
		);

		if ( is_wp_error( $activity_id ) ) {
			return new WP_Error( $activity_id->get_error_code(), $activity_id->get_error_message(), array( 'status' => 400 ) );
		}

		Orbit_Notifier::dispatch_for_activity( $activity_id );

		return new WP_REST_Response( Orbit_Activity::get( $activity_id ), 201 );
	}

	/**
	 * Update an activity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function update_activity( $request ) {
		$id   = $request->get_param( 'id' );
		$args = $request->get_params();
		unset( $args['id'] );

		$result = Orbit_Activity::update( $id, $args );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( Orbit_Activity::get( $id ), 200 );
	}

	/**
	 * Cancel an activity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function cancel_activity( $request ) {
		$id     = $request->get_param( 'id' );
		$result = Orbit_Activity::cancel( $id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( Orbit_Activity::get( $id ), 200 );
	}

	/**
	 * Get responses for an activity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_activity_responses( $request ) {
		$id        = $request->get_param( 'id' );
		$responses = Orbit_Response::list_by_activity( $id );

		return new WP_REST_Response( $responses, 200 );
	}

	/**
	 * Remove a response (logged-in subscriber).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_remove_response( $request ) {
		$activity_id = $request->get_param( 'activity_id' );
		$user_id     = get_current_user_id();

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'orbit' ), array( 'status' => 404 ) );
		}

		$subscription = Orbit_Subscription::get_by_user_and_profile( $user_id, $activity->profile_id );
		if ( ! $subscription ) {
			return new WP_Error( 'not_subscribed', __( 'Not subscribed.', 'orbit' ), array( 'status' => 403 ) );
		}

		$response = Orbit_Response::get_by_activity_and_subscription( $activity_id, $subscription->id );
		if ( ! $response ) {
			return new WP_Error( 'no_response', __( 'No response to remove.', 'orbit' ), array( 'status' => 404 ) );
		}

		$result = Orbit_Response::remove( $response->id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'message' => __( 'Response removed.', 'orbit' ) ), 200 );
	}

	/**
	 * Check if the current user can create activities.
	 *
	 * @return bool True if authorized.
	 */
	public static function can_create_activity() {
		return is_user_logged_in() && current_user_can( 'orbit_create_activity' );
	}

	/**
	 * Check if the current user can manage a specific activity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if authorized.
	 */
	public static function can_manage_activity( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$activity = Orbit_Activity::get( $request->get_param( 'id' ) );
		if ( ! $activity ) {
			return false;
		}

		$profile = Orbit_Profile::get( $activity->profile_id );

		return $profile && ( (int) $profile->user_id === get_current_user_id() || current_user_can( 'orbit_admin' ) );
	}
}
