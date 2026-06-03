---
status: complete
priority: p1
issue_id: "088"
tags: [code-review, error-handling, consent-ledger, PR-24]
dependencies: []
---

# perform_unsubscribe silently swallows Orbit_Consent::record() failures — subscription unsubscribed but no audit row

## Problem Statement

`Orbit_Routes::perform_unsubscribe()` calls `Orbit_Consent::record(...)` (`includes/class-orbit-routes.php:531`) but discards the return value. `Orbit_Consent::record()` returns `WP_Error` in legitimate failure modes:

- `orbit_consent_salt_missing` — `ORBIT_CONSENT_IP_SALT` not defined in wp-config.
- `orbit_consent_chain_conflict` — concurrent write collided on `UNIQUE KEY chain_pos`.
- (Future) `orbit_consent_insert_failed` — DB error.

In any of those cases, `Orbit_Subscription::unsubscribe()` has already updated the subscription row at line 525 — so the subscription is `unsubscribed` but the ledger has no `opt_out` event. The function then returns `true` to the caller, which echoes `unsubscribed` to the user.

This is the exact TCPA defense gap the ledger is supposed to close: "show evidence the user opted out" → ledger is empty → defense fails.

## Findings

- `includes/class-orbit-routes.php:516-541` — `perform_unsubscribe()`.
- `includes/class-orbit-consent.php:87-174` — return values of `record()`.

## Proposed Solutions

**Option A — Check return, log on error, propagate (recommended):**

```php
$consent_result = Orbit_Consent::record( $user_id, 'email', 'opt_out', array( 'source' => $source ) );

if ( is_wp_error( $consent_result ) ) {
    error_log( sprintf(
        'Orbit_Consent::record failed during unsubscribe for user %d: %s',
        $user_id,
        $consent_result->get_error_message()
    ) );

    // For chain_conflict specifically, retry once with refreshed prev_hash.
    if ( 'orbit_consent_chain_conflict' === $consent_result->get_error_code() ) {
        $consent_result = Orbit_Consent::record( $user_id, 'email', 'opt_out', array( 'source' => $source ) );
    }
}

// Continue regardless — the subscription is already updated and the
// caller's UX should not change. But the error log entry gives ops
// signal that the audit row was missed.
```

**Option B — Wrap the subscription update + ledger write in a `$wpdb->query('START TRANSACTION')` block** so both succeed or both roll back. Cleaner but heavier.

Recommend **Option A** for v1.6.0 (the ledger failure should not block the user's operational unsubscribe; logging gives ops a path to reconcile manually).

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-routes.php`
- Similar pattern likely needed in any future caller of `Orbit_Consent::record()` (the new STOP/START code in todo 085 should follow the same pattern).

## Acceptance Criteria

- [ ] `perform_unsubscribe` checks `Orbit_Consent::record()` return.
- [ ] `WP_Error` from `record()` is logged to error_log with user_id + error code.
- [ ] `orbit_consent_chain_conflict` retries once with a refreshed prev_hash.
- [ ] Operational unsubscribe still succeeds even if the consent record fails.
- [ ] Test added: simulate ledger failure (e.g., missing salt), verify error_log captures it.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by data-migration-expert.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:516-541`
