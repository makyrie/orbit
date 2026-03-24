<?php
/**
 * Custom URL routing and rewrite rules.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Routes
 */
class Orbit_Routes {

	/**
	 * Register hooks for routing.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_routes' ) );
		add_action( 'wp_head', array( __CLASS__, 'add_noindex_meta' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'modify_robots_txt' ), 10, 2 );
	}

	/**
	 * Add custom rewrite rules.
	 */
	public static function add_rewrite_rules() {
		// /@{slug} → profile page.
		add_rewrite_rule(
			'^@([^/]+)/?$',
			'index.php?orbit_profile_slug=$matches[1]',
			'top'
		);

		// /@{slug}/subscribe → subscription form.
		add_rewrite_rule(
			'^@([^/]+)/subscribe/?$',
			'index.php?orbit_profile_slug=$matches[1]&orbit_subscribe=1',
			'top'
		);

		// /activity/{id} → activity detail.
		add_rewrite_rule(
			'^activity/([0-9]+)/?$',
			'index.php?orbit_activity_id=$matches[1]',
			'top'
		);

		// /unsubscribe → unsubscribe handler.
		add_rewrite_rule(
			'^unsubscribe/?$',
			'index.php?orbit_unsubscribe=1',
			'top'
		);
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'orbit_profile_slug';
		$vars[] = 'orbit_subscribe';
		$vars[] = 'orbit_activity_id';
		$vars[] = 'orbit_unsubscribe';

		return $vars;
	}

	/**
	 * Handle custom routes on template_redirect.
	 */
	public static function handle_routes() {
		$profile_slug = get_query_var( 'orbit_profile_slug' );
		$activity_id  = get_query_var( 'orbit_activity_id' );
		$unsubscribe  = get_query_var( 'orbit_unsubscribe' );

		if ( $profile_slug ) {
			self::handle_profile_route( $profile_slug );
		}

		if ( $activity_id ) {
			self::handle_activity_route( absint( $activity_id ) );
		}

		if ( $unsubscribe ) {
			self::handle_unsubscribe_route();
		}
	}

	/**
	 * Handle profile page route.
	 *
	 * @param string $slug Profile slug.
	 */
	private static function handle_profile_route( $slug ) {
		$profile = Orbit_Profile::get_by_slug( sanitize_title( $slug ) );

		if ( ! $profile ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		// Store profile data for shortcode consumption.
		set_query_var( 'orbit_current_profile', $profile );
	}

	/**
	 * Handle activity detail route.
	 *
	 * @param int $activity_id Activity ID.
	 */
	private static function handle_activity_route( $activity_id ) {
		$activity = Orbit_Activity::get( $activity_id );

		if ( ! $activity ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		// Store activity data for shortcode consumption.
		set_query_var( 'orbit_current_activity', $activity );
	}

	/**
	 * Handle unsubscribe route.
	 */
	private static function handle_unsubscribe_route() {
		// Token-based unsubscribe is handled by the shortcode or REST API.
		// This route just needs to serve the unsubscribe page.
	}

	/**
	 * Add noindex meta tag on authenticated and activity pages.
	 */
	public static function add_noindex_meta() {
		$noindex_pages = array(
			'orbit-dashboard',
			'orbit-settings',
			'orbit-manage',
			'orbit-new-activity',
			'orbit-edit-activity',
			'orbit-subscribers',
			'orbit-edit-profile',
		);

		if ( is_page( $noindex_pages ) ) {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
			return;
		}

		// Activity pages should be noindex.
		if ( get_query_var( 'orbit_activity_id' ) ) {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		}
	}

	/**
	 * Add Disallow rules to robots.txt.
	 *
	 * @param string $output Existing robots.txt output.
	 * @param bool   $public Whether the site is public.
	 * @return string Modified robots.txt.
	 */
	public static function modify_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$output .= "\n# Orbit\n";
		$output .= "Disallow: /activity/\n";
		$output .= "Disallow: /dashboard/\n";
		$output .= "Disallow: /manage/\n";
		$output .= "Disallow: /settings/\n";
		$output .= "Disallow: /unsubscribe/\n";

		return $output;
	}
}
