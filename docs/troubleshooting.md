# Troubleshooting CrashTape

## Quick decision guide

Where to look first, based on what the problem sounds like. All of these are the
Timeline's own real channel filter buttons and tab names, not general categories, so
these are exactly what you'll click.

| Problem sounds like... | Look at... |
|---|---|
| PHP fatal or warning | **PHP** channel filter, or the **Errors only** / **Warnings+** severity filter, on the Sessions tab's event table |
| Checkout / payment problem | **WooCommerce** channel + **HTTP** channel (the gateway's own API calls) + **Browser** channel (frontend checkout JS) |
| Contact form problem | **Forms** channel + **Mail** channel (if the form's own notification email failed) + **Browser** channel |
| Third-party API / integration problem | **HTTP** channel, plus the request detail's REST/AJAX metadata (namespace, auth status) on the affected request |
| Cache purge mid-session, or a cache-purge timing question | **Cache** channel |
| Background task (WP-Cron / Action Scheduler) problem | Dashboard's **Scheduled Tasks** row, and the **Correlation** column (Related/Nearby) on background events in the Sessions tab's event table |
| Problem started after an update | Sessions tab's **Recent Changes** section, or the "Changes in the X hours before this session started" detail (inside **Technical details**) on the specific session |
| Suspected plugin conflict | **Conflict Finder** tab |
| Need raw event IDs, request IDs, or the underlying JSON | **Developer Mode** (Settings tab) |
| A custom event from your own plugin (`crashtape_record_event()`) | **Custom** channel |

If nothing was captured at all regardless of channel, that's a different problem: see
the next section, not this table.

## The recorder isn't capturing anything

Start with **Diagnostics → Run Self-Test**: it directly checks the things most likely to be
wrong: database tables exist and are writable, the REST endpoint is reachable, the
retention cleanup cron is scheduled, the report-package directory is protected and
writable, the redaction engine actually masks a known secret, and `ZipArchive` is
available.

If self-test passes but a specific session still shows nothing:

- **No active session at all.** Capture only runs while a session is active. Check the
  Dashboard tab.
- **The wrong browser.** In visitor mode, only the browser that opened the one-time setup
  link is attached to the session; your own admin browser isn't, unless you also started
  an authenticated-mode recording.
- **An expired or already-used visitor link.** A visitor-mode setup link is single-use and
  expires after 15 minutes. A delayed click, a second click, or a chat/email client's own
  link-preview crawler opening it first can all silently consume it before the real
  visitor does. The Dashboard warns about this specifically for a visitor-mode session
  once the grace period below has passed.
- **A full-page cache.** See the next section: this is the single most common cause of
  "started a session, reproduced the problem, got zero events."
- **The grace-period warning hasn't fired yet.** The admin UI only warns about zero
  captured requests after 3 minutes, specifically so it doesn't fire before you've had a
  chance to browse anything.

## A cache/CDN is interfering

A fully cached page can be served without WordPress (and this recorder) ever running.

1. The visitor diagnostic link already appends a cache-bypass query parameter, which most
   page-cache plugins skip caching for by default.
2. For a caching plugin that supports excluding requests by cookie name, add
   `crashtape_diag` to that setting, e.g. WP Super Cache's real **Settings → Advanced →
   Rejected Cookies** field. The visitor setup screen shows this same tip with the exact
   cookie name.
3. Server-level or CDN-level caching (a reverse proxy in front of the whole site) is
   outside any WordPress plugin's reach entirely; that layer needs its own bypass rule,
   and no amount of WordPress-side configuration can substitute for one.

## Report generation is failing

"Generate Support Package" failing shows a clear error message right on the page, and
usually falls into one of these, each also separately recorded in **Diagnostics → Internal
Log** for later reference:

- `export_failure`: couldn't open or finalize the ZIP file. Almost always a filesystem
  permissions problem on the reports storage directory; self-test's "Report directory
  protected" check covers this.
- `storage_failure`: the storage directory itself isn't writable.
- `migration_failure`: a database schema upgrade didn't complete; self-test's "Database
  tables" and "Database writable" checks cover this.

The Internal Log is CrashTape's own operational log: failures in CrashTape itself, not
diagnostic data about your site. It only appears when there's actually something in it.

## The REST endpoint seems blocked

Self-test's "Browser recorder / REST endpoint reachable" check makes a real request to
CrashTape's own REST route and reports exactly what happened: a connection failure (DNS,
TLS, timeout) or `rest_no_route` (the route genuinely isn't registered, usually meaning
the whole REST API is disabled or blocked) both show up here directly, with the specific
error message.

Common causes: a security plugin or server rule disabling the WordPress REST API for
logged-out requests (which breaks visitor-mode recording specifically, since that
browser isn't authenticated), or a firewall/mod_security rule blocking `/wp-json/`
entirely. If self-test fails this check, that's where to look, not in CrashTape's own
capture logic, which never runs if the request never arrives.

## A security plugin seems to be conflicting

Use the [Conflict Finder](advanced-features.md#conflict-finder) the same way you would
for any other suspected plugin conflict: it isolates plugins for one diagnostic browser
without touching what's active for anyone else, so you can test with a security plugin
disabled-for-that-browser-only without actually turning off your site's real protection
for real visitors.

One thing to check first, since it's specific to security plugins: many block or rate-
limit the REST API. See the previous section before assuming it's something deeper.
