---
status: pending
priority: p2
issue_id: "105"
tags: [code-review, timezone, action-tokens, PR-24]
dependencies: []
---

# compute_default_expiry() uses strtotime() against MySQL UTC datetime — UTC ambiguity on non-UTC server TZ

## Problem Statement

`Orbit_Token::compute_default_expiry()` does:

```php
$date_time = $wpdb->get_var( "SELECT date_time FROM activities WHERE id = %d" );
if ( $date_time ) {
    return strtotime( $date_time ) + self::ACTION_TOKEN_EXPIRY_DATED;
}
```

`strtotime( $date_time )` interprets the MySQL `datetime` string in PHP's default timezone (typically the server's local TZ unless overridden via `date_default_timezone_set` or `ini_set`). Activities are stored as UTC per the recent timezone fix.

On a server where PHP's default TZ is not UTC (very common on shared hosts), `strtotime` interprets the UTC datetime as local time. Effect: action tokens for activities near midnight UTC may expire one full day earlier or later than intended. The user gets a "this link expired" error 24h earlier than the real expiry, or a token works for 24h longer than the policy allows.

Found by security-sentinel and confirmed by performance-oracle (the function is called per send).

## Proposed Solutions

**Option A — Force UTC interpretation (recommended):**

```php
$utc = new DateTimeZone( 'UTC' );
$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_time, $utc );
return $dt ? $dt->getTimestamp() + self::ACTION_TOKEN_EXPIRY_DATED : ( time() + self::ACTION_TOKEN_EXPIRY_DATELESS );
```

**Option B — Append `' UTC'` to the string:** `strtotime( $date_time . ' UTC' )`. Concise but more fragile (strtotime's parsing rules).

Recommend **Option A** for robustness.

Bonus optimization (performance-oracle): the caller often already has the `$activity` object with `date_time`. Add an optional `$activity_date_time` parameter to `generate_action_token` so we don't re-query.

## Acceptance Criteria

- [ ] `compute_default_expiry` interprets the stored datetime as UTC.
- [ ] Optional `$activity_date_time` parameter on `generate_action_token`.
- [ ] Test: with `date_default_timezone_set('America/Los_Angeles')`, compute expiry for an activity dated `2026-06-01 00:00:00`, assert returned timestamp equals UTC midnight + 7 days.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by security-sentinel, performance-oracle.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-token.php:244-260`
