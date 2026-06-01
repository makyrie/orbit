---
status: pending
priority: p2
issue_id: "094"
tags: [code-review, twilio, webhook, PR-24]
dependencies: []
---

# Twilio invalid-signature returns 403 — Twilio retries for 24h, flooding the endpoint

## Problem Statement

`Orbit_REST_Notification::handle_twilio_incoming()` returns `WP_Error('invalid_signature', ..., array('status' => 403))` on signature failure. WordPress's REST infrastructure renders that as a 403 JSON response.

Twilio's retry policy on 4xx/5xx is to retry up to ~24h, exponentially backing off. A misconfigured Twilio Messaging Service URL (e.g., during a routing change) means hours of retries hitting the endpoint with the same invalid signature, polluting logs and consuming compute.

Twilio's documented best practice for "signature was invalid, please stop": return 204 No Content (or any 2xx). The endpoint drops the request silently from Twilio's perspective and Twilio doesn't retry.

## Proposed Solutions

**Option A — Return 204 on signature failure (recommended):**

```php
if ( ! Orbit_Twilio::validate_webhook( $request, $expected_url ) ) {
    return new WP_REST_Response( null, 204 );
}
```

The trade-off: ops loses visibility of "Twilio is sending us bad signatures" (would otherwise be a 403 spike in logs). Mitigation: log invalid-signature attempts to `error_log()` or to `wp_orbit_notification_log` with a special status, so the signal is still observable internally without provoking Twilio.

**Option B — Add an internal log row** without changing the HTTP response. Acceptable but doesn't stop the retry storm.

Recommend **Option A** with internal logging.

## Acceptance Criteria

- [ ] Bad-signature webhook returns 204.
- [ ] Invalid attempts are logged internally (error_log or DB).
- [ ] Test added: bad-signature request returns 204.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-rest-notification.php:82-105`
- [Twilio: Webhook retries](https://www.twilio.com/docs/usage/webhooks/webhooks-overview)
