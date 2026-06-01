---
status: pending
priority: p1
issue_id: "090"
tags: [code-review, configuration, operability, PR-24]
dependencies: []
---

# ORBIT_CONSENT_IP_SALT missing in production produces silent ledger failures with no admin warning

## Problem Statement

`Orbit_Consent::record()` returns `WP_Error('orbit_consent_salt_missing')` when the constant is undefined. Several callers (e.g., `Orbit_Routes::perform_unsubscribe` per todo 088) discard the return value. Result: in production, if an admin forgets to set the constant, every consent write silently fails with NO admin notice, NO plugin-activation fatal, NO install-time check.

The ledger appears intact (no PHP errors logged unless a caller adds explicit logging) but is empty. Discovery typically happens during a TCPA dispute when the audit response is "we have no record."

## Findings

- `includes/class-orbit-consent.php:88-93` — fails fast on missing salt.
- No admin notice anywhere flags the missing constant.
- README has no entry naming the constant alongside other required production constants.
- `Orbit_Activator::activate()` does not check or generate a default.

## Proposed Solutions

**Option A — Admin notice + auto-generated default on activation (recommended):**

1. In `Orbit_Activator::activate()`, check `defined('ORBIT_CONSENT_IP_SALT')`. If undefined, generate `wp_generate_password(64, false)` and store it in `wp_options` as `orbit_consent_ip_salt`.
2. Update `Orbit_Consent::record()` to fall back to the option when the constant is undefined:
   ```php
   $salt = defined( 'ORBIT_CONSENT_IP_SALT' ) ? ORBIT_CONSENT_IP_SALT : get_option( 'orbit_consent_ip_salt', '' );
   if ( '' === $salt ) { return new WP_Error( ... ); }
   ```
3. Add an `admin_notices` warning when only the option (not the constant) is in use, encouraging ops to move it to wp-config.php for key-rotation discipline.

**Option B — Hard fatal at activation** if the constant isn't defined. Forces ops to set it, but blocks fresh installs that don't yet have ops attention.

Recommend **Option A**. Production never silently fails; ops gets a clear "you should pin this to wp-config" prompt; key rotation is still possible (drop the option, redefine the constant — old IP hashes don't change, retention cron eventually redacts them).

Also: README/AGENTS.md should document `ORBIT_CONSENT_IP_SALT` alongside the other required production constants (`ORBIT_TWILIO_*`, etc.).

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected files: `includes/class-orbit-activator.php`, `includes/class-orbit-consent.php`, `README.md` (or wp-config.php sample).

## Acceptance Criteria

- [ ] Fresh activation either defines the constant or generates a fallback option.
- [ ] `Orbit_Consent::record()` succeeds on a fresh install with no wp-config edit.
- [ ] Admin notice appears when the option is in use but the constant isn't.
- [ ] README documents the constant.
- [ ] Test added: simulate missing constant, verify fallback to option works.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-php-reviewer, security-sentinel.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-consent.php:88-93`
