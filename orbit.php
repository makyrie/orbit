<?php
/**
 * Plugin Name: Perihelion
 * Description: Person-centric social activity tool. Subscribe to people, get notified about their activities, respond with lightweight going/maybe actions.
 * Version:     1.4.0
 * Author:      Perihelion
 * License:     GPL-2.0-or-later
 * Text Domain: orbit
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'ORBIT_VERSION', '1.4.0' );
define( 'ORBIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORBIT_PLUGIN_FILE', __FILE__ );

/**
 * Table name constants (without $wpdb->prefix).
 */
define( 'ORBIT_TABLE_PROFILES', 'orbit_profiles' );
define( 'ORBIT_TABLE_SUBSCRIPTIONS', 'orbit_subscriptions' );
define( 'ORBIT_TABLE_ACTIVITIES', 'orbit_activities' );
define( 'ORBIT_TABLE_RESPONSES', 'orbit_responses' );
define( 'ORBIT_TABLE_NOTIFICATION_PREFERENCES', 'orbit_notification_preferences' );
define( 'ORBIT_TABLE_NOTIFICATION_LOG', 'orbit_notification_log' );
define( 'ORBIT_TABLE_PHONE_VERIFICATION', 'orbit_phone_verification' );

/**
 * Autoload dependencies via Composer.
 */
if ( file_exists( ORBIT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ORBIT_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Include plugin classes.
 */
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-activator.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-roles.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-token.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-profile.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-activity.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-subscription.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-response.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-privacy.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-twilio.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-phone-verify.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-notifier.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-api.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-subscription.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-activity.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-profile.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-notification.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rate-limiter.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-routes.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-shortcodes.php';

/**
 * Register WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-profile.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-activity.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-subscription.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-subscriber.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-response.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-notification.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-status.php';

	WP_CLI::add_command( 'orbit profile', 'Orbit_CLI_Profile' );
	WP_CLI::add_command( 'orbit activity', 'Orbit_CLI_Activity' );
	WP_CLI::add_command( 'orbit subscription', 'Orbit_CLI_Subscription' );
	WP_CLI::add_command( 'orbit subscriber', 'Orbit_CLI_Subscriber' );
	WP_CLI::add_command( 'orbit response', 'Orbit_CLI_Response' );
	WP_CLI::add_command( 'orbit notification', 'Orbit_CLI_Notification' );
	WP_CLI::add_command( 'orbit status', 'Orbit_CLI_Status' );
}

/**
 * Activation hook.
 */
function orbit_activate() {
	Orbit_Activator::activate();
	Orbit_Roles::register();
	flush_rewrite_rules();
}
register_activation_hook( ORBIT_PLUGIN_FILE, 'orbit_activate' );

/**
 * Deactivation hook.
 */
function orbit_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'orbit_send_immediate_notification' );
		as_unschedule_all_actions( 'orbit_send_daily_digest' );
		as_unschedule_all_actions( 'orbit_mark_past_activities' );
		as_unschedule_all_actions( 'orbit_cleanup_notification_log' );
		as_unschedule_all_actions( 'orbit_dispatch_activity_notifications' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( ORBIT_PLUGIN_FILE, 'orbit_deactivate' );

/**
 * Database upgrade mechanism.
 *
 * Compares the stored DB version against the current plugin version.
 * On mismatch, re-runs table creation (dbDelta is safe for updates)
 * and re-registers roles/capabilities.
 */
function orbit_maybe_upgrade() {
	$installed_version = get_option( 'orbit_db_version' );

	if ( $installed_version !== ORBIT_VERSION ) {
		Orbit_Activator::create_tables();
		Orbit_Roles::register();
		orbit_migrate_page_slugs();
		orbit_migrate_app_page_templates();
		update_option( 'orbit_db_version', ORBIT_VERSION );
	}
}

/**
 * One-time migration to rename internal pages from `orbit-*` slugs to
 * the simplified versions. Idempotent — pages already at the new slug
 * are skipped, and we never overwrite a page that happens to live at
 * the new slug already (defensive in case a site predefined one).
 */
function orbit_migrate_page_slugs() {
	$renames = array(
		'orbit-dashboard'        => 'dashboard',
		'orbit-settings'         => 'settings',
		'orbit-my-subscriptions' => 'subscriptions',
		'orbit-manage'           => 'manage',
		'orbit-new-activity'     => 'new-activity',
		'orbit-edit-activity'    => 'edit-activity',
		'orbit-subscribers'      => 'subscribers',
		'orbit-edit-profile'     => 'edit-profile',
	);

	foreach ( $renames as $old => $new ) {
		$page = get_page_by_path( $old );
		if ( ! $page ) {
			continue;
		}

		// Don't clobber a different page that already occupies the new slug.
		$existing_at_new = get_page_by_path( $new );
		if ( $existing_at_new && (int) $existing_at_new->ID !== (int) $page->ID ) {
			continue;
		}

		wp_update_post(
			array(
				'ID'        => $page->ID,
				'post_name' => $new,
			)
		);
	}
}

/**
 * One-time migration to assign the `page-app` template to the plugin's
 * 8 internal app pages so the active theme (when it provides one) can
 * render them with a wider layout. Idempotent — pages already on the
 * `page-app` template are skipped, and pages that don't exist on this
 * install are silently ignored.
 *
 * The Perihelion theme provides the `page-app.html` template; other
 * themes can ignore the meta or provide their own template of the
 * same name. The post-meta value is harmless when the active theme
 * doesn't define a matching template — WordPress falls back to the
 * default page template.
 */
function orbit_migrate_app_page_templates() {
	$app_slugs = array(
		'dashboard',
		'settings',
		'subscriptions',
		'manage',
		'new-activity',
		'edit-activity',
		'subscribers',
		'edit-profile',
	);

	foreach ( $app_slugs as $slug ) {
		$page = get_page_by_path( $slug );
		if ( ! $page ) {
			continue;
		}

		$current = get_post_meta( $page->ID, '_wp_page_template', true );
		if ( 'page-app' === $current ) {
			continue;
		}

		update_post_meta( $page->ID, '_wp_page_template', 'page-app' );
	}
}
add_action( 'plugins_loaded', 'orbit_maybe_upgrade' );

/**
 * Register ActionScheduler hooks and schedule recurring jobs.
 */
Orbit_Notifier::register_hooks();
add_action( 'init', array( 'Orbit_Notifier', 'schedule_recurring_jobs' ) );

/**
 * Register REST API routes.
 */
add_action( 'rest_api_init', array( 'Orbit_REST_API', 'register_routes' ) );

/**
 * Register custom routes and shortcodes.
 */
Orbit_Routes::register();
add_action( 'init', array( 'Orbit_Shortcodes', 'register' ) );

/**
 * Enqueue frontend scripts on pages that use Orbit shortcodes or routes.
 */
function orbit_enqueue_scripts() {
	// Only load on pages that need it: Orbit pages, profile routes, activity routes.
	$dominated_by_orbit = is_page( orbit_get_internal_page_slugs() );

	$is_orbit_route = get_query_var( 'orbit_profile_slug' )
		|| get_query_var( 'orbit_activity_id' )
		|| get_query_var( 'orbit_unsubscribe' );

	if ( ! $dominated_by_orbit && ! $is_orbit_route ) {
		return;
	}

	wp_enqueue_style(
		'orbit',
		plugins_url( 'assets/css/orbit.css', ORBIT_PLUGIN_FILE ),
		array(),
		ORBIT_VERSION
	);

	wp_enqueue_script(
		'orbit-forms',
		plugins_url( 'assets/js/orbit-forms.js', ORBIT_PLUGIN_FILE ),
		array(),
		ORBIT_VERSION,
		true
	);

	wp_localize_script(
		'orbit-forms',
		'orbitForms',
		array(
			'restUrl'   => esc_url_raw( rest_url( 'orbit/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'homeUrl'   => esc_url_raw( home_url() ),
			'manageUrl' => esc_url_raw( home_url( '/manage/' ) ),
			'strings'   => array(
				'success'       => __( 'Saved successfully.', 'orbit' ),
				'responseSaved' => __( 'Response saved.', 'orbit' ),
				'confirmCancel'       => __( 'Are you sure you want to cancel this activity?', 'orbit' ),
				'confirmUnsubscribe' => __( 'Are you sure you want to unsubscribe?', 'orbit' ),
				'retract'            => __( 'Cancel RSVP', 'orbit' ),
				'timeout'            => __( 'The request timed out. Please try again.', 'orbit' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'orbit_enqueue_scripts' );

/**
 * Hide Orbit's internal pages from navigation menus.
 *
 * These pages are app screens reached via in-app links, not top-level nav items.
 * Filters both classic menus (wp_nav_menu_objects) and FSE page-list blocks (get_pages).
 */
function orbit_get_internal_page_slugs() {
	return array(
		'dashboard',
		'settings',
		'subscriptions',
		'manage',
		'new-activity',
		'edit-activity',
		'subscribers',
		'edit-profile',
	);
}

/**
 * Filter classic nav menus.
 *
 * @param array $items Menu items.
 * @return array Filtered items.
 */
function orbit_filter_nav_menu_items( $items ) {
	$slugs = orbit_get_internal_page_slugs();

	return array_filter(
		$items,
		function ( $item ) use ( $slugs ) {
			if ( 'page' === $item->object ) {
				$page = get_post( $item->object_id );
				if ( $page && in_array( $page->post_name, $slugs, true ) ) {
					return false;
				}
			}
			return true;
		}
	);
}
add_filter( 'wp_nav_menu_objects', 'orbit_filter_nav_menu_items' );

/**
 * Filter FSE page-list blocks (used by block themes like Twenty Twenty-Five).
 *
 * @param WP_Post[] $pages Array of page objects.
 * @return WP_Post[] Filtered pages.
 */
function orbit_filter_page_list_block( $pages ) {
	if ( is_admin() ) {
		return $pages;
	}

	$slugs = orbit_get_internal_page_slugs();

	return array_filter(
		$pages,
		function ( $page ) use ( $slugs ) {
			return ! in_array( $page->post_name, $slugs, true );
		}
	);
}
add_filter( 'get_pages', 'orbit_filter_page_list_block' );

/**
 * Clean up Orbit data when a WordPress user is deleted.
 *
 * Handles the full cascade: activities, cross-user responses, cross-user
 * subscriptions, notification preferences, notification log entries,
 * phone verification records, and profile deletion.
 */
add_action( 'delete_user', array( 'Orbit_Privacy', 'cleanup_user_data' ) );
