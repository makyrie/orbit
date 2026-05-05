# Perihelion Theme — Phase 6 QA Punch List

Phase 6 deliverable from the website engagement. Reads
[`docs/design-system.md`](./design-system.md), the theme repo's
`DEVIATIONS.md`, and the [creative-direction.md](./creative-direction.md)
to compare the built theme against the spec. Verified against the live
local install at `https://orbit.local/`.

## Summary

| Severity | Count |
|---|---|
| 🔴 Critical | 1 |
| 🟡 Major | 3 |
| 🔵 Minor | 4 |
| ⚪ Observations | 4 |

**Overall assessment:** Theme is in good shape — the design system token application is faithful, all deliverables from upstream phases are honored, and `DEVIATIONS.md` accurately describes intentional implementation choices. The single Critical issue is the duplicated wordmark on the login screen (visible bug), and the three Majors are quality-of-life items that should be wired before any external launch. **Theme is ready for an internal soft-launch with minor fixes; address the Major items before external promotion.**

---

## 🔴 Critical (blocks launch)

### **C1. Login screen renders "PerihelionPerihelion" — duplicated wordmark**

- **Location:** `assets/css/login.css` lines 18–37; `functions.php` `login_headertext` filter
- **Expected:** Single Fraunces wordmark "Perihelion" at top of login form
- **Actual:** Wordmark text appears twice. Visible in the live `/wp-login.php` page (page accessible-name reads `link "PerihelionPerihelion"`).
- **Why:** Two mechanisms collide. The `login_headertext` filter sets the anchor text content to "Perihelion". Then `login.css`'s `text-indent: 0` reveals it (WP's default CSS hides it via large negative text-indent), AND `.login h1 a::after { content: 'Perihelion' }` appends a second copy.
- **Deviations report:** Not noted (this is a bug, not a deviation).
- **Recommendation:** Remove the `::after { content: 'Perihelion' }` rule entirely. The filter-set anchor text + revealed text-indent already produces the wordmark. One fix, one rule deletion, two-line PR.

---

## 🟡 Major (should fix before launch)

### **M1. Active-page nav underline never highlights — `core/list` doesn't add `current-menu-item`**

- **Location:** `assets/css/custom.css:90–94`; `parts/header-app.html`; `parts/header-marketing.html`
- **Expected:** When viewing `/dashboard/`, the "Dashboard" link in the app header should show a Sienna underline (per design system spec, "Active item gets a sienna underline").
- **Actual:** No active-state highlighting. The CSS rule `.is-style-app-nav li.current-menu-item a { border-bottom-color: var(--wp--preset--color--sienna) }` exists but the `current-menu-item` class is never applied — `core/list` doesn't add it the way `core/navigation` does.
- **Deviations report:** Acknowledged in PR #3 description but not in `DEVIATIONS.md` itself.
- **Recommendation:** Two clean options:
  1. Small JS shim in `assets/js/app-nav.js` (~15 lines) that detects current `window.location.pathname` and adds `current-menu-item` class to the matching `.is-style-app-nav li`. Enqueue on logged-in pages only.
  2. PHP-side `render_block` filter that walks the block tree and adds the class server-side based on `get_permalink()` vs current URL. More robust but more complex.
  
  Recommend option 1. Document the deviation.

### **M2. Footer "Sign out" link is hardcoded — non-functional when logged out**

- **Location:** `parts/footer-app.html:5`
- **Expected:** Adapts to login state — "Sign out" when logged in, "Sign in" when logged out (or just hidden, since the app pages already have the login prompt).
- **Actual:** `<a href="/wp-login.php?action=logout">Sign out</a>` is always rendered regardless of auth state. When clicked while logged out, it goes to the WP logout flow which silently no-ops or redirects.
- **Deviations report:** Not noted.
- **Recommendation:** Replace with `<!-- wp:loginout {"displayLoginAsForm":false} /-->` (same pattern used in `header-app.html` last list item). Auto-flips between Sign in and Sign out per auth state. One-line markup swap.

### **M3. App nav shows poster-only links to all logged-in users**

- **Location:** `parts/header-app.html:18–28`
- **Expected:** Per the original `app_nav()` logic that this replaces, links like "Manage", "New Activity", "Subscribers", and "Profile (edit)" are only shown to users with `orbit_create_activity` capability. Subscribers without poster role shouldn't see them.
- **Actual:** All seven internal nav links appear for any logged-in user. A subscriber clicking "Manage" or "Subscribers" lands on a page that renders the shortcode's not-authorized state — workable but cluttered and confusing.
- **Deviations report:** Acknowledged in PR #3 description ("Non-poster users currently see all nav links… could re-implement role filtering via theme filter hooks").
- **Recommendation:** Wrap the 4 poster-only `<li>` items in a `<!-- wp:group -->` with a server-side render-block filter that hides it for non-posters, OR use a small PHP `pre_render_block` filter on `core/list-item` looking for a marker class (`is-poster-only`). Either approach is ~20–30 lines in `functions.php`. Worth doing before launch since the affected users are the largest cohort (subscribers > posters) and seeing inert nav links is a real UX regression.

---

## 🔵 Minor (fix when convenient)

### **m1. `core/navigation` block style in `theme.json` is dead code**

- **Location:** `theme.json:259–265` (`styles.blocks.core/navigation`)
- **Expected:** Either used or removed.
- **Actual:** The header-app and header-marketing both use `core/list` now (post PR #3). No template currently uses `core/navigation`, so this style declaration has no rendering effect. Not harmful, just dead code.
- **Deviations report:** Not noted.
- **Recommendation:** Either delete the rule (preferred — YAGNI), or keep it documented in case future template parts use core/navigation. Delete recommended.

### **m2. `customGradient: false` is set but a gradient could still appear via custom CSS**

- **Location:** `theme.json:15`
- **Expected:** "No fluorescent or saturated CTAs… the design language has no gradients" per design system don'ts.
- **Actual:** `customGradient: false` does prevent editors from applying gradients via the block UI, but `assets/css/custom.css` has no rule preventing inline gradients via theme.json's `style.gradient` attribute on individual blocks. Not currently a problem, just a soft enforcement gap.
- **Recommendation:** Skip — the editor UI gates this sufficiently. Note in `DEVIATIONS.md` for future reference.

### **m3. Search template uses `<!-- wp:search -->` block but has no result-action affordance**

- **Location:** `templates/search.html:14`
- **Expected:** Search input within results page lets user refine search.
- **Actual:** Works, but the placement (in a header block above the results) is a bit unusual — most search-results pages put the search box in a global header, not duplicated on the results page. Functional but not best-practice.
- **Recommendation:** Fine for MVP. If a global header search is added later, remove this duplicate.

### **m4. No `<title>` placeholder on dynamic plugin routes (`/@{slug}` profile pages, `/activity/{id}`)**

- **Location:** Plugin's `Orbit_Routes` rewrites + `page-app.html` template
- **Expected:** Profile pages and activity pages should have descriptive `<title>` tags ("Sarah's profile · Perihelion", "Saturday morning hike · Perihelion") for SEO and tab clarity.
- **Actual:** All dynamic plugin routes inherit the WP page title from whatever WP page is matched (or fall back to "Perihelion"). The plugin's shortcode renders content but doesn't update `<title>` via `wp_title` filter or `document_title_parts` filter.
- **Deviations report:** Not noted (this is a plugin issue, not strictly a theme issue).
- **Recommendation:** Out of scope for theme QA, but worth flagging to plugin work — a `document_title_parts` filter in `Orbit_Routes` that customizes the title based on the matched `orbit_profile_slug` / `orbit_activity_id` query var would address it. Track separately.

---

## ⚪ Observations (not bugs, just notes)

### **O1. The font-loading approach is now via Google Fonts CSS API, not theme.json `fontFace`**

Per `DEVIATIONS.md` entry #11. Documented and intentional. Performance is fine — single CSS request to fonts.googleapis.com, preconnect hints are in place, fonts use variable-font weight ranges. Could in the future move to self-hosted woff2 for offline/privacy/GDPR reasons but not blocking.

### **O2. Sienna canonical value adjustment from creative direction (`#B85D3D` → `#9C4B30`)**

Documented in design system spec under "One Engineering Adjustment." Sarah confirmed in PR #11. Not a deviation, just worth noting in QA records that the in-tree value matches the AA-compliant variant, not the creative-direction-named brighter one.

### **O3. The theme has no automated tests**

WordPress block themes are largely declarative, so this is normal. Visual QA via the live site is the test. The plugin has a 53-test PHPUnit suite (per `tests/OrbitRestActivityTest.php`).

### **O4. Plugin folder is still `wp-content/plugins/orbit/`, not `perihelion`**

The last user-facing "Orbit" surface. Renaming requires filesystem move + plugin reactivation. Deferred per the engagement tracker.

---

## Recommended fix order (if shipping these)

1. **C1** — login wordmark duplicate. 1-line CSS fix, biggest visible regression. Theme PR.
2. **M2** — footer Sign out → `core/loginout`. 1-line markup fix. Theme PR.
3. **M3** — role-aware nav. ~30 lines PHP. Theme PR.
4. **M1** — active-page nav highlight via JS shim. ~15 lines JS + enqueue. Theme PR.
5. **m1** — drop dead `core/navigation` style. Tiny cleanup. Theme PR.

Recommend bundling all 5 into one Phase-6-fixes PR on the theme repo. Total effort: ~1–2 hours of focused work.

---

## What this QA verified ✅

- All 7 templates and 4 template parts from the inventory exist
- `theme.json` is valid JSON, version 3, all spec'd palette and typography tokens present with correct values
- `customTemplates` registers `page-app` correctly
- `templateParts` registers all 4 parts with correct `area` values
- Front page assembles 5 patterns (hero, audience-mirror, how-it-works, whats-different, closing-cta) in order
- 404 page renders with correct heading hierarchy and "Take me home" CTA
- Footer-marketing has all 4 link entries
- Spacing scale (`20`–`80`) matches spec values
- Type scale matches spec values, fluid bounds applied
- Site title set to "Perihelion" globally; document `<title>` resolves correctly across pages
- Color contrast: Ink/Paper 12.8:1 (AAA), Sienna/Paper 5.38:1 (AA), Slate/Paper 6.22:1 (AA)
- Sage and Honey are restricted to non-text decorative use, per spec
- Skip-to-content link is present (WP core)
- Focus-visible rings defined on all interactive elements
- Heading hierarchy across templates: single h1, no skipped levels
- Semantic landmarks: `<header>`, `<main>`, `<footer>`, `<nav>` all present
- Cream-paper noise overlay applied to body background
- Plugin's app pages use `page-app.html` template (post PR #13 + plugin migration)
- Plugin's tier badges now use design-system tinted-surface pattern (post plugin PR #14)
- Plugin's CSS consumes theme tokens via `var(--wp--preset--color--*, fallback)` (post plugin PR #14)
