# UX Audit Punch List — 2026-05-06

Comprehensive walk-through of the live site (`orbit.local` / `perihelion.social`) as both anonymous and logged-in users. Each finding has a recommended fix. Check items off as resolved.

**Test fixture used:** logged in as `claude` (admin role, has profile, subscribed to Sarah K). Sarah K subscribed to claude. Three activities: Crafternoon (Sarah K), Saturday morning bike ride (Sarah K), Cherry Blossom Festival (claude, undated).

---

## 🔴 P1 — Functionally broken, fix before anything else

### [x] 1. Logged-in users can't view profiles, activities, or unsubscribe pages

**Severity:** Critical regression — shipped to production with the heading-fix bundle.

**Symptom:** Every Orbit virtual page returns `303 → /dashboard/` for logged-in users.

```
GET /@claude/      (logged in) → 303 → /dashboard/
GET /activity/1    (logged in) → 303 → /dashboard/
GET /unsubscribe/  (logged in) → 303 → /dashboard/
```

Clicking any activity card on the dashboard bounces back to the dashboard. Looking at someone's profile is impossible while logged in. Unsubscribe links from emails won't work for users who happen to be logged in.

**Root cause:** `redirect_logged_in_from_home()` runs at `template_redirect` priority 5. At that point the rewrite rules have set `orbit_profile_slug` / `orbit_activity_id` etc., but `wp_query` hasn't been replaced by `handle_routes()` yet — so `is_front_page()` returns true.

**Recommended fix** in `includes/class-orbit-routes.php` (`redirect_logged_in_from_home()`, around line 50):

```php
public static function redirect_logged_in_from_home() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( self::is_app_route() ) {
        return; // Profile / activity / unsubscribe are not the front page.
    }

    if ( ! is_front_page() ) {
        return;
    }

    nocache_headers();
    wp_safe_redirect( home_url( '/dashboard/' ), 303 );
    exit;
}
```

Add a regression test that `GET /@some-slug/` and `GET /activity/{id}` return 200 for an authenticated request.

---

### [ ] 2. `[orbit_cta]` renders as literal text on the marketing front page

**Severity:** Critical — public marketing page has no working call-to-action.

**Symptom:** Both the hero CTA and the closing CTA on `https://perihelion.social/` render literally as the string `[orbit_cta]` instead of as a button. The HTML output is `<p>[orbit_cta]</p>`.

**Root cause:** The `<!-- wp:shortcode -->[orbit_cta]<!-- /wp:shortcode -->` block in `patterns/hero.php` and `patterns/closing-cta.php` (theme repo, not plugin) isn't being processed correctly when the patterns are inlined into the template. The wp:shortcode block delimiters get stripped and `wpautop` wraps the orphan text in `<p>`.

**Recommended fix** in **theme repo** (`patterns/hero.php` line 28-30, `patterns/closing-cta.php` line 27-29):

Replace
```html
<!-- wp:shortcode -->
[orbit_cta]
<!-- /wp:shortcode -->
```

with
```php
<?php echo do_shortcode( '[orbit_cta]' ); ?>
```

PHP patterns load lazily at render time (in `WP_Block_Patterns_Registry::get_content()`), by which point `init` has fired and `orbit_cta` is registered. The shortcode runs, returns the button HTML, and that becomes the pattern's content directly — no wp:shortcode block round-trip needed.

---

### [ ] 3. Anonymous visitors on virtual pages see the logged-in app nav

**Severity:** High — public-facing pages confuse first-time visitors.

**Symptom:** When an anonymous visitor lands on `/@sarah-k/`, `/activity/1`, or `/unsubscribe/`, the top nav shows `Dashboard | Subscriptions | Settings | Log in`. Clicking Dashboard / Subscriptions / Settings sends them to login. The marketing front page and 404 page correctly use the marketing header — only virtual pages have this issue.

**Root cause:** `force_app_template()` forces `page-app.html` for all virtual pages regardless of authentication state. `page-app.html` template-parts in the app header which contains the authenticated nav.

**Recommended fix:** Either
- (A) In `force_app_template()`, skip the override when `! is_user_logged_in()` so anonymous viewers land on the default `page.html` template (which uses the marketing header). Quick but might lose styling consistency.
- (B) Add a separate `header-public.html` template part with just the wordmark + Log in link, and have `header-app.html` load it for anonymous viewers via `<?php if ( ! is_user_logged_in() ) ?>` (in the theme repo).
- (C) In the theme's `app-nav` block-style filter, hide Dashboard / Subscriptions / Settings / Manage etc. items entirely when not logged in, leaving only `Log in`.

**Recommendation:** option C — least disruptive, single filter change in the theme `functions.php`.

---

## 🟡 P2 — Real confusion / friction

### Navigation & wayfinding

- [ ] **"Profile" in nav goes to the editor, not your public profile.** No way from anywhere in the app to view your own `/@slug/` page. Add either a "View profile" link on the edit form, or a separate "My page" nav item.
- [ ] **No "Copy" button on the Share Link** in `Edit Profile`. Long URL with a token — users will mis-select. Add a copy-to-clipboard button beside it. Optional: QR code.
- [ ] **"Subscriptions" vs "Subscribers" in nav** — easy to flip. Consider relabeling ("Following" / "Followers" or "I follow" / "Follow me"), or at minimum group them visually.
- [ ] **Subscribers nav item shows for everyone with `orbit_create_activity`**, but a brand-new poster sees an empty page with no orientation. Either hide until first subscriber arrives, or add empty-state copy: "Once people subscribe to you, they'll show up here for approval."
- [ ] **Eight nav items is a lot.** Consider grouping Manage / New Activity under "Activities".

### Empty / approval states

- [ ] **My Subscriptions has zero introductory copy.** Just a table. Add: "People you've subscribed to. They have to approve you before their activities show up on your dashboard."
- [ ] **Confirm pending subscriptions are visible from My Subscriptions.** Test fixture didn't have a pending sub to verify.
- [ ] **Status `approved` lowercase plain text** on My Subscriptions and Subscribers. Use the `orbit-status-badge` treatment used on Manage Activities.

### Subscribe form (anonymous via shared link)

- [ ] **No expectation set after submit.** Add: "[Sarah K] will see your subscription request and approve you. You'll get an email when they do."
- [ ] **"How do you know this person?" has no help text.** Is it required? Public to the poster? Add help: "Just a quick note for [Sarah K] — only they will see this."

### New / Edit Activity

- [ ] **No required-field markers.** Title is required but unmarked. Use `*` or "(required)".
- [ ] **`Show Attendees` label inconsistency** between create ("Show count" / "Show names" / "Hide") and edit ("Count" / "Names" / "None"). Same control, different copy. Pick one.
- [ ] **Tier (Commitment Level) is missing from the edit form.** If intentional (because tier drives notification routing), add inline note: "Commitment level can't be changed after posting — create a new activity instead." If unintentional, add the field.
- [ ] **"Cancel Activity" sits next to "Update Activity"**, both in primary-button styling. Risk of mis-click. Visually demote Cancel (link or outlined-danger style) and add a confirmation dialog.
- [ ] **No tier descriptions inline.** Add a one-line description below the dropdown that updates with selection.
- [ ] **`Date is approximate` checkbox has no explanation.** Document what it changes for invitees.
- [ ] **`Location Address` purpose isn't obvious.** Add help: "Hidden from non-subscribers — only your approved followers see this."
- [ ] **No "Cancel/Back" affordance on either form.** Browser-back works, but a "← Back to Manage" link would be polite.

### Settings

- [ ] **Tier names in Notification Preferences need context.** Add an intro: "How do you want to be alerted about each kind of activity from people you've subscribed to?"
- [ ] **`Sms` capitalization** — `ucfirst()` produces "Sms" which looks like a typo. Fix to `SMS` everywhere.
- [ ] **Digest Time has no timezone.** Make explicit (local? site?).
- [ ] **Phone Number forced top-level**, even for users who'll never use SMS. Consider collapsing or marking optional.

### Dashboard

- [ ] **Poster name on cards is plain text.** "Sarah K" should link to `/@sarah-k/`. Currently only the activity title is linked.
- [ ] **Undated tier-1 cards look empty.** Either show "(no date set)" placeholder or move undated activities to a separate "Ideas" section under the upcoming list.
- [ ] **Stale "active" activities with past dates** are showing on the dashboard. Confirm `mark_past()` Action Scheduler job is registered and running.

### Profile (public)

- [ ] **Owner viewing their own profile** should see "This is your profile — share link / edit", not the Subscribe button. (Currently impossible due to P1 #1, but design for the post-fix state.)
- [ ] **Activity dates show past dates** but cards style as upcoming. Same root cause as the past-sweep issue.

### Footer

- [ ] **App-template footer is just `Perihelion · Log in/out`.** Marketing footer has Why-this-exists / Privacy / Contact / GitHub. App users lose access. Either match the marketing footer or add at least "Privacy" / "About" links.

---

## 🔵 P3 — Polish

- [ ] **Manage table response counts** — currently just a number. Could link to who responded.
- [ ] **"Cancel" in Manage table** — rename to "Cancel activity" to disambiguate.
- [ ] **No counts/summary** at top of Manage / Subscribers / My Subscriptions ("3 active · 1 cancelled · 2 past").
- [ ] **Login redirect** sends admin-role users to wp-admin. Add a `login_redirect` filter that sends Orbit users to `/dashboard/` regardless of role.
- [ ] **Activity card heading hierarchy** — make the H3 the link target rather than `<h3><a>` nested.
- [ ] ~~**Date formatting** — consider `Apr 17 · 5:54 PM` compact form.~~ **Keep day of week — worth the space tradeoff. If mobile wraps awkwardly, solve with layout / line breaks / responsive treatment, not by dropping content.**
- [ ] **No timezone shown** on activity date displays.
- [ ] **404 page** is good — no fix needed, just noting it works correctly with the marketing header.

---

## Recommended ordering

1. **P1 #1 first** (4-line code change, ships immediately) — every other finding assumes you can actually click around the site logged in.
2. **P1 #2** (theme-repo edit) so the marketing front actually has a working CTA.
3. **P1 #3** so anonymous visitors don't see a confusing app nav.
4. Triage P2 — most are 1–2 line copy or markup additions.
5. P3 as time allows.

---

## Out of scope for this audit

- Mobile-specific layout (only tested at 1280×900 desktop)
- Email content/styling (digest, notifications, approval emails)
- Twilio SMS UX (not testable without configured Twilio)
- Action Scheduler reliability (separate ops concern)
- Long-form content stress testing (1000-char descriptions, etc.)
