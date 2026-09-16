# Advanced Features

## Conflict Finder

Requires the isolation MU-loader to be installed first (Conflict Finder tab; see
[Isolation Mode](#isolation-mode) below); the Finder itself is built on top of it and
won't start without it.

A guided binary search for "which plugin is causing this." Each round keeps roughly half
of the remaining suspect plugins active for one specific, isolated diagnostic browser
(see [Isolation](#isolation-mode) below) and asks whether the problem still happens,
narrowing around 40 plugins down to one suspect in about six rounds.

**Guided mode**: start it from the Conflict Finder tab, reproduce the problem in the
isolated diagnostic browser each round, then answer **Yes** (still happening), **No**
(fixed), or **Unable to tell**. CrashTape narrows the candidate list and starts the next
round automatically.

**Automated mode**: skips the manual yes/no step by reusing one of your [custom
checks](diagnostics.md#custom-checks) of the "URL responds with an expected HTTP status"
type as the assertion: CrashTape
makes the request itself each round and narrows automatically. Only that check type can
drive automated mode: the other custom-check types (option equals, constant defined,
plugin active) evaluate the *current* admin request, not the isolated browser's actual
state, so they can't reflect what a given round's reduced plugin set actually does.
Capped at 15 rounds.

**What this can't tell you**: MU-plugins, drop-ins (e.g. object-cache.php,
advanced-cache.php), and the active theme are outside what plugin isolation can affect;
narrowing to "no plugin conflict found" doesn't rule those out. If a declared plugin
dependency (`Requires Plugins` header) exists for anything under test, it's always kept
active alongside it automatically, so a real conflict never gets mistaken for a fatal
error caused by disabling a dependency.

## Isolation Mode

The underlying mechanism the Conflict Finder is built on, and usable on its own. An
optional MU-loader component (installed/removed from the Conflict Finder tab) lets one
specific diagnostic browser load a reduced set of plugins, determined by a signed
cookie, not by changing which plugins are actually active site-wide. Every other visitor
sees every plugin active as normal, the whole time.

An **emergency bypass** option exists in case an isolation test ever leaves the site in a
state you need to immediately back out of.

Theme isolation (available separately, when starting a session) works the same way but
for the active theme: it filters WordPress's own `stylesheet`/`template` lookups for the
diagnostic browser only, rather than touching the site's actual theme selection.

## Session Comparison

Diff two sessions directly: pick a "working" one and a "failing" one from the Sessions
tab, and CrashTape shows what's actually different: environment/plugin version changes,
and which error fingerprints appear in one session but not the other.

### Baselines

Mark any session as a healthy baseline. From then on, every session you stop is
automatically compared against it, surfacing new error fingerprints that weren't present
when things were working, without you having to manually pick a comparison session each
time.

## Integrations

These are automatic: feature-detected against whichever third-party plugins are active,
no configuration needed, and each fails safe if the integration itself has a problem
(never taking down capture for anything else in the same request):

- **WooCommerce**: order failures captured in real time; webhook delivery health,
  outdated theme template overrides, and HPOS status captured as part of the environment
  snapshot.
- **Contact Form 7**: submission failures (validation, spam, mail-send failure).
- **Caching plugins**: cache-purge events (WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP
  Rocket), plus a cache-bypass status flag on every captured request. See
  [Troubleshooting → cache/CDN](troubleshooting.md#a-cachecdn-is-interfering).
- **WP Mail SMTP**: the configured mailer type, host, port, and encryption (never
  credentials) exposed as part of the environment snapshot.

## Alerts

Not built. Alerting/notifications is explicitly out of MVP scope. Passive Monitoring
(Settings tab) records ongoing PHP/mail/HTTP health
independent of any session, but nothing currently notifies anyone about it. You have to
check the Dashboard tab yourself.
