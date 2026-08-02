# CloudBSS v4.9 — Dealer Programme drop 4a: PRICES (spec approved)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.9.zip (cumulative — supersedes v4.8.2 and everything before it) · **Migration:** **YES — `php artisan migrate`** (two guarded migrations now: oem_number from v4.7.7 + dealer fields from this drop; both safe to re-run).

Built to the approved spec's recommendations: Dealer 10% · Wholesale 15% · margin floor 5% over cost · owner-only grants · quotation-only. All changeable in Settings.

## What ships

**1. One pricing brain — `PriceResolver`.** Per-product negotiated `dealer_price` beats the tier percentage; the **margin floor beats everything** (no resolved price ever below cost + floor %, never above retail). Every clamp is BotTraced (`dealer_clamp`). Pure ladder is harness-tested 12/12 — including the case that matters: *on today's +5% retail a 10% discount clamps to the floor and dealers save almost nothing.* The programme has teeth only after the retail re-price (below).

**2. The WhatsApp bot is dealer-aware TODAY.** Mark a customer as Dealer/Wholesale and the bot's order calculator prices every quote at their trade price automatically — same phone number, zero login, nothing for the dealer to do. Quotes show *"✓ Your dealer price is applied."* and the AI is briefed on their status (talk trade — stock, lead times, repeat orders — and never invent extra discounts; the calculator's numbers are law). Weighted/per-kg items are exempt in v1.

**3. Grant from the customer card.** Open any customer in the panel → new **💼 Dealer pricing** card: Retail / Dealer / Wholesale selector, optional custom %, floor reminder. Saving is owner-only (staff get a clear error), every grant/revoke/percentage BotTraced (`dealer_grant`).

**4. Settings → Storefront → Dealer programme:** Dealer tier % (default 10) · Wholesale % (default 15) · Margin floor % (default 5).

**5. Data plumbing:** `customer_profiles` + dealer_tier/dealer_discount_pct/dealer_since/dealer_notes · `products.dealer_price` (negotiated override) — importable via a **"Dealer Price"** CSV column (both import branches, schema-guarded) and exported as `Dealer_Price` in the products feed.

Everything is schema-guarded: before `migrate` runs, every customer simply resolves as retail — nothing breaks.

## The retail re-price (your call — files are ready)

Also delivered: **boraboda-30margin-part{1,2,3}-of-3.csv** — the same 89-part catalogue at **retail = supplier + 30%** (e.g. Drive Chain 15,000 → 19,500; a 10% dealer then pays 17,550 and you still keep ~17% margin). Upload them exactly like before (owner, bike tenant, part 1 with Replace → RESTART) **only when you've decided the margin** — say 25% or any other number and I regenerate in a minute. Until the re-price, dealer discounts clamp at the floor by design.

## Deploy

GitHub upload → Rebuild → **`php artisan migrate`**. Then: mark your first dealer (customer card → 💼), have them ask the bot for a price, and watch the quote come back with their trade price applied.

## Files changed vs v4.8.2

NEW migration 2026_08_03_000001_add_dealer_fields.php · NEW app/Services/Pricing/PriceResolver.php · OrderCalculator.php (dealer resolution + catalogue cost/dealer_price + "dealer price applied" line) · AiBrain.php (4 quote sites pass the customer phone) · ProfileContext.php (dealer briefing block) · CustomerProfile.php (fillable) · PanelApiController.php (dealer/dealer-set endpoints + Dealer Price import/export) · routes/web.php · SettingsSchema/SettingsWriteService · shell.js BOTH copies (dealer card, md5-identical).

## Verified before ship

12/12 PriceResolver harness (tier/override/clamp/no-cost/POR/cap-90/never-above-retail) · php -l ×10 · node --check + md5-identical shell.js pair · schema guards traced at every read/write site (feed, importer, resolver, endpoints). Drops 4b (storefront login + dealer prices on the web) and 4c (CSV quick-order + saved lists) follow on your word.
