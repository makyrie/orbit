---
status: complete
priority: p3
issue_id: "063"
tags: [code-review, security, defense-in-depth, php]
dependencies: []
---

# `redirect_after_login` should use `wp_validate_redirect()` for explicit defense

## Problem Statement

In `includes/class-orbit-routes.php:88-100`, the login redirect filter checks `0 !== strpos( $requested_redirect_to, $wp_admin_url )` and returns the upstream `$redirect_to`. The security-sentinel reviewer confirmed this is safe end-to-end (WP core's `wp_safe_redirect( $redirect_to )` validates the host downstream), but `strpos` is a brittle prefix check, and the implementation should not depend implicitly on downstream validation.

## Proposed Solution

Replace the `strpos` prefix check with an explicit `wp_validate_redirect( $requested_redirect_to, home_url( '/dashboard/' ) )` call so the validation is local, obvious, and defends in depth regardless of downstream behavior.

## Acceptance Criteria

- [ ] `redirect_after_login` in `includes/class-orbit-routes.php` uses `wp_validate_redirect()` with `home_url( '/dashboard/' )` as the fallback.
- [ ] The brittle `strpos` admin-URL prefix check is removed.
- [ ] Existing redirect flows (admin login, dashboard fallback, externally-supplied `redirect_to`) still behave correctly.
