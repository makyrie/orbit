<?php
/**
 * Plugin Name: Perihelion
 * Description: Person-centric social activity tool. Subscribe to people, get notified about their activities, respond with lightweight going/maybe actions.
 * Version:     1.9.4
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
define( 'ORBIT_VERSION', '1.9.8' );
define( 'ORBIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORBIT_PLUGIN_FILE', __FILE__ );

/**
 * Brand name used in user-facing messaging (SMS body, HELP/STOP replies,
 * sample messages submitted to TCR). Pinned via constant so it cannot drift
 * via Settings → General. Override in wp-config.php if needed.
 */
defined( 'ORBIT_MESSAGING_BRAND' ) || define( 'ORBIT_MESSAGING_BRAND', 'Perihelion' );

/**
 * Support contact returned by HELP TwiML replies. Pinned via constant so it
 * matches the support address registered with TCR (sample-message drift
 * triggers campaign suspension). Override in wp-config.php for another
 * deployment identity.
 */
defined( 'ORBIT_MESSAGING_SUPPORT' ) || define( 'ORBIT_MESSAGING_SUPPORT', 'sarah@perihelion.social' );

/**
 * Sunset date (UTC) for the legacy raw-secret unsubscribe fallback path.
 * After this date, `Orbit_Routes::resolve_unsubscribe_subscription()` will
 * stop honoring pre-HMAC unsubscribe tokens — bounding the leaked-mail-spool
 * blast radius. 12 months matches the HMAC token's 1-year expiry.
 */
defined( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET' ) || define( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET', '2027-06-01' );

/**
 * Table name constants (without $wpdb->prefix).
 *
 * Note: ORBIT_TABLE_CONSENT_LEDGER is network-scoped on multisite (uses
 * $wpdb->base_prefix in Orbit_Activator + Orbit_Consent). All other Orbit
 * tables are per-site.
 */
defined( 'ORBIT_TABLE_PROFILES' ) || define( 'ORBIT_TABLE_PROFILES', 'orbit_profiles' );
defined( 'ORBIT_TABLE_SUBSCRIPTIONS' ) || define( 'ORBIT_TABLE_SUBSCRIPTIONS', 'orbit_subscriptions' );
defined( 'ORBIT_TABLE_ACTIVITIES' ) || define( 'ORBIT_TABLE_ACTIVITIES', 'orbit_activities' );
defined( 'ORBIT_TABLE_RESPONSES' ) || define( 'ORBIT_TABLE_RESPONSES', 'orbit_responses' );
defined( 'ORBIT_TABLE_NOTIFICATION_PREFERENCES' ) || define( 'ORBIT_TABLE_NOTIFICATION_PREFERENCES', 'orbit_notification_preferences' );
defined( 'ORBIT_TABLE_NOTIFICATION_LOG' ) || define( 'ORBIT_TABLE_NOTIFICATION_LOG', 'orbit_notification_log' );
defined( 'ORBIT_TABLE_PHONE_VERIFICATION' ) || define( 'ORBIT_TABLE_PHONE_VERIFICATION', 'orbit_phone_verification' );
defined( 'ORBIT_TABLE_CONSENT_LEDGER' ) || define( 'ORBIT_TABLE_CONSENT_LEDGER', 'orbit_consent_ledger' );

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
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-features.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-messaging-copy.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-compliance-ui.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-consent.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-token.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-profile.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-activity.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-subscription.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-response.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-privacy.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-twilio.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-phone-verify.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-notifier.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rolled-back-exception.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-user-provisioning.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-api.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-subscription.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-activity.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-profile.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-notification.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-client-ip.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rate-limiter.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-routes.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-shortcodes.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-spam.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-rest-signup.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-email-template.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-emails.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-mail.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-account-emails.php';
require_once ORBIT_PLUGIN_DIR . 'includes/class-orbit-user-notifications.php';

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
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-signup.php';
	require_once ORBIT_PLUGIN_DIR . 'cli/class-orbit-cli-consent.php';

	WP_CLI::add_command( 'orbit profile', 'Orbit_CLI_Profile' );
	WP_CLI::add_command( 'orbit activity', 'Orbit_CLI_Activity' );
	WP_CLI::add_command( 'orbit subscription', 'Orbit_CLI_Subscription' );
	WP_CLI::add_command( 'orbit subscriber', 'Orbit_CLI_Subscriber' );
	WP_CLI::add_command( 'orbit response', 'Orbit_CLI_Response' );
	WP_CLI::add_command( 'orbit notification', 'Orbit_CLI_Notification' );
	WP_CLI::add_command( 'orbit status', 'Orbit_CLI_Status' );
	WP_CLI::add_command( 'orbit signup', 'Orbit_CLI_Signup' );
	WP_CLI::add_command( 'orbit consent', 'Orbit_CLI_Consent' );
}

/**
 * Activation hook.
 */
function orbit_activate() {
	Orbit_Activator::activate();
	Orbit_Roles::register();
	// Single owner of the version write: this function for fresh activations,
	// and orbit_maybe_upgrade() for in-place upgrades. The activator no longer
	// writes the version itself to keep the write path centralized.
	update_option( 'orbit_db_version', ORBIT_VERSION );
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
		as_unschedule_all_actions( 'orbit_send_new_user_notification' );
		as_unschedule_all_actions( 'orbit_send_subscription_approved' );
		as_unschedule_all_actions( 'orbit_send_new_subscriber' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( ORBIT_PLUGIN_FILE, 'orbit_deactivate' );

/**
 * Database upgrade mechanism.
 *
 * Compares the stored DB version against the current plugin version.
 * On a forward jump (no stored version, or stored < current), re-runs
 * table creation (dbDelta is safe for updates) and re-registers
 * roles/capabilities. A downgrade (stored > current) is treated as a
 * no-op — an admin who rolled the plugin back should not have the
 * older code attempt to "upgrade" against a newer schema.
 */
function orbit_maybe_upgrade() {
	$installed_version = get_option( 'orbit_db_version' );

	if ( ! $installed_version || version_compare( $installed_version, ORBIT_VERSION, '<' ) ) {
		Orbit_Activator::create_tables();
		Orbit_Roles::register();
		orbit_migrate_page_slugs();
		orbit_migrate_app_page_templates();
		// Seed the consent IP salt on in-place upgrades too, not just fresh
		// activation. Installs first activated before the salt-seed landed are
		// updated by uploading files (no re-activation), so without this every
		// signup / subscribe 500s with orbit_consent_salt_missing. Guarded and
		// idempotent — no-ops when the constant is defined or the option exists.
		Orbit_Activator::seed_consent_ip_salt();
		update_option( 'orbit_db_version', ORBIT_VERSION );
	}

	$content_version = get_option( 'orbit_content_version' );
	if ( ! $content_version || version_compare( $content_version, ORBIT_VERSION, '<' ) ) {
		if ( Orbit_Activator::create_pages() ) {
			update_option( 'orbit_content_version', ORBIT_VERSION, false );
		}
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
// Fire at `init` priority 0 (not `plugins_loaded`) because create_pages()
// calls wp_insert_post() which needs $wp_rewrite. WP only finalizes the
// rewrite global on `init` — calling it earlier crashes with
// "Call to a member function get_page_permastruct() on null".
add_action( 'init', 'orbit_maybe_upgrade', 0 );

/**
 * Register the consent ledger's append-only query guard.
 *
 * Prevents UPDATE/DELETE statements against the consent ledger from any
 * code path outside a deliberate migration window. Append-only is a
 * legal-defense invariant (TCPA), not a soft convention.
 */
Orbit_Consent::register_query_guard();

// Disable SendGrid click/open tracking on our own sends (per message, not
// account-wide) so transactional-email links stay clean and readable.
Orbit_Mail::register();
Orbit_Account_Emails::register();

/**
 * Register ActionScheduler hooks and schedule recurring jobs.
 */
Orbit_Notifier::register_hooks();
add_action( 'init', array( 'Orbit_Notifier', 'schedule_recurring_jobs' ) );

/**
 * Register the deferred new-user-notification handler.
 *
 * Signup + subscribe enqueue an `orbit_send_new_user_notification` job
 * after COMMIT so the REST response isn't blocked on SMTP latency. The
 * job carries `user_id` and (from the subscribe path) the poster's
 * `poster_profile_id`, dispatching via
 * `Orbit_User_Notifications::send_new_user_notification()`.
 */
add_action( 'orbit_send_new_user_notification', array( 'Orbit_User_Notifications', 'send_new_user_notification' ), 10, 2 );

/**
 * Register the transactional lifecycle-email handlers.
 *
 * `orbit_subscription_status_changed` fires from Orbit_Subscription on every
 * status change; the handler emails the subscriber only on pending → approved.
 * `orbit_subscription_requested` fires when a subscription enters the pending
 * state; the handler emails the poster their new-request notice.
 */
add_action( 'orbit_subscription_status_changed', array( 'Orbit_Emails', 'on_subscription_status_changed' ), 10, 3 );
add_action( 'orbit_subscription_requested', array( 'Orbit_Emails', 'on_subscription_requested' ), 10, 1 );

/**
 * Register the deferred lifecycle-email ActionScheduler callbacks.
 *
 * The two handlers above enqueue these jobs (after COMMIT / after the REST
 * response) so SMTP latency never blocks the request. Each callback re-loads
 * the subscription by ID and no-ops if it's gone.
 */
add_action( Orbit_Emails::HOOK_SEND_APPROVED, array( 'Orbit_Emails', 'dispatch_subscription_approved' ), 10, 1 );
add_action( Orbit_Emails::HOOK_SEND_NEW_SUBSCRIBER, array( 'Orbit_Emails', 'dispatch_new_subscriber' ), 10, 1 );

/**
 * Register REST API routes.
 */
add_action( 'rest_api_init', array( 'Orbit_REST_API', 'register_routes' ) );

/**
 * Register custom routes and shortcodes.
 */
Orbit_Routes::register();
Orbit_Spam::register();
add_action( 'init', array( 'Orbit_Shortcodes', 'register' ) );

/**
 * Enqueue frontend scripts on pages that use Orbit shortcodes or routes.
 */
function orbit_enqueue_scripts() {
	// Only load on pages that need it: Orbit pages, profile routes, activity routes.
	// Sign-up is a public marketing page (kept out of the internal list, which
	// controls nav-menu hiding) but needs the form handler too.
	$dominated_by_orbit = is_page( orbit_get_internal_page_slugs() ) || is_page( 'sign-up' );

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
				'success'            => __( 'Saved successfully.', 'orbit' ),
				'responseSaved'      => __( 'Response saved.', 'orbit' ),
				'statusGoing'        => __( "You're going", 'orbit' ),
				'statusMaybe'        => __( 'You said maybe', 'orbit' ),
				'confirmCancel'      => __( 'Are you sure you want to cancel this activity?', 'orbit' ),
				'confirmUnsubscribe' => __( 'Are you sure you want to unsubscribe?', 'orbit' ),
				'retract'            => __( 'Cancel RSVP', 'orbit' ),
				'timeout'            => __( 'The request timed out. Please try again.', 'orbit' ),
				'logIn'              => __( 'Log in', 'orbit' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'orbit_enqueue_scripts' );

/**
 * Hide the WordPress admin toolbar for everyone who can't manage the site.
 *
 * Perihelion's posters and subscribers live entirely on the front end. The
 * black WP bar ("Howdy", "My Sites", "About WordPress"…) leaks the underlying
 * plumbing and has no place in the experience; the app provides its own nav
 * and Log out. True site admins keep the bar.
 */
add_filter(
	'show_admin_bar',
	function ( $show ) {
		return current_user_can( 'manage_options' ) ? $show : false;
	}
);

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
 * Give the login screen a clean /login/ URL and route logged-out visitors there.
 *
 * - /login/ is served by wp-login.php in place — the URL stays /login/ — via a
 *   rewrite rule to a query var, with a self-healing one-time flush so the
 *   in-place-upload deploy needs no plugin re-activation.
 * - login_url and the login form's POST target both become /login/, so the
 *   whole flow stays off wp-login.php.
 * - Logged-out visitors who hit a login-required app page (dashboard, settings,
 *   new-activity, …) are redirected to /login/ with a return-to. One surface.
 */
add_action(
	'init',
	function () {
		add_rewrite_rule( '^login/?$', 'index.php?orbit_login=1', 'top' );

		// Self-healing flush: register the rule once after deploy without a
		// plugin re-activation (prod deploys are in-place file uploads). Bump
		// the stored value to re-flush if the rule ever changes.
		if ( '1' !== get_option( 'orbit_login_rewrite', '' ) ) {
			flush_rewrite_rules( false );
			update_option( 'orbit_login_rewrite', '1' );
		}
	}
);

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'orbit_login';
		return $vars;
	}
);

add_filter(
	'login_url',
	function ( $login_url, $redirect, $force_reauth ) {
		$url = home_url( '/login/' );
		if ( ! empty( $redirect ) ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	},
	10,
	3
);

// Keep the login form's POST on /login/ so the URL never flips to wp-login.php.
add_filter(
	'site_url',
	function ( $url, $path, $scheme ) {
		if ( 'login_post' === $scheme && is_string( $path ) && 0 === strpos( ltrim( $path, '/' ), 'wp-login.php' ) ) {
			return home_url( '/login/' );
		}
		return $url;
	},
	10,
	3
);

add_action(
	'template_redirect',
	function () {
		// Serve wp-login.php in place at /login/ (GET renders the form, POST
		// authenticates). Requiring it after WP is loaded is a no-op for
		// wp-load (require_once guards); its own login logic then runs and exits.
		if ( get_query_var( 'orbit_login' ) ) {
			// wp-login.php is built as a top-level entry script; included here,
			// a couple of the vars its form renderer reads aren't defined on
			// this path. Seed them so the include doesn't emit notices.
			$error      = '';
			$user_login = '';
			require ABSPATH . 'wp-login.php';
			exit;
		}

		// Route logged-out visitors on login-required app pages to /login/.
		if ( ! is_user_logged_in() && is_page( orbit_get_internal_page_slugs() ) ) {
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;
		}
	}
);

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
