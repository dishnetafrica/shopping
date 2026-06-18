# ShopBot / CloudBSS — Production Freeze

**Release tag:** `2026.06.18-86.5`
**Status:** STAGING APPROVED → run checklist → PRODUCTION
**Feature freeze:** ON
**Freeze owner:** Bhavin

> **Freeze philosophy.** The line is not "no changes" — it's **no new operational complexity**.
> **Freeze violation ❌:** new workflow / engine / automation / AI capability / business process (Ticket Engine, Activities, Timeline, Voice Notes, Receipt OCR, CSV Import).
> **Freeze-safe ✅:** administrative tooling, visibility, manual data entry, operational controls (Operations Dashboard 86.3, Health Diagnostics 86.4, Manual Lead CRM 86.5). These make *existing* workflows manageable.

---

## 1. What is in this release

The platform crossed from "WhatsApp shopping bot" to "CRM operating layer": every inbound message is routed to **Shopping · Lead · Ticket**, then assigned to a human, with analytics on the whole funnel.

```
Customer message
      ↓
Intent Router  →  Shopping | Lead | Ticket
      ↓
Assignment (round-robin / claim / manual)
      ↓
Human
```

### Build history (82 → 86.4)

| Build | Area | Summary |
|------|------|---------|
| 82 | Image search | Photo → vision (`gpt-4o-mini`) → existing catalogue search → numbered pick-list. Per-tenant feature flag. |
| 83 | Image hardening | Caption folding, confidence gate, **hallucination guard** (must match a real product), repeat-image cache (7-day), photo trace stats + dashboard card. |
| 84 | Image feedback loop | Records the product the customer actually picks (`image_search_feedback`); funnel on dashboard. |
| 85 | Known-image shortcut | Repeated/known photos skip OpenAI and resolve from history; "Instant · no AI" metric. |
| 85.1 | Shortcut consensus | Known image requires **≥2 distinct customers** (not one person's repeats). |
| 86 | Lead assignment | Intent Router, `Lead` model, recipients with roles, round-robin, **atomic claim** (`CLAIM <id>`), analytics events. |
| 86.1 | Lead hardening | `source`, `lead_score` (rule-based), `conversation_id` (shopping→lead escalation keeps context), availability fallback in round-robin. |
| 86.2 | Content dedupe | Dedupe on `sha1(phone│intent│normalized_interest)` so distinct asks ("Starlink" vs "Fiber") are separate leads; repeats collapse. |
| 86.3 | Operations dashboard | Today's Sales / Support / Shopping numbers on the seller dashboard. No new tables. |
| 86.4 | Health diagnostics | System Health strip on `/panel/diagnostics`: WhatsApp, Redis, OpenAI, Queue, Last webhook, Last processed. |
| 86.5 | Manual Lead CRM | `/panel/leads`: create/edit/list/filter/assign/won-lost, selectable source, **4 pipeline KPI cards**, **next follow-up + last contacted** (additive columns). Phone/referral/walk-in leads now visible alongside WhatsApp. |

---

## 2. Production readiness

| Area | Status |
|------|--------|
| Shopping Engine | ✅ Ready |
| Image Search | ✅ Ready |
| Known-Image Learning | ✅ Ready |
| Lead Assignment | ✅ Ready |
| Claim System | ✅ Ready |
| Content Dedupe | ✅ Ready |
| Operations Dashboard | ✅ Ready |
| Diagnostics Health Panel | ✅ Ready |
| Manual Lead CRM (+ KPIs, follow-ups) | ✅ Ready |
| Analytics Backbone (`bot_events`) | ✅ Ready |
| Ticket Engine | ⏳ Build 87 |
| Human Inbox | ⏳ Build 88 |
| Voice Notes | ⏳ Build 89 |
| Activities & Timeline | ⏳ Build 90 |
| CSV Import | ⏳ Build 91 |

### CRM menu — target shape (evolves with 87–91)

```
CRM
├── Leads        (86.5 — live)
├── Customers
├── Activities   (Build 90)
├── Inbox        (Build 88)
└── Reports
```

---

## 3. Production Freeze Rules

**Allowed during freeze**
- Bug fixes
- Production-incident fixes
- Diagnostics / health improvements
- Router keyword additions (via tenant settings — no code)
- Dashboard / reporting additions

**Not allowed during freeze**
- New AI features
- New database tables (unless incident-related)
- New workflows or channels
- Voice search, Ticket engine, Human inbox, Receipt OCR

**Build 87 begins only after reviewing at least one week of production metrics.**

---

## 4. Pre-production deployment checklist

**Image search**
- [ ] Send 10 product photos — matches returned
- [ ] Known-image shortcut fires on a repeated photo (no vision latency; `photo_known` trace)
- [ ] No hallucinated products (guard rejects items not in catalogue)
- [ ] Photo analytics increment on the dashboard

**Leads** — each should create a **separate** lead
- [ ] "Call me"  [ ] "Need Starlink"  [ ] "Need Fiber"  [ ] "Need Dedicated Internet"

**Tickets** — must **not** reach ShoppingEngine
- [ ] "Internet down"  [ ] "No connection"  [ ] "Slow speed"

**Claim race**
- [ ] Two reps send `CLAIM <id>` simultaneously → exactly one owner

**Dedupe**
- [ ] Same request 3× within 6h → exactly one lead

**Escalation**
- [ ] "Need Starlink plans" → "show packages" → "call me" creates a lead **with `conversation_id`**

**Health**
- [ ] `/panel/diagnostics` health strip reads green (WhatsApp open, Redis connected, OpenAI configured)

---

## 5. Three metrics to watch daily

All derived from `bot_events` + `leads` — no new schema.

**Sales funnel** — `lead_created → lead_claimed → lead_won`
- Track **claim rate** and **win rate** per salesperson (`assigned_to`).

**Photo-search funnel** — `photo_received → photo_identified → photo_selected`
- KPI: **selection rate = photo_selected / photo_received**. ≥60% = keep investing; <20% = investigate.

**Router accuracy** — review the last 20 leads + 20 tickets each morning
- Look for: shopping mis-flagged as lead, lead mis-flagged as shopping, ticket mis-flagged as shopping.
- Fix by adding phrases to `lead_keywords` / `ticket_keywords` (settings, no code).

---

## 6. Operational reference (the knobs you're allowed to turn)

**Required environment (`shopping` service on EasyPanel)**
- `OPENAI_API_KEY` — image search + future NLU. Missing → bot asks customers to type the name.
- Redis up — cache (image shortcut), queue, scheduler.
- Persistent **Volume** mounted at `/var/www/html/storage/app/public` — otherwise uploaded product photos wipe on redeploy. *(Still pending — set once.)*

**Tunable tenant settings (admin → Tenant → Settings, or DB)**
- `lead_keywords`, `ticket_keywords` — comma/newline extra phrases for the router.
- `lead_assignment_mode` — `round_robin` (default) | `claim` | `manual`.
- `lead_recipients` — `[{phone, role: sales|support|manager, name, enabled}]`.
- `image_search_min_confidence` (50), `image_shortcut_min_votes` (3), `image_shortcut_min_share` (0.80), `image_shortcut_min_customers` (2).
- `feature_image_search`, `feature_thali` — per-tenant feature flags.

**Where to look when something breaks**
- `/panel/diagnostics` — health strip + live per-message trace (where a message stopped).
- Dashboard "Operations · today" — lead/ticket/shopping volumes.
- `bot_events` table — full event history for ad-hoc reports.

---

## 7. After deployment

1. Monitor daily (the three funnels above).
2. Collect 1–2 weeks of metrics.
3. Export **top 20 lead phrases**, **top 20 ticket phrases**, **top 20 photo searches**.
4. Use those to tune the router and design the Ticket engine.

Then, and only then:

```
Build 87 → Ticket Engine     (shared Assignable services; P1/P2/P3 priority)
Build 88 → Human Inbox        (Take Over / Release / Assign / Resolve / Mark Won inline)
Build 89 → Voice Notes        (Whisper → Intent Router → Shopping/Lead/Ticket)
Build 90 → Activities & Timeline (lead_activities table; logged calls/visits/quotes/meetings)
Build 91 → CSV Import         (bulk lead creation with validation + dedupe)
```

The architecture is mature. The next improvements should be driven by what customers and sales agents actually do — not further design speculation.
