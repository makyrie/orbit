---
title: "ORDER BY nullable column puts NULLs first by default in MySQL"
category: database-issues
problem_type: query_ordering
severity: medium
components: [Orbit_Activity, dashboard, list_by_profile_ids]
tags: [wpdb, null-handling, order-by, mysql, sort-order, dashboard]
date_discovered: 2026-05-05
date_resolved: 2026-05-05
---

# `ORDER BY` Nullable Column Puts NULLs First by Default in MySQL

## Problem Symptom

The dashboard listed activities in the order they were created (newest first), so events happening tomorrow could be buried behind something added last week with no date set. Switching the query to `ORDER BY date_time ASC` to surface upcoming events appeared to "work," but tier-1 *"just an idea"* activities — which have no date at all — silently crowded the top of the list above genuine upcoming events.

## Root Cause

MySQL has no `NULLS FIRST` / `NULLS LAST` syntax (it's a parse error). Its default treats `NULL` as lower than any value:

- `ORDER BY col ASC` → NULLs **first**
- `ORDER BY col DESC` → NULLs **last**

The `orbit_activities.date_time` column is `datetime DEFAULT NULL`, populated only when the poster sets a date. With `orderby = 'date_time'` and `order = 'ASC'`, every undated activity sorted to position 0, so the top of the dashboard was a wall of "ideas" instead of the next concrete plans.

This is the inverse of PostgreSQL's default (NULLs *high*), so an idiom that "looks" right on Postgres breaks on MySQL and vice versa. WordPress's `$wpdb` and `WP_Query` do not paper over this — you write the SQL.

## Working Solution

Sort `IS NULL` first as a boolean expression, *then* the actual column. `FALSE` (has-a-date) sorts before `TRUE` (NULL), so dated rows always come first — regardless of whether the secondary sort is `ASC` or `DESC`:

```php
// includes/class-orbit-activity.php
$order_clause = 'date_time' === $orderby
    ? "date_time IS NULL, date_time {$order}"
    : "{$orderby} {$order}";

$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$order_clause} LIMIT %d OFFSET %d";
```

Caller switches to chronological:

```php
// includes/class-orbit-shortcodes.php — dashboard()
$activities = Orbit_Activity::list_by_profile_ids(
    $profile_ids,
    array(
        'status'   => 'active',
        'per_page' => 50,
        'orderby'  => 'date_time',
        'order'    => 'ASC',
    )
);
```

The conditional in the model ensures other callers (e.g. the REST endpoint sorting by `created_at`) are untouched — the null-first guard only kicks in when the orderby column is actually nullable.

## Why This Idiom

- **Portable.** `IS NULL` is standard SQL — works on MySQL, MariaDB, SQLite, and Postgres.
- **Type-agnostic.** Works on any column type (datetime, varchar, int, etc.) — no need to invent a sentinel like `'9999-12-31'`.
- **Readable.** Intent is obvious to a future maintainer; the alternative `COALESCE(col, '9999-12-31')` invites questions about the magic value.
- **Cost.** It defeats a B-tree index on `col`, forcing a filesort. For a dashboard list (≤50 rows after a `WHERE status = 'active'` filter) this is negligible. For hot, large-table queries, prefer a `NOT NULL` schema with an explicit sentinel column instead.

## Prevention Rules

1. **Whenever you `ORDER BY` a nullable column, make NULL placement explicit.** Never rely on the engine default — MySQL puts NULLs first on ASC, PostgreSQL puts them last. Silent wrong is worse than loud wrong.
2. **Use `col IS NULL` as a primary sort key** to push nulls to the end (or `NOT col IS NULL` to push them to the front). Works the same regardless of the secondary `ASC`/`DESC` direction.
3. **Gate the workaround on the orderby column.** Apply the `IS NULL` prefix only when the orderby field is actually nullable, so unrelated callers keep their plain ordering.
4. **Test with mixed data.** A list query with all-dated rows looks fine; the bug only surfaces once one undated row exists. Seed test fixtures with at least one NULL when verifying sort order.

## References

- Fix commit: `9c995be` ("fix: sort dashboard activities chronologically by upcoming date")
- Affected files:
  - `includes/class-orbit-activity.php` — `list_by_profile_ids()` order clause
  - `includes/class-orbit-shortcodes.php` — `dashboard()` query args
- Schema: `orbit_activities.date_time datetime DEFAULT NULL` (`includes/class-orbit-activator.php:82`)
- Related: `database-issues/wpdb-prepare-null-to-zero.md` — different NULL gotcha (write-side, INSERT format specifiers)
