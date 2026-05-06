---
status: complete
priority: p2
issue_id: "048"
tags: [code-review, javascript, accessibility]
dependencies: []
---

# Copy Button Race on Rapid Clicks

## Problem Statement

In `assets/js/orbit-forms.js:543-590` (the copy-to-clipboard handler), two clicks within 1500ms produce two pending `setTimeout` calls. The first timer fires and resets the button label to `defaultLabel` while the user is still seeing "Copied!" from the second click — so the confirmation visually disappears earlier than expected.

There's also a related fragility: if the PHP shortcode doesn't emit `data-orbit-copy-label`, `defaultLabel` is captured from the button's current `textContent`. If the user clicks during the confirmation window and the code re-reads the label, `defaultLabel` can become "Copied!" and the original label is permanently lost.

## Proposed Solution

In `assets/js/orbit-forms.js:543-590`:

1. Track the pending timer on the element itself and clear it before scheduling a new one:
   ```js
   if ( button._orbitCopyTimer ) {
       clearTimeout( button._orbitCopyTimer );
   }
   button._orbitCopyTimer = setTimeout( function () {
       button.textContent = defaultLabel;
       button._orbitCopyTimer = null;
   }, 1500 );
   ```
2. Read `defaultLabel` once on first interaction (or from `data-orbit-copy-label`) and cache it in a closure or `data-*` attribute so it can never be overwritten by "Copied!".
3. In `includes/class-orbit-shortcodes.php`, ensure every copy button emits an explicit `data-orbit-copy-label="<original label>"` so the JS never has to fall back to the live `textContent`.

## Acceptance Criteria

- [ ] Rapid double-click on a copy button shows "Copied!" for the full 1500ms after the second click (no premature reset).
- [ ] Only one pending timeout exists per button at any time.
- [ ] `defaultLabel` cannot be overwritten by the confirmation text under any click sequence.
- [ ] PHP shortcode always renders `data-orbit-copy-label` on copy buttons.
- [ ] Manual test: spam-click copy button 5 times in 2 seconds — label always returns to original after final click + 1500ms.
