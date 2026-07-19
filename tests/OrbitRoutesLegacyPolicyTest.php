<?php
/**
 * Tests for legacy public-policy URL resolution.
 *
 * @package Orbit
 */

class OrbitRoutesLegacyPolicyTest extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		foreach ( array( 'privacy', 'terms' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				wp_delete_post( $page->ID, true );
			}
		}
	}

	public function test_resolves_both_legacy_policy_slugs() {
		$this->assertSame( '/privacy/', Orbit_Routes::legacy_policy_destination( '/privacy-policy/', 'https://example.test/' ) );
		$this->assertSame( '/terms/', Orbit_Routes::legacy_policy_destination( '/terms-and-conditions/', 'https://example.test/' ) );
	}

	public function test_ignores_query_strings_and_trailing_slash_variants() {
		$this->assertSame( '/privacy/', Orbit_Routes::legacy_policy_destination( '/privacy-policy?source=old', 'https://example.test/' ) );
		$this->assertSame( '/terms/', Orbit_Routes::legacy_policy_destination( '/terms-and-conditions/?source=old', 'https://example.test/' ) );
	}

	public function test_supports_wordpress_in_a_subdirectory() {
		$this->assertSame( '/privacy/', Orbit_Routes::legacy_policy_destination( '/community/privacy-policy/', 'https://example.test/community/' ) );
	}

	public function test_does_not_redirect_unrelated_or_prefixed_paths() {
		$this->assertSame( '', Orbit_Routes::legacy_policy_destination( '/privacy/', 'https://example.test/' ) );
		$this->assertSame( '', Orbit_Routes::legacy_policy_destination( '/archive/privacy-policy/', 'https://example.test/' ) );
	}

	public function test_requires_a_published_owned_canonical_destination() {
		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => 'privacy',
		) );

		$this->assertFalse( Orbit_Routes::is_owned_canonical_policy_page( 'privacy' ) );

		update_post_meta( $page_id, '_orbit_code_owned_page', 'privacy' );
		update_post_meta( $page_id, '_orbit_canonical_compliance', 'privacy' );
		$this->assertTrue( Orbit_Routes::is_owned_canonical_policy_page( 'privacy' ) );

		wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
		$this->assertFalse( Orbit_Routes::is_owned_canonical_policy_page( 'privacy' ) );
	}
}
