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
		add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_from_home' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_routes' ) );
		add_action( 'wp_head', array( __CLASS__, 'add_noindex_meta' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'modify_robots_txt' ), 10, 2 );

		// Force the active theme's `page-app` template (when it provides
		// one) for Orbit virtual pages — activity detail, profile pages,
		// and the unsubscribe flow. Without this, WordPress falls back to
		// the default page template, which on the Perihelion theme means
		// the marketing-narrow layout with the marketing header instead
		// of the wider app layout with the app nav.
		foreach ( array( 'page_template_hierarchy', 'singular_template_hierarchy' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'force_app_template' ) );
		}
	}

	/**
	 * Redirect logged-in users from the marketing front page directly to
	 * their dashboard. The home page exists to convert prospects; once
	 * someone is logged in they don't need it.
	 *
	 * Only redirects from the actual front page — `/why`, `/privacy`, etc.
	 * remain reachable for logged-in users who want to share or reread
	 * them.
	 */
	public static function redirect_logged_in_from_home() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! is_front_page() ) {
			return;
		}

		wp_safe_redirect( home_url( '/dashboard/' ) );
		exit;
	}

	/**
	 * Prepend `page-app` to the template hierarchy when rendering one of
	 * Orbit's virtual pages, so themes that ship a `page-app` template
	 * pick it up. Themes without that template are unaffected — the
	 * hierarchy gracefully falls through to whatever they do provide.
	 *
	 * @param string[] $templates Existing template hierarchy.
	 * @return string[] Possibly-modified hierarchy.
	 */
	public static function force_app_template( $templates ) {
		if ( ! self::is_app_route() ) {
			return $templates;
		}

		array_unshift( $templates, 'page-app' );

		return $templates;
	}

	/**
	 * Whether the current request is one of Orbit's virtual-page routes.
	 *
	 * @return bool
	 */
	private static function is_app_route() {
		return (bool) (
			get_query_var( 'orbit_profile_slug' )
			|| get_query_var( 'orbit_activity_id' )
			|| get_query_var( 'orbit_unsubscribe' )
		);
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
	 *
	 * For routes that map to custom query vars (profile, activity),
	 * we create a virtual page with the appropriate shortcode so
	 * WordPress renders it through the normal page template.
	 */
	public static function handle_routes() {
		global $wp_query;

		$profile_slug = get_query_var( 'orbit_profile_slug' );
		$activity_id  = get_query_var( 'orbit_activity_id' );
		$unsubscribe  = get_query_var( 'orbit_unsubscribe' );

		if ( $profile_slug ) {
			self::handle_profile_route( $profile_slug );
		} elseif ( $activity_id ) {
			self::handle_activity_route( absint( $activity_id ) );
		} elseif ( $unsubscribe ) {
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

		$is_subscribe = get_query_var( 'orbit_subscribe' );
		$title        = $is_subscribe
			? sprintf( __( 'Subscribe to %s', 'orbit' ), $profile->display_name )
			: $profile->display_name;

		self::render_virtual_page( $title, '[orbit_profile]' );
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

		self::render_virtual_page( $activity->title, '[orbit_activity]' );
	}

	/**
	 * Handle unsubscribe route.
	 *
	 * GET  — validate the token and render a confirmation form.
	 * POST — verify the nonce, then process the unsubscribe.
	 */
	private static function handle_unsubscribe_route() {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ( $is_post ) {
			self::handle_unsubscribe_post();
			return;
		}

		self::handle_unsubscribe_get();
	}

	/**
	 * Render the unsubscribe confirmation form (GET step).
	 */
	private static function handle_unsubscribe_get() {
		$token = isset( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';

		if ( ! $token ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid unsubscribe link.', 'orbit' ) . '</p>'
			);
			return;
		}

		$subscription = Orbit_Subscription::get_by_secret( $token );

		if ( ! $subscription ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid or expired unsubscribe link.', 'orbit' ) . '</p>'
			);
			return;
		}

		$profile = Orbit_Profile::get( $subscription->profile_id );
		$name    = $profile ? esc_html( $profile->display_name ) : esc_html__( 'this poster', 'orbit' );

		ob_start();
		?>
		<p><?php echo esc_html( sprintf( __( 'Are you sure you want to unsubscribe from %s?', 'orbit' ), $profile ? $profile->display_name : __( 'this poster', 'orbit' ) ) ); ?></p>
		<form method="post" action="<?php echo esc_url( home_url( '/unsubscribe/' ) ); ?>">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'orbit_unsubscribe', 'orbit_unsubscribe_nonce' ); ?>
			<button type="submit"><?php esc_html_e( 'Confirm Unsubscribe', 'orbit' ); ?></button>
		</form>
		<?php
		$content = ob_get_clean();

		self::render_virtual_page( __( 'Unsubscribe', 'orbit' ), $content );
	}

	/**
	 * Process the unsubscribe action (POST step).
	 */
	private static function handle_unsubscribe_post() {
		$token = isset( $_POST['token'] )
			? sanitize_text_field( wp_unslash( $_POST['token'] ) )
			: '';

		$nonce = isset( $_POST['orbit_unsubscribe_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['orbit_unsubscribe_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'orbit_unsubscribe' ) ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Security check failed. Please try again using the link in your email.', 'orbit' ) . '</p>'
			);
			return;
		}

		if ( ! $token ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid unsubscribe link.', 'orbit' ) . '</p>'
			);
			return;
		}

		$subscription = Orbit_Subscription::get_by_secret( $token );

		if ( ! $subscription ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid or expired unsubscribe link.', 'orbit' ) . '</p>'
			);
			return;
		}

		$result = Orbit_Subscription::unsubscribe( $subscription->id );

		if ( is_wp_error( $result ) ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html( $result->get_error_message() ) . '</p>'
			);
			return;
		}

		$profile = Orbit_Profile::get( $subscription->profile_id );
		$name    = $profile ? $profile->display_name : __( 'this poster', 'orbit' );

		self::render_virtual_page(
			__( 'Unsubscribed', 'orbit' ),
			'<p>' . esc_html( sprintf( __( 'You have been unsubscribed from %s.', 'orbit' ), $name ) ) . '</p>'
		);
	}

	/**
	 * Render a virtual page with the given title and content.
	 *
	 * Replaces the main query with a synthetic page post so WordPress
	 * renders it using the page template (including FSE block themes).
	 *
	 * @param string $title   Page title.
	 * @param string $content Page content (may contain shortcodes).
	 */
	private static function render_virtual_page( $title, $content ) {
		global $wp_query;

		$post                    = new stdClass();
		$post->ID                = 0;
		$post->post_title        = $title;
		$post->post_content      = $content;
		$post->post_status       = 'publish';
		$post->post_type         = 'page';
		$post->post_name         = '';
		$post->post_author       = 0;
		$post->post_date         = current_time( 'mysql' );
		$post->post_date_gmt     = current_time( 'mysql', true );
		$post->post_modified     = current_time( 'mysql' );
		$post->post_modified_gmt = current_time( 'mysql', true );
		$post->comment_status    = 'closed';
		$post->ping_status       = 'closed';
		$post->filter            = 'raw';

		$wp_query->posts         = array( new WP_Post( $post ) );
		$wp_query->post          = $wp_query->posts[0];
		$wp_query->post_count    = 1;
		$wp_query->found_posts   = 1;
		$wp_query->is_page       = true;
		$wp_query->is_singular   = true;
		$wp_query->is_home       = false;
		$wp_query->is_archive    = false;
		$wp_query->is_404        = false;
		$wp_query->max_num_pages = 1;
	}

	/**
	 * Add noindex meta tag on authenticated and activity pages.
	 */
	public static function add_noindex_meta() {
		$noindex_pages = orbit_get_internal_page_slugs();

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
