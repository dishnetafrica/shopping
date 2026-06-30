# CloudBSS — combined deploy (single package; supersedes all earlier zips)
Setup Wizard + AI Help Bot + Reliability + Activation Checklist + What's New.
Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD (not Restart).
Needs OPENAI_API_KEY for the help bot.

1) Setup Wizard            /panel/onboard (owners on first login; re-runnable, prefilled)
2) AI Help Bot             floating "?" -> /papi/help-ask (global OpenAI key)
3) Reliability             X-Request-Id/X-App-Build headers, JSON API errors, /healthz,
                           owner /papi/platform[/errors|/opcache-reset]
4) Activation Checklist    bottom-left "Setup x/5" -> /papi/activation[, /mark]
5) What's New              "?" widget shows release notes with an unseen badge -> /papi/whatsnew.
                           Edit app/Support/Changelog.php and bump the top `version` to notify merchants.

## Files (11)
resources/panel/onboard.html
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

Note: seller.html already has empty-states for lists, so none were added (avoided risky churn).
