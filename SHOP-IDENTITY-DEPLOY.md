# SHOP-IDENTITY-DEPLOY.md — address + website, preconfigured in ONE line

## Deploy, then paste exactly this in the EasyPanel console:
```
php artisan shop:configure 17 --address="A29 & A31, FREEDOM SQUARE CENTRE\nKonyo Konyo Market, Juba" --website="https://familyshoppers.net"
```
Done. Effective immediately (no rebuild needed after the command). The command
prints current values; rerun with new values any time; `--show` to inspect.

## What the bot now answers (both paths — instant FAQ + AI)
- "where is your shop / how do I find you" → 📍 the address + "Browse online:
  https://familyshoppers.net" + visit-or-order-here line
- "send me your catalogue / price list / what do you sell / can I see your
  products / your website" → 🛒 the website link + "add to cart there, or tell me
  here — both reach us instantly"
- AiBrain's BUSINESS DETAILS already carried the `website` setting with a "never
  say we don't have a website" instruction — this zip completes the deterministic
  path and the address, one shared setting each.

## Files (5) · Gate
FaqDictionary · BotBrain · ShopConfigureCommand (NEW) · ShopAddressFaqTest
(extended) · this doc — Rebuild → `php artisan test --filter=ShopAddressFaq` →
run the one-liner → WhatsApp the bot both questions.
