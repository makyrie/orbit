---
title: "$wpdb->prepare() silently converts NULL to 0 for integer columns"
category: database-issues
problem_type: data_corruption
severity: critical
components: [Orbit_Notifier, notification_preferences]
tags: [wpdb, null-handling, wordpress, prepare, silent-failure]
date_discovered: 2026-03-24
date_resolved: 2026-03-24
---

# $wpdb->prepare() Silently Converts NULL to 0

## Problem Symptom

Every new subscriber silently received zero SMS notifications. Users configured SMS for tier 3 alerts, subscribed, and never received a text — with no error messages anywhere. The system appeared functional but quietly routed every SMS to the email digest fallback.

## Root Cause

`$wpdb->prepare()` cannot represent SQL `NULL`. When you pass PHP `null` with any format specifier:

- `%s` converts `null` to empty string `''`
- `%d` converts `null` to `0`

The `sms_daily_cap` column (`smallint unsigned DEFAULT NULL`) was being inserted with `null` and format `'%s'`, which stored `0` instead of `NULL`. The cap-checking function `is_sms_cap_reached()` then evaluated `0 >= 0` → always true → all SMS blocked.

## Kill Chain

1. `get_or_create_preferences()` inserts row with `sms_daily_cap = 0` (intended: `NULL`)
2. `is_sms_cap_reached()` checks `null === $prefs->sms_daily_cap` → `false` (value is `0`)
3. Compares `$today_count >= (int) $prefs->sms_daily_cap` → `0 >= 0` → always `true`
4. Every SMS gets downgraded to digest

## Working Solution

**For INSERT: Omit nullable columns entirely.** Let the schema's `DEFAULT NULL` handle it:

```php
// BEFORE (buggy):
$wpdb->insert( $table, array(
    'sms_daily_cap' => null,  // BUG: %s → '' → MySQL casts to 0
), array( '%s' ) );

// AFTER (fixed):
$wpdb->insert( $table, array(
    // sms_daily_cap omitted — column DEFAULT NULL applies
), array() );
```

**For UPDATE: Use raw SQL with literal NULL:**

```php
// BEFORE (buggy):
$data['sms_daily_cap'] = absint( $args['sms_daily_cap'] ); // absint(null) = 0

// AFTER (fixed):
if ( null === $args['sms_daily_cap'] ) {
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET sms_daily_cap = NULL WHERE user_id = %d",
        $user_id
    ) );
} else {
    $data['sms_daily_cap'] = absint( $args['sms_daily_cap'] );
}
```

## Prevention Rules

1. **Never pass PHP `null` through `$wpdb` format specifiers.** There is no `%null` format.
2. **For INSERT: omit nullable columns** and rely on `DEFAULT NULL`.
3. **For UPDATE: write literal `NULL` in the SQL** string, not as a bound parameter.
4. **Use `array_key_exists()` not `isset()`** — `isset($args['key'])` returns `false` when value is `null`.
5. **Test the round-trip:** after inserting, SELECT back and assert `null === $row->column`.

## References

- Fix commit: `48b45cb` (resolve 18 code review findings)
- Affected file: `includes/class-orbit-notifier.php` — `get_or_create_preferences()`, `update_preferences()`
- Todo: `todos/001-complete-p1-sms-daily-cap-null-stored-as-zero.md`
