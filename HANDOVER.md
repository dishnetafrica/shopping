# ShopBot — Handover & Continuation Guide

_Last updated: 13 Jun 2026. Keep this file in the repo root and update it as the system evolves._

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

