# Backend cumulative deploy — greeting fix + custom-domain links + brand-site root

Self-contained and consistent: deploy this regardless of which earlier backend ZIPs you
already pushed. It supersedes the "custom-domain brand-site" and "track-link custom-domain"
ZIPs (their changes are all included here). The storefront `shop.html` (FAQ + category-first
menu) is the only piece NOT in here — deploy that one separately if you haven't.

## NEW in this build — greeting
The bot no longer echoes a religious greeting back at the customer (the "Walaikum Assalam"
problem). Instead each shop greets with ITS OWN configured greeting, in BOTH the AI path
and the fast deterministic path.
- `app/Services/Bot/AiBrain.php` — AI prompt: mirror the customer's language for content,
  but do NOT echo Islamic/Hindu/other religious greetings back; open with the shop's own
  greeting when one is set.
- `app/Services/Bot/BotBrain.php` — deterministic greetingReply(): the shop's configured
  greeting now wins over the built-in localized defaults (Habari / Salaam / Namaste / etc.),
  so a shop set to "Jai Shri Krishna" always opens that way.

### ACTION REQUIRED to make Pal's Snack greet as JSK
Set the greeting in the seller panel: **Settings → WhatsApp Settings → Greeting**, e.g.
    Jai Shri Krishna! 🙏 Welcome to Pal's Snack. Tell me what you'd like and I'll add it up.
(You can write it in Gujlish too, e.g. "Jai Shri Krishna! 🙏 Pal's Snack ma swagat che. Kaho su joiye?")
This greeting feeds both the AI and the fast path. Do the same per shop that wants its own
greeting; shops left blank keep the existing localized defaults.

## ALSO INCLUDED (from earlier turns)
- Custom-domain customer track links (`Tenant::publicUrl()` helper + call sites in
  NotifyCustomerOrderReceived, AiBrain, PanelApiController): server-side track links use the
  shop's own custom domain when set, else the platform URL.
- Custom-domain ROOT serves the brand site for manufacturers (MarketingController → landing()).

## Files (6)
- app/Models/Tenant.php
- app/Jobs/NotifyCustomerOrderReceived.php
- app/Services/Bot/AiBrain.php
- app/Services/Bot/BotBrain.php
- app/Http/Controllers/Panel/PanelApiController.php
- app/Http/Controllers/Marketing/MarketingController.php

## Scope / safety
- No database change, no migration, no new settings keys (reuses the existing `bot_greeting`).
- Shops with no custom greeting keep their current localized greetings.
- Shops with no custom domain keep platform-URL links.
- `php -l` each file after pulling (sandbox here had no PHP to lint with).

## Deploy
1. Unzip at the repo root (overwrites the 6 files).
2. Commit + push → EasyPanel rebuild.
3. `php artisan optimize:clear`.
4. Set Pal's greeting (above). Then message the bot "Assalam" / "hi" — it should reply with
   the JSK greeting, not "Walaikum Assalam".

## Rollback
Restore the 6 files from the previous version and redeploy.
