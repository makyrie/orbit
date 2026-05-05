# Perihelion — Brand Brief

Phase 1 deliverable from the website engagement. Produced from a structured
interview with Sarah Lewis on 2026-05-05. The prose section is the working
brand brief; the YAML block at the end is for downstream skills (Creative
Director, Content Architect, etc.) to consume.

## What Perihelion Is

Perihelion is a low-friction way to spend more real-life time with the friends you already have — and to deepen friendships with acquaintances you've recently met — without the social tax of one-off text invitations or group-chat awkwardness. It works by inverting the default: invitees opt in to subscriptions with the organizers they want to hear from, and can control how (and whether) those invitations reach them. Three commitment tiers ("just an idea," "I'll go if you will," "I'm going — join me") let casual hangs travel as casually as the invitation suggests. The product wants you offline and in the room with your people; the success metric is *more friend hangs actually happening*, not engagement.

## Founder Relationship

Sarah Lewis built Perihelion to scratch her own itch as a high-frequency organizer whose friends couldn't always match her cadence. She's the maker, not the long-term face — Perihelion is intended to stand on its own and could plausibly be handed off (open-source, non-commercial) without losing its character. The Orbit/Perihelion split inside the codebase is engineering inertia, not strategic positioning; the brand should not lean on it as a story.

## Audience

The primary audience is the **high-frequency organizer** — the person in any given friend group most often putting out the "anyone want to come do this thing?" invitation. Invitees are the secondary audience, but their friction tolerance is the binding design constraint: anything that requires them to sign up for a new service to stay in touch with their existing friend won't survive contact with reality, which is why the architecture leans on text/email/tokens. Perihelion serves the full spectrum of social closeness, from close friends to brand-new acquaintances ("deepening a baby friendship" is a use case as legitimate as inviting your weekly hiking group). It is explicitly **not for people looking to make new friends from scratch** — Perihelion is bring-your-own-friends.

## Promise

For the organizer: the freedom to invite without fear of being a burden, because the invitee always has the agency to opt out without it being awkward. For the invitee: control over what they hear, when, and through which channel. The functional outcome is *more in-person time with the people you already want to see*; the emotional outcome is the **redistribution of social agency** that asymmetric invitation patterns currently concentrate in the organizer. Perihelion is an ongoing utility, not a behavior-change project — its social shift operates *inside the system*, not as a permanent rewiring of how people relate to coordination at large.

## Voice & Tone

Perihelion sounds like a fellow learner — calm, warm in feel but minimal in word count, with humor that's quietly observational rather than performative. The visual language carries the warmth (color, type, palette); the copy stays out of the way. **Anti-extractive is a stated value, not a vibe**: no salesiness, no engagement loops, no captive-audience energy. Perihelion would never sound salesy, hypey, performatively wholesome, twee, or productivity-tool-stern. The brand has a sense of humor, but it never interrupts what you came there to do.

## Positioning

Perihelion is a friend-coordination *layer above* discovery platforms (Meetup, Partiful, Evite, Facebook Events) rather than a competitor to them — it solves *who from my friends wants to come with me?*, not *what's happening?*. It's built for **casual coordination** ("I'm going to the museum, who wants to come?"), not Big Productions like a birthday party with twenty RSVPs to track. It competes on **values**: anti-extractive, agency-redistributing, get-offline. The closest spiritual analogues are not in software at all: **REI**, a company whose job is to get you outside rather than absorbed into them, and **Taproot Magazine**, which is slow, intentional, low-ad, calm. The longest possible articulation of the position: *a really helpful utility built by someone who wants you to use it and then close the tab.*

## Direction

Twelve-month picture: a few hundred organizers using Perihelion regularly for small casual gatherings, growing by word of mouth, beloved without being burdensome. Explicit ceilings: no mass scale, no team of human moderators (AI-assisted moderation is on the table for safety levers), no full-time-job energy required to maintain. The failure mode is anything that turns the product into a tool for harassment, exclusion, or unhealthy behavior — the deliberate absence of DM/chat features is a structural defense, not a missing roadmap item. The product is the product; no spinoffs, courses, or ongoing content planned. A single permanent manifesto page celebrating "the joy of connecting with near strangers and the joy of making friends" is on the table for the Content Architecture phase, but not committed.

---

## Structured Brand Data

```yaml
brand:
  name: "Perihelion"
  tagline: "" # not committed; nearest natural candidates from interview: "more time with the friends you already have, without the friction" or "bring your own friends." Defer to Content Architect.
  founder:
    name: "Sarah Lewis"
    role_in_brand: "Maker, not long-term face. Brand should stand on its own; plausibly handoff-able to a non-commercial steward."

  audience:
    primary: "High-frequency organizers — the person in any friend group most often putting out invitations to casual gatherings."
    secondary: "Invitees (friends and recent acquaintances of the organizer). Design constraint, not target."
    not_for: "People looking to make new friends from scratch. Perihelion is bring-your-own-friends, not a discovery platform."
    entry_trigger: "Repeated awkwardness inviting friends or acquaintances via one-off text messages or group chats; an asymmetric invitation pattern in which the organizer feels like a burden and the invitee feels pressured."

  promise:
    functional: "Throw out invitations to your whole network without it being weird, and have more in-person hangs actually happen as a result."
    emotional: "Agency redistribution — organizer no longer feels like a burden; invitee gains control over what they hear and how. Both sides gain breathing room."
    type: "ongoing resource"

  voice:
    tone_words: ["calm", "warm", "minimal", "wry", "fellow-learner"]
    register: "Fellow learner; warm wrapper around precise mechanics"
    avoids:
      - "salesy / hypey"
      - "performatively wholesome"
      - "twee"
      - "productivity-tool-stern"
      - "extractive (engagement-loop, captive-audience)"
    humor: "Quietly observational. Present but never interrupts the utility."

  positioning:
    competitors:
      - "Group texts (the dominant default Perihelion is replacing)"
      - "Partiful (direct UX comparison)"
      - "Evite (direct UX comparison; geared toward Big Productions)"
      - "Geneva (adjacent; excluded by BYOF)"
      - "Meetup (adjacent; excluded by BYOF; potential long-term integration target)"
      - "Facebook Events (adjacent; potential long-term integration target)"
      - "Bumble BFF (adjacent; suffers the asymmetric-DM problem Perihelion is built to solve)"
    gap: "Casual, low-stakes coordination among existing friends, where declining is the structural default and no engagement loop attempts to recapture attention."
    differentiator: "Bring-your-own-friends + declining-as-default + a values-led anti-extractive posture."
    competes_on: "values"

  inspiration:
    soul_references:
      - name: "REI"
        why: "A company whose job is to get you outside doing stuff, not absorbed into them."
      - name: "Taproot Magazine"
        why: "Slow, intentional, low-ad, calm."
    voice_references: []  # not yet identified beyond the soul references; Creative Director phase to expand.

  direction:
    one_year_goal: "A few hundred organizers using Perihelion regularly for casual gatherings, growing via word of mouth, beloved without being burdensome."
    planned_offerings: []  # the product is the product; possible permanent manifesto page TBD in Content Architecture.
    scale_ceiling: "No mass scale; no human moderation team (AI-assisted moderation acceptable); no full-time-job energy required to maintain."
    failure_signal: "Becoming a tool for harassment, exclusion, or unhealthy behavior. Commercialization that becomes 'yucky.' Scope creep into messaging or social-network territory."

  channels:
    current:
      - "The Perihelion app/site itself"
      - "Word of mouth via existing organizers"
    planned:
      - "Possible discovery-platform integrations (e.g., posting 'I'm going to this Meetup, who wants to come?' with a link)"
      - "Possible permanent manifesto page on the marketing site"
```

## Open Questions

These are areas where the interview surfaced a real question without a final answer. Downstream skills (Creative Director, Content Architect, Theme Builder) should treat them as decisions to make, not assumptions to inherit.

1. **Tagline.** Nothing committed. The closest natural candidates from the interview were "more time with the friends you already have, without the friction" and "bring your own friends." Likely landing in the **Content Architect** or **Creative Director** phase once the broader copy direction is set.

2. **Manifesto page.** Sarah is roughly 60/40 in favor of having a single permanent values/manifesto page on the marketing site (celebrating "the joy of connecting with near strangers and the joy of making friends"), but explicitly does not want an ongoing blog or magazine. Decision belongs to the **Content Architect** phase.

3. **Communication scope inside the product.** Sarah's strong instinct is no DMs and no in-product chat — she named the *absence* of communication features as a structural defense against the worst failure mode (harassment). Open question: are there any *limited* communication primitives that could exist without breaking that posture (e.g., RSVP-attached comments, host-to-attendees broadcast)? Belongs to product roadmap discussions, but worth flagging in **Content Architecture** in case it affects sitemap.

4. **AI moderation surface.** Sarah is open to automated/AI moderation (sentiment analysis, automated flagging) in lieu of a human review team. Open: where in the product this lives, how it surfaces to users (transparency? trust signal?), and whether it's mentioned in marketing copy as a deliberate stance. Belongs to **Content Architecture** and **Theme Builder**.

5. **Persona name for the primary audience.** "High-frequency organizer" is descriptive but clinical. The primary audience deserves a sharper, more humane label — something the user themselves would self-identify with. Belongs to **Content Architect** or **Creative Director**.

6. **Voice references beyond soul references.** REI and Taproot Magazine are excellent value/soul references, but no concrete *voice* references (specific copy that sounds like Perihelion should sound) have been identified. **Creative Director** phase should surface candidates.
