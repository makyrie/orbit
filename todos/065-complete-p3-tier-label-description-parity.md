---
status: complete
priority: p3
issue_id: "065"
tags: [code-review, testing, php]
dependencies: []
---

# Tier label/description parity not asserted

## Problem Statement

In `includes/class-orbit-activity.php:419-435`, `get_tier_descriptions()` is now parallel to `get_tier_labels()`. If a future tier is added to `get_tier_labels()` without a matching entry in `get_tier_descriptions()`, the `?? ''` fallback at `class-orbit-shortcodes.php:699` will silently swallow the missing description — the tier dropdown loses its description text with no warning.

## Proposed Solution

Pick one of:

1. Fold both into a single `get_tiers()` returning `array( 1 => array( 'label' => ..., 'description' => ... ) )` so labels and descriptions cannot drift apart.
2. Add a unit test asserting `array_keys( get_tier_labels() ) === array_keys( get_tier_descriptions() )`.

## Acceptance Criteria

- [ ] Either the data structure is unified, or a unit test asserts label/description key parity.
- [ ] Adding a tier to one set without the other fails fast (test failure or impossible by construction).
- [ ] Existing call sites continue to work without behavior changes.
