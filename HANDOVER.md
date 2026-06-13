# ShopBot — Handover & Continuation Guide

_Last updated: 14 Jun 2026. Keep this file in the repo root and update it as the system evolves._

---

## 1. What this is

**ShopBot** is a multi-tenant SaaS: every business (a "tenant") gets a **WhatsApp ordering bot** + a **web admin panel**. It's built to onboard many supermarkets, especially in Uganda (it reads the standard Uganda POS pricelist export format directly).

- **First live tenant:** Family Shoppers (Indian grocery, Kampala). WhatsApp number **+256 731 002066** via Evolution instance **`savan`**.
- It is a **greenfield Laravel rebuild** of an older n8n-based prototype. Do NOT migrate n8n logic; the new platform is self-contained.

---

## 2. Live environment (where everything runs)

| Thing | Value |
|---|---|
| App URL | `https://evo-shopping.1gk84r.easypanel.host` |
| Operator panel | `/admin` (super-admin: manage businesses) |
| Business panel | `/app` (staff: products, orders, riders, POS, categories, settings) |
| Bot webhook | `/api/webhook/whatsapp/evolution` |
| Host | EasyPanel @ `167.99.93.203`, project **`evo`** |
| App service | **`shopping`** (built from GitHub via Dockerfile) |
| Postgres service | **`saaspg`** → internal host `evo_saaspg`, db `evo`, user `postgres` |
| Redis service | **`redissaas`** → internal host `evo_redissaas` |
| GitHub repo | `github.com/dishnetafrica/shopping` (private), branch `main` |
| Evolution API | `https://evo-evolution-api.1gk84r.easypanel.host` |

**Logins**
- `/admin`: the `ADMIN_EMAIL` / `ADMIN_PASSWORD` set in the EasyPanel env (super-admin).
- `/app`: seeded staff user `staff@familyshoppers.test` (change the password).

**Secrets** (DB/Redis passwords, `APP_KEY`, `OPENAI_API_KEY`, `EVOLUTION_API_KEY`, admin creds) live ONLY in **EasyPanel → app `shopping` → Environment**. Never hardcode them. If sharing this doc, values are not in it on purpose.

---

## 3. Stack

Laravel 11 · PHP 8.3 · **Filament v3** (admin UI) · Livewire 3 · Postgres · Redis (queue + cache) · `openai-php/laravel` (bot NLU) · Evolution API (WhatsApp). Queue worker + scheduler run inside the container via supervisor.

---

## 4. How build & deploy works (unusual — read this)

- The GitHub repo holds **only application code** (an "overlay") — **no** Laravel skeleton, **no** `vendor/`, **no** `composer.lock`.
- The **Dockerfile** (multi-stage) does the assembly at build time:
  1. `composer create-project laravel/laravel` → fresh Laravel 11 skeleton.
  2. `composer require` Filament + predis + horizon (+ openai).
  3. Copies our `app/ config/ database/ routes/ bootstrap/ resources/` **over** the skeleton.
  4. Builds the runtime image (php-fpm + nginx + supervisor).
- **`docker/entrypoint.sh`** on boot: waits for Postgres → `migrate --force` → seed `InitialSeeder` → `storage:link` → `filament:assets` → `config:cache` → starts supervisord.
- Container serves **nginx on port 80** → EasyPanel **proxy port = 80**.
- **Persistent volume** mounted at `/var/www/html/storage/app/public` (uploaded product/rider images survive redeploys).

**Deploy loop (how the developer works):**
1. Edit/add files in the GitHub repo (web editor, or upload folders from the zip).
2. EasyPanel → app `shopping` → **Deploy** (or enable Auto Deploy so every push deploys).
3. Migrations run automatically on boot.

There is **no local PHP/Composer/Docker** in the dev workflow — code is edited in GitHub and built by EasyPanel. So an AI dev should always hand over **complete files** to paste, never diffs.

---

## 5. Multi-tenancy (how data is isolated)

- **Single shared Postgres**, row-level by `tenant_id`.
- Models that belong to a tenant use the **`BelongsToTenant`** trait (`app/Models/Concerns/`): a global scope filters every query to the active tenant and stamps `tenant_id` on create.
- **`TenantContext`** (`app/Support/TenantContext.php`) holds the active tenant id for the request/job.
- **`SetTenantFromUser`** middleware sets it from the logged-in user. Registered on both panels with **`isPersistent: true`** — this is REQUIRED so it runs on Livewire requests (form saves, imports), not just page loads. Without it, writes insert `tenant_id = null` and fail.
- Bot resolves the tenant by the **Evolution instance** that received the message (`Tenant.whatsapp_instance`).

---

## 6. Key files map

```
app/Support/TenantContext.php            current tenant id (per request/job)
app/Models/Concerns/BelongsToTenant.php  global scope + auto-stamp tenant_id
app/Models/{Tenant,User,Product,Order,OrderItem,Rider,Branch,Conversation,Category}.php
app/Http/Middleware/SetTenantFromUser.php   sets TenantContext from the logged-in user

WhatsApp / bot:
  routes/api.php                          the webhook route
  app/Http/Controllers/Bot/WebhookController.php   resolves tenant, dispatches job
  app/Jobs/ProcessIncomingMessage.php     queued: sets tenant, calls brain, replies
  app/Services/Bot/BotBrain.php           NLU-first dispatch + deterministic cart/checkout + keyword fallback
  app/Services/Bot/BotNlu.php             OpenAI intent + item extraction (never sets prices)
  app/Services/Catalogue/ProductSearch.php  tenant-scoped ILIKE search
  app/Services/Pricing.php                discount + currency formatting from tenant settings
  app/Contracts/WhatsAppGateway.php + app/Services/WhatsApp/*  Evolution driver now, Cloud-API driver stub
  app/Observers/OrderObserver.php         order_no on create + status-change notification
  app/Jobs/SendOrderStatusNotification.php  WA message when order status changes

Admin UI (Filament):
  app/Providers/Filament/AppPanelProvider.php    /app  (staff)  — isPersistent tenant mw
  app/Providers/Filament/AdminPanelProvider.php  /admin (operator)
  app/Filament/Resources/ProductResource.php (+Pages/) — CRUD, CSV import, image url-or-upload
  app/Filament/Resources/{Order,Rider,Category}Resource.php (+Pages/)
  app/Filament/Admin/Resources/TenantResource.php — onboard businesses
  app/Filament/Pages/{Settings,Pos}.php + resources/views/filament/pages/*.blade.php
  app/Filament/Widgets/{OrdersStatsOverview,OrdersChart}.php — dashboard
  app/Services/Catalogue/ProductImporter.php — CSV import (Uganda POS aliases, bulk insert, replace/merge)

Infra:
  Dockerfile, docker/{entrypoint.sh,nginx.conf,supervisord.conf,php.ini}
  database/migrations/2026_01_01_0000xx_*.php
  database/seeders/InitialSeeder.php — super-admin + demo Family Shoppers tenant + sample products
```

---

## 7. How the bot works

1. Evolution POSTs an incoming message to `/api/webhook/whatsapp/evolution`.
2. `WebhookController` finds the tenant by the receiving instance, dispatches `ProcessIncomingMessage`.
3. The job sets `TenantContext`, marks the message read, calls `BotBrain->respond()`, sends the reply via the tenant's WhatsApp gateway.
4. `BotBrain`: tries `BotNlu` (OpenAI) → `{intent, items[]}`; resolves items to real catalogue products (prices come from DB, never the model); builds the cart; on `checkout` asks for location then creates an `Order` (+`OrderItem`s). If OpenAI is unavailable, a keyword parser handles the same flow.
5. Order status changes (panel or bot) fire `SendOrderStatusNotification` → WhatsApp update to the customer.

**OpenAI:** `OPENAI_API_KEY` + `OPENAI_MODEL` (default `gpt-4o-mini`) in env. If absent, bot still works via keywords.

---

## 8. ⚠️ WhatsApp connection status (do not break the live customer)

Family Shoppers is **live on `savan` with real customers**, currently served by the **older n8n prototype**. The new platform's webhook is **not** yet the active webhook on `savan`. Before pointing `savan` at the new platform, add a **bot on/off per tenant** and ideally a "only reply to unknown numbers" guard, and test on a **separate test number** first.

---

## 9. Hard-won gotchas (read before editing)

- **Composer advisory block:** Composer refuses advisory-flagged packages; the Dockerfile sets `composer config --global policy.advisories.block false`. Keep it.
- **Filament closures resolve by NAME:** column callbacks must use `$state` / `$record` (not `$s`, `$r`) or Filament 500s with a `BindingResolutionException`.
- **`isPersistent: true`** on both panels' `authMiddleware` is mandatory (see §5).
- **HTTPS behind proxy:** `AppServiceProvider` forces `https` in production + `trustProxies('*')` in `bootstrap/app.php`, so Filament assets aren't blocked as mixed content.
- **Big imports:** use chunked bulk insert (replace mode), never per-row, or 15k rows times out.
- **No `route:cache`** in the entrypoint (the `/` route uses a closure; `config:cache` only).
- **Blind coding:** there's no local PHP — code is written without running it. Always brace/paren balance-check, expect the *first* deploy of new Filament code to occasionally need a one-line fix, and debug by setting `APP_DEBUG=true` and reading the on-screen error or **EasyPanel → app → Logs**.

---

## 10. Data model (tables)

`tenants` · `users` (`tenant_id`, `is_super_admin`, `role`) · `products` · `riders` · `branches` · `orders` · `order_items` · `conversations` · `categories` · plus Laravel defaults (`users`/`cache`/`jobs`/`sessions`).

---

## 11. Done so far

- ✅ Deployed on EasyPanel (Postgres + Redis + queue + scheduler), HTTPS, push-to-deploy.
- ✅ Operator panel (onboard businesses) + business panel (Products, Orders, Riders, Settings, POS, Categories).
- ✅ Smart bot (OpenAI NLU + keyword fallback), deterministic cart/checkout, status notifications.
- ✅ CSV import understanding the Uganda POS pricelist format; 15,229 real products loaded for Family Shoppers.
- ✅ Dashboard widgets (stat cards + orders chart), Category management, POS screen.

## 12. Roadmap / what's next

- **Bot category browsing** (15k items is hard to type-search): "categories" → pick one → list.
- **Customer order-tracking page** (Blade; `orders.track_token` already exists).
- **Bot on/off per tenant** + unknown-number guard (before going live on `savan`).
- **WhatsApp Cloud API driver** (production-grade vs Evolution ban risk at scale) — gateway interface already exists.
- **Operator billing / plans.**
- **Dispatch screen** + rider photo to customer on dispatch.
- **Fast search at scale** (Postgres full-text or Meilisearch) once catalogues are large.
- **Multilingual bot replies** (understanding is already multilingual; replies are English).

---

## 13. How to brief the next AI developer

Give the new chat **these three things**:

1. **This file** (`HANDOVER.md`).
2. **The current code** — either point it at `github.com/dishnetafrica/shopping` or upload the latest `shopbot-saas.zip`.
3. **The way you work:** "I edit files in GitHub and deploy via EasyPanel; I don't run composer/docker locally. Give me complete files to paste, not diffs. When something breaks I'll paste the EasyPanel log or the on-screen error."

**Ready-to-paste opening message for a new chat:**

> I'm continuing work on ShopBot, my multi-tenant WhatsApp ordering SaaS (Laravel 11 + Filament v3 + Postgres + Redis on EasyPanel). I'm attaching HANDOVER.md and the project zip. I deploy by editing files in my GitHub repo (dishnetafrica/shopping) and clicking Deploy in EasyPanel — I do NOT run composer/docker/php locally, so always give me complete files to paste, never diffs. The app is live at evo-shopping.1gk84r.easypanel.host. Read HANDOVER.md first, then help me with: [your task]. When you need to see an error, I'll screenshot the EasyPanel Logs or the browser error.

Keep changes consistent with the conventions in §5 and §9, and update this file when you add features.

---

## 14. Change log

_Newest first. Every session appends one entry here: date, who/what, and a one-line summary of what changed. Bump the "Last updated" date at the top of this file too._

### 2026-06-14 — Phase 18: Cashbook + order payments with customer receipt (Bhavin + AI)
- **Cashbook** (hybrid-ledger style, single-currency UGX, tenant-isolated). New `ledger_entries` table + `LedgerEntry` model (type in/out, category order_payment|expense|supplier|owner_draw|other, optional order_id, method, received_by, note). Running cash-on-hand = sum(in) − sum(out).
- **Order payments.** New `orders.amount_paid` column + Order helpers `balanceDue()` / `paymentState()` (unpaid|partial|paid) + `payments()` relation. `recordPayment` endpoint registers an order payment, bumps amount_paid, and **WhatsApps the customer a receipt** ("Payment received… paid in full / balance left UGX Y") via `forTenant()`. Handles part-payments.
- **Money in/out** via `cashbookAdd` for expenses/supplier/owner-draw/other income ("pay as per requirement").
- **New Cashbook page** `/panel/cashbook` (`cashbook.html`, served by `SellerPanelController::cashbook()`): balance + period totals, "record payment for an order" (owing-orders picker, prefilled balance, notify toggle), "add money in/out", recent-entries table. Added a **💰 Cashbook** link to the seller nav.
- Migration `2026_01_01_000015` (idempotent: hasTable/hasColumn guards). Kept separate from the subscription `payments` table (what shops pay CloudBSS) — this is each shop's own till.
- Added/changed: migration 000015, LedgerEntry.php, Order.php, PanelApiController.php (cashbook/cashbookAdd/recordPayment + import), SellerPanelController.php (cashbook page), routes/web.php, resources/panel/cashbook.html (new), resources/panel/seller.html (nav link), HOW-TO-GUIDE.md (§8a).

### 2026-06-14 — Phase 17: Official Cloud API (BYO, per-tenant) + marketing page wired + HOW-TO guide (Bhavin + AI)
- **Per-tenant WhatsApp driver.** `WhatsAppManager::forTenant($tenant)` now resolves the gateway per shop and, for the cloud driver, builds `CloudApiGateway` with THAT tenant's own access token (`settings.cloud_token`). All five send sites switched from `driver($t->whatsapp_driver)` to `forTenant($t)`: NotifyOwner, NotifyOwnerNewOrder, ProcessIncomingMessage, SendOrderStatusNotification, PanelApiController::chatSend.
- **Official Cloud API is BYO, gated to Pro.** New panel endpoints `wa/cloud-info`, `wa/cloud-save` (Pro-gated; stores phone_number_id as `whatsapp_instance`, token/WABA/display in settings, sets driver=cloud), `wa/use-evolution` (switch back). New "Use the official WhatsApp API" card in `setup.html` shows the fields + the exact Callback URL & Verify token to paste into Meta.
- **Webhook handshake.** `WebhookController` now answers Meta's GET verification (`hub.challenge`) against `config('whatsapp.cloud_verify_token')` (env `WHATSAPP_CLOUD_VERIFY_TOKEN`, default `cloudbss-verify`); POST path unchanged, tenant routed by phone_number_id. The Cloud driver itself (`CloudApiGateway`) was already implemented — only per-tenant creds + verify were missing.
- **Marketing page is live at `/`** — wired the chosen design (`index_chosen.html`, iPhone-mock hero + animated chat + logins) into `resources/marketing/index.html`.
- **HOW-TO-GUIDE.md added** — full end-to-end: deploy, env vars, first-run, both WhatsApp connection options, bot setup, plans/payments, go-live checklist, troubleshooting, file map.
- No migration needed (`whatsapp_driver` column + `settings` JSON already existed).
- Added/changed: WhatsAppManager.php, CloudApiGateway.php (already done), WebhookController.php, PanelApiController.php, routes/web.php, config/whatsapp.php, resources/panel/setup.html, resources/marketing/index.html, the 4 jobs, HOW-TO-GUIDE.md.

### 2026-06-14 — Phase 16: CloudBSS marketing page is now the front door (/) (Bhavin + AI)
- `/` no longer redirects to `/panel` — it now serves the **CloudBSS marketing landing page** (`resources/marketing/index.html`) via `MarketingController::home()`. The page is now part of THIS app, so it deploys together (no separate cloudbss-site service to maintain).
- **Login entry points added to the page**: nav **Log in** → `/app/login` (shop owners); footer **Account → Shop owner login** (`/app/login`) and **Operator login** (`/admin/login`, that's us). `/panel`, `/app`, `/admin` all unchanged.
- **Contact points are config-driven** (`config/marketing.php`): `MARKETING_WA_NUMBER` / `MARKETING_PHONE` / `MARKETING_EMAIL`. Page ships with placeholder `256700000000`; set the env var once the real CloudBSS marketing WhatsApp line is connected and every wa.me/tel: link updates automatically (no HTML edit). Served raw (not Blade) so its CSS/JS braces are safe.
- Next (Part B, see Roadmap): give CloudBSS its OWN marketing WhatsApp number + auto-reply bot — planned as a dedicated "operator/marketing" tenant reusing the existing connect + chats + bot machinery, with a sales/FAQ bot persona instead of grocery-ordering.
- Added/changed: routes/web.php (`/` route), app/Http/Controllers/Marketing/MarketingController.php (new), config/marketing.php (new), resources/marketing/index.html (new — page + login links + .nav-login style).

### 2026-06-14 — Phase 15: "Re-link webhook" button — fix missing incoming messages (Bhavin + AI)
- **Symptom**: a thread (e.g. +211927797217) showed ONLY outgoing green bubbles — no customer messages on the left. Looked like a UI bug; it is not.
- **Root cause**: rendering is correct (`direction='in'`→left/white, `'out'`→right/green, bubbles cap 65%). The DB simply had no inbound rows for that number. Incoming messages are only logged when the Evolution instance's **webhook points at our app** and fires `MESSAGES_UPSERT` (real-time path = `WebhookController`→`ProcessIncomingMessage`→`MessageLog::record(..., 'in','customer',...)`). That instance's webhook wasn't pointed at us, so every inbound message was dropped on arrival. A one-sided thread looks "wrong" but is just missing data — it becomes a normal two-sided window once inbound is captured.
- **Fix**: new **🔗 Re-link** button in the Chats header (next to ⟳ Sync). Calls `POST /papi/chats/relink-webhook` → `PanelApiController::chatRelinkWebhook()` → `EvolutionAdmin::setWebhook()` (registers MESSAGES_UPSERT/UPDATE/CONNECTION/QR), then reads `getWebhook()` back and reports `linked`/`enabled`/`current`/`expected`. On success the panel auto-runs Sync to backfill anything already in Evolution's store. Going forward, new inbound logs in real time.
- **Operator note**: after connecting/reconnecting a WhatsApp number, click **Re-link** once. To diagnose, `/papi/chats/sync-debug` shows `webhook_url` vs `webhook_expected`.
- Added/changed: PanelApiController.php (chatRelinkWebhook), routes/web.php (chats/relink-webhook), resources/panel/chats.html (Re-link button + relinkWebhook()).

### 2026-06-13 (later) — Phase 14: Owner alerts + admin Payments + bot Free-cap (Bhavin + AI)
- **New-order owner alert**: `OrderObserver::created()` now fires `NotifyOwnerNewOrder` for every new order EXCEPT POS (owner made those at the counter). Job WhatsApps a summary (order no, customer, location, items, total, panel link) to the shop's alert number(s).
- **Owner alert number**: `Tenant::ownerAlertNumbers()` reads `settings.owner_alert_phone` (comma-separated allowed). Set per shop in /admin -> Business -> Settings. If empty, alerts/receipts silently skip.
- **Generic `NotifyOwner` job** (tenantId, text, ?to) — reused for alerts and receipts; logs as 'system' message.
- **Payment receipt**: `BillingController::sendReceipt()` WhatsApps "Payment received — ... active until <date>" after every successful payment (MoMo markPaid + Stripe webhook). Sent to the payer's MoMo number, else owner number(s).
- **Bot Free-plan cap (now enforced softly)**: at bot `checkout` (and a safety net in `placeOrder`), if effectivePlan=free AND overOrderCap(30), the bot does NOT auto-place — it replies "someone from the shop will confirm shortly" and nudges the owner once/day (Cache::add dedupe) to upgrade. Customer experience stays graceful; pressure lands on the shop. Paid plans unaffected (unlimited).
- **Admin Payments list**: read-only `PaymentResource` at /admin (When, Business, provider [Mobile Money/Card], plan, amount in its currency, network, phone, status, ref) with status/provider filters. No create/edit.
- Added/changed: app/Jobs/NotifyOwner.php, app/Jobs/NotifyOwnerNewOrder.php, Tenant.php, OrderObserver.php, BotBrain.php, BillingController.php, TenantResource.php, PaymentResource.php + Pages/ListPayments.php.

### 2026-06-13 (later) — Phase 13: Online payments — MoMo (MTN/Airtel) + Card (Stripe) (Bhavin + AI)
- Shops can now pay/renew in-app. New `/panel/billing` page: pick Starter/Pro, choose Mobile Money (MTN or Airtel) or Card, pay. Success auto-extends the plan via the Phase 12 `Tenant::applyPaidPlan()` (sets plan, +1 month paid_until, clears trial). Upgrade banner now links here.
- Providers are env-gated (hidden until keys set):
  - Flutterwave (UGX MoMo): `App\Services\Billing\Flutterwave` — chargeMobileMoney (type=mobile_money_uganda), verifyByReference/ById, webhook 'verif-hash' check. Customer approves PIN on phone -> webhook -> plan extended. Status endpoint also actively verifies so polling confirms even if webhook is slow.
  - Stripe (USD card): `App\Services\Billing\StripeGateway` — hosted Checkout in **subscription** mode with inline price_data (true monthly auto-renew, no pre-created Price). Webhook handles checkout.session.completed + invoice.paid (renewals); Stripe-Signature HMAC verified.
- `payments` table (migration 000014) + `Payment` model record every attempt (provider, plan, amount, currency, tx_ref unique, status pending/successful/failed). Prices: Starter UGX 75,000/$20, Pro UGX 185,000/$50 (in config/plans.php).
- Routes: papi billing/quote, billing/pay-momo, billing/pay-card, billing/status (authed); api /billing/flutterwave/webhook, /billing/stripe/webhook (public, signature-verified).
- ENV to enable: FLW_SECRET_KEY, FLW_PUBLIC_KEY, FLW_WEBHOOK_HASH; STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, STRIPE_CURRENCY(=usd). Webhook URLs to register: https://app/api/billing/flutterwave/webhook and .../stripe/webhook.
- Reality note: MoMo has no card-on-file auto-debit — each monthly MoMo renewal is a fresh PIN approval (one tap from the shop). Stripe cards DO auto-renew monthly. Manual "Mark paid" in /admin still works for cash/offline payments.
- Added/changed: config/billing.php, config/plans.php, migration 000014, Payment.php, Tenant.php, Flutterwave.php, StripeGateway.php, BillingController.php, billing.html, SellerPanelController.php, web.php, api.php.

### 2026-06-13 (later) — Phase 12: Plans & billing (Free / Starter / Pro) (Bhavin + AI)
- Adds the subscription layer the freemium model needs. `config/plans.php` defines Free (30 orders, bot+orders), Starter ($20, unlimited, bot+confirmations), Pro ($50, everything: POS, dispatch, tracking, reports, returns, branding, multi-user).
- Migration `...000013_add_plan_billing_to_tenants` adds `trial_ends_at`, `paid_until`, `billing_note`. **Grandfathers existing tenants to Pro+10yr** so the live Family Shoppers panel is not locked. New tenants auto-start a 30-day trial via Tenant::booted() creating hook.
- `Tenant` gains plan logic: `onTrial/trialDaysLeft`, `effectivePlan()` (trial -> pro; paid plan active unless `paid_until` lapsed -> free), `can($feature)`, `orderCap/ordersThisMonth/overOrderCap`, `planLabel()`.
- Enforcement: `PanelApiController::planDeny()` returns `{ok:false,error:'upgrade_required',feature}` (403). Gated: new POS order (saveOrder w/o row) -> 'pos'; dispatch/riderSave/riderDel -> 'dispatch'; branchSave/branchDel -> 'pos'; returnSave -> 'returns'.
- Panel UI: `SellerPanelController::injectPlan()` injects `window.PLAN` + a script that HIDES locked nav items (POS/Dispatch/Riders/Reports/Returns) and shows a sticky upgrade/trial banner. Panel HTML untouched. Upgrade link uses placeholder wa.me 256700000000 (operator's sales number — REPLACE).
- Operator workflow (`/admin` -> Businesses): plan/trial/paid_until/billing_note fields + row actions **"Mark paid 1 month"** (sets plan, extends paid_until +1mo, clears trial, logs note) and **"Start 30-day trial"**. This is how you handle Mobile Money payments manually — no payment gateway yet.
- Changed/added: config/plans.php, migration 000013, Tenant.php, PanelApiController.php, SellerPanelController.php, TenantResource.php, InitialSeeder.php.

### 2026-06-13 (later) — Phase 11: capture incoming (non-text) messages + back link (Bhavin + AI)
- Bug: Chats threads showed only outgoing (green) bubbles; customer messages were missing. Root cause: `chatSync` extracted text only from `conversation`/`extendedTextMessage.text` and SKIPPED everything else, so customer replies sent as button/list taps, photos, voice notes, etc. were dropped on import.
- Fix: new `waMessageText()` extractor unwraps ephemeral/view-once wrappers and reads captions, button/list/template replies, and reactions; media with no caption becomes a labelled placeholder (📷 Photo, 🎤 Voice message, 📄 Document, 📍 Location, etc.) so the inbound bubble still appears on the left. Re-run Sync to backfill.
- UX: restored the "‹ Panel" back link (dropped in the Phase 9 redesign) in the green Chats list header, so you can return to the seller panel.
- Note for two-way live chat: customer messages only log in real time if the instance webhook points at our app (`/api/webhook/whatsapp/evolution`). Check `webhook_url` in `/papi/chats/sync-debug`; if it's not ours, re-point via Setup -> Connect.
- Changed: `PanelApiController.php`, `chats.html`.

### 2026-06-13 (later) — Phase 10: POS place-order fix (Bhavin + AI)
- Bug: POS "Place order" always failed with "Could not place order". Root cause: `PanelApiController::saveOrder` only handled the EDIT path -- it did `Order::find($row)` and returned `not_found` when no `row` was supplied. POS sends a NEW order with no `row`, so it 404'd every time.
- Fix: `saveOrder` now CREATES a new Order when `row` is empty (POS / new sale) and UPDATES when `row` is present. On create it fills customer_name, customer_phone, items_json/items_text, total, payment, location, channel ('pos'), status ('New'), and branch_id (when numeric). OrderObserver still assigns the FS-#### order_no + track_token on create. Returns `{ok,created,id,order_no}`.
- Changed: `PanelApiController.php` only.

### 2026-06-13 (later) — Phase 9: WhatsApp-style Chats UI + reply-to (Bhavin + AI)
- Full visual rebuild of `resources/panel/chats.html` to look like WhatsApp: green header, WhatsApp chat background + dotted texture, tailed bubbles (incoming white / outgoing #d9fdd3) with in-bubble time + ✓✓ ticks, WhatsApp-style list rows (avatar, name, preview, time, green unread badge), rounded pill composer with send button. On phone it renders like the WhatsApp app (green chat header, full-screen list <-> thread with a back arrow).
- **Reply-to-message**: hover/tap a bubble -> "↩ reply" -> a quote bar appears above the composer; sending includes the quoted message so it shows as a native WhatsApp reply on the customer's phone.
- Backend for quoting: `WhatsAppGateway::sendText` gained optional `?array $quoted`; `EvolutionGateway` passes `quoted` to `/message/sendText`; `chatThread` now returns each message's `wa_id`; `chatSend` accepts `quoted_id` and builds `['key'=>['id'=>...]]`. `CloudApiGateway` signature updated for interface parity (ignores quoted for now).
- Replying still auto-takes-over the chat (bot pauses there). Bot on/off toggle + Sync moved into the green list header.
- Changed: `WhatsAppGateway.php`, `EvolutionGateway.php`, `CloudApiGateway.php`, `PanelApiController.php`, `chats.html`. Brandize still swaps "Family Shopper" -> tenant name at serve time.

### 2026-06-13 (later) — Phase 8: per-tenant branding (Bhavin + AI)
- The panel now shows **each business's own name + initials**, not hardcoded "Family Shopper / FS". `SellerPanelController::brandize()` swaps the brand name, the `FS` logo badge, and the iOS app title at serve time using `tenant->name` (initials derived from the name, e.g. "Pals Snacks" -> "PS"). Applied to panel, chats, and setup pages.
- **PWA manifest is now per-tenant**: `PwaController::manifest()` returns the tenant's name as the installed-app name/short_name (reads the session; manifest `<link>` got `crossorigin="use-credentials"` so the browser sends the cookie). So when Pals Snacks installs the app, their home-screen app says "Pals Snacks".
- Backward compatible: the Family Shoppers tenant still shows "Family Shopper / FS" (its name is unchanged). A business's brand follows `tenant->name`, which the Settings page already updates.
- Changed: `SellerPanelController.php` (brandize + initials), `PwaController.php` (dynamic manifest + initials), `seller.html`/`chats.html`/`setup.html` (manifest crossorigin).
- Still generic per tenant: the app **icon** PNG (green badge) — dynamic per-tenant icon is a future nice-to-have; the app *name* differentiates for now.

### 2026-06-13 (later) — Phase 3b (part 2): Settings, Returns, Customers, Branches + tracking page (Bhavin + AI)
- **All remaining panel saves now persist.** Settings (`settings-save` -> tenant.settings + name/phone), currency & discount (`bot-config-save` -> tenant.settings, feeds bot/quotes), Branches (`branch-save`/`branch-delete` -> Branch model), Customers (`customer-save` -> CustomerProfile; also updates the name on that phone's orders), Returns/refunds (`return` -> ReturnRecord; store credit computed as credit issued minus redeemed and returned in `returns.credit`).
- Reads upgraded: `settings` now returns fee/currency fields too; `branches` real list; `customers` returns the `{customers:{phone:{...}}}` map the panel reads (note: panel uses `d.customers`, not `d.profiles`).
- New customer **order-tracking page**: public `GET /papi/track?o=&t=` (TrackController, bypasses tenant scope, matches id+token) — themed status timeline + items + total. This is the link `dispatch` puts in the WhatsApp flow.
- New: migration `...000012_create_returns_and_customer_profiles.php`, `ReturnRecord`, `CustomerProfile`, `TrackController`. Changed: `PanelApiController` (settingsSave/botConfigSave/branchSave/branchDel/customerSave/returnSave + creditMap/branchesList + richer reads), `routes/web.php`.
- Phase 3b COMPLETE — every page in the seller panel is now backed by real, tenant-scoped endpoints.

### 2026-06-13 (later) — Phase 3b (part 1): Dispatch + Riders wired (Bhavin + AI)
- **Dispatch** now persists: `/papi/dispatch` (GET `row,rider,riderphone,phone,name`) finds-or-creates the rider, sets `order.rider_id`, ensures a `track_token`, sets status **Out for delivery** (fires the WhatsApp "on the way" notification via OrderObserver) and returns `{ok,track}` with a `?t=` token the panel reads.
- **Riders** full CRUD: `/papi/rider-save` (name,phone,active,city,dob,address + identity/payment fields) and `/papi/rider-delete` (id) — both return the refreshed `{riders:[...]}` the panel expects. `riders` read now flattens the new fields.
- Added JSON `profile` column to `riders` (license_no, nid_no, doc_url, bank_name, account_name, bank_account, pay_notes, pay_type, comm_pct/min/max). Migration auto-runs.
- New: migration `...000011_extend_riders_profile.php`. Changed: `Rider.php` (+profile), `PanelApiController.php` (dispatch/riderSave/riderDel + ridersList helper), `routes/web.php`.
- Still pending (still return ok:false): Returns (`return`), Settings save, bot-config-save, Branches, Customers. Plus the customer `/papi/track` page (token is generated; page not built yet).

### 2026-06-13 (later) — Phase 7: WhatsApp chat history sync (Bhavin + AI)
- New **⤓ Sync past chats** button in the Chats inbox header. Pulls existing messages out of Evolution's store into our `messages` transcript so past conversations show up in the inbox (not just messages from connect-time onward).
- `EvolutionAdmin::findMessages($instance,$page,$offset)` — pages `POST /chat/findMessages/{instance}` (Evolution's remoteJid filter is buggy, so we fetch all and bucket by chat ourselves; records read from `messages.records`|`records`|list).
- `PanelApiController::chatSync` (`POST /papi/chats/sync`): maps Baileys records -> messages (in/out by `key.fromMe`, body from `conversation`/`extendedTextMessage.text`, original `messageTimestamp` preserved as created_at), de-dupes on `wa_message_id` (re-runnable), bulk-inserts, updates conversation `last_message_at`. Cap ~5000/run (re-run for more). Skips groups/broadcast/media-only.
- Note: only what Evolution has stored is available (recent window WhatsApp synced to the device), not the full lifetime archive. Historical outbound is labelled `bot` generically.
- Changed: `EvolutionAdmin.php`, `PanelApiController.php`, `routes/web.php`, `chats.html`.

### 2026-06-13 (later) — Phase 6: installable mobile app (PWA) + app-style UI (Bhavin + AI)
- The panel is now an **installable PWA** — owners "Add to Home Screen" and it opens full-screen with its own icon, no browser bars. No native app needed (keeps one codebase). Works on the phones most Kampala owners use; the panel was already responsive (off-canvas drawer at <=820px), so all operations run from mobile.
- New `App\\Http\\Controllers\\Panel\\PwaController` + public routes: `/manifest.webmanifest`, `/sw.js` (network-first shell cache; never caches `/papi`|`/api` or writes; `Service-Worker-Allowed: /`), `/icons/{name}`, `/apple-touch-icon.png`. Icons generated at `resources/panel/icons/` (192/512 maskable + 180 apple-touch).
- `seller.html`: PWA head meta + a **mobile bottom tab bar** (Home / Orders / Chats / POS / More) shown only <=820px, with safe-area inset. `chats.html`: PWA meta + a mobile **back-to-list** button in the thread header. `setup.html`: PWA meta. All three register the service worker.
- New: `PwaController.php`, `resources/panel/icons/*.png`. Changed: `routes/web.php`, `seller.html`, `chats.html`, `setup.html`.
- Future: per-tenant app name/icon in the manifest (currently static "Family Shopper / Seller"); push notifications for new orders.

### 2026-06-13 (later) — Phase 5: self-serve onboarding (WhatsApp QR connect + AI bot setup) (Bhavin + AI)
- New **Setup** page at `/panel/setup` (sidebar link). Two cards: (1) **Connect WhatsApp** by scanning a QR like WhatsApp Web — no Evolution dashboard; (2) **Set up your assistant** — owner describes the shop in plain words, OpenAI writes the bot's welcome message, owner edits + saves.
- New `App\\Services\\WhatsApp\\EvolutionAdmin`: create instance, fetch QR, poll connectionState, set webhook, disconnect — all from our portal using the global Evolution API key. Defensive about v2.x payload drift (reads QR/state from multiple paths).
- New endpoints (/papi): `wa/status`, `wa/connect` (creates instance `shopbot_t{id}` if the tenant has none, sets webhook to our `/api/webhook/whatsapp/evolution`, returns QR), `wa/qr` (refresh + poll), `wa/disconnect`; `bot/generate` (OpenAI -> {greeting,profile}; template fallback if no API key), `bot/save` (-> `tenant.settings['bot_greeting'|'business_profile']`).
- `BotBrain` greet now uses `tenant.setting('bot_greeting')` when set, so the generated welcome is what customers actually get.
- Needs env on the app: `EVOLUTION_BASE_URL`, `EVOLUTION_API_KEY`, `OPENAI_API_KEY`. ⚠️ `wa/connect` uses the tenant's existing instance if set (Family Shoppers = `savan`, the LIVE n8n number) — test with a NEW tenant/number, don't relink savan until cutover.
- New: `EvolutionAdmin.php`, `resources/panel/setup.html`. Changed: `PanelApiController`, `SellerPanelController`, `BotBrain`, `seller.html` (+Setup nav), `routes/web.php`.

### 2026-06-13 (later) — Phase 4b: live web Chats inbox + human takeover (Bhavin + AI)
- New **Chats** screen at `/panel/chats` (linked from the panel sidebar, after Orders). WhatsApp-style 2-pane inbox matching the panel theme: conversation list (name/phone, snippet, time, unread badge, "you" tag) + live thread (customer/bot/agent/system bubbles) + composer.
- Near-live via polling: list every 15s, open thread every 4s (incremental via `?after=id`). No websockets needed on this stack.
- **Human takeover**: per-chat "Take over / Hand back to bot" (sets `conversation.agent_active`); sending a manual reply auto-takes-over so the bot goes quiet. **Global bot switch** in the header (sets `tenant.settings['bot_mode']` auto|off).
- New: `resources/panel/chats.html`. Changed: `resources/panel/seller.html` (+Chats nav link), `PanelApiController` (chats/thread/send/takeover/bot-mode endpoints), `SellerPanelController` (serve chats page), `routes/web.php`.
- Endpoints (tenant-scoped, under /papi): `GET chats`, `GET chats/thread`, `POST chats/send`, `POST chats/takeover`, `POST chats/bot-mode`. `chats/send` calls Evolution `sendText` + logs via MessageLog.
- Needs Phase 4a deployed (messages table). Chats only populate once WhatsApp traffic flows through the NEW app (connect a test Evolution instance + set `EVOLUTION_BASE_URL`/`EVOLUTION_API_KEY`).

### 2026-06-13 (later) — Phase 4a: message logging + bot on/off + takeover hook (Bhavin + AI)
- New `messages` table (full WhatsApp transcript, in/out, sender = customer|bot|agent|system). New `App\\Models\\Message`, new `App\\Support\\MessageLog::record()` — the single write path every inbound/outbound message goes through, so the transcript is complete and the inbox list stays in sync.
- `conversations` gained `agent_active` (human took over -> bot stays quiet), `unread` (badge), `last_inbound_at`.
- `ProcessIncomingMessage`: now logs every inbound message (even when bot is off), honours `tenant->setting('bot_mode')` (`auto` replies; anything else = monitor-only) and `conversation->agent_active`, and logs the bot's outbound reply.
- `SendOrderStatusNotification`: order-status WhatsApp messages now logged as `system` so they show in the thread.
- Migration auto-runs on deploy (additive/safe). Foundation for Phase 4b (web Chats inbox + human takeover UI + bot on/off toggle).

### 2026-06-13 (later) — Phase 3a: customer's real seller-panel UI (Bhavin + AI)
- Replaced the "too basic" Filament business UI with the customer's existing **Family Shopper — Seller Panel** served verbatim at **`/panel`** (12 pages, unchanged HTML/CSS/JS).
- Only its backend config was repointed: `BASE`/`EP` now hit new tenant-scoped Laravel endpoints under **`/papi/*`** returning the same JSON shapes the old n8n webhooks did. Session token injected so it boots straight into the dashboard (no separate OTP).
- New: `app/Http/Controllers/Panel/SellerPanelController.php` (serves HTML behind `/app` session), `app/Http/Controllers/Panel/PanelApiController.php` (all endpoints), `resources/panel/seller.html` (patched UI). Changed: `routes/web.php` (+`/panel` +`/papi` group), `bootstrap/app.php` (csrf except `papi/*`, guests -> `/app/login`).
- Live & persisting: orders + products + riders reads, update-status (fires WA notify), save-order, add/update product, image upload, bot-config read.
- Phase 3b pending (return `ok:false` -> panel shows "saved on this device only"): dispatch, rider save/delete, returns, settings-save, bot-config-save, branches, customers-save. Plus `/papi/track` page.
- Login: `/app/login` as staff, then open `/panel`.

### 2026-06-13 — Initial build & launch (Bhavin + AI)
- Stood up ShopBot on EasyPanel: Dockerfile-assembled Laravel 11 + Filament v3, Postgres `saaspg`, Redis `redissaas`, queue + scheduler. App live at `evo-shopping.1gk84r.easypanel.host`.
- Multi-tenancy (BelongsToTenant + TenantContext + SetTenantFromUser). Operator panel `/admin`, business panel `/app`.
- Resources: Product (CRUD + CSV import + image url/upload), Order (status → WhatsApp notify), Rider, Category, Settings, admin Tenant.
- Bot: webhook → ProcessIncomingMessage → BotBrain (OpenAI NLU via BotNlu + keyword fallback) → cart/checkout → Order. Status notifications via OrderObserver + SendOrderStatusNotification.
- Dashboard widgets (stat cards + orders chart), POS screen.
- CSV importer reads Uganda POS pricelist format (bulk insert, replace/merge). Loaded 15,229 Family Shoppers products.
- Fixes: Composer `policy.advisories.block false`; Filament closures by-name (`$state`/`$record`); `authMiddleware isPersistent: true` (null-tenant bug); HTTPS `forceScheme` + `trustProxies` behind EasyPanel proxy; dropped `route:cache`; persistent volume for `storage/app/public`.
- Pending: bot category browsing, customer order-tracking page, bot on/off per tenant before repointing `savan`, Cloud API driver, billing/plans.

### YYYY-MM-DD — <title> (<who>)
- <what changed>

