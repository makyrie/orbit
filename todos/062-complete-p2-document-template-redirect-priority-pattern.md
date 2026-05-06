---
status: complete
priority: p2
issue_id: "062"
tags: [code-review, documentation, wordpress, routing]
dependencies: []
---

# Document the `template_redirect` priority + `is_app_route()` pattern

## Problem Statement

The learnings researcher confirmed there is no existing solution doc capturing a real WordPress routing gotcha that bit this plugin: `redirect_logged_in_from_home()` ran on `template_redirect` at priority 5, before `handle_routes()` (priority 10) had swapped the main query. At priority 5 the rewrite has already set Orbit query vars, but `is_front_page()` still reports true — so the redirect bounced logged-in users from every Orbit virtual page back to `/dashboard/`. The fix (commit `3c90809`) bails on `is_app_route()` at the top of the redirect callback. Future contributors hitting a similar symptom have no documented reference.

## Proposed Solution

Create a new solution doc at `docs/solutions/runtime-errors/template-redirect-priority-rewrite-vars.md` that captures:

- **Symptom:** logged-in users on Orbit virtual pages get bounced to `/dashboard/`.
- **Root cause:** `template_redirect` callbacks at priority < 10 see rewrite vars set but `is_front_page()` / `is_home()` still return true because `handle_routes()` has not yet swapped the main query.
- **The trap:** priority 5 vs priority 10 ordering on `template_redirect`.
- **The fix pattern:** guard early-priority redirects with `if ( $this->is_app_route() ) { return; }`.
- **Cross-reference:** fix commit `3c90809`.

Follow the existing format of other docs under `docs/solutions/runtime-errors/`.

## Acceptance Criteria

- New file exists at `docs/solutions/runtime-errors/template-redirect-priority-rewrite-vars.md`.
- Doc contains sections covering symptom, root cause, the priority trap, and the `is_app_route()` guard pattern.
- Commit `3c90809` is referenced as the canonical fix.
- Style and structure match sibling docs in `docs/solutions/runtime-errors/`.
