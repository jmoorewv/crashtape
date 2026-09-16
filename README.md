# CrashTape

**WordPress Diagnostic & Support Recorder.** Reproduce it. Record it. Fix it.

Instead of asking a customer or client to describe what went wrong, start a diagnostic
session, reproduce the problem, and stop it. CrashTape correlates everything that
happened, across the browser, PHP, and outbound HTTP, into one timeline for that session
and request, with a rule-based analysis suggesting the most likely cause and its
confidence level.

CrashTape ships as one unified product; there is no free/pro tier split. Every feature
below is available to everyone who installs it.

> **Release candidate.** Not yet the final 1.0 release. The full automated test suite
> (420 tests, single-site and multisite), a real browser-based (E2E) test pass, and a
> dedicated security/privacy review have all run clean against this exact build.
> Feature-frozen for the remainder of the release candidate period, accepting
> release-blocker and important fixes only until 1.0. CrashTape is not on the
> WordPress.org plugin directory; it's distributed independently, from this
> repository's [Releases](../../releases) page once a release is published.

## Core workflow

1. Start a diagnostic session from **Tools → CrashTape** (as yourself, or hand a
   logged-out visitor a one-time link).
2. Reproduce the problem.
3. Stop the session.
4. Review the timeline (filterable, with a detail drawer on every event), the
   environment snapshot, recent changes, and the "Most Likely Issue" analysis; every
   conclusion cites the specific events and changes behind it.
5. Generate a support ZIP to attach to a ticket, or compare the session against a known
   working session or your own marked baseline.

## What it captures during an active session

- PHP warnings, notices, and fatal errors, attributed to the responsible plugin/theme
  where possible
- Failed outbound HTTP requests made by WordPress (`http_api_debug`)
- Browser JavaScript errors, unhandled promise rejections, and failed fetch/XHR calls
- REST and AJAX request metadata (route, namespace, coarse auth status, action name)
- Mail send failures (subject fingerprint and recipient domain only; never the message
  body or full subject)
- WooCommerce order failures, webhook delivery health, and outdated template overrides
  in the active theme (when WooCommerce is active)
- Contact Form 7 submission failures; validation, spam, and mail-send status (when CF7
  is active)
- Cache-purge events (WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket) and
  whether a captured request carried the diagnostic cache-bypass flag
- The configured mail transport's mailer type, host, port, and encryption; never
  credentials (when WP Mail SMTP is active)
- A WordPress environment snapshot (versions, active plugins/theme, cache/HTTPS/cron
  status)
- WP-Cron overdue events and Action Scheduler pending/failed/overdue counts
- Plugin/theme/core updates, activations, deactivations, and deletions; independent of
  any active session
- Repeated identical errors are deduplicated to one entry with an occurrence count, not
  one row per repeat

## Diagnosing conflicts

- A guided, binary-search conflict finder narrows ~40 active plugins down to the likely
  culprit in about six rounds
- Automated Mode drives the same rounds on its own using a URL-status check you define
- Declared plugin dependencies (`Requires Plugins`) stay active alongside whatever's
  under test, so isolation never fatals a plugin's own required dependency
- An optional MU-loader component performs the actual isolation for one diagnostic
  browser only; the site's real active-plugins configuration is never modified
- Theme Isolation renders the diagnostic browser with the default theme instead of the
  site's real active theme, to tell a theme problem apart from a plugin problem, again
  without touching the site's real theme selection

## Privacy

Secrets (API keys, tokens, passwords, `Authorization`/`Cookie` headers) and personal
information are redacted **before** anything is written to the database, not only when a
report is exported. Three privacy profiles control exactly how much is retained:

- **Strict** (default): email addresses and IP addresses fully masked, query strings
  omitted entirely
- **Balanced**: email domain retained, IP address coarsened, an admin-configured
  allowlist of query parameters retained
- **Advanced**: full email addresses, full IP addresses, and the complete query string
  retained, still scanned for recognizable secret patterns as defense in depth

Custom redaction rules (additional field names and regex patterns) extend the built-in
detection without replacing it. See [docs/privacy-statement.md](docs/privacy-statement.md)
for a plain-language explanation, or [docs/privacy.md](docs/privacy.md) for the technical
detail.

## Extending CrashTape

- `crashtape_register_diagnostic()`: register your own on-demand diagnostic check
- `crashtape_register_rule()`: register a custom analysis rule for the rule engine to
  consider
- `crashtape_record_event()`: record a custom event into the active session's timeline
- No-code reusable diagnostic checks (option equals a value, constant defined, URL
  responds with an expected status, plugin is active), manageable entirely from the
  admin screen
- `wp crashtape` WP-CLI commands: `status`, `snapshot`, `export <session>`, `cleanup`,
  `sessions list|show`, `diagnostics run`, `changes list`, `isolation status`

See [docs/developers.md](docs/developers.md) for the full Diagnostics SDK, Event SDK,
rule registry, WP-CLI commands, REST API, and support-package schema.

## Installation

CrashTape isn't on the WordPress.org plugin directory, so installation is manual:

1. Download `crashtape-<version>.zip` from this repository's
   [Releases](../../releases) page.
2. In wp-admin, go to **Plugins → Add New → Upload Plugin** and upload the ZIP, or
   extract it into `wp-content/plugins/crashtape/` over (S)FTP.
3. Activate **CrashTape**.

Updates are manual for 1.0: download the new version and upload over the existing
install (deactivating first isn't required — any needed database migration runs
automatically on the next request). See
[docs/getting-started.md](docs/getting-started.md#updating) for the full process.

**Requirements:** WordPress 6.4+, PHP 7.4+.

## Documentation

- [CrashTape in Five Minutes](docs/five-minutes.md): the whole workflow, no background
  reading required
- [Getting Started](docs/getting-started.md): install, record your first session,
  export a support package
- [Privacy Statement](docs/privacy-statement.md): what this plugin does with your
  site's data, written for a site owner
- [Privacy](docs/privacy.md): the same territory in technical/configuration detail
- [Diagnostics](docs/diagnostics.md): what each capture channel actually records, plus
  the no-code Custom Checks feature
- [Developers](docs/developers.md): SDKs, rule registry, WP-CLI, REST API, support
  package schema
- [Advanced Features](docs/advanced-features.md): Conflict Finder, isolation, session
  comparison, baselines, third-party integrations
- [Troubleshooting](docs/troubleshooting.md): capture not working, cache/CDN
  interference, export failures, REST blocked, security-plugin conflicts

## Quality

Every release is checked against WordPress Coding Standards + PHPCompatibilityWP, an
automated test suite (420 tests, single-site and multisite), and a real browser-based
end-to-end pass, across PHP 7.4/8.2/8.3 and WordPress 6.4/6.7/current-stable, before it's
published here. This repository ships the built plugin only; it doesn't include the test
suite or build tooling used to produce it.

## Issues

Found a bug or have a feature request? [Open an issue](../../issues).

## License

GPLv2 or later. See [LICENSE](LICENSE).
