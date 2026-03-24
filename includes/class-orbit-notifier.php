<?php
/**
 * Notification dispatch — SMS, email, and digest batching.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Notifier
 */
class Orbit_Notifier {

	/**
	 * ActionScheduler hook names.
	 */
	const HOOK_IMMEDIATE  = 'orbit_send_immediate_notification';
	const HOOK_DIGEST     = 'orbit_send_daily_digest';
	const HOOK_MARK_PAST  = 'orbit_mark_past_activities';
	const HOOK_CLEANUP    = 'orbit_cleanup_notification_log';
	const HOOK_DISPATCH   = 'orbit_dispatch_activity_notifications';

	/**
	 * Register ActionScheduler hooks.
	 */
	public static function register_hooks() {
		add_action( self::HOOK_IMMEDIATE, array( __CLASS__, 'process_immediate_notification' ), 10, 3 );
		add_action( self::HOOK_DIGEST, array( __CLASS__, 'send_digest' ), 10, 1 );
		add_action( self::HOOK_MARK_PAST, array( __CLASS__, 'process_mark_past' ) );
		add_action( self::HOOK_CLEANUP, array( __CLASS__, 'process_cleanup' ) );
		add_action( self::HOOK_DISPATCH, array( __CLASS__, 'process_dispatch' ), 10, 1 );
	}

	/**
	 * Schedule recurring jobs on plugin init.
	 */
	public static function schedule_recurring_jobs() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Mark past activities — daily at midnight UTC.
		if ( ! as_has_scheduled_action( self::HOOK_MARK_PAST ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow midnight' ), DAY_IN_SECONDS, self::HOOK_MARK_PAST, array(), 'orbit' );
		}

		// Cleanup old notification log entries — weekly.
		if ( ! as_has_scheduled_action( self::HOOK_CLEANUP ) ) {
			as_schedule_recurring_action( strtotime( 'next monday midnight' ), WEEK_IN_SECONDS, self::HOOK_CLEANUP, array(), 'orbit' );
		}
	}

	/**
	 * Dispatch notifications for a new activity.
	 *
	 * For each approved subscriber: check tier preference, check SMS daily cap,
	 * route to immediate or digest, log to notification_log.
	 *
	 * @param int $activity_id Activity ID.
	 */
	public static function dispatch_for_activity( $activity_id ) {
		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity || 'active' !== $activity->status ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK_DISPATCH,
				array( $activity_id ),
				'orbit'
			);
		} else {
			self::process_dispatch( $activity_id );
		}
	}

	/**
	 * Process notification dispatch for an activity (ActionScheduler callback).
	 *
	 * Iterates subscribers and routes each to immediate or digest notification.
	 *
	 * @param int $activity_id Activity ID.
	 */
	public static function process_dispatch( $activity_id ) {
		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity || 'active' !== $activity->status ) {
			return;
		}

		$subscribers = Orbit_Subscription::list(
			array(
				'profile_id' => $activity->profile_id,
				'status'     => 'approved',
				'per_page'   => 9999,
			)
		);

		foreach ( $subscribers as $subscription ) {
			$user_id = (int) $subscription->user_id;
			$method  = self::resolve_notification_method( $user_id, $activity->tier );

			if ( 'none' === $method ) {
				continue;
			}

			if ( 'sms' === $method ) {
				// Check SMS opt-out.
				if ( Orbit_Twilio::is_sms_opted_out( $user_id ) ) {
					$method = 'digest';
				}

				// Check phone verified.
				if ( 'sms' === $method && ! get_user_meta( $user_id, 'orbit_phone_verified', true ) ) {
					$method = 'digest';
				}

				// Check SMS daily cap.
				if ( 'sms' === $method && self::is_sms_cap_reached( $user_id ) ) {
					$method = 'digest';
				}
			}

			if ( 'digest' === $method ) {
				// Log for inclusion in next digest.
				self::log_notification( $user_id, $activity_id, 'digest', 'queued' );
				self::ensure_digest_scheduled( $user_id );
				continue;
			}

			// Queue immediate notification via ActionScheduler.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					self::HOOK_IMMEDIATE,
					array( $user_id, $activity_id, $method ),
					'orbit'
				);
			} else {
				// Fallback: send immediately.
				self::process_immediate_notification( $user_id, $activity_id, $method );
			}
		}
	}

	/**
	 * Process a single immediate notification (ActionScheduler callback).
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $activity_id Activity ID.
	 * @param string $method      'sms' or 'email'.
	 */
	public static function process_immediate_notification( $user_id, $activity_id, $method ) {
		$log_id = self::log_notification( $user_id, $activity_id, $method, 'queued' );

		if ( 'sms' === $method ) {
			$result = self::send_immediate_sms( $user_id, $activity_id );
		} else {
			$result = self::send_immediate_email( $user_id, $activity_id );
		}

		$status = is_wp_error( $result ) ? 'failed' : 'sent';
		self::update_log_status( $log_id, $status );
	}

	/**
	 * Send an immediate SMS notification.
	 *
	 * @param int $user_id     User ID.
	 * @param int $activity_id Activity ID.
	 * @return true|WP_Error True on success.
	 */
	public static function send_immediate_sms( $user_id, $activity_id ) {
		$phone = get_user_meta( $user_id, 'orbit_phone', true );
		if ( ! $phone ) {
			return new WP_Error( 'no_phone', __( 'User has no phone number.', 'orbit' ) );
		}

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get( $activity->profile_id );
		$poster_name = $profile ? $profile->display_name : __( 'Someone', 'orbit' );

		// Generate action token for this subscriber.
		$subscription = Orbit_Subscription::get_by_user_and_profile( $user_id, $activity->profile_id );
		$action_url   = '';

		if ( $subscription ) {
			$token      = Orbit_Token::generate_action_token( $subscription->subscription_secret, $activity_id, $subscription->id );
			$action_url = "\n" . home_url( '/activity/' . $activity_id . '?act=' . urlencode( $token ) );
		}

		$tier_labels = array(
			1 => __( 'Just an idea', 'orbit' ),
			2 => __( "I'll go if you will", 'orbit' ),
			3 => __( "I'm going — join me", 'orbit' ),
		);

		$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

		$body = sprintf(
			"%s: %s\n%s%s",
			$poster_name,
			$activity->title,
			$tier_label,
			$action_url
		);

		return Orbit_Twilio::send_sms( $phone, $body );
	}

	/**
	 * Send an immediate email notification.
	 *
	 * @param int $user_id     User ID.
	 * @param int $activity_id Activity ID.
	 * @return true|WP_Error True on success.
	 */
	public static function send_immediate_email( $user_id, $activity_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'orbit' ) );
		}

		$activity = Orbit_Activity::get( $activity_id );
		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found.', 'orbit' ) );
		}

		$profile     = Orbit_Profile::get( $activity->profile_id );
		$poster_name = $profile ? $profile->display_name : __( 'Someone', 'orbit' );

		$subscription = Orbit_Subscription::get_by_user_and_profile( $user_id, $activity->profile_id );
		$action_url   = '';
		$unsub_url    = '';

		if ( $subscription ) {
			$token      = Orbit_Token::generate_action_token( $subscription->subscription_secret, $activity_id, $subscription->id );
			$action_url = home_url( '/activity/' . $activity_id . '?act=' . urlencode( $token ) );
			$unsub_url  = home_url( '/unsubscribe?token=' . urlencode( $subscription->subscription_secret ) );
		}

		$tier_labels = array(
			1 => __( 'Just an idea', 'orbit' ),
			2 => __( "I'll go if you will", 'orbit' ),
			3 => __( "I'm going — join me", 'orbit' ),
		);

		$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

		$subject = sprintf(
			/* translators: 1: poster name, 2: activity title */
			__( '%1$s: %2$s', 'orbit' ),
			$poster_name,
			$activity->title
		);

		$message = sprintf(
			"%s shared a new activity:\n\n%s\n%s\n",
			$poster_name,
			$activity->title,
			$tier_label
		);

		if ( $activity->description ) {
			$message .= "\n" . $activity->description . "\n";
		}

		if ( $activity->location_name ) {
			$message .= "\n" . sprintf( __( 'Location: %s', 'orbit' ), $activity->location_name );
		}

		if ( $activity->date_time ) {
			$message .= "\n" . sprintf( __( 'When: %s', 'orbit' ), $activity->date_time );
		}

		if ( $action_url ) {
			$message .= "\n\n" . sprintf( __( 'Respond: %s', 'orbit' ), $action_url );
		}

		if ( $unsub_url ) {
			$message .= "\n\n" . sprintf( __( 'Unsubscribe: %s', 'orbit' ), $unsub_url );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $user->user_email, $subject, $message, $headers );

		return $sent ? true : new WP_Error( 'email_failed', __( 'Failed to send email.', 'orbit' ) );
	}

	/**
	 * Compile and send a digest for a user.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True on success, WP_Error if nothing to send.
	 */
	public static function send_digest( $user_id ) {
		global $wpdb;

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'orbit' ) );
		}

		$log_table        = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$activities_table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;

		// Get queued digest items for this user.
		$queued_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT nl.id AS log_id, nl.activity_id, a.title, a.tier, a.date_time, a.profile_id, a.description, a.location_name
				FROM {$log_table} nl
				INNER JOIN {$activities_table} a ON nl.activity_id = a.id
				WHERE nl.user_id = %d AND nl.method = 'digest' AND nl.status = 'queued'
				ORDER BY a.tier DESC, a.date_time ASC",
				$user_id
			)
		);

		if ( empty( $queued_items ) ) {
			return new WP_Error( 'nothing_to_send', __( 'No activities to include in digest.', 'orbit' ) );
		}

		// Batch-load profiles for grouping.
		$profile_ids  = array_unique( array_map( function ( $i ) { return (int) $i->profile_id; }, $queued_items ) );
		$profiles_map = Orbit_Profile::get_by_ids( $profile_ids );

		// Batch-load subscriptions for action token generation.
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);
		$sub_by_profile = array();
		foreach ( $subscriptions as $sub ) {
			$sub_by_profile[ (int) $sub->profile_id ] = $sub;
		}

		// Group by poster.
		$grouped = array();
		foreach ( $queued_items as $item ) {
			$profile     = isset( $profiles_map[ (int) $item->profile_id ] ) ? $profiles_map[ (int) $item->profile_id ] : null;
			$poster_name = $profile ? $profile->display_name : __( 'Unknown', 'orbit' );

			if ( ! isset( $grouped[ $poster_name ] ) ) {
				$grouped[ $poster_name ] = array();
			}
			$grouped[ $poster_name ][] = $item;
		}

		// Build digest message.
		$tier_labels = Orbit_Activity::get_tier_labels();

		$message = __( "Here's what's new from people you follow:\n\n", 'orbit' );

		foreach ( $grouped as $poster_name => $items ) {
			$message .= "--- {$poster_name} ---\n\n";

			foreach ( $items as $item ) {
				$subscription = isset( $sub_by_profile[ (int) $item->profile_id ] ) ? $sub_by_profile[ (int) $item->profile_id ] : null;
				$action_url   = '';

				if ( $subscription ) {
					$token      = Orbit_Token::generate_action_token( $subscription->subscription_secret, $item->activity_id, $subscription->id );
					$action_url = home_url( '/activity/' . $item->activity_id . '?act=' . urlencode( $token ) );
				}

				$tier_label = isset( $tier_labels[ $item->tier ] ) ? $tier_labels[ $item->tier ] : '';

				$message .= sprintf( "[%s] %s\n", $tier_label, $item->title );

				if ( $item->date_time ) {
					$message .= sprintf( __( "  When: %s\n", 'orbit' ), $item->date_time );
				}

				if ( $item->location_name ) {
					$message .= sprintf( __( "  Where: %s\n", 'orbit' ), $item->location_name );
				}

				if ( $action_url ) {
					$message .= sprintf( __( "  Respond: %s\n", 'orbit' ), $action_url );
				}

				$message .= "\n";
			}
		}

		if ( ! empty( $subscriptions ) ) {
			$message .= "---\n" . __( "Manage your subscriptions at: ", 'orbit' ) . home_url( '/dashboard' ) . "\n";
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your %s Digest', 'orbit' ),
			get_bloginfo( 'name' )
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $user->user_email, $subject, $message, $headers );

		// Mark all queued items as sent or failed.
		$status = $sent ? 'sent' : 'failed';
		$now    = current_time( 'mysql', true );

		// Bulk update all queued items in a single query.
		$log_ids = array_map( function ( $item ) { return (int) $item->log_id; }, $queued_items );
		if ( ! empty( $log_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$log_table} SET status = %s, sent_at = %s WHERE id IN ({$placeholders})",
					$status,
					$now,
					...$log_ids
				)
			);
		}

		return $sent ? true : new WP_Error( 'email_failed', __( 'Failed to send digest email.', 'orbit' ) );
	}

	/**
	 * Process mark-past job (ActionScheduler callback).
	 */
	public static function process_mark_past() {
		Orbit_Activity::mark_past();
	}

	/**
	 * Process notification log cleanup (ActionScheduler callback).
	 *
	 * Removes entries older than 90 days.
	 */
	public static function process_cleanup() {
		global $wpdb;

		$table    = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Resolve the notification method for a user based on tier.
	 *
	 * @param int $user_id User ID.
	 * @param int $tier    Activity tier.
	 * @return string 'sms', 'email', 'digest', or 'none'.
	 */
	public static function resolve_notification_method( $user_id, $tier ) {
		$prefs = self::get_or_create_preferences( $user_id );

		$tier_key = 'tier' . $tier . '_method';

		return isset( $prefs->$tier_key ) ? $prefs->$tier_key : 'digest';
	}

	/**
	 * Get or create notification preferences for a user.
	 *
	 * @param int $user_id User ID.
	 * @return object Preferences row.
	 */
	/**
	 * Static request-level cache for preferences.
	 *
	 * @var array
	 */
	private static $preferences_cache = array();

	public static function get_or_create_preferences( $user_id ) {
		if ( isset( self::$preferences_cache[ $user_id ] ) ) {
			return self::$preferences_cache[ $user_id ];
		}

		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;

		$prefs = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id )
		);

		if ( $prefs ) {
			self::$preferences_cache[ $user_id ] = $prefs;
			return $prefs;
		}

		// Create default preferences.
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'user_id'      => absint( $user_id ),
				'tier1_method' => 'digest',
				'tier2_method' => 'digest',
				'tier3_method' => 'sms',
				'digest_time'  => '18:00:00',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$prefs = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id )
		);

		self::$preferences_cache[ $user_id ] = $prefs;

		return $prefs;
	}

	/**
	 * Update notification preferences for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Fields to update.
	 * @return bool|WP_Error True on success.
	 */
	public static function update_preferences( $user_id, $args ) {
		global $wpdb;

		// Ensure preferences exist.
		self::get_or_create_preferences( $user_id );

		$table          = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;
		$valid_methods  = array( 'sms', 'email', 'digest', 'none' );
		$data           = array();
		$formats        = array();
		$set_cap_null   = false;

		foreach ( array( 'tier1_method', 'tier2_method', 'tier3_method' ) as $key ) {
			if ( isset( $args[ $key ] ) && in_array( $args[ $key ], $valid_methods, true ) ) {
				$data[ $key ] = $args[ $key ];
				$formats[]    = '%s';
			}
		}

		if ( array_key_exists( 'sms_daily_cap', $args ) ) {
			if ( null === $args['sms_daily_cap'] ) {
				// Set NULL directly via raw SQL after the main update.
				$set_cap_null = true;
			} else {
				$data['sms_daily_cap'] = absint( $args['sms_daily_cap'] );
				$formats[]             = '%d';
			}
		}

		if ( isset( $args['digest_time'] ) ) {
			$data['digest_time'] = sanitize_text_field( $args['digest_time'] );
			$formats[]           = '%s';
		}

		if ( empty( $data ) && ! $set_cap_null ) {
			return new WP_Error( 'nothing_to_update', __( 'No valid fields to update.', 'orbit' ) );
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$formats[]          = '%s';

			$result = $wpdb->update( $table, $data, array( 'user_id' => $user_id ), $formats, array( '%d' ) );

			if ( false === $result ) {
				return new WP_Error( 'db_error', __( 'Failed to update preferences.', 'orbit' ) );
			}
		}

		// Handle sms_daily_cap = NULL separately since wpdb cannot bind NULL values.
		if ( $set_cap_null ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET sms_daily_cap = NULL, updated_at = %s WHERE user_id = %d",
					current_time( 'mysql', true ),
					$user_id
				)
			);
		}

		return true;
	}

	/**
	 * Check if a user has reached their SMS daily cap.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if cap is reached.
	 */
	private static function is_sms_cap_reached( $user_id ) {
		global $wpdb;

		$prefs = self::get_or_create_preferences( $user_id );

		// No cap set — unlimited.
		if ( null === $prefs->sms_daily_cap ) {
			return false;
		}

		$log_table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$today     = gmdate( 'Y-m-d 00:00:00' );

		$today_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE user_id = %d AND method = 'sms' AND created_at >= %s",
				$user_id,
				$today
			)
		);

		return $today_count >= (int) $prefs->sms_daily_cap;
	}

	/**
	 * Ensure a digest is scheduled for a user.
	 *
	 * @param int $user_id User ID.
	 */
	private static function ensure_digest_scheduled( $user_id ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$args = array( $user_id );

		if ( as_has_scheduled_action( self::HOOK_DIGEST, $args, 'orbit' ) ) {
			return;
		}

		// Schedule at user's preferred digest time in their timezone.
		$prefs    = self::get_or_create_preferences( $user_id );
		$timezone = get_user_meta( $user_id, 'orbit_timezone', true );

		if ( ! $timezone ) {
			$timezone = wp_timezone_string();
		}

		try {
			$tz           = new DateTimeZone( $timezone );
			$now          = new DateTime( 'now', $tz );
			$digest_time  = new DateTime( 'today ' . $prefs->digest_time, $tz );

			// If the digest time has already passed today, schedule for tomorrow.
			if ( $digest_time <= $now ) {
				$digest_time->modify( '+1 day' );
			}

			as_schedule_single_action( $digest_time->getTimestamp(), self::HOOK_DIGEST, $args, 'orbit' );
		} catch ( \Exception $e ) {
			// Fallback: schedule 1 hour from now.
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK_DIGEST, $args, 'orbit' );
		}
	}

	/**
	 * Log a notification.
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $activity_id Activity ID.
	 * @param string $method      'sms', 'email', or 'digest'.
	 * @param string $status      'queued', 'sent', or 'failed'.
	 * @return int Log entry ID.
	 */
	private static function log_notification( $user_id, $activity_id, $method, $status ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;

		$wpdb->insert(
			$table,
			array(
				'user_id'     => absint( $user_id ),
				'activity_id' => absint( $activity_id ),
				'method'      => $method,
				'status'      => $status,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a notification log entry's status.
	 *
	 * @param int    $log_id Log entry ID.
	 * @param string $status New status.
	 */
	private static function update_log_status( $log_id, $status ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;

		$data    = array( 'status' => $status );
		$formats = array( '%s' );

		if ( 'sent' === $status ) {
			$data['sent_at'] = current_time( 'mysql', true );
			$formats[]       = '%s';
		}

		$wpdb->update(
			$table,
			$data,
			array( 'id' => $log_id ),
			$formats,
			array( '%d' )
		);
	}
}
