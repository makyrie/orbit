---
status: pending
priority: p1
issue_id: "003"
tags: [code-review, security, performance]
dependencies: []
---

# Action Token Validation is O(N) Linear Scan — DoS Vector

## Problem Statement

When validating an action token (from SMS/email links), the code fetches ALL approved subscriptions for the profile (up to 9,999 rows) and iterates through each, computing HMAC-SHA256 for each one. For a profile with 1,000 subscribers, this means 1,000 HMAC computations and a full table scan per response submission. This is a denial-of-service vector on a public endpoint with only 30/hr rate limiting.

The same O(N) scan is duplicated in the shortcode activity page.

## Findings

- **Security sentinel (#1):** DoS vector + timing side-channel leaking subscriber count
- **Performance oracle (#3):** O(n) in subscriber count on every unauthenticated response
- **Architecture strategist (#7):** Token design requires linear scan by omitting subscription identity
- **Call-chain verifier (C-003-A):** Confirmed the scan spans both REST API and shortcode
- **PHP reviewer (#2):** Duplicated in `class-orbit-rest-api.php:557-570` and `class-orbit-shortcodes.php:796-809`

## Proposed Solutions

### Option A: Embed subscription_id in action token format
Change from `{base64(expiry)}:{hmac}` to `{subscription_id}.{base64(expiry)}:{hmac}`. Look up subscription by ID in O(1), then verify HMAC against that single record.
- **Pros:** O(1) validation, eliminates DoS vector
- **Cons:** Token is slightly longer, subscription_id is exposed (but it's an opaque integer)
- **Effort:** Medium
- **Risk:** Low — backwards-incompatible with existing tokens, but no tokens exist yet (greenfield)

## Acceptance Criteria

- [ ] Token validation uses direct subscription lookup (single query)
- [ ] Token format includes subscription identifier
- [ ] Both REST API and shortcode use the same validation path
- [ ] Invalid tokens rejected without scanning all subscriptions
