---
status: complete
priority: p2
issue_id: "095"
tags: [code-review, rfc-8058, dead-code, PR-24]
dependencies: []
---

# is_one_click_unsubscribe_post checks HTTP_LIST_UNSUBSCRIBE_POST header — RFC 8058 doesn't define such a request header

## Problem Statement

`Orbit_Routes::is_one_click_unsubscribe_post()` has two branches:

1. `$_POST['List-Unsubscribe'] === 'One-Click'` — correct per RFC 8058 §3.2.
2. `$_SERVER['HTTP_LIST_UNSUBSCRIBE_POST']` containing 'One-Click' — **looking for a request header that no client sends**.

`List-Unsubscribe-Post` is a HEADER THE SENDER PUTS ON THE EMAIL, telling mail clients that one-click is allowed. Mail clients then POST `List-Unsubscribe=One-Click` in the request body — they do NOT echo `List-Unsubscribe-Post` back as a request header.

Branch 2 is dead code that adds attack surface for nothing:

- If a misconfigured CDN ever reflected response headers into request headers (unlikely but documented), an attacker could flip an arbitrary form POST into a "one-click" POST, bypassing the nonce check that the two-step form requires.
- The HMAC token still has to be valid for the target subscription, so this isn't a self-spam attack. But the rate limit is per-IP only, not per-token, so an attacker with one valid token from a leaked email could replay without nonce protection and burn through rate budget for legit users on shared NAT.

Flagged by both security-sentinel and call-chain-verifier.

## Proposed Solutions

**Option A — Remove branch 2 (recommended):**

```php
private static function is_one_click_unsubscribe_post() {
    return isset( $_POST['List-Unsubscribe'] )
        && is_string( $_POST['List-Unsubscribe'] )
        && 'One-Click' === wp_unslash( $_POST['List-Unsubscribe'] );
}
```

Strict RFC 8058 conformance. Eliminates the dead branch.

**Option B — Replace branch 2 with `Content-Type: application/x-www-form-urlencoded` check** for stricter validation that the request shape matches what RFC 8058 mandates.

Recommend **Option A**. RFC 8058 §3.2 is specific; we shouldn't be more permissive than the spec.

Also: add `is_string()` guard so `List-Unsubscribe[]=One-Click` (array form) doesn't crash strict-equality.

## Acceptance Criteria

- [ ] Branch 2 removed.
- [ ] `is_string()` guard added.
- [ ] Test: POST with `List-Unsubscribe-Post` header but no body field → NOT recognized as one-click.
- [ ] Test: POST with array-form `List-Unsubscribe[]=One-Click` → NOT recognized as one-click.
- [ ] Test: POST with body field `List-Unsubscribe=One-Click` → IS recognized.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel, call-chain-verifier.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:553-567`
- [RFC 8058 §3.2](https://datatracker.ietf.org/doc/html/rfc8058#section-3.2)
