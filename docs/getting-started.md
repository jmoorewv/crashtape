# Getting Started

## Install

CrashTape is distributed independently rather than through the WordPress.org plugin
directory (see `readme.txt`), so installation is manual:

1. Copy the `crashtape` folder into `wp-content/plugins/`.
2. Activate it from **Plugins** in wp-admin, same as any other plugin.

Requirements: WordPress 6.4+, PHP 7.4+. No configuration is required to activate: every
capture channel only turns on for the duration of an active diagnostic session (see
[Diagnostics](diagnostics.md)), so there's no setup cost just from having the plugin active.

## Updating

CrashTape doesn't ship an in-dashboard update checker yet, so updates are manual: replace
the plugin's files with the new version's (upload a fresh copy of the `crashtape` folder
over the old one, or delete the old files and upload the new ones), without deleting the
plugin from the Plugins screen in between (a deactivate/reactivate cycle is fine; an
actual **Delete** isn't, since that's the one action that runs uninstall behavior:
by default this removes everything, unless you've checked "Keep my diagnostic data if
this plugin is deleted" under Settings first).

Everything CrashTape stores (settings, sessions, support packages) lives in the database,
not in the plugin's own files, so replacing the files doesn't touch any of it. Any schema
change the new version needs runs automatically on the very next page load after the
files are replaced (`Bootstrap::init()`'s own version check runs on every request, not
just on activation), so there's no separate migration step, no WP-CLI command, nothing
to trigger by hand.

## Start your first recording

1. Go to **Tools → CrashTape** (the Dashboard tab is shown by default).
2. Give the session a label if you want one (optional; helps you find it later in the
   **Dashboard** tab's **Recent Sessions** list).
3. Choose how you'll reproduce the problem:
   - **In this logged-in browser**: the session starts recording immediately in your
     current browser tab.
   - **As a logged-out visitor**: CrashTape gives you a one-time link (valid 15 minutes,
     single-use) to open in a private/incognito window. Opening it attaches that browser
     to the session without logging it into WordPress or granting it any access; it only
     sets a signed diagnostic cookie. Use this when the problem only happens for
     logged-out visitors, or for a specific non-admin role.
4. If the site's active theme might be part of the problem, you can optionally check the
   box to use the default theme instead of the active one for this diagnostic browser
   only. This only affects that one browser, never the live site for anyone else.
5. Click **Start Diagnostic Session**.

## Reproduce the problem

Just use the site normally in whichever browser is attached to the session. While a
session is active, CrashTape:

- Captures PHP warnings/notices/fatals, failed outbound HTTP requests, browser JS
  errors and failed fetch/XHR calls, mail failures, and REST/AJAX request metadata.
- Attributes each captured problem to the plugin, theme, or core file responsible where
  it can.

None of this instrumentation is active outside a session; it costs nothing on a normal
request.

If nothing seems to be getting captured after a couple of minutes, the admin UI will
warn you: see [Troubleshooting](troubleshooting.md#the-recorder-isnt-capturing-anything).

## Stop recording

Click **Stop Recording** on the Dashboard tab. CrashTape immediately:

- Runs its rule-based analysis and shows a "Most Likely Issue" (with a confidence level
  and the specific events/changes it cites as evidence) at the top of the Sessions tab.
- If you'd previously marked a session as a healthy baseline, compares this session
  against it and shows what's new.

## Review what happened

The **Sessions** tab shows the most recent session (whether it's still active or already
stopped; reload the page while a session is active to see events arrive in real time):

- The analysis result.
- The environment at the moment the session started (WP/PHP versions, active plugins,
  cache/cron status).
- Plugin/theme/core changes in the 24 hours before the session started (often the actual
  cause).
- The full event timeline, filterable by severity and channel, with a click-to-expand
  detail row on every event.

## Export a support package

Still on the Sessions tab (once the session is stopped), click **Generate Support
Package**. This builds a ZIP containing a manifest, the full timeline, per-channel
breakdowns, the environment snapshot, and a human-readable `summary.html`, already
redacted according to your privacy profile (see [Privacy](privacy.md)). Attach that ZIP
to a support ticket or hand it to whoever's debugging the issue.
