---
status: complete
priority: p2
issue_id: "039"
tags: [code-review, php, wordpress, fse]
dependencies: []
---

# `force_app_template` filter registers on two hooks; can double-prepend `page-app`

## Problem Statement

`Orbit_Routes::register()` (`includes/class-orbit-routes.php:32-34`) hooks `force_app_template` on both `page_template_hierarchy` AND `singular_template_hierarchy`. For virtual pages, `render_virtual_page()` sets `$wp_query->is_page = true` AND `is_singular = true`, so both hooks fire and `array_unshift($templates, 'page-app')` runs twice — leaving `['page-app', 'page-app', ...]` in the hierarchy.

Currently harmless (WP stops at first match), but wasteful and a foot-gun if anyone changes the unshift logic.

Independently flagged by wp-php-reviewer (P2) and code-simplicity-reviewer (P2).

## Proposed Solutions

**Option A (preferred): defensive guard against duplicate prepend**

```php
public static function force_app_template( $templates ) {
    if ( ! self::is_app_route() ) {
        return $templates;
    }
    if ( ! is_array( $templates ) ) {
        return array( 'page-app' );
    }
    if ( ! in_array( 'page-app', $templates, true ) ) {
        array_unshift( $templates, 'page-app' );
    }
    return $templates;
}
```

The `is_array` guard also protects against any upstream filter that returns a non-array.

**Option B: pick one hook.** Per template-loader.php, `is_page()` is checked before `is_singular()`, so `page_template_hierarchy` is the relevant one and the `singular_template_hierarchy` registration is dead code. Drop it. Simpler but loses the safety net if `is_page` ever flips off.

## Acceptance Criteria

- [ ] `array_unshift` is guarded so `force_app_template` running twice produces a hierarchy with one `page-app` entry, not two
- [ ] Non-array input (defensive) doesn't fatal
- [ ] Activity detail and profile pages still render with `page-app.html`
