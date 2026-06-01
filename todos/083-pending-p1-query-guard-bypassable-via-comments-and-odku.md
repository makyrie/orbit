---
status: pending
priority: p1
issue_id: "083"
tags: [code-review, security, consent-ledger, append-only, PR-24]
dependencies: []
---

# Append-only ledger query guard bypassable via leading comments, INSERT ON DUPLICATE KEY UPDATE, and REPLACE INTO

## Problem Statement

`Orbit_Consent::filter_query()` enforces append-only via regex `^\s*(update|delete|truncate)\s+/i`. Three documented bypass paths:

1. **Leading SQL comments**: MySQL accepts `/* comment */ UPDATE ...`, `-- comment\nDELETE ...`, `# comment\nUPDATE ...`. The `\s*` does not consume comments, so anything that prepends a trace comment slips through. WordPress query-instrumentation plugins (Query Monitor's tracer, NewRelic's MySQL hooks) routinely prepend `/* */` comments.
2. **`INSERT ... ON DUPLICATE KEY UPDATE`**: starts with `INSERT`, regex misses it. The UNIQUE KEY `chain_pos (user_id, channel, prev_hash)` makes ODKU a functional UPDATE — overwrite an existing chain row by colliding on the unique key.
3. **`REPLACE INTO`**: starts with `REPLACE`, regex misses it. DELETE-then-INSERT semantics on UNIQUE KEY collision.

Either ODKU or REPLACE INTO can silently rewrite ledger rows without firing the `E_USER_WARNING` that operators rely on for forensic signal. TCPA defense weakened.

## Findings

- `includes/class-orbit-consent.php:423` — guard regex.
- `includes/class-orbit-consent.php:404-435` — guard callback.

## Proposed Solutions

**Option A — Invert to allow-list (recommended):**

```php
// Strip leading SQL comments before matching.
$stripped = preg_replace( '#^\s*(/\*.*?\*/|--[^\n]*\n|#[^\n]*\n|\s)+#s', '', $query );
$first_word = strtoupper( strtok( $stripped, " \t\n(" ) );

// Only INSERT is permitted (and even that must not be ODKU).
if ( 'INSERT' !== $first_word || false !== stripos( $stripped, 'ON DUPLICATE KEY UPDATE' ) ) {
    trigger_error( 'Orbit_Consent: refused non-INSERT write...', E_USER_WARNING );
    return 'SELECT 1 WHERE 1 = 0';
}
```

**Option B — Extend the deny-list** to include `replace`, `insert ... on duplicate`, and a leading-comment stripper. More fragile (adversarial input can find another bypass).

Recommend **Option A**. Insert is the only legitimate ledger write; everything else (UPDATE/DELETE/REPLACE/ODKU/TRUNCATE/RENAME/etc.) should be blocked by default.

Also tighten the cheap-path substring check: `is_consent_ledger_query()` currently uses `strpos($query, $table_name)`, which false-positives on any query that mentions the table name as a string literal (e.g., an audit-log INSERT that captures SQL text). Use a word-boundary regex (`\b<table>\b`) instead.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-consent.php`
- The allow-list approach handles future MySQL syntax additions correctly: anything unknown gets blocked.

## Acceptance Criteria

- [ ] `/* trace */ UPDATE wp_orbit_consent_ledger SET ...` is blocked.
- [ ] `INSERT INTO wp_orbit_consent_ledger ... ON DUPLICATE KEY UPDATE ...` is blocked.
- [ ] `REPLACE INTO wp_orbit_consent_ledger ...` is blocked.
- [ ] Legitimate `INSERT INTO wp_orbit_consent_ledger (...) VALUES (...)` still works.
- [ ] Tests added for each bypass attempt.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel, wp-php-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-consent.php:423`
