# CloudBSS / ShopBot — How-To Guide (deploy + run end to end)

_Last updated: 14 Jun 2026. This is the practical "do this, then this" guide. For architecture and history see `HANDOVER.md`._

CloudBSS is a multi-tenant WhatsApp ordering platform (Laravel 11 + Filament v3 + Postgres + Redis), deployed via GitHub → EasyPanel/Docker. One install serves many shops ("tenants").

---

## 0. The 60-second mental model

- **Public site** lives at `/` — the CloudBSS marketing page. Shop owners log in from there.
- **Shop owners** use the seller panel at `/panel` (orders, chats, POS, riders, setup, billing).
- **You (operator)** use the admin at `/admin` (create shops, set plans, mark payments, see all payments).
- **Customers** never log in — they just message the shop's WhatsApp number; the bot does the rest.
- Each shop connects WhatsApp one of two ways: **QR (Evolution)** — instant, free, ban-risk at scale; or **Official Cloud API** — reliable, needs a Meta account (Pro plan).

URLs (replace host with yours):
- Public site: `https://app/`
- Shop login: `https://app/app/login`  · Shop panel: `https://app/panel`
- Operator login: `https://app/admin/login` · Admin: `https://app/admin`

---

## 1. Deploy (GitHub → EasyPanel)

You do **not** run composer/docker/php locally. The flow is: put files in GitHub → click Deploy in EasyPanel.

1. Push the contents of `shopbot-saas.zip` to your repo (`dishnetafrica/shopping`). Keep `cloudbss-site/` if it exists separately — this app does not contain it; the marketing page now lives inside the app at `resources/marketing/index.html`.
2. In EasyPanel, the service builds from the `Dockerfile`. Make sure these services exist and are linked: **app**, **Postgres**, **Redis**.
3. Set environment variables (next section), then click **Deploy**.
4. First deploy runs migrations automatically (see `docker/` entrypoint). If you ever need to run them by hand, use the EasyPanel console: `php artisan migrate --force`.
5. Visit `https://app/` — you should see the CloudBSS marketing page.

If a page 500s, open EasyPanel **Logs** and read the last error; that's the fastest path. With `APP_DEBUG=true` (staging only) the error shows in the browser.

---

## 2. Environment variables (.env)

Core (always):
```
APP_NAME=CloudBSS
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate once, then keep it
APP_URL=https://your-app-host
APP_DEBUG=false               # MUST be false in production

DB_CONNECTION=pgsql
DB_HOST=...  DB_PORT=5432  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...

REDIS_HOST=...  REDIS_PORT=6379
QUEUE_CONNECTION=redis        # jobs (bot replies, owner alerts) run on the queue
```

WhatsApp — Evolution (QR connection, the default on-ramp):
```
WHATSAPP_DRIVER=evolution
EVOLUTION_BASE_URL=https://your-evolution-server
EVOLUTION_API_KEY=...
```

WhatsApp — Official Cloud API (only needed if shops will use BYO official API):
```
WHATSAPP_CLOUD_VERIFY_TOKEN=cloudbss-verify   # the token shops paste into Meta's webhook
# (per-tenant tokens are entered in the panel, not here)
```

AI bot (sales/marketing auto-reply uses OpenAI):
```
OPENAI_API_KEY=sk-...
```

Marketing page contact points (optional — defaults to placeholder 256700000000):
```
MARKETING_WA_NUMBER=2567XXXXXXXX    # digits only, no "+"
MARKETING_PHONE=+256 7XX XXXXXX
MARKETING_EMAIL=hello@mycloudbss.com
```

Payments (only when you turn on in-app paying — see §7):
```
FLW_SECRET_KEY=...  FLW_PUBLIC_KEY=...  FLW_WEBHOOK_HASH=...     # Flutterwave (MoMo)
STRIPE_SECRET_KEY=...  STRIPE_WEBHOOK_SECRET=...  STRIPE_CURRENCY=usd   # Stripe (card)
```

---

## 3. First-run: create yourself + the first shop

1. Create your operator (admin) user. Easiest: EasyPanel console →
   `php artisan tinker` →
   ```php
   \App\Models\User::create(['name'=>'Operator','email'=>'you@dishnet.com','password'=>bcrypt('CHANGE_ME')]);
   ```
   (Or use the seeder if one is configured.)
2. Log in at `/admin/login`. Change the password immediately.
3. **Create a shop**: Admin → Businesses → New. Set name, slug, plan, and a staff login (email + password) for the shop owner.
4. Give the shop owner their `/app/login` details. They land on `/panel`.

---

## 4. Connect a shop's WhatsApp — Option A: QR (Evolution)

Fast, free, no Meta account. Best for Free/Starter and quick trials.

1. Shop owner opens **/panel → Setup**.
2. **Step 1 → Connect WhatsApp** → a QR appears.
3. On the shop's phone: WhatsApp → **Linked devices** → **Link a device** → scan. Status flips to **Connected**.
4. The app auto-points the Evolution webhook at itself so incoming messages are captured.
5. If customer messages ever stop showing in **Chats**, open Chats → **🔗 Re-link** (re-points the webhook) → it auto-syncs. (Diagnose at `/papi/chats/sync-debug`.)

Caveat: Evolution is an unofficial connection. High-volume or broadcast-style sending can get a number banned by Meta. For serious shops, use Option B.

---

## 5. Connect a shop's WhatsApp — Option B: Official Cloud API (Pro, BYO)

Reliable and ban-safe. The shop brings its own Meta WhatsApp Cloud API number. Requires the **Pro** plan.

**What the shop needs from Meta first** (one-time, on business.facebook.com / Meta for Developers):
1. A verified **Meta Business** account.
2. A **WhatsApp Business App** with a phone number added (a number **not** currently active in the WhatsApp app). Note its **Phone number ID** and **WhatsApp Business Account (WABA) ID**.
3. A **permanent access token** (create a System User in Business Settings, assign the WhatsApp app, generate a token that doesn't expire).

**In the panel** (/panel → Setup → "Use the official WhatsApp API"):
1. Enter **Phone number ID**, **Permanent access token** (and optionally WABA ID + display number). Click **Connect official API**.
   - This switches the shop's driver to `cloud` and stores the token against that shop only.
2. The card now shows a **Callback URL** and a **Verify token**. In Meta → your WhatsApp app → **Configuration → Webhook**:
   - Callback URL = the shown URL (`https://app/api/webhook/whatsapp/cloud`)
   - Verify token = the shown token (this is `WHATSAPP_CLOUD_VERIFY_TOKEN`)
   - Click verify — Meta calls the app and the app echoes the challenge. Then **subscribe to the `messages` field**.
3. Send a test message to the number → it appears in **Chats**, and the bot replies. Done.

To revert: Setup → **Switch back to QR (Evolution)** (the cloud token is kept in case you switch again).

Notes:
- On the cloud driver, **Meta charges per conversation** — that bill goes to the shop's own Meta account (BYO). Your flat plan price still covers the software.
- A number already used in the WhatsApp **app** must be migrated to Cloud API on Meta's side first; that move isn't casually reversible.

---

## 6. Set up the shop's assistant (bot)

1. /panel → Setup → **Step 2: Set up your assistant**.
2. Fill business name, what they sell, city, delivery note, tone → **Generate welcome message** (uses OpenAI; if no key, it drafts a sensible template).
3. Edit the greeting/profile if needed → **Save assistant**.
4. Add products under /panel → Products (or bulk import). The bot prices orders from these.
5. Toggle the bot on/off per chat from **Chats** (the Bot switch), and **Take over** any chat to reply by hand.

---

## 7. Plans, billing & payments

- Plans (config/plans.php): **Free** (30 orders/mo, bot + orders), **Starter** ($20, unlimited, bot + confirmations), **Pro** ($50, everything: POS, riders, tracking, reports, branding, multi-user, **official Cloud API**).
- New shops get a **30-day full-feature trial**; existing shops were grandfathered to Pro.
- **Manual payments** (cash / MoMo you collected yourself): Admin → Businesses → row action **"Mark paid 1 month"** (extends `paid_until`, clears trial).
- **In-app paying** (optional): set the Flutterwave + Stripe keys (§2), then shops can pay/renew at /panel → Billing (MoMo via Flutterwave, card via Stripe). Register these webhooks in each provider:
  - Flutterwave: `https://app/api/billing/flutterwave/webhook`
  - Stripe: `https://app/api/billing/stripe/webhook`
- See all payments at Admin → Payments (read-only).

---

## 8. Day-to-day for a shop owner (what they actually do)

1. Customer messages the shop's WhatsApp. Bot greets, takes the order, prices it, confirms.
2. New order pings the owner (owner alert number set in /panel → Business → Settings) and lands in **Orders**.
3. Owner packs → marks **Packed** → **Send rider** (Pro) → customer gets a live tracking link.
4. Owner can jump into any **Chat** and take over from the bot at any time.
5. Counter sales go through **POS** (Pro). Returns, customers, branches all under the panel.

---

## 8a. Cashbook & order payments (how the customer knows you got the money)

The **Cashbook** (/panel → 💰 Cashbook) is the shop's money record: money in (order payments + other income) and money out (expenses, supplier payments, owner draws), with a running **cash-on-hand** balance and Today / 7-day / 30-day / All totals.

**Registering a payment against an order** (this is what triggers the customer's confirmation):
1. /panel → **Cashbook** → "Record a payment for an order".
2. Pick the order from the **still-owing** list (it shows who owes how much), the amount prefills to the balance — adjust for part payments.
3. Choose method (cash / MoMo / card / bank), add a note, keep **"WhatsApp the customer a receipt"** ticked.
4. **Record payment.** The shop's balance updates, the order's paid amount + balance update, and the customer gets a WhatsApp:
   - Paid in full → *"✅ Payment received: UGX X for order #FS-1042. Paid in full — thank you!"*
   - Part payment → *"✅ Payment received: UGX X for order #FS-1042. Balance left: UGX Y."*

Orders track `amount_paid` and a state of **unpaid / partial / paid**, so partial payments and pay-on-delivery both work — record each instalment as it comes in.

**Money in/out as needed** ("pay as per requirement"): use "Add money in / out" for expenses, restock/supplier payments, owner draws, or other income. These move the cash-on-hand balance but aren't tied to an order.

It's single-currency UGX and tenant-isolated (each shop sees only its own book). Entries are kept separately from subscription `payments` (what shops pay *you*) — this cashbook is the shop's own till.

## 8b. Staff logins (seat-capped by plan)

Each shop manages its own team under **/panel → 👤 Staff** — self-serve, no operator action needed.
- The page shows seat usage (e.g. "1 of 2 used") and an add form (name, email, 6+ char password, optional role). New staff sign in at **/app/login**.
- Caps come from `config/plans.php` → `user_cap`: **Free 1, Starter 2, Pro unlimited** (trial = unlimited). Change the numbers there if you want different limits.
- At the limit, the add form is replaced with an upgrade prompt; `staffAdd` also enforces server-side (returns `upgrade_required`). A shop can't remove its own login or the last remaining one.
- You (operator) can still create users directly in tinker if ever needed; the per-shop screen is the normal path.

## 8c. CloudBSS's own marketing/sales bot (OpenAI)

You can run an AI sales assistant on CloudBSS's *own* WhatsApp line — it answers questions about CloudBSS, quotes pricing, pushes the free trial, and hands hot leads to you. It reuses the whole tenant machinery; it just thinks like a salesperson instead of a shop.

Set it up once:
1. **Create a tenant** for yourself in `/admin` → Businesses → New (e.g. name "CloudBSS").
2. **Connect its WhatsApp** number under that tenant's `/panel → Setup` (QR or official Cloud API) — use a number separate from any real shop.
3. **Mark it as the marketing line.** In the EasyPanel console:
   ```php
   php artisan tinker
   $t = \App\Models\Tenant::where('name','CloudBSS')->first();
   $t->settings = array_merge($t->settings ?? [], ['bot_kind' => 'marketing']);
   $t->save();
   ```
4. Make sure `OPENAI_API_KEY` is set (and optionally `OPENAI_MODEL`, default `gpt-4o-mini`).
5. Point the website at this number: set `MARKETING_WA_NUMBER` to it, so every "Start free trial" / "Talk to sales" CTA opens a chat with the bot.

Now any message to that number gets an AI sales reply (`MarketingBrain`), with a graceful canned fallback if the AI is unavailable. You can **Take over** any sales chat from `/panel → Chats`, exactly like a shop does. (Normal shop tenants are unaffected — they keep the ordering bot.)

## 8d. The scheduler (required for scheduled deliveries & timed campaigns)

Scheduled-delivery reminders and timed campaigns are driven by `shopbot:process-scheduled`, run every minute by `routes/console.php`. The EasyPanel image already runs a **scheduler** process; if you ever run it yourself, the cron is:
```
* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1
```
A **queue worker** must also be running (it sends the WhatsApp messages): `php artisan queue:work`. Without the worker, reminders and campaigns are computed but never delivered. Test once with `php artisan shopbot:process-scheduled`.

## 8e. Scheduled deliveries

`/panel → 🗓️ Scheduled` lets the shop pick an un-delivered order and set a delivery time (today-later / tomorrow / custom). Each scheduled order moves through **Scheduled → Preparing → Ready For Dispatch → Out For Delivery** automatically as its time approaches, and the owner gets a WhatsApp at:
- **2h before** — "needs preparation"
- **30m before** — "assign a rider"
- **at the time** — "dispatch now"

The queue groups orders by day with a live countdown. (v1 schedules from the panel; conversational "schedule for tomorrow" capture inside the bot is the next step. Stored in `orders.scheduled_for / sched_stage / sched_reminders`.)

## 8f. Marketing campaigns ⚠️ (mind the ban risk)

`/panel → 📣 Marketing` is the campaign builder: pick products, write or **AI-draft** a message (weekend / new arrivals / overstock / slow movers), add an image URL, choose an audience (all / recent / inactive / VIP / by category), then send now or schedule. Messages = text + product lines + a CTA ("Reply BUY to order instantly.").

**Ban risk is real.** Mass-broadcasting on the unofficial (Evolution) connection is the fastest way to get a number blocked. Mitigations built in: `SendCampaign` sends **one message at a time with a 4–9s jitter**, and the page shows a prominent warning. For real campaign volume, connect the **official WhatsApp Business API** first (Setup → official WhatsApp). Audience resolution lives in `AudienceResolver`; "by category" is a best-effort text match on order items (v2 = exact line-item join). Image upload is by URL for now (direct upload is a follow-up).

## 8g. Measuring bot response time

Every auto-reply logs a timing line so you can see real latency instead of guessing. In the app logs (EasyPanel → logs, or `tail -f storage/logs/laravel.log`) look for `bot.latency`:
```
bot.latency {"tenant":1,"from":"2567...","mode":"shop","queue_ms":40,"brain_ms":1850,"send_ms":260,"total_ms":2150}
```
- `total_ms` — end-to-end, webhook received → reply sent (this is what the customer feels).
- `queue_ms` — time the job waited for a worker. **If this is large, run more queue workers** (`php artisan queue:work`, several copies for busy hours).
- `brain_ms` — the OpenAI call (the usual dominant cost; keep `OPENAI_MODEL=gpt-4o-mini` for speed, or it falls back to instant keyword parsing if the AI is off/slow).
- `send_ms` — the WhatsApp send.

A paused loop logs `bot.loop_paused` with the trip counts. Compared with the old n8n + Google Sheets flow (~1 min), expect a few seconds, dominated by `brain_ms` — provided a queue worker is running.

## 8h. Diagnostics — "where did the message get stuck?"

Like watching an n8n execution, `/panel → 🩺 Diagnostics` shows every inbound message and what the bot did with it, newest-first, auto-refreshing. Each message has a short trace id; you'll see its steps:
- **queued** — webhook received it and handed it to the worker.
- **started** — the worker picked it up. *(If you see `queued` but never `started`, the **queue worker isn't running**.)*
- **skipped** — with the reason: *a person is handling this chat* (Take over), *bot is switched off*, *echo*, or *debounced*.
- **paused** — the loop guard tripped.
- **replied** — sent, with the latency in ms.
- **empty** — the bot decided not to answer.
- **error** — brain (OpenAI) or send failed, with the error message.

So a message with no reply is no longer a mystery: the last stage tells you exactly where and why it stopped. Events are also in the app log as `bot.trace`, and pruned after 3 days. Backed by the `bot_events` table (migration 000017).

## 9. Go-live checklist (do before real customers)- [ ] `APP_DEBUG=false`, real `APP_KEY`, `APP_URL` correct (https).
- [ ] Change the operator and every shop's default password.
- [ ] Replace the marketing number: set `MARKETING_WA_NUMBER` (and `MARKETING_PHONE`) so the site CTAs point to your real sales WhatsApp.
- [ ] Rotate the Evolution API key from any value shared during development.
- [ ] If using payments: set the keys, register both webhooks, test one MoMo and one card payment in test mode first.
- [ ] If using official Cloud API: set `WHATSAPP_CLOUD_VERIFY_TOKEN` to a private value (not the default), and re-share it with shops.
- [ ] Queue worker is running (bot replies/alerts are queued jobs) — confirm in EasyPanel.
- [ ] Send yourself a full test order on a real number end to end.

---

## 10. Troubleshooting (fast)

- **Marketing page not showing at /** → deploy didn't pick up `resources/marketing/index.html` / route; check Logs.
- **Customer messages not in Chats** → webhook not pointed at app. Evolution: Chats → 🔗 Re-link. Cloud: re-check Meta webhook URL + verify token + that `messages` is subscribed.
- **Bot not replying** → queue worker down, or no OpenAI key, or bot toggled off for that chat.
- **POS / Dispatch hidden for a shop** → they're on Free/Starter; those are Pro features (working as intended).
- **Official API "verify" fails in Meta** → the Verify token in Meta must exactly equal `WHATSAPP_CLOUD_VERIFY_TOKEN`.
- **500 on any page** → EasyPanel Logs, last stack trace. Temporarily set `APP_DEBUG=true` on staging to see it in-browser.

---

## 11. Where things live (quick map)

- Public site: `resources/marketing/index.html` served by `MarketingController` at `/`.
- Seller panel UI: `resources/panel/*.html` (`seller.html`, `chats.html`, `setup.html`) via `SellerPanelController`.
- Panel JSON API: `App\Http\Controllers\Panel\PanelApiController` under `/papi/*`.
- WhatsApp drivers: `app/Services/WhatsApp/` — `EvolutionGateway`, `CloudApiGateway`, resolved per-tenant by `WhatsAppManager::forTenant()`.
- Inbound webhook: `app/Http/Controllers/Bot/WebhookController` at `/api/webhook/whatsapp/{evolution|cloud}`.
- Admin (Filament): `app/Filament/Admin/*` at `/admin`.
- Config: `config/whatsapp.php`, `config/plans.php`, `config/billing.php`, `config/marketing.php`.
