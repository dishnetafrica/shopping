# Order Engine — Draft Order spine v2 (provenance, locking, priority, replay tests)

Customer-side counterpart to OKE. Boundary: **OKE** = what the business knows/operates;
**Order Engine** = what the customer is buying and what's still missing. Additive, fully
unit-tested. Does **not** rewire the live `BotBrain` path yet; `ProductResolver` stays a contract.

## Milestone met
"Can the Order Engine reconstruct every real WhatsApp conversation from this week?" — the four
observed conversations are now regression tests and all pass (`OrderReplayTest`):
- **A** `Vanela` → captured as a product line (alias → *Vanela Gathiya* is ProductResolver/Phase D).
- **B** `300g khaman / 200g gathiya / 200g fafda / 100g jalebi` → **4 lines, weights 300/200/200/100**
  (not 1kg×4 — the bug is structurally gone).
- **C** `503 Silver Condos` → `address` set, **zero** product lines.
- **D** `250g fafda / 250g vanela / Tomorrow 8AM / Mobile Money` (phone seeded from the WhatsApp
  sender) → missing = **address only**, priority CRITICAL.

## What changed vs v1
- **Provenance per field** — every field stores `{source, confidence, message_id, updated_at}` in
  `DraftOrder->provenance`, set by `DraftMerge`. Ready for WhatsApp / website / voice / seller edits
  / AI corrections: you'll always know who changed what and why.
- **Field locking** — `DraftOrder->lock()/lockRequired()`; once locked (post-confirmation), a new
  value becomes a **pending modification** (`pendingModifications[]` + a `pending_modification`
  timeline event), never a silent edit. Matters most after payment.
- **Prioritised missing-info** — `MissingInfoDetector` returns `priorities` (CRITICAL/MEDIUM/LOW)
  and picks the highest-priority missing field first (lines/address CRITICAL, time/payment MEDIUM,
  phone LOW).
- **Resolution Report** — `ResolutionReport::from($draft)->render()` →
  `✓ lines — 2 item(s)`, `✗ address`, `✓ payment_method — Mobile Money`, … for debugging real chats.
- **Phone seeding** — a draft is created with the WhatsApp sender's number, so phone isn't
  spuriously "missing" (this is why D resolves to address-only).

## Carried from v1
`CartLine` (weight first-class) · `DraftOrder` · `EntityBundle` · address/time/payment/phone
detectors · unified `EntityExtractor` (strips location/time/payment/phone before product parsing) ·
`DraftMerge` (accumulate, replace-same-key, timeline events) · `order_drafts` + `order_draft_events`
persistence + `DraftOrderRepository` · `ProductResolver` contract.

## Tests
- Pure: `AddressRecognizerTest`, `TimeParserTest`, `EntityExtractorTest`, `MissingInfoDetectorTest`,
  `DraftMergeTest` (incl. provenance + locking→pending), `OrderReplayTest` (A–D).
- DB: `DraftOrderRepositoryTest`.
- **Sandbox: 21 spine assertions + 10 v2 assertions pass** against the real `ShoppingParser`/
  `CatalogueMatcher`; all files `php -l` clean.

## Deploy
Additive. Unzip → `php artisan migrate` → `php artisan test --filter=Orders`.

## Next (per the agreed sequence)
1. **`ProductResolver` impl** (its own focused work): aliases, spelling correction, fuzzy match,
   popularity, tenant-specific + discontinued products, substitutions, and the **weight-aware
   price** that fills `variant_id`/`unit_price`/`line_total` (completes B's totals/variants).
2. **Wire `BotBrain`** customer path: load draft → extract → merge → resolve → ask
   highest-priority missing / confirm (lock) → create order; retire the ad-hoc `$convo->cart`.
3. **Phase D** aliases as first-class Product Master data (`Vanela` → `Vanela Gathiya`).
4. **Observability**: log each ordering turn so every future mistake becomes a new `OrderReplayTest`.
