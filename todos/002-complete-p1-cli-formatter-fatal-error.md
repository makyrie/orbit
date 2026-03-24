---
status: pending
priority: p1
issue_id: "002"
tags: [code-review, php, cli]
dependencies: []
---

# WP_CLI\Formatter\Args Fatal Error — All CLI Commands Broken

## Problem Statement

`Orbit_CLI::format_output()` instantiates `\WP_CLI\Formatter\Args` which does not exist in WP-CLI. Any CLI command that calls this method will fatal error at runtime. The `\WP_CLI\Formatter` constructor accepts an associative array directly as its first argument.

## Findings

- **PHP reviewer (#18):** `\WP_CLI\Formatter\Args` is not a real WP-CLI class. Fatal error on any CLI use.
- Note: The `output_item()` and `output_items()` methods also use `\WP_CLI\Formatter` but construct it correctly with `$assoc_args` directly. Only `format_output()` is broken.

**Affected file:** `cli/class-orbit-cli.php:51`

## Proposed Solutions

### Option A: Fix format_output to use WP_CLI\Formatter correctly
```php
$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
```
- **Effort:** Small
- **Risk:** Low

### Option B: Remove format_output entirely since output_item/output_items are used instead
- **Effort:** Small — verify no callers exist
- **Risk:** Low

## Acceptance Criteria

- [ ] `wp orbit status --format=json` runs without fatal error
- [ ] `wp orbit profile list` runs without fatal error
- [ ] All CLI commands execute successfully
