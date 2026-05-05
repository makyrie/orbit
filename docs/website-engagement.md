# Perihelion — Website Engagement Status

Living tracking document for the multi-phase Perihelion website build.
Coordinated by the `wp-website-engagement` skill; each phase invokes
its own role-based skill to produce that phase's deliverable.

## Project Info

- **Brand:** Perihelion
- **Started:** 2026-05-05
- **Current Phase:** 2 — Creative Direction (next)
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
- **Status:** Not started
- **Output:**
- **Open Questions:**

### 3. Content Architecture

- **Skill:** `content-architect`
- **Status:** Not started
- **Output:**
- **Open Questions:**

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

## Parking Lot

<!-- Ideas, questions, and tangents to revisit later — especially marketing/GTM ideas surfaced during brand strategy. -->

- **Manifesto page** celebrating "the joy of connecting with near strangers and the joy of making friends" — Sarah is leaning yes-but-not-now. Decide in Content Architecture.
- **Discovery-platform integrations** (Meetup, Facebook Events) as a long-term feature where Perihelion sits as a friend-coordination layer above them. Out of scope for MVP; revisit post-launch.
- **AI moderation** specifics: where it lives in the product, whether it's a marketing-page trust signal. Open for Content Architect and Theme Builder.
