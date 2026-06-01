---
status: complete
priority: p3
issue_id: "075"
tags: [code-review, theme, fse, PR-23]
dependencies: [070]
---

# /sign-up/ page inherits `page-app` template meant for authenticated app screens

## Problem Statement

`Orbit_Activator::create_pages()` assigns `_wp_page_template => page-app` to every page it creates, including the new `sign-up` entry:

```php
'meta_input' => array(
    '_wp_page_template' => 'page-app',
),
```

The other 8 pages (`dashboard`, `settings`, `subscriptions`, `manage`, `new-activity`, `edit-activity`, `subscribers`, `edit-profile`) are all authenticated app screens — they're explicitly listed in `orbit_get_internal_page_slugs()` and hidden from nav menus. `sign-up` is a public marketing page (intentionally kept out of `orbit_get_internal_page_slugs` per the PR comment so it stays visible in nav).

Assigning the app template to a marketing page is conceptually inconsistent. End-to-end testing shows it works visually — the Perihelion theme's `page-app` template apparently renders the marketing header for anonymous users — but that's relying on coincidence, not intent.

## Findings

- `includes/class-orbit-activator.php:227-229` — meta_input assigned unconditionally.
- `orbit.php:233-244` — sign-up is explicitly outside `orbit_get_internal_page_slugs()`.
- E2E walkthrough showed the marketing header on /sign-up/ ("Perihelion" + "Log in"), not the app nav.

## Proposed Solutions

**Option A — Don't set `_wp_page_template` on the sign-up page:**

```php
'sign-up' => array(
    'title'    => 'Sign Up',
    'content'  => '[orbit_sign_up]',
    'template' => '', // marketing page, default template
),
```

Adapt the loop to only set `_wp_page_template` when `template` is non-empty. Lets the theme's default `page.html` (marketing template) handle it.

**Option B — Keep page-app template, add a comment to `create_pages()` saying why:**

If page-app legitimately handles anonymous viewers correctly, document that and move on.

**Option C — Add a dedicated `page-marketing` template option** for future public pages.

Recommend **Option A**, paired with quick browser verification that the marketing header still renders on /sign-up/ without the meta.

## Recommended Action

(Filled during triage.)

## Technical Details

- Depends on todo 070 (whichever path is chosen for create_pages-on-update needs to apply this nuance).
- The fix-up on existing local sign-up pages: a one-time migration that removes `_wp_page_template = page-app` for slug `sign-up`. Or just accept the existing meta and apply this only to new installs.

## Acceptance Criteria

- [ ] sign-up either uses the default page template OR has a documented reason to use page-app.
- [ ] Visual parity: marketing header + footer on /sign-up/.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-activator.php:172-233`
