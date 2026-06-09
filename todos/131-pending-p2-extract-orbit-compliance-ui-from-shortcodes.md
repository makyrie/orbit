---
status: pending
priority: p2
issue_id: "131"
tags: [code-review, PR-26, refactor, architecture]
dependencies: []
---

# Extract Orbit_Compliance_UI from Orbit_Shortcodes — REST handlers should not reach into a shortcode class

## Problem Statement

`Orbit_Shortcodes` is 1677 lines and growing. PR #26 adds four compliance helpers there: `compliance_disclosure_text()`, `render_compliance_block()`, `render_phone_field()`, `render_consent_checkboxes()` (lines 1715-1839).

Two of these are now called *from non-shortcode REST handlers*:

- `Orbit_REST_Subscription::handle_subscribe` (line 217) calls `Orbit_Shortcodes::compliance_disclosure_text()` to build `cta_snapshot`.
- `Orbit_REST_Signup::handle_signup` (line 153) does the same.

REST controllers depending on a presentation-layer class for legal-defense evidence is an architectural smell. It also means any future tightening of `Orbit_Shortcodes`' surface (e.g. lazy-loading, autoloader scoping) risks breaking REST endpoints.

## Findings

- `includes/class-orbit-shortcodes.php:1715-1839` — four compliance helpers tucked inside the shortcodes class.
- `includes/class-orbit-rest-subscription.php:217` — REST handler calls into the shortcode class.
- `includes/class-orbit-rest-signup.php:153` — same.
- Shortcodes class is the largest file in the plugin; reviewer load is already high.
- Surfaced by architecture-strategist (finding #1) during multi-agent review.

## Proposed Solutions

**Option A — Extract into `Orbit_Compliance_UI` (recommended).** New `includes/class-orbit-compliance-ui.php` owns the four helpers. `Orbit_Shortcodes` calls into it for the rendered form path. REST handlers call into it for `cta_snapshot`. Shortcodes file shrinks by ~150 lines.

Effort: medium. Risk: low — the helpers are mostly pure functions, easy to relocate.

**Option B — Pull the disclosure text into a tiny dedicated helper class.** Leave the render helpers in shortcodes; move only `compliance_disclosure_text()` (the one with TCPA-evidence weight). Smaller change but leaves the architectural smell partially intact.

## Recommended Action

Option A. Pair with todo 130 — both touch the same hot files, and `Orbit_User_Provisioning` will want to call `Orbit_Compliance_UI::compliance_disclosure_text()` to keep the snapshot/render byte-equality invariant clean (see todo 124).

## Technical Details

- Class methods stay static — they're pure functions over input.
- `Orbit_Shortcodes` becomes a thin caller; keep backwards-compat shims if anything external (themes? other plugins?) references the old method names. Search for callers and decide whether to leave a deprecation shim.
- The class lives in `includes/` next to other UI-presentation helpers (no new directory needed for this).
- The TCPA-evidence docblock from todo 124 lives on `compliance_disclosure_text()` after extraction.
- Coordinate the extraction with todo 128's `Orbit_Messaging_Copy::sms_status_clause()` — the disclosure helper composes the SMS clause from the new helper.

## Acceptance Criteria

- [ ] `Orbit_Compliance_UI` exists with the four helpers as static methods.
- [ ] REST handlers call `Orbit_Compliance_UI::compliance_disclosure_text()`, not `Orbit_Shortcodes::...`.
- [ ] `Orbit_Shortcodes` shrinks by ~150 lines.
- [ ] Backwards-compat shims (or deletion + grep verification) handle any external callers.
- [ ] `Orbit_Compliance_UI` is what `Orbit_User_Provisioning` (todo 130) calls for `cta_snapshot`.
- [ ] todo 124 snapshot-equality test runs against the new class.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-shortcodes.php:1715-1839`
- `includes/class-orbit-rest-subscription.php:217`
- `includes/class-orbit-rest-signup.php:153`
- New: `includes/class-orbit-compliance-ui.php`
- Related: todos 124 (snapshot equality), 128 (SMS copy gate), 130 (provisioning service)
