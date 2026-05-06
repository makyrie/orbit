---
status: complete
priority: p2
issue_id: "042"
tags: [code-review, css, simplicity]
dependencies: []
---

# `.orbit-status-badge { vertical-align: 1px; }` is unidiomatic CSS

## Problem Statement

`assets/css/orbit.css:529` has `vertical-align: 1px;` on the new `.orbit-status-badge` rule. Bare lengths on `vertical-align` are valid CSS for inline elements (shifts the baseline by that distance) but support is patchy and the result depends on surrounding font/line-height. It's also the only `vertical-align` length value in the codebase, so it'll confuse the next maintainer.

Independently flagged by wp-php-reviewer (P3) and code-simplicity-reviewer (P2). Most likely it's a 1px nudge to align the badge baseline with the link text next to it.

## Proposed Solutions

**Option A: keyword + line-height combo (most idiomatic)**

```css
.orbit-status-badge {
    /* ... */
    vertical-align: middle;
    line-height: 1;
}
```

**Option B: positioning nudge (most precise)**

```css
.orbit-status-badge {
    /* ... */
    position: relative;
    top: -1px;
}
```

**Option C: adjust badge padding instead** — change top/bottom padding so the natural baseline aligns; remove the `vertical-align` line entirely.

Option A is preferred: simplest, robust to font changes.

## Acceptance Criteria

- [ ] `vertical-align: 1px` replaced
- [ ] Visual alignment of the status badge next to the title link looks correct in browser
- [ ] No regression in other contexts where `.orbit-status-badge` might be used
