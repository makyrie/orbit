---
status: pending
priority: p2
issue_id: "098"
tags: [code-review, yagni, schema, PR-24]
dependencies: []
---

# Drop provider_message_id, status_updated_at, status-varchar widening, and cta_snapshot_sha256 — all speculative

## Problem Statement

Per code-simplicity-reviewer: PR #24 adds schema for deferred v1.1 features that are unused in v1.6.0:

1. **`provider_message_id varchar(100)`** + its index on `wp_orbit_notification_log` — added "for v1.1 delivery callbacks." Never written or read in v1.6.0.
2. **`status_updated_at datetime`** on same table — added for the same future feature. Never written or read.
3. **Status column widening from `enum` to `varchar(32)`** — added to enable future statuses like `coerced_email`, `delivered`, etc. None of those are used in v1.6.0 either.
4. **`cta_snapshot_sha256 char(64)`** on `wp_orbit_consent_ledger` — computed and written but never read.

This is textbook YAGNI: schema speculation for deferred features that costs a migration on production data. The widening also triggers the ALTER-on-every-version-bump issue (see todo 092). The cta_snapshot_sha256 column is also implicated in the hash chain integrity gap (see todo 080).

## Proposed Solutions

**Option A — Drop all four for v1.6.0 (recommended):**

- Remove `provider_message_id`, `status_updated_at`, and the index from `wp_orbit_notification_log` schema in `create_tables()`.
- Remove the explicit `ALTER TABLE MODIFY COLUMN status varchar(32)` block.
- Remove `cta_snapshot_sha256` from `wp_orbit_consent_ledger` schema AND from `Orbit_Consent::record()` insert payload.

When v1.1 lands (SendGrid event webhook), add the columns then via a versioned migration in `orbit_maybe_upgrade()`.

**Option B — Keep `provider_message_id`** because architecture-strategist argued it'd cause a "second migration" in Phase 5. Counter (from simplicity reviewer): a single column ADD is exactly one migration; that's fine. Don't trade certain present complexity for theoretical future simplicity.

Recommend **Option A**.

## Acceptance Criteria

- [ ] Three log columns + index removed from `create_tables()`.
- [ ] `ALTER TABLE MODIFY COLUMN status` removed.
- [ ] `cta_snapshot_sha256` column removed from consent ledger schema.
- [ ] `Orbit_Consent::record()` no longer computes / inserts the sha256 value.
- [ ] Plan file updated to note these are v1.1 additions.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by code-simplicity-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-activator.php:125-203`
- `includes/class-orbit-consent.php:126, 151`
