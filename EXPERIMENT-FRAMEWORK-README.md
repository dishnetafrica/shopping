# Order Engine — Experiment Framework (live vs shadow A/B) + review→replay loop

Upgrades shadow mode from a logger into an experiment framework: every production turn becomes an
automatic A/B comparison between the LIVE bot's decision and the SHADOW Order Engine's decision,
classified by reason — and every disagreement can be promoted to a replay regression in one step.
No behavior change to the live flow.

## What's here
- **`DiffClassifier`** (pure) + **`DiffReason`** taxonomy (PRODUCT, ADDRESS, PAYMENT, TIME,
  QUANTITY, WEIGHT, VARIANT, ALIAS, STATE, UNKNOWN). Per field: MATCH / DIFFER / LIVE_ONLY
  (shadow missed it = **regression**) / SHADOW_ONLY (shadow captured more, e.g. address = an
  **improvement**, not a regression).
- **Comparison in the shadow recorder** — `BotBrain` snapshots the cart before/after the turn;
  `OrderObservatory` compares what the live bot added vs what the Order Engine extracted and stores
  any disagreement in `shadow_diffs` (live_json, shadow_json, diff_json, reasons, regression flag).
- **`ReviewService`** — accept (shadow better) / reject (live right) / mark bug / **promote to
  replay**: appends a labeled case to `tests/Fixtures/order_replays.json`.
- **`ProductionReplayTest`** — data-driven: replays the promoted corpus through extract+merge and
  asserts the confirmed draft. Starts empty, grows from review — **no hand-written test code**.
- **`orders:diffs`** — CLI triage (list today's disagreements, ⚠ regressions; `--id --action`
  accept/reject/bug) until the review UI exists.
- **Report upgrades** — `OrderObservatoryReport` now also reports **intervention avoided**
  (orders handled with no clarification + clean parse — the business metric) and the
  **disagreement-reason breakdown** (where to spend engineering time).

## The loop, now physically wired
Production → shadow comparison (`shadow_diffs`) → review (`orders:diffs` / `ReviewService`) →
promote to `ProductionReplayTest` → fix → regression test → Observatory metrics improve.

## Deferred (honest scope)
- **Filament review UI** — the data + service layer is done (`ShadowDiff` + `ReviewService`); the
  tiny visual interface is a thin follow-up I'd build against the running app (can't verify Filament
  rendering here). `orders:diffs` covers triage in the meantime.
- **Confidence calibration table** — needs accumulated review labels; the shadow confidence is
  available per field, so once a week of reviewed diffs exists this becomes a report method.

## Tests
`DiffClassifierTest` (4 scenarios incl. the 1kg→WEIGHT case and address-as-improvement),
`OrderObservatoryTest`, `ProductionReplayTest` (skips while corpus empty). All `php -l` clean;
DiffClassifier 4/4 in-sandbox.

## I agree with the freeze
Architecture is frozen. The next month is the data-driven loop: run the router in shadow, collect
Observatory metrics, review disagreements, promote confirmed cases to replay tests, and only then
change parser/resolver behavior — driven by the reason breakdown, not intuition.
