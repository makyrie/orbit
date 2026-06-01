---
status: pending
priority: p2
issue_id: "103"
tags: [code-review, security, consent-ledger, PR-24]
dependencies: []
---

# with_migration_mode flag leaks if callable exit()s or wp_die()s — guard bypass persists

## Problem Statement

`Orbit_Consent::with_migration_mode()` uses `try { $callback(); } finally { $in_migration_mode = $prior; }` — correct for synchronous return and thrown exceptions. But:

- `exit()`, `die()`, and `wp_die()` inside the callback BYPASS the `finally` block (PHP's shutdown sequence runs `register_shutdown_function` callbacks but does NOT execute pending `finally` blocks in already-entered try blocks).
- After such a bypass, `self::$in_migration_mode` stays `true` for the rest of the PHP process.
- Subsequent code in the same process (e.g., a WP-CLI command processing many users sequentially, or a long-running batch import) would have its UPDATEs against the ledger silently permitted.

The guard's whole point is to prevent unintended writes; a stuck `true` state defeats it for the rest of the process.

Also: `$in_migration_mode` is declared `protected`, not `private`. A subclass or reflection could mutate it directly without going through the wrapper.

## Proposed Solutions

**Option A — Add a `register_shutdown_function` safety reset + tighten visibility (recommended):**

```php
public static function with_migration_mode( callable $callback ) {
    $prior = self::$in_migration_mode;
    self::$in_migration_mode = true;

    // Defense against exit() / wp_die() inside the callback.
    register_shutdown_function( function () use ( $prior ) {
        self::$in_migration_mode = $prior;
    } );

    try {
        return $callback();
    } finally {
        self::$in_migration_mode = $prior;
    }
}
```

The shutdown function runs only if the script reaches PHP shutdown; if the try/finally executed normally, the shutdown callback also re-runs the reset (idempotent — sets prior twice with the same value).

Change `protected static $in_migration_mode` → `private static`.

**Option B — Document the gotcha in the docblock; do nothing else.** Acceptable since production callers won't `exit()` mid-migration, but tests using `expectException()` could trip it.

Recommend **Option A**. Defense in depth costs ~5 lines.

## Acceptance Criteria

- [ ] `register_shutdown_function` safety net added.
- [ ] Property visibility changed to `private`.
- [ ] Docblock warns about the exit/wp_die gotcha.
- [ ] Test added: nested `with_migration_mode` correctly restores outer state.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel, data-integrity-guardian.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-consent.php:43-53, 449-457`
