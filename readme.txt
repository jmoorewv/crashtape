=== CrashTape ===
Contributors: (none yet; not published to WordPress.org)
Tags: diagnostics, debugging, error logging, support, troubleshooting
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record a WordPress problem while it happens and generate the evidence support actually needs.

== Description ==

CrashTape is a WordPress diagnostic and support recorder. Instead of asking a customer or
teammate to describe a bug in words, you start a diagnostic session, reproduce the problem,
stop the session, and CrashTape gives you a correlated timeline of what actually happened;
PHP errors and fatals, failed outbound HTTP requests, browser JavaScript errors and failed
fetch/XHR calls, mail failures, and recent plugin/theme/core changes; all tied together by
session and request, with a rule-based analysis suggesting the most likely cause and its
confidence level.

CrashTape ships as one unified product. There is no free/pro tier split; every feature below
is available to everyone who installs it.

**This is a release candidate, not yet the final 1.0 release.** The full automated test
suite (420 tests, single-site and multisite), a real browser-based (E2E) test pass, and a
dedicated security/privacy review have all run clean against this exact build. It has not
been submitted to WordPress.org (this plugin is independently distributed) and hasn't yet
been through external beta testing; feature-frozen for the remainder of the release
candidate period, accepting release-blocker and important fixes only until 1.0.

= Core workflow =

1. Start a diagnostic session from Tools → CrashTape (as yourself, or hand a logged-out visitor
   a one-time link).
2. Reproduce the problem.
3. Stop the session.
4. Review the timeline (filterable, with a detail drawer on every event), the environment
   snapshot, recent changes, and the "Most Likely Issue" analysis; every conclusion cites the
   specific events and changes behind it.
5. Generate a support ZIP package to attach to a ticket, or compare the session against a known
   working session or your own marked baseline.

= What CrashTape captures during an active session =

* PHP warnings, notices, and fatal errors, attributed to the responsible plugin/theme where
  possible
* Failed outbound HTTP requests made by WordPress (`http_api_debug`)
* Browser JavaScript errors, unhandled promise rejections, and failed fetch/XHR calls
* REST and AJAX request metadata (route, namespace, coarse auth status, action name)
* Mail send failures (subject fingerprint and recipient domain only; never the message body
  or full subject)
* WooCommerce order failures, webhook delivery health, and outdated template overrides in the
  active theme (when WooCommerce is active)
* Contact Form 7 submission failures; validation, spam, and mail-send status (when CF7 is active)
* Cache-purge events (WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket) and whether a
  captured request carried the diagnostic cache-bypass flag
* The configured mail transport's mailer type, host, port, and encryption; never credentials
  (when WP Mail SMTP is active)
* A WordPress environment snapshot (versions, active plugins/theme, cache/HTTPS/cron status)
* WP-Cron overdue events and Action Scheduler pending/failed/overdue counts
* Plugin/theme/core updates, activations, deactivations, and deletions; independent of any
  active session
* Repeated identical errors are deduplicated to one entry with an occurrence count, not one row
  per repeat

= Diagnosing conflicts =

* A guided, binary-search conflict finder narrows ~40 active plugins down to the likely culprit
  in about six rounds, answering "is the problem still happening?" after each round
* Automated Mode drives the same rounds on its own using a URL-status check you define, instead
  of a manual answer every round
* Declared plugin dependencies (`Requires Plugins`) are automatically kept active alongside
  whatever's under test, so isolation never fatals a plugin's own required dependency
* An optional MU-loader component performs the actual isolation for one diagnostic browser only;
  the site's real active-plugins configuration is never modified
* Theme Isolation renders the diagnostic browser with the default theme instead of the site's
  real active theme, to tell a theme problem apart from a plugin problem; again without
  touching the site's real theme selection

= Privacy =

Secrets (API keys, tokens, passwords, Authorization/Cookie headers) and personal information
are redacted **before** anything is written to the database, not only when a report is
exported. Three privacy profiles control exactly how much is retained:

* **Strict** (default); email addresses and IP addresses fully masked, query strings omitted
  entirely
* **Balanced**; email domain retained, IP address coarsened, an admin-configured allowlist of
  query parameters retained
* **Advanced**; full email addresses, full IP addresses, and the complete query string
  retained, still scanned for recognizable secret patterns as defense in depth

Custom redaction rules (additional field names and regex patterns) extend the built-in
detection without replacing it.

= Extending CrashTape =

* `crashtape_register_diagnostic()`; register your own on-demand diagnostic check
* `crashtape_register_rule()`; register a custom analysis rule for the rule engine to consider
* `crashtape_record_event()`; record a custom event into the active session's timeline
* No-code reusable diagnostic checks (option equals a value, constant defined, URL responds with
  an expected status, plugin is active) manageable entirely from the admin screen
* `wp crashtape` WP-CLI commands: `status`, `snapshot`, `export <session>`, `cleanup`,
  `sessions list|show`, `diagnostics run`, `changes list`, `isolation status`

= Documentation =

Full documentation (a five-minute quick start, getting started, a plain-language privacy
statement plus the fuller privacy/redaction reference, per-channel diagnostics reference,
developer SDK/CLI/REST reference, advanced features, troubleshooting) lives in the
`docs/` folder shipped with the plugin.

= Other release-relevant behavior =

* Full multisite support; every table/option is per-site, and uninstalling loops every site
* Passive Monitoring Mode (optional, off by default) samples PHP fatals, mail failures, and
  outbound HTTP failures continuously between diagnostic sessions, independent of any session
* A built-in self-test checks the database schema, storage directory, redaction engine, ZIP
  support, and REST reachability
* An internal operational log (separate from diagnostic data) records CrashTape's own migration,
  storage, export, and integration-initialization failures
* Automatic scheduled cleanup deletes expired sessions and their data on a configurable
  retention period (1–365 days, 7 by default)
* Uninstalling the plugin removes every table, option, transient, and stored file it created,
  unless you check "Keep my diagnostic data if this plugin is deleted" under Settings first

== Installation ==

1. Upload the `crashtape` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen.
3. Go to Tools → CrashTape to start a diagnostic session.

== Frequently Asked Questions ==

= Does this require WP_DEBUG or SAVEQUERIES? =

No. CrashTape is designed to work without either.

= Does this replace Query Monitor or Site Health? =

No. CrashTape occupies a different position: support-oriented incident capture across a
browser → PHP → outbound HTTP chain, tied to one reproduction session, rather than an
always-on developer inspector.

= What happens to recorded data? =

Sessions expire after 7 days by default (configurable, 1–365 days) and are deleted
automatically; including their events, requests, and any generated support packages; by a
daily scheduled cleanup task, or immediately if you delete a session by hand.

= Does the conflict finder actually disable my plugins? =

No. An optional MU-loader component filters which plugins load for one specific diagnostic
browser's requests only; the site's real, stored active-plugins list is never changed, and
every other visitor sees every plugin active as normal throughout.

= Is CrashTape safe to use on a multisite network? =

Yes. Every table and option this plugin creates is per-site, and every operation that needs to
run network-wide (like uninstalling) loops through each site individually rather than assuming
a single shared installation.

= Which WordPress and PHP versions are supported? =

WordPress 6.4 and PHP 7.4 are the declared floor (see the plugin header above); PHP 7.4,
8.2, and 8.3 and WordPress 6.4, 6.7, and the current stable release are the versions this
plugin's automated test suite is run against before every release. Anything below 6.4/7.4
is unsupported and untested. A newer PHP than 8.3 (e.g. 8.4+) has no known incompatibility
but also isn't yet covered by that suite, so treat it as untested rather than unsupported.

= What if I don't want any query strings or IP addresses retained at all? =

That's the default (Strict profile). Balanced and Advanced retain progressively more, and are
both opt-in changes you make explicitly under the Privacy settings.

= The diagnostic browser isn't recording anything; why? =

A fully cached page can be served without WordPress PHP executing at all, so CrashTape never
runs. The visitor diagnostic link already appends a cache-bypass parameter that common caching
plugins honor when configured to do so, and the admin UI warns when a session has captured zero
requests after a grace period. For a caching plugin that supports excluding requests by cookie
name (WP Super Cache's Settings > Advanced > Rejected Cookies is one real example), add
`crashtape_diag` to skip the cache entirely for a diagnostic browser; the visitor setup screen
shows this same tip. Server-level or CDN-level caching (e.g. a reverse proxy in front of the
site) is outside any WordPress plugin's reach; that layer may need its own bypass rule for the
diagnostic session to be visible at all.

== Changelog ==

= 1.0.0-rc.1 =

CrashTape's first release candidate. Grouped by capability below rather than listed as a
commit-by-commit history; see this project's own git history for that level of detail.

**Diagnostic recording**
* Start a session in your own browser, or hand a logged-out visitor a one-time link
  (15 minutes, single-use) that attaches their browser without granting any access
* Optional theme isolation: render the diagnostic browser with the default theme instead
  of the site's active theme, without touching the real theme selection
* Zero cost outside an active session; every capture channel is session-scoped, not
  always-on logging

**Browser capture**
* JavaScript errors, unhandled promise rejections, and failed fetch/XHR calls

**PHP/error capture**
* Warnings, notices, and fatal errors, attributed to the responsible plugin, theme, or
  core file where possible

**HTTP/API diagnostics**
* Failed outbound HTTP requests made by WordPress or any plugin, with the query string
  always stripped from the captured URL

**REST/AJAX diagnostics**
* Route, namespace, and coarse auth status for REST requests; action name for AJAX
  requests

**Mail diagnostics**
* Send failures and successes, subject fingerprint and recipient domain only; mail
  reported as "accepted" is never claimed as "delivered"

**Scheduled task diagnostics**
* WP-Cron and Action Scheduler overdue/failed counts as part of the environment
  snapshot; background dispatches captured during a session and labeled Related/Nearby
  against whatever foreground activity they may connect to

**WooCommerce integration**
* Order failures captured in real time; webhook delivery health, outdated theme
  template overrides, and HPOS status as part of the environment snapshot

**Contact Form 7 integration**
* Submission failures; validation, spam, and mail-send status

**SMTP snapshot**
* The configured mail transport's mailer type, host, port, and encryption when WP Mail
  SMTP is active; never credentials

**Cache integration**
* Purge events from WP Super Cache, LiteSpeed Cache, W3 Total Cache, and WP Rocket, plus
  a cache-bypass status flag on every captured request

**Conflict Finder**
* Guided and Automated modes narrow ~40 active plugins to a likely culprit in about six
  rounds, isolating plugins for one diagnostic browser only; the site's real
  active-plugins configuration is never touched
* Declared plugin dependencies (`Requires Plugins` headers) are automatically kept active
  alongside whatever's under test

**Session comparison & baselines**
* Diff a failing session against a working one, or mark any session as a healthy
  baseline for automatic comparison going forward

**Passive Monitoring**
* Optional, off by default: rolling PHP/mail/HTTP health tracking between diagnostic
  sessions, independent of any session

**Developer SDK**
* `crashtape_register_diagnostic()`, `crashtape_register_rule()`, and
  `crashtape_record_event()` for third-party plugins to extend CrashTape's own
  diagnostics, analysis engine, and timeline

**REST API**
* A full session control plane (list/create/show/stop/export/download, run the
  self-test) for external tooling, authenticated the normal WordPress way

**WP-CLI**
* A `wp crashtape` command family: status, snapshot, export, cleanup, sessions,
  diagnostics, changes, isolation

**Report export**
* A one-click, already-redacted support package ZIP: full timeline, per-channel
  breakdowns, environment snapshot, and a human-readable summary

**Privacy/redaction**
* Redacted before storage, not just at export; one central redaction service, nothing
  invents its own redaction logic
* Three privacy profiles (Strict, Balanced, Advanced); no product telemetry, ever
* A Settings choice for what happens to your data if the plugin is deleted: full cleanup
  (default) or keep everything

**Accessibility**
* A filterable timeline with a click-to-expand detail row per event, fully
  keyboard-operable and screen-reader announced

**Security**
* A dedicated security/privacy review pass; a built-in self-test covering the database
  schema, storage directory, redaction engine, ZIP support, and REST reachability

**Also in this release**
* Full multisite support; every table and option is per-site
* Full documentation in `docs/`, plus a real visual identity and release/download page
* A standalone, plain-language Privacy Statement added (`docs/privacy-statement.md`), verified
  directly against the real code rather than described from memory: exactly three outbound
  request call sites exist anywhere in this plugin, none of them a CrashTape-owned server, and
  no telemetry/analytics code exists anywhere either
* 420 automated tests (up from 87 in the previous documented build)

= 0.2.0-dev =
* Internal development build. Session recording, PHP/HTTP/browser/mail capture, component
  attribution, a recent-changes journal, an environment snapshot, a rule-based analysis
  engine, support ZIP export, automatic retention cleanup, and a self-test are implemented.
