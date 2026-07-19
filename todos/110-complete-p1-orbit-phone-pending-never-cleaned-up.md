---
status: complete
priority: p1
issue_id: "110"
tags: [code-review, PR-26, privacy, data-integrity, gdpr]
dependencies: []
---

# orbit_phone_pending user_meta has no cleanup path — leaks across verify, privacy delete, and cron

## Problem Statement

PR #26 introduces `orbit_phone_pending` as a user_meta key written at signup and subscribe time so the settings UI can prompt unverified users to confirm the phone they originally supplied. The write side is wired up, but the read/delete side has three concrete holes that together leak phone PII indefinitely, misrepresent UI state, and fail GDPR erasure:

1. **Verify success path doesn't delete pending.** When the user completes SMS verification, `orbit_phone` and `orbit_phone_verified=1` are written, but `orbit_phone_pending` is left in place. The settings UI render at `class-orbit-shortcodes.php:528-537` then keeps showing "we have this number on file from your sign-up but it's not verified yet" even after verification succeeded.
2. **Privacy cleanup misses the key.** `Orbit_Privacy::cleanup_user_data()` at `class-orbit-privacy.php:245-246` deletes `orbit_phone` and `orbit_phone_verified` but does not delete `orbit_phone_pending`. User-deletion (which is a GDPR erasure path) therefore leaks an unverified phone number indefinitely.
3. **Notifier cron has no GC.** `Orbit_Notifier`'s scheduled cleanup hooks were not extended to garbage-collect pending phones for users who sign up, never verify, and abandon the account. The meta accumulates across rows forever.

These are not theoretical: every signup that doesn't immediately verify produces a pending row that will outlive the account.

## Findings

- `includes/class-orbit-phone-verify.php` — verify success branch writes `update_user_meta($user_id, 'orbit_phone', $phone)` and `update_user_meta($user_id, 'orbit_phone_verified', 1)`. No matching `delete_user_meta($user_id, 'orbit_phone_pending')`.
- `includes/class-orbit-privacy.php:245-246` — `cleanup_user_data()` deletes `orbit_phone` and `orbit_phone_verified` only. `orbit_phone_pending` is not in the list.
- `includes/class-orbit-notifier.php` — scheduled cleanup hooks were not updated in PR #26 to include pending-phone GC.
- `includes/class-orbit-shortcodes.php:528-537` — settings UI reads `orbit_phone_pending` to render the "unverified number on file" notice, so leftover pending rows produce a stuck/misleading notice after verification.

## Proposed Solutions

### Option 1: Fix all three sites + add daily GC cron (recommended)

1. **Verify path:** After the verified update, call `delete_user_meta( $user_id, 'orbit_phone_pending' )` and `delete_user_meta( $user_id, 'orbit_phone_pending_added_at' )`.
2. **Privacy cleanup:** Extend the list at `class-orbit-privacy.php:245-246` to include `orbit_phone_pending` and the companion `orbit_phone_pending_added_at` timestamp.
3. **Signup/subscribe write side:** When writing `orbit_phone_pending`, also write `orbit_phone_pending_added_at = time()`. user_meta has no native update timestamp; we need an explicit companion.
4. **Cron GC:** Add an Action Scheduler daily job (e.g., `orbit_gc_pending_phones`) that selects user_meta rows where `orbit_phone_pending_added_at < (time() - 30 * DAY_IN_SECONDS)` and deletes both keys. 30 days is a soft default — make it filterable via `apply_filters( 'orbit_pending_phone_max_age', 30 * DAY_IN_SECONDS )`.

**Pros:** Closes the leak end-to-end; consistent with existing Action Scheduler usage in the notifier.
**Cons:** Three coordinated edits; need a schema-touch (companion timestamp meta).
**Effort:** Medium (2-3 hr).
**Risk:** Low.

### Option 2: Fix only the verify + privacy paths; defer cron GC

Skip the Action Scheduler job, document the cron retention as a follow-up.

**Pros:** Smaller surface; closes the worst leak (GDPR + UI).
**Cons:** Indefinite accumulation for abandoned signups; admins with strict retention policies will still flag it.
**Effort:** Small.
**Risk:** Low.

## Recommended Action

Ship Option 1 before merge. Fix all three sites and add the GC cron. The GC cron is small (a single `WP_User_Query` + `delete_user_meta` loop) and the alternative is shipping a known PII leak.

## Technical Details

**Affected files:**
- `includes/class-orbit-phone-verify.php` — add `delete_user_meta` for both pending keys after verify success.
- `includes/class-orbit-privacy.php` (lines 245-246) — extend cleanup list.
- `includes/class-orbit-rest-signup.php` and `includes/class-orbit-rest-subscription.php` — add companion `orbit_phone_pending_added_at` write.
- `includes/class-orbit-shortcodes.php` (lines 528-537) — no code change; behavior fixes itself once verify path deletes the meta.
- `includes/class-orbit-notifier.php` — register `orbit_gc_pending_phones` Action Scheduler job + handler.

**Data model:**
- New companion key `orbit_phone_pending_added_at` (int unix timestamp).
- No schema migration; pure `usermeta` rows.

## Acceptance Criteria

- [ ] Successful phone verification deletes both `orbit_phone_pending` and `orbit_phone_pending_added_at`.
- [ ] `Orbit_Privacy::cleanup_user_data()` deletes both keys on user erasure.
- [ ] Signup and subscribe write `orbit_phone_pending_added_at = time()` alongside `orbit_phone_pending`.
- [ ] Action Scheduler `orbit_gc_pending_phones` job exists, runs daily, deletes rows older than the filtered max age.
- [ ] Settings UI no longer shows the "unverified number on file" notice after a successful verify.
- [ ] PHPUnit: verify-success path test asserts pending meta is gone.
- [ ] PHPUnit: privacy-cleanup test asserts pending meta is gone after user delete.
- [ ] PHPUnit: GC cron test seeds an old pending row and asserts the job removes it.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Implemented Option 1 — closed the pending-phone leak end-to-end across four sites.
  - **`includes/class-orbit-phone-verify.php` (verify success path)** — after the existing `update_user_meta()` writes for `orbit_phone` + `orbit_phone_verified` in `verify_code()`, added `delete_user_meta()` calls for `orbit_phone_pending` AND its companion `orbit_phone_pending_at`. This removes the stale-notice bug on `/settings/` and prevents the daily GC from racing the user's just-completed verification.
  - **`includes/class-orbit-privacy.php` (`cleanup_user_data()`)** — extended the usermeta-cleanup block with `delete_user_meta()` calls for `orbit_phone_pending` and `orbit_phone_pending_at`. GDPR Article 17 erasure no longer leaks an unverified candidate phone.
  - **`includes/class-orbit-rest-subscription.php` and `includes/class-orbit-rest-signup.php` (pending-write sites)** — both now write a companion `orbit_phone_pending_at = time()` alongside `orbit_phone_pending`. usermeta has no native updated_at so this is the GC cron's age signal.
  - **`includes/class-orbit-notifier.php` (daily GC cron)** — added `HOOK_CLEANUP_PENDING_PHONE` constant (`orbit_cleanup_pending_phones`), registered against `register_hooks()`, scheduled daily in `schedule_recurring_jobs()` to mirror `HOOK_CLEANUP_VERIFY`'s cadence. New method `cleanup_pending_phones()` runs `$wpdb->get_col` against `usermeta` for `orbit_phone_pending_at` rows older than the filtered max age (default 30 days, `orbit_pending_phone_max_age` filter), iterates the user IDs, and `delete_user_meta`s both pending keys per user. Returns the reaped count.
  - **Tests** — added `tests/OrbitPhoneVerifyTest.php` (two tests: success clears pending pair; failure preserves pending pair), `tests/OrbitPrivacyTest.php` (one test: `cleanup_user_data` clears all six Orbit usermeta keys including the pending pair), and two new tests in `tests/OrbitNotifierTest.php` (`cleanup_pending_phones_purges_only_stale_rows`; `cleanup_pending_phones_honors_max_age_filter`).
  - **Out of scope (per todo):** `includes/class-orbit-shortcodes.php` (settings UI render) — untouched; the data layer fixes make the rendered notice correct without any view edit. Backfill of legacy `orbit_phone_pending` rows missing the `_at` companion is a separate one-off migration task.
  - **Parallel-agent note:** Todo 109 ran concurrently against `class-orbit-notifier.php` and `class-orbit-rest-subscription.php`. Edits were sited to distinct regions of each file (109 added `forget_preferences()` near the cache property and modified the subscribe-handler catch block; 110 added the constant/hook/scheduler/GC method and the pending-write companion). Both sets of changes coexist in the working tree without conflict.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Sources: data-integrity-guardian, architecture-strategist (item #5), call-chain-verifier (item #10)
- `includes/class-orbit-privacy.php:245-246`
- `includes/class-orbit-shortcodes.php:528-537`
