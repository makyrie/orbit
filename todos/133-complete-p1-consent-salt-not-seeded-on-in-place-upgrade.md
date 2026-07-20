---
status: complete
priority: p1
issue_id: "133"
tags: [bug, launch-blocker, consent, upgrade, signup]
dependencies: []
---

# P1 — Consent IP salt not seeded on in-place upgrade → every signup/subscribe 500s

## Problem Statement

Discovered during the pre-launch Local email walkthrough (2026-07-19). A real
sign-up through `/sign-up/` returned HTTP 500:

```json
{"code":"orbit_consent_salt_missing","data":{"status":500}}
```

`Orbit_Consent::record()` refuses to write a ledger row when no IP salt
resolves, and every signup/subscribe provisioning transaction runs through it —
so the whole transaction rolls back and **no account is created and no email is
sent.** The user sees only "We couldn't complete your sign-up."

## Root Cause

The salt fallback option `orbit_consent_ip_salt` is seeded by
`Orbit_Activator::seed_consent_ip_salt()`, but that was only called from
`Orbit_Activator::activate()` (the `register_activation_hook` path — i.e. fresh
activation or manual re-activation).

`orbit_maybe_upgrade()` (`orbit.php`, the in-place upgrade path that runs on
`init` when `orbit_db_version < ORBIT_VERSION`) ran `create_tables()`, role
registration, and slug migrations — **but never seeded the salt.**

Deployments here are done by uploading plugin files in place (no
re-activation). So any install first activated **before** the salt-seed code
landed (PR #26, June 2026) and then updated in place never gets a salt →
`orbit_consent_ip_salt` is absent → all signups/subscribes 500.

Confirmed on the Local DB: `orbit_db_version` SET, `orbit_consent_ip_salt`
**absent**.

## Production Impact

**Almost certainly affects production.** perihelion.social is updated by manual
file upload (no re-activation). If it was first activated before June, its salt
option is missing and sign-up/subscribe are broken (or will be the moment the
compliance code is live there).

## Fix

`orbit.php` — `orbit_maybe_upgrade()` now calls
`Orbit_Activator::seed_consent_ip_salt()` inside the forward-version-jump
branch. The method is guarded and idempotent (no-ops when the constant is
defined or the option already exists), so it is safe on every upgrade.

Bumped `ORBIT_VERSION` (and the plugin header) `1.8.0 → 1.8.1` so the upgrade
branch is guaranteed to fire on the next production deploy. **This is
load-bearing:** if prod is already at `orbit_db_version = 1.8.0` with a missing
salt (the exact state Local was in), the seed line alone would not re-run —
the version advance is what triggers the self-heal.

## Verification

- Reproduced the 500 via the real `/sign-up/` form (Playwright).
- Applied the fix, forced the upgrade path (deleted `orbit_db_version`, loaded a
  page): `orbit_consent_ip_salt` was minted (64 chars).
- Re-ran sign-up end to end: account created, logged in, redirected to
  `/edit-profile/`; consent ledger row written (`channel=email`,
  `event=opt_in`, `source=signup`, `ip_hash` populated); the "[Perihelion]
  Login Details" email landed in the mail catcher with a valid password-set
  link.
- Full PHPUnit suite green (238 tests); `composer policy-diff` clean.

## Deployment Note

On the production deploy of 1.8.1, verify the heal actually ran:

```
wp option get orbit_consent_ip_salt   # should be a 64-char string
```

If for any reason the upgrade didn't fire, either define
`ORBIT_CONSENT_IP_SALT` in `wp-config.php` (preferred, documented best
practice) or seed the option once.

## Test Gap (follow-up)

No unit test exercises the real option-mint branch: `tests/bootstrap.php`
always defines `ORBIT_CONSENT_IP_SALT`, so `seed_consent_ip_salt()` short-
circuits and the upgrade-path seeding can't be asserted in-process. A faithful
regression test would need to run `orbit_maybe_upgrade()` with the constant
undefined (not possible in the current single-process bootstrap). Options:
a subprocess/@runInSeparateProcess test without the constant, or refactor the
seed to accept an injected "constant present?" predicate. Left as a follow-up;
the behavior is covered manually above.

## Work Log

- 2026-07-19: Found during pre-launch Local email walkthrough; fixed in
  `orbit_maybe_upgrade()`; version bumped to 1.8.1.
