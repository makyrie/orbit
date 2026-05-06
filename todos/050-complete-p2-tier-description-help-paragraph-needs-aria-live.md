---
status: complete
priority: p2
issue_id: "050"
tags: [code-review, accessibility, javascript, php]
dependencies: []
---

# Tier-Description Help Paragraph Needs aria-live

## Problem Statement

In `includes/class-orbit-shortcodes.php:701`, the `<p class="orbit-help" data-orbit-tier-description>` is rendered inside `new_activity()` (and similarly in edit-activity). In `assets/js/orbit-forms.js:522-538`, JS updates the paragraph's `textContent` when the user changes the tier `<select>`.

Because the paragraph isn't a live region, screen readers don't announce the tier-description change. Users on screen readers pick a tier but get no feedback explaining what that commitment level means.

## Proposed Solution

In `includes/class-orbit-shortcodes.php:701`, add `aria-live="polite"` and `aria-atomic="true"` to the rendered paragraph:

```php
<p class="orbit-help" data-orbit-tier-description aria-live="polite" aria-atomic="true">
    <?php echo esc_html( $initial_description ); ?>
</p>
```

Apply the same change to the matching markup in `edit_activity()` if it has its own copy of the paragraph.

No JS change needed — `textContent` updates on a live region trigger announcements automatically.

## Acceptance Criteria

- [ ] `<p data-orbit-tier-description>` includes `aria-live="polite"` and `aria-atomic="true"` in both new- and edit-activity shortcodes.
- [ ] Screen reader (VoiceOver / NVDA) announces the new description text after the tier `<select>` changes.
- [ ] No regression to visual rendering of the help text.
