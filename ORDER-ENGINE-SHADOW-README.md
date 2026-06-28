# Order Engine — router in shadow + Conversation Observatory

Wires the `MessageRouter` into the live customer path in **shadow mode** and adds the daily KPI
Observatory. No behavior change: the live bot still replies exactly as before; we just record, per
message, what the new Order Engine *would* have done — so the cutover decision is made on production
evidence.

## What's here
- **Shadow hook** in `BotBrain::respond()` — after the live reply is computed, `orderShadow()`
  records a telemetry row (best-effort, fully isolated; a telemetry failure never affects the
  customer).
- **`OrderObservatory`** — for each incoming message records: router intent · draft_parsed? ·
  products_found · has_address/time/payment · whether the LIVE reply was a clarification · outcome ·
  processing_ms · masked sample. Table `order_flow_metrics`.
- **`OrderObservatoryReport`** + **`orders:observatory`** (scheduled daily 06:00) — the KPI line:
  `messages · orders · parser-success% · clarifications% · intent mix`, plus a per-query
  clarification table (the "where do aliases cost most" view; rate fills in once the resolver logs
  all resolutions).

## Production metrics you'll get immediately (per message)
router intent · draft parser invoked? · products found · address/time/payment present? · did the
live bot clarify? · outcome (added_to_cart / clarify / checkout / browse / other). That's the data
to confirm the router is actually reducing bad parses in live traffic.

## Precision vs coverage (your point #3)
Coverage (94% of routed orders yield a product) is already measured by `qa/corpus_report.php`.
**Precision / false-positive / false-negative / per-field replay accuracy** are measured against the
**labeled replay corpus** (`OrderReplayTest`), which grows every time a production bug is fixed —
no need to block on hand-labeling hundreds of conversations. The shadow telemetry + the replay
corpus together give both sides: live behavior and labeled accuracy.

## Tests
`OrderObservatoryTest` (order→parsed, greeting→not parsed, live clarification flagged).
`MessageRouterTest` (real-corpus cases). All `php -l` clean.

## Prerequisites (from earlier drops, applied cumulatively)
`MessageRouter` + `qa/corpus_report.php` (corpus harness), the Draft Order spine
(`EntityExtractor` etc.), and `OkeServiceProvider` registered in `bootstrap/providers.php`.

## Deploy
`php artisan migrate` (adds `order_flow_metrics`) → run the scheduler → watch
`php artisan orders:observatory` accumulate. Then per your revised order: seed/tune ProductResolver
→ alias proposals from clarification logs → expand replay tests from production failures.
