---
status: complete
priority: p1
issue_id: "025"
tags: [code-review, javascript, reliability, races]
dependencies: []
---

# `apiRequest()` has no fetch timeout — slow Twilio call locks submit button forever

## Problem Statement

`assets/js/orbit-forms.js:24-47` issues `fetch()` with no `AbortController`. Every submit handler in this file follows the same pattern: disable the submit button on submit, re-enable in `.finally()`. If the request never resolves (slow Twilio API, dropped connection, cell-network stall), `.finally()` never fires and the button is permanently disabled — no error message, no recovery short of a page reload.

Surfaced by PR #5 because the new "Send verification code" path is the most likely action to hit a slow third-party round trip (Twilio). But the bug is file-wide — it affects `respond`, `subscribers PATCH`, `activities DELETE`, `subscriptions DELETE`, etc.

## Proposed Solution

Add an `AbortController` with a default 30s timeout to `apiRequest()`:

```js
function apiRequest( endpoint, method, data, timeoutMs ) {
    var controller = new AbortController();
    var timer = setTimeout( function () { controller.abort(); }, timeoutMs || 30000 );

    var options = { /* ... existing ... */ signal: controller.signal };
    // ... existing fetch ...
    .finally( function () { clearTimeout( timer ); } );
}
```

Aborted fetches reject with `AbortError`, hitting the `.catch` → `.finally` → button re-enable. Error message can be left as-is or specialized for timeouts.

## Acceptance Criteria

- [ ] `apiRequest()` accepts an optional `timeoutMs` and defaults to 30s
- [ ] Timed-out request rejects with a clear "request timed out" message via `showMessage`
- [ ] Submit button re-enables after timeout
- [ ] Manual test: throttle network to "Offline" mid-request, confirm button re-enables within 30s
