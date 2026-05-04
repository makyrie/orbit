---
status: pending
priority: p3
issue_id: "037"
tags: [code-review, accessibility]
dependencies: []
---

# Code-form reveal isn't announced to screen readers

## Problem Statement

When the phone form succeeds, JS sets `codeForm.hidden = false` and focuses the code input. Sighted users see the form swap; screen-reader users get focus moved to a `<label>` + `<input>` with no announcement of the state change ("A 6-digit code was sent to +1…" is now visible but not read aloud).

## Proposed Solution

Add `aria-live="polite"` to the code-form section (or to the `.orbit-code-sent-msg` paragraph) so the message is announced when revealed:

```php
echo '<p class="orbit-code-sent-msg" aria-live="polite">';
```

Alternative: a dedicated `role="status"` region updated by JS at the moment of swap.

## Acceptance Criteria

- [ ] Screen reader announces "A 6-digit code was sent to +1…" when code form is revealed
- [ ] No double-announcement when initial pending state renders the form server-side
- [ ] Tested with VoiceOver or NVDA
