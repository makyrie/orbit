---
status: pending
priority: p2
issue_id: "126"
tags: [code-review, PR-26, ux, data-integrity]
dependencies: []
---

# orbit_phone_pending can be overwritten for logged-in resubscribers via hand-crafted REST

## Problem Statement

`Orbit_REST_Subscription::handle_subscribe()` unconditionally writes `orbit_phone_pending` whenever a non-empty `phone` arrives in the request body. This includes the existing-logged-in-user branch, where the rendered subscribe form does not even render a phone field, so a normal browser session never sends one.

But a hand-crafted REST POST (or a future form variant) WILL hit that code path. A logged-in user with a verified `orbit_phone` who issues a subscribe POST with a `phone` field will have a new `orbit_phone_pending` written, even though their verified number is untouched. On `/settings/`, the user is then shown the misleading "we have this number on file from your sign-up but it's not verified yet" notice — for a number they never knowingly submitted.

## Findings

- `includes/class-orbit-rest-subscription.php:277-282` — `update_user_meta( $user_id, 'orbit_phone_pending', $phone )` runs without checking which branch (new vs existing) we're in.
- `includes/class-orbit-rest-subscription.php:234-247` — the existing-logged-in-user branch does not need this write.
- `includes/class-orbit-shortcodes.php:528-537` — settings UI surfaces the pending notice from `orbit_phone_pending` presence.
- Surfaced by call-chain-verifier (finding #5) during multi-agent review.

## Proposed Solutions

**Option A — Gate on branch (recommended).** Move the `orbit_phone_pending` write inside the new-account branch only. The existing-logged-in branch leaves phone state alone.

Effort: trivial. Risk: low.

**Option B — Refuse to overwrite verified.** Keep the write but skip it when `get_user_meta( $user_id, 'orbit_phone_verified', true )` is truthy. Defense-in-depth, but doesn't address the case where a hand-crafted POST overwrites an existing pending number with a different one.

## Recommended Action

Option A plus the Option B guard as a belt-and-braces safeguard. The combined effect: pending phone can only be written in the new-account branch, and even there, never over a verified number.

## Technical Details

- The new-account branch is identifiable by the `! $existing_user` condition that already drives whether `wp_insert_user` runs.
- Add a sanity log when a logged-in branch receives a `phone` field — likely a misuse or a hand-crafted client we want to know about.
- Update PHPUnit coverage to exercise both branches with and without a `phone` body field.

## Acceptance Criteria

- [ ] `orbit_phone_pending` is never written by the existing-logged-in-user branch.
- [ ] A verified `orbit_phone` is never overwritten by `orbit_phone_pending`.
- [ ] A logged-in subscribe with a `phone` in the body logs a warning (suspicious request shape).
- [ ] PHPUnit test: logged-in resubscriber POSTs with a phone; assert no `orbit_phone_pending` row appears.
- [ ] PHPUnit test: new account POSTs with a phone; assert `orbit_phone_pending` is written.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php:277-282, 234-247`
- `includes/class-orbit-shortcodes.php:528-537`
