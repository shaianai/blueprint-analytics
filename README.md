# Blueprint Analytics

Admin-only consultant engagement analytics for Blueprint Collective.
Built by WYN Digital, Phase 1 (August 2026).

## What it does

Records three visitor interactions on consultant (`business`) profiles and
displays them per consultant in WP Admin → Blueprint Analytics.

| Event stored as | Triggered by |
|---|---|
| `profile_view` | Opening a single published consultant profile |
| `phone_click` | Clicking **Show Phone** (the reveal, NOT dialling) |
| `website_click` | Clicking the consultant's Website URL link |

## ⚠️ READ THIS FIRST: what silently breaks tracking

### 1. Rebuilding the Business Elementor template

The phone and website tracking attach to elements in the JetEngine
Business template:

- **Phone:** any link starting with `tel:`. Converted to a Show Phone
  button by JavaScript on page load. Works on both Single Location
  (Icon List) and Multiple Location (Dynamic Repeater) layouts.
- **Website:** any link with the CSS class `bpa-website-link`.
  This class must be present on the Website URL link in BOTH layouts.

**If the template is rebuilt and `bpa-website-link` is not carried over,
website clicks stop being recorded. No error appears.**

Check the Tracking Health panel at the top of the dashboard. It shows when
each event type was last recorded. A stale or "never recorded" line is
usually a template change, not a traffic drop.

### 2. WP Rocket "Delay JavaScript Execution"

If enabled, `bpa-tracker.js` **must** be in the exclusion list.
(WP Rocket → File Optimization → Delay JavaScript Execution → Excluded files)

If it is delayed: the Show Phone button never appears, and profile views
are lost for any visitor who does not interact with the page.

### 3. Security plugins

Wordfence and Security Optimizer can rate-limit or block REST API
requests. Tracking uses `POST /wp-json/blueprint-analytics/v1/event`.
If tracking stops after a security plugin change, check there first.

### Phone reveal tracking depends on EXTERNAL code

The Show Phone control is **not** part of this plugin. It is provided by
an existing inline snippet on the site. This plugin only listens for the
reveal click.

**If that snippet is removed, changed, or the button markup changes,
phone tracking stops silently.** Check the Tracking Health panel.

Detection works structurally (a click near a hidden `tel:` link), not by
class name, so it survives styling changes. It does NOT survive the
control being removed.

## Design decisions and why

| Decision | Reason |
|---|---|
| Custom table, not posts | Keeps `wp_posts` clean. ~5x fewer rows, no impact on site speed. |
| Recording via browser request, not on page load | The site uses WP Rocket. Cached pages never run PHP, so server-side counting would miss almost every view. |
| Unique views = **same calendar day** (not rolling 24h) | Approved as Option A. Enforced by a database constraint, so it cannot be bypassed by a coding error or two simultaneous requests. |
| Dates stored in **site timezone** (Australia/Sydney) | Date filtering needs no conversion. Trade-off: changing the site timezone would shift the meaning of historical rows. |
| Clicks are **never** deduplicated | Deliberate actions. Every one counts. Only `profile_view` uses the dedupe key. |
| No nonce on the tracking endpoint | A nonce would be cached and served stale to every visitor. Protection comes from allow-listed event types, validated consultant IDs, prepared queries and rate limiting. |
| No nonce on the dashboard | It is read-only. **Add nonces if you ever add export, delete, or settings.** |
| No summary/rollup table | Unnecessary below ~10 million rows. Revisit if the dashboard exceeds ~1 second with the cache bypassed. |

## Known limitations

- **Logged-in Customer/Subscriber traffic IS counted.** Approved as
  Option A. A consultant viewing their own profile adds a view (capped at
  one per day by the dedupe rule). Excluding this needs a link between
  user accounts and consultant records.
- **Only Administrator and Author are excluded** (anyone with `edit_posts`).
- **Bot filtering is user-agent based.** Catches obvious crawlers. A bot
  that impersonates a browser and runs JavaScript will be counted.
- **Clicks on directory/listing cards are not tracked.** Only single
  profile pages. Per approved requirement 10.08.
- **The About Us button is not tracked.** Per client decision, only the
  Website URL link.
- **Phone numbers are visible in the page source.** The reveal is a
  JavaScript convenience, not scraper protection. A shortcode version
  with encoding exists in `includes/class-bpa-phone.php` but is
  **disabled** (see `blueprint-analytics.php`). Re-enable by
  uncommenting one `add_action` line if scraper protection is wanted.
- **`phone_click` records the reveal, not dialling.** Desktop visitors who
  read the number without clicking it are counted; that is intended.

## Data stored

Per event: consultant ID, event type, anonymised visitor fingerprint,
date, timestamp, page path.

**Not stored:** IP addresses, browser identifiers, referrers, or anything
identifying a person. The visitor fingerprint is a one-way hash that
includes the current date, so the same person produces a different
fingerprint each day and cannot be tracked across days.

## Retention

Events older than **12 months** are deleted daily.
Change `BPA_Retention::RETENTION_MONTHS`.

## Files

| File | Purpose |
|---|---|
| `blueprint-analytics.php` | Loads everything, activation, hooks |
| `includes/class-bpa-database.php` | Table creation and writes |
| `includes/class-bpa-tracker.php` | Rules, endpoint, script loading |
| `includes/class-bpa-admin.php` | Menu, queries, dashboard |
| `includes/class-bpa-list-table.php` | The results table |
| `includes/class-bpa-retention.php` | Scheduled cleanup |
| `includes/class-bpa-phone.php` | **Disabled.** Shortcode reveal with encoding. |
| `assets/js/bpa-tracker.js` | All browser-side tracking |
| `assets/css/bpa.css` | Minimal styling |

## Changing the table structure later

1. Edit the SQL in `BPA_Database::install()`
2. Increment `BPA_DB_VERSION` in `blueprint-analytics.php`
3. Load any page. The update applies automatically.

Note: `dbDelta()` can add columns and indexes but cannot remove them.

## Five-minute verification test

Anyone can run this. No technical knowledge needed.

1. Open a consultant profile in a **private/incognito** window
2. Open WP Admin → Blueprint Analytics, click **Refresh data**
3. That consultant's Profile Views should have increased by 1
4. Reload the profile 3 times in the same private window
5. Refresh the dashboard. **Views should NOT have increased.**
6. In the private window, click **Show Phone**
7. Refresh. Phone Interactions +1
8. Click the consultant's **Website** link
9. Refresh. Website Clicks +1

If any step fails, see "what silently breaks tracking" above.