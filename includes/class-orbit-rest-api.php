<?php
/**
 * REST API endpoint registration.
 *
 * All endpoints under /wp-json/orbit/v1/.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_API
 */
class Orbit_REST_API {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'orbit/v1';

	/**
	 * Register all REST API routes.
	 */
	public static function register_routes() {
		// Public endpoints (no auth required).
		register_rest_route(
			self::NAMESPACE,
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
			self::NAMESPACE,
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
			self::NAMESPACE,
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
			self::NAMESPACE,
			'/twilio/incoming',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_twilio_incoming' ),
				'permission_callback' => '__return_true',
			)
		);

		// Authenticated endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/verify-phone',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_verify_phone' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'phone' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
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
						'location_name'    => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'location_address' => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'date_time'        => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date_flexible'    => array(
							'required'          => false,
							'default'           => false,
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
			self::NAMESPACE,
			'/activities/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update_activity' ),
					'permission_callback' => array( __CLASS__, 'can_manage_activity' ),
					'args'                => array(
						'title'          => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'description'    => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
						'location_name'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'location_address' => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
						'date_time'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'date_flexible'  => array(),
						'show_attendees' => array( 'sanitize_callback' => 'sanitize_text_field' ),
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
			self::NAMESPACE,
			'/activities/(?P<id>\d+)/responses',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_activity_responses' ),
				'permission_callback' => array( __CLASS__, 'can_manage_activity' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_subscriptions' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NAMESPACE,
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
			self::NAMESPACE,
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
			self::NAMESPACE,
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

		register_rest_route(
			self::NAMESPACE,
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

		// Admin endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/profiles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'admin_list_profiles' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'admin_create_profile' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
					'args'                => array(
						'user_id'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						'slug'             => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'display_name'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'bio'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'require_approval' => array( 'required' => false, 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/profiles/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'admin_update_profile' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'admin_delete_profile' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/profiles/(?P<id>\d+)/regenerate-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_regenerate_token' ),
				'permission_callback' => array( __CLASS__, 'is_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'is_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_notifications' ),
				'permission_callback' => array( __CLASS__, 'is_admin' ),
				'args'                => array(
					'user_id' => array( 'sanitize_callback' => 'absint' ),
					'method'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'status'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	// =========================================================================
	// Public endpoint handlers
	// =========================================================================

	/**
	 * Handle subscription request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_subscribe( $request ) {
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
	 * Handle response submission (via action token or logged-in user).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_respond( $request ) {
		$activity_id = $request->get_param( 'activity_id' );
		$response    = $request->get_param( 'response' );
		$act_token   = $request->get_param( 'act' );

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'orbit' ), array( 'status' => 404 ) );
		}

		$subscription = null;

		if ( $act_token ) {
			// Validate action token — find subscription by trying all approved subscriptions.
			$subscriptions = Orbit_Subscription::list(
				array(
					'profile_id' => $activity->profile_id,
					'status'     => 'approved',
					'per_page'   => 9999,
				)
			);

			foreach ( $subscriptions as $sub ) {
				if ( Orbit_Token::validate_action_token( $act_token, $sub->subscription_secret, $activity_id ) ) {
					$subscription = $sub;
					break;
				}
			}

			if ( ! $subscription ) {
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
	 * Handle incoming Twilio webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_twilio_incoming( $request ) {
		if ( ! Orbit_Twilio::validate_webhook( $request ) ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid webhook signature.', 'orbit' ), array( 'status' => 403 ) );
		}

		$result = Orbit_Twilio::handle_incoming( $request );

		// Twilio expects TwiML response.
		return new WP_REST_Response( $result, 200 );
	}

	// =========================================================================
	// Authenticated endpoint handlers
	// =========================================================================

	/**
	 * Handle phone verification (send code or verify code).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function handle_verify_phone( $request ) {
		$user_id = get_current_user_id();
		$phone   = $request->get_param( 'phone' );
		$code    = $request->get_param( 'code' );

		if ( $phone ) {
			// Send verification code.
			$result = Orbit_Phone_Verify::send_code( $user_id, $phone );

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
			}

			return new WP_REST_Response(
				array( 'message' => __( 'Verification code sent.', 'orbit' ) ),
				200
			);
		}

		if ( $code ) {
			// Verify code.
			$result = Orbit_Phone_Verify::verify_code( $user_id, $code );

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
			}

			return new WP_REST_Response(
				array(
					'verified' => true,
					'message'  => __( 'Phone number verified.', 'orbit' ),
				),
				200
			);
		}

		return new WP_Error( 'missing_params', __( 'Provide either phone or code.', 'orbit' ), array( 'status' => 400 ) );
	}

	/**
	 * Get activities for the logged-in subscriber.
	 *
	 * Scoped to profiles the user is approved for.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_activities( $request ) {
		$user_id    = get_current_user_id();
		$profile_id = $request->get_param( 'profile_id' );

		// If a profile_id is specified, verify the user is an approved subscriber.
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

		// No profile_id — return activities from all approved subscriptions.
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);

		// Also include poster's own profile.
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

		// Fetch activities from all subscribed profiles.
		$all_activities = array();
		foreach ( $profile_ids as $pid ) {
			$activities = Orbit_Activity::list(
				array(
					'profile_id' => $pid,
					'status'     => $request->get_param( 'status' ) ? $request->get_param( 'status' ) : 'active',
					'tier'       => $request->get_param( 'tier' ),
					'per_page'   => 50,
				)
			);
			$all_activities = array_merge( $all_activities, $activities );
		}

		// Sort by created_at descending.
		usort( $all_activities, function ( $a, $b ) {
			return strcmp( $b->created_at, $a->created_at );
		} );

		// Apply pagination.
		$per_page = $request->get_param( 'per_page' ) ?: 20;
		$page     = $request->get_param( 'page' ) ?: 1;
		$offset   = ( $page - 1 ) * $per_page;

		$all_activities = array_slice( $all_activities, $offset, $per_page );

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
				'location_name'    => $request->get_param( 'location_name' ),
				'location_address' => $request->get_param( 'location_address' ),
				'date_time'        => $request->get_param( 'date_time' ),
				'date_flexible'    => $request->get_param( 'date_flexible' ),
				'show_attendees'   => $request->get_param( 'show_attendees' ),
			)
		);

		if ( is_wp_error( $activity_id ) ) {
			return new WP_Error( $activity_id->get_error_code(), $activity_id->get_error_message(), array( 'status' => 400 ) );
		}

		// Dispatch notifications.
		Orbit_Notifier::dispatch_for_activity( $activity_id );

		$activity = Orbit_Activity::get( $activity_id );

		return new WP_REST_Response( $activity, 201 );
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

		return new WP_REST_Response( Orbit_Subscription::get( $id ), 200 );
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

	// =========================================================================
	// Admin endpoint handlers
	// =========================================================================

	/**
	 * List all profiles (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function admin_list_profiles( $request ) {
		$profiles = Orbit_Profile::list(
			array(
				'per_page' => $request->get_param( 'per_page' ) ?: 50,
				'page'     => $request->get_param( 'page' ) ?: 1,
			)
		);

		return new WP_REST_Response( $profiles, 200 );
	}

	/**
	 * Create a profile (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function admin_create_profile( $request ) {
		$profile_id = Orbit_Profile::create( $request->get_params() );

		if ( is_wp_error( $profile_id ) ) {
			return new WP_Error( $profile_id->get_error_code(), $profile_id->get_error_message(), array( 'status' => 400 ) );
		}

		// Upgrade user to poster role.
		Orbit_Roles::upgrade_to_poster( $request->get_param( 'user_id' ) );

		return new WP_REST_Response( Orbit_Profile::get( $profile_id ), 201 );
	}

	/**
	 * Update a profile (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function admin_update_profile( $request ) {
		$id   = $request->get_param( 'id' );
		$args = $request->get_params();

		unset( $args['id'] );

		$result = Orbit_Profile::update( $id, $args );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( Orbit_Profile::get( $id ), 200 );
	}

	/**
	 * Delete a profile (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function admin_delete_profile( $request ) {
		$result = Orbit_Profile::delete( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Regenerate a profile's share token (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function admin_regenerate_token( $request ) {
		$result = Orbit_Profile::regenerate_token( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'share_token' => $result,
				'message'     => __( 'Share token regenerated.', 'orbit' ),
			),
			200
		);
	}

	/**
	 * Get system status (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_status( $request ) {
		global $wpdb;

		$profiles_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES );
		$activities_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES );
		$subscriptions_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS );
		$responses_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_RESPONSES );

		$active_activities = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . ORBIT_TABLE_ACTIVITIES . " WHERE status = 'active'"
		);

		$pending_subscriptions = Orbit_Subscription::count( array( 'status' => 'pending' ) );

		$twilio_configured = defined( 'ORBIT_TWILIO_SID' ) && defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) && defined( 'ORBIT_TWILIO_FROM' );

		return new WP_REST_Response(
			array(
				'version'               => ORBIT_VERSION,
				'profiles'              => $profiles_count,
				'activities'            => $activities_count,
				'active_activities'     => $active_activities,
				'subscriptions'         => $subscriptions_count,
				'pending_subscriptions' => $pending_subscriptions,
				'responses'             => $responses_count,
				'twilio_configured'     => $twilio_configured,
				'action_scheduler'      => function_exists( 'as_has_scheduled_action' ),
			),
			200
		);
	}

	/**
	 * Get notification log (admin).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function get_notifications( $request ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$where  = array( '1=1' );
		$values = array();

		if ( $request->get_param( 'user_id' ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $request->get_param( 'user_id' ) );
		}

		if ( $request->get_param( 'method' ) ) {
			$where[]  = 'method = %s';
			$values[] = sanitize_text_field( $request->get_param( 'method' ) );
		}

		if ( $request->get_param( 'status' ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 100";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		$logs = $wpdb->get_results( $sql );

		return new WP_REST_Response( $logs, 200 );
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

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

	/**
	 * Check if the current user can manage subscribers.
	 *
	 * @return bool True if authorized.
	 */
	public static function can_manage_subscribers() {
		return is_user_logged_in() && current_user_can( 'orbit_manage_subscribers' );
	}

	/**
	 * Check if the current user is an admin.
	 *
	 * @return bool True if admin.
	 */
	public static function is_admin() {
		return is_user_logged_in() && current_user_can( 'orbit_admin' );
	}
}
