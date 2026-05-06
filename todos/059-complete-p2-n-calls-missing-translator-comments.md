---
status: complete
priority: p2
issue_id: "059"
tags: [code-review, i18n, php]
dependencies: []
---

# Multiple `_n()` calls missing translator comments

## Problem Statement

Several `_n()` call sites in `includes/class-orbit-shortcodes.php` (lines 120, 122, 447, 449, 514, 593-602, 951) lack the `/* translators: %d: count */` comment that WPCS i18n best-practice requires. Even when `%d` looks unambiguous in English, translators reading only the extracted strings cannot tell whether `%d` represents a count, an ID, or something else — leading to ambiguous or incorrect translations.

## Proposed Solution

Add a `/* translators: %d: count */` comment immediately preceding each affected `_n()` call. Tailor the wording where the placeholder represents something more specific (e.g. `/* translators: %d: number of pending subscriptions */`).

## Acceptance Criteria

- Every `_n()` call in the listed line ranges has a `/* translators: ... */` comment immediately above it.
- `phpcs` with WPCS no longer flags `WordPress.WP.I18n.MissingTranslatorsComment` on these lines.
- Comments accurately describe what each placeholder represents.
