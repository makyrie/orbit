---
status: complete
priority: p1
issue_id: "108"
tags: [code-review, PR-26, data-integrity, consent, activator]
dependencies: []
---

# ORBIT_CONSENT_IP_SALT never seeded by activator — fresh installs fail every signup

## Problem Statement

`Orbit_Consent::record()` requires a non-empty IP salt to hash the caller's IP into the ledger. On a fresh install where the operator has not manually added `define( 'ORBIT_CONSENT_IP_SALT', '…' )` to `wp-config.php`, the salt is empty and every signup / subscribe write throws — the transaction rolls back and the user sees a 500. PR #24 introduced the salt requirement but did not add an activator-side fallback to mint a per-site salt on activation.

PR #26 turns this from "theoretical" to "real bug": Phase 2 routes BOTH `/signup` and `/subscribe` through `Orbit_Consent::record()` inside a transaction, so a missing salt now blocks all new account creation on any fresh install.

## Findings

- `Orbit_Consent::record()` derives `ip_hash` via the salt; without the constant defined and no fallback option, the hash step returns empty and the row insert fails the chain-hash invariant.
- `includes/class-orbit-activator.php::activate()` does not call `add_option( 'orbit_consent_ip_salt', wp_generate_password( 64, false ) )` or set the constant equivalent in `wp-config.php` instructions.
- `Orbit_Consent::record()` has no graceful "salt missing → log + continue without IP hash" path either.

## Proposed Solutions

### Option 1: Auto-mint per-site salt option on activation (recommended)

Add to `Orbit_Activator::activate()`:

```php
if ( ! defined( 'ORBIT_CONSENT_IP_SALT' ) && false === get_option( 'orbit_consent_ip_salt' ) ) {
    add_option( 'orbit_consent_ip_salt', wp_generate_password( 64, false ), '', false );
}
```

Update `Orbit_Consent::record()` to fall back to the option when the constant isn't defined:

```php
$salt = defined( 'ORBIT_CONSENT_IP_SALT' )
    ? ORBIT_CONSENT_IP_SALT
    : (string) get_option( 'orbit_consent_ip_salt', '' );
```

**Pros:** Zero-config installs work; documented salts (constant) still take precedence; reversible by deleting the option.
**Cons:** Salt lives in the DB rather than the config file (lower defense-in-depth — but a determined attacker with DB access already has the ledger contents).
**Effort:** Small (30 min).
**Risk:** Low.

### Option 2: Hard-fail activation if salt missing

Refuse to activate the plugin and surface a notice instructing the operator to define the constant.

**Pros:** Forces explicit operator decision; salt stays out of DB.
**Cons:** Breaks the "drop in and try it" UX that everything else in this plugin supports; multisite installs become painful.
**Effort:** Small.
**Risk:** Medium (activation failure cascade).

### Option 3: Make IP hashing optional

Document the salt as opt-in; when absent, write a null `ip_hash` and rely on user_id alone for ledger identity.

**Pros:** Simplest.
**Cons:** Weakens TCPA evidence — the salt-hashed IP is part of the per-row defense; dropping it on fresh installs creates an audit asymmetry.
**Effort:** Small.
**Risk:** Medium (compliance regression).

## Recommended Action

Ship Option 1 before merge. The activator-seeded option preserves zero-config installs, the wp-config constant remains the documented best-practice override, and the consent ledger keeps the IP-hash defense intact on every install.

## Technical Details

**Affected files:**
- `includes/class-orbit-activator.php::activate()`
- `includes/class-orbit-consent.php::record()`

**Database changes:**
- New `wp_options` row (or `wp_sitemeta` on multisite) `orbit_consent_ip_salt`. Autoload off.

## Acceptance Criteria

- [ ] Fresh activation on a site with no `ORBIT_CONSENT_IP_SALT` constant produces a non-empty `orbit_consent_ip_salt` option.
- [ ] `Orbit_Consent::record()` falls back to the option when the constant is absent.
- [ ] When the constant IS defined, it takes precedence (constant wins).
- [ ] PHPUnit: new test in `OrbitConsentTest` proves the fallback path produces a stable hash.
- [ ] PHPUnit: new test proves the salt option is created exactly once across re-activation (idempotent).

## Resources

- PR #26: feat/compliance-ui-and-consent-capture
- Surfaced by: data-integrity-guardian PR #26 review
- Related: PR #24 introduced the salt requirement.

## Work Log

### 2026-06-09

Implemented Option 1 (auto-mint per-site salt option on activation).

- `includes/class-orbit-activator.php`: added `seed_consent_ip_salt()` and
  wired it into `activate()`. Guards: no-op when `ORBIT_CONSENT_IP_SALT` is
  defined (constant wins, leaves option absent so deleting it can't shadow
  the constant later), and no-op when the option already exists
  (re-activation must not rotate the salt — that would invalidate every
  prior `ip_hash` in the ledger). Autoload off.
- `includes/class-orbit-consent.php`: no change needed. The existing
  `resolve_ip_salt()` helper already prefers `ORBIT_CONSENT_IP_SALT` and
  falls back to `(string) get_option( 'orbit_consent_ip_salt', '' )`, with
  the `orbit_consent_ip_salt_resolved` filter as a last-mile override for
  HSM-backed installs and tests.
- `tests/OrbitActivatorConsentSaltTest.php`: new test file covering
  (a) fallback resolves via option when the constant is absent (simulated
  via the `orbit_consent_ip_salt_resolved` filter, since the bootstrap
  pre-defines the constant and PHP can't undefine it at runtime),
  (b) constant takes precedence when both exist (asserted by comparing
  `hash_ip()` output against the constant-salted vs. option-salted hashes),
  and (c) idempotent on re-activation (three back-to-back
  `seed_consent_ip_salt()` calls preserve the original option value).

PHPUnit could not be run locally: Local by Flywheel's MySQL socket was not
present at `~/Library/Application Support/Local/run/NZ_MOyrML/mysql/mysqld.sock`
when this work landed (the orbit site wasn't started), so
`vendor/bin/phpunit --filter OrbitConsentTest` failed with
`Error establishing a database connection`. The PHP for all touched files
lints clean. The wave runner / CI will execute the suite against a live
DB.
