<?php
/**
 * WP-CLI signup command.
 *
 * Programmatic equivalent of `POST /orbit/v1/signup` — creates a poster
 * WordPress account and stamps the consent ledger using the same shape
 * as Orbit_REST_Signup::handle_signup() so seed scripts, fixtures, and
 * agent flows produce indistinguishable accounts from the web form.
 *
 * Validation, multisite role attach, transaction envelope, and consent
 * row construction all mirror the REST handler exactly. The only
 * intentional divergences are:
 *
 *   - Welcome email is OPTIONAL (--send-welcome-email) — off by default
 *     so seed scripts don't spray real inboxes.
 *   - Provenance is stamped as source=cli with a CLI-shaped user_agent.
 *   - No honeypot/rate-limit checks (CLI is operator-trusted).
 *   - No auto-login (CLI has no session).
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Create a poster account with consent capture.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Signup extends Orbit_CLI {

	/**
	 * Create a poster account with consent capture.
	 *
	 * Mirrors POST /orbit/v1/signup: validates inputs the same way,
	 * inserts the user with the same shape, attaches them to the
	 * current site on multisite, and writes one or two consent ledger
	 * rows under a transaction so a partial failure cannot leave a
	 * half-created account or a half-written audit trail.
	 *
	 * ## OPTIONS
	 *
	 * --display_name=<name>
	 * : Poster's display name. Required, must be non-empty after trim.
	 *
	 * --email=<email>
	 * : Poster's email address. Required, must pass is_email().
	 *
	 * [--phone=<phone>]
	 * : Optional phone number in E.164 format (e.g. +12025550123).
	 * Required when --consent_sms is set.
	 *
	 * [--consent_email]
	 * : Boolean flag — presence is treated as opt-in to email. Required.
	 *
	 * [--consent_sms]
	 * : Boolean flag — presence is treated as opt-in to SMS. Requires --phone.
	 *
	 * [--send-welcome-email]
	 * : When set, fires wp_send_new_user_notifications() so the new
	 * poster receives the standard WordPress password-set email. Off
	 * by default to avoid spamming inboxes during seed/fixture runs.
	 *
	 * [--format=<format>]
	 * : Output format for the summary payload.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Minimum: name + email + email consent.
	 *     $ wp orbit signup create --display_name="Test Poster" --email=test@example.test --consent_email
	 *
	 *     # Full: phone + SMS consent + welcome email.
	 *     $ wp orbit signup create --display_name="Pat" --email=pat@example.test \
	 *         --phone=+12025550199 --consent_email --consent_sms --send-welcome-email
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function create( $args, $assoc_args ) {
		$display_name       = isset( $assoc_args['display_name'] ) ? sanitize_text_field( $assoc_args['display_name'] ) : '';
		$email              = isset( $assoc_args['email'] ) ? sanitize_email( $assoc_args['email'] ) : '';
		$phone              = isset( $assoc_args['phone'] ) ? trim( (string) $assoc_args['phone'] ) : '';
		$consent_email      = ! empty( $assoc_args['consent_email'] );
		$consent_sms        = ! empty( $assoc_args['consent_sms'] );
		$send_welcome_email = ! empty( $assoc_args['send-welcome-email'] );

		// Validation mirrors Orbit_REST_Signup::handle_signup() — same
		// codes so scripts that handle one can handle the other.
		if ( '' === trim( $display_name ) ) {
			WP_CLI::error( 'invalid_name: Display name is required.' );
		}

		if ( ! is_email( $email ) ) {
			WP_CLI::error( 'invalid_email: Please provide a valid email address.' );
		}

		if ( ! $consent_email ) {
			WP_CLI::error( 'consent_required: --consent_email is required to create an account that will receive a password-set email.' );
		}

		if ( '' !== $phone && ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			WP_CLI::error( 'invalid_phone: Phone number must be in E.164 format, like +12025550123.' );
		}

		if ( $consent_sms && '' === $phone ) {
			WP_CLI::error( 'consent_sms_without_phone: --consent_sms requires --phone.' );
		}

		if ( get_user_by( 'email', $email ) ) {
			WP_CLI::error( sprintf( 'login_required: An account with email %s already exists.', $email ) );
		}

		// Cache the disclosure the user agreed to — same source as the
		// REST handler so the ledger snapshot is byte-identical across
		// surfaces.
		$cta_snapshot = Orbit_Compliance_UI::compliance_disclosure_text();

		// Build a unique username from the display name — same shape as
		// the REST handler. The provisioning service's retry loop closes
		// the race between username_exists() and wp_insert_user() when
		// two requests share a base.
		$base     = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ), true );
		$base     = '' !== $base ? $base : 'orbit-user';
		$username = $base . wp_rand( 10000, 99999 );

		// Build consent payload — CLI provenance is `source=cli`,
		// `user_agent=wp orbit signup create` so ops-initiated rows are
		// distinguishable from end-user opt-in for TCPA / TCR audits.
		$consents = array(
			'email' => array(
				'state'        => 'opt_in',
				'source'       => 'cli',
				'cta_snapshot' => $cta_snapshot,
				'ip'           => '',
				'user_agent'   => 'wp orbit signup create',
			),
		);
		if ( $consent_sms ) {
			$consents['sms'] = array(
				'state'        => 'opt_in',
				'source'       => 'cli',
				'cta_snapshot' => $cta_snapshot,
				'ip'           => '',
				'user_agent'   => 'wp orbit signup create',
			);
		}

		// Hand off the full transactional envelope to the provisioning
		// service: wp_insert_user (with the same 5-retry username loop
		// the REST handler uses), multisite role attach, timezone meta,
		// optional pending-phone meta, and consent rows — all inside one
		// START TRANSACTION / COMMIT.
		$user_id = Orbit_User_Provisioning::create_user_with_consent(
			array(
				'user_login'              => $username,
				'user_email'              => $email,
				'display_name'            => $display_name,
				// Parity with the REST handler: orbit_subscriber (not core
				// 'subscriber') so the account carries orbit_subscribe and
				// can create a profile. See #54.
				'role'                    => 'orbit_subscriber',
				'phone_pending'           => $phone,
				'username_retry_attempts' => 5,
			),
			$consents,
			array(
				// Welcome email is opt-in for CLI (vs. always-on for REST)
				// so seed scripts don't spray real inboxes. CLI also wants
				// the email delivered synchronously when requested — the
				// CLI process exits before any ActionScheduler tick fires.
				'send_welcome_email'     => $send_welcome_email,
				'schedule_welcome_async' => false,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id->get_error_code() . ': ' . $user_id->get_error_message() );
		}

		$pending_phone_stashed = '' !== $phone;

		// Inspect the ledger rows just written so the summary payload
		// keeps the same shape as before the refactor. Same-request reads
		// against the just-committed table are deterministic.
		global $wpdb;
		$table       = Orbit_Consent::table_name();
		$ledger_rows = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE user_id = %d ORDER BY id ASC",
					(int) $user_id
				)
			)
		);

		$user = get_user_by( 'id', (int) $user_id );

		$summary = (object) array(
			'user_id'               => (int) $user_id,
			'username'              => $user ? $user->user_login : $username,
			'email'                 => $email,
			'display_name'          => $display_name,
			'ledger_row_ids'        => $ledger_rows,
			'pending_phone_stashed' => $pending_phone_stashed,
			'welcome_email_sent'    => $send_welcome_email,
		);

		WP_CLI::success( sprintf( 'Account created (user_id: %d, ledger rows: %d).', (int) $user_id, count( $ledger_rows ) ) );
		self::output_item( $summary, $assoc_args );
	}
}
