# V4-COMBINED-DEPLOY.md — the complete AI Relationship Engine, one upload
### Supersedes the five incremental zips (v4-0 · v4-0b · v4-1 · v4-2 · v4-3)

## What this is
V4.0 Memory+Stage · V4.0b the 360 Relationship card · V4.1 Goal Engine + inbox ·
V4.2 Planning/silence/escalation + Briefing · V4.3 Attribution + monthly review.
28 files, all final versions. If you already deployed some of the five zips,
uploading this over them is safe — identical files, idempotent migrations.

## Deploy (one pass)
1. **Upload ALL files** (keep repo paths exactly) → EasyPanel **Rebuild**
2. `php artisan migrate`   (2 new migrations, both rerun-safe)
3. **Test gate** — must be green before flags:
   `php artisan test --filter='RelationshipMemory|FollowupEngine|PlanningEscalation|Attribution'`
   (15 tests) — then the full house gate if you want everything:
   `php artisan test --filter='Comms|ChatsWorkspace|PriceTruth|StorefrontUx|RelationshipMemory|FollowupEngine|PlanningEscalation|Attribution'`
4. **Flip the three flags** (all default OFF — nothing runs before this):
   ```
   php artisan tinker
   $t = Tenant::find(17);
   $t->putSetting('relationship_memory', true);   // the memory loop + Guardian recording
   $t->putSetting('followups_ai', true);          // the goal engine + inbox
   $t->putSetting('ai_planning', true);           // think-first: silence + escalation
   ```
5. **Kick everything once by hand** (the crons take over after):
   ```
   php artisan relationship:extract --tenant=17 --batch=10
   php artisan relationship:stages --tenant=17
   php artisan relationship:plan --tenant=17
   php artisan relationship:attribute --tenant=17
   php artisan relationship:review --tenant=17 --days=7
   ```

## The four proof moments (do these — each one IS a phase working)
1. **Memory**: open any customer with recent chats in /v3 → the "Relationship
   (AI memory)" card carries facts you never typed.
2. **The loop closed**: message the bot from that customer's number → the reply
   reflects who they are.
3. **The plan**: open `#/followups` → today's proposals with reasons and drafts →
   approve one quote-chase → it lands in the customer's WhatsApp + transcript.
4. **Escalation**: message the bot "this is the third time my order is wrong, I
   want a manager" → polite handoff + 🚨 push + Take-over engaged + ESCALATED
   stamp on the 360.

## The machine's own schedule (installed by this zip)
extract every 15 min · stages 03:15 · attribute 06:50 · plan 07:30 ·
review monthly on the 1st, 08:00

## Settings you may tune later (tinker, per tenant)
`sales_reorder_days` (21) · `followup_touch_cap_days` (4) ·
`followup_attribution_days` (7)

## Standing laws (all pinned by tests)
AI proposes only INTERESTED/NEGOTIATING/WAITING_BUDGET — data floors everything
else · LOST is human-only and STICKY · numbers come from math, AI stays
categorical · one open proposal per customer, global touch cap across ALL engines
· silence is honored · every send is human-approved until a trigger EARNS
graduation (≥90% over ≥10) · nothing ever rewrites a prompt.

## Rollback
Three putSetting(false) calls — extraction stops, the inbox drains, the brain's
context returns to pre-V4. Zero residue, no schema removal needed.
