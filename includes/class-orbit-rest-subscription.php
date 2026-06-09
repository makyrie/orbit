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
		$ns = Orbit_REST_API::API_NAMESPACE;

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
					'phone'           => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_email'   => array(
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'consent_sms'     => array(
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
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
			'/subscriptions/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_own_subscription' ),
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
					'sms_daily_cap' => array(
						'sanitize_callback' => function ( $value ) {
							return null === $value ? null : absint( $value );
						},
					),
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
		global $wpdb;

		// Honeypot + timestamp check. Mirrors signup's first line of defense
		// so both account-creation endpoints share the same bot-rejection
		// envelope. Runs BEFORE the rate-limit check so trap-tripped requests
		// don't consume the legitimate 5/hr/IP budget.
		$trap_error = Orbit_Spam::check_traps( $request->get_params() );
		if ( is_wp_error( $trap_error ) ) {
			return new WP_Error(
				$trap_error->get_error_code(),
				$trap_error->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Rate limit: 5 subscription attempts per hour per IP.
		$ip = Orbit_Client_IP::get();
		if ( $ip && ! Orbit_Rate_Limiter::attempt( 'subscribe', $ip, 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many subscription attempts. Please try again later.', 'orbit' ), array( 'status' => 429 ) );
		}

		$share_token     = $request->get_param( 'share_token' );
		$email           = $request->get_param( 'email' );
		$display_name    = $request->get_param( 'display_name' );
		$connection_note = $request->get_param( 'connection_note' );
		$phone           = (string) $request->get_param( 'phone' );
		$consent_email   = (bool) $request->get_param( 'consent_email' );
		$consent_sms     = (bool) $request->get_param( 'consent_sms' );

		// Validate email.
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Email consent is required — the user can't reach this code path
		// via the form without checking the box, but a direct REST call
		// could bypass the UI.
		if ( ! $consent_email ) {
			return new WP_Error( 'consent_required', __( 'You must agree to receive notifications by email to subscribe.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Phone format. Accept a trimmed E.164 string ("+12025550100").
		// Empty is fine — phone is optional.
		$phone = trim( $phone );
		if ( '' !== $phone && ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			return new WP_Error( 'invalid_phone', __( 'Phone number must be in E.164 format, like +12025550123.', 'orbit' ), array( 'status' => 400 ) );
		}

		// SMS consent requires a phone — checking the box without one is
		// a UX bug worth surfacing rather than silently dropping.
		if ( $consent_sms && '' === $phone ) {
			return new WP_Error( 'consent_sms_without_phone', __( 'To opt in to SMS, please provide a phone number.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Find profile by share token.
		$profile = Orbit_Profile::get_by_share_token( $share_token );
		if ( ! $profile ) {
			return new WP_Error( 'invalid_token', __( 'Invalid share token.', 'orbit' ), array( 'status' => 400 ) );
		}

		// Check for existing WordPress account.
		$existing_user = get_user_by( 'email', $email );

		// Cache the disclosure shown to the user so the consent ledger
		// captures the exact wording they agreed to. The shortcode AND
		// this handler both call Orbit_Shortcodes::compliance_disclosure_text()
		// so they always agree byte-for-byte. The version is taken from
		// the published /privacy/ page's orbit_policy_version meta inside
		// Orbit_Consent::record() so we don't need to pass it here.
		$cta_snapshot = Orbit_Shortcodes::compliance_disclosure_text();

		// Defer non-DB side effects (auth cookies, password-set email)
		// until after the transaction commits — those can't be rolled back.
		$is_new_account            = false;
		$pending_auth_user_id      = 0;
		$pending_password_set_send = false;

		// Declared in the outer scope so the catch block can evict the
		// Orbit_Notifier preferences cache on rollback. See cache-invariant
		// note on Orbit_Notifier::get_or_create_preferences().
		$user_id = 0;

		// All DB writes (wp_users + wp_orbit_subscriptions + wp_orbit_
		// notification_preferences + wp_orbit_consent_ledger rows) are
		// wrapped in a single transaction. InnoDB supports this across
		// wp_insert_user / $wpdb->insert calls on the same connection;
		// callers are responsible for not introducing third-party hooks
		// inside that issue COMMIT.
		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( $existing_user ) {
				if ( ! is_user_logged_in() ) {
					$wpdb->query( 'ROLLBACK' );

					return new WP_Error(
						'login_required',
						__( 'An account with this email already exists. Please log in first.', 'orbit' ),
						array(
							'status'    => 409,
							'login_url' => wp_login_url( home_url( '/@' . $profile->slug . '/subscribe?token=' . $share_token ) ),
						)
					);
				}
				$user_id = (int) $existing_user->ID;
			} else {
				// Create new WordPress user with a generated placeholder
				// password — wp_send_new_user_notifications() (called after
				// COMMIT) emails them a "set your password" link.
				$username = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ) . wp_rand( 100, 999 ) );
				$password = wp_generate_password();
				$user_id  = wp_create_user( $username, $password, $email );

				if ( is_wp_error( $user_id ) ) {
					throw new RuntimeException( $user_id->get_error_message() );
				}

				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => sanitize_text_field( $display_name ),
					)
				);

				// On multisite, route role assignment through
				// add_user_to_blog() so the canonical `add_user_to_blog`
				// action fires — third-party integrations (Stream, WP
				// Activity Log, multisite role managers) hook that action
				// to track membership changes. On single-site the function
				// isn't loaded (ms-functions.php is multisite-only) and
				// WP_User::add_role() is the only path.
				if ( is_multisite() ) {
					add_user_to_blog( get_current_blog_id(), $user_id, 'orbit_subscriber' );
				} else {
					$user = get_userdata( $user_id );
					$user->add_role( 'orbit_subscriber' );
				}

				update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );

				$is_new_account            = true;
				$pending_auth_user_id      = (int) $user_id;
				$pending_password_set_send = true;
			}

			if ( '' !== $phone ) {
				// `orbit_phone_pending` (not `orbit_phone`) — promotion
				// to the verified meta happens in Orbit_Phone_Verify on
				// successful code entry. The companion `_at` timestamp
				// is the daily GC cron's age signal — usermeta has no
				// native updated_at, so an explicit unix timestamp is
				// the only way Orbit_Notifier::cleanup_pending_phones()
				// can reap abandoned signups.
				update_user_meta( $user_id, 'orbit_phone_pending', $phone );
				update_user_meta( $user_id, 'orbit_phone_pending_at', time() );
			}

			// Subscription row.
			$subscription_id = Orbit_Subscription::subscribe(
				array(
					'user_id'         => $user_id,
					'profile_id'      => $profile->id,
					'connection_note' => $connection_note,
				)
			);

			if ( is_wp_error( $subscription_id ) ) {
				throw new RuntimeException( $subscription_id->get_error_message() );
			}

			Orbit_Notifier::get_or_create_preferences( $user_id );

			// Consent ledger rows — one per channel the user consented to.
			$email_result = Orbit_Consent::record(
				$user_id,
				'email',
				'opt_in',
				array(
					'source'       => 'subscribe',
					'cta_snapshot' => $cta_snapshot,
				)
			);
			if ( is_wp_error( $email_result ) ) {
				throw new RuntimeException( 'consent_email: ' . $email_result->get_error_message() );
			}

			if ( $consent_sms ) {
				$sms_result = Orbit_Consent::record(
					$user_id,
					'sms',
					'opt_in',
					array(
						'source'       => 'subscribe',
						'cta_snapshot' => $cta_snapshot,
					)
				);
				if ( is_wp_error( $sms_result ) ) {
					throw new RuntimeException( 'consent_sms: ' . $sms_result->get_error_message() );
				}
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			// Evict the Orbit_Notifier preferences cache if a row was
			// inserted before the throw. get_or_create_preferences()
			// populates the static cache immediately after the INSERT —
			// after ROLLBACK the row is gone but the cache entry would
			// otherwise survive the request and serve a phantom hit on
			// any retry. Guarded so we don't pass a WP_Error from a
			// failed wp_create_user() through the int cast.
			if ( is_int( $user_id ) && $user_id > 0 ) {
				Orbit_Notifier::forget_preferences( $user_id );
			}

			return new WP_Error( 'subscribe_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Side effects below run after COMMIT — they can't be rolled
		// back, and we want them to fire only on the happy path.
		if ( $pending_auth_user_id ) {
			wp_clear_auth_cookie();
			wp_set_current_user( $pending_auth_user_id );
			wp_set_auth_cookie( $pending_auth_user_id, true );
		}
		if ( $pending_password_set_send ) {
			wp_send_new_user_notifications( $user_id, 'user' );
		}

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
	 * Unsubscribe the current user from a subscription by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public static function delete_own_subscription( $request ) {
		$subscription = Orbit_Subscription::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'orbit' ), array( 'status' => 404 ) );
		}

		if ( (int) $subscription->user_id !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'You can only unsubscribe yourself.', 'orbit' ), array( 'status' => 403 ) );
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
