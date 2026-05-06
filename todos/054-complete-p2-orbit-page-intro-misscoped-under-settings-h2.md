---
status: complete
priority: p2
issue_id: "054"
tags: [code-review, consistency, php, css]
dependencies: []
---

# `.orbit-page-intro` Mis-scoped Under Settings H2

## Problem Statement

In `includes/class-orbit-shortcodes.php:300-301`, a `<p class="orbit-page-intro">` is rendered under the `<h2>Notification Preferences</h2>` heading inside the Settings page — not under the page-level `<h1>Settings</h1>`.

The class `orbit-page-intro` is reserved for page-level introductory copy (sits directly under the H1). Using it under an H2 makes its semantics inconsistent across the codebase and can produce subtle visual hierarchy issues (e.g. larger top margin appearing in the wrong place).

## Proposed Solution

Pick one of three approaches in `includes/class-orbit-shortcodes.php:300-301`:

1. **Move/duplicate the intro under the `<h1>Settings</h1>`** — promote the paragraph so it explains the whole page, and drop or restate the section-specific bit elsewhere.
2. **Rename the class** — change the class on the misplaced `<p>` to `orbit-section-intro` (or `orbit-help`) and add matching CSS for that class.
3. **Add a top-level Settings page intro** (covered by finding 055) and demote this paragraph to plain `orbit-help`.

The cleanest combined fix is option 3 paired with finding 055: add a real page-level intro under `<h1>Settings</h1>`, and change this paragraph to `class="orbit-help"`.

## Acceptance Criteria

- [ ] `.orbit-page-intro` only appears directly under `<h1>` elements anywhere in `class-orbit-shortcodes.php`.
- [ ] The paragraph at `:300-301` either moves to under the H1, gets renamed, or gets demoted to `orbit-help`.
- [ ] CSS for any new class (e.g. `orbit-section-intro`) exists or the demotion to `orbit-help` produces an acceptable visual result.
- [ ] No regression to other pages that already use `.orbit-page-intro` correctly.
