---
status: pending
priority: p2
issue_id: "129"
tags: [code-review, PR-26, content, drift-detection]
dependencies: []
---

# Privacy/terms policy content lives in two places with no drift detection

## Problem Statement

/privacy/ and /terms/ content currently exists in two places:

1. ~180 lines of Gutenberg-block heredocs inside `class-orbit-activator.php:336-518` (used to create the canonical pages on activation).
2. Markdown source-of-truth at `docs/compliance/privacy-policy.md` and `docs/compliance/terms-of-service.md` (declared canonical by the plan).

The .md files are declared canonical, but enforcement is by-hand. A maintainer editing the rendered page in wp-admin, or a developer editing the PHP heredoc, can silently desync the two. The consent ledger references the rendered page; a TCR-relevant change made only to the .md is invisible. Worse, only the activator code is byte-checkable in CI.

## Findings

- `includes/class-orbit-activator.php:336-518` — block-markup heredocs for privacy and terms.
- `docs/compliance/privacy-policy.md` and `docs/compliance/terms-of-service.md` — the supposedly-canonical sources.
- No CI step, lint, or smoke test compares the two.
- Activator docblock says "Mirrors `docs/compliance/...`" — passive prose, no enforcement signal.
- Surfaced by simplicity-reviewer (finding #4) and architecture-strategist (finding #8) during multi-agent review.

## Proposed Solutions

**Option A — CI drift-detection script (recommended).** Add `bin/check-policy-sync.php`:

1. Render the activator's privacy/terms output to a string.
2. Strip block-markup wrappers (`<!-- wp:paragraph -->` etc.) to get prose.
3. Read the .md, strip frontmatter, normalize whitespace.
4. Diff. Exit non-zero on drift.

Wire as `composer policy-diff` and as a CI step. ~30 LOC, no vendor dependency.

Effort: low. Risk: low.

**Option B — Load .md at activation through a markdown→blocks parser.** Removes the duplication entirely but adds a vendor dependency (parsedown or similar) and changes activation semantics. Heavier than warranted.

## Recommended Action

Option A. At minimum, change the activator docblock from passive "mirrors" wording to explicit: "MUST byte-match prose in `docs/compliance/<file>.md` (block markup excluded). When updating, edit both and run `composer policy-diff`." The script makes the discipline mechanical.

## Technical Details

- The normalisation step needs to:
  - Strip Gutenberg block delimiters (`<!-- wp:... -->`, `<!-- /wp:... -->`).
  - Strip surrounding `<p>` and `<h2>` tags inside heredocs.
  - Strip frontmatter from .md.
  - Collapse runs of whitespace and normalise newlines.
- Exit 0 on match, non-zero with a unified diff on mismatch.
- The CI job should run on PRs that touch either file.
- Document the workflow in AGENTS.md so editors know to edit both files.

## Acceptance Criteria

- [ ] `bin/check-policy-sync.php` exists and exits non-zero on prose drift between activator output and .md.
- [ ] Composer script `composer policy-diff` runs the check.
- [ ] CI workflow runs the check on PRs that change activator or compliance .md files.
- [ ] Activator docblock spells out the MUST-byte-match contract.
- [ ] AGENTS.md documents the dual-edit workflow.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-activator.php:336-518`
- `docs/compliance/privacy-policy.md`
- `docs/compliance/terms-of-service.md`
- New: `bin/check-policy-sync.php`
- Related: todo 117 (canonical compliance page ownership)
