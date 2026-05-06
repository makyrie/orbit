---
status: complete
priority: p2
issue_id: "038"
tags: [code-review, performance, caching, php]
dependencies: []
---

# `redirect_logged_in_from_home()` should call `nocache_headers()` before redirect

## Problem Statement

`Orbit_Routes::redirect_logged_in_from_home()` (`includes/class-orbit-routes.php:46-57`) issues a `wp_safe_redirect()` without first emitting cache-control headers. WordPress's standard logged-in-cookie pattern means most caches WILL bypass caching for these responses, but edge caches (Cloudflare, CloudFront) without cookie-aware rules, or proxy layers without seeing the `wordpress_logged_in_*` cookie, can cache the 302 and serve it to anonymous visitors — breaking the marketing site.

wp-php-reviewer rated this P1 ("real production risk on cached sites"); security-sentinel rated it CLEAN; call-chain-verifier didn't surface it. P2 reflects "defense-in-depth on top of WP's standard protection."

## Proposed Solution

```php
public static function redirect_logged_in_from_home() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    if ( ! is_front_page() ) {
        return;
    }
    nocache_headers();
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}
```

## Acceptance Criteria

- [ ] `nocache_headers()` called before `wp_safe_redirect()`
- [ ] No regression in normal redirect behavior (logged-in user still goes to `/dashboard/`)
- [ ] Anonymous visitor on `/` still sees the marketing front page
