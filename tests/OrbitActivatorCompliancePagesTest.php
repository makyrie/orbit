<?php
/**
 * Tests for activator-managed canonical compliance pages.
 *
 * Covers todo 117: the activator must store the canonical /privacy/ and
 * /terms/ page IDs in dedicated options (`orbit_privacy_page_id`,
 * `orbit_terms_page_id`) and stamp `_orbit_canonical_compliance` post_meta
 * on those pages so downstream code can dereference the canonical post
 * directly instead of trusting a slug lookup. The slug-only path lets any
 * user with the `edit_pages` capability silently squat the canonical URL
 * by pre-creating a draft, defeating the TCPA-defense story.
 *
 * @package Orbit
 */

/**
 * Class OrbitActivatorCompliancePagesTest
 */
class OrbitActivatorCompliancePagesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Wipe options + pages so each test starts from a known clean state.
		// The shared suite bootstrap creates the consent ledger table once;
		// we don't touch it here.
		delete_option( 'orbit_privacy_page_id' );
		delete_option( 'orbit_terms_page_id' );

		$this->delete_pages_by_slug( array( 'privacy', 'terms' ) );
	}

	public function tear_down() {
		delete_option( 'orbit_privacy_page_id' );
		delete_option( 'orbit_terms_page_id' );

		$this->delete_pages_by_slug( array( 'privacy', 'terms' ) );

		parent::tear_down();
	}

	/**
	 * Force-delete any pages matching the supplied slugs.
	 *
	 * Uses get_posts() rather than get_page_by_path() so we catch draft
	 * collisions too — drafts at /privacy/ are exactly the attack surface
	 * this test file exists to lock down.
	 *
	 * @param array $slugs Slugs to clear.
	 */
	private function delete_pages_by_slug( array $slugs ) {
		foreach ( $slugs as $slug ) {
			$pages = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => 'page',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			foreach ( $pages as $page_id ) {
				wp_delete_post( $page_id, true );
			}
		}
	}

	/**
	 * Fresh activation must mint both canonical-page options.
	 *
	 * Asserts the options exist, point at real page IDs, and that those
	 * page IDs match what `get_page_by_path()` resolves for the slug. This
	 * is the baseline "happy path" before any squatting / collision logic
	 * comes into play.
	 */
	public function test_fresh_activation_writes_both_canonical_page_id_options() {
		Orbit_Activator::create_pages();

		$privacy_page_id = (int) get_option( 'orbit_privacy_page_id', 0 );
		$terms_page_id   = (int) get_option( 'orbit_terms_page_id', 0 );

		$this->assertGreaterThan( 0, $privacy_page_id, 'orbit_privacy_page_id must be a real page ID after activation.' );
		$this->assertGreaterThan( 0, $terms_page_id, 'orbit_terms_page_id must be a real page ID after activation.' );

		$privacy_page = get_page_by_path( 'privacy' );
		$terms_page   = get_page_by_path( 'terms' );

		$this->assertNotNull( $privacy_page, 'A /privacy/ page must exist after activation.' );
		$this->assertNotNull( $terms_page, 'A /terms/ page must exist after activation.' );

		$this->assertSame( (int) $privacy_page->ID, $privacy_page_id );
		$this->assertSame( (int) $terms_page->ID, $terms_page_id );
	}

	/**
	 * Both canonical pages must carry the `_orbit_canonical_compliance`
	 * post_meta marker after activation.
	 *
	 * The marker is what lets us detect slug-squatting later — without it,
	 * a manual database edit or a future plugin could repoint the canonical
	 * option at an unrelated page and we'd have no way to notice.
	 */
	public function test_fresh_activation_stamps_canonical_compliance_post_meta() {
		Orbit_Activator::create_pages();

		$privacy_page_id = (int) get_option( 'orbit_privacy_page_id', 0 );
		$terms_page_id   = (int) get_option( 'orbit_terms_page_id', 0 );

		$privacy_marker = get_post_meta( $privacy_page_id, '_orbit_canonical_compliance', true );
		$terms_marker   = get_post_meta( $terms_page_id, '_orbit_canonical_compliance', true );

		$this->assertSame( 'privacy', $privacy_marker, '/privacy/ page must be stamped with the canonical-compliance marker.' );
		$this->assertSame( 'terms', $terms_marker, '/terms/ page must be stamped with the canonical-compliance marker.' );
	}

	/**
	 * Re-activation must not change the stored canonical page IDs.
	 *
	 * The activator runs on every plugin upgrade; if it rewrote the stored
	 * IDs we'd risk dropping the canonical pointer when an admin had
	 * already cleaned up a duplicate. Once the option is set, the only
	 * thing that should mutate it is a deliberate migration.
	 */
	public function test_re_activation_does_not_change_stored_page_ids() {
		Orbit_Activator::create_pages();

		$privacy_page_id_first = (int) get_option( 'orbit_privacy_page_id', 0 );
		$terms_page_id_first   = (int) get_option( 'orbit_terms_page_id', 0 );

		$this->assertGreaterThan( 0, $privacy_page_id_first );
		$this->assertGreaterThan( 0, $terms_page_id_first );

		// Re-run activation a few times to mimic plugin upgrades.
		Orbit_Activator::create_pages();
		Orbit_Activator::create_pages();
		Orbit_Activator::create_pages();

		$this->assertSame(
			$privacy_page_id_first,
			(int) get_option( 'orbit_privacy_page_id', 0 ),
			'orbit_privacy_page_id must be stable across re-activations.'
		);
		$this->assertSame(
			$terms_page_id_first,
			(int) get_option( 'orbit_terms_page_id', 0 ),
			'orbit_terms_page_id must be stable across re-activations.'
		);
	}

	/**
	 * `Orbit_Consent::canonical_compliance_page_id()` must return the
	 * option-stored ID for valid kinds and 0 otherwise.
	 *
	 * This is the read-side API that downstream callers will use to
	 * dereference the canonical post — the option is the source of truth,
	 * not the slug.
	 */
	public function test_canonical_compliance_page_id_reads_from_options() {
		Orbit_Activator::create_pages();

		$privacy_page_id = (int) get_option( 'orbit_privacy_page_id', 0 );
		$terms_page_id   = (int) get_option( 'orbit_terms_page_id', 0 );

		$this->assertSame( $privacy_page_id, Orbit_Consent::canonical_compliance_page_id( 'privacy' ) );
		$this->assertSame( $terms_page_id, Orbit_Consent::canonical_compliance_page_id( 'terms' ) );

		// Unknown kinds return 0 so callers can branch into their
		// home_url() fallback without inventing a sentinel.
		$this->assertSame( 0, Orbit_Consent::canonical_compliance_page_id( 'bogus' ) );
		$this->assertSame( 0, Orbit_Consent::canonical_compliance_page_id( '' ) );
	}

	/**
	 * When the option is absent, `canonical_compliance_page_id()` must
	 * return 0 so callers know to fall back to home_url('/privacy/').
	 */
	public function test_canonical_compliance_page_id_returns_zero_when_option_absent() {
		// set_up() already deleted both options. No activation yet.
		$this->assertSame( 0, Orbit_Consent::canonical_compliance_page_id( 'privacy' ) );
		$this->assertSame( 0, Orbit_Consent::canonical_compliance_page_id( 'terms' ) );
	}
}
