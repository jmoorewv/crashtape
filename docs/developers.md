# Developers

Three ways to extend CrashTape from your own plugin, a WP-CLI command family for
scripting/ops, and a REST API for external tooling. All four are independent; use
whichever fits.

## Diagnostics SDK

Register a self-check other code (and CrashTape's own Diagnostics tab) can query at any
time, not tied to an active session. Runs on demand, and when a support package is
generated.

```php
add_action( 'init', function () {
    crashtape_register_diagnostic( 'my-plugin/api-connection', array(
        'label'       => 'My Plugin API connection',
        'sensitivity' => 'low', // 'low' | 'medium' | 'high'
        'permission'  => 'manage_options', // optional, defaults to manage_options
        'callback'    => function () {
            $ok = my_plugin_check_api();

            return array(
                'status'  => $ok ? 'good' : 'error', // good|info|warning|error|unavailable
                'summary' => $ok ? 'API reachable.' : 'API did not respond.',
                'data'    => array(), // optional extra safe detail
            );
        },
    ) );
} );
```

Call this from your own plugin's `plugins_loaded` or `init` hook, not top-level file
scope, since CrashTape may not have loaded yet at that point depending on plugin order.

## Event SDK

Records a custom event into the *active* diagnostic session, if one is active for the
current request. Does nothing otherwise, so it's safe to call unconditionally on every
request; no need to check whether a session is running yourself.

```php
crashtape_record_event( 'my-plugin', 'sync_failed', array(
    'severity' => 'error', // info|notice|warning|error|critical, default info
    'summary'  => 'Inventory sync failed after 3 retries.',
    'data'     => array( 'retry_count' => 3 ), // optional, redacted like any other event
) );
```

Custom events land in the `custom` channel, and in `custom-events.json` inside an
exported support package.

## Rule Registry

Adds a rule to the analysis engine that runs when a session stops. A rule watches one or
more event channels and, when it matches, contributes a candidate "most likely issue."

```php
crashtape_register_rule( 'my-plugin/sync-failure', array(
    'channels' => array( 'custom' ),
    'callback' => function ( $event, $data ) {
        if ( 'sync_failed' !== $event->event_type ) {
            return null; // no match
        }

        return array(
            'classification'   => 'my-plugin-sync-failure',
            'title'             => 'My Plugin sync is failing',
            'explanation'       => 'Inventory sync failed and retried the maximum number of times.',
            'suggested_checks'  => array( 'Check the remote API\'s status page.' ),
            'base_points'       => 3,
        );
    },
) );
```

`$event` is the raw event row; `$data` is its already-decoded `data` array. Confidence is
High at a score of 8+, Medium at 4+, otherwise Low. `base_points` is one input into that
score alongside the event's severity, whether it was attributed to a specific
plugin/theme, whether a recent change to that component was recorded, and how many times
it recurred. Pick a `base_points` value in that spirit rather than trying to force a
specific confidence level directly.

## Hooks and filters

None today. CrashTape doesn't currently expose any `apply_filters()`/`do_action()` hooks
of its own for third parties; the three functions above are the entire extension
surface. (Documented here rather than left silent, since hooks/filters is worth covering
as its own documentation category, even though there's honestly nothing to list yet.)

## WP-CLI

```
wp crashtape status                        # schema version, privacy profile, active session, etc.
wp crashtape snapshot                       # a fresh environment/cron/WooCommerce/SMTP snapshot, redacted
wp crashtape export <session>               # build a support package; <session> is a UUID or numeric ID
wp crashtape cleanup                        # run the retention cleanup pass immediately

wp crashtape sessions list [--limit=<n>] [--format=<fmt>]
wp crashtape sessions show <session>

wp crashtape diagnostics run [--user=<id>] [--format=<fmt>]   # SDK diagnostics + custom checks
wp crashtape changes list [--limit=<n>] [--format=<fmt>]

wp crashtape isolation status               # MU loader / emergency bypass / conflict finder status
```

SDK diagnostics are gated by their own registered capability. WP-CLI has no logged-in
user by default, so pass `--user=<id>` to `diagnostics run` for a capability check to
actually pass.

## REST API

Two separate route groups, deliberately using two different auth models:

**Browser ingestion** (`POST /wp-json/crashtape/v1/events`): accepts event batches from
the recorder script itself. Authenticated by the diagnostic cookie, not a WordPress
capability, since visitor-mode recording has to work for a logged-out browser. Not meant
to be called directly by your own code.

**Session control plane**: everything else, gated by `manage_options` like every other
CrashTape admin action, authenticated the normal WordPress REST way: cookie + nonce for a
logged-in browser, or WordPress's own built-in Application Passwords feature (Users →
Profile → Application Passwords) for external tooling.

| Method | Route | Does |
|---|---|---|
| `GET` | `/wp-json/crashtape/v1/session` | List sessions (`?page=`, `?per_page=`) |
| `POST` | `/wp-json/crashtape/v1/session` | Start a session (`label`, `mode`, `isolate_theme`) |
| `GET` | `/wp-json/crashtape/v1/session/{uuid}` | Show one session, including its environment/analysis |
| `POST` | `/wp-json/crashtape/v1/session/{uuid}/stop` | Stop it (only works on the currently active session) |
| `POST` | `/wp-json/crashtape/v1/session/{uuid}/export` | Build a support package |
| `GET` | `/wp-json/crashtape/v1/session/{uuid}/download` | Fetch the most recently built package (base64, JSON) |
| `GET` | `/wp-json/crashtape/v1/self-test` | Run the self-test, return results |

`/download` returns JSON with a `content_base64` field rather than streaming raw bytes.
This is what lets a purely headless client (Application Password, no cookies at all)
actually retrieve the package it just asked the API to build. Capped at 25 MB; past that,
use the admin UI's own download link instead (works only for a cookie-authenticated
browser).

## Report Schema

A support package is a ZIP with these files, listed in `manifest.json` along with a
SHA-256 checksum for each:

- `README.txt`: what's in this package and how to read it.
- `manifest.json`: product/schema version, plugin version, file checksums.
- `session.json`: the session's own record (label, mode, status, counts, timestamps).
- `environment.json`: the full environment snapshot at session start.
- `timeline.jsonl`: every event, one JSON object per line, in order.
- `requests.json`: one row per captured request (method, path, type, status, duration).
- `changes.json`: plugin/theme/core changes in the 24 hours before the session started.
- `redaction-report.json`: what redaction profile was active and what got redacted.
- `diagnostics.json`: the result of every registered SDK diagnostic at export time.
- `errors.json`, `http.json`, `browser.json`, `mail.json`, `woocommerce.json`,
  `forms.json`, `cache.json`, `custom-events.json`: the same events already in
  `timeline.jsonl`, filtered to one channel each, for someone who only wants one
  channel's evidence.
- `rest.json`: REST requests only, with namespace and auth status.
- `scheduled-tasks.json`: the WP-Cron/Action Scheduler overview from the environment
  snapshot, pulled out on its own.
- `summary.html`: a human-readable version of the above, meant to be opened directly.

`manifest.json`'s `schema_version` is currently `1.0`.
