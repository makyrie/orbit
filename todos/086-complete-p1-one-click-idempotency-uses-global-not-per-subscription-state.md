---
status: complete
priority: p1
issue_id: "086"
tags: [code-review, bug, unsubscribe, multi-subscription, PR-24]
dependencies: []
---

# One-click unsubscribe idempotency guard uses channel-global consent state, not per-subscription

## Problem Statement

`Orbit_Routes::perform_unsubscribe()` short-circuits when `Orbit_Consent::latest_state( $user_id, 'email' ) === 'opt_out'`. The ledger state is **global per (user, channel)** — but subscriptions are **per (user, profile)**. The mismatch:

User U has subscriptions S_A and S_B (two different posters). U clicks one-click unsubscribe on poster A's email:
1. `perform_unsubscribe(S_A, ...)` runs.
2. Consent ledger records `(user_id=U, channel='email', event='opt_out')`.
3. Subscription S_A is unsubscribed.

Later U clicks one-click unsubscribe on poster B's email:
1. `perform_unsubscribe(S_B, ...)` runs.
2. The idempotency guard reads `latest_state(U, 'email')` → `'opt_out'` (from step 2 above).
3. Function returns `true` immediately — **S_B subscription is NEVER updated**.

Mail client gets HTTP 200 `unsubscribed` but S_B remains `'approved'`. User keeps getting emails from poster B.

This is a real bug for any user with multiple poster subscriptions — exactly the target demographic for Perihelion.

## Findings

- `includes/class-orbit-routes.php:516-541` — `perform_unsubscribe()` idempotency guard.
- `includes/class-orbit-consent.php:183-200` — `latest_state()` is keyed on `(user_id, channel)` only, no subscription scope.
- `includes/class-orbit-subscription.php:236-238` — `unsubscribe()` enforces `approved|pending → unsubscribed` transition; would error on already-unsubscribed.

## Proposed Solutions

**Option A — Check subscription status instead of ledger state (recommended):**

```php
if ( 'unsubscribed' === $subscription->status ) {
    // Already unsubscribed at the subscription level — idempotent no-op.
    return true;
}
$result = Orbit_Subscription::unsubscribe( $subscription->id );
if ( is_wp_error( $result ) ) {
    return $result;
}
Orbit_Consent::record( $user_id, 'email', 'opt_out', array( 'source' => $source ) );
return true;
```

Reads from the subscription row (cheap) instead of the consent ledger, and correctly scopes "already done" to this specific subscription. Still appends a consent row on every fresh unsubscribe — multiple per-subscription opt_outs in the ledger are correct because the channel is the right granularity for TCPA evidence but per-subscription action is the right granularity for the operation.

**Option B — Add per-subscription scoping to the ledger** (`subscription_id` column on consent rows). Larger change; better long-term audit shape. Defer to v1.1.

Recommend **Option A** for v1.6.0.

Also: `handle_one_click_unsubscribe` ignores the WP_Error return from `perform_unsubscribe` (`includes/class-orbit-routes.php:470`) and still returns 200 `unsubscribed`. Even with the fix above, the handler should check `is_wp_error( $result )` and return 4xx if unsubscribe failed.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-routes.php`
- Two-step form path has the same bug — also relies on the channel-global guard.

## Acceptance Criteria

- [ ] User with 2 subscriptions can unsubscribe both via one-click links.
- [ ] First unsubscribe returns 200, writes consent row, unsubscribes S_A.
- [ ] Second unsubscribe returns 200, writes a second consent row, unsubscribes S_B.
- [ ] Third unsubscribe of S_A returns 200 (idempotent — subscription is already unsubscribed; no duplicate ledger row).
- [ ] `handle_one_click_unsubscribe` propagates WP_Error from `perform_unsubscribe` to the HTTP response.
- [ ] Test added: 2-subscription unsubscribe sequence + idempotency.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by call-chain-verifier.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:444-541`
