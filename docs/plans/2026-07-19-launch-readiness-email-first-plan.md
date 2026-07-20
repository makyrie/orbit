---
title: "Launch readiness — email-first friends & family trial"
type: plan
status: active
date: 2026-07-19
---

# Launch readiness — email-first friends & family trial

## Goal

Get Perihelion into the hands of a small group of real people who can actually
sign up, subscribe, and receive notifications. The trial runs **email-only**;
SMS stays dormant until Twilio A2P is approved and the kill-switch is flipped.

## Where we are (2026-07-19)

The Twilio-ready notification work
(`docs/plans/2026-06-01-feat-twilio-ready-notification-flow-plan.md`) is
**mostly built**. Against that plan's five phases:

| Phase | Scope | Status |
|---|---|---|
| 1 | Kill-switch, consent ledger, brand pinning, webhook hardening, RFC 8058 headers | ✅ merged (PR #24) |
| 2 | Compliance UI + consent capture on signup/subscribe/settings, `/privacy/` + `/terms/` | ✅ merged (PR #26) |
| 3 | Email deliverability (SMTP provider + DNS) | ❌ **not started** |
| 4 | Twilio TCR submission package | ❌ **not started** |
| 5 | Post-approval SMS flip + ramp-up | ⛔ blocked on Phase 4 + Twilio |

`ORBIT_VERSION` is `1.8.0`; the compliance pages, consent ledger (with an
auto-seeded IP-salt fallback so it works without a wp-config constant), and the
`Orbit_Features::sms_enabled()` kill-switch (default: off) are all live.

### The key insight

**SMS approval does not block the trial.** The architecture deliberately routes
every notification through email while `orbit_sms_enabled` is off. So the trial's
critical path is *email delivery*, and Twilio is a parallel track that unblocks
SMS later with a one-option flip and no data migration.

## The two gaps confirmed in this environment

1. **Email won't reach real inboxes.** No SMTP plugin is installed (only `orbit`,
   `user-role-editor`, `wp-migrate-db-pro`). Default `wp_mail()` is captured
   locally by Local, not delivered. No `ORBIT_SENDGRID_API_KEY`, no FluentSMTP,
   no `docs/compliance/dns-records.md`. The *code* path is done (RFC 8058 headers
   are in `Orbit_Notifier`, `class-orbit-notifier.php:1042-1077`); only the
   provider/DNS wiring is missing.
2. **Twilio campaign not registered.** Real Twilio creds and a 10DLC number
   (`+16194324434`, area code 619) are in `wp-config.php`, but a 10DLC number
   cannot send to subscribers without an approved A2P campaign, and
   `docs/compliance/twilio-submission.md` doesn't exist yet.

## Plan

### Track A — Ship the email-only trial (critical path)

- [ ] **A1. Stand up SendGrid + FluentSMTP.** Install FluentSMTP, connect
  SendGrid via API, store the key as `ORBIT_SENDGRID_API_KEY` in `wp-config.php`
  (not the options table). See `docs/compliance/dns-records.md` for the runbook.
  - *Needs from Sarah:* SendGrid API key (or confirmation to create one).
- [ ] **A2. Configure DNS for perihelion.social** — SPF, 2048-bit DKIM, DMARC
  `p=none` with a `rua=` mailbox. Records enumerated in
  `docs/compliance/dns-records.md`.
  - *Needs from Sarah:* DNS access for perihelion.social.
- [ ] **A3. End-to-end email smoke test on the real domain.** Sign up a fresh
  address → confirm the verification/welcome email lands in a real Gmail inbox
  (not spam); post an activity → confirm the notification email fires with a
  working one-click unsubscribe. Verify DMARC alignment at
  postmaster.google.com.
- [ ] **A4. Confirm SMS is dormant.** Verify `orbit_sms_enabled` is off and the
  `/settings/` phone UI shows the "email now, SMS coming soon" framing so testers
  aren't misled and no SMS send is attempted.
- [ ] **A5. Clear launch-blocking UX/theme items.** Re-verify in the **theme
  repo** (not this plugin):
  - Theme-QA Critical: duplicated "PerihelionPerihelion" login wordmark.
  - UX-audit P1 #2: `[orbit_cta]` renders literally on the marketing homepage
    (theme pattern bug; the plugin shortcode is registered correctly).
  - UX-audit P1 #3: anonymous visitors on virtual pages see the logged-in app
    nav (`force_app_template()` doesn't branch on login; recommended fix is
    theme-side option C).

### Track B — Twilio A2P approval (parallel; unblocks SMS later)

- [ ] **B1. Finish `docs/compliance/twilio-submission.md`** — brand reg, use
  case, PP/Terms URLs, 5 sample messages, opt-in URL + screenshots, frequency
  disclosure, ops runbook. (Drafted 2026-07-19; fill the `<<PLACEHOLDER>>`
  business fields.)
- [ ] **B2. Submit 10DLC + Toll-Free Verification in parallel** via the Twilio
  console; pay TCR fees (~$14/mo + ~$40 vetting).
- [ ] **B3. On approval → Phase 5 flip.** Pre-flip notice email, then
  `wp option update orbit_sms_enabled 1`, then the 48h ramp-up cap. No deploy, no
  data migration.

## Sequencing

Track A is the trial's blocker; do it first (A1→A2 need Sarah's SendGrid + DNS
access, so kick those off immediately). Track B (a document + a console
submission) runs in the background and does not gate the trial. Twilio approval
typically lands in 2–7 days, which comfortably overlaps the DNS warm-up window.

## Open items needing Sarah

- SendGrid API key (or go-ahead to create the account/key).
- DNS management access for perihelion.social.
- Twilio submission business fields: legal entity/EIN, support email, support URL
  (see `<<PLACEHOLDER>>` markers in `twilio-submission.md`).
