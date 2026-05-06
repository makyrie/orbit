# Perihelion — Marketing Plan

Phase 7 deliverable from the website engagement. Reads
[`docs/brand-brief.md`](./brand-brief.md),
[`docs/content-architecture.md`](./content-architecture.md), and
[`docs/creative-direction.md`](./creative-direction.md).

> A note up front. The brand brief explicitly forbids most of the standard marketing playbook — no engagement loops, no captive-audience tactics, no aggressive growth, no commercialization, no scale. Sarah named "becoming yucky" and "becoming a tool for harassment" as the two failure modes; the design system carries an explicit "built to be left" principle. **A normal SaaS marketing plan would actively erode this brand.** This plan is intentionally smaller, quieter, and more cautious than a marketing planner usually outputs — and that's the point. Most "wins" in conventional marketing would be losses for Perihelion.

## 1. Channel Strategy

### Primary channels (3)

**Channel 1 — The product itself, via share tokens.** Perihelion's core growth mechanism is already built into the product: every organizer's profile has a shareable URL with an invite token. When organizer A subscribes their friends, those friends *are* Perihelion's audience. They subscribe to A and may organize themselves later. This is **the channel that compounds**, costs nothing in time, and is consistent with the "bring your own friends" positioning. The marketing investment here is making sure first-impression-via-share-link is excellent: fast load, immediate clarity about what the thing is, no signup wall to RSVP.

**Channel 2 — A modest, findable marketing site.** The website (Phases 1–6 deliverables) is itself a channel. Visitors arrive cold from search, from Sarah's personal mentions, from word-of-mouth, or directly via a share token. The site's job is to convert curiosity to a profile. **Not** a content engine — a thoughtful five-page site (Home, Why, Privacy, Contact, 404) maintained as a static artifact. Maybe a refresh every 6–12 months.

**Channel 3 — Sarah's existing personal voice.** Sarah already writes and shows up wherever she shows up (per the brand brief: "I don't tend to stick around and do things on a long-term basis"). When Perihelion is genuinely relevant — a friend complaining about group-text awkwardness, a community thread about social coordination — *mention it.* Not branded marketing, not a content calendar, not a Twitter account for Perihelion the brand. Just sincere word of mouth from the person who built it. **This channel uses no Perihelion-named accounts** — it lives in Sarah's existing footprint.

### Channels explicitly declined (and why)

- **Newsletter / email list (marketing).** The single biggest deviation from a default plan. **Not declining transactional email** — verification codes, activity notifications, digests are the product. Declining a *marketing* mailing list because:
  - The brand brief's "built to be left" principle directly conflicts with cultivating attention-via-inbox
  - Sarah said she doesn't sustain long-term commitments — a newsletter that goes silent for six months is worse than no newsletter
  - There's no ongoing content the list would distribute (the manifesto page is a one-time read)
  - Growing a marketing list contradicts "no mass scale"
- **Social media presence (Twitter/X, Instagram, LinkedIn, TikTok, etc.) under a Perihelion brand identity.** Each requires sustained content production at minimum 1–2 posts per week to avoid looking dead. This is unsustainable for a solo non-commercial maker, and the engagement-driven nature of these platforms is exactly the dynamic Perihelion is built to oppose.
- **Paid advertising** — non-commercial brand, no budget, and aspiring-mass-scale is a stated failure mode.
- **Influencer / creator partnerships** — would feel performative; brand identity isn't compatible with paid endorsements.
- **SEO-driven blog content** — explicitly ruled out by the brand brief ("the product is the product"). A blog would also create the same long-term commitment problem the newsletter has.
- **Community platforms (Discord, dedicated forum, Slack)** — engagement-loop antipattern, plus moderation overhead Sarah explicitly doesn't want.
- **Product Hunt launch** — the platform is built around hype-cycle attention; Perihelion's audience isn't there. (One **possible exception** is Hacker News — see Growth Tactics below — but as a single one-shot, not sustained presence.)

## 2. Content Strategy

The brand brief said it plainly: "the product is the product." Content here means **the small set of marketing-site pages and the talking points used on Channel 3**. There is no content production cadence in the SaaS-marketing sense.

### Content pillars (3)

**Pillar 1 — "Who this is for."** The audience-mirror frame: someone who recognizes themselves as the friend who plans things and is tired of the social tax. Carried by the Home page and Sarah's word-of-mouth voice. Goal: when someone has the right symptom (asymmetric inviting fatigue), they recognize themselves immediately.

**Pillar 2 — "Why we built this differently."** The values story — anti-extractive, agency-redistributing, get-offline. Carried primarily by the **manifesto page (`/why`)**, with consistent supporting language across the site. Goal: people who care about the philosophy understand why this isn't a typical social product, and choose to engage despite (or because of) the smallness.

**Pillar 3 — "How it actually works."** Short, concrete, mechanical: subscriptions, three commitment tiers, opt-in default. Carried by the front page's how-it-works section, the manifesto's mechanics paragraph, and any in-conversation explanations. Goal: a curious prospect understands the model in under 90 seconds and can decide whether it fits.

### Cadence

| Surface | Cadence |
|---|---|
| Marketing site copy | Once at launch, then revisit every 6–12 months if the language feels stale |
| Manifesto page (`/why`) | Once at launch; minor edits as values clarify |
| Sarah's word-of-mouth mentions | When relevant, never on a schedule |
| Status updates from the brand itself | Never (explicitly: no @perihelion social handle) |

### What NOT to produce

- Weekly newsletter
- Blog posts of any kind
- Social media graphics, carousels, or video
- Webinars
- Lead magnets / gated PDFs
- Case studies
- Marketing emails of any frequency

If content production starts feeling like a job, something has gone wrong with the brand.

## 3. SEO Positioning

### Core positioning

> Perihelion should be findable by people frustrated with group-text awkwardness or asymmetric inviting, who are searching for a less-extractive alternative to existing social/event tools.

This is **defensive SEO** — being there when someone with the right pain point goes looking — not aggressive SEO chasing high-volume terms.

### Keyword tiers

| Tier | Description | Example keyword phrases | Difficulty | Priority |
|---|---|---|---|---|
| Foundation | Brand + brand-adjacent terms — search for these should land on the homepage cleanly | "perihelion app", "perihelion social", "perihelion.social", "perihelion friend coordination", "bring your own friends" + ("app" / "tool" / "perihelion") | Low | High |
| Growth | Long-tail searches expressing the audience's pain | "alternative to evite for casual hangouts", "less awkward way to invite friends", "social coordination without group text", "casual hangout invite app", "low-friction friend invite tool", "asynchronous invitation app", "tool for the friend who plans everything" | Medium (long-tail) | Medium |
| Aspirational | Conceptually right, competitively unwinnable for a small site | "social network for existing friends", "alternative to meetup", "alternative to facebook events", "friend coordination app" | High | Low (don't actively pursue) |

The Foundation and Growth tiers are achievable specifically because they're long-tail and the niche is small. Don't try to rank for "social app" or "meetup alternative" — those are dominated by VC-backed competitors.

### On-site SEO recommendations

- **The Home page should have a clean H1 with the tagline** (currently "Perihelion" + tagline) — but consider the H1 carrying explicit pain-point language ("More time with the friends you already have. Without the friction.") to align with how people actually search. Already in place.
- **The `/why` manifesto page should target the values-led searches** — content like "anti-engagement social tool" and "non-extractive social product." Body language already aligned; no specific changes needed beyond authoring.
- **Each marketing page needs a clear `<title>` and `<meta description>`.** WordPress 6.x handles this via SEO plugins or built-in `<title>` tag generation. **Recommended:** install a small SEO plugin (Yoast, Rank Math, or — leaner — SEOPress) for explicit per-page meta control. Do not let SEO-plugin defaults drive away from the brand voice.
- **Schema.org Article markup on `/why`**, `WebSite` markup on the home page. Lightweight; helps search engines parse the values content.
- **No content silos, no internal-linking gymnastics** — the site is too small to need them. Five pages, all linked from the footer; that's the entire IA.
- **Submit `sitemap.xml` to Google Search Console once.** Then forget about it.

## 4. Email Strategy

**Recommendation: do not build a marketing email list.**

This is the unusual call the skill instructions warned about. Justifying it concretely:

1. **The brand brief explicitly opposes engagement-loop tactics.** A marketing email is by definition an attempt to draw the recipient back. The brand says "built to be left." Adding an email list directly contradicts a load-bearing brand value.
2. **Sarah's stated long-term commitment limits.** "I don't tend to stick around and do things on a long-term basis." A marketing list that goes 6+ months silent then sends a "we're back!" email is worse than no list. The realistic outcome is a list that gradually decays into a guilt-asset.
3. **There's no content the list would distribute.** No blog, no podcast, no events, no product newsletter. A list with nothing to send to is overhead without payoff.
4. **The product already has the only email pipeline that matters** — the digest, immediate notifications, and verification codes (per the plugin's `Orbit_Notifier`). That's email *as the product*. The marketing list would be email *about the product*, which is the part that's contradictory.

**Transactional email stays:** verification codes, immediate-tier notifications, daily digests, password resets, phone-verification confirmations. These are user-initiated and serve the user's goals — totally consistent with the brand.

**One small allowed item:** if someone uses the Contact page, Sarah can reply by email. That's correspondence, not a list.

## 5. Growth Tactics

Five concrete actions, each ranked by effort and timing:

### Tactic A — Polish the share-token first impression
- **What:** When someone follows a `/@{slug}/subscribe?token=...` URL from a friend, the page they land on must instantly answer "what is this and what's it asking of me?" Today the subscribe form does this functionally; verify visual polish, trust signals, "no account needed yet" reassurance, the friend's name and bio above the form, the path to declining without guilt.
- **Why it fits:** Channel 1 is the share token. The token's landing page is the funnel. Most growth investment goes here.
- **Effort:** Low–medium. Mostly a copy + design pass on the existing subscribe shortcode.
- **Timeline:** Pre-launch / before any external promotion.
- **Expected impact:** **High per-click conversion** is the realistic goal. Cold-acquired visitors won't convert as well as friend-referred visitors, so optimizing this surface compounds.

### Tactic B — One-shot Hacker News post (timed carefully)
- **What:** A single Show HN or "I built this thing" post explaining Perihelion's design ethos — opt-in inviting, three commitment tiers, anti-engagement-loop posture, the agency-redistribution thesis. The HN audience overlaps significantly with Perihelion's "philosophically aligned" cohort: people who care about software values, who are skeptical of extractive social media, and who are themselves often the friend-who-plans-things in their groups.
- **Why it fits:** HN is one of the few attention-distribution channels where the *substance* of an idea outweighs the *visibility* of the brand. A non-commercial, thoughtful post can reach the right audience without paying for it.
- **Effort:** Medium. Drafting the post + selecting an opportune time to submit (Tuesday/Wednesday morning Pacific is statistically best for HN). Ready to engage in comments thoughtfully for ~24 hours.
- **Timeline:** After ~30 days of soft-launch use by Sarah's own friend group, so there's a real install with real activity to demo.
- **Expected impact:** **One realistic outcome:** 50–500 new profile creations over 1–2 weeks; some retention, most churn. **One unrealistic outcome:** "going viral." Don't expect that. The HN post serves to seed the population of philosophically aligned early users; it doesn't sustain growth.

### Tactic C — Strategic placement in adjacent communities
- **What:** Identify 3–5 communities where the audience hangs out and where mentioning Perihelion would not be promotional spam. Examples:
  - Indieweb / IndieHackers — small-tools-with-values communities
  - The "friendship recession" discourse — substacks like Anne Helen Petersen's *Culture Study*, or thoughtful pieces in *The Atlantic* about adult friendship
  - Specific Reddit communities — r/CasualConversation, r/socialskills, r/AdultFriendship
  - Quiet community newsletters — Robin Sloan's, Substacks about software-as-craft
- **Mention rules:** only when the conversation is *already* about asymmetric inviting / friendship coordination / social tax. Never as a top-of-mind promotion.
- **Effort:** Low (mention) to medium (write a guest piece if invited).
- **Timeline:** Months 2–6 after launch, opportunistic.
- **Expected impact:** Slow drip of philosophically aligned users. 5–20 new profile creations per mention is realistic.

### Tactic D — Sarah's friend group, with intent
- **What:** Sarah's actual friend group is the alpha test of Perihelion. Specifically *use it* with them for 2–4 months. This isn't really a marketing tactic so much as a credibility prerequisite for everything else. If Sarah herself isn't the prototype hero user, the brand has no center.
- **Why it fits:** "If you're the friend who plans things" — Sarah is. Be that.
- **Effort:** Low (use the product as built).
- **Timeline:** Pre-launch + ongoing.
- **Expected impact:** Real word-of-mouth is generated by real positive experiences, not by marketing copy.

### Tactic E — A single, beautiful README on the GitHub repos
- **What:** Both [`makyrie/orbit`](https://github.com/makyrie/orbit) and [`bookchiq/perihelion-theme`](https://github.com/bookchiq/perihelion-theme) get README treatments that read like a small essay about why this exists. Doubles as a discoverability vector for developers who happen across the repos and a permanent reference for anyone considering forking / contributing.
- **Why it fits:** Open-source-curious developers are an aligned audience (technically literate, often disenchanted with extractive social products). They find projects via GitHub topic browsing, "made-with" lists, and links from elsewhere.
- **Effort:** Low. ~1 hour of writing per repo.
- **Timeline:** Whenever — defer to post-launch if convenient.
- **Expected impact:** Modest. A handful of GitHub stars, a couple of issues filed, perhaps one or two contributors over the first year. The real win is having a permanent artifact that explains the project's intent.

## 6. Measurement

### Primary metrics (3)

**P1 — Active monthly organizers.** The single most important number. An organizer is "active" if they posted at least one activity in the trailing 30 days. Available via SQL on `wp_orbit_activities` joined to `wp_orbit_profiles`. **Realistic targets:**

| 3 months | 6 months | 12 months |
|---|---|---|
| 25–50 active monthly organizers | 75–150 | 200–400 |

These targets reflect the brand brief's "few hundred organizers" 12-month picture and rate-limit it down based on word-of-mouth growth being the only real channel.

**P2 — Activity posts per active organizer per month.** Proxy for "are organizers actually getting use from this." Available via `COUNT(activities)/COUNT(distinct active organizers)`. **Target: 2–4.** Below 1.5 means the product isn't fitting into people's coordination habits. Above 6 might mean a single power user skews the average — check the median too.

**P3 — Subscribe-conversion from share-token landing.** When someone follows a friend's `/@{slug}/subscribe?token=...` URL, what % create a profile and confirm? Tracked via UTM parameters or a simple `_referrer` query var captured at the subscribe form. **Target: 60–75%** (warm referrals always convert at much higher rates than cold). Falling below 50% means the share-token landing experience needs work (Tactic A).

### Secondary metrics (4)

- **SMS opt-out rate** (`orbit_sms_opted_out` user_meta count / total users with verified phone). Should not rise over time. A rising opt-out rate signals notifications have become intrusive.
- **Time-to-first-activity-posted** for new posters (profile_created → first_activity timestamp). Onboarding-friction proxy. Should be measured in hours, not days.
- **Returning-poster rate** — % of organizers who posted activities in *at least* 2 different months over their lifetime. Sustained-engagement proxy. Goal: 40%+ at 12 months.
- **Repeat-organizer cluster rate** — number of distinct friend groups (defined as overlapping subscriber sets) using Perihelion. Proxy for spread across social graphs vs deep penetration of a single group. Goal: at least 5 distinct clusters by month 6.

### What NOT to measure

- **Total user signups** (active matters; abandoned profiles distort the number)
- **Page views / sessions / time on site** — for this brand, *short* time on site is the goal. Visitors should bounce to the app or close the tab. Time-on-site as a "good number" is incompatible with "built to be left."
- **Bounce rate** (same reason)
- **Social-media follower count** — no social presence, nothing to measure
- **Email-list size** — no marketing list, see Section 4
- **Cost-per-acquisition / CAC / LTV / etc.** — non-commercial brand; these metrics imply revenue mechanics that don't exist
- **Traffic from any single high-volume keyword** — vanity unless the visitors actually become organizers

---

## Open Questions

These came up while planning and need user input or a future decision:

1. ~~**Production domain.**~~ ✅ **Resolved 2026-05-06: [perihelion.social](https://perihelion.social/) registered.**

2. **SEO plugin choice.** Per Section 3 recommendations. Yoast, Rank Math, and SEOPress are all reasonable. SEOPress is the leanest, Yoast the most established, Rank Math has the most modern UX. Sarah's call. Doesn't need to ship at launch — can be added later.

3. **Analytics tool, if any.** Conventional answer: Google Analytics or Plausible. Privacy-first answer: **Plausible** (no cookies, no personal data, self-hostable). The brand's anti-extractive stance argues strongly for Plausible. But Plausible costs ~$9/month. Acceptable cost, just calling out.

4. **Email contact for the Contact page.** Now that the domain is registered, set up `hello@perihelion.social` (or similar) and forward to Sarah's personal email. Defer to host setup.

5. **Comment moderation in adjacent communities (Tactic C).** Some communities allow self-promotion only in tagged threads or with disclosure ("disclosure: I built this"). Others ban anything that looks like self-promotion. **Recommendation:** when in doubt, lead with a useful answer to the actual question and mention Perihelion only if it's directly relevant — never as a top-line "have you heard of my project."

---

## Structured Marketing Data

```yaml
marketing:
  primary_channels:
    - "Product share-tokens (organic word-of-mouth)"
    - "Marketing site (modest, findable, static)"
    - "Sarah's existing personal voice (no Perihelion-branded social)"

  declined_channels:
    - "Marketing email list / newsletter"
    - "Social media presence under the Perihelion brand"
    - "Paid advertising"
    - "Influencer / creator partnerships"
    - "SEO-driven blog content"
    - "Community platforms (Discord, Slack, dedicated forum)"
    - "Product Hunt launch"

  content_pillars:
    - theme: "Who this is for"
      content_type: "Marketing site pages + word-of-mouth talking points"
      cadence: "Once at launch, revisit every 6-12 months"
    - theme: "Why we built this differently (the values story)"
      content_type: "Manifesto page + supporting site copy"
      cadence: "Once at launch, minor edits as values clarify"
    - theme: "How it actually works"
      content_type: "Front-page how-it-works section + in-conversation explanations"
      cadence: "Static; revisit when product mechanics change"

  seo:
    core_positioning: "Findable by people frustrated with group-text awkwardness or asymmetric inviting, looking for a less-extractive alternative."
    foundation_keywords:
      - "perihelion app"
      - "perihelion social"
      - "perihelion.social"
      - "perihelion friend coordination"
      - "bring your own friends app"
    growth_keywords:
      - "alternative to evite for casual hangouts"
      - "less awkward way to invite friends"
      - "social coordination without group text"
      - "casual hangout invite app"
      - "low-friction friend invite tool"
      - "asynchronous invitation app"
      - "tool for the friend who plans everything"
    aspirational_keywords_not_pursued:
      - "social network for existing friends"
      - "alternative to meetup"
      - "alternative to facebook events"

  email:
    approach: "Transactional only. No marketing list."
    cadence: "Verification codes, immediate notifications, daily digest — all triggered by user action via the plugin's Orbit_Notifier."
    list_building: "Explicitly not pursued. See Section 4 justification."

  growth_tactics:
    - name: "Polish share-token first-impression"
      effort: "low-medium"
      timing: "pre-launch"
    - name: "One-shot Hacker News post"
      effort: "medium"
      timing: "month 1-2"
    - name: "Strategic placement in adjacent communities"
      effort: "low"
      timing: "months 2-6, opportunistic"
    - name: "Sarah uses Perihelion with her actual friend group"
      effort: "low (use the product)"
      timing: "pre-launch and ongoing"
    - name: "Beautiful READMEs on GitHub repos"
      effort: "low"
      timing: "any time"

  kpis:
    primary:
      - "Active monthly organizers (posted at least 1 activity in trailing 30 days)"
      - "Activity posts per active organizer per month"
      - "Subscribe-conversion rate from share-token landing"
    targets:
      three_month:
        active_monthly_organizers: "25-50"
        posts_per_organizer: "2-4"
        subscribe_conversion_pct: "60-75"
      six_month:
        active_monthly_organizers: "75-150"
        posts_per_organizer: "2-4"
        subscribe_conversion_pct: "60-75"
      twelve_month:
        active_monthly_organizers: "200-400"
        posts_per_organizer: "2-4"
        subscribe_conversion_pct: "60-75"
    secondary:
      - "SMS opt-out rate (should not rise over time)"
      - "Time-to-first-activity-posted for new posters"
      - "Returning-poster rate (% posting in 2+ different months)"
      - "Repeat-organizer cluster rate (distinct overlapping friend groups)"
    not_measured:
      - "Total user signups (vanity)"
      - "Page views, sessions, time on site (incompatible with built-to-be-left)"
      - "Bounce rate"
      - "Social-media follower count (no presence)"
      - "Email list size (no list)"
      - "CAC / LTV (non-commercial brand)"
```
