# WhatsApp number health — diagnostics panel

Answers, per tenant, on `/panel/diagnostics`: **which number is connected, and is it actually able to
send/receive** — the exact gap from the Family Shoppers episode, where a "Connected" instance hid an
all-outbound-ERROR number.

## Two new tiles (top of the diagnostics page)

- **Number** — the connected WhatsApp number + profile name (e.g. `+256758953737 · Family Shoppers`), pulled live from Evolution. Green when linked + open, amber if linked but not open, red if not linked.
- **Sending** — real outbound health:
  - `delivering · 2m ago` (green) — a recent message reached the recipient (DELIVERY_ACK / READ).
  - `ERROR ×N (6h)` (red) — N failed sends in the last 6h. **This is the flagged-number signal.**
  - `no recent sends` (amber) — nothing to report yet.

Existing tiles unchanged (WhatsApp state, Redis, OpenAI, Queue, Last webhook = receive recency, Last processed).

## Why this is reliable (and `findMessages` wasn't)

Evolution's `findMessages` returned **null status** for our messages, so it can't tell us if sends
succeed. Instead we now capture the truth from Evolution's **`messages.update`** webhook — the same
event stream that carries `connection.update`. For every OUTBOUND message we record the delivery status
as **our own** data:
- `status = ERROR`  → a `send_failed` row in `bot_events` (shows red in the live list) + `wa_send_err_at`.
- `status = DELIVERY_ACK / READ` → stamps `wa_send_ok_at` (throttled to once / 30s).

`MESSAGES_UPDATE` is already in the instances' subscribed webhook events, so no re-link is needed — but
if **Sending** stays `no recent sends` after real traffic, hit **Re-link** once to re-assert the webhook.

## Files

**Edited**
- `app/Services/WhatsApp/EvolutionAdmin.php` — `instanceInfo()`: connected number / profile / state / counts from `fetchInstances` (tolerant of flat-list, wrapped `{instance:{…}}`, and `ownerJid` vs `owner`).
- `app/Http/Controllers/Bot/WebhookController.php` — `maybeHandleSendStatus()` captures outbound delivery status from `messages.update` (defensive: single or list payload, `fromMe` filter, `status` vs `update.status`).
- `app/Http/Controllers/Panel/PanelApiController.php` — `health()` now returns `whatsapp.number`, `profile_name`, `send_ok_at`, `send_err_at`, `recent_send_fail`.
- `resources/panel/diagnostics.html` — Number + Sending tiles; `send_failed` styled red in the event list.

**New**
- `qa/wa_health_parse.php` — proves both parsers against the Evolution v2.3.7 payload shapes. **15/15 green.**

## Deploy

```bash
# GitHub → EasyPanel pull → restart.
```

No migrations, no new routes — uses existing `bot_events` + tenant settings, and the already-routed
`/papi/health` + webhook. Just pull and restart.

## What it does NOT do (yet)

- Operator-wide view of *all* tenants' numbers on one screen — this is per-logged-in-tenant (matches the rest of diagnostics). Easy to add an operator roll-up later.
- It reflects status as WhatsApp reports it; a number can still be healthy now and get throttled later under automation volume. The durable ban-safe path remains the official Cloud API.
