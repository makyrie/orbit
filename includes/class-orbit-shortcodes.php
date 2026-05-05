<?php
/**
 * Shortcode registration.
 *
 * All shortcodes registered by the plugin for theme consumption.
 * Shortcodes handle access control and data fetching; the theme
 * provides the outer template layout.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Shortcodes
 */
class Orbit_Shortcodes {

	/**
	 * Register all shortcodes.
	 */
	public static function register() {
		add_shortcode( 'orbit_dashboard', array( __CLASS__, 'dashboard' ) );
		add_shortcode( 'orbit_settings', array( __CLASS__, 'settings' ) );
		add_shortcode( 'orbit_my_subscriptions', array( __CLASS__, 'my_subscriptions' ) );
		add_shortcode( 'orbit_manage', array( __CLASS__, 'manage' ) );
		add_shortcode( 'orbit_new_activity', array( __CLASS__, 'new_activity' ) );
		add_shortcode( 'orbit_edit_activity', array( __CLASS__, 'edit_activity' ) );
		add_shortcode( 'orbit_subscribers', array( __CLASS__, 'subscribers' ) );
		add_shortcode( 'orbit_edit_profile', array( __CLASS__, 'edit_profile' ) );
		add_shortcode( 'orbit_profile', array( __CLASS__, 'profile' ) );
		add_shortcode( 'orbit_subscribe_form', array( __CLASS__, 'subscribe_form' ) );
		add_shortcode( 'orbit_activity', array( __CLASS__, 'activity' ) );
	}

	/**
	 * Subscriber's unified dashboard.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function dashboard( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to view your dashboard.', 'orbit' ) );
		}

		$user_id = get_current_user_id();

		// Get all approved subscriptions.
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);

		// Collect all profile IDs (subscriptions + own profile).
		$profile_ids = array_map( function ( $s ) {
			return (int) $s->profile_id;
		}, $subscriptions );

		$own_profile = Orbit_Profile::get_by_user_id( $user_id );
		if ( $own_profile ) {
			$profile_ids[] = (int) $own_profile->id;
		}

		$profile_ids = array_unique( $profile_ids );

		// Single query for all activities across all profiles.
		$activities = Orbit_Activity::list_by_profile_ids(
			$profile_ids,
			array(
				'status'   => 'active',
				'per_page' => 50,
				'order'    => 'DESC',
			)
		);

		ob_start();

		echo '<div class="orbit-dashboard">';

		if ( empty( $activities ) ) {
			// Check for pending subscriptions.
			$pending_subs = Orbit_Subscription::list(
				array(
					'user_id'  => $user_id,
					'status'   => 'pending',
					'per_page' => 100,
				)
			);

			if ( ! empty( $pending_subs ) ) {
				$pending_count = count( $pending_subs );
				echo '<p class="orbit-notice">';
				echo esc_html( sprintf(
					_n(
						'You\'ve subscribed to %d person who hasn\'t approved you yet. Their activities will appear here once they do.',
						'You\'ve subscribed to %d people who haven\'t approved you yet. Their activities will appear here once they do.',
						$pending_count,
						'orbit'
					),
					$pending_count
				) );
				echo '</p>';
			} else {
				echo '<p>' . esc_html__( 'No activities yet. Subscribe to someone to see their activities here.', 'orbit' ) . '</p>';
			}

			if ( current_user_can( 'orbit_create_activity' ) ) {
				echo '<p><a href="' . esc_url( home_url( '/manage/' ) ) . '" class="orbit-btn">';
				echo esc_html__( 'Manage Your Activities', 'orbit' );
				echo '</a></p>';
			} else {
				echo '<p class="orbit-cta">';
				echo esc_html__( 'Want to share your own activities?', 'orbit' ) . ' ';
				echo '<a href="' . esc_url( home_url( '/edit-profile/' ) ) . '">';
				echo esc_html__( 'Create a profile', 'orbit' );
				echo '</a></p>';
			}
		}

		$tier_labels = Orbit_Activity::get_tier_labels();

		// Batch-load profiles and response counts.
		$needed_profile_ids = array_unique( array_map( function ( $a ) {
			return (int) $a->profile_id;
		}, $activities ) );
		$profiles_map = Orbit_Profile::get_by_ids( $needed_profile_ids );

		$activity_ids    = array_map( function ( $a ) { return (int) $a->id; }, $activities );
		$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );

		// Load current user's responses to show "You: Going/Maybe" on cards.
		$my_responses_map = array();
		$user_responses   = Orbit_Response::list_by_user( $user_id );
		foreach ( $user_responses as $resp ) {
			$my_responses_map[ (int) $resp->activity_id ] = $resp->response;
		}

		foreach ( $activities as $activity ) {
			$profile    = isset( $profiles_map[ (int) $activity->profile_id ] ) ? $profiles_map[ (int) $activity->profile_id ] : null;
			$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

			echo '<div class="orbit-activity-card" data-tier="' . esc_attr( $activity->tier ) . '">';
			echo '<div class="orbit-activity-meta">';

			if ( $profile ) {
				echo '<span class="orbit-poster-name">' . esc_html( $profile->display_name ) . '</span>';
			}

			echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';
			echo '</div>';

			echo '<h3 class="orbit-activity-title">';
			echo '<a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">';
			echo esc_html( $activity->title );
			echo '</a></h3>';

			if ( $activity->date_time ) {
				echo '<p class="orbit-activity-date">' . esc_html( self::format_datetime( $activity->date_time ) ) . '</p>';
			}

			if ( $activity->location_name ) {
				echo '<p class="orbit-activity-location">' . esc_html( $activity->location_name ) . '</p>';
			}

			// Show response counts (from batch-loaded data).
			$counts      = isset( $response_counts[ $activity->id ] ) ? $response_counts[ $activity->id ] : array( 'going' => 0, 'maybe' => 0 );
			$going_count = $counts['going'];
			$maybe_count = $counts['maybe'];

			if ( 'none' !== $activity->show_attendees && ( $going_count || $maybe_count ) ) {
				echo '<p class="orbit-response-counts">';
				if ( $going_count ) {
					echo esc_html( sprintf( _n( '%d going', '%d going', $going_count, 'orbit' ), $going_count ) );
				}
				if ( $going_count && $maybe_count ) {
					echo ' &middot; ';
				}
				if ( $maybe_count ) {
					echo esc_html( sprintf( _n( '%d maybe', '%d maybe', $maybe_count, 'orbit' ), $maybe_count ) );
				}
				echo '</p>';
			}

			// Show user's own response status.
			$my_response = isset( $my_responses_map[ (int) $activity->id ] ) ? $my_responses_map[ (int) $activity->id ] : null;
			if ( $my_response ) {
				$response_label = 'going' === $my_response ? __( "You're going", 'orbit' ) : __( 'You said maybe', 'orbit' );
				echo '<p class="orbit-my-response">' . esc_html( $response_label ) . '</p>';
			}

			echo '</div>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Notification preferences / settings page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function settings( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to view your settings.', 'orbit' ) );
		}

		$user_id = get_current_user_id();
		$prefs   = Orbit_Notifier::get_or_create_preferences( $user_id );

		$tier_labels = Orbit_Activity::get_tier_labels();

		ob_start();

		echo '<div class="orbit-settings">';

		echo self::render_phone_verification( $user_id );

		echo '<h2>' . esc_html__( 'Notification Preferences', 'orbit' ) . '</h2>';
		echo '<form method="post" class="orbit-settings-form" data-orbit-api="preferences">';

		foreach ( array( 1, 2, 3 ) as $tier ) {
			$key     = "tier{$tier}_method";
			$current = $prefs->$key;

			echo '<div class="orbit-setting-row">';
			echo '<label>' . esc_html( $tier_labels[ $tier ] ) . '</label>';
			echo '<select name="' . esc_attr( $key ) . '">';

			foreach ( array( 'sms', 'email', 'digest', 'none' ) as $method ) {
				$selected = selected( $current, $method, false );
				echo '<option value="' . esc_attr( $method ) . '" ' . $selected . '>' . esc_html( ucfirst( $method ) ) . '</option>';
			}

			echo '</select>';
			echo '</div>';
		}

		echo '<div class="orbit-setting-row">';
		echo '<label>' . esc_html__( 'SMS Daily Cap', 'orbit' ) . '</label>';
		echo '<input type="number" name="sms_daily_cap" value="' . esc_attr( $prefs->sms_daily_cap ) . '" min="0" placeholder="' . esc_attr__( 'No limit', 'orbit' ) . '">';
		echo '</div>';

		echo '<div class="orbit-setting-row">';
		echo '<label>' . esc_html__( 'Digest Time', 'orbit' ) . '</label>';
		echo '<input type="time" name="digest_time" value="' . esc_attr( substr( $prefs->digest_time, 0, 5 ) ) . '">';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Save Preferences', 'orbit' ) . '</button>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Render the phone verification block for the settings page.
	 *
	 * Two page-load states:
	 *   - verified:     phone is verified — show number + "Change" link
	 *   - not-verified: show phone entry form (code form is hidden, revealed by JS)
	 *
	 * The transient "code-pending" state (after submitting the phone form) is
	 * entered client-side by JS within a single page session. Once the backend
	 * stops overwriting `orbit_phone` until verification, the absence of a
	 * verified phone is sufficient to drive the initial UI here.
	 *
	 * TODO: Migrate to the new `GET /verify-phone` primitive (see #030) once
	 * that endpoint lands so we can surface a server-known pending state on
	 * page load (e.g. user closes the tab between step 1 and step 2 and
	 * returns within the code TTL).
	 *
	 * @param int $user_id User ID.
	 * @return string HTML markup.
	 */
	private static function render_phone_verification( $user_id ) {
		$phone             = (string) get_user_meta( $user_id, 'orbit_phone', true );
		$verified          = (bool) get_user_meta( $user_id, 'orbit_phone_verified', true );
		$has_verified      = $verified && '' !== $phone;
		$twilio_configured = defined( 'ORBIT_TWILIO_ACCOUNT_SID' ) && defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) && defined( 'ORBIT_TWILIO_FROM_NUMBER' );

		ob_start();

		echo '<div class="orbit-phone-verification">';
		echo '<h2>' . esc_html__( 'Phone Number', 'orbit' ) . '</h2>';
		echo '<p class="orbit-help">' . esc_html__( 'Required for SMS notifications. We use this only to send activity alerts you opt into.', 'orbit' ) . '</p>';

		if ( ! $twilio_configured ) {
			echo '<div class="orbit-notice orbit-notice-warning">';
			echo esc_html__( 'SMS is not currently available — Twilio is not configured on this site.', 'orbit' );
			echo '</div>';
			echo '</div>';
			return ob_get_clean();
		}

		// State: verified.
		if ( $has_verified ) {
			echo '<div class="orbit-phone-verified">';
			echo '<p class="orbit-phone-current">';
			echo '<span class="orbit-verified-badge">&check; ' . esc_html__( 'Verified', 'orbit' ) . '</span> ';
			echo '<strong>' . esc_html( $phone ) . '</strong>';
			echo '</p>';
			echo '<button type="button" class="orbit-btn orbit-btn-sm orbit-btn-link" data-orbit-phone-change>';
			echo esc_html__( 'Change phone number', 'orbit' );
			echo '</button>';
			echo '</div>';
		}

		// Phone entry form — hidden when already verified (revealed by Change button).
		echo '<form method="post" class="orbit-phone-form" data-orbit-api="verify-phone" data-orbit-step="phone"' . ( $has_verified ? ' hidden' : '' ) . '>';
		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-phone-input">' . esc_html__( 'Phone number', 'orbit' ) . '</label>';
		echo '<input type="tel" id="orbit-phone-input" name="phone" placeholder="+15551234567" required>';
		echo '<p class="orbit-help">' . esc_html__( 'Use E.164 format with country code (e.g., +15551234567).', 'orbit' ) . '</p>';
		echo '</div>';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Send verification code', 'orbit' ) . '</button>';
		echo '</form>';

		// Code entry form — always hidden on page load; JS reveals it after the phone form succeeds.
		echo '<form method="post" class="orbit-code-form" data-orbit-api="verify-phone" data-orbit-step="code" hidden>';
		echo '<p class="orbit-code-sent-msg" aria-live="polite">';
		printf(
			/* translators: %s: phone number the code was sent to */
			wp_kses(
				__( 'A 6-digit code was sent to %s. Enter it below to verify.', 'orbit' ),
				array( 'strong' => array( 'class' => array() ) )
			),
			'<strong class="orbit-code-target"></strong>'
		);
		echo '</p>';
		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-code-input">' . esc_html__( 'Verification code', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-code-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>';
		echo '</div>';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Verify', 'orbit' ) . '</button> ';
		echo '<button type="button" class="orbit-btn orbit-btn-link orbit-btn-sm" data-orbit-phone-change>';
		echo esc_html__( 'Use a different number', 'orbit' );
		echo '</button> ';
		echo '<button type="button" class="orbit-btn orbit-btn-link orbit-btn-sm" data-orbit-phone-resend>';
		echo esc_html__( "Didn't get the code? Resend", 'orbit' );
		echo '</button>';
		echo '</form>';

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * My Subscriptions page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function my_subscriptions( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to view your subscriptions.', 'orbit' ) );
		}

		$user_id = get_current_user_id();

		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);

		$pending_subs = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'pending',
				'per_page' => 100,
			)
		);

		$all_subs = array_merge( $subscriptions, $pending_subs );

		ob_start();

		echo '<div class="orbit-my-subscriptions">';
		echo '<h2>' . esc_html__( 'My Subscriptions', 'orbit' ) . '</h2>';

		if ( empty( $all_subs ) ) {
			echo '<p>' . esc_html__( 'You are not subscribed to anyone yet.', 'orbit' ) . '</p>';
		} else {
			// Batch-load profiles.
			$profile_ids  = array_unique( array_map( function ( $s ) {
				return (int) $s->profile_id;
			}, $all_subs ) );
			$profiles_map = Orbit_Profile::get_by_ids( $profile_ids );

			echo '<table class="orbit-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Poster', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Since', 'orbit' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $all_subs as $sub ) {
				$profile = isset( $profiles_map[ (int) $sub->profile_id ] ) ? $profiles_map[ (int) $sub->profile_id ] : null;
				$name    = $profile ? $profile->display_name : __( 'Unknown', 'orbit' );
				$url     = $profile ? home_url( '/@' . $profile->slug ) : '#';

				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></td>';
				echo '<td>' . esc_html( $sub->status ) . '</td>';
				echo '<td>' . esc_html( self::format_datetime( $sub->created_at, 'M j, Y' ) ) . '</td>';
				echo '<td>';

				if ( 'approved' === $sub->status ) {
					echo '<button class="orbit-btn orbit-btn-sm orbit-btn-danger" data-orbit-unsubscribe="' . esc_attr( $sub->id ) . '">';
					echo esc_html__( 'Unsubscribe', 'orbit' );
					echo '</button>';
				}

				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Poster's management view.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function manage( $atts ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'orbit_manage_profile' ) ) {
			return self::login_prompt( __( 'Please log in as a poster to manage your profile.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'You don\'t have a profile yet.', 'orbit' ) . '</p>';
		}

		$activities = Orbit_Activity::list(
			array(
				'profile_id' => $profile->id,
				'per_page'   => 50,
			)
		);

		ob_start();

		echo '<div class="orbit-manage">';
		echo '<h2>' . esc_html__( 'Manage Activities', 'orbit' ) . '</h2>';

		echo '<p><a href="' . esc_url( home_url( '/new-activity/' ) ) . '" class="orbit-btn">';
		echo esc_html__( 'New Activity', 'orbit' );
		echo '</a></p>';

		if ( ! empty( $activities ) ) {
			// Batch-load response counts.
			$activity_ids    = array_map( function ( $a ) { return (int) $a->id; }, $activities );
			$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );

			echo '<table class="orbit-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Title', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Tier', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Responses', 'orbit' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $activities as $activity ) {
				$response_count = isset( $response_counts[ $activity->id ]['total'] ) ? $response_counts[ $activity->id ]['total'] : 0;

				echo '<tr>';
				echo '<td><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">' . esc_html( $activity->title ) . '</a></td>';
				echo '<td>' . esc_html( $activity->tier ) . '</td>';
				echo '<td>' . esc_html( $activity->status ) . '</td>';
				echo '<td>' . ( $activity->date_time ? esc_html( self::format_datetime( $activity->date_time, 'M j, Y g:i A' ) ) : '—' ) . '</td>';
				echo '<td>' . esc_html( $response_count ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Create activity form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function new_activity( $atts ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'orbit_create_activity' ) ) {
			return self::login_prompt( __( 'Please log in as a poster to create activities.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'You need a profile to create activities.', 'orbit' ) . '</p>';
		}

		ob_start();

		echo '<div class="orbit-new-activity">';
		echo '<h2>' . esc_html__( 'New Activity', 'orbit' ) . '</h2>';
		echo '<form method="post" class="orbit-form" data-orbit-api="activities" data-profile-id="' . esc_attr( $profile->id ) . '">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-title">' . esc_html__( 'Title', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-title" name="title" maxlength="300" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-tier">' . esc_html__( 'Commitment Level', 'orbit' ) . '</label>';
		echo '<select id="orbit-tier" name="tier" required>';
		echo '<option value="1">' . esc_html__( 'Just an idea', 'orbit' ) . '</option>';
		echo '<option value="2">' . esc_html__( "I'll go if you will", 'orbit' ) . '</option>';
		echo '<option value="3" selected>' . esc_html__( "I'm going — join me", 'orbit' ) . '</option>';
		echo '</select>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-description">' . esc_html__( 'Description', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-description" name="description" rows="3"></textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-audience">' . esc_html__( "Who's this for?", 'orbit' ) . '</label>';
		echo '<textarea id="orbit-audience" name="audience" rows="2" placeholder="' . esc_attr__( 'e.g. Beginners welcome, or anyone who likes long walks', 'orbit' ) . '"></textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'Help people decide if this is right for them.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-url">' . esc_html__( 'Link', 'orbit' ) . '</label>';
		echo '<input type="url" id="orbit-url" name="url" placeholder="' . esc_attr__( 'https://example.com/event-page', 'orbit' ) . '">';
		echo '<p class="orbit-help">' . esc_html__( 'Link to an external event page with more details', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-name">' . esc_html__( 'Location Name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-location-name" name="location_name" maxlength="300">';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-address">' . esc_html__( 'Location Address', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-location-address" name="location_address" rows="2"></textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-date-time">' . esc_html__( 'Date & Time', 'orbit' ) . '</label>';
		echo '<input type="datetime-local" id="orbit-date-time" name="date_time">';
		echo '<label><input type="checkbox" name="date_flexible" value="1"> ' . esc_html__( 'Date is approximate', 'orbit' ) . '</label>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-show-attendees">' . esc_html__( 'Show Attendees', 'orbit' ) . '</label>';
		echo '<select id="orbit-show-attendees" name="show_attendees">';
		echo '<option value="count" selected>' . esc_html__( 'Show count', 'orbit' ) . '</option>';
		echo '<option value="names">' . esc_html__( 'Show names', 'orbit' ) . '</option>';
		echo '<option value="none">' . esc_html__( 'Hide', 'orbit' ) . '</option>';
		echo '</select>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Create Activity', 'orbit' ) . '</button>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Edit activity form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function edit_activity( $atts ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'orbit_manage_activity' ) ) {
			return self::login_prompt( __( 'Please log in to edit activities.', 'orbit' ) );
		}

		$activity_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $activity_id ) {
			return '<p>' . esc_html__( 'No activity specified.', 'orbit' ) . '</p>';
		}

		$activity = Orbit_Activity::get( $activity_id );

		if ( ! $activity ) {
			return '<p>' . esc_html__( 'Activity not found.', 'orbit' ) . '</p>';
		}

		// Verify ownership.
		$profile = Orbit_Profile::get( $activity->profile_id );
		if ( ! $profile || (int) $profile->user_id !== get_current_user_id() ) {
			return '<p>' . esc_html__( 'You do not have permission to edit this activity.', 'orbit' ) . '</p>';
		}

		ob_start();

		echo '<div class="orbit-edit-activity">';
		echo '<h2>' . esc_html__( 'Edit Activity', 'orbit' ) . '</h2>';
		echo '<form method="post" class="orbit-form" data-orbit-api="activities/' . esc_attr( $activity_id ) . '" data-method="PATCH">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-title">' . esc_html__( 'Title', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-title" name="title" value="' . esc_attr( $activity->title ) . '" maxlength="300" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-description">' . esc_html__( 'Description', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-description" name="description" rows="3">' . esc_textarea( $activity->description ) . '</textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-audience">' . esc_html__( "Who's this for?", 'orbit' ) . '</label>';
		echo '<textarea id="orbit-audience" name="audience" rows="2" placeholder="' . esc_attr__( 'e.g. Beginners welcome, or anyone who likes long walks', 'orbit' ) . '">' . esc_textarea( $activity->audience ) . '</textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-url">' . esc_html__( 'Link', 'orbit' ) . '</label>';
		echo '<input type="url" id="orbit-url" name="url" value="' . esc_attr( $activity->url ) . '" placeholder="' . esc_attr__( 'https://example.com/event-page', 'orbit' ) . '">';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-name">' . esc_html__( 'Location Name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-location-name" name="location_name" value="' . esc_attr( $activity->location_name ) . '" maxlength="300">';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-address">' . esc_html__( 'Location Address', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-location-address" name="location_address" rows="2">' . esc_textarea( $activity->location_address ) . '</textarea>';
		echo '</div>';

		$date_value = $activity->date_time ? date( 'Y-m-d\TH:i', strtotime( $activity->date_time ) ) : '';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-date-time">' . esc_html__( 'Date & Time', 'orbit' ) . '</label>';
		echo '<input type="datetime-local" id="orbit-date-time" name="date_time" value="' . esc_attr( $date_value ) . '">';
		echo '<label><input type="checkbox" name="date_flexible" value="1" ' . checked( $activity->date_flexible, 1, false ) . '> ' . esc_html__( 'Date is approximate', 'orbit' ) . '</label>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-show-attendees">' . esc_html__( 'Show Attendees', 'orbit' ) . '</label>';
		echo '<select id="orbit-show-attendees" name="show_attendees">';

		foreach ( array( 'count', 'names', 'none' ) as $option ) {
			$selected = selected( $activity->show_attendees, $option, false );
			echo '<option value="' . esc_attr( $option ) . '" ' . $selected . '>' . esc_html( ucfirst( $option ) ) . '</option>';
		}

		echo '</select>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Update Activity', 'orbit' ) . '</button>';

		if ( 'active' === $activity->status ) {
			echo ' <button type="button" class="orbit-btn orbit-btn-danger" data-orbit-cancel="' . esc_attr( $activity_id ) . '">';
			echo esc_html__( 'Cancel Activity', 'orbit' );
			echo '</button>';
		}

		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Subscriber management list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function subscribers( $atts ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'orbit_manage_subscribers' ) ) {
			return self::login_prompt( __( 'Please log in as a poster to manage subscribers.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'You don\'t have a profile yet.', 'orbit' ) . '</p>';
		}

		$subscriptions = Orbit_Subscription::list(
			array(
				'profile_id' => $profile->id,
				'per_page'   => 100,
			)
		);

		ob_start();

		echo '<div class="orbit-subscribers">';
		echo '<h2>' . esc_html__( 'Subscribers', 'orbit' ) . '</h2>';

		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( 'No subscribers yet. Share your link to invite people.', 'orbit' ) . '</p>';

			$share_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<p class="orbit-share-link"><strong>' . esc_html__( 'Share link:', 'orbit' ) . '</strong> ';
			echo '<code>' . esc_html( $share_url ) . '</code></p>';
		} else {
			// Pre-populate WordPress user cache to avoid N+1 user lookups.
			$user_ids = array_map( function ( $s ) { return (int) $s->user_id; }, $subscriptions );
			cache_users( $user_ids );

			echo '<table class="orbit-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Note', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Since', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'orbit' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $subscriptions as $sub ) {
				$user = get_userdata( $sub->user_id );
				$name = $user ? $user->display_name : __( 'Unknown', 'orbit' );

				echo '<tr>';
				echo '<td>' . esc_html( $name ) . '</td>';
				echo '<td>' . esc_html( $sub->status ) . '</td>';
				echo '<td>' . esc_html( $sub->connection_note ? $sub->connection_note : '—' ) . '</td>';
				echo '<td>' . esc_html( self::format_datetime( $sub->created_at, 'M j, Y' ) ) . '</td>';
				echo '<td>';

				if ( 'pending' === $sub->status ) {
					echo '<button class="orbit-btn orbit-btn-sm" data-orbit-subscriber-action="approve" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Approve', 'orbit' ) . '</button> ';
					echo '<button class="orbit-btn orbit-btn-sm orbit-btn-danger" data-orbit-subscriber-action="deny" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Deny', 'orbit' ) . '</button>';
				} elseif ( 'approved' === $sub->status ) {
					echo '<button class="orbit-btn orbit-btn-sm orbit-btn-danger" data-orbit-subscriber-action="remove" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Remove', 'orbit' ) . '</button>';
				}

				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Edit poster profile form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function edit_profile( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to manage your profile.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return self::create_profile_form();
		}

		ob_start();

		echo '<div class="orbit-edit-profile">';
		echo '<h2>' . esc_html__( 'Edit Profile', 'orbit' ) . '</h2>';
		echo '<form method="post" class="orbit-form" data-orbit-api="profiles/' . esc_attr( $profile->id ) . '" data-method="PATCH">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-display-name">' . esc_html__( 'Display Name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-display-name" name="display_name" value="' . esc_attr( $profile->display_name ) . '" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-slug">' . esc_html__( 'URL Slug', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-slug" name="slug" value="' . esc_attr( $profile->slug ) . '" required>';
		echo '<p class="orbit-help">' . esc_html( home_url( '/@' . $profile->slug ) ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-bio">' . esc_html__( 'Bio', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-bio" name="bio" rows="3">' . esc_textarea( $profile->bio ) . '</textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label><input type="checkbox" name="require_approval" value="1" ' . checked( $profile->require_approval, 1, false ) . '> ';
		echo esc_html__( 'Require approval for new subscribers', 'orbit' ) . '</label>';
		echo '</div>';

		$share_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
		echo '<div class="orbit-form-group">';
		echo '<label>' . esc_html__( 'Share Link', 'orbit' ) . '</label>';
		echo '<code>' . esc_html( $share_url ) . '</code>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Save Profile', 'orbit' ) . '</button>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Profile creation form for users who don't have a profile yet.
	 *
	 * @return string HTML output.
	 */
	private static function create_profile_form() {
		$user = wp_get_current_user();

		ob_start();

		echo '<div class="orbit-edit-profile">';
		echo '<h2>' . esc_html__( 'Create Your Profile', 'orbit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Set up a profile to start sharing activities with your people.', 'orbit' ) . '</p>';
		echo '<form method="post" class="orbit-form" data-orbit-api="profiles/me">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-display-name">' . esc_html__( 'Display Name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-display-name" name="display_name" value="' . esc_attr( $user->display_name ) . '" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-slug">' . esc_html__( 'URL Slug', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-slug" name="slug" value="' . esc_attr( sanitize_title( $user->display_name ) ) . '" required>';
		echo '<p class="orbit-help">' . esc_html( home_url( '/@' ) ) . '<span id="orbit-slug-preview">' . esc_html( sanitize_title( $user->display_name ) ) . '</span></p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-bio">' . esc_html__( 'Bio', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-bio" name="bio" rows="3" placeholder="' . esc_attr__( 'A short description of what you like to do', 'orbit' ) . '"></textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label><input type="checkbox" name="require_approval" value="1" checked> ';
		echo esc_html__( 'Require approval for new subscribers', 'orbit' ) . '</label>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Create Profile', 'orbit' ) . '</button>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Public profile page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function profile( $atts ) {
		$profile = get_query_var( 'orbit_current_profile' );

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'Profile not found.', 'orbit' ) . '</p>';
		}

		$is_subscribe = get_query_var( 'orbit_subscribe' );

		if ( $is_subscribe ) {
			return self::subscribe_form( array( 'profile_id' => $profile->id ) );
		}

		ob_start();

		$viewer_id    = is_user_logged_in() ? get_current_user_id() : null;
		$subscription = $viewer_id ? Orbit_Subscription::get_by_user_and_profile( $viewer_id, $profile->id ) : null;
		$is_approved  = $subscription && 'approved' === $subscription->status;
		$is_pending   = $subscription && 'pending' === $subscription->status;
		$is_owner     = $viewer_id && (int) $profile->user_id === $viewer_id;

		echo '<div class="orbit-profile">';

		if ( $profile->bio ) {
			echo '<p class="orbit-bio">' . esc_html( $profile->bio ) . '</p>';
		}

		// Subscribe CTA (not shown to owner or existing subscribers).
		if ( ! $is_owner && ! $is_approved && ! $is_pending ) {
			$subscribe_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<p><a href="' . esc_url( $subscribe_url ) . '" class="orbit-btn">';
			echo esc_html__( 'Subscribe', 'orbit' );
			echo '</a></p>';
		} elseif ( $is_pending ) {
			echo '<p class="orbit-notice">' . esc_html__( 'Your subscription is awaiting approval.', 'orbit' ) . '</p>';
		} elseif ( $is_approved ) {
			echo '<p class="orbit-subscription-status">';
			echo '<span class="orbit-subscribed-badge">' . esc_html__( 'Subscribed', 'orbit' ) . '</span> ';
			echo '<button class="orbit-btn orbit-btn-sm orbit-btn-danger" data-orbit-unsubscribe="' . esc_attr( $subscription->id ) . '">';
			echo esc_html__( 'Unsubscribe', 'orbit' );
			echo '</button></p>';
		}

		// Show active activities.
		$activities = Orbit_Activity::list(
			array(
				'profile_id' => $profile->id,
				'status'     => 'active',
				'per_page'   => 10,
				'order'      => 'DESC',
			)
		);

		if ( ! empty( $activities ) ) {
			echo '<h2>' . esc_html__( 'Recent Activities', 'orbit' ) . '</h2>';

			$tier_labels = Orbit_Activity::get_tier_labels();

			foreach ( $activities as $activity ) {
				$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

				echo '<div class="orbit-activity-card">';
				echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';
				echo '<h3><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">';
				echo esc_html( $activity->title );
				echo '</a></h3>';

				if ( $activity->date_time ) {
					echo '<p class="orbit-activity-date">' . esc_html( self::format_datetime( $activity->date_time ) ) . '</p>';
				}

				if ( $activity->location_name ) {
					echo '<p>' . esc_html( $activity->location_name ) . '</p>';
				}

				// Location address only for approved subscribers.
				if ( $is_approved && $activity->location_address ) {
					echo '<p class="orbit-location-address">' . esc_html( $activity->location_address ) . '</p>';
				}

				echo '</div>';
			}
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Subscription signup form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function subscribe_form( $atts ) {
		$atts = shortcode_atts(
			array( 'profile_id' => 0 ),
			$atts
		);

		$profile = null;
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( $atts['profile_id'] ) {
			$profile = Orbit_Profile::get( absint( $atts['profile_id'] ) );
		} elseif ( $token ) {
			$profile = Orbit_Profile::get_by_share_token( $token );
		}

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'Invalid subscription link.', 'orbit' ) . '</p>';
		}

		// Check for self-subscription.
		if ( is_user_logged_in() && (int) $profile->user_id === get_current_user_id() ) {
			return '<p>' . esc_html__( 'You cannot subscribe to yourself.', 'orbit' ) . '</p>';
		}

		// Check if already subscribed.
		if ( is_user_logged_in() ) {
			$existing = Orbit_Subscription::get_by_user_and_profile( get_current_user_id(), $profile->id );

			if ( $existing && in_array( $existing->status, array( 'approved', 'pending' ), true ) ) {
				$status_message = 'approved' === $existing->status
					? __( 'You are already subscribed.', 'orbit' )
					: __( 'Your subscription is pending approval.', 'orbit' );
				return '<p>' . esc_html( $status_message ) . '</p>';
			}
		}

		ob_start();

		echo '<div class="orbit-subscribe-form">';

		echo '<form method="post" class="orbit-form" data-orbit-api="subscribe">';
		echo '<input type="hidden" name="share_token" value="' . esc_attr( $profile->share_token ) . '">';

		if ( ! is_user_logged_in() ) {
			echo '<div class="orbit-form-group">';
			echo '<label for="orbit-name">' . esc_html__( 'Your Name', 'orbit' ) . '</label>';
			echo '<input type="text" id="orbit-name" name="display_name" required>';
			echo '</div>';

			echo '<div class="orbit-form-group">';
			echo '<label for="orbit-email">' . esc_html__( 'Email', 'orbit' ) . '</label>';
			echo '<input type="email" id="orbit-email" name="email" required>';
			echo '</div>';
		} else {
			$user = wp_get_current_user();
			echo '<input type="hidden" name="display_name" value="' . esc_attr( $user->display_name ) . '">';
			echo '<input type="hidden" name="email" value="' . esc_attr( $user->user_email ) . '">';
			echo '<p>' . esc_html( sprintf( __( 'Subscribing as %s', 'orbit' ), $user->display_name ) ) . '</p>';
		}

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-note">' . esc_html__( 'How do you know this person?', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-note" name="connection_note" rows="2" maxlength="500"></textarea>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Subscribe', 'orbit' ) . '</button>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Activity detail page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function activity( $atts ) {
		$activity = get_query_var( 'orbit_current_activity' );

		if ( ! $activity ) {
			return '<p>' . esc_html__( 'Activity not found.', 'orbit' ) . '</p>';
		}

		$profile = Orbit_Profile::get( $activity->profile_id );
		$viewer_id = is_user_logged_in() ? get_current_user_id() : null;

		// Check for action token in URL.
		$act_token    = isset( $_GET['act'] ) ? sanitize_text_field( wp_unslash( $_GET['act'] ) ) : '';
		$subscription = null;

		if ( $act_token ) {
			// Extract subscription ID from token for O(1) lookup.
			$sub_id = Orbit_Token::extract_subscription_id( $act_token );
			if ( $sub_id ) {
				$sub = Orbit_Subscription::get( $sub_id );
				if ( $sub && 'approved' === $sub->status && (int) $sub->profile_id === (int) $activity->profile_id ) {
					if ( Orbit_Token::validate_action_token( $act_token, $sub->subscription_secret, $activity->id ) ) {
						$subscription = $sub;
					}
				}
			}
		} elseif ( $viewer_id ) {
			$subscription = Orbit_Subscription::get_by_user_and_profile( $viewer_id, $activity->profile_id );

			if ( $subscription && 'approved' !== $subscription->status ) {
				$subscription = null;
			}
		}

		$tier_labels = Orbit_Activity::get_tier_labels();

		$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

		ob_start();

		echo '<div class="orbit-activity-detail">';

		if ( 'cancelled' === $activity->status ) {
			echo '<div class="orbit-notice orbit-notice-warning">' . esc_html__( 'This activity has been cancelled.', 'orbit' ) . '</div>';
		}

		if ( $profile ) {
			echo '<p class="orbit-poster-link"><a href="' . esc_url( home_url( '/@' . $profile->slug ) ) . '">';
			echo esc_html( $profile->display_name ) . '</a></p>';
		}

		echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';

		if ( $activity->description ) {
			echo '<div class="orbit-activity-description">' . wp_kses_post( wpautop( $activity->description ) ) . '</div>';
		}

		if ( $activity->audience ) {
			echo '<div class="orbit-activity-audience">';
			echo '<p class="orbit-activity-audience-label">' . esc_html__( "Who's this for", 'orbit' ) . '</p>';
			echo wp_kses_post( wpautop( $activity->audience ) );
			echo '</div>';
		}

		if ( $activity->date_time ) {
			echo '<p class="orbit-activity-date"><strong>' . esc_html__( 'When:', 'orbit' ) . '</strong> ';
			echo esc_html( self::format_datetime( $activity->date_time ) );
			if ( $activity->date_flexible ) {
				echo ' <em>(' . esc_html__( 'approximate', 'orbit' ) . ')</em>';
			}
			echo '</p>';
		}

		if ( $activity->location_name ) {
			echo '<p class="orbit-activity-location"><strong>' . esc_html__( 'Where:', 'orbit' ) . '</strong> ';
			echo esc_html( $activity->location_name ) . '</p>';
		}

		// Show address only to approved subscribers.
		if ( $activity->location_address && $subscription ) {
			echo '<p class="orbit-location-address">' . esc_html( $activity->location_address ) . '</p>';
		}

		// External link.
		if ( ! empty( $activity->url ) ) {
			echo '<p class="orbit-activity-url"><a href="' . esc_url( $activity->url ) . '" class="orbit-btn orbit-btn-link" target="_blank" rel="noopener noreferrer">';
			echo esc_html__( 'View event details', 'orbit' ) . ' &rarr;';
			echo '</a></p>';
		}

		// Response section.
		$responses        = Orbit_Response::list_by_activity( $activity->id );
		$privacy_resolved = Orbit_Privacy::resolve_responses( $activity, $responses, $viewer_id );

		if ( 'count' === $privacy_resolved['visibility_mode'] || 'names' === $privacy_resolved['visibility_mode'] ) {
			echo '<div class="orbit-responses-summary">';
			if ( $privacy_resolved['going_count'] ) {
				echo '<span class="orbit-going-count">' . esc_html( sprintf( _n( '%d going', '%d going', $privacy_resolved['going_count'], 'orbit' ), $privacy_resolved['going_count'] ) ) . '</span>';
			}
			if ( $privacy_resolved['maybe_count'] ) {
				echo '<span class="orbit-maybe-count">' . esc_html( sprintf( _n( '%d maybe', '%d maybe', $privacy_resolved['maybe_count'], 'orbit' ), $privacy_resolved['maybe_count'] ) ) . '</span>';
			}
			echo '</div>';
		}

		if ( 'names' === $privacy_resolved['visibility_mode'] && ! empty( $privacy_resolved['visible_responses'] ) ) {
			echo '<ul class="orbit-attendee-list">';
			foreach ( $privacy_resolved['visible_responses'] as $resp ) {
				$name = $resp['visible'] && $resp['display_name'] ? $resp['display_name'] : __( 'Someone', 'orbit' );
				echo '<li>' . esc_html( $name ) . ' — ' . esc_html( $resp['response'] ) . '</li>';
			}
			echo '</ul>';
		}

		// Subscribe CTA for non-subscribers (not shown to the poster).
		$is_own_activity = $viewer_id && $profile && (int) $profile->user_id === $viewer_id;
		if ( ! $subscription && $profile && ! $is_own_activity ) {
			$subscribe_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<p class="orbit-cta">';
			echo esc_html( sprintf( __( 'Subscribe to %s to get notified about activities like this.', 'orbit' ), $profile->display_name ) ) . ' ';
			echo '<a href="' . esc_url( $subscribe_url ) . '">' . esc_html__( 'Subscribe', 'orbit' ) . '</a>';
			echo '</p>';
		}

		// Response buttons (only for approved subscribers, active/past activities).
		if ( $subscription && 'cancelled' !== $activity->status ) {
			$my_response = Orbit_Response::get_by_activity_and_subscription( $activity->id, $subscription->id );
			$is_past     = 'past' === $activity->status;

			if ( ! $is_past ) {
				echo '<div class="orbit-response-buttons" data-activity-id="' . esc_attr( $activity->id ) . '" data-subscription-id="' . esc_attr( $subscription->id ) . '">';

				$going_class = $my_response && 'going' === $my_response->response ? ' orbit-btn-active' : '';
				$maybe_class = $my_response && 'maybe' === $my_response->response ? ' orbit-btn-active' : '';

				echo '<button class="orbit-btn orbit-btn-going' . esc_attr( $going_class ) . '" data-response="going">';
				echo esc_html__( "I'm going", 'orbit' ) . '</button> ';

				echo '<button class="orbit-btn orbit-btn-maybe' . esc_attr( $maybe_class ) . '" data-response="maybe">';
				echo esc_html__( 'Maybe', 'orbit' ) . '</button>';

				if ( $my_response ) {
					echo ' <button class="orbit-btn orbit-btn-sm orbit-btn-retract" data-response="retract">';
					echo esc_html__( 'Cancel RSVP', 'orbit' ) . '</button>';
				}

				if ( $act_token ) {
					echo '<input type="hidden" class="orbit-act-token" value="' . esc_attr( $act_token ) . '">';
				}

				echo '</div>';
			} else {
				echo '<p class="orbit-past-notice">' . esc_html__( 'This activity has passed.', 'orbit' ) . '</p>';
			}
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Format a UTC datetime string for display in the viewer's timezone.
	 *
	 * @param string $utc_datetime UTC datetime string (Y-m-d H:i:s).
	 * @param string $format       PHP date format. Default: full readable.
	 * @return string Formatted date string.
	 */
	private static function format_datetime( $utc_datetime, $format = '' ) {
		if ( empty( $utc_datetime ) ) {
			return '';
		}

		if ( ! $format ) {
			$format = 'l, F j \a\t g:i A';
		}

		$timezone_string = '';

		if ( is_user_logged_in() ) {
			$timezone_string = get_user_meta( get_current_user_id(), 'orbit_timezone', true );
		}

		if ( ! $timezone_string ) {
			$timezone_string = wp_timezone_string();
		}

		try {
			$utc      = new DateTimeZone( 'UTC' );
			$local_tz = new DateTimeZone( $timezone_string );
			$dt       = new DateTime( $utc_datetime, $utc );
			$dt->setTimezone( $local_tz );

			return $dt->format( $format );
		} catch ( Exception $e ) {
			return $utc_datetime;
		}
	}

	/**
	 * Generate a login prompt.
	 *
	 * @param string $message Message to display.
	 * @return string HTML.
	 */
	private static function login_prompt( $message ) {
		return '<p class="orbit-login-prompt">' . esc_html( $message ) . ' '
			. '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">'
			. esc_html__( 'Log in', 'orbit' )
			. '</a></p>';
	}
}
