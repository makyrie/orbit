---
status: complete
priority: p2
issue_id: "119"
tags: [code-review, PR-26, performance, async]
dependencies: []
---

# wp_send_new_user_notifications blocks the REST response on SMTP

## Problem Statement

Both subscribe and signup call `wp_send_new_user_notifications( $user_id, 'user' )` synchronously *after* the transaction COMMIT but *before* returning the `WP_REST_Response`. That function hits `wp_mail` → `PHPMailer::send()`, which performs blocking SMTP I/O. Typical latency is 100-500ms; a misconfigured SMTP relay can hang for tens of seconds.

The browser-side JS has a 30s `fetch` timeout. If mail hangs longer than that, the user sees a "timeout" error in the form — but the account WAS created, the auth cookie WAS set, and the consent ledger row WAS written. The user retries, gets `existing_user_email` / `409`, and ends up in the "log in instead" branch, confused.

## Findings

- `includes/class-orbit-rest-subscription.php:342` — `wp_send_new_user_notifications( $user_id, 'user' )` called inline before the response.
- `includes/class-orbit-rest-signup.php:262` — same pattern.
- The codebase already uses ActionScheduler for other async work; the wiring exists.
- `orbit.php:139-145` is the natural location to register the async handler.
- Surfaced by call-chain-verifier (finding #9) during multi-agent review.

## Proposed Solutions

**Option A — ActionScheduler dispatch (recommended).**

```php
as_schedule_single_action(
    time(),
    'orbit_send_new_user_notification',
    [ 'user_id' => $user_id ],
    'orbit'
);
```

Register a handler in `orbit.php` that simply calls `wp_send_new_user_notifications( $args['user_id'], 'user' )`. The REST response returns instantly; mail goes out on the next scheduler tick.

Effort: low. Risk: low — the call is fire-and-forget and we already use AS elsewhere.

**Option B — `wp_schedule_single_event` with a near-future timestamp.** Works without ActionScheduler but is less reliable, has no retry semantics, and is harder to inspect operationally.

## Recommended Action

Option A. We already depend on ActionScheduler; this is a one-line change at the call site plus a small registered handler.

## Technical Details

- The user-meta required for the welcome email (locale, display name) is already persisted before COMMIT, so the deferred job has everything it needs.
- The handler should be idempotent-safe — if the same job runs twice, the user gets two welcome emails but no state corruption. Acceptable for now; document this.
- Make sure the AS group is `orbit` so it shows up in the existing Tools → Scheduled Actions filter.
- The auth cookie is set before the response (unchanged); only the email send moves async.

## Acceptance Criteria

- [ ] REST responses for `/orbit/v1/signup` and `/orbit/v1/subscribe` return without waiting on `wp_mail`.
- [ ] `orbit_send_new_user_notification` handler is registered in `orbit.php` alongside other AS handlers.
- [ ] Welcome email still goes out on the next scheduler tick under normal conditions.
- [ ] Manual test: configure SMTP to hang (point at a black-hole host), confirm the REST response still returns within ~1s.
- [ ] AS job appears in the `orbit` group in Tools → Scheduled Actions.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Implemented Option A. New handler class `Orbit_User_Notifications`
  in `includes/class-orbit-user-notifications.php` wraps
  `wp_send_new_user_notifications( $user_id, 'user' )` behind a deleted-user
  guard. Registered the `orbit_send_new_user_notification` AS hook in
  `orbit.php` alongside `Orbit_Notifier::register_hooks()` and added it to
  the deactivation unschedule list. Replaced the synchronous calls in
  `Orbit_REST_Signup::handle_signup()` and
  `Orbit_REST_Subscription::handle_subscribe()` with
  `as_schedule_single_action( time(), 'orbit_send_new_user_notification',
  array( 'user_id' => $user_id ), 'orbit' )`, with a `function_exists`
  fallback to the synchronous call if AS is somehow not loaded. Added one
  test per controller (`test_happy_path_defers_welcome_email_to_action_scheduler`)
  that hooks `wp_new_user_notification_email` to assert the sync path did
  NOT fire and, when AS is available, calls `as_has_scheduled_action` to
  confirm the job was enqueued under the `orbit` group. Updated
  `tests/bootstrap.php` to require AS's `action-scheduler.php` on
  `muplugins_loaded` so the AS function family is declared during tests.
  Full suite: 188 tests, 518 assertions, 1 pre-existing incomplete. Green.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php:342`
- `includes/class-orbit-rest-signup.php:262`
- `orbit.php:139-145`
