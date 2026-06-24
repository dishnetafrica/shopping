# Combo offers (Bundle & Save) — live feature

Curated bundles you define once and that show in three places: the **storefront**, the **WhatsApp bot**,
and the **seller panel** where you manage them. Stored in tenant settings like FAQ/brands — **no migration**.

## How you manage them
Seller panel → Settings → **Website & branding** → **Combo offers** → "+ Add combo". Per combo:
- **Name** (e.g. "Shop Starter Combo"), **Who it's for** (e.g. "small shops"),
- **Items** — one per line, `2 x EuroPearl Toilet Paper` (qty parsed automatically),
- **Combo price**, **Original price** (optional — if set, the saving shows automatically),
- **Active** toggle. Save.

## Where they appear
- **Storefront** (`/ep/shop`) — a "Bundle & Save — Combo Offers" strip on the home screen: each combo
  shows its items, original (struck-through) + combo price, a SAVE badge, and an **"Order this combo on
  WhatsApp"** button that opens a chat pre-filled with the combo name + price.
- **WhatsApp bot** — combos are injected into the AI prompt, so when a customer asks "any offers?",
  "deal", "combo", the bot suggests a relevant one and quotes the fixed combo price (owner-set, so it's
  safe to quote — no LLM math).
- **Seller panel** — the editor above.

## Safety / design choices
- Combo prices are **owner-set** (like quotations), so the bot quotes them verbatim — this stays within
  the "LLM never does the arithmetic" rule.
- A combo is only shown if it has a name, a price > 0, and at least one item (half-filled rows are dropped).
- **Caustic soda / chemicals were deliberately left out** of the sample combos — bundling a corrosive
  chemical with consumer tissue isn't a good look. You can still make a chemicals-only combo if you want.

## Files
- `app/Support/Combos.php` — normalize / forTenant / promptBlock (NEW, single source of truth).
- `app/Http/Controllers/Storefront/StorefrontController.php` — feeds combos to the storefront.
- `app/Http/Controllers/Panel/PanelApiController.php` — saves combos + returns them in the settings feed.
- `app/Services/Bot/AiBrain.php` — injects combos into the bot prompt.
- `resources/storefront/shop.html` — the combo strip.
- `resources/panel/seller.html` — the combo editor.
- `qa/combos.php` — 10/10.

## Deploy
Pull → restart → `php artisan optimize:clear`. **No migration.** Then in the seller panel add your real
combos (the mockup's prices were examples) and hard-refresh the storefront (it caches ~5 min).

## Honest caveats
- Combo items are **free text** you type — they're for display + the bot's suggestion, not linked to
  catalogue stock. The customer taps "Order on WhatsApp" and your team/bot confirms. (If you later want
  combos to auto-add to the cart with live stock, that's a bigger follow-up.)
- This turn wired combos into the **native AI bot**; the n8n brain doesn't get them yet (you're on native
  AI, so this matches your setup). Say the word if you want n8n parity.
- Couldn't render the live storefront from here — hard-refresh `/ep/shop` after deploy to confirm the strip.
