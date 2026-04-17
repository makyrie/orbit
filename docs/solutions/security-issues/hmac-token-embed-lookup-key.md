---
title: "HMAC action tokens must embed the lookup key to avoid O(N) brute-force validation"
category: security-issues
problem_type: security_vulnerability
severity: high
components: [Orbit_Token, Orbit_REST_Activity]
tags: [hmac, token-design, dos-prevention, authentication]
date_discovered: 2026-03-24
date_resolved: 2026-03-24
---

# HMAC Tokens Must Embed the Lookup Key

## Problem

Token format `{base64(expiry)}:{hmac}` didn't encode which subscription it belonged to. Validation required fetching ALL approved subscriptions for a profile and computing HMAC-SHA256 for each one until a match was found. For N subscribers, every unauthenticated request triggered N HMAC computations — a DoS vector on a public endpoint. Timing differences also leaked subscriber count as a side-channel.

## Root Cause

The token format was identity-less. The HMAC was keyed on a per-subscription secret, but the token itself didn't say *which* subscription's secret to use. This forced brute-force scanning.

## Working Solution

**New format:** `{subscription_id}.{base64(expiry)}:{hmac}`

The subscription ID is embedded as a prefix. Validation extracts the ID in O(1), fetches the single subscription by primary key, and verifies the HMAC against that one secret.

```php
// Generate: embed subscription_id as prefix
return $subscription_id . '.' . base64_encode( (string) $expiry ) . ':' . $hmac;

// Extract: O(1) lookup key extraction
public static function extract_subscription_id( $token ) {
    $dot_pos = strpos( $token, '.' );
    return false === $dot_pos ? null : absint( substr( $token, 0, $dot_pos ) );
}

// Validate: single subscription lookup + single HMAC check
$sub_id = Orbit_Token::extract_subscription_id( $token );
$subscription = Orbit_Subscription::get( $sub_id );  // O(1) PK lookup
Orbit_Token::validate_action_token( $token, $subscription->subscription_secret, $activity_id );
```

The subscription ID prefix cannot be tampered with — changing it would invalidate the HMAC since the HMAC is keyed on the subscription's secret.

## Key Insight

When designing token formats for systems where validation requires looking up a record, **always embed the lookup key in the token**. The HMAC ensures integrity — the identity prefix cannot be spoofed. This is the same principle behind JWT's `sub` claim or API key prefixes that encode tenant ID.

## References

- Fix commit: `48b45cb`
- Affected files: `includes/class-orbit-token.php`, `includes/class-orbit-rest-activity.php`
- Todo: `todos/003-complete-p1-action-token-linear-scan.md`
