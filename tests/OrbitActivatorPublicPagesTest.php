<?php
/**
 * Tests for code-owned public pages.
 *
 * @package Orbit
 */

class OrbitActivatorPublicPagesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->delete_pages( array( 'why', 'contact' ) );
	}

	public function tear_down() {
		$this->delete_pages( array( 'why', 'contact' ) );
		parent::tear_down();
	}

	private function delete_pages( array $slugs ) {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page ) {
				wp_delete_post( $page->ID, true );
			}
		}
	}

	public function test_creates_and_republishes_owned_public_pages() {
		Orbit_Activator::create_pages();

		$why     = get_page_by_path( 'why' );
		$contact = get_page_by_path( 'contact' );

		$this->assertSame( 'why', get_post_meta( $why->ID, '_orbit_code_owned_page', true ) );
		$this->assertSame( 'contact', get_post_meta( $contact->ID, '_orbit_code_owned_page', true ) );
		$this->assertStringContainsString( '/sign-up/', $why->post_content );
		$this->assertStringContainsString( 'sarah@perihelion.social', $contact->post_content );

		wp_update_post( array( 'ID' => $contact->ID, 'post_content' => 'editor drift' ) );
		Orbit_Activator::create_pages();

		$this->assertStringContainsString( 'sarah@perihelion.social', get_post( $contact->ID )->post_content );
		$this->assertStringNotContainsString( 'editor drift', get_post( $contact->ID )->post_content );
	}

	public function test_refuses_unowned_slug_collision() {
		$page_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'contact',
			'post_title'   => 'Independent contact',
			'post_content' => 'Do not replace me.',
		) );

		Orbit_Activator::create_pages();

		$this->assertSame( 'Do not replace me.', get_post( $page_id )->post_content );
		$this->assertSame( '', get_post_meta( $page_id, '_orbit_code_owned_page', true ) );
	}

	public function test_adopts_only_the_fingerprinted_legacy_why_page() {
		$page_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'why',
			'post_content' => 'Inviting is asymmetric work. This is also a product designed to be put down.',
		) );

		Orbit_Activator::create_pages();

		$this->assertSame( 'why', get_post_meta( $page_id, '_orbit_code_owned_page', true ) );
		$this->assertStringContainsString( '/sign-up/', get_post( $page_id )->post_content );
	}
}
