---
status: complete
priority: p2
issue_id: "027"
tags: [code-review, security, phone-verify]
dependencies: []
---

# Phone rebinding silently overwrites verified number with no notification

## Problem Statement

`includes/class-orbit-phone-verify.php:88-89` writes the new (unverified) phone to `orbit_phone` user_meta and clears `orbit_phone_verified` *before* the new number is verified:

```php
update_user_meta( $user_id, 'orbit_phone', $phone );
update_user_meta( $user_id, 'orbit_phone_verified', 0 );
```

Effects:
- A session-hijack attacker can rebind the victim's phone with no out-of-band notification to the original phone owner.
- The Twilio webhook handler (`class-orbit-twilio.php:113-126`) looks up users by `meta_value => $from`, so the attacker's phone is bound for STOP/START even before verification completes.
- If the user abandons the flow, `orbit_phone` ends up holding an unverified number.

Pre-existing in the unmodified backend, but the new UI in PR #5 makes this much more discoverable.

## Proposed Solution

1. Don't overwrite `orbit_phone` until verification succeeds. Store the candidate phone inside the `orbit_phone_verification` row only. Move the `update_user_meta('orbit_phone', $phone)` into `verify_code()` after `hash_equals` passes.
2. (Stretch) Send an out-of-band confirmation: email to `user_email` AND SMS to the *previous* verified phone whenever a phone change is requested. Standard practice (Stripe, Google).

## Acceptance Criteria

- [ ] `orbit_phone` user_meta is only written after successful `verify_code()`
- [ ] If a verification is pending, `Orbit_Twilio::handle_incoming` STOP/START still resolves to the previous verified phone, not the candidate
- [ ] (Optional) Email to user_email on phone change request
- [ ] Test: attacker session, change phone, abandon — verified phone unchanged
