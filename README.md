# CloudBSS — Phase 1 Brain Migration (deploy bundle)

Deterministic conversational brain ported from the production n8n workflow into
CloudBSS, plus the full test + scale suites. **Phase 1 only** (gap analysis
approved; Phase 2/3/4 frozen).

## What to deploy

These five files are the production change (drop into the repo at these paths):

```
app/Services/Bot/CatalogueMatcher.php     NEW
app/Services/Bot/ShoppingParser.php       NEW
app/Services/Bot/ClarificationFlow.php    NEW
app/Services/Bot/ShoppingEngine.php       NEW
app/Services/Bot/BotBrain.php             EDIT (keywordRespond -> engine; +tenantCatalogue/currencyFor)
```

`tests/`, `qa/`, `load/`, and the `*.md` files are CI / QA / docs — they live in
the repo but are **not** copied by your Dockerfile, so they never deploy. That's
intended.

### Integration notes
- `BotBrain`'s constructor gains 3 zero-arg services — Laravel autowires them, no
  service-provider binding needed (it's resolved from the container via the job).
- The OpenAI NLU path and `execute()/placeOrder` are untouched; the engine is the
  deterministic floor that works with the AI off.

## Contents

```
app/Services/Bot/*.php                     production code
tests/Unit/ShoppingEngineTest.php          repo unit test (php artisan test --filter=ShoppingEngineTest)
qa/conversational_commerce_suite.php       Categories 1-20 functional suite (php qa/...)
qa/performance_scale_suite.php             Categories 21-26 perf/scale suite
qa/qa_catalogue.php                        synthetic catalogue generator
qa/TEST-REPORT.md                          functional pass/fail report
qa/PERF-REPORT.md                          performance & scale report
qa/RESULTS.txt / PERF-RESULTS.txt          captured run output
load/k6_webhook.js                         staging load test (the real Category 26)
NOTES.md                                   deploy + behaviour notes
```

## Test status (captured)

- Functional (Cat 1-20): **Phase-1 scope 63/63 = 100%**; all production criteria PASS.
  The 10 non-passing are Categories 10 & 11 (advanced edits + replacements) =
  Phase 2, flagged PENDING, fail-safe (no wrong cart mods).
- Performance (Cat 21-26): **18/18 measured checks pass**. ~6 ms/message at 1000
  SKUs. Concurrency rows are projections — run `load/k6_webhook.js` on staging for
  the true end-to-end test.

## Run the suites locally

```
php qa/conversational_commerce_suite.php
php qa/performance_scale_suite.php
php artisan test --filter=ShoppingEngineTest
```

## Open decisions / Phase 2

1. **Bare list = show, not auto-add** (a verb/quantity is required to add). Confirm
   or flip (one-line change).
2. **Big-catalogue clarify friction**: generic words clarify when many SKUs share a
   price spread — add a per-item default SKU in Phase 2.
3. Infra before concurrency sign-off: per-tenant catalogue cache + messageId de-dup
   + staging k6 run.
4. Phase 2 unlocks Categories 9(remove)/10/11/12/13-flow/16 (cart-edit engine,
   reorder, checkout flow, escalation).
```
```
