---
status: complete
priority: p3
issue_id: "069"
tags: [code-review, consistency, copy, polish]
dependencies: [057]
---

# Help-text style + casing consistency micro-pass

## Problem Statement

Lower-priority follow-up to todo 057. Various small inconsistencies in help text and label casing remain in `includes/class-orbit-shortcodes.php`:

- `:702` — tier-description help text may not end with a period (depends on `get_tier_descriptions()` content; verify and align).
- `:748, 867` — strings end with `?` (questions); fine, but consider rewriting to declarative form for parity with surrounding sentences.
- `:1050` — string ends with a colon followed by `<code>`, not a sentence — atypical pattern.
- Casing drift: some labels lowercased in this PR (`SMS daily cap`, `Digest time`) while most surrounding labels remain Title Case.

## Proposed Solution

After the bigger consistency pass in todo 057 lands, run a micro-pass for:

- Help-text terminal punctuation (period, question mark, colon).
- Label casing — pick Title Case or sentence case site-wide and align.

## Acceptance Criteria

- [ ] Help-text punctuation is consistent across the shortcode file (or any deviations are intentional and noted).
- [ ] Label casing follows a single convention across settings forms.
- [ ] Translatable strings remain wrapped in `__()` / `esc_html__()` etc.
- [ ] No functional changes.
