<?php
/**
 * Transaction-safety canary (todo 118).
 *
 * Tripwire test that proves the InnoDB rollback path actually rolls back
 * side effects of WordPress `user_register` callbacks fired during account
 * provisioning. If a hook on `user_register` (or `wpmu_new_user` on
 * multisite) ever lands that issues DDL — `CREATE TABLE`, `ALTER`, `DROP`,
 * `TRUNCATE`, `REPLACE` (in some configurations) — MySQL triggers an
 * implicit COMMIT, the subsequent ROLLBACK becomes a no-op, and the
 * sentinel row planted by this test will survive. That's the signal.
 *
 * See `AGENTS.md` → "Transactional Boundaries" for the rule, and
 * https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html for the
 * underlying MySQL behavior.
 *
 * @group transactions
 * @package Orbit
 */

class OrbitTransactionSafetyCanaryTest extends WP_UnitTestCase {

	/**
	 * Test IP address used during dispatches (TEST-NET-3, RFC 5737).
	 *
	 * @var string
	 */
	const TEST_IP = '203.0.113.30';

	/**
	 * Saved REMOTE_ADDR to restore in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_remote_addr = null;

	/**
	 * Fully-qualified sentinel table name (with prefix).
	 *
	 * @var string
	 */
	private $sentinel_table;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server, $wpdb;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->saved_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$_SERVER['REMOTE_ADDR']  = self::TEST_IP;

		// Reset rate-limit transients so subsequent runs of this test
		// inside the same suite don't trip the 5/hour signup ceiling.
		delete_transient( 'orbit_rl_' . md5( 'signup|' . self::TEST_IP ) );
		delete_transient( 'orbit_rl_' . md5( 'subscribe|' . self::TEST_IP ) );

		// Sentinel table. WP_UnitTestCase auto-rewrites `CREATE TABLE` to
		// `CREATE TEMPORARY TABLE` via the `query` filter set up in
		// start_transaction(), so this lives only for the duration of the
		// MySQL session. TEMPORARY-table DDL does NOT trigger implicit
		// commit (per the MySQL docs), so creating the sentinel here is
		// safe even though it runs while the wp-unittest outer transaction
		// is open.
		$this->sentinel_table = $wpdb->prefix . 'orbit_canary_sentinel';
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->sentinel_table} (
				id INT PRIMARY KEY AUTO_INCREMENT,
				marker VARCHAR(64) NOT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			)"
		);
	}

	public function tear_down() {
		global $wp_rest_server, $wpdb;

		// Drop the sentinel table explicitly. The wp-unittest TEMPORARY-
		// table rewrite makes this session-local, but being explicit
		// avoids cross-test bleed if the rewrite ever changes.
		if ( ! empty( $this->sentinel_table ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$this->sentinel_table}" );
		}

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		if ( null === $this->saved_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_remote_addr;
		}

		delete_transient( 'orbit_rl_' . md5( 'signup|' . self::TEST_IP ) );
		delete_transient( 'orbit_rl_' . md5( 'subscribe|' . self::TEST_IP ) );

		parent::tear_down();
	}

	/**
	 * Dispatch a POST /orbit/v1/signup request with sane defaults.
	 *
	 * @param array $overrides Field overrides.
	 * @return WP_REST_Response
	 */
	private function dispatch_signup( array $overrides = array() ) {
		$default_init_ms = (int) round( microtime( true ) * 1000 ) - 2000;

		$params = array_merge(
			array(
				'display_name'    => 'Canary User',
				'email'           => 'canary-' . wp_rand( 100000, 999999 ) . '@example.test',
				'orbit_url'       => '',
				'orbit_form_init' => $default_init_ms,
				'consent_email'   => true,
			),
			$overrides
		);

		$request = new WP_REST_Request( 'POST', '/orbit/v1/signup' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Count sentinel rows.
	 *
	 * @return int
	 */
	private function count_sentinel_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->sentinel_table}" );
	}

	// ---------------------------------------------------------------- //
	// Canary
	// ---------------------------------------------------------------- //

	/**
	 * Tripwire: a `user_register` hook that issues a DML INSERT into a
	 * sentinel table must be rolled back when the surrounding signup
	 * transaction fails. If the sentinel row survives, MySQL committed
	 * mid-transaction — almost certainly because something in the hook
	 * chain issued DDL.
	 */
	public function test_user_register_dml_rolls_back_when_signup_transaction_fails() {
		$sentinel_table = $this->sentinel_table;

		// Sentinel: a DML-only `user_register` hook. Mirrors what a
		// well-behaved third-party plugin might do — write a row to its
		// own audit table from the hook. The transaction must roll this
		// back when a later step fails.
		$sentinel = function ( $user_id ) use ( $sentinel_table ) {
			global $wpdb;
			$wpdb->insert(
				$sentinel_table,
				array( 'marker' => 'user_register:' . (int) $user_id ),
				array( '%s' )
			);
		};
		add_action( 'user_register', $sentinel );

		// Force consent recording to fail AFTER wp_insert_user has run
		// (and therefore after the sentinel hook has fired) but BEFORE
		// COMMIT. Returning '' from this filter trips the
		// orbit_consent_salt_missing branch in Orbit_Consent::record(),
		// which the signup handler converts into a thrown RuntimeException
		// → ROLLBACK. Same mechanism HSM-backed deployments use to
		// override salt resolution.
		$blank_salt = static function () {
			return '';
		};
		add_filter( 'orbit_consent_ip_salt_resolved', $blank_salt );

		try {
			$response = $this->dispatch_signup(
				array(
					'display_name' => 'Canary User',
					'email'        => 'canary-' . wp_rand( 100000, 999999 ) . '@example.test',
				)
			);
		} finally {
			remove_filter( 'orbit_consent_ip_salt_resolved', $blank_salt );
			remove_action( 'user_register', $sentinel );
		}

		// Sanity: the handler must have hit the rollback path. If this
		// fails the test is misconfigured — the consent step didn't fail
		// the way we forced it to.
		//
		// The handler preserves the inner WP_Error code from
		// Orbit_Rolled_Back_Exception, so the code we see here is the
		// one Orbit_Consent::record() returned (`orbit_consent_salt_missing`)
		// rather than a generic `signup_failed`. Status is always 500
		// for the rolled-back branch (other than the email-race 409).
		$this->assertSame(
			500,
			$response->get_status(),
			'Expected signup to fail with 500 once the consent salt was forced empty; check forced-failure mechanism.'
		);
		$this->assertSame(
			'orbit_consent_salt_missing',
			$response->as_error()->get_error_code(),
			'Expected the consent-salt-missing code to surface from the rollback path.'
		);

		// The actual canary assertion.
		$rows = $this->count_sentinel_rows();
		$this->assertSame(
			0,
			$rows,
			'Sentinel row from a user_register hook survived ROLLBACK. '
			. 'This means an InnoDB implicit COMMIT happened mid-transaction — '
			. 'almost certainly because some hook in the user_register / '
			. 'wpmu_new_user chain issued a DDL statement (CREATE TABLE, ALTER, '
			. 'DROP, TRUNCATE, etc.). See AGENTS.md → "Transactional Boundaries" '
			. 'and https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html.'
		);
	}

	/**
	 * Companion: when the salt is fine, the happy path commits and the
	 * sentinel row persists. Proves the test rig actually exercises the
	 * commit branch — without this we couldn't tell a passing rollback
	 * assertion from a sentinel that never inserted in the first place.
	 */
	public function test_user_register_dml_persists_on_signup_commit() {
		$sentinel_table = $this->sentinel_table;

		$sentinel = function ( $user_id ) use ( $sentinel_table ) {
			global $wpdb;
			$wpdb->insert(
				$sentinel_table,
				array( 'marker' => 'user_register:' . (int) $user_id ),
				array( '%s' )
			);
		};
		add_action( 'user_register', $sentinel );

		try {
			$response = $this->dispatch_signup();
		} finally {
			remove_action( 'user_register', $sentinel );
		}

		$this->assertSame( 201, $response->get_status(), 'Expected happy-path signup to return 201.' );
		$this->assertGreaterThan(
			0,
			$this->count_sentinel_rows(),
			'Sentinel row from user_register hook is missing after COMMIT — '
			. 'the test rig may be eating writes; the rollback assertion in '
			. 'test_user_register_dml_rolls_back_when_signup_transaction_fails '
			. 'cannot be trusted until this passes.'
		);
	}
}
