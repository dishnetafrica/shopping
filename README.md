# CloudBSS / ShopBot

Multi-tenant **WhatsApp grocery & commerce SaaS**. Customers chat on WhatsApp, a
deterministic bot takes the order, and each shop owner runs everything from a web
seller panel. One deployment serves many shops (tenants).

> **Reading this as an AI/dev picking up the project?** This file is the full
> orientation — read it top to bottom first. Deeper detail lives in `docs/`
> (handover, operations, troubleshooting, pilot, module specs). Don't guess at
> architecture or product facts that aren't here; check the code or ask.

- **Live (staging):** `https://evo-shopping.1gk84r.easypanel.host` (panel at `/panel`)
- **Anchor tenant:** Family Shoppers (Kampala Indian-grocery supermarket)
- **Repo:** GitHub `dishnetafrica/shopping` · **Host:** EasyPanel / Docker

---

## Table of contents
1. [Working agreement](#1-working-agreement)
2. [Stack](#2-stack)
3. [Deploy model](#3-deploy-model-the-build-dir-is-not-a-runnable-app)
4. [Working in the build sandbox (AI sessions)](#4-working-in-the-build-sandbox-ai-sessions)
5. [Repo map](#5-repo-map)
6. [The conversational brain](#6-the-conversational-brain)
7. [Production-safety layer](#7-production-safety-layer)
8. [Data model](#8-data-model)
9. [Plans](#9-plans)
10. [State of play](#10-state-of-play)
11. [Operational essentials](#11-operational-essentials)
12. [Tests & verification](#12-tests--verification)
13. [Conventions & gotchas](#13-conventions--gotchas)
14. [Companion docs](#14-companion-docs)

---

## 1. Working agreement
Owner/operator: **Bhavin** (DishNet Africa; Kampala / Juba / Jamnagar). Terse, mixed
Gujarati/English. Expectations for anyone (human or AI) working on this:
- **No fabrication.** If something can't be verified here, say so. Never invent test
  output, load numbers, or SQL results.
- **No over-engineering.** Ship the smallest correct thing. Mark MVP vs later.
- **Complete files, not diffs.** Deliver whole files ready to drop in.
- **Production-ready.** Lint, test what's testable, be explicit about what needs staging.
- **Honest pushback** is wanted over agreement.

## 2. Stack
A modern Laravel app — **not** the PHP-7.4 DishNet UCRM plugin world (don't confuse them).
- **Laravel 11**, **PHP 8.3**, **Filament v3** (admin), **Postgres**, **Redis**.
- Queue jobs (`ShouldQueue`); **production requires `QUEUE_CONNECTION=redis`** (see §7).
- WhatsApp via two interchangeable drivers: **Evolution API** (unofficial) and
  **Meta Cloud API** (official), selected by `WHATSAPP_DRIVER` / per tenant instance.
- Payments: **Flutterwave** (MTN/Airtel MoMo, UGX) + **Stripe** (cards).
- AI: OpenAI powers `BotNlu` / `MarketingBrain`. **The shopping/cart path is
  deterministic PHP — no AI in it, by design** (determinism + cost control).
- Multi-tenancy: one shared Postgres DB; every tenant-owned table has `tenant_id`; the
  `BelongsToTenant` trait auto-filters queries and stamps `tenant_id` on insert.

## 3. Deploy model (the build dir is NOT a runnable app)
The working tree is a **Laravel overlay**: source files only — **no `vendor/`, no
`composer.lock`, no artisan runtime**. You cannot boot Laravel/PHPUnit from it.

- **Deploy = push changed files to GitHub → EasyPanel rebuilds the Docker image.**
  The Dockerfile COPYs only `app/ config/ database/ routes/ bootstrap/ resources/`.
  So `tests/`, `qa/`, `load/`, and `*.md` live in the repo for reference/CI but
  **never deploy** (intended).
- **ZIP rule:** files at repo-relative paths, **no wrapper folder**. Unzip at repo
  root, commit, push.
- After deploy, in the container: `php artisan migrate --force && php artisan optimize:clear`.

## 4. Working in the build sandbox (AI sessions)
- PHP 8.3 CLI is available. Install extras as root (no sudo):
  `apt-get update && apt-get install -y php8.3-cli php8.3-mbstring php8.3-sqlite3`.
- **Can run here:** `php -l` (lint), standalone PHP test harnesses, real SQL via
  `pdo_sqlite`.
- **Cannot run here:** `composer` / PHPUnit phar (packagist + phar.phpunit.de
  blocked), Laravel runtime, Postgres, Redis, queue workers, k6, staging network.
  Prove framework-bound logic with **assertion shims / pure harnesses** and state
  plainly what must be verified on staging. **Never fake** load/queue/live-SQL output.
- Discipline: `php -l` after every edit; `grep` callers before changing a signature;
  deliver complete files.

## 5. Repo map
```
app/Services/Bot/        deterministic conversational brain
  CatalogueMatcher.php   synonyms, stop-words, fuzzy (typo-fallback), clarify, size-aware
  ShoppingParser.php     splits "rice, sugar 2kg & oil"; intent ADD vs BROWSE vs decline
  ShoppingEngine.php     pure orchestrator handle(text, products, cart, state)
  ClarificationFlow.php  numbered-option selection
  BotBrain.php           catalogue/cart/checkout/orders + greetings, declines, affirmations
  MarketingBrain.php     marketing-tenant replies     BotNlu.php  OpenAI NLU helper
app/Jobs/                ProcessIncomingMessage (inbound), SendCampaign,
                         NotifyOwner / NotifyOwnerNewOrder, SendOrderStatusNotification
app/Http/Controllers/
  Bot/WebhookController          /api/webhook/whatsapp/{driver}
  Panel/PanelApiController       seller-panel JSON API (orders, status, products, dispatch...)
  Panel/SellerPanelController    serves resources/panel/seller.html
  Panel/TrackController          customer order-tracking page (by order track_token)
  Billing/BillingController      Flutterwave + Stripe webhooks
  Marketing/MarketingController  public marketing site
app/Models/              Tenant, Conversation, Order, OrderItem, Product, Category,
                         Campaign, Message, CustomerProfile, Payment, Rider, Branch,
                         ReturnRecord, LedgerEntry, ProductDefault,
                         MessageReceipt, CampaignMessage     (last two = prod-safety)
app/Observers/OrderObserver.php   order_no (per-tenant Redis seq), track_token, delivered_at
app/Support/Idempotency.php       pure idempotency-key helpers
app/Console/Commands/ProcessScheduled.php   shopbot:process-scheduled (run every minute)
app/Services/Marketing/AudienceResolver.php audience segments (recent/inactive/vip/category/all)
config/   whatsapp.php  billing.php  plans.php  tenancy.php  marketing.php
resources/panel/seller.html       the whole seller panel (one file, has in-app help)
database/migrations/              schema
tests/  qa/  load/                 PHPUnit + standalone suites + k6 (reference; not deployed)
docs/                              handover, operations, troubleshooting, pilot, specs
```

## 6. The conversational brain
**Deterministic, no AI in the cart path.** `ShoppingEngine::handle()` is pure.
- **Parsing:** splits on comma/`;`/`&`/`+`/and/plus/ane/newline; separates **count vs
  size** ("2kg sugar"); intent = **ADD** (verb or quantity) vs **BROWSE** (bare
  word/list/question -> show, never auto-add); **edit verbs deferred** (never mutate cart).
- **Matching:** synonym map (sakar->sugar, tel->oil, doodh->milk, atta->flour...), stop-words,
  transposition-aware fuzzy. **Fuzzy is a typo *fallback* only** — a query word that
  matches a product exactly anywhere won't also fuzzy-pull look-alikes (so "rice"
  never drags in "race"; "rcie" still resolves to "rice"). Clarify when one generic
  word maps to >=2 SKUs with a >=3x price spread.
- **Declines:** "no", "cancel", "nothing", "i don't want anything", "not interested",
  etc. get a friendly prompt and **never** run a product search (this was a real pilot
  bug — filler words were fuzzy-matching products like Dent/Donut).
- **Default Product Strategy** (shipped): owner sets a default SKU per canonical term
  (`ProductDefault`, Filament "Smart Defaults"); strategy `explicit` = use owner
  default else clarify. Stated size beats default; size hint shown once per
  conversation. No category defaults. Auto-pick / store-learning / customer-learning
  are roadmap, not built.
- **Checkout:** `checkout` -> ask location -> create `Order` + `OrderItem`s (idempotent),
  clear cart. Free plan over cap -> hand to human, owner nudged once/day.

## 7. Production-safety layer
Code-complete, integrated into the real job/webhook/order/campaign paths, lint-clean,
logic + real-SQL verified in sandbox. **Not signed off** until staging load/chaos/
live-SQL pass (see `docs/PILOT-PLAYBOOK.md` and the staging runbook).
- **2A dedup** — `message_receipts` unique `(tenant_id, whatsapp_message_id)`;
  `MessageReceipt::claim()` at top of `ProcessIncomingMessage` drops duplicates.
- **2B ordering** — `ProcessIncomingMessage::middleware()` `WithoutOverlapping`
  per-conversation lock; one message per conversation at a time.
- **2C order idempotency** — `orders.idempotency_key` unique + checkout `Cache::lock`;
  `Order::firstOrCreate(idempotency_key)` seeded by a per-checkout token.
- **2D campaign** — `campaign_messages` unique `(campaign_id, recipient)`; claim before
  each send; restart/retry never double-sends.
- Helper: `app/Support/Idempotency.php`. Deliberate trade-off: **no duplicates over
  at-least-once replies** (a crash mid-process may drop one reply, never corrupts a
  cart/order).
- **Requires `QUEUE_CONNECTION=redis`** — `WithoutOverlapping` + `Cache::lock` need an
  atomic store; `sync`/`database` will not serialise.

## 8. Data model
Every table carries `tenant_id` (multi-tenant). Key models: `Tenant`, `Conversation`
(state + cart as JSON), `Order` + `OrderItem`, `Product` + `Category`, `Campaign`,
`Message`, `CustomerProfile`, `Payment`, `Rider`, `Branch`, `ReturnRecord`,
`LedgerEntry`. Production-safety/feature tables: `ProductDefault`, `MessageReceipt`,
`CampaignMessage`. `OrderObserver` centralises `order_no` (per-tenant Redis sequence),
`track_token`, and **`delivered_at`** (stamped when status -> Delivered; cleared if
reverted). Orders delivered before that fix need a one-time backfill — see operations doc.

## 9. Plans (`config/plans.php`)
| plan | price | order cap | key features |
|---|---|---|---|
| free | 0 | 30/mo | bot, orders |
| starter | $20 / UGX 75,000 | unlimited | + confirmations |
| pro | $50 / UGX 185,000 | unlimited | + pos, dispatch, tracking, reports, returns, branding, multi_user |

## 10. State of play
**Built & green:** deterministic brain (Phase-1 63/63), default strategy (18/18 +
25/25 regression), performance (18/18), production-safety code + idempotency (20/20) +
real-SQL verification (0 dup). Recent pilot fixes: `delivered_at` stamping; decline +
fuzzy-fallback bot fix (15/15).

**Next roadmap — customer-value modules** (full spec in
`docs/CUSTOMER-VALUE-MODULES-SPEC.md`). Recommended build order by value/effort:
1. **Customer Reorder** (quick revenue+retention; data already exists)
2. **Customer CRM (lite)** (segments/LTV — foundation for the rest)
3. **Marketing Campaigns V2** (segment broadcast + abandoned-cart automation)
4. **Delivery Management V2** (zones, fees, board, proof-of-delivery; extends Dispatch)
5. **Loyalty & Rewards** (stamps/points; build last)

**FROZEN until pilot data decides:** Cart Edit Engine (remove/change/replace items),
Loyalty, Coupons, Mobile Apps, and any feature not in an approved phase. Also deferred:
order auto-pick ranking, store/customer learning, reorder subscriptions, full
name+location checkout capture, escalation, per-tenant AI metering, vector search.

**Immediate path:** deploy current drop + fixes -> run staging verification ->
**7-day staging pilot** (Family Shopper + one supermarket + one pharmacy) -> produce the
pilot report (most common actions / failures / requested features) -> then pick the
next phase. Real customer chats are being pasted in and fixed as bugs surface.

## 11. Operational essentials
Full detail in `docs/OPERATIONS-RUNBOOK.md`. Minimum to run:
- **Env:** `APP_KEY`, `QUEUE_CONNECTION=redis`, Postgres (`DB_*`), Redis (`REDIS_*`),
  `WHATSAPP_DRIVER` + `EVOLUTION_*` and/or `WHATSAPP_CLOUD_*`, `FLW_*`, `STRIPE_*`,
  `OPENAI_*`, `MARKETING_*`, `APP_TENANT_ROOT_DOMAIN`.
- **Queue worker:** Horizon (or `queue:work redis --tries=25`) running continuously.
- **Scheduler:** `* * * * * php artisan schedule:run` (drives `shopbot:process-scheduled`).
- **Webhooks:** WhatsApp -> `/api/webhook/whatsapp/{evolution|cloud}`; Flutterwave ->
  `/api/billing/flutterwave/webhook`; Stripe -> `/api/billing/stripe/webhook`. Tenant is
  resolved by the `whatsapp_instance` that received the message.
- **Go-live still pending:** set FLW/Stripe keys + register webhooks; rotate
  `EVOLUTION_API_KEY`; replace placeholder phone `256700000000` in the marketing site;
  set `owner_alert_phone` per tenant; run the staging verification.

## 12. Tests & verification
Standalone suites (run with `php qa/<file>` inside the full repo):
- `qa/conversational_commerce_suite.php` — Phase-1 brain 63/63
- `qa/default_strategy_suite.php` (18/18) · `qa/final_regression_suite.php` (25/25)
- `qa/performance_scale_suite.php` (18/18) · `qa/idempotency_suite.php` (20/20)
- `qa/decline_and_fuzzy_fix_suite.php` (15/15, transcript bugs)

PHPUnit (`php artisan test` on a deployed/staging app): `tests/Unit/*`,
`tests/Feature/ProductionSafetyTest.php` (DB-backed). Staging load/chaos/queue/live-SQL:
`load/k6_*.js` + the staging runbook.

## 13. Conventions & gotchas
- Panel is one big `resources/panel/seller.html` driven by `PanelApiController` JSON —
  edit both sides together.
- Any money/points/order/send side-effect uses `app/Support/Idempotency.php` — no
  double-charge / double-earn / double-send.
- All outbound marketing must honour `marketing_opt_out` and the send throttle
  (`SendCampaign` spaces sends 4-9s to protect the WhatsApp number from bans).
- Check callers before changing a service signature; `php -l` after every edit.
- This is **Laravel 11 / PHP 8.3 / Postgres** — UCRM-plugin habits (PHP 7.4,
  `return;`-in-cron, webhook-secret quirks) do **not** apply here.

## 14. Companion docs (`docs/`)
- `AI-HANDOVER.md` — deeper continuation notes for a fresh session.
- `OPERATIONS-RUNBOOK.md` — deploy, env, webhooks, queue/scheduler, go-live checklist.
- `SELLER-PANEL-GUIDE.md` — owner-facing how-to (hand to pilot shops).
- `TROUBLESHOOTING.md` — symptom -> cause -> fix.
- `PILOT-PLAYBOOK.md` — the 7-day pilot + data to collect + report template.
- `CUSTOMER-VALUE-MODULES-SPEC.md` — full specs for the 5 roadmap modules.
