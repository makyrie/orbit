---
status: pending
priority: p1
issue_id: "109"
tags: [code-review, PR-26, data-integrity, notifier, cache, transactions]
dependencies: []
---

# Orbit_Notifier::get_or_create_preferences cache leaks stale rows after transaction rollback

## Problem Statement

`Orbit_Notifier::get_or_create_preferences()` populates a static `$preferences_cache` keyed by `user_id` immediately after creating the row. The subscribe REST handler now wraps that call (and all its sibling writes) in a `START TRANSACTION` / `ROLLBACK` envelope. When the transaction rolls back, the preference row vanishes from the database but the cache entry survives in the PHP process for the remainder of the request, and any later code path that reads `get_or_create_preferences( $user_id )` for the same user gets a phantom hit pointing at a non-existent row.

In practice this matters for: (a) a failed subscribe that retries within the same request (rare but possible via observer plugins); (b) test runs where multiple subscribe attempts share a process; (c) future code paths added on the success branch that re-fetch preferences after rollback-then-retry.

## Findings

- `Orbit_Notifier::get_or_create_preferences()` writes `$preferences_cache[ $user_id ]` directly after the `$wpdb->insert()` call, before the outer transaction has committed.
- `class-orbit-rest-subscription.php::handle_subscribe()` calls `get_or_create_preferences()` inside the try-block; if a subsequent step throws, the catch issues `ROLLBACK` but never clears the cache.
- The static cache persists for the lifetime of the PHP request (and across tests sharing a process unless explicitly reset).

## Proposed Solutions

### Option 1: Cache only AFTER COMMIT (caller-driven cache primer)

Split into two methods: `get_or_create_preferences()` returns the row without caching, plus a new `prime_preferences_cache( $user_id, $prefs )` the REST handler calls after the COMMIT.

**Pros:** Cache contents always reflect committed state.
**Cons:** Two-step API; existing call sites must be audited.
**Effort:** Medium (1-2 hr).
**Risk:** Low.

### Option 2: Expose `forget_preferences_cache( $user_id )` and call it in the catch

Keep current caching behavior, add a public static method to evict the cache entry, call it in the subscribe REST handler's catch block alongside `ROLLBACK`.

**Pros:** Smallest diff; matches the existing transactional pattern (side effects gated on COMMIT).
**Cons:** Easy to forget at future call sites; cache invariant is enforced by convention.
**Effort:** Small (30 min).
**Risk:** Low.

### Option 3: Drop the static cache entirely

Rely on WP object cache (which respects transactions on `wp_cache_*` calls only weakly — most installs use in-process cache).

**Pros:** Removes a manual cache invariant.
**Cons:** Performance regression on the same-request second read; behavior depends on object-cache backend.
**Effort:** Small.
**Risk:** Medium (perf regression on dashboard renders).

## Recommended Action

Ship Option 2 before merge. Add `Orbit_Notifier::forget_preferences_cache( int $user_id )` and call it in the catch block of every transactional REST handler that touches preferences (currently only subscribe; signup doesn't touch prefs but should follow the pattern). Document the cache invariant in the class docblock.

## Technical Details

**Affected files:**
- `includes/class-orbit-notifier.php` — add `forget_preferences_cache()` static
- `includes/class-orbit-rest-subscription.php::handle_subscribe()` catch block — call the eviction before `ROLLBACK`

## Acceptance Criteria

- [ ] `Orbit_Notifier::forget_preferences_cache( $user_id )` exists and removes the static entry.
- [ ] Subscribe handler's catch path evicts the cache for the user_id before issuing ROLLBACK.
- [ ] PHPUnit test simulates: (a) primed cache, (b) trigger rollback path, (c) assert subsequent read returns null / re-queries DB.

## Resources

- PR #26: feat/compliance-ui-and-consent-capture
- Surfaced by: data-integrity-guardian PR #26 review
- Related code: `includes/class-orbit-notifier.php::get_or_create_preferences()`
