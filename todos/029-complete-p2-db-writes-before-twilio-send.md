---
status: complete
priority: p2
issue_id: "029"
tags: [code-review, transactions, twilio, phone-verify]
dependencies: []
---

# `send_code()` writes DB row + user meta before calling Twilio — leaves phantom records on failure

## Problem Statement

`includes/class-orbit-phone-verify.php:74-102` order:

1. `$wpdb->insert(...)` — writes verification row (line 74)
2. `update_user_meta('orbit_phone', $phone)` (line 88)
3. `update_user_meta('orbit_phone_verified', 0)` (line 89)
4. `Orbit_Twilio::send_sms(...)` (line 98) — may fail (twilio_not_configured, twilio_api_error, network)

If Twilio fails, the row + meta are persisted, the rate limiter ticks up, but no SMS arrives. After 3 failed attempts the user hits the per-phone rate limit with zero codes received and has no recourse for an hour.

Pre-existing. PR #5's UI makes it visible to non-CLI users for the first time.

## Proposed Solution

Either:
- (a) Call `Orbit_Twilio::send_sms()` first; only persist on success.
- (b) On Twilio failure, delete the just-written row and clear the user meta before returning the error.

Option (a) is cleaner but reorders side effects. Option (b) is local and conservative.

## Acceptance Criteria

- [ ] Twilio failure leaves `wp_orbit_phone_verification` unchanged
- [ ] Twilio failure leaves `orbit_phone` user_meta unchanged
- [ ] Test: define `ORBIT_TWILIO_SID` to an invalid value, submit a phone, verify no row appears in `wp_orbit_phone_verification` and `orbit_phone` is unchanged
