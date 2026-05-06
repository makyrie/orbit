---
status: complete
priority: p2
issue_id: "043"
tags: [code-review, php, documentation]
dependencies: []
---

# `template_redirect` priority 5 on `redirect_logged_in_from_home` is unexplained

## Problem Statement

`Orbit_Routes::register()` (`includes/class-orbit-routes.php:21`) hooks `redirect_logged_in_from_home` at priority `5`:

```php
add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_from_home' ), 5 );
```

The explicit priority signals "run before something at default 10" but the only other `template_redirect` hook in the plugin is `handle_routes` at default priority — and that one early-returns when no Orbit query var is set, so there's no race within the plugin.

Possible reasons to keep `5`: avoiding a redirect war with another plugin/theme on the home page (maintenance-mode, paywall, etc.), or running before `redirect_canonical`. None of these are documented.

Independently flagged by wp-php-reviewer (P3) and code-simplicity-reviewer (P2).

## Proposed Solutions

**Option A: drop the explicit priority** if there's no real reason for it. Default 10 is fine.

**Option B: keep the priority and document why** with a one-line comment:

```php
// Priority 5 so we redirect before redirect_canonical and any
// third-party `template_redirect` work on the home page.
add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_from_home' ), 5 );
```

Recommend Option B if the priority is intentional, Option A otherwise. Discover intent via testing or git blame.

## Acceptance Criteria

- [ ] Either priority is dropped to default OR a one-line comment explains why 5 is needed
- [ ] No regression in the redirect's behavior
