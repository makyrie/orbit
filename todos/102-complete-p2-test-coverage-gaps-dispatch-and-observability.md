---
status: complete
priority: p2
issue_id: "102"
tags: [code-review, tests, PR-24]
dependencies: []
---

# Test coverage gaps: dispatch end-to-end, observability hooks, legacy fallback, multisite ledger

## Problem Statement

wp-test-reviewer identified four meaningful test gaps in PR #24:

1. **`process_dispatch` end-to-end** — the new paginated dispatcher with `cache_users` warmup has no test. A subtle bug (off-by-one on `$page`, infinite loop on partial final batch, `cache_users` called with empty array) would ship green.

2. **`orbit_notification_sent` and `orbit_notification_failed` observability hooks** — only `_coerced` has a test. Per "assert happy AND error paths," the success and failure hooks both need coverage.

3. **Legacy raw-secret unsubscribe fallback** — `OrbitTokenUnsubscribeTest` exercises HMAC generation/validation, but `resolve_unsubscribe_subscription`'s legacy `Orbit_Subscription::get_by_secret( $token )` branch has no test.

4. **Multisite base_prefix verification** — `Orbit_Consent::table_name()` uses `$wpdb->base_prefix`. On single-site, `base_prefix === prefix`, so a regression that swapped to `$wpdb->prefix` would pass all 11 ledger tests. Add at minimum a string assertion: `assertStringStartsWith( $wpdb->base_prefix, Orbit_Consent::table_name() )`.

## Proposed Solutions

**Add to existing test files (recommended):**

`OrbitNotifierTest`:
- `test_process_dispatch_paginates_above_batch_size()` — create 501 approved subscribers, fire dispatch, assert all 501 enqueued.
- `test_orbit_notification_sent_fires_on_email_success()` + `test_orbit_notification_failed_fires_on_wp_mail_failure()` — via filter that toggles `wp_mail` short-circuit.

`OrbitTokenUnsubscribeTest`:
- `test_legacy_raw_secret_resolves_subscription()` — create subscription, call resolver with raw `subscription_secret`, assert returned subscription matches.

`OrbitConsentTest`:
- `test_table_name_uses_base_prefix()` — `assertStringStartsWith( $wpdb->base_prefix, Orbit_Consent::table_name() )`.

## Acceptance Criteria

- [ ] All 4 new test methods exist and pass.
- [ ] Existing 97/97 still pass.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-test-reviewer, data-integrity-guardian.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `tests/OrbitNotifierTest.php`, `tests/OrbitTokenUnsubscribeTest.php`, `tests/OrbitConsentTest.php`
