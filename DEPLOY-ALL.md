# CloudBSS — combined deploy (single package, supersedes all earlier zips)
Setup Wizard + AI Help Bot + Reliability layer + First-run Activation Checklist.
Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD (not Restart).
Needs OPENAI_API_KEY in env for the help bot.

## 1. Setup Wizard  (/panel/onboard)
Owners auto-redirected on first login. Native currency renders plain; bot location context fixed.
Re-runnable (prefilled) from the Help assistant's "Re-run shop setup" chip.

## 2. AI Help Bot  (floating "?" bottom-right -> /papi/help-ask)
Grounded in App\Support\HelpKnowledge; global OpenAI key; replies in the user's language.

## 3. Reliability & Observability
- X-Request-Id + X-App-Build on every response; JSON errors (with request_id) for papi/ops/api paths.
- GET /healthz (public). Owner: GET /papi/platform , /papi/platform/errors , /papi/platform/opcache-reset.
- opcache "any_stale" answers "is my deploy live?"; opcache-reset is the escape hatch.

## 4. First-run Activation Checklist  (NEW)
- Bottom-LEFT launcher "Setup X/5" -> "Get your shop ready" panel. Owner-only. Auto-hides when complete.
- Steps (computed from real data): set up shop / add first product / connect WhatsApp / share shop link /
  get first order. Each has a CTA. "Share" copies the shop link + opens a WhatsApp share.
- Auto-opens once per browser session while incomplete. "Hide checklist" dismisses it; re-show from the
  Help assistant's "Show setup checklist" chip.
- Endpoints: GET /papi/activation , GET|POST /papi/activation/mark {action: link|hide|show}.

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

## Post-deploy sanity
- /healthz -> build + versions.  Panel responses carry X-App-Build.
- New tenant owner: wizard shows; bottom-left "Setup x/5" checklist appears and auto-opens once.
- As owner: /papi/platform -> opcache.any_stale=false on a fresh image.
