---
status: complete
priority: p1
issue_id: "026"
tags: [code-review, javascript, races, twilio, cost]
dependencies: []
---

# Double-submit on "Send verification code" can fire two SMS messages

## Problem Statement

`assets/js/orbit-forms.js:116-138` disables the submit button after the submit event fires. Disabling protects against the most common case (mouse double-click), but rapid `Enter` keypresses (or autorepeat) can dispatch a second submit event in the same task queue before the disabled state takes effect. Result: two parallel POSTs to `/verify-phone`, two SMS messages billed by Twilio, two rate-limit decrements, and a UI race over which response wins.

Verification SMS is a per-message cost (real money). Even a 1% double-fire rate over many users is wasted spend.

## Proposed Solution

Use a synchronous form-level in-flight flag (faster to set than the disabled property):

```js
if ( form.dataset.orbitInFlight === '1' ) {
    e.preventDefault();
    return;
}
form.dataset.orbitInFlight = '1';
// ... existing logic ...
.finally( function () {
    delete form.dataset.orbitInFlight;
    if ( submitBtn ) submitBtn.disabled = false;
} );
```

`dataset` writes are synchronous and unaffected by repaint timing.

## Acceptance Criteria

- [ ] Form-level in-flight guard added to `apiRequest`-using forms
- [ ] Manual test: hold Enter on phone input, confirm only one POST hits `/verify-phone` per submission
- [ ] Manual test: throttle network, double-click submit, confirm only one Twilio SMS is sent
