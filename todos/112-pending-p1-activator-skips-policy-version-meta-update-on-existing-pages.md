---
status: pending
priority: p1
issue_id: "112"
tags: [code-review, PR-26, wp-php, activation, tcpa-evidence]
dependencies: []
---

# Activator skips orbit_policy_version meta update on existing /privacy/ and /terms/ pages — stale ledger version on upgrades

## Problem Statement

`Orbit_Activator::create_pages()` at `class-orbit-activator.php:262-285` iterates the desired policy pages (privacy, terms), checks whether each page already exists, and `continue`s the loop early if it does. The early continue skips **every** subsequent operation in the loop body — including the `update_post_meta( $page->ID, 'orbit_policy_version', ORBIT_VERSION )` call.

`Orbit_Consent::current_policy_version()` at `class-orbit-consent.php:477-489` reads `orbit_policy_version` post_meta off the policy page to stamp the consent ledger row with the policy version the user agreed to. When the early continue skips the meta write, the post_meta retains whatever version it was last stamped with — or nothing at all if the page predates PR #26.

Concrete failure: an admin upgrades the plugin to a version that bumps `ORBIT_VERSION` (or edits the /privacy/ page content via the block editor without the plugin knowing). `create_pages()` runs at activation, sees the page exists, skips the meta update. Every new consent row stamps the **old** version even though the user is reading the new text. The ledger row's `policy_version` then misrepresents what the user actually saw — directly undermining TCPA evidence.

## Findings

- `includes/class-orbit-activator.php:262-285` — the `if ( $existing ) { continue; }` branch is too coarse. It correctly avoids re-inserting page content/template but also skips meta upserts.
- `includes/class-orbit-consent.php:477-489` — `current_policy_version()` reads `get_post_meta( $page_id, 'orbit_policy_version', true )`. Returns empty when meta is missing.
- The consent record path then stamps the (possibly empty / stale) version into the ledger row, breaking the "row tells you exactly which text version the user saw" invariant.

## Proposed Solutions

### Option 1: Split the conditional — always upsert meta, only gate content insert (recommended)

Restructure the loop body:

```php
foreach ( $pages as $slug => $config ) {
    $existing = get_page_by_path( $slug );

    if ( ! $existing ) {
        $page_id = wp_insert_post( [
            'post_title'   => $config['title'],
            'post_content' => $config['content'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => $slug,
        ] );
    } else {
        $page_id = $existing->ID;
    }

    if ( ! $page_id || is_wp_error( $page_id ) ) {
        continue;
    }

    // Always upsert the policy version, regardless of whether the page is new.
    update_post_meta( $page_id, 'orbit_policy_version', ORBIT_VERSION );
}
```

**Pros:** Meta stays in sync with `ORBIT_VERSION` on every activation; preserves "don't clobber admin-edited content" intent; minimal diff.
**Cons:** None significant. The admin's content is still preserved.
**Effort:** Small (30 min).
**Risk:** Low.

### Option 2: Add an admin-action button "I edited the policy text, restamp version"

Keep the activator as-is, surface a settings-page action that explicitly bumps `orbit_policy_version` to `ORBIT_VERSION` (or to a user-supplied value).

**Pros:** Gives admins explicit control over when ledger version stamps change.
**Cons:** Easy to forget; not idempotent across upgrades; doesn't fix the activator-on-upgrade case which is the primary failure path.
**Effort:** Medium.
**Risk:** Medium (relies on admin remembering).

## Recommended Action

Ship Option 1 before merge. The activator must keep the post_meta version in sync with the plugin's `ORBIT_VERSION` constant whenever it runs — that's the only way the ledger can claim to know which version of the text was shown. Optionally add Option 2 later as a way to bump version mid-cycle when the admin edits the page text without bumping the plugin version, but Option 1 is the merge-blocker fix.

## Technical Details

**Affected files:**
- `includes/class-orbit-activator.php` (lines 262-285) — restructure loop.
- `includes/class-orbit-consent.php` (lines 477-489, `current_policy_version()`) — no code change but its contract becomes reliable.

**ORBIT_VERSION coupling:**
- `current_policy_version()` continues to read the post_meta as the source of truth. The activator becomes responsible for keeping post_meta = plugin version on every run. Existing rows in the consent ledger are untouched (historical stamps remain correct as of when they were written).

## Acceptance Criteria

- [ ] Fresh activation on a site without /privacy/ or /terms/ creates the pages and stamps `orbit_policy_version = ORBIT_VERSION` on both.
- [ ] Activation on a site where the pages already exist updates the meta to `ORBIT_VERSION` without touching post_content.
- [ ] `Orbit_Consent::current_policy_version()` returns `ORBIT_VERSION` immediately post-activation.
- [ ] Consent ledger rows written after activation stamp the new version.
- [ ] PHPUnit: seed an existing page with old meta, run activator, assert meta updated, assert post_content unchanged.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Sources: architecture-strategist (item #8), wp-php-reviewer
- `includes/class-orbit-activator.php:262-285`
- `includes/class-orbit-consent.php:477-489`
