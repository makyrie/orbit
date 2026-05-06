---
status: complete
priority: p3
issue_id: "046"
tags: [code-review, http, php]
dependencies: []
---

# Consider 303 status code for `redirect_logged_in_from_home`

## Problem Statement

`wp_safe_redirect()` defaults to 302 (Found / Moved Temporarily). For "logged-in users always go to the dashboard," 303 (See Other) is semantically more correct — it explicitly tells the browser "the resource you wanted is over there" and prevents browsers from caching the redirect aggressively.

The practical risk is small: most browsers handle 302 and 303 identically in the GET case. But if Sarah ever sees the redirect "stick" after logout (where /dashboard/ keeps redirecting back), aggressive 302 caching would be the cause.

## Proposed Solution

```php
wp_safe_redirect( home_url( '/dashboard/' ), 303 );
```

## Acceptance Criteria

- [ ] Status code changed to 303
- [ ] Verified behavior is identical for normal logged-in / logged-out flows
- [ ] No regression in the post-logout redirect chain
