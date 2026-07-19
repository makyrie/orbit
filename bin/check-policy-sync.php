<?php
/**
 * Policy content drift check.
 *
 * Detects drift between the canonical Markdown sources at
 * docs/compliance/{privacy-policy,terms-of-service}.md and the
 * Gutenberg-block-encoded HTML embedded in Orbit_Activator's
 * privacy_policy_content() / terms_of_service_content() methods.
 *
 * Exit 0 — the two sources match (prose only; markup excluded).
 * Exit 1 — drift detected. Prints a unified diff of the first ~20
 *          mismatched line pairs so the maintainer can reconcile.
 *
 * Invoke directly (`php bin/check-policy-sync.php`) or via
 * `composer policy-diff`. Wire into CI on PRs that touch either
 * the activator or the docs/compliance/*.md files.
 *
 * Implementation note: this script intentionally avoids bootstrapping
 * WordPress. It defines just enough constants (`ABSPATH`, `ORBIT_VERSION`)
 * to require Orbit_Activator and call its protected static content
 * helpers via Reflection.
 *
 * @package Orbit
 */

// Bootstrap: minimal constants so the activator file's `defined( 'ABSPATH' )
// || exit;` guard passes without dragging in core.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ORBIT_VERSION' ) ) {
	define( 'ORBIT_VERSION', 'cli-check' );
}

$repo_root      = dirname( __DIR__ );
$activator_path = $repo_root . '/includes/class-orbit-activator.php';

if ( ! is_readable( $activator_path ) ) {
	fwrite( STDERR, "ERROR: activator file not found at {$activator_path}\n" );
	exit( 2 );
}

require_once $activator_path;

if ( ! class_exists( 'Orbit_Activator' ) ) {
	fwrite( STDERR, "ERROR: Orbit_Activator class did not load.\n" );
	exit( 2 );
}

/**
 * Invoke a protected static method on Orbit_Activator by name.
 *
 * @param string $method Method name.
 * @return string Returned content.
 */
function orbit_invoke_protected( $method ) {
	$ref = new ReflectionMethod( 'Orbit_Activator', $method );
	$ref->setAccessible( true );
	return (string) $ref->invoke( null );
}

/**
 * Normalize block-encoded HTML to a prose token stream.
 *
 * Strips Gutenberg block delimiters, HTML tags, decodes entities,
 * and collapses whitespace.
 *
 * @param string $html Block-encoded HTML from the activator.
 * @return string Normalized prose.
 */
function orbit_normalize_html( $html ) {
	// Strip Gutenberg block comments (opening + closing forms).
	$out = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', "\n", $html );
	// Strip remaining HTML tags.
	$out = strip_tags( $out );
	// Decode entities (e.g. &amp; → &, &mdash; etc.).
	$out = html_entity_decode( $out, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return orbit_collapse_whitespace( $out );
}

/**
 * Normalize Markdown to a prose token stream.
 *
 * Strips frontmatter, the leading H1 title line (the activator does
 * not carry a page title — WordPress' post_title supplies it),
 * markdown formatting markers (headers, bullets, bold/italic, link
 * syntax), and collapses whitespace.
 *
 * @param string $md Raw markdown.
 * @return string Normalized prose.
 */
function orbit_normalize_markdown( $md ) {
	$out = $md;

	// Strip YAML frontmatter if present.
	$out = preg_replace( '/\A---\n.*?\n---\n/s', '', $out );

	// Drop the leading H1 title — the activator doesn't render one.
	$out = preg_replace( '/\A\s*#\s+.*\n/', '', $out );

	// Strip ATX heading markers (## Foo → Foo) on each remaining line.
	$out = preg_replace( '/^#{1,6}\s+/m', '', $out );

	// Strip leading bullet markers ("- ", "* ", "+ ") and ordered list markers ("1. ").
	$out = preg_replace( '/^[\-\*\+]\s+/m', '', $out );
	$out = preg_replace( '/^\d+\.\s+/m', '', $out );

	// Inline link [text](url) → text.
	$out = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $out );

	// Bold (**text** or __text__) → text.
	$out = preg_replace( '/\*\*([^*]+)\*\*/', '$1', $out );
	$out = preg_replace( '/__([^_]+)__/', '$1', $out );

	// Italic (_text_ or *text*) → text. Handle underscores conservatively to
	// avoid eating intra-word underscores.
	$out = preg_replace( '/(^|\s)_([^_]+)_(\s|$|[\.,;:!?])/m', '$1$2$3', $out );
	$out = preg_replace( '/(^|\s)\*([^*]+)\*(\s|$|[\.,;:!?])/m', '$1$2$3', $out );

	return orbit_collapse_whitespace( $out );
}

/**
 * Collapse whitespace: normalize newlines, trim each line, drop blank
 * lines, then join with single newlines so a line-by-line diff is
 * meaningful.
 *
 * @param string $text Input text.
 * @return string Normalized text.
 */
function orbit_collapse_whitespace( $text ) {
	// Normalize CRLF → LF.
	$text = str_replace( "\r\n", "\n", $text );
	$text = str_replace( "\r", "\n", $text );

	$lines      = explode( "\n", $text );
	$normalized = array();
	foreach ( $lines as $line ) {
		// Collapse internal whitespace runs.
		$line = preg_replace( '/\s+/', ' ', $line );
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$normalized[] = $line;
	}

	return implode( "\n", $normalized );
}

/**
 * Produce a compact unified diff between two prose strings.
 *
 * Shows up to $max mismatched line pairs so the maintainer can spot
 * what drifted without a wall of output.
 *
 * @param string $a   Left side (typically the activator).
 * @param string $b   Right side (typically the .md).
 * @param int    $max Maximum mismatched pairs to show.
 * @return string Diff text.
 */
function orbit_diff( $a, $b, $max = 20 ) {
	$a_lines = explode( "\n", $a );
	$b_lines = explode( "\n", $b );
	$count   = max( count( $a_lines ), count( $b_lines ) );

	$out   = array();
	$shown = 0;
	for ( $i = 0; $i < $count && $shown < $max; $i++ ) {
		$la = $a_lines[ $i ] ?? '<missing>';
		$lb = $b_lines[ $i ] ?? '<missing>';
		if ( $la === $lb ) {
			continue;
		}
		$out[] = sprintf( 'line %d:', $i + 1 );
		$out[] = '  - php: ' . $la;
		$out[] = '  + md : ' . $lb;
		++$shown;
	}

	if ( $shown >= $max ) {
		$out[] = sprintf( '... (truncated after %d mismatched lines)', $max );
	}

	return implode( "\n", $out );
}

/**
 * Compare one (PHP method, markdown file) pair and report.
 *
 * @param string $label    Human label for the policy.
 * @param string $method   Orbit_Activator method name.
 * @param string $md_path  Absolute path to the canonical .md.
 * @return bool True on match, false on drift.
 */
function orbit_check_pair( $label, $method, $md_path ) {
	if ( ! is_readable( $md_path ) ) {
		fwrite( STDERR, "ERROR: cannot read {$md_path}\n" );
		return false;
	}

	$php_prose = orbit_normalize_html( orbit_invoke_protected( $method ) );
	$md_prose  = orbit_normalize_markdown( file_get_contents( $md_path ) );

	if ( $php_prose === $md_prose ) {
		fwrite( STDOUT, "[OK] {$label}: activator matches {$md_path}\n" );
		return true;
	}

	fwrite( STDOUT, "[DRIFT] {$label}: activator does NOT match {$md_path}\n" );
	fwrite( STDOUT, orbit_diff( $php_prose, $md_prose ) . "\n" );
	return false;
}

$ok = true;
$ok = orbit_check_pair(
	'Privacy Policy',
	'privacy_policy_content',
	$repo_root . '/docs/compliance/privacy-policy.md'
) && $ok;
$ok = orbit_check_pair(
	'Terms of Service',
	'terms_of_service_content',
	$repo_root . '/docs/compliance/terms-of-service.md'
) && $ok;

exit( $ok ? 0 : 1 );
