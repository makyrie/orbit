# Perihelion — Go-to-Market Playbook

Phase 8 deliverable from the website engagement. Reads
[`docs/marketing-plan.md`](./marketing-plan.md) and
[`docs/brand-brief.md`](./brand-brief.md). Translates the strategy
into a concrete 7-week launch sequence.

> **Why this is shorter than a typical GTM playbook.** Per the marketing plan, Perihelion explicitly declines most growth tactics. There's no email list to coordinate, no social presence to schedule, no influencers to brief, no paid ads to set up. **The launch is mostly the absence of launching** — getting the product into production, being polished, and letting word-of-mouth do its work over months, not weeks. This document is a checklist for that quieter rollout.

## 1. Launch Readiness

### Ready ✅

- **Brand brief** finalized (Phase 1 deliverable)
- **Creative direction + style tile** complete (Phase 2)
- **Content architecture** with all marketing pages spec'd (Phase 3)
- **Design system** with theme.json-ready tokens (Phase 4)
- **FSE theme** at v0.3.0, all Phase 6 QA fixes merged (Phase 5 + 6)
- **Marketing plan** at v1 (Phase 7)
- **Plugin** at v1.3.0 with consume-theme-token CSS, role-aware app nav, page-app meta migration
- **53 PHPUnit tests passing** on the plugin (activity REST endpoints covered)
- **Local install working** — site renders correctly at `orbit.local` with theme + plugin coordinated

### Blocking 🔴

- **Production domain not yet acquired or DNS-configured.** Until this lands, none of the launch can ship — every channel and tactic in the marketing plan needs a real public URL. **Sarah's call:** pick a domain (suggestions: `perihelion.app`, `perihelion.tools`, `perihelion.club`, `peri.helio.app`) and register it. Defer to her preference; flag as the single biggest blocker.
- **Production hosting not yet provisioned.** Once the domain exists, the WP install needs to live somewhere. Managed WP (Pressable, WP Engine, Kinsta) is fast and turn-key but $20–40/mo. Self-managed VPS is cheaper but Sarah owns the maintenance. Plain DigitalOcean/Linode + a one-click WordPress droplet is the middle ground (~$6/mo). **Recommend: a managed WP host for first 12 months** — Sarah explicitly noted she doesn't want full-time-job energy on this, and managed hosting absorbs ~80% of the operational burden for $25/mo.
- **Marketing-page content authored.** Site templates exist; the actual *copy* for `/why`, `/privacy`, and `/contact` is still placeholder-y or empty. **Especially `/why`** — that's the manifesto page; needs Sarah's voice to land. Estimate: 2 hours of focused writing.

### Not blocking (can launch without) ⚠️

- **SMS / Twilio integration** — Sarah's Twilio account is still pending approval. **Activity creation works fine without SMS** (organizers create activities, subscribers see them in the dashboard, email digests deliver). Phone verification flow can be soft-disabled at launch (the design system already accounts for the "Twilio not configured" state with a graceful notice). Add SMS in a v1.1 once Twilio approves.
- **Plugin folder rename** (`orbit/` → `perihelion/`) — the last user-facing "Orbit" surface, but only visible in WP admin's plugin file path, not on the public site. Defer indefinitely.
- **Favicon / social-share image** — placeholder is fine for soft launch. Polish during Amplification phase.
- **README polish on both repos** — Tactic E from the marketing plan; pre-launch is fine but not blocking.
- **SEO plugin install** (Yoast / Rank Math / SEOPress) — recommend installing during Pre-Launch but the site works fine without it for the first weeks.
- **Analytics tool** — Plausible recommended. Adding it in week 2 vs day 1 doesn't materially affect launch metrics since the early traffic is so small.

## 2. Launch Phases

Total timeline: **7 weeks from "ready to ship" to "ongoing operations"**, with explicit decision points along the way.

### Phase A — Pre-Launch (2 weeks)

**Goal:** Get the product live on a real domain, with marketing-site copy that reads, and Sarah's first ~5 friends quietly using it.

**Week -2:**

- Sarah picks production domain. Registers it.
- Set up DNS, SSL, WordPress installation on chosen host
- Migrate the local WP install (or rebuild from the perihelion-theme + orbit plugin GitHub repos, plus a fresh install of WP)
- Activate Perihelion theme, run plugin migrations, verify all 8 app pages have `_wp_page_template = 'page-app'`
- Set the WP Site Title to "Perihelion" (per ACTIVATION.md step 5)
- Create the marketing pages: `/`, `/why`, `/privacy`, `/contact`
- **Sarah authors the manifesto.** Use the brand brief's "joy of connecting with near strangers" and "agency redistribution" language as anchors. ~500–800 words. Voice: closer to a Taproot essay than a product page.
- **Sarah authors the privacy page.** Plain-language summary at top, formal language below. Surfaces the AI-moderation-only stance and the no-data-selling policy.
- Contact page: short, with a `mailto:` link
- Verify the front page renders correctly via the 5 patterns

**Week -1:**

- Install SEO plugin (recommend SEOPress for leanness; Yoast or Rank Math also fine). Configure: site name, default meta description, Open Graph fallback image.
- Install analytics — recommend Plausible (privacy-first, $9/mo, brand-aligned). Or skip entirely for v1 and add later when there's traffic worth analyzing.
- Submit `sitemap.xml` to Google Search Console
- **Polish the share-token landing experience** (Tactic A from the marketing plan). Walk through the `/@{slug}/subscribe?token=...` flow as if you'd never seen Perihelion before. Friction points get fixed now.
- **Sarah onboards 3–5 close friends.** Personal walkthroughs over text or in person — "hey, I built this thing, want to be one of my first users? Takes 30 seconds." Get them into the subscriber + poster flow.
- Plan: those 3–5 friends post 1–3 activities each over the week, so when external visitors arrive in the next phase, the dashboard view feels alive.

**Success signal at end of Pre-Launch:**

- Site is live at the production domain, all 5 marketing pages render correctly
- 3–5 organizers exist with at least one activity each
- Sarah has personally completed the share → subscribe → respond loop and feels good about it
- No red flags in the QA punch list (PR #4 fixes already in)

**If unmet:** delay Soft Launch one week. The brand is allowed to take its time.

### Phase B — Soft Launch (2 weeks)

**Goal:** Use Perihelion for real, with Sarah's actual friend group, while not yet announcing externally. The product needs to feel inhabited before it gets visitors.

**Week 1 (launch week):**

- Sarah continues to use Perihelion for actually planning hangouts with her friends. The friends already onboarded in Pre-Launch keep using it.
- Sarah expands to 5–10 more friends — the second-tier "I think they'd like this" group. Same low-key personal outreach.
- Each new poster onboards by example: "Maya posted a hike on Saturday — see if you want to come, or post your own." Give them a path that doesn't feel like a tutorial.
- Watch the share-token conversion rate from these warm referrals. Target: 70%+ for friend-to-friend invitations. Lower than that means the landing page still has friction worth fixing.

**Week 2:**

- 1–2 weeks into real use, Sarah evaluates: are the friends actually using it, or did they sign up to be polite? **This is the most important launch metric.** If the friends use it without prompting (i.e., they post things to it that would have otherwise been a group text), the product is working. If they don't, no marketing tactic can fix that.
- Optional: Sarah writes a short reflection post on her existing personal channels — what it's been like to plan a few weeks of hangs through Perihelion. Not a launch announcement; just a "here's what I've been doing" update. Some friends-of-friends will see it and might ask. That's the early word-of-mouth seeding.

**Success signal at end of Soft Launch (week 2):**

- 8–15 active organizers (Sarah + first-tier + second-tier friends), most having posted at least 2 activities in the past 14 days
- The share-token funnel is converting at 60%+
- Sarah personally feels the product is working in her life — not just functioning, but *useful*

**If unmet:** the issue is product-market-fit not marketing. Pause external launch. Talk to friends about why they didn't engage. Iterate the product before going broader.

### Phase C — Amplification (3 weeks)

**Goal:** Extend reach beyond Sarah's existing network, *carefully*, using the marketing plan's named tactics.

**Week 3:**

- **Tactic E (READMEs).** Spend ~2 hours writing thoughtful READMEs for both repos: `makyrie/orbit` and `bookchiq/perihelion-theme`. The READMEs should function as standalone explainers — the project's purpose, anti-extractive design ethos, brand attribution, and the relationship between plugin and theme. People who find the repos via GitHub topic browsing should be able to understand the project from the README alone.
- This week is otherwise low-effort. Watch metrics. Respond to any incoming Contact emails.

**Week 4:**

- **Tactic B (Hacker News post).** Sarah drafts a Show HN post (see Section 4 for draft copy). Submits Tuesday or Wednesday around 9–10 AM Pacific (statistically the best window). Plans to be available to respond in comments for the following 24 hours.
- The HN post is **a single one-shot.** If it gets 5 upvotes and dies, that's not failure; it's just a non-event. If it gets traction (50+ upvotes), expect 100–500 profile creations in 1–2 days, mostly tire-kickers, ~10–20% of whom stick.
- Watch the post's traffic for: signups, support questions, complaints about the model. Comment patterns will reveal whether the audience landing on the site matches the brand's target.

**Week 5:**

- Reflect on HN outcome. **No rebound effort if it didn't go well** — the brand doesn't get a second HN shot for this announcement.
- **Tactic C (community mentions).** Begin opportunistic mentions in 1–2 communities where Sarah is already a participant. Examples: an IndieHackers thread about social products, a Reddit thread about adult-friendship coordination, a comment on a Substack post about the friendship recession. **Rule:** only when the conversation is *already* about the relevant pain point. Never as a top-line "have you heard of my project."
- Continue using the product personally.

**Success signal at end of Amplification (week 5):**

- 25–50 active organizers (matching the 3-month target from the marketing plan, hit early)
- 1–2 distinct friend-group clusters beyond Sarah's own immediate circle (sign of word-of-mouth spread)
- HN post outcome metabolized — either it brought aligned users (good), brought wrong-audience users who churn (fine), or did nothing (also fine)

**If significantly unmet:** the issue is more likely product-market fit than marketing reach. Don't repeat HN; revisit the share-token funnel and the manifesto-page positioning.

### Phase D — Sustain (week 6 onward)

**Goal:** Drop into the marketing plan's "do nothing on a schedule" cadence and watch metrics.

**Week 6:**

- The launch is over. Behavior shifts from active outreach to passive presence.
- The site sits there, ranking gradually for foundation + growth keywords (per Section 3 of the marketing plan).
- Sarah uses Perihelion. Friends use it. New organizers find it occasionally via search or word-of-mouth.

**Week 8 (post-launch review):**

- Sarah reviews the launch metrics from Section 6 below.
- Decides: keep the same approach? Add anything? Drop anything?
- **Default decision: change nothing for 3 more months.** The brand explicitly opposes constant tinkering. Let the metrics develop signal before reacting.

**Sustained operation (months 3–12):**

- Per the marketing plan, KPIs reviewed quarterly.
- Revisit `/why` copy if voice feels stale (~6 months out).
- Maybe add a `/changelog` page if and when there's enough product evolution to be worth listing.
- That's it.

## 3. Channel-Specific Launch Tactics

### Channel 1 — The product itself / share tokens

| Phase | What happens |
|---|---|
| Pre-Launch | Polish the share-token landing experience. Walk through `/@sarahlewis/subscribe?token=...` as a stranger. Verify the bio, friend's name, and "what is Perihelion" framing all read clearly above the fold. |
| Soft Launch | Sarah generates her invite link and shares it with 5 close friends, then 5–10 more. Watch conversion. |
| Amplification | The HN post drives traffic to the home page, *not* a share token. Different funnel — slower conversion. |
| Sustain | Continues passively forever. The token in every poster's profile is the channel. |

### Channel 2 — The marketing site

| Phase | What happens |
|---|---|
| Pre-Launch | All 5 pages authored and live. SEO plugin configured. Search Console submitted. |
| Soft Launch | Watch the Plausible/analytics dashboard for surprise visitors. Most visitors at this stage will be invited friends, not search traffic. |
| Amplification | HN traffic spikes the home page briefly. Most don't convert. A small percentage do — those are the aligned audience. |
| Sustain | Site sits. SEO accumulates over months. Long-tail traffic begins arriving in months 3–6. |

### Channel 3 — Sarah's existing personal voice

| Phase | What happens |
|---|---|
| Pre-Launch | Nothing. Don't pre-announce. |
| Soft Launch | One quiet "here's what I've been doing" reflection post on Sarah's existing platforms (whatever she normally writes on). NOT a launch announcement; a personal update. |
| Amplification | If Sarah writes about adjacent topics (friendship, software ethics, anti-extractive product design) during this window, she can mention Perihelion as relevant context. Not a marketing exercise — a sincere mention. |
| Sustain | Sarah keeps writing about whatever she writes about. Perihelion gets mentioned when relevant. Forever. |

## 4. Announcement Copy Drafts

Drafts to edit. Sarah should rewrite each in her actual voice.

### Soft-launch personal outreach (1:1 message, edit per recipient)

> Hey [name] — I built a small thing for the friend who plans things, and I think you'd like it given how often you're the one putting hangs together. It's called Perihelion. The short version: you post "I'm doing X, want to come?" with three commitment levels (just an idea / I'll go if you will / I'm going - join me) and people who've subscribed to you can opt in or stay quiet — no group-text pressure. Built specifically *not* to be another social network you have to babysit.
>
> Want to be one of my first users? It's open at [URL]. Setting up your profile takes about 30 seconds. No worries if it's not your thing.

### "Here's what I've been doing" reflection post (Sarah's existing channels)

> I've been quietly working on a small thing called **Perihelion** — a way to invite the friends I already have to casual stuff without the social tax of group texts. The premise: people opt *in* to hearing about my activities, declining is the structural default, and there are three commitment tiers so a "want to grab coffee?" travels as casually as a "you should come to my birthday party."
>
> It's been working surprisingly well for the last [N] weeks. I'm now planning museum trips, hikes, and dinners with people who've subscribed without feeling like I'm bothering anyone. Saying yes is real, saying nothing is fine.
>
> If you're the friend who plans things, you can take a look at [URL]. Free, open source, non-commercial.

### Show HN draft

**Title:**
> Show HN: Perihelion — coordinating hangouts with the friends you already have

**Body:**
> I built this because I was tired of the awkwardness of group texts when I wanted to invite people to casual things. Perihelion is a small WordPress plugin + companion FSE block theme for low-friction friend coordination. The premise:
>
> 1. Subscribe to people whose plans you want to hear about (they approve you, you're in)
> 2. They post things they're doing — at one of three commitment tiers ("just an idea", "I'll go if you will", "I'm going — join me")
> 3. You opt in if interested, stay quiet if not. Saying nothing is the default; saying yes is real.
>
> A few non-features by design: no feed to scroll, no notifications begging you back, no engagement metrics, no AI feed ranking, no DMs (deliberate — keeps moderation overhead near-zero). It's not for finding *new* friends; it's for spending more time with the ones you have. The whole thing is built around getting you off the app and into the room with your people.
>
> Open source (plugin: GPL, theme: GPL), non-commercial. Live at [URL]. Built solo by me, [Sarah Lewis](https://github.com/bookchiq), over the last few months.
>
> Plugin: https://github.com/makyrie/orbit
> Theme: https://github.com/bookchiq/perihelion-theme
>
> Happy to answer questions about the architecture, the design philosophy, or why I made specific tradeoffs (especially: why no DMs, why no blog, why no email list, why three tiers and not two or five).

### No mass email blast — see marketing plan §4

There's no mailing list to send to. If Sarah has a personal list (Substack subscribers, IndieHackers connections, whatever) that overlaps the audience, **a single update** there during Soft Launch is fine — same voice as the "Here's what I've been doing" post above. **Not a launch sequence.** One message.

## 5. Contingency Notes

### "Nobody noticed"

This is the **default outcome.** A new brand from a non-celebrity maker, with no paid promotion, on a single HN post + handful of community mentions, will mostly be invisible to the broader market. **This is fine.**

What to do:
- **Don't repeat the HN post** — single one-shot per the marketing plan
- **Don't start a newsletter** to compensate — that would erode the brand
- **Don't add a Twitter/social handle** to "build awareness" — same erosion
- **Do** continue using Perihelion personally with friends. Word-of-mouth is the channel. It's slow. That's the design.
- After 90 days, evaluate: is anyone using it besides Sarah's first-tier friends? If yes, the slow growth is working — keep going. If no, revisit Tactic A (share-token first-impression) — friends-of-friends conversion is the bottleneck.

### "Wrong audience showed up"

Likely scenarios:
- **HN post brought VC-types asking about monetization or scaling.** Stay polite, redirect to the brand brief's "non-commercial, possibly handoff-able" stance. Don't engage with monetization questions; restate the position.
- **HN post brought open-source-ideology debates** (GPL vs MIT, why WordPress, etc.). Engage briefly with technical questions; don't get drawn into ideology-of-licensing fights.
- **Marketing site brought "social network for finding friends" hopefuls.** They'll bounce when they see the BYOF positioning. The home page already says it's not for that. No fix needed.

### "Overwhelmed"

If at any point the launch starts feeling like a job, drop these in order:
1. **First, drop community mentions (Tactic C).** Easiest to skip; lowest individual ROI.
2. **Then, defer the HN post (Tactic B).** Wait until it feels like a sincere thing to share, not a marketing chore. There's no expiration.
3. **Then, defer the READMEs (Tactic E).** They can be written later.
4. **Last to drop: actually using Perihelion personally (Tactic D).** This one is non-negotiable. If Sarah isn't using it, the project doesn't have a center.

If multiple of these need to drop: **the brand is fine.** The launch is mostly the absence of launching, by design. Slowness is on-brand.

### "Something's broken"

Reference [`docs/theme-qa-punch-list.md`](./theme-qa-punch-list.md) for the most recent known issues. Known carve-outs for launch:

- SMS verification depends on Twilio approval (still pending) — until then, the `/settings` page should show "SMS not currently available." This is a graceful degradation, not a bug.
- Plugin folder is still named `orbit/` not `perihelion/` — visible only in WP admin's plugin file path. Cosmetic, not blocking.
- Active-page nav highlighting requires JS to run — degraded gracefully on no-JS browsers (the nav still works, the highlight just doesn't appear).

For **new** issues that surface during launch, the standard PR-review workflow on the orbit + perihelion-theme repos applies. Severity-prioritize per the QA punch list categories.

## 6. Launch Metrics

Distinct from the marketing plan's ongoing KPIs. **Window: weeks -2 through 8** (the 7-week launch period, plus the 2 weeks of pre-launch readiness).

### What to track

| Metric | Source | Target by week 8 |
|---|---|---|
| Total active organizers | SQL on `wp_orbit_activities` JOIN `wp_orbit_profiles` (active = posted in last 30 days) | 25–50 (matches marketing plan's 3-month KPI, hit early) |
| Distinct friend-group clusters | Manual eyeball of the subscriber overlap matrix | 2–4 (Sarah's group + 1–3 others) |
| Share-token landing → profile conversion | Plausible UTM or `?token=` referrer captures | 60–75% |
| HN post outcome | HN points + comments + post-day site traffic | Either 50+ points (engaged) or <10 (one-shot non-event) — both are valid outcomes |
| Total activities posted | SQL count | 50–150 |

### What success looks like

- **Strong:** 30+ active organizers, 3+ distinct clusters, 70%+ token conversion, HN post landed quietly with a small batch of aligned signups
- **Healthy:** 15–30 active organizers, 2 clusters, 60%+ token conversion, HN post may not have landed
- **Concerning:** <15 active organizers including Sarah's first-tier friends, single cluster, low token conversion. Pause and revisit product fit, not marketing reach.

### What to definitely **not** measure

Per the marketing plan, the same vanity metrics apply during launch:

- Total signups (active matters; abandoned profiles don't)
- Page views (small site, low traffic — the number is meaningless)
- Time on site (incompatible with "built to be left")
- Bounce rate (visitors *should* bounce if the brand isn't for them)
- Social-media follower count (no presence)

### Post-launch review (week 8)

Sarah blocks 30 minutes to:
1. Run the SQL queries above against production
2. Look at Plausible dashboard if installed
3. Read any incoming Contact emails from the launch period
4. Decide: continue current approach unchanged for 3 more months (default), OR identify one specific thing worth changing

**Default outcome of the review: change nothing.** The brand opposes constant tinkering. Signal develops slowly.

---

## 7. Open Questions

These need decisions before or during launch:

1. **Production domain name.** Single biggest blocker. Suggestions: `perihelion.app` (clean), `perihelion.tools` (utility framing), `perihelion.club` (community framing), shorter variants like `peri.app`. **Sarah's call.**

2. **Hosting platform.** Recommendation: managed WordPress (Pressable, Kinsta, WP Engine, $20–40/mo). Alternative: DigitalOcean/Linode 1-click WP droplet ($6/mo, more maintenance). **Sarah's call.**

3. **SEO plugin.** Yoast / Rank Math / SEOPress — all reasonable. Recommend SEOPress for leanness. **Sarah's call.**

4. **Analytics: Plausible or skip?** Recommend Plausible for brand-alignment (privacy-first, no cookies). Cost: $9/mo. Alternative: skip for v1 and add when there's traffic worth analyzing. **Sarah's call.**

5. **Contact email.** When the production domain exists, set up `hello@[domain]` (or similar) and forward to Sarah's personal email. **Defer to domain setup.**

6. **Twilio approval timeline.** Affects whether SMS launches with v1 or in a v1.1 follow-up. **External dependency, not actionable.**

7. **Social-share image (Open Graph).** A simple image for when Perihelion gets shared on social platforms. Could be the wordmark on cream paper at 1200×630px. ~20 minutes of design work. Not blocking; do during Pre-Launch week -1 if convenient.

---

## Structured GTM Data

```yaml
gtm:
  launch_date: "TBD — gated on production domain decision (see Open Question 1)"
  total_timeline: "7 weeks from production-ready to ongoing operations"

  phases:
    - name: "Pre-Launch"
      duration: "2 weeks"
      start: "Day -14"
      key_actions:
        - "Acquire production domain + DNS + SSL + hosting"
        - "Migrate WP install to production"
        - "Author /why and /privacy and /contact copy"
        - "Configure SEO plugin + analytics"
        - "Polish share-token landing experience (Tactic A)"
        - "Onboard 3-5 close friends as first organizers"
      success_signal: "Site live at production domain; 3-5 organizers exist with 1+ activity each; share-token loop feels good to walk through cold"

    - name: "Soft Launch"
      duration: "2 weeks"
      start: "Day 1"
      key_actions:
        - "Sarah uses Perihelion for actual friend coordination"
        - "Expand to 5-10 second-tier friends via 1:1 outreach"
        - "Watch share-token conversion rate"
        - "Write one quiet 'here's what I've been doing' personal-channel post (week 2)"
      success_signal: "8-15 active organizers, 70%+ share-token conversion from warm referrals, Sarah personally feels the product is useful in her life"

    - name: "Amplification"
      duration: "3 weeks"
      start: "Day 15"
      key_actions:
        - "Week 3: Author thoughtful READMEs on both repos (Tactic E)"
        - "Week 4: Single Show HN post (Tactic B). Tuesday/Wednesday ~9-10 AM Pacific"
        - "Week 5: Opportunistic mentions in 1-2 adjacent communities (Tactic C)"
      success_signal: "25-50 active organizers, 1-2 friend-group clusters beyond Sarah's immediate circle, HN outcome metabolized"

    - name: "Sustain"
      duration: "ongoing"
      start: "Day 36"
      key_actions:
        - "Settle into marketing plan's 'no schedule' cadence"
        - "Week 8: post-launch review (~30 minutes)"
        - "Default decision after review: change nothing for 3 months"
        - "Quarterly KPI review thereafter"
      success_signal: "Slow word-of-mouth growth; site ranks gradually for long-tail SEO; Sarah continues using product personally"

  readiness:
    blocking:
      - "Production domain decision and registration"
      - "Hosting provisioned"
      - "Marketing-page copy authored (especially /why)"
    non_blocking:
      - "Twilio approval (SMS path can launch in v1.1)"
      - "Plugin folder rename orbit/ -> perihelion/"
      - "Favicon / social-share image"
      - "README polish on both repos"
      - "SEO plugin (can add in week -1 or week 1)"
      - "Analytics tool (can add later)"

  launch_metrics:
    targets:
      week_2:
        active_organizers: "8-15"
        share_token_conversion_pct: "70+"
      week_5:
        active_organizers: "25-50"
        distinct_clusters: "2-4"
        share_token_conversion_pct: "60-75"
      week_8:
        active_organizers: "25-50"
        activities_posted_total: "50-150"
        post_launch_review: "completed"
    review_date: "Day 56 (week 8) — 30-minute review, default decision is change nothing"

  contingency_drop_order:
    - "First: community mentions (Tactic C)"
    - "Second: HN post (Tactic B)"
    - "Third: READMEs (Tactic E)"
    - "Last (non-negotiable): Sarah using the product personally (Tactic D)"
```
