# CrashTape Documentation

CrashTape is a WordPress diagnostic and support recorder: start a session, reproduce a
problem, stop the session, and get a correlated timeline of what actually happened, plus
a rule-based "most likely issue" analysis and a support package you can attach to a
ticket.

This is a release candidate (see `readme.txt`), feature-frozen and accepting only
release-blocker and important fixes until 1.0. These docs describe what's actually built
and tested, not aspirational features.

## Contents

- [CrashTape in Five Minutes](five-minutes.md): the whole workflow, no background
  reading required.
- [Getting Started](getting-started.md): install, record your first session, export a
  support package.
- [Privacy Statement](privacy-statement.md): a plain-language answer to "what does this
  plugin do with my site's data," written for a site owner rather than a developer.
- [Privacy](privacy.md): the same territory in more technical/configuration detail, what's
  recorded, what's never recorded, redaction profiles, retention, and how session data
  goes away.
- [Diagnostics](diagnostics.md): what each capture channel (PHP, browser, HTTP, REST,
  AJAX, mail, cron, Action Scheduler) actually records, plus the no-code Custom Checks
  feature.
- [Developers](developers.md): the Diagnostics SDK, the Event SDK, the rule registry,
  the WP-CLI commands, the REST API, and the support-package schema.
- [Advanced Features](advanced-features.md): Conflict Finder, theme/plugin isolation,
  session comparison and baselines, third-party integrations, and what "Alerts" means
  today (it isn't built).
- [Troubleshooting](troubleshooting.md): the recorder isn't capturing anything, cache/CDN
  interference, support package generation failures, the REST endpoint being blocked, and
  security-plugin conflicts.

## Where things live in the admin UI

Everything is under **Tools → CrashTape**, organized into five tabs: Dashboard, Sessions,
Diagnostics, Conflict Finder, and Settings. All tab content is always in the page; it's
one admin page (not five separate wp-admin submenu items), though a `?tab=` URL
parameter can select which one starts active, e.g. what Stop Recording's own redirect
uses to land you on Sessions afterward.
