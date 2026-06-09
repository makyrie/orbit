<?php
/**
 * Tests for the WP-CLI `wp orbit consent` subcommands (Orbit_CLI_Consent).
 *
 * Same harness as OrbitCliSubscriptionTest / OrbitCliSignupTest — stubs
 * `WP_CLI` + `WP_CLI_Command` before loading the CLI file and exercises
 * the subcommands by calling them directly.
 *
 * Covers `verify` on a clean seeded ledger (PASS) and on a tampered row
 * (FAIL with non-zero exit). `log` and `state` are smoke-tested for
 * basic dispatch — full output assertions are left to manual review
 * since they exercise the standard WP_CLI\Formatter path.
 *
 * @package Orbit
 */

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Bare WP_CLI_Command stub so Orbit_CLI can extend it under PHPUnit.
	 */
	class WP_CLI_Command {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

if ( ! class_exists( 'Orbit_Test_CLI_Exit_Exception' ) ) {
	/**
	 * Test-side analog of WP_CLI's internal ExitException.
	 */
	class Orbit_Test_CLI_Exit_Exception extends RuntimeException {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP_CLI stub. `error()` throws so the test can catch it;
	 * `success()`, `log()`, and `line()` accumulate.
	 */
	class WP_CLI { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		public static $messages = array();

		public static function error( $message ) {
			throw new Orbit_Test_CLI_Exit_Exception( (string) $message );
		}

		public static function success( $message ) {
			self::$messages[] = array( 'level' => 'success', 'text' => (string) $message );
		}

		public static function log( $message ) {
			self::$messages[] = array( 'level' => 'log', 'text' => (string) $message );
		}

		public static function line( $message = '' ) {
			self::$messages[] = array( 'level' => 'line', 'text' => (string) $message );
		}

		public static function reset() {
			self::$messages = array();
		}
	}
}

require_once dirname( __DIR__ ) . '/cli/class-orbit-cli.php';
require_once dirname( __DIR__ ) . '/cli/class-orbit-cli-consent.php';

/**
 * Tests covering Orbit_CLI_Consent::log(), verify(), and state().
 */
class OrbitCliConsentTest extends WP_UnitTestCase {

	/**
	 * The command instance under test.
	 *
	 * @var Orbit_CLI_Consent
	 */
	private $command;

	/**
	 * User ID seeded with consent rows.
	 *
	 * @var int
	 */
	private $user_id;

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();

		// Reset the ledger so each test starts fresh.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		$this->user_id = self::factory()->user->create();
		$this->command = new Orbit_CLI_Consent();
	}

	public function tear_down() {
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		parent::tear_down();
	}

	// ---------------------------------------------------------------- //
	// verify
	// ---------------------------------------------------------------- //

	public function test_verify_passes_on_intact_chain() {
		Orbit_Consent::record(
			$this->user_id,
			'email',
			'opt_in',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);
		Orbit_Consent::record(
			$this->user_id,
			'email',
			'opt_out',
			array( 'source' => 'test', 'created_at_utc' => '2026-02-01 00:00:00' )
		);

		// Should not throw.
		$this->command->verify(
			array(),
			array(
				'user_id' => $this->user_id,
				'format'  => 'json',
			)
		);

		// Last message should be the intact-chain success line.
		$messages = WP_CLI::$messages;
		$this->assertNotEmpty( $messages );
		$last = end( $messages );
		$this->assertSame( 'success', $last['level'] );
		$this->assertStringContainsString( 'intact', $last['text'] );
	}

	public function test_verify_exits_nonzero_on_tampered_row() {
		Orbit_Consent::record(
			$this->user_id,
			'email',
			'opt_in',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);

		// Tamper directly with the row's event under the migration-mode
		// bypass — the query guard would otherwise refuse the UPDATE.
		Orbit_Consent::with_migration_mode(
			function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET event = 'opt_out' WHERE user_id = %d AND channel = 'email'",
						$this->user_id
					)
				);
			}
		);

		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/broken/' );

		$this->command->verify(
			array(),
			array(
				'user_id' => $this->user_id,
				'format'  => 'json',
			)
		);
	}

	public function test_verify_with_no_rows_emits_success_message() {
		$this->command->verify(
			array(),
			array(
				'user_id' => $this->user_id,
				'format'  => 'json',
			)
		);

		// Either the no-rows shortcut or the all-PASS shortcut is fine —
		// both emit a single success-level message indicating no break.
		$success = array_filter(
			WP_CLI::$messages,
			static fn( $m ) => 'success' === $m['level']
		);
		$this->assertNotEmpty( $success );
	}

	// ---------------------------------------------------------------- //
	// state
	// ---------------------------------------------------------------- //

	public function test_state_reports_per_channel_latest_event() {
		Orbit_Consent::record(
			$this->user_id,
			'email',
			'opt_in',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);
		Orbit_Consent::record(
			$this->user_id,
			'sms',
			'opt_out',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);

		// Should not throw — pure output. Sanity: the latest_state
		// primitive itself returns the expected values, which is what
		// the CLI wraps.
		$this->command->state(
			array(),
			array(
				'user_id' => $this->user_id,
				'format'  => 'json',
			)
		);

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->user_id, 'email' ) );
		$this->assertSame( 'opt_out', Orbit_Consent::latest_state( $this->user_id, 'sms' ) );
	}

	public function test_state_requires_user_id() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/user_id/' );

		$this->command->state( array(), array( 'format' => 'json' ) );
	}

	public function test_state_rejects_unknown_user() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/not found/' );

		$this->command->state(
			array(),
			array(
				'user_id' => 999999,
				'format'  => 'json',
			)
		);
	}

	// ---------------------------------------------------------------- //
	// log
	// ---------------------------------------------------------------- //

	public function test_log_runs_with_no_filters_and_no_rows() {
		// Should not throw — empty result is a normal state.
		$this->command->log(
			array(),
			array(
				'format' => 'json',
			)
		);

		// At least one line of output (the empty-array JSON or the
		// "No items found." marker).
		$this->assertNotEmpty( WP_CLI::$messages );
	}

	public function test_log_filters_by_user_and_channel() {
		Orbit_Consent::record(
			$this->user_id,
			'email',
			'opt_in',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);
		Orbit_Consent::record(
			$this->user_id,
			'sms',
			'opt_in',
			array( 'source' => 'test', 'created_at_utc' => '2026-01-01 00:00:00' )
		);

		// Should not throw. We don't introspect the rendered table —
		// the Formatter path is exercised here mostly as a smoke check
		// that the SQL composition + prepare wiring is correct.
		$this->command->log(
			array(),
			array(
				'user_id' => $this->user_id,
				'channel' => 'email',
				'format'  => 'json',
			)
		);

		$this->assertNotEmpty( WP_CLI::$messages );
	}

	public function test_log_rejects_unknown_channel() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/Invalid --channel/' );

		$this->command->log(
			array(),
			array(
				'channel' => 'fax',
				'format'  => 'json',
			)
		);
	}
}
