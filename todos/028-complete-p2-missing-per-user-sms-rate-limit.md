---
status: complete
priority: p2
issue_id: "028"
tags: [code-review, security, rate-limit, twilio, cost]
dependencies: []
---

# SMS rate limit is per-phone only — single user can pivot to high Twilio bill

## Problem Statement

`includes/class-orbit-phone-verify.php:53-67` enforces "3 SMS per phone per hour" but has no per-user cap. A malicious logged-in user can submit 3 codes to phone +A, then 3 to +B, +C, +D... driving Twilio billing arbitrarily high and using the platform to send unsolicited SMS to arbitrary numbers (the body identifies as "Your Orbit verification code is...", so it's annoying-spam-grade).

Pre-existing, but PR #5 polishes the attack surface.

## Proposed Solution

Add a per-user-id cap (e.g., 5 sends/hour) in addition to the per-phone cap:

```php
$user_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at > %s",
    $user_id, $one_hour_ago
) );
if ( $user_count >= 5 ) {
    return new WP_Error( 'rate_limited', __( 'Too many verification requests. Please try again later.', 'orbit' ) );
}
```

Optionally also wire `Orbit_Rate_Limiter` for a per-IP cap as defense-in-depth across multiple-account attacks.

## Acceptance Criteria

- [ ] Per-user 5/hour cap added in `Orbit_Phone_Verify::send_code()`
- [ ] Test: same user submits 5 different phones, 6th request returns `rate_limited`
- [ ] Same `rate_limited` error code preserved for client error contract
