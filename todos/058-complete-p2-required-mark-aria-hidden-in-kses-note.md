---
status: complete
priority: p2
issue_id: "058"
tags: [code-review, accessibility, i18n]
dependencies: []
---

# Required-mark span in kses note lacks aria-hidden

## Problem Statement

In `includes/class-orbit-shortcodes.php` (lines 683-686, 802-805, 1034-1037), the translatable required-fields note string is:

```
"Fields marked with <span class=\"orbit-required-mark\">*</span> are required."
```

The inline `<span>` has no `aria-hidden="true"`, while every label-side asterisk span does (e.g. lines 695, 809, 1042, 1048, 1292, 1297). Screen readers will announce "asterisk" once in the note paragraph — redundant and confusing, since the surrounding sentence already conveys the same meaning.

## Proposed Solution

1. Update the translatable string to:
   ```
   "Fields marked with <span class=\"orbit-required-mark\" aria-hidden=\"true\">*</span> are required."
   ```
2. Extend the `wp_kses` allowlist used to render this note so that `aria-hidden` is permitted on `span`.
3. When fix 053 (centralizing the required-note helper) lands, change in one place rather than three.

## Acceptance Criteria

- All three call sites render the note with `aria-hidden="true"` on the asterisk span.
- The `wp_kses` allowlist permits `aria-hidden` on `span` so the attribute survives sanitization.
- Screen-reader smoke check: the note paragraph reads "Fields marked with are required." (no "asterisk" announced).
- Visual rendering of the asterisk is unchanged.
