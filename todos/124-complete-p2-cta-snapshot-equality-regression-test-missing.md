---
status: complete
priority: p2
issue_id: "124"
tags: [code-review, PR-26, testing, tcpa-evidence, i18n]
dependencies: []
---

# No regression test enforces cta_snapshot byte-equality with rendered disclosure

## Problem Statement

The core TCPA-defense invariant for this PR is that the `cta_snapshot` value stored in the consent ledger is the *exact* text rendered to the user at opt-in time. If the snapshot drifts from the rendered disclosure (because translators updated the .po file, because a developer reworded the helper, because a locale-switch happened mid-request), our legal-defense story for that signup is destroyed.

There is no test enforcing this invariant. A future POT regeneration, copy update, or i18n change could silently break it.

## Findings

- `includes/class-orbit-shortcodes.php:1696-1721` — `compliance_disclosure_text()` is called both by the rendered form (visible to the user) and by the REST handlers (for the stored snapshot). The byte-equality relationship is structural but unenforced.
- No test in `tests/` exercises the round trip from rendered disclosure → REST signup → stored ledger row.
- Surfaced by architecture-strategist (findings #2 and #10) during multi-agent review.

## Proposed Solutions

**Option A — Round-trip equality test (recommended).**

1. Call `Orbit_Shortcodes::compliance_disclosure_text()` (or its post-extraction equivalent from todo 131) and capture the rendered string.
2. Drive a signup request with the test framework's REST client.
3. Query the resulting `wp_orbit_consent_log` row.
4. Assert `cta_snapshot` is byte-identical to the captured string.

Add a second variant that does `switch_to_locale( 'es_ES' )` before the call, drives the signup with the same locale, and asserts equality holds — proving the snapshot reflects the locale the user actually saw.

Effort: low. Risk: low.

**Option B — Add an assertion inside production code.** A `wp_doing_tests()`-gated `assert()` in the REST handler that the snapshot matches the helper output. Catches drift in tests but adds noise.

## Recommended Action

Option A. Pair with a docblock update on `compliance_disclosure_text()` clarifying that the stored ledger string is whatever locale the request resolved at opt-in time, so future maintainers don't accidentally hoist locale-switching above the helper call.

## Technical Details

- Use the WP test framework's `rest_do_request` rather than HTTP — keeps the test fast and deterministic.
- The locale-switch variant should also assert the disclosure text differs between en_US and es_ES (to catch the case where i18n is silently broken and both locales return English).
- The docblock update should call out: "The byte string returned here is stored in the consent ledger as `cta_snapshot`. Any change to its output is a TCPA-relevant change."
- Consider adding a snapshot test that pins the en_US disclosure text — drift triggers a deliberate update.

## Acceptance Criteria

- [ ] Test exists in `tests/` that drives signup and asserts `cta_snapshot` byte-equality with the helper output.
- [ ] Locale-switch variant proves the snapshot tracks the user-visible locale.
- [ ] `compliance_disclosure_text()` docblock documents the TCPA-evidence role of its return value.
- [ ] Tests fail loudly when the helper output drifts from the stored snapshot.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Added `tests/OrbitConsentCtaSnapshotTest.php` enforcing
  cta_snapshot byte-equality with `Orbit_Shortcodes::compliance_disclosure_text()`
  for both /signup and /subscribe REST paths, across both SMS-dormant
  (Wave A default, sunset clause prepended) and SMS-live (option flipped via
  `Orbit_Features::OPTION_SMS_ENABLED='1'`) states. Each variant first
  pins the precondition (sunset clause present / absent in the disclosure)
  so the byte-equality assertions never pass vacuously against a
  silently-broken disclosure. Email-only and email+SMS rows both checked;
  SMS variants confirm BOTH ledger rows hold the same snapshot string.
  Test count: 7 in new file (incl. dormancy precondition + deferred
  locale-variant placeholder); 61 total under
  `--filter "Orbit(Consent|RestSignup|RestSubscription)"`, all green.
  Locale-switching variant (Option A second variant in the proposal)
  intentionally deferred and documented in the test docblock — the project
  ships no compiled .mo files yet, so `switch_to_locale()` would fall back
  to en_US and the test would pass vacuously. Production source unchanged
  per scope constraint (docblock-clarification subitem deferred until the
  same constraint lifts).

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-shortcodes.php:1696-1721`
- `tests/OrbitRestSignupTest.php` (or new file)
- Related: todo 131 (extract compliance UI)
