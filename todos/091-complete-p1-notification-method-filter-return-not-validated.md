---
status: complete
priority: p1
issue_id: "091"
tags: [code-review, hooks, validation, PR-24]
dependencies: []
---

# orbit_notification_method filter return value is not validated — third-party returns can pollute the log

## Problem Statement

`Orbit_Notifier::resolve_notification_method()` ends with `return apply_filters('orbit_notification_method', $method, ...)`. The docblock asks third-party listeners to stick to `'sms'|'email'|'digest'|'none'`, but the implementation doesn't enforce that.

If a third-party filter returns `'webpush'` (or anything else), `dispatch_to_subscriber()` falls through every guarded branch (`!== 'none'`, `!== 'sms'`, `!== 'digest'`) and ends at the immediate-notification enqueue, where `process_immediate_notification()` treats `$method === 'webpush'` as not-sms and calls `send_immediate_email()`. The recipient gets an email, but the log row is written with `method='webpush'`, polluting:

- The digest query at `class-orbit-notifier.php:392` (`WHERE method = 'digest'`) — invisible.
- The SMS cap counter at `:767` (`WHERE method = 'sms'`) — invisible.
- The Twilio status callback (future v1.1) — can't correlate.

The kill-switch correctly coerces stored `'sms'` → `'email'` before the filter. But the filter's return is the final word, and bad data slips through silently.

## Findings

- `includes/class-orbit-notifier.php:613` — `return apply_filters(...)`.
- `:157-200` — `dispatch_to_subscriber` decision tree treats unknown values as "send immediate."
- `:213-220` — `process_immediate_notification` `if (sms) ... else { email }` — anything not-sms gets email but logs the original method.
- `update_preferences()` at `:687` already maintains the canonical list `array('sms','email','digest','none')`.

## Proposed Solutions

**Option A — Whitelist after the filter (recommended):**

1. Extract the canonical list to a class constant: `const VALID_METHODS = array('sms','email','digest','none');`
2. After the filter call:
   ```php
   $resolved = apply_filters( 'orbit_notification_method', $method, $user_id, $tier, $context );
   if ( ! in_array( $resolved, self::VALID_METHODS, true ) ) {
       // Fall back to the pre-filter value (already coerced through the kill-switch).
       $resolved = $method;
   }
   return $resolved;
   ```
3. Reuse the constant in `update_preferences()` validation.

**Option B — Log a warning when a filter returns an unknown value** (don't fall back; let it through with telemetry). Riskier — keeps the bug in play but at least makes it findable.

Recommend **Option A**. Single class constant, single defensive check, prevents log pollution.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-notifier.php`
- Forward-compatible: when v1.x adds `'web_push'` as a legitimate channel, it gets added to `VALID_METHODS` and filters can return it.

## Acceptance Criteria

- [ ] `VALID_METHODS` class constant defined.
- [ ] Filter return is whitelisted; unknown values fall back to pre-filter value.
- [ ] `update_preferences()` reuses the constant.
- [ ] Test added: register a filter that returns 'webpush', verify dispatcher falls back to pre-filter value.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-hooks-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:600-613`
