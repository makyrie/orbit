<?php
/**
 * Tests for Orbit_Features SMS kill-switch.
 *
 * @package Orbit
 */

/**
 * Class OrbitFeaturesTest
 */
class OrbitFeaturesTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		parent::tear_down();
	}

	public function test_sms_disabled_by_default() {
		$this->assertFalse( Orbit_Features::sms_enabled() );
	}

	public function test_option_one_enables_sms() {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, '1' );
		$this->assertTrue( Orbit_Features::sms_enabled() );
	}

	public function test_option_zero_disables_sms() {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, '0' );
		$this->assertFalse( Orbit_Features::sms_enabled() );
	}

	public function test_non_one_string_disables_sms() {
		// Only the literal string '1' counts as enabled, not 'true' or
		// 'yes' or other truthy-looking values — guards against accidental
		// admin-UI writes that store the wrong shape.
		update_option( Orbit_Features::OPTION_SMS_ENABLED, 'true' );
		$this->assertFalse( Orbit_Features::sms_enabled() );

		update_option( Orbit_Features::OPTION_SMS_ENABLED, 'yes' );
		$this->assertFalse( Orbit_Features::sms_enabled() );
	}

	/**
	 * The ORBIT_SMS_ENABLED constant cannot be tested both true and false
	 * in the same PHP process (constants are immutable). We test the
	 * option-only path here; integration of the constant override is
	 * exercised at the runtime layer where it matters — in
	 * resolve_notification_method().
	 */
}
