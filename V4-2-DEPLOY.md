# V4-2-DEPLOY.md — the bot thinks first: planning, silence, escalation, visibility

## 1. Planning-in-the-prompt (flag `ai_planning`, default OFF)
The bot's reply now begins with a hidden `<<PLAN {json}>>` block — the SAME
machine-block idiom as your existing <<ORDER>> and <<QUOTE>> — declaring its
purpose: sell · solve · ask · **escalate** · **wait** · **silent**. One call
thinks AND speaks: zero extra latency, zero extra cost.
- **silent / wait → nothing is sent.** For the first time, the bot can decide the
  professional move is to say nothing. Every plan lands in BotTrace.
- No plan block → normal reply (backward compatible). Malformed plan → stripped
  and ignored — a bad plan can never break a reply. All pinned by tests.

## 2. Reasoned escalation (built from mechanisms you already trust)
purpose=escalate → taxonomy-validated reason (angry_customer · negotiation ·
credit_request · large_quote · vip_active · technical_problem ·
repeated_misunderstanding) →
- the conversation is **taken over** (agent_active — the bot stops, your proven mechanic)
- the profile's follow_up stamps "ESCALATED: Credit request — wants 30-day terms"
- the team gets a **push**: 🚨 Escalation: … 
- BotTrace records the why (V4.3 reads these)
- the customer receives the model's short polite handoff
Honest deviation from the roadmap: TaskEngine turned out to be the ORDER-stage
workflow, not a generic task system — so escalations reuse takeover + profile +
push + trace instead of inventing fake tasks. Truer to "close the loops".

## 3. Stage playbooks
The profile block now carries one guidance line per stage — NEGOTIATING: "no new
discounts without a human" · DORMANT: "no guilt, no pressure" · VIP: "flag anything
unusual early" — and because GoalEngine drafts through the same block, cadence
follow-ups obey the playbooks automatically.

## 4. The Morning Briefing sees the AI desk
Two new plan lines (the house ActionSpec pattern): "Review the AI's plan for today —
N follow-ups awaiting your judgment" → the inbox · "🚨 N escalated customers need a
human" → Chats.

## Files (7) · Deploy
PlanParser · EscalationService · AiBrain · ProfileContext · MorningBriefing ·
PlanningEscalationTest (3) — over V4.1 → Rebuild →
`php artisan test --filter='RelationshipMemory|FollowupEngine|PlanningEscalation'`
→ flip: `Tenant::find(17)->putSetting('ai_planning', true);`
→ live test: message the bot "I am very unhappy, this is the third time my order is
wrong, I want to speak to a manager" → polite handoff arrives, YOUR PHONE BUZZES
🚨, the chat shows Take over already done, the 360 shows the ESCALATED stamp.
Rollback: flag off — plans stop being requested, everything else untouched.

**Next (continue): V4.3** — attribution (proposal→order influenced revenue),
the learning-lite monthly review, and the AI-desk measurement card. The last mile.
