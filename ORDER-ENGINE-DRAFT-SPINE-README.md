# Order Engine — Draft Order spine (Phase A + B + C + address recognizer)

The customer-side counterpart to OKE. Boundary kept clean: **OKE** = what the business knows/
operates; **Order Engine** = what the customer is buying and what's still missing. This drop is
the spine — additive and fully unit-tested. It does **not** rewire the live `BotBrain` path yet;
that wiring + the alias catalogue (Phase D) + the concrete `ProductResolver` come in the next drop.

## What's here
- **CartLine** (Phase B): `{name, product_id, variant_id, weight_grams, quantity, unit_price,
  line_total, confidence}`. Weight is first-class — the "everything becomes 1 kg" bug cannot exist,
  because a line models the weight the customer actually said.
- **DraftOrder** (Phase A): `{lines[], delivery_area, address, landmark, delivery_time,
  delivery_date, payment_method, phone, notes, waiting_for, confidence{per-field}}` with
  `missing()` / `isComplete()`.
- **EntityExtractor** (Phase C): returns ALL entities from one message and never throws info away.
  Address/time/payment/phone/area are detected and **stripped first**, then the remainder is parsed
  for products (reusing the existing `ShoppingParser` + `ProductAlias` + `LocationDictionary`).
  Verified on your headline message: `Send 250g Vanela, 250g Fafda, Ntinda, 8 AM, Mobile Money` →
  2 products @ 250g + area Ntinda + 08:00 + Mobile Money.
- **AddressRecognizer** (Phase E, the part extraction needs): condos/apartments, roads, landmarks,
  Google Maps links, pins, coordinates — so `503 Silver Condos` updates `address` and never reaches
  the product matcher.
- **DraftMerge**: applies a message's entities onto the persistent draft (fields only overwritten
  when present and at least as confident; product lines accumulate, same product+weight replaces),
  emits **timeline events**, and recomputes `waiting_for`.
- **MissingInfoDetector**: the smallest-missing-piece + a human question; low-confidence required
  fields surface for confirmation. Never re-asks what's known.
- **Persistence + Draft Order Timeline**: `order_drafts` (one open draft/conversation) +
  `order_draft_events` (append-only). `DraftOrderRepository` loads/saves and appends events — so
  "Make Vanela 500g" later just replaces the line and keeps history.
- **ProductResolver** contract: the seam where the Order Engine asks OKE/catalogue for
  product_id/variant/price (weight-aware) — concrete impl in the wiring drop.

## Tests
- Pure: `AddressRecognizerTest`, `TimeParserTest`, `EntityExtractorTest` (the multi-entity message
  + address-only→no-products), `MissingInfoDetectorTest`, `DraftMergeTest` (accumulation, weight is
  250 not 1 kg, replace-not-duplicate, timeline emitted, waiting_for = phone in your example).
- DB: `DraftOrderRepositoryTest` (persist + timeline + reload + confirm).
- **21 pure assertions pass in-sandbox** against the real `ShoppingParser`/`CatalogueMatcher`;
  all files `php -l` clean.

## Deploy
Additive. Unzip → `php artisan migrate` (adds `order_drafts`, `order_draft_events`) →
`php artisan test --filter=Orders`.

## Next drop (wiring)
1. Concrete `ProductResolver` over `CatalogueMatcher` + weight pricing (`weightPriceFor`) — this is
   where a 250 g line gets the 250 g price, killing the 1 kg bug end-to-end.
2. Wire `BotBrain`'s customer path to: load draft → `EntityExtractor` → `DraftMerge` → resolve lines
   → `MissingInfoDetector` → ask smallest missing / confirm → create order; replace the ad-hoc
   `$convo->cart` flow.
3. Phase D — aliases as first-class Product Master data (official name, aliases, search tokens,
   Gujarati, misspellings, phonetics, popularity) so `Vanela` → `Vanela Gathiya` auto-resolves.
4. Raise auto-resolve confidence so the bot stops over-asking.
