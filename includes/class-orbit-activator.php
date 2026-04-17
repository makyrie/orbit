<?php
/**
 * Plugin activation handler.
 *
 * Creates all custom database tables and required WordPress pages.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Activator
 */
class Orbit_Activator {

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		self::create_tables();
		self::create_pages();
	}

	/**
	 * Create all 7 custom tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		// orbit_profiles.
		$table_profiles = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$sql[]          = "CREATE TABLE {$table_profiles} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			slug varchar(100) NOT NULL,
			display_name varchar(200) NOT NULL,
			bio text DEFAULT NULL,
			share_token varchar(64) NOT NULL,
			require_approval tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY share_token (share_token)
		) {$charset_collate};";

		// orbit_subscriptions.
		$table_subscriptions = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
		$sql[]               = "CREATE TABLE {$table_subscriptions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			profile_id bigint(20) unsigned NOT NULL,
			connection_note text DEFAULT NULL,
			status enum('pending','approved','denied','unsubscribed') NOT NULL DEFAULT 'pending',
			visibility_default enum('anonymous','visible') NOT NULL DEFAULT 'anonymous',
			subscription_secret varchar(64) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_profile (user_id, profile_id),
			UNIQUE KEY subscription_secret (subscription_secret),
			KEY profile_id (profile_id)
		) {$charset_collate};";

		// orbit_activities.
		$table_activities = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$sql[]            = "CREATE TABLE {$table_activities} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id bigint(20) unsigned NOT NULL,
			tier tinyint(1) unsigned NOT NULL,
			title varchar(300) NOT NULL,
			description text DEFAULT NULL,
			location_name varchar(300) DEFAULT NULL,
			location_address text DEFAULT NULL,
			date_time datetime DEFAULT NULL,
			date_flexible tinyint(1) NOT NULL DEFAULT 0,
			show_attendees enum('none','count','names') NOT NULL DEFAULT 'count',
			status enum('active','cancelled','past') NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY profile_id (profile_id),
			KEY status_date (status, date_time)
		) {$charset_collate};";

		// orbit_responses.
		$table_responses = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
		$sql[]           = "CREATE TABLE {$table_responses} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			activity_id bigint(20) unsigned NOT NULL,
			subscription_id bigint(20) unsigned NOT NULL,
			response enum('going','maybe') NOT NULL,
			visibility_override enum('anonymous','visible','default') NOT NULL DEFAULT 'default',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY activity_subscription (activity_id, subscription_id),
			KEY subscription_id (subscription_id)
		) {$charset_collate};";

		// orbit_notification_preferences.
		$table_notif_prefs = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_PREFERENCES;
		$sql[]             = "CREATE TABLE {$table_notif_prefs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			tier1_method enum('sms','email','digest','none') NOT NULL DEFAULT 'digest',
			tier2_method enum('sms','email','digest','none') NOT NULL DEFAULT 'digest',
			tier3_method enum('sms','email','digest','none') NOT NULL DEFAULT 'sms',
			sms_daily_cap smallint unsigned DEFAULT NULL,
			digest_time time NOT NULL DEFAULT '18:00:00',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id)
		) {$charset_collate};";

		// orbit_notification_log.
		$table_notif_log = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$sql[]           = "CREATE TABLE {$table_notif_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			activity_id bigint(20) unsigned NOT NULL,
			method enum('sms','email','digest') NOT NULL,
			status enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY activity_id (activity_id),
			KEY user_method_date (user_id, method, created_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		// orbit_phone_verification.
		$table_phone_verify = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$sql[]              = "CREATE TABLE {$table_phone_verify} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			phone varchar(20) NOT NULL,
			code varchar(6) NOT NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY phone_created (phone, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'orbit_db_version', ORBIT_VERSION );
	}

	/**
	 * Create WordPress pages for authenticated routes.
	 *
	 * These pages use shortcodes registered by the plugin to render dynamic content.
	 * The theme's FSE templates handle the outer layout.
	 */
	public static function create_pages() {
		$pages = array(
			'orbit-dashboard'     => array(
				'title'   => 'Dashboard',
				'content' => '[orbit_dashboard]',
			),
			'orbit-settings'      => array(
				'title'   => 'Settings',
				'content' => '[orbit_settings]',
			),
			'orbit-my-subscriptions' => array(
				'title'   => 'My Subscriptions',
				'content' => '[orbit_my_subscriptions]',
			),
			'orbit-manage'        => array(
				'title'   => 'Manage',
				'content' => '[orbit_manage]',
			),
			'orbit-new-activity'  => array(
				'title'   => 'New Activity',
				'content' => '[orbit_new_activity]',
			),
			'orbit-edit-activity' => array(
				'title'   => 'Edit Activity',
				'content' => '[orbit_edit_activity]',
			),
			'orbit-subscribers'   => array(
				'title'   => 'Subscribers',
				'content' => '[orbit_subscribers]',
			),
			'orbit-edit-profile'  => array(
				'title'   => 'Edit Profile',
				'content' => '[orbit_edit_profile]',
			),
		);

		foreach ( $pages as $slug => $page_data ) {
			$existing = get_page_by_path( $slug );

			if ( $existing ) {
				continue;
			}

			wp_insert_post(
				array(
					'post_title'   => $page_data['title'],
					'post_name'    => $slug,
					'post_content' => $page_data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				)
			);
		}
	}
}
