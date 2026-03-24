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

		// Get activities from all subscribed profiles.
		$activities = array();
		foreach ( $subscriptions as $sub ) {
			$profile_activities = Orbit_Activity::list(
				array(
					'profile_id' => $sub->profile_id,
					'status'     => 'active',
					'per_page'   => 20,
					'order'      => 'DESC',
				)
			);
			$activities = array_merge( $activities, $profile_activities );
		}

		// Also include own activities if poster.
		$own_profile = Orbit_Profile::get_by_user_id( $user_id );
		if ( $own_profile ) {
			$own_activities = Orbit_Activity::list(
				array(
					'profile_id' => $own_profile->id,
					'status'     => 'active',
					'per_page'   => 20,
				)
			);
			$activities = array_merge( $activities, $own_activities );
		}

		// Sort by created_at desc, deduplicate.
		$seen = array();
		$activities = array_filter( $activities, function ( $a ) use ( &$seen ) {
			if ( isset( $seen[ $a->id ] ) ) {
				return false;
			}
			$seen[ $a->id ] = true;
			return true;
		} );

		usort( $activities, function ( $a, $b ) {
			return strcmp( $b->created_at, $a->created_at );
		} );

		$activities = array_slice( $activities, 0, 50 );

		ob_start();

		echo '<div class="orbit-dashboard">';

		if ( empty( $activities ) ) {
			echo '<p>' . esc_html__( 'No activities yet. Subscribe to someone to see their activities here.', 'orbit' ) . '</p>';
		}

		$tier_labels = array(
			1 => __( 'Just an idea', 'orbit' ),
			2 => __( "I'll go if you will", 'orbit' ),
			3 => __( "I'm going — join me", 'orbit' ),
		);

		foreach ( $activities as $activity ) {
			$profile    = Orbit_Profile::get( $activity->profile_id );
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
				echo '<p class="orbit-activity-date">' . esc_html( $activity->date_time ) . '</p>';
			}

			if ( $activity->location_name ) {
				echo '<p class="orbit-activity-location">' . esc_html( $activity->location_name ) . '</p>';
			}

			// Show response counts.
			$going_count = Orbit_Response::count_by_activity( $activity->id, 'going' );
			$maybe_count = Orbit_Response::count_by_activity( $activity->id, 'maybe' );

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

		$tier_labels = array(
			1 => __( 'Just an idea', 'orbit' ),
			2 => __( "I'll go if you will", 'orbit' ),
			3 => __( "I'm going — join me", 'orbit' ),
		);

		ob_start();

		echo '<div class="orbit-settings">';
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

		$pending_count = Orbit_Subscription::count(
			array(
				'profile_id' => $profile->id,
				'status'     => 'pending',
			)
		);

		ob_start();

		echo '<div class="orbit-manage">';
		echo '<h2>' . esc_html__( 'Manage Activities', 'orbit' ) . '</h2>';

		if ( $pending_count > 0 ) {
			echo '<div class="orbit-notice">';
			echo '<a href="' . esc_url( home_url( '/orbit-subscribers/' ) ) . '">';
			echo esc_html( sprintf( _n( '%d pending subscriber', '%d pending subscribers', $pending_count, 'orbit' ), $pending_count ) );
			echo '</a>';
			echo '</div>';
		}

		echo '<p><a href="' . esc_url( home_url( '/orbit-new-activity/' ) ) . '" class="orbit-btn">';
		echo esc_html__( 'New Activity', 'orbit' );
		echo '</a></p>';

		if ( ! empty( $activities ) ) {
			echo '<table class="orbit-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Title', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Tier', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'orbit' ) . '</th>';
			echo '<th>' . esc_html__( 'Responses', 'orbit' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $activities as $activity ) {
				$response_count = Orbit_Response::count_by_activity( $activity->id );

				echo '<tr>';
				echo '<td><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">' . esc_html( $activity->title ) . '</a></td>';
				echo '<td>' . esc_html( $activity->tier ) . '</td>';
				echo '<td>' . esc_html( $activity->status ) . '</td>';
				echo '<td>' . ( $activity->date_time ? esc_html( $activity->date_time ) : '—' ) . '</td>';
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

		$activity_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

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
				echo '<td>' . esc_html( $sub->created_at ) . '</td>';
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
		if ( ! is_user_logged_in() || ! current_user_can( 'orbit_manage_profile' ) ) {
			return self::login_prompt( __( 'Please log in to edit your profile.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return '<p>' . esc_html__( 'You don\'t have a profile yet.', 'orbit' ) . '</p>';
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

		echo '<div class="orbit-profile">';
		echo '<h1>' . esc_html( $profile->display_name ) . '</h1>';

		if ( $profile->bio ) {
			echo '<p class="orbit-bio">' . esc_html( $profile->bio ) . '</p>';
		}

		// Show active activities (limited info for non-subscribers).
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

			$viewer_id    = is_user_logged_in() ? get_current_user_id() : null;
			$subscription = $viewer_id ? Orbit_Subscription::get_by_user_and_profile( $viewer_id, $profile->id ) : null;
			$is_approved  = $subscription && 'approved' === $subscription->status;
			$is_pending   = $subscription && 'pending' === $subscription->status;

			if ( $is_pending ) {
				echo '<p class="orbit-notice">' . esc_html__( 'Your subscription is awaiting approval.', 'orbit' ) . '</p>';
			}

			$tier_labels = array(
				1 => __( 'Just an idea', 'orbit' ),
				2 => __( "I'll go if you will", 'orbit' ),
				3 => __( "I'm going — join me", 'orbit' ),
			);

			foreach ( $activities as $activity ) {
				$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

				echo '<div class="orbit-activity-card">';
				echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';
				echo '<h3>' . esc_html( $activity->title ) . '</h3>';

				if ( $activity->date_time ) {
					echo '<p class="orbit-activity-date">' . esc_html( $activity->date_time ) . '</p>';
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
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

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
		echo '<h2>' . esc_html( sprintf(
			/* translators: %s: poster display name */
			__( 'Subscribe to %s', 'orbit' ),
			$profile->display_name
		) ) . '</h2>';

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
			$activity_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			if ( $activity_id ) {
				$activity = Orbit_Activity::get( $activity_id );
			}
		}

		if ( ! $activity ) {
			return '<p>' . esc_html__( 'Activity not found.', 'orbit' ) . '</p>';
		}

		$profile = Orbit_Profile::get( $activity->profile_id );
		$viewer_id = is_user_logged_in() ? get_current_user_id() : null;

		// Check for action token in URL.
		$act_token    = isset( $_GET['act'] ) ? sanitize_text_field( $_GET['act'] ) : '';
		$subscription = null;

		if ( $act_token ) {
			// Find subscription via token validation.
			$subscriptions = Orbit_Subscription::list(
				array(
					'profile_id' => $activity->profile_id,
					'status'     => 'approved',
					'per_page'   => 9999,
				)
			);

			foreach ( $subscriptions as $sub ) {
				if ( Orbit_Token::validate_action_token( $act_token, $sub->subscription_secret, $activity->id ) ) {
					$subscription = $sub;
					break;
				}
			}
		} elseif ( $viewer_id ) {
			$subscription = Orbit_Subscription::get_by_user_and_profile( $viewer_id, $activity->profile_id );

			if ( $subscription && 'approved' !== $subscription->status ) {
				$subscription = null;
			}
		}

		$tier_labels = array(
			1 => __( 'Just an idea', 'orbit' ),
			2 => __( "I'll go if you will", 'orbit' ),
			3 => __( "I'm going — join me", 'orbit' ),
		);

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
		echo '<h1>' . esc_html( $activity->title ) . '</h1>';

		if ( $activity->description ) {
			echo '<div class="orbit-activity-description">' . wp_kses_post( wpautop( $activity->description ) ) . '</div>';
		}

		if ( $activity->date_time ) {
			echo '<p class="orbit-activity-date"><strong>' . esc_html__( 'When:', 'orbit' ) . '</strong> ';
			echo esc_html( $activity->date_time );
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

		// Response buttons (only for approved subscribers, active/past activities).
		if ( $subscription && 'cancelled' !== $activity->status ) {
			$my_response = Orbit_Response::get_by_activity_and_subscription( $activity->id, $subscription->id );
			$is_past     = 'past' === $activity->status;

			if ( ! $is_past ) {
				echo '<div class="orbit-response-buttons" data-activity-id="' . esc_attr( $activity->id ) . '">';

				$going_class = $my_response && 'going' === $my_response->response ? ' orbit-btn-active' : '';
				$maybe_class = $my_response && 'maybe' === $my_response->response ? ' orbit-btn-active' : '';

				echo '<button class="orbit-btn orbit-btn-going' . esc_attr( $going_class ) . '" data-response="going">';
				echo esc_html__( "I'm going", 'orbit' ) . '</button> ';

				echo '<button class="orbit-btn orbit-btn-maybe' . esc_attr( $maybe_class ) . '" data-response="maybe">';
				echo esc_html__( 'Maybe', 'orbit' ) . '</button>';

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
