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
		add_shortcode( 'orbit_my_subscriptions', array( __CLASS__, 'my_subscriptions' ) );
		add_shortcode( 'orbit_manage', array( __CLASS__, 'manage' ) );
		add_shortcode( 'orbit_new_activity', array( __CLASS__, 'new_activity' ) );
		add_shortcode( 'orbit_edit_activity', array( __CLASS__, 'edit_activity' ) );
		add_shortcode( 'orbit_subscribers', array( __CLASS__, 'subscribers' ) );
		add_shortcode( 'orbit_edit_profile', array( __CLASS__, 'edit_profile' ) );
		add_shortcode( 'orbit_profile', array( __CLASS__, 'profile' ) );
		add_shortcode( 'orbit_subscribe_form', array( __CLASS__, 'subscribe_form' ) );
		add_shortcode( 'orbit_activity', array( __CLASS__, 'activity' ) );
		add_shortcode( 'orbit_cta', array( __CLASS__, 'cta' ) );
		add_shortcode( 'orbit_sign_up', array( __CLASS__, 'sign_up' ) );
	}

	/**
	 * Render a user-aware call-to-action button.
	 *
	 * Used in the marketing-site front page so the CTA adapts to the
	 * viewer's state instead of always pointing at one destination.
	 *
	 *   - Logged-out: Sign up → /sign-up/
	 *   - Logged-in without a profile: Set up your profile → /edit-profile/
	 *   - Logged-in with a profile: Go to your dashboard → /dashboard/
	 *
	 * Output is wrapped in WP's button-block markup so it inherits the
	 * theme's button styling.
	 *
	 * @param array $atts Shortcode attributes (none used currently).
	 * @return string Rendered HTML for a single button block.
	 */
	public static function cta( $atts ) {
		if ( ! is_user_logged_in() ) {
			$href  = home_url( '/sign-up/' );
			$label = _x( 'Sign up', 'orbit_cta button label', 'orbit' );
		} elseif ( Orbit_Profile::get_by_user_id( get_current_user_id() ) ) {
			$href  = home_url( '/dashboard/' );
			$label = _x( 'Go to your dashboard', 'orbit_cta button label', 'orbit' );
		} else {
			$href  = home_url( '/edit-profile/' );
			$label = _x( 'Set up your profile', 'orbit_cta button label', 'orbit' );
		}

		return sprintf(
			'<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div></div>',
			esc_url( $href ),
			esc_html( $label )
		);
	}

	/**
	 * Sign-up form.
	 *
	 * Marketing-surface form for new posters. Calls POST
	 * `orbit/v1/signup` (handled by `Orbit_REST_Signup`), which creates
	 * a WP user, auto-logs them in, sends the password-set email, and
	 * returns a `redirect_url` to /edit-profile/ so the JS handler can
	 * forward them straight into setting up their profile.
	 *
	 * Multisite-friendly: bypasses wp-signup.php (which requires
	 * `users_can_register` at the network level and forces a two-step
	 * email-confirm flow that's overkill for an invite-driven product).
	 *
	 * @param array $atts Shortcode attributes (none used currently).
	 * @return string HTML output.
	 */
	public static function sign_up( $atts ) {
		if ( is_user_logged_in() ) {
			// Logged in already → bounce to the natural next step. The
			// `redirect_after_login` filter already handles the
			// no-profile-yet → /edit-profile/ branch, so we just match
			// that here for direct hits to /sign-up/.
			$has_profile = (bool) Orbit_Profile::get_by_user_id( get_current_user_id() );
			$href        = $has_profile ? home_url( '/dashboard/' ) : home_url( '/edit-profile/' );

			return sprintf(
				'<p class="orbit-empty">%s <a href="%s">%s</a></p>',
				esc_html__( "You're already signed in.", 'orbit' ),
				esc_url( $href ),
				esc_html( $has_profile ? __( 'Go to your dashboard', 'orbit' ) : __( 'Set up your profile', 'orbit' ) )
			);
		}

		$login_url = wp_login_url( home_url( '/dashboard/' ) );

		ob_start();
		?>
		<h1 class="orbit-h1"><?php esc_html_e( 'Create your account', 'orbit' ); ?></h1>
		<p class="orbit-page-intro">
			<?php esc_html_e( "A poster account lets you share what you're up to with the friends you invite. Two steps: this one, then a short profile so people know who they're subscribing to.", 'orbit' ); ?>
		</p>
		<p class="orbit-required-note">
			<?php
			echo wp_kses(
				/* translators: %s is a red asterisk indicating the required-field marker. */
				sprintf( __( 'Fields marked with %s are required.', 'orbit' ), '<span class="orbit-required-mark" aria-hidden="true">*</span>' ),
				array( 'span' => array( 'class' => array(), 'aria-hidden' => array() ) )
			);
			?>
		</p>
		<form data-orbit-api="signup" method="post" class="orbit-form">
			<p>
				<label for="orbit-signup-name">
					<?php esc_html_e( 'Your name', 'orbit' ); ?>
					<span class="orbit-required-mark" aria-hidden="true">*</span>
				</label>
				<input type="text" id="orbit-signup-name" name="display_name" required autocomplete="name">
				<span class="orbit-field-help"><?php esc_html_e( "How you'll appear on activity cards and your public profile. You can change it later.", 'orbit' ); ?></span>
			</p>
			<p>
				<label for="orbit-signup-email">
					<?php esc_html_e( 'Email', 'orbit' ); ?>
					<span class="orbit-required-mark" aria-hidden="true">*</span>
				</label>
				<input type="email" id="orbit-signup-email" name="email" required autocomplete="email">
				<span class="orbit-field-help"><?php esc_html_e( 'Used to send you activity notifications and a link to set your password.', 'orbit' ); ?></span>
			</p>
			<?php
			// Phone capture + compliance disclosure + per-channel consent.
			// Same building blocks the subscribe form uses (Phase 2b) so
			// the rendered text and ledger snapshots agree across all
			// opt-in surfaces.
			echo self::render_phone_field( 'orbit-signup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
			echo self::render_compliance_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
			echo self::render_consent_checkboxes( 'orbit-signup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
			?>
			<?php Orbit_Spam::render_traps(); ?>
			<p>
				<button type="submit" class="orbit-btn orbit-btn-primary"><?php esc_html_e( 'Create account', 'orbit' ); ?></button>
			</p>
			<p class="orbit-form-footer">
				<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Already have an account? Log in', 'orbit' ); ?></a>
			</p>
		</form>
		<?php
		return ob_get_clean();
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

		// Collect all profile IDs (subscriptions + own profile).
		$profile_ids = array_map( function ( $s ) {
			return (int) $s->profile_id;
		}, $subscriptions );

		$own_profile = Orbit_Profile::get_by_user_id( $user_id );
		if ( $own_profile ) {
			$profile_ids[] = (int) $own_profile->id;
		}

		$profile_ids = array_unique( $profile_ids );

		// Single query for all activities across all profiles, sorted by
		// when they're happening so the soonest upcoming events appear first.
		// Undated activities (e.g. tier 1 "just an idea") sort to the end.
		$activities = Orbit_Activity::list_by_profile_ids(
			$profile_ids,
			array(
				'status'   => 'active',
				'per_page' => 50,
				'orderby'  => 'date_time',
				'order'    => 'ASC',
			)
		);

		ob_start();

		echo '<div class="orbit-dashboard">';

		echo '<h1>' . esc_html__( 'Dashboard', 'orbit' ) . '</h1>';

		// One-time onboarding banner for users who haven't verified a phone
		// yet. Dismissable per-user via the `orbit_dashboard_banner_dismissed`
		// user_meta. Shown when:
		// - The user has no verified phone.
		// - Settings page is reachable to act on the banner.
		// During the dormant window this is the primary path that surfaces
		// the SMS opt-in surface to new posters — without it /settings/ is
		// unreachable via the post-signup redirect.
		$has_verified_phone = (bool) get_user_meta( $user_id, 'orbit_phone_verified', true );
		$dismissed          = (bool) get_user_meta( $user_id, 'orbit_dashboard_banner_dismissed', true );

		// Gate on Orbit_Features::sms_enabled() so the "as soon as our SMS
		// program launches" copy doesn't keep rendering after SMS goes
		// live. Post-launch the banner disappears for unverified users
		// entirely; a verify-your-phone CTA can land elsewhere later (per
		// todo 128). The banner body itself comes from
		// Orbit_Messaging_Copy so the dormancy copy stays a one-flag flip.
		if ( ! Orbit_Features::sms_enabled() && ! $has_verified_phone && ! $dismissed ) {
			$settings_link = '<a href="' . esc_url( home_url( '/settings/' ) ) . '">'
				. esc_html__( 'verify your phone in Settings', 'orbit' )
				. '</a>';

			$banner_body = Orbit_Messaging_Copy::dashboard_onboarding_banner_copy();
			// The body comes back with an `{settings_link}` placeholder
			// so the helper itself stays HTML-free; we substitute the
			// anchor here after escaping the surrounding sentence.
			$banner_html = str_replace(
				'{settings_link}',
				$settings_link,
				esc_html( $banner_body )
			);

			echo '<div class="orbit-onboarding-banner" data-orbit-onboarding-banner>';
			echo '<p>' . wp_kses(
				$banner_html,
				array(
					'a' => array(
						'href' => array(),
					),
				)
			) . '</p>';
			echo '<button type="button" class="orbit-btn-link" data-orbit-onboarding-dismiss aria-label="' . esc_attr__( 'Dismiss this banner', 'orbit' ) . '">';
			echo esc_html_x( 'Dismiss', 'banner action', 'orbit' );
			echo '</button>';
			echo '</div>';
		}

		if ( ! empty( $activities ) ) {
			echo '<p class="orbit-page-intro">' . esc_html__( 'Upcoming activities from you and the people you\'ve subscribed to, soonest first.', 'orbit' ) . '</p>';
		}

		if ( empty( $activities ) ) {
			// Check for pending subscriptions.
			$pending_subs = Orbit_Subscription::list(
				array(
					'user_id'  => $user_id,
					'status'   => 'pending',
					'per_page' => 100,
				)
			);

			if ( ! empty( $pending_subs ) ) {
				$pending_count = count( $pending_subs );
				echo '<p class="orbit-notice">';
				echo esc_html( sprintf(
					/* translators: %d: count of pending subscriptions */
					_n(
						'You\'ve subscribed to %d person who hasn\'t approved you yet. Their activities will appear here once they do.',
						'You\'ve subscribed to %d people who haven\'t approved you yet. Their activities will appear here once they do.',
						$pending_count,
						'orbit'
					),
					$pending_count
				) );
				echo '</p>';
			} else {
				echo '<p>' . esc_html__( 'No activities yet. Subscribe to someone to see their activities here.', 'orbit' ) . '</p>';
			}

			if ( current_user_can( 'orbit_create_activity' ) ) {
				echo '<p><a href="' . esc_url( home_url( '/manage/' ) ) . '" class="orbit-btn">';
				echo esc_html__( 'Manage Your Activities', 'orbit' );
				echo '</a></p>';
			} else {
				echo '<p class="orbit-cta">';
				echo esc_html__( 'Want to share your own activities?', 'orbit' ) . ' ';
				echo '<a href="' . esc_url( home_url( '/edit-profile/' ) ) . '">';
				echo esc_html__( 'Create a profile', 'orbit' );
				echo '</a></p>';
			}
		}

		$tier_labels = Orbit_Activity::get_tier_labels();

		// Batch-load profiles and response counts.
		$needed_profile_ids = array_unique( array_map( function ( $a ) {
			return (int) $a->profile_id;
		}, $activities ) );
		$profiles_map = Orbit_Profile::get_by_ids( $needed_profile_ids );

		$activity_ids    = array_map( function ( $a ) { return (int) $a->id; }, $activities );
		$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );

		// Load current user's responses to show "You: Going/Maybe" on cards.
		$my_responses_map = array();
		$user_responses   = Orbit_Response::list_by_user( $user_id );
		foreach ( $user_responses as $resp ) {
			$my_responses_map[ (int) $resp->activity_id ] = $resp->response;
		}

		$own_profile_id = $own_profile ? (int) $own_profile->id : 0;

		foreach ( $activities as $activity ) {
			$profile    = isset( $profiles_map[ (int) $activity->profile_id ] ) ? $profiles_map[ (int) $activity->profile_id ] : null;
			$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';
			$is_mine    = $own_profile_id && (int) $activity->profile_id === $own_profile_id;

			$card_class = 'orbit-activity-card';
			if ( $is_mine ) {
				$card_class .= ' orbit-activity-card--mine';
			}

			echo '<div class="' . esc_attr( $card_class ) . '" data-tier="' . esc_attr( $activity->tier ) . '">';
			echo '<div class="orbit-activity-meta">';

			if ( $is_mine ) {
				echo '<span class="orbit-poster-name orbit-poster-name--mine">' . esc_html__( 'You', 'orbit' ) . '</span>';
			} elseif ( $profile ) {
				echo '<a class="orbit-poster-name" href="' . esc_url( home_url( '/@' . $profile->slug ) ) . '">' . esc_html( $profile->display_name ) . '</a>';
			}

			echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';
			echo '</div>';

			echo '<h3 class="orbit-activity-title">';
			echo '<a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">';
			echo esc_html( $activity->title );
			echo '</a></h3>';

			if ( $activity->date_time ) {
				echo '<p class="orbit-activity-date">' . esc_html( self::format_datetime( $activity->date_time ) ) . '</p>';
			} else {
				echo '<p class="orbit-activity-date orbit-activity-date--undated">' . esc_html__( 'No date set', 'orbit' ) . '</p>';
			}

			if ( $activity->location_name ) {
				echo '<p class="orbit-activity-location">' . esc_html( $activity->location_name ) . '</p>';
			}

			// Show response counts (from batch-loaded data).
			$counts      = isset( $response_counts[ $activity->id ] ) ? $response_counts[ $activity->id ] : array( 'going' => 0, 'maybe' => 0 );
			$going_count = $counts['going'];
			$maybe_count = $counts['maybe'];

			if ( 'none' !== $activity->show_attendees && ( $going_count || $maybe_count ) ) {
				echo '<p class="orbit-response-counts">';
				if ( $going_count ) {
					/* translators: %d: count of subscribers going */
					echo esc_html( sprintf( _n( '%d going', '%d going', $going_count, 'orbit' ), $going_count ) );
				}
				if ( $going_count && $maybe_count ) {
					echo ' &middot; ';
				}
				if ( $maybe_count ) {
					/* translators: %d: count of subscribers responding maybe */
					echo esc_html( sprintf( _n( '%d maybe', '%d maybe', $maybe_count, 'orbit' ), $maybe_count ) );
				}
				echo '</p>';
			}

			// Show user's own response status.
			$my_response = isset( $my_responses_map[ (int) $activity->id ] ) ? $my_responses_map[ (int) $activity->id ] : null;
			if ( $my_response ) {
				$response_label = 'going' === $my_response ? __( "You're going", 'orbit' ) : __( 'You said maybe', 'orbit' );
				echo '<p class="orbit-my-response">' . esc_html( $response_label ) . '</p>';
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

		$tier_labels = Orbit_Activity::get_tier_labels();

		ob_start();

		$method_labels = array(
			'sms'    => _x( 'SMS', 'notification method', 'orbit' ),
			'email'  => _x( 'Email', 'notification method', 'orbit' ),
			'digest' => _x( 'Digest', 'notification method', 'orbit' ),
			'none'   => _x( 'None', 'notification method', 'orbit' ),
		);

		$wp_timezone     = wp_timezone_string();
		$digest_tz_label = sprintf(
			/* translators: %s: site timezone (e.g. "America/Los_Angeles" or "+01:00") */
			__( 'Site timezone: %s', 'orbit' ),
			$wp_timezone
		);

		echo '<div class="orbit-settings">';

		echo '<h1>' . esc_html__( 'Settings', 'orbit' ) . '</h1>';
		echo '<p class="orbit-page-intro">' . esc_html__( 'How you want Perihelion to reach you and what shows up in your daily digest.', 'orbit' ) . '</p>';

		echo self::render_phone_verification( $user_id );

		echo '<h2>' . esc_html__( 'Notification preferences', 'orbit' ) . '</h2>';
		echo '<p class="orbit-help">' . esc_html__( "How do you want to be alerted about each kind of activity from people you've subscribed to?", 'orbit' ) . '</p>';
		echo '<form method="post" class="orbit-settings-form" data-orbit-api="preferences">';

		foreach ( array( 1, 2, 3 ) as $tier ) {
			$key     = "tier{$tier}_method";
			$current = $prefs->$key;

			echo '<div class="orbit-setting-row">';
			echo '<label>' . esc_html( $tier_labels[ $tier ] ) . '</label>';
			echo '<select name="' . esc_attr( $key ) . '">';

			foreach ( $method_labels as $method => $label ) {
				$selected = selected( $current, $method, false );
				echo '<option value="' . esc_attr( $method ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
			}

			echo '</select>';
			echo '</div>';
		}

		echo '<div class="orbit-setting-row">';
		echo '<label>' . esc_html__( 'SMS daily cap', 'orbit' ) . '</label>';
		echo '<input type="number" name="sms_daily_cap" value="' . esc_attr( $prefs->sms_daily_cap ) . '" min="0" placeholder="' . esc_attr__( 'No limit', 'orbit' ) . '">';
		echo '</div>';
		echo '<p class="orbit-help orbit-help--inset">' . esc_html__( 'If you set a daily cap, anything over the limit gets routed to your daily digest instead of an SMS.', 'orbit' ) . '</p>';

		echo '<div class="orbit-setting-row">';
		echo '<label>' . esc_html__( 'Digest time', 'orbit' ) . '</label>';
		echo '<input type="time" name="digest_time" value="' . esc_attr( substr( $prefs->digest_time, 0, 5 ) ) . '">';
		echo '</div>';
		echo '<p class="orbit-help orbit-help--inset">' . esc_html( $digest_tz_label ) . '</p>';

		echo '<div class="orbit-form-actions">';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Save preferences', 'orbit' ) . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Render the phone verification block for the settings page.
	 *
	 * Two page-load states:
	 *   - verified:     phone is verified — show number + "Change" link
	 *   - not-verified: show phone entry form (code form is hidden, revealed by JS)
	 *
	 * The transient "code-pending" state (after submitting the phone form) is
	 * entered client-side by JS within a single page session. Once the backend
	 * stops overwriting `orbit_phone` until verification, the absence of a
	 * verified phone is sufficient to drive the initial UI here.
	 *
	 * TODO: Migrate to the new `GET /verify-phone` primitive (see #030) once
	 * that endpoint lands so we can surface a server-known pending state on
	 * page load (e.g. user closes the tab between step 1 and step 2 and
	 * returns within the code TTL).
	 *
	 * @param int $user_id User ID.
	 * @return string HTML markup.
	 */
	private static function render_phone_verification( $user_id ) {
		$phone             = (string) get_user_meta( $user_id, 'orbit_phone', true );
		$phone_pending     = (string) get_user_meta( $user_id, 'orbit_phone_pending', true );
		$verified          = (bool) get_user_meta( $user_id, 'orbit_phone_verified', true );
		$has_verified      = $verified && '' !== $phone;
		$twilio_configured = defined( 'ORBIT_TWILIO_ACCOUNT_SID' ) && defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) && defined( 'ORBIT_TWILIO_FROM_NUMBER' );
		$sms_live          = Orbit_Features::sms_enabled();

		ob_start();

		echo '<div class="orbit-phone-verification">';
		echo '<h2>' . esc_html__( 'Phone number', 'orbit' ) . ' <span class="orbit-section-tag">' . esc_html__( 'optional', 'orbit' ) . '</span></h2>';

		// Status banner — visible at the top of the block so the user knows
		// what verifying their phone right now will (or won't) do.
		if ( ! $sms_live ) {
			echo '<div class="orbit-notice orbit-notice-info">';
			echo esc_html__( "Verifying your phone now lets you receive SMS notifications as soon as the program launches. Until then, we'll send everything by email.", 'orbit' );
			echo '</div>';
		} else {
			echo '<p class="orbit-help">' . esc_html__( 'Only needed if you want SMS notifications for any of the tiers below. We use it only to send activity alerts you opt into.', 'orbit' ) . '</p>';
		}

		if ( ! $twilio_configured ) {
			// During the dormant phase Twilio creds may not be configured
			// yet. The plan: don't hide the surface — explain the state.
			echo '<div class="orbit-notice orbit-notice-warning">';
			echo esc_html__( "Phone verification will be available once SMS is live. You'll be notified by email when that happens.", 'orbit' );
			echo '</div>';
			echo '</div>';
			return ob_get_clean();
		}

		// State: verified.
		if ( $has_verified ) {
			echo '<div class="orbit-phone-verified">';
			echo '<p class="orbit-phone-current">';
			echo '<span class="orbit-verified-badge">&check; ' . esc_html__( 'Verified', 'orbit' ) . '</span> ';
			echo '<strong>' . esc_html( $phone ) . '</strong>';
			echo '</p>';
			echo '<button type="button" class="orbit-btn orbit-btn-sm orbit-btn-link" data-orbit-phone-change>';
			echo esc_html__( 'Change phone number', 'orbit' );
			echo '</button>';
			echo '</div>';
		}

		// Phone entry form — hidden when already verified (revealed by Change button).
		// Pre-populate with orbit_phone_pending so a user who provided a
		// phone at subscribe/signup-time doesn't have to re-type it.
		$initial_phone_value = '' !== $phone_pending ? $phone_pending : '';

		echo '<form method="post" class="orbit-phone-form" data-orbit-api="verify-phone" data-orbit-step="phone"' . ( $has_verified ? ' hidden' : '' ) . '>';
		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-phone-input">' . esc_html__( 'Phone number', 'orbit' ) . '</label>';
		echo '<input type="tel" id="orbit-phone-input" name="phone" placeholder="+15551234567" value="' . esc_attr( $initial_phone_value ) . '" required>';
		echo '<p class="orbit-help">' . esc_html__( 'Use E.164 format with country code (e.g., +15551234567).', 'orbit' ) . '</p>';
		if ( '' !== $phone_pending && ! $has_verified ) {
			// Copy lives in Orbit_Messaging_Copy so the "when SMS goes live"
			// promise flips to a launch-appropriate sentence the moment
			// Orbit_Features::sms_enabled() returns true.
			echo '<p class="orbit-help">' . esc_html( Orbit_Messaging_Copy::settings_phone_help_note() ) . '</p>';
		}
		echo '</div>';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Send verification code', 'orbit' ) . '</button>';
		echo '</form>';

		// Code entry form — always hidden on page load; JS reveals it after the phone form succeeds.
		echo '<form method="post" class="orbit-code-form" data-orbit-api="verify-phone" data-orbit-step="code" hidden>';
		echo '<p class="orbit-code-sent-msg" aria-live="polite">';
		printf(
			/* translators: %s: phone number the code was sent to */
			wp_kses(
				__( 'A 6-digit code was sent to %s. Enter it below to verify.', 'orbit' ),
				array( 'strong' => array( 'class' => array() ) )
			),
			'<strong class="orbit-code-target"></strong>'
		);
		echo '</p>';
		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-code-input">' . esc_html__( 'Verification code', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-code-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>';
		echo '</div>';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Verify', 'orbit' ) . '</button> ';
		echo '<button type="button" class="orbit-btn orbit-btn-link orbit-btn-sm" data-orbit-phone-change>';
		echo esc_html__( 'Use a different number', 'orbit' );
		echo '</button> ';
		echo '<button type="button" class="orbit-btn orbit-btn-link orbit-btn-sm" data-orbit-phone-resend>';
		echo esc_html__( "Didn't get the code? Resend", 'orbit' );
		echo '</button>';
		echo '</form>';

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * My Subscriptions page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function my_subscriptions( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to view your subscriptions.', 'orbit' ) );
		}

		$user_id = get_current_user_id();

		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'approved',
				'per_page' => 100,
			)
		);

		$pending_subs = Orbit_Subscription::list(
			array(
				'user_id'  => $user_id,
				'status'   => 'pending',
				'per_page' => 100,
			)
		);

		$all_subs = array_merge( $subscriptions, $pending_subs );

		ob_start();

		echo '<div class="orbit-my-subscriptions">';
		echo '<h1>' . esc_html__( 'My Subscriptions', 'orbit' ) . '</h1>';

		echo '<p class="orbit-page-intro">' . esc_html__( "People you've subscribed to. They have to approve you before their activities show up on your dashboard.", 'orbit' ) . '</p>';

		if ( empty( $all_subs ) ) {
			echo '<p>' . esc_html__( "You aren't subscribed to anyone yet. To subscribe to someone, ask them for their share link.", 'orbit' ) . '</p>';
		} else {
			$approved_count = count( $subscriptions );
			$pending_count  = count( $pending_subs );

			echo '<p class="orbit-table-summary">';
			if ( $approved_count && $pending_count ) {
				echo esc_html( sprintf(
					/* translators: 1: approved count, 2: pending count */
					_x( '%1$d approved · %2$d pending', 'subscription counts', 'orbit' ),
					$approved_count,
					$pending_count
				) );
			} elseif ( $approved_count ) {
				/* translators: %d: count of approved subscriptions */
				echo esc_html( sprintf( _n( '%d approved', '%d approved', $approved_count, 'orbit' ), $approved_count ) );
			} else {
				/* translators: %d: count of pending subscriptions */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $pending_count, 'orbit' ), $pending_count ) );
			}
			echo '</p>';

			// Batch-load profiles.
			$profile_ids  = array_unique( array_map( function ( $s ) {
				return (int) $s->profile_id;
			}, $all_subs ) );
			$profiles_map = Orbit_Profile::get_by_ids( $profile_ids );

			$status_labels = Orbit_Subscription::get_status_labels();

			echo '<ul class="orbit-card-list">';

			foreach ( $all_subs as $sub ) {
				$profile      = isset( $profiles_map[ (int) $sub->profile_id ] ) ? $profiles_map[ (int) $sub->profile_id ] : null;
				$name         = $profile ? $profile->display_name : __( 'Unknown', 'orbit' );
				$url          = $profile ? home_url( '/@' . $profile->slug ) : '#';
				$status_label = $status_labels[ $sub->status ] ?? __( 'Unknown', 'orbit' );
				$since        = self::format_datetime( $sub->created_at, 'M j, Y' );

				echo '<li class="orbit-card">';

				echo '<div class="orbit-card__header">';
				echo '<h3 class="orbit-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></h3>';
				echo '<span class="orbit-status-badge orbit-status-' . esc_attr( $sub->status ) . '">' . esc_html( $status_label ) . '</span>';
				echo '</div>';

				echo '<p class="orbit-card__meta">' . esc_html( sprintf(
					/* translators: %s: date, e.g. "Apr 17, 2026" */
					__( 'Subscribed %s', 'orbit' ),
					$since
				) ) . '</p>';

				if ( 'approved' === $sub->status ) {
					echo '<div class="orbit-card__actions">';
					echo '<button type="button" class="orbit-btn-link orbit-btn-link--danger" data-orbit-unsubscribe="' . esc_attr( $sub->id ) . '">';
					echo esc_html__( 'Unsubscribe', 'orbit' );
					echo '</button>';
					echo '</div>';
				}

				echo '</li>';
			}

			echo '</ul>';
		}

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

		ob_start();

		echo '<div class="orbit-manage">';
		echo '<h1>' . esc_html__( 'Manage Activities', 'orbit' ) . '</h1>';

		echo '<p class="orbit-page-intro">' . esc_html__( "Everything you've posted, in one place. Edit details, cancel a plan, or post a new activity.", 'orbit' ) . '</p>';

		echo '<div class="orbit-form-actions">';
		echo '<a href="' . esc_url( home_url( '/new-activity/' ) ) . '" class="orbit-btn">';
		echo esc_html__( 'New Activity', 'orbit' );
		echo '</a>';
		echo '</div>';

		if ( ! empty( $activities ) ) {
			// Batch-load response counts.
			$activity_ids    = array_map( function ( $a ) { return (int) $a->id; }, $activities );
			$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );
			$tier_labels     = Orbit_Activity::get_tier_labels();
			$status_labels   = Orbit_Activity::get_status_labels();

			$activity_counts = array( 'active' => 0, 'cancelled' => 0, 'past' => 0 );
			foreach ( $activities as $a ) {
				if ( isset( $activity_counts[ $a->status ] ) ) {
					$activity_counts[ $a->status ]++;
				}
			}

			$summary_parts = array();
			if ( $activity_counts['active'] ) {
				/* translators: %d: count of active activities */
				$summary_parts[] = sprintf( _n( '%d active', '%d active', $activity_counts['active'], 'orbit' ), $activity_counts['active'] );
			}
			if ( $activity_counts['cancelled'] ) {
				/* translators: %d: count of cancelled activities */
				$summary_parts[] = sprintf( _n( '%d cancelled', '%d cancelled', $activity_counts['cancelled'], 'orbit' ), $activity_counts['cancelled'] );
			}
			if ( $activity_counts['past'] ) {
				/* translators: %d: count of past activities */
				$summary_parts[] = sprintf( _n( '%d past', '%d past', $activity_counts['past'], 'orbit' ), $activity_counts['past'] );
			}

			if ( $summary_parts ) {
				echo '<p class="orbit-table-summary">' . esc_html( implode( ' · ', $summary_parts ) ) . '</p>';
			}

			echo '<ul class="orbit-card-list">';

			foreach ( $activities as $activity ) {
				$response_count = isset( $response_counts[ $activity->id ]['total'] ) ? $response_counts[ $activity->id ]['total'] : 0;
				$tier_label     = isset( $tier_labels[ (int) $activity->tier ] ) ? $tier_labels[ (int) $activity->tier ] : '';
				$is_active      = 'active' === $activity->status;
				$status_label   = $is_active ? '' : ( $status_labels[ $activity->status ] ?? '' );
				$card_class     = 'orbit-card';
				if ( ! $is_active ) {
					$card_class .= ' orbit-card--' . $activity->status;
				}

				echo '<li class="' . esc_attr( $card_class ) . '">';

				echo '<div class="orbit-card__header">';
				echo '<h3 class="orbit-card__title"><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">' . esc_html( $activity->title ) . '</a></h3>';
				if ( '' !== $status_label ) {
					echo '<span class="orbit-status-badge orbit-status-' . esc_attr( $activity->status ) . '" aria-label="' . esc_attr( sprintf(
						/* translators: %s: status label e.g. Cancelled, Past */
						__( 'Status: %s', 'orbit' ),
						$status_label
					) ) . '">' . esc_html( $status_label ) . '</span>';
				}
				echo '</div>';

				echo '<p class="orbit-card__meta">';
				echo esc_html( $tier_label );
				echo ' <span aria-hidden="true">·</span> ';
				echo $activity->date_time ? esc_html( self::format_datetime( $activity->date_time, 'M j, Y g:i A' ) ) : esc_html__( 'No date set', 'orbit' );
				echo ' <span aria-hidden="true">·</span> ';
				echo esc_html( sprintf(
					/* translators: %d: response count */
					_n( '%d response', '%d responses', $response_count, 'orbit' ),
					$response_count
				) );
				echo '</p>';

				echo '<div class="orbit-card__actions">';
				echo '<a class="orbit-btn-link" href="' . esc_url( home_url( '/edit-activity/?id=' . $activity->id ) ) . '">' . esc_html__( 'Edit', 'orbit' ) . '</a>';
				if ( $is_active ) {
					echo '<button type="button" class="orbit-btn-link orbit-btn-link--danger" data-orbit-cancel="' . esc_attr( $activity->id ) . '">' . esc_html__( 'Cancel activity', 'orbit' ) . '</button>';
				}
				echo '</div>';

				echo '</li>';
			}

			echo '</ul>';
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

		$tier_labels       = Orbit_Activity::get_tier_labels();
		$tier_descriptions = Orbit_Activity::get_tier_descriptions();
		$default_tier      = 3;

		echo '<div class="orbit-new-activity">';
		echo '<h1>' . esc_html__( 'New activity', 'orbit' ) . '</h1>';
		echo '<p class="orbit-page-intro">' . esc_html__( "Tell your subscribers what you're up to. Pick a commitment level so they know how to read it.", 'orbit' ) . '</p>';
		echo self::render_required_note();
		echo '<form method="post" class="orbit-form" data-orbit-api="activities" data-profile-id="' . esc_attr( $profile->id ) . '">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-title">' . esc_html__( 'Title', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
		echo '<input type="text" id="orbit-title" name="title" maxlength="300" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-tier">' . esc_html__( 'Commitment level', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
		echo '<select id="orbit-tier" name="tier" required data-orbit-tier-select>';
		foreach ( $tier_labels as $tier_value => $tier_label ) {
			$selected = $default_tier === $tier_value ? ' selected' : '';
			echo '<option value="' . esc_attr( $tier_value ) . '" data-tier-description="' . esc_attr( $tier_descriptions[ $tier_value ] ?? '' ) . '"' . $selected . '>' . esc_html( $tier_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="orbit-help" data-orbit-tier-description aria-live="polite" aria-atomic="true">' . esc_html( $tier_descriptions[ $default_tier ] ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-description">' . esc_html__( 'Description', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-description" name="description" rows="3"></textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-audience">' . esc_html__( "Who's this for?", 'orbit' ) . '</label>';
		echo '<textarea id="orbit-audience" name="audience" rows="2" placeholder="' . esc_attr__( 'e.g. Beginners welcome, or anyone who likes long walks', 'orbit' ) . '"></textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'Help people decide if this is right for them.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-url">' . esc_html__( 'Link', 'orbit' ) . '</label>';
		echo '<input type="url" id="orbit-url" name="url" placeholder="' . esc_attr__( 'https://example.com/event-page', 'orbit' ) . '">';
		echo '<p class="orbit-help">' . esc_html__( 'Link to an external event page with more details.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-name">' . esc_html__( 'Location name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-location-name" name="location_name" maxlength="300">';
		echo '<p class="orbit-help">' . esc_html__( 'A short name everyone will recognize, like "Dolores Park" or "the usual coffee place".', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-address">' . esc_html__( 'Location address', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-location-address" name="location_address" rows="2"></textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'Hidden from non-subscribers — only your approved subscribers see this.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-date-time">' . esc_html__( 'Date & time', 'orbit' ) . '</label>';
		echo '<input type="datetime-local" id="orbit-date-time" name="date_time">';
		echo '<label class="orbit-checkbox-label"><input type="checkbox" name="date_flexible" value="1"> ' . esc_html__( 'Date is approximate', 'orbit' ) . '</label>';
		echo '<p class="orbit-help">' . esc_html__( "Tick if the date is a rough plan rather than a fixed time. Subscribers will see it as flexible so they don't expect it to be locked in.", 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-show-attendees">' . esc_html__( 'Show attendees', 'orbit' ) . '</label>';
		echo '<select id="orbit-show-attendees" name="show_attendees">';
		echo '<option value="count" selected>' . esc_html__( 'Show count', 'orbit' ) . '</option>';
		echo '<option value="names">' . esc_html__( 'Show names', 'orbit' ) . '</option>';
		echo '<option value="none">' . esc_html__( 'Hide', 'orbit' ) . '</option>';
		echo '</select>';
		echo '<p class="orbit-help">' . esc_html__( 'Controls how much other subscribers see about who has responded.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-actions">';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Create activity', 'orbit' ) . '</button>';
		echo ' <a class="orbit-btn-link" href="' . esc_url( home_url( '/manage/' ) ) . '">' . esc_html__( '← Back to manage', 'orbit' ) . '</a>';
		echo '</div>';
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

		$activity_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

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

		$tier_labels       = Orbit_Activity::get_tier_labels();
		$tier_label        = $tier_labels[ $activity->tier ] ?? '';
		$show_attendees_labels = array(
			'count' => __( 'Show count', 'orbit' ),
			'names' => __( 'Show names', 'orbit' ),
			'none'  => __( 'Hide', 'orbit' ),
		);

		echo '<div class="orbit-edit-activity">';
		echo '<h1>' . esc_html__( 'Edit activity', 'orbit' ) . '</h1>';
		echo '<p class="orbit-page-intro">' . esc_html__( "Update an existing post. Subscribers won't be re-notified by edits.", 'orbit' ) . '</p>';
		echo self::render_required_note();
		echo '<form method="post" class="orbit-form" data-orbit-api="activities/' . esc_attr( $activity_id ) . '" data-method="PATCH">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-title">' . esc_html__( 'Title', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
		echo '<input type="text" id="orbit-title" name="title" value="' . esc_attr( $activity->title ) . '" maxlength="300" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label>' . esc_html__( 'Commitment level', 'orbit' ) . '</label>';
		echo '<p class="orbit-form-static-value">' . esc_html( $tier_label ) . '</p>';
		echo '<p class="orbit-help">' . esc_html__( "Commitment level can't be changed after posting — it's tied to how subscribers were notified. Create a new activity if you need a different level.", 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-description">' . esc_html__( 'Description', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-description" name="description" rows="3">' . esc_textarea( $activity->description ) . '</textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-audience">' . esc_html__( "Who's this for?", 'orbit' ) . '</label>';
		echo '<textarea id="orbit-audience" name="audience" rows="2" placeholder="' . esc_attr__( 'e.g. Beginners welcome, or anyone who likes long walks', 'orbit' ) . '">' . esc_textarea( $activity->audience ) . '</textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'Help people decide if this is right for them.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-url">' . esc_html__( 'Link', 'orbit' ) . '</label>';
		echo '<input type="url" id="orbit-url" name="url" value="' . esc_attr( $activity->url ) . '" placeholder="' . esc_attr__( 'https://example.com/event-page', 'orbit' ) . '">';
		echo '<p class="orbit-help">' . esc_html__( 'Link to an external event page with more details.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-name">' . esc_html__( 'Location name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-location-name" name="location_name" value="' . esc_attr( $activity->location_name ) . '" maxlength="300">';
		echo '<p class="orbit-help">' . esc_html__( 'A short name everyone will recognize, like "Dolores Park" or "the usual coffee place".', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-location-address">' . esc_html__( 'Location address', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-location-address" name="location_address" rows="2">' . esc_textarea( $activity->location_address ) . '</textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'Hidden from non-subscribers — only your approved subscribers see this.', 'orbit' ) . '</p>';
		echo '</div>';

		$date_value = $activity->date_time ? date( 'Y-m-d\TH:i', strtotime( $activity->date_time ) ) : '';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-date-time">' . esc_html__( 'Date & time', 'orbit' ) . '</label>';
		echo '<input type="datetime-local" id="orbit-date-time" name="date_time" value="' . esc_attr( $date_value ) . '">';
		echo '<label class="orbit-checkbox-label"><input type="checkbox" name="date_flexible" value="1" ' . checked( $activity->date_flexible, 1, false ) . '> ' . esc_html__( 'Date is approximate', 'orbit' ) . '</label>';
		echo '<p class="orbit-help">' . esc_html__( "Tick if the date is a rough plan rather than a fixed time. Subscribers will see it as flexible so they don't expect it to be locked in.", 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-show-attendees">' . esc_html__( 'Show attendees', 'orbit' ) . '</label>';
		echo '<select id="orbit-show-attendees" name="show_attendees">';

		foreach ( $show_attendees_labels as $option => $label ) {
			$selected = selected( $activity->show_attendees, $option, false );
			echo '<option value="' . esc_attr( $option ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '<p class="orbit-help">' . esc_html__( 'Controls how much other subscribers see about who has responded.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-actions">';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Update activity', 'orbit' ) . '</button>';
		echo ' <a class="orbit-btn-link" href="' . esc_url( home_url( '/manage/' ) ) . '">' . esc_html__( '← Back to manage', 'orbit' ) . '</a>';
		echo '</div>';

		echo '</form>';

		if ( 'active' === $activity->status ) {
			echo '<div class="orbit-danger-zone">';
			echo '<h2>' . esc_html__( 'Danger zone', 'orbit' ) . '</h2>';
			echo '<p>' . esc_html__( 'Cancelling this activity tells subscribers it is off. They will see it marked Cancelled on their dashboards. This cannot be undone.', 'orbit' ) . '</p>';
			echo '<button type="button" class="orbit-btn orbit-btn-danger orbit-btn-outline" data-orbit-cancel="' . esc_attr( $activity_id ) . '">';
			echo esc_html__( 'Cancel activity', 'orbit' );
			echo '</button>';
			echo '</div>';
		}

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
		echo '<h1>' . esc_html__( 'Subscribers', 'orbit' ) . '</h1>';

		echo '<p class="orbit-page-intro">' . esc_html__( "People who've subscribed to your activities. Approve or deny pending requests; remove approved subscribers any time.", 'orbit' ) . '</p>';

		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( "No subscribers yet. Send your share link to people you'd like to invite — they'll be able to subscribe with one click and you'll see them here for approval.", 'orbit' ) . '</p>';

			$share_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<div class="orbit-form-group">';
			echo '<label for="orbit-subscribers-share-link">' . esc_html__( 'Your share link', 'orbit' ) . '</label>';
			echo '<div class="orbit-share-link-row">';
			echo '<input type="text" id="orbit-subscribers-share-link" class="orbit-share-link-input" value="' . esc_attr( $share_url ) . '" readonly>';
			echo '<button type="button" class="orbit-btn orbit-btn-sm" data-orbit-copy-target="#orbit-subscribers-share-link" data-orbit-copy-label="' . esc_attr__( 'Copy', 'orbit' ) . '" data-orbit-copy-confirm="' . esc_attr__( 'Copied!', 'orbit' ) . '">' . esc_html__( 'Copy', 'orbit' ) . '</button>';
			echo '</div>';
			echo '</div>';
		} else {
			// Pre-populate WordPress user cache to avoid N+1 user lookups.
			$user_ids = array_map( function ( $s ) { return (int) $s->user_id; }, $subscriptions );
			cache_users( $user_ids );

			$counts = array( 'approved' => 0, 'pending' => 0 );
			foreach ( $subscriptions as $sub ) {
				if ( isset( $counts[ $sub->status ] ) ) {
					$counts[ $sub->status ]++;
				}
			}

			echo '<p class="orbit-table-summary">';
			if ( $counts['approved'] && $counts['pending'] ) {
				echo esc_html( sprintf(
					/* translators: 1: approved count, 2: pending count */
					_x( '%1$d approved · %2$d pending', 'subscriber counts', 'orbit' ),
					$counts['approved'],
					$counts['pending']
				) );
			} elseif ( $counts['approved'] ) {
				/* translators: %d: count of approved subscribers */
				echo esc_html( sprintf( _n( '%d approved', '%d approved', $counts['approved'], 'orbit' ), $counts['approved'] ) );
			} elseif ( $counts['pending'] ) {
				/* translators: %d: count of pending subscribers */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $counts['pending'], 'orbit' ), $counts['pending'] ) );
			}
			echo '</p>';

			$status_labels = Orbit_Subscription::get_status_labels();

			echo '<ul class="orbit-card-list">';

			foreach ( $subscriptions as $sub ) {
				$user         = get_userdata( $sub->user_id );
				$name         = $user ? $user->display_name : __( 'Unknown', 'orbit' );
				$status_label = $status_labels[ $sub->status ] ?? __( 'Unknown', 'orbit' );
				$since        = self::format_datetime( $sub->created_at, 'M j, Y' );

				echo '<li class="orbit-card">';

				echo '<div class="orbit-card__header">';
				echo '<h3 class="orbit-card__title">' . esc_html( $name ) . '</h3>';
				echo '<span class="orbit-status-badge orbit-status-' . esc_attr( $sub->status ) . '">' . esc_html( $status_label ) . '</span>';
				echo '</div>';

				if ( $sub->connection_note ) {
					echo '<p class="orbit-card__note">' . esc_html( $sub->connection_note ) . '</p>';
				}

				echo '<p class="orbit-card__meta">' . esc_html( sprintf(
					/* translators: %s: date, e.g. "Apr 17, 2026" */
					__( 'Subscribed %s', 'orbit' ),
					$since
				) ) . '</p>';

				echo '<div class="orbit-card__actions">';
				if ( 'pending' === $sub->status ) {
					echo '<button type="button" class="orbit-btn-link" data-orbit-subscriber-action="approve" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Approve', 'orbit' ) . '</button>';
					echo '<button type="button" class="orbit-btn-link orbit-btn-link--danger" data-orbit-subscriber-action="deny" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Deny', 'orbit' ) . '</button>';
				} elseif ( 'approved' === $sub->status ) {
					echo '<button type="button" class="orbit-btn-link orbit-btn-link--danger" data-orbit-subscriber-action="remove" data-id="' . esc_attr( $sub->id ) . '">' . esc_html__( 'Remove', 'orbit' ) . '</button>';
				}
				echo '</div>';

				echo '</li>';
			}

			echo '</ul>';
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
		if ( ! is_user_logged_in() ) {
			return self::login_prompt( __( 'Please log in to manage your profile.', 'orbit' ) );
		}

		$profile = Orbit_Profile::get_by_user_id( get_current_user_id() );

		if ( ! $profile ) {
			return self::create_profile_form();
		}

		ob_start();

		echo '<div class="orbit-edit-profile">';
		echo '<h1>' . esc_html__( 'Edit profile', 'orbit' ) . '</h1>';

		echo '<p class="orbit-page-intro">';
		echo esc_html__( 'How you appear to subscribers and what your share link points to.', 'orbit' );
		echo ' <a href="' . esc_url( home_url( '/@' . $profile->slug ) ) . '">';
		echo esc_html__( 'View your profile →', 'orbit' );
		echo '</a></p>';

		echo self::render_required_note();

		echo '<form method="post" class="orbit-form" data-orbit-api="profiles/' . esc_attr( $profile->id ) . '" data-method="PATCH">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-display-name">' . esc_html__( 'Display name', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
		echo '<input type="text" id="orbit-display-name" name="display_name" value="' . esc_attr( $profile->display_name ) . '" required>';
		echo '<p class="orbit-help">' . esc_html__( "How you'll appear on activity cards and your public profile.", 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-slug">' . esc_html__( 'URL slug', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
		echo '<input type="text" id="orbit-slug" name="slug" value="' . esc_attr( $profile->slug ) . '" required>';
		echo '<p class="orbit-help">' . esc_html__( 'Your profile URL is', 'orbit' ) . ' <code>' . esc_html( home_url( '/@' . $profile->slug ) ) . '</code>.</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-bio">' . esc_html__( 'Bio', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-bio" name="bio" rows="3">' . esc_textarea( $profile->bio ) . '</textarea>';
		echo '<p class="orbit-help">' . esc_html__( 'A sentence or two so visitors recognize you. Shown on your public profile above your activities.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label class="orbit-checkbox-label"><input type="checkbox" name="require_approval" value="1" ' . checked( $profile->require_approval, 1, false ) . '> ';
		echo esc_html__( 'Require approval for new subscribers', 'orbit' ) . '</label>';
		echo '<p class="orbit-help">' . esc_html__( 'When ticked, new subscribers wait until you approve them before they can see your activities. Untick to let anyone with your share link subscribe immediately.', 'orbit' ) . '</p>';
		echo '</div>';

		$share_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-share-link">' . esc_html__( 'Share link', 'orbit' ) . '</label>';
		echo '<div class="orbit-share-link-row">';
		echo '<input type="text" id="orbit-share-link" class="orbit-share-link-input" value="' . esc_attr( $share_url ) . '" readonly>';
		echo '<button type="button" class="orbit-btn orbit-btn-sm" data-orbit-copy-target="#orbit-share-link" data-orbit-copy-label="' . esc_attr__( 'Copy', 'orbit' ) . '" data-orbit-copy-confirm="' . esc_attr__( 'Copied!', 'orbit' ) . '">' . esc_html__( 'Copy', 'orbit' ) . '</button>';
		echo '</div>';
		echo '<p class="orbit-help">' . esc_html__( 'Send this link to someone you want as a subscriber. The token in the link is theirs to redeem once.', 'orbit' ) . '</p>';
		echo '</div>';

		echo '<div class="orbit-form-actions">';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Save profile', 'orbit' ) . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Profile creation form for users who don't have a profile yet.
	 *
	 * @return string HTML output.
	 */
	private static function create_profile_form() {
		$user = wp_get_current_user();

		ob_start();

		echo '<div class="orbit-edit-profile">';
		echo '<h1>' . esc_html__( 'Create Your Profile', 'orbit' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set up a profile to start sharing activities with your people.', 'orbit' ) . '</p>';
		echo '<form method="post" class="orbit-form" data-orbit-api="profiles/me">';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-display-name">' . esc_html__( 'Display Name', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-display-name" name="display_name" value="' . esc_attr( $user->display_name ) . '" required>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-slug">' . esc_html__( 'URL Slug', 'orbit' ) . '</label>';
		echo '<input type="text" id="orbit-slug" name="slug" value="' . esc_attr( sanitize_title( $user->display_name ) ) . '" required>';
		echo '<p class="orbit-help">' . esc_html( home_url( '/@' ) ) . '<span id="orbit-slug-preview">' . esc_html( sanitize_title( $user->display_name ) ) . '</span></p>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-bio">' . esc_html__( 'Bio', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-bio" name="bio" rows="3" placeholder="' . esc_attr__( 'A short description of what you like to do', 'orbit' ) . '"></textarea>';
		echo '</div>';

		echo '<div class="orbit-form-group">';
		echo '<label><input type="checkbox" name="require_approval" value="1" checked> ';
		echo esc_html__( 'Require approval for new subscribers', 'orbit' ) . '</label>';
		echo '</div>';

		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Create Profile', 'orbit' ) . '</button>';
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

		$viewer_id    = is_user_logged_in() ? get_current_user_id() : null;
		$subscription = $viewer_id ? Orbit_Subscription::get_by_user_and_profile( $viewer_id, $profile->id ) : null;
		$is_approved  = $subscription && 'approved' === $subscription->status;
		$is_pending   = $subscription && 'pending' === $subscription->status;
		$is_owner     = $viewer_id && (int) $profile->user_id === $viewer_id;

		echo '<div class="orbit-profile">';

		echo '<h1 class="orbit-profile-name">' . esc_html( $profile->display_name ) . '</h1>';

		if ( $profile->bio ) {
			echo '<p class="orbit-bio">' . esc_html( $profile->bio ) . '</p>';
		}

		// Subscribe CTA (not shown to owner or existing subscribers).
		if ( $is_owner ) {
			echo '<div class="orbit-notice orbit-notice-owner">';
			echo '<span class="orbit-notice-owner__tag">' . esc_html__( 'This is your profile.', 'orbit' ) . '</span> ';
			echo '<a class="orbit-btn orbit-btn-sm" href="' . esc_url( home_url( '/edit-profile/' ) ) . '">' . esc_html__( 'Edit profile', 'orbit' ) . '</a> ';
			echo '<a class="orbit-btn orbit-btn-sm" href="' . esc_url( home_url( '/manage/' ) ) . '">' . esc_html__( 'Manage activities', 'orbit' ) . '</a>';
			echo '</div>';
		} elseif ( ! $is_approved && ! $is_pending ) {
			$subscribe_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<p><a href="' . esc_url( $subscribe_url ) . '" class="orbit-btn">';
			echo esc_html__( 'Subscribe', 'orbit' );
			echo '</a></p>';
		} elseif ( $is_pending ) {
			echo '<p class="orbit-notice">' . esc_html__( 'Your subscription is awaiting approval.', 'orbit' ) . '</p>';
		} elseif ( $is_approved ) {
			echo '<p class="orbit-subscription-status">';
			echo '<span class="orbit-subscribed-badge">' . esc_html__( 'Subscribed', 'orbit' ) . '</span> ';
			echo '<button class="orbit-btn orbit-btn-sm orbit-btn-danger" data-orbit-unsubscribe="' . esc_attr( $subscription->id ) . '">';
			echo esc_html__( 'Unsubscribe', 'orbit' );
			echo '</button></p>';
		}

		// Show active activities.
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

			$tier_labels = Orbit_Activity::get_tier_labels();

			foreach ( $activities as $activity ) {
				$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

				echo '<div class="orbit-activity-card">';
				echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';
				echo '<h3><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">';
				echo esc_html( $activity->title );
				echo '</a></h3>';

				if ( $activity->date_time ) {
					echo '<p class="orbit-activity-date">' . esc_html( self::format_datetime( $activity->date_time ) ) . '</p>';
				} else {
					echo '<p class="orbit-activity-date orbit-activity-date--undated">' . esc_html__( 'No date set', 'orbit' ) . '</p>';
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
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

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

		echo '<h1>' . esc_html( sprintf(
			/* translators: %s: profile display name */
			__( 'Subscribe to %s', 'orbit' ),
			$profile->display_name
		) ) . '</h1>';

		echo '<p class="orbit-page-intro">' . esc_html( sprintf(
			/* translators: %s: profile display name */
			__( "%s will see your subscription request and approve you. You'll get an email when they do — then their activities will start showing up on your dashboard.", 'orbit' ),
			$profile->display_name
		) ) . '</p>';

		echo self::render_required_note();

		echo '<form method="post" class="orbit-form" data-orbit-api="subscribe">';
		echo '<input type="hidden" name="share_token" value="' . esc_attr( $profile->share_token ) . '">';

		if ( ! is_user_logged_in() ) {
			echo '<div class="orbit-form-group">';
			echo '<label for="orbit-name">' . esc_html__( 'Your name', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
			echo '<input type="text" id="orbit-name" name="display_name" required>';
			echo '</div>';

			echo '<div class="orbit-form-group">';
			echo '<label for="orbit-email">' . esc_html__( 'Email', 'orbit' ) . ' <span class="orbit-required-mark" aria-hidden="true">*</span></label>';
			echo '<input type="email" id="orbit-email" name="email" required>';
			echo '<p class="orbit-help">' . esc_html__( 'Used only to send you activity notifications.', 'orbit' ) . '</p>';
			echo '</div>';
		} else {
			$user = wp_get_current_user();
			echo '<input type="hidden" name="display_name" value="' . esc_attr( $user->display_name ) . '">';
			echo '<input type="hidden" name="email" value="' . esc_attr( $user->user_email ) . '">';
			echo '<p>' . esc_html( sprintf(
				/* translators: %s: subscriber display name */
				__( 'Subscribing as %s', 'orbit' ),
				$user->display_name
			) ) . '</p>';
		}

		echo '<div class="orbit-form-group">';
		echo '<label for="orbit-note">' . esc_html__( 'How do you know this person?', 'orbit' ) . '</label>';
		echo '<textarea id="orbit-note" name="connection_note" rows="2" maxlength="500"></textarea>';
		echo '<p class="orbit-help">' . esc_html( sprintf(
			/* translators: %s: profile display name */
			__( "Just a quick note for %s — only they will see this. Helps them recognize you and decide whether to approve.", 'orbit' ),
			$profile->display_name
		) ) . '</p>';
		echo '</div>';

		// Phone field, compliance disclosure, and per-channel consent
		// checkboxes — grouped so the disclosure reads adjacent to the
		// phone capture rather than as a footer afterthought (Twilio
		// reviewer guidance).
		if ( ! is_user_logged_in() ) {
			// Anonymous-flow subscribers may provide a phone for SMS at
			// account-creation time. Logged-in users already have an
			// account; they can verify their phone from /settings/ if
			// they want SMS.
			echo self::render_phone_field( 'orbit-subscribe' );
		}
		echo self::render_compliance_block();
		echo self::render_consent_checkboxes( 'orbit-subscribe' );

		// Honeypot + timestamp traps — same defense the signup form uses,
		// rendered just before the submit button so it sits inside the
		// <form> envelope. Orbit_Spam::check_traps() in the REST handler
		// reads orbit_url + orbit_form_init off the request payload.
		Orbit_Spam::render_traps();

		echo '<div class="orbit-form-actions">';
		echo '<button type="submit" class="orbit-btn">' . esc_html__( 'Subscribe', 'orbit' ) . '</button>';
		echo ' <a class="orbit-btn-link" href="' . esc_url( home_url( '/@' . $profile->slug ) ) . '">' . esc_html__( '← Back to profile', 'orbit' ) . '</a>';
		echo '</div>';
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
			return '<p>' . esc_html__( 'Activity not found.', 'orbit' ) . '</p>';
		}

		$profile = Orbit_Profile::get( $activity->profile_id );
		$viewer_id = is_user_logged_in() ? get_current_user_id() : null;

		// Check for action token in URL.
		$act_token    = isset( $_GET['act'] ) ? sanitize_text_field( wp_unslash( $_GET['act'] ) ) : '';
		$subscription = null;

		if ( $act_token ) {
			// Extract subscription ID from token for O(1) lookup.
			$sub_id = Orbit_Token::extract_subscription_id( $act_token );
			if ( $sub_id ) {
				$sub = Orbit_Subscription::get( $sub_id );
				if ( $sub && 'approved' === $sub->status && (int) $sub->profile_id === (int) $activity->profile_id ) {
					if ( Orbit_Token::validate_action_token( $act_token, $sub->subscription_secret, $activity->id ) ) {
						$subscription = $sub;
					}
				}
			}
		} elseif ( $viewer_id ) {
			$subscription = Orbit_Subscription::get_by_user_and_profile( $viewer_id, $activity->profile_id );

			if ( $subscription && 'approved' !== $subscription->status ) {
				$subscription = null;
			}
		}

		$tier_labels = Orbit_Activity::get_tier_labels();

		$tier_label = isset( $tier_labels[ $activity->tier ] ) ? $tier_labels[ $activity->tier ] : '';

		ob_start();

		echo '<div class="orbit-activity-detail">';

		if ( 'cancelled' === $activity->status ) {
			echo '<div class="orbit-notice orbit-notice-warning">' . esc_html__( 'This activity has been cancelled.', 'orbit' ) . '</div>';
		}

		echo '<h1 class="orbit-activity-title">' . esc_html( $activity->title ) . '</h1>';

		if ( $profile ) {
			echo '<p class="orbit-poster-link"><a href="' . esc_url( home_url( '/@' . $profile->slug ) ) . '">';
			echo esc_html( $profile->display_name ) . '</a></p>';
		}

		echo '<span class="orbit-tier-badge orbit-tier-' . esc_attr( $activity->tier ) . '">' . esc_html( $tier_label ) . '</span>';

		if ( $activity->description ) {
			echo '<div class="orbit-activity-description">' . wp_kses_post( wpautop( $activity->description ) ) . '</div>';
		}

		if ( $activity->audience ) {
			echo '<div class="orbit-activity-audience">';
			echo '<p class="orbit-activity-audience-label">' . esc_html__( "Who's this for", 'orbit' ) . '</p>';
			echo wp_kses_post( wpautop( $activity->audience ) );
			echo '</div>';
		}

		if ( $activity->date_time ) {
			echo '<p class="orbit-activity-date"><strong>' . esc_html__( 'When:', 'orbit' ) . '</strong> ';
			echo esc_html( self::format_datetime( $activity->date_time ) );
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

		// External link.
		if ( ! empty( $activity->url ) ) {
			echo '<p class="orbit-activity-url"><a href="' . esc_url( $activity->url ) . '" class="orbit-btn orbit-btn-link" target="_blank" rel="noopener noreferrer">';
			echo esc_html__( 'View event details', 'orbit' ) . ' &rarr;';
			echo '</a></p>';
		}

		// Response section.
		$responses        = Orbit_Response::list_by_activity( $activity->id );
		$privacy_resolved = Orbit_Privacy::resolve_responses( $activity, $responses, $viewer_id );

		if ( 'count' === $privacy_resolved['visibility_mode'] || 'names' === $privacy_resolved['visibility_mode'] ) {
			echo '<div class="orbit-responses-summary">';
			if ( $privacy_resolved['going_count'] ) {
				/* translators: %d: count of subscribers going */
				echo '<span class="orbit-going-count">' . esc_html( sprintf( _n( '%d going', '%d going', $privacy_resolved['going_count'], 'orbit' ), $privacy_resolved['going_count'] ) ) . '</span>';
			}
			if ( $privacy_resolved['maybe_count'] ) {
				/* translators: %d: count of subscribers responding maybe */
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

		// Subscribe CTA for non-subscribers (not shown to the poster).
		$is_own_activity = $viewer_id && $profile && (int) $profile->user_id === $viewer_id;
		if ( ! $subscription && $profile && ! $is_own_activity ) {
			$subscribe_url = home_url( '/@' . $profile->slug . '/subscribe?token=' . $profile->share_token );
			echo '<p class="orbit-cta">';
			echo esc_html( sprintf(
				/* translators: %s: profile display name */
				__( 'Subscribe to %s to get notified about activities like this.', 'orbit' ),
				$profile->display_name
			) ) . ' ';
			echo '<a href="' . esc_url( $subscribe_url ) . '">' . esc_html__( 'Subscribe', 'orbit' ) . '</a>';
			echo '</p>';
		}

		// Response buttons (only for approved subscribers, active/past activities).
		if ( $subscription && 'cancelled' !== $activity->status ) {
			$my_response = Orbit_Response::get_by_activity_and_subscription( $activity->id, $subscription->id );
			$is_past     = 'past' === $activity->status;

			if ( ! $is_past ) {
				echo '<div class="orbit-response-buttons" data-activity-id="' . esc_attr( $activity->id ) . '" data-subscription-id="' . esc_attr( $subscription->id ) . '">';

				$going_class = $my_response && 'going' === $my_response->response ? ' orbit-btn-active' : '';
				$maybe_class = $my_response && 'maybe' === $my_response->response ? ' orbit-btn-active' : '';

				echo '<button class="orbit-btn orbit-btn-going' . esc_attr( $going_class ) . '" data-response="going">';
				echo esc_html__( "I'm going", 'orbit' ) . '</button> ';

				echo '<button class="orbit-btn orbit-btn-maybe' . esc_attr( $maybe_class ) . '" data-response="maybe">';
				echo esc_html__( 'Maybe', 'orbit' ) . '</button>';

				if ( $my_response ) {
					echo ' <button class="orbit-btn orbit-btn-sm orbit-btn-retract" data-response="retract">';
					echo esc_html__( 'Cancel RSVP', 'orbit' ) . '</button>';
				}

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
	 * Render the "Fields marked with * are required" note used above
	 * forms with required fields.
	 *
	 * The asterisk span is purely visual (the input itself carries the
	 * required attribute and aria-required), so the span is marked
	 * aria-hidden to keep screen readers from announcing a stray "*".
	 *
	 * @return string HTML markup for the required-note paragraph.
	 */
	private static function render_required_note() {
		return '<p class="orbit-form-required-note">' . wp_kses(
			__( 'Fields marked with <span class="orbit-required-mark" aria-hidden="true">*</span> are required.', 'orbit' ),
			array(
				'span' => array(
					'class'       => array(),
					'aria-hidden' => array(),
				),
			)
		) . '</p>';
	}

	/**
	 * Canonical compliance-disclosure text shown adjacent to phone-capture
	 * fields on every opt-in surface.
	 *
	 * Used in TWO places that must agree byte-for-byte:
	 *
	 * 1. As the rendered HTML in the subscribe + signup forms (via
	 *    {@see self::render_compliance_block()}).
	 * 2. As the cta_snapshot string stored on every consent ledger row at
	 *    opt-in time (via {@see Orbit_REST_Subscription::handle_subscribe}
	 *    and {@see Orbit_REST_Signup::handle_signup} when they call
	 *    Orbit_Consent::record).
	 *
	 * Storing the exact phrasing the user agreed to is the TCPA evidence
	 * the consent ledger exists to preserve.
	 *
	 * When this text changes, bump ORBIT_VERSION so the orbit_policy_version
	 * post_meta on /privacy/ + /terms/ tracks the change. Every consent
	 * row captures the version it agreed to.
	 *
	 * @param string|null $privacy_label Optional label to interpolate for the
	 *                                   "Privacy Policy" placeholder. Pass plain
	 *                                   text (default) for the ledger snapshot;
	 *                                   pass anchor HTML for the rendered form.
	 * @param string|null $terms_label   Optional label to interpolate for the
	 *                                   "Terms" placeholder. Same semantics as
	 *                                   $privacy_label.
	 * @return string Plain text (default) safe to esc_html() into HTML or store
	 *                verbatim in the ledger; or HTML when callers pass anchor
	 *                strings.
	 */
	public static function compliance_disclosure_text( $privacy_label = null, $terms_label = null ) {
		if ( null === $privacy_label ) {
			$privacy_label = __( 'Privacy Policy', 'orbit' );
		}
		if ( null === $terms_label ) {
			$terms_label = __( 'Terms', 'orbit' );
		}

		$baseline = sprintf(
			/* translators: 1: Privacy Policy link or label, 2: Terms link or label. */
			__( 'Get notified when posters you follow share new activities. Email is required; phone is optional and used only for SMS notifications. Up to 10 msgs/week. Msg & data rates may apply. Reply STOP to opt out, HELP for help. See our %1$s and %2$s.', 'orbit' ),
			$privacy_label,
			$terms_label
		);

		// SMS dormancy clause comes from the central gate so the dashboard
		// banner, settings help note, this disclosure, and the ledger
		// snapshot all flip together on the SMS-launch day. The clause is
		// PREPENDED — keep this position stable across the rendered HTML
		// path and the ledger snapshot path or the cta_snapshot byte-match
		// invariant breaks. When SMS is live the helper returns an empty
		// string and the disclosure reads as the baseline alone.
		$sms_clause = Orbit_Messaging_Copy::sms_status_clause();
		if ( '' === $sms_clause ) {
			return $baseline;
		}

		return $sms_clause . ' ' . $baseline;
	}

	/**
	 * Render the compliance-disclosure block.
	 *
	 * Visually distinct from surrounding form fields so it reads as a
	 * disclosure, not body copy. Twilio reviewer guidance: the
	 * disclosures must be adjacent to the phone field, not buried in
	 * the form footer. Includes inline links to /privacy/ and /terms/.
	 *
	 * @return string Block-level HTML.
	 */
	public static function render_compliance_block() {
		$privacy_url = esc_url( home_url( '/privacy/' ) );
		$terms_url   = esc_url( home_url( '/terms/' ) );

		// Build the anchor labels locally so the sprintf template only ever
		// interpolates trusted strings. Each label escapes its own translated
		// text; the rest of the sentence is locale-controlled but contains
		// no untrusted input. This replaces an earlier str_replace approach
		// that broke i18n because translators don't necessarily render the
		// substring "Privacy Policy" / "Terms" identically in two independent
		// translation strings — see todo 111.
		$privacy_label = '<a href="' . $privacy_url . '">' . esc_html__( 'Privacy Policy', 'orbit' ) . '</a>';
		$terms_label   = '<a href="' . $terms_url . '">' . esc_html__( 'Terms', 'orbit' ) . '</a>';

		// The sentence template is interpolated with anchor HTML, so we
		// can't esc_html() the whole result. Run the rendered string through
		// wp_kses() with a tight allowlist (only the <a> tags we inject) so
		// any HTML-special chars in the brand name (interpolated inside
		// compliance_disclosure_text() and not pre-escaped, to preserve the
		// byte-match invariant with the ledger snapshot) are neutralized.
		$rendered = self::compliance_disclosure_text( $privacy_label, $terms_label );
		$rendered = wp_kses(
			$rendered,
			array(
				'a' => array(
					'href' => array(),
				),
			)
		);

		return '<div class="orbit-compliance-block" role="note">' . $rendered . '</div>';
	}

	/**
	 * Render the optional phone field. Visually grouped with the
	 * compliance block.
	 *
	 * @param string $id_prefix Form ID prefix so multiple forms on a
	 *                          single page don't collide (e.g. 'orbit-subscribe').
	 * @return string Block-level HTML.
	 */
	public static function render_phone_field( $id_prefix ) {
		$id = $id_prefix . '-phone';

		ob_start();
		?>
		<div class="orbit-form-group orbit-phone-group">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Phone (optional)', 'orbit' ); ?></label>
			<input
				type="tel"
				id="<?php echo esc_attr( $id ); ?>"
				name="phone"
				autocomplete="tel"
				inputmode="tel"
				placeholder="+1 555 555 0123"
				data-orbit-phone-input
			>
			<p class="orbit-help"><?php esc_html_e( 'Include country code (e.g. +1 for US/Canada).', 'orbit' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the consent checkboxes.
	 *
	 * Two checkboxes per Twilio's "explicit consent per channel" pattern:
	 * - Email consent is required (the form can't submit without it).
	 * - SMS consent is optional and only meaningful when the phone field
	 *   has a value. JS toggles its `disabled` attribute based on phone
	 *   input; server-side, if `consent_sms=1` arrives without a phone
	 *   the handler returns a validation error.
	 *
	 * @param string $id_prefix Form ID prefix.
	 * @return string Block-level HTML.
	 */
	public static function render_consent_checkboxes( $id_prefix ) {
		$email_id = $id_prefix . '-consent-email';
		$sms_id   = $id_prefix . '-consent-sms';

		ob_start();
		?>
		<div class="orbit-form-group orbit-consent-group">
			<label class="orbit-checkbox-label" for="<?php echo esc_attr( $email_id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $email_id ); ?>" name="consent_email" value="1" required>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: brand name */
						__( 'I agree to receive activity notifications from %s at the email address above.', 'orbit' ),
						defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' )
					)
				);
				?>
				<span class="orbit-required-mark" aria-hidden="true">*</span>
			</label>
			<label class="orbit-checkbox-label" for="<?php echo esc_attr( $sms_id ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $sms_id ); ?>"
					name="consent_sms"
					value="1"
					disabled
					data-orbit-sms-consent
				>
				<?php esc_html_e( "Also send me SMS notifications at the phone above (once SMS is live).", 'orbit' ); ?>
				<span class="orbit-help"><?php esc_html_e( '(Optional — only available when you provide a phone number.)', 'orbit' ); ?></span>
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Format a stored datetime string for display.
	 *
	 * Activity datetimes are stored naively — the clock time the poster
	 * typed in, with no timezone conversion at save. They represent the
	 * local clock time of the physical event ("5pm at the park") rather
	 * than a timezone-bearing instant. This formatter therefore parses
	 * the value naively (interpreting it as UTC purely so PHP's
	 * DateTime can read it) and formats it without timezone shifting,
	 * so a viewer in any timezone sees the same clock time the poster
	 * intended.
	 *
	 * Other persisted timestamps that this same helper also formats —
	 * `created_at` on subscriptions, for instance — are MySQL `datetime`
	 * columns set via `current_time( 'mysql' )` which produces site-tz
	 * (or UTC, depending on caller) values. Those are read back here in
	 * the same naive way, which is fine for the "Subscribed Apr 17,
	 * 2026" date-only display they're used for.
	 *
	 * @param string $datetime Stored datetime string (Y-m-d H:i:s).
	 * @param string $format   PHP date format. Default: full readable.
	 * @return string Formatted date string.
	 */
	private static function format_datetime( $datetime, $format = '' ) {
		if ( empty( $datetime ) ) {
			return '';
		}

		if ( ! $format ) {
			$format = 'l, F j \a\t g:i A';
		}

		try {
			$utc = new DateTimeZone( 'UTC' );
			$dt  = new DateTime( $datetime, $utc );

			return $dt->format( $format );
		} catch ( Exception $e ) {
			return $datetime;
		}
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
