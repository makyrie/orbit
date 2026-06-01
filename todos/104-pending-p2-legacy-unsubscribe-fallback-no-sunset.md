---
status: pending
priority: p2
issue_id: "104"
tags: [code-review, security, unsubscribe, PR-24]
dependencies: []
---

# Legacy raw-secret unsubscribe fallback has no expiry — leaked email spool valid forever

## Problem Statement

`Orbit_Routes::resolve_unsubscribe_subscription()` falls back to `Orbit_Subscription::get_by_secret( $token )` when the modern HMAC parser can't read the token (intentional — covers emails sent before the cutover).

The new HMAC unsubscribe token has a 1-year expiry — explicitly designed to bound the leaked-mail-spool blast radius. The legacy fallback completely defeats that bound: an email sent before the cutover is valid **forever** unless the user re-subscribes (which doesn't rotate `subscription_secret`).

There's no sunset date documented, no telemetry to show when legacy traffic stops, and no admin control to forcibly retire the legacy path.

## Proposed Solutions

**Option A — Time-box the fallback (recommended):**

Add a constant:

```php
defined( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET' ) || define( 'ORBIT_LEGACY_UNSUB_TOKEN_SUNSET', '2027-06-01' );
```

In `resolve_unsubscribe_subscription`:

```php
if ( time() >= strtotime( ORBIT_LEGACY_UNSUB_TOKEN_SUNSET . ' UTC' ) ) {
    return null; // Legacy fallback sunset.
}
return Orbit_Subscription::get_by_secret( $token );
```

12-month sunset matches the new HMAC token's 1-year expiry — by then any legitimate user with a legacy-format email link has either used it or it's older than the new format's TTL anyway.

Also: log every legacy-fallback hit to `error_log()` or to `wp_orbit_notification_log` with `status='legacy_unsub'`, so ops can see when legacy traffic actually stops and verify the sunset is safe.

**Option B — Rotate `subscription_secret` on every re-subscribe.** Doesn't help the leaked-email-spool case; the original secret stays valid.

Recommend **Option A** plus telemetry.

## Acceptance Criteria

- [ ] `ORBIT_LEGACY_UNSUB_TOKEN_SUNSET` constant defined with sunset date.
- [ ] Resolver returns null after sunset.
- [ ] Legacy hits logged.
- [ ] README documents the sunset.
- [ ] Test added: with sunset in past, legacy resolver returns null.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel, architecture-strategist.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:478-502`
