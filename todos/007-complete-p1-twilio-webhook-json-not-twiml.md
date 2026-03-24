---
status: pending
priority: p1
issue_id: "007"
tags: [code-review, php, integration]
dependencies: []
---

# Twilio Webhook Returns JSON Instead of TwiML

## Problem Statement

The Twilio incoming webhook handler returns a `WP_REST_Response` (JSON), but Twilio expects a TwiML XML response. While Twilio won't fail on a 200 JSON response, it will log warnings and it prevents sending reply messages (e.g., "You have been unsubscribed from SMS").

## Findings

- **PHP reviewer (#17):** Twilio expects `<Response></Response>` TwiML, not JSON.
- **Call-chain verifier (C-005-A):** Confirmed the response format mismatch.

**Affected file:** `includes/class-orbit-rest-api.php:620-621`

## Proposed Solutions

### Option A: Return empty TwiML response
```php
header( 'Content-Type: text/xml' );
echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
exit;
```
- **Effort:** Small
- **Risk:** Low

## Acceptance Criteria

- [ ] Twilio webhook returns valid TwiML XML with Content-Type text/xml
- [ ] STOP/START handling still works correctly
