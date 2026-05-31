---
status: complete
priority: p2
issue_id: "072"
tags: [code-review, security, rate-limit, proxy, PR-23]
dependencies: []
---

# Rate limiter keyed on $_SERVER['REMOTE_ADDR'] doesn't survive Cloudflare / reverse proxy

## Problem Statement

`Orbit_REST_Signup::handle_signup()` rate-limits by IP via:

```php
$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
if ( $ip && ! Orbit_Rate_Limiter::attempt( 'signup', $ip, 5, HOUR_IN_SECONDS ) ) {
```

On production (perihelion.social is a multisite sub-site, almost certainly behind a CDN/reverse proxy), `$_SERVER['REMOTE_ADDR']` is the proxy's IP, not the real client. Result: all signups across the internet appear to come from the same handful of proxy IPs, and the 5-per-hour limit becomes effectively global — legitimate users get rate-limited by other people's traffic.

This is **not new** — `Orbit_REST_Subscription::handle_subscribe()` has the same pattern. But PR #23 makes the pattern load-bearing for a second endpoint, raising the production blast radius.

## Findings

- `includes/class-orbit-rest-signup.php:71-80` — uses `$_SERVER['REMOTE_ADDR']` directly.
- `includes/class-orbit-rest-subscription.php` — same pattern (pre-existing, PR #22).
- No centralized `Orbit_Client_IP::get()` helper yet.

## Proposed Solutions

**Option A — Add `Orbit_Client_IP::get()` helper that respects trusted forwarded headers:**

```php
class Orbit_Client_IP {
    public static function get() {
        $proxy_header = apply_filters( 'orbit_client_ip_header', '' );
        if ( $proxy_header && ! empty( $_SERVER[ $proxy_header ] ) ) {
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER[ $proxy_header ] ) );
            $parts     = array_map( 'trim', explode( ',', $forwarded ) );
            $candidate = $parts[0] ?? '';
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                return $candidate;
            }
        }
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }
}
```

Site owner sets `add_filter( 'orbit_client_ip_header', fn () => 'HTTP_CF_CONNECTING_IP' );` (Cloudflare) or `'HTTP_X_FORWARDED_FOR'` (generic proxy) in `wp-config.php` / a mu-plugin. Default behavior is unchanged.

Wire it from both `class-orbit-rest-signup.php` and `class-orbit-rest-subscription.php`.

**Option B — Hardcode CF-Connecting-IP:**

Perihelion runs on Cloudflare specifically. Read that header directly. Simpler, less general.

**Option C — Defer until we see rate-limit complaints in production.**

Acceptable if we add monitoring/logging on rate-limit events so we know when this starts hurting us. Lower priority than fixing it preemptively.

Recommend **Option A** — keeps the abstraction clean and works for both endpoints.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected files: `includes/class-orbit-rest-signup.php`, `includes/class-orbit-rest-subscription.php` (refactor in shared spot)
- Potential new file: `includes/class-orbit-client-ip.php`

## Acceptance Criteria

- [ ] Rate limiter keys on the real client IP when a trusted forwarded header is configured.
- [ ] Default (no config) behavior is unchanged.
- [ ] Both `signup` and `subscribe` endpoints use the helper.
- [ ] Header name is configurable so we don't bake in Cloudflare assumptions.

## Work Log

- 2026-05-14: Identified during code review of PR #23. Pre-existing pattern, but now load-bearing for two endpoints.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php:71-80`
- `includes/class-orbit-rest-subscription.php` (same pattern, pre-existing)
