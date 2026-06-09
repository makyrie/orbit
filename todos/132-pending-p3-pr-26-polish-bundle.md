---
status: pending
priority: p3
issue_id: "132"
tags: [code-review, polish, PR-26]
dependencies: []
---

# PR #26 P3 polish bundle — compliance-UI helper consolidation, REST/cookie simplification, regex/brand dedup, locale-aware ledger docs

## Problem Statement

Catch-all for ~15 P3/hygiene findings from PR #26 (Phase 2 compliance UI + transactional opt-in capture). Each item is small (1-line to 10-line fix) but worth bundling so they land together rather than each being its own follow-up PR.

## Items

### A. REST/cookie simplification (simplicity-reviewer #2, #7)
- [ ] Replace `POST /me/dismiss-onboarding-banner` REST endpoint + JS handler with a plain `orbit_banner_dismissed=1` cookie (1-year expiry). Saves the REST route registration in `class-orbit-rest-profile.php:108-127` (~20 LOC), the `dismiss_onboarding_banner` method, and the orbit-forms.js click handler at lines 626-645 (~20 LOC). Cross-device sync is YAGNI for an "until SMS launches" sunset banner. Net ~40 LOC + one REST surface gone.
- [ ] If keeping the REST endpoint, move it from `class-orbit-rest-profile.php` (profiles controller) to a new `class-orbit-rest-me.php` (architecture-strategist #9). The /me/ URL and user_meta mutation don't belong in the profiles controller. Cheap (~20 lines new file boilerplate) and establishes the namespace before more /me/ endpoints land.

### B. Helper extraction and dedup (simplicity-reviewer #1, #3, #6, #11)
- [ ] Drop the `Orbit_Activator::compliance_page_content($kind)` dispatcher at lines 336-342 — caller already has the slug, let it call `privacy_policy_content()` / `terms_of_service_content()` directly. Net -7 LOC.
- [ ] Consolidate the 3-line render sequence `render_phone_field + render_compliance_block + render_consent_checkboxes` (called at shortcodes.php:145-147 and :1481-1484) into a single `render_optin_block($id_prefix, $include_phone = true)` helper that calls the three primitives internally. Net -4 LOC; removes the only currently-existing "you must always call these three together" coupling.
- [ ] Promote E.164 regex `/^\+[1-9]\d{1,14}$/` to `Orbit_Phone_Verify::E164_REGEX` constant. Currently duplicated 3x: `class-orbit-phone-verify.php:80`, `class-orbit-rest-subscription.php:192`, `class-orbit-rest-signup.php:126`. Also dedup the matching error-message string into `Orbit_Phone_Verify::invalid_format_message()`.
- [ ] Extract a `messaging_brand()` private helper for the `defined('ORBIT_MESSAGING_BRAND') ? ORBIT_MESSAGING_BRAND : get_bloginfo('name')` pattern duplicated at `class-orbit-shortcodes.php:1719` and :1815 (also see todo 107 item A which flagged 4 copies project-wide).

### C. Dashboard banner architecture (architecture-strategist #6)
- [ ] Extract dashboard banner from inline HTML in `Orbit_Shortcodes::dashboard()` (lines 215-238) to a `render_onboarding_banner($user_id): string` method. Wrap the visible copy in `apply_filters('orbit_dashboard_onboarding_banner_text', $text, $context)` for theme/mu-plugin overrides. Makes the banner unit-testable in isolation and trivially swappable post-SMS-launch.

### D. Locale-aware ledger documentation (architecture-strategist #2, simplicity-reviewer side note)
- [ ] Add a docblock paragraph to `Orbit_Shortcodes::compliance_disclosure_text()` (lines 1696-1706) clarifying that the stored ledger string is whatever locale the user's request resolved at opt-in time — captured as evidence-of-what-they-saw, not as a language-agnostic anchor.
- [ ] Operational policy "When this text changes, bump ORBIT_VERSION" (currently a comment on line 1708) should also note that ANY i18n change to the source string requires bumping `ORBIT_VERSION` so `current_policy_version()` advances for new opt-ins.

### E. Agent-native CLI expansions (agent-native-reviewer #4, #5, #6)
- [ ] Add `wp orbit compliance disclosure` (prints canonical text from `compliance_disclosure_text()`) and `wp orbit compliance policy-versions` (prints orbit_policy_version for /privacy/ and /terms/). One-line wrappers; high audit-prep value for TCR submission and legal review screenshot diffs.
- [ ] Add `wp orbit user reset-banner --user_id=X` and `wp orbit user dismiss-banner --user_id=X` for QA/support impersonation flows. Inverse op (reset) is more valuable than forward op for testing.
- [ ] File a follow-up: consolidated `wp orbit user show --user_id=X` dumping phone, pending phone, per-channel consent state, banner state, policy versions in force at last consent — single CLI lookup vs. three meta + a ledger query.

### F. Transaction safety canary (architecture-strategist #11)
- [ ] Add a tripwire integration test that registers a `user_register` hook that inserts a sentinel row into a temp table, runs a known-failing signup, and asserts the sentinel was rolled back. If implicit-commit happened on the deployed MySQL config, this test fails noisily before a real rollback case bites. (See also the heavier-duty todo on documenting implicit-commit risk.)

### G. Locale-driven render robustness (architecture-strategist #3)
- [ ] After the str_replace fix in the P1 todo lands, add a Spanish/German PHPUnit case that confirms `render_compliance_block()` produces clickable /privacy/ and /terms/ links in non-English locales. Defends against future translator edits that would have silently broken the str_replace path.

### H. Cleanup hygiene
- [ ] After todo 110 ships pending-phone cleanup, verify `Orbit_Privacy::cleanup_user_data()` includes `delete_user_meta($user_id, 'orbit_phone_pending')` and that the GDPR data-export path includes pending-phone if present.
- [ ] Sanity-check that `wp_initialize_site` hook (from todo 107 item I) creates the orbit_policy_version post_meta on multisite sub-site adds, since /privacy/ + /terms/ creation now happens at activation.

## Recommended Action

Bundle into a single follow-up PR after the higher-severity PR #26 fixes (108-115 P1, key P2s) land. Items A and B are independent and can ship first; C/D/E/F can ship together. Total estimated effort: 1 dev-day.

## Acceptance Criteria

- [ ] All items above are addressed or explicitly punted with a follow-up todo (Orbit v1.7.1 polish target).

## Work Log

- 2026-06-08: Consolidated during PR #26 code review synthesis from findings by 10 review agents.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Various source files (see each item).
