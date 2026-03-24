---
status: pending
priority: p1
issue_id: "004"
tags: [code-review, security]
dependencies: []
---

# Subscription Secret Exposed in REST API Responses

## Problem Statement

Multiple REST API endpoints return raw subscription rows via `SELECT *`, which includes the `subscription_secret` column in JSON responses. The subscription secret grants unsubscribe capability without authentication. Exposing it in API responses means any XSS vulnerability, browser extension, or proxy logging would capture these secrets, enabling mass unsubscribes.

## Findings

- **Security sentinel (#3, #4):** `GET /subscriptions`, `GET /subscribers`, `PATCH /subscribers/{id}` all return subscription_secret.

**Affected endpoints in `class-orbit-rest-api.php`:**
- `get_subscriptions()` line 859-866
- `get_subscribers()` line 882-888
- `update_subscriber()` line 933

## Proposed Solutions

### Option A: Create a response-shaping helper that strips sensitive fields
```php
private static function shape_subscription( $sub ) {
    unset( $sub->subscription_secret );
    return $sub;
}
```
- **Effort:** Small
- **Risk:** Low

## Acceptance Criteria

- [ ] `subscription_secret` never appears in any REST API JSON response
- [ ] Unsubscribe flow still works (uses secret from URL, not API)
