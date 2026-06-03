---
status: complete
priority: p1
issue_id: "080"
tags: [code-review, security, data-integrity, consent-ledger, PR-24]
dependencies: []
---

# Hash chain omits 4 schema-load-bearing fields; verify_chain rehashes with URL-supplied user_id

## Problem Statement

Two related defects in the consent-ledger hash chain that together silently weaken TCPA evidence:

1. `Orbit_Consent::compute_row_hash()` hashes only 9 of the 13 chain-relevant fields. It misses `program`, `privacy_policy_version`, `terms_version`, and `cta_snapshot_sha256`. Tampering with any of those fields on an existing row goes undetected by `verify_chain()`.
2. `verify_chain()` re-hashes using the URL-supplied `$user_id` parameter, not the row's stored `user_id`. The SELECT filters by `user_id = %d`, so in normal operation they match — but if `user_id` is ever mutated by a tamper attempt (the whole point of `verify_chain()`), the recompute uses the queried value and the chain still appears intact.

## Findings

- `includes/class-orbit-consent.php:296-313` — `compute_row_hash()` payload omits 4 fields.
- `includes/class-orbit-consent.php:250-260` — `verify_chain()` uses `(int) $user_id` (parameter) when recomputing.
- `includes/class-orbit-consent.php:227-235` — SELECT in `verify_chain()` doesn't even project `user_id`, so the row's value isn't available.

A `privacy_policy_version` swap (e.g., a malicious or buggy UPDATE) is exactly the kind of tampering the ledger is supposed to defend against — that field documents *which* policy the user actually agreed to.

## Proposed Solutions

**Option A — Extend the hash payload + chain-version byte (recommended):**

1. Add `program`, `privacy_policy_version`, `terms_version`, `cta_snapshot_sha256` to `compute_row_hash()` inputs.
2. Add `user_id` to the SELECT projection in `verify_chain()` and pass `(int) $row->user_id` into the recompute.
3. Prepend a version byte (e.g., `'v2|'`) to the payload so existing rows still validate via a `v1` codepath. Without that, every pre-existing row breaks the moment the fix ships.

**Option B — Drop `cta_snapshot_sha256` from the schema entirely** (per simplicity reviewer #2): the column is written but never read. Removing it sidesteps the integrity gap for that field. Still need to hash the other 3.

Recommend **Option A** for the policy/terms fields (load-bearing for TCPA evidence) and **Option B** for the sha256 column. They compose cleanly.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-consent.php`
- The chain-version byte must be applied to both `record()` (writes) and `verify_chain()` (reads). Test coverage needs updates: an existing row written with v1 must still verify after the fix ships.

## Acceptance Criteria

- [ ] `compute_row_hash()` includes `program`, `privacy_policy_version`, `terms_version`.
- [ ] `verify_chain()` rehashes with the row's own `user_id`.
- [ ] Tampering with `privacy_policy_version` is detected by `verify_chain()`.
- [ ] Existing v1.6.0 rows (if any in production) still verify after fix lands.
- [ ] Test added: tamper `privacy_policy_version`, assert `verify_chain` reports broken.
- [ ] Test added: tamper `user_id` directly, assert `verify_chain` reports broken (use `with_migration_mode`).

## Work Log

- 2026-06-01: Identified during code review of PR #24 by data-integrity-guardian.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-consent.php:296-313`, `:250-260`
- `docs/solutions/security-issues/hmac-token-embed-lookup-key.md`
