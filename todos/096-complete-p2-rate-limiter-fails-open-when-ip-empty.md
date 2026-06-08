---
status: complete
priority: p2
issue_id: "096"
tags: [code-review, rate-limit, security, PR-24]
dependencies: []
---

# One-click unsubscribe rate limit is skipped entirely when client IP can't be resolved

## Problem Statement

`Orbit_Routes::handle_one_click_unsubscribe()` rate-limits like this:

```php
$ip = Orbit_Client_IP::get();
if ( '' !== $ip && ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click', $ip, 30, MINUTE_IN_SECONDS ) ) {
    // 429
}
```

When `$ip === ''`, the rate-limit code is SKIPPED — fails open. Empty-IP scenarios in production are not just "CLI":

- Misconfigured proxy filter (`orbit_client_ip_header` filter returns an empty header).
- Some FastCGI configs / HHVM occasionally produce empty `REMOTE_ADDR`.
- Nginx `fastcgi_pass` without `REMOTE_ADDR` propagation.

An attacker who crafts requests that strip identifying headers gets unlimited one-click attempts.

## Proposed Solutions

**Option A — Fall back to a session-scoped lower-budget limit (recommended):**

```php
if ( '' === $ip ) {
    // Use a global anon bucket with a much tighter cap.
    if ( ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click', '_anon', 5, MINUTE_IN_SECONDS ) ) {
        // 429
    }
} elseif ( ! Orbit_Rate_Limiter::attempt( 'unsubscribe_one_click', $ip, 30, MINUTE_IN_SECONDS ) ) {
    // 429
}
```

**Option B — Reject anonymous (empty-IP) requests with 400.** Defensible since a legitimate mail-client POST always has REMOTE_ADDR.

Recommend **Option A**: lower budget but not zero, since strict rejection might block legitimate one-click POSTs in certain proxy configurations.

## Acceptance Criteria

- [ ] Empty IP falls back to a global anon rate limit (e.g., 5/min).
- [ ] Resolved IP still gets 30/min.
- [ ] Test: empty IP, 6 POSTs in a minute → 6th returns 429.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:444-476`
