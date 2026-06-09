---
status: complete
priority: p2
issue_id: "130"
tags: [code-review, PR-26, refactor, architecture]
dependencies: []
---

# Extract Orbit_User_Provisioning service to deduplicate signup/subscribe transactions

## Problem Statement

`Orbit_REST_Subscription::handle_subscribe()` and `Orbit_REST_Signup::handle_signup()` wrap nearly-identical transactional sequences:

1. `wp_insert_user` / `wp_create_user` (with retry-on-existing-username only in signup).
2. Multisite `add_user_to_blog`.
3. `orbit_timezone` meta write.
4. Optional `orbit_phone_pending` write.
5. One or two `Orbit_Consent::record()` calls.
6. COMMIT or ROLLBACK.
7. Deferred `wp_send_new_user_notifications` (todo 119 makes this async).
8. `wp_set_auth_cookie`.

This is duplicated across ~100 lines of subscribe and signup. Only two legitimate divergences exist:

- Subscribe additionally writes the subscription row and `Orbit_Notifier::get_or_create_preferences`.
- Signup has the retry-on-`existing_user_login` loop.

Risks today: a bug fixed in one handler can be silently re-introduced in the other; the transactional boundary is owned in two places; CLI commands (todos 121, 122) will duplicate the logic a third and fourth time.

## Findings

- `includes/class-orbit-rest-subscription.php:225-332` — first copy of the sequence.
- `includes/class-orbit-rest-signup.php:148-261` — second copy with the username-retry variant.
- Future CLI signup (todo 122) and CLI subscription parity (todo 121) would add two more copies.
- Surfaced by architecture-strategist (finding #4) during multi-agent review.

## Proposed Solutions

**Option A — Service class with a single entry point (recommended).** Create `includes/class-orbit-user-provisioning.php`:

```php
class Orbit_User_Provisioning {
    /**
     * @param array $userdata Compatible with wp_insert_user.
     * @param array $consents [ ['channel' => 'email', ...], ... ]
     * @param array $opts     [ 'retry_on_existing_username' => bool, 'phone_pending' => string, ... ]
     */
    public static function create_user_with_consent( array $userdata, array $consents, array $opts ): int|\WP_Error;
}
```

The service owns the transaction boundary, the retry logic (gated on `$opts`), and all common meta writes. The two REST handlers shrink to ~30 lines each: parse request → call service → format response. CLI commands route through the same service.

Effort: medium (~200 LOC new code, two handlers shrunk by ~70 each). Risk: medium — needs careful tests so the refactor doesn't regress consent stamping.

**Option B — Trait-based extraction.** Share via a `Trait_Orbit_Provisioning` mixed into both handlers. Cheaper but less clean; trait state and method overrides are surprising.

## Recommended Action

Option A. Pair with todo 116 (carrier exception lives next to the service), todo 131 (compliance UI extraction so the service can call helpers without reaching into a shortcode class), and todos 121/122 (CLI parity routes through the service).

## Technical Details

- The service should accept a callable `$opts['post_create']` so subscribe can plug in its subscription-row + notifier-preferences step without leaking subscribe-specific code into the service.
- Treat the service as the single owner of START TRANSACTION / COMMIT / ROLLBACK — handlers must not wrap the call in their own transaction.
- The service throws `Orbit_RolledBack_Exception` on failure (composes with todo 116).
- Consent stamping happens inside the transaction; if a consent insert fails, the user creation rolls back.
- Add PHPUnit coverage that exercises both branches (subscribe-shaped and signup-shaped) via the service directly.

## Acceptance Criteria

- [ ] `Orbit_User_Provisioning::create_user_with_consent()` exists and is called by both REST handlers.
- [ ] Subscribe and signup handlers shrink to a thin parse-validate-call-format shape.
- [ ] No duplicated transaction boundaries — only the service owns BEGIN/COMMIT/ROLLBACK.
- [ ] Consent ledger writes for subscribe and signup are byte-identical for equivalent inputs (verified by the todo 124 test).
- [ ] CLI commands (todos 121, 122) route through the same service.
- [ ] PHPUnit coverage exercises the service directly, not only via REST.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Extracted `Orbit_User_Provisioning::create_user_with_consent()` into a
  new file at `includes/class-orbit-user-provisioning.php` (298 LOC). The service
  owns the full transactional envelope (`START TRANSACTION` → `wp_insert_user`
  with optional retry-on-`existing_user_login` → multisite `add_user_to_blog` →
  `orbit_timezone` + optional `orbit_phone_pending` meta → per-channel
  `Orbit_Consent::record` → `COMMIT` / `ROLLBACK` via
  `Orbit_Rolled_Back_Exception`). Loader entry added to `orbit.php` after the
  three dependencies (`Orbit_Consent`, `Orbit_Notifier`,
  `Orbit_Rolled_Back_Exception`).

  Collapsed call sites:
  - `includes/class-orbit-rest-signup.php::handle_signup()` (351 → 285 lines):
    new-account creation now routes through the service. Email-race handling
    (`existing_user_email` → 409 `login_required`), retry-loop exhaustion
    (`user_creation_failed` → 503), and rollback error-code preservation all
    still work because the service returns the original WP_Error code.
  - `includes/class-orbit-rest-subscription.php::handle_subscribe()` (663 →
    662 lines): the new-account branch routes through the service; the
    existing-logged-in branch keeps its own minimal consent-stamping
    transaction (matches todo guidance — the service is "create user + stamp
    consent", not "stamp consent on existing user"). Subscription row +
    `Orbit_Notifier::get_or_create_preferences` are written post-provisioning
    because they're subscribe-specific. The early-return-if-anonymous-and-
    email-exists guard moved above the transaction.
  - `cli/class-orbit-cli-signup.php::create()` (252 → 221 lines): routes
    through the service with `send_welcome_email` controlled by the
    `--send-welcome-email` flag and `schedule_welcome_async=false` so the
    CLI process delivers synchronously when requested.
  - `cli/class-orbit-cli-subscription.php`: NOT touched. Confirmed by reading
    the file — CLI subscribe only attaches existing user IDs to profiles; it
    never creates users. The provisioning service is "create user + stamp
    consent" and doesn't apply to the existing-user-attach flow.

  Tests added (`tests/OrbitUserProvisioningTest.php`, 300 LOC):
  - Happy path: `create_user_with_consent` returns int user_id, stamps the
    email ledger row, writes `orbit_timezone` meta.
  - Rollback path: forced `orbit_consent_salt_missing` via the
    `orbit_consent_ip_salt_resolved` filter triggers ROLLBACK; no `wp_users`
    row survives (verified via direct `SELECT COUNT(*)` to bypass WP's user
    object cache).
  - Retry path: pre-existing `user_login` collision with
    `username_retry_attempts=5` resolves via suffix retry; returned user_id
    differs from pre-existing one and new login shares the original base
    prefix.
  - Cache eviction: rollback-path `forget_preferences` is exercised by
    seeding the cache from a `user_register` hook then dispatching the
    rolled-back service call.

  Full suite: `vendor/bin/phpunit` reports 228 tests, 635 assertions,
  1 skipped (the same canary-deferral skip the baseline had). 4 new tests
  added on top of 224 baseline = 228 total.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php:225-332`
- `includes/class-orbit-rest-signup.php:148-261`
- New: `includes/class-orbit-user-provisioning.php`
- Related: todos 116 (carrier exception), 121, 122 (CLI parity), 131 (compliance UI), 124 (snapshot test)
