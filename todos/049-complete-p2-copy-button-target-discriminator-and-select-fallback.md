---
status: complete
priority: p2
issue_id: "049"
tags: [code-review, javascript, defensive]
dependencies: []
---

# Copy Button Target Discriminator and select() Fallback

## Problem Statement

In `assets/js/orbit-forms.js:567`, the discriminator `target.value !== undefined` is true for any form element including `<select>` and empty inputs (which return `''`), so non-input targets like `<span>` or `<p>` may be misclassified depending on shape.

Worse, in the fallback path at `assets/js/orbit-forms.js:581-587`, after `navigator.clipboard.writeText` fails or is unavailable, the code calls `target.focus(); target.select()`. The `select()` method only exists on `HTMLInputElement` / `HTMLTextAreaElement`. When the target is a `<span>` or `<p>` (using `textContent`), `select()` throws `TypeError`, which is silently caught — the user gets no copy and no feedback.

## Proposed Solution

In `assets/js/orbit-forms.js:567`:

1. Replace the loose check with an explicit type test:
   ```js
   var isTextField = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement;
   var text = isTextField ? target.value : target.textContent;
   ```
2. Branch the fallback at `:581-587` based on `isTextField`:
   - For text fields: use the existing `focus()` + `select()` + `document.execCommand('copy')` path.
   - For non-input targets: use the `Range` / `Selection` API:
     ```js
     var range = document.createRange();
     range.selectNodeContents( target );
     var selection = window.getSelection();
     selection.removeAllRanges();
     selection.addRange( range );
     document.execCommand( 'copy' );
     selection.removeAllRanges();
     ```
3. If both the async API and the fallback fail, surface a visible failure state on the button (e.g. swap to "Copy failed" briefly) instead of silently doing nothing.

## Acceptance Criteria

- [ ] Discriminator uses `instanceof HTMLInputElement || instanceof HTMLTextAreaElement` (or `'value' in target` with a stricter guard).
- [ ] Fallback for non-input targets uses Range/Selection API and does not call `select()`.
- [ ] Failure of both clipboard paths produces visible user feedback (e.g. "Copy failed" label).
- [ ] Manual test: copy button on a `<span>` works in a browser without `navigator.clipboard` (test by mocking).
- [ ] No `TypeError` thrown in the console under any target type.
