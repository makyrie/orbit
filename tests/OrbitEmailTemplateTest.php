<?php
/**
 * Tests for Orbit_Email_Template — the branded HTML email builder.
 *
 * Verifies the shell, the swappable wordmark, the escaping contract of each
 * content-piece helper, and the filterable footer.
 *
 * @package Orbit
 */

class OrbitEmailTemplateTest extends WP_UnitTestCase {

	/**
	 * Remove per-test filters.
	 */
	public function tear_down() {
		remove_all_filters( 'orbit_email_footer_html' );
		parent::tear_down();
	}

	/**
	 * wrap() returns a full HTML document with the Paper background, the
	 * Sienna wordmark, the white card holding the inner HTML, and the footer.
	 */
	public function test_wrap_builds_full_branded_document() {
		$html = Orbit_Email_Template::wrap( '<p>INNER CONTENT</p>', 'Preview snippet' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		// Newsprint background canvas.
		$this->assertStringContainsString( '#F0EEE6', $html );
		// White card with the 2px ink hairline border + rounded corners.
		$this->assertStringContainsString( 'border:2px solid #191A1D', $html );
		$this->assertStringContainsString( 'border-radius:8px', $html );
		// The uppercase ink wordmark.
		$this->assertStringContainsString( 'Perihelion', $html );
		$this->assertStringContainsString( 'Century Gothic', $html );
		$this->assertStringContainsString( '#D8176E', $html );
		// The inner content is present.
		$this->assertStringContainsString( '<p>INNER CONTENT</p>', $html );
	}

	/**
	 * The preheader text is rendered (escaped) inside the hidden preview div,
	 * and omitted entirely when empty.
	 */
	public function test_wrap_preheader_is_hidden_and_escaped() {
		$html = Orbit_Email_Template::wrap( 'x', 'Set your password to <get> started' );
		$this->assertStringContainsString( 'display:none', $html );
		$this->assertStringContainsString( 'Set your password to &lt;get&gt; started', $html );

		$no_pre = Orbit_Email_Template::wrap( 'x' );
		$this->assertStringNotContainsString( 'display:none', $no_pre );
	}

	/**
	 * The footer is overridable via the orbit_email_footer_html filter.
	 */
	public function test_footer_is_filterable() {
		add_filter(
			'orbit_email_footer_html',
			static function () {
				return 'CUSTOM FOOTER MARKUP';
			}
		);

		$html = Orbit_Email_Template::wrap( 'x' );
		$this->assertStringContainsString( 'CUSTOM FOOTER MARKUP', $html );
	}

	/**
	 * The wordmark is a single self-contained unit (the #62 swap point).
	 */
	public function test_wordmark_is_isolated_serif_lockup() {
		$mark = Orbit_Email_Template::wordmark();
		$this->assertStringContainsString( 'Perihelion', $mark );
		$this->assertStringContainsString( 'Century Gothic', $mark );
		// The wordmark is uppercase Ink (not the pink accent) in Community Press.
		$this->assertStringContainsString( '#191A1D', $mark );
	}

	/**
	 * paragraph() escapes text and converts newlines to <br> for two-line
	 * sign-offs.
	 */
	public function test_paragraph_escapes_and_breaks_lines() {
		$p = Orbit_Email_Template::paragraph( "See you out there,\nPerihelion & friends" );

		$this->assertStringContainsString( 'See you out there,<br>Perihelion', $p );
		// HTML-special characters are escaped.
		$this->assertStringContainsString( '&amp;', $p );
		$this->assertStringNotContainsString( ' & ', $p );
		// Ink body color.
		$this->assertStringContainsString( '#191A1D', $p );
	}

	/**
	 * paragraph_muted() uses the Slate sign-off color.
	 */
	public function test_paragraph_muted_uses_slate() {
		$p = Orbit_Email_Template::paragraph_muted( 'Perihelion' );
		$this->assertStringContainsString( '#54555C', $p );
	}

	/**
	 * button() renders a bulletproof Sienna table-cell button with an escaped
	 * URL and white anchor text.
	 */
	public function test_button_is_bulletproof_and_escapes_url() {
		$button = Orbit_Email_Template::button( 'Set your password', 'https://orbit.local/wp-login.php?action=rp&key=ABC&login=casey' );

		// Table-cell + deep-pink bgcolor + ink outline + rounded corners.
		$this->assertStringContainsString( 'bgcolor="#D8176E"', $button );
		$this->assertStringContainsString( 'border:2px solid #191A1D', $button );
		$this->assertStringContainsString( 'border-radius:6px', $button );
		// White anchor text.
		$this->assertStringContainsString( 'color:#ffffff', $button );
		$this->assertStringContainsString( 'Set your password', $button );
		// URL is escaped (& → &#038;) but the path/params survive.
		$this->assertStringContainsString( 'wp-login.php?action=rp', $button );
		$this->assertStringContainsString( 'login=casey', $button );
		$this->assertStringNotContainsString( '&key=', $button );
	}

	/**
	 * link_paragraph() renders a Sienna inline text link with an escaped URL.
	 */
	public function test_link_paragraph_renders_sienna_link() {
		$link = Orbit_Email_Template::link_paragraph( 'Respond', 'https://orbit.local/activity/5?act=tok' );
		$this->assertStringContainsString( 'Respond', $link );
		$this->assertStringContainsString( '#D8176E', $link );
		$this->assertStringContainsString( '/activity/5', $link );
	}

	/**
	 * heading() renders an escaped serif Ink heading.
	 */
	public function test_heading_renders_escaped_serif() {
		$h = Orbit_Email_Template::heading( 'Saturday <ride>' );
		$this->assertStringContainsString( 'Century Gothic', $h );
		$this->assertStringContainsString( 'Saturday &lt;ride&gt;', $h );
	}
}
