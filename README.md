# Tourfic iCal Integration

A WordPress plugin that exposes a subscribable iCal feed of tour bookings made through the [Tourfic](https://tourfic.com/) plugin.

## What it does

- Reads completed bookings from the Tourfic database table (`{prefix}tf_order_data`)
- Groups bookings by tour and date — multiple bookings on the same tour/date become a single calendar event
- Shows full visitor details for every booking in the event description
- Picks up the tour start time and duration from the post meta, so events appear at the correct time in your calendar (falls back to all-day if no time is set)
- Secured with a secret token URL — only someone with the link can subscribe

## Installation

1. Copy `tourfic-ical.php` into a new folder on your server:
   ```
   wp-content/plugins/tourfic-ical/tourfic-ical.php
   ```
2. Go to **WP Admin → Plugins** and activate **Tourfic iCal Integration**.
3. Go to **Settings → Tourfic iCal** and copy the subscribe URL.
4. Paste the URL into any iCal-compatible calendar app as a subscribed calendar.

## Calendar app setup

| App | How to subscribe |
|-----|-----------------|
| Google Calendar | Other calendars → + → From URL |
| Apple Calendar | File → New Calendar Subscription |
| Thunderbird | New Calendar → On the Network → iCalendar (ICS) |
| Outlook | Add calendar → Subscribe from web |

## Feed behaviour

- Shows bookings with status **completed** only
- Includes events from **30 days in the past** through all future dates
- Event title: `Tour Name (N visitors)`
- Event time: taken from the booking's selected time slot, or from the tour's availability schedule in the post meta; falls back to all-day if neither is set
- Event duration: taken from the tour's duration field in the post meta; defaults to 1 hour if a start time is set but no duration is found

## Security

The feed URL contains a random 32-character token. Anyone with the URL can read the feed, so treat it like a password. Use **Regenerate Token** on the settings page if the URL is ever compromised — existing subscriptions will need to be updated with the new URL.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Tourfic plugin (provides the `{prefix}tf_order_data` table and `tf_tours_opt` post meta)
