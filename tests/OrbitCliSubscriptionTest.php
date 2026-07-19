<?php
/**
 * Tests for the WP-CLI subscription create subcommand (Orbit_CLI_Subscription).
 *
 * The CLI dispatch layer is too thin to be worth a full WP_CLI integration
 * harness, so this test sidesteps the runner by:
 *   1. Stubbing `WP_CLI` + `WP_CLI_Command` BEFORE requiring the CLI file so
 *      the `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }` guard at
 *      the top of cli/class-orbit-cli-subscription.php falls through to the
 *      class declaration.
 *   2. Instantiating Orbit_CLI_Subscription and calling `create()` directly
 *      with constructed `$args` / `$assoc_args` arrays — the same payload
 *      shape WP-CLI would synthesize.
 *
 * Asserts the new --phone, --consent_email, --consent_sms flags behave at
 * parity with POST /orbit/v1/subscribe: pending phone stashed, consent rows
 * recorded with source=cli, error codes match.
 *
 * @package Orbit
 */

// Stub WP_CLI BEFORE the CLI file is loaded. The CLI file is guarded by
// `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }` so without these
// declarations the Orbit_CLI / Orbit_CLI_Subscription classes never exist
// in the test runtime.
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Bare WP_CLI_Command stub so Orbit_CLI can extend it under PHPUnit.
	 * Real WP-CLI provides a richer base class; for unit tests we only need
	 * the symbol to exist.
	 */
	class WP_CLI_Command {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

if ( ! class_exists( 'Orbit_Test_CLI_Exit_Exception' ) ) {
	/**
	 * Test-side analog of WP_CLI's internal ExitException. Lets us assert
	 * on the message a CLI command would have exited with, without the test
	 * process itself calling `exit()`.
	 */
	class Orbit_Test_CLI_Exit_Exception extends RuntimeException {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP_CLI stub. `error()` throws (instead of exit-ing) so the
	 * test can catch it; `success()` and `log()` accumulate into a static
	 * buffer the test can inspect.
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

// Now load the CLI base + subscription command. Guard against duplicate
// includes if any other test in the run already pulled them in.
require_once dirname( __DIR__ ) . '/cli/class-orbit-cli.php';
require_once dirname( __DIR__ ) . '/cli/class-orbit-cli-subscription.php';

/**
 * Tests covering the --phone / --consent_email / --consent_sms parity work.
 */
class OrbitCliSubscriptionTest extends WP_UnitTestCase {

	/**
	 * The command instance under test.
	 *
	 * @var Orbit_CLI_Subscription
	 */
	private $command;

	/**
	 * Poster user ID owning the profile under test.
	 *
	 * @var int
	 */
	private $poster_id;

	/**
	 * Subscriber user (the one who gets subscribed in each test).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Profile row created per test.
	 *
	 * @var object
	 */
	private $profile;

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();

		// Same cleanup pattern OrbitRestSubscriptionTest uses — WP rolls
		// back wp_* tables between tests but not the orbit_* prefix, so
		// stale rows from prior runs collide with the factory's reused IDs.
		// The consent ledger is also persistent (network-scoped under
		// $wpdb->base_prefix) AND guarded against destructive writes, so
		// we have to wrap the TRUNCATE in with_migration_mode().
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS );
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ORBIT_TABLE_PROFILES );
		Orbit_Consent::with_migration_mode(
			static function () use ( $wpdb ) {
				$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->base_prefix . ORBIT_TABLE_CONSENT_LEDGER );
			}
		);

		$this->poster_id     = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$profile_id    = Orbit_Profile::create(
			array(
				'user_id'          => $this->poster_id,
				'slug'             => 'cli-poster-' . wp_rand( 100000, 999999 ),
				'display_name'     => 'CLI Test Poster',
				'require_approval' => false,
			)
		);
		$this->profile = Orbit_Profile::get( $profile_id );

		$this->command = new Orbit_CLI_Subscription();
	}

	public function tear_down() {
		global $wpdb;

		if ( $this->profile ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_SUBSCRIPTIONS . ' WHERE profile_id = %d',
					$this->profile->id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . ' WHERE id = %d',
					$this->profile->id
				)
			);
		}

		parent::tear_down();
	}

	/**
	 * Convenience: build the assoc_args array with sensible defaults.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function assoc_args( array $overrides = array() ) {
		return array_merge(
			array(
				'user_id'    => $this->subscriber_id,
				'profile_id' => $this->profile->id,
				'format'     => 'json',
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------- //
	// Backwards compatibility — no new flags should look like today.
	// ---------------------------------------------------------------- //

	public function test_create_without_consent_flags_writes_no_ledger_rows() {
		$this->command->create( array(), $this->assoc_args() );

		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'sms' ) );
		$this->assertSame( '', get_user_meta( $this->subscriber_id, 'orbit_phone_pending', true ) );
	}

	// ---------------------------------------------------------------- //
	// Consent capture parity with POST /orbit/v1/subscribe
	// ---------------------------------------------------------------- //

	public function test_create_with_consent_email_stamps_email_ledger_row() {
		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'consent_email' => 'true',
				)
			)
		);

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->subscriber_id, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'sms' ) );
	}

	public function test_create_with_phone_and_consent_sms_stamps_both_rows_and_stashes_phone() {
		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'phone'         => '+12025550199',
					'consent_email' => 'true',
					'consent_sms'   => 'true',
				)
			)
		);

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->subscriber_id, 'email' ) );
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->subscriber_id, 'sms' ) );

		// Pending slot, not the verified one — promotion happens in
		// Orbit_Phone_Verify.
		$this->assertSame( '+12025550199', get_user_meta( $this->subscriber_id, 'orbit_phone_pending', true ) );
		$this->assertSame( '', get_user_meta( $this->subscriber_id, 'orbit_phone', true ) );

		// Companion timestamp present for the cleanup cron.
		$stashed_at = get_user_meta( $this->subscriber_id, 'orbit_phone_pending_at', true );
		$this->assertNotEmpty( $stashed_at );
		$this->assertGreaterThan( 0, (int) $stashed_at );
	}

	public function test_create_with_phone_but_no_sms_consent_stashes_phone_only() {
		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'phone'         => '+12025550100',
					'consent_email' => 'true',
				)
			)
		);

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $this->subscriber_id, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'sms' ) );
		$this->assertSame( '+12025550100', get_user_meta( $this->subscriber_id, 'orbit_phone_pending', true ) );
	}

	public function test_cli_consent_row_records_source_and_user_agent_provenance() {
		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'consent_email' => 'true',
				)
			)
		);

		global $wpdb;
		$table = $wpdb->base_prefix . ORBIT_TABLE_CONSENT_LEDGER;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source, user_agent FROM {$table} WHERE user_id = %d AND channel = 'email' ORDER BY id DESC LIMIT 1",
				$this->subscriber_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'cli', $row->source );
		$this->assertSame( 'wp-cli', $row->user_agent );
	}

	// ---------------------------------------------------------------- //
	// Validation errors
	// ---------------------------------------------------------------- //

	public function test_consent_sms_without_phone_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/consent_sms_without_phone/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'consent_sms' => 'true',
				)
			)
		);
	}

	public function test_invalid_phone_format_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/E\\.164/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'phone' => '555-555-1234',
				)
			)
		);
	}

	public function test_consent_sms_without_phone_leaves_database_clean() {
		try {
			$this->command->create(
				array(),
				$this->assoc_args(
					array(
						'consent_sms' => 'true',
					)
				)
			);
			$this->fail( 'Expected CLI error for consent_sms without phone.' );
		} catch ( Orbit_Test_CLI_Exit_Exception $e ) {
			// Expected.
			unset( $e );
		}

		// Validation runs BEFORE the transaction, so nothing should have
		// landed in any of the affected tables / metas.
		$subscriptions = Orbit_Subscription::list(
			array(
				'user_id'    => $this->subscriber_id,
				'profile_id' => $this->profile->id,
			)
		);
		$this->assertEmpty( $subscriptions );
		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $this->subscriber_id, 'sms' ) );
	}
}
