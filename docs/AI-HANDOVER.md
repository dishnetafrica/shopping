# CloudBSS / ShopBot — AI & Developer Handover

Read this first in any fresh session. It is the single source of truth for picking
up CloudBSS without re-discovering the system. Pair it with `OPERATIONS-RUNBOOK.md`
(deploy/ops) and `TROUBLESHOOTING.md`.

---

## 1. What this is
**CloudBSS** (owner-facing brand **ShopBot**) is a **multi-tenant WhatsApp grocery /
commerce SaaS**. Customers chat on WhatsApp; a conversational bot takes the order;
the shop owner runs everything from a web seller panel. One deployment serves many
shops (tenants).

- Live staging: `https://evo-shopping.1gk84r.easypanel.host` (panel at `/panel`).
- Anchor tenant: **Family Shoppers** (Kampala Indian-grocery supermarket).
- Owner/operator: **Bhavin** (DishNet Africa). Terse, mixed Gujarati/English.
  Wants honest pushback, **no fabrication**, **no over-engineering**, **complete
  files not diffs**, production-ready output.

## 2. Stack (do NOT confuse with the DishNet UCRM plugins)
This is a **modern Laravel app**, not the PHP-7.4 UCRM plugin world.
- **Laravel 11**, **PHP 8.3**, **Filament v3** (admin), **Postgres**, **Redis**.
- Queue jobs (`ShouldQueue`) — **must run on Redis** in production (see §6).
- WhatsApp via two interchangeable drivers: **Evolution API** (unofficial) and
  **Meta Cloud API** (official). Chosen per `WHATSAPP_DRIVER` / per tenant.
- Payments: **Flutterwave** (MTN/Airtel MoMo, UGX) + **Stripe** (cards).
- AI: OpenAI (used by `BotNlu` / `MarketingBrain`); the **shopping brain itself is
  deterministic PHP** (no AI in the cart path — by design).
- Repo: GitHub `dishnetafrica/shopping`. Hosting: **EasyPanel / Docker**.

## 3. Deploy model (important — the build dir is NOT a runnable app)
The working directory is a **Laravel overlay**: source files only, **no
`vendor/`, no `composer.lock`, no artisan runtime**. You cannot boot Laravel or
PHPUnit here.

- **Deploy = upload changed files to the GitHub repo → EasyPanel builds a fresh
  Laravel image.** The Dockerfile COPYs only `app/ config/ database/ routes/
  bootstrap/ resources/`. So `tests/`, `qa/`, `load/`, and `*.md` ride along in
  the repo for reference/CI but **never deploy** (intended).
- **ZIP rule:** files at repo-relative paths, **no wrapper folder**. Unzip at repo
  root, commit, push.

### Working in the build sandbox (for an AI session)
- PHP 8.3 CLI is available. Install extras as root (no sudo):
  `apt-get update && apt-get install -y php8.3-cli php8.3-mbstring php8.3-sqlite3`.
- **Can** run: `php -l` (lint), standalone PHP test harnesses, real SQL via
  `pdo_sqlite`.
- **Cannot** run here: `composer`/PHPUnit phar (packagist + phar.phpunit.de
  blocked), Laravel runtime, Postgres, Redis, queue workers, k6, anything needing
  staging network. Prove framework-bound logic with **assertion shims / pure
  harnesses**, and **say plainly** what must be verified on staging. Never fake
  load/queue/live-SQL numbers.
- Discipline: `php -l` after every edit; `grep` callers before changing a function
  signature; deliver complete files.

## 4. Repo map (what lives where)
```
app/Services/Bot/        the conversational brain (deterministic)
  CatalogueMatcher.php   synonyms, fuzzy match, clarify logic, size-aware
  ShoppingParser.php     splits "rice, sugar 2kg & oil" into items + intent
  ShoppingEngine.php     pure orchestrator handle(text,products,cart,state)
  ClarificationFlow.php  numbered-option selection
  BotBrain.php           ties brain to tenant catalogue, cart, checkout, orders
  MarketingBrain.php     marketing-tenant replies   BotNlu.php  OpenAI NLU helper
app/Jobs/                ProcessIncomingMessage (inbound), SendCampaign,
                         NotifyOwner(NewOrder), SendOrderStatusNotification
app/Http/Controllers/
  Bot/WebhookController          /api/webhook/whatsapp/{driver}
  Panel/PanelApiController       seller-panel JSON API (orders, status, products…)
  Panel/SellerPanelController    serves resources/panel/seller.html
  Panel/TrackController          customer order-tracking page
  Billing/BillingController      Flutterwave + Stripe webhooks
  Marketing/MarketingController  public marketing site
app/Models/              Tenant, Conversation, Order, OrderItem, Product, Category,
                         Campaign, Message, CustomerProfile, Payment, Rider, Branch,
                         ReturnRecord, LedgerEntry, ProductDefault,
                         MessageReceipt, CampaignMessage  (last two = prod-safety)
app/Observers/OrderObserver.php   order_no, track_token, delivered_at stamping
app/Console/Commands/ProcessScheduled.php   shopbot:process-scheduled
config/   whatsapp.php  billing.php  plans.php  tenancy.php  marketing.php
resources/panel/seller.html       the whole seller panel (single file, has in-app help)
database/migrations/              schema (incl. the 4 dated 2026_06_14_* files)
tests/  qa/  load/                 test suites + k6 (reference only, don't deploy)
```

## 5. The conversational brain (current behaviour)
- **Deterministic, no AI in the cart path.** `ShoppingEngine.handle()` is pure.
- Parsing: splits on comma/`;`/`&`/`+`/and/plus/ane/newline; separates **count vs
  size** ("2kg sugar"); intent = **ADD** (verb or quantity) vs **BROWSE** (bare
  word/list/question → show, never auto-add); **edit verbs deferred** (never mutate
  cart — see Frozen).
- Matching: synonym map (sakar→sugar, tel→oil, doodh→milk, atta→flour…), stop
  words, transposition-aware fuzzy (gated same-first-char, len≥4, dist≤1),
  clarify when one generic word maps to ≥2 SKUs with a ≥3× price spread.
- **Default Product Strategy** (shipped, approved): owner sets a default SKU per
  canonical term (`ProductDefault`, Filament "Smart Defaults"); strategy `explicit`
  = use owner default else clarify. Stated size beats default; size hint shown once
  per conversation. No category defaults. Auto-pick / store-learning / customer-
  learning are **roadmap, not built**.
- Checkout: `checkout` → ask location → `placeOrder` creates `Order` + `OrderItem`s,
  clears cart. Free plan over cap → hand to human, owner nudged once/day.

## 6. Production-safety layer (code complete, staging-verify pending)
Built in code, integrated into the real job/webhook/order/campaign paths, lint-clean,
logic + real-SQL verified in sandbox. **Not signed off** until staging load/chaos/
live-SQL pass (`PHASE2-STAGING-RUNBOOK.md`).
- **2A dedup** — `message_receipts` unique `(tenant_id, whatsapp_message_id)`;
  `MessageReceipt::claim()` at top of `ProcessIncomingMessage` drops duplicates.
- **2B ordering** — `ProcessIncomingMessage::middleware()` `WithoutOverlapping`
  per-conversation lock; one message per conversation at a time.
- **2C order idempotency** — `orders.idempotency_key` unique + checkout
  `Cache::lock`; `Order::firstOrCreate(idempotency_key)` seeded by a per-checkout
  token. One checkout = one order.
- **2D campaign** — `campaign_messages` unique `(campaign_id, recipient)`; claim
  before each send; restart/retry never double-sends.
- Helper: `app/Support/Idempotency.php` (pure keys). Trade-off chosen: **no
  duplicates over at-least-once replies** (documented in `qa/PRODUCTION-SAFETY.md`).
- **Requires `QUEUE_CONNECTION=redis`** — `WithoutOverlapping` and `Cache::lock`
  need an atomic store; `sync`/`database` won't serialise.

## 7. Plans (config/plans.php)
| plan | price | order cap | key features |
|---|---|---|---|
| free | 0 | 30/mo | bot, orders |
| starter | $20 / UGX 75,000 | unlimited | + confirmations |
| pro | $50 / UGX 185,000 | unlimited | + pos, dispatch, tracking, reports, returns, branding, multi_user |

## 8. State of play
**Built & green:** deterministic brain (Phase-1 63/63), default strategy (18/18 +
25/25 regression), performance (18/18), production-safety code + idempotency
(20/20) + real-SQL verification (0 dup), `delivered_at` fix.

**FROZEN — do not build until pilot data returns:** Cart Edit Engine (remove/
change/replace items), Loyalty, Coupons, Mobile Apps, any new feature. Also
deferred: order auto-pick ranking, store/customer learning, reorder, full
name+location checkout capture, escalation, per-tenant AI metering, vector search.

**Immediate path:** (1) deploy current drop + `delivered_at` fix, (2) run staging
verification runbook, (3) **7-day staging pilot** on Family Shopper + one
supermarket + one pharmacy, (4) produce the pilot report → then decide next phase.

## 9. Conventions / gotchas
- `NotificationService` and signatures: check callers before changing.
- Webhook auth: UCRM-style "reject only non-empty wrong keys" does **not** apply
  here; this is a Laravel app.
- Panel is one big `resources/panel/seller.html` driven by `PanelApiController`
  JSON — edit both sides together.
- `OrderObserver` centralises order_no (per-tenant Redis seq), track_token, and now
  `delivered_at` stamping on the Delivered transition.
- Placeholder phone `256700000000` still appears in the **marketing site**
  (`config/marketing.php`, `MarketingController`, `resources/marketing/index.html`,
  `index_*.html`) — replace before public launch.
