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

		global $wpdb;

		// Cache the disclosure the user agreed to — same source as the
		// REST handler so the ledger snapshot is byte-identical across
		// surfaces.
		$cta_snapshot = Orbit_Shortcodes::compliance_disclosure_text();

		// Build a unique username from the display name — same shape as
		// the REST handler, including the post-check retry loop that
		// closes the race between username_exists() and wp_insert_user()
		// when two requests share a base.
		$base     = sanitize_user( strtolower( str_replace( ' ', '', $display_name ) ), true );
		$base     = '' !== $base ? $base : 'orbit-user';
		$username = $base . wp_rand( 10000, 99999 );

		$wpdb->query( 'START TRANSACTION' );

		$ledger_rows = array();

		try {
			$attempts = 0;
			$user_id  = null;
			do {
				$user_id = wp_insert_user(
					array(
						'user_login'   => $username,
						'user_pass'    => wp_generate_password(),
						'user_email'   => $email,
						'display_name' => $display_name,
						'role'         => 'subscriber',
					)
				);

				if ( ! is_wp_error( $user_id ) ) {
					break;
				}

				if ( 'existing_user_login' !== $user_id->get_error_code() ) {
					throw new RuntimeException( $user_id->get_error_message() );
				}

				$username = $base . wp_rand( 10000, 99999 );
				++$attempts;
			} while ( $attempts < 5 );

			if ( is_wp_error( $user_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				WP_CLI::error( 'user_creation_failed: ' . $user_id->get_error_message() );
			}

			// Multisite attach — same rationale as the REST handler:
			// wp_insert_user() creates a network user with no role on
			// the current sub-site; add_user_to_blog() pins the role
			// here. The function is only defined on multisite installs.
			if ( is_multisite() ) {
				add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
			}

			update_user_meta( $user_id, 'orbit_timezone', wp_timezone_string() );

			$pending_phone_stashed = false;
			if ( '' !== $phone ) {
				update_user_meta( $user_id, 'orbit_phone_pending', $phone );
				update_user_meta( $user_id, 'orbit_phone_pending_at', time() );
				$pending_phone_stashed = true;
			}

			$email_consent = Orbit_Consent::record(
				$user_id,
				'email',
				'opt_in',
				array(
					'source'       => 'cli',
					'cta_snapshot' => $cta_snapshot,
					'ip'           => '',
					'user_agent'   => 'wp orbit signup create',
				)
			);
			if ( is_wp_error( $email_consent ) ) {
				throw new RuntimeException( 'consent_email: ' . $email_consent->get_error_message() );
			}
			$ledger_rows[] = (int) $email_consent;

			if ( $consent_sms ) {
				$sms_consent = Orbit_Consent::record(
					$user_id,
					'sms',
					'opt_in',
					array(
						'source'       => 'cli',
						'cta_snapshot' => $cta_snapshot,
						'ip'           => '',
						'user_agent'   => 'wp orbit signup create',
					)
				);
				if ( is_wp_error( $sms_consent ) ) {
					throw new RuntimeException( 'consent_sms: ' . $sms_consent->get_error_message() );
				}
				$ledger_rows[] = (int) $sms_consent;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			WP_CLI::error( 'signup_failed: ' . $e->getMessage() );
		}

		// Side effects after COMMIT — they can't be rolled back. The
		// welcome email is opt-in for CLI (vs. always-on for REST) so
		// seed scripts don't spray real inboxes; pass --send-welcome-email
		// when you actually want the password-set link delivered.
		if ( $send_welcome_email ) {
			wp_send_new_user_notifications( $user_id, 'user' );
		}

		$summary = (object) array(
			'user_id'               => (int) $user_id,
			'username'              => $username,
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
