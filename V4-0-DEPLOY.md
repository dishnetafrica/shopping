# V4-0-DEPLOY.md — Relationship Memory + Stage (zip 1 of the V4 build)

## What starts happening after this deploy
Every settled conversation (idle 30 min) gets ONE AI analysis (~$0.001) producing:
- **Facts** onto the customer profile (usual products, budget band, objections,
  decision role, wanted-but-unavailable items…) — merged, humans can overwrite later
- **Relationship stage** — AI may propose only INTERESTED / NEGOTIATING /
  WAITING_BUDGET, transition-validated; everything else belongs to DATA:
  the nightly job floors REPEAT (≥2 orders), PURCHASED, DORMANT (45d silence),
  VIP (health ≥85 + REPEAT). LOST is human-only, forever.
- **Health 0-100** — pure RFM math (recency 40 · frequency 30 · monetary 30),
  with yesterday's value kept for the V4.4 improved/deteriorated lists
- **A rolling 5-8 line relationship summary**
- **The Guardian review** (stored silently for V4.4): unanswered questions,
  missed quotes, promises we made, abandonment, quality good/needs_attention/poor
  WITH reasons — categorical only, never invented numbers

And the loop CLOSES: **AiBrain's system prompt now carries the profile block** —
name, stage, facts, summary — so the bot that answers your customers finally knows
who it's talking to. SalesCadence gets the same block in V4.1.

## Files (10)
migration (idempotent) · RelationshipExtractor (one-call, two analyses, stage
machine) · relationship:extract cron (15 min) · relationship:stages cron (03:15,
data floors + health) · ProfileContext · AiBrain (one-line injection) ·
CustomerProfile + Conversation models · routes/console.php · RelationshipMemoryTest (4)

## Deploy
1. Upload all 10 → Rebuild → `php artisan migrate`
2. `php artisan test --filter=RelationshipMemory`
3. **Flip the flag** (default OFF — nothing runs until you do):
   `php artisan tinker` → `Tenant::find(17)->putSetting('relationship_memory', true);`
4. Kick it once by hand: `php artisan relationship:extract --tenant=17 --batch=10`
   then `php artisan relationship:stages --tenant=17`
5. See it: `Tinker → CustomerProfile::where('tenant_id',17)->whereNotNull('ai_summary')->first()`
   → facts, stage, summary populated from today's real chats
6. From now on both crons run themselves (15 min / nightly).

## Prove the loop is closed
Pick a customer whose profile now has facts → message the bot from their number →
the reply should reflect what it knows (their products, their stage). That reply is
V4.0 working.

Rollback: `putSetting('relationship_memory', false)` — extraction stops, the brain's
block returns empty, zero residue.

**Next zip (say "go v4 zip 2"):** the Customer 360 Relationship card + papi
endpoints, so the team SEES the memory and can correct it.
