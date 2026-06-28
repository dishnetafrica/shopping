# Golden-corpus harness — Pal's chats as the regression benchmark

Turns `pals_chats.csv` into the benchmark every release must beat: route every message, replay the
order ones through the Draft Order extractor, and report what the resolver must support. Run before
every parser/resolver change.

## Run
```
php qa/corpus_report.php /path/to/pals_chats.csv
```
(PII-safe: aggregates + masked samples; the CSV itself is never committed.)

## Baseline measured on the real corpus (2,787 customer messages)
- **Routing**: greeting 41% · other 30% · question 12% · order 12% · order_update 2% ·
  delivery 1% · payment 1% · support 1%. → **~72% of messages are non-orders**; the router keeps
  them out of the Draft parser, which is the single biggest false-positive reduction.
- **Order coverage**: 14% of incoming are orders; **94% of routed orders yield ≥1 product line**
  (246 with explicit weight).
- **Media**: 94 photos · 53 voice notes · 14 contacts (route to existing vision/voice handlers).
- **~5%+ Gujlish/Gujarati**, incl. Gujarati script (e.g. શ્રીખંડ, શ્રીખંડ orders).

## What the corpus exposed (fixed / noted)
- **Fixed**: `Almond 250gms ketla tya` ("ketla" = how much) was mis-routed to ORDER because qty+unit
  fired first. `MessageRouter` now treats price-questions (ketla/kitla/ketlo/kelu/how much/price?)
  as QUESTION even with a quantity. (~58 messages corrected order/other → question.)
- **Noted**: over-capture — phrases like "arrange delivery" / "hi pals snack" parse as products.
  The ProductResolver filters these naturally (no catalogue match → not a product line); the router
  also now diverts most of them.

## Real vocabulary to seed (top order-message tokens — your alias seed list)
`jalebi, fafda, gathiya, vanela, kaju, mavo, paneer, khaman, samosa, dhokla, sev, chakri,`
`શ્રીખંડ (shrikhand), dates, almond, cashew, walnuts, apricot, pani puri, anjeer, ghee, nylon sev`

## New code
- **`app/Apps/Orders/MessageRouter.php`** — Gujlish-aware first-pass router
  (greeting/question/order/order_update/delivery/payment/support/other). Only ORDER / ORDER_UPDATE
  should reach the Draft Order parser. Pure, tested against real cases (`MessageRouterTest`).
- **`qa/corpus_report.php`** — the analytics + baseline harness above.

## Next (the feedback loop)
1. **Auto-alias proposals (Phase 3)**: extend the report to emit `query → most-frequent-resolution`
   pairs from the corpus + the live `ResolutionClarification` log (e.g. "Vanela ×347 → Vanela
   Gathiya") for one-click review into `product_aliases`.
2. **Daily Conversation Observatory**: a scheduled command emitting the KPI line
   (messages, orders, completed, parser-success %, clarifications %, unknown products/addresses/
   payment/time) so "is the bot getting better?" becomes a measured number.
3. **Wire `MessageRouter` into `BotBrain`** so only ORDER messages hit the Draft parser in production.
4. Keep `pals_chats.csv` as the **golden corpus**: every parser/resolver change is replayed against
   it (and grows with new production conversations) before deploy.
