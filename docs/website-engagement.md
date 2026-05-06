# Perihelion — Website Engagement Status

Living tracking document for the multi-phase Perihelion website build.
Coordinated by the `wp-website-engagement` skill; each phase invokes
its own role-based skill to produce that phase's deliverable.

## Project Info

- **Brand:** Perihelion
- **Started:** 2026-05-05
- **Current Phase:** 8 — Go-to-Market (next)
- **Plugin:** This site is powered by the Orbit / Perihelion plugin in this repo.
- **Theme:** Built in a separate repo at [bookchiq/perihelion-theme](https://github.com/bookchiq/perihelion-theme); installed at `wp-content/themes/perihelion/`.
- **Domain:** orbit.local (development); production domain TBD

## Phase Status

### 1. Brand Strategy

- **Skill:** `brand-brief-builder`
- **Status:** Complete (2026-05-05)
- **Output:** [`docs/brand-brief.md`](./brand-brief.md)
- **Open Questions:**
  - Tagline (defer to Content Architect / Creative Director)
  - Manifesto page yes/no (defer to Content Architect; user 60/40 in favor)
  - In-product communication scope (defer to product roadmap; potentially affects sitemap)
  - AI moderation surface — where/how it shows up (Content Architect + Theme Builder)
  - Sharper persona name for the primary audience (Content Architect / Creative Director)
  - Concrete voice references beyond REI + Taproot (Creative Director)

### 2. Creative Direction

- **Skill:** `creative-director`
- **Status:** Complete (2026-05-05)
- **Output:**
  - [`docs/creative-direction.md`](./creative-direction.md) — written direction
  - [`docs/style-tile.html`](./style-tile.html) — rendered style tile (open at 1200px wide)
- **Open Questions:**
  - Noise/texture overlay yes-or-no (defer to Theme Builder cost-vs-payoff call)
  - Honey accent usage rules — formalize in Design System Generator
  - Tagline lockup — visual treatment locked in (Fraunces italic, display size); the words *"More time with the friends you already have. Without the friction."* are a working candidate, not a commitment

### 3. Content Architecture

- **Skill:** `content-architect`
- **Status:** Complete (2026-05-05)
- **Output:** [`docs/content-architecture.md`](./content-architecture.md)
- **Open Questions:**
  - Final tagline wording (defer to Theme Builder when copy lands in templates; working: *"More time with the friends you already have."* + *"Bring your own friends."* as complementary phrases)
  - Final voice for AI moderation copy in Privacy page (defer to Theme Builder)
  - Manifesto-page slug — recommended `/why`, alternatives possible (defer to Theme Builder)
  - Contact email address (personal vs branded) — defer to production-domain setup
  - **Plugin coordination needed:** `_wp_page_template` post-meta assignment for app pages — small plugin patch alongside Phase 5

### 4. Visual Design

- **Skill:** `design-system-generator`
- **Status:** Complete (2026-05-05)
- **Output:** [`docs/design-system.md`](./design-system.md)
- **Open Questions:**
  - **Sienna canonical value** — spec promotes `#9C4B30` (was style-tile hover) to canonical Sienna for WCAG AA compliance. Visually near-identical, accessibility-significant. Awaiting Sarah's confirmation before Theme Builder applies.
  - Noise-overlay default = ON (resolved here, can be feature-flagged in Theme Builder if needed)
  - Mobile breakpoint = 768px recommended; final call deferred to Theme Builder
  - Logo / favicon / social-share image — placeholder for now, real assets later
  - Tier-badge copy source-of-truth coordination (plugin owns labels; theme reads or sync)

### 5. Theme Development

- **Skill:** `theme-builder`
- **Status:** Complete (2026-05-05)
- **Output:**
  - Repo: [bookchiq/perihelion-theme](https://github.com/bookchiq/perihelion-theme)
  - Initial scaffold PR: [#1](https://github.com/bookchiq/perihelion-theme/pull/1)
  - Deviations from spec: [`DEVIATIONS.md`](https://github.com/bookchiq/perihelion-theme/blob/feat/initial-theme-scaffold/DEVIATIONS.md) — 10 explicit calls
  - Activation guide: [`ACTIVATION.md`](https://github.com/bookchiq/perihelion-theme/blob/feat/initial-theme-scaffold/ACTIVATION.md)
- **Plugin coordination still needed:**
  - Patch `Orbit_Activator::create_pages()` and `orbit_migrate_page_slugs()` to set `_wp_page_template = 'page-app'` on the 8 internal app pages. Until this lands, manual template assignment per the activation guide.
  - (Future) actionable workflow indicator (pending-subscriber count badge) needs a hook the theme can consume — out of scope for the initial scaffold, defer to a follow-up.
- **Open Questions:**
  - Mobile breakpoint applied at 768px per the spec
  - Cream-paper noise overlay applied (default ON per spec; can be commented out in `custom.css`)
  - Theme deviations from spec are documented in DEVIATIONS.md for the QA Reviewer to reference

### 6. QA Review

- **Skill:** `theme-qa-reviewer`
- **Status:** Complete (2026-05-05)
- **Output:** [`docs/theme-qa-punch-list.md`](./theme-qa-punch-list.md)
- **Findings:** 1 Critical (login wordmark duplicate), 3 Major (active-nav highlight, footer Sign out, role-aware nav), 4 Minor, 4 Observations.
- **Recommended:** bundle all 5 high-priority items into one Phase-6-fixes PR on the theme repo. ~1–2 hours of focused work.
- **Open Questions:** (none — all open items from upstream phases either resolved or noted in punch list)

### 7. Marketing

- **Skill:** `marketing-planner`
- **Status:** Complete (2026-05-06)
- **Output:** [`docs/marketing-plan.md`](./marketing-plan.md)
- **Headline:** Plan is intentionally smaller than a default SaaS marketing playbook — declining most growth tactics (newsletter, branded social, paid, blog, community platforms) because they conflict with the brand's anti-extractive posture. Three primary channels: product share-tokens, the marketing site itself, and Sarah's existing personal voice. Five growth tactics, all low-medium effort. Three primary KPIs; explicitly not measuring vanity metrics.
- **Open Questions:**
  - Production domain — needed before HN post + Search Console submission. Defer to GTM phase.
  - SEO plugin choice (Yoast / Rank Math / SEOPress) — Sarah's call.
  - Analytics tool — recommend Plausible for privacy-first alignment with brand values; ~$9/mo.
  - Contact email address — defer to production domain setup.
  - Comment etiquette in adjacent communities (Tactic C) — judgment call per community.

### 8. Go-to-Market

- **Skill:** `gtm-playbook-builder`
- **Status:** Not started
- **Output:**
- **Open Questions:**

## Decisions Log

<!-- Record key decisions here as they're made so future sessions don't relitigate them. Add entries chronologically. -->

- **2026-05-05** — Brand competes on **values**, not features. Anti-extractive, agency-redistributing, get-offline are the three load-bearing values.
- **2026-05-05** — Primary audience is the high-frequency organizer; invitee experience is the binding design constraint, not the brand target. Brand voice and marketing speak to organizers.
- **2026-05-05** — Bring-your-own-friends positioning is permanent for the MVP — Perihelion is *not* a discovery platform. Long-term integrations with Meetup/Facebook Events/etc. are acceptable as a layer on top.
- **2026-05-05** — No human moderation team. AI/automated moderation acceptable. Deliberate absence of DM/chat is a structural defense against harassment.
- **2026-05-05** — Plugin-internal namespace stays as Orbit (engineering inertia, not strategic). Brand reads should not lean on the rename as a story.
- **2026-05-05** — Sarah is the maker, not the long-term face. Brand should stand on its own, plausibly handoff-able to a non-commercial steward.
- **2026-05-05** — Soul references: **REI** (gets you outside, not absorbed in them) and **Taproot Magazine** (slow, intentional, low-ad).
- **2026-05-05** — Visual direction: editorial calm + earthy warmth. Cream-paper foundation (`#F7F3ED`) + Sienna primary (`#B85D3D`) + Ink/Sage/Slate/Honey supporting palette.
- **2026-05-05** — Type pairing: **Fraunces** (headings, variable serif with personality) + **Inter** (body/UI, calm and legible). Both Google Fonts.
- **2026-05-05** — Five design principles: quiet by design, warm not bright, editorial pacing, tactile not flat, built to be left.
- **2026-05-05** — Five design don'ts: no dark mode default, no fluorescent CTAs, no urgency cues / engagement-trap notifications, no SaaS-startup tropes, no icons-as-mystery-meat.
- **2026-05-05** — Notification carve-out: **actionable workflow indicators** (e.g., pending subscribers awaiting approval) are allowed and necessary — they serve the user's intent, not engagement metrics. Visually restrained (small, paired with affected element, no pulsing/animation, Honey not red). Engagement-trap notifications remain forbidden.
- **2026-05-05** — **Manifesto page IS happening.** Resolves Phase 1 open question (Sarah was 60/40 yes-but-someday). Single page at `/why`, voice closer to a Taproot essay than to product marketing. Houses the "joy of connecting with near strangers" line.
- **2026-05-05** — **Persona naming in copy:** address the audience as **"you"** in most copy; use behavioral description **"the friend who plans things"** when naming the persona to itself. No single-noun handle ("the host" / "the planner" / "the gatherer") — all overclaim or undershoot.
- **2026-05-05** — **Marketing site = same WP install as the app.** Two-template approach: `page.html` (narrow editorial, marketing pages) and `page-app.html` (wider, app pages). Plugin will need to assign `_wp_page_template` to its created pages — small plugin patch coordinated with Theme Builder phase.
- **2026-05-05** — **Five marketing surfaces in MVP scope:** Home, Why this exists, Privacy, Contact, 404. Auth handled by WP core (`wp-login.php` styled via `login_enqueue_scripts`, no template replacement). No blog, no FAQ, no pricing.
- **2026-05-05** — **Sienna engineering adjustment:** canonical value moves from `#B85D3D` (creative direction) to `#9C4B30` (was the hover state) for WCAG AA compliance on Paper. Visually nearly identical; accessibility-significant. Awaiting Sarah's explicit confirm/revert before Theme Builder applies.
- **2026-05-05** — **Honey usage formalized:** Honey appears in exactly 2 places — actionable workflow indicator badges (with Ink text inside) and "I'm going" tier badge fills. Never as text on Paper, never on CTA buttons. (Resolves Phase 2 Open Question.)
- **2026-05-05** — **Body links:** Ink text + 2px sienna underline. Never sienna text in body. Body inline links pass WCAG AA via underline+contrast.
- **2026-05-05** — **Spacing scale:** 7 steps (`20`/`30`/`40`/`50`/`60`/`70`/`80` slugs) maps to `0.25rem`/`0.5rem`/`1rem`/`1.5rem`/`2.5rem`/`4rem`/`6rem`. Section padding compresses one step below 768px.
- **2026-05-05** — **No animations** beyond hover transitions and the 8-second success-message fadeout. No page-level motion, no entrance animations, no pulsing indicators.
- **2026-05-05** — **Theme lives in a separate repo** at [bookchiq/perihelion-theme](https://github.com/bookchiq/perihelion-theme). Plugin remains at `makyrie/orbit`. Two-repo split = WP-conventional separation (plugin = code, theme = presentation). Engagement docs continue to live in the plugin repo because they reference plugin work too.
- **2026-05-05** — **WordPress login is theme-styled, never theme-replaced.** Custom CSS via `login_enqueue_scripts` action; the wordmark is a Fraunces text replacement of the WP logo. Login flows (sign in / register / lost password) all served by WP core.
- **2026-05-05** — **Plugin-side patch needed for theme integration:** the 8 app pages need `_wp_page_template = 'page-app'` post-meta. Will land as a small follow-up plugin PR.

## Parking Lot

<!-- Ideas, questions, and tangents to revisit later — especially marketing/GTM ideas surfaced during brand strategy. -->

- **Manifesto page** celebrating "the joy of connecting with near strangers and the joy of making friends" — Sarah is leaning yes-but-not-now. Decide in Content Architecture.
- **Discovery-platform integrations** (Meetup, Facebook Events) as a long-term feature where Perihelion sits as a friend-coordination layer above them. Out of scope for MVP; revisit post-launch.
- **AI moderation** specifics: where it lives in the product, whether it's a marketing-page trust signal. Open for Content Architect and Theme Builder.
