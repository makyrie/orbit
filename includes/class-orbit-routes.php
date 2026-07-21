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
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_policy_urls' ) );
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
	 * Redirect superseded policy slugs to Orbit's canonical pages.
	 */
	public static function redirect_legacy_policy_urls() {
		$destination = self::legacy_policy_destination(
			isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
			home_url( '/' )
		);

		if ( ! $destination ) {
			return;
		}

		$kind = '/privacy/' === $destination ? 'privacy' : 'terms';
		if ( ! self::is_owned_canonical_policy_page( $kind ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Orbit_Routes: legacy policy redirect skipped because /%s/ is not a published Orbit-owned canonical page.', $kind ) );
			return;
		}

		wp_safe_redirect( home_url( $destination ), 301 );
		exit;
	}

	/**
	 * Whether a canonical policy destination is safe to expose via redirect.
	 *
	 * @param string $kind Privacy or terms.
	 * @return bool
	 */
	public static function is_owned_canonical_policy_page( $kind ) {
		$page = get_page_by_path( $kind, OBJECT, 'page' );
		return $page
			&& 'publish' === $page->post_status
			&& $kind === (string) get_post_meta( $page->ID, '_orbit_code_owned_page', true )
			&& $kind === (string) get_post_meta( $page->ID, '_orbit_canonical_compliance', true );
	}

	/**
	 * Resolve a request URI to a canonical policy path.
	 *
	 * @param string $request_uri Request URI, including an optional query.
	 * @param string $site_home   Site home URL, possibly in a subdirectory.
	 * @return string Empty string or the canonical root-relative path.
	 */
	public static function legacy_policy_destination( $request_uri, $site_home ) {
		$path      = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( $site_home, PHP_URL_PATH ), '/' );
		if ( $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
			$path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}
		$destinations = array(
			'privacy-policy'       => '/privacy/',
			'terms-and-conditions' => '/terms/',
		);

		return isset( $destinations[ $path ] ) ? $destinations[ $path ] : '';
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
			|| get_query_var( 'orbit_hi_code' )
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

		// /@{slug}/subscribe → subscription form (legacy; requires a valid token).
		add_rewrite_rule(
			'^@([^/]+)/subscribe/?$',
			'index.php?orbit_profile_slug=$matches[1]&orbit_subscribe=1',
			'top'
		);

		// /hi/{code} → the memorable invite link → subscription form.
		add_rewrite_rule(
			'^hi/([^/]+)/?$',
			'index.php?orbit_hi_code=$matches[1]',
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

		// Self-healing flush: the /hi/ rule is new, so register it once after an
		// in-place deploy without requiring a plugin re-activation. Bump the
		// stored value if these rules ever change again.
		if ( '1' !== get_option( 'orbit_routes_rewrite', '' ) ) {
			flush_rewrite_rules( false );
			update_option( 'orbit_routes_rewrite', '1' );
		}
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
		$vars[] = 'orbit_hi_code';
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
		$hi_code      = get_query_var( 'orbit_hi_code' );
		$activity_id  = get_query_var( 'orbit_activity_id' );
		$unsubscribe  = get_query_var( 'orbit_unsubscribe' );

		if ( $hi_code ) {
			self::handle_hi_route( $hi_code );
		} elseif ( $profile_slug ) {
			self::handle_profile_route( $profile_slug );
		} elseif ( $activity_id ) {
			self::handle_activity_route( absint( $activity_id ) );
		} elseif ( $unsubscribe ) {
			self::handle_unsubscribe_route();
		}
	}

	/**
	 * Whether the current viewer may see a profile's private surface (the
	 * profile page and its activities): the owner, or an approved subscriber.
	 * Everyone else gets nothing — profiles are private by default.
	 *
	 * @param object $profile Profile row.
	 * @return bool
	 */
	public static function viewer_can_see_profile( $profile ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( (int) $profile->user_id === $user_id ) {
			return true;
		}

		$subscription = Orbit_Subscription::get_by_user_and_profile( $user_id, $profile->id );
		return $subscription && 'approved' === $subscription->status;
	}

	/**
	 * Emit a hard 404 — used to hide private profiles/activities completely,
	 * revealing nothing (not even that the handle or code exists).
	 */
	private static function not_found() {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Handle profile page route.
	 *
	 * @param string $slug Profile slug.
	 */
	private static function handle_profile_route( $slug ) {
		$profile = Orbit_Profile::get_by_slug( sanitize_title( $slug ) );

		if ( ! $profile ) {
			self::not_found();
			return;
		}

		$is_subscribe = get_query_var( 'orbit_subscribe' );

		if ( $is_subscribe ) {
			// Legacy /@slug/subscribe: only valid with a matching share token
			// (the memorable /hi/<code> link is the primary invite path now).
			// Without it, reveal nothing — a bare slug is not a capability.
			$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $token || ! hash_equals( (string) $profile->share_token, $token ) ) {
				self::not_found();
				return;
			}

			set_query_var( 'orbit_current_profile', $profile );
			self::render_virtual_page(
				sprintf( __( 'Subscribe to %s', 'orbit' ), $profile->display_name ),
				'[orbit_profile]'
			);
			return;
		}

		// The profile page is private by default: only the owner or an approved
		// subscriber may see it (and the activity whereabouts it lists).
		if ( ! self::viewer_can_see_profile( $profile ) ) {
			self::not_found();
			return;
		}

		set_query_var( 'orbit_current_profile', $profile );
		self::render_virtual_page( $profile->display_name, '[orbit_profile]' );
	}

	/**
	 * Handle the memorable invite route, /hi/{code}.
	 *
	 * Resolves the profile by its share code and lands on the subscribe request
	 * form — never on the profile's activities. An unknown code 404s, revealing
	 * nothing. This is the one public door into an otherwise-private profile.
	 *
	 * @param string $code The share code.
	 */
	private static function handle_hi_route( $code ) {
		// Anti-enumeration: cap lookups per source IP. A human opening a link
		// they were given never trips this; a script walking the code space
		// does. On limit we 404 rather than 429 — consistent with the route's
		// "reveal nothing" stance, so a limited request is indistinguishable
		// from an unknown code.
		$ip = Orbit_Client_IP::get();
		$allowed = '' === $ip
			? Orbit_Rate_Limiter::attempt( 'hi_lookup_anon', '_anon', 10, MINUTE_IN_SECONDS )
			: Orbit_Rate_Limiter::attempt( 'hi_lookup', $ip, 30, MINUTE_IN_SECONDS );
		if ( ! $allowed ) {
			self::not_found();
			return;
		}

		$profile = Orbit_Profile::get_by_share_code( sanitize_text_field( wp_unslash( $code ) ) );

		if ( ! $profile ) {
			self::not_found();
			return;
		}

		set_query_var( 'orbit_current_profile', $profile );
		set_query_var( 'orbit_subscribe', 1 );
		self::render_virtual_page(
			sprintf( __( 'Subscribe to %s', 'orbit' ), $profile->display_name ),
			'[orbit_profile]'
		);
	}

	/**
	 * Handle activity detail route.
	 *
	 * @param int $activity_id Activity ID.
	 */
	private static function handle_activity_route( $activity_id ) {
		$activity = Orbit_Activity::get( $activity_id );

		if ( ! $activity ) {
			self::not_found();
			return;
		}

		// Activity detail is as private as the profile it belongs to: the owner,
		// an approved subscriber, or someone holding a valid action token from an
		// email link. Everyone else gets nothing — not even the title.
		if ( ! self::viewer_can_see_activity( $activity ) ) {
			self::not_found();
			return;
		}

		// Store activity data for shortcode consumption.
		set_query_var( 'orbit_current_activity', $activity );

		self::render_virtual_page( $activity->title, '[orbit_activity]' );
	}

	/**
	 * Whether the current viewer may see an activity's detail page.
	 *
	 * Mirrors the access resolution in the [orbit_activity] shortcode: owner of
	 * the poster profile, an approved logged-in subscriber, or a request bearing
	 * a valid action token (?act=) from an email link.
	 *
	 * @param object $activity Activity row.
	 * @return bool
	 */
	public static function viewer_can_see_activity( $activity ) {
		$profile = Orbit_Profile::get( (int) $activity->profile_id );
		if ( ! $profile ) {
			return false;
		}

		// Owner or approved subscriber (logged-in).
		if ( self::viewer_can_see_profile( $profile ) ) {
			return true;
		}

		// Action token from an email link — grants view to the specific
		// approved subscriber it was minted for, even when logged out.
		$act_token = isset( $_GET['act'] ) ? sanitize_text_field( wp_unslash( $_GET['act'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $act_token ) {
			$sub_id = Orbit_Token::extract_subscription_id( $act_token );
			if ( $sub_id ) {
				$sub = Orbit_Subscription::get( $sub_id );
				if ( $sub
					&& 'approved' === $sub->status
					&& (int) $sub->profile_id === (int) $activity->profile_id
					&& Orbit_Token::validate_action_token( $act_token, $sub->subscription_secret, (int) $activity->id )
				) {
					return true;
				}
			}
		}

		return false;
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
	 *
	 * Public for test access — production callers only reach it via
	 * `handle_unsubscribe_route()` on the `template_redirect` action.
	 */
	public static function handle_unsubscribe_get() {
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
	 *
	 * Public for test access — production callers only reach it via
	 * `handle_unsubscribe_route()` on the `template_redirect` action.
	 */
	public static function handle_unsubscribe_post() {
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
	 * RFC 8058 one-click handler — production wrapper that emits + exits.
	 *
	 * Delegates the response computation to `one_click_unsubscribe_response()`
	 * (pure: no echo, no exit) so tests can assert on the returned payload
	 * without process isolation. The wrapper here is what production calls
	 * via `handle_unsubscribe_post()`.
	 *
	 * @param string $token Token from POST body.
	 */
	private static function handle_one_click_unsubscribe( $token ) {
		$response = self::one_click_unsubscribe_response( $token );
		self::emit_response( $response['status'], $response['body'] );
	}

	/**
	 * Compute the one-click unsubscribe response without emitting it.
	 *
	 * Rate-limits the source IP (30/min) — or falls back to a global
	 * anon bucket with a tighter cap (5/min) when the client IP can't
	 * be resolved — before doing any auth or DB work. Then validates
	 * the HMAC token and idempotently unsubscribes. Returned shape is
	 * always `array( 'status' => int, 'body' => string )` so tests can
	 * assert on the exact wire-level response.
	 *
	 * @param string $token Token from POST body.
	 * @return array{status:int,body:string}
	 */
	public static function one_click_unsubscribe_response( $token ) {
		$ip = Orbit_Client_IP::get();

		if ( '' === $ip ) {
			// Empty IP fails open without a fallback bucket — use a
			// global anon bucket with a much tighter cap so a client
			// that strips identifying headers can't replay forever.
			if ( ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click_anon', '_anon', 5, MINUTE_IN_SECONDS ) ) {
				return array(
					'status' => 429,
					'body'   => 'rate_limited',
				);
			}
		} elseif ( ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click', $ip, 30, MINUTE_IN_SECONDS ) ) {
			return array(
				'status' => 429,
				'body'   => 'rate_limited',
			);
		}

		if ( ! $token ) {
			return array(
				'status' => 400,
				'body'   => 'invalid_token',
			);
		}

		$subscription = self::resolve_unsubscribe_subscription( $token );

		if ( ! $subscription ) {
			return array(
				'status' => 400,
				'body'   => 'invalid_token',
			);
		}

		$perform_result = self::perform_unsubscribe( $subscription, 'email_unsubscribe_one_click' );

		if ( is_wp_error( $perform_result ) ) {
			// Surface a 4xx so the mail client doesn't believe an
			// unsubscribe succeeded when the subscription update
			// actually failed (e.g., a status-machine reject).
			return array(
				'status' => 400,
				'body'   => 'unsubscribe_failed',
			);
		}

		return array(
			'status' => 200,
			'body'   => 'unsubscribed',
		);
	}

	/**
	 * Emit a plain-text response and terminate the request.
	 *
	 * Wraps the `status_header` + `Content-Type` + `echo` + `exit`
	 * boilerplate so the testable response-shape functions don't have
	 * to call `exit` directly. Production paths call this from the
	 * thin handler wrappers; tests never reach it.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Plain-text body.
	 * @return void
	 */
	private static function emit_response( $status, $body ) {
		status_header( (int) $status );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( (string) $body );
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
	 * The legacy fallback is time-boxed via `ORBIT_LEGACY_UNSUB_TOKEN_SUNSET`.
	 * After that date the resolver returns null for legacy-format tokens —
	 * a leaked pre-cutover email spool is then no longer indefinitely
	 * actionable. Every legacy-format hit is logged via `error_log()` so
	 * ops can watch when legacy traffic actually drops to zero and
	 * confirm the sunset is safe.
	 *
	 * @param string $token Token from query string or POST body.
	 * @return object|null Subscription row, or null if invalid.
	 */
	public static function resolve_unsubscribe_subscription( $token ) {
		// Try modern HMAC format first.
		$subscription_id = Orbit_Token::extract_subscription_id( $token );
		if ( $subscription_id ) {
			$subscription = Orbit_Subscription::get( $subscription_id );
			if ( $subscription && Orbit_Token::validate_unsubscribe_token( $token, $subscription->subscription_secret, (int) $subscription->id ) ) {
				return $subscription;
			}
		}

		// Legacy fallback: token is the raw subscription_secret.
		// Time-box the fallback so leaked pre-cutover spools have a
		// bounded blast radius matching the new HMAC TTL.
		if ( defined( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET' )
			&& time() >= strtotime( ORBIT_LEGACY_UNSUB_TOKEN_SUNSET . ' UTC' )
		) {
			return null;
		}

		$legacy = Orbit_Subscription::get_by_secret( $token );

		if ( $legacy ) {
			// Telemetry: surface every legacy hit so ops can watch the
			// rate decay before flipping the sunset. Using error_log()
			// (not the notification log) keeps this out of the user-
			// facing audit surface and into ops dashboards.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'orbit: legacy unsubscribe fallback hit (subscription_id=%d)',
					(int) $legacy->id
				)
			);
		}

		return $legacy;
	}

	/**
	 * Idempotent unsubscribe with consent ledger write.
	 *
	 * Idempotency is checked at the **subscription** level, not at the
	 * channel-global ledger level. Reasoning: the consent ledger is
	 * keyed on (user_id, channel) — but a user with multiple poster
	 * subscriptions can correctly unsubscribe from each one independently.
	 * Using the channel-global state as the short-circuit would silently
	 * skip the subscription-row update for every subsequent unsubscribe
	 * after the first, leaving the user still receiving emails from the
	 * other posters.
	 *
	 * Multiple per-subscription opt_out rows in the ledger are the
	 * correct shape: the channel is the right granularity for TCPA
	 * evidence (was this user opted-in / opted-out at any point), and
	 * the subscription is the right granularity for the operation
	 * (which specific connection are we ending).
	 *
	 * @param object $subscription Subscription row.
	 * @param string $source       Free-form source label written to the consent row.
	 * @return true|WP_Error
	 */
	public static function perform_unsubscribe( $subscription, $source ) {
		if ( 'unsubscribed' === $subscription->status ) {
			// Already unsubscribed at the subscription level —
			// idempotent no-op. No ledger row appended; the prior
			// opt_out is still the latest event for this subscription.
			return true;
		}

		$result = Orbit_Subscription::unsubscribe( $subscription->id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Orbit_Consent::record(
			(int) $subscription->user_id,
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
	 * exact body `List-Unsubscribe=One-Click`. `List-Unsubscribe-Post` is
	 * a **sender** header on the outbound email — it's NOT echoed back
	 * by mail clients as a request header, so we don't (and shouldn't)
	 * check for it on the way in. The strict RFC body-shape check is the
	 * only signal we accept.
	 *
	 * The `is_string()` guard prevents a crash on the array shape
	 * (`List-Unsubscribe[]=One-Click`) — which would also slip past a
	 * naive `===` against the string `'One-Click'`.
	 *
	 * @return bool
	 */
	public static function is_one_click_unsubscribe_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- One-click POSTs are auth'd via HMAC token, not nonce.
		if ( ! isset( $_POST['List-Unsubscribe'] ) ) {
			return false;
		}

		if ( ! is_string( $_POST['List-Unsubscribe'] ) ) {
			return false;
		}

		return 'One-Click' === wp_unslash( $_POST['List-Unsubscribe'] );
		// phpcs:enable
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
