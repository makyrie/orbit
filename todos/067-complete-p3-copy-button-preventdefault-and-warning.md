---
status: complete
priority: p3
issue_id: "067"
tags: [code-review, javascript, developer-experience]
dependencies: []
---

# JS Copy button: preventDefault ordering + empty-target warning

## Problem Statement

In `assets/js/orbit-forms.js:558, 561`:

- `e.preventDefault()` fires before validation. If the selector is empty or invalid, the click is suppressed and nothing happens — silently broken from the user's perspective.
- An empty `data-orbit-copy-target` is silently ignored: `selector ? ... : null` swallows the misconfiguration with no developer feedback.

## Proposed Solution

- Move `e.preventDefault()` so it runs only after `if ( ! target ) return;` resolves a valid target.
- Add a `console.warn` (dev-only / conditional on a debug flag if appropriate) when `data-orbit-copy-target` is missing, empty, or fails to resolve, so misconfiguration surfaces during development.

## Acceptance Criteria

- [ ] `preventDefault()` runs only when a valid target element is found.
- [ ] Missing or unresolvable `data-orbit-copy-target` triggers a `console.warn`.
- [ ] Existing working copy buttons continue to function as before.
