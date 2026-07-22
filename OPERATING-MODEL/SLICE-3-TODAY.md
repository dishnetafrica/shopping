# Slice 3 — Today  ·  STATUS: BUILT (overlay, ready to deploy)

## Built (what shipped)
- **Today projection** (`DailyToday extends TodayProjection`): greeting + four KPI tiles
  (To Collect / Overdue / Quotes Waiting / Collected Today) + the ranked activity list. Every figure
  is a live query (SUM open invoice balances, overdue count, quotes waiting, today's receipts) —
  **no invented numbers** (R23, ADR-006). Today is a *grouping* of the same facts the timeline reads,
  not a new data source.
- **Activity projection** (`CollectionsActivities extends ActivityProjection`): one "Collect USD X —
  <name>" per customer who owes, grouped from live invoices, ranked by value × urgency (overdue = ×2),
  each carrying the resolved `customer_id` so a tap deep-links into the customer Timeline (Slice 2).
- **Read endpoint** `GET /papi/bos-today` (`BosTodayController`) — pure read, wrapped in try/catch so a
  projection error returns `ok:false` (HTTP 200) and shows "Today unavailable" instead of white-screening.
- **UI:** the mobile "Today" tab now opens `bosToday()` (greeting + tone-accented KPI tiles + one-thumb
  to-do list). Phone landing goes to Today; **desktop is untouched** (still the classic briefing).
- Lint clean (5 files); shell JS valid; fitness green (both projections are read-only — only
  `get/sum/count/pluck`; the screen consumes a projection and emits nothing). 390px render verified.

Deploy after Slice 1 (`bos_events`) + Slice 2 (timeline). No migration.

---

## Scope
1. **Today projection** on top of the canonical Activity/Today types — no fifth projection type (ADR-014).
2. **Mobile Today screen** in the V3 shell, replacing the briefing as the phone landing.
3. **One read endpoint**; zero writes, zero new tables, zero new rules.

## Definition of Ready
- Capability **Sell / Collect** · reads Invoice + CustomerPayment + Quotation objects (current state).
- Rules: none new. Events consumed: none directly (Today reads objects; the timeline reads events).
- Projections: **Today** + **Activity** (both canonical). UI: one mobile screen (a projection).
- Tests: projection returns real figures for a tenant; endpoint never throws; fitness (read-only).

## Technical design
- `DailyToday(tenantId)->build()` → `['greeting','date','kpis'=>[4],'activities'=>slice(Collections,0,20)]`.
- `CollectionsActivities(tenantId)->build()` → ranked `collect` activities with `customer_id` for the
  timeline deep-link; groups open invoices (`status in issued|part_paid`, `balance>0.5`) by customer.
- Shell: `bosToday()` fetches `/papi/bos-today`, renders greeting + `.tdy-kpi` tiles (tone accent bar)
  + `.tdy-row` to-do list; `route('bos.timeline', customer_id)` on tap. `WS.today.route='bos.today'`;
  `landingKey()` returns `bos.today` on phones (`max-width:880px`), `briefing` on desktop.

## Migration / Rollback
- Migration: none. Rollback: revert the one web.php line + the shell edits, delete the 2 projections +
  controller. Read-only, so removal restores exact prior behaviour. No data/schema change.

## Acceptance tests
- Phone open → greeting + 4 tiles + ranked collect list; tap a row → that customer's Timeline. ✅
- "To Collect" = Σ open invoice balances; "Collected Today" = today's receipts (USD). ✅
- Nothing outstanding → "All clear", tiles read 0. Projection error → "Today unavailable" (never breaks). ✅
- Fitness: both projections read-only; the screen emits nothing. ✅  · 390px render. ✅

## Four-review gate
- Functional: the rep opens the app and instantly sees the day's money + who to chase. · Architectural:
  two canonical projections + one read endpoint; fitness green. · Operational: additive, reversible,
  can't white-screen (guarded). · UX: one-thumb, biggest/overdue first, ≤ 1 tap from work to context.
