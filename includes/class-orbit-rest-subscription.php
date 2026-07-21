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

		// Anonymous request hitting an existing-email path is a clear
		// "log in first" UX — surface before opening any transaction.
		// The 409 + login_url matches the signup endpoint's response so
		// the JS branch is identical (see todo 127).
		if ( $existing_user && ! is_user_logged_in() ) {
			return new WP_Error(
				'login_required',
				__( 'An account with this email already exists. Please log in first.', 'orbit' ),
				array(
					'status'    => 409,
					'login_url' => wp_login_url( Orbit_Profile::share_url( $profile ) ),
				)
			);
		}

		// Cache the disclosure shown to the user so the consent ledger
		// captures the exact wording they agreed to. The shortcode AND
		// this handler both call Orbit_Compliance_UI::compliance_disclosure_text()
		// so they always agree byte-for-byte. The version is taken from
		// the published /privacy/ page's orbit_policy_version meta inside
		// Orbit_Consent::record() so we don't need to pass it here.
		$cta_snapshot = Orbit_Compliance_UI::compliance_disclosure_text();

		$is_new_account            = false;
		$pending_auth_user_id      = 0;
		$pending_password_set_send = false;
		$user_id                   = 0;

		if ( $existing_user ) {
			// Existing logged-in user branch: no user creation. Stamp the
			// consent rows directly under a minimal transaction so a
			// partial failure doesn't leave a half-written audit trail.
			// Notifier preferences and subscription row are created
			// post-COMMIT below (same pattern the new-account branch uses).
			$user_id = (int) $existing_user->ID;

			$wpdb->query( 'START TRANSACTION' );

			try {
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
					throw new Orbit_Rolled_Back_Exception( $email_result );
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
						throw new Orbit_Rolled_Back_Exception( $sms_result );
					}
				}

				$wpdb->query( 'COMMIT' );
			} catch ( Orbit_Rolled_Back_Exception $e ) {
				$wpdb->query( 'ROLLBACK' );

				$inner_error = $e->wp_error;
				$inner_code  = (string) $inner_error->get_error_code();

				error_log(
					sprintf(
						'[orbit] subscribe (existing user) rolled back: code=%s message=%s data=%s',
						$inner_code,
						$inner_error->get_error_message(),
						wp_json_encode( $inner_error->get_error_data() )
					)
				);

				return new WP_Error(
					$inner_code,
					__( "We couldn't complete your subscription. Please try again in a moment.", 'orbit' ),
					array( 'status' => 500 )
				);
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( '[orbit] subscribe (existing user) unexpected throwable: ' . $e->getMessage() );

				return new WP_Error(
					'subscribe_failed',
					__( "We couldn't complete your subscription. Please try again in a moment.", 'orbit' ),
					array( 'status' => 500 )
				);
			}
		} else {
			// New-account branch: hand off the full user-creation envelope
			// (wp_insert_user → multisite role attach → meta → consent rows)
			// to Orbit_User_Provisioning so signup and subscribe share the
			// same transactional boundary. Subscription row + notifier
			// preferences are subscribe-specific and stay below.
			$username = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ) . wp_rand( 100, 999 ) );

			$consents = array(
				'email' => array(
					'state'        => 'opt_in',
					'source'       => 'subscribe',
					'cta_snapshot' => $cta_snapshot,
				),
			);
			if ( $consent_sms ) {
				$consents['sms'] = array(
					'state'        => 'opt_in',
					'source'       => 'subscribe',
					'cta_snapshot' => $cta_snapshot,
				);
			}

			$result = Orbit_User_Provisioning::create_user_with_consent(
				array(
					'user_login'    => $username,
					'user_email'    => $email,
					'display_name'  => sanitize_text_field( $display_name ),
					'role'          => 'orbit_subscriber',
					'phone_pending' => $phone,
					// Subscribe has historically been no-retry — keep that
					// behavior so the rollback path's response code shape
					// matches what the existing tests expect.
				),
				$consents,
				array(
					// REST handler defers the welcome email via
					// ActionScheduler after we return so the auth-cookie
					// write happens first.
					'send_welcome_email' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				$inner_code = (string) $result->get_error_code();

				error_log(
					sprintf(
						'[orbit] subscribe rolled back: code=%s message=%s data=%s',
						$inner_code,
						$result->get_error_message(),
						wp_json_encode( $result->get_error_data() )
					)
				);

				return new WP_Error(
					$inner_code,
					__( "We couldn't complete your subscription. Please try again in a moment.", 'orbit' ),
					array( 'status' => 500 )
				);
			}

			$user_id                   = (int) $result;
			$is_new_account            = true;
			$pending_auth_user_id      = $user_id;
			$pending_password_set_send = true;
		}

		// Subscription row + notifier preferences run AFTER the provisioning
		// transaction has committed (or after the existing-user consent
		// transaction). For the new-account branch this means the user
		// exists by the time we try to subscribe them; for the existing
		// branch, the consent rows are already durable.
		$subscription_id = Orbit_Subscription::subscribe(
			array(
				'user_id'         => $user_id,
				'profile_id'      => $profile->id,
				'connection_note' => $connection_note,
			)
		);

		if ( is_wp_error( $subscription_id ) ) {
			error_log(
				sprintf(
					'[orbit] subscribe row write failed post-provisioning: code=%s message=%s',
					$subscription_id->get_error_code(),
					$subscription_id->get_error_message()
				)
			);
			return new WP_Error(
				$subscription_id->get_error_code(),
				__( "We couldn't complete your subscription. Please try again in a moment.", 'orbit' ),
				array( 'status' => 500 )
			);
		}

		Orbit_Notifier::get_or_create_preferences( $user_id );

		// Side effects below run after COMMIT — they can't be rolled
		// back, and we want them to fire only on the happy path.
		if ( $pending_auth_user_id ) {
			wp_clear_auth_cookie();
			wp_set_current_user( $pending_auth_user_id );
			wp_set_auth_cookie( $pending_auth_user_id, true );
		}
		if ( $pending_password_set_send ) {
			// Defer the welcome email so SMTP latency doesn't block the
			// HTTP response (see todo 119). Enqueued as an async action so
			// AS fires a background loopback request and runs it within
			// seconds, rather than waiting for the next system-cron tick;
			// if ActionScheduler somehow isn't loaded, fall back to the
			// sync path so users still get their password-set link. The
			// poster's profile ID is threaded through the job payload so
			// the subscriber welcome can name the poster they signed up to
			// follow.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'orbit_send_new_user_notification',
					array(
						'user_id'           => $user_id,
						'poster_profile_id' => (int) $profile->id,
					),
					'orbit'
				);
			} else {
				// Fallback: AS not loaded — should not happen in production.
				Orbit_User_Notifications::send_new_user_notification( $user_id, (int) $profile->id );
			}
		}

		$subscription = Orbit_Subscription::get( $subscription_id );

		// Where the JS should forward the user after the success flash.
		// New accounts land on /dashboard/ (their profile editor / first
		// step). Existing logged-in subscribers go to the profile they
		// just subscribed to so they immediately see the content they
		// signed up for. There's no `Orbit_Profile::get_permalink()` helper
		// yet — the canonical front-end URL is `/@<slug>/`, see
		// Orbit_Routes for the rewrite that resolves it.
		if ( $is_new_account ) {
			$redirect_url = home_url( '/dashboard/' );
		} else {
			$redirect_url = home_url( '/@' . $profile->slug . '/' );
		}

		return new WP_REST_Response(
			array(
				'id'           => $subscription_id,
				'status'       => $subscription->status,
				'message'      => 'approved' === $subscription->status
					? __( 'You are now subscribed!', 'orbit' )
					: __( 'Your subscription request has been sent for approval.', 'orbit' ),
				'redirect_url' => esc_url_raw( $redirect_url ),
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
