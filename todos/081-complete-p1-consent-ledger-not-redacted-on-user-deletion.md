---
status: complete
priority: p1
issue_id: "081"
tags: [code-review, privacy, gdpr, consent-ledger, PR-24]
dependencies: []
---

# Consent ledger PII not redacted on user deletion — but the activator's comment claims it is

## Problem Statement

`includes/class-orbit-activator.php:177-179` documents the consent-ledger schema with this promise:

> `Orbit_Privacy::cleanup_user_data()` will redact PII (ip_hash, user_agent) when a user is deleted but leave the row.

But `includes/class-orbit-privacy.php::cleanup_user_data()` makes **zero** references to the consent ledger. It cleans up profiles, subscriptions, activities, responses, notification_preferences, notification_log, phone_verification, and usermeta — but the consent ledger table is never touched.

On a real user deletion today, raw `ip_hash` + `user_agent` + `cta_snapshot` (which may contain PII echoed back from form copy) survive indefinitely in the network-scoped table. The `redacted_at_utc` column exists for exactly this purpose but has no writer.

GDPR Article 17 (right to erasure) exposure: `ip_hash` derived from a static install salt + linkable `user_id` is still personal data under GDPR even after the WP user row is deleted.

## Findings

- `includes/class-orbit-activator.php:177-179` — documents promised redaction.
- `includes/class-orbit-privacy.php:153-280` — `cleanup_user_data()` has no consent-ledger code path.
- `orbit.php:360` — `add_action( 'delete_user', array( 'Orbit_Privacy', 'cleanup_user_data' ) )` fires on every user deletion.

## Proposed Solutions

**Option A — Implement the redaction (recommended):**

1. In `Orbit_Privacy::cleanup_user_data( $user_id )`, wrap a single `Orbit_Consent::with_migration_mode()` block that runs:
   ```sql
   UPDATE wp_orbit_consent_ledger
   SET ip_hash = '', user_agent = '', cta_snapshot = '[redacted per user deletion]', redacted_at_utc = UTC_TIMESTAMP()
   WHERE user_id = %d AND redacted_at_utc IS NULL
   ```
2. Because redaction mutates hash inputs, the chain breaks. Either (a) tolerate it (each row's `redacted_at_utc` documents why), or (b) recompute `row_hash` under a `redacted` chain-version flag so `verify_chain` understands.
3. Use the `base_prefix` table name from `Orbit_Consent::table_name()` — DO NOT join via the global `$wpdb->prefix`.

**Option B — Remove the misleading comment** and file a separate todo to implement properly in v1.1.

Recommend **Option A** — shipping with the comment as written creates a false belief that GDPR coverage exists. Either the comment is correct or it isn't.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected files: `includes/class-orbit-privacy.php` (add redaction call), optionally `includes/class-orbit-consent.php` (add chain-version handling for redacted rows).
- Pairs with todo 080 (hash chain extension): if 080 ships first with chain versioning, this redaction can use the same versioning to preserve chain integrity through redaction.

## Acceptance Criteria

- [ ] `delete_user` cascades a redaction UPDATE on the consent ledger.
- [ ] `redacted_at_utc` is populated after redaction.
- [ ] `ip_hash` and `user_agent` are NULLed (or empty-stringed) after redaction.
- [ ] `cta_snapshot` is replaced with a sentinel (preserves the existence of the consent event without retaining the PII-bearing snapshot).
- [ ] `verify_chain` either tolerates redacted rows (returns them as expected gaps) or validates them under a redacted-version codepath.
- [ ] Either the comment at `class-orbit-activator.php:177-179` is true, or it's removed.
- [ ] Test added: delete a user with consent rows, assert `ip_hash` and `user_agent` are empty after deletion, assert `user_id` linkage remains.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by data-integrity-guardian, data-migration-expert.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-activator.php:177-179`
- `includes/class-orbit-privacy.php:153-280`
