<?php
/**
 * REST API profile controller.
 *
 * Handles admin profile CRUD, token regeneration, and system status.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_REST_Profile
 */
class Orbit_REST_Profile {

	/**
	 * Register profile-related routes.
	 */
	public static function register_routes() {
		$ns = Orbit_REST_API::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/profiles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_profiles' ),
					'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_profile' ),
					'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
					'args'                => array(
						'user_id'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						'slug'             => array( 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
						'display_name'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'bio'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'require_approval' => array( 'required' => false, 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/profiles/me',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_own_profile' ),
				'permission_callback' => function() {
					return current_user_can( 'orbit_subscribe' );
				},
				'args'                => array(
					'slug'             => array( 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
					'display_name'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'bio'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'require_approval' => array( 'required' => false, 'default' => true ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/profiles/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update_profile' ),
					'permission_callback' => array( __CLASS__, 'can_manage_profile_or_admin' ),
					'args'                => array(
						'display_name'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'slug'             => array( 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
						'bio'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'require_approval' => array( 'required' => false ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_profile' ),
					'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/profiles/(?P<id>\d+)/regenerate-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'regenerate_token' ),
				'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( 'Orbit_REST_API', 'is_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/me/dismiss-onboarding-banner',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dismiss_onboarding_banner' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * Mark the dashboard onboarding banner as dismissed for the current
	 * user. Stored in user_meta so the dismissal is persistent and
	 * specific to the user.
	 *
	 * @return WP_REST_Response
	 */
	public static function dismiss_onboarding_banner() {
		update_user_meta( get_current_user_id(), 'orbit_dashboard_banner_dismissed', 1 );
		return new WP_REST_Response( array( 'dismissed' => true ), 200 );
	}

	/**
	 * List all profiles.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public static function list_profiles( $request ) {
		$profiles = Orbit_Profile::list(
			array(
				'per_page' => $request->get_param( 'per_page' ) ?: 50,
				'page'     => $request->get_param( 'page' ) ?: 1,
			)
		);

		return new WP_REST_Response( $profiles, 200 );
	}

	/**
	 * Create a profile.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function create_profile( $request ) {
		$profile_id = Orbit_Profile::create( $request->get_params() );

		if ( is_wp_error( $profile_id ) ) {
			return new WP_Error( $profile_id->get_error_code(), $profile_id->get_error_message(), array( 'status' => 400 ) );
		}

		Orbit_Roles::upgrade_to_poster( $request->get_param( 'user_id' ) );

		return new WP_REST_Response( Orbit_Profile::get( $profile_id ), 201 );
	}

	/**
	 * Create a profile for the current logged-in user (self-service).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function create_own_profile( $request ) {
		$user_id = get_current_user_id();

		$existing = Orbit_Profile::get_by_user_id( $user_id );
		if ( $existing ) {
			return new WP_Error( 'profile_exists', __( 'You already have a profile.', 'orbit' ), array( 'status' => 400 ) );
		}

		$profile_id = Orbit_Profile::create(
			array(
				'user_id'          => $user_id,
				'slug'             => $request->get_param( 'slug' ),
				'display_name'     => $request->get_param( 'display_name' ),
				'bio'              => $request->get_param( 'bio' ),
				'require_approval' => $request->get_param( 'require_approval' ),
			)
		);

		if ( is_wp_error( $profile_id ) ) {
			return new WP_Error( $profile_id->get_error_code(), $profile_id->get_error_message(), array( 'status' => 400 ) );
		}

		Orbit_Roles::upgrade_to_poster( $user_id );

		return new WP_REST_Response( Orbit_Profile::get( $profile_id ), 201 );
	}

	/**
	 * Update a profile.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function update_profile( $request ) {
		$id      = $request->get_param( 'id' );
		$allowed = array( 'display_name', 'slug', 'bio', 'require_approval' );
		$args    = array_intersect_key( $request->get_params(), array_flip( $allowed ) );
		unset( $args['id'] );

		$result = Orbit_Profile::update( $id, $args );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( Orbit_Profile::get( $id ), 200 );
	}

	/**
	 * Delete a profile.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function delete_profile( $request ) {
		$result = Orbit_Profile::delete( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Regenerate a profile's share token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function regenerate_token( $request ) {
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
	 * Get system status.
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

		$twilio_configured = defined( 'ORBIT_TWILIO_ACCOUNT_SID' ) && defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) && defined( 'ORBIT_TWILIO_FROM_NUMBER' );

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
	 * Check if the current user is the profile owner or an admin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if authorized.
	 */
	public static function can_manage_profile_or_admin( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'orbit_admin' ) ) {
			return true;
		}

		$profile = Orbit_Profile::get( $request->get_param( 'id' ) );

		return $profile && (int) $profile->user_id === get_current_user_id();
	}
}
