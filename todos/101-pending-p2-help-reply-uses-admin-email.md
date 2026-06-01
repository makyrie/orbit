---
status: pending
priority: p2
issue_id: "101"
tags: [code-review, twilio, tcr, branding, PR-24]
dependencies: []
---

# HELP TwiML reply uses get_option('admin_email') — won't match TCR-registered support contact

## Problem Statement

`Orbit_Twilio::help_reply_body()` interpolates `get_option('admin_email')` as the support contact in the HELP reply. That's the WordPress admin's personal email — almost certainly NOT the support address ops registered with TCR.

The TCR-approved sample messages will reference whatever support contact is on the campaign. If ops registered `support@perihelion.social`, the HELP reply will return the wp-admin's email (probably the install owner's personal address). When Twilio randomly samples HELP replies to compare against the TCR campaign, content won't match — campaign suspension risk.

Same issue applies to the `ORBIT_MESSAGING_BRAND` pattern: pinning the brand prevents drift. Support contact needs the same treatment.

## Proposed Solutions

**Add `ORBIT_MESSAGING_SUPPORT` constant (recommended):**

In `orbit.php`:

```php
defined( 'ORBIT_MESSAGING_SUPPORT' ) || define( 'ORBIT_MESSAGING_SUPPORT', get_option( 'admin_email' ) );
```

Update `Orbit_Twilio::help_reply_body()`:

```php
$support = defined( 'ORBIT_MESSAGING_SUPPORT' ) ? ORBIT_MESSAGING_SUPPORT : get_option( 'admin_email' );
```

Production sets `define( 'ORBIT_MESSAGING_SUPPORT', 'support@perihelion.social' )` in wp-config.php. Default fallback to `admin_email` keeps fresh installs working.

Also add a CI assertion (PHPUnit) that the help reply content matches the TCR-submitted sample message byte-for-byte — drift fails the build.

## Acceptance Criteria

- [ ] `ORBIT_MESSAGING_SUPPORT` constant defined with admin_email default.
- [ ] `help_reply_body()` uses the constant.
- [ ] README documents the constant.
- [ ] Test: assert HELP reply contains the constant's value.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-php-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-twilio.php:236-244`
