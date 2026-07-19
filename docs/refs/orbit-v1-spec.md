# Orbit v1 — Product & Technical Specification

> **Historical reference.** This was the original v1 specification. It remains
> useful for product intent and design rationale, but it is not a current file,
> schema, route, or implementation inventory. See [`../../README.md`](../../README.md)
> and the code for current behavior.

## Overview

Orbit is a person-centric social activity tool. A **poster** shares a link with people they've met. Those people **subscribe** and receive notifications when the poster broadcasts activities at varying levels of commitment. Subscribers respond with lightweight "going" / "maybe" actions. No app download required — the entire experience lives on the web, with SMS and email as notification channels.

Orbit is deployed as a standalone WordPress site — the theme *is* the product interface, and a companion plugin provides the data layer, business logic, and integrations.

### Core Design Principles

1. **Single-player value.** One poster with a handful of subscribers is a complete, useful product. No network effects required.
2. **Socially costless decline.** Not responding is a valid, invisible response. The UI never guilt-trips.
3. **Person-centric, not event-centric.** You subscribe to a *person*, not a topic or group.
4. **Privacy by default.** Every visibility setting defaults to the most restrictive option. Users opt in to exposure, never out.
5. **No unsolicited contact.** Orbit never sends a message to someone who hasn't explicitly opted in. Posters share their own link through their own channels.

---

## User Roles

A single WordPress account can hold both roles simultaneously. Roles are assigned independently; `orbit_poster` does not inherit from `orbit_subscriber`.

### `orbit_subscriber` (default for all users)

- Subscribes to one or more posters
- Views a unified dashboard of all subscriptions and upcoming activities
- Responds "going" or "maybe" to activities
- Controls notification preferences and attendee visibility

### `orbit_poster` (added via CTA when user wants to share activities)

- Creates a profile/page with a shareable subscription link
- Posts activities at defined commitment tiers
- Approves or denies subscription requests
- Controls per-activity visibility settings
- Manages their subscriber list

### Role Upgrade Flow

Any subscriber can become a poster. A gentle CTA ("Share your own activities") appears in a few key places (dashboard sidebar, settings page). Clicking it assigns the `orbit_poster` role to their existing account and creates an `orbit_profiles` record. No separate registration flow.

---

## Activity Commitment Tiers

Each activity is created at one of three tiers (v1). The tier is prominently displayed so subscribers immediately understand the poster's level of commitment.

| Tier | Label | Meaning | Default Notification |
|------|-------|---------|---------------------|
| 1 | **Just an idea** | "Would anyone be interested in this?" | Daily digest email |
| 2 | **I'll go if you will** | "I'm interested but want company" | Daily digest email |
| 3 | **I'm going — join me** | "This is happening, come along" | SMS/text |

Date/time is optional for all tiers. Tier 1 activities may or may not be time-bound.

### Deferred (post-v1)

| Tier | Label | Meaning |
|------|-------|---------|
| 0 | **Things I'd do whenever** | Persistent list, not time-bound, not broadcasted |

### Implementation Note: Tier and Status as Columns

Tier and status are stored as columns on the `orbit_activities` custom table rather than as WordPress taxonomies. Rationale: these are tightly constrained fields (3 tier values, 3 status values) that directly drive application logic — notification routing, display filtering, SMS vs. digest decisions. Column-level storage provides enum validation, simpler queries, and avoids the overhead of `wp_get_object_terms()` for values that are never user-defined or admin-editable.

---

## Data Model

All custom tables use the `orbit_` prefix. All users — posters and subscribers — are WordPress users. The `orbit_subscriptions` table tracks relationships between users and poster profiles.

### Tables

#### `orbit_profiles`

The poster's public-facing page configuration. Created when a user activates the poster role.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `user_id` | bigint, unique, FK → `wp_users` | One profile per user |
| `slug` | varchar(100), unique | URL-safe identifier, e.g., `sarah-k` |
| `display_name` | varchar(200) | Public name |
| `bio` | text, nullable | Optional short description |
| `share_token` | varchar(64), unique | Used in the shareable subscription link |
| `require_approval` | tinyint(1), default 1 | Whether new subscribers need approval |
| `created_at` | datetime | |
| `updated_at` | datetime | |

#### `orbit_subscriptions`

Relationship table: which user subscribes to which poster profile.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `user_id` | bigint, FK → `wp_users` | The subscriber |
| `profile_id` | bigint, FK → `orbit_profiles` | The poster they subscribe to |
| `connection_note` | text, nullable | "How do you know this person?" |
| `status` | enum('pending', 'approved', 'denied', 'unsubscribed') | Default: 'pending' |
| `visibility_default` | enum('anonymous', 'visible') | Default: 'anonymous' |
| `subscription_secret` | varchar(64), unique | Stable identifier for this subscription — used for unsubscribe links and as seed for generating activity-scoped action tokens |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**Unique constraint:** (`user_id`, `profile_id`)

#### User Metadata (wp_usermeta)

Additional per-user fields stored in `wp_usermeta`:

| Meta Key | Type | Notes |
|----------|------|-------|
| `orbit_phone` | varchar(20) | E.164 format, nullable |
| `orbit_phone_verified` | tinyint(1) | Default: 0. SMS notifications only sent when 1 |
| `orbit_timezone` | varchar(50) | IANA timezone string (e.g., `America/Los_Angeles`). Defaults to poster's site timezone at signup |

#### `orbit_activities`

An activity posted by a poster.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `profile_id` | bigint, FK → `orbit_profiles` | Who posted it |
| `tier` | tinyint | 1 = just an idea, 2 = I'll go if you will, 3 = I'm going |
| `title` | varchar(300) | Short description |
| `description` | text, nullable | Longer details |
| `location_name` | varchar(300), nullable | Human-readable location (visible publicly) |
| `location_address` | text, nullable | Full address — only shown to approved subscribers |
| `date_time` | datetime, nullable | Optional for all tiers |
| `date_flexible` | tinyint(1), default 0 | "Date is approximate" |
| `show_attendees` | enum('none', 'count', 'names') | Default: 'count' |
| `status` | enum('active', 'cancelled', 'past') | Default: 'active' |
| `created_at` | datetime | |
| `updated_at` | datetime | |

#### `orbit_responses`

Subscriber responses to activities.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `activity_id` | bigint, FK → `orbit_activities` | |
| `subscription_id` | bigint, FK → `orbit_subscriptions` | |
| `response` | enum('going', 'maybe') | No "not going" — absence is the default |
| `visibility_override` | enum('anonymous', 'visible', 'default'), default 'default' | Per-event override |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**Unique constraint:** (`activity_id`, `subscription_id`)

#### `orbit_notification_preferences`

Account-wide notification settings for a user (in their subscriber capacity). One row per user.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `user_id` | bigint, unique, FK → `wp_users` | |
| `tier1_method` | enum('sms', 'email', 'digest', 'none') | Default: 'digest' |
| `tier2_method` | enum('sms', 'email', 'digest', 'none') | Default: 'digest' |
| `tier3_method` | enum('sms', 'email', 'digest', 'none') | Default: 'sms' |
| `sms_daily_cap` | smallint, nullable | Max total SMS per day across all subscriptions. Null = no cap (default). Overflow routed to digest |
| `digest_time` | time | Default: '18:00:00' (6 PM in user's timezone) |
| `created_at` | datetime | |
| `updated_at` | datetime | |

#### `orbit_notification_log`

Tracks sent notifications for deduplication, debugging, digest batching, and SMS cap enforcement.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `user_id` | bigint, FK → `wp_users` | The recipient |
| `activity_id` | bigint, FK → `orbit_activities` | |
| `method` | enum('sms', 'email', 'digest') | |
| `status` | enum('queued', 'sent', 'failed') | |
| `sent_at` | datetime, nullable | |
| `created_at` | datetime | |

#### `orbit_phone_verification`

Tracks phone number verification codes.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `user_id` | bigint, FK → `wp_users` | |
| `phone` | varchar(20) | E.164 format |
| `code` | varchar(6) | 6-digit verification code |
| `attempts` | tinyint, default 0 | Failed verification attempts |
| `expires_at` | datetime | Code expiration (e.g., 10 minutes) |
| `created_at` | datetime | |

---

## Architecture: Theme + Plugin

### Plugin: `orbit-plugin`

Owns all data and logic. No UI rendering (except within shortcode/block callbacks).

```
orbit-plugin/
├── orbit-plugin.php                   # Plugin header, activation/deactivation hooks
├── includes/
│   ├── class-orbit-activator.php      # Table creation, role setup
│   ├── class-orbit-roles.php          # Role and capability registration
│   ├── class-orbit-profile.php        # Profile CRUD
│   ├── class-orbit-activity.php       # Activity CRUD
│   ├── class-orbit-subscription.php   # Subscription management
│   ├── class-orbit-response.php       # Response handling
│   ├── class-orbit-notifier.php       # Notification dispatch (SMS, email, digest)
│   ├── class-orbit-twilio.php         # Twilio API wrapper
│   ├── class-orbit-phone-verify.php   # Phone verification flow
│   ├── class-orbit-privacy.php        # Visibility resolution logic
│   └── class-orbit-rest-api.php       # REST API endpoint registration
├── cli/
│   ├── class-orbit-cli.php            # WP-CLI registration and base command
│   ├── class-orbit-cli-profile.php    # wp orbit profile [create|get|update|delete|list|regenerate-token]
│   ├── class-orbit-cli-activity.php   # wp orbit activity [create|get|update|cancel|list|responses]
│   ├── class-orbit-cli-subscription.php # wp orbit subscription [list|approve|deny|remove]
│   ├── class-orbit-cli-subscriber.php # wp orbit subscriber [list|get|set-role]
│   ├── class-orbit-cli-notification.php # wp orbit notification [send-digest|preview-digest|log]
│   └── class-orbit-cli-status.php     # wp orbit status — system overview for agent context
├── composer.json                      # ActionScheduler dependency
└── readme.txt
```

### Theme: `orbit-theme`

FSE (Full Site Editing) block theme. Handles all rendering and user-facing templates. Uses blocks and/or shortcodes registered by the plugin to insert dynamic content.

```
orbit-theme/
├── style.css                          # Theme header + base styles
├── theme.json                         # Global styles, color palette, typography, spacing
├── functions.php                      # Theme setup, enqueue styles/scripts, register block patterns
├── templates/
│   ├── index.html                     # Fallback template
│   ├── front-page.html                # Landing/marketing page (logged out) or redirect to dashboard
│   ├── single-orbit-activity.html     # Activity detail page (if using CPT) — or page template
│   └── page.html                      # Generic page template
├── parts/
│   ├── header.html                    # Site header (navigation varies by role)
│   ├── footer.html                    # Site footer
│   └── sidebar-dashboard.html         # Dashboard sidebar (subscription list, CTA)
├── patterns/
│   ├── activity-card.php              # Reusable activity card pattern
│   ├── tier-badge.php                 # Tier indicator pattern
│   ├── response-buttons.php           # Going/Maybe action buttons
│   └── subscriber-form.php            # Subscription signup form
├── assets/
│   ├── css/
│   │   └── orbit-custom.css           # Additional styles beyond theme.json
│   └── js/
│       └── orbit.js                   # Minimal JS (AJAX responses, form handling, phone verification)
└── screenshot.png
```

### Plugin ↔ Theme Boundary

- **Plugin registers:** Custom tables, roles, REST API endpoints, WP-CLI commands, ActionScheduler jobs, shortcodes (e.g., `[orbit_dashboard]`, `[orbit_profile]`, `[orbit_subscribe_form]`, `[orbit_activity]`), and block types if desired
- **Theme renders:** All HTML/CSS using FSE templates, block patterns, and the plugin's shortcodes/blocks for dynamic content
- **Data flow:** Theme calls plugin functions or REST API; plugin never outputs HTML directly (except within shortcode callbacks)
- **Agent access:** WP-CLI commands and REST API provide full parity with the admin UI — any action a poster or subscriber can take through the web interface is also achievable via CLI or API

---

## Page Structure & Routes

Orbit is the entire site. No `/orbit/` prefix.

### Public Pages (No Auth Required)

| Route | Purpose | Implementation |
|-------|---------|----------------|
| `/` | Landing page: explains Orbit, login/signup | FSE `front-page.html` |
| `/@{slug}` | Poster's public profile page | Custom rewrite → shortcode |
| `/@{slug}/subscribe?token={share_token}` | Subscription signup form | Shortcode on profile page or separate template |
| `/activity/{id}` | Individual activity detail page | Custom rewrite → shortcode |
| `/unsubscribe?token={token}` | One-click unsubscribe (no login required) | Custom rewrite → plugin handler |

### Authenticated Pages

| Route | Purpose | Implementation |
|-------|---------|----------------|
| `/dashboard` | Subscriber's unified view: all subscriptions, upcoming activities | WordPress page + `[orbit_dashboard]` shortcode |
| `/dashboard/settings` | Notification preferences, visibility defaults, timezone | WordPress page + `[orbit_settings]` shortcode |
| `/manage` | Poster's management view | WordPress page + `[orbit_manage]` shortcode |
| `/manage/new` | Create new activity form | WordPress page + `[orbit_new_activity]` shortcode |
| `/manage/activity/{id}` | Edit activity, view responses | Custom rewrite + shortcode |
| `/manage/subscribers` | Manage subscriber list | WordPress page + `[orbit_subscribers]` shortcode |
| `/manage/profile` | Edit poster profile | WordPress page + `[orbit_edit_profile]` shortcode |

### Route Implementation Notes

- Profile slugs use `/@` prefix (e.g., `/@sarah-k`), which follows a familiar convention and eliminates collision risk with WordPress page slugs entirely. No reserved slug list needed.
- Authenticated pages are standard WordPress pages with shortcodes. Access control via role checks in the shortcode callbacks.
- Navigation dynamically adjusts: subscribers see Dashboard and Settings; users with `orbit_poster` role also see Manage, New Activity, etc.

### Search Engine Indexing

Activity pages include `<meta name="robots" content="noindex, nofollow">` to prevent indexing. Profile pages may be indexable (poster's choice, deferred to post-v1; default noindex for now). `robots.txt` blocks `/activity/`, `/dashboard/`, `/manage/`.

---

## Authentication & Account Creation

### Subscription Signup Flow (Account Creation)

When a person clicks a poster's subscription link, they either log in to an existing account or create a new one. The signup form collects:

1. Name (required)
2. Email (required — becomes their WordPress account email)
3. Phone (optional — triggers verification flow if provided)
4. Connection note: "How do you know [Poster Name]?" (optional but encouraged)
5. Password (required — standard WordPress account creation)

On submission:
- WordPress user account created with `orbit_subscriber` role
- `orbit_subscriptions` record created with `status = 'pending'` (if poster requires approval) or `status = 'approved'`
- `orbit_timezone` usermeta set to the poster's site timezone as initial default
- If phone provided, verification code sent via SMS; `orbit_phone_verified` remains 0 until verified
- `subscription_secret` generated for unsubscribe links and action token derivation

### Returning Users

If a logged-in user clicks a subscription link for a new poster, the form is pre-filled and only shows the connection note field + confirm button. No duplicate account creation.

### No-Login Interactions

For subscribers who don't want to use the dashboard:
- Notification links include `?act={action_token}` — an activity-scoped, time-limited token for automatic identification
- Activity response pages work without login when a valid action token is present
- Unsubscribe links use `?token={subscription_secret}` and work without login
- Action tokens only grant access to respond to the specific activity they were generated for

---

## Notification System

### Immediate Notifications (SMS and Email)

Triggered when a poster creates an activity at a tier matching the subscriber's preference for immediate notification.

**SMS format:**
```
[Poster Name] is going to [Activity Title] on [Date].
Details & RSVP: https://orbit.example.com/activity/123?act=ACTION_TOKEN
Reply STOP to unsubscribe from all Orbit texts.
```

**Email format:**
- From: `Orbit <notifications@orbit.example.com>`
- Subject: `[Poster Name]: [Activity Title]`
- Body: Activity details, tier label, RSVP buttons (going/maybe as links with action token), unsubscribe link (with subscription secret)
- Plain text + HTML versions

### SMS Daily Cap

Subscribers can optionally set a total daily SMS limit in their notification preferences (`sms_daily_cap` on `orbit_notification_preferences`). Default is no cap. If a subscriber would exceed their self-set cap:
- The notification is not dropped — it's routed to the next daily digest instead
- Cap is total across all subscriptions, enforced via `orbit_notification_log`
- If a subscriber receives more than 3 SMS in a day without having a cap set, the system surfaces a non-intrusive prompt on their next dashboard visit or activity page: "You received [N] texts from Orbit today — want to set a daily limit?"

### Daily Digest Email

Batched by ActionScheduler, sent at the subscriber's preferred time in their local timezone.

**Content:**
- Grouped by poster
- Sorted by tier (highest commitment first), then by date
- Each activity shows: tier badge, title, date/time if set, RSVP link with activity-scoped action token
- Only includes activities posted since the last digest
- Includes any SMS-capped notifications that were redirected to digest
- Skip sending if there's nothing new

### Phone Number Verification

1. User provides phone number during signup or in settings
2. System sends 6-digit code via SMS: `Your Orbit verification code is: 123456`
3. User enters code on the verification page
4. Max 3 attempts per code; code expires after 10 minutes
5. On success: `orbit_phone_verified` set to 1, SMS notifications activated
6. If user changes phone number, verification resets

### ActionScheduler Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `orbit_send_immediate_notification` | One-off, on activity creation | Send SMS or email for tier-matched subscribers |
| `orbit_send_daily_digest` | Recurring, per-user based on `digest_time` + timezone | Compile and send digest emails |
| `orbit_mark_past_activities` | Recurring, daily | Update status of activities whose date has passed |
| `orbit_cleanup_notification_log` | Recurring, weekly | Prune entries older than retention period |

### Twilio Integration

Store credentials as `wp-config.php` constants:
- `ORBIT_TWILIO_ACCOUNT_SID`
- `ORBIT_TWILIO_AUTH_TOKEN`
- `ORBIT_TWILIO_FROM_NUMBER`

Use Twilio's REST API directly via `wp_remote_post()` — no SDK dependency needed.

Handle incoming messages via Twilio webhook:
- `POST /api/twilio/incoming` — processes STOP/START keywords, updates user preferences
- Validate Twilio request signature on all incoming webhooks

---

## Privacy & Visibility Model

### Defaults (Everything Private)

| Setting | Default | Controlled By |
|---------|---------|---------------|
| New subscriber status | Pending (requires approval) | Poster |
| Subscriber visibility on activity pages | Anonymous | Subscriber (account-wide default) |
| Attendee display on activities | Count only | Poster (per-activity) |
| Location address visibility | Approved subscribers only | System (always enforced) |
| Activity visibility beyond subscribers | Not shared | Poster (per-activity, post-v1) |
| Profile page indexable by search engines | No | System default (poster choice post-v1) |

### Visibility Resolution for Attendee Lists

When rendering an activity's attendee list:

1. **Poster's `show_attendees` setting for this activity:**
   - `none` → show nothing
   - `count` → show "3 going · 1 maybe" (no names)
   - `names` → proceed to step 2

2. **Each attendee's visibility** (resolved: per-activity override > account default):
   - `anonymous` → show as "Someone" or similar placeholder
   - `visible` → show their display name

### Location Privacy

- `location_name` (e.g., "Balboa Park") — visible on public activity page
- `location_address` (e.g., "1549 El Prado, San Diego, CA 92101") — only visible to approved subscribers (identified via login session or valid action token)

---

## Key User Flows

### Flow 1: Poster Creates Profile and Shares Link

1. User with `orbit_poster` role navigates to `/manage/profile`
2. Sets display name, optional bio, and slug
3. System generates a `share_token` and constructs shareable URL: `https://orbit.example.com/@sarah-k/subscribe?token=abc123`
4. Poster copies link and shares via their own channels (text, email, in-person QR code, etc.)

### Flow 2: New Person Subscribes

1. Person clicks the shared link, lands on subscription form
2. Creates WordPress account: name, email, password, optional phone, connection note
3. If phone provided → verification code sent, user enters code
4. If poster requires approval → status = `pending`; subscriber sees confirmation message
5. Poster receives notification of pending subscriber (email or dashboard indicator)
6. Poster approves → subscriber receives welcome notification via preferred channel
7. Subscriber's timezone set to poster's site timezone as default
8. `subscription_secret` generated for unsubscribe links and action token derivation

### Flow 3: Existing User Subscribes to Another Poster

1. Logged-in user clicks a different poster's subscription link
2. Form is pre-filled; only shows connection note field + confirm button
3. Subscription created; approval flow same as above

### Flow 4: Poster Creates Activity

1. Poster navigates to `/manage/new`
2. Fills in: tier (select from three options), title, optional description, optional location, optional date/time, date flexibility flag
3. Sets attendee visibility for this activity (defaults to their preference)
4. Submits → system creates activity and queues notifications:
   - For each approved subscriber: check notification preference for this tier
   - Check SMS daily cap; route to digest if exceeded
   - Queue immediate SMS/email or flag for next digest accordingly

### Flow 5: Subscriber Responds to Activity

**From notification link (no login required):**
1. Clicks link in SMS or email (URL includes activity-scoped action token)
2. Lands on activity detail page; system validates action token and identifies subscriber
3. Taps "Going" or "Maybe" — response recorded immediately via REST API
4. Page updates to show their response and (per visibility rules) other attendees

**From dashboard (logged in):**
1. Views unified feed of activities across all subscriptions
2. Taps into an activity, responds going/maybe
3. Can change response at any time before the activity date

### Flow 6: Subscriber Becomes Poster

1. Subscriber sees CTA: "Want to share your own activities?"
2. Clicks through → `orbit_poster` role added to their account
3. Redirected to profile setup (`/manage/profile`)
4. From here, standard poster flow

### Flow 7: Subscriber Manages Settings

1. From dashboard, navigates to `/dashboard/settings`
2. Sets account-wide notification method per tier (SMS, email, digest, none)
3. Sets preferred digest delivery time
4. Sets timezone (pre-populated from signup, adjustable)
5. Sets default attendee visibility (anonymous or visible)
6. Manages/verifies phone number

---

## REST API Endpoints

All under `/wp-json/orbit/v1/`.

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| POST | `/subscribe` | Submit subscription request | Public (requires valid `share_token`) |
| POST | `/verify-phone` | Submit phone verification code | Logged-in or token |
| POST | `/respond` | Submit going/maybe response | Action token or logged-in |
| GET | `/activities` | List activities for a profile | Logged-in subscriber |
| POST | `/activities` | Create activity | `orbit_poster` role |
| PATCH | `/activities/{id}` | Update activity | Poster (owner) only |
| DELETE | `/activities/{id}` | Cancel activity | Poster (owner) only |
| GET | `/subscriptions` | List current user's subscriptions | Logged-in subscriber |
| GET | `/subscribers` | List subscribers for poster's profile | `orbit_poster` role |
| PATCH | `/subscribers/{id}` | Approve/deny/remove subscriber | Poster (owner) only |
| PATCH | `/preferences` | Update notification preferences | Logged-in |
| POST | `/unsubscribe` | Unsubscribe via token | Public (valid `subscription_secret`) |
| POST | `/twilio/incoming` | Handle Twilio webhooks | Twilio signature validation |

---

## Agent-Native Architecture

Orbit is designed so that agents (Claude Code, WP-CLI scripts, external automation) are first-class actors. Every action a human can take through the web UI is achievable via WP-CLI or REST API. Tools are atomic primitives; complex workflows are composed by the agent, not baked into the code.

### Capability Map

| User Action | Web UI | WP-CLI | REST API |
|---|---|---|---|
| Create poster profile | ✓ | `wp orbit profile create` | POST `/profiles` |
| Update poster profile | ✓ | `wp orbit profile update` | PATCH `/profiles/{id}` |
| Delete poster profile | ✓ | `wp orbit profile delete` | DELETE `/profiles/{id}` |
| Regenerate share token | ✓ | `wp orbit profile regenerate-token` | POST `/profiles/{id}/regenerate-token` |
| List poster profiles | — | `wp orbit profile list` | GET `/profiles` |
| Create activity | ✓ | `wp orbit activity create` | POST `/activities` |
| Update activity | ✓ | `wp orbit activity update` | PATCH `/activities/{id}` |
| Cancel activity | ✓ | `wp orbit activity cancel` | DELETE `/activities/{id}` |
| List activities | ✓ | `wp orbit activity list` | GET `/activities` |
| View activity responses | ✓ | `wp orbit activity responses` | GET `/activities/{id}/responses` |
| Respond to activity | ✓ | `wp orbit response set` | POST `/respond` |
| Remove response | ✓ | `wp orbit response remove` | DELETE `/respond` |
| List subscribers | ✓ | `wp orbit subscription list` | GET `/subscribers` |
| Approve subscriber | ✓ | `wp orbit subscription approve` | PATCH `/subscribers/{id}` |
| Deny subscriber | ✓ | `wp orbit subscription deny` | PATCH `/subscribers/{id}` |
| Remove subscriber | ✓ | `wp orbit subscription remove` | PATCH `/subscribers/{id}` |
| List user's subscriptions | ✓ | `wp orbit subscriber subscriptions` | GET `/subscriptions` |
| Update notification prefs | ✓ | `wp orbit subscriber set-preferences` | PATCH `/preferences` |
| Assign poster role | ✓ | `wp orbit subscriber set-role` | — (use `wp user add-role`) |
| View system status | — | `wp orbit status` | GET `/status` |
| Send digest now | — | `wp orbit notification send-digest` | — |
| Preview digest | — | `wp orbit notification preview-digest` | — |
| View notification log | — | `wp orbit notification log` | GET `/notifications` |

Dashes in the Web UI column indicate operations that are agent/admin-only (not exposed in the user-facing interface). Dashes in REST API indicate operations that only make sense from the server (digest sending, role assignment).

### WP-CLI Commands

All commands are registered under the `orbit` namespace. All list/get commands support `--format=json|csv|table` (default: `table`). All mutating commands output the affected record as JSON on success. All commands use proper exit codes (0 = success, 1 = error) and write errors to STDERR.

#### `wp orbit status`

System overview for agent context. Returns counts, configuration state, and recent activity. This is the first command an agent should run to understand the current state.

```bash
wp orbit status --format=json
```

```json
{
  "profiles": { "total": 3 },
  "activities": { "active": 12, "past": 47, "cancelled": 2 },
  "users": { "total": 84, "subscribers": 81, "posters": 3 },
  "subscriptions": { "approved": 156, "pending": 4 },
  "notifications": {
    "sms_sent_today": 8,
    "digests_sent_today": 42,
    "failures_last_24h": 0
  },
  "config": {
    "twilio_configured": true,
    "smtp_configured": true,
    "default_digest_time": "18:00",
    "notification_log_retention_days": 90
  }
}
```

#### `wp orbit profile`

```bash
# Create
wp orbit profile create --user=<user_id> --slug="sarah-k" --display-name="Sarah K" --bio="..." 

# Get
wp orbit profile get <profile_id> --format=json
wp orbit profile get --slug="sarah-k" --format=json

# Update
wp orbit profile update <profile_id> --display-name="Sarah K." --bio="Updated bio"

# Delete (soft: deactivates profile, notifies subscribers)
wp orbit profile delete <profile_id>
wp orbit profile delete <profile_id> --force  # hard delete, no notification

# List
wp orbit profile list --format=json
wp orbit profile list --user=<user_id>

# Regenerate share token (invalidates old subscription links)
wp orbit profile regenerate-token <profile_id>
```

#### `wp orbit activity`

```bash
# Create (queues notifications per subscriber preferences)
wp orbit activity create --profile=<profile_id> --tier=3 --title="Ceramics workshop" \
  --description="..." --location-name="Clay Studio" --location-address="123 Main St" \
  --date-time="2026-04-15 10:00:00" --show-attendees=count

# Get
wp orbit activity get <activity_id> --format=json

# Update
wp orbit activity update <activity_id> --title="Updated title" --tier=2

# Cancel (sets status=cancelled, does not delete)
wp orbit activity cancel <activity_id>

# List (supports filtering)
wp orbit activity list --profile=<profile_id> --format=json
wp orbit activity list --status=active --tier=3
wp orbit activity list --after="2026-04-01" --before="2026-04-30"

# View responses for an activity
wp orbit activity responses <activity_id> --format=json
```

#### `wp orbit subscription`

Manages the relationship between a subscriber and a poster (from the poster's perspective).

```bash
# List subscribers for a profile
wp orbit subscription list --profile=<profile_id> --format=json
wp orbit subscription list --profile=<profile_id> --status=pending

# Approve
wp orbit subscription approve <subscription_id>

# Deny
wp orbit subscription deny <subscription_id>

# Remove (sets status=unsubscribed)
wp orbit subscription remove <subscription_id>

# Bulk approve
wp orbit subscription approve --profile=<profile_id> --status=pending --all
```

#### `wp orbit subscriber`

Manages subscriber-side operations (from the subscriber's perspective).

```bash
# List a user's subscriptions (across all posters)
wp orbit subscriber subscriptions --user=<user_id> --format=json

# Get subscriber details
wp orbit subscriber get --user=<user_id> --format=json

# Update notification preferences
wp orbit subscriber set-preferences --user=<user_id> --tier1=digest --tier2=digest --tier3=sms

# Add poster role to a subscriber
wp orbit subscriber set-role --user=<user_id> --add=orbit_poster
```

#### `wp orbit response`

```bash
# Set a response (idempotent — creates or updates)
wp orbit response set --subscription=<subscription_id> --activity=<activity_id> --response=going

# Remove a response
wp orbit response remove --subscription=<subscription_id> --activity=<activity_id>

# List responses by user across activities
wp orbit response list --user=<user_id> --format=json
```

#### `wp orbit notification`

```bash
# Manually trigger digest for a specific user (useful for testing)
wp orbit notification send-digest --user=<user_id>

# Preview what a digest would contain (dry run, no send)
wp orbit notification preview-digest --user=<user_id> --format=json

# View notification log
wp orbit notification log --format=json
wp orbit notification log --user=<user_id> --method=sms --after="2026-04-01"
wp orbit notification log --status=failed
```

### Design Principles for Agent Access

**Atomic operations:** Each CLI command and API endpoint does one thing. `wp orbit activity create` creates an activity and queues notifications. It doesn't also create a profile or approve subscribers. An agent composes these primitives to achieve complex outcomes.

**Structured output:** Every command supports `--format=json`. Agents parse JSON; humans read tables. Both paths work.

**Idempotent where possible:** `wp orbit response set` creates or updates — calling it twice with the same arguments is safe. `wp orbit subscription approve` on an already-approved subscription is a no-op that succeeds.

**Errors to STDERR, exit codes for control flow:** A failing command returns exit code 1 and writes a human-readable error to STDERR. An agent in a loop can check exit codes to decide what to do next.

**Context before action:** `wp orbit status` gives an agent everything it needs to understand the system state before operating. No context starvation.

**Filtering on list commands:** List commands accept filters (`--status`, `--tier`, `--profile`, `--user`, `--after`, `--before`) so agents can narrow results without post-processing.

**No workflow-shaped commands:** There is no `wp orbit create-activity-and-notify`. The agent creates the activity (which queues notifications as a side effect of the data layer, not the CLI). If an agent wants to create an activity *without* notifying, that's a future flag (`--skip-notifications`), not a separate command.

### REST API Additions for Parity

The following endpoints are additions to the REST API table above, identified through the capability map:

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/profiles` | List all profiles (admin) or own profile | `orbit_poster` role |
| POST | `/profiles` | Create profile | `orbit_poster` role |
| PATCH | `/profiles/{id}` | Update profile | Poster (owner) only |
| DELETE | `/profiles/{id}` | Deactivate/delete profile | Poster (owner) only |
| POST | `/profiles/{id}/regenerate-token` | Regenerate share token | Poster (owner) only |
| GET | `/activities/{id}/responses` | List responses for an activity | Poster (owner) only |
| DELETE | `/respond` | Remove a response | Logged-in subscriber |
| GET | `/status` | System status summary | Admin only |
| GET | `/notifications` | Notification log (filtered) | Admin only |

### Approval and Autonomy Matrix

Per the agent-native skill's stakes/reversibility framework:

| Action | Stakes | Reversibility | Pattern |
|---|---|---|---|
| Create/update activity (draft-like, no notification yet) | Low | Easy | Auto-apply |
| Create activity (triggers notifications) | Low | Hard | Quick confirm — notification is the irreversible part |
| Approve/deny subscriber | Low | Easy | Auto-apply |
| Cancel activity | Medium | Easy | Auto-apply (can be reactivated) |
| Send digest manually | Low | Hard | Quick confirm |
| Delete profile | High | Hard | Explicit approval |
| Bulk approve subscribers | Medium | Easy | Suggest + apply |
| Remove subscriber | Medium | Medium | Auto-apply (resubscription possible) |

Agents interacting with Orbit should use these patterns to decide when to ask for confirmation. Note that activity creation is the key boundary: the activity itself is low-stakes, but the notifications it triggers are not easily reversible. An agent creating activities should confirm before creating tier-3 activities that will immediately trigger SMS notifications.

---

## Security Considerations

### Token Design

- **Share tokens** (`share_token` on `orbit_profiles`): Used in subscription links. Can be regenerated by the poster (invalidates old links). Generated via `wp_generate_password(32, false)`.
- **Subscription secrets** (`subscription_secret` on `orbit_subscriptions`): Stable per-subscription identifier. Used directly for unsubscribe links (one-click, no-login, per CAN-SPAM / TCPA). Also used as the seed for generating activity-scoped action tokens.
- **Action tokens** (computed, not stored): Short-lived, activity-scoped tokens for no-login responses (going/maybe). Generated as `HMAC-SHA256(subscription_secret, activity_id + expiry_timestamp)` and included in notification links. Validated on the server by recomputing the HMAC. Expire after a configurable period (e.g., 7 days after the activity date, or 30 days after creation for dateless activities). This ensures a leaked notification link only grants access to one specific activity response, not the subscriber's entire account.
- **Unsubscribe links**: Use the `subscription_secret` directly. The realistic threat model for a leaked unsubscribe link is malicious unsubscription, which is low-harm and reversible. Acceptable for v1; if it becomes a problem, add a resubscribe confirmation flow ("Someone unsubscribed you from [Poster] — was that you?").

### Input Validation

- Phone numbers: Validate and normalize to E.164 format on save
- Email: WordPress `is_email()` validation
- Profile slugs: `sanitize_title()`, check uniqueness, check against reserved slugs (dashboard, manage, activity, unsubscribe, api, wp-admin, etc.)
- Connection notes: `sanitize_textarea_field()`, max 500 characters
- Activity titles: `sanitize_text_field()`, max 300 characters
- All form submissions: Nonce verification for logged-in actions; token verification for public actions

### Rate Limiting

- Subscription form: Rate-limit by IP (e.g., 5 submissions per hour per IP) via transients
- Phone verification: Max 3 code requests per phone number per hour
- API endpoints: Basic rate limiting via transients for unauthenticated endpoints

### Abuse Prevention

- Subscribers must opt in (click link + create account)
- Posters approve subscribers (default setting)
- Every SMS includes STOP instructions (Twilio-standard)
- Every email includes one-click unsubscribe link
- Unsubscribe is instant and requires no login
- No mechanism for a poster to send arbitrary text to subscribers — only system-generated notifications for posted activities
- Phone numbers must be verified before SMS notifications are sent

---

## Timezone Handling

- All datetimes stored in UTC in the database
- User's timezone stored in `wp_usermeta` as `orbit_timezone` (IANA string, e.g., `America/Los_Angeles`)
- Default timezone derived from the poster's site timezone at subscription signup
- User can change timezone in settings
- Activity times displayed in the viewer's timezone
- Digest scheduling uses the subscriber's timezone (digest at 6 PM *their* time)
- Poster creates activities in their own timezone; system converts to UTC for storage

---

## Configuration (wp-config.php)

```php
// Required for SMS
define('ORBIT_TWILIO_ACCOUNT_SID', '...');
define('ORBIT_TWILIO_AUTH_TOKEN', '...');
define('ORBIT_TWILIO_FROM_NUMBER', '+1...');

// Optional
define('ORBIT_NOTIFICATION_LOG_RETENTION', 90);  // Days to keep notification logs
define('ORBIT_DEFAULT_DIGEST_TIME', '18:00');     // Default digest send time
define('ORBIT_PHONE_VERIFY_EXPIRY', 600);         // Verification code expiry in seconds (10 min)
define('ORBIT_PHONE_VERIFY_MAX_ATTEMPTS', 3);     // Max verification attempts per code
define('ORBIT_RATE_LIMIT_SUBSCRIBE', 5);           // Max subscription attempts per IP per hour
```

---

## UI/UX Notes

### Visual Design Direction

- Clean, minimal, not "social media"-looking
- The vibe is closer to a personal page or newsletter signup than a social network
- Tier badges should be visually distinct and immediately readable (color + icon + label)
- Mobile-first — most subscribers will interact via phone
- FSE theme should feel polished but not over-designed; content-forward

### Tier Badge Design

| Tier | Color Direction | Icon Concept |
|------|----------------|--------------|
| Just an idea | Soft/neutral (gray, light blue) | Lightbulb or thought bubble |
| I'll go if you will | Warm/inviting (amber, soft orange) | Two people or handshake |
| I'm going — join me | Confident/active (green, teal) | Checkmark or pin |

### Activity Card Layout

```
┌──────────────────────────────────┐
│ [TIER BADGE]                     │
│                                  │
│ Activity Title                   │
│ Date & Time (if set)             │
│ Location name (if set)           │
│                                  │
│ 3 going · 1 maybe                │
│                                  │
│ [Going]  [Maybe]                 │
└──────────────────────────────────┘
```

### Poster Profile Page Layout

```
┌──────────────────────────────────┐
│ Display Name                     │
│ Bio text if provided             │
│                                  │
│ ── Upcoming ──────────────────── │
│                                  │
│ [Activity Card - Tier 3]         │
│ [Activity Card - Tier 2]         │
│ [Activity Card - Tier 1]         │
│                                  │
│ ── Past ─────────────────────── │
│                                  │
│ [Collapsed past activities]      │
└──────────────────────────────────┘
```

### Subscriber Dashboard Layout

```
┌──────────────────────────────────┐
│ Your Subscriptions               │
│                                  │
│ ── Coming Up ────────────────── │
│                                  │
│ [Activity Card] — via Sarah K    │
│ [Activity Card] — via Mike R     │
│ [Activity Card] — via Sarah K    │
│                                  │
│ ── Ideas & Interests ────────── │
│                                  │
│ [Tier 1 & 2 cards, less prominent│
│                                  │
│ ── Sidebar ──────────────────── │
│ [Your Subscriptions list]        │
│ [Notification Settings]          │
│ [Share your own activities →]    │
└──────────────────────────────────┘
```

---

## v1 Scope Summary

### Included

- WordPress site with FSE theme (Orbit *is* the site)
- Plugin: data layer, roles, REST API, WP-CLI commands, notification logic, Twilio integration, ActionScheduler jobs
- Agent-native architecture: full WP-CLI command set (`wp orbit`) and REST API with parity to all web UI actions, structured output, `wp orbit status` for agent context
- All users are WordPress accounts with `orbit_subscriber` and/or `orbit_poster` roles
- Poster profile creation and management
- Activity posting at three commitment tiers (date/time optional for all)
- Shareable subscription link with poster-controlled approval flow
- Subscriber signup with account creation, connection note, optional phone with verification
- Going/maybe responses (from notification links via token or from authenticated dashboard)
- SMS notifications for tier 3 (default), daily digest email for tiers 1-2 (default)
- Account-wide notification preferences (method per tier, digest time)
- Subscriber-controlled SMS daily cap (optional, no cap by default, overflow → digest, prompt after high-volume days)
- Unified subscriber dashboard across all subscriptions
- Attendee visibility controls (poster: none/count/names; subscriber: anonymous/visible, per-activity override)
- Activity-scoped action tokens for no-login responses; subscription secrets for unsubscribe
- Timezone: stored per-user, defaulted from poster, used for display and digest scheduling
- Poster upgrade CTA (subscriber → poster by adding role)
- `noindex` on activity pages and authenticated pages

### Deferred to Post-v1

- Per-subscription notification overrides (filter by tier per poster)
- "Things I'd do whenever" persistent list (tier 0)
- Local discovery opt-in (broadening activity visibility beyond subscribers)
- Social discovery features (interest matching, favorites lists)
- Activity comments or discussion threads
- Notification of activity edits after initial notification sent
- Multiple posters sharing a single profile/page
- Calendar integration (ICS export, Google Calendar add)
- Poster choice on profile page indexability
- Transactional email provider integration (start with SMTP, migrate to Postmark/Mailgun)

---

## Open Questions for Development

1. **Email sending for v1:** Start with A2's SMTP for the proof-of-concept phase. Plan migration to a transactional provider (Postmark recommended for deliverability + easy WordPress integration) once subscriber count makes deliverability matter. The plugin should abstract email sending behind a class so the switch is a configuration change, not a code change.

2. **Activity editing after notifications sent:** v1 defers this, but the data model supports it. When we add it, we'll need to decide: send update notifications? Only for material changes (date/time/location)? Only to people who responded going/maybe? Flag for post-v1 design.

3. **What happens when a poster deactivates or deletes their profile?** Subscribers should be notified and their subscription records soft-deleted. Need to define the exact UX. Defer detailed design to when it's needed, but the data model supports soft deletion via the `status` field.

4. **Action token expiry window:** Proposed default is 7 days after the activity's date (or 30 days after creation for dateless activities). Needs validation during development — too short and links break before people use them; too long and the security benefit diminishes.
