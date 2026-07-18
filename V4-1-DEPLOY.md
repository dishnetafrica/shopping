# V4-1-DEPLOY.md — the Goal Engine: "Who needs me today?"

## What happens each morning (07:30, flag on)
`relationship:plan` asks the data who needs attention and fills the
**Follow-ups inbox** (route `#/followups`):

| Trigger | Rule (all deterministic) | Confidence (by rule) |
|---|---|---|
| **Quote chase** | quotation 2–14 days old, never became an order | ≤5d = high |
| **Reorder** | PURCHASED/REPEAT/VIP past `sales_reorder_days` (21), <90d | VIP = high |
| **Winback** | DORMANT with ≥1 past order | low |
| **Promise due** | the Guardian recorded a promise WE made | high — we keep our word |

Each candidate gets an AI-drafted personal message (using the V4.0 profile block);
if AI is down, a deterministic template carries it — **a candidate is never lost**.

## Anti-spam is structural, not aspirational
- **ONE open proposal per customer** across all triggers (+7-day shadow after a send)
- **Global touch cap** (`followup_touch_cap_days`, default 4): if ANY engine
  contacted them recently, the Goal Engine stays silent — and SalesCadence now
  stamps `last_contacted_at` on every send, so the cap finally sees everything
- Daily cap 20 proposals/tenant · per-tenant flag `followups_ai` default OFF

## The inbox (V3 → Follow-ups)
Table: customer · why now (trigger + evidence) · proposed message · confidence ·
waiting. Quick actions per proposal: **Send** (confirm shows the exact text) ·
**Edit & send** (your words win — pinned by test) · **Snooze 3d** · **Skip**
with a reason ("he visits weekly anyway") — every skip is V4.3 training data.
Every send: real gateway → transcript → `sent_message_id` linked for V4.3
revenue attribution → touch cap stamped.

## Files (11)
migration · FollowupProposal · GoalEngine · relationship:plan (07:30 cron) ·
FollowupsCapability + registration · 3 papi handlers + routes ·
SalesCadence touch-stamp · FollowupEngineTest (4)

## Deploy
1. Over V4.0 → Rebuild → `php artisan migrate`
2. `php artisan test --filter='RelationshipMemory|FollowupEngine'`
3. Flip: `Tenant::find(17)->putSetting('followups_ai', true);`
4. Kick once: `php artisan relationship:plan --tenant=17` → open `#/followups`
5. Approve one real quote-chase → watch it land in the customer's WhatsApp and
   the chat transcript.
Rollback: flag off — the cron skips, the inbox empties naturally.

**Next (say continue): V4.2** — planning-in-the-prompt (the bot may choose
SILENCE), the escalation taxonomy → Tasks, and the Briefing cards.
