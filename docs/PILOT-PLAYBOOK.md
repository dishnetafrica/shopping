# CloudBSS — Staging Pilot Playbook (7 days)

Goal: run real customer traffic on three shops for ≥7 days, then decide the next
development phase from evidence — not guesses.

Pilot tenants: **Family Shopper**, **one supermarket**, **one pharmacy**.

---

## Pre-flight (before day 1)
- [ ] Current drop deployed (brain + default strategy + production safety + the
      `delivered_at` fix). Migrations applied. `QUEUE_CONNECTION=redis`. Horizon +
      scheduler running.
- [ ] Staging verification done (`PHASE2-STAGING-RUNBOOK.md`): load + chaos + the
      five SQL checks all return 0 dup rows. **Don't start the pilot until these pass.**
- [ ] Each tenant onboarded: WhatsApp connected + webhook registered, products
      loaded, delivery pricing + `owner_alert_phone` set, bot mode = auto.
- [ ] Owners given `SELLER-PANEL-GUIDE.md` and a way to reach you.

## Daily (each of the 7 days)
- Skim Horizon (failed jobs, queue depth) and `bot.latency`.
- Skim each tenant's Chats for conversations the bot mishandled (clarify loops,
  wrong product, dead-ends, "agent took over").
- Note any owner complaints verbatim.

## What to collect (so the report is real)
Pull at the end of the week (Postgres). Examples:
```sql
-- volume per tenant
SELECT tenant_id, count(*) orders, sum(total) value FROM orders GROUP BY 1;

-- order status mix (completion vs drop-off)
SELECT tenant_id, status, count(*) FROM orders GROUP BY 1,2 ORDER BY 1,3 DESC;

-- bot vs human vs pos
SELECT channel, count(*) FROM orders GROUP BY 1;

-- busiest hours
SELECT date_trunc('hour', created_at) h, count(*) FROM orders GROUP BY 1 ORDER BY 1;

-- chats where a human had to take over (bot couldn't cope)
SELECT tenant_id, count(*) FROM conversations WHERE agent_active = true GROUP BY 1;
```
Also export, per tenant: full **Chats** transcripts (the bot's actual replies are
the richest signal), and the **Diagnostics**/`bot.latency` summary.

## The report (produced after 7 days)
Bring the collected data back and the report will cover, per tenant and overall:

1. **Most common customer actions** — what people actually do (browse, single-item
   add, multi-item list, ask price, checkout, reorder, ask hours/location…), ranked.
2. **Most common failures** — where the bot stumbles: wrong product matched,
   clarify loops, items it couldn't find, abandoned checkouts, human-takeover
   triggers, latency spikes. Ranked, with example transcripts.
3. **Most requested features** — what customers/owners asked for that doesn't exist
   yet (edit/remove items, reorder, payment-on-chat, delivery ETA, etc.).

## Decision gate
Only after the report do we pick the next phase. The leading candidate is the
**Cart Edit Engine** (remove/change/replace items) — currently frozen — but the
pilot decides whether that, full checkout capture, reorder, or something else is
the real priority. **No new features are built during the pilot.**

## Success signals to watch
- Zero duplicate orders / receipts / campaign sends (the safety layer holding under
  real traffic).
- Correct delivery dates on delivered orders.
- Bot completing orders without frequent human takeover.
- Reply latency steady (no queue backlog that doesn't drain).
