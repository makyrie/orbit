---
status: complete
priority: p2
issue_id: "099"
tags: [code-review, hooks, observability, PR-24]
dependencies: []
---

# orbit_notification_coerced fires before filter — final method may differ from coerced signal

## Problem Statement

Three related observability-hook issues from wp-hooks-reviewer:

1. **`orbit_notification_coerced` fires inside `resolve_notification_method()` BEFORE the `orbit_notification_method` filter.** A third-party filter that flips `email` back to `sms` (or vice versa) makes the audit signal wrong: the coerced event was logged but the final dispatch isn't actually coerced. Conversely, a filter that coerces `sms → email` for its own reasons never fires the action.

2. **`orbit_notification_sent` and `orbit_notification_failed` fire per `process_immediate_notification()` invocation** but ActionScheduler can retry on transient failure. Two `_sent` (or `_sent` + `_failed`) actions fire for "one notification" from an observer's perspective. No idempotency token.

3. **`orbit_notification_sent` is semantically misleading** — fires after `wp_mail()` / `Orbit_Twilio::send_sms()` returns 2xx, which means "accepted for delivery," not "delivered." A future v1.1 delivery callback (via SendGrid event webhook) would be the appropriate place to fire a separate `_delivered` action. Current naming forecloses that.

## Proposed Solutions

**Option A — Tighten semantics + names (recommended):**

1. Move `orbit_notification_coerced` to fire AFTER the filter when `$pre_filter === 'sms' && $final !== 'sms'`. The audit signal then reflects the final outcome.
2. Add an idempotency token to the action payload: `do_action( 'orbit_notification_sent', $user_id, $activity_id, $method, $log_id, $idempotency_key )` where the key is e.g., `"{$user_id}|{$activity_id}|{$method}"`. Consumers can dedupe.
3. Rename `orbit_notification_sent` to `orbit_notification_dispatched` to make "accepted by upstream provider" semantics explicit. Document in the docblock that delivery-confirmation will be a separate `_delivered` hook in v1.1.

**Option B — Document the gotchas in docblocks; leave behavior as-is.** Easier in v1.6.0 but the naming foreclosure problem (issue 3) remains.

Recommend **Option A** for v1.6.0 — hook names are public API; renaming later is more painful than getting the naming right at launch.

## Acceptance Criteria

- [ ] `orbit_notification_coerced` fires after the filter, based on pre-vs-final method delta.
- [ ] Action payload includes an idempotency key.
- [ ] `_sent` renamed (or docblock explicitly disclaims delivery-confirmation semantics).
- [ ] Test updated: existing kill-switch test still passes with the post-filter timing.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-hooks-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:226-247, 581-613`
