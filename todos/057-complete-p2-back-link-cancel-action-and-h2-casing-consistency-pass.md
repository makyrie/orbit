---
status: complete
priority: p2
issue_id: "057"
tags: [code-review, consistency, copy]
dependencies: []
---

# Back-Link Copy + Cancel-Action Copy + H2 Casing Consistency Pass

## Problem Statement

Several copy and casing inconsistencies across `includes/class-orbit-shortcodes.php`:

**Back-link copy:**
- `:753` New Activity: `← Cancel and go back`
- `:872` Edit Activity: `← Back to Manage` (Title Case destination)
- `:1324` Subscribe: `← Back to profile` (lowercase destination)
- Edit Profile (`:1075-1077`): no back link at all.

**Cancel-this-activity action labels:**
- `:212` "Cancel activity"
- `:644` "Cancel activity"
- `:881` "Cancel this activity"

Three near-duplicates for the same destructive action.

**H2 casing:**
- `:879` "Danger zone" (sentence case)
- "Notification Preferences" (Title Case)
- "Phone Number" (Title Case)

**Field-label casing:**
- "SMS daily cap" / "Digest time" lowercase
- "Phone Number" / "Display Name" / "URL Slug" / "Date & Time" / "Show Attendees" / "Location Name" Title Case

## Proposed Solution

Pick one convention each and apply consistently across `includes/class-orbit-shortcodes.php`. Suggested:

1. **Back link:** `← Back to {destination}` with lowercase destination ("manage", "profile", "settings", etc.). Update `:753`, `:872`, `:1324` to match. Add a back link to Edit Profile (`:1075-1077`) pointing to the appropriate page.
2. **Cancel-action button:** "Cancel activity" everywhere. Update `:881` from "Cancel this activity" to match `:212` and `:644`.
3. **H2 headings:** sentence case throughout — "Notification preferences", "Phone number", "Danger zone".
4. **Field labels:** sentence case throughout — "Display name", "URL slug", "Date & time", "Show attendees", "Location name", "Phone number".

Update strings inside `__()` calls (text domain `orbit`); regenerate the `.pot` file afterward.

## Acceptance Criteria

- [ ] All back links match `← Back to {lowercase destination}`.
- [ ] Edit Profile has a back link (or a documented reason it doesn't).
- [ ] All cancel-this-activity buttons read "Cancel activity".
- [ ] All H2 headings in shortcodes use sentence case.
- [ ] All field labels in shortcodes use sentence case.
- [ ] `.pot` file regenerated; existing translations either migrated or flagged.
- [ ] Visual pass on each affected page confirms consistency.
