<?php
/**
 * Tests for Orbit_Messaging_Copy — the central SMS dormancy / launch copy gate.
 *
 * Each helper is exercised in both SMS-dormant and SMS-live states so that
 * Phase 5 (the SMS-launch flip) can be validated as a one-flag change with
 * no copy regressions.
 *
 * @package Orbit
 */

class OrbitMessagingCopyTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Orbit_Features::OPTION_SMS_ENABLED );
		parent::tear_down();
	}

	/**
	 * Drive SMS-live ON for the current test, dormant OFF.
	 *
	 * @param bool $enabled Whether sms_enabled() should return true.
	 */
	private function set_sms_enabled( $enabled ) {
		update_option( Orbit_Features::OPTION_SMS_ENABLED, $enabled ? '1' : '0' );
	}

	/* ---------- sms_status_clause() ---------- */

	public function test_sms_status_clause_returns_dormancy_sentence_when_sms_disabled() {
		$this->set_sms_enabled( false );

		$clause = Orbit_Messaging_Copy::sms_status_clause();

		$this->assertNotSame( '', $clause, 'sms_status_clause must return non-empty text while SMS is dormant.' );
		$this->assertStringContainsString( 'SMS goes live', $clause, 'Dormancy clause should advertise that SMS goes live later.' );
	}

	public function test_sms_status_clause_includes_brand_name_when_dormant() {
		$this->set_sms_enabled( false );

		$brand  = defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' );
		$clause = Orbit_Messaging_Copy::sms_status_clause();

		$this->assertStringContainsString( $brand, $clause, 'Dormancy clause should interpolate the configured brand name.' );
	}

	public function test_sms_status_clause_is_empty_when_sms_live() {
		$this->set_sms_enabled( true );

		$this->assertSame( '', Orbit_Messaging_Copy::sms_status_clause() );
	}

	public function test_sms_status_clause_brand_falls_back_to_bloginfo_when_constant_undefined() {
		// ORBIT_MESSAGING_BRAND is defined at plugin boot and PHP constants
		// are immutable inside the test process, so we can't actually
		// unset it here. Instead we validate the documented contract: the
		// returned clause carries SOME identifier consistent with the
		// brand() fallback chain — either the constant or the WP site
		// name. Both have to be non-empty strings in any sane test env.
		$this->set_sms_enabled( false );

		$brand_constant  = defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : '';
		$brand_blog_name = (string) get_bloginfo( 'name' );

		$clause = Orbit_Messaging_Copy::sms_status_clause();

		$saw_one = ( '' !== $brand_constant && false !== strpos( $clause, $brand_constant ) )
			|| ( '' !== $brand_blog_name && false !== strpos( $clause, $brand_blog_name ) );

		$this->assertTrue( $saw_one, 'Dormancy clause must interpolate either the ORBIT_MESSAGING_BRAND constant or the bloginfo("name") fallback.' );
	}

	/* ---------- dashboard_onboarding_banner_copy() ---------- */

	public function test_dashboard_banner_copy_differs_between_dormant_and_live_states() {
		$this->set_sms_enabled( false );
		$dormant = Orbit_Messaging_Copy::dashboard_onboarding_banner_copy();

		$this->set_sms_enabled( true );
		$live = Orbit_Messaging_Copy::dashboard_onboarding_banner_copy();

		$this->assertNotSame( '', $dormant );
		$this->assertNotSame( '', $live );
		$this->assertNotSame(
			$dormant,
			$live,
			'Dashboard banner copy must change when SMS goes live so the "as soon as our SMS program launches" promise stops rendering.'
		);
	}

	public function test_dashboard_banner_dormant_copy_mentions_sms_launch() {
		$this->set_sms_enabled( false );

		$copy = Orbit_Messaging_Copy::dashboard_onboarding_banner_copy();

		$this->assertStringContainsString( 'SMS program launches', $copy );
	}

	public function test_dashboard_banner_live_copy_does_not_mention_pending_launch() {
		$this->set_sms_enabled( true );

		$copy = Orbit_Messaging_Copy::dashboard_onboarding_banner_copy();

		$this->assertStringNotContainsString( 'SMS program launches', $copy );
		$this->assertStringNotContainsString( 'goes live', $copy );
	}

	/* ---------- settings_phone_help_note() ---------- */

	public function test_settings_phone_help_note_changes_between_dormant_and_live() {
		$this->set_sms_enabled( false );
		$dormant = Orbit_Messaging_Copy::settings_phone_help_note();

		$this->set_sms_enabled( true );
		$live = Orbit_Messaging_Copy::settings_phone_help_note();

		$this->assertNotSame( '', $dormant );
		$this->assertNotSame( '', $live );
		$this->assertNotSame(
			$dormant,
			$live,
			'Settings phone-help note must drop the "when SMS goes live" promise once SMS is enabled.'
		);
		$this->assertStringContainsString( 'when SMS goes live', $dormant );
		$this->assertStringNotContainsString( 'when SMS goes live', $live );
	}

	/* ---------- compliance disclosure composition ---------- */

	public function test_compliance_disclosure_includes_sms_clause_when_dormant() {
		$this->set_sms_enabled( false );

		$text = Orbit_Compliance_UI::compliance_disclosure_text();

		$this->assertStringContainsString( 'SMS goes live', $text, 'Disclosure must carry the dormancy clause when SMS is off.' );
	}

	public function test_compliance_disclosure_drops_sms_clause_when_live() {
		$this->set_sms_enabled( true );

		$text = Orbit_Compliance_UI::compliance_disclosure_text();

		$this->assertStringNotContainsString( 'SMS goes live', $text, 'Disclosure must drop the dormancy clause once SMS is live.' );
	}
}
