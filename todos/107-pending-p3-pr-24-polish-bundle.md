---
status: pending
priority: p3
issue_id: "107"
tags: [code-review, polish, PR-24]
dependencies: []
---

# PR #24 P3 polish bundle — docblocks, naming, indentation, idempotency hygiene

## Problem Statement

Catch-all for ~20 P3/hygiene findings from PR #24 review. Each item is small (1-line to 5-line fix) but worth bundling so they land together rather than each being its own follow-up PR.

## Items

### A. Docblock + comment hygiene
- [ ] `includes/class-orbit-activator.php:26` — change "Create all 7 custom tables" → "Create all 8 custom tables" (consent_ledger is the 8th).
- [ ] `includes/class-orbit-notifier.php:622-627` — move `$preferences_cache` property + its docblock above the `get_or_create_preferences()` docblock; currently breaks the docblock-to-method association.
- [ ] `includes/class-orbit-consent.php:296-313` — add per-`@param` lines to `compute_row_hash()`.
- [ ] `includes/class-orbit-routes.php:489-490` — add `// TODO: remove legacy fallback after ORBIT_LEGACY_UNSUB_TOKEN_SUNSET` comment alongside the implementation from todo 104.
- [ ] `includes/class-orbit-notifier.php` — add `HOOK_NOTIFICATION_METHOD`, `HOOK_NOTIFICATION_SENT`, `HOOK_NOTIFICATION_FAILED`, `HOOK_NOTIFICATION_COERCED` class constants matching the existing `HOOK_*` pattern. Public-API hooks deserve constants more than the internal AS hooks do.
- [ ] Consider extracting `Orbit_Features::messaging_brand()` static helper to replace the 4 copies of `defined('ORBIT_MESSAGING_BRAND') ? ... : get_bloginfo('name')` (architecture-strategist suggestion #8). Optional v1.6.1 if we touch any of those files.

### B. Double-write hygiene
- [ ] `includes/class-orbit-activator.php:218` — remove `update_option('orbit_db_version', ORBIT_VERSION)` from `create_tables()`. Single owner of the version write is `orbit_maybe_upgrade()` and `orbit_activate()`. The activator's own call is a leftover.

### C. Defensive constant guards
- [ ] `orbit.php:38-46` — wrap `ORBIT_TABLE_*` constants in `defined() || define()` for symmetry with `ORBIT_MESSAGING_BRAND` and to avoid collision warnings if a sister codebase defines the same names.

### D. Version comparison
- [ ] `orbit.php:140` — switch `if ( $installed_version !== ORBIT_VERSION )` to `if ( ! $installed_version || version_compare( $installed_version, ORBIT_VERSION, '<' ) )`. Treats downgrade as no-op (admin rolled back the plugin) instead of re-running upgrade against newer schema.

### E. cta_snapshot length cap
- [ ] `includes/class-orbit-consent.php::record()` — reject `cta_snapshot` longer than 16,000 chars with `WP_Error('orbit_consent_cta_too_long')`. Defends against a programming error where a caller passes the entire rendered HTML page (or megabytes of irrelevant context) instead of the CTA text. Silent truncation breaks future hash verification.

### F. Query guard cheap-path memoization
- [ ] `includes/class-orbit-consent.php::is_consent_ledger_query()` — memoize `self::table_name()` in a static so it's computed once per process, not per query. ~1µs savings × N queries.

### G. Test hygiene
- [ ] `tests/OrbitConsentTest.php` — add `tear_down()` mirroring `set_up()` so the table is empty on the way out of the class, not just on the way in.
- [ ] `tests/OrbitConsentTest.php::test_verify_chain_detects_tampering` — assert WHICH rows are broken (the tampered row + every row after it in the chain), not just that some rows are reported broken.
- [ ] `tests/OrbitConsentTest.php::test_query_guard_blocks_naked_update` — use `expectUserWarning` or a custom error handler to assert the `E_USER_WARNING` is fired (the warning IS the operator-facing signal; silencing it loses test value).
- [ ] `tests/OrbitFeaturesTest.php:43-49` — add `@runInSeparateProcess @preserveGlobalState disabled` test for the `ORBIT_SMS_ENABLED` constant override path.
- [ ] `tests/OrbitTwilioWebhookTest.php:31-33` — document in a comment that `ORBIT_TWILIO_AUTH_TOKEN` definition leaks to other tests; consider `@runInSeparateProcess` if future tests need the "undefined" path.

### H. Require-order hygiene
- [ ] `orbit.php` — reorder requires: move `class-orbit-client-ip.php` above `class-orbit-consent.php` so the dependency direction is visible in the file (consent's `record()` calls into client-ip; works today by PHP's lazy method binding, but reordering removes the load-order trap).

### I. Multisite onboarding gap
- [ ] Add `wp_initialize_site` hook so consent ledger / per-site tables are created on multisite sub-site add (today they self-heal on first cold hit, but the hook is the explicit pattern).

### J. Notification cap query index
- [ ] Verify `wp_orbit_notification_log` has an index on `(user_id, method, created_at)` for the `is_sms_cap_reached()` query — performance-oracle flagged it as not strictly required for v1 but worth confirming the existing index covers the path.

## Recommended Action

Bundle into a single follow-up PR after the higher-severity PR #24 fixes land. Some items (E, F, G, J) have weak ordering dependencies on each other; the rest are independent.

## Acceptance Criteria

- [ ] All items above are addressed or explicitly punted with a v1.1 follow-up todo.

## Work Log

- 2026-06-01: Consolidated during PR #24 code review synthesis from findings by 8 review agents.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- Various source files (see each item).
