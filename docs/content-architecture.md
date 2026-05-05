# Perihelion — Content Architecture

Phase 3 deliverable from the website engagement. Reads from
`docs/brand-brief.md` and `docs/creative-direction.md`. Produces the
sitemap and template inventory that `design-system-generator` and
`theme-builder` will consume.

## Working Assumptions

A few unknowns I'm proceeding on — flagging so they can be corrected
without unraveling the whole structure:

1. **One install, two purposes.** The marketing site and the app live in the same WordPress install at `orbit.local` (eventually a public domain TBD). Marketing pages are served from the theme's editorial layout; app pages are the existing plugin-created shortcodes wrapped in a wider layout. There is no separate marketing site.
2. **No existing marketing content.** The 8 app pages exist; nothing else does. This is a greenfield marketing site, not a content audit.
3. **Auth is via WordPress core.** Sign up / sign in / password reset are handled by `wp-login.php` and the standard WP user system. The theme styles those screens; the plugin doesn't replace them.

## Step 1 — Content Inventory

### Existing content

The only "content" that currently exists is the 8 app pages auto-created by `Orbit_Activator::create_pages()` at plugin activation. These are app screens reached by logged-in users from inside the app — they are not marketing pages and the plugin already filters them out of nav menus via `orbit_get_internal_page_slugs()`.

| Slug | Title | Renders | Audience |
|---|---|---|---|
| `dashboard` | Dashboard | `[orbit_dashboard]` | Logged-in subscribers |
| `settings` | Settings | `[orbit_settings]` | Logged-in users |
| `subscriptions` | Subscriptions | `[orbit_my_subscriptions]` | Logged-in subscribers |
| `manage` | Manage | `[orbit_manage]` | Logged-in posters |
| `new-activity` | New Activity | `[orbit_new_activity]` | Logged-in posters |
| `edit-activity` | Edit Activity | `[orbit_edit_activity]` | Logged-in posters |
| `subscribers` | Subscribers | `[orbit_subscribers]` | Logged-in posters |
| `edit-profile` | Edit Profile | `[orbit_edit_profile]` | Logged-in users |

**Key fields:** title, content (just the shortcode), `post_name` (slug). No featured images, no excerpts, no taxonomies. Pure rendering containers.

**Relationships:** none at the post/page level. All relationships live in the plugin's own custom tables (`wp_orbit_*`).

### Content types not used

The plugin defines no custom post types or custom taxonomies. All Orbit data — profiles, activities, subscriptions, responses, notification log, phone verifications — lives in custom tables, queried directly via the `Orbit_*` model classes. **This is the right call** (avoids the post-meta lookup tax and keeps the data model honest), and there's no need to revisit it during this phase.

The default WP post type (`post`) and built-in taxonomies (Category, Tag) are technically present but unused by the plugin. The marketing site won't use them either — there's no blog planned (per Section 6 of the brand brief: "the product is the product").

## Step 2 — Content Model Assessment

The current model is **clean and intentional**: app screens are WordPress pages with a single shortcode, all dynamic data lives in the plugin's tables, and no post types exist that don't earn their keep. Nothing to consolidate, nothing to deprecate.

The marketing-site additions in this phase introduce a small number of static pages — Home, the manifesto/about page, Privacy. None require custom post types or taxonomies. They're standard WP pages with hand-authored content rendered by editorial templates.

**One structural recommendation that affects both worlds:** the theme should provide two distinct page templates and the plugin should assign them appropriately:

- **`page.html`** (default) — narrow editorial layout (~640–720px reading column, generous whitespace) for marketing pages
- **`page-app.html`** — wider layout (~960–1100px) for app pages that need horizontal space (Dashboard, Manage, Subscribers)

Implementation: the plugin's existing page-creation logic (`Orbit_Activator::create_pages()` and `orbit_migrate_page_slugs()`) should set `_wp_page_template` post-meta to `page-app.html` for all 8 app pages. This is a **small plugin-side change** that should be coordinated with the theme build in Phase 5; flagging here so it doesn't get lost.

## Step 3 — Site Map

```yaml
sitemap:
  # ===== Marketing surface =====
  - page: Home
    template: front-page
    slug: "/"
    purpose: "First impression. Communicates what Perihelion is, who it's for, and how it differs from the social-product field. Routes prospects toward signing up; routes returning users toward the app."
    content_sources: []
    notes: "Single landing page. Composed of: hero (wordmark + tagline + one-sentence what), the audience mirror ('if you're the friend who plans things…'), how it works (3 steps), what's different (BYOF + anti-extractive + three tiers), closing CTA. No social proof section, no testimonial carousel, no animated hero — per design don'ts."

  - page: Why this exists
    template: page
    slug: "/why"
    purpose: "The values/manifesto page. Explains the anti-extractive stance, the agency-redistribution promise, and the joy-of-near-strangers idea. Makes the brand's values legible to prospects who want to understand what they're signing up for."
    content_sources: []
    notes: "Single-column reading page. Voice is fellow-learner / quietly editorial — closest existing reference is a Taproot Magazine essay. Includes a low-key footer attribution that Sarah Lewis built this and why. NOT a founder-story page."

  - page: Privacy
    template: page
    slug: "/privacy"
    purpose: "Required legal page. Frames privacy as a brand value: explicitly states what is collected (phone, email), what is not done (no ads, no data selling, no AI training on user data), and how AI moderation is used (sentiment analysis only, no human reviewers). Doubles as a trust signal."
    content_sources: []
    notes: "Plain-language summary at top, formal language below. Structured so the summary alone is sufficient for most readers. Surfaces the AI-moderation stance from the brand brief Open Questions."

  - page: Contact
    template: page
    slug: "/contact"
    purpose: "A way to reach Sarah for questions, feature requests, or to report problems. Single page with an email link and a short note about response expectations."
    content_sources: []
    notes: "MVP can be a footer mailto link plus this page. No contact form (would require backend, spam handling, etc. — overkill). If a form becomes warranted later, defer to a follow-up iteration."

  # ===== App surface (existing, theme-wrapped) =====
  - page: Dashboard
    template: page-app
    slug: "/dashboard"
    purpose: "Subscriber's unified inbox of activities from people they follow. The home base for using the product."
    content_sources: ["[orbit_dashboard] shortcode"]
    notes: "Plugin-rendered. Theme provides the wider page-app template. Restricted to logged-in users; falls back to a login prompt for anonymous visitors."

  - page: Settings
    template: page-app
    slug: "/settings"
    purpose: "Notification preferences and phone verification. Currently the only place a user manages SMS/email/digest tier preferences."
    content_sources: ["[orbit_settings] shortcode"]
    notes: "Plugin-rendered."

  - page: Subscriptions
    template: page-app
    slug: "/subscriptions"
    purpose: "List of profiles the current user subscribes to, with status (approved, pending, denied)."
    content_sources: ["[orbit_my_subscriptions] shortcode"]
    notes: "Plugin-rendered."

  - page: Manage
    template: page-app
    slug: "/manage"
    purpose: "Poster's dashboard for their own activities — list, edit, cancel."
    content_sources: ["[orbit_manage] shortcode"]
    notes: "Plugin-rendered. Restricted to users with orbit_create_activity capability."

  - page: New Activity
    template: page-app
    slug: "/new-activity"
    purpose: "Form to create a new activity (title, tier, description, audience, location, date, link)."
    content_sources: ["[orbit_new_activity] shortcode"]
    notes: "Plugin-rendered. Restricted to posters."

  - page: Edit Activity
    template: page-app
    slug: "/edit-activity"
    purpose: "Form to edit an existing activity. Reached via ?id= query param from Manage."
    content_sources: ["[orbit_edit_activity] shortcode"]
    notes: "Plugin-rendered. Restricted to the activity's owner or admin."

  - page: Subscribers
    template: page-app
    slug: "/subscribers"
    purpose: "Poster's view of who subscribes to them, with approve/deny/remove controls. Source of the actionable workflow indicator (pending count) surfaced in the app header."
    content_sources: ["[orbit_subscribers] shortcode"]
    notes: "Plugin-rendered. Restricted to posters. The 'X pending subscribers' indicator that surfaces elsewhere in the UI links here."

  - page: Edit Profile
    template: page-app
    slug: "/edit-profile"
    purpose: "Form for the user to set up or edit their poster profile (display name, slug, bio, share token, approval requirement)."
    content_sources: ["[orbit_edit_profile] shortcode"]
    notes: "Plugin-rendered. Available to any logged-in user; the act of saving a profile here promotes the user from subscriber to poster."

  # ===== Activity / profile dynamic routes (plugin-rewritten) =====
  - section: Profile
    template: page-app
    slug: "/@{slug}"
    purpose: "Public profile page for a poster, showing their display name, bio, and (if visible to viewer) recent activities. The URL the organizer shares to invite people to subscribe."
    content_sources: ["[orbit_profile] shortcode via Orbit_Routes rewrite"]
    notes: "Plugin-rewritten URL pattern. Theme handles the page-app outer chrome; the shortcode handles all dynamic content."

  - section: Activity
    template: page-app
    slug: "/activity/{id}"
    purpose: "Activity detail page — title, description, audience hint, location, date, response state, RSVP buttons, attendees (per privacy settings)."
    content_sources: ["[orbit_activity] shortcode via Orbit_Routes rewrite"]
    notes: "Plugin-rewritten URL pattern. The destination of in-SMS/email action token links."

  - section: Subscribe form
    template: page-app
    slug: "/@{slug}/subscribe"
    purpose: "Form for an invitee to subscribe to a poster's profile, with optional connection note."
    content_sources: ["[orbit_subscribe_form] shortcode via Orbit_Routes rewrite"]
    notes: "Plugin-rewritten URL pattern. Reachable via the share token in the poster's invitation."

  # ===== Auth (WordPress core) =====
  - page: Sign in
    template: wp-login
    slug: "/wp-login.php"
    purpose: "Standard WordPress login. Theme styles the login form to match the brand."
    content_sources: []
    notes: "WordPress core. Theme provides custom CSS via the login_enqueue_scripts hook, NOT a replacement template. Keep WP's auth flow intact."

  - page: Sign up / Register
    template: wp-login
    slug: "/wp-login.php?action=register"
    purpose: "Standard WordPress registration. Same login screen, ?action=register query param."
    content_sources: []
    notes: "Site must have user registration enabled in WP admin Settings → General. Theme styles the registration form."

  - page: Password reset
    template: wp-login
    slug: "/wp-login.php?action=lostpassword"
    purpose: "Standard WordPress password reset."
    content_sources: []
    notes: "WordPress core flow."

  # ===== Utility =====
  - page: 404 Not Found
    template: 404
    slug: "(any unmatched URL)"
    purpose: "Friendly not-found page. Should feel of-a-piece with the rest of the site, never apologetic-tech-error voice."
    content_sources: []
    notes: "Voice opportunity: 'Looks like that's not here anymore. Try heading back to the front.' Link to home."

  - page: Search results
    template: search
    slug: "/?s={query}"
    purpose: "WordPress search results. Realistically, very low-traffic on a small marketing site, but the theme should style it consistently."
    content_sources: ["wp post search"]
    notes: "MVP can omit the search form from primary nav (it's not needed) but ship the template anyway. Search is also useful for the 404 fallback."
```

## Step 4 — Template Inventory

The flat list of unique templates the FSE theme needs to ship. Direct handoff to `theme-builder` in Phase 5.

### Page templates

| Template | WordPress Template File | Used By | Purpose |
|---|---|---|---|
| Front Page | `templates/front-page.html` | Home | Landing page; multi-section hero + how-it-works + CTA |
| Page (default) | `templates/page.html` | Why this exists, Privacy, Contact | Narrow editorial reading column with generous whitespace |
| Page (app) | `templates/page-app.html` | All 8 app pages + dynamic Profile/Activity/Subscribe routes | Wider layout to accommodate plugin-rendered dashboards, forms, and lists |
| 404 | `templates/404.html` | Not found | Branded error page with link home |
| Search | `templates/search.html` | Search results | List of matching content; minimal styling |

### Template parts

| Template Part | File | Used By |
|---|---|---|
| Site Header | `parts/header.html` | All page templates |
| Site Footer | `parts/footer.html` | All page templates |
| Marketing Header | `parts/header-marketing.html` | `front-page.html`, `page.html`, `404.html`, `search.html` |
| App Header | `parts/header-app.html` | `page-app.html` (includes the actionable workflow indicator slot for pending subscribers) |
| Footer (minimal) | `parts/footer-minimal.html` | `page-app.html` (smaller footer for app context) |

### WordPress login (theme-styled, not theme-templated)

| Surface | File | Approach |
|---|---|---|
| Sign in / Sign up / Password reset | `assets/css/login.css` | Custom CSS enqueued via `login_enqueue_scripts` action; no template replacement |

## Step 5 — Content Gaps

### Blocking (site cannot launch credibly without these)

1. **Home page copy** — currently no marketing landing page exists at all. Required for any prospect to understand what Perihelion is.
2. **Why this exists / About content** — the values-statement essay. Without it, the brand's anti-extractive positioning lives only in this internal documentation, not in anything a visitor reads.
3. **Privacy policy copy** — required by phone-number collection alone. Plain-language summary plus formal language. Doubles as a trust signal that aligns with the anti-extractive stance.
4. **Sign-up flow polish** — WordPress's default registration screen styled to match the brand. The first impression of every user who creates an account.

### Important but deferrable

1. **Manifesto-page expansion** — the "Why this exists" page can launch as a single composed essay. A future iteration could add multiple pieces (the agency-redistribution explainer, the bystander-effect-flip framing, the get-offline ethic) as separate sections or sub-pages. Defer until post-launch and only if it earns its keep.
2. **Help / FAQ** — when someone has a question, where do they go? MVP answer is the Contact email; a FAQ page becomes useful only once enough questions accumulate to be worth aggregating. Defer.
3. **An attribution / colophon block in the footer** — small note that Perihelion is built by Sarah Lewis, links to wherever she'd want to be reachable. Nice but not blocking.

### Future opportunity

1. **Onboarding for first-time hosts.** The first time someone sets up a poster profile, there's no guided path explaining the three tiers, the privacy controls, or how to share their invite link. Likely belongs to a future product iteration, not the marketing site. Park.
2. **Public examples / showcase.** "Here's what an activity looks like" with a fake or anonymized example. Useful for prospects who want to understand the UX before signing up. Can wait.
3. **Integration content.** When discovery-platform integrations land (Meetup, Facebook Events — see brand brief Parking Lot), they'll need explainer content. Out of scope for MVP.

## Step 6 — Open Questions

These are decisions that surfaced during this phase that downstream skills should make rather than inherit blindly:

1. **Tagline lockup.** Working candidate from the creative direction is *"More time with the friends you already have."* — short, descriptive, captures the promise. *"Bring your own friends."* is a complementary positioning phrase that should appear elsewhere on the site (as a section header or pullquote). Both belong in the Home composition. Final decision belongs to **Design System Generator** / **Theme Builder** as the words land in the actual templates.

2. **Persona name in marketing copy.** "High-frequency organizer" is too clinical for user-facing copy. Recommended treatment: address the audience directly as **"you"** in most copy, and describe the persona by behavior — *"the friend who plans things"* — when describing the audience to itself. Don't invent a single noun ("the host," "the planner," "the gatherer") — they all overclaim or undershoot. Defer final voice calls to **Theme Builder** as copy gets written.

3. **Manifesto-page slug.** `/why` is short and fits the voice. Alternatives: `/about`, `/manifesto`, `/why-perihelion`. I picked `/why` because it's the shortest non-clinical option and matches the question a curious prospect actually asks. Defer to **Theme Builder** if a different slug is preferred.

4. **AI moderation surface in Privacy copy.** The brand brief identified AI moderation (sentiment analysis, automated flagging, no human reviewers) as a stated stance. Privacy is the natural place to surface it, but the exact copy ("we use AI to flag patterns of harm…") needs careful wording — too vague and it sounds like CYA, too specific and it locks in an implementation. Defer to **Theme Builder** when copy is written.

5. **Contact page format.** MVP is a single page with `mailto:` link and an expectation-setting note. Question: should the email address be Sarah's personal or a Perihelion-branded address (e.g., `hello@perihelion.app` once a domain exists)? Defer to whenever the production domain is set up.

6. **`page-app.html` template assignment.** The 8 app pages need their `_wp_page_template` post-meta set to `page-app.html`. Two options: (a) plugin-side — update `Orbit_Activator::create_pages()` and `orbit_migrate_page_slugs()` to assign it, with a corresponding migration to set it on existing pages; (b) one-time manual assignment via the page editor. Option (a) is correct long-term but requires a small plugin patch coordinated with the Theme Builder phase. Flag for plugin work alongside Phase 5.
