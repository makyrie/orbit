# Documentation Guide

This directory contains both current working guidance and intentionally
historical project artifacts. Use this map before treating an older statement
as current implementation truth.

## Current guidance

- [`../README.md`](../README.md) — installation, architecture, routes, data,
  operations, and development
- [`brand-brief.md`](brand-brief.md) — product identity and voice
- [`content-architecture.md`](content-architecture.md) — website information
  architecture and content needs
- [`creative-direction.md`](creative-direction.md) and
  [`design-system.md`](design-system.md) — visual direction and theme contract
- [`website-engagement.md`](website-engagement.md) — website work status and
  decisions
- [`marketing-plan.md`](marketing-plan.md) and
  [`gtm-playbook.md`](gtm-playbook.md) — launch strategy; operational readiness
  items should be revalidated before use
- [`compliance/`](compliance/) — canonical policy prose mirrored into the plugin

## Historical references

- [`refs/orbit-v1-spec.md`](refs/orbit-v1-spec.md) is the original v1 product and
  technical specification. It explains intent, but the code and README now
  supersede its file trees, schemas, route inventory, and implementation status.
- [`plans/`](plans/) records implementation decisions at the time each plan was
  written. Completed checkboxes and version references are historical evidence,
  not a live backlog.
- [`theme-qa-punch-list.md`](theme-qa-punch-list.md) and
  [`ux-audit-punch-list.md`](ux-audit-punch-list.md) are dated QA snapshots.
  Reproduce a finding before assuming it remains open.
- [`solutions/`](solutions/) contains durable problem/solution write-ups. Check
  cited paths and APIs against current code before applying them mechanically.

## Compliance synchronization

The privacy policy and terms are duplicated in
`includes/class-orbit-activator.php` so activation can create canonical
WordPress pages. When policy prose changes:

1. Edit the Markdown and PHP copies together.
2. Bump `ORBIT_VERSION`; consent records store the policy version.
3. Run `composer policy-diff` (or `php bin/check-policy-sync.php`).
