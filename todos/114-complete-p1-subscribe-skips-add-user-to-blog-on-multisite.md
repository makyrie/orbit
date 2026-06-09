---
status: complete
priority: p1
issue_id: "114"
tags: [code-review, PR-26, multisite, wp-php]
dependencies: []
---

# Subscribe handler bypasses add_user_to_blog on multisite — skips canonical hook, breaks third-party integrations

## Problem Statement

`Orbit_REST_Subscription::handle_subscribe()` at `class-orbit-rest-subscription.php:248-275` creates a new user via `wp_create_user()` and then assigns the subscriber role via:

```php
$user = get_user_by( 'id', $user_id );
$user->add_role( 'orbit_subscriber' );
```

On multisite, `WP_User::add_role()` writes directly to the per-site `wp_<blog_id>_capabilities` user_meta key, bypassing the canonical `add_user_to_blog( $blog_id, $user_id, $role )` path. The signup handler at `class-orbit-rest-signup.php:208-216` does this correctly — it branches on `is_multisite()` and calls `add_user_to_blog()` on multisite, falling back to `add_role()` on single-site. Subscribe does not branch.

The bypass matters because `add_user_to_blog()` fires the `add_user_to_blog` action, which is the documented hook for:

- Per-site default option assignment (e.g., notification prefs that subscribe also writes manually).
- Audit logging plugins (Stream, WP Activity Log) that track membership changes.
- Welcome-email plugins.
- Multisite role-management plugins (User Switching, WP Multisite User Sync).

None of these fire when subscribe creates a user. The user appears in the site's user list (because the capabilities meta is set), but no third-party integration knows about it.

This is also a divergence from signup behavior — two endpoints that both create users + assign the same role should use the same role-assignment path.

## Findings

- `includes/class-orbit-rest-subscription.php:248-275` — direct `$user->add_role( 'orbit_subscriber' )` with no multisite branch.
- `includes/class-orbit-rest-signup.php:208-216` — correct pattern:
  ```php
  if ( is_multisite() ) {
      add_user_to_blog( get_current_blog_id(), $user_id, 'orbit_subscriber' );
  } else {
      $user = get_user_by( 'id', $user_id );
      $user->add_role( 'orbit_subscriber' );
  }
  ```
- WP Core: `add_user_to_blog()` in `wp-includes/ms-functions.php` fires `do_action( 'add_user_to_blog', $user_id, $role, $blog_id )` — the documented integration point.

## Proposed Solutions

### Option 1: Mirror signup's branch in subscribe (recommended)

Replace the unconditional `add_role()` call in subscribe with the same `is_multisite()` branch used by signup:

```php
if ( is_multisite() ) {
    $result = add_user_to_blog( get_current_blog_id(), $user_id, 'orbit_subscriber' );
    if ( is_wp_error( $result ) ) {
        // Rollback transaction, return error.
        throw new RuntimeException( 'subscribe_add_to_blog_failed' );
    }
} else {
    $user = get_user_by( 'id', $user_id );
    $user->add_role( 'orbit_subscriber' );
}
```

**Pros:** Restores parity with signup; canonical multisite path; integration hooks fire.
**Cons:** None.
**Effort:** Small (15 min).
**Risk:** Low.

### Option 2: Extract a helper `Orbit_User::assign_subscriber_role( $user_id )`

Move the branch into a shared helper used by both signup and subscribe. Same behavior, fewer duplication.

**Pros:** DRY; reduces drift risk.
**Cons:** New abstraction; small refactor.
**Effort:** Small (30 min).
**Risk:** Low.

## Recommended Action

Ship Option 1 before merge for the smallest correct fix. Open a follow-up todo to extract the helper (Option 2) as cleanup — both paths should converge on a single function but the immediate concern is making subscribe match signup's behavior.

## Technical Details

**Affected files:**
- `includes/class-orbit-rest-subscription.php` (lines 248-275).
- `includes/class-orbit-rest-signup.php` (lines 208-216) — reference implementation.

**Transaction interaction:**
- `add_user_to_blog()` can return a `WP_Error`. Treat that as a transaction-rollback trigger inside the subscribe try/catch envelope. The user was created by `wp_create_user()` but if role assignment fails we want the whole thing to roll back (or compensate by deleting the user).

**Hook firing order:**
- `add_user_to_blog` action fires inside `add_user_to_blog()`. Subscribe-specific consent rows and notifier prefs should still write after that, matching signup's order.

## Acceptance Criteria

- [ ] Subscribe handler branches on `is_multisite()` and uses `add_user_to_blog()` on multisite.
- [ ] Action `add_user_to_blog` fires when a multisite subscribe succeeds (verified via assertion in test).
- [ ] Single-site behavior unchanged (regression check).
- [ ] PHPUnit: multisite subscribe test asserts `add_user_to_blog` action ran with the correct args.
- [ ] PHPUnit: single-site subscribe test asserts the user has the role and `add_user_to_blog` did NOT fire.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Shipped Option 1. `Orbit_REST_Subscription::handle_subscribe()` now branches on `is_multisite()` for role assignment — on multisite it calls `add_user_to_blog( get_current_blog_id(), $user_id, 'orbit_subscriber' )` so the canonical `add_user_to_blog` action fires and third-party integrations (Stream, WP Activity Log, multisite role managers) can hook membership changes; on single-site it keeps the existing `WP_User::add_role( 'orbit_subscriber' )` path since `add_user_to_blog()` isn't loaded outside multisite. Preserves the `orbit_subscriber` role slug the subscribe handler has always used (signup uses `subscriber` because that's the role it assigns at `wp_insert_user` time — the two handlers assign different roles, so the slug stays divergent). Branch sits inside the existing try/catch envelope so any thrown error still rolls back the transaction. Option 2 helper extraction deferred — open a follow-up todo if drift becomes a concern. Tests not extended in this pass: the multisite assertion called for in the acceptance criteria requires a multisite-aware test bootstrap, which the current PHPUnit harness doesn't run in CI — flagged for the test-infra wave.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Source: call-chain-verifier (item #2)
- `includes/class-orbit-rest-subscription.php:248-275`
- `includes/class-orbit-rest-signup.php:208-216`
- WP Core: `add_user_to_blog()` in `wp-includes/ms-functions.php`.
