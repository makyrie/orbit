<?php
/**
 * Tests for Orbit_Phone_Verify.
 *
 * @package Orbit
 */

/**
 * Class OrbitPhoneVerifyTest
 *
 * Covers the verify-success branch's user_meta side effects, with
 * particular focus on the post-todo-110 invariant that successful
 * verification must delete BOTH `orbit_phone_pending` and its companion
 * `orbit_phone_pending_at` timestamp. Stale pending data drives the
 * misleading "we have this number on file but it's not verified yet"
 * notice on /settings/.
 */
class OrbitPhoneVerifyTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	protected $user_id;

	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PHONE_VERIFICATION );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PHONE_VERIFICATION );

		parent::tear_down();
	}

	/**
	 * Successful verification promotes the candidate phone to
	 * `orbit_phone` + `orbit_phone_verified = 1` AND deletes both
	 * pending-phone keys.
	 *
	 * Without the delete, the settings UI would keep rendering the
	 * "unverified number on file" notice even after the user just
	 * completed verification.
	 */
	public function test_verify_success_clears_pending_meta() {
		global $wpdb;

		$phone = '+15551234567';
		$code  = '123456';

		// Seed both pending keys as if signup or subscribe had run.
		update_user_meta( $this->user_id, 'orbit_phone_pending', $phone );
		update_user_meta( $this->user_id, 'orbit_phone_pending_at', time() - 60 );

		// Seed a fresh verification row (bypasses Twilio in send_code()).
		$table = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $this->user_id,
				'phone'      => $phone,
				'code'       => $code,
				'attempts'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$result = Orbit_Phone_Verify::verify_code( $this->user_id, $code );

		$this->assertTrue( $result, 'verify_code() should succeed for a fresh matching code.' );

		// Promoted to verified meta.
		$this->assertSame( $phone, get_user_meta( $this->user_id, 'orbit_phone', true ) );
		$this->assertSame( '1', (string) get_user_meta( $this->user_id, 'orbit_phone_verified', true ) );

		// And — the todo 110 invariant — both pending keys are gone.
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone_pending', true ), 'orbit_phone_pending must be cleared after successful verification.' );
		$this->assertSame( '', (string) get_user_meta( $this->user_id, 'orbit_phone_pending_at', true ), 'orbit_phone_pending_at must be cleared after successful verification.' );
	}

	/**
	 * Failed verification (wrong code) must NOT touch the pending meta —
	 * the user might be retrying typing the code in, and clearing pending
	 * on failure would erase the candidate they're still trying to verify.
	 */
	public function test_verify_failure_preserves_pending_meta() {
		global $wpdb;

		$phone = '+15551234567';
		$code  = '123456';
		$now   = time();

		update_user_meta( $this->user_id, 'orbit_phone_pending', $phone );
		update_user_meta( $this->user_id, 'orbit_phone_pending_at', $now - 60 );

		$table = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $this->user_id,
				'phone'      => $phone,
				'code'       => $code,
				'attempts'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + 600 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$result = Orbit_Phone_Verify::verify_code( $this->user_id, '000000' );

		$this->assertTrue( is_wp_error( $result ), 'Wrong code should return a WP_Error.' );

		// Pending meta survives the failed attempt.
		$this->assertSame( $phone, get_user_meta( $this->user_id, 'orbit_phone_pending', true ) );
		$this->assertSame( (string) ( $now - 60 ), (string) get_user_meta( $this->user_id, 'orbit_phone_pending_at', true ) );
	}
}
