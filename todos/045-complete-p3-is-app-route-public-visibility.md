---
status: complete
priority: p3
issue_id: "045"
tags: [code-review, php, refactor]
dependencies: []
---

# `Orbit_Routes::is_app_route()` should be public

## Problem Statement

`Orbit_Routes::is_app_route()` (`includes/class-orbit-routes.php:83-89`) is a pure read of three query vars with no side effects. It's currently `private`, called only by `force_app_template()`. Making it `public` costs nothing and prevents the inevitable future copy-paste duplication when other code (a body-class filter, an enqueue gate, an admin-bar tweak, the existing `add_noindex_meta()` at line 369 which already does adjacent work) wants the same predicate.

## Proposed Solution

Change visibility from `private static` to `public static`. Optionally refactor `add_noindex_meta()` to use it:

```php
public static function add_noindex_meta() {
    $noindex_pages = orbit_get_internal_page_slugs();
    if ( is_page( $noindex_pages ) || self::is_app_route() ) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
}
```

(Currently `add_noindex_meta` only checks `orbit_activity_id` — broadening to all app routes via `is_app_route()` would also noindex profile pages and the unsubscribe flow, which makes sense.)

## Acceptance Criteria

- [ ] `is_app_route()` is public
- [ ] No regression in current callers
- [ ] Optionally: `add_noindex_meta()` consumes the helper and noindexes profile/unsubscribe routes too
