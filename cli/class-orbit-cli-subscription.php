<?php
/**
 * WP-CLI subscription commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage subscriptions.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Subscription extends Orbit_CLI {

	/**
	 * List subscriptions.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * [--profile_id=<id>]
	 * [--status=<status>]
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function list_( $args, $assoc_args ) {
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'    => isset( $assoc_args['user_id'] ) ? $assoc_args['user_id'] : null,
				'profile_id' => isset( $assoc_args['profile_id'] ) ? $assoc_args['profile_id'] : null,
				'status'     => isset( $assoc_args['status'] ) ? $assoc_args['status'] : null,
			)
		);

		self::output_items( $subscriptions, $assoc_args, array( 'id', 'user_id', 'profile_id', 'status', 'visibility_default', 'created_at' ) );
	}

	/**
	 * Approve a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function approve( $args, $assoc_args ) {
		$result = Orbit_Subscription::approve( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription approved.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Deny a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function deny( $args, $assoc_args ) {
		$result = Orbit_Subscription::deny( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription denied.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Remove a subscription.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function remove( $args, $assoc_args ) {
		$result = Orbit_Subscription::remove( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Subscription removed.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}

	/**
	 * Create a subscription (subscribe a user to a profile).
	 *
	 * Reaches parity with the public POST /orbit/v1/subscribe endpoint:
	 * optionally stashes a pending phone number and stamps consent ledger
	 * rows for the email and/or SMS channels. CLI-stamped rows carry
	 * `source=cli` and `user_agent=wp-cli` so ops-initiated provisioning is
	 * distinguishable from end-user opt-in for TCPA / TCR audits.
	 *
	 * ## OPTIONS
	 *
	 * --user_id=<id>
	 * : Subscriber's WordPress user ID.
	 *
	 * --profile_id=<id>
	 * : Profile ID to subscribe to.
	 *
	 * [--connection_note=<note>]
	 * : Optional connection note.
	 *
	 * [--phone=<phone>]
	 * : Optional phone number in E.164 format (e.g. +12025550123). Stored as
	 * `orbit_phone_pending` user_meta — promotion to `orbit_phone` still
	 * requires verification via Orbit_Phone_Verify.
	 *
	 * [--consent_email=<bool>]
	 * : Optional; when true, stamps an email opt-in row in the consent ledger.
	 * Default false. Unlike the REST endpoint (which requires consent_email
	 * for new-account creation), CLI usage may attach an existing user to a
	 * profile where prior consent already exists — so consent is opt-in here.
	 * ---
	 * default: false
	 * options:
	 *   - true
	 *   - false
	 * ---
	 *
	 * [--consent_sms=<bool>]
	 * : Optional; when true, stamps an SMS opt-in row. Requires --phone or the
	 * command errors out (mirrors the REST handler's consent_sms_without_phone
	 * error code). Default false.
	 * ---
	 * default: false
	 * options:
	 *   - true
	 *   - false
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *     # Subscribe a user with no consent capture (legacy behavior).
	 *     $ wp orbit subscription create --user_id=42 --profile_id=7
	 *
	 *     # Subscribe a user and stamp email + SMS consent with a phone.
	 *     $ wp orbit subscription create --user_id=42 --profile_id=7 \
	 *         --phone=+12025550123 --consent_email=true --consent_sms=true
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function create( $args, $assoc_args ) {
		global $wpdb;

		$user_id    = (int) ( isset( $assoc_args['user_id'] ) ? $assoc_args['user_id'] : 0 );
		$profile_id = (int) ( isset( $assoc_args['profile_id'] ) ? $assoc_args['profile_id'] : 0 );

		// Phone + consent flags. WP-CLI string-typecasts everything coming
		// off the command line, so normalize the booleans through the same
		// permissive truth check the rest of WordPress uses (rest_sanitize_
		// boolean treats "1", "true", "yes", "on" as true).
		$phone         = isset( $assoc_args['phone'] ) ? trim( (string) $assoc_args['phone'] ) : '';
		$consent_email = isset( $assoc_args['consent_email'] ) ? self::truthy( $assoc_args['consent_email'] ) : false;
		$consent_sms   = isset( $assoc_args['consent_sms'] ) ? self::truthy( $assoc_args['consent_sms'] ) : false;

		// E.164 validation — same regex the REST handler uses (todo 121
		// notes Orbit_Phone_Verify::E164_REGEX may land in todo 132; until
		// then the regex is inlined in both call sites).
		if ( '' !== $phone && ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			WP_CLI::error( 'Phone number must be in E.164 format, like +12025550123.' );
		}

		// SMS consent without a phone is a clear ops mistake — fail loud
		// rather than silently dropping the SMS row.
		if ( $consent_sms && '' === $phone ) {
			WP_CLI::error( 'consent_sms_without_phone: --consent_sms requires --phone.' );
		}

		// Snapshot the disclosure text BEFORE opening the transaction so any
		// throw inside the wrap doesn't have to redo the work on rollback.
		// Matches the REST handler's byte-for-byte capture.
		$cta_snapshot = Orbit_Shortcodes::compliance_disclosure_text();

		$subscription_id = 0;
		$ledger_rows     = 0;
		$phone_stashed   = false;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = Orbit_Subscription::subscribe(
				array(
					'user_id'         => $user_id,
					'profile_id'      => $profile_id,
					'connection_note' => isset( $assoc_args['connection_note'] ) ? $assoc_args['connection_note'] : null,
				)
			);

			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}

			$subscription_id = (int) $result;

			if ( '' !== $phone ) {
				// Mirrors the REST handler's pending-phone stash pattern
				// (see todo 110): the verified meta slot stays empty until
				// Orbit_Phone_Verify promotes it after code entry. The
				// companion `_at` timestamp gives the cleanup cron a way
				// to reap abandoned signups.
				update_user_meta( $user_id, 'orbit_phone_pending', $phone );
				update_user_meta( $user_id, 'orbit_phone_pending_at', time() );
				$phone_stashed = true;
			}

			Orbit_Notifier::get_or_create_preferences( $user_id );

			if ( $consent_email ) {
				$email_result = Orbit_Consent::record(
					$user_id,
					'email',
					'opt_in',
					array(
						'source'       => 'cli',
						'cta_snapshot' => $cta_snapshot,
						'ip'           => '',
						'user_agent'   => 'wp-cli',
					)
				);
				if ( is_wp_error( $email_result ) ) {
					throw new RuntimeException( 'consent_email: ' . $email_result->get_error_message() );
				}
				$ledger_rows++;
			}

			if ( $consent_sms && '' !== $phone ) {
				$sms_result = Orbit_Consent::record(
					$user_id,
					'sms',
					'opt_in',
					array(
						'source'       => 'cli',
						'cta_snapshot' => $cta_snapshot,
						'ip'           => '',
						'user_agent'   => 'wp-cli',
					)
				);
				if ( is_wp_error( $sms_result ) ) {
					throw new RuntimeException( 'consent_sms: ' . $sms_result->get_error_message() );
				}
				$ledger_rows++;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			// Mirror the REST handler's notifier-preferences cache eviction
			// so a failed run doesn't leave a phantom cache hit behind.
			if ( $user_id > 0 ) {
				Orbit_Notifier::forget_preferences( $user_id );
			}

			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Subscription created (ID: {$subscription_id})." );

		if ( $ledger_rows > 0 ) {
			WP_CLI::log( sprintf( 'Consent ledger rows added: %d.', $ledger_rows ) );
		}
		if ( $phone_stashed ) {
			WP_CLI::log( sprintf( 'Pending phone stashed for user %d.', $user_id ) );
		}

		self::output_item( Orbit_Subscription::get( $subscription_id ), $assoc_args );
	}

	/**
	 * Permissive boolean coercion for CLI assoc_args.
	 *
	 * WP-CLI hands every flag value back as a string, so "true"/"false"/"1"/
	 * "0" all need to collapse to PHP booleans before they hit the consent
	 * branches. Centralized here so the email/sms paths agree byte-for-byte.
	 *
	 * @param mixed $value Raw value from $assoc_args.
	 * @return bool
	 */
	private static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Unsubscribe (subscriber-initiated opt-out).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscription ID.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function unsubscribe( $args, $assoc_args ) {
		$result = Orbit_Subscription::unsubscribe( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Unsubscribed.' );
		self::output_item( Orbit_Subscription::get( absint( $args[0] ) ), array( 'format' => 'json' ) );
	}
}
