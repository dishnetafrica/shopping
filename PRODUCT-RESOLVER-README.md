# ProductResolver — ranking engine over the real Product Master

A ranking engine (not a fuzzy matcher) that resolves an extracted line (name + weight) to a
concrete catalogue line, with a transparent decision: **auto-resolve | clarify | none**. Reuses the
existing `WeightPricer` for weight rules. Data-driven — the catalogue's own aliases/variants/
popularity decide outcomes, so it learns Pal's vocabulary rather than guessing.

## What's here
- **Product Master extended** (migration 000011): `popularity`, `short_name`, `gujarati_name`,
  `default_variant_grams`, `allow_prorata`. Alias data as first-class (`product_aliases`, 000012):
  alias / misspelling / gujarati / plural / short / token, with `is_default` (e.g. `gathiya` →
  Vanela Gathiya). Learning-loop table `resolution_clarifications` (000013).
- **CandidateScorer** (pure): independent signals — exact alias, name similarity, popularity,
  **availability gate**, category context, conversation context — combined, then ranked. Auto only
  when the top score ≥ 0.80 **and** clears the runner-up by ≥ 0.15; otherwise clarify with the close
  candidates. Weights/thresholds are tunable defaults.
- **WeightResolver** (pure, reuses `WeightPricer`): exact variant wins → pro-rata only if
  `allow_prorata` → **never silently upsizes** (asks instead) → below `min_grams` asks. Fills
  `variant_id` / `unit_price`.
- **ProductResolution**: query + ranked candidates with scores + decision + resolved line — the
  debuggable report you asked for.
- **RankingProductResolver** (DB, implements the contract): loads candidates by alias + name +
  keywords from the real Product Master, ranks, weight-resolves.
- **ClarificationLogger** + `aliasProposals()`: logs every clarify (query, candidates, choice,
  reply); repeated query→choice patterns (e.g. 100× "Vanela" → Vanela Gathiya) surface as alias
  proposals for review — production traffic becomes catalogue improvements.

## Tests (pure, with Pal's-like fixtures)
- `CandidateScorerTest`: `Vanela`→Vanela Gathiya (auto); `Gathiya` default→auto; `Gathiya` ambiguous
  →clarify [Vanela Gathiya, Fafda Gathiya]; misspelling `vanilaa`→auto; unavailable→none.
- `WeightResolverTest`: exact 250g variant; 750g pro-rata when allowed; 750g→clarify when pro-rata
  off (no upsize); 50g→min; unit product→unit price.
- **10 assertions pass in-sandbox**; all files `php -l` clean. Built against the real schema
  (`ProductWeightVariant`, `WeightPricer`).

## What I need from you to finish grounding it (the catalogue is the ground truth)
The engine is data-driven, but I deliberately did **not** fabricate Pal's data. To seed and tune it:
1. **Pal's catalogue export** — products with: name, category, price, `sold_by_weight`,
   `reference_price` / `reference_weight_grams`, weight variants (grams → price), and any known
   aliases / Gujarati names / popularity. CSV or JSON is fine.
2. **`pals_chats.csv`** (the file `qa/replay_pals_phase1.php` already expects) — real customer
   messages, to add real-name `OrderReplayTest` cases and tune the scorer thresholds against actual
   traffic.

With those I will: seed `product_aliases` + popularity, tune the scorer weights/thresholds from real
clarifications, add real-name replay + resolver tests, and validate weight resolution against the
real variant tables. A small `orders:seed-aliases` import command will be tailored to your export
format.

## Deploy
Additive. Unzip → `php artisan migrate` (adds resolver columns + `product_aliases` +
`resolution_clarifications`) → `php artisan test --filter=Orders`.

## Sequence from here (your plan)
1. ProductResolver ✅ (this drop) — seed + tune once the export arrives.
2. Wire the Order Engine into `BotBrain` (load draft → extract → merge → **resolve** →
   ask-highest-priority-missing / confirm → create order).
3. Run replay tests (real Pal's conversations).
4. **Shadow mode**: existing flow creates the live order; the Order Engine reconstructs the draft in
   parallel; compare automatically and log mismatches.
5. Switch traffic when the mismatch rate is low enough.
