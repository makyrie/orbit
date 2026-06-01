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

	public function test_ip_hash_is_deterministic_across_calls() {
		$h1 = Orbit_Consent::hash_ip( '203.0.113.10' );
		$h2 = Orbit_Consent::hash_ip( '203.0.113.10' );
		$h3 = Orbit_Consent::hash_ip( '203.0.113.11' );

		$this->assertSame( $h1, $h2 );
		$this->assertNotSame( $h1, $h3 );
		$this->assertSame( 64, strlen( $h1 ), 'hash_ip returns a 64-char hex SHA-256' );
	}
}
