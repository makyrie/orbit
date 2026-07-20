# DNS & email deliverability — perihelion.social

Phase 3 of the Twilio-ready notification plan. Goal: production-grade
transactional email so Gmail/Yahoo accept perihelion.social's volume during the
email-only period (and after the SMS flip). **No plugin code ships in this
phase — it is operational (SMTP provider + DNS).**

> Values marked `<<FROM SENDGRID WIZARD>>` are generated per-domain by SendGrid's
> Sender Authentication wizard and must be copied verbatim. Do not guess them.

## 1. SMTP provider: SendGrid via FluentSMTP

**Decision:** SendGrid (existing account, native FluentSMTP integration).

Setup:

1. Install and activate **FluentSMTP** (`fluent-smtp`).
2. In SendGrid, create an API key scoped to **Mail Send** only. Consider two
   keys for isolation (optional for the trial):
   - `transactional` — verification, welcome
   - `notifications` — activity notifications, digests
3. Store the key as a constant in `wp-config.php` (NOT in the options table,
   where an admin compromise could read it):

   ```php
   define( 'ORBIT_SENDGRID_API_KEY', '<<SENDGRID_API_KEY>>' );
   ```

   FluentSMTP's connection settings accept a `PHP constant` reference for the
   key — select that option rather than pasting the key into the DB.
4. Set the From address to something on the authenticated domain, e.g.
   `notifications@perihelion.social`, with From name `Perihelion`.
5. Send a FluentSMTP test email and confirm it arrives.

## 2. DNS records for perihelion.social

Publish these at the DNS host for perihelion.social.

### SPF (one TXT record only — merge if others send for the domain)

```
Type:  TXT
Host:  @
Value: v=spf1 include:sendgrid.net -all
```

If another service also sends mail for the domain, combine the `include:`
mechanisms into this single record — a domain may have only one SPF TXT record.

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

- [ ] FluentSMTP test email delivered.
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
