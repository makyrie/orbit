---
status: complete
priority: p2
issue_id: "055"
tags: [code-review, consistency, copy]
dependencies: []
---

# Settings + New/Edit Activity Missing Top-Level Page Intros

## Problem Statement

Several shortcode pages are missing the page-level intro paragraph (`<p class="orbit-page-intro">`) that other pages consistently include:

- `includes/class-orbit-shortcodes.php:299` — Settings: `<h1>` is followed straight by the (mis-scoped) section, no top-level intro.
- `includes/class-orbit-shortcodes.php:682` — New Activity: `<h1>` is followed only by the required-note, no intro.
- `includes/class-orbit-shortcodes.php:797` — Edit Activity: `<h1>` is followed only by the required-note, no intro.

By contrast, My Subscriptions, Manage, Subscribers, Edit Profile, and Subscribe all have intro paragraphs. This makes the three pages above feel abrupt and visually inconsistent.

## Proposed Solution

In `includes/class-orbit-shortcodes.php`, add a `<p class="orbit-page-intro">…</p>` immediately after each affected `<h1>`. Suggested copy:

- **Settings (`:299`)**: "How you want Perihelion to reach you and what shows up in your daily digest."
- **New Activity (`:682`)**: "Tell your subscribers what you're up to. Pick a commitment level so they know how to read it."
- **Edit Activity (`:797`)**: "Update an existing post. Subscribers won't be re-notified by edits."

Wrap each in `__( ..., 'orbit' )` for translation and `esc_html_e()` (or equivalent) when echoing.

For Settings specifically, this pairs with finding 054 — adding the page-level intro lets the existing mis-scoped paragraph be demoted to `orbit-help`.

## Acceptance Criteria

- [ ] Settings page renders an `orbit-page-intro` directly under `<h1>Settings</h1>`.
- [ ] New Activity renders an `orbit-page-intro` directly under its `<h1>`.
- [ ] Edit Activity renders an `orbit-page-intro` directly under its `<h1>`.
- [ ] All copy is wrapped in `__()` with the `orbit` text domain.
- [ ] Visual consistency check: all shortcode pages now follow the H1 → page-intro → content pattern.
