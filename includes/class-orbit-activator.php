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
		self::seed_consent_ip_salt();
	}

	/**
	 * Seed the consent IP salt option on fresh installs.
	 *
	 * Orbit_Consent::record() refuses to write a ledger row when no salt
	 * resolves — and every signup / subscribe write runs through it. Without
	 * an activator-side fallback, a fresh install on a host where the
	 * operator hasn't added ORBIT_CONSENT_IP_SALT to wp-config.php would 500
	 * every signup. We mint a per-site fallback so zero-config installs work.
	 *
	 * Guards:
	 * - If the constant is defined, do nothing — the documented best practice
	 *   wins and we leave the option absent so deleting it does not silently
	 *   shadow the constant later.
	 * - If the option already exists (re-activation, restored backup), do
	 *   nothing — rewriting the salt would invalidate every previously
	 *   recorded ip_hash.
	 *
	 * Autoload is off because record() is the only consumer and it runs
	 * outside the page-load hot path.
	 */
	public static function seed_consent_ip_salt() {
		if ( defined( 'ORBIT_CONSENT_IP_SALT' ) ) {
			return;
		}

		if ( false !== get_option( 'orbit_consent_ip_salt', false ) ) {
			return;
		}

		add_option( 'orbit_consent_ip_salt', wp_generate_password( 64, false ), '', false );
	}

	/**
	 * Create all 8 custom tables using dbDelta.
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
			audience text DEFAULT NULL,
			location_name varchar(300) DEFAULT NULL,
			location_address text DEFAULT NULL,
			date_time datetime DEFAULT NULL,
			date_flexible tinyint(1) NOT NULL DEFAULT 0,
			url text DEFAULT NULL,
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
		//
		// Status is varchar(32) on fresh installs so v1.6.0's
		// 'queued' | 'sent' | 'failed' fit comfortably. Installs upgrading
		// from <=1.5.x retain the legacy enum column until v1.1 ships an
		// explicit, version-gated widening migration; v1.6.0 only writes
		// values that fit in either column type.
		$table_notif_log = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$sql[]           = "CREATE TABLE {$table_notif_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			activity_id bigint(20) unsigned NOT NULL,
			method enum('sms','email','digest') NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'queued',
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

		// orbit_consent_ledger.
		//
		// Network-scoped on multisite (base_prefix, not prefix). Consent
		// attaches to a user identity, which is network-wide. Per-site
		// scoping would fragment the audit trail.
		//
		// Append-only by design — see Orbit_Consent for the hash-chain
		// invariant and the query-filter guard that refuses UPDATE/DELETE
		// outside an ORBIT_CONSENT_MIGRATION window.
		//
		// `user_id` has no FK constraint — TCPA evidence must survive user
		// deletion. PII redaction on user deletion is implemented in
		// `Orbit_Privacy::cleanup_user_data()` (v1.6.0).
		$table_consent_ledger = $wpdb->base_prefix . ORBIT_TABLE_CONSENT_LEDGER;
		$sql[]                = "CREATE TABLE {$table_consent_ledger} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			channel enum('email','sms') NOT NULL,
			event enum('opt_in','opt_out','re_opt_in') NOT NULL,
			program varchar(64) NOT NULL DEFAULT 'creator-notifications',
			cta_snapshot text NOT NULL,
			source varchar(64) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			privacy_policy_version varchar(32) NOT NULL DEFAULT '',
			terms_version varchar(32) NOT NULL DEFAULT '',
			created_at_utc datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			row_hash char(64) NOT NULL,
			prev_hash char(64) NOT NULL DEFAULT '',
			redacted_at_utc datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chain_pos (user_id, channel, prev_hash),
			KEY user_channel_time (user_id, channel, created_at_utc),
			KEY channel_event_time (channel, event, created_at_utc),
			KEY redacted_at (redacted_at_utc)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	/**
	 * Create WordPress pages for authenticated routes.
	 *
	 * These pages use shortcodes registered by the plugin to render dynamic content.
	 * The theme's FSE templates handle the outer layout.
	 */
	public static function create_pages() {
		$pages = array(
			'dashboard'     => array(
				'title'    => 'Dashboard',
				'content'  => '[orbit_dashboard]',
				'template' => 'page-app',
			),
			'settings'      => array(
				'title'    => 'Settings',
				'content'  => '[orbit_settings]',
				'template' => 'page-app',
			),
			'subscriptions' => array(
				'title'    => 'Subscriptions',
				'content'  => '[orbit_my_subscriptions]',
				'template' => 'page-app',
			),
			'manage'        => array(
				'title'    => 'Manage',
				'content'  => '[orbit_manage]',
				'template' => 'page-app',
			),
			'new-activity'  => array(
				'title'    => 'New Activity',
				'content'  => '[orbit_new_activity]',
				'template' => 'page-app',
			),
			'edit-activity' => array(
				'title'    => 'Edit Activity',
				'content'  => '[orbit_edit_activity]',
				'template' => 'page-app',
			),
			'subscribers'   => array(
				'title'    => 'Subscribers',
				'content'  => '[orbit_subscribers]',
				'template' => 'page-app',
			),
			'edit-profile'  => array(
				'title'    => 'Edit Profile',
				'content'  => '[orbit_edit_profile]',
				'template' => 'page-app',
			),
			'sign-up'       => array(
				'title'    => 'Sign Up',
				'content'  => '[orbit_sign_up]',
				'template' => '',
			),
			'why'           => array(
				'title'     => 'Why this exists',
				'content'   => self::why_page_content(),
				'template'  => '',
				'code_owned' => 'why',
			),
			'contact'       => array(
				'title'      => 'Contact',
				'content'    => self::contact_page_content(),
				'template'   => '',
				'code_owned' => 'contact',
			),
			// Public compliance pages. /privacy/ and /terms/ are referenced by
			// the subscribe + signup compliance blocks and are a required
			// field on TCR campaign registration (as of 2026-06-30). The
			// content uses Twilio-blessed sharing language verbatim — see
			// docs/compliance/privacy-policy.md for the canonical source.
			//
			// Both pages get an `orbit_policy_version` post_meta so that
			// Orbit_Consent::record() can capture the version each user
			// agreed to at consent time.
			'privacy'       => array(
				'title'                => 'Privacy Policy',
				'content'              => self::compliance_page_content( 'privacy' ),
				'template'             => '',
				'meta'                 => array(
					'orbit_policy_version' => ORBIT_VERSION,
				),
				'compliance_canonical' => 'privacy',
				'code_owned'           => 'privacy',
			),
			'terms'         => array(
				'title'                => 'Terms of Service',
				'content'              => self::compliance_page_content( 'terms' ),
				'template'             => '',
				'meta'                 => array(
					'orbit_policy_version' => ORBIT_VERSION,
				),
				'compliance_canonical' => 'terms',
				'code_owned'           => 'terms',
			),
		);

		foreach ( $pages as $slug => $page_data ) {
			$existing       = get_page_by_path( $slug );
			$is_canonical   = ! empty( $page_data['compliance_canonical'] );
			$canonical_kind = $is_canonical ? (string) $page_data['compliance_canonical'] : '';
			$owned_kind     = ! empty( $page_data['code_owned'] ) ? (string) $page_data['code_owned'] : '';

			if ( $existing ) {
				$page_id = $existing->ID;
				if ( $owned_kind && ! self::may_manage_page( $existing, $owned_kind ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( 'Orbit_Activator: refusing to overwrite unowned page at "/%s/" (page_id=%d).', $slug, $page_id ) );
					continue;
				}
			} else {
				$post_args = array(
					'post_title'   => $page_data['title'],
					'post_name'    => $slug,
					'post_content' => $page_data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
				);

				$meta_input = array();

				if ( ! empty( $page_data['template'] ) ) {
					$meta_input['_wp_page_template'] = $page_data['template'];
				}

				if ( ! empty( $page_data['meta'] ) ) {
					$meta_input = array_merge( $meta_input, $page_data['meta'] );
				}

				// Stamp the canonical-compliance ownership marker at insert
				// time so newly-minted /privacy/ and /terms/ pages are
				// identifiable as Orbit-owned from the very first row.
				if ( $is_canonical ) {
					$meta_input['_orbit_canonical_compliance'] = $canonical_kind;
				}
				if ( $owned_kind ) {
					$meta_input['_orbit_code_owned_page'] = $owned_kind;
				}

				if ( ! empty( $meta_input ) ) {
					$post_args['meta_input'] = $meta_input;
				}

				$page_id = wp_insert_post( $post_args );
			}

			if ( ! $page_id || is_wp_error( $page_id ) ) {
				continue;
			}

			if ( $owned_kind ) {
				wp_update_post( array(
					'ID'           => $page_id,
					'post_title'   => $page_data['title'],
					'post_content' => $page_data['content'],
					'post_status'  => 'publish',
				) );
				update_post_meta( $page_id, '_orbit_code_owned_page', $owned_kind );
			}

			// Always upsert declared meta on every activation so values like
			// `orbit_policy_version` stay in sync with the plugin even when the
			// page itself already exists (e.g. on upgrade). The content/template
			// insert above is what's gated on `! $existing`; the meta-write is
			// not.
			if ( ! empty( $page_data['meta'] ) ) {
				foreach ( $page_data['meta'] as $meta_key => $meta_value ) {
					update_post_meta( $page_id, $meta_key, $meta_value );
				}
			}

			// Canonical compliance pages (/privacy/, /terms/) store their
			// page_id in an option so the consent ledger and any downstream
			// URL/version lookups can dereference the canonical post directly
			// instead of going through get_page_by_path() — which lets any
			// user with edit_pages capability silently win the slug by
			// pre-creating a draft. See todo 117.
			//
			// Slug-collision guard: if the page at the slug is already
			// stamped `_orbit_canonical_compliance` but with a marker value
			// for a DIFFERENT canonical kind (e.g. the /privacy/ page is
			// somehow stamped 'terms'), refuse to overwrite the option — the
			// data is inconsistent and the operator needs to reconcile. The
			// policy-version meta upsert above still runs, preserving todo
			// 112 behavior.
			if ( $is_canonical ) {
				$option_key = 'privacy' === $canonical_kind
					? 'orbit_privacy_page_id'
					: 'orbit_terms_page_id';

				$existing_marker = get_post_meta( $page_id, '_orbit_canonical_compliance', true );

				$collision_detected = ! empty( $existing_marker )
					&& (string) $existing_marker !== $canonical_kind;

				if ( $collision_detected ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'Orbit_Activator: slug-collision detected for canonical compliance page "/%s/" (page_id=%d, existing marker="%s"). Skipping orbit_%s_page_id option write — reconcile manually.',
							$slug,
							$page_id,
							(string) $existing_marker,
							$canonical_kind
						)
					);
				} else {
					// Stamp the ownership marker on existing pages too —
					// newly inserted pages already received it via
					// meta_input, but re-activation paths and pages that
					// pre-existed the canonical-id system need backfill.
					update_post_meta( $page_id, '_orbit_canonical_compliance', $canonical_kind );

					update_option( $option_key, (int) $page_id, false );
				}
			}
		}
	}

	/**
	 * Whether an existing page is safe for a code-owned release to update.
	 *
	 * The legacy Why page predates ownership metadata. Its distinctive copy
	 * provides a narrow one-time adoption path; every later update uses meta.
	 *
	 * @param WP_Post $page Existing page.
	 * @param string  $kind Expected ownership marker.
	 * @return bool
	 */
	protected static function may_manage_page( $page, $kind ) {
		$marker = (string) get_post_meta( $page->ID, '_orbit_code_owned_page', true );
		if ( $kind === $marker ) {
			return true;
		}

		if ( in_array( $kind, array( 'privacy', 'terms' ), true ) ) {
			return $kind === (string) get_post_meta( $page->ID, '_orbit_canonical_compliance', true );
		}

		return 'why' === $kind
			&& false !== strpos( $page->post_content, 'Inviting is asymmetric work' )
			&& false !== strpos( $page->post_content, 'designed to be put down' );
	}

	/** @return string */
	protected static function why_page_content() {
		return '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">Most apps designed to bring people together are also designed, more quietly, to keep you on the app. Perihelion is for the simpler thing: making it easier to ask your friends to do something together.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Inviting is asymmetric work. The friend who plans things notices nobody has seen each other in a while, picks the place, sends the text, and fields the half-replies. Group chats reward the loud; event platforms turn casual hangs into productions; calendar invites are for meetings.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Perihelion makes one structural choice and lets it ripple: friends opt in once, on their own terms. Every later invitation is something they already agreed to receive. The organizer is freed from feeling like a burden, and the invited friend can decline simply by saying nothing.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>There is no feed to scroll, no streak to maintain, and no discovery engine. Notifications arrive by email, immediately or in a daily digest. SMS is planned, but it will stay off until the service is approved to send it responsibly. When the plan has been made, the right thing to do is close the tab.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>It works for old friends and for newer friendships that need a little room to grow. Inviting somebody you barely know to do something casual is one of the highest-friction asks in adult life. Reducing that friction is a good in itself.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Perihelion is a small, open-source, non-commercial utility built by Sarah Lewis. It does not run ads or sell your data. If it helps you see your friends more often, that is enough.</p><!-- /wp:paragraph -->\n\n<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/sign-up/">Set up your profile</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	}

	/** @return string */
	protected static function contact_page_content() {
		return '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">Perihelion is a small project run by Sarah Lewis. Email <a href="mailto:sarah@perihelion.social">sarah@perihelion.social</a> with questions about your account, privacy, notifications, or the service.</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>I usually reply within a few days. Please do not send passwords, verification codes, or other secrets by email.</p><!-- /wp:paragraph -->\n\n<!-- wp:heading --><h2 class="wp-block-heading">Technical issues</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>If you have found a reproducible bug or want to follow development, open an issue in the <a href="https://github.com/makyrie/orbit/issues">Orbit GitHub repository</a>. For anything involving personal information, email instead.</p><!-- /wp:paragraph -->';
	}

	/**
	 * Build post_content for the /privacy/ or /terms/ page.
	 *
	 * Stored as Gutenberg block markup so admins can edit via the block
	 * editor after activation. Headings and paragraphs only — no fancy
	 * layout — so the active theme controls typography.
	 *
	 * Canonical source: docs/compliance/{privacy-policy,terms-of-service}.md.
	 * When the published copy changes, update both this method AND the
	 * markdown source-of-truth, and bump ORBIT_VERSION so the
	 * orbit_policy_version post_meta tracks the change.
	 *
	 * @param string $kind 'privacy' or 'terms'.
	 * @return string Block-formatted post content.
	 */
	protected static function compliance_page_content( $kind ) {
		if ( 'privacy' === $kind ) {
			return self::privacy_policy_content();
		}

		return self::terms_of_service_content();
	}

	/**
	 * Privacy policy content.
	 *
	 * MUST byte-match the prose in docs/compliance/privacy-policy.md
	 * (block markup excluded). When updating, edit both files and run
	 * `composer policy-diff` (or `php bin/check-policy-sync.php`). The
	 * Orbit_Consent ledger stamps ORBIT_VERSION on every policy
	 * revision — bump it whenever this prose changes.
	 *
	 * @return string
	 */
	protected static function privacy_policy_content() {
		ob_start();
		?>
<!-- wp:paragraph --><p><em>Last updated: July 18, 2026 · Version: 1.8.0</em></p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>This Privacy Policy describes how Perihelion ("we", "us", "our") collects, uses, and shares information about you when you use perihelion.social and our notification services (the "Service").</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>What we collect</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>When you create an account or subscribe to a creator, we collect:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li><strong>Account information</strong>: your name, email address, and (optionally) phone number.</li>
<li><strong>Notification preferences</strong>: which creators you follow, which channels you want to receive notifications on (email and/or SMS), and how often.</li>
<li><strong>Activity records</strong>: which activities you've responded to ("going," "maybe," or no response).</li>
<li><strong>Technical context at consent</strong>: when you opt in to or out of notifications, we record the date and time, a one-way hashed version of your IP address, a truncated user-agent string, and the exact wording of the consent prompt you saw. This is held as immutable audit evidence required by U.S. telecommunications regulations (TCPA).</li>
</ul><!-- /wp:list -->

<!-- wp:paragraph --><p>We do not sell your information. We do not share your information with advertisers, data brokers, or lead-generation services.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>How we use your information</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>We use your information to:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li>Operate the Service — deliver notifications when creators you follow post activities.</li>
<li>Authenticate you and keep your account secure.</li>
<li>Respond to your requests for help.</li>
<li>Comply with legal obligations (including responding to lawful requests from courts or government agencies).</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>How we share your information</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p><strong>No mobile information will be shared with third parties or affiliates for marketing or promotional purposes. All the above categories exclude text-messaging originator opt-in data and consent; this information will not be shared with any third parties.</strong></p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>We do work with a small number of subcontractors who provide infrastructure that makes the Service work:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li><strong>Twilio</strong> — sends SMS messages when you've opted in to SMS notifications.</li>
<li><strong>SendGrid</strong> — delivers transactional and notification emails.</li>
<li><strong>The host of perihelion.social</strong> — runs the WordPress server.</li>
</ul><!-- /wp:list -->

<!-- wp:paragraph --><p>These subcontractors process information only on our behalf, only to provide their service, and may not use it for their own marketing.</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>We may disclose information when required by law, when responding to a lawful court order or subpoena, or when necessary to protect the safety or rights of users or the public.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>SMS and email opt-in</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>When you opt in to SMS notifications from Perihelion, you agree to receive messages about activities posted by creators you follow. <strong>Message frequency varies — up to 10 messages per week.</strong> Message and data rates may apply.</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>To stop receiving SMS messages, reply <strong>STOP</strong> to any message we send you, or visit your account settings. For help, reply <strong>HELP</strong>. For email, click the "Unsubscribe" link in any message we send you, or visit your account settings.</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>We honor opt-out requests promptly across both channels.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Your rights</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>You can:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li>Access, correct, or delete your account information at any time via your account settings.</li>
<li>Opt out of any notification channel via STOP, HELP, an unsubscribe link, or account settings.</li>
<li>Request a copy of the consent and notification records we hold about you by emailing the support address below.</li>
</ul><!-- /wp:list -->

<!-- wp:paragraph --><p>If you delete your account, we will redact personally identifying details from our consent audit records but retain the record itself (without identifying you) for the length of time required by TCPA.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Data retention</h2><!-- /wp:heading -->

<!-- wp:list --><ul>
<li>Account information: retained while your account is active. Deleted within 30 days of account deletion.</li>
<li>Notification log: retained for 90 days, then automatically purged.</li>
<li>Consent audit records: retained for 4 years (the TCPA statute-of-limitations period). After that, personally identifying details (hashed IP, user agent) are removed while the consent event itself is preserved as audit evidence.</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Children</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>The Service is not directed to children under 13. We do not knowingly collect personal information from children under 13.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Changes to this Policy</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>We may revise this Policy. The "Last updated" date at the top of this page reflects the current version. Material changes will be announced via email to active account holders at least 30 days before they take effect.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Contact</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>For privacy questions, opt-out requests, or to exercise the rights above, email <a href="mailto:sarah@perihelion.social">sarah@perihelion.social</a>.</p><!-- /wp:paragraph -->
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * Terms of service content.
	 *
	 * MUST byte-match the prose in docs/compliance/terms-of-service.md
	 * (block markup excluded). When updating, edit both files and run
	 * `composer policy-diff` (or `php bin/check-policy-sync.php`). The
	 * Orbit_Consent ledger stamps ORBIT_VERSION on every policy
	 * revision — bump it whenever this prose changes.
	 *
	 * @return string
	 */
	protected static function terms_of_service_content() {
		ob_start();
		?>
<!-- wp:paragraph --><p><em>Last updated: July 18, 2026 · Version: 1.8.0</em></p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>These Terms govern your use of perihelion.social and our notification services (the "Service"). By creating an account or subscribing to a creator, you agree to these Terms.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>What Perihelion does</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>Perihelion lets you subscribe to creators ("posters") and receive notifications when they post activities — meetups, events, ideas, plans. Notifications are delivered by email and, when you opt in, SMS.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Account and consent</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>You must be at least 13 years old to use the Service. When you create an account or subscribe to a creator, you confirm that you control the email address (and phone number, if provided) you give us, and that you authorize Perihelion to send notifications to those endpoints.</p><!-- /wp:paragraph -->

<!-- wp:paragraph --><p>You may revoke that authorization at any time:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li><strong>SMS</strong>: reply STOP to any message, or change your settings.</li>
<li><strong>Email</strong>: click the "Unsubscribe" link in any message, or change your settings.</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Messaging program</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>When you opt in to SMS notifications, you agree to receive activity notifications from creators you follow.</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li><strong>Frequency</strong>: up to 10 messages per week.</li>
<li><strong>Msg &amp; data rates may apply</strong> — your wireless carrier may charge for messages sent or received.</li>
<li><strong>Reply STOP</strong> to opt out of all Perihelion SMS at any time. You will receive a confirmation message.</li>
<li><strong>Reply HELP</strong> for help — you'll get a reply with our support contact, message frequency, and a STOP reminder.</li>
</ul><!-- /wp:list -->

<!-- wp:paragraph --><p>For full privacy details, see our <a href="/privacy/">Privacy Policy</a>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Conduct</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>You agree not to:</p><!-- /wp:paragraph -->

<!-- wp:list --><ul>
<li>Use the Service to harass, threaten, or impersonate others.</li>
<li>Post unlawful content or engage in unlawful activity.</li>
<li>Interfere with the Service's operation (e.g., scraping, DDoS, bypassing rate limits).</li>
<li>Use the Service to send commercial messages without recipient consent.</li>
</ul><!-- /wp:list -->

<!-- wp:paragraph --><p>We may suspend or terminate accounts that violate these Terms.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Your content</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>You retain ownership of any content you post (activity titles, descriptions, RSVPs). By posting, you grant Perihelion a non-exclusive, royalty-free license to display that content to other users you've authorized to see it (your subscribers).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Disclaimers</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>The Service is provided "as is." We do not guarantee uninterrupted availability, that all messages will be delivered, or that the Service will be free of errors. Carrier-side delivery is outside our control.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Limitation of liability</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>To the maximum extent permitted by law, Perihelion is not liable for indirect, incidental, special, consequential, or punitive damages arising out of your use of the Service. Our total liability to you for any claim arising out of or relating to these Terms or the Service will not exceed the greater of (a) $100, or (b) the amount you paid us in the 12 months before the claim arose.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Governing law</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>These Terms are governed by the laws of the United States and, where applicable, the State of California, without regard to its conflict-of-laws principles. Disputes will be resolved in the state or federal courts located in San Francisco County, California.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Changes</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>We may revise these Terms. The "Last updated" date reflects the current version. Material changes will be announced via email to active account holders at least 30 days before they take effect; continued use after the effective date constitutes acceptance.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Contact</h2><!-- /wp:heading -->

<!-- wp:paragraph --><p>For questions about these Terms, email <a href="mailto:sarah@perihelion.social">sarah@perihelion.social</a>.</p><!-- /wp:paragraph -->
		<?php
		return trim( ob_get_clean() );
	}
}
