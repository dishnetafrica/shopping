# CloudBSS — combined deploy: Setup Wizard + AI Help Bot + Reliability layer
This single package supersedes setup-wizard.zip, cloudbss-setup-help.zip and cloudbss-reliability.zip.
Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD (not Restart).

## 1. Setup Wizard (self-serve config; fixes South Sudan USD/Kampala)
/panel/onboard, owners auto-redirected on first login; native currency renders plain + bot context fixed.
Re-runnable (prefilled) via the Help assistant's "Re-run shop setup" chip (owner-only).

## 2. AI Help Bot
Floating "?" on every panel page -> /papi/help-ask (OpenAI global key) grounded in App\Support\HelpKnowledge.
Needs OPENAI_API_KEY (+ optional OPENAI_MODEL).

## 3. Reliability & Observability
- X-Request-Id + X-App-Build on every response.
- JSON errors (with request_id) for papi/*, ops/*, api/* and JSON requests.
- GET /healthz (public) ; GET /papi/platform , /papi/platform/errors , /papi/platform/opcache-reset (owner-only).
- opcache "any_stale" tells you instantly whether a deploy actually went live.

## Files (10)
resources/panel/onboard.html
app/Support/PanelCurrency.php
app/Support/HelpKnowledge.php
app/Support/BuildInfo.php
app/Services/Bot/AiBrain.php
app/Http/Middleware/RequestId.php
app/Http/Controllers/Panel/SellerPanelController.php
app/Http/Controllers/Panel/PanelApiController.php
bootstrap/app.php
routes/web.php

## Post-deploy sanity (browser console / curl)
- /healthz -> build + versions.
- Any panel page response carries X-App-Build (confirms the live build) and X-Request-Id.
- As owner: /papi/platform -> opcache.any_stale should be false on a fresh image.
