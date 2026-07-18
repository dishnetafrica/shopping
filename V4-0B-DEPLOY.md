# V4-0B-DEPLOY.md — zip 2: the memory made visible & correctable (Customer 360)

## What the team sees (flag on)
Every Customer 360 Overview gains a **"Relationship (AI memory)"** card:
- **Stage** (with since-date) · **Health n/100** with ↑/↓ vs yesterday · **Flags**
  (VIP / AT_RISK / WINBACK)
- Every learned **fact** (usual products, budget band, objections, decision role…)
- The rolling **AI summary**

## Humans own the memory — three actions in the ⋯ menu
| Action | Behaviour |
|---|---|
| **Set relationship stage** | any stage, including LOST and VIP — the two the AI can never touch. LOST is STICKY: the nightly math skips LOST profiles forever (pinned by test) |
| **Edit AI summary** | textarea prefilled with the current summary |
| **Correct a fact** | pick the fact (existing keys offered), type the right value — or leave EMPTY to DELETE it |

House patterns throughout: workspace action endpoints (no new routes), enum/combo/
textarea form fields, flag-gated (card and actions vanish when `relationship_memory`
is off).

## Files (3)
CustomerCapability (card + 3 actions + dispatcher + handler) ·
RelationshipStagesCommand (LOST-sticky guard) · RelationshipMemoryTest (+1 = 5 total)

## Deploy
Over zip 1 → Rebuild → `php artisan test --filter=RelationshipMemory` → open any
customer in /v3 → the Relationship card is there. Let a chat settle 30 min → refresh
→ watch facts appear on their own.
