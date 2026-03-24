---
status: pending
priority: p3
issue_id: "024"
tags: [code-review, php]
dependencies: []
---

# NAMESPACE Constant Uses PHP Reserved Keyword

## Problem Statement

`Orbit_REST_API::NAMESPACE` uses a reserved keyword. Works at runtime but trips static analysis tools. Referenced by all 4 controller classes.

## Proposed Solutions

Rename to `API_NAMESPACE`. Update all references in the 4 controller files.

## Acceptance Criteria

- [ ] Constant renamed to `API_NAMESPACE`
- [ ] All 4 controllers updated
- [ ] No `NAMESPACE` references remain
