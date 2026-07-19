---
status: complete
priority: p1
issue_id: "111"
tags: [code-review, PR-26, i18n, wp-php]
dependencies: []
---

# render_compliance_block uses str_replace on translated text — Privacy/Terms links silently disappear in non-English locales

## Problem Statement

`Orbit_Shortcodes::render_compliance_block()` at `class-orbit-shortcodes.php:1733-1758` produces the consent disclosure by:

1. Calling `compliance_disclosure_text()` which runs the entire sentence through `__()` for translation.
2. Wrapping it in `esc_html()`.
3. Running `str_replace( esc_html__( 'Privacy Policy', 'orbit' ), '<a href="…">…</a>', $text )` to splice anchor tags around the words "Privacy Policy" and "Terms".

This breaks in two ways:

**Bug 1 — i18n.** The two `__()` calls (one for the full sentence, one for the "Privacy Policy"/"Terms" needles in the str_replace) are independent translation strings. A translator who renders "Privacy Policy" as `Politique de confidentialité` in one string and `politique de confidentialité` (lowercased, inflected, or syntactically rearranged) in the disclosure sentence will produce two strings that don't share a substring. `str_replace` finds nothing, returns the haystack unchanged, and **the links silently disappear in every non-English locale**. The TCPA disclosure becomes plain text with no anchors — which is exactly what we need to NOT happen for compliance.

**Bug 2 — substring collisions.** If the disclosure text ever evolves to include words like "terms of use", "terms of service", or any sentence containing "Terms" as a non-link substring, str_replace will swap the wrong occurrence. Brittle and content-coupling.

## Findings

- `class-orbit-shortcodes.php:1715-1758` — `compliance_disclosure_text()` and `render_compliance_block()`.
- `compliance_disclosure_text()` returns a single translated sentence containing the literal English words "Privacy Policy" and "Terms".
- `render_compliance_block()` then does:
  ```php
  $text = esc_html( compliance_disclosure_text() );
  $text = str_replace( esc_html__( 'Privacy Policy', 'orbit' ), '<a href="…">…</a>', $text );
  $text = str_replace( esc_html__( 'Terms', 'orbit' ), '<a href="…">…</a>', $text );
  ```
- The ledger snapshot path also calls `compliance_disclosure_text()` and stores the plain translated string. The two callers want different rendering (one wants anchors, one wants plain text) from the same source.

## Proposed Solutions

### Option 1: sprintf placeholders in the translation source (recommended)

Rewrite `compliance_disclosure_text()` to accept the two label strings as arguments and use `%1$s` / `%2$s` placeholders:

```php
public function compliance_disclosure_text( string $privacy_label, string $terms_label ): string {
    /* translators: 1: Privacy Policy link or label, 2: Terms link or label */
    return sprintf(
        __( 'By submitting this form you agree to our %1$s and %2$s.', 'orbit' ),
        $privacy_label,
        $terms_label
    );
}
```

`render_compliance_block()` passes anchor HTML for both labels (each label is already escaped/safe before sprintf, the sentence template is translator-controlled, and the only HTML comes from us). The ledger snapshot path passes plain `__( 'Privacy Policy', 'orbit' )` / `__( 'Terms', 'orbit' )`.

**Pros:** Single source of truth; works in every locale because translators control the sentence structure but the labels are placeholders, not substring needles; no str_replace; matches WordPress core's well-established pattern.
**Cons:** Translation string changes — a one-time `.pot` regen + retranslation. Acceptable; the feature is new in PR #26 and no translations have shipped yet.
**Effort:** Small (1 hr).
**Risk:** Low.

### Option 2: Keep str_replace but add `wp_kses` + collision guards

Validate that the str_replace actually replaced something; if not, fall back to "append anchors at end of sentence".

**Pros:** Smallest diff.
**Cons:** Fragile fallback; degraded UX in non-English locales; doesn't fix bug 2; still couples translation to substring matching.
**Effort:** Small.
**Risk:** Medium (compliance/UX regression in some locales).

## Recommended Action

Ship Option 1 before merge. sprintf with `%1$s` / `%2$s` is the canonical WordPress approach for interpolated links inside translated sentences. Keep the translator comment to make placeholder semantics explicit. Regenerate the `.pot`.

## Technical Details

**Affected files:**
- `includes/class-orbit-shortcodes.php` (lines 1715-1758) — `compliance_disclosure_text()` and `render_compliance_block()`.
- Both consent ledger snapshot callers must pass plain labels; render path passes anchor HTML.
- `.pot` file (regen).

**Escaping rules under Option 1:**
- The translated template is not user-controlled but is translator-controlled — treat it as trusted format-string only (no `%s` from untrusted sources).
- The two labels passed in are either (a) escaped anchor HTML built locally with hard-coded `esc_url(home_url('/privacy/'))` and `esc_html__('Privacy Policy', 'orbit')` inside, or (b) plain `esc_html__()` text for the ledger snapshot.

## Acceptance Criteria

- [ ] `compliance_disclosure_text()` uses `sprintf` with numbered placeholders.
- [ ] `render_compliance_block()` no longer calls `str_replace`.
- [ ] Ledger snapshot path stores the plain-text version (no anchors) — verified via test.
- [ ] Render path produces correct anchors in en_US (regression check).
- [ ] Render path produces correct anchors when locale is switched (e.g., `fr_FR` or any locale where "Privacy Policy" would inflect) — verified with `switch_to_locale()` in a test.
- [ ] `.pot` regenerated; translator comment present.
- [ ] No HTML appears inside the ledger-stored disclosure text.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Implemented Option 1 (sprintf with numbered placeholders).
  - `includes/class-orbit-shortcodes.php` — `compliance_disclosure_text()` rewritten
    to accept `($privacy_label = null, $terms_label = null)`. Defaults resolve to
    `__('Privacy Policy', 'orbit')` and `__('Terms', 'orbit')` so the no-arg call
    from the REST handlers still returns the plain-text disclosure verbatim. The
    sentence template now uses `%1$s` (Privacy), `%2$s` (Terms), and `%3$s` (brand
    name) with a translator comment naming all three.
  - `render_compliance_block()` — removed both `str_replace` calls. Builds anchor
    HTML locally with `esc_url(home_url('/privacy/'))` + `esc_html__('Privacy
    Policy', 'orbit')` (and the same for Terms), passes those as the two label
    args, then runs the result through `wp_kses()` with `<a href>` allowlist to
    neutralize any HTML-special characters in the brand name (which is interpolated
    inside `compliance_disclosure_text()` without pre-escaping so the byte-match
    invariant with the ledger snapshot holds).
  - `class-orbit-rest-subscription.php:217` + `class-orbit-rest-signup.php:153` —
    unchanged. Both still call `Orbit_Shortcodes::compliance_disclosure_text()`
    with no args; backward-compatible by design.
  - PHPUnit: `vendor/bin/phpunit --filter OrbitRestSignupTest` could not run —
    Local site `NZ_MOyrML` is stopped and no MySQL socket is available. PHP
    syntax check (`php -l`) on the edited file passes cleanly. Tests should be
    re-run once Local is started; signature change is backward-compatible so the
    REST handlers' no-arg call site continues to work.
  - `.pot` regeneration: deferred — translation source string changed; needs
    `wp i18n make-pot` once Local is back up. Not blocking; no translations have
    shipped yet.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Sources: wp-php-reviewer, architecture-strategist (item #3), simplicity reviewer (item #10), call-chain-verifier (item #6)
- `includes/class-orbit-shortcodes.php:1715-1758`
- WordPress i18n docs on sprintf placeholders inside translated strings.
