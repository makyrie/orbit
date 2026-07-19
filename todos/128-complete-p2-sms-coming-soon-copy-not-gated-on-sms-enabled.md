---
status: complete
priority: p2
issue_id: "128"
tags: [code-review, PR-26, copy, sunset, sms-launch]
dependencies: []
---

# "SMS coming soon" copy is not consistently gated on Orbit_Features::sms_enabled()

## Problem Statement

Four user-visible strings hardcode the pre-launch "SMS coming soon / messaging service pending approval" message. Only one of the four is gated on `Orbit_Features::sms_enabled()`. The day the SMS service goes live and the flag flips:

- The dashboard banner will still say "SMS coming soon" — wrong.
- The settings phone-help note will still say "we'll text you once SMS is live" — wrong.
- The **compliance disclosure text stored in the consent ledger as cta_snapshot** will still say "SMS goes live once X's messaging service is approved" — wrong, and now this is wrong inside our legal-defense evidence.

## Findings

- `includes/class-orbit-shortcodes.php:228-232` — dashboard banner copy, no gate.
- `includes/class-orbit-shortcodes.php:494-500` — settings status banner, correctly gated.
- `includes/class-orbit-shortcodes.php:506` — settings phone-help note, no gate.
- `includes/class-orbit-shortcodes.php:1715-1721` — compliance disclosure text in `compliance_disclosure_text()`, no gate. This is the most serious one because it gets persisted to the consent ledger.
- `includes/class-orbit-features.php` — `sms_enabled()` exists but is only checked in one of the four sites.
- Surfaced by simplicity-reviewer (finding #5) and architecture-strategist (finding #7) during multi-agent review.

## Proposed Solutions

**Option A — Single source of truth for the SMS clause (recommended).** Introduce `Orbit_Messaging_Copy::sms_status_clause()`:

```php
public static function sms_status_clause(): string {
    if ( Orbit_Features::sms_enabled() ) {
        return ''; // No special clause; baseline disclosure is correct.
    }
    return __( 'Initially we deliver everything by email — SMS goes live once our messaging service is approved.', 'orbit' );
}
```

Inject into the disclosure helper, the dashboard banner, and the settings notes. Phase 5 SMS-launch becomes a one-flag config change with no copy regression.

Effort: low. Risk: low.

**Option B — Add the gate inline at each call site.** Cheaper now, fragile later — a fifth or sixth site will appear and someone will forget the gate again.

## Recommended Action

Option A. The centralisation cost is small and the future-proof value is high, especially for the consent-ledger snapshot.

## Technical Details

- Place the new class in `includes/class-orbit-messaging-copy.php`.
- All four sites should call the helper; lint or grep for the literal phrase "SMS coming soon" to catch stragglers.
- The compliance disclosure should compose the SMS clause with the baseline disclosure (don't store the clause as a separate snapshot column — the ledger stays a single string).
- Coordinate with todo 124 (cta_snapshot equality test) — that test should pin both gated and ungated variants.

## Acceptance Criteria

- [x] `Orbit_Messaging_Copy::sms_status_clause()` exists and returns the SMS clause only when `! sms_enabled()`.
- [x] All four sites compose copy via the helper, not via inline strings.
- [x] Flipping `sms_enabled()` in test removes the SMS clause from all four sites.
- [x] `compliance_disclosure_text()` output differs as expected when the flag flips, and the snapshot-equality test (todo 124) covers both variants.
- [x] Grep for "SMS coming soon" and the heredoc phrase returns no untreated hits.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Implemented Option A. Added `includes/class-orbit-messaging-copy.php` with `sms_status_clause()`, `dashboard_onboarding_banner_copy()`, and `settings_phone_help_note()` — all three flip on `Orbit_Features::sms_enabled()`. Refactored `Orbit_Shortcodes::compliance_disclosure_text()` so the SMS clause is prepended via the helper (same position in rendered HTML + ledger snapshot path → cta_snapshot byte-match preserved). Replaced the dashboard onboarding banner copy and gated the whole banner on `! sms_enabled()` so it disappears for unverified users post-launch. Replaced the settings phone-help note with the helper. Loader updated (`orbit.php`). New PHPUnit file `tests/OrbitMessagingCopyTest.php` — 10 tests / 18 assertions, all green. `OrbitRestSignupTest` re-run green (17 tests / 65 assertions). Pre-existing unrelated failure in `OrbitRestSubscriptionTest::test_happy_path_creates_subscription` confirmed not caused by these changes (reproduces on baseline).

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-shortcodes.php:213-237, 494-500, 1715-1721`
- `includes/class-orbit-features.php`
- New: `includes/class-orbit-messaging-copy.php`
- Related: todo 124 (cta_snapshot equality test)
