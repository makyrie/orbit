<?php
/**
 * Tests for the WP-CLI `wp orbit signup create` subcommand
 * (Orbit_CLI_Signup).
 *
 * Same harness shape as OrbitCliSubscriptionTest — sidesteps the WP-CLI
 * runner by stubbing `WP_CLI` + `WP_CLI_Command` before loading the CLI
 * file, then instantiates Orbit_CLI_Signup and calls `create()` directly
 * with constructed `$args` / `$assoc_args` arrays.
 *
 * Covers happy path (user created, consent rows stamped, provenance
 * recorded), the optional --consent_sms + --phone combination, and the
 * validation errors at parity with POST /orbit/v1/signup.
 *
 * @package Orbit
 */

// Stub WP_CLI BEFORE the CLI file is loaded. The CLI file is guarded by
// `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }` so without these
// declarations the Orbit_CLI / Orbit_CLI_Signup classes never exist in
// the test runtime.
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
	 * test can catch it; `success()`, `log()`, and `line()` accumulate
	 * into a static buffer the test can inspect.
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

// Now load the CLI base + signup command. Guard against duplicate
// includes if any other test in the run already pulled them in.
require_once dirname( __DIR__ ) . '/cli/class-orbit-cli.php';
require_once dirname( __DIR__ ) . '/cli/class-orbit-cli-signup.php';

/**
 * Tests covering Orbit_CLI_Signup::create().
 */
class OrbitCliSignupTest extends WP_UnitTestCase {

	/**
	 * The command instance under test.
	 *
	 * @var Orbit_CLI_Signup
	 */
	private $command;

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();

		// Reset the ledger between tests so chains don't pile up — the
		// query guard refuses DELETE outside the sanctioned wrapper.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		$this->command = new Orbit_CLI_Signup();
	}

	/**
	 * Build the assoc_args array with sensible defaults.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function assoc_args( array $overrides = array() ) {
		return array_merge(
			array(
				'display_name'  => 'CLI Test User',
				'email'         => 'cli-signup-' . wp_rand( 100000, 999999 ) . '@example.test',
				'consent_email' => true,
				'format'        => 'json',
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------- //
	// Happy path
	// ---------------------------------------------------------------- //

	public function test_create_minimum_inputs_creates_user_with_email_consent() {
		$email = 'happy-' . wp_rand( 100000, 999999 ) . '@example.test';

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'display_name' => 'Happy Path',
					'email'        => $email,
				)
			)
		);

		// User exists with subscriber role + correct display name.
		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertSame( 'Happy Path', $user->display_name );
		$this->assertContains( 'subscriber', (array) $user->roles );

		// Single email consent row stamped, no SMS row.
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user->ID, 'email' ) );
		$this->assertNull( Orbit_Consent::latest_state( $user->ID, 'sms' ) );

		// Timezone meta stamped (parity with REST handler).
		$this->assertSame( wp_timezone_string(), get_user_meta( $user->ID, 'orbit_timezone', true ) );
	}

	public function test_create_with_phone_and_consent_sms_stamps_both_rows_and_stashes_pending_phone() {
		$email = 'sms-' . wp_rand( 100000, 999999 ) . '@example.test';

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'display_name' => 'SMS Path',
					'email'        => $email,
					'phone'        => '+12025550199',
					'consent_sms'  => true,
				)
			)
		);

		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( 'WP_User', $user );

		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user->ID, 'email' ) );
		$this->assertSame( 'opt_in', Orbit_Consent::latest_state( $user->ID, 'sms' ) );

		// Pending slot, not verified — promotion happens in Orbit_Phone_Verify.
		$this->assertSame( '+12025550199', get_user_meta( $user->ID, 'orbit_phone_pending', true ) );
		$this->assertSame( '', get_user_meta( $user->ID, 'orbit_phone', true ) );
	}

	public function test_create_consent_row_records_cli_provenance() {
		$email = 'prov-' . wp_rand( 100000, 999999 ) . '@example.test';

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'display_name' => 'Provenance Path',
					'email'        => $email,
				)
			)
		);

		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( 'WP_User', $user );

		global $wpdb;
		$table = $wpdb->base_prefix . ORBIT_TABLE_CONSENT_LEDGER;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source, user_agent FROM {$table} WHERE user_id = %d AND channel = 'email' ORDER BY id DESC LIMIT 1",
				$user->ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'cli', $row->source );
		$this->assertSame( 'wp orbit signup create', $row->user_agent );
	}

	// ---------------------------------------------------------------- //
	// Validation errors at parity with POST /orbit/v1/signup
	// ---------------------------------------------------------------- //

	public function test_missing_consent_email_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/consent_required/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'consent_email' => false,
				)
			)
		);
	}

	public function test_invalid_email_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/invalid_email/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'email' => 'not-an-email',
				)
			)
		);
	}

	public function test_empty_display_name_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/invalid_name/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'display_name' => '   ',
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

	public function test_consent_sms_without_phone_errors_out() {
		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/consent_sms_without_phone/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'consent_sms' => true,
				)
			)
		);
	}

	public function test_existing_email_errors_out_with_login_required() {
		$email = 'dup-' . wp_rand( 100000, 999999 ) . '@example.test';
		$this->factory->user->create(
			array(
				'user_email' => $email,
			)
		);

		$this->expectException( Orbit_Test_CLI_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/login_required/' );

		$this->command->create(
			array(),
			$this->assoc_args(
				array(
					'email' => $email,
				)
			)
		);
	}

	// ---------------------------------------------------------------- //
	// Welcome email default-off behavior
	// ---------------------------------------------------------------- //

	/**
	 * Without --send-welcome-email the synchronous mail path must not
	 * fire — that's the whole point of the flag (fixture/seed use).
	 */
	public function test_create_does_not_send_welcome_email_by_default() {
		$filter_fired = false;
		$capture      = function ( $email ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $email;
		};
		add_filter( 'wp_new_user_notification_email', $capture, 10, 1 );

		try {
			$this->command->create(
				array(),
				$this->assoc_args(
					array(
						'email' => 'no-mail-' . wp_rand( 100000, 999999 ) . '@example.test',
					)
				)
			);

			$this->assertFalse(
				$filter_fired,
				'wp_send_new_user_notifications fired without --send-welcome-email; the welcome email should default off for CLI fixture use.'
			);
		} finally {
			remove_filter( 'wp_new_user_notification_email', $capture, 10 );
		}
	}
}
