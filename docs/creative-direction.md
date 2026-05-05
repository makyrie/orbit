# Perihelion — Creative Direction

Phase 2 deliverable from the website engagement. Translates `docs/brand-brief.md`
into a specific, opinionated visual direction. Rendered demonstration lives at
[`docs/style-tile.html`](./style-tile.html).

## Interpretation

The brand serves people who want to spend MORE time offline with friends, so the design's job is to be useful and then step out of the way. The REI reference establishes earthy, outdoor-adjacent warmth; Taproot Magazine establishes editorial calm and generous whitespace. The product itself is precise (three commitment tiers, structured privacy), so the design should carry that crafted clarity in a *warm* register — not minimal-tech, not maximalist-craft. The aesthetic territory: **warm-toned, editorially calm, tactile, confident-but-quiet**. Like a well-loved field notebook, or the magazine you bring on a hike: informative, attractive enough to pick up, but not the point of the trip.

## Design Principles

**1. Quiet by design.** Hierarchy comes from spatial relationships and typographic weight, not from color saturation or visual noise. The interface never demands attention.

**2. Warm not bright.** Color provides the friendliness the copy intentionally withholds. Earthy, low-saturation tones — the warmth of paper, terracotta, late-afternoon light. Never neon, never primary, never "tech blue."

**3. Editorial pacing.** Generous whitespace. Generous line-heights. Deliberate spacing. The page feels like a magazine spread, not an app screen — even when it *is* an app screen.

**4. Tactile, not flat.** Subtle materiality: cream-paper background, gentle shadows, ink-rendered type. The design signals "made by someone" rather than "served by infrastructure." Never skeuomorphic.

**5. Built to be left.** CTAs are clear but not loud. No notification badges, no urgency cues, no *"3 friends are going!!"* alerts. The visual language enforces the brand promise: this is something you use, then close.

## Color

| Name | Hex | Role |
|---|---|---|
| **Sienna** | `#B85D3D` | Primary. Warm earth tone. Buttons, links, accent strokes. |
| **Ink** | `#2A2A28` | Body text, headings. Slightly warm — not pure black. |
| **Sage** | `#9DA67E` | Secondary. Muted UI elements, status badges, calm callouts. |
| **Paper** | `#F7F3ED` | Background. Cream paper warmth — never pure white. |
| **Slate** | `#5A5A55` | Muted text, captions, secondary UI. |
| **Honey** | `#D69A3C` | Active/notification accent. Used SPARINGLY. |

The palette is rooted in the REI/outdoors register — terracotta, sage, paper, ink — without being literally an outdoor brand. It's warm enough to feel inviting, restrained enough not to shout. Honey is the one "alert" color, used only at moments of genuine functional importance (an unread invitation, the single primary CTA on a page).

**Off-limits:** pure black (clinical), pure white (sterile, breaks the paper foundation), SaaS-blue or any saturated primary, any red (suggests urgency, which the brand explicitly opposes), neon anything (opposite of anti-extractive).

## Typography

- **Headings: Fraunces** (Google Fonts). A variable serif with personality — warmth and authority both. Slight playfulness in the lower-case `g` and `e`. Not corporate, not whimsical.
- **Body and UI: Inter** (Google Fonts). Calm, neutral, highly legible at small sizes. The counterweight to Fraunces.

Type scale on a 1.25 (major third) modular scale: body 16px, caption 14px, h3 20px, h2 28px, h1 40px, display 56px. Line-heights generous — body 1.65, headings 1.2.

Together the pair signals **considered editorial calm**: Fraunces says *this is crafted*, Inter says *and it's not getting in your way*.

**What to avoid:** Inter alone (too SaaS-default), geometric sans (too cold/startup), heavy display serifs for body (would slow reading), more than two type families (clutter).

## Texture and Materiality

The design feels like **uncoated paper**. Subtle warmth in the background — never slick. Surfaces have *gentle* depth via soft shadows (1–2px y-offset, low blur, low opacity), never the heavier card-shadow tradition.

Optional refinement: a very subtle SVG noise overlay on the body background (5–10% opacity) to give the cream a touch of physicality. The style tile demonstrates this. It can be skipped during theme build if it complicates rendering — the cream is doing most of the work either way.

Type renders at full weight, no anti-aliasing tricks — gives it ink-on-paper quality.

## Layout and Spacing

Editorial. Single content column on text-heavy pages, max-width 640–720px for reading. UI screens (dashboard, manage activities) get more width but stay airy — no dense data-table feeling.

Whitespace is generous. Section padding large (4–6rem on desktop). The page is not in a hurry.

Asymmetric layouts where useful (hero with text on left, simple list on right), but never busy. Most pages center their content.

## Imagery Direction

**Photography**: muted, candid, warm. Friends in real environments — coffee shops, hiking trails, living rooms — never staged "diverse group laughing in office" stock. Color-graded toward the cream/terracotta palette: warm shadows, slight desaturation.

**Illustration**: minimal use. If used: line-based, hand-drawn quality, in Sienna or Ink. Could do small line icons for navigation accents.

**Don'ts**: no isometric scenes, no rainbow gradient blobs, no 3D phones-with-app-screenshot, no glassmorphism, no hero animations, no AI-generated imagery in a "look how shiny" register.

## Design Don'ts

1. **No dark mode as default.** The cream-paper foundation IS the brand. Dark mode allowed as opt-in, but the canonical experience is light, warm, on-paper.
2. **No fluorescent or saturated CTAs.** The only "loud" color is Honey, used sparingly. Buttons feel like ink, not LED.
3. **No notification dots, urgency cues, or "Limited time!" framing.** Anti-extractive means no engagement-trap visuals.
4. **No SaaS-startup tropes.** No isometric illustrations. No animated gradient heroes. No glassmorphic cards. No "Get started in 60 seconds" landing-page tropes.
5. **No icons-as-mystery-meat.** Use words. Icons appear *with* labels, never as silent navigation. Iconography is sparse and purposeful.

---

## Presentation

The direction goes for **editorial calm with earthy warmth** — like a well-loved field notebook rendered as a website. The single most important design decision is the **cream-paper background with low-saturation earth tones**: it makes Perihelion read as *considered and human-made* rather than *served by a server*, which is the visual translation of the anti-extractive value. Everything else (typography pair, spacing personality, the absence of urgency cues) follows from that foundation.

The mood: a calm afternoon. Soft. Warm. Honest about what it is and what it isn't.

## Open Questions for Downstream Phases

1. **Noise/texture overlay yes-or-no.** The cream-paper foundation works without the subtle SVG noise; the overlay adds physicality at a cost of build complexity. The style tile demonstrates it on. Defer to **theme-builder** to evaluate the cost vs. visual payoff once the theme is being assembled.

2. **Honey accent usage rules.** Honey (`#D69A3C`) is reserved for sparing use, but the exact rule isn't fully formalized. Candidates: only on the single primary CTA per page; only on unread/active states; both. Defer to **design-system-generator** to decide and document as a token-usage rule.

3. **Tagline lockup.** The style tile uses *"More time with the friends you already have"* as a working tagline placeholder. The brand brief explicitly leaves the tagline to Content Architect / Creative Director — this is a candidate, not a commitment. The visual treatment (Fraunces italic at display size, sitting under the wordmark) is the part that's locked in; the words can change.
