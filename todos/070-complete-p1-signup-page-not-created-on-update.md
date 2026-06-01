---
status: complete
priority: p1
issue_id: "070"
tags: [code-review, wordpress, php, activation, deployment, PR-23]
dependencies: []
---

# /sign-up/ page won't exist on production after plugin update

## Problem Statement

PR #23 adds `/sign-up/` to `Orbit_Activator::create_pages()`. That method only runs from the activation hook (`register_activation_hook` → `orbit_activate` → `Orbit_Activator::activate`). On the live multisite, this PR will arrive via a normal plugin **update**, not a fresh activation — `create_pages()` will not run, and `/sign-up/` will 404.

This is load-bearing for the rest of the PR:

- `[orbit_cta]` on the homepage now routes anonymous viewers to `/sign-up/`.
- `[orbit_sign_up]` is the shortcode that backs that page.
- Until `/sign-up/` exists, the entire signup feature is dead on production — the CTA points at a 404.

## Findings

- `includes/class-orbit-activator.php:172-233` — `create_pages()` is only called from `Orbit_Activator::activate()`.
- `orbit.php:91-96` — `orbit_activate` is wired to `register_activation_hook`, not to the upgrade path.
- `orbit.php:121-131` — `orbit_maybe_upgrade()` runs `create_tables()` and migration helpers (`orbit_migrate_page_slugs`, `orbit_migrate_app_page_templates`) on version mismatch, but does NOT call `create_pages()`. The 8 pre-existing pages got created via this same activation-only path because they predate the upgrade mechanism — they're already in production. The new `sign-up` page is the first net-new page created since `orbit_maybe_upgrade` was added.
- The local environment doesn't expose this because the page was manually inserted into the DB during testing.

## Proposed Solutions

**Option A — Call create_pages() from orbit_maybe_upgrade (lowest risk):**

```php
function orbit_maybe_upgrade() {
    $installed_version = get_option( 'orbit_db_version' );

    if ( $installed_version !== ORBIT_VERSION ) {
        Orbit_Activator::create_tables();
        Orbit_Activator::create_pages();   // <-- add
        Orbit_Roles::register();
        orbit_migrate_page_slugs();
        orbit_migrate_app_page_templates();
        update_option( 'orbit_db_version', ORBIT_VERSION );
    }
}
```

`create_pages()` is already idempotent (`if ( $existing ) continue;`), so calling it on every version bump is safe. Pros: trivial, future-proof for any new pages we add. Cons: very mildly couples upgrade to page-creation (acceptable).

**Option B — One-off migration just for /sign-up/:**

Add `orbit_migrate_signup_page()` that inserts only that page if missing. Smaller blast radius, but creates a special-case path that future page additions would have to duplicate.

**Option C — Bump ORBIT_VERSION and add a dedicated activator call.** Same shape as Option A but more ceremonial.

Recommend **Option A**.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected files: `orbit.php` (orbit_maybe_upgrade)
- ORBIT_VERSION is currently `1.4.1` — this PR should bump it (e.g., to `1.5.0`) so the upgrade path actually runs on production.

## Acceptance Criteria

- [ ] `/sign-up/` page is created on plugin update without requiring deactivate/reactivate.
- [ ] `ORBIT_VERSION` is bumped so `orbit_maybe_upgrade` fires on deploy.
- [ ] Manual smoke test on a copy of the production DB: install the plugin update, confirm `wp post list --post_type=page --name=sign-up` returns a row.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- Activator: `includes/class-orbit-activator.php:172-233`
- Upgrade mechanism: `orbit.php:121-131`
