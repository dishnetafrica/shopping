# CloudBSS × n8n smart-bot — P1 (CloudBSS side)

This is the Laravel half of the design: the per-tenant **bot brain** switch, the n8n hand-off,
and the bridge endpoints n8n posts back to. CloudBSS stays the only thing that touches WhatsApp.

## What it does
- **`bot_mode` per tenant** (Admin → tenant → "Smart bot (n8n)"):
  `auto` = inbuilt cart bot (default, unchanged) · `n8n` = smart bot · `off` = staff only.
- **One branch** in `ProcessIncomingMessage` (after tenant resolve, dedupe, group/own-message
  filtering, staff-takeover and mute checks): if `bot_mode = n8n`, POST the message to the tenant's
  `n8n_webhook_url` with `X-CloudBSS-Secret`, then stop. The inbuilt bot is skipped.
- **Bridge endpoints** (auth = tenant shared secret, only while `bot_mode = n8n`):
  - `POST /api/bot/reply`  `{tenant_id, phone, text}` → sends + logs as a bot reply.
  - `POST /api/bot/alert`  `{tenant_id, to[], text}` → sends staff alert(s), logged.
  - `GET  /api/tenant/{id}/catalog` → tenant products (id/name/price/unit/pack/moq/stock/category), 60s cache.
- **Owner on/off toggle is n8n-safe**: the seller panel switch flips n8n↔off, never silently
  downgrades an n8n tenant to the cart bot.
- **Failure-safe**: if n8n times out, the inbound is already logged; optional soft-ack + staff flag
  (per-tenant toggle). A lead is never lost to an n8n outage.

## CloudBSS → n8n payload (what your workflow's Webhook receives)
```json
{ "tenant_id":12, "tenant_slug":"ep", "vertical":"manufacturer",
  "customer":{"phone":"2567…","name":"…","jid":"2567…@s.whatsapp.net"},
  "message":{"text":"do you deliver to Mbarara?","type":"conversation","messageId":"…"},
  "persona":"…", "alert_routing":{"sales":["2567…"],"accounts":["2567…"]},
  "reply_url":"https://app…/api/bot/reply",
  "alert_url":"https://app…/api/bot/alert",
  "catalog_url":"https://app…/api/tenant/12/catalog" }
```
`reply_url`/`alert_url`/`catalog_url` are passed in, so the workflow is environment-agnostic
(staging vs prod) and never hardcodes them.

## Onboard a smart-bot tenant (e.g. Krishna Wellness)
Admin → tenant → **Smart bot (n8n)**: set Bot brain = `n8n`, paste the shared webhook URL + secret,
write the persona, fill alert routing (sales/accounts/dispatch/quality/management → numbers), save.
No deploy.

## Deploy
Pull → restart → `php artisan optimize:clear` (new API routes). **No migration** (all settings-based).

## Test from the shell (after setting secret S and a real tenant id T + number P)
```bash
# reply
curl -s -X POST https://app.cloudbss.com/api/bot/reply \
  -H "X-CloudBSS-Secret: $S" -H "Content-Type: application/json" \
  -d '{"tenant_id":'$T',"phone":"'$P'","text":"Test reply from n8n bridge"}'
# alert
curl -s -X POST https://app.cloudbss.com/api/bot/alert \
  -H "X-CloudBSS-Secret: $S" -H "Content-Type: application/json" \
  -d '{"tenant_id":'$T',"to":["'$P'"],"text":"Test staff alert"}'
# catalog
curl -s "https://app.cloudbss.com/api/tenant/$T/catalog" -H "X-CloudBSS-Secret: $S"
```
Wrong/empty secret or a non-n8n tenant → 401.

## QA
`qa/n8n_bridge.php` — **17/17** (brain resolution, toggle guard, secret auth, routing normalisation).

## Files
- `app/Jobs/ProcessIncomingMessage.php` — n8n branch + `forwardToN8n()` + `normalizeRouting()`.
- `app/Http/Controllers/Api/BotBridgeController.php` — reply / alert / catalog (NEW).
- `routes/api.php` — the three bridge routes.
- `app/Filament/Admin/Resources/TenantResource.php` — "Smart bot (n8n)" admin section.
- `app/Http/Controllers/Panel/PanelApiController.php` — n8n-safe on/off toggle.
- `qa/n8n_bridge.php` — QA.

## Next (not in this ZIP)
The **n8n workflow transform** — take your 94-node all-in-one and produce the CloudBSS-generic,
tenant-keyed version: Webhook (verify secret) → Signal Engine (state keyed by tenant_id+phone) →
AI Agent (persona + cached catalog from `catalog_url`) → POST reply to `reply_url`; alerts via
`alert_url`; watchdog + digest as shared tenant-keyed schedules. That's a separate large artifact —
I'll build it next so I can give the node wiring the care it needs.
