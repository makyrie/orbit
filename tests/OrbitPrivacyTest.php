<?php
/**
 * Tests for Orbit_Privacy::cleanup_user_data().
 *
 * @package Orbit
 */

/**
 * Class OrbitPrivacyTest
 *
 * Focused on the GDPR Article 17 erasure path: the per-user usermeta
 * cleanup block must purge every Orbit-owned key, including the
 * `orbit_phone_pending` + `orbit_phone_pending_at` pair added by todo
 * 110. Missing the pending keys leaks an unverified phone number
 * indefinitely after user deletion.
 */
class OrbitPrivacyTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	protected $user_id;

	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
	}

	/**
	 * cleanup_user_data() deletes all Orbit usermeta keys including the
	 * pending-phone pair. This is the GDPR-erasure assertion.
	 */
	public function test_cleanup_user_data_deletes_pending_phone_pair() {
		// Seed every Orbit usermeta key the function is supposed to purge.
		update_user_meta( $this->user_id, 'orbit_phone', '+15551234567' );
		update_user_meta( $this->user_id, 'orbit_phone_verified', 1 );
		update_user_meta( $this->user_id, 'orbit_phone_pending', '+15559876543' );
		update_user_meta( $this->user_id, 'orbit_phone_pending_at', time() - HOUR_IN_SECONDS );
		update_user_meta( $this->user_id, 'orbit_timezone', 'America/Los_Angeles' );
		update_user_meta( $this->user_id, 'orbit_sms_opted_out', 1 );

		// Sanity: present before cleanup.
		$this->assertSame( '+15559876543', get_user_meta( $this->user_id, 'orbit_phone_pending', true ) );

		Orbit_Privacy::cleanup_user_data( $this->user_id );

		// All six keys must be gone.
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone_verified', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone_pending', true ), 'orbit_phone_pending must be deleted on GDPR erasure.' );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone_pending_at', true ), 'orbit_phone_pending_at must be deleted on GDPR erasure.' );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_timezone', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_sms_opted_out', true ) );
	}
}
