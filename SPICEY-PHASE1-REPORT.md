# Spicey Herbs — Phase 1 Build Report (Flat-Menu Pilot)

All 8 tasks built. Everything is `php -l` clean (16 files) and the logic suites are green: `order_instructions` 49/49, `replay_csv` 40/40, plus a new offline match estimate. The one honest boundary: this sandbox has no `vendor/` so Laravel can't boot — the Kitchen Board UI and the BotBrain rewiring are verified by lint + pure tests, not by an integration run. Your first live order is the integration test (step 6 of the runbook).

## What shipped

| # | Task | Status | Where |
|---|---|---|---|
| 1 | Kitchen Board (KOT) | ✅ | `app/Filament/Pages/KitchenBoard.php` + blade. Live columns New→Accepted→Preparing→Ready→Dispatched, one-tap advance, Reject/Cancel, 15s poll, new-ticket sound, sidebar badge. |
| 2 | Status workflow | ✅ | `Order::STATUSES` + `KITCHEN_FLOW` + `nextKitchenStatus()`; `OrderResource` + stats widget use them; `OrderObserver` stamps `accepted_at`/`ready_at`. |
| 3 | Per-stage notifications | ✅ | `SendOrderStatusNotification` copy for Accepted/Preparing/Ready/Dispatched/Rejected. Fires automatically via `OrderObserver` on every status change. |
| 4 | Order + item notes | ✅ | Migration adds `orders.notes`, `order_items.notes`. `OrderInstructions` helper splits "biryani extra spicy" → note; persisted in `placeOrder`; standalone "less spicy" attaches to last line. |
| 5 | products.description | ✅ | Column + importer alias + product form field + bot "what's Chicken Changezi?" answer (`productInfoReply`) + description in price replies. |
| 6 | USD currency | ✅ | `PanelCurrency::code()` (per-tenant) replaces hardcoded UGX in Order/Product/Delivery resources + POS view. Bot already tenant-aware. Set tenant currency=USD (runbook step 3). |
| 7 | Menu import | ✅ | `spicey-herbs-menu.csv` — 122 active dishes, dual-price split, descriptions. Imports cleanly (0 malformed). |
| 8 | Replay / match rate | ✅ (offline) | `qa/spicey_match_estimate.php` — **95% top-1** dish match on realistic phrases. True end-to-end number via `bot:replay` in your console. |

## Readiness: ~55% → ~85%

| Area | Before | After | Note |
|---|---|---|---|
| Seller panel | 45% | **85%** | Kitchen Board is the big lift; dashboard gained revenue-today + kitchen counts |
| Ordering (bot) | 35% | **70%** | free-text instructions captured; 95% dish match; no structured modifiers (deferred) |
| Catalog | 40% | **70%** | descriptions in; still flat (variants/modifiers deferred) |
| Notifications | 70% | **90%** | per-stage restaurant copy |
| Reporting | 40% | **60%** | revenue-today + queue counts; top-items still pending |
| Checkout | 85% | 85% | now carries notes |
| Multi-tenant / Rider / Delivery | 90 / 80 / 80 | unchanged | already solid |

**Overall ≈ 85%** — onboarding-ready as a flat-menu pilot once the runbook's go-live steps are done.

## Estimated order accuracy
**~90%** for typical single-and-multi item orders, driven by the measured **95% top-1 dish match**. This will be confirmed (or corrected) by the live `bot:replay`. The matcher estimate covers dish resolution; it does not by itself prove multi-item parsing or the instruction-attach wiring end-to-end.

## Failed / weak examples (from the offline run)
- **"CTM"** (abbreviation for Chicken Tikka Masala) → no match. Fix: add `ctm` to that product's keywords. 1-minute owner edit.
- **"Coke" / drinks** → miss, because the printed menu has no beverages. The CSV ships 4 inactive placeholders; set prices + activate before launch.
- **Inline instruction attach** ("biryani extra spicy" in one message) is wired but **best-effort** — the conservative splitter never truncates dish names (proven against "Hot & Sour Soup", "Garlic Naan", "Add Veg Burger"), but confirm the note lands on the right line in your first live test. Standalone "less spicy" (its own message) is the more robust path.

## Remaining blockers before production
1. **Deploy steps** (runbook): migrate → set USD → import menu → `catalogue:flush`.
2. **Beverages**: add real drink items + prices (otherwise "Coke" fails).
3. **Live smoke test**: place a real WhatsApp order, watch it hit the Kitchen Board, advance it through all stages, confirm the customer gets each notification. This is the only thing that can't be tested here.
4. *(Optional)* a few keyword aliases (CTM, etc.) to lift match rate above 95%.

## Explicitly deferred (Phase 2/3, as agreed)
Structured modifiers (spice level, naan type, pizza extras) and real variants (Half/Full, Reg/Large). For launch, dual-price items are split into separate products and special requests ride as free-text notes — which, as you said, is how most restaurants survive day one.
