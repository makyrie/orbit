# Perihelion — Website Engagement Status

Living tracking document for the multi-phase Perihelion website build.
Coordinated by the `wp-website-engagement` skill; each phase invokes
its own role-based skill to produce that phase's deliverable.

## Project Info

- **Brand:** Perihelion
- **Started:** 2026-05-05
- **Current Phase:** 4 — Visual Design (next)
- **Plugin:** This site is powered by the Orbit / Perihelion plugin in this repo.
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
- **Status:** Not started
- **Output:**
- **Open Questions:**

### 5. Theme Development

- **Skill:** `theme-builder`
- **Status:** Not started
- **Output:**
- **Open Questions:**

### 6. QA Review

- **Skill:** `theme-qa-reviewer`
- **Status:** Not started
- **Output:**
- **Open Questions:**

### 7. Marketing

- **Skill:** `marketing-planner`
- **Status:** Not started
- **Output:**
- **Open Questions:**

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

## Parking Lot

<!-- Ideas, questions, and tangents to revisit later — especially marketing/GTM ideas surfaced during brand strategy. -->

- **Manifesto page** celebrating "the joy of connecting with near strangers and the joy of making friends" — Sarah is leaning yes-but-not-now. Decide in Content Architecture.
- **Discovery-platform integrations** (Meetup, Facebook Events) as a long-term feature where Perihelion sits as a friend-coordination layer above them. Out of scope for MVP; revisit post-launch.
- **AI moderation** specifics: where it lives in the product, whether it's a marketing-page trust signal. Open for Content Architect and Theme Builder.
