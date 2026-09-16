# Privacy

For a plain-language, non-technical answer to "what does this plugin do with my site's
data," see the [Privacy Statement](privacy-statement.md) instead; this document covers
the same ground with more configuration/implementation detail.

Redaction happens once, centrally, before anything is written to the database: every
capture channel goes through the same `Redactor` service. Nothing invents its own
redaction logic. There's no way to capture something first and redact it "at export
time"; if it isn't safe to store, it was never stored.

## Never captured, regardless of profile

- Passwords
- `Authorization` and `Cookie` headers
- Request/response bodies
- Form field values
- Email bodies
- Raw stack-trace arguments
- Any captured field whose name matches a known or admin-defined secret pattern (see
  custom rules, below), checked against every event's data, not just a fixed list

## Privacy profiles

One of three profiles applies to every session, site-wide (**Settings** tab). **Strict**
is the default.

| | Strict (default) | Balanced | Advanced |
|---|---|---|---|
| Email addresses | Redacted entirely | Domain only (e.g. `[redacted]@example.com`) | Retained in full |
| Query strings | Omitted entirely | Only allowlisted parameters (configurable below) | Every parameter retained |
| IP addresses | Fully masked | Coarsened (last IPv4 octet zeroed) | Retained in full |

Values are still scanned for recognizable secret patterns under every profile. Advanced
retaining more by default isn't a substitute for Strict/Balanced on a site with genuinely
sensitive query data.

### Query parameter allowlist

Under Balanced, only parameters you've explicitly listed (one per line, in Settings) are
ever retained from a query string, e.g. `page`, `s`, `orderby`. Everything else is
stripped regardless. This list is ignored under Strict (nothing is retained) and under
Advanced (everything is retained).

### Custom redaction rules

Beyond the built-in never-capture list, Settings lets you add:

- **Custom keys**: array/object key names (e.g. a field your own plugin calls
  `license_key`) whose values get masked wherever they appear in captured data.
- **Custom patterns**: your own regular expressions, for a secret shape the built-in
  rules don't already recognize.

Both apply on top of whichever privacy profile is active, not instead of it.

## Retention

Sessions and their support packages share one retention window, configurable from 1 to
365 days (default 7, in Settings). A daily background job deletes anything past its
`expires_at`; an **active** session is never deleted regardless of age, only completed
ones.

## Deleting session data

There's no manual "delete this session" button today; deletion happens automatically
once a session's retention window passes. Deleting a session cascades to everything tied
to it (its requests, events, and any built support packages), since none of that data is
meaningful without the session that gives it context.

To remove everything immediately: uninstalling the plugin (not just deactivating it)
drops every CrashTape table and option, looping through each site individually on a
multisite network rather than assuming a single shared installation. This is the default;
checking "Keep my diagnostic data if this plugin is deleted" under Settings first skips
this cleanup entirely, so uninstalling only removes the plugin's files.

## What ends up in a support package

Everything in an exported ZIP has already passed through the same redaction as the
database itself; a support package is not a separate place secrets could leak through.
See [Developers → Report Schema](developers.md#report-schema) for exactly what files it
contains.
