<?php
/**
 * WP-CLI consent ledger commands.
 *
 * Operator and agent surface for the append-only consent ledger
 * (Orbit_Consent). Wraps the primitives Orbit_Consent::record(),
 * Orbit_Consent::latest_state(), and Orbit_Consent::verify_chain()
 * so operators can audit consent state, list ledger rows, and detect
 * tampering without dropping into `wp shell` or raw SQL.
 *
 * `verify` exits non-zero on a broken chain so it composes into CI
 * and monitoring scripts (e.g. `wp orbit consent verify ... || alert`).
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Inspect and verify the consent ledger.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Consent extends Orbit_CLI {

	/**
	 * List consent ledger rows.
	 *
	 * Reads the append-only ledger directly (SELECT is never blocked by
	 * the query guard) and surfaces the columns most useful for ops:
	 * id, user_id, channel, event, source, policy versions, created_at_utc.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * : Filter to a single user.
	 *
	 * [--channel=<channel>]
	 * : Filter by channel.
	 * ---
	 * options:
	 *   - email
	 *   - sms
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max rows to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Most recent 20 rows across the whole ledger.
	 *     $ wp orbit consent log
	 *
	 *     # All SMS rows for a user, as JSON for piping.
	 *     $ wp orbit consent log --user_id=42 --channel=sms --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function log( $args, $assoc_args ) {
		global $wpdb;

		$table  = Orbit_Consent::table_name();
		$where  = array( '1=1' );
		$values = array();

		if ( isset( $assoc_args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $assoc_args['user_id'] );
		}

		if ( isset( $assoc_args['channel'] ) ) {
			$channel = sanitize_text_field( $assoc_args['channel'] );
			if ( ! in_array( $channel, Orbit_Consent::CHANNELS, true ) ) {
				WP_CLI::error( sprintf( 'Invalid --channel value: %s', $channel ) );
			}
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		$limit = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 20;

		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$sql = "SELECT id, user_id, channel, event, source, privacy_policy_version, terms_version, created_at_utc
				FROM {$table}
				WHERE {$where_clause}
				ORDER BY created_at_utc DESC, id DESC
				LIMIT %d";

		$values[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
		// phpcs:enable

		self::output_items(
			$rows,
			$assoc_args,
			array( 'id', 'user_id', 'channel', 'event', 'source', 'privacy_policy_version', 'terms_version', 'created_at_utc' )
		);
	}

	/**
	 * Verify the consent ledger hash chain.
	 *
	 * Walks Orbit_Consent::verify_chain() over both email and sms
	 * channels (per-user when --user_id is provided, or every user
	 * with rows otherwise). Prints PASS / FAIL per (user, channel)
	 * tuple, lists the first broken row IDs, and exits non-zero on
	 * any failure so the command composes into monitoring scripts.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * : Limit verification to a single user. Omit to check every
	 * user with at least one ledger row.
	 *
	 * [--format=<format>]
	 * : Output format for the per-tuple result list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Verify chain for user 42.
	 *     $ wp orbit consent verify --user_id=42
	 *
	 *     # Verify every user. Exit 1 if any chain is broken.
	 *     $ wp orbit consent verify || alert "consent chain broken"
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function verify( $args, $assoc_args ) {
		global $wpdb;

		$table = Orbit_Consent::table_name();

		if ( isset( $assoc_args['user_id'] ) ) {
			$user_ids = array( absint( $assoc_args['user_id'] ) );
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$user_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} ORDER BY user_id ASC" ) );
			// phpcs:enable
		}

		if ( empty( $user_ids ) ) {
			WP_CLI::success( 'No ledger rows to verify.' );
			return;
		}

		$results       = array();
		$any_broken    = false;
		$broken_total  = 0;

		foreach ( $user_ids as $user_id ) {
			foreach ( Orbit_Consent::CHANNELS as $channel ) {
				$broken = Orbit_Consent::verify_chain( $user_id, $channel );

				$status = empty( $broken ) ? 'PASS' : 'FAIL';

				if ( ! empty( $broken ) ) {
					$any_broken    = true;
					$broken_total += count( $broken );
				}

				$results[] = (object) array(
					'user_id'         => (int) $user_id,
					'channel'         => $channel,
					'status'          => $status,
					'broken_row_ids'  => empty( $broken ) ? '' : implode( ',', $broken ),
					'broken_count'    => count( $broken ),
				);
			}
		}

		self::output_items( $results, $assoc_args, array( 'user_id', 'channel', 'status', 'broken_row_ids', 'broken_count' ) );

		if ( $any_broken ) {
			// WP_CLI::error() exits non-zero — that's the whole point of
			// the verify command for monitoring scripts. Pass false so
			// it doesn't re-print the message-wrapper banner over the
			// table we just rendered.
			WP_CLI::error( sprintf( 'Consent chain broken: %d row(s) failed verification across %d tuple(s).', $broken_total, count( array_filter( $results, static fn( $r ) => 'FAIL' === $r->status ) ) ) );
		}

		WP_CLI::success( sprintf( 'Consent chain intact across %d tuple(s).', count( $results ) ) );
	}

	/**
	 * Show the latest consent state for a user across both channels.
	 *
	 * Wraps Orbit_Consent::latest_state() for email and sms and
	 * prints a compact one-row-per-channel summary. A null value
	 * means the user has no rows for that channel.
	 *
	 * ## OPTIONS
	 *
	 * --user_id=<id>
	 * : WordPress user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp orbit consent state --user_id=42
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function state( $args, $assoc_args ) {
		if ( ! isset( $assoc_args['user_id'] ) ) {
			WP_CLI::error( '--user_id is required.' );
		}

		$user_id = absint( $assoc_args['user_id'] );

		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			WP_CLI::error( sprintf( 'User %d not found.', $user_id ) );
		}

		$results = array();
		foreach ( Orbit_Consent::CHANNELS as $channel ) {
			$state = Orbit_Consent::latest_state( $user_id, $channel );

			$results[] = (object) array(
				'user_id' => $user_id,
				'channel' => $channel,
				'state'   => null === $state ? '(none)' : $state,
			);
		}

		self::output_items( $results, $assoc_args, array( 'user_id', 'channel', 'state' ) );
	}
}
