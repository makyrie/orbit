<?php
/**
 * Tests for Orbit_Consent ledger.
 *
 * @package Orbit
 */

/**
 * Class OrbitConsentTest
 */
class OrbitConsentTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	protected $user_id;

	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		// Reset the consent ledger between tests so hash chains don't
		// pile up across runs. The query guard refuses naked DELETE — use
		// the sanctioned migration-mode wrapper.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		// Ensure any per-test salt-option overrides don't leak across tests.
		delete_option( 'orbit_consent_ip_salt' );
	}

	public function test_record_returns_row_id() {
		$id = Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'source' => 'test' ) );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_record_rejects_unknown_channel() {
		$result = Orbit_Consent::record( $this->user_id, 'fax', 'opt_in' );

		$this->assertWPError( $result );
		$this->assertSame( 'orbit_consent_invalid_channel', $result->get_error_code() );
	}

	public function test_record_rejects_unknown_event() {
		$result = Orbit_Consent::record( $this->user_id, 'email', 'shrug' );

		$this->assertWPError( $result );
		$this->assertSame( 'orbit_consent_invalid_event', $result->get_error_code() );
	}

	public function test_record_rejects_invalid_user_id() {
		$result = Orbit_Consent::record( 0, 'email', 'opt_in' );

		$this->assertWPError( $result );
		$this->assertSame( 'orbit_consent_invalid_user', $result->get_error_code() );
	}

	public function test_latest_state_returns_most_recent_event() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'source' => 'subscribe', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'source' => 'unsubscribe', 'created_at_utc' => '2026-02-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 're_opt_in', array( 'source' => 'settings', 'created_at_utc' => '2026-03-01 00:00:00' ) );

		$this->assertSame( 're_opt_in', Orbit_Consent::latest_state( $this->user_id, 'email' ) );
	}

	public function test_latest_state_per_channel_is_independent() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'sms', 'opt_out', array( 'created_at_utc' => '2026-01-01 00:00:00' ) );

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->user_id, 'email' ) );
		$this->assertSame( 'opt_out', Orbit_Consent::latest_state( $this->user_id, 'sms' ) );
	}

	public function test_latest_state_returns_null_when_no_events() {
		$this->assertNull( Orbit_Consent::latest_state( $this->user_id, 'email' ) );
	}

	public function test_verify_chain_intact_after_normal_writes() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'cta_snapshot' => 'cta-v1', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'created_at_utc' => '2026-02-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 're_opt_in', array( 'created_at_utc' => '2026-03-01 00:00:00' ) );

		$broken = Orbit_Consent::verify_chain( $this->user_id, 'email' );

		$this->assertSame( array(), $broken );
	}

	public function test_verify_chain_detects_tampering() {
		// Setup: three legitimate ledger rows.
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'cta_snapshot' => 'cta-v1', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'created_at_utc' => '2026-02-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 're_opt_in', array( 'created_at_utc' => '2026-03-01 00:00:00' ) );

		// Tamper: mutate the cta_snapshot on row 2 directly via raw SQL,
		// using the sanctioned migration-mode wrapper to bypass the
		// append-only guard.
		Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET cta_snapshot = %s WHERE user_id = %d AND event = 'opt_out'",
						'tampered',
						$this->user_id
					)
				);
			}
		);

		$broken = Orbit_Consent::verify_chain( $this->user_id, 'email' );

		$this->assertNotEmpty( $broken, 'verify_chain must report at least one broken row after tampering' );
	}

	public function test_verify_chain_detects_privacy_policy_version_tampering() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'privacy_version' => '2026-01-01', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'privacy_version' => '2026-01-01', 'created_at_utc' => '2026-02-01 00:00:00' ) );

		// Tamper: swap the privacy_policy_version on the second row. The
		// TODO 080 fix folds privacy_policy_version into the hash payload,
		// so this swap must break the chain.
		Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET privacy_policy_version = %s WHERE user_id = %d AND event = 'opt_out'",
						'2099-12-31-FAKE',
						$this->user_id
					)
				);
			}
		);

		$broken = Orbit_Consent::verify_chain( $this->user_id, 'email' );

		$this->assertNotEmpty( $broken, 'privacy_policy_version tampering must break the chain' );
	}

	public function test_verify_chain_detects_terms_version_tampering() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'terms_version' => '2026-01-01', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'terms_version' => '2026-01-01', 'created_at_utc' => '2026-02-01 00:00:00' ) );

		Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET terms_version = %s WHERE user_id = %d AND event = 'opt_out'",
						'forged-terms',
						$this->user_id
					)
				);
			}
		);

		$broken = Orbit_Consent::verify_chain( $this->user_id, 'email' );

		$this->assertNotEmpty( $broken, 'terms_version tampering must break the chain' );
	}

	public function test_verify_chain_detects_program_tampering() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'program' => 'creator-notifications', 'created_at_utc' => '2026-01-01 00:00:00' ) );
		Orbit_Consent::record( $this->user_id, 'email', 'opt_out', array( 'program' => 'creator-notifications', 'created_at_utc' => '2026-02-01 00:00:00' ) );

		Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET program = %s WHERE user_id = %d AND event = 'opt_out'",
						'other-program',
						$this->user_id
					)
				);
			}
		);

		$broken = Orbit_Consent::verify_chain( $this->user_id, 'email' );

		$this->assertNotEmpty( $broken, 'program tampering must break the chain' );
	}

	public function test_verify_chain_detects_user_id_tampering() {
		// Create two users with one row each, then swap user_id on user A's
		// row to point at user B. verify_chain() must catch the swap
		// because it rehashes against the row's stored user_id, not the
		// query parameter.
		$other_user_id = self::factory()->user->create();

		Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'created_at_utc' => '2026-01-01 00:00:00' ) );

		Orbit_Consent::with_migration_mode(
			function () use ( $other_user_id ) {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET user_id = %d WHERE user_id = %d",
						$other_user_id,
						$this->user_id
					)
				);
			}
		);

		// Verify under the *new* user_id (the tamper attempt's target).
		// The row was originally hashed against $this->user_id; the recompute
		// uses the stored (mutated) user_id, so the stored hash won't match.
		$broken = Orbit_Consent::verify_chain( $other_user_id, 'email' );

		$this->assertNotEmpty( $broken, 'user_id tampering must break the chain' );
	}

	public function test_query_guard_blocks_naked_update() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		global $wpdb;
		$table = Orbit_Consent::table_name();

		// Attempt a naked UPDATE outside migration mode. The query filter
		// substitutes a no-op SELECT, so 0 rows affected.
		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( "UPDATE {$table} SET event = 'opt_out' WHERE user_id = {$this->user_id}" );
		error_reporting( $prior_level );

		$this->assertSame( 0, (int) $result, 'Naked UPDATE must be coerced to a no-op SELECT' );

		// And confirm the row was NOT actually mutated.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT event FROM {$table} WHERE user_id = %d LIMIT 1",
				$this->user_id
			)
		);
		$this->assertSame( 'opt_in', $row->event );
	}

	public function test_query_guard_blocks_comment_prefixed_update() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		global $wpdb;
		$table = Orbit_Consent::table_name();

		// A trace-comment-prefixed UPDATE (the form Query Monitor / NewRelic
		// emit) must still be blocked by the allow-list guard.
		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "/* trace=qm */ UPDATE {$table} SET event = 'opt_out' WHERE user_id = {$this->user_id}" );
		error_reporting( $prior_level );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT event FROM {$table} WHERE user_id = %d LIMIT 1",
				$this->user_id
			)
		);
		$this->assertSame( 'opt_in', $row->event, 'Comment-prefixed UPDATE must be blocked by the guard' );
	}

	public function test_query_guard_blocks_insert_on_duplicate_key_update() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		global $wpdb;
		$table = Orbit_Consent::table_name();

		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			"INSERT INTO {$table} (user_id, channel, event, program, created_at_utc, row_hash, prev_hash) "
			. "VALUES ({$this->user_id}, 'email', 'opt_out', 'creator-notifications', '2026-04-01 00:00:00', 'forged', '') "
			. 'ON DUPLICATE KEY UPDATE event = VALUES(event)'
		);
		error_reporting( $prior_level );

		$this->assertSame( 0, (int) $result, 'INSERT ... ON DUPLICATE KEY UPDATE must be no-oped' );

		// And the original row must still read opt_in.
		$state = Orbit_Consent::latest_state( $this->user_id, 'email' );
		$this->assertSame( 'opt_in', $state );
	}

	public function test_query_guard_blocks_replace_into() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		global $wpdb;
		$table = Orbit_Consent::table_name();

		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			"REPLACE INTO {$table} (user_id, channel, event, program, created_at_utc, row_hash, prev_hash) "
			. "VALUES ({$this->user_id}, 'email', 'opt_out', 'creator-notifications', '2026-04-01 00:00:00', 'forged', '')"
		);
		error_reporting( $prior_level );

		$this->assertSame( 0, (int) $result, 'REPLACE INTO must be no-oped' );

		$state = Orbit_Consent::latest_state( $this->user_id, 'email' );
		$this->assertSame( 'opt_in', $state );
	}

	public function test_query_guard_allows_writes_in_migration_mode() {
		Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		$rows_affected = Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET event = %s WHERE user_id = %d",
						'opt_out',
						$this->user_id
					)
				);
			}
		);

		$this->assertSame( 1, (int) $rows_affected, 'with_migration_mode() must permit the UPDATE' );
	}

	public function test_with_migration_mode_nested_restores_outer_state() {
		// Outer call sets the flag true; inner call also sets it true; on
		// inner exit the prior (true) must be restored, and on outer exit
		// the prior (false) must be restored. This guards the visibility
		// change (protected -> private) and the shutdown-function refactor.
		Orbit_Consent::with_migration_mode(
			static function () {
				Orbit_Consent::with_migration_mode(
					static function () {
						// no-op
					}
				);

				// After the inner returns, the guard must still be relaxed.
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table} WHERE 1 = 0" );
			}
		);

		// After both returns, the guard must be re-engaged: a naked UPDATE
		// is refused.
		global $wpdb;
		$table       = Orbit_Consent::table_name();
		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( "UPDATE {$table} SET event = 'opt_out' WHERE user_id = 0" );
		error_reporting( $prior_level );

		$this->assertSame( 0, (int) $result, 'After nested with_migration_mode returns, the guard must be re-engaged.' );
	}

	public function test_ip_hash_is_deterministic_across_calls() {
		$h1 = Orbit_Consent::hash_ip( '203.0.113.10' );
		$h2 = Orbit_Consent::hash_ip( '203.0.113.10' );
		$h3 = Orbit_Consent::hash_ip( '203.0.113.11' );

		$this->assertSame( $h1, $h2 );
		$this->assertNotSame( $h1, $h3 );
		$this->assertSame( 64, strlen( $h1 ), 'hash_ip returns a 64-char hex SHA-256' );
	}

	public function test_hash_ip_accepts_explicit_salt_parameter() {
		$h_default = Orbit_Consent::hash_ip( '203.0.113.10' );
		$h_custom  = Orbit_Consent::hash_ip( '203.0.113.10', 'a-different-salt' );

		$this->assertNotSame( $h_default, $h_custom, 'Different salts must produce different hashes' );
		$this->assertSame( 64, strlen( $h_custom ) );
	}

	public function test_hash_ip_returns_empty_string_when_no_salt_resolves() {
		$this->assertSame( '', Orbit_Consent::hash_ip( '203.0.113.10', '' ) );
	}

	public function test_record_returns_insert_failed_on_non_duplicate_db_error() {
		// Force $wpdb->insert() to fail with a non-duplicate-key DB error
		// by rewriting the INSERT (via the `query` filter) into deliberately
		// invalid SQL. MySQL responds with a syntax error, $wpdb->insert()
		// returns false, and $wpdb->last_error contains the syntax error
		// (NOT "Duplicate entry") — so record() must return the
		// orbit_consent_insert_failed code, not orbit_consent_chain_conflict.
		global $wpdb;

		$inject_failure = static function ( $query ) {
			if ( false !== stripos( $query, 'orbit_consent_ledger' ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ) {
				return 'THIS IS NOT VALID SQL FOR ORBIT TEST';
			}
			return $query;
		};
		add_filter( 'query', $inject_failure, 5 );

		// Silence the expected MySQL error output.
		$prior_show_errors = $wpdb->show_errors;
		$wpdb->hide_errors();
		$prior_level = error_reporting( error_reporting() & ~E_USER_WARNING & ~E_WARNING );

		$result = Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		error_reporting( $prior_level );
		if ( $prior_show_errors ) {
			$wpdb->show_errors();
		}

		remove_filter( 'query', $inject_failure, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'orbit_consent_insert_failed', $result->get_error_code() );
	}

	public function test_record_returns_salt_missing_when_resolver_returns_empty() {
		// ORBIT_CONSENT_IP_SALT is defined by the test bootstrap and cannot
		// be undefined at runtime. We exercise the salt-missing branch via
		// the orbit_consent_ip_salt_resolved filter, which is the same hook
		// HSM-backed deployments use. Returning '' simulates "neither the
		// constant nor the option is set."
		$blank_salt = static function () {
			return '';
		};
		add_filter( 'orbit_consent_ip_salt_resolved', $blank_salt );

		$result = Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		remove_filter( 'orbit_consent_ip_salt_resolved', $blank_salt );

		$this->assertWPError( $result );
		$this->assertSame( 'orbit_consent_salt_missing', $result->get_error_code() );
	}

	public function test_record_uses_option_when_constant_is_unavailable() {
		// Simulate the production "no constant in wp-config.php, fallback
		// to wp_options" path by overriding resolve_ip_salt() to ignore the
		// constant and return only the option value.
		update_option( 'orbit_consent_ip_salt', 'fallback-salt-value' );

		$prefer_option = static function () {
			return (string) get_option( 'orbit_consent_ip_salt', '' );
		};
		add_filter( 'orbit_consent_ip_salt_resolved', $prefer_option );

		$id = Orbit_Consent::record( $this->user_id, 'email', 'opt_in' );

		remove_filter( 'orbit_consent_ip_salt_resolved', $prefer_option );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_record_payload_does_not_write_cta_snapshot_sha256_column() {
		// TODO 098: the cta_snapshot_sha256 column was removed from both
		// the schema and the record() insert payload. We capture the
		// effective INSERT via the `query` filter (which runs every SQL
		// statement through, INSERT included) and assert it does not
		// reference the dropped column.
		$captured = array();
		$capture  = static function ( $query ) use ( &$captured ) {
			if ( false !== stripos( $query, 'INSERT INTO' ) && false !== stripos( $query, 'orbit_consent_ledger' ) ) {
				$captured[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );

		$id = Orbit_Consent::record( $this->user_id, 'email', 'opt_in', array( 'cta_snapshot' => 'cta-v1' ) );

		remove_filter( 'query', $capture );

		$this->assertIsInt( $id );
		$this->assertNotEmpty( $captured, 'Expected to capture the INSERT against the consent ledger via the query filter' );

		$insert_query = (string) end( $captured );
		$this->assertStringNotContainsString( 'cta_snapshot_sha256', $insert_query, 'INSERT payload must not reference the dropped cta_snapshot_sha256 column' );
		$this->assertStringContainsString( 'cta_snapshot', $insert_query, 'INSERT payload must still write the cta_snapshot column itself' );
	}
}
