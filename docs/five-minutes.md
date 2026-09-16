# CrashTape in Five Minutes

The whole workflow, no background reading required.

## 1. Record

Go to **Tools → CrashTape**. On the Dashboard tab, click **Start Diagnostic Session**
(a label is optional). Now reproduce the problem, in that same browser tab, the normal
way you'd use the site.

Nothing is captured before you click Start, and nothing is captured after you click Stop.
This is a recorder, not an always-on log.

## 2. Stop

Click **Stop Recording**. CrashTape takes you straight to the Sessions tab and runs its
analysis immediately.

## 3. Read the result

At the top of the Sessions tab:

- **Most Likely Issue**: a plain-language title, a confidence level (High/Medium/Low,
  never presented as certainty), the specific event that's the evidence for it, and a few
  suggested things to check.
- Below that, the full event timeline (tucked behind a **Technical details** disclosure
  if you don't need it) and any plugin/theme/core changes from before the session
  started, often the real cause.

If nothing shows up at all, see
[Troubleshooting](troubleshooting.md#the-recorder-isnt-capturing-anything). A full-page
cache serving pages without WordPress running is the single most common reason.

## 4. Hand it off

Still on the Sessions tab, click **Generate Support Package**. That builds a ZIP, already
redacted according to your privacy profile, with the full timeline, the environment
snapshot, and a `summary.html` meant to be opened directly. Attach it to a support ticket
or send it to whoever's debugging the issue.

## Two things worth knowing before you start

- **The problem only happens for logged-out visitors?** Choose "As a logged-out visitor"
  instead of "In this logged-in browser" when starting the session. CrashTape gives you a
  one-time link (15 minutes, single use) to open in a private window.
- **Nothing is recorded outside an active session.** No configuration cost, no background
  overhead, on a normal request with no session running.

## Where to go next

- [Getting Started](getting-started.md): the same workflow above, with more detail on
  each step.
- [Privacy](privacy.md): exactly what is and isn't captured, and the three redaction
  profiles.
- [Diagnostics](diagnostics.md): what each capture channel actually records.
- [Advanced Features](advanced-features.md): the Conflict Finder, session comparison,
  and third-party integrations.
- [Developers](developers.md): the SDK, WP-CLI, and REST API, if you're extending
  CrashTape or driving it from external tooling.
