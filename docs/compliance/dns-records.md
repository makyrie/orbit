# DNS & email deliverability — perihelion.social

Phase 3 of the Twilio-ready notification plan. Goal: production-grade
transactional email so Gmail/Yahoo accept perihelion.social's volume during the
email-only period (and after the SMS flip). **No plugin code ships in this
phase — it is operational (SMTP provider + DNS).**

> Values marked `<<FROM SENDGRID WIZARD>>` are generated per-domain by SendGrid's
> Sender Authentication wizard and must be copied verbatim. Do not guess them.

## 1. SMTP provider: SendGrid via wp-mail-smtp

**Decision:** SendGrid, sent through **wp-mail-smtp** — already installed on the
network (v4.9.0), so nothing new to install. Configured entirely via `wp-config.php`
constants so the API key never touches the database.

> **Multisite scoping (critical).** Production is a large multi-project multisite;
> perihelion.social is subsite 49 (`wp_49_`). wp-mail-smtp is activated **only on
> the perihelion.social subsite** — never network-wide — so no other project's mail
> is affected. `wp-config.php` constants are network-global, so they are wrapped in
> a host guard to belt-and-suspenders the isolation.

Setup:

1. In SendGrid, create an API key scoped to **Mail Send** only.
2. Add the host-guarded block to `wp-config.php` (above `/* That's all, stop
   editing! */`). The key lives here, not in the options table:

   ```php
   // Perihelion transactional email via SendGrid (wp-mail-smtp).
   // wp-config constants are network-global, so guard on host: never govern
   // another subsite's mail even if wp-mail-smtp gets activated elsewhere.
   if ( isset( $_SERVER['HTTP_HOST'] ) && false !== stripos( $_SERVER['HTTP_HOST'], 'perihelion.social' ) ) {
       define( 'WPMS_ON', true );
       define( 'WPMS_MAILER', 'sendgrid' );
       define( 'WPMS_SENDGRID_API_KEY', '<<SENDGRID_API_KEY>>' );
       define( 'WPMS_SENDGRID_DOMAIN', 'perihelion.social' );
       define( 'WPMS_MAIL_FROM', 'hi@perihelion.social' );
       define( 'WPMS_MAIL_FROM_FORCE', true );
       define( 'WPMS_MAIL_FROM_NAME', 'Perihelion' );
       define( 'WPMS_MAIL_FROM_NAME_FORCE', true );
   }
   ```

3. Activate the plugin **on the subsite only**:
   `wp plugin activate wp-mail-smtp --url=https://perihelion.social`
4. Send a test and confirm it routes via SendGrid.

**Background-send note:** `DISABLE_WP_CRON` is on; a system cron runs each subsite's
due events every 15 min via `wp cron event run --url=<site>`. WP-CLI's `--url` sets
`$_SERVER['HTTP_HOST']`, so the host guard passes for Action Scheduler notification
sends too. (Notifications may therefore lag up to ~15 min — fine for the trial.)

## 2. DNS records for perihelion.social

Publish these at the DNS host for perihelion.social.

### SPF — usually no change needed

SendGrid's CNAME-based domain authentication routes the envelope/return-path
through `emNNNN.perihelion.social` (a CNAME to sendgrid.net), so SPF passes and
aligns via SendGrid's own records — **you do not need to add sendgrid.net to the
root SPF.** DMARC alignment comes from the DKIM CNAMEs.

perihelion.social already has an SPF record for Namecheap email forwarding:

```
v=spf1 include:spf.efwd.registrar-servers.com ~all
```

**Leave it as-is.** Only if you later want belt-and-suspenders SPF alignment,
*edit* (never duplicate — one SPF TXT per domain) to merge the include:

```
v=spf1 include:spf.efwd.registrar-servers.com include:sendgrid.net ~all
```

Keep `~all` (softfail); do not switch to `-all`, and do not drop the efwd
include or you break email forwarding.

### DKIM (2048-bit, from SendGrid's wizard)

SendGrid's Sender Authentication wizard emits CNAME records (branded-link +
DKIM). Publish all of them as given:

```
Type:  CNAME
Host:  <<FROM SENDGRID WIZARD>>   (e.g. s1._domainkey.perihelion.social)
Value: <<FROM SENDGRID WIZARD>>   (e.g. s1.domainkey.uXXXXXXX.wlXXX.sendgrid.net)

Type:  CNAME
Host:  <<FROM SENDGRID WIZARD>>   (e.g. s2._domainkey.perihelion.social)
Value: <<FROM SENDGRID WIZARD>>
```

### DMARC (start permissive, tighten after monitoring)

```
Type:  TXT
Host:  _dmarc
Value: v=DMARC1; p=none; rua=mailto:dmarc@perihelion.social; fo=1
```

Progress `p=none` → `p=quarantine` within ~30 days once aggregate reports show
SPF/DKIM aligning cleanly.

## 3. Verification checklist

- [ ] wp-mail-smtp active on the perihelion.social subsite only; test email delivered.
- [ ] SendGrid Sender Authentication shows the domain **verified** (all CNAMEs
      green).
- [ ] `dig TXT perihelion.social` shows the SPF record.
- [ ] `dig TXT _dmarc.perihelion.social` shows the DMARC record.
- [ ] A real sign-up delivers the verification/welcome email to a Gmail inbox
      (not spam), with a working one-click unsubscribe.
- [ ] postmaster.google.com shows DMARC alignment passing.
- [ ] First ~100 sends show delivery rate ≥98% in the SendGrid dashboard.

## Notes

- The outbound email code already sets RFC 8058 one-click unsubscribe headers
  (`Orbit_Notifier`, `class-orbit-notifier.php:1042-1077`) — nothing to change
  there.
- Local by Flywheel captures mail locally and does not deliver externally; this
  wiring matters on the production host, so run the smoke test against
  perihelion.social, not orbit.local.
