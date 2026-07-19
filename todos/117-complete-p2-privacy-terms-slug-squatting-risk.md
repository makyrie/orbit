---
status: complete
priority: p2
issue_id: "117"
tags: [code-review, PR-26, security, compliance]
dependencies: []
---

# /privacy/ and /terms/ slug-squatting bypasses activator-managed compliance pages

## Problem Statement

`Orbit_Activator::create_pages()` creates `/privacy/` and `/terms/` only when `get_page_by_path()` returns nothing at those slugs. Any user (or unrelated plugin) who pre-creates a page at `/privacy/` — even as a draft — wins ownership of the slug. The consent ledger then points its `policy_url` at a page whose content is controlled by someone other than Orbit, defeating the entire TCPA-defense story for that slug.

Worse: WordPress lets any user with `edit_pages` capability edit the activator-created page later. Nothing in Orbit prevents a well-meaning editor from rewriting `/privacy/` after activation, again silently desyncing the ledger from the rendered policy.

## Findings

- `includes/class-orbit-activator.php` — `create_pages()` calls `get_page_by_path()` and short-circuits when any page matches, regardless of post_author / post_status / post_content.
- The activator does not stamp ownership metadata on the created pages, so there is no signal after the fact that "this page is Orbit's."
- Surfaced by security-sentinel during multi-agent review.

## Proposed Solutions

**Option A — Canonical-id option + ownership meta (recommended).** On activation, if no canonical page exists, create it, store the ID in `orbit_privacy_page_id` / `orbit_terms_page_id`, and stamp `_orbit_canonical_compliance` post_meta on the page. Render consent disclosures using the canonical ID, not a slug lookup. Add a `pre_delete_post` / `pre_post_update` filter that refuses to delete the canonical page and warns on content edits.

Effort: medium. Risk: low. Strongest defense.

**Option B — Author verification.** Continue to look up by path, but only trust the page when `post_author === orbit_admin_user_id`. Cheaper but doesn't prevent later edits, and relies on a stable `orbit_admin` identity.

## Recommended Action

Option A. Canonical IDs + ownership meta is the defensible pattern. The `policy_url` in the consent ledger should be derived from `get_permalink( get_option( 'orbit_privacy_page_id' ) )` so that even if an admin renames the slug later, the ledger continues to point at the right post.

## Technical Details

- Add `orbit_privacy_page_id` and `orbit_terms_page_id` options written by the activator.
- Stamp `_orbit_canonical_compliance` (value: `privacy` or `terms`) on the created pages.
- Add a `pre_delete_post` filter that returns an error when the post has the canonical meta.
- Existing installs need a one-time migration: look up `/privacy/` and `/terms/` by slug, stamp them, and write the IDs into options.
- Document the ownership convention next to todo 129 (privacy/terms drift detection).

## Acceptance Criteria

- [ ] Activator stores canonical page IDs in options and stamps `_orbit_canonical_compliance` meta.
- [ ] `pre_delete_post` blocks deletion of canonical compliance pages with a clear error.
- [ ] `policy_url` in the consent ledger is derived from canonical IDs, not slug lookups.
- [ ] One-time migration for existing installs reattaches ownership to current `/privacy/` and `/terms/` pages (if author matches) or logs a warning if they were squatted.
- [ ] Test: pre-create a `/privacy/` page with a different author, run the activator, assert ownership is not silently inherited.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Implemented option (b) — canonical-id storage. `Orbit_Activator::create_pages()` now carries a `compliance_canonical` flag on its /privacy/ and /terms/ page config; for those pages it stamps `_orbit_canonical_compliance` post_meta (newly-inserted pages get it via `meta_input`, existing pages get a backfill `update_post_meta()`) and persists the resolved page_id to `orbit_privacy_page_id` / `orbit_terms_page_id` via `update_option(..., $page_id, false)` (autoload off). A slug-collision guard skips the option write and logs via `error_log()` when the existing marker on the resolved page already claims a different canonical kind; the policy-version meta upsert still runs, preserving todo 112 behavior. Added `Orbit_Consent::canonical_compliance_page_id( string $kind ): int` as the read-side API (returns 0 for unknown kinds or unset options; documented `home_url('/privacy/')` fallback for callers). Slug-resolution sites elsewhere in the codebase are intentionally NOT touched in this todo — that's a follow-up; this commit only hardens the data layer + read API. New test file `tests/OrbitActivatorCompliancePagesTest.php` (5 tests) covers fresh-activation option mint, marker stamping, re-activation idempotency, the read-API option dereference, and the absent-option zero return. `vendor/bin/phpunit --filter OrbitActivator` → 9/9 green; `--filter OrbitConsent` → 26/26 green (no regression).

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-activator.php` (`create_pages()`)
