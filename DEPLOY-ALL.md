# CloudBSS — combined deploy (single package; supersedes all earlier zips)
Setup Wizard + AI Help Bot + Reliability + Activation Checklist + What's New
+ Sentry error tracking + internal System Health page.
Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD.

## Error tracking (Sentry) — how big companies see errors
- Added to the Dockerfile's optional require block (|| true, so it can never break the build).
- It is INERT until you set the DSN. To turn it on:
  1. Create a free project at sentry.io -> copy the DSN.
  2. In EasyPanel app env, set:  SENTRY_LARAVEL_DSN=<your dsn>
     (optional) SENTRY_RELEASE=<your build/git sha>  and  SENTRY_TRACES_SAMPLE_RATE=0
  3. Rebuild. From then on every exception lands in the Sentry dashboard with stack trace,
     request_id (we tag it), and the user/tenant id.
- Code hooks are guarded by class_exists/function_exists — safe whether or not the package/DSN exist.

## Internal System Health page (no raw JSON)
- Owner-only page at  /panel/system  (also reachable from Help -> "System health").
- Shows: build id + versions, DB/cache/storage checks, OPCACHE state with a clear
  "Deploy not fully live" banner when code is stale, a one-tap "Reset opcache" button,
  and the recent error log. This is the human-friendly cockpit for the diagnostics endpoints.

## Everything else (unchanged from prior bundle)
1) Setup Wizard /panel/onboard   2) AI Help Bot ("?")   3) Reliability (/healthz, /papi/platform*)
4) Activation Checklist (bottom-left)   5) What's New (✨ in Help)

## Files (13)
Dockerfile
resources/panel/onboard.html
resources/panel/system.html
app/Support/PanelCurrency.php
app/Support/HelpKnowledge.php
app/Support/BuildInfo.php
app/Support/Changelog.php
app/Services/Bot/AiBrain.php
app/Http/Middleware/RequestId.php
app/Http/Controllers/Panel/SellerPanelController.php
app/Http/Controllers/Panel/PanelApiController.php
bootstrap/app.php
routes/web.php
