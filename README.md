# Orbit

Person-centric social activity tool for WordPress. Share what you're doing, let people subscribe, and coordinate lightweight going/maybe responses via SMS and email.

Orbit is a WordPress plugin that provides the data layer, business logic, REST API, WP-CLI commands, and notification system. A companion FSE theme (separate repo) handles rendering.

## How It Works

A **poster** creates a profile and shares a link. People who receive the link become **subscribers**. When the poster broadcasts an activity at one of three commitment tiers, subscribers get notified via SMS or email based on their preferences. Subscribers respond with "going" or "maybe" -- no app required.

### Commitment Tiers

| Tier | Meaning | Default Notification |
|------|---------|---------------------|
| 1 | Just an idea | Daily digest email |
| 2 | I'll go if you will | Daily digest email |
| 3 | I'm going -- join me | SMS |

## Requirements

- WordPress 6.4+
- PHP 8.0+
- Composer (for ActionScheduler dependency)
- Twilio account (for SMS notifications)
- Configured SMTP (for email notifications)

## Installation

1. Clone or copy the plugin into `wp-content/plugins/orbit/`
2. Install Composer dependencies:
   ```
   cd wp-content/plugins/orbit
   composer install
   ```
3. Activate the plugin in WordPress admin (Plugins > Activate)

On activation, Orbit automatically:
- Creates 7 custom database tables (`orbit_profiles`, `orbit_subscriptions`, `orbit_activities`, `orbit_responses`, `orbit_notification_preferences`, `orbit_notification_log`, `orbit_phone_verification`)
- Registers two roles: `orbit_subscriber` and `orbit_poster`
- Creates WordPress pages for all authenticated routes (Dashboard, Settings, Manage, etc.)
- Registers ActionScheduler recurring jobs

## Configuration

Add these constants to `wp-config.php`:

```php
// Required for SMS notifications
define( 'ORBIT_TWILIO_ACCOUNT_SID', 'your_account_sid' );
define( 'ORBIT_TWILIO_AUTH_TOKEN', 'your_auth_token' );
define( 'ORBIT_TWILIO_FROM_NUMBER', '+1234567890' );

// Optional (shown with defaults)
define( 'ORBIT_NOTIFICATION_LOG_RETENTION', 90 );   // Days to keep notification logs
define( 'ORBIT_DEFAULT_DIGEST_TIME', '18:00' );      // Default digest delivery time
define( 'ORBIT_PHONE_VERIFY_EXPIRY', 600 );           // Verification code TTL in seconds
define( 'ORBIT_PHONE_VERIFY_MAX_ATTEMPTS', 3 );       // Max code entry attempts
define( 'ORBIT_RATE_LIMIT_SUBSCRIBE', 5 );             // Subscription requests per IP per hour
```

## Getting Started

After activating the plugin, here's how to verify it's working and set up your first poster.

### 1. Verify Tables and Roles

Using WP-CLI:

```
wp orbit status --format=json
```

This returns a system overview: table counts, configuration state, Twilio/SMTP status, and recent activity. If the tables were created correctly, all counts will be 0.

Or check manually:
- In phpMyAdmin or your DB client, look for 7 tables prefixed with `wp_orbit_`
- In WordPress admin > Users, the Roles dropdown should show "Orbit Subscriber" and "Orbit Poster"

### 2. Create a Poster Profile

A poster is a WordPress user with the `orbit_poster` role. Create one via WP-CLI:

```
# First, assign the poster role to an existing user (user ID is positional)
wp orbit subscriber set-role 1

# Then create their profile
wp orbit profile create --user_id=1 --slug="sarah-k" --display_name="Sarah K" --bio="Weekend adventurer"
```

The `create` command returns the profile as JSON, including the generated `share_token`. The poster's public URL is `/@sarah-k` and their subscription link is `/@sarah-k/subscribe?token={share_token}`.

### 3. Share the Subscription Link

The poster shares their link however they like -- text, email, QR code, etc. When someone visits the link:

1. If not logged in: they create a WordPress account (name, email, password, optional phone)
2. If already logged in: the form is pre-filled, just confirm
3. They can add a connection note ("How do you know this person?")
4. If the poster requires approval, the subscription starts as `pending`

### 4. Approve Subscribers (if applicable)

```
# List pending subscriptions
wp orbit subscription list --profile_id=1 --status=pending

# Approve one
wp orbit subscription approve 42
```

### 5. Create an Activity

```
wp orbit activity create \
  --profile_id=1 \
  --tier=3 \
  --title="Saturday morning bike ride" \
  --description="Meeting at the park entrance, 15-mile loop" \
  --location_name="Riverside Park" \
  --location_address="100 River Rd" \
  --date_time="2026-04-19 09:00:00" \
  --show_attendees=names
```

This immediately queues notifications for all approved subscribers based on their tier preferences.

### 6. Check Responses

```
wp orbit activity responses 1 --format=table
```

## URL Routes

Orbit registers custom rewrite rules for clean URLs:

| URL | What It Shows |
|-----|---------------|
| `/@{slug}` | Poster's public profile page |
| `/@{slug}/subscribe?token={share_token}` | Subscription signup form |
| `/activity/{id}` | Activity detail page |
| `/activity/{id}?act={action_token}` | Activity page with no-login token access |
| `/unsubscribe?token={subscription_secret}` | One-click unsubscribe (no login required) |

### Authenticated Pages

These WordPress pages are created on activation with shortcodes:

| Path | Role Required | Shortcode |
|------|--------------|-----------|
| `/dashboard` | Subscriber | `[orbit_dashboard]` |
| `/dashboard/settings` | Any logged-in user | `[orbit_settings]` |
| `/manage` | Poster | `[orbit_manage]` |
| `/manage/new` | Poster | `[orbit_new_activity]` |
| `/manage/subscribers` | Poster | `[orbit_subscribers]` |
| `/manage/profile` | Poster | `[orbit_edit_profile]` |

## Notifications

### How Routing Works

When an activity is created, the system checks each approved subscriber's notification preferences for that tier:

1. **SMS** -- sent immediately via Twilio. Message includes a one-tap action token link.
2. **Email** -- sent immediately via `wp_mail()`. HTML + plain text with RSVP links.
3. **Digest** -- batched into the subscriber's next daily digest email.
4. **None** -- no notification sent.

### SMS Daily Cap

Subscribers can set an optional daily SMS limit. If exceeded, remaining notifications overflow to the next digest. After a high-volume day (3+ SMS without a cap set), the dashboard prompts the subscriber to set a cap.

### Daily Digest

- Delivered at the subscriber's preferred time in their timezone (default: 6:00 PM)
- Groups activities by poster, sorted by tier (highest first) then date
- Only includes activities posted since the last digest
- Skips sending if there's nothing new

### Phone Verification

Before SMS notifications work, subscribers must verify their phone number:

1. Enter phone number (E.164 format, e.g., `+12125551234`)
2. Receive a 6-digit code via SMS
3. Enter the code (3 attempts max, expires in 10 minutes)
4. On success, SMS notifications are enabled
5. Changing the phone number resets verification

### Twilio Webhooks

Orbit handles incoming Twilio messages at `POST /wp-json/orbit/v1/twilio/incoming`:
- **STOP** -- opts user out of all SMS (TCPA compliance)
- **START** -- re-enables SMS notifications

## REST API

All endpoints are under `/wp-json/orbit/v1/`.

### Public (No Auth Required)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/subscribe` | Subscribe with valid `share_token` |
| POST | `/unsubscribe` | Unsubscribe with `subscription_secret` |
| POST | `/respond` | RSVP via action token |
| POST | `/twilio/incoming` | Twilio webhook (signature validated) |

### Subscriber (Logged In)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/activities` | List activities from subscribed posters |
| GET | `/subscriptions` | List current user's subscriptions |
| POST | `/respond` | RSVP as logged-in user |
| DELETE | `/respond` | Remove a response |
| PATCH | `/preferences` | Update notification preferences |
| POST | `/verify-phone` | Submit phone verification code |

### Poster (orbit_poster role)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/activities` | Create activity |
| PATCH | `/activities/{id}` | Update activity |
| DELETE | `/activities/{id}` | Cancel activity |
| GET | `/activities/{id}/responses` | View responses |
| GET | `/subscribers` | List profile's subscribers |
| PATCH | `/subscribers/{id}` | Approve / deny / remove |

### Admin

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/profiles` | List all profiles |
| POST | `/profiles` | Create profile |
| PATCH | `/profiles/{id}` | Update profile |
| DELETE | `/profiles/{id}` | Delete profile |
| POST | `/profiles/{id}/regenerate-token` | Regenerate share token |
| GET | `/status` | System status |
| GET | `/notifications` | Notification log |

## WP-CLI Commands

All commands are under the `wp orbit` namespace. All list commands support `--format=json|csv|table`. Mutations output the affected record as JSON.

### System

```
wp orbit status                          # System overview
wp orbit status --format=json            # Machine-readable status
```

### Profiles

```
wp orbit profile create --user_id=1 --slug="sarah-k" --display_name="Sarah K"
wp orbit profile get 1
wp orbit profile get --slug="sarah-k"
wp orbit profile update 1 --bio="Updated bio"
wp orbit profile delete 1               # Soft delete (deactivates, notifies subscribers)
wp orbit profile delete 1 --force       # Hard delete
wp orbit profile list
wp orbit profile list --user_id=1
wp orbit profile regenerate-token 1     # Invalidates old share links
```

### Activities

```
wp orbit activity create --profile_id=1 --tier=3 --title="Bike ride" --date_time="2026-04-19 09:00:00"
wp orbit activity get 1
wp orbit activity update 1 --title="Updated title"
wp orbit activity cancel 1
wp orbit activity list --profile_id=1 --status=active --tier=3
wp orbit activity list --after="2026-04-01" --before="2026-04-30"
wp orbit activity responses 1
```

### Subscriptions (Poster's View)

```
wp orbit subscription list --profile_id=1
wp orbit subscription list --profile_id=1 --status=pending
wp orbit subscription approve 42
wp orbit subscription deny 42
wp orbit subscription remove 42
wp orbit subscription create --user_id=2 --profile_id=1            # Subscribe a user directly
```

### Subscribers (Subscriber's View)

```
wp orbit subscriber subscriptions 2                   # user_id is positional
wp orbit subscriber get 2
wp orbit subscriber set-preferences 2 --tier1_method=digest --tier2_method=digest --tier3_method=sms --sms_daily_cap=3 --digest_time=18:00
wp orbit subscriber set-role 2
```

### Responses

```
wp orbit response set --subscription_id=1 --activity_id=1 --response=going
wp orbit response set --subscription_id=1 --activity_id=1 --response=maybe
wp orbit response remove 1                            # response ID is positional
wp orbit response list 2                              # user_id is positional
```

### Notifications

```
wp orbit notification send-digest 2               # user_id is positional
wp orbit notification preview-digest 2            # Dry run
wp orbit notification log --user_id=2 --method=sms --status=failed
```

## Token System

Orbit uses three types of tokens:

| Token | Format | Purpose | Lifetime |
|-------|--------|---------|----------|
| Share token | 32-char random string | Subscription links | Until regenerated |
| Subscription secret | 32-char random string | Unsubscribe links, action token seed | Permanent per subscription |
| Action token | HMAC-SHA256 | No-login RSVP links in notifications | 7 days after activity date (30 days if dateless) |

## Privacy and Visibility

Orbit defaults to the most restrictive settings:

- **Attendee visibility**: Poster controls per-activity whether responses show as `none`, `count`, or `names`
- **Subscriber visibility**: Each subscriber sets a default (anonymous or visible), with per-activity overrides
- **Location address**: Only shown to approved subscribers
- **Activity pages**: Marked `noindex, nofollow`
- **Robots.txt**: Blocks `/activity/`, `/dashboard/`, `/manage/`

## Background Jobs

Orbit uses ActionScheduler for async processing:

| Job | Schedule | Purpose |
|-----|----------|---------|
| `orbit_send_immediate_notification` | One-off per activity | Route and send SMS/email |
| `orbit_send_daily_digest` | Recurring per user | Compile and send digest at preferred time |
| `orbit_mark_past_activities` | Daily | Mark activities with past dates as `past` |
| `orbit_cleanup_notification_log` | Weekly | Prune old notification log entries |

## Database

Orbit creates 7 custom tables (all prefixed with `$wpdb->prefix`):

- `orbit_profiles` -- poster accounts with slug, share token, approval setting
- `orbit_subscriptions` -- user-to-poster relationships with status lifecycle
- `orbit_activities` -- posted activities with tier, date, location, visibility
- `orbit_responses` -- going/maybe responses (unique per activity + subscription)
- `orbit_notification_preferences` -- per-user tier routing and digest timing
- `orbit_notification_log` -- sent notification records for deduplication and debugging
- `orbit_phone_verification` -- verification codes with expiry and attempt tracking

Additionally, three `wp_usermeta` entries per user:
- `orbit_phone` -- E.164 phone number
- `orbit_phone_verified` -- 1 or 0
- `orbit_timezone` -- IANA timezone string

## Roles and Capabilities

| Role | Capabilities |
|------|-------------|
| `orbit_subscriber` | read, subscribe, respond, manage preferences, view activities |
| `orbit_poster` | All subscriber caps + create/manage activities, manage profile, manage subscribers |

Users can hold both roles simultaneously. Subscribing adds `orbit_subscriber`; becoming a poster adds `orbit_poster` without replacing the subscriber role.

## Development

### Running Tests

```
vendor/bin/phpunit
```

Runs 30 tests covering token security, subscription lifecycle, response idempotency, and notification routing.

### Project Structure

```
orbit/
  orbit.php                    # Plugin bootstrap, constants, hooks
  includes/
    class-orbit-activator.php  # Table creation, page creation
    class-orbit-roles.php      # Role and capability registration
    class-orbit-profile.php    # Profile CRUD
    class-orbit-activity.php   # Activity CRUD
    class-orbit-subscription.php # Subscription management
    class-orbit-response.php   # Response handling
    class-orbit-privacy.php    # Visibility resolution
    class-orbit-token.php      # Token generation and validation
    class-orbit-notifier.php   # Notification dispatch and digest
    class-orbit-twilio.php     # Twilio API wrapper
    class-orbit-phone-verify.php # Phone verification flow
    class-orbit-rest-api.php   # REST API base + public routes
    class-orbit-rest-subscription.php
    class-orbit-rest-activity.php
    class-orbit-rest-profile.php
    class-orbit-rest-notification.php
    class-orbit-rate-limiter.php
    class-orbit-routes.php     # Custom rewrite rules
    class-orbit-shortcodes.php # All 10 shortcodes
  cli/
    class-orbit-cli-*.php      # 7 WP-CLI command classes
  tests/
    OrbitTokenTest.php
    OrbitSubscriptionTest.php
    OrbitResponseTest.php
    OrbitNotifierTest.php
```
