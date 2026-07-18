# SHOP-ADDRESS-DEPLOY.md — "Where is your shop?" answered, both bot paths

## One setting feeds everything
```
php artisan tinker
Tenant::find(17)->putSetting('shop_address', "A29 & A31, FREEDOM SQUARE CENTRE\nKonyo Konyo Market, Juba");
```
(Wording is yours — edit any time, takes effect immediately, no rebuild.)

## What customers get
- "where is your shop / where are you located / your address / how can I find you /
  can I come buy directly" → instant deterministic reply:
  📍 You'll find us here: … + "visit us — or order right here and we deliver 🚚"
- The AI path carries the same address in its BUSINESS CONTEXT, so free-form
  phrasings (and Arabic questions) get the exact same location.
- Setting empty → the topic yields silently (nothing breaks, nothing invented).

## Files (4) · Deploy
FaqDictionary (+topic, placed before the broad topics per the file's own ordering
rule) · BotBrain faqContext (+shop_address) · AiBrain systemPrompt (+1 line) ·
ShopAddressFaqTest — upload → Rebuild →
`php artisan test --filter=ShopAddressFaq` → run the tinker → WhatsApp the bot
"where is your shop?" from any number.
