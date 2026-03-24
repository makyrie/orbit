---
status: pending
priority: p3
issue_id: "018"
tags: [code-review, quality, php]
dependencies: []
---

# Tier Labels Array Duplicated 8 Times

## Problem Statement

The tier labels array (`1 => 'Just an idea', 2 => "I'll go if you will", 3 => "I'm going — join me"`) is defined identically in 8 separate locations across 4 files.

## Proposed Solutions

Extract to `Orbit_Activity::get_tier_labels()` static method or class constant.

## Acceptance Criteria

- [ ] Single source of truth for tier labels
- [ ] All 8 locations reference the shared definition
