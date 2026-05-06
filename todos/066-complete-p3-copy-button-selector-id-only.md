---
status: complete
priority: p3
issue_id: "066"
tags: [code-review, defense-in-depth, javascript]
dependencies: []
---

# JS Copy button: harden selector to ID-only

## Problem Statement

In `assets/js/orbit-forms.js:561-562`, `document.querySelector( selector )` runs whatever attribute value is provided via `data-orbit-copy-target`. The two current call sites pass simple `#orbit-share-link` ID selectors, but as defense-in-depth (in case a future XSS pathway lets attackers control a `data-orbit-copy-target`), the resolver should restrict the attribute to ID-only references.

## Proposed Solution

Validate the attribute value matches `^#[A-Za-z0-9_-]+$` before passing to `querySelector`, or use `document.getElementById( selector.slice( 1 ) )` after stripping the leading `#`. Reject (and ignore) any value that does not match the expected ID pattern.

## Acceptance Criteria

- [ ] The copy-target attribute is validated against an ID-only pattern, or resolved via `getElementById`.
- [ ] Non-ID selectors (class, attribute, descendant combinators, etc.) no longer resolve.
- [ ] Existing copy-button call sites still work as expected.
