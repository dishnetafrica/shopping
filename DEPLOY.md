# CloudBSS — Setup Wizard + AI Help Bot (one deploy)

Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD (not Restart).

## Feature 1 — First-login Setup Wizard  (fixes the South Sudan / USD / Kampala issue)
- /panel/onboard : 5-step wizard. Owners are redirected here on first login; staff are never gated.
- Captures: business name + type + WhatsApp; country/city/timezone (auto from country);
  currency + PRICE BASIS (native vs converted); languages; delivery areas.
- Saves tenant settings + onboarded=true via POST /papi/onboard-save.
- currency_basis=native -> USD/SSP render PLAIN (no UGX conversion) and usdUgx forced to 0,
  so the bot's "UGX-base / ex-Kampala" pricing logic stays OFF. (PanelCurrency.php)
- AiBrain prepends a BUSINESS CONTEXT block (city/country/currency/languages) so the assistant
  never assumes Kampala and quotes prices in the native currency.

Fix this customer now (after deploy): owner logs in -> wizard -> South Sudan / Juba / USD / native.
Or via tinker:
  $t=App\Models\Tenant::find(<ID>);
  $t->putSetting('country','South Sudan');$t->putSetting('country_code','SS');
  $t->putSetting('city','Juba');$t->putSetting('timezone','Africa/Juba');
  $t->putSetting('currency','USD');$t->putSetting('currency_basis','native');
  $t->putSetting('usdUgx',0);$t->putSetting('languages',['English','Arabic']);
  $t->putSetting('onboarded',true);

## Feature 2 — In-app AI Help Bot
- Floating "?" button on EVERY panel page (injected in brandize). Opens a chat drawer.
- POST /papi/help-ask -> OpenAI (GLOBAL CloudBSS key, so support isn't billed to the tenant),
  grounded in App\Support\HelpKnowledge so answers are about THIS system (add product, import CSV,
  connect WhatsApp, change currency, orders, bot, staff, etc.). Replies in the user's language.
- Degrades gracefully (curated fallback text) if OpenAI is unavailable. History capped; input capped.
- Requires OPENAI_API_KEY (and optional OPENAI_MODEL, default gpt-4o-mini) in the app env.

## Files
- resources/panel/onboard.html
- app/Support/PanelCurrency.php
- app/Support/HelpKnowledge.php
- app/Services/Bot/AiBrain.php
- app/Http/Controllers/Panel/SellerPanelController.php
- app/Http/Controllers/Panel/PanelApiController.php
- routes/web.php

## Verify after rebuild
- Owner of a fresh tenant -> wizard shows; finish -> /panel/today.
- Money columns show chosen currency as-is (no UGX/SSP switcher when native).
- "?" button bottom-right on every page; ask "How do I add a product?" -> step-by-step answer.
