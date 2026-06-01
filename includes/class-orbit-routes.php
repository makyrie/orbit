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
		// Priority 5 so the logged-in front-page redirect runs before
		// `redirect_canonical` (priority 10) and any third-party
		// `template_redirect` work that might short-circuit on the home page.
		add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_from_home' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_routes' ) );
		add_action( 'wp_head', array( __CLASS__, 'add_noindex_meta' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'modify_robots_txt' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'redirect_after_login' ), 10, 3 );

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

		// Bail for Orbit virtual pages (profile, activity, unsubscribe).
		// At template_redirect priority 5 the rewrite has set their query
		// vars, but `handle_routes()` (priority 10) hasn't yet replaced the
		// main query — so `is_front_page()` still reports true on these
		// URLs, which would incorrectly redirect logged-in users away from
		// every Orbit virtual page.
		if ( self::is_app_route() ) {
			return;
		}

		if ( ! is_front_page() ) {
			return;
		}

		nocache_headers();
		wp_safe_redirect( home_url( '/dashboard/' ), 303 );
		exit;
	}

	/**
	 * After a successful login, send Orbit users to /dashboard/ instead of
	 * the WordPress admin. Admin-role users land in /wp-admin/ by default,
	 * which is wrong for a forward-facing app — they want their dashboard.
	 *
	 * Honors any explicit `redirect_to` the login form already passed
	 * through (e.g. when login was reached from an interstitial page that
	 * wants the user back where they were).
	 *
	 * @param string           $redirect_to           URL the user is about to be redirected to.
	 * @param string           $requested_redirect_to URL the user requested via redirect_to (often empty).
	 * @param WP_User|WP_Error $user                  Logged-in user, or error.
	 * @return string Final redirect URL.
	 */
	public static function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		$dashboard_url    = home_url( '/dashboard/' );
		$edit_profile_url = home_url( '/edit-profile/' );

		// Honor an explicit non-admin `redirect_to`, but validate it locally
		// rather than relying on the downstream `wp_safe_redirect()` host
		// check. `wp_validate_redirect()` enforces the `allowed_redirect_hosts`
		// allowlist and returns the dashboard fallback for anything off-site,
		// malformed, or empty — defense in depth regardless of where the
		// redirect ultimately fires. Admin-default `redirect_to` values fall
		// through to the dashboard; admin-role users wanting wp-admin are an
		// edge case Orbit doesn't optimize for.
		if ( $requested_redirect_to && 0 !== strpos( $requested_redirect_to, admin_url() ) ) {
			return wp_validate_redirect( $requested_redirect_to, $dashboard_url );
		}

		// Greenfield-poster fast path: if the user has no profile AND no
		// subscriptions, they're a fresh `users_can_register=1` signup
		// who came in to be a poster. Send them straight to the profile
		// editor instead of the (empty) dashboard. Subscribers, who at
		// minimum have one subscription, fall through to /dashboard/.
		if ( ! Orbit_Profile::get_by_user_id( $user->ID ) ) {
			$has_subscriptions = ! empty( Orbit_Subscription::list( array(
				'user_id'  => $user->ID,
				'per_page' => 1,
			) ) );

			if ( ! $has_subscriptions ) {
				return $edit_profile_url;
			}
		}

		return $dashboard_url;
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

		// Defensive: if another filter handed us something other than an
		// array, fall back to a sane single-entry hierarchy.
		if ( ! is_array( $templates ) ) {
			return array( 'page-app' );
		}

		// Both `page_template_hierarchy` and `singular_template_hierarchy`
		// fire for our virtual pages, so guard against double-prepending
		// `page-app` when this filter runs twice on the same request.
		if ( ! in_array( 'page-app', $templates, true ) ) {
			array_unshift( $templates, 'page-app' );
		}

		return $templates;
	}

	/**
	 * Whether the current request is one of Orbit's virtual-page routes.
	 *
	 * @return bool
	 */
	public static function is_app_route() {
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
	 * GET  — validate the token and render a confirmation form (two-step
	 *        pattern; email-scanner-safe per CTIA and our prior security
	 *        review at docs/solutions/security-issues/poster-setup-flow-
	 *        and-security-review.md:101-113).
	 *
	 * POST — two paths:
	 *        (a) RFC 8058 one-click: when the request carries
	 *            `List-Unsubscribe=One-Click` in the POST body or the
	 *            equivalent header, act immediately. No nonce (mail
	 *            clients don't have one); HMAC token IS the auth.
	 *        (b) Two-step confirmation form: verify the nonce as before.
	 *
	 * One-click POSTs are rate-limited to 30/IP/min — a leaked mail spool
	 * cannot be replayed to bulk-unsubscribe other recipients in seconds.
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
				'<p>' . esc_html__( 'Invalid unsubscribe link.', 'orbit' ) . '</p>',
				true
			);
			return;
		}

		$subscription = self::resolve_unsubscribe_subscription( $token );

		if ( ! $subscription ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid or expired unsubscribe link.', 'orbit' ) . '</p>',
				true
			);
			return;
		}

		$profile = Orbit_Profile::get( $subscription->profile_id );

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

		self::render_virtual_page( __( 'Unsubscribe', 'orbit' ), $content, true );
	}

	/**
	 * Process the unsubscribe action (POST step).
	 *
	 * Branches between RFC 8058 one-click (mail-client driven, no nonce,
	 * rate-limited) and two-step confirmation (form post with nonce).
	 */
	private static function handle_unsubscribe_post() {
		$token = isset( $_POST['token'] )
			? sanitize_text_field( wp_unslash( $_POST['token'] ) )
			: '';

		$is_one_click = self::is_one_click_unsubscribe_post();

		if ( $is_one_click ) {
			self::handle_one_click_unsubscribe( $token );
			return;
		}

		// Two-step confirmation flow — nonce required.
		$nonce = isset( $_POST['orbit_unsubscribe_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['orbit_unsubscribe_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'orbit_unsubscribe' ) ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Security check failed. Please try again using the link in your email.', 'orbit' ) . '</p>',
				true
			);
			return;
		}

		if ( ! $token ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid unsubscribe link.', 'orbit' ) . '</p>',
				true
			);
			return;
		}

		$subscription = self::resolve_unsubscribe_subscription( $token );

		if ( ! $subscription ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html__( 'Invalid or expired unsubscribe link.', 'orbit' ) . '</p>',
				true
			);
			return;
		}

		$result = self::perform_unsubscribe( $subscription, 'email_unsubscribe_form' );

		if ( is_wp_error( $result ) ) {
			self::render_virtual_page(
				__( 'Unsubscribe', 'orbit' ),
				'<p>' . esc_html( $result->get_error_message() ) . '</p>',
				true
			);
			return;
		}

		$profile = Orbit_Profile::get( $subscription->profile_id );
		$name    = $profile ? $profile->display_name : __( 'this poster', 'orbit' );

		self::render_virtual_page(
			__( 'Unsubscribed', 'orbit' ),
			'<p>' . esc_html( sprintf( __( 'You have been unsubscribed from %s.', 'orbit' ), $name ) ) . '</p>',
			true
		);
	}

	/**
	 * RFC 8058 one-click handler.
	 *
	 * Rate-limit checks the source IP (30/min) before doing any auth or
	 * DB work, then validates the HMAC token, then idempotently
	 * unsubscribes. Returns a plain 200 to the mail client — mail UI
	 * does not render a page.
	 *
	 * @param string $token Token from POST body.
	 */
	private static function handle_one_click_unsubscribe( $token ) {
		$ip = Orbit_Client_IP::get();

		if ( '' !== $ip && ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click', $ip, 30, MINUTE_IN_SECONDS ) ) {
			status_header( 429 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'rate_limited';
			exit;
		}

		if ( ! $token ) {
			status_header( 400 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'invalid_token';
			exit;
		}

		$subscription = self::resolve_unsubscribe_subscription( $token );

		if ( ! $subscription ) {
			status_header( 400 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'invalid_token';
			exit;
		}

		self::perform_unsubscribe( $subscription, 'email_unsubscribe_one_click' );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'unsubscribed';
		exit;
	}

	/**
	 * Resolve an unsubscribe token to a subscription row.
	 *
	 * Tries the modern HMAC-signed format first: format is
	 * `{subscription_id}.{base64(expiry)}:{hmac}` (see Orbit_Token).
	 * Falls back to the legacy raw-secret-as-token format for emails
	 * sent before the cut-over so users who haven't read their inbox
	 * in a while still get a working link.
	 *
	 * @param string $token Token from query string or POST body.
	 * @return object|null Subscription row, or null if invalid.
	 */
	private static function resolve_unsubscribe_subscription( $token ) {
		// Try modern HMAC format first.
		$subscription_id = Orbit_Token::extract_subscription_id( $token );
		if ( $subscription_id ) {
			$subscription = Orbit_Subscription::get( $subscription_id );
			if ( $subscription && Orbit_Token::validate_unsubscribe_token( $token, $subscription->subscription_secret, (int) $subscription->id ) ) {
				return $subscription;
			}
		}

		// Legacy fallback: token is the raw subscription_secret.
		return Orbit_Subscription::get_by_secret( $token );
	}

	/**
	 * Idempotent unsubscribe with consent ledger write.
	 *
	 * If the user's latest email consent state is already `opt_out`, the
	 * call is a no-op — the subscription stays unsubscribed and no new
	 * ledger row is appended (RFC 8058 allows replays; we don't want
	 * duplicate audit events).
	 *
	 * @param object $subscription Subscription row.
	 * @param string $source       Free-form source label written to the consent row.
	 * @return true|WP_Error
	 */
	private static function perform_unsubscribe( $subscription, $source ) {
		$user_id = (int) $subscription->user_id;

		// Idempotent replay: if already opted out for email, skip the
		// subscription update and ledger append.
		if ( 'opt_out' === Orbit_Consent::latest_state( $user_id, 'email' ) ) {
			return true;
		}

		$result = Orbit_Subscription::unsubscribe( $subscription->id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Orbit_Consent::record(
			$user_id,
			'email',
			'opt_out',
			array(
				'source' => $source,
			)
		);

		return true;
	}

	/**
	 * Whether the current POST request is an RFC 8058 one-click unsubscribe.
	 *
	 * Per RFC 8058 §3.2, mail clients indicate one-click by POSTing the
	 * exact body `List-Unsubscribe=One-Click`. We accept that body shape,
	 * AND the equivalent `List-Unsubscribe` header (some clients pass it
	 * as a header instead) as a permissive interpretation.
	 *
	 * @return bool
	 */
	private static function is_one_click_unsubscribe_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- One-click POSTs are auth'd via HMAC token, not nonce.
		if ( isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === wp_unslash( $_POST['List-Unsubscribe'] ) ) {
			return true;
		}
		// phpcs:enable

		if ( ! empty( $_SERVER['HTTP_LIST_UNSUBSCRIBE_POST'] )
			&& false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_LIST_UNSUBSCRIBE_POST'] ) ), 'One-Click' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Render a virtual page with the given title and content.
	 *
	 * Replaces the main query with a synthetic page post so WordPress
	 * renders it using the page template (including FSE block themes).
	 *
	 * @param string $title          Page title.
	 * @param string $content        Page content (may contain shortcodes).
	 * @param bool   $prepend_title  Whether to prepend the title as an `<h1>`
	 *                               in the rendered content. Useful for routes
	 *                               whose body is a bare paragraph or form,
	 *                               since `page-app.html` does not render
	 *                               `wp:post-title`. Routes whose shortcode
	 *                               already self-renders an `<h1>` (e.g.
	 *                               `[orbit_profile]`, `[orbit_activity]`)
	 *                               should leave this false to avoid doubling
	 *                               the heading.
	 */
	private static function render_virtual_page( $title, $content, $prepend_title = false ) {
		global $wp_query;

		if ( $prepend_title ) {
			$content = '<h1>' . esc_html( $title ) . '</h1>' . $content;
		}

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

		// Activity, profile, and unsubscribe routes should all be noindex.
		if ( self::is_app_route() ) {
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
