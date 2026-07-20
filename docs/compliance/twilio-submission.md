# Twilio A2P 10DLC submission package — Perihelion

Single source of truth for the Campaign Registry (TCR) submission. Phase 4 of the
Twilio-ready notification plan. Approval here unblocks the SMS flip (Phase 5); it
does **not** gate the email-only trial.

> **Secrets policy.** This file is scanned pre-commit. Never paste a live Twilio
> SID (`AC…`), auth token, SendGrid key (`SG.…`), or a real phone number outside
> the reserved `+1500555xxxx` test range. Business identifiers below are
> `<<PLACEHOLDER>>` until Sarah supplies them.

## Brand registration

| Field | Value |
|---|---|
| Brand / DBA | Perihelion |
| Brand type | **Sole Proprietor** (individual, no EIN) |
| Legal name | `<<YOUR LEGAL NAME>>` |
| EIN | N/A — sole proprietor |
| Address | `<<YOUR ADDRESS>>` |
| OTP mobile (identity verification) | `<<PERSONAL MOBILE — receives the one-time code; not the sending number>>` |
| Support email | `<<SUPPORT EMAIL, e.g. hi@perihelion.social>>` |
| Support / website URL | https://perihelion.social/ |
| Production 10DLC number | `<<PRODUCTION 10DLC NUMBER — e.g. +16194324434>>` |

**Sole Proprietor constraints:** one campaign per brand; reduced carrier throughput
(e.g. T-Mobile ~2,000 msgs/day) — ample for the trial; identity verified by an OTP
sent to the personal mobile above.

## Campaign

| Field | Value |
|---|---|
| Use case | **Sole Proprietor** (the dedicated use case for sole-prop brands — NOT "Low Volume Mixed", which is Standard-only) |
| Privacy Policy URL | https://perihelion.social/privacy/ |
| Terms URL | https://perihelion.social/terms/ |
| Opt-in URL | https://perihelion.social/sign-up/ (also on `/subscribe/` and `/settings/`) |
| Message frequency disclosure | "Up to 10 msgs/week" |
| Opt-in type | Web form — unchecked SMS consent checkbox adjacent to the phone field |

## Opt-in flow (what a reviewer will see)

A person provides a phone number on `/sign-up/`, `/subscribe/`, or `/settings/`.
Adjacent to the phone field is a compliance block containing: the CTA, the "up to
10 msgs/week" frequency, "Msg & data rates may apply", STOP/HELP instructions,
and links to `/privacy/` and `/terms/`. SMS consent is a **separate, unchecked**
checkbox — phone is optional; email is required. Every opt-in/opt-out is written
to an append-only, hash-chained consent ledger.

**Attach screenshots** of the compliance block at each of the three surfaces.

## Sample messages

Illustrative numbers use the Twilio reserved test number `+15005550006`. The
bracketed samples reflect the exact strings the code sends
(`class-orbit-phone-verify.php`, `class-orbit-twilio.php`); brand is pinned via
`ORBIT_MESSAGING_BRAND` = "Perihelion".

1. **Verification** (`Orbit_Phone_Verify`):
   `Your Perihelion verification code is: 123456`
2. **Welcome / opt-in confirmation:**
   `Perihelion: You're subscribed to creator notifications. Up to 10 msgs/week. Msg & data rates may apply. Reply HELP for help, STOP to unsubscribe.`
3. **Activity notification:**
   `Perihelion: Sarah posted "Saturday morning bike ride" — Sat 9:00 AM. Reply going or maybe. Reply STOP to unsubscribe.`
4. **Digest summary:**
   `Perihelion: 3 new activities from creators you follow this week. See them at https://perihelion.social/dashboard/ . Reply STOP to unsubscribe.`
5. **HELP reply** (`Orbit_Twilio::help_reply`):
   `Perihelion: Creator notifications. Up to 10 msgs/week. Msg & data rates may apply. Support: <<SUPPORT EMAIL>>. Reply STOP to unsubscribe.`
6. **STOP confirmation** (`Orbit_Twilio`):
   `Perihelion: You've been unsubscribed and will receive no further messages. Reply START to resubscribe.`
7. **START confirmation** (`Orbit_Twilio`):
   `Perihelion: You're re-subscribed. Reply STOP to unsubscribe, HELP for help.`

## Privacy Policy — required sharing language

The live `/privacy/` page contains the Twilio-blessed language verbatim (also in
`docs/compliance/privacy-policy.md`):

> No mobile information will be shared with third parties or affiliates for
> marketing or promotional purposes. All the above categories exclude
> text-messaging originator opt-in data and consent; this information will not be
> shared with any third parties.

(Quoted verbatim from the live `/privacy/` page and `docs/compliance/privacy-policy.md:29`.)

This is what keeps the campaign clear of error 30520.

## Toll-Free Verification (submit in parallel)

Submit Toll-Free Verification via the Twilio console alongside 10DLC. Independent
timing; gives a sanctioned channel for welcome / STOP confirmations even while
10DLC is in vetting. Owner: Sarah.

## Operations runbook — the SMS flip (Phase 5)

- **Pre-flip:** confirm DNS green, consent ledger non-empty, SendGrid delivery
  rate ≥98% for 7 days. Send the pre-flip service-change notice email to users
  with `tier3_method='sms'` and a verified phone.
- **Flip:** `wp option update orbit_sms_enabled 1`. The runtime filter stops
  coercing SMS→email immediately. No code deploy, no data migration.
- **Ramp-up:** throttle sends for the first 48h (`orbit_sms_rampup_hourly_cap`),
  with 0–30 min jitter on deferred sends.
- **Rollback:** `wp option update orbit_sms_enabled 0` — sub-second; in-flight
  jobs queued before the flip finish, new dispatches revert to email.
- **Monitoring thresholds:** delivery >90%, complaints <0.3%, STOP <2×
  baseline. Any breach → roll back.

## Consent ledger (audit note)

Consent is recorded per channel in `wp_orbit_consent_ledger` — append-only,
hash-chained, IP stored as HMAC-SHA256 (never raw), with policy-version capture
per row. Read via `wp orbit consent log --user_id=<id>`; verify integrity via
`wp orbit consent verify --user_id=<id>`.

## Pre-submission checklist

- [ ] All `<<PLACEHOLDER>>` business fields filled.
- [ ] Screenshots of the compliance block at `/sign-up/`, `/subscribe/`,
      `/settings/` attached.
- [ ] `/privacy/` and `/terms/` resolve publicly and contain the sharing
      language.
- [ ] Pre-commit secret scrub passes (no live SID/token/key, no real numbers
      outside `+1500555`).
- [ ] Toll-Free Verification submitted in parallel.
