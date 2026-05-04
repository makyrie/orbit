<?php
/**
 * WP-CLI notification commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage notifications.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Notification extends Orbit_CLI {

	/**
	 * Send a digest for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * @subcommand send-digest
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function send_digest( $args, $assoc_args ) {
		$result = Orbit_Notifier::send_digest( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Digest sent.' );
	}

	/**
	 * Preview a digest for a user (dry run).
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * @subcommand preview-digest
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function preview_digest( $args, $assoc_args ) {
		global $wpdb;

		$user_id           = absint( $args[0] );
		$log_table         = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$activities_table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;

		$queued_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT nl.activity_id, a.title, a.tier, a.date_time, a.profile_id
				FROM {$log_table} nl
				INNER JOIN {$activities_table} a ON nl.activity_id = a.id
				WHERE nl.user_id = %d AND nl.method = 'digest' AND nl.status = 'queued'
				ORDER BY a.tier DESC, a.date_time ASC",
				$user_id
			)
		);

		if ( empty( $queued_items ) ) {
			WP_CLI::log( 'No pending digest items.' );
			return;
		}

		WP_CLI::log( sprintf( 'Digest preview for user %d (%d items):', $user_id, count( $queued_items ) ) );
		WP_CLI::log( '' );

		$tier_labels = Orbit_Activity::get_tier_labels();

		foreach ( $queued_items as $item ) {
			$profile     = Orbit_Profile::get( $item->profile_id );
			$poster_name = $profile ? $profile->display_name : 'Unknown';
			$tier_label  = isset( $tier_labels[ $item->tier ] ) ? $tier_labels[ $item->tier ] : '';

			WP_CLI::log( sprintf( '  [%s] %s — %s', $tier_label, $item->title, $poster_name ) );
			if ( $item->date_time ) {
				WP_CLI::log( sprintf( '    When: %s', $item->date_time ) );
			}
		}
	}

	/**
	 * View notification log.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * [--method=<method>]
	 * [--status=<status>]
	 * [--after=<datetime>]
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function log( $args, $assoc_args ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_NOTIFICATION_LOG;
		$where  = array( '1=1' );
		$values = array();

		if ( isset( $assoc_args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $assoc_args['user_id'] );
		}

		if ( isset( $assoc_args['method'] ) ) {
			$where[]  = 'method = %s';
			$values[] = sanitize_text_field( $assoc_args['method'] );
		}

		if ( isset( $assoc_args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $assoc_args['status'] );
		}

		if ( isset( $assoc_args['after'] ) ) {
			$where[]  = 'created_at > %s';
			$values[] = sanitize_text_field( $assoc_args['after'] );
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 100";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		$logs = $wpdb->get_results( $sql );

		self::output_items( $logs, $assoc_args, array( 'id', 'user_id', 'activity_id', 'method', 'status', 'sent_at', 'created_at' ) );
	}

	/**
	 * Inspect phone verification state for a user.
	 *
	 * Returns the same payload as `GET /wp-json/orbit/v1/verify-phone`:
	 * phone, verified, state (`no_phone` | `pending` | `verified` | `unavailable`),
	 * twilio_configured, pending_phone, pending_code_expires_at.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 *   - csv
	 * ---
	 *
	 * @subcommand phone-status
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function phone_status( $args, $assoc_args ) {
		$user_id = absint( $args[0] );

		if ( ! get_userdata( $user_id ) ) {
			WP_CLI::error( sprintf( 'User %d not found.', $user_id ) );
		}

		$payload = Orbit_REST_Notification::build_phone_status( $user_id );

		self::output_item( (object) $payload, $assoc_args );
	}

	/**
	 * Send or verify a phone verification code for a user.
	 *
	 * Pass `--phone=<e164>` to send a code, or `--code=<6-digit>` to verify
	 * a previously-sent code. The two flags are mutually exclusive.
	 *
	 * Note: sending a code for a new phone overwrites any previously-stored
	 * candidate (the latest non-expired row wins). The user's verified phone
	 * (`orbit_phone` user_meta) is only updated on successful verification.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--phone=<e164>]
	 * : Phone number in E.164 format (e.g., +15551234567). Sends a code.
	 *
	 * [--code=<code>]
	 * : 6-digit verification code to verify.
	 *
	 * [--format=<format>]
	 * : Output format for the resulting phone-status payload.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 *   - csv
	 * ---
	 *
	 * @subcommand verify-phone
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function verify_phone( $args, $assoc_args ) {
		$user_id = absint( $args[0] );

		if ( ! get_userdata( $user_id ) ) {
			WP_CLI::error( sprintf( 'User %d not found.', $user_id ) );
		}

		$phone = isset( $assoc_args['phone'] ) ? $assoc_args['phone'] : null;
		$code  = isset( $assoc_args['code'] ) ? $assoc_args['code'] : null;

		if ( null === $phone && null === $code ) {
			WP_CLI::error( 'Provide either --phone=<e164> or --code=<6-digit>.' );
		}

		if ( null !== $phone && null !== $code ) {
			WP_CLI::error( '--phone and --code are mutually exclusive.' );
		}

		if ( null !== $phone ) {
			$result = Orbit_Phone_Verify::send_code( $user_id, $phone );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Verification code sent to %s for user %d.', $phone, $user_id ) );
		} else {
			$result = Orbit_Phone_Verify::verify_code( $user_id, $code );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Phone number verified for user %d.', $user_id ) );
		}

		$payload = Orbit_REST_Notification::build_phone_status( $user_id );
		self::output_item( (object) $payload, $assoc_args );
	}
}
