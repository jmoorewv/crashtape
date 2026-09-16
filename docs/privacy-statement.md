# CrashTape Privacy Statement

A plain-language explanation of what CrashTape does with data on your site, written for
a site owner, not a developer. For the technical detail behind each answer below (the
exact redaction rules, how to configure privacy profiles, what a support package
contains), see [Privacy](privacy.md).

## What CrashTape records

While a diagnostic session is active, CrashTape can record:

- PHP warnings, notices, and fatal errors
- Failed requests your site makes to other services (payment gateways, APIs, etc.)
- JavaScript errors in the browser that's attached to the session
- Failed email sends
- Metadata about REST API and AJAX requests (which endpoint, whether the visitor was
  logged in, never the actual data sent or received)
- A snapshot of your site's environment (WordPress/PHP versions, active plugins and
  theme, whether caching is on) and recent plugin/theme/core changes

None of this includes passwords, login session cookies, the actual content of a request
or its response, form field values someone typed in, or email message bodies. Those are
never recorded, under any circumstances, regardless of any setting. See
[Redaction](#redaction), below.

## When CrashTape records

Only while a diagnostic session is active: something you (an administrator) start
deliberately from Tools → CrashTape, for as long as you leave it running, and nothing
more. There is no configuration cost or background activity from having CrashTape
installed and active outside of a session.

The one exception is **Passive Monitoring**, an optional feature that is off by default.
If you turn it on, it continuously samples a much narrower, lower-detail slice: just
rolling daily counts of PHP fatal errors, mail failures, and outbound request failures,
plus a short list of the most common recurring errors. No browser activity, no full
event detail, no per-visit records. See [Advanced Features](advanced-features.md) for
what it does and doesn't cover.

## When CrashTape does not record

Any time no diagnostic session is active and Passive Monitoring is off (its default
state, which is the default state of a freshly installed CrashTape). CrashTape does
nothing on a normal page request at that point; there is nothing to opt out of because
nothing is happening.

## Where the data is stored

In this WordPress site's own database, in tables CrashTape creates for that purpose,
nowhere else. Generated support package files (see below) are written to this site's own
`wp-content/uploads/` directory, protected from direct web access. Nothing is stored
anywhere outside this site's own hosting.

## Redaction

Secrets and personal information are removed **before** anything is written to the
database, not afterward, and not only when you export a report. One central mechanism
handles this for every single thing CrashTape records; there's no separate capture path
that could skip it.

Never recorded, under any privacy profile setting: passwords, `Authorization` and
`Cookie` request headers, the actual content (body) of any request or response, form
field values, and email message bodies. Beyond that fixed list, CrashTape also
automatically detects and masks text that looks like an API key, access token, or other
secret, and you can add your own field names or patterns to catch anything specific to
your own site.

## Privacy profiles

You choose how much CrashTape retains beyond the always-redacted items above, from
**Settings → Privacy**:

- **Strict** (the default): email addresses fully masked, IP addresses fully masked, no
  query string parameters retained at all.
- **Balanced**: email domain only retained (e.g. `[redacted]@example.com`), IP addresses
  coarsened, and only query parameters you've explicitly allowlisted are retained.
- **Advanced**: full email addresses, full IP addresses, and complete query strings
  retained. Still scanned for recognizable secrets as a second layer of protection, but
  a real, opt-in increase in what's kept, meant for a site where that trade-off has
  already been considered.

Strict, the default, is what a new install has until you deliberately change it.

## Data retention

Sessions and any support packages generated from them are automatically deleted after a
configurable period (7 days by default, adjustable from 1 to 365 days). A background
task checks for anything past its expiration once a day and removes it, including every
recorded event and request tied to that session. An actively-recording session is never
auto-deleted regardless of how long it's been running; only completed ones age out.

## Generated support packages

When you export a session as a support package (a ZIP file meant to be attached to a
support ticket), everything in it has already gone through the same redaction as the
database itself. The export step doesn't add any new exposure, and there's no separate,
less-protected copy of the data anywhere. The package includes the event timeline, the
environment snapshot, and a human-readable summary. It's stored locally on your own site
(subject to the same retention period above) until you download it and send it wherever
you choose. CrashTape itself never sends it anywhere.

## Manual deletion

There is currently no "delete this session now" button in the admin screen; a session's
data is removed automatically once its retention period passes (see above), or
immediately and entirely if you uninstall the plugin with the default setting (see next).

## What happens when you uninstall

By default, deleting the plugin (not just deactivating it) removes every table, option,
and temporary token CrashTape ever created, plus every stored support package file, from
your site's database and filesystem. This is irreversible. Checking "Keep my diagnostic
data if this plugin is deleted" under Settings beforehand skips all of this: uninstalling
then only removes the plugin's own files, and everything it stored stays in place, ready
to pick up again if you reinstall. Deactivating the plugin, without deleting it, never
touches any stored data either way; data remains in place, simply inactive, until you
either reactivate CrashTape or delete it. On a multisite network, uninstalling loops
through every individual site rather than assuming one shared installation, and each
site's own choice is honored independently.

## Does any data leave this site?

Not automatically, no. CrashTape doesn't send anything anywhere on its own. The only way
data leaves your site is if you, personally, choose to download a support package and
send it to someone (a support ticket, an email, wherever you decide), an action you take
deliberately, not something CrashTape does for you.

The only outbound network requests CrashTape's own code ever makes are: (1) a self-test
that pings your own site's own REST API, to a URL on your own domain, to confirm it's
reachable; and (2) the Conflict Finder / Custom Checks features, when you configure a
URL for CrashTape to check the status of, a URL you provide, almost always pointing back
at your own site, never a CrashTape-controlled address.

## Telemetry

**CrashTape does not use product telemetry and does not automatically upload diagnostic
data to a CrashTape cloud service.** There is no usage tracking, no analytics, no
"phone home" of any kind, anywhere in this plugin's code.

## Does CrashTape contact CrashTape-owned servers?

No. CrashTape has no cloud service, no update-check endpoint, and no CrashTape-owned
server of any kind that this plugin's code contacts. Every outbound request the code can
possibly make is described under "Does any data leave this site?" above, and none of
them go to anything CrashTape-owned.
