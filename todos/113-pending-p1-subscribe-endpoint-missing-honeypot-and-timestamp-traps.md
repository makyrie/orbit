---
status: pending
priority: p1
issue_id: "113"
tags: [code-review, PR-26, security, anti-spam]
dependencies: []
---

# Subscribe form and handler missing honeypot + timestamp traps — public account-creation endpoint sprayable by botnets

## Problem Statement

The signup endpoint (`/orbit/v1/signup`) renders `Orbit_Spam::render_traps()` in its form template and calls `Orbit_Spam::check_traps()` at the top of its handler — honeypot field plus minimum-time check that rejects obvious bot submissions. The subscribe endpoint (`/orbit/v1/subscribe`) does neither.

`Orbit_Shortcodes::subscribe_form()` at `class-orbit-shortcodes.php:1437-1490` does NOT call `Orbit_Spam::render_traps()`. `Orbit_REST_Subscription::handle_subscribe()` at `class-orbit-rest-subscription.php:160-357` does NOT call `Orbit_Spam::check_traps()`. The only line of defense is the 5-per-hour-per-IP rate limit.

Both endpoints are public, anonymous, and create WordPress user accounts. Both ship password-reset / set-password emails to whatever email address the form supplies. A botnet that rotates IPs (even modestly — say 100 IPs cycling at 4/hr each) sprays 400 fake accounts per hour past the rate limiter. Each account creation triggers a transactional email, burning sender reputation and giving the attacker a vehicle for email-based abuse (the password-set link is a unique URL that, if forwarded, lets the attacker take control of the account).

This is a real attack surface. Signup has the defense; subscribe doesn't.

## Findings

- `includes/class-orbit-shortcodes.php` — `signup_form()` calls `Orbit_Spam::render_traps()` inside its `<form>`. `subscribe_form()` at lines 1437-1490 does not.
- `includes/class-orbit-rest-signup.php` — `handle_signup()` calls `Orbit_Spam::check_traps()` near the top of the handler, before any DB writes.
- `includes/class-orbit-rest-subscription.php` — `handle_subscribe()` at lines 160-357 does not call `Orbit_Spam::check_traps()`. The handler proceeds to user creation and email dispatch with only rate-limit protection.
- `Orbit_Spam::check_traps()` is endpoint-agnostic — it inspects the request payload for the honeypot field and submit-time delta. No reason it can't be called from the subscribe handler.

## Proposed Solutions

### Option 1: Mirror signup's trap usage in subscribe (recommended)

1. In `subscribe_form()`, call `Orbit_Spam::render_traps()` inside the form markup.
2. In `handle_subscribe()`, call `Orbit_Spam::check_traps( $request )` immediately after auth/nonce validation and before rate-limit check. Return a 400 on trap fail.
3. Trap rejections should not increment the rate limit counter — keep the counter clean for real humans who legitimately hit the rate limit later.

```php
$trap_check = Orbit_Spam::check_traps( $request );
if ( is_wp_error( $trap_check ) ) {
    return new WP_REST_Response( [ 'code' => 'spam_detected' ], 400 );
}
```

**Pros:** Identical defense across both account-creation endpoints; reuses existing code; tiny diff.
**Cons:** None.
**Effort:** Small (30 min).
**Risk:** Low.

### Option 2: Tighten rate limit instead

Drop subscribe to 1 per IP per hour, or add a CAPTCHA.

**Pros:** Stronger ceiling.
**Cons:** Doesn't help against IP rotation; CAPTCHA UX regression; doesn't restore parity with signup defense.
**Effort:** Medium.
**Risk:** Medium (UX regression).

## Recommended Action

Ship Option 1 before merge. There's no reason subscribe should have weaker bot defense than signup — both create accounts and send email. The work is mechanical: render traps in the form, check traps at handler top. Same one-line change pattern as signup.

## Technical Details

**Affected files:**
- `includes/class-orbit-shortcodes.php` — `subscribe_form()` (lines 1437-1490).
- `includes/class-orbit-rest-subscription.php` — `handle_subscribe()` (lines 160-357).

**Order of operations in handler:**
1. Nonce / permission check (existing).
2. **Trap check (new).**
3. Rate limit check (existing).
4. Field validation (existing).
5. Transactional user + consent writes (existing).

## Acceptance Criteria

- [ ] `subscribe_form()` renders the honeypot + timestamp traps inside the form.
- [ ] `handle_subscribe()` calls `Orbit_Spam::check_traps()` after nonce check and before rate limit.
- [ ] Trap rejection returns 400 with a generic body (don't leak that traps exist).
- [ ] Trap rejection does NOT consume rate-limit budget.
- [ ] PHPUnit: trap-tripped request returns 400 and creates no user.
- [ ] PHPUnit: trap-clean request still succeeds (regression).
- [ ] Manual: submit subscribe form via browser, verify success (real human path unbroken).

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Sources: security-sentinel, call-chain-verifier (item #1)
- `includes/class-orbit-shortcodes.php:1437-1490`
- `includes/class-orbit-rest-subscription.php:160-357`
- `includes/class-orbit-rest-signup.php` (reference implementation)
