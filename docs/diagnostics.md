# Diagnostics: What Each Channel Captures

Every channel below is only active during a diagnostic session; none of this
instrumentation runs otherwise. Everything described here is also subject to the
redaction rules in [Privacy](privacy.md), applied before anything is written to the
database.

## PHP

Chains onto PHP's own error handler (never replaces it: whatever handler was already
registered still runs) and the shutdown sequence.

- `E_WARNING`, `E_USER_WARNING` → captured as `warning` severity.
- `E_NOTICE`, `E_USER_NOTICE`, `E_DEPRECATED`, `E_USER_DEPRECATED` → captured as `notice`
  severity.
- Fatal errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`),
  detected via `error_get_last()` at shutdown → captured as `critical` severity.

Each captured error is attributed to the plugin, theme, or core file that raised it,
resolved from the file path, never a full absolute filesystem path, only the plugin/
theme slug and relative file.

## Browser

The recorder script (only enqueued while a session is active for the current browser)
reports six distinct failure types:

- `js_error`: an uncaught JavaScript exception.
- `unhandled_rejection`: a promise rejection nothing caught.
- `fetch_failed` / `fetch_error`: a `fetch()` call that failed at the network level, or
  that resolved with a non-OK response.
- `xhr_failed` / `xhr_error`: the same distinction for `XMLHttpRequest`.

## Outbound HTTP (APIs)

Observes WordPress's own outbound HTTP API (`http_api_debug`: every `wp_remote_get()`/
`wp_remote_post()`/etc. call in WordPress core or any plugin passes through this).

- A request that fails before getting any HTTP response (DNS failure, timeout, SSL
  failure, anything WordPress itself surfaces as a `WP_Error`) is captured as
  `outbound_error`, severity `error`.
- A response with status ≥ 400 is captured as `outbound_response`: severity `error` for
  5xx, `warning` for 4xx (so a 401 or a 429 doesn't get the same visual weight as a 500).
- A successful response is never captured at all.
- The captured URL has its query string stripped (redaction applies regardless of the
  outer privacy profile for this specific case; outbound API URLs routinely carry
  tokens). A small allowlist of response headers is captured when present and relevant to
  debugging a failure: `content-type`, `retry-after`, `www-authenticate`,
  `x-ratelimit-limit`, `x-ratelimit-remaining`, nothing else, ever (no `Set-Cookie`, no
  custom headers).

## REST

REST request metadata is enriched once routing has actually resolved (`rest_post_dispatch`,
too early to know this at plain request-start): the endpoint's namespace (e.g.
`myplugin/v1`), and a coarse `authenticated`/`anonymous` status, never which specific
auth method or capability was involved.

## AJAX

The AJAX action name (`$_REQUEST['action']`) is captured as a machine identifier, not
free text; this is genuinely available at request start, unlike REST context.

## Mail

Observes `wp_mail_failed` and `wp_mail_succeeded` (WordPress 6.4+; harmlessly never fires
on older cores). Never captures the message body or full subject line, only:

- Recipient count and recipient **domains** (never full addresses).
- A subject fingerprint (first 16 characters of a SHA-256 hash): enough to tell "this is
  the same failing message repeated" from "these are different messages," without
  revealing what the subject said.
- Attachment count.

A successful send is recorded too, at `info` severity, explicitly noting that WordPress
reporting acceptance doesn't prove the message was actually delivered to the recipient's
inbox.

## Cron

Captured once, as part of the environment snapshot at session start (not a real-time
capture channel; cron state doesn't change second to second the way a browser error
does):

- Total scheduled events, how many are overdue, and a sample of the most overdue hooks.
- Duplicate hooks: the same hook scheduled 4+ times, often a real bug (a plugin
  re-scheduling without checking `wp_next_scheduled()` first).
- Whether `DISABLE_WP_CRON` is set.

## Action Scheduler

Also captured at session start, independent of WooCommerce; Action Scheduler ships as a
library bundled by many plugins, not just WooCommerce, and is feature-detected on its own
(`as_get_scheduled_actions()`), not assumed present. Reports pending/failed/overdue
counts, plus a sample of failed actions (including their most recent log message,
redacted like any other free text) and overdue-pending actions.

If WooCommerce is also active, a second, WooCommerce-specific view of its own scheduled
action groups (order processing, sales scheduling, etc.) appears separately. See
[Advanced Features → Integrations](advanced-features.md#integrations).

## Custom Checks

Admin-defined assertions you can create from the Diagnostics tab without writing any
code, different from the [Diagnostics SDK](developers.md#diagnostics-sdk), which is for
your own plugin's code to register a check programmatically. Every check type is a
read-only assertion; none of them can execute arbitrary code. Up to 20 at a time. Run on
demand (**Run All Checks**), and included in every exported support package's
`diagnostics.json`.

Four types, one per check:

- **WordPress option equals a value**: `get_option()` a given option name and compare it
  (as a string) against an expected value.
- **PHP constant is defined**: whether a given constant name (e.g. `WP_DEBUG`) is
  `defined()`.
- **URL responds with an expected HTTP status**: a real `wp_remote_get()` request (10
  second timeout) against the URL, checked against one or more expected status codes. The
  only check type that can drive the Conflict Finder's [Automated
  Mode](advanced-features.md#conflict-finder): the other three evaluate the *current*
  admin request, not an isolated diagnostic browser's actual state, so they can't reflect
  what a given round's reduced plugin set actually does.
- **A plugin is active**: `is_plugin_active()` for a given plugin file (e.g.
  `woocommerce/woocommerce.php`).

Each run reports pass, fail, or error (e.g. the URL check's own request failed) with a
plain-language message naming the actual value found, not just pass/fail.
