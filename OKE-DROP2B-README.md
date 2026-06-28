# OKE Phase 1 — Drop 2b: Observe mode + Behavior Observatory + Projection Parity

Ships OKE in **observe mode**: every owner WhatsApp message is captured, classified and measured,
and the evening nudge runs — while the proven merchant apply-path is **untouched**. This is the
lowest-risk way to start collecting the real-owner behaviour data that should drive Phase 2. The
writer cutover (OKE actions driving live changes) is a deliberate, **data-gated** next step.

## Contents
- **Behavior Observatory** (observer of the engine, not part of it):
  - `knowledge_metrics` table + `KnowledgeMetric` model.
  - `ObservingEngine` — decorator that times each ingest and records a metric; a metrics failure
    never breaks ingest.
  - `BehaviorObservatory` — derives the bucket (`success|partial|fallback|unknown`), confidence
    (per-entity rollup), language, processing time, and a message sample.
  - `MetricsReport` — dashboards: top-20 unparsed phrases, avg confidence by capability,
    confirmation acceptance rate, result distribution, unknowns by language.
  - `LanguageDetector` — en / gu / gujlish.
- **Projection parity** (`App\Apps\DailyMenu\MenuParity` + `oke:parity`): rebuilds the Daily Menu
  projection from the knowledge store and diffs it against the live projection; logs mismatches.
  Diagnostic only — no blocking, no auto-repair.
- **`oke:nudge`** — conversational evening nudge to every tenant with an owner number, once/day,
  via the existing `NotifyOwner`. Scheduled every 15 min (per-tenant local time + once-per-day guard).
- **Capture hook**: `MerchantAssistant` now feeds every owner message to the `ObservingEngine`
  (best-effort, isolated). The live confirm/undo/apply path is unchanged.
- **Wiring**: `OkeServiceProvider` registered in `bootstrap/providers.php`; `oke:nudge` + `oke:parity`
  scheduled in `routes/console.php`.

## Why observe mode (the cutover is data-gated)
You don't need OKE to be the live *writer* to collect extraction metrics — you need it *watching*.
So Pal's can run with zero risk to the working merchant flow, and the decision to flip OKE to the
writer (and fold `DailyState` into `OperationalStateStore` — finding 2) is made from the
Observatory's acceptance/parse-rate numbers, not on faith.

## Tests
- `LanguageDetectorTest` (pure). `ObservatoryTest` (DB): success/partial/unknown bucketing, timing,
  language, sample, confirmation recording, and "observation failure never breaks ingest".
  `ProjectionParityTest` (DB): match + drift detection.
- The engine namespace stays domain-free — the architecture guard caught `MenuParity` referencing
  the `DailyMenu` model and it was moved out of the engine into the app (where it belongs).
- All files `php -l` clean; prior pure/static suites still green (17 core + 9 architecture + 19 2a).

## Deploy to Pal's (observe mode)
1. Unzip → `php artisan migrate` (adds `knowledge_metrics`).
2. `php artisan test --filter=Knowledge`.
3. Ensure Pal's owner number is authorized (owner/manager user phone, `owner_alert_phone`, or
   `merchant_admins`) so `MerchantAssistant` captures their messages.
4. Run the scheduler (`schedule:run` each minute) for `oke:nudge` + nightly `oke:parity`.

## Then: observe, don't design
After a few hundred Pal's conversations, read `MetricsReport`: top unparsed phrases, acceptance
rate, avg confidence per capability, unknowns by language. Those numbers decide what Phase 2
hardens — and when the writer cutover is justified.
