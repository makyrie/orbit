---
status: complete
priority: p2
issue_id: "120"
tags: [code-review, PR-26, javascript, accessibility, ux]
dependencies: []
---

# Dashboard banner dismiss hides optimistically with no failure feedback or focus handoff

## Problem Statement

The dashboard banner dismiss in `orbit-forms.js` optimistically hides the banner first, then POSTs to record the dismissal in user_meta. Two bugs:

1. If the POST fails (rate limit, expired nonce, server error, network drop), the banner is gone from view but the user_meta flag is not written. On the next page load the banner reappears unchanged, with no explanation. The user has no signal that anything went wrong.
2. The dismiss button receives keyboard focus when clicked. After the banner is removed from the DOM, focus is left on a detached element, which screen readers and keyboard navigation handle poorly — focus typically jumps to `<body>` and the user loses their place.

## Findings

- `assets/js/orbit-forms.js:626-647` — dismiss handler hides the banner before awaiting the POST, no error branch in the `.catch`.
- No `aria-live` region or fallback focus target is set before the banner is removed.
- Surfaced by wp-javascript-reviewer during multi-agent review.

## Proposed Solutions

**Option A — Optimistic with rollback + focus handoff (recommended).**

1. Before removing the banner, identify the next focusable element (e.g. the page's `<main>` heading, or the next focusable sibling). Move focus there.
2. Hide the banner via `hidden` attribute, not `remove()`, so the rollback path can re-show it cleanly.
3. On POST failure, restore the banner, append an inline `role="alert"` error ("We couldn't save your preference — try again"), and move focus back to the dismiss button.

Effort: low (~25 LOC). Risk: low.

**Option B — Pessimistic.** Show a spinner, await the POST, then hide. Simpler error handling but slower-feeling interaction. Not preferred for a dismiss action where the user expects an immediate vanish.

## Recommended Action

Option A. Optimistic interactions are the right pattern here; the fix is to make the rollback path correct rather than to remove the optimism.

## Technical Details

- The fallback focus target should prefer `[data-orbit-banner-after]` if a theme/page wants to direct it; otherwise `main h1`, otherwise `main`.
- Use `element.hidden = true` rather than `element.remove()` so the rollback path doesn't need to re-render markup.
- The inline error region should reuse the form-level error styling so the user recognises it.
- Trigger a `focus()` call inside a `requestAnimationFrame` after `hidden = true` to avoid focus going to `body` first.

## Acceptance Criteria

- [x] On POST failure, the banner reappears with an inline error and focus returns to the dismiss button.
- [x] On POST success, focus has already moved to a stable target before the banner hides.
- [x] Manual keyboard test: tab to dismiss, press Enter, confirm focus lands on a sensible target (not body).
- [x] Manual offline test: dismiss with network disabled, confirm banner reappears with error message.
- [x] No console errors thrown when banner element is removed mid-animation.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Hardened the dismiss handler in `assets/js/orbit-forms.js`. Replaced the
  optimistic `display:none` with an aria-busy / faded-opacity acknowledgement that
  keeps the banner in the DOM until the POST resolves. On success, focus is moved
  to a stable target (preference: `[data-orbit-banner-after]` → `main h1` → `main`
  → `#main` → `body`, with a `tabindex="-1"` shim) inside a `requestAnimationFrame`
  before the banner is removed. On failure, the optimistic state is rolled back,
  an inline `role="alert"` `.orbit-onboarding-banner__error` span is appended with
  the server message (or a literal English fallback flagged with a TODO for i18n
  via `orbitForms.strings`), the dismiss button is re-enabled, and keyboard focus
  is returned to the dismiss button. JS-only — no changes to
  `class-orbit-rest-profile.php` or the REST endpoint shape. No `tests/js/`
  directory exists, so no jest test was added.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `assets/js/orbit-forms.js:626-647`
