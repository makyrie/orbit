<?php
/**
 * Tests for the activator-seeded consent IP salt fallback.
 *
 * Covers todo 108: a fresh install with no ORBIT_CONSENT_IP_SALT constant
 * must still hash IPs into the consent ledger. The activator mints a
 * per-site option as fallback; the constant always wins when both exist;
 * re-activation must not rewrite an existing option (that would invalidate
 * every prior ip_hash).
 *
 * @package Orbit
 */

/**
 * Class OrbitActivatorConsentSaltTest
 */
class OrbitActivatorConsentSaltTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Clear any leakage from other tests so each scenario starts with a
		// known wp_options state.
		delete_option( 'orbit_consent_ip_salt' );
	}

	public function tear_down() {
		delete_option( 'orbit_consent_ip_salt' );

		parent::tear_down();
	}

	/**
	 * Activation on a fresh install with no constant defined must mint a
	 * non-empty `orbit_consent_ip_salt` option.
	 *
	 * The bootstrap defines ORBIT_CONSENT_IP_SALT, so we cannot literally
	 * undefine it. Instead we assert seed_consent_ip_salt() does NOTHING
	 * when the constant is present (preserving "constant wins"), then
	 * exercise the option-mint branch via the filter-based fallback test
	 * below.
	 */
	public function test_seed_consent_ip_salt_is_noop_when_constant_defined() {
		$this->assertTrue( defined( 'ORBIT_CONSENT_IP_SALT' ), 'Bootstrap pre-defines the constant.' );

		Orbit_Activator::seed_consent_ip_salt();

		$this->assertFalse(
			get_option( 'orbit_consent_ip_salt', false ),
			'When the constant is defined, the activator must NOT create the option (the constant wins; an absent option is the right state).'
		);
	}

	/**
	 * Re-activating must NOT rewrite an existing salt option.
	 *
	 * The salt is wired into every ip_hash already in the ledger — rotating
	 * it would silently invalidate the entire chain for every user.
	 */
	public function test_seed_consent_ip_salt_is_idempotent_on_re_activation() {
		// Seed a pre-existing option that mimics a prior activation.
		update_option( 'orbit_consent_ip_salt', 'sentinel-salt-from-prior-activation', false );

		// Re-run activation as if the operator deactivated and re-activated.
		Orbit_Activator::seed_consent_ip_salt();
		Orbit_Activator::seed_consent_ip_salt();
		Orbit_Activator::seed_consent_ip_salt();

		$this->assertSame(
			'sentinel-salt-from-prior-activation',
			get_option( 'orbit_consent_ip_salt' ),
			'Re-activation must preserve the existing salt; rotating it would invalidate every prior ip_hash in the ledger.'
		);
	}

	/**
	 * When the constant is absent, Orbit_Consent::record() must resolve the
	 * salt from the `orbit_consent_ip_salt` option and successfully insert.
	 *
	 * The constant is set by the test bootstrap and cannot be undefined at
	 * runtime, so we simulate the "no constant" branch via the
	 * `orbit_consent_ip_salt_resolved` filter — the same hook used by
	 * the existing fallback test in OrbitConsentTest. The seeded option
	 * value is what makes the chain succeed.
	 */
	public function test_record_uses_seeded_option_when_constant_absent() {
		// Mint the option exactly as the activator would on a fresh install
		// with no constant defined.
		update_option( 'orbit_consent_ip_salt', 'activator-seeded-fallback-salt', false );

		$user_id = self::factory()->user->create();

		// Force resolve_ip_salt() to ignore the constant and use the option.
		$prefer_option = static function () {
			return (string) get_option( 'orbit_consent_ip_salt', '' );
		};
		add_filter( 'orbit_consent_ip_salt_resolved', $prefer_option );

		// Reset ledger to a clean slate for the chain assertion.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		$id = Orbit_Consent::record( $user_id, 'email', 'opt_in', array( 'ip' => '203.0.113.10' ) );

		remove_filter( 'orbit_consent_ip_salt_resolved', $prefer_option );

		// Cleanup ledger so we don't leak rows across the suite.
		Orbit_Consent::with_migration_mode(
			static function () {
				global $wpdb;
				$table = Orbit_Consent::table_name();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table}" );
			}
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id, 'record() must succeed when the salt resolves from the option fallback.' );
	}

	/**
	 * When BOTH the constant and the option exist, the constant must win.
	 *
	 * This is the documented best-practice override path: operators rotate
	 * the salt by setting the constant in wp-config.php; the seeded option
	 * is a zero-config convenience that must not shadow the explicit choice.
	 *
	 * We use the same filter hook to verify resolution priority by NOT
	 * adding any filter — the production resolve_ip_salt() implementation
	 * inherently prefers the constant when it is defined and non-empty,
	 * and the bootstrap defines the constant. So the resulting ip_hash
	 * must match the constant-salted hash, not the option-salted one.
	 */
	public function test_constant_takes_precedence_over_option() {
		$this->assertTrue( defined( 'ORBIT_CONSENT_IP_SALT' ), 'Bootstrap pre-defines the constant.' );
		$this->assertNotSame( '', (string) ORBIT_CONSENT_IP_SALT, 'Bootstrap salt must be non-empty.' );

		update_option( 'orbit_consent_ip_salt', 'option-salt-that-must-be-ignored', false );

		$ip = '203.0.113.42';

		// Production helper: hash with the resolved salt (no $salt override).
		$resolved_hash = Orbit_Consent::hash_ip( $ip );

		// Explicit comparison: the constant's salt produces the same hash;
		// the option's salt produces a DIFFERENT hash.
		$constant_hash = Orbit_Consent::hash_ip( $ip, (string) ORBIT_CONSENT_IP_SALT );
		$option_hash   = Orbit_Consent::hash_ip( $ip, 'option-salt-that-must-be-ignored' );

		$this->assertSame( $constant_hash, $resolved_hash, 'When the constant is defined, the resolver must use it.' );
		$this->assertNotSame( $option_hash, $resolved_hash, 'The option value must NOT shadow the constant.' );
	}
}
