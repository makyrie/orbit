---
title: "template_redirect priority < 10 reads stale conditional tags on rewritten URLs"
category: runtime-errors
problem_type: hook_priority_ordering
severity: high
components: [Orbit_Routes, redirect_logged_in_from_home, handle_routes]
tags: [template_redirect, hook-priority, rewrite-rules, query-vars, conditional-tags, virtual-pages, is_front_page]
affected_versions: ["0.1.0"]
created: 2026-05-05
date_resolved: 2026-05-05
---

# `template_redirect` Priority < 10 Reads Stale Conditional Tags on Rewritten URLs

## Symptom

Logged-in users were 303-redirected from every Orbit virtual page —
`/@{slug}/`, `/activity/{id}`, and `/unsubscribe/` — back to `/dashboard/`.
Clicking any activity card on the dashboard bounced the user straight back
to the dashboard. Direct-typed URLs and links from emails were equally
affected. Logged-out users saw the pages render correctly, which made the
bug feel like an authentication problem when it was actually a hook-ordering
problem.

## Root Cause

`Orbit_Routes::redirect_logged_in_from_home()` was registered on
`template_redirect` at **priority 5** so it could run before
`redirect_canonical` (priority 10) and any third-party callbacks that might
short-circuit on the home page. The callback's logic was, in effect:

```php
if ( ! is_user_logged_in() ) return;
if ( ! is_front_page() )    return;
wp_safe_redirect( home_url( '/dashboard/' ), 303 );
```

That looks correct in isolation, but on Orbit's virtual-page routes it
fires too early. By priority 5 the rewrite has already populated the
custom query vars (`orbit_profile_slug`, `orbit_activity_id`,
`orbit_unsubscribe`) — but `Orbit_Routes::handle_routes()`, which
*replaces* the main query with a synthetic page post, runs at the default
priority 10. So between priorities 5 and 10:

| What's true at priority 5                    | What's not yet true   |
| -------------------------------------------- | --------------------- |
| `get_query_var( 'orbit_activity_id' )` works | `is_singular()` true  |
| Rewrite rules have matched                   | `is_page()` true      |
| `$wp_query` is the **default home query**    | `is_front_page()` false |

Because `$wp_query` still represents the default home query, **`is_front_page()`
returns true on every Orbit virtual URL**. The redirect fires, the user lands
on `/dashboard/`, and any state they were trying to reach (an activity, a
profile, an unsubscribe link) is lost.

## Fix

Bail on `is_app_route()` at the very top of the redirect callback, before
checking `is_front_page()`. `is_app_route()` reads the rewrite-populated
query vars directly, which are reliably set by priority 5:

```php
public static function redirect_logged_in_from_home() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Bail for Orbit virtual pages (profile, activity, unsubscribe).
    // At template_redirect priority 5 the rewrite has set their query
    // vars, but `handle_routes()` (priority 10) hasn't yet replaced the
    // main query — so `is_front_page()` still reports true on these
    // URLs, which would incorrectly redirect logged-in users away from
    // every Orbit virtual page.
    if ( self::is_app_route() ) {
        return;
    }

    if ( ! is_front_page() ) {
        return;
    }

    nocache_headers();
    wp_safe_redirect( home_url( '/dashboard/' ), 303 );
    exit;
}
```

`is_app_route()` is the same helper `force_app_template()` uses, so the
"this is one of ours" check is centralized:

```php
public static function is_app_route() {
    return (bool) (
        get_query_var( 'orbit_profile_slug' )
        || get_query_var( 'orbit_activity_id' )
        || get_query_var( 'orbit_unsubscribe' )
    );
}
```

## Pattern

> **Any `template_redirect` callback at priority < 10 that branches on
> conditional tags (`is_front_page()`, `is_singular()`, `is_page()`,
> `is_archive()`, `is_404()`, etc.) is reading stale query state on routes
> that rely on a later-priority handler to swap or mutate the main query.**

The two reliable fixes:

1. **Run at priority ≥ 10.** Once `template_redirect` has finished its
   default work the conditional tags reflect the post-rewrite state. This
   is the right answer when nothing about the callback actually requires
   running earlier.
2. **Check the underlying query var directly.** `get_query_var()` reads
   the rewrite output and is correct from the moment query vars are
   parsed (well before priority 5). When a callback genuinely needs to
   run early — for example, to short-circuit before another priority-5
   callback — guard it with `get_query_var()` rather than
   `is_*()`.

This applies broadly. Any plugin or theme that registers virtual pages
via `add_rewrite_rule()` + a synthetic-`WP_Query` swap (the same pattern
WooCommerce, BuddyPress, bbPress, and many other apps use) will exhibit
the same hook-ordering trap. Conditional tags are a function of
`$wp_query`, and `$wp_query` is not "settled" for synthetic routes until
the rewriting hook runs.

### Quick diagnostic

If a `template_redirect` callback misbehaves only on routes powered by a
custom rewrite, log the callback's effective priority and the state of
both `get_query_var()` and the conditional tag at entry:

```php
add_action( 'template_redirect', function () {
    error_log( sprintf(
        'priority=%d activity_id=%s is_front_page=%s',
        // your priority,
        var_export( get_query_var( 'orbit_activity_id' ), true ),
        var_export( is_front_page(), true )
    ) );
}, 5 );
```

A query var with a value plus a "true" conditional tag for an unrelated
route is the signature of this bug.

## References

- Fix commit: `3c90809`
- Pull request: [#19](https://github.com/orbit/orbit/pull/19)
- Affected file: `includes/class-orbit-routes.php` line ~50 — `redirect_logged_in_from_home()`
- Related helper: `includes/class-orbit-routes.php` — `is_app_route()`
- Related: WordPress core `template_redirect` action — fires from `wp-includes/template-loader.php` after `parse_query` but before template selection.
