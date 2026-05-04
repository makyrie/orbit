---
status: complete
priority: p3
issue_id: "034"
tags: [code-review, ux]
dependencies: []
---

# No "Resend code" button when user lands in pending state with expired code

## Problem Statement

If a user requests a code, closes the tab, and returns >10 minutes later, the page renders the code form (because `$phone && ! $verified`) saying "A 6-digit code was sent to +1…". Submitting any code returns `no_pending_code` (since the row expired). The only path forward is "Use a different number" → re-enter the *same* number to send a new code. Awkward.

## Proposed Solution

Add a "Resend code" button next to "Use a different number" in the code form. It POSTs to `/verify-phone` with the current `orbit_phone` value (rendered as a hidden input or read by JS), reusing the step-1 flow.

Alternative: detect expiry server-side and add a hint to the response/UI.

## Acceptance Criteria

- [ ] Resend button visible in pending-state code form
- [ ] Click triggers a new code without requiring user to retype phone
- [ ] Respects existing per-phone rate limit
