# Perihelion — Design System

Phase 4 deliverable from the website engagement. Reads from
`docs/creative-direction.md` (with style tile at `docs/style-tile.html`)
and `docs/content-architecture.md`. Hands off to `theme-builder` (Phase 5).

This document is the **buildable spec**: every value precise enough to
drop into `theme.json` or a CSS custom property without further
interpretation.

## One Engineering Adjustment to the Creative Direction

The creative-direction Sienna (`#B85D3D`) yields a ~4.06:1 contrast ratio on Paper (`#F7F3ED`), which fails WCAG AA for normal-size text and is borderline even for buttons. The fix with the smallest aesthetic ripple is to promote the existing `--color-sienna-hover` (`#9C4B30`) to be the canonical Sienna — it sits at ~5.38:1 on Paper, clears WCAG AA universally, and is visually nearly identical (both are warm earth-tone terracottas). Hover state moves one step darker.

This is the only token change from the creative direction. Flagging in Open Questions for Sarah to confirm or revert.

---

## Step 1 — Design Tokens

### Color

```yaml
color:
  palette:
    - name: "Sienna"
      slug: "sienna"
      color: "#9C4B30"  # was #B85D3D in creative direction; darkened for AA
      usage: "Primary brand color. Buttons, links (underlined), section accents, tier badges, headings (when sienna-tinted)."
    - name: "Sienna Hover"
      slug: "sienna-hover"
      color: "#803D26"
      usage: "Hover/active state for sienna interactive elements. Not used as a standalone palette color."
    - name: "Ink"
      slug: "ink"
      color: "#2A2A28"
      usage: "Body text, headings, inline link text. Slightly warm — not pure black."
    - name: "Slate"
      slug: "slate"
      color: "#5A5A55"
      usage: "Muted text, captions, secondary UI labels."
    - name: "Sage"
      slug: "sage"
      color: "#9DA67E"
      usage: "DECORATIVE ONLY — backgrounds, badges, dividers. Fails WCAG AA for text on Paper, never use for typography."
    - name: "Honey"
      slug: "honey"
      color: "#D69A3C"
      usage: "DECORATIVE ONLY — actionable workflow indicators (pending count badge), 'going' tier badge fills. Never for text on Paper."
    - name: "Paper"
      slug: "paper"
      color: "#F7F3ED"
      usage: "Default page background. Cream paper warmth — never pure white."
    - name: "Paper Warm"
      slug: "paper-warm"
      color: "#F1ECE2"
      usage: "Slightly darker surface for cards, inset blocks, hover-fills on neutral elements."
    - name: "Rule"
      slug: "rule"
      color: "rgba(156, 75, 48, 0.15)"
      usage: "Hairline dividers, section underlines, subtle borders. Sienna-tinted, low alpha."

  semantic:
    text_primary:        "var(--wp--preset--color--ink)"
    text_secondary:      "var(--wp--preset--color--slate)"
    text_inverse:        "var(--wp--preset--color--paper)"
    background_primary:  "var(--wp--preset--color--paper)"
    background_surface:  "var(--wp--preset--color--paper-warm)"
    background_inverse:  "var(--wp--preset--color--ink)"
    border_default:      "var(--wp--preset--color--rule)"
    link:                "var(--wp--preset--color--ink)"  # ink with sienna underline
    link_underline:      "var(--wp--preset--color--sienna)"
    accent:              "var(--wp--preset--color--sienna)"
    button_bg:           "var(--wp--preset--color--sienna)"
    button_bg_hover:     "var(--wp--preset--color--sienna-hover)"
    button_text:         "var(--wp--preset--color--paper)"

  contrast_checks:
    - pair: "Ink on Paper"
      ratio: "12.80:1"
      wcag_aa_normal: true
      wcag_aaa_normal: true
    - pair: "Sienna on Paper"
      ratio: "5.38:1"
      wcag_aa_normal: true
      wcag_aaa_normal: false
    - pair: "Slate on Paper"
      ratio: "6.22:1"
      wcag_aa_normal: true
      wcag_aaa_normal: false
    - pair: "Paper on Sienna (button text)"
      ratio: "5.38:1"
      wcag_aa_normal: true
      wcag_aaa_normal: false
    - pair: "Sage on Paper"
      ratio: "2.29:1"
      wcag_aa_normal: false
      note: "Decorative use only — never for text"
    - pair: "Honey on Paper"
      ratio: "2.20:1"
      wcag_aa_normal: false
      note: "Decorative use only — never for text"
```

**Honey usage rule (resolves Phase 2 Open Question):**

Honey appears in exactly two places in the system, both of them visual fills (never text):

1. The actionable workflow indicator in the app header — a small filled circle badge next to the "Subscribers" nav link when there are pending subscribers. Number rendered inside the badge is in **Ink** for contrast (12.8:1).
2. The "I'm going" tier badge background fill on activity cards — paired with **Ink** text for the tier label inside it.

That's it. Honey does not appear in CTA buttons, in regular notifications, or anywhere else.

### Typography

```yaml
typography:
  font_families:
    - name: "Heading"
      slug: "heading"
      fontFamily: "'Fraunces', Georgia, 'Times New Roman', serif"
      google_fonts_url: "https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,400;1,9..144,500&display=swap"
      weights_used: [400, 500, 600, 700]
      italic: true
    - name: "Body"
      slug: "body"
      fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif"
      google_fonts_url: "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap"
      weights_used: [400, 500, 600]
      italic: false
    - name: "Mono"
      slug: "mono"
      fontFamily: "'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', monospace"
      google_fonts_url: ""
      weights_used: [400]
      italic: false
      note: "System mono only; used for hex codes, code samples. Never for body text."

  font_sizes:
    - name: "Caption"
      slug: "caption"
      size: "0.875rem"   # 14px
      fluid: false
    - name: "Body"
      slug: "body"
      size: "1rem"       # 16px
      fluid: false
    - name: "Lead"
      slug: "lead"
      size: "1.1875rem"  # 19px
      fluid:
        min: "1.0625rem"
        max: "1.1875rem"
    - name: "Heading 3"
      slug: "h3"
      size: "1.25rem"    # 20px
      fluid:
        min: "1.125rem"
        max: "1.25rem"
    - name: "Heading 2"
      slug: "h2"
      size: "1.75rem"    # 28px
      fluid:
        min: "1.5rem"
        max: "1.75rem"
    - name: "Heading 1"
      slug: "h1"
      size: "2.5rem"     # 40px
      fluid:
        min: "2rem"
        max: "2.5rem"
    - name: "Display"
      slug: "display"
      size: "3.75rem"    # 60px
      fluid:
        min: "2.5rem"
        max: "3.75rem"

  line_heights:
    body: "1.65"
    lead: "1.55"
    heading: "1.2"
    tight: "1.1"     # display sizes
    ui: "1.4"        # buttons, badges, compact UI

  letter_spacing:
    body: "0"
    heading: "-0.015em"
    display: "-0.02em"
    caps: "0.12em"   # uppercase section labels
    button: "0.01em"

  weights:
    regular: "400"
    medium: "500"
    semibold: "600"
    bold: "700"

  defaults:
    body_size: "var(--wp--preset--font-size--body)"
    body_family: "var(--wp--preset--font-family--body)"
    body_line_height: "1.65"
    heading_family: "var(--wp--preset--font-family--heading)"
    heading_weight: "500"
    heading_letter_spacing: "-0.015em"
```

### Spacing

```yaml
spacing:
  scale:
    - name: "2xs"
      slug: "20"
      size: "0.25rem"   # 4px
    - name: "xs"
      slug: "30"
      size: "0.5rem"    # 8px
    - name: "s"
      slug: "40"
      size: "1rem"      # 16px
    - name: "m"
      slug: "50"
      size: "1.5rem"    # 24px
    - name: "l"
      slug: "60"
      size: "2.5rem"    # 40px
    - name: "xl"
      slug: "70"
      size: "4rem"      # 64px
    - name: "2xl"
      slug: "80"
      size: "6rem"      # 96px

  layout:
    content_size: "720px"   # narrow editorial reading column (page.html, page-app inner content)
    wide_size:    "1080px"  # full app layout container, hero blocks
    full_bleed:   "100%"    # hero backgrounds, footer, full-width separators

  responsive_overrides:
    # Below 768px, section padding compresses by one step on the scale
    mobile:
      "80": "4rem"  # 2xl drops to xl spacing
      "70": "3rem"  # xl drops between l and xl
      "60": "2rem"  # l drops slightly
```

### Surface

```yaml
surface:
  border_radius:
    sm: "4px"     # tier badges, small UI labels
    md: "8px"     # buttons, inputs, swatches
    lg: "12px"    # cards
  shadow:
    soft:  "0 1px 3px rgba(42, 42, 40, 0.04), 0 2px 8px rgba(42, 42, 40, 0.04)"
    lift:  "0 2px 6px rgba(42, 42, 40, 0.06), 0 8px 20px rgba(42, 42, 40, 0.06)"
    inset: "inset 0 0 0 1px var(--wp--preset--color--rule)"
  border:
    hairline: "1px solid var(--wp--preset--color--rule)"
  background_texture:
    enabled: true
    css: "background-image: url(\"data:image/svg+xml;utf8,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' seed='5'/%3E%3CfeColorMatrix values='0 0 0 0 0.16 0 0 0 0 0.16 0 0 0 0 0.13 0 0 0 0.045 0'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)'/%3E%3C/svg%3E\");"
    note: "Optional per the creative direction's Open Question. Apply to body background only. ~3KB inline."
  transition:
    fast:    "0.15s ease"   # color/background fades
    default: "0.18s ease"   # default for interactive elements (matches style tile)
    slow:    "0.3s ease"    # opacity fades on dismissable messages
```

---

## Step 2 — Component Specs

### Button — Primary
- **Used in:** Hero CTA, form submits, "I'm in" / "Subscribe to {name}" actions
- **Background:** `--wp--preset--color--sienna` → `--wp--preset--color--sienna-hover` on hover
- **Text:** `--wp--preset--color--paper`, body font, body size, weight 500, letter-spacing `0.01em`
- **Padding:** `0.55em 1.15em` (vertical/horizontal)
- **Border radius:** `--surface--border-radius--md` (8px)
- **Border:** none
- **Transition:** `--surface--transition--fast` on background-color
- **States:**
  - `default`: as above
  - `hover`: background → sienna-hover
  - `focus-visible`: 2px sienna-hover outline at 2px offset
  - `active`: brief downward 1px translate
  - `disabled`: 50% opacity, no pointer
- **Mobile:** unchanged

### Button — Ghost
- **Used in:** "Maybe" responses, secondary CTAs ("View profile"), "Use a different number"
- **Background:** transparent
- **Text:** `--wp--preset--color--sienna`, body font/size, weight 500
- **Border:** `1px solid --wp--preset--color--sienna`
- **Padding/radius/transition:** identical to primary
- **Hover:** background fills to sienna, text flips to paper
- **Focus-visible:** same outline as primary

### Button — Small
- **Modifier on either above:** `font-size: --wp--preset--font-size--caption (14px)`, `padding: 0.4em 0.9em`
- **Used in:** Card footer "I'm in", inline actions, "Resend" links

### Activity Card
- **Used in:** Dashboard, Manage, Profile (single-poster activity list)
- **Background:** `--wp--preset--color--paper-warm` (slight surface lift from page bg)
- **Border:** `--surface--border--hairline`
- **Border radius:** `--surface--border-radius--lg` (12px)
- **Padding:** `--wp--preset--spacing--50` (24px) on all sides
- **Shadow:** `--surface--shadow--soft` default; `--surface--shadow--lift` on hover
- **Hover:** translate Y `-2px`, shadow → lift, transition `--surface--transition--default`
- **Internal layout:**
  - `.card__poster` — caption size, slate text, letter-spacing 0.02em, margin-bottom 2xs
  - `.card__title` — h3 size, heading font, weight 500, ink text, line-height 1.3, margin-bottom xs
  - `.card__tier` — see Tier Badge spec
  - `.card__details` — vertical stack of caption-size lines, slate text, ink for `<strong>` time/date
  - `.card__description` — body size, line-height 1.6, ink text
  - `.card__footer` — flex row, justify-content: space-between, padding-top xs, border-top hairline; left side count (caption, slate), right side small button
- **Mobile:** unchanged structure; padding reduces to `--wp--preset--spacing--40` (16px) below 480px

### Tier Badge
- **Used in:** Activity cards, activity detail page header
- **Display:** inline-block, `padding: 0.25em 0.75em`, `border-radius: 4px`, `font-size: caption`, `letter-spacing: 0.02em`, weight 500
- **Three variants** (matches the three commitment tiers from the brand brief):
  - `tier--idea` (Just an idea) — bg: `rgba(157, 166, 126, 0.18)` (Sage 18%), text: `#5C6845` (darker sage for contrast)
  - `tier--maybe` (I'll go if you will) — bg: `rgba(156, 75, 48, 0.14)` (Sienna 14%), text: `--wp--preset--color--sienna`
  - `tier--going` (I'm going — join me) — bg: `rgba(214, 154, 60, 0.18)` (Honey 18%), text: `#8B6420` (darker honey for contrast)

### Actionable Workflow Indicator
- **Used in:** App header, next to the "Subscribers" nav link when pending count > 0
- **Display:** inline-flex, gap 0.4em with the nav label
- **The badge:** circular, `min-width: 1.4em`, `height: 1.4em`, padding `0 0.4em`, border-radius full, bg `--wp--preset--color--honey`, text `--wp--preset--color--ink` (Ink on Honey = 5.8:1, AA pass), font-size caption, weight 600, line-height 1, centered text
- **Animation:** none. Never pulses, never bounces, never grows on update. Per the design carve-out: "visually restrained — small, paired with the affected element, no pulsing or animation."
- **Empty state (count = 0):** badge is not rendered. No empty state at all.
- **Mobile:** unchanged

### Form Input
- **Used in:** All forms (new activity, edit activity, profile, subscribe, settings, phone verification, etc.)
- **Container:** `.orbit-form-group` with vertical stack — label, input, optional help text
- **Label:** body font, body size, weight 500, ink text, margin-bottom xs
- **Input/textarea/select:**
  - Background: `--wp--preset--color--paper`
  - Border: `1px solid --wp--preset--color--rule`
  - Border-radius: `--surface--border-radius--md` (8px)
  - Padding: `0.5em 0.65em`
  - Font: body, body size, ink text
  - Focus: border-color `--wp--preset--color--sienna`, no glow
- **Help text:** `.orbit-help` — caption size, slate text, margin-top 2xs
- **Error state:** border `--wp--preset--color--sienna` (note: NOT red — sienna conveys "needs attention" within the brand palette without breaking the no-red rule)
- **Mobile:** input padding bumps slightly for touch comfort (`0.6em 0.7em`)

### Section Heading (within content)
- **Used in:** Page sections within front-page, page templates, manifesto page
- **Structure:** uppercase eyebrow label + heading + thin sienna underline
- **Eyebrow (`.section__label`):** body font, caption size, weight 500, slate text, uppercase, letter-spacing `0.12em`, margin-bottom xs
- **Heading (`.section__heading`):** heading font, h2 size, weight 500, ink text, line-height 1.2, padding-bottom s, border-bottom 1px solid `--wp--preset--color--rule`, margin-bottom l

### Pull Quote / Blockquote
- **Used in:** Manifesto page, long-form content
- **Container:** padding-left m (24px), border-left `2px solid --wp--preset--color--sienna`
- **Text:** heading font, italic, h3 size (20px), line-height 1.5, ink text
- **Max-width:** 32em (keeps editorial column proportions)

### Hero (Front Page)
- **Used in:** front-page only (one per page)
- **Container:** content-size width, centered, padding-block 2xl
- **Wordmark:** display size (60px responsive), heading font, weight 500, letter-spacing `-0.02em`, line-height 1, ink text, margin-bottom s
- **Tagline:** heading font, italic, lead size, weight 400, slate text, line-height 1.4, max-width 30em, margin-bottom l
- **One-sentence what:** lead size, body font, slate text, max-width 36em, margin-bottom l
- **Primary CTA:** primary button, sized normally
- **Below 768px:** wordmark fluid drops to ~2.5rem; vertical padding compresses to xl

### Site Header — Marketing
- **Used in:** front-page, page (default), 404, search
- **Container:** wide-size width, padding-block m, padding-inline m, hairline border-bottom
- **Layout:** flex row, justify-content: space-between, align-items: center
- **Left:** Wordmark "Perihelion" — heading font, h3 size, weight 500, ink text. Links to `/`.
- **Right:** Single nav link "Sign in" (when logged out) OR "Dashboard" (when logged in). Body font, body size, ink text, sienna underline on hover.
- **Mobile:** unchanged structure (already minimal)

### Site Header — App
- **Used in:** page-app and all dynamic plugin routes
- **Container:** same as marketing header
- **Left:** Wordmark linking to `/dashboard`
- **Right:** Horizontal nav of app pages — Dashboard, Subscriptions, Settings (all users); Manage, Subscribers, New Activity (posters only). Each nav item is body font, body size, ink text. Active item gets a sienna underline. Hover gets sienna underline at low opacity.
- **Pending indicator:** lives next to "Subscribers" nav item when count > 0 (see Actionable Workflow Indicator spec)
- **Mobile:** below 768px, nav collapses to a "Menu" button that toggles a vertical stack below the header. No hamburger icon — use the word "Menu". Word + small chevron when expanded.

### Site Footer — Full
- **Used in:** Marketing pages (front-page, page, 404, search)
- **Container:** full-bleed, padding-block xl, background paper-warm, hairline top border
- **Inner:** wide-size, two-column on desktop (attribution left, links right), single column below 768px
- **Attribution:** body size, slate text. "Perihelion is a really helpful utility built by [Sarah Lewis](mailto:…). Open source, non-commercial."
- **Links:** small vertical list — Why this exists, Privacy, Contact, GitHub
- **No newsletter signup, no social icons.**

### Site Footer — Minimal
- **Used in:** App pages (page-app)
- **Container:** padding-block m, no top border (the page itself has enough hairlines)
- **Inner:** caption size, slate text, centered. "Perihelion · [Sign out](…)"

### Notice / Message
- **Used in:** Form validation, success confirmations, system warnings (e.g., "SMS not currently available")
- **Container:** padding `0.75em 1em`, border-radius md, margin-block s, body size
- **Variants:**
  - `notice--success` — bg: `rgba(157, 166, 126, 0.18)` (Sage 18%), text: ink, optional check icon
  - `notice--error` — bg: `rgba(156, 75, 48, 0.12)` (Sienna 12%), text: ink, border-left `3px solid sienna`
  - `notice--warning` — bg: `rgba(214, 154, 60, 0.15)` (Honey 15%), text: ink, border-left `3px solid honey`
- **No system colors (red/yellow/green) — entirely within brand palette.**

---

## Step 3 — Layout Annotations

### Template: `templates/front-page.html`

**Layout type:** Constrained, centered, single column with one full-bleed accent block at the bottom.

**Sections (top to bottom):**
1. **Hero** — content-size width, centered. Vertical padding: `--wp--preset--spacing--80` (96px). Contains: wordmark (display), tagline (italic lead), one-sentence what (lead), primary CTA. Spacing below section: `--wp--preset--spacing--80`.
2. **Audience mirror** — content-size width. Section heading "Who this is for". Body paragraph addressing the user as "you" with the persona-by-behavior framing ("If you're the friend who plans things…"). Spacing below: `--wp--preset--spacing--70` (64px).
3. **How it works** — content-size width. Section heading "How it works". Three numbered steps stacked vertically (NOT a 3-column grid — single column reinforces the editorial pacing). Each step: small circular number badge (sienna fill, paper text), heading-font h3 step title, body description. Spacing below: `--wp--preset--spacing--80`.
4. **What's different** — content-size width. Section heading "Why this is different from the rest". Three short paragraphs covering: BYOF, anti-extractive (the get-offline ethic), three-tier commitment. Spacing below: `--wp--preset--spacing--80`.
5. **Closing CTA** — full-bleed background `--wp--preset--color--paper-warm` (very slight contrast lift), inner content-size centered. Single line of editorial copy + primary CTA. Padding-block: `--wp--preset--spacing--80`.

**Responsive:**
- All text fluid per the type scale
- Below 768px, section padding compresses by one scale step (80 → 70, 70 → 60)
- Hero wordmark drops to `2.5rem` minimum

### Template: `templates/page.html`

**Layout type:** Single editorial reading column. Used by Why this exists, Privacy, Contact, and any future static marketing pages.

**Sections:**
1. **Page header** — content-size width, padding-block xl. Page title in heading font, h1 size, ink text. Optional eyebrow above (uppercase slate caption). No subtitle field — let the first paragraph carry that role.
2. **Page content** — content-size width, padding-block 0 (header already supplied space). Long-form prose:
   - Body paragraphs at body size, line-height 1.65, ink text, max-width 32em
   - Headings within prose: h2 and h3 sizes from token scale, heading font, weight 500, generous top margin (l)
   - Inline links: ink text + sienna underline (`text-decoration-thickness: 2px`, `text-underline-offset: 2px`)
   - Blockquotes: per Pull Quote spec
   - Lists: standard, with `padding-left: m`, line-height 1.6, item margin-block xs
3. **Footer attribution** (manifesto page only) — narrow line below the prose, caption size, slate text, separated from prose by a hairline rule and m of space. "Built by Sarah Lewis."

**Responsive:**
- Reading column stays at content-size; on narrower viewports browser handles the natural reflow inside the 720px max
- Padding-block reduces by one scale step below 768px

### Template: `templates/page-app.html`

**Layout type:** Wider container for plugin-rendered shortcode content. Used by all 8 app pages and 3 dynamic plugin routes.

**Sections:**
1. **App header** — see Site Header — App component spec
2. **Page content area** — wide-size width (1080px), padding-block l. Inner content is the plugin shortcode, which carries its own internal layout (cards, forms, tables, etc.). The template's job is just to provide the chrome.
3. **App footer** — see Site Footer — Minimal component spec

**Responsive:**
- Wide-size container reflows to viewport width below 1100px
- App nav collapses per Site Header — App spec

### Template: `templates/404.html`

**Layout type:** Centered, narrow, friendly.

**Sections:**
1. **Marketing header** — same as front-page
2. **Centered content** — content-size, centered both axes (min-height: ~50vh):
   - Caption-size eyebrow "404"
   - Heading font, h1 size: "We can't find that one."
   - Body paragraph: "It might've been moved, mistyped, or never existed. Try heading back to the front."
   - Primary button linking to `/`
3. **Marketing footer** — same as front-page

### Template: `templates/search.html`

**Layout type:** Single editorial column, results list.

**Sections:**
1. **Marketing header**
2. **Search header** — content-size, padding-block xl. Eyebrow "Search results", heading h1 with the query, e.g., "Results for 'subscribe'". A search input below for refining.
3. **Results list** — content-size, padding-block 0. Each result: hairline top border, padding-block m. Item: caption-size eyebrow with post type, heading h3 with title (linked, ink + sienna underline on hover), body excerpt, caption permalink.
4. **No results** — same structure as 404's centered content but with "No results for 'X'" heading.
5. **Marketing footer**

---

## Step 4 — `theme.json` Preview

A partial `theme.json` containing the settings and styles derived from the spec above. The theme-builder will extend this with block-specific styles, template-part references, and pattern registrations.

```json
{
	"$schema": "https://schemas.wp.org/trunk/theme.json",
	"version": 3,
	"settings": {
		"appearanceTools": true,
		"useRootPaddingAwareAlignments": true,
		"layout": {
			"contentSize": "720px",
			"wideSize": "1080px"
		},
		"color": {
			"defaultPalette": false,
			"defaultGradients": false,
			"defaultDuotone": false,
			"palette": [
				{ "name": "Sienna",       "slug": "sienna",       "color": "#9C4B30" },
				{ "name": "Sienna Hover", "slug": "sienna-hover", "color": "#803D26" },
				{ "name": "Ink",          "slug": "ink",          "color": "#2A2A28" },
				{ "name": "Slate",        "slug": "slate",        "color": "#5A5A55" },
				{ "name": "Sage",         "slug": "sage",         "color": "#9DA67E" },
				{ "name": "Honey",        "slug": "honey",        "color": "#D69A3C" },
				{ "name": "Paper",        "slug": "paper",        "color": "#F7F3ED" },
				{ "name": "Paper Warm",   "slug": "paper-warm",   "color": "#F1ECE2" },
				{ "name": "Rule",         "slug": "rule",         "color": "rgba(156, 75, 48, 0.15)" }
			]
		},
		"typography": {
			"fluid": true,
			"fontFamilies": [
				{
					"name": "Heading",
					"slug": "heading",
					"fontFamily": "'Fraunces', Georgia, 'Times New Roman', serif",
					"fontFace": [
						{
							"fontFamily": "Fraunces",
							"fontWeight": "400 700",
							"fontStyle": "normal",
							"src": [ "https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..700&display=swap" ]
						}
					]
				},
				{
					"name": "Body",
					"slug": "body",
					"fontFamily": "'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif",
					"fontFace": [
						{
							"fontFamily": "Inter",
							"fontWeight": "400 600",
							"fontStyle": "normal",
							"src": [ "https://fonts.googleapis.com/css2?family=Inter:wght@400..600&display=swap" ]
						}
					]
				}
			],
			"fontSizes": [
				{ "name": "Caption", "slug": "caption", "size": "0.875rem" },
				{ "name": "Body",    "slug": "body",    "size": "1rem" },
				{ "name": "Lead",    "slug": "lead",    "size": "1.1875rem", "fluid": { "min": "1.0625rem", "max": "1.1875rem" } },
				{ "name": "H3",      "slug": "h3",      "size": "1.25rem",   "fluid": { "min": "1.125rem", "max": "1.25rem" } },
				{ "name": "H2",      "slug": "h2",      "size": "1.75rem",   "fluid": { "min": "1.5rem",   "max": "1.75rem" } },
				{ "name": "H1",      "slug": "h1",      "size": "2.5rem",    "fluid": { "min": "2rem",     "max": "2.5rem" } },
				{ "name": "Display", "slug": "display", "size": "3.75rem",   "fluid": { "min": "2.5rem",   "max": "3.75rem" } }
			]
		},
		"spacing": {
			"defaultSpacingSizes": false,
			"spacingSizes": [
				{ "name": "2xs", "slug": "20", "size": "0.25rem" },
				{ "name": "xs",  "slug": "30", "size": "0.5rem" },
				{ "name": "s",   "slug": "40", "size": "1rem" },
				{ "name": "m",   "slug": "50", "size": "1.5rem" },
				{ "name": "l",   "slug": "60", "size": "2.5rem" },
				{ "name": "xl",  "slug": "70", "size": "4rem" },
				{ "name": "2xl", "slug": "80", "size": "6rem" }
			]
		}
	},
	"styles": {
		"color": {
			"background": "var(--wp--preset--color--paper)",
			"text":       "var(--wp--preset--color--ink)"
		},
		"typography": {
			"fontFamily": "var(--wp--preset--font-family--body)",
			"fontSize":   "var(--wp--preset--font-size--body)",
			"lineHeight": "1.65"
		},
		"spacing": {
			"blockGap": "var(--wp--preset--spacing--40)"
		},
		"elements": {
			"link": {
				"color": { "text": "var(--wp--preset--color--ink)" },
				":hover": {
					"typography": { "textDecoration": "underline solid var(--wp--preset--color--sienna) 2px" }
				},
				"typography": {
					"textDecoration": "underline solid var(--wp--preset--color--sienna) 2px",
					"textUnderlineOffset": "2px"
				}
			},
			"h1": {
				"typography": {
					"fontFamily":    "var(--wp--preset--font-family--heading)",
					"fontSize":      "var(--wp--preset--font-size--h1)",
					"fontWeight":    "500",
					"lineHeight":    "1.2",
					"letterSpacing": "-0.015em"
				}
			},
			"h2": {
				"typography": {
					"fontFamily":    "var(--wp--preset--font-family--heading)",
					"fontSize":      "var(--wp--preset--font-size--h2)",
					"fontWeight":    "500",
					"lineHeight":    "1.2",
					"letterSpacing": "-0.015em"
				}
			},
			"h3": {
				"typography": {
					"fontFamily": "var(--wp--preset--font-family--heading)",
					"fontSize":   "var(--wp--preset--font-size--h3)",
					"fontWeight": "500",
					"lineHeight": "1.3"
				}
			},
			"button": {
				"color": {
					"background": "var(--wp--preset--color--sienna)",
					"text":       "var(--wp--preset--color--paper)"
				},
				"border": { "radius": "8px" },
				"spacing": { "padding": { "top": "0.55em", "right": "1.15em", "bottom": "0.55em", "left": "1.15em" } },
				"typography": {
					"fontFamily":    "var(--wp--preset--font-family--body)",
					"fontWeight":    "500",
					"letterSpacing": "0.01em"
				},
				":hover": {
					"color": { "background": "var(--wp--preset--color--sienna-hover)" }
				}
			}
		}
	}
}
```

---

## Step 5 — Implementation Notes

### Font loading strategy
- Use `<link rel="preconnect">` to `fonts.googleapis.com` and `fonts.gstatic.com` (with `crossorigin`) in the theme's `<head>` to shave the initial DNS lookup.
- Use the variable-font Google Fonts URLs (`opsz,wght@9..144,400..700` for Fraunces; `wght@400..600` for Inter) so the browser fetches a single font file per family rather than discrete weight files.
- Use `font-display: swap` (Google Fonts URL parameter `&display=swap`) so text remains visible during font load. Acceptable FOUT given the close fallback stack (Georgia / system sans).

### CSS custom properties beyond `theme.json`
The following don't have first-class `theme.json` slots and need to be exposed as CSS custom properties in the theme's stylesheet:
- `--surface--shadow--soft`, `--surface--shadow--lift`
- `--surface--border-radius--sm/md/lg` (block-level border-radius lives in theme.json, but the design system uses three radii across components and exposing all three is cleaner than per-block declarations)
- `--surface--transition--fast/default/slow`
- The body background-image (the SVG noise overlay) — apply via theme stylesheet, not theme.json. Conditional based on the noise-yes/no Open Question (default ON; can be feature-flagged in PHP if needed).

### Animation
Almost no motion. Only:
- Hover transitions on interactive elements (`--surface--transition--default`)
- Notice success messages fade out after 8 seconds (per existing `orbit-forms.js`)
- No page-level animations, no scroll-triggered animations, no entrance animations on first paint, no animated indicators (the actionable workflow indicator is intentionally static — see brand brief and creative direction).

### Browser support
- WordPress 7.0.1 is the current project baseline. Browser support should follow
  WordPress core's current browser policy rather than the older 6.4-era browser
  versions that originally informed this design system.
- Variable fonts require Chrome 62+, Safari 11+ — universal in target browsers.
- CSS custom properties, `clamp()`, modern flexbox/grid: all available in target.

### `theme.json` schema version
- Targeting v3 (current, WP 6.6+). If support for older WP versions becomes necessary, fluid typography fallbacks would need to drop in.

### Plugin coordination
Phase 3 surfaced this; restating here for the theme-builder: the 8 app pages need their `_wp_page_template` post-meta set to `page-app.html` so the wider layout renders. Easiest path is a small plugin patch to `Orbit_Activator::create_pages()` and a one-line migration in `orbit_maybe_upgrade()`. Worth bundling into the same PR as the theme so the two ship together.

---

## Step 6 — Open Questions

1. **Sienna canonical value.** This spec promotes the creative-direction's hover value `#9C4B30` to be the canonical Sienna (replacing `#B85D3D`) so it clears WCAG AA universally on Paper. The visual difference is subtle but real. **Sarah's call:** confirm the darker value, OR keep `#B85D3D` and accept that body-text Sienna and button labels will fail strict AA (some users rendering at default zoom levels may have legibility difficulty). My strong recommendation is the darker value — the accessibility win is large, the aesthetic loss is small.

2. **Noise overlay default.** The spec includes the SVG noise overlay enabled by default (per the style tile). The Phase 2 Open Question deferred yes/no to me; I've defaulted to **yes** because the texture is doing real work in the "made by someone" aesthetic and the cost is ~3KB inline. The theme-builder can still feature-flag it via PHP if it conflicts with anything during implementation.

3. **Mobile breakpoint values.** The spec uses `768px` as the single breakpoint for layout shifts. WordPress's modern conventions sometimes prefer `782px` (the WP admin breakpoint) or container-query-based switching. **Defer to theme-builder** to pick one and apply consistently. 768px is my recommendation.

4. **Dark mode.** Explicitly out of scope per the creative direction's design don't #1. If/when it's added later, the entire palette will need a sibling dark variant — substantial work, not in scope here.

5. **Logo.** The wordmark is currently Fraunces-rendered text. A future iteration might want an icon mark to pair with it (favicon, social-share image, app icon). Out of scope for this phase. Worth flagging for **theme-builder** so a placeholder favicon can be swapped later without restructuring.

6. **Tier-badge copy match.** The badge labels in the design ("Just an idea" / "I'll go if you will" / "I'm going — join me") match the plugin's `Orbit_Activity::get_tier_labels()` return values. If the theme renders these labels independently (e.g., in a marketing-page diagram), make sure the source of truth stays in the plugin and the theme either reads from there or the strings are kept in sync manually. Defer to **theme-builder** to wire correctly.
