# First-login Setup Wizard + native-currency / locale fix

Deploy: unzip into repo ROOT (paths preserved) -> commit -> push -> EasyPanel REBUILD.

## Files
- resources/panel/onboard.html ................ the wizard (5 steps + review)
- app/Http/Controllers/Panel/SellerPanelController.php  onboard() page + first-login gate (owners only)
- app/Http/Controllers/Panel/PanelApiController.php ... onboardSave() persists settings
- app/Support/PanelCurrency.php ............... native currency support (currency_basis)
- app/Services/Bot/AiBrain.php ............... injects BUSINESS CONTEXT (city/country/currency/langs)
- routes/web.php ............................. GET /panel/onboard, POST papi/onboard-save

## What it does
- On first login an OWNER is redirected to /panel/onboard. Staff are never gated.
- Wizard captures: business name + type + WhatsApp; country/city/timezone (auto-suggested from country);
  currency + PRICE BASIS (native vs converted); main languages; delivery areas.
- Saves to tenant settings: name, whatsapp_number, bot_kind, country, country_code, city,
  timezone, currency, currency_basis, languages[], delivery_areas[], onboarded=true.
- currency_basis=native  -> USD/SSP render PLAIN (no UGX conversion); also forces usdUgx=0 so the
  bot's "UGX-base / ex-Kampala" pricing logic stays OFF.
- AiBrain now prepends a BUSINESS CONTEXT block so the assistant uses the real city/country/currency
  and never assumes Kampala.

## Fix the South Sudan customer immediately (after deploy)
Option A (recommended): have the owner just log in -> the wizard appears -> pick
  Country = South Sudan, City = Juba, Currency = USD, Price basis = "Prices are in USD directly",
  languages English/Arabic -> Finish.
Option B (set it for them via tinker):
  $t = App\Models\Tenant::find(<ID>);
  $t->putSetting('country','South Sudan'); $t->putSetting('country_code','SS');
  $t->putSetting('city','Juba'); $t->putSetting('timezone','Africa/Juba');
  $t->putSetting('currency','USD'); $t->putSetting('currency_basis','native');
  $t->putSetting('usdUgx',0); $t->putSetting('languages',['English','Arabic']);
  $t->putSetting('onboarded',true);

## Verify
- Log in as the owner of a fresh tenant -> wizard shows. Finish -> lands on /panel/today.
- Panel money columns show the chosen currency as-is (no UGX/SSP switcher when native).
- Bot replies reference the right city and quote prices in the native currency.
