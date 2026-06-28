# Today / Needs-attention home screen + shared JS layer

Two things in one drop, as agreed: the **Today screen** (the page a seller opens first each day) and
the first piece of the **shared front-end layer** — Today is built on it, proving the incremental
module pattern without rewriting anything.

## The shared layer (the structural win)
`resources/panel/shared/{api,utils,modal}.js`, served via a new whitelisted route
`/panel/shared/{file}.js` (these live under `resources/`, not `public/`, so they need a route — raw
JS, no brandize). Everything is namespaced under `window.CB` so it **cannot clash** with the existing
inline `esc`/`toast`/`$` in `seller.html` — the monolith is untouched.
- `CB.api(path, params)` — fetch wrapper with the panel `?token=` + JSON + error handling.
- `CB.esc`, `CB.money`, `CB.toast` — the helpers every page currently re-implements.
- `CB.modal(title, html)`, `CB.closeModal()` — a self-contained modal.

From here, any new or touched page uses `CB.*` instead of copy-pasting helpers. `seller.html` gets
migrated opportunistically, not rewritten.

## The Today screen
`resources/panel/today.html` at `/panel/today` — a standalone page (first consumer of `CB.*`). It calls
one new endpoint and renders clickable cards, each with a **reason** (never a number without a why):

| Card | Counts | Reason shown |
|---|---|---|
| 🔴 Orders need attention | active orders past their stage SLA (vertical-aware) | "Past the response time for their stage" |
| 🟡 Deliveries to confirm | delivered, not customer-confirmed | "Marked delivered, customer hasn't confirmed receipt" |
| 📄 Quotations awaiting reply | status `sent` | "N sent over 24h ago, no reply" |
| 🔵 Likely to reorder | ≥2 past orders, nothing in 30+ days | "Ordered before, nothing in 30+ days" |
| ⚠️ Inventory needs a count | snapshots stale/unknown | "bot says 'I'll confirm' instead of a number" |
| 🛠️ Failed quote pipelines today | `bot_events` quote_pipeline failures | "check diagnostics" |

Plus a positive line ("🟢 8 deliveries confirmed today"). Cards link into the panel; a small guarded
handler in `seller.html` makes `/panel#orders` open that section. Thresholds are tenant settings
(`quote_followup_hours`, `reorder_days`, `order_sla`).

## Backend
`PanelApiController::today()` — pure read over existing data (Order, Quotation, InventorySnapshot,
bot_events). **No new tables.** Tenant-scoped like every other `/papi` endpoint. The "likely to
reorder" query is wrapped in try/catch and is a simple heuristic, not the full Customer-Memory
derivation (that stays deferred per ADR-0002).

## Deliberately NOT built (from the advisor doc — future capabilities, no evidence yet)
Unified draft order across web/WhatsApp, omnichannel cart merge, customer timeline view, website
search/loyalty/reviews. Noted, not built — they earn a drop when there's real demand.

## Verify
- `php artisan route:list | grep -E "today|shared"` shows the three routes.
- Open `/panel/today` → cards load with counts + reasons; click one → lands in the right panel section.
- Open `/panel/shared/api.js` → serves JS (200, `application/javascript`); `/panel/shared/x.js` → 404.
- In-sandbox: PHP lint clean; all JS `node --check` clean. Real DB counts + browser rendering need a
  click-through in your environment — I can't run the panel here.
