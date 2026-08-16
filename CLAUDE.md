# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**CloudBSS** (owner-facing brand **ShopBot**) is a **multi-tenant WhatsApp commerce SaaS** with a full ERP backend. Customers chat on WhatsApp, a conversational bot takes the order, and shop owners run everything from a web seller panel + Filament admin. One deployment serves many shops (tenants). Anchor tenant is *Family Shoppers* (Kampala grocery); owner/operator is Bhavin (DishNet Africa) — wants honest pushback, no fabrication, no over-engineering, complete files over diffs.

**Read `AI-HANDOVER.md` first** — it is the richest single orientation doc. Note it predates a lot of current code (features it lists as "FROZEN", e.g. the Cart Edit Engine → `app/Services/Bot/CartEditor.php`, have since shipped; the Bot brain is now ~70 classes, and `cloudbss-kernel/` has been added). Treat it as history + intent, and verify specifics against the tree.

## The repo is a Laravel *overlay*, not a runnable app

The working directory holds **application source only** — no `vendor/`, no `composer.lock`, no artisan runtime. You **cannot boot Laravel or run PHPUnit here as-is** (composer/packagist access is typically blocked in the sandbox).

- **Deploy model:** push to GitHub `dishnetafrica/shopping` → **EasyPanel builds a fresh Docker image**. The `Dockerfile` creates a clean Laravel 11 skeleton, `composer require`s the SaaS packages, then COPYs only `app/ config/ database/ routes/ bootstrap/ resources/ public/ cloudbss-kernel/ composer.json` over it. So `tests/`, `qa/`, `load/`, and all `*.md` ride along in the repo for reference/CI but **never deploy** (intended).
- **What you CAN do in the sandbox:** `php -l` (lint) after every edit; standalone PHP harnesses; real SQL via `pdo_sqlite`. Prove framework-bound logic with assertion shims / pure harnesses, and **say plainly** what must be verified on staging. Never fabricate load/queue/live-SQL numbers.
- **Discipline:** `php -l` after each edit; `grep` for callers before changing a function signature; deliver complete files.

## Financial writes — verification bar (non-negotiable)

For any write to **inventory, accounting, cashbook, ledger, stock, or payments**, never rely on unit tests or static reasoning alone:

- **Identify the authoritative writer first** — the single place that fact is persisted — and route through it. Never add a second writer for the same fact; if one exists, share or extend it (e.g. `CountRecorder` is the one physical-count writer for both the `stock:count` CLI and the panel). Duplicated financial writers drift and are the failure this rule exists to prevent.
- **Prove old/new behaviour equivalence** when changing it — a pure harness over the decision logic *and* the exact persisted values (amounts, deltas, keys, audit-note strings), not "looks right".
- **Run the tests** that cover it; if they can't run here (the sandbox can't boot Laravel/PHPUnit), say so plainly and name what stays unverified.
- **Do a controlled staging transaction before merge whenever possible** — one real write on staging, reconciled against the books/screen — because test-green is necessary, not sufficient (ADR-000 §12, "prove with real data before trusting with real money"). If a merge lands before that, the staging transaction is still **owed** and must be called out explicitly, not treated as done.

## Commands

The app itself is validated by CI (`.github/workflows/php.yml`) inside a full Laravel install against real Postgres 16, on **PHP 8.4** (runtime image is 8.3; `composer.json` requires `php ^8.2`). To reproduce what CI does *after a real `composer install`*:

```bash
# App test suites (Laravel — needs vendor/ + a DB; SQLite :memory: per phpunit.xml, Postgres in CI)
php artisan test                                  # full suite (many tests are known-red — informational)
php artisan test tests/Unit/Kernel tests/Feature/Kernel   # the gate that MUST pass (money kernel)
php artisan test --filter=SomeTestName            # single test
php artisan migrate --force                        # migrate the test DB first

vendor/bin/pint                                    # code style (Laravel Pint; dev dependency)
```

The **`cloudbss-kernel/`** is a standalone library with **its own pure-PHP harness** (no PHPUnit, no framework) — this one *does* run in the sandbox:

```bash
cd cloudbss-kernel
php tests/run.php            # golden suite      | composer test
php tests/stress.php         # stress suite      | composer stress
php infra/tests/persistence_test.php   # Postgres adapter (needs pdo_pgsql + a DB)
php infra/tests/books_test.php          # trial-balance / P&L slice
# see cloudbss-kernel/composer.json "scripts" for the full list (test-slice, test-payments, test-aging, test-interest)
```

Scheduled/queue work runs via artisan console commands (`routes/console.php`), e.g. `shopbot:process-scheduled`, `bot:watchdog`, `wa:watchdog`, `orders:staleness-watchdog`, `comms:campaign-sweep`, `billing:lifecycle`, `stock:reconcile`, `ww:scan`.

## Architecture — the two halves

### 1. The conversational brain — `app/Services/Bot/` (~70 classes)

**Deliberately deterministic PHP. There is no AI in the cart path — by design.** The pipeline:

`ShoppingParser` (splits `"rice, sugar 2kg & oil"` into items + intent; separates count vs size; ADD vs BROWSE) → `CatalogueMatcher` (synonym map incl. **Gujlish** — sakar→sugar, tel→oil, doodh→milk, atta→flour — stop words, transposition-aware fuzzy match, clarifies when one generic word maps to ≥2 SKUs with a ≥3× price spread) → `ShoppingEngine::handle()` (**pure orchestrator** over text/products/cart/state) → `BotBrain` (ties the brain to the tenant catalogue, cart, checkout, order creation) → `ClarificationFlow` (numbered-option selection).

OpenAI is used only at the edges (`BotNlu`, `AiBrain`, `MarketingBrain`, vision), never in the deterministic order-taking. Owner-set **Default Product Strategy** (`ProductDefault` model, Filament "Smart Defaults") resolves ambiguous canonical terms; stated size always beats a default.

### 2. The accounting kernel — `cloudbss-kernel/` (event-sourced, double-entry)

Governed by ADRs; **`ADR-000-erp-architecture-principles.md` is the constitution** every other ADR must obey. Non-negotiable rules that shape all financial code:

- One write model: the **event store**. State is derived by folding events into rebuildable projections; the log is the source of truth.
- Posted journals are **immutable** — corrections are new compensating entries, never edits.
- **All money is the `Money` value object** — integer-minor, currency-tagged, refuses to mix currencies. No raw floats/decimals for money anywhere.
- Every financial fact reaches the books through a domain event → `JournalPosted`. No side-channel writes to balances.
- A journal line (`JournalAmount`) holds exactly `{foreign, base}` — the *why* (rate, approver, reason) lives on the originating business event, never on the line.
- Balancing is in base currency: Σ(debits.base) == Σ(credits.base).
- Bounded contexts (Accounting, Treasury, Inventory, CRM, Reporting) interact **through events, not direct calls**.
- **`cloudbss-kernel/src/` is frozen against a hash baseline** — changing it requires an ADR + freeze ceremony + full golden/stress suites green. `app/` and `infra/` additions (policies, projections, reports) are freeze-safe.

Layout: `src/` = frozen kernel (Money, Ledger, Event, Decision, Work, Participant); `infra/` = Postgres persistence + ledger slices + reporting; `app/` = app-layer policies. Namespaces: `CloudBSS\Kernel\` → `src/`, `CloudBSS\App\` → `app/`, `CloudBSS\Infra\` → `infra/` (autoloaded from the root `composer.json` too).

## Stack & integrations

- **Laravel 11**, **Filament v3** admin (`app/Filament/`, 29 resources), **Postgres**, **Redis**, **Laravel Horizon**.
- **WhatsApp** via two interchangeable drivers behind `app/Services/WhatsApp/WhatsAppManager::forTenant()`: **Evolution API** (unofficial, `EvolutionGateway`) and **Meta Cloud API** (official, `CloudApiGateway`). Selected per-tenant / `WHATSAPP_DRIVER`.
- **Payments:** Flutterwave (MoMo, UGX) + Stripe.
- App layer beyond Bot/kernel: `app/Services/` holds ~50 domain namespaces (Ledger, Money, Vision, Trust, Winworld manufacturing, Storefront, Comms, Crm, Invoicing, Procurement, …). HTTP entrypoints in `app/Http/Controllers/{Panel,Storefront,Api,Comms,...}`.

## Production-safety / idempotency layer

Built into the real job/webhook/order/campaign paths — the chosen trade-off is **no duplicates over at-least-once replies**:

- `message_receipts` unique `(tenant_id, whatsapp_message_id)` — `MessageReceipt::claim()` drops duplicate inbound.
- `ProcessIncomingMessage` uses `WithoutOverlapping` per-conversation (one message per conversation at a time).
- `orders.idempotency_key` unique + `Cache::lock` at checkout → one checkout = one order.
- `campaign_messages` unique `(campaign_id, recipient)` — restart/retry never double-sends.

**This requires `QUEUE_CONNECTION=redis` in production** — `WithoutOverlapping` and `Cache::lock` need an atomic store; `sync`/`database` won't serialise. (CI runs `sync`, so these paths aren't exercised there.)

## Conventions & gotchas

- The seller panel is largely **one big `resources/panel/seller.html`** driven by Panel controllers' JSON APIs — edit both sides together.
- `OrderObserver` centralises `order_no` (per-tenant Redis sequence), `track_token`, and `delivered_at` stamping — don't duplicate that logic in controllers.
- Check callers before changing `NotificationService` / notification signatures.
- Webhook auth here is normal Laravel middleware — the DishNet UCRM "reject only non-empty wrong keys" convention does **not** apply.
- `routes/`: `web.php` (~930 lines, panel + storefront), `winworld.php` (Winworld manufacturing vertical), `api.php`, `console.php` (scheduler + commands).
- There is a separate static storefront generator (`package.json` → `python3 build/generate.py`, "EA Auto Part" vertical) — unrelated to the Laravel test/deploy flow above.

## Git / deploy workflow

Develop on branch **`claude/code-learning-sgzdic`**; commit with clear messages; push with `git push -u origin <branch>`. Do not open a PR unless explicitly asked. If this branch's PR is already merged, restart the branch from the latest default branch rather than stacking new commits on merged history.
