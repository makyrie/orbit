<?php
/**
 * Orbit uninstall handler.
 *
 * Cleans up all plugin data when deleted via WordPress admin.
 *
 * @package Orbit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$tables = array(
	'orbit_responses',
	'orbit_notification_log',
	'orbit_phone_verification',
	'orbit_notification_preferences',
	'orbit_subscriptions',
	'orbit_activities',
	'orbit_profiles',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// Delete options.
delete_option( 'orbit_db_version' );
delete_option( 'orbit_roles_version' );

// Clean up user meta for all users.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('orbit_phone', 'orbit_phone_verified', 'orbit_timezone', 'orbit_sms_opted_out')" );

// Remove roles.
remove_role( 'orbit_subscriber' );
remove_role( 'orbit_poster' );

// Remove admin capabilities.
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
	$caps = array(
		'orbit_subscribe', 'orbit_respond', 'orbit_manage_preferences',
		'orbit_view_activities', 'orbit_create_activity', 'orbit_manage_activity',
		'orbit_manage_profile', 'orbit_manage_subscribers', 'orbit_admin',
	);
	foreach ( $caps as $cap ) {
		$admin_role->remove_cap( $cap );
	}
}

// Delete pages created by the plugin.
$page_slugs = array(
	'orbit-dashboard', 'orbit-settings', 'orbit-manage',
	'orbit-new-activity', 'orbit-edit-activity', 'orbit-subscribers', 'orbit-edit-profile',
);
foreach ( $page_slugs as $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page ) {
		wp_delete_post( $page->ID, true );
	}
}
