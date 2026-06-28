# Inventory Confidence (primitive) + Operational Timeline (ADR)

Two deliverables from the "Blinkit primitives" discussion: one built (a correctness fix), one
designed (a contract). Nothing speculative.

## Built — Inventory Confidence
The bot used to assume availability (`'stock' => $p->stock ?? 1` — unknown became "1, available").
That's certainty without evidence. Now stock carries **confidence + freshness + source**, and the
bot's answer changes with what we actually know.

| State | When | Bot says |
|---|---|---|
| **exact** | counted/adjusted within the freshness window (24h) | "17 available" / "Out of stock" |
| **estimated** | a known count gone stale, or owner says "usually" | "Usually available (last checked …)" |
| **unknown** | never counted, or explicitly cleared | "I'll confirm before placing your order" |

- Migration `…000020` adds `stock_confidence`, `stock_checked_at`, `stock_source` to `products`
  (default confidence = **unknown** — honest by default).
- `App\Services\Inventory\InventoryState` is the only writer: `recordCount()`, `markEstimated()`,
  `markUnknown()`, `decrementOnSale()` (reduces a *known* count, leaves unknown stock unknown), plus
  `state()` (derivation) and `botTag()` (catalogue annotation).
- The AI catalogue now tags each product — `[in stock: 17]` / `[usually available — confirm]` /
  `[stock unknown — confirm before promising]` / `[OUT OF STOCK]` — and a prompt rule makes the bot
  reflect it (never state a number that isn't shown as "in stock: N").
- `InventoryStateTest`: exact states the number; zero → out of stock; stale exact → estimated;
  unknown → promise nothing; a sale decrements known stock but not unknown. Derivation verified
  6/6 in-sandbox.

This is a **primitive**: substitutions, picking, and capacity (all evidence-gated) will consume it
when their triggers arrive — they aren't built here.

### Follow-up wiring (noted, not done)
`BotBrain::searchCatalogue()` still defaults `stock` to 1 internally; that result isn't the
customer-facing claim, but it should consult `InventoryState` when it next changes for a real reason.

## Designed — Operational Timeline (ADR-0001)
`docs/adr/ADR-0001-operational-timeline.md`. Defines the event envelope (event_id, event_type,
entity_type, entity_id, tenant_id, actor, source, occurred_at, payload), identity/idempotency, and a
mapping of every existing scattered event source into the timeline via read-only adapters. **No code,
no migration, no bus** — a contract so the eventual unification is intentional and the freeze holds.
